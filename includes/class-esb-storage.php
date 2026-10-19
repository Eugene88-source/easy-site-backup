<?php
/**
 * Захищене сховище резервних копій.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Storage {

    const DIR_NAME = 'esb-backups';

    /**
     * Ім'я архіву: тип, дата (UTC) і випадковий суфікс, щоб файл неможливо було вгадати.
     */
    const FILE_PATTERN = '/^esb-(full|db|files)-(\d{8}-\d{6})-[A-Za-z0-9]{16}\.zip$/';

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

    public static function new_filename( $type ) {
        return sprintf( 'esb-%s-%s-%s.zip', $type, gmdate( 'Ymd-His' ), wp_generate_password( 16, false ) );
    }

    public static function is_valid_name( $name ) {
        return is_string( $name ) && 1 === preg_match( self::FILE_PATTERN, $name );
    }

    /**
     * Повертає повний шлях до архіву лише для коректного імені — захист від path traversal.
     *
     * @return string|false
     */
    public static function path( $name ) {
        return self::is_valid_name( $name ) ? self::dir() . '/' . $name : false;
    }

    /**
     * @return array[] Список архівів, новіші першими.
     */
    public static function list_backups() {
        $dir = self::dir();

        if ( ! is_dir( $dir ) ) {
            return array();
        }

        $items = array();

        foreach ( (array) scandir( $dir ) as $name ) {
            if ( ! preg_match( self::FILE_PATTERN, (string) $name, $matches ) ) {
                continue;
            }

            $path    = $dir . '/' . $name;
            $items[] = array(
                'name' => $name,
                'type' => $matches[1],
                'size' => filesize( $path ),
                'time' => filemtime( $path ),
            );
        }

        usort(
            $items,
            function ( $a, $b ) {
                return $b['time'] - $a['time'];
            }
        );

        return $items;
    }
}
