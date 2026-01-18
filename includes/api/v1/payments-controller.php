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

  private static function error_response(string $message, string $code, int $status): WP_REST_Response {
    return new WP_REST_Response([
      'ok' => false,
      'code' => $code,
      'message' => $message,
    ], $status);
  }

}
