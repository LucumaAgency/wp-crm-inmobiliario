<?php
/**
 * Cliente HTTP del Lucuma CRM.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_API_Client {

	/**
	 * Base de la API pública.
	 *
	 * @return string
	 */
	private static function base() {
		return untrailingslashit( lcrm_setting( 'api_base', '' ) ) . '/api/v1/public';
	}

	/**
	 * GET con la secret key (server side). Nunca se usa desde el navegador.
	 *
	 * @param string $path Ruta relativa.
	 * @return array { ok: bool, code: int, body: mixed }
	 */
	public static function get_private( $path ) {
		return self::request( 'GET', $path, null, array( 'X-LCRM-Secret' => lcrm_setting( 'secret_key' ) ) );
	}

	/**
	 * GET con la public key.
	 *
	 * @param string $path Ruta relativa.
	 * @return array
	 */
	public static function get_public( $path ) {
		return self::request( 'GET', $path, null, array( 'X-LCRM-Key' => lcrm_setting( 'public_key' ) ) );
	}

	/**
	 * Envía un formulario al CRM.
	 *
	 * @param string $form_id Formulario.
	 * @param array  $payload Cuerpo ya armado (incluye idempotencyKey).
	 * @return array
	 */
	public static function submit( $form_id, $payload ) {
		return self::request(
			'POST',
			'/forms/' . rawurlencode( $form_id ) . '/submissions',
			$payload,
			array( 'X-LCRM-Key' => lcrm_setting( 'public_key' ) ),
			8 // Timeout corto: el visitante no puede quedarse esperando.
		);
	}

	/**
	 * Prueba de conexión para la pantalla de ajustes.
	 *
	 * @return array
	 */
	public static function test() {
		$base = untrailingslashit( lcrm_setting( 'api_base', '' ) );
		if ( ! $base ) {
			return array( 'ok' => false, 'message' => 'Falta la URL del CRM.' );
		}
		$salud = wp_remote_get( $base . '/api/health', array( 'timeout' => 10 ) );
		if ( is_wp_error( $salud ) ) {
			return array( 'ok' => false, 'message' => 'No se pudo conectar: ' . $salud->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $salud ) ) {
			return array( 'ok' => false, 'message' => 'El CRM respondió ' . wp_remote_retrieve_response_code( $salud ) . '.' );
		}

		$forms = self::get_private( '/forms' );
		if ( ! $forms['ok'] ) {
			return array(
				'ok'      => false,
				'message' => 401 === $forms['code']
					? 'El CRM responde, pero la secret key no es válida.'
					: 'El CRM responde, pero /forms devolvió ' . $forms['code'] . '.',
			);
		}
		$total = isset( $forms['body']['forms'] ) ? count( $forms['body']['forms'] ) : 0;
		return array(
			'ok'      => true,
			'message' => sprintf( 'Conexión correcta. %d formulario(s) disponibles.', $total ),
			'forms'   => isset( $forms['body']['forms'] ) ? $forms['body']['forms'] : array(),
		);
	}

	/**
	 * Petición genérica.
	 *
	 * @param string     $method  Método.
	 * @param string     $path    Ruta.
	 * @param array|null $body    Cuerpo.
	 * @param array      $headers Cabeceras.
	 * @param int        $timeout Timeout.
	 * @return array
	 */
	private static function request( $method, $path, $body = null, $headers = array(), $timeout = 12 ) {
		$url  = self::base() . $path;
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array_merge(
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => 'LucumaCRM-WP/' . LCRM_VERSION . '; ' . home_url(),
				),
				array_filter( $headers )
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$res = wp_remote_request( $url, $args );

		if ( is_wp_error( $res ) ) {
			lcrm_log( 'Error de red', array( 'path' => $path, 'error' => $res->get_error_message() ) );
			return array( 'ok' => false, 'code' => 0, 'body' => $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = wp_remote_retrieve_body( $res );
		$json = json_decode( $raw, true );

		return array(
			'ok'   => ( $code >= 200 && $code < 300 ),
			'code' => $code,
			'body' => ( null !== $json ) ? $json : $raw,
		);
	}
}
