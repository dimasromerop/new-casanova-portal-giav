/*
  App.portal.viajes-detalle-tabs.jsx
  - Viajes: listado con tabla rica (ancho ampliado + fechas ES)
  - Viaje: vista detalle con breadcrumb + header + tabs (Resumen/Pagos/Facturas/Bonos/Mensajes)
  - Mensajes: timeline por expediente (usa /messages?expediente=ID)
*/

import React, { useEffect, useMemo, useState } from "react";

/* ===== Helpers ===== */
function api(path) {
  const base = window.CasanovaPortal?.restUrl;
  const nonce = window.CasanovaPortal?.nonce;
  return fetch(base + path, {
    credentials: "same-origin",
    headers: { "X-WP-Nonce": nonce },
  }).then(async (r) => {
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw j;
    return j;
  });
}

function readParams() {
  const p = new URLSearchParams(window.location.search);
  return {
    view: p.get("view") || "trips",
    expediente: p.get("expediente"),
    tab: p.get("tab") || "summary",
    mock: p.get("mock") === "1",
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

function DisabledHint({ reason, children }) {
  return (
    <span className="cp-disabledhint" title={reason}>
      {children}
    </span>
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


function formatMsgDate(d) {
  if (!d) return "—";
  const dt = new Date(d);
  if (Number.isNaN(dt.getTime())) return String(d);
  return dt.toLocaleDateString("es-ES", { day: "2-digit", month: "2-digit", year: "numeric" });
}

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
        <button
          className={`cp-nav-btn ${view === "dashboard" ? "is-active" : ""}`}
          onClick={() => setParam("view", "dashboard")}
        >
          Dashboard
        </button>

        <button
          className={`cp-nav-btn ${view === "trips" || view === "trip" ? "is-active" : ""}`}
          onClick={() => setParam("view", "trips")}
        >
          Viajes
        </button>

        <button
          className={`cp-nav-btn ${view === "inbox" ? "is-active" : ""}`}
          onClick={() => setParam("view", "inbox")}
        >
          <span>Mensajes</span>
          {/* Badge solo fuera de inbox/detalle-mensajes para no molestar */}
          {view !== "inbox" && unread > 0 ? <span className="cp-badge">{unread}</span> : null}
        </button>
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
            return (
              <tr key={t.id} style={{ borderBottom: "1px solid #eee" }}>
                <td>{t.code || `#${t.id}`}</td>
                <td>
                  <div style={{ fontWeight: 600 }}>{t.title}</div>
                  <div style={{ fontSize: 12, opacity: 0.75 }}>{t.code ? `ID ${t.id}` : `Expediente ${t.id}`}</div>
                </td>
                <td>{formatDateES(r.start)}</td>
                <td>{formatDateES(r.end)}</td>
                <td>{t.status || "—"}</td>
                <td style={{ textAlign: "right" }}>—</td>
                <td>—</td>
                <td>—</td>
                <td style={{ display: "flex", gap: 10, justifyContent: "flex-end" }}>
                  <button className="cp-btn primary" style={{ whiteSpace: "nowrap" }} onClick={() => onOpen(t.id)}>
                    Ver detalle
                  </button>
                  <DisabledHint reason="El pago se habilita cuando haya un importe pendiente."><button className="cp-btn" disabled>
                    Pagar
                  </button></DisabledHint>
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
            disabled={!payments?.can_pay || !payments?.pay_url}
            onClick={() => {
              if (payments?.can_pay && payments?.pay_url) window.location.href = payments.pay_url;
            }}
          >
            Pagar
          </button>
        </div>
      </div>
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

  if (loading) return <Skeleton title="Mensajes" rows={3} />;
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
              params.set("trip", String(it.expediente_id));
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
  const services = Array.isArray(detail?.services) ? detail.services : [];
  const invoices = Array.isArray(detail?.invoices) ? detail.invoices : [];
  const vouchers = Array.isArray(detail?.vouchers) ? detail.vouchers : [];

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

            {services.length === 0 ? (
              <div style={{ marginTop: 10 }} className="cp-meta">
                No hay servicios disponibles ahora mismo.
              </div>
            ) : (
              <div style={{ marginTop: 14, display: "flex", flexDirection: "column", gap: 10 }}>
                {services.map((s) => (
                  <div
                    key={s.id || `${s.type}-${s.title}`}
                    style={{
                      border: "1px solid rgba(0,0,0,0.08)",
                      borderRadius: 12,
                      padding: 12,
                      background: "#fff",
                    }}
                  >
                    <div style={{ display: "flex", justifyContent: "space-between", gap: 12 }}>
                      <div style={{ fontWeight: 700 }}>{s.title || "Servicio"}</div>
                      <div className="cp-chip">{(s.type || "servicio").toUpperCase()}</div>
                    </div>
                    <div style={{ marginTop: 6, fontSize: 13, opacity: 0.85 }}>
                      {s.type === "hotel" && (s.check_in || s.check_out) ? (
                        <span>
                          <span className="cp-strong">Hotel:</span> {formatDateES(s.check_in)} → {formatDateES(s.check_out)}
                          {typeof s.nights === "number" ? ` · ${s.nights} noches` : ""}
                          {s.board ? ` · ${s.board}` : ""}
                        </span>
                      ) : s.type === "golf" && (s.date || s.tee_time || s.course) ? (
                        <span>
                          <span className="cp-strong">Golf:</span> {s.course || s.title}
                          {s.date ? ` · ${formatDateES(s.date)}` : ""}
                          {s.tee_time ? ` · ${s.tee_time}` : ""}
                        </span>
                      ) : s.type === "transfer" && (s.from || s.to || s.date) ? (
                        <span>
                          <span className="cp-strong">Transfer:</span> {s.from || "—"} → {s.to || "—"}
                          {s.date ? ` · ${formatDateES(s.date)}` : ""}
                          {s.time ? ` · ${s.time}` : ""}
                        </span>
                      ) : s.notes ? (
                        <span>{s.notes}</span>
                      ) : (
                        <span>Detalles no disponibles.</span>
                      )}
                    </div>
                  </div>
                ))}
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
              <div style={{ marginTop: 14, display: "flex", flexWrap: "wrap", gap: 12 }}>
                <div className="cp-card" style={{ background: "#fff", flex: "1 1 240px" }}>
                  <div className="cp-meta">Total</div>
                  <div style={{ fontSize: 22, fontWeight: 800, marginTop: 6 }}>
                    {euro(payments.total, payments.currency || "EUR")}
                  </div>
                </div>

                <div className="cp-card" style={{ background: "#fff", flex: "1 1 240px" }}>
                  <div className="cp-meta">Pagado</div>
                  <div style={{ fontSize: 22, fontWeight: 800, marginTop: 6 }}>
                    {euro(payments.paid, payments.currency || "EUR")}
                  </div>
                </div>

                <div className="cp-card" style={{ background: "#fff", flex: "1 1 240px" }}>
                  <div className="cp-meta">Pendiente</div>
                  <div style={{ fontSize: 22, fontWeight: 800, marginTop: 6 }}>
                    {euro(payments.pending, payments.currency || "EUR")}
                  </div>
                </div>

                <div style={{ flex: "1 1 100%", display: "flex", justifyContent: "flex-end", marginTop: 6 }}>
                  <button
                    className="cp-btn primary"
                    style={{ whiteSpace: "nowrap" }}
                    disabled={!payments.can_pay || !payments.pay_url}
                    onClick={() => {
                      if (payments.can_pay && payments.pay_url) window.location.href = payments.pay_url;
                    }}
                  >
                    Pagar ahora
                  </button>
                </div>
              </div>
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
              <div style={{ marginTop: 14, display: "flex", flexDirection: "column", gap: 10 }}>
                {invoices.map((inv) => (
                  <a
                    key={inv.id}
                    href={inv.download_url}
                    style={{
                      display: "block",
                      textDecoration: "none",
                      border: "1px solid rgba(0,0,0,0.08)",
                      borderRadius: 12,
                      padding: 12,
                      background: "#fff",
                      color: "inherit",
                    }}
                  >
                    <div style={{ display: "flex", justifyContent: "space-between", gap: 12 }}>
                      <div style={{ fontWeight: 700 }}>{inv.title || "Factura"}</div>
                      <div style={{ fontSize: 12, opacity: 0.75 }}>{formatDateES(inv.date)}</div>
                    </div>
                    <div style={{ marginTop: 6, opacity: 0.85, fontSize: 13 }}>
                      {typeof inv.total === "number" ? euro(inv.total, inv.currency || "EUR") : "—"}
                    </div>
                  </a>
                ))}
              </div>
            )}
          </div>
        ) : null}

        {tab === "vouchers" ? (
          <div className="cp-card">
            <div className="cp-card-title">Bonos</div>
            <div className="cp-card-sub">Vouchers y documentación</div>
            {vouchers.length === 0 ? (
              <div style={{ marginTop: 10 }} className="cp-meta">No hay bonos disponibles.</div>
            ) : (
              <div style={{ marginTop: 14, display: "flex", flexDirection: "column", gap: 10 }}>
                {vouchers.map((v) => (
                  <div
                    key={v.id}
                    style={{
                      border: "1px solid rgba(0,0,0,0.08)",
                      borderRadius: 12,
                      padding: 12,
                      background: "#fff",
                      display: "flex",
                      justifyContent: "space-between",
                      gap: 12,
                      alignItems: "center",
                    }}
                  >
                    <div>
                      <div style={{ fontWeight: 700 }}>{v.title || "Bono"}</div>
                      <div style={{ fontSize: 12, opacity: 0.75 }}>
                        {v.issued ? "Emitido" : "No emitido"}
                      </div>
                    </div>
                    <button
                      className="cp-btn"
                      disabled={!v.issued || !v.download_url}
                      onClick={() => {
                        if (v.issued && v.download_url) window.location.href = v.download_url;
                      }}
                    >
                      Descargar
                    </button>
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
export default function App() {
  const [route, setRoute] = useState(readParams());
  const [dashboard, setDashboard] = useState(null);
  const [loadingDash, setLoadingDash] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [dashErr, setDashErr] = useState(null);

  const [inbox, setInbox] = useState(null);
  const [inboxErr, setInboxErr] = useState(null);

  const [inboxLatestTs, setInboxLatestTs] = useState(() => lsGetInt(LS_KEYS.inboxLatestTs, 0));
  const [messagesLastSeenTs, setMessagesLastSeenTs] = useState(() => lsGetInt(LS_KEYS.messagesLastSeenTs, 0));

  useEffect(() => {
    const onPop = () => setRoute(readParams());
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);

  async function loadDashboard() {
    const hadData = !!dashboard;
    try {
      if (hadData) setIsRefreshing(true);
      else setLoadingDash(true);

      setDashErr(null);
      const qs = route.mock ? "?mock=1" : "";
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
    return "Portal";
  }, [route.view]);

  const chip = route.mock ? "Modo prueba" : null;

  return (
    <div className="cp-app">
      <Sidebar view={route.view} unread={unreadCount} />
      <main className="cp-main">
        <Topbar title={title} chip={chip} onRefresh={loadDashboard} isRefreshing={isRefreshing} />

        {loadingDash && !dashboard ? (
          <div className="cp-content">
            <div className="cp-card" style={{ background: "#fff" }}>
              <div className="cp-card-title">{(route.view === "viajes" || route.view === "trips") ? "Tus viajes" : "Cargando"}</div>
              {(route.view === "viajes" || route.view === "trips") ? (
                <div style={{ marginTop: 14 }}>
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
            onOpen={(id) => {
              setParam("view", "trip");
              setParam("expediente", String(id));
              setParam("tab", "summary");
            }}
          />
        ) : route.view === "trip" && route.expediente ? (
          <TripDetailView mock={route.mock} expediente={route.expediente} dashboard={dashboard} onLatestTs={handleLatestTs} onSeen={markMessagesSeen} />
        ) : route.view === "inbox" ? (
          <InboxView mock={route.mock} inbox={inbox} loading={loadingDash} error={inboxErr} onLatestTs={handleLatestTs} onSeen={markMessagesSeen} />
        ) : (
          <div className="cp-content">
            <div className="cp-notice">Vista en construcción.</div>
          </div>
        )}
      </main>
    </div>
  );
}
