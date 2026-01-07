<?php
if (!defined('ABSPATH')) exit;

/**
 * Service de Mensajes.
 *
 * - Lee comentarios de GIAV como timeline.
 * - Soporta mock para desarrollo: ?mock=1 (solo admin).
 * - Diseñado para ser consumido por React y por legacy si se necesitara.
 */
class Casanova_Messages_Service {

  /**
   * @return array<string,mixed>
   */
  public static function get_messages_for_user(int $user_id, WP_REST_Request $request): array {

    $mock = (int) $request->get_param('mock') === 1;
    if ($mock && current_user_can('manage_options')) {
      return self::mock_response($request);
    }

    $idCliente = (int) get_user_meta($user_id, 'casanova_idcliente', true);
    if (!$idCliente) {
      return [
        'status' => 'ok',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        'unread' => 0,
        'items'  => [],
      ];
    }

    // Selección de expediente
    $expediente = (int) $request->get_param('expediente');
    if (!$expediente) {
      // fallback: usa el próximo viaje si no llega expediente
      $dash = Casanova_Dashboard_Service::build_for_user($user_id)->to_array();
      $expediente = (int) ($dash['next_trip']['id'] ?? 0);
    }

    if (!$expediente || !function_exists('casanova_giav_comments_por_expediente')) {
      return [
        'status' => 'ok',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        'unread' => 0,
        'items'  => [],
      ];
    }

    $limit = (int) $request->get_param('limit');
    if ($limit <= 0) $limit = 50;
    if ($limit > 200) $limit = 200;

    $days = 365;

    try {
      $comments = casanova_giav_comments_por_expediente($expediente, $limit, $days);
      if (is_wp_error($comments) || !is_array($comments)) $comments = [];

      $items = [];
      foreach ($comments as $c) {
        if (!is_object($c)) continue;
        $items[] = [
          'id'           => (string) ($c->Id ?? $c->ID ?? $c->IdComment ?? ''),
          'date'         => (string) ($c->CreationDate ?? $c->Fecha ?? ''),
          'author'       => (string) ($c->Author ?? $c->Usuario ?? 'Casanova Golf'),
          'direction'    => 'agency',
          'content'      => (string) ($c->Body ?? ''),
          'expediente_id'=> $expediente,
        ];
      }

      $unread = function_exists('casanova_messages_new_count_for_expediente')
        ? (int) casanova_messages_new_count_for_expediente($user_id, $expediente, 30)
        : 0;

      // mark_seen: solo meta local (no toca GIAV)
      $mark_seen = (int) $request->get_param('mark_seen') === 1;
      if ($mark_seen && function_exists('casanova_messages_mark_seen_for_expediente')) {
        casanova_messages_mark_seen_for_expediente($user_id, $expediente);
        $unread = 0;
      }

      return [
        'status' => 'ok',
        'giav'   => ['ok' => true, 'source' => 'live', 'error' => null],
        'unread' => $unread,
        'items'  => $items,
      ];

    } catch (Exception $e) {
      return [
        'status' => 'degraded',
        'giav'   => ['ok' => false, 'source' => 'live', 'error' => $e->getMessage()],
        'unread' => 0,
        'items'  => [],
      ];
    }
  }

  /**
   * @return array<string,mixed>
   */
  private static function mock_response(WP_REST_Request $request): array {
    $file = CASANOVA_GIAV_PLUGIN_PATH . 'includes/mock/messages.json';
    $raw = file_exists($file) ? file_get_contents($file) : '';
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
      $data = [
        'unread' => 2,
        'items' => [],
      ];
    }

    $exp = (int) $request->get_param('expediente');
    if ($exp) {
      $filtered = [];
      foreach ((array) ($data['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        if ((int) ($it['expediente_id'] ?? 0) === $exp) $filtered[] = $it;
      }
      $data['items'] = $filtered;
    }

    return [
      'status' => 'mock',
      'giav'   => ['ok' => true, 'source' => 'mock', 'error' => null],
      'unread' => (int) ($data['unread'] ?? 0),
      'items'  => array_values((array) ($data['items'] ?? [])),
    ];
  }
}
