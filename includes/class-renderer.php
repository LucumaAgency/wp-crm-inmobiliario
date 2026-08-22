<?php
/**
 * Render nativo del formulario.
 *
 * El HTML lo genera el sitio, no un iframe: hereda el diseño, es rápido, indexable y no
 * rompe el tracking de conversiones.
 *
 * No hay mapeo de campos. El CRM declara qué ES cada campo (`semantic`), el plugin solo
 * dibuja lo que le dicen.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Renderer {

	/**
	 * Devuelve el HTML del formulario.
	 *
	 * @param array $form Respuesta de LCRM_Form_Cache::get().
	 * @return string
	 */
	public static function render( $form ) {
		$schema  = $form['schema'];
		$opciones = isset( $form['dynamicOptions'] ) ? $form['dynamicOptions'] : array();
		$uid     = 'lcrm-' . wp_generate_password( 6, false, false );

		ob_start();
		?>
		<form class="lcrm-form" id="<?php echo esc_attr( $uid ); ?>"
			data-form-id="<?php echo esc_attr( $form['id'] ); ?>"
			data-version="<?php echo esc_attr( $form['version'] ); ?>"
			data-min-seconds="<?php echo esc_attr( isset( $schema['antispam']['minSeconds'] ) ? $schema['antispam']['minSeconds'] : 3 ); ?>"
			data-endpoint="<?php echo esc_url( rest_url( 'lucuma-crm/v1/submit' ) ); ?>"
			novalidate>

			<div class="lcrm-fields">
				<?php foreach ( $schema['fields'] as $field ) : ?>
					<?php echo self::field( $field, $opciones, $uid ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $schema['consent']['required'] ) || ! empty( $schema['consent']['text'] ) ) : ?>
				<?php
				// Ley 29733: checkbox SIN premarcar y separado del botón de envío.
				?>
				<div class="lcrm-field lcrm-field-consent">
					<label class="lcrm-consent">
						<input type="checkbox" name="lcrm_consent" value="1"
							<?php echo ! empty( $schema['consent']['required'] ) ? 'required' : ''; ?> />
						<span><?php echo esc_html( $schema['consent']['text'] ); ?>
						<?php if ( ! empty( $schema['consent']['policyUrl'] ) ) : ?>
							<a href="<?php echo esc_url( $schema['consent']['policyUrl'] ); ?>" target="_blank" rel="noopener">Ver política</a>
						<?php endif; ?>
						</span>
					</label>
				</div>
			<?php endif; ?>

			<?php // Honeypot: invisible para humanos, irresistible para bots. ?>
			<div class="lcrm-hp" aria-hidden="true">
				<label>No llenar este campo
					<input type="text" name="lcrm_website" tabindex="-1" autocomplete="off" />
				</label>
			</div>

			<input type="hidden" name="lcrm_consent_version"
				value="<?php echo esc_attr( isset( $schema['consent']['version'] ) ? $schema['consent']['version'] : '' ); ?>" />

			<div class="lcrm-actions">
				<button type="submit" class="lcrm-submit">
					<?php echo esc_html( isset( $schema['submitLabel'] ) ? $schema['submitLabel'] : 'Enviar' ); ?>
				</button>
			</div>

			<div class="lcrm-message" role="status" aria-live="polite"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Un campo.
	 *
	 * @param array  $field    Definición.
	 * @param array  $opciones Opciones dinámicas resueltas por el CRM.
	 * @param string $uid      Prefijo de ids.
	 * @return string
	 */
	private static function field( $field, $opciones, $uid ) {
		$key   = isset( $field['key'] ) ? $field['key'] : '';
		$tipo  = isset( $field['type'] ) ? $field['type'] : 'text';
		$id    = $uid . '-' . sanitize_html_class( $key );
		$req   = ! empty( $field['required'] );
		$name  = 'f[' . $key . ']';
		$lista = isset( $opciones[ $key ] ) ? $opciones[ $key ] : ( isset( $field['options'] ) ? $field['options'] : array() );

		if ( 'hidden' === $tipo ) {
			return sprintf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $name ),
				esc_attr( isset( $field['defaultValue'] ) ? $field['defaultValue'] : '' )
			);
		}

		ob_start();
		?>
		<div class="lcrm-field lcrm-field-<?php echo esc_attr( $tipo ); ?> lcrm-semantic-<?php echo esc_attr( isset( $field['semantic'] ) ? $field['semantic'] : 'custom' ); ?>">
			<label class="lcrm-label" for="<?php echo esc_attr( $id ); ?>">
				<?php echo esc_html( $field['label'] ); ?>
				<?php if ( $req ) : ?><span class="lcrm-req" aria-hidden="true">*</span><?php endif; ?>
			</label>

			<?php if ( 'textarea' === $tipo ) : ?>
				<textarea class="lcrm-input lcrm-textarea" id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>" rows="4"
					placeholder="<?php echo esc_attr( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ); ?>"
					<?php echo $req ? 'required' : ''; ?>></textarea>

			<?php elseif ( 'select' === $tipo ) : ?>
				<select class="lcrm-input lcrm-select" id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>" <?php echo $req ? 'required' : ''; ?>>
					<option value=""><?php echo esc_html( isset( $field['placeholder'] ) ? $field['placeholder'] : 'Selecciona una opción' ); ?></option>
					<?php foreach ( $lista as $op ) : ?>
						<option value="<?php echo esc_attr( $op['value'] ); ?>"><?php echo esc_html( $op['label'] ); ?></option>
					<?php endforeach; ?>
				</select>

			<?php elseif ( 'radio' === $tipo ) : ?>
				<div class="lcrm-radios">
					<?php foreach ( $lista as $i => $op ) : ?>
						<label class="lcrm-radio">
							<input type="radio" name="<?php echo esc_attr( $name ); ?>"
								value="<?php echo esc_attr( $op['value'] ); ?>" <?php echo ( $req && 0 === $i ) ? 'required' : ''; ?> />
							<span><?php echo esc_html( $op['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

			<?php elseif ( 'checkbox' === $tipo ) : ?>
				<label class="lcrm-checkbox">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $name ); ?>" value="1" <?php echo $req ? 'required' : ''; ?> />
					<span><?php echo esc_html( isset( $field['help'] ) ? $field['help'] : $field['label'] ); ?></span>
				</label>

			<?php else : ?>
				<input class="lcrm-input" type="<?php echo esc_attr( $tipo ); ?>" id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					placeholder="<?php echo esc_attr( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ); ?>"
					<?php echo isset( $field['maxLength'] ) ? 'maxlength="' . esc_attr( $field['maxLength'] ) . '"' : ''; ?>
					<?php echo self::autocomplete( $field ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php echo $req ? 'required' : ''; ?> />
			<?php endif; ?>

			<?php if ( ! empty( $field['help'] ) && 'checkbox' !== $tipo ) : ?>
				<small class="lcrm-help"><?php echo esc_html( $field['help'] ); ?></small>
			<?php endif; ?>
			<span class="lcrm-field-error"></span>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Autocompletado del navegador según el tipo semántico. Menos fricción, más envíos.
	 *
	 * @param array $field Campo.
	 * @return string
	 */
	private static function autocomplete( $field ) {
		$mapa = array(
			'fname' => 'given-name',
			'lname' => 'family-name',
			'email' => 'email',
			'phone' => 'tel',
		);
		$sem = isset( $field['semantic'] ) ? $field['semantic'] : '';
		return isset( $mapa[ $sem ] ) ? 'autocomplete="' . esc_attr( $mapa[ $sem ] ) . '"' : '';
	}
}
