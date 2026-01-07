<?php
if (!defined('ABSPATH')) exit;

/**
 * Recupera pasajeros de una reserva específica
 */
function casanova_giav_pasajeros_por_reserva(int $idReserva, int $idExpediente = 0): array {
  if ($idReserva <= 0) return [];

  $cache_key = 'casanova_pasajeros_reserva_' . $idReserva;
  $cached = get_transient($cache_key);
  if (is_array($cached)) return $cached;

  $res = casanova_giav_call('PasajeroReserva_SEARCH', [
    'idsExpediente'              => $idExpediente > 0 ? [$idExpediente] : null,
    'idsReservas'                => [$idReserva],
    'idsPasajeros'               => null,
    'idsPerfilesPlazaaPlaza'     => null,
    'idsRelacion'                => null,
    'fechaHoraModificacionDesde' => null,
    'fechaHoraModificacionHasta' => null,
    'pageSize'                   => 100,
    'pageIndex'                  => 0,
  ]);

  if (is_wp_error($res)) {
    set_transient($cache_key, [], 15 * MINUTE_IN_SECONDS);
    return [];
  }

  $result = $res->PasajeroReserva_SEARCHResult ?? null;
  $items = casanova_giav_normalize_list($result, 'WsPasajeroReserva');

  set_transient($cache_key, $items, 12 * HOUR_IN_SECONDS);
  return $items;
}

/**
 * Recupera TODOS los pasajeros del expediente
 */
function casanova_giav_pasajeros_por_expediente(int $idExpediente): array {
  if ($idExpediente <= 0) return [];

  $cache_key = 'casanova_pasajeros_expediente_' . $idExpediente;
  $cached = get_transient($cache_key);
  if (is_array($cached)) return $cached;

  $res = casanova_giav_call('PasajeroExpediente_SEARCH', [
    'idsExpediente' => [$idExpediente],
    'idsPasajeros'  => null,
    'idsRelacion'   => null,
    'pageSize'      => 100,
    'pageIndex'     => 0,
  ]);

  if (is_wp_error($res) || !is_object($res)) {
    set_transient($cache_key, [], 30 * MINUTE_IN_SECONDS);
    return [];
  }

  $root = $res->PasajeroExpediente_SEARCHResult ?? null;
  if (!$root) {
    set_transient($cache_key, [], 30 * MINUTE_IN_SECONDS);
    return [];
  }

  $items = casanova_giav_normalize_list($root, 'WsPasajeroExpediente');

  // Limpieza de objetos vacíos
  $items = array_values(array_filter($items, function($x) {
    return is_object($x) && !empty(get_object_vars($x));
  }));

  set_transient($cache_key, $items, 6 * HOUR_IN_SECONDS);
  return $items;
}

/**
 * Helper para bonos: intenta buscar por reserva, si falla, trae todos.
 */
function casanova_giav_pasajeros_para_bono(int $idReserva, int $idExpediente): array {
  $pas = casanova_giav_pasajeros_por_reserva($idReserva, $idExpediente);
  if (is_array($pas) && !empty($pas)) return $pas;
  return casanova_giav_pasajeros_por_expediente($idExpediente);
}

/**
 * SHORTCODE: [casanova_pasajeros]
 * Muestra lista de pasajeros del expediente actual.
 * OPTIMIZADO: Lógica de nombres alineada con portal-voucher.php
 */
add_shortcode('casanova_pasajeros', function($atts) {
  if (!is_user_logged_in()) return '';

  $idExp = isset($_GET['expediente']) ? (int)$_GET['expediente'] : 0;
  if (!$idExp) return '<p class="casanova-muted">' . esc_html__('No hay expediente seleccionado.', 'casanova-portal') . '</p>';

  $pasajeros = casanova_giav_pasajeros_por_expediente($idExp);

  if (empty($pasajeros)) {
    return '<div class="casanova-alert casanova-alert--info">' . esc_html__('No hay pasajeros registrados en este expediente.', 'casanova-portal') . '</div>';
  }

  ob_start();
  echo '<div class="casanova-card">';
  echo '<div class="casanova-section-title">' . esc_html__('Pasajeros', 'casanova-portal') . '</div>';
  echo '<div class="casanova-tablewrap">';
  echo '<table class="casanova-table">';
  echo '<thead><tr><th>' . esc_html__('Nombre', 'casanova-portal') . '</th><th>' . esc_html__('Tipo', 'casanova-portal') . '</th><th>' . esc_html__('Documento', 'casanova-portal') . '</th></tr></thead>';
  echo '<tbody>';

  foreach ($pasajeros as $p) {
    // --- LÓGICA MEJORADA DE EXTRACCIÓN DE NOMBRES (Igual que en Bonos) ---
    $dx = $p->DatosExternos ?? null;
    $nombre = '';

    // 1. Prioridad: DatosExternos (NombrePasajero o Nombre)
    if (is_object($dx)) {
        $nombre = trim((string)($dx->NombrePasajero ?? $dx->Nombre ?? ''));
    }

    // 2. Propiedades directas (NombrePasajero o Nombre)
    if ($nombre === '') {
        $nombre = trim((string)($p->NombrePasajero ?? $p->Nombre ?? ''));
    }

    // 3. Concatenación clásica (Nombre + Apellidos)
    if ($nombre === '') {
        $n = trim((string)($p->Nombre ?? ''));
        $a = trim((string)($p->Apellidos ?? ''));
        if ($n || $a) {
            $nombre = trim($n . ' ' . $a);
        }
    }

    // Fallback final
    if ($nombre === '') {
        $idp = (int)($p->IdPasajero ?? $p->Id ?? 0);
        $nombre = $idp > 0 ? sprintf(__('Pasajero #%d', 'casanova-portal'), $idp) : esc_html__('Pasajero', 'casanova-portal');
    }
    // ---------------------------------------------------------------------
    
    // Intentamos sacar el tipo (Adulto/Niño) o la edad
    $tipo = !empty($p->TipoPasajero) ? $p->TipoPasajero : '-';
    if (!empty($p->Edad)) $tipo .= ' (' . $p->Edad . ' años)';
    
    $doc = !empty($p->Documento) ? $p->Documento : '—';

    echo '<tr>';
    echo '<td><strong>' . esc_html($nombre) . '</strong></td>';
    echo '<td>' . esc_html($tipo) . '</td>';
    echo '<td>' . esc_html($doc) . '</td>';
    echo '</tr>';
  }

  echo '</tbody></table>';
  echo '</div></div>';

  return ob_get_clean();
});