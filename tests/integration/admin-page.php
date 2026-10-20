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
esb_test_cleanup( $keep );

finish();
