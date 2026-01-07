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
                  <button className="cp-btn" disabled>
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


  if (state.loading) return <div className="cp-notice">Cargando mensajes…</div>;
  if (state.error) {
    return (
      <div className="cp-notice is-warn">
        No se pueden cargar los datos ahora mismo. Intenta de nuevo en unos minutos.
      </div>
    );
  }

  if (items.length === 0) return <div className="cp-notice">No hay mensajes (o no disponibles ahora).</div>;

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

function InboxView({ mock, dashboard, onLatestTs, onSeen }) {
  const trips = Array.isArray(dashboard?.trips) ? dashboard.trips : [];
  const [state, setState] = useState({ loading: true, error: null, items: [] });

  useEffect(() => {
    let alive = true;

    async function load() {
      try {
        setState({ loading: true, error: null, items: [] });

        const results = await Promise.all(
          trips.map(async (t) => {
            try {
              const params = new URLSearchParams();
              if (mock) params.set("mock", "1");
              params.set("expediente", String(t.id));
              const d = await api(`/messages?${params.toString()}`);
              const items = Array.isArray(d?.items) ? d.items : [];
              if (items.length === 0) return null;

              const sorted = items
                .slice()
                .sort((a, b) => new Date(b?.date || 0).getTime() - new Date(a?.date || 0).getTime());
              const last = sorted[0];

              return {
                expediente_id: t.id,
                trip_title: t.title,
                trip_code: t.code,
                trip_status: t.status,
                date: last?.date,
                author: last?.author || (last?.direction === "agency" ? "Casanova Golf" : "Tú"),
                direction: last?.direction,
                content: last?.content || "",
              };
            } catch (e) {
              return null;
            }
          })
        );

        const cleaned = results
          .filter(Boolean)
          .sort((a, b) => new Date(b.date || 0).getTime() - new Date(a.date || 0).getTime());

        const latestTs = cleaned.length
          ? Math.max(...cleaned.map((x) => new Date(x?.date || 0).getTime()).filter((n) => Number.isFinite(n)))
          : 0;

        if (latestTs && typeof onLatestTs === "function") onLatestTs(latestTs);

        if (!alive) return;
        setState({ loading: false, error: null, items: cleaned });
      } catch (e) {
        if (!alive) return;
        setState({ loading: false, error: e, items: [] });
      }
    }

    load();
    return () => {
      alive = false;
    };
  }, [mock, dashboard]);

  const unreadCount = typeof dashboard?.messages?.unread === "number" ? dashboard.messages.unread : 0;

  useEffect(() => {
    if (typeof onSeen === "function") onSeen();
  }, [onSeen]);

  return (
    <div className="cp-content" style={{ maxWidth: 1200, width: "100%", margin: "0 auto" }}>
      <div className="cp-card" style={{ background: "#fff" }}>
        <div
          style={{
            display: "flex",
            alignItems: "center",
            justifyContent: "space-between",
            gap: 12,
            marginBottom: 12,
          }}
        >
          <div>
            <div className="cp-card-title" style={{ margin: 0 }}>
              Mensajes
            </div>
            <div className="cp-card-sub">Últimos mensajes por viaje</div>
          </div>
          {unreadCount > 0 ? (
            <div className="cp-chip">{unreadCount} sin leer</div>
          ) : (
            <div className="cp-chip">Al día</div>
          )}
        </div>

        {state.loading ? (
          <div className="cp-notice">Cargando mensajes…</div>
        ) : state.error ? (
          <div className="cp-notice is-warn">
            No se pueden cargar los datos ahora mismo. Intenta de nuevo en unos minutos.
          </div>
        ) : state.items.length === 0 ? (
          <div className="cp-notice">No hay mensajes disponibles ahora mismo.</div>
        ) : (
          <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
            {state.items.map((m) => (
              <button
                key={`${m.expediente_id}`}
                className="cp-inbox-row"
                onClick={() => {
                  setParam("view", "trip");
                  setParam("expediente", String(m.expediente_id));
                  setParam("tab", "messages");
                }}
                style={{
                  textAlign: "left",
                  border: "1px solid rgba(0,0,0,0.08)",
                  borderRadius: 12,
                  padding: 12,
                  background: "#fff",
                  cursor: "pointer",
                }}
              >
                <div
                  style={{
                    display: "flex",
                    justifyContent: "space-between",
                    gap: 12,
                    alignItems: "baseline",
                  }}
                >
                  <div style={{ fontWeight: 700 }}>
                    {m.trip_title || "Viaje"}{" "}
                    {m.trip_code ? (
                      <span style={{ fontWeight: 600, opacity: 0.75 }}>· {m.trip_code}</span>
                    ) : null}
                  </div>
                  <div style={{ fontSize: 12, opacity: 0.75 }}>{formatMsgDate(m.date)}</div>
                </div>

                <div
                  style={{
                    marginTop: 6,
                    display: "flex",
                    justifyContent: "space-between",
                    gap: 12,
                    alignItems: "center",
                  }}
                >
                  <div style={{ fontSize: 13, opacity: 0.85 }}>
                    <span style={{ fontWeight: 600 }}>{m.author}:</span>{" "}
                    <span style={{ opacity: 0.9 }}>
                      {m.content.length > 160 ? m.content.slice(0, 160) + "…" : m.content}
                    </span>
                  </div>
                  <div className="cp-chip" style={{ flex: "0 0 auto" }}>
                    {m.trip_status || "—"}
                  </div>
                </div>
              </button>
            ))}
          </div>
        )}
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
          <div className="cp-notice">Cargando expediente…</div>
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
              <div style={{ marginTop: 10 }} className="cp-meta">No hay información de pagos disponible.</div>
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
      const d = await api(`/dashboard${qs}`);
      setDashboard(d);
    } catch (e) {
      setDashErr(e);
    } finally {
      setIsRefreshing(false);
      setLoadingDash(false);
    }
  }

  useEffect(() => {
    loadDashboard();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [route.mock]);

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

  const unread = dashboard?.messages?.unread;
  const unreadFromServer = typeof unread === "number" ? unread : 0;

  const unreadCount =
    inboxLatestTs > 0 && messagesLastSeenTs >= inboxLatestTs ? 0 : unreadFromServer;

  const title = useMemo(() => {
    if (route.view === "trips") return "Viajes";
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
            <div className="cp-notice">Cargando…</div>
          </div>
        ) : dashErr ? (
          <div className="cp-content">
            <div className="cp-notice is-warn">
              No se pueden cargar los datos ahora mismo. Intenta de nuevo en unos minutos.
            </div>
          </div>
        ) : route.view === "trips" ? (
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
          <InboxView mock={route.mock} dashboard={dashboard} onLatestTs={handleLatestTs} onSeen={markMessagesSeen} />
        ) : (
          <div className="cp-content">
            <div className="cp-notice">Vista en construcción.</div>
          </div>
        )}
      </main>
    </div>
  );
}
