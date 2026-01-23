<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('casanova/v1', '/payments/methods', [
        'methods' => 'GET',
        'callback' => 'casanova_get_available_payment_methods',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);
});

function casanova_get_available_payment_methods(WP_REST_Request $request) {
    $methods = [];

    // Card (Redsys) always available if existing logic allows payments
    $methods[] = [
        'id' => 'card',
        'label' => __('Tarjeta', 'casanova-portal'),
        'provider' => 'redsys'
    ];

    // Stripe bank transfer availability
    if (get_option('casanova_stripe_secret_key')) {
        $methods[] = [
            'id' => 'bank_transfer',
            'label' => __('Transferencia bancaria', 'casanova-portal'),
            'provider' => 'stripe'
        ];
    }

    return [
        'methods' => $methods
    ];
}
