<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Inbox
 * GET /wp-json/casanova/v1/inbox
 */
class Casanova_Inbox_Controller {

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/inbox', [
      'methods'             => WP_REST_Server::READABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions'],
      'args'                => [
        'mock' => [
          'description' => 'Devuelve datos mock (solo admins).',
          'type'        => 'integer',
          'required'    => false,
        ],
      ],
    ]);
  }

  public static function permissions(WP_REST_Request $request): bool {
    return is_user_logged_in();
  }

  public static function handle(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();
    $user_id = get_current_user_id();
    $data = Casanova_Inbox_Service::get_inbox_for_user($user_id, $request);
    return rest_ensure_response($data);
  }
}
