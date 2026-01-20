<?php
if (!defined('ABSPATH')) exit;

/**
 * REST: Perfil de usuario (portal cliente)
 *
 * Principio: React solo consume REST. Toda la lógica (GIAV + WP) vive aquí.
 */
class Casanova_Profile_Controller {

  /**
   * Devuelve una URL del portal en el idioma WPML indicado.
   * - Si WPML no está activo, devuelve la base URL tal cual.
   * - Si WPML está activo, usa su filtro oficial para resolver el permalink.
   */
  private static function wpml_portal_url_for_lang(string $lang): string {
    $base = function_exists('casanova_portal_base_url') ? (string) casanova_portal_base_url() : home_url('/portal-app/');
    $lang = strtolower(trim($lang));
    if ($lang === '') return $base;

    // WPML: usar el filtro oficial si está disponible.
    if (has_filter('wpml_permalink')) {
      $u = apply_filters('wpml_permalink', $base, $lang);
      if (is_string($u) && $u !== '') return $u;
    }

    // Fallback (por si WPML está instalado pero el filtro no está accesible en este contexto)
    if (defined('ICL_SITEPRESS_VERSION') && function_exists('apply_filters')) {
      $u = apply_filters('wpml_permalink', $base, $lang);
      if (is_string($u) && $u !== '') return $u;
    }

    return $base;
  }

  public static function register_routes(): void {
    register_rest_route('casanova/v1', '/profile', [
      [
        'methods'  => 'GET',
        'callback' => [__CLASS__, 'get_profile'],
        'permission_callback' => [__CLASS__, 'perm_logged_in'],
      ],
      [
        'methods'  => 'POST',
        'callback' => [__CLASS__, 'update_profile'],
        'permission_callback' => [__CLASS__, 'perm_logged_in'],
      ],
    ]);

    register_rest_route('casanova/v1', '/profile/password', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'change_password'],
      'permission_callback' => [__CLASS__, 'perm_logged_in'],
    ]);

    register_rest_route('casanova/v1', '/profile/locale', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'update_locale'],
      'permission_callback' => [__CLASS__, 'perm_logged_in'],
    ]);
  }

  public static function perm_logged_in(): bool {
    return is_user_logged_in();
  }

  private static function get_user_idcliente(int $user_id): int {
    return (int) get_user_meta($user_id, 'casanova_idcliente', true);
  }

  private static function map_giav_cliente($c): array {
    if (!$c || !is_object($c)) return [];

    $dni_raw = (string)($c->Documento ?? $c->PasaporteNumero ?? '');

    return [
      'idCliente'  => (int)($c->Id ?? $c->idCliente ?? 0),
      'nombre'     => trim((string)($c->Nombre ?? '')),
      'apellidos'  => trim((string)($c->Apellidos ?? '')),
      'email'      => trim((string)($c->Email ?? '')),
      'telefono'   => trim((string)($c->Telefono ?? $c->Tel ?? '')),
      'movil'      => trim((string)($c->Movil ?? '')),
      'dni_mask'   => function_exists('casanova_portal_trunc_dni') ? casanova_portal_trunc_dni($dni_raw) : '',
      'direccion'  => trim((string)($c->Direccion ?? '')),
      'codPostal'  => trim((string)($c->CodPostal ?? $c->CP ?? '')),
      'poblacion'  => trim((string)($c->Poblacion ?? '')),
      'provincia'  => trim((string)($c->Provincia ?? '')),
      'pais'       => trim((string)($c->Pais ?? '')),
    ];
  }

  public static function get_profile(WP_REST_Request $request) {
    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) return new WP_Error('not_logged_in', __('Debes iniciar sesión.', 'casanova-portal'), ['status' => 401]);

    $idCliente = self::get_user_idcliente($user_id);
    if ($idCliente <= 0) {
      return new WP_Error('not_linked', __('Tu cuenta no está vinculada todavía.', 'casanova-portal'), ['status' => 409]);
    }

    $c = function_exists('casanova_giav_cliente_get_by_id') ? casanova_giav_cliente_get_by_id($idCliente) : null;
    if (is_wp_error($c)) return $c;

    $user = get_userdata($user_id);
    $locale = (string) get_user_meta($user_id, 'casanova_portal_locale', true);
    if ($locale === '') {
      $locale = (string) get_user_locale($user_id);
    }

    return [
      'user' => [
        'id' => $user_id,
        'displayName' => $user ? (string)($user->display_name ?? '') : '',
        'email' => $user ? (string)($user->user_email ?? '') : '',
        'avatarUrl' => function_exists('get_avatar_url') ? (string) get_avatar_url($user_id, ['size' => 128]) : '',
      ],
      'giav' => self::map_giav_cliente($c),
      'locale' => $locale,
      'logoutUrl' => wp_logout_url(home_url('/')),
    ];
  }

  public static function update_profile(WP_REST_Request $request) {
    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) return new WP_Error('not_logged_in', __('Debes iniciar sesión.', 'casanova-portal'), ['status' => 401]);

    $idCliente = self::get_user_idcliente($user_id);
    if ($idCliente <= 0) {
      return new WP_Error('not_linked', __('Tu cuenta no está vinculada todavía.', 'casanova-portal'), ['status' => 409]);
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) $payload = [];

    $addr = [
      'direccion'  => sanitize_text_field($payload['direccion'] ?? ''),
      'codPostal'  => sanitize_text_field($payload['codPostal'] ?? ''),
      'poblacion'  => sanitize_text_field($payload['poblacion'] ?? ''),
      'provincia'  => sanitize_text_field($payload['provincia'] ?? ''),
      'pais'       => sanitize_text_field($payload['pais'] ?? ''),
      'telefono'   => sanitize_text_field($payload['telefono'] ?? ''),
      'movil'      => sanitize_text_field($payload['movil'] ?? ''),
    ];

    if (!function_exists('casanova_giav_cliente_update_direccion')) {
      return new WP_Error('missing_impl', __('No está disponible la actualización de perfil.', 'casanova-portal'), ['status' => 500]);
    }

    $r = casanova_giav_cliente_update_direccion($idCliente, $addr);
    if (is_wp_error($r)) return $r;

    // Devolvemos datos frescos
    return self::get_profile($request);
  }

  public static function change_password(WP_REST_Request $request) {
    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) return new WP_Error('not_logged_in', __('Debes iniciar sesión.', 'casanova-portal'), ['status' => 401]);

    $params = $request->get_json_params();
    if (!is_array($params)) $params = [];

    $current = (string)($params['current'] ?? '');
    $next = (string)($params['next'] ?? '');
    $confirm = (string)($params['confirm'] ?? '');

    if ($next === '' || strlen($next) < 8) {
      return new WP_Error('weak_password', __('La nueva contraseña debe tener al menos 8 caracteres.', 'casanova-portal'), ['status' => 400]);
    }
    if ($confirm !== '' && $confirm !== $next) {
      return new WP_Error('password_mismatch', __('Las contraseñas no coinciden.', 'casanova-portal'), ['status' => 400]);
    }

    $user = get_userdata($user_id);
    if (!$user) return new WP_Error('user_missing', __('No se ha encontrado el usuario.', 'casanova-portal'), ['status' => 404]);

    if ($current === '' || !wp_check_password($current, $user->user_pass, $user_id)) {
      return new WP_Error('bad_current_password', __('La contraseña actual no es correcta.', 'casanova-portal'), ['status' => 400]);
    }

    $updated = wp_update_user([
      'ID' => $user_id,
      'user_pass' => $next,
    ]);
    if (is_wp_error($updated)) return $updated;

    // Mantener sesión
    wp_set_auth_cookie($user_id, true);

    return ['ok' => true];
  }

  public static function update_locale(WP_REST_Request $request) {
    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) return new WP_Error('not_logged_in', __('Debes iniciar sesión.', 'casanova-portal'), ['status' => 401]);

    $params = $request->get_json_params();
    if (!is_array($params)) $params = [];

    // "locale" (es_ES) se mantiene por compatibilidad.
    $locale = sanitize_text_field((string)($params['locale'] ?? ''));
    $lang = sanitize_text_field((string)($params['lang'] ?? ''));

    if ($lang === '' && $locale !== '') {
      // Derivar lang desde locale: es_ES -> es
      $lang = strtolower(preg_replace('/[^a-zA-Z]/', '', substr($locale, 0, 2)));
    }

    if ($locale === '' && $lang === '') {
      return new WP_Error('bad_locale', __('Idioma inválido.', 'casanova-portal'), ['status' => 400]);
    }

    // Guardamos preferencia del portal (no toca el locale global del WP).
    if ($locale !== '') {
      update_user_meta($user_id, 'casanova_portal_locale', $locale);
    }
    if ($lang !== '') {
      update_user_meta($user_id, 'casanova_portal_lang', $lang);
    }

    // Si WPML está activo, devolvemos una URL en el idioma solicitado para redirigir.
    $wpml_active = ($lang !== '') && (has_filter('wpml_permalink') || defined('ICL_SITEPRESS_VERSION'));
    $redirect = $wpml_active ? self::wpml_portal_url_for_lang($lang) : '';

    return ['ok' => true, 'locale' => $locale ?: $lang, 'lang' => $lang, 'redirectUrl' => $redirect];
  }
}
