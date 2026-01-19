<?php
if (!defined('ABSPATH')) exit;

/**
 * Servicio de pagos reutilizable por REST y templates.
 */
class Casanova_Payments_Service {

  /**
   * Describe el estado de pagos de un expediente autorizado.
   *
   * @return array<string,mixed>|WP_Error
   */
  public static function describe_for_user(int $user_id, int $idCliente, int $idExpediente) {
    if ($idCliente <= 0) {
      return new WP_Error('payments_no_client', esc_html__('Tu cuenta no está vinculada a un cliente casanova.', 'casanova-portal'));
    }

    if ($idExpediente <= 0) {
      return new WP_Error('payments_invalid_expediente', esc_html__('Expediente inválido.', 'casanova-portal'));
    }

    if (!function_exists('casanova_user_can_access_expediente')) {
      return new WP_Error('payments_missing_helper', esc_html__('No se puede verificar la propiedad del expediente.', 'casanova-portal'));
    }

    if (!casanova_user_can_access_expediente($user_id, $idExpediente)) {
      return new WP_Error('permissions', esc_html__('No autorizado para este expediente.', 'casanova-portal'));
    }

    if (!function_exists('casanova_giav_reservas_por_expediente')) {
      return new WP_Error('payments_missing_feature', esc_html__('Reservas no disponibles.', 'casanova-portal'));
    }

    $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
    if (is_wp_error($reservas)) {
      return new WP_Error('reservas_error', $reservas->get_error_message());
    }
    if (empty($reservas) || !is_array($reservas)) {
      return new WP_Error('reservas_empty', esc_html__('No se encontraron reservas para este expediente.', 'casanova-portal'));
    }

    if (!function_exists('casanova_calc_pago_expediente')) {
      return new WP_Error('payments_missing_calc', esc_html__('No se puede calcular el estado de pagos.', 'casanova-portal'));
    }

    $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
    if (is_wp_error($calc)) {
      return $calc;
    }

    $total = (float) ($calc['total_objetivo'] ?? 0);
    $paid = (float) ($calc['pagado'] ?? 0);
    $pending = max(0.0, (float) ($calc['pendiente_real'] ?? 0));
    $is_paid = !empty($calc['expediente_pagado']);

    $deposit_allowed = false;
    $deposit_amount = 0.0;
    if ($paid <= 0.01 && function_exists('casanova_payments_is_deposit_allowed')) {
      $deposit_allowed = casanova_payments_is_deposit_allowed($reservas);
    }

    if ($deposit_allowed && function_exists('casanova_payments_calc_deposit_amount')) {
      $deposit_amount = casanova_payments_calc_deposit_amount($pending, $idExpediente);
    }
    $deposit_effective = $deposit_allowed && ($deposit_amount + 0.01 < $pending);

    if ($deposit_amount < 0) $deposit_amount = 0;

    $deadline_iso = null;
    if (function_exists('casanova_payments_min_fecha_limite')) {
      $deadline = casanova_payments_min_fecha_limite($reservas);
      if ($deadline instanceof DateTimeInterface) {
        $deadline_iso = $deadline->format('Y-m-d');
      }
    }

    $can_pay_full = $pending > 0.01;
    $payment_options = [
      'can_pay_deposit' => (bool) $deposit_effective,
      'deposit_deadline' => $deadline_iso,
      'can_pay_full' => (bool) $can_pay_full,
      'recommended' => $deposit_effective ? 'deposit' : ($can_pay_full ? 'full' : null),
      'deposit_amount' => round($deposit_amount, 2),
      'pending_amount' => round($pending, 2),
    ];

    if (!function_exists('casanova_portal_pay_expediente_url')) {
      $pay_url = add_query_arg([
        'action' => 'casanova_pay_expediente',
        'expediente' => $idExpediente,
        '_wpnonce' => wp_create_nonce('casanova_pay_expediente_' . $idExpediente),
      ], admin_url('admin-post.php'));
    } else {
      $pay_url = casanova_portal_pay_expediente_url($idExpediente);
    }

    // Nombre a mostrar como "pagador" por defecto (evita el genérico "WP user X").
    $payer_default = '';
    if (function_exists('get_userdata')) {
      $u = get_userdata($user_id);
      if ($u && is_object($u)) {
        $payer_default = trim((string)($u->display_name ?? ''));
      }
    }
    if ($payer_default === '') {
      $payer_default = __('Cliente', 'casanova-portal');
    }

    return [
      'user_id' => $user_id,
      'idCliente' => $idCliente,
      'idExpediente' => $idExpediente,
      'reservas' => $reservas,
      'calc' => is_array($calc) ? $calc : [],
      'total' => $total,
      'paid' => $paid,
      'pending' => $pending,
      'currency' => 'EUR',
      'can_pay' => $pending > 0.01,
      'pay_url' => $pay_url,
      'history' => self::fetch_cobros_history($idExpediente, $idCliente, $payer_default),
      'expediente_pagado' => $is_paid,
      'mulligans_used' => casanova_mulligans_used_for_expediente($idExpediente, $idCliente),
      'payment_options' => $payment_options,
      'actions' => [
        'deposit' => [
          'allowed' => (bool) $payment_options['can_pay_deposit'],
          'amount' => (float) $payment_options['deposit_amount'],
        ],
        'balance' => [
          'allowed' => (bool) $payment_options['can_pay_full'],
          'amount' => (float) $payment_options['pending_amount'],
        ],
      ],
    ];
  }

  /**
   * Devuelve histórico de cobros y mejora campos para UX:
   * - "concept" identifica si fue Depósito / Saldo cuando viene de nuestro TPV.
   * - "payer" sustituye el genérico "WP user X" por el nombre del cliente.
   */
  private static function fetch_cobros_history(int $idExpediente, int $idCliente, string $payer_default = ''): array {
    if ($idExpediente <= 0 || $idCliente <= 0 || !function_exists('casanova_giav_cobros_por_expediente_all')) {
      return [];
    }

    $items = casanova_giav_cobros_por_expediente_all($idExpediente, $idCliente);
    if (is_wp_error($items) || !is_array($items)) {
      return [];
    }

    $rows = [];
    foreach ($items as $item) {
      if (!is_object($item)) continue;

      $date_raw = trim((string) ($item->FechaCobro ?? ''));
      $ts = $date_raw ? strtotime($date_raw) : 0;
      $date = $ts ? date_i18n('Y-m-d', $ts) : '';

      $importe = (float) ($item->Importe ?? 0);
      $tipo = trim((string) ($item->TipoOperacion ?? ''));
      $tipo_up = strtoupper($tipo);
      $is_refund = (strpos($tipo_up, 'REEM') !== false || strpos($tipo_up, 'DEV') !== false);

      if ($tipo === '') {
        if ($importe >= 0) {
          $tipo = __('Cobro', 'casanova-portal');
        } else {
          $tipo = __('Reembolso', 'casanova-portal');
        }
      }

      $concept = trim((string) ($item->Concepto ?? ''));
      if ($concept === '') {
        $concept = trim((string) ($item->Documento ?? ''));
      }
      if ($concept === '') {
        $concept = __('Pago', 'casanova-portal');
      }

      $payer = trim((string) ($item->Pagador ?? ''));
      $doc = trim((string) ($item->Documento ?? ''));

      // --- Enriquecimiento para cobros del portal (Redsys) ---
      // 1) Normalizar pagador: evitar "WP user X"
      if ($payer_default !== '') {
        if ($payer === '' || preg_match('/^wp\s*user\s*\d+/i', $payer)) {
          $payer = $payer_default;
        }
      }

      // 2) Detectar modo (deposit/full) desde nuestra tabla de intents y renombrar concepto.
      // GIAV suele guardar "Pago Redsys <ORDER>" en Concepto.
      $order = '';
      if ($concept !== '') {
        if (preg_match('/Redsys\s*([0-9A-Za-z]+)/i', $concept, $m)) {
          $order = (string)($m[1] ?? '');
        }
      }
      if ($order === '' && $doc !== '') {
        // Algunos GIAV guardan order en Documento o variaciones.
        if (preg_match('/([0-9A-Za-z]{6,})/', $doc, $m)) {
          $order = (string)($m[1] ?? '');
        }
      }

      if ($order !== '' && function_exists('casanova_payment_intent_get_by_order')) {
        $intent = casanova_payment_intent_get_by_order($order);
        if ($intent && is_object($intent)) {
          $payload = [];
          $payload_raw = (string)($intent->payload ?? '');
          if ($payload_raw !== '') {
            $decoded = json_decode($payload_raw, true);
            if (is_array($decoded)) $payload = $decoded;
          }
          $mode = strtolower(trim((string)($payload['mode'] ?? '')));
          if ($mode === 'deposit') {
            $concept = sprintf(__('Depósito (Redsys %s)', 'casanova-portal'), $order);
          } elseif ($mode === 'full') {
            $concept = sprintf(__('Pago (Redsys %s)', 'casanova-portal'), $order);
          } else {
            // Si no sabemos modo, al menos indicamos que viene del portal.
            $concept = sprintf(__('Pago (Redsys %s)', 'casanova-portal'), $order);
          }
        }
      }

      $rows[] = [
        'id' => (string) ($item->Id ?? $item->IdCobro ?? $item->IdCobroSolicitud ?? uniqid('cobro-', true)),
        'date' => $date,
        'timestamp' => $ts,
        'type' => $tipo,
        'is_refund' => $is_refund,
        'concept' => $concept,
        'payer' => $payer,
        'document' => $doc,
        'amount' => abs($importe),
      ];
    }

    usort($rows, function($a, $b) {
      return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
    });

    return $rows;
  }

}
