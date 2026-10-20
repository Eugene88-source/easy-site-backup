<?php
/**
 * Оновлення з GitHub: плагін один раз вмикає собі автооновлення, поважає вимкнене адміністратором,
 * а щогодинна перевірка встановлює нову версію лише за ввімкненого автооновлення.
 * Реліз підмінено (99.0), а завантаження заблоковано, тож мережа до GitHub не потрібна.
 *
 * Запуск: php tests/integration/updater.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

$file      = ESB_Updater::basename();
$orig_auto = get_site_option( 'auto_update_plugins', array() );
$orig_on   = get_site_option( ESB_Updater::AUTO_UPDATE_ON );
$orig_old  = get_site_option( ESB_Updater::OLD_AUTO_UPDATE_FLAG );
$version   = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false )['Version'];

function auto_update_on() {
    return in_array( ESB_Updater::basename(), (array) get_site_option( 'auto_update_plugins', array() ), true );
}

function set_auto_update( $on ) {
    $plugins = array_diff( (array) get_site_option( 'auto_update_plugins', array() ), array( ESB_Updater::basename() ) );
    if ( $on ) {
        $plugins[] = ESB_Updater::basename();
    }
    update_site_option( 'auto_update_plugins', array_values( $plugins ) );
}

echo "== enable once\n";
// Сайт після 0.4.4: автооновлення вимкнене, позначок немає.
set_auto_update( false );
delete_site_option( ESB_Updater::AUTO_UPDATE_ON );
update_site_option( ESB_Updater::OLD_AUTO_UPDATE_FLAG, 1 );
ESB_Updater::enable_auto_update_once();
check( auto_update_on(), 'auto-update turned on' );
check( (bool) get_site_option( ESB_Updater::AUTO_UPDATE_ON ), 'flag set' );
check( false === get_site_option( ESB_Updater::OLD_AUTO_UPDATE_FLAG ), 'old flag removed' );

set_auto_update( false ); // адміністратор вимкнув
ESB_Updater::enable_auto_update_once();
check( ! auto_update_on(), 'auto-update disabled by admin stays off' );

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

$downloads = array();
add_filter(
    'upgrader_pre_download',
    function ( $reply, $package ) use ( &$downloads ) {
        $downloads[] = $package;
        return new WP_Error( 'test', 'download blocked by test' );
    },
    10,
    2
);

// Старий список оновлень без нашої версії: перевірка має змусити WordPress перевірити знову.
set_site_transient( 'update_plugins', (object) array( 'last_checked' => time(), 'response' => array(), 'no_update' => array(), 'checked' => array() ) );

ESB_Updater::hourly_check(); // автооновлення вимкнене
$updates = get_site_transient( 'update_plugins' );
check( isset( $updates->response[ $file ] ) && '99.0' === $updates->response[ $file ]->new_version, 'update 99.0 shown' );
check( 0 === count( $downloads ), 'auto-update off → nothing downloaded' );

set_auto_update( true );
ESB_Updater::hourly_check();
check( 1 === count( $downloads ) && $fake['package'] === $downloads[0], 'auto-update on → release package downloaded once' );
check( false === get_option( 'auto_updater.lock' ), 'auto_updater lock released' );
wp_clean_plugins_cache( false );
check( get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false )['Version'] === $version, "installed version unchanged after blocked download ($version)" );

echo "== cleanup\n";
update_site_option( 'auto_update_plugins', $orig_auto );
foreach ( array( ESB_Updater::AUTO_UPDATE_ON => $orig_on, ESB_Updater::OLD_AUTO_UPDATE_FLAG => $orig_old ) as $name => $value ) {
    if ( false === $value ) {
        delete_site_option( $name );
    } else {
        update_site_option( $name, $value );
    }
}
delete_site_transient( 'update_plugins' );

finish();
