<?php
/**
 * Cola local de envíos.
 *
 * La razón de existir del plugin: si el CRM no responde, el lead NO se pierde.
 * Se guarda aquí, se reintenta con backoff y el visitante ve igualmente el mensaje
 * de éxito, porque su envío está guardado y garantizado.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Queue {

	const ESTADO_PENDIENTE = 'pending';
	const ESTADO_ENVIADO   = 'sent';
	const ESTADO_FALLIDO   = 'failed';

	const MAX_INTENTOS = 6;

	/**
	 * Nombre de la tabla.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lcrm_queue';
	}

	/**
	 * Crea la tabla. Se llama en la activación.
	 */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tabla   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$tabla} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			idempotency_key CHAR(36) NOT NULL,
			form_id VARCHAR(64) NOT NULL,
			payload LONGTEXT NOT NULL,
			status VARCHAR(12) NOT NULL DEFAULT 'pending',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			next_try_at DATETIME NULL,
			sent_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status_next (status, next_try_at)
		) {$collate};";

		dbDelta( $sql );
		update_option( 'lcrm_db_version', LCRM_DB_VERSION );
	}

	/**
	 * Encola un envío.
	 *
	 * @param string $form_id Formulario.
	 * @param array  $payload Cuerpo completo listo para la API.
	 * @param string $error   Último error.
	 * @return int|false ID de la fila.
	 */
	public static function push( $form_id, $payload, $error = '' ) {
		global $wpdb;
		$ahora = current_time( 'mysql', true );

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'idempotency_key' => $payload['idempotencyKey'],
				'form_id'         => $form_id,
				'payload'         => wp_json_encode( $payload ),
				'status'          => self::ESTADO_PENDIENTE,
				'attempts'        => 1,
				'last_error'      => $error,
				'created_at'      => $ahora,
				'next_try_at'     => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			// Choque de clave única: ya estaba encolado, que es justo lo que queremos.
			lcrm_log( 'El envío ya estaba en la cola', array( 'form' => $form_id ) );
			return false;
		}
		LCRM_Scheduler::schedule_soon();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Envíos listos para reintentar.
	 *
	 * @param int $limite Máximo.
	 * @return array
	 */
	public static function due( $limite = 20 ) {
		global $wpdb;
		$tabla = self::table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$tabla} WHERE status = %s AND (next_try_at IS NULL OR next_try_at <= %s) ORDER BY created_at ASC LIMIT %d",
				self::ESTADO_PENDIENTE,
				current_time( 'mysql', true ),
				$limite
			)
		);
	}

	/**
	 * Marca un envío como entregado.
	 *
	 * @param int $id Fila.
	 */
	public static function mark_sent( $id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array( 'status' => self::ESTADO_ENVIADO, 'sent_at' => current_time( 'mysql', true ), 'last_error' => '' ),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Registra un intento fallido y reprograma con backoff exponencial.
	 *
	 * @param object $fila  Fila.
	 * @param string $error Error.
	 */
	public static function mark_failed_attempt( $fila, $error ) {
		global $wpdb;
		$intentos = (int) $fila->attempts + 1;
		$agotado  = $intentos >= self::MAX_INTENTOS;

		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'      => $agotado ? self::ESTADO_FALLIDO : self::ESTADO_PENDIENTE,
				'attempts'    => $intentos,
				'last_error'  => substr( $error, 0, 1000 ),
				'next_try_at' => gmdate( 'Y-m-d H:i:s', time() + self::backoff( $intentos ) ),
			),
			array( 'id' => $fila->id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( $agotado ) {
			self::avisar_al_admin( $fila, $error );
		}
	}

	/**
	 * Backoff en segundos: 1 min, 5, 15, 1 h, 6 h, 24 h.
	 *
	 * @param int $intento Número de intento.
	 * @return int
	 */
	public static function backoff( $intento ) {
		$tabla = array( 60, 300, 900, 3600, 21600, 86400 );
		$i     = max( 0, min( $intento - 1, count( $tabla ) - 1 ) );
		return $tabla[ $i ];
	}

	/**
	 * Conteos por estado, para el aviso del admin.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$tabla = self::table();
		$filas = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$tabla} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array( self::ESTADO_PENDIENTE => 0, self::ESTADO_ENVIADO => 0, self::ESTADO_FALLIDO => 0 );
		foreach ( (array) $filas as $f ) {
			$out[ $f['status'] ] = (int) $f['n'];
		}
		return $out;
	}

	/**
	 * Listado para la pantalla de administración.
	 *
	 * @param string $estado Filtro.
	 * @param int    $limite Máximo.
	 * @return array
	 */
	public static function listar( $estado = '', $limite = 100 ) {
		global $wpdb;
		$tabla = self::table();
		if ( $estado ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE status = %s ORDER BY created_at DESC LIMIT %d", $estado, $limite ) ); // phpcs:ignore WordPress.DB
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabla} ORDER BY created_at DESC LIMIT %d", $limite ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Reintenta ahora una fila concreta (botón del admin).
	 *
	 * @param int $id Fila.
	 */
	public static function retry_now( $id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array( 'status' => self::ESTADO_PENDIENTE, 'next_try_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		LCRM_Scheduler::schedule_soon();
	}

	/**
	 * Purga los entregados con más de 30 días. Lo pendiente y lo fallido no se toca.
	 */
	public static function purge_old() {
		global $wpdb;
		$tabla = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"DELETE FROM {$tabla} WHERE status = %s AND sent_at < %s",
				self::ESTADO_ENVIADO,
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Avisa al administrador cuando un envío agota los reintentos.
	 *
	 * @param object $fila  Fila.
	 * @param string $error Error.
	 */
	private static function avisar_al_admin( $fila, $error ) {
		$url = admin_url( 'options-general.php?page=lucuma-crm-queue' );
		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '[%s] Un lead no pudo sincronizarse con el CRM', get_bloginfo( 'name' ) ),
			"Un envío de formulario agotó sus reintentos contra el CRM.\n\n" .
			"Formulario: {$fila->form_id}\nÚltimo error: {$error}\n\n" .
			"El lead NO se perdió: sigue guardado en la cola del sitio y puedes reintentarlo o descargarlo aquí:\n{$url}\n"
		);
	}
}
