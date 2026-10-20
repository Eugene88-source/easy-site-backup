<?php
/**
 * Завантаження WordPress для інтеграційних тестів.
 *
 * Шлях до WordPress — першим аргументом або в WP_PATH. Тести працюють із живим сайтом:
 * створюють і відновлюють копії, тож запускайте їх лише на тестовому сайті.
 */

require dirname( __DIR__ ) . '/lib.php';

$wp_path = isset( $argv[1] ) ? $argv[1] : getenv( 'WP_PATH' );
if ( ! $wp_path || ! is_file( $wp_path . '/wp-load.php' ) ) {
    fwrite( STDERR, "Usage: php {$argv[0]} /path/to/wordpress\n" );
    exit( 2 );
}

if ( ! defined( 'WP_ADMIN' ) ) {
    define( 'WP_ADMIN', true );
}
// Інакше WordPress замінить фатальну помилку сторінкою «критична помилка» без тексту.
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

require $wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) reset( $admins ) );

if ( ! class_exists( 'ESB_Admin' ) ) {
    fwrite( STDERR, "Easy Site Backup is not active on this site\n" );
    exit( 2 );
}

/**
 * Ідентифікатори наявних копій — щоб наприкінці видалити лише створені тестом.
 */
function esb_test_job_ids() {
    return array_map(
        function ( $job ) {
            return $job->id;
        },
        ESB_Storage::list_jobs()
    );
}

function esb_test_cleanup( array $keep ) {
    foreach ( ESB_Storage::list_jobs() as $job ) {
        if ( ! in_array( $job->id, $keep, true ) ) {
            ESB_Storage::delete_dir( $job->id );
        }
    }
}

/**
 * Створює копію, виконуючи кроки в цьому ж процесі.
 */
function esb_test_backup( $type, $scheduled = false ) {
    $job = ESB_Backup::start( $type, $scheduled );
    if ( is_wp_error( $job ) ) {
        check( false, "backup $type start: " . $job->get_error_message() );
        finish();
    }

    $started = microtime( true );
    do {
        $job = ESB_Backup::step( $job->id );
    } while ( $job->is_running() );

    printf( "  backup %s: %s, %s, %.1fs\n", $type, $job->data['status'], html_entity_decode( size_format( $job->size() ) ), microtime( true ) - $started );

    return $job;
}

/**
 * POST на admin-ajax.php без авторизації — як браузер після заміни бази.
 */
function esb_test_ajax( array $data ) {
    $response = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'body' => $data, 'timeout' => 300 ) );

    if ( is_wp_error( $response ) ) {
        echo '  HTTP error: ' . $response->get_error_message() . "\n";
        return null;
    }

    $json = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $json ) ) {
        echo '  HTTP ' . wp_remote_retrieve_response_code( $response ) . ': ' . substr( wp_remote_retrieve_body( $response ), 0, 500 ) . "\n";
    }

    return $json;
}

/**
 * Виконує кроки відновлення HTTP-запитами з токеном, поки воно не завершиться.
 */
function esb_test_restore_steps( $token ) {
    $steps = 0;
    do {
        $result = esb_test_ajax( array( 'action' => 'esb_restore_step', 'token' => $token ) );
        $steps++;
        if ( ! $result || empty( $result['success'] ) ) {
            echo '  step error: ' . wp_json_encode( $result ) . "\n";
            return null;
        }
        echo "  [{$result['data']['percent']}%] {$result['data']['message']}\n";
    } while ( 'running' === $result['data']['status'] && $steps < 300 );

    return $result['data'];
}

/**
 * Опція напряму з бази, повз кеш.
 */
function esb_test_option( $name ) {
    global $wpdb;

    return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
}

function esb_test_temp_tables() {
    global $wpdb;

    return $wpdb->get_col( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE 'esbr\\_%' OR TABLE_NAME LIKE 'esbo\\_%')" );
}
