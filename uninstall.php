<?php
/**
 * Desinstalación.
 *
 * Se borran los ajustes, pero la tabla de la cola solo si el administrador lo pidió
 * explícitamente en los ajustes. Puede contener leads que todavía no llegaron al CRM.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$opciones = get_option( 'lucuma_crm_settings', array() );

if ( isset( $opciones['delete_data_on_uninstall'] ) && '1' === $opciones['delete_data_on_uninstall'] ) {
	global $wpdb;
	$tabla = $wpdb->prefix . 'lcrm_queue';
	$wpdb->query( "DROP TABLE IF EXISTS {$tabla}" ); // phpcs:ignore WordPress.DB
}

delete_option( 'lucuma_crm_settings' );
delete_option( 'lcrm_db_version' );

// Cachés de esquemas de formulario.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lcrm_%' OR option_name LIKE '_transient_timeout_lcrm_%'" ); // phpcs:ignore WordPress.DB
