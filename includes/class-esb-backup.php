<?php
/**
 * Створення резервних копій: дамп бази даних і ZIP-архів файлів.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Backup {

    const TYPES = array( 'full', 'db', 'files' );

    /**
     * Скільки рядків читати з таблиці за один запит, щоб не впертися в ліміт пам'яті.
     */
    const ROWS_PER_QUERY = 500;

    /**
     * @param string $type full | db | files.
     * @return string|WP_Error Ім'я створеного архіву або помилка.
     */
    public static function create( $type ) {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            return new WP_Error( 'esb_type', __( 'Невідомий тип резервної копії.', 'easy-site-backup' ) );
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'esb_zip', __( 'На сервері немає PHP-розширення zip (ZipArchive).', 'easy-site-backup' ) );
        }

        $dir = ESB_Storage::ensure_dir();
        if ( is_wp_error( $dir ) ) {
            return $dir;
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        wp_raise_memory_limit( 'admin' );

        $zip_path = $dir . '/' . ESB_Storage::new_filename( $type );
        $sql_path = '';
        $zip      = new ZipArchive();

        if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
            return new WP_Error( 'esb_zip_open', __( 'Не вдалося створити ZIP-архів.', 'easy-site-backup' ) );
        }

        $closed = false;

        try {
            if ( 'files' !== $type ) {
                $sql_path = $dir . '/esb-tmp-' . wp_generate_password( 16, false ) . '.sql';
                self::dump_database( $sql_path );

                if ( ! $zip->addFile( $sql_path, 'database.sql' ) ) {
                    throw new RuntimeException( __( 'Не вдалося додати дамп бази даних до архіву.', 'easy-site-backup' ) );
                }
            }

            if ( 'db' !== $type ) {
                self::add_site_files( $zip, $dir );
            }

            // Фактичний запис архіву відбувається саме тут.
            $closed = true;
            if ( ! $zip->close() ) {
                throw new RuntimeException( __( 'Не вдалося записати архів. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
            }
        } catch ( Exception $e ) {
            if ( ! $closed ) {
                $zip->unchangeAll();
                $zip->close();
            }
            if ( file_exists( $zip_path ) ) {
                unlink( $zip_path );
            }

            return new WP_Error( 'esb_backup', $e->getMessage() );
        } finally {
            if ( $sql_path && file_exists( $sql_path ) ) {
                unlink( $sql_path );
            }
        }

        return basename( $zip_path );
    }

    /**
     * Пише SQL-дамп усіх таблиць сайту (з префіксом WordPress) у файл.
     */
    private static function dump_database( $path ) {
        global $wpdb;

        $fh = fopen( $path, 'wb' );
        if ( ! $fh ) {
            throw new RuntimeException( __( 'Не вдалося створити тимчасовий файл дампу бази даних.', 'easy-site-backup' ) );
        }

        try {
            self::write(
                $fh,
                "-- Easy Site Backup SQL dump\n"
                . '-- Site: ' . home_url() . "\n"
                . '-- Date: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n"
                . 'SET NAMES ' . ( $wpdb->charset ? $wpdb->charset : 'utf8mb4' ) . ";\n"
                . "SET FOREIGN_KEY_CHECKS = 0;\n"
                . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n"
            );

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

            foreach ( $tables as $table ) {
                self::dump_table( $fh, $table );
            }

            self::write( $fh, "SET FOREIGN_KEY_CHECKS = 1;\n" );
        } finally {
            fclose( $fh );
        }
    }

    private static function dump_table( $fh, $table ) {
        global $wpdb;

        $quoted = '`' . str_replace( '`', '``', $table ) . '`';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ім'я таблиці екрановане вище.
        $create = $wpdb->get_row( "SHOW CREATE TABLE {$quoted}", ARRAY_N );
        if ( empty( $create[1] ) ) {
            /* translators: %s: назва таблиці */
            throw new RuntimeException( sprintf( __( 'Не вдалося прочитати структуру таблиці %s.', 'easy-site-backup' ), $table ) );
        }

        self::write( $fh, "DROP TABLE IF EXISTS {$quoted};\n{$create[1]};\n\n" );

        for ( $offset = 0; ; $offset += self::ROWS_PER_QUERY ) {
            $rows = $wpdb->get_results(
                sprintf( 'SELECT * FROM %s LIMIT %d, %d', $quoted, $offset, self::ROWS_PER_QUERY ),
                ARRAY_N
            );
            // phpcs:enable

            if ( $wpdb->last_error ) {
                /* translators: 1: назва таблиці, 2: текст помилки */
                throw new RuntimeException( sprintf( __( 'Помилка читання таблиці %1$s: %2$s', 'easy-site-backup' ), $table, $wpdb->last_error ) );
            }

            if ( empty( $rows ) ) {
                break;
            }

            $values = array();
            foreach ( $rows as $row ) {
                $values[] = '(' . implode( ',', array_map( array( __CLASS__, 'sql_value' ), $row ) ) . ')';
            }

            self::write( $fh, "INSERT INTO {$quoted} VALUES\n" . implode( ",\n", $values ) . ";\n" );

            if ( count( $rows ) < self::ROWS_PER_QUERY ) {
                break;
            }
        }

        self::write( $fh, "\n" );
    }

    private static function sql_value( $value ) {
        global $wpdb;

        if ( null === $value ) {
            return 'NULL';
        }

        // esc_sql() замінює % на плейсхолдер, тому повертаємо його назад.
        return "'" . $wpdb->remove_placeholder_escape( esc_sql( $value ) ) . "'";
    }

    private static function write( $fh, $data ) {
        if ( false === fwrite( $fh, $data ) ) {
            throw new RuntimeException( __( 'Не вдалося записати дамп бази даних. Можливо, на диску закінчилося місце.', 'easy-site-backup' ) );
        }
    }

    /**
     * Додає до архіву всі файли сайту, крім теки з резервними копіями.
     */
    private static function add_site_files( ZipArchive $zip, $backup_dir ) {
        $root    = untrailingslashit( wp_normalize_path( ABSPATH ) );
        $exclude = trailingslashit( wp_normalize_path( $backup_dir ) );

        $filter = function ( SplFileInfo $file ) use ( $exclude ) {
            // Символьні посилання пропускаємо, щоб не зациклитися і не вийти за межі сайту.
            if ( $file->isLink() ) {
                return false;
            }
            if ( $file->isDir() && ! $file->isReadable() ) {
                return false;
            }

            return trailingslashit( wp_normalize_path( $file->getPathname() ) ) !== $exclude;
        };

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
                $filter
            )
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() || ! $file->isReadable() ) {
                continue;
            }

            $path  = wp_normalize_path( $file->getPathname() );
            $local = 'files/' . ltrim( substr( $path, strlen( $root ) ), '/' );

            if ( ! $zip->addFile( $file->getPathname(), $local ) ) {
                /* translators: %s: шлях до файлу */
                throw new RuntimeException( sprintf( __( 'Не вдалося додати файл до архіву: %s', 'easy-site-backup' ), $path ) );
            }
        }
    }
}
