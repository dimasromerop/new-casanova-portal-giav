<?php
/**
 * GIAV - Facturas (Factura_SEARCH + FacturaPDF_GET)
 */

/**
 * Buscar facturas del expediente para el cliente logueado.
 *
 * En el WSDL, Factura_SEARCH obliga a enviar:
 * - AmountFrom / AmountTo (nillable=true)
 * - AmounPendeningCompensateFrom / To (nillable=true)
 * - HasCompensations / IsRemitted / IsPrepayment (FiltroBoleanoOpcional)
 * - pageSize (1-100) y pageIndex
 *
 * + idsExpediente / idsCliente como filtros.
 */
 
 function casanova_giav_facturas_por_expediente(int $idExpediente, int $idCliente, int $pageSize = 50, int $pageIndex = 0) {

  // Cache corta para no machacar SOAP.
  if (function_exists('casanova_cache_remember')) {
    return casanova_cache_remember(
      'giav:facturas_por_expediente:' . (int)$idExpediente . ':' . (int)$idCliente . ':' . (int)$pageSize . ':' . (int)$pageIndex,
      defined('CASANOVA_CACHE_TTL') ? (int)CASANOVA_CACHE_TTL : 90,
      function () use ($idExpediente, $idCliente, $pageSize, $pageIndex) {
        return casanova_giav_facturas_por_expediente_uncached($idExpediente, $idCliente, $pageSize, $pageIndex);
      }
    );
  }

  return casanova_giav_facturas_por_expediente_uncached($idExpediente, $idCliente, $pageSize, $pageIndex);
 }

 /**
  * Implementación real (sin cache).
  */
 function casanova_giav_facturas_por_expediente_uncached(int $idExpediente, int $idCliente, int $pageSize = 50, int $pageIndex = 0) {

  $pageSize  = max(1, min(100, (int)$pageSize));
  $pageIndex = max(0, (int)$pageIndex);

  $p = new stdClass();
  $p->apikey = CASANOVA_GIAV_APIKEY;

  // filtros principales (ArrayOfInt style)
  $p->idsExpediente = (object) ['int' => [(int)$idExpediente]];
  $p->idsCliente    = (object) ['int' => [(int)$idCliente]];

  // Filtros importes (nillable)
  $p->importeTotalaPagarDesde = null;
  $p->importeTotalaPagarHasta = null;
  $p->importePteCobroDesde    = null;
  $p->importePteCobroHasta    = null;

  // flags obligatorios
  $p->traspasado = 'NoAplicar';
  $p->mostrarSoloConPtePago = false;     // ✅ CAMBIO CRÍTICO
  $p->liquidada = 'NoAplicar';
  $p->simplificada = 'NoAplicar';

  // Otros (nillable)
  $p->idModalidadFactura = null;
  $p->importeTotalDesde  = null;
  $p->importeTotalHasta  = null;

  // paginación
  $p->pageSize  = $pageSize;
  $p->pageIndex = $pageIndex;

  $resp = casanova_giav_call('Factura_SEARCH', $p);
  if (is_wp_error($resp)) return $resp;

  $result = $resp->Factura_SEARCHResult ?? null;
  return casanova_giav_normalize_list($result, 'WsFactura');
}


/**
 * Descarga PDF de factura por ID usando FacturaPDF_GET (devuelve string).
 * OJO: normalmente es base64 del PDF.
 */
function casanova_giav_factura_pdf_get(int $idFactura) {
  $p = new stdClass();
  $p->apikey = CASANOVA_GIAV_APIKEY;
  $p->id     = (int)$idFactura;

  $resp = casanova_giav_call('FacturaPDF_GET', $p);
  if (is_wp_error($resp)) return $resp;

  return (string)($resp->FacturaPDF_GETResult ?? '');
}

/**
 * Endpoint para descargar factura (admin-post.php).
 */
add_action('admin_post_casanova_invoice_pdf', function () {

 $user_id     = get_current_user_id();
 $idExpediente = (int) ($_GET['expediente'] ?? 0);
 $user_id = get_current_user_id();

error_log('[CASANOVA] invoice_pdf: is_user_logged_in=' . (is_user_logged_in() ? '1' : '0'));
error_log('[CASANOVA] invoice_pdf: user_id=' . $user_id);
error_log('[CASANOVA] invoice_pdf: casanova_idcliente=' . get_user_meta($user_id, 'casanova_idcliente', true));
error_log('[CASANOVA] invoice_pdf: REQUEST=' . print_r($_REQUEST, true));

if (!casanova_user_can_access_expediente($user_id, $idExpediente)) {
  wp_die(esc_html__('No autorizado', 'casanova-portal'), 403);
}

  $idExpediente = isset($_GET['expediente']) ? (int)$_GET['expediente'] : 0;
  $idFactura    = isset($_GET['factura']) ? (int)$_GET['factura'] : 0;
  $nonce        = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';

  if ($idExpediente <= 0 || $idFactura <= 0) wp_die('Faltan parámetros.');

  if (!wp_verify_nonce($nonce, 'casanova_invoice_pdf_' . $idExpediente . '_' . $idFactura)) {
    wp_die('Nonce inválido.');
  }

  $user_id   = get_current_user_id();
  $idCliente = (int)get_user_meta($user_id, 'casanova_idcliente', true);
  if ($idCliente <= 0) wp_die(esc_html__('Cuenta no vinculada.', 'casanova-portal'));

  // Seguridad: comprobar que esta factura pertenece al expediente y cliente
  $facturas = casanova_giav_facturas_por_expediente($idExpediente, $idCliente, 100, 0);
  if (is_wp_error($facturas)) wp_die(esc_html__('No se pudo validar la factura.', 'casanova-portal'));

  $ok = false;
  foreach ($facturas as $f) {
    if ((int)($f->Id ?? 0) === $idFactura) { $ok = true; break; }
  }
  if (!$ok) wp_die(esc_html__('No tienes acceso a esta factura.', 'casanova-portal'));

  // Obtener PDF desde GIAV
  $pdf_str = casanova_giav_factura_pdf_get($idFactura);
if (is_wp_error($pdf_str) || $pdf_str === '') {
  wp_die(esc_html__('No se pudo generar el PDF.', 'casanova-portal'));
}

$raw = trim((string)$pdf_str);

// Caso A: GIAV devuelve una ruta tipo /Fichero.aspx?P=...
if (stripos($raw, '/Fichero.aspx') === 0 || stripos($raw, 'Fichero.aspx') !== false) {

  $host = casanova_giav_base_host();
  if (!$host) {
    wp_die('No se pudo resolver el host de GIAV.');
  }

  // Si viene relativo, lo hacemos absoluto
  $file_url = $raw;
  if (stripos($file_url, 'http://') !== 0 && stripos($file_url, 'https://') !== 0) {
    if ($file_url[0] !== '/') $file_url = '/' . $file_url;
    $file_url = $host . $file_url;
  }

  $dl = casanova_http_get_binary($file_url);
  if (is_wp_error($dl)) {
    error_log('[CASANOVA] Error descargando Fichero.aspx: ' . $dl->get_error_message());
    wp_die('No se pudo descargar el PDF desde GIAV.');
  }

  $bin = (string)$dl['body'];
  $content_type = (string)$dl['content_type'];

} else {

  // Caso B: base64 (por si en otros entornos lo devuelven así)
  if (stripos($raw, 'base64,') !== false) {
    $raw = substr($raw, stripos($raw, 'base64,') + 7);
  }
  $raw = preg_replace('/\s+/', '', $raw);

  $bin = base64_decode($raw, true);
  if ($bin === false) $bin = base64_decode($raw, false);
  if (!$bin) {
    wp_die(esc_html__('GIAV no ha devuelto un PDF válido.', 'casanova-portal'));
  }

  $content_type = 'application/pdf';
}

// Validación final: que parezca PDF
$pos = strpos($bin, '%PDF');
if ($pos === false) {
  error_log('[CASANOVA] Descarga factura: contenido no PDF. Head=' . substr($bin, 0, 200));
  wp_die(esc_html__('GIAV no ha devuelto un PDF válido.', 'casanova-portal'));
}
if ($pos > 0) $bin = substr($bin, $pos);

// limpiar buffers
while (ob_get_level()) { @ob_end_clean(); }

$filename = 'factura_' . $idFactura . '.pdf';

nocache_headers();
header('Content-Type: ' . $content_type);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . strlen($bin));
echo $bin;
exit;


});


/**
 * Shortcode: lista facturas del expediente seleccionado.
 * Uso: pon [casanova_facturas] en la plantilla del detalle de expediente.
 */
add_shortcode('casanova_facturas', function () {

  if (!is_user_logged_in()) return '<p>' . esc_html__('Debes iniciar sesión.', 'casanova-portal') . '</p>';

  $user_id   = get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if ($idCliente <= 0) return '<p>' . esc_html__('Tu cuenta no está vinculada todavía.', 'casanova-portal') . '</p>';

  $idExpediente = isset($_GET['expediente']) ? (int) $_GET['expediente'] : 0;
  if ($idExpediente <= 0) return '<p>' . esc_html__('Selecciona un expediente para ver sus facturas.', 'casanova-portal') . '</p>';

  $facturas = casanova_giav_facturas_por_expediente($idExpediente, $idCliente, 100, 0);
  if (is_wp_error($facturas)) return '<p>' . esc_html__('No se han podido cargar las facturas. Inténtalo más tarde.', 'casanova-portal') . '</p>';

  // Orden: más nuevas primero
  usort($facturas, function($a, $b){
    $da = isset($a->FechaEmision) ? strtotime((string)$a->FechaEmision) : 0;
    $db = isset($b->FechaEmision) ? strtotime((string)$b->FechaEmision) : 0;
    return $db <=> $da;
  });
  
  $facturas = array_values(array_filter($facturas, function($f){
  return is_object($f) && (int)($f->Id ?? 0) > 0;
}));

if (empty($facturas)) return '<p>' . esc_html__('Todavía no hay facturas emitidas.', 'casanova-portal') . '</p>';

  ob_start();

  echo '<div class="casanova-portal casanova-facturas">';
  echo '<div class="casanova-card">';
  echo '<div class="casanova-section-title">' . esc_html__('Facturas', 'casanova-portal') . '</div>';
  echo '<div class="casanova-tablewrap">';
  echo '<table class="casanova-table">';
  echo '<thead><tr>';
  echo '<th>' . esc_html__('Factura', 'casanova-portal') . '</th>';
  echo '<th>' . esc_html__('Fecha', 'casanova-portal') . '</th>';
  echo '<th class="num">' . esc_html__('Importe', 'casanova-portal') . '</th>';
  echo '<th>' . esc_html__('Estado', 'casanova-portal') . '</th>';
  echo '<th>' . esc_html__('Acciones', 'casanova-portal') . '</th>';
  echo '</tr></thead><tbody>';

  foreach ($facturas as $f) {

    $id = (int)($f->Id ?? 0);
    if ($id <= 0) continue;

    // Etiqueta factura
    $label = '';
    if (!empty($f->Codigo)) $label = (string)$f->Codigo;
    else $label = sprintf(__('Factura #%s', 'casanova-portal'), $id);

    // Fecha
    $fecha = '';
    if (!empty($f->FechaEmision)) $fecha = date_i18n('d/m/Y', strtotime((string)$f->FechaEmision));

    // Total (preferimos DatosExternos->TotalFactura)
    $total = 0.0;
    if (isset($f->DatosExternos) && is_object($f->DatosExternos) && isset($f->DatosExternos->TotalFactura)) {
      $total = (float)$f->DatosExternos->TotalFactura;
    } elseif (isset($f->Total)) {
      $total = (float)$f->Total;
    } elseif (isset($f->Importe)) {
      $total = (float)$f->Importe;
    }

    // Pendiente cobro (para estado)
    $pendiente = 0.0;
    if (isset($f->DatosExternos) && is_object($f->DatosExternos) && isset($f->DatosExternos->PendienteCobro)) {
      $pendiente = (float)$f->DatosExternos->PendienteCobro;
    }

    $estado = ($pendiente > 0.01) ? esc_html__('Pendiente', 'casanova-portal') : 'Pagada';

    // Descargar PDF (cuando el endpoint ya esté listo)
    $url = add_query_arg([
      'action'     => 'casanova_invoice_pdf',
      'expediente' => $idExpediente,
      'factura'    => $id,
      '_wpnonce'   => wp_create_nonce('casanova_invoice_pdf_' . $idExpediente . '_' . $id),
    ], admin_url('admin-post.php'));

    echo '<tr>';
    echo '<td>' . esc_html($label) . '</td>';
    echo '<td>' . esc_html($fecha) . '</td>';
    echo '<td class="num">' . esc_html(casanova_fmt_money($total)) . '</td>';

    // Estado con badge simple
    echo '<td>';
    if ($pendiente > 0.01) {
      echo '<span class="casanova-badge casanova-badge--pending">' . esc_html__('Pendiente', 'casanova-portal') . '</span>';
    } else {
      echo '<span class="casanova-badge casanova-badge--pay">' . esc_html__('Pagada', 'casanova-portal') . '</span>';
    }
    echo '</td>';

    echo '<td>';
    echo '<a class="casanova-btn casanova-btn--sm casanova-btn--ghost" href="' . esc_url($url) . '">' . esc_html__('Descargar PDF', 'casanova-portal') . '</a>';
    echo '</td>';

    echo '</tr>';
  }

  echo '</tbody></table></div></div></div>';

  return ob_get_clean();
});