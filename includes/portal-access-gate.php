<?php
add_action('template_redirect', function () {

  $area_usuario_slug   = 'area-usuario';
  $portal_cliente_slug = 'portal-app'; // o portal-app si es el slug real

  // --- 1. Usuario NO logueado intentando entrar al portal ---
  if (!is_user_logged_in() && is_page($portal_cliente_slug)) {

    // Redirige al login y vuelve luego al portal
    wp_safe_redirect(
      wp_login_url(site_url('/' . $portal_cliente_slug . '/'))
    );
    exit;
  }

  // A partir de aquí, solo usuarios logueados
  if (!is_user_logged_in()) {
    return;
  }

  $user_id   = get_current_user_id();
  $idcliente = get_user_meta($user_id, 'casanova_idcliente', true);
  $is_linked = !empty($idcliente);

  // --- 2. Usuario vinculado entrando al onboarding ---
  if ($is_linked && is_page($area_usuario_slug)) {
    wp_safe_redirect(site_url('/' . $portal_cliente_slug . '/'));
    exit;
  }

  // --- 3. Usuario NO vinculado entrando al portal ---
  if (!$is_linked && is_page($portal_cliente_slug)) {
    wp_safe_redirect(site_url('/' . $area_usuario_slug . '/'));
    exit;
  }

});
