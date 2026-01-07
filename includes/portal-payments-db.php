<?php
if (!defined('ABSPATH')) exit;

function casanova_payments_table(): string {
  global $wpdb;
  return $wpdb->prefix . 'casanova_payment_intents';
}

function casanova_payments_install(): void {
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $table = casanova_payments_table();
  $charset_collate = $wpdb->get_charset_collate();

$sql = "CREATE TABLE $table (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token VARCHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    id_cliente BIGINT UNSIGNED NOT NULL DEFAULT 0,
    id_expediente BIGINT UNSIGNED NOT NULL DEFAULT 0,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    status VARCHAR(32) NOT NULL DEFAULT 'created',
    order_redsys VARCHAR(32) DEFAULT NULL,
    payload LONGTEXT NULL,
    mail_cobro_sent_at DATETIME NULL,
    mail_expediente_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
attempts INT NOT NULL DEFAULT 0,
last_check_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY token (token),
    KEY idx_user (user_id),
    KEY idx_expediente (id_expediente),
    KEY idx_status (status),
    KEY idx_order (order_redsys)
) $charset_collate;";

  dbDelta($sql);
}