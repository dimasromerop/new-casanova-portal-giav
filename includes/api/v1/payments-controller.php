<?php
if (!defined('ABSPATH')) exit;

/**
 * REST Controller: Pagos
 * POST /wp-json/casanova/v1/payments/intent
 */
class Casanova_Payments_Controller {

  private static array $ALLOWED_TYPES = ['deposit', 'balance'];

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/payments/intent', [
      'methods'             => WP_REST_Server::CREATABLE,
      'callback'            => [self::class, 'handle'],
      'permission_callback' => [self::class, 'permissions_check'],
      'args'                => [
        'expediente_id' => [
          'type' => 'integer',
          'required' => true,
        ],
        'type' => [
          'type' => 'string',
          'required' => true,
          'validate_callback' => function ($value) {
            return in_array(strtolower((string)$value), self::$ALLOWED_TYPES, true);
          },
        ],
        'mock' => [
          'type' => 'integer',
          'required' => false,
        ],
      ],
    ]);

// Stripe Bank Transfer (SEPA) - devuelve instrucciones, sin redirección.
register_rest_route('casanova/v1', '/payments/stripe/bank-transfer', [
  'methods'             => WP_REST_Server::CREATABLE,
  'callback'            => [self::class, 'handle_stripe_bank_transfer'],
  'permission_callback' => [self::class, 'permissions_check'],
  'args'                => [
    'expediente_id' => [
      'type' => 'integer',
      'required' => true,
    ],
    'type' => [
      'type' => 'string',
      'required' => true,
      'validate_callback' => function ($value) {
        return in_array(strtolower((string)$value), self::$ALLOWED_TYPES, true);
      },
    ],
  ],
]);
  }

  public static function permissions_check(): bool {
    return is_user_logged_in();
  }

  public static function handle(WP_REST_Request $request) {
    casanova_portal_clear_rest_output();
    $mock = (int) $request->get_param('mock') === 1 && current_user_can('manage_options');
    if ($mock) {
      $mock_url = esc_url_raw(add_query_arg(['payment' => 'intent-mock'], home_url('/portal')));
      return rest_ensure_response([
        'ok' => true,
        'redirect_url' => $mock_url,
      ]);
    }

    $expediente_id = (int) $request->get_param('expediente_id');
    if ($expediente_id <= 0) {
      return self::error_response(
        esc_html__('Expediente inválido.', 'casanova-portal'),
        'invalid_expediente',
        400
      );
    }

    $type = strtolower(trim((string) $request->get_param('type')));
    if (!in_array($type, self::$ALLOWED_TYPES, true)) {
      return self::error_response(
        esc_html__('Tipo de pago inválido.', 'casanova-portal'),
        'invalid_type',
        400
      );
    }

    $user_id = get_current_user_id();
    $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
    if ($idCliente <= 0) {
      return self::error_response(
        esc_html__('Tu cuenta no está vinculada a un cliente.', 'casanova-portal'),
        'no_client',
        403
      );
    }

    $context = Casanova_Payments_Service::describe_for_user($user_id, $idCliente, $expediente_id);
    if (is_wp_error($context)) {
      $status = $context->get_error_code() === 'permissions' ? 403 : 400;
      return new WP_REST_Response([
        'ok' => false,
        'code' => $context->get_error_code() ?: 'payments_error',
        'message' => $context->get_error_message(),
      ], $status);
    }

    $actions = is_array($context['actions'] ?? null) ? $context['actions'] : [];
    $action = $actions[$type] ?? ['allowed' => false];
    if (empty($action['allowed'])) {
      $code = $type === 'deposit' ? 'deposit_not_allowed' : 'balance_not_allowed';
      $message = $type === 'deposit'
        ? esc_html__('El depósito no está disponible para este expediente.', 'casanova-portal')
        : esc_html__('No hay importe pendiente para pagar.', 'casanova-portal');
      return self::error_response($message, $code, 403);
    }

    $pay_url = $context['pay_url'] ?? '';
    if (!$pay_url) {
      return self::error_response(
        esc_html__('No se pudo generar la URL de pago.', 'casanova-portal'),
        'no_redirect',
        500
      );
    }

    $mode = $type === 'deposit' ? 'deposit' : 'full';
    $redirect_url = esc_url_raw(add_query_arg(['mode' => $mode], $pay_url));

    return rest_ensure_response([
      'ok' => true,
      'redirect_url' => $redirect_url,
    ]);
  }


public static function handle_stripe_bank_transfer(WP_REST_Request $request) {
  casanova_portal_clear_rest_output();

  $expediente_id = (int) $request->get_param('expediente_id');
  if ($expediente_id <= 0) {
    return self::error_response(
      esc_html__('Expediente inválido.', 'casanova-portal'),
      'invalid_expediente',
      400
    );
  }

  $type = strtolower(trim((string) $request->get_param('type')));
  if (!in_array($type, self::$ALLOWED_TYPES, true)) {
    return self::error_response(
      esc_html__('Tipo de pago inválido.', 'casanova-portal'),
      'invalid_type',
      400
    );
  }

  $user_id = get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if ($idCliente <= 0) {
    return self::error_response(
      esc_html__('Tu cuenta no está vinculada a un cliente.', 'casanova-portal'),
      'no_client',
      403
    );
  }

  if (!class_exists('Casanova_Payments_Service')) {
    return self::error_response(
      esc_html__('Servicio de pagos no disponible.', 'casanova-portal'),
      'payments_service_missing',
      500
    );
  }

  $context = Casanova_Payments_Service::describe_for_user($user_id, $idCliente, $expediente_id);
  if (is_wp_error($context)) {
    $status = $context->get_error_code() === 'permissions' ? 403 : 400;
    return new WP_REST_Response([
      'ok' => false,
      'code' => $context->get_error_code() ?: 'payments_error',
      'message' => $context->get_error_message(),
    ], $status);
  }

  $actions = is_array($context['actions'] ?? null) ? $context['actions'] : [];
  $action = $actions[$type] ?? ['allowed' => false];
  if (empty($action['allowed'])) {
    $code = $type === 'deposit' ? 'deposit_not_allowed' : 'balance_not_allowed';
    $message = $type === 'deposit'
      ? esc_html__('El depósito no está disponible para este expediente.', 'casanova-portal')
      : esc_html__('No hay importe pendiente para pagar.', 'casanova-portal');
    return self::error_response($message, $code, 403);
  }

  $amount = (float) ($action['amount'] ?? 0);
  if ($amount <= 0.01) {
    return self::error_response(
      esc_html__('Importe inválido.', 'casanova-portal'),
      'invalid_amount',
      400
    );
  }

  if (!function_exists('casanova_payment_intent_create')) {
    return self::error_response(
      esc_html__('No se pudo crear el intent de pago.', 'casanova-portal'),
      'intent_missing',
      500
    );
  }

  $mode = $type === 'deposit' ? 'deposit' : 'balance';
  $token = function_exists('casanova_payments_new_token') ? casanova_payments_new_token() : wp_generate_password(40, false, false);

  // Create internal intent
  $intent = casanova_payment_intent_create([
    'token' => $token,
    'user_id' => $user_id,
    'id_cliente' => $idCliente,
    'id_expediente' => $expediente_id,
    'amount' => $amount,
    'currency' => 'EUR',
    'status' => 'created',
    'payload' => [
      'provider' => 'stripe',
      'method' => 'bank_transfer',
      'mode' => $mode,
    ],
  ]);

  if (is_wp_error($intent)) {
    return self::error_response(
      esc_html__('No se pudo crear el intent.', 'casanova-portal'),
      'intent_create_failed',
      500
    );
  }

  if (!function_exists('casanova_stripe_create_bank_transfer_payment_intent')) {
    return self::error_response(
      esc_html__('Stripe no está disponible.', 'casanova-portal'),
      'stripe_missing',
      500
    );
  }

  $amount_cents = (int) round($amount * 100);
  $pi = casanova_stripe_create_bank_transfer_payment_intent([
    'amount_cents' => $amount_cents,
    'currency' => 'eur',
    'description' => 'Casanova Golf - ' . ($mode === 'deposit' ? 'Depósito' : 'Saldo') . ' expediente #' . $expediente_id,
    'metadata' => [
      'casanova_token' => $token,
      'id_expediente' => (string)$expediente_id,
      'type' => $mode,
    ],
  ]);

  if (is_wp_error($pi)) {
    // Mark internal intent failed
    if (function_exists('casanova_payment_intent_update')) {
      casanova_payment_intent_update((int)$intent->id, [
        'status' => 'failed',
        'payload' => [
          'provider' => 'stripe',
          'method' => 'bank_transfer',
          'mode' => $mode,
          'error' => $pi->get_error_message(),
        ],
      ]);
    }

    return new WP_REST_Response([
      'ok' => false,
      'code' => $pi->get_error_code() ?: 'stripe_error',
      'message' => $pi->get_error_message(),
    ], 502);
  }

  $instructions = function_exists('casanova_stripe_extract_bank_transfer_instructions')
    ? casanova_stripe_extract_bank_transfer_instructions($pi)
    : [];

  // Update intent payload snapshot
  $payload = [
    'provider' => 'stripe',
    'method' => 'bank_transfer',
    'mode' => $mode,
    'stripe_payment_intent_id' => (string)($pi['id'] ?? ''),
    'stripe_payment_intent_status' => (string)($pi['status'] ?? ''),
    'instructions' => $instructions,
  ];

  if (function_exists('casanova_payment_intent_update')) {
    casanova_payment_intent_update((int)$intent->id, ['payload' => $payload, 'status' => 'pending']);
  }

  return rest_ensure_response([
    'ok' => true,
    'token' => $token,
    'intent_id' => (int)$intent->id,
    'provider' => 'stripe',
    'method' => 'bank_transfer',
    'status' => 'pending',
    'amount' => round($amount, 2),
    'currency' => 'EUR',
    'instructions' => [
      'iban' => (string)($instructions['iban'] ?? ''),
      'bic' => (string)($instructions['bic'] ?? ''),
      'beneficiary' => (string)($instructions['beneficiary'] ?? ''),
      'bank_name' => (string)($instructions['bank_name'] ?? ''),
      'reference' => (string)($instructions['reference'] ?? ''),
      'reference_type' => (string)($instructions['reference_type'] ?? ''),
    ],
  ]);
}

  private static function error_response(string $message, string $code, int $status): WP_REST_Response {
    return new WP_REST_Response([
      'ok' => false,
      'code' => $code,
      'message' => $message,
    ], $status);
  }

}
