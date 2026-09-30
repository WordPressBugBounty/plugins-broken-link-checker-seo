<?php
namespace AIOSEO\BrokenLinkChecker\Main\Migrations;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers, executes, and logs {@see Migration} instances.
 *
 * The runner closes the half-applied failure mode: the version flag is only
 * advanced when EVERY registered migration's verify() returns true. A
 * concurrent-request lock loser bails without touching state; a lock holder
 * that dies mid-migration leaves the relevant log entry at status = 0 (or
 * absent), and the runner retries on the next request until verify() actually
 * passes.
 *
 * @since 1.3.0
 */
class MigrationRunner {
	/**
	 * Registered migrations, in registration order.
	 *
	 * @since 1.3.0
	 *
	 * @var Migration[]
	 */
	private $migrations = [];

	/**
	 * Log accessor.
	 *
	 * @since 1.3.0
	 *
	 * @var MigrationLog
	 */
	private $log;

	/**
	 * Lock name. MySQL GET_LOCK is per-connection, so this serializes across
	 * concurrent PHP processes for the same site.
	 *
	 * @since   1.3.0
	 * @version 1.3.1 Scoped to the site, since GET_LOCK is server-wide.
	 *
	 * @var string
	 */
	private $lockName = '';

	/**
	 * How long a request waits for the lock before giving up on it, in seconds.
	 *
	 * NOTE: A short wait rather than none: the DDL a migration runs is measured in milliseconds on a
	 * table of any ordinary size, so waiting it out spares the requests behind it the shape they would
	 * otherwise be served against.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $lockTimeout = 10;

	/**
	 * @since 1.3.0
	 */
	public function __construct() {
		$this->log      = new MigrationLog();
		$this->lockName = 'aioseo_blc_migration_runner_' . get_current_blog_id();
	}

	/**
	 * Register a migration. Order is preserved — later registrations run
	 * after earlier ones within the same request.
	 *
	 * @since 1.3.0
	 *
	 * @param  Migration $migration The migration to register.
	 * @return void
	 */
	public function register( Migration $migration ) {
		$this->migrations[] = $migration;
	}

	/**
	 * Execute pending migrations.
	 *
	 * Short-circuits early when the recorded schema version matches the
	 * current plugin version — healthy sites never touch the log option.
	 * Lock losers exit silently and do NOT advance any version flag, so a
	 * future-bumped lastSchemaVersion can't strand work.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function run() {
		if ( aioseoBrokenLinkChecker()->internalOptions->internal->lastSchemaVersion === aioseoBrokenLinkChecker()->version ) {
			return;
		}

		if ( empty( $this->migrations ) ) {
			aioseoBrokenLinkChecker()->internalOptions->internal->lastSchemaVersion = aioseoBrokenLinkChecker()->version;

			return;
		}

		if ( ! aioseoBrokenLinkChecker()->core->db->acquireLock( $this->lockName, $this->lockTimeout ) ) {
			return;
		}

		try {
			$log     = $this->log->read();
			$allDone = true;

			foreach ( $this->migrations as $migration ) {
				$name = $migration->name();

				if ( $this->verifySafely( $migration ) ) {
					if ( ! isset( $log[ $name ] ) || 1 !== ( $log[ $name ]['status'] ?? 0 ) ) {
						$log[ $name ] = $this->successEntry( $migration, $log );
					}
					continue;
				}

				try {
					$migration->up();
					$verified = $this->verifySafely( $migration );

					$log[ $name ] = $verified
						? $this->successEntry( $migration, $log )
						: $this->failureEntry( $migration, $log, 'verify() returned false after up()' );

					if ( ! $verified ) {
						$allDone = false;
					}
				} catch ( \Throwable $e ) {
					$log[ $name ] = $this->failureEntry( $migration, $log, $e->getMessage() );
					$allDone = false;
				}
			}

			$this->log->write( $log );

			if ( $allDone ) {
				aioseoBrokenLinkChecker()->internalOptions->internal->lastSchemaVersion = aioseoBrokenLinkChecker()->version;
			}
		} finally {
			// Explicit release shrinks the hold window to the migration work itself.
			// acquireLock() also registers a shutdown function as belt-and-braces in
			// case execution exits before we reach this point.
			aioseoBrokenLinkChecker()->core->db->releaseLock( $this->lockName );
		}
	}

	/**
	 * Whether the migration with the given name is on record as having landed.
	 *
	 * NOTE: For the callers that must not touch a table a migration is still reshaping. A migration
	 * with no entry counts as landed once the runner has walked every one of them and moved the schema
	 * version on, so a log that was never written doesn't switch a caller off for good.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $name The migration name.
	 * @return bool         Whether it has landed.
	 */
	public function hasVerified( $name ) {
		$log = $this->log->read();

		if ( isset( $log[ $name ] ) ) {
			return 1 === (int) ( $log[ $name ]['status'] ?? 0 );
		}

		return aioseoBrokenLinkChecker()->internalOptions->internal->lastSchemaVersion === aioseoBrokenLinkChecker()->version;
	}

	/**
	 * Wrap verify() so a throwing implementation degrades to "not verified"
	 * instead of bringing down the runner. The error surfaces via the log's
	 * failureEntry on the next up()/verify() cycle.
	 *
	 * @since 1.3.0
	 *
	 * @param  Migration $migration The migration to verify.
	 * @return bool
	 */
	private function verifySafely( Migration $migration ) {
		try {
			return (bool) $migration->verify();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Build a success log entry, preserving the prior attempts counter so
	 * support can see how many retries it took to land.
	 *
	 * @since 1.3.0
	 *
	 * @param  Migration $migration The migration.
	 * @param  array     $log       The current log state.
	 * @return array
	 */
	private function successEntry( Migration $migration, array $log ) {
		$name     = $migration->name();
		$attempts = isset( $log[ $name ]['attempts'] ) ? (int) $log[ $name ]['attempts'] : 0;

		return [
			'version'    => $migration->version(),
			'ran_at'     => aioseoBrokenLinkChecker()->helpers->timeToMysql( time() ),
			'status'     => 1,
			'attempts'   => $attempts + 1,
			'last_error' => null
		];
	}

	/**
	 * Build a failure log entry, incrementing attempts so retry pressure is
	 * visible in the log.
	 *
	 * @since 1.3.0
	 *
	 * @param  Migration $migration The migration.
	 * @param  array     $log       The current log state.
	 * @param  string    $error     Reason for the failure.
	 * @return array
	 */
	private function failureEntry( Migration $migration, array $log, $error ) {
		$name     = $migration->name();
		$attempts = isset( $log[ $name ]['attempts'] ) ? (int) $log[ $name ]['attempts'] : 0;

		return [
			'version'    => $migration->version(),
			'ran_at'     => aioseoBrokenLinkChecker()->helpers->timeToMysql( time() ),
			'status'     => 0,
			'attempts'   => $attempts + 1,
			'last_error' => $error
		];
	}
}