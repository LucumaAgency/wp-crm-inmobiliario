<?php
/**
 * Pantalla de la cola de envíos y aviso en el admin.
 *
 * Sin esto, una cola que falla es invisible. El objetivo es que nadie descubra tarde que
 * hubo leads sin sincronizar.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Admin_Queue {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_notices', array( $this, 'aviso' ) );
		add_action( 'admin_post_lcrm_retry', array( $this, 'retry' ) );
		add_action( 'admin_post_lcrm_retry_all', array( $this, 'retry_all' ) );
		add_action( 'admin_post_lcrm_export_queue', array( $this, 'export' ) );
	}

	/**
	 * Menú (submenú oculto colgado de Ajustes).
	 */
	public function menu() {
		add_submenu_page(
			'options-general.php',
			'Cola de envíos — Lucuma CRM',
			'Cola de envíos',
			'manage_options',
			'lucuma-crm-queue',
			array( $this, 'render' )
		);
	}

	/**
	 * Aviso persistente mientras haya algo sin sincronizar.
	 */
	public function aviso() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pantalla = get_current_screen();
		if ( $pantalla && 'settings_page_lucuma-crm-queue' === $pantalla->id ) {
			return;
		}

		$c = LCRM_Queue::counts();
		if ( 0 === $c['pending'] && 0 === $c['failed'] ) {
			return;
		}

		$url   = admin_url( 'options-general.php?page=lucuma-crm-queue' );
		$clase = $c['failed'] > 0 ? 'notice-error' : 'notice-warning';
		?>
		<div class="notice <?php echo esc_attr( $clase ); ?>">
			<p>
				<strong>Lucuma CRM:</strong>
				<?php if ( $c['failed'] > 0 ) : ?>
					<?php echo (int) $c['failed']; ?> envío(s) no pudieron sincronizarse con el CRM.
				<?php else : ?>
					<?php echo (int) $c['pending']; ?> envío(s) pendientes de sincronizar; se reintentan solos.
				<?php endif; ?>
				Los leads <strong>no se perdieron</strong>: están guardados en este sitio.
				<a href="<?php echo esc_url( $url ); ?>">Ver la cola</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Reintentar uno.
	 */
	public function retry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'lcrm_retry' );
		$id = isset( $_GET['fila'] ) ? (int) $_GET['fila'] : 0;
		if ( $id ) {
			LCRM_Queue::retry_now( $id );
			LCRM_Scheduler::process();
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=lucuma-crm-queue&hecho=1' ) );
		exit;
	}

	/**
	 * Reintentar todo lo pendiente y fallido.
	 */
	public function retry_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'lcrm_retry_all' );

		global $wpdb;
		$tabla = LCRM_Queue::table();
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$tabla} SET status = %s, next_try_at = %s WHERE status IN (%s, %s)",
				LCRM_Queue::ESTADO_PENDIENTE,
				current_time( 'mysql', true ),
				LCRM_Queue::ESTADO_PENDIENTE,
				LCRM_Queue::ESTADO_FALLIDO
			)
		);
		LCRM_Scheduler::process();

		wp_safe_redirect( admin_url( 'options-general.php?page=lucuma-crm-queue&hecho=1' ) );
		exit;
	}

	/**
	 * Descarga la cola en CSV. Es la salida de emergencia si el CRM no vuelve.
	 */
	public function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'lcrm_export_queue' );

		$filas = LCRM_Queue::listar( '', 5000 );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cola-leads-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM para que Excel respete los acentos
		fputcsv( $out, array( 'fecha', 'estado', 'intentos', 'formulario', 'campos', 'ultimo_error' ) );

		foreach ( $filas as $f ) {
			$payload = json_decode( $f->payload, true );
			$campos  = array();
			if ( isset( $payload['values'] ) && is_array( $payload['values'] ) ) {
				foreach ( $payload['values'] as $k => $v ) {
					$campos[] = $k . ': ' . $v;
				}
			}
			fputcsv(
				$out,
				array( $f->created_at, $f->status, $f->attempts, $f->form_id, implode( ' | ', $campos ), $f->last_error )
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Pantalla.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$estado = isset( $_GET['estado'] ) ? sanitize_text_field( wp_unslash( $_GET['estado'] ) ) : '';
		$filas  = LCRM_Queue::listar( $estado, 200 );
		$c      = LCRM_Queue::counts();
		?>
		<div class="wrap">
			<h1>Cola de envíos</h1>
			<p>Cada fila es un lead que el sitio recibió pero todavía no confirmó en el CRM.
				El visitante ya vio el mensaje de éxito: su envío está guardado aquí y se reintenta solo.</p>

			<?php if ( isset( $_GET['hecho'] ) ) : ?>
				<div class="notice notice-success"><p>Reintento ejecutado.</p></div>
			<?php endif; ?>

			<p>
				<strong><?php echo (int) $c['pending']; ?></strong> pendientes ·
				<strong><?php echo (int) $c['failed']; ?></strong> fallidos ·
				<strong><?php echo (int) $c['sent']; ?></strong> entregados
			</p>

			<p>
				<a class="button button-primary"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lcrm_retry_all' ), 'lcrm_retry_all' ) ); ?>">
					Reintentar todo ahora
				</a>
				<a class="button"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lcrm_export_queue' ), 'lcrm_export_queue' ) ); ?>">
					Descargar CSV
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=lucuma-crm-queue' ) ); ?>">Todos</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=lucuma-crm-queue&estado=failed' ) ); ?>">Solo fallidos</a>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:150px;">Fecha</th>
						<th style="width:100px;">Estado</th>
						<th style="width:70px;">Intentos</th>
						<th>Datos del lead</th>
						<th>Último error</th>
						<th style="width:110px;"></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $filas ) : ?>
						<tr><td colspan="6">No hay envíos en la cola. Todo lo recibido llegó al CRM.</td></tr>
					<?php endif; ?>

					<?php foreach ( $filas as $f ) : ?>
						<?php
						$payload = json_decode( $f->payload, true );
						$valores = isset( $payload['values'] ) ? $payload['values'] : array();
						$resumen = array();
						foreach ( array_slice( (array) $valores, 0, 4 ) as $k => $v ) {
							$resumen[] = $k . ': ' . $v;
						}
						?>
						<tr>
							<td><?php echo esc_html( $f->created_at ); ?></td>
							<td>
								<?php if ( 'failed' === $f->status ) : ?>
									<span style="color:#b32d2e;font-weight:600;">fallido</span>
								<?php elseif ( 'sent' === $f->status ) : ?>
									<span style="color:#008a20;">entregado</span>
								<?php else : ?>
									pendiente
								<?php endif; ?>
							</td>
							<td><?php echo (int) $f->attempts; ?></td>
							<td><?php echo esc_html( implode( ' · ', $resumen ) ); ?></td>
							<td><small><?php echo esc_html( (string) $f->last_error ); ?></small></td>
							<td>
								<?php if ( 'sent' !== $f->status ) : ?>
									<a class="button button-small"
										href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lcrm_retry&fila=' . (int) $f->id ), 'lcrm_retry' ) ); ?>">
										Reintentar
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description" style="margin-top:14px;">
				Los envíos entregados se borran solos a los 30 días. Lo pendiente y lo fallido no se
				borra nunca de forma automática.
			</p>
		</div>
		<?php
	}
}
