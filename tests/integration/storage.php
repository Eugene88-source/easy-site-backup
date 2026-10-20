<?php
/**
 * Самоперевірка доступу до теки копій з інтернету і перенесення стану відновлення
 * зі старої постійної назви.
 *
 * Справжній запит іде до вебсервера сайту: на Nginx і вбудованому сервері PHP тека відкрита,
 * на Apache з .htaccess — закрита. Відповіді Apache імітуються фільтром pre_http_request.
 *
 * Запуск: php tests/integration/storage.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

function render() {
    ob_start();
    ESB_Admin::render_page();
    return ob_get_clean();
}

function probes_left() {
    return glob( ESB_Storage::dir() . '/probe-*.txt' );
}

echo "== real web server\n";
$real = ESB_Storage::is_exposed( true );
echo '  storage exposed: ' . var_export( $real, true ) . "\n";
check( null !== $real, 'check returned a result' );
check( array() === probes_left(), 'probe file removed' );
check( ( true === $real ? 'yes' : 'no' ) === get_transient( ESB_Storage::EXPOSED_CACHE ), 'result cached' );

echo "== exposed (Nginx)\n";
set_transient( ESB_Storage::EXPOSED_CACHE, 'yes', HOUR_IN_SECONDS );
$html    = render();
$uploads = wp_upload_dir( null, false );
$path    = trailingslashit( wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) ) . ESB_Storage::DIR_NAME . '/';
check( false !== strpos( $html, 'esb-exposed' ), 'warning shown' );
check( false !== strpos( $html, 'location ^~ &quot;' . $path . '&quot; {' ), "nginx snippet with quoted $path" );
check( false !== strpos( $html, 'action=esb_recheck_exposure' ), 'recheck button' );
check( false === strpos( $html, 'іншої адреси' ), 'no CDN note when uploads are on the site host' );

// Uploads на іншому хості й з пробілом у шляху (як у плагінів винесення медіа на CDN).
$cdn = function ( $dirs ) {
    $dirs['baseurl'] = 'https://cdn.example.com/my%20site/uploads';
    return $dirs;
};
add_filter( 'upload_dir', $cdn );
$html = render();
remove_filter( 'upload_dir', $cdn );
check( false !== strpos( $html, 'location ^~ &quot;/my site/uploads/' . ESB_Storage::DIR_NAME . '/&quot; {' ), 'path decoded and quoted (space survives in nginx config)' );
check( false !== strpos( $html, 'іншої адреси (cdn.example.com)' ), 'CDN host note shown' );

echo "== closed (Apache .htaccess)\n";
$forbidden = function () {
    return array( 'headers' => array(), 'body' => 'Forbidden', 'response' => array( 'code' => 403, 'message' => 'Forbidden' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $forbidden );
check( false === ESB_Storage::is_exposed( true ), '403 → not exposed' );
$html = render();
check( false === strpos( $html, 'esb-exposed' ) && false !== strpos( $html, 'закрито — перевірено' ), 'no warning, "closed — checked" note' );
remove_filter( 'pre_http_request', $forbidden );

echo "== check failed\n";
$broken = function () {
    return new WP_Error( 'http_request_failed', 'test' );
};
add_filter( 'pre_http_request', $broken );
check( null === ESB_Storage::is_exposed( true ), 'HTTP error → unknown' );
check( false === strpos( render(), 'esb-exposed' ), 'unknown → no warning' );
remove_filter( 'pre_http_request', $broken );
check( array() === probes_left(), 'no probe files left' );

echo "== legacy restore state\n";
// Стан від версії до 0.4.6 під постійною назвою переноситься під випадкову.
foreach ( (array) glob( ESB_Storage::dir() . '/' . ESB_Restore::STATE_PATTERN ) as $file ) {
    @unlink( $file );
}
file_put_contents( ESB_Storage::dir() . '/restore.json', wp_json_encode( array( 'status' => 'done', 'token' => 'x', 'seen' => true ) ) );
$state = ESB_Restore::load();
check( is_array( $state ) && 'done' === $state['status'], 'legacy state loaded' );
check( ! file_exists( ESB_Storage::dir() . '/restore.json' ), 'legacy restore.json renamed' );
check( (bool) preg_match( '/^restore-[a-z0-9]{32}\.json$/', basename( (string) ESB_Restore::state_path() ) ), 'now under random name' );

ESB_Storage::is_exposed( true ); // повернути справжній результат у кеш

finish();
