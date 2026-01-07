<?php

if (!defined('ABSPATH')) exit;

/**
 * Casanova Portal - Vinculación WP <-> GIAV (Cliente_SEARCH)
 */
function casanova_giav_client(): SoapClient {
  static $client = null;
  if ($client instanceof SoapClient) return $client;

  if (!defined('CASANOVA_GIAV_WSDL')) {
    throw new Exception('CASANOVA_GIAV_WSDL no está definido en wp-config.php');
  }

  $opts = [
    'trace' => true,
    'exceptions' => true,
    'cache_wsdl' => WSDL_CACHE_BOTH,
    'connection_timeout' => 15,
  ];

  if (defined('CASANOVA_GIAV_USER') && defined('CASANOVA_GIAV_PASS') && CASANOVA_GIAV_PASS !== '') {
    $opts['login'] = CASANOVA_GIAV_USER;
    $opts['password'] = CASANOVA_GIAV_PASS;
  }

  $client = new SoapClient(CASANOVA_GIAV_WSDL, $opts);
  return $client;
}
/**
 * Llama a un método SOAP metiendo apikey automáticamente en el body.
 * Acepta $params como array o stdClass y lo convierte a stdClass.
 */
/**
 * Convierte arrays asociativos a stdClass, pero mantiene arrays "lista" como arrays.
 * Esto es clave para ArrayOfInt y ArrayOfCustomDataItem.
 */
function casanova_giav_call(string $method, $params = []) {
  $client = casanova_giav_client();

  if (is_array($params)) {
    $obj = new stdClass();
    foreach ($params as $k => $v) $obj->$k = $v;
    $params = $obj;
  } elseif (!is_object($params)) {
    $params = new stdClass();
  }

  if (!isset($params->apikey)) {
    $params->apikey = CASANOVA_GIAV_APIKEY;
  }

 try {
     if (defined('CASANOVA_GIAV_DEBUG') && CASANOVA_GIAV_DEBUG && $method === 'Factura_SEARCH') {
  error_log('[CASANOVA SOAP] Factura_SEARCH PARAMS: ' . print_r($params, true));
}
  $res = $client->__soapCall($method, [$params]);

  // Debug controlado por método
  if (defined('CASANOVA_GIAV_DEBUG') && CASANOVA_GIAV_DEBUG) {

    if ($method === 'Reservas_SEARCH') {
      error_log('[CASANOVA SOAP] Reservas_SEARCH LAST REQUEST: ' . $client->__getLastRequest());
      error_log('[CASANOVA SOAP] Reservas_SEARCH LAST RESPONSE: ' . $client->__getLastResponse());
    }

    if ($method === 'Factura_SEARCH') {
      error_log('[CASANOVA SOAP] Factura_SEARCH LAST REQUEST: ' . $client->__getLastRequest());
      error_log('[CASANOVA SOAP] Factura_SEARCH LAST RESPONSE: ' . $client->__getLastResponse());
    }

    if ($method === 'Cobro_SEARCH') {
      error_log('[CASANOVA SOAP] Cobro_SEARCH LAST REQUEST: ' . $client->__getLastRequest());
      error_log('[CASANOVA SOAP] Cobro_SEARCH LAST RESPONSE: ' . $client->__getLastResponse());
    }
    
    if ($method === 'Expediente_SEARCH') {
      error_log('[CASANOVA SOAP] Expediente_SEARCH LAST REQUEST: ' . $client->__getLastRequest());
      error_log('[CASANOVA SOAP] Expediente_SEARCH LAST RESPONSE: ' . $client->__getLastResponse());
    }
  }

  return $res;

} catch (Throwable $e) {

  error_log('[CASANOVA SOAP] ' . $method . ' :: ' . $e->getMessage());

  // Log del último request/response si existen
  if (defined('CASANOVA_GIAV_DEBUG') && CASANOVA_GIAV_DEBUG) {
    try {
      error_log('[CASANOVA SOAP] ' . $method . ' LAST REQUEST (ERR): ' . $client->__getLastRequest());
    } catch (Throwable $x) {}

    try {
      error_log('[CASANOVA SOAP] ' . $method . ' LAST RESPONSE (ERR): ' . $client->__getLastResponse());
    } catch (Throwable $x) {}
  }

  return new WP_Error('soap_error', $e->getMessage());
}
}

/**
 * Normaliza listas SOAP que pueden venir como:
 * - null
 * - objeto con propiedad WsX (objeto o array)
 * - array directo
 */
function casanova_soap_list($maybeList, string $itemProp) : array {
  if ($maybeList === null) return [];

  // GIAV a veces devuelve stdClass {} vacío en vez de null/array
  if (is_object($maybeList) && count(get_object_vars($maybeList)) === 0) return [];

  // Si ya viene como array, listo.
  if (is_array($maybeList)) return $maybeList;

  // Caso típico: objeto contenedor con propiedad lista (WsX)
  if (is_object($maybeList) && isset($maybeList->$itemProp)) {
    $items = $maybeList->$itemProp;

    if ($items === null) return [];
    if (is_object($items) && count(get_object_vars($items)) === 0) return [];

    if (is_array($items)) return $items;
    if (is_object($items)) return [$items];
    return [];
  }

  // Si es un objeto "item" directo
  if (is_object($maybeList)) return [$maybeList];
  return [];
}

/**
 * Alias explícito (más semántico) para normalizar listas GIAV.
 *
 * Ejemplo: $items = casanova_giav_normalize_list($result, 'WsFactura');
 */
function casanova_giav_normalize_list($container, string $itemProp) : array {
  return casanova_soap_list($container, $itemProp);
}

add_action('admin_post_casanova_debug_run_reconcile', function () {
  if (!current_user_can('manage_options')) wp_die('No');
  $id = isset($_GET['intent']) ? (int)$_GET['intent'] : 0;
  if ($id <= 0) wp_die('intent missing');
  do_action('casanova_job_reconcile_payment', $id);
  echo "OK reconcile job ejecutado para intent $id";
  exit;
});