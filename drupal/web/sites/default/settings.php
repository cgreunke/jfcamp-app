<?php

// phpcs:ignoreFile

/**
 * @file
 * Drupal site-specific configuration file.
 *
 * Diese Datei bleibt nahe am Core-Standard. Datenbank & Proxy/Hosts kommen
 * ab jetzt aus settings.env.php (ENV-Variablen). Am Ende dieser Datei werden
 * settings.env.php und settings.local.php eingebunden.
 */

/**
 * Basis-Setup aus Core (aus Platzgründen gekürzt – deine originale
 * Kommentarsektion kann bleiben; sie ist funktional egal)
 */

$databases = [];

/**
 * Sicherheit / technische Defaults – unverändert belassen
 */
$settings['rebuild_access'] = FALSE;
$settings['update_free_access'] = FALSE;

/**
 * Hash salt: aus deiner bestehenden Datei übernommen.
 */
$settings['hash_salt'] = 'Pz6MUQsToP_3UsaPNePBGW2xbo2SJbr0_JvIwdYeH-sZeFQsBRPrKXku9yltxQJ84PUz2mZsDQ';

/**
 * Services & CORS: bleibt wie gehabt.
 */
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/cors.local.yml';

/**
 * Filesystem-Pfade (öffentlich & privat)
 * Belassen wie in deiner Datei.
 */
$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = $app_root . '/' . $site_path . '/private';
// Optional: Temp-Verzeichnis explizit setzen
# $settings['file_temp_path'] = '/tmp';

/**
 * Verzeichnisse, die der File-Scanner ignoriert
 */
$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];

/**
 * Entity-Update-Einstellungen
 */
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;

/**
 * Config-Sync-Verzeichnis – aus deiner Datei übernommen.
 * Falls du das Verzeichnis umziehst, hier anpassen.
 */
# $settings['config_sync_directory'] = 'sites/default/files/config_siSF-8YbJM8nvFwaRzTKEH1qngYbafXyvvRdxb-xSX_YV_XTSKonLwFJXoZhc08D4TEN-jHSAQ/sync';
$settings['config_sync_directory'] = '../drupal/config/sync';


/**
 * JFCamp Matching Default Endpoint
 * Nimmt MATCHING_BASE_URL aus ENV, sonst Fallback.
 */
$settings['jfcamp_matching_default_endpoint'] = getenv('MATCHING_BASE_URL') ?: 'http://matching:5001';

/**
 * *** WICHTIG ***
 * Ganz am Ende: unsere ENV-/Local-Includes einbinden.
 * (Ersetzt den früheren $app_root/$site_path-Include.)
 */
if (file_exists(__DIR__ . '/settings.env.php')) {
  include __DIR__ . '/settings.env.php';
}
if (file_exists(__DIR__ . '/settings.local.php')) {
  include __DIR__ . '/settings.local.php';
}
