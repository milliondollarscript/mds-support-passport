<?php
/**
 * Security and lifecycle fixture for Proof of Support Passport.
 *
 * Run with:
 * ./scripts/wp eval-file wp-content/plugins/mds-support-passport/tests/security-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

use MillionDollarScript\Extensions\SupportPassport\Repository;
use MillionDollarScript\Extensions\SupportPassport\Retention;

if (!class_exists(Repository::class) || !class_exists(Retention::class)) {
    fwrite(STDERR, "Support Passport security dependencies are not loaded.\n");
    exit(1);
}

global $wpdb;

$fixture_profile_ids = [];
$fixture_badge_ids = [];
$fixture_user_ids = [];
$previous_settings = Repository::settings();
$exit_code = 0;

$assert = static function ($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$track_profile = static function ($profile_id) use (&$fixture_profile_ids) {
    $profile_id = absint($profile_id);
    if ($profile_id) {
        $fixture_profile_ids[] = $profile_id;
    }
};

try {
    Repository::install();
    do_action('rest_api_init');

    $suffix = strtolower(wp_generate_password(10, false, false));
    $attacker_email = 'passport-owner-' . $suffix . '@example.test';
    $victim_email = 'passport-victim-' . $suffix . '@example.test';
    $attacker_id = wp_create_user('passport-owner-' . $suffix, wp_generate_password(24), $attacker_email);
    $assert(!is_wp_error($attacker_id), 'Could not create the subscriber ownership fixture.');
    $fixture_user_ids[] = absint($attacker_id);
    (new WP_User($attacker_id))->set_role('subscriber');

    $victim_profile_id = Repository::save_profile([
        'email' => $victim_email,
        'display_name' => 'Victim Profile',
        'visibility' => 'private',
    ]);
    $track_profile($victim_profile_id);

    wp_set_current_user($attacker_id);
    $create_request = new WP_REST_Request('POST', '/million-dollar-script/v1/support-passport/profile');
    $create_request->set_param('email', $victim_email);
    $create_request->set_param('display_name', 'Account Owner');
    $create_response = rest_do_request($create_request);
    $create_data = $create_response->get_data();
    $owner_profile = is_array($create_data['profile'] ?? null) ? $create_data['profile'] : [];
    $owner_profile_id = absint($owner_profile['id'] ?? 0);
    $track_profile($owner_profile_id);
    $assert(in_array($create_response->get_status(), [200, 201], true), 'Authenticated profile creation failed.');
    $assert($owner_profile_id > 0, 'Authenticated profile creation did not return a profile.');
    $assert($attacker_email === (string) ($owner_profile['email'] ?? ''), 'A subscriber could create a profile for a different email address.');
    $assert(absint($owner_profile['user_id'] ?? 0) === absint($attacker_id), 'The new profile was not linked to its WordPress account.');
    $assert(0 === absint(Repository::profile($victim_profile_id)['user_id'] ?? 0), 'A subscriber claimed an unrelated existing profile.');

    $update_request = new WP_REST_Request('POST', '/million-dollar-script/v1/support-passport/profile');
    $update_request->set_param('profile_id', $owner_profile_id);
    $update_request->set_param('email', $victim_email);
    $update_request->set_param('display_name', 'Updated Account Owner');
    $update_response = rest_do_request($update_request);
    $updated_owner = $update_response->get_data()['profile'] ?? [];
    $assert(200 === $update_response->get_status(), 'The account owner could not update their profile.');
    $assert($attacker_email === (string) ($updated_owner['email'] ?? ''), 'An owner-session update replaced the protected profile email.');

    $claim_request = new WP_REST_Request('POST', '/million-dollar-script/v1/support-passport/profile');
    $claim_request->set_param('profile_id', $victim_profile_id);
    $claim_request->set_param('display_name', 'Claim Attempt');
    $claim_response = rest_do_request($claim_request);
    $assert($claim_response->get_status() >= 400, 'A subscriber could update a profile they do not own.');

    wp_set_current_user(1);
    $badge_id = Repository::save_badge([
        'title' => 'Security Fixture Badge ' . $suffix,
        'description' => 'Lifecycle fixture badge.',
        'color_token' => 'blue',
        'eligibility_source' => 'fixture',
        'status' => 'active',
    ]);
    $second_badge_id = Repository::save_badge([
        'title' => 'Security Fixture Badge Two ' . $suffix,
        'description' => 'Pagination fixture badge.',
        'color_token' => 'green',
        'eligibility_source' => 'fixture',
        'status' => 'active',
    ]);
    $fixture_badge_ids = [absint($badge_id), absint($second_badge_id)];
    $assert($badge_id && $second_badge_id, 'Could not create lifecycle fixture badges.');

    $grants = [];
    foreach ([1, 2] as $index) {
        $grant = Repository::grant_badge([
            'badge_id' => $badge_id,
            'email' => 'passport-public-' . $index . '-' . $suffix . '@example.test',
            'display_name' => 'Security Pagination Supporter ' . $index,
            'public_handle' => 'security-pagination-' . $index . '-' . $suffix,
            'source_extension' => 'security-fixture',
            'source_entity_type' => 'test',
            'source_entity_id' => $suffix . '-' . $index,
            'public_display' => true,
        ]);
        $assert(!is_wp_error($grant), 'Could not create a public lifecycle fixture grant.');
        $track_profile($grant['profile_id']);
        $grants[] = $grant;
    }

    Repository::save_settings(array_merge($previous_settings, [
        'directory_enabled' => '1',
        'directory_search_enabled' => '1',
        'retention_profile_months' => 1,
        'retention_audit_years' => 1,
    ]));

    $first_grant = $grants[0];
    $public_proof_request = new WP_REST_Request('GET', '/million-dollar-script/v1/support-passport/proofs/' . rawurlencode($first_grant['proof_token']));
    $assert(200 === rest_do_request($public_proof_request)->get_status(), 'An eligible public proof was not available through REST.');
    $public_before = Repository::counts()['public_grants'];
    $wpdb->update(Repository::table('grants'), ['expires_at' => '2000-01-01 00:00:00'], ['id' => absint($first_grant['grant_id'])]);
    $expired_proof = Repository::proof_by_token($first_grant['proof_token']);
    $assert($expired_proof && empty($expired_proof['public']) && 'expired' === $expired_proof['status'], 'An expired proof remained public.');
    $assert(404 === rest_do_request($public_proof_request)->get_status(), 'An expired proof remained available through public REST.');
    $assert(!Repository::public_directory('security-pagination-1-' . $suffix, 10), 'An expired grant remained in the public directory.');
    $assert(!Repository::grants(['profile_id' => $first_grant['profile_id'], 'public_only' => true]), 'An expired grant remained in public-only results.');
    $assert(Repository::counts()['public_grants'] < $public_before, 'The public grant metric counted an expired grant.');
    $assert(!Repository::set_grant_public_state($first_grant['grant_id'], $first_grant['profile_id'], 'public', 'fixture'), 'An expired grant could be made public again.');

    $wpdb->update(Repository::table('grants'), ['expires_at' => '2099-01-01 00:00:00'], ['id' => absint($first_grant['grant_id'])]);
    $assert(Repository::update_badge_status($badge_id, 'inactive'), 'Could not deactivate the lifecycle fixture badge.');
    $inactive_proof = Repository::proof_by_token($first_grant['proof_token']);
    $assert($inactive_proof && empty($inactive_proof['public']) && 'inactive' === $inactive_proof['status'], 'A proof for an inactive badge remained public.');
    $assert(!Repository::public_directory('security-pagination', 10), 'Inactive badge grants remained in the public directory.');
    $assert(!Repository::grants(['profile_id' => $first_grant['profile_id'], 'public_only' => true]), 'Inactive badge grants remained in public-only results.');
    $assert(Repository::update_badge_status($badge_id, 'active'), 'Could not reactivate the lifecycle fixture badge.');

    $directory_total = Repository::public_directory_count('security-pagination');
    $directory_page_one = Repository::public_directory('security-pagination', 1, 0);
    $directory_page_two = Repository::public_directory('security-pagination', 1, 1);
    $assert($directory_total >= 2, 'The public directory count omitted eligible fixture grants.');
    $assert($directory_page_one && $directory_page_two && $directory_page_one[0]['id'] !== $directory_page_two[0]['id'], 'Public directory offsets did not produce distinct pages.');
    $badge_page_one = Repository::badges(['limit' => 1, 'offset' => 0]);
    $badge_page_two = Repository::badges(['limit' => 1, 'offset' => 1]);
    $assert($badge_page_one && $badge_page_two && $badge_page_one[0]['id'] !== $badge_page_two[0]['id'], 'Badge offsets did not produce distinct pages.');

    $closed_grant = Repository::grant_badge([
        'badge_id' => $badge_id,
        'email' => 'passport-retention-closed-' . $suffix . '@example.test',
        'display_name' => 'Closed Retention Profile',
        'source_extension' => 'security-fixture',
        'source_entity_type' => 'retention',
        'source_entity_id' => $suffix . '-closed',
        'expires_at' => '2000-01-01 00:00:00',
        'public_display' => false,
    ]);
    $live_grant = Repository::grant_badge([
        'badge_id' => $badge_id,
        'email' => 'passport-retention-live-' . $suffix . '@example.test',
        'display_name' => 'Live Retention Profile',
        'source_extension' => 'security-fixture',
        'source_entity_type' => 'retention',
        'source_entity_id' => $suffix . '-live',
        'expires_at' => '2099-01-01 00:00:00',
        'public_display' => false,
    ]);
    $assert(!is_wp_error($closed_grant) && !is_wp_error($live_grant), 'Could not create retention fixture grants.');
    $track_profile($closed_grant['profile_id']);
    $track_profile($live_grant['profile_id']);
    $old_date = '2000-01-01 00:00:00';
    foreach ([$closed_grant['profile_id'], $live_grant['profile_id']] as $profile_id) {
        $wpdb->update(Repository::table('profiles'), [
            'visibility' => 'private',
            'user_id' => null,
            'updated_at' => $old_date,
        ], ['id' => absint($profile_id)]);
    }
    $wpdb->insert(Repository::table('audit'), [
        'event_type' => 'fixture.old_audit',
        'actor_type' => 'system',
        'details' => '{}',
        'created_at' => $old_date,
    ]);
    $old_audit_id = absint($wpdb->insert_id);

    Retention::schedule();
    $assert((bool) wp_next_scheduled(Retention::HOOK), 'The daily retention event was not scheduled.');
    $retention_result = Retention::run();
    $closed_profile = Repository::profile($closed_grant['profile_id']);
    $live_profile = Repository::profile($live_grant['profile_id']);
    $assert(absint($retention_result['profiles_anonymized'] ?? 0) >= 1, 'Retention did not report an anonymized closed profile.');
    $assert($closed_profile && '' === $closed_profile['email'] && '' === $closed_profile['display_name'], 'Retention did not anonymize an eligible closed profile.');
    $assert($live_profile && '' !== $live_profile['email'] && '' !== $live_profile['display_name'], 'Retention anonymized a profile with a live grant.');
    $assert(!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Repository::table('audit') . ' WHERE id = %d', $old_audit_id)), 'Retention did not remove an expired audit row.');

    echo "Success: Support Passport security and lifecycle fixture passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Support Passport security fixture failed: ' . $error->getMessage() . "\n");
    $exit_code = 1;
} finally {
    Repository::save_settings($previous_settings);
    wp_set_current_user(1);

    foreach (array_unique(array_map('absint', $fixture_profile_ids)) as $profile_id) {
        $grant_ids = array_map('absint', $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . Repository::table('grants') . ' WHERE profile_id = %d',
            $profile_id
        )) ?: []);
        foreach ($grant_ids as $grant_id) {
            $wpdb->delete(Repository::table('proofs'), ['grant_id' => $grant_id]);
            $wpdb->delete(Repository::table('revocations'), ['grant_id' => $grant_id]);
        }
        $wpdb->delete(Repository::table('consent'), ['profile_id' => $profile_id]);
        $wpdb->delete(Repository::table('audit'), ['profile_id' => $profile_id]);
        $wpdb->delete(Repository::table('grants'), ['profile_id' => $profile_id]);
        $wpdb->delete(Repository::table('profiles'), ['id' => $profile_id]);
    }
    foreach (array_unique(array_map('absint', $fixture_badge_ids)) as $badge_id) {
        $wpdb->delete(Repository::table('badges'), ['id' => $badge_id]);
    }
    foreach (array_unique(array_map('absint', $fixture_user_ids)) as $user_id) {
        wp_delete_user($user_id);
    }
}

exit($exit_code);
