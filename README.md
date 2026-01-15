# Casanova Portal - GIAV (pagos React)

## Cambios recientes
- `includes/services/trip-service.php` ahora normaliza el paquete PQ, los servicios incluidos y los extras con `code`, `detail` y `voucher_urls`, manteniendo la lógica heredada de `casanova_portal_voucher_url`.
- `react-app-template/src/App.jsx` + `react-app-template/src/styles.css` renderizan la tarjeta **Resumen** con “Paquete”, “Servicios incluidos” y “Extras”, botones `Detalle`, `Ver bono` y `PDF` y el panel colapsable de información adicional.
- `react-app-template/dist` se reconstruyó con `npm run build` y se copiaron los artefactos actualizados a `assets/portal-app.js`/`.css` para que WordPress entregue el nuevo UI.

## JSON del endpoint `/trip`
```json
{
  "status": "ok",
  "giav": { "ok": true, "source": "giav|cache|mock", "error": null },
  "trip": { "id": 123, "code": "0001", "title": "...", "status": "...", "date_range": "dd/mm/yyyy – dd/mm/yyyy" },
  "package": {
    "id": "PQ-123",
    "code": "0001",
    "type": "PQ",
    "title": "...",
    "date_range": "...",
    "price": 1400,
    "actions": { "detail": true, "voucher": false, "pdf": false },
    "voucher_urls": { "view": "", "pdf": "" },
    "detail": { "code": "0001", "type": "PQ", "dates": "...", "locator": "...", "bonus_text": "" },
    "services": [
      {
        "id": "HT-456",
        "code": "0002",
        "type": "HT",
        "title": "Hotel …",
        "date_range": "...",
        "price": null,
        "included": true,
        "actions": { "detail": true, "voucher": true, "pdf": true },
        "voucher_urls": { "view": "https://...", "pdf": "https://..." },
        "detail": { "code": "0002", "type": "HT", "dates": "...", "locator": "...", "bonus_text": "Texto adicional" }
      }
    ]
  },
  "extras": [
    {
      "id": "TR-789",
      "code": "0005",
      "type": "TR",
      "title": "Traslado",
      "date_range": "...",
      "price": 500,
      "included": false,
      "actions": { "detail": true, "voucher": true, "pdf": true },
      "voucher_urls": { "view": "https://...", "pdf": "https://..." },
      "detail": { "code": "0005", "type": "TR", "dates": "...", "locator": "...", "bonus_text": "" }
    }
  ],
  "passengers": [
    { "name": "Juan Pérez", "type": "Adulto", "document": "X1234567" }
  ]
}
```

> Nota: si no hay PQ, `package` viene como `null` y todos los servicios aparecen en `extras[]` con `included: false`.

## Cómo probar
- **GIAV real:** `GET /wp-json/casanova/v1/trip?id=250056` con un usuario logueado que tenga `casanova_idcliente`. La tarjeta Resumen debe mostrar el PQ con “Servicios incluidos” y los botones `Detalle`, `Ver bono` y `PDF`.
- **Modo mock:** usa `?mock=1` para forzar datos sintéticos (`id=250056` para probar PQ+extras, `id=250057` para servicios sueltos). El mock replica la jerarquía y los enlaces de bono/PDF para validar el nuevo panel de detalle.
> El paquete PQ solo muestra `Ver bono` y `PDF` cuando no tiene servicios incluidos (es decir, cuando el propio PQ es el servicio principal).


## React build (producción)

El portal carga la SPA si detecta el shortcode `[casanova_portal_app]` y existe el build.

Recomendado:

```bash
cd react-app-template
npm install
npm run build:wp
```

Esto compila con Vite y copia `portal-app.js` y `portal-app.css` al directorio `assets/` del plugin (para WordPress).
