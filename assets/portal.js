(function () {
  function closest(el, sel) {
    return el && el.closest ? el.closest(sel) : null;
  }

  function isModifiedClick(e) {
    return !!(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1);
  }

  function getDetailContainer() {
    var detail = document.querySelector('.casanova-detail');
    if (detail) return detail;
    var portal = document.querySelector('.casanova-portal');
    if (portal) return portal;
    return document.body; // Fallback final
  }

  function ensureLoadingOverlay(container) {
    // Si el contenedor ya tiene el overlay (o uno compatible), no hacemos nada
    if (container.querySelector('.casanova-loading') || container.querySelector('.casanova-tabs-loading')) {
      return;
    }

    // Si no, inyectamos el HTML del overlay global para asegurar que se vea
    var overlay = document.createElement('div');
    overlay.className = 'casanova-loading';
    overlay.innerHTML = 
      '<div class="casanova-loading__card">' +
        '<div class="casanova-loading__bar"></div>' +
        '<div class="casanova-loading__text">Cargando...</div>' +
      '</div>';
    
    container.appendChild(overlay);
  }

  function scrollDetailIntoView(detail) {
    try {
      if (!detail) return;
      var isMobile = window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
      if (!isMobile) return;
      var rect = detail.getBoundingClientRect();
      if (rect.top < 0 || rect.top > (window.innerHeight * 0.35)) {
        window.scrollTo(0, Math.max(0, window.scrollY + rect.top - 16));
      }
    } catch (_) {}
  }

  document.addEventListener('click', function (e) {
    // Toggle details
    var toggle = closest(e.target, '[data-casanova-toggle-details]');
    if (toggle) {
      var det = closest(toggle, 'details');
      if (det) det.open = !det.open;
      e.preventDefault();
      e.stopPropagation();
      return;
    }

    // Open drawer
    var openBtn = closest(e.target, '[data-casanova-open-drawer]');
    if (openBtn) {
      document.documentElement.classList.add('casanova-drawer-open');
      e.preventDefault();
      return;
    }

    // Close drawer
    var closeBtn = closest(e.target, '[data-casanova-close-drawer]');
    if (closeBtn) {
      document.documentElement.classList.remove('casanova-drawer-open');
      e.preventDefault();
      return;
    }

    // Nav Links Spinner
    var navLink = closest(e.target, '[data-casanova-nav-link]');
    if (navLink) {
      if (isModifiedClick(e) || (navLink.getAttribute('target') === '_blank')) return;
      if (!navLink.classList.contains('is-active')) {
        navLink.classList.add('is-loading');
      }
      document.documentElement.classList.remove('casanova-drawer-open');
      return;
    }

    // Expediente Link Overlay
    var expLink = closest(e.target, '[data-casanova-expediente-link]');
    if (expLink) {
      if (isModifiedClick(e) || (expLink.getAttribute('target') === '_blank')) return;

      var target = getDetailContainer();
      if (target) {
        scrollDetailIntoView(target);
        // Aseguramos que tenga el HTML necesario
        if (target.classList.contains('casanova-detail')) {
           // Bricks suele tener el suyo, pero por si acaso
        } else {
           ensureLoadingOverlay(target);
        }
        target.classList.add('is-loading');
        // Soporte para CSS global:
        target.classList.add('casanova-is-loading');
      }
      document.documentElement.classList.remove('casanova-drawer-open');
      return;
    }
  });

  // NUEVO: Listener robusto para el cambio de año
  document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'periodo-select') {
      var form = e.target.form;
      if (!form) return;

      // Buscamos dónde poner el loading (preferiblemente toda la lista)
      var portal = document.querySelector('.casanova-portal') || document.querySelector('.casanova-main') || document.body;
      
      // 1. Asegurar HTML
      ensureLoadingOverlay(portal);
      
      // 2. Activar clase visual (ambas versiones por compatibilidad CSS)
      portal.classList.add('is-loading'); 
      portal.classList.add('casanova-is-loading');

      // 3. Enviar formulario con un micro-delay para que el navegador pinte el overlay
      requestAnimationFrame(function() {
        requestAnimationFrame(function() {
          form.submit();
        });
      });
    }
  });

  // Limpieza al cargar (por si se queda pillado por caché de navegador al volver atrás)
  function clearLoading() {
    document.documentElement.classList.remove('casanova-drawer-open');
    var loadingEls = document.querySelectorAll('.is-loading, .casanova-is-loading');
    loadingEls.forEach(function(el) {
      el.classList.remove('is-loading');
      el.classList.remove('casanova-is-loading');
    });
  }

  document.addEventListener('DOMContentLoaded', clearLoading);
  window.addEventListener('pageshow', clearLoading);

})();

// Manejo de botones submit (Guardando...)
document.addEventListener('DOMContentLoaded', function () {
  const forms = document.querySelectorAll('form[action*="casanova_update_address"], .casanova-portal form');
  forms.forEach(function (form) {
    form.addEventListener('submit', function (e) {
      // Si es el filtro de periodo, ya lo manejamos arriba, no duplicar spinner de botón
      if (form.querySelector('#periodo-select')) return;

      const btn = form.querySelector('.casanova-btn-submit') || form.querySelector('button[type="submit"], input[type="submit"]');
      if (!btn) return;
      btn.classList.add('is-loading');
      btn.disabled = true;
      const label = btn.querySelector('.label');
      if (label) label.textContent = (window.casanovaPortalI18n && casanovaPortalI18n.saving) ? casanovaPortalI18n.saving : 'Guardando…';
    });
  });
});

// Persistencia de Tabs
(function () {
  const WRAP_ID = "casanova-exp-tabs";
  const STORAGE_KEY = "casanova_active_tab_" + WRAP_ID;
  function wrap(){ return document.getElementById(WRAP_ID); }
  function buttons(w){ return w ? Array.from(w.querySelectorAll('[role="tab"]')) : []; }
  function tabId(btn){
    if (!btn) return "";
    return btn.getAttribute("aria-controls") || (btn.getAttribute("href") || "").replace("#","") || "";
  }
  function activateById(id){
    const w = wrap(); if (!w || !id) return;
    const btn = buttons(w).find(b => tabId(b) === id);
    if (btn) btn.click();
  }
  document.addEventListener("DOMContentLoaded", function(){
    const w = wrap(); if (!w) return;
    const fromHash = (location.hash || "").replace("#","").trim();
    const fromStore = sessionStorage.getItem(STORAGE_KEY) || "";
    if (fromHash) activateById(fromHash);
    else if (fromStore) activateById(fromStore);
  });
  document.addEventListener("click", function(e){
    const w = wrap(); if (!w) return;
    const btn = e.target.closest('#' + WRAP_ID + ' [role="tab"]');
    if (!btn) return;
    const id = tabId(btn); if (!id) return;
    sessionStorage.setItem(STORAGE_KEY, id);
    if (history && history.replaceState) history.replaceState(null, "", "#" + id);
    else location.hash = id;
  });
  document.addEventListener("click", function(e){
    const a = e.target.closest('a[href]'); if (!a) return;
    const href = a.getAttribute("href") || "";
    if (!href || href.includes("#")) return;
    if (!href.includes("expediente=")) return;
    const id = (location.hash || "").replace("#","").trim() || sessionStorage.getItem(STORAGE_KEY) || "";
    if (!id) return;
    a.setAttribute("href", href + "#" + id);
  }, true);
})();