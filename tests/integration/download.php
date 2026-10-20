<?php
/**
 * Скачування архіву через admin-post.php: цілком і частинами (HTTP Range), як це робить
 * сторінка плагіна на хостингах, що обривають довгий запит.
 *
 * Запуск: php tests/integration/download.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

$keep = esb_test_job_ids();

$user       = get_current_user_id();
$expiration = time() + HOUR_IN_SECONDS;
$token      = WP_Session_Tokens::get_instance( $user )->create( $expiration );
$cookie     = wp_generate_auth_cookie( $user, $expiration, 'logged_in', $token );

$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;

/**
 * GET з cookie адміністратора.
 *
 * @return array [код, заголовки (нижній регістр), тіло]
 */
function get( $url, array $headers = array(), $with_cookie = true ) {
    global $cookie;

    if ( $with_cookie ) {
        $headers[] = 'Cookie: ' . LOGGED_IN_COOKIE . '=' . rawurlencode( $cookie );
    }

    $received = array();
    $ch       = curl_init( $url );
    curl_setopt_array(
        $ch,
        array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$received ) {
                if ( false !== strpos( $line, ':' ) ) {
                    list( $key, $value )               = explode( ':', $line, 2 );
                    $received[ strtolower( trim( $key ) ) ] = trim( $value );
                }
                return strlen( $line );
            },
        )
    );
    $body = curl_exec( $ch );
    $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    curl_close( $ch );

    return array( $code, $received, (string) $body );
}

$job     = esb_test_backup( 'db' );
$archive = file_get_contents( $job->path( ESB_Storage::ARCHIVE ) );
$size    = strlen( $archive );
$url     = add_query_arg(
    array(
        'action'   => 'esb_download',
        'id'       => $job->id,
        'file'     => ESB_Storage::ARCHIVE,
        '_wpnonce' => wp_create_nonce( 'esb_download_' . $job->id ),
    ),
    admin_url( 'admin-post.php' )
);

echo "== whole file\n";
list( $code, $headers, $body ) = get( $url );
check( 200 === $code && $body === $archive, "200, whole archive ($size bytes)" );
check( isset( $headers['accept-ranges'] ) && 'bytes' === $headers['accept-ranges'], 'Accept-Ranges: bytes' );
check( ! empty( $headers['etag'] ), 'ETag sent' );
$etag = $headers['etag'];

echo "== in parts, like the plugin page\n";
$chunk  = (int) ceil( $size / 7 );
$joined = '';
for ( $offset = 0; $offset < $size; $offset += $chunk ) {
    $end                         = min( $offset + $chunk, $size ) - 1;
    list( $code, $headers, $body ) = get( $url, array( "Range: bytes=$offset-$end" ) );
    if ( 206 !== $code || "bytes $offset-$end/$size" !== $headers['content-range'] || strlen( $body ) !== $end - $offset + 1 ) {
        check( false, "part $offset-$end: HTTP $code, " . ( isset( $headers['content-range'] ) ? $headers['content-range'] : '-' ) );
        break;
    }
    $joined .= $body;
}
check( $joined === $archive, '7 parts (206) joined = archive' );

list( $code, $headers, $body ) = get( $url, array( 'Range: bytes=-100' ) );
check( 206 === $code && substr( $archive, -100 ) === $body, 'last 100 bytes (bytes=-100)' );

list( $code, $headers, $body ) = get( $url, array( 'Range: bytes=' . ( $size - 10 ) . '-' ) );
check( 206 === $code && substr( $archive, -10 ) === $body, 'open range (bytes=N-)' );

list( $code, $headers ) = get( $url, array( 'Range: bytes=' . ( $size + 5 ) . '-' ) );
check( 416 === $code && 'bytes */' . $size === $headers['content-range'], 'range beyond file → 416' );

list( $code, , $body ) = get( $url, array( 'Range: bytes=0-9', 'If-Range: "stale"' ) );
check( 200 === $code && $body === $archive, 'If-Range with old ETag → whole file' );

list( $code, , $body ) = get( $url, array( 'Range: bytes=0-9', 'If-Range: ' . $etag ) );
check( 206 === $code && substr( $archive, 0, 10 ) === $body, 'If-Range with current ETag → part' );

list( $code, , $body ) = get( $url, array( 'Range: bytes=0-1,5-9' ) );
check( 200 === $code && $body === $archive, 'several ranges → whole file' );

echo "== access\n";
list( $code, , $body ) = get( $url, array(), false );
check( $body !== $archive && false === strpos( $body, 'PK' ), 'not available without login' );

esb_test_cleanup( $keep );

finish();
