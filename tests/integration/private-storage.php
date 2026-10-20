<?php
/**
 * Сховище поза публічною текою сайту: вибір теки, перенесення наявних копій, вказівник,
 * бекап і відновлення звідти, повернення назад.
 *
 * Публічною текою (DOCUMENT_ROOT) тут вважається корінь WordPress — так на більшості хостингів.
 *
 * Запуск: php tests/integration/private-storage.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

global $wpdb;

$root = untrailingslashit( wp_normalize_path( realpath( ABSPATH ) ) );

// Початковий стан відновимо наприкінці: сайт міг уже перенести копії (сторінка плагіна).
$initial = ESB_Storage::private_dir();
if ( $initial ) {
    check( true === ESB_Storage::relocate_to_uploads(), 'start from uploads storage' );
}
$keep = esb_test_job_ids();

echo "== choosing the folder\n";
$target = ESB_Storage::private_candidate( ABSPATH );
echo "  candidate: $target\n";
check( $target && 0 !== strpos( $target . '/', $root . '/' ), 'candidate is outside the public folder' );
check( null === ESB_Storage::private_candidate( sys_get_temp_dir() ), 'WordPress not inside the given document root → no candidate' );
check( null === ESB_Storage::private_candidate( '' ), 'no document root → no candidate' );

echo "== moving existing backups\n";
$a = esb_test_backup( 'db' );
$b = esb_test_backup( 'db' );
check( 0 === strpos( $a->dir(), ESB_Storage::uploads_dir() ), 'backups start in uploads' );
$ids = esb_test_job_ids();

$moved = ESB_Storage::relocate( ABSPATH );
check( $moved === $target, 'relocated to ' . ( is_wp_error( $moved ) ? $moved->get_error_message() : var_export( $moved, true ) ) );
check( ESB_Storage::is_private() && ESB_Storage::dir() === $target, 'storage now outside the public folder' );
check( esb_test_job_ids() === $ids, 'same backups listed after move' );
check( is_file( $target . '/' . $a->id . '/' . ESB_Storage::ARCHIVE ) && ! is_dir( ESB_Storage::uploads_dir() . '/' . $a->id ), 'archives physically moved' );

$left = array_values( array_diff( scandir( ESB_Storage::uploads_dir() ), array( '.', '..' ) ) );
sort( $left );
check( array( '.htaccess', 'index.html', 'index.php', 'location.php' ) === $left, 'only service files and the pointer left in uploads: ' . implode( ', ', $left ) );
check( 0 === strpos( file_get_contents( ESB_Storage::uploads_dir() . '/location.php' ), "<?php exit; ?>\n" ), 'pointer is a PHP file that exits at once' );

$uploads  = wp_upload_dir( null, false );
$response = wp_remote_get( $uploads['baseurl'] . '/' . ESB_Storage::DIR_NAME . '/location.php' );
check( false === strpos( (string) wp_remote_retrieve_body( $response ), $target ), 'pointer does not reveal the path over HTTP' );
check( false === ESB_Storage::is_exposed( true ), 'storage cannot be exposed: no URL' );
check( null === ESB_Storage::relocate( ABSPATH ), 'second relocation does nothing' );

echo "== page\n";
ob_start();
ESB_Admin::render_page();
$html = ob_get_clean();
check( false !== strpos( $html, 'поза публічною частиною сайту' ) && false === strpos( $html, 'esb-exposed' ), 'page says storage is private, no exposure warning' );

echo "== backup and restore from private storage\n";
$c = esb_test_backup( 'db' );
check( 0 === strpos( $c->path( ESB_Storage::ARCHIVE ), $target . '/' ), 'new backup written to private storage' );

update_option( 'esb_test_marker', 'after-backup' );
$state = ESB_Restore::start( $c->id );
do {
    $state = ESB_Restore::step( $state['token'] );
} while ( 'running' === $state['status'] );
wp_cache_flush();
check( 'done' === $state['status'] && null === esb_test_option( 'esb_test_marker' ), 'restore from private storage done ' . $state['error'] );
check( ESB_Storage::is_private() && ESB_Storage::dir() === $target, 'pointer survives database restore' );
check( 0 === strpos( (string) ESB_Restore::state_path(), $target . '/' ), 'restore state kept in private storage' );

echo "== files backup excludes the uploads pointer folder\n";
$f        = esb_test_backup( 'files' );
$reader   = new ESB_Zip_Reader( $f->path( ESB_Storage::ARCHIVE ) );
$position = $reader->cd_offset;
$leaked   = array();
$prefix   = 'files/' . ltrim( substr( ESB_Storage::uploads_dir(), strlen( $root ) ), '/' ) . '/';
while ( $entry = $reader->next_entry( $position ) ) {
    if ( 0 === strpos( $entry['name'], $prefix ) ) {
        $leaked[] = $entry['name'];
    }
}
$reader->close();
check( array() === $leaked, 'uploads/esb-backups not in the archive' );

echo "== back to uploads\n";
check( true === ESB_Storage::relocate_to_uploads(), 'moved back' );
check( ! ESB_Storage::is_private() && ! is_dir( $target ) && is_file( ESB_Storage::uploads_dir() . '/' . $a->id . '/' . ESB_Storage::ARCHIVE ), 'backups back in uploads, private folder removed' );

echo "== opening the plugin page moves backups (real web request)\n";
// Як адміністратор у браузері: DOCUMENT_ROOT дає сам вебсервер.
delete_transient( ESB_Admin::RELOCATE_SKIP );
$user       = get_current_user_id();
$expiration = time() + HOUR_IN_SECONDS;
$token      = WP_Session_Tokens::get_instance( $user )->create( $expiration );
$cookie     = wp_generate_auth_cookie( $user, $expiration, 'logged_in', $token );
$auth       = wp_generate_auth_cookie( $user, $expiration, is_ssl() ? 'secure_auth' : 'auth', $token );
$response   = wp_remote_get(
    admin_url( 'tools.php?page=' . ESB_Admin::PAGE ),
    array(
        'timeout' => 60,
        'cookies' => array(
            LOGGED_IN_COOKIE                          => $cookie,
            ( is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE ) => $auth,
        ),
    )
);
$html = wp_remote_retrieve_body( $response );
clearstatcache();
check( 200 === wp_remote_retrieve_response_code( $response ) && false !== strpos( $html, 'Резервні копії перенесено поза публічну теку' ), 'page load relocated storage and said so' );
check( ESB_Storage::is_private() && is_file( ESB_Storage::private_dir() . '/' . $a->id . '/' . ESB_Storage::ARCHIVE ), 'backups now in ' . ESB_Storage::private_dir() );
check( true === ESB_Storage::relocate_to_uploads(), 'moved back again' );

delete_option( 'esb_test_marker' );
ESB_Restore::mark_seen();
esb_test_cleanup( $keep );

if ( $initial ) {
    check( $initial === ESB_Storage::relocate_to( $initial ), 'initial private storage restored' );
}

finish();
