<?php
/**
 * Programación de los reintentos.
 *
 * Usa Action Scheduler si está disponible (empaquetado por Composer o traído por
 * WooCommerce). Si no, cae a wp_cron, que depende del tráfico del sitio y es menos
 * confiable: por eso Action Scheduler es la vía recomendada.
 *
 * @package Lucuma_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCRM_Scheduler {

	const HOOK       = 'lcrm_process_queue';
	const HOOK_PURGE = 'lcrm_purge_queue';
	const GRUPO      = 'lucuma-crm';

	/**
	 * Registra los handlers.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'process' ) );
		add_action( self::HOOK_PURGE, array( 'LCRM_Queue', 'purge_old' ) );

		if ( ! self::usando_action_scheduler() ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		}
	}

	/**
	 * ¿Hay Action Scheduler?
	 *
	 * @return bool
	 */
	public static function usando_action_scheduler() {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Intervalo propio para el fallback de wp_cron.
	 *
	 * @param array $schedules Intervalos.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['lcrm_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'Cada 5 minutos (Lucuma CRM)',
		);
		return $schedules;
	}

	/**
	 * Programa el barrido recurrente y la purga. Se llama en la activación.
	 */
	public static function schedule_recurring() {
		if ( self::usando_action_scheduler() ) {
			if ( ! as_has_scheduled_action( self::HOOK, array(), self::GRUPO ) ) {
				as_schedule_recurring_action( time() + 60, 300, self::HOOK, array(), self::GRUPO );
			}
			if ( ! as_has_scheduled_action( self::HOOK_PURGE, array(), self::GRUPO ) ) {
				as_schedule_recurring_action( time() + 3600, DAY_IN_SECONDS, self::HOOK_PURGE, array(), self::GRUPO );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'lcrm_five_minutes', self::HOOK );
		}
		if ( ! wp_next_scheduled( self::HOOK_PURGE ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::HOOK_PURGE );
		}
	}

	/**
	 * Programa un barrido inmediato tras encolar algo.
	 */
	public static function schedule_soon() {
		if ( self::usando_action_scheduler() ) {
			as_schedule_single_action( time() + 60, self::HOOK, array(), self::GRUPO );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::HOOK );
		}
	}

	/**
	 * Quita lo programado (desactivación).
	 */
	public static function unschedule() {
		if ( self::usando_action_scheduler() && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GRUPO );
			as_unschedule_all_actions( self::HOOK_PURGE, array(), self::GRUPO );
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK_PURGE );
	}

	/**
	 * Procesa la cola: reintenta cada envío pendiente que ya venció.
	 */
	public static function process() {
		$filas = LCRM_Queue::due( 20 );
		if ( ! $filas ) {
			return;
		}
		lcrm_log( 'Procesando cola', array( 'pendientes' => count( $filas ) ) );

		foreach ( $filas as $fila ) {
			$payload = json_decode( $fila->payload, true );
			if ( ! is_array( $payload ) ) {
				LCRM_Queue::mark_failed_attempt( $fila, 'Payload corrupto en la cola' );
				continue;
			}

			// La misma idempotencyKey viaja en cada reintento: el CRM no duplica el lead.
			$res = LCRM_API_Client::submit( $fila->form_id, $payload );

			if ( $res['ok'] ) {
				LCRM_Queue::mark_sent( $fila->id );
				continue;
			}

			// Un 400 no se arregla reintentando: son datos inválidos.
			if ( 400 === $res['code'] ) {
				LCRM_Queue::mark_failed_attempt( $fila, 'Rechazado por el CRM (400): ' . self::texto( $res['body'] ) );
				continue;
			}

			LCRM_Queue::mark_failed_attempt( $fila, 'HTTP ' . $res['code'] . ': ' . self::texto( $res['body'] ) );
		}
	}

	/**
	 * Convierte un cuerpo de respuesta a texto corto.
	 *
	 * @param mixed $body Cuerpo.
	 * @return string
	 */
	private static function texto( $body ) {
		if ( is_string( $body ) ) {
			return substr( $body, 0, 300 );
		}
		if ( is_array( $body ) && isset( $body['error'] ) ) {
			return (string) $body['error'];
		}
		return substr( (string) wp_json_encode( $body ), 0, 300 );
	}
}
