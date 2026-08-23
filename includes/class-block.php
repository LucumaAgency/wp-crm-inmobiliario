<?php
/**
 * Bloque de Gutenberg.
 *
 * Es la vía "WordPress a secas": funciona en el editor de bloques de cualquier sitio, sin
 * depender de ningún constructor. Bricks y Elementor tienen sus propios envoltorios.
 *
 * El bloque es dinámico (se pinta en el servidor) a propósito: así el formulario que ve el
 * visitante sale siempre de la caché fresca del CRM y no de un HTML congelado en el
 * contenido del post el día que se guardó.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Block {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'registrar' ) );
		add_action( 'rest_api_init', array( $this, 'registrar_rest' ) );
	}

	/**
	 * Registra el tipo de bloque.
	 */
	public function registrar() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'lcrm-block',
			LCRM_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'wp-i18n' ),
			LCRM_VERSION,
			true
		);

		register_block_type(
			'lucuma-crm/form',
			array(
				'api_version'     => 2,
				'title'           => 'Formulario Lucuma CRM',
				'category'        => 'widgets',
				'icon'            => 'feedback',
				'description'     => 'Formulario definido en el CRM, renderizado con el diseño del sitio.',
				'editor_script'   => 'lcrm-block',
				'attributes'      => array(
					'formId' => array(
						'type'    => 'string',
						'default' => '',
					),
					'estilo' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Render en el front.
	 *
	 * @param array $attrs Atributos del bloque.
	 * @return string
	 */
	public function render( $attrs ) {
		$form_id = isset( $attrs['formId'] ) ? $attrs['formId'] : '';
		$estilo  = isset( $attrs['estilo'] ) ? (bool) $attrs['estilo'] : true;

		if ( '' === trim( (string) $form_id ) ) {
			return LCRM_Embed::aviso( 'Falta elegir el formulario en el bloque.' );
		}

		return LCRM_Embed::render( $form_id, $estilo );
	}

	/**
	 * Rutas REST que consume el editor.
	 */
	public function registrar_rest() {
		register_rest_route(
			'lucuma-crm/v1',
			'/forms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_forms' ),
				// La lista sale de la secret key del sitio: solo para quien edita.
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'lucuma-crm/v1',
			'/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_preview' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'formId' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	/**
	 * Lista de formularios para el desplegable del editor.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_forms() {
		return rest_ensure_response( array( 'forms' => LCRM_Embed::lista_formularios() ) );
	}

	/**
	 * Vista previa del formulario dentro del editor.
	 *
	 * @param WP_REST_Request $req Petición.
	 * @return WP_REST_Response
	 */
	public function rest_preview( $req ) {
		return rest_ensure_response(
			array( 'html' => LCRM_Embed::render_editor( $req->get_param( 'formId' ) ) )
		);
	}
}
