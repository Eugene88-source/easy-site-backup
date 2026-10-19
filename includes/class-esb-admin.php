<?php
/**
 * Сторінка плагіна в адмінці та обробники дій.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Admin {

    const PAGE = 'easy-site-backup';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_post_esb_create', array( __CLASS__, 'handle_create' ) );
        add_action( 'admin_post_esb_download', array( __CLASS__, 'handle_download' ) );
        add_action( 'admin_post_esb_delete', array( __CLASS__, 'handle_delete' ) );
    }

    public static function add_page() {
        add_management_page(
            __( 'Easy Site Backup', 'easy-site-backup' ),
            __( 'Easy Site Backup', 'easy-site-backup' ),
            'manage_options',
            self::PAGE,
            array( __CLASS__, 'render_page' )
        );
    }

    private static function type_labels() {
        return array(
            'full'  => __( 'База даних + файли', 'easy-site-backup' ),
            'db'    => __( 'Тільки база даних', 'easy-site-backup' ),
            'files' => __( 'Тільки файли', 'easy-site-backup' ),
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $labels  = self::type_labels();
        $backups = ESB_Storage::list_backups();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Easy Site Backup', 'easy-site-backup' ); ?></h1>

            <?php self::render_notice(); ?>

            <h2><?php esc_html_e( 'Нова резервна копія', 'easy-site-backup' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="esb_create">
                <?php wp_nonce_field( 'esb_create' ); ?>

                <fieldset>
                    <?php foreach ( $labels as $type => $label ) : ?>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="radio" name="type" value="<?php echo esc_attr( $type ); ?>" <?php checked( 'full', $type ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <p class="description">
                    <?php esc_html_e( 'Створення копії великого сайту може тривати кілька хвилин. Не закривайте сторінку.', 'easy-site-backup' ); ?>
                </p>

                <?php submit_button( __( 'Створити бекап', 'easy-site-backup' ) ); ?>
            </form>

            <h2><?php esc_html_e( 'Наявні резервні копії', 'easy-site-backup' ); ?></h2>

            <?php if ( empty( $backups ) ) : ?>
                <p><?php esc_html_e( 'Резервних копій ще немає.', 'easy-site-backup' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Дата', 'easy-site-backup' ); ?></th>
                            <th><?php esc_html_e( 'Тип', 'easy-site-backup' ); ?></th>
                            <th><?php esc_html_e( 'Розмір', 'easy-site-backup' ); ?></th>
                            <th><?php esc_html_e( 'Дії', 'easy-site-backup' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $backups as $backup ) : ?>
                            <tr>
                                <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['time'] ) ); ?></td>
                                <td><?php echo esc_html( $labels[ $backup['type'] ] ); ?></td>
                                <td><?php echo esc_html( size_format( $backup['size'], 1 ) ); ?></td>
                                <td>
                                    <a class="button" href="<?php echo esc_url( self::download_url( $backup['name'] ) ); ?>">
                                        <?php esc_html_e( 'Завантажити', 'easy-site-backup' ); ?>
                                    </a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
                                        onsubmit="return confirm('<?php echo esc_js( __( 'Видалити цю резервну копію?', 'easy-site-backup' ) ); ?>');">
                                        <input type="hidden" name="action" value="esb_delete">
                                        <input type="hidden" name="file" value="<?php echo esc_attr( $backup['name'] ); ?>">
                                        <?php wp_nonce_field( 'esb_delete_' . $backup['name'] ); ?>
                                        <button type="submit" class="button button-link-delete"><?php esc_html_e( 'Видалити', 'easy-site-backup' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="description" style="margin-top:12px;">
                <?php
                printf(
                    /* translators: %s: шлях до теки */
                    esc_html__( 'Копії зберігаються в %s. На Apache прямий доступ заборонено через .htaccess; на Nginx файли захищені лише випадковими іменами — бажано заборонити цю теку в конфігурації сервера.', 'easy-site-backup' ),
                    '<code>' . esc_html( ESB_Storage::dir() ) . '</code>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    private static function render_notice() {
        $notice = isset( $_GET['esb_notice'] ) ? sanitize_key( wp_unslash( $_GET['esb_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 'created' === $notice ) {
            $class   = 'notice-success';
            $message = __( 'Резервну копію створено.', 'easy-site-backup' );
        } elseif ( 'deleted' === $notice ) {
            $class   = 'notice-success';
            $message = __( 'Резервну копію видалено.', 'easy-site-backup' );
        } elseif ( 'error' === $notice ) {
            $key     = self::error_key();
            $class   = 'notice-error';
            $message = get_transient( $key );
            delete_transient( $key );

            if ( ! $message ) {
                $message = __( 'Сталася помилка.', 'easy-site-backup' );
            }
        } else {
            return;
        }

        printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    private static function download_url( $name ) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'esb_download',
                    'file'   => $name,
                ),
                admin_url( 'admin-post.php' )
            ),
            'esb_download_' . $name
        );
    }

    public static function handle_create() {
        self::check_access( 'esb_create' );

        $type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'full';
        $result = ESB_Backup::create( $type );

        if ( is_wp_error( $result ) ) {
            self::redirect_error( $result->get_error_message() );
        }

        self::redirect( 'created' );
    }

    public static function handle_download() {
        $name = isset( $_GET['file'] ) ? (string) wp_unslash( $_GET['file'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- перевіряється регуляркою в ESB_Storage.
        self::check_access( 'esb_download_' . $name );

        $path = ESB_Storage::path( $name );
        if ( ! $path || ! is_file( $path ) ) {
            wp_die( esc_html__( 'Резервну копію не знайдено.', 'easy-site-backup' ), '', array( 'response' => 404 ) );
        }

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'X-Content-Type-Options: nosniff' );

        readfile( $path );
        exit;
    }

    public static function handle_delete() {
        $name = isset( $_POST['file'] ) ? (string) wp_unslash( $_POST['file'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- перевіряється регуляркою в ESB_Storage.
        self::check_access( 'esb_delete_' . $name );

        $path = ESB_Storage::path( $name );
        if ( ! $path || ! is_file( $path ) || ! unlink( $path ) ) {
            self::redirect_error( __( 'Не вдалося видалити резервну копію.', 'easy-site-backup' ) );
        }

        self::redirect( 'deleted' );
    }

    private static function check_access( $nonce_action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Недостатньо прав.', 'easy-site-backup' ), '', array( 'response' => 403 ) );
        }

        check_admin_referer( $nonce_action );
    }

    private static function error_key() {
        return 'esb_error_' . get_current_user_id();
    }

    private static function redirect_error( $message ) {
        set_transient( self::error_key(), $message, MINUTE_IN_SECONDS );
        self::redirect( 'error' );
    }

    private static function redirect( $notice ) {
        wp_safe_redirect( add_query_arg( 'esb_notice', $notice, admin_url( 'tools.php?page=' . self::PAGE ) ) );
        exit;
    }
}
