<?php
function casanova_giav_base_host(): string {
  $wsdl = defined('CASANOVA_GIAV_WSDL') ? CASANOVA_GIAV_WSDL : '';
  if (!$wsdl) return '';

  $parts = wp_parse_url($wsdl);
  if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return '';

  $base = $parts['scheme'] . '://' . $parts['host'];
  if (!empty($parts['port'])) $base .= ':' . $parts['port'];
  return $base;
}

/**
 * Comprueba si el usuario actual puede acceder a un expediente GIAV
 */
function casanova_user_can_access_expediente(int $user_id, int $idExpediente) : bool {
  if (!$user_id || !$idExpediente) return false;

  $idClienteUser = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if (!$idClienteUser) return false;

  $expedientes = casanova_giav_expedientes_por_cliente($idClienteUser);
    if (!is_array($expedientes)) return false;

  foreach ($expedientes as $exp) {
    if (!is_object($exp)) continue;

    // Intentar extraer el id del expediente con varios nombres posibles
    $id = 0;
    foreach (['IdExpediente', 'IDExpediente', 'Id', 'ID', 'Codigo', 'IdExp'] as $k) {
      if (isset($exp->$k) && $exp->$k !== '') {
        $id = (int) $exp->$k;
        if ($id > 0) break;
      }
    }

    // Como último recurso: si no hay id, saltar sin warnings
    if ($id <= 0) continue;

    if ($id === $idExpediente) return true;
  }

  return false;
}

/**
 * Determina si un expediente está completamente pagado
 * según las reglas reales del negocio.
 */
function casanova_is_expediente_pagado(int $idExpediente, int $idCliente) : bool {
  if (!$idExpediente || !$idCliente) return false;

  // Recuperar reservas del expediente (ya normalizadas)
  $reservas = casanova_giav_reservas_por_expediente($idExpediente);

  // Calcular pagos reales
  $calc = casanova_calc_pago_expediente(
    $idExpediente,
    $idCliente,
    $reservas
  );

  return !empty($calc['expediente_pagado']);
}
function casanova_human_service_type($tipo, $desc = '', $reserva = null): string {
  $tipo = strtoupper(trim((string)$tipo));
  
  // Si es OT, intentamos el custom "Tipo" (Key 2171)
  if ($tipo === 'OT' && is_object($reserva)) {
    $custom = casanova_reserva_custom_tipo($reserva, '2171');
    if ($custom !== '') {
      // Ej: "Golf" -> "Golf" (puedes mapear a algo más bonito si quieres)
            // Normalización estética: "golf" -> "Golf"
      $custom = mb_strtolower($custom, 'UTF-8');
      $custom = ucfirst($custom);

      return $custom;
    }
  }

  switch ($tipo) {
    case 'HT': return 'Alojamiento';
    case 'GF': return 'Green Fee';
    case 'TR': return 'Traslado';
    case 'FL': return 'Vuelo';
    case 'CR': return 'Alquiler de coche';
    case 'EX': return 'Excursión';
    case 'PQ': return 'Paquete';
    case 'OT': return 'Otros';
    case 'AV' : return 'Avion';
    default:
      return $desc !== '' ? $desc : 'Servicio';
  }
}


function casanova_custom_value($obj, $key): string {
  if (!is_object($obj)) return '';
  $key = (string)$key;

  $cdv = $obj->CustomDataValues ?? null;
  if (!is_object($cdv)) return '';

  $items = $cdv->CustomDataItem ?? null;
  if (!$items) return '';

  // Normaliza a array
  if (is_object($items)) $items = [$items];
  if (!is_array($items)) return '';

  foreach ($items as $it) {
    if (!is_object($it)) continue;
    $k = trim((string)($it->Key ?? ''));
    if ($k === $key) {
      return trim((string)($it->Value ?? ''));
    }
  }
  return '';
}
function casanova_reserva_custom_tipo($reserva, $key_tipo = '2171'): string {
  if (!is_object($reserva)) return '';

  // 1) Directo en la reserva
  $v = casanova_custom_value($reserva, $key_tipo);
  if ($v !== '') return $v;

  // 2) En DatosExternos (por si GIAV lo mueve)
  $dx = $reserva->DatosExternos ?? null;
  $v = casanova_custom_value($dx, $key_tipo);
  if ($v !== '') return $v;

  return '';
}

function casanova_is_golf_service($tipo, $reserva = null): bool {
  $tipo = strtoupper(trim((string)$tipo));

  if ($tipo === 'GF') return true;

  if ($tipo === 'OT' && is_object($reserva)) {
    $custom = casanova_reserva_custom_tipo($reserva, '2171');
    return mb_strtolower(trim($custom), 'UTF-8') === 'golf';
  }

  return false;
}

/**
 * Devuelve metadata básica del expediente (título y código humano) usando la lista de expedientes del cliente.
 * GIAV es la fuente de verdad; esto evita depender de un Expediente_GET que no existe.
 */
function casanova_portal_expediente_meta(int $idCliente, int $idExpediente): array {
  $idExpediente = (int)$idExpediente;
  $out = [
    'titulo' => '',
    'codigo' => '',
    'label'  => $idExpediente ? (sprintf(__('Expediente %s', 'casanova-portal'), $idExpediente)) : '',
  ];
  if ($idCliente <= 0 || $idExpediente <= 0) return $out;

  if (!function_exists('casanova_giav_expedientes_por_cliente')) return $out;

  // GIAV limita pageSize a 100. Buscamos en varias páginas para poder resolver el título/código
  // incluso si el expediente no está en la primera página.
  $pageSize = 100;
  $maxPages = 5; // 500 expedientes como tope razonable
  $items = [];
  for ($page = 0; $page < $maxPages; $page++) {
    $chunk = casanova_giav_expedientes_por_cliente((int)$idCliente, $pageSize, $page * $pageSize);
    if (is_wp_error($chunk) || !is_array($chunk) || empty($chunk)) break;
    $items = $chunk;
    // Intentamos encontrarlo en este chunk; si no, seguimos.
    $found = false;
    foreach ($items as $e) {
      if (!is_object($e)) continue;
      $eid = (int)($e->IdExpediente ?? $e->Id ?? 0);
      if ($eid === $idExpediente) { $found = true; break; }
    }
    if ($found) break;
  }
  if (is_wp_error($items) || !is_array($items)) return $out;

  foreach ($items as $e) {
    if (!is_object($e)) continue;
    $id = (int)($e->IdExpediente ?? $e->Id ?? 0);
    if ($id !== $idExpediente) continue;
    $titulo = trim((string)($e->Titulo ?? ''));
    $codigo = trim((string)($e->Codigo ?? ''));
    $out['titulo'] = $titulo;
    $out['codigo'] = $codigo;
    if ($titulo !== '' && $codigo !== '') {
      $out['label'] = $titulo . ' (' . $codigo . ')';
    } elseif ($titulo !== '') {
      $out['label'] = $titulo;
    } elseif ($codigo !== '') {
      $out['label'] = sprintf(__('Expediente %s', 'casanova-portal'), $codigo);
    }
    break;
  }

  return $out;
}
