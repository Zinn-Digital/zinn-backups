<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

use ZinnDigital\Backups\Storage\Destinations;
use ZinnDigital\Backups\Storage\Dropbox;
use ZinnDigital\Backups\Storage\Google_Drive;

defined( 'ABSPATH' ) || exit;

/**
 * Every action is an `admin-ajax.php` call that checks the capability (`manage_options`) and
 * a nonce. There is no REST route and no public endpoint except the job's own loopback,
 * which a random key in the private work folder authorises ({@see Runner::loopback_tick()}).
 */
final class Plugin {

	public const CAP   = 'manage_options';
	public const NONCE = 'zinn_backups';
	public const PAGE  = 'zinn-backups';

	/** Folder (under the uploads folder) a person can upload an archive into by FTP, to import it. */
	public const INBOX = 'zinn-backups-import';

	/**
	 * Boot.
	 *
	 * @return void
	 */
	public static function boot(): void {
		Runner::register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'zinn-backups', Cli::class );
		}
		if ( ! is_admin() ) {
			return;
		}
		Admin::register();
		// HOSTDISC — offered only on a site Zinn hosts, and never to a site already on Pro.
		Pro_Discount::register(
			self::PAGE,
			static fn(): bool => class_exists( \ZinnDigital\BackupsPro\Licence::class ) && \ZinnDigital\BackupsPro\Licence::is_active()
		);
		add_action( 'admin_init', array( self::class, 'oauth_return' ) );
		$actions = array(
			'tick'               => 'ajax_tick',
			'backup'             => 'ajax_backup',
			'cancel'             => 'ajax_cancel',
			'list'               => 'ajax_list',
			'restore'            => 'ajax_restore',
			'delete'             => 'ajax_delete',
			'save_destination'   => 'ajax_save_destination',
			'delete_destination' => 'ajax_delete_destination',
			'test_destination'   => 'ajax_test_destination',
			'oauth_start'        => 'ajax_oauth_start',
			'oauth_finish'       => 'ajax_oauth_finish',
			'save_settings'      => 'ajax_save_settings',
			'import_begin'       => 'ajax_import_begin',
			'import_chunk'       => 'ajax_import_chunk',
			'import_start'       => 'ajax_import_start',
		);
		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_zinn_backups_' . $action, array( self::class, $method ) );
		}
	}

	/**
	 * On deactivation: stop any job and forget the background event. Settings stay.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		try {
			Runner::cancel();
		} catch ( \Throwable $e ) {
			// The work folder is gone: there is nothing to cancel.
			unset( $e );
		}
		wp_clear_scheduled_hook( Runner::CONTINUE_HOOK );
	}

	/**
	 * Refuse anyone who may not manage backups, or a request without our nonce.
	 *
	 * @return void
	 */
	private static function guard(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage backups.', 'zinn-backups' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );
	}

	/**
	 * A POSTed text field.
	 *
	 * @param string $key Field.
	 * @return string
	 */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller has run guard() first.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/**
	 * Send the outcome of an action, turning any exception into a readable error.
	 *
	 * @param callable $action Does the work; returns the data to send.
	 * @return void
	 */
	private static function respond( callable $action ): void {
		try {
			wp_send_json_success( $action() );
		} catch ( \Throwable $e ) {
			// Messages were escaped where they were thrown; the screen inserts them as text.
			wp_send_json_error( array( 'message' => html_entity_decode( $e->getMessage(), ENT_QUOTES, 'UTF-8' ) ), 400 );
		}
	}

	/**
	 * Run a short step of the current job and report it.
	 *
	 * @return void
	 */
	public static function ajax_tick(): void {
		self::guard();
		self::respond( static fn() => array( 'job' => Runner::tick( 12.0 ) ) );
	}

	/**
	 * Start a backup.
	 *
	 * @return void
	 */
	public static function ajax_backup(): void {
		self::guard();
		self::respond(
			static function (): array {
				$what = self::post( 'what' );
				$job  = Runner::start(
					Backup_Job::create(
						array(
							'database' => 'files' !== $what,
							'files'    => 'database' !== $what,
							'note'     => self::post( 'note' ),
						)
					)
				);
				return array( 'job' => $job->summary() );
			}
		);
	}

	/**
	 * Cancel the running job.
	 *
	 * @return void
	 */
	public static function ajax_cancel(): void {
		self::guard();
		self::respond(
			static function (): array {
				Runner::cancel();
				return array();
			}
		);
	}

	/**
	 * List the backups in storage.
	 *
	 * @return void
	 */
	public static function ajax_list(): void {
		self::guard();
		self::respond( static fn() => array( 'backups' => Catalog::all( '1' === self::post( 'fresh' ) ) ) );
	}

	/**
	 * Restore a backup.
	 *
	 * @return void
	 */
	public static function ajax_restore(): void {
		self::guard();
		self::respond(
			static function (): array {
				if ( 'yes' !== self::post( 'confirm' ) ) {
					throw new \InvalidArgumentException( esc_html__( 'Tick the box to confirm that the restore replaces this site’s files and database.', 'zinn-backups' ) );
				}
				$what = self::post( 'what' );
				$job  = Runner::start(
					Restore_Job::from_backup(
						array(
							'destination' => self::post( 'destination' ),
							'remote'      => self::post( 'remote' ),
							'files'       => 'database' !== $what,
							'database'    => 'files' !== $what,
							'passphrase'  => isset( $_POST['passphrase'] ) ? (string) wp_unslash( $_POST['passphrase'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ran; a passphrase is used as typed, never output or stored in plain text.
						)
					)
				);
				return array( 'job' => $job->summary() );
			}
		);
	}

	/**
	 * Delete a backup from storage.
	 *
	 * @return void
	 */
	public static function ajax_delete(): void {
		self::guard();
		self::respond( static fn() => array( 'deleted' => Catalog::delete( self::post( 'destination' ), self::post( 'remote' ) ) ) );
	}

	/**
	 * Save a destination.
	 *
	 * @return void
	 */
	public static function ajax_save_destination(): void {
		self::guard();
		self::respond(
			static function (): array {
				$input                    = isset( $_POST['destination'] ) && is_array( $_POST['destination'] ) ? map_deep( wp_unslash( $_POST['destination'] ), 'strval' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checked the nonce; each field is sanitised in Destinations::from_input(); secrets are kept as typed.
				list( $record, $secrets ) = Destinations::from_input( $input );
				$saved                    = Settings::put_destination( $record, $secrets );
				Catalog::flush();
				return array(
					'destination' => Admin::public_destination( $saved ),
				);
			}
		);
	}

	/**
	 * Remove a destination (the backups in it are not touched).
	 *
	 * @return void
	 */
	public static function ajax_delete_destination(): void {
		self::guard();
		self::respond(
			static function (): array {
				Settings::delete_destination( self::post( 'id' ) );
				return array();
			}
		);
	}

	/**
	 * Check that a destination works.
	 *
	 * @return void
	 */
	public static function ajax_test_destination(): void {
		self::guard();
		self::respond(
			static function (): array {
				$record = Settings::destination( self::post( 'id' ) );
				if ( null === $record ) {
					throw new \InvalidArgumentException( esc_html__( 'Save the destination first.', 'zinn-backups' ) );
				}
				Destinations::client( $record )->test();
				return array( 'message' => __( 'Connected: a test file was written and removed.', 'zinn-backups' ) );
			}
		);
	}

	/**
	 * Start signing in to Dropbox or Google.
	 *
	 * @return void
	 */
	public static function ajax_oauth_start(): void {
		self::guard();
		self::respond(
			static function (): array {
				$record = Settings::destination( self::post( 'id' ) );
				if ( null === $record ) {
					throw new \InvalidArgumentException( esc_html__( 'Save the destination first.', 'zinn-backups' ) );
				}
				$verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a PKCE verifier.
				$state    = wp_generate_password( 32, false );
				set_transient(
					'zinn_backups_oauth_' . get_current_user_id(),
					array(
						'id'       => $record['id'],
						'verifier' => $verifier,
						'state'    => $state,
					),
					30 * MINUTE_IN_SECONDS
				);
				if ( 'dropbox' === $record['type'] ) {
					$key = Destinations::dropbox_key( (array) $record['config'] );
					if ( '' === $key ) {
						throw new \InvalidArgumentException( esc_html__( 'Enter the app key of your Dropbox app first (see the steps beside the form).', 'zinn-backups' ) );
					}
					return array(
						'url'   => Dropbox::authorize_url( $key, $verifier ),
						'paste' => true,
					);
				}
				if ( 'gdrive' === $record['type'] ) {
					return array(
						'url'   => Google_Drive::authorize_url( (string) $record['config']['client_id'], self::oauth_redirect(), $state, $verifier ),
						'paste' => false,
					);
				}
				throw new \InvalidArgumentException( esc_html__( 'This kind of storage does not sign in.', 'zinn-backups' ) );
			}
		);
	}

	/**
	 * Finish signing in to Dropbox with the code the person pasted.
	 *
	 * @return void
	 */
	public static function ajax_oauth_finish(): void {
		self::guard();
		self::respond(
			static function (): array {
				$pending = get_transient( 'zinn_backups_oauth_' . get_current_user_id() );
				$record  = is_array( $pending ) ? Settings::destination( (string) $pending['id'] ) : null;
				if ( null === $record || 'dropbox' !== $record['type'] ) {
					throw new \InvalidArgumentException( esc_html__( 'The sign-in expired. Press Connect again.', 'zinn-backups' ) );
				}
				$tokens = Dropbox::exchange_code( Destinations::dropbox_key( (array) $record['config'] ), self::post( 'code' ), (string) $pending['verifier'] );
				Settings::merge_secrets( (string) $record['id'], $tokens );
				delete_transient( 'zinn_backups_oauth_' . get_current_user_id() );
				Destinations::client( (array) Settings::destination( (string) $record['id'] ) )->test();
				return array( 'message' => __( 'Dropbox is connected.', 'zinn-backups' ) );
			}
		);
	}

	/**
	 * The address Google sends the person back to.
	 *
	 * @return string
	 */
	public static function oauth_redirect(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Google's return to this site: check the state, exchange the code, go back to the tab.
	 *
	 * @return void
	 */
	public static function oauth_return(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the OAuth `state` is the anti-forgery token here, checked against the one stored for this user.
		if ( ! isset( $_GET['page'], $_GET['code'], $_GET['state'] ) || self::PAGE !== $_GET['page'] || ! current_user_can( self::CAP ) ) {
			return;
		}
		$pending = get_transient( 'zinn_backups_oauth_' . get_current_user_id() );
		$state   = sanitize_text_field( wp_unslash( (string) $_GET['state'] ) );
		$code    = sanitize_text_field( wp_unslash( (string) $_GET['code'] ) );
		// phpcs:enable
		$notice = 'oauth_failed';
		if ( is_array( $pending ) && hash_equals( (string) $pending['state'], $state ) ) {
			$record = Settings::destination( (string) $pending['id'] );
			if ( null !== $record && 'gdrive' === $record['type'] ) {
				try {
					$secrets = Secrets::open( (string) $record['secret'] );
					$tokens  = Google_Drive::exchange_code( (string) $record['config']['client_id'], (string) ( $secrets['client_secret'] ?? '' ), $code, self::oauth_redirect(), (string) $pending['verifier'] );
					Settings::merge_secrets( (string) $record['id'], $tokens );
					$notice = 'oauth_ok';
				} catch ( \Throwable $e ) {
					set_transient( 'zinn_backups_notice_' . get_current_user_id(), $e->getMessage(), MINUTE_IN_SECONDS * 5 );
				}
			}
			delete_transient( 'zinn_backups_oauth_' . get_current_user_id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&tab=storage&zb_notice=' . $notice ) );
		exit;
	}

	/**
	 * Save the general settings.
	 *
	 * @return void
	 */
	public static function ajax_save_settings(): void {
		self::guard();
		self::respond(
			static function (): array {
				Settings::save_general(
					array(
						'include_core' => '1' === self::post( 'include_core' ),
						'leave_out'    => isset( $_POST['leave_out'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['leave_out'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran.
						'part_mb'      => (int) self::post( 'part_mb' ),
					)
				);
				/**
				 * Fires when the settings form is saved, so Zinn® Backups Pro can save its own fields.
				 *
				 * @param array<string,mixed> $post The submitted form (unslashed, not sanitised).
				 */
				do_action( 'zinn_backups_save_settings', wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ran; each listener sanitises its own fields.
				return array( 'message' => __( 'Settings saved.', 'zinn-backups' ) );
			}
		);
	}

	/**
	 * Start an import upload: a job id and a folder to receive the chunks.
	 *
	 * @return void
	 */
	public static function ajax_import_begin(): void {
		self::guard();
		self::respond(
			static function (): array {
				$current = Runner::current();
				if ( null !== $current && $current->running() ) {
					throw new \RuntimeException( esc_html__( 'Another backup, restore or import is running. Wait for it to finish, or cancel it.', 'zinn-backups' ) );
				}
				$id = Job::new_id();
				Work_Dir::job( $id );
				return array( 'id' => $id );
			}
		);
	}

	/**
	 * Receive one chunk of an uploaded archive.
	 *
	 * ⭐ Chunks are smaller than PHP's upload limit, so an archive of any size can be uploaded
	 * on a host whose `upload_max_filesize` is 2 MB.
	 *
	 * @return void
	 */
	public static function ajax_import_chunk(): void {
		self::guard();
		self::respond(
			static function (): array {
				$id     = self::post( 'id' );
				$name   = sanitize_file_name( self::post( 'name' ) );
				$index  = (int) self::post( 'index' );
				$offset = (int) self::post( 'offset' );
				if ( 1 !== preg_match( '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', $id ) || '' === $name ) {
					throw new \InvalidArgumentException( esc_html__( 'The upload was not started properly. Start again.', 'zinn-backups' ) );
				}
				$tmp = isset( $_FILES['chunk']['tmp_name'] ) ? (string) $_FILES['chunk']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checked the nonce; a temporary path PHP created, checked with is_uploaded_file().
				if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
					throw new \InvalidArgumentException( esc_html__( 'The chunk did not arrive. Your server may limit upload sizes; try again.', 'zinn-backups' ) );
				}
				$target = Work_Dir::job( $id ) . '/upload-' . $index . '-' . $name;
				$have   = file_exists( $target ) ? (int) filesize( $target ) : 0;
				if ( $have !== $offset ) {
					return array( 'have' => $have );
				}
				file_put_contents( $target, (string) file_get_contents( $tmp ), FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- appending an uploaded chunk to our private work folder.
				clearstatcache( true, $target );
				return array( 'have' => (int) filesize( $target ) );
			}
		);
	}

	/**
	 * Start an import from uploaded files, links, or files in the import folder.
	 *
	 * @return void
	 */
	public static function ajax_import_start(): void {
		self::guard();
		self::respond(
			static function (): array {
				if ( 'yes' !== self::post( 'confirm' ) ) {
					throw new \InvalidArgumentException( esc_html__( 'Tick the box to confirm that the import replaces this site’s content and database.', 'zinn-backups' ) );
				}
				$what   = self::post( 'what' );
				$deploy = self::post( 'deploy' );
				if ( '' !== $deploy ) {
					$code = Deploy_Code::read( $deploy );
					$job  = Runner::start(
						Restore_Job::from_backup(
							array(
								'urls'       => $code['urls'],
								'remote'     => $code['remote'],
								'files'      => 'database' !== $what,
								'database'   => 'files' !== $what,
								'passphrase' => isset( $_POST['passphrase'] ) ? (string) wp_unslash( $_POST['passphrase'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checked the nonce; a passphrase is used as typed, never output or stored in plain text.
							)
						)
					);
					return array( 'job' => $job->summary() );
				}
				$id     = self::post( 'id' );
				$id     = 1 === preg_match( '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', $id ) ? $id : Job::new_id();
				$dir    = Work_Dir::job( $id );
				$inputs = array();
				foreach ( (array) glob( $dir . '/upload-*' ) as $path ) {
					$inputs[] = array(
						'path' => $path,
						'name' => (string) preg_replace( '/^upload-\d+-/', '', basename( $path ) ),
					);
				}
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran.
				$links = isset( $_POST['urls'] ) ? preg_split( '/\R/', sanitize_textarea_field( wp_unslash( (string) $_POST['urls'] ) ) ) : array();
				foreach ( (array) $links as $link ) {
					$link = esc_url_raw( trim( (string) $link ), array( 'https', 'http' ) );
					if ( '' !== $link ) {
						$url      = Downloader::normalize( $link );
						$inputs[] = array(
							'url'  => $url,
							'name' => Downloader::name_for( $link ),
						);
					}
				}
				$server = isset( $_POST['server'] ) && is_array( $_POST['server'] ) ? array_map( 'sanitize_file_name', wp_unslash( $_POST['server'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checked the nonce; sanitize_file_name on each.
				foreach ( $server as $index => $name ) {
					$from = self::inbox() . '/' . $name;
					if ( '' !== $name && is_file( $from ) ) {
						$to = $dir . '/server-' . $index . '-' . $name;
						rename( $from, $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving the person's archive into the job folder (same disk).
						$inputs[] = array(
							'path' => $to,
							'name' => $name,
						);
					}
				}
				if ( ! $inputs ) {
					throw new \InvalidArgumentException( esc_html__( 'Choose an archive to import: upload it, paste a link, or pick one from the import folder.', 'zinn-backups' ) );
				}
				$job = Runner::start(
					Restore_Job::from_import(
						$inputs,
						array(
							'id'       => $id,
							'files'    => 'database' !== $what,
							'database' => 'files' !== $what,
						)
					)
				);
				return array( 'job' => $job->summary() );
			}
		);
	}

	/**
	 * The import folder, created on demand.
	 *
	 * @return string
	 */
	public static function inbox(): string {
		$dir = self::inbox_path();
		if ( ! is_dir( $dir ) && wp_mkdir_p( $dir ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- sealing the import folder.
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see above.
		}
		return $dir;
	}

	/**
	 * Where the import folder is.
	 *
	 * @return string
	 */
	public static function inbox_path(): string {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( (string) $uploads['basedir'] ) . '/' . self::INBOX;
	}

	/**
	 * Archives waiting in the import folder.
	 *
	 * @return array<int,array{name:string,size:int}>
	 */
	public static function inbox_files(): array {
		$out = array();
		$dir = self::inbox_path();
		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			$name = basename( $path );
			if ( is_file( $path ) && ! in_array( $name, array( 'index.php', '.htaccess' ), true ) ) {
				$out[] = array(
					'name' => $name,
					'size' => (int) filesize( $path ),
				);
			}
		}
		return $out;
	}
}
