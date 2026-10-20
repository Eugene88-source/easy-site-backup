<?php
/**
 * Оновлення плагіна з GitHub Releases.
 *
 * Плагіна немає на wordpress.org, тому заголовок «Update URI» вказує на GitHub, а фільтр
 * update_plugins_github.com (WordPress 5.8+) повідомляє WordPress про нову версію.
 * Плагін один раз вмикає собі автооновлення, а щогодинна перевірка встановлює нову версію.
 * Вимкнене адміністратором на сторінці «Плагіни» автооновлення плагін поважає.
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
     * Автооновлення вмикається один раз; якщо адміністратор потім його вимкне, ми не втручаємося.
     */
    const AUTO_UPDATE_ON = 'esb_auto_update_on';

    /**
     * Позначка версій 0.3–0.4.3. Версія 0.4.4 видаляла її разом з автооновленням,
     * тому для повторного ввімкнення потрібна нова (AUTO_UPDATE_ON); стару лише прибираємо.
     */
    const OLD_AUTO_UPDATE_FLAG = 'esb_auto_update_default';

    /**
     * Власна щогодинна перевірка: WordPress сам перевіряє плагіни лише раз на 12 годин.
     */
    const CHECK_HOOK = 'esb_update_check';

    /**
     * Трохи менше за годину, щоб щогодинна перевірка завжди бачила свіжу відповідь GitHub.
     */
    const CACHE_TTL = 50 * MINUTE_IN_SECONDS;

    public static function init() {
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
        add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
        add_filter( 'auto_update_plugin', array( __CLASS__, 'postpone_during_backup' ), 20, 2 );

        // «Перевірити знову» на сторінці оновлень видаляє цей transient — тоді й нашу відповідь GitHub перечитуємо.
        add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'clear_cache' ) );
        // На init, а не admin_init: щоб автооновлення ввімкнулося, навіть якщо в адмінку довго не заходять.
        add_action( 'init', array( __CLASS__, 'enable_auto_update_once' ) );

        add_action( self::CHECK_HOOK, array( __CLASS__, 'hourly_check' ) );
        // Не лише при активації: сайти отримують цю версію оновленням, а воно активацію не запускає.
        add_action( 'init', array( __CLASS__, 'schedule_check' ) );
    }

    public static function schedule_check() {
        if ( ! wp_next_scheduled( self::CHECK_HOOK ) ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CHECK_HOOK );
        }
    }

    /**
     * Якщо на GitHub є новіша версія — одразу встановлює її стандартним автооновленням WordPress.
     * Вимкнене на сторінці «Плагіни» автооновлення і відкладання під час бекапу при цьому діють.
     */
    public static function hourly_check() {
        // Наново, а не з кешу: інакше відповідь, збережена перед самим релізом, ховала б його
        // ще до 50 хвилин, і нова версія з'являлася б аж через дві години. Свіжа відповідь
        // заодно оновлює кеш, з якого далі бере дані перевірка самого WordPress.
        $release = self::latest_release( true );
        if ( ! $release || ! version_compare( $release['version'], ESB_VERSION, '>' ) ) {
            return;
        }

        // Змушуємо WordPress побачити нову версію зараз, не чекаючи свого 12-годинного інтервалу.
        // Transient не видаляємо: це скинуло б кеш відповіді GitHub (див. clear_cache).
        $updates = get_site_transient( 'update_plugins' );
        $known   = is_object( $updates ) && isset( $updates->response[ self::basename() ] )
            && version_compare( $updates->response[ self::basename() ]->new_version, $release['version'], '>=' );

        if ( ! $known ) {
            if ( is_object( $updates ) ) {
                $updates->last_checked = 0;
                set_site_transient( 'update_plugins', $updates );
            }
            wp_update_plugins();
            $updates = get_site_transient( 'update_plugins' );
        }

        if ( empty( $updates->response[ self::basename() ] ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/admin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $updater = new WP_Automatic_Updater();
        if ( $updater->is_disabled() || ! WP_Upgrader::create_lock( 'auto_updater' ) ) {
            return;
        }

        try {
            // update() сам перевіряє, чи ввімкнене автооновлення для плагіна (should_update).
            $updater->update( 'plugin', $updates->response[ self::basename() ] );
        } finally {
            WP_Upgrader::release_lock( 'auto_updater' );
        }
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
     * Не оновлюємося автоматично посеред бекапу чи відновлення — WordPress спробує знову пізніше.
     */
    public static function postpone_during_backup( $update, $item ) {
        if ( isset( $item->plugin ) && self::basename() === $item->plugin && ( ESB_Backup::active_job() || ESB_Restore::active() ) ) {
            return false;
        }

        return $update;
    }

    public static function enable_auto_update_once() {
        if ( get_site_option( self::AUTO_UPDATE_ON ) ) {
            return;
        }

        $plugins = (array) get_site_option( 'auto_update_plugins', array() );
        if ( ! in_array( self::basename(), $plugins, true ) ) {
            $plugins[] = self::basename();
            update_site_option( 'auto_update_plugins', $plugins );
        }

        update_site_option( self::AUTO_UPDATE_ON, 1 );
        delete_site_option( self::OLD_AUTO_UPDATE_FLAG );
    }

    public static function clear_cache() {
        delete_site_transient( self::CACHE );
    }

    /**
     * @return array|null Останній реліз: version, package, url, notes, date.
     */
    /**
     * @param bool $fresh Запитати GitHub наново, оминаючи кеш.
     */
    private static function latest_release( $fresh = false ) {
        $cached = $fresh ? false : get_site_transient( self::CACHE );
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

        // Невдалу перевірку теж кешуємо, щоб не впертися в ліміт GitHub API — 60 запитів на годину з IP.
        set_site_transient( self::CACHE, $release ? $release : '', self::CACHE_TTL );

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
