<?php
/**
 * Configuración del conector por REST, solo para administradores (contraseña de aplicación).
 *
 *   GET  /wp-json/lucuma-crm/v1/config   ajustes sin la secret key + estado de la cola
 *   POST /wp-json/lucuma-crm/v1/config   { api_base?, public_key?, secret_key?, bricks_forms?, phone_prefix?, notify_emails? }
 *
 * Existe para poder dar de alta un sitio desde un script (llaves recién creadas en el CRM)
 * sin copiarlas a mano en la pantalla de ajustes. Solo cambia las claves que llegan.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Config_Endpoint {

	const CLAVES = array( 'api_base', 'public_key', 'secret_key', 'bricks_forms', 'phone_prefix', 'notify_emails' );

	/**
	 * Registra las rutas.
	 */
	public static function init() {
		add_action(
			'rest_api_init',
			function () {
				$admin = function () {
					return current_user_can( 'manage_options' );
				};
				register_rest_route(
					'lucuma-crm/v1',
					'/config',
					array(
						array( 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'leer' ) ),
						array( 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'guardar' ) ),
					)
				);
			}
		);
	}

	/**
	 * Ajustes visibles + cola.
	 *
	 * @return array
	 */
	public static function leer() {
		$o = get_option( LCRM_OPTION, array() );
		unset( $o['secret_key'] );
		$o['has_secret']   = '' !== (string) lcrm_setting( 'secret_key', '' );
		$o['bricks_map']   = (object) LCRM_Bricks_Native::mapa();
		$o['queue']        = LCRM_Queue::counts();
		$o['queue_recent'] = array_map(
			function ( $f ) {
				return array( 'id' => (int) $f->id, 'form' => $f->form_id, 'status' => $f->status, 'attempts' => (int) $f->attempts, 'error' => $f->last_error, 'created' => $f->created_at );
			},
			array_slice( (array) LCRM_Queue::listar( '', 10 ), 0, 10 )
		);
		$o['version'] = LCRM_VERSION;
		return $o;
	}

	/**
	 * Guarda solo las claves recibidas, con el mismo saneo de la pantalla.
	 *
	 * @param WP_REST_Request $req Petición.
	 * @return array
	 */
	public static function guardar( $req ) {
		$o = get_option( LCRM_OPTION, array() );
		if ( ! is_array( $o ) ) {
			$o = array();
		}
		foreach ( self::CLAVES as $k ) {
			$v = $req->get_param( $k );
			if ( null === $v ) {
				continue;
			}
			switch ( $k ) {
				case 'api_base':
					$o[ $k ] = esc_url_raw( trim( (string) $v ) );
					break;
				case 'bricks_forms':
					$o[ $k ] = sanitize_textarea_field( (string) $v );
					break;
				case 'phone_prefix':
					$o[ $k ] = preg_replace( '/\D/', '', (string) $v );
					break;
				default:
					$o[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		// Sin pasar por register_setting: el saneo de la pantalla reconstruye todo el arreglo.
		remove_all_filters( 'sanitize_option_' . LCRM_OPTION );
		update_option( LCRM_OPTION, $o );
		LCRM_Form_Cache::flush();
		return self::leer();
	}
}
