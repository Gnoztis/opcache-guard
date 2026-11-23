<?php
/**
 * Plugin Name:       OPcache Guard
 * Description:       Инвалидирует записи OPcache для файлов, заменённых обновлением плагина, темы или ядра, чтобы воркеры PHP-FPM не исполняли устаревший байткод при opcache.validate_timestamps=0.
 * Version:           1.0.2
 * Requires at least: 7.0
 * Tested up to:      7.1
 * Requires PHP:      8.0
 * Author:            Gnostis
 * Author URI:        https://github.com/Gnoztis
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/Gnoztis/opcache-guard
 * Text Domain:       opcache-guard
 *
 * @package OpcacheGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPCACHE_GUARD_VERSION', '1.0.2' );
define( 'OPCACHE_GUARD_FILE', __FILE__ );

/*
 * A target with more files than this is invalidated with one full reset: the
 * per-file walk costs more than recompiling the pool. Override in wp-config.php
 * before the plugin loads.
 */
if ( ! defined( 'OPCACHE_GUARD_MAX_FILES' ) ) {
	define( 'OPCACHE_GUARD_MAX_FILES', 5000 );
}

/*
 * A target that takes longer than this many seconds is finished with a full
 * reset as well: an upgrade request must not walk a huge tree.
 */
if ( ! defined( 'OPCACHE_GUARD_MAX_SECONDS' ) ) {
	define( 'OPCACHE_GUARD_MAX_SECONDS', 5.0 );
}

/*
 * How long the deferred job stays armed, in seconds. A request in another FPM
 * pool has to see it before it expires.
 */
if ( ! defined( 'OPCACHE_GUARD_DEFERRED_TTL' ) ) {
	define( 'OPCACHE_GUARD_DEFERRED_TTL', 900 );
}

/*
 * Minimal gap between two deferred runs, in seconds: while the job is armed,
 * every pool needs one run, but no pool needs one per request.
 */
if ( ! defined( 'OPCACHE_GUARD_DEFERRED_INTERVAL' ) ) {
	define( 'OPCACHE_GUARD_DEFERRED_INTERVAL', 60 );
}

/*
 * A lock older than this is considered abandoned by a worker that died.
 */
if ( ! defined( 'OPCACHE_GUARD_LOCK_TTL' ) ) {
	define( 'OPCACHE_GUARD_LOCK_TTL', 60 );
}

/**
 * Invalidates the OPcache entries of a plugin, theme or core update.
 *
 * OPcache compares file timestamps only when opcache.validate_timestamps is on.
 * With `opcache.validate_timestamps=0` the workers keep executing the bytecode
 * they compiled before the update until the pool is restarted, which shows up
 * as a 502 or a fatal error on a site that has just been updated. Invalidating
 * per changed file fixes that without dropping the cache of every other site
 * that shares the same pool.
 *
 * A reset only reaches the pool that runs it: when the admin and the frontend
 * are served by different PHP-FPM pools, clearing from wp-admin leaves the
 * frontend stale. The update therefore also arms a short-lived option, and the
 * next normal request of every pool does the same per-file invalidation.
 */
class Opcache_Guard {

	/** Option holding the one-time flags (notice and the restrict_api log). */
	private const STATE_OPTION = 'opcache_guard_state';

	/** Option holding the deferred job: directories + expiry. */
	private const PENDING_OPTION = 'opcache_guard_pending';

	/** Option used as the deferred-run lock (add_option() is the atomic part). */
	private const LOCK_OPTION = 'opcache_guard_lock';

	/** Accepted extension of a cached file. */
	private const PHP_EXTENSION = '/\.php$/i';

	/** Deferred jobs remember at most this many directories. */
	private const MAX_PENDING_DIRS = 20;

	/** @var self|null */
	private static ?self $instance = null;

	/** A run happens once per request: hooks must not repeat the work. */
	private bool $done = false;

	/** One error line per request at most. */
	private bool $logged_error = false;

	/** Paths already invalidated in this request, so a forced path is counted once. */
	private array $invalidated = array();

	/**
	 * Accessor.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks the plugin.
	 */
	public static function init(): void {
		self::instance()->register_hooks();
	}

	/**
	 * Registers the hooks and the WP-CLI commands.
	 */
	public function register_hooks(): void {
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'on_switch_theme' ), 10, 2 );
		add_action( 'deleted_plugin', array( $this, 'on_deleted_plugin' ), 10, 2 );

		// Late enough to stay out of the critical path of a normal request,
		// and it fires in every context: frontend, admin, AJAX, REST, cron.
		add_action( 'init', array( $this, 'run_deferred' ), 99 );

		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_notice' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'opcache-guard flush', array( $this, 'cli_flush' ) );
			WP_CLI::add_command( 'opcache-guard arm', array( $this, 'cli_arm' ) );
		}
	}

	/* ============================================================
	 *  Hooks
	 * ============================================================ */

	/**
	 * Handles a finished upgrade.
	 *
	 * The target comes from $hook_extra: a plugin (one or many), a theme or
	 * the core. Core is the only case where the files cannot be enumerated
	 * cheaply, so it always gets one full reset right away.
	 *
	 * The deferred job is armed before the availability check: the admin pool
	 * may run with OPcache off while the frontend pool does not.
	 *
	 * @param mixed $upgrader   Upgrader instance, unused.
	 * @param mixed $hook_extra Update context.
	 */
	public function on_upgrade( $upgrader = null, $hook_extra = array() ): void {
		if ( $this->done ) {
			return;
		}
		$this->done = true;

		$extra = is_array( $hook_extra ) ? $hook_extra : array();
		$type  = isset( $extra['type'] ) ? (string) $extra['type'] : '';

		try {
			if ( 'core' === $type ) {
				$this->queue_deferred( $this->core_targets() );
				if ( ! $this->opcache_available() ) {
					$this->note_disabled();
					return;
				}
				$this->full_reset( 'core update' );
				return;
			}

			$targets = array();
			if ( 'plugin' === $type ) {
				$targets = $this->plugin_targets( $extra );
			}
			if ( 'theme' === $type ) {
				$targets = $this->theme_targets( $extra );
			}

			if ( ! $targets ) {
				return;
			}

			$this->queue_deferred( $targets );

			if ( ! $this->opcache_available() ) {
				$this->note_disabled();
				return;
			}

			$this->invalidate_targets( $targets );
		} catch ( \Throwable $error ) {
			$this->log_error( $error );
		}
	}

	/**
	 * Handles a theme switch: the new theme directory is already active, the
	 * previous one is still in the cache.
	 *
	 * @param string $new_name  Slug of the theme that was activated.
	 * @param mixed  $old_theme Previous WP_Theme object, when WordPress passes it.
	 */
	public function on_switch_theme( $new_name = '', $old_theme = null ): void {
		if ( $this->done ) {
			return;
		}
		$this->done = true;

		try {
			$targets = $this->theme_targets( array( 'theme' => (string) $new_name ) );

			if ( is_object( $old_theme ) && method_exists( $old_theme, 'get_template_directory' ) ) {
				$old_dir = (string) $old_theme->get_template_directory();
				if ( '' !== $old_dir ) {
					$targets[] = array(
						'dir'   => $old_dir,
						'files' => array(),
						'label' => $old_dir,
					);
				}
			}

			$this->queue_deferred( $targets );

			if ( ! $this->opcache_available() ) {
				$this->note_disabled();
				return;
			}

			$this->invalidate_targets( $targets );
		} catch ( \Throwable $error ) {
			$this->log_error( $error );
		}
	}

	/**
	 * Handles a plugin deletion: the files are gone, but OPcache may still hold
	 * their entries under the old paths.
	 *
	 * @param string $plugin_file Plugin basename relative to WP_PLUGIN_DIR.
	 * @param bool   $deleted     Whether WordPress deleted the files.
	 */
	public function on_deleted_plugin( $plugin_file = '', $deleted = true ): void {
		unset( $deleted );

		$plugin_file = (string) $plugin_file;
		if ( '' === $plugin_file || $this->done ) {
			return;
		}
		$this->done = true;

		try {
			$dir  = dirname( $plugin_file );
			$main = trailingslashit( WP_PLUGIN_DIR ) . ltrim( $plugin_file, '/\\' );

			$targets = array(
				array(
					'dir'   => '.' === $dir ? '' : trailingslashit( WP_PLUGIN_DIR ) . ltrim( $dir, '/\\' ),
					'files' => array( $main ),
					'label' => $main,
				),
			);

			$this->queue_deferred( $targets );

			if ( ! $this->opcache_available() ) {
				$this->note_disabled();
				return;
			}

			$this->invalidate_targets( $targets );
		} catch ( \Throwable $error ) {
			$this->log_error( $error );
		}
	}

	/* ============================================================
	 *  Deferred pass
	 * ============================================================ */

	/**
	 * Runs the pending deferred invalidation in whatever pool serves this
	 * request, then keeps the job armed until it expires.
	 *
	 * A reset reaches one pool only, so the pool that answered the update must
	 * not consume the job on behalf of the others: the option survives with a
	 * "last run" marker and every pool gets its own run after the interval.
	 * A short-lived lock keeps concurrent workers of one pool from duplicating
	 * the walk; a request that loses the race leaves the job for the next one.
	 */
	public function run_deferred(): void {
		// WP-CLI has its own cache and never serves HTTP requests.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$pending = $this->pending();
		if ( null === $pending ) {
			return;
		}

		$interval = (int) apply_filters( 'opcache_guard_deferred_interval', (int) OPCACHE_GUARD_DEFERRED_INTERVAL );
		$last     = isset( $pending['last'] ) ? (int) $pending['last'] : 0;

		if ( $last > 0 && ( time() - $last ) < $interval ) {
			return;
		}

		if ( ! $this->acquire_lock() ) {
			return;
		}

		try {
			// Another worker may have finished the run while this one waited.
			$pending = $this->pending();
			if ( null === $pending ) {
				return;
			}

			$last = isset( $pending['last'] ) ? (int) $pending['last'] : 0;
			if ( $last > 0 && ( time() - $last ) < $interval ) {
				return;
			}

			if ( ! $this->opcache_available() ) {
				$this->note_disabled();
				delete_option( self::PENDING_OPTION );
				return;
			}

			$total   = 0;
			$labels  = array();
			$capped  = false;

			foreach ( $pending['dirs'] as $target ) {
				$outcome = $this->invalidate_deferred_target( $target );
				$total  += $outcome['count'];
				$capped  = $capped || $outcome['capped'];

				if ( isset( $target['label'] ) && '' !== (string) $target['label'] ) {
					$labels[] = (string) $target['label'];
				}
			}

			$pending['last'] = time();
			update_option( self::PENDING_OPTION, $pending, true );

			$this->log(
				'invalidated ' . $total . ' file(s) in ' . implode( ', ', $labels )
				. ' (deferred' . ( $capped ? ', capped' : '' ) . ')'
			);
		} catch ( \Throwable $error ) {
			$this->log_error( $error );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Invalidates one deferred target per file. Never resets the pool: the
	 * deferred pass must not touch another pool's cache.
	 *
	 * @param array{dir?:string,files?:array<int,string>,label?:string} $target Target.
	 * @return array{count:int,capped:bool}
	 */
	private function invalidate_deferred_target( $target ): array {
		if ( ! is_array( $target ) ) {
			return array(
				'count'  => 0,
				'capped' => false,
			);
		}

		$dir   = isset( $target['dir'] ) ? (string) $target['dir'] : '';
		$files = isset( $target['files'] ) && is_array( $target['files'] ) ? $target['files'] : array();

		$count = $this->invalidate_paths( $files );

		if ( '' === $dir ) {
			return array(
				'count'  => $count,
				'capped' => false,
			);
		}

		$walk = $this->invalidate_directory( $dir );

		return array(
			'count'  => $count + $walk['count'],
			'capped' => $walk['overflow'],
		);
	}

	/**
	 * Reads the pending job, dropping it when it has expired.
	 *
	 * @return array{expires:int,last:int,dirs:array<int,array<string,mixed>>}|null
	 */
	private function pending(): ?array {
		$pending = get_option( self::PENDING_OPTION, array() );

		if ( ! is_array( $pending ) || empty( $pending['expires'] ) || empty( $pending['dirs'] ) ) {
			return null;
		}

		if ( time() > (int) $pending['expires'] ) {
			delete_option( self::PENDING_OPTION );
			return null;
		}

		if ( ! is_array( $pending['dirs'] ) ) {
			return null;
		}

		return $pending;
	}

	/**
	 * Arms or extends the deferred job.
	 *
	 * @param array<int,array{dir:string,files:array<int,string>,label:string}> $targets Targets to remember.
	 */
	private function queue_deferred( array $targets ): void {
		if ( ! $targets ) {
			return;
		}

		$stored = array();
		foreach ( $targets as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$dir  = isset( $target['dir'] ) ? (string) $target['dir'] : '';
			$file = isset( $target['files'] ) && is_array( $target['files'] ) ? array_values( array_map( 'strval', $target['files'] ) ) : array();

			if ( '' === $dir && ! $file ) {
				continue;
			}

			$stored[] = array(
				'dir'   => $dir,
				'files' => array_slice( $file, 0, 5 ),
				'label' => isset( $target['label'] ) ? (string) $target['label'] : $dir,
			);

			if ( count( $stored ) >= self::MAX_PENDING_DIRS ) {
				break;
			}
		}

		if ( ! $stored ) {
			return;
		}

		$current = get_option( self::PENDING_OPTION, array() );
		$dirs    = array();

		if ( is_array( $current ) && ! empty( $current['dirs'] ) && is_array( $current['dirs'] ) ) {
			foreach ( $current['dirs'] as $existing ) {
				if ( is_array( $existing ) ) {
					$dirs[ (string) ( $existing['dir'] ?? '' ) . '|' . implode( ',', (array) ( $existing['files'] ?? array() ) ) ] = $existing;
				}
			}
		}

		foreach ( $stored as $target ) {
			$dirs[ $target['dir'] . '|' . implode( ',', $target['files'] ) ] = $target;
		}

		$pending = array(
			'armed'   => time(),
			'expires' => time() + (int) apply_filters( 'opcache_guard_deferred_ttl', (int) OPCACHE_GUARD_DEFERRED_TTL ),
			'last'    => is_array( $current ) && isset( $current['last'] ) ? (int) $current['last'] : 0,
			'dirs'    => array_values( array_slice( $dirs, 0, self::MAX_PENDING_DIRS ) ),
		);

		update_option( self::PENDING_OPTION, $pending, true );
	}

	/**
	 * Core target for the deferred pass: the two directories a core update
	 * replaces. Walking ABSPATH would descend into wp-content as well.
	 *
	 * @return array<int,array{dir:string,files:array<int,string>,label:string}>
	 */
	private function core_targets(): array {
		return array(
			array(
				'dir'   => trailingslashit( ABSPATH ) . 'wp-admin',
				'files' => array(),
				'label' => trailingslashit( ABSPATH ) . 'wp-admin',
			),
			array(
				'dir'   => trailingslashit( ABSPATH ) . WPINC,
				'files' => array(),
				'label' => trailingslashit( ABSPATH ) . WPINC,
			),
		);
	}

	/**
	 * Takes the deferred-run lock.
	 *
	 * add_option() fails when the option exists, which makes it the atomic
	 * part; a lock left by a worker that died is stolen after its TTL.
	 */
	private function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $locked_at > 0 && ( time() - $locked_at ) <= (int) OPCACHE_GUARD_LOCK_TTL ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );

		return (bool) add_option( self::LOCK_OPTION, time(), '', false );
	}

	/**
	 * Releases the deferred-run lock.
	 */
	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/* ============================================================
	 *  Targets
	 * ============================================================ */

	/**
	 * Plugin targets from $hook_extra.
	 *
	 * `plugins` is a list of basenames (bulk update), `plugin` is one basename
	 * (single update). A single-file plugin has no directory of its own, so only
	 * that file is invalidated.
	 *
	 * @param array $extra Update context.
	 * @return array<int,array{dir:string,files:array<int,string>,label:string}>
	 */
	private function plugin_targets( array $extra ): array {
		$plugins = array();

		if ( ! empty( $extra['plugins'] ) && is_array( $extra['plugins'] ) ) {
			$plugins = $extra['plugins'];
		} elseif ( ! empty( $extra['plugin'] ) ) {
			$plugins = array( $extra['plugin'] );
		}

		$targets = array();
		foreach ( $plugins as $plugin ) {
			$plugin = (string) $plugin;
			if ( '' === $plugin ) {
				continue;
			}

			$main = trailingslashit( WP_PLUGIN_DIR ) . ltrim( $plugin, '/\\' );
			$dir  = dirname( $plugin );

			$targets[] = array(
				'dir'   => '.' === $dir ? '' : trailingslashit( WP_PLUGIN_DIR ) . ltrim( $dir, '/\\' ),
				'files' => array( $main ),
				'label' => $main,
			);
		}

		return $targets;
	}

	/**
	 * Theme targets from $hook_extra.
	 *
	 * @param array $extra Update context.
	 * @return array<int,array{dir:string,files:array<int,string>,label:string}>
	 */
	private function theme_targets( array $extra ): array {
		$themes = array();

		if ( ! empty( $extra['themes'] ) && is_array( $extra['themes'] ) ) {
			$themes = $extra['themes'];
		} elseif ( ! empty( $extra['theme'] ) ) {
			$themes = array( $extra['theme'] );
		}

		$root    = $this->themes_root();
		$targets = array();

		foreach ( $themes as $theme ) {
			$theme = (string) $theme;
			if ( '' === $theme ) {
				continue;
			}

			$dir = trailingslashit( $root ) . ltrim( $theme, '/\\' );

			$targets[] = array(
				'dir'   => $dir,
				'files' => array( $dir . '/style.css', $dir . '/functions.php' ),
				'label' => $dir,
			);
		}

		return $targets;
	}

	/**
	 * Theme root without a hard dependency on get_theme_root().
	 */
	private function themes_root(): string {
		if ( function_exists( 'get_theme_root' ) ) {
			return (string) get_theme_root();
		}

		return trailingslashit( WP_CONTENT_DIR ) . 'themes';
	}

	/* ============================================================
	 *  Invalidation
	 * ============================================================ */

	/**
	 * Invalidates every target and writes the single log line of this run.
	 *
	 * @param array<int,array{dir:string,files:array<int,string>,label:string}> $targets Targets.
	 */
	private function invalidate_targets( array $targets ): void {
		if ( ! $targets ) {
			return;
		}

		$total  = 0;
		$labels = array();

		foreach ( $targets as $target ) {
			$outcome = $this->invalidate_target( $target );
			if ( 'reset' === $outcome['mode'] ) {
				return;
			}

			$total   += $outcome['count'];
			$labels[] = $target['label'];
		}

		$this->log( 'invalidated ' . $total . ' file(s) in ' . implode( ', ', $labels ) );
	}

	/**
	 * Invalidates one target, or resets the pool when it is too large.
	 *
	 * @param array{dir:string,files:array<int,string>,label:string} $target Target.
	 * @return array{mode:string,count:int} Mode is "files" or "reset".
	 */
	private function invalidate_target( array $target ): array {
		$dir   = isset( $target['dir'] ) ? (string) $target['dir'] : '';
		$files = isset( $target['files'] ) && is_array( $target['files'] ) ? $target['files'] : array();

		$count = 0;

		// The main file may be gone after a rename or a deletion: force still
		// drops its entry, so it is invalidated before the directory walk.
		$count += $this->invalidate_paths( $files );

		if ( '' !== $dir ) {
			$walk = $this->invalidate_directory( $dir );

			if ( $walk['overflow'] ) {
				$this->full_reset( 'more than ' . $this->max_files() . ' files in target' );
				return array(
					'mode'  => 'reset',
					'count' => $count,
				);
			}

			$count += $walk['count'];
		}

		return array(
			'mode'  => 'files',
			'count' => $count,
		);
	}

	/**
	 * Invalidates a list of paths, including the ones that no longer exist.
	 *
	 * @param array<int,string> $paths Paths.
	 */
	private function invalidate_paths( array $paths ): int {
		$count = 0;

		foreach ( $paths as $path ) {
			$path = (string) $path;
			if ( '' === $path || isset( $this->invalidated[ $path ] ) ) {
				continue;
			}

			if ( $this->invalidate_script( $path ) ) {
				$this->invalidated[ $path ] = true;
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Walks a directory and invalidates its PHP files one by one.
	 *
	 * @param string $dir Directory.
	 * @return array{count:int,overflow:bool}
	 */
	private function invalidate_directory( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return array(
				'count'    => 0,
				'overflow' => false,
			);
		}

		$limit    = $this->max_files();
		$deadline = microtime( true ) + $this->max_seconds();
		$count    = 0;
		$seen     = 0;

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || 1 !== preg_match( self::PHP_EXTENSION, (string) $file->getFilename() ) ) {
					continue;
				}

				$seen++;

				if ( $seen > $limit || microtime( true ) > $deadline ) {
					return array(
						'count'    => $count,
						'overflow' => true,
					);
				}

				$path = (string) $file->getPathname();
				if ( isset( $this->invalidated[ $path ] ) ) {
					continue;
				}

				if ( $this->invalidate_script( $path ) ) {
					$this->invalidated[ $path ] = true;
					$count++;
				}
			}
		} catch ( \Throwable $error ) {
			// One unreadable subdirectory must not abort the whole target.
			$this->log_error( $error );
		}

		return array(
			'count'    => $count,
			'overflow' => false,
		);
	}

	/**
	 * Drops the whole pool. Only core updates and oversized targets use it.
	 *
	 * @param string $reason Why the reset is done.
	 */
	private function full_reset( string $reason ): void {
		if ( ! function_exists( 'opcache_reset' ) ) {
			$this->log_error( new \RuntimeException( 'opcache_reset() is not available' ) );
			return;
		}

		if ( $this->reset_pool() ) {
			$this->log( 'reset (' . $reason . ')' );
			return;
		}

		$this->log_error( new \RuntimeException( 'opcache_reset() failed' ) );
	}

	/* ============================================================
	 *  OPcache access
	 * ============================================================ */

	/**
	 * Invalidates one cached script.
	 *
	 * A method rather than an inline call so a test double can act as an
	 * OPcache with a real shared memory segment: php-wasm, and any SAPI built
	 * without SHM, reports opcache_get_status() === false.
	 *
	 * @param string $path Absolute path; the file may already be gone.
	 */
	protected function invalidate_script( string $path ): bool {
		return (bool) $this->guard(
			static function () use ( $path ) {
				return opcache_invalidate( $path, true );
			}
		);
	}

	/**
	 * Status of the pool behind this SAPI, or false when it is off.
	 *
	 * @return array<string,mixed>|false
	 */
	protected function pool_status() {
		return $this->guard(
			static function () {
				return opcache_get_status( false );
			}
		);
	}

	/**
	 * Drops the whole pool.
	 */
	protected function reset_pool(): bool {
		return (bool) $this->guard(
			static function () {
				return opcache_reset();
			}
		);
	}

	/**
	 * Whether the pool can be invalidated from this request at all.
	 *
	 * A disabled extension and a restrict_api that blocks this file both look
	 * like "OPcache is off": the update must stay untouched either way.
	 */
	private function opcache_available(): bool {
		if ( ! function_exists( 'opcache_invalidate' ) || ! function_exists( 'opcache_get_status' ) ) {
			return false;
		}

		/**
		 * Filters whether the guard treats OPcache as enabled.
		 *
		 * null keeps the ini check; true/false override it, which is useful for
		 * a server whose ini is not visible from PHP.
		 *
		 * @param bool|null $enabled Override.
		 */
		$enabled = apply_filters( 'opcache_guard_enabled', null );

		if ( null === $enabled ) {
			$enabled = $this->ini_on( (string) ini_get( 'opcache.enable' ) );
		}

		if ( ! $enabled ) {
			return false;
		}

		try {
			$status = $this->pool_status();
		} catch ( \Throwable $error ) {
			$this->note_restricted( $error->getMessage() );
			return false;
		}

		return is_array( $status ) && ! empty( $status['opcache_enabled'] );
	}

	/**
	 * Runs an OPcache call with warnings turned into exceptions.
	 *
	 * opcache.restrict_api reports a blocked call as a warning and a false
	 * return value; without the handler the warning would land in the debug log
	 * on every file of the loop.
	 *
	 * @param callable $callback Call to guard.
	 * @return mixed
	 */
	private function guard( callable $callback ) {
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				throw new \ErrorException( $errstr, 0, $errno );
			}
		);

		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Reads the on/off form of an ini value.
	 *
	 * @param string $value Raw value.
	 */
	private function ini_on( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( '1', 'on', 'true', 'yes' ), true );
	}

	/**
	 * File-count threshold, filterable for tests and unusual hosts.
	 */
	private function max_files(): int {
		return (int) apply_filters( 'opcache_guard_max_files', (int) OPCACHE_GUARD_MAX_FILES );
	}

	/**
	 * Time threshold in seconds, filterable for tests and unusual hosts.
	 */
	private function max_seconds(): float {
		return (float) apply_filters( 'opcache_guard_max_seconds', (float) OPCACHE_GUARD_MAX_SECONDS );
	}

	/* ============================================================
	 *  Logging and state
	 * ============================================================ */

	/**
	 * The one log line of a successful run.
	 *
	 * @param string $message Message without the prefix.
	 */
	private function log( string $message ): void {
		error_log( '[opcache-guard] ' . $message ); // phpcs:ignore -- this plugin has no other output channel.
	}

	/**
	 * One error line per request, never more: an update must not flood the log.
	 *
	 * @param \Throwable $error Error.
	 */
	private function log_error( \Throwable $error ): void {
		if ( $this->logged_error ) {
			return;
		}
		$this->logged_error = true;

		$message = trim( $error->getMessage() );

		if ( '' !== $message && false !== stripos( $message, 'restrict_api' ) ) {
			$this->note_restricted( $message );
			return;
		}

		$this->log( 'invalidation failed: ' . ( '' === $message ? 'unknown error' : $message ) );
	}

	/**
	 * Remembers that OPcache is unavailable, once.
	 *
	 * The update request itself stays silent: the notice is shown in the admin
	 * on the next page load.
	 */
	private function note_disabled(): void {
		$state = $this->state();

		if ( ! empty( $state['disabled'] ) ) {
			return;
		}

		$state['disabled'] = time();
		$this->save_state( $state );
	}

	/**
	 * Logs a restrict_api block once, then stays quiet.
	 *
	 * @param string $message Warning text from PHP.
	 */
	private function note_restricted( string $message ): void {
		$state = $this->state();

		if ( ! empty( $state['restricted'] ) ) {
			return;
		}

		$state['restricted'] = time();
		$this->save_state( $state );

		$detail = trim( $message );
		$this->log(
			'opcache_invalidate() is blocked by opcache.restrict_api; nothing was invalidated'
			. ( '' === $detail ? '' : ' (' . $detail . ')' )
		);
	}

	/**
	 * Current one-time flags.
	 *
	 * @return array<string,mixed>
	 */
	private function state(): array {
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persists the one-time flags.
	 *
	 * @param array<string,mixed> $state Flags.
	 */
	private function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	/* ============================================================
	 *  Admin notice
	 * ============================================================ */

	/**
	 * Tells the administrator once that OPcache is off, so the plugin is not
	 * expected to do anything on this server.
	 */
	public function maybe_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = $this->state();
		if ( empty( $state['disabled'] ) ) {
			return;
		}

		$dismiss = wp_nonce_url(
			add_query_arg( 'opcache_guard_dismiss', '1', admin_url( 'plugins.php' ) ),
			'opcache_guard_dismiss'
		);

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'OPcache Guard:', 'opcache-guard' ),
			esc_html__( 'OPcache в этом пуле выключен или недоступен (opcache.restrict_api) — сбрасывать нечего. Уведомление больше не появится.', 'opcache-guard' ),
			esc_url( $dismiss ),
			esc_html__( 'Скрыть', 'opcache-guard' )
		);
	}

	/**
	 * Handles the dismiss link of the notice.
	 */
	public function maybe_dismiss_notice(): void {
		if ( ! isset( $_GET['opcache_guard_dismiss'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'opcache_guard_dismiss' );

		$state = $this->state();
		unset( $state['disabled'] );
		$this->save_state( $state );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/* ============================================================
	 *  WP-CLI
	 * ============================================================ */

	/**
	 * WP-CLI: `wp opcache-guard flush`.
	 *
	 * Invalidates every plugin and theme file in this process, and arms the
	 * deferred job so the HTTP pools clear themselves on their next request:
	 * a CLI process has its own OPcache and cannot reach the FPM pools.
	 *
	 * @param array $args       Positional arguments, unused.
	 * @param array $assoc_args Flags, unused.
	 */
	public function cli_flush( $args = array(), $assoc_args = array() ): void {
		unset( $args, $assoc_args );

		$targets = $this->content_targets();

		if ( $this->opcache_available() ) {
			$total = 0;
			foreach ( $targets as $target ) {
				try {
					$outcome = $this->invalidate_target( $target );
					$total  += $outcome['count'];
				} catch ( \Throwable $error ) {
					$this->log_error( $error );
					$this->cli_warning( $error->getMessage() );
				}
			}

			/* translators: %d: number of files. */
			$this->cli_success( sprintf( 'В этом процессе инвалидировано %d файл(ов).', $total ) );
		} else {
			$this->cli_warning( 'OPcache в этом процессе недоступен: чистим только через отложенный проход.' );
		}

		$this->queue_deferred( $targets );

		$this->cli_success( 'Отложенная задача поставлена: следующий обычный запрос каждого пула почистит свой OPcache.' );
	}

	/**
	 * WP-CLI: `wp opcache-guard arm [--minutes=15]`.
	 *
	 * Only arms the deferred job. Use it after a manual FPM reload is not
	 * possible: arm, then open the site once.
	 *
	 * @param array $args       Positional arguments, unused.
	 * @param array $assoc_args { @type int $minutes How long the job stays armed. }
	 */
	public function cli_arm( $args = array(), $assoc_args = array() ): void {
		unset( $args );

		$minutes = isset( $assoc_args['minutes'] ) ? (int) $assoc_args['minutes'] : 15;
		$minutes = max( 1, min( 60, $minutes ) );

		$targets = $this->content_targets();

		$pending = array(
			'armed'   => time(),
			'expires' => time() + $minutes * MINUTE_IN_SECONDS,
			'last'    => 0,
			'dirs'    => $targets,
		);

		update_option( self::PENDING_OPTION, $pending, true );

		$this->cli_success(
			sprintf(
				/* translators: %d: number of minutes. */
				'Отложенная задача поставлена на %d мин. Откройте сайт один раз — каждый пул почистит свой OPcache.',
				$minutes
			)
		);
	}

	/**
	 * Plugin and theme directories, for the CLI commands.
	 *
	 * @return array<int,array{dir:string,files:array<int,string>,label:string}>
	 */
	private function content_targets(): array {
		return array(
			array(
				'dir'   => WP_PLUGIN_DIR,
				'files' => array(),
				'label' => WP_PLUGIN_DIR,
			),
			array(
				'dir'   => $this->themes_root(),
				'files' => array(),
				'label' => $this->themes_root(),
			),
		);
	}

	/**
	 * Prints a success line when WP-CLI is present.
	 *
	 * @param string $message Message.
	 */
	private function cli_success( string $message ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::success( $message );
		}
	}

	/**
	 * Prints a warning when WP-CLI is present.
	 *
	 * @param string $message Message.
	 */
	private function cli_warning( string $message ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::warning( $message );
		}
	}
}

Opcache_Guard::init();

/*
 * legacy-reset-notice: the third-party "OPcache Reset" plugin resets the cache only
 * in the pool that served the request, so it cannot help when admin and frontend run
 * in different PHP-FPM pools. Ask the admin to remove it instead of running both.
 */
if ( is_admin() ) {
	add_action( 'admin_notices', static function (): void {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'is_plugin_active' ) ) {
			return;
		}

		if ( ! is_plugin_active( 'opcache-reset/opcache-reset.php' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'OPcache Guard:', 'opcache-guard' ),
			esc_html__( 'Плагин «OPcache Reset» ещё активен. Он сбрасывает кэш только в том пуле, который обслужил запрос, поэтому при раздельных пулах админки и сайта не помогает. Деактивируйте и удалите его: этот плагин делает то же самое и дополнительно очищает кэш в пуле фронтенда.', 'opcache-guard' )
		);
	} );
}

/*
 * Shared GitHub updater, generated from tools/shared/updater — do not edit by hand.
 * It answers the standard WordPress update flow, so the guard is itself updated
 * from the repository like any other plugin.
 */
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
	require_once __DIR__ . '/includes/class-opcache_guard-github-updater.php';

	if ( class_exists( 'OPCACHE_GUARD_GitHub_Updater' ) ) {
		( new OPCACHE_GUARD_GitHub_Updater() )->register_hooks();
	}
}