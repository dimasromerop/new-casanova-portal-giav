<?php
if (!defined('ABSPATH')) exit;

/**
 * Router principal del portal (una sola página + ?view=...).
 *
 * Shortcode: [casanova_portal]
 *
 * Menú y templates configurables en Ajustes → Casanova Portal → Portal → Menú.
 */

/**
 * Devuelve los items del menú (normalizados).
 * Cada item: key, label, icon, template_id, preserve (array de query vars), order, enabled
 */

/**
 * Traduce etiquetas estándar del portal según idioma (WPML/locale).
 * Si el usuario ha personalizado la etiqueta, se respeta tal cual.
 */
function casanova_portal_i18n_label(string $key, string $label): string {
  $defaults = [
    'dashboard'   => 'Principal',
    'expedientes' => 'Reservas',
    'bonos'       => 'Bonos',
    'vouchers'    => 'Bonos',
    'mulligans'   => 'Mulligans',
    'perfil'      => 'Mis datos',
    'profile'     => 'Mis datos',
    'facturas'    => 'Facturas',
    'pagos'       => 'Pagos',
    'mensajes'   => 'Mensajes',
    'messages'   => 'Mensajes',
  ];
  if (isset($defaults[$key]) && $label === $defaults[$key]) {
    return __($defaults[$key], 'casanova-portal');
  }
  return $label;
}

function casanova_portal_menu_items(): array {
  $raw = get_option('casanova_portal_menu_items', null);

  // Defaults (compatibles con instalaciones previas)
  $defaults = [
    [
      'key' => 'dashboard',
      'label' => 'Principal',
      'icon' => 'home',
      'template_id' => (int) get_option('casanova_portal_tpl_dashboard', 0),
      'preserve' => [],
      'order' => 10,
      'enabled' => 1,
    ],
    [
      'key' => 'expedientes',
      'label' => 'Reservas',
      'icon' => 'briefcase',
      'template_id' => (int) get_option('casanova_portal_tpl_expedientes', 0),
      'preserve' => ['expediente'],
      'order' => 20,
      'enabled' => 1,
    ],
    [
      'key' => 'mulligans',
      'label' => 'Mulligans',
      'icon' => 'flag',
      'template_id' => (int) get_option('casanova_portal_tpl_mulligans', 0),
      'preserve' => [],
      'order' => 30,
      'enabled' => 1,
    ],
    [
      'key' => 'mensajes',
      'label' => 'Mensajes',
      'icon' => 'message',
      'template_id' => (int) get_option('casanova_portal_tpl_mensajes', 0),
      'preserve' => ['expediente'],
      'order' => 35,
      'enabled' => 1,
    ],
    [
      'key' => 'perfil',
      'label' => 'Mis datos',
      'icon' => 'user',
      'template_id' => (int) get_option('casanova_portal_tpl_perfil', 0),
      'preserve' => [],
      'order' => 40,
      'enabled' => 1,
    ],
  ];

  $items = [];
  if (is_array($raw) && !empty($raw)) {
    foreach ($raw as $it) {
      if (!is_array($it)) continue;
      $key = isset($it['key']) ? sanitize_key((string)$it['key']) : '';
      if (!$key) continue;

      $label = isset($it['label']) ? sanitize_text_field((string)$it['label']) : $key;
      $icon = isset($it['icon']) ? sanitize_key((string)$it['icon']) : 'dot';
      $template_id = isset($it['template_id']) ? absint($it['template_id']) : 0;

      $preserve = [];
      if (isset($it['preserve']) && is_array($it['preserve'])) {
        foreach ($it['preserve'] as $qv) {
          $qv = sanitize_key((string)$qv);
          if ($qv) $preserve[] = $qv;
        }
      }

      $order = isset($it['order']) ? (int)$it['order'] : 100;
      $enabled = isset($it['enabled']) ? (int)!!$it['enabled'] : 1;

      $items[] = [
        'key' => $key,
        'label' => $label,
        'icon' => $icon,
        'template_id' => $template_id,
        'preserve' => $preserve,
        'order' => $order,
        'enabled' => $enabled,
      ];
    }
  }

  if (empty($items)) $items = $defaults;

  // Filtra deshabilitados y ordena
  $items = array_values(array_filter($items, fn($x) => !empty($x['enabled'])));
  usort($items, fn($a,$b) => ((int)$a['order'] <=> (int)$b['order']));

  if (empty($items)) $items = [$defaults[0]];
  return $items;
}

function casanova_portal_allowed_views(): array {
  $views = [];
  foreach (casanova_portal_menu_items() as $it) {
    $views[] = (string)($it['key'] ?? '');
  }
  $views = array_values(array_filter(array_unique($views)));
  return $views;
}

function casanova_portal_default_view(): string {
  $items = casanova_portal_menu_items();
  $v = sanitize_key((string)($items[0]['key'] ?? 'dashboard'));
  return $v ?: 'dashboard';
}

function casanova_portal_get_view(): string {
  $view = isset($_GET['view']) ? sanitize_key((string)$_GET['view']) : '';
  if (!$view) $view = casanova_portal_default_view();

  // Permitir vista de prueba aunque no esté en el menú (solo admins)
  if ($view === 'giav_test_messages' && current_user_can('manage_options')) return $view;
  $allowed = casanova_portal_allowed_views();
  if (!in_array($view, $allowed, true)) $view = casanova_portal_default_view();
  return $view;
}

function casanova_portal_get_current_menu_item(string $view): array {
  foreach (casanova_portal_menu_items() as $it) {
    if (($it['key'] ?? '') === $view) return $it;
  }
  $items = casanova_portal_menu_items();
  return $items[0] ?? ['key'=>'dashboard','label'=>'Principal','icon'=>'home','template_id'=>0,'preserve'=>[],'order'=>10,'enabled'=>1];
}

/** Renderiza un template de Bricks por ID */
function casanova_portal_render_bricks_template(int $template_id): string {
  if ($template_id <= 0) return '';

  // WPML: si el template tiene traducción, usar el ID del idioma actual.
  // Importante: el menú guarda el ID (normalmente ES). WPML no lo "adivina" al renderizar.
  if (function_exists('apply_filters')) {
    $translated_id = apply_filters('wpml_object_id', $template_id, 'bricks_template', true);
    if (!empty($translated_id)) {
      $template_id = (int) $translated_id;
    }
  }

  return do_shortcode('[bricks_template id="' . (int) $template_id . '"]');



}


/** Fallbacks por si no hay template definido */
function casanova_portal_fallback_content(string $view, int $user_id): string {
  switch ($view) {
    case 'expedientes':
      $out  = do_shortcode('[casanova_expedientes]');
      $out .= do_shortcode('[casanova_expediente_header]');
      $out .= do_shortcode('[casanova_expediente_detalle]');
      return $out;

    case 'mulligans':
      return do_shortcode('[casanova_mulligans]') . do_shortcode('[casanova_mulligans_movimientos limit="10"]');

    case 'perfil':
      return do_shortcode('[casanova_mis_datos]');

    case 'mensajes':
    case 'messages':
      return do_shortcode('[casanova_mensajes]');

    case 'giav_test_messages':
      if (function_exists('casanova_portal_render_giav_test_messages')) {
        return casanova_portal_render_giav_test_messages($user_id);
      }
      return '<p>' . esc_html__('Vista de prueba no disponible.', 'casanova-portal-giav') . '</p>';

    case 'dashboard':
    default:
      return function_exists('casanova_portal_render_dashboard')
        ? casanova_portal_render_dashboard($user_id)
        : '<p>' . esc_html__('Dashboard no disponible.', 'casanova-portal') . '</p>';
  }
}

add_shortcode('casanova_portal', function($atts = []) {
  if (!is_user_logged_in()) {
    $url = wp_login_url( esc_url_raw( $_SERVER['REQUEST_URI'] ?? home_url('/') ) );
    return '<p>' . sprintf(
      /* translators: %s = login URL */
      wp_kses_post(__('Necesitas iniciar sesión para acceder al portal. <a href="%s">Iniciar sesión</a>', 'casanova-portal')),
      esc_url($url)
    ) . '</p>';
  }

  $user_id = get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  $view = casanova_portal_get_view();
  $items = casanova_portal_menu_items();

  $menu = '';
  foreach ($items as $it) {
    $k = (string)($it['key'] ?? '');
    if (!$k) continue;

    $url = casanova_portal_base_url();
    $url = add_query_arg(['view' => $k], $url);

    // Preserva query vars configuradas
    $preserve = $it['preserve'] ?? [];
    if (is_array($preserve) && !empty($preserve)) {
      foreach ($preserve as $qv) {
        if (isset($_GET[$qv]) && $_GET[$qv] !== '') {
          $url = add_query_arg([$qv => (string)$_GET[$qv]], $url);
        }
      }
    }

    $active = ($k === $view) ? ' is-active' : '';
    $menu .= '<a class="casanova-nav-item' . $active . '" href="' . esc_url($url) . '" data-casanova-nav-link="1">';
    $menu .= '  <span class="casanova-nav-icon" aria-hidden="true">' . casanova_portal_icon_svg((string)($it['icon'] ?? 'dot')) . '</span>';
    $menu .= '  <span class="casanova-nav-label">' . esc_html(casanova_portal_i18n_label($k, (string)($it['label'] ?? $k))) . '</span>';
    $menu .= '  <span class="casanova-nav-spinner" aria-hidden="true"><span class="spinner"></span></span>';
    // Badge de "Nuevos" para Bonos/Vouchers (ventana 3 días por defecto).
    $badge = '';
    if ($idCliente && in_array($k, ['bonos','vouchers','bono','voucher'], true) && function_exists('casanova_bonos_recent_count')) {
      $n_new = (int) casanova_bonos_recent_count((int)$user_id, (int)$idCliente, 3);
      if ($n_new > 0) {
        $badge = '<span class="casanova-nav-badge" aria-label="' . esc_attr__('Bonos nuevos', 'casanova-portal') . '">' . (int)$n_new . '</span>';
      }
    }
    // Badge de "Nuevos" para Mensajes (comentarios) por expediente.
    if ($idCliente && in_array($k, ['mensajes','messages'], true) && function_exists('casanova_messages_new_count_user')) {
      $n_new = (int) casanova_messages_new_count_user((int)$user_id, (int)$idCliente, 300);
      if ($n_new > 0) {
        $badge = '<span class="casanova-nav-badge" aria-label="' . esc_attr__('Mensajes nuevos', 'casanova-portal') . '">' . (int)$n_new . '</span>';
      }
    }

    if ($badge) $menu .= '  ' . $badge;
    $menu .= '</a>';
  }

  // Contenido según item actual
  $cur = casanova_portal_get_current_menu_item($view);
  $tpl_id = (int)($cur['template_id'] ?? 0);

  $content = '';
  if ($tpl_id > 0) $content = casanova_portal_render_bricks_template($tpl_id);
  if (!$content) $content = casanova_portal_fallback_content($view, $user_id);

  $html  = '<div class="casanova-app">';
  $html .= '  <aside class="casanova-sidebar" aria-label="' . esc_attr__('Navegación del portal', 'casanova-portal') . '">';
  $html .= '    <div class="casanova-sidebar-inner">';
  $html .= '      <div class="casanova-nav">';
  $html .= '        <div class="casanova-nav-head">';
  $html .= '          <div class="casanova-nav-brand">' . esc_html__('Gestión de Reservas', 'casanova-portal') . '</div>';
  $html .= '        </div>';
  $html .=          $menu;
  $html .= '      </div>';
  $html .= '    </div>';
  $html .= '  </aside>';
  $html .= '  <main class="casanova-main" aria-label="' . esc_attr__('Contenido del portal', 'casanova-portal') . '">';
  $html .= '    <div class="casanova-main-inner">' . $content . '</div>';
  $html .= '  </main>';
  $html .= '</div>';

  return $html;
});

/**
 * Iconos SVG inline minimalistas (tamaño controlado por CSS).
 */
function casanova_portal_icon_svg(string $name): string {
  $icons = [
    'home' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5l9-7 9 7"/><path d="M9 22V12h6v10"/></svg>',
    'briefcase' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 6V5a2 2 0 0 1 2-2h0a2 2 0 0 1 2 2v1"/><rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 12h18"/></svg>',
    'flag' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22V4"/><path d="M4 4h11l-1 4 4 2-2 3 2 3-4 2 1 4H4"/></svg>',
    'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="8" r="4"/></svg>',
    'receipt' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12v20l-2-1-2 1-2-1-2 1-2-1-2 1V2z"/><path d="M9 7h6"/><path d="M9 11h6"/><path d="M9 15h5"/></svg>',
    'ticket' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M12 6v12"/></svg>',
    'mail' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="M4 6l8 6 8-6"/></svg>',
    'message' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 8h8"/><path d="M8 12h6"/></svg>',
    'help' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.5 9a2.5 2.5 0 1 1 4.2 1.8c-.8.7-1.2 1.1-1.2 2.2"/><path d="M12 17h.01"/></svg>',
    'dot' => '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="3"/></svg>',
  ];
  return $icons[$name] ?? $icons['dot'];
}