<?php

/**
 * GIAV: buscar expedientes de un cliente
 * Usa Expediente_SEARCH con idsCliente (ArrayOfInt)
 * @param int $year (Opcional) Filtra por año de salida (1 de Ene a 31 de Dic).
 */
function casanova_giav_expedientes_por_cliente(int $idCliente, int $pageSize = 50, int $pageIndex = 0, ?int $year = null) {
  // GIAV limita pageSize a 100 (hard cap).
  $pageSize  = max(1, min(100, (int)$pageSize));
  $pageIndex = max(0, (int)$pageIndex);

  // Clave de caché incluye el año para evitar mezclar listados
  $cache_key = 'giav:expedientes_por_cliente:' . $idCliente . ':' . $pageSize . ':' . $pageIndex . ':' . ($year ?? 'all');

  // Cache corta (mejora UX y reduce llamadas SOAP)
  if (function_exists('casanova_cache_remember')) {
    return casanova_cache_remember(
      $cache_key,
      defined('CASANOVA_CACHE_TTL') ? (int)CASANOVA_CACHE_TTL : 300,
      function () use ($idCliente, $pageSize, $pageIndex, $year) {
        return casanova_giav_expedientes_por_cliente_uncached($idCliente, $pageSize, $pageIndex, $year);
      }
    );
  }

  return casanova_giav_expedientes_por_cliente_uncached($idCliente, $pageSize, $pageIndex, $year);
}

/**
 * Implementación real (sin cache). Separada para poder envolver con transients.
 */
function casanova_giav_expedientes_por_cliente_uncached(int $idCliente, int $pageSize = 50, int $pageIndex = 0, ?int $year = null) {

  $p = new stdClass();
  $p->apikey = CASANOVA_GIAV_APIKEY;

  // ✅ ArrayOfInt en formato SOAP
  $p->idsCliente = (object) ['int' => [$idCliente]];

  $p->pageSize  = $pageSize;
  $p->pageIndex = $pageIndex;

  // Filtro por FECHA
  $p->modoMultiFiltroFecha = 'Salida'; 

  if ($year && $year > 2000) {
    // CORRECCIÓN: Enviamos solo la fecha (yyyy-MM-dd) sin hora para evitar error SOAP
    $p->multiFiltroFechaDesde = $year . '-01-01';
    $p->multiFiltroFechaHasta = $year . '-12-31';
  } else {
    // Nillable explícito para no filtrar
    $p->multiFiltroFechaDesde = null;
    $p->multiFiltroFechaHasta = null;
  }

  $p->facturacionPendiente = 'NoAplicar';
  $p->cobroPendiente       = 'NoAplicar';
  $p->estadoCierre         = 'NoAplicar';
  $p->tipoExpediente       = 'NoAplicar';
  $p->recepcionCosteTotal  = 'NoAplicar';

  $resp = casanova_giav_call('Expediente_SEARCH', $p);
  if (is_wp_error($resp)) return $resp;

  $result = $resp->Expediente_SEARCHResult ?? null;
  if (empty($result)) return [];

  if (is_object($result) && isset($result->WsExpediente)) {
    $items = $result->WsExpediente;
    if (is_array($items)) return $items;
    if (is_object($items)) return [$items];
  }

  return [];
}


/**
 * Shortcode: lista expedientes del usuario logueado con filtro de año.
 */
add_shortcode('casanova_expedientes', function($atts) {
  if (!is_user_logged_in()) return '<p>' . esc_html__('Debes iniciar sesión.', 'casanova-portal') . '</p>';

  $user_id   = get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);

  if (!$idCliente) {
    return '<p>' . esc_html__('No tienes la cuenta vinculada todavía.', 'casanova-portal') . '</p>';
  }

  // --- Lógica del Filtro Inteligente ---
  $current_year = (int)date('Y');
  
  // 1. Intentamos coger el periodo de la URL
  $selected_year = isset($_GET['periodo']) ? (int)$_GET['periodo'] : 0;

  // 2. Si no hay periodo pero sí hay expediente (ej. vengo del Dashboard),
  // detectamos el año de ese expediente para mostrarlo.
  if ($selected_year <= 0 && isset($_GET['expediente'])) {
    $reqExp = (int)$_GET['expediente'];
    if ($reqExp > 0 && function_exists('casanova_giav_expediente_get')) {
      $e = casanova_giav_expediente_get($reqExp);
      if ($e && !is_wp_error($e)) {
        // Usamos FechaInicio (o FechaDesde) para determinar el año
        $d = $e->FechaInicio ?? $e->FechaDesde ?? null;
        if ($d) {
          $ts = strtotime((string)$d);
          if ($ts) {
            $y = (int)date('Y', $ts);
            if ($y > 2000) $selected_year = $y;
          }
        }
      }
    }
  }

  // 3. Fallback: Año actual
  if ($selected_year <= 0) {
    $selected_year = $current_year;
  }
  
  // Rango de años para el select
  $years = range($current_year + 1, 2020); 

  // Petición a GIAV filtrada
  $items = casanova_giav_expedientes_por_cliente($idCliente, 50, 0, ($selected_year > 0 ? $selected_year : null));

  // --- Renderizado ---
  ob_start();
  
  // Barra de herramientas (Filtro)
  echo '<div class="casanova-toolbar" style="margin-bottom:20px; display:flex; align-items:center; justify-content:flex-end;">';
    echo '<form method="get" class="casanova-filter-form" style="display:flex; align-items:center; gap:10px;">';
      echo '<input type="hidden" name="view" value="expedientes">';
      
      // CAMBIO: Eliminamos el input hidden de "expediente".
      // Al cambiar de año (submit del form), la URL se limpiará de ?expediente=...
      // y se mostrará solo el listado del año seleccionado, sin detalle activo.
      
      echo '<label for="periodo-select" style="font-weight:600; font-size:14px;">' . esc_html__('Año:', 'casanova-portal') . '</label>';
      
      // Select sin onchange (manejado por JS portal.js para el overlay)
      echo '<select name="periodo" id="periodo-select" style="padding:6px 30px 6px 12px; border-radius:8px; border:1px solid #ddd; font-weight:600;">';
        foreach ($years as $y) {
          echo '<option value="' . esc_attr($y) . '" ' . selected($selected_year, $y, false) . '>' . esc_html($y) . '</option>';
        }
      echo '</select>';
    echo '</form>';
  echo '</div>';

  if (is_wp_error($items)) {
    echo '<div class="casanova-alert casanova-alert--warn">' . esc_html__('No se han podido cargar los expedientes. Inténtalo más tarde.', 'casanova-portal') . '</div>';
    return ob_get_clean();
  }

  if (empty($items)) {
    echo '<div class="casanova-card casanova-card--empty"><div class="casanova-card__body" style="text-align:center; padding:40px;">';
    echo '<div class="casanova-muted">' . sprintf(esc_html__('No hay viajes registrados en %s.', 'casanova-portal'), $selected_year) . '</div>';
    echo '</div></div>';
    return ob_get_clean();
  }

  // Ordenar y renderizar
  usort($items, function($a, $b) {
    $da = isset($a->FechaInicio) ? strtotime((string)$a->FechaInicio) : (isset($a->FechaCreacion) ? strtotime((string)$a->FechaCreacion) : 0);
    $db = isset($b->FechaInicio) ? strtotime((string)$b->FechaInicio) : (isset($b->FechaCreacion) ? strtotime((string)$b->FechaCreacion) : 0);
    return $db <=> $da;
  });

  $active_id = isset($_GET['expediente']) ? (int)$_GET['expediente'] : 0;

  echo '<div class="casanova-portal casanova-expedientes casanova-expedientes--drawer">';
  foreach ($items as $e) {
    $id      = (int) ($e->Id ?? 0);
    $codigo  = esc_html($e->Codigo ?? '');
    $titulo  = esc_html($e->Titulo ?? '');
    $destino = esc_html($e->Destino ?? '');
    $cerrado = !empty($e->Cerrado) ? 'Cerrado' : 'Abierto';
    $state_cls = !empty($e->Cerrado) ? 'is-closed' : 'is-open';

    $desde = !empty($e->FechaDesde) ? date_i18n('d/m/Y', strtotime((string)$e->FechaDesde)) : '';
    $hasta = !empty($e->FechaHasta) ? date_i18n('d/m/Y', strtotime((string)$e->FechaHasta)) : '';
    $rango = trim($desde . ($hasta ? ' – ' . $hasta : ''));

    $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');
    
    $view = 'expedientes';
    $url  = add_query_arg([
      'view' => $view, 
      'expediente' => $id,
      'periodo' => $selected_year
    ], $base);

    $is_active = ($active_id > 0 && $id === $active_id);
    $cls = 'casanova-expediente-card' . ($is_active ? ' is-active' : '');

    echo '<a class="' . esc_attr($cls) . '" data-casanova-expediente-link href="' . esc_url($url) . '">';
      echo '<div class="casanova-expediente-card__row">';
        echo '<div class="casanova-expediente-card__main">';
          echo '<div class="casanova-expediente-card__title">' . esc_html($titulo ?: (sprintf(__('Expediente %s', 'casanova-portal'), $codigo))) . '</div>';
          echo '<div class="casanova-expediente-card__meta">' . $codigo . ($destino ? ' · ' . $destino : '') . ($rango ? ' · ' . $rango : '') . '</div>';
        echo '</div>';
        echo '<div class="casanova-expediente-card__right">';
          echo '<div class="casanova-expediente-card__state ' . esc_attr($state_cls) . '">' . esc_html($cerrado) . '</div>';
          echo '<div class="casanova-expediente-card__chev" aria-hidden="true">›</div>';
        echo '</div>';
      echo '</div>';
    echo '</a>';
  }
  echo '</div>';

  return ob_get_clean();
});

add_shortcode('casanova_expediente_header', function() {
  if (!is_user_logged_in()) return '';

  $user_id   = (int) get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);

  $idExp = isset($_GET['expediente']) ? (int)$_GET['expediente'] : 0;

  $title = '' . esc_html__('Selecciona un expediente para ver su detalle.', 'casanova-portal') . '';
  $meta  = '';

  if ($idExp > 0 && function_exists('casanova_giav_expediente_get')) {
    $exp = casanova_giav_expediente_get($idExp);
    if (!is_wp_error($exp) && is_object($exp)) {
      if (!isset($exp->IdCliente) || (int)$exp->IdCliente === $idCliente) {
        $codigo = trim((string)($exp->Codigo ?? ''));
        $titulo = trim((string)($exp->Titulo ?? ''));
        $dest   = trim((string)($exp->Destino ?? ''));

        $desde = !empty($exp->FechaDesde) ? date_i18n('d/m/Y', strtotime((string)$exp->FechaDesde)) : '';
        $hasta = !empty($exp->FechaHasta) ? date_i18n('d/m/Y', strtotime((string)$exp->FechaHasta)) : '';
        $rango = trim($desde . ($hasta ? ' – ' . $hasta : ''));

        $title = $titulo !== '' ? $titulo : ($codigo !== '' ? (sprintf(__('Expediente %s', 'casanova-portal'), $codigo)) : (sprintf(__('Expediente %s', 'casanova-portal'), $idExp)));
        $meta_parts = array_filter([$codigo, $dest, $rango]);
        $meta = !empty($meta_parts) ? implode(' · ', $meta_parts) : '';
      }
    }
  }

  $is_empty = ($idExp <= 0);

  $html  = '<div class="casanova-exp-header'.($is_empty ? ' casanova-exp-header--empty' : '').'">';
  $html .= '  <button type="button" class="casanova-exp-header__btn" data-casanova-open-drawer aria-label="Abrir lista de expedientes">'
        . ($is_empty ? 'Elegir expediente' : '' . esc_html__('Expedientes', 'casanova-portal') . '')
        . ' <span class="casanova-exp-header__chev" aria-hidden="true">▾</span>'
        . '</button>';
  $html .= '  <div class="casanova-exp-header__text">';
  $html .= '    <div class="casanova-expediente-head__title">' . esc_html($title) . '</div>';
  if ($meta !== '') {
    $html .= '    <div class="casanova-exp-header__meta">' . esc_html($meta) . '</div>';
  }
  if ($is_empty) {
    $html .= '    <div class="casanova-exp-header__meta">' . esc_html__('Usa el botón para ver tu lista de expedientes.', 'casanova-portal') . '</div>';
  }
  $html .= '  </div>';
  $html .= '</div>';

  $html .= '<div class="casanova-drawer-backdrop" data-casanova-close-drawer></div>';

  return $html;
});

/**
 * Trae un expediente por ID.
 * OPTIMIZADO: Con caché para que la auto-detección del año en el listado sea instantánea.
 */
function casanova_giav_expediente_get(int $idExpediente) {
  if (function_exists('casanova_cache_remember')) {
    return casanova_cache_remember(
      'giav:expediente_get:' . $idExpediente,
      300, // 5 min cache
      function () use ($idExpediente) {
        return casanova_giav_expediente_get_uncached($idExpediente);
      }
    );
  }
  return casanova_giav_expediente_get_uncached($idExpediente);
}

function casanova_giav_expediente_get_uncached(int $idExpediente) {
  $p = new stdClass();
  $p->apikey = CASANOVA_GIAV_APIKEY;
  $p->idsExpediente = (object) ['int' => [(int)$idExpediente]];

  $p->modoMultiFiltroFecha   = 'Salida';
  $p->multiFiltroFechaDesde  = null;
  $p->multiFiltroFechaHasta  = null;

  $p->facturacionPendiente = 'NoAplicar';
  $p->cobroPendiente       = 'NoAplicar';
  $p->estadoCierre         = 'NoAplicar';
  $p->tipoExpediente       = 'NoAplicar';
  $p->recepcionCosteTotal  = 'NoAplicar';

  $p->pageSize  = 10;
  $p->pageIndex = 0;

  $resp = casanova_giav_call('Expediente_SEARCH', $p);
  if (is_wp_error($resp)) return $resp;

  $result = $resp->Expediente_SEARCHResult ?? null;
  if (empty($result)) return null;

  if (is_object($result) && isset($result->WsExpediente)) {
    $items = $result->WsExpediente;
    if (is_array($items)) return $items[0] ?? null;
    if (is_object($items)) return $items;
  }

  return null;
}

add_shortcode('casanova_expediente_detalle', function() {
  if (!is_user_logged_in()) return '<p>' . esc_html__('Debes iniciar sesión.', 'casanova-portal') . '</p>';

  $user_id   = get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);

  if (!$idCliente) return '<p>' . esc_html__('No tienes la cuenta vinculada todavía.', 'casanova-portal') . '</p>';

  $idExp = isset($_GET['expediente']) ? (int) $_GET['expediente'] : 0;
  if (!$idExp) return '';

  $exp = casanova_giav_expediente_get($idExp);

  if (is_wp_error($exp)) return '<p>' . esc_html__('No se ha podido cargar el expediente.', 'casanova-portal') . '</p>';
  if (!$exp) return '<p>' . esc_html__('Expediente no encontrado.', 'casanova-portal') . '</p>';

  if (isset($exp->IdCliente) && (int)$exp->IdCliente !== $idCliente) {
    return '<p>' . esc_html__('No tienes acceso a este expediente.', 'casanova-portal') . '</p>';
  }

  $codigo  = esc_html($exp->Codigo ?? '');
  $titulo  = esc_html($exp->Titulo ?? '');
  $destino = esc_html($exp->Destino ?? '');
  $cerrado = !empty($exp->Cerrado) ? 'Cerrado' : 'Abierto';

  $desde = !empty($exp->FechaDesde) ? date_i18n('d/m/Y', strtotime((string)$exp->FechaDesde)) : '';
  $hasta = !empty($exp->FechaHasta) ? date_i18n('d/m/Y', strtotime((string)$exp->FechaHasta)) : '';
  $rango = trim($desde . ($hasta ? ' – ' . $hasta : ''));

  $obs = '';
  if (!empty($exp->Observaciones)) {
    $obs = wp_kses_post(nl2br(esc_html((string)$exp->Observaciones)));
  }

  // Mantenemos el parámetro 'periodo' al volver atrás
  $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');
  $backArgs = ['view' => 'expedientes'];
  if (!empty($_GET['periodo'])) $backArgs['periodo'] = (int)$_GET['periodo'];
  
  $backUrl = add_query_arg($backArgs, $base);

  ob_start();
  echo '<div class="casanova-portal">';
  echo '<section class="casanova-card casanova-expediente-detalle">';
    echo '<div class="casanova-expediente-head">';
      echo '<div class="casanova-expediente-head__main">';
        echo '<div class="casanova-expediente-head__title">' . ($titulo ?: (sprintf(__('Expediente %s', 'casanova-portal'), $codigo))) . '</div>';
        echo '<div class="casanova-expediente-head__meta">' . $codigo . ($destino ? ' · ' . $destino : '') . ($rango ? ' · ' . $rango : '') . '</div>';
      echo '</div>';
      echo '<div class="casanova-expediente-head__state">' . esc_html($cerrado) . '</div>';
    echo '</div>';

    if ($obs) {
      echo '<div class="casanova-divider"></div>';
      echo '<div class="casanova-subtitle">' . esc_html__('Observaciones', 'casanova-portal') . '</div>';
      echo '<div class="casanova-expediente-obs">' . $obs . '</div>';
    }

    echo '<div class="casanova-expediente-back">';
      echo '<a class="casanova-link" href="' . esc_url($backUrl) . '">' . esc_html__('← Volver a mis expedientes', 'casanova-portal') . '</a>';
    echo '</div>';
  echo '</section>';
  echo '</div>';

  return ob_get_clean();
});