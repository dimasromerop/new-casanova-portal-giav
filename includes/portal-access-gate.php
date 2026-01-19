<?php

add_action('template_redirect', function () {

  if (!is_user_logged_in()) {
    return;
  }

  $user_id    = get_current_user_id();
  $idcliente  = get_user_meta($user_id, 'casanova_idcliente', true);

  $is_linked = !empty($idcliente);

  // Ajusta los slugs si son distintos
  $area_usuario_slug   = 'area-usuario';
  $portal_cliente_slug = 'portal-app';

  // Si está vinculado y entra en Área Usuario → redirige al portal
  if ($is_linked && is_page($area_usuario_slug)) {
    wp_safe_redirect(site_url('/' . $portal_cliente_slug . '/'));
    exit;
  }

  // Si NO está vinculado y entra en el Portal → redirige al onboarding
  if (!$is_linked && is_page($portal_cliente_slug)) {
    wp_safe_redirect(site_url('/' . $area_usuario_slug . '/'));
    exit;
  }

});
