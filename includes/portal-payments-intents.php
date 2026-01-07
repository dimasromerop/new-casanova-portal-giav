<?php
if (!defined('ABSPATH')) exit;

function casanova_payments_new_token(): string {
  return bin2hex(random_bytes(20)); // 40 chars
}

/**
 * Crea un intent en DB (alineado con la tabla real).
 */
function casanova_payment_intent_create(array $data) {
  global $wpdb;
  $table = casanova_payments_table();
  $now = current_time('mysql');

  $row = [
    'token'        => $data['token'] ?? casanova_payments_new_token(),
    'user_id'      => (int)($data['user_id'] ?? 0),
    'id_cliente'   => (int)($data['id_cliente'] ?? 0),
    'id_expediente'=> (int)($data['id_expediente'] ?? 0),
    'amount'       => (string) number_format((float)($data['amount'] ?? 0), 2, '.', ''), // DECIMAL
    'currency'     => strtoupper((string)($data['currency'] ?? 'EUR')),
    'status'       => (string)($data['status'] ?? 'created'),
    'order_redsys' => !empty($data['order_redsys']) ? (string)$data['order_redsys'] : null,
    'payload'      => !empty($data['payload']) ? (is_string($data['payload']) ? $data['payload'] : wp_json_encode($data['payload'])) : null,
    'mail_cobro_sent_at'      => !empty($data['mail_cobro_sent_at']) ? (string)$data['mail_cobro_sent_at'] : null,
    'mail_expediente_sent_at' => !empty($data['mail_expediente_sent_at']) ? (string)$data['mail_expediente_sent_at'] : null,
    'created_at'   => $now,
    'updated_at'   => $now,
    'attempts'     => (int)($data['attempts'] ?? 0),
'last_check_at'=> !empty($data['last_check_at']) ? (string)$data['last_check_at'] : null,

  ];

  $ok = $wpdb->insert(
    $table,
    $row,
    [
      '%s', // token
      '%d', // user_id
      '%d', // id_cliente
      '%d', // id_expediente
      '%s', // amount (DECIMAL)
      '%s', // currency
      '%s', // status
      '%s', // order_redsys (nullable, WP la mete como '' si null, ok)
      '%s', // payload (nullable)
      '%s', // mail_cobro_sent_at (nullable)
      '%s', // mail_expediente_sent_at (nullable)
      '%s', // created_at
      '%s', // updated_at
    ]
  );

  if (!$ok) {
    return new WP_Error('db_insert_failed', 'No se pudo crear el intent: ' . $wpdb->last_error);
  }

  $row['id'] = (int)$wpdb->insert_id;
  return (object)$row;
}

/**
 * GET por ID (esto es lo que te faltaba y por eso “no hace nada” el mail hook).
 */
function casanova_payment_intent_get(int $id) {
  global $wpdb;
  $table = casanova_payments_table();
  $id = (int)$id;
  if ($id <= 0) return null;

  return $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", $id)
  );
}

function casanova_payment_intent_get_by_token(string $token) {
  global $wpdb;
  $table = casanova_payments_table();
  $token = trim($token);
  if ($token === '') return null;

  return $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE token=%s LIMIT 1", $token)
  );
}

function casanova_payment_intent_get_by_order(string $order_redsys) {
  global $wpdb;
  $table = casanova_payments_table();
  $order_redsys = trim($order_redsys);
  if ($order_redsys === '') return null;

  return $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$table} WHERE order_redsys=%s LIMIT 1", $order_redsys)
  );
}

function casanova_payment_intent_update(int $id, array $fields): bool {
  global $wpdb;
  $table = casanova_payments_table();
  $id = (int)$id;
  if ($id <= 0) return false;

  // Solo permitimos campos reales (evita “inventos” que rompen updates)
    $allowed = [
    'token','user_id','id_cliente','id_expediente','amount','currency','status',
    'order_redsys','payload','mail_cobro_sent_at','mail_expediente_sent_at',
    'created_at','updated_at','attempts','last_check_at'
  ];

  $clean = [];
  foreach ($fields as $k => $v) {
    if (!in_array($k, $allowed, true)) continue;

    if ($k === 'payload') {
      $clean[$k] = is_string($v) ? $v : wp_json_encode($v);
      continue;
    }
    if ($k === 'amount') {
      $clean[$k] = (string) number_format((float)$v, 2, '.', '');
      continue;
    }
    if ($k === 'attempts') {
      $clean[$k] = (int)$v;
      continue;
    }
    if ($k === 'last_check_at') {
      $clean[$k] = (string)$v;
      continue;
    }

    $clean[$k] = $v;
  }

  $clean['updated_at'] = current_time('mysql');

  // Formatos correctos
  $formats = [];
  foreach ($clean as $k => $v) {
    if (in_array($k, ['user_id','id_cliente','id_expediente','attempts'], true)) { $formats[] = '%d'; continue; }
    if ($k === 'amount') { $formats[] = '%s'; continue; } // amount lo guardas ya formateado string
    $formats[] = '%s';
  }

  $ok = $wpdb->update($table, $clean, ['id' => $id], $formats, ['%d']);
  return $ok !== false;


  $ok = $wpdb->update($table, $clean, ['id' => $id], $formats, ['%d']);
  return $ok !== false;
}