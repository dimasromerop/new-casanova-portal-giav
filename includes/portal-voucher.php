<?php
if (!defined('ABSPATH')) exit;

function casanova_render_voucher_html(array $data): string {
  $a = $data['agencia'] ?? [];
  $c = $data['cliente'] ?? [];
  $p = $data['proveedor'] ?? [];
  $r = $data['reserva'] ?? null;
  $pasajeros = $data['pasajeros'] ?? [];
  $pdf_url = $data['pdf_url'] ?? '';
  $logo = $data['logo'] ?? '';

  if (!$r || !is_object($r)) return '<p>' . esc_html__('Voucher no disponible.', 'casanova-portal') . '</p>';

  $dxr = $r->DatosExternos ?? null;

  $s = function($v): string { return trim((string)($v ?? '')); };
  $pick = function(...$vals) use ($s): string {
    foreach ($vals as $v) {
      $x = $s($v);
      if ($x !== '') return $x;
    }
    return '';
  };
  
 // Expediente: Intentar el código “humano” del ERP (suele estar en DatosExternos)
$dxr = $r->DatosExternos ?? null;
$codExp = '';
if (is_object($dxr) && !empty($dxr->CodigoExpediente)) {
  $codExp = trim((string)$dxr->CodigoExpediente);
}

  // Servicio
  $tipo = $pick($r->TipoReserva ?? null, is_object($dxr) ? ($dxr->TipoReserva ?? null) : null);
  $desc = $pick($r->Descripcion ?? null, is_object($dxr) ? ($dxr->Descripcion ?? null) : null, $r->Concepto ?? null);
  $dest = $pick($r->Destino ?? null, is_object($dxr) ? ($dxr->Destino ?? null) : null);

  // Fechas
  $rango = casanova_fmt_date_range($r->FechaDesde ?? null, $r->FechaHasta ?? null);
  if ($rango === '' && is_object($dxr)) {
    $entrada = $pick($dxr->FechaEntrada ?? null);
    $salida  = $pick($dxr->FechaSalida ?? null);
    $rango = trim($entrada . ($entrada && $salida ? ' - ' : '') . $salida);
  }

  $loc = $pick($r->Localizador ?? null, is_object($dxr) ? ($dxr->Localizador ?? null) : null);

  // Incluye / condiciones
  $reg = $pick($r->Regimen ?? null, is_object($dxr) ? ($dxr->Regimen ?? null) : null);

  // Habitaciones (estructurado): puede venir en Reserva, en DatosExternos de Reserva,
// o (muy habitual) en PasajeroReserva / DatosExternos del pasajero.
$habit = casanova_reserva_room_types_text($r);
if ($habit === '') {
  $habit = $pick($r->Habitaciones ?? null, is_object($dxr) ? ($dxr->Habitaciones ?? null) : null);
}

if (current_user_can('manage_options')) {
  error_log('[CASANOVA] habit=' . $habit);
  if (is_object($dxr)) error_log('[CASANOVA] reserva DX keys: ' . implode(',', array_keys(get_object_vars($dxr))));
  if (!empty($pasajeros) && is_object($pasajeros[0] ?? null)) {
    $dxp = ($pasajeros[0]->DatosExternos ?? null);
    error_log('[CASANOVA] pasajero keys: ' . implode(',', array_keys(get_object_vars($pasajeros[0]))));
    if (is_object($dxp)) error_log('[CASANOVA] pasajero DX keys: ' . implode(',', array_keys(get_object_vars($dxp))));
  }
}

// Rooming (nota libre): esto sí es “texto” de la reserva.
// Puede venir en Reserva o en DatosExternos de Reserva con otro nombre.
$rooming = $pick(
  $r->Rooming ?? null,
  is_object($dxr) ? ($dxr->Rooming ?? $dxr->TextoRooming ?? null) : null
);

  // Pax
  $pax = (int)($r->NumPax ?? 0);
  $ad  = (int)($r->NumAdultos ?? 0);
  $ni  = (int)($r->NumNinos ?? 0);
  $paxTxt = $pax ? (string)$pax : trim(($ad ? $ad.' Adultos' : '') . ($ni ? ' · '.$ni.' Niños' : ''));

  // Extra
  $textoExtra = trim((string)($r->TextoBono ?? ''));

  // Importe (opcional)
  $show_importe = !empty($data['show_importe']);
  $venta = casanova_fmt_money($r->Venta ?? 0);

  // Pasajeros a nombres
  $pasajeros_names = [];
  if (is_array($pasajeros)) {
    foreach ($pasajeros as $pr0) {
      if (!is_object($pr0)) continue;
      $dx = $pr0->DatosExternos ?? null;

      $nombre = '';
      if (is_object($dx)) $nombre = trim((string)($dx->NombrePasajero ?? $dx->Nombre ?? ''));
      if ($nombre === '') $nombre = trim((string)($pr0->NombrePasajero ?? $pr0->Nombre ?? ''));
      if ($nombre === '') {
        $idp = (int)($pr0->IdPasajero ?? $pr0->Id ?? 0);
        $nombre = $idp > 0 ? sprintf(__('Pasajero #%d', 'casanova-portal'), $idp) : '';
      }
      if ($nombre !== '') $pasajeros_names[] = $nombre;
    }
  }
  // Deduplicar y ordenar (muy importante con fallback por expediente)
$pasajeros_names = array_values(array_unique(array_map(function($n) {
  $n = trim((string)$n);
  // normalizamos espacios para evitar duplicados tontos
  return preg_replace('/\s+/', ' ', $n);
}, $pasajeros_names)));

sort($pasajeros_names, SORT_FLAG_CASE | SORT_STRING);


  // Agencia / Cliente / Proveedor
  $ag_nombre = $pick($a['nombre'] ?? null);
  $ag_dir    = $pick($a['direccion'] ?? null);
  $ag_web    = $pick($a['web'] ?? null);
  $ag_email  = $pick($a['email'] ?? null);
  $ag_tel    = $pick($a['tel'] ?? null);

  $cl_nombre = $pick($c['nombre'] ?? null);

  $pr_nombre = $pick($p['nombre'] ?? null);
  $pr_tel    = $pick($p['tel'] ?? null);
  $pr_email  = $pick($p['email'] ?? null);
  $pr_dir    = $pick($p['direccion'] ?? null);

  $tipo_humano = casanova_human_service_type($tipo, $desc, $r);
  $is_golf = casanova_is_golf_service($tipo, $r);
$label_pax = $is_golf ? esc_html__('Jugadores', 'casanova-portal') : esc_html__('Pasajeros', 'casanova-portal');

$is_golf = casanova_is_golf_service($tipo, $r);
$pax_label = $is_golf ? 'Nº Jugadores' : 'Pax';

  $incluye = [];
if ($reg !== '') {
  $incluye[] = 'Régimen: ' . $reg;
}

if ($habit !== '') {
  $incluye[] = 'Distribución: ' . $habit;
}

if ($paxTxt !== '') {
  $incluye[] = $pax_label . ': ' . $paxTxt;
}

  ob_start();
  ?>
 <?php
// Dentro de casanova_render_voucher_html(), ya con tus variables calculadas:
// $ag_nombre, $ag_dir, $ag_email, $ag_tel, $ag_web, $logo
// $titulo_servicio, $tipo, $dest, $rango, $loc, $cl_nombre
// $pr_nombre, $pr_dir, $pr_email, $pr_tel
// $habit (distribución), $reg, $rooming
// $pasajeros_names, $textoExtra, $pdf_url

$issue_date = date_i18n('d/m/Y');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo esc_html__('Bono', 'casanova-portal'); ?></title>
  <style>
    @page { margin: 18mm 16mm; }
    body { font-family: Heveltica, sans-serif; color:#1C2926; max-width: 800px;margin: 10px auto;font-size: 12px; }
    .page { border: 2px solid #eee; padding: 14px; background: #ffffff}
    .muted { color:#444; opacity:.9; }
    .h1 { font-size: 18px; font-weight: 800; }
    .h2 { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing:.3px; }
    .hr { border-top:1px solid #eaeaea; margin: 10px 0; }
    .box { border:1px solid #eaeaea; border-radius: 10px; padding: 10px; }
    .no-break { page-break-inside: avoid; }

    table { width: 100%; border-collapse: collapse; }
    .t-head td { vertical-align: top; }
    .ref { background:#F8F4F1; /*border:1px solid #e6e7ea;*/ border-radius: 10px; padding: 10px; }
    .ref-big { font-size: 18px; font-weight: 900; letter-spacing:.3px; }
    .k { width: 34%; color:#333; opacity:.85; }
    .v { width: 66%; font-weight: 700; }

    .grid td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; }
    .grid tr:last-child td { border-bottom: 0; }
    .grid .k { font-weight: 700; opacity:.75; width: 28%; }
    .grid .v { font-weight: 700; width: 72%; }

    .tbl th, .tbl td { border: 1px solid #ececec; padding: 7px 8px; font-size: 12px; }
    .tbl th { background: #fafafa; text-align: left; }

    ul { margin: 6px 0 0 18px; padding: 0; }
  </style>
</head>

<body>
  <div class="page">

    <?php if ($pdf_url !== ''): ?>
      <div style="text-align:right;margin-bottom:0px;">
        <a href="<?php echo esc_url($pdf_url); ?>" style="text-decoration:none;border:1px solid #e5e7eb;border-radius:10px;padding:7px 10px;display:inline-block;">
          <?php echo esc_html__('Descargar PDF', 'casanova-portal'); ?>
        </a>
      </div>
    <?php endif; ?>

    <!-- HEADER (tabla para Dompdf) -->
    <table class="t-head no-break">
        <tr>
        <td colspan="2" style="width:100%;text-align:center;">
          <?php if (!empty($logo)): ?>
            <img src="<?php echo esc_attr($logo); ?>" style="height:110px; margin-bottom:6px;">
          <?php endif; ?>
          </td></tr>
      <tr>
        <td style="width:55%;">
                    <div style="font-weight:900;font-size:16px;"><?php echo esc_html($ag_nombre); ?></div>
          <div class="muted" style="margin-top:4px;line-height:1.4;">
            <?php if ($ag_dir): ?><?php echo esc_html($ag_dir); ?><br><?php endif; ?>
            <?php if ($ag_web): ?><?php echo esc_html($ag_web); ?><br><?php endif; ?>
            <?php if ($ag_email): ?><?php echo esc_html($ag_email); ?><br><?php endif; ?>
            <?php if ($ag_tel): ?><?php echo esc_html($ag_tel); ?><?php endif; ?>
          </div>
        </td>

        <td style="width:45%; text-align:right;">
          <div class="h1"><?php echo esc_html__('Bono / Voucher', 'casanova-portal'); ?></div>
          <?php if ($codExp !== '' || $idExp > 0): ?>
  <div class="muted" style="margin-top:6px;">
    Expediente:
    <strong>
      <?php
        // si existe el código humano, muéstralo; si no, el id
        echo esc_html($codExp !== '' ? $codExp : (string)$idExp);
      ?>
    </strong>
  </div>
<?php endif; ?>

          <?php if (!empty($tipo)): ?>
            <div class="muted" style="margin-top:4px;"><?php echo esc_html($tipo_humano); ?></div>
          <?php endif; ?>
          <div class="muted" style="margin-top:8px;"><?php echo esc_html__('Fecha emisión:', 'casanova-portal'); ?> <strong><?php echo esc_html($issue_date); ?></strong></div>
          <?php if (!empty($cl_nombre)): ?>
            <div class="muted" style="margin-top:6px;"><?php echo esc_html__('Cliente:', 'casanova-portal'); ?> <strong><?php echo esc_html($cl_nombre); ?></strong></div>
          <?php endif; ?>
        </td>
      </tr>
    </table>

    <div class="hr"></div>

    <!-- REFERENCIA -->
    <div class="ref no-break">
      <table>
        <tr>
          <td style="width:40%; vertical-align:top;">
            <div class="muted"><?php echo esc_html__('Localizador / Reference', 'casanova-portal'); ?></div>
            <div class="ref-big"><?php echo esc_html($loc ?: '—'); ?></div>
          </td>
          <td style="width:60%; vertical-align:top;">
            <div class="muted"><?php echo esc_html__('Servicio', 'casanova-portal'); ?></div>
            <div style="font-weight:900;font-size:14px;"><?php echo esc_html($desc); ?></div>
            <?php if (!empty($rango)): ?>
              <div class="muted" style="margin-top:4px;"><?php echo esc_html__('Fechas:', 'casanova-portal'); ?> <strong><?php echo esc_html($rango); ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($dest)): ?>
              <div class="muted" style="margin-top:4px;"><?php echo esc_html__('Destino:', 'casanova-portal'); ?> <strong><?php echo esc_html($dest); ?></strong></div>
            <?php endif; ?>
          </td>
        </tr>
      </table>
    </div>

    <div style="height:10px;"></div>

    <!-- DATOS PRINCIPALES -->
    <div class="box no-break">
      <div class="h2" style="margin-bottom:6px;"><?php echo esc_html__('Detalles del servicio', 'casanova-portal'); ?></div>
      <table class="grid">
        <?php if ($pr_nombre): ?>
          <tr><td class="k"><?php echo esc_html__('Proveedor', 'casanova-portal'); ?></td><td class="v"><?php echo esc_html($pr_nombre); ?></td></tr>
        <?php endif; ?>
        <?php if ($paxTxt): ?>
          <td class="k"><?php echo esc_html($pax_label); ?></td>
  <td class="v"><?php echo esc_html($paxTxt); ?></td>
        <?php endif; ?>
        <?php if ($habit): ?>
          <tr><td class="k"><?php echo esc_html__('Distribución', 'casanova-portal'); ?></td><td class="v"><?php echo esc_html($habit); ?></td></tr>
        <?php endif; ?>
        <?php if ($reg): ?>
          <tr><td class="k"><?php echo esc_html__('Régimen', 'casanova-portal'); ?></td><td class="v"><?php echo esc_html($reg); ?></td></tr>
        <?php endif; ?>
      </table>

      <?php if (!empty($rooming)): ?>
        <div style="margin-top:8px;">
          <div class="h2" style="font-size:12px;"><?php echo esc_html__('Rooming', 'casanova-portal'); ?></div>
          <div style="white-space:pre-wrap;"><?php echo esc_html($rooming); ?></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($pasajeros_names)): ?>
        <div style="margin-top:8px;">
          <div class="h2" style="font-size:12px;"><?php echo esc_html($label_pax); ?></div>
          <ul>
            <?php foreach ($pasajeros_names as $nm): ?>
              <li><?php echo esc_html($nm); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>

    <!-- OBSERVACIONES -->
    <?php if (!empty($textoExtra)): ?>
      <div style="height:10px;"></div>
      <div class="box no-break">
        <div class="h2" style="margin-bottom:6px;"><?php echo esc_html__('Observaciones / Remarks', 'casanova-portal'); ?></div>
        <div style="white-space:pre-wrap; line-height:1.45;"><?php echo esc_html($textoExtra); ?></div>
      </div>
    <?php endif; ?>

    <div style="height:10px;"></div>

    <!-- FOOTER CONTACTOS -->
    <div class="muted" style="font-size:11px; line-height:1.35;">
      <strong><?php echo esc_html__('Contacto proveedor:', 'casanova-portal'); ?></strong>
      <?php echo esc_html(trim($pr_dir)); ?>
      <?php if ($pr_email) echo ' · ' . esc_html($pr_email); ?>
      <?php if ($pr_tel) echo ' · ' . esc_html($pr_tel); ?>
      <br>
      <strong><?php echo esc_html__('Contacto agencia:', 'casanova-portal'); ?></strong>
      <?php echo esc_html($ag_email); ?><?php if ($ag_tel) echo ' · ' . esc_html($ag_tel); ?>
      <br>
      <?php echo esc_html__('Este bono es válido únicamente si el expediente no tiene pagos pendientes.', 'casanova-portal'); ?>
    </div>

  </div>
</body>
</html>

  <?php
  return ob_get_clean();
}