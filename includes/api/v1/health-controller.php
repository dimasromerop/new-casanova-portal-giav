<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Health
 * GET /wp-json/casanova/v1/health
 *
 * Endpoint de diagnóstico para comprobar conectividad con GIAV.
 * - Solo admins.
 * - No expone credenciales.
 */
class Casanova_Health_Controller {

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/health', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
    ]);
  }

  public static function permissions_check(): bool {
    return current_user_can('manage_options');
  }

  public static function handle(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();

    $t0 = microtime(true);
    $giav_ok = false;
    $giav_error = null;

    try {
      // Fuerza la creación del SoapClient (carga WSDL). Si falla, lo capturamos.
      if (function_exists('casanova_giav_client')) {
        casanova_giav_client();
      } else {
        throw new Exception('GIAV client no disponible (includes no cargados)');
      }
      $giav_ok = true;
    } catch (Throwable $e) {
      $giav_ok = false;
      $giav_error = $e->getMessage();
    }

    $latency_ms = (int) round((microtime(true) - $t0) * 1000);

    return rest_ensure_response([
      'plugin' => [
        'version' => defined('CASANOVA_GIAV_VERSION') ? CASANOVA_GIAV_VERSION : 'unknown',
      ],
      'giav' => [
        'ok' => $giav_ok,
        'latency_ms' => $latency_ms,
        'error' => $giav_ok ? null : $giav_error,
        'wsdl_defined' => defined('CASANOVA_GIAV_WSDL'),
      ],
      'wp' => [
        'timestamp' => time(),
        'site_url'  => home_url('/'),
      ],
    ]);
  }
}
