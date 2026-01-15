
<?php
// Simple Dashboard Page using existing REST endpoint

add_shortcode('casanova_react_dashboard', function() {
    ob_start();
    ?>
    <div id="casanova-dashboard-root"></div>
    <script>
    (function(){
      fetch('<?php echo esc_url(rest_url('casanova/v1/dashboard')); ?>', {
        headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' }
      })
      .then(r => r.json())
      .then(data => {
        const el = document.getElementById('casanova-dashboard-root');
        if(!data || !data.next_trip){ el.innerHTML = '<p>No hay viajes.</p>'; return; }
        el.innerHTML = `
          <h2>Próximo viaje</h2>
          <p><strong>${data.next_trip.title || ''}</strong></p>
          <p>Total: ${data.next_trip.total || ''}</p>
          <p>Pendiente: ${data.next_trip.pending || ''}</p>
          <hr/>
          <h3>Mulligans</h3>
          <p>${data.mulligans?.points_balance || 0} puntos</p>
        `;
      });
    })();
    </script>
    <?php
    return ob_get_clean();
});
