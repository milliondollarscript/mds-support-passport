<?php
/**
 * Proof of Support Passport runtime, admin UI, frontend, REST, and privacy hooks.
 *
 * @package MillionDollarScript\Extensions\SupportPassport
 */

namespace MillionDollarScript\Extensions\SupportPassport;

use WP_REST_Request;
use WP_REST_Response;
use MillionDollarScript\Core\Orders;

if (!defined('ABSPATH')) {
    exit;
}

final class Main {

    public const REST_NAMESPACE = 'million-dollar-script/v1';
    public const REST_BASE = '/support-passport';

    private static $instance = null;
    private $initialized = false;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function init() {
        self::instance()->initialize();
    }

    private function initialize() {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        Repository::maybe_install();

        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'maybe_render_proof_page']);
        add_action('million-dollar-script/admin/menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('admin_post_mds_support_passport_save_badge', [$this, 'handle_save_badge']);
        add_action('admin_post_mds_support_passport_delete_badge', [$this, 'handle_delete_badge']);
        add_action('admin_post_mds_support_passport_update_badge_status', [$this, 'handle_update_badge_status']);
        add_action('admin_post_mds_support_passport_grant_badge', [$this, 'handle_grant_badge']);
        add_action('admin_post_mds_support_passport_update_grant', [$this, 'handle_update_grant']);
        add_action('admin_post_mds_support_passport_revoke_grant', [$this, 'handle_revoke_grant']);
        add_action('admin_post_mds_support_passport_archive_grant', [$this, 'handle_archive_grant']);
        add_action('admin_post_mds_support_passport_issue_manage_link', [$this, 'handle_issue_manage_link']);
        add_action('admin_post_mds_support_passport_issue_proof_link', [$this, 'handle_issue_proof_link']);
        add_action('admin_post_mds_support_passport_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_mds_support_passport_manage_profile', [$this, 'handle_manage_profile']);
        add_action('admin_post_nopriv_mds_support_passport_manage_profile', [$this, 'handle_manage_profile']);
        add_action('admin_post_mds_support_passport_manage_consent', [$this, 'handle_manage_consent']);
        add_action('admin_post_nopriv_mds_support_passport_manage_consent', [$this, 'handle_manage_consent']);
        add_action('million-dollar-script/payment/source/paid', [$this, 'handle_paid_payment_source'], 20, 3);
        add_action('million-dollar-script/order/status/changed', [$this, 'handle_order_status_changed'], 20, 6);
        add_action('million-dollar-script/extensions/support-passport/grant/source/badge', [$this, 'handle_source_badge_grant'], 10, 2);
        add_action('mds_support_passport_grant_source_badge', [$this, 'handle_source_badge_grant'], 10, 2);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'privacy_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'privacy_erasers']);
        add_filter('million-dollar-script/api/endpoint/manifest', [$this, 'api_endpoint_manifest']);
        add_filter('million-dollar-script/extension/onboarding/items', [$this, 'extension_onboarding_items']);
        add_filter('million-dollar-script/dashboard/extension/cards', [$this, 'dashboard_extension_cards']);
        add_filter('million-dollar-script/editor/extension/blocks', [$this, 'editor_blocks']);
        add_shortcode('mds_support_passport_directory', [$this, 'directory_shortcode']);
        add_shortcode('mds_support_passport_badges', [$this, 'badges_shortcode']);
    }

    public static function add_rewrite_rules() {
        $settings = Repository::settings();
        $slug = sanitize_title((string) ($settings['proof_slug'] ?? 'support-proof')) ?: 'support-proof';
        add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/?$', 'index.php?mds_support_passport_proof=$matches[1]', 'top');
        add_rewrite_rule('^support-passport/manage/([0-9]+)/([^/]+)/?$', 'index.php?mds_support_passport_manage=$matches[1]&mds_support_passport_token=$matches[2]', 'top');
    }

    public function query_vars(array $vars) {
        $vars[] = 'mds_support_passport_proof';
        $vars[] = 'mds_support_passport_manage';
        $vars[] = 'mds_support_passport_token';

        return $vars;
    }

    public function admin_menu() {
        add_submenu_page(
            'mds3',
            __('Proof of Support Passport', 'mds-support-passport'),
            __('Support Passport', 'mds-support-passport'),
            'manage_options',
            'mds-support-passport',
            [$this, 'admin_page']
        );
    }

    public function admin_assets($hook) {
        if (false === strpos((string) $hook, 'mds-support-passport')) {
            return;
        }

        if (class_exists('\MillionDollarScript\Core\Runtime')) {
            wp_enqueue_style('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'css/admin.css', [], \MillionDollarScript\Core\Runtime::version());
            wp_enqueue_script('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'js/admin.js', [], \MillionDollarScript\Core\Runtime::version(), true);
        }
        wp_enqueue_media();
        wp_enqueue_style('mds-support-passport-admin', MDS_SUPPORT_PASSPORT_URL . 'assets/css/admin.css', [], MDS_SUPPORT_PASSPORT_VERSION);
        wp_enqueue_script('mds-support-passport-admin-js', MDS_SUPPORT_PASSPORT_URL . 'assets/js/admin.js', [], MDS_SUPPORT_PASSPORT_VERSION, true);
        \MillionDollarScript\Extensions\Support::add_browser_config('mds-support-passport-admin-js', 'supportPassport', 'admin', [
            'mediaTitle' => __('Choose badge image', 'mds-support-passport'),
            'mediaButton' => __('Use this image', 'mds-support-passport'),
            'copyLabel' => __('Copy', 'mds-support-passport'),
            'copiedLabel' => __('Copied', 'mds-support-passport'),
        ]);
    }

    public function frontend_assets() {
        wp_register_style('mds-support-passport', MDS_SUPPORT_PASSPORT_URL . 'assets/css/style.css', [], MDS_SUPPORT_PASSPORT_VERSION);
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage support passports.', 'mds-support-passport'));
        }

        $tab = sanitize_key((string) ($_GET['tab'] ?? 'grants'));
        if (!in_array($tab, ['overview', 'badges', 'grants', 'privacy', 'settings'], true)) {
            $tab = 'grants';
        }
        $counts = Repository::counts();
        $grant_filters = $this->grant_filters_from_request();
        $settings = Repository::settings();
        $badges = [];
        $badge_total = 0;
        $badge_page = max(1, absint($this->request_query_scalar('badge_page')));
        $grants = [];
        $grant_total = 0;
        $grant_page = max(1, absint($this->request_query_scalar('grant_page')));
        $per_page = 20;

        if ('badges' === $tab) {
            $badge_total = Repository::badge_count();
            $badge_page = min($badge_page, max(1, (int) ceil($badge_total / $per_page)));
            $badges = Repository::badges(['limit' => $per_page, 'offset' => ($badge_page - 1) * $per_page]);
        } elseif ('grants' === $tab) {
            $badges = Repository::badges(['active_only' => true, 'limit' => 0]);
            $grant_total = Repository::grant_count($grant_filters);
            $grant_page = min($grant_page, max(1, (int) ceil($grant_total / $per_page)));
            $grants = Repository::grants(array_merge([
                'limit' => $per_page,
                'offset' => ($grant_page - 1) * $per_page,
            ], $grant_filters));
        }
        ?>
        <div class="wrap mds3-admin mds-support-passport-admin">
            <div class="mds-extension-heading-row">
                <h1><?php esc_html_e('Proof of Support Passport', 'mds-support-passport'); ?></h1>
                <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::docs_button('mds-support-passport')); ?>
            </div>
            <hr class="wp-header-end">
            <?php $this->admin_notices(); ?>
            <p class="description"><?php esc_html_e('Issue local, privacy-first support badges and proof pages. Supporters are private by default unless public display consent is recorded.', 'mds-support-passport'); ?></p>
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Support Passport sections', 'mds-support-passport'); ?>">
                <?php foreach ([
                    'grants' => __('Grants', 'mds-support-passport'),
                    'overview' => __('Overview', 'mds-support-passport'),
                    'badges' => __('Badges', 'mds-support-passport'),
                    'privacy' => __('Consent & Privacy', 'mds-support-passport'),
                    'settings' => __('Settings', 'mds-support-passport'),
                ] as $key => $label) : ?>
                    <a class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => $key], admin_url('admin.php'))); ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php
            if ('overview' === $tab) {
                $this->render_overview($counts, $settings);
            } elseif ('badges' === $tab) {
                $this->render_badges($badges, $badge_total, $badge_page, $per_page);
            } elseif ('grants' === $tab) {
                $this->render_grants($badges, $grants, $grant_filters, $grant_total, $grant_page, $per_page);
            } elseif ('privacy' === $tab) {
                $this->render_privacy($counts);
            } else {
                $this->render_settings($settings);
            }
            ?>
        </div>
        <?php
    }

    private function grant_filters_from_request() {
        return [
            'search' => sanitize_text_field($this->request_query_scalar('grant_search')),
            'public_display_state' => sanitize_key($this->request_query_scalar('grant_public_state')),
            'source_extension' => sanitize_key($this->request_query_scalar('grant_source')),
            'revoked' => sanitize_key($this->request_query_scalar('grant_revoked')),
        ];
    }

    private function request_query_scalar($key) {
        if (!isset($_GET[$key])) {
            return '';
        }

        $value = wp_unslash($_GET[$key]);

        return is_scalar($value) ? (string) $value : '';
    }

    private function admin_notices() {
        $message = sanitize_key((string) ($_GET['mds_support_passport_status'] ?? ''));
        $error = sanitize_text_field((string) ($_GET['mds_support_passport_error'] ?? ''));
        if ($error) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p></div>';
        }
        if (!$message) {
            $this->last_grant_notice();
            return;
        }

        $labels = [
            'badge_saved' => __('Badge saved.', 'mds-support-passport'),
            'badge_deleted' => __('Badge deleted.', 'mds-support-passport'),
            'badge_archived' => __('Badge archived. Existing grants were preserved.', 'mds-support-passport'),
            'badge_status_updated' => __('Badge status updated.', 'mds-support-passport'),
            'grant_created' => __('Badge granted.', 'mds-support-passport'),
            'grant_saved' => __('Grant updated.', 'mds-support-passport'),
            'grant_revoked' => __('Badge grant revoked.', 'mds-support-passport'),
            'grant_archived' => __('Grant archived. Audit and consent records were preserved.', 'mds-support-passport'),
            'manage_link_created' => __('A fresh private manage link was created.', 'mds-support-passport'),
            'proof_link_created' => __('A fresh proof link was created.', 'mds-support-passport'),
            'settings_saved' => __('Settings saved. Visit Settings > Permalinks if proof links do not resolve immediately.', 'mds-support-passport'),
        ];
        if (empty($labels[$message])) {
            return;
        }

        echo '<div class="notice notice-success inline"><p>' . esc_html($labels[$message]) . '</p></div>';
        $this->last_grant_notice();
    }

    private function last_grant_notice() {
        $notice = get_transient('mds_support_passport_last_grant_' . get_current_user_id());
        if (!is_array($notice) || (empty($notice['manage_url']) && empty($notice['proof_url']))) {
            return;
        }

        delete_transient('mds_support_passport_last_grant_' . get_current_user_id());
        echo '<div class="notice notice-info inline"><p>';
        echo esc_html__('Support Passport links were created. Treat private manage links like account access links. Copy them now because private tokens are not shown again after this notice.', 'mds-support-passport');
        echo '</p><div class="mds-support-passport-link-list">';
        if (!empty($notice['manage_url'])) {
            $this->render_generated_link_row(__('Manage link', 'mds-support-passport'), (string) $notice['manage_url'], __('Open manage link', 'mds-support-passport'));
        }
        if (!empty($notice['proof_url'])) {
            $this->render_generated_link_row(__('Proof link', 'mds-support-passport'), (string) $notice['proof_url'], __('Open proof link', 'mds-support-passport'));
        }
        echo '</div></div>';
    }

    private function render_generated_link_row($label, $url, $open_label) {
        $url = esc_url($url);
        if (!$url) {
            return;
        }

        echo '<div class="mds-support-passport-link-row">';
        echo '<label><span>' . esc_html($label) . '</span><input type="text" readonly value="' . esc_attr($url) . '" /></label>';
        echo '<div class="mds-support-passport-actions">';
        echo '<a class="button button-small" href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html($open_label) . '</a>';
        echo '<button type="button" class="button button-small mds-support-passport-copy" data-copy="' . esc_attr($url) . '">' . esc_html__('Copy', 'mds-support-passport') . '</button>';
        echo '</div>';
        echo '</div>';
    }

    private function render_overview(array $counts, array $settings) {
        ?>
        <section class="mds3-card">
            <h2><?php esc_html_e('Local Support Proofs', 'mds-support-passport'); ?></h2>
            <div class="mds-support-passport-metrics">
                <?php foreach ([
                    'profiles' => __('Profiles', 'mds-support-passport'),
                    'badges' => __('Badges', 'mds-support-passport'),
                    'grants' => __('Grants', 'mds-support-passport'),
                    'public_grants' => __('Public grants', 'mds-support-passport'),
                    'revoked_grants' => __('Revoked', 'mds-support-passport'),
                ] as $key => $label) : ?>
                    <div><strong><?php echo esc_html(number_format_i18n(absint($counts[$key] ?? 0))); ?></strong><span><?php echo esc_html($label); ?></span></div>
                <?php endforeach; ?>
            </div>
            <p><?php esc_html_e('Hosted and cross-site identity mode is intentionally disabled. This extension stores support proofs only on this WordPress site.', 'mds-support-passport'); ?></p>
            <div class="mds3-button-row">
                <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'grants'], admin_url('admin.php'))); ?>"><?php esc_html_e('Grant a badge', 'mds-support-passport'); ?></a>
                <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'badges'], admin_url('admin.php'))); ?>"><?php esc_html_e('Manage badges', 'mds-support-passport'); ?></a>
            </div>
        </section>
        <section class="mds3-card">
            <h2><?php esc_html_e('How Support Passport Works', 'mds-support-passport'); ?></h2>
            <ol class="mds-support-passport-steps">
                <li><strong><?php esc_html_e('Create badge types.', 'mds-support-passport'); ?></strong> <?php esc_html_e('Define the local badges this site can grant, such as Founding Supporter or Community Sponsor.', 'mds-support-passport'); ?></li>
                <li><strong><?php esc_html_e('Grant a badge.', 'mds-support-passport'); ?></strong> <?php esc_html_e('Attach a badge to a supporter profile after a qualifying purchase, campaign action, or manual review.', 'mds-support-passport'); ?></li>
                <li><strong><?php esc_html_e('Share private controls.', 'mds-support-passport'); ?></strong> <?php esc_html_e('A private manage link lets the supporter choose a display name and opt each proof public or private.', 'mds-support-passport'); ?></li>
                <li><strong><?php esc_html_e('Show public proof only by consent.', 'mds-support-passport'); ?></strong> <?php esc_html_e('Public proof pages and the directory only show badges that the supporter opted into displaying.', 'mds-support-passport'); ?></li>
            </ol>
            <div class="mds-support-passport-page-links">
                <?php $this->render_page_link_by_path('support-badges', __('Support Badges page', 'mds-support-passport')); ?>
                <?php $this->render_page_link_by_path('supporter-directory', __('Supporter Directory page', 'mds-support-passport')); ?>
            </div>
        </section>
        <section class="mds3-card">
            <h2><?php esc_html_e('Public Display Defaults', 'mds-support-passport'); ?></h2>
            <ul class="mds-support-passport-checklist">
                <li><?php esc_html_e('New profiles are private by default.', 'mds-support-passport'); ?></li>
                <li><?php esc_html_e('Public proof pages only show badge/supporter details after public display consent.', 'mds-support-passport'); ?></li>
                <li><?php esc_html_e('Directory display is disabled unless you enable it in settings and supporters opt in.', 'mds-support-passport'); ?></li>
                <li><?php esc_html_e('Email addresses are matched by hash and encrypted when local OpenSSL support is available.', 'mds-support-passport'); ?></li>
            </ul>
            <p><?php echo esc_html(sprintf(__('Proof URL base: /%s/{token}/', 'mds-support-passport'), sanitize_title((string) ($settings['proof_slug'] ?? 'support-proof')))); ?></p>
        </section>
        <section class="mds3-card">
            <h2><?php esc_html_e('Blocks and Shortcodes', 'mds-support-passport'); ?></h2>
            <p class="description"><?php esc_html_e('Use the WordPress editor blocks when possible. Shortcodes remain available for classic editors, widgets, and theme templates.', 'mds-support-passport'); ?></p>
            <div class="mds-support-passport-resource-grid">
                <?php $this->render_resource_card(__('Support Badges', 'mds-support-passport'), __('Block', 'mds-support-passport'), __('Shows active badge definitions without supporter data.', 'mds-support-passport'), 'mds-support-passport/badges'); ?>
                <?php $this->render_resource_card(__('Support Badges', 'mds-support-passport'), __('Shortcode', 'mds-support-passport'), __('Classic shortcode for the public badge list.', 'mds-support-passport'), '[mds_support_passport_badges]'); ?>
                <?php $this->render_resource_card(__('Supporter Directory', 'mds-support-passport'), __('Block', 'mds-support-passport'), __('Shows supporters who explicitly opted into public display.', 'mds-support-passport'), 'mds-support-passport/directory'); ?>
                <?php $this->render_resource_card(__('Supporter Directory', 'mds-support-passport'), __('Shortcode', 'mds-support-passport'), __('Classic shortcode for the opt-in supporter directory.', 'mds-support-passport'), '[mds_support_passport_directory]'); ?>
            </div>
        </section>
        <?php
    }

    private function render_resource_card($title, $type, $description, $value) {
        echo '<article class="mds-support-passport-resource-card">';
        echo '<span>' . esc_html($type) . '</span>';
        echo '<strong>' . esc_html($title) . '</strong>';
        echo '<p>' . esc_html($description) . '</p>';
        echo '<code>' . esc_html($value) . '</code>';
        echo '<button type="button" class="button button-small mds-support-passport-copy" data-copy="' . esc_attr($value) . '">' . esc_html__('Copy', 'mds-support-passport') . '</button>';
        echo '</article>';
    }

    private function render_page_link_by_path($path, $label) {
        $page = get_page_by_path((string) $path);
        if (!$page || 'publish' !== get_post_status($page)) {
            echo '<span class="mds-support-passport-page-pill is-missing">' . esc_html($label) . ': ' . esc_html__('not created yet', 'mds-support-passport') . '</span>';
            return;
        }

        echo '<a class="mds-support-passport-page-pill" href="' . esc_url(get_permalink($page)) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '</a>';
    }

    private function badge_visual(array $badge, $extra_class = '') {
        $classes = trim('mds-support-passport-badge-visual is-' . sanitize_html_class((string) ($badge['color_token'] ?? 'blue')) . ' ' . sanitize_html_class((string) $extra_class));
        $attachment_id = absint($badge['icon_attachment_id'] ?? 0);
        if ($attachment_id) {
            $image = wp_get_attachment_image($attachment_id, 'thumbnail', false, [
                'class' => 'mds-support-passport-badge-image',
                'alt' => '',
                'loading' => 'lazy',
            ]);
            if ($image) {
                return '<span class="' . esc_attr($classes) . '">' . $image . '</span>';
            }
        }

        $title = sanitize_text_field((string) ($badge['title'] ?? $badge['badge_title'] ?? ''));
        $initial = strtoupper(substr($title ?: 'S', 0, 1));

        return '<span class="' . esc_attr($classes) . '" aria-hidden="true">' . esc_html($initial) . '</span>';
    }

    private function render_badges(array $badges, $total, $current_page, $per_page) {
        $edit_badge_id = absint($_GET['edit_badge'] ?? 0);
        $edit_badge = $edit_badge_id ? Repository::badge($edit_badge_id) : null;
        $creating_badge = !empty($_GET['new_badge']);
        $show_badge_form = $creating_badge || $edit_badge;
        $form_badge = $edit_badge ?: [
            'id' => 0,
            'title' => '',
            'description' => '',
            'icon_attachment_id' => 0,
            'color_token' => 'blue',
            'eligibility_source' => 'manual',
            'eligibility_note' => '',
            'status' => 'active',
        ];
        ?>
        <?php if ($show_badge_form) : ?>
            <section class="mds3-card">
                <h2><?php echo $edit_badge ? esc_html__('Edit Badge', 'mds-support-passport') : esc_html__('Create Badge', 'mds-support-passport'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-support-passport-form">
                    <?php wp_nonce_field('mds_support_passport_save_badge' . ($edit_badge ? '_' . absint($edit_badge['id']) : '')); ?>
                    <input type="hidden" name="action" value="mds_support_passport_save_badge" />
                    <input type="hidden" name="badge_id" value="<?php echo esc_attr(absint($form_badge['id'])); ?>" />
                    <label><span><?php esc_html_e('Title', 'mds-support-passport'); ?></span><input type="text" name="title" value="<?php echo esc_attr($form_badge['title']); ?>" required /></label>
                    <label><span><?php esc_html_e('Description', 'mds-support-passport'); ?></span><textarea name="description" rows="3"><?php echo esc_textarea($form_badge['description']); ?></textarea></label>
                    <div class="mds-support-passport-media-field" data-mds-support-passport-media>
                        <label><span><?php esc_html_e('Badge image', 'mds-support-passport'); ?></span></label>
                        <div class="mds-support-passport-media-preview" data-mds-support-passport-media-preview>
                            <?php echo $this->badge_visual($form_badge, 'is-preview'); ?>
                        </div>
                        <input type="number" name="icon_attachment_id" min="0" value="<?php echo esc_attr(absint($form_badge['icon_attachment_id'])); ?>" data-mds-support-passport-media-id />
                        <div class="mds-support-passport-actions">
                            <button type="button" class="button" data-mds-support-passport-select-media><?php esc_html_e('Choose image', 'mds-support-passport'); ?></button>
                            <button type="button" class="button" data-mds-support-passport-clear-media><?php esc_html_e('Clear image', 'mds-support-passport'); ?></button>
                        </div>
                        <small><?php esc_html_e('Optional square image from the WordPress media library. If no image is set, the badge uses an initial with the selected color.', 'mds-support-passport'); ?></small>
                    </div>
                    <label><span><?php esc_html_e('Color', 'mds-support-passport'); ?></span><select name="color_token"><?php foreach (['blue' => __('Blue', 'mds-support-passport'), 'green' => __('Green', 'mds-support-passport'), 'gold' => __('Gold', 'mds-support-passport'), 'purple' => __('Purple', 'mds-support-passport'), 'slate' => __('Slate', 'mds-support-passport')] as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($value, $form_badge['color_token']); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                    <label><span><?php esc_html_e('Eligibility source', 'mds-support-passport'); ?></span><input type="text" name="eligibility_source" value="<?php echo esc_attr($form_badge['eligibility_source']); ?>" /><small><?php esc_html_e('Examples: manual, grid, sponsorboard, launch-wall, missions.', 'mds-support-passport'); ?></small></label>
                    <label><span><?php esc_html_e('Eligibility note', 'mds-support-passport'); ?></span><textarea name="eligibility_note" rows="2"><?php echo esc_textarea($form_badge['eligibility_note']); ?></textarea></label>
                    <label><span><?php esc_html_e('Show this note on the public badge page', 'mds-support-passport'); ?></span><input type="checkbox" name="public_eligibility" value="1" <?php checked(!empty($form_badge['public_eligibility'])); ?> /><small><?php esc_html_e('Unless this is ticked, the note is for site administrators only.', 'mds-support-passport'); ?></small></label>
                    <label><span><?php esc_html_e('Status', 'mds-support-passport'); ?></span><select name="status"><?php foreach (['active' => __('Active', 'mds-support-passport'), 'inactive' => __('Inactive', 'mds-support-passport'), 'archived' => __('Archived', 'mds-support-passport')] as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($value, $form_badge['status']); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                    <div class="mds-support-passport-actions">
                        <button type="submit" class="button button-primary"><?php echo $edit_badge ? esc_html__('Update badge', 'mds-support-passport') : esc_html__('Save badge', 'mds-support-passport'); ?></button>
                        <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'badges'], admin_url('admin.php'))); ?>"><?php echo $edit_badge ? esc_html__('Cancel edit', 'mds-support-passport') : esc_html__('Cancel', 'mds-support-passport'); ?></a>
                    </div>
                </form>
            </section>
        <?php endif; ?>
        <section class="mds3-card">
            <div class="mds-support-passport-section-header">
                <h2><?php esc_html_e('Badges', 'mds-support-passport'); ?></h2>
                <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'badges', 'new_badge' => 1], admin_url('admin.php'))); ?>"><?php esc_html_e('New Badge', 'mds-support-passport'); ?></a>
            </div>
            <?php if (!$badges) : ?>
                <p><?php esc_html_e('No badges have been created yet.', 'mds-support-passport'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Badge', 'mds-support-passport'); ?></th><th><?php esc_html_e('Source', 'mds-support-passport'); ?></th><th><?php esc_html_e('Grants', 'mds-support-passport'); ?></th><th><?php esc_html_e('Status', 'mds-support-passport'); ?></th><th><?php esc_html_e('Actions', 'mds-support-passport'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($badges as $badge) : ?>
                        <?php $grant_count = Repository::badge_grant_count(absint($badge['id'])); ?>
                        <tr>
                            <td>
                                <div class="mds-support-passport-badge-cell">
                                    <?php echo $this->badge_visual($badge); ?>
                                    <div>
                                        <span class="mds-support-passport-badge is-<?php echo esc_attr($badge['color_token']); ?>"><?php echo esc_html($badge['title']); ?></span>
                                        <p class="description"><?php echo esc_html($badge['description']); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo esc_html($badge['eligibility_source']); ?></td>
                            <td><?php echo esc_html(number_format_i18n($grant_count)); ?></td>
                            <td><?php echo esc_html($badge['status']); ?></td>
                            <td>
                                <div class="mds-support-passport-actions">
                                    <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'badges', 'edit_badge' => absint($badge['id'])], admin_url('admin.php'))); ?>"><?php esc_html_e('Edit', 'mds-support-passport'); ?></a>
                                    <?php if ('archived' !== $badge['status']) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('mds_support_passport_update_badge_status_' . absint($badge['id'])); ?>
                                            <input type="hidden" name="action" value="mds_support_passport_update_badge_status" />
                                            <input type="hidden" name="badge_id" value="<?php echo esc_attr(absint($badge['id'])); ?>" />
                                            <input type="hidden" name="status" value="archived" />
                                            <button type="submit" class="button button-small"><?php esc_html_e('Archive', 'mds-support-passport'); ?></button>
                                        </form>
                                    <?php else : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('mds_support_passport_update_badge_status_' . absint($badge['id'])); ?>
                                            <input type="hidden" name="action" value="mds_support_passport_update_badge_status" />
                                            <input type="hidden" name="badge_id" value="<?php echo esc_attr(absint($badge['id'])); ?>" />
                                            <input type="hidden" name="status" value="active" />
                                            <button type="submit" class="button button-small"><?php esc_html_e('Restore', 'mds-support-passport'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($grant_count < 1) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this unused badge? This cannot be undone.', 'mds-support-passport')); ?>');">
                                            <?php wp_nonce_field('mds_support_passport_delete_badge_' . absint($badge['id'])); ?>
                                            <input type="hidden" name="action" value="mds_support_passport_delete_badge" />
                                            <input type="hidden" name="badge_id" value="<?php echo esc_attr(absint($badge['id'])); ?>" />
                                            <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Delete', 'mds-support-passport'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php $this->render_admin_pagination($total, $per_page, $current_page, 'badge_page', ['tab' => 'badges']); ?>
            <?php endif; ?>
        </section>
        <?php
    }

    private function render_grants(array $badges, array $grants, array $filters, $total, $current_page, $per_page) {
        $edit_grant_id = absint($_GET['edit_grant'] ?? 0);
        $edit_grant = $edit_grant_id ? Repository::grant($edit_grant_id) : null;
        $creating_grant = !empty($_GET['new_grant']);
        ?>
        <?php if ($creating_grant) : ?>
            <section class="mds3-card">
                <h2><?php esc_html_e('Grant Badge', 'mds-support-passport'); ?></h2>
                <?php if (!$badges) : ?>
                    <p><?php esc_html_e('Create a badge before granting one to a supporter.', 'mds-support-passport'); ?></p>
                    <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'badges', 'new_badge' => 1], admin_url('admin.php'))); ?>"><?php esc_html_e('New Badge', 'mds-support-passport'); ?></a></p>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-support-passport-form">
                        <?php wp_nonce_field('mds_support_passport_grant_badge'); ?>
                        <input type="hidden" name="action" value="mds_support_passport_grant_badge" />
                        <label><span><?php esc_html_e('Badge', 'mds-support-passport'); ?></span><select name="badge_id" required><?php foreach ($badges as $badge) : ?><option value="<?php echo esc_attr($badge['id']); ?>"><?php echo esc_html($badge['title']); ?></option><?php endforeach; ?></select></label>
                        <label><span><?php esc_html_e('Supporter email', 'mds-support-passport'); ?></span><input type="email" name="email" /><small><?php esc_html_e('Used for local profile matching and WordPress privacy export/erase requests.', 'mds-support-passport'); ?></small></label>
                        <label><span><?php esc_html_e('Display name', 'mds-support-passport'); ?></span><input type="text" name="display_name" /><small><?php esc_html_e('Only shown publicly if public display consent is granted.', 'mds-support-passport'); ?></small></label>
                        <label><span><?php esc_html_e('Public handle', 'mds-support-passport'); ?></span><input type="text" name="public_handle" /><small><?php esc_html_e('Optional pseudonymous handle, such as founding-supporter.', 'mds-support-passport'); ?></small></label>
                        <label><span><?php esc_html_e('Source extension', 'mds-support-passport'); ?></span><input type="text" name="source_extension" value="manual" /></label>
                        <label><span><?php esc_html_e('Source entity type', 'mds-support-passport'); ?></span><input type="text" name="source_entity_type" placeholder="order" /></label>
                        <label><span><?php esc_html_e('Source entity ID', 'mds-support-passport'); ?></span><input type="text" name="source_entity_id" /></label>
                        <label><span><?php esc_html_e('Order/reference', 'mds-support-passport'); ?></span><input type="text" name="order_reference" /></label>
                        <label><span><?php esc_html_e('Expires at', 'mds-support-passport'); ?></span><input type="datetime-local" name="expires_at" /></label>
                        <label class="mds-support-passport-inline"><input type="checkbox" name="public_display" value="1" /> <span><?php esc_html_e('Supporter has authorized public display', 'mds-support-passport'); ?></span></label>
                        <small><?php esc_html_e('Enable only when the supporter has clearly agreed to public display. The authorization is recorded as an administrator-attested consent event.', 'mds-support-passport'); ?></small>
                        <div class="mds-support-passport-actions">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Grant badge', 'mds-support-passport'); ?></button>
                            <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'grants'], admin_url('admin.php'))); ?>"><?php esc_html_e('Cancel', 'mds-support-passport'); ?></a>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <?php if ($edit_grant) : ?>
            <section class="mds3-card">
                <h2><?php esc_html_e('Edit Grant', 'mds-support-passport'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-support-passport-form">
                    <?php wp_nonce_field('mds_support_passport_update_grant_' . absint($edit_grant['id'])); ?>
                    <input type="hidden" name="action" value="mds_support_passport_update_grant" />
                    <input type="hidden" name="grant_id" value="<?php echo esc_attr(absint($edit_grant['id'])); ?>" />
                    <label><span><?php esc_html_e('Display name', 'mds-support-passport'); ?></span><input type="text" name="display_name" value="<?php echo esc_attr($edit_grant['profile_display_name']); ?>" /></label>
                    <label><span><?php esc_html_e('Public handle', 'mds-support-passport'); ?></span><input type="text" name="public_handle" value="<?php echo esc_attr($edit_grant['profile_public_handle']); ?>" /></label>
                    <label><span><?php esc_html_e('Source extension', 'mds-support-passport'); ?></span><input type="text" name="source_extension" value="<?php echo esc_attr($edit_grant['source_extension']); ?>" /></label>
                    <label><span><?php esc_html_e('Source entity type', 'mds-support-passport'); ?></span><input type="text" name="source_entity_type" value="<?php echo esc_attr($edit_grant['source_entity_type']); ?>" /></label>
                    <label><span><?php esc_html_e('Source entity ID', 'mds-support-passport'); ?></span><input type="text" name="source_entity_id" value="<?php echo esc_attr($edit_grant['source_entity_id']); ?>" /></label>
                    <label><span><?php esc_html_e('Order/reference', 'mds-support-passport'); ?></span><input type="text" name="order_reference" value="<?php echo esc_attr($edit_grant['order_reference']); ?>" /></label>
                    <label><span><?php esc_html_e('Expires at', 'mds-support-passport'); ?></span><input type="datetime-local" name="expires_at" value="<?php echo esc_attr($this->datetime_local_value($edit_grant['expires_at'])); ?>" /></label>
                    <label><span><?php esc_html_e('Public display', 'mds-support-passport'); ?></span><select name="public_display_state" <?php disabled(!empty($edit_grant['revoked_at']) || 'archived' === $edit_grant['public_display_state']); ?>><option value="private" <?php selected('private', $edit_grant['public_display_state']); ?>><?php esc_html_e('Private', 'mds-support-passport'); ?></option><option value="public" <?php selected('public', $edit_grant['public_display_state']); ?>><?php esc_html_e('Public', 'mds-support-passport'); ?></option></select><small><?php esc_html_e('Public display requires supporter consent. Revoked or archived grants cannot be made public.', 'mds-support-passport'); ?></small></label>
                    <div class="mds-support-passport-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Update grant', 'mds-support-passport'); ?></button>
                        <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'grants'], admin_url('admin.php'))); ?>"><?php esc_html_e('Cancel edit', 'mds-support-passport'); ?></a>
                    </div>
                </form>
            </section>
        <?php endif; ?>
        <section class="mds3-card">
            <div class="mds-support-passport-section-header">
                <h2><?php esc_html_e('Recent Grants', 'mds-support-passport'); ?></h2>
                <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'grants', 'new_grant' => 1], admin_url('admin.php'))); ?>"><?php esc_html_e('New Grant', 'mds-support-passport'); ?></a>
            </div>
            <form class="mds-support-passport-filter-bar" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <input type="hidden" name="page" value="mds-support-passport" />
                <input type="hidden" name="tab" value="grants" />
                <label>
                    <span><?php esc_html_e('Search grants', 'mds-support-passport'); ?></span>
                    <input type="search" name="grant_search" value="<?php echo esc_attr($filters['search'] ?? ''); ?>" placeholder="<?php esc_attr_e('Badge, supporter, handle, source ID, or reference', 'mds-support-passport'); ?>" />
                </label>
                <label>
                    <span><?php esc_html_e('Public display', 'mds-support-passport'); ?></span>
                    <select name="grant_public_state">
                        <option value=""><?php esc_html_e('Any state', 'mds-support-passport'); ?></option>
                        <?php foreach (['private' => __('Private', 'mds-support-passport'), 'public' => __('Public', 'mds-support-passport'), 'archived' => __('Archived', 'mds-support-passport'), 'revoked' => __('Revoked', 'mds-support-passport')] as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $filters['public_display_state'] ?? ''); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e('Source', 'mds-support-passport'); ?></span>
                    <input type="text" name="grant_source" value="<?php echo esc_attr($filters['source_extension'] ?? ''); ?>" placeholder="<?php esc_attr_e('manual, mds-grid, launch-wall', 'mds-support-passport'); ?>" />
                </label>
                <label>
                    <span><?php esc_html_e('Revoked', 'mds-support-passport'); ?></span>
                    <select name="grant_revoked">
                        <option value=""><?php esc_html_e('Any', 'mds-support-passport'); ?></option>
                        <option value="no" <?php selected('no', $filters['revoked'] ?? ''); ?>><?php esc_html_e('Active only', 'mds-support-passport'); ?></option>
                        <option value="yes" <?php selected('yes', $filters['revoked'] ?? ''); ?>><?php esc_html_e('Revoked only', 'mds-support-passport'); ?></option>
                    </select>
                </label>
                <div class="mds-support-passport-filter-actions">
                    <button type="submit" class="button"><?php esc_html_e('Filter', 'mds-support-passport'); ?></button>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'grants'], admin_url('admin.php'))); ?>"><?php esc_html_e('Reset', 'mds-support-passport'); ?></a>
                </div>
            </form>
            <?php if (!$grants) : ?>
                <p><?php esc_html_e('No support badge grants match the current filters.', 'mds-support-passport'); ?></p>
                <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'grants', 'new_grant' => 1], admin_url('admin.php'))); ?>"><?php esc_html_e('New Grant', 'mds-support-passport'); ?></a></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Grant', 'mds-support-passport'); ?></th><th><?php esc_html_e('Supporter', 'mds-support-passport'); ?></th><th><?php esc_html_e('Public display', 'mds-support-passport'); ?></th><th><?php esc_html_e('Source', 'mds-support-passport'); ?></th><th><?php esc_html_e('Actions', 'mds-support-passport'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($grants as $grant) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($grant['badge_title']); ?></strong><br /><small><?php echo esc_html($grant['issued_at']); ?></small></td>
                            <td><?php echo esc_html($grant['supporter_name']); ?></td>
                            <td><?php echo esc_html($grant['public_display_state']); ?></td>
                            <td><?php echo esc_html($grant['source_extension']); ?><?php if ($grant['order_reference']) : ?><br /><small><?php echo esc_html($grant['order_reference']); ?></small><?php endif; ?></td>
                            <td>
                                <div class="mds-support-passport-actions">
                                    <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'grants', 'edit_grant' => absint($grant['id'])], admin_url('admin.php'))); ?>"><?php esc_html_e('Edit', 'mds-support-passport'); ?></a>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('mds_support_passport_issue_manage_link_' . absint($grant['id'])); ?>
                                        <input type="hidden" name="action" value="mds_support_passport_issue_manage_link" />
                                        <input type="hidden" name="grant_id" value="<?php echo esc_attr(absint($grant['id'])); ?>" />
                                        <button type="submit" class="button button-small"><?php esc_html_e('New manage link', 'mds-support-passport'); ?></button>
                                    </form>
                                    <?php if (empty($grant['revoked_at']) && 'archived' !== $grant['public_display_state']) : ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                            <?php wp_nonce_field('mds_support_passport_issue_proof_link_' . absint($grant['id'])); ?>
                                            <input type="hidden" name="action" value="mds_support_passport_issue_proof_link" />
                                            <input type="hidden" name="grant_id" value="<?php echo esc_attr(absint($grant['id'])); ?>" />
                                            <button type="submit" class="button button-small"><?php esc_html_e('New proof link', 'mds-support-passport'); ?></button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Archive this grant? Public proof display will be disabled but audit records will remain.', 'mds-support-passport')); ?>');">
                                            <?php wp_nonce_field('mds_support_passport_archive_grant_' . absint($grant['id'])); ?>
                                            <input type="hidden" name="action" value="mds_support_passport_archive_grant" />
                                            <input type="hidden" name="grant_id" value="<?php echo esc_attr(absint($grant['id'])); ?>" />
                                            <button type="submit" class="button button-small"><?php esc_html_e('Archive', 'mds-support-passport'); ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (empty($grant['revoked_at'])) : ?>
                                        <details class="mds-support-passport-revoke">
                                            <summary class="button button-small button-link-delete"><?php esc_html_e('Revoke', 'mds-support-passport'); ?></summary>
                                            <div class="mds-support-passport-revoke-panel">
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-support-passport-revoke-form" onsubmit="return confirm('<?php echo esc_js(__('Revoke this grant? Public proof display will be disabled and the audit record will remain.', 'mds-support-passport')); ?>');">
                                                    <?php wp_nonce_field('mds_support_passport_revoke_grant_' . absint($grant['id'])); ?>
                                                    <input type="hidden" name="action" value="mds_support_passport_revoke_grant" />
                                                    <input type="hidden" name="grant_id" value="<?php echo esc_attr(absint($grant['id'])); ?>" />
                                                    <label>
                                                        <span><?php esc_html_e('Reason', 'mds-support-passport'); ?></span>
                                                        <input type="text" name="reason" placeholder="<?php esc_attr_e('Optional internal note', 'mds-support-passport'); ?>" />
                                                    </label>
                                                    <p class="description"><?php esc_html_e('Stored privately with the revocation record.', 'mds-support-passport'); ?></p>
                                                    <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Confirm revoke', 'mds-support-passport'); ?></button>
                                                </form>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($grant['revoked_at'])) : ?>
                                    <?php esc_html_e('Revoked', 'mds-support-passport'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
                $pagination_args = array_filter([
                    'tab' => 'grants',
                    'grant_search' => $filters['search'] ?? '',
                    'grant_public_state' => $filters['public_display_state'] ?? '',
                    'grant_source' => $filters['source_extension'] ?? '',
                    'grant_revoked' => $filters['revoked'] ?? '',
                ], static function ($value) {
                    return '' !== (string) $value;
                });
                $this->render_admin_pagination($total, $per_page, $current_page, 'grant_page', $pagination_args);
                ?>
            <?php endif; ?>
        </section>
        <?php
    }

    private function render_admin_pagination($total, $per_page, $current_page, $page_arg, array $query_args) {
        $total = absint($total);
        $per_page = max(1, absint($per_page));
        $total_pages = (int) ceil($total / $per_page);
        if ($total_pages < 2) {
            return;
        }

        $base_url = add_query_arg(array_merge(['page' => 'mds-support-passport'], $query_args), admin_url('admin.php'));
        $base = str_replace('%25%23%25', '%#%', add_query_arg($page_arg, '%#%', $base_url));
        $links = paginate_links([
            'base' => $base,
            'format' => '',
            'current' => max(1, absint($current_page)),
            'total' => $total_pages,
            'type' => 'list',
            'prev_text' => __('Previous', 'mds-support-passport'),
            'next_text' => __('Next', 'mds-support-passport'),
        ]);
        if (!$links) {
            return;
        }

        echo '<nav class="mds-support-passport-pagination" aria-label="' . esc_attr__('List pagination', 'mds-support-passport') . '">';
        echo '<p>' . esc_html(sprintf(
            __('Showing %1$s-%2$s of %3$s', 'mds-support-passport'),
            number_format_i18n((max(1, absint($current_page)) - 1) * $per_page + 1),
            number_format_i18n(min($total, max(1, absint($current_page)) * $per_page)),
            number_format_i18n($total)
        )) . '</p>';
        echo wp_kses_post($links);
        echo '</nav>';
    }

    private function render_privacy(array $counts) {
        ?>
        <section class="mds3-card">
            <h2><?php esc_html_e('Consent & Privacy', 'mds-support-passport'); ?></h2>
            <p><?php esc_html_e('Consent records are append-only. Public display can be withdrawn by setting profiles/private grants back to private or by using WordPress personal data erasure tools for a supporter email address.', 'mds-support-passport'); ?></p>
            <ul class="mds-support-passport-checklist">
                <li><?php esc_html_e('WordPress personal data exporter is registered for support passport profiles, grants, and consent.', 'mds-support-passport'); ?></li>
                <li><?php esc_html_e('WordPress personal data eraser anonymizes profile identifiers and hides public grants/proofs.', 'mds-support-passport'); ?></li>
                <li><?php esc_html_e('Minimal audit records may be retained for revocation, fraud, disputes, security, and compliance.', 'mds-support-passport'); ?></li>
            </ul>
            <p><?php echo esc_html(sprintf(__('Current private/default profile count: %s profiles.', 'mds-support-passport'), number_format_i18n(absint($counts['profiles'] ?? 0)))); ?></p>
        </section>
        <section class="mds3-card">
            <h2><?php esc_html_e('Hosted Mode Disabled', 'mds-support-passport'); ?></h2>
            <p><?php esc_html_e('Cross-site recognition, hosted accounts, centralized supporter identity, and shared reputation are not enabled in this version. Those features require separate privacy, legal, security, and support review.', 'mds-support-passport'); ?></p>
        </section>
        <?php
    }

    private function render_settings(array $settings) {
        $badges = Repository::badges(['active_only' => true, 'limit' => 200]);
        ?>
        <section class="mds3-card">
            <h2><?php esc_html_e('Settings', 'mds-support-passport'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-support-passport-form">
                <?php wp_nonce_field('mds_support_passport_save_settings'); ?>
                <input type="hidden" name="action" value="mds_support_passport_save_settings" />
                <label class="mds-support-passport-inline"><input type="checkbox" name="directory_enabled" value="1" <?php checked('1', $settings['directory_enabled']); ?> /> <span><?php esc_html_e('Enable public supporter directory', 'mds-support-passport'); ?></span></label>
                <label class="mds-support-passport-inline"><input type="checkbox" name="directory_search_enabled" value="1" <?php checked('1', $settings['directory_search_enabled']); ?> /> <span><?php esc_html_e('Enable directory search and filters', 'mds-support-passport'); ?></span></label>
                <fieldset class="mds-support-passport-fieldset">
                    <legend><?php esc_html_e('Automatic Grants', 'mds-support-passport'); ?></legend>
                    <p class="description"><?php esc_html_e('Automatically grant a Support Passport badge when a Million Dollar Script grid order is marked paid. Public display still requires supporter opt-in.', 'mds-support-passport'); ?></p>
                    <label>
                        <span><?php esc_html_e('Paid grid order badge', 'mds-support-passport'); ?></span>
                        <select name="grid_purchase_badge_id">
                            <option value="0"><?php esc_html_e('Do not grant a badge automatically', 'mds-support-passport'); ?></option>
                            <?php foreach ($badges as $badge) : ?>
                                <option value="<?php echo esc_attr(absint($badge['id'])); ?>" <?php selected(absint($settings['grid_purchase_badge_id'] ?? 0), absint($badge['id'])); ?>><?php echo esc_html($badge['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small><?php esc_html_e('Duplicate grants are prevented per Million Dollar Script order ID. Create the badge first if it is not listed here.', 'mds-support-passport'); ?></small>
                    </label>
                    <label class="mds-support-passport-inline"><input type="checkbox" name="email_manage_link_on_auto_grant" value="1" <?php checked('1', (string) ($settings['email_manage_link_on_auto_grant'] ?? '1')); ?> /> <span><?php esc_html_e('Email the private manage link after automatic grants', 'mds-support-passport'); ?></span></label>
                    <small><?php esc_html_e('Uses direct WordPress mail. Install an SMTP or email logging plugin if this site needs delivery routing or logs.', 'mds-support-passport'); ?></small>
                </fieldset>
                <label><span><?php esc_html_e('Proof URL base', 'mds-support-passport'); ?></span><input type="text" name="proof_slug" value="<?php echo esc_attr($settings['proof_slug']); ?>" /><small><?php esc_html_e('Changing this may require visiting Settings > Permalinks to refresh rewrite rules.', 'mds-support-passport'); ?></small></label>
                <label><span><?php esc_html_e('Private profile retention months', 'mds-support-passport'); ?></span><input type="number" name="retention_profile_months" min="1" max="120" value="<?php echo esc_attr(absint($settings['retention_profile_months'])); ?>" /><small><?php esc_html_e('Daily cleanup anonymizes stale, private, unowned profiles only after every grant is expired, revoked, or archived. Active supporter access is preserved.', 'mds-support-passport'); ?></small></label>
                <label><span><?php esc_html_e('Audit retention years', 'mds-support-passport'); ?></span><input type="number" name="retention_audit_years" min="1" max="20" value="<?php echo esc_attr(absint($settings['retention_audit_years'])); ?>" /><small><?php esc_html_e('Daily cleanup removes audit rows older than this period. WordPress cron must be able to run scheduled events.', 'mds-support-passport'); ?></small></label>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save settings', 'mds-support-passport'); ?></button>
            </form>
        </section>
        <?php
    }

    public function handle_save_badge() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-support-passport'));
        }
        $badge_id = absint($_POST['badge_id'] ?? 0);
        check_admin_referer('mds_support_passport_save_badge' . ($badge_id ? '_' . $badge_id : ''));
        Repository::save_badge([
            'title' => wp_unslash($_POST['title'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
            'icon_attachment_id' => absint($_POST['icon_attachment_id'] ?? 0),
            'color_token' => wp_unslash($_POST['color_token'] ?? 'blue'),
            'eligibility_source' => wp_unslash($_POST['eligibility_source'] ?? 'manual'),
            'eligibility_note' => wp_unslash($_POST['eligibility_note'] ?? ''),
            'public_eligibility' => !empty($_POST['public_eligibility']),
            'status' => wp_unslash($_POST['status'] ?? 'active'),
        ], $badge_id);
        $this->redirect('badges', 'badge_saved');
    }

    public function handle_delete_badge() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-support-passport'));
        }
        $badge_id = absint($_POST['badge_id'] ?? 0);
        check_admin_referer('mds_support_passport_delete_badge_' . $badge_id);

        if (Repository::badge_grant_count($badge_id) > 0) {
            Repository::update_badge_status($badge_id, 'archived');
            $this->redirect('badges', 'badge_archived');
        }

        $result = Repository::delete_badge($badge_id);
        if (is_wp_error($result)) {
            $this->redirect('badges', '', ['mds_support_passport_error' => $result->get_error_message()]);
        }

        $this->redirect('badges', 'badge_deleted');
    }

    public function handle_update_badge_status() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-support-passport'));
        }
        $badge_id = absint($_POST['badge_id'] ?? 0);
        check_admin_referer('mds_support_passport_update_badge_status_' . $badge_id);
        Repository::update_badge_status($badge_id, wp_unslash($_POST['status'] ?? 'inactive'));
        $this->redirect('badges', 'badge_status_updated');
    }

    public function handle_grant_badge() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-support-passport'));
        }
        check_admin_referer('mds_support_passport_grant_badge');
        $result = Repository::grant_badge([
            'badge_id' => absint($_POST['badge_id'] ?? 0),
            'email' => wp_unslash($_POST['email'] ?? ''),
            'display_name' => wp_unslash($_POST['display_name'] ?? ''),
            'public_handle' => wp_unslash($_POST['public_handle'] ?? ''),
            'source_extension' => wp_unslash($_POST['source_extension'] ?? 'manual'),
            'source_entity_type' => wp_unslash($_POST['source_entity_type'] ?? ''),
            'source_entity_id' => wp_unslash($_POST['source_entity_id'] ?? ''),
            'order_reference' => wp_unslash($_POST['order_reference'] ?? ''),
            'expires_at' => wp_unslash($_POST['expires_at'] ?? ''),
            'public_display' => !empty($_POST['public_display']),
        ]);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(['page' => 'mds-support-passport', 'tab' => 'grants', 'mds_support_passport_error' => $result->get_error_message()], admin_url('admin.php')));
            exit;
        }
        set_transient('mds_support_passport_last_grant_' . get_current_user_id(), [
            'manage_url' => $this->manage_url(absint($result['profile_id'] ?? 0), (string) ($result['manage_token'] ?? '')),
            'proof_url' => $this->proof_url((string) ($result['proof_token'] ?? '')),
        ], 10 * MINUTE_IN_SECONDS);
        $this->redirect('grants', 'grant_created');
    }

    public function handle_update_grant() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-support-passport'));
        }
        $grant_id = absint($_POST['grant_id'] ?? 0);
        check_admin_referer('mds_support_passport_update_grant_' . $grant_id);
        $result = Repository::update_grant($grant_id, [
            'display_name' => wp_unslash($_POST['display_name'] ?? ''),
            'public_handle' => wp_unslash($_POST['public_handle'] ?? ''),
            'source_extension' => wp_unslash($_POST['source_extension'] ?? 'manual'),
            'source_entity_type' => wp_unslash($_POST['source_entity_type'] ?? ''),
            'source_entity_id' => wp_unslash($_POST['source_entity_id'] ?? ''),
            'order_reference' => wp_unslash($_POST['order_reference'] ?? ''),
            'expires_at' => wp_unslash($_POST['expires_at'] ?? ''),
            'public_display_state' => wp_unslash($_POST['public_display_state'] ?? ''),
        ]);
        if (is_wp_error($result)) {
            $this->redirect('grants', '', ['mds_support_passport_error' => $result->get_error_message()]);
        }
        $this->redirect('grants', 'grant_saved');
    }

    public function handle_revoke_grant() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-support-passport'));
        }
        $grant_id = absint($_POST['grant_id'] ?? 0);
        check_admin_referer('mds_support_passport_revoke_grant_' . $grant_id);
        Repository::revoke_grant($grant_id, wp_unslash($_POST['reason'] ?? ''));
        $this->redirect('grants', 'grant_revoked');
    }

    public function handle_archive_grant() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-support-passport'));
        }
        $grant_id = absint($_POST['grant_id'] ?? 0);
        check_admin_referer('mds_support_passport_archive_grant_' . $grant_id);
        Repository::archive_grant($grant_id);
        $this->redirect('grants', 'grant_archived');
    }

    public function handle_issue_manage_link() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-support-passport'));
        }
        $grant_id = absint($_POST['grant_id'] ?? 0);
        check_admin_referer('mds_support_passport_issue_manage_link_' . $grant_id);
        $grant = Repository::grant($grant_id);
        if (!$grant) {
            $this->redirect('grants', '', ['mds_support_passport_error' => __('Choose a grant before creating a manage link.', 'mds-support-passport')]);
        }
        $token = Repository::issue_manage_token(absint($grant['profile_id']));
        $this->redirect('grants', 'manage_link_created', [], [
            'manage_url' => $this->manage_url(absint($grant['profile_id']), $token),
        ]);
    }

    public function handle_issue_proof_link() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-support-passport'));
        }
        $grant_id = absint($_POST['grant_id'] ?? 0);
        check_admin_referer('mds_support_passport_issue_proof_link_' . $grant_id);
        $token = Repository::issue_proof_token($grant_id);
        if (is_wp_error($token)) {
            $this->redirect('grants', '', ['mds_support_passport_error' => $token->get_error_message()]);
        }
        $this->redirect('grants', 'proof_link_created', [], [
            'proof_url' => $this->proof_url((string) $token),
        ]);
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-support-passport'));
        }
        check_admin_referer('mds_support_passport_save_settings');
        Repository::save_settings(wp_unslash($_POST));
        self::add_rewrite_rules();
        flush_rewrite_rules(false);
        $this->redirect('settings', 'settings_saved');
    }

    public function handle_manage_profile() {
        $profile_id = absint($_POST['profile_id'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        check_admin_referer('mds_support_passport_manage_' . $profile_id);

        $profile = Repository::profile_by_manage_token($profile_id, $token);
        if (!$profile) {
            wp_die(esc_html__('This manage link is invalid or expired.', 'mds-support-passport'));
        }

        Repository::save_profile([
            'display_name' => wp_unslash($_POST['display_name'] ?? ''),
            'public_handle' => wp_unslash($_POST['public_handle'] ?? ''),
            'visibility' => $profile['visibility'],
        ], $profile_id);
        wp_safe_redirect(add_query_arg('updated', '1', $this->manage_url($profile_id, $token)));
        exit;
    }

    public function handle_manage_consent() {
        $profile_id = absint($_POST['profile_id'] ?? 0);
        $grant_id = absint($_POST['grant_id'] ?? 0);
        $token = sanitize_text_field(wp_unslash($_POST['token'] ?? ''));
        $state = sanitize_key(wp_unslash($_POST['public_display_state'] ?? 'private'));
        check_admin_referer('mds_support_passport_manage_' . $profile_id);

        $profile = Repository::profile_by_manage_token($profile_id, $token);
        if (!$profile) {
            wp_die(esc_html__('This manage link is invalid or expired.', 'mds-support-passport'));
        }

        Repository::set_grant_public_state($grant_id, $profile_id, $state, 'manage_link');
        wp_safe_redirect(add_query_arg('updated', '1', $this->manage_url($profile_id, $token)));
        exit;
    }

    public function handle_paid_payment_source($source, $source_id, array $context = []) {
        if ('mds-grid' !== sanitize_key((string) $source)) {
            return;
        }

        $this->maybe_grant_grid_purchase_badge(absint($source_id), $context);
    }

    public function handle_order_status_changed($order_id, $new_status, $old_status, array $order = [], array $before = [], array $data = []) {
        unset($before, $data);
        if ('paid' !== sanitize_key((string) $new_status) || 'paid' === sanitize_key((string) $old_status)) {
            return;
        }

        $this->maybe_grant_grid_purchase_badge(absint($order_id), [
            'source' => 'order_status_changed',
            'order' => $order,
        ]);
    }

    public function handle_source_badge_grant($data, $context = []) {
        $this->grant_source_badge(is_array($data) ? $data : [], is_array($context) ? $context : []);
    }

    public function grant_source_badge(array $data, array $context = []) {
        $badge_id = absint($data['badge_id'] ?? 0);
        if (!$badge_id) {
            $badge_id = absint(\MillionDollarScript\Core\Hooks::apply_compat('million-dollar-script/extensions/support-passport/source/badge/id', ['mds_support_passport_source_badge_id'], 0, $data, $context));
        }
        $source_extension = sanitize_key((string) ($data['source_extension'] ?? 'manual'));
        $source_entity_type = sanitize_key((string) ($data['source_entity_type'] ?? 'event'));
        $source_entity_id = sanitize_text_field((string) ($data['source_entity_id'] ?? ''));
        if (!$badge_id || !$source_extension || !$source_entity_type || '' === $source_entity_id) {
            $error = new \WP_Error('mds_support_passport_invalid_source_grant', __('Badge ID, source extension, source type, and source ID are required.', 'mds-support-passport'));
            \MillionDollarScript\Core\Hooks::do_compat('million-dollar-script/extensions/support-passport/source/grant/failed', ['mds_support_passport_source_grant_failed'], $data, $error, $context);
            return $error;
        }

        $existing = Repository::grant_for_source($source_extension, $source_entity_type, $source_entity_id);
        if ($existing) {
            \MillionDollarScript\Core\Hooks::do_compat('million-dollar-script/extensions/support-passport/source/grant/duplicate', ['mds_support_passport_source_grant_duplicate'], $existing, $data, $context);
            return $existing;
        }

        $email = sanitize_email((string) ($data['email'] ?? ''));
        $user_id = absint($data['user_id'] ?? 0);
        $user = null;
        if (!$email && $user_id) {
            $user = get_user_by('id', $user_id);
            if ($user) {
                $email = sanitize_email((string) $user->user_email);
            }
        }
        if (!$email) {
            $error = new \WP_Error('mds_support_passport_missing_email', __('An email address is required so the supporter can receive the private manage link.', 'mds-support-passport'));
            \MillionDollarScript\Core\Hooks::do_compat('million-dollar-script/extensions/support-passport/source/grant/failed', ['mds_support_passport_source_grant_failed'], $data, $error, $context);
            return $error;
        }

        if (!$user && $user_id) {
            $user = get_user_by('id', $user_id);
        }

        $display_name = sanitize_text_field((string) ($data['display_name'] ?? ''));
        if (!$display_name && $user) {
            $display_name = (string) $user->display_name;
        }
        if (!$display_name) {
            $display_name = $this->display_name_from_email($email);
        }

        $result = Repository::grant_badge([
            'badge_id' => $badge_id,
            'email' => $email,
            'display_name' => $display_name,
            'public_handle' => sanitize_title((string) ($data['public_handle'] ?? '')),
            'source_extension' => $source_extension,
            'source_entity_type' => $source_entity_type,
            'source_entity_id' => $source_entity_id,
            'order_reference' => sanitize_text_field((string) ($data['order_reference'] ?? '')),
            'expires_at' => sanitize_text_field((string) ($data['expires_at'] ?? '')),
            'public_display' => false,
        ]);
        if (is_wp_error($result)) {
            \MillionDollarScript\Core\Hooks::do_compat('million-dollar-script/extensions/support-passport/source/grant/failed', ['mds_support_passport_source_grant_failed'], $data, $result, $context);
            return $result;
        }

        $manage_url = $this->manage_url(absint($result['profile_id'] ?? 0), (string) ($result['manage_token'] ?? ''));
        $proof_url = $this->proof_url((string) ($result['proof_token'] ?? ''));
        $send_email = array_key_exists('send_email', $data) ? (bool) $data['send_email'] : true;
        if ($send_email) {
            $this->send_auto_grant_email($email, [
                'id' => absint($data['order_id'] ?? 0),
            ], $manage_url, $proof_url);
        }

        \MillionDollarScript\Core\Hooks::do_compat('million-dollar-script/extensions/support-passport/source/grant/created', ['mds_support_passport_source_grant_created'], $result, $data, $context);

        return $result;
    }

    private function maybe_grant_grid_purchase_badge($order_id, array $context = []) {
        $order_id = absint($order_id);
        $settings = Repository::settings();
        $badge_id = absint($settings['grid_purchase_badge_id'] ?? 0);
        if (!$order_id || !$badge_id) {
            return false;
        }

        if (Repository::grant_for_source('mds-grid', 'order', (string) $order_id)) {
            return false;
        }

        $order = is_array($context['order'] ?? null) ? $context['order'] : [];
        if (!$order && class_exists(Orders::class)) {
            $order = Orders::find($order_id) ?: [];
        }

        if (!$order || 'paid' !== sanitize_key((string) ($order['status'] ?? ''))) {
            return false;
        }

        $email = sanitize_email((string) ($order['email'] ?? ''));
        $user = null;
        $user_id = absint($order['user_id'] ?? 0);
        if (!$email && $user_id) {
            $user = get_user_by('id', $user_id);
            if ($user) {
                $email = sanitize_email((string) $user->user_email);
            }
        }

        if (!$email) {
            \MillionDollarScript\Core\Hooks::do_compat('million-dollar-script/extensions/support-passport/auto/grant/skipped', ['mds_support_passport_auto_grant_skipped'], $order_id, 'missing_email', $order, $context);
            return false;
        }

        if (!$user && $user_id) {
            $user = get_user_by('id', $user_id);
        }

        $display_name = $user ? (string) $user->display_name : '';
        if (!$display_name) {
            $display_name = $this->display_name_from_email($email);
        }

        $result = Repository::grant_badge([
            'badge_id' => $badge_id,
            'email' => $email,
            'display_name' => $display_name,
            'public_handle' => '',
            'source_extension' => 'mds-grid',
            'source_entity_type' => 'order',
            'source_entity_id' => (string) $order_id,
            'order_reference' => (string) ($order['order_key'] ?? ('mds-order-' . $order_id)),
            'public_display' => false,
        ]);
        if (is_wp_error($result)) {
            \MillionDollarScript\Core\Hooks::do_compat('million-dollar-script/extensions/support-passport/auto/grant/failed', ['mds_support_passport_auto_grant_failed'], $order_id, $result, $order, $context);
            return false;
        }

        $manage_url = $this->manage_url(absint($result['profile_id'] ?? 0), (string) ($result['manage_token'] ?? ''));
        $proof_url = $this->proof_url((string) ($result['proof_token'] ?? ''));
        if ('1' === (string) ($settings['email_manage_link_on_auto_grant'] ?? '1')) {
            $this->send_auto_grant_email($email, $order, $manage_url, $proof_url);
        }

        \MillionDollarScript\Core\Hooks::do_compat('million-dollar-script/extensions/support-passport/auto/grant/created', ['mds_support_passport_auto_grant_created'], $result, $order, $context);

        return $result;
    }

    private function display_name_from_email($email) {
        $email = sanitize_email((string) $email);
        if (!$email || false === strpos($email, '@')) {
            return __('Supporter', 'mds-support-passport');
        }

        $parts = explode('@', $email);
        $local = sanitize_text_field((string) ($parts[0] ?? ''));
        $local = trim(str_replace(['.', '_', '-'], ' ', $local));

        return $local ? ucwords($local) : __('Supporter', 'mds-support-passport');
    }

    private function send_auto_grant_email($email, array $order, $manage_url, $proof_url) {
        $email = sanitize_email((string) $email);
        if (!$email || !$manage_url) {
            return false;
        }

        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $order_id = absint($order['id'] ?? 0);
        $subject = sprintf(
            /* translators: %s: site name. */
            __('Your support badge on %s', 'mds-support-passport'),
            $site_name
        );
        $message = '<p>' . esc_html(sprintf(
            /* translators: %s: site name. */
            __('Thank you for supporting %s. A local support badge was added for your purchase.', 'mds-support-passport'),
            $site_name
        )) . '</p>';
        if ($order_id) {
            $message .= '<p>' . esc_html(sprintf(
                /* translators: %d: order id. */
                __('Order #%d', 'mds-support-passport'),
                $order_id
            )) . '</p>';
        }
        $message .= '<p>' . esc_html__('Your badge is private until you choose to display it publicly.', 'mds-support-passport') . '</p>';
        $message .= '<p><a href="' . esc_url($manage_url) . '">' . esc_html__('Manage your support badge display', 'mds-support-passport') . '</a></p>';
        if ($proof_url) {
            $message .= '<p>' . esc_html__('Your proof link will show public details only after you opt in:', 'mds-support-passport') . '<br><a href="' . esc_url($proof_url) . '">' . esc_html($proof_url) . '</a></p>';
        }
        $message .= '<p>' . esc_html__('This badge is local to this website and is not a transferable credential, ownership token, investment claim, or cross-site identity.', 'mds-support-passport') . '</p>';

        return wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
    }

    private function redirect($tab, $status, array $extra = [], array $link_notice = []) {
        if ($link_notice) {
            set_transient('mds_support_passport_last_grant_' . get_current_user_id(), $link_notice, 10 * MINUTE_IN_SECONDS);
        }

        $args = array_merge([
            'page' => 'mds-support-passport',
            'tab' => sanitize_key($tab),
            'mds_support_passport_status' => sanitize_key($status),
        ], $extra);
        if (!$status) {
            unset($args['mds_support_passport_status']);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function register_rest_routes() {
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/badges', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_badges'],
                'permission_callback' => '__return_true',
            ],
        ]);
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/profile', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_profile'],
                'permission_callback' => [$this, 'can_access_profile'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_save_profile'],
                'permission_callback' => [$this, 'can_access_profile'],
            ],
        ]);
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/profile/consent', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_save_consent'],
                'permission_callback' => [$this, 'can_access_profile'],
            ],
        ]);
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/grants', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_grants'],
                'permission_callback' => [$this, 'can_access_profile'],
            ],
        ]);
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/badges/(?P<id>\d+)/grant', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_grant_badge'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ],
        ]);
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/grants/(?P<id>\d+)/revoke', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_revoke_grant'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ],
        ]);
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/proofs/(?P<token>[A-Za-z0-9_.\\-]+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_proof'],
                'permission_callback' => '__return_true',
            ],
        ]);
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/export', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_export'],
                'permission_callback' => [$this, 'can_access_profile'],
            ],
        ]);
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/delete', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_delete'],
                'permission_callback' => [$this, 'can_access_profile'],
            ],
        ]);
    }

    public function rest_badges(WP_REST_Request $request) {
        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 50)));
        $page = max(1, absint($request->get_param('page') ?: 1));
        $total = Repository::badge_count(['active_only' => true]);

        return new WP_REST_Response([
            'badges' => Repository::public_badges([
                'active_only' => true,
                'limit' => $per_page,
                'offset' => ($page - 1) * $per_page,
            ]),
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        ]);
    }

    public function rest_profile(WP_REST_Request $request) {
        $profile = $this->request_profile($request);

        return $profile ? new WP_REST_Response(['profile' => $profile]) : new WP_REST_Response(['message' => __('Profile not found.', 'mds-support-passport')], 404);
    }

    public function rest_save_profile(WP_REST_Request $request) {
        $profile = $this->request_profile($request);
        $user_id = get_current_user_id();

        if (!$profile && $user_id && !current_user_can('manage_options')) {
            $user = get_userdata($user_id);
            $email = $user ? sanitize_email((string) $user->user_email) : '';
            if (!$email) {
                return new WP_REST_Response(['message' => __('Your WordPress account needs a valid email address before a support profile can be created.', 'mds-support-passport')], 400);
            }

            $profile = Repository::profile_by_email($email);
            if ($profile && absint($profile['user_id']) && absint($profile['user_id']) !== $user_id) {
                return new WP_REST_Response(['message' => __('A support profile for this account email is already owned by another WordPress account.', 'mds-support-passport')], 409);
            }
            if ($profile && !Repository::claim_profile_for_user(absint($profile['id']), $user_id, $email)) {
                return new WP_REST_Response(['message' => __('The existing support profile could not be linked to your account.', 'mds-support-passport')], 409);
            }
            if (!$profile) {
                $profile_id = Repository::save_profile([
                    'email' => $email,
                    'display_name' => $request->get_param('display_name'),
                    'public_handle' => $request->get_param('public_handle'),
                    'visibility' => $request->get_param('visibility') ?: 'private',
                    'user_id' => $user_id,
                ]);

                return new WP_REST_Response(['profile' => Repository::profile($profile_id)], 201);
            }

            $profile = Repository::profile(absint($profile['id']));
        }

        $profile_data = [
            'display_name' => $request->get_param('display_name'),
            'public_handle' => $request->get_param('public_handle'),
            'visibility' => $request->get_param('visibility') ?: 'private',
        ];
        if (!$profile) {
            $profile_data['email'] = $request->get_param('email');
        }
        $profile_id = Repository::save_profile($profile_data, $profile ? absint($profile['id']) : 0);

        return new WP_REST_Response(['profile' => Repository::profile($profile_id)], $profile ? 200 : 201);
    }

    public function rest_save_consent(WP_REST_Request $request) {
        $profile = $this->request_profile($request);
        if (!$profile) {
            return new WP_REST_Response(['message' => __('Profile not found.', 'mds-support-passport')], 404);
        }

        $consent_id = Repository::save_consent([
            'profile_id' => absint($profile['id']),
            'purpose_key' => $request->get_param('purpose_key') ?: 'display_badge_publicly',
            'scope' => $request->get_param('scope') ?: 'site',
            'state' => $request->get_param('state') ?: 'granted',
            'consent_source' => 'manage_link',
            'actor_type' => is_user_logged_in() ? 'supporter' : 'manage_token',
            'actor_id' => get_current_user_id(),
        ]);

        return new WP_REST_Response(['consent_id' => $consent_id]);
    }

    public function rest_grants(WP_REST_Request $request) {
        $profile = $this->request_profile($request);
        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 50)));
        $page = max(1, absint($request->get_param('page') ?: 1));
        $profile_id = $profile ? absint($profile['id']) : 0;
        $total = $profile_id ? Repository::grant_count(['profile_id' => $profile_id]) : 0;

        return new WP_REST_Response([
            'grants' => $profile_id ? Repository::grants([
                'profile_id' => $profile_id,
                'limit' => $per_page,
                'offset' => ($page - 1) * $per_page,
            ]) : [],
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        ]);
    }

    public function rest_grant_badge(WP_REST_Request $request) {
        $result = Repository::grant_badge(array_merge($request->get_json_params() ?: [], ['badge_id' => absint($request['id'])]));
        if (is_wp_error($result)) {
            return new WP_REST_Response(['message' => $result->get_error_message()], 400);
        }

        return new WP_REST_Response($result, 201);
    }

    public function rest_revoke_grant(WP_REST_Request $request) {
        $ok = Repository::revoke_grant(absint($request['id']), (string) $request->get_param('reason'));

        return new WP_REST_Response(['revoked' => (bool) $ok], $ok ? 200 : 404);
    }

    public function rest_proof(WP_REST_Request $request) {
        $proof = Repository::proof_by_token((string) $request['token']);
        if (!$proof || empty($proof['public'])) {
            return new WP_REST_Response(['message' => __('Proof not found.', 'mds-support-passport')], 404);
        }

        return new WP_REST_Response(['proof' => $proof]);
    }

    public function rest_export(WP_REST_Request $request) {
        $profile = $this->request_profile($request);
        if (!$profile) {
            return new WP_REST_Response(['message' => __('Profile not found.', 'mds-support-passport')], 404);
        }

        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 50)));
        $page = max(1, absint($request->get_param('page') ?: 1));
        $offset = ($page - 1) * $per_page;
        $profile_id = absint($profile['id']);
        $grant_total = Repository::grant_count(['profile_id' => $profile_id]);
        $consent_total = Repository::consent_count($profile_id);

        return new WP_REST_Response([
            'profile' => $profile,
            'grants' => Repository::grants(['profile_id' => $profile_id, 'limit' => $per_page, 'offset' => $offset]),
            'consent' => Repository::consent_for_profile($profile_id, $per_page, $offset),
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'grant_total' => $grant_total,
                'grant_total_pages' => (int) ceil($grant_total / $per_page),
                'consent_total' => $consent_total,
                'consent_total_pages' => (int) ceil($consent_total / $per_page),
            ],
        ]);
    }

    public function rest_delete(WP_REST_Request $request) {
        $profile = $this->request_profile($request);
        if (!$profile || empty($profile['email'])) {
            return new WP_REST_Response(['message' => __('Profile not found or no email is available for erasure matching.', 'mds-support-passport')], 404);
        }

        return new WP_REST_Response(Repository::erase_by_email($profile['email']));
    }

    public function can_access_profile(WP_REST_Request $request) {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (
            'POST' === $request->get_method()
            && self::REST_NAMESPACE . self::REST_BASE . '/profile' === ltrim($request->get_route(), '/')
            && !$request->get_param('profile_id')
            && is_user_logged_in()
        ) {
            return true;
        }

        return (bool) $this->request_profile($request);
    }

    private function request_profile(WP_REST_Request $request) {
        $profile_id = absint($request->get_param('profile_id'));
        $token = (string) $request->get_param('token');
        if ($profile_id && $token) {
            return Repository::profile_by_manage_token($profile_id, $token);
        }

        if (is_user_logged_in()) {
            $profile = $profile_id
                ? Repository::profile($profile_id)
                : Repository::profile_by_user_id(get_current_user_id());
            if ($profile && absint($profile['user_id']) === get_current_user_id()) {
                return $profile;
            }
        }

        return null;
    }

    public function maybe_render_proof_page() {
        $manage_profile_id = absint(get_query_var('mds_support_passport_manage'));
        if ($manage_profile_id) {
            $this->render_manage_page($manage_profile_id, sanitize_text_field((string) get_query_var('mds_support_passport_token')));
        }

        $token = get_query_var('mds_support_passport_proof');
        if (!$token) {
            return;
        }

        $proof = Repository::proof_by_token($token);
        status_header($proof ? 200 : 404);
        nocache_headers();
        wp_enqueue_style('mds-support-passport');
        $title = $proof && !empty($proof['public']) && !empty($proof['badge_title'])
            ? sprintf(__('%s Support Proof', 'mds-support-passport'), $proof['badge_title'])
            : __('Support Proof', 'mds-support-passport');
        $this->render_frontend_document($this->render_proof($proof), $title);
        exit;
    }

    private function render_manage_page($profile_id, $token) {
        $profile = Repository::profile_by_manage_token($profile_id, $token);
        status_header($profile ? 200 : 404);
        nocache_headers();
        wp_enqueue_style('mds-support-passport');
        if (!$profile) {
            $content = '<section class="mds-support-passport-proof"><h1>' . esc_html__('Manage link unavailable', 'mds-support-passport') . '</h1><p>' . esc_html__('This private manage link is invalid or has been replaced.', 'mds-support-passport') . '</p></section>';
            $title = __('Manage link unavailable', 'mds-support-passport');
        } else {
            $content = $this->render_manage_panel($profile, $token);
            $title = __('Manage Support Proofs', 'mds-support-passport');
        }
        $this->render_frontend_document($content, $title);
        exit;
    }

    private function render_frontend_document($content, $title = '') {
        $has_header = $this->theme_has_template('header.php');
        $has_footer = $this->theme_has_template('footer.php');
        if ($title) {
            add_filter('pre_get_document_title', static function () use ($title) {
                return $title;
            });
        }

        if ($has_header) {
            get_header();
        } else {
            echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '"><meta name="viewport" content="width=device-width, initial-scale=1">';
            if ($title) {
                remove_action('wp_head', '_wp_render_title_tag', 1);
                echo '<title>' . esc_html($title) . '</title>';
            }
            wp_head();
            echo '</head><body ';
            body_class();
            echo '>';
        }

        echo '<main class="mds-support-passport-proof-wrap">';
        echo $content;
        echo '</main>';

        if ($has_footer) {
            get_footer();
        } else {
            wp_footer();
            echo '</body></html>';
        }
    }

    private function theme_has_template($file) {
        $template = locate_template((string) $file, false, false);
        if (!$template) {
            return false;
        }

        $template = wp_normalize_path($template);
        $compat = wp_normalize_path(ABSPATH . WPINC . '/theme-compat/');

        return false === strpos($template, $compat);
    }

    private function render_manage_panel(array $profile, $token) {
        $profile_id = absint($profile['id']);
        $grants = Repository::grants(['profile_id' => $profile_id, 'limit' => 100]);
        ob_start();
        ?>
        <section class="mds-support-passport-proof">
            <p class="mds-support-passport-eyebrow"><?php esc_html_e('Private supporter manage link', 'mds-support-passport'); ?></p>
            <h1><?php esc_html_e('Manage Support Proofs', 'mds-support-passport'); ?></h1>
            <p><?php esc_html_e('Choose how your local support badges appear on this website. Public display is optional and can be withdrawn here.', 'mds-support-passport'); ?></p>
            <?php if (!empty($_GET['updated'])) : ?>
                <p class="mds-support-passport-note"><?php esc_html_e('Your support proof preferences were saved.', 'mds-support-passport'); ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-support-passport-manage-form">
                <?php wp_nonce_field('mds_support_passport_manage_' . $profile_id); ?>
                <input type="hidden" name="action" value="mds_support_passport_manage_profile" />
                <input type="hidden" name="profile_id" value="<?php echo esc_attr($profile_id); ?>" />
                <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>" />
                <label><span><?php esc_html_e('Display name', 'mds-support-passport'); ?></span><input type="text" name="display_name" value="<?php echo esc_attr($profile['display_name']); ?>" /></label>
                <label><span><?php esc_html_e('Public handle', 'mds-support-passport'); ?></span><input type="text" name="public_handle" value="<?php echo esc_attr($profile['public_handle']); ?>" /></label>
                <button type="submit"><?php esc_html_e('Save profile', 'mds-support-passport'); ?></button>
            </form>
            <div class="mds-support-passport-manage-grants">
                <?php foreach ($grants as $grant) : ?>
                    <article class="mds-support-passport-card is-<?php echo esc_attr($grant['color_token']); ?>">
                        <?php echo $this->badge_visual($grant); ?>
                        <h3><?php echo esc_html($grant['badge_title']); ?></h3>
                        <p><?php echo esc_html($grant['badge_description']); ?></p>
                        <p><strong><?php esc_html_e('Public display:', 'mds-support-passport'); ?></strong> <?php echo esc_html($grant['public_display_state']); ?></p>
                        <?php if (empty($grant['revoked_at'])) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('mds_support_passport_manage_' . $profile_id); ?>
                                <input type="hidden" name="action" value="mds_support_passport_manage_consent" />
                                <input type="hidden" name="profile_id" value="<?php echo esc_attr($profile_id); ?>" />
                                <input type="hidden" name="grant_id" value="<?php echo esc_attr(absint($grant['id'])); ?>" />
                                <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>" />
                                <input type="hidden" name="public_display_state" value="<?php echo 'public' === $grant['public_display_state'] ? 'private' : 'public'; ?>" />
                                <button type="submit"><?php echo 'public' === $grant['public_display_state'] ? esc_html__('Withdraw public display', 'mds-support-passport') : esc_html__('Enable public display', 'mds-support-passport'); ?></button>
                            </form>
                        <?php else : ?>
                            <p><?php esc_html_e('This badge was revoked and cannot be displayed publicly.', 'mds-support-passport'); ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function render_proof($proof) {
        if (!$proof) {
            return '<section class="mds-support-passport-proof"><h1>' . esc_html__('Support proof not found', 'mds-support-passport') . '</h1><p>' . esc_html__('This proof link is invalid, private, revoked, or no longer available.', 'mds-support-passport') . '</p></section>';
        }

        if (empty($proof['public'])) {
            return '<section class="mds-support-passport-proof"><h1>' . esc_html__('Support proof is private', 'mds-support-passport') . '</h1><p>' . esc_html__('This supporter has not enabled public display for this proof, or the badge is no longer public.', 'mds-support-passport') . '</p></section>';
        }

        return '<section class="mds-support-passport-proof is-' . esc_attr($proof['color_token']) . '">'
            . '<p class="mds-support-passport-eyebrow">' . esc_html__('Verified local support proof', 'mds-support-passport') . '</p>'
            . $this->badge_visual($proof, 'is-proof')
            . '<h1>' . esc_html($proof['badge_title']) . '</h1>'
            . '<p>' . esc_html($proof['badge_description']) . '</p>'
            . '<dl><div><dt>' . esc_html__('Supporter', 'mds-support-passport') . '</dt><dd>' . esc_html($proof['supporter_name']) . '</dd></div><div><dt>' . esc_html__('Issued', 'mds-support-passport') . '</dt><dd>' . esc_html($proof['issued_at']) . '</dd></div></dl>'
            . '<p class="mds-support-passport-note">' . esc_html__('This proof is local to this website and does not create a cross-site identity, ownership token, investment claim, or transferable credential.', 'mds-support-passport') . '</p>'
            . '</section>';
    }

    private function proof_url($token) {
        $settings = Repository::settings();
        $slug = sanitize_title((string) ($settings['proof_slug'] ?? 'support-proof')) ?: 'support-proof';

        return home_url(user_trailingslashit($slug . '/' . rawurlencode((string) $token)));
    }

    private function manage_url($profile_id, $token) {
        return home_url(user_trailingslashit('support-passport/manage/' . absint($profile_id) . '/' . rawurlencode((string) $token)));
    }

    private function datetime_local_value($value) {
        $value = trim((string) $value);
        if ('' === $value || '0000-00-00 00:00:00' === $value) {
            return '';
        }

        $timestamp = strtotime($value);
        if (!$timestamp) {
            return '';
        }

        return date('Y-m-d\TH:i', $timestamp);
    }

    public function directory_shortcode($atts = []) {
        wp_enqueue_style('mds-support-passport');
        $settings = Repository::settings();
        $atts = shortcode_atts(['search' => ''], $atts, 'mds_support_passport_directory');
        if ('1' !== (string) ($settings['directory_enabled'] ?? '0')) {
            return '<div class="mds-support-passport-directory-wrap"><div class="mds-support-passport-empty"><p>' . esc_html__('The public supporter directory is not enabled yet.', 'mds-support-passport') . '</p></div></div>';
        }

        $search = (string) $atts['search'];
        if ('1' === (string) ($settings['directory_search_enabled'] ?? '1') && isset($_GET['mds_supporter_search'])) {
            $search = sanitize_text_field(wp_unslash($_GET['mds_supporter_search']));
        }
        $per_page = 24;
        $total = Repository::public_directory_count($search);
        $total_pages = max(1, (int) ceil($total / $per_page));
        $current_page = min($total_pages, max(1, absint($this->request_query_scalar('mds_supporter_page'))));
        $items = Repository::public_directory($search, $per_page, ($current_page - 1) * $per_page);
        $html = '<div class="mds-support-passport-directory-wrap">';
        if ('1' === (string) ($settings['directory_search_enabled'] ?? '1')) {
            $html .= '<form class="mds-support-passport-directory-search" method="get">';
            $html .= '<label><span>' . esc_html__('Search supporters', 'mds-support-passport') . '</span><input type="search" name="mds_supporter_search" value="' . esc_attr($search) . '" /></label>';
            $html .= '<button type="submit">' . esc_html__('Search', 'mds-support-passport') . '</button>';
            if ($search) {
                $html .= '<a href="' . esc_url(remove_query_arg(['mds_supporter_search', 'mds_supporter_page'])) . '">' . esc_html__('Clear', 'mds-support-passport') . '</a>';
            }
            $html .= '</form>';
        }
        if (!$items) {
            return $html . '<div class="mds-support-passport-empty"><p>' . esc_html__('No public supporters are listed yet.', 'mds-support-passport') . '</p></div></div>';
        }

        $html .= '<div class="mds-support-passport-directory">';
        foreach ($items as $item) {
            $html .= '<article class="mds-support-passport-card is-' . esc_attr($item['color_token']) . '">' . $this->badge_visual($item) . '<h3>' . esc_html($item['supporter_name']) . '</h3><p>' . esc_html($item['badge_title']) . '</p><small>' . esc_html($item['issued_at']) . '</small></article>';
        }
        $html .= '</div>';
        if ($total_pages > 1) {
            $base = str_replace('%25%23%25', '%#%', add_query_arg('mds_supporter_page', '%#%'));
            $links = paginate_links([
                'base' => $base,
                'format' => '',
                'current' => $current_page,
                'total' => $total_pages,
                'type' => 'list',
                'prev_text' => __('Previous', 'mds-support-passport'),
                'next_text' => __('Next', 'mds-support-passport'),
            ]);
            if ($links) {
                $html .= '<nav class="mds-support-passport-pagination" aria-label="' . esc_attr__('Supporter directory pagination', 'mds-support-passport') . '">';
                $html .= '<p>' . esc_html(sprintf(
                    __('Showing %1$s-%2$s of %3$s supporters', 'mds-support-passport'),
                    number_format_i18n(($current_page - 1) * $per_page + 1),
                    number_format_i18n(min($total, $current_page * $per_page)),
                    number_format_i18n($total)
                )) . '</p>';
                $html .= wp_kses_post($links);
                $html .= '</nav>';
            }
        }
        $html .= '</div>';

        return $html;
    }

    public function badges_shortcode() {
        wp_enqueue_style('mds-support-passport');
        $badges = Repository::badges(['active_only' => true, 'limit' => 0]);
        if (!$badges) {
            return '<div class="mds-support-passport-badges"><p>' . esc_html__('No support badges are available yet.', 'mds-support-passport') . '</p></div>';
        }

        $html = '<div class="mds-support-passport-badges">';
        foreach ($badges as $badge) {
            $html .= '<article class="mds-support-passport-card is-' . esc_attr($badge['color_token']) . '">' . $this->badge_visual($badge) . '<h3>' . esc_html($badge['title']) . '</h3><p>' . esc_html($badge['description']) . '</p></article>';
        }
        $html .= '</div>';

        return $html;
    }

    public function privacy_exporters(array $exporters) {
        $exporters['mds-support-passport'] = [
            'exporter_friendly_name' => __('Proof of Support Passport', 'mds-support-passport'),
            'callback' => function ($email_address, $page = 1) {
                return Repository::export_by_email($email_address, $page);
            },
        ];

        return $exporters;
    }

    public function privacy_erasers(array $erasers) {
        $erasers['mds-support-passport'] = [
            'eraser_friendly_name' => __('Proof of Support Passport', 'mds-support-passport'),
            'callback' => function ($email_address, $page = 1) {
                return Repository::erase_by_email($email_address, $page);
            },
        ];

        return $erasers;
    }

    public function api_endpoint_manifest(array $endpoints) {
        $base = '/' . self::REST_NAMESPACE . self::REST_BASE;
        $endpoints[] = ['id' => 'support-passport-badges-read', 'route' => $base . '/badges', 'methods' => ['GET'], 'scope' => 'support-passport.badge.read', 'minimum_security_level' => 'public_read', 'description' => __('Read active public support badge definitions.', 'mds-support-passport')];
        $endpoints[] = ['id' => 'support-passport-profile-read', 'route' => $base . '/profile', 'methods' => ['GET'], 'scope' => 'support-passport.profile.read', 'minimum_security_level' => 'signed_manage_token', 'description' => __('Read a support profile using its signed manage token or owner session.', 'mds-support-passport')];
        $endpoints[] = ['id' => 'support-passport-profile-write', 'route' => $base . '/profile', 'methods' => ['POST'], 'scope' => 'support-passport.profile.write', 'minimum_security_level' => 'signed_manage_token', 'description' => __('Create or update a support profile using its signed manage token or owner session.', 'mds-support-passport')];
        $endpoints[] = ['id' => 'support-passport-consent-write', 'route' => $base . '/profile/consent', 'methods' => ['POST'], 'scope' => 'support-passport.consent.write', 'minimum_security_level' => 'signed_manage_token', 'description' => __('Update public-display consent using the profile manage token or owner session.', 'mds-support-passport')];
        $endpoints[] = ['id' => 'support-passport-grants-read', 'route' => $base . '/grants', 'methods' => ['GET'], 'scope' => 'support-passport.profile.read', 'minimum_security_level' => 'signed_manage_token', 'description' => __('Read badge grants for the managed support profile.', 'mds-support-passport')];
        $endpoints[] = ['id' => 'support-passport-badge-grant', 'route' => $base . '/badges/(?P<id>\d+)/grant', 'methods' => ['POST'], 'scope' => 'support-passport.grant.write', 'minimum_security_level' => 'wp_capability', 'description' => __('Grant a support badge as an administrator.', 'mds-support-passport')];
        $endpoints[] = ['id' => 'support-passport-grant-revoke', 'route' => $base . '/grants/(?P<id>\d+)/revoke', 'methods' => ['POST'], 'scope' => 'support-passport.grant.revoke', 'minimum_security_level' => 'wp_capability', 'description' => __('Revoke a support badge grant as an administrator.', 'mds-support-passport')];
        $endpoints[] = ['id' => 'support-passport-proof-read', 'route' => $base . '/proofs/(?P<token>[A-Za-z0-9_.\-]+)', 'methods' => ['GET'], 'scope' => 'support-passport.badge.read', 'minimum_security_level' => 'public_read', 'description' => __('Read a public, non-revoked support proof by its proof token.', 'mds-support-passport')];
        $endpoints[] = ['id' => 'support-passport-privacy-export', 'route' => $base . '/export', 'methods' => ['POST'], 'scope' => 'support-passport.privacy.export', 'minimum_security_level' => 'signed_manage_token', 'description' => __('Export data for the profile authorized by a signed manage token or owner session.', 'mds-support-passport')];
        $endpoints[] = ['id' => 'support-passport-privacy-delete', 'route' => $base . '/delete', 'methods' => ['POST'], 'scope' => 'support-passport.privacy.delete', 'minimum_security_level' => 'signed_manage_token', 'description' => __('Erase data for the profile authorized by a signed manage token or owner session.', 'mds-support-passport')];

        return $endpoints;
    }

    public function extension_onboarding_items(array $items) {
        $items['mds-support-passport'] = [
            'name' => __('Proof of Support Passport', 'mds-support-passport'),
            'summary' => __('Create local supporter badges, proof pages, consent defaults, and privacy handling before showing public supporter proofs.', 'mds-support-passport'),
            'priority' => 35,
            'actions' => [
                [
                    'label' => __('Open Support Passport', 'mds-support-passport'),
                    'url' => admin_url('admin.php?page=mds-support-passport'),
                    'primary' => true,
                ],
            ],
            'setup_pages' => [
                [
                    'slug' => 'badges',
                    'title' => __('Support Badges', 'mds-support-passport'),
                    'description' => __('Shows the public badge types supporters can earn or receive.', 'mds-support-passport'),
                    'page_slug' => 'support-badges',
                    'content' => '[mds_support_passport_badges]',
                    'post_status' => 'publish',
                ],
                [
                    'slug' => 'directory',
                    'title' => __('Supporter Directory', 'mds-support-passport'),
                    'description' => __('Shows supporters who have opted in to public display.', 'mds-support-passport'),
                    'page_slug' => 'supporter-directory',
                    'content' => '[mds_support_passport_directory]',
                    'post_status' => 'publish',
                ],
            ],
            'legal_documents' => [
                [
                    'slug' => 'support-proof-terms',
                    'title' => __('Support Proof Terms', 'mds-support-passport'),
                    'description' => __('Terms for local support badges, proof pages, public display consent, revocation, and non-transferability.', 'mds-support-passport'),
                    'page_slug' => 'support-proof-terms',
                    'content' => $this->support_terms_content(),
                ],
            ],
        ];

        return $items;
    }

    public function dashboard_extension_cards(array $cards) {
        $slug = 'mds-support-passport';
        $cards[$slug] = [
            'slug' => $slug,
            'name' => __('Proof of Support Passport', 'mds-support-passport'),
            'description' => __('Issue privacy-first, local supporter badges and shareable proof pages.', 'mds-support-passport'),
            'active' => true,
            'installed' => true,
            'actions' => [
                [
                    'label' => __('Manage grants', 'mds-support-passport'),
                    'url' => admin_url('admin.php?page=mds-support-passport'),
                    'primary' => true,
                    'icon' => 'dashicons-awards',
                ],
            ],
        ];

        return $cards;
    }

    public function editor_blocks(array $blocks) {
        $blocks[] = [
            'name' => 'mds-support-passport/directory',
            'title' => __('Supporter Directory', 'mds-support-passport'),
            'description' => __('Display opt-in public supporter badges from Proof of Support Passport.', 'mds-support-passport'),
            'icon' => 'awards',
            'category' => 'million-dollar-script',
            'shortcode' => 'mds_support_passport_directory',
            'attributes' => ['search' => ['type' => 'string', 'default' => '']],
            'controls' => [
                [
                    'attribute' => 'search',
                    'type' => 'text',
                    'label' => __('Search filter', 'mds-support-passport'),
                    'help' => __('Optional starting search term. Directory settings can disable searching entirely.', 'mds-support-passport'),
                ],
            ],
            'preview' => [
                'title' => __('Supporter Directory', 'mds-support-passport'),
                'description' => __('Shows supporters who explicitly opted in to public display.', 'mds-support-passport'),
                'rows' => [
                    __('Private profiles stay hidden.', 'mds-support-passport'),
                    __('Directory search follows extension settings.', 'mds-support-passport'),
                ],
            ],
            'render_callback' => [$this, 'directory_shortcode'],
        ];

        $blocks[] = [
            'name' => 'mds-support-passport/badges',
            'title' => __('Support Badges', 'mds-support-passport'),
            'description' => __('Display the active support badges available on this site.', 'mds-support-passport'),
            'icon' => 'star-filled',
            'category' => 'million-dollar-script',
            'shortcode' => 'mds_support_passport_badges',
            'attributes' => [],
            'controls' => [],
            'preview' => [
                'title' => __('Support Badges', 'mds-support-passport'),
                'description' => __('Shows active badge definitions without exposing supporter data.', 'mds-support-passport'),
                'rows' => [
                    __('Badge names and descriptions', 'mds-support-passport'),
                    __('No private supporter identifiers', 'mds-support-passport'),
                ],
            ],
            'render_callback' => [$this, 'badges_shortcode'],
        ];

        return $blocks;
    }

    private function support_terms_content() {
        return '<h2>' . esc_html__('Support Proof Terms', 'mds-support-passport') . '</h2>'
            . '<p>' . esc_html__('Support badges and proof pages on {{site_name}} recognize local support activity on this website. A badge does not create ownership, equity, investment value, transferable rights, account portability, endorsement, ranking permanence, or a cross-site identity.', 'mds-support-passport') . '</p>'
            . '<h3>' . esc_html__('Local Recognition Only', 'mds-support-passport') . '</h3>'
            . '<p>' . esc_html__('Support badges are issued and managed only by this website. They may recognize purchases, sponsorships, campaign support, community actions, manual approvals, or other local eligibility rules selected by the site administrator. A badge is not guaranteed to be accepted by another website, platform, person, organization, payment provider, or marketplace.', 'mds-support-passport') . '</p>'
            . '<h3>' . esc_html__('Eligibility and Source Records', 'mds-support-passport') . '</h3>'
            . '<p>' . esc_html__('The site may keep source references such as order numbers, campaign IDs, badge IDs, grant IDs, issue dates, expiration dates, revocation records, and audit events so that badge eligibility can be verified, corrected, withdrawn, or defended against abuse, fraud, chargebacks, disputes, or mistakes.', 'mds-support-passport') . '</p>'
            . '<h3>' . esc_html__('Public Display Consent', 'mds-support-passport') . '</h3>'
            . '<p>' . esc_html__('Supporter profiles, names, handles, avatars, messages, and proof pages are private unless public display consent is recorded. Public display consent can be withdrawn, after which public proof surfaces should be hidden where practical while minimal audit records may be retained for security, disputes, fraud prevention, revocation, and compliance.', 'mds-support-passport') . '</p>'
            . '<h3>' . esc_html__('Private Manage Links', 'mds-support-passport') . '</h3>'
            . '<p>' . esc_html__('A private manage link may allow a supporter to edit display preferences or change whether a badge is public. Anyone with a valid private manage link may be able to change those preferences, so supporters should keep manage links private and notify the site administrator if a link may have been shared or compromised.', 'mds-support-passport') . '</p>'
            . '<h3>' . esc_html__('Proof Links and Directory Listings', 'mds-support-passport') . '</h3>'
            . '<p>' . esc_html__('A proof link or supporter directory listing may show badge details only when the related grant and supporter profile are eligible for public display. Private, withdrawn, archived, expired, revoked, refunded, disputed, fraudulent, or deleted proofs may be hidden or changed to a non-public status.', 'mds-support-passport') . '</p>'
            . '<h3>' . esc_html__('Revocation and Corrections', 'mds-support-passport') . '</h3>'
            . '<p>' . esc_html__('Badges may be corrected, hidden, expired, or revoked when a payment is refunded, reversed, fraudulent, abusive, mistaken, no longer eligible, legally risky, or inconsistent with site rules. Revocation may remove public display without deleting legally or operationally necessary records.', 'mds-support-passport') . '</p>'
            . '<h3>' . esc_html__('No Transfer, Resale, or Investment Rights', 'mds-support-passport') . '</h3>'
            . '<p>' . esc_html__('Support badges are not securities, investments, tokens, NFTs, stored-value products, financial accounts, or transferable property rights. Unless a separate written agreement says otherwise, badges cannot be sold, transferred, assigned, sublicensed, inherited, pledged, or used to claim revenue, governance rights, ownership, exclusivity, ranking, priority, endorsement, or future benefits.', 'mds-support-passport') . '</p>'
            . '<h3>' . esc_html__('Security, Abuse, and Availability', 'mds-support-passport') . '</h3>'
            . '<p>' . esc_html__('The site may rate limit, suspend, hide, correct, revoke, or investigate support proofs to protect supporters, administrators, the public, and the website. Proof pages, directories, badges, and manage links may be unavailable during maintenance, security incidents, legal review, abuse review, backups, migrations, plugin changes, or account recovery.', 'mds-support-passport') . '</p>'
            . '<h3>' . esc_html__('Privacy Requests', 'mds-support-passport') . '</h3>'
            . '<p>' . esc_html__('Supporters may request access, correction, withdrawal, export, or erasure through the site contact page or by contacting the site administrator, subject to security, dispute, accounting, fraud-prevention, backup, and legal retention needs.', 'mds-support-passport') . '</p>'
            . '<h3>' . esc_html__('Changes to These Terms', 'mds-support-passport') . '</h3>'
            . '<p>' . esc_html__('The site may update these terms for future badges, new proof features, legal requirements, security changes, or operational needs. Existing public display choices may be rechecked when terms materially change, when eligibility changes, or when a supporter requests a review.', 'mds-support-passport') . '</p>';
    }
}
