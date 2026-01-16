<?php
if (!defined('ABSPATH')) exit;

/**
 * Billetes asociados a una reserva (para servicios AV/FL).
 * Usa Billete_SEARCH filtrando por idsReserva.
 *
 * @return array<int,object>|WP_Error
 */
function casanova_giav_billetes_por_reserva(int $idReserva, int $pageSize = 50, int $pageIndex = 0) {
  $idReserva = (int) $idReserva;
  if ($idReserva <= 0) return [];

  if (function_exists('casanova_cache_remember')) {
    return casanova_cache_remember(
      'giav:billetes_por_reserva:' . $idReserva . ':' . (int)$pageSize . ':' . (int)$pageIndex,
      defined('CASANOVA_CACHE_TTL') ? (int)CASANOVA_CACHE_TTL : 90,
      function () use ($idReserva, $pageSize, $pageIndex) {
        return casanova_giav_billetes_por_reserva_uncached($idReserva, $pageSize, $pageIndex);
      }
    );
  }

  return casanova_giav_billetes_por_reserva_uncached($idReserva, $pageSize, $pageIndex);
}

/**
 * @return array<int,object>|WP_Error
 */
function casanova_giav_billetes_por_reserva_uncached(int $idReserva, int $pageSize = 50, int $pageIndex = 0) {
  $pageSize  = max(1, min(100, (int)$pageSize));
  $pageIndex = max(0, (int)$pageIndex);

  if (!function_exists('casanova_giav_call')) {
    return new WP_Error('giav_missing', 'GIAV call helper missing');
  }

  $p = new stdClass();
  $p->apikey = defined('CASANOVA_GIAV_APIKEY') ? CASANOVA_GIAV_APIKEY : '';

  // Filtro principal (WSDL: idsReserva)
  $p->idsReserva = (object) ['int' => [(int)$idReserva]];

  // Obligatorios según WSDL
  $p->recepcionCosteTotal = 'NoAplicar';
  $p->modofiltroImporte = 'NoAplicar';
  $p->importeDesde = null;
  $p->importeHasta = null;
  $p->liquidado = 'NoAplicar';
  $p->pagoDirecto_UATP = 'NoAplicar';

  // Opcionales
  $p->idsBillete = null;
  $p->idsOficina = null;
  $p->codExpedienteDesde = null;
  $p->codExpedienteHasta = null;
  $p->codFacturaDesde = null;
  $p->codFacturaHasta = null;
  $p->numBillete = null;
  $p->fechaEmisionDesde = null;
  $p->fechaEmisionHasta = null;
  $p->nombrePasajero = null;
  $p->trayecto = null;
  $p->idsProveedor = null;
  $p->idsGastoGestion = null;
  $p->idsEntitiesStages = null;
  $p->fechaHoraModificacionDesde = null;
  $p->fechaHoraModificacionHasta = null;
  $p->customDataValues = null;

  $p->pageSize = $pageSize;
  $p->pageIndex = $pageIndex;

  $resp = casanova_giav_call('Billete_SEARCH', $p);
  if (is_wp_error($resp)) return $resp;

  $result = $resp->Billete_SEARCHResult ?? null;
  if (!function_exists('casanova_giav_normalize_list')) return [];

  return casanova_giav_normalize_list($result, 'WsBilleteV2');
}

/**
 * Segmentos para uno o varios billetes.
 * Usa BilleteSegmento_SEARCH filtrando por idsBilletes.
 *
 * @param array<int,int> $idsBilletes
 * @return array<int,object>|WP_Error
 */
function casanova_giav_billete_segmentos_por_billetes(array $idsBilletes, int $pageSize = 100, int $pageIndex = 0) {
  $idsBilletes = array_values(array_filter(array_map('intval', $idsBilletes), function($v){ return $v > 0; }));
  if (empty($idsBilletes)) return [];

  if (function_exists('casanova_cache_remember')) {
    $key = 'giav:billete_segmentos:' . md5(json_encode($idsBilletes)) . ':' . (int)$pageSize . ':' . (int)$pageIndex;
    return casanova_cache_remember(
      $key,
      defined('CASANOVA_CACHE_TTL') ? (int)CASANOVA_CACHE_TTL : 90,
      function () use ($idsBilletes, $pageSize, $pageIndex) {
        return casanova_giav_billete_segmentos_por_billetes_uncached($idsBilletes, $pageSize, $pageIndex);
      }
    );
  }

  return casanova_giav_billete_segmentos_por_billetes_uncached($idsBilletes, $pageSize, $pageIndex);
}

/**
 * @param array<int,int> $idsBilletes
 * @return array<int,object>|WP_Error
 */
function casanova_giav_billete_segmentos_por_billetes_uncached(array $idsBilletes, int $pageSize = 100, int $pageIndex = 0) {
  $pageSize  = max(1, min(100, (int)$pageSize));
  $pageIndex = max(0, (int)$pageIndex);

  if (!function_exists('casanova_giav_call')) {
    return new WP_Error('giav_missing', 'GIAV call helper missing');
  }

  $p = new stdClass();
  $p->apikey = defined('CASANOVA_GIAV_APIKEY') ? CASANOVA_GIAV_APIKEY : '';
  $p->idsBilletes = (object) ['int' => $idsBilletes];
  $p->idsBilleteSegmentos = null;
  $p->fechaHoraModificacionDesde = null;
  $p->fechaHoraModificacionHasta = null;
  $p->pageSize = $pageSize;
  $p->pageIndex = $pageIndex;

  $resp = casanova_giav_call('BilleteSegmento_SEARCH', $p);
  if (is_wp_error($resp)) return $resp;

  $result = $resp->BilleteSegmento_SEARCHResult ?? null;
  if (!function_exists('casanova_giav_normalize_list')) return [];

  return casanova_giav_normalize_list($result, 'WsBilleteSegmento');
}
