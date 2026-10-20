<?php
/**
 * Перевірка вільного місця (через фільтр esb_free_space).
 *
 * Запуск: php tests/integration/space.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

$keep = esb_test_job_ids();

// Повна копія для перевірки відновлення — до того, як фільтр «забере» місце.
$full = esb_test_backup( 'full' );

$free = 0;
add_filter(
    'esb_free_space',
    function () use ( &$free ) {
        return $free;
    }
);

$free   = 10 * MB_IN_BYTES;
$result = ESB_Backup::start( 'db' );
check( is_wp_error( $result ) && 'esb_space' === $result->get_error_code(), 'start blocked when space < margin' );

// Більше за запас, але менше за обсяг файлів сайту.
$free = ESB_Storage::SPACE_MARGIN + MB_IN_BYTES;
$job  = ESB_Backup::start( 'files' );
check( ! is_wp_error( $job ), 'start allowed above margin' );
do {
    $job = ESB_Backup::step( $job->id );
} while ( $job->is_running() );
check( 'failed' === $job->data['status'] && false !== strpos( $job->data['error'], 'Недостатньо місця' ), 'files backup failed at scan: ' . $job->data['error'] );
check( ! file_exists( $job->path( ESB_Storage::ARCHIVE ) ) && file_exists( $job->path( 'log.txt' ) ), 'archive removed, log kept' );

update_option( 'esb_test_marker', 'keep' );
// Рівно запас: дамп і найбільший файл у нього вже не вміщаються.
$free  = ESB_Storage::SPACE_MARGIN;
$state = ESB_Restore::start( $full->id );
do {
    $state = ESB_Restore::step( $state['token'] );
} while ( 'running' === $state['status'] );
check( 'failed' === $state['status'] && false !== strpos( $state['error'], 'Недостатньо місця' ), 'restore failed in prepare: ' . $state['error'] );
wp_cache_flush();
check( 'keep' === esb_test_option( 'esb_test_marker' ), 'database unchanged' );

$free   = null; // хостинг не повідомляє вільне місце
$result = ESB_Backup::start( 'db' );
check( ! is_wp_error( $result ), 'unknown free space → check skipped' );
if ( ! is_wp_error( $result ) ) {
    do {
        $result = ESB_Backup::step( $result->id );
    } while ( $result->is_running() );
}

delete_option( 'esb_test_marker' );
ESB_Restore::mark_seen();
esb_test_cleanup( $keep );

finish();
