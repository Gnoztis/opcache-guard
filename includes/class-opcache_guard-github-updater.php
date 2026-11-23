<?php
/**
 * GitHub updater for OPcache Guard.
 *
 * GENERATED FILE — do not edit by hand.
 * Source: tools/shared/updater/class-github-updater.php
 * Regenerate: node tools/scripts/sync-updater.mjs opcache-guard
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin updates through the native WordPress mechanism; source — GitHub
 * Releases and tags of the repository constant.
 *
 * The source of truth is the highest version of the GitHub Release and the
 * newest vX.Y.Z tag: WordPress sees the new version, shows it under Plugins
 * and updates from the package of the winning source.
 * No third-party libraries and no write requests to the repository.
 */
final class OPCACHE_GUARD_GitHub_Updater {

	private const TRANSIENT       = 'opcache_guard_github_release';
	private const ERROR_TRANSIENT = 'opcache_guard_github_release_error';

	/**
	 * PHP files invalidated one by one after an update.
	 *
	 * The site runs with opcache.validate_timestamps=0, so a worker keeps
	 * executing the bytecode of the replaced files until it is restarted.
	 * Above the cap a single opcache_reset() is cheaper than thousands of
	 * calls; the standalone guard does the same for every other update.
	 */
	private const OPCACHE_CAP = 2000;

	private static ?array $header = null;

	/**
	 * Explicit user action, not a scheduled check: must not be answered from
	 * the error backoff, which otherwise hides the fact that no request was
	 * made at all.
	 */
	private static bool $force = false;

	/**
	 * Failure of the request in flight: ['code' => int, 'reason' => string,
	 * 'at' => int]. The reason vocabulary lives in error_reason().
	 *
	 * @var array{code:int,reason:string,at:int}|null
	 */
	private ?array $last_error = null;

	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection',             [ $this, 'rename_source' ], 10, 4 );
		add_action( 'upgrader_process_complete',             [ $this, 'after_upgrade' ], 10, 2 );
		add_action( 'load-plugins.php',                      [ $this, 'maybe_force_check' ] );
		add_action( 'admin_notices',                         [ $this, 'admin_notices' ] );
		add_filter( 'plugin_action_links_' . self::basename(), [ $this, 'action_links' ] );
		add_filter( 'http_request_args', [ $this, 'authenticate_download' ], 10, 2 );
	}

	/**
	 * Attaches the token to the package download of a private repository.
	 *
	 * WordPress downloads the archive itself, outside api_get(), so without this
	 * filter a private repository answers 404 even when the token is valid.
	 * Only GitHub download hosts are touched: api.github.com (release assets),
	 * codeload.github.com (zipball) and the signed asset host. A generic
	 * github.com URL is deliberately left alone, so the token can never be sent
	 * to another plugin's download.
	 *
	 * @param array<string,mixed> $args Request arguments.
	 * @param string              $url  Request URL.
	 * @return array<string,mixed>
	 */
	public function authenticate_download( $args, $url ) {
		$token = self::token();

		if ( '' === $token || ! is_string( $url ) || ! is_array( $args ) ) {
			return $args;
		}

		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return $args;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$repo = '/' . strtolower( 'Gnoztis/opcache-guard' );

		if ( 'github.com' === $host ) {
			// A release asset is downloaded from github.com/<owner>/<repo>/
			// releases/download/...; authorise it only for our own repository, so
			// the token cannot travel to another plugin's download.
			if ( false === strpos( strtolower( $path ), '/releases/download/' ) || false === strpos( strtolower( $path ), '/' . strtolower( 'opcache-guard' ) . '/' ) ) {
				return $args;
			}
		} elseif ( 'api.github.com' === $host ) {
			// API calls carry the repository in the path: /repos/<owner>/<repo>/...
			if ( false === strpos( strtolower( $path ), $repo . '/' ) ) {
				return $args;
			}
		} elseif ( 'codeload.github.com' === $host ) {
			// codeload paths start with the repository: /<owner>/<repo>/zipball/...
			if ( 0 !== strpos( strtolower( $path ), $repo . '/' ) ) {
				return $args;
			}
		} elseif ( 'objects.githubusercontent.com' !== $host ) {
			// The signed asset host cannot expose the repository in the path.
			return $args;
		}

		$args['headers'] = is_array( $args['headers'] ?? null ) ? $args['headers'] : [];
		$args['headers']['Authorization'] = 'Bearer ' . $token;

		return $args;
	}

	/**
	 * Basename of the installed main plugin file, e.g.
	 * "<slug>/<slug>.php".
	 *
	 * Computed at runtime, never taken from a constant: the folder is
	 * whatever the site has. A GitHub zipball unpacks into
	 * Gnoztis-opcache-guard-<sha>, a manual upload can use any name.
	 * WordPress keys update_plugins->response by this basename and counts
	 * every entry for the admin badge, but lists only entries whose key
	 * matches a file from get_plugins() — so a hard-coded key shows an
	 * "update available" badge with an empty update list.
	 */
	public static function basename(): string {
		$basename = plugin_basename( OPCACHE_GUARD_FILE );

		if ( $basename === '' ) {
			// plugin_basename() returns the path relative to the plugin
			// directory; an empty result means the main file was not inside
			// it, so fall back to the documented layout.
			$basename = 'opcache-guard' . '/' . wp_basename( OPCACHE_GUARD_FILE );
		}

		return $basename;
	}

	/**
	 * Slug of the installed plugin: the folder of the main file at runtime.
	 *
	 * WordPress requests the plugin details modal with the folder the plugin
	 * is installed in, which may differ from the repository slug after a
	 * manual rename or an archive that unpacked under another name. Updates
	 * already key on plugin_basename() and keep working, so the modal has to
	 * answer under the same runtime name. When the main file sits directly in
	 * the plugins directory there is no folder, and the repository slug is the
	 * fallback.
	 */
	public static function slug(): string {
		$folder = dirname( self::basename() );

		if ( '.' === $folder || '' === $folder || '/' === $folder ) {
			return 'opcache-guard';
		}

		return $folder;
	}

	/* ============================================================
	 *  Updates
	 * ============================================================ */

	/**
	 * Adds our entry to the update_plugins transient when GitHub has a
	 * release newer than the installed version.
	 *
	 * The comparison is made against both the version constant and the
	 * header of the file on disk: inside the request that replaces the
	 * files, core re-writes this transient while the constant still holds
	 * the version loaded at the start of the request. Comparing only with
	 * the constant would re-inject "2.5.0 -> 2.5.0" right after a
	 * successful update, and the phantom entry would live up to 12 hours.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}

		$basename  = self::basename();
		$installed = $this->installed_version();

		// Runs before the early returns: a dangling entry is a leftover of
		// the previous key and must disappear even when GitHub is
		// unreachable right now.
		$this->purge_stale_entries( $transient, $basename );

		// The entry offers what is already on disk: drop it, so a phantom
		// left by an earlier run disappears on this one.
		$this->purge_installed_entry( $transient, $basename, $installed );

		$release = $this->get_release();
		if ( $release === null ) {
			return $transient;
		}

		// Newer than the running code (constant) and than the files on
		// disk (header), otherwise there is nothing to install.
		if ( version_compare( $release['version'], OPCACHE_GUARD_VERSION, '<=' )
			|| version_compare( $release['version'], $installed, '<=' ) ) {
			return $transient;
		}

		$transient->response[ $basename ] = (object) [
			'id'            => $basename,
			'slug'          => 'opcache-guard',
			'plugin'        => $basename,
			'new_version'   => $release['version'],
			'url'           => 'https://github.com/' . 'Gnoztis/opcache-guard',
			'package'       => $release['package'],
			'tested'        => $release['tested'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'icons'         => [],
			'banners'       => [],
			'compatibility' => new stdClass(),
		];

		if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
			unset( $transient->no_update[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Version of the main plugin file as it is on disk.
	 *
	 * Deliberately not cached in a static: the whole point is to see the
	 * files after Plugin_Upgrader replaced them within the current request.
	 * get_file_data() reads the first 8 KB of one file, so the cost is
	 * negligible next to the transient write it guards.
	 */
	private function installed_version(): string {
		$data = function_exists( 'get_file_data' )
			? get_file_data( OPCACHE_GUARD_FILE, [ 'Version' => 'Version' ], 'plugin' )
			: [];

		$version = isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';

		return $version !== '' ? $version : OPCACHE_GUARD_VERSION;
	}

	/**
	 * Removes our own update entry when it offers what is already installed.
	 *
	 * Core compares the entry's new_version with the version from
	 * get_plugins() and shows the row as an update anyway until the
	 * transient is rebuilt, so the entry must not stay in response.
	 *
	 * @param mixed  $transient
	 * @param string $basename Our key in the transient.
	 * @param string $installed Version from the file on disk.
	 */
	private function purge_installed_entry( $transient, string $basename, string $installed ): void {
		if ( ! isset( $transient->response[ $basename ] ) || ! is_object( $transient->response[ $basename ] ) ) {
			return;
		}

		$entry   = $transient->response[ $basename ];
		$offered = isset( $entry->new_version ) ? trim( (string) $entry->new_version ) : '';

		if ( $offered === '' || version_compare( $offered, $installed, '<=' ) ) {
			unset( $transient->response[ $basename ] );
			return;
		}

		// Not newer than the constant either — the same phantom, seen from
		// the other side.
		if ( version_compare( $offered, OPCACHE_GUARD_VERSION, '<=' ) ) {
			unset( $transient->response[ $basename ] );
		}
	}

	/**
	 * Drops update entries that point at our main file under a foreign key.
	 *
	 * Such entries are leftovers of the hard-coded basename this class used
	 * before, or of a folder that was renamed. They make the admin badge
	 * count an update that get_plugin_updates() cannot list, because the
	 * key does not match any installed plugin file.
	 *
	 * @param mixed  $transient
	 * @param string $basename Our real basename, kept as is.
	 */
	private function purge_stale_entries( $transient, string $basename ): void {
		$main = wp_basename( OPCACHE_GUARD_FILE );

		foreach ( [ 'response', 'no_update' ] as $bucket ) {
			if ( ! isset( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
				continue;
			}

			foreach ( $transient->$bucket as $key => $entry ) {
				if ( (string) $key === $basename || ! is_object( $entry ) ) {
					continue;
				}
				if ( empty( $entry->plugin ) || wp_basename( (string) $entry->plugin ) !== $main ) {
					continue;
				}

				unset( $transient->$bucket[ $key ] );
			}
		}
	}

	/**
	 * Data for the View plugin details modal.
	 *
	 * @param mixed $result
	 * @param string $action
	 * @param mixed $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}

		// The modal is requested with the installed folder name; the full
		// basename and the repository slug are accepted as well, because core
		// and older integrations ask with either of them.
		$requested = (string) $args->slug;

		if (
			$requested !== self::slug()
			&& $requested !== self::basename()
			&& $requested !== 'opcache-guard'
		) {
			return $result;
		}

		$release = $this->get_release();
		if ( $release === null ) {
			return $result;
		}

		$header = $this->header_data();

		return (object) [
			'name'          => 'OPcache Guard',
			'slug'          => 'opcache-guard',
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/Gnoztis">Gnoztis</a>',
			'homepage'      => 'https://github.com/' . 'Gnoztis/opcache-guard',
			'requires'      => $header['requires'],
			'tested'        => $this->tested_version( $header['tested'] ),
			'requires_php'  => $header['requires_php'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published_at'],
			'sections'      => [
				'description' => __( 'Инвалидирует записи OPcache для файлов, заменённых обновлением плагина, темы или ядра, чтобы воркеры PHP-FPM не исполняли устаревший байткод при opcache.validate_timestamps=0.', 'opcache-guard' ),
				'changelog'   => $release['body'] !== '' ? wpautop( $release['body'] ) : __( 'Описание изменений в релизе не заполнено.', 'opcache-guard' ),
			],
		];
	}

	/**
	 * GitHub archives unpack into a directory like
	 * Gnoztis-opcache-guard-1a2b3c4 — rename it to the directory the site
	 * actually uses (the folder of the installed main file), otherwise
	 * WordPress updates the wrong directory or adds a second copy.
	 *
	 * This is also the supply-chain gate: before anything is renamed the
	 * unpacked directory is checked for path traversal (zip-slip), the main
	 * file must be present, and the archive version must not go backwards.
	 * Any failure returns a WP_Error so the upgrader aborts before the
	 * installed plugin is touched — a failed update leaves the site intact.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param mixed  $upgrader
	 * @param array  $hook_extra
	 * @return string|WP_Error
	 */
	public function rename_source( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
			return $source;
		}
		if ( ! is_array( $hook_extra ) ) {
			return $source;
		}

		// A single update carries `plugin`, a bulk update carries `plugins`.
		// Checking only the first form left bulk installs with the folder name
		// taken from the archive, which produces a second copy of the plugin.
		$ours   = self::basename();
		$target = '';

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$target = (string) $hook_extra['plugin'];
		} elseif ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			foreach ( $hook_extra['plugins'] as $candidate ) {
				if ( $ours === (string) $candidate ) {
					$target = $ours;
					break;
				}
			}
		}

		if ( '' === $target || $ours !== $target ) {
			return $source;
		}
		if ( ! is_dir( $source ) || is_link( $source ) ) {
			return $this->package_error(
				'wss_unsafe_source',
				__( 'Архив обновления распакован в небезопасный путь — установка отменена.', 'opcache-guard' )
			);
		}

		// Zip-slip: a hostile archive can create symlinks or `../` entries, so
		// the real path of the unpacked directory must stay inside the
		// WordPress content directory (plugins, or the upgrader's work area).
		$source_real = realpath( $source );
		$content_real = realpath( WP_CONTENT_DIR );

		if ( false === $source_real || false === $content_real
			|| ! $this->is_inside( $source_real, $content_real ) ) {
			return $this->package_error(
				'wss_unsafe_source',
				__( 'Распакованный архив выходит за пределы каталога WordPress — установка отменена.', 'opcache-guard' )
			);
		}

		$main_file = trailingslashit( $source ) . wp_basename( OPCACHE_GUARD_FILE );

		if ( ! is_file( $main_file ) ) {
			return $this->package_error(
				'wss_package_missing_file',
				__( 'В архиве обновления нет главного файла плагина — установка отменена.', 'opcache-guard' )
			);
		}

		// Downgrade protection: a stale or hostile tag must not install an
		// older version over the one on disk. The version is read from the
		// archive's own header, not from the tag name.
		$archive_version = $this->version_from_file( $main_file );
		$installed       = $this->installed_version();

		if ( '' !== $archive_version && version_compare( $archive_version, $installed, '<' ) ) {
			return $this->package_error(
				'wss_downgrade',
				sprintf(
					/* translators: 1: version in the archive, 2: installed version */
					__( 'Архив содержит версию %1$s, а установлена %2$s: установка старых версий запрещена.', 'opcache-guard' ),
					$archive_version,
					$installed
				)
			);
		}

		// Rename the unpacked directory to the folder the site actually
		// uses, not to the slug: the update has to replace the installed
		// directory, otherwise WordPress ends up with two copies of the
		// plugin (one of them inactive).
		$folder = dirname( $ours );
		if ( $folder === '.' || $folder === '' || $folder === '..' || strpos( $folder, '..' ) !== false ) {
			// The main file sits directly in the plugins directory, so
			// there is no directory to rename.
			return $source;
		}

		$desired       = trailingslashit( $remote_source ) . $folder;
		$desired_parent = realpath( dirname( untrailingslashit( $desired ) ) );

		if ( false === $desired_parent
			|| ( ! $this->is_inside( $desired_parent, (string) realpath( WP_PLUGIN_DIR ) ) && ! $this->is_inside( $desired_parent, $content_real ) ) ) {
			return $this->package_error(
				'wss_unsafe_destination',
				__( 'Каталог установки вне каталога плагинов — установка отменена.', 'opcache-guard' )
			);
		}

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return $this->package_error(
				'wss_no_filesystem',
				__( 'Нет доступа к файловой системе — установка отменена, плагин не тронут.', 'opcache-guard' )
			);
		}

		if ( ! $wp_filesystem->move( $source, $desired, true ) ) {
			return $this->package_error(
				'wss_package_move_failed',
				__( 'Не удалось переименовать каталог обновления — установка отменена, установленный плагин не тронут.', 'opcache-guard' )
			);
		}

		// Rollback: a move that produced no main file must not reach the
		// destination. Remove what we created and abort.
		if ( ! is_file( trailingslashit( $desired ) . wp_basename( OPCACHE_GUARD_FILE ) ) ) {
			$wp_filesystem->delete( $desired, true );

			return $this->package_error(
				'wss_package_incomplete',
				__( 'После распаковки в каталоге нет главного файла — установка отменена, изменения отменены.', 'opcache-guard' )
			);
		}

		return trailingslashit( $desired );
	}

	/**
	 * WP_Error for a refused package, with one shared code prefix.
	 *
	 * @return WP_Error
	 */
	private function package_error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message );
	}

	/**
	 * Whether a real path is the root itself or lives below it.
	 */
	private function is_inside( string $path, string $root ): bool {
		if ( '' === $root ) {
			return false;
		}

		$root = untrailingslashit( $root );

		return $path === $root || 0 === strpos( $path, $root . DIRECTORY_SEPARATOR );
	}

	/**
	 * Version header of a file, empty when it cannot be read.
	 */
	private function version_from_file( string $file ): string {
		if ( ! function_exists( 'get_file_data' ) || ! is_file( $file ) ) {
			return '';
		}

		$data = get_file_data( $file, [ 'Version' => 'Version' ], 'plugin' );

		return isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';
	}

	/**
	 * Reset the update-check cache after an upgrade so we do not keep
	 * advertising an update for the version just installed.
	 */
	public function after_upgrade( $upgrader = null, $hook_extra = [] ): void {
		$ours_updated = false;

		if ( is_array( $hook_extra ) ) {
			$ours = self::basename();
			$hit  = false;

			if ( ! empty( $hook_extra['plugin'] ) ) {
				$hit = $ours === (string) $hook_extra['plugin'];
			} elseif ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				$hit = in_array( $ours, $hook_extra['plugins'], true );
			}

			// Another plugin was updated: leave our cached release and backoff
			// alone, otherwise every unrelated update costs a GitHub request.
			if ( ! $hit ) {
				return;
			}

			$ours_updated = true;
		}

		$this->flush();

		// Only our own update, and only per file: a full reset is the
		// standalone guard's job (OC-001), so the two never double-reset in
		// the normal path. Invalidate twice would be harmless anyway.
		if ( $ours_updated ) {
			$this->invalidate_opcache();
		}
	}

	/**
	 * Drops the bytecode of the files just replaced.
	 *
	 * A no-op when OPcache is absent or disabled: the site then reads the
	 * files from disk on every request and nothing has to be invalidated.
	 * The log line carries no paths, only the plugin slug and the count.
	 */
	private function invalidate_opcache(): void {
		$invalidate = function_exists( 'opcache_invalidate' );

		if ( ! $invalidate && ! function_exists( 'opcache_reset' ) ) {
			return;
		}

		$folder = dirname( self::basename() );
		$dir    = ( '.' === $folder || '' === $folder )
			? untrailingslashit( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : dirname( OPCACHE_GUARD_FILE ) )
			: trailingslashit( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : dirname( OPCACHE_GUARD_FILE ) ) . $folder;

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$count = 0;

		try {
			$files = $this->plugin_php_files( $dir );

			// Over the cap: one reset instead of thousands of calls.
			if ( null === $files ) {
				if ( function_exists( 'opcache_reset' ) ) {
					opcache_reset();
				}

				return;
			}

			foreach ( $files as $file ) {
				if ( $invalidate ) {
					opcache_invalidate( $file, true );
				}
				$count++;
			}
		} catch ( Throwable $e ) {
			// A failed invalidation must never break the update response.
			return;
		}

		// Intentionally silent: a successful invalidation needs no log entry, and a
		// failed one is swallowed above so the update response stays intact.
	}

	/**
	 * PHP files of this plugin's directory.
	 *
	 * @return string[]|null Null when the directory holds more than the cap.
	 */
	private function plugin_php_files( string $dir ): ?array {
		$files    = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( (string) $file->getExtension() ) ) {
				continue;
			}

			$files[] = $file->getPathname();

			if ( count( $files ) > self::OPCACHE_CAP ) {
				return null;
			}
		}

		return $files;
	}

	/**
	 * Update state for the /me diagnostics.
	 *
	 * Answers "why does the admin show an update badge with nothing to
	 * update?" in one request: the real basename, and the entry the
	 * update_plugins transient holds for our main file — including the key
	 * it sits under, which is where a mismatch shows up.
	 *
	 * @return array{plugin_basename:string,transient_entry:bool,transient_key:string,new_version:string,package:string,last_error:array{code:int,reason:string,at:int}|null,next_retry_in:int,token_configured:bool}
	 */
	public static function diagnostic(): array {
		$basename = self::basename();

		$out = [
			'plugin_basename'  => $basename,
			'transient_entry'  => false,
			'transient_key'    => '',
			'new_version'      => '',
			'package'          => '',
			'last_error'       => self::saved_error(),
			'next_retry_in'    => self::retry_in(),
			'token_configured' => '' !== self::token(),
		];

		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return $out;
		}

		$entry = null;
		$key   = '';

		if ( isset( $transient->response[ $basename ] ) && is_object( $transient->response[ $basename ] ) ) {
			$entry = $transient->response[ $basename ];
			$key   = $basename;
		} else {
			// No entry under our key: report a dangling one, if any, so the
			// badge/list mismatch is visible directly.
			$main = wp_basename( OPCACHE_GUARD_FILE );
			foreach ( $transient->response as $candidate_key => $candidate ) {
				if ( ! is_object( $candidate ) || empty( $candidate->plugin ) ) {
					continue;
				}
				if ( wp_basename( (string) $candidate->plugin ) !== $main ) {
					continue;
				}
				$entry = $candidate;
				$key   = (string) $candidate_key;
				break;
			}
		}

		if ( ! is_object( $entry ) ) {
			return $out;
		}

		$out['transient_entry'] = true;
		$out['transient_key']   = $key;
		$out['new_version']     = isset( $entry->new_version ) ? (string) $entry->new_version : '';
		$out['package']         = isset( $entry->package ) ? (string) $entry->package : '';

		return $out;
	}

	/**
	 * Check for updates link in the plugin row.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public function action_links( $links ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return (array) $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'opcache_guard-force-check', '1', admin_url( 'plugins.php' ) ),
			'opcache_guard_force_check'
		);

		$links = (array) $links;

		// The last check failed: say so, otherwise "no update" and "the check
		// crashed" look exactly the same in the plugin row.
		$error = self::saved_error();
		if ( null !== $error ) {
			$links['opcache_guard_state'] = '<span class="opcache_guard-check-failed" style="color:#b32d2e">'
				. esc_html( self::error_label( $error ) )
				. '</span>';
		}

		$links['opcache_guard_check'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Проверить обновления', 'opcache-guard' ) . '</a>';

		return $links;
	}

	/**
	 * Explains a failed check on the Plugins screen.
	 *
	 * Without it the failure is invisible: WordPress only shows "no updates",
	 * and an exhausted GitHub quota looks like a plugin without releases.
	 */
	public function admin_notices(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		$error = self::saved_error();
		if ( null === $error ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'OPcache Guard: проверка обновлений не удалась.', 'opcache-guard' ),
			esc_html( self::error_label( $error ) . '.' ),
			esc_html( self::error_hint( $error ) )
		);
	}

	/**
	 * Handles the Check for updates click on plugins.php.
	 */
	public function maybe_force_check(): void {
		if ( ! isset( $_GET['opcache_guard-force-check'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'opcache_guard_force_check' );

		// The click is an explicit request for a fresh check: ignore and drop
		// the error backoff, then flush the caches and ask GitHub again.
		self::$force = true;

		$this->flush();

		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Drops every cached value of this class.
	 *
	 * Called after an upgrade, so the next run does not reuse the release
	 * data (and the header values) read before the files were replaced.
	 */
	private function flush(): void {
		delete_transient( self::TRANSIENT );
		delete_transient( self::ERROR_TRANSIENT );
		delete_site_transient( 'update_plugins' );

		self::$header = null;
	}

	/* ============================================================
	 *  GitHub API
	 * ============================================================ */

	/**
	 * Latest version data (6 hour cache, 15 minute error cache).
	 */
	private function get_release(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
			return $cached;
		}

		// An explicit force-check is not answered from the backoff: the user
		// asked for a request, and a cached failure must not fake an answer.
		if ( ! self::$force && null !== self::saved_error() ) {
			return null;
		}

		$this->last_error = null;
		$release          = $this->fetch_release();

		if ( $release === null ) {
			$error = $this->last_error ?? [
				'code'   => 404,
				'reason' => 'not_found',
				'at'     => time(),
			];

			set_transient( self::ERROR_TRANSIENT, $error, 900 );

			return null;
		}

		// A success clears the backoff: the next scheduled check may run again.
		delete_transient( self::ERROR_TRANSIENT );
		set_transient( self::TRANSIENT, $release, 21600 );

		return $release;
	}

	/**
	 * Latest version data: the highest version of both sources.
	 *
	 * Both sources are always queried (the winner is cached as a whole).
	 * Taking the release and using the tag only when there is no release
	 * hides a fresh tag behind an older GitHub Release: the repository
	 * keeps release 1.6.3, the owner pushes tag 1.7.0, and the update is
	 * never offered until a release is created by hand.
	 *
	 * Equal versions: the release wins, it carries the changelog.
	 */
	private function fetch_release(): ?array {
		$release = $this->fetch_latest_release();
		$tag     = $this->fetch_latest_tag();

		if ( $release === null && $tag === null ) {
			return null;
		}
		if ( $release === null ) {
			return $tag;
		}
		if ( $tag === null ) {
			return $release;
		}

		return version_compare( $tag['version'], $release['version'], '>' ) ? $tag : $release;
	}

	private function fetch_latest_release(): ?array {
		$body = $this->api_get( sprintf( 'https://api.github.com/repos/%s/releases/latest', 'Gnoztis/opcache-guard' ) );
		if ( $body === null ) {
			return null;
		}

		$version = $this->normalize_version( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '' );
		if ( $version === '' ) {
			return null;
		}

		$package = $this->find_package( $body );
		if ( $package === '' ) {
			return null;
		}

		return $this->build_release(
			$version,
			$package,
			isset( $body['body'] ) ? (string) $body['body'] : '',
			isset( $body['published_at'] ) ? (string) $body['published_at'] : ''
		);
	}

	/**
	 * Highest semver tag of the repository. Competes with the release in
	 * fetch_release(); a tag without a GitHub Release is a normal case.
	 */
	private function fetch_latest_tag(): ?array {
		$tags = $this->api_get(
			sprintf( 'https://api.github.com/repos/%s/tags?per_page=30', 'Gnoztis/opcache-guard' )
		);

		if ( ! is_array( $tags ) ) {
			return null;
		}

		$best_version = '';
		$best_tag     = '';

		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || empty( $tag['name'] ) ) {
				continue;
			}
			$version = $this->normalize_version( (string) $tag['name'] );
			if ( $version === '' ) {
				continue;
			}
			if ( $best_version === '' || version_compare( $version, $best_version, '>' ) ) {
				$best_version = $version;
				$best_tag     = (string) $tag['name'];
			}
		}

		if ( $best_version === '' || $best_tag === '' ) {
			return null;
		}

		$package = $this->safe_package_url(
			sprintf(
				'https://api.github.com/repos/%s/zipball/%s',
				'Gnoztis/opcache-guard',
				rawurlencode( $best_tag )
			)
		);

		if ( $package === '' ) {
			return null;
		}

		return $this->build_release( $best_version, $package, '', '' );
	}

	/**
	 * @param string $version
	 * @param string $package
	 * @param string $body
	 * @param string $published_at
	 */
	private function build_release( string $version, string $package, string $body, string $published_at ): array {
		$header = $this->header_data();

		return [
			'version'      => $version,
			'package'      => $package,
			'body'         => $body,
			'published_at' => $published_at,
			'tested'       => $this->tested_version( $header['tested'] ),
			'requires'     => $header['requires'],
			'requires_php' => $header['requires_php'],
		];
	}

	/**
	 * "Tested up to" turned into the value WordPress compares with.
	 *
	 * The header holds a branch claim ("7.1"), while the update screen
	 * compares the stored value with the full running version ("7.1.5"):
	 * version_compare( $update->tested, $cur_wp_version, '>=' ). A branch
	 * claim is always lower than a patch release, so a bare "7.1" still
	 * renders "Compatibility with WordPress 7.1.5: Not tested".
	 *
	 * api.wordpress.org resolves this by serving the patch release of the
	 * declared branch: readmes say "Tested up to: 7.1" and the API answers
	 * "7.1.5". The same expansion is done here, so the claim covers the
	 * running patch release of the branch the author declared.
	 *
	 * @param string $tested Header value, e.g. "7.1"; empty when absent.
	 * @return string Empty when the header is absent, otherwise a version.
	 */
	private function tested_version( string $tested ): string {
		$tested = trim( $tested );
		if ( '' === $tested ) {
			return '';
		}

		$running = function_exists( 'wp_get_wp_version' ) ? (string) wp_get_wp_version() : '';
		if ( '' === $running && isset( $GLOBALS['wp_version'] ) ) {
			$running = (string) $GLOBALS['wp_version'];
		}
		$running = trim( (string) preg_replace( '/-.*/', '', $running ) );
		if ( '' === $running ) {
			return $tested;
		}

		$tested_parts  = explode( '.', $tested );
		$running_parts = explode( '.', $running );

		// Already as specific as the running version, or more so.
		if ( count( $tested_parts ) >= count( $running_parts ) ) {
			return $tested;
		}

		// Same branch: the claim covers the running patch release as well.
		if ( array_slice( $running_parts, 0, count( $tested_parts ) ) === $tested_parts ) {
			return $running;
		}

		return $tested;
	}

	/**
	 * GET to the GitHub API with our User-Agent and an optional token.
	 *
	 * Failures are recorded in $last_error instead of being flattened to null:
	 * a 403 rate limit, a 404 tag and a dead network need different advice.
	 * A 404 is deliberately not recorded — for releases/latest "no release"
	 * is a normal answer and the tag list may still provide the update.
	 *
	 * @return array<string,mixed>|null
	 */
	private function api_get( string $url ): ?array {
		// The token is attached only to GitHub hosts: the URL comes from the
		// repository constant, never from user input.
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$args  = [
			'timeout'     => 5,
			'redirection' => 2,
			'headers'     => [
				'Accept'       => 'application/vnd.github+json',
				'User-Agent'   => 'OPCache-Guard/' . OPCACHE_GUARD_VERSION,
				'Content-Type' => 'application/json',
			],
		];

		$token = self::token();
		if ( '' !== $token && ( 'api.github.com' === $host || 'github.com' === $host ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		// The URL is built from the repository constant, not from user
		// input; scheme and host of the answer are validated separately.
		$response = wp_remote_get( $url, $args ); // phpcs:ignore -- URL built from the repository constant; scheme and host are validated below.

		if ( is_wp_error( $response ) ) {
			$this->last_error = [
				'code'   => 0,
				'reason' => 'network',
				'at'     => time(),
			];
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			if ( 404 !== $code ) {
				$this->last_error = [
					'code'   => $code,
					'reason' => self::error_reason( $code, $response ),
					'at'     => time(),
				];
			}
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			$this->last_error = [
				'code'   => $code,
				'reason' => 'invalid',
				'at'     => time(),
			];
			return null;
		}

		return $body;
	}

	/**
	 * Why the request failed, in the vocabulary used by the admin notice.
	 *
	 * @param int              $code     HTTP status.
	 * @param array<string,mixed>|WP_Error $response Raw response.
	 */
	private static function error_reason( int $code, $response ): string {
		if ( 429 === $code ) {
			return 'rate_limit';
		}

		if ( 403 === $code ) {
			// GitHub reports an exhausted quota as a 403 with a zero
			// remaining counter; a plain 403 means the token lacks access.
			$remaining = '';
			if ( ! is_wp_error( $response ) ) {
				$remaining = (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
			}

			return '' === trim( $remaining ) || '0' === trim( $remaining ) ? 'rate_limit' : 'unauthorized';
		}

		if ( 404 === $code ) {
			return 'not_found';
		}

		if ( 401 === $code ) {
			return 'unauthorized';
		}

		return 'invalid';
	}

	/**
	 * Saved failure of the last check, or null when the last check succeeded.
	 *
	 * Before 2.6.2 this transient held a bare `1`, so a rate limit, a missing
	 * tag and a dead network were indistinguishable. An old-style value may
	 * still be in the cache right after the upgrade; it is reported as
	 * "unknown" rather than ignored.
	 *
	 * @return array{code:int,reason:string,at:int}|null
	 */
	private static function saved_error(): ?array {
		$error = get_transient( self::ERROR_TRANSIENT );

		if ( is_array( $error ) && isset( $error['reason'] ) ) {
			return [
				'code'   => (int) ( $error['code'] ?? 0 ),
				'reason' => (string) $error['reason'],
				'at'     => (int) ( $error['at'] ?? 0 ),
			];
		}

		if ( $error ) {
			return [
				'code'   => 0,
				'reason' => 'unknown',
				'at'     => 0,
			];
		}

		return null;
	}

	/**
	 * Seconds until the failed check may be retried; 0 when there is none.
	 */
	private static function retry_in(): int {
		$error = self::saved_error();

		if ( null === $error || $error['at'] <= 0 ) {
			return 0;
		}

		return max( 0, $error['at'] + (int) 900 - time() );
	}

	/**
	 * Human readable form of a failure, e.g. "проверка не удалась: 403
	 * (лимит GitHub API)".
	 *
	 * @param array{code:int,reason:string,at:int} $error
	 */
	private static function error_label( array $error ): string {
		$reasons = [
			'rate_limit'   => __( 'лимит GitHub API', 'opcache-guard' ),
			'not_found'    => __( 'репозиторий или тег не найдены', 'opcache-guard' ),
			'unauthorized' => __( 'токен отклонён', 'opcache-guard' ),
			'network'      => __( 'нет связи с GitHub', 'opcache-guard' ),
			'invalid'      => __( 'неожиданный ответ GitHub', 'opcache-guard' ),
			'unknown'      => __( 'неизвестная ошибка', 'opcache-guard' ),
		];

		$reason = $reasons[ $error['reason'] ] ?? $reasons['unknown'];
		$code   = $error['code'] > 0 ? (string) $error['code'] : '—';

		return sprintf(
			/* translators: 1: HTTP status or a dash, 2: failure reason */
			__( 'проверка не удалась: %1$s (%2$s)', 'opcache-guard' ),
			$code,
			$reason
		);
	}

	/**
	 * What to do about a failure.
	 *
	 * @param array{code:int,reason:string,at:int} $error
	 */
	private static function error_hint( array $error ): string {
		switch ( $error['reason'] ) {
			case 'rate_limit':
				return __( 'Задайте OPCACHE_GUARD_GITHUB_TOKEN в wp-config.php: без токена GitHub разрешает 60 запросов в час на IP, с токеном — 5000.', 'opcache-guard' );
			case 'not_found':
				return __( 'Проверьте, что тег вида vX.Y.Z существует и репозиторий доступен текущему токену: приватный репозиторий без прав тоже отвечает 404.', 'opcache-guard' );
			case 'unauthorized':
				return __( 'Проверьте OPCACHE_GUARD_GITHUB_TOKEN: токен должен иметь доступ на чтение этого репозитория.', 'opcache-guard' );
			case 'network':
				return __( 'Хостинг не смог открыть api.github.com: проверьте исходящие запросы и DNS.', 'opcache-guard' );
			default:
				return __( 'Повторите проверку позже; если ошибка повторяется, посмотрите ответ api.github.com.', 'opcache-guard' );
		}
	}

	/**
	 * Archive URL: the release zip asset first, then the GitHub zipball.
	 *
	 * @param array $body
	 */
	private function find_package( array $body ): string {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if ( ! preg_match( '/\.zip$/i', $name ) ) {
					continue;
				}
				$url = $this->safe_package_url( (string) $asset['browser_download_url'] );
				if ( $url !== '' ) {
					return $url;
				}
			}
		}

		if ( ! empty( $body['zipball_url'] ) ) {
			return $this->safe_package_url( (string) $body['zipball_url'] );
		}

		return '';
	}

	/**
	 * HTTPS only and GitHub hosts only — the package must not come
	 * from a field a third-party service could tamper with.
	 */
	private function safe_package_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( $url === '' ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== strtolower( (string) $scheme ) ) {
			return '';
		}

		$allowed = [ 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' ];
		if ( ! in_array( strtolower( (string) $host ), $allowed, true ) ) {
			return '';
		}

		return $url;
	}

	private function normalize_version( string $tag ): string {
		$tag = trim( $tag );
		if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m ) ) {
			return '';
		}
		return $m[1];
	}

	/**
	 * Personal access token for the GitHub API, if the site provides one.
	 *
	 * Optional: a public repository needs no token, but the anonymous quota is
	 * 60 requests per hour per IP, and several plugins behind one hosting IP
	 * exhaust it. The value is never printed — diagnostic() only reports
	 * whether it is configured.
	 */
	private static function token(): string {
		$token = defined( 'OPCACHE_GUARD_GITHUB_TOKEN' ) ? (string) OPCACHE_GUARD_GITHUB_TOKEN : '';
		$token = (string) apply_filters( 'opcache_guard_github_token', $token );
		return trim( $token );
	}

	/**
	 * Version headers of the plugin file.
	 *
	 * `Tested up to` and `Requires at least` are different things: the
	 * first is the newest WordPress the plugin was checked against, the
	 * second is the oldest it runs on. Feeding the minimum into `tested`
	 * makes the update screen show "compatibility: unknown", so they are
	 * read separately and `tested` stays empty when the header is absent.
	 *
	 * @return array{requires:string,requires_php:string,tested:string}
	 */
	private function header_data(): array {
		if ( self::$header === null ) {
			$data = function_exists( 'get_file_data' )
				? get_file_data(
					OPCACHE_GUARD_FILE,
					[
						'Requires at least' => 'Requires at least',
						'Requires PHP'      => 'Requires PHP',
						'Tested up to'      => 'Tested up to',
					],
					'plugin'
				)
				: [];

			self::$header = [
				'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
				'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
				'tested'       => isset( $data['Tested up to'] ) ? (string) $data['Tested up to'] : '',
			];
		}

		return self::$header;
	}
}
