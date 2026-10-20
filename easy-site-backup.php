<?php
/**
 * Plugin Name: Easy Site Backup
 * Description: Резервне копіювання бази даних і файлів сайту.
 * Version: 0.4.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Eugene
 * License: GPL-2.0-or-later
 * Text Domain: easy-site-backup
 * Update URI: https://github.com/Eugene88-source/easy-site-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ESB_VERSION', '0.4.0' );
define( 'ESB_FILE', __FILE__ );
define( 'ESB_DIR', plugin_dir_path( __FILE__ ) );

require_once ESB_DIR . 'includes/class-esb-storage.php';
require_once ESB_DIR . 'includes/class-esb-job.php';
require_once ESB_DIR . 'includes/class-esb-zip-writer.php';
require_once ESB_DIR . 'includes/class-esb-backup.php';
require_once ESB_DIR . 'includes/class-esb-zip-reader.php';
require_once ESB_DIR . 'includes/class-esb-restore.php';
require_once ESB_DIR . 'includes/class-esb-schedule.php';
require_once ESB_DIR . 'includes/class-esb-updater.php';

register_activation_hook( __FILE__, array( 'ESB_Schedule', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ESB_Schedule', 'deactivate' ) );

// Запасний виконавець кроків, коли сторінку плагіна закрито.
add_action( ESB_Backup::CRON_HOOK, array( 'ESB_Backup', 'cron_step' ) );
add_action( ESB_Restore::CRON_HOOK, array( 'ESB_Restore', 'cron_step' ) );

add_action( ESB_Schedule::HOOK, array( 'ESB_Schedule', 'run' ) );
add_action( 'esb_backup_finished', array( 'ESB_Schedule', 'on_finished' ) );

// Перевірка оновлень працює й у WP-Cron, тож підключаємо її не лише в адмінці.
ESB_Updater::init();

if ( is_admin() ) {
    require_once ESB_DIR . 'includes/class-esb-admin.php';
    ESB_Admin::init();
}
