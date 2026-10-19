<?php
/**
 * Plugin Name: Easy Site Backup
 * Description: Резервне копіювання бази даних і файлів сайту.
 * Version: 0.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Eugene
 * License: GPL-2.0-or-later
 * Text Domain: easy-site-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ESB_VERSION', '0.1.0' );
define( 'ESB_DIR', plugin_dir_path( __FILE__ ) );

require_once ESB_DIR . 'includes/class-esb-storage.php';
require_once ESB_DIR . 'includes/class-esb-backup.php';

register_activation_hook( __FILE__, array( 'ESB_Storage', 'ensure_dir' ) );

if ( is_admin() ) {
    require_once ESB_DIR . 'includes/class-esb-admin.php';
    ESB_Admin::init();
}
