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
 * Вміст архіву: database.sql, files/… (файли сайту відносно кореня WordPress), manifest.json.
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
     * Скільки разів поспіль крок може перерватися, перш ніж завдання вважається невдалим.
     */
    const MAX_ATTEMPTS = 3;

    const ROWS_PER_QUERY = 500;

    /**
     * Великі файли додаються без стиснення, щоб один файл не займав увесь крок.
     */
    const STORE_LARGER_THAN = 64 * MB_IN_BYTES;

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
     * @param string $type full | db | files.
     * @return ESB_Job|WP_Error
     */
    public static function start( $type ) {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            return new WP_Error( 'esb_type', __( 'Невідомий тип резервної копії.', 'easy-site-backup' ) );
        }

        if ( ! function_exists( 'deflate_init' ) ) {
            return new WP_Error( 'esb_zlib', __( 'На сервері немає PHP-розширення zlib.', 'easy-site-backup' ) );
        }

        if ( self::active_job() ) {
            return new WP_Error( 'esb_busy', __( 'Резервне копіювання вже виконується.', 'easy-site-backup' ) );
        }

        $job = ESB_Job::create( $type );
        if ( is_wp_error( $job ) ) {
            return $job;
        }

        $job->data['phase'] = 'files' === $type ? 'files_scan' : 'db_init';
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

        $job->log( 'Початок резервного копіювання, тип: ' . $type );
        update_option( self::ACTIVE_OPTION, $job->id, false );
        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK, array( $job->id ) );

        return $job;
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
                $message = __( 'Додавання бази даних до архіву…', 'easy-site-backup' );
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

        $deadline = microtime( true ) + self::STEP_SECONDS;

        try {
            // Лічильник скидається лише після успішного кроку, тож він рахує кроки, які вбив сервер.
            if ( ++$job->data['attempts'] > self::MAX_ATTEMPTS ) {
                throw new RuntimeException( __( 'Крок резервного копіювання кілька разів поспіль переривався — ймовірно, через ліміт часу або пам\'яті сервера.', 'easy-site-backup' ) );
            }
            $job->save();

            while ( $job->is_running() && microtime( true ) < $deadline && ! $job->cancel_requested() ) {
                self::run_phase( $job, $deadline );
            }

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

        $path = $job->path( 'database.sql.gz' );
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
        );
        $job->data['phase'] = 'db';
        $job->log( sprintf( 'Таблиць для збереження: %d', count( $tables ) ) );
        $job->save();
    }

    private static function db_step( ESB_Job $job, $deadline ) {
        $state = &$job->data['db'];
        $path  = $job->path( 'database.sql.gz' );
        $count = count( $state['tables'] );

        self::truncate( $path, $state['bytes'] );
        $fh = self::open( $path, 'ab' );

        try {
            while ( $state['table'] < $count && microtime( true ) < $deadline ) {
                self::dump_chunk( $fh, $state );
            }

            if ( $state['table'] >= $count ) {
                $state['sql_bytes'] += self::write_gz( $fh, "SET FOREIGN_KEY_CHECKS = 1;\n" );
            }
        } finally {
            fclose( $fh );
        }

        clearstatcache( true, $path );
        $state['bytes'] = filesize( $path );

        if ( $state['table'] >= $count ) {
            $job->log( 'Базу даних збережено: ' . size_format( $state['sql_bytes'], 1 ) );
            $job->data['phase'] = 'archive_db';
        }

        $job->save();
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
            $sql = sprintf( 'SELECT * FROM %s LIMIT %d, %d', $quoted, $state['offset'], self::ROWS_PER_QUERY );
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
     * Переносить готовий дамп у архів як database.sql.
     */
    private static function archive_db( ESB_Job $job ) {
        $writer = self::writer( $job );
        $src    = self::open( 'compress.zlib://' . $job->path( 'database.sql.gz' ), 'rb' );

        try {
            if ( ! $writer->add_stream( $src, 'database.sql', true, $job->data['db']['sql_bytes'], time() ) ) {
                throw new RuntimeException( __( 'Не вдалося прочитати дамп бази даних.', 'easy-site-backup' ) );
            }

            $job->data['zip'] = $writer->state();
        } finally {
            fclose( $src );
            $writer->close();
        }

        $job->data['phase'] = 'db' === $job->data['type'] ? 'finalize' : 'files_scan';
        $job->save();

        // Видаляємо лише після збереження стану: якщо крок переб'ють раніше, повтор знову прочитає дамп.
        @unlink( $job->path( 'database.sql.gz' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
        $exclude = array( trailingslashit( ESB_Storage::dir() ) );

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

            while ( microtime( true ) < $deadline ) {
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

                $size     = (int) filesize( $path );
                $compress = $size < self::STORE_LARGER_THAN
                    && ! in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), self::STORE_EXTENSIONS, true );

                if ( ! $writer->add_file( $path, 'files/' . $relative, $compress ) ) {
                    $state['skipped']++;
                    $job->log( 'Пропущено файл, який не вдалося прочитати: ' . $relative );
                    continue;
                }

                $state['done_files']++;
                $state['done_bytes'] += $size;
            }

            $job->data['zip'] = $writer->state();
        } finally {
            if ( $list ) {
                fclose( $list );
            }
            $writer->close();
        }

        $job->save();
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

        self::remove_temp_files( $job );
        clearstatcache( true, $job->path( ESB_Storage::ARCHIVE ) );

        $job->data['size']     = filesize( $job->path( ESB_Storage::ARCHIVE ) );
        $job->data['status']   = 'done';
        $job->data['finished'] = time();
        $job->log( 'Готово. Розмір архіву: ' . size_format( $job->size(), 1 ) );
        $job->save();

        delete_option( self::ACTIVE_OPTION );
    }

    private static function writer( ESB_Job $job ) {
        return new ESB_Zip_Writer( $job->path( ESB_Storage::ARCHIVE ), $job->path( 'archive.cd' ), $job->data['zip'] );
    }

    /**
     * Видаляє проміжні файли: дамп, список файлів і центральний каталог архіву.
     */
    private static function remove_temp_files( ESB_Job $job ) {
        foreach ( array( 'database.sql.gz', 'filelist.txt', 'archive.cd' ) as $name ) {
            @unlink( $job->path( $name ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
