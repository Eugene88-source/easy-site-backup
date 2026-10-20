<?php
/**
 * Перервані кроки: кожен крок — окремий процес, і частину з них тест «вбиває» у випадковий
 * момент (як хостинг за лімітом часу чи пам'яті). Бекап і відновлення мають продовжитися
 * й дати точно той самий сайт: контрольні суми таблиць і файлів збігаються зі станом до бекапу
 * (задвоєний чи втрачений рядок змінив би суму). Три обриви поспіль — зрозуміла помилка.
 *
 * Запуск: php tests/integration/interrupt.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

global $wpdb;

if ( ! function_exists( 'proc_open' ) ) {
    echo "SKIP: proc_open disabled\n";
    exit( 0 );
}

$keep   = esb_test_job_ids();
$table  = $wpdb->prefix . 'esb_int_test';
$nopk   = $wpdb->prefix . 'esb_int_nopk';
$dir    = wp_upload_dir()['basedir'] . '/esb-int-test';
$killed = array();

/**
 * Запускає один крок у дочірньому процесі. З $kill_after — вбиває його через стільки секунд.
 *
 * @return string killed | вивід кроку (статус)
 */
function child_step( $kind, $arg, $kill_after = null, array $env = array() ) {
    $cmd = array( PHP_BINARY );
    if ( php_ini_loaded_file() ) {
        $cmd[] = '-c';
        $cmd[] = php_ini_loaded_file();
    }
    array_push( $cmd, __DIR__ . '/step-child.php', untrailingslashit( ABSPATH ), $kind, $arg );

    $proc = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env ? array_merge( getenv(), $env ) : null );

    if ( null !== $kill_after ) {
        usleep( (int) ( $kill_after * 1000000 ) );
        $status = proc_get_status( $proc );
        if ( $status['running'] ) {
            proc_terminate( $proc, 9 ); // SIGKILL: без finally, без збереження стану
            fclose( $pipes[1] );
            fclose( $pipes[2] );
            proc_close( $proc );
            return 'killed';
        }
    }

    $out = stream_get_contents( $pipes[1] );
    $err = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    proc_close( $proc );

    if ( '' !== trim( $err ) ) {
        echo '  child stderr: ' . substr( trim( $err ), 0, 300 ) . "\n";
    }

    return trim( $out );
}

/**
 * Виконує завдання, чергуючи «вбиті» й нормальні кроки, доки воно не завершиться.
 *
 * @param callable $phase Повертає поточну фазу (для статистики обривів).
 */
function run_with_kills( $kind, $arg, $phase, $label ) {
    global $killed;

    $status = 'running';
    for ( $i = 0; $i < 600 && 'running' === $status; $i++ ) {
        if ( 0 === $i % 2 ) {
            $before = call_user_func( $phase );
            // Від запуску WordPress (~0,1–0,3 с) до кінця кроку (1 с).
            // Після запуску WordPress (~0,1–0,3 с), до кінця кроку (1 с).
            if ( 'killed' === child_step( $kind, $arg, mt_rand( 300, 1150 ) / 1000 ) ) {
                $killed[ $label ][ $before ] = isset( $killed[ $label ][ $before ] ) ? $killed[ $label ][ $before ] + 1 : 1;
            }
            continue;
        }
        $status = child_step( $kind, $arg, null, array( 'ESB_STEP' => '0.25' ) );
    }

    return $status;
}

function checksums() {
    global $wpdb, $table, $nopk, $dir;

    $sums = array();
    foreach ( array( $table, $nopk, $wpdb->posts, $wpdb->users ) as $t ) {
        $sums[ $t ] = $wpdb->get_row( "CHECKSUM TABLE $t", ARRAY_N )[1];
    }
    $files = glob( "$dir/*" );
    sort( $files );
    foreach ( $files as $file ) {
        $sums[ basename( $file ) ] = md5_file( $file );
    }

    return $sums;
}

echo "== data\n";
$wpdb->query( "DROP TABLE IF EXISTS $table" );
$wpdb->query( "DROP TABLE IF EXISTS $nopk" );
$wpdb->query( "CREATE TABLE $table (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, v TEXT) DEFAULT CHARSET=utf8mb4" );
$wpdb->query( "CREATE TABLE $nopk (k VARCHAR(40), v TEXT) DEFAULT CHARSET=utf8mb4" );
// Даних достатньо, щоб робота тривала десятки кроків навіть на маленькому чистому сайті (як у CI).
for ( $batch = 0; $batch < 300; $batch++ ) {
    $values = array();
    $plain  = array();
    for ( $i = 0; $i < 1000; $i++ ) {
        $values[] = $wpdb->prepare( '(%s)', "рядок $batch-$i 'лапки' % " . wp_generate_password( 60, true, true ) );
        if ( $i < 100 ) {
            $plain[] = $wpdb->prepare( '(%s, %s)', "k-$batch-$i", str_repeat( 'v', 150 ) );
        }
    }
    $wpdb->query( "INSERT INTO $table (v) VALUES " . implode( ',', $values ) );
    $wpdb->query( "INSERT INTO $nopk (k, v) VALUES " . implode( ',', $plain ) );
}
wp_mkdir_p( $dir );
for ( $i = 0; $i < 12; $i++ ) {
    file_put_contents( "$dir/large-$i.bin", random_bytes( 3 * MB_IN_BYTES + $i * 1001 ) );
}
for ( $i = 0; $i < 200; $i++ ) {
    file_put_contents( "$dir/small-$i.txt", str_repeat( "small $i\n", 300 ) );
}
$before = checksums();
printf( "  rows: %d + %d (no PK), files: %d\n", $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), $wpdb->get_var( "SELECT COUNT(*) FROM $nopk" ), count( glob( "$dir/*" ) ) );

echo "== backup with killed steps\n";
$job    = ESB_Backup::start( 'full' );
$id     = $job->id;
$status = run_with_kills(
    'backup',
    $id,
    function () use ( $id ) {
        $job = ESB_Job::load( $id );
        return $job ? $job->data['phase'] : '?';
    },
    'backup'
);
$job = ESB_Job::load( $id );
check( 'done' === $status && 'done' === $job->data['status'], 'backup done despite kills ' . $job->data['error'] );
echo '  killed in phases: ' . wp_json_encode( $killed['backup'] ) . "\n";
check( count( $killed['backup'] ) >= 3, 'kills landed in several backup phases' );

// Архів цілий: кожен запис читається, і CRC сходиться.
$reader   = new ESB_Zip_Reader( $job->path( ESB_Storage::ARCHIVE ) );
$position = $reader->cd_offset;
$entries  = 0;
$broken   = 0;
while ( $entry = $reader->next_entry( $position ) ) {
    $entries++;
    try {
        $reader->extract( $entry, fopen( 'php://temp', 'w+b' ) );
    } catch ( RuntimeException $e ) {
        $broken++;
    }
}
$reader->close();
check( $entries > 0 && 0 === $broken, "all $entries archive entries pass CRC" );
if ( class_exists( 'ZipArchive' ) ) {
    $za = new ZipArchive();
    check( true === $za->open( $job->path( ESB_Storage::ARCHIVE ), ZipArchive::CHECKCONS ), 'ZipArchive CHECKCONS' );
    $za->close();
}

echo "== changes\n";
update_option( 'esb_test_marker', 'after-backup' );
$wpdb->query( "DELETE FROM $table WHERE id % 3 = 0" );
$wpdb->query( "UPDATE $nopk SET v = 'changed' WHERE k LIKE 'k-1-%'" );
file_put_contents( "$dir/large-2.bin", 'changed' );
unlink( "$dir/small-7.txt" );

echo "== restore with killed steps\n";
$state  = ESB_Restore::start( $id );
$status = run_with_kills(
    'restore',
    $state['token'],
    function () {
        $state = ESB_Restore::load();
        return $state ? $state['phase'] : '?';
    },
    'restore'
);
wp_cache_flush();
$final = ESB_Restore::load();
check( 'done' === $status, 'restore done despite kills ' . $final['error'] );
echo '  killed in phases: ' . wp_json_encode( $killed['restore'] ) . "\n";
check( count( $killed['restore'] ) >= 2, 'kills landed in several restore phases' );

$after = checksums();
foreach ( $before as $name => $sum ) {
    if ( ! isset( $after[ $name ] ) || $after[ $name ] !== $sum ) {
        $info = file_exists( "$dir/$name" ) ? ' (size ' . filesize( "$dir/$name" ) . ( file_exists( "$dir/$name.esb-restore" ) ? ', .esb-restore ' . filesize( "$dir/$name.esb-restore" ) : '' ) . ')' : '';
        check( false, "$name differs after restore$info" );
    }
}
if ( $before !== $after ) {
    echo "  restore log:\n    " . implode( "\n    ", array_slice( file( ESB_Job::load( $id )->path( 'log.txt' ), FILE_IGNORE_NEW_LINES ), -12 ) ) . "\n";
}
check( $before === $after, 'tables and files identical to the state before backup (no duplicated or lost rows)' );
check( null === esb_test_option( 'esb_test_marker' ), 'option added after backup is gone' );
check( array() === esb_test_temp_tables(), 'no temporary tables left' );
check( ! glob( "$dir/*.esb-restore" ), 'no temporary .esb-restore files' );

echo "== killed right after the tables were swapped\n";
$swap_job = esb_test_backup( 'db' );
update_option( 'esb_test_marker', 'after-backup' );
$state = ESB_Restore::start( $swap_job->id );
$token = $state['token'];
// Усі кроки — з обривом після заміни таблиць: крок може дійти до неї будь-коли.
for ( $i = 0; $i < 200; $i++ ) {
    child_step( 'restore', $token, null, array( 'ESB_EXIT_AFTER_SWAP' => '1' ) );
    $mid = ESB_Restore::load();
    if ( 'running' !== $mid['status'] || ! empty( $mid['db']['swapping'] ) ) {
        break;
    }
}
check( 'running' === $mid['status'] && ! empty( $mid['db']['swapping'] ), 'process died after RENAME, before saving state' );
wp_cache_flush();
check( null === esb_test_option( 'esb_test_marker' ), 'swap itself already done (backup tables are live)' );
child_step( 'restore', $token );
$done = ESB_Restore::load();
check( 'done' === $done['status'], 'next step recognised the finished swap and completed ' . $done['error'] );
check( array() === esb_test_temp_tables(), 'old and temporary tables cleaned up' );
check( checksums() === $before, 'data intact after the interrupted swap' );

echo "== three kills in a row\n";
function kill_until_attempts( $kind, $arg, $attempts ) {
    for ( $i = 0; $i < 40 && $attempts() < ESB_Backup::MAX_ATTEMPTS; $i++ ) {
        child_step( $kind, $arg, 0.7 );
    }
    return $attempts();
}

$job2 = ESB_Backup::start( 'db' );
$id2  = $job2->id;
$n    = kill_until_attempts(
    'backup',
    $id2,
    function () use ( $id2 ) {
        return (int) ESB_Job::load( $id2 )->data['attempts'];
    }
);
child_step( 'backup', $id2 );
$job2 = ESB_Job::load( $id2 );
check( ESB_Backup::MAX_ATTEMPTS === $n && 'failed' === $job2->data['status'] && false !== strpos( $job2->data['error'], 'переривався' ), 'backup: clear error after ' . $n . ' kills: ' . $job2->data['error'] );
check( null === ESB_Backup::active_job(), 'no backup left hanging' );

update_option( 'esb_test_marker', 'must-survive' );
$sums  = checksums();
$state = ESB_Restore::start( $id );
$token = $state['token'];
// Пропускаємо швидкі фази, щоб обриви припали на імпорт бази.
while ( in_array( ESB_Restore::load()['phase'], array( 'prepare', 'db_extract' ), true ) ) {
    child_step( 'restore', $token );
}
$n = kill_until_attempts(
    'restore',
    $token,
    function () {
        return (int) ESB_Restore::load()['attempts'];
    }
);
child_step( 'restore', $token );
wp_cache_flush();
$failed = ESB_Restore::load();
check( 'failed' === $failed['status'] && false !== strpos( $failed['error'], 'переривався' ), 'restore: clear error after ' . $n . ' kills: ' . $failed['error'] );
check( 'must-survive' === esb_test_option( 'esb_test_marker' ) && checksums() === $sums, 'database untouched by the failed restore' );
check( array() === esb_test_temp_tables(), 'temporary tables cleaned after failure' );
check( null === ESB_Restore::active(), 'no restore left hanging' );

$wpdb->query( "DROP TABLE IF EXISTS $table" );
$wpdb->query( "DROP TABLE IF EXISTS $nopk" );
array_map( 'unlink', glob( "$dir/*" ) );
rmdir( $dir );
delete_option( 'esb_test_marker' );
ESB_Restore::mark_seen();
esb_test_cleanup( $keep );

finish();
