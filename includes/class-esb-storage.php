<?php
/**
 * Захищене сховище резервних копій.
 *
 * Кожна копія лежить в окремій теці з випадковою назвою:
 * esb-backups/<id>/{backup.zip, job.json, log.txt}
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Storage {

    const DIR_NAME = 'esb-backups';

    /**
     * Ідентифікатор копії: дата (UTC) і випадковий суфікс, щоб теку неможливо було вгадати.
     */
    const ID_PATTERN = '/^\d{8}-\d{6}-[a-z0-9]{16}$/';

    const ARCHIVE = 'backup.zip';

    /**
     * Запас вільного місця понад оцінку: лог, стан, тимчасові файли.
     */
    const SPACE_MARGIN = 50 * MB_IN_BYTES;

    const EXPOSED_CACHE = 'esb_storage_exposed';

    /**
     * Файли, які дозволено завантажувати.
     */
    const DOWNLOAD_PATTERN = '/^(backup\.zip|log\.txt)$/';

    public static function dir() {
        $uploads = wp_upload_dir( null, false );

        return trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . self::DIR_NAME;
    }

    /**
     * Створює теку сховища і файли, що забороняють прямий доступ до неї.
     *
     * @return string|WP_Error Шлях до теки або помилка.
     */
    public static function ensure_dir() {
        $dir = self::dir();

        if ( ! wp_mkdir_p( $dir ) ) {
            /* translators: %s: шлях до теки */
            return new WP_Error( 'esb_mkdir', sprintf( __( 'Не вдалося створити теку %s.', 'easy-site-backup' ), $dir ) );
        }

        $protection = array(
            '.htaccess'  => "# Easy Site Backup: заборона прямого доступу\n"
                . "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
            'index.php'  => "<?php\n// Silence is golden.\n",
            'index.html' => '',
        );

        foreach ( $protection as $name => $content ) {
            $path = $dir . '/' . $name;

            if ( ! file_exists( $path ) && false === file_put_contents( $path, $content ) ) {
                /* translators: %s: шлях до файлу */
                return new WP_Error( 'esb_protect', sprintf( __( 'Не вдалося створити файл %s.', 'easy-site-backup' ), $path ) );
            }
        }

        return $dir;
    }

    public static function new_id() {
        return gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 16, false ) );
    }

    public static function is_valid_id( $id ) {
        return is_string( $id ) && 1 === preg_match( self::ID_PATTERN, $id );
    }

    /**
     * Повертає теку копії лише для коректного ідентифікатора — захист від path traversal.
     *
     * @return string|false
     */
    public static function job_dir( $id ) {
        return self::is_valid_id( $id ) ? self::dir() . '/' . $id : false;
    }

    /**
     * @return string|false Шлях до файлу, який дозволено завантажити.
     */
    public static function download_path( $id, $name ) {
        $dir = self::job_dir( $id );

        if ( ! $dir || ! is_string( $name ) || ! preg_match( self::DOWNLOAD_PATTERN, $name ) ) {
            return false;
        }

        return $dir . '/' . $name;
    }

    /**
     * @return ESB_Job[] Усі копії, новіші першими.
     */
    public static function list_jobs() {
        $dir = self::dir();

        if ( ! is_dir( $dir ) ) {
            return array();
        }

        $jobs = array();
        foreach ( (array) scandir( $dir ) as $name ) {
            $job = self::is_valid_id( (string) $name ) ? ESB_Job::load( $name ) : null;
            if ( $job ) {
                $jobs[] = $job;
            }
        }

        usort(
            $jobs,
            function ( $a, $b ) {
                return $b->data['created'] - $a->data['created'];
            }
        );

        return $jobs;
    }

    /**
     * Чи віддає вебсервер файли з теки сховища напряму (Nginx, Apache з AllowOverride None).
     *
     * Кладе в теку тестовий файл із випадковою назвою, запитує його через HTTP і одразу видаляє.
     * Результат кешується на добу, а невдала перевірка — на годину.
     *
     * @return bool|null true — теку видно з інтернету; null — перевірити не вдалося.
     */
    public static function is_exposed( $force = false ) {
        $cached = get_transient( self::EXPOSED_CACHE );
        if ( ! $force && false !== $cached ) {
            return 'unknown' === $cached ? null : 'yes' === $cached;
        }

        $result = null;
        $dir    = self::ensure_dir();

        if ( ! is_wp_error( $dir ) ) {
            $name    = 'probe-' . strtolower( wp_generate_password( 16, false ) ) . '.txt';
            $content = 'esb-probe-' . wp_generate_password( 16, false );

            if ( false !== file_put_contents( $dir . '/' . $name, $content ) ) {
                $uploads  = wp_upload_dir( null, false );
                $response = wp_remote_get( trailingslashit( $uploads['baseurl'] ) . self::DIR_NAME . '/' . $name, array( 'timeout' => 5, 'redirection' => 0 ) );
                @unlink( $dir . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

                if ( ! is_wp_error( $response ) ) {
                    $result = 200 === wp_remote_retrieve_response_code( $response ) && false !== strpos( wp_remote_retrieve_body( $response ), $content );
                }
            }
        }

        set_transient( self::EXPOSED_CACHE, null === $result ? 'unknown' : ( $result ? 'yes' : 'no' ), null === $result ? HOUR_IN_SECONDS : DAY_IN_SECONDS );

        return $result;
    }

    /**
     * @return float|null Вільне місце на диску сховища; null — хостинг цього не повідомляє.
     */
    public static function free_space() {
        // До першої копії теки сховища ще немає — тоді рахуємо за текою uploads.
        $dir  = is_dir( self::dir() ) ? self::dir() : dirname( self::dir() );
        $free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        // Фільтр — для хостингів із квотами, де disk_free_space() показує місце всього сервера.
        $free = apply_filters( 'esb_free_space', false === $free ? null : $free );

        return null === $free ? null : (float) $free;
    }

    /**
     * Перевіряє, що на диску є щонайменше $bytes вільного місця.
     *
     * @param string $what Для чого потрібне місце — для тексту помилки.
     */
    public static function require_space( $bytes, $what ) {
        $free = self::free_space();

        if ( null !== $free && $free < $bytes ) {
            throw new RuntimeException(
                sprintf(
                    /* translators: 1: для чого місце, 2: потрібний обсяг, 3: вільний обсяг */
                    __( 'Недостатньо місця на диску: для %1$s потрібно щонайменше %2$s, вільно %3$s.', 'easy-site-backup' ),
                    $what,
                    html_entity_decode( size_format( $bytes, 1 ), ENT_QUOTES, 'UTF-8' ),
                    html_entity_decode( size_format( $free, 1 ), ENT_QUOTES, 'UTF-8' )
                )
            );
        }
    }

    public static function delete_dir( $id ) {
        $dir = self::job_dir( $id );

        if ( ! $dir || ! is_dir( $dir ) ) {
            return false;
        }

        $names = scandir( $dir );
        if ( ! is_array( $names ) ) {
            return false;
        }

        // Тека копії пласка, вкладених тек у ній немає.
        foreach ( $names as $name ) {
            if ( '.' !== $name && '..' !== $name ) {
                @unlink( $dir . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }
}
