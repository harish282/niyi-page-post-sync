<?php

/**
 * Plugin Name: Niyi Page Post Sync
 * Plugin URI:  https://github.com/niyi/niyi-page-post-sync
 * Description: Sync pages and posts between WordPress sites via the WordPress REST API. Supports multiple receivers, bulk/single sync, remote ID tracking and media ID remapping.
 * Version:     1.0.0
 * Author:      Niyi
 * License:     GPL-2.0-or-later
 * Text Domain: niyi-page-post-sync
 */

if (! defined('ABSPATH')) {
	exit;
}

define('NIYI_PPS_VERSION', '1.0.0');
define('NIYI_PPS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NIYI_PPS_PLUGIN_PATH', plugin_dir_path(__FILE__));

require_once NIYI_PPS_PLUGIN_PATH . 'src/Settings.php';
require_once NIYI_PPS_PLUGIN_PATH . 'src/ApiClient.php';
require_once NIYI_PPS_PLUGIN_PATH . 'src/Synchronizer.php';
require_once NIYI_PPS_PLUGIN_PATH . 'src/Shortcodes.php';
require_once NIYI_PPS_PLUGIN_PATH . 'src/Plugin.php';

add_action('plugins_loaded', function () {
	$plugin = new \Niyi\PagePostSync\Plugin();
	$plugin->run();
});
