<?php
/**
 * Резервні копії за розкладом: запуск через WP-Cron, ротація старих копій і сповіщення поштою.
 *
 * Кожен запуск планує наступний окремою подією, а не повторюваною: так час запуску
 * лишається тим самим за поясом сайту й після переходу на літній чи зимовий час.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ESB_Schedule {

    const OPTION = 'esb_schedule';

    const LAST_OPTION = 'esb_schedule_last';

    const HOOK = 'esb_scheduled_backup';

    const FREQUENCIES = array( 'daily', 'weekly' );

    const NOTIFY = array( 'never', 'failure', 'always' );

    const MAX_KEEP = 50;

    /**
     * Скільки днів зберігати невдалі копії за розкладом (лишається лише лог).
     */
    const KEEP_FAILED_DAYS = 30;

    public static function defaults() {
        return array(
            'enabled'   => false,
            'frequency' => 'daily',
            'weekday'   => 1,
            'time'      => '03:00',
            'type'      => 'full',
            'keep'      => 5,
            'notify'    => 'failure',
            'email'     => '',
        );
    }

    public static function settings() {
        return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
    }

    /**
     * @param array $input Сирі дані форми.
     */
    public static function sanitize( array $input ) {
        $defaults = self::defaults();
        $input    = array_map(
            function ( $value ) {
                return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
            },
            $input
        );
        $email    = isset( $input['email'] ) ? sanitize_email( $input['email'] ) : '';
        $weekday  = isset( $input['weekday'] ) && preg_match( '/^[0-6]$/', $input['weekday'] ) ? (int) $input['weekday'] : $defaults['weekday'];

        return array(
            'enabled'   => ! empty( $input['enabled'] ),
            'frequency' => isset( $input['frequency'] ) && in_array( $input['frequency'], self::FREQUENCIES, true ) ? $input['frequency'] : $defaults['frequency'],
            'weekday'   => $weekday,
            'time'      => isset( $input['time'] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $input['time'] ) ? $input['time'] : $defaults['time'],
            'type'      => isset( $input['type'] ) && in_array( $input['type'], ESB_Backup::TYPES, true ) ? $input['type'] : $defaults['type'],
            'keep'      => isset( $input['keep'] ) ? min( self::MAX_KEEP, max( 1, absint( $input['keep'] ) ) ) : $defaults['keep'],
            'notify'    => isset( $input['notify'] ) && in_array( $input['notify'], self::NOTIFY, true ) ? $input['notify'] : $defaults['notify'],
            'email'     => is_email( $email ) ? $email : '',
        );
    }

    public static function save( array $settings ) {
        update_option( self::OPTION, $settings, false );
        self::reschedule();
    }

    /**
     * Прибирає заплановані запуски й, якщо розклад увімкнено, планує найближчий.
     */
    public static function reschedule() {
        wp_clear_scheduled_hook( self::HOOK );

        $settings = self::settings();
        if ( $settings['enabled'] ) {
            wp_schedule_single_event( self::next_run( $settings ), self::HOOK );
        }
    }

    /**
     * @return int Найближчий момент у майбутньому з заданим часом (і днем тижня) за поясом сайту.
     */
    public static function next_run( array $settings, $now = null ) {
        $now = null === $now ? time() : (int) $now;

        list( $hour, $minute ) = array_map( 'intval', explode( ':', $settings['time'] ) );

        $run = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( $hour, $minute );

        if ( 'weekly' === $settings['frequency'] ) {
            $days = ( (int) $settings['weekday'] - (int) $run->format( 'w' ) + 7 ) % 7;
            $run  = $run->modify( "+{$days} days" );
            $step = '+7 days';
        } else {
            $step = '+1 day';
        }

        if ( $run->getTimestamp() <= $now ) {
            $run = $run->modify( $step );
        }

        return $run->getTimestamp();
    }

    /**
     * Обробник WP-Cron: запускає копію і планує наступний запуск.
     */
    public static function run() {
        $settings = self::settings();

        if ( ! $settings['enabled'] ) {
            return;
        }

        self::reschedule();

        $job = ESB_Backup::start( $settings['type'], true );

        if ( is_wp_error( $job ) ) {
            self::record( 'failed', '', $job->get_error_message() );

            if ( 'never' !== $settings['notify'] ) {
                self::notify( null, $job->get_error_message() );
            }
            return;
        }

        // Далі кроки виконує ESB_Backup::cron_step, а підсумок підбиває on_finished().
        self::record( 'running', $job->id, '' );
    }

    /**
     * Після завершення копії за розкладом: підсумок, ротація і лист.
     */
    public static function on_finished( ESB_Job $job ) {
        if ( empty( $job->data['scheduled'] ) ) {
            return;
        }

        $settings = self::settings();
        $done     = 'done' === $job->data['status'];

        self::record( $done ? 'done' : 'failed', $job->id, $job->data['error'] );
        self::rotate( $settings['keep'] );

        if ( ( ! $done && 'never' !== $settings['notify'] ) || ( $done && 'always' === $settings['notify'] ) ) {
            self::notify( $job, $job->data['error'] );
        }
    }

    /**
     * Видаляє зайві копії за розкладом: успішні понад $keep і невдалі, старші за KEEP_FAILED_DAYS.
     * Копії, створені вручну, не чіпає.
     */
    public static function rotate( $keep ) {
        $restore = ESB_Restore::active();
        $expired = time() - self::KEEP_FAILED_DAYS * DAY_IN_SECONDS;
        $kept    = 0;

        // list_jobs() повертає новіші першими.
        foreach ( ESB_Storage::list_jobs() as $job ) {
            if ( empty( $job->data['scheduled'] ) || $job->is_running() || ( $restore && $restore['backup'] === $job->id ) ) {
                continue;
            }

            if ( 'done' === $job->data['status'] ? ++$kept > $keep : $job->data['created'] < $expired ) {
                ESB_Storage::delete_dir( $job->id );
            }
        }
    }

    /**
     * @return array|null Останній запуск: time, status (running | done | failed), job, error.
     */
    public static function last_run() {
        $last = get_option( self::LAST_OPTION );

        return is_array( $last ) ? $last : null;
    }

    private static function record( $status, $job_id, $error ) {
        $last = self::last_run();

        update_option(
            self::LAST_OPTION,
            array(
                // Для завершення копії час лишаємо від її запуску.
                'time'   => 'running' !== $status && $last && $last['job'] === $job_id && $job_id ? $last['time'] : time(),
                'status' => $status,
                'job'    => $job_id,
                'error'  => $error,
            ),
            false
        );
    }

    /**
     * @param ESB_Job|null $job   Копія; null — запуск не відбувся.
     * @param string       $error Текст помилки.
     */
    private static function notify( $job, $error ) {
        $settings = self::settings();
        $to       = $settings['email'] ? $settings['email'] : get_option( 'admin_email' );
        $site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $labels   = ESB_Backup::type_labels();
        $type     = $job ? $job->data['type'] : $settings['type'];
        $lines    = array();

        if ( $job && 'done' === $job->data['status'] ) {
            /* translators: %s: назва сайту */
            $subject = sprintf( __( '[%s] Резервну копію створено', 'easy-site-backup' ), $site );
            /* translators: %s: адреса сайту */
            $lines[] = sprintf( __( 'Резервну копію сайту %s створено за розкладом.', 'easy-site-backup' ), home_url() );
            /* translators: %s: розмір архіву */
            $lines[] = sprintf( __( 'Розмір: %s', 'easy-site-backup' ), html_entity_decode( size_format( $job->size(), 1 ), ENT_QUOTES, 'UTF-8' ) );
        } else {
            /* translators: %s: назва сайту */
            $subject = sprintf( __( '[%s] Помилка резервного копіювання', 'easy-site-backup' ), $site );
            /* translators: %s: адреса сайту */
            $lines[] = sprintf( __( 'Не вдалося створити резервну копію сайту %s за розкладом.', 'easy-site-backup' ), home_url() );
            /* translators: %s: текст помилки */
            $lines[] = sprintf( __( 'Причина: %s', 'easy-site-backup' ), $error ? $error : __( 'невідома', 'easy-site-backup' ) );
        }

        /* translators: %s: тип копії */
        $lines[] = sprintf( __( 'Тип: %s', 'easy-site-backup' ), isset( $labels[ $type ] ) ? $labels[ $type ] : $type );
        /* translators: %s: дата й час */
        $lines[] = sprintf( __( 'Час: %s', 'easy-site-backup' ), ESB_Job::site_time( 'Y-m-d H:i' ) );
        $lines[] = '';
        /* translators: %s: посилання на сторінку плагіна */
        $lines[] = sprintf( __( 'Керування копіями: %s', 'easy-site-backup' ), admin_url( 'tools.php?page=easy-site-backup' ) );

        $attachments = array();
        if ( $job && is_file( $job->path( 'log.txt' ) ) ) {
            $attachments[] = $job->path( 'log.txt' );
        }

        wp_mail( $to, $subject, implode( "\n", $lines ), '', $attachments );
    }

    public static function activate() {
        ESB_Storage::ensure_dir();
        self::reschedule();
    }

    /**
     * wp_unschedule_hook(), а не wp_clear_scheduled_hook(): у подій кроків є аргументи.
     */
    public static function deactivate() {
        wp_unschedule_hook( self::HOOK );
        wp_unschedule_hook( ESB_Backup::CRON_HOOK );
        wp_unschedule_hook( ESB_Restore::CRON_HOOK );
        wp_unschedule_hook( ESB_Updater::CHECK_HOOK );
    }
}
