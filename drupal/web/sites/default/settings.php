<?php

// phpcs:ignoreFile

/**
 * @file
 * Container-oriented Drupal settings for JF Camp.
 *
 * Runtime-specific values come exclusively from environment variables.
 */

$databases = [];

$settings['rebuild_access'] = FALSE;
$settings['update_free_access'] = FALSE;

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: '';

$config_sync_directory = getenv('CONFIG_SYNC_DIRECTORY') ?: '/opt/drupal/config/sync';
$settings['config_sync_directory'] = $config_sync_directory;

$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = '/opt/drupal/private';

$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];

$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;

$settings['jfcamp_matching_default_endpoint'] = getenv('MATCHING_BASE_URL') ?: 'http://matching:5001';

$db_host = getenv('DRUPAL_DB_HOST') ?: 'postgres';
$db_port = getenv('DRUPAL_DB_PORT') ?: '5432';
$db_name = getenv('DRUPAL_DB_NAME') ?: 'drupal';
$db_user = getenv('DRUPAL_DB_USER') ?: 'drupal';
$db_pass = getenv('DRUPAL_DB_PASS') ?: 'drupal';

$databases['default']['default'] = [
  'database' => $db_name,
  'username' => $db_user,
  'password' => $db_pass,
  'prefix' => '',
  'host' => $db_host,
  'port' => $db_port,
  'driver' => 'pgsql',
  'namespace' => 'Drupal\\pgsql\\Driver\\Database\\pgsql',
  'autoload' => 'core/modules/pgsql/src/Driver/Database/pgsql/',
];

$trusted_hosts = getenv('DRUPAL_TRUSTED_HOSTS') ?: '';
if ($trusted_hosts !== '') {
  $settings['trusted_host_patterns'] = array_values(array_filter(array_map(
    'trim',
    explode(',', $trusted_hosts)
  )));
}

if ((getenv('DRUPAL_REVERSE_PROXY') ?: '0') === '1') {
  $proxy_addresses = getenv('DRUPAL_REVERSE_PROXY_ADDRESSES') ?: '';
  $resolved_addresses = [];

  foreach (array_filter(array_map('trim', explode(',', $proxy_addresses))) as $address) {
    if (filter_var($address, FILTER_VALIDATE_IP)) {
      $resolved_addresses[] = $address;
      continue;
    }

    $resolved = gethostbyname($address);
    if ($resolved !== $address) {
      $resolved_addresses[] = $resolved;
    }
  }

  // Only enable trusted-proxy processing once at least one configured proxy
  // address resolves. This keeps direct container healthchecks working before
  // Caddy has started and registered in Docker DNS.
  if ($resolved_addresses) {
    $settings['reverse_proxy'] = TRUE;
    $settings['reverse_proxy_addresses'] = array_values(array_unique($resolved_addresses));
    $settings['reverse_proxy_trusted_headers'] =
      \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR |
      \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST |
      \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT |
      \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;
  }
}

$cors_file = __DIR__ . '/cors.local.yml';
if ((getenv('DRUPAL_ENABLE_DEV_CORS') ?: '0') === '1' && file_exists($cors_file)) {
  $settings['container_yamls'][] = $cors_file;
}

$local_settings = __DIR__ . '/settings.local.php';
if (file_exists($local_settings)) {
  include $local_settings;
}
