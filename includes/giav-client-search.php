<?php
/**
 * Cliente_SEARCH por documento usando enums correctos:
 * documentoModo: Solo_NIF | Solo_Pasaporte | Ambos
 * rgpdSigned: NoAplicar | Si | No
 * modoFecha: Creacion | RelevantDates | CreacionModificacion
 */
function casanova_giav_cliente_search_por_dni(string $dni) {
  $dni = preg_replace('/\s+/', '', strtoupper(trim($dni)));
  if ($dni === '') return [];

  $p = new stdClass();
  $p->apikey = CASANOVA_GIAV_APIKEY;

  // Filtro principal
  $p->documento = $dni;
  $p->documentoModo = 'Solo_NIF';      // <-- CLAVE (ya lo has comprobado)
  $p->documentoExacto = true;

  // Para no filtrar por RGPD, usa NoAplicar (según tu ayuda)
  $p->rgpdSigned = 'NoAplicar';

  // Si no quieres filtrar por fechas, mejor NO mandar modoFecha,
  // pero para evitar "Encoding missing property", lo declaramos con null:
  $p->modoFecha = 'Creacion';
  $p->fechaHoraDesde = null;
  $p->fechaHoraHasta = null;

  // Otros
  $p->incluirDeshabilitados = false;
  $p->pageSize = 50;
  $p->pageIndex = 0;

  // OJO: también existen muchos más filtros (nombre, email, idsCliente, etc.)
  // pero no los enviamos si no hacen falta.

  $resp = casanova_giav_call('Cliente_SEARCH', $p);
  if (is_wp_error($resp)) return $resp;

  // Log temporal mientras ajustamos el parseo
  error_log('[CASANOVA SOAP] Cliente_SEARCH raw response: ' . print_r($resp, true));

  return $resp;
}

/**
 * Extraer idCliente de la respuesta (tolerante).
 * Ajustaremos si tu estructura concreta es distinta, pero esto cubre lo típico.
 */
function casanova_giav_extraer_idcliente($resp): ?string {
  if (!is_object($resp)) return null;

  // Caso REAL de tu API: Cliente_SEARCHResult->WsCliente->Id
  if (
    isset($resp->Cliente_SEARCHResult)
    && is_object($resp->Cliente_SEARCHResult)
    && isset($resp->Cliente_SEARCHResult->WsCliente)
    && is_object($resp->Cliente_SEARCHResult->WsCliente)
    && isset($resp->Cliente_SEARCHResult->WsCliente->Id)
  ) {
    return (string) $resp->Cliente_SEARCHResult->WsCliente->Id;
  }

  // Si en algún caso viniera un array/lista de WsCliente
  if (
    isset($resp->Cliente_SEARCHResult)
    && is_object($resp->Cliente_SEARCHResult)
    && isset($resp->Cliente_SEARCHResult->WsClientes)
  ) {
    $node = $resp->Cliente_SEARCHResult->WsClientes;
    if (is_array($node)) {
      $c0 = $node[0] ?? null;
      if (is_object($c0) && isset($c0->Id)) return (string) $c0->Id;
    }
    if (is_object($node) && isset($node->WsCliente)) {
      $c = $node->WsCliente;
      if (is_array($c)) {
        $c0 = $c[0] ?? null;
        if (is_object($c0) && isset($c0->Id)) return (string) $c0->Id;
      } elseif (is_object($c) && isset($c->Id)) {
        return (string) $c->Id;
      }
    }
  }

  // Fallbacks antiguos (por si el proveedor devuelve otra estructura)
  foreach (['Cliente_SEARCHResult', 'Result'] as $rk) {
    if (isset($resp->$rk) && is_object($resp->$rk)) {
      $r = $resp->$rk;

      if (isset($r->Clientes) && is_object($r->Clientes) && isset($r->Clientes->Cliente)) {
        $c = $r->Clientes->Cliente;
        if (is_array($c) && isset($c[0]->idCliente)) return (string)$c[0]->idCliente;
        if (is_object($c) && isset($c->idCliente)) return (string)$c->idCliente;
      }

      if (isset($r->Cliente) && is_object($r->Cliente) && isset($r->Cliente->idCliente)) {
        return (string)$r->Cliente->idCliente;
      }
    }
  }

  return null;
}

/**
 * BRICKS: Vincular cuenta (formId wkwkgw, campo DNI form-field-a1a1f7)
 */
add_action('bricks/form/custom_action', function($form) {

  $fields = $form->get_fields();

  // Solo tu formulario
  if (($fields['formId'] ?? '') !== 'wkwkgw') return;

  if (!is_user_logged_in()) {
    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => 'Debes iniciar sesión para vincular tu cuenta.',
    ]);
    return;
  }

  $dni_raw = $fields['form-field-a1a1f7'] ?? '';
  $dni = preg_replace('/\s+/', '', strtoupper(sanitize_text_field($dni_raw)));

  if ($dni === '') {
    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => 'Introduce tu DNI.',
    ]);
    return;
  }

  $user_id = get_current_user_id();
  update_user_meta($user_id, 'casanova_dni', $dni);

  $resp = casanova_giav_cliente_search_por_dni($dni);

  if (is_wp_error($resp)) {
    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => 'No hemos podido consultar el sistema. Inténtalo más tarde.',
    ]);
    return;
  }

  // Si el result viene vacío, no hay cliente
  $isEmptyResult =
    !isset($resp->Cliente_SEARCHResult)
    || !is_object($resp->Cliente_SEARCHResult)
    || (
      !isset($resp->Cliente_SEARCHResult->WsCliente)
      && count(get_object_vars($resp->Cliente_SEARCHResult)) === 0
    );

  if ($isEmptyResult) {
    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => 'No encontramos ningún cliente con ese DNI. Si ya has viajado con nosotros, escríbenos y lo revisamos.',
    ]);
    return;
  }

  // ✅EXTRAER idCliente REAL (tu API lo devuelve como WsCliente->Id)
  $idCliente = null;
  if (
    isset($resp->Cliente_SEARCHResult->WsCliente)
    && is_object($resp->Cliente_SEARCHResult->WsCliente)
    && isset($resp->Cliente_SEARCHResult->WsCliente->Id)
  ) {
    $idCliente = (string) $resp->Cliente_SEARCHResult->WsCliente->Id;
  }

  if (empty($idCliente)) {
    error_log('[CASANOVA] Cliente_SEARCH sin Id. Resp: ' . print_r($resp, true));
    $form->set_result([
      'action'  => 'casanova_vincular',
      'type'    => 'error',
      'message' => 'Hemos encontrado tu ficha, pero no hemos podido vincularla automáticamente. Contacta con nosotros.',
    ]);
    return;
  }

  // Guardar idCliente REAL
  update_user_meta($user_id, 'casanova_idcliente', $idCliente);

  $form->set_result([
    'action'          => 'casanova_vincular',
    'type'            => 'redirect',
    'redirectTo'      => $fields['referrer'] ?? (wp_get_referer() ?: (function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/'))),
    'redirectTimeout' => 0,
  ]);

}, 10, 1);