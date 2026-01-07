<?php
if (!defined('ABSPATH')) exit;

/**
 * Fecha límite mínima entre reservas (la más restrictiva).
 * Devuelve DateTimeImmutable en timezone WP o null si no hay fecha.
 */
if (!function_exists('casanova_payments_min_fecha_limite')) {
  function casanova_payments_min_fecha_limite(array $reservas): ?DateTimeImmutable {
    $min = null;

    foreach ($reservas as $r) {
      if (!is_object($r)) continue;

	      // Nota: GIAV puede devolver FechaLimite o FechaLimitePago según contexto.
	      $raw = $r->FechaLimite ?? ($r->FechaLimitePago ?? null);
      if (!$raw) continue;

      $s = (string)$raw;
      $s = substr($s, 0, 10);

      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) continue;

      try {
        $dt = new DateTimeImmutable($s, wp_timezone());
      } catch (Throwable $e) {
        continue;
      }

      if ($min === null || $dt < $min) $min = $dt;
    }

    return $min;
  }
}

/**
 * ' . esc_html__('Depósito', 'casanova-portal') . ' permitido si estamos ANTES del final del día de la fecha límite mínima.
 * Si no hay fecha límite, permitimos.
 */
if (!function_exists('casanova_payments_is_deposit_allowed')) {
  function casanova_payments_is_deposit_allowed(array $reservas): bool {
    $min = casanova_payments_min_fecha_limite($reservas);
    if ($min === null) return true;

    try {
      // Ahora mismo, con hora real (WP timezone)
      $now = new DateTimeImmutable(current_time('Y-m-d H:i:s'), wp_timezone());

      // Permitimos todo el día de la fecha límite (hasta justo antes del día siguiente)
      $deadline_end = $min->modify('+1 day');
    } catch (Throwable $e) {
      return true; // fail-open
    }

    return ($now < $deadline_end);
  }
}

if (!function_exists('casanova_payments_calc_deposit_amount')) {
  function casanova_payments_calc_deposit_amount(float $total_pend, int $idExpediente): float {
    $percent = function_exists('casanova_payments_get_deposit_percent')
      ? (float)casanova_payments_get_deposit_percent($idExpediente)
      : 10.0;

    $min = function_exists('casanova_payments_get_deposit_min_amount')
      ? (float)casanova_payments_get_deposit_min_amount()
      : 50.0;

    $amt = round($total_pend * ($percent / 100.0), 2);

    if ($amt < $min) $amt = $min;
    if ($amt > $total_pend) $amt = $total_pend;

    return round($amt, 2);
  }
}

/**
 * ============================================================
 * DEBUG CONTROLADO: comprobar qué callbacks hay en el hook
 * SOLO cuando entramos por admin-post.php?action=casanova_pay_expediente
 * ============================================================
 */
add_action('init', function () {

  $uri = $_SERVER['REQUEST_URI'] ?? '';
  if (stripos($uri, 'admin-post.php') === false) return;
  if (($_REQUEST['action'] ?? '') !== 'casanova_pay_expediente') return;

  global $wp_filter;
  $hook = 'admin_post_casanova_pay_expediente';

  if (empty($wp_filter[$hook])) {
    error_log('[CASANOVA][DEBUG] no wp_filter entry for ' . $hook);
    return;
  }

  $h = $wp_filter[$hook];

  if (!is_object($h) || !property_exists($h, 'callbacks') || !is_array($h->callbacks)) {
    error_log('[CASANOVA][DEBUG] hook has no callbacks array');
    return;
  }

  foreach ($h->callbacks as $priority => $items) {
    foreach ($items as $cb) {
      $fn = $cb['function'] ?? null;

      if (is_string($fn)) {
        $name = $fn;
      } elseif (is_array($fn)) {
        $name = (is_object($fn[0]) ? get_class($fn[0]) : (string)$fn[0]) . '::' . (string)$fn[1];
      } elseif ($fn instanceof Closure) {
        $name = 'Closure';
      } else {
        $name = 'Unknown';
      }

      error_log('[CASANOVA][DEBUG] hook=' . $hook . ' priority=' . $priority . ' cb=' . $name);
    }
  }
}, 2);

/**
 * ============================================================
 * REGISTRO DE HOOKS (PRIORIDAD 1 PARA ENTRAR LOS PRIMEROS)
 * ============================================================
 */
add_action('admin_post_casanova_pay_expediente', 'casanova_handle_pay_expediente', 1);
add_action('admin_post_nopriv_casanova_pay_expediente', 'casanova_handle_pay_expediente', 1);

add_action('plugins_loaded', function () {
  error_log('[CASANOVA][HOOK] admin_post_casanova_pay_expediente has_handlers=' . (string) has_action('admin_post_casanova_pay_expediente'));
}, 20);

/**
 * ============================================================
 * Helper URL admin-post (sin hardcodeos)
 * ============================================================
 */
function casanova_canonical_admin_post_url(array $args): string {
  return add_query_arg($args, admin_url('admin-post.php'));
}

/**
 * ============================================================
 * URL FRONTEND para iniciar el pago (evita bloqueos a /wp-admin/)
 *
 * Importante: muchos setups redirigen /wp-admin/* para roles no admin,
 * lo que rompe admin-post.php. Por eso el flujo de pago se inicia desde
 * el portal (frontend) y reutiliza el mismo handler.
 * ============================================================
 */
function casanova_portal_pay_expediente_url(int $idExpediente, string $mode = ''): string {
  // Base del portal (filtro/constante ya existente en el plugin)
  $base = function_exists('casanova_portal_base_url')
    ? (string) casanova_portal_base_url()
    : home_url('/');

  $args = [
    'casanova_action' => 'pay_expediente',
    'expediente'      => (int) $idExpediente,
    '_wpnonce'        => wp_create_nonce('casanova_pay_expediente_' . (int)$idExpediente),
  ];
  if ($mode === 'deposit' || $mode === 'full') {
    $args['mode'] = $mode;
  }

  return add_query_arg($args, $base);
}

/**
 * Entrada FRONTEND para el flujo de pago.
 *
 * Esto permite que clientes (no admin) paguen aunque exista una redirección
 * global que bloquee /wp-admin/.
 */
add_action('init', function () {
  if (empty($_REQUEST['casanova_action']) || (string)$_REQUEST['casanova_action'] !== 'pay_expediente') return;

  // Normalizamos para reutilizar el handler existente.
  $_REQUEST['action'] = 'casanova_pay_expediente';

  if (!function_exists('casanova_handle_pay_expediente')) {
    wp_die(esc_html__('Sistema de pago no disponible', 'casanova-portal'), 500);
  }

  casanova_handle_pay_expediente();
  exit;
}, 0);

/**
 * ============================================================
 * HANDLER PRINCIPAL DE PAGO
 * ============================================================
 */
function casanova_handle_pay_expediente(): void {

  error_log('[CASANOVA][PAY] reached method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') .
    ' logged_in=' . (is_user_logged_in() ? '1' : '0') .
    ' host=' . ($_SERVER['HTTP_HOST'] ?? '?')
  );

  if (!is_user_logged_in()) {
    error_log('[CASANOVA][PAY] STOP not logged in');
    wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);
  }

  $idExpediente = isset($_REQUEST['expediente']) ? (int)$_REQUEST['expediente'] : 0;
  error_log('[CASANOVA][PAY] A expediente=' . $idExpediente);

  if ($idExpediente <= 0) {
    error_log('[CASANOVA][PAY] STOP expediente invalid');
    wp_die(esc_html__('Expediente inválido', 'casanova-portal'), 400);
  }

  if (empty($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'casanova_pay_expediente_' . $idExpediente)) {
    error_log('[CASANOVA][PAY] STOP nonce invalid expediente=' . $idExpediente);
    wp_die(esc_html__('Nonce inválido', 'casanova-portal'), 403);
  }
  error_log('[CASANOVA][PAY] B nonce ok');

  $user_id   = (int) get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);

  error_log('[CASANOVA][PAY] C user_id=' . $user_id . ' idCliente=' . $idCliente);

  if ($idCliente <= 0) {
    error_log('[CASANOVA][PAY] STOP cliente no vinculado user_id=' . $user_id);
    wp_die(esc_html__('Cliente no vinculado', 'casanova-portal'), 403);
  }

  if (!function_exists('casanova_user_can_access_expediente')) {
    error_log('[CASANOVA][PAY] STOP ownership helper missing');
    wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);
  }

  $can = casanova_user_can_access_expediente($user_id, $idExpediente);
  if (!$can) {
    error_log('[CASANOVA][PAY] STOP ownership failed user_id=' . $user_id . ' expediente=' . $idExpediente . ' idCliente=' . $idCliente);
    wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);
  }
  error_log('[CASANOVA][PAY] D ownership ok');

  // ==========================
  // Reservas GIAV
  // ==========================
  if (!function_exists('casanova_giav_reservas_por_expediente')) {
    error_log('[CASANOVA][PAY] STOP reservas helper missing');
    wp_die(esc_html__('Sistema de reservas no disponible', 'casanova-portal'), 500);
  }

  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas)) {
    error_log('[CASANOVA][PAY] STOP reservas WP_Error: ' . $reservas->get_error_message());
    wp_die(esc_html__('No se pudieron cargar reservas', 'casanova-portal'), 500);
  }
  if (empty($reservas)) {
    error_log('[CASANOVA][PAY] STOP reservas empty');
    wp_die(esc_html__('No se pudieron cargar reservas', 'casanova-portal'), 500);
  }

  error_log('[CASANOVA][PAY] E reservas ok count=' . (is_array($reservas) ? count($reservas) : -1));

  // ==========================
  // Cálculo real pendiente
  // ==========================
  if (!function_exists('casanova_calc_pago_expediente')) {
    error_log('[CASANOVA][PAY] STOP calc helper missing');
    wp_die(esc_html__('Sistema de pagos no disponible', 'casanova-portal'), 500);
  }

  $calc = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($calc)) {
    error_log('[CASANOVA][PAY] STOP calc WP_Error: ' . $calc->get_error_message());
    wp_die(esc_html__('No se pudo calcular el estado de pago', 'casanova-portal'), 500);
  }

  $pagado_now = (float)($calc['pagado'] ?? 0);
  $total_pend = (float)($calc['pendiente_real'] ?? 0);

  error_log('[CASANOVA][PAY] F calc ok pendiente_real=' . $total_pend . ' pagado=' . $pagado_now);

  // Si no hay nada que pagar, fuera.
  if ($total_pend <= 0.01) {
    error_log('[CASANOVA][PAY] redirect: nothing to pay expediente=' . $idExpediente);
    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');
    // ' . esc_html__('Volver', 'casanova-portal') . ' al mismo contexto del portal. Si estamos usando el router (?view=...)
    // forzamos la vista de expedientes para no caer en Principal tras pagar.
    $view = isset($_GET['view']) ? sanitize_key((string)$_GET['view']) : '';
    if ($view === '') $view = 'expedientes';
    wp_safe_redirect(add_query_arg(['view' => $view, 'expediente' => $idExpediente], $base));
    exit;
  }

  // ==========================
  // Mínimo pagos parciales
  // ==========================
  $min_amount = (float) apply_filters(
    'casanova_min_partial_payment_amount',
    function_exists('casanova_payments_get_deposit_min_amount') ? (float)casanova_payments_get_deposit_min_amount() : 50.00,
    $idExpediente,
    $idCliente
  );

  // ==========================
  // GET: mostrar selector
  // ==========================
  $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  if ($method === 'GET') {

    // ' . esc_html__('Depósito', 'casanova-portal') . ' permitido solo si:
    // - no se ha pagado nada aún
    // - fecha límite lo permite
    $deposit_allowed = ($pagado_now <= 0.01) && casanova_payments_is_deposit_allowed($reservas);
    $deposit_amt = $deposit_allowed ? casanova_payments_calc_deposit_amount($total_pend, $idExpediente) : 0.0;

    // ' . esc_html__('Depósito', 'casanova-portal') . ' efectivo: solo si es menor que el total pendiente (para que no sea un "depósito" que en realidad paga todo)
    $deposit_effective = ($deposit_allowed && ($deposit_amt + 0.01 < $total_pend));

    $percent = function_exists('casanova_payments_get_deposit_percent') ? (float)casanova_payments_get_deposit_percent($idExpediente) : 10.0;

    $deadline = casanova_payments_min_fecha_limite($reservas);
    $deadline_txt = $deadline ? $deadline->format('d/m/Y') : '';

    $pref_mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : '';
    if ($pref_mode !== 'deposit' && $pref_mode !== 'full') $pref_mode = '';

    $checked_deposit = ($deposit_effective && ($pref_mode === 'deposit' || $pref_mode === ''));
    $checked_full    = !$checked_deposit;

    // IMPORTANTE: usar endpoint frontend para no depender de /wp-admin/admin-post.php
    $action_url = function_exists('casanova_portal_pay_expediente_url')
      ? casanova_portal_pay_expediente_url($idExpediente)
      : admin_url('admin-post.php');
    $nonce = wp_create_nonce('casanova_pay_expediente_' . $idExpediente);


    // Etiqueta legible del expediente (título/código humano) para evitar confusión
    $exp_meta = function_exists('casanova_portal_expediente_meta') ? casanova_portal_expediente_meta($idCliente, $idExpediente) : ['titulo'=>'','codigo'=>'','label'=>(sprintf(__('Expediente %s', 'casanova-portal'), $idExpediente))];
    $exp_titulo = trim((string)($exp_meta['titulo'] ?? ''));
    $exp_codigo = trim((string)($exp_meta['codigo'] ?? ''));
    $exp_label  = trim((string)($exp_meta['label'] ?? ''));

    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
echo '<div style="max-width:720px;margin:24px auto;padding:18px;border:1px solid #e5e5e5;border-radius:10px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;">';

if (defined('CASANOVA_AGENCY_LOGO_URL') && CASANOVA_AGENCY_LOGO_URL) {
  echo '<div style="margin:0 0 14px;"><img src="' . esc_url(CASANOVA_AGENCY_LOGO_URL) . '" alt="" style="max-height:44px;width:auto;"/></div>';
}

echo '<h2 style="margin:0 0 10px;">' . esc_html__('Pago de expediente', 'casanova-portal') . '</h2>';

$viaje_label = esc_html((string) $exp_label);
$codigo_html = '';
if ($exp_titulo && $exp_codigo) {
  $codigo_html = ' <span style="color:#666;">(' . esc_html((string) $exp_codigo) . ')</span>';
}
echo '<p style="margin:0 0 14px;">' . wp_kses_post(
  sprintf(
    /* translators: %1$s is the trip/expediente label (title), %2$s is the human code in parentheses (may be empty). */
    __('Viaje: <strong>%1$s</strong>%2$s', 'casanova-portal'),
    $viaje_label,
    $codigo_html
  )
) . '</p>';

echo '<div style="background:#fafafa;border:1px solid #eee;border-radius:8px;padding:12px;margin:0 0 14px;">';

$pendiente_html = '<strong>' . esc_html(number_format($total_pend, 2, ',', '.')) . ' €</strong>';
echo '<div>' . wp_kses_post(
  sprintf(
    /* translators: %s is a formatted amount like "<strong>1.234,56 €</strong>" */
    __('Pendiente: %s', 'casanova-portal'),
    $pendiente_html
  )
) . '</div>';

if ($pagado_now > 0.01) {
  echo '<div style="margin-top:6px;">' . esc_html(
    sprintf(
      /* translators: %s is a formatted amount like "1.234,56 €" */
      __('Pagado: %s €', 'casanova-portal'),
      number_format($pagado_now, 2, ',', '.')
    )
  ) . '</div>';
}

if ($deadline_txt) {
  echo '<div style="margin-top:6px;">' . esc_html(
    sprintf(
      /* translators: %s is a date text like "23/12/2025" */
      __('Fecha límite: %s', 'casanova-portal'),
      $deadline_txt
    )
  ) . '</div>';
}

echo '</div>';

echo '<form method="post" action="' . esc_url($action_url) . '">';
echo '<input type="hidden" name="action" value="casanova_pay_expediente" />';
echo '<input type="hidden" name="expediente" value="' . (int) $idExpediente . '" />';
echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';

if ($deposit_effective) {
  echo '<label style="display:block;margin:10px 0;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
  echo '<input type="radio" name="mode" value="deposit" ' . ($checked_deposit ? 'checked' : '') . ' style="margin-right:8px;" />';

  $deposit_amount_html = '<strong>' . esc_html(number_format($deposit_amt, 2, ',', '.')) . ' €</strong>';
  echo wp_kses_post(
    sprintf(
      /* translators: 1: percent (e.g. 30,00), 2: deposit amount HTML (e.g. "<strong>300,00 €</strong>") */
      __('Pagar depósito (%1$s%%): %2$s', 'casanova-portal'),
      esc_html(number_format($percent, 2, ',', '.')),
      $deposit_amount_html
    )
  );

  echo '</label>';
} else {
  echo '<label style="display:block;margin:10px 0;padding:10px;border:1px solid #eee;border-radius:8px;opacity:.55;cursor:not-allowed;">';
  echo '<input type="radio" name="mode" value="deposit" disabled style="margin-right:8px;" />';

  echo esc_html__('Depósito no disponible', 'casanova-portal');

  echo '</label>';
}

echo '<label style="display:block;margin:10px 0;padding:10px;border:1px solid #ddd;border-radius:8px;cursor:pointer;">';
echo '<input type="radio" name="mode" value="full" ' . ($checked_full ? 'checked' : '') . ' style="margin-right:8px;" />';

$amount_html = '<strong>' . esc_html(number_format($total_pend, 2, ',', '.')) . ' €</strong>';
echo wp_kses_post(
  sprintf(
    /* translators: %s is an amount HTML like "<strong>1.234,56 €</strong>" */
    __('Pagar el total pendiente: %s', 'casanova-portal'),
    $amount_html
  )
);

echo '</label>';

echo '<button type="submit" style="margin-top:10px;padding:10px 14px;border:0;border-radius:8px;background:#111;color:#fff;cursor:pointer;">'
  . esc_html__('Continuar al pago', 'casanova-portal')
  . '</button>';

echo '</form>';

echo '<p style="margin:14px 0 0;font-size:12px;color:#777;">'
  . esc_html__('Si tienes dudas, contacta con la agencia antes de pagar.', 'casanova-portal')
  . '</p>';

echo '</div>';
exit;

  }

  // ==========================
  // POST: procesar pago
  // ==========================
  $mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'full';
  if ($mode !== 'deposit' && $mode !== 'full') $mode = 'full';

  // Si ya hay algo pagado, solo permitimos full
  if ($pagado_now > 0.01) {
    $mode = 'full';
  }

  $deposit_allowed = ($pagado_now <= 0.01) && casanova_payments_is_deposit_allowed($reservas);
  $deposit_amt = $deposit_allowed ? casanova_payments_calc_deposit_amount($total_pend, $idExpediente) : 0.0;
  $deposit_effective = ($deposit_allowed && ($deposit_amt + 0.01 < $total_pend));

  if ($mode === 'deposit' && $deposit_effective) {
    $amount_to_pay = $deposit_amt;
  } else {
    $amount_to_pay = $total_pend;
    $mode = 'full';
  }

  $amount_to_pay = round((float)$amount_to_pay, 2);
  $is_full = ($amount_to_pay + 0.01 >= $total_pend);

  error_log('[CASANOVA][PAY] G mode=' . $mode . ' amount_to_pay=' . $amount_to_pay . ' total_pend=' . $total_pend . ' is_full=' . ($is_full ? '1' : '0') . ' min=' . $min_amount);

  if ($amount_to_pay < 0.01) {
    error_log('[CASANOVA][PAY] STOP amount invalid amount_to_pay=' . $amount_to_pay);
    wp_die('' . esc_html__('Importe', 'casanova-portal') . ' inválido', 400);
  }

  if ($amount_to_pay - $total_pend > 0.01) {
    error_log('[CASANOVA][PAY] STOP amount > pending amount_to_pay=' . $amount_to_pay . ' total_pend=' . $total_pend);
    wp_die('' . esc_html__('Importe', 'casanova-portal') . ' superior al pendiente', 400);
  }

  if (!$is_full && $amount_to_pay < $min_amount) {
    error_log('[CASANOVA][PAY] STOP amount < min amount_to_pay=' . $amount_to_pay . ' min=' . $min_amount);
    wp_die('' . esc_html__('Importe', 'casanova-portal') . ' inferior al mínimo permitido', 400);
  }

  // ==========================
  // Crear intent
  // ==========================
  if (!function_exists('casanova_payment_intent_create') || !function_exists('casanova_payment_intent_update')) {
    error_log('[CASANOVA][PAY] STOP intent helpers missing');
    wp_die(esc_html__('Sistema de pago no disponible', 'casanova-portal'), 500);
  }

  error_log('[CASANOVA][PAY] H creating intent amount=' . $amount_to_pay . ' expediente=' . $idExpediente . ' cliente=' . $idCliente);

  $intent = casanova_payment_intent_create([
    'user_id' => $user_id,
    'id_cliente' => $idCliente,
    'id_expediente' => $idExpediente,
    'amount' => $amount_to_pay,
    'currency' => 'EUR',
    'status' => 'created',
    'payload' => [
      'source' => 'portal',
      'requested_amount' => $amount_to_pay,
      'pending_at_create' => round($total_pend, 2),
      'mode' => $mode,
    ],
  ]);

  if (is_wp_error($intent)) {
    error_log('[CASANOVA][PAY] STOP intent WP_Error: ' . $intent->get_error_message());
    wp_die($intent->get_error_message(), 500);
  }

  if (!is_object($intent) || empty($intent->id) || empty($intent->token)) {
    error_log('[CASANOVA][PAY] STOP intent invalid object/id/token');
    wp_die(esc_html__('Intent inválido', 'casanova-portal'), 500);
  }

  error_log('[CASANOVA][PAY] I intent ok id=' . (int)$intent->id . ' token=' . (string)$intent->token);

  // ==========================
  // Order Redsys
  // ==========================
  if (!function_exists('casanova_redsys_order_from_intent_id')) {
    error_log('[CASANOVA][PAY] STOP redsys_order helper missing');
    wp_die(esc_html__('Redsys no disponible', 'casanova-portal'), 500);
  }

  $order = casanova_redsys_order_from_intent_id((int)$intent->id);
  error_log('[CASANOVA][PAY] J order generated=' . $order);

  casanova_payment_intent_update((int)$intent->id, ['order_redsys' => $order]);
  $intent->order_redsys = $order;

  // ==========================
  // Config Redsys
  // ==========================
  if (!function_exists('casanova_redsys_config')) {
    error_log('[CASANOVA][PAY] STOP redsys_config missing');
    wp_die(esc_html__('Config Redsys no disponible', 'casanova-portal'), 500);
  }

  $cfg = casanova_redsys_config();
  if (empty($cfg['endpoint']) || empty($cfg['merchant_code']) || empty($cfg['terminal']) || empty($cfg['currency']) || empty($cfg['secret_key'])) {
    error_log('[CASANOVA][PAY] STOP redsys config incomplete endpoint=' . (string)($cfg['endpoint'] ?? '') .
      ' merchant=' . (string)($cfg['merchant_code'] ?? '') .
      ' terminal=' . (string)($cfg['terminal'] ?? '') .
      ' currency=' . (string)($cfg['currency'] ?? '')
    );
    wp_die(esc_html__('Config Redsys incompleta', 'casanova-portal'), 500);
  }

  // URLs callback
  $url_notify = home_url('/wp-json/casanova/v1/redsys/notify');
  $token = (string)$intent->token;

  $url_ok = add_query_arg([
    'action' => 'casanova_tpv_return',
    'result' => 'ok',
    'token'  => $token,
  ], admin_url('admin-post.php'));

  $url_ko = add_query_arg([
    'action' => 'casanova_tpv_return',
    'result' => 'ko',
    'token'  => $token,
  ], admin_url('admin-post.php'));

  // Amount cents
  $amount_cents = (string)((int) round(((float)$intent->amount) * 100));

  // Merchant Params
  if (!function_exists('casanova_redsys_encode_params') || !function_exists('casanova_redsys_signature')) {
    error_log('[CASANOVA][PAY] STOP redsys helpers missing (encode/signature)');
    wp_die(esc_html__('Redsys no disponible', 'casanova-portal'), 500);
  }

  $merchantParams = [
    'DS_MERCHANT_AMOUNT' => $amount_cents,
    'DS_MERCHANT_ORDER' => (string)$intent->order_redsys,
    'DS_MERCHANT_MERCHANTCODE' => (string)$cfg['merchant_code'],
    'DS_MERCHANT_CURRENCY'     => (string)$cfg['currency'],
    'DS_MERCHANT_TERMINAL'     => (string)$cfg['terminal'],
    'DS_MERCHANT_TRANSACTIONTYPE' => '0',
    'DS_MERCHANT_MERCHANTURL' => $url_notify,
    'DS_MERCHANT_URLOK' => $url_ok,
    'DS_MERCHANT_URLKO' => $url_ko,
    'DS_MERCHANT_MERCHANTDATA' => (string)$intent->token,
  ];

  $mpB64 = casanova_redsys_encode_params($merchantParams);
  $signature = casanova_redsys_signature($mpB64, (string)$intent->order_redsys, (string)$cfg['secret_key']);

  if ($signature === '') {
    error_log('[CASANOVA][PAY] STOP signature empty order=' . (string)$intent->order_redsys);
    wp_die(esc_html__('Firma Redsys inválida', 'casanova-portal'), 500);
  }

  error_log('[CASANOVA][PAY] K redsys prepared endpoint=' . (string)$cfg['endpoint'] . ' amount_cents=' . $amount_cents);

  casanova_payment_intent_update((int)$intent->id, ['status' => 'redirecting']);

  while (ob_get_level()) ob_end_clean();

  echo '<form id="redsys" action="' . esc_url($cfg['endpoint']) . '" method="post">';
    echo '<input type="hidden" name="Ds_SignatureVersion" value="HMAC_SHA256_V1">';
    echo '<input type="hidden" name="Ds_MerchantParameters" value="' . esc_attr($mpB64) . '">';
    echo '<input type="hidden" name="Ds_Signature" value="' . esc_attr($signature) . '">';
  echo '</form>';
  echo '<script>document.getElementById("redsys").submit();</script>';
  exit;
}