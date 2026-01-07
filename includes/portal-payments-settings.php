<?php
if (!defined('ABSPATH')) exit;

/**
 * Ajustes del plugin (Pagos + Portal).
 *
 * - Pagos: % depósito, mínimo, overrides.
 * - Portal: templates legacy por vista (compatibilidad) + menú dinámico (recomendado).
 */

function casanova_payments_get_deposit_percent(int $idExpediente = 0): float {
  // Override por expediente (si existe)
  $overrides_raw = get_option('casanova_deposit_overrides', '');
  if ($idExpediente > 0 && is_string($overrides_raw) && $overrides_raw !== '') {
    $lines = preg_split('/\r\n|\r|\n/', $overrides_raw);
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || strpos($line, '=') === false) continue;
      [$k,$v] = array_map('trim', explode('=', $line, 2));
      if ((int)$k === $idExpediente) {
        $p = (float) str_replace(',', '.', $v);
        if ($p > 0 && $p <= 100) return $p;
      }
    }
  }

  $p = (float) get_option('casanova_deposit_percent', 10);
  if ($p <= 0) $p = 10;
  if ($p > 100) $p = 100;
  return $p;
}

function casanova_payments_get_deposit_min_amount(): float {
  $m = (float) get_option('casanova_deposit_min_amount', 50);
  if ($m < 0) $m = 0;
  return $m;
}

add_action('admin_menu', function () {
  add_options_page(
    'Casanova Portal',
    'Casanova Portal',
    'manage_options',
    'casanova-payments',
    'casanova_payments_render_settings_page'
  );
});

add_action('admin_init', function () {
  // --- Pagos
  register_setting('casanova_payments', 'casanova_deposit_percent', [
    'type' => 'number',
    'sanitize_callback' => function($v){
      $v = (float) str_replace(',', '.', (string)$v);
      if ($v <= 0) $v = 10;
      if ($v > 100) $v = 100;
      return $v;
    },
    'default' => 10,
  ]);

  register_setting('casanova_payments', 'casanova_deposit_min_amount', [
    'type' => 'number',
    'sanitize_callback' => function($v){
      $v = (float) str_replace(',', '.', (string)$v);
      if ($v < 0) $v = 0;
      return $v;
    },
    'default' => 50,
  ]);

  register_setting('casanova_payments', 'casanova_deposit_overrides', [
    'type' => 'string',
    'sanitize_callback' => function($v){
      $v = trim((string)$v);
      if (strlen($v) > 20000) $v = substr($v, 0, 20000);
      return $v;
    },
    'default' => '',
  ]);

  // --- Portal (legacy templates por vista)
  register_setting('casanova_portal', 'casanova_portal_tpl_dashboard', ['type' => 'integer', 'sanitize_callback' => 'absint']);
  register_setting('casanova_portal', 'casanova_portal_tpl_expedientes', ['type' => 'integer', 'sanitize_callback' => 'absint']);
  register_setting('casanova_portal', 'casanova_portal_tpl_mulligans', ['type' => 'integer', 'sanitize_callback' => 'absint']);
  register_setting('casanova_portal', 'casanova_portal_tpl_perfil', ['type' => 'integer', 'sanitize_callback' => 'absint']);

  // --- Menú dinámico (recomendado)
  register_setting('casanova_portal_menu', 'casanova_portal_menu_items', [
    'type' => 'array',
    'sanitize_callback' => 'casanova_portal_sanitize_menu_items',
    'default' => [],
  ]);
});

function casanova_portal_sanitize_menu_items($value): array {
  if (!is_array($value)) return [];

  $out = [];
  foreach ($value as $row) {
    if (!is_array($row)) continue;

    $key = isset($row['key']) ? sanitize_key((string)$row['key']) : '';
    $label = isset($row['label']) ? sanitize_text_field((string)$row['label']) : '';
    $icon = isset($row['icon']) ? sanitize_key((string)$row['icon']) : 'dot';
    $template_id = isset($row['template_id']) ? absint($row['template_id']) : 0;
    $order = isset($row['order']) ? (int)$row['order'] : 100;
    $enabled = isset($row['enabled']) ? (int)!!$row['enabled'] : 0;

    // preserve[]
    $preserve = [];
    if (isset($row['preserve']) && is_array($row['preserve'])) {
      foreach ($row['preserve'] as $qv) {
        $qv = sanitize_key((string)$qv);
        if ($qv) $preserve[] = $qv;
      }
    }

    // Row vacía: se ignora
    if (!$key && !$label) continue;

    if (!$key) continue; // clave obligatoria
    if (!$label) $label = $key;

    $out[] = [
      'key' => $key,
      'label' => $label,
      'icon' => $icon ?: 'dot',
      'template_id' => $template_id,
      'order' => $order,
      'enabled' => $enabled ? 1 : 0,
      'preserve' => array_values(array_unique($preserve)),
    ];
  }

  // Orden estable
  usort($out, function($a, $b){
    return ((int)($a['order'] ?? 100)) <=> ((int)($b['order'] ?? 100));
  });

  // Evita keys duplicadas: última gana
  $by = [];
  foreach ($out as $row) {
    $by[$row['key']] = $row;
  }
  return array_values($by);
}

function casanova_portal_get_bricks_templates(): array {
  $templates = [];
  $posts = get_posts([
    'post_type'      => 'bricks_template',
    'posts_per_page' => 300,
    'post_status'    => ['publish','draft','private'],
    'orderby'        => 'title',
    'order'          => 'ASC',
  ]);
  foreach ($posts as $pp) {
    $templates[(int)$pp->ID] = $pp->post_title ? $pp->post_title : ('Template #' . (int)$pp->ID);
  }
  return $templates;
}

function casanova_payments_render_settings_page(): void {
  if (!current_user_can('manage_options')) return;

  $tab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'payments';
  if (!in_array($tab, ['payments','portal','menu','help'], true)) $tab = 'payments';

  echo '<div class="wrap">';
  echo '<h1>Casanova Portal</h1>';

  $base = admin_url('options-general.php?page=casanova-payments');
  $t_pay = add_query_arg(['tab' => 'payments'], $base);
  $t_por = add_query_arg(['tab' => 'portal'], $base);
  $t_men = add_query_arg(['tab' => 'menu'], $base);
  $t_hlp = add_query_arg(['tab' => 'help'], $base);

  echo '<nav class="nav-tab-wrapper" aria-label="Secciones">';
  echo '<a href="' . esc_url($t_pay) . '" class="nav-tab ' . ($tab==='payments'?'nav-tab-active':'') . '">Pagos</a>';
  echo '<a href="' . esc_url($t_por) . '" class="nav-tab ' . ($tab==='portal'?'nav-tab-active':'') . '">Portal</a>';
  echo '<a href="' . esc_url($t_men) . '" class="nav-tab ' . ($tab==='menu'?'nav-tab-active':'') . '">Menú</a>';
  echo '<a href="' . esc_url($t_hlp) . '" class="nav-tab ' . ($tab==='help'?'nav-tab-active':'') . '">Ayuda</a>';
  echo '</nav>';

  if ($tab === 'payments') {
    $p  = get_option('casanova_deposit_percent', 10);
    $m  = get_option('casanova_deposit_min_amount', 50);
    $ov = get_option('casanova_deposit_overrides', '');
    if (!is_string($ov)) $ov = '';

    echo '<form method="post" action="options.php">';
    settings_fields('casanova_payments');

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="casanova_deposit_percent">Depósito (%)</label></th>';
    echo '<td><input name="casanova_deposit_percent" id="casanova_deposit_percent" type="number" step="0.01" min="0" max="100" value="' . esc_attr($p) . '" /> <p class="description">Por defecto 10%.</p></td></tr>';

    echo '<tr><th scope="row"><label for="casanova_deposit_min_amount">Depósito mínimo (€)</label></th>';
    echo '<td><input name="casanova_deposit_min_amount" id="casanova_deposit_min_amount" type="number" step="0.01" min="0" value="' . esc_attr($m) . '" /> <p class="description">Por defecto 50€.</p></td></tr>';

    echo '<tr><th scope="row"><label for="casanova_deposit_overrides">Overrides por expediente</label></th>';
    echo '<td><textarea name="casanova_deposit_overrides" id="casanova_deposit_overrides" rows="8" cols="60" class="large-text code">' . esc_textarea($ov) . '</textarea>';
    echo '<p class="description">Opcional. Una línea por expediente: <code>2553848=15</code> (porcentaje).</p></td></tr>';

    echo '</table>';
    submit_button();
    echo '</form>';

  } elseif ($tab === 'portal') {
    // Legacy templates por vista (compatibilidad)
    $tpl_dashboard   = (int) get_option('casanova_portal_tpl_dashboard', 0);
    $tpl_expedientes = (int) get_option('casanova_portal_tpl_expedientes', 0);
    $tpl_mulligans   = (int) get_option('casanova_portal_tpl_mulligans', 0);
    $tpl_perfil      = (int) get_option('casanova_portal_tpl_perfil', 0);

    $templates = casanova_portal_get_bricks_templates();

    $render_select = function(string $name, int $current) use ($templates) {
      echo '<select name="' . esc_attr($name) . '" class="regular-text">';
      echo '<option value="0">— No asignado —</option>';
      foreach ($templates as $id => $title) {
        $sel = selected($current, $id, false);
        echo '<option value="' . (int)$id . '" ' . $sel . '>' . esc_html($title) . ' (#' . (int)$id . ')</option>';
      }
      echo '</select>';
    };

    echo '<p class="description">Compatibilidad: asignación directa de templates por vista. Si usas el Menú dinámico, lo normal es asignar templates ahí y dejar esto en blanco.</p>';

    echo '<form method="post" action="options.php">';
    settings_fields('casanova_portal');

    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row">Dashboard</th><td>';
    $render_select('casanova_portal_tpl_dashboard', $tpl_dashboard);
    echo '<p class="description">Vista: <code>?view=dashboard</code></p></td></tr>';

    echo '<tr><th scope="row">Expedientes</th><td>';
    $render_select('casanova_portal_tpl_expedientes', $tpl_expedientes);
    echo '<p class="description">Vista: <code>?view=expedientes</code></p></td></tr>';

    echo '<tr><th scope="row">Mulligans</th><td>';
    $render_select('casanova_portal_tpl_mulligans', $tpl_mulligans);
    echo '<p class="description">Vista: <code>?view=mulligans</code></p></td></tr>';

    echo '<tr><th scope="row">Perfil</th><td>';
    $render_select('casanova_portal_tpl_perfil', $tpl_perfil);
    echo '<p class="description">Vista: <code>?view=perfil</code></p></td></tr>';

    echo '</table>';
    submit_button('Guardar');
    echo '</form>';

  } elseif ($tab === 'menu') {
    // Menú dinámico
    $templates = casanova_portal_get_bricks_templates();
    $items = get_option('casanova_portal_menu_items', []);
    if (!is_array($items)) $items = [];

    // Si no hay items, pre-rellena con los defaults (mismo orden que el router)
    if (empty($items)) {
      $items = [
        ['key'=>'dashboard','label'=>'Principal','icon'=>'home','template_id'=>(int)get_option('casanova_portal_tpl_dashboard',0),'order'=>10,'enabled'=>1,'preserve'=>[]],
        ['key'=>'expedientes','label'=>'Reservas','icon'=>'briefcase','template_id'=>(int)get_option('casanova_portal_tpl_expedientes',0),'order'=>20,'enabled'=>1,'preserve'=>['expediente']],
        ['key'=>'mulligans','label'=>'Mulligans','icon'=>'flag','template_id'=>(int)get_option('casanova_portal_tpl_mulligans',0),'order'=>30,'enabled'=>1,'preserve'=>[]],
        ['key'=>'mensajes','label'=>'Mensajes','icon'=>'message','template_id'=>(int)get_option('casanova_portal_tpl_mensajes',0),'order'=>35,'enabled'=>1,'preserve'=>[]],
        ['key'=>'perfil','label'=>'Mis datos','icon'=>'user','template_id'=>(int)get_option('casanova_portal_tpl_perfil',0),'order'=>40,'enabled'=>1,'preserve'=>[]],
      ];
    }

    $icon_choices = [
      'home' => 'Home',
      'briefcase' => 'Maletín',
      'flag' => 'Bandera',
      'user' => 'Usuario',
      'message' => 'Mensajes',
      'receipt' => 'Factura',
      'ticket' => 'Bono/Voucher',
      'help' => 'Soporte',
      'dot' => 'Punto',
    ];

    echo '<p class="description">Define los elementos del menú lateral. Cada elemento es una sección con su <code>?view=</code> y un template de Bricks asociado.</p>';

    echo '<form method="post" action="options.php">';
    settings_fields('casanova_portal_menu');

    echo '<style>
      .casanova-menu-table th, .casanova-menu-table td { vertical-align: top; }
      .casanova-menu-table input[type=text] { width: 100%; }
      .casanova-menu-table .small { width: 90px; }
      .casanova-menu-actions { margin-top: 10px; display:flex; gap:10px; align-items:center; }
      .casanova-menu-hint { color:#666; }
    </style>';

    echo '<table class="widefat striped casanova-menu-table" id="casanova-menu-table">';
    echo '<thead><tr>';
    echo '<th style="width:110px">Clave (view)</th>';
    echo '<th style="width:180px">Etiqueta</th>';
    echo '<th style="width:140px">Icono</th>';
    echo '<th>Template Bricks</th>';
    echo '<th style="width:90px">Orden</th>';
    echo '<th style="width:140px">Preservar</th>';
    echo '<th style="width:90px">Activo</th>';
    echo '<th style="width:70px"></th>';
    echo '</tr></thead><tbody>';

    $row_idx = 0;
    foreach ($items as $it) {
      if (!is_array($it)) continue;
      $key = sanitize_key((string)($it['key'] ?? ''));
      $label = sanitize_text_field((string)($it['label'] ?? ''));
      $icon = sanitize_key((string)($it['icon'] ?? 'dot'));
      $template_id = absint($it['template_id'] ?? 0);
      $order = (int)($it['order'] ?? 100);
      $enabled = !empty($it['enabled']) ? 1 : 0;
      $preserve = $it['preserve'] ?? [];
      if (!is_array($preserve)) $preserve = [];

      echo '<tr>';
      echo '<td><input type="text" name="casanova_portal_menu_items['.$row_idx.'][key]" value="'.esc_attr($key).'" placeholder="dashboard" /></td>';
      echo '<td><input type="text" name="casanova_portal_menu_items['.$row_idx.'][label]" value="'.esc_attr($label).'" placeholder="Principal" /></td>';

      echo '<td><select name="casanova_portal_menu_items['.$row_idx.'][icon]">';
      foreach ($icon_choices as $k => $lbl) {
        echo '<option value="'.esc_attr($k).'" '.selected($icon, $k, false).'>'.esc_html($lbl).'</option>';
      }
      echo '</select></td>';

      echo '<td><select name="casanova_portal_menu_items['.$row_idx.'][template_id]" class="regular-text">';
      echo '<option value="0">— (fallback del plugin) —</option>';
      foreach ($templates as $tid => $title) {
        echo '<option value="'.(int)$tid.'" '.selected($template_id, (int)$tid, false).'>'.esc_html($title).' (#'.(int)$tid.')</option>';
      }
      echo '</select></td>';

      echo '<td><input class="small" type="number" name="casanova_portal_menu_items['.$row_idx.'][order]" value="'.esc_attr($order).'" /></td>';

      // preserve checkboxes
      $preserve_options = ['expediente' => 'expediente'];
      echo '<td>';
      foreach ($preserve_options as $pv => $pl) {
        $checked = in_array($pv, $preserve, true) ? 'checked' : '';
        echo '<label style="display:block"><input type="checkbox" name="casanova_portal_menu_items['.$row_idx.'][preserve][]" value="'.esc_attr($pv).'" '.$checked.' /> '.esc_html($pl).'</label>';
      }
      echo '</td>';

      echo '<td style="text-align:center"><input type="checkbox" name="casanova_portal_menu_items['.$row_idx.'][enabled]" value="1" '.checked($enabled, 1, false).' /></td>';
      echo '<td><button type="button" class="button link-delete casanova-row-del">Quitar</button></td>';
      echo '</tr>';

      $row_idx++;
    }

    
    
    echo '</tbody></table>';

    echo '<div class="casanova-menu-actions">';
    echo '<button type="button" class="button" id="casanova-menu-add">Añadir item</button>';
    echo '<span class="casanova-menu-hint">Tip: Clave = lo que irá en <code>?view=</code>. Ej: <code>facturas</code>, <code>vouchers</code>, <code>soporte</code>.</span>';
    echo '</div>';

    // Template de fila para JS
    echo '<template id="casanova-menu-row-template">';
    echo '<tr>';
    echo '<td><input type="text" name="__NAME__[key]" value="" placeholder="facturas" /></td>';
    echo '<td><input type="text" name="__NAME__[label]" value="" placeholder="Facturas" /></td>';
    echo '<td><select name="__NAME__[icon]">';
    foreach ($icon_choices as $k => $lbl) {
      echo '<option value="'.esc_attr($k).'">'.esc_html($lbl).'</option>';
    }
    echo '</select></td>';

    echo '<td><select name="__NAME__[template_id]" class="regular-text">';
    echo '<option value="0">— (fallback del plugin) —</option>';
    foreach ($templates as $tid => $title) {
      echo '<option value="'.(int)$tid.'">'.esc_html($title).' (#'.(int)$tid.')</option>';
    }
    echo '</select></td>';

    echo '<td><input class="small" type="number" name="__NAME__[order]" value="100" /></td>';
    echo '<td><label style="display:block"><input type="checkbox" name="__NAME__[preserve][]" value="expediente" /> expediente</label></td>';
    echo '<td style="text-align:center"><input type="checkbox" name="__NAME__[enabled]" value="1" checked /></td>';
    echo '<td><button type="button" class="button link-delete casanova-row-del">Quitar</button></td>';
    echo '</tr>';
    echo '</template>';

    echo '<script>
      (function(){
        const table = document.getElementById("casanova-menu-table");
        const addBtn = document.getElementById("casanova-menu-add");
        const tpl = document.getElementById("casanova-menu-row-template");
        if (!table || !addBtn || !tpl) return;

        function nextIndex(){
          const rows = table.querySelectorAll("tbody tr");
          return rows.length;
        }

        function wireDelete(btn){
          btn.addEventListener("click", function(){
            const tr = btn.closest("tr");
            if (tr) tr.remove();
          });
        }

        table.querySelectorAll(".casanova-row-del").forEach(wireDelete);

        addBtn.addEventListener("click", function(){
          const idx = nextIndex();
          const html = tpl.innerHTML.replaceAll("__NAME__", `casanova_portal_menu_items[${idx}]`);
          const tbody = table.querySelector("tbody");
          const tmp = document.createElement("tbody");
          tmp.innerHTML = html;
          const tr = tmp.querySelector("tr");
          if (tr) {
            tbody.appendChild(tr);
            const del = tr.querySelector(".casanova-row-del");
            if (del) wireDelete(del);
          }
        });
      })();
    </script>';

    submit_button('Guardar menú');
    echo '</form>';

  } elseif ($tab === 'help') {
    echo '<h2>Ayuda rápida</h2>';
    echo '<p class="description">Mini-documentación del portal: shortcodes disponibles, parámetros y notas de configuración. Para no depender de la memoria (mal negocio).</p>';

    echo '<h3>Shortcodes principales</h3>';
    echo '<table class="widefat striped" style="max-width:1100px">';
    echo '<thead><tr><th style="width:240px">Shortcode</th><th>Qué muestra</th><th style="width:420px">Opciones / Ejemplos</th></tr></thead><tbody>';

    echo '<tr><td><code>[casanova_portal]</code></td><td>Layout base del portal (menú + contenido según <code>?view=</code>).</td><td>Se usa en la página principal del portal. Ej.: <code>/area-usuario/?view=dashboard</code></td></tr>';
    echo '<tr><td><code>[casanova_mulligans]</code></td><td>Card de Mulligans (saldo, tier, progreso, movimientos).</td><td>Sin parámetros relevantes. Puedes limitar movimientos con <code>[casanova_mulligans_movimientos limit="10"]</code></td></tr>';

    echo '<tr><td><code>[casanova_proximo_viaje]</code></td><td>Próximo viaje del cliente (título, fechas, countdown, totales, enlace).</td><td><code>variant="hero"</code> (dashboard), <code>variant="compact"</code>, <code>variant="default"</code> (por defecto). Ej.: <code>[casanova_proximo_viaje variant="hero"]</code></td></tr>';

    echo '<tr><td><code>[casanova_card_pagos]</code></td><td>Card de estado de pagos (total, pagado, pendiente, barra).</td><td><code>source="auto|current|next"</code> y <code>cta="both|pagar|detalle|none"</code>. Ej.: <code>[casanova_card_pagos source="auto" cta="both"]</code></td></tr>';

    echo '<tr><td><code>[casanova_card_proxima_accion]</code></td><td>Card con la siguiente acción sugerida (pago/deposito/facturas/todo ok).</td><td><code>source="auto|current|next"</code>, <code>tab_pagos="pagos"</code>, <code>tab_facturas="facturas"</code>. Ej.: <code>[casanova_card_proxima_accion]</code></td></tr>';

    echo '<tr><td><code>[casanova_expedientes]</code></td><td>Listado de expedientes del cliente.</td><td>Navega con <code>?expediente=XXXX</code>. Se integra bien en layouts 2 columnas (lista+detalle).</td></tr>';
    echo '<tr><td><code>[casanova_expediente_resumen]</code></td><td>Resumen del expediente (totales, pagado, pendiente, mulligans usados).</td><td>Normalmente dentro del detalle (tab “Resumen”).</td></tr>';
    echo '<tr><td><code>[casanova_expediente_reservas]</code></td><td>Reservas/servicios del expediente (incluye botones de pago donde aplica).</td><td>Normalmente dentro de un tab “Reservas/Servicios”.</td></tr>';

    echo '<tr><td><code>[casanova_mensajes]</code></td><td>Centro de mensajes del viaje (notas/Comentarios en GIAV). Muestra solo elementos tipo <code>Comment</code>.</td><td>Usa el expediente activo (<code>?expediente=</code>) o el próximo viaje. Ej.: <code>[casanova_mensajes]</code></td></tr>';
    echo '<tr><td><code>[casanova_card_mensajes]</code></td><td>Card resumen de mensajes (dashboard): indica si hay mensajes nuevos y a qué viaje corresponde.</td><td>Sin parámetros. Ej.: <code>[casanova_card_mensajes]</code></td></tr>';
    echo '<tr><td><code>[casanova_bonos]</code></td><td>Listado de bonos disponibles (expedientes pagados), agrupado por viaje con enlaces a HTML y PDF.</td><td><code>days="3"</code>, <code>only_recent="1"</code></td></tr>';
    
    echo '</tbody></table>';

    echo '<h3>Notas importantes</h3>';
    echo '<ul style="max-width:1100px; list-style:disc; margin-left:20px">';
    echo '<li><strong>CSS personalizado:</strong> guarda tus overrides en <code>wp-content/uploads/casanova-portal/portal-custom.css</code> (se carga después de <code>portal.css</code> y sobrevive a actualizaciones del plugin).</li>';
    echo '<li><strong>Menú:</strong> en la pestaña “Menú” cada item es un <code>?view=</code>. Si marcas “preservar <code>expediente</code>”, el portal mantiene el viaje activo al navegar (recomendado para secciones contextuales).</li>';
    echo '<li><strong>Depósito:</strong> solo se ofrece si estamos dentro de fecha límite y no se ha pagado nada anteriormente. Si no aplica, se ofrece pago pendiente normal.</li>';
    echo '</ul>';
  }

  echo '</div>';
}