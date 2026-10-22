<?php
/**
 * Завантаження архіву з комп'ютера частинами через admin-ajax.php (як браузер, з multipart
 * і авторизацією адміністратора), перевірка архіву й відновлення з завантаженої копії.
 *
 * Запуск: php tests/integration/upload.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

$keep = esb_test_job_ids();

// Сесія адміністратора: cookie для запитів і nonce, прив'язаний до цієї сесії.
$user       = get_current_user_id();
$expiration = time() + HOUR_IN_SECONDS;
$token      = WP_Session_Tokens::get_instance( $user )->create( $expiration );
$cookie     = wp_generate_auth_cookie( $user, $expiration, 'logged_in', $token );

$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
$nonce                       = wp_create_nonce( 'esb_ajax' );

/**
 * POST multipart на admin-ajax.php від імені адміністратора.
 */
function upload_request( array $fields, $chunk_path = null ) {
    global $cookie, $nonce;

    $fields['nonce'] = $nonce;
    if ( $chunk_path ) {
        $fields['chunk'] = new CURLFile( $chunk_path, 'application/octet-stream', 'chunk.bin' );
    }

    $ch = curl_init( admin_url( 'admin-ajax.php' ) );
    curl_setopt_array(
        $ch,
        array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => array( 'Cookie: ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $cookie ) ),
        )
    );
    $body = curl_exec( $ch );
    curl_close( $ch );

    $json = json_decode( (string) $body, true );
    if ( ! is_array( $json ) ) {
        echo '  bad response: ' . substr( (string) $body, 0, 300 ) . "\n";
    }

    return $json;
}

function chunk_file( $data ) {
    $path = wp_tempnam( 'esb-chunk' );
    file_put_contents( $path, $data );
    return $path;
}

/**
 * Надсилає вміст частинами по $size байтів, повторюючи одну частину двічі (обірвана відповідь).
 */
function upload_content( $name, $content, $size ) {
    $start = upload_request( array( 'action' => 'esb_upload_start', 'name' => $name, 'size' => strlen( $content ) ) );
    if ( empty( $start['success'] ) ) {
        return $start;
    }

    $id     = $start['data']['id'];
    $offset = 0;
    $part   = 0;
    while ( $offset < strlen( $content ) ) {
        $path   = chunk_file( substr( $content, $offset, $size ) );
        $result = upload_request( array( 'action' => 'esb_upload_chunk', 'id' => $id, 'offset' => $offset ), $path );

        if ( 1 === $part ) {
            // Та сама частина ще раз — як після обірваної відповіді.
            $result = upload_request( array( 'action' => 'esb_upload_chunk', 'id' => $id, 'offset' => $offset ), $path );
        }
        unlink( $path );

        if ( empty( $result['success'] ) ) {
            return $result;
        }
        $offset = $result['data']['received'];
        $part++;
    }

    $finish             = upload_request( array( 'action' => 'esb_upload_finish', 'id' => $id ) );
    $finish['upload_id'] = $id;
    $finish['parts']     = $part;

    return $finish;
}

echo "== upload a real backup\n";
$source = esb_test_backup( 'db' );
update_option( 'esb_test_marker', 'after-backup' );
$archive = file_get_contents( $source->path( ESB_Storage::ARCHIVE ) );

$start = upload_request( array( 'action' => 'esb_upload_start', 'name' => 'x.zip', 'size' => 10 ) );
check( ! empty( $start['success'] ) && $start['data']['chunk_size'] >= 256 * KB_IN_BYTES, 'chunk size reported: ' . ( empty( $start['success'] ) ? '-' : size_format( $start['data']['chunk_size'] ) ) );
ESB_Storage::delete_dir( $start['data']['id'] );

$result = upload_content( 'esb-backup.zip', $archive, 64 * KB_IN_BYTES );
check( ! empty( $result['success'] ), "uploaded in {$result['parts']} parts (one sent twice): " . ( empty( $result['success'] ) ? wp_json_encode( $result ) : 'ok' ) );

$uploaded = ESB_Job::load( $result['upload_id'] );
check( $uploaded && $uploaded->has_archive() && 'db' === $uploaded->data['type'] && ! empty( $uploaded->data['uploaded'] ), 'listed as finished db backup, marked uploaded' );
check( $uploaded && md5_file( $uploaded->path( ESB_Storage::ARCHIVE ) ) === md5( $archive ), 'archive identical to the original' );
check( $uploaded && abs( $uploaded->data['created'] - $source->data['created'] ) <= 1, 'date = when the backup was made, not uploaded' );
check( $uploaded && ! file_exists( $uploaded->path( ESB_Upload::PART ) ), 'no .part file left' );

echo "== rejected uploads\n";
$bad = upload_content( 'notes.txt', 'hello', 1024 );
check( empty( $bad['success'] ), 'non-zip name rejected' );

$jobs_before = count( ESB_Storage::list_jobs() );
$bad         = upload_content( 'fake.zip', random_bytes( 200 * KB_IN_BYTES ), 64 * KB_IN_BYTES );
check( empty( $bad['success'] ) && false !== strpos( (string) $bad['data']['message'], 'пошкоджено' ), 'random bytes rejected: ' . ( isset( $bad['data']['message'] ) ? $bad['data']['message'] : '-' ) );
check( count( ESB_Storage::list_jobs() ) === $jobs_before, 'rejected upload removed' );
check( array() === glob( ESB_Storage::dir() . '/*/' . ESB_Upload::PART ), 'no .part file of rejected upload left on disk' );

$start = upload_request( array( 'action' => 'esb_upload_start', 'name' => 'gap.zip', 'size' => 1000 ) );
$path  = chunk_file( str_repeat( 'x', 100 ) );
$gap   = upload_request( array( 'action' => 'esb_upload_chunk', 'id' => $start['data']['id'], 'offset' => 500 ), $path );
unlink( $path );
check( empty( $gap['success'] ), 'gap in data rejected' );
$early = upload_request( array( 'action' => 'esb_upload_finish', 'id' => $start['data']['id'] ) );
check( empty( $early['success'] ), 'finish before all data rejected' );
ESB_Storage::delete_dir( $start['data']['id'] );

$anon = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'body' => array( 'action' => 'esb_upload_start', 'name' => 'a.zip', 'size' => 1 ) ) );
check( '0' === wp_remote_retrieve_body( $anon ) || 400 <= wp_remote_retrieve_response_code( $anon ), 'not available without login' );

echo "== abandoned uploads cleaned up\n";
$stale = ESB_Upload::start( 'stale.zip', 1000 );
$fresh = ESB_Upload::start( 'fresh.zip', 1000 );
file_put_contents( $stale->path( ESB_Upload::PART ), str_repeat( 'x', 100 ) );
// save() ставить updated = зараз, тож давність пишемо в job.json напряму.
$stale->data['updated'] = time() - 2 * DAY_IN_SECONDS;
file_put_contents( $stale->path( 'job.json' ), wp_json_encode( $stale->data ) );

$deleted = ESB_Upload::cleanup_stale();
check( 1 === $deleted && ! is_dir( $stale->dir() ), 'upload idle for 2 days removed with its .part' );
check( null !== ESB_Job::load( $fresh->id ), 'fresh upload kept' );
check( null !== ESB_Job::load( $uploaded->id ), 'finished uploaded copy kept' );
ESB_Storage::delete_dir( $fresh->id );

echo "== restore from uploaded copy\n";
$state  = ESB_Restore::start( $uploaded->id );
$status = esb_test_restore_steps( $state['token'] );
wp_cache_flush();
check( $status && 'done' === $status['status'], 'restore from uploaded copy done' );
check( null === esb_test_option( 'esb_test_marker' ), 'site returned to the uploaded backup state' );

// Відновлення бази прибрало сесію, створену після копії; nonce теж уже недійсний.
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
ESB_Restore::mark_seen();
esb_test_cleanup( $keep );

finish();
