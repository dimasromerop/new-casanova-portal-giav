<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================
 * GIAV: Reservas por expediente
 * ============================
 */
function casanova_giav_reservas_por_expediente(int $idExpediente, int $idCliente, int $pageSize = 100, int $pageIndex = 0) {

  // Cache corta para mejorar rendimiento del portal.
  if (function_exists('casanova_cache_remember')) {
    return casanova_cache_remember(
      'giav:reservas_por_expediente:' . (int)$idExpediente . ':' . (int)$idCliente . ':' . (int)$pageSize . ':' . (int)$pageIndex,
      defined('CASANOVA_CACHE_TTL') ? (int)CASANOVA_CACHE_TTL : 90,
      function () use ($idExpediente, $idCliente, $pageSize, $pageIndex) {
        return casanova_giav_reservas_por_expediente_uncached($idExpediente, $idCliente, $pageSize, $pageIndex);
      }
    );
  }

  return casanova_giav_reservas_por_expediente_uncached($idExpediente, $idCliente, $pageSize, $pageIndex);
}

/**
 * Implementación real (sin cache).
 */
function casanova_giav_reservas_por_expediente_uncached(int $idExpediente, int $idCliente, int $pageSize = 100, int $pageIndex = 0) {

  $pageSize  = max(1, min(100, (int)$pageSize));
  $pageIndex = max(0, (int)$pageIndex);

  $p = new stdClass();
  $p->apikey = defined('CASANOVA_GIAV_APIKEY') ? CASANOVA_GIAV_APIKEY : '';

  // Filtros principales
  $p->idsExpediente = (object) ['int' => [(int)$idExpediente]];

  // Defensa: evita reservas ajenas por edge cases
  if ($idCliente > 0) {
    $p->idsCliente = (object) ['int' => [(int)$idCliente]];
  }

  // Enums válidos
  $p->facturacionPendiente = 'NoAplicar';
  $p->cobroPendiente       = 'NoAplicar';
  $p->prepagoPendiente     = 'NoAplicar';
  $p->recepcionCosteTotal  = 'NoAplicar';

  // OJO: si mandas modofiltroImporte, NO puedes dejar importeHasta = 0
  $p->modofiltroImporte = 'venta';
  $p->importeDesde = 0;
  $p->importeHasta = 9999999;

  $p->fechaReserva = 'creacion';
  $p->modoFiltroLocalizadorPedido = 'LOCPED';

  $p->pageSize  = $pageSize;
  $p->pageIndex = $pageIndex;

  if (!function_exists('casanova_giav_call')) {
    return new WP_Error('giav_missing', 'GIAV call helper missing');
  }

  $resp = casanova_giav_call('Reservas_SEARCH', $p);
  if (is_wp_error($resp)) return $resp;

  $result = $resp->Reservas_SEARCHResult ?? null;
  if (!function_exists('casanova_giav_normalize_list')) return [];

  return casanova_giav_normalize_list($result, 'WsReserva');
}

/**
 * ============================
 * GIAV: Cobros por expediente
 * ============================
 */
function casanova_giav_cobros_por_expediente(int $idExpediente, int $idCliente, int $pageSize = 100, int $pageIndex = 0) {

  $pageSize  = max(1, min(100, (int)$pageSize));
  $pageIndex = max(0, (int)$pageIndex);

  $p = new stdClass();
  $p->apikey = defined('CASANOVA_GIAV_APIKEY') ? CASANOVA_GIAV_APIKEY : '';

  // OJO: idsExpedientes (plural)
  $p->idsExpedientes = (object) ['int' => [(int)$idExpediente]];

  if ($idCliente > 0) {
    $p->idsCliente = (object) ['int' => [(int)$idCliente]];
  }

  // Obligatorios según esquema
  $p->idBankAccount = null;
  $p->modoImporte   = 'Importe';
  $p->importeDesde  = null;
  $p->importeHasta  = null;

  $p->traspasado = 'NoAplicar';
  $p->idsTraspaso = null;

  $p->conciliado = 'NoAplicar';
  $p->idsEntitiesStages = null;

  $p->fechaCobroDesde = null;
  $p->fechaCobroHasta = null;

  $p->fechaHoraModificacionDesde = null;
  $p->fechaHoraModificacionHasta = null;

  $p->customDataValues = null;

  $p->pageSize  = $pageSize;
  $p->pageIndex = $pageIndex;

  if (!function_exists('casanova_giav_call')) {
    return new WP_Error('giav_missing', 'GIAV call helper missing');
  }

  $resp = casanova_giav_call('Cobro_SEARCH', $p);
  if (is_wp_error($resp)) return $resp;

  $result = $resp->Cobro_SEARCHResult ?? null;
  if (!function_exists('casanova_giav_normalize_list')) return [];

  $items = casanova_giav_normalize_list($result, 'WsCobro');

  // Filtro defensivo: GIAV a veces devuelve cobros del cliente entero
  $items = array_values(array_filter($items, function($c) use ($idExpediente) {
    return is_object($c) && (int)($c->IdExpediente ?? 0) === (int)$idExpediente;
  }));

  return $items;
}

function casanova_giav_cobros_por_expediente_all(int $idExpediente, int $idCliente): array|WP_Error {
  // Usamos un TTL de 5 minutos (300s). Los pagos no cambian cada segundo.
  if (function_exists('casanova_cache_remember')) {
    return casanova_cache_remember(
      'giav:cobros_all:' . $idExpediente . ':' . $idCliente,
      300, 
      function() use ($idExpediente, $idCliente) {
        return casanova_giav_cobros_por_expediente_all_uncached($idExpediente, $idCliente);
      }
    );
  }
  return casanova_giav_cobros_por_expediente_all_uncached($idExpediente, $idCliente);
}

// Renombramos la original a _uncached y la dejamos igual (copia tu lógica interna aquí)
function casanova_giav_cobros_por_expediente_all_uncached(int $idExpediente, int $idCliente): array|WP_Error {
  $all = [];
  $pageIndex = 0;

  while (true) {
    // Nota: llamamos a la función unitaria (que ya existe en tu código)
    $chunk = casanova_giav_cobros_por_expediente($idExpediente, $idCliente, 100, $pageIndex);
    if (is_wp_error($chunk)) return $chunk;
    if (empty($chunk)) break;

    $all = array_merge($all, $chunk);

    if (count($chunk) < 100) break;
    $pageIndex++;
    if ($pageIndex > 50) break; // safety
  }

  return $all;
}

/**
 * ==========================================
 * Cálculo pago expediente (ventas - cobros)
 * ==========================================
 */
function casanova_calc_pago_expediente(int $idExpediente, int $idCliente, array $reservas) {

  // Índice por Id para detectar padres válidos
  $byId = [];
  foreach ($reservas as $r) {
    $rid = (int)($r->Id ?? 0);
    if ($rid) $byId[$rid] = $r;
  }

  $tiene_padre_valido = function($r) use ($byId): bool {
    $pid = (int)($r->Anidacion_IdReservaContenedora ?? 0);
    return ($pid > 0 && isset($byId[$pid]));
  };

  // PQ roots
  $pq_roots = [];
  foreach ($reservas as $r) {
    $tipo = (string)($r->TipoReserva ?? '');
    if ($tipo === 'PQ' && !$tiene_padre_valido($r)) {
      $pq_roots[] = $r;
    }
  }

  // 1) Total objetivo (venta)
  $total_objetivo = 0.0;

  if (!empty($pq_roots)) {
    foreach ($pq_roots as $pq) {
      $total_objetivo += (float)($pq->Venta ?? 0);
    }
    foreach ($reservas as $r) {
      $tipo = (string)($r->TipoReserva ?? '');
      if ($tipo !== 'PQ' && !$tiene_padre_valido($r)) {
        $total_objetivo += (float)($r->Venta ?? 0);
      }
    }
  } else {
    foreach ($reservas as $r) {
      if (!$tiene_padre_valido($r)) {
        $total_objetivo += (float)($r->Venta ?? 0);
      }
    }
  }

  // 2) Pagado real (Cobros)
  $pagado_real = 0.0;
  $reemb_real  = 0.0;

  $all = [];
  $pageIndex = 0;
  while (true) {
    $chunk = casanova_giav_cobros_por_expediente($idExpediente, $idCliente, 100, $pageIndex);
    if (is_wp_error($chunk)) { $chunk = []; break; }
    if (empty($chunk)) break;

    $all = array_merge($all, $chunk);
    if (count($chunk) < 100) break;
    $pageIndex++;
    if ($pageIndex > 50) break;
  }

  $all = array_values(array_filter($all, function($c) use ($idExpediente){
    return is_object($c) && (int)($c->IdExpediente ?? 0) === (int)$idExpediente;
  }));

  foreach ($all as $c) {
    $importe = (float)($c->Importe ?? 0);
    $tipoOp = strtoupper(trim((string)($c->TipoOperacion ?? '')));

    $isReembolso =
      ($tipoOp === 'REEMBOLSO')
      || (strpos($tipoOp, 'REEM') !== false)
      || (strpos($tipoOp, 'DEV') !== false);

    // DEFENSIVO: si TipoOperacion viene vacío, NO uses abs() a ciegas.
    if ($tipoOp === '') {
      if ($importe >= 0) $pagado_real += $importe;
      else $reemb_real += abs($importe);
      continue;
    }

    $abs = abs($importe);
    if ($isReembolso) $reemb_real += $abs;
    else $pagado_real += $abs;
  }

  $pagado_neto = $pagado_real - $reemb_real;
  if ($pagado_neto < 0) $pagado_neto = 0;

  // Fallback: TotalCobrosPasajeros en roots si no hay Cobro_SEARCH
  if ($pagado_neto <= 0.0001) {
    $fallback = 0.0;

    if (!empty($pq_roots)) {
      foreach ($pq_roots as $pq) {
        if (isset($pq->DatosExternos) && is_object($pq->DatosExternos) && isset($pq->DatosExternos->TotalCobrosPasajeros)) {
          $fallback += (float)$pq->DatosExternos->TotalCobrosPasajeros;
        }
      }
      foreach ($reservas as $r) {
        $tipo = (string)($r->TipoReserva ?? '');
        if ($tipo !== 'PQ' && !$tiene_padre_valido($r)) {
          if (isset($r->DatosExternos) && is_object($r->DatosExternos) && isset($r->DatosExternos->TotalCobrosPasajeros)) {
            $fallback += (float)$r->DatosExternos->TotalCobrosPasajeros;
          }
        }
      }
    } else {
      foreach ($reservas as $r) {
        if (!$tiene_padre_valido($r)) {
          if (isset($r->DatosExternos) && is_object($r->DatosExternos) && isset($r->DatosExternos->TotalCobrosPasajeros)) {
            $fallback += (float)$r->DatosExternos->TotalCobrosPasajeros;
          }
        }
      }
    }

    if ($fallback > 0.0001) $pagado_neto = $fallback;
  }

  // 3) Pendiente real
  $pendiente_real = $total_objetivo - $pagado_neto;
  if ($pendiente_real < 0) $pendiente_real = 0;

  $expediente_pagado = ($pendiente_real <= 0.01);

  return [
    'total_objetivo' => $total_objetivo,
    'pagado' => $pagado_neto,
    'pendiente_real' => $pendiente_real,
    'expediente_pagado' => $expediente_pagado,
    'cobros_count' => count($all),
  ];
}

/**
 * ============================
 * Contexto portal (usuario + expediente)
 * ============================
 * Devuelve array con idCliente, idExpediente, reservas, pago, pay_url
 * o WP_Error.
 */
function casanova_portal_expediente_context(): array|WP_Error {

  if (!is_user_logged_in()) return new WP_Error('auth', esc_html__('Debes iniciar sesión.', 'casanova-portal'));

  $user_id   = (int) get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if ($idCliente <= 0) return new WP_Error('no_link', 'Tu cuenta no está vinculada todavía.');

  $idExpediente = isset($_GET['expediente']) ? (int) $_GET['expediente'] : 0;
  if ($idExpediente <= 0) return new WP_Error('no_expediente', __('Selecciona un expediente para ver su detalle.', 'casanova-portal'));

  $reservas = casanova_giav_reservas_por_expediente($idExpediente, $idCliente);
  if (is_wp_error($reservas)) return new WP_Error('giav_reservas', 'No se han podido cargar las reservas. Inténtalo más tarde.');
  if (empty($reservas)) return new WP_Error('no_reservas', esc_html__('No hay reservas en este expediente.', 'casanova-portal'));

  // Orden por FechaDesde asc (si existe)
  usort($reservas, function($a, $b){
    $da = isset($a->FechaDesde) ? strtotime((string)$a->FechaDesde) : 0;
    $db = isset($b->FechaDesde) ? strtotime((string)$b->FechaDesde) : 0;
    return $da <=> $db;
  });

  $pago = casanova_calc_pago_expediente($idExpediente, $idCliente, $reservas);
  if (is_wp_error($pago)) return new WP_Error('giav_pago', 'No se ha podido calcular el estado de pagos.');

  if (function_exists('casanova_portal_payments_tick')) {
    casanova_portal_payments_tick($idExpediente, $idCliente, $reservas, $pago);
  }

  // Iniciar pago desde frontend (evita bloqueos a /wp-admin/ para no-admin)
  if (function_exists('casanova_portal_pay_expediente_url')) {
    $pay_url = casanova_portal_pay_expediente_url($idExpediente);
  } else {
    $pay_url = add_query_arg([
      'action' => 'casanova_pay_expediente',
      'expediente' => $idExpediente,
      '_wpnonce' => wp_create_nonce('casanova_pay_expediente_' . $idExpediente),
    ], admin_url('admin-post.php'));
  }

  
  // Mulligans usados (campo custom GIAV 2179) por expediente, para mostrar en resumen
  $mulligans_used = 0;
  if (function_exists('casanova_giav_expediente_get')) {
    $exp_obj = casanova_giav_expediente_get($idExpediente);
    if (!is_wp_error($exp_obj) && is_object($exp_obj)) {
      $v = null;
      if (function_exists('casanova_mulligans_giav_custom_value')) {
        $v = casanova_mulligans_giav_custom_value($exp_obj, 2179);
      } else {
        // fallback local (sin depender del módulo loyalty)
        $cdv = $exp_obj->customDataValues ?? ($exp_obj->CustomDataValues ?? null);
        $items = null;
        if (is_object($cdv) && isset($cdv->CustomDataItem)) $items = $cdv->CustomDataItem;
        elseif (is_array($cdv)) $items = $cdv;
        if ($items && !is_array($items)) $items = [$items];
        if (is_array($items)) {
          foreach ($items as $it) {
            if (!is_object($it)) continue;
            $k = $it->Key ?? ($it->key ?? null);
            if ((int)$k !== 2179) continue;
            $v = (string)($it->Value ?? ($it->value ?? ''));
            break;
          }
        }
      }
      if ($v !== null && $v !== '') {
        $mulligans_used = (int) round((float) str_replace(',', '.', (string)$v));
      }
    }
  }

return [
    'user_id' => $user_id,
    'idCliente' => $idCliente,
    'idExpediente' => $idExpediente,
    'reservas' => $reservas,
    'pago' => $pago,
    'pay_url' => $pay_url,
    'mulligans_used' => (int)$mulligans_used,
  ];
}

/**
 * ============================
 * Helpers de render (HTML limpio)
 * ============================
 */
function casanova_portal_render_summary(array $ctx): string {

  $pago = $ctx['pago'] ?? [];
  $reservas = $ctx['reservas'] ?? [];
  $idExpediente = (int)($ctx['idExpediente'] ?? 0);

  $total_objetivo     = (float)($pago['total_objetivo'] ?? 0);
  $total_pagado       = (float)($pago['pagado'] ?? 0);
  $pendiente_real     = (float)($pago['pendiente_real'] ?? 0);
  $expediente_pagado  = (bool)($pago['expediente_pagado'] ?? false);

  $html = '';
  $html .= '<section class="casanova-card casanova-summary">';
  $html .= '<div class="casanova-summary__grid">';
  $html .= '<div class="casanova-metric"><div class="casanova-metric__label">' . esc_html__('Total viaje', 'casanova-portal') . '</div><div class="casanova-metric__value">'.esc_html(casanova_fmt_money($total_objetivo)).'</div></div>';
  $html .= '<div class="casanova-metric"><div class="casanova-metric__label">' . esc_html__('Pagado', 'casanova-portal') . '</div><div class="casanova-metric__value casanova-metric__value--ok">'.esc_html(casanova_fmt_money($total_pagado)).'</div></div>';
  $html .= '<div class="casanova-metric"><div class="casanova-metric__label">' . esc_html__('Pendiente', 'casanova-portal') . '</div><div class="casanova-metric__value">'.esc_html(casanova_fmt_money($pendiente_real)).'</div></div>';
  $html .= '<div class="casanova-metric"><div class="casanova-metric__label">' . esc_html__('Mulligans usados', 'casanova-portal') . '</div><div class="casanova-metric__value">'.esc_html(number_format_i18n((int)($ctx['mulligans_used'] ?? 0))).'</div></div>';
$html .= '</div>';

  if ($expediente_pagado) {
    $html .= '<div class="casanova-pill casanova-pill--muted">' . esc_html__('Pagado', 'casanova-portal') . '</div>';
    $html .= '<div class="casanova-alert casanova-alert--ok">'
          . '<strong>' . esc_html__('Bonos disponibles.', 'casanova-portal') . '</strong> ' . esc_html__('En cada reserva podrás ver el bono y descargar el PDF.', 'casanova-portal') . ''
          . '</div>';
  } else {

    $pay_url = (string)($ctx['pay_url'] ?? '');
    $percent = function_exists('casanova_payments_get_deposit_percent') ? (float)casanova_payments_get_deposit_percent($idExpediente) : 10.0;

    $deposit_allowed = ($total_pagado <= 0.01) && (function_exists('casanova_payments_is_deposit_allowed') ? (bool)casanova_payments_is_deposit_allowed($reservas) : true);
    $deposit_amt = ($deposit_allowed && function_exists('casanova_payments_calc_deposit_amount'))
      ? (float)casanova_payments_calc_deposit_amount((float)$pendiente_real, $idExpediente)
      : 0.0;
    $deposit_effective = ($deposit_allowed && ($deposit_amt + 0.01 < (float)$pendiente_real));

    $html .= '<div class="casanova-actions">';

      if ($deposit_effective) {
        $url_dep = add_query_arg(['mode' => 'deposit'], $pay_url);
        $html .= '<a class="casanova-btn casanova-btn--dark" href="'.esc_url($url_dep).'">' . sprintf(esc_html__('Pagar depósito (%1$s%%): %2$s', 'casanova-portal'), esc_html(number_format($percent, 2, ',', '.')), esc_html(casanova_fmt_money($deposit_amt))) . '</a>';
      } else {
        $html .= '<span class="casanova-btn casanova-btn--disabled">' . esc_html__('Depósito no disponible', 'casanova-portal') . '</span>';
      }

      $url_full = add_query_arg(['mode' => 'full'], $pay_url);
      $html .= '<a class="casanova-btn casanova-btn--primary" href="'.esc_url($url_full).'">' . sprintf(esc_html__('Pagar total pendiente: %s', 'casanova-portal'), esc_html(casanova_fmt_money($pendiente_real))) . '</a>';

    $html .= '</div>';

    $html .= '<div class="casanova-alert casanova-alert--warn">';
      $html .= '<strong>' . esc_html__('Bonos no disponibles todavía.', 'casanova-portal') . '</strong> ' . sprintf(
        esc_html__('Pendiente de pago: %s', 'casanova-portal'),
        esc_html(casanova_fmt_money($pendiente_real))
      );
    $html .= '</div>';
  }

  $html .= '</section>';

  return $html;
}

function casanova_portal_render_cobros(array $ctx, bool $wrap_in_details = true): string {

  $idExpediente = (int)($ctx['idExpediente'] ?? 0);
  $idCliente = (int)($ctx['idCliente'] ?? 0);

  $cobros = casanova_giav_cobros_por_expediente_all($idExpediente, $idCliente);
  if (is_wp_error($cobros) || empty($cobros)) return '';

  usort($cobros, function($a, $b){
    $da = isset($a->FechaCobro) ? strtotime((string)$a->FechaCobro) : 0;
    $db = isset($b->FechaCobro) ? strtotime((string)$b->FechaCobro) : 0;
    return $da <=> $db;
  });

  $count = (int)count($cobros);

  $html = '';

  if ($wrap_in_details) {
    $html .= '<details class="casanova-card casanova-details">';
    $html .= '<summary class="casanova-details__summary">' . sprintf(esc_html__('Histórico de cobros (%s)', 'casanova-portal'), (string)$count) . '</summary>';
    $html .= '<div class="casanova-details__body">';
  } else {
    $html .= '<section class="casanova-card">';
    $html .= '<div class="casanova-section-title">' . sprintf(esc_html__('Histórico de cobros (%s)', 'casanova-portal'), (string)$count) . '</div>';
  }

  $html .= '<div class="casanova-tablewrap">';
  $html .= '<table class="casanova-table">';
  $html .= '<thead><tr>';
    $html .= '<th>' . esc_html__('Fecha', 'casanova-portal') . '</th><th>' . esc_html__('Tipo', 'casanova-portal') . '</th><th>' . esc_html__('Concepto', 'casanova-portal') . '</th><th>' . esc_html__('Pagador', 'casanova-portal') . '</th><th class="num">' . esc_html__('Importe', 'casanova-portal') . '</th>';
  $html .= '</tr></thead><tbody>';

  foreach ($cobros as $c) {
    if (!is_object($c)) continue;

    $fecha = !empty($c->FechaCobro) ? date_i18n('d/m/Y', strtotime((string)$c->FechaCobro)) : '';
    $importe = (float)($c->Importe ?? 0);

    $tipo = trim((string)($c->TipoOperacion ?? ''));
    $tipoUp = strtoupper($tipo);
    $isReembolso = (strpos($tipoUp, 'REEM') !== false || strpos($tipoUp, 'DEV') !== false);

    $badgeText = $tipo !== '' ? $tipo : ($isReembolso ? __('Reembolso', 'casanova-portal') : __('Cobro', 'casanova-portal'));
    $concepto = trim((string)($c->Concepto ?? ''));
    $doc      = trim((string)($c->Documento ?? ''));
    $pagador  = trim((string)($c->Pagador ?? ''));

    $conceptoFinal = $concepto !== '' ? $concepto : ($doc !== '' ? $doc : __('Pago', 'casanova-portal'));
    $importeTxt = ($isReembolso ? '-' : '') . casanova_fmt_money(abs($importe));

    $badgeClass = $isReembolso ? 'casanova-badge--refund' : 'casanova-badge--pay';

    $html .= '<tr>';
      $html .= '<td>'.esc_html($fecha).'</td>';
      $html .= '<td><span class="casanova-badge '.$badgeClass.'">'.esc_html($badgeText).'</span></td>';
      $html .= '<td>'.esc_html($conceptoFinal).'</td>';
      $html .= '<td>'.esc_html($pagador).'</td>';
      $html .= '<td class="num">'.esc_html($importeTxt).'</td>';
    $html .= '</tr>';
  }

  $html .= '</tbody></table></div>';
  $html .= '<div class="casanova-note">' . esc_html__('Nota: este listado refleja los cobros registrados en el expediente (cobros y reembolsos).', 'casanova-portal') . '</div>';

  if ($wrap_in_details) {
    $html .= '</div></details>';
  } else {
    $html .= '</section>';
  }

  return $html;
}

function casanova_portal_render_reserva_detalle(array $ctx): string {
  $reservas = $ctx['reservas'] ?? [];
  $selected = isset($_GET['reserva']) ? (int) $_GET['reserva'] : 0;
  if ($selected <= 0) return '';

  $found = null;
  foreach ($reservas as $rr) {
    if ((int)($rr->Id ?? 0) === $selected) { $found = $rr; break; }
  }
  if (!$found) return '';

  $tb = trim((string)($found->TextoBono ?? ''));

  $html = '<section class="casanova-card">';
  $html .= '<div class="casanova-section-title">' . esc_html__('Detalle de reserva', 'casanova-portal') . '</div>';
  $html .= '<div class="casanova-kv">';
    $html .= '<div><strong>' . esc_html__('Código:', 'casanova-portal') . '</strong> '.esc_html($found->Codigo ?? '').'</div>';
    $html .= '<div><strong>' . esc_html__('Tipo:', 'casanova-portal') . '</strong> '.esc_html($found->TipoReserva ?? '').'</div>';
    $html .= '<div><strong>' . esc_html__('Descripción:', 'casanova-portal') . '</strong> '.esc_html($found->Descripcion ?? '').'</div>';
    $html .= '<div><strong>' . esc_html__('Fechas:', 'casanova-portal') . '</strong> '.esc_html(casanova_fmt_date_range($found->FechaDesde ?? null, $found->FechaHasta ?? null)).'</div>';
    $html .= '<div><strong>' . esc_html__('Localizador:', 'casanova-portal') . '</strong> '.esc_html($found->Localizador ?? '').'</div>';
    $html .= '<div><strong>' . esc_html__('PVP:', 'casanova-portal') . '</strong> '.esc_html(casanova_fmt_money($found->Venta ?? 0)).'</div>';
  $html .= '</div>';

  if ($tb !== '') {
    $html .= '<div class="casanova-divider"></div>';
    $html .= '<div><strong>' . esc_html__('Texto adicional (bono):', 'casanova-portal') . '</strong></div>';
    $html .= '<div class="casanova-pre">'.esc_html($tb).'</div>';
  }

  $html .= '</section>';
  return $html;
}

function casanova_portal_render_reservas(array $ctx): string {

  $reservas = $ctx['reservas'] ?? [];
  $idExpediente = (int)($ctx['idExpediente'] ?? 0);
  $pago = $ctx['pago'] ?? [];
  $total_objetivo = (float)($pago['total_objetivo'] ?? 0);
  $pendiente_real = (float)($pago['pendiente_real'] ?? 0);

  // Índice por Id para detectar padres válidos
  $byId = [];
  foreach ($reservas as $r) {
    $rid = (int)($r->Id ?? 0);
    if ($rid) $byId[$rid] = $r;
  }

  $tiene_padre_valido = function($r) use ($byId): bool {
    $pid = (int)($r->Anidacion_IdReservaContenedora ?? 0);
    return ($pid > 0 && isset($byId[$pid]));
  };

  // ✅ FIX: solo PQ ROOTS (no PQ hijos)
  $pqs = [];
  foreach ($reservas as $r) {
    if ((string)($r->TipoReserva ?? '') !== 'PQ') continue;
    if ($tiene_padre_valido($r)) continue; // roots only
    $pqs[(int)($r->Id ?? 0)] = $r;
  }

  $children = []; // padreId => [hijos...]
  foreach ($reservas as $r) {
    $pid = (int)($r->Anidacion_IdReservaContenedora ?? 0);
    $rid = (int)($r->Id ?? 0);
    if ($pid > 0 && $rid > 0) $children[$pid][] = $r;
  }

  // Reservas "sueltas": sin padre válido
  $sueltas = [];
  foreach ($reservas as $r) {
    $pid = (int)($r->Anidacion_IdReservaContenedora ?? 0);
    $tienePadreValido = ($pid > 0 && isset($byId[$pid]));
    if (!$tienePadreValido) $sueltas[] = $r;
  }

  // Pendiente estimado por grupo (proporcional)
  $pending_estimate = function(float $subVenta) use ($total_objetivo, $pendiente_real): float {
    if ($total_objetivo <= 0.0001) return 0.0;
    $p = ($subVenta / $total_objetivo) * $pendiente_real;
    if ($p < 0) $p = 0;
    return round($p, 2);
  };

  $expediente_pagado = (bool)($pago['expediente_pagado'] ?? false);

  $voucher_urls = function(int $idReserva) use ($idExpediente): array {
    if ($idReserva <= 0) return ['view' => '', 'pdf' => ''];
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
  };

  // ✅ FIX: allow_voucher para suprimir bonos en PQ root
  // ✅ FIX: show_pvp para ocultar PVP en servicios anidados (suelen venir a 0 porque el precio está en el PQ)
  $render_item = function(
    $r,
    string $tag,
    ?string $extra = null,
    bool $allow_voucher = true,
    bool $show_pvp = true,
    string $class = ''
  ) use ($expediente_pagado, $voucher_urls): string {
    if (!is_object($r)) return '';
    $rid = (int)($r->Id ?? 0);
    $code = (string)($r->Codigo ?? '');
    $desc = (string)($r->Descripcion ?? '');
    $dates = casanova_fmt_date_range($r->FechaDesde ?? null, $r->FechaHasta ?? null);
    $venta_raw = (float)($r->Venta ?? 0);
    $pvp = casanova_fmt_money($venta_raw);
    $tb = trim((string)($r->TextoBono ?? ''));

    $u = $voucher_urls($rid);

    $cls = 'casanova-item' . ($class ? (' ' . trim($class)) : '');
    $html = '<details class="' . esc_attr($cls) . '">';
      $html .= '<summary class="casanova-item__summary">';
        $html .= '<div class="casanova-item__main">';
          $html .= '<div class="casanova-item__title">'.esc_html($code).'</div>';
          $html .= '<div class="casanova-item__desc">'.esc_html($desc).'</div>';
          $html .= '<div class="casanova-item__meta">'.esc_html($dates).'</div>';
        $html .= '</div>';
        $html .= '<div class="casanova-item__side">';
          if ($show_pvp) {
            $html .= '<div class="casanova-item__pvp">'.esc_html($pvp).'</div>';
          }
          $html .= '<div class="casanova-item__actions">';
            $html .= '<span class="casanova-chip">'.esc_html($tag).'</span>';

            // Botón explícito para que quede claro que hay más detalle (UX > telepatía)
            $html .= '<button type="button" class="casanova-btn casanova-btn--sm casanova-btn--ghost" data-casanova-toggle-details>' . esc_html__('Detalle', 'casanova-portal') . '</button>';

            if ($allow_voucher) {
              if ($expediente_pagado) {
                $html .= '<a class="casanova-btn casanova-btn--sm casanova-btn--ghost" href="'.esc_url($u['view']).'">' . esc_html__('Ver bono', 'casanova-portal') . '</a>';
                $html .= '<a class="casanova-btn casanova-btn--sm casanova-btn--ghost" href="'.esc_url($u['pdf']).'">' . esc_html__('PDF', 'casanova-portal') . '</a>';
              } else {
                $html .= '<span class="casanova-btn casanova-btn--sm casanova-btn--disabled">' . esc_html__('Bono', 'casanova-portal') . '</span>';
                $html .= '<span class="casanova-btn casanova-btn--sm casanova-btn--disabled">' . esc_html__('PDF', 'casanova-portal') . '</span>';
              }
            }

          $html .= '</div>';
        $html .= '</div>';
      $html .= '</summary>';

      $html .= '<div class="casanova-item__body">';
        $html .= '<div class="casanova-kv">';
          $html .= '<div><strong>' . esc_html__('Código:', 'casanova-portal') . '</strong> '.esc_html($code).'</div>';
          $html .= '<div><strong>' . esc_html__('Tipo:', 'casanova-portal') . '</strong> '.esc_html($r->TipoReserva ?? '').'</div>';
          $html .= '<div><strong>' . esc_html__('Fechas:', 'casanova-portal') . '</strong> '.esc_html($dates).'</div>';
          if (!empty($r->Localizador)) $html .= '<div><strong>' . esc_html__('Localizador:', 'casanova-portal') . '</strong> '.esc_html($r->Localizador).'</div>';
          if ($show_pvp) {
            $html .= '<div><strong>' . esc_html__('PVP:', 'casanova-portal') . '</strong> '.esc_html($pvp).'</div>';
          }
        $html .= '</div>';
        if ($extra) {
          $html .= '<div class="casanova-item__extra">'.$extra.'</div>';
        }
        if ($tb !== '') {
          $html .= '<div class="casanova-divider"></div>';
          $html .= '<div><strong>' . esc_html__('Texto adicional (bono):', 'casanova-portal') . '</strong></div>';
          $html .= '<div class="casanova-pre">'.esc_html($tb).'</div>';
        }
      $html .= '</div>';

    $html .= '</details>';
    return $html;
  };

  $html = '<section class="casanova-card">';
  $html .= '<div class="casanova-section-title">' . esc_html__('Reservas', 'casanova-portal') . '</div>';

  if (!empty($pqs)) {

    // PQ roots + hijos
    foreach ($pqs as $pqId => $pq) {
      $html .= '<div class="casanova-subsection">';
      $html .= '<div class="casanova-subtitle">' . esc_html__('Paquete', 'casanova-portal') . '</div>';

      // ✅ PQ root: PVP sí, bonos NO
      $html .= $render_item($pq, 'PQ', null, false, true);

      $kids = $children[$pqId] ?? [];
      if (!empty($kids)) {
        $subVenta = 0.0;
        foreach ($kids as $k) { $subVenta += (float)($k->Venta ?? 0); }

        $html .= '<div class="casanova-subtitle casanova-subtitle--small">' . esc_html__('Servicios incluidos', 'casanova-portal') . '';
        if ($pendiente_real > 0.01) {
          $html .= ' <span class="casanova-pill casanova-pill--muted">' . esc_html__('Pendiente estimado:', 'casanova-portal') . ' '.esc_html(casanova_fmt_money($pending_estimate((float)$pq->Venta + $subVenta))).'</span>';
        }
        $html .= '</div>';

        foreach ($kids as $k) {
          // Hijos del PQ: el precio suele venir a 0 (el PVP real está en el PQ root)
          // Además, añadimos una clase para poder indentar en CSS y dar contexto jerárquico.
          $html .= $render_item($k, (string)($k->TipoReserva ?? 'Servicio'), null, true, false, 'casanova-item--child');
        }
      }

      $html .= '</div>';
    }

    // ✅ FIX: también mostrar sueltas NO-PQ aunque haya PQ
    $otras = array_filter($sueltas, function($r) {
      return is_object($r) && (string)($r->TipoReserva ?? '') !== 'PQ';
    });

    if (!empty($otras)) {
      $html .= '<div class="casanova-subsection">';
      $html .= '<div class="casanova-subtitle">' . esc_html__('Otros servicios', 'casanova-portal') . '</div>';
      foreach ($otras as $r) {
        $html .= $render_item($r, (string)($r->TipoReserva ?? 'Servicio'));
      }
      $html .= '</div>';
    }

  } else {
    // Sin PQ
    $html .= '<div class="casanova-subsection">';
    $html .= '<div class="casanova-subtitle">' . esc_html__('Servicios', 'casanova-portal') . '</div>';
    foreach ($sueltas as $r) {
      $html .= $render_item($r, (string)($r->TipoReserva ?? 'Servicio'));
    }
    $html .= '</div>';
  }

  $html .= '</section>';
  return $html;
}

/**
 * ============================
 * Shortcodes (UX modular)
 * ============================
 */
add_shortcode('casanova_expediente_resumen', function() {
  $ctx = casanova_portal_expediente_context();
  if (is_wp_error($ctx)) return '<p>'.esc_html($ctx->get_error_message()).'</p>';
  return '<div class="casanova-portal">'.casanova_portal_render_summary($ctx).'</div>';
});

add_shortcode('casanova_expediente_cobros', function() {
  $ctx = casanova_portal_expediente_context();
  if (is_wp_error($ctx)) return '<p>'.esc_html($ctx->get_error_message()).'</p>';
  $html = casanova_portal_render_cobros($ctx, false);
  if ($html === '') {
    // Estado vacío: evita sección en blanco.
    $empty  = '<section class="casanova-card">';
    $empty .= '<div class="casanova-section-title">' . esc_html__('Histórico de cobros', 'casanova-portal') . '</div>';
    $empty .= '<div class="casanova-empty">' . esc_html__('Todavía no has realizado ningún pago en este viaje.', 'casanova-portal') . '</div>';
    $empty .= '</section>';
    return '<div class="casanova-portal">'.$empty.'</div>';
  }
  return '<div class="casanova-portal">'.$html.'</div>';
});

add_shortcode('casanova_expediente_reservas', function() {
  $ctx = casanova_portal_expediente_context();
  if (is_wp_error($ctx)) return '<p>'.esc_html($ctx->get_error_message()).'</p>';
  return '<div class="casanova-portal">'.casanova_portal_render_reservas($ctx).'</div>';
});

add_shortcode('casanova_expediente_reserva_detalle', function() {
  $ctx = casanova_portal_expediente_context();
  if (is_wp_error($ctx)) return '<p>'.esc_html($ctx->get_error_message()).'</p>';
  $html = casanova_portal_render_reserva_detalle($ctx);
  if ($html === '') return '';
  return '<div class="casanova-portal">'.$html.'</div>';
});

/**
 * ============================
 * Backwards compatible: [casanova_reservas]
 * ============================
 * Render “todo junto” pero con HTML limpio.
 */
add_shortcode('casanova_reservas', function () {

  $ctx = casanova_portal_expediente_context();
  if (is_wp_error($ctx)) return '<p>'.esc_html($ctx->get_error_message()).'</p>';

  $html  = '<div class="casanova-portal casanova-portal--stack">';
  $html .= casanova_portal_render_summary($ctx);

  // Cobros en modo "details" (compacto)
  $html .= casanova_portal_render_cobros($ctx, true);

  // Layout 2 columnas: reservas (izq) + detalle (der)
  $html .= '<div class="casanova-layout">';
    $html .= '<div class="casanova-col casanova-col--left">'.casanova_portal_render_reservas($ctx).'</div>';
    $html .= '<div class="casanova-col casanova-col--right">'.casanova_portal_render_reserva_detalle($ctx).'</div>';
  $html .= '</div>';

  $html .= '</div>';

  return $html;
});