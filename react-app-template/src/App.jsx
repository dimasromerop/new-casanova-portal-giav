/*
  App.portal.viajes-detalle-tabs.jsx
  - Viajes: listado con tabla rica (ancho ampliado + fechas ES)
  - Viaje: vista detalle con breadcrumb + header + tabs (Resumen/Pagos/Facturas/Bonos/Mensajes)
  - Mensajes: timeline por expediente (usa /messages?expediente=ID)
*/

import React, { useEffect, useMemo, useRef, useState } from "react";

/* ===== Helpers ===== */
function api(path, options = {}) {
  const base = window.CasanovaPortal?.restUrl;
  const nonce = window.CasanovaPortal?.nonce;
  const method = options.method ? options.method.toUpperCase() : "GET";
  const headers = {
    "X-WP-Nonce": nonce,
    ...(options.headers || {}),
  };

  const init = {
    method,
    credentials: "same-origin",
    headers,
  };

  if (options.body !== undefined) {
    if (options.body instanceof FormData) {
      init.body = options.body;
    } else {
      headers["Content-Type"] = headers["Content-Type"] || "application/json";
      init.body =
        typeof options.body === "string" ? options.body : JSON.stringify(options.body);
    }
  }

  return fetch(base + path, init).then(async (r) => {
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw j;
    return j;
  });
}

function readParams() {
  const p = new URLSearchParams(window.location.search);
  return {
    view: p.get("view") || "dashboard",
    expediente: p.get("expediente"),
    tab: p.get("tab") || "summary",
    mock: p.get("mock") === "1",
    payStatus: p.get("pay_status") || "",
    payment: p.get("payment") || "",
    refresh: p.get("refresh") === "1",
  };
}

function setParam(key, value) {
  const p = new URLSearchParams(window.location.search);
  if (value === null || value === undefined || value === "") p.delete(key);
  else p.set(key, value);
  window.history.pushState({}, "", `${window.location.pathname}?${p.toString()}`);
  window.dispatchEvent(new Event("popstate"));
}

/* ===== UX Components (microcopy + empty/loading states) ===== */

function Notice({ variant = "info", title, children, action }) {
  return (
    <div className={`cp-notice2 is-${variant}`}>
      <div className="cp-notice2__body">
        {title ? <div className="cp-notice2__title">{title}</div> : null}
        <div className="cp-notice2__text">{children}</div>
      </div>
      {action ? <div className="cp-notice2__action">{action}</div> : null}
    </div>
  );
}

function EmptyState({ title, children, icon = "🗂️", action }) {
  return (
    <div className="cp-empty">
      <div className="cp-empty__icon" aria-hidden="true">
        {icon}
      </div>
      <div className="cp-empty__title">{title}</div>
      {children ? <div className="cp-empty__text">{children}</div> : null}
      {action ? <div className="cp-empty__action">{action}</div> : null}
    </div>
  );
}

function Skeleton({ lines = 3 }) {
  return (
    <div className="cp-skeleton" aria-hidden="true">
      {Array.from({ length: lines }).map((_, i) => (
        <div key={i} className="cp-skeleton__line" />
      ))}
    </div>
  );
}


function TableSkeleton({ rows = 6, cols = 7 }) {
  return (
    <div className="cp-table-skel" aria-hidden="true">
      <div className="cp-table-skel__row is-head">
        {Array.from({ length: cols }).map((_, i) => (
          <div key={i} className="cp-table-skel__cell" />
        ))}
      </div>
      {Array.from({ length: rows }).map((_, r) => (
        <div key={r} className="cp-table-skel__row">
          {Array.from({ length: cols }).map((_, c) => (
            <div key={c} className="cp-table-skel__cell" />
          ))}
        </div>
      ))}
    </div>
  );
}

/* ===== Local state (frontend-only) =====
   GIAV is read-only from this portal for now. We track "seen" client-side to avoid
   zombie badges and keep UX sane while we wait for API write-back.
*/
const LS_KEYS = {
  inboxLatestTs: "casanovaPortal_inboxLatestTs",
  messagesLastSeenTs: "casanovaPortal_messagesLastSeenTs",
};

function lsGetInt(key, fallback = 0) {
  try {
    const v = window.localStorage.getItem(key);
    const n = parseInt(v || "", 10);
    return Number.isFinite(n) ? n : fallback;
  } catch {
    return fallback;
  }
}

function lsSetInt(key, value) {
  try {
    window.localStorage.setItem(key, String(value));
  } catch {}
}
function formatDateES(iso) {
  if (!iso || typeof iso !== "string") return "—";
  const m = iso.match(/(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return iso;
  const [, y, mo, d] = m;
  return `${d}/${mo}/${y}`;
}

function splitRange(range) {
  if (!range || typeof range !== "string") return { start: "—", end: "—" };
  const parts = range.split("–").map((s) => s.trim());
  if (parts.length === 2) return { start: parts[0], end: parts[1] };
  const parts2 = range.split("-").map((s) => s.trim());
  if (parts2.length >= 2) return { start: parts2[0], end: parts2[1] };
  return { start: range, end: "—" };
}

function normalizeTripDates(trip) {
  // Supports both legacy `date_range` ("YYYY-MM-DD – YYYY-MM-DD")
  // and contract fields `date_start` / `date_end`.
  if (!trip) return { start: "—", end: "—" };
  if (trip.date_start || trip.date_end) {
    return { start: trip.date_start || "—", end: trip.date_end || "—" };
  }
  const r = splitRange(trip.date_range);
  return { start: r.start, end: r.end };
}

function euro(n, currency = "EUR") {
  if (typeof n !== "number" || Number.isNaN(n)) return "—";
  try {
    return new Intl.NumberFormat("es-ES", { style: "currency", currency }).format(n);
  } catch {
    return `${Math.round(n)} ${currency}`;
  }
}

function formatTierLabel(tier) {
  if (!tier) return "—";
  const normalized = String(tier).toLowerCase();
  const map = {
    birdie: "Birdie",
    eagle: "Eagle",
    eagle_plus: "Eagle+",
    albatross: "Albatross",
  };
  if (map[normalized]) return map[normalized];
  return normalized.replace(/_/g, " ").replace(/\b\w/g, (char) => char.toUpperCase());
}

function formatTimestamp(value) {
  const ts = Number(value || 0);
  if (!Number.isFinite(ts) || ts <= 0) return null;
  const date = new Date(ts * 1000);
  if (Number.isNaN(date.getTime())) return null;
  return date.toLocaleString("es-ES", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}


function formatMsgDate(d) {
  if (!d) return "—";
  const dt = new Date(d);
  if (Number.isNaN(dt.getTime())) return String(d);
  return dt.toLocaleDateString("es-ES", { day: "2-digit", month: "2-digit", year: "numeric" });
}

const ICON_PROPS = {
  width: 20,
  height: 20,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.5,
  strokeLinecap: "round",
  strokeLinejoin: "round",
  focusable: "false",
};

function IconGrid() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <rect x={3} y={3} width={7.5} height={7.5} rx={1.5} />
      <rect x={13.5} y={3} width={7.5} height={7.5} rx={1.5} />
      <rect x={3} y={13.5} width={7.5} height={7.5} rx={1.5} />
      <rect x={13.5} y={13.5} width={7.5} height={7.5} rx={1.5} />
    </svg>
  );
}

function IconMapPin() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M12 3c-3.866 0-7 3.134-7 7 0 4.25 7 11 7 11s7-6.75 7-11c0-3.866-3.134-7-7-7z" />
      <circle cx={12} cy={10} r={2.2} />
    </svg>
  );
}

function IconChatBubble() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M5 6h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H8l-4 4V8a2 2 0 0 1 2-2z" />
    </svg>
  );
}

function IconStarBadge() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M12 4l1.91 3.86 4.27.62-3.09 3.01.73 4.25-3.82-2.01-3.82 2.01.73-4.25-3.09-3.01 4.27-.62z" />
    </svg>
  );
}

function IconClipboardList() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M8 5h8a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z" />
      <path d="M9 9h6" />
      <path d="M9 13l2 2 4-4" />
    </svg>
  );
}

function IconWallet() {
  return (
    <svg {...ICON_PROPS} aria-hidden="true">
      <path d="M5 8h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8z" />
      <path d="M16 12h2" />
      <circle cx={17} cy={11.5} r={1} fill="currentColor" stroke="none" />
    </svg>
  );
}

const NAV_ITEMS = [
  {
    key: "dashboard",
    label: "Dashboard",
    view: "dashboard",
    icon: IconGrid,
    isActive: (view) => view === "dashboard",
  },
  {
    key: "trips",
    label: "Viajes",
    view: "trips",
    icon: IconMapPin,
    isActive: (view) => view === "trips" || view === "trip",
  },
  {
    key: "inbox",
    label: "Mensajes",
    view: "inbox",
    icon: IconChatBubble,
    isActive: (view) => view === "inbox",
  },
  {
    key: "mulligans",
    label: "Mulligans",
    view: "mulligans",
    icon: IconStarBadge,
    isActive: (view) => view === "mulligans",
  },
];

/* ===== Shell ===== */
function Sidebar({ view, unread = 0 }) {
  return (
    <aside className="cp-sidebar">
      <div className="cp-brand" style={{ display: "flex", alignItems: "center", gap: 12 }}>
        <div
          className="cp-logo"
          style={{
            width: 44,
            height: 44,
            borderRadius: 12,
            background: "var(--primary, #0f3d2e)",
            flex: "0 0 auto",
          }}
        />
        <div style={{ display: "flex", flexDirection: "column", lineHeight: 1.1 }}>
          <div className="cp-brand-title">Casanova Portal</div>
          <div className="cp-brand-sub">Gestión de Reservas</div>
        </div>
      </div>

      <nav className="cp-nav">
        {NAV_ITEMS.map((item) => {
          const IconComponent = item.icon;
          const active = item.isActive(view);
          return (
            <button
              key={item.key}
              type="button"
              className={`cp-nav-btn ${active ? "is-active" : ""}`}
              onClick={() => setParam("view", item.view)}
            >
              <span className="cp-nav-label">
                <span className="cp-nav-icon">
                  <IconComponent />
                </span>
                <span>{item.label}</span>
              </span>
              {item.key === "inbox" && view !== "inbox" && unread > 0 ? (
                <span className="cp-badge">{unread}</span>
              ) : null}
            </button>
          );
        })}
      </nav>

      <div style={{ marginTop: "auto", padding: 10, color: "var(--muted)", fontSize: 12 }}>
        Soporte
        <div style={{ marginTop: 6 }}>Si necesitas algo, escríbenos desde Mensajes.</div>
      </div>
    </aside>
  );
}

function Topbar({ title, chip, onRefresh, isRefreshing }) {
  return (
    <div className="cp-topbar">
      <div className="cp-topbar-inner">
        <div className="cp-title">{title}</div>
        <div className="cp-actions">
          {chip ? <div className="cp-chip">{chip}</div> : null}
          {isRefreshing ? <div className="cp-chip">Actualizando…</div> : null}
          <button className="cp-btn" onClick={onRefresh}>
            Actualizar
          </button>
        </div>
      </div>
    </div>
  );
}

/* ===== Views ===== */
function TripsList({ mock, onOpen, dashboard }) {
  const trips = Array.isArray(dashboard?.trips) ? dashboard.trips : [];
  const years = Array.from(
    new Set(
      trips
        .map((t) => (t?.date_range || "").match(/(\d{4})/g)?.[0])
        .filter(Boolean)
    )
  ).sort();

  const defaultYear = years.includes(String(new Date().getFullYear()))
    ? String(new Date().getFullYear())
    : (years[0] || String(new Date().getFullYear()));

  const [year, setYear] = useState(defaultYear);

  const filteredTrips = year ? trips.filter((t) => String(t?.date_range || "").includes(year)) : trips;


  return (
    <div className="cp-content" style={{ maxWidth: 1600, width: "100%", margin: "0 auto", paddingTop: 8 }}>
            <div className="cp-card" style={{ background: "#fff" }}>
        <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, marginBottom: 12 }}>
          <div className="cp-card-title" style={{ margin: 0 }}>Tus viajes</div>
          <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
            <div className="cp-meta"><span className="cp-strong">Año:</span></div>
            <select
              value={year}
              onChange={(e) => setYear(e.target.value)}
              className="cp-select"
              style={{ padding: "8px 10px", borderRadius: 10, border: "1px solid rgba(0,0,0,0.12)", background: "#fff" }}
            >
              {years.map((y) => (
                <option key={y} value={y}>{y}</option>
              ))}
            </select>
          </div>
        </div>

        <div className="cp-table-wrap" style={{ marginTop: 14 }}>
          <table width="100%" cellPadding="10" style={{ borderCollapse: "collapse" }}>
            <thead>
              <tr style={{ textAlign: "left", borderBottom: "1px solid #ddd" }}>
                <th style={{ width: 120 }}>Expediente</th>
                <th>Viaje</th>
                <th style={{ width: 140 }}>Inicio</th>
                <th style={{ width: 140 }}>Fin</th>
                <th style={{ width: 120 }}>Estado</th>
                <th style={{ width: 110, textAlign: "right" }}>Total</th>
                <th style={{ width: 160 }}>Pagos</th>
                <th style={{ width: 120 }}>Bonos</th>
                <th style={{ width: 180 }}></th>
              </tr>
            </thead>
            <tbody>
              {filteredTrips.map((t) => {
                const r = normalizeTripDates(t);
                const payments = t?.payments || null;
                const totalAmount = typeof payments?.total === "number" ? payments.total : Number.NaN;
                const paidAmount = typeof payments?.paid === "number" ? payments.paid : Number.NaN;
                const pendingCandidate = typeof payments?.pending === "number" ? payments.pending : Number.NaN;
                const pendingAmount = Number.isFinite(pendingCandidate)
                  ? pendingCandidate
                  : (Number.isFinite(totalAmount) && Number.isFinite(paidAmount)
                      ? Math.max(0, totalAmount - paidAmount)
                      : Number.NaN);
                const hasPayments = Number.isFinite(totalAmount);
                const totalLabel = hasPayments ? euro(totalAmount) : "-";
                const pendingLabel = Number.isFinite(pendingAmount) ? euro(pendingAmount) : "-";
                const paymentsLabel = hasPayments
                  ? (pendingAmount <= 0.01 ? "Pagado" : `Pendiente: ${pendingLabel}`)
                  : "-";
                const bonusesAvailable = typeof t?.bonuses?.available === "boolean" ? t.bonuses.available : null;
                const bonusesLabel = bonusesAvailable === null
                  ? "-"
                  : (bonusesAvailable ? "Disponibles" : "No disponibles");
                return (
                  <tr key={t.id} style={{ borderBottom: "1px solid #eee" }}>
                    <td>{t.code || `#${t.id}`}</td>
                    <td>
                      <div style={{ fontWeight: 600 }}>{t.title}</div>
                      <div style={{ fontSize: 12, opacity: 0.75 }}>{t.code ? `ID ${t.id}` : `Expediente ${t.id}`}</div>
                    </td>
                    <td>{formatDateES(r.start)}</td>
                    <td>{formatDateES(r.end)}</td>
                    <td>{t.status || "-"}</td>
                    <td style={{ textAlign: "right" }}>{totalLabel}</td>
                    <td>{paymentsLabel}</td>
                    <td>{bonusesLabel}</td>
                    <td style={{ display: "flex", gap: 10, justifyContent: "flex-end" }}>
                      <button className="cp-btn primary" style={{ whiteSpace: "nowrap" }} onClick={() => onOpen(t.id)}>
                        Ver detalle
                      </button>
                      <button
                        className="cp-btn"
                        style={{ whiteSpace: "nowrap" }}
                        onClick={() => onOpen(t.id, "payments")}
                      >
                        Pagar
                      </button>
                    </td>
                  </tr>
                );
              })}
              {filteredTrips.length === 0 ? (
                <tr>
                  <td colSpan={9} style={{ padding: 18, opacity: 0.8 }}>
                    No hay viajes disponibles ahora mismo.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>
      </div>

          </div>
  );
}

function Tabs({ tab, onTab }) {
  const items = [
    { k: "summary", label: "Resumen" },
    { k: "payments", label: "Pagos" },
    { k: "invoices", label: "Facturas" },
    { k: "vouchers", label: "Bonos" },
    { k: "messages", label: "Mensajes" },
  ];
  return (
    <div style={{ display: "flex", gap: 10, flexWrap: "wrap", marginTop: 14 }}>
      {items.map((it) => (
        <button
          key={it.k}
          className={`cp-btn ${tab === it.k ? "primary" : ""}`}
          onClick={() => onTab(it.k)}
        >
          {it.label}
        </button>
      ))}
    </div>
  );
}

function TripHeader({ trip, payments }) {
  const r = normalizeTripDates(trip);
  return (
    <div className="cp-card" style={{ marginTop: 14 }}>
      <div className="cp-card-header">
        <div>
          <div className="cp-card-title" style={{ fontSize: 20 }}>
            {trip?.title || "Viaje"}
          </div>
          <div className="cp-card-sub">
            {trip?.code || `Expediente #${trip?.id || "—"}`} · {trip?.status || "—"}
          </div>
        </div>
        <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
          <div className="cp-meta">
            <span className="cp-strong">Fechas:</span> {formatDateES(r.start)} – {formatDateES(r.end)}
          </div>
          <button
            className="cp-btn"
            onClick={() => {
              setParam("tab", "payments");
            }}
          >
            Ver pagos
          </button>
        </div>
      </div>
    </div>
  );
}

function PaymentActions({ expediente, payments, mock }) {
  const [state, setState] = useState({ loading: null, error: null });
  const totalAmount = typeof payments?.total === "number" ? payments.total : Number.NaN;
  const paidAmount = typeof payments?.paid === "number" ? payments.paid : Number.NaN;
  const pendingCandidate = typeof payments?.pending === "number" ? payments.pending : Number.NaN;
  const pendingAmount = Number.isFinite(pendingCandidate)
    ? pendingCandidate
    : (Number.isFinite(totalAmount) && Number.isFinite(paidAmount)
        ? Math.max(0, totalAmount - paidAmount)
        : null);
  const isPaidLocal = pendingAmount !== null ? pendingAmount <= 0.01 : false;
  const actions = payments?.actions ?? {};
  const deposit = actions.deposit ?? { allowed: false, amount: 0 };
  const balance = actions.balance ?? { allowed: false, amount: 0 };
  const options = payments?.payment_options ?? null;
  const depositAllowed =
    typeof options?.can_pay_deposit === "boolean" ? options.can_pay_deposit : deposit.allowed;
  const depositAmount =
    typeof options?.deposit_amount === "number" ? options.deposit_amount : deposit.amount;
  const balanceAllowed =
    typeof options?.can_pay_full === "boolean" ? options.can_pay_full : balance.allowed;
  const balanceAmount =
    typeof options?.pending_amount === "number" ? options.pending_amount : balance.amount;

  const startIntent = async (type) => {
    setState({ loading: type, error: null });
    try {
      const qs = mock ? "?mock=1" : "";
      const payload = await api(`/payments/intent${qs}`, {
        method: "POST",
        body: {
          expediente_id: Number(expediente),
          type,
        },
      });
      if (payload?.ok && payload?.redirect_url) {
        window.location.href = payload.redirect_url;
        return;
      }
      throw payload;
    } catch (error) {
      const message =
        typeof error === "string"
          ? error
          : error?.message || error?.msg || error?.code || "No se pudo iniciar el pago.";
      setState({ loading: null, error: message });
    }
  };

  const hasActions = depositAllowed || balanceAllowed;
  const currency = payments?.currency || "EUR";

  return (
    <div style={{ marginTop: 20, display: "flex", flexDirection: "column", gap: 10 }}>
      <div style={{ display: "flex", flexWrap: "wrap", gap: 10 }}>
        {depositAllowed ? (
          <button
            className="cp-btn primary"
            style={{ whiteSpace: "nowrap" }}
            disabled={state.loading !== null}
            onClick={() => startIntent("deposit")}
          >
            {state.loading === "deposit"
              ? "Redirigiendo..."
              : `Pagar depósito (${euro(depositAmount, currency)})`}
          </button>
        ) : null}

        {balanceAllowed ? (
          <button
            className="cp-btn primary"
            style={{ whiteSpace: "nowrap" }}
            disabled={state.loading !== null}
            onClick={() => startIntent("balance")}
          >
            {state.loading === "balance"
              ? "Redirigiendo..."
              : `Pagar pendiente (${euro(balanceAmount, currency)})`}
          </button>
        ) : null}

        {!hasActions && !isPaidLocal ? (
          <div className="cp-meta" style={{ alignSelf: "center" }}>
            Aún no hay pagos disponibles para este viaje.
          </div>
        ) : null}
      </div>

      {state.error ? (
        <Notice variant="error" title="No se puede iniciar el pago">
          {state.error}
        </Notice>
      ) : null}
    </div>
  );
}

function MessagesTimeline({ expediente, mock, onLatestTs, onSeen }) {
  const [state, setState] = useState({ loading: true, error: null, data: null });

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        setState({ loading: true, error: null, data: null });
        const params = new URLSearchParams();
        if (mock) params.set("mock", "1");
        params.set("expediente", String(expediente));
        const qs = `?${params.toString()}`;
        const d = await api(`/messages${qs}`);
        if (!alive) return;
        setState({ loading: false, error: null, data: d });
      } catch (e) {
        if (!alive) return;
        setState({ loading: false, error: e, data: null });
      }
    })();
    return () => {
      alive = false;
    };
  }, [expediente, mock]);

  const items = Array.isArray(state.data?.items) ? state.data.items : [];
  const latestTs = items.length
    ? Math.max(
        ...items
          .map((x) => new Date(x?.date || 0).getTime())
          .filter((n) => Number.isFinite(n))
      )
    : 0;

  useEffect(() => {
    if (latestTs && typeof onLatestTs === "function") onLatestTs(latestTs);
  }, [latestTs, onLatestTs]);

  useEffect(() => {
    // Frontend-only "seen" marker: if user is viewing this timeline, consider it seen.
    if (typeof onSeen === "function") onSeen();
  }, [expediente, onSeen]);


  if (state.loading) return (<div className="cp-card"><div className="cp-card-title">Cargando mensajes</div><Skeleton lines={6} /></div>);
  if (state.error) {
    return (
      <div className="cp-notice is-warn">
        Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.
      </div>
    );
  }

  if (items.length === 0) return <EmptyState title="No hay mensajes disponibles" icon="💬">Si te escribimos, lo verás aquí al momento.</EmptyState>;

  return (
    <div className="cp-timeline" style={{ marginTop: 14 }}>
      {items.map((m) => (
        <div key={m.id} className="cp-msg">
          <div className="cp-msg-head">
            <div className="cp-msg-author">
              <span className="cp-dot" />
              <span>{m.author || (m.direction === "agency" ? "Casanova Golf" : "Tú")}</span>
            </div>
            <div>{formatMsgDate(m.date)}</div>
          </div>
          <div className="cp-msg-body">{m.content || ""}</div>
        </div>
      ))}
    </div>
  );
}

function InboxView({ mock, inbox, loading, error, onLatestTs, onSeen }) {
  const items = Array.isArray(inbox?.items) ? inbox.items : [];

  const sorted = useMemo(() => {
    return items
      .slice()
      .sort((a, b) => {
        const ta = a?.last_message_at ? new Date(a.last_message_at).getTime() : 0;
        const tb = b?.last_message_at ? new Date(b.last_message_at).getTime() : 0;
        return tb - ta;
      });
  }, [items]);

  useEffect(() => {
    if (!sorted.length) return;
    const latest = sorted.reduce((max, it) => {
      const t = it?.last_message_at ? new Date(it.last_message_at).getTime() : 0;
      return t > max ? t : max;
    }, 0);
    if (latest) onLatestTs?.(latest);
  }, [sorted, onLatestTs]);

  useEffect(() => {
    // cuando el usuario entra en Inbox, consideramos que ha "visto" los mensajes
    onSeen?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (loading) return (<div className="cp-card"><div className="cp-card-title">Mensajes</div><Skeleton lines={6} /></div>);
  if (error)
    return (
      <div className="cp-card">
        <div className="cp-card-title">Mensajes</div>
        <Notice variant="error" title="No se pueden cargar los mensajes">Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.</Notice>
      </div>
    );

  const status = inbox?.status === "mock" || mock ? "mock" : "ok";

  if (!sorted.length)
    return (
      <div className="cp-card">
        <div className="cp-card-title">Mensajes</div>
        <EmptyState title="No hay mensajes nuevos" icon="✅">Si te escribimos, lo verás aquí al momento.</EmptyState>
      </div>
    );

  return (
    <div className="cp-card">
      <div className="cp-card-title">Mensajes</div>
      {status === "mock" ? <div className="cp-chip">Modo prueba</div> : null}

      <div className="cp-inbox-list">
        {sorted.map((it) => (
          <button
            key={String(it.expediente_id)}
            className="cp-inbox-item"
            onClick={() => {
              const params = new URLSearchParams(window.location.search);
              params.set("view", "trip");
              params.set("expediente", String(it.expediente_id));
              // abre directamente pestaña Mensajes
              params.set("tab", "messages");
              window.history.pushState({}, "", `${window.location.pathname}?${params.toString()}`);
              window.dispatchEvent(new PopStateEvent("popstate"));
            }}
          >
            <div className="cp-inbox-left">
              <div className="cp-inbox-title">
                {it.trip_title || "Viaje"}{" "}
                <span className="cp-muted">
                  {it.trip_code ? `· ${it.trip_code}` : ""} {it.trip_status ? `· ${it.trip_status}` : ""}
                </span>
              </div>
              <div className="cp-inbox-snippet">{it.content || "Sin mensajes"}</div>
            </div>
            <div className="cp-inbox-right">
              <div className="cp-muted">{it.last_message_at ? formatMsgDate(it.last_message_at) : ""}</div>
              {typeof it.unread === "number" && it.unread > 0 ? <span className="cp-badge">{it.unread}</span> : null}
            </div>
          </button>
        ))}
      </div>
    </div>
  );
}

function ServiceItem({ service, indent = false }) {
  const [open, setOpen] = useState(false);
  const detail = service.detail || {};
  const bonusText = typeof detail.bonus_text === "string" ? detail.bonus_text.trim() : "";
  const price = typeof service.price === "number" ? service.price : null;
  const imageUrl = service?.media?.image_url || "";
  const viewUrl = service.voucher_urls?.view || "";
  const pdfUrl = service.voucher_urls?.pdf || "";
  const canVoucher = Boolean(service.actions?.voucher);
  const canPdf = Boolean(service.actions?.pdf);
  const tagLabel = (service.type || "servicio").toUpperCase();

  const toggleDetail = () => {
    if (!service.actions?.detail) return;
    setOpen((prev) => !prev);
  };

  return (
    <div className={`cp-service${indent ? " cp-service--child" : ""}`}>
      <div className="cp-service__summary">
        {imageUrl ? (
          <div className="cp-service__thumb" aria-hidden="true">
            <img src={imageUrl} alt="" loading="lazy" />
          </div>
        ) : null}
        <div className="cp-service__main">
          <div className="cp-service__code">
            {detail.code || service.id || "Servicio"}
          </div>
          <div className="cp-service__title">{service.title || "Servicio"}</div>
          <div className="cp-service__dates">
            {service.date_range || "Fechas por confirmar"}
          </div>
        </div>
        <div className="cp-service__right">
          {price != null ? (
            <div className="cp-service__price">{euro(price)}</div>
          ) : null}
          <div className="cp-service__actions">
            <span className="cp-chip">{tagLabel}</span>
            <button
              type="button"
              className="cp-btn cp-btn--ghost"
              onClick={toggleDetail}
              disabled={!service.actions?.detail}
              aria-expanded={open}
            >
              Detalle
            </button>
            {canVoucher && viewUrl ? (
              <a className="cp-btn cp-btn--ghost" href={viewUrl} target="_blank" rel="noreferrer">
                Ver bono
              </a>
            ) : (
              <span className="cp-btn cp-btn--ghost cp-btn--disabled">Bono</span>
            )}
            {canPdf && pdfUrl ? (
              <a className="cp-btn cp-btn--ghost" href={pdfUrl} target="_blank" rel="noreferrer">
                PDF
              </a>
            ) : (
              <span className="cp-btn cp-btn--ghost cp-btn--disabled">PDF</span>
            )}
          </div>
        </div>
      </div>
      {open ? (
        <div className="cp-service__detail">
          <div className="cp-service__kv">
            {detail.code || service.id ? (
              <div>
                <strong>Código:</strong> {detail.code || service.id}
              </div>
            ) : null}
            {detail.type ? (
              <div>
                <strong>Tipo:</strong> {detail.type}
              </div>
            ) : null}
            <div>
              <strong>Fechas:</strong> {service.date_range || "—"}
            </div>
            {detail.locator ? (
              <div>
                <strong>Localizador:</strong> {detail.locator}
              </div>
            ) : null}
            {price != null ? (
              <div>
                <strong>PVP:</strong> {euro(price)}
              </div>
            ) : null}
          </div>
          {bonusText ? (
            <>
              <div className="cp-service__divider" />
              <div>
                <strong>Texto adicional (bono):</strong>
                <p className="cp-service__bonus">{bonusText}</p>
              </div>
            </>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function ServiceList({ services, indent = false }) {
  if (!Array.isArray(services) || services.length === 0) return null;
  return (
    <div className="cp-service-list">
      {services.map((service, index) => (
        <ServiceItem
          key={service.id || `${service.type || "srv"}-${index}`}
          service={service}
          indent={indent}
        />
      ))}
    </div>
  );
}

function TripDetailView({ mock, expediente, dashboard, onLatestTs, onSeen }) {
  const trips = Array.isArray(dashboard?.trips) ? dashboard.trips : [];
  const fallbackTrip = trips.find((t) => String(t.id) === String(expediente)) || { id: expediente };

  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState(null);

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        setLoading(true);
        setErr(null);
        const params = new URLSearchParams();
        if (mock) params.set("mock", "1");
        const refreshFlag = (() => {
          const urlParams = new URLSearchParams(window.location.search);
          return (
            urlParams.get("pay_status") === "checking" ||
            urlParams.get("payment") === "success" ||
            urlParams.get("refresh") === "1"
          );
        })();
        if (refreshFlag) params.set("refresh", "1");
        const qs = params.toString() ? `?${params.toString()}` : "";
        const d = await api(`/trip/${encodeURIComponent(String(expediente))}${qs}`);
        if (!alive) return;
        setDetail(d);
      } catch (e) {
        if (!alive) return;
        setErr(e);
        setDetail(null);
      } finally {
        if (!alive) return;
        setLoading(false);
      }
    })();
    return () => {
      alive = false;
    };
  }, [expediente, mock]);

  const trip = detail?.trip || fallbackTrip;
  const payments = detail?.payments || null;
  const pkg = detail?.package || null;
  const extras = Array.isArray(detail?.extras) ? detail.extras : [];
  const packageServices = Array.isArray(pkg?.services) ? pkg.services : [];
  const hasServices = Boolean(pkg) || extras.length > 0;
  const invoices = Array.isArray(detail?.invoices) ? detail.invoices : [];
  const bonuses = detail?.bonuses ?? { available: false, items: [] };
  const voucherItems = Array.isArray(bonuses.items) ? bonuses.items : [];
  const chargeHistory = payments?.history ?? [];
  const totalAmount = typeof payments?.total === "number" ? payments.total : Number.NaN;
  const paidAmount = typeof payments?.paid === "number" ? payments.paid : Number.NaN;
  const pendingCandidate = typeof payments?.pending === "number" ? payments.pending : Number.NaN;
  const pendingAmount = Number.isFinite(pendingCandidate)
    ? pendingCandidate
    : (Number.isFinite(totalAmount) && Number.isFinite(paidAmount)
        ? Math.max(0, totalAmount - paidAmount)
        : null);
  const isPaid = pendingAmount !== null ? pendingAmount <= 0.01 : false;
  const currency = payments?.currency || "EUR";
  const mulligansUsed = payments?.mulligans_used ?? 0;

  const bonusDisabledReason = (type) => {
    if (!isPaid) return "El viaje debe estar pagado para descargar los bonos.";
    return type === "view"
      ? "No hay una vista previa disponible para este bono."
      : "No hay un PDF disponible para este bono.";
  };

  const renderBonusButton = (label, url, type) => {
    if (url) {
      return (
        <a
          className="cp-btn cp-btn--ghost cp-bonus-btn"
          href={url}
          target="_blank"
          rel="noreferrer noopener"
        >
          {label}
        </a>
      );
    }
    return (
      <button
        type="button"
        className="cp-btn cp-btn--ghost cp-bonus-btn"
        disabled
        title={bonusDisabledReason(type)}
      >
        {label}
      </button>
    );
  };

  const title = trip?.title || `Expediente #${expediente}`;
  const tab = readParams().tab;

  return (
    <div className="cp-content" style={{ maxWidth: 1200, width: "100%", margin: "0 auto" }}>
      <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
        <button className="cp-btn" onClick={() => setParam("view", "trips")}>
          ← Viajes
        </button>
        <div className="cp-meta" style={{ opacity: 0.85 }}>
          Viajes &gt; <span className="cp-strong">{title}</span>
        </div>
      </div>

      <TripHeader trip={trip} payments={payments} />

      <Tabs
        tab={tab}
        onTab={(k) => {
          setParam("tab", k);
        }}
      />

      <div style={{ marginTop: 14 }}>
        {loading ? (
          <div className="cp-card" style={{ background: "#fff" }}><div className="cp-card-title">Cargando expediente</div><Skeleton lines={8} /></div>
        ) : err ? (
          <div className="cp-notice is-warn">No se puede cargar el expediente ahora mismo.</div>
        ) : null}

        {tab === "summary" ? (
          <div className="cp-card">
            <div className="cp-card-title">Resumen</div>
            <div className="cp-card-sub">Servicios y planificación del viaje</div>

            {!hasServices ? (
              <div style={{ marginTop: 10 }} className="cp-meta">
                No hay servicios disponibles ahora mismo.
              </div>
            ) : (
              <div className="cp-summary-services">
                {pkg ? (
                  <div className="cp-service-section">
                    <div className="cp-service-section__heading">Paquete</div>
                    <ServiceItem service={pkg} />
                    {packageServices.length > 0 ? (
                      <div className="cp-service-section">
                        <div className="cp-service-section__heading">Servicios incluidos</div>
                        <ServiceList services={packageServices} indent />
                      </div>
                    ) : null}
                  </div>
                ) : null}
                {extras.length > 0 ? (
                  <div className="cp-service-section">
                    <div className="cp-service-section__heading">{pkg ? "Extras" : "Servicios"}</div>
                    <ServiceList services={extras} />
                  </div>
                ) : null}
              </div>
            )}
          </div>
        ) : null}

        {tab === "payments" ? (
          <div className="cp-card">
            <div className="cp-card-title">Pagos</div>
            <div className="cp-card-sub">Estado de pagos del viaje</div>

            {!payments ? (
              <div style={{ marginTop: 10 }} className="cp-meta">Aún no hay pagos asociados a este viaje.</div>
            ) : (
              <>
                <div style={{ marginTop: 14, display: "flex", flexWrap: "wrap", gap: 12 }}>
                  <div className="cp-card" style={{ background: "#fff", flex: "1 1 240px" }}>
                    <div className="cp-meta">Total</div>
                    <div style={{ fontSize: 22, fontWeight: 800, marginTop: 6 }}>
                      {euro(payments.total, currency)}
                    </div>
                  </div>

                  <div className="cp-card" style={{ background: "#fff", flex: "1 1 240px" }}>
                    <div className="cp-meta">Pagado</div>
                    <div style={{ fontSize: 22, fontWeight: 800, marginTop: 6 }}>
                      {euro(payments.paid, currency)}
                    </div>
                  </div>

                  <div className="cp-card" style={{ background: "#fff", flex: "1 1 240px" }}>
                    <div className="cp-meta">Pendiente</div>
                    <div style={{ fontSize: 22, fontWeight: 800, marginTop: 6 }}>
                      {euro(payments.pending, currency)}
                    </div>
                  </div>
                  <div className="cp-card" style={{ background: "#fff", flex: "1 1 240px" }}>
                    <div className="cp-meta">Mulligans usados</div>
                    <div style={{ fontSize: 22, fontWeight: 800, marginTop: 6 }}>
                      {mulligansUsed.toLocaleString("es-ES")}
                    </div>
                  </div>
                </div>

                <PaymentActions expediente={expediente} payments={payments} mock={mock} />
                {isPaid ? (
                  <div style={{ marginTop: 12 }}>
                    <div className="cp-pill cp-pill--success">Pagado</div>
                  </div>
                ) : null}

                {chargeHistory.length > 0 ? (
                  <div className="cp-payments-history">
                    <div className="cp-payments-history__title">Histórico de cobros</div>
                    <div className="cp-table-wrap">
                      <table className="cp-payments-history__table">
                        <thead>
                          <tr>
                            <th>Fecha</th>
                            <th>Tipo</th>
                            <th>Concepto</th>
                            <th>Pagador</th>
                            <th className="is-right">Importe</th>
                          </tr>
                        </thead>
                        <tbody>
                          {chargeHistory.map((row) => (
                            <tr key={row.id}>
                              <td>{formatDateES(row.date)}</td>
                              <td>{row.type}</td>
                              <td>{row.concept}</td>
                              <td>{row.payer || row.document || "—"}</td>
                              <td className="is-right">
                                {euro(row.is_refund ? -row.amount : row.amount, currency)}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                ) : (
                  <div style={{ marginTop: 14 }} className="cp-meta">
                    Aún no hay cobros registrados en este viaje.
                  </div>
                )}
              </>
            )}
          </div>
        ) : null}

        {tab === "invoices" ? (
          <div className="cp-card">
            <div className="cp-card-title">Facturas</div>
            <div className="cp-card-sub">Descargas asociadas a este viaje</div>
            {invoices.length === 0 ? (
              <div style={{ marginTop: 10 }} className="cp-meta">No hay facturas disponibles.</div>
            ) : (
              <div className="casanova-tablewrap" style={{ marginTop: 14 }}>
                <table className="casanova-table">
                  <thead>
                    <tr>
                      <th>Factura</th>
                      <th>Fecha</th>
                      <th className="num">Importe</th>
                      <th>Estado</th>
                      <th>Acciones</th>
                    </tr>
                  </thead>
                  <tbody>
                    {invoices.map((inv) => {
                      const statusRaw = String(inv.status || "").trim();
                      const statusNormalized = statusRaw.toLowerCase();
                      let statusClass = "casanova-badge";
                      if (statusNormalized.includes("pend")) statusClass += " casanova-badge--pending";
                      else if (statusNormalized.includes("pag")) statusClass += " casanova-badge--pay";
                      return (
                        <tr key={inv.id}>
                          <td>{inv.title || `Factura #${inv.id}`}</td>
                          <td>{formatDateES(inv.date)}</td>
                          <td className="num">
                            {typeof inv.amount === "number" ? euro(inv.amount, inv.currency || "EUR") : "—"}
                          </td>
                          <td>
                            <span className={statusClass}>{statusRaw || "—"}</span>
                          </td>
                          <td>
                            {inv.download_url ? (
                              <a className="casanova-btn casanova-btn--sm casanova-btn--ghost" href={inv.download_url}>
                                Descargar PDF
                              </a>
                            ) : (
                              <span className="casanova-btn casanova-btn--sm casanova-btn--disabled">Descargar PDF</span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        ) : null}

        {tab === "vouchers" ? (
          <div className="cp-card">
            <div className="cp-card-title">Bonos</div>
            <div className="cp-card-sub">Vouchers y documentación</div>
            {bonuses.available && voucherItems.length > 0 ? (
              <Notice variant="info" title="Bonos disponibles">
                En cada reserva podrás ver el bono y descargar el PDF.
              </Notice>
            ) : null}

            {voucherItems.length === 0 ? (
              <div style={{ marginTop: 10 }} className="cp-meta">
                {isPaid
                  ? "No hay bonos disponibles para este viaje."
                  : "Los bonos aparecerán cuando el viaje esté pagado."}
              </div>
            ) : (
              <div className="cp-bonus-list">
                {voucherItems.map((item) => (
                  <div key={item.id} className="cp-bonus-card">
                    <div>
                      <div className="cp-bonus-title">{item.label}</div>
                      <div className="cp-bonus-meta">{item.date_range || "Sin fechas"}</div>
                    </div>
                    <div className="cp-bonus-actions">
                      {renderBonusButton("Ver bono", item.view_url, "view")}
                      {renderBonusButton("PDF", item.pdf_url, "pdf")}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        ) : null}

        {tab === "messages" ? (
          <div className="cp-card">
            <div className="cp-card-title">Mensajes</div>
            <div className="cp-card-sub">Conversación sobre este viaje</div>
            <MessagesTimeline expediente={expediente} mock={mock} onLatestTs={onLatestTs} onSeen={onSeen} />
          </div>
        ) : null}
      </div>
    </div>
  );
}


/* ===== App ===== */
function MulligansView({ data }) {
  const m = data?.mulligans || {};
  const points = Number(m.points || 0);
  const tier = String(m.tier || "birdie").toLowerCase();
  const spend = Number(m.spend || 0);
  const earned = Number(m.earned || 0);
  const bonus = Number(m.bonus || 0);
  const used = Number(m.used || 0);
  const ledger = Array.isArray(m.ledger) ? m.ledger : [];

  const tierLabel = (t) => {
    if (t === "albatross") return "Albatross";
    if (t === "eagle") return "Eagle";
    if (t === "birdie") return "Birdie";
    return t ? t.charAt(0).toUpperCase() + t.slice(1) : "Birdie";
  };

  const fmtMoney = (v) =>
    new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR", maximumFractionDigits: 0 }).format(v || 0);

  const fmtDate = (ts) => {
    const n = Number(ts || 0);
    if (!n) return "—";
    const d = new Date(n * 1000);
    return d.toLocaleDateString("es-ES", { day: "2-digit", month: "2-digit", year: "numeric" });
  };

  return (
    <div className="cp-content">
      <div className="cp-card">
        <div className="cp-card-header">
          <div>
            <div className="cp-card-title">Tu programa Mulligans</div>
            <div className="cp-card-sub">Puntos y nivel se actualizan automáticamente con tus reservas.</div>
          </div>
          <div className="cp-pill">{tierLabel(tier)}</div>
        </div>

        <div className="cp-grid-3" style={{ marginTop: 14 }}>
          <div className="cp-stat">
            <div className="cp-stat-k">Balance</div>
            <div className="cp-stat-v">{points.toLocaleString("es-ES")}</div>
            <div className="cp-stat-s">Mulligans disponibles</div>
          </div>

          <div className="cp-stat">
            <div className="cp-stat-k">Gasto histórico</div>
            <div className="cp-stat-v">{fmtMoney(spend)}</div>
            <div className="cp-stat-s">Define tu nivel</div>
          </div>

          <div className="cp-stat">
            <div className="cp-stat-k">Última sincronización</div>
            <div className="cp-stat-v">{fmtDate(m.last_sync)}</div>
            <div className="cp-stat-s">Cuando se recalculó por última vez</div>
          </div>
        </div>

        <div className="cp-grid-4" style={{ marginTop: 14 }}>
          <div className="cp-mini">
            <div className="cp-mini-k">Ganados</div>
            <div className="cp-mini-v">{earned.toLocaleString("es-ES")}</div>
          </div>
          <div className="cp-mini">
            <div className="cp-mini-k">Bonus</div>
            <div className="cp-mini-v">{bonus.toLocaleString("es-ES")}</div>
          </div>
          <div className="cp-mini">
            <div className="cp-mini-k">Usados</div>
            <div className="cp-mini-v">{used.toLocaleString("es-ES")}</div>
          </div>
          <div className="cp-mini">
            <div className="cp-mini-k">Balance</div>
            <div className="cp-mini-v">{points.toLocaleString("es-ES")}</div>
          </div>
        </div>

        <div style={{ marginTop: 14 }}>
          <Notice variant="info" title="Cómo funciona">
            Los beneficios se activan con una reserva real. Si un año no viajas, mantienes tu nivel, pero no se “dispara” el beneficio.
          </Notice>
        </div>
      </div>

      <div className="cp-card" style={{ marginTop: 14 }}>
        <div className="cp-card-title">Histórico</div>
        <div className="cp-card-sub">Movimientos recientes (ganados, bonus y canjes).</div>

        {ledger.length === 0 ? (
          <EmptyState title="Aún no hay movimientos" icon="🧾">
            Cuando se registren pagos o se aplique un bonus, aparecerán aquí.
          </EmptyState>
        ) : (
          <div className="cp-ledger">
            {ledger.map((it) => {
              const pts = Number(it.points || 0);
              const sign = pts >= 0 ? "+" : "";
              const when = it.ts ? fmtDate(it.ts) : "—";
              const type = String(it.type || "");
              const label = type === "bonus" ? "Bonus" : type === "earn" ? "Ganado" : type === "redeem" ? "Canje" : "Movimiento";
              return (
                <div key={it.id || `${it.ts}-${Math.random()}`} className="cp-ledger-row">
                  <div className="cp-ledger-main">
                    <div className="cp-ledger-title">{label}</div>
                    <div className="cp-ledger-sub">{it.note || it.source || "—"}</div>
                  </div>
                  <div className="cp-ledger-right">
                    <div className={`cp-ledger-points ${pts >= 0 ? "is-pos" : "is-neg"}`}>{sign}{pts}</div>
                    <div className="cp-ledger-date">{when}</div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}



function CardTitleWithIcon({ icon: Icon, children }) {
  return (
    <div className="cp-card-title cp-card-title--with-icon">
      <span className="cp-card-title-icon" aria-hidden="true">
        <Icon />
      </span>
      <span>{children}</span>
    </div>
  );
}

function DashboardView({ data }) {
  const nextTrip = data?.next_trip || null;
  const payments = data?.payments || null;
  const mull = data?.mulligans || null;
  const action = data?.next_action || null;

  const hasPaymentsData = payments && !Array.isArray(payments);
  const totalAmount = hasPaymentsData ? Number(payments?.total) : Number.NaN;
  const paidAmount = hasPaymentsData ? Number(payments?.paid) : Number.NaN;
  const pendingCandidate = hasPaymentsData ? Number(payments?.pending) : Number.NaN;
  const pendingAmount = hasPaymentsData
    ? (Number.isFinite(pendingCandidate)
        ? pendingCandidate
        : (Number.isFinite(totalAmount) && Number.isFinite(paidAmount)
            ? Math.max(0, totalAmount - paidAmount)
            : null))
    : null;
  const totalLabel = Number.isFinite(totalAmount) ? euro(totalAmount) : "—";
  const paidLabel = Number.isFinite(paidAmount) ? euro(paidAmount) : "—";
  const pendingLabel = pendingAmount !== null ? euro(pendingAmount) : "—";
  const paymentProgress =
    hasPaymentsData && Number.isFinite(totalAmount) && totalAmount > 0 && Number.isFinite(paidAmount)
      ? Math.max(0, Math.min(100, Math.round((paidAmount / totalAmount) * 100)))
      : 0;

  const points = typeof mull?.points === "number" ? mull.points : 0;
  const levelLabel = formatTierLabel(mull?.tier);
  const lastSyncLabel = formatTimestamp(mull?.last_sync);

  const tierRaw = String(mull?.tier || "");
  const tierSlug = tierRaw
    .trim()
    .toLowerCase()
    .replace(/\\s+/g, "_")
    .replace(/\\+/g, "_plus")
    .replace(/\\-/g, "_");
  const tierClass = tierSlug ? "is-" + tierSlug : "";
  const multLabel = typeof mull?.mult === "number" ? ("x" + mull.mult) : null;
  const progressRaw = (typeof mull?.progress_pct === "number") ? mull.progress_pct : (typeof mull?.progress === "number" ? (mull.progress <= 1 ? mull.progress * 100 : mull.progress) : 0);
  const progressPct = Math.max(0, Math.min(100, Math.round(progressRaw || 0)));
  const remaining = typeof mull?.remaining_to_next === "number" ? mull.remaining_to_next : null;
  const nextTier = mull?.next_tier_label ? String(mull.next_tier_label) : null;
  const hintText = (remaining !== null && nextTier) ? ("Te faltan " + euro(remaining) + " para subir a " + nextTier + ".") : null;

  const tripLabel = nextTrip?.title ? String(nextTrip.title) : "Viaje";
  const tripCode = nextTrip?.code ? String(nextTrip.code) : "";
  const tripContext = tripCode ? `${tripLabel} (${tripCode})` : tripLabel;
  const tripMeta = [tripCode, nextTrip?.date_range].filter(Boolean).join(" · ");
  const daysLeftRaw = Number(nextTrip?.days_left);
  const daysLeft = Number.isFinite(daysLeftRaw) ? Math.max(0, Math.round(daysLeftRaw)) : null;
  const daysLeftLabel = daysLeft !== null ? `En ${daysLeft} días` : null;
  const calendarUrl = nextTrip?.calendar_url ? String(nextTrip.calendar_url) : "";

  const isPaid = pendingAmount !== null ? pendingAmount <= 0.01 : false;
  const actionStatus = action?.status || (hasPaymentsData ? (isPaid ? "ok" : "pending") : "info");
  const actionBadge = action?.badge || (hasPaymentsData ? (isPaid ? "Todo listo" : "Pendiente") : "Info");
  const actionText = action?.description || (!nextTrip
    ? "No hay viajes próximos para mostrar aquí."
    : !hasPaymentsData
      ? "Cuando haya información de pagos, la verás reflejada aquí."
      : isPaid
        ? "Tu próximo viaje está al día. No tienes acciones pendientes ahora mismo."
        : `Tienes un pago pendiente de ${euro(pendingAmount)}.`);
  const actionTripLabel = action?.trip_label || (nextTrip ? tripContext : "");
  const actionNote = action?.note || null;
  const noteExpedienteId = actionNote?.expediente_id ? String(actionNote.expediente_id) : "";
  const actionNoteUrl = noteExpedienteId
    ? (() => {
        const p = new URLSearchParams(window.location.search);
        p.set("view", "trip");
        p.set("expediente", noteExpedienteId);
        return `${window.location.pathname}?${p.toString()}`;
      })()
    : (actionNote?.url ? String(actionNote.url) : "");
  const actionPillClass = actionStatus === "pending" ? "is-warn" : (actionStatus === "ok" ? "is-ok" : "is-info");

  const viewTrip = () => {
    if (!nextTrip?.id) return;
    setParam("view", "trip");
    setParam("expediente", String(nextTrip.id));
  };

  const viewPayments = () => {
    if (!nextTrip?.id) return;
    setParam("view", "trip");
    setParam("expediente", String(nextTrip.id));
    setParam("tab", "payments");
  };

  const viewActionTrip = () => {
    const targetId = action?.expediente_id || nextTrip?.id;
    if (!targetId) return;
    setParam("view", "trip");
    setParam("expediente", String(targetId));
    if (actionStatus === "pending") setParam("tab", "payments");
  };

  const viewNoteTrip = (event) => {
    if (!noteExpedienteId) return;
    if (event && typeof event.preventDefault === "function") event.preventDefault();
    setParam("view", "trip");
    setParam("expediente", noteExpedienteId);
  };

  return (
    <div className="cp-content">
      <div className="cp-grid cp-dash-grid">
        <section className="cp-card cp-dash-card cp-dash-span-8">
          <div className="cp-dash-head">
            <CardTitleWithIcon icon={IconMapPin}>Próximo viaje</CardTitleWithIcon>
            <div className="cp-dash-head-right">
              {daysLeftLabel ? <span className="cp-pill cp-dash-pill">{daysLeftLabel}</span> : null}
              {nextTrip?.status ? <span className="cp-pill cp-dash-pill">{nextTrip.status}</span> : null}
            </div>
          </div>
          {nextTrip ? (
            <>
              <div className="cp-dash-next-title">{tripLabel}</div>
              <div className="cp-dash-next-meta">{tripMeta || "Fechas por definir"}</div>
              <div className="cp-dash-kpis">
                <div className="cp-dash-kpi">
                  <div className="cp-dash-kpi-label">Total viaje</div>
                  <div className="cp-dash-kpi-value">{hasPaymentsData ? totalLabel : "—"}</div>
                </div>
                <div className="cp-dash-kpi">
                  <div className="cp-dash-kpi-label">Pendiente</div>
                  <div className="cp-dash-kpi-value cp-dash-kpi-value--warn">
                    {hasPaymentsData ? pendingLabel : "—"}
                  </div>
                </div>
              </div>
              <div className="cp-dash-actions">
                <div className="cp-dash-actions-left">
                  <button className="cp-btn primary" onClick={viewTrip}>
                    Ver detalle
                  </button>
                  <button className="cp-btn cp-btn--ghost" onClick={viewPayments} disabled={!hasPaymentsData}>
                    Pagos
                  </button>
                  {calendarUrl ? (
                    <a className="cp-btn cp-btn--ghost" href={calendarUrl}>
                      Calendario
                    </a>
                  ) : null}
                </div>
                <div className="cp-dash-actions-meta">
                  Pagado: {hasPaymentsData ? paidLabel : "—"}
                </div>
              </div>
            </>
          ) : (
            <div className="cp-muted" style={{ marginTop: 8 }}>
              No tienes viajes próximos.
            </div>
          )}
        </section>

        <section className="cp-card cp-dash-card cp-dash-span-4">
          <div className="cp-dash-head">
            <CardTitleWithIcon icon={IconClipboardList}>Qué necesitas hacer ahora</CardTitleWithIcon>
            <span className={`cp-pill cp-dash-pill ${actionPillClass}`}>{actionBadge}</span>
          </div>
          {actionTripLabel ? (
            <div className="cp-dash-context">
              Para: <strong>{actionTripLabel}</strong>
            </div>
          ) : null}
          <div className="cp-dash-note">{actionText}</div>
          {actionNote?.label && actionNoteUrl ? (
            <div className="cp-dash-note-box">
              También tienes otro viaje a la vista:{" "}
              <a href={actionNoteUrl} onClick={noteExpedienteId ? viewNoteTrip : undefined}>
                {actionNote.label}
              </a>
              {actionNote.pending ? ` · Pendiente: ${actionNote.pending}` : ""}
            </div>
          ) : null}
          {actionTripLabel ? (
            <button className="cp-btn primary" onClick={viewActionTrip}>
              Ver viaje
            </button>
          ) : null}
        </section>

        <section className="cp-card cp-dash-card cp-dash-span-4">
          <div className="cp-dash-head">
            <CardTitleWithIcon icon={IconWallet}>Estado de pagos</CardTitleWithIcon>
            {hasPaymentsData ? (
              <span className={`cp-pill cp-dash-pill ${isPaid ? "is-ok" : "is-warn"}`}>
                {isPaid ? "Todo pagado" : "Pendiente"}
              </span>
            ) : null}
          </div>
          {hasPaymentsData ? (
            <>
              <div className="cp-dash-stats">
                <div>
                  <div className="cp-dash-stat-label">Pagado</div>
                  <div className="cp-dash-stat-value">{paidLabel}</div>
                </div>
                <div>
                  <div className="cp-dash-stat-label">Total</div>
                  <div className="cp-dash-stat-value">{totalLabel}</div>
                </div>
              </div>
              <div className="cp-dash-progress">
                <div className="cp-dash-progress-bar" style={{ width: `${paymentProgress}%` }} />
              </div>
              <div className="cp-dash-meta">
                Has pagado {paidLabel} de {totalLabel}
              </div>
              <button className="cp-btn cp-btn--ghost" onClick={viewPayments}>
                Ver detalle
              </button>
            </>
          ) : (
            <div className="cp-muted" style={{ marginTop: 12 }}>
              No hay datos de pagos disponibles por el momento.
            </div>
          )}
        </section>

        
        <section className={"casanova-mulligans-card " + tierClass}>
          <div className="casanova-mulligans-card__top">
            <div className="casanova-mulligans-card__title cp-card-title--with-icon">
              <span className="cp-card-title-icon" aria-hidden="true">
                <IconStarBadge />
              </span>
              <span>Tus Mulligans</span>
            </div>
            <div className="casanova-mulligans-card__tier">
              <span className="casanova-mulligans-badge">{levelLabel}</span>
            </div>
          </div>

          <div className="casanova-mulligans-card__big">{points.toLocaleString("es-ES")}</div>

          <div className="casanova-mulligans-card__meta">
            Gasto acumulado: {typeof mull?.spend === "number" ? euro(mull.spend) : "—"} · Ratio actual: {multLabel ? multLabel : "—"}
          </div>

          <div className="casanova-progress">
            <span className="casanova-progress__bar" style={{ width: progressPct + "%" }} />
          </div>

          {hintText ? <div className="casanova-mulligans-card__hint">{hintText}</div> : null}
          {lastSyncLabel ? <div className="casanova-mulligans-updated">Última actualización: {lastSyncLabel}</div> : null}

          <button className="cp-btn cp-btn--ghost" style={{ marginTop: 12 }} onClick={() => setParam("view", "mulligans")}>
            Ver movimientos
          </button>
        </section>

      </div>
    </div>
  );
}

function App() {
  const [route, setRoute] = useState(readParams());
  const [dashboard, setDashboard] = useState(null);
  const [loadingDash, setLoadingDash] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [dashErr, setDashErr] = useState(null);
  const [showPaymentBanner, setShowPaymentBanner] = useState(false);
  const bannerTimerRef = useRef(null);

  const [inbox, setInbox] = useState(null);
  const [inboxErr, setInboxErr] = useState(null);

  const [inboxLatestTs, setInboxLatestTs] = useState(() => lsGetInt(LS_KEYS.inboxLatestTs, 0));
  const [messagesLastSeenTs, setMessagesLastSeenTs] = useState(() => lsGetInt(LS_KEYS.messagesLastSeenTs, 0));

  useEffect(() => {
    const onPop = () => setRoute(readParams());
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);

  useEffect(() => {
    if (route.payment === "success") {
      setShowPaymentBanner(true);
      if (bannerTimerRef.current) {
        window.clearTimeout(bannerTimerRef.current);
      }
      bannerTimerRef.current = window.setTimeout(() => {
        setShowPaymentBanner(false);
      }, 5_000);
      setParam("payment", "");
      setParam("pay_status", "");
    }
    return () => {
      if (bannerTimerRef.current) {
        window.clearTimeout(bannerTimerRef.current);
        bannerTimerRef.current = null;
      }
    };
  }, [route.payment]);

  async function loadDashboard(refresh = false) {
    const hadData = !!dashboard;
    try {
      if (hadData) setIsRefreshing(true);
      else setLoadingDash(true);

      setDashErr(null);
      const qs = route.mock ? "?mock=1" : (refresh ? "?refresh=1" : "");
      const [dashRes, inboxRes] = await Promise.all([
        api(`/dashboard${qs}`),
        api(`/inbox${qs}`),
      ]);
      setDashboard(dashRes);
      setInbox(inboxRes);
      setInboxErr(null);
    } catch (e) {
      setDashErr(e);
      setInboxErr(e);
    } finally {
      setIsRefreshing(false);
      setLoadingDash(false);
    }
  }

  useEffect(() => {
    loadDashboard();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.mock]);

  useEffect(() => {
    if (route.payStatus === "checking" || route.payment === "success" || route.refresh) {
      loadDashboard(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.payStatus, route.payment, route.refresh]);

  useEffect(() => {
    const items = Array.isArray(inbox?.items) ? inbox.items : [];
    if (!items.length) return;
    const latest = items.reduce((max, it) => {
      const d = it?.last_message_at || it?.date;
      const t = d ? new Date(d).getTime() : 0;
      return t > max ? t : max;
    }, 0);
    if (latest) handleLatestTs(latest);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [inbox]);

  function handleLatestTs(ts) {
    if (!ts || !Number.isFinite(ts)) return;
    setInboxLatestTs(ts);
    lsSetInt(LS_KEYS.inboxLatestTs, ts);
  }

  function markMessagesSeen() {
    const now = Date.now();
    setMessagesLastSeenTs(now);
    lsSetInt(LS_KEYS.messagesLastSeenTs, now);
  }

  const unreadInbox = inbox?.unread;
  const unreadDash = dashboard?.messages?.unread;
  const unreadFromServer = typeof unreadInbox === "number" ? unreadInbox : (typeof unreadDash === "number" ? unreadDash : 0);

  const unreadCount =
    inboxLatestTs > 0 && messagesLastSeenTs >= inboxLatestTs ? 0 : unreadFromServer;

  const title = useMemo(() => {
    if ((route.view === "viajes" || route.view === "trips")) return "Viajes";
    if (route.view === "trip") return "Detalle del viaje";
    if (route.view === "inbox") return "Mensajes";
    if (route.view === "dashboard") return "Dashboard";
    if (route.view === "mulligans") return "Mulligans";
    return "Portal";
  }, [route.view]);

  const chip = route.mock ? "Modo prueba" : null;

  return (
    <div className="cp-app">
      <Sidebar view={route.view} unread={unreadCount} />
      <main className="cp-main">
        <Topbar title={title} chip={chip} onRefresh={() => loadDashboard(true)} isRefreshing={isRefreshing} />
        {showPaymentBanner ? (
          <div className="cp-content">
            <Notice variant="success" title="Pago registrado">
              Gracias, procesamos el cobro y actualizamos tus datos.
            </Notice>
          </div>
        ) : null}

        {loadingDash && !dashboard ? (
          <div className="cp-content">
            <div className="cp-card" style={{ background: "#fff" }}>
              <div className="cp-card-title">{(route.view === "viajes" || route.view === "trips") ? "Tus viajes" : "Cargando"}</div>
              {(route.view === "viajes" || route.view === "trips") ? (
                <div className="cp-table-wrap" style={{ marginTop: 14 }}>
                  <TableSkeleton rows={7} cols={8} />
                </div>
              ) : (
                <Skeleton lines={8} />
              )}
            </div>
          </div>
        ) : dashErr ? (
          <div className="cp-content">
            <div className="cp-notice is-warn">
              Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.
            </div>
          </div>
        ) : (route.view === "viajes" || route.view === "trips") ? (
          <TripsList
            mock={route.mock}
            dashboard={dashboard}
            onOpen={(id, tab = "summary") => {
              setParam("view", "trip");
              setParam("expediente", String(id));
              setParam("tab", tab);
            }}
          />
        ) : route.view === "trip" && route.expediente ? (
          <TripDetailView mock={route.mock} expediente={route.expediente} dashboard={dashboard} onLatestTs={handleLatestTs} onSeen={markMessagesSeen} />
        ) : route.view === "inbox" ? (
          <InboxView mock={route.mock} inbox={inbox} loading={loadingDash} error={inboxErr} onLatestTs={handleLatestTs} onSeen={markMessagesSeen} />
        ) : route.view === "dashboard" ? (
          <DashboardView data={dashboard} />
        ) : route.view === "mulligans" ? (
          <MulligansView data={dashboard} />
        ) : (
          <div className="cp-content">
            <div className="cp-notice">Vista en construcción.</div>
          </div>
        )}
      </main>
    </div>
  );
}

export default App;
