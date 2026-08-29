<?php
/**
 * Удаление данных плагина.
 *
 * @package SF_Typograf
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'sf_typograf_settings' );
delete_metadata( 'post', 0, '_sf_typograf_field_states', '', true );

global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sf_typograf_%' OR option_name LIKE '_transient_timeout_sf_typograf_%'"
);
