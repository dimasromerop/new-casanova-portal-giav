# React App Template (Casanova Portal)

Plantilla mínima para compilar la SPA del portal.

- App: `src/App.jsx`
- Output esperado en el plugin:
  - `assets/portal-app.js`
  - `assets/portal-app.css` (opcional)

## Modo mock

`?mock=1` (solo admin) lee fixtures:
- `includes/mock/dashboard.json`
- `includes/mock/messages.json`


## Build para WordPress (recomendado)

El plugin carga el bundle desde `assets/portal-app.js` y `assets/portal-app.css`.

Para compilar y copiar automáticamente el build a `assets/`:

```bash
npm run build:wp
```

Para desarrollo local (Vite dev server):

```bash
npm run dev:wp
```

