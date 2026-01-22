<?php
/**
 * JS i18n dictionary.
 *
 * WPML does not translate bundled JS automatically. We expose a translated
 * dictionary via wp_localize_script so React can use window.CASANOVA_I18N.
 *
 * Strategy:
 * - We keep a small set of explicit keys (stable API).
 * - For the rest of UI strings, we use hash-based keys (djb2 32-bit) so we can
 *   translate *all* visible literals without manually inventing keys.
 *   React calls tt("literal") -> key s_<hexhash> -> translated value here.
 */

if (!defined('ABSPATH')) exit;

function casanova_portal_i18n_hash_key(string $literal): string {
  // djb2 32-bit (must match App.jsx)
  $h = 5381;
  $len = strlen($literal);
  for ($i = 0; $i < $len; $i++) {
    $h = (($h << 5) + $h) + ord($literal[$i]);
    $h = $h & 0xFFFFFFFF;
  }
  // unsigned hex
  if ($h < 0) $h = $h + 0x100000000;
  return 's_' . dechex($h);
}

function casanova_portal_js_literals(): array {
  return [
    'Abrir en Google Maps',
    'Acciones',
    'Actualizando…',
    'Actualizar',
    'Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.',
    'Añadir al calendario',
    'Año:',
    'Aún no hay cobros registrados en este viaje.',
    'Aún no hay movimientos',
    'Aún no hay pagos asociados a este viaje.',
    'Aún no hay pagos disponibles para este viaje.',
    'Aún no tienes un próximo viaje',
    'Bono',
    'Bonos',
    'Bonos disponibles',
    'Cambiar contraseña',
    'Cargando expediente',
    'Cargando mensajes',
    'Casanova Portal',
    'Concepto',
    'Contraseña actualizada.',
    'Conversación sobre este viaje',
    'Cuando confirmes una reserva, la verás aquí con todos sus detalles.',
    'Cuando se registren pagos o se aplique un bonus, aparecerán aquí.',
    'Código:',
    'Cómo funciona',
    'Dejar opinión',
    'Descargar PDF',
    'Descargas asociadas a este viaje',
    'Detalle',
    'Dirección',
    'En cada reserva podrás ver el bono y descargar el PDF.',
    'English',
    'Error al cargar los viajes',
    'Español',
    'Estado',
    'Estado de pagos del viaje',
    'Esto solo afecta al portal.',
    'Expediente',
    'Factura',
    'Facturas',
    'Fecha',
    'Fechas:',
    'Fin',
    'Gestión de Reservas',
    'Gracias, procesamos el cobro y actualizamos tus datos.',
    'Guardar',
    'Histórico',
    'Histórico de cobros',
    'Importe',
    'Información personal',
    'Inicio',
    'Ir al detalle',
    'Localizador:',
    'Mensajes',
    'Modo prueba',
    'Movimientos recientes (ganados, bonus y canjes).',
    'No hay datos de pagos disponibles por el momento.',
    'No hay facturas disponibles.',
    'No hay mensajes disponibles',
    'No hay mensajes nuevos',
    'No hay servicios disponibles ahora mismo.',
    'No hay viajes disponibles para el año seleccionado.',
    'No podemos cargar tu perfil',
    'No se pudo actualizar la contraseña.',
    'No se pudo guardar el perfil.',
    'No se puede cargar el expediente ahora mismo.',
    'No se puede iniciar el pago',
    'No se pueden cargar los mensajes',
    'PDF',
    'PVP:',
    'Pagado',
    'Pagador',
    'Pagar',
    'Pagos',
    'Paquete',
    'Para:',
    'Pendiente',
    'Perfil actualizado.',
    'Programa del viaje (PDF)',
    'Puntos y nivel se actualizan automáticamente con tus reservas.',
    'Resumen',
    'Segmentos:',
    'Servicios incluidos',
    'Servicios y planificación del viaje',
    'Si necesitas algo, escríbenos desde Mensajes.',
    'Si te escribimos, lo verás aquí al momento.',
    'Siguiente paso',
    'Soporte',
    'Texto adicional (bono):',
    'Tiempo',
    'Tipo',
    'Tipo:',
    'Total',
    'Total del viaje',
    'Tu contraseña es tu llave digital. No la compartas, aunque a los humanos les encante hacerlo.',
    'Tu programa Mulligans',
    'Tu viaje',
    'Tus Mulligans',
    'Tus viajes',
    'Ver bono',
    'Ver detalle',
    'Ver mapa',
    'Ver movimientos',
    'Ver pagos',
    'Ver ruta',
    'Viaje',
    'Viajes &gt;',
    'Vista en construcción.',
    'Vouchers y documentación',
    '← Viajes',

    'Disponibles',
    'No disponibles',
    'Sin datos',
    'Pagado:',
    'Expediente #{id}',
    'ID {id}',
    'Expediente {id}',
    'Ver ruta en Google Maps',
    'Ver mapa en Google Maps',
    'Redirigiendo…',
    'Pagar depósito ({amount})',
    'Pagar pendiente ({amount})',
    'Tu próximo viaje',
    'Tu último viaje',
    'Finalizado',
    'Todo listo',
    'Todo pagado',
    'Info',
  ];
}

function casanova_portal_get_js_i18n(): array {
  $out = [
    // Generic (explicit keys)
    'close' => __('Cerrar', 'casanova-portal'),
    'account_label' => __('Tu cuenta', 'casanova-portal'),

    // User menu (explicit keys)
    'menu_profile' => __('Mi perfil', 'casanova-portal'),
    'menu_security' => __('Seguridad', 'casanova-portal'),
    'menu_logout' => __('Cerrar sesión', 'casanova-portal'),

    // Language (explicit keys)
    'portal_language' => __('Idioma del portal', 'casanova-portal'),
    'language_updated' => __('Idioma actualizado.', 'casanova-portal'),
    'language_update_failed' => __('No se pudo actualizar el idioma.', 'casanova-portal'),

    // Nav / titles (explicit keys)
    'nav_dashboard' => __('Dashboard', 'casanova-portal'),
    'nav_trips' => __('Viajes', 'casanova-portal'),
    'nav_trip_detail' => __('Detalle del viaje', 'casanova-portal'),
    'nav_messages' => __('Mensajes', 'casanova-portal'),
    'nav_mulligans' => __('Mulligans', 'casanova-portal'),
    'nav_portal' => __('Portal', 'casanova-portal'),
    'mock_mode' => __('Modo prueba', 'casanova-portal'),

    // Microcopy (explicit keys)
    'view_details' => __('Ver detalles', 'casanova-portal'),

    // Payments banner (explicit keys)
    'payment_registered_title' => __('Pago registrado', 'casanova-portal'),
  ];

  foreach (casanova_portal_js_literals() as $s) {
    $out[casanova_portal_i18n_hash_key($s)] = __($s, 'casanova-portal');
  }

  return $out;
}
