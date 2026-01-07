<?php
if (!defined('ABSPATH')) exit;

/**
 * Hooks: Redsys puede llamar SIN login
 */
add_action('admin_post_nopriv_casanova_tpv_notify', 'casanova_handle_tpv_notify');
add_action('admin_post_casanova_tpv_notify', 'casanova_handle_tpv_notify');

add_action('admin_post_nopriv_casanova_tpv_return', 'casanova_handle_tpv_return');
add_action('admin_post_casanova_tpv_return', 'casanova_handle_tpv_return');


/**
 * ==========================
 * Helpers Redsys (solo si NO existen)
 * ==========================
 */

if (!function_exists('casanova_redsys_decode_params')) {
  function casanova_redsys_decode_params(string $b64): array {
    // Redsys suele enviar MerchantParameters en Base64, pero a veces llega URL-safe (-_) o sin padding.
    // Para decodificar, normalizamos a Base64 estándar con padding. Para firmar, SIEMPRE se usa el string original.
    $b64 = trim($b64);
    $b64 = str_replace(' ', '+', $b64);
    $b64 = strtr($b64, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) $b64 .= str_repeat('=', 4 - $pad);

    $json = base64_decode($b64, true);
    if ($json === false) return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
  }
}

if (!function_exists('casanova_redsys_normalize_sig')) {
  function casanova_redsys_normalize_sig(string $sig): string {
    // Redsys puede enviar la firma en Base64 URL-safe (-_). Normalizamos a Base64 estándar (+/)
    // y corregimos espacios por '+' (por si el transporte los convierte).
    $sig = trim($sig);
    $sig = str_replace(' ', '+', $sig);
    $sig = strtr($sig, '-_', '+/');
    $pad = strlen($sig) % 4;
    if ($pad) $sig .= str_repeat('=', 4 - $pad);
    return $sig;
  }
}

if (!function_exists('casanova_redsys_get_secret')) {
  function casanova_redsys_get_secret(): string {
    if (function_exists('casanova_redsys_config')) {
      $cfg = casanova_redsys_config();
      if (!empty($cfg['secret_key'])) return (string)$cfg['secret_key'];
    }
    return '';
  }
}

if (!function_exists('casanova_redsys_verify')) {
  function casanova_redsys_verify(string $mpB64, array $params, string $sigProvided): bool {
    if (!function_exists('casanova_redsys_signature')) return false;

    // IMPORTANTE: La firma se deriva usando el Ds_Order que viene DENTRO de MerchantParameters.
    // No usar el order guardado en DB para verificar, porque Redsys puede variar el formato (rellenos/ceros, etc.).
    $decoded = casanova_redsys_decode_params($mpB64);
    $order = (string)($decoded['Ds_Order'] ?? $decoded['DS_ORDER'] ?? $params['Ds_Order'] ?? $params['DS_ORDER'] ?? '');
    $order = trim($order);
    if ($order === '') return false;

    $secret = casanova_redsys_get_secret();
    if ($secret === '') return false;

    $expected = casanova_redsys_signature($mpB64, $order, $secret);
    if ($expected === '') return false;

    // Comparamos en Base64 estándar y sin padding (Redsys a veces lo omite).
    $exp = rtrim(casanova_redsys_normalize_sig($expected), '=');
    $got = rtrim(casanova_redsys_normalize_sig($sigProvided), '=');
    return hash_equals($exp, $got);
  }
}

if (!function_exists('casanova_payload_merge')) {
  function casanova_payload_merge($old, array $add): array {
    $oldArr = is_array($old) ? $old : (json_decode((string)$old, true) ?: []);
    if (!is_array($oldArr)) $oldArr = [];
    return array_replace_recursive($oldArr, $add);
  }
}

/**
 * ==========================
 * RETURN (cliente vuelve)
 * ==========================
 */
function casanova_handle_tpv_return(): void {

  error_log('[CASANOVA][TPV][RETURN] reached method=' . ($_SERVER['REQUEST_METHOD'] ?? '?'));

  $mpB64 = isset($_REQUEST['Ds_MerchantParameters']) ? (string)wp_unslash($_REQUEST['Ds_MerchantParameters']) : '';
  $sig   = isset($_REQUEST['Ds_Signature']) ? (string)wp_unslash($_REQUEST['Ds_Signature']) : '';

  error_log('[CASANOVA][TPV][RETURN] mp_len=' . strlen($mpB64) . ' sig_len=' . strlen($sig));

  if ($mpB64 === '' || $sig === '') {
  // fallback: return por GET sin payload, usamos token en URL
  $token_qs = (string)($_REQUEST['token'] ?? '');
  error_log('[CASANOVA][TPV][RETURN] missing params fallback token=' . $token_qs);

  if ($token_qs !== '' && function_exists('casanova_payment_intent_get_by_token')) {
    $intent = casanova_payment_intent_get_by_token($token_qs);
    if ($intent) {
      casanova_payment_intent_update((int)$intent->id, [
        'status' => (($_REQUEST['result'] ?? '') === 'ok') ? 'returned_ok' : 'returned_ko',
        'payload' => casanova_payload_merge($intent->payload ?? [], [
          'redsys_return' => [
            'valid_signature' => false,
            'error' => 'missing_params_return_get',
            'result' => (string)($_REQUEST['result'] ?? ''),
            'request_keys' => [
              'get' => array_keys($_GET),
              'post' => array_keys($_POST),
            ],
          ],
          'time' => current_time('mysql'),
        ]),
      ]);

      // Agenda reconciliación (una vez)
if (!wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id])) {
  $when = time() + 15;
  wp_schedule_single_event($when, 'casanova_job_reconcile_payment', [(int)$intent->id]);
  if (function_exists('casanova_log')) {
    casanova_log('tpv', 'reconcile scheduled', ['when' => $when, 'intent_id' => (int)$intent->id], 'info');
  } else {
    error_log('[CASANOVA][TPV] reconcile scheduled when=' . $when . ' intent_id=' . (int)$intent->id);
  }
} else {
  $ts = wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id]);
  if (function_exists('casanova_log')) {
    casanova_log('tpv', 'reconcile already scheduled', ['at' => $ts, 'intent_id' => (int)$intent->id], 'debug');
  } else {
    error_log('[CASANOVA][TPV] reconcile already scheduled at=' . (string)$ts . ' intent_id=' . (int)$intent->id);
  }
}


      wp_safe_redirect(add_query_arg([
        'expediente' => (int)$intent->id_expediente,
        'pay_status' => 'checking',
      ], (function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/'))));
      exit;
    }
  }

  // si no hay token o no existe intent, KO genérico
  wp_safe_redirect(add_query_arg(['pay_status' => 'ko'], (function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/'))));
  exit;
}


  $params = casanova_redsys_decode_params($mpB64);
  $token  = (string)($params['Ds_MerchantData'] ?? '');

  error_log('[CASANOVA][TPV][RETURN] token=' . $token);

  if ($token === '' || !function_exists('casanova_payment_intent_get_by_token')) {
    wp_safe_redirect(add_query_arg(['pay_status' => 'ko'], (function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/'))));
    exit;
  }

  $intent = casanova_payment_intent_get_by_token($token);
  if (!$intent) {
    wp_safe_redirect(add_query_arg(['pay_status' => 'ko'], (function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/'))));
    exit;
  }

  $is_valid = casanova_redsys_verify($mpB64, $params, $sig);
  // Fallback: si falla la firma pero tenemos intent y order guardado, probamos con ese order.
  // Esto cubre variaciones raras de Ds_Order y/o decodificación (manteniendo el token como ancla de seguridad).
  if (!$is_valid && !empty($intent->order_redsys) && function_exists('casanova_redsys_signature')) {
    $secret = casanova_redsys_get_secret();
    if ($secret !== '') {
      $expected2 = casanova_redsys_signature($mpB64, trim((string)$intent->order_redsys), $secret);
      if ($expected2 !== '') {
        $exp2 = rtrim(casanova_redsys_normalize_sig($expected2), '=');
        $got2 = rtrim(casanova_redsys_normalize_sig($sig), '=');
        $is_valid = hash_equals($exp2, $got2);
        if ($is_valid) {
          error_log('[CASANOVA][TPV][RETURN] signature fallback matched using intent->order_redsys');
        }
      }
    }
  }
  $ds_resp  = (int)($params['Ds_Response'] ?? 9999);
  $ok = $is_valid && $ds_resp >= 0 && $ds_resp <= 99;

  error_log('[CASANOVA][TPV][RETURN] intent=' . $intent->id . ' valid_sig=' . ($is_valid ? '1' : '0') . ' ds=' . $ds_resp);

  casanova_payment_intent_update((int)$intent->id, [
    'status' => $ok ? 'returned_ok' : 'returned_ko',
    'payload' => casanova_payload_merge($intent->payload ?? [], [
      'redsys_return' => [
        'valid_signature' => $is_valid,
        'ds_response' => $ds_resp,
        'params' => $params,
      ],
      'time' => current_time('mysql'),
    ]),
  ]);

  if (!wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id])) {
    wp_schedule_single_event(time() + 15, 'casanova_job_reconcile_payment', [(int)$intent->id]);
  }

  wp_safe_redirect(add_query_arg([
    'expediente' => (int)$intent->id_expediente,
    'pay_status' => $ok ? 'checking' : 'ko',
  ], (function_exists('casanova_portal_base_url') ? casanova_portal_base_url() : home_url('/area-usuario/'))));
  exit;
}

/**
 * ==========================
 * NOTIFY (server to server)
 * ==========================
 */
function casanova_handle_tpv_notify(): void {

  error_log('[CASANOVA][TPV][NOTIFY] reached method=' . ($_SERVER['REQUEST_METHOD'] ?? '?'));

  $mpB64 = isset($_REQUEST['Ds_MerchantParameters']) ? (string)wp_unslash($_REQUEST['Ds_MerchantParameters']) : '';
  $sig   = isset($_REQUEST['Ds_Signature']) ? (string)wp_unslash($_REQUEST['Ds_Signature']) : '';
  $ver   = isset($_REQUEST['Ds_SignatureVersion']) ? (string)wp_unslash($_REQUEST['Ds_SignatureVersion']) : '';

  error_log('[CASANOVA][TPV][NOTIFY] mp_len=' . strlen($mpB64) . ' sig_len=' . strlen($sig) . ' ver=' . $ver);

  if ($mpB64 === '' || $sig === '') {
    status_header(400);
    echo 'Missing params';
    exit;
  }

  $params = casanova_redsys_decode_params($mpB64);
  $token  = (string)($params['Ds_MerchantData'] ?? '');

  error_log('[CASANOVA][TPV][NOTIFY] token=' . $token);

  if ($token === '' || !function_exists('casanova_payment_intent_get_by_token') || !function_exists('casanova_payment_intent_update')) {
    status_header(500);
    echo 'Payments module missing';
    exit;
  }

  $intent = casanova_payment_intent_get_by_token($token);
  if (!$intent) {
    status_header(404);
    echo 'Intent not found';
    exit;
  }

  // Idempotencia básica
  $curr_status = (string)($intent->status ?? '');
  if ($curr_status === 'reconciled' || $curr_status === 'failed') {
    status_header(200);
    echo 'OK';
    exit;
  }

  // Validación de firma usando el Ds_Order que viene dentro de MerchantParameters.
  $is_valid = casanova_redsys_verify($mpB64, $params, $sig);
  // Fallback: si falla la firma pero tenemos el order guardado del intent, probamos también con ese order.
  if (!$is_valid && !empty($intent->order_redsys) && function_exists('casanova_redsys_signature')) {
    $secret = casanova_redsys_get_secret();
    if ($secret !== '') {
      $expected2 = casanova_redsys_signature($mpB64, trim((string)$intent->order_redsys), $secret);
      if ($expected2 !== '') {
        $exp2 = rtrim(casanova_redsys_normalize_sig($expected2), '=');
        $got2 = rtrim(casanova_redsys_normalize_sig($sig), '=');
        $is_valid = hash_equals($exp2, $got2);
        if ($is_valid) {
          error_log('[CASANOVA][TPV][NOTIFY] signature fallback matched using intent->order_redsys');
        }
      }
    }
  }

  $ds_resp = (int)($params['Ds_Response'] ?? 9999);
  $bank_ok = ($ds_resp >= 0 && $ds_resp <= 99);

  error_log('[CASANOVA][TPV][NOTIFY] intent=' . (int)$intent->id . ' status=' . $curr_status .
    ' valid_sig=' . ($is_valid ? '1' : '0') . ' ds=' . $ds_resp
  );

  // Estado: separado para entender qué pasó
  $new_status = 'notified_bad_sig';
  if ($is_valid && $bank_ok) $new_status = 'notified_ok';
  elseif ($is_valid && !$bank_ok) $new_status = 'notified_ko';

  // ==============================================================
  // Puente Redsys -> GIAV: insertar Cobro_POST de forma idempotente
  // ==============================================================
  $extra_payload = [];
  if ($is_valid && $bank_ok) {
    $payload_arr = is_array($intent->payload ?? null)
      ? (array)$intent->payload
      : (json_decode((string)($intent->payload ?? ''), true) ?: []);
    if (!is_array($payload_arr)) $payload_arr = [];

    $already = isset($payload_arr['giav_cobro']) && is_array($payload_arr['giav_cobro'])
      && (!empty($payload_arr['giav_cobro']['cobro_id']) || !empty($payload_arr['giav_cobro']['inserted_at']));

    if ($already) {
      error_log('[CASANOVA][TPV][GIAV] cobro already inserted intent_id=' . (int)$intent->id);
    } else {
      $id_forma_pago = 0;
      if (defined('CASANOVA_GIAV_IDFORMAPAGO_REDSYS')) {
        $id_forma_pago = (int)CASANOVA_GIAV_IDFORMAPAGO_REDSYS;
      }
      if ($id_forma_pago <= 0) {
        $id_forma_pago = (int) get_option('casanova_giav_idformapago_redsys', 1027);
      }

      // Oficina: algunos GIAV requieren idOficina para permitir Cobro_POST (si no, 'No se tiene acceso al registro').
      $id_oficina = 0;
      if (defined('CASANOVA_GIAV_IDOFICINA')) {
        $id_oficina = (int)CASANOVA_GIAV_IDOFICINA;
      }
      $id_oficina = (int) apply_filters('casanova_giav_idoficina_for_cobro', $id_oficina, $intent, $params);

      // Notas internas: guardamos huella Redsys sin liarla demasiado
      $notes = [
        'source' => 'casanova-portal-giav',
        'token' => (string)$intent->token,
        'order' => (string)($params['Ds_Order'] ?? ''),
        'auth_code' => (string)($params['Ds_AuthorisationCode'] ?? ''),
        'merchant_identifier' => (string)($params['Ds_Merchant_Identifier'] ?? ''),
        'card_country' => (string)($params['Ds_Card_Country'] ?? ''),
        'response' => (string)$ds_resp,
      ];

      $giav_params = [
        'idFormaPago' => $id_forma_pago,
        'idOficina' => ($id_oficina > 0 ? (int)$id_oficina : null),
        'idExpediente' => (int)$intent->id_expediente,
        'idCliente' => (int)$intent->id_cliente,
        'idRelacionPasajeroReserva' => null,
        'idTipoOperacion' => 'Cobro',
        'importe' => (double)$intent->amount,
        'fechaCobro' => current_time('Y-m-d'),
        'concepto' => 'Pago Redsys ' . (string)($intent->order_redsys ?? ''),
        'documento' => (string)($params['Ds_AuthorisationCode'] ?? ($params['Ds_Merchant_Identifier'] ?? '')),
        'pagador' => (string)($intent->user_id ? ('WP user ' . (int)$intent->user_id) : 'Portal'),
        'notasInternas' => wp_json_encode($notes),
        'autocompensar' => true,
        'idEntityStage' => null,
        // customDataValues OMITIDO: Key debe ser int y no sabemos los IDs en tu GIAV.
      ];
      // Si no tenemos oficina, mejor omitir el campo (algunos GIAV interpretan xsi:nil como 'no autorizado').
      if ($id_oficina <= 0) {
        unset($giav_params['idOficina']);
      }
      error_log('[CASANOVA][TPV][GIAV] Cobro_POST params idFormaPago=' . (int)$id_forma_pago . ' idOficina=' . (int)$id_oficina);


      if (!function_exists('casanova_giav_call')) {
        error_log('[CASANOVA][TPV][GIAV] casanova_giav_call missing');
        $extra_payload['giav_cobro'] = [
          'attempted_at' => current_time('mysql'),
          'ok' => false,
          'error' => 'casanova_giav_call_missing',
        ];
      } else {
        error_log('[CASANOVA][TPV][GIAV] Cobro_POST attempt intent_id=' . (int)$intent->id . ' exp=' . (int)$intent->id_expediente . ' cliente=' . (int)$intent->id_cliente . ' importe=' . (string)$intent->amount);
        $res = casanova_giav_call('Cobro_POST', $giav_params);

        if (is_wp_error($res)) {
          error_log('[CASANOVA][TPV][GIAV] Cobro_POST ERROR: ' . $res->get_error_message());
          $extra_payload['giav_cobro'] = [
            'attempted_at' => current_time('mysql'),
            'ok' => false,
            'error' => $res->get_error_message(),
          ];
        } else {
          // SoapClient suele devolver objeto con propiedad Cobro_POSTResult
          $cobro_id = 0;
          if (is_object($res) && isset($res->Cobro_POSTResult)) {
            $cobro_id = (int)$res->Cobro_POSTResult;
          } elseif (is_numeric($res)) {
            $cobro_id = (int)$res;
          }

          if ($cobro_id > 0) {
            error_log('[CASANOVA][TPV][GIAV] Cobro_POST OK cobro_id=' . $cobro_id . ' intent_id=' . (int)$intent->id);
            $extra_payload['giav_cobro'] = [
              'attempted_at' => current_time('mysql'),
              'inserted_at' => current_time('mysql'),
              'ok' => true,
              'cobro_id' => $cobro_id,
            ];
          } else {
            // Respuesta rara: lo registramos para debug, pero no marcamos como insertado.
            error_log('[CASANOVA][TPV][GIAV] Cobro_POST unexpected response intent_id=' . (int)$intent->id . ' res=' . print_r($res, true));
            $extra_payload['giav_cobro'] = [
              'attempted_at' => current_time('mysql'),
              'ok' => false,
              'error' => 'unexpected_response',
              'raw' => is_scalar($res) ? (string)$res : null,
            ];
          }
        }
      }
    }
  }

  $merge_payload = [
    'redsys_notify' => [
      'valid_signature' => $is_valid,
      'ds_response' => $ds_resp,
      'bank_ok' => $bank_ok,
      'params' => $params,
    ],
    'time' => current_time('mysql'),
  ];

  // Si intentamos (o fallamos) el insert de GIAV, lo registramos.
  // Ojo: solo marca inserted_at/cobro_id cuando realmente devuelve un id.
  // Si falla, NO marcamos y el siguiente NOTIFY podrá reintentar.
  if (isset($extra_payload['giav_cobro'])) {
    $merge_payload['giav_cobro'] = $extra_payload['giav_cobro'];
  }

  casanova_payment_intent_update((int)$intent->id, [
    'status' => $new_status,
    'payload' => casanova_payload_merge($intent->payload ?? [], $merge_payload),
    'last_check_at' => current_time('mysql'),
  ]);

  // Si se ha registrado cobro en GIAV, disparar email de confirmación aunque sea pago parcial.
  if (!empty($merge_payload['giav_cobro']) && !empty($merge_payload['giav_cobro']['ok']) && !empty($merge_payload['giav_cobro']['cobro_id'])) {
    do_action('casanova_payment_cobro_recorded', (int)$intent->id);
  }

  // Solo agenda reconciliación si tiene sentido (firma válida + banco OK)
  if ($is_valid && $bank_ok) {
    if (!wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id])) {
      $when = time() + 15;
      wp_schedule_single_event($when, 'casanova_job_reconcile_payment', [(int)$intent->id]);
      error_log('[CASANOVA][TPV] reconcile scheduled when=' . $when . ' intent_id=' . (int)$intent->id);
    } else {
      $ts = wp_next_scheduled('casanova_job_reconcile_payment', [(int)$intent->id]);
      error_log('[CASANOVA][TPV] reconcile already scheduled at=' . (string)$ts . ' intent_id=' . (int)$intent->id);
    }

    // DEBUG opcional: ejecutar reconciliación inline SOLO si lo pides por query (?recon_now=1)
    // (Esto evita dobles efectos en producción)
    $run_inline = !empty($_GET['recon_now']) && current_user_can('manage_options');
    if ($run_inline && function_exists('casanova_job_reconcile_payment')) {
      error_log('[CASANOVA][TPV] running reconcile inline intent_id=' . (int)$intent->id);
      casanova_job_reconcile_payment((int)$intent->id);
      error_log('[CASANOVA][TPV] reconcile inline finished intent_id=' . (int)$intent->id);
    }
  } else {
    error_log('[CASANOVA][TPV] reconcile NOT scheduled (valid_sig=' . ($is_valid ? '1' : '0') . ' bank_ok=' . ($bank_ok ? '1' : '0') . ') intent_id=' . (int)$intent->id);
  }

  status_header(200);
  echo 'OK';
  exit;
}

add_action('rest_api_init', function () {
  register_rest_route('casanova/v1', '/redsys/notify', [
    'methods'  => 'POST',
    'callback' => function ($request) {
      // simula el mismo handler
      casanova_handle_tpv_notify();
      return new WP_REST_Response(['ok' => true], 200);
    },
    'permission_callback' => '__return_true',
  ]);
});