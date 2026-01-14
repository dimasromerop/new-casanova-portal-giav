# Casanova Portal - GIAV (pagos React)

## Cambios recientes
- `includes/services/trip-service.php` ahora normaliza el detalle del viaje (paquete PQ + servicios incluidos + extras) directamente desde GIAV.
- `react-app-template/src/App.jsx` consume `package` y `extras` sin reconstrucción en frontend.
- `includes/mock/trip.json` contiene escenarios mock para `/trip` con estructura jerárquica.

## Endpoint `/trip`
`GET /wp-json/casanova/v1/trip?id=XXXX`

Forma mínima del JSON:

```json
{
  "status": "ok",
  "giav": { "ok": true, "source": "giav|cache|mock", "error": null },
  "trip": {
    "id": 123,
    "title": "…",
    "code": "…",
    "status": "…",
    "date_range": "dd/mm/yyyy – dd/mm/yyyy"
  },
  "package": {
    "id": "…",
    "type": "PQ",
    "title": "…",
    "date_range": "…",
    "price": 123,
    "services": [
      {
        "id": "…",
        "type": "HT|GF|TR|AV|OT|…",
        "title": "…",
        "date_range": "…",
        "price": null,
        "included": true,
        "actions": { "detail": true, "voucher": true, "pdf": true }
      }
    ]
  },
  "extras": [
    {
      "id": "…",
      "type": "…",
      "title": "…",
      "date_range": "…",
      "price": 123,
      "included": false,
      "actions": { "detail": true, "voucher": false, "pdf": false }
    }
  ],
  "passengers": [
    { "name": "…", "type": "…", "document": "…" }
  ]
}
```

## Cómo probar
- GIAV real: `GET /wp-json/casanova/v1/trip?id=250056` (usuario logueado).
- Mock: `GET /wp-json/casanova/v1/trip?id=250056&mock=1` o `GET /wp-json/casanova/v1/trip?id=250057&mock=1`.

## Nuevos endpoints
- `POST /wp-json/casanova/v1/payments/intent`
  - Body: `{"expediente_id": 12345, "type": "deposit"|"balance"}`
  - Requiere nonce WP (`X-WP-Nonce` con `wp_rest`) y la sesión del usuario.
  - Valida ownership, disponibilidad del modo y devuelve `{ok:true, redirect_url:"..."}` al flujo legacy.
  - `?mock=1` (solo administradores) responde `redirect_url=/portal?payment=intent-mock`.

## Cómo probar el flujo
1. Accede al listado de viajes y usa el botón **Pagar** para abrir el expediente en la pestaña **Pagos**.
2. Allí se muestran tarjetas de totales y los botones **Pagar depósito** / **Pagar pendiente**; tocan el backend via `payments/intent` y redirigen al TPV legacy.
3. Para tests manuales o automatizados puedes llamar al endpoint:

```bash
curl -X POST https://tu-sitio/wp-json/casanova/v1/payments/intent \
  -H "X-WP-Nonce: $(wp eval 'echo wp_create_nonce(\"wp_rest\");')" \
  -H "Content-Type: application/json" \
  -d '{"expediente_id": 12345, "type": "deposit"}'
```

4. Usa `?mock=1` en la URL del endpoint para obtener `redirect_url` de prueba y confirmar que React muestra errores amistosos cuando el backend rechaza la acción.

## Notas
- React ya no construye la URL de pago: consume la respuesta del endpoint y sólo muestra estados/errores.
- El backend reutiliza los helpers de pagos legacy (`casanova_calc_pago_expediente`, `casanova_payments_*`, `casanova_portal_pay_expediente_url`).
