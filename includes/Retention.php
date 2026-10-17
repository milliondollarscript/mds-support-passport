<?php
/**
 * Scheduled privacy retention for Support Passport data.
 *
 * @package MillionDollarScript\Extensions\SupportPassport
 */

namespace MillionDollarScript\Extensions\SupportPassport;

if (!defined('ABSPATH')) {
    exit;
}

final class Retention {

    public const HOOK = 'mds_support_passport_daily_retention';

    public static function register() {
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('init', [__CLASS__, 'schedule']);
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function clear() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run() {
        $settings = Repository::settings();
        $now = current_datetime();
        $profile_months = min(120, max(1, absint($settings['retention_profile_months'] ?? 24)));
        $audit_years = min(20, max(1, absint($settings['retention_audit_years'] ?? 6)));
        $profile_cutoff = $now->sub(new \DateInterval('P' . $profile_months . 'M'))->format('Y-m-d H:i:s');
        $audit_cutoff = $now->sub(new \DateInterval('P' . $audit_years . 'Y'))->format('Y-m-d H:i:s');

        return [
            'profiles_made_private' => Repository::privatize_profiles_without_public_grants(500),
            'profiles_anonymized' => Repository::anonymize_stale_private_profiles($profile_cutoff, 100),
            'audit_rows_deleted' => Repository::delete_old_audit_entries($audit_cutoff, 1000),
        ];
    }
}
