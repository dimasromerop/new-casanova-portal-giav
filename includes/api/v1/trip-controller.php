<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Trip/Expediente
 * GET /wp-json/casanova/v1/trip/{id}
 */
class Casanova_Trip_Controller {

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/trip/(?P<id>\\d+)', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'id'   => ['type' => 'integer', 'required' => true],
        'mock' => ['type' => 'integer', 'required' => false],
      ],
    ]);
  }

  public static function permissions_check(): bool {
    return is_user_logged_in();
  }

  public static function handle(WP_REST_Request $request) {
    try {
      $user_id = get_current_user_id();
      $id = (int) $request->get_param('id');
      $data = Casanova_Trip_Service::get_trip_for_user($user_id, $id, $request);
      return rest_ensure_response($data);
    } catch (Exception $e) {
      return new WP_REST_Response([
        'status' => 'degraded',
        'giav'   => ['ok' => false, 'source' => 'live', 'error' => $e->getMessage()],
        'trip'   => null,
        'services' => [],
        'payments' => null,
        'invoices' => [],
        'vouchers' => [],
        'messages_meta' => ['unread_count' => 0, 'last_message_at' => null],
      ], 200);
    }
  }
}
