<?php
/**
 * Recepción del formulario y envío al CRM.
 *
 * El envío pasa por el servidor del sitio y no directo del navegador al CRM. Es lo único
 * que permite encolar cuando el CRM no responde: si el fetch saliera del navegador y la
 * API estuviera caída, el lead se perdería antes de que el plugin pudiera hacer nada.
 *
 * Secuencia en cada envío:
 *   1. Enviar al CRM.
 *   2. Si falla, encolar y reintentar con backoff.
 *   3. Enviar el correo de respaldo, siempre.
 *   4. (El aviso en el admin lo pinta LCRM_Admin_Queue.)
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Submit_Endpoint {

	const LIMITE_POR_IP = 10; // envíos por 10 minutos

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/**
	 * Registra la ruta.
	 */
	public function register() {
		register_rest_route(
			'lucuma-crm/v1',
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // formulario público
			)
		);
	}

	/**
	 * Maneja el envío.
	 *
	 * @param WP_REST_Request $req Petición.
	 * @return WP_REST_Response
	 */
	public function handle( $req ) {
		$form_id  = sanitize_text_field( (string) $req->get_param( 'formId' ) );
		$valores  = (array) $req->get_param( 'values' );
		$clave    = sanitize_text_field( (string) $req->get_param( 'idempotencyKey' ) );
		$antispam = (array) $req->get_param( 'antispam' );
		$attrib   = (array) $req->get_param( 'attribution' );
		$consent  = (array) $req->get_param( 'consent' );

		if ( ! $form_id || ! $clave ) {
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Petición incompleta.' ), 400 );
		}

		if ( $this->rate_limited() ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'message' => 'Demasiados envíos seguidos. Espera un momento.' ),
				429
			);
		}

		$form = LCRM_Form_Cache::get( $form_id );
		if ( ! $form ) {
			// Sin esquema no se puede ni validar ni armar el payload con garantías.
			lcrm_log( 'Envío sin esquema disponible', array( 'form' => $form_id ) );
			return new WP_REST_Response(
				array( 'ok' => false, 'message' => 'El formulario no está disponible en este momento.' ),
				503
			);
		}

		$schema = $form['schema'];

		// Validación de obligatorios en el servidor (el required del HTML es solo ayuda).
		foreach ( $schema['fields'] as $campo ) {
			if ( ! empty( $campo['required'] ) && '' === trim( (string) ( isset( $valores[ $campo['key'] ] ) ? $valores[ $campo['key'] ] : '' ) ) ) {
				return new WP_REST_Response(
					array( 'ok' => false, 'message' => sprintf( 'El campo "%s" es obligatorio.', $campo['label'] ) ),
					400
				);
			}
		}

		// Consentimiento (Ley 29733).
		if ( ! empty( $schema['consent']['required'] ) && empty( $consent['accepted'] ) ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'message' => 'Debes aceptar el tratamiento de datos personales.' ),
				400
			);
		}

		$payload = array(
			'idempotencyKey' => $clave,
			'values'         => $this->limpiar( $valores ),
			'consent'        => array(
				'accepted' => ! empty( $consent['accepted'] ),
				'version'  => isset( $consent['version'] ) ? sanitize_text_field( $consent['version'] ) : '',
			),
			'attribution'    => $this->atribucion( $attrib ),
			'antispam'       => array(
				'honeypot'        => isset( $antispam['honeypot'] ) ? sanitize_text_field( $antispam['honeypot'] ) : '',
				'elapsedSeconds'  => isset( $antispam['elapsedSeconds'] ) ? (int) $antispam['elapsedSeconds'] : null,
				'turnstileToken'  => isset( $antispam['turnstileToken'] ) ? sanitize_text_field( $antispam['turnstileToken'] ) : '',
			),
		);

		// 1) Enviar al CRM.
		$res          = LCRM_API_Client::submit( $form_id, $payload );
		$sincronizado = ! empty( $res['ok'] );
		$encolado     = false;

		if ( ! $sincronizado ) {
			if ( 400 === $res['code'] ) {
				// Datos inválidos: reintentar no lo arregla. Se le dice al visitante.
				$mensaje = is_array( $res['body'] ) && isset( $res['body']['error'] )
					? $res['body']['error']
					: 'Revisa los datos e intenta de nuevo.';
				return new WP_REST_Response( array( 'ok' => false, 'message' => $mensaje ), 400 );
			}
			// 2) Cualquier otro fallo: a la cola.
			$encolado = (bool) LCRM_Queue::push( $form_id, $payload, 'HTTP ' . $res['code'] );
			lcrm_log( 'Envío encolado', array( 'form' => $form_id, 'code' => $res['code'] ) );
		}

		// 3) Correo de respaldo. Siempre.
		LCRM_Backup_Mail::send(
			$form,
			$valores,
			array(
				'sincronizado' => $sincronizado,
				'pageUrl'      => isset( $attrib['pageUrl'] ) ? esc_url_raw( $attrib['pageUrl'] ) : '',
				'campaign'     => isset( $attrib['last']['utm_campaign'] ) ? sanitize_text_field( $attrib['last']['utm_campaign'] ) : '',
			)
		);

		/**
		 * Permite enganchar acciones propias tras un envío (píxeles, integraciones).
		 *
		 * @param string $form_id      Formulario.
		 * @param array  $valores      Valores enviados.
		 * @param bool   $sincronizado Si llegó al CRM en el primer intento.
		 */
		do_action( 'lucuma_crm_after_submit', $form_id, $valores, $sincronizado );

		// Al visitante se le confirma igual: su envío está guardado y garantizado.
		$exito = isset( $schema['success'] ) ? $schema['success'] : array( 'type' => 'message', 'value' => 'Gracias por escribirnos.' );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'queued'  => $encolado,
				'success' => $exito,
			),
			200
		);
	}

	/**
	 * Límite por IP: tope simple contra envíos automatizados.
	 *
	 * @return bool
	 */
	private function rate_limited() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$clave = 'lcrm_rl_' . md5( $ip );
		$n     = (int) get_transient( $clave );
		if ( $n >= self::LIMITE_POR_IP ) {
			return true;
		}
		set_transient( $clave, $n + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Sanea los valores del formulario.
	 *
	 * @param array $valores Valores.
	 * @return array
	 */
	private function limpiar( $valores ) {
		$out = array();
		foreach ( $valores as $k => $v ) {
			$k = sanitize_key( $k );
			if ( is_array( $v ) ) {
				$v = implode( ', ', array_map( 'sanitize_text_field', $v ) );
			}
			$out[ $k ] = sanitize_textarea_field( (string) $v );
		}
		return $out;
	}

	/**
	 * Sanea la atribución. La de primera visita es la que dice qué campaña trajo al lead.
	 *
	 * @param array $attrib Atribución cruda.
	 * @return array
	 */
	private function atribucion( $attrib ) {
		$utm  = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' );
		$sub  = function ( $arr ) use ( $utm ) {
			$o = array();
			foreach ( $utm as $k ) {
				if ( ! empty( $arr[ $k ] ) ) {
					$o[ $k ] = sanitize_text_field( $arr[ $k ] );
				}
			}
			return $o;
		};

		return array_filter(
			array(
				'first'       => $sub( isset( $attrib['first'] ) ? (array) $attrib['first'] : array() ),
				'last'        => $sub( isset( $attrib['last'] ) ? (array) $attrib['last'] : array() ),
				'gclid'       => isset( $attrib['gclid'] ) ? sanitize_text_field( $attrib['gclid'] ) : '',
				'fbclid'      => isset( $attrib['fbclid'] ) ? sanitize_text_field( $attrib['fbclid'] ) : '',
				'referrer'    => isset( $attrib['referrer'] ) ? esc_url_raw( $attrib['referrer'] ) : '',
				'landingPage' => isset( $attrib['landingPage'] ) ? esc_url_raw( $attrib['landingPage'] ) : '',
				'pageUrl'     => isset( $attrib['pageUrl'] ) ? esc_url_raw( $attrib['pageUrl'] ) : '',
				'pageTitle'   => isset( $attrib['pageTitle'] ) ? sanitize_text_field( $attrib['pageTitle'] ) : '',
			)
		);
	}
}
