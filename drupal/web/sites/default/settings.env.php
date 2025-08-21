<?php
// Auto-generated (slim)
$settings['hash_salt'] = 'ed07c35bab534fce3992863a93f860cd7a2ae7634a3a8ebf80bf919e92ad9e6d';
$settings['config_sync_directory'] = '/opt/drupal/config/sync';
$settings['trusted_host_patterns'] = [ '^localhost$','^drupal$','^127\.0\.0\.1$', ];

$settings['reverse_proxy'] = 0 ? TRUE : FALSE;
$settings['reverse_proxy_addresses'] = array_filter(array_map('trim', explode(',', '')));

$__hdrs = array_map('trim', explode(',', 'X_FORWARDED_FOR,X_FORWARDED_HOST,X_FORWARDED_PROTO,X_FORWARDED_PORT'));
$__map = [
  'X_FORWARDED_FOR'  => \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR,
  'X_FORWARDED_HOST' => \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST,
  'X_FORWARDED_PROTO'=> \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO,
  'X_FORWARDED_PORT' => \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT,
  'FORWARDED'        => \Symfony\Component\HttpFoundation\Request::HEADER_FORWARDED,
];
$settings['reverse_proxy_trusted_headers'] = array_reduce($__hdrs, fn($c,$h)=> $c | ($__map[$h] ?? 0), 0);

if (!isset($config)) { $config = []; }
$config['jfcamp.settings']['matching_base_url'] = getenv('MATCHING_BASE_URL') ?: 'http://matching:5001';
