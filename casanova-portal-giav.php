<?php
/**
 * Plugin Name: New Casanova Portal - GIAV
 * Description: Área privada Casanova Golf conectada a GIAV por SOAP (Cliente, Expedientes, Reservas).
 * Version: 0.28.0
 * Author: Casanova Golf
 * Text Domain: casanova-portal
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('CASANOVA_GIAV_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CASANOVA_GIAV_PLUGIN_URL', plugin_dir_url(__FILE__));

// JS i18n strings (WPML)
require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-i18n.php';

if (!function_exists('casanova_portal_clear_rest_output')) {
  function casanova_portal_clear_rest_output(): void {
    while (ob_get_level()) {
      ob_end_clean();
    }
  }
}

if (!function_exists('casanova_portal_get_preferred_lang_url')) {
  /**
   * Returns the current portal page URL in the user's preferred WPML language (if any).
   * Used so the SPA can hard-redirect once, keeping the language choice persistent.
   */
  function casanova_portal_get_preferred_lang_url(): string {
    if (!is_user_logged_in()) return '';
    $lang = (string) get_user_meta(get_current_user_id(), 'casanova_portal_lang', true);
    $lang = strtolower(trim($lang));
    if ($lang === '') return '';

    // Only meaningful if WPML is active.
    if (!has_filter('wpml_permalink') && !defined('ICL_SITEPRESS_VERSION')) return '';

    $post_id = (int) get_queried_object_id();
    if ($post_id <= 0) return '';

    $url = get_permalink($post_id);
    if (!$url) return '';

    $translated = apply_filters('wpml_permalink', $url, $lang);
    return is_string($translated) ? $translated : '';
  }
}


add_action('plugins_loaded', function () {
  load_plugin_textdomain('casanova-portal', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_action('wp_enqueue_scripts', function () {
  if (is_admin()) return;

  $file = CASANOVA_GIAV_PLUGIN_PATH . 'assets/portal.css';
  $ver  = file_exists($file) ? (string) filemtime($file) : '0';

  wp_enqueue_style(
    'casanova-portal-giav',
    CASANOVA_GIAV_PLUGIN_URL . 'assets/portal.css',
    [],
    $ver
  );

  // Custom CSS del portal (FUERA del plugin, para que sobreviva a actualizaciones)
  // Ruta esperada: wp-content/uploads/casanova-portal/portal-custom.css
  $u = wp_upload_dir();
  $custom_path = trailingslashit($u['basedir']) . 'casanova-portal/portal-custom.css';
  $custom_url  = trailingslashit($u['baseurl']) . 'casanova-portal/portal-custom.css';
  if (!empty($u['basedir']) && file_exists($custom_path)) {
    $cver = (string) filemtime($custom_path);
    wp_enqueue_style('casanova-portal-giav-custom', $custom_url, ['casanova-portal-giav'], $cver);
  }

  // JS: drawer (mobile) + loading overlay
  $js = CASANOVA_GIAV_PLUGIN_PATH . 'assets/portal.js';
  $js_ver = file_exists($js) ? (string) filemtime($js) : '0';
  wp_enqueue_script(
    'casanova-portal-giav',
    CASANOVA_GIAV_PLUGIN_URL . 'assets/portal.js',
    [],
    $js_ver,
    true
  );

  // i18n strings for JS
  wp_localize_script('casanova-portal-giav', 'casanovaPortalI18n', [
    'saving' => __('Guardando…', 'casanova-portal'),
  ]);

  // ==========================
  // React App (opcional)
  // ==========================
  // Solo encolamos la SPA si la página contiene el shortcode [casanova_portal_app]
  // y existe el build en assets/portal-app.js. Si no existe, usamos fallback (portal-react.js)
  // para evitar pantallas en blanco.
  $should_load_app = false;
  if (is_singular()) {
    $post = get_post();
    if ($post && isset($post->post_content) && has_shortcode($post->post_content, 'casanova_portal_app')) {
      $should_load_app = true;
    }
  }

  if ($should_load_app) {
    $app_js  = CASANOVA_GIAV_PLUGIN_PATH . 'react-app-template/dist/portal-app.js';
    $app_js_fallback  = CASANOVA_GIAV_PLUGIN_PATH . 'assets/portal-app.js';
    $app_css = CASANOVA_GIAV_PLUGIN_PATH . 'react-app-template/dist/portal-app.css';
    $app_css_fallback = CASANOVA_GIAV_PLUGIN_PATH . 'assets/portal-app.css';

    $use_build = file_exists($app_js);
    $handle    = 'casanova-portal-app';

    if ($use_build) {
      $ver = (string) filemtime($app_js);
      wp_enqueue_script($handle, CASANOVA_GIAV_PLUGIN_URL . 'react-app-template/dist/portal-app.js', [], $ver, true);
      if (file_exists($app_css)) {
        wp_enqueue_style($handle, CASANOVA_GIAV_PLUGIN_URL . 'react-app-template/dist/portal-app.css', [], (string) filemtime($app_css));
      }
    } else {
      $fallback = CASANOVA_GIAV_PLUGIN_PATH . 'assets/portal-react.js';
      if (file_exists($fallback)) {
        wp_enqueue_script('wp-element');
        $ver = (string) filemtime($fallback);
        wp_enqueue_script($handle, CASANOVA_GIAV_PLUGIN_URL . 'assets/portal-react.js', ['wp-element'], $ver, true);

        $fallback_css = CASANOVA_GIAV_PLUGIN_PATH . 'assets/portal-react.css';
        if (file_exists($fallback_css)) {
          wp_enqueue_style($handle, CASANOVA_GIAV_PLUGIN_URL . 'assets/portal-react.css', [], (string) filemtime($fallback_css));
        }
      }
    }

    // Datos necesarios para consumir la REST API autenticada desde JS/React
    wp_localize_script($handle, 'CasanovaPortal', [
      'restUrl' => esc_url_raw(rest_url('casanova/v1')),
      'nonce'   => wp_create_nonce('wp_rest'),
      // WPML language context (optional)
      'currentLang' => (defined('ICL_LANGUAGE_CODE') ? ICL_LANGUAGE_CODE : (has_filter('wpml_current_language') ? (string) apply_filters('wpml_current_language', null) : '')),
      'preferredLang' => (is_user_logged_in() ? (string) get_user_meta(get_current_user_id(), 'casanova_portal_lang', true) : ''),
      'preferredRedirectUrl' => (function_exists('casanova_portal_get_preferred_lang_url') ? (string) casanova_portal_get_preferred_lang_url() : ''),

    ]);

    // Translatable strings for the React app (WPML reads these from PHP)
    wp_localize_script($handle, 'CASANOVA_I18N', casanova_portal_get_js_i18n());
  }



}, 20);

// 1) INCLUDES MÍNIMOS para activación (solo DB)
require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-payments-db.php';

// 2) HOOK de activación (tabla)
register_activation_hook(__FILE__, 'casanova_payments_install');

// Activación: programa de fidelización (cron)
register_activation_hook(__FILE__, function () {
  if (!wp_next_scheduled('casanova_mulligans_daily_sync')) {
    // Evitamos el "pico" típico de medianoche; WordPress terminará ejecutándolo cuando pueda.
    wp_schedule_event(time() + 120, 'daily', 'casanova_mulligans_daily_sync');
  }
});

// Desactivación: limpia cron fidelización
register_deactivation_hook(__FILE__, function () {
  $ts = wp_next_scheduled('casanova_mulligans_daily_sync');
  if ($ts) wp_unschedule_event($ts, 'casanova_mulligans_daily_sync');
});

// 3) Carga del resto del plugin (después, ya con WP listo)
add_action('plugins_loaded', function () {

  // Optimizaciones (cache/log/base URL). Cargado primero para que el resto lo use.
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-optimizations.php';

  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-core.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-client-search.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-expedientes.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-entity-stages.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-reservas.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-oficina.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-proveedores.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-pasajeros.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-billetes.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-facturas.php';

  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/helpers-http.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/helpers-giav.php';

  // API-first (services + REST) - sin impacto visual
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/dto/dashboard-dto.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/services/dashboard-service.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/api/v1/dashboard-controller.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/services/messages-service.php';
require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/services/inbox-service.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/api/v1/messages-controller.php';

  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/services/payments-service.php';

  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/dto/trip-dto.php';
  // Resolver de imágenes/permalinks (hoteles JetEngine CCT, campos CPT, etc.)
  // Permite al portal mostrar thumbnails si existe mapeo GIAV→WP.
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/services/media-resolver.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/services/trip-service.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/api/v1/trip-controller.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/api/v1/payments-controller.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/api/v1/expedientes-controller.php';
require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/api/v1/inbox-controller.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/api/v1/profile-controller.php';

  add_action('rest_api_init', ['Casanova_Dashboard_Controller', 'register_routes']);
  add_action('rest_api_init', ['Casanova_Messages_Controller', 'register_routes']);
  add_action('rest_api_init', ['Casanova_Trip_Controller', 'register_routes']);
  add_action('rest_api_init', ['Casanova_Expedientes_Controller', 'register_routes']);
  add_action('rest_api_init', ['Casanova_Inbox_Controller', 'register_routes']);
  add_action('rest_api_init', ['Casanova_Payments_Controller', 'register_routes']);
  add_action('rest_api_init', ['Casanova_Profile_Controller', 'register_routes']);
  add_action('rest_api_init', ['Casanova_Profile_Controller', 'register_routes']);

  // React container shortcode
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-react-app.php';

  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-format.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-dashboard.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-cards.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-messages.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-bonos.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-router.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-voucher.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-itinerary.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-actions.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/giav-reservas-map.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-profile.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-loyalty.php';
  
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-payments-db.php'; 
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-payments-intents.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-payments-redsys.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-mail.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-mail-templates.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-mail-events.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-payments-settings.php';

    require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-payments-actions.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-payments-tpv.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-payments-cron.php';
  require_once CASANOVA_GIAV_PLUGIN_PATH . 'includes/portal-calendar.php';
  require_once __DIR__ . '/includes/portal-access-gate.php';

});
// Toast notice (post-redirect feedback)
add_action('wp_footer', function () {
  if (is_admin()) return;

  $notice = isset($_GET['casanova_notice']) ? sanitize_key($_GET['casanova_notice']) : '';
  if (!$notice) return;

  $map = [
    'address_saved' => 'Dirección actualizada.',
    'address_error' => 'No se pudo actualizar la dirección.',
  ];
  if (!isset($map[$notice])) return;

  $msg = esc_html($map[$notice]);
  ?>
  <div class="casanova-toast" role="status" aria-live="polite"><?php echo $msg; ?></div>
  <script>
  (function(){
    const toast = document.querySelector('.casanova-toast');
    if (!toast) return;
    requestAnimationFrame(() => toast.classList.add('is-visible'));
    setTimeout(() => toast.classList.remove('is-visible'), 3500);

    // limpia el parámetro para que no reaparezca al refrescar
    try {
      const url = new URL(window.location.href);
      url.searchParams.delete('casanova_notice');
      window.history.replaceState({}, document.title, url.toString());
    } catch (e) {}
  })();
  </script>
  <?php
}, 50);
