<?php
/**
 * Punto único de inserción del formulario.
 *
 * El shortcode, el bloque de Gutenberg, el elemento de Bricks y el widget de Elementor
 * son cuatro envoltorios del mismo render. Toda la lógica —caché, assets, avisos— vive
 * aquí para que no se desincronicen: si mañana el render cambia, cambia para los cuatro.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Embed {

	/**
	 * Si ya se encolaron los assets en esta petición.
	 *
	 * @var bool
	 */
	private static $assets_encolados = false;

	/**
	 * Registra los assets. Se encolan solo cuando de verdad se pinta un formulario.
	 */
	public static function registrar_assets() {
		wp_register_script( 'lcrm-form', LCRM_URL . 'assets/js/form.js', array(), LCRM_VERSION, true );
		wp_register_style( 'lcrm-form', LCRM_URL . 'assets/css/form.css', array(), LCRM_VERSION );
	}

	/**
	 * Pinta un formulario.
	 *
	 * @param string $form_id     ID del formulario en el CRM.
	 * @param bool   $con_estilo  Cargar la hoja de estilos mínima del plugin.
	 * @return string HTML.
	 */
	public static function render( $form_id, $con_estilo = true ) {
		$form_id = trim( (string) $form_id );

		if ( '' === $form_id ) {
			return self::aviso( 'Falta el ID del formulario.' );
		}

		$form = LCRM_Form_Cache::get( $form_id );
		if ( ! $form ) {
			lcrm_log( 'Formulario no disponible', array( 'form' => $form_id ) );
			return self::aviso( 'El formulario no está disponible en este momento.' );
		}

		if ( ! self::$assets_encolados ) {
			wp_enqueue_script( 'lcrm-form' );
			if ( $con_estilo ) {
				wp_enqueue_style( 'lcrm-form' );
			}
			self::$assets_encolados = true;
		}

		return LCRM_Renderer::render( $form );
	}

	/**
	 * Vista para el editor (Gutenberg, Bricks, Elementor).
	 *
	 * Se pinta el formulario real para que el diseñador vea lo que va a quedar, pero
	 * envuelto en un contenedor que bloquea la interacción: dentro del editor un submit
	 * accidental crearía un lead de verdad en el CRM del cliente.
	 *
	 * @param string $form_id    ID del formulario.
	 * @param bool   $con_estilo Cargar CSS del plugin.
	 * @return string HTML.
	 */
	public static function render_editor( $form_id, $con_estilo = true ) {
		if ( '' === trim( (string) $form_id ) ) {
			return self::marcador( 'Elige un formulario en los ajustes del elemento.' );
		}

		$html = self::render( $form_id, $con_estilo );

		return '<div class="lcrm-editor-preview" style="position:relative">'
			. '<div style="pointer-events:none">' . $html . '</div>'
			. '</div>';
	}

	/**
	 * Marcador neutro para el editor cuando todavía no hay formulario elegido.
	 *
	 * @param string $mensaje Mensaje.
	 * @return string
	 */
	public static function marcador( $mensaje ) {
		return '<div class="lcrm-marcador" style="padding:24px;border:1px dashed #c3c9d4;border-radius:8px;'
			. 'text-align:center;color:#667085;font-family:system-ui,sans-serif">'
			. esc_html( 'Lucuma CRM · ' . $mensaje ) . '</div>';
	}

	/**
	 * Aviso visible solo para quien puede editar; el visitante no ve errores internos.
	 *
	 * @param string $mensaje Mensaje.
	 * @return string
	 */
	public static function aviso( $mensaje ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<div class="lcrm-aviso" style="padding:12px;border:1px solid #f0c36d;background:#fff8e5;border-radius:6px">'
			. esc_html( 'Lucuma CRM: ' . $mensaje ) . '</div>';
	}

	/**
	 * Lista de formularios del CRM para los selectores del editor.
	 *
	 * Cacheada: el editor la pide en cada carga de pantalla y no tiene sentido golpear
	 * la API cada vez. Si la API no responde se devuelve la última copia buena, de modo
	 * que un mal minuto del CRM no vacíe el desplegable del diseñador.
	 *
	 * @param bool $refrescar Forzar consulta.
	 * @return array Lista de arrays con id, name y version.
	 */
	public static function lista_formularios( $refrescar = false ) {
		$clave  = 'lcrm_forms_index';
		$backup = 'lcrm_forms_index_backup';

		if ( ! $refrescar ) {
			$cache = get_transient( $clave );
			if ( is_array( $cache ) ) {
				return $cache;
			}
		}

		$res = LCRM_API_Client::get_private( '/forms' );

		if ( is_wp_error( $res ) || ! isset( $res['forms'] ) || ! is_array( $res['forms'] ) ) {
			$respaldo = get_option( $backup );
			return is_array( $respaldo ) ? $respaldo : array();
		}

		$lista = array();
		foreach ( $res['forms'] as $f ) {
			if ( empty( $f['id'] ) ) {
				continue;
			}
			$lista[] = array(
				'id'      => (string) $f['id'],
				'name'    => isset( $f['name'] ) ? (string) $f['name'] : (string) $f['id'],
				'version' => isset( $f['version'] ) ? (int) $f['version'] : 1,
			);
		}

		set_transient( $clave, $lista, 10 * MINUTE_IN_SECONDS );
		update_option( $backup, $lista, false );
		return $lista;
	}

	/**
	 * La lista en el formato { valor => etiqueta } que usan Bricks y Elementor.
	 *
	 * @return array
	 */
	public static function opciones_formularios() {
		$opciones = array();
		foreach ( self::lista_formularios() as $f ) {
			$opciones[ $f['id'] ] = $f['name'];
		}
		return $opciones;
	}
}
