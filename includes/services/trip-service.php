<?php
if (!defined('ABSPATH')) exit;

/**
 * Service de Trip/Expediente.
 *
 * - Reutiliza helpers GIAV existentes.
 * - Devuelve contrato estable (JSON) para React.
 * - Degrada de forma segura si faltan dependencias o GIAV falla.
 */
class Casanova_Trip_Service {

  /**
   * @return array<string,mixed>
   */
  public static function get_trip_for_user(int $user_id, int $expediente_id, WP_REST_Request $request): array {

    $mock = (int) $request->get_param('mock') === 1;
    if ($mock && current_user_can('manage_options')) {
      return self::mock_response($expediente_id);
    }

    $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
    if (!$idCliente || !$expediente_id) {
      return self::empty_ok();
    }

    // Ownership: el expediente debe pertenecer al cliente.
    if (!self::client_owns_expediente($idCliente, $expediente_id)) {
      return [
        'status' => 'forbidden',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        'trip'   => null,
        'services' => [],
        'payments' => null,
        'invoices' => [],
        'vouchers' => [],
        'messages_meta' => ['unread_count' => 0, 'last_message_at' => null],
      ];
    }

    try {
      $trip = self::build_trip($idCliente, $expediente_id);
      $services = self::build_services($idCliente, $expediente_id);
      $payments = self::build_payments($user_id, $idCliente, $expediente_id, $services);
      $messages_meta = self::build_messages_meta($user_id, $expediente_id);

      return [
        'status' => 'ok',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        'trip'   => $trip,
        'services' => $services,
        'payments' => $payments,
        'invoices' => [],
        'vouchers' => [],
        'messages_meta' => $messages_meta,
      ];

    } catch (Exception $e) {
      return [
        'status' => 'degraded',
        'giav'   => ['ok' => false, 'source' => 'live', 'error' => $e->getMessage()],
        'trip'   => null,
        'services' => [],
        'payments' => null,
        'invoices' => [],
        'vouchers' => [],
        'messages_meta' => ['unread_count' => 0, 'last_message_at' => null],
      ];
    }
  }

  /**
   * @return array<string,mixed>
   */
  private static function empty_ok(): array {
    return [
      'status' => 'ok',
      'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
      'trip'   => null,
      'services' => [],
      'payments' => null,
      'invoices' => [],
      'vouchers' => [],
      'messages_meta' => ['unread_count' => 0, 'last_message_at' => null],
    ];
  }

  private static function client_owns_expediente(int $idCliente, int $idExpediente): bool {
    if (!function_exists('casanova_giav_expedientes_por_cliente')) return true; // no bloqueamos si falta dependencia

    $exps = casanova_giav_expedientes_por_cliente($idCliente);
    if (!is_array($exps)) return false;

    foreach ($exps as $e) {
      if (!is_object($e)) continue;
      $id = (int) ($e->IdExpediente ?? $e->IDExpediente ?? $e->Id ?? 0);
      if (!$id && isset($e->Codigo)) $id = (int) $e->Codigo;
      if ($id === $idExpediente) return true;
    }
    return false;
  }

  /**
   * @return array<string,mixed>|null
   */
  private static function build_trip(int $idCliente, int $idExpediente): ?array {
    if (!function_exists('casanova_giav_expediente_get')) {
      return [
        'id' => $idExpediente,
        'code' => 'EXP-' . $idExpediente,
        'title' => 'Expediente',
        'status' => '',
        'date_start' => null,
        'date_end' => null,
        'pax' => null,
      ];
    }

    $e = casanova_giav_expediente_get($idExpediente, $idCliente);
    if (!is_object($e)) {
      return null;
    }

    $code = (string) ($e->Codigo ?? 'EXP-' . $idExpediente);
    $title = (string) ($e->Titulo ?? $e->Nombre ?? 'Expediente');
    $status = (string) ($e->Estado ?? $e->Situacion ?? '');

    $ini = (string) ($e->FechaInicio ?? $e->FechaDesde ?? $e->Desde ?? '');
    $fin = (string) ($e->FechaFin ?? $e->FechaHasta ?? $e->Hasta ?? '');

    $date_start = $ini ? gmdate('Y-m-d', strtotime($ini)) : null;
    $date_end   = $fin ? gmdate('Y-m-d', strtotime($fin)) : null;

    $pax = null;
    if (isset($e->NumPax)) $pax = (int) $e->NumPax;
    if (!$pax && isset($e->Pax)) $pax = (int) $e->Pax;

    return [
      'id' => $idExpediente,
      'code' => $code,
      'title' => $title,
      'status' => $status,
      'date_start' => $date_start,
      'date_end' => $date_end,
      'pax' => $pax,
    ];
  }

  /**
   * @return array<int,array<string,mixed>>
   */
  private static function build_services(int $idCliente, int $idExpediente): array {
    if (!function_exists('casanova_giav_reservas_por_expediente')) return [];

    $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
    if (!is_array($reservas)) return [];

    $out = [];
    foreach ($reservas as $r) {
      if (!is_object($r)) continue;

      $m = function_exists('casanova_map_wsreserva') ? casanova_map_wsreserva($r) : [];

      $tipo = strtolower((string) ($m['tipo'] ?? ($r->TipoReserva ?? '')));
      $title = (string) ($m['descripcion'] ?? ($r->Descripcion ?? 'Servicio'));
      $id = (string) ($m['codigo'] ?? ($r->Codigo ?? ($r->Id ?? '')));

      $svc_type = 'extra';
      if (strpos($tipo, 'hotel') !== false) $svc_type = 'hotel';
      elseif (strpos($tipo, 'golf') !== false) $svc_type = 'golf';
      elseif (strpos($tipo, 'transfer') !== false || strpos($tipo, 'tras') !== false) $svc_type = 'transfer';
      elseif (strpos($tipo, 'paquete') !== false || strpos($tipo, 'package') !== false || strpos($tipo, 'pq') !== false) $svc_type = 'package';

      $status = null;
      if (function_exists('casanova_reserva_estado_from_mapped') && is_array($m) && !empty($m)) {
        [$lbl, $tone] = casanova_reserva_estado_from_mapped($m);
        $status = $tone === 'ok' ? 'ok' : ($tone === 'bad' ? 'cancelled' : 'pending');
      }

      $out[] = [
        'id' => $id ?: ('srv-' . (count($out) + 1)),
        'type' => $svc_type,
        'title' => $title,
        'status' => $status,
      ];
    }

    return $out;
  }

  /**
   * @param array<int,array<string,mixed>> $services
   * @return array<string,mixed>|null
   */
  private static function build_payments(int $user_id, int $idCliente, int $idExpediente, array $services): ?array {
    $ctx = Casanova_Payments_Service::describe_for_user($user_id, $idCliente, $idExpediente);
    if (is_wp_error($ctx)) {
      return null;
    }

    $actions = is_array($ctx['actions'] ?? null) ? $ctx['actions'] : [];

    return [
      'currency' => $ctx['currency'] ?? 'EUR',
      'total' => (float) ($ctx['total'] ?? 0),
      'paid' => (float) ($ctx['paid'] ?? 0),
      'pending' => (float) ($ctx['pending'] ?? 0),
      'can_pay' => (bool) ($ctx['can_pay'] ?? false),
      'pay_url' => $ctx['pay_url'] ?? null,
      'actions' => [
        'deposit' => $actions['deposit'] ?? ['allowed' => false, 'amount' => 0],
        'balance' => $actions['balance'] ?? ['allowed' => false, 'amount' => 0],
      ],
    ];
  }

  /**
   * @return array<string,mixed>
   */
  private static function build_messages_meta(int $user_id, int $idExpediente): array {
    $unread = function_exists('casanova_messages_new_count_for_expediente')
      ? (int) casanova_messages_new_count_for_expediente($user_id, $idExpediente, 30)
      : 0;

    $last = null;
    if (function_exists('casanova_giav_comments_por_expediente')) {
      $comments = casanova_giav_comments_por_expediente($idExpediente, 1, 365);
      if (is_array($comments) && !empty($comments)) {
        $c = $comments[0];
        if (is_object($c) && !empty($c->CreationDate)) {
          $last = (string) $c->CreationDate;
        }
      }
    }

    return [
      'unread_count' => $unread,
      'last_message_at' => $last,
    ];
  }

  /**
   * @return array<string,mixed>
   */
  private static function mock_response($expediente_id): array {
    $file = CASANOVA_GIAV_PLUGIN_PATH . 'includes/mock/trip.json';
    $raw  = file_exists($file) ? file_get_contents($file) : '';
    $all  = $raw ? json_decode($raw, true) : null;

    // Supports both:
    // - a single mock object (TripDetailResponse)
    // - a map keyed by expediente_id (string) => TripDetailResponse
    $data = null;
    if (is_array($all) && isset($all[(string) $expediente_id])) {
      $data = $all[(string) $expediente_id];
    } elseif (is_array($all) && isset($all['trip'])) {
      $data = $all; // single object
    } elseif (is_array($all)) {
      // map but missing key -> fallback to first element
      $first = reset($all);
      $data = is_array($first) ? $first : null;
    }

    if (!$data || !is_array($data)) {
      return [
        'status' => 'mock',
        'giav'   => ['ok' => true, 'source' => 'mock', 'error' => null],
        'trip'   => null,
        'services' => [],
        'payments' => null,
        'invoices' => [],
        'vouchers' => [],
        'messages_meta' => ['unread_count' => 0, 'last_message_at' => null],
      ];
    }

    if (!isset($data['status'])) $data['status'] = 'mock';
    if (!isset($data['giav'])) $data['giav'] = ['ok' => true, 'source' => 'mock', 'error' => null];
    if (!isset($data['services']) || !is_array($data['services'])) $data['services'] = [];
    if (!isset($data['invoices']) || !is_array($data['invoices'])) $data['invoices'] = [];
    if (!isset($data['vouchers']) || !is_array($data['vouchers'])) $data['vouchers'] = [];
    if (!isset($data['messages_meta']) || !is_array($data['messages_meta'])) $data['messages_meta'] = ['unread_count' => 0, 'last_message_at' => null];

    return $data;
  }
}
