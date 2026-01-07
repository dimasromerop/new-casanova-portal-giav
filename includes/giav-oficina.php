<?php
// includes/giav-oficina.php

if (!defined('ABSPATH')) exit;

/**
 * Devuelve WsOficina por ID desde GIAV.
 */
function casanova_giav_oficina_get(int $idOficina) {
  if ($idOficina <= 0) return new WP_Error('bad_id', 'ID oficina inválido');

  $res = casanova_giav_call('Oficina_GET', [
    'id' => $idOficina,
  ]);
  
   if (is_wp_error($res)) return $res;

  // Dependiendo del wrapper, puede venir directo o dentro de ...Result
  if (is_object($res) && isset($res->Oficina_GETResult)) return $res->Oficina_GETResult;

  return $res;
}

/**
 * Perfil “agencia” listo para usar en bonos/facturas/etc.
 * Cacheado para no machacar GIAV.
 */
function casanova_portal_agency_profile(): array {
  $cache_key = 'casanova_agency_profile_v1';
  $cached = get_transient($cache_key);
  if (is_array($cached) && !empty($cached)) return $cached;

  $idOficina = (int) (defined('CASANOVA_GIAV_IDOFICINA') ? CASANOVA_GIAV_IDOFICINA : 0);

  $fallback = [
    'nombre'    => defined('CASANOVA_AGENCY_NAME') ? CASANOVA_AGENCY_NAME : 'Casanova Golf',
    'email'     => defined('CASANOVA_AGENCY_EMAIL') ? CASANOVA_AGENCY_EMAIL : '',
    'tel'       => defined('CASANOVA_AGENCY_PHONE') ? CASANOVA_AGENCY_PHONE : '',
    'web'       => defined('CASANOVA_AGENCY_WEB') ? CASANOVA_AGENCY_WEB : '',
    'direccion' => defined('CASANOVA_AGENCY_ADDR') ? CASANOVA_AGENCY_ADDR : '',
  ];

  if ($idOficina <= 0) {
    set_transient($cache_key, $fallback, 6 * HOUR_IN_SECONDS);
    return $fallback;
  }

  $resp = casanova_giav_call('Oficina_GET', ['id' => $idOficina]);
  if (is_wp_error($resp)) {
    set_transient($cache_key, $fallback, 6 * HOUR_IN_SECONDS);
    return $fallback;
  }

  $office = is_object($resp) && isset($resp->Oficina_GETResult) ? $resp->Oficina_GETResult : $resp;
  if (!is_object($office)) {
    set_transient($cache_key, $fallback, 6 * HOUR_IN_SECONDS);
    return $fallback;
  }

  $nombre = trim((string)($office->NombreComercial ?? $office->Denominacion ?? ''));
  $dir = trim((string)($office->Direccion ?? ''));
  $cp  = trim((string)($office->CodPostal ?? ''));
  $pob = trim((string)($office->Poblacion ?? ''));
  $prov= trim((string)($office->Provincia ?? ''));
  $pais= trim((string)($office->Pais ?? ''));

  $direccion_full = trim(implode(', ', array_filter([$dir, trim($cp . ' ' . $pob), $prov, $pais])));

  $profile = [
    'nombre'    => $nombre !== '' ? $nombre : $fallback['nombre'],
    'email'     => trim((string)($office->Email ?? '')) ?: $fallback['email'],
    'tel'       => trim((string)($office->Telefono ?? '')) ?: $fallback['tel'],
    'web'       => $fallback['web'],
    'direccion' => $direccion_full !== '' ? $direccion_full : $fallback['direccion'],
    'idOficina' => (int)($office->Id ?? $idOficina),
    'codigo'    => (string)($office->Codigo ?? ''),
  ];

  set_transient($cache_key, $profile, 12 * HOUR_IN_SECONDS);
  return $profile;
}