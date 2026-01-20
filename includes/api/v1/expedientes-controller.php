<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Expedientes list + año filter.
 * GET /wp-json/casanova/v1/expedientes
 */
class Casanova_Expedientes_Controller {

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/expedientes', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args' => [
        'year' => [
          'type' => 'integer',
          'validate_callback' => fn($val) => is_null($val) || (is_numeric($val) && (int)$val > 0),
        ],
        'periodo' => [
          'type' => 'integer',
          'validate_callback' => fn($val) => is_null($val) || (is_numeric($val) && (int)$val > 0),
        ],
        'page' => [
          'type' => 'integer',
          'default' => 0,
        ],
        'per_page' => [
          'type' => 'integer',
          'default' => 50,
        ],
      ],
    ]);
  }

  public static function permissions_check(WP_REST_Request $request): bool|WP_Error {
    if (!is_user_logged_in()) {
      return new WP_Error('rest_forbidden', __('No autorizado', 'casanova-portal'), ['status' => 401]);
    }
    return true;
  }

  public static function handle(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();
    try {
      $user_id = get_current_user_id();
      $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
      if (!$idCliente) {
        return new WP_Error('rest_forbidden', __('Acceso denegado', 'casanova-portal'), ['status' => 403]);
      }

      $year  = self::extract_year($request);
      $page  = max(0, (int) $request->get_param('page'));
      $perPage = (int) $request->get_param('per_page');
      $perPage = $perPage <= 0 ? 50 : min(100, $perPage);

      $items = casanova_giav_expedientes_por_cliente($idCliente, $perPage, $page, $year);
      if (is_wp_error($items)) {
        return $items;
      }

      $rows = [];
      foreach ($items as $item) {
        if (!is_object($item)) continue;
        $rows[] = self::normalize_expediente($item, $idCliente);
      }

      $years = self::build_years_list();
      if ($year && !in_array($year, $years, true)) {
        array_unshift($years, $year);
      }

      return rest_ensure_response([
        'status' => 'ok',
        'items' => $rows,
        'years' => $years,
        'selected_year' => $year ?? (int)date('Y'),
      ]);
    } catch (Throwable $e) {
      return new WP_REST_Response([
        'status' => 'degraded',
        'items' => [],
        'years' => self::build_years_list(),
        'selected_year' => (int)date('Y'),
        'error' => $e->getMessage(),
      ], 200);
    }
  }

  private static function extract_year(WP_REST_Request $request): ?int {
    $year = (int) $request->get_param('year');
    if ($year <= 0) {
      $year = (int) $request->get_param('periodo');
    }
    return $year > 0 ? $year : null;
  }

  private static function build_years_list(): array {
    $current_year = (int) date('Y');
    $min_year = max(2015, $current_year - 5);
    return array_values(range($current_year + 1, $min_year));
  }

  private static function normalize_expediente($exp, int $idCliente): array {
    $id = (int) ($exp->IdExpediente ?? $exp->Id ?? $exp->IDExpediente ?? 0);
    if (!$id && isset($exp->Codigo)) $id = (int) $exp->Codigo;
    $title = (string) ($exp->Titulo ?? $exp->Nombre ?? 'Expediente');
    $code = (string) ($exp->Codigo ?? '');
    $status = (string) ($exp->Estado ?? $exp->Situacion ?? '');
    if ($status === '') {
      $status = (string) ($exp->Situacion ?? '');
    }

    // Estado configurable GIAV (EntityStages)
    $stage_id = isset($exp->IdEntityStage) ? (int) $exp->IdEntityStage : 0;
    $stage_name = ($stage_id > 0 && function_exists('casanova_giav_entity_stage_name'))
      ? casanova_giav_entity_stage_name('Expediente', $stage_id)
      : null;

    if ($stage_name && $status === '') {
      $status = (string) $stage_name;
    }

    $start_raw = $exp->FechaInicio ?? $exp->FechaDesde ?? $exp->Desde ?? null;
    $end_raw   = $exp->FechaFin ?? $exp->FechaHasta ?? $exp->Hasta ?? null;
    $date_start = $start_raw ? gmdate('Y-m-d', strtotime((string) $start_raw)) : null;
    $date_end   = $end_raw ? gmdate('Y-m-d', strtotime((string) $end_raw)) : null;
    $date_range = function_exists('casanova_fmt_date_range')
      ? casanova_fmt_date_range($start_raw, $end_raw)
      : ((string)$date_start . ($date_end ? ' – ' . $date_end : ''));

    $payments = self::describe_payments($idCliente, $id);
    $pending = isset($payments['pending']) ? $payments['pending'] : null;
    $bonuses_available = is_numeric($pending) ? ($pending <= 0.01) : null;

    return [
      'id' => $id,
      'code' => $code,
      'title' => $title,
      'status' => $status,
      'stage' => [
        'id' => $stage_id > 0 ? $stage_id : null,
        'name' => $stage_name,
      ],
      'date_start' => $date_start,
      'date_end' => $date_end,
      'date_range' => $date_range,
      'payments' => $payments,
      'bonuses' => ['available' => $bonuses_available],
      '_raw' => [
        'start' => $start_raw,
        'end' => $end_raw,
      ],
    ];
  }

  private static function describe_payments(int $idCliente, int $idExpediente): array {
    if ($idCliente <= 0 || $idExpediente <= 0) {
      return [];
    }
    if (!function_exists('casanova_giav_reservas_por_expediente') || !function_exists('casanova_calc_pago_expediente')) {
      return [];
    }

    $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
    if (!is_array($reservas)) {
      return [];
    }
    $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
    if (!is_array($calc)) {
      return [];
    }

    $total = isset($calc['total_objetivo']) ? (float) $calc['total_objetivo'] : 0;
    $paid = isset($calc['pagado']) ? (float) $calc['pagado'] : (isset($calc['pagado_real']) ? (float) $calc['pagado_real'] : 0);
    $pending = isset($calc['pendiente_real']) ? (float) $calc['pendiente_real'] : max(0, $total - $paid);
    if ($pending < 0) $pending = 0;
    $is_paid = !empty($calc['expediente_pagado']) || ($pending <= 0.01);

    return [
      'total' => $total,
      'paid' => $paid,
      'pending' => $pending,
      'is_paid' => $is_paid,
      'currency' => 'EUR',
    ];
  }
}
