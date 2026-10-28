<?php
/**
 * Покрокове створення резервної копії: один ZIP-архів з дампом бази даних і файлами сайту.
 *
 * Кожен крок працює не довше STEP_SECONDS, тож бекап не впирається в ліміт часу PHP
 * і таймаут проксі (у Cloudflare — 100 секунд). Кроки запускає AJAX зі сторінки
 * плагіна, а якщо її закрили — WP-Cron.
 *
 * Фази: db_init → db → archive_db → files_scan → files_zip → finalize.
 *
 * Вміст архіву: database-001.sql, database-002.sql… (дамп бази частинами), files/… (файли сайту
 * відносно кореня WordPress), manifest.json.
 *
 * Жодна операція не має тривати довше за крок, хоч би якою великою була база чи файл: дамп
 * пишеться частинами по DUMP_PART_BYTES, а великі файли копіюються в архів частинами з
 * продовженням у наступному кроці. Фази, які не діляться (пошук файлів, завершення), починаються
 * лише на початку кроку, щоб мати весь його час.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Backup {

    const TYPES = array( 'full', 'db', 'files' );

    const ACTIVE_OPTION = 'esb_active_job';

    const CRON_HOOK = 'esb_run_step';

    const STEP_SECONDS = 20;

    /**
     * Фази, що не діляться на частини: починаються, лише якщо від початку кроку минуло не більше
     * FRESH_STEP_SECONDS. Інакше крок закінчується, і фаза отримує весь наступний.
     */
    const FRESH_STEP_PHASES = array( 'files_scan', 'finalize' );

    const FRESH_STEP_SECONDS = 5;

    /**
     * Розмір частини дампу (нестиснений SQL): її стиснення в архів — коротка операція навіть на повільному хостингу.
     */
    const DUMP_PART_BYTES = 16 * MB_IN_BYTES;

    /**
     * Скільки разів поспіль крок може перерватися, перш ніж завдання вважається невдалим.
     */
    const MAX_ATTEMPTS = 3;

    const ROWS_PER_QUERY = 500;

    /**
     * Великі файли додаються без стиснення і частинами між кроками (на 64-бітному PHP).
     */
    const STORE_LARGER_THAN = 64 * MB_IN_BYTES;

    /**
     * Тривалість кроку. Фільтр — для тестів і хостингів з коротшим лімітом часу.
     */
    public static function step_seconds() {
        return (float) apply_filters( 'esb_step_seconds', self::STEP_SECONDS );
    }

    public static function dump_part_bytes() {
        return (int) apply_filters( 'esb_dump_part_bytes', self::DUMP_PART_BYTES );
    }

    /**
     * Файли від цього розміру копіюються частинами між кроками.
     */
    public static function large_file_bytes() {
        return (int) apply_filters( 'esb_large_file_bytes', self::STORE_LARGER_THAN );
    }

    /**
     * Уже стиснені формати додаються без повторного стиснення — це значно швидше.
     */
    const STORE_EXTENSIONS = array(
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic',
        'mp3', 'm4a', 'ogg', 'mp4', 'm4v', 'mov', 'webm',
        'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar',
        'woff', 'woff2', 'pdf',
    );

    /**
     * Теки кешу й бекапів інших плагінів, відносно кореня сайту.
     */
    const EXCLUDE_DIRS = array(
        'wp-content/cache',
        'wp-content/upgrade',
        'wp-content/upgrade-temp-backup',
        'wp-content/ai1wm-backups',
        'wp-content/updraft',
        'wp-content/backups-dup-lite',
    );

    /**
     * @param string $type      full | db | files.
     * @param bool   $scheduled Копія за розкладом (на неї діє ротація).
     * @return ESB_Job|WP_Error
     */
    public static function start( $type, $scheduled = false ) {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            return new WP_Error( 'esb_type', __( 'Невідомий тип резервної копії.', 'easy-site-backup' ) );
        }

        if ( ! function_exists( 'deflate_init' ) ) {
            return new WP_Error( 'esb_zlib', __( 'На сервері немає PHP-розширення zlib.', 'easy-site-backup' ) );
        }

        if ( self::active_job() ) {
            return new WP_Error( 'esb_busy', __( 'Резервне копіювання вже виконується.', 'easy-site-backup' ) );
        }

        if ( ESB_Restore::active() ) {
            return new WP_Error( 'esb_busy', __( 'Зараз виконується відновлення сайту. Дочекайтеся його завершення.', 'easy-site-backup' ) );
        }

        try {
            ESB_Storage::require_space( ESB_Storage::SPACE_MARGIN, __( 'резервної копії', 'easy-site-backup' ) );
        } catch ( RuntimeException $e ) {
            return new WP_Error( 'esb_space', $e->getMessage() );
        }

        $job = ESB_Job::create( $type );
        if ( is_wp_error( $job ) ) {
            return $job;
        }

        $job->data['phase']     = 'files' === $type ? 'files_scan' : 'db_init';
        $job->data['scheduled'] = (bool) $scheduled;
        $job->data['zip']   = array(
            'size'    => 0,
            'cd_size' => 0,
            'entries' => 0,
        );

        try {
            $job->save();
        } catch ( Exception $e ) {
            ESB_Storage::delete_dir( $job->id );
            return new WP_Error( 'esb_save', $e->getMessage() );
        }

        $job->log( 'Початок резервного копіювання, тип: ' . $type . ( $scheduled ? ' (за розкладом)' : '' ) );
        update_option( self::ACTIVE_OPTION, $job->id, false );
        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $job->id ) );

        return $job;
    }

    public static function type_labels() {
        return array(
            'full'  => __( 'База даних + файли', 'easy-site-backup' ),
            'db'    => __( 'Тільки база даних', 'easy-site-backup' ),
            'files' => __( 'Тільки файли', 'easy-site-backup' ),
        );
    }

    /**
     * @return ESB_Job|null Завдання, що зараз виконується.
     */
    public static function active_job() {
        $id  = get_option( self::ACTIVE_OPTION );
        $job = $id ? ESB_Job::load( $id ) : null;

        if ( $job && $job->is_running() ) {
            return $job;
        }

        if ( $job && 'done' === $job->data['status'] ) {
            // Крок убили між збереженням готової копії і прибиранням (див. finalize()). Лише тимчасові
            // файли: повідомляти про завершення (ротація, лист) з простої перевірки стану не будемо.
            self::remove_temp_files( $job );
        }

        if ( $id ) {
            delete_option( self::ACTIVE_OPTION );
        }

        return null;
    }

    /**
     * Виконує один крок завдання.
     *
     * @return ESB_Job|WP_Error Стан завдання після кроку.
     */
    public static function step( $id ) {
        $job = ESB_Job::load( $id );

        if ( ! $job ) {
            return new WP_Error( 'esb_not_found', __( 'Резервну копію не знайдено.', 'easy-site-backup' ) );
        }

        if ( ! $job->is_running() ) {
            return $job;
        }

        if ( ! $job->lock() ) {
            $job->busy = true;
            return $job;
        }

        try {
            $job->reload();
            if ( $job->is_running() ) {
                self::run( $job );
            }
        } finally {
            $job->unlock();
        }

        return $job;
    }

    /**
     * Обробник WP-Cron: виконує крок і планує наступний, поки завдання не завершиться.
     */
    public static function cron_step( $id ) {
        $job = self::step( $id );

        if ( $job instanceof ESB_Job && $job->is_running() ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $id ) );
        }
    }

    public static function cancel( $id ) {
        $job = ESB_Job::load( $id );

        if ( ! $job || ! $job->is_running() ) {
            return;
        }

        touch( $job->path( 'cancel' ) );

        // Якщо зараз ніхто не виконує крок — скасовуємо одразу, інакше це зробить поточний крок.
        if ( $job->lock() ) {
            self::finish_cancel( $job );
        }
    }

    /**
     * Стан завдання для інтерфейсу.
     */
    public static function status( ESB_Job $job ) {
        $data    = $job->data;
        $percent = 0;
        $message = '';

        // Частка загального прогресу, яку займає кожна фаза.
        $ranges = array(
            'full'  => array( 'db' => array( 0, 15 ), 'archive_db' => 15, 'files' => array( 20, 99 ) ),
            'db'    => array( 'db' => array( 0, 80 ), 'archive_db' => 80 ),
            'files' => array( 'files' => array( 0, 99 ) ),
        );
        $range  = $ranges[ $data['type'] ];

        switch ( $data['phase'] ) {
            case 'db_init':
                $message = __( 'Підготовка…', 'easy-site-backup' );
                break;

            case 'db':
                $total   = count( $data['db']['tables'] );
                $done    = $data['db']['table'];
                $percent = self::scale( $range['db'], $total ? $done / $total : 1 );
                /* translators: 1: номер таблиці, 2: кількість таблиць */
                $message = sprintf( __( 'База даних: таблиця %1$d з %2$d', 'easy-site-backup' ), min( $done + 1, $total ), $total );
                break;

            case 'archive_db':
                $percent = $range['archive_db'];
                /* translators: 1: номер частини, 2: кількість частин */
                $message = sprintf( __( 'Додавання бази даних до архіву: частина %1$d з %2$d', 'easy-site-backup' ), $data['db']['archived'] + 1, count( $data['db']['parts'] ) );
                break;

            case 'files_scan':
                $percent = self::scale( $range['files'], 0 );
                $message = __( 'Пошук файлів…', 'easy-site-backup' );
                break;

            case 'files_zip':
                $total   = $data['files']['total_bytes'];
                $done    = min( $data['files']['done_bytes'], $total );
                $percent = self::scale( $range['files'], $total ? $done / $total : 1 );
                /* translators: 1: оброблений обсяг, 2: загальний обсяг */
                $message = sprintf( __( 'Файли: %1$s з %2$s', 'easy-site-backup' ), size_format( $done, 1 ), size_format( $total, 1 ) );
                break;

            case 'finalize':
                $percent = 99;
                $message = __( 'Завершення…', 'easy-site-backup' );
                break;
        }

        if ( 'done' === $data['status'] ) {
            $percent = 100;
            $message = __( 'Готово!', 'easy-site-backup' );
        }

        return array(
            'id'      => $job->id,
            'status'  => $data['status'],
            'percent' => (int) round( $percent ),
            // size_format() розділяє тисячі через &nbsp;, а сторінка виводить повідомлення як текст.
            'message' => html_entity_decode( $message, ENT_QUOTES, 'UTF-8' ),
            'error'   => $data['error'],
            'busy'    => $job->busy,
        );
    }

    private static function scale( $range, $fraction ) {
        return $range[0] + ( $range[1] - $range[0] ) * $fraction;
    }

    private static function run( ESB_Job $job ) {
        if ( $job->cancel_requested() ) {
            self::finish_cancel( $job );
            return;
        }

        // Крок має завершитися, навіть якщо користувач закрив сторінку.
        ignore_user_abort( true );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        wp_raise_memory_limit( 'admin' );

        $started  = microtime( true );
        $deadline = $started + self::step_seconds();

        try {
            // Лічильник скидається лише після успішного кроку, тож він рахує кроки, які вбив сервер.
            if ( ++$job->data['attempts'] > self::MAX_ATTEMPTS ) {
                throw new RuntimeException( __( 'Крок резервного копіювання кілька разів поспіль переривався — ймовірно, через ліміт часу або пам\'яті сервера.', 'easy-site-backup' ) );
            }
            $job->save();

            // Хоча б одна дія за крок — інакше за дуже короткого кроку завдання не просунулося б.
            do {
                if ( in_array( $job->data['phase'], self::FRESH_STEP_PHASES, true ) && microtime( true ) - $started > self::FRESH_STEP_SECONDS ) {
                    break;
                }
                self::run_phase( $job, $deadline );
            } while ( $job->is_running() && microtime( true ) < $deadline && ! $job->cancel_requested() );

            if ( $job->cancel_requested() ) {
                self::finish_cancel( $job );
                return;
            }

            $job->data['attempts'] = 0;
            $job->save();
        } catch ( Exception $e ) {
            self::fail( $job, $e->getMessage() );
        }
    }

    private static function run_phase( ESB_Job $job, $deadline ) {
        switch ( $job->data['phase'] ) {
            case 'db_init':
                self::db_init( $job );
                break;
            case 'db':
                self::db_step( $job, $deadline );
                break;
            case 'archive_db':
                self::archive_db( $job );
                break;
            case 'files_scan':
                self::files_scan( $job );
                break;
            case 'files_zip':
                self::files_step( $job, $deadline );
                break;
            case 'finalize':
                self::finalize( $job );
                break;
            default:
                throw new RuntimeException( __( 'Невідома фаза резервного копіювання.', 'easy-site-backup' ) );
        }
    }

    private static function fail( ESB_Job $job, $message ) {
        $job->data['status']   = 'failed';
        $job->data['error']    = $message;
        $job->data['finished'] = time();
        $job->log( 'Помилка: ' . $message );

        // Лог і стан лишаємо, щоб було видно причину; неповний архів видаляємо.
        @unlink( $job->path( ESB_Storage::ARCHIVE ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        self::remove_temp_files( $job );

        try {
            $job->save();
        } catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Записати стан не вдалося — у списку копія покажеться як перервана.
        }

        delete_option( self::ACTIVE_OPTION );

        do_action( 'esb_backup_finished', $job );
    }

    private static function finish_cancel( ESB_Job $job ) {
        // На Windows не можна видалити теку з відкритим файлом блокування.
        $job->unlock();
        ESB_Storage::delete_dir( $job->id );
        delete_option( self::ACTIVE_OPTION );

        $job->data['status'] = 'cancelled';
    }

    /*
     * База даних.
     */

    private static function db_init( ESB_Job $job ) {
        global $wpdb;

        // base_prefix охоплює і таблиці всіх сайтів мультисайту.
        $tables = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE %s
                 ORDER BY TABLE_NAME",
                $wpdb->esc_like( $wpdb->base_prefix ) . '%'
            )
        );

        if ( empty( $tables ) ) {
            throw new RuntimeException( __( 'Не знайдено жодної таблиці бази даних.', 'easy-site-backup' ) );
        }

        $path = self::dump_path( $job, 1 );
        $fh   = self::open( $path, 'wb' );

        try {
            $sql_bytes = self::write_gz(
                $fh,
                "-- Easy Site Backup SQL dump\n"
                . '-- Site: ' . home_url() . "\n"
                . '-- Date: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n"
                . 'SET NAMES ' . ( $wpdb->charset ? $wpdb->charset : 'utf8mb4' ) . ";\n"
                . "SET FOREIGN_KEY_CHECKS = 0;\n"
                . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n"
            );
        } finally {
            fclose( $fh );
        }

        clearstatcache( true, $path );

        $job->data['db']    = array(
            'tables'    => $tables,
            'table'     => 0,
            'started'   => false,
            'pk'        => null,
            'key'       => null,
            'offset'    => 0,
            'bytes'     => filesize( $path ),
            'sql_bytes' => $sql_bytes,
            // Частини дампу: номер поточної, нестиснений обсяг поточної й готових, скільки вже в архіві.
            'part'      => 1,
            'part_sql'  => $sql_bytes,
            'parts'     => array(),
            'archived'  => 0,
        );
        $job->data['phase'] = 'db';
        $job->log( sprintf( 'Таблиць для збереження: %d', count( $tables ) ) );
        $job->save();
    }

    private static function db_step( ESB_Job $job, $deadline ) {
        $state = &$job->data['db'];
        $path  = self::dump_path( $job, $state['part'] );
        $count = count( $state['tables'] );
        $limit = self::dump_part_bytes();

        self::truncate( $path, $state['bytes'] );
        $fh = self::open( $path, 'ab' );

        try {
            // Порція — цілі інструкції INSERT, тож частина завжди закінчується на межі інструкції.
            while ( $state['table'] < $count && $state['part_sql'] < $limit && microtime( true ) < $deadline ) {
                $before = $state['sql_bytes'];
                self::dump_chunk( $fh, $state );
                $state['part_sql'] += $state['sql_bytes'] - $before;
            }

            if ( $state['table'] >= $count ) {
                $written             = self::write_gz( $fh, "SET FOREIGN_KEY_CHECKS = 1;\n" );
                $state['sql_bytes'] += $written;
                $state['part_sql']  += $written;
            }
        } finally {
            fclose( $fh );
        }

        clearstatcache( true, $path );
        $state['bytes'] = filesize( $path );

        // Частина заповнена або дамп закінчено — закриваємо її; далі пишемо в новий файл.
        if ( $state['table'] >= $count || $state['part_sql'] >= $limit ) {
            $state['parts'][] = $state['part_sql'];

            if ( $state['table'] < $count ) {
                $state['part']++;
                $state['part_sql'] = 0;
                $state['bytes']    = 0;
            }
        }

        if ( $state['table'] >= $count ) {
            $job->log( sprintf( 'Базу даних збережено: %s, частин: %d', size_format( $state['sql_bytes'], 1 ), count( $state['parts'] ) ) );
            $job->data['phase'] = 'archive_db';
        }

        $job->save();
    }

    private static function dump_path( ESB_Job $job, $part ) {
        return $job->path( sprintf( 'database-%03d.sql.gz', $part ) );
    }

    /**
     * Зберігає наступну порцію рядків поточної таблиці.
     */
    private static function dump_chunk( $fh, array &$state ) {
        global $wpdb;

        $table  = $state['tables'][ $state['table'] ];
        $quoted = self::quote( $table );

        // phpcs:disable WordPress.DB.PreparedSQL -- імена таблиць і колонок екрановано в quote(), ключ перевірено регуляркою.
        if ( ! $state['started'] ) {
            $create = $wpdb->get_row( "SHOW CREATE TABLE {$quoted}", ARRAY_N );
            if ( empty( $create[1] ) ) {
                /* translators: %s: назва таблиці */
                throw new RuntimeException( sprintf( __( 'Не вдалося прочитати структуру таблиці %s.', 'easy-site-backup' ), $table ) );
            }

            $state['sql_bytes'] += self::write_gz( $fh, "DROP TABLE IF EXISTS {$quoted};\n{$create[1]};\n\n" );

            $state['started'] = true;
            $state['pk']      = self::numeric_primary_key( $table );
            $state['order']   = $state['pk'] ? array() : self::order_columns( $table );
            $state['key']     = null;
            $state['offset']  = 0;
        }

        // Пагінація за первинним ключем швидша за OFFSET і не пропускає рядків на великих таблицях.
        if ( $state['pk'] ) {
            $pk    = self::quote( $state['pk'] );
            $where = '';

            if ( null !== $state['key'] ) {
                if ( ! preg_match( '/^-?\d+$/', $state['key'] ) ) {
                    throw new RuntimeException( __( 'Некоректне значення первинного ключа.', 'easy-site-backup' ) );
                }
                $where = " WHERE {$pk} > {$state['key']}";
            }

            $sql = "SELECT * FROM {$quoted}{$where} ORDER BY {$pk} LIMIT " . self::ROWS_PER_QUERY;
        } else {
            // Без ORDER BY порядок рядків між запитами не гарантовано — порції могли б перекриватися
            // чи пропускати рядки (див. order_columns()).
            $order = empty( $state['order'] ) ? '' : ' ORDER BY ' . implode( ', ', array_map( array( __CLASS__, 'quote' ), $state['order'] ) );
            $sql   = sprintf( 'SELECT * FROM %s%s LIMIT %d, %d', $quoted, $order, $state['offset'], self::ROWS_PER_QUERY );
        }

        $rows = (array) $wpdb->get_results( $sql, ARRAY_A );
        // phpcs:enable

        if ( $wpdb->last_error ) {
            /* translators: 1: назва таблиці, 2: текст помилки */
            throw new RuntimeException( sprintf( __( 'Помилка читання таблиці %1$s: %2$s', 'easy-site-backup' ), $table, $wpdb->last_error ) );
        }

        if ( $rows ) {
            $values = array();
            foreach ( $rows as $row ) {
                $values[] = '(' . implode( ',', array_map( array( __CLASS__, 'sql_value' ), array_values( $row ) ) ) . ')';
            }

            $state['sql_bytes'] += self::write_gz( $fh, "INSERT INTO {$quoted} VALUES\n" . implode( ",\n", $values ) . ";\n" );

            $last             = end( $rows );
            $state['key']     = $state['pk'] ? (string) $last[ $state['pk'] ] : null;
            $state['offset'] += count( $rows );
        }

        if ( count( $rows ) < self::ROWS_PER_QUERY ) {
            $state['sql_bytes'] += self::write_gz( $fh, "\n" );
            $state['table']++;
            $state['started'] = false;
        }
    }

    /**
     * @return string|null Назва первинного ключа, якщо він один і цілочисельний.
     */
    private static function numeric_primary_key( $table ) {
        global $wpdb;

        $quoted = self::quote( $table );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $keys = $wpdb->get_results( "SHOW KEYS FROM {$quoted} WHERE Key_name = 'PRIMARY'", ARRAY_A );
        if ( 1 !== count( (array) $keys ) ) {
            return null;
        }

        $column = $keys[0]['Column_name'];
        $type   = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$quoted} LIKE %s", $wpdb->esc_like( $column ) ), 1 );
        // phpcs:enable

        return preg_match( '/^(tiny|small|medium|big)?int\b/i', (string) $type ) ? $column : null;
    }

    /**
     * Порядок рядків для пагінації через OFFSET: первинний ключ або інший унікальний ключ без NULL.
     *
     * Без такого ключа — усі колонки: однакові рядки взаємозамінні, тож порядок однозначний. Відоме
     * обмеження: довгі TEXT/BLOB, що збігаються в перших max_sort_length (1024) байтах, MySQL вважає
     * рівними, і їхній порядок між порціями може змінитися. До того ж кожна порція сортує всю таблицю.
     * Повне рішення — інший спосіб читання таблиці; поки таблиці зовсім без унікального ключа рідкісні
     * й зазвичай невеликі (журнали плагінів).
     *
     * @return string[]
     */
    private static function order_columns( $table ) {
        global $wpdb;

        $quoted = self::quote( $table );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- назву таблиці екрановано в quote().
        $rows = (array) $wpdb->get_results( "SHOW KEYS FROM {$quoted}", ARRAY_A );
        $keys = array();

        foreach ( $rows as $row ) {
            // У стовпці Null — «YES», якщо колонка допускає NULL: тоді ключ не робить рядки унікальними.
            if ( '0' !== (string) $row['Non_unique'] || '' !== (string) $row['Null'] ) {
                $keys[ $row['Key_name'] ] = false;
            } elseif ( ! isset( $keys[ $row['Key_name'] ] ) || false !== $keys[ $row['Key_name'] ] ) {
                $keys[ $row['Key_name'] ][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
            }
        }

        $keys = array_filter( $keys );
        if ( ! $keys ) {
            return (array) $wpdb->get_col( "SHOW COLUMNS FROM {$quoted}" );
        }
        // phpcs:enable

        $columns = isset( $keys['PRIMARY'] ) ? $keys['PRIMARY'] : reset( $keys );
        ksort( $columns );

        return array_values( $columns );
    }

    private static function quote( $name ) {
        return '`' . str_replace( '`', '``', $name ) . '`';
    }

    private static function sql_value( $value ) {
        global $wpdb;

        if ( null === $value ) {
            return 'NULL';
        }

        // esc_sql() замінює % на плейсхолдер, тому повертаємо його назад.
        return "'" . $wpdb->remove_placeholder_escape( esc_sql( $value ) ) . "'";
    }

    /**
     * Кожен запис — окремий gzip-блок: склеєні блоки є коректним .gz-файлом,
     * тож дамп можна дописувати між кроками.
     *
     * @return int Розмір нестиснених даних.
     */
    private static function write_gz( $fh, $data ) {
        $gz = gzencode( $data, 6 );

        if ( false === $gz ) {
            throw new RuntimeException( __( 'Не вдалося стиснути дамп бази даних.', 'easy-site-backup' ) );
        }

        if ( strlen( $gz ) !== fwrite( $fh, $gz ) ) {
            throw new RuntimeException( __( 'Не вдалося записати дамп бази даних. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
        }

        return strlen( $data );
    }

    /**
     * Переносить у архів наступну частину дампу як database-NNN.sql — по одній за виклик.
     */
    private static function archive_db( ESB_Job $job ) {
        $state  = &$job->data['db'];
        $part   = $state['archived'] + 1;
        $path   = self::dump_path( $job, $part );
        $writer = self::writer( $job );
        $src    = self::open( 'compress.zlib://' . $path, 'rb' );

        try {
            if ( ! $writer->add_stream( $src, sprintf( 'database-%03d.sql', $part ), true, $state['parts'][ $part - 1 ], time() ) ) {
                throw new RuntimeException( __( 'Не вдалося прочитати дамп бази даних.', 'easy-site-backup' ) );
            }

            $job->data['zip'] = $writer->state();
        } finally {
            fclose( $src );
            $writer->close();
        }

        $state['archived'] = $part;
        if ( $part >= count( $state['parts'] ) ) {
            $job->data['phase'] = 'db' === $job->data['type'] ? 'finalize' : 'files_scan';
        }
        $job->save();

        // Видаляємо лише після збереження стану: якщо крок переб'ють раніше, повтор знову прочитає частину.
        @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    /*
     * Файли.
     */

    private static function site_root() {
        return untrailingslashit( wp_normalize_path( ABSPATH ) );
    }

    /**
     * Складає список файлів сайту, які потрібно заархівувати.
     */
    private static function files_scan( ESB_Job $job ) {
        $root    = self::site_root();
        // Сховище і тека з вказівником на нього (якщо сховище поза публічною текою) — не в копію.
        $exclude = array( trailingslashit( ESB_Storage::dir() ), trailingslashit( ESB_Storage::uploads_dir() ) );

        foreach ( self::EXCLUDE_DIRS as $dir ) {
            $exclude[] = $root . '/' . $dir . '/';
        }

        $filter = function ( SplFileInfo $file ) use ( $exclude ) {
            // Символьні посилання пропускаємо, щоб не зациклитися і не вийти за межі сайту.
            if ( $file->isLink() ) {
                return false;
            }

            if ( $file->isDir() ) {
                return $file->isReadable() && ! in_array( trailingslashit( wp_normalize_path( $file->getPathname() ) ), $exclude, true );
            }

            return true;
        };

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
                $filter
            )
        );

        $fh    = self::open( $job->path( 'filelist.txt' ), 'wb' );
        $count = 0;
        $bytes = 0;

        try {
            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() || ! $file->isReadable() ) {
                    continue;
                }

                $relative = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $root ) ), '/' );
                if ( false !== strpos( $relative, "\n" ) ) {
                    continue;
                }

                if ( false === fwrite( $fh, $relative . "\n" ) ) {
                    throw new RuntimeException( __( 'Не вдалося записати список файлів. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
                }

                $count++;
                $bytes += $file->getSize();
            }
        } finally {
            fclose( $fh );
        }

        $job->data['files'] = array(
            'total_files' => $count,
            'total_bytes' => $bytes,
            'done_files'  => 0,
            'done_bytes'  => 0,
            'skipped'     => 0,
            'offset'      => 0,
        );
        $job->data['phase'] = 'files_zip';
        $job->log( sprintf( 'Знайдено файлів: %d (%s)', $count, size_format( $bytes, 1 ) ) );
        $job->save();

        // Оцінка зверху: медіа майже не стискаються.
        ESB_Storage::require_space( $bytes + ESB_Storage::SPACE_MARGIN, __( 'архіву файлів', 'easy-site-backup' ) );
    }

    /**
     * Дописує до архіву файли зі списку, поки не вичерпається час кроку.
     */
    private static function files_step( ESB_Job $job, $deadline ) {
        $state  = &$job->data['files'];
        $root   = self::site_root();
        $writer = self::writer( $job );
        $list   = null;

        try {
            $list = self::open( $job->path( 'filelist.txt' ), 'rb' );
            fseek( $list, $state['offset'] );

            do {
                // Великий файл, який копіюється частинами, — спершу дописуємо його.
                if ( ! empty( $state['large'] ) ) {
                    self::continue_large( $job, $writer, $deadline );
                    continue;
                }

                $line = fgets( $list );
                if ( false === $line ) {
                    $job->log( sprintf( 'Файли додано до архіву: %d, пропущено: %d', $state['done_files'], $state['skipped'] ) );
                    $job->data['phase'] = 'finalize';
                    break;
                }

                $state['offset'] = ftell( $list );
                $relative        = rtrim( $line, "\n" );
                $path            = $root . '/' . $relative;

                // Файл міг зникнути після сканування (наприклад, кеш).
                if ( '' === $relative || ! is_file( $path ) ) {
                    continue;
                }

                $size = (int) filesize( $path );

                // CRC частин поєднується в 64-бітній арифметиці; на 32-бітному PHP — як раніше, цілком.
                if ( $size >= self::large_file_bytes() && PHP_INT_SIZE >= 8 ) {
                    $state['large'] = array(
                        'relative' => $relative,
                        'entry'    => $writer->begin_large( 'files/' . $relative, $size, (int) filemtime( $path ) ),
                    );
                    continue;
                }

                $compress = $size < self::STORE_LARGER_THAN
                    && ! in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), self::STORE_EXTENSIONS, true );

                if ( ! $writer->add_file( $path, 'files/' . $relative, $compress ) ) {
                    $state['skipped']++;
                    $job->log( 'Пропущено файл, який не вдалося прочитати: ' . $relative );
                    continue;
                }

                $state['done_files']++;
                $state['done_bytes'] += $size;
            } while ( microtime( true ) < $deadline );

            $job->data['zip'] = $writer->state();
        } finally {
            if ( $list ) {
                fclose( $list );
            }
            $writer->close();
        }

        $job->save();
    }

    /**
     * Дописує частину великого файлу до дедлайну; коли файл скінчився — завершує запис.
     */
    private static function continue_large( ESB_Job $job, ESB_Zip_Writer $writer, $deadline ) {
        $state  = &$job->data['files'];
        $large  = &$state['large'];
        $before = $large['entry']['done'];
        $result = $writer->continue_large( $large['entry'], self::site_root() . '/' . $large['relative'], $deadline );

        if ( null === $result ) {
            // Файл зник або перестав читатися посеред копіювання.
            $writer->abort_large( $large['entry'] );
            $state['skipped']++;
            $state['done_bytes'] += $large['entry']['size'] - $before;
            $job->log( 'Пропущено файл, який не вдалося прочитати: ' . $large['relative'] );
            $state['large'] = null;
            return;
        }

        $state['done_bytes'] += $large['entry']['done'] - $before;

        if ( $result ) {
            $writer->end_large( $large['entry'] );
            $state['done_files']++;
            $state['large'] = null;
        }
    }

    /*
     * Спільне.
     */

    private static function finalize( ESB_Job $job ) {
        global $wpdb, $wp_version;

        $manifest = array(
            'plugin_version' => ESB_VERSION,
            'created'        => gmdate( 'c', $job->data['created'] ),
            'type'           => $job->data['type'],
            'site_url'       => site_url(),
            'home_url'       => home_url(),
            'wp_version'     => $wp_version,
            'table_prefix'   => $wpdb->base_prefix,
            'charset'        => $wpdb->charset,
            'tables'         => isset( $job->data['db'] ) ? $job->data['db']['tables'] : array(),
            'db_parts'       => isset( $job->data['db'] ) ? count( $job->data['db']['parts'] ) : 0,
            'files'          => isset( $job->data['files'] ) ? $job->data['files']['done_files'] : 0,
            'skipped_files'  => isset( $job->data['files'] ) ? $job->data['files']['skipped'] : 0,
        );

        $writer = self::writer( $job );

        try {
            $writer->add_string( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
            $writer->finish();
        } finally {
            $writer->close();
        }

        clearstatcache( true, $job->path( ESB_Storage::ARCHIVE ) );

        $job->data['size']     = filesize( $job->path( ESB_Storage::ARCHIVE ) );
        $job->data['status']   = 'done';
        $job->data['finished'] = time();
        $job->log( 'Готово. Розмір архіву: ' . size_format( $job->size(), 1 ) );
        $job->save();

        // Тимчасові файли — лише після збереження стану: якщо крок переб'ють раніше, повтор допише
        // центральний каталог наново, а без archive.cd він був би зіпсований. Якщо переб'ють після,
        // їх прибере active_job().
        self::remove_temp_files( $job );

        delete_option( self::ACTIVE_OPTION );

        do_action( 'esb_backup_finished', $job );
    }

    private static function writer( ESB_Job $job ) {
        return new ESB_Zip_Writer( $job->path( ESB_Storage::ARCHIVE ), $job->path( 'archive.cd' ), $job->data['zip'] );
    }

    /**
     * Видаляє проміжні файли: дамп, список файлів і центральний каталог архіву.
     */
    private static function remove_temp_files( ESB_Job $job ) {
        $names = array_merge( (array) glob( $job->path( 'database-*.sql.gz' ) ), array( $job->path( 'filelist.txt' ), $job->path( 'archive.cd' ) ) );

        foreach ( $names as $path ) {
            @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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

    /**
     * Обрізає файл до розміру після останнього успішного кроку, щоб прибрати дані перерваного кроку.
     */
    private static function truncate( $path, $size ) {
        clearstatcache( true, $path );

        if ( ! file_exists( $path ) || filesize( $path ) <= $size ) {
            return;
        }

        $fh = self::open( $path, 'r+b' );
        $ok = ftruncate( $fh, $size );
        fclose( $fh );

        if ( ! $ok ) {
            /* translators: %s: назва файлу */
            throw new RuntimeException( sprintf( __( 'Не вдалося відновити файл %s після перерваного кроку.', 'easy-site-backup' ), basename( $path ) ) );
        }
    }
}
