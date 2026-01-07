<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================
 * Programa de fidelización: Mulligans
 * ==========================================
 *
 * Objetivo
 * - GIAV no ofrece puntos, así que los calculamos en WordPress a partir de cobros reales.
 * - Persistimos el resultado en user_meta para que JetEngine (metabox) lo pueda mostrar.
 *
 * Diseño
 * - 1€ pagado (neto) = 1 Mulligan (base)
 * - El "tier" depende del gasto histórico (lifetime spend), no de puntos canjeados.
 * - Los multiplicadores se muestran como "ratio de acumulación" para futuras compras.
 *   (No aplicamos multiplicador retroactivo al histórico, para evitar injusticias y líos.)
 */

// Metas
const CASANOVA_MULL_META_TOTAL       = 'casanova_mulligans_total';
const CASANOVA_MULL_META_SPEND       = 'casanova_mulligans_lifetime_spend';
const CASANOVA_MULL_META_TIER        = 'casanova_mulligans_tier';
const CASANOVA_MULL_META_LAST_SYNC   = 'casanova_mulligans_last_sync';


const CASANOVA_MULL_META_LEDGER      = 'casanova_mulligans_ledger';
const CASANOVA_MULL_META_EARNED      = 'casanova_mulligans_points_earned';
const CASANOVA_MULL_META_BONUS       = 'casanova_mulligans_points_bonus';
const CASANOVA_MULL_META_USED        = 'casanova_mulligans_points_used';
const CASANOVA_MULL_META_BALANCE     = 'casanova_mulligans_points_balance';

/**
 * Config tiers
 * Puedes ajustar umbrales cuando quieras.
 */
function casanova_mulligans_tiers(): array {
  return [
    'birdie'     => [ 'min' => 0,      'max' => 4999.99,  'mult' => 1.00, 'label' => 'Birdie' ],
    'eagle'      => [ 'min' => 5000,   'max' => 14999.99, 'mult' => 1.20, 'label' => 'Eagle' ],
    'eagle_plus' => [ 'min' => 15000,  'max' => 29999.99, 'mult' => 1.35, 'label' => 'Eagle+' ],
    'albatross'  => [ 'min' => 30000,  'max' => INF,      'mult' => 1.50, 'label' => 'Albatross' ],
  ];
}


function casanova_mulligans_tier_for_spend(float $spend): string {
  $tiers = casanova_mulligans_tiers();
  foreach ($tiers as $key => $cfg) {
    if ($spend >= (float)$cfg['min'] && $spend <= (float)$cfg['max']) return $key;
  }
  return 'birdie';
}

function casanova_mulligans_tier_cfg(string $tier): array {
  $tiers = casanova_mulligans_tiers();
  return $tiers[$tier] ?? $tiers['birdie'];
}

function casanova_mulligans_points_for_spend(float $spend): int {
  // Base: 1€ = 1 punto. Redondeo hacia abajo para ser conservadores.
  if ($spend <= 0) return 0;
  return (int) floor($spend);
}

/**
 * Bonus de bienvenida por registro
 * - Se aplica una sola vez por usuario.
 * - No afecta al gasto histórico ni al tier.
 * - Se guarda como movimiento en el ledger (type=bonus) para que el sistema lo preserve.
 */
const CASANOVA_MULLIGANS_SIGNUP_BONUS_POINTS = 250;

function casanova_mulligans_ledger_add_once(int $user_id, array $movement): void {
  if ($user_id <= 0) return;

  $id = (string)($movement['id'] ?? '');
  if ($id === '') return;

  $ledger_json = (string) get_user_meta($user_id, CASANOVA_MULL_META_LEDGER, true);
  $ledger = $ledger_json ? json_decode($ledger_json, true) : [];
  if (!is_array($ledger)) $ledger = [];

  foreach ($ledger as $row) {
    if (is_array($row) && (string)($row['id'] ?? '') === $id) {
      return; // ya existe
    }
  }

  $ledger[] = $movement;

  // Persistimos ledger
  update_user_meta($user_id, CASANOVA_MULL_META_LEDGER, wp_json_encode($ledger));

  // Actualizamos caches básicos para que sea visible sin esperar a un sync
  $bonus  = (int) get_user_meta($user_id, CASANOVA_MULL_META_BONUS, true);
  $balance= (int) get_user_meta($user_id, CASANOVA_MULL_META_BALANCE, true);
  $total  = (int) get_user_meta($user_id, CASANOVA_MULL_META_TOTAL, true);

  $points = (int)($movement['points'] ?? 0);
  if ((string)($movement['type'] ?? '') === 'bonus') $bonus += $points;
  $balance += $points;
  $total   += $points;

  update_user_meta($user_id, CASANOVA_MULL_META_BONUS, $bonus);
  update_user_meta($user_id, CASANOVA_MULL_META_BALANCE, $balance);
  update_user_meta($user_id, CASANOVA_MULL_META_TOTAL, $total);
}

function casanova_mulligans_bonus_on_register(int $user_id): void {
  if ($user_id <= 0) return;

  // Guard adicional: si ya se aplicó, no repetir
  if (get_user_meta($user_id, '_casanova_mulligans_signup_bonus', true)) return;

  $movement = [
    'id'     => 'bonus:signup',
    'type'   => 'bonus',
    'points' => (int) CASANOVA_MULLIGANS_SIGNUP_BONUS_POINTS,
    'source' => 'portal',
    'ref'    => 'signup',
    'note'   => 'Bonus de bienvenida',
    'ts'     => time(),
  ];

  casanova_mulligans_ledger_add_once($user_id, $movement);

  update_user_meta($user_id, '_casanova_mulligans_signup_bonus', 1);
}
add_action('user_register', 'casanova_mulligans_bonus_on_register', 20);


/**
 * GIAV: gasto histórico (pagado neto) por cliente.
 * Estrategia: Expediente_SEARCH(idsCliente) y por cada expediente sumar cobros netos.
 *
 * Nota: esto puede ser pesado si un cliente tiene muchos expedientes.
 * Por eso lo cacheamos en user_meta con last_sync.
 */
function casanova_mulligans_giav_lifetime_spend(int $idCliente): float|WP_Error {
  if ($idCliente <= 0) return new WP_Error('no_cliente', 'Cliente GIAV inválido');

  if (!function_exists('casanova_giav_expedientes_por_cliente')) {
    return new WP_Error('missing', 'GIAV expedientes helper missing');
  }
  if (!function_exists('casanova_giav_cobros_por_expediente_all')) {
    return new WP_Error('missing', 'GIAV cobros helper missing');
  }

 $all = [];
$pageSize  = 100;
$pageIndex = 0;

do {
  $chunk = casanova_giav_expedientes_por_cliente($idCliente, $pageSize, $pageIndex);
  if (is_wp_error($chunk)) {
    return $chunk;
  }

  $count = is_array($chunk) ? count($chunk) : 0;

  if ($count > 0) {
    $all = array_merge($all, $chunk);
  }

  $pageIndex++;
} while ($count === $pageSize);

$expedientes = $all;

  if (is_wp_error($expedientes)) return $expedientes;
  if (empty($expedientes)) return 0.0;

  $total_pagado = 0.0;

  foreach ($expedientes as $e) {
    if (!is_object($e)) continue;
    $idExp = (int)($e->Id ?? 0);
    if ($idExp <= 0) continue;

    $cobros = casanova_giav_cobros_por_expediente_all($idExp, $idCliente);
    if (is_wp_error($cobros)) {
      // Si un expediente falla, no tiramos todo. Log y seguimos.
      if (function_exists('error_log')) {
        error_log('[CASANOVA][MULLIGANS] Cobros_SEARCH fallo expediente '.$idExp.': '.$cobros->get_error_message());
      }
      continue;
    }

    $pagado_real = 0.0;
    $reemb_real  = 0.0;

    foreach ($cobros as $c) {
      if (!is_object($c)) continue;
      $importe = (float)($c->Importe ?? 0);
      $tipoOp = strtoupper(trim((string)($c->TipoOperacion ?? '')));

      $isReembolso =
        ($tipoOp === 'REEMBOLSO')
        || (strpos($tipoOp, 'REEM') !== false)
        || (strpos($tipoOp, 'DEV') !== false);

      if ($tipoOp === '') {
        if ($importe >= 0) $pagado_real += $importe;
        else $reemb_real += abs($importe);
        continue;
      }

      $abs = abs($importe);
      if ($isReembolso) $reemb_real += $abs;
      else $pagado_real += $abs;
    }

    $neto = $pagado_real - $reemb_real;
    if ($neto < 0) $neto = 0;
    $total_pagado += $neto;
  }

  return (float) round($total_pagado, 2);
}

/**
 * Sincroniza Mulligans de un usuario.
 * - cache por last_sync (por defecto 12h)
 */

/**
 * Extrae un customDataValue (GIAV) de un expediente por Key (int).
 * Soporta propiedades CustomDataValues/customDataValues y estructuras anidadas.
 */
function casanova_mulligans_giav_custom_value($expediente, int $key): ?string {
  if (!is_object($expediente)) return null;

  $cdv = $expediente->customDataValues ?? ($expediente->CustomDataValues ?? null);
  if (!$cdv && isset($expediente->DatosExternos)) $cdv = $expediente->DatosExternos;

  // Normaliza a array de items
  $items = null;
  if (is_array($cdv)) $items = $cdv;
  elseif (is_object($cdv)) {
    // algunos WSDL devuelven ArrayOfCustomDataItem con propiedad "CustomDataItem"
    if (isset($cdv->CustomDataItem)) $items = $cdv->CustomDataItem;
    elseif (isset($cdv->customDataItem)) $items = $cdv->customDataItem;
    else $items = $cdv;
  }

  if (!$items) return null;
  if (!is_array($items)) $items = [$items];

  foreach ($items as $it) {
    if (!is_object($it)) continue;
    $k = $it->Key ?? ($it->key ?? null);
    if ((int)$k !== (int)$key) continue;
    $v = $it->Value ?? ($it->value ?? null);
    if ($v === null) return null;
    return (string)$v;
  }

  return null;
}

/**
 * GIAV: detecta Mulligans usados por expediente (custom field 2179) y crea movimientos redeem.
 * Devuelve ['total_used' => float, 'movements' => array].
 */
function casanova_mulligans_giav_used_movements(int $idCliente, int $field_used = 2179, int $field_note = 2180): array|WP_Error {
  if (!function_exists('casanova_giav_expedientes_por_cliente')) {
    return new WP_Error('missing', 'GIAV expedientes helper missing');
  }

  $pageSize  = 100;
  $pageIndex = 0;

  $total_used = 0.0;
  $movs = [];

  do {
    $chunk = casanova_giav_expedientes_por_cliente($idCliente, $pageSize, $pageIndex);
    if (is_wp_error($chunk)) return $chunk;

    $n = is_array($chunk) ? count($chunk) : 0;
    if ($n > 0) {
      foreach ($chunk as $exp) {
        if (!is_object($exp)) continue;
        $idExp = (int)($exp->IdExpediente ?? ($exp->idExpediente ?? ($exp->Id ?? 0)));
        if ($idExp <= 0) continue;

        $used_raw = casanova_mulligans_giav_custom_value($exp, $field_used);
        if ($used_raw === null || $used_raw === '') continue;

        $used = (float) str_replace(',', '.', (string)$used_raw);
        if ($used <= 0) continue;

        $note = casanova_mulligans_giav_custom_value($exp, $field_note);
        $note = $note !== null ? trim((string)$note) : '';
        if ($note === '') $note = 'Canje aplicado';

        $total_used += $used;

        $movs[] = [
          'id'     => 'redeem:exp:'.$idExp,
          'type'   => 'redeem',
          'points' => -(int) round($used), // puntos negativos
          'source' => 'giav',
          'ref'    => 'expediente:'.$idExp,
          'note'   => $note,
          'ts'     => time(),
          'exp_titulo' => (string)($exp->Titulo ?? ''),
          'exp_codigo' => (string)($exp->Codigo ?? ''),
        ];
      }
    }

    $pageIndex++;
  } while ($n === $pageSize);

  return [
    'total_used' => (float) round($total_used, 2),
    'movements'  => $movs,
  ];
}


function casanova_mulligans_sync_user(int $user_id, bool $force = false): array|WP_Error {
  if ($user_id <= 0) return new WP_Error('no_user', 'Usuario inválido');

  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if ($idCliente <= 0) return new WP_Error('no_link', 'Usuario sin idCliente GIAV');

  $last = (int) get_user_meta($user_id, CASANOVA_MULL_META_LAST_SYNC, true);
  $ttl = 12 * HOUR_IN_SECONDS;
  if (!$force && $last > 0 && (time() - $last) < $ttl) {
    return casanova_mulligans_get_user($user_id);
  }

  // 1) Earned: gasto histórico (GIAV) -> puntos base
  $spend = casanova_mulligans_giav_lifetime_spend($idCliente);
  if (is_wp_error($spend)) return $spend;

  $earned_points = (int) casanova_mulligans_points_for_spend((float)$spend);
  $tier = casanova_mulligans_tier_for_spend((float)$spend);

  // 2) Ledger existente (preservamos bonus/ajustes manuales)
  $ledger_json = (string) get_user_meta($user_id, CASANOVA_MULL_META_LEDGER, true);
  $ledger_old = $ledger_json ? json_decode($ledger_json, true) : [];
  if (!is_array($ledger_old)) $ledger_old = [];

  $preserved = [];
  foreach ($ledger_old as $row) {
    if (!is_array($row)) continue;
    $id = (string)($row['id'] ?? '');
    $type = (string)($row['type'] ?? '');
    if ($id === 'earn:lifetime') continue;
    if (strpos($id, 'redeem:exp:') === 0) continue;
    // preservamos bonus/adjust (y cualquier otro movimiento manual)
    if (in_array($type, ['bonus','adjust'], true) || (($row['source'] ?? '') !== 'giav')) {
      $preserved[] = $row;
    }
  }

  // 3) Used: canjes desde GIAV (custom fields por expediente)
  $used_pack = casanova_mulligans_giav_used_movements($idCliente, 2179, 2180);
  if (is_wp_error($used_pack)) return $used_pack;

  $used_movs = (array)($used_pack['movements'] ?? []);
  $used_total = 0.0;
  foreach ($used_movs as $m) {
    if (!is_array($m)) continue;
    $used_total += abs((int)($m['points'] ?? 0));
  }

  // 4) Movimiento "earned" acumulado (se actualiza, no se multiplica)
  $earned_move = [
    'id'     => 'earn:lifetime',
    'type'   => 'earn',
    'points' => (int)$earned_points,
    'source' => 'giav',
    'ref'    => 'lifetime',
    'note'   => 'Gasto confirmado',
    'ts'     => time(),
  ];

  $ledger = array_merge([$earned_move], $preserved, $used_movs);

  // Totales cacheados (para JetEngine/UI)
  $bonus_total = 0;
  foreach ($preserved as $row) {
    $bonus_total += (int)($row['points'] ?? 0);
  }

  $balance = 0;
  foreach ($ledger as $row) {
    if (!is_array($row)) continue;
    $balance += (int)($row['points'] ?? 0);
  }

  // Persistimos (compatibilidad + nuevos campos)
  update_user_meta($user_id, CASANOVA_MULL_META_SPEND, (float)$spend);
  update_user_meta($user_id, CASANOVA_MULL_META_TIER, $tier);
  update_user_meta($user_id, CASANOVA_MULL_META_LAST_SYNC, (int) time());

  update_user_meta($user_id, CASANOVA_MULL_META_EARNED, (int)$earned_points);
  update_user_meta($user_id, CASANOVA_MULL_META_BONUS, (int)$bonus_total);
  update_user_meta($user_id, CASANOVA_MULL_META_USED, (int)$used_total);
  update_user_meta($user_id, CASANOVA_MULL_META_BALANCE, (int)$balance);

  // Mantenemos 'total' como balance para no romper lo existente
  update_user_meta($user_id, CASANOVA_MULL_META_TOTAL, (int)$balance);

  update_user_meta($user_id, CASANOVA_MULL_META_LEDGER, wp_json_encode($ledger));

  return [
    'user_id' => $user_id,
    'idCliente' => $idCliente,
    'spend' => (float)$spend,
    'tier' => $tier,
    'points' => (int)$balance,
    'earned' => (int)$earned_points,
    'bonus' => (int)$bonus_total,
    'used' => (int)$used_total,
    'last_sync' => (int) get_user_meta($user_id, CASANOVA_MULL_META_LAST_SYNC, true),
  ];
}

function casanova_mulligans_get_user(int $user_id): array {
  $spend = (float) get_user_meta($user_id, CASANOVA_MULL_META_SPEND, true);
  $tier = (string) get_user_meta($user_id, CASANOVA_MULL_META_TIER, true);
  $last = (int) get_user_meta($user_id, CASANOVA_MULL_META_LAST_SYNC, true);

  $earned = (int) get_user_meta($user_id, CASANOVA_MULL_META_EARNED, true);
  $bonus  = (int) get_user_meta($user_id, CASANOVA_MULL_META_BONUS, true);
  $used   = (int) get_user_meta($user_id, CASANOVA_MULL_META_USED, true);
  $balance= (int) get_user_meta($user_id, CASANOVA_MULL_META_BALANCE, true);

  // Compatibilidad: si no existen los nuevos, caemos a total viejo
  if ($balance === 0) {
    $balance = (int) get_user_meta($user_id, CASANOVA_MULL_META_TOTAL, true);
  }
  if ($tier === '') $tier = casanova_mulligans_tier_for_spend($spend);

  return [
    'user_id' => $user_id,
    'idCliente' => (int) get_user_meta($user_id, 'casanova_idcliente', true),
    'spend' => $spend,
    'tier' => $tier,
    'points' => $balance,
    'earned' => $earned,
    'bonus' => $bonus,
    'used' => $used,
    'last_sync' => $last,
  ];
}

/**
 * Shortcode para el portal.
 * Uso: [casanova_mulligans]
 */
add_shortcode('casanova_mulligans', function ($atts) {
  if (!is_user_logged_in()) return '<p>' . esc_html__('Debes iniciar sesión.', 'casanova-portal') . '</p>';

  $user_id = (int) get_current_user_id();
  $force = isset($_GET['mulligans_sync']) && current_user_can('manage_options');
  $data = casanova_mulligans_sync_user($user_id, (bool)$force);
  if (is_wp_error($data)) {
    return '<p>' . esc_html__('No se han podido cargar tus Mulligans ahora mismo.', 'casanova-portal') . '</p>';
  }

  // Tier seguro
  $tier = isset($data['tier']) ? sanitize_key((string)$data['tier']) : 'birdie';
  $tier = in_array($tier, ['birdie','eagle','eagle_plus','albatross'], true) ? $tier : 'birdie';

  $tier_cfg = casanova_mulligans_tier_cfg($tier);
  $label = (string)($tier_cfg['label'] ?? 'Birdie');
  $mult  = (float)($tier_cfg['mult'] ?? 1.0);

  // progreso al siguiente tier
  $tiers = casanova_mulligans_tiers();
  $spend = (float)($data['spend'] ?? 0);
  $tier_keys = array_keys($tiers);
  $idx = array_search($tier, $tier_keys, true);
  $next_key = ($idx !== false && isset($tier_keys[$idx+1])) ? $tier_keys[$idx+1] : null;

  $progress = 100;
  $next_label = '';
  $to_next = 0.0;
  if ($next_key) {
    $next_cfg = $tiers[$next_key];
    $next_min = (float)$next_cfg['min'];
    $prev_min = (float)($tier_cfg['min'] ?? 0);
    $span = max(1.0, $next_min - $prev_min);
    $progress = (int) max(0, min(100, round((($spend - $prev_min) / $span) * 100)));
    $to_next = max(0.0, $next_min - $spend);
    $next_label = (string)($next_cfg['label'] ?? '');
  }

  // Aviso actualización
  $last_sync = (int) get_user_meta($user_id, 'casanova_mulligans_last_sync', true);

  // Clase del badge
  $tier_class = 'casanova-mulligans-badge is-' . $tier;

  ob_start();
 echo '<div class="casanova-mulligans-card is-'.esc_attr($tier).'">';
  echo '  <div class="casanova-mulligans-card__top">';
  echo '    <div class="casanova-mulligans-card__title">' . esc_html__('Tus Mulligans', 'casanova-portal') . '</div>';
  echo '    <div class="casanova-mulligans-card__tier"><span class="'.esc_attr($tier_class).'">'.esc_html($label).'</span></div>';
  echo '  </div>';
  echo '  <div class="casanova-mulligans-card__big">'.number_format_i18n((int)($data['points'] ?? 0)).'</div>';
  echo '  <div class="casanova-mulligans-card__meta">' . sprintf(
    wp_kses_post(__('Gasto acumulado: <strong>%1$s</strong> · Ratio actual: <strong>x%2$s</strong>', 'casanova-portal')),
    esc_html(casanova_fmt_money($spend)),
    esc_html(rtrim(rtrim(number_format((float)$mult, 2, '.', ''), '0'), '.'))
  ) . '</div>';

  if ($next_key) {
    echo '  <div class="casanova-mulligans-card__progress">';
    echo '    <div class="casanova-progress"><span class="casanova-progress__bar" style="width:'.esc_attr((string)$progress).'%"></span></div>';
    echo '    <div class="casanova-mulligans-card__hint">' . sprintf(
      wp_kses_post(__('Te faltan <strong>%1$s</strong> para subir a <strong>%2$s</strong>.', 'casanova-portal')),
      esc_html(casanova_fmt_money($to_next)),
      esc_html($next_label)
    ) . '</div>';
    echo '  </div>';
  } else {
    echo '  <div class="casanova-mulligans-card__hint">Estás en el nivel más alto. ' . esc_html__('Sí, eres oficialmente peligroso.', 'casanova-portal') . '</div>';
  }

  if ($last_sync) {
    echo '<div class="casanova-mulligans-updated">';
    printf(
      esc_html__('Última actualización hace %s', 'casanova-portal'),
      esc_html(human_time_diff($last_sync, time()))
    );
    echo '</div>';
  }

  // Últimos movimientos (plegable) dentro de la card
  echo '<details class="casanova-mulligans-details">';
  echo '  <summary>' . esc_html__('Ver últimos movimientos', 'casanova-portal') . '</summary>';
  echo '  <div class="casanova-mulligans-details__inner">';
  echo        do_shortcode('[casanova_mulligans_movimientos limit="5" embedded="1"]');
  echo '  </div>';
  echo '</details>';

  echo '</div>';

  return ob_get_clean();
});

/**
 * Convierte secuencias tipo "Habitaciu00f3n" o "\\u00f3" a UTF-8 real.
 * Mantiene el string intacto si no hay nada que convertir.
 */
function casanova_mulligans_unescape_unicode(string $s): string {
  // Caso 1: secuencias JSON \u00f3
  if (strpos($s, '\\u') !== false) {
    $json = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    $decoded = json_decode($json, true);
    if (is_string($decoded)) return $decoded;
  }

  // Caso 2: texto con u00f3 (sin barra)
  if (preg_match('/u[0-9a-fA-F]{4}/', $s)) {
    $fixed = preg_replace_callback('/u([0-9a-fA-F]{4})/', function($m){
      $cp = hexdec($m[1]);
      // convertir codepoint a UTF-8
      if ($cp < 0x80) return chr($cp);
      if ($cp < 0x800) return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
      return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }, $s);
    if (is_string($fixed) && $fixed !== $s) return $fixed;
  }

  return $s;
}

/**
 * Movimientos (ledger)
 * Uso: [casanova_mulligans_movimientos limit="10"]
 */
add_shortcode('casanova_mulligans_movimientos', function($atts){
  if (!is_user_logged_in()) return '<p>' . esc_html__('Debes iniciar sesión.', 'casanova-portal') . '</p>';

  $atts = shortcode_atts(['limit' => 10, 'embedded' => 0], $atts);
  $limit = max(1, min(50, (int)$atts['limit']));
  $embedded = !empty($atts['embedded']);

  $user_id = (int) get_current_user_id();
  $json = (string) get_user_meta($user_id, CASANOVA_MULL_META_LEDGER, true);
  $ledger = $json ? json_decode($json, true) : [];
  if (!is_array($ledger) || empty($ledger)) return '<p>' . esc_html__('No hay movimientos todavía.', 'casanova-portal') . '</p>';

  usort($ledger, function($a, $b){
    $ta = isset($a['ts']) ? (int)$a['ts'] : 0;
    $tb = isset($b['ts']) ? (int)$b['ts'] : 0;
    return $tb <=> $ta;
  });

  $ledger = array_slice($ledger, 0, $limit);

  $type_label = function($t){
    switch ((string)$t) {
      case 'earn': return __('Ganados', 'casanova-portal');
      case 'bonus': return __('Bono', 'casanova-portal');
      case 'redeem': return __('Canje', 'casanova-portal');
      case 'adjust': return __('Ajuste', 'casanova-portal');
      default: return __('Movimiento', 'casanova-portal');
    }
  };

  ob_start();
  echo '<div class="casanova-mulligans-ledger'.($embedded ? ' is-embedded' : '').'">';
  if (!$embedded) {
    echo '<div class="casanova-mulligans-ledger__title">' . esc_html__('Movimientos', 'casanova-portal') . '</div>';
  }
  echo '<table class="casanova-mulligans-ledger__table">';
  echo '<thead><tr><th>' . esc_html__('Fecha', 'casanova-portal') . '</th><th>' . esc_html__('Tipo', 'casanova-portal') . '</th><th>' . esc_html__('Concepto', 'casanova-portal') . '</th><th class="is-right">' . esc_html__('Mulligans', 'casanova-portal') . '</th></tr></thead><tbody>';

  foreach ($ledger as $row) {
    if (!is_array($row)) continue;
    $ts = isset($row['ts']) ? (int)$row['ts'] : 0;
    $date = $ts ? date_i18n('d/m/Y', $ts) : '—';

    $type = (string)($row['type'] ?? '');
    $type_txt = $type_label($type);

    $note = trim((string)($row['note'] ?? ''));
    $note = casanova_mulligans_unescape_unicode($note);

    // Añadir referencia de expediente en canjes (si existe)
    if ($type === 'redeem') {
      $ref = (string)($row['ref'] ?? '');
      $expTitulo = trim((string)($row['exp_titulo'] ?? ''));
      $expTitulo = casanova_mulligans_unescape_unicode($expTitulo);

      $expCodigo = trim((string)($row['exp_codigo'] ?? ''));
      $expCodigo = casanova_mulligans_unescape_unicode($expCodigo);

      if (stripos($ref, 'expediente:') === 0) {
        $expId = trim(substr($ref, strlen('expediente:')));
        if ($expId !== '') {
          // URL hacia el expediente dentro del portal (mismo contexto, cambia querystring)
          $scheme = is_ssl() ? 'https' : 'http';
          $host = $_SERVER['HTTP_HOST'] ?? '';
          $uri  = $_SERVER['REQUEST_URI'] ?? '';
          $current = ($host && $uri) ? ($scheme.'://'.$host.$uri) : '';
          $base = $current ? remove_query_arg(['expediente','mulligans_sync'], $current) : '';
          // Al navegar desde Mulligans hacia un expediente, forzamos la vista
          // de expedientes para evitar caer en "Principal".
          $exp_url = $base ? add_query_arg(['view' => 'expedientes', 'expediente' => $expId], $base) : '';

          $human = $expCodigo !== '' ? $expCodigo : $expId;
          $human_html = $exp_url
            ? '<a href="'.esc_url($exp_url).'">'.esc_html($human).'</a>'
            : esc_html($human);

          $exp_part = 'Exp. ' . $human_html;

          // Si tenemos título, lo usamos como etiqueta humana
          if ($expTitulo !== '') {
            $suffix = esc_html($expTitulo) . ' (' . $exp_part . ')';
          } else {
            $suffix = $exp_part;
          }

          $note = $note ? ($note . ' · ' . $suffix) : $suffix;
        }
      }
    }

    if ($note === '') {
        if ($type === 'earn') {
  $note = __('Gasto confirmado', 'casanova-portal');
} elseif ($type === 'redeem') {
  $note = __('Canje', 'casanova-portal');
} elseif ($type === 'bonus') {
  $note = __('Bono', 'casanova-portal');
}
      else $note = '—';
    } else {
      // Limpieza: el cliente no tiene por qué saber qué ERP hay debajo.
      $note = str_ireplace(['(GIAV)', 'GIAV'], ['', ''], $note);
    }

    $points = (int)($row['points'] ?? 0);
    $pts_txt = number_format_i18n(abs($points));
    $sign = $points >= 0 ? '+' : '−';
    $cls = $points >= 0 ? 'is-plus' : 'is-minus';

    echo '<tr>';
    echo '<td>'.esc_html($date).'</td>';
    echo '<td>'.esc_html($type_txt).'</td>';
    echo '<td>'.wp_kses_post($note).'</td>';
    echo '<td class="is-right '.esc_attr($cls).'">'.esc_html($sign.$pts_txt).'</td>';
    echo '</tr>';
  }

  echo '</tbody></table></div>';
  return ob_get_clean();
});


/**
 * Cron diario: sincroniza usuarios por lotes.
 * - Por seguridad, procesa N usuarios por ejecución.
 */
add_action('casanova_mulligans_daily_sync', function () {
  $batch = 25;
  $offset = (int) get_option('casanova_mulligans_sync_offset', 0);

  $q = new WP_User_Query([
    'number' => $batch,
    'offset' => $offset,
    'fields' => 'ID',
    'meta_query' => [
      [
        'key' => 'casanova_idcliente',
        'compare' => 'EXISTS',
      ]
    ],
  ]);

  $ids = $q->get_results();
  if (empty($ids)) {
    // reinicia ciclo
    update_option('casanova_mulligans_sync_offset', 0, false);
    return;
  }

  foreach ($ids as $uid) {
    $uid = (int)$uid;
    // fuerza para que el cron sí actualice
    $res = casanova_mulligans_sync_user($uid, true);
    if (is_wp_error($res)) {
      if (function_exists('error_log')) {
        error_log('[CASANOVA][MULLIGANS] Sync user '.$uid.' fallo: '.$res->get_error_message());
      }
    }
  }

  update_option('casanova_mulligans_sync_offset', $offset + $batch, false);
});