<?php
if (!defined('ABSPATH')) exit;

/**
 * Config centralizada Redsys (TEST por defecto).
 * En PROD lo cambiaremos sin tocar el resto del código.
 */
function casanova_redsys_config(): array {
  $cfg = [
    'merchant_code' => '358384055',
    'terminal'      => '001',
    'currency'      => '978',
    'secret_key'    => 'sq7HjrUOBfKmC576ILgskD5srU870gJ7',
    'endpoint'      => 'https://sis-t.redsys.es:25443/sis/realizarPago',
    'sig_version'   => 'HMAC_SHA256_V1',
  ];

  return apply_filters('casanova_redsys_config', $cfg);
}


/**
 * Genera un order compatible con Redsys (4–12 chars, solo dígitos)
 * Formato: YYMMDD + intent_id padded a 6
 */
function casanova_redsys_order_from_intent_id(int $intent_id): string {
  $prefix = gmdate('ymd'); // YYMMDD
  $suffix = str_pad((string)$intent_id, 6, '0', STR_PAD_LEFT);
  return $prefix . $suffix; // 12 chars
}
function casanova_redsys_is_base64(string $s): bool {
  $d = base64_decode($s, true);
  return $d !== false && base64_encode($d) === preg_replace('/\s+/', '', $s);
}

function casanova_redsys_secret_key_raw(string $secret): string {
  // Redsys a veces te lo da ya en base64, a veces “parece base64”.
  // Si valida como base64, lo decodificamos. Si no, lo usamos tal cual.
  return casanova_redsys_is_base64($secret) ? base64_decode($secret, true) : $secret;
}

function casanova_redsys_encrypt_3des(string $order, string $key_raw): string {
  $iv = str_repeat("\0", 8);
  $order_padded = str_pad($order, 16, "\0"); // importante
  $cipher = openssl_encrypt(
    $order_padded,
    'DES-EDE3-CBC',
    $key_raw,
    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
    $iv
  );
  return $cipher === false ? '' : $cipher;
}

function casanova_redsys_signature(string $merchantParamsB64, string $order, string $secret): string {
  $key_raw = casanova_redsys_secret_key_raw($secret);
  $key = casanova_redsys_encrypt_3des($order, $key_raw);
  if ($key === '') return '';
  $mac = hash_hmac('sha256', $merchantParamsB64, $key, true);
  return base64_encode($mac);
}

function casanova_redsys_encode_params(array $params): string {
  $json = json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  return base64_encode($json ?: '{}');
}

function casanova_redsys_decode_params(string $b64): array {
  $json = base64_decode($b64, true);
  if ($json === false) return [];
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : [];
}
function casanova_redsys_verify_signature(string $merchantParamsB64, string $order, string $secret, string $sig_b64): bool {
  $expected = casanova_redsys_signature($merchantParamsB64, $order, $secret);
  return $expected !== '' && hash_equals($expected, $sig_b64);
}