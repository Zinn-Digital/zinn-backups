<?php
/**
 * A long-running job (backup, restore, import), done in short resumable steps.
 *
 * @package ZinnDigital\Backups
 */

declare( strict_types=1 );

namespace ZinnDigital\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * ⛔ A step must leave the job in a state the NEXT step can continue from, because the host
 * may end any request at `max_execution_time` and nothing after that line runs. So work is
 * cut into units that finish well inside a deadline, and `state` is saved after each unit.
 */
abstract class Job {

	/**
	 * Everything the job needs to continue, saved as JSON between steps.
	 *
	 * @var array<string,mixed>
	 */
	public array $state;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $state Saved state.
	 */
	public function __construct( array $state ) {
		$this->state = $state + array(
			'status'   => 'running',
			'stage'    => '',
			'log'      => array(),
			'progress' => array(),
			'retries'  => 0,
			'updated'  => time(),
		);
	}

	/**
	 * A new job id: time-sortable and unguessable.
	 *
	 * @return string
	 */
	public static function new_id(): string {
		return gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) );
	}

	/**
	 * The job's kind: `backup` or `restore`.
	 *
	 * @return string
	 */
	abstract public function type(): string;

	/**
	 * Do work until `$deadline` (a `microtime(true)` value) or until the stage changes.
	 *
	 * @param float $deadline When to stop.
	 * @return void
	 */
	abstract public function step( float $deadline ): void;

	/**
	 * What to tidy when the job fails or is cancelled.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		Work_Dir::remove_job( $this->id() );
	}

	/**
	 * The job id.
	 *
	 * @return string
	 */
	public function id(): string {
		return (string) $this->state['id'];
	}

	/**
	 * Whether the job still has work to do.
	 *
	 * @return bool
	 */
	public function running(): bool {
		return 'running' === $this->state['status'];
	}

	/**
	 * The job's own folder in the work directory.
	 *
	 * @return string
	 */
	public function dir(): string {
		return Work_Dir::job( $this->id() );
	}

	/**
	 * Add a line to the job log (kept to the last 200 lines).
	 *
	 * @param string $message Message (already translated).
	 * @param string $level   info|warning|error.
	 * @return void
	 */
	public function log( string $message, string $level = 'info' ): void {
		$this->state['log'][] = array( time(), $level, $message );
		if ( count( $this->state['log'] ) > 200 ) {
			$this->state['log'] = array_slice( $this->state['log'], -200 );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::log( '[' . $this->state['stage'] . '] ' . $message );
		}
	}

	/**
	 * Move to another stage.
	 *
	 * @param string $stage Stage name.
	 * @return void
	 */
	public function stage( string $stage ): void {
		$this->state['stage']    = $stage;
		$this->state['progress'] = array();
	}

	/**
	 * Set progress within the current stage.
	 *
	 * @param int $done  Units done.
	 * @param int $total Units in all (0 when unknown).
	 * @return void
	 */
	public function progress( int $done, int $total ): void {
		$this->state['progress'] = array(
			'done'  => $done,
			'total' => $total,
		);
	}

	/**
	 * Finish successfully.
	 *
	 * @param string $message Final message.
	 * @return void
	 */
	public function finish( string $message ): void {
		$this->state['status']   = 'done';
		$this->state['finished'] = time();
		$this->log( $message );
	}

	/**
	 * Fail.
	 *
	 * @param string $message Why.
	 * @return void
	 */
	public function fail( string $message ): void {
		$this->state['status']   = 'failed';
		$this->state['finished'] = time();
		$this->state['error']    = $message;
		$this->log( $message, 'error' );
	}

	/**
	 * A summary for the admin screen and WP-CLI.
	 *
	 * @return array<string,mixed>
	 */
	public function summary(): array {
		return array(
			'id'       => $this->id(),
			'type'     => $this->type(),
			'status'   => $this->state['status'],
			'stage'    => $this->state['stage'],
			'label'    => $this->stage_label(),
			'progress' => $this->state['progress'],
			'error'    => $this->state['error'] ?? '',
			'started'  => $this->state['started'] ?? 0,
			'finished' => $this->state['finished'] ?? 0,
			'log'      => array_slice( $this->state['log'], -30 ),
			'result'   => $this->state['result'] ?? array(),
		);
	}

	/**
	 * A human label for the current stage.
	 *
	 * @return string
	 */
	abstract public function stage_label(): string;
}
