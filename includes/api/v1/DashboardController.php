<?php

class Casanova_Dashboard_Controller {

    public static function register_routes() {
        register_rest_route('casanova/v1', '/dashboard', [
            'methods'  => 'GET',
            'callback' => [self::class, 'handle'],
            'permission_callback' => [self::class, 'permissions_check'],
        ]);
    }

    public static function permissions_check() {
        return is_user_logged_in();
    }

    public static function handle(WP_REST_Request $request) {
        casanova_portal_clear_rest_output();
        try {
            $user_id = get_current_user_id();

            $dashboard = Casanova_Dashboard_Service::build_for_user($user_id);

            return rest_ensure_response($dashboard->to_array());

        } catch (Exception $e) {
            return new WP_REST_Response([
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
