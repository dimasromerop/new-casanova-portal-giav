<?php
// includes/portal-mail-events.php
if (!defined('ABSPATH')) exit;

/**
 * Hash estable de un cobro GIAV para dedupe.
 * Importante: usamos abs(Importe) porque GIAV puede mandar reembolsos en negativo.
 */
function casanova_cobro_hash($c): string {
  if (!is_object($c)) return '';
  $fecha = (string)($c->FechaCobro ?? '');
  $importe = (float)($c->Importe ?? 0);
  $tipo = strtoupper(trim((string)($c->TipoOperacion ?? '')));
  $doc = trim((string)($c->Documento ?? ''));
  $concepto = trim((string)($c->Concepto ?? ''));
  $id = (int)($c->Id ?? 0);

  return sha1(implode('|', [
    $id ?: '0',
    $fecha,
    number_format(abs($importe), 2, '.', ''),
    $tipo,
    $doc,
    $concepto,
  ]));
}

function casanova_is_reembolso_cobro($c): bool {
  $tipo = strtoupper(trim((string)($c->TipoOperacion ?? '')));
  return ($tipo === 'REEMBOLSO' || strpos($tipo, 'REEM') !== false || strpos($tipo, 'DEV') !== false);
}

/**
 * Detecta cambios y encola emails.
 * - Llamar a esto después de calcular $pago en la vista del expediente.
 */
function casanova_portal_payments_tick(int $idExpediente, int $idCliente, array $reservas, array $pago): void {

  if ($idExpediente <= 0 || $idCliente <= 0) return;

  // (1) COBROS: detectar nuevos cobros en GIAV
  $all = casanova_giav_cobros_por_expediente_all($idExpediente, $idCliente);
  if (!is_wp_error($all)) {

    $meta_key_hashes = 'casanova_cobros_hashes_v1_' . $idExpediente;
    $sent_hashes = get_user_meta(get_current_user_id(), $meta_key_hashes, true);
    if (!is_array($sent_hashes)) $sent_hashes = [];

    $new = [];
    foreach ($all as $c) {
      $h = casanova_cobro_hash($c);
      if (!$h) continue;
      if (!isset($sent_hashes[$h])) {
        // Solo notificamos COBROS (no reembolsos) como “confirmación de pago recibido”
        if (!casanova_is_reembolso_cobro($c)) {
          $new[] = $c;
        }
        // marcamos hash como “visto” siempre, para no repetir en el futuro
        $sent_hashes[$h] = time();
      }
    }

    if (!empty($new)) {
      // Encolamos un job por seguridad (no bloquea la página)
      if (!wp_next_scheduled('casanova_job_send_cobro_emails', [$idExpediente, $idCliente])) {
        wp_schedule_single_event(time() + 15, 'casanova_job_send_cobro_emails', [$idExpediente, $idCliente]);
      }
    }

    update_user_meta(get_current_user_id(), $meta_key_hashes, $sent_hashes);
  }

  // (2) EXPEDIENTE PAGADO: transición a pagado completo
  $is_paid_now = !empty($pago['expediente_pagado']);
  $meta_key_paid_sent = 'casanova_paid_email_sent_v1_' . $idExpediente;

  if ($is_paid_now && !get_user_meta(get_current_user_id(), $meta_key_paid_sent, true)) {
    if (!wp_next_scheduled('casanova_job_send_expediente_paid_email', [$idExpediente, $idCliente])) {
      wp_schedule_single_event(time() + 20, 'casanova_job_send_expediente_paid_email', [$idExpediente, $idCliente]);
    }
  }
}

add_action('casanova_payment_reconciled', 'casanova_on_payment_reconciled_send_emails', 10, 1);
add_action('casanova_payment_cobro_recorded', 'casanova_on_payment_cobro_recorded_send_email', 10, 1);

/**
 * Email por COBRO registrado (parcial o total).
 * Importante: nunca debe romper NOTIFY.
 */
function casanova_on_payment_cobro_recorded_send_email(int $intent_id): void {
  $intent_id = (int)$intent_id;
  error_log('[CASANOVA][MAIL] hook COBRO_RECORDED intent_id=' . $intent_id);

  if ($intent_id <= 0) return;
  if (!function_exists('casanova_payment_intent_get') || !function_exists('casanova_payment_intent_update')) return;

  $intent = casanova_payment_intent_get($intent_id);
  if (!$intent || !is_object($intent)) return;

  // No duplicar: email de cobro ya enviado
  if (!empty($intent->mail_cobro_sent_at)) {
    error_log('[CASANOVA][MAIL] SKIP: mail_cobro ya enviado (cobro_recorded)');
    return;
  }

  if (!function_exists('get_user_by') || !function_exists('casanova_mail_send_payment_confirmed')) return;

  $user = get_user_by('id', (int)($intent->user_id ?? 0));
  if (!$user || empty($user->user_email)) return;

  $exp_id = (int)($intent->id_expediente ?? 0);
  $idCliente = (int)($intent->id_cliente ?? 0);

  // Unificar: siempre usar el CÓDIGO HUMANO desde Expediente_GET
  $codExp = '';
  if ($exp_id > 0 && function_exists('casanova_giav_expediente_get')) {
    try {
      $exp = casanova_giav_expediente_get($exp_id);
      if (is_object($exp)) {
        $codExp = (string)($exp->CodigoExpediente ?? ($exp->Codigo ?? ''));
      }
    } catch (Throwable $e) {
      error_log('[CASANOVA][MAIL] WARN: expediente_get failed: ' . $e->getMessage());
    }
  }

  // Para evitar “dos cuerpos” en payment_confirmed, NO pasamos totales aquí.
  $ctx = [
    'to' => $user->user_email,
    'cliente_nombre' => ($user->first_name ?: $user->display_name),
    'idExpediente' => $exp_id,
    'codigoExpediente' => $codExp,
    'importe' => number_format((float)($intent->amount ?? 0), 2, ',', '.') . ' €',
    'fecha' => date_i18n('d/m/Y H:i', current_time('timestamp')),
    'pagado' => '',
    'pendiente' => '',
  ];

  error_log('[CASANOVA][MAIL] SENDING: payment_confirmed (cobro_recorded)…');
  $ok = (bool) casanova_mail_send_payment_confirmed($ctx);
  error_log('[CASANOVA][MAIL] SENT payment_confirmed (cobro_recorded) ok=' . ($ok ? 'YES' : 'NO'));

  if ($ok) {
    casanova_payment_intent_update($intent_id, [
      'mail_cobro_sent_at' => current_time('mysql'),
    ]);
    error_log('[CASANOVA][MAIL] UPDATED: mail_cobro_sent_at (cobro_recorded)');
  }
}

/**
 * Emails al reconciliar (expediente pagado).
 * - Puede mandar payment_confirmed si aún no se mandó.
 * - Debe mandar expediente_paid una vez por intent (mail_expediente_sent_at).
 */
function casanova_on_payment_reconciled_send_emails(int $intent_id): void {

  $intent_id = (int)$intent_id;
  error_log('[CASANOVA][MAIL] hook CALLED intent_id=' . $intent_id);

  if ($intent_id <= 0) {
    error_log('[CASANOVA][MAIL] STOP: intent_id inválido');
    return;
  }

  if (!function_exists('casanova_payment_intent_get')) {
    error_log('[CASANOVA][MAIL] STOP: casanova_payment_intent_get NO existe');
    return;
  }
  if (!function_exists('casanova_payment_intent_update')) {
    error_log('[CASANOVA][MAIL] STOP: casanova_payment_intent_update NO existe');
    return;
  }

  $intent = casanova_payment_intent_get($intent_id);
  if (!$intent || !is_object($intent)) {
    error_log('[CASANOVA][MAIL] STOP: intent no encontrado o no es objeto');
    return;
  }

  error_log('[CASANOVA][MAIL] intent status=' . ($intent->status ?? 'NULL')
    . ' user_id=' . ($intent->user_id ?? 'NULL')
    . ' amount=' . ($intent->amount ?? 'NULL')
    . ' mail_cobro_sent_at=' . (($intent->mail_cobro_sent_at ?? '') ?: 'NULL')
    . ' mail_expediente_sent_at=' . (($intent->mail_expediente_sent_at ?? '') ?: 'NULL')
  );

  if (($intent->status ?? '') !== 'reconciled') {
    error_log('[CASANOVA][MAIL] STOP: status != reconciled');
    return;
  }

  $user = get_user_by('id', (int)($intent->user_id ?? 0));
  if (!$user) {
    error_log('[CASANOVA][MAIL] STOP: user no encontrado (user_id=' . (int)($intent->user_id ?? 0) . ')');
    return;
  }
  if (empty($user->user_email)) {
    error_log('[CASANOVA][MAIL] STOP: user sin email (user_id=' . (int)$user->ID . ')');
    return;
  }

  error_log('[CASANOVA][MAIL] user OK id=' . (int)$user->ID . ' email=' . $user->user_email);

  $exp_id = (int)($intent->id_expediente ?? 0);
  $idCliente = (int)($intent->id_cliente ?? 0);

  // Unificar: código humano del expediente desde Expediente_GET
  $codExp = '';
  if ($exp_id > 0 && function_exists('casanova_giav_expediente_get')) {
    try {
      $exp = casanova_giav_expediente_get($exp_id);
      if (is_object($exp)) {
        $codExp = (string)($exp->CodigoExpediente ?? ($exp->Codigo ?? ''));
      }
    } catch (Throwable $e) {
      error_log('[CASANOVA][MAIL] WARN: expediente_get failed: ' . $e->getMessage());
    }
  }

  $ctx = [
    'to' => $user->user_email,
    'cliente_nombre' => ($user->first_name ?: $user->display_name),
    'idExpediente' => $exp_id,
    'codigoExpediente' => $codExp,
    'importe' => number_format((float)($intent->amount ?? 0), 2, ',', '.') . ' €',
    'fecha' => date_i18n('d/m/Y H:i', current_time('timestamp')),
    'pagado' => '',
    'pendiente' => '',
  ];

  error_log('[CASANOVA][MAIL] funcs: confirmed=' . (function_exists('casanova_mail_send_payment_confirmed') ? 'YES' : 'NO')
    . ' paid=' . (function_exists('casanova_mail_send_expediente_paid') ? 'YES' : 'NO')
  );

  // 1) Confirmación de cobro (si no se envió antes)
  if (!empty($intent->mail_cobro_sent_at)) {
    error_log('[CASANOVA][MAIL] SKIP: mail_cobro ya enviado');
  } elseif (!function_exists('casanova_mail_send_payment_confirmed')) {
    error_log('[CASANOVA][MAIL] STOP: casanova_mail_send_payment_confirmed NO existe');
  } else {
    error_log('[CASANOVA][MAIL] SENDING: payment_confirmed…');
    $ok = (bool) casanova_mail_send_payment_confirmed($ctx);
    error_log('[CASANOVA][MAIL] SENT payment_confirmed ok=' . ($ok ? 'YES' : 'NO'));

    if ($ok) {
      casanova_payment_intent_update($intent_id, [
        'mail_cobro_sent_at' => current_time('mysql'),
      ]);
      error_log('[CASANOVA][MAIL] UPDATED: mail_cobro_sent_at');
    }
  }

  // 2) Expediente pagado (1 vez por intent)
  if (!empty($intent->mail_expediente_sent_at)) {
    error_log('[CASANOVA][MAIL] SKIP: mail_expediente ya enviado');
    return;
  }

  if (!function_exists('casanova_mail_send_expediente_paid')) {
    error_log('[CASANOVA][MAIL] STOP: casanova_mail_send_expediente_paid NO existe');
    return;
  }

  // Para el email de "pago completo" sí podemos enriquecer con totales.
  $ctx_paid = $ctx;
  if ($exp_id > 0 && $idCliente > 0 && function_exists('casanova_giav_reservas_por_expediente') && function_exists('casanova_calc_pago_expediente')) {
    try {
      $reservas2 = casanova_giav_reservas_por_expediente($exp_id, $idCliente);
      if (!is_wp_error($reservas2)) {
        $calc2 = casanova_calc_pago_expediente($exp_id, $idCliente, $reservas2);
        if (!is_wp_error($calc2) && is_array($calc2)) {
          $ctx_paid['pagado'] = isset($calc2['pagado']) ? number_format((float)$calc2['pagado'], 2, ',', '.') . ' €' : '';
          $ctx_paid['pendiente'] = isset($calc2['pendiente_real']) ? number_format((float)$calc2['pendiente_real'], 2, ',', '.') . ' €' : '';
          if (isset($calc2['total_objetivo'])) {
            $ctx_paid['total'] = number_format((float)$calc2['total_objetivo'], 2, ',', '.') . ' €';
          }
        }
      }
    } catch (Throwable $e) {
      error_log('[CASANOVA][MAIL] WARN: enrich paid calc failed: ' . $e->getMessage());
    }
  }

  error_log('[CASANOVA][MAIL] SENDING: expediente_paid…');
  $ok_paid = (bool) casanova_mail_send_expediente_paid($ctx_paid);
  error_log('[CASANOVA][MAIL] SENT expediente_paid ok=' . ($ok_paid ? 'YES' : 'NO'));

  if ($ok_paid) {
    casanova_payment_intent_update($intent_id, [
      'mail_expediente_sent_at' => current_time('mysql'),
    ]);
    error_log('[CASANOVA][MAIL] UPDATED: mail_expediente_sent_at');
  }
}
