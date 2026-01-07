<?php
if (!defined('ABSPATH')) exit;

function casanova_map_wsreserva($r): array {
  $id          = (int)($r->Id ?? 0);
  $codigo      = (string)($r->Codigo ?? '');
  $tipo        = (string)($r->TipoReserva ?? 'Otros');
  $subtipo     = (string)($r->SubtipoOtros ?? '');
  $desc        = (string)($r->Descripcion ?? '');
  $destino     = (string)($r->Destino ?? '');
  $localizador = (string)($r->Localizador ?? '');

  $fecha_desde = $r->FechaDesde ?? null;
  $fecha_hasta = $r->FechaHasta ?? null;

  $fecha_limite  = $r->FechaLimite ?? null;
  $fecha_prepago = $r->FechaPrepago ?? null;

  $pax   = (int)($r->NumPax ?? 0);
  $ad    = (int)($r->NumAdultos ?? 0);
  $nin   = (int)($r->NumNinos ?? 0);

  $regimen = (string)($r->Regimen ?? '');

  $venta     = (float)($r->Venta ?? 0);
  $pendiente = (float)($r->Pendiente ?? 0);

  $facturado = (bool)($r->Facturado ?? false);
  $facturado_importe = (float)($r->FacturadoImporte ?? 0);

  // TextoBono suele ser clave para “bonos/descargas”
  $texto_bono   = (string)($r->TextoBono ?? '');
  $cliente_bono = (string)($r->ClienteBono ?? '');

  // IDs útiles para futuro (pagos, proveedor, producto)
  $id_cliente    = (int)($r->IdCliente ?? 0);
  $id_expediente = (int)($r->IdExpediente ?? 0);
  $id_proveedor  = (int)($r->IdProveedor ?? 0);
  $id_producto   = (int)($r->IdProducto ?? 0);

  // Campos “metadata” que luego te sirven para reglas:
  $via = (string)($r->Via ?? '');
  $pagadero_por = (string)($r->PagaderoPor ?? '');
  $cod_forma_pago = (string)($r->CodFormaPago ?? '');

  return [
    'id' => $id,
    'codigo' => $codigo ?: (string)$id,
    'tipo' => $tipo ?: 'Otros',
    'subtipo' => $subtipo,
    'descripcion' => $desc,
    'destino' => $destino,
    'localizador' => $localizador,

    'fecha_desde' => $fecha_desde,
    'fecha_hasta' => $fecha_hasta,
    'fecha_limite' => $fecha_limite,
    'fecha_prepago' => $fecha_prepago,

    'pax' => $pax,
    'adultos' => $ad,
    'ninos' => $nin,
    'regimen' => $regimen,

    'venta' => $venta,
    'pendiente' => $pendiente,

    'facturado' => $facturado,
    'facturado_importe' => $facturado_importe,

    'texto_bono' => $texto_bono,
    'cliente_bono' => $cliente_bono,

    'id_cliente' => $id_cliente,
    'id_expediente' => $id_expediente,
    'id_proveedor' => $id_proveedor,
    'id_producto' => $id_producto,

    'via' => $via,
    'pagadero_por' => $pagadero_por,
    'cod_forma_pago' => $cod_forma_pago,

    'raw' => $r, // útil mientras afinamos
  ];
}

function casanova_reserva_estado_from_mapped(array $m): array {
  // Estado “real” con lo que tenemos, sin fantasías:
  // - facturado
  // - pendiente
  // - fechas límite / prepago
  // - si ya pasó la fecha_hasta, lo marcamos como “Finalizada” (si no está pendiente)

  $pend = (float)($m['pendiente'] ?? 0);
  $venta = (float)($m['venta'] ?? 0);
  $facturado = (bool)($m['facturado'] ?? false);

  $hoy = current_time('timestamp');

  $ts_limite = !empty($m['fecha_limite']) ? strtotime((string)$m['fecha_limite']) : 0;
  $ts_hasta  = !empty($m['fecha_hasta']) ? strtotime((string)$m['fecha_hasta']) : 0;
  $ts_prep   = !empty($m['fecha_prepago']) ? strtotime((string)$m['fecha_prepago']) : 0;

  if ($pend <= 0.01 && $venta > 0) {
    // pagada / sin saldo
    if ($facturado) return ['Facturada', 'ok'];
    if ($ts_hasta > 0 && $ts_hasta < $hoy) return ['Finalizada', 'neutral'];
    return ['Pagada', 'ok'];
  }

  // Tiene pendiente
  if ($ts_limite > 0 && $ts_limite < $hoy) return ['Vencida', 'bad'];

  // Si hay fecha de prepago, lo usamos como aviso
  if ($ts_prep > 0 && $ts_prep < $hoy) return ['Prepago vencido', 'bad'];
  if ($ts_prep > 0 && $ts_prep >= $hoy) return ['Prepago pendiente', 'warn'];

  return [esc_html__('Pendiente', 'casanova-portal'), 'warn'];
}