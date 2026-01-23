<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
  register_rest_route('casanova/v1', '/stripe/webhook', [
    'methods'             => WP_REST_Server::CREATABLE,
    'callback'            => 'casanova_handle_stripe_webhook',
    'permission_callback' => '__return_true',
  ]);
});

/**
 * Stripe webhook handler for bank transfers.
 * - Verifies signature
 * - Idempotent by event.id stored in intent payload
 * - On payment_intent.succeeded: records cobro in GIAV using existing helper
 */
function casanova_handle_stripe_webhook(WP_REST_Request $request) {
  casanova_portal_clear_rest_output();

  $payload = (string) $request->get_body();
  $sig = (string) $request->get_header('stripe-signature');
  $secret = function_exists('casanova_stripe_webhook_secret') ? casanova_stripe_webhook_secret() : '';

  if (!function_exists('casanova_stripe_verify_webhook_signature') || !casanova_stripe_verify_webhook_signature($payload, $sig, $secret)) {
    return new WP_REST_Response(['ok' => false, 'code' => 'invalid_signature'], 400);
  }

  $event = json_decode($payload, true);
  if (!is_array($event) || empty($event['type'])) {
    return new WP_REST_Response(['ok' => false, 'code' => 'invalid_payload'], 400);
  }

  $event_id = (string)($event['id'] ?? '');
  $type = (string)$event['type'];

  if ($type !== 'payment_intent.succeeded') {
    return new WP_REST_Response(['ok' => true, 'status' => 'ignored'], 200);
  }

  $pi = $event['data']['object'] ?? null;
  if (!is_array($pi)) {
    return new WP_REST_Response(['ok' => false, 'code' => 'no_object'], 400);
  }

  $meta = is_array($pi['metadata'] ?? null) ? $pi['metadata'] : [];
  $token = isset($meta['casanova_token']) ? (string)$meta['casanova_token'] : '';
  if ($token === '' || !function_exists('casanova_payment_intent_get_by_token')) {
    return new WP_REST_Response(['ok' => false, 'code' => 'no_token'], 400);
  }

  $intent = casanova_payment_intent_get_by_token($token);
  if (!$intent || !is_object($intent)) {
    return new WP_REST_Response(['ok' => false, 'code' => 'intent_not_found'], 404);
  }

  // Idempotency: check last processed Stripe event
  $payload_arr = [];
  if (!empty($intent->payload)) {
    $payload_arr = json_decode((string)$intent->payload, true);
    if (!is_array($payload_arr)) $payload_arr = [];
  }
  $last = (string)($payload_arr['stripe_event_last_id'] ?? '');
  if ($event_id !== '' && $last === $event_id) {
    return new WP_REST_Response(['ok' => true, 'status' => 'duplicate'], 200);
  }

  $payload_arr['stripe_event_last_id'] = $event_id;
  $payload_arr['stripe_payment_intent_status'] = (string)($pi['status'] ?? '');
  if (function_exists('casanova_payment_intent_update')) {
    casanova_payment_intent_update((int)$intent->id, ['payload' => $payload_arr, 'status' => 'paid']);
  }

  // Record cobro in GIAV (existing idempotent helper)
  if (function_exists('casanova_payments_try_giav_cobro')) {
    casanova_payments_try_giav_cobro((int)$intent->id);
  }

  return new WP_REST_Response(['ok' => true], 200);
}
