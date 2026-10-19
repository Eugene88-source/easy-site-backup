<?php
/**
 * Оновлення плагіна з GitHub Releases.
 *
 * Плагіна немає на wordpress.org, тому заголовок «Update URI» вказує на GitHub, а фільтр
 * update_plugins_github.com (WordPress 5.8+) повідомляє WordPress про нову версію.
 * Далі оновлення встановлює сам WordPress — вручну або автоматично.
 *
 * Реліз: тег vX.Y.Z, що збігається з «Version» у заголовку плагіна. Пакет — вкладення
 * easy-site-backup.zip (його збирає .github/workflows/release.yml), а за його відсутності — zipball.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Updater {

    const REPO = 'Eugene88-source/easy-site-backup';

    const ASSET = 'easy-site-backup.zip';

    const CACHE = 'esb_latest_release';

    /**
     * Автооновлення вмикається один раз; якщо користувач потім його вимкне, ми не втручаємося.
     */
    const AUTO_UPDATE_FLAG = 'esb_auto_update_default';

    public static function init() {
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
        add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
        add_filter( 'auto_update_plugin', array( __CLASS__, 'postpone_during_backup' ), 20, 2 );

        // «Перевірити знову» на сторінці оновлень видаляє цей transient — тоді й нашу відповідь GitHub перечитуємо.
        add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'clear_cache' ) );
        add_action( 'admin_init', array( __CLASS__, 'enable_auto_update_once' ) );
    }

    public static function basename() {
        return plugin_basename( ESB_FILE );
    }

    /**
     * @return array|false Дані оновлення для WordPress.
     */
    public static function check( $update, $plugin_data, $plugin_file ) {
        if ( self::basename() !== $plugin_file ) {
            return $update;
        }

        $release = self::latest_release();
        if ( ! $release ) {
            return $update;
        }

        return array(
            'slug'         => dirname( $plugin_file ),
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => '5.8',
            'requires_php' => '7.4',
        );
    }

    /**
     * Дані для вікна «Переглянути деталі» на сторінці плагінів.
     */
    public static function details( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( self::basename() ) !== $args->slug ) {
            return $result;
        }

        $release = self::latest_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) array(
            'name'          => 'Easy Site Backup',
            'slug'          => $args->slug,
            'version'       => $release['version'],
            'author'        => 'Eugene',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'download_link' => $release['package'],
            'last_updated'  => $release['date'],
            'sections'      => array(
                'description' => esc_html__( 'Резервне копіювання бази даних і файлів сайту.', 'easy-site-backup' ),
                'changelog'   => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '',
            ),
        );
    }

    /**
     * Архів GitHub (zipball) розпаковується в теку «власник-репозиторій-хеш»;
     * перейменовуємо її, щоб плагін лишився в теці easy-site-backup.
     */
    public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
        global $wp_filesystem;

        if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
            return $source;
        }

        $expected = trailingslashit( $remote_source ) . dirname( self::basename() );

        if ( untrailingslashit( $source ) === $expected ) {
            return $source;
        }

        if ( ! $wp_filesystem->move( $source, $expected, true ) ) {
            return new WP_Error( 'esb_update_folder', __( 'Не вдалося підготувати теку оновлення плагіна.', 'easy-site-backup' ) );
        }

        return trailingslashit( $expected );
    }

    /**
     * Не оновлюємося автоматично посеред бекапу — WordPress спробує знову пізніше.
     */
    public static function postpone_during_backup( $update, $item ) {
        if ( isset( $item->plugin ) && self::basename() === $item->plugin && ESB_Backup::active_job() ) {
            return false;
        }

        return $update;
    }

    public static function enable_auto_update_once() {
        if ( get_site_option( self::AUTO_UPDATE_FLAG ) ) {
            return;
        }

        $plugins = (array) get_site_option( 'auto_update_plugins', array() );
        if ( ! in_array( self::basename(), $plugins, true ) ) {
            $plugins[] = self::basename();
            update_site_option( 'auto_update_plugins', $plugins );
        }

        update_site_option( self::AUTO_UPDATE_FLAG, 1 );
    }

    public static function clear_cache() {
        delete_site_transient( self::CACHE );
    }

    /**
     * @return array|null Останній реліз: version, package, url, notes, date.
     */
    private static function latest_release() {
        $cached = get_site_transient( self::CACHE );
        if ( false !== $cached ) {
            return $cached ? $cached : null;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'Easy-Site-Backup/' . ESB_VERSION,
                ),
            )
        );

        $release = null;
        if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
            $release = self::parse_release( json_decode( wp_remote_retrieve_body( $response ), true ) );
        }

        // Невдалу перевірку теж кешуємо (на годину), щоб не впертися в ліміт GitHub API — 60 запитів на годину з IP.
        set_site_transient( self::CACHE, $release ? $release : '', $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

        return $release;
    }

    private static function parse_release( $data ) {
        if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
            return null;
        }

        $version = ltrim( $data['tag_name'], 'vV' );
        if ( ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
            return null;
        }

        $package = '';
        foreach ( isset( $data['assets'] ) ? (array) $data['assets'] : array() as $asset ) {
            if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET === $asset['name'] ) {
                $package = $asset['browser_download_url'];
            }
        }
        if ( ! $package && isset( $data['zipball_url'] ) ) {
            $package = $data['zipball_url'];
        }

        // Встановлюємо лише те, що лежить у нашому репозиторії.
        if ( ! self::is_trusted_url( $package ) ) {
            return null;
        }

        return array(
            'version' => $version,
            'package' => $package,
            'url'     => isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . self::REPO,
            'notes'   => isset( $data['body'] ) ? (string) $data['body'] : '',
            'date'    => isset( $data['published_at'] ) ? $data['published_at'] : '',
        );
    }

    private static function is_trusted_url( $url ) {
        return is_string( $url )
            && ( 0 === strpos( $url, 'https://github.com/' . self::REPO . '/' )
                || 0 === strpos( $url, 'https://api.github.com/repos/' . self::REPO . '/' ) );
    }
}
