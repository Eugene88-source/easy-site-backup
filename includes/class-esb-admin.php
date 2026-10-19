<?php
/**
 * Сторінка плагіна в адмінці та обробники дій.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Admin {

    const PAGE = 'easy-site-backup';

    private static $hook = '';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

        add_action( 'wp_ajax_esb_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_esb_step', array( __CLASS__, 'ajax_step' ) );
        add_action( 'wp_ajax_esb_cancel', array( __CLASS__, 'ajax_cancel' ) );

        // Запуск без JavaScript: далі кроки виконує WP-Cron.
        add_action( 'admin_post_esb_create', array( __CLASS__, 'handle_create' ) );
        add_action( 'admin_post_esb_download', array( __CLASS__, 'handle_download' ) );
        add_action( 'admin_post_esb_delete', array( __CLASS__, 'handle_delete' ) );
    }

    public static function add_page() {
        self::$hook = add_management_page(
            __( 'Easy Site Backup', 'easy-site-backup' ),
            __( 'Easy Site Backup', 'easy-site-backup' ),
            'manage_options',
            self::PAGE,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function enqueue( $hook ) {
        if ( $hook !== self::$hook ) {
            return;
        }

        $active = ESB_Backup::active_job();

        wp_enqueue_style( 'esb-admin', plugins_url( 'assets/admin.css', ESB_FILE ), array(), ESB_VERSION );
        wp_enqueue_script( 'esb-admin', plugins_url( 'assets/admin.js', ESB_FILE ), array(), ESB_VERSION, true );
        wp_localize_script(
            'esb-admin',
            'esbAdmin',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'esb_ajax' ),
                'activeJob' => $active ? ESB_Backup::status( $active ) : null,
                'i18n'      => array(
                    'error'         => __( 'Сталася помилка.', 'easy-site-backup' ),
                    'retry'         => __( 'Зв\'язок із сервером перервався, повторюю спробу…', 'easy-site-backup' ),
                    'cancelConfirm' => __( 'Скасувати резервне копіювання? Уже створені частини буде видалено.', 'easy-site-backup' ),
                    'cancelling'    => __( 'Скасування…', 'easy-site-backup' ),
                ),
            )
        );
    }

    private static function type_labels() {
        return array(
            'full'  => __( 'База даних + файли', 'easy-site-backup' ),
            'db'    => __( 'Тільки база даних', 'easy-site-backup' ),
            'files' => __( 'Тільки файли', 'easy-site-backup' ),
        );
    }

    private static function status_label( ESB_Job $job ) {
        switch ( $job->data['status'] ) {
            case 'done':
                return __( 'Готово', 'easy-site-backup' );
            case 'failed':
                return __( 'Помилка', 'easy-site-backup' );
            default:
                return __( 'Перервано', 'easy-site-backup' );
        }
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $labels = self::type_labels();
        $active = ESB_Backup::active_job();
        $jobs   = array_filter(
            ESB_Storage::list_jobs(),
            function ( $job ) use ( $active ) {
                return ! $active || $job->id !== $active->id;
            }
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Easy Site Backup', 'easy-site-backup' ); ?></h1>

            <?php self::render_notice(); ?>

            <h2><?php esc_html_e( 'Нова резервна копія', 'easy-site-backup' ); ?></h2>

            <form id="esb-start-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo $active ? 'hidden' : ''; ?>>
                <input type="hidden" name="action" value="esb_create">
                <?php wp_nonce_field( 'esb_create' ); ?>

                <fieldset>
                    <?php foreach ( $labels as $type => $label ) : ?>
                        <label class="esb-type">
                            <input type="radio" name="type" value="<?php echo esc_attr( $type ); ?>" <?php checked( 'full', $type ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <?php submit_button( __( 'Створити бекап', 'easy-site-backup' ) ); ?>
            </form>

            <div id="esb-progress" class="esb-progress" <?php echo $active ? '' : 'hidden'; ?>>
                <div class="esb-progress-track"><div class="esb-progress-bar"></div></div>
                <p class="esb-progress-message"></p>
                <p class="description">
                    <?php esc_html_e( 'Поки сторінка відкрита, копія створюється швидше. Якщо її закрити, процес продовжиться у фоні через WP-Cron, але повільніше.', 'easy-site-backup' ); ?>
                </p>
                <button type="button" id="esb-cancel" class="button"><?php esc_html_e( 'Скасувати', 'easy-site-backup' ); ?></button>
            </div>

            <h2><?php esc_html_e( 'Наявні резервні копії', 'easy-site-backup' ); ?></h2>

            <?php if ( empty( $jobs ) ) : ?>
                <p><?php esc_html_e( 'Резервних копій ще немає.', 'easy-site-backup' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Дата', 'easy-site-backup' ); ?></th>
                            <th><?php esc_html_e( 'Тип', 'easy-site-backup' ); ?></th>
                            <th><?php esc_html_e( 'Статус', 'easy-site-backup' ); ?></th>
                            <th><?php esc_html_e( 'Розмір', 'easy-site-backup' ); ?></th>
                            <th><?php esc_html_e( 'Файли', 'easy-site-backup' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $jobs as $job ) : ?>
                            <tr>
                                <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $job->data['created'] ) ); ?></td>
                                <td><?php echo esc_html( isset( $labels[ $job->data['type'] ] ) ? $labels[ $job->data['type'] ] : $job->data['type'] ); ?></td>
                                <td>
                                    <?php echo esc_html( self::status_label( $job ) ); ?>
                                    <?php if ( $job->data['error'] ) : ?>
                                        <br><span class="esb-error-text"><?php echo esc_html( $job->data['error'] ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo 'done' === $job->data['status'] ? esc_html( size_format( $job->size(), 1 ) ) : '—'; ?></td>
                                <td class="esb-parts">
                                    <?php foreach ( $job->data['parts'] as $part ) : ?>
                                        <a href="<?php echo esc_url( self::download_url( $job->id, $part['name'] ) ); ?>"><?php echo esc_html( $part['name'] ); ?></a>
                                        <span class="description">(<?php echo esc_html( size_format( $part['size'], 1 ) ); ?>)</span><br>
                                    <?php endforeach; ?>
                                    <?php if ( file_exists( $job->path( 'log.txt' ) ) ) : ?>
                                        <a href="<?php echo esc_url( self::download_url( $job->id, 'log.txt' ) ); ?>"><?php esc_html_e( 'Лог', 'easy-site-backup' ); ?></a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                        onsubmit="return confirm('<?php echo esc_js( __( 'Видалити цю резервну копію?', 'easy-site-backup' ) ); ?>');">
                                        <input type="hidden" name="action" value="esb_delete">
                                        <input type="hidden" name="id" value="<?php echo esc_attr( $job->id ); ?>">
                                        <?php wp_nonce_field( 'esb_delete_' . $job->id ); ?>
                                        <button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Видалити', 'easy-site-backup' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="description esb-storage-note">
                <?php
                printf(
                    /* translators: %s: шлях до теки */
                    esc_html__( 'Копії зберігаються в %s. На Apache прямий доступ заборонено через .htaccess; на Nginx файли захищені лише випадковими назвами тек — бажано заборонити цю теку в конфігурації сервера.', 'easy-site-backup' ),
                    '<code>' . esc_html( ESB_Storage::dir() ) . '</code>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    private static function render_notice() {
        $notice = isset( $_GET['esb_notice'] ) ? sanitize_key( wp_unslash( $_GET['esb_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 'started' === $notice ) {
            $class   = 'notice-success';
            $message = __( 'Резервне копіювання запущено.', 'easy-site-backup' );
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

    private static function download_url( $id, $name ) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'esb_download',
                    'id'     => $id,
                    'file'   => $name,
                ),
                admin_url( 'admin-post.php' )
            ),
            'esb_download_' . $id
        );
    }

    /*
     * AJAX.
     */

    public static function ajax_start() {
        self::check_ajax();

        $type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'full'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- перевірено в check_ajax().
        $job  = ESB_Backup::start( $type );

        if ( is_wp_error( $job ) ) {
            wp_send_json_error( array( 'message' => $job->get_error_message() ) );
        }

        wp_send_json_success( ESB_Backup::status( $job ) );
    }

    public static function ajax_step() {
        self::check_ajax();

        $job = ESB_Backup::step( self::posted_id() );

        if ( is_wp_error( $job ) ) {
            wp_send_json_error( array( 'message' => $job->get_error_message() ) );
        }

        wp_send_json_success( ESB_Backup::status( $job ) );
    }

    public static function ajax_cancel() {
        self::check_ajax();

        ESB_Backup::cancel( self::posted_id() );

        wp_send_json_success();
    }

    private static function check_ajax() {
        if ( ! check_ajax_referer( 'esb_ajax', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'easy-site-backup' ) ), 403 );
        }
    }

    private static function posted_id() {
        return isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- перевіряється викликачем.
    }

    /*
     * admin-post.
     */

    public static function handle_create() {
        self::check_access( 'esb_create' );

        $type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'full';
        $job  = ESB_Backup::start( $type );

        if ( is_wp_error( $job ) ) {
            self::redirect_error( $job->get_error_message() );
        }

        self::redirect( 'started' );
    }

    public static function handle_download() {
        $id   = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
        $name = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
        self::check_access( 'esb_download_' . $id );

        $path = ESB_Storage::download_path( $id, $name );
        if ( ! $path || ! is_file( $path ) ) {
            wp_die( esc_html__( 'Файл не знайдено.', 'easy-site-backup' ), '', array( 'response' => 404 ) );
        }

        $types = array(
            'zip'  => 'application/zip',
            'gz'   => 'application/gzip',
            'json' => 'application/json',
            'txt'  => 'text/plain; charset=utf-8',
        );
        $ext   = pathinfo( $name, PATHINFO_EXTENSION );

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'Content-Type: ' . $types[ $ext ] );
        header( 'Content-Disposition: attachment; filename="esb-' . $id . '-' . $name . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'X-Content-Type-Options: nosniff' );

        readfile( $path );
        exit;
    }

    public static function handle_delete() {
        $id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
        self::check_access( 'esb_delete_' . $id );

        $active = ESB_Backup::active_job();
        if ( $active && $active->id === $id ) {
            self::redirect_error( __( 'Ця копія ще створюється — спочатку скасуйте її.', 'easy-site-backup' ) );
        }

        if ( ! ESB_Storage::delete_dir( $id ) ) {
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
