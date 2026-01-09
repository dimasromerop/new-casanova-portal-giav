<?php
if (!defined('ABSPATH')) exit;

/**
 * Servicio de pagos reutilizable por REST y templates.
 */
class Casanova_Payments_Service {

  /**
   * Describe el estado de pagos de un expediente autorizado.
   *
   * @return array<string,mixed>|WP_Error
   */
  public static function describe_for_user(int $user_id, int $idCliente, int $idExpediente) {
    if ($idCliente <= 0) {
      return new WP_Error('payments_no_client', esc_html__('Tu cuenta no está vinculada a un cliente casanova.', 'casanova-portal'));
    }

    if ($idExpediente <= 0) {
      return new WP_Error('payments_invalid_expediente', esc_html__('Expediente inválido.', 'casanova-portal'));
    }

    if (!function_exists('casanova_user_can_access_expediente')) {
      return new WP_Error('payments_missing_helper', esc_html__('No se puede verificar la propiedad del expediente.', 'casanova-portal'));
    }

    if (!casanova_user_can_access_expediente($user_id, $idExpediente)) {
      return new WP_Error('permissions', esc_html__('No autorizado para este expediente.', 'casanova-portal'));
    }

    if (!function_exists('casanova_giav_reservas_por_expediente')) {
      return new WP_Error('payments_missing_feature', esc_html__('Reservas no disponibles.', 'casanova-portal'));
    }

    $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
    if (is_wp_error($reservas)) {
      return new WP_Error('reservas_error', $reservas->get_error_message());
    }
    if (empty($reservas) || !is_array($reservas)) {
      return new WP_Error('reservas_empty', esc_html__('No se encontraron reservas para este expediente.', 'casanova-portal'));
    }

    if (!function_exists('casanova_calc_pago_expediente')) {
      return new WP_Error('payments_missing_calc', esc_html__('No se puede calcular el estado de pagos.', 'casanova-portal'));
    }

    $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
    if (is_wp_error($calc)) {
      return $calc;
    }

    $total = (float) ($calc['total_objetivo'] ?? 0);
    $paid = (float) ($calc['pagado'] ?? 0);
    $pending = max(0.0, (float) ($calc['pendiente_real'] ?? 0));

    $deposit_allowed = false;
    $deposit_amount = 0.0;
    if ($paid <= 0.01 && function_exists('casanova_payments_is_deposit_allowed')) {
      $deposit_allowed = casanova_payments_is_deposit_allowed($reservas);
    }

    if ($deposit_allowed && function_exists('casanova_payments_calc_deposit_amount')) {
      $deposit_amount = casanova_payments_calc_deposit_amount($pending, $idExpediente);
    }
    $deposit_effective = $deposit_allowed && ($deposit_amount + 0.01 < $pending);

    if ($deposit_amount < 0) $deposit_amount = 0;

    if (!function_exists('casanova_portal_pay_expediente_url')) {
      $pay_url = add_query_arg([
        'action' => 'casanova_pay_expediente',
        'expediente' => $idExpediente,
        '_wpnonce' => wp_create_nonce('casanova_pay_expediente_' . $idExpediente),
      ], admin_url('admin-post.php'));
    } else {
      $pay_url = casanova_portal_pay_expediente_url($idExpediente);
    }

    return [
      'user_id' => $user_id,
      'idCliente' => $idCliente,
      'idExpediente' => $idExpediente,
      'reservas' => $reservas,
      'calc' => is_array($calc) ? $calc : [],
      'total' => $total,
      'paid' => $paid,
      'pending' => $pending,
      'currency' => 'EUR',
      'can_pay' => $pending > 0.01,
      'pay_url' => $pay_url,
      'actions' => [
        'deposit' => [
          'allowed' => (bool) $deposit_effective,
          'amount' => round($deposit_amount, 2),
        ],
        'balance' => [
          'allowed' => $pending > 0.01,
          'amount' => round($pending, 2),
        ],
      ],
    ];
  }

}
