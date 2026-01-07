<?php
// includes/portal-mail-templates.php
if (!defined('ABSPATH')) exit;

function casanova_mail_wrap_html(string $title, string $bodyHtml): string {
  $site = esc_html(get_bloginfo('name'));
  $titleEsc = esc_html($title);

  return '
  <div style="font-family:Arial,Helvetica,sans-serif;line-height:1.45;color:#111">
    <div style="max-width:640px;margin:0 auto;padding:24px">
      <h2 style="margin:0 0 12px 0;font-size:20px;">' . $titleEsc . '</h2>
      <div style="font-size:14px;">' . $bodyHtml . '</div>
      <hr style="border:none;border-top:1px solid #eee;margin:18px 0;">
      <div style="font-size:12px;color:#555">
        ' . $site . '
      </div>
    </div>
  </div>';
}

function casanova_portal_url_expediente(int $idExpediente): string {
  // Ajusta esta URL a tu ruta real del portal si es distinta
  // Ej: /mi-cuenta/expediente/?expediente=123
  $base = function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/');
  return add_query_arg(['expediente' => $idExpediente], $base);
}

function casanova_tpl_email_confirmacion_cobro(array $ctx): array {
  // ctx esperado: cliente_nombre, idExpediente, codigoExpediente, importe, fecha, pagado, pendiente
  $cliente = esc_html((string)($ctx['cliente_nombre'] ?? ''));
  $idExp = (int)($ctx['idExpediente'] ?? 0);
  $codExp = (string)($ctx['codigoExpediente'] ?? '');
  $importe = (string)($ctx['importe'] ?? '');
  $fecha = (string)($ctx['fecha'] ?? '');
  $pagado = (string)($ctx['pagado'] ?? '');
  $pendiente = (string)($ctx['pendiente'] ?? '');

  $expLabel = $codExp !== '' ? esc_html($codExp) : ('#' . $idExp);
  $url = esc_url(casanova_portal_url_expediente($idExp));

  $subject = sprintf(__('Confirmación de pago recibido – Expediente %s', 'casanova-portal'), $expLabel);

  $body = '';
  if ($cliente !== '') {
    $body .= '<p>' . sprintf(esc_html__('Hola %s,', 'casanova-portal'), $cliente) . '</p>';
  } else {
    $body .= '<p>' . esc_html__('Hola,', 'casanova-portal') . '</p>';
  }

  $body .= '<p>' . sprintf(wp_kses_post(__('Hemos registrado un pago para tu expediente <strong>%s</strong>.', 'casanova-portal')), $expLabel) . '</p>';

  $body .= '<table style="width:70%;border-collapse:collapse;margin:12px 0;font-size:14px;">';
  $body .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html__('Importe', 'casanova-portal') . '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;"><strong>' . esc_html($importe) . '</strong></td></tr>';
  if ($fecha !== '') {
    $body .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html__('Fecha', 'casanova-portal') . '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . esc_html($fecha) . '</td></tr>';
  }
  if ($pagado !== '') {
    $body .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html__('Total pagado', 'casanova-portal') . '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . esc_html($pagado) . '</td></tr>';
  }
  if ($pendiente !== '') {
    $body .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html__('Pendiente', 'casanova-portal') . '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . esc_html($pendiente) . '</td></tr>';
  }
  $body .= '</table>';

  $body .= '<p>' . esc_html__('Puedes ver el estado actualizado aquí:', 'casanova-portal') . '</p>';
  $body .= '<p><a href="' . $url . '" style="display:inline-block;padding:10px 14px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">' . esc_html__('Ver expediente', 'casanova-portal') . '</a></p>';

  $html = casanova_mail_wrap_html(__('Pago recibido', 'casanova-portal'), $body);

  return ['subject' => $subject, 'html' => $html];
}

function casanova_tpl_email_expediente_pagado(array $ctx): array {
  // ctx esperado: cliente_nombre, idExpediente, codigoExpediente, total_objetivo, pagado, fecha (opcional)
  $cliente = esc_html((string)($ctx['cliente_nombre'] ?? ''));
  $idExp = (int)($ctx['idExpediente'] ?? 0);
  $codExp = (string)($ctx['codigoExpediente'] ?? '');
  $total = (string)($ctx['total_objetivo'] ?? '');
  $pagado = (string)($ctx['pagado'] ?? '');
  $fecha = (string)($ctx['fecha'] ?? '');

  $expLabel = $codExp !== '' ? esc_html($codExp) : ('#' . $idExp);
  $url = esc_url(casanova_portal_url_expediente($idExp));

  $subject = sprintf(__('Pago completado – Documentación disponible – Expediente %s', 'casanova-portal'), $expLabel);

  $body = '';
  if ($cliente !== '') {
    $body .= '<p>' . sprintf(esc_html__('Hola %s,', 'casanova-portal'), $cliente) . '</p>';
  } else {
    $body .= '<p>' . esc_html__('Hola,', 'casanova-portal') . '</p>';
  }

  $body .= '<p>' . sprintf(wp_kses_post(__('Tu expediente <strong>%s</strong> está <strong>completamente pagado</strong>.', 'casanova-portal')), $expLabel) . '</p>';

  $body .= '<table style="width:70%;border-collapse:collapse;margin:12px 0;font-size:14px;">';
  if ($total !== '') {
    $body .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html__('Total expediente', 'casanova-portal') . '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . esc_html($total) . '</td></tr>';
  }
  if ($pagado !== '') {
    $body .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html__('Total pagado', 'casanova-portal') . '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;"><strong>' . esc_html($pagado) . '</strong></td></tr>';
  }
  if ($fecha !== '') {
    $body .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . esc_html__('Fecha', 'casanova-portal') . '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . esc_html($fecha) . '</td></tr>';
  }
  $body .= '</table>';

  $body .= '<p>' . esc_html__('Ya puedes acceder a tu documentación (bonos y facturas) desde el portal:', 'casanova-portal') . '</p>';
  $body .= '<p><a href="' . $url . '" style="display:inline-block;padding:10px 14px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">' . esc_html__('Acceder al portal', 'casanova-portal') . '</a></p>';

  $html = casanova_mail_wrap_html(__('Pago completado', 'casanova-portal'), $body);

  return ['subject' => $subject, 'html' => $html];
}