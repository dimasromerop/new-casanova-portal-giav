<?php
/**
 * Mensajes del portal (Centro de mensajes por expediente)
 * Fuente de verdad: GIAV (Message_SEARCH / EventLogItemType=Comment)
 *
 * Shortcodes:
 * - [casanova_mensajes] Lista de mensajes (comentarios) por expediente
 * - [casanova_card_mensajes] Card para dashboard (nuevos / último mensaje)
 */
if (!defined('ABSPATH')) exit;

/** Normaliza lista de mensajes devuelta por GIAV Message_SEARCH. */
function casanova_giav_messages_search(int $idExpediente, ?string $from = null, ?string $to = null, int $pageSize = 50, int $pageIndex = 0) {
  if ($idExpediente <= 0) return [];
  $params = [
    'targetClass'      => 'Expediente',
    'classId'          => (string)$idExpediente,
    'creationDateFrom' => $from,
    'creationDateTo'   => $to,
    'pageSize'         => max(1, (int)$pageSize),
    'pageIndex'        => max(0, (int)$pageIndex),
  ];
  $res = casanova_giav_call('Message_SEARCH', $params);
  if (is_wp_error($res)) return $res;

  // GIAV: $res->Message_SEARCHResult->WsMessage (obj|array)
  $container = is_object($res) && isset($res->Message_SEARCHResult) ? $res->Message_SEARCHResult : $res;
  $items = function_exists('casanova_giav_normalize_list')
    ? casanova_giav_normalize_list($container, 'WsMessage')
    : [];

  return $items;
}

/**
 * Devuelve solo comentarios (EventLogItemType=Comment) ordenados por fecha desc.
 * OPTIMIZADO: Usa caché (transient) para no saturar el Dashboard.
 */
function casanova_giav_comments_por_expediente(int $idExpediente, int $limit = 50, int $daysBack = 365) {
  // Si tenemos el helper de caché, lo usamos (TTL 300s = 5 min)
  if (function_exists('casanova_cache_remember')) {
    return casanova_cache_remember(
      'giav:comments:' . $idExpediente . ':' . $limit . ':' . $daysBack,
      300,
      function() use ($idExpediente, $limit, $daysBack) {
        return casanova_giav_comments_por_expediente_uncached($idExpediente, $limit, $daysBack);
      }
    );
  }
  // Fallback si no hay caché
  return casanova_giav_comments_por_expediente_uncached($idExpediente, $limit, $daysBack);
}

/**
 * Lógica real síncrona (extraída para poder ser llamada desde la caché).
 */
function casanova_giav_comments_por_expediente_uncached(int $idExpediente, int $limit, int $daysBack) {
  $from = date('Y-m-d', strtotime('-' . max(1, (int)$daysBack) . ' days'));
  
  // Llama a message search (SOAP)
  $items = casanova_giav_messages_search($idExpediente, $from, null, min(100, max(1, (int)$limit)), 0);
  
  if (is_wp_error($items)) return $items;
  if (!is_array($items)) return [];

  $out = [];
  foreach ($items as $it) {
    if (!is_object($it)) continue;
    // Filtramos para quedarnos solo con 'Comment' (mensajes legibles)
    $t = (string)($it->EventLogItemType ?? $it->ItemType ?? '');
    if (strcasecmp($t, 'Comment') !== 0) continue;
    $out[] = $it;
  }

  // Ordenar por fecha más reciente primero
  usort($out, function($a, $b){
    $ta = strtotime((string)($a->CreationDate ?? '')) ?: 0;
    $tb = strtotime((string)($b->CreationDate ?? '')) ?: 0;
    return $tb <=> $ta;
  });

  return array_slice($out, 0, max(1, (int)$limit));
}

/** Meta key para último mensaje visto por usuario+expediente */
function casanova_messages_seen_meta_key(int $idExpediente): string {
  return 'casanova_last_seen_msg_' . (int)$idExpediente;
}

/** Marca como vistos los mensajes de un expediente hasta $ts (timestamp). */
function casanova_messages_mark_seen(int $user_id, int $idExpediente, int $ts): void {
  if ($user_id <= 0 || $idExpediente <= 0 || $ts <= 0) return;
  $key = casanova_messages_seen_meta_key($idExpediente);
  $cur = (int) get_user_meta($user_id, $key, true);
  if ($ts > $cur) update_user_meta($user_id, $key, $ts);
}

/** Cuenta mensajes nuevos (comentarios) por expediente para un usuario. */
function casanova_messages_new_count_for_expediente(int $user_id, int $idExpediente, int $limit = 50): int {
  if ($user_id <= 0 || $idExpediente <= 0) return 0;
  $seen = (int) get_user_meta($user_id, casanova_messages_seen_meta_key($idExpediente), true);

  $comments = casanova_giav_comments_por_expediente($idExpediente, $limit, 365);
  if (is_wp_error($comments) || !is_array($comments)) return 0;

  $n = 0;
  foreach ($comments as $c) {
    $ts = strtotime((string)($c->CreationDate ?? '')) ?: 0;
    if ($ts > $seen) $n++;
  }
  return $n;
}

/** Cuenta mensajes nuevos del usuario en todos sus expedientes (hasta $maxExpedientes). */
function casanova_messages_new_count_user(int $user_id, int $idCliente, int $maxExpedientes = 300): int {
  if ($user_id <= 0 || $idCliente <= 0) return 0;
  if (!function_exists('casanova_giav_expedientes_por_cliente')) return 0;

  $pageSize = 100;
  $maxPages = (int)ceil(max(1, $maxExpedientes) / $pageSize);
  $total = 0;

  for ($page = 0; $page < $maxPages; $page++) {
    $exps = casanova_giav_expedientes_por_cliente($idCliente, $pageSize, $page * $pageSize);
    if (is_wp_error($exps) || !is_array($exps) || empty($exps)) break;

    foreach ($exps as $e) {
      if (!is_object($e)) continue;
      $idExp = (int)($e->IdExpediente ?? $e->Id ?? 0);
      if ($idExp <= 0) continue;
      $total += casanova_messages_new_count_for_expediente($user_id, $idExp, 30);
    }
  }
  return $total;
}

/** Render lista de mensajes (comentarios) en HTML. */
function casanova_portal_render_comments_list(array $comments): string {
  if (empty($comments)) {
    return '<div class="casanova-messages-empty">' . esc_html__('No hay mensajes.', 'casanova-portal') . '</div>';
  }

  $html = '<div class="casanova-messages">';
  foreach ($comments as $c) {
    if (!is_object($c)) continue;
    $date = (string)($c->CreationDate ?? '');
    $ts = strtotime($date) ?: 0;
    $when = $ts ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts) : $date;
    $user = trim((string)($c->UserName ?? ''));
    $subject = trim((string)($c->Subject ?? ''));
    $body = (string)($c->Body ?? '');
    $body = $body !== '' ? wp_kses_post($body) : '';

    $html .= '<article class="casanova-message">';
    $html .= '  <div class="casanova-message__meta">';
    $html .= '    <span class="casanova-message__date">' . esc_html($when) . '</span>';
    if ($user !== '') $html .= '    <span class="casanova-message__author">' . esc_html($user) . '</span>';
    $html .= '  </div>';
    if ($subject !== '') {
      $html .= '  <div class="casanova-message__subject">' . esc_html($subject) . '</div>';
    }
    if ($body !== '') {
      $html .= '  <div class="casanova-message__body">' . $body . '</div>';
    }
    $html .= '</article>';
  }
  $html .= '</div>';

  return $html;
}

/**
 * Shortcode: [casanova_mensajes]
 * - expediente: id numérico; si vacío usa ?expediente= o el próximo viaje.
 * - limit: nº máximo de comentarios (default 20)
 * - mark_seen: 1/0 (default 1) actualiza estado de leído
 */
add_shortcode('casanova_mensajes', function($atts){
  if (!is_user_logged_in()) return '';
  $atts = shortcode_atts([
    'expediente' => '',
    'limit' => 20,
    'mark_seen' => 1,
    'title' => 1,
  ], (array)$atts, 'casanova_mensajes');

  $user_id = (int) get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if ($idCliente <= 0) return '';

  $idExp = 0;
  if ($atts['expediente'] !== '') $idExp = (int)$atts['expediente'];
  if ($idExp <= 0 && isset($_GET['expediente'])) $idExp = (int) $_GET['expediente'];

  if ($idExp <= 0 && function_exists('casanova_portal_get_next_trip_expediente')) {
    $next = casanova_portal_get_next_trip_expediente($idCliente);
    $exp_obj = $next;
    $idExp = $next ? (int)($next->IdExpediente ?? $next->Id ?? 0) : 0;
  }

  // Si el expediente viene por query o por otra fuente, intentamos cargar su ficha para etiquetarlo en la card
  if ($idExp > 0 && empty($exp_obj) && function_exists('casanova_giav_expediente_get')) {
    $exp_obj = casanova_giav_expediente_get($idExp);
  }

  if ($idExp <= 0) {
    return '<p>' . esc_html__('Selecciona un expediente para ver sus mensajes.', 'casanova-portal') . '</p>';
  }

  if (function_exists('casanova_user_can_access_expediente') && !casanova_user_can_access_expediente($user_id, $idExp)) {
    return '<p>' . esc_html__('No tienes permiso para ver este expediente.', 'casanova-portal') . '</p>';
  }

  $limit = max(1, (int)$atts['limit']);
  $comments = casanova_giav_comments_por_expediente($idExp, $limit, 365);
  if (is_wp_error($comments)) {
    return '<p>' . esc_html__('No se han podido cargar los mensajes.', 'casanova-portal') . '</p>';
  }

  $out = '';
  if (!empty($atts['title'])) {
    $out .= '<h3 class="casanova-messages-title">' . esc_html__('Mensajes', 'casanova-portal') . '</h3>';
  }

  // Contexto expediente (título/código)
  if (function_exists('casanova_portal_expediente_meta')) {
    $meta = casanova_portal_expediente_meta($idCliente, $idExp);
    $label = is_array($meta) ? trim((string)($meta['label'] ?? '')) : '';
    if ($label !== '') {
      $out .= '<div class="casanova-messages-context">' . esc_html__('Expediente:', 'casanova-portal') . ' <strong>' . esc_html($label) . '</strong></div>';
    }
  }

  $out .= casanova_portal_render_comments_list(is_array($comments) ? $comments : []);

  // Mark seen: usar el timestamp más reciente
  if (!empty($atts['mark_seen']) && is_array($comments) && !empty($comments)) {
    $maxTs = 0;
    foreach ($comments as $c) {
      $ts = strtotime((string)($c->CreationDate ?? '')) ?: 0;
      if ($ts > $maxTs) $maxTs = $ts;
    }
    if ($maxTs > 0) casanova_messages_mark_seen($user_id, $idExp, $maxTs);
  }

  return $out;
});

/**
 * Card dashboard: [casanova_card_mensajes]
 * Muestra nº de nuevos + último mensaje (próximo viaje).
 */
add_shortcode('casanova_card_mensajes', function($atts){
  if (!is_user_logged_in()) return '';
  $atts = shortcode_atts([
    'source' => 'next', // next|current|auto
    'limit' => 10,
    'cta' => 1,
  ], (array)$atts, 'casanova_card_mensajes');

  $user_id = (int) get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if ($idCliente <= 0) return '';

  $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');

  // Determinar expediente objetivo
  $idExp = 0;
  $exp_obj = null;
  $qExp = isset($_GET['expediente']) ? (int) $_GET['expediente'] : 0;
  if ($atts['source'] === 'current') {
    $idExp = $qExp;
  } elseif ($atts['source'] === 'auto') {
    $idExp = $qExp;
  }
  if ($idExp <= 0 && function_exists('casanova_portal_get_next_trip_expediente')) {
    $next = casanova_portal_get_next_trip_expediente($idCliente);
    $exp_obj = $next;
    $idExp = $next ? (int)($next->IdExpediente ?? $next->Id ?? 0) : 0;
  }

  if ($idExp > 0 && empty($exp_obj) && function_exists('casanova_giav_expediente_get')) {
    $exp_obj = casanova_giav_expediente_get($idExp);
  }

  if ($idExp <= 0) {
    return '<article class="casanova-card casanova-msgcard"><div class="casanova-msgcard__empty">' . esc_html__('No hay viajes próximos.', 'casanova-portal') . '</div></article>';
  }

  // Nuevos
  $n_new = casanova_messages_new_count_for_expediente($user_id, $idExp, 30);

  // Último mensaje
  $comments = casanova_giav_comments_por_expediente($idExp, (int)$atts['limit'], 365);
  $latest = (is_array($comments) && !empty($comments)) ? $comments[0] : null;

  $latest_body = '';
  $latest_when = '';
  if (is_object($latest)) {
    $b = trim((string)($latest->Body ?? ''));
    if ($b !== '') {
      $latest_body = wp_strip_all_tags($b);
      if (mb_strlen($latest_body, 'UTF-8') > 140) {
        $latest_body = mb_substr($latest_body, 0, 140, 'UTF-8') . '…';
      }
    }
    $ts = strtotime((string)($latest->CreationDate ?? '')) ?: 0;
    if ($ts) $latest_when = sprintf(
      /* translators: %s = human time diff */
      esc_html__('Hace %s', 'casanova-portal'),
      human_time_diff($ts, time())
    );
  }

  $url = add_query_arg(['view' => 'mensajes', 'expediente' => $idExp], $base);

  $exp_label = '';
  if (!empty($exp_obj) && function_exists('casanova_portal_expediente_label_from_obj')) {
    $exp_label = casanova_portal_expediente_label_from_obj($exp_obj);
  } elseif ($idExp > 0) {
    /* translators: %s = expediente id */
    $exp_label = sprintf(esc_html__('Expediente %s', 'casanova-portal'), number_format_i18n($idExp));
  }

  $html  = '<article class="casanova-card casanova-msgcard">';
  $html .= '<div class="casanova-msgcard__top">';
  $html .= '  <div class="casanova-msgcard__title">' . esc_html__('Mensajes', 'casanova-portal') . '</div>';
  if ($exp_label !== '') { $html .= '  <div class="casanova-msgcard__exp">' . esc_html($exp_label) . '</div>'; }
  if ($n_new > 0) {
    $html .= '  <div class="casanova-msgcard__badge">' . sprintf(
      /* translators: %s = number */
      esc_html__('%s nuevos', 'casanova-portal'),
      esc_html(number_format_i18n($n_new))
    ) . '</div>';
  }
  $html .= '</div>';

  if ($latest_body !== '') {
    $html .= '<div class="casanova-msgcard__body">' . esc_html($latest_body) . '</div>';
    if ($latest_when !== '') {
      $html .= '<div class="casanova-msgcard__meta">' . esc_html($latest_when) . '</div>';
    }
  } else {
    $html .= '<div class="casanova-msgcard__empty">' . esc_html__('No hay mensajes recientes.', 'casanova-portal') . '</div>';
  }

  if (!empty($atts['cta'])) {
    $html .= '<div class="casanova-msgcard__cta"><a class="casanova-btn casanova-btn--primary" href="' . esc_url($url) . '">' . esc_html__('Ver mensajes', 'casanova-portal') . '</a></div>';
  }

  $html .= '</article>';

  return $html;
});