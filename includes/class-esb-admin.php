<?php
/**
 * Сторінка плагіна в адмінці та обробники дій.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Admin {

    const PAGE = 'easy-site-backup';

    /**
     * Дозволений HTML для повідомлень з датою.
     */
    const TIME_TAG = array(
        'time' => array(
            'class'    => true,
            'datetime' => true,
        ),
    );

    private static $hook = '';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

        add_action( 'wp_ajax_esb_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_esb_step', array( __CLASS__, 'ajax_step' ) );
        add_action( 'wp_ajax_esb_cancel', array( __CLASS__, 'ajax_cancel' ) );
        add_action( 'wp_ajax_esb_restore_start', array( __CLASS__, 'ajax_restore_start' ) );

        // Після заміни бази сесії користувача може вже не бути — кроки відновлення перевіряються токеном.
        add_action( 'wp_ajax_esb_restore_step', array( __CLASS__, 'ajax_restore_step' ) );
        add_action( 'wp_ajax_nopriv_esb_restore_step', array( __CLASS__, 'ajax_restore_step' ) );

        // Запуск без JavaScript: далі кроки виконує WP-Cron.
        add_action( 'admin_post_esb_create', array( __CLASS__, 'handle_create' ) );
        add_action( 'admin_post_esb_download', array( __CLASS__, 'handle_download' ) );
        add_action( 'admin_post_esb_delete', array( __CLASS__, 'handle_delete' ) );
        add_action( 'admin_post_esb_bulk_delete', array( __CLASS__, 'handle_bulk_delete' ) );
        add_action( 'admin_post_esb_save_schedule', array( __CLASS__, 'handle_save_schedule' ) );
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

        $active  = ESB_Backup::active_job();
        $restore = ESB_Restore::active();

        wp_enqueue_style( 'esb-admin', plugins_url( 'assets/admin.css', ESB_FILE ), array(), ESB_VERSION );
        wp_enqueue_script( 'esb-admin', plugins_url( 'assets/admin.js', ESB_FILE ), array(), ESB_VERSION, true );
        wp_localize_script(
            'esb-admin',
            'esbAdmin',
            array(
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'esb_ajax' ),
                'activeJob'     => $active ? ESB_Backup::status( $active ) : null,
                'activeRestore' => $restore ? ESB_Restore::status( $restore ) : null,
                'i18n'          => array(
                    'error'          => __( 'Сталася помилка.', 'easy-site-backup' ),
                    'retry'          => __( 'Зв\'язок із сервером перервався, повторюю спробу…', 'easy-site-backup' ),
                    'cancelConfirm'  => __( 'Скасувати резервне копіювання? Незавершений архів буде видалено.', 'easy-site-backup' ),
                    'cancelling'     => __( 'Скасування…', 'easy-site-backup' ),
                    /* translators: %d: кількість вибраних копій */
                    'bulkConfirm'    => __( 'Видалити вибрані резервні копії (%d)?', 'easy-site-backup' ),
                    /* translators: %s: дата копії */
                    'restoreConfirm' => __( "Відновити сайт із копії від %s?\n\nПоточні база даних і файли буде замінено вмістом копії. Файли й таблиці, яких немає в копії, залишаться. Скасувати відновлення буде неможливо.\n\nРадимо спершу створити свіжу резервну копію.", 'easy-site-backup' ),
                ),
            )
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

        $labels  = ESB_Backup::type_labels();
        $active  = ESB_Backup::active_job();
        $restore = ESB_Restore::active();
        $busy    = $active || $restore;
        $jobs    = array_filter(
            ESB_Storage::list_jobs(),
            function ( $job ) use ( $active ) {
                return ! $active || $job->id !== $active->id;
            }
        );
        ?>
        <div class="wrap esb-wrap">
            <div class="esb-header">
                <span class="dashicons dashicons-backup" aria-hidden="true"></span>
                <div>
                    <h1><?php esc_html_e( 'Easy Site Backup', 'easy-site-backup' ); ?></h1>
                    <p><?php esc_html_e( 'Резервні копії бази даних і файлів сайту', 'easy-site-backup' ); ?></p>
                </div>
            </div>
            <?php // WordPress переносить сповіщення інших плагінів під цю лінію, а не в шапку. ?>
            <hr class="wp-header-end">

            <?php self::render_notice(); ?>
            <?php self::render_restore_result(); ?>
            <?php self::render_timezone_hint(); ?>

            <div class="esb-card">
            <h2><?php esc_html_e( 'Нова резервна копія', 'easy-site-backup' ); ?></h2>

            <form id="esb-start-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" <?php echo $busy ? 'hidden' : ''; ?>>
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

            <div id="esb-progress" class="esb-progress" <?php echo $busy ? '' : 'hidden'; ?>>
                <div class="esb-progress-track"><div class="esb-progress-bar"></div></div>
                <p class="esb-progress-message"></p>
                <p class="description">
                    <?php esc_html_e( 'Поки сторінка відкрита, процес іде швидше. Якщо її закрити, він продовжиться у фоні через WP-Cron, але повільніше.', 'easy-site-backup' ); ?>
                </p>
                <button type="button" id="esb-cancel" class="button"><?php esc_html_e( 'Скасувати', 'easy-site-backup' ); ?></button>
            </div>
            </div>

            <div class="esb-card">
            <h2><?php esc_html_e( 'Наявні резервні копії', 'easy-site-backup' ); ?></h2>

            <?php if ( empty( $jobs ) ) : ?>
                <p><?php esc_html_e( 'Резервних копій ще немає.', 'easy-site-backup' ); ?></p>
            <?php else : ?>
                <form id="esb-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="esb_bulk_delete">
                    <?php wp_nonce_field( 'esb_bulk_delete' ); ?>

                    <div class="tablenav top">
                        <button type="submit" id="esb-bulk-delete" class="button" disabled>
                            <?php esc_html_e( 'Видалити вибрані', 'easy-site-backup' ); ?>
                        </button>
                    </div>

                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <td class="manage-column check-column">
                                    <label class="screen-reader-text" for="esb-select-all"><?php esc_html_e( 'Вибрати всі', 'easy-site-backup' ); ?></label>
                                    <input type="checkbox" id="esb-select-all">
                                </td>
                                <th><?php esc_html_e( 'Дата', 'easy-site-backup' ); ?></th>
                                <th><?php esc_html_e( 'Тип', 'easy-site-backup' ); ?></th>
                                <th><?php esc_html_e( 'Статус', 'easy-site-backup' ); ?></th>
                                <th><?php esc_html_e( 'Розмір', 'easy-site-backup' ); ?></th>
                                <th><?php esc_html_e( 'Дії', 'easy-site-backup' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $jobs as $job ) : ?>
                                <?php $date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $job->data['created'] ); ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <label class="screen-reader-text" for="esb-cb-<?php echo esc_attr( $job->id ); ?>">
                                            <?php
                                            /* translators: %s: дата копії */
                                            echo esc_html( sprintf( __( 'Вибрати копію від %s', 'easy-site-backup' ), $date ) );
                                            ?>
                                        </label>
                                        <input type="checkbox" class="esb-select" id="esb-cb-<?php echo esc_attr( $job->id ); ?>" name="ids[]" value="<?php echo esc_attr( $job->id ); ?>">
                                    </th>
                                    <td><?php echo wp_kses( self::time_html( $job->data['created'] ), self::TIME_TAG ); ?></td>
                                    <td>
                                        <?php echo esc_html( isset( $labels[ $job->data['type'] ] ) ? $labels[ $job->data['type'] ] : $job->data['type'] ); ?>
                                        <?php if ( ! empty( $job->data['scheduled'] ) ) : ?>
                                            <br><span class="esb-scheduled"><?php esc_html_e( 'за розкладом', 'easy-site-backup' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html( self::status_label( $job ) ); ?>
                                        <?php if ( $job->data['error'] ) : ?>
                                            <br><span class="esb-error-text"><?php echo esc_html( $job->data['error'] ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $job->has_archive() ? esc_html( size_format( $job->size(), 1 ) ) : '—'; ?></td>
                                    <td class="esb-actions">
                                        <?php if ( $job->has_archive() ) : ?>
                                            <a class="button button-small" href="<?php echo esc_url( self::download_url( $job->id, ESB_Storage::ARCHIVE ) ); ?>"><?php esc_html_e( 'Завантажити', 'easy-site-backup' ); ?></a>
                                            <button type="button" class="button button-small esb-restore" data-id="<?php echo esc_attr( $job->id ); ?>" <?php disabled( $busy ); ?>><?php esc_html_e( 'Відновити', 'easy-site-backup' ); ?></button>
                                        <?php endif; ?>
                                        <?php if ( file_exists( $job->path( 'log.txt' ) ) ) : ?>
                                            <a href="<?php echo esc_url( self::download_url( $job->id, 'log.txt' ) ); ?>"><?php esc_html_e( 'Лог', 'easy-site-backup' ); ?></a>
                                        <?php endif; ?>
                                        <a class="button-link-delete" href="<?php echo esc_url( self::delete_url( $job->id ) ); ?>"
                                            onclick="return confirm('<?php echo esc_js( __( 'Видалити цю резервну копію?', 'easy-site-backup' ) ); ?>');">
                                            <?php esc_html_e( 'Видалити', 'easy-site-backup' ); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
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

            <div class="esb-card" id="esb-schedule">
            <?php self::render_schedule(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Налаштування розкладу і стан запусків.
     */
    private static function render_schedule() {
        global $wp_locale;

        $settings = ESB_Schedule::settings();
        $next     = wp_next_scheduled( ESB_Schedule::HOOK );
        $last     = ESB_Schedule::last_run();
        $last_job = $last && $last['job'] ? ESB_Job::load( $last['job'] ) : null;
        ?>
        <h2><?php esc_html_e( 'Розклад', 'easy-site-backup' ); ?></h2>

        <?php if ( isset( $_GET['esb_notice'] ) && 'schedule_saved' === $_GET['esb_notice'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <?php // Тут, а не вгорі: після збереження сторінка відкривається на розкладі. Клас inline не дає WordPress перенести сповіщення під шапку. ?>
            <div class="notice notice-success inline"><p><?php esc_html_e( 'Розклад збережено.', 'easy-site-backup' ); ?></p></div>
        <?php endif; ?>

        <form id="esb-schedule-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="esb_save_schedule">
            <?php wp_nonce_field( 'esb_save_schedule' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Автоматичні копії', 'easy-site-backup' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="schedule[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>>
                            <?php esc_html_e( 'Створювати резервні копії за розкладом', 'easy-site-backup' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="esb-frequency"><?php esc_html_e( 'Коли', 'easy-site-backup' ); ?></label></th>
                    <td>
                        <select id="esb-frequency" name="schedule[frequency]">
                            <option value="daily" <?php selected( $settings['frequency'], 'daily' ); ?>><?php esc_html_e( 'Щодня', 'easy-site-backup' ); ?></option>
                            <option value="weekly" <?php selected( $settings['frequency'], 'weekly' ); ?>><?php esc_html_e( 'Щотижня', 'easy-site-backup' ); ?></option>
                        </select>
                        <select id="esb-weekday" name="schedule[weekday]" aria-label="<?php esc_attr_e( 'День тижня', 'easy-site-backup' ); ?>" <?php echo 'weekly' === $settings['frequency'] ? '' : 'hidden'; ?>>
                            <?php foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $day ) : ?>
                                <option value="<?php echo esc_attr( $day ); ?>" <?php selected( $settings['weekday'], $day ); ?>><?php echo esc_html( $wp_locale->get_weekday( $day ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="esb-time"><?php esc_html_e( 'о', 'easy-site-backup' ); ?></label>
                        <input type="time" id="esb-time" name="schedule[time]" value="<?php echo esc_attr( $settings['time'] ); ?>" required>
                        <p class="description">
                            <?php
                            /* translators: %s: часовий пояс сайту */
                            echo esc_html( sprintf( __( 'За часом сайту (%s).', 'easy-site-backup' ), wp_timezone_string() ) );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="esb-schedule-type"><?php esc_html_e( 'Що копіювати', 'easy-site-backup' ); ?></label></th>
                    <td>
                        <select id="esb-schedule-type" name="schedule[type]">
                            <?php foreach ( ESB_Backup::type_labels() as $type => $label ) : ?>
                                <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $settings['type'], $type ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="esb-keep"><?php esc_html_e( 'Зберігати', 'easy-site-backup' ); ?></label></th>
                    <td>
                        <input type="number" id="esb-keep" name="schedule[keep]" class="small-text" min="1" max="<?php echo esc_attr( ESB_Schedule::MAX_KEEP ); ?>" value="<?php echo esc_attr( $settings['keep'] ); ?>">
                        <?php esc_html_e( 'останніх копій за розкладом', 'easy-site-backup' ); ?>
                        <p class="description"><?php esc_html_e( 'Старіші копії за розкладом видаляються автоматично. Копії, створені вручну, не видаляються.', 'easy-site-backup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="esb-notify"><?php esc_html_e( 'Лист на пошту', 'easy-site-backup' ); ?></label></th>
                    <td>
                        <select id="esb-notify" name="schedule[notify]">
                            <option value="failure" <?php selected( $settings['notify'], 'failure' ); ?>><?php esc_html_e( 'Лише про помилки', 'easy-site-backup' ); ?></option>
                            <option value="always" <?php selected( $settings['notify'], 'always' ); ?>><?php esc_html_e( 'Після кожної копії', 'easy-site-backup' ); ?></option>
                            <option value="never" <?php selected( $settings['notify'], 'never' ); ?>><?php esc_html_e( 'Не надсилати', 'easy-site-backup' ); ?></option>
                        </select>
                        <input type="email" name="schedule[email]" class="regular-text" value="<?php echo esc_attr( $settings['email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" aria-label="<?php esc_attr_e( 'Адреса для листів', 'easy-site-backup' ); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Стан', 'easy-site-backup' ); ?></th>
                    <td>
                        <p>
                            <?php esc_html_e( 'Наступний запуск:', 'easy-site-backup' ); ?>
                            <?php echo $next ? wp_kses( self::time_html( $next ), self::TIME_TAG ) : esc_html__( 'не заплановано', 'easy-site-backup' ); ?>
                        </p>
                        <?php if ( $last ) : ?>
                            <p>
                                <?php esc_html_e( 'Останній запуск:', 'easy-site-backup' ); ?>
                                <?php echo wp_kses( self::time_html( $last['time'] ), self::TIME_TAG ); ?> —
                                <?php if ( 'done' === $last['status'] ) : ?>
                                    <?php esc_html_e( 'успішно', 'easy-site-backup' ); ?>
                                <?php elseif ( 'running' === $last['status'] ) : ?>
                                    <?php esc_html_e( 'виконується', 'easy-site-backup' ); ?>
                                <?php else : ?>
                                    <span class="esb-error-text"><?php echo esc_html( sprintf( /* translators: %s: текст помилки */ __( 'помилка: %s', 'easy-site-backup' ), $last['error'] ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( $last_job && file_exists( $last_job->path( 'log.txt' ) ) ) : ?>
                                    (<a href="<?php echo esc_url( self::download_url( $last_job->id, 'log.txt' ) ); ?>"><?php esc_html_e( 'лог', 'easy-site-backup' ); ?></a>)
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e( 'WP-Cron спрацьовує лише тоді, коли хтось відвідує сайт, тож на сайтах з малим трафіком копія може запуститися пізніше. Для точного часу налаштуйте системний cron (див. README).', 'easy-site-backup' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Зберегти розклад', 'easy-site-backup' ), 'secondary' ); ?>
        </form>
        <?php
    }

    private static function render_notice() {
        $notice = isset( $_GET['esb_notice'] ) ? sanitize_key( wp_unslash( $_GET['esb_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 'started' === $notice ) {
            $class   = 'notice-success';
            $message = __( 'Резервне копіювання запущено.', 'easy-site-backup' );
        } elseif ( 'deleted' === $notice ) {
            $count   = isset( $_GET['count'] ) ? absint( $_GET['count'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $class   = 'notice-success';
            /* translators: %d: кількість видалених копій */
            $message = sprintf( __( 'Видалено резервних копій: %d.', 'easy-site-backup' ), $count );
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

    /**
     * Результат останнього відновлення — показується один раз.
     */
    private static function render_restore_result() {
        $state = ESB_Restore::load();

        if ( ! $state || ! empty( $state['seen'] ) || ! in_array( $state['status'], array( 'done', 'failed' ), true ) ) {
            return;
        }

        // Повідомлення збирається вже екранованим, бо дата — HTML-елемент <time>.
        $date = self::time_html( $state['created'] );

        if ( 'done' === $state['status'] ) {
            $class = 'notice-success';
            /* translators: %s: дата копії */
            $message = sprintf( esc_html__( 'Сайт відновлено з копії від %s.', 'easy-site-backup' ), $date );

            if ( $state['files']['failed'] ) {
                $class    = 'notice-warning';
                $message .= ' ' . esc_html(
                    sprintf(
                        /* translators: %d: кількість файлів */
                        __( 'Не вдалося записати файлів: %d — подробиці в лозі копії.', 'easy-site-backup' ),
                        $state['files']['failed']
                    )
                );
            }
        } else {
            $class = 'notice-error';
            /* translators: 1: дата копії, 2: текст помилки */
            $message = sprintf( esc_html__( 'Не вдалося відновити сайт з копії від %1$s: %2$s Подробиці — у лозі копії.', 'easy-site-backup' ), $date, esc_html( $state['error'] ) );
        }

        printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), wp_kses( $message, self::TIME_TAG ) );

        ESB_Restore::mark_seen();
    }

    /**
     * Дата як <time>: сервер виводить її за поясом сайту, а admin.js переписує на час браузера.
     */
    private static function time_html( $timestamp ) {
        return sprintf(
            '<time class="esb-time" datetime="%s">%s</time>',
            esc_attr( gmdate( 'c', $timestamp ) ),
            esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) )
        );
    }

    /**
     * Підказка, якщо пояс сайту — UTC: admin.js покаже її, коли пояс комп'ютера інший.
     */
    private static function render_timezone_hint() {
        if ( 0 !== wp_timezone()->getOffset( new DateTime() ) ) {
            return;
        }
        ?>
        <div id="esb-tz-notice" class="notice notice-info" hidden>
            <p>
                <?php
                printf(
                    /* translators: %s: посилання на загальні налаштування */
                    esc_html__( 'Часовий пояс сайту — UTC, а у вашого комп\'ютера інший. Дати на цій сторінці показано за вашим часом, а в лозі — за часом сайту. Змінити пояс сайту: %s.', 'easy-site-backup' ),
                    '<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Налаштування → Загальні', 'easy-site-backup' ) . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
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

    private static function delete_url( $id ) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'esb_delete',
                    'id'     => $id,
                ),
                admin_url( 'admin-post.php' )
            ),
            'esb_delete_' . $id
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

    public static function ajax_restore_start() {
        self::check_ajax();

        $state = ESB_Restore::start( self::posted_id() );

        if ( is_wp_error( $state ) ) {
            wp_send_json_error( array( 'message' => $state->get_error_message() ) );
        }

        wp_send_json_success( ESB_Restore::status( $state ) );
    }

    /**
     * Без nonce і перевірки прав: доступ дає токен, який отримав адміністратор, що запустив відновлення.
     */
    public static function ajax_restore_step() {
        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $state = ESB_Restore::step( $token );

        if ( is_wp_error( $state ) ) {
            wp_send_json_error( array( 'message' => $state->get_error_message() ) );
        }

        wp_send_json_success( ESB_Restore::status( $state ) );
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

    public static function handle_save_schedule() {
        self::check_access( 'esb_save_schedule' );

        $input = isset( $_POST['schedule'] ) && is_array( $_POST['schedule'] ) ? wp_unslash( $_POST['schedule'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- очищається в ESB_Schedule::sanitize().
        ESB_Schedule::save( ESB_Schedule::sanitize( $input ) );

        // Розклад — внизу сторінки, під списком копій: повертаємося одразу до нього.
        self::redirect( 'schedule_saved', 'esb-schedule' );
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
        $id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
        self::check_access( 'esb_delete_' . $id );

        self::delete_backups( array( $id ) );
    }

    public static function handle_bulk_delete() {
        self::check_access( 'esb_bulk_delete' );

        $ids = isset( $_POST['ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['ids'] ) ) : array();

        if ( empty( $ids ) ) {
            self::redirect_error( __( 'Не вибрано жодної резервної копії.', 'easy-site-backup' ) );
        }

        self::delete_backups( $ids );
    }

    private static function delete_backups( array $ids ) {
        $active  = ESB_Backup::active_job();
        $restore = ESB_Restore::active();
        $deleted = 0;
        $failed  = 0;

        foreach ( array_unique( $ids ) as $id ) {
            // Копію, що ще створюється, спершу треба скасувати; з копії, з якої йде відновлення, — дочекатися кінця.
            if ( ( $active && $active->id === $id ) || ( $restore && $restore['backup'] === $id ) ) {
                $failed++;
                continue;
            }

            if ( ESB_Storage::delete_dir( $id ) ) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ( $failed ) {
            self::redirect_error(
                sprintf(
                    /* translators: 1: кількість видалених копій, 2: кількість невидалених */
                    __( 'Видалено резервних копій: %1$d. Не вдалося видалити: %2$d.', 'easy-site-backup' ),
                    $deleted,
                    $failed
                )
            );
        }

        wp_safe_redirect( add_query_arg( array( 'esb_notice' => 'deleted', 'count' => $deleted ), admin_url( 'tools.php?page=' . self::PAGE ) ) );
        exit;
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

    /**
     * @param string $anchor id елемента сторінки, до якого прокрутити.
     */
    private static function redirect( $notice, $anchor = '' ) {
        $url = add_query_arg( 'esb_notice', $notice, admin_url( 'tools.php?page=' . self::PAGE ) );

        wp_safe_redirect( $anchor ? $url . '#' . $anchor : $url );
        exit;
    }
}
