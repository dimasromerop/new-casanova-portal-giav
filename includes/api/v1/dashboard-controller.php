<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Dashboard
 * GET /wp-json/casanova/v1/dashboard
 */
class Casanova_Dashboard_Controller {

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/dashboard', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
    ]);
  }

  public static function permissions_check(): bool {
    return is_user_logged_in();
  }

  public static function handle(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();
    try {
      $mock = (int) $request->get_param('mock') === 1;
      $refresh = (int) $request->get_param('refresh') === 1;

      if ($refresh) {
        // Invalida caches sin borrar transients uno a uno
        if (function_exists('casanova_cache_buster_bump')) {
          casanova_cache_buster_bump();
        }
        // Limpia cache específica del dashboard
        $idCliente = (int) get_user_meta(get_current_user_id(), 'casanova_idcliente', true);
        if ($idCliente > 0) {
          delete_transient('casanova_dash_v1_' . $idCliente);
        }
        // Fuerza sync de Mulligans (evita "decoración")
        if (function_exists('casanova_mulligans_sync_user')) {
          casanova_mulligans_sync_user(get_current_user_id(), true);
        }
      }

      if ($mock && current_user_can('manage_options')) {
        $file = CASANOVA_GIAV_PLUGIN_PATH . 'includes/mock/dashboard.json';
        $raw = file_exists($file) ? file_get_contents($file) : '';
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data)) {
          $data = [
            'status' => 'mock',
            'giav'   => ['ok' => true, 'source' => 'mock', 'error' => null],
            'mulligans' => ['points' => 0, 'tier' => '', 'last_sync' => 0],
            'trips' => [],
            'next_trip' => null,
            'payments' => [],
            'messages' => [],
          ];
        }
        return rest_ensure_response($data);
      }

      $user_id = get_current_user_id();
      $dto = Casanova_Dashboard_Service::build_for_user($user_id, $refresh);
      $out = $dto->to_array();
      // Encapsulamos estado para consumers React.
      if (!isset($out['status'])) $out['status'] = 'ok';
      if (!isset($out['giav'])) $out['giav'] = ['ok' => true, 'source' => 'live', 'error' => null];
      return rest_ensure_response($out);
    } catch (Throwable $e) {
      // Degradado: capturamos fatales (TypeError, Error, etc.) para evitar 500.
      return new WP_REST_Response([
        'status' => 'degraded',
        'giav'   => ['ok' => false, 'source' => 'live', 'error' => $e->getMessage()],
        'mulligans' => ['points' => 0, 'tier' => '', 'last_sync' => 0],
        'trips' => [],
        'next_trip' => null,
        'payments' => [],
        'messages' => [],
      ], 200);
    } catch (Exception $e) {
      // Degradado: no devolvemos 500 para no romper UX en frontend.
      return new WP_REST_Response([
        'status' => 'degraded',
        'giav'   => ['ok' => false, 'source' => 'live', 'error' => $e->getMessage()],
        'mulligans' => ['points' => 0, 'tier' => '', 'last_sync' => 0],
        'trips' => [],
        'next_trip' => null,
        'payments' => [],
        'messages' => [],
      ], 200);
    }
  }
}
