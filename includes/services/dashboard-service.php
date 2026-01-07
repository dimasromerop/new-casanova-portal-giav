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

  public static function build_for_user(int $user_id): Casanova_Dashboard_DTO {

    $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);

    // Cache ligero: GIAV manda, WP consume.
    if ($idCliente > 0) {
      $cache_key = 'casanova_dash_v1_' . $idCliente;
      $cached = get_transient($cache_key);
      if (is_array($cached) && !empty($cached)) {
        return Casanova_Dashboard_DTO::from_array($cached);
      }
    }

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');

    // 1) Mulligans (datos locales)
    $m = function_exists('casanova_mulligans_get_user') ? (array) casanova_mulligans_get_user($user_id) : [];
    $m_points = isset($m['points']) ? (int) $m['points'] : 0;
    $m_tier   = isset($m['tier']) ? (string) $m['tier'] : '';
    $m_last   = isset($m['last_sync']) ? (int) $m['last_sync'] : 0;

    // 2) Viajes futuros (GIAV: expedientes)
    $trips = self::get_future_trips($idCliente);

    // 3) Próximo viaje (derivado)
    $next = !empty($trips) ? $trips[0] : null;

    // 4) Pagos (sobre próximo viaje)
    $payments = self::get_payments_summary($idCliente, $next);

    // 5) Mensajes (sobre próximo viaje)
    $messages = self::get_messages_summary($user_id, $idCliente, $next);

    $data = [
      'mulligans' => [
        'points'    => $m_points,
        'tier'      => $m_tier,
        'last_sync' => $m_last,
      ],
      'trips'    => $trips,
      'next_trip' => $next,
      'payments' => $payments,
      'messages' => $messages,
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

      $ini = $e->FechaInicio ?? $e->FechaDesde ?? $e->Desde ?? null;
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
    $idExp = (int) ($e->IdExpediente ?? $e->IDExpediente ?? $e->Id ?? 0);
    if (!$idExp && isset($e->Codigo)) $idExp = (int) $e->Codigo;

    $title  = (string) ($e->Titulo ?? $e->Nombre ?? 'Expediente');
    $code   = (string) ($e->Codigo ?? '');
    $status = (string) ($e->Estado ?? $e->Situacion ?? '');

    $fin = $e->FechaFin ?? $e->FechaHasta ?? $e->Hasta ?? null;
    $date_range = function_exists('casanova_fmt_date_range')
      ? (string) casanova_fmt_date_range($e->FechaInicio ?? null, $fin)
      : '';

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');
    $url = $idExp ? add_query_arg(['view' => 'expedientes', 'expediente' => $idExp], $base) : '';

      $out[] = [
        'id'         => $idExp,
        'title'      => $title,
        'code'       => $code,
        'status'     => $status,
        'date_range' => $date_range,
        'url'        => $url,
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

    if (!function_exists('casanova_giav_reservas_por_expediente') || !function_exists('casanova_calc_pago_expediente')) {
      return [];
    }

    $reservas = casanova_giav_reservas_por_expediente($idExp, $idCliente);
    if (!is_array($reservas)) {
      return [];
    }

    $p = casanova_calc_pago_expediente($idExp, $idCliente, $reservas);
    if (!is_array($p)) {
      return [];
    }

    $total  = (float) ($p['total_objetivo'] ?? 0);
    $paid   = (float) ($p['pagado_real'] ?? 0);
    $pending = max(0, $total - $paid);

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
