<?php
if (!defined('ABSPATH')) exit;

/**
 * ============================
 * Portal: Mis datos (GIAV)
 * ============================
 *
 * Lógica:
 * - Leemos SIEMPRE de GIAV (fuente de verdad).
 * - Para no freír el SOAP, cacheamos en transient 5 min por usuario/cliente.
 * - Solo permitimos editar dirección postal (direccion, codPostal, poblacion, provincia, pais).
 * - DNI se muestra truncado (protección de datos).
 */

function casanova_portal_trunc_dni(string $dni): string {
  $dni = trim($dni);
  if ($dni === '') return '';
  $len = strlen($dni);
  if ($len <= 4) return str_repeat('•', $len);
  return substr($dni, 0, 2) . str_repeat('•', max(0, $len - 4)) . substr($dni, -2);
}

function casanova_giav_cliente_get_by_id(int $idCliente) {
  $id = (int)$idCliente;
  if ($id <= 0) return new WP_Error('bad_id', __('ID de cliente inválido.', 'casanova-portal'));

  $p = new stdClass();
  $p->apikey = defined('CASANOVA_GIAV_APIKEY') ? CASANOVA_GIAV_APIKEY : '';
  // IMPORTANTE: Cliente_GET exige la propiedad "id" (no idsCliente / idCliente)
  $p->id = $id;

  $resp = casanova_giav_call('Cliente_GET', $p);
  if (is_wp_error($resp)) return $resp;

  // Respuesta típica: Cliente_GETResult (WsCliente)
  $c = $resp->Cliente_GETResult ?? null;

  // Fallbacks defensivos (según configuraciones / serializadores)
  if (!$c && isset($resp->WsCliente)) $c = $resp->WsCliente;
  if (!$c && isset($resp->Cliente_GETResponse) && is_object($resp->Cliente_GETResponse) && isset($resp->Cliente_GETResponse->Cliente_GETResult)) {
    $c = $resp->Cliente_GETResponse->Cliente_GETResult;
  }

  if (!$c || !is_object($c)) {
    error_log('[CASANOVA SOAP] Cliente_GET sin Cliente_GETResult. Resp: ' . print_r($resp, true));
    return new WP_Error('giav_empty', __('No se han podido cargar los datos del cliente.', 'casanova-portal'));
  }

  return $c;
}

/**
 * Intento de update en GIAV.
 * Nota: el WSDL suele exponer un Cliente_PUT para actualización. Si tu GIAV usa otro nombre,
 * solo hay que cambiar el método aquí.
 */
function casanova_giav_cliente_update_direccion(int $idCliente, array $addr): bool|WP_Error {
  $c = casanova_giav_cliente_get_by_id($idCliente);
  if (is_wp_error($c)) return $c;

  // Respuesta típica: Cliente_GETResult (SOAP 1.2) o WsCliente (HTTP POST)
  $ws = null;
  if (is_object($c) && isset($c->Cliente_GETResult) && is_object($c->Cliente_GETResult)) {
    $ws = $c->Cliente_GETResult;
  } elseif (is_object($c) && isset($c->WsCliente) && is_object($c->WsCliente)) {
    $ws = $c->WsCliente;
  } elseif (is_object($c)) {
    $ws = $c; // fallback
  }

  if (!$ws || !is_object($ws)) {
    return new WP_Error('giav_cliente_get_shape', __('Respuesta Cliente_GET inesperada.', 'casanova-portal'));
  }

  // Campos nuevos (si llegan vacíos, mantenemos los del cliente)
  $newAddr    = sanitize_text_field($addr['direccion'] ?? '');
  $newCP      = sanitize_text_field($addr['codPostal'] ?? '');
  $newCity    = sanitize_text_field($addr['poblacion'] ?? '');
  $newProv    = sanitize_text_field($addr['provincia'] ?? '');
  // Ojo: NO usamos país en PUT porque en el WSDL que pegaste NO existe "pais" como parámetro

  // TipoCliente obligatorio
  $tipo = (string)($ws->Tipo ?? $ws->tipoCliente ?? '');
  if ($tipo === '') $tipo = 'Particular';

  // CodCC / cuenta contable: GIAV suele exigir 10 dígitos.
  // IMPORTANTE: NO inventar valores si vienen informados en GET. Primero usamos CodCc.
  // Si CodCc está vacío, probamos con Codigo (a veces el "código cliente" está ahí).
  $raw_codcc = (string)($ws->CodCc ?? $ws->CodCC ?? '');
  $raw_codigo = (string)($ws->Codigo ?? '');

  $digits_codcc = preg_replace('/\\D+/', '', $raw_codcc);
  $digits_codigo = preg_replace('/\\D+/', '', $raw_codigo);

  $digits = $digits_codcc !== '' ? $digits_codcc : $digits_codigo;
  if ($digits === '') {
    // Último recurso: derivar del idCliente para no mandar vacío. Esto puede fallar si GIAV
    // exige un código contable real, pero al menos el error será explícito y logueable.
    $digits = (string)$idCliente;
  }

  $codCC10 = substr(str_pad($digits, 10, '0', STR_PAD_LEFT), -10);

  $p = new stdClass();
  $p->apikey = defined('CASANOVA_GIAV_APIKEY') ? CASANOVA_GIAV_APIKEY : '';
  if ($p->apikey === '') return new WP_Error('missing_apikey', __('Falta CASANOVA_GIAV_APIKEY.', 'casanova-portal'));

  // Obligatorios
  $p->idCliente = (int)$idCliente;
  $p->tipoCliente = $tipo;

  // Opcionales (pero clonamos del GET)
  $p->documento = (string)($ws->Documento ?? '');
  $p->pasaporteNumero = (string)($ws->PasaporteNumero ?? '');
  $p->cuentaContableCliente = $codCC10;         // <- suele ser lo que dispara el “10 dígitos”
  $p->cuentaContableVentas = (string)($ws->CuentaContableVentas ?? '');
  $p->email = (string)($ws->Email ?? '');
  $p->apellidos = (string)($ws->Apellidos ?? '');
  $p->nombre = (string)($ws->Nombre ?? '');
  $p->nombreAlias = (string)($ws->NombreAlias ?? '');

  // Dirección editable
  $p->direccion = $newAddr !== '' ? $newAddr : (string)($ws->Direccion ?? '');
  $p->codPostal = $newCP !== '' ? $newCP : (string)($ws->CodPostal ?? '');
  $p->poblacion = $newCity !== '' ? $newCity : (string)($ws->Poblacion ?? '');
  $p->provincia = $newProv !== '' ? $newProv : (string)($ws->Provincia ?? '');

  $p->telefono = (string)($ws->Telefono ?? '');
  $p->fax = (string)($ws->Fax ?? '');
  $p->codCC = $codCC10;

  // Obligatorios (minOccurs=1 en tu WSDL)
  $p->creditoImporte = (double)($ws->CreditoImporte ?? 0);
  $p->comentarios = (string)($ws->Comentarios ?? '');
  $p->traspasaDepartamentos = (bool)($ws->TraspasaDepartamentos ?? false);
  $p->movil = (string)($ws->Movil ?? '');
  $p->factTotalizadora = (bool)($ws->FactTotalizadora ?? false);
  $p->deshabilitado = (bool)($ws->Deshabilitado ?? false);
  $p->empresa_Facturar_Reg_General = (bool)($ws->EmpresaFacturarRegGeneral ?? false);
  $p->sedeFiscal = (string)($ws->SedeFiscal ?? '');

  // Nillables obligatorios como propiedad
  $p->idTaxDistrict = null;
  $p->comisionesIVAIncluido = (bool)($ws->ComisionesIvaIncluido ?? false);
  $p->comisionesComisionDefecto = isset($ws->ComisionesComisionDefecto) ? (double)$ws->ComisionesComisionDefecto : null;
  $p->excluir_347_349 = (bool)($ws->Excluir347349 ?? false);
  $p->idAgenteComercial = isset($ws->idAgenteComercial) ? (int)$ws->idAgenteComercial : null;
  $p->validaAeat = false;
  $p->idEntityStage = isset($ws->idEntityStage) ? (int)$ws->idEntityStage : null;
  $p->idsCategories = null; // opcional
  $p->idPaymentTerm = isset($ws->idPaymentTerm) ? (int)$ws->idPaymentTerm : null;

  $p->validaROI = false;
  $p->inscritoROI = (bool)($ws->inscritoROI ?? false);
  $p->idSepatipo = (string)($ws->idSepaTipo ?? $ws->idSepatipo ?? 'Puntual');
  $p->idSepaEstado = (string)($ws->idSepaEstado ?? esc_html__('Pendiente', 'casanova-portal'));
  $p->sepaReferencia = (string)($ws->SepaReferencia ?? '');
  $p->sepaFecha = null;

  $p->alertMsg = (string)($ws->AlertMsg ?? '');
  $p->mailingConsent = (string)($ws->MailingConsent ?? 'Pending');
  $p->rgpdSigned = (bool)($ws->RGPDSigned ?? false);

  // Arrays opcionales
  $p->contacts = null;
  $p->relevantDates = null;
  $p->rrsss = null;

  // Customer portal flags obligatorios
  $p->customerPortal_Enabled = (bool)($ws->CustomerPortal_Enabled ?? false);
  $p->customerPortal_Email = (string)($ws->CustomerPortal_Email ?? '');
  $p->customerPortal_Password = (string)($ws->CustomerPortal_Password ?? '');
  $p->customerPortal_DefaultVendorId = isset($ws->CustomerPortal_DefaultVendorId) ? (int)$ws->CustomerPortal_DefaultVendorId : null;
  $p->customerPortal_Zone_TravelFiles = (bool)($ws->CustomerPortal_Zone_TravelFiles ?? true);
  $p->customerPortal_Zone_Invoicing = (bool)($ws->CustomerPortal_Zone_Invoicing ?? true);
  $p->customerPortal_Zone_Payments = (bool)($ws->CustomerPortal_Zone_Payments ?? true);
  $p->customerPortal_Zone_Contact = (bool)($ws->CustomerPortal_Zone_Contact ?? true);

  // Factura-e obligatorios
  $p->facturaETipoPersona = (string)($ws->FacturaeTipoPersona ?? '');
  $p->facturaETipoResidencia = (string)($ws->FacturaeTipoResidencia ?? '');
  $p->facturaECodPais = (string)($ws->FacturaeCodPais ?? 'ESP');
  if ($p->facturaECodPais === '') $p->facturaECodPais = 'ESP';
  $p->facturaEAcepta = (bool)($ws->FacturaeAcepta ?? false);

  // DIR3 opcionales
  $p->facturaeDir3OficinaContable = (string)($ws->FacturaeDir3OficinaContable ?? '');
  $p->facturaeDir3OrganoGestor = (string)($ws->FacturaeDir3OrganoGestor ?? '');
  $p->facturaeDir3UnidadTramitadora = (string)($ws->FacturaeDir3UnidadTramitadora ?? '');
  $p->facturaeDir3OrganoProponente = (string)($ws->FacturaeDir3OrganoProponente ?? '');
  $p->facturaeDir3OcDesc = (string)($ws->FacturaeDir3OcDesc ?? '');
  $p->facturaeDir3OgDesc = (string)($ws->FacturaeDir3OgDesc ?? '');
  $p->facturaeDir3UtDesc = (string)($ws->FacturaeDir3UtDesc ?? '');
  $p->facturaeDir3OpDesc = (string)($ws->FacturaeDir3OpDesc ?? '');

  // Resto opcional
  $p->printOptions = null;
  $p->customDataValues = null;
  $p->CustomDataCustomerSettings = null;

  $resp = casanova_giav_call('Cliente_PUT', $p);
  if (is_wp_error($resp)) {
    error_log('[CASANOVA SOAP] Cliente_PUT fallo. Payload: ' . print_r($p, true));
    return $resp;
  }

  // Invalidate cache (si lo usas)
  delete_transient('casanova_profile_' . get_current_user_id());

  return true;
}

/**
 * Handler: Guardar dirección (admin-post)
 */
function casanova_portal_handle_update_address(): void {
  if (!is_user_logged_in()) {
    wp_safe_redirect(wp_login_url());
    exit;
  }

  $user_id = (int)get_current_user_id();
  $idCliente = (int)get_user_meta($user_id, 'casanova_idcliente', true);

  // Redirigimos al referer por defecto
  $redirect = wp_get_referer();
  if (!$redirect) $redirect = home_url('/');

  if ($idCliente <= 0) {
    wp_safe_redirect(add_query_arg(['casanova_profile'=>'error','casanova_notice'=>'address_error'], $redirect));
    exit;
  }

  $nonce = isset($_POST['_wpnonce']) ? (string)$_POST['_wpnonce'] : '';
  if (!wp_verify_nonce($nonce, 'casanova_update_address_' . $idCliente)) {
    wp_safe_redirect(add_query_arg(['casanova_profile'=>'error','casanova_notice'=>'address_error'], $redirect));
    exit;
  }

  // Sanitizado defensivo: jamás asumir que vienen.
  $addr = [
    'direccion'  => sanitize_text_field($_POST['direccion'] ?? ''),
    'codPostal'  => sanitize_text_field($_POST['codPostal'] ?? ''),
    'poblacion'  => sanitize_text_field($_POST['poblacion'] ?? ''),
    'provincia'  => sanitize_text_field($_POST['provincia'] ?? ''),
    'pais'       => sanitize_text_field($_POST['pais'] ?? ''),
  ];

  $r = casanova_giav_cliente_update_direccion($idCliente, $addr);
  if (is_wp_error($r)) {
    error_log('[CASANOVA] Update address error: ' . $r->get_error_code() . ' ' . $r->get_error_message());
    wp_safe_redirect(add_query_arg(['casanova_profile'=>'error','casanova_notice'=>'address_error'], $redirect));
    exit;
  }

  wp_safe_redirect(add_query_arg(['casanova_profile'=>'saved','casanova_notice'=>'address_saved'], $redirect));
  exit;
}

add_action('admin_post_casanova_update_address', 'casanova_portal_handle_update_address');
add_action('admin_post_nopriv_casanova_update_address', 'casanova_portal_handle_update_address');

add_shortcode('casanova_mis_datos', function () {

  if (!is_user_logged_in()) return '<p>' . esc_html__('Debes iniciar sesión.', 'casanova-portal') . '</p>';

  $user_id   = (int) get_current_user_id();
  $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
  if ($idCliente <= 0) return '<p>' . esc_html__('Tu cuenta no está vinculada todavía.', 'casanova-portal') . '</p>';

  $c = casanova_giav_cliente_get_by_id($idCliente);
  if (is_wp_error($c)) return '<p>' . esc_html__('No se han podido cargar tus datos. Inténtalo más tarde.', 'casanova-portal') . '</p>';

  $status = isset($_GET['casanova_profile']) ? (string)$_GET['casanova_profile'] : '';

  $dni_raw = (string)($c->Documento ?? $c->PasaporteNumero ?? '');
  $dni = casanova_portal_trunc_dni($dni_raw);

  $nombre = trim((string)($c->Nombre ?? ''));
  $apellidos = trim((string)($c->Apellidos ?? ''));
  $email = trim((string)($c->Email ?? ''));
  $telefono = trim((string)($c->Telefono ?? $c->Tel ?? ''));

  $direccion = (string)($c->Direccion ?? '');
  $codPostal = (string)($c->CodPostal ?? $c->CP ?? '');
  $poblacion = (string)($c->Poblacion ?? '');
  $provincia = (string)($c->Provincia ?? '');
  $pais      = (string)($c->Pais ?? '');

  $action = admin_url('admin-post.php');

  ob_start();

  echo '<div class="casanova-portal">';
  echo '<section class="casanova-card">';
    echo '<div class="casanova-section-title">' . esc_html__('Mis datos', 'casanova-portal') . '</div>';

    if ($status === 'saved') {
      echo '<div class="casanova-alert casanova-alert--ok"><strong>' . esc_html__('Dirección actualizada.', 'casanova-portal') . '</strong></div>';
    } elseif ($status === 'error') {
      echo '<div class="casanova-alert casanova-alert--warn"><strong>' . esc_html__('No se pudo actualizar la dirección.', 'casanova-portal') . '</strong> ' . esc_html__('Si persiste, lo revisamos.', 'casanova-portal') . '</div>';
    }

    echo '<div class="casanova-kv" style="margin-bottom:12px;">';
      if ($nombre !== '' || $apellidos !== '') echo '<div><strong>' . esc_html__('Nombre', 'casanova-portal') . ':</strong> ' . esc_html(trim($nombre . ' ' . $apellidos)) . '</div>';
      if ($email !== '') echo '<div><strong>' . esc_html__('Email', 'casanova-portal') . ':</strong> ' . esc_html($email) . '</div>';
      if ($telefono !== '') echo '<div><strong>' . esc_html__('Teléfono', 'casanova-portal') . ':</strong> ' . esc_html($telefono) . '</div>';
      if ($dni !== '') echo '<div><strong>' . esc_html__('DNI', 'casanova-portal') . ':</strong> ' . esc_html($dni) . '</div>';
    echo '</div>';

    echo '<div class="casanova-divider"></div>';
    echo '<div class="casanova-subtitle">' . esc_html__('Dirección de facturación', 'casanova-portal') . '</div>';

      echo '<form method="post" action="' . esc_url($action) . '" class="casanova-profile-form">';
  echo '<input type="hidden" name="action" value="casanova_update_address">';
  echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('casanova_update_address_' . $idCliente)) . '">';

  echo '<div class="casanova-kv" style="max-width:560px;">';

    echo '<label><strong>' . esc_html__('Dirección', 'casanova-portal') . '</strong><br>';
      echo '<input type="text" name="direccion" value="' . esc_attr($direccion) . '" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;">';
    echo '</label>';

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
      echo '<label><strong>' . esc_html__('CP', 'casanova-portal') . '</strong><br>';
        echo '<input type="text" name="codPostal" value="' . esc_attr($codPostal) . '" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;">';
      echo '</label>';

      echo '<label><strong>' . esc_html__('Población', 'casanova-portal') . '</strong><br>';
        echo '<input type="text" name="poblacion" value="' . esc_attr($poblacion) . '" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;">';
      echo '</label>';
    echo '</div>';

    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
      echo '<label><strong>' . esc_html__('Provincia', 'casanova-portal') . '</strong><br>';
        echo '<input type="text" name="provincia" value="' . esc_attr($provincia) . '" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;">';
      echo '</label>';

      echo '<label><strong>' . esc_html__('País', 'casanova-portal') . '</strong><br>';
        echo '<input type="text" name="pais" value="' . esc_attr($pais) . '" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;">';
      echo '</label>';
    echo '</div>';

  echo '</div>';

  echo '<div style="margin-top:12px;">';
    echo '<button type="submit" class="casanova-btn-submit"><span class="label">' . esc_html__('Guardar dirección', 'casanova-portal') . '</span><span class="spinner" aria-hidden="true"></span></button>';
  echo '</div>';

  echo '<div class="casanova-note">' . esc_html__('Por seguridad, el DNI y el email no se pueden modificar desde aquí.', 'casanova-portal') . '</div>';

echo '</form>';

  echo '</section>';
  echo '</div>';

  return ob_get_clean();
});