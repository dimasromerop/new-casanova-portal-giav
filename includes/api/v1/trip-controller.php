<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Trip/Expediente
 * GET /wp-json/casanova/v1/trip/{id}
 */
class Casanova_Trip_Controller {

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/trip', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'id'   => ['type' => 'integer', 'required' => true],
        'mock' => ['type' => 'integer', 'required' => false],
        'refresh' => ['type' => 'integer', 'required' => false],
      ],
    ]);
    register_rest_route('casanova/v1', '/trip/(?P<id>\\d+)', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'id'   => ['type' => 'integer', 'required' => true],
        'mock' => ['type' => 'integer', 'required' => false],
        'refresh' => ['type' => 'integer', 'required' => false],
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
      $id = (int) $request->get_param('id');
      if ($id <= 0) {
        return new WP_REST_Response([
          'status' => 'invalid',
          'message' => __('Expediente inválido', 'casanova-portal'),
        ], 400);
      }
      $refresh = (int) $request->get_param('refresh') === 1;
      if ($refresh && function_exists('casanova_invalidate_customer_cache')) {
        $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
        casanova_invalidate_customer_cache($user_id, $idCliente, $id);
      }
      $data = Casanova_Trip_Service::get_trip_for_user($user_id, $id, $request);
      if (is_wp_error($data)) {
        return $data;
      }
      return rest_ensure_response($data);
    } catch (Exception $e) {
      return new WP_REST_Response([
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
      ], 200);
    }
  }
}
