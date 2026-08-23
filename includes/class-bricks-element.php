<?php
/**
 * Elemento nativo de Bricks.
 *
 * Es la forma principal de insertar el formulario en los sitios de Lucuma (decisión #4):
 * el diseñador lo arrastra como cualquier otro elemento y elige el formulario de una
 * lista traída del CRM, sin escribir el ID a mano.
 *
 * Se registra solo si Bricks está activo, de modo que el plugin sigue funcionando en un
 * WordPress cualquiera.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Bricks\Element' ) ) {
	return;
}

class LCRM_Bricks_Element extends \Bricks\Element {

	public $category = 'general';
	public $name     = 'lucuma-crm-form';
	public $icon     = 'ti-write';
	public $scripts  = array();

	/**
	 * Etiqueta del elemento.
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Formulario CRM', 'lucuma-crm' );
	}

	/**
	 * Controles del panel.
	 */
	public function set_controls() {
		$opciones = LCRM_Embed::opciones_formularios();

		$this->controls['formId'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Formulario', 'lucuma-crm' ),
			'type'        => 'select',
			'options'     => $opciones,
			'placeholder' => esc_html__( 'Elige un formulario', 'lucuma-crm' ),
			'description' => empty( $opciones )
				? esc_html__( 'No se pudo consultar el CRM. Revisa la secret key en los ajustes del plugin.', 'lucuma-crm' )
				: '',
		);

		$this->controls['estilo'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Cargar los estilos del plugin', 'lucuma-crm' ),
			'type'        => 'checkbox',
			'default'     => true,
			'description' => esc_html__( 'Desactívalo para que el formulario herede solo el diseño del sitio.', 'lucuma-crm' ),
		);
	}

	/**
	 * Render.
	 */
	public function render() {
		$form_id = isset( $this->settings['formId'] ) ? $this->settings['formId'] : '';
		$estilo  = ! empty( $this->settings['estilo'] );

		if ( '' === trim( (string) $form_id ) ) {
			// En el constructor se muestra el marcador; en el front, nada.
			echo '<div ' . $this->render_attributes( '_root' ) . '>';
			echo bricks_is_builder() || bricks_is_builder_call()
				? wp_kses_post( LCRM_Embed::marcador( 'Elige un formulario en el panel.' ) )
				: '';
			echo '</div>';
			return;
		}

		$html = ( bricks_is_builder() || bricks_is_builder_call() )
			? LCRM_Embed::render_editor( $form_id, $estilo )
			: LCRM_Embed::render( $form_id, $estilo );

		echo '<div ' . $this->render_attributes( '_root' ) . '>' . $html . '</div>';
	}
}
