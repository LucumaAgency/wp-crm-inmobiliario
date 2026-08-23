<?php
/**
 * Shortcode [lucuma_crm_form id="..."] y carga de assets.
 *
 * Es la vía universal: funciona en cualquier WordPress y en cualquier constructor, aunque
 * no tenga integración propia. El bloque de Gutenberg, el elemento de Bricks y el widget de
 * Elementor comparten el mismo render a través de LCRM_Embed.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Shortcode {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'lucuma_crm_form', array( $this, 'render' ) );
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

		return LCRM_Embed::render( $atts['id'], 'no' !== $atts['estilo'] );
	}
}
