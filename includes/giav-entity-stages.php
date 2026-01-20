<?php
if (!defined('ABSPATH')) exit;

/**
 * GIAV: catálogo de estados (EntityStages) por entidad.
 *
 * En GIAV los estados configurables por el usuario se devuelven en:
 * - En cada entidad: IdEntityStage
 * - Catálogo: EntityStages_GET(targetClass)
 */

/**
 * Devuelve el listado de estados para una entidad GIAV.
 *
 * @param string $targetClass Ej: 'Expediente'
 * @return array<int,array{Id:int,StageName:?string,OrderNum:int,Cancelled:bool,ClassName:?string}>
 */
function casanova_giav_entity_stages(string $targetClass): array {
  $targetClass = trim($targetClass);
  if ($targetClass === '') return [];

  $ttl = defined('CASANOVA_ENTITY_STAGES_TTL') ? (int) CASANOVA_ENTITY_STAGES_TTL : 86400;
  if ($ttl <= 0) $ttl = 86400;

  $cache_key = 'giav:entity_stages:' . strtolower($targetClass);

  // Preferimos el wrapper de caché del proyecto si existe.
  if (function_exists('casanova_cache_remember')) {
    return (array) casanova_cache_remember($cache_key, $ttl, function () use ($targetClass) {
      return casanova_giav_entity_stages_uncached($targetClass);
    });
  }

  $cached = get_transient($cache_key);
  if (is_array($cached) && !empty($cached)) {
    return $cached;
  }

  $rows = casanova_giav_entity_stages_uncached($targetClass);
  set_transient($cache_key, $rows, $ttl);
  return $rows;
}

function casanova_giav_entity_stages_uncached(string $targetClass): array {
  if (!function_exists('casanova_giav_call')) return [];

  $p = new stdClass();
  $p->apikey = defined('CASANOVA_GIAV_APIKEY') ? CASANOVA_GIAV_APIKEY : null;
  $p->targetClass = $targetClass; // enum CrmTargetClass

  $resp = casanova_giav_call('EntityStages_GET', $p);
  if (is_wp_error($resp)) return [];

  $result = $resp->EntityStages_GETResult ?? null;
  if ($result === null) return [];

  // Normalizamos ArrayOfWsEntityStage
  $items = [];
  if (function_exists('casanova_giav_normalize_list')) {
    $items = casanova_giav_normalize_list($result, 'WsEntityStage');
  } else {
    if (is_object($result) && isset($result->WsEntityStage)) {
      $raw = $result->WsEntityStage;
      if (is_array($raw)) $items = $raw;
      elseif (is_object($raw)) $items = [$raw];
    } elseif (is_array($result)) {
      $items = $result;
    } elseif (is_object($result)) {
      $items = [$result];
    }
  }

  $out = [];
  foreach ($items as $s) {
    if (!is_object($s)) continue;
    $id = (int) ($s->Id ?? 0);
    if ($id <= 0) continue;
    $out[] = [
      'Id' => $id,
      'StageName' => isset($s->StageName) ? (string) $s->StageName : null,
      'ClassName' => isset($s->ClassName) ? (string) $s->ClassName : null,
      'OrderNum' => (int) ($s->OrderNum ?? 0),
      'Cancelled' => (bool) ($s->Cancelled ?? false),
    ];
  }

  usort($out, function($a, $b) {
    $oa = (int) ($a['OrderNum'] ?? 0);
    $ob = (int) ($b['OrderNum'] ?? 0);
    if ($oa === $ob) return ((int)$a['Id']) <=> ((int)$b['Id']);
    return $oa <=> $ob;
  });

  return $out;
}

/**
 * Devuelve el nombre de estado a partir de su IdEntityStage.
 */
function casanova_giav_entity_stage_name(string $targetClass, int $idEntityStage): ?string {
  if ($idEntityStage <= 0) return null;

  static $maps = [];
  $k = strtolower(trim($targetClass));

  if (!isset($maps[$k])) {
    $rows = casanova_giav_entity_stages($targetClass);
    $map = [];
    foreach ($rows as $r) {
      $id = (int) ($r['Id'] ?? 0);
      if ($id <= 0) continue;
      $map[$id] = isset($r['StageName']) && $r['StageName'] !== '' ? (string)$r['StageName'] : null;
    }
    $maps[$k] = $map;
  }

  return $maps[$k][$idEntityStage] ?? null;
}
