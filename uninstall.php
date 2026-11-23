<?php
/**
 * Removes the plugin data on uninstall.
 *
 * The plugin keeps three options (one-time flags, the deferred job and its lock)
 * and the update-check transients of the bundled GitHub updater. Nothing else.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'opcache_guard_uninstall_site' ) ) {
	/**
	 * Deletes the plugin data of the current site.
	 */
	function opcache_guard_uninstall_site(): void {
		delete_option( 'opcache_guard_state' );
		delete_option( 'opcache_guard_pending' );
		delete_option( 'opcache_guard_lock' );

		delete_transient( 'opcache_guard_editor_notice' );
		delete_transient( 'opcache_guard_github_release' );
		delete_transient( 'opcache_guard_github_release_error' );
	}
}

if ( is_multisite() ) {
	$opcache_guard_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $opcache_guard_sites as $opcache_guard_site_id ) {
		switch_to_blog( (int) $opcache_guard_site_id );
		opcache_guard_uninstall_site();
		restore_current_blog();
	}
} else {
	opcache_guard_uninstall_site();
}
