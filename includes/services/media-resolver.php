<?php
if (!defined('ABSPATH')) exit;

/**
 * Resolve a WP-side media (image/permalink) for a GIAV service.
 *
 * Assumptions (because humans love them):
 * - The mapping between GIAV (idProveedor/idProducto) and WP objects is maintained elsewhere
 *   (typically the proposals plugin). This portal plugin only *consumes* that mapping.
 * - If mapping tables or objects don't exist, we safely return null media.
 *
 * @param string $service_type  GIAV TipoReserva (HT/OT/PQ/...) already normalized.
 * @param int    $id_proveedor  GIAV IdProveedor.
 * @param int    $id_producto   GIAV IdProducto.
 * @param string $service_title Fallback hint.
 *
 * @return array{image_url:?string,permalink:?string,source:?string}
 */
function casanova_portal_resolve_service_media(string $service_type, int $id_proveedor, int $id_producto, string $service_title = ''): array {
  $service_type = strtoupper(trim($service_type));

  // Allow projects to override everything.
  $filtered = apply_filters('casanova_portal_resolve_service_media', null, $service_type, $id_proveedor, $id_producto, $service_title);
  if (is_array($filtered) && (array_key_exists('image_url', $filtered) || array_key_exists('permalink', $filtered))) {
    return [
      'image_url' => isset($filtered['image_url']) ? (string)$filtered['image_url'] : null,
      'permalink' => isset($filtered['permalink']) ? (string)$filtered['permalink'] : null,
      'source' => isset($filtered['source']) ? (string)$filtered['source'] : 'filter',
    ];
  }

  // Use service type to disambiguate suppliers that map to both a hotel and a golf course.
  // Mapping table stores wp_object_type as 'hotel' or 'course'.
  $mapping = casanova_portal_find_wp_mapping($service_type, $id_proveedor, $id_producto);
  if (!$mapping) {
    return ['image_url' => null, 'permalink' => null, 'source' => null];
  }

  $object_type = strtolower((string)($mapping['wp_object_type'] ?? ''));
  $object_id   = (int)($mapping['wp_object_id'] ?? 0);
  if ($object_id <= 0) {
    return ['image_url' => null, 'permalink' => null, 'source' => null];
  }

  // Golf courses: usually a WP post/CPT.
  if (
    $object_type === 'post' || $object_type === 'cpt' || $object_type === 'campos_de_golf' ||
    $object_type === 'golf_course' || $object_type === 'golf' || $object_type === 'course'
  ) {
    $img = get_the_post_thumbnail_url($object_id, 'large');
    $url = get_permalink($object_id);
    return [
      'image_url' => $img ? (string)$img : null,
      'permalink' => $url ? (string)$url : null,
      'source' => 'wp_post',
    ];
  }

  // Hotels: usually JetEngine CCT (wp_jet_cct_hoteles) with imagen_hotel.
  if ($object_type === 'jet_cct_hoteles' || $object_type === 'hoteles_cct' || $object_type === 'hotel' || $object_type === 'cct_hotel') {
    $row = casanova_portal_get_hotel_cct_row($object_id);
    if (!$row) {
      return ['image_url' => null, 'permalink' => null, 'source' => null];
    }
    $img = casanova_portal_extract_image_url_from_cct($row, 'imagen_hotel');
    return [
      'image_url' => $img,
      'permalink' => null,
      'source' => 'jetengine_cct',
    ];
  }

  // Unknown object type.
  return ['image_url' => null, 'permalink' => null, 'source' => null];
}

/**
 * Attempt to find a mapping row in a shared GIAV mapping table.
 * We support multiple table/column name variants to avoid fragile coupling.
 */
function casanova_portal_find_wp_mapping(string $service_type, int $id_proveedor, int $id_producto): ?array {
  global $wpdb;
  if (!$wpdb) return null;
  if ($id_proveedor <= 0 && $id_producto <= 0) return null;

  $service_type = strtoupper(trim($service_type));
  $wanted_wp_object_type = null;
  if ($service_type === 'HT') {
    $wanted_wp_object_type = 'hotel';
  } elseif ($service_type === 'OT') {
    // Best-effort: in our mapping table, OT images are only relevant for golf (courses).
    // Transfers/extras won't have wp_object_type=course mapping.
    $wanted_wp_object_type = 'course';
  }

  $candidates = [
    $wpdb->prefix . 'wp_travel_giav_mapping',
    $wpdb->prefix . 'travel_giav_mapping',
    $wpdb->prefix . 'giav_mapping',
    $wpdb->prefix . 'wp_travel_proposals_giav_mapping',
  ];

  $table = null;
  foreach ($candidates as $t) {
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
    if ($exists === $t) { $table = $t; break; }
  }
  if (!$table) return null;

  $cols = $wpdb->get_col('DESCRIBE ' . $table, 0);
  if (!is_array($cols) || !$cols) return null;
  $cols_l = array_map('strtolower', $cols);

  $col_supplier = casanova_portal_pick_col($cols_l, ['giav_supplier_id','supplier_id','id_proveedor','idproveedor','giav_entity_id']);
  $col_product  = casanova_portal_pick_col($cols_l, ['giav_product_id','product_id','id_producto','idproducto']);
  $col_type     = casanova_portal_pick_col($cols_l, ['wp_object_type','object_type','wp_type','tipo_wp']);
  $col_id       = casanova_portal_pick_col($cols_l, ['wp_object_id','object_id','wp_id','id_wp']);

  if (!$col_type || !$col_id) return null;

  $where = [];
  if ($col_supplier && $id_proveedor > 0) {
    $where[] = $wpdb->prepare("`$col_supplier` = %d", $id_proveedor);
  }
  if ($col_product && $id_producto > 0) {
    $where[] = $wpdb->prepare("`$col_product` = %d", $id_producto);
  }
  if ($wanted_wp_object_type && $col_type) {
    $where[] = $wpdb->prepare("LOWER(`$col_type`) = %s", strtolower($wanted_wp_object_type));
  }

  if (!$where) return null;

  $sql = "SELECT `$col_type` AS wp_object_type, `$col_id` AS wp_object_id FROM `$table` WHERE " . implode(' AND ', $where) . ' LIMIT 1';
  $row = $wpdb->get_row($sql, ARRAY_A);
  return is_array($row) ? $row : null;
}

function casanova_portal_pick_col(array $cols_lower, array $wanted): ?string {
  foreach ($wanted as $w) {
    $idx = array_search(strtolower($w), $cols_lower, true);
    if ($idx !== false) return $cols_lower[$idx];
  }
  return null;
}

function casanova_portal_get_hotel_cct_row(int $cct_id): ?array {
  global $wpdb;
  if (!$wpdb || $cct_id <= 0) return null;

  $table = $wpdb->prefix . 'jet_cct_hoteles';
  $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
  if ($exists !== $table) return null;

  $cols = $wpdb->get_col('DESCRIBE ' . $table, 0);
  if (!is_array($cols) || !$cols) return null;
  $pk = in_array('_ID', $cols, true) ? '_ID' : (in_array('id', $cols, true) ? 'id' : null);
  if (!$pk) return null;

  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE `$pk` = %d LIMIT 1", $cct_id), ARRAY_A);
  return is_array($row) ? $row : null;
}

/**
 * JetEngine CCT media fields can be stored as:
 *  - attachment ID (numeric)
 *  - URL string
 *  - JSON-ish array string
 */
function casanova_portal_extract_image_url_from_cct(array $row, string $field): ?string {
  $raw = $row[$field] ?? null;
  if ($raw === null) return null;

  if (is_numeric($raw)) {
    $url = wp_get_attachment_url((int)$raw);
    return $url ? (string)$url : null;
  }

  $raw = trim((string)$raw);
  if ($raw === '') return null;

  if (preg_match('~^https?://~i', $raw)) return $raw;

  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    // common JetEngine shape: [{"id":123,"url":"..."}] or {"url":"..."}
    if (isset($decoded['url']) && is_string($decoded['url'])) return $decoded['url'];
    if (isset($decoded[0]) && is_array($decoded[0])) {
      if (isset($decoded[0]['url']) && is_string($decoded[0]['url'])) return $decoded[0]['url'];
      if (isset($decoded[0]['id']) && is_numeric($decoded[0]['id'])) {
        $url = wp_get_attachment_url((int)$decoded[0]['id']);
        return $url ? (string)$url : null;
      }
    }
  }

  return null;
}
