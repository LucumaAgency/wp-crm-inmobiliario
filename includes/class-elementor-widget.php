<?php
/**
 * Widget de Elementor.
 *
 * Mismo render que el resto: el widget solo aporta el panel de selección. Se registra
 * únicamente si Elementor está activo.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

class LCRM_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'lucuma_crm_form';
	}

	public function get_title() {
		return esc_html__( 'Formulario CRM', 'lucuma-crm' );
	}

	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'formulario', 'crm', 'lead', 'lucuma' );
	}

	/**
	 * Controles del panel.
	 */
	protected function register_controls() {
		$opciones = LCRM_Embed::opciones_formularios();

		$this->start_controls_section(
			'seccion_contenido',
			array( 'label' => esc_html__( 'Formulario', 'lucuma-crm' ) )
		);

		$this->add_control(
			'form_id',
			array(
				'label'       => esc_html__( 'Formulario del CRM', 'lucuma-crm' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array( '' => esc_html__( '— Elige uno —', 'lucuma-crm' ) ) + $opciones,
				'default'     => '',
				'description' => empty( $opciones )
					? esc_html__( 'No se pudo consultar el CRM. Revisa la secret key en los ajustes del plugin.', 'lucuma-crm' )
					: '',
			)
		);

		$this->add_control(
			'estilo',
			array(
				'label'        => esc_html__( 'Cargar los estilos del plugin', 'lucuma-crm' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'description'  => esc_html__( 'Desactívalo para que el formulario herede solo el diseño del sitio.', 'lucuma-crm' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render en el front y en el editor.
	 */
	protected function render() {
		$s       = $this->get_settings_for_display();
		$form_id = isset( $s['form_id'] ) ? $s['form_id'] : '';
		$estilo  = ! isset( $s['estilo'] ) || 'yes' === $s['estilo'];

		$en_editor = \Elementor\Plugin::$instance->editor->is_edit_mode();

		if ( '' === trim( (string) $form_id ) ) {
			if ( $en_editor ) {
				echo wp_kses_post( LCRM_Embed::marcador( 'Elige un formulario en el panel.' ) );
			}
			return;
		}

		echo $en_editor
			? LCRM_Embed::render_editor( $form_id, $estilo )
			: LCRM_Embed::render( $form_id, $estilo );
	}
}
