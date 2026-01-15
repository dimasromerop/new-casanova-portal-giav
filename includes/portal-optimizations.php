<?php
if (!defined('ABSPATH')) exit;

/**
 * Optimizaciones seguras (sin romper nada):
 * - Cache corta vía transients
 * - Logging centralizado con niveles
 * - Base URL del portal desacoplada del slug
 *
 * Todo es opcional y con fallback al comportamiento actual.
 */

// ===== Config defaults (override con wp-config.php si quieres) =====

if (!defined('CASANOVA_CACHE_ENABLED')) {
  define('CASANOVA_CACHE_ENABLED', true);
}

if (!defined('CASANOVA_CACHE_TTL')) {
  // Cache corta para evitar desincronizaciones y dolores de cabeza.
  define('CASANOVA_CACHE_TTL', 600);
}

if (!defined('CASANOVA_LOG_LEVEL')) {
  // En producción: error|warn. En dev (WP_DEBUG): info.
  define('CASANOVA_LOG_LEVEL', (defined('WP_DEBUG') && WP_DEBUG) ? 'info' : 'warn');
}

/**
 * Base URL del portal (antes hardcodeado a /area-usuario/)
 * Puedes sobreescribirlo con:
 *   add_filter('casanova_portal_base_url', fn() => home_url('/tu-pagina/'));
 */
function casanova_portal_base_url(): string {
  $default = home_url('/portal-app/');
  $url = (string) apply_filters('casanova_portal_base_url', $default);
  return $url ?: $default;
}

// ===== Logging =====

function casanova_log_level_rank(string $level): int {
  $level = strtolower(trim($level));
  switch ($level) {
    case 'debug':
      return 10;
    case 'info':
      return 20;
    case 'warn':
    case 'warning':
      return 30;
    case 'error':
    default:
      return 40;
  }
}

/**
 * Log centralizado.
 * Ej: casanova_log('payments', 'Intent created', ['id' => 123]);
 */
function casanova_log(string $channel, string $message, array $context = [], string $level = 'info'): void {
  $min = casanova_log_level_rank((string)CASANOVA_LOG_LEVEL);
  $cur = casanova_log_level_rank($level);
  if ($cur < $min) return;

  $prefix = '[CASANOVA][' . strtoupper($channel) . '][' . strtoupper($level) . '] ';
  $line = $prefix . $message;
  if (!empty($context)) {
    // Evitar warnings por recursos/ciclos.
    $json = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json) $line .= ' ' . $json;
  }
  error_log($line);
}

// ===== Cache =====


/**
 * Cache buster global: incrementa para invalidar transients sin borrarlos.
 */
function casanova_cache_buster_get(): int {
  $v = (int) get_option('casanova_cache_buster', 1);
  return $v > 0 ? $v : 1;
}
function casanova_cache_buster_bump(): int {
  $v = casanova_cache_buster_get() + 1;
  update_option('casanova_cache_buster', $v, false);
  return $v;
}

function casanova_cache_key(string $key): string {
  $blog_id = function_exists('get_current_blog_id') ? (int)get_current_blog_id() : 1;
    $buster = function_exists('casanova_cache_buster_get') ? casanova_cache_buster_get() : 1;
  return 'casanova_' . $blog_id . '_' . $buster . '_' . md5($key);
}

/**
 * Cache “remember” con TTL corto.
 * - No cachea WP_Error
 * - Fallback a ejecución normal si cache está desactivada
 */
function casanova_cache_remember(string $key, int $ttl, callable $fn) {
  if (!CASANOVA_CACHE_ENABLED) {
    return $fn();
  }

  $tkey = casanova_cache_key($key);
  $cached = get_transient($tkey);

  if ($cached !== false) {
    return $cached;
  }

  $value = $fn();
  if (is_wp_error($value)) {
    return $value;
  }

  // TTL mínimo defensivo
  $ttl = max(5, (int)$ttl);
  set_transient($tkey, $value, $ttl);

  return $value;
}

/**
 * Invalidaciร caches relevantes para un cliente/expediente tras pagos.
 */
function casanova_invalidate_customer_cache(int $user_id, int $idCliente, int $idExpediente = 0): void {
  if (function_exists('casanova_cache_buster_bump')) {
    casanova_cache_buster_bump();
  }

  if ($idCliente > 0) {
    delete_transient('casanova_dash_v1_' . $idCliente);
    delete_transient('casanova_dashboard_' . $idCliente);
  }

  if ($user_id > 0) {
    delete_transient('casanova_profile_' . $user_id);
  }

  if ($idExpediente > 0) {
    delete_transient('casanova_pasajeros_expediente_' . $idExpediente);
  }

  do_action('casanova_customer_cache_invalidated', $user_id, $idCliente, $idExpediente);
}
