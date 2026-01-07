<?php
if (!defined('ABSPATH')) exit;

/**
 * Bonos / Vouchers: listado por expediente con links a preview HTML y PDF.
 * Shortcode: [casanova_bonos days="3" only_recent="0"]
 *
 * Nota: GIAV es fuente de verdad. Los bonos se consideran "disponibles" cuando el expediente está pagado
 * (mismo gate que portal-actions.php antes de permitir ver el bono).
 */

function casanova_bonos_service_label($r): string {
  if (!is_object($r)) return '';
  $dx = $r->DatosExternos ?? null;
  $candidates = [
    $r->Descripcion ?? null,
    is_object($dx) ? ($dx->Descripcion ?? null) : null,
    $r->Concepto ?? null,
    $r->Servicio ?? null,
  ];
  foreach ($candidates as $v) {
    $s = trim((string)$v);
    if ($s !== '') return $s;
  }
  return 'Servicio';
}

function casanova_bonos_reserva_dates($r): array {
  if (!is_object($r)) return ['from' => '', 'to' => ''];
  $dx = $r->DatosExternos ?? null;
  $from = $r->FechaInicio ?? ($r->FechaDesde ?? (is_object($dx) ? ($dx->FechaDesde ?? null) : null));
  $to   = $r->FechaFin ?? ($r->FechaHasta ?? (is_object($dx) ? ($dx->FechaHasta ?? null) : null));
  return ['from' => (string)$from, 'to' => (string)$to];
}

function casanova_bonos_links(int $idExpediente, int $idReserva): array {
  if ($idExpediente <= 0 || $idReserva <= 0) return ['view' => '', 'pdf' => ''];
  $nonce = wp_create_nonce('casanova_voucher_' . $idExpediente . '_' . $idReserva);

  // Preferimos endpoint frontend para evitar bloqueos a /wp-admin/ en usuarios no admin.
  if (function_exists('casanova_portal_voucher_url')) {
    return [
      'view' => casanova_portal_voucher_url($idExpediente, $idReserva, 'view'),
      'pdf'  => casanova_portal_voucher_url($idExpediente, $idReserva, 'pdf'),
    ];
  }

  // Fallback legacy (admin-post.php)
  $base = admin_url('admin-post.php');
  return [
    'view' => add_query_arg([
      'action' => 'casanova_voucher',
      'expediente' => $idExpediente,
      'reserva' => $idReserva,
      '_wpnonce' => $nonce,
    ], $base),
    'pdf' => add_query_arg([
      'action' => 'casanova_voucher_pdf',
      'expediente' => $idExpediente,
      'reserva' => $idReserva,
      '_wpnonce' => $nonce,
    ], $base),
  ];
}


/**
 * Devuelve lista de bonos disponibles agrupados por expediente.
 * OPTIMIZADO: Filtra por fecha para no escanear viajes antiguos (evita N+1 masivo).
 * @return array<int, array{exp:object, items:array<int,array>}>
 */
function casanova_bonos_available_grouped(int $idCliente): array {
  // Pedimos un batch razonable de expedientes (50)
  $exps = function_exists('casanova_giav_expedientes_por_cliente')
    ? casanova_giav_expedientes_por_cliente($idCliente, 50, 0)
    : [];

  if (is_wp_error($exps) || !is_array($exps)) $exps = [];

  $out = [];
  
  // FECHA DE CORTE: Solo procesamos viajes que terminaron hace menos de 90 días o son futuros.
  // Esto previene que un cliente con historial largo tumbe el servidor con llamadas SOAP innecesarias.
  $corte = strtotime('-90 days');

  foreach ($exps as $exp) {
    if (!is_object($exp)) continue;
    $idExp = (int)($exp->IdExpediente ?? $exp->Id ?? 0);
    if ($idExp <= 0) continue;

    // --- OPTIMIZACIÓN DE RENDIMIENTO ---
    // Si el viaje terminó hace mucho, lo saltamos. 
    // Ahorramos llamadas pesadas a Reservas_SEARCH y Cobro_SEARCH.
    $fin = $exp->FechaDesde ?? $exp->FechaHasta ?? $exp->Hasta ?? null;
    // Solo aplicamos el corte si tenemos fecha de fin. Si no, procesamos por seguridad.
    if ($fin && strtotime((string)$fin) < $corte) {
        continue; 
    }
    // -----------------------------------

    $reservas = function_exists('casanova_giav_reservas_por_expediente')
      ? casanova_giav_reservas_por_expediente($idExp, $idCliente)
      : [];
    if (is_wp_error($reservas) || !is_array($reservas)) $reservas = [];

    // Gate de pago (mismo criterio que en admin_post_casanova_voucher)
    $calc = function_exists('casanova_calc_pago_expediente')
      ? casanova_calc_pago_expediente($idExp, $idCliente, $reservas)
      : null;

    if (is_wp_error($calc) || !is_array($calc) || empty($calc['expediente_pagado'])) {
      continue; // sin pago completo -> sin bonos
    }

    $items = [];
    // En GIAV, los PQ ROOT (paquetes con servicios anidados) no tienen bono.
    // Los bonos se generan a nivel de servicios hijos, no del PQ contenedor.
    $byId = [];
    foreach ($reservas as $rr) {
      if (!is_object($rr)) continue;
      $rid = (int)($rr->Id ?? 0);
      if ($rid) $byId[$rid] = $rr;
    }
    $tiene_padre_valido = function($r) use ($byId): bool {
      $pid = (int)($r->Anidacion_IdReservaContenedora ?? 0);
      return ($pid > 0 && isset($byId[$pid]));
    };

    foreach ($reservas as $r) {
      if (!is_object($r)) continue;
      $idRes = (int)($r->Id ?? 0);
      if ($idRes <= 0) continue;

      // Si está anulada/cancelada, normalmente no tiene sentido ofrecer bono
      $anulada = (int)($r->Anulada ?? 0);
      if ($anulada === 1) continue;
      
      // Omitir PQ ROOT (paquete contenedor) porque no genera bono
      $tipo = (string)($r->TipoReserva ?? '');
      if ($tipo === 'PQ' && !$tiene_padre_valido($r)) continue;

      $label = casanova_bonos_service_label($r);
      $dates = casanova_bonos_reserva_dates($r);
      $links = casanova_bonos_links($idExp, $idRes);

      $items[] = [
        'id_reserva' => $idRes,
        'label' => $label,
        'from' => $dates['from'],
        'to' => $dates['to'],
        'view_url' => $links['view'],
        'pdf_url' => $links['pdf'],
      ];
    }

    if (!empty($items)) {
      $out[] = ['exp' => $exp, 'items' => $items];
    }
  }

  return $out;
}

/**
 * Guarda el primer avistamiento de cada bono por usuario, para badge "Nuevo".
 */
function casanova_bonos_update_seen(int $user_id, array $available_keys): array {
  $seen = get_user_meta($user_id, 'casanova_bonos_seen', true);
  if (!is_array($seen)) $seen = [];

  $now = time();
  foreach ($available_keys as $k) {
    if (!isset($seen[$k])) $seen[$k] = $now;
  }

  update_user_meta($user_id, 'casanova_bonos_seen', $seen);
  return $seen;
}

/**
 * Cuenta bonos "nuevos" (primer avistamiento en los últimos N días).
 */
function casanova_bonos_recent_count(int $user_id, int $idCliente, int $days = 3): int {
  $days = max(1, (int)$days);
  $cut = time() - ($days * DAY_IN_SECONDS);

  // Cache corta para no recalcular en cada render del menú
  $cache_key = 'casanova_bonos_badge_' . $user_id . '_' . $days;

  if (function_exists('casanova_cache_remember')) {
    return (int) casanova_cache_remember($cache_key, 300, function() use ($user_id, $idCliente, $cut) {
      $groups = casanova_bonos_available_grouped($idCliente);
      $keys = [];
      foreach ($groups as $g) {
        $exp = $g['exp'];
        $idExp = (int)($exp->IdExpediente ?? $exp->Id ?? 0);
        foreach (($g['items'] ?? []) as $it) {
          $keys[] = 'exp:' . $idExp . '|res:' . (int)($it['id_reserva'] ?? 0);
        }
      }
      $seen = casanova_bonos_update_seen($user_id, $keys);

      $count = 0;
      foreach ($keys as $k) {
        $ts = isset($seen[$k]) ? (int)$seen[$k] : 0;
        if ($ts >= $cut) $count++;
      }
      return $count;
    });
  }

  // Fallback sin helper de caché
  $groups = casanova_bonos_available_grouped($idCliente);
  $keys = [];
  foreach ($groups as $g) {
    $exp = $g['exp'];
    $idExp = (int)($exp->IdExpediente ?? $exp->Id ?? 0);
    foreach (($g['items'] ?? []) as $it) {
      $keys[] = 'exp:' . $idExp . '|res:' . (int)($it['id_reserva'] ?? 0);
    }
  }
  $seen = casanova_bonos_update_seen($user_id, $keys);

  $count = 0;
  foreach ($keys as $k) {
    $ts = isset($seen[$k]) ? (int)$seen[$k] : 0;
    if ($ts >= $cut) $count++;
  }
  return $count;
}

/**
 * Shortcode listado de bonos.
 * - days: ventana para "Nuevos" (badge por expediente)
 * - only_recent: si 1, solo muestra expedientes con algún bono nuevo en esa ventana
 */
add_shortcode('casanova_bonos', function($atts = []) {
  if (!is_user_logged_in()) return '';

  $atts = shortcode_atts([
    'days' => '3',
    'only_recent' => '0',
    'show_all_label' => '0',
  ], (array)$atts);

  $user_id = (int) get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if (!$idCliente) return '';

  $days = max(1, (int)$atts['days']);
  $only_recent = ((string)$atts['only_recent'] === '1');
  $cut = time() - ($days * DAY_IN_SECONDS);

  $groups = casanova_bonos_available_grouped($idCliente);

  // Prepara mapa seen y calcula "nuevo" por bono
  $keys = [];
  foreach ($groups as $g) {
    $exp = $g['exp'];
    $idExp = (int)($exp->IdExpediente ?? $exp->Id ?? 0);
    foreach (($g['items'] ?? []) as $it) {
      $keys[] = 'exp:' . $idExp . '|res:' . (int)($it['id_reserva'] ?? 0);
    }
  }
  $seen = casanova_bonos_update_seen($user_id, $keys);

  if (empty($groups)) {
    return '<div class="casanova-card casanova-card--empty"><div class="casanova-card__body"><div class="casanova-muted">' . esc_html__('No hay', 'casanova-portal') . ' bonos disponibles todavía.</div><div class="casanova-small">Los bonos aparecerán cuando el viaje esté pagado.</div></div></div>';
  }

  $out = '<div class="casanova-bonos">';
  foreach ($groups as $g) {
    $exp = $g['exp'];
    $items = $g['items'] ?? [];
    $idExp = (int)($exp->IdExpediente ?? $exp->Id ?? 0);
    $titulo = trim((string)($exp->Titulo ?? ''));
    $codigo = trim((string)($exp->Codigo ?? ''));
    $labelExp = $titulo ?: ($codigo ? (sprintf(__('Expediente %s', 'casanova-portal'), $codigo)) : (sprintf(__('Expediente %s', 'casanova-portal'), $idExp)));

    // Calcula si hay nuevos para este expediente
    $new_count = 0;
    foreach ($items as $it) {
      $k = 'exp:' . $idExp . '|res:' . (int)($it['id_reserva'] ?? 0);
      $ts = isset($seen[$k]) ? (int)$seen[$k] : 0;
      if ($ts >= $cut) $new_count++;
    }
    if ($only_recent && $new_count === 0) continue;

    $exp_badge = '';
    if ($new_count > 0) $exp_badge = '<span class="casanova-pill casanova-pill--new">' . esc_html__('Nuevo', 'casanova-portal') . '</span>';

    $out .= '<div class="casanova-card casanova-card--bonos">';
    $out .= '<div class="casanova-card__head">';
    $out .= '<div class="casanova-card__title">' . esc_html($labelExp) . '</div>';
    $out .= '<div class="casanova-card__meta">' . ($codigo ? '<span class="casanova-muted">Código: ' . esc_html($codigo) . '</span>' : '') . $exp_badge . '</div>';
    $out .= '</div>';
    $out .= '<div class="casanova-card__body">';

    $out .= '<div class="casanova-list">';
    foreach ($items as $it) {
      $range = function_exists('casanova_fmt_date_range') ? casanova_fmt_date_range($it['from'], $it['to']) : '';
      $out .= '<div class="casanova-list__row">';
      $out .= '<div class="casanova-list__main">';
      $out .= '<div class="casanova-list__title">' . esc_html((string)$it['label']) . '</div>';
      if ($range) $out .= '<div class="casanova-list__sub casanova-muted">' . esc_html($range) . '</div>';
      $out .= '</div>';
      $out .= '<div class="casanova-list__actions">';
      if (!empty($it['view_url'])) $out .= '<a class="casanova-btn casanova-btn--ghost" href="' . esc_url($it['view_url']) . '">' . esc_html__('Ver', 'casanova-portal') . '</a>';
      if (!empty($it['pdf_url']))  $out .= '<a class="casanova-btn casanova-btn--primary" href="' . esc_url($it['pdf_url']) . '">' . esc_html__('PDF', 'casanova-portal') . '</a>';
      $out .= '</div>';
      $out .= '</div>';
    }
    $out .= '</div>';

    $out .= '</div></div>';
  }
  $out .= '</div>';

  return $out;
});