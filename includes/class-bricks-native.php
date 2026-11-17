<?php
/**
 * Formularios de Bricks ya hechos → CRM (modo de transición de docs/03, construido en 0.2.0).
 *
 * Para sitios que ya tienen sus formularios en Bricks (con su diseño, sus IDs para GTM, sus
 * acciones de correo) y no conviene rehacerlos con el elemento del conector.
 *
 * Cómo se usa:
 *  1. En Bricks, al formulario se le agrega la acción «Custom» (las demás se quedan).
 *  2. En Ajustes → Lucuma CRM → «Formularios de Bricks», una línea por formulario:
 *         <id del elemento de Bricks> = <id del formulario del CRM>
 *  3. **El ID de cada campo en Bricks es la clave del campo en el CRM** (email → email). No hay
 *     mapeo campo por campo: es lo que se rompía con el conector de Sperant.
 *
 * Reglas para que un formulario existente encaje sin tocarlo:
 *  - Un campo `name` (nombre completo) se parte en `fname` y `lname` si el CRM no los recibe.
 *  - Los campos de Bricks que el CRM no conoce no se pierden: se agregan, con su etiqueta, al
 *    campo de mensaje del CRM (o al final si no tiene).
 *  - Teléfonos sin «+» se completan con el prefijo del país de los ajustes (1 = EE. UU.); sin
 *    prefijo configurado, el CRM los interpreta como peruanos.
 *
 * Seguridad de siempre: si el CRM no responde, el envío va a la cola con la misma clave de
 * idempotencia y se manda el correo de respaldo. Si el CRM responde 400 (los campos no encajan
 * con su esquema) no se reintenta: queda en la cola como fallido, para verlo en el admin. El
 * visitante siempre ve el mensaje de éxito de Bricks; nunca se le muestra un error por esto.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Bricks_Native {

	/**
	 * Engancha la acción «Custom» de Bricks.
	 */
	public static function init() {
		add_action( 'bricks/form/custom_action', array( __CLASS__, 'handle' ), 10, 1 );
	}

	/**
	 * Mapa «id de Bricks => id del CRM» leído de los ajustes.
	 *
	 * @return array
	 */
	public static function mapa() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n|,/', (string) lcrm_setting( 'bricks_forms', '' ) ) as $linea ) {
			$partes = array_map( 'trim', explode( '=', $linea, 2 ) );
			if ( 2 === count( $partes ) && '' !== $partes[0] && '' !== $partes[1] ) {
				$out[ sanitize_text_field( $partes[0] ) ] = sanitize_text_field( $partes[1] );
			}
		}
		return $out;
	}

	/**
	 * Teléfono a E.164 con el prefijo del país configurado. Con «+» se respeta tal cual.
	 *
	 * @param string $crudo Teléfono como lo escribió el visitante.
	 * @return string
	 */
	public static function telefono( $crudo ) {
		$crudo  = trim( (string) $crudo );
		$prefijo = preg_replace( '/\D/', '', (string) lcrm_setting( 'phone_prefix', '' ) );
		$d      = preg_replace( '/\D/', '', $crudo );
		if ( '' === $d || '' === $prefijo || 0 === strpos( $crudo, '+' ) ) {
			return $crudo;
		}
		$d = ltrim( $d, '0' );
		// Ya trae el código de país (p. ej. 1-717-555-0123 en EE. UU.).
		if ( 0 === strpos( $d, $prefijo ) && strlen( $d ) > 10 ) {
			return '+' . $d;
		}
		return '+' . $prefijo . $d;
	}

	/**
	 * Arma los valores para el CRM a partir de los campos de Bricks.
	 *
	 * @param array $campos_bricks Definición de campos del elemento (id, label, type).
	 * @param array $enviados      Lo enviado: form-field-<id> => valor.
	 * @param array $schema        Esquema del formulario del CRM.
	 * @return array
	 */
	public static function valores( $campos_bricks, $enviados, $schema ) {
		$claves_crm = array();
		$mensaje    = '';
		foreach ( (array) ( isset( $schema['fields'] ) ? $schema['fields'] : array() ) as $f ) {
			$claves_crm[ $f['key'] ] = $f;
			if ( '' === $mensaje && isset( $f['semantic'] ) && 'message' === $f['semantic'] ) {
				$mensaje = $f['key'];
			}
		}

		// Sin esquema (CRM caído y nunca cacheado) se confía en los IDs tal cual: es la convención.
		$sin_esquema = empty( $claves_crm );
		$valores     = array();
		$extras      = array();
		foreach ( (array) $campos_bricks as $campo ) {
			$id = isset( $campo['id'] ) ? (string) $campo['id'] : '';
			if ( '' === $id || ! array_key_exists( 'form-field-' . $id, $enviados ) ) {
				continue;
			}
			$v = $enviados[ 'form-field-' . $id ];
			if ( is_array( $v ) ) {
				$v = implode( ', ', array_map( 'strval', $v ) );
			}
			$v = trim( sanitize_textarea_field( (string) $v ) );
			if ( '' === $v ) {
				continue;
			}
			$clave = sanitize_key( $id );
			if ( 'name' === $clave && ! isset( $claves_crm['name'] ) && ( $sin_esquema || isset( $claves_crm['fname'] ) ) ) {
				$partes = preg_split( '/\s+/', $v, 2 );
				$valores['fname'] = $partes[0];
				if ( isset( $partes[1] ) && ( $sin_esquema || isset( $claves_crm['lname'] ) ) ) {
					$valores['lname'] = $partes[1];
				}
			} elseif ( $sin_esquema || isset( $claves_crm[ $clave ] ) ) {
				$valores[ $clave ] = $v;
			} else {
				$etiqueta = ! empty( $campo['label'] ) ? wp_strip_all_tags( (string) $campo['label'] ) : $id;
				$extras[] = $etiqueta . ': ' . $v;
			}
		}

		if ( isset( $valores['phone'] ) ) {
			$valores['phone'] = self::telefono( $valores['phone'] );
		}
		if ( $extras ) {
			$linea = implode( "\n", $extras );
			if ( '' !== $mensaje ) {
				$valores[ $mensaje ] = isset( $valores[ $mensaje ] ) ? $valores[ $mensaje ] . "\n\n" . $linea : $linea;
			} else {
				$valores['_extra'] = $linea;
			}
		}
		return $valores;
	}

	/**
	 * Acción «Custom» de Bricks. Nunca marca error en el formulario.
	 *
	 * @param object $form Instancia \Bricks\Integrations\Form\Init.
	 */
	public static function handle( $form ) {
		if ( ! is_object( $form ) || ! method_exists( $form, 'get_fields' ) || ! method_exists( $form, 'get_settings' ) ) {
			return;
		}
		$enviados = (array) $form->get_fields();
		$bricks   = isset( $enviados['formId'] ) ? (string) $enviados['formId'] : '';
		$mapa     = self::mapa();
		if ( '' === $bricks || empty( $mapa[ $bricks ] ) ) {
			return; // Formulario de Bricks que no va al CRM.
		}
		$crm_id = $mapa[ $bricks ];

		$crm = LCRM_Form_Cache::get( $crm_id );
		if ( ! $crm ) {
			// Sin esquema no se sabe qué campos acepta: se encola crudo con lo que hay y se avisa.
			$crm = array( 'schema' => array( 'name' => $bricks, 'fields' => array() ) );
		}
		$settings = (array) $form->get_settings();
		$valores  = self::valores( isset( $settings['fields'] ) ? $settings['fields'] : array(), $enviados, $crm['schema'] );

		$post_id = isset( $enviados['postId'] ) ? absint( $enviados['postId'] ) : 0;
		$payload = array(
			'idempotencyKey' => wp_generate_uuid4(),
			'values'         => $valores,
			'consent'        => array(
				// Un campo «consent» marcado en Bricks cuenta como aceptación.
				'accepted' => ! empty( $enviados['form-field-consent'] ),
				'version'  => isset( $crm['schema']['consent']['version'] ) ? (string) $crm['schema']['consent']['version'] : '',
			),
			'attribution'    => array_filter(
				array(
					'pageUrl'  => $post_id ? (string) get_permalink( $post_id ) : '',
					'referrer' => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
				)
			),
			// Bricks ya hizo sus propias comprobaciones; este envío va de servidor a servidor.
			'antispam'       => array( 'honeypot' => '' ),
		);

		$res = LCRM_API_Client::submit( $crm_id, $payload );
		if ( ! empty( $res['ok'] ) ) {
			do_action( 'lucuma_crm_after_submit', $crm_id, $valores, true );
			return;
		}

		$detalle = is_array( $res['body'] ) && isset( $res['body']['error'] ) ? $res['body']['error'] : '';
		$id      = LCRM_Queue::push( $crm_id, $payload, trim( 'HTTP ' . $res['code'] . ' ' . $detalle ) );
		if ( $id && 400 === (int) $res['code'] ) {
			// Reintentar no lo arregla (los campos no encajan): queda fallido para verlo en el admin.
			$fila = (object) array( 'id' => $id, 'attempts' => 0 );
			LCRM_Queue::mark_failed( $fila, 'HTTP 400 ' . $detalle );
		}
		lcrm_log( 'Envío de Bricks no sincronizado', array( 'bricks' => $bricks, 'form' => $crm_id, 'code' => $res['code'] ) );

		// El correo de respaldo del conector solo cuando no llegó: Bricks ya manda el suyo.
		LCRM_Backup_Mail::send( $crm, $valores, array( 'sincronizado' => false, 'pageUrl' => $payload['attribution']['pageUrl'] ?? '' ) );
		do_action( 'lucuma_crm_after_submit', $crm_id, $valores, false );
	}
}
