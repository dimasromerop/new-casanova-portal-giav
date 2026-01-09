# Casanova Portal - GIAV (pagos React)

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
