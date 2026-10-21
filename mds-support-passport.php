<?php
/**
 * Plugin Name: Million Dollar Script - Proof of Support Passport
 * Plugin URI: https://milliondollarscript.com/extensions/support-passport
 * Description: Issue privacy-first, site-local supporter badges and proof pages for Million Dollar Script.
 * Version: 0.2.0
 * Author: Million Dollar Script
 * Author URI: https://milliondollarscript.com
 * Text Domain: mds-support-passport
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.1.3
 * Requires PHP: 8.1
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins: million-dollar-script
 *
 * Requires MDS: 3.0.0
 * Requires MDS API: 1
 * Requires License: No
 * MDS Provides: supporter.badges proof.support privacy.proofs
 * MDS Requires: platform.core
 * MDS Recommends: mds-grid mds-sponsorboard mds-launch-wall mds-missions
 * MDS Requires Service: no
 * MDS Setup Category: trust
 * MDS Minimum Security Level: public_read
 * MDS LLM Safe Actions: read_badges read_public_proofs
 *
 * @package MillionDollarScript\Extensions\SupportPassport
 */

namespace MillionDollarScript\Extensions\SupportPassport;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MDS_SUPPORT_PASSPORT_VERSION')) {
    define('MDS_SUPPORT_PASSPORT_VERSION', '0.2.0');
}

if (!defined('MDS_SUPPORT_PASSPORT_DB_VERSION')) {
    define('MDS_SUPPORT_PASSPORT_DB_VERSION', '0.1.1');
}

if (!defined('MDS_SUPPORT_PASSPORT_FILE')) {
    define('MDS_SUPPORT_PASSPORT_FILE', __FILE__);
}

if (!defined('MDS_SUPPORT_PASSPORT_PATH')) {
    define('MDS_SUPPORT_PASSPORT_PATH', plugin_dir_path(__FILE__));
}

if (!defined('MDS_SUPPORT_PASSPORT_URL')) {
    define('MDS_SUPPORT_PASSPORT_URL', plugin_dir_url(__FILE__));
}

if (!defined('MDS_SUPPORT_PASSPORT_BASENAME')) {
    define('MDS_SUPPORT_PASSPORT_BASENAME', plugin_basename(__FILE__));
}

function mds_support_passport_core_active() {
    return class_exists('\\MillionDollarScript\\Core\\Runtime')
        && \MillionDollarScript\Core\Runtime::is_ready();
}

function mds_support_passport_missing_core_notice() {
    echo '<div class="notice notice-error"><p>';
    echo '<strong>' . esc_html__('Million Dollar Script - Proof of Support Passport', 'mds-support-passport') . '</strong>: ';
    echo esc_html__('This extension requires the Million Dollar Script plugin to be installed and activated.', 'mds-support-passport');
    echo ' <a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Manage Plugins', 'mds-support-passport') . '</a>';
    echo '</p></div>';
}

function mds_support_passport_load_files() {
    require_once MDS_SUPPORT_PASSPORT_PATH . 'includes/Repository.php';
    require_once MDS_SUPPORT_PASSPORT_PATH . 'includes/Retention.php';
    require_once MDS_SUPPORT_PASSPORT_PATH . 'includes/Main.php';
}

function mds_support_passport_init() {
    if (!mds_support_passport_core_active()) {
        add_action('admin_notices', __NAMESPACE__ . '\\mds_support_passport_missing_core_notice');
        return;
    }

    mds_support_passport_load_files();
    Main::init();
    Retention::register();
}

function mds_support_passport_register_extension() {
    if (!mds_support_passport_core_active()) {
        return;
    }

    mds_support_passport_load_files();

    if (class_exists('\\MillionDollarScript\\Extensions\\Registry')) {
        \MillionDollarScript\Extensions\Registry::register([
            'id' => 'mds-support-passport',
            'name' => 'Proof of Support Passport',
            'description' => 'Issue privacy-first, site-local supporter badges and proof pages.',
            'version' => MDS_SUPPORT_PASSPORT_VERSION,
            'author' => 'Million Dollar Script',
            'url' => 'https://milliondollarscript.com/extensions/support-passport',
            'main_file' => __FILE__,
            'premium' => false,
            'license_required' => false,
            'cleanup' => [
                'description' => 'Supporter profiles, badges, grants, consent, proofs, and audit records.',
            ],
            'capabilities' => [
                'admin_pages' => true,
                'api' => true,
                'privacy_export' => true,
                'privacy_erase' => true,
                'frontend_assets' => true,
            ],
            'init_callback' => ['\\MillionDollarScript\\Extensions\\SupportPassport\\Main', 'init'],
        ]);
    }
}

function mds_support_passport_grant_badge_for_source(array $data, array $context = []) {
    if (!mds_support_passport_core_active()) {
        return new \WP_Error(
            'mds_support_passport_missing_core',
            __('Million Dollar Script - Proof of Support Passport requires the Million Dollar Script plugin to be active.', 'mds-support-passport')
        );
    }

    mds_support_passport_load_files();

    return Main::instance()->grant_source_badge($data, $context);
}

function mds_support_passport_activate() {
    if (!mds_support_passport_core_active()) {
        deactivate_plugins(MDS_SUPPORT_PASSPORT_BASENAME);
        wp_die(
            esc_html__('Million Dollar Script - Proof of Support Passport requires the Million Dollar Script plugin to be installed and activated.', 'mds-support-passport'),
            esc_html__('Plugin Activation Error', 'mds-support-passport'),
            ['back_link' => true]
        );
    }

    mds_support_passport_load_files();
    Repository::install();
    Repository::maybe_seed();
    Retention::schedule();
    Main::add_rewrite_rules();
    flush_rewrite_rules(false);
}

function mds_support_passport_deactivate() {
    mds_support_passport_load_files();
    Retention::clear();
    flush_rewrite_rules(false);
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\mds_support_passport_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\mds_support_passport_deactivate');

add_action('plugins_loaded', __NAMESPACE__ . '\\mds_support_passport_init', 20);
add_action('million-dollar-script/register/extensions', __NAMESPACE__ . '\\mds_support_passport_register_extension');
