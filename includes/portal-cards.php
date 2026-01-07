<?php
/**
 * Cards (shortcodes) para dashboards modernos.
 * El objetivo es que Bricks pueda componer vistas con bloques reutilizables.
 */
if (!defined('ABSPATH')) exit;

/**
 * Encuentra el próximo expediente con FechaDesde futura.
 */
function casanova_portal_get_next_trip_expediente(int $idCliente): ?object {
  $items = function_exists('casanova_giav_expedientes_por_cliente')
    ? casanova_giav_expedientes_por_cliente($idCliente, 80, 0)
    : [];
  if (is_wp_error($items) || empty($items)) return null;
  $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
  $today = new DateTimeImmutable('today', $tz);
  $candidates = [];
  foreach ($items as $e) {
    if (!is_object($e)) continue;
    $raw = $e->FechaDesde ?? null;
    if (!$raw) continue;
    $ts = strtotime((string)$raw);
    if (!$ts) continue;
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    if ($d < $today) continue;
    $candidates[] = [$d->getTimestamp(), $e];
  }
  if (empty($candidates)) return null;
  usort($candidates, fn($a, $b) => $a[0] <=> $b[0]);
  return $candidates[0][1] ?? null;
}

/**
 * Devuelve una lista de expedientes futuros (ordenados por FechaDesde asc).
 */
function casanova_portal_get_upcoming_trips(int $idCliente, int $limit = 3): array {
  $limit = max(1, (int)$limit);
  $items = function_exists('casanova_giav_expedientes_por_cliente')
    ? casanova_giav_expedientes_por_cliente($idCliente, 100, 0)
    : [];
  if (is_wp_error($items) || !is_array($items)) return [];
  $today = new DateTimeImmutable('today', wp_timezone());
  $out = [];
  foreach ($items as $exp) {
    if (!is_object($exp)) continue;
    $fd = $exp->FechaDesde ?? $exp->FechaInicio ?? null;
    if (!$fd) continue;
    try {
      $d = new DateTimeImmutable((string)$fd, wp_timezone());
    } catch (Throwable $e) {
      continue;
    }
    if ($d < $today) continue;
    $out[] = ['exp' => $exp, 'date' => $d];
  }
  usort($out, function($a,$b){ return $a['date'] <=> $b['date']; });
  $out = array_slice($out, 0, $limit);
  return array_map(fn($row) => $row['exp'], $out);
}

/**
 * Formatea título/código humano para UI.
 */
function casanova_portal_expediente_label_from_obj($exp): string {
  if (!is_object($exp)) return __('Viaje', 'casanova-portal');
  $titulo = trim((string)($exp->Titulo ?? ''));
  $codigo = trim((string)($exp->Codigo ?? ''));
  if ($titulo && $codigo) return $titulo . ' (' . $codigo . ')';
  if ($titulo) return $titulo;
  if ($codigo) return sprintf(__('Expediente %s', 'casanova-portal'), $codigo);
  $id = (int)($exp->IdExpediente ?? $exp->Id ?? 0);
  return $id ? sprintf(esc_html__('Expediente #%s', 'casanova-portal'), $id) : esc_html__('Viaje', 'casanova-portal');
}

/**
 * Shortcode: card del próximo viaje.
 * Uso: [casanova_proximo_viaje]
 */
add_shortcode('casanova_proximo_viaje', function($atts){
  $atts = shortcode_atts([
    'variant' => 'card', // card|dashboard|hero|compact|legacy
  ], (array)$atts, 'casanova_proximo_viaje');
  
  $variant = sanitize_key((string)($atts['variant'] ?? 'card'));
  if ($variant === 'dashboard') $variant = 'card';
  
  $wrap_class = 'casanova-nexttrip-card casanova-card';
  if ($variant === 'hero') $wrap_class .= ' casanova-nexttrip-card--hero';
  if ($variant === 'compact') $wrap_class .= ' casanova-nexttrip-card--compact';
  
  if (!is_user_logged_in()) return '';
  $user_id   = (int)get_current_user_id();
  $idCliente = (int)get_user_meta($user_id, 'casanova_idcliente', true);
  if (!$idCliente) return '';
  
  $exp = casanova_portal_get_next_trip_expediente($idCliente);
  if (!$exp) {
    return '<div class="' . esc_attr($wrap_class) . '"><div class="casanova-nexttrip-card__empty">' . esc_html__('No tienes viajes próximos.', 'casanova-portal') . '</div></div>';
  }
  
  $idExp = (int)($exp->Id ?? 0);
  $titulo = trim((string)($exp->Titulo ?? ''));
  $codigo = trim((string)($exp->Codigo ?? ''));
  
  $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
  $today = new DateTimeImmutable('today', $tz);
  $desde = !empty($exp->FechaDesde) ? (new DateTimeImmutable((string)$exp->FechaDesde, $tz)) : null;
  $hasta = !empty($exp->FechaHasta) ? (new DateTimeImmutable((string)$exp->FechaHasta, $tz)) : null;
  $days_left = $desde ? (int)$today->diff($desde)->format('%a') : 0;
  $rango = casanova_fmt_date_range($exp->FechaDesde ?? null, $exp->FechaHasta ?? null);
  
  // Totales/pagos (reutiliza lógica existente)
  $reservas = function_exists('casanova_giav_reservas_por_expediente')
    ? casanova_giav_reservas_por_expediente($idExp, $idCliente)
    : [];
  $pago = (!is_wp_error($reservas) && function_exists('casanova_calc_pago_expediente'))
    ? casanova_calc_pago_expediente($idExp, $idCliente, is_array($reservas) ? $reservas : [])
    : [];
  $total = (float)($pago['total_objetivo'] ?? 0);
  $pagado = (float)($pago['pagado'] ?? 0);
  $pendiente = (float)($pago['pendiente_real'] ?? 0);
  
  $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');
  $detail_url = add_query_arg(['view' => 'expedientes', 'expediente' => $idExp], $base);
  
  // CORRECCIÓN: Usamos la URL frontend segura, no admin-post
  $ics_url = '';
  if (function_exists('casanova_portal_ics_url')) {
    $ics_url = casanova_portal_ics_url($idExp);
  } else {
    // Fallback manual por si acaso no se ha cargado el include
    $ics_url = add_query_arg([
      'casanova_action' => 'download_ics',
      'expediente'      => (int) $idExp,
      '_wpnonce'        => wp_create_nonce('casanova_download_ics_' . (int)$idExp),
    ], $base);
  }

  $headline = $titulo !== '' ? $titulo : ($codigo !== '' ? (sprintf(__('Expediente %s', 'casanova-portal'), $codigo)) : (sprintf(__('Expediente %s', 'casanova-portal'), $idExp)));
  
  // Icono SVG
  $icon_cal = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:4px;position:relative;top:-1px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';

  // --- Render Legacy (simple text) ---
  if ($variant === 'legacy') {
    $date_line = $rango ?: '';
    $days_text = sprintf(esc_html__('En %s días', 'casanova-portal'), number_format_i18n($days_left));

    $simple  = '<div class="casanova-nexttrip-legacy">';
    $simple .= '<div class="casanova-nexttrip-legacy__title">' . esc_html($titulo) . '</div>';
    $simple .= '<div class="casanova-nexttrip-legacy__meta">' . esc_html($date_line) . ' · ' . esc_html($days_text) . '</div>';
    $simple .= '<div class="casanova-nexttrip-legacy__money">Total: ' . esc_html(casanova_fmt_money($total)) . ' · Pendiente: ' . esc_html(casanova_fmt_money($pendiente)) . '</div>';
    $simple .= '<div style="margin-top:10px; display:flex; gap:10px; align-items:center;">';
    $simple .= '  <a class="casanova-nexttrip-legacy__link" href="' . esc_url($detail_url) . '">' . esc_html__('Ver detalle', 'casanova-portal') . '</a>'; 
    $simple .= '  <a class="casanova-nexttrip-legacy__link" href="' . esc_url($ics_url) . '" style="color:#666;">' . esc_html__('Añadir a calendario', 'casanova-portal') . '</a>'; 
    $simple .= '</div>';
    $simple .= '</div>';
    return $simple;
  }

  // --- Render Card Normal/Hero ---
  $html  = '<article class="casanova-nexttrip-card">';
  $html .= '  <div class="casanova-nexttrip-card__top">';
  $html .= '    <div class="casanova-nexttrip-card__title">' . esc_html__('Próximo viaje', 'casanova-portal') . '</div>';
  $html .= '    <div class="casanova-nexttrip-card__badge">' . sprintf(esc_html__('En %s días', 'casanova-portal'), esc_html(number_format_i18n($days_left))) . '</div>';
  $html .= '  </div>';
  $html .= '  <div class="casanova-nexttrip-card__headline">' . esc_html($headline) . '</div>';
  if ($rango !== '') {
    $html .= '  <div class="casanova-nexttrip-card__meta">' . esc_html($rango) . '</div>';
  }
  $html .= '  <div class="casanova-nexttrip-card__grid">';
  $html .= '    <div class="casanova-nexttrip-card__stat">';
  $html .= '      <div class="casanova-nexttrip-card__label">' . esc_html__('Total viaje', 'casanova-portal') . '</div>';
  $html .= '      <div class="casanova-nexttrip-card__value">' . esc_html(casanova_fmt_money($total)) . '</div>';
  $html .= '    </div>';
  $html .= '    <div class="casanova-nexttrip-card__stat">';
  $html .= '      <div class="casanova-nexttrip-card__label">' . esc_html__("Pendiente", 'casanova-portal') . '</div>';
  $html .= '      <div class="casanova-nexttrip-card__value casanova-nexttrip-card__value--warn">' . esc_html(casanova_fmt_money($pendiente)) . '</div>';
  $html .= '    </div>';
  $html .= '  </div>';
  
  $html .= '  <div class="casanova-nexttrip-card__cta">';
  $html .= '    <div style="display:flex; gap:8px;">';
  $html .= '      <a class="casanova-btn casanova-btn--primary" href="' . esc_url($detail_url) . '">' . esc_html__('Ver detalle', 'casanova-portal') . '</a>'; 
  $html .= '      <a class="casanova-btn casanova-btn--ghost" href="' . esc_url($ics_url) . '" title="' . esc_attr__('Añadir a calendario', 'casanova-portal') . '" style="padding:10px 12px;">' . $icon_cal . esc_html__('Calendario', 'casanova-portal') . '</a>';
  $html .= '    </div>';
  $html .= '    <span class="casanova-nexttrip-card__hint">'. esc_html__('Pagado', 'casanova-portal') .': ' . esc_html(casanova_fmt_money($pagado)) . '</span>';
  $html .= '  </div>';
  $html .= '</article>';
  
  return $html;
});

/**
 * Card: Estado de pagos (dashboard).
 */
add_shortcode('casanova_card_pagos', function($atts) {
  if (!is_user_logged_in()) return '';
  $atts = shortcode_atts([
    'source' => 'auto', // auto|current|next
    'cta'    => 'both', // both|pagar|detalle|none
    'tab'    => '',     // opcional: hash/tab id (ej. "pagos")
  ], (array)$atts);
  $user_id   = get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if (!$idCliente) return '';
  $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');
  
  // Determinar expediente objetivo
  $idExp = 0;
  $qExp = isset($_GET['expediente']) ? (int) $_GET['expediente'] : 0;
  if ($atts['source'] === 'current') {
    $idExp = $qExp;
  } elseif ($atts['source'] === 'next') {
    $next = function_exists('casanova_portal_get_next_trip_expediente') ? casanova_portal_get_next_trip_expediente($idCliente) : null;
    $idExp = $next ? (int)($next->IdExpediente ?? $next->Id ?? 0) : 0;
  } else { // auto
    if ($qExp) {
      $idExp = $qExp;
    } else {
      $next = function_exists('casanova_portal_get_next_trip_expediente') ? casanova_portal_get_next_trip_expediente($idCliente) : null;
      $idExp = $next ? (int)($next->IdExpediente ?? $next->Id ?? 0) : 0;
    }
  }

  // Contexto del viaje
  $exp_label = '';
  $exp_meta_html = '';
  if ($idExp > 0 && function_exists('casanova_portal_expediente_meta')) {
    $meta = casanova_portal_expediente_meta($idCliente, (int)$idExp);
    if (is_array($meta)) {
      $exp_label = trim((string)($meta['label'] ?? ''));
    }
  }
  if ($exp_label !== '') {
    $exp_meta_html = '<div class="casanova-actioncard__ctx">'. esc_html__('Para:', 'casanova-portal') . ' <strong>' . esc_html($exp_label) . '</strong></div>';
  }

  if (!$idExp) {
    $html  = '<article class="casanova-paycard casanova-paycard--empty">';
    $html .= '  <div class="casanova-paycard__top">';
    $html .= '    <div class="casanova-paycard__title">' . esc_html__('Estado de pagos', 'casanova-portal') . '</div>';
    $html .= '  </div>';
    $html .= '  <div class="casanova-paycard__empty">' . esc_html__('No hay un viaje activo o próximo para mostrar pagos.', 'casanova-portal') . '</div>';
    $html .= '</article>';
    return $html;
  }

  // Reservas + cálculo pagos
  $reservas = function_exists('casanova_giav_reservas_por_expediente')
    ? casanova_giav_reservas_por_expediente($idExp, $idCliente, 100, 0)
    : [];
  if (is_wp_error($reservas)) {
    $html  = '<article class="casanova-paycard casanova-paycard--empty">';
    $html .= '  <div class="casanova-paycard__top">';
    $html .= '    <div class="casanova-paycard__title">' . esc_html__('Estado de pagos', 'casanova-portal') . '</div>';
    $html .= '  </div>';
    $html .= '  <div class="casanova-paycard__empty">' . esc_html__('No se han podido cargar los pagos. Inténtalo más tarde.', 'casanova-portal') . '</div>';
    $html .= '</article>';
    return $html;
  }
  $pago = function_exists('casanova_calc_pago_expediente')
    ? casanova_calc_pago_expediente($idExp, $idCliente, (array)$reservas)
    : [];
  $total     = (float)($pago['total_objetivo'] ?? 0);
  $pagado    = (float)($pago['pagado'] ?? 0);
  $pendiente = (float)($pago['pendiente_real'] ?? 0);
  
  if ($total < 0) $total = 0;
  if ($pagado < 0) $pagado = 0;
  if ($pendiente < 0) $pendiente = 0;
  $progress = ($total > 0) ? min(100, max(0, round(($pagado / $total) * 100))) : 0;
  $is_ok = ($total > 0 && $pendiente <= 0.01) || ($total <= 0.01);
  $status_label = $is_ok ? '' . esc_html__('Todo pagado', 'casanova-portal') . '' : (sprintf(__('Pendiente %s', 'casanova-portal'), casanova_fmt_money($pendiente)));
  $status_class = $is_ok ? 'casanova-paycard__status--ok' : 'casanova-paycard__status--warn';
  
  $detail_url = add_query_arg(['view' => 'expedientes', 'expediente' => $idExp], $base);
  if (!empty($atts['tab'])) {
    $detail_url .= '#' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$atts['tab']);
  }
  
  $cta = (string)$atts['cta'];
  $html  = '<article class="casanova-paycard">';
  $html .= '  <div class="casanova-paycard__top">';
  $html .= '    <div class="casanova-paycard__title">' . esc_html__('Estado de pagos', 'casanova-portal') . '</div>';
  $html .= '    <div class="casanova-paycard__status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</div>';
  $html .= '  </div>';
  $html .= '  <div class="casanova-paycard__numbers">';
  $html .= '    <div class="casanova-paycard__num">';
  $html .= '      <div class="casanova-paycard__label">' . esc_html__('Pagado', 'casanova-portal') . '</div>';
  $html .= '      <div class="casanova-paycard__value">' . esc_html(casanova_fmt_money($pagado)) . '</div>';
  $html .= '    </div>';
  $html .= '    <div class="casanova-paycard__num">';
  $html .= '      <div class="casanova-paycard__label">' . esc_html__('Total', 'casanova-portal') . '</div>';
  $html .= '      <div class="casanova-paycard__value">' . esc_html(casanova_fmt_money($total)) . '</div>';
  $html .= '    </div>';
  $html .= '  </div>';
  $html .= '  <div class="casanova-paycard__bar" role="progressbar" aria-valuenow="' . esc_attr((string)$progress) . '" aria-valuemin="0" aria-valuemax="100">';
  $html .= '    <div class="casanova-paycard__barFill" style="width:' . esc_attr((string)$progress) . '%"></div>';
  $html .= '  </div>';
  $html .= '  <div class="casanova-paycard__meta">' . sprintf(
    esc_html__('Has pagado %1$s de %2$s', 'casanova-portal'),
    casanova_fmt_money($pagado),
    casanova_fmt_money($total)
  ) . '</div>';
  $html .= '  <div class="casanova-paycard__actions">';
  if ($cta === 'both' || $cta === 'detalle') {
    $html .= '    <a class="casanova-btn casanova-btn--ghost" href="' . esc_url($detail_url) . '">' . esc_html__('Ver detalle', 'casanova-portal') . '</a>'; 
  }
  if (!$is_ok && ($cta === 'both' || $cta === 'pagar')) {
    $html .= '    <a class="casanova-btn casanova-btn--primary" href="' . esc_url($detail_url) . '">' . esc_html__('Pagar ahora', 'casanova-portal') . '</a>';
  }
  $html .= '  </div>';
  $html .= '</article>';
  return $html;
});

/**
 * Card: Próxima acción.
 */
add_shortcode('casanova_card_proxima_accion', function($atts) {
  if (!is_user_logged_in()) return '';
  $atts = shortcode_atts([
    'source'      => 'auto',
    'tab_pagos'   => 'pagos',
    'tab_facturas'=> 'facturas',
  ], (array)$atts);
  $user_id   = get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if (!$idCliente) return '';
  $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');
  
  $qExp = isset($_GET['expediente']) ? (int) $_GET['expediente'] : 0;
  $candidates = [];

  // OPTIMIZACIÓN: Solo escaneamos 1 viaje futuro si estamos en modo auto (dashboard),
  // en vez de 3, para que la carga sea más rápida.
  $scan_upcoming = ($atts['source'] === 'next') || ($atts['source'] === 'auto' && !$qExp);
  $upcoming = [];
  if ($scan_upcoming && function_exists('casanova_portal_get_upcoming_trips')) {
    $upcoming = casanova_portal_get_upcoming_trips($idCliente, 1); 
  }
  if (!is_array($upcoming)) $upcoming = [];

  if ($atts['source'] === 'current') {
    if ($qExp) $candidates = [$qExp];
  } elseif ($scan_upcoming) {
    foreach ($upcoming as $t) {
      if (!is_object($t)) continue;
      $tid = (int)($t->IdExpediente ?? $t->Id ?? 0);
      if ($tid > 0) $candidates[] = $tid;
    }
  } else {
    if ($qExp) $candidates = [$qExp];
  }
  $candidates = array_values(array_unique(array_filter($candidates)));

  if (empty($candidates)) {
    $html  = '<article class="casanova-actioncard casanova-actioncard--info">';
    $html .= '  <div class="casanova-actioncard__top">';
    $html .= '    <div class="casanova-actioncard__title">' . esc_html__('Qué necesitas hacer ahora', 'casanova-portal') . '</div>';
    $html .= '    <div class="casanova-actioncard__badge">' . esc_html__('Info', 'casanova-portal') . '</div>';
    $html .= '  </div>';
		$html .= '  <div class="casanova-actioncard__msg">' . esc_html__('No hay viajes próximos para mostrar aquí.', 'casanova-portal') . '</div>';
    $html .= '</article>';
    return $html;
  }

  // Elegir la primera acción realmente pendiente
  $chosen = 0;
  $chosen_reservas = [];
  $chosen_total = 0.0;
  $chosen_pagado = 0.0;
  $chosen_pendiente = 0.0;
  $chosen_facturas = [];

  foreach ($candidates as $cid) {
    $reservas = function_exists('casanova_giav_reservas_por_expediente')
      ? casanova_giav_reservas_por_expediente($cid, $idCliente, 100, 0)
      : [];
    $total = 0.0; $pagado = 0.0; $pendiente = 0.0;
    if (!is_wp_error($reservas) && function_exists('casanova_calc_pago_expediente')) {
      $pago = casanova_calc_pago_expediente($cid, $idCliente, (array)$reservas);
      $total     = (float)($pago['total_objetivo'] ?? 0);
      $pagado    = (float)($pago['pagado'] ?? 0);
      $pendiente = (float)($pago['pendiente_real'] ?? ($pago['pendiente'] ?? 0));
      if ($pendiente < 0) $pendiente = 0;
    }
    if ($pendiente > 0.01) {
      $chosen = (int)$cid;
      $chosen_reservas = is_wp_error($reservas) ? [] : (array)$reservas;
      $chosen_total = $total;
      $chosen_pagado = $pagado;
      $chosen_pendiente = $pendiente;
      break;
    }
    $facturas = function_exists('casanova_giav_facturas_por_expediente')
      ? casanova_giav_facturas_por_expediente($cid, $idCliente, 50, 0)
      : [];
    if (!is_wp_error($facturas) && is_array($facturas) && count($facturas) > 0) {
      $chosen = (int)$cid;
      $chosen_reservas = is_wp_error($reservas) ? [] : (array)$reservas;
      $chosen_total = $total;
      $chosen_pagado = $pagado;
      $chosen_pendiente = $pendiente;
      $chosen_facturas = $facturas;
      break;
    }
  }

  if (!$chosen) {
    $chosen = (int)($candidates[0] ?? 0);
  }
  $idExp = $chosen;
  $reservas = $chosen_reservas;
  $total = $chosen_total;
  $pagado = $chosen_pagado;
  $pendiente = $chosen_pendiente;

  $exp_label = '';
  $exp_meta_html = '';
  if ($idExp > 0 && function_exists('casanova_portal_expediente_meta')) {
    $meta = casanova_portal_expediente_meta($idCliente, (int)$idExp);
    if (is_array($meta)) $exp_label = trim((string)($meta['label'] ?? ''));
  }
  if ($exp_label !== '') {
    $exp_meta_html = '<div class="casanova-actioncard__ctx">'. esc_html__('Para:', 'casanova-portal') . ' <strong>' . esc_html($exp_label) . '</strong></div>';
  }

  $detail_url = add_query_arg(['view' => 'expedientes', 'expediente' => $idExp], $base);

  // 1) Pendiente de pago
  if ($pendiente > 0.01) {
    $deposit_allowed = false;
    $deposit_amt = 0.0;
    $deposit_effective = false;
    $deadline_label = '';
    if (function_exists('casanova_payments_is_deposit_allowed') && function_exists('casanova_payments_calc_deposit_amount')) {
      $deposit_allowed = ($pagado <= 0.01) && (bool) casanova_payments_is_deposit_allowed((array)$reservas);
      if ($deposit_allowed) {
        $deposit_amt = (float) casanova_payments_calc_deposit_amount((float)$pendiente, (int)$idExp);
        $deposit_effective = ($deposit_amt > 0.01) && ($deposit_amt + 0.01 < (float)$pendiente);
        if ($deposit_effective && function_exists('casanova_payments_min_fecha_limite')) {
          $dl = casanova_payments_min_fecha_limite((array)$reservas);
          if ($dl instanceof DateTimeImmutable) {
            $deadline_label = $dl->format('d/m/Y');
          }
        }
      }
    }
    $tab = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$atts['tab_pagos']);
    $url_tab = $detail_url . ($tab ? ('#' . $tab) : '');
    
    if (function_exists('casanova_portal_pay_expediente_url')) {
      $pay_url = casanova_portal_pay_expediente_url((int)$idExp);
    } else {
      $pay_url = add_query_arg([
        'action' => 'casanova_pay_expediente',
        'expediente' => (int)$idExp,
        '_wpnonce' => wp_create_nonce('casanova_pay_expediente_' . (int)$idExp),
      ], admin_url('admin-post.php'));
    }
    
    if ($deposit_effective) {
      $url_dep = add_query_arg(['mode' => 'deposit'], $pay_url);
      $html  = '<article class="casanova-actioncard casanova-actioncard--warn">';
      $html .= '  <div class="casanova-actioncard__top">';
      $html .= '    <div class="casanova-actioncard__title">' . esc_html__('Qué necesitas hacer ahora', 'casanova-portal') . '</div>';
      $html .= '    <div class="casanova-actioncard__badge">' . esc_html__('Depósito', 'casanova-portal') . '</div>';
      $html .= '  </div>';
      if ($deadline_label) {
        $msg = sprintf(
          esc_html__('Para confirmar tu viaje, puedes pagar un depósito de %1$s hasta el %2$s', 'casanova-portal'),
          esc_html(casanova_fmt_money($deposit_amt)),
          '<strong>' . esc_html($deadline_label) . '</strong>'
        );
      } else {
        $msg = sprintf(
          esc_html__('Para confirmar tu viaje, puedes pagar un depósito de %s', 'casanova-portal'),
          esc_html(casanova_fmt_money($deposit_amt))
        );
      }
      $msg .= '.';
      $html .= '  <div class="casanova-actioncard__msg">' . $msg . '</div>';
      $html .= $exp_meta_html;
      if ($total > 0) {
      $html .= '  <div class="casanova-actioncard__sub">' . esc_html__('Pendiente:', 'casanova-portal') . ' <strong>' . esc_html(casanova_fmt_money($pendiente)) . '</strong> · ' . esc_html__('Total:', 'casanova-portal') . ' ' . esc_html(casanova_fmt_money($total)) . '</div>';
      }
      $html .= '  <div class="casanova-actioncard__footer">';
      $html .= '    <a class="casanova-btn casanova-btn--primary" href="' . esc_url($url_dep) . '">' . esc_html__('Pagar depósito', 'casanova-portal') . '</a>'; 
      $html .= '    <a class="casanova-btn casanova-btn--ghost" href="' . esc_url($url_tab) . '">' . esc_html__('Ver opciones de pago', 'casanova-portal') . '</a>'; 
      $html .= '  </div>';
      $html .= '</article>';
      return $html;
    }
    // Sin depósito
    $html  = '<article class="casanova-actioncard casanova-actioncard--warn">';
    $html .= '  <div class="casanova-actioncard__top">';
    $html .= '    <div class="casanova-actioncard__title">' . esc_html__('Qué necesitas hacer ahora', 'casanova-portal') . '</div>';
    $html .= '    <div class="casanova-actioncard__badge">' . esc_html__('Pendiente', 'casanova-portal') . '</div>';
    $html .= '  </div>';
      $html .= $exp_meta_html;
  $html .= '  <div class="casanova-actioncard__msg">' . sprintf(
      wp_kses_post(__('Tienes un pago pendiente de <strong>%s</strong> para este viaje.', 'casanova-portal')),
      esc_html(casanova_fmt_money($pendiente))
    ) . '</div>';
    if ($total > 0) {
    $html .= '  <div class="casanova-actioncard__sub">' . esc_html__('Pagado:', 'casanova-portal') . ' <strong>' . esc_html(casanova_fmt_money($pagado)) . '</strong> · Total: ' . esc_html(casanova_fmt_money($total)) . '</div>';
    }
    $html .= '  <div class="casanova-actioncard__footer">';
    $html .= '    <a class="casanova-btn casanova-btn--primary" href="' . esc_url($url_tab) . '">' . esc_html__('Pagar ahora', 'casanova-portal') . '</a>';
    $html .= '    <a class="casanova-btn casanova-btn--ghost" href="' . esc_url($detail_url) . '">' . esc_html__('Ver viaje', 'casanova-portal') . '</a>';
    $html .= '  </div>';
    $html .= '</article>';
    return $html;
  }
  
  // 2) Facturas
  $facturas = $chosen_facturas;
  if (empty($facturas)) {
    $facturas = function_exists('casanova_giav_facturas_por_expediente')
      ? casanova_giav_facturas_por_expediente($idExp, $idCliente, 50, 0)
      : [];
  }
  if (!is_wp_error($facturas) && is_array($facturas) && count($facturas) > 0) {
    $tab = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$atts['tab_facturas']);
    $url = $detail_url . ($tab ? ('#' . $tab) : '');
    $n = count($facturas);
    $html  = '<article class="casanova-actioncard casanova-actioncard--info">';
    $html .= '  <div class="casanova-actioncard__top">';
    $html .= '    <div class="casanova-actioncard__title">' . esc_html__('Qué necesitas hacer ahora', 'casanova-portal') . '</div>';
    $html .= '    <div class="casanova-actioncard__badge">' . esc_html__('Facturas', 'casanova-portal') . '</div>';
    $html .= '  </div>';
      $html .= $exp_meta_html;
  $html .= '  <div class="casanova-actioncard__msg">' . sprintf(
      wp_kses_post(_n(
        'Tienes <strong>%s</strong> factura disponible.',
        'Tienes <strong>%s</strong> facturas disponibles.',
        $n,
        'casanova-portal'
      )),
      esc_html((string)$n)
    ) . '</div>';
    $html .= '  <div class="casanova-actioncard__footer">';
    $html .= '    <a class="casanova-btn casanova-btn--primary" href="' . esc_url($url) . '">' . esc_html__('Ver facturas', 'casanova-portal') . '</a>';
    $html .= '    <a class="casanova-btn casanova-btn--ghost" href="' . esc_url($detail_url) . '">' . esc_html__('Ver viaje', 'casanova-portal') . '</a>';
    $html .= '  </div>';
    $html .= '</article>';
    return $html;
  }
  
 // 3) Todo OK
  $note_html = '';
  
  // CORRECCIÓN: Si hemos llegado aquí, el primer viaje está pagado.
  // Ahora sí necesitamos saber si hay un segundo viaje. 
  // Si la lista original tenía menos de 2 (por la optimización), recargamos buscando 2.
  if (is_array($upcoming) && count($upcoming) < 2) {
    $upcoming = function_exists('casanova_portal_get_upcoming_trips') 
      ? casanova_portal_get_upcoming_trips($idCliente, 2) 
      : [];
  }

  if (is_array($upcoming) && count($upcoming) >= 2) {
    $second = $upcoming[1]; // El segundo de la lista
    $second_id = (int)($second->IdExpediente ?? $second->Id ?? 0);
    
    if ($second_id && $second_id !== (int)$idExp) {
      $label2 = function_exists('casanova_portal_expediente_label_from_obj') 
        ? casanova_portal_expediente_label_from_obj($second) 
        : esc_html__('Otro viaje', 'casanova-portal');
        
      $url2 = add_query_arg(['view' => 'expedientes', 'expediente' => $second_id], $base);
      
      // Opcional: ver si el segundo tiene pendiente para mostrar el importe
      $extra = '';
      if (function_exists('casanova_giav_reservas_por_expediente') && function_exists('casanova_calc_pago_expediente')) {
         // Aquí sí hacemos la llamada extra, pero SOLO si el usuario no debe nada del primero.
         $res2 = casanova_giav_reservas_por_expediente($second_id, $idCliente, 100, 0);
         if (!is_wp_error($res2)) {
            $p2 = casanova_calc_pago_expediente($second_id, $idCliente, (array)$res2);
            $pend2 = (float)($p2['pendiente_real'] ?? 0);
            if ($pend2 > 0.01) {
               $extra = ' <span class="casanova-actioncard__note-amount">' . sprintf(esc_html__('Pendiente: %s', 'casanova-portal'), casanova_fmt_money($pend2)) . '</span>';
            }
         }
      }

      $note_html = '<div class="casanova-actioncard__note">' . 
        sprintf(
          wp_kses_post(__('También tienes otro viaje a la vista: <a href="%s">%s</a>.%s', 'casanova-portal')),
          esc_url($url2),
          esc_html($label2),
          $extra
        ) . 
      '</div>';
    }
  }

  $html  = '<article class="casanova-actioncard casanova-actioncard--ok">';
  // ... (resto del renderizado igual)
  $html .= '  <div class="casanova-actioncard__top">';
    $html .= '    <div class="casanova-actioncard__title">' . esc_html__('Qué necesitas hacer ahora', 'casanova-portal') . '</div>';
  $html .= '    <div class="casanova-actioncard__badge">' . esc_html__('Todo listo', 'casanova-portal') . '</div>';
  $html .= '  </div>';
	$html .= $exp_meta_html;
	$html .= '  <div class="casanova-actioncard__msg">' . esc_html__('Tu próximo viaje está al día. No tienes acciones pendientes ahora mismo.', 'casanova-portal') . '</div>';
  if ($note_html) $html .= $note_html;
  $html .= '  <div class="casanova-actioncard__footer">';
  $html .= '    <a class="casanova-btn casanova-btn--primary" href="' . esc_url($detail_url) . '">' . esc_html__('Ver viaje', 'casanova-portal') . '</a>';
  $html .= '  </div>';
  $html .= '</article>';
  return $html;
});