<?php
if (!defined('ABSPATH')) exit;

/**
 * Helpers de seguridad: validar que el expediente pertenece al cliente logueado.
 */
function casanova_portal_require_owner(int $idExpediente, int $idCliente): void {
  if (!is_user_logged_in()) wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);

  if ($idExpediente <= 0 || $idCliente <= 0) wp_die('Parámetros inválidos', 400);

  $exp = casanova_giav_expediente_get($idExpediente);
  if (is_wp_error($exp) || !$exp) wp_die('Expediente no encontrado', 404);

  // Si el ERP devuelve IdCliente, comprobamos. Si no, al menos lo filtraste en Reservas_SEARCH por idsCliente.
  if (isset($exp->IdCliente) && (int)$exp->IdCliente !== $idCliente) {
    wp_die('No tienes acceso a este expediente', 403);
  }
}


/**
 * ============================================================
 * URL FRONTEND para bonos (evita bloqueos a /wp-admin/)
 * ============================================================
 */
function casanova_portal_voucher_url(int $idExpediente, int $idReserva, string $mode = 'view'): string {
  $base = function_exists('casanova_portal_base_url')
    ? (string) casanova_portal_base_url()
    : home_url('/');

  $action = ($mode === 'pdf') ? 'voucher_pdf' : 'voucher';

  return add_query_arg([
    'casanova_action' => $action,
    'expediente'      => (int) $idExpediente,
    'reserva'         => (int) $idReserva,
    '_wpnonce'        => wp_create_nonce('casanova_voucher_' . (int)$idExpediente . '_' . (int)$idReserva),
  ], $base);
}

/**
 * Entrada FRONTEND para ver/descargar bono desde el portal.
 * Reutiliza los handlers existentes (mismo nonce/ownership).
 */
add_action('init', function () {
  $act = isset($_REQUEST['casanova_action']) ? (string)$_REQUEST['casanova_action'] : '';
  if ($act !== 'voucher' && $act !== 'voucher_pdf') return;

  // Normalizamos para reutilizar handlers existentes.
  $_REQUEST['action'] = ($act === 'voucher_pdf') ? 'casanova_voucher_pdf' : 'casanova_voucher';

  if ($act === 'voucher_pdf') {
    if (function_exists('casanova_handle_voucher_pdf')) {
      casanova_handle_voucher_pdf();
    }
  } else {
    if (function_exists('casanova_handle_voucher_html')) {
      casanova_handle_voucher_html();
    }
  }
  // Si no existía el handler, no hacemos nada (evitar fatal).
}, 1);


/**
 * Acción: descargar bono/documento (HTML preview).
 */
function casanova_handle_voucher_html(): void {
  if (!is_user_logged_in()) wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);

  $user_id   = (int) get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);

  // Mejor GET: es un endpoint de enlace, no un formulario
  $idExpediente = (int) ($_GET['expediente'] ?? 0);
  $idReserva    = (int) ($_GET['reserva'] ?? 0);
  $download     = !empty($_GET['download']);

  if (!$idCliente || $idExpediente <= 0 || $idReserva <= 0) {
    wp_die('Parámetros inválidos', 400);
  }

  $nonce = (string) ($_GET['_wpnonce'] ?? '');
  if (!wp_verify_nonce($nonce, 'casanova_voucher_' . $idExpediente . '_' . $idReserva)) {
    wp_die('Nonce inválido', 403);
  }

  // Ownership centralizado
  if (!function_exists('casanova_user_can_access_expediente') || !casanova_user_can_access_expediente($user_id, $idExpediente)) {
    wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);
  }

  // Reservas del expediente (filtrado por cliente)
  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas)) wp_die(esc_html__('No se han podido cargar las reservas', 'casanova-portal'), 500);
  if (!is_array($reservas)) $reservas = [];

  // Validar que la reserva pertenece a este expediente (por Id interno)
  $reserva = null;
  foreach ($reservas as $r) {
    if ((int)($r->Id ?? 0) === $idReserva) { $reserva = $r; break; }
  }
  if (!$reserva) wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);

  // Gate real de pago (a nivel expediente)
  $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($calc)) wp_die(esc_html__('No se pudo calcular el estado de pago', 'casanova-portal'), 500);

  if (empty($calc['expediente_pagado'])) {
    wp_die(esc_html__('Bono no disponible: el viaje tiene pagos pendientes', 'casanova-portal'), 403);
  }

  // Cliente
  $u = wp_get_current_user();
  $cliente_nombre = trim((string)$u->display_name);

  // Proveedor
  $idProveedor = (int)($reserva->IdProveedor ?? 0);
  $prov = casanova_portal_provider_profile($idProveedor);

  // Agencia (ERP)
  $agency = casanova_portal_agency_profile();

  // Refresh pasajeros (ANTES de leer)
  if (current_user_can('manage_options') && !empty($_GET['refresh_pasajeros'])) {
    delete_transient('casanova_pasajeros_reserva_' . $idReserva);
    delete_transient('casanova_pasajeros_expediente_' . $idExpediente);
  }

  // Pasajeros
  $pasajeros = casanova_giav_pasajeros_para_bono($idReserva, $idExpediente);

  // URL del PDF para botón dentro del preview
  $pdf_url = add_query_arg([
    'action' => 'casanova_voucher_pdf',
    'expediente' => $idExpediente,
    'reserva' => $idReserva,
    '_wpnonce' => wp_create_nonce('casanova_voucher_' . $idExpediente . '_' . $idReserva),
  ], admin_url('admin-post.php'));

  // Debug seguro
  if (current_user_can('manage_options')) {
    error_log('[CASANOVA] reserva keys: ' . implode(', ', array_keys(get_object_vars($reserva))));
  }

  // Render HTML (preview)
  $html = casanova_render_voucher_html([
    'agencia' => [
      'nombre'    => $agency['nombre'] ?? '',
      'email'     => $agency['email'] ?? '',
      'tel'       => $agency['tel'] ?? '',
      'web'       => $agency['web'] ?? '',
      'direccion' => $agency['direccion'] ?? '',
    ],
    'cliente' => [
      'nombre' => $cliente_nombre,
    ],
    'proveedor' => [
      'nombre'    => $prov['nombre'] ?? '',
      'tel'       => $prov['tel'] ?? '',
      'email'     => $prov['email'] ?? '',
      'direccion' => $prov['direccion'] ?? '',
    ],
    'reserva' => $reserva,
    'pasajeros' => $pasajeros,
    'pdf_url' => $pdf_url,
    'show_importe' => false,
    'logo' => function_exists('casanova_pdf_logo_data_uri') ? casanova_pdf_logo_data_uri() : '',
    'expediente' => $idExpediente,
  ]);

  header('Content-Type: text/html; charset=utf-8');

  // Si alguien fuerza download=1, descarga el HTML (útil para depurar)
  if ($download) {
    header('Content-Disposition: attachment; filename="bono-' . $idExpediente . '-' . $idReserva . '.html"');
  }

  echo $html;
  exit;
}

add_action('admin_post_casanova_voucher', 'casanova_handle_voucher_html');
add_action('admin_post_nopriv_casanova_voucher', 'casanova_handle_voucher_html');



/**
 * Acción: descargar PDF del bono (Dompdf).
 */
function casanova_handle_voucher_pdf(): void {
  if (!is_user_logged_in()) wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);

  $user_id = (int) get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);

  $idExpediente = (int) ($_REQUEST['expediente'] ?? 0);
  $idReserva    = (int) ($_REQUEST['reserva'] ?? 0);

  if (!$idCliente || $idExpediente <= 0 || $idReserva <= 0) {
    wp_die('Parámetros inválidos', 400);
  }

  $nonce = (string) ($_REQUEST['_wpnonce'] ?? '');
  if (!wp_verify_nonce($nonce, 'casanova_voucher_' . $idExpediente . '_' . $idReserva)) {
    wp_die('Nonce inválido', 403);
  }

  // Ownership centralizado
  if (!function_exists('casanova_user_can_access_expediente') || !casanova_user_can_access_expediente($user_id, $idExpediente)) {
    wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);
  }

  // Reservas del expediente
  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas)) wp_die(esc_html__('No se han podido cargar las reservas', 'casanova-portal'), 500);
  if (!is_array($reservas)) $reservas = [];

  // Reserva pertenece al expediente
  $reserva = null;
  foreach ($reservas as $r) {
    if ((int)($r->Id ?? 0) === $idReserva) { $reserva = $r; break; }
  }
  if (!$reserva) wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);

  // Gate real de pago
  $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($calc)) wp_die(esc_html__('No se pudo calcular el estado de pago', 'casanova-portal'), 500);

  if (empty($calc['expediente_pagado'])) {
    wp_die(esc_html__('Bono no disponible: el viaje tiene pagos pendientes', 'casanova-portal'), 403);
  }

  // Agencia (ERP)
  $agency = casanova_portal_agency_profile();

  // Proveedor
  $idProveedor = (int)($reserva->IdProveedor ?? 0);
  $prov = casanova_portal_provider_profile($idProveedor);

  // Pasajeros
  $pasajeros = casanova_giav_pasajeros_para_bono($idReserva, $idExpediente);

  // Cliente
  $u = wp_get_current_user();
  $cliente_nombre = trim((string)$u->display_name);

  // HTML
  $html = casanova_render_voucher_html([
    'agencia' => $agency,
    'cliente' => ['nombre' => $cliente_nombre],
    'proveedor' => $prov,
    'reserva' => $reserva,
    'pasajeros' => $pasajeros,
    'show_importe' => false,
    'logo' => function_exists('casanova_pdf_logo_data_uri') ? casanova_pdf_logo_data_uri() : '',
    'expediente' => $idExpediente,
  ]);

  // Cargar Dompdf
  $dompdf_autoload = plugin_dir_path(__FILE__) . '../vendor/dompdf/autoload.inc.php';
  if (!file_exists($dompdf_autoload)) {
    wp_die('Falta Dompdf (vendor/dompdf/autoload.inc.php)', 500);
  }
  require_once $dompdf_autoload;

  $options = new \Dompdf\Options();
  $options->set('isRemoteEnabled', true);
  $dompdf = new \Dompdf\Dompdf($options);

  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();

  $filename = 'bono-' . $idExpediente . '-' . $idReserva . '.pdf';
  $dompdf->stream($filename, ['Attachment' => true]);
  exit;
}

add_action('admin_post_casanova_voucher_pdf', 'casanova_handle_voucher_pdf');
add_action('admin_post_nopriv_casanova_voucher_pdf', 'casanova_handle_voucher_pdf');



/**
 * Cron: email de confirmación de cobro
 */
add_action('casanova_job_send_cobro_emails', function(int $idExpediente, int $idCliente) {

  $user = wp_get_current_user();
  $to = $user->user_email;
  if (!is_email($to)) return;

  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas) || empty($reservas)) return;

  $pago = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($pago)) return;

  $cobros = casanova_giav_cobros_por_expediente_all($idExpediente, $idCliente);
  if (is_wp_error($cobros) || empty($cobros)) return;

  usort($cobros, function($a, $b){
    $da = isset($a->FechaCobro) ? strtotime((string)$a->FechaCobro) : 0;
    $db = isset($b->FechaCobro) ? strtotime((string)$b->FechaCobro) : 0;
    return $db <=> $da;
  });

  $last = null;
  foreach ($cobros as $c) {
    if (!casanova_is_reembolso_cobro($c)) { $last = $c; break; }
  }
  if (!$last) return;

  $exp = casanova_giav_expediente_get($idExpediente);
  $codigo = is_object($exp) ? (string)($exp->Codigo ?? '') : '';

  $ctx = [
    'cliente_nombre' => trim((string)$user->display_name),
    'idExpediente' => $idExpediente,
    'codigoExpediente' => $codigo,
    'importe' => casanova_fmt_money(abs((float)($last->Importe ?? 0))),
    'fecha' => !empty($last->FechaCobro) ? date_i18n('d/m/Y H:i', strtotime((string)$last->FechaCobro)) : '',
    'pagado' => casanova_fmt_money((float)$pago['pagado']),
    'pendiente' => casanova_fmt_money((float)$pago['pendiente_real']),
  ];

  $tpl = casanova_tpl_email_confirmacion_cobro($ctx);
  casanova_mail_send($to, $tpl['subject'], $tpl['html']);

}, 10, 2);


/**
 * Cron: email expediente pagado
 */
add_action('casanova_job_send_expediente_paid_email', function(int $idExpediente, int $idCliente) {

  $user = wp_get_current_user();
  $to = $user->user_email;
  if (!is_email($to)) return;

  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas) || empty($reservas)) return;

  $pago = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($pago)) return;

  if (empty($pago['expediente_pagado'])) return;

  $exp = casanova_giav_expediente_get($idExpediente);
  $codigo = is_object($exp) ? (string)($exp->Codigo ?? '') : '';

  $ctx = [
    'cliente_nombre' => trim((string)$user->display_name),
    'idExpediente' => $idExpediente,
    'codigoExpediente' => $codigo,
    'total_objetivo' => casanova_fmt_money((float)$pago['total_objetivo']),
    'pagado' => casanova_fmt_money((float)$pago['pagado']),
    'fecha' => date_i18n('d/m/Y H:i'),
  ];

  $tpl = casanova_tpl_email_expediente_pagado($ctx);
  $ok = casanova_mail_send($to, $tpl['subject'], $tpl['html']);

  if ($ok) {
    $meta_key_paid_sent = 'casanova_paid_email_sent_v1_' . $idExpediente;
    update_user_meta(get_current_user_id(), $meta_key_paid_sent, 1);
  }

}, 10, 2);