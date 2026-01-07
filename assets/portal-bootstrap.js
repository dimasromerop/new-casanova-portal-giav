// assets/portal-bootstrap.js
(function () {
  if (!window.CasanovaPortal) return;

  fetch(window.CasanovaPortal.restUrl + '/dashboard', {
    credentials: 'same-origin',
    headers: { 'X-WP-Nonce': window.CasanovaPortal.nonce },
  })
    .then(r => r.json())
    .then(data => console.log('[Dashboard]', data))
    .catch(err => console.error('[Dashboard ERROR]', err));
})();
