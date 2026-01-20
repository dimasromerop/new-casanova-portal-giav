<?php
if (!defined('ABSPATH')) exit;

/**
 * PDF Programa del viaje (v2):
 * - Vuelos: sección independiente con segmentos.
 * - Hotel: "Alojamiento en" cada día + check-in/check-out.
 */
function casanova_render_itinerary_html(array $payload): string {
  $trip = $payload['trip'] ?? null;
  if (!is_array($trip)) {
    return '<p>' . esc_html__('Programa del viaje no disponible.', 'casanova-portal') . '</p>';
  }

  $services = [];
  $package = is_array($payload['package'] ?? null) ? $payload['package'] : null;
  if (is_array($package)) {
    $pkg_services = is_array($package['services'] ?? null) ? $package['services'] : [];
    foreach ($pkg_services as $s) {
      if (is_array($s)) $services[] = $s;
    }
  }
  $extras = is_array($payload['extras'] ?? null) ? $payload['extras'] : [];
  foreach ($extras as $s) {
    if (is_array($s)) $services[] = $s;
  }

  $normalize_ts = function($value) {
    if (empty($value)) return null;
    $ts = strtotime((string)$value);
    return $ts === false ? null : (int)$ts;
  };

  $day_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

  // Separate flights; expand hotels across days.
  $flight_services = [];
  $service_entries = [];
  $all_timestamps = [];

  $extract_time = function(string $notes, string $kind): string {
    $notes = trim($notes);
    if ($notes === '') return '';
    $re = $kind === 'in'
      ? '/check\s*in\s*[:\-]?\s*([0-9]{1,2}:[0-9]{2})/i'
      : '/check\s*out\s*[:\-]?\s*([0-9]{1,2}:[0-9]{2})/i';
    if (preg_match($re, $notes, $m)) {
      return trim((string)($m[1] ?? ''));
    }
    return '';
  };

  foreach ($services as $service) {
    if (!is_array($service)) continue;

    $semantic = (string)($service['semantic_type'] ?? 'other');

    $start_raw = $service['date_from'] ?? '';
    $end_raw = $service['date_to'] ?? '';
    $start_ts = $normalize_ts($start_raw);
    $end_ts = $normalize_ts($end_raw);

    if ($start_ts !== null) $all_timestamps[] = $start_ts;
    if ($end_ts !== null) $all_timestamps[] = $end_ts;

    if ($semantic === 'flight') {
      $flight_services[] = $service;
      continue;
    }

    if ($start_ts === null && $end_ts === null) {
      continue;
    }

    if ($semantic === 'hotel') {
      $start_ts = $start_ts ?? $end_ts;
      $end_ts = $end_ts ?? $start_ts;
      if ($start_ts === null) continue;
      if ($end_ts < $start_ts) {
        $tmp = $start_ts;
        $start_ts = $end_ts;
        $end_ts = $tmp;
      }

      $start_day = date('Y-m-d', $start_ts);
      $checkout_day = date('Y-m-d', $end_ts);
      $last_lodging_ts = $end_ts - $day_seconds;
      $last_lodging_day = $last_lodging_ts >= $start_ts ? date('Y-m-d', $last_lodging_ts) : $start_day;

      $title_base = trim((string)($service['title'] ?? ''));
      if ($title_base === '') $title_base = trim((string)($service['detail']['code'] ?? 'Hotel'));

      $notes = trim((string)($service['detail']['bonus_text'] ?? ''));
      $checkin = $extract_time($notes, 'in');
      $checkout = $extract_time($notes, 'out');

      // Lodging entries: start..last_lodging_day (inclusive)
      $ts = $start_ts;
      while (date('Y-m-d', $ts) <= $last_lodging_day) {
        $date_key = date('Y-m-d', $ts);
        $role = ($date_key === $start_day) ? 'checkin' : 'stay';
        $service_entries[] = [
          'service' => $service,
          'date' => $date_key,
          'timestamp' => $ts,
          'itinerary' => [
            'title' => sprintf(__('Alojamiento en: %s', 'casanova-portal'), $title_base),
            'role' => $role,
            'checkin' => $checkin,
            'checkout' => $checkout,
          ],
        ];
        $ts += $day_seconds;
      }

      // Checkout entry on checkout_day
      $service_entries[] = [
        'service' => $service,
        'date' => $checkout_day,
        'timestamp' => $end_ts,
        'itinerary' => [
          'title' => sprintf(__('Check-out de: %s', 'casanova-portal'), $title_base),
          'role' => 'checkout',
          'checkin' => $checkin,
          'checkout' => $checkout,
        ],
      ];

      continue;
    }

    // Default (golf, other): use start date.
    $event_ts = $start_ts ?? $end_ts;
    $service_entries[] = [
      'service' => $service,
      'date' => date('Y-m-d', $event_ts),
      'timestamp' => $event_ts,
    ];
  }

  $trip_start_ts = $normalize_ts($trip['date_start'] ?? null);
  $trip_end_ts = $normalize_ts($trip['date_end'] ?? null);
  if ($trip_start_ts !== null) $all_timestamps[] = $trip_start_ts;
  if ($trip_end_ts !== null) $all_timestamps[] = $trip_end_ts;

  if (empty($all_timestamps)) {
    $start_ts = time();
    $end_ts = $start_ts;
  } else {
    $start_ts = $trip_start_ts ?? min($all_timestamps);
    $end_ts = $trip_end_ts ?? max($all_timestamps);
    if ($start_ts > $end_ts) { $tmp = $start_ts; $start_ts = $end_ts; $end_ts = $tmp; }
  }

  // Days map
  $days = [];
  $day_index = 1;
  for ($ts = $start_ts; $ts <= $end_ts; $ts += $day_seconds) {
    $date_key = date('Y-m-d', $ts);
    $day_name = date_i18n('l', $ts);
    if (function_exists('mb_strtoupper') && function_exists('mb_substr')) {
      $day_name = mb_strtoupper(mb_substr($day_name, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($day_name, 1, null, 'UTF-8');
    } else {
      $day_name = ucfirst($day_name);
    }
    $days[$date_key] = [
      'number' => $day_index,
      'label' => sprintf(__('Día %d - %s, %s', 'casanova-portal'), $day_index, $day_name, date_i18n('j \d\e F \d\e Y', $ts)),
      'timestamp' => $ts,
    ];
    $day_index++;
  }

  // Group by day
  $events_by_day = [];
  foreach ($service_entries as $entry) {
    $date = $entry['date'];
    if (!isset($events_by_day[$date])) $events_by_day[$date] = [];
    $events_by_day[$date][] = $entry;
  }

  foreach ($events_by_day as &$day_events) {
    usort($day_events, function($a, $b) {
      $as = $a['service'] ?? [];
      $bs = $b['service'] ?? [];
      $aid = (string)($as['id'] ?? ($as['product_id'] ?? ($as['supplier_id'] ?? '')));
      $bid = (string)($bs['id'] ?? ($bs['product_id'] ?? ($bs['supplier_id'] ?? '')));
      if ($aid === $bid) {
        return ((int)($a['timestamp'] ?? 0)) <=> ((int)($b['timestamp'] ?? 0));
      }
      return strcmp($aid, $bid);
    });
  }
  unset($day_events);

  $logo = function_exists('casanova_pdf_logo_data_uri') ? casanova_pdf_logo_data_uri() : '';
  $trip_title = trim((string)($trip['title'] ?? ''));
  if ($trip_title === '') $trip_title = __('Programa del viaje', 'casanova-portal');

  $trip_code = trim((string)($trip['code'] ?? ''));
  $trip_id = (int)($trip['id'] ?? 0);
  if ($trip_code !== '' && $trip_id > 0) $expediente_label = $trip_code . ' (#' . $trip_id . ')';
  elseif ($trip_code !== '') $expediente_label = $trip_code;
  elseif ($trip_id > 0) $expediente_label = '#' . $trip_id;
  else $expediente_label = __('Expediente', 'casanova-portal');

  $display_dates = trim((string)($trip['date_range'] ?? ''));
  if ($display_dates === '' && !empty($trip['date_start']) && !empty($trip['date_end'])) {
    $display_dates = casanova_fmt_date_range($trip['date_start'], $trip['date_end']);
  }

  $pax_count = (int)($trip['pax'] ?? 0);
  if ($pax_count <= 0 && is_array($payload['passengers'] ?? null)) $pax_count = count($payload['passengers']);
  $pax_label = $pax_count > 0 ? sprintf(__('%d Pax', 'casanova-portal'), $pax_count) : '';

  $board = '';
  foreach ($services as $service) {
    $candidate = trim((string)($service['details']['board'] ?? ''));
    if ($candidate !== '') { $board = $candidate; break; }
  }

  $type_labels = [
    'hotel' => __('Hotel', 'casanova-portal'),
    'golf' => __('Golf', 'casanova-portal'),
    'flight' => __('Vuelo', 'casanova-portal'),
    'other' => __('Servicio', 'casanova-portal'),
  ];

  ob_start();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?php echo esc_html($trip_title); ?></title>
  <style>
    @page { margin: 18mm 16mm; }
    body { font-family: 'DejaVu Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif; color:#111827; background:#fff; }
    .page { max-width:960px; margin:6px auto; padding:8px 10px; }
    .itinerary-header { border-bottom:1px solid #e5e7eb; padding-bottom:12px; margin-bottom:12px; }
    .itinerary-logo { height:90px; margin-bottom:8px; display:block; }
    .itinerary-title { font-size:22px; font-weight:700; margin:4px 0 6px; }
    .itinerary-subtitle { font-size:14px; color:#475569; margin-bottom:10px; }
    .itinerary-info { display:flex; flex-wrap:wrap; gap:10px; font-size:13px; color:#1f2933; }
    .itinerary-chip { background:#f8fafc; border:1px solid #e2e8f0; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:600; color:#0f172a; }
    .section-title { font-size:16px; font-weight:700; margin:16px 0 8px; color:#0f172a; }
    .day-block { margin-top:18px; page-break-inside:avoid; }
    .day-label { font-size:16px; font-weight:700; margin-bottom:8px; color:#0f172a; }
    .event { border:1px solid #e5e7eb; border-radius:12px; padding:10px 12px 12px; margin-bottom:10px; background:#ffffff; }
    .event-title-row { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; }
    .event-title { font-size:14px; font-weight:700; color:#111827; margin:0; }
    .event-type { background:#e0f2fe; color:#0369a1; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:600; letter-spacing:.5px; text-transform:uppercase; }
    .event-meta { font-size:12px; color:#475569; margin-top:6px; display:flex; flex-wrap:wrap; gap:6px; }
    .event-meta span { background:#f1f5f9; border-radius:6px; padding:2px 8px; }
    .event-note { margin-top:8px; font-size:12px; color:#1f2937; line-height:1.4; white-space:pre-wrap; }
    .itinerary-empty { font-size:12px; font-style:italic; color:#475569; }
    .flight-seg { margin-top:6px; font-size:12px; color:#1f2937; line-height:1.4; }
    .flight-seg ul { margin:6px 0 0 18px; padding:0; }
    .flight-seg li { margin:2px 0; }
  </style>
</head>
<body>
  <div class="page">

    <div class="itinerary-header">
      <?php if ($logo !== ''): ?>
        <img src="<?php echo esc_attr($logo); ?>" class="itinerary-logo" alt="<?php echo esc_attr__('Logo', 'casanova-portal'); ?>">
      <?php endif; ?>
      <div class="itinerary-title"><?php echo esc_html($trip_title); ?></div>
      <div class="itinerary-subtitle">
        <?php echo esc_html__('Programa del viaje por expediente', 'casanova-portal'); ?>
        <?php if ($expediente_label !== ''): ?>
          · <strong><?php echo esc_html($expediente_label); ?></strong>
        <?php endif; ?>
      </div>
      <div class="itinerary-info">
        <?php if ($display_dates !== ''): ?>
          <span class="itinerary-chip"><?php echo esc_html__('Fechas:', 'casanova-portal'); ?> <?php echo esc_html($display_dates); ?></span>
        <?php endif; ?>
        <?php if ($pax_label !== ''): ?>
          <span class="itinerary-chip"><?php echo esc_html__('Pax:', 'casanova-portal'); ?> <?php echo esc_html($pax_label); ?></span>
        <?php endif; ?>
        <?php if ($board !== ''): ?>
          <span class="itinerary-chip"><?php echo esc_html__('Régimen:', 'casanova-portal'); ?> <?php echo esc_html($board); ?></span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($flight_services)): ?>
      <div class="section-title"><?php echo esc_html__('Vuelos', 'casanova-portal'); ?></div>
      <?php foreach ($flight_services as $fs): ?>
        <?php
          $title = trim((string)($fs['title'] ?? ''));
          if ($title === '') $title = esc_html__('Billetes', 'casanova-portal');
          $details = is_array($fs['details'] ?? null) ? $fs['details'] : [];
          $route = trim((string)($details['route'] ?? ''));
          $code = trim((string)($details['flight_code'] ?? ''));
          $schedule = trim((string)($details['schedule'] ?? ''));
          $locator = trim((string)($details['locator'] ?? ''));
          $pax = trim((string)($details['passengers'] ?? ''));
          $segments = is_array($details['segments'] ?? null) ? $details['segments'] : [];
          $date = casanova_fmt_date_range($fs['date_from'] ?? null, $fs['date_to'] ?? null);
        ?>
        <div class="event">
          <div class="event-title-row">
            <div class="event-title"><?php echo esc_html($route !== '' ? $route : $title); ?></div>
            <span class="event-type"><?php echo esc_html($type_labels['flight']); ?></span>
          </div>
          <div class="event-meta">
            <?php if ($code !== ''): ?><span><?php echo esc_html($code); ?></span><?php endif; ?>
            <?php if ($schedule !== ''): ?><span><?php echo esc_html($schedule); ?></span><?php endif; ?>
            <?php if ($locator !== ''): ?><span><?php echo esc_html__('Localizador', 'casanova-portal'); ?>: <?php echo esc_html($locator); ?></span><?php endif; ?>
            <?php if ($pax !== ''): ?><span><?php echo esc_html($pax); ?></span><?php endif; ?>
            <?php if ($date !== ''): ?><span><?php echo esc_html($date); ?></span><?php endif; ?>
          </div>
          <?php if (!empty($segments)): ?>
            <div class="flight-seg">
              <strong><?php echo esc_html__('Segmentos:', 'casanova-portal'); ?></strong>
              <ul>
                <?php foreach ($segments as $seg): ?>
                  <li><?php echo esc_html((string)$seg); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($days)): ?>
      <p class="itinerary-empty"><?php echo esc_html__('No hay servicios programados.', 'casanova-portal'); ?></p>
    <?php else: ?>
      <?php foreach ($days as $date_key => $day): ?>
        <div class="day-block">
          <div class="day-label"><?php echo esc_html($day['label']); ?></div>
          <?php if (!empty($events_by_day[$date_key])): ?>
            <?php foreach ($events_by_day[$date_key] as $event): ?>
              <?php
                $service = $event['service'] ?? [];
                $semantic = (string)($service['semantic_type'] ?? 'other');
                $type_label = $type_labels[$semantic] ?? $type_labels['other'];

                $title = trim((string)($service['title'] ?? ''));
                if ($title === '') $title = trim((string)($service['detail']['code'] ?? 'Servicio'));

                $override = is_array($event['itinerary'] ?? null) ? $event['itinerary'] : [];
                if (!empty($override['title'])) $title = (string)$override['title'];

                $range = casanova_fmt_date_range($service['date_from'] ?? null, $service['date_to'] ?? null);
                $board_meta = trim((string)($service['details']['board'] ?? ''));
                $rooming_meta = trim((string)($service['details']['rooming'] ?? ''));
                $players = (int)($service['details']['players'] ?? 0);
                $notes = trim((string)($service['detail']['bonus_text'] ?? ''));

                $role = (string)($override['role'] ?? '');
                $checkin = trim((string)($override['checkin'] ?? ''));
                $checkout = trim((string)($override['checkout'] ?? ''));
              ?>
              <div class="event">
                <div class="event-title-row">
                  <div class="event-title"><?php echo esc_html($title); ?></div>
                  <span class="event-type"><?php echo esc_html($type_label); ?></span>
                </div>
                <div class="event-meta">
                  <?php if ($semantic !== 'hotel' && $range !== ''): ?>
                    <span><?php echo esc_html($range); ?></span>
                  <?php endif; ?>
                  <?php if ($board_meta !== '' && $semantic === 'hotel'): ?>
                    <span><?php echo esc_html__('Régimen', 'casanova-portal'); ?>: <?php echo esc_html($board_meta); ?></span>
                  <?php endif; ?>
                  <?php if ($rooming_meta !== ''): ?>
                    <span><?php echo esc_html__('Rooming', 'casanova-portal'); ?>: <?php echo esc_html($rooming_meta); ?></span>
                  <?php endif; ?>
                  <?php if ($players > 0 && $semantic === 'golf'): ?>
                    <span><?php echo esc_html__('Jugadores', 'casanova-portal'); ?>: <?php echo esc_html((string)$players); ?></span>
                  <?php endif; ?>
                  <?php if ($semantic === 'hotel' && $role === 'checkin' && $checkin !== ''): ?>
                    <span><?php echo esc_html__('Check-in', 'casanova-portal'); ?>: <?php echo esc_html($checkin); ?></span>
                  <?php endif; ?>
                  <?php if ($semantic === 'hotel' && $role === 'checkout' && $checkout !== ''): ?>
                    <span><?php echo esc_html__('Check-out', 'casanova-portal'); ?>: <?php echo esc_html($checkout); ?></span>
                  <?php endif; ?>
                </div>
                <?php if ($notes !== '' && $semantic !== 'hotel'): ?>
                  <div class="event-note">
                    <strong><?php echo esc_html__('Observaciones:', 'casanova-portal'); ?></strong>
                    <?php echo esc_html($notes); ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="itinerary-empty"><?php echo esc_html__('Sin servicios programados para este día.', 'casanova-portal'); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </div>
</body>
</html>
<?php
  return ob_get_clean();
}
