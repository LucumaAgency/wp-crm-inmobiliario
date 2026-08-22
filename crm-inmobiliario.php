<?php
/**
 * Plugin Name:       Lucuma CRM — Conector
 * Plugin URI:        https://github.com/LucumaAgency/wp-crm-inmobiliario
 * Description:       Renderiza en el sitio los formularios definidos en el Lucuma CRM y envía los leads. Con cola de reintentos y correo de respaldo: si el CRM no responde, no se pierde ningún lead.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Lucuma Agency
 * License:           GPL-2.0+
 * Text Domain:       lucuma-crm
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCRM_VERSION', '0.1.0' );
define( 'LCRM_FILE', __FILE__ );
define( 'LCRM_PATH', plugin_dir_path( __FILE__ ) );
define( 'LCRM_URL', plugin_dir_url( __FILE__ ) );
define( 'LCRM_OPTION', 'lucuma_crm_settings' );
define( 'LCRM_DB_VERSION', 1 );

// Action Scheduler, si viene empaquetado por Composer. Ver class-scheduler.php:
// sin él se usa wp_cron, que es menos confiable pero suficiente para arrancar.
if ( file_exists( LCRM_PATH . 'vendor/autoload.php' ) ) {
	require_once LCRM_PATH . 'vendor/autoload.php';
}

require_once LCRM_PATH . 'includes/class-settings.php';
require_once LCRM_PATH . 'includes/class-api-client.php';
require_once LCRM_PATH . 'includes/class-form-cache.php';
require_once LCRM_PATH . 'includes/class-renderer.php';
require_once LCRM_PATH . 'includes/class-queue.php';
require_once LCRM_PATH . 'includes/class-scheduler.php';
require_once LCRM_PATH . 'includes/class-backup-mail.php';
require_once LCRM_PATH . 'includes/class-submit-endpoint.php';
require_once LCRM_PATH . 'includes/class-admin-queue.php';
require_once LCRM_PATH . 'includes/class-shortcode.php';

/**
 * Arranque.
 */
function lcrm_init() {
	if ( is_admin() ) {
		new LCRM_Settings();
		new LCRM_Admin_Queue();
	}
	new LCRM_Shortcode();
	new LCRM_Submit_Endpoint();
	LCRM_Scheduler::init();
}
add_action( 'plugins_loaded', 'lcrm_init' );

/**
 * Activación: crea la tabla de la cola y los ajustes por defecto.
 */
function lcrm_activate() {
	LCRM_Queue::create_table();

	if ( false === get_option( LCRM_OPTION ) ) {
		add_option(
			LCRM_OPTION,
			array(
				'api_base'      => 'https://crm.lucuma.agency',
				'public_key'    => '',
				'secret_key'    => '',
				'notify_emails' => get_option( 'admin_email' ),
				'cache_ttl'     => 900, // 15 minutos
				'debug'         => '0',
			)
		);
	}
	LCRM_Scheduler::schedule_recurring();
}
register_activation_hook( __FILE__, 'lcrm_activate' );

/**
 * Desactivación: se quitan las tareas programadas. Los datos de la cola NO se tocan.
 */
function lcrm_deactivate() {
	LCRM_Scheduler::unschedule();
}
register_deactivation_hook( __FILE__, 'lcrm_deactivate' );

/**
 * Accesor de ajustes.
 *
 * Se lee la opción en cada llamada a propósito: congelarla en el constructor fue uno de
 * los problemas del conector anterior.
 *
 * @param string $key     Clave.
 * @param mixed  $default Valor por defecto.
 * @return mixed
 */
function lcrm_setting( $key, $default = '' ) {
	$o = get_option( LCRM_OPTION, array() );
	return isset( $o[ $key ] ) && '' !== $o[ $key ] ? $o[ $key ] : $default;
}

/**
 * Log solo con debug activo y SIN datos personales en claro.
 *
 * @param string $message Mensaje.
 * @param array  $context Contexto (se filtran claves sensibles).
 */
function lcrm_log( $message, $context = array() ) {
	if ( '1' !== lcrm_setting( 'debug', '0' ) ) {
		return;
	}
	$sensibles = array( 'email', 'phone', 'document', 'fname', 'lname', 'values', 'payload' );
	foreach ( $sensibles as $k ) {
		if ( isset( $context[ $k ] ) ) {
			$context[ $k ] = '[oculto]';
		}
	}
	error_log( '[Lucuma CRM] ' . $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ) );
}
