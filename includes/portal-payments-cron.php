<?php
if (!defined('ABSPATH')) exit;

add_action('casanova_job_reconcile_payment', 'casanova_job_reconcile_payment', 10, 1);

function casanova_job_reconcile_payment(int $intent_id): void {
  $intent_id = (int)$intent_id;
  if ($intent_id <= 0) return;

  if (!function_exists('casanova_payments_table')) return;
  if (!function_exists('casanova_payment_intent_update')) return;
  if (!function_exists('casanova_giav_reservas_por_expediente')) return;
  if (!function_exists('casanova_calc_pago_expediente')) return;

  global $wpdb;
  $table = casanova_payments_table();

  $intent = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $intent_id));
  if (!$intent) return;

  $status = (string)($intent->status ?? '');
  if ($status === 'reconciled' || $status === 'failed') return;

  $attempts = (int)($intent->attempts ?? 0);
  $max_attempts = 20;

  error_log('[CASANOVA][RECON] START intent_id=' . $intent_id . ' status=' . $status . ' attempts=' . $attempts);

  if ($attempts >= $max_attempts) {
    casanova_payment_intent_update($intent_id, [
      'status' => 'failed',
      'attempts' => $attempts + 1,
      'last_check_at' => current_time('mysql'),
      'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
        'reconcile' => [
          'stopped' => true,
          'reason' => 'max_attempts',
          'attempts' => $attempts + 1,
          'time' => current_time('mysql'),
        ],
      ]),
    ]);
    error_log('[CASANOVA][RECON] STOP max_attempts intent_id=' . $intent_id);
    return;
  }

  // marcar intento (si tu update lo permite)
  casanova_payment_intent_update($intent_id, [
    'attempts' => $attempts + 1,
    'last_check_at' => current_time('mysql'),
  ]);

  $idExpediente = (int)($intent->id_expediente ?? 0);
  $idCliente    = (int)($intent->id_cliente ?? 0);

  if ($idExpediente <= 0 || $idCliente <= 0) {
    error_log('[CASANOVA][RECON] STOP missing ids intent_id=' . $intent_id . ' exp=' . $idExpediente . ' cli=' . $idCliente);
    return;
  }

  // 1) Recalcular pago real (GIAV manda)
  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas) || empty($reservas)) {
    error_log('[CASANOVA][RECON] reservas empty/error intent_id=' . $intent_id . ' -> reschedule');
    casanova_job_reconcile_payment_reschedule($intent_id, $attempts + 1);
    return;
  }

  $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($calc)) {
    error_log('[CASANOVA][RECON] calc WP_Error intent_id=' . $intent_id . ' -> reschedule');
    casanova_job_reconcile_payment_reschedule($intent_id, $attempts + 1);
    return;
  }

  $pend = (float)($calc['pendiente_real'] ?? 0);
  $pagado = (float)($calc['pagado'] ?? 0);
  $total_obj = (float)($calc['total_objetivo'] ?? 0);

  error_log('[CASANOVA][RECON] calc ok intent_id=' . $intent_id . ' pendiente_real=' . $pend . ' pagado=' . $pagado . ' total_objetivo=' . $total_obj);

  // 2) Si ya está pagado, reconciliamos + hook
  if ($pend <= 0.01) {
    casanova_payment_intent_update($intent_id, [
      'status' => 'reconciled',
      'payload' => casanova_intent_payload_merge($intent->payload ?? null, [
        'reconciled_at' => current_time('mysql'),
        'pendiente_real' => $pend,
        'calc' => [
          'total_objetivo' => $total_obj,
          'pagado' => $pagado,
        ],
      ]),
    ]);

    do_action('casanova_payment_reconciled', $intent_id);
    error_log('[CASANOVA][RECON] END reconciled intent_id=' . $intent_id);
    return;
  }

  // 3) Si aún no, reintento con backoff
  casanova_job_reconcile_payment_reschedule($intent_id, $attempts + 1);
  error_log('[CASANOVA][RECON] END rescheduled intent_id=' . $intent_id);
}

function casanova_job_reconcile_payment_reschedule(int $intent_id, int $attempts): void {
  // Backoff por defecto (en segundos)
  $delay = min(120 + ($attempts * 60), 900); // max 15 min

  // Si ya hemos creado un cobro en GIAV, normalmente solo hace falta un pequeño lag
  // para que el cálculo de pendiente lo refleje. En ese caso reintentamos mucho más rápido.
  $has_giav_cobro = false;
  if (function_exists('casanova_payment_intent_get')) {
    $intent = casanova_payment_intent_get($intent_id);
    if ($intent && isset($intent->payload)) {
      $p = is_array($intent->payload) ? $intent->payload : (json_decode((string)$intent->payload, true) ?: []);
      if (is_array($p) && !empty($p['giav_cobro']) && ( !empty($p['giav_cobro']['cobro_id']) || !empty($p['giav_cobro']['inserted_at']) )) {
        $has_giav_cobro = true;
      }
    }
  }

  if ($has_giav_cobro) {
    // 3 intentos rápidos antes de volver al backoff largo
    $fast = [15, 30, 60];
    if ($attempts >= 1 && $attempts <= 3) {
      $delay = $fast[$attempts - 1];
    }
  }
  if (!wp_next_scheduled('casanova_job_reconcile_payment', [$intent_id])) {
    wp_schedule_single_event(time() + $delay, 'casanova_job_reconcile_payment', [$intent_id]);
    error_log('[CASANOVA][RECON] scheduled intent_id=' . $intent_id . ' in=' . $delay . 's');
  } else {
    error_log('[CASANOVA][RECON] already scheduled intent_id=' . $intent_id);
  }
}

function casanova_intent_payload_merge($old_payload, array $add): string {
  $oldArr = [];
  if (is_string($old_payload) && $old_payload !== '') {
    $tmp = json_decode($old_payload, true);
    if (is_array($tmp)) $oldArr = $tmp;
  } elseif (is_array($old_payload)) {
    $oldArr = $old_payload;
  }
  return wp_json_encode(array_replace_recursive($oldArr, $add));
}