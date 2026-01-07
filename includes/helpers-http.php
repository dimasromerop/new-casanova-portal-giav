<?php

function casanova_http_get_binary(string $url) {
  $args = [
    'timeout' => 25,
    'redirection' => 3,
    'sslverify' => true,
    'headers' => [
      // A veces ayuda a que devuelva binario sin tonterías
      'Accept' => 'application/pdf,application/octet-stream,*/*',
    ],
  ];

  $res = wp_remote_get($url, $args);
  if (is_wp_error($res)) return $res;

  $code = (int) wp_remote_retrieve_response_code($res);
  if ($code < 200 || $code >= 300) {
    return new WP_Error('http_error', 'HTTP ' . $code . ' al descargar fichero');
  }

  $body = wp_remote_retrieve_body($res);
  $ctype = wp_remote_retrieve_header($res, 'content-type');

  return ['body' => $body, 'content_type' => $ctype ?: 'application/pdf'];
}