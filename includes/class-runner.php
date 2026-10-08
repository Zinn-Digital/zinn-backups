<?php
/**
 * Saves, loads and drives the current job.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * How a job keeps moving with nobody watching:
 *
 * 1. after each step the runner fires a non-blocking request back to this site
 *    (`admin-ajax.php?action=zinn_backups_continue`, authorised by a single-use token whose
 *    hash lives in the work folder and which expires after LOOP_TTL seconds), which runs the
 *    next step;
 * 2. a WP-Cron event five minutes out picks the job up again if that chain is ever broken
 *    (a host that blocks requests to itself);
 * 3. the admin screen, while open, runs steps itself; and WP-CLI runs them all in one go.
 *
 * The file lock ({@see Lock}) means two of these never run a step at the same time.
 */
final class Runner {

	public const CONTINUE_HOOK = 'zinn_backups_continue';

	/**
	 * Seconds a loopback token stays valid.
	 */
	public const LOOP_TTL = 300;
	public const HISTORY  = 'zinn_backups_history';

	/**
	 * Job classes by type.
	 *
	 * @return array<string,class-string<Job>>
	 */
	private static function classes(): array {
		return array(
			'backup'  => Backup_Job::class,
			'restore' => Restore_Job::class,
		);
	}

	/**
	 * Register the hooks that continue a job.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::CONTINUE_HOOK, array( self::class, 'cron_tick' ) );
		add_action( 'wp_ajax_nopriv_zinn_backups_continue', array( self::class, 'loopback_tick' ) );
		add_action( 'wp_ajax_zinn_backups_continue', array( self::class, 'loopback_tick' ) );
	}

	/**
	 * Start a job. Refuses when another one is running.
	 *
	 * @param Job $job The job.
	 * @return Job
	 * @throws \RuntimeException When a job is already running.
	 */
	public static function start( Job $job ): Job {
		$current = self::current();
		if ( null !== $current && $current->running() ) {
			throw new \RuntimeException( esc_html__( 'Another backup, restore or import is running. Wait for it to finish, or cancel it.', 'zinn-backups' ) );
		}
		$job->state['started'] = time();
		// Older jobs' folders (finished, failed, abandoned uploads) go before a new one starts.
		foreach ( (array) glob( Work_Dir::path() . '/[0-9]*-[0-9]*-*', GLOB_ONLYDIR ) as $dir ) {
			if ( basename( $dir ) !== $job->id() ) {
				Work_Dir::rmdir( $dir );
			}
		}
		self::save( $job );
		self::write_json(
			Work_Dir::path() . '/current.json',
			array(
				'id'   => $job->id(),
				'type' => $job->type(),
			)
		);
		self::kick();
		return $job;
	}

	/**
	 * The current (most recent) job, or null.
	 *
	 * @return Job|null
	 */
	public static function current(): ?Job {
		$pointer = self::read_json( Work_Dir::path() . '/current.json' );
		if ( empty( $pointer['id'] ) || empty( $pointer['type'] ) ) {
			return null;
		}
		$class = self::classes()[ (string) $pointer['type'] ] ?? null;
		$state = self::read_json( Work_Dir::job( (string) $pointer['id'] ) . '/state.json' );
		if ( null === $class || empty( $state ) ) {
			return null;
		}
		return new $class( $state );
	}

	/**
	 * Save a job's state (atomically: write then rename).
	 *
	 * @param Job $job The job.
	 * @return void
	 */
	public static function save( Job $job ): void {
		$job->state['updated'] = time();
		self::write_json( Work_Dir::job( $job->id() ) . '/state.json', $job->state );
	}

	/**
	 * Run steps of the current job for up to `$budget` seconds.
	 *
	 * @param float $budget Seconds.
	 * @return array<string,mixed>|null Summary of the job, or null when there is none.
	 */
	public static function tick( float $budget ): ?array {
		if ( ! Lock::acquire() ) {
			$job = self::current();
			return null === $job ? null : $job->summary() + array( 'busy' => true );
		}
		try {
			$job = self::current();
			if ( null === $job ) {
				return null;
			}
			if ( $job->running() && (int) ( $job->state['retry_after'] ?? 0 ) <= time() ) {
				self::run( $job, microtime( true ) + $budget );
			}
			return $job->summary();
		} finally {
			Lock::release();
		}
	}

	/**
	 * Run one job until the deadline.
	 *
	 * @param Job   $job      The job.
	 * @param float $deadline microtime deadline.
	 * @return void
	 */
	private static function run( Job $job, float $deadline ): void {
		wp_raise_memory_limit( 'admin' );
		ignore_user_abort( true );
		while ( $job->running() && microtime( true ) < $deadline ) {
			try {
				$job->step( $deadline );
				$job->state['retries'] = 0;
			} catch ( Remote_Error $e ) {
				if ( $e->is_transient() && (int) $job->state['retries'] < 6 ) {
					++$job->state['retries'];
					$wait                      = 15 * ( 2 ** (int) $job->state['retries'] );
					$job->state['retry_after'] = time() + $wait;
					/* translators: 1: error message, 2: seconds until the retry. */
					$job->log( sprintf( __( '%1$s — trying again in %2$d seconds.', 'zinn-backups' ), html_entity_decode( $e->getMessage(), ENT_QUOTES, 'UTF-8' ), $wait ), 'warning' );
					self::save( $job );
					return;
				}
				$job->fail( html_entity_decode( $e->getMessage(), ENT_QUOTES, 'UTF-8' ) );
				$job->cleanup();
			} catch ( \Throwable $e ) {
				$job->fail( html_entity_decode( $e->getMessage(), ENT_QUOTES, 'UTF-8' ) );
				$job->cleanup();
			}
			self::save( $job );
		}
		if ( ! $job->running() ) {
			self::remember( $job );
		}
	}

	/**
	 * Cancel the current job.
	 *
	 * @return void
	 */
	public static function cancel(): void {
		$job = self::current();
		if ( null === $job || ! $job->running() ) {
			return;
		}
		$job->state['status']   = 'cancelled';
		$job->state['finished'] = time();
		$job->log( __( 'Cancelled.', 'zinn-backups' ), 'warning' );
		$job->cleanup();
		self::save( $job );
		self::remember( $job );
	}

	/**
	 * Keep a short history of finished jobs for the admin screen.
	 *
	 * @param Job $job A finished job.
	 * @return void
	 */
	public static function remember( Job $job ): void {
		$history = get_option( self::HISTORY, array() );
		$history = is_array( $history ) ? $history : array();
		$summary = $job->summary();
		unset( $summary['log'] );
		$history = array_filter( $history, static fn( $row ): bool => ( $row['id'] ?? '' ) !== $job->id() );
		array_unshift( $history, $summary );
		update_option( self::HISTORY, array_slice( array_values( $history ), 0, 20 ), false );
		/**
		 * Fires when a backup, restore or import job finishes, fails or is cancelled.
		 *
		 * @param array<string,mixed> $summary Job summary (`type`, `status`, `error`, `result`).
		 */
		do_action( 'zinn_backups_job_finished', $summary );
	}

	/**
	 * Keep the job moving in the background.
	 *
	 * @return void
	 */
	public static function kick(): void {
		$job = self::current();
		if ( null === $job || ! $job->running() ) {
			wp_clear_scheduled_hook( self::CONTINUE_HOOK );
			return;
		}
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + 300, self::CONTINUE_HOOK );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		// ⛔ A SINGLE-USE token per loopback, kept only as its hash and valid for five minutes
		// (wp-compat 6.6.10, run 37759912019: the job-long key in 1.0.x was a standing credential).
		$pointer = self::read_json( Work_Dir::path() . '/current.json' );
		$token   = bin2hex( random_bytes( 16 ) );
		unset( $pointer['key'] );
		$pointer['loop']         = wp_hash( $token );
		$pointer['loop_expires'] = time() + self::LOOP_TTL;
		self::write_json( Work_Dir::path() . '/current.json', $pointer );
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter for requests to this site.
				'body'      => array(
					'action' => 'zinn_backups_continue',
					'key'    => $token,
				),
			)
		);
	}

	/**
	 * The step a loopback request runs.
	 *
	 * @return void
	 */
	public static function loopback_tick(): void {
		$pointer = self::read_json( Work_Dir::path() . '/current.json' );
		$key     = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorised by a single-use loopback token instead: the request comes from the site itself, with no user and no cookies.
		if ( ! self::loop_token_ok( $pointer, $key, time() ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		// Spent before any work, so a replayed request is refused.
		unset( $pointer['loop'], $pointer['loop_expires'] );
		self::write_json( Work_Dir::path() . '/current.json', $pointer );
		$job = self::current();
		if ( null === $job || ! $job->running() ) {
			wp_die( '', '', array( 'response' => 200 ) );
		}
		self::background_tick();
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Whether a loopback token is the one the last kick issued and is still fresh.
	 *
	 * @param array<string,mixed> $pointer The current-job pointer.
	 * @param string              $token   The token the request carried.
	 * @param int                 $now     Unix time.
	 * @return bool
	 */
	public static function loop_token_ok( array $pointer, string $token, int $now ): bool {
		if ( '' === $token || empty( $pointer['loop'] ) || (int) ( $pointer['loop_expires'] ?? 0 ) < $now ) {
			return false;
		}
		return hash_equals( (string) $pointer['loop'], wp_hash( $token ) );
	}

	/**
	 * The step WP-Cron runs.
	 *
	 * @return void
	 */
	public static function cron_tick(): void {
		self::background_tick();
	}

	/**
	 * A step with the time the PHP limit allows, then keep going.
	 *
	 * @return void
	 */
	private static function background_tick(): void {
		$limit = (int) ini_get( 'max_execution_time' );
		$limit = $limit <= 0 ? 60 : $limit;
		self::tick( (float) max( 5, min( 40, $limit - 10 ) ) );
		self::kick();
	}

	/**
	 * Write JSON atomically.
	 *
	 * @param string $path File.
	 * @param array  $data Data.
	 * @return void
	 */
	public static function write_json( string $path, array $data ): void {
		$tmp = $path . '.' . bin2hex( random_bytes( 3 ) ) . '.tmp';
		file_put_contents( $tmp, (string) wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- job state in our private folder; must survive a restore of the database.
		rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace.
	}

	/**
	 * Read JSON, or an empty array.
	 *
	 * @param string $path File.
	 * @return array
	 */
	public static function read_json( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		return is_array( $data ) ? $data : array();
	}
}
