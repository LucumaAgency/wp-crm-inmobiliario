<?php
/**
 * Pantalla de ajustes.
 *
 * Deliberadamente corta: el plugin no es el CRM. Todo lo demás se configura en la
 * plataforma.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Settings {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'registrar' ) );
		add_action( 'wp_ajax_lcrm_test', array( $this, 'ajax_test' ) );
	}

	/**
	 * Menú.
	 */
	public function menu() {
		add_options_page( 'Lucuma CRM', 'Lucuma CRM', 'manage_options', 'lucuma-crm', array( $this, 'render' ) );
	}

	/**
	 * Registro de ajustes.
	 */
	public function registrar() {
		register_setting( 'lcrm_group', LCRM_OPTION, array( $this, 'sanitize' ) );
	}

	/**
	 * Saneo.
	 *
	 * @param array $input Entrada.
	 * @return array
	 */
	public function sanitize( $input ) {
		$anterior = get_option( LCRM_OPTION, array() );

		$out = array(
			'api_base'                 => esc_url_raw( trim( isset( $input['api_base'] ) ? $input['api_base'] : '' ) ),
			'public_key'               => sanitize_text_field( isset( $input['public_key'] ) ? $input['public_key'] : '' ),
			'notify_emails'            => sanitize_text_field( isset( $input['notify_emails'] ) ? $input['notify_emails'] : '' ),
			'cache_ttl'                => max( 60, (int) ( isset( $input['cache_ttl'] ) ? $input['cache_ttl'] : 900 ) ),
			'debug'                    => ! empty( $input['debug'] ) ? '1' : '0',
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ) ? '1' : '0',
		);

		// La secret key solo se reemplaza si se escribió una nueva: el campo se muestra
		// enmascarado, así que guardar sin tocarlo no debe borrarla.
		$nueva = isset( $input['secret_key'] ) ? trim( $input['secret_key'] ) : '';
		if ( '' !== $nueva && 0 !== strpos( $nueva, '•' ) ) {
			$out['secret_key'] = sanitize_text_field( $nueva );
		} else {
			$out['secret_key'] = isset( $anterior['secret_key'] ) ? $anterior['secret_key'] : '';
		}

		// Si cambió la conexión, la caché de esquemas ya no sirve.
		if ( ( isset( $anterior['api_base'] ) && $anterior['api_base'] !== $out['api_base'] )
			|| ( isset( $anterior['public_key'] ) && $anterior['public_key'] !== $out['public_key'] ) ) {
			LCRM_Form_Cache::flush();
		}

		return $out;
	}

	/**
	 * AJAX: prueba de conexión y lista de formularios disponibles.
	 */
	public function ajax_test() {
		check_ajax_referer( 'lcrm_test', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sin permisos.' ) );
		}
		$res = LCRM_API_Client::test();
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( $res );
		}
		wp_send_json_success( $res );
	}

	/**
	 * Campo de texto.
	 *
	 * @param string $key         Clave.
	 * @param string $placeholder Placeholder.
	 * @param string $tipo        Tipo.
	 */
	private function campo( $key, $placeholder = '', $tipo = 'text' ) {
		$valor = lcrm_setting( $key, '' );
		if ( 'secret_key' === $key && $valor ) {
			$valor = str_repeat( '•', 12 ) . substr( $valor, -4 );
		}
		printf(
			'<input type="%s" name="%s[%s]" value="%s" placeholder="%s" class="regular-text" />',
			esc_attr( $tipo ),
			esc_attr( LCRM_OPTION ),
			esc_attr( $key ),
			esc_attr( $valor ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Pantalla.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$conteos = LCRM_Queue::counts();
		?>
		<div class="wrap">
			<h1>Lucuma CRM — Conector</h1>
			<p>Los formularios se crean en el CRM y se insertan aquí con
				<code>[lucuma_crm_form id="..."]</code>. Este plugin los dibuja con el diseño del
				sitio y se encarga de que ningún lead se pierda.</p>

			<?php if ( $conteos['pending'] > 0 || $conteos['failed'] > 0 ) : ?>
				<div class="notice notice-warning">
					<p>
						Hay <strong><?php echo (int) $conteos['pending']; ?></strong> envío(s) pendientes de
						sincronizar y <strong><?php echo (int) $conteos['failed']; ?></strong> fallido(s).
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=lucuma-crm-queue' ) ); ?>">Ver la cola</a>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'lcrm_group' ); ?>

				<h2>Conexión</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">URL del CRM</th>
						<td><?php $this->campo( 'api_base', 'https://tu-cliente.crmlucuma.com' ); ?>
							<p class="description">Sin <code>/api</code> al final. El plugin arma la ruta.</p></td>
					</tr>
					<tr>
						<th scope="row">Public key</th>
						<td><?php $this->campo( 'public_key', 'pk_...' ); ?>
							<p class="description">Va en el HTML de la página a propósito. Lo que la protege es la
								lista blanca de dominios configurada en el CRM.</p></td>
					</tr>
					<tr>
						<th scope="row">Secret key</th>
						<td><?php $this->campo( 'secret_key', 'sk_...', 'password' ); ?>
							<p class="description">Solo servidor: nunca sale al navegador. Déjala como está para no
								cambiarla. Si la pierdes, se rota desde el CRM.</p></td>
					</tr>
				</table>

				<p>
					<button type="button" class="button button-secondary" id="lcrm-test">Probar conexión</button>
					<span id="lcrm-test-status" style="margin-left:8px;"></span>
				</p>
				<div id="lcrm-test-result"></div>

				<h2>Correo de respaldo</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Avisar a</th>
						<td><?php $this->campo( 'notify_emails', 'ventas@cliente.com, gerencia@cliente.com' ); ?>
							<p class="description">Separados por coma. <strong>Se envía siempre</strong>, haya llegado
								el lead al CRM o no. Es la prueba independiente de que el lead existió.</p></td>
					</tr>
				</table>

				<h2>Avanzado</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Caché del formulario</th>
						<td><?php $this->campo( 'cache_ttl', '900' ); ?>
							<p class="description">Segundos. Si el CRM no responde al vencer, se usa la última
								versión buena guardada: el formulario nunca desaparece de la página.</p></td>
					</tr>
					<tr>
						<th scope="row">Reintentos</th>
						<td>
							<?php if ( LCRM_Scheduler::usando_action_scheduler() ) : ?>
								<span style="color:#008a20;">Action Scheduler activo.</span>
							<?php else : ?>
								<span style="color:#b32d2e;">Action Scheduler no disponible: se usa wp_cron,
									que depende de que el sitio reciba visitas.</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Modo debug</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( LCRM_OPTION ); ?>[debug]" value="1"
									<?php checked( lcrm_setting( 'debug', '0' ), '1' ); ?> />
								Registrar errores en el log. <strong>Nunca</strong> se escriben datos personales.
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Al desinstalar</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( LCRM_OPTION ); ?>[delete_data_on_uninstall]" value="1"
									<?php checked( lcrm_setting( 'delete_data_on_uninstall', '0' ), '1' ); ?> />
								Borrar también la cola de envíos.
							</label>
							<p class="description">Desactivado por defecto: la cola puede contener leads que
								todavía no llegaron al CRM.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Guardar ajustes' ); ?>
			</form>
		</div>

		<script>
		( function () {
			var btn = document.getElementById( 'lcrm-test' );
			if ( ! btn ) { return; }
			var status = document.getElementById( 'lcrm-test-status' );
			var result = document.getElementById( 'lcrm-test-result' );
			var nonce  = <?php echo wp_json_encode( wp_create_nonce( 'lcrm_test' ) ); ?>;

			function esc( v ) {
				return String( v == null ? '' : v )
					.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
			}

			btn.addEventListener( 'click', function () {
				status.textContent = 'Probando…';
				result.innerHTML = '';
				var data = new URLSearchParams();
				data.append( 'action', 'lcrm_test' );
				data.append( 'nonce', nonce );

				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) {
						var d = res.data || {};
						status.textContent = '';
						if ( ! res.success ) {
							result.innerHTML = '<p style="color:#b32d2e;">' + esc( d.message || 'Error.' ) + '</p>';
							return;
						}
						var html = '<p style="color:#008a20;">' + esc( d.message ) + '</p>';
						if ( d.forms && d.forms.length ) {
							html += '<table class="widefat striped" style="max-width:640px;margin-top:8px;">' +
								'<thead><tr><th>Formulario</th><th style="width:120px;">Versión</th><th>Shortcode</th></tr></thead><tbody>';
							d.forms.forEach( function ( f ) {
								html += '<tr><td>' + esc( f.name ) + '</td><td>v' + esc( f.version ) + '</td>' +
									'<td><code>[lucuma_crm_form id="' + esc( f.id ) + '"]</code></td></tr>';
							} );
							html += '</tbody></table>';
						}
						result.innerHTML = html;
					} )
					.catch( function () {
						status.textContent = '';
						result.innerHTML = '<p style="color:#b32d2e;">No se pudo conectar.</p>';
					} );
			} );
		} )();
		</script>
		<?php
	}
}
