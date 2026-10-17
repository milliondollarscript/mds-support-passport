<?php
/**
 * Local data layer for Proof of Support Passport.
 *
 * @package MillionDollarScript\Extensions\SupportPassport
 */

namespace MillionDollarScript\Extensions\SupportPassport;

if (!defined('ABSPATH')) {
    exit;
}

final class Repository {

    private const TABLES = [
        'profiles' => 'mds_support_passport_profiles',
        'badges' => 'mds_support_passport_badges',
        'grants' => 'mds_support_passport_grants',
        'consent' => 'mds_support_passport_consent',
        'revocations' => 'mds_support_passport_revocations',
        'proofs' => 'mds_support_passport_proofs',
        'audit' => 'mds_support_passport_audit_log',
    ];

    public static function table($key) {
        global $wpdb;
        $key = sanitize_key((string) $key);

        return $wpdb->prefix . (self::TABLES[$key] ?? '');
    }

    public static function maybe_install() {
        if (get_option('mds_support_passport_db_version') === MDS_SUPPORT_PASSPORT_DB_VERSION) {
            return;
        }

        self::install();
    }

    public static function install() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $profiles = self::table('profiles');
        $badges = self::table('badges');
        $grants = self::table('grants');
        $consent = self::table('consent');
        $revocations = self::table('revocations');
        $proofs = self::table('proofs');
        $audit = self::table('audit');

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$profiles} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            email_hash varchar(191) DEFAULT NULL,
            email_ciphertext longtext DEFAULT NULL,
            display_name varchar(191) DEFAULT NULL,
            public_handle varchar(191) DEFAULT NULL,
            avatar_attachment_id bigint(20) unsigned DEFAULT NULL,
            visibility varchar(20) NOT NULL DEFAULT 'private',
            manage_token_lookup varchar(191) DEFAULT NULL,
            manage_token_hash varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_consent_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY email_hash (email_hash),
            KEY visibility (visibility),
            KEY manage_token_lookup (manage_token_lookup)
        ) {$charset};");

        dbDelta("CREATE TABLE {$badges} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(191) NOT NULL,
            description text DEFAULT NULL,
            icon_attachment_id bigint(20) unsigned DEFAULT NULL,
            color_token varchar(32) NOT NULL DEFAULT 'blue',
            eligibility_source varchar(64) NOT NULL DEFAULT 'manual',
            eligibility_note text DEFAULT NULL,
            public_eligibility tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY eligibility_source (eligibility_source)
        ) {$charset};");

        dbDelta("CREATE TABLE {$grants} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned NOT NULL,
            badge_id bigint(20) unsigned NOT NULL,
            source_extension varchar(100) NOT NULL DEFAULT 'manual',
            source_entity_type varchar(100) DEFAULT NULL,
            source_entity_id varchar(100) DEFAULT NULL,
            order_reference varchar(191) DEFAULT NULL,
            public_display_state varchar(20) NOT NULL DEFAULT 'private',
            issued_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            revoked_at datetime DEFAULT NULL,
            revocation_id bigint(20) unsigned DEFAULT NULL,
            proof_id bigint(20) unsigned DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY profile_id (profile_id),
            KEY badge_id (badge_id),
            KEY public_display_state (public_display_state),
            KEY revoked_at (revoked_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$consent} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned NOT NULL,
            purpose_key varchar(100) NOT NULL,
            scope varchar(100) DEFAULT NULL,
            state varchar(20) NOT NULL DEFAULT 'granted',
            consent_source varchar(50) NOT NULL DEFAULT 'admin',
            copy_version varchar(50) DEFAULT NULL,
            terms_hash varchar(191) DEFAULT NULL,
            actor_type varchar(30) NOT NULL DEFAULT 'system',
            actor_id bigint(20) unsigned DEFAULT NULL,
            ip_hash varchar(191) DEFAULT NULL,
            user_agent_hash varchar(191) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY profile_id (profile_id),
            KEY purpose_key (purpose_key),
            KEY state (state)
        ) {$charset};");

        dbDelta("CREATE TABLE {$revocations} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            grant_id bigint(20) unsigned NOT NULL,
            reason text DEFAULT NULL,
            actor_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY grant_id (grant_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$proofs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            grant_id bigint(20) unsigned NOT NULL,
            token_lookup varchar(191) NOT NULL,
            token_hash varchar(255) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'private',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY token_lookup (token_lookup),
            KEY grant_id (grant_id),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            profile_id bigint(20) unsigned DEFAULT NULL,
            grant_id bigint(20) unsigned DEFAULT NULL,
            event_type varchar(100) NOT NULL,
            actor_type varchar(30) NOT NULL DEFAULT 'system',
            actor_id bigint(20) unsigned DEFAULT NULL,
            details longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY profile_id (profile_id),
            KEY grant_id (grant_id),
            KEY event_type (event_type)
        ) {$charset};");

        update_option('mds_support_passport_db_version', MDS_SUPPORT_PASSPORT_DB_VERSION, false);
    }

    public static function maybe_seed() {
        if (self::badges(['limit' => 1])) {
            return;
        }

        self::save_badge([
            'title' => __('Founding Supporter', 'mds-support-passport'),
            'description' => __('Recognizes early supporters on this site.', 'mds-support-passport'),
            'color_token' => 'blue',
            'eligibility_source' => 'manual',
            'eligibility_note' => __('Grant manually after confirming support.', 'mds-support-passport'),
            'status' => 'active',
        ]);
    }

    public static function settings() {
        $defaults = [
            'directory_enabled' => '0',
            'directory_search_enabled' => '1',
            'grid_purchase_badge_id' => 0,
            'email_manage_link_on_auto_grant' => '1',
            'proof_slug' => 'support-proof',
            'retention_profile_months' => 24,
            'retention_audit_years' => 6,
        ];
        $settings = get_option('mds_support_passport_settings', []);
        $settings = is_array($settings) ? $settings : [];

        return array_merge($defaults, $settings);
    }

    public static function save_settings(array $settings) {
        $current = self::settings();
        $updated = [
            'directory_enabled' => !empty($settings['directory_enabled']) ? '1' : '0',
            'directory_search_enabled' => !empty($settings['directory_search_enabled']) ? '1' : '0',
            'grid_purchase_badge_id' => absint($settings['grid_purchase_badge_id'] ?? $current['grid_purchase_badge_id']),
            'email_manage_link_on_auto_grant' => !empty($settings['email_manage_link_on_auto_grant']) ? '1' : '0',
            'proof_slug' => sanitize_title((string) ($settings['proof_slug'] ?? $current['proof_slug'])) ?: 'support-proof',
            'retention_profile_months' => min(120, max(1, absint($settings['retention_profile_months'] ?? $current['retention_profile_months']))),
            'retention_audit_years' => min(20, max(1, absint($settings['retention_audit_years'] ?? $current['retention_audit_years']))),
        ];
        update_option('mds_support_passport_settings', $updated, false);

        return $updated;
    }

    public static function save_badge(array $data, $badge_id = 0) {
        global $wpdb;

        $badge_id = absint($badge_id);
        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        if ('' === $title) {
            return 0;
        }

        $status = sanitize_key((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'inactive', 'archived'], true)) {
            $status = 'active';
        }

        $color = sanitize_key((string) ($data['color_token'] ?? 'blue'));
        if (!in_array($color, ['blue', 'green', 'gold', 'purple', 'slate'], true)) {
            $color = 'blue';
        }

        $row = [
            'title' => $title,
            'description' => sanitize_textarea_field((string) ($data['description'] ?? '')),
            'icon_attachment_id' => absint($data['icon_attachment_id'] ?? 0),
            'color_token' => $color,
            'eligibility_source' => sanitize_key((string) ($data['eligibility_source'] ?? 'manual')) ?: 'manual',
            'eligibility_note' => sanitize_textarea_field((string) ($data['eligibility_note'] ?? '')),
            // Whether the site owner lets that note appear where the public can see it.
            'public_eligibility' => empty($data['public_eligibility']) ? 0 : 1,
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ];

        if ($badge_id) {
            $wpdb->update(self::table('badges'), $row, ['id' => $badge_id]);
            self::audit('badge.updated', 0, 0, ['badge_id' => $badge_id]);
            return $badge_id;
        }

        $row['created_at'] = current_time('mysql');
        $wpdb->insert(self::table('badges'), $row);
        $badge_id = absint($wpdb->insert_id);
        self::audit('badge.created', 0, 0, ['badge_id' => $badge_id]);

        return $badge_id;
    }

    public static function badge($badge_id) {
        global $wpdb;
        $badge = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('badges') . ' WHERE id = %d', absint($badge_id)), ARRAY_A);

        return $badge ? self::normalize_badge($badge) : null;
    }

    public static function badge_grant_count($badge_id) {
        global $wpdb;

        return absint($wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table('grants') . ' WHERE badge_id = %d',
            absint($badge_id)
        )));
    }

    public static function grant_for_source($source_extension, $source_entity_type, $source_entity_id) {
        global $wpdb;

        $source_extension = sanitize_key((string) $source_extension);
        $source_entity_type = sanitize_key((string) $source_entity_type);
        $source_entity_id = sanitize_text_field((string) $source_entity_id);
        if (!$source_extension || !$source_entity_type || '' === $source_entity_id) {
            return null;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT g.*, b.title AS badge_title, b.description AS badge_description, b.icon_attachment_id AS badge_icon_attachment_id, b.color_token, b.status AS badge_status, p.display_name, p.public_handle, p.visibility, pr.status AS proof_status '
            . 'FROM ' . self::table('grants') . ' g '
            . 'INNER JOIN ' . self::table('badges') . ' b ON b.id = g.badge_id '
            . 'INNER JOIN ' . self::table('profiles') . ' p ON p.id = g.profile_id '
            . 'LEFT JOIN ' . self::table('proofs') . ' pr ON pr.id = g.proof_id '
            . 'WHERE g.source_extension = %s AND g.source_entity_type = %s AND g.source_entity_id = %s '
            . 'ORDER BY g.id DESC LIMIT 1',
            $source_extension,
            $source_entity_type,
            $source_entity_id
        ), ARRAY_A);

        return $rows ? self::normalize_grant($rows[0]) : null;
    }

    public static function update_badge_status($badge_id, $status) {
        global $wpdb;
        $badge_id = absint($badge_id);
        $status = sanitize_key((string) $status);
        if (!$badge_id || !in_array($status, ['active', 'inactive', 'archived'], true)) {
            return false;
        }

        $updated = $wpdb->update(self::table('badges'), [
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ], ['id' => $badge_id]);
        if (false !== $updated) {
            self::audit('badge.status_' . $status, 0, 0, ['badge_id' => $badge_id]);
        }

        return false !== $updated;
    }

    public static function delete_badge($badge_id) {
        global $wpdb;
        $badge_id = absint($badge_id);
        if (!$badge_id) {
            return new \WP_Error('invalid_badge', __('Choose a badge to delete.', 'mds-support-passport'));
        }

        $grant_count = self::badge_grant_count($badge_id);
        if ($grant_count > 0) {
            return new \WP_Error('badge_has_grants', __('This badge has support grants and cannot be hard deleted. Archive it instead.', 'mds-support-passport'));
        }

        $deleted = $wpdb->delete(self::table('badges'), ['id' => $badge_id]);
        if ($deleted) {
            self::audit('badge.deleted', 0, 0, ['badge_id' => $badge_id]);
        }

        return (bool) $deleted;
    }

    public static function badges(array $args = []) {
        global $wpdb;

        $limit = array_key_exists('limit', $args) ? absint($args['limit']) : 100;
        $limit = $limit > 0 ? min(500, $limit) : 0;
        $offset = max(0, absint($args['offset'] ?? 0));
        $where = '';
        if (!empty($args['active_only'])) {
            $where = "WHERE status = 'active'";
        }

        $sql = 'SELECT * FROM ' . self::table('badges') . " {$where} ORDER BY id DESC";
        if ($limit > 0) {
            $sql = $wpdb->prepare($sql . ' LIMIT %d OFFSET %d', $limit, $offset);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return array_map([__CLASS__, 'normalize_badge'], $rows ?: []);
    }

    /**
     * Badges as they may reach someone without this site's admin screens.
     *
     * The eligibility note is what an administrator writes about how a badge is
     * earned. Some sites mean that as public copy and some mean it as an internal
     * reminder, so a badge carries the choice (Repository::save_badge) and this is
     * where a public surface asks for the version that respects it. Use this for
     * any new public badge surface; the admin screens keep using badges().
     */
    public static function public_badges(array $args = []) {
        $badges = self::badges($args);

        return array_values(array_map(static function (array $badge) {
            if (empty($badge['public_eligibility'])) {
                unset($badge['eligibility_note']);
            }
            unset($badge['public_eligibility']);

            return $badge;
        }, $badges));
    }

    public static function badge_count(array $args = []) {
        global $wpdb;

        $where = !empty($args['active_only']) ? " WHERE status = 'active'" : '';

        return absint($wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('badges') . $where));
    }

    public static function save_profile(array $data, $profile_id = 0) {
        global $wpdb;

        $profile_id = absint($profile_id);
        $email = sanitize_email((string) ($data['email'] ?? ''));
        $visibility = sanitize_key((string) ($data['visibility'] ?? 'private'));
        if (!in_array($visibility, ['private', 'unlisted', 'public'], true)) {
            $visibility = 'private';
        }

        $row = ['updated_at' => current_time('mysql')];

        if (!$profile_id || array_key_exists('user_id', $data)) {
            $row['user_id'] = absint($data['user_id'] ?? 0) ?: null;
        }
        if (!$profile_id || array_key_exists('display_name', $data)) {
            $row['display_name'] = sanitize_text_field((string) ($data['display_name'] ?? ''));
        }
        if (!$profile_id || array_key_exists('public_handle', $data)) {
            $row['public_handle'] = sanitize_title((string) ($data['public_handle'] ?? ''));
        }
        if (!$profile_id || array_key_exists('avatar_attachment_id', $data)) {
            $row['avatar_attachment_id'] = absint($data['avatar_attachment_id'] ?? 0);
        }
        if (!$profile_id || array_key_exists('visibility', $data)) {
            $row['visibility'] = $visibility;
        }

        if ($email) {
            $row['email_hash'] = self::email_hash($email);
            $row['email_ciphertext'] = self::encrypt($email);
        }

        if ($profile_id) {
            $wpdb->update(self::table('profiles'), $row, ['id' => $profile_id]);
            self::audit('profile.updated', $profile_id);
            return $profile_id;
        }

        $token = self::new_token();
        $row['manage_token_lookup'] = self::token_lookup($token);
        $row['manage_token_hash'] = wp_hash_password($token);
        $row['created_at'] = current_time('mysql');
        $wpdb->insert(self::table('profiles'), $row);
        $profile_id = absint($wpdb->insert_id);
        self::audit('profile.created', $profile_id);

        return $profile_id;
    }

    public static function issue_manage_token($profile_id) {
        global $wpdb;
        $profile_id = absint($profile_id);
        if (!$profile_id) {
            return '';
        }

        $token = self::new_token();
        $wpdb->update(self::table('profiles'), [
            'manage_token_lookup' => self::token_lookup($token),
            'manage_token_hash' => wp_hash_password($token),
            'updated_at' => current_time('mysql'),
        ], ['id' => $profile_id]);
        self::audit('profile.manage_token_issued', $profile_id);

        return $token;
    }

    public static function profile($profile_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('profiles') . ' WHERE id = %d', absint($profile_id)), ARRAY_A);

        return $row ? self::normalize_profile($row) : null;
    }

    public static function profile_by_email($email) {
        global $wpdb;
        $hash = self::email_hash(sanitize_email((string) $email));
        if (!$hash) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('profiles') . ' WHERE email_hash = %s ORDER BY id DESC LIMIT 1', $hash), ARRAY_A);

        return $row ? self::normalize_profile($row) : null;
    }

    public static function profile_by_user_id($user_id) {
        global $wpdb;

        $user_id = absint($user_id);
        if (!$user_id) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table('profiles') . ' WHERE user_id = %d ORDER BY id DESC LIMIT 1',
            $user_id
        ), ARRAY_A);

        return $row ? self::normalize_profile($row) : null;
    }

    public static function claim_profile_for_user($profile_id, $user_id, $email) {
        global $wpdb;

        $profile_id = absint($profile_id);
        $user_id = absint($user_id);
        $email_hash = self::email_hash($email);
        if (!$profile_id || !$user_id || !$email_hash) {
            return false;
        }

        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table('profiles') . ' SET user_id = %d, updated_at = %s WHERE id = %d AND email_hash = %s AND (user_id IS NULL OR user_id = 0 OR user_id = %d)',
            $user_id,
            current_time('mysql'),
            $profile_id,
            $email_hash,
            $user_id
        ));

        if ($updated > 0) {
            self::audit('profile.owner_claimed', $profile_id, 0, ['user_id' => $user_id]);
        }

        $profile = self::profile($profile_id);

        return $profile && absint($profile['user_id']) === $user_id;
    }

    public static function profile_by_manage_token($profile_id, $token) {
        global $wpdb;
        $profile_id = absint($profile_id);
        $token = (string) $token;
        if (!$profile_id || '' === $token) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table('profiles') . ' WHERE id = %d AND manage_token_lookup = %s',
            $profile_id,
            self::token_lookup($token)
        ), ARRAY_A);
        if (!$row || empty($row['manage_token_hash']) || !wp_check_password($token, $row['manage_token_hash'])) {
            return null;
        }

        return self::normalize_profile($row);
    }

    public static function grant_badge(array $data) {
        global $wpdb;

        $badge = self::badge(absint($data['badge_id'] ?? 0));
        if (!$badge || 'active' !== $badge['status']) {
            return new \WP_Error('invalid_badge', __('Choose an active badge before granting support proof.', 'mds-support-passport'));
        }

        $profile_id = absint($data['profile_id'] ?? 0);
        $profile = $profile_id ? self::profile($profile_id) : null;
        if (!$profile) {
            $existing = !empty($data['email']) ? self::profile_by_email($data['email']) : null;
            $profile_id = $existing ? absint($existing['id']) : self::save_profile($data);
            $profile = self::profile($profile_id);
        }

        if (!$profile) {
            return new \WP_Error('invalid_profile', __('Create or choose a supporter profile first.', 'mds-support-passport'));
        }

        $public_state = !empty($data['public_display']) ? 'public' : 'private';
        $grant_row = [
            'profile_id' => absint($profile['id']),
            'badge_id' => absint($badge['id']),
            'source_extension' => sanitize_key((string) ($data['source_extension'] ?? 'manual')) ?: 'manual',
            'source_entity_type' => sanitize_key((string) ($data['source_entity_type'] ?? '')),
            'source_entity_id' => sanitize_text_field((string) ($data['source_entity_id'] ?? '')),
            'order_reference' => sanitize_text_field((string) ($data['order_reference'] ?? '')),
            'public_display_state' => $public_state,
            'issued_at' => current_time('mysql'),
            'expires_at' => self::datetime_or_null($data['expires_at'] ?? ''),
            'created_by' => get_current_user_id() ?: null,
        ];
        $wpdb->insert(self::table('grants'), $grant_row);
        $grant_id = absint($wpdb->insert_id);

        $proof_token = self::new_token();
        $wpdb->insert(self::table('proofs'), [
            'grant_id' => $grant_id,
            'token_lookup' => self::token_lookup($proof_token),
            'token_hash' => wp_hash_password($proof_token),
            'status' => $public_state,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $proof_id = absint($wpdb->insert_id);
        $wpdb->update(self::table('grants'), ['proof_id' => $proof_id], ['id' => $grant_id]);

        self::save_consent([
            'profile_id' => absint($profile['id']),
            'purpose_key' => 'store_supporter_profile',
            'scope' => 'site',
            'state' => 'granted',
            'consent_source' => 'admin',
            'actor_type' => 'admin',
            'actor_id' => get_current_user_id(),
        ]);

        if ('public' === $public_state) {
            self::save_consent([
                'profile_id' => absint($profile['id']),
                'purpose_key' => 'display_badge_publicly',
                'scope' => 'grant:' . $grant_id,
                'state' => 'granted',
                'consent_source' => 'admin',
                'actor_type' => 'admin',
                'actor_id' => get_current_user_id(),
            ]);
            self::set_profile_visibility(absint($profile['id']), 'public');
        }

        $manage_token = self::issue_manage_token(absint($profile['id']));

        self::audit('grant.created', absint($profile['id']), $grant_id, ['badge_id' => absint($badge['id'])]);

        return [
            'grant_id' => $grant_id,
            'profile_id' => absint($profile['id']),
            'manage_token' => $manage_token,
            'proof_token' => $proof_token,
        ];
    }

    public static function revoke_grant($grant_id, $reason = '') {
        global $wpdb;
        $grant_id = absint($grant_id);
        $grant = self::grant($grant_id);
        if (!$grant || !empty($grant['revoked_at'])) {
            return false;
        }

        $wpdb->insert(self::table('revocations'), [
            'grant_id' => $grant_id,
            'reason' => sanitize_textarea_field((string) $reason),
            'actor_id' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ]);
        $revocation_id = absint($wpdb->insert_id);
        $wpdb->update(self::table('grants'), [
            'revoked_at' => current_time('mysql'),
            'revocation_id' => $revocation_id,
            'public_display_state' => 'revoked',
        ], ['id' => $grant_id]);
        if (!empty($grant['proof_id'])) {
            $wpdb->update(self::table('proofs'), [
                'status' => 'revoked',
                'updated_at' => current_time('mysql'),
            ], ['id' => absint($grant['proof_id'])]);
        }
        self::audit('grant.revoked', absint($grant['profile_id']), $grant_id, ['reason' => sanitize_textarea_field((string) $reason)]);

        return true;
    }

    public static function update_grant($grant_id, array $data) {
        global $wpdb;
        $grant_id = absint($grant_id);
        $grant = self::grant($grant_id);
        if (!$grant) {
            return new \WP_Error('invalid_grant', __('Choose a support grant to update.', 'mds-support-passport'));
        }

        $row = [
            'source_extension' => sanitize_key((string) ($data['source_extension'] ?? $grant['source_extension'])) ?: 'manual',
            'source_entity_type' => sanitize_key((string) ($data['source_entity_type'] ?? $grant['source_entity_type'])),
            'source_entity_id' => sanitize_text_field((string) ($data['source_entity_id'] ?? $grant['source_entity_id'])),
            'order_reference' => sanitize_text_field((string) ($data['order_reference'] ?? $grant['order_reference'])),
            'expires_at' => self::datetime_or_null($data['expires_at'] ?? $grant['expires_at']),
        ];
        $wpdb->update(self::table('grants'), $row, ['id' => $grant_id]);

        $profile = self::profile(absint($grant['profile_id']));
        if ($profile) {
            self::save_profile([
                'display_name' => $data['display_name'] ?? $profile['display_name'],
                'public_handle' => $data['public_handle'] ?? $profile['public_handle'],
                'visibility' => $profile['visibility'],
            ], absint($grant['profile_id']));
        }

        $state = sanitize_key((string) ($data['public_display_state'] ?? ''));
        if ($state && in_array($state, ['public', 'private'], true) && empty($grant['revoked_at']) && 'archived' !== $grant['public_display_state']) {
            self::set_grant_public_state($grant_id, absint($grant['profile_id']), $state, 'admin');
        }

        self::set_profile_visibility(
            absint($grant['profile_id']),
            self::profile_has_public_grants(absint($grant['profile_id'])) ? 'public' : 'private'
        );
        self::audit('grant.updated', absint($grant['profile_id']), $grant_id);

        return true;
    }

    public static function archive_grant($grant_id) {
        global $wpdb;
        $grant_id = absint($grant_id);
        $grant = self::grant($grant_id);
        if (!$grant) {
            return false;
        }

        $updated = $wpdb->update(self::table('grants'), [
            'public_display_state' => 'archived',
        ], ['id' => $grant_id]);
        if (!empty($grant['proof_id'])) {
            $wpdb->update(self::table('proofs'), [
                'status' => 'archived',
                'updated_at' => current_time('mysql'),
            ], ['id' => absint($grant['proof_id'])]);
        }

        self::set_profile_visibility(absint($grant['profile_id']), self::profile_has_public_grants(absint($grant['profile_id'])) ? 'public' : 'private');
        self::audit('grant.archived', absint($grant['profile_id']), $grant_id);

        return false !== $updated;
    }

    public static function issue_proof_token($grant_id) {
        global $wpdb;
        $grant_id = absint($grant_id);
        $grant = self::grant($grant_id);
        if (
            !$grant
            || !empty($grant['revoked_at'])
            || 'archived' === $grant['public_display_state']
            || 'active' !== $grant['badge_status']
            || self::is_expired($grant['expires_at'])
        ) {
            return new \WP_Error('invalid_grant', __('This grant cannot receive a new proof link.', 'mds-support-passport'));
        }

        $token = self::new_token();
        $row = [
            'grant_id' => $grant_id,
            'token_lookup' => self::token_lookup($token),
            'token_hash' => wp_hash_password($token),
            'status' => in_array($grant['public_display_state'], ['public', 'private'], true) ? $grant['public_display_state'] : 'private',
            'updated_at' => current_time('mysql'),
        ];

        if (!empty($grant['proof_id'])) {
            $wpdb->update(self::table('proofs'), $row, ['id' => absint($grant['proof_id'])]);
        } else {
            $row['created_at'] = current_time('mysql');
            $wpdb->insert(self::table('proofs'), $row);
            $wpdb->update(self::table('grants'), ['proof_id' => absint($wpdb->insert_id)], ['id' => $grant_id]);
        }

        self::audit('grant.proof_token_issued', absint($grant['profile_id']), $grant_id);

        return $token;
    }

    public static function grants(array $args = []) {
        global $wpdb;

        [$where, $values] = self::grant_query_conditions($args);
        $limit = array_key_exists('limit', $args) ? absint($args['limit']) : 100;
        $limit = $limit > 0 ? min(500, $limit) : 0;
        $offset = max(0, absint($args['offset'] ?? 0));

        $sql = 'SELECT g.*, b.title AS badge_title, b.description AS badge_description, b.icon_attachment_id AS badge_icon_attachment_id, b.color_token, b.status AS badge_status, p.display_name, p.public_handle, p.visibility, pr.status AS proof_status '
            . 'FROM ' . self::table('grants') . ' g '
            . 'INNER JOIN ' . self::table('badges') . ' b ON b.id = g.badge_id '
            . 'INNER JOIN ' . self::table('profiles') . ' p ON p.id = g.profile_id '
            . 'LEFT JOIN ' . self::table('proofs') . ' pr ON pr.id = g.proof_id '
            . 'WHERE ' . implode(' AND ', $where)
            . ' ORDER BY g.id DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT %d OFFSET %d';
            $values[] = $limit;
            $values[] = $offset;
        }

        $rows = $wpdb->get_results($values ? $wpdb->prepare($sql, $values) : $sql, ARRAY_A);

        return array_map([__CLASS__, 'normalize_grant'], $rows ?: []);
    }

    public static function grant_count(array $args = []) {
        global $wpdb;

        [$where, $values] = self::grant_query_conditions($args);
        $sql = 'SELECT COUNT(*) FROM ' . self::table('grants') . ' g '
            . 'INNER JOIN ' . self::table('badges') . ' b ON b.id = g.badge_id '
            . 'INNER JOIN ' . self::table('profiles') . ' p ON p.id = g.profile_id '
            . 'WHERE ' . implode(' AND ', $where);

        return absint($wpdb->get_var($values ? $wpdb->prepare($sql, $values) : $sql));
    }

    public static function grant($grant_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT g.*, b.title AS badge_title, b.description AS badge_description, b.icon_attachment_id AS badge_icon_attachment_id, b.color_token, b.status AS badge_status, p.display_name, p.public_handle, p.visibility, pr.status AS proof_status '
            . 'FROM ' . self::table('grants') . ' g '
            . 'INNER JOIN ' . self::table('badges') . ' b ON b.id = g.badge_id '
            . 'INNER JOIN ' . self::table('profiles') . ' p ON p.id = g.profile_id '
            . 'LEFT JOIN ' . self::table('proofs') . ' pr ON pr.id = g.proof_id '
            . 'WHERE g.id = %d LIMIT 1',
            absint($grant_id)
        ), ARRAY_A);

        return $rows ? self::normalize_grant($rows[0]) : null;
    }

    public static function proof_by_token($token) {
        global $wpdb;
        $lookup = self::token_lookup((string) $token);
        if (!$lookup) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT pr.*, g.profile_id, g.badge_id, g.public_display_state, g.issued_at, g.expires_at, g.revoked_at, b.title AS badge_title, b.description AS badge_description, b.icon_attachment_id AS badge_icon_attachment_id, b.color_token, b.status AS badge_status, p.display_name, p.public_handle, p.visibility '
            . 'FROM ' . self::table('proofs') . ' pr '
            . 'INNER JOIN ' . self::table('grants') . ' g ON g.id = pr.grant_id '
            . 'INNER JOIN ' . self::table('badges') . ' b ON b.id = g.badge_id '
            . 'INNER JOIN ' . self::table('profiles') . ' p ON p.id = g.profile_id '
            . 'WHERE pr.token_lookup = %s LIMIT 1',
            $lookup
        ), ARRAY_A);

        if (!$row || empty($row['token_hash']) || !wp_check_password((string) $token, $row['token_hash'])) {
            return null;
        }

        return self::public_proof_payload($row);
    }

    public static function save_consent(array $data) {
        global $wpdb;
        $profile_id = absint($data['profile_id'] ?? 0);
        if (!$profile_id) {
            return 0;
        }

        $state = sanitize_key((string) ($data['state'] ?? 'granted'));
        if (!in_array($state, ['granted', 'withdrawn', 'superseded'], true)) {
            $state = 'granted';
        }

        $wpdb->insert(self::table('consent'), [
            'profile_id' => $profile_id,
            'purpose_key' => sanitize_key((string) ($data['purpose_key'] ?? 'supporter_profile')),
            'scope' => sanitize_text_field((string) ($data['scope'] ?? 'site')),
            'state' => $state,
            'consent_source' => sanitize_key((string) ($data['consent_source'] ?? 'system')),
            'copy_version' => sanitize_text_field((string) ($data['copy_version'] ?? MDS_SUPPORT_PASSPORT_VERSION)),
            'terms_hash' => sanitize_text_field((string) ($data['terms_hash'] ?? '')),
            'actor_type' => sanitize_key((string) ($data['actor_type'] ?? 'system')),
            'actor_id' => absint($data['actor_id'] ?? 0) ?: null,
            'ip_hash' => self::request_hash('ip'),
            'user_agent_hash' => self::request_hash('ua'),
            'created_at' => current_time('mysql'),
        ]);
        $consent_id = absint($wpdb->insert_id);
        $wpdb->update(self::table('profiles'), [
            'last_consent_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $profile_id]);
        self::audit('consent.' . $state, $profile_id, 0, ['purpose' => sanitize_key((string) ($data['purpose_key'] ?? 'supporter_profile'))]);

        return $consent_id;
    }

    public static function set_grant_public_state($grant_id, $profile_id, $state, $source = 'manage_link') {
        global $wpdb;

        $grant_id = absint($grant_id);
        $profile_id = absint($profile_id);
        $state = sanitize_key((string) $state);
        if (!in_array($state, ['public', 'private'], true)) {
            $state = 'private';
        }

        $grant = self::grant($grant_id);
        if (!$grant || absint($grant['profile_id']) !== $profile_id || !empty($grant['revoked_at'])) {
            return false;
        }
        if ('public' === $state && ('active' !== $grant['badge_status'] || self::is_expired($grant['expires_at']))) {
            return false;
        }

        $wpdb->update(self::table('grants'), [
            'public_display_state' => $state,
        ], ['id' => $grant_id]);

        if (!empty($grant['proof_id'])) {
            $wpdb->update(self::table('proofs'), [
                'status' => $state,
                'updated_at' => current_time('mysql'),
            ], ['id' => absint($grant['proof_id'])]);
        }

        self::save_consent([
            'profile_id' => $profile_id,
            'purpose_key' => 'display_badge_publicly',
            'scope' => 'grant:' . $grant_id,
            'state' => 'public' === $state ? 'granted' : 'withdrawn',
            'consent_source' => $source,
            'actor_type' => is_user_logged_in() ? 'supporter' : 'manage_token',
            'actor_id' => get_current_user_id(),
        ]);

        self::set_profile_visibility($profile_id, self::profile_has_public_grants($profile_id) ? 'public' : 'private');
        self::audit('grant.public_display_' . $state, $profile_id, $grant_id);

        return true;
    }

    public static function profile_has_public_grants($profile_id) {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table('grants') . ' g '
            . 'INNER JOIN ' . self::table('badges') . " b ON b.id = g.badge_id AND b.status = 'active' "
            . "WHERE g.profile_id = %d AND g.public_display_state = 'public' AND g.revoked_at IS NULL AND (g.expires_at IS NULL OR g.expires_at > %s)",
            absint($profile_id),
            current_time('mysql')
        ));
    }

    public static function consent_for_profile($profile_id, $limit = 100, $offset = 0) {
        global $wpdb;

        $limit = absint($limit);
        $sql = 'SELECT * FROM ' . self::table('consent') . ' WHERE profile_id = %d ORDER BY id DESC';
        $values = [absint($profile_id)];
        if ($limit > 0) {
            $sql .= ' LIMIT %d OFFSET %d';
            $values[] = min(500, $limit);
            $values[] = max(0, absint($offset));
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

        return $rows ?: [];
    }

    public static function consent_count($profile_id) {
        global $wpdb;

        return absint($wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table('consent') . ' WHERE profile_id = %d',
            absint($profile_id)
        )));
    }

    public static function public_directory($search = '', $limit = 50, $offset = 0) {
        global $wpdb;
        $settings = self::settings();
        if ('1' !== (string) ($settings['directory_enabled'] ?? '0')) {
            return [];
        }

        [$where, $values] = self::public_directory_conditions($search, $settings);
        $values[] = min(100, max(1, absint($limit)));
        $values[] = max(0, absint($offset));

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT g.*, b.title AS badge_title, b.description AS badge_description, b.icon_attachment_id AS badge_icon_attachment_id, b.color_token, b.status AS badge_status, p.display_name, p.public_handle, p.visibility '
            . 'FROM ' . self::table('grants') . ' g '
            . 'INNER JOIN ' . self::table('badges') . ' b ON b.id = g.badge_id '
            . 'INNER JOIN ' . self::table('profiles') . ' p ON p.id = g.profile_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY g.issued_at DESC LIMIT %d OFFSET %d',
            $values
        ), ARRAY_A);

        return array_map([__CLASS__, 'normalize_grant'], $rows ?: []);
    }

    public static function public_directory_count($search = '') {
        global $wpdb;

        $settings = self::settings();
        if ('1' !== (string) ($settings['directory_enabled'] ?? '0')) {
            return 0;
        }

        [$where, $values] = self::public_directory_conditions($search, $settings);
        $sql = 'SELECT COUNT(*) FROM ' . self::table('grants') . ' g '
            . 'INNER JOIN ' . self::table('badges') . ' b ON b.id = g.badge_id '
            . 'INNER JOIN ' . self::table('profiles') . ' p ON p.id = g.profile_id '
            . 'WHERE ' . implode(' AND ', $where);

        return absint($wpdb->get_var($wpdb->prepare($sql, $values)));
    }

    public static function export_by_email($email, $page = 1) {
        $profile = self::profile_by_email($email);
        if (!$profile) {
            return ['data' => [], 'done' => true];
        }

        $profile_id = absint($profile['id']);
        $page = max(1, absint($page));
        $per_page = 50;
        $cursor = ($page - 1) * $per_page;
        $remaining = $per_page;
        $grant_count = self::grant_count(['profile_id' => $profile_id]);
        $consent_count = self::consent_count($profile_id);
        $total = 1 + $grant_count + $consent_count;
        $items = [];

        if (0 === $cursor) {
            $items[] = [
                'group_id' => 'mds-support-passport',
                'group_label' => __('Proof of Support Passport', 'mds-support-passport'),
                'item_id' => 'mds-support-passport-profile-' . $profile_id,
                'data' => [
                    ['name' => __('Profile ID', 'mds-support-passport'), 'value' => $profile_id],
                    ['name' => __('Display name', 'mds-support-passport'), 'value' => $profile['display_name']],
                    ['name' => __('Public handle', 'mds-support-passport'), 'value' => $profile['public_handle']],
                    ['name' => __('Visibility', 'mds-support-passport'), 'value' => $profile['visibility']],
                    ['name' => __('Created', 'mds-support-passport'), 'value' => $profile['created_at']],
                ],
            ];
            ++$cursor;
            --$remaining;
        }

        if ($remaining > 0 && $cursor >= 1 && $cursor < 1 + $grant_count) {
            $grant_offset = $cursor - 1;
            $grant_limit = min($remaining, $grant_count - $grant_offset);
            foreach (self::grants(['profile_id' => $profile_id, 'limit' => $grant_limit, 'offset' => $grant_offset]) as $grant) {
                $items[] = self::grant_export_item($grant);
            }
            $cursor += $grant_limit;
            $remaining -= $grant_limit;
        }

        if ($remaining > 0 && $cursor >= 1 + $grant_count) {
            $consent_offset = $cursor - 1 - $grant_count;
            $consent_limit = min($remaining, max(0, $consent_count - $consent_offset));
            if ($consent_limit > 0) {
                foreach (self::consent_for_profile($profile_id, $consent_limit, $consent_offset) as $consent) {
                    $items[] = self::consent_export_item($consent);
                }
            }
        }

        return [
            'data' => $items,
            'done' => (($page - 1) * $per_page) + count($items) >= $total,
        ];
    }

    private static function grant_export_item(array $grant) {
        return [
            'group_id' => 'mds-support-passport-grants',
            'group_label' => __('Support Badges', 'mds-support-passport'),
            'item_id' => 'mds-support-passport-grant-' . absint($grant['id']),
            'data' => [
                ['name' => __('Badge', 'mds-support-passport'), 'value' => $grant['badge_title']],
                ['name' => __('Source', 'mds-support-passport'), 'value' => $grant['source_extension']],
                ['name' => __('Public display', 'mds-support-passport'), 'value' => $grant['public_display_state']],
                ['name' => __('Issued', 'mds-support-passport'), 'value' => $grant['issued_at']],
                ['name' => __('Revoked', 'mds-support-passport'), 'value' => $grant['revoked_at']],
            ],
        ];
    }

    private static function consent_export_item(array $consent) {
        return [
            'group_id' => 'mds-support-passport-consent',
            'group_label' => __('Support Passport Consent', 'mds-support-passport'),
            'item_id' => 'mds-support-passport-consent-' . absint($consent['id']),
            'data' => [
                ['name' => __('Purpose', 'mds-support-passport'), 'value' => $consent['purpose_key']],
                ['name' => __('Scope', 'mds-support-passport'), 'value' => $consent['scope']],
                ['name' => __('State', 'mds-support-passport'), 'value' => $consent['state']],
                ['name' => __('Source', 'mds-support-passport'), 'value' => $consent['consent_source']],
                ['name' => __('Created', 'mds-support-passport'), 'value' => $consent['created_at']],
            ],
        ];
    }

    public static function erase_by_email($email) {
        global $wpdb;
        $profile = self::profile_by_email($email);
        if (!$profile) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $profile_id = absint($profile['id']);
        $wpdb->update(self::table('profiles'), [
            'email_ciphertext' => null,
            'email_hash' => null,
            'display_name' => '',
            'public_handle' => '',
            'avatar_attachment_id' => 0,
            'visibility' => 'private',
            'manage_token_lookup' => null,
            'manage_token_hash' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $profile_id]);
        $wpdb->update(self::table('grants'), ['public_display_state' => 'private'], ['profile_id' => $profile_id]);
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table('proofs') . ' pr INNER JOIN ' . self::table('grants') . ' g ON g.proof_id = pr.id SET pr.status = %s, pr.updated_at = %s WHERE g.profile_id = %d',
            'private',
            current_time('mysql'),
            $profile_id
        ));
        self::save_consent([
            'profile_id' => $profile_id,
            'purpose_key' => 'privacy_erasure',
            'scope' => 'profile',
            'state' => 'withdrawn',
            'consent_source' => 'privacy_request',
            'actor_type' => 'system',
        ]);
        self::audit('profile.erased', $profile_id);

        return [
            'items_removed' => true,
            'items_retained' => true,
            'messages' => [__('Support Passport profile identifiers were anonymized. Minimal audit and grant records may be retained for revocation, fraud, dispute, and compliance records.', 'mds-support-passport')],
            'done' => true,
        ];
    }

    public static function set_profile_visibility($profile_id, $visibility) {
        global $wpdb;
        $visibility = sanitize_key((string) $visibility);
        if (!in_array($visibility, ['private', 'unlisted', 'public'], true)) {
            $visibility = 'private';
        }

        return false !== $wpdb->update(self::table('profiles'), [
            'visibility' => $visibility,
            'updated_at' => current_time('mysql'),
        ], ['id' => absint($profile_id)]);
    }

    public static function audit($event_type, $profile_id = 0, $grant_id = 0, array $details = []) {
        global $wpdb;
        $wpdb->insert(self::table('audit'), [
            'profile_id' => absint($profile_id) ?: null,
            'grant_id' => absint($grant_id) ?: null,
            'event_type' => sanitize_key((string) $event_type),
            'actor_type' => is_user_logged_in() ? 'admin' : 'system',
            'actor_id' => get_current_user_id() ?: null,
            'details' => wp_json_encode($details),
            'created_at' => current_time('mysql'),
        ]);
    }

    public static function anonymize_stale_private_profiles($cutoff, $limit = 100) {
        global $wpdb;

        $cutoff = self::datetime_or_null($cutoff);
        $limit = min(500, max(1, absint($limit)));
        if (!$cutoff) {
            return 0;
        }

        $profile_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT p.id FROM ' . self::table('profiles') . ' p '
            . "WHERE p.visibility = 'private' AND (p.user_id IS NULL OR p.user_id = 0) AND p.updated_at < %s "
            . 'AND (p.email_hash IS NOT NULL OR p.email_ciphertext IS NOT NULL OR p.display_name <> %s OR p.public_handle <> %s OR p.manage_token_hash IS NOT NULL) '
            . 'AND NOT EXISTS ('
            . 'SELECT 1 FROM ' . self::table('grants') . ' g '
            . "INNER JOIN " . self::table('badges') . " b ON b.id = g.badge_id AND b.status = 'active' "
            . "WHERE g.profile_id = p.id AND g.revoked_at IS NULL AND g.public_display_state <> 'archived' AND (g.expires_at IS NULL OR g.expires_at > %s)"
            . ') ORDER BY p.id ASC LIMIT %d',
            $cutoff,
            '',
            '',
            current_time('mysql'),
            $limit
        ));

        $anonymized = 0;
        foreach (array_map('absint', $profile_ids ?: []) as $profile_id) {
            $updated = $wpdb->update(self::table('profiles'), [
                'email_ciphertext' => null,
                'email_hash' => null,
                'display_name' => '',
                'public_handle' => '',
                'avatar_attachment_id' => 0,
                'manage_token_lookup' => null,
                'manage_token_hash' => null,
                'updated_at' => current_time('mysql'),
            ], ['id' => $profile_id]);
            if (false !== $updated) {
                ++$anonymized;
                self::audit('profile.retention_anonymized', $profile_id);
            }
        }

        return $anonymized;
    }

    public static function privatize_profiles_without_public_grants($limit = 500) {
        global $wpdb;

        $limit = min(2000, max(1, absint($limit)));
        $profile_ids = $wpdb->get_col($wpdb->prepare(
            'SELECT p.id FROM ' . self::table('profiles') . ' p '
            . "WHERE p.visibility = 'public' AND NOT EXISTS ("
            . 'SELECT 1 FROM ' . self::table('grants') . ' g '
            . "INNER JOIN " . self::table('badges') . " b ON b.id = g.badge_id AND b.status = 'active' "
            . "WHERE g.profile_id = p.id AND g.public_display_state = 'public' AND g.revoked_at IS NULL AND (g.expires_at IS NULL OR g.expires_at > %s)"
            . ') ORDER BY p.id ASC LIMIT %d',
            current_time('mysql'),
            $limit
        ));

        $updated = 0;
        foreach (array_map('absint', $profile_ids ?: []) as $profile_id) {
            if (false !== $wpdb->update(self::table('profiles'), [
                'visibility' => 'private',
                'updated_at' => current_time('mysql'),
            ], ['id' => $profile_id])) {
                ++$updated;
            }
        }

        return $updated;
    }

    public static function delete_old_audit_entries($cutoff, $limit = 500) {
        global $wpdb;

        $cutoff = self::datetime_or_null($cutoff);
        $limit = min(2000, max(1, absint($limit)));
        if (!$cutoff) {
            return 0;
        }

        return absint($wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table('audit') . ' WHERE created_at < %s ORDER BY id ASC LIMIT %d',
            $cutoff,
            $limit
        )));
    }

    public static function counts() {
        global $wpdb;

        $now = current_time('mysql');
        $public_grants_sql = $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table('grants') . ' g '
            . 'INNER JOIN ' . self::table('badges') . " b ON b.id = g.badge_id AND b.status = 'active' "
            . "WHERE g.public_display_state = 'public' AND g.revoked_at IS NULL AND (g.expires_at IS NULL OR g.expires_at > %s)",
            $now
        );

        return [
            'profiles' => absint($wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('profiles'))),
            'badges' => absint($wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('badges'))),
            'grants' => absint($wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('grants'))),
            'public_grants' => absint($wpdb->get_var($public_grants_sql)),
            'revoked_grants' => absint($wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('grants') . ' WHERE revoked_at IS NOT NULL')),
        ];
    }

    public static function public_proof_payload(array $row) {
        $state = sanitize_key((string) ($row['public_display_state'] ?? $row['status'] ?? 'private'));
        $revoked = !empty($row['revoked_at']);
        $expired = self::is_expired($row['expires_at'] ?? '');
        $badge_active = 'active' === sanitize_key((string) ($row['badge_status'] ?? 'active'));
        $public = 'public' === $state && !$revoked && !$expired && $badge_active && 'public' === (string) ($row['visibility'] ?? 'private');
        $status = $public ? 'public' : 'private';
        if ($revoked) {
            $status = 'revoked';
        } elseif ($expired) {
            $status = 'expired';
        } elseif (!$badge_active) {
            $status = 'inactive';
        }

        return [
            'grant_id' => absint($row['grant_id'] ?? 0),
            'badge_id' => absint($row['badge_id'] ?? 0),
            'badge_title' => sanitize_text_field((string) ($row['badge_title'] ?? '')),
            'badge_description' => sanitize_textarea_field((string) ($row['badge_description'] ?? '')),
            'icon_attachment_id' => absint($row['badge_icon_attachment_id'] ?? 0),
            'color_token' => sanitize_key((string) ($row['color_token'] ?? 'blue')),
            'supporter_name' => $public ? self::safe_public_name($row) : '',
            'issued_at' => sanitize_text_field((string) ($row['issued_at'] ?? '')),
            'expires_at' => sanitize_text_field((string) ($row['expires_at'] ?? '')),
            'status' => $status,
            'public' => $public,
        ];
    }

    public static function safe_public_name(array $profile) {
        $handle = sanitize_title((string) ($profile['public_handle'] ?? ''));
        if ($handle) {
            return '@' . $handle;
        }

        $name = sanitize_text_field((string) ($profile['display_name'] ?? ''));

        return $name ?: __('Supporter', 'mds-support-passport');
    }

    private static function grant_query_conditions(array $args) {
        global $wpdb;

        $where = ['1=1'];
        $values = [];
        if (!empty($args['profile_id'])) {
            $where[] = 'g.profile_id = %d';
            $values[] = absint($args['profile_id']);
        }
        if (!empty($args['public_only'])) {
            $where[] = "g.public_display_state = 'public'";
            $where[] = 'g.revoked_at IS NULL';
            $where[] = "b.status = 'active'";
            $where[] = '(g.expires_at IS NULL OR g.expires_at > %s)';
            $values[] = current_time('mysql');
        }
        $public_state = sanitize_key((string) ($args['public_display_state'] ?? ''));
        if ($public_state && in_array($public_state, ['private', 'public', 'archived', 'revoked'], true)) {
            $where[] = 'g.public_display_state = %s';
            $values[] = $public_state;
        }
        $source_extension = sanitize_key((string) ($args['source_extension'] ?? ''));
        if ($source_extension) {
            $where[] = 'g.source_extension = %s';
            $values[] = $source_extension;
        }
        $revoked = sanitize_key((string) ($args['revoked'] ?? ''));
        if ('yes' === $revoked) {
            $where[] = 'g.revoked_at IS NOT NULL';
        } elseif ('no' === $revoked) {
            $where[] = 'g.revoked_at IS NULL';
        }
        $search = sanitize_text_field((string) ($args['search'] ?? ''));
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(b.title LIKE %s OR p.display_name LIKE %s OR p.public_handle LIKE %s OR g.order_reference LIKE %s OR g.source_entity_id LIKE %s)';
            $values = array_merge($values, [$like, $like, $like, $like, $like]);
        }

        return [$where, $values];
    }

    private static function public_directory_conditions($search, array $settings) {
        global $wpdb;

        $where = [
            "p.visibility = 'public'",
            "g.public_display_state = 'public'",
            'g.revoked_at IS NULL',
            "b.status = 'active'",
            '(g.expires_at IS NULL OR g.expires_at > %s)',
        ];
        $values = [current_time('mysql')];
        $search = sanitize_text_field((string) $search);
        if ($search && '1' === (string) ($settings['directory_search_enabled'] ?? '1')) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(p.display_name LIKE %s OR p.public_handle LIKE %s OR b.title LIKE %s)';
            $values = array_merge($values, [$like, $like, $like]);
        }

        return [$where, $values];
    }

    private static function is_expired($expires_at) {
        $expires_at = trim((string) $expires_at);
        if ('' === $expires_at || '0000-00-00 00:00:00' === $expires_at) {
            return false;
        }

        $expires = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expires_at, wp_timezone());

        return $expires instanceof \DateTimeImmutable && $expires <= current_datetime();
    }

    private static function normalize_badge(array $row) {
        return [
            'id' => absint($row['id'] ?? 0),
            'title' => sanitize_text_field((string) ($row['title'] ?? '')),
            'description' => sanitize_textarea_field((string) ($row['description'] ?? '')),
            'icon_attachment_id' => absint($row['icon_attachment_id'] ?? 0),
            'color_token' => sanitize_key((string) ($row['color_token'] ?? 'blue')),
            'eligibility_source' => sanitize_key((string) ($row['eligibility_source'] ?? 'manual')),
            'eligibility_note' => sanitize_textarea_field((string) ($row['eligibility_note'] ?? '')),
            'public_eligibility' => !empty($row['public_eligibility']),
            'status' => sanitize_key((string) ($row['status'] ?? 'active')),
            'created_at' => sanitize_text_field((string) ($row['created_at'] ?? '')),
            'updated_at' => sanitize_text_field((string) ($row['updated_at'] ?? '')),
        ];
    }

    private static function normalize_profile(array $row) {
        return [
            'id' => absint($row['id'] ?? 0),
            'user_id' => absint($row['user_id'] ?? 0),
            'email' => self::decrypt((string) ($row['email_ciphertext'] ?? '')),
            'email_hash' => sanitize_text_field((string) ($row['email_hash'] ?? '')),
            'display_name' => sanitize_text_field((string) ($row['display_name'] ?? '')),
            'public_handle' => sanitize_title((string) ($row['public_handle'] ?? '')),
            'avatar_attachment_id' => absint($row['avatar_attachment_id'] ?? 0),
            'visibility' => sanitize_key((string) ($row['visibility'] ?? 'private')),
            'created_at' => sanitize_text_field((string) ($row['created_at'] ?? '')),
            'updated_at' => sanitize_text_field((string) ($row['updated_at'] ?? '')),
            'last_consent_at' => sanitize_text_field((string) ($row['last_consent_at'] ?? '')),
        ];
    }

    private static function normalize_grant(array $row) {
        return [
            'id' => absint($row['id'] ?? 0),
            'profile_id' => absint($row['profile_id'] ?? 0),
            'badge_id' => absint($row['badge_id'] ?? 0),
            'badge_title' => sanitize_text_field((string) ($row['badge_title'] ?? '')),
            'badge_description' => sanitize_textarea_field((string) ($row['badge_description'] ?? '')),
            'icon_attachment_id' => absint($row['badge_icon_attachment_id'] ?? 0),
            'color_token' => sanitize_key((string) ($row['color_token'] ?? 'blue')),
            'badge_status' => sanitize_key((string) ($row['badge_status'] ?? 'active')),
            'supporter_name' => self::safe_public_name($row),
            'visibility' => sanitize_key((string) ($row['visibility'] ?? 'private')),
            'source_extension' => sanitize_key((string) ($row['source_extension'] ?? 'manual')),
            'source_entity_type' => sanitize_key((string) ($row['source_entity_type'] ?? '')),
            'source_entity_id' => sanitize_text_field((string) ($row['source_entity_id'] ?? '')),
            'order_reference' => sanitize_text_field((string) ($row['order_reference'] ?? '')),
            'public_display_state' => sanitize_key((string) ($row['public_display_state'] ?? 'private')),
            'profile_display_name' => sanitize_text_field((string) ($row['display_name'] ?? '')),
            'profile_public_handle' => sanitize_title((string) ($row['public_handle'] ?? '')),
            'issued_at' => sanitize_text_field((string) ($row['issued_at'] ?? '')),
            'expires_at' => sanitize_text_field((string) ($row['expires_at'] ?? '')),
            'revoked_at' => sanitize_text_field((string) ($row['revoked_at'] ?? '')),
            'proof_id' => absint($row['proof_id'] ?? 0),
            'proof_status' => sanitize_key((string) ($row['proof_status'] ?? 'private')),
        ];
    }

    private static function email_hash($email) {
        $email = sanitize_email((string) $email);
        if (!$email) {
            return '';
        }

        return hash_hmac('sha256', strtolower($email), wp_salt('auth'));
    }

    private static function token_lookup($token) {
        $token = trim((string) $token);
        if ('' === $token) {
            return '';
        }

        return hash_hmac('sha256', $token, wp_salt('secure_auth'));
    }

    private static function new_token() {
        return wp_generate_uuid4() . '-' . wp_generate_password(24, false, false);
    }

    private static function encrypt($value) {
        $value = (string) $value;
        if ('' === $value || !function_exists('openssl_encrypt')) {
            return '';
        }

        $iv = random_bytes(16);
        $key = hash('sha256', wp_salt('auth'), true);
        $cipher = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if (false === $cipher) {
            return '';
        }

        return base64_encode($iv . $cipher);
    }

    private static function decrypt($ciphertext) {
        $ciphertext = (string) $ciphertext;
        if ('' === $ciphertext || !function_exists('openssl_decrypt')) {
            return '';
        }

        $decoded = base64_decode($ciphertext, true);
        if (!$decoded || strlen($decoded) <= 16) {
            return '';
        }

        $iv = substr($decoded, 0, 16);
        $cipher = substr($decoded, 16);
        $key = hash('sha256', wp_salt('auth'), true);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return false === $plain ? '' : sanitize_email($plain);
    }

    private static function request_hash($type) {
        $value = 'ua' === $type ? ($_SERVER['HTTP_USER_AGENT'] ?? '') : ($_SERVER['REMOTE_ADDR'] ?? '');
        $value = sanitize_text_field(wp_unslash((string) $value));

        return $value ? hash_hmac('sha256', $value, wp_salt('nonce')) : '';
    }

    private static function datetime_or_null($value) {
        $value = sanitize_text_field((string) $value);
        if ('' === $value) {
            return null;
        }

        $timestamp = strtotime($value);
        if (!$timestamp) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

}
