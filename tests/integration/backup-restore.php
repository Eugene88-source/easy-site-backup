<?php
/**
 * Повний цикл: бекап → зміни → відновлення HTTP-кроками за токеном → перевірки;
 * потім відновлення з пошкодженого архіву.
 *
 * Запуск: php tests/integration/backup-restore.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

global $wpdb;

$keep     = esb_test_job_ids();
$post_id  = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' ORDER BY ID LIMIT 1" );
$style    = get_stylesheet_directory() . '/style.css';
$new_file = ABSPATH . 'esb-new-file.txt';
// Drop-in кешу, прив'язаний до сервера, не відновлюється. advanced-cache.php без WP_CACHE не
// завантажується, тож тестовий файл сайту не шкодить; справжній чужий не чіпаємо.
$dropin   = WP_CONTENT_DIR . '/advanced-cache.php';
$dropin_t = ! file_exists( $dropin );

$wpdb->update( $wpdb->posts, array( 'post_title' => 'Original title' ), array( 'ID' => $post_id ) );
delete_option( 'esb_test_marker' );
@unlink( $new_file );
$style_md5 = md5_file( $style );
if ( $dropin_t ) {
    file_put_contents( $dropin, "<?php\n// esb test: from backup\n" );
}

echo "== full backup\n";
$job = esb_test_backup( 'full' );
check( 'done' === $job->data['status'], 'full backup done ' . $job->data['error'] );

// Зміни після бекапу.
$wpdb->update( $wpdb->posts, array( 'post_title' => 'CHANGED' ), array( 'ID' => $post_id ) );
update_option( 'esb_test_marker', 'after-backup' );
file_put_contents( $style, "\n/* changed after backup */\n", FILE_APPEND );
file_put_contents( $new_file, 'new' );
if ( $dropin_t ) {
    file_put_contents( $dropin, "<?php\n// esb test: live\n" );
}

echo "== access\n";
$result = esb_test_ajax( array( 'action' => 'esb_restore_step', 'token' => 'wrong' ) );
check( is_array( $result ) && false === $result['success'], 'wrong token rejected' );
check( is_wp_error( ESB_Restore::start( 'nonexistent' ) ), 'restore of unknown backup rejected' );

echo "== restore\n";
$state = ESB_Restore::start( $job->id );
check( ! is_wp_error( $state ), 'restore started' );
$blocked = ESB_Backup::start( 'db' );
check( is_wp_error( $blocked ), 'backup blocked during restore' );
check( is_wp_error( ESB_Restore::start( $job->id ) ), 'second restore blocked' );

$status = esb_test_restore_steps( $state['token'] );
wp_cache_flush();
$final = ESB_Restore::load();

check( $status && 'done' === $status['status'], 'restore done ' . $final['error'] );
check( 'Original title' === $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $post_id ) ), 'post title restored' );
check( null === esb_test_option( 'esb_test_marker' ), 'option added after backup is gone' );
clearstatcache();
check( md5_file( $style ) === $style_md5, 'theme file restored' );
check( file_exists( $new_file ), 'file created after backup kept' );
if ( $dropin_t ) {
    check( false !== strpos( file_get_contents( $dropin ), 'live' ), 'cache drop-in not restored' );
}
check( array() === esb_test_temp_tables(), 'no esbr_/esbo_ tables left' );
check( 0 === strpos( ESB_Restore::sql_path( $final ), $job->dir() . '/' ), 'dump extracted into the backup folder (random name), not storage root' );
check( ! file_exists( ESB_Restore::sql_path( $final ) ), 'restore.sql removed' );
check( (bool) preg_match( '/^restore-[a-z0-9]{32}\.json$/', basename( (string) ESB_Restore::state_path() ) ), 'state file has random name: ' . basename( (string) ESB_Restore::state_path() ) );
foreach ( ESB_Restore::LEGACY_FILES as $legacy ) {
    check( ! file_exists( ESB_Storage::dir() . '/' . $legacy ), "no fixed-name $legacy in storage root" );
}
check( 0 === $final['files']['failed'], "files: done {$final['files']['done_files']}, failed {$final['files']['failed']}, skipped {$final['files']['skipped']}" );
check( false !== strpos( file_get_contents( $job->path( 'log.txt' ) ), 'Відновлення: готово' ), 'restore logged in backup log' );
check( 200 === wp_remote_retrieve_response_code( wp_remote_get( home_url( '/' ) ) ), 'front page 200 after restore' );

echo "== corrupt archive\n";
$job2 = esb_test_backup( 'db' );
update_option( 'esb_test_marker', 'must-survive' );
$fh = fopen( $job2->path( ESB_Storage::ARCHIVE ), 'r+b' );
fseek( $fh, 2000 );
$byte = fread( $fh, 1 );
fseek( $fh, 2000 );
fwrite( $fh, chr( ord( $byte ) ^ 0xFF ) );
fclose( $fh );

$state  = ESB_Restore::start( $job2->id );
$status = esb_test_restore_steps( $state['token'] );
check( $status && 'failed' === $status['status'], 'corrupt archive → restore failed: ' . ( $status ? $status['error'] : '-' ) );
wp_cache_flush();
check( 'must-survive' === esb_test_option( 'esb_test_marker' ), 'database unchanged after failed restore' );
check( array() === esb_test_temp_tables(), 'temp tables cleaned after failure' );

delete_option( 'esb_test_marker' );
@unlink( $new_file );
if ( $dropin_t ) {
    @unlink( $dropin );
}
ESB_Restore::mark_seen();
esb_test_cleanup( $keep );

finish();
