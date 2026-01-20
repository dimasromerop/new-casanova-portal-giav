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
   * Lightweight debug collector for the /trip endpoint.
   * Enabled only when ?debug=1 and the requester can manage options.
   *
   * IMPORTANT: Debug must never break the response contract.
   */
  private static array $debug = [
    'enabled' => false,
    'items' => [],
  ];

  private static function debug_enable(WP_REST_Request $request): void {
    self::$debug['enabled'] = false;
    self::$debug['items'] = [];
  }

  /**
   * @param mixed $value
   */
  private static function debug_add(string $key, ): void {
    return;
  }

  /**
   * @return array<string,mixed>
   */
  public static function get_trip_for_user(int $user_id, int $expediente_id, WP_REST_Request $request): array|WP_Error {

    self::debug_enable($request);
    self::debug_add('expediente_id', $expediente_id);
    self::debug_add('user_id', $user_id);

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
      // Destination/map/weather are optional enrichments. They must never break the existing contract.
      $destination = self::resolve_destination($structure);
      $map = self::build_map_from_structure($structure, $destination);
      $weather = self::build_weather_from_destination($destination);

      self::debug_add('destination', $destination);
      self::debug_add('map', $map);
      self::debug_add('weather_is_null', $weather === null);
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

      $portal_base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');
      $itinerary_pdf_url = add_query_arg([
        'casanova_action' => 'itinerary_pdf',
        'expediente' => $expediente_id,
        '_wpnonce' => wp_create_nonce('casanova_itinerary_' . $expediente_id),
      ], $portal_base);

      return [
        'status' => 'ok',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        // Keep the existing response contract intact.
        'trip'    => $trip,
        'destination' => $destination,
        'map'     => $map,
        'weather' => $weather,
        'package' => $structure['package'],
        'extras' => $structure['extras'],
        'passengers' => $passengers,
        'payments' => $payments,
        'invoices' => $invoices,
        'vouchers' => $bonuses['items'] ?? [],
        'bonuses' => $bonuses,
        'messages_meta' => $messages_meta,
        'itinerary_pdf_url' => $itinerary_pdf_url,
        // Only included for admins when explicitly requested.
      ];

    } catch (Exception $e) {
      self::debug_add('exception', $e->getMessage());
      return [
        'status' => 'degraded',
        'giav'   => ['ok' => false, 'source' => 'live', 'error' => $e->getMessage()],
        'trip'   => null,
        'destination' => null,
        'map'    => null,
        'weather' => null,
        'package' => null,
        'extras' => [],
        'passengers' => [],
        'payments' => null,
        'invoices' => [],
        'vouchers' => [],
        'messages_meta' => ['unread_count' => 0, 'last_message_at' => null],
        'itinerary_pdf_url' => '',
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
      'destination' => null,
      'map'    => null,
      'weather' => null,
      'package' => null,
      'extras' => [],
      'passengers' => [],
      'payments' => null,
      'invoices' => [],
      'vouchers' => [],
      'messages_meta' => ['unread_count' => 0, 'last_message_at' => null],
      'itinerary_pdf_url' => '',
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
   * Build a Google Maps URL for the trip based on hotel services found in the
   * normalized package/extras structure.
   *
   * - If there is 1 hotel: maps/search
   * - If there are 2+ hotels: maps/dir with waypoints
   *
   * @param array<string,mixed> $structure
   * @return array<string,mixed>|null
   */
  private static function build_map_from_structure(array $structure, ?array $destination = null): ?array {
    // Prefer curated coordinates when available.
    if (is_array($destination)) {
      $lat = isset($destination['lat']) ? (float)$destination['lat'] : null;
      $lng = isset($destination['lng']) ? (float)$destination['lng'] : null;
      if ($lat && $lng) {
        $q = $lat . ',' . $lng;
        $place_id = isset($destination['place_id']) ? trim((string)$destination['place_id']) : '';
        $label_for_query = isset($destination['label']) ? trim((string)$destination['label']) : $q;
        $url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q);
        if ($place_id !== '') {
          // When we have a Place ID, pass it to improve accuracy and reduce ambiguity.
          $url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($label_for_query) . '&query_place_id=' . rawurlencode($place_id);
        }

        $out = [
          'type' => 'point',
          'query' => $q,
          'hotels' => [],
          'url' => $url,
        ];
        if ($place_id !== '') {
          $out['place_id'] = $place_id;
        }
        // If there are multiple hotels, also offer an optional route link.
        $titles_for_route = self::extract_hotel_titles_from_structure($structure);
        if (is_array($titles_for_route) && count($titles_for_route) >= 2) {
          $titles_for_route = array_slice($titles_for_route, 0, 8);
          $destination_q = rawurlencode($titles_for_route[0]);
          $wps = array_slice($titles_for_route, 1);
          $wps = array_map(function($t) { return rawurlencode($t); }, $wps);
          $out['route_url'] = 'https://www.google.com/maps/dir/?api=1&destination=' . $destination_q . '&waypoints=' . implode('|', $wps);
          $out['route_hotels'] = $titles_for_route;
        }
        return $out;
      }
    }

    $titles = self::extract_hotel_titles_from_structure($structure);
    if (empty($titles)) return null;

    // Avoid creating absurdly long URLs.
    $titles = array_slice($titles, 0, 8);

    if (count($titles) === 1) {
      $q = $titles[0];
      return [
        'type' => 'single',
        'query' => $q,
        'hotels' => [$q],
        'url' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q),
      ];
    }

    $destination = rawurlencode($titles[0]);
    $wps = array_slice($titles, 1);
    $wps = array_map(function($t) { return rawurlencode($t); }, $wps);
    $waypoints = implode('|', $wps);

    return [
      'type' => 'route',
      'query' => $titles,
      'hotels' => $titles,
      'url' => 'https://www.google.com/maps/dir/?api=1&destination=' . $destination . '&waypoints=' . $waypoints,
    ];
  }

  /**
   * Extract hotel titles from the normalized structure.
   *
   * @param array<string,mixed> $structure
   * @return array<int,string>
   */
  private static function extract_hotel_titles_from_structure(array $structure): array {
    $titles = [];

    $push = function($service) use (&$titles) {
      if (!is_array($service)) return;
      $semantic = (string) ($service['semantic_type'] ?? '');
      if ($semantic !== 'hotel') return;
      $t = trim((string) ($service['title'] ?? ''));
      if ($t === '') return;
      $t = preg_replace('/\s+/', ' ', $t);
      $titles[] = $t;
    };

    $pkg = $structure['package'] ?? null;
    if (is_array($pkg)) {
      $services = $pkg['services'] ?? [];
      if (is_array($services)) {
        foreach ($services as $s) $push($s);
      }
    }

    $extras = $structure['extras'] ?? [];
    if (is_array($extras)) {
      foreach ($extras as $s) $push($s);
    }

    // De-duplicate while preserving order.
    $seen = [];
    $out = [];
    foreach ($titles as $t) {
      $k = function_exists('mb_strtolower') ? mb_strtolower($t) : strtolower($t);
      if (isset($seen[$k])) continue;
      $seen[$k] = true;
      $out[] = $t;
    }
    return $out;
  }

  /**
   * Resolve a reliable destination for the trip.
   *
   * Priority:
   * 1) WP Package (JetEngine relations) -> curated lat/lng
   * 2) GIAV prestatario address (best-effort) -> textual
   * 3) Hotel names -> textual
   *
   * @param array<string,mixed> $structure
   * @return array<string,mixed>|null
   */
  private static function resolve_destination(array $structure): ?array {
    $primary_hotel = self::find_primary_hotel_service($structure);
    if (!$primary_hotel) {
      return null;
    }

    // 1) Try WP Package via JetEngine relations using the mapped WP hotel id.
    $supplier_id = (int)($primary_hotel['supplier_id'] ?? 0);
    $product_id  = (int)($primary_hotel['product_id'] ?? 0);
    $wp_hotel_id = 0;
    if (function_exists('casanova_portal_find_wp_mapping')) {
      $mapping = casanova_portal_find_wp_mapping('HT', $supplier_id, $product_id);
      if (is_array($mapping)) {
        $wp_hotel_id = (int)($mapping['wp_object_id'] ?? 0);
      }
    }

    if ($wp_hotel_id > 0) {
      $pkg = self::find_wp_package_by_hotel_id($wp_hotel_id);
      if ($pkg && isset($pkg['label'])) {
        $dest = [
          'source' => 'package_wp',
          'package_id' => (int)($pkg['package_id'] ?? 0),
          'label' => (string)($pkg['label'] ?? ''),
          'lat' => isset($pkg['lat']) ? (float)$pkg['lat'] : null,
          'lng' => isset($pkg['lng']) ? (float)$pkg['lng'] : null,
          'place_id' => (string)($pkg['place_id'] ?? ''),
        ];
        return self::ensure_destination_coords($dest);
      }
    }

    // 2) GIAV prestatario (best-effort). We only use it if it looks usable.
    $prestatario_address = self::extract_giav_prestatario_address($primary_hotel);
    if ($prestatario_address) {
      $dest = [
        'source' => 'giav_prestatario',
        'label' => $prestatario_address,
        'lat' => null,
        'lng' => null,
      ];
      return self::ensure_destination_coords($dest);
    }

    // 3) Hotel name fallback.
    $title = trim((string)($primary_hotel['title'] ?? ''));
    if ($title !== '') {
      $dest = [
        'source' => 'hotel_name',
        'label' => $title,
        'lat' => null,
        'lng' => null,
      ];
      return self::ensure_destination_coords($dest);
    }

    return null;
  }

  /**
   * @param array<string,mixed> $structure
   * @return array<string,mixed>|null
   */
  private static function find_primary_hotel_service(array $structure): ?array {
    $pkg = $structure['package'] ?? null;
    if (is_array($pkg)) {
      $services = $pkg['services'] ?? [];
      if (is_array($services)) {
        foreach ($services as $s) {
          if (!is_array($s)) continue;
          if ((string)($s['semantic_type'] ?? '') === 'hotel') return $s;
        }
      }
    }
    $extras = $structure['extras'] ?? [];
    if (is_array($extras)) {
      foreach ($extras as $s) {
        if (!is_array($s)) continue;
        if ((string)($s['semantic_type'] ?? '') === 'hotel') return $s;
      }
    }
    return null;
  }

  /**
   * Try to find a WP package (Paquetes Mundo/Espana) associated to a Hotel CCT
   * through JetEngine relations.
   *
   * @return array{package_id:int,label:string,lat:float,lng:float}|null
   */
  private static function find_wp_package_by_hotel_id(int $hotel_wp_id): ?array {
    if ($hotel_wp_id <= 0) return null;
    global $wpdb;
    if (!$wpdb) return null;

    // JetEngine stores each relation in its own table jet_rel_<REL_ID>.
    // We have multiple relations (Paquetes Mundo -> Hoteles, Paquetes Espana -> Hoteles).
    // We must look up the *parent_object_id* (package post ID) by *child_object_id* (hotel CCT ID)
    // in the correct table.
    $rel_ids = [78, 52];

    $package_id = 0;
    foreach ($rel_ids as $rel_id) {
      $rel_id = (int)$rel_id;
      $table = $wpdb->prefix . 'jet_rel_' . $rel_id;

      $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
      if ($exists !== $table) continue;

      // Use rel_id filter for safety, and pick the newest match.
      $pid = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT parent_object_id FROM `{$table}` WHERE rel_id = %d AND child_object_id = %d ORDER BY _ID DESC LIMIT 1",
        $rel_id,
        $hotel_wp_id
      ));

      if ($pid > 0) { $package_id = $pid; break; }
    }

    if ($package_id <= 0) return null;

    $loc = self::read_package_location_meta($package_id);
    if (!$loc) return null;

    $out = [
      'package_id' => $package_id,
      'label' => (string)($loc['label'] ?? ''),
    ];
    if (isset($loc['lat']) && isset($loc['lng'])) {
      $out['lat'] = (float)$loc['lat'];
      $out['lng'] = (float)$loc['lng'];
    }
    return $out;
  }

  /**
   * Best-effort reading of the JetEngine "Ubicacion" metabox.
   * We support both structured (array/json with lat/lng/address) and split meta keys.
   *
   * @return array{label?:string,lat?:float,lng?:float}|null
   */
  private static function read_package_location_meta(int $package_id): ?array {
    if ($package_id <= 0) return null;

    // First: try likely meta keys.
    $candidates = [
      'ubicacion',
      'ubicacion_paquete',
      'ubicacion_del_paquete',
      'ubicacion_pkg',
      'location',
      'map',
      'ubicacion_paquete_mapa',
    ];

    foreach ($candidates as $key) {
      $raw = get_post_meta($package_id, $key, true);
      $parsed = self::parse_location_value($raw);
      if ($parsed) return $parsed;

      // Split lat/lng keys.
      $lat = get_post_meta($package_id, $key . '_lat', true);
      $lng = get_post_meta($package_id, $key . '_lng', true);
      if ($lat !== '' && $lng !== '') {
        $label = get_post_meta($package_id, $key, true);
        $label = is_string($label) ? trim($label) : '';
        return [
          'label' => $label,
          'lat' => (float)$lat,
          'lng' => (float)$lng,
        ];
      }
    }

    // Last resort: scan all meta for something that looks like a JetEngine map field.
    $all = get_post_meta($package_id);
    if (is_array($all)) {
      foreach ($all as $k => $vals) {
        if (!is_string($k)) continue;
        if (!preg_match('/ubicaci|location|map/i', $k)) continue;
        $val = is_array($vals) ? ($vals[0] ?? null) : $vals;
        $parsed = self::parse_location_value($val);
        if ($parsed && isset($parsed['lat']) && isset($parsed['lng'])) return $parsed;
      }
    }



    // Last resort: JetEngine map fields may store lat/lng under hashed meta keys like <field_id>_lat / <field_id>_lng.
    // Those keys may not contain "ubicacion" in their name, so we detect any valid pair.
    $all = get_post_meta($package_id);
    if (is_array($all)) {
      foreach ($all as $k => $vals) {
        if (!is_string($k)) continue;
        if (!preg_match('/_lat$/i', $k)) continue;
        $base = preg_replace('/_lat$/i', '', $k);
        $k_lng = $base . '_lng';
        if (!array_key_exists($k_lng, $all)) continue;

        $v_lat = is_array($vals) ? ($vals[0] ?? '') : $vals;
        $v_lng_vals = $all[$k_lng];
        $v_lng = is_array($v_lng_vals) ? ($v_lng_vals[0] ?? '') : $v_lng_vals;

        if ($v_lat === '' || $v_lng === '') continue;
        if (!is_numeric($v_lat) || !is_numeric($v_lng)) continue;

        $lat = (float)$v_lat;
        $lng = (float)$v_lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;

        $label = get_post_meta($package_id, 'ubicacion_paquete', true);
        $label = is_string($label) ? trim($label) : '';
        return [
          'label' => $label,
          'lat' => $lat,
          'lng' => $lng,
        ];
      }
    }

    return null;
  }

  /**
   * @return array{label?:string,lat?:float,lng?:float}|null
   */
  private static function parse_location_value($raw): ?array {
    if (is_array($raw)) {
      $lat = $raw['lat'] ?? ($raw['latitude'] ?? null);
      $lng = $raw['lng'] ?? ($raw['longitude'] ?? null);
      $label = $raw['address'] ?? ($raw['label'] ?? ($raw['value'] ?? ''));
      if ($lat !== null && $lng !== null) {
        return [
          'label' => is_string($label) ? trim($label) : '',
          'lat' => (float)$lat,
          'lng' => (float)$lng,
        ];
      }
      if (is_string($label) && trim($label) !== '') {
        return ['label' => trim($label)];
      }
      return null;
    }

    if (is_string($raw)) {
      $s = trim($raw);
      if ($s === '') return null;

      // JSON string?
      if ($s[0] === '{' || $s[0] === '[') {
        $decoded = json_decode($s, true);
        if (is_array($decoded)) {
          return self::parse_location_value($decoded);
        }
      }

      // Serialized?
      if (preg_match('/^a:\d+:/', $s)) {
        $un = @unserialize($s);
        if (is_array($un)) {
          return self::parse_location_value($un);
        }
      }

      // Plain address string.
      return ['label' => $s];
    }

    return null;
  }

  /**
   * Try to extract a usable address from GIAV prestatario/provider fields if present in
   * the normalized service object.
   *
   * NOTE: This is best-effort. We only accept it when it looks like a real place.
   */
  private static function extract_giav_prestatario_address(array $hotel_service): ?string {
    // Prefer explicit prestatario id if available (WsReserva has IdPrestatario).
    $pid = (int)($hotel_service['prestatario_id'] ?? 0);
    if ($pid <= 0) {
      $details = $hotel_service['details'] ?? null;
      if (is_array($details)) {
        $pid = (int)($details['prestatario_id'] ?? ($details['IdPrestatario'] ?? 0));
      }
    }
    if ($pid <= 0) return null;

    // Cache to avoid repeated SOAP calls.
    $cache_key = 'casanova_prestatario_addr_' . $pid;
    $cached = get_transient($cache_key);
    if (is_string($cached) && $cached !== '') return $cached;

    if (!function_exists('casanova_giav_call')) return null;

    $res = casanova_giav_call('Prestatariol_GET', ['id' => $pid]);
    if (is_wp_error($res)) return null;

    // Response wrapper can vary; handle both direct and wrapped result.
    $obj = null;
    if (is_object($res) && isset($res->Prestatariol_GETResult)) {
      $obj = $res->Prestatariol_GETResult;
    } elseif (is_object($res)) {
      $obj = $res;
    }
    if (!is_object($obj)) return null;

    $parts = [];
    foreach (['Direccion','CodPostal','Poblacion','Provincia','Pais'] as $k) {
      $v = $obj->$k ?? '';
      if (!is_string($v)) continue;
      $v = trim(preg_replace('/\s+/', ' ', $v));
      if ($v === '') continue;
      $parts[] = $v;
    }

    // If Direccion is empty, fall back to Nombre + Poblacion/Pais etc.
    if (!$parts) {
      $nombre = $obj->Nombre ?? '';
      if (is_string($nombre)) {
        $nombre = trim(preg_replace('/\s+/', ' ', $nombre));
      } else {
        $nombre = '';
      }
      if ($nombre !== '') $parts[] = $nombre;
    }

    $addr = trim(implode(', ', $parts));
    if ($addr === '' || strlen($addr) < 8) return null;

    set_transient($cache_key, $addr, 7 * DAY_IN_SECONDS);
    return $addr;
  }


  /**
   * Returns the Google API key used for Places + Weather.
   *
   * Supported sources:
   * - define('CASANOVA_GOOGLE_API_KEY', '...')
   * - option: casanova_google_api_key
   */
  private static function get_google_api_key(): string {
    if (defined('CASANOVA_GOOGLE_API_KEY') && is_string(CASANOVA_GOOGLE_API_KEY) && CASANOVA_GOOGLE_API_KEY !== '') {
      return (string) CASANOVA_GOOGLE_API_KEY;
    }
    $opt = get_option('casanova_google_api_key');
    if (is_string($opt)) {
      $opt = trim($opt);
      if ($opt !== '') return $opt;
    }
    return '';
  }

  /**
   * Ensure destination has coordinates (and place_id) using Google Places Text Search.
   * We cache geocode results for up to 365 days (Google recommends refreshing place IDs after ~12 months).
   *
   * @param array<string,mixed>|null $dest
   * @return array<string,mixed>|null
   */
  private static function ensure_destination_coords(?array $dest): ?array {
    if (!is_array($dest)) return null;

    $lat = isset($dest['lat']) ? (float) $dest['lat'] : 0.0;
    $lng = isset($dest['lng']) ? (float) $dest['lng'] : 0.0;
    if ($lat && $lng) return $dest;

    $label = trim((string)($dest['label'] ?? ''));
    if ($label === '') return $dest;

    $resolved = self::google_places_resolve($label);
    if (!$resolved) return $dest;

    if (isset($resolved['lat']) && isset($resolved['lng'])) {
      $dest['lat'] = (float) $resolved['lat'];
      $dest['lng'] = (float) $resolved['lng'];
    }
    if (!empty($resolved['place_id'])) {
      $dest['place_id'] = (string) $resolved['place_id'];
    }
    if (!empty($resolved['label'])) {
      // Prefer Google's formatted address when available (usually more precise than a plain hotel name).
      $dest['label'] = (string) $resolved['label'];
    }

    return $dest;
  }

  /**
   * Resolve a text label into a stable place_id + coordinates using Places API (Text Search, New).
   *
   * @return array{place_id?:string,lat?:float,lng?:float,label?:string}|null
   */
  private static function google_places_resolve(string $label): ?array {
    $label = trim($label);
    if ($label === '') return null;

    self::debug_add('places_label', $label);
    self::debug_add('using_ext_object_cache', function_exists('wp_using_ext_object_cache') ? (wp_using_ext_object_cache() ? 'yes' : 'no') : 'unknown');

    $key = self::get_google_api_key();
    self::debug_add('google_api_key_defined', $key !== '' ? 'yes' : 'no');
    if ($key === '') return null;

    $cache_key = 'casanova_place_' . md5($label);
    $cached = get_transient($cache_key);
    self::debug_add('places_cache_key', $cache_key);
    self::debug_add('places_cache_hit', is_array($cached) ? 'yes' : 'no');
    if (is_array($cached) && isset($cached['lat']) && isset($cached['lng'])) {
      return $cached;
    }

    // Heuristic region hint: if the label mentions Portugal/PT, use PT; otherwise keep ES.
    $region = 'ES';
    if (preg_match('/\b(portugal|lisboa|lisbon|porto|algarve|cascais|sintra)\b/i', $label)) {
      $region = 'PT';
    }
    self::debug_add('places_region', $region);

    $url = 'https://places.googleapis.com/v1/places:searchText';
    $payload = [
      'textQuery' => $label,
      'languageCode' => 'es',
      'regionCode' => $region,
      'pageSize' => 1,
    ];

    self::debug_add('places_endpoint', $url);

    $resp = wp_remote_post($url, [
      'timeout' => 5,
      'redirection' => 2,
      'headers' => [
        'Content-Type' => 'application/json; charset=utf-8',
        'X-Goog-Api-Key' => $key,
        'X-Goog-FieldMask' => 'places.id,places.location,places.formattedAddress',
      ],
      'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($resp)) {
      self::debug_add('places_wp_error', $resp->get_error_message());
      return null;
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    self::debug_add('places_http_code', $code);
    if ($code < 200 || $code >= 300) {
      $body = wp_remote_retrieve_body($resp);
      self::debug_add('places_error_body', $body);
      return null;
    }

    $body = wp_remote_retrieve_body($resp);
    if (!is_string($body) || trim($body) === '') return null;

    self::debug_add('places_body', $body);

    $json = json_decode($body, true);
    if (!is_array($json)) return null;
    $places = $json['places'] ?? null;
    if (!is_array($places) || !isset($places[0]) || !is_array($places[0])) return null;

    $place = $places[0];
    $loc = $place['location'] ?? null;
    if (!is_array($loc)) return null;

    $lat = $loc['latitude'] ?? null;
    $lng = $loc['longitude'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lng)) return null;

    $out = [
      'place_id' => is_string($place['id'] ?? null) ? (string)$place['id'] : '',
      'lat' => (float)$lat,
      'lng' => (float)$lng,
      'label' => is_string($place['formattedAddress'] ?? null) ? (string)$place['formattedAddress'] : $label,
    ];

    set_transient($cache_key, $out, YEAR_IN_SECONDS);
    return $out;
  }

  /**
   * Map Google WeatherCondition.Type to (approx.) Open-Meteo weather codes used by the frontend.
   */
  private static function google_weather_condition_to_open_meteo_code(string $type): int {
    $t = strtoupper(trim($type));
    if ($t === '' || $t === 'TYPE_UNSPECIFIED') return 0;

    // Clear / clouds
    if (in_array($t, ['CLEAR'], true)) return 0;
    if (in_array($t, ['MOSTLY_CLEAR'], true)) return 1;
    if (in_array($t, ['PARTLY_CLOUDY'], true)) return 2;
    if (in_array($t, ['MOSTLY_CLOUDY', 'CLOUDY'], true)) return 3;

    // Wind
    if (in_array($t, ['WINDY'], true)) return 3;
    if (in_array($t, ['WIND_AND_RAIN'], true)) return 61;

    // Rain / showers
    if (in_array($t, ['LIGHT_RAIN_SHOWERS', 'CHANCE_OF_SHOWERS', 'SCATTERED_SHOWERS', 'RAIN_SHOWERS'], true)) return 61;
    if (in_array($t, ['HEAVY_RAIN_SHOWERS'], true)) return 65;
    if (in_array($t, ['LIGHT_TO_MODERATE_RAIN', 'LIGHT_RAIN'], true)) return 61;
    if (in_array($t, ['MODERATE_TO_HEAVY_RAIN', 'RAIN', 'RAIN_PERIODICALLY_HEAVY'], true)) return 63;
    if (in_array($t, ['HEAVY_RAIN'], true)) return 65;

    // Snow
    if (in_array($t, ['LIGHT_SNOW_SHOWERS', 'CHANCE_OF_SNOW_SHOWERS', 'SCATTERED_SNOW_SHOWERS', 'SNOW_SHOWERS'], true)) return 71;
    if (in_array($t, ['HEAVY_SNOW_SHOWERS', 'MODERATE_TO_HEAVY_SNOW', 'HEAVY_SNOW', 'SNOWSTORM'], true)) return 75;
    if (in_array($t, ['LIGHT_TO_MODERATE_SNOW', 'SNOW', 'LIGHT_SNOW'], true)) return 71;

    // Thunderstorm (Google has several, but mapping is "thunder" cluster)
    if (strpos($t, 'THUNDER') !== false) return 95;

    // Fog / haze / etc.
    if (strpos($t, 'FOG') !== false) return 45;

    // Default: cloud-ish
    return 3;
  }

  /**
   * Fetch daily forecast via Google Weather API.
   * Returns the same shape used by the frontend weather component.
   *
   * @return array<string,mixed>|null
   */
  private static function google_weather_daily(float $lat, float $lng, int $days = 5, string $lang = 'es'): ?array {
    $key = self::get_google_api_key();
    if ($key === '') return null;

    self::debug_add('gweather_latlng', $lat . ',' . $lng);

    $days = max(1, min(10, (int)$days));
    $lang = $lang ? $lang : 'es';

    $cache_key = 'casanova_gweather_' . md5($lat . ',' . $lng . ',' . $days . ',' . $lang);
    $cached = get_transient($cache_key);
    self::debug_add('gweather_cache_key', $cache_key);
    self::debug_add('gweather_cache_hit', is_array($cached) ? 'yes' : 'no');
    if (is_array($cached)) return $cached;

    $url = add_query_arg([
      'location.latitude' => $lat,
      'location.longitude' => $lng,
      'days' => $days,
      'pageSize' => $days,
      'unitsSystem' => 'METRIC',
      'languageCode' => $lang,
      'key' => $key,
    ], 'https://weather.googleapis.com/v1/forecast/days:lookup');

    self::debug_add('gweather_endpoint', $url);

    $resp = wp_remote_get($url, [
      'timeout' => 5,
      'redirection' => 2,
    ]);
    if (is_wp_error($resp)) {
      self::debug_add('gweather_wp_error', $resp->get_error_message());
      return null;
    }
    $http = (int) wp_remote_retrieve_response_code($resp);
    self::debug_add('gweather_http_code', $http);
    if ($http < 200 || $http >= 300) {
      self::debug_add('gweather_error_body', wp_remote_retrieve_body($resp));
      return null;
    }

    $body = wp_remote_retrieve_body($resp);
    if (!is_string($body) || trim($body) === '') return null;

    self::debug_add('gweather_body', $body);

    $json = json_decode($body, true);
    if (!is_array($json)) return null;

    $forecast_days = $json['forecastDays'] ?? null;
    if (!is_array($forecast_days) || !$forecast_days) return null;

    $out_days = [];
    $count = min($days, count($forecast_days));
    for ($i = 0; $i < $count; $i++) {
      $fd = $forecast_days[$i];
      if (!is_array($fd)) continue;

      $d = $fd['displayDate'] ?? null;
      if (is_array($d) && isset($d['year'], $d['month'], $d['day'])) {
        $date = sprintf('%04d-%02d-%02d', (int)$d['year'], (int)$d['month'], (int)$d['day']);
      } else {
        $date = null;
      }

      $min_t = $fd['minTemperature']['degrees'] ?? null;
      $max_t = $fd['maxTemperature']['degrees'] ?? null;
      if (!is_numeric($min_t) || !is_numeric($max_t)) continue;

      $cond_type = '';
      $icon_base_uri = '';
      $day_fc = $fd['daytimeForecast'] ?? null;
      if (is_array($day_fc)) {
        $wc = $day_fc['weatherCondition'] ?? null;
        if (is_array($wc)) {
          if (isset($wc['type']) && is_string($wc['type'])) {
            $cond_type = (string)$wc['type'];
          }
          if (isset($wc['iconBaseUri']) && is_string($wc['iconBaseUri'])) {
            $icon_base_uri = (string)$wc['iconBaseUri'];
          }
        }
      }
      $out_days[] = [
        'date' => $date ?: (string)($i + 1),
        't_min' => (float)$min_t,
        't_max' => (float)$max_t,
        'icon_base_uri' => $icon_base_uri !== '' ? $icon_base_uri : null,
        'code' => self::google_weather_condition_to_open_meteo_code($cond_type),
      ];
    }

    if (!$out_days) return null;

    $out = [
      'provider' => 'google-weather',
      'today' => [
        't_min' => (float) $out_days[0]['t_min'],
        't_max' => (float) $out_days[0]['t_max'],
        'code' => (int) $out_days[0]['code'],
        'icon_base_uri' => isset($out_days[0]['icon_base_uri']) ? $out_days[0]['icon_base_uri'] : null,
      ],
      'daily' => $out_days,
    ];

    // Paid API: cache a bit longer.
    set_transient($cache_key, $out, 6 * HOUR_IN_SECONDS);
    return $out;
  }

  /**
   * @return array<string,mixed>|null
   */
  private static function build_weather_from_destination(?array $destination): ?array {
    if (!is_array($destination)) return null;

    // Ensure we have coordinates when possible.
    $destination = self::ensure_destination_coords($destination);

    $lat = isset($destination['lat']) ? (float)$destination['lat'] : null;
    $lng = isset($destination['lng']) ? (float)$destination['lng'] : null;
    if (!$lat || !$lng) return null;

    // Prefer Google Weather if we have an API key.
    if (self::get_google_api_key() !== '') {
      $g = self::google_weather_daily($lat, $lng, 5, 'es');
      if (is_array($g)) return $g;
    }

    // Fallback: Open-Meteo (free). Kept for resilience.
    $cache_key = 'casanova_weather_' . md5($lat . ',' . $lng);
    $cached = get_transient($cache_key);
    if (is_array($cached)) return $cached;

    $url = add_query_arg([
      'latitude' => $lat,
      'longitude' => $lng,
      'daily' => 'weathercode,temperature_2m_max,temperature_2m_min',
      'timezone' => 'auto',
      'forecast_days' => 5,
    ], 'https://api.open-meteo.com/v1/forecast');

    $resp = wp_remote_get($url, [
      'timeout' => 4,
      'redirection' => 2,
    ]);
    if (is_wp_error($resp)) {
      return null;
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) {
      return null;
    }
    $body = wp_remote_retrieve_body($resp);
    if (!is_string($body) || trim($body) === '') return null;
    $json = json_decode($body, true);
    if (!is_array($json)) return null;
    $daily = $json['daily'] ?? null;
    if (!is_array($daily)) return null;

    $times = $daily['time'] ?? [];
    $tmins = $daily['temperature_2m_min'] ?? [];
    $tmaxs = $daily['temperature_2m_max'] ?? [];
    $wcodes = $daily['weathercode'] ?? [];

    $tmin0 = $tmins[0] ?? null;
    $tmax0 = $tmaxs[0] ?? null;
    $wcode0 = $wcodes[0] ?? null;
    if ($tmin0 === null || $tmax0 === null || $wcode0 === null) return null;

    $days = [];
    $count = min(5, count($times), count($tmins), count($tmaxs), count($wcodes));
    for ($i = 0; $i < $count; $i++) {
      $days[] = [
        'date' => (string) $times[$i],
        't_min' => (float) $tmins[$i],
        't_max' => (float) $tmaxs[$i],
        'code' => (int) $wcodes[$i],
      ];
    }

    $out = [
      'provider' => 'open-meteo',
      'today' => [
        't_min' => (float)$tmin0,
        't_max' => (float)$tmax0,
        'code' => (int)$wcode0,
      ],
      'daily' => $days,
    ];

    set_transient($cache_key, $out, 2 * HOUR_IN_SECONDS);
    return $out;
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
    $supplier_id = (int) ($m['id_proveedor'] ?? ($r->IdProveedor ?? 0));
    $product_id  = (int) ($m['id_producto'] ?? ($r->IdProducto ?? 0));

    $media = function_exists('casanova_portal_resolve_service_media')
      ? casanova_portal_resolve_service_media(
          $tipo,
          $supplier_id,
          $product_id,
          $title
        )
      : ['image_url' => null, 'permalink' => null, 'source' => null];

    if ($show_price && isset($r->Venta) && $r->Venta !== '') {
      $price = is_numeric($r->Venta) ? (float) $r->Venta : null;
    }

    $dates = function_exists('casanova_fmt_date_range') ? casanova_fmt_date_range($r->FechaDesde ?? null, $r->FechaHasta ?? null) : '';
    $date_from = self::normalize_date($r->FechaDesde ?? null);
    $date_to = self::normalize_date($r->FechaHasta ?? null);

    $actions = self::build_actions($allow_voucher);
    $voucher_urls = $allow_voucher ? self::voucher_urls($expediente_id, $rid) : ['view' => '', 'pdf' => ''];

    $semantic_type = self::resolve_semantic_type($tipo, $r);
    $details = self::map_service_details($semantic_type, $r, $m, $expediente_id, $rid);

    $supplier_id = (int) ($m['id_proveedor'] ?? ($r->IdProveedor ?? 0));
    $product_id  = (int) ($m['id_producto'] ?? ($r->IdProducto ?? 0));

    $prestatario_id = (int) ($m['id_prestatario'] ?? ($r->IdPrestatario ?? 0));

    return [
      'id' => $code ?: ('srv-' . $rid),
      'code' => $code,
      'type' => $tipo !== '' ? $tipo : 'OT',
      'giav_type' => $tipo !== '' ? $tipo : 'OT',
      'semantic_type' => $semantic_type,
      'title' => $title,
      // Optional GIAV identifiers (used for destination resolution, mapping, debugging).
      'supplier_id' => $supplier_id > 0 ? $supplier_id : null,
      'product_id'  => $product_id > 0 ? $product_id : null,
      'prestatario_id' => $prestatario_id > 0 ? $prestatario_id : null,
      'date_from' => $date_from,
      'date_to' => $date_to,
      'date_range' => $dates,
      'details' => $details,
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
        'details' => $details,
      ],
    ];
  }

  private static function resolve_semantic_type(string $giav_type, $r): string {
    $giav_type = strtoupper(trim($giav_type));
    if (function_exists('casanova_is_golf_service') && casanova_is_golf_service($giav_type, $r)) {
      return 'golf';
    }
    return match ($giav_type) {
      'HT' => 'hotel',
      'AV' => 'flight',
      default => 'other',
    };
  }

  /**
   * @param array<string,mixed> $mapped
   * @return array<string,mixed>
   */
  private static function map_service_details(string $semantic_type, $r, array $mapped, int $expediente_id, int $id_reserva): array {
    return match ($semantic_type) {
      'hotel' => self::map_hotel_service($r, $expediente_id, $id_reserva),
      'golf' => self::map_golf_service($r, $mapped),
      'flight' => self::map_flight_service($r, $mapped),
      default => [],
    };
  }

  /**
   * @return array<string,string>
   */
  private static function map_hotel_service($r, int $expediente_id, int $id_reserva): array {
    $dx = is_object($r) ? ($r->DatosExternos ?? null) : null;
    $rooms = function_exists('casanova_reserva_room_types_text') ? casanova_reserva_room_types_text($r) : '';
    if ($rooms === '') {
      $rooms = self::pick_first([
        self::read_prop($r, ['Habitaciones', 'TipoHabitacion', 'TiposHabitacion', 'Distribucion', 'Distribución']),
        self::read_prop($dx, ['Habitaciones', 'TipoHabitacion', 'TiposHabitacion', 'Distribucion', 'Distribución']),
      ]);
    }

    $board = self::pick_first([
      self::read_prop($r, ['Regimen', 'Board', 'Regime']),
      self::read_prop($dx, ['Regimen', 'Board', 'Regime']),
    ]);

    $rooming = self::pick_first([
      self::read_prop($r, ['Rooming', 'TextoRooming', 'RoomingText']),
      self::read_prop($dx, ['Rooming', 'TextoRooming', 'RoomingText']),
    ]);

    // IMPORTANT (GIAV): en muchos casos, la distribución de habitaciones y el texto de rooming
    // NO vienen en la reserva del expediente, sino en PasajeroReserva_SEARCH (misma lógica que los bonos).
    // Reutilizamos esa vía como fallback (con caché por transient en casanova_giav_pasajeros_por_reserva).
    if (($rooms === '' || $rooming === '') && function_exists('casanova_giav_pasajeros_para_bono')) {
      $pasajeros = casanova_giav_pasajeros_para_bono((int)$id_reserva, (int)$expediente_id);
      if (is_array($pasajeros) && !empty($pasajeros)) {
        if ($rooms === '' && function_exists('casanova_reserva_room_types_text')) {
          $parts = [];
          foreach ($pasajeros as $p) {
            if (!is_object($p)) continue;
            // A veces viene en el propio PasajeroReserva, otras en DatosExternos
            $parts[] = casanova_reserva_room_types_text($p);
            $dxp = $p->DatosExternos ?? null;
            if (is_object($dxp)) {
              $parts[] = casanova_reserva_room_types_text($dxp);
            }
          }
          $parts = array_values(array_filter(array_map(function($x) {
            $x = trim((string)$x);
            return $x !== '' ? preg_replace('/\s+/', ' ', $x) : '';
          }, $parts)));
          $parts = array_values(array_unique($parts));
          if (!empty($parts)) {
            // Si hay varios, los concatenamos. Normalmente será uno solo.
            $rooms = implode(', ', $parts);
          }
        }

        if ($rooming === '') {
          foreach ($pasajeros as $p) {
            if (!is_object($p)) continue;
            $dxp = $p->DatosExternos ?? null;
            $rooming = self::pick_first([
              self::read_prop($p, ['Rooming', 'TextoRooming', 'RoomingText']),
              self::read_prop($dxp, ['Rooming', 'TextoRooming', 'RoomingText']),
            ]);
            if ($rooming !== '') break;
          }
        }
      }
    }

    return [
      'rooms' => $rooms,
      'board' => $board,
      'rooming' => $rooming,
    ];
  }

  /**
   * @param array<string,mixed> $mapped
   * @return array<string,mixed>
   */
  private static function map_golf_service($r, array $mapped): array {
    $players = 0;
    if (!empty($mapped['pax'])) {
      $players = (int) $mapped['pax'];
    } elseif (is_object($r) && isset($r->NumPax)) {
      $players = (int) $r->NumPax;
    }

    return [
      'players' => $players,
    ];
  }

  /**
   * @param array<string,mixed> $mapped
   * @return array<string,string>
   */
  private static function map_flight_service($r, array $mapped): array {
    $dx = is_object($r) ? ($r->DatosExternos ?? null) : null;

    $origin = self::pick_first([
      self::read_prop($r, ['Origen', 'CiudadOrigen', 'AeropuertoOrigen', 'AirportOrigen']),
      self::read_prop($dx, ['Origen', 'CiudadOrigen', 'AeropuertoOrigen', 'AirportOrigen']),
    ]);
    $destination = self::pick_first([
      self::read_prop($r, ['Destino', 'CiudadDestino', 'AeropuertoDestino', 'AirportDestino']),
      self::read_prop($dx, ['Destino', 'CiudadDestino', 'AeropuertoDestino', 'AirportDestino']),
    ]);
    $route = '';
    if ($origin !== '' || $destination !== '') {
      $route = trim($origin . ($origin && $destination ? ' → ' : '') . $destination);
    }

    // Importante: el "codigo" interno de la reserva (mapped['codigo']) NO es el código de vuelo.
    $flight_code = self::pick_first([
      self::read_prop($r, ['CodigoVuelo', 'Vuelo', 'FlightNumber', 'NumeroVuelo']),
      self::read_prop($dx, ['CodigoVuelo', 'Vuelo', 'FlightNumber', 'NumeroVuelo']),
    ]);

    $locator = self::pick_first([
      self::read_prop($r, ['Localizador', 'PNR', 'Referencia', 'RecordLocator']),
      self::read_prop($dx, ['Localizador', 'PNR', 'Referencia', 'RecordLocator']),
    ]);

    $departure = self::pick_first([
      self::read_prop($r, ['HoraSalida', 'SalidaHora', 'HoraEmbarque']),
      self::read_prop($dx, ['HoraSalida', 'SalidaHora', 'HoraEmbarque']),
    ]);
    $arrival = self::pick_first([
      self::read_prop($r, ['HoraLlegada', 'LlegadaHora']),
      self::read_prop($dx, ['HoraLlegada', 'LlegadaHora']),
    ]);
    $schedule = '';
    if ($departure !== '' || $arrival !== '') {
      $schedule = trim($departure . ($departure && $arrival ? ' – ' : '') . $arrival);
    }

    $passengers = self::build_passengers_summary($r, $mapped);
    $segments = self::map_flight_segments($r);
    if ($route === '' && !empty($segments)) {
      $route = $segments[0]['route'] ?? '';
    }
    if ($flight_code === '' && !empty($segments)) {
      $flight_code = $segments[0]['code'] ?? '';
    }
    if ($schedule === '' && !empty($segments)) {
      $schedule = $segments[0]['schedule'] ?? '';
    }

    return [
      'route' => $route,
      'flight_code' => $flight_code,
      'schedule' => $schedule,
      'passengers' => $passengers,
      'locator' => $locator,
      'segments' => array_values(array_filter(array_map(function($segment) {
        $code = trim((string) ($segment['code'] ?? ''));
        $route = trim((string) ($segment['route'] ?? ''));
        $schedule = trim((string) ($segment['schedule'] ?? ''));
        $airline = trim((string) ($segment['airline'] ?? ''));
        $parts = array_filter([$airline, $code, $route, $schedule], function($val) {
          return $val !== '';
        });
        // Separador estable para UI.
        return $parts ? implode(' · ', $parts) : '';
      }, $segments))),
    ];
  }

  /**
   * @param array<string,mixed> $mapped
   */
  private static function build_passengers_summary($r, array $mapped): string {
    $pax = (int) ($mapped['pax'] ?? 0);
    $adult = (int) ($mapped['adultos'] ?? 0);
    $child = (int) ($mapped['ninos'] ?? 0);

    if (is_object($r)) {
      if (!$pax && isset($r->NumPax)) $pax = (int) $r->NumPax;
      if (!$adult && isset($r->NumAdultos)) $adult = (int) $r->NumAdultos;
      if (!$child && isset($r->NumNinos)) $child = (int) $r->NumNinos;
    }

    $parts = [];
    if ($adult > 0) {
      $parts[] = sprintf(__('%d adultos', 'casanova-portal'), $adult);
    }
    if ($child > 0) {
      $parts[] = sprintf(__('%d niños', 'casanova-portal'), $child);
    }

    if (!empty($parts)) {
      return implode(' · ', $parts);
    }

    if ($pax > 0) {
      return sprintf(__('%d pasajeros', 'casanova-portal'), $pax);
    }

    return '';
  }

  /**
   * @return array<int,array<string,string>>
   */
  private static function map_flight_segments($r): array {
    if (!is_object($r)) return [];

    $segment_sources = [
      $r->Segmentos ?? null,
      $r->BilleteSegmentos ?? null,
      $r->SegmentosBillete ?? null,
      $r->SegmentInfo ?? null,
    ];

    $dx = $r->DatosExternos ?? null;
    if (is_object($dx)) {
      $segment_sources[] = $dx->Segmentos ?? null;
      $segment_sources[] = $dx->SegmentInfo ?? null;
    }

    $segments = [];
    foreach ($segment_sources as $source) {
      foreach (self::normalize_segment_list($source) as $segment) {
        if (!is_object($segment)) continue;
        $info = is_object($segment->SegmentInfo ?? null) ? $segment->SegmentInfo : $segment;

        $code = self::pick_first([
          self::read_prop($info, ['Codigo', 'codigo', 'Vuelo', 'FlightNumber', 'NumeroVuelo']),
          self::read_prop($segment, ['Codigo', 'codigo', 'Vuelo', 'FlightNumber', 'NumeroVuelo']),
        ]);

        $origin = self::pick_first([
          self::read_prop($info, ['LugarSalida', 'Origen', 'CiudadOrigen', 'AeropuertoOrigen', 'AirportOrigen']),
          self::read_prop($segment, ['LugarSalida', 'Origen', 'CiudadOrigen', 'AeropuertoOrigen', 'AirportOrigen']),
        ]);
        $destination = self::pick_first([
          self::read_prop($info, ['LugarLlegada', 'Destino', 'CiudadDestino', 'AeropuertoDestino', 'AirportDestino']),
          self::read_prop($segment, ['LugarLlegada', 'Destino', 'CiudadDestino', 'AeropuertoDestino', 'AirportDestino']),
        ]);
        $route = trim($origin . ($origin && $destination ? ' → ' : '') . $destination);

        $departure_raw = self::pick_first([
          self::read_prop($info, ['FechaSalida', 'HoraSalida', 'SalidaHora']),
          self::read_prop($segment, ['FechaSalida', 'HoraSalida', 'SalidaHora']),
        ]);
        $arrival_raw = self::pick_first([
          self::read_prop($info, ['FechaLlegada', 'HoraLlegada', 'LlegadaHora']),
          self::read_prop($segment, ['FechaLlegada', 'HoraLlegada', 'LlegadaHora']),
        ]);
        $schedule = self::format_segment_schedule($departure_raw, $arrival_raw);

        $airline = self::pick_first([
          self::read_prop($info, ['Compania', 'Aerolinea', 'Airline']),
          self::read_prop($segment, ['Compania', 'Aerolinea', 'Airline']),
        ]);

        if ($code === '' && $route === '' && $schedule === '') {
          continue;
        }

        $segments[] = [
          'code' => $code,
          'route' => $route,
          'schedule' => $schedule,
          'airline' => $airline,
        ];
      }
    }

    // Si la reserva no trae segmentos, los sacamos de BilleteSegmento_SEARCH.
    if (empty($segments) && function_exists('casanova_giav_billetes_por_reserva') && function_exists('casanova_giav_billete_segmentos_por_billetes')) {
      $idReserva = (int) ($r->Id ?? $r->ID ?? 0);
      if ($idReserva > 0) {
        $billetes = casanova_giav_billetes_por_reserva($idReserva, 50, 0);
        if (!is_wp_error($billetes) && is_array($billetes) && !empty($billetes)) {
          $ids = [];
          foreach ($billetes as $b) {
            if (!is_object($b)) continue;
            $idB = (int) ($b->Id ?? $b->ID ?? 0);
            if ($idB > 0) $ids[] = $idB;
          }

          if (!empty($ids)) {
            $segObjs = casanova_giav_billete_segmentos_por_billetes(array_values(array_unique($ids)), 100, 0);
            if (!is_wp_error($segObjs) && is_array($segObjs)) {
              foreach ($segObjs as $ws) {
                if (!is_object($ws)) continue;
                $info = is_object($ws->SegmentInfo ?? null) ? $ws->SegmentInfo : null;
                if (!$info) continue;

                $code = self::read_prop($info, ['Codigo']);
                $origin = self::read_prop($info, ['LugarSalida']);
                $destination = self::read_prop($info, ['LugarLlegada']);
                $route = trim($origin . ($origin && $destination ? ' → ' : '') . $destination);

                $schedule = self::format_segment_schedule(
                  self::read_prop($info, ['FechaSalida']),
                  self::read_prop($info, ['FechaLlegada'])
                );

                $airline = self::read_prop($info, ['Compania']);

                if ($code === '' && $route === '' && $schedule === '') continue;

                $segments[] = [
                  'code' => (string)$code,
                  'route' => (string)$route,
                  'schedule' => (string)$schedule,
                  'airline' => (string)$airline,
                ];
              }
            }
          }
        }
      }
    }

    return $segments;
  }

  /**
   * @return array<int,object>
   */
  private static function normalize_segment_list($source): array {
    if (!$source) return [];
    if (is_object($source)) {
      foreach (['WsBilleteSegmento', 'WsSegmento', 'Segmento', 'Tramo', 'Segment'] as $key) {
        if (isset($source->$key)) {
          $source = $source->$key;
          break;
        }
      }
    }
    if (is_object($source)) {
      return [$source];
    }
    if (is_array($source)) {
      return $source;
    }
    return [];
  }

  /**
   * Formatea un horario de tramo con fecha + hora cuando sea posible.
   *
   * - Si ambas fechas existen y son el mismo día: "16/01/2026 10:30 – 11:45"
   * - Si son días distintos: "16/01/2026 23:10 – 17/01/2026 01:05"
   * - Si solo existe una: "16/01/2026 10:30"
   */
  private static function format_segment_schedule($departure_raw, $arrival_raw): string {
    $dep_raw = trim((string) ($departure_raw ?? ''));
    $arr_raw = trim((string) ($arrival_raw ?? ''));

    $dep_ts = $dep_raw !== '' ? strtotime($dep_raw) : false;
    $arr_ts = $arr_raw !== '' ? strtotime($arr_raw) : false;

    // Fallback: si no se puede parsear, mantenemos lo que venga pero intentamos al menos mostrar hora.
    if ($dep_ts === false && $arr_ts === false) {
      $dep = self::normalize_time($dep_raw);
      $arr = self::normalize_time($arr_raw);
      return trim($dep . ($dep && $arr ? ' – ' : '') . $arr);
    }

    $date_fmt = 'd/m/Y';
    $time_fmt = 'H:i';
    $dt_fmt = $date_fmt . ' ' . $time_fmt;

    $fmt_date = function($ts) use ($date_fmt) {
      if ($ts === false) return '';
      return function_exists('wp_date') ? wp_date($date_fmt, $ts) : date($date_fmt, $ts);
    };
    $fmt_time = function($ts) use ($time_fmt) {
      if ($ts === false) return '';
      return function_exists('wp_date') ? wp_date($time_fmt, $ts) : date($time_fmt, $ts);
    };
    $fmt_dt = function($ts) use ($dt_fmt) {
      if ($ts === false) return '';
      return function_exists('wp_date') ? wp_date($dt_fmt, $ts) : date($dt_fmt, $ts);
    };

    if ($dep_ts !== false && $arr_ts !== false) {
      $dep_date = $fmt_date($dep_ts);
      $arr_date = $fmt_date($arr_ts);
      if ($dep_date !== '' && $dep_date === $arr_date) {
        return trim($dep_date . ' ' . $fmt_time($dep_ts) . ' – ' . $fmt_time($arr_ts));
      }
      return trim($fmt_dt($dep_ts) . ' – ' . $fmt_dt($arr_ts));
    }

    if ($dep_ts !== false) {
      return trim($fmt_dt($dep_ts));
    }
    return trim($fmt_dt($arr_ts));
  }

  private static function normalize_time($value): string {
    if ($value === null || $value === '') return '';
    $ts = strtotime((string) $value);
    if ($ts === false) return trim((string) $value);
    return gmdate('H:i', $ts);
  }

  private static function normalize_date($value): ?string {
    if (empty($value)) return null;
    $ts = strtotime((string) $value);
    if ($ts === false) return null;
    return gmdate('Y-m-d', $ts);
  }

  /**
   * @param array<int,string> $values
   */
  private static function pick_first(array $values): string {
    foreach ($values as $val) {
      $val = trim((string) $val);
      if ($val !== '') return $val;
    }
    return '';
  }

  /**
   * @param array<int,string> $keys
   */
  private static function read_prop($obj, array $keys): string {
    if (!is_object($obj)) return '';
    foreach ($keys as $key) {
      if (isset($obj->$key) && $obj->$key !== '') {
        return trim((string) $obj->$key);
      }
    }
    return '';
  }

  /**
   * @param object|null $reservation
   */
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
        'itinerary_pdf_url' => '',
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
    if (!isset($data['itinerary_pdf_url'])) $data['itinerary_pdf_url'] = '';

    return $data;
  }
}
