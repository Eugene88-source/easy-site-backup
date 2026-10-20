<?php
/**
 * Сторінка плагіна: розмітка, дати як <time>, підказка про пояс, розклад, формат логу.
 *
 * Запуск: php tests/integration/admin-page.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

$keep        = esb_test_job_ids();
$orig_tz     = get_option( 'timezone_string' );
$orig_offset = get_option( 'gmt_offset' );
$job         = esb_test_backup( 'db' );

function render() {
    ob_start();
    ESB_Admin::render_page();
    return ob_get_clean();
}

foreach ( array( '', 'Europe/Kyiv' ) as $tz ) {
    update_option( 'timezone_string', $tz );
    update_option( 'gmt_offset', 0 );
    $label = $tz ? $tz : 'UTC';
    echo "== timezone $label\n";

    $html = render();
    check( substr_count( $html, '<div' ) === substr_count( $html, '</div>' ), 'balanced <div>' );
    check( 1 === substr_count( $html, 'class="esb-header"' ) && 3 === substr_count( $html, 'class="esb-card"' ), 'header and 3 cards' );
    check( false !== strpos( $html, 'id="esb-schedule-form"' ), 'schedule form' );

    // Заголовки карток є завжди, на відміну від форми списку, яка з'являється лише за наявності копій.
    $positions = array(
        strpos( $html, '<h2>' . __( 'Нова резервна копія', 'easy-site-backup' ) . '</h2>' ),
        strpos( $html, '<h2>' . __( 'Наявні резервні копії', 'easy-site-backup' ) . '</h2>' ),
        strpos( $html, '<h2>' . __( 'Розклад', 'easy-site-backup' ) . '</h2>' ),
    );
    check( ! in_array( false, $positions, true ) && $positions[0] < $positions[1] && $positions[1] < $positions[2], 'order: new backup → backups list → schedule' );
    check( false !== strpos( $html, 'class="esb-card" id="esb-schedule"' ), 'schedule card has anchor for redirect' );
    check( false !== strpos( $html, 'esb-restore' ) && false === strpos( $html, 'data-date' ), 'restore button without data-date' );

    $iso = gmdate( 'c', $job->data['created'] );
    check( false !== strpos( $html, '<time class="esb-time" datetime="' . $iso . '">' ), 'backup date as <time datetime>' );
    check( ( '' === $tz ) === ( false !== strpos( $html, 'id="esb-tz-notice"' ) ), 'timezone hint only for UTC site' );

    $job->log( 'page test' );
    $lines = file( $job->path( 'log.txt' ) );
    $last  = trim( end( $lines ) );
    $zone  = $tz ? '(EEST|EET)' : 'UTC';
    check( (bool) preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d ' . $zone . '  page test$/', $last ), "log line: $last" );
}

update_option( 'timezone_string', $orig_tz );
update_option( 'gmt_offset', $orig_offset );

echo "== plugins page\n";
$links = apply_filters( 'plugin_action_links_' . ESB_Updater::basename(), array( '<a href="#">Деактивувати</a>' ) );
check( 2 === count( $links ) && false !== strpos( $links[0], 'tools.php?page=easy-site-backup' ) && false !== strpos( $links[0], '>Налаштування</a>' ), 'Settings link first: ' . $links[0] );

echo "== save schedule\n";
$orig_settings = get_option( ESB_Schedule::OPTION );
$redirect      = null;
// wp_redirect() перехоплюємо винятком, щоб обробник не завершив процес через exit.
add_filter(
    'wp_redirect',
    function ( $location ) {
        throw new RuntimeException( $location );
    }
);
$_POST['schedule']    = ESB_Schedule::settings();
$_REQUEST['_wpnonce'] = wp_create_nonce( 'esb_save_schedule' );
try {
    ESB_Admin::handle_save_schedule();
} catch ( RuntimeException $e ) {
    $redirect = $e->getMessage();
}
check( $redirect && '#esb-schedule' === substr( $redirect, -13 ) && false !== strpos( $redirect, 'esb_notice=schedule_saved' ), "redirect to schedule: $redirect" );

$_GET['esb_notice'] = 'schedule_saved';
$html               = render();
$saved              = strpos( $html, 'Розклад збережено.' );
check( false !== $saved && $saved > strpos( $html, 'id="esb-schedule"' ) && false !== strpos( $html, 'notice-success inline' ), 'saved notice inside schedule card' );
unset( $_GET['esb_notice'], $_POST['schedule'], $_REQUEST['_wpnonce'] );

if ( false === $orig_settings ) {
    delete_option( ESB_Schedule::OPTION );
} else {
    update_option( ESB_Schedule::OPTION, $orig_settings );
}
ESB_Schedule::reschedule();
esb_test_cleanup( $keep );

finish();
