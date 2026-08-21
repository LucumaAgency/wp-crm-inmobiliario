<?php
/**
 * Caché del esquema de formularios.
 *
 * Dos niveles a propósito:
 *  1. Transient con TTL corto, para no llamar al CRM en cada visita.
 *  2. Copia persistente de respaldo (opción), que NO vence.
 *
 * Si el CRM no responde cuando vence el transient, se dibuja la última versión buena.
 * Un formulario que desaparece porque la API tuvo un mal minuto es peor que uno
 * ligeramente desactualizado.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Form_Cache {

	const PREFIJO_TRANSIENT = 'lcrm_form_';
	const PREFIJO_RESPALDO  = 'lcrm_backup_form_';

	/**
	 * Devuelve el esquema del formulario, de caché o del CRM.
	 *
	 * @param string $form_id Formulario.
	 * @return array|null { id, version, schema, dynamicOptions } o null si nunca se pudo traer.
	 */
	public static function get( $form_id ) {
		$clave = self::PREFIJO_TRANSIENT . md5( $form_id );
		$cache = get_transient( $clave );
		if ( is_array( $cache ) ) {
			return $cache;
		}

		$res = LCRM_API_Client::get_public( '/forms/' . rawurlencode( $form_id ) );

		if ( $res['ok'] && is_array( $res['body'] ) && isset( $res['body']['schema'] ) ) {
			$ttl = max( 60, (int) lcrm_setting( 'cache_ttl', 900 ) );
			set_transient( $clave, $res['body'], $ttl );
			update_option( self::PREFIJO_RESPALDO . md5( $form_id ), $res['body'], false );
			return $res['body'];
		}

		lcrm_log( 'No se pudo traer el esquema, se usa el respaldo', array( 'form' => $form_id, 'code' => $res['code'] ) );

		$respaldo = get_option( self::PREFIJO_RESPALDO . md5( $form_id ) );
		if ( is_array( $respaldo ) && isset( $respaldo['schema'] ) ) {
			// Reintento corto para no machacar al CRM caído en cada visita.
			set_transient( $clave, $respaldo, 120 );
			return $respaldo;
		}

		return null;
	}

	/**
	 * Invalida la caché de un formulario (o de todos).
	 *
	 * @param string|null $form_id Formulario o null.
	 */
	public static function flush( $form_id = null ) {
		global $wpdb;
		if ( $form_id ) {
			delete_transient( self::PREFIJO_TRANSIENT . md5( $form_id ) );
			return;
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lcrm_form_%' OR option_name LIKE '_transient_timeout_lcrm_form_%'" ); // phpcs:ignore WordPress.DB
	}
}
