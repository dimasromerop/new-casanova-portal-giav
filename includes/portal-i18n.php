<?php
/**
 * JS i18n dictionary.
 *
 * WPML does not translate bundled JS automatically. We expose a translated
 * dictionary via wp_localize_script so React can use window.CASANOVA_I18N.
 */

if (!defined('ABSPATH')) exit;

function casanova_portal_get_js_i18n(): array {
  return [
    // Generic
    'close' => __('Cerrar', 'casanova-portal'),
    'account_label' => __('Tu cuenta', 'casanova-portal'),

    // User menu
    'menu_profile' => __('Mi perfil', 'casanova-portal'),
    'menu_security' => __('Seguridad', 'casanova-portal'),
    'menu_logout' => __('Cerrar sesión', 'casanova-portal'),

    // Language
    'portal_language' => __('Idioma del portal', 'casanova-portal'),
    'language_updated' => __('Idioma actualizado.', 'casanova-portal'),
    'language_update_failed' => __('No se pudo actualizar el idioma.', 'casanova-portal'),

    // Nav / titles
    'nav_dashboard' => __('Dashboard', 'casanova-portal'),
    'nav_trips' => __('Viajes', 'casanova-portal'),
    'nav_trip_detail' => __('Detalle del viaje', 'casanova-portal'),
    'nav_messages' => __('Mensajes', 'casanova-portal'),
    'nav_mulligans' => __('Mulligans', 'casanova-portal'),
    'nav_portal' => __('Portal', 'casanova-portal'),
    'mock_mode' => __('Modo prueba', 'casanova-portal'),

    // Microcopy
    'view_details' => __('Ver detalles', 'casanova-portal'),

    // Payments banner
    'payment_registered_title' => __('Pago registrado', 'casanova-portal'),
  ];
}
