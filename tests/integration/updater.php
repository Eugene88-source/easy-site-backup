<?php
/**
 * Оновлення з GitHub: плагін показує нову версію, але сам нічого не встановлює
 * й не вмикає собі автооновлення. Реліз підмінено (99.0), тож мережа до GitHub не потрібна.
 *
 * Запуск: php tests/integration/updater.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

$file      = ESB_Updater::basename();
$orig_auto = get_site_option( 'auto_update_plugins', array() );
$orig_flag = get_site_option( ESB_Updater::AUTO_UPDATE_FLAG );
$version   = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false )['Version'];

echo "== auto-update\n";
check( ! method_exists( 'ESB_Updater', 'enable_auto_update_once' ), 'plugin no longer enables auto-update for itself' );

// Сайт після версії, яка сама ввімкнула автооновлення.
update_site_option( 'auto_update_plugins', array_merge( (array) $orig_auto, array( $file ) ) );
update_site_option( ESB_Updater::AUTO_UPDATE_FLAG, 1 );
ESB_Updater::disable_auto_update_once();
check( ! in_array( $file, (array) get_site_option( 'auto_update_plugins' ), true ), 'auto-update enabled by old version is turned off' );
check( false === get_site_option( ESB_Updater::AUTO_UPDATE_FLAG ), 'migration flag removed' );

// Увімкнене адміністратором (без позначки) не чіпаємо.
update_site_option( 'auto_update_plugins', array( $file ) );
ESB_Updater::disable_auto_update_once();
check( in_array( $file, (array) get_site_option( 'auto_update_plugins' ), true ), 'auto-update enabled by admin is kept' );
update_site_option( 'auto_update_plugins', $orig_auto );
if ( $orig_flag ) {
    update_site_option( ESB_Updater::AUTO_UPDATE_FLAG, $orig_flag );
}

echo "== hourly check\n";
$fake = array(
    'version' => '99.0',
    'package' => 'https://github.com/' . ESB_Updater::REPO . '/releases/download/v99.0/easy-site-backup.zip',
    'url'     => 'https://github.com/' . ESB_Updater::REPO . '/releases/tag/v99.0',
    'notes'   => '- test',
    'date'    => gmdate( 'c' ),
);
add_filter(
    'pre_site_transient_' . ESB_Updater::CACHE,
    function () use ( $fake ) {
        return $fake;
    }
);
// Старий transient без нашої версії: щогодинна перевірка має змусити WordPress перевірити знову.
set_site_transient( 'update_plugins', (object) array( 'last_checked' => time(), 'response' => array(), 'no_update' => array(), 'checked' => array() ) );

$installs = 0;
add_filter(
    'upgrader_pre_download',
    function ( $reply ) use ( &$installs ) {
        $installs++;
        return new WP_Error( 'test', 'blocked' );
    }
);

ESB_Updater::hourly_check();
$updates = get_site_transient( 'update_plugins' );
$offered = isset( $updates->response[ $file ] ) ? $updates->response[ $file ] : null;
check( $offered && '99.0' === $offered->new_version, 'update 99.0 shown on Plugins page' );
check( 0 === $installs, 'nothing downloaded or installed' );
wp_clean_plugins_cache( false ); // false: не видаляти список оновлень, він потрібен далі.
check( get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false )['Version'] === $version, "installed version unchanged ($version)" );

// Повторна перевірка не смикає WordPress, якщо версія вже відома.
$checked = $updates->last_checked;
sleep( 1 );
ESB_Updater::hourly_check();
check( get_site_transient( 'update_plugins' )->last_checked === $checked, 'known version → no extra update check' );

delete_site_transient( 'update_plugins' );

finish();
