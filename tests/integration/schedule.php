<?php
/**
 * Розклад: розрахунок наступного запуску, планування, ротація, листи.
 *
 * Запуск: php tests/integration/schedule.php /path/to/wordpress
 */

require __DIR__ . '/bootstrap.php';

$keep          = esb_test_job_ids();
$orig_tz       = get_option( 'timezone_string' );
$orig_offset   = get_option( 'gmt_offset' );
$orig_settings = get_option( ESB_Schedule::OPTION );
$orig_auto     = get_site_option( 'auto_update_plugins' );

$mails = array();
add_filter(
    'pre_wp_mail',
    function ( $null, $atts ) use ( &$mails ) {
        $mails[] = $atts;
        return true;
    },
    10,
    2
);

function run_utc( $settings, $now ) {
    return gmdate( 'Y-m-d H:i', ESB_Schedule::next_run( array_merge( ESB_Schedule::defaults(), $settings ), strtotime( $now ) ) );
}

echo "== next_run\n";
update_option( 'timezone_string', '' );
update_option( 'gmt_offset', 0 );
$now = '2026-10-20 06:30:00 UTC'; // вівторок
check( '2026-10-21 03:00' === run_utc( array( 'time' => '03:00' ), $now ), 'daily, time passed → tomorrow' );
check( '2026-10-20 07:15' === run_utc( array( 'time' => '07:15' ), $now ), 'daily, time ahead → today' );
check( '2026-10-26 03:00' === run_utc( array( 'frequency' => 'weekly', 'weekday' => 1, 'time' => '03:00' ), $now ), 'weekly Monday → next Monday' );
check( '2026-10-27 06:30' === run_utc( array( 'frequency' => 'weekly', 'weekday' => 2, 'time' => '06:30' ), $now ), 'weekly, same minute → +7 days' );

update_option( 'timezone_string', esb_test_kyiv() );
check( '2026-10-21 00:00' === run_utc( array( 'time' => '03:00' ), $now ), 'Kyiv 03:00 in summer time = 00:00 UTC' );
check( '2026-10-26 01:00' === run_utc( array( 'time' => '03:00' ), '2026-10-25 12:00:00 UTC' ), 'Kyiv 03:00 after DST change = 01:00 UTC' );
update_option( 'timezone_string', '' );

echo "== sanitize\n";
$clean = ESB_Schedule::sanitize( array( 'enabled' => '1', 'frequency' => 'hourly', 'weekday' => '9', 'time' => '25:00', 'type' => 'evil', 'keep' => '999', 'notify' => 'x', 'email' => 'not-email' ) );
check( $clean['enabled'] && 'daily' === $clean['frequency'] && 1 === $clean['weekday'] && '03:00' === $clean['time'] && 'full' === $clean['type'] && ESB_Schedule::MAX_KEEP === $clean['keep'] && 'failure' === $clean['notify'] && '' === $clean['email'], 'invalid input → defaults and limits' );

echo "== save\n";
ESB_Schedule::save( ESB_Schedule::sanitize( array( 'enabled' => '1', 'time' => '04:00', 'type' => 'db', 'keep' => '2', 'notify' => 'failure' ) ) );
check( wp_next_scheduled( ESB_Schedule::HOOK ) === ESB_Schedule::next_run( ESB_Schedule::settings() ), 'event scheduled at next_run' );
ESB_Schedule::save( array_merge( ESB_Schedule::settings(), array( 'enabled' => false ) ) );
check( false === wp_next_scheduled( ESB_Schedule::HOOK ), 'disabled → no event' );
ESB_Schedule::save( array_merge( ESB_Schedule::settings(), array( 'enabled' => true ) ) );

echo "== runs and rotation\n";
$manual    = esb_test_backup( 'db' );
$scheduled = array();
for ( $i = 0; $i < 3; $i++ ) {
    sleep( 1 ); // різний час створення
    ESB_Schedule::run();
    $last = ESB_Schedule::last_run();
    check( 'running' === $last['status'], "run $i started" );

    $job = ESB_Job::load( $last['job'] );
    do {
        $job = ESB_Backup::step( $job->id );
    } while ( $job->is_running() );
    $scheduled[] = $job->id;
    check( ! empty( $job->data['scheduled'] ), "run $i marked as scheduled" );
}
$last = ESB_Schedule::last_run();
check( 'done' === $last['status'] && end( $scheduled ) === $last['job'], 'last run = done' );
check( false !== wp_next_scheduled( ESB_Schedule::HOOK ), 'next run scheduled after run()' );
check( ! ESB_Job::load( $scheduled[0] ) && ESB_Job::load( $scheduled[1] ) && ESB_Job::load( $scheduled[2] ), 'rotation kept 2 newest scheduled' );
check( (bool) ESB_Job::load( $manual->id ), 'manual backup kept' );
foreach ( $keep as $id ) {
    check( (bool) ESB_Job::load( $id ), "pre-existing backup $id kept" );
}
check( 0 === count( $mails ), 'notify=failure → no mail on success' );

echo "== mail\n";
ESB_Schedule::save( array_merge( ESB_Schedule::settings(), array( 'notify' => 'always', 'email' => 'test@example.com' ) ) );
ESB_Schedule::run();
$job = ESB_Job::load( ESB_Schedule::last_run()['job'] );
do {
    $job = ESB_Backup::step( $job->id );
} while ( $job->is_running() );
check( 1 === count( $mails ) && 'test@example.com' === $mails[0]['to'] && false !== strpos( $mails[0]['subject'], 'створено' ) && ! empty( $mails[0]['attachments'] ), 'notify=always → success mail with log' );

$mails   = array();
$restore = ESB_Restore::start( $job->id );
ESB_Schedule::save( array_merge( ESB_Schedule::settings(), array( 'notify' => 'failure' ) ) );
ESB_Schedule::run();
$last = ESB_Schedule::last_run();
check( 'failed' === $last['status'] && '' === $last['job'], 'run skipped during restore' );
check( 1 === count( $mails ) && false !== strpos( $mails[0]['subject'], 'Помилка' ), 'failure mail on skipped run' );
do {
    $state = ESB_Restore::step( $restore['token'] );
} while ( 'running' === $state['status'] );
check( 'done' === $state['status'], 'restore finished' );
// Копію зроблено з notify=always; перед відновленням стало failure — так і має лишитися.
check( 'failure' === ESB_Schedule::settings()['notify'], 'restore keeps schedule settings from before the restore' );
check( wp_next_scheduled( ESB_Schedule::HOOK ) === ESB_Schedule::next_run( ESB_Schedule::settings() ), 'schedule rescheduled after restore' );

echo "== deactivate\n";
ESB_Schedule::deactivate();
check( false === wp_next_scheduled( ESB_Schedule::HOOK ) && false === wp_next_scheduled( ESB_Updater::CHECK_HOOK ), 'deactivate clears schedule and update check' );

// Відновлення бази повернуло налаштування на момент копії — повертаємо початкові.
update_option( 'timezone_string', $orig_tz );
update_option( 'gmt_offset', $orig_offset );
if ( false === $orig_settings ) {
    delete_option( ESB_Schedule::OPTION );
} else {
    update_option( ESB_Schedule::OPTION, $orig_settings );
}
update_site_option( 'auto_update_plugins', $orig_auto );
delete_option( ESB_Schedule::LAST_OPTION );
ESB_Schedule::reschedule();
ESB_Updater::schedule_check();
ESB_Restore::mark_seen();
esb_test_cleanup( $keep );

finish();
