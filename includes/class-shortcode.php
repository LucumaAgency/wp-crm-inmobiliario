<?php
/**
 * Shortcode [lucuma_crm_form id="..."] y carga de assets.
 *
 * Es la primera forma de insertar el formulario. El elemento nativo de Bricks y el bloque
 * de Gutenberg reutilizarán este mismo render.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Shortcode {

	/**
	 * Si ya se encolaron los assets en esta petición.
	 *
	 * @var bool
	 */
	private $assets_encolados = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'lucuma_crm_form', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'registrar_assets' ) );
	}

	/**
	 * Registra (no encola) los assets.
	 */
	public function registrar_assets() {
		wp_register_script( 'lcrm-form', LCRM_URL . 'assets/js/form.js', array(), LCRM_VERSION, true );
		wp_register_style( 'lcrm-form', LCRM_URL . 'assets/css/form.css', array(), LCRM_VERSION );
	}

	/**
	 * Render del shortcode.
	 *
	 * @param array $atts Atributos.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'     => '',
				'estilo' => 'si', // "no" para no cargar el CSS mínimo del plugin
			),
			$atts,
			'lucuma_crm_form'
		);

		if ( ! $atts['id'] ) {
			return self::aviso( 'Falta el ID del formulario.' );
		}

		$form = LCRM_Form_Cache::get( $atts['id'] );
		if ( ! $form ) {
			lcrm_log( 'Formulario no disponible', array( 'form' => $atts['id'] ) );
			return self::aviso( 'El formulario no está disponible en este momento.' );
		}

		if ( ! $this->assets_encolados ) {
			wp_enqueue_script( 'lcrm-form' );
			if ( 'no' !== $atts['estilo'] ) {
				wp_enqueue_style( 'lcrm-form' );
			}
			$this->assets_encolados = true;
		}

		return LCRM_Renderer::render( $form );
	}

	/**
	 * Aviso visible solo para quien puede editar; el visitante no ve errores internos.
	 *
	 * @param string $mensaje Mensaje.
	 * @return string
	 */
	private static function aviso( $mensaje ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<div class="lcrm-aviso" style="padding:12px;border:1px solid #f0c36d;background:#fff8e5;border-radius:6px">'
			. esc_html( 'Lucuma CRM: ' . $mensaje ) . '</div>';
	}
}
