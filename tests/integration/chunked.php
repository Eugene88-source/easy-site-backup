<?php
/**
 * Поділ на частини: дамп бази частинами (database-NNN.sql), великі файли частинами між
 * кроками в обидва боки, відновлення зі старого архіву з одним database.sql.
 *
 * Межі зменшено фільтрами (частина дампу 64 КБ, «великий» файл від 512 КБ, крок 0,2 с), тож
 * усі шляхи проходять на невеликому сайті. Кроки виконуються в цьому ж процесі, щоб діяли фільтри.
 *
 * Запуск: php tests/integration/chunked.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

global $wpdb;

add_filter(
    'esb_dump_part_bytes',
    function () {
        return 64 * KB_IN_BYTES;
    }
);
add_filter(
    'esb_large_file_bytes',
    function () {
        return 512 * KB_IN_BYTES;
    }
);
add_filter(
    'esb_step_seconds',
    function () {
        return 0.2;
    }
);

$keep  = esb_test_job_ids();
$table = $wpdb->prefix . 'esb_chunk_test';
$dir   = wp_upload_dir()['basedir'] . '/esb-chunk-test';

// Таблиця на ~700 КБ дампу і кілька файлів, більших за поріг.
$wpdb->query( "DROP TABLE IF EXISTS $table" );
$wpdb->query( "CREATE TABLE $table (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, v TEXT) DEFAULT CHARSET=utf8mb4" );
for ( $batch = 0; $batch < 6; $batch++ ) {
    $values = array();
    for ( $i = 0; $i < 500; $i++ ) {
        $values[] = $wpdb->prepare( '(%s)', str_repeat( "рядок $batch-$i; ", 12 ) . wp_generate_password( 20, true, true ) );
    }
    $wpdb->query( "INSERT INTO $table (v) VALUES " . implode( ',', $values ) );
}
$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

wp_mkdir_p( $dir );
$large = array();
foreach ( array( 'a.mp4', 'b.zip', 'c.bin' ) as $i => $name ) {
    file_put_contents( "$dir/$name", random_bytes( 2 * MB_IN_BYTES + $i * 777 ) );
    $large[ $name ] = md5_file( "$dir/$name" );
}

echo "== backup in short steps\n";
$job   = ESB_Backup::start( 'full' );
$steps = 0;
do {
    $job = ESB_Backup::step( $job->id );
    $steps++;
} while ( $job->is_running() && $steps < 20000 );
check( 'done' === $job->data['status'], "backup done in $steps steps " . $job->data['error'] );

$reader   = new ESB_Zip_Reader( $job->path( ESB_Storage::ARCHIVE ) );
$position = $reader->cd_offset;
$parts    = array();
$stored   = array();
$manifest = null;
while ( $entry = $reader->next_entry( $position ) ) {
    if ( preg_match( '/^database-(\d{3})\.sql$/', $entry['name'], $m ) ) {
        $parts[] = (int) $m[1];
    } elseif ( 0 === strpos( $entry['name'], 'files/wp-content/uploads/esb-chunk-test/' ) ) {
        $stored[ basename( $entry['name'] ) ] = $entry['method'];
    } elseif ( 'manifest.json' === $entry['name'] ) {
        $manifest = json_decode( $reader->read_string( $entry, MB_IN_BYTES ), true );
    }
}
$reader->close();
check( count( $parts ) > 3 && range( 1, count( $parts ) ) === $parts, 'dump split into ' . count( $parts ) . ' numbered parts' );
check( $manifest && count( $parts ) === $manifest['db_parts'], 'manifest db_parts matches' );
check( 3 === count( array_filter( $stored, function ( $method ) { return 0 === $method; } ) ), 'large files stored (written in parts)' );
check( ! glob( $job->path( 'database-*.sql.gz' ) ), 'temporary dump parts removed' );

echo "== changes\n";
update_option( 'esb_test_marker', 'after-backup' );
$wpdb->query( "DELETE FROM $table WHERE id % 2 = 0" );
unlink( "$dir/a.mp4" );
file_put_contents( "$dir/b.zip", 'changed' );

echo "== restore in short steps\n";
$state = ESB_Restore::start( $job->id );
$steps = 0;
do {
    $state = ESB_Restore::step( $state['token'] );
    $steps++;
} while ( 'running' === $state['status'] && $steps < 20000 );
wp_cache_flush();
check( 'done' === $state['status'], "restore done in $steps steps " . $state['error'] );
check( null === esb_test_option( 'esb_test_marker' ), 'option added after backup is gone' );
check( $rows === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), "all $rows rows back from " . count( $parts ) . ' dump parts' );
clearstatcache();
foreach ( $large as $name => $md5 ) {
    check( file_exists( "$dir/$name" ) && md5_file( "$dir/$name" ) === $md5, "large file $name restored identical" );
}
check( ! glob( "$dir/*.esb-restore" ), 'no temporary .esb-restore files' );

echo "== old archive with a single database.sql\n";
// Архів як у версіях до 0.5.1: увесь дамп одним записом, без db_parts у manifest.
$legacy   = ESB_Job::create( 'db' );
$writer   = new ESB_Zip_Writer( $legacy->path( ESB_Storage::ARCHIVE ), $legacy->path( 'archive.cd' ), array( 'size' => 0, 'cd_size' => 0, 'entries' => 0 ) );
$reader   = new ESB_Zip_Reader( $job->path( ESB_Storage::ARCHIVE ) );
$position = $reader->cd_offset;
$sql      = fopen( 'php://temp', 'w+b' );
$entries  = array();
while ( $entry = $reader->next_entry( $position ) ) {
    if ( preg_match( '/^database-\d{3}\.sql$/', $entry['name'] ) ) {
        $entries[ $entry['name'] ] = $entry;
    }
}
ksort( $entries );
foreach ( $entries as $entry ) {
    $reader->extract( $entry, $sql );
}
$reader->close();
$size = ftell( $sql );
rewind( $sql );
$writer->add_stream( $sql, 'database.sql', true, $size, time() );
fclose( $sql );
unset( $manifest['db_parts'] );
$manifest['type'] = 'db';
$writer->add_string( 'manifest.json', wp_json_encode( $manifest ) );
$writer->finish();
$writer->close();
@unlink( $legacy->path( 'archive.cd' ) );
$legacy->data['status'] = 'done';
$legacy->data['size']   = filesize( $legacy->path( ESB_Storage::ARCHIVE ) );
$legacy->save();

update_option( 'esb_test_marker', 'after-backup' );
$wpdb->query( "DELETE FROM $table WHERE id < 100" );
$state = ESB_Restore::start( $legacy->id );
do {
    $state = ESB_Restore::step( $state['token'] );
} while ( 'running' === $state['status'] );
wp_cache_flush();
check( 'done' === $state['status'], 'legacy single-file dump restored ' . $state['error'] );
check( null === esb_test_option( 'esb_test_marker' ) && $rows === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'legacy restore brought data back' );

$wpdb->query( "DROP TABLE IF EXISTS $table" );
array_map( 'unlink', glob( "$dir/*" ) );
rmdir( $dir );
delete_option( 'esb_test_marker' );
ESB_Restore::mark_seen();
esb_test_cleanup( $keep );

finish();
