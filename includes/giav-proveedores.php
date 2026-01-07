<?php
if (!defined('ABSPATH')) exit;

/**
 * Trae un proveedor por ID desde GIAV.
 */
function casanova_giav_proveedor_get(int $idProveedor) {
  if ($idProveedor <= 0) return new WP_Error('bad_id', 'ID proveedor inválido');

  $res = casanova_giav_call('Proveedor_GET', [
    'id' => $idProveedor,
  ]);

  if (is_wp_error($res)) return $res;

  // Puede venir directo o dentro de ...Result según tu wrapper
  if (is_object($res) && isset($res->Proveedor_GETResult)) return $res->Proveedor_GETResult;

  return $res;
}

/**
 * Devuelve perfil del proveedor listo para pintar (cacheado).
 */
function casanova_portal_provider_profile(int $idProveedor): array {
  $idProveedor = (int)$idProveedor;
  if ($idProveedor <= 0) return [
    'id' => 0,
    'nombre' => '',
    'tel' => '',
    'email' => '',
    'direccion' => '',
  ];

  $cache_key = 'casanova_provider_profile_v1_' . $idProveedor;
  $cached = get_transient($cache_key);
  if (is_array($cached)) return $cached;

  $prov = casanova_giav_proveedor_get($idProveedor);
  if (is_wp_error($prov) || !is_object($prov)) {
    $fallback = [
      'id' => $idProveedor,
      'nombre' => 'Proveedor #' . $idProveedor,
      'tel' => '',
      'email' => '',
      'direccion' => '',
    ];
    set_transient($cache_key, $fallback, 6 * HOUR_IN_SECONDS);
    return $fallback;
  }

  // Campos típicos: Denominacion/NombreComercial + datos de contacto
  // No asumimos todos: usamos isset/?? para no warnings.
 $alias  = trim((string)($prov->NombreAlias ?? ''));
$nombre = trim((string)($prov->Nombre ?? ''));

if ($alias !== '') {
  $nombre_final = $alias;
} elseif ($nombre !== '') {
  $nombre_final = $nombre;
} else {
  $nombre_final = 'Proveedor #' . $idProveedor;
}

  $dir = trim((string)($prov->Direccion ?? ''));
  $cp  = trim((string)($prov->CodPostal ?? ''));
  $pob = trim((string)($prov->Poblacion ?? ''));
  $provincia = trim((string)($prov->Provincia ?? ''));
  $pais = trim((string)($prov->Pais ?? ''));

  $direccion_full = trim(implode(', ', array_filter([$dir, trim($cp . ' ' . $pob), $provincia, $pais])));

  $profile = [
    'id' => $idProveedor,
    'nombre' => $nombre_final,
    'tel' => trim((string)($prov->Telefono ?? '')),
    'email' => trim((string)($prov->Email ?? '')),
    'direccion' => $direccion_full,
  ];

  set_transient($cache_key, $profile, 12 * HOUR_IN_SECONDS);
  return $profile;
}