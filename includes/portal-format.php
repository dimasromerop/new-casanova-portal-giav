<?php
if (!defined('ABSPATH')) exit;

function casanova_fmt_date($date, string $format = 'd/m/Y'): string {
  if (empty($date)) return '';
  $ts = strtotime((string)$date);
  if (!$ts) return '';
  return date_i18n($format, $ts);
}

function casanova_fmt_date_range($from, $to): string {
  $a = casanova_fmt_date($from);
  $b = casanova_fmt_date($to);
  if ($a && $b) return $a . ' – ' . $b;
  return $a ?: $b;
}

function casanova_fmt_money($amount, string $currency = '€'): string {
  $n = is_numeric($amount) ? (float)$amount : 0.0;
  return number_format($n, 2, ',', '.') . ' ' . $currency;
}

function casanova_badge(string $text, string $type = 'neutral'): string {
  $map = [
    'ok' => 'background:#e8f7ee;color:#167a3f;border:1px solid #bfe9cf;',
    'warn' => 'background:#fff7e6;color:#8a5a00;border:1px solid #ffe0a6;',
    'bad' => 'background:#fdecec;color:#a11919;border:1px solid #f7b7b7;',
    'neutral' => 'background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;',
  ];
  $style = $map[$type] ?? $map['neutral'];

  return '<span style="display:inline-block;padding:4px 8px;border-radius:999px;font-size:.85em;line-height:1;white-space:nowrap;' . esc_attr($style) . '">' . esc_html($text) . '</span>';
}

function casanova_badge_from_mapped_estado(array $m): string {
  [$txt, $type] = casanova_reserva_estado_from_mapped($m);
  return casanova_badge($txt, $type);
}


function casanova_group_by(array $items, callable $keyFn): array {
  $out = [];
  foreach ($items as $it) {
    $k = (string) $keyFn($it);
    if ($k === '') $k = 'Otros';
    if (!isset($out[$k])) $out[$k] = [];
    $out[$k][] = $it;
  }
  return $out;
}

function casanova_reserva_actions_html($r, int $idExpediente, bool $expediente_pagado): string {
  $idReserva = (string)($r->Id ?? '');
  if ($idReserva === '') $idReserva = (string)($r->Codigo ?? '');
  if ($idReserva === '') return '';

  $btn = function(?string $href, string $label, bool $disabled = false) {
    $style = 'display:inline-block;padding:6px 10px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;font-size:.9em;line-height:1;white-space:nowrap;';
    if ($disabled) return '<span style="' . esc_attr($style) . 'opacity:.45;cursor:not-allowed;">' . esc_html($label) . '</span>';
    return '<a href="' . esc_url($href) . '" style="' . esc_attr($style) . '">' . esc_html($label) . '</a>';
  };

  $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');
  $view = add_query_arg(['expediente' => $idExpediente, 'reserva' => (int)$idReserva], $base);

  $voucher_preview_url = add_query_arg([
  'action' => 'casanova_voucher',
  'expediente' => $idExpediente,
  'reserva' => (int)$idReserva,
  '_wpnonce' => wp_create_nonce('casanova_voucher_' . $idExpediente . '_' . (int)$idReserva),
], admin_url('admin-post.php'));

  
  $voucher_pdf_url = add_query_arg([
  'action' => 'casanova_voucher_pdf',
  'expediente' => $idExpediente,
  'reserva' => (int)$idReserva,
  '_wpnonce' => wp_create_nonce('casanova_voucher_' . $idExpediente . '_' . (int)$idReserva),
], admin_url('admin-post.php'));


  $out = '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
  $out .= $btn($view, esc_html__('Ver', 'casanova-portal'));

 if (!$expediente_pagado) {
  $out .= $btn(null, esc_html__('Ver bono', 'casanova-portal'), true);
  $out .= $btn(null, esc_html__('PDF', 'casanova-portal'), true);
} else {
  $out .= $btn($voucher_preview_url, esc_html__('Ver bono', 'casanova-portal'));
  $out .= $btn($voucher_pdf_url, esc_html__('PDF', 'casanova-portal'));
}

  $out .= '</div>';
  return $out;
}


function casanova_reserva_nombre_bono($r): string {
  if (!$r || !is_object($r)) return '';

  // 1) Campo directo
  $v = isset($r->ClienteBono) ? trim((string)$r->ClienteBono) : '';
  if ($v !== '') return $v;

  // 2) A veces va en Rooming (depende del tipo de reserva)
  $v = isset($r->Rooming) ? trim((string)$r->Rooming) : '';
  if ($v !== '') return $v;

  // 3) A veces está dentro de CustomDataValues (si GIAV lo usa)
  // Como no sabemos la estructura exacta, hacemos una búsqueda defensiva.
  if (isset($r->CustomDataValues) && $r->CustomDataValues) {
    $dump = print_r($r->CustomDataValues, true);

    // Si en ese dump aparece algo tipo "ClienteBono" o "Nombre", te lo extraemos luego fino.
    // Por ahora: si hay pista, al menos sabemos que está ahí.
    // (No devolvemos nada “a ciegas”).
  }

  return '';
}

function casanova_bono_servicio_btn($r, int $idExpediente, bool $expediente_pagado): string {
  $idReserva = (int)($r->Id ?? 0);
  if ($idReserva <= 0) return '';

  $style = 'display:inline-block;padding:6px 10px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;font-size:.9em;line-height:1;white-space:nowrap;';
  $wrap  = 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;';

  $btn = function(?string $href, string $label, bool $disabled = false) use ($style) {
    if ($disabled) {
      return '<span style="' . esc_attr($style) . 'opacity:.45;cursor:not-allowed;">' . esc_html($label) . '</span>';
    }
    return '<a href="' . esc_url($href) . '" style="' . esc_attr($style) . '">' . esc_html($label) . '</a>';
  };

  // Gate principal: pago a nivel expediente
  if (!$expediente_pagado) {
    return '<div style="' . esc_attr($wrap) . '">'
      . $btn(null, esc_html__('Ver bono', 'casanova-portal'), true)
      . $btn(null, esc_html__('PDF', 'casanova-portal'), true)
      . '</div>';
  }

  // NO usamos Pendiente por servicio para bloquear, pero si quieres mantenerlo como "cinturón"
  // lo dejamos solo como deshabilitado (sin romper tu lógica anterior).
  $pend = (float)($r->Pendiente ?? 0);
  if ($pend > 0.01) {
    return '<div style="' . esc_attr($wrap) . '">'
      . $btn(null, esc_html__('Ver bono', 'casanova-portal'), true)
      . $btn(null, esc_html__('PDF', 'casanova-portal'), true)
      . '</div>';
  }

  // Preview HTML
  $preview_url = function_exists('casanova_portal_voucher_url')
    ? casanova_portal_voucher_url($idExpediente, $idReserva, 'view')
    : add_query_arg([
      'action'    => 'casanova_voucher',
      'expediente'=> $idExpediente,
      'reserva'   => $idReserva,
      '_wpnonce'  => wp_create_nonce('casanova_voucher_' . $idExpediente . '_' . $idReserva),
    ], admin_url('admin-post.php'));
// PDF
  $pdf_url = function_exists('casanova_portal_voucher_url')
    ? casanova_portal_voucher_url($idExpediente, $idReserva, 'pdf')
    : add_query_arg([
      'action'    => 'casanova_voucher_pdf',
      'expediente'=> $idExpediente,
      'reserva'   => $idReserva,
      '_wpnonce'  => wp_create_nonce('casanova_voucher_' . $idExpediente . '_' . $idReserva),
    ], admin_url('admin-post.php'));
return '<div style="' . esc_attr($wrap) . '">'
    . $btn($preview_url, esc_html__('Ver bono', 'casanova-portal'))
    . $btn($pdf_url, esc_html__('PDF', 'casanova-portal'))
    . '</div>';
}



function casanova_pagar_expediente_btn(int $idExpediente, bool $expediente_pagado, float $total_pend): string {
  $style = 'display:inline-block;padding:6px 10px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;font-size:.9em;line-height:1;white-space:nowrap;';

  if ($expediente_pagado) {
    return '<span style="' . esc_attr($style) . 'opacity:.45;cursor:not-allowed;">' . esc_html__('Pagado', 'casanova-portal') . '</span>';
  }

  // Iniciar pago desde frontend (evita bloqueos a /wp-admin/ para no-admin)
  if (function_exists('casanova_portal_pay_expediente_url')) {
    $url = casanova_portal_pay_expediente_url($idExpediente);
  } else {
    $url = add_query_arg([
      'action' => 'casanova_pay_expediente',
      'expediente' => $idExpediente,
      '_wpnonce'   => wp_create_nonce('casanova_pay_expediente_' . $idExpediente),
    ], admin_url('admin-post.php'));
  }

  return '<a href="' . esc_url($url) . '" style="' . esc_attr($style) . '">' . esc_html__('Pagar', 'casanova-portal') . '</a>';
}

function casanova_pdf_logo_data_uri(): string {
  // Opción A: constante con URL del logo (recomendado que sea en uploads)
  $url = defined('CASANOVA_AGENCY_LOGO_URL') ? CASANOVA_AGENCY_LOGO_URL : '';
  if (!$url) return '';

  // Convertir URL a path local si es de tu dominio/uploads
  $uploads = wp_get_upload_dir();
  if (strpos($url, $uploads['baseurl']) === 0) {
    $path = $uploads['basedir'] . substr($url, strlen($uploads['baseurl']));
  } else {
    // Si no es local, Dompdf puede fallar. Mejor no insistir.
    return '';
  }

  if (!file_exists($path)) return '';

  $bin = file_get_contents($path);
  if ($bin === false) return '';

  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  $mime = $ext === 'png' ? 'image/png' : ($ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png');

  return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

function casanova_reserva_room_types_text($r): string {
  if (!is_object($r)) return '';

  $candidates = [];

  // 1) ¿Viene anidado como TiposDeHabitacion?
  $th = $r->TiposDeHabitacion ?? $r->tiposDeHabitacion ?? null;
  if (is_object($th)) {
    $candidates[] = $th;
  }

  // 2) ¿Viene en la raíz del objeto reserva?
  $candidates[] = $r;

  foreach ($candidates as $obj) {
    if (!is_object($obj)) continue;

    $parts = [];
    for ($i = 1; $i <= 4; $i++) {
      // puede ser uso1/num1 o Uso1/Num1
      $uso = $obj->{'uso'.$i} ?? $obj->{'Uso'.$i} ?? null;
      $num = $obj->{'num'.$i} ?? $obj->{'Num'.$i} ?? null;

      $uso = is_string($uso) ? trim($uso) : (is_object($uso) && isset($uso->value) ? trim((string)$uso->value) : trim((string)($uso ?? '')));
      $num = is_numeric($num) ? (int)$num : (int)($num ?? 0);

      if ($uso !== '' && $num > 0) {
        $parts[] = $num . ' ' . $uso;
      }
    }

    if (!empty($parts)) {
      return implode(', ', $parts);
    }
  }

  return '';
}