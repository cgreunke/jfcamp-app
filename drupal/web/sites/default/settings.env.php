<?php
/**
 * settings.env.php
 * Zentrale, umgebungsabhängige Einstellungen für Drupal – liest nur ENV-Variablen.
 * Für Dev & Prod geeignet.
 */

use Symfony\Component\HttpFoundation\Request;

$env = getenv();

/**
 * TRUSTED HOSTS
 * Kommagetrennte Regex-Patterns, z.B. '^drupal$,^localhost$,^camp\.example\.org$'
 */
if (!empty($env['DRUPAL_TRUSTED_HOSTS'])) {
  $settings['trusted_host_patterns'] = array_map('trim', explode(',', $env['DRUPAL_TRUSTED_HOSTS']));
} else {
  // Dev-Default
  $settings['trusted_host_patterns'] = ['^drupal$', '^localhost$', '^127\.0\.0\.1$'];
}

/**
 * REVERSE PROXY
 */
$settings['reverse_proxy'] = !empty($env['DRUPAL_REVERSE_PROXY']) && $env['DRUPAL_REVERSE_PROXY'] !== '0';

if (!empty($env['DRUPAL_REVERSE_PROXY_ADDRESSES'])) {
  $settings['reverse_proxy_addresses'] = array_map('trim', explode(',', $env['DRUPAL_REVERSE_PROXY_ADDRESSES']));
}

// Trusted Headers (optional; Default ist oft ausreichend)
if (!empty($env['DRUPAL_REVERSE_PROXY_TRUSTED_HEADERS'])) {
  $map = [
    'X_FORWARDED_FOR'  => Request::HEADER_X_FORWARDED_FOR,
    'X_FORWARDED_HOST' => Request::HEADER_X_FORWARDED_HOST,
    'X_FORWARDED_PORT' => Request::HEADER_X_FORWARDED_PORT,
    'X_FORWARDED_PROTO'=> Request::HEADER_X_FORWARDED_PROTO,
  ];
  $settings['reverse_proxy_trusted_headers'] = 0;
  foreach (array_map('trim', explode(',', $env['DRUPAL_REVERSE_PROXY_TRUSTED_HEADERS'])) as $h) {
    if (isset($map[$h])) { $settings['reverse_proxy_trusted_headers'] |= $map[$h]; }
  }
}

/**
 * BASE URL / CANONICAL HOST
 * DRUPAL_BASE_URL: absolute Basis-URL, z.B. http://drupal (Dev) oder https://camp.example.org (Prod)
 */
if (!empty($env['DRUPAL_BASE_URL'])) {
  $base_url = rtrim($env['DRUPAL_BASE_URL'], '/');
}

/**
 * Optional: kanonischer Hostname für generierte Links (edge cases)
 */
if (!empty($env['DRUPAL_CANONICAL_HOST'])) {
  $settings['host'] = $env['DRUPAL_CANONICAL_HOST'];
}

/**
 * Datenbank – nur falls nicht bereits durch settings.php gesetzt.
 */
if (empty($databases['default']['default'])) {
  $db_host = $env['DRUPAL_DB_HOST'] ?? 'postgres';
  $db_name = $env['DRUPAL_DB_NAME'] ?? 'drupal';
  $db_user = $env['DRUPAL_DB_USER'] ?? 'drupal';
  $db_pass = $env['DRUPAL_DB_PASS'] ?? 'drupal';
  $databases['default']['default'] = [
    'driver' => 'pgsql',
    'database' => $db_name,
    'username' => $db_user,
    'password' => $db_pass,
    'host' => $db_host,
    'port' => '5432',
    'prefix' => '',
    'namespace' => 'Drupal\\Core\\Database\\Driver\\pgsql',
    'driver_options' => [],
  ];
}

/**
 * Datei-/Temp-Pfade – optional, falls ihr spezielle Pfade wollt.
 * Beispiele:
 * $settings['file_public_path'] = 'sites/default/files';
 * $settings['file_private_path'] = '/opt/drupal/private';
 */

/**
 * Performance-Hinweise:
 * Nach Änderung dieser Datei Cache leeren:
 *   drush cr
 */
