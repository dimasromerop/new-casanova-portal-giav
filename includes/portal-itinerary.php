<?php
if (!defined('ABSPATH')) exit;

function casanova_render_itinerary_html(array $payload): string {
  $trip = $payload['trip'] ?? null;
  if (!is_array($trip)) {
    return '<p>' . esc_html__('Programa del viaje no disponible.', 'casanova-portal') . '</p>';
  }

  $services = [];
  $package = is_array($payload['package'] ?? null) ? $payload['package'] : null;
  if (is_array($package)) {
    $pkg_services = is_array($package['services'] ?? null) ? $package['services'] : [];
    foreach ($pkg_services as $service) {
      if (is_array($service)) {
        $services[] = $service;
      }
    }
  }
  $extras = is_array($payload['extras'] ?? null) ? $payload['extras'] : [];
  foreach ($extras as $service) {
    if (is_array($service)) {
      $services[] = $service;
    }
  }

  $normalize_ts = function($value) {
    if (empty($value)) return null;
    $ts = strtotime($value);
    return $ts === false ? null : (int) $ts;
  };

  $service_entries = [];
  $all_timestamps = [];
  foreach ($services as $service) {
    if (!is_array($service)) continue;
    $start_raw = $service['date_from'] ?? '';
    $end_raw = $service['date_to'] ?? '';
    $start_ts = $normalize_ts($start_raw);
    $end_ts = $normalize_ts($end_raw);
    if ($start_ts !== null) {
      $all_timestamps[] = $start_ts;
    }
    if ($end_ts !== null) {
      $all_timestamps[] = $end_ts;
    }
    $event_ts = $start_ts ?? $end_ts;
    if ($event_ts === null) {
      continue;
    }
    $service_entries[] = [
      'service' => $service,
      'date' => date('Y-m-d', $event_ts),
      'timestamp' => $event_ts,
    ];
  }

  $trip_start_ts = $normalize_ts($trip['date_start'] ?? null);
  $trip_end_ts = $normalize_ts($trip['date_end'] ?? null);
  if ($trip_start_ts !== null) {
    $all_timestamps[] = $trip_start_ts;
  }
  if ($trip_end_ts !== null) {
    $all_timestamps[] = $trip_end_ts;
  }

  if (empty($all_timestamps)) {
    $now = time();
    $start_ts = $now;
    $end_ts = $now;
  } else {
    $start_ts = $trip_start_ts ?? min($all_timestamps);
    $end_ts = $trip_end_ts ?? max($all_timestamps);
    if ($start_ts === null) {
      $start_ts = min($all_timestamps);
    }
    if ($end_ts === null) {
      $end_ts = max($all_timestamps);
    }
  }

  if ($start_ts === null) {
    $start_ts = time();
  }
  if ($end_ts === null) {
    $end_ts = $start_ts;
  }

  if ($start_ts > $end_ts) {
    [$start_ts, $end_ts] = [$end_ts, $start_ts];
  }

  $day_seconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
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

  $events_by_day = [];
  foreach ($service_entries as $entry) {
    $date = $entry['date'];
    if (!isset($events_by_day[$date])) {
      $events_by_day[$date] = [];
    }
    $events_by_day[$date][] = [
      'service' => $entry['service'],
      'timestamp' => $entry['timestamp'],
    ];
  }

  foreach ($events_by_day as &$day_events) {
    usort($day_events, function($a, $b) {
      $aid = (string) ($a['service']['id'] ?? '');
      $bid = (string) ($b['service']['id'] ?? '');
      if ($aid === $bid) {
        return ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0);
      }
      return strcmp($aid, $bid);
    });
  }
  unset($day_events);

  $logo = function_exists('casanova_pdf_logo_data_uri') ? casanova_pdf_logo_data_uri() : '';
  $trip_title = trim((string) ($trip['title'] ?? ''));
  if ($trip_title === '') {
    $trip_title = __('Programa del viaje', 'casanova-portal');
  }

  $trip_code = trim((string) ($trip['code'] ?? ''));
  $trip_id = (int) ($trip['id'] ?? 0);
  $expediente_label = '';
  if ($trip_code !== '' && $trip_id > 0) {
    $expediente_label = $trip_code . ' (#' . $trip_id . ')';
  } elseif ($trip_code !== '') {
    $expediente_label = $trip_code;
  } elseif ($trip_id > 0) {
    $expediente_label = '#' . $trip_id;
  } else {
    $expediente_label = __('Expediente', 'casanova-portal');
  }

  $display_dates = trim((string) ($trip['date_range'] ?? ''));
  if ($display_dates === '' && !empty($trip['date_start']) && !empty($trip['date_end'])) {
    $display_dates = casanova_fmt_date_range($trip['date_start'], $trip['date_end']);
  }

  $pax_count = (int) ($trip['pax'] ?? 0);
  if ($pax_count <= 0 && is_array($payload['passengers'] ?? null)) {
    $pax_count = count($payload['passengers']);
  }
  $pax_label = $pax_count > 0 ? sprintf(__('%d Pax', 'casanova-portal'), $pax_count) : '';

  $board = '';
  foreach ($services as $service) {
    $candidate = trim((string) ($service['details']['board'] ?? ''));
    if ($candidate !== '') {
      $board = $candidate;
      break;
    }
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
    .day-block { margin-top:18px; page-break-inside:avoid; }
    .day-label { font-size:16px; font-weight:700; margin-bottom:8px; color:#0f172a; }
    .day-divider { border-top:1px solid #e5e7eb; margin:6px 0; }
    .event { border:1px solid #e5e7eb; border-radius:12px; padding:10px 12px 12px; margin-bottom:10px; background:#ffffff; }
    .event-title-row { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; }
    .event-title { font-size:14px; font-weight:700; color:#111827; margin:0; }
    .event-type { background:#e0f2fe; color:#0369a1; border-radius:999px; padding:2px 8px; font-size:11px; font-weight:600; letter-spacing:.5px; text-transform:uppercase; }
    .event-meta { font-size:12px; color:#475569; margin-top:6px; display:flex; flex-wrap:wrap; gap:6px; }
    .event-meta span { background:#f1f5f9; border-radius:6px; padding:2px 8px; }
    .event-note { margin-top:8px; font-size:12px; color:#1f2937; line-height:1.4; white-space:pre-wrap; }
    .itinerary-empty { font-size:12px; font-style:italic; color:#475569; }
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

    <?php if (empty($days)): ?>
      <p class="itinerary-empty"><?php echo esc_html__('No hay servicios programados.', 'casanova-portal'); ?></p>
    <?php else: ?>
      <?php foreach ($days as $date_key => $day): ?>
        <div class="day-block">
          <div class="day-label"><?php echo esc_html($day['label']); ?></div>
          <?php if (!empty($events_by_day[$date_key])): ?>
            <?php foreach ($events_by_day[$date_key] as $event): ?>
              <?php
                $service = $event['service'];
                $title = trim((string) ($service['title'] ?? ''));
                if ($title === '') $title = trim((string) ($service['detail']['code'] ?? 'Servicio'));
                $semantic = (string) ($service['semantic_type'] ?? 'other');
                $type_label = $type_labels[$semantic] ?? $type_labels['other'];
                $range = casanova_fmt_date_range($service['date_from'] ?? null, $service['date_to'] ?? null);
                $board_meta = trim((string) ($service['details']['board'] ?? ''));
                $rooming_meta = trim((string) ($service['details']['rooming'] ?? ''));
                $players = (int) ($service['details']['players'] ?? 0);
                $notes = trim((string) ($service['detail']['bonus_text'] ?? ''));
              ?>
              <div class="event">
                <div class="event-title-row">
                  <div class="event-title"><?php echo esc_html($title); ?></div>
                  <span class="event-type"><?php echo esc_html($type_label); ?></span>
                </div>
                <div class="event-meta">
                  <?php if ($range !== ''): ?>
                    <span><?php echo esc_html($range); ?></span>
                  <?php endif; ?>
                  <?php if ($board_meta !== '' && $semantic === 'hotel'): ?>
                    <span><?php echo esc_html__('Régimen', 'casanova-portal'); ?>: <?php echo esc_html($board_meta); ?></span>
                  <?php endif; ?>
                  <?php if ($rooming_meta !== ''): ?>
                    <span><?php echo esc_html__('Rooming', 'casanova-portal'); ?>: <?php echo esc_html($rooming_meta); ?></span>
                  <?php endif; ?>
                  <?php if ($players > 0 && $semantic === 'golf'): ?>
                    <span><?php echo esc_html__('Jugadores', 'casanova-portal'); ?>: <?php echo esc_html((string) $players); ?></span>
                  <?php endif; ?>
                </div>
                <?php if ($notes !== ''): ?>
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
