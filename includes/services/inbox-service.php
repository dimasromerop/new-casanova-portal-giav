<?php
if (!defined('ABSPATH')) exit;

/**
 * Service de Inbox Global.
 *
 * Devuelve un item por expediente con el último mensaje (si existe) y
 * un contador global de no leídos.
 */
class Casanova_Inbox_Service {

  /**
   * @return array<string,mixed>
   */
  public static function get_inbox_for_user(int $user_id, WP_REST_Request $request): array {
    $mock = (int) $request->get_param('mock');
    if ($mock && !current_user_can('manage_options')) $mock = 0;

    // Dashboard ya resuelve trips y (en mock) estructura base.
    $dash = Casanova_Dashboard_Service::build_for_user($user_id)->to_array();
    $trips = array_values((array) ($dash['trips'] ?? []));

    // En modo mock, si el dashboard no devuelve viajes (por usuario, permisos, etc.),
    // generamos una lista mínima desde includes/mock/trip.json para poder testear UI.
    if ($mock && (!$trips || !is_array($trips) || count($trips) === 0)) {
      $trip_file = CASANOVA_GIAV_PLUGIN_PATH . 'includes/mock/trip.json';
      $raw = file_exists($trip_file) ? file_get_contents($trip_file) : '';
      $all = $raw ? json_decode($raw, true) : null;

      if (is_array($all) && !isset($all['trip'])) {
        foreach ($all as $k => $v) {
          if (!is_array($v) || !isset($v['trip']) || !is_array($v['trip'])) continue;
          $tr = $v['trip'];
          $trips[] = [
            'id'         => (int) ($tr['id'] ?? 0),
            'title'      => (string) ($tr['title'] ?? ''),
            'code'       => (string) ($tr['code'] ?? ''),
            'status'     => (string) ($tr['status'] ?? ''),
            'date_start' => (string) ($tr['date_start'] ?? ''),
            'date_end'   => (string) ($tr['date_end'] ?? ''),
          ];
        }
      } elseif (is_array($all) && isset($all['trip']) && is_array($all['trip'])) {
        $tr = $all['trip'];
        $trips[] = [
          'id'         => (int) ($tr['id'] ?? 0),
          'title'      => (string) ($tr['title'] ?? ''),
          'code'       => (string) ($tr['code'] ?? ''),
          'status'     => (string) ($tr['status'] ?? ''),
          'date_start' => (string) ($tr['date_start'] ?? ''),
          'date_end'   => (string) ($tr['date_end'] ?? ''),
        ];
      }

      // Normaliza: elimina ids vacíos
      $trips = array_values(array_filter($trips, function($t){
        return !empty($t['id']);
      }));
    }


    if (!$trips) {
      return [
        'status' => $mock ? 'mock' : 'ok',
        'giav'   => ['ok' => true, 'source' => $mock ? 'mock' : 'live', 'error' => null],
        'unread' => 0,
        'items'  => [],
      ];
    }

    $items = [];
    $unread_total = 0;

    foreach ($trips as $t) {
      $exp = (int) ($t['id'] ?? 0);
      if (!$exp) continue;

      // Reutiliza service de mensajes para obtener lista y unread por expediente.
      $req2 = new WP_REST_Request('GET', '/casanova/v1/messages');
      $req2->set_param('mock', $mock ? 1 : 0);
      $req2->set_param('expediente', $exp);

      $m = Casanova_Messages_Service::get_messages_for_user($user_id, $req2);

      $list = array_values((array) ($m['items'] ?? []));
      usort($list, function($a,$b){
        $ta = isset($a['date']) ? strtotime((string)$a['date']) : 0;
        $tb = isset($b['date']) ? strtotime((string)$b['date']) : 0;
        return $tb <=> $ta;
      });

      $last = $list ? $list[0] : null;

      $unread = (int) ($m['unread'] ?? 0);
      $unread_total += $unread;

      $items[] = [
        'expediente_id'   => $exp,
        'trip_title'      => (string) ($t['title'] ?? ''),
        'trip_code'       => (string) ($t['code'] ?? ''),
        'trip_status'     => (string) ($t['status'] ?? ''),
        'date_start'      => (string) ($t['date_start'] ?? ''),
        'date_end'        => (string) ($t['date_end'] ?? ''),
        'last_message_at' => $last ? (string) ($last['date'] ?? '') : null,
        'author'          => $last ? (string) ($last['author'] ?? '') : '',
        'direction'       => $last ? (string) ($last['direction'] ?? '') : '',
        'content'         => $last ? (string) ($last['content'] ?? '') : '',
        'unread'          => $unread,
      ];
    }

    usort($items, function($a,$b){
      $ta = !empty($a['last_message_at']) ? strtotime((string)$a['last_message_at']) : 0;
      $tb = !empty($b['last_message_at']) ? strtotime((string)$b['last_message_at']) : 0;
      return $tb <=> $ta;
    });

    return [
      'status' => $mock ? 'mock' : 'ok',
      'giav'   => ['ok' => true, 'source' => $mock ? 'mock' : 'live', 'error' => null],
      'unread' => (int) $unread_total,
      'items'  => $items,
    ];
  }
}
