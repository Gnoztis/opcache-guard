<?php
/**
 * Plugin Name:       OPcache Guard
 * Description:       Инвалидирует записи OPcache для файлов, заменённых обновлением плагина, темы или ядра, чтобы воркеры PHP-FPM не исполняли устаревший байткод при opcache.validate_timestamps=0.
 * Version:           1.2.0
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

/*
 * FastCGI-only internal reset.
 *
 * A FastCGI client inside this plugin calls this very file over the FPM socket
 * with the server parameter OPCACHE_GUARD_RESET_INTERNAL=1. Only FastCGI can set
 * a $_SERVER key without the HTTP_ prefix, so an HTTP request can never reach
 * this branch: a header would arrive as HTTP_OPCACHE_GUARD_RESET_INTERNAL.
 *
 * The branch runs before WordPress is loaded and prints nothing but OK/FAILED,
 * so the reset happens in the pool behind the socket without an HTTP request.
 */
if ( isset( $_SERVER['OPCACHE_GUARD_RESET_INTERNAL'] ) && '1' === (string) $_SERVER['OPCACHE_GUARD_RESET_INTERNAL'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a FastCGI server parameter, not user input.
	$opcache_guard_reset_ok = false;

	set_error_handler(
		static function () {
			return true;
		}
	);

	try {
		$opcache_guard_reset_ok = function_exists( 'opcache_reset' ) && (bool) opcache_reset();
	} catch ( \Throwable $opcache_guard_reset_error ) {
		unset( $opcache_guard_reset_error );
		$opcache_guard_reset_ok = false;
	}

	restore_error_handler();

	echo $opcache_guard_reset_ok ? 'OK' : 'FAILED'; // phpcs:ignore WordPress.Security.EscapeOutput -- a fixed two-word protocol answer.
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPCACHE_GUARD_VERSION', '1.2.0' );
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

	/** Paths already invalidated in this request, so a forced path is counted once. */
	private array $invalidated = array();

	/**
	 * File the admin editor is about to save: path and the content hash taken
	 * before core wrote it.
	 *
	 * @var array{file:string,hash:string}|null
	 */
	private ?array $edited = null;

	/** Editor result waiting to be shown on the next admin page. */
	private const NOTICE_TRANSIENT = 'opcache_guard_editor_notice';

	/** Server parameter only FastCGI can supply. */
	private const RESET_PARAM = 'OPCACHE_GUARD_RESET_INTERNAL';

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

		// Activation and deactivation change no files, but a plugin that was
		// uploaded over FTP before being activated may be cached with an older
		// content than the one on disk.
		add_action( 'activated_plugin', array( $this, 'on_plugin_state_change' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_state_change' ), 10, 2 );

		// The editors: remember the file before core writes it, invalidate in
		// the shutdown phase, once core has checked the nonce and saved it.
		// phpcs:ignore WordPress.Security.NonceVerification -- core verifies the editor nonce and only then writes; this callback just records the path and a content hash.
		add_action( 'load-plugin-editor.php', array( $this, 'remember_editor_edit' ) );
		// phpcs:ignore WordPress.Security.NonceVerification -- the theme editor verifies its nonce in core before writing; see above.
		add_action( 'load-theme-editor.php', array( $this, 'remember_editor_edit' ) );
		// phpcs:ignore WordPress.Security.NonceVerification -- the AJAX editor verifies its nonce inside wp_edit_theme_plugin_file(); nothing is invalidated unless the hash changed.
		add_action( 'wp_ajax_edit-theme-plugin-file', array( $this, 'remember_editor_edit' ), 9 );

		// Late enough to stay out of the critical path of a normal request,
		// and it fires in every context: frontend, admin, AJAX, REST, cron.
		add_action( 'init', array( $this, 'run_deferred' ), 99 );

		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_editor_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_notice' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'opcache-guard flush', array( $this, 'cli_flush' ) );
			WP_CLI::add_command( 'opcache-guard arm', array( $this, 'cli_arm' ) );
			WP_CLI::add_command( 'opcache-guard status', array( $this, 'cli_status' ) );
			WP_CLI::add_command( 'opcache-guard reset', array( $this, 'cli_reset' ) );
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
			$this->handle_error( $error );
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
			$this->handle_error( $error );
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
			$this->handle_error( $error );
		}
	}

	/**
	 * Handles plugin activation and deactivation.
	 *
	 * Neither changes files, but a plugin uploaded over FTP and activated
	 * afterwards can be cached with older content than the one on disk. The
	 * invalidation stays local (per file, never a reset) and the deferred job
	 * covers the other workers and pools. Silent by design.
	 *
	 * @param string $plugin       Plugin basename relative to WP_PLUGIN_DIR.
	 * @param bool   $network_wide Whether the action was network-wide.
	 */
	public function on_plugin_state_change( $plugin = '', $network_wide = false ): void {
		unset( $network_wide );

		$plugin = (string) $plugin;
		if ( '' === $plugin ) {
			return;
		}

		$targets = $this->plugin_targets( array( 'plugin' => $plugin ) );
		if ( ! $targets ) {
			return;
		}

		try {
			$this->queue_deferred( $targets );

			if ( ! $this->opcache_available() ) {
				$this->note_disabled();
				return;
			}

			foreach ( $targets as $target ) {
				$this->invalidate_deferred_target( $target );
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * Remembers the file an admin editor is about to save.
	 *
	 * Runs on load-*-editor.php (before core processes the POST) and on the
	 * AJAX file editor. The content hash is taken now; the invalidation happens
	 * in the shutdown phase, once core has checked the nonce and written the
	 * file. Nothing is logged.
	 */
	public function remember_editor_edit(): void {
		if ( ! is_admin() || ! isset( $_POST['newcontent'] ) ) {
			return;
		}

		$file = $this->edited_file();
		if ( '' === $file ) {
			return;
		}

		$this->edited = array(
			'file' => $file,
			'hash' => (string) md5_file( $file ),
		);

		register_shutdown_function( array( $this, 'flush_editor_edit' ) );
	}

	/**
	 * Invalidates the file saved by an admin editor, then arms the deferred job
	 * for its directory.
	 *
	 * Only a changed file is touched: when the editor request dies before core
	 * writes it (a wrong nonce makes core call wp_die), the content hash is the
	 * same and nothing is invalidated.
	 */
	public function flush_editor_edit(): void {
		if ( null === $this->edited ) {
			return;
		}

		$edited       = $this->edited;
		$this->edited = null;

		$file = (string) $edited['file'];
		if ( ! is_file( $file ) || (string) md5_file( $file ) === (string) $edited['hash'] ) {
			return;
		}

		$available = $this->opcache_available();
		$reason    = '';

		try {
			if ( $available ) {
				$this->invalidate_paths( array( $file ) );
			} else {
				$state  = $this->state();
				$reason = ! empty( $state['restricted'] ) ? 'restrict_api' : 'disabled';
			}

			if ( $this->file_cache_only() ) {
				$this->rotate_file_cache();
				$reason = 'file_cache_only';
			}

			$dir = dirname( $file );
			if ( '' !== $dir && is_dir( $dir ) ) {
				$this->queue_deferred(
					array(
						array(
							'dir'   => $dir,
							'files' => array( $file ),
							'label' => $dir,
						),
					)
				);
			}
		} catch ( \Throwable $error ) {
			unset( $error );
			$reason = '' === $reason ? 'failed' : $reason;
		}

		$this->store_editor_notice( $available && '' === $reason, $reason );
	}

	/**
	 * Absolute path of the file an editor request is saving, or an empty string.
	 *
	 * The request shape is the one core itself accepts in
	 * wp_edit_theme_plugin_file(): `file` relative to the plugin or theme
	 * directory plus `plugin` (basename) or `theme` (stylesheet). The result is
	 * resolved and checked against the directory it must live in.
	 */
	private function edited_file(): string {
		$relative = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['file'] ) ) : '';
		if ( '' === $relative ) {
			return '';
		}

		if ( function_exists( 'validate_file' ) && 0 !== validate_file( $relative ) ) {
			return '';
		}

		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['plugin'] ) ) : '';
		$theme  = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['theme'] ) ) : '';

		if ( '' !== $plugin ) {
			$base = (string) WP_PLUGIN_DIR;
		} elseif ( '' !== $theme ) {
			$base = trailingslashit( $this->themes_root() ) . ltrim( $theme, '/\\' );
		} else {
			return '';
		}

		$root = realpath( $base );
		$real = realpath( trailingslashit( $base ) . ltrim( $relative, '/\\' ) );
		if ( false === $root || false === $real ) {
			return '';
		}

		$real = trailingslashit( wp_normalize_path( (string) $real ) );
		$root = trailingslashit( wp_normalize_path( (string) $root ) );

		return 0 === strpos( $real, $root ) ? untrailingslashit( $real ) : '';
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

			// Second step: neighbouring pools behind the FastCGI socket, and
			// the file cache when this pool is served from it.
			$this->fastcgi_reset();

			if ( $this->file_cache_only() ) {
				$this->rotate_file_cache();
			}
		} catch ( \Throwable $error ) {
			$this->handle_error( $error );
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

		foreach ( $targets as $target ) {
			$outcome = $this->invalidate_target( $target );
			if ( 'reset' === $outcome['mode'] ) {
				return;
			}
		}
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
			$this->handle_error( $error );
		}

		return array(
			'count'    => $count,
			'overflow' => false,
		);
	}

	/**
	 * Drops the whole pool. Only core updates and oversized targets use it.
	 *
	 * The file cache is rotated as well: with opcache.file_cache set, dropping
	 * the shared memory leaves the compiled scripts in that directory, and a
	 * later worker can pick them up again.
	 *
	 * @param string $reason Why the reset is done; kept for the call sites.
	 */
	private function full_reset( string $reason ): void {
		unset( $reason );

		if ( ! function_exists( 'opcache_reset' ) ) {
			return;
		}

		$this->reset_pool();
		$this->rotate_file_cache();
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
	 *  FastCGI and opcache.file_cache
	 * ============================================================ */

	/**
	 * Socket of the FPM pool to talk to, or an empty string.
	 *
	 * OPCACHE_GUARD_FASTCGI in wp-config.php wins; otherwise the `address` of a
	 * .cachetool.yml next to WordPress is used. Without either, everything that
	 * relies on FastCGI stays silent.
	 */
	protected function fastcgi_address(): string {
		if ( defined( 'OPCACHE_GUARD_FASTCGI' ) ) {
			$constant = trim( (string) OPCACHE_GUARD_FASTCGI );
			if ( '' !== $constant ) {
				return $constant;
			}
		}

		foreach ( array( ABSPATH, dirname( untrailingslashit( ABSPATH ) ) ) as $root ) {
			$file = trailingslashit( $root ) . '.cachetool.yml';
			if ( ! is_readable( $file ) ) {
				continue;
			}

			$raw = $this->silently(
				static function () use ( $file ) {
					return file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a server config file, read once.
				}
			);

			if ( is_string( $raw ) && preg_match( '/^\s*address:\s*[\'"]?([^\s\'"]+)/m', $raw, $match ) ) {
				return trim( $match[1] );
			}
		}

		return '';
	}

	/**
	 * Resets the pool behind the configured FastCGI socket.
	 *
	 * The request goes to this very file with a server parameter only FastCGI
	 * can supply, so the response is the two-word protocol answer, not a page.
	 */
	private function fastcgi_reset(): bool {
		$address = $this->fastcgi_address();
		if ( '' === $address ) {
			return false;
		}

		$endpoint = $this->fastcgi_endpoint( $address );
		if ( '' === $endpoint ) {
			return false;
		}

		$answer = $this->silently(
			static function () use ( $endpoint ) {
				$errno  = 0;
				$errstr = '';
				$socket = stream_socket_client( $endpoint, $errno, $errstr, 1.0 );
				if ( ! $socket ) {
					return '';
				}

				stream_set_timeout( $socket, 1 );
				fwrite( $socket, Opcache_Guard::fastcgi_request() );

				$output = '';
				while ( ! feof( $socket ) ) {
					$header = fread( $socket, 8 );
					if ( ! is_string( $header ) || strlen( $header ) < 8 ) {
						break;
					}

					$frame   = unpack( 'Cversion/Ctype/nid/nlength/Cpadding/Creserved', $header );
					$type    = (int) $frame['type'];
					$length  = (int) $frame['length'];
					$padding = (int) $frame['padding'];

					if ( $length > 0 ) {
						$content = (string) fread( $socket, $length );
						if ( 6 === $type ) {
							$output .= $content;
						}
					}
					if ( $padding > 0 ) {
						fread( $socket, $padding );
					}
					if ( 3 === $type ) {
						break;
					}
				}

				fclose( $socket );

				return $output;
			}
		);

		return is_string( $answer ) && false !== strpos( $answer, 'OK' );
	}

	/**
	 * tcp://host:port or unix:///path/to.sock.
	 *
	 * @param string $address Socket as configured.
	 */
	private function fastcgi_endpoint( string $address ): string {
		if ( 0 === strpos( $address, 'unix://' ) || 0 === strpos( $address, 'tcp://' ) ) {
			return $address;
		}

		if ( '/' === $address[0] ) {
			return 'unix://' . $address;
		}

		return false === strpos( $address, ':' ) ? '' : 'tcp://' . $address;
	}

	/**
	 * The FastCGI request: BEGIN_REQUEST, PARAMS, empty STDIN.
	 */
	private static function fastcgi_request(): string {
		$params = array(
			'GATEWAY_INTERFACE' => 'FastCGI/1.0',
			'REQUEST_METHOD'    => 'GET',
			'SCRIPT_FILENAME'   => (string) OPCACHE_GUARD_FILE,
			'SCRIPT_NAME'       => '/' . basename( (string) OPCACHE_GUARD_FILE ),
			'DOCUMENT_ROOT'     => untrailingslashit( ABSPATH ),
			'SERVER_PROTOCOL'   => 'HTTP/1.1',
			'SERVER_SOFTWARE'   => 'opcache-guard/' . OPCACHE_GUARD_VERSION,
			'REMOTE_ADDR'       => '127.0.0.1',
			self::RESET_PARAM   => '1',
		);

		$encoded = '';
		foreach ( $params as $key => $value ) {
			$encoded .= self::fastcgi_length( strlen( (string) $key ) )
				. self::fastcgi_length( strlen( (string) $value ) )
				. $key . $value;
		}

		return self::fastcgi_frame( 1, pack( 'nC', 1, 0 ) . str_repeat( "\0", 5 ) )
			. self::fastcgi_frame( 4, $encoded )
			. self::fastcgi_frame( 4, '' )
			. self::fastcgi_frame( 5, '' );
	}

	/**
	 * One FastCGI record, padded to the 8-byte boundary.
	 *
	 * @param int    $type    Record type.
	 * @param string $content Payload.
	 */
	private static function fastcgi_frame( int $type, string $content ): string {
		$length  = strlen( $content );
		$padding = ( 8 - ( $length % 8 ) ) % 8;

		return pack( 'CCnnCC', 1, $type, 1, $length, $padding, 0 ) . $content . str_repeat( "\0", $padding );
	}

	/**
	 * FastCGI name/value length: one byte, or four with the high bit set.
	 *
	 * @param int $length Byte length.
	 */
	private static function fastcgi_length( int $length ): string {
		return $length < 128 ? chr( $length ) : pack( 'N', $length | 0x80000000 );
	}

	/**
	 * The configured opcache.file_cache directory, or an empty string.
	 */
	protected function file_cache_directory(): string {
		$dir = (string) ini_get( 'opcache.file_cache' );

		return '' !== $dir && is_dir( $dir ) ? $dir : '';
	}

	/**
	 * Whether the pool is served from the file cache only.
	 */
	protected function file_cache_only(): bool {
		return $this->ini_on( (string) ini_get( 'opcache.file_cache_only' ) );
	}

	/**
	 * Rotates the file cache: rename the directory away, delete it, recreate it
	 * empty. When the rename fails (a mount point, EXDEV) the directory is
	 * deleted in place. A path that is not a directory is never touched.
	 */
	protected function rotate_file_cache(): void {
		$dir = $this->file_cache_directory();
		if ( '' === $dir || ! is_writable( $dir ) ) {
			return;
		}

		$trash = $dir . '.rm-' . uniqid( '', true );
		$moved = $this->silently(
			static function () use ( $dir, $trash ) {
				return rename( $dir, $trash );
			}
		);

		if ( $moved ) {
			$this->remove_path( $trash );
		} else {
			$this->remove_path( $dir );
		}

		if ( ! is_dir( $dir ) ) {
			$this->silently(
				static function () use ( $dir ) {
					return wp_mkdir_p( $dir );
				}
			);
		}
	}

	/**
	 * Deletes a file or a directory tree.
	 *
	 * @param string $path Path.
	 */
	private function remove_path( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			$this->silently(
				static function () use ( $path ) {
					return unlink( $path );
				}
			);
			return;
		}

		if ( ! is_dir( $path ) ) {
			return;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $iterator as $item ) {
				$item_path = (string) $item->getPathname();
				if ( $item->isDir() ) {
					$this->silently(
						static function () use ( $item_path ) {
							return rmdir( $item_path );
						}
					);
				} else {
					$this->silently(
						static function () use ( $item_path ) {
							return unlink( $item_path );
						}
					);
				}
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		$this->silently(
			static function () use ( $path ) {
				return rmdir( $path );
			}
		);
	}

	/**
	 * Runs a filesystem or socket call with warnings swallowed.
	 *
	 * The plugin must not write to the error log, and PHP reports a failed
	 * rename(), unlink() or socket connect as a warning.
	 *
	 * @param callable $callback Call to run.
	 * @return mixed Null when the call raised a warning or threw.
	 */
	private function silently( callable $callback ) {
		set_error_handler(
			static function () {
				return true;
			}
		);

		try {
			return $callback();
		} catch ( \Throwable $error ) {
			unset( $error );
			return null;
		} finally {
			restore_error_handler();
		}
	}

	/* ============================================================
	 *  State
	 * ============================================================ */

	/**
	 * Records the state a failure leaves behind, without writing to the log.
	 *
	 * The plugin never logs: a failure that matters (restrict_api) is stored so
	 * the editor notice and the CLI status can report it.
	 *
	 * @param \Throwable $error Error.
	 */
	private function handle_error( \Throwable $error ): void {
		$message = trim( $error->getMessage() );

		if ( '' !== $message && false !== stripos( $message, 'restrict_api' ) ) {
			$this->note_restricted( $message );
		}
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
	 * Remembers that restrict_api blocks the OPcache calls, once.
	 *
	 * The plugin never logs: the flag lets the editor notice and the CLI status
	 * explain why nothing was invalidated.
	 *
	 * @param string $message Warning text from PHP.
	 */
	private function note_restricted( string $message ): void {
		unset( $message );

		$state = $this->state();

		if ( ! empty( $state['restricted'] ) ) {
			return;
		}

		$state['restricted'] = time();
		$this->save_state( $state );
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
	 * Shows the result of the last editor save on the next admin page.
	 *
	 * The invalidation happens in the shutdown phase, after the editor page has
	 * been rendered, so the result is stored and shown here. A notice is UI, not
	 * a log: the plugin still writes nothing to the error log.
	 */
	public function maybe_editor_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = get_transient( self::NOTICE_TRANSIENT );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( self::NOTICE_TRANSIENT );

		$type = isset( $notice['type'] ) && 'success' === $notice['type'] ? 'success' : 'warning';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p></div>',
			esc_attr( $type ),
			esc_html__( 'OPcache Guard:', 'opcache-guard' ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Stores the editor result for the next admin page.
	 *
	 * @param bool   $ok     Whether the invalidation happened.
	 * @param string $reason Why it did not: restrict_api, file_cache_only, disabled.
	 */
	private function store_editor_notice( bool $ok, string $reason = '' ): void {
		if ( $ok ) {
			$message = __( 'Файл сохранён, кэш OPcache инвалидирован.', 'opcache-guard' );
		} elseif ( 'restrict_api' === $reason ) {
			$message = __( 'Файл сохранён, но OPcache заблокирован директивой opcache.restrict_api — инвалидация не выполнена.', 'opcache-guard' );
		} elseif ( 'file_cache_only' === $reason ) {
			$message = __( 'Файл сохранён, каталог opcache.file_cache повёрнут: файловый кэш будет собран заново.', 'opcache-guard' );
		} elseif ( 'disabled' === $reason ) {
			$message = __( 'Файл сохранён; OPcache в этом пуле выключен, инвалидация не требуется.', 'opcache-guard' );
		} else {
			$message = __( 'Файл сохранён, но инвалидировать OPcache не удалось.', 'opcache-guard' );
		}

		set_transient(
			self::NOTICE_TRANSIENT,
			array(
				'type'    => $ok ? 'success' : 'warning',
				'message' => $message,
			),
			MINUTE_IN_SECONDS
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
	 * Invalidates every plugin and theme file in this process, asks the pools
	 * behind the FastCGI socket to reset immediately, and arms the deferred job
	 * so the remaining HTTP pools clear themselves on their next request: a CLI
	 * process has its own OPcache and cannot reach them directly.
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
					$this->handle_error( $error );
				}
			}

			/* translators: %d: number of files. */
			$this->cli_success( sprintf( 'В этом процессе инвалидировано %d файл(ов).', $total ) );
		} else {
			$this->cli_warning( 'OPcache в этом процессе недоступен: чистим через FastCGI и отложенный проход.' );
		}

		$remote = $this->fastcgi_reset();
		if ( $remote ) {
			$this->cli_success( 'Соседние пулы сброшены через FastCGI.' );
		} elseif ( '' !== $this->fastcgi_address() ) {
			$this->cli_warning( 'FastCGI-сокет задан, но сброс в соседних пулах не удался.' );
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
	 * WP-CLI: `wp opcache-guard status [--format=table|json]`.
	 *
	 * The plugin has no admin status page on purpose: the operator reads this
	 * command.
	 *
	 * @param array $args       Positional arguments, unused.
	 * @param array $assoc_args { @type string $format table or json. }
	 */
	public function cli_status( $args = array(), $assoc_args = array() ): void {
		unset( $args );

		$data   = $this->status_data();
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		if ( 'json' === $format ) {
			$this->cli_line( (string) wp_json_encode( $data ) );
			return;
		}

		foreach ( $data as $field => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}
			$this->cli_line( str_pad( (string) $field, 22 ) . ': ' . (string) $value );
		}
	}

	/**
	 * WP-CLI: `wp opcache-guard reset [--force]`.
	 *
	 * Resets the pool of this process, rotates opcache.file_cache and asks the
	 * neighbouring pools behind the FastCGI socket to reset as well.
	 *
	 * @param array $args       Positional arguments, unused.
	 * @param array $assoc_args { @type bool $force Reset even when OPcache looks off. }
	 */
	public function cli_reset( $args = array(), $assoc_args = array() ): void {
		unset( $args );

		$force     = ! empty( $assoc_args['force'] );
		$available = $this->opcache_available();

		if ( ! $available && ! $force ) {
			$this->cli_warning( 'OPcache в этом процессе недоступен. Повторите с --force, чтобы попробовать всё равно.' );
			return;
		}

		$pool    = $this->reset_pool();
		$rotated = false !== $this->file_cache_directory();

		$this->rotate_file_cache();

		$remote = $this->fastcgi_reset();
		$socket = $this->fastcgi_address();

		$this->cli_success(
			sprintf(
				'Текущий пул: %1$s; файловый кэш: %2$s; соседние пулы: %3$s.',
				$pool ? 'сброшен' : 'не сброшен',
				$rotated ? 'повёрнут' : 'не настроен',
				'' === $socket ? 'сокет не задан' : ( $remote ? 'сброшены' : 'недоступны' )
			)
		);
	}

	/**
	 * Snapshot for `wp opcache-guard status`.
	 *
	 * @return array<string,mixed>
	 */
	private function status_data(): array {
		$status = $this->silently(
			static function () {
				return function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;
			}
		);

		$status = is_array( $status ) ? $status : array();
		$memory = isset( $status['memory_usage'] ) && is_array( $status['memory_usage'] ) ? $status['memory_usage'] : array();
		$stats  = isset( $status['opcache_statistics'] ) && is_array( $status['opcache_statistics'] ) ? $status['opcache_statistics'] : array();

		$hits   = (int) ( $stats['hits'] ?? 0 );
		$misses = (int) ( $stats['misses'] ?? 0 );
		$total  = $hits + $misses;
		$pending = $this->pending();

		return array(
			'enabled'             => ! empty( $status['opcache_enabled'] ),
			'num_cached_scripts'  => (int) ( $stats['num_cached_scripts'] ?? 0 ),
			'hits'                => $hits,
			'misses'              => $misses,
			'used_memory'         => (int) ( $memory['used_memory'] ?? 0 ),
			'free_memory'         => (int) ( $memory['free_memory'] ?? 0 ),
			'total_memory'        => (int) ( $memory['total_memory'] ?? 0 ),
			'hit_rate'            => $total > 0 ? round( $hits * 100 / $total, 2 ) : 0.0,
			'file_cache'          => (string) ini_get( 'opcache.file_cache' ),
			'validate_timestamps' => (string) ini_get( 'opcache.validate_timestamps' ),
			'sapi'                => (string) PHP_SAPI,
			'deferred_pending'    => null !== $pending,
			'deferred_last'       => null !== $pending && ! empty( $pending['last'] ) ? (int) $pending['last'] : 0,
			'fastcgi_socket'      => $this->fastcgi_address(),
		);
	}

	/**
	 * Prints one line when WP-CLI is present.
	 *
	 * @param string $line Line.
	 */
	private function cli_line( string $line ): void {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::line( $line );
		}
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
