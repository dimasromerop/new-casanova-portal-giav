# 📘 Contrato de Datos – Portal Casanova (GIAV)

Versión: 1.0  
Estado: Aprobado para desarrollo

---

## Propósito
Definir qué datos necesita el portal de cliente para funcionar correctamente, independientemente de cómo GIAV los proporcione internamente.

GIAV es la fuente de verdad.  
El backend mapea GIAV → este contrato.  
El frontend solo consume este contrato.

---

## Principios
- El frontend no parsea HTML
- El frontend no conoce campos internos de GIAV
- JSON estable
- Fechas en ISO (yyyy-mm-dd o ISO datetime)
- URLs siempre frontend

---

## Dashboard
GET /dashboard

```json
{
  "giav": { "ok": true, "source": "giav", "error": null },
  "trips": [
    {
      "id": 12345,
      "code": "PT-12345",
      "title": "Portugal Golf Escape",
      "status": "Confirmado",
      "date_start": "2026-02-10",
      "date_end": "2026-02-14"
    }
  ],
  "messages": { "unread": 2 }
}
```

---

## Trip / Expediente
GET /trip/{id}

```json
{
  "trip": {
    "id": 12345,
    "code": "PT-12345",
    "title": "Portugal Golf Escape",
    "status": "Confirmado",
    "date_start": "2026-02-10",
    "date_end": "2026-02-14",
    "pax": 4
  },
  "services": [],
  "payments": {
    "currency": "EUR",
    "total": 4500,
    "paid": 1500,
    "pending": 3000,
    "can_pay": true
  }
}
```

---

## Mensajes
GET /messages?expediente={id}

```json
{
  "items": [
    {
      "id": "m1",
      "date": "2026-01-04T18:20:00+01:00",
      "author": "Casanova Golf",
      "content": "Welcome pack listo.",
      "expediente_id": 12345
    }
  ]
}
```

---

Fin del contrato
