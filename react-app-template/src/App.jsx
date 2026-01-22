/*
  App.portal.viajes-detalle-tabs.jsx
  - Viajes: listado con tabla rica (ancho ampliado + fechas ES)
  - Viaje: vista detalle con breadcrumb + header + tabs (Resumen/Pagos/Facturas/Bonos/Mensajes)
  - Mensajes: timeline por expediente (usa /messages?expediente=ID)
*/

import React, { useEffect, useMemo, useRef, useState } from "react";

/* ===== i18n (WPML via PHP localized dict) =====
   WPML does not translate bundled JS automatically.
   We inject translated strings from PHP into window.CASANOVA_I18N.
*/
const __I18N__ = (typeof window !== "undefined" && window.CASANOVA_I18N) ? window.CASANOVA_I18N : {};

function t(key, fallback = "") {
  const v = __I18N__?.[key];
  return (typeof v === "string" && v.length) ? v : fallback;
}

function tf(key, fallback = "", vars = {}) {
  const s = t(key, fallback);
  return s.replace(/\{(\w+)\}/g, (_, name) => (vars[name] ?? ""));
}

// Hash-based translator: avoids manually inventing keys.
// Keys are computed in JS and in PHP (same algorithm) so WPML can translate them.
function __hashKey(str) {
  // djb2 32-bit
  let h = 5381;
  for (let i = 0; i < str.length; i++) {
    h = ((h << 5) + h) + str.charCodeAt(i);
    h = h >>> 0;
  }
  return "s_" + h.toString(16);
}

function tt(literal, fallback = null) {
  const key = __hashKey(String(literal ?? ""));
  const fb = fallback === null ? String(literal ?? "") : String(fallback ?? "");
  return t(key, fb);
}

function ttf(literal, vars = {}, fallback = null) {
  const s = tt(literal, fallback);
  return s.replace(/\{(\w+)\}/g, (_, name) => (vars[name] ?? ""));
}

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

function Notice({ variant = "info", title, children, action, className = "", onClose, closeLabel = t('close', 'Cerrar') }) {
  return (
    <div className={`cp-notice2 is-${variant} ${className}`.trim()}>
      <div className="cp-notice2__body">
        {title ? <div className="cp-notice2__title">{title}</div> : null}
        <div className="cp-notice2__text">{children}</div>
      </div>
      <div className="cp-notice2__action">
        {action ? <div className="cp-notice2__action-inner">{action}</div> : null}
        {typeof onClose === "function" ? (
          <button type="button" className="cp-notice2__close" onClick={onClose} aria-label={closeLabel} title={closeLabel}>
            ×
          </button>
        ) : null}
      </div>
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

const KPI_ICON_PROPS = {
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

function IconPlane() {
  return (
    <svg
      viewBox="0 0 122.88 122.88"
      width={22}
      height={22}
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
    >
      <path
        fill="currentColor"
        fillRule="evenodd"
        clipRule="evenodd"
        d="M16.63,105.75c0.01-4.03,2.3-7.97,6.03-12.38L1.09,79.73c-1.36-0.59-1.33-1.42-0.54-2.4l4.57-3.9
		c0.83-0.51,1.71-0.73,2.66-0.47l26.62,4.5l22.18-24.02L4.8,18.41c-1.31-0.77-1.42-1.64-0.07-2.65l7.47-5.96l67.5,18.97L99.64,7.45
		c6.69-5.79,13.19-8.38,18.18-7.15c2.75,0.68,3.72,1.5,4.57,4.08c1.65,5.06-0.91,11.86-6.96,18.86L94.11,43.18l18.97,67.5
		l-5.96,7.47c-1.01,1.34-1.88,1.23-2.65-0.07L69.43,66.31L45.41,88.48l4.5,26.62c0.26,0.94,0.05,1.82-0.47,2.66l-3.9,4.57
		c-0.97,0.79-1.81,0.82-2.4-0.54l-13.64-21.57c-4.43,3.74-8.37,6.03-12.42,6.03C16.71,106.24,16.63,106.11,16.63,105.75
		L16.63,105.75z"
      />
    </svg>
  );
}

function IconBriefcase() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <rect x={5} y={9} width={14} height={12} rx={2} stroke="currentColor" strokeWidth={1.5} fill="none" />
      <path d="M8 9V7h8v2" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M6 13h12" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
    </svg>
  );
}

function IconShieldCheck() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M12 3l6 3v6c0 4-3 7-6 8-3-1-6-4-6-8V6z" fill="none" stroke="currentColor" strokeWidth={1.5} />
      <polyline
        points="9.5 12 11.5 14.5 15.5 10.5"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.5}
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconClockArrow() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <circle cx={12} cy={12} r={8} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <path d="M12 8v4l3 2" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function IconSparkle() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M12 4l2 4 4 .5-3 2.9 1 4.7-4-2-4 2 1-4.7-3-2.9 4-.5z" fill="currentColor" />
    </svg>
  );
}

function IconChartBar() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <rect x={5} y={11} width={3} height={8} fill="currentColor" />
      <rect x={10.5} y={7} width={3} height={12} fill="currentColor" />
      <rect x={16} y={4} width={3} height={15} fill="currentColor" />
    </svg>
  );
}

function IconCalendar() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <rect x={4} y={5} width={16} height={15} rx={3} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <line x1={4} y1={9} x2={20} y2={9} stroke="currentColor" strokeWidth={1.5} />
      <line x1={7} y1={2} x2={7} y2={7} stroke="currentColor" strokeWidth={1.5} />
      <line x1={17} y1={2} x2={17} y2={7} stroke="currentColor" strokeWidth={1.5} />
    </svg>
  );
}

function IconStar() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M12 3l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 17l-5.2 2.5 1-5.8-4.2-4.1 5.8-.8z" fill="currentColor" />
    </svg>
  );
}

function IconGlobe() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <circle cx={12} cy={12} r={9} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <path d="M3 12h18" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M12 3c3 3 3 15 0 18" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M12 3c-3 3-3 15 0 18" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
    </svg>
  );
}

function IconUser() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <circle cx={12} cy={9} r={3.2} fill="none" stroke="currentColor" strokeWidth={1.5} />
      <path d="M6.2 20c1.6-3 4-4.5 5.8-4.5s4.2 1.5 5.8 4.5" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
    </svg>
  );
}

function IconLogout() {
  return (
    <svg {...KPI_ICON_PROPS} aria-hidden="true">
      <path d="M10 7V6a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-6a2 2 0 0 1-2-2v-1" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M3 12h9" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" />
      <path d="M7 8l-4 4 4 4" fill="none" stroke="currentColor" strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

function KpiCard({ icon, label, value, colorClass = "" }) {
  return (
    <div className="cp-kpi-card">
      <span className={`cp-kpi-card__icon ${colorClass}`} aria-hidden="true">
        {icon}
      </span>
      <div>
        <div className="cp-kpi-card__label">{label}</div>
        <div className="cp-kpi-card__value">{value}</div>
      </div>
    </div>
  );
}

function BadgeLabel({ label, variant = "info", className = "" }) {
  if (!label) return null;
  const base = `cp-badge cp-badge--${variant}`.trim();
  return <span className={`${base} ${className}`.trim()}>{label}</span>;
}

function getStatusVariant(status) {
  const value = String(status || "").toLowerCase();
  if (!value) return "muted";
  if (/(pag|ok|listo)/.test(value)) return "success";
  if (/pend/.test(value)) return "warning";
  if (/(cancel|rech|fail|error)/.test(value)) return "danger";
  return "info";
}

function getPaymentVariant(pendingAmount, hasPayments) {
  if (!hasPayments) return "muted";
  if (pendingAmount <= 0.01) return "success";
  if (pendingAmount > 0) return "warning";
  return "info";
}

function getBonusesVariant(available) {
  if (available === null) return "muted";
  return available ? "success" : "warning";
}

function getInvoiceVariant(status) {
  const value = String(status || "").toLowerCase();
  if (value.includes("pend")) return "warning";
  if (/(pag|ok)/.test(value)) return "success";
  if (/(cancel|fail|rech)/.test(value)) return "danger";
  return "info";
}

function getHistoryBadge(row) {
  const label = row.is_refund ? "Reembolso" : row.type ? row.type : "Cobro";
  const variant = row.is_refund ? "danger" : "success";
  return { label, variant };
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
          <div className="cp-brand-title">{tt("Casanova Portal")}</div>
          <div className="cp-brand-sub">{tt("Gestión de Reservas")}</div>
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
        {tt("Soporte")}
        <div style={{ marginTop: 6 }}>{tt("Si necesitas algo, escríbenos desde Mensajes.")}</div>
      </div>
    </aside>
  );
}

function Topbar({ title, chip, onRefresh, isRefreshing, profile, onGo, onLogout, onLocale }) {
  return (
    <div className="cp-topbar">
      <div className="cp-topbar-inner">
        <div className="cp-title">{title}</div>
        <div className="cp-actions">
          {chip ? <div className="cp-chip">{chip}</div> : null}
          {isRefreshing ? <div className="cp-chip">{tt("Actualizando…")}</div> : null}
          <button className="cp-btn" onClick={onRefresh}>
            {tt("Actualizar")}
          </button>
          <LanguageMenu locale={profile?.locale} onLocale={onLocale} />
          <UserMenu profile={profile} onGo={onGo} onLogout={onLogout} />
        </div>
      </div>
    </div>
  );
}

function LanguageMenu({ locale, onLocale }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const current = locale || "es_ES";

  useEffect(() => {
    function onDocClick(e) {
      if (!open) return;
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener("mousedown", onDocClick);
    return () => document.removeEventListener("mousedown", onDocClick);
  }, [open]);

  const items = [
    { value: "es_ES", label: "ES", name: "Español" },
    { value: "en_US", label: "EN", name: "English" },
  ];
  const active = items.find((i) => i.value === current) || items[0];

  return (
    <div className="cp-lang" ref={ref}>
      <button type="button" className="cp-lang-btn" onClick={() => setOpen((v) => !v)} aria-haspopup="menu" aria-expanded={open ? "true" : "false"} title={active.name}>
        <span className="cp-lang-ico" aria-hidden="true"><IconGlobe /></span>
        <span className="cp-lang-label">{active.label}</span>
      </button>
      {open ? (
        <div className="cp-lang-menu" role="menu">
          {items.map((it) => (
            <button
              key={it.value}
              type="button"
              className={`cp-lang-item ${it.value === current ? "is-active" : ""}`}
              onClick={() => {
                setOpen(false);
                if (typeof onLocale === "function") onLocale(it.value);
              }}
              role="menuitem"
            >
              <span className="cp-lang-item-label">{it.name}</span>
              {it.value === current ? <span className="cp-lang-check" aria-hidden="true">✓</span> : null}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

function initials(name) {
  const n = String(name || "").trim();
  if (!n) return "U";
  const parts = n.split(/\s+/).filter(Boolean);
  const a = parts[0]?.[0] || "U";
  const b = parts.length > 1 ? parts[parts.length - 1]?.[0] : "";
  return (a + b).toUpperCase();
}

function UserMenu({ profile, onGo, onLogout }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    function onDocClick(e) {
      if (!open) return;
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener("mousedown", onDocClick);
    return () => document.removeEventListener("mousedown", onDocClick);
  }, [open]);

  const name = profile?.user?.displayName || profile?.giav?.nombre || "";
  const email = profile?.user?.email || profile?.giav?.email || "";
  const avatarUrl = profile?.user?.avatarUrl || "";

  return (
    <div className="cp-user" ref={ref}>
      <button type="button" className="cp-user-btn" onClick={() => setOpen((v) => !v)} aria-haspopup="menu" aria-expanded={open ? "true" : "false"}>
        {avatarUrl ? (
          <img className="cp-user-avatar" src={avatarUrl} alt="" />
        ) : (
          <div className="cp-user-avatar is-fallback" aria-hidden="true">{initials(name)}</div>
        )}
      </button>

      {open ? (
        <div className="cp-user-menu" role="menu">
          <div className="cp-user-head">
            {avatarUrl ? (
              <img className="cp-user-avatar" src={avatarUrl} alt="" />
            ) : (
              <div className="cp-user-avatar is-fallback" aria-hidden="true">{initials(name)}</div>
            )}
            <div>
              <div className="cp-user-name">{name || t('account_label', 'Tu cuenta')}</div>
              {email ? <div className="cp-user-email">{email}</div> : null}
            </div>
          </div>

          <button type="button" className="cp-user-item" onClick={() => { setOpen(false); onGo("profile"); }} role="menuitem">
            <span className="cp-user-item-ico" aria-hidden="true"><IconUser /></span>
            {t('menu_profile', 'Mi perfil')}
          </button>
          <button type="button" className="cp-user-item" onClick={() => { setOpen(false); onGo("security"); }} role="menuitem">
            <span className="cp-user-item-ico" aria-hidden="true"><IconShieldCheck /></span>
            {t('menu_security', 'Seguridad')}
          </button>

          <div className="cp-user-sep" />
          <button type="button" className="cp-user-item is-danger" onClick={() => { setOpen(false); onLogout(); }} role="menuitem">
            <span className="cp-user-item-ico" aria-hidden="true"><IconLogout /></span>
            {t('menu_logout', 'Cerrar sesión')}
          </button>
        </div>
      ) : null}
    </div>
  );
}

function Field({ label, children, help }) {
  return (
    <div className="cp-field">
      <div className="cp-label">{label}</div>
      {children}
      {help ? <div className="cp-help">{help}</div> : null}
    </div>
  );
}

function ProfileView({ profile, onSave, onLocale }) {
  const giav = profile?.giav || {};
  const [form, setForm] = useState(() => ({
    telefono: giav.telefono || "",
    movil: giav.movil || "",
    direccion: giav.direccion || "",
    codPostal: giav.codPostal || "",
    poblacion: giav.poblacion || "",
    provincia: giav.provincia || "",
    pais: giav.pais || "",
  }));

  useEffect(() => {
    setForm({
      telefono: giav.telefono || "",
      movil: giav.movil || "",
      direccion: giav.direccion || "",
      codPostal: giav.codPostal || "",
      poblacion: giav.poblacion || "",
      provincia: giav.provincia || "",
      pais: giav.pais || "",
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [profile?.giav?.direccion, profile?.giav?.telefono, profile?.giav?.movil, profile?.giav?.codPostal, profile?.giav?.poblacion, profile?.giav?.provincia, profile?.giav?.pais]);

  const locale = profile?.locale || "";

  return (
    <div className="cp-content">
      <div className="cp-card" style={{ background: "#fff" }}>
        <div className="cp-card-title">{tt("Información personal")}</div>

        <div className="cp-grid2">
          <Field label="Nombre">
            <input className="cp-input" value={`${giav.nombre || ""} ${giav.apellidos || ""}`.trim() || "—"} disabled />
          </Field>
          <Field label="Email">
            <input className="cp-input" value={giav.email || profile?.user?.email || "—"} disabled />
          </Field>
        </div>

        <div className="cp-grid2">
          <Field label="Teléfono">
            <input className="cp-input" value={form.telefono} onChange={(e) => setForm((s) => ({ ...s, telefono: e.target.value }))} placeholder="" />
          </Field>
          <Field label="Móvil">
            <input className="cp-input" value={form.movil} onChange={(e) => setForm((s) => ({ ...s, movil: e.target.value }))} placeholder="" />
          </Field>
        </div>

        <div className="cp-divider" />
        <div className="cp-card-subtitle">{tt("Dirección")}</div>

        <Field label="Dirección">
          <input className="cp-input" value={form.direccion} onChange={(e) => setForm((s) => ({ ...s, direccion: e.target.value }))} />
        </Field>

        <div className="cp-grid2">
          <Field label="Código postal">
            <input className="cp-input" value={form.codPostal} onChange={(e) => setForm((s) => ({ ...s, codPostal: e.target.value }))} />
          </Field>
          <Field label="Población">
            <input className="cp-input" value={form.poblacion} onChange={(e) => setForm((s) => ({ ...s, poblacion: e.target.value }))} />
          </Field>
        </div>

        <div className="cp-grid2">
          <Field label="Provincia">
            <input className="cp-input" value={form.provincia} onChange={(e) => setForm((s) => ({ ...s, provincia: e.target.value }))} />
          </Field>
          <Field label="País" help="(Opcional, según datos de facturación)">
            <input className="cp-input" value={form.pais} onChange={(e) => setForm((s) => ({ ...s, pais: e.target.value }))} />
          </Field>
        </div>

        <div className="cp-actions-row">
          <button className="cp-btn-primary" type="button" onClick={() => onSave(form)}>
            {tt("Guardar")}
          </button>
        </div>
      </div>

      <div className="cp-card" style={{ background: "#fff" }}>
        <div className="cp-card-title">{t('portal_language', 'Idioma del portal')}</div>
        <div className="cp-row" style={{ gap: 12, alignItems: "center" }}>
          <select className="cp-input" value={locale} onChange={(e) => onLocale(e.target.value)} style={{ maxWidth: 280 }}>
            <option value="es_ES">{tt("Español")}</option>
            <option value="en_US">{tt("English")}</option>
          </select>
          <div className="cp-help">{tt("Esto solo afecta al portal.")}</div>
        </div>
      </div>
    </div>
  );
}

function SecurityView({ onChangePassword }) {
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [confirm, setConfirm] = useState("");

  return (
    <div className="cp-content">
      <div className="cp-card" style={{ background: "#fff" }}>
        <div className="cp-card-title">{tt("Cambiar contraseña")}</div>
        <div className="cp-help" style={{ marginTop: -6, marginBottom: 18 }}>
          {tt("Tu contraseña es tu llave digital. No la compartas, aunque a los humanos les encante hacerlo.")}
        </div>

        <Field label="Contraseña actual">
          <input className="cp-input" type="password" value={current} onChange={(e) => setCurrent(e.target.value)} />
        </Field>
        <Field label="Nueva contraseña">
          <input className="cp-input" type="password" value={next} onChange={(e) => setNext(e.target.value)} />
        </Field>
        <Field label="Confirmar nueva contraseña">
          <input className="cp-input" type="password" value={confirm} onChange={(e) => setConfirm(e.target.value)} />
        </Field>

        <div className="cp-actions-row">
          <button className="cp-btn-primary" type="button" onClick={() => onChangePassword({ current, next, confirm })}>
            {tt("Actualizar")}
          </button>
        </div>
      </div>
    </div>
  );
}

/* ===== Views ===== */
function buildFallbackYears() {
  const currentYear = new Date().getFullYear();
  const minYear = Math.max(2015, currentYear - 5);
  const years = [];
  for (let year = currentYear + 1; year >= minYear; year -= 1) {
    years.push(String(year));
  }
  return years;
}

function TripsList({ mock, onOpen, dashboard }) {
  const [year, setYear] = useState(String(new Date().getFullYear()));
  const [years, setYears] = useState(() => buildFallbackYears());
  const [trips, setTrips] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    if (mock) return;
    let active = true;
    setLoading(true);
    setError("");
    (async () => {
      try {
        const params = new URLSearchParams();
        if (year) {
          params.set("year", year);
        }
        const query = params.toString();
        const data = await api(`/expedientes${query ? `?${query}` : ""}`);
        if (!active) return;
        setTrips(Array.isArray(data.items) ? data.items : []);
        const serverYears = Array.isArray(data.years) && data.years.length
          ? data.years.map((value) => String(value))
          : buildFallbackYears();
        setYears(serverYears);
        if (serverYears.length && !serverYears.includes(year)) {
          setYear(serverYears[0]);
        }
      } catch (err) {
        if (!active) return;
        setError(err?.message || "No se han podido cargar los viajes.");
        setTrips([]);
      } finally {
        if (active) setLoading(false);
      }
    })();
    return () => {
      active = false;
    };
  }, [year, mock]);

  useEffect(() => {
    if (!mock) return;
    const mockTrips = Array.isArray(dashboard?.trips) ? dashboard.trips : [];
    setTrips(mockTrips);
    setError("");
    setLoading(false);
    const extractedYears = Array.from(
      new Set(
        mockTrips
          .map((t) => (t?.date_range || "").match(/(\d{4})/g)?.[0])
          .filter(Boolean)
      )
    ).sort((a, b) => b.localeCompare(a));
    if (extractedYears.length) {
      setYears(extractedYears);
      if (!extractedYears.includes(year)) {
        setYear(extractedYears[0]);
      }
    } else {
      setYears(buildFallbackYears());
    }
  }, [mock, dashboard, year]);

  const hasTrips = trips.length > 0;

  return (
    <div className="cp-content" style={{ maxWidth: 1600, width: "100%", margin: "0 auto", paddingTop: 8 }}>
      <div className="cp-card" style={{ background: "#fff" }}>
        <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, marginBottom: 12 }}>
          <div className="cp-card-title" style={{ margin: 0 }}>{tt("Tus viajes")}</div>
          <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
            <div className="cp-meta"><span className="cp-strong">{tt("Año:")}</span></div>
            <select
              value={year}
              onChange={(e) => setYear(e.target.value)}
              className="cp-select"
              style={{ padding: "8px 10px", borderRadius: 10, border: "1px solid rgba(0,0,0,0.12)", background: "#fff" }}
            >
              {years.map((optionYear) => (
                <option key={optionYear} value={optionYear}>{optionYear}</option>
              ))}
            </select>
          </div>
        </div>
        {error ? (
          <Notice variant="warn" title={tt("Error al cargar los viajes")}>
            {error}
          </Notice>
        ) : null}
        <div className="cp-table-wrap" style={{ marginTop: 14 }}>
          {loading ? (
            <TableSkeleton rows={6} cols={9} />
          ) : (
            <table width="100%" cellPadding="10" style={{ borderCollapse: "collapse" }}>
              <thead>
                <tr style={{ textAlign: "left", borderBottom: "1px solid #ddd" }}>
                  <th style={{ width: 120 }}>{tt("Expediente")}</th>
                  <th>{tt("Viaje")}</th>
                  <th style={{ width: 140 }}>{tt("Inicio")}</th>
                  <th style={{ width: 140 }}>{tt("Fin")}</th>
                  <th style={{ width: 120 }}>{tt("Estado")}</th>
                  <th style={{ width: 110, textAlign: "right" }}>{tt("Total")}</th>
                  <th style={{ width: 160 }}>{tt("Pagos")}</th>
                  <th style={{ width: 120 }}>{tt("Bonos")}</th>
                  <th style={{ width: 180 }}></th>
                </tr>
              </thead>
              <tbody>
                {hasTrips ? (
                  trips.map((t) => {
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
                    const currencyForTrip = payments?.currency || "EUR";
                    const totalLabel = hasPayments ? euro(totalAmount, currencyForTrip) : "-";
                    const paymentsLabelText = hasPayments
                      ? (pendingAmount <= 0.01 ? tt("Pagado") : tt("Pendiente"))
                      : tt("Sin datos");
                    const paymentsVariant = getPaymentVariant(
                      Number.isFinite(pendingAmount) ? pendingAmount : Number.NaN,
                      hasPayments
                    );
                    const bonusesAvailable = typeof t?.bonuses?.available === "boolean" ? t.bonuses.available : null;
                    const bonusesLabel = bonusesAvailable === null
                      ? ""
                      : (bonusesAvailable ? tt("Disponibles") : tt("No disponibles"));
                    const bonusesVariant = getBonusesVariant(bonusesAvailable);
                    const statusLabel = t.status || "";
                    const statusVariant = getStatusVariant(statusLabel);
                    return (
                      <tr key={t.id} style={{ borderBottom: "1px solid #eee" }}>
                        <td>{t.code || `#${t.id}`}</td>
                        <td>
                          <div style={{ fontWeight: 600 }}>{t.title}</div>
                          <div style={{ fontSize: 12, opacity: 0.75 }}>{t.code ? ttf("ID {id}", { id: t.id }) : ttf("Expediente {id}", { id: t.id })}</div>
                        </td>
                        <td>{formatDateES(r.start)}</td>
                        <td>{formatDateES(r.end)}</td>
                        <td>
                          <BadgeLabel label={statusLabel} variant={statusVariant} />
                        </td>
                        <td style={{ textAlign: "right" }}>{totalLabel}</td>
                        <td>
                          <div className="cp-trip-payments-info">
                            <BadgeLabel label={paymentsLabelText} variant={paymentsVariant} />
                            {hasPayments && Number.isFinite(paidAmount) ? (
                              <div className="cp-trip-paid-amount">{tt("Pagado:")} {euro(paidAmount)}</div>
                            ) : null}
                          </div>
                        </td>
                        <td>
                          <BadgeLabel label={bonusesLabel} variant={bonusesVariant} />
                        </td>
                        <td style={{ display: "flex", gap: 10, justifyContent: "flex-end" }}>
                          <button className="cp-btn primary" style={{ whiteSpace: "nowrap" }} onClick={() => onOpen(t.id)}>
                            {tt("Ver detalle")}
                          </button>
                          <button
                            className="cp-btn"
                            style={{ whiteSpace: "nowrap" }}
                            onClick={() => onOpen(t.id, "payments")}
                          >
                            {tt("Pagar")}
                          </button>
                        </td>
                      </tr>
                    );
                  })
                ) : (
                  <tr>
                    <td colSpan={9} style={{ padding: 18, opacity: 0.8 }}>
                      {tt("No hay viajes disponibles para el año seleccionado.")}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
function Tabs({ tab, onTab }) {
  const items = [
    { k: "summary", label: tt("Resumen") },
    { k: "payments", label: tt("Pagos") },
    { k: "invoices", label: tt("Facturas") },
    { k: "vouchers", label: tt("Bonos") },
    { k: "messages", label: tt("Mensajes") },
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

function weatherIconFor(code) {
  const c = Number(code);
  if (!Number.isFinite(c)) return "";
  if (c === 0) return "☀️";
  if (c >= 1 && c <= 3) return "⛅";
  if (c === 45 || c === 48) return "🌫️";
  if (c >= 51 && c <= 57) return "🌦️";
  if (c >= 61 && c <= 67) return "🌧️";
  if (c >= 71 && c <= 77) return "🌨️";
  if (c >= 80 && c <= 82) return "🌧️";
  if (c >= 95) return "⛈️";
  return "🌤️";
}

function weekdayShortES(isoDate) {
  if (!isoDate) return "";
  try {
    const d = new Date(String(isoDate));
    return new Intl.DateTimeFormat("es-ES", { weekday: "short" }).format(d);
  } catch {
    return "";
  }
}

function TripWeather({ weather }) {
  const days = Array.isArray(weather?.daily) ? weather.daily : [];
  const slice = days.slice(0, 5);
  const provider = String(weather?.provider || "");
  if (!slice.length) return null;

  const title = "Previsión en destino";

  function iconNode(d) {
    const base = d?.icon_base_uri || d?.iconBaseUri || "";
    if (base && provider === "google-weather") {
      // Google Weather icons: iconBaseUri + (.svg) per docs.
      const src = String(base).endsWith(".svg") ? String(base) : String(base) + ".svg";
      return <img className="cp-weather__icon-img" src={src} alt="" loading="lazy" />;
    }
    return <span aria-hidden="true">{weatherIconFor(d?.code)}</span>;
  }

  return (
    <div className="cp-weather" title={title}>
      <div className="cp-weather__title">{tt("Tiempo")}</div>
      <div className="cp-weather__row">
        {slice.map((d, idx) => {
          const tmin = Number(d?.t_min);
          const tmax = Number(d?.t_max);
          return (
            <div key={idx} className="cp-weather__day">
              <div className="cp-weather__dow">{weekdayShortES(d?.date)}</div>
              <div className="cp-weather__icon">{iconNode(d)}</div>
              <div className="cp-weather__temp">
                {Number.isFinite(tmax) ? Math.round(tmax) : "–"}° /{" "}
                {Number.isFinite(tmin) ? Math.round(tmin) : "–"}°
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function TripHeader({ trip, payments, map, weather, itineraryUrl }) {
  const r = normalizeTripDates(trip);
  return (
    <div className="cp-card" style={{ marginTop: 14 }}>
      <div className="cp-card-header">
        <div>
          <div className="cp-card-title" style={{ fontSize: 20 }}>
            {trip?.title || tt("Viaje")}
          </div>
          <div className="cp-card-sub">
            {trip?.code || ttf("Expediente #{id}", { id: trip?.id || "—" })} · {trip?.status || "—"}
          </div>
        </div>
        <div className="cp-trip-head__meta-actions">
          <div className="cp-trip-head__meta">
            <span className="cp-strong">{tt("Fechas:")}</span> {formatDateES(r.start)} – {formatDateES(r.end)}
          </div>
          <div className="cp-trip-head__actions">
            {map?.url ? (
              <a
                className="cp-btn"
                href={String(map.url)}
                target="_blank"
                rel="noreferrer"
                title={map?.type === "route" ? tt("Ver ruta en Google Maps") : tt("Ver mapa en Google Maps")}
              >
                {map?.type === "route" ? tt("Ver ruta") : tt("Ver mapa")}
              </a>
            ) : null}
            <button
              className="cp-btn"
              onClick={() => {
                setParam("tab", "payments");
              }}
            >
              {tt("Ver pagos")}
            </button>
            {itineraryUrl ? (
              <a
                className="cp-btn cp-btn--ghost"
                href={itineraryUrl}
                target="_blank"
                rel="noreferrer noopener"
              >
                {tt("Programa del viaje (PDF)")}
              </a>
            ) : null}
            <TripWeather weather={weather} />
          </div>
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
          : error?.message || error?.msg || error?.code || tt("No se pudo iniciar el pago.");
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
              ? tt("Redirigiendo…")
              : ttf("Pagar depósito ({amount})", { amount: euro(depositAmount, currency) })}
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
              ? tt("Redirigiendo…")
              : ttf("Pagar pendiente ({amount})", { amount: euro(balanceAmount, currency) })}
          </button>
        ) : null}

        {!hasActions && !isPaidLocal ? (
          <div className="cp-meta" style={{ alignSelf: "center" }}>
            {tt("Aún no hay pagos disponibles para este viaje.")}
          </div>
        ) : null}
      </div>

      {state.error ? (
        <Notice variant="error" title={tt("No se puede iniciar el pago")}>
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


  if (state.loading) return (<div className="cp-card"><div className="cp-card-title">{tt("Cargando mensajes")}</div><Skeleton lines={6} /></div>);
  if (state.error) {
    return (
      <div className="cp-notice is-warn">
        {tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}
      </div>
    );
  }

  if (items.length === 0) return <EmptyState title={tt("No hay mensajes disponibles")} icon="💬">{tt("Si te escribimos, lo verás aquí al momento.")}</EmptyState>;

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

  if (loading) return (<div className="cp-card"><div className="cp-card-title">{tt("Mensajes")}</div><Skeleton lines={6} /></div>);
  if (error)
    return (
      <div className="cp-card">
        <div className="cp-card-title">{tt("Mensajes")}</div>
        <Notice variant="error" title={tt("No se pueden cargar los mensajes")}>{tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}</Notice>
      </div>
    );

  const status = inbox?.status === "mock" || mock ? "mock" : "ok";

  if (!sorted.length)
    return (
      <div className="cp-card">
        <div className="cp-card-title">{tt("Mensajes")}</div>
        <EmptyState title={tt("No hay mensajes nuevos")} icon="✅">{tt("Si te escribimos, lo verás aquí al momento.")}</EmptyState>
      </div>
    );

  return (
    <div className="cp-card">
      <div className="cp-card-title">{tt("Mensajes")}</div>
      {status === "mock" ? <div className="cp-chip">{tt("Modo prueba")}</div> : null}

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
                {it.trip_title || tt("Viaje")}{" "}
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
  const serviceType = (detail.type || service.type || "").toUpperCase();
  const isPlaneService = serviceType === "AV";
  const semanticType = String(service.semantic_type || "").toLowerCase();
  const detailPayload = detail.details || service.details || {};
  const segments = Array.isArray(detailPayload.segments) ? detailPayload.segments : [];

  const shouldShowRow = (expectedType) => !semanticType || semanticType === expectedType;
  const extraDetailRows = [
    {
      key: "rooms",
      label: "Habitaciones",
      value: detailPayload.rooms,
      show: shouldShowRow("hotel"),
    },
    {
      key: "board",
      label: "Régimen",
      value: detailPayload.board,
      show: shouldShowRow("hotel"),
    },
    {
      key: "rooming",
      label: "Rooming",
      value: detailPayload.rooming,
      show: shouldShowRow("hotel"),
    },
    {
      key: "players",
      label: "Jugadores",
      value: detailPayload.players,
      show: shouldShowRow("golf"),
    },
    {
      key: "route",
      label: "Trayecto",
      value: detailPayload.route,
      show: shouldShowRow("flight"),
    },
    {
      key: "flight_code",
      label: "Código de vuelo",
      value: detailPayload.flight_code,
      show: shouldShowRow("flight"),
    },
    {
      key: "schedule",
      label: "Horario",
      value: detailPayload.schedule,
      show: shouldShowRow("flight"),
    },
    {
      key: "passengers",
      label: "Pasajeros",
      value: detailPayload.passengers,
      show: shouldShowRow("flight"),
    },
  ].filter((row) => row.show && row.value !== undefined && row.value !== null && String(row.value).trim() !== "");

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
        <div className="cp-service__title">
          {isPlaneService ? (
            <span className="cp-service__title-icon" aria-hidden="true">
              <IconPlane />
            </span>
          ) : null}
          <span>{service.title || "Servicio"}</span>
        </div>
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
              {tt("Detalle")}
            </button>
            {canVoucher && viewUrl ? (
              <a className="cp-btn cp-btn--ghost" href={viewUrl} target="_blank" rel="noreferrer">
                {tt("Ver bono")}
              </a>
            ) : (
              <span className="cp-btn cp-btn--ghost cp-btn--disabled">{tt("Bono")}</span>
            )}
            {canPdf && pdfUrl ? (
              <a className="cp-btn cp-btn--ghost" href={pdfUrl} target="_blank" rel="noreferrer">
                {tt("PDF")}
              </a>
            ) : (
              <span className="cp-btn cp-btn--ghost cp-btn--disabled">{tt("PDF")}</span>
            )}
          </div>
        </div>
      </div>
      {open ? (
        <div className="cp-service__detail">
          <div className="cp-service__kv">
            {detail.code || service.id ? (
              <div>
                <strong>{tt("Código:")}</strong> {detail.code || service.id}
              </div>
            ) : null}
            {detail.type ? (
              <div>
                <strong>{tt("Tipo:")}</strong> {detail.type}
              </div>
            ) : null}
            <div>
              <strong>{tt("Fechas:")}</strong> {service.date_range || "—"}
            </div>
            {detail.locator ? (
              <div>
                <strong>{tt("Localizador:")}</strong> {detail.locator}
              </div>
            ) : null}
            {price != null ? (
              <div>
                <strong>{tt("PVP:")}</strong> {euro(price)}
              </div>
            ) : null}
            {extraDetailRows.map((row) => (
              <div key={row.key}>
                <strong>{row.label}:</strong> {row.value}
              </div>
            ))}
          </div>
          {segments.length > 0 ? (
            <>
              <div className="cp-service__divider" />
              <div>
                <strong>{tt("Segmentos:")}</strong>
                <ul className="cp-service__bonus">
                  {segments.map((segment, index) => (
                    <li key={`${segment}-${index}`}>{segment}</li>
                  ))}
                </ul>
              </div>
            </>
          ) : null}
          {bonusText ? (
            <>
              <div className="cp-service__divider" />
              <div>
                <strong>{tt("Texto adicional (bono):")}</strong>
                <p className="cp-service__bonus">{bonusText}</p>
              </div>
            </>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

  function getServiceSortKey(service) {
    if (!service) return "";
    const candidate = service.detail?.code ?? service.id;
    if (candidate === null || candidate === undefined) return "";
    return String(candidate).trim();
  }

  function compareServicesByGiavCode(a, b) {
    const keyA = getServiceSortKey(a);
    const keyB = getServiceSortKey(b);
    return keyA.localeCompare(keyB, undefined, {
      numeric: true,
      sensitivity: "base",
    });
  }

  function ServiceList({ services, indent = false }) {
    if (!Array.isArray(services) || services.length === 0) return null;
    const sortedServices = useMemo(() => [...services].sort(compareServicesByGiavCode), [services]);
    return (
      <div className="cp-service-list">
        {sortedServices.map((service, index) => (
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
  const totalLabel = Number.isFinite(totalAmount) ? euro(totalAmount, currency) : "—";
  const paidLabel = Number.isFinite(paidAmount) ? euro(paidAmount, currency) : "—";
  const pendingLabel = pendingAmount !== null && Number.isFinite(pendingAmount) ? euro(pendingAmount, currency) : "—";

  const paymentKpiItems = payments
    ? [
        { key: "total", label: "Total", value: totalLabel, icon: <IconBriefcase />, colorClass: "is-salmon" },
        { key: "paid", label: tt("Pagado"), value: paidLabel, icon: <IconShieldCheck />, colorClass: "is-blue" },
        { key: "pending", label: tt("Pendiente"), value: pendingLabel, icon: <IconClockArrow />, colorClass: "is-green" },
        {
          key: "mulligans",
          label: "Mulligans usados",
          value: mulligansUsed.toLocaleString("es-ES"),
          icon: <IconSparkle />,
          colorClass: "is-lilac",
        },
      ]
    : [];

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

  const title = trip?.title || ttf("Expediente #{id}", { id: expediente });
  const tab = readParams().tab;

  const resolvedTrip = useMemo(() => {
    if (!detail?.trip) return fallbackTrip;
    const detailTrip = detail.trip;
    const fallbackStatus = fallbackTrip.status;
    const status = detailTrip.status && String(detailTrip.status).trim() !== '' ? detailTrip.status : fallbackStatus;
    return {
      ...fallbackTrip,
      ...detailTrip,
      status,
    };
  }, [detail?.trip, fallbackTrip]);

  return (
      <div className="cp-content" style={{ maxWidth: 1200, width: "100%", margin: "0 auto" }}>
      <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
        <button className="cp-btn" onClick={() => setParam("view", "trips")}>
          {tt("← Viajes")}
        </button>
        <div className="cp-meta" style={{ opacity: 0.85 }}>
          {tt("Viajes &gt;")} <span className="cp-strong">{title}</span>
        </div>
      </div>

      <TripHeader
        trip={detail?.trip ? resolvedTrip : trip}
        payments={payments}
        map={detail?.map}
        weather={detail?.weather}
        itineraryUrl={detail?.itinerary_pdf_url}
      />

      <Tabs
        tab={tab}
        onTab={(k) => {
          setParam("tab", k);
        }}
      />

      <div style={{ marginTop: 14 }}>
        {loading ? (
          <div className="cp-card" style={{ background: "#fff" }}><div className="cp-card-title">{tt("Cargando expediente")}</div><Skeleton lines={8} /></div>
        ) : err ? (
          <div className="cp-notice is-warn">{tt("No se puede cargar el expediente ahora mismo.")}</div>
        ) : null}

        {tab === "summary" ? (
          <div className="cp-card">
            <div className="cp-card-title">{tt("Resumen")}</div>
            <div className="cp-card-sub">{tt("Servicios y planificación del viaje")}</div>

            {!hasServices ? (
              <div style={{ marginTop: 10 }} className="cp-meta">
                {tt("No hay servicios disponibles ahora mismo.")}
              </div>
            ) : (
              <div className="cp-summary-services">
                {pkg ? (
                  <div className="cp-service-section">
                    <div className="cp-service-section__heading">{tt("Paquete")}</div>
                    <ServiceItem service={pkg} />
                    {packageServices.length > 0 ? (
                      <div className="cp-service-section">
                        <div className="cp-service-section__heading">{tt("Servicios incluidos")}</div>
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
            <div className="cp-card-title">{tt("Pagos")}</div>
            <div className="cp-card-sub">{tt("Estado de pagos del viaje")}</div>

            {!payments ? (
              <div style={{ marginTop: 10 }} className="cp-meta">{tt("Aún no hay pagos asociados a este viaje.")}</div>
            ) : (
              <>
                <div className="cp-kpi-card-grid">
                  {paymentKpiItems.map((item) => (
                    <KpiCard
                      key={item.key}
                      icon={item.icon}
                      label={item.label}
                      value={item.value}
                      colorClass={item.colorClass}
                    />
                  ))}
                </div>

                <PaymentActions expediente={expediente} payments={payments} mock={mock} />
                {isPaid ? (
                  <div style={{ marginTop: 12 }}>
                    <div className="cp-pill cp-pill--success">{tt("Pagado")}</div>
                  </div>
                ) : null}

                {chargeHistory.length > 0 ? (
                  <div className="cp-payments-history">
                    <div className="cp-payments-history__title">{tt("Histórico de cobros")}</div>
                    <div className="cp-table-wrap">
                      <table className="cp-payments-history__table">
                        <thead>
                          <tr>
                            <th>{tt("Fecha")}</th>
                            <th>{tt("Tipo")}</th>
                            <th>{tt("Concepto")}</th>
                            <th>{tt("Pagador")}</th>
                            <th className="is-right">{tt("Importe")}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {chargeHistory.map((row) => {
                            const historyBadge = getHistoryBadge(row);
                            return (
                              <tr key={row.id}>
                                <td>{formatDateES(row.date)}</td>
                                <td>
                                  <BadgeLabel
                                    label={historyBadge.label}
                                    variant={historyBadge.variant}
                                    className="cp-history-badge"
                                  />
                                </td>
                                <td>{row.concept}</td>
                                <td>{row.payer || row.document || "—"}</td>
                                <td className="is-right">
                                  {euro(row.is_refund ? -row.amount : row.amount, currency)}
                                </td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  </div>
                ) : (
                  <div style={{ marginTop: 14 }} className="cp-meta">
                    {tt("Aún no hay cobros registrados en este viaje.")}
                  </div>
                )}
              </>
            )}
          </div>
        ) : null}

        {tab === "invoices" ? (
          <div className="cp-card">
            <div className="cp-card-title">{tt("Facturas")}</div>
            <div className="cp-card-sub">{tt("Descargas asociadas a este viaje")}</div>
            {invoices.length === 0 ? (
              <div style={{ marginTop: 10 }} className="cp-meta">{tt("No hay facturas disponibles.")}</div>
            ) : (
              <div className="casanova-tablewrap" style={{ marginTop: 14 }}>
                <table className="casanova-table">
                  <thead>
                    <tr>
                      <th>{tt("Factura")}</th>
                      <th>{tt("Fecha")}</th>
                      <th className="num">{tt("Importe")}</th>
                      <th>{tt("Estado")}</th>
                      <th>{tt("Acciones")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {invoices.map((inv) => {
                      const statusRaw = String(inv.status || "").trim();
                      return (
                        <tr key={inv.id}>
                          <td>{inv.title || `Factura #${inv.id}`}</td>
                          <td>{formatDateES(inv.date)}</td>
                          <td className="num">
                            {typeof inv.amount === "number" ? euro(inv.amount, inv.currency || "EUR") : "—"}
                          </td>
                          <td>
                            <BadgeLabel label={statusRaw || "—"} variant={getInvoiceVariant(statusRaw)} />
                          </td>
                          <td>
                            {inv.download_url ? (
                              <a className="casanova-btn casanova-btn--sm casanova-btn--ghost" href={inv.download_url}>
                                {tt("Descargar PDF")}
                              </a>
                            ) : (
                              <span className="casanova-btn casanova-btn--sm casanova-btn--disabled">{tt("Descargar PDF")}</span>
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
            <div className="cp-card-title">{tt("Bonos")}</div>
            <div className="cp-card-sub">{tt("Vouchers y documentación")}</div>
            {bonuses.available && voucherItems.length > 0 ? (
              <Notice variant="info" title={tt("Bonos disponibles")}>
                {tt("En cada reserva podrás ver el bono y descargar el PDF.")}
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
                      {renderBonusButton(tt("Ver bono"), item.view_url, "view")}
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
            <div className="cp-card-title">{tt("Mensajes")}</div>
            <div className="cp-card-sub">{tt("Conversación sobre este viaje")}</div>
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
  const tierSlug = tier
    .trim()
    .toLowerCase()
    .replace(/\s+/g, "_")
    .replace(/\+/g, "_plus")
    .replace(/-/g, "_");

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

  const mulliganKpiItems = [
    { key: "balance", label: "Balance", value: points.toLocaleString("es-ES"), icon: <IconSparkle />, colorClass: "is-salmon" },
    { key: "spend", label: "Gasto histórico", value: fmtMoney(spend), icon: <IconChartBar />, colorClass: "is-blue" },
    { key: "sync", label: "Última sincronización", value: fmtDate(m.last_sync), icon: <IconCalendar />, colorClass: "is-lilac" },
    { key: "earned", label: "Ganados", value: earned.toLocaleString("es-ES"), icon: <IconShieldCheck />, colorClass: "is-green" },
    { key: "bonus", label: "Bonus", value: bonus.toLocaleString("es-ES"), icon: <IconStar />, colorClass: "is-salmon" },
    { key: "used", label: "Usados", value: used.toLocaleString("es-ES"), icon: <IconClockArrow />, colorClass: "is-lilac" },
  ];

  return (
    <div className="cp-content">
      <div className="cp-card">
        <div className="cp-card-header">
          <div>
            <div className="cp-card-title">{tt("Tu programa Mulligans")}</div>
            <div className="cp-card-sub">{tt("Puntos y nivel se actualizan automáticamente con tus reservas.")}</div>
          </div>
          <div className={`cp-pill ${tierSlug ? `is-${tierSlug}` : ""}`}>{tierLabel(tier)}</div>
        </div>
        <div className="cp-kpi-card-grid cp-mulligans-kpi-grid">
          {mulliganKpiItems.map((item) => (
            <KpiCard
              key={item.key}
              icon={item.icon}
              label={item.label}
              value={item.value}
              colorClass={item.colorClass}
            />
          ))}
        </div>
        <div style={{ marginTop: 14 }}>
          <Notice variant="info" title={tt("Cómo funciona")}>
            Los beneficios se activan con una reserva real. Si un año no viajas, mantienes tu nivel, pero no se “dispara” el beneficio.
          </Notice>
        </div>
      </div>

      <div className="cp-card" style={{ marginTop: 14 }}>
        <div className="cp-card-title">{tt("Histórico")}</div>
        <div className="cp-card-sub">{tt("Movimientos recientes (ganados, bonus y canjes).")}</div>

        {ledger.length === 0 ? (
          <EmptyState title={tt("Aún no hay movimientos")} icon="🧾">
            {tt("Cuando se registren pagos o se aplique un bonus, aparecerán aquí.")}
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

function DashboardView({ data, heroImageUrl, heroMap }) {
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

  const postTrip = Boolean(data?.post_trip?.is_post_trip);
  const reviewUrl = data?.post_trip?.review_url ? String(data.post_trip.review_url) : "";

  const tripLabel = nextTrip?.title ? String(nextTrip.title) : (postTrip ? tt("Tu viaje") : tt("Viaje"));
  const tripCode = nextTrip?.code ? String(nextTrip.code) : "";
  const tripContext = tripCode ? `${tripLabel} (${tripCode})` : tripLabel;
  const tripMeta = [tripCode, nextTrip?.date_range].filter(Boolean).join(" · ");
  const daysLeftRaw = Number(nextTrip?.days_left);
  const daysLeft = Number.isFinite(daysLeftRaw) ? Math.max(0, Math.round(daysLeftRaw)) : null;
  let daysLeftLabel = null;
  if (postTrip) {
    daysLeftLabel = "Viaje finalizado";
  } else if (daysLeft !== null) {
    daysLeftLabel = daysLeft === 0 ? "Empieza hoy" : `Empieza en ${daysLeft} días`;
  }
  const calendarUrl = nextTrip?.calendar_url ? String(nextTrip.calendar_url) : "";

  const isPaid = pendingAmount !== null ? pendingAmount <= 0.01 : false;
  const actionStatus = action?.status || (hasPaymentsData ? (isPaid ? "ok" : "pending") : "info");
  const actionBadge = action?.badge || (hasPaymentsData ? (isPaid ? tt("Todo listo") : tt("Pendiente")) : tt("Info"));
  const actionText = action?.description || (!nextTrip
    ? "No hay viajes próximos para mostrar aquí."
    : !hasPaymentsData
      ? "Cuando haya información de pagos, la verás reflejada aquí."
      : isPaid
        ? "Todo listo. No tienes nada pendiente ahora mismo."
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
    else if (actionStatus === "invoices") setParam("tab", "invoices");
  };

  const actionCtaLabel = actionStatus === "pending" ? tt("Ver pagos") : (actionStatus === "invoices" ? "Ver facturas" : "Ver viaje");
  const actionPillLabel = actionStatus === "invoices" && typeof action?.invoice_count === "number"
    ? `${actionBadge} · ${action.invoice_count}`
    : actionBadge;

  const viewNoteTrip = (event) => {
    if (!noteExpedienteId) return;
    if (event && typeof event.preventDefault === "function") event.preventDefault();
    setParam("view", "trip");
    setParam("expediente", noteExpedienteId);
  };

  return (
    <div className="cp-content">
      <div className="cp-grid cp-dash-grid cp-dash-premium">
        {/* HERO: Próximo viaje */}
        <section className="cp-hero cp-dash-span-12">
          <div className="cp-hero__bg" aria-hidden="true" />
          <div className="cp-hero__content">
            <div className="cp-hero__top">
              <div className="cp-hero__eyebrow">{postTrip ? tt("Tu último viaje") : tt("Tu próximo viaje")}</div>
              <div className="cp-hero__badges">
                {daysLeftLabel ? <span className="cp-pill cp-hero-pill">{daysLeftLabel}</span> : null}
                {(postTrip || nextTrip?.status) ? (
                  <span className="cp-pill cp-hero-pill">{postTrip ? tt("Finalizado") : nextTrip.status}</span>
                ) : null}
              </div>
            </div>

            {nextTrip ? (
              <>
                <div className="cp-hero__header">
                  <div className="cp-hero__heading">
                    <div className="cp-hero__title">{tripLabel}</div>
                    <div className="cp-hero__meta">
                      {tripMeta || "Fechas por confirmar"}
                      {heroMap?.url ? (
                        <a
                          className="cp-hero__meta-link"
                          href={heroMap.url}
                          target="_blank"
                          rel="noreferrer"
                          title={tt("Abrir en Google Maps")}
                        >
                          {heroMap?.type === 'route' ? tt("Ver ruta") : tt("Ver mapa")}
                        </a>
                      ) : null}
                    </div>
                  </div>

                  {heroImageUrl ? (
                    <div className="cp-hero__media" aria-hidden="true">
                      <img className="cp-hero__media-img" src={heroImageUrl} alt="" loading="lazy" />
                    </div>
                  ) : (
                    <div className="cp-hero__media is-empty" aria-hidden="true" />
                  )}
                </div>

                <div className="cp-hero__kpis">
                  <div className="cp-hero-kpi">
                    <div className="cp-hero-kpi__label">{tt("Total del viaje")}</div>
                    <div className="cp-hero-kpi__value">{hasPaymentsData ? totalLabel : "—"}</div>
                  </div>
                  <div className="cp-hero-kpi">
                    <div className="cp-hero-kpi__label">{tt("Pendiente")}</div>
                    <div className="cp-hero-kpi__value is-warn">{hasPaymentsData ? pendingLabel : "—"}</div>
                  </div>
                  <div className="cp-hero-kpi">
                    <div className="cp-hero-kpi__label">{tt("Pagado")}</div>
                    <div className="cp-hero-kpi__value">{hasPaymentsData ? paidLabel : "—"}</div>
                  </div>
                </div>

                <div className="cp-hero__actions">
                  <div className="cp-hero__actions-left">
                    <button className="cp-btn primary" onClick={viewTrip}>
                      {t('view_details', 'Ver detalles')}
                    </button>
                    <button className="cp-btn cp-btn--ghost" onClick={viewPayments} disabled={!hasPaymentsData}>
                      {tt("Pagos")}
                    </button>
                    {calendarUrl ? (
                      <a className="cp-btn cp-btn--ghost" href={calendarUrl}>
                        {tt("Añadir al calendario")}
                      </a>
                    ) : null}

                    {postTrip && reviewUrl ? (
                      <a className="cp-btn cp-btn--ghost" href={reviewUrl} target="_blank" rel="noreferrer">
                        {tt("Dejar opinión")}
                      </a>
                    ) : null}
                  </div>
                  <div className="cp-hero__actions-right">
                    <button
                      type="button"
                      className={`cp-pill cp-hero-pill cp-pill--clickable ${actionPillClass}`}
                      onClick={viewActionTrip}
                      title={actionStatus === "pending" ? "Ir a pagos" : (actionStatus === "invoices" ? "Ir a facturas" : "Ir al viaje")}
                    >
                      {actionPillLabel}
                    </button>
                    <span className="cp-hero__hint">{actionTripLabel ? `Para: ${actionTripLabel}` : ""}</span>
                  </div>
                </div>
              </>
            ) : (
              <div className="cp-hero__empty">
                <div className="cp-hero__title">{tt("Aún no tienes un próximo viaje")}</div>
                <div className="cp-hero__meta">{tt("Cuando confirmes una reserva, la verás aquí con todos sus detalles.")}</div>
              </div>
            )}
          </div>
        </section>

        {/* Cards secundarias */}
        <section className="cp-card cp-dash-card cp-dash-span-4 cp-card--quiet">
          <div className="cp-dash-head">
            <CardTitleWithIcon icon={IconClipboardList}>{tt("Siguiente paso")}</CardTitleWithIcon>
            <button
              type="button"
              className={`cp-pill cp-dash-pill cp-pill--clickable ${actionPillClass}`}
              onClick={actionTripLabel ? viewActionTrip : undefined}
              title={actionTripLabel ? tt("Ver detalle") : ""}
              disabled={!actionTripLabel}
            >
              {actionPillLabel}
            </button>
          </div>
          {actionTripLabel ? (
            <div className="cp-dash-context">
              {tt("Para:")} <strong>{actionTripLabel}</strong>
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
              {actionCtaLabel}
            </button>
          ) : null}
        </section>

        <section className="cp-card cp-dash-card cp-dash-span-4 cp-card--quiet">
          <div className="cp-dash-head">
            <CardTitleWithIcon icon={IconWallet}>{tt("Pagos")}</CardTitleWithIcon>
            {hasPaymentsData ? (
              <span className={`cp-pill cp-dash-pill ${isPaid ? "is-ok" : "is-warn"}`}>
                {isPaid ? tt("Todo pagado") : tt("Pendiente")}
              </span>
            ) : null}
          </div>
          {hasPaymentsData ? (
            <>
              <div className="cp-dash-stats">
                <div>
                  <div className="cp-dash-stat-label">{tt("Pagado")}</div>
                  <div className="cp-dash-stat-value">{paidLabel}</div>
                </div>
                <div>
                  <div className="cp-dash-stat-label">{tt("Total")}</div>
                  <div className="cp-dash-stat-value">{totalLabel}</div>
                </div>
              </div>
              <div className="cp-dash-progress">
                <div className="cp-dash-progress-bar" style={{ width: `${paymentProgress}%` }} />
              </div>
              <div className="cp-dash-meta">Has pagado {paidLabel} de {totalLabel}</div>
              <button className="cp-btn cp-btn--ghost" onClick={viewPayments}>
                {tt("Ver detalle")}
              </button>
            </>
          ) : (
            <div className="cp-muted" style={{ marginTop: 12 }}>
              {tt("No hay datos de pagos disponibles por el momento.")}
            </div>
          )}
        </section>

        <section className="cp-card cp-dash-card cp-dash-span-4 cp-card--quiet">
          <div className="cp-dash-head">
            <CardTitleWithIcon icon={IconMapPin}>{tt("Tu viaje")}</CardTitleWithIcon>
            {tripCode ? <span className="cp-pill cp-dash-pill is-info">{tripCode}</span> : null}
          </div>
          <div className="cp-dash-note">
            {nextTrip ? (
              <>
                <strong>{tripLabel}</strong>
                <div className="cp-muted" style={{ marginTop: 6 }}>
                  {tripMeta || "Fechas por definir"}
                </div>
              </>
            ) : (
              "Cuando confirmes un viaje, aquí tendrás acceso rápido a todo."
            )}
          </div>
          {nextTrip ? (
            <button className="cp-btn cp-btn--ghost" onClick={viewTrip}>
              {tt("Ir al detalle")}
            </button>
          ) : null}
        </section>

        {/* Mulligans con presencia (sin tocar datos) */}
        <section className={"casanova-mulligans-card casanova-mulligans-card--dash " + tierClass}>
          <div className="casanova-mulligans-card__top">
            <div className="casanova-mulligans-card__title cp-card-title--with-icon">
              <span className="cp-card-title-icon" aria-hidden="true">
                <IconStarBadge />
              </span>
              <span>{tt("Tus Mulligans")}</span>
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
            {tt("Ver movimientos")}
          </button>
        </section>

      </div>
    </div>
  );
}

function pickHeroImageFromTripDetail(detail) {
  if (!detail || typeof detail !== "object") return "";
  const pkgServices = Array.isArray(detail?.package?.services) ? detail.package.services : [];
  const extras = Array.isArray(detail?.extras) ? detail.extras : [];
  const pool = [...pkgServices, ...extras];
  for (const s of pool) {
    const url = s?.media?.image_url;
    if (typeof url === "string" && url.trim() !== "") return url.trim();
  }
  // fallback: sometimes trip may include a hero image directly
  const tripImg = detail?.trip?.media?.image_url || detail?.trip?.hero_image_url || "";
  return typeof tripImg === "string" ? tripImg.trim() : "";
}

function App() {
  const [route, setRoute] = useState(readParams());
  const [dashboard, setDashboard] = useState(null);
  const [heroImageUrl, setHeroImageUrl] = useState("");
  const [heroMap, setHeroMap] = useState(null);
  const [loadingDash, setLoadingDash] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [dashErr, setDashErr] = useState(null);
  const [showPaymentBanner, setShowPaymentBanner] = useState(false);
  const bannerTimerRef = useRef(null);
  const paymentDismissKey = "casanova_payment_banner_dismissed";

  const [inbox, setInbox] = useState(null);
  const [inboxErr, setInboxErr] = useState(null);

  const [inboxLatestTs, setInboxLatestTs] = useState(() => lsGetInt(LS_KEYS.inboxLatestTs, 0));
  const [messagesLastSeenTs, setMessagesLastSeenTs] = useState(() => lsGetInt(LS_KEYS.messagesLastSeenTs, 0));

  const heroImageTripIdRef = useRef("");

  const [profile, setProfile] = useState(null);
  const [profileErr, setProfileErr] = useState(null);
  const [toast, setToast] = useState(null);

  useEffect(() => {
    const onPop = () => setRoute(readParams());
    window.addEventListener("popstate", onPop);
    return () => window.removeEventListener("popstate", onPop);
  }, []);

  // Persist language preference (WPML):
  // - Backend stores the choice in user_meta via /profile/locale.
  // - On load, if the current WPML language differs, redirect once to the preferred language URL.
  useEffect(() => {
    try {
      const current = String(window.CasanovaPortal?.currentLang || "").toLowerCase();
      const preferred = String(window.CasanovaPortal?.preferredLang || "").toLowerCase();
      const redirectUrl = String(window.CasanovaPortal?.preferredRedirectUrl || "");
      if (!current || !preferred || current === preferred) return;
      if (!redirectUrl) return;

      const u = new URL(redirectUrl, window.location.origin);
      // keep current SPA params
      if (window.location.search) u.search = window.location.search;
      if (u.toString() !== window.location.href) {
        window.location.replace(u.toString());
      }
    } catch {
      // ignore
    }
  }, []);

  async function loadProfile() {
    try {
      setProfileErr(null);
      const data = await api('/profile');
      setProfile(data);
    } catch (e) {
      setProfileErr(e);
    }
  }

  useEffect(() => {
    loadProfile();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function notify(message, variant = 'info') {
    setToast({ message, variant });
    window.setTimeout(() => setToast(null), 4_000);
  }

  useEffect(() => {
    if (route.payment === "success") {
      // Evitar que se quede "plantificado" si el usuario recarga o navega.
      const dismissed = window.sessionStorage ? window.sessionStorage.getItem(paymentDismissKey) === "1" : false;
      if (!dismissed) {
        setShowPaymentBanner(true);

        // Auto-hide como red de seguridad (si el usuario no lo cierra).
        if (bannerTimerRef.current) {
          window.clearTimeout(bannerTimerRef.current);
        }
        bannerTimerRef.current = window.setTimeout(() => {
          setShowPaymentBanner(false);
        }, 8_000);
      }

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

  function dismissPaymentBanner() {
    setShowPaymentBanner(false);
    try {
      if (window.sessionStorage) window.sessionStorage.setItem(paymentDismissKey, "1");
    } catch {
      // ignore
    }
  }

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

      // Reset hero image if next trip changes (image will be re-fetched lazily).
      const nextTripId = dashRes?.next_trip?.id ? String(dashRes.next_trip.id) : "";
      if (heroImageTripIdRef.current && heroImageTripIdRef.current !== nextTripId) {
        setHeroImageUrl("");
        setHeroMap(null);
      }
    } catch (e) {
      setDashErr(e);
      setInboxErr(e);
    } finally {
      setIsRefreshing(false);
      setLoadingDash(false);
    }
  }

  // Lazy-load a hero image from the trip detail (reuses existing /trip endpoint).
  useEffect(() => {
    const nextTripId = dashboard?.next_trip?.id ? String(dashboard.next_trip.id) : "";
    if (!nextTripId) {
      heroImageTripIdRef.current = "";
      setHeroImageUrl("");
      setHeroMap(null);
      return;
    }
    if (heroImageTripIdRef.current === nextTripId && heroImageUrl && heroMap?.url) return;

    let alive = true;
    heroImageTripIdRef.current = nextTripId;

    (async () => {
      try {
        const params = new URLSearchParams();
        if (route.mock) params.set("mock", "1");
        const qs = params.toString() ? `?${params.toString()}` : "";
        const d = await api(`/trip/${encodeURIComponent(nextTripId)}${qs}`);
        if (!alive) return;

        if (d && typeof d === 'object' && d.map && typeof d.map.url === 'string') {
          setHeroMap({ type: d.map.type || 'single', url: d.map.url, hotels: Array.isArray(d.map.hotels) ? d.map.hotels : [] });
        } else {
          setHeroMap(null);
        }

        const pick = (rows) => {
          if (!Array.isArray(rows)) return "";
          for (const s of rows) {
            const url = s?.media?.image_url || "";
            if (url) return String(url);
          }
          return "";
        };

        const pkgRows = Array.isArray(d?.package?.services) ? d.package.services : [];
        const extraRows = Array.isArray(d?.extras) ? d.extras : [];
        const url = pick(pkgRows) || pick(extraRows) || "";
        if (url) setHeroImageUrl(url);
      } catch {
        // Silent fail: hero keeps its premium gradients.
      }
    })();

    return () => {
      alive = false;
    };
  }, [dashboard?.next_trip?.id, route.mock]);

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

  async function saveProfile(data) {
    try {
      const res = await api('/profile', { method: 'POST', body: data });
      setProfile(res);
      notify(tt('Perfil actualizado.'), 'success');
    } catch (e) {
      notify(e?.message || tt('No se pudo guardar el perfil.'), 'warn');
    }
  }

  async function changePassword(data) {
    try {
      await api('/profile/password', { method: 'POST', body: data });
      notify(tt('Contraseña actualizada.'), 'success');
    } catch (e) {
      notify(e?.message || tt('No se pudo actualizar la contraseña.'), 'warn');
    }
  }

  async function setLocale(locale) {
    try {
      const lang = String(locale || "").slice(0, 2).toLowerCase();
      const res = await api('/profile/locale', { method: 'POST', body: { locale, lang } });
      setProfile((p) => (p ? { ...p, locale: res.locale || locale } : p));

      // Si WPML está activo, el backend nos devuelve la URL del portal en el idioma correcto.
      if (res && typeof res.redirectUrl === "string" && res.redirectUrl.trim() !== "") {
        const u = new URL(res.redirectUrl, window.location.origin);
        // Mantener la vista actual (query params) al cambiar de idioma.
        if (window.location.search) u.search = window.location.search;
        window.location.href = u.toString();
        return;
      }

      notify(t('language_updated', 'Idioma actualizado.'), 'success');
    } catch (e) {
      notify(e?.message || t('language_update_failed', 'No se pudo actualizar el idioma.'), 'warn');
    }
  }

  function go(view) {
    if (view) setParam('view', view);
  }

  function logout() {
    const url = profile?.logoutUrl;
    if (url) window.location.href = url;
    else window.location.href = '/';
  }

  const unreadInbox = inbox?.unread;
  const unreadDash = dashboard?.messages?.unread;
  const unreadFromServer = typeof unreadInbox === "number" ? unreadInbox : (typeof unreadDash === "number" ? unreadDash : 0);

  const unreadCount =
    inboxLatestTs > 0 && messagesLastSeenTs >= inboxLatestTs ? 0 : unreadFromServer;

  const title = useMemo(() => {
    if ((route.view === "viajes" || route.view === "trips")) return t('nav_trips', 'Viajes');
    if (route.view === "trip") return t('nav_trip_detail', 'Detalle del viaje');
    if (route.view === "inbox") return t('nav_messages', 'Mensajes');
    if (route.view === "dashboard") return t('nav_dashboard', 'Dashboard');
    if (route.view === "mulligans") return t('nav_mulligans', 'Mulligans');
    if (route.view === "profile") return t('menu_profile', 'Mi perfil');
    if (route.view === "security") return t('menu_security', 'Seguridad');
    return t('nav_portal', 'Portal');
  }, [route.view]);

  const chip = route.mock ? t('mock_mode', 'Modo prueba') : null;

  return (
    <div className="cp-app">
      <Sidebar view={route.view} unread={unreadCount} />
      <main className="cp-main">
        <Topbar
          title={title}
          chip={chip}
          onRefresh={() => loadDashboard(true)}
          isRefreshing={isRefreshing}
          profile={profile}
          onGo={go}
          onLogout={logout}
          onLocale={setLocale}
        />
        {toast ? (
          <div className={`cp-toast is-${toast.variant || 'info'}`}>{toast.message}</div>
        ) : null}
        {showPaymentBanner ? (
          <div className="cp-content">
            <Notice
              variant="success"
              title={t('payment_registered_title', 'Pago registrado')}
              className="casanova-notice casanova-notice--payment"
              onClose={dismissPaymentBanner}
              closeLabel="Cerrar"
            >
              {tt("Gracias, procesamos el cobro y actualizamos tus datos.")}
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
              {tt("Ahora mismo no podemos cargar tus datos. Si es urgente, escríbenos y lo revisamos.")}
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
          <DashboardView data={dashboard} heroImageUrl={heroImageUrl} heroMap={heroMap} />
        ) : route.view === "mulligans" ? (
          <MulligansView data={dashboard} />
        ) : route.view === "profile" ? (
          profile ? (
            <ProfileView profile={profile} onSave={saveProfile} onLocale={setLocale} />
          ) : (
            <div className="cp-content">
              {profileErr ? (
                <Notice variant="warn" title={tt("No podemos cargar tu perfil")}>
                  {profileErr?.message || 'Inténtalo de nuevo más tarde.'}
                </Notice>
              ) : (
                <div className="cp-card" style={{ background: "#fff" }}><Skeleton lines={6} /></div>
              )}
            </div>
          )
        ) : route.view === "security" ? (
          <SecurityView onChangePassword={changePassword} />
        ) : (
          <div className="cp-content">
            <div className="cp-notice">{tt("Vista en construcción.")}</div>
          </div>
        )}
      </main>
    </div>
  );
}

export default App;
