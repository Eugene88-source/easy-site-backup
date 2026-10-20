<?php
/**
 * Покрокове відновлення сайту з резервної копії.
 *
 * Фази: prepare → db_extract → db_import → files → finalize.
 *
 * База даних спершу імпортується в тимчасові таблиці (TEMP_PREFIX), а наприкінці один
 * RENAME TABLE підміняє ними робочі. Якщо відновлення зламається раніше, база сайту
 * лишиться без змін. Файли відновлюються до підміни бази, щоб вона була останньою дією.
 *
 * Після підміни бази в ній може не бути сесії поточного користувача, а з нею — і nonce.
 * Тому стан відновлення зберігається у файлі, а кроки перевіряються випадковим токеном.
 *
 * Файли відновлення не мають постійних назв: на Nginx .htaccess не діє, і файл із відомою
 * адресою можна було б завантажити напряму. Стан — у корені сховища під випадковою назвою
 * (restore-<випадкове>.json), розпакований дамп — у теці самої копії, назва якої теж випадкова.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Restore {

    const CRON_HOOK = 'esb_restore_step';

    const STATE_PATTERN = 'restore-*.json';

    /**
     * Розпакований дамп — у теці копії, з якої йде відновлення.
     */
    const SQL = 'restore.sql';

    /**
     * Постійні назви до 0.4.6, у корені сховища.
     */
    const LEGACY_FILES = array( 'restore.json', 'restore.sql' );

    const TEMP_PREFIX = 'esbr_';

    const OLD_PREFIX = 'esbo_';

    /**
     * Найдовша назва таблиці в MySQL.
     */
    const MAX_TABLE_NAME = 64;

    const MANIFEST_MAX_BYTES = 16 * MB_IN_BYTES;

    /**
     * Фази, що не діляться на частини (див. ESB_Backup::FRESH_STEP_PHASES). db_extract — заради
     * архівів до 0.5.1, де весь дамп лежить одним database.sql.
     */
    const FRESH_STEP_PHASES = array( 'prepare', 'db_extract', 'finalize' );

    private static $lock;

    private static $state_path;

    /**
     * Попередній крок перервали: перша інструкція дампу могла вже виконатися.
     */
    private static $resumed = false;

    /**
     * @param string $id Ідентифікатор резервної копії.
     * @return array|WP_Error Стан відновлення.
     */
    public static function start( $id ) {
        $job = ESB_Job::load( $id );

        if ( ! $job || ! $job->has_archive() ) {
            return new WP_Error( 'esb_not_found', __( 'Резервну копію не знайдено.', 'easy-site-backup' ) );
        }

        if ( ! function_exists( 'inflate_init' ) ) {
            return new WP_Error( 'esb_zlib', __( 'На сервері немає PHP-розширення zlib.', 'easy-site-backup' ) );
        }

        if ( ESB_Backup::active_job() ) {
            return new WP_Error( 'esb_busy', __( 'Зараз створюється резервна копія. Дочекайтеся її завершення.', 'easy-site-backup' ) );
        }

        if ( self::active() ) {
            return new WP_Error( 'esb_busy', __( 'Відновлення вже виконується.', 'easy-site-backup' ) );
        }

        $base = ESB_Storage::ensure_dir();
        if ( is_wp_error( $base ) ) {
            return $base;
        }

        $state = array(
            'backup'    => $job->id,
            'created'   => $job->data['created'],
            'token'     => wp_generate_password( 32, false ),
            'status'    => 'running',
            'phase'     => 'prepare',
            'started'   => time(),
            'updated'   => time(),
            'finished'  => 0,
            'attempts'  => 0,
            'error'     => '',
            'seen'      => false,
            'has_db'    => false,
            'has_files' => false,
            'db'        => array(
                // Частини дампу (записи архіву) по черзі: розпаковується й імпортується по одній.
                'entries'  => array(),
                'part'     => 0,
                'done'     => 0,
                'tables'   => array(),
                'size'     => 0,
                'offset'   => 0,
                'sets'     => array(),
                'swapping' => false,
            ),
            'files'     => array(
                'position'    => 0,
                'total_files' => 0,
                'total_bytes' => 0,
                'done_files'  => 0,
                'done_bytes'  => 0,
                'failed'      => 0,
                'skipped'     => 0,
            ),
        );

        // Стан попереднього відновлення і файли старих версій більше не потрібні.
        foreach ( array_merge( (array) glob( self::path( self::STATE_PATTERN ) ), array_map( array( __CLASS__, 'path' ), self::LEGACY_FILES ) ) as $old ) {
            @unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        self::$state_path = null;

        try {
            self::save( $state );
        } catch ( Exception $e ) {
            return new WP_Error( 'esb_save', $e->getMessage() );
        }

        self::log( $state, 'початок' );
        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $state['token'] ) );

        return $state;
    }

    /**
     * @return array|null Стан останнього відновлення.
     */
    public static function load() {
        $path = self::state_path();

        if ( ! $path || ! is_readable( $path ) ) {
            return null;
        }

        $state = json_decode( (string) file_get_contents( $path ), true );

        return is_array( $state ) ? $state : null;
    }

    /**
     * @return array|null Відновлення, що зараз виконується.
     */
    public static function active() {
        $state = self::load();

        return $state && 'running' === $state['status'] ? $state : null;
    }

    /**
     * Виконує один крок відновлення.
     *
     * @return array|WP_Error Стан після кроку.
     */
    public static function step( $token ) {
        $state = self::load();

        if ( ! $state || ! is_string( $token ) || ! hash_equals( $state['token'], $token ) ) {
            return new WP_Error( 'esb_not_found', __( 'Відновлення не знайдено.', 'easy-site-backup' ) );
        }

        $state['busy'] = false;

        if ( 'running' !== $state['status'] ) {
            return $state;
        }

        if ( ! self::lock() ) {
            $state['busy'] = true;
            return $state;
        }

        try {
            $state = self::load();
            if ( 'running' === $state['status'] ) {
                self::run( $state );
            }
        } finally {
            self::unlock();
        }

        $state['busy'] = false;

        return $state;
    }

    /**
     * Обробник WP-Cron: виконує крок і планує наступний, поки відновлення не завершиться.
     */
    public static function cron_step( $token ) {
        $state = self::step( $token );

        if ( is_array( $state ) && 'running' === $state['status'] ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $token ) );
        }
    }

    /**
     * Позначає результат останнього відновлення показаним.
     */
    public static function mark_seen() {
        $state = self::load();

        if ( ! $state || ! empty( $state['seen'] ) ) {
            return;
        }

        $state['seen'] = true;

        try {
            self::save( $state );
        } catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Сповіщення просто покажеться ще раз.
        }
    }

    /**
     * Стан відновлення для інтерфейсу.
     */
    public static function status( array $state ) {
        if ( $state['has_db'] && $state['has_files'] ) {
            $db    = array( 0, 40 );
            $files = array( 40, 99 );
        } elseif ( $state['has_db'] ) {
            $db    = array( 0, 99 );
            $files = array( 99, 99 );
        } else {
            $db    = array( 0, 0 );
            $files = array( 0, 99 );
        }

        $percent = 0;
        $message = '';

        switch ( $state['phase'] ) {
            case 'prepare':
                $message = __( 'Перевірка архіву…', 'easy-site-backup' );
                break;

            case 'db_extract':
                $percent = $db[0];
                $message = __( 'Розпакування бази даних…', 'easy-site-backup' );
                break;

            case 'db_import':
                $total   = $state['db']['size'];
                $done    = min( $state['db']['done'] + $state['db']['offset'], $total );
                $percent = self::scale( $db, $total ? $done / $total : 1 );
                /* translators: 1: оброблений обсяг, 2: загальний обсяг */
                $message = sprintf( __( 'База даних: %1$s з %2$s', 'easy-site-backup' ), size_format( $done, 1 ), size_format( $total, 1 ) );
                break;

            case 'files':
                $total   = $state['files']['total_bytes'];
                $done    = min( $state['files']['done_bytes'], $total );
                $percent = self::scale( $files, $total ? $done / $total : 1 );
                /* translators: 1: оброблений обсяг, 2: загальний обсяг */
                $message = sprintf( __( 'Файли: %1$s з %2$s', 'easy-site-backup' ), size_format( $done, 1 ), size_format( $total, 1 ) );
                break;

            case 'finalize':
                $percent = 99;
                $message = $state['has_db'] ? __( 'Заміна бази даних…', 'easy-site-backup' ) : __( 'Завершення…', 'easy-site-backup' );
                break;
        }

        if ( 'done' === $state['status'] ) {
            $percent = 100;
            $message = __( 'Сайт відновлено!', 'easy-site-backup' );
        }

        return array(
            'id'      => $state['backup'],
            'token'   => $state['token'],
            'status'  => $state['status'],
            'percent' => (int) round( $percent ),
            // size_format() розділяє тисячі через &nbsp;, а сторінка виводить повідомлення як текст.
            'message' => html_entity_decode( $message, ENT_QUOTES, 'UTF-8' ),
            'error'   => $state['error'],
            'busy'    => ! empty( $state['busy'] ),
        );
    }

    private static function scale( $range, $fraction ) {
        return $range[0] + ( $range[1] - $range[0] ) * $fraction;
    }

    private static function run( array &$state ) {
        // Крок має завершитися, навіть якщо користувач закрив сторінку.
        ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        wp_raise_memory_limit( 'admin' );

        $started  = microtime( true );
        $deadline = $started + ESB_Backup::step_seconds();

        try {
            // Лічильник скидається лише після успішного кроку, тож він рахує кроки, які вбив сервер.
            if ( ++$state['attempts'] > ESB_Backup::MAX_ATTEMPTS ) {
                throw new RuntimeException( __( 'Крок відновлення кілька разів поспіль переривався — ймовірно, через ліміт часу або пам\'яті сервера.', 'easy-site-backup' ) );
            }
            self::$resumed = $state['attempts'] > 1;
            self::save( $state );

            // Хоча б одна дія за крок; фази, що не діляться, — лише на початку кроку.
            do {
                if ( in_array( $state['phase'], self::FRESH_STEP_PHASES, true ) && microtime( true ) - $started > ESB_Backup::FRESH_STEP_SECONDS ) {
                    break;
                }
                self::run_phase( $state, $deadline );
            } while ( 'running' === $state['status'] && microtime( true ) < $deadline );

            $state['attempts'] = 0;
            self::save( $state );
        } catch ( Exception $e ) {
            self::fail( $state, $e->getMessage() );
        }
    }

    private static function run_phase( array &$state, $deadline ) {
        switch ( $state['phase'] ) {
            case 'prepare':
                self::prepare( $state );
                break;
            case 'db_extract':
                self::db_extract( $state );
                break;
            case 'db_import':
                self::db_import( $state, $deadline );
                break;
            case 'files':
                self::files_step( $state, $deadline );
                break;
            case 'finalize':
                self::finalize( $state );
                break;
            default:
                throw new RuntimeException( __( 'Невідома фаза відновлення.', 'easy-site-backup' ) );
        }
    }

    private static function fail( array &$state, $message ) {
        $state['status']   = 'failed';
        $state['error']    = $message;
        $state['finished'] = time();
        self::log( $state, 'помилка: ' . $message );

        // Після вдалого RENAME тимчасових таблиць уже немає, тож прибирати їх безпечно завжди.
        try {
            self::drop_tables( self::TEMP_PREFIX, $state['db']['tables'] );
        } catch ( Exception $e ) {
            self::log( $state, 'не вдалося видалити тимчасові таблиці: ' . $e->getMessage() );
        }

        @unlink( self::sql_path( $state ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        try {
            self::save( $state );
        } catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Записати стан не вдалося — наступний крок упреться в ліміт спроб.
        }
    }

    /*
     * Підготовка.
     */

    /**
     * Перевіряє архів і manifest, рахує файли для прогресу.
     */
    private static function prepare( array &$state ) {
        global $wpdb;

        $reader = self::reader( $state );

        try {
            $manifest_entry = null;
            $db_entries     = array();
            $files          = 0;
            $bytes          = 0;
            $largest        = 0;
            $position       = $reader->cd_offset;

            while ( $entry = $reader->next_entry( $position ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
                if ( 'manifest.json' === $entry['name'] ) {
                    $manifest_entry = $entry;
                } elseif ( preg_match( '/^database(?:-(\d+))?\.sql$/', $entry['name'], $m ) ) {
                    // database-001.sql… — частини дампу; database.sql — увесь дамп (архіви до 0.5.1).
                    $db_entries[ isset( $m[1] ) ? (int) $m[1] : 0 ] = $entry;
                } elseif ( 0 === strpos( $entry['name'], 'files/' ) && '/' !== substr( $entry['name'], -1 ) ) {
                    $files++;
                    $bytes  += $entry['size'];
                    $largest = max( $largest, $entry['size'] );
                }
            }

            if ( ! $manifest_entry ) {
                throw new RuntimeException( __( 'В архіві немає manifest.json — це не резервна копія Easy Site Backup.', 'easy-site-backup' ) );
            }

            $manifest = json_decode( $reader->read_string( $manifest_entry, self::MANIFEST_MAX_BYTES ), true );
        } finally {
            $reader->close();
        }

        if ( ! is_array( $manifest ) || empty( $manifest['type'] ) ) {
            throw new RuntimeException( __( 'Файл manifest.json в архіві пошкоджено.', 'easy-site-backup' ) );
        }

        if ( ! isset( $manifest['home_url'] ) || untrailingslashit( $manifest['home_url'] ) !== untrailingslashit( home_url() ) ) {
            /* translators: %s: адреса сайту з копії */
            throw new RuntimeException( sprintf( __( 'Копію створено для іншого сайту (%s). Перенесення між сайтами поки не підтримується.', 'easy-site-backup' ), isset( $manifest['home_url'] ) ? $manifest['home_url'] : '?' ) );
        }

        $state['has_db']    = in_array( $manifest['type'], array( 'full', 'db' ), true );
        $state['has_files'] = in_array( $manifest['type'], array( 'full', 'files' ), true );

        ksort( $db_entries );
        $db_entries = array_values( $db_entries );
        $db_part    = 0;
        foreach ( $db_entries as $entry ) {
            $db_part = max( $db_part, $entry['size'] );
        }

        // Розпаковується одна частина дампу за раз, а кожен файл пишеться поруч перед заміною.
        // Місце під тимчасові таблиці не перевіряємо: дані MySQL можуть лежати на іншому диску.
        ESB_Storage::require_space(
            ( $state['has_db'] ? $db_part : 0 ) + ( $state['has_files'] ? $largest : 0 ) + ESB_Storage::SPACE_MARGIN,
            __( 'відновлення', 'easy-site-backup' )
        );

        if ( $state['has_db'] ) {
            if ( ! $db_entries ) {
                throw new RuntimeException( __( 'В архіві немає дампу бази даних.', 'easy-site-backup' ) );
            }

            if ( isset( $manifest['db_parts'] ) && (int) $manifest['db_parts'] !== count( $db_entries ) ) {
                throw new RuntimeException( __( 'В архіві бракує частин дампу бази даних.', 'easy-site-backup' ) );
            }

            if ( ! isset( $manifest['table_prefix'] ) || $manifest['table_prefix'] !== $wpdb->base_prefix ) {
                /* translators: 1: префікс з копії, 2: префікс сайту */
                throw new RuntimeException( sprintf( __( 'Префікс таблиць у копії (%1$s) відрізняється від префікса сайту (%2$s).', 'easy-site-backup' ), isset( $manifest['table_prefix'] ) ? $manifest['table_prefix'] : '?', $wpdb->base_prefix ) );
            }

            $tables = isset( $manifest['tables'] ) && is_array( $manifest['tables'] ) ? $manifest['tables'] : array();
            if ( empty( $tables ) ) {
                throw new RuntimeException( __( 'У manifest.json немає списку таблиць.', 'easy-site-backup' ) );
            }

            foreach ( $tables as $table ) {
                if ( ! is_string( $table ) || 0 !== strpos( $table, $wpdb->base_prefix ) || strlen( self::OLD_PREFIX . $table ) > self::MAX_TABLE_NAME ) {
                    /* translators: %s: назва таблиці */
                    throw new RuntimeException( sprintf( __( 'Таблицю %s неможливо відновити.', 'easy-site-backup' ), is_string( $table ) ? $table : '?' ) );
                }
            }

            $state['db']['entries'] = $db_entries;
            $state['db']['size']    = array_sum( wp_list_pluck( $db_entries, 'size' ) );
            $state['db']['tables']  = $tables;

            // Залишки попереднього невдалого відновлення.
            self::drop_tables( self::TEMP_PREFIX, $tables );
        }

        $state['files']['position']    = $reader->cd_offset;
        $state['files']['total_files'] = $files;
        $state['files']['total_bytes'] = $bytes;

        $state['phase'] = $state['has_db'] ? 'db_extract' : 'files';
        self::log( $state, sprintf( 'архів перевірено, таблиць: %d, файлів: %d (%s)', count( $state['db']['tables'] ), $files, size_format( $bytes, 1 ) ) );
        self::save( $state );
    }

    /*
     * База даних.
     */

    /**
     * Розпаковує поточну частину дампу.
     */
    private static function db_extract( array &$state ) {
        $reader = self::reader( $state );
        $out    = null;

        try {
            $out = self::open( self::sql_path( $state ), 'wb' );
            $reader->extract( $state['db']['entries'][ $state['db']['part'] ], $out );
        } finally {
            if ( $out ) {
                fclose( $out );
            }
            $reader->close();
        }

        $state['db']['offset'] = 0;
        $state['phase']        = 'db_import';
        self::save( $state );
    }

    /**
     * Виконує інструкції дампу, поки не вичерпається час кроку.
     *
     * Кожна інструкція дампу закінчується рядком, що завершується «;». Перенесення рядків
     * у значеннях екрановано (esc_sql), тож усередині значень такого рядка бути не може.
     */
    private static function db_import( array &$state, $deadline ) {
        $db = &$state['db'];
        $fh = self::open( self::sql_path( $state ), 'rb' );

        try {
            if ( 0 !== fseek( $fh, $db['offset'] ) ) {
                throw new RuntimeException( __( 'Не вдалося прочитати дамп бази даних.', 'easy-site-backup' ) );
            }

            // Кожен крок — нове з'єднання з базою, тож налаштування сесії треба повторити.
            foreach ( $db['sets'] as $set ) {
                self::query( $set );
            }

            $statement = '';

            while ( true ) {
                if ( '' === $statement && microtime( true ) >= $deadline ) {
                    break;
                }

                $line = fgets( $fh );

                if ( false === $line ) {
                    // Частина закінчується на межі інструкції, тож недописана інструкція — це обрив.
                    if ( '' !== trim( $statement ) ) {
                        throw new RuntimeException( __( 'Дамп бази даних обірвано.', 'easy-site-backup' ) );
                    }

                    $db['done']  += $db['entries'][ $db['part'] ]['size'];
                    $db['offset'] = 0;

                    if ( $db['part'] + 1 < count( $db['entries'] ) ) {
                        $db['part']++;
                        $state['phase'] = 'db_extract';
                        break;
                    }

                    self::log( $state, sprintf( 'базу даних імпортовано в тимчасові таблиці (частин: %d)', count( $db['entries'] ) ) );
                    $state['phase'] = $state['has_files'] ? 'files' : 'finalize';
                    break;
                }

                if ( '' === $statement && ( '' === trim( $line ) || 0 === strpos( $line, '--' ) ) ) {
                    $db['offset'] = ftell( $fh );
                    continue;
                }

                $statement .= $line;

                if ( ';' !== substr( rtrim( $line ), -1 ) ) {
                    continue;
                }

                self::execute( $state, rtrim( $statement ) );
                $statement    = '';
                $db['offset'] = ftell( $fh );

                // Після кожної інструкції: якщо крок переб'ють, повториться щонайбільше одна.
                self::save( $state );
            }
        } finally {
            fclose( $fh );
        }

        self::save( $state );
    }

    /**
     * Виконує інструкцію дампу, перенаправивши її на тимчасову таблицю.
     */
    private static function execute( array &$state, $sql ) {
        $resumed       = self::$resumed;
        self::$resumed = false;

        if ( preg_match( '/^SET\s+(\w+)/i', $sql, $m ) ) {
            $state['db']['sets'][ strtoupper( $m[1] ) ] = $sql;
            self::query( $sql );
            return;
        }

        // Дозволено лише те, що пише ESB_Backup: так з архіву не виконається довільний SQL.
        if ( ! preg_match( '/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) (`(?:[^`]|``)+`)/', $sql, $m ) ) {
            throw new RuntimeException( __( 'Невідома інструкція в дампі бази даних.', 'easy-site-backup' ) );
        }

        $table = self::unquote( $m[2] );
        if ( ! in_array( $table, $state['db']['tables'], true ) ) {
            /* translators: %s: назва таблиці */
            throw new RuntimeException( sprintf( __( 'Таблиці %s немає в списку таблиць копії.', 'easy-site-backup' ), $table ) );
        }

        $verb = $m[1];
        $temp = self::quote( self::TEMP_PREFIX . $table );
        $sql  = $verb . ' ' . $temp . substr( $sql, strlen( $m[0] ) );

        if ( 'CREATE TABLE' === $verb ) {
            // Таблицю могли створити в перерваному кроці.
            self::query( "DROP TABLE IF EXISTS {$temp}" );
            $sql = self::rewrite_foreign_keys( $sql, $state['db']['tables'] );
        } elseif ( 'INSERT INTO' === $verb && $resumed ) {
            // Ця вставка могла вже виконатися в перерваному кроці.
            $sql = 'INSERT IGNORE INTO' . substr( $sql, strlen( 'INSERT INTO' ) );
        }

        self::query( $sql );
    }

    /**
     * Зовнішні ключі мають посилатися на тимчасові таблиці. Імена обмежень мусять бути
     * унікальними в межах бази, тому їх прибираємо — MySQL згенерує власні.
     */
    private static function rewrite_foreign_keys( $sql, array $tables ) {
        $sql = preg_replace( '/CONSTRAINT `(?:[^`]|``)+` FOREIGN KEY/', 'FOREIGN KEY', $sql );

        return preg_replace_callback(
            '/REFERENCES (`(?:[^`]|``)+`)/',
            function ( $m ) use ( $tables ) {
                $table = self::unquote( $m[1] );
                return in_array( $table, $tables, true ) ? 'REFERENCES ' . self::quote( self::TEMP_PREFIX . $table ) : $m[0];
            },
            $sql
        );
    }

    /**
     * Підміняє робочі таблиці тимчасовими одним атомарним RENAME TABLE.
     */
    private static function swap_tables( array &$state ) {
        global $wpdb;

        $tables  = $state['db']['tables'];
        $temp    = self::existing_tables( self::TEMP_PREFIX );
        $missing = array_diff( $tables, array_map( array( __CLASS__, 'strip_temp_prefix' ), $temp ) );

        self::query( 'SET FOREIGN_KEY_CHECKS = 0' );

        if ( $missing ) {
            // Тимчасових таблиць немає зовсім, а підміна вже почалася — отже, RENAME виконався в перерваному кроці.
            if ( ! $state['db']['swapping'] || count( $missing ) !== count( $tables ) ) {
                /* translators: %s: назви таблиць */
                throw new RuntimeException( sprintf( __( 'Не знайдено тимчасових таблиць відновлення: %s.', 'easy-site-backup' ), implode( ', ', $missing ) ) );
            }
        } else {
            self::drop_tables( self::OLD_PREFIX, $tables );

            $live  = self::existing_tables( $wpdb->base_prefix );
            $pairs = array();
            foreach ( $tables as $table ) {
                if ( in_array( $table, $live, true ) ) {
                    $pairs[] = self::quote( $table ) . ' TO ' . self::quote( self::OLD_PREFIX . $table );
                }
                $pairs[] = self::quote( self::TEMP_PREFIX . $table ) . ' TO ' . self::quote( $table );
            }

            $state['db']['swapping'] = true;
            self::save( $state );

            self::query( 'RENAME TABLE ' . implode( ', ', $pairs ) );
        }

        self::drop_tables( self::OLD_PREFIX, $tables );
    }

    private static function strip_temp_prefix( $name ) {
        return substr( $name, strlen( self::TEMP_PREFIX ) );
    }

    /**
     * @return string[] Таблиці поточної бази з префіксом $prefix.
     */
    private static function existing_tables( $prefix ) {
        global $wpdb;

        return (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );
    }

    private static function drop_tables( $prefix, array $tables ) {
        if ( empty( $tables ) ) {
            return;
        }

        self::query( 'SET FOREIGN_KEY_CHECKS = 0' );

        foreach ( array_chunk( $tables, 100 ) as $chunk ) {
            $names = array();
            foreach ( $chunk as $table ) {
                $names[] = self::quote( $prefix . $table );
            }
            self::query( 'DROP TABLE IF EXISTS ' . implode( ', ', $names ) );
        }
    }

    /**
     * Виконує запит напряму через mysqli: wpdb::query() перевіряє кодування кожного запиту,
     * що на великих INSERT дуже повільно, а дамп знято з цієї ж бази.
     */
    private static function query( $sql ) {
        global $wpdb;

        // phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- дамп власного формату, імена екрановано.
        if ( $wpdb->dbh instanceof mysqli ) {
            if ( false !== mysqli_query( $wpdb->dbh, $sql ) ) {
                return;
            }
            $error = mysqli_error( $wpdb->dbh );
        } else {
            // Нестандартний драйвер бази (drop-in db.php).
            $suppress = $wpdb->suppress_errors( true );
            $result   = $wpdb->query( $sql );
            $wpdb->suppress_errors( $suppress );

            if ( false !== $result ) {
                return;
            }
            $error = $wpdb->last_error;
        }
        // phpcs:enable

        /* translators: %s: текст помилки */
        throw new RuntimeException( sprintf( __( 'Помилка бази даних: %s', 'easy-site-backup' ), $error ) );
    }

    private static function quote( $name ) {
        return '`' . str_replace( '`', '``', $name ) . '`';
    }

    private static function unquote( $quoted ) {
        return str_replace( '``', '`', substr( $quoted, 1, -1 ) );
    }

    /*
     * Файли.
     */

    /**
     * Розпаковує файли з архіву, поки не вичерпається час кроку.
     */
    private static function files_step( array &$state, $deadline ) {
        $files  = &$state['files'];
        $root   = untrailingslashit( wp_normalize_path( ABSPATH ) );
        $skip   = self::skipped_paths( $root );
        $reader = self::reader( $state );

        try {
            do {
                // Великий файл, що розпаковується частинами, — спершу дописуємо його.
                if ( ! empty( $files['large'] ) ) {
                    self::continue_large( $state, $reader, $root, $deadline );
                    continue;
                }

                $position = $files['position'];
                $entry    = $reader->next_entry( $position );

                if ( ! $entry ) {
                    self::log( $state, sprintf( 'файлів відновлено: %d, не вдалося: %d, пропущено: %d', $files['done_files'], $files['failed'], $files['skipped'] ) );
                    $state['phase'] = 'finalize';
                    break;
                }

                $files['position'] = $position;

                if ( 0 !== strpos( $entry['name'], 'files/' ) || '/' === substr( $entry['name'], -1 ) ) {
                    continue;
                }

                $relative = substr( $entry['name'], strlen( 'files/' ) );

                if ( ! self::is_safe_path( $relative ) ) {
                    $files['skipped']++;
                    $files['done_bytes'] += $entry['size'];
                    self::log( $state, 'пропущено файл із неприпустимим шляхом: ' . $relative );
                    continue;
                }

                if ( self::is_skipped( $relative, $skip ) ) {
                    $files['skipped']++;
                    $files['done_bytes'] += $entry['size'];
                    continue;
                }

                // Великий файл без стиснення — частинами між кроками (CRC частин — лише на 64-бітному PHP).
                if ( 0 === $entry['method'] && $entry['size'] >= ESB_Backup::large_file_bytes() && PHP_INT_SIZE >= 8 ) {
                    $files['large'] = array(
                        'entry'    => $entry,
                        'relative' => $relative,
                        'done'     => 0,
                        'crc'      => 0,
                    );
                    continue;
                }

                $files['done_bytes'] += $entry['size'];

                if ( self::restore_file( $reader, $entry, $root . '/' . $relative ) ) {
                    $files['done_files']++;
                } else {
                    $files['failed']++;
                    self::log( $state, 'не вдалося записати файл: ' . $relative );
                }
            } while ( microtime( true ) < $deadline );
        } finally {
            $reader->close();
        }

        self::save( $state );
    }

    /**
     * Дописує частину великого файлу у тимчасовий файл поруч; коли файл готовий — підміняє наявний.
     * Якщо крок переб'ють, тимчасовий файл обріжеться до збереженої позиції й допишеться далі.
     */
    private static function continue_large( array &$state, ESB_Zip_Reader $reader, $root, $deadline ) {
        $files = &$state['files'];
        $large = &$files['large'];
        $path  = $root . '/' . $large['relative'];
        $temp  = $path . '.esb-restore';
        $size  = $large['entry']['size'];

        $out = wp_mkdir_p( dirname( $path ) ) ? @fopen( $temp, 'c+b' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $out || ! ftruncate( $out, $large['done'] ) || 0 !== fseek( $out, $large['done'] ) ) {
            if ( $out ) {
                fclose( $out );
            }
            @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $files['failed']++;
            $files['done_bytes'] += $size - $large['done'];
            self::log( $state, 'не вдалося записати файл: ' . $large['relative'] );
            $files['large'] = null;
            return;
        }

        $before   = $large['done'];
        $complete = false;
        $ok       = false;
        try {
            // Пошкоджений архів або брак місця зупиняють усе відновлення.
            $complete = $reader->extract_stored_part( $large['entry'], $out, $large['done'], $large['crc'], $deadline );
            $ok       = true;
        } finally {
            fclose( $out );
            if ( ! $ok ) {
                @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        $files['done_bytes'] += $large['done'] - $before;

        if ( ! $complete ) {
            return;
        }

        if ( @rename( $temp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @touch( $path, $large['entry']['mtime'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $files['done_files']++;
        } else {
            @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $files['failed']++;
            self::log( $state, 'не вдалося записати файл: ' . $large['relative'] );
        }

        $files['large'] = null;
    }

    /**
     * Розпаковує файл поруч і підміняє ним наявний, щоб на місці не лишився обрізаний файл.
     *
     * @return bool false, якщо файл не вдалося записати (права, блокування).
     */
    private static function restore_file( ESB_Zip_Reader $reader, array $entry, $path ) {
        if ( ! wp_mkdir_p( dirname( $path ) ) ) {
            return false;
        }

        $temp = $path . '.esb-restore';
        $out  = @fopen( $temp, 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $out ) {
            return false;
        }

        $ok = false;
        try {
            // Пошкоджений архів або брак місця зупиняють усе відновлення.
            $reader->extract( $entry, $out );
            $ok = true;
        } finally {
            fclose( $out );
            if ( ! $ok ) {
                @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        if ( ! @rename( $temp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return false;
        }

        @touch( $path, $entry['mtime'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        return true;
    }

    /**
     * Шлях не виходить за межі кореня сайту.
     */
    private static function is_safe_path( $relative ) {
        if ( '' === $relative || false !== strpbrk( $relative, "\\\0" ) || preg_match( '/^[a-z]:/i', $relative ) ) {
            return false;
        }

        foreach ( explode( '/', $relative ) as $segment ) {
            if ( '' === $segment || '.' === $segment || '..' === $segment ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Що не відновлюється: налаштування підключення до бази, сам плагін, який зараз
     * виконує відновлення, і тека сховища. Шляхи на «/» — теки.
     */
    private static function skipped_paths( $root ) {
        $paths = array( 'wp-config.php', '.maintenance' );

        foreach ( array( ESB_Storage::dir(), wp_normalize_path( ESB_DIR ) ) as $dir ) {
            $dir = trailingslashit( $dir );
            if ( 0 === strpos( $dir, $root . '/' ) ) {
                $paths[] = substr( $dir, strlen( $root ) + 1 );
            }
        }

        return $paths;
    }

    private static function is_skipped( $relative, array $skip ) {
        foreach ( $skip as $path ) {
            if ( '/' === substr( $path, -1 ) ? 0 === strpos( $relative, $path ) : $relative === $path ) {
                return true;
            }
        }

        return false;
    }

    /*
     * Спільне.
     */

    private static function finalize( array &$state ) {
        if ( $state['has_db'] ) {
            self::swap_tables( $state );
            self::log( $state, 'базу даних замінено' );
        }

        @unlink( self::sql_path( $state ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        wp_cache_flush();

        $state['status']   = 'done';
        $state['finished'] = time();
        self::log( $state, 'готово' );
        self::save( $state );
    }

    private static function reader( array $state ) {
        return new ESB_Zip_Reader( ESB_Storage::job_dir( $state['backup'] ) . '/' . ESB_Storage::ARCHIVE );
    }

    private static function path( $name ) {
        return ESB_Storage::dir() . '/' . $name;
    }

    /**
     * Шлях до розпакованого дампу — у теці копії з випадковою назвою.
     */
    public static function sql_path( array $state ) {
        return ESB_Storage::job_dir( $state['backup'] ) . '/' . self::SQL;
    }

    /**
     * @return string|null Файл стану відновлення, якщо він є.
     */
    public static function state_path() {
        if ( self::$state_path && is_file( self::$state_path ) ) {
            return self::$state_path;
        }

        $found = glob( self::path( self::STATE_PATTERN ) );
        if ( $found ) {
            return self::$state_path = $found[0];
        }

        // Стан від версії до 0.4.6 — переносимо під випадкову назву.
        $legacy = self::path( self::LEGACY_FILES[0] );
        if ( is_file( $legacy ) ) {
            $path = self::new_state_path();
            if ( @rename( $legacy, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                return self::$state_path = $path;
            }
        }

        return null;
    }

    private static function new_state_path() {
        return self::path( str_replace( '*', strtolower( wp_generate_password( 32, false ) ), self::STATE_PATTERN ) );
    }

    private static function save( array &$state ) {
        $state['updated'] = time();

        $path = self::state_path();
        if ( ! $path ) {
            $path = self::$state_path = self::new_state_path();
        }

        if ( ! ESB_Storage::write_atomic( $path, wp_json_encode( $state ) ) ) {
            throw new RuntimeException( __( 'Не вдалося зберегти стан відновлення. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
        }
    }

    /**
     * Пише в лог самої копії.
     */
    private static function log( array $state, $message ) {
        $job = ESB_Job::load( $state['backup'] );

        if ( $job ) {
            $job->log( 'Відновлення: ' . $message );
        }
    }

    /**
     * Блокування через flock: якщо процес помре, система зніме його сама.
     */
    private static function lock() {
        $fh = @fopen( self::path( 'restore.lock' ), 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $fh ) {
            return false;
        }

        if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
            fclose( $fh );
            return false;
        }

        self::$lock = $fh;

        return true;
    }

    private static function unlock() {
        if ( self::$lock ) {
            flock( self::$lock, LOCK_UN );
            fclose( self::$lock );
            self::$lock = null;
        }
    }

    private static function open( $path, $mode ) {
        $fh = @fopen( $path, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( ! $fh ) {
            /* translators: %s: назва файлу */
            throw new RuntimeException( sprintf( __( 'Не вдалося відкрити файл %s.', 'easy-site-backup' ), basename( $path ) ) );
        }

        return $fh;
    }
}
