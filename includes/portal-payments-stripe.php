<?php
if (!defined('ABSPATH')) exit;

/**
 * Stripe Bank Transfer (SEPA) helpers.
 * No SDK required: uses Stripe REST API via wp_remote_request.
 */

function casanova_stripe_secret_key(): string {
  return (string) get_option('casanova_stripe_secret_key', '');
}

function casanova_stripe_webhook_secret(): string {
  return (string) get_option('casanova_stripe_webhook_secret', '');
}

/**
 * Create a Stripe PaymentIntent for Bank Transfer (EU).
 *
 * @return array|WP_Error  Stripe PI object as array on success.
 */
function casanova_stripe_create_bank_transfer_payment_intent(array $args) {
  $secret = trim(casanova_stripe_secret_key());
  if ($secret === '') {
    return new WP_Error('stripe_no_secret', __('Stripe no está configurado.', 'casanova-portal'));
  }

  $amount_cents = (int) ($args['amount_cents'] ?? 0);
  if ($amount_cents <= 0) {
    return new WP_Error('stripe_invalid_amount', __('Importe inválido.', 'casanova-portal'));
  }

  $currency = strtolower(trim((string)($args['currency'] ?? 'eur')));
  if ($currency === '') $currency = 'eur';

  $metadata = is_array($args['metadata'] ?? null) ? $args['metadata'] : [];
  $description = (string)($args['description'] ?? '');

  $body = [
    'amount' => $amount_cents,
    'currency' => $currency,
    'payment_method_types[]' => 'customer_balance',
    'payment_method_options[customer_balance][funding_type]' => 'bank_transfer',
    'payment_method_options[customer_balance][bank_transfer][type]' => 'eu_bank_transfer',
  ];

  if ($description !== '') $body['description'] = $description;

  foreach ($metadata as $k => $v) {
    $k = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$k);
    if ($k === '') continue;
    $body["metadata[$k]"] = (string)$v;
  }

  $resp = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
    'timeout' => 20,
    'headers' => [
      'Authorization' => 'Bearer ' . $secret,
      'Content-Type' => 'application/x-www-form-urlencoded',
    ],
    'body' => $body,
  ]);

  if (is_wp_error($resp)) return $resp;

  $code = (int) wp_remote_retrieve_response_code($resp);
  $raw  = (string) wp_remote_retrieve_body($resp);
  $data = json_decode($raw, true);

  if ($code < 200 || $code >= 300 || !is_array($data)) {
    $msg = is_array($data) && isset($data['error']['message']) ? (string)$data['error']['message'] : $raw;
    return new WP_Error('stripe_api_error', sprintf(__('Stripe error: %s', 'casanova-portal'), $msg));
  }

  return $data;
}

/**
 * Extract bank transfer instructions from a Stripe PaymentIntent.
 */
function casanova_stripe_extract_bank_transfer_instructions(array $pi): array {
  $out = [
    'reference' => '',
    'reference_type' => '',
    'iban' => '',
    'bic' => '',
    'beneficiary' => '',
    'bank_name' => '',
    'raw' => [],
  ];

  $next = $pi['next_action'] ?? null;
  $inst = is_array($next) ? ($next['display_bank_transfer_instructions'] ?? null) : null;
  if (!is_array($inst)) return $out;

  $out['raw'] = $inst;

  // Reference
  if (isset($inst['reference']) && is_array($inst['reference'])) {
    $out['reference_type'] = (string)($inst['reference']['type'] ?? '');
    $out['reference']      = (string)($inst['reference']['value'] ?? '');
  }

  // Financial addresses (IBAN etc.)
  $fa = $inst['financial_addresses'] ?? [];
  if (is_array($fa) && !empty($fa[0]) && is_array($fa[0])) {
    $first = $fa[0];
    $type = (string)($first['type'] ?? '');
    $addr = $type && isset($first[$type]) && is_array($first[$type]) ? $first[$type] : $first;

    $out['iban']        = (string)($addr['iban'] ?? '');
    $out['bic']         = (string)($addr['bic'] ?? '');
    $out['beneficiary'] = (string)($addr['account_holder_name'] ?? ($addr['beneficiary_name'] ?? ''));
    $out['bank_name']   = (string)($addr['bank_name'] ?? '');
  }

  return $out;
}

/**
 * Verify Stripe webhook signature (manual, no SDK).
 */
function casanova_stripe_verify_webhook_signature(string $payload, string $sig_header, string $secret, int $tolerance = 300): bool {
  $secret = trim($secret);
  if ($secret === '' || trim($sig_header) === '') return false;

  $parts = array_map('trim', explode(',', $sig_header));
  $timestamp = 0;
  $v1 = [];
  foreach ($parts as $p) {
    if (strpos($p, 't=') === 0) {
      $timestamp = (int) substr($p, 2);
      continue;
    }
    if (strpos($p, 'v1=') === 0) {
      $v1[] = substr($p, 3);
      continue;
    }
  }
  if ($timestamp <= 0 || empty($v1)) return false;

  // Tolerance check
  if (abs(time() - $timestamp) > $tolerance) return false;

  $signed = $timestamp . '.' . $payload;
  $calc = hash_hmac('sha256', $signed, $secret);

  foreach ($v1 as $sig) {
    if (hash_equals($calc, $sig)) return true;
  }
  return false;
}
