<?php
if (!defined('ABSPATH')) exit;

/**
 * Service del Dashboard.
 *
 * - Centraliza la lógica de datos.
 * - Reutilizable por shortcodes (legacy) y por REST/React.
 * - Mantiene enfoque "sin romper": si faltan helpers, degrada de forma segura.
 */
class Casanova_Dashboard_Service {

  public static function build_for_user(int $user_id, bool $refresh = false): Casanova_Dashboard_DTO {

    $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);

    // Cache ligero: GIAV manda, WP consume.
    if (!$refresh && $idCliente > 0) {
      $cache_key = 'casanova_dash_v1_' . $idCliente;
      $cached = get_transient($cache_key);
      if (is_array($cached) && !empty($cached)) {
        return Casanova_Dashboard_DTO::from_array($cached);
      }
    }

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');

    // 1) Mulligans (datos locales)

    $m_user = function_exists('casanova_mulligans_get_user') ? (array) casanova_mulligans_get_user($user_id) : [];
    $m_points = isset($m_user['points']) ? (int) $m_user['points'] : 0;
    $m_tier   = isset($m_user['tier']) ? (string) $m_user['tier'] : '';
    $m_last   = isset($m_user['last_sync']) ? (int) $m_user['last_sync'] : 0;
    $m_spend  = isset($m_user['spend']) ? (float) $m_user['spend'] : 0.0;
    $m_earned = isset($m_user['earned']) ? (int) $m_user['earned'] : 0;
    $m_bonus  = isset($m_user['bonus']) ? (int) $m_user['bonus'] : 0;
    $m_used   = isset($m_user['used']) ? (int) $m_user['used'] : 0;

    $m_ledger = [];
    $ledger_raw = (string) get_user_meta($user_id, CASANOVA_MULL_META_LEDGER, true);
    if ($ledger_raw) {
      $decoded = json_decode($ledger_raw, true);
      if (is_array($decoded)) {
        // ordena por ts desc
        usort($decoded, function($a, $b){
          $ta = (int)($a['ts'] ?? 0);
          $tb = (int)($b['ts'] ?? 0);
          return $tb <=> $ta;
        });
        $m_ledger = array_slice($decoded, 0, 20);
      }
    }



    // 2) Viajes futuros (GIAV: expedientes)
    $trips = self::get_future_trips($idCliente);

    // 3) Próximo viaje (derivado)
    $next = !empty($trips) ? $trips[0] : null;

    // 4) Pagos (sobre próximo viaje)
    $payments = self::get_payments_summary($idCliente, $next);

    // 5) Mensajes (sobre próximo viaje)
    $messages = self::get_messages_summary($user_id, $idCliente, $next);

    // 6) Próxima acción (prioriza siguiente viaje si el actual está al día)
    $next_action = self::get_next_action($idCliente, $next, $trips, $payments);

    $data = [
      'mulligans' => [
        'points'    => $m_points,
        'tier'      => $m_tier,
        'last_sync' => $m_last,
        'spend'     => $m_spend,
        'earned'    => $m_earned,
        'bonus'     => $m_bonus,
        'used'      => $m_used,
        'ledger'    => $m_ledger,
      ],
      'trips'    => $trips,
      'next_trip' => $next,
      'payments' => $payments,
      'messages' => $messages,
      'next_action' => $next_action,
    ];

    if ($idCliente > 0) {
      set_transient('casanova_dash_v1_' . $idCliente, $data, 60); // TTL corto
    }

    return new Casanova_Dashboard_DTO($data);
  }

  /**
   * @return array<int,array<string,mixed>>
   */
  private static function get_future_trips(int $idCliente): array {

    if (!$idCliente || !function_exists('casanova_giav_expedientes_por_cliente')) {
      return [];
    }

    $today = new DateTimeImmutable('today', wp_timezone());
    $items = [];

    $exps = casanova_giav_expedientes_por_cliente($idCliente);
    if (!is_array($exps)) {
      return [];
    }

    foreach ($exps as $e) {
      if (!is_object($e)) continue;

      $ini = $e->FechaDesde ?? $e->Desde ?? $e->FechaInicio ?? $e->FechaInicioViaje ?? null;
      if (!$ini) continue;

      $ts = strtotime((string) $ini);
      if (!$ts) continue;

      $d = (new DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone());
      if ($d < $today) continue;

      $items[] = [
        'obj'  => $e,
        'date' => $d,
      ];
    }

    if (empty($items)) return [];

    usort($items, function($a, $b) {
      $da = $a['date'] ?? null;
      $db = $b['date'] ?? null;
      if (!$da || !$db) return 0;
      return $da <=> $db;
    });

    $out = [];
    foreach ($items as $row) {
      if (empty($row['obj']) || !is_object($row['obj'])) continue;
      $e = $row['obj'];

    $ini = $e->FechaDesde ?? $e->Desde ?? $e->FechaInicio ?? $e->FechaInicioViaje ?? null;

    $idExp = (int) ($e->IdExpediente ?? $e->IDExpediente ?? $e->Id ?? 0);
    if (!$idExp && isset($e->Codigo)) $idExp = (int) $e->Codigo;

    $title  = (string) ($e->Titulo ?? $e->Nombre ?? 'Expediente');
    $code   = (string) ($e->Codigo ?? '');
    $status = (string) ($e->Estado ?? $e->Situacion ?? '');

    // Fallback: si GIAV no trae Estado/Situación, inferimos por fechas (sin lógica en React)
    if ($status === '' && ($ini || $fin)) {
      $today_iso = date('Y-m-d');
      $fin_iso = $fin && function_exists('casanova_date_to_iso') ? (string) casanova_date_to_iso($fin) : '';
      $status = ($fin_iso && $fin_iso < $today_iso) ? 'Cerrado' : 'Abierto';
    }

    $fin = $e->FechaHasta ?? $e->Hasta ?? $e->FechaFin ?? $e->FechaFinViaje ?? null;
    $date_range = function_exists('casanova_fmt_date_range')
      ? (string) casanova_fmt_date_range($ini ?? null, $fin)
      : '';

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');
    $url = $idExp ? add_query_arg(['view' => 'expedientes', 'expediente' => $idExp], $base) : '';

    $days_left = 0;
    if (isset($row['date']) && $row['date'] instanceof DateTimeImmutable) {
      $days_left = (int) $today->diff($row['date'])->format('%a');
    }

    $ics_url = '';
    if ($idExp > 0) {
      if (function_exists('casanova_portal_ics_url')) {
        $ics_url = casanova_portal_ics_url($idExp);
      } else {
        $ics_url = add_query_arg([
          'casanova_action' => 'download_ics',
          'expediente'      => (int) $idExp,
          '_wpnonce'        => wp_create_nonce('casanova_download_ics_' . (int)$idExp),
        ], $base);
      }
    }

    $payments = [];
    $bonuses = [];
    if ($idExp > 0) {
      $calc = self::get_payments_for_expediente($idCliente, $idExp);
      if (!empty($calc)) {
        $total = (float) ($calc['total'] ?? 0);
        $paid = (float) ($calc['paid'] ?? 0);
        $pending = (float) ($calc['pending'] ?? 0);
        $is_paid = !empty($calc['is_paid']) || ($pending <= 0.01);
        $payments = [
          'total' => $total,
          'paid' => $paid,
          'pending' => $pending,
          'is_paid' => $is_paid,
        ];
        $bonuses = [
          'available' => $is_paid,
        ];
      }
    }

      $out[] = [
        'id'         => $idExp,
        'title'      => $title,
        'code'       => $code,
        'status'     => $status,
        'date_start' => $ini ? gmdate('Y-m-d', strtotime((string)$ini)) : null,
        'date_end'   => $fin ? gmdate('Y-m-d', strtotime((string)$fin)) : null,
        'date_range' => $date_range,
        'url'        => $url,
        'days_left'  => $days_left,
        'calendar_url' => $ics_url,
        'payments'   => $payments,
        'bonuses'    => $bonuses,
        '_raw'       => [
          'FechaInicio' => isset($e->FechaInicio) ? (string) $e->FechaInicio : null,
          'FechaFin'    => $fin ? (string) $fin : null,
        ],
      ];
      if (count($out) >= 10) break;
    }

    return $out;
  }

  /**
   * @param array<string,mixed>|null $next_trip
   * @return array<string,mixed>
   */
  private static function get_payments_summary(int $idCliente, ?array $next_trip): array {

    if (!$idCliente || empty($next_trip['id'])) {
      return [];
    }

    $idExp = (int) $next_trip['id'];
    if (!$idExp) return [];

    $calc = self::get_payments_for_expediente($idCliente, $idExp);
    if (empty($calc)) return [];

    $total = (float) ($calc['total'] ?? 0);
    $paid = (float) ($calc['paid'] ?? 0);
    $pending = (float) ($calc['pending'] ?? 0);

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');
    $url = add_query_arg(['view' => 'expedientes', 'expediente' => $idExp], $base) . '#pagos';

    return [
      'total'   => $total,
      'paid'    => $paid,
      'pending' => $pending,
      'url'     => $url,
    ];
  }

  /**
   * @return array<string,float>
   */
  private static function get_payments_for_expediente(int $idCliente, int $idExp): array {
    if ($idCliente <= 0 || $idExp <= 0) return [];
    if (!function_exists('casanova_giav_reservas_por_expediente') || !function_exists('casanova_calc_pago_expediente')) {
      return [];
    }

    $reservas = casanova_giav_reservas_por_expediente($idExp, $idCliente);
    if (!is_array($reservas)) return [];

    $p = casanova_calc_pago_expediente($idExp, $idCliente, $reservas);
    if (!is_array($p)) return [];

    $total = (float) ($p['total_objetivo'] ?? 0);
    $paid = (float) ($p['pagado'] ?? ($p['pagado_real'] ?? 0));
    $pending = isset($p['pendiente_real']) ? (float) $p['pendiente_real'] : max(0, $total - $paid);
    if ($pending < 0) $pending = 0;
    $is_paid = !empty($p['expediente_pagado']) || ($pending <= 0.01);

    return [
      'total' => $total,
      'paid' => $paid,
      'pending' => $pending,
      'is_paid' => $is_paid,
    ];
  }


  /**
   * Devuelve un listado normalizado de facturas (GIAV) para un expediente.
   * @return array<int,array<string,mixed>>
   */
  private static function get_invoices_for_expediente(int $idCliente, int $idExpediente): array {
    if ($idCliente <= 0 || $idExpediente <= 0 || !function_exists('casanova_giav_facturas_por_expediente')) {
      return [];
    }
    $rows = casanova_giav_facturas_por_expediente($idExpediente, $idCliente, 50, 0);
    if (is_wp_error($rows) || !is_array($rows)) return [];
    $out = [];
    foreach ($rows as $f) {
      if (!is_object($f)) continue;
      $id = (int) ($f->Id ?? $f->ID ?? 0);
      if ($id <= 0) continue;

      $num = (string) ($f->Numero ?? $f->NumFactura ?? $f->Codigo ?? ('F' . $id));
      $fecha = (string) ($f->Fecha ?? $f->FechaFactura ?? '');
      $iso = $fecha ? gmdate('Y-m-d', strtotime($fecha)) : null;

      $importe = null;
      foreach (['Importe', 'Total', 'ImporteTotal', 'ImporteFactura'] as $k) {
        if (isset($f->$k) && $f->$k !== '') { $importe = (float) $f->$k; break; }
      }

      $estado = (string) ($f->Estado ?? $f->Situacion ?? '');
      $out[] = [
        'id' => $id,
        'title' => $num,
        'date' => $iso,
        'amount' => $importe,
        'status' => $estado,
        'download_url' => '', // se rellena en /trip, aquí solo contamos
      ];
    }
    return $out;
  }

  private static function get_invoices_count_for_expediente(int $idCliente, int $idExpediente): int {
    $inv = self::get_invoices_for_expediente($idCliente, $idExpediente);
    return is_array($inv) ? count($inv) : 0;
  }


  /**
   * @param array<int,array<string,mixed>> $trips
   * @param array<string,mixed> $payments
   * @return array<string,mixed>
   */
  private static function get_next_action(int $idCliente, ?array $next_trip, array $trips, array $payments): array {
    if (!$next_trip || empty($next_trip['id'])) {
      return [
        'status' => 'empty',
        'badge' => __('Info', 'casanova-portal'),
        'description' => __('No hay viajes próximos para mostrar aquí.', 'casanova-portal'),
      ];
    }

    $trip_label = self::format_trip_label($next_trip);
    $pending = (float) ($payments['pending'] ?? 0);
    $has_payments = !empty($payments);

    if ($has_payments && $pending > 0.01) {
      return [
        'status' => 'pending',
        'badge' => __('Pendiente', 'casanova-portal'),
        'description' => sprintf(
          __('Tienes un pago pendiente de %s.', 'casanova-portal'),
          casanova_fmt_money($pending)
        ),
        'expediente_id' => (int) $next_trip['id'],
        'trip_label' => $trip_label,
      ];
    }
    // Si no hay pagos pendientes, revisamos si hay facturas disponibles para descargar
    $inv_count = self::get_invoices_count_for_expediente($idCliente, (int) $next_trip['id']);
    if ($inv_count > 0) {
      return [
        'status' => 'invoices',
        'badge' => __('Facturas', 'casanova-portal'),
        'description' => sprintf(__('Tienes %d facturas disponibles.', 'casanova-portal'), $inv_count),
        'expediente_id' => (int) $next_trip['id'],
        'trip_label' => $trip_label,
        'invoice_count' => $inv_count,
      ];
    }



    $note = null;
    if (count($trips) >= 2) {
      $second = $trips[1];
      $second_id = (int) ($second['id'] ?? 0);
      if ($second_id > 0 && $second_id !== (int) $next_trip['id']) {
        $calc = self::get_payments_for_expediente($idCliente, $second_id);
        if (!empty($calc)) {
          $pend2 = (float) ($calc['pending'] ?? 0);
          if ($pend2 > 0.01) {
            $note = [
              'label' => self::format_trip_label($second),
              'expediente_id' => $second_id,
              'pending' => casanova_fmt_money($pend2),
            ];
          }
        }
      }
    }

    return [
      'status' => 'ok',
      'badge' => __('Todo listo', 'casanova-portal'),
      'description' => __('Tu próximo viaje está al día. No tienes acciones pendientes ahora mismo.', 'casanova-portal'),
      'expediente_id' => (int) $next_trip['id'],
      'trip_label' => $trip_label,
      'note' => $note,
    ];
  }

  /**
   * @param array<string,mixed> $trip
   */
  private static function format_trip_label(array $trip): string {
    $title = trim((string) ($trip['title'] ?? ''));
    $code = trim((string) ($trip['code'] ?? ''));
    if ($title && $code) return $title . ' (' . $code . ')';
    if ($title) return $title;
    if ($code) return sprintf(__('Expediente %s', 'casanova-portal'), $code);
    $id = (int) ($trip['id'] ?? 0);
    return $id ? sprintf(__('Expediente %s', 'casanova-portal'), (string) $id) : __('Viaje', 'casanova-portal');
  }

  /**
   * @param array<string,mixed>|null $next_trip
   * @return array<string,mixed>
   */
  private static function get_messages_summary(int $user_id, int $idCliente, ?array $next_trip): array {

    $idExp = (int) ($next_trip['id'] ?? 0);
    if (!$idExp) {
      return [];
    }

    if (!function_exists('casanova_giav_comments_por_expediente')) {
      return [];
    }

    $unread = function_exists('casanova_messages_new_count_for_expediente')
      ? (int) casanova_messages_new_count_for_expediente($user_id, $idExp, 30)
      : 0;

    $comments = casanova_giav_comments_por_expediente($idExp, 10, 365);
    if (is_wp_error($comments) || !is_array($comments) || empty($comments)) {
      $comments = [];
    }

    $snippet = '';
    $when = '';

    if (!empty($comments)) {
      $latest = $comments[0];
      $b = is_object($latest) ? trim((string) ($latest->Body ?? '')) : '';
      $b = $b !== '' ? wp_strip_all_tags($b) : '';
      if ($b !== '' && mb_strlen($b, 'UTF-8') > 140) {
        $b = mb_substr($b, 0, 140, 'UTF-8') . '…';
      }
      $snippet = $b;

      $ts = is_object($latest) ? (strtotime((string) ($latest->CreationDate ?? '')) ?: 0) : 0;
      $when = $ts ? sprintf(esc_html__('Hace %s', 'casanova-portal'), human_time_diff($ts, time())) : '';
    }

    $trip_label = '';
    if (!empty($next_trip['title']) || !empty($next_trip['code'])) {
      $t = trim((string) ($next_trip['title'] ?? ''));
      $c = trim((string) ($next_trip['code'] ?? ''));
      if ($t && $c) $trip_label = $t . ' (' . $c . ')';
      elseif ($t) $trip_label = $t;
      elseif ($c) $trip_label = sprintf(__('Expediente %s', 'casanova-portal'), $c);
    }

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');
    $url = add_query_arg(['view' => 'mensajes', 'expediente' => $idExp], $base);

    return [
      'unread'    => $unread,
      'snippet'   => $snippet,
      'when'      => $when,
      'trip_label'=> $trip_label,
      'url'       => $url,
    ];
  }
}
