<?php
/**
 * Correo de respaldo.
 *
 * Se envía SIEMPRE, haya respondido bien el CRM o no. No es un plan B: es una constante.
 * El cliente lleva años trabajando con ese correo, y es la prueba independiente de que el
 * lead existió aunque falle el CRM o la cola.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Backup_Mail {

	/**
	 * Envía el correo con todos los campos del formulario.
	 *
	 * @param array  $form      Esquema del formulario.
	 * @param array  $valores   Valores enviados.
	 * @param array  $contexto  Datos extra (página, atribución, estado del envío).
	 */
	public static function send( $form, $valores, $contexto = array() ) {
		$destinos = self::destinatarios();
		if ( ! $destinos ) {
			return;
		}

		$schema  = isset( $form['schema'] ) ? $form['schema'] : array();
		$nombre  = isset( $schema['name'] ) ? $schema['name'] : 'Formulario';
		$sincro  = ! empty( $contexto['sincronizado'] );

		$filas = '';
		if ( ! empty( $schema['fields'] ) ) {
			foreach ( $schema['fields'] as $campo ) {
				$k = $campo['key'];
				if ( ! isset( $valores[ $k ] ) || '' === $valores[ $k ] ) {
					continue;
				}
				$filas .= sprintf(
					'<tr><td style="padding:6px 14px 6px 0;color:#667085;vertical-align:top">%s</td><td style="padding:6px 0"><strong>%s</strong></td></tr>',
					esc_html( $campo['label'] ),
					nl2br( esc_html( self::etiqueta_valor( $campo, $valores[ $k ], $form ) ) )
				);
			}
		}

		$aviso = $sincro
			? '<p style="color:#10b981;font-size:13px">Registrado en el CRM.</p>'
			: '<p style="color:#f59e0b;font-size:13px">Pendiente de sincronizar con el CRM: quedó guardado en la cola del sitio y se reintenta solo. Este correo es el respaldo.</p>';

		$html = '<div style="font-family:system-ui,sans-serif;font-size:14px">'
			. '<h2 style="margin:0 0 4px">Nuevo lead: ' . esc_html( $nombre ) . '</h2>'
			. $aviso
			. '<table style="border-collapse:collapse">' . $filas . '</table>'
			. self::bloque_contexto( $contexto )
			. '</div>';

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type' ) );
		wp_mail(
			$destinos,
			sprintf( '[%s] Nuevo lead: %s', get_bloginfo( 'name' ), $nombre ),
			$html
		);
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type' ) );
	}

	/**
	 * Tipo de contenido HTML.
	 *
	 * @return string
	 */
	public static function content_type() {
		return 'text/html';
	}

	/**
	 * Destinatarios configurados.
	 *
	 * @return array
	 */
	private static function destinatarios() {
		$raw = lcrm_setting( 'notify_emails', get_option( 'admin_email' ) );
		$out = array();
		foreach ( explode( ',', (string) $raw ) as $mail ) {
			$mail = trim( $mail );
			if ( is_email( $mail ) ) {
				$out[] = $mail;
			}
		}
		return $out;
	}

	/**
	 * Traduce el value de un select a su etiqueta legible.
	 *
	 * @param array $campo Campo.
	 * @param mixed $valor Valor.
	 * @param array $form  Formulario completo.
	 * @return string
	 */
	private static function etiqueta_valor( $campo, $valor, $form ) {
		$opciones = array();
		if ( isset( $form['dynamicOptions'][ $campo['key'] ] ) ) {
			$opciones = $form['dynamicOptions'][ $campo['key'] ];
		} elseif ( isset( $campo['options'] ) ) {
			$opciones = $campo['options'];
		}
		foreach ( $opciones as $op ) {
			if ( (string) $op['value'] === (string) $valor ) {
				return $op['label'];
			}
		}
		return (string) $valor;
	}

	/**
	 * Página de origen y campaña, que es lo que permite saber qué anuncio trajo al lead.
	 *
	 * @param array $contexto Contexto.
	 * @return string
	 */
	private static function bloque_contexto( $contexto ) {
		$partes = array();
		if ( ! empty( $contexto['pageUrl'] ) ) {
			$partes[] = 'Página: ' . esc_html( $contexto['pageUrl'] );
		}
		if ( ! empty( $contexto['campaign'] ) ) {
			$partes[] = 'Campaña: ' . esc_html( $contexto['campaign'] );
		}
		if ( ! $partes ) {
			return '';
		}
		return '<p style="color:#667085;font-size:12px;margin-top:16px">' . implode( '<br>', $partes ) . '</p>';
	}
}
