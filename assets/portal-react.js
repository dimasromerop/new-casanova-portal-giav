/* Casanova Portal React (sin build): usa wp.element (React/ReactDOM) ya incluido por WordPress.
   Esto es un "smoke test" productivo: dashboard + mensajes (mock opcional por query param ?mock=1). */
(function () {
  if (!window.wp || !wp.element) return;

  var el = document.getElementById('casanova-portal-root');
  if (!el) return;

  var e = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;

  function getQueryParam(name) {
    try {
      var url = new URL(window.location.href);
      return url.searchParams.get(name);
    } catch (err) {
      return null;
    }
  }

  function apiFetch(path) {
    var base = window.CasanovaPortal && window.CasanovaPortal.restUrl;
    var nonce = window.CasanovaPortal && window.CasanovaPortal.nonce;
    if (!base || !nonce) return Promise.reject({ message: 'Missing CasanovaPortal.restUrl/nonce' });

    return fetch(base + path, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': nonce }
    }).then(function (r) {
      return r.json().catch(function () { return null; }).then(function (body) {
        if (!r.ok) {
          var err = body || { message: 'Request failed', status: r.status };
          err.status = r.status;
          throw err;
        }
        return body;
      });
    });
  }

  function App() {
    var mock = getQueryParam('mock') === '1';
    var _tab = useState('dashboard');
    var tab = _tab[0];
    var setTab = _tab[1];

    var _dash = useState(null);
    var dash = _dash[0];
    var setDash = _dash[1];

    var _msgs = useState(null);
    var msgs = _msgs[0];
    var setMsgs = _msgs[1];

    var _loading = useState(true);
    var loading = _loading[0];
    var setLoading = _loading[1];

    var _err = useState(null);
    var err = _err[0];
    var setErr = _err[1];

    useEffect(function () {
      setLoading(true);
      setErr(null);

      var dashPath = '/dashboard' + (mock ? '?mock=1' : '');
      var msgPath  = '/messages' + (mock ? '?mock=1' : '');

      Promise.all([apiFetch(dashPath), apiFetch(msgPath)])
        .then(function (res) {
          setDash(res[0]);
          setMsgs(res[1]);
          setLoading(false);
        })
        .catch(function (e) {
          setErr(e);
          setLoading(false);
        });
    }, []);

    function Button(label, onClick, isActive) {
      return e('button', {
        type: 'button',
        className: 'cg-btn' + (isActive ? ' is-active' : ''),
        onClick: onClick
      }, label);
    }

    if (loading) {
      return e('div', { className: 'cg-wrap' },
        e('div', { className: 'cg-card' },
          e('div', { className: 'cg-title' }, 'Cargando portal…'),
          e('div', { className: 'cg-muted' }, 'Si esto tarda infinito, algo está roto. Sorpresa.')
        )
      );
    }

    if (err) {
      return e('div', { className: 'cg-wrap' },
        e('div', { className: 'cg-card' },
          e('div', { className: 'cg-title' }, 'Error cargando datos'),
          e('pre', { className: 'cg-pre' }, JSON.stringify(err, null, 2))
        )
      );
    }

    return e('div', { className: 'cg-wrap' },
      e('div', { className: 'cg-topbar' },
        e('div', { className: 'cg-brand' }, 'Casanova Portal (React shell)'),
        e('div', { className: 'cg-tabs' },
          Button('Dashboard', function () { setTab('dashboard'); }, tab === 'dashboard'),
          Button('Mensajes', function () { setTab('messages'); }, tab === 'messages'),
          e('span', { className: 'cg-chip' }, mock ? 'MOCK' : 'LIVE')
        )
      ),

      tab === 'dashboard'
        ? e('div', { className: 'cg-card' },
            e('div', { className: 'cg-title' }, 'Dashboard'),
            e('pre', { className: 'cg-pre' }, JSON.stringify(dash, null, 2))
          )
        : e('div', { className: 'cg-card' },
            e('div', { className: 'cg-title' }, 'Mensajes'),
            e('pre', { className: 'cg-pre' }, JSON.stringify(msgs, null, 2))
          )
    );
  }

  // Mount (React 18 createRoot si existe, si no render legacy)
  try {
    if (wp.element.createRoot) {
      wp.element.createRoot(el).render(e(App));
    } else if (wp.element.render) {
      wp.element.render(e(App), el);
    } else if (window.ReactDOM && window.React && window.ReactDOM.createRoot) {
      window.ReactDOM.createRoot(el).render(window.React.createElement(App));
    } else if (window.ReactDOM && window.React && window.ReactDOM.render) {
      window.ReactDOM.render(window.React.createElement(App), el);
    }
  } catch (ex) {
    // fallback ultra simple
    el.innerHTML = '<pre style="white-space:pre-wrap">React mount error: ' + String(ex) + '</pre>';
  }
})();
