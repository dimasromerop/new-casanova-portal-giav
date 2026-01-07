<?php
if (!defined('ABSPATH')) exit;

/**
 * Dashboard principal del portal: cards rápidas (Mulligans + Próximo viaje + Pagos).
 * Mantiene el enfoque "sin romper": todo es lectura, usa wrappers GIAV existentes.
 */
function casanova_portal_render_dashboard(int $user_id): string {

  // Nuevo flujo (API-first): el dashboard deja de calcular datos aquí.
  // La lógica vive en Casanova_Dashboard_Service, reutilizable por REST/React.
  if (class_exists('Casanova_Dashboard_Service') && class_exists('Casanova_Dashboard_DTO')) {
    try {
      $dto  = Casanova_Dashboard_Service::build_for_user($user_id);
      $data = $dto ? $dto->to_array() : [];

      if (is_array($data) && !empty($data)) {
        return casanova_portal_render_dashboard_from_data($user_id, $data);
      }
    } catch (Exception $e) {
      // Silencioso: si algo falla, caemos al legacy para no romper el portal.
    }
  }

  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);

  // 1) Mulligans (datos locales)
  $m = function_exists('casanova_mulligans_get_user') ? casanova_mulligans_get_user($user_id) : [];
  $m_points = isset($m['points']) ? (int)$m['points'] : 0;
  $m_tier   = isset($m['tier']) ? (string)$m['tier'] : '';
  $m_last   = isset($m['last_sync']) ? (int)$m['last_sync'] : 0;

  // 2) Próximo viaje (GIAV: expedientes)
  $next = null;
  $today = new DateTimeImmutable('today', wp_timezone());

  if ($idCliente && function_exists('casanova_giav_expedientes_por_cliente')) {
    $exps = casanova_giav_expedientes_por_cliente($idCliente);
    if (is_array($exps)) {
      foreach ($exps as $e) {
        if (!is_object($e)) continue;
        $ini = $e->FechaInicio ?? $e->FechaDesde ?? $e->Desde ?? null;
        if (!$ini) continue;
        $ts = strtotime((string)$ini);
        if (!$ts) continue;

        $d = (new DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone());
        if ($d < $today) continue;

        if ($next === null || $d < $next['date']) {
          $next = [
            'obj' => $e,
            'date' => $d,
          ];
        }
      }
    }
  }

  $next_html = '<p class="casanova-muted">No hay próximos viajes.</p>';
  $pay_html  = '<p class="casanova-muted">No disponible.</p>';

  if ($next) {
    $e = $next['obj'];
    $idExp = (int)($e->IdExpediente ?? $e->IDExpediente ?? $e->Id ?? 0);
    if (!$idExp && isset($e->Codigo)) $idExp = (int)$e->Codigo;

    $titulo = (string)($e->Titulo ?? $e->Nombre ?? 'Expediente');
    $codigo = (string)($e->Codigo ?? '');
    $estado = (string)($e->Estado ?? $e->Situacion ?? '');

    $fin = $e->FechaFin ?? $e->FechaHasta ?? $e->Hasta ?? null;
    $rango = function_exists('casanova_fmt_date_range') ? casanova_fmt_date_range($e->FechaInicio ?? null, $fin) : '';

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/');
    $exp_url = add_query_arg(['view' => 'expedientes', 'expediente' => $idExp], $base);

    $next_html  = '<div class="casanova-next">';
    $next_html .= '  <div class="casanova-next-title"><a href="' . esc_url($exp_url) . '">' . esc_html($titulo) . '</a></div>';
    $next_html .= '  <div class="casanova-next-meta">' . esc_html($codigo ? ($codigo . ' · ') : '') . esc_html($rango) . '</div>';
    if ($estado) $next_html .= '  <div class="casanova-pill">' . esc_html($estado) . '</div>';
    $next_html .= '</div>';

    // Pagos: usa reservas + calculadora ya existente
    if ($idCliente && $idExp && function_exists('casanova_giav_reservas_por_expediente') && function_exists('casanova_calc_pago_expediente')) {
      $reservas = casanova_giav_reservas_por_expediente($idExp, $idCliente);
      if (is_array($reservas)) {
        $p = casanova_calc_pago_expediente($idExp, $idCliente, $reservas);
        if (is_array($p)) {
          $total = (float)($p['total_objetivo'] ?? 0);
          $pagado = (float)($p['pagado_real'] ?? 0);
          $pend = max(0, $total - $pagado);

          $pay_html  = '<div class="casanova-pay">';
          $pay_html .= '  <div><span class="casanova-muted">' . esc_html__('Total:', 'casanova-portal') . '</span> <strong>' . esc_html(number_format_i18n($total, 2)) . '</strong></div>';
          $pay_html .= '  <div><span class="casanova-muted">' . esc_html__('Pagado:', 'casanova-portal') . '</span> <strong>' . esc_html(number_format_i18n($pagado, 2)) . '</strong></div>';
          $pay_html .= '  <div><span class="casanova-muted">' . esc_html__('Pendiente:', 'casanova-portal') . '</span> <strong>' . esc_html(number_format_i18n($pend, 2)) . '</strong></div>';
          $pay_html .= '  <div class="casanova-pay-actions"><a class="casanova-btn" href="' . esc_url($exp_url . '#pagos') . '">' . esc_html__('Ver pagos', 'casanova-portal') . '</a></div>';
          $pay_html .= '</div>';
        }
      }
    }
  }

  // Mensajes (GIAV: comentarios)
  $msg_html = '<p class="casanova-muted">' . esc_html__('No hay mensajes recientes.', 'casanova-portal') . '</p>';
  
  // OPTIMIZACIÓN: Reutilizamos el expediente encontrado en el paso 2 ($next['obj'])
  $e = ($next && isset($next['obj'])) ? $next['obj'] : null;

  // Fallback: solo buscamos si no tenemos expediente y existe la función helper
  if (!$e && $idCliente && function_exists('casanova_portal_get_next_trip_expediente')) {
    $e = casanova_portal_get_next_trip_expediente($idCliente);
  }

  if ($e) {
    $idExpMsg = (int)($e->IdExpediente ?? $e->Id ?? 0);
    // Aseguramos ID válido
    if (!$idExpMsg && isset($e->Codigo)) $idExpMsg = (int)$e->Codigo;

    if ($idExpMsg && function_exists('casanova_giav_comments_por_expediente')) {
      $n_new = function_exists('casanova_messages_new_count_for_expediente') ? (int) casanova_messages_new_count_for_expediente($user_id, $idExpMsg, 30) : 0;
      // Usamos la versión optimizada con caché si existe
      $comments = casanova_giav_comments_por_expediente($idExpMsg, 10, 365);
      
      if (!is_wp_error($comments) && is_array($comments) && !empty($comments)) {
        $latest = $comments[0];
        $b = is_object($latest) ? trim((string)($latest->Body ?? '')) : '';
        $b = $b !== '' ? wp_strip_all_tags($b) : '';
        if (mb_strlen($b, 'UTF-8') > 140) $b = mb_substr($b, 0, 140, 'UTF-8') . '…';
        $ts = is_object($latest) ? (strtotime((string)($latest->CreationDate ?? '')) ?: 0) : 0;
        $when = $ts ? sprintf(esc_html__('Hace %s', 'casanova-portal'), human_time_diff($ts, time())) : '';

        $msg_html  = '';
        $exp_label = '';
        
        if (is_object($e)) {
          if (function_exists('casanova_portal_expediente_label_from_obj')) {
            $exp_label = casanova_portal_expediente_label_from_obj($e);
          } else {
            $t = trim((string)($e->Titulo ?? ''));
            $c = trim((string)($e->Codigo ?? ''));
            if ($t && $c) $exp_label = $t . ' (' . $c . ')';
            elseif ($t) $exp_label = $t;
            elseif ($c) $exp_label = sprintf(__('Expediente %s', 'casanova-portal'), $c);
          }
        }
        if ($exp_label !== '') {
          $msg_html .= '<div class="casanova-muted" style="margin-top:2px;">' .
            sprintf(esc_html__('Viaje: %s', 'casanova-portal'), esc_html($exp_label)) .
          '</div>';
        }

        if ($n_new > 0) {
          $msg_html .= '<div class="casanova-pill">' . sprintf(esc_html__('%s nuevos', 'casanova-portal'), esc_html(number_format_i18n($n_new))) . '</div>';
        }
        if ($b !== '') {
          $msg_html .= '<div class="casanova-muted" style="margin-top:8px;">' . esc_html($b) . '</div>';
        }
        if ($when !== '') {
          $msg_html .= '<div class="casanova-card-meta casanova-muted" style="margin-top:6px;">' . esc_html($when) . '</div>';
        }
      }
      $msg_url = add_query_arg(['view' => 'mensajes', 'expediente' => $idExpMsg], casanova_portal_base_url());
      $msg_html .= '<div class="casanova-card-actions"><a class="casanova-btn" href="' . esc_url($msg_url) . '">' . esc_html__('Ver mensajes', 'casanova-portal') . '</a></div>';
    }
  }

  // Render cards
  $html  = '<div class="casanova-dashboard">';
  $html .= '  <div class="casanova-cards">';
  $html .= '    <section class="casanova-card">';
  $html .= '      <div class="casanova-card-h">' . esc_html__('Mulligans', 'casanova-portal') . '</div>';
  $html .= '      <div class="casanova-card-kpi"><strong>' . esc_html(number_format_i18n($m_points)) . '</strong></div>';
  $html .= '      <div class="casanova-card-meta">' . esc_html($m_tier ? (sprintf(__('Nivel: %s', 'casanova-portal'), $m_tier)) : ' ') . '</div>';
  if ($m_last) $html .= '      <div class="casanova-card-meta casanova-muted">' . esc_html__('Última sincronización:', 'casanova-portal') . ' ' . esc_html(date_i18n('d/m/Y H:i', $m_last)) . '</div>';
  $html .= '      <div class="casanova-card-actions"><a class="casanova-btn" href="' . esc_url(add_query_arg(['view'=>'mulligans'], casanova_portal_base_url())) . '">' . esc_html__('Ver movimientos', 'casanova-portal') . '</a></div>';
  $html .= '    </section>';

  $html .= '    <section class="casanova-card">';
  $html .= '      <div class="casanova-card-h">' . esc_html__('Próximo viaje', 'casanova-portal') . '</div>';
  $html .=        $next_html;
  $html .= '    </section>';

  $html .= '    <section class="casanova-card">';
  $html .= '      <div class="casanova-card-h">' . esc_html__('Pagos', 'casanova-portal') . '</div>';
  $html .=        $pay_html;
  $html .= '    </section>';

  $html .= '    <section class="casanova-card">';
  $html .= '      <div class="casanova-card-h">' . esc_html__('Mensajes', 'casanova-portal') . '</div>';
  $html .=        $msg_html;
  $html .= '    </section>';
  $html .= '  </div>';

  $html .= '</div>';

  return $html;
}

/**
 * Renderiza el dashboard a partir de datos (DTO/API). Mantiene el HTML legacy.
 *
 * @param int   $user_id
 * @param array $data
 */
function casanova_portal_render_dashboard_from_data(int $user_id, array $data): string {

  $m = is_array($data['mulligans'] ?? null) ? $data['mulligans'] : [];
  $m_points = (int)($m['points'] ?? 0);
  $m_tier   = (string)($m['tier'] ?? '');
  $m_last   = (int)($m['last_sync'] ?? 0);

  $next = is_array($data['next_trip'] ?? null) ? $data['next_trip'] : null;
  $pay  = is_array($data['payments'] ?? null) ? $data['payments'] : [];
  $msg  = is_array($data['messages'] ?? null) ? $data['messages'] : [];

  // Próximo viaje
  $next_html = '<p class="casanova-muted">' . esc_html__('No hay próximos viajes.', 'casanova-portal') . '</p>';
  if (is_array($next) && !empty($next)) {
    $titulo  = (string)($next['title'] ?? __('Viaje', 'casanova-portal'));
    $codigo  = (string)($next['code'] ?? '');
    $estado  = (string)($next['status'] ?? '');
    $rango   = (string)($next['date_range'] ?? '');
    $exp_url = (string)($next['url'] ?? '');

    $next_html  = '<div class="casanova-next">';
    $next_html .= '  <div class="casanova-next-title">' . ($exp_url ? '<a href="' . esc_url($exp_url) . '">' . esc_html($titulo) . '</a>' : esc_html($titulo)) . '</div>';
    $next_html .= '  <div class="casanova-next-meta">' . esc_html($codigo ? ($codigo . ' · ') : '') . esc_html($rango) . '</div>';
    if ($estado) $next_html .= '  <div class="casanova-pill">' . esc_html($estado) . '</div>';
    $next_html .= '</div>';
  }

  // Pagos
  $pay_html = '<p class="casanova-muted">' . esc_html__('No disponible.', 'casanova-portal') . '</p>';
  if (!empty($pay)) {
    $total  = (float)($pay['total'] ?? 0);
    $pagado = (float)($pay['paid'] ?? 0);
    $pend   = (float)($pay['pending'] ?? max(0, $total - $pagado));
    $url    = (string)($pay['url'] ?? '');

    $pay_html  = '<div class="casanova-pay">';
    $pay_html .= '  <div><span class="casanova-muted">' . esc_html__('Total:', 'casanova-portal') . '</span> <strong>' . esc_html(number_format_i18n($total, 2)) . '</strong></div>';
    $pay_html .= '  <div><span class="casanova-muted">' . esc_html__('Pagado:', 'casanova-portal') . '</span> <strong>' . esc_html(number_format_i18n($pagado, 2)) . '</strong></div>';
    $pay_html .= '  <div><span class="casanova-muted">' . esc_html__('Pendiente:', 'casanova-portal') . '</span> <strong>' . esc_html(number_format_i18n($pend, 2)) . '</strong></div>';
    if ($url) {
      $pay_html .= '  <div class="casanova-pay-actions"><a class="casanova-btn" href="' . esc_url($url) . '">' . esc_html__('Ver pagos', 'casanova-portal') . '</a></div>';
    }
    $pay_html .= '</div>';
  }

  // Mensajes
  $msg_html = '<p class="casanova-muted">' . esc_html__('No hay mensajes recientes.', 'casanova-portal') . '</p>';
  if (!empty($msg)) {
    $msg_html = '';
    $exp_label = (string)($msg['trip_label'] ?? '');
    $unread    = (int)($msg['unread'] ?? 0);
    $snippet   = (string)($msg['snippet'] ?? '');
    $when      = (string)($msg['when'] ?? '');
    $url       = (string)($msg['url'] ?? '');

    if ($exp_label !== '') {
      $msg_html .= '<div class="casanova-muted" style="margin-top:2px;">' . sprintf(esc_html__('Viaje: %s', 'casanova-portal'), esc_html($exp_label)) . '</div>';
    }
    if ($unread > 0) {
      $msg_html .= '<div class="casanova-pill">' . sprintf(esc_html__('%s nuevos', 'casanova-portal'), esc_html(number_format_i18n($unread))) . '</div>';
    }
    if ($snippet !== '') {
      $msg_html .= '<div class="casanova-muted" style="margin-top:8px;">' . esc_html($snippet) . '</div>';
    }
    if ($when !== '') {
      $msg_html .= '<div class="casanova-card-meta casanova-muted" style="margin-top:6px;">' . esc_html($when) . '</div>';
    }
    if ($url) {
      $msg_html .= '<div class="casanova-card-actions"><a class="casanova-btn" href="' . esc_url($url) . '">' . esc_html__('Ver mensajes', 'casanova-portal') . '</a></div>';
    }
  }

  // Render cards (HTML legacy)
  $html  = '<div class="casanova-dashboard">';
  $html .= '  <div class="casanova-cards">';
  $html .= '    <section class="casanova-card">';
  $html .= '      <div class="casanova-card-h">' . esc_html__('Mulligans', 'casanova-portal') . '</div>';
  $html .= '      <div class="casanova-card-kpi"><strong>' . esc_html(number_format_i18n($m_points)) . '</strong></div>';
  $html .= '      <div class="casanova-card-meta">' . esc_html($m_tier ? (sprintf(__('Nivel: %s', 'casanova-portal'), $m_tier)) : ' ') . '</div>';
  if ($m_last) $html .= '      <div class="casanova-card-meta casanova-muted">' . esc_html__('Última sincronización:', 'casanova-portal') . ' ' . esc_html(date_i18n('d/m/Y H:i', $m_last)) . '</div>';
  $html .= '      <div class="casanova-card-actions"><a class="casanova-btn" href="' . esc_url(add_query_arg(['view'=>'mulligans'], casanova_portal_base_url())) . '">' . esc_html__('Ver movimientos', 'casanova-portal') . '</a></div>';
  $html .= '    </section>';

  $html .= '    <section class="casanova-card">';
  $html .= '      <div class="casanova-card-h">' . esc_html__('Próximo viaje', 'casanova-portal') . '</div>';
  $html .=        $next_html;
  $html .= '    </section>';

  $html .= '    <section class="casanova-card">';
  $html .= '      <div class="casanova-card-h">' . esc_html__('Pagos', 'casanova-portal') . '</div>';
  $html .=        $pay_html;
  $html .= '    </section>';

  $html .= '    <section class="casanova-card">';
  $html .= '      <div class="casanova-card-h">' . esc_html__('Mensajes', 'casanova-portal') . '</div>';
  $html .=        $msg_html;
  $html .= '    </section>';
  $html .= '  </div>';
  $html .= '</div>';

  return $html;
}