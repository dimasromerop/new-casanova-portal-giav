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
  public static function get_trip_for_user(int $user_id, int $expediente_id, WP_REST_Request $request): array|WP_Error {

    $mock = (int) $request->get_param('mock') === 1;
    if ($mock && current_user_can('manage_options')) {
      return self::mock_response($expediente_id);
    }

    $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
    if (!$idCliente || !$expediente_id) {
      return self::empty_ok();
    }

    // Ownership: el expediente debe pertenecer al cliente.
    if (!self::client_owns_expediente($idCliente, $expediente_id, $user_id)) {
      return new WP_Error('rest_forbidden', __('No autorizado', 'casanova-portal'), ['status' => 403]);
    }

    try {
      $trip = self::build_trip($idCliente, $expediente_id);
      $reservas = self::build_reservas($idCliente, $expediente_id);
      $structure = self::build_package_structure($expediente_id, $reservas);
      $passengers = self::build_passengers($expediente_id);
      $services = [];
      $payments = self::build_payments($user_id, $idCliente, $expediente_id, $services);
      
      $invoices = [];
      if (function_exists('casanova_giav_facturas_por_expediente')) {
        $rows = casanova_giav_facturas_por_expediente($expediente_id, $idCliente, 50, 0);
        if (!is_wp_error($rows) && is_array($rows)) {
          $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');
          foreach ($rows as $f) {
            if (!is_object($f)) continue;
            $idF = (int) ($f->Id ?? $f->ID ?? 0);
            if ($idF <= 0) continue;
            $num = (string) ($f->Numero ?? $f->NumFactura ?? $f->Codigo ?? ('F' . $idF));

            $dateFields = ['FechaEmision', 'FechaFactura', 'Fecha'];
            $iso = null;
            foreach ($dateFields as $field) {
              if (!empty($f->$field)) {
                $parsed = strtotime((string) $f->$field);
                if ($parsed !== false) {
                  $iso = gmdate('Y-m-d', $parsed);
                  break;
                }
              }
            }

            $datosExternos = isset($f->DatosExternos) && is_object($f->DatosExternos) ? $f->DatosExternos : null;
            $importe = null;
            if ($datosExternos && isset($datosExternos->TotalFactura) && $datosExternos->TotalFactura !== '') {
              $importe = (float) $datosExternos->TotalFactura;
            } else {
              foreach (['Importe', 'Total', 'ImporteTotal', 'ImporteFactura'] as $key) {
                if (isset($f->$key) && $f->$key !== '') { $importe = (float) $f->$key; break; }
              }
            }

            $pendiente = null;
            if ($datosExternos && isset($datosExternos->PendienteCobro) && $datosExternos->PendienteCobro !== '') {
              $pendiente = (float) $datosExternos->PendienteCobro;
            } elseif (isset($f->PendienteCobro) && $f->PendienteCobro !== '') {
              $pendiente = (float) $f->PendienteCobro;
            }

            $estado = '';
            if (!empty($f->Estado)) {
              $estado = (string) $f->Estado;
            } elseif (!empty($f->Situacion)) {
              $estado = (string) $f->Situacion;
            } elseif ($pendiente !== null) {
              $estado = $pendiente > 0.01 ? __('Pendiente', 'casanova-portal') : __('Pagada', 'casanova-portal');
            }

            $nonce = wp_create_nonce('casanova_invoice_pdf_' . $expediente_id . '_' . $idF);
            $download_url = add_query_arg([
              'casanova_action' => 'invoice_pdf',
              'expediente' => $expediente_id,
              'factura' => $idF,
              '_wpnonce' => $nonce,
            ], $base);

            $invoices[] = [
              'id' => $idF,
              'title' => $num,
              'date' => $iso,
              'amount' => $importe,
              'status' => $estado,
              'download_url' => $download_url,
            ];
          }
        }
      }

$bonuses = self::build_bonos($idCliente, $expediente_id);
      $messages_meta = self::build_messages_meta($user_id, $expediente_id);

      return [
        'status' => 'ok',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        'trip'   => $trip,
        'package' => $structure['package'],
        'extras' => $structure['extras'],
        'passengers' => $passengers,
        'payments' => $payments,
        'invoices' => $invoices,
        'vouchers' => $bonuses['items'] ?? [],
        'bonuses' => $bonuses,
        'messages_meta' => $messages_meta,
      ];

    } catch (Exception $e) {
      return [
        'status' => 'degraded',
        'giav'   => ['ok' => false, 'source' => 'live', 'error' => $e->getMessage()],
        'trip'   => null,
        'package' => null,
        'extras' => [],
        'passengers' => [],
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
      'package' => null,
      'extras' => [],
      'passengers' => [],
      'payments' => null,
      'invoices' => [],
      'vouchers' => [],
      'messages_meta' => ['unread_count' => 0, 'last_message_at' => null],
    ];
  }

  private static function client_owns_expediente(int $idCliente, int $expediente_id, int $user_id): bool {
    if (function_exists('casanova_user_can_access_expediente')) {
      return casanova_user_can_access_expediente($user_id, $expediente_id);
    }
    if (!function_exists('casanova_giav_expedientes_por_cliente')) return true; // no bloqueamos si falta dependencia

    $exps = casanova_giav_expedientes_por_cliente($idCliente);
    if (!is_array($exps)) return false;

    foreach ($exps as $e) {
      if (!is_object($e)) continue;
      $id = (int) ($e->IdExpediente ?? $e->IDExpediente ?? $e->Id ?? 0);
      if (!$id && isset($e->Codigo)) $id = (int) $e->Codigo;
      if ($id === $expediente_id) return true;
    }
    return false;
  }

  /**
   * @return array<string,mixed>|null
   */
  private static function build_trip(int $idCliente, int $expediente_id): ?array {
    if (!function_exists('casanova_giav_expediente_get')) {
      return [
        'id' => $expediente_id,
        'code' => 'EXP-' . $expediente_id,
        'title' => 'Expediente',
        'status' => '',
        'date_start' => null,
        'date_end' => null,
        'date_range' => '',
        'pax' => null,
      ];
    }

    $e = casanova_giav_expediente_get($expediente_id, $idCliente);
    if (!is_object($e)) {
      return null;
    }

    $code = (string) ($e->Codigo ?? 'EXP-' . $expediente_id);
    $title = (string) ($e->Titulo ?? $e->Nombre ?? 'Expediente');
    $status = (string) ($e->Estado ?? $e->Situacion ?? '');

    $ini = (string) ($e->FechaInicio ?? $e->FechaDesde ?? $e->Desde ?? '');
    $fin = (string) ($e->FechaFin ?? $e->FechaHasta ?? $e->Hasta ?? '');

    $date_start = $ini ? gmdate('Y-m-d', strtotime($ini)) : null;
    $date_end   = $fin ? gmdate('Y-m-d', strtotime($fin)) : null;

    $pax = null;
    if (isset($e->NumPax)) $pax = (int) $e->NumPax;
    if (!$pax && isset($e->Pax)) $pax = (int) $e->Pax;

    $date_range = function_exists('casanova_fmt_date_range') ? casanova_fmt_date_range($ini, $fin) : '';

    return [
      'id' => $expediente_id,
      'code' => $code,
      'title' => $title,
      'status' => $status,
      'date_start' => $date_start,
      'date_end' => $date_end,
      'date_range' => $date_range,
      'pax' => $pax,
    ];
  }

  /**
   * @return array<int,array<string,mixed>>
   */
  private static function build_reservas(int $idCliente, int $expediente_id): array {
    if (!function_exists('casanova_giav_reservas_por_expediente')) return [];

    $reservas = casanova_giav_reservas_por_expediente($expediente_id, $idCliente);
    if (!is_array($reservas)) return [];

    return $reservas;
  }

  /**
   * @param array<int,mixed> $reservas
   * @return array{package: array<string,mixed>|null, extras: array<int,array<string,mixed>>}
   */
  private static function build_package_structure(int $expediente_id, array $reservas): array {
    if (empty($reservas)) {
      return ['package' => null, 'extras' => []];
    }

    $byId = [];
    foreach ($reservas as $r) {
      if (!is_object($r)) continue;
      $rid = (int) ($r->Id ?? 0);
      if ($rid) $byId[$rid] = $r;
    }

    $has_parent = function($r) use ($byId): bool {
      $pid = (int) ($r->Anidacion_IdReservaContenedora ?? 0);
      return $pid > 0 && isset($byId[$pid]);
    };

    $pqs = [];
    foreach ($reservas as $r) {
      if (!is_object($r)) continue;
      $tipo = (string) ($r->TipoReserva ?? '');
      if ($tipo === 'PQ' && !$has_parent($r)) {
        $pqs[(int) ($r->Id ?? 0)] = $r;
      }
    }

    $children = [];
    foreach ($reservas as $r) {
      if (!is_object($r)) continue;
      $pid = (int) ($r->Anidacion_IdReservaContenedora ?? 0);
      $rid = (int) ($r->Id ?? 0);
      if ($pid > 0 && $rid > 0) {
        $children[$pid][] = $r;
      }
    }

    $expediente_pagado = self::expediente_pagado($expediente_id);
    $extras = [];

    if (empty($pqs)) {
      foreach ($reservas as $r) {
        if (!is_object($r)) continue;
        $extras[] = self::normalize_service($r, $expediente_id, false, $expediente_pagado, true);
      }
      return ['package' => null, 'extras' => $extras];
    }

    foreach ($reservas as $r) {
      if (!is_object($r)) continue;
      if ($has_parent($r)) continue;
      $tipo = (string) ($r->TipoReserva ?? '');
      if ($tipo === 'PQ') continue;
      $extras[] = self::normalize_service($r, $expediente_id, false, $expediente_pagado, true);
    }

    $root = reset($pqs);
    $root_id = (int) ($root->Id ?? 0);
    $kids = $children[$root_id] ?? [];
    $allow_voucher_root = empty($kids) && $expediente_pagado;
    $pkg = self::normalize_service($root, $expediente_id, true, $allow_voucher_root, true);
    $pkg['type'] = 'PQ';
    $pkg['services'] = [];
    foreach ($kids as $kid) {
      $pkg['services'][] = self::normalize_service($kid, $expediente_id, true, $expediente_pagado, false);
    }

    return [
      'package' => $pkg,
      'extras' => $extras,
    ];
  }

  /**
   * @return array<string,mixed>
   */
  private static function normalize_service($r, int $expediente_id, bool $included, bool $allow_voucher, bool $show_price = true): array {
    $m = function_exists('casanova_map_wsreserva') ? casanova_map_wsreserva($r) : [];
    $tipo = strtoupper((string) ($m['tipo'] ?? ($r->TipoReserva ?? '')));
    $code = (string) ($m['codigo'] ?? ($r->Codigo ?? ($r->Id ?? '')));
    $title = (string) ($m['descripcion'] ?? ($r->Descripcion ?? 'Servicio'));
    $rid = (int) ($r->Id ?? 0);
    $price = null;

    // Optional WP-side media (hotel/golf images) if there is mapping GIAV→WP.
    $media = function_exists('casanova_portal_resolve_service_media')
      ? casanova_portal_resolve_service_media(
          $tipo,
          (int) ($m['id_proveedor'] ?? ($r->IdProveedor ?? 0)),
          (int) ($m['id_producto'] ?? ($r->IdProducto ?? 0)),
          $title
        )
      : ['image_url' => null, 'permalink' => null, 'source' => null];

    if ($show_price && isset($r->Venta) && $r->Venta !== '') {
      $price = is_numeric($r->Venta) ? (float) $r->Venta : null;
    }

    $dates = function_exists('casanova_fmt_date_range') ? casanova_fmt_date_range($r->FechaDesde ?? null, $r->FechaHasta ?? null) : '';

    $actions = self::build_actions($allow_voucher);
    $voucher_urls = $allow_voucher ? self::voucher_urls($expediente_id, $rid) : ['view' => '', 'pdf' => ''];

    return [
      'id' => $code ?: ('srv-' . $rid),
      'code' => $code,
      'type' => $tipo !== '' ? $tipo : 'OT',
      'title' => $title,
      'date_range' => $dates,
      'price' => $price,
      'included' => $included,
      'media' => $media,
      'actions' => $actions,
      'voucher_urls' => $voucher_urls,
      'detail' => [
        'code' => $code,
        'type' => (string) ($r->TipoReserva ?? ''),
        'dates' => $dates,
        'locator' => (string) ($r->Localizador ?? ''),
        'bonus_text' => trim((string) ($r->TextoBono ?? '')),
      ],
    ];
  }

  /**
   * @return array{detail:bool,voucher:bool,pdf:bool}
   */
  private static function build_actions(bool $allow_voucher): array {
    return [
      'detail' => true,
      'voucher' => $allow_voucher,
      'pdf' => $allow_voucher,
    ];
  }

  private static function voucher_urls(int $expediente_id, int $idReserva): array {
    if ($idReserva <= 0) {
      return ['view' => '', 'pdf' => ''];
    }

    if (function_exists('casanova_portal_voucher_url')) {
      return [
        'view' => casanova_portal_voucher_url($expediente_id, $idReserva, 'view'),
        'pdf' => casanova_portal_voucher_url($expediente_id, $idReserva, 'pdf'),
      ];
    }

    $nonce = wp_create_nonce('casanova_voucher_' . $expediente_id . '_' . $idReserva);
    $base = admin_url('admin-post.php');
    return [
      'view' => add_query_arg([
        'action' => 'casanova_voucher',
        'expediente' => $expediente_id,
        'reserva' => $idReserva,
        '_wpnonce' => $nonce,
      ], $base),
      'pdf' => add_query_arg([
        'action' => 'casanova_voucher_pdf',
        'expediente' => $expediente_id,
        'reserva' => $idReserva,
        '_wpnonce' => $nonce,
      ], $base),
    ];
  }

  private static function expediente_pagado(int $expediente_id): bool {
    if (!function_exists('casanova_calc_pago_expediente') || !function_exists('casanova_giav_reservas_por_expediente')) {
      return false;
    }

    $user_id = get_current_user_id();
    $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
    if (!$idCliente) return false;

    $reservas = casanova_giav_reservas_por_expediente($expediente_id, $idCliente);
    if (!is_array($reservas)) return false;

    $calc = casanova_calc_pago_expediente($expediente_id, $idCliente, $reservas);
    if (is_wp_error($calc)) return false;

    return !empty($calc['expediente_pagado']);
  }

  /**
   * @return array<int,array<string,string>>
   */
  private static function build_passengers(int $expediente_id): array {
    if (!function_exists('casanova_giav_pasajeros_por_expediente')) return [];
    $items = casanova_giav_pasajeros_por_expediente($expediente_id);
    if (!is_array($items)) return [];

    $out = [];
    foreach ($items as $p) {
      if (!is_object($p)) continue;
      $dx = $p->DatosExternos ?? null;
      $name = '';
      if (is_object($dx)) {
        $name = trim((string) ($dx->NombrePasajero ?? $dx->Nombre ?? ''));
      }
      if ($name === '') {
        $name = trim((string) ($p->NombrePasajero ?? $p->Nombre ?? ''));
      }
      if ($name === '') {
        $n = trim((string) ($p->Nombre ?? ''));
        $a = trim((string) ($p->Apellidos ?? ''));
        $name = trim($n . ' ' . $a);
      }
      if ($name === '') {
        $idp = (int) ($p->IdPasajero ?? $p->Id ?? 0);
        $name = $idp > 0 ? sprintf(__('Pasajero #%d', 'casanova-portal'), $idp) : __('Pasajero', 'casanova-portal');
      }
      $type = (string) ($p->TipoPasajero ?? '');
      if ($type === '' && isset($p->Edad)) {
        $type = sprintf(__('%s años', 'casanova-portal'), (string) $p->Edad);
      }
      $doc = (string) ($p->Documento ?? '');

      $out[] = [
        'name' => $name,
        'type' => $type,
        'document' => $doc,
      ];
    }

    return $out;
  }

  /**
   * @param array<int,array<string,mixed>> $services
   * @return array<string,mixed>|null
   */
  private static function build_payments(int $user_id, int $idCliente, int $expediente_id, array $services): ?array {
    $ctx = Casanova_Payments_Service::describe_for_user($user_id, $idCliente, $expediente_id);
    if (is_wp_error($ctx)) {
      return null;
    }

    $actions = is_array($ctx['actions'] ?? null) ? $ctx['actions'] : [];
    $history = is_array($ctx['history'] ?? null) ? $ctx['history'] : [];
    $calc = is_array($ctx['calc'] ?? null) ? $ctx['calc'] : [];
    $is_paid = !empty($ctx['expediente_pagado']) || !empty($calc['expediente_pagado']);
    $mulligans_used = (int)($ctx['mulligans_used'] ?? 0);
    $payment_options = is_array($ctx['payment_options'] ?? null) ? $ctx['payment_options'] : null;

    return [
      'currency' => $ctx['currency'] ?? 'EUR',
      'total' => (float) ($ctx['total'] ?? 0),
      'paid' => (float) ($ctx['paid'] ?? 0),
      'pending' => (float) ($ctx['pending'] ?? 0),
      'history' => $history,
      'is_paid' => $is_paid,
      'mulligans_used' => $mulligans_used,
      'payment_options' => $payment_options,
      'can_pay' => (bool) ($ctx['can_pay'] ?? false),
      'pay_url' => $ctx['pay_url'] ?? null,
      'actions' => [
        'deposit' => $actions['deposit'] ?? ['allowed' => false, 'amount' => 0],
        'balance' => $actions['balance'] ?? ['allowed' => false, 'amount' => 0],
      ],
    ];
  }

  private static function build_bonos(int $idCliente, int $expediente_id): array {
    if ($idCliente <= 0 || $expediente_id <= 0 || !function_exists('casanova_bonos_for_expediente')) {
      return ['available' => false, 'items' => []];
    }

    $raw = casanova_bonos_for_expediente($idCliente, $expediente_id);
    if (!is_array($raw) || empty($raw)) {
      return ['available' => false, 'items' => []];
    }

    $items = [];
    foreach ($raw as $row) {
      if (!is_array($row)) continue;
      $label = trim((string) ($row['label'] ?? ''));
      if ($label === '') {
        $label = __('Bono', 'casanova-portal');
      }
      $range = '';
      if (function_exists('casanova_fmt_date_range')) {
        $range = casanova_fmt_date_range($row['from'] ?? null, $row['to'] ?? null);
      }
      if ($range === '') {
        $start = trim((string) ($row['from'] ?? ''));
        $end = trim((string) ($row['to'] ?? ''));
        $range = trim($start . ' - ' . $end, ' -');
      }

      $from_ts = 0;
      if (!empty($row['from'])) {
        $from_ts = strtotime((string) $row['from']) ?: 0;
      }

      $items[] = [
        'id' => 'exp:' . $expediente_id . '|res:' . (int) ($row['id_reserva'] ?? 0),
        'label' => $label,
        'date_range' => $range,
        'from' => $row['from'] ?? '',
        'to' => $row['to'] ?? '',
        'from_ts' => $from_ts,
        'view_url' => (string) ($row['view_url'] ?? ''),
        'pdf_url' => (string) ($row['pdf_url'] ?? ''),
        'downloadable' => !empty($row['view_url'] ?? '') || !empty($row['pdf_url'] ?? ''),
      ];
    }

    usort($items, function($a, $b) {
      return ($a['from_ts'] ?? 0) <=> ($b['from_ts'] ?? 0);
    });

    return [
      'available' => !empty($items),
      'items' => $items,
    ];
  }

  /**
   * @return array<string,mixed>
   */
  private static function build_messages_meta(int $user_id, int $expediente_id): array {
    $unread = function_exists('casanova_messages_new_count_for_expediente')
      ? (int) casanova_messages_new_count_for_expediente($user_id, $expediente_id, 30)
      : 0;

    $last = null;
    if (function_exists('casanova_giav_comments_por_expediente')) {
      $comments = casanova_giav_comments_por_expediente($expediente_id, 1, 365);
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
        'package' => null,
        'extras' => [],
        'passengers' => [],
        'payments' => null,
        'invoices' => [],
        'vouchers' => [],
        'messages_meta' => ['unread_count' => 0, 'last_message_at' => null],
      ];
    }

    if (!isset($data['status'])) $data['status'] = 'mock';
    if (!isset($data['giav'])) $data['giav'] = ['ok' => true, 'source' => 'mock', 'error' => null];
    if (!array_key_exists('package', $data)) $data['package'] = null;
    if (!isset($data['extras']) || !is_array($data['extras'])) $data['extras'] = [];
    if (!isset($data['passengers']) || !is_array($data['passengers'])) $data['passengers'] = [];
    if (!isset($data['invoices']) || !is_array($data['invoices'])) $data['invoices'] = [];
    if (!isset($data['vouchers']) || !is_array($data['vouchers'])) $data['vouchers'] = [];
    if (!isset($data['messages_meta']) || !is_array($data['messages_meta'])) $data['messages_meta'] = ['unread_count' => 0, 'last_message_at' => null];

    return $data;
  }
}
