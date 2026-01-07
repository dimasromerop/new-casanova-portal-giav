<?php
if (!defined('ABSPATH')) exit;

/**
 * Genera el contenido de un archivo .ics (iCalendar)
 */
function casanova_build_ics_content(object $exp): string {
  $titulo  = trim((string)($exp->Titulo ?? 'Viaje'));
  $destino = trim((string)($exp->Destino ?? ''));
  $codigo  = trim((string)($exp->Codigo ?? ''));
  
  // Fechas: GIAV suele mandar YYYY-MM-DDTHH:MM:SS. 
  // Para eventos de día completo (viajes), ICS prefiere YYYYMMDD.
  $iniRaw = $exp->FechaInicio ?? $exp->FechaDesde ?? null;
  $finRaw = $exp->FechaFin ?? $exp->FechaHasta ?? null;

  if (!$iniRaw) return '';

  $tsIni = strtotime((string)$iniRaw);
  $tsFin = $finRaw ? strtotime((string)$finRaw) : $tsIni;

  // Ajuste: Los eventos de día completo en ICS son "exclusivos" en la fecha fin.
  // Si el viaje termina el día 20, para que el calendario marque el 20 completo, 
  // el DTEND debe ser el 21. Sumamos 1 día al final.
  $dtStart = date('Ymd', $tsIni);
  $dtEnd   = date('Ymd', $tsFin + 86400); 

  $summary = 'Viaje a ' . ($destino ?: $titulo);
  $desc    = sprintf('Expediente: %s', $codigo);
  if ($titulo && $titulo !== $summary) $desc .= "\n" . $titulo;

  // Escape especial para ICS
  $escape = fn($str) => str_replace([',', ';', "\n"], ['\,', '\;', '\\n'], $str);

  $lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Casanova Golf//Portal Cliente//ES',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:casanova-exp-' . ($exp->Id ?? $exp->IdExpediente ?? uniqid()) . '@' . $_SERVER['HTTP_HOST'],
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART;VALUE=DATE:' . $dtStart,
    'DTEND;VALUE=DATE:' . $dtEnd,
    'SUMMARY:' . $escape($summary),
    'DESCRIPTION:' . $escape($desc),
    'LOCATION:' . $escape($destino),
    'STATUS:CONFIRMED',
    'TRANSP:TRANSPARENT', // Disponible (para que no bloquee reuniones si no quieren)
    'END:VEVENT',
    'END:VCALENDAR',
  ];

  return implode("\r\n", $lines);
}

/**
 * URL Frontend para descargar .ics (evita bloqueos a /wp-admin/)
 */
function casanova_portal_ics_url(int $idExpediente): string {
  $base = function_exists('casanova_portal_base_url')
    ? (string) casanova_portal_base_url()
    : home_url('/');

  return add_query_arg([
    'casanova_action' => 'download_ics',
    'expediente'      => (int) $idExpediente,
    '_wpnonce'        => wp_create_nonce('casanova_download_ics_' . (int)$idExpediente),
  ], $base);
}

/**
 * Handler para descargar el .ics
 */
function casanova_handle_download_ics() {
  if (!is_user_logged_in()) wp_die(__('No autorizado', 'casanova-portal'), 403);

  $user_id   = get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  $idExp     = (int) ($_GET['expediente'] ?? 0);

  if (!$idCliente || !$idExp) wp_die('Parámetros inválidos', 400);

  // Verificación de nonce
  $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
  if (!wp_verify_nonce($nonce, 'casanova_download_ics_' . $idExp)) {
    wp_die('Enlace caducado o inválido', 403);
  }

  // Seguridad: Verificar propiedad
  if (function_exists('casanova_user_can_access_expediente') && !casanova_user_can_access_expediente($user_id, $idExp)) {
    wp_die(__('No tienes permiso para acceder a este expediente.', 'casanova-portal'), 403);
  }

  // Obtener datos del viaje
  $exp = function_exists('casanova_giav_expediente_get') ? casanova_giav_expediente_get($idExp) : null;
  if (!$exp || is_wp_error($exp)) wp_die('Viaje no encontrado', 404);

  $ics_content = casanova_build_ics_content($exp);
  $filename = 'viaje-' . $idExp . '.ics';

  // Forzar descarga sin cache
  if (ob_get_level()) ob_end_clean();
  nocache_headers();
  header('Content-Type: text/calendar; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Content-Length: ' . strlen($ics_content));
  
  echo $ics_content;
  exit;
}

/**
 * Listener en INIT para capturar la acción desde el frontend
 * (Igual que en los pagos, para evitar admin-post.php)
 */
add_action('init', function() {
  if (empty($_REQUEST['casanova_action']) || $_REQUEST['casanova_action'] !== 'download_ics') return;

  casanova_handle_download_ics();
  exit;
}, 5);