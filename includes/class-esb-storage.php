<?php
/**
 * Захищене сховище резервних копій.
 *
 * Кожна копія лежить в окремій теці з випадковою назвою:
 * <сховище>/<id>/{backup.zip, job.json, log.txt}
 *
 * Сховище — поза публічною текою сайту (поруч із нею: esb-backups-<код сайту>), якщо хостинг
 * дозволяє туди писати: тоді жоден вебсервер не віддасть архів за посиланням, хоч би як його
 * було налаштовано. Інакше — wp-content/uploads/esb-backups із захистом .htaccess.
 *
 * Шлях до сховища поза публічною текою записано у файл-вказівник у теці uploads, а не в базу:
 * відновлення замінює базу цілком, і плагін не має «загубити» власні копії посеред нього.
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

    /**
     * Вказівник на сховище поза публічною текою. PHP-файл, що одразу завершується: навіть якщо
     * вебсервер віддає теку uploads напряму, шлях до сховища з нього не прочитати.
     */
    const LOCATION_FILE = 'location.php';

    const LOCATION_HEADER = "<?php exit; ?>\n";

    /**
     * Службові файли теки сховища: їх не переносять разом з копіями.
     */
    const SERVICE_FILES = array( '.htaccess', 'index.php', 'index.html', self::LOCATION_FILE, 'relocate.lock' );

    private static $dir;

    public static function dir() {
        if ( null === self::$dir ) {
            $private   = self::private_dir();
            self::$dir = $private ? $private : self::uploads_dir();
        }

        return self::$dir;
    }

    /**
     * Тека в uploads: тут копії лежать, якщо поза публічною текою писати не можна,
     * і тут же — вказівник на сховище поза нею.
     */
    public static function uploads_dir() {
        $uploads = wp_upload_dir( null, false );

        return trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . self::DIR_NAME;
    }

    /**
     * @return string|null Сховище поза публічною текою, якщо копії вже перенесено туди.
     */
    public static function private_dir() {
        $file = self::uploads_dir() . '/' . self::LOCATION_FILE;

        if ( ! is_file( $file ) ) {
            return null;
        }

        $path = trim( substr( (string) file_get_contents( $file ), strlen( self::LOCATION_HEADER ) ) );

        return '' !== $path && is_dir( $path ) ? $path : null;
    }

    public static function is_private() {
        return null !== self::private_dir();
    }

    /**
     * Створює теку сховища і файли, що забороняють прямий доступ до неї.
     *
     * @return string|WP_Error Шлях до теки або помилка.
     */
    public static function ensure_dir() {
        return self::protect( self::dir() );
    }

    /**
     * Куди перенести сховище: поруч із публічною текою сайту, а не в ній.
     *
     * Публічна тека — DOCUMENT_ROOT вебсервера (а не тека WordPress: його можуть поставити в
     * підтеку). Якщо тека над нею сама схожа на публічну (сайт-піддомен усередині public_html
     * основного сайту), піднімаємося вище — до трьох рівнів.
     *
     * @return string|null null — розташування незрозуміле або вище писати не можна.
     */
    public static function private_candidate( $document_root ) {
        $root    = $document_root ? realpath( $document_root ) : false;
        $abspath = realpath( ABSPATH );

        if ( ! $root || ! $abspath ) {
            return null;
        }

        $root    = untrailingslashit( wp_normalize_path( $root ) );
        $abspath = untrailingslashit( wp_normalize_path( $abspath ) );

        // WordPress має лежати в публічній теці, інакше не знаємо, що в цьому акаунті публічне.
        if ( $abspath !== $root && 0 !== strpos( $abspath . '/', $root . '/' ) ) {
            return null;
        }

        $parent = $root;
        for ( $level = 0; $level < 3; $level++ ) {
            $up = dirname( $parent );
            // Корінь диска чи файлової системи — не місце для копій.
            if ( $up === $parent || '/' === $up || '.' === $up || preg_match( '#^[a-z]:/?$#i', $up ) ) {
                return null;
            }
            $parent = $up;

            if ( ! self::open_basedir_allows( $parent ) ) {
                return null;
            }
            if ( ! self::looks_public( $parent ) ) {
                return $parent . '/' . self::DIR_NAME . '-' . substr( md5( home_url() ), 0, 8 );
            }
        }

        return null;
    }

    /**
     * Переносить сховище поза публічну теку сайту разом з наявними копіями.
     *
     * Викликається зі сторінки плагіна (потрібен DOCUMENT_ROOT веб-запиту), коли не йде бекап
     * чи відновлення. Якщо перенести не вдалося, усе лишається на старому місці.
     *
     * @return string|null|WP_Error Нова тека; null — переносити нікуди або вже перенесено.
     */
    public static function relocate( $document_root ) {
        if ( self::is_private() ) {
            return null;
        }

        $target = self::private_candidate( $document_root );

        return $target ? self::relocate_to( $target ) : null;
    }

    /**
     * @return string|null|WP_Error Нова тека; null — туди писати не можна.
     */
    public static function relocate_to( $target ) {
        if ( self::is_private() || ! wp_mkdir_p( $target ) || ! wp_is_writable( $target ) ) {
            return null;
        }

        $source = self::protect( self::uploads_dir() );
        if ( is_wp_error( $source ) ) {
            return $source;
        }

        $moved = self::move_entries( $source, $target );
        if ( is_wp_error( $moved ) ) {
            return $moved;
        }

        if ( false === file_put_contents( $source . '/' . self::LOCATION_FILE, self::LOCATION_HEADER . $target . "\n" ) ) {
            self::move_entries( $target, $source );
            /* translators: %s: шлях до файлу */
            return new WP_Error( 'esb_protect', sprintf( __( 'Не вдалося створити файл %s.', 'easy-site-backup' ), $source . '/' . self::LOCATION_FILE ) );
        }

        self::$dir = null;
        delete_transient( self::EXPOSED_CACHE );

        $protected = self::protect( $target );

        return is_wp_error( $protected ) ? $protected : $target;
    }

    /**
     * Повертає сховище в теку uploads (наприклад, якщо теку поза публічною частиною треба звільнити).
     *
     * @return true|WP_Error
     */
    public static function relocate_to_uploads() {
        $private = self::private_dir();
        if ( ! $private ) {
            return true;
        }

        $moved = self::move_entries( $private, self::uploads_dir() );
        if ( is_wp_error( $moved ) ) {
            return $moved;
        }

        @unlink( self::uploads_dir() . '/' . self::LOCATION_FILE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        foreach ( array( '.htaccess', 'index.php', 'index.html' ) as $name ) {
            @unlink( $private . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        @rmdir( $private ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        self::$dir = null;
        delete_transient( self::EXPOSED_CACHE );

        return true;
    }

    /**
     * Переносить копії й стан відновлення rename()-ом. Якщо щось не перенеслося (наприклад, інший
     * диск), повертає вже перенесене назад — копії ніколи не лишаються розкиданими по двох теках.
     *
     * @return true|WP_Error
     */
    private static function move_entries( $from, $to ) {
        $names = is_dir( $from ) ? scandir( $from ) : array();
        $moved = array();

        foreach ( (array) $names as $name ) {
            if ( '.' === $name || '..' === $name || in_array( $name, self::SERVICE_FILES, true ) ) {
                continue;
            }

            if ( ! @rename( $from . '/' . $name, $to . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                foreach ( array_reverse( $moved ) as $back ) {
                    @rename( $to . '/' . $back, $from . '/' . $back ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }

                /* translators: 1: що переносимо, 2: куди */
                return new WP_Error( 'esb_relocate', sprintf( __( 'Не вдалося перенести %1$s до %2$s.', 'easy-site-backup' ), $name, $to ) );
            }

            $moved[] = $name;
        }

        return true;
    }

    /**
     * Тека, яку, найімовірніше, віддає вебсервер: у ній лежить сайт.
     */
    private static function looks_public( $dir ) {
        foreach ( array( 'index.php', 'index.html', 'index.htm', '.htaccess', 'wp-config.php' ) as $name ) {
            if ( @file_exists( $dir . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                return true;
            }
        }

        return false;
    }

    /**
     * Чи не забороняє open_basedir доступ до теки (інакше PHP видавав би попередження).
     */
    private static function open_basedir_allows( $dir ) {
        $allowed = (string) ini_get( 'open_basedir' );
        if ( '' === $allowed ) {
            return true;
        }

        $dir = trailingslashit( wp_normalize_path( $dir ) );
        foreach ( explode( PATH_SEPARATOR, $allowed ) as $base ) {
            $base = trailingslashit( wp_normalize_path( $base ) );
            if ( '' !== trim( $base, '/' ) && 0 === strpos( $dir, $base ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Створює теку й файли, що забороняють прямий доступ до неї.
     *
     * @return string|WP_Error
     */
    private static function protect( $dir ) {
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
     * Атомарно записує файл: спершу поруч у .tmp, потім rename() поверх.
     *
     * На Windows rename() поверх файлу, який саме відкрив антивірус чи індексатор, коротко
     * відмовляє, тож пробуємо кілька разів.
     *
     * @return bool
     */
    public static function write_atomic( $path, $contents ) {
        $tmp = $path . '.tmp';

        if ( false === file_put_contents( $tmp, $contents ) ) {
            return false;
        }

        for ( $attempt = 0; $attempt < 10; $attempt++ ) {
            if ( @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                return true;
            }
            usleep( 50000 );
        }

        @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        return false;
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
        // Поза публічною текою в копій немає адреси — перевіряти нічого.
        if ( self::is_private() ) {
            return false;
        }

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
