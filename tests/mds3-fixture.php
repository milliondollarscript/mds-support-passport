<?php
/**
 * Local fixture checks for Proof of Support Passport.
 *
 * Run with:
 * ./scripts/wp eval-file wp-content/plugins/mds-support-passport/tests/mds3-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\\MillionDollarScript\\Extensions\\SupportPassport\\Repository')) {
    fwrite(STDERR, "Support Passport repository is not loaded.\n");
    exit(1);
}

wp_set_current_user(1);

$repo = '\\MillionDollarScript\\Extensions\\SupportPassport\\Repository';
$repo::install();
$repo::maybe_seed();

if (!function_exists('add_submenu_page')) {
    fwrite(STDERR, "WordPress admin menu helpers are not available.\n");
    exit(1);
}

\MillionDollarScript\Extensions\SupportPassport\Main::instance()->admin_menu();
global $submenu;
$found_submenu = false;
foreach ((array) ($submenu['mds3'] ?? []) as $item) {
    if ('mds-support-passport' === (string) ($item[2] ?? '')) {
        $found_submenu = true;
        break;
    }
}
if (!$found_submenu) {
    fwrite(STDERR, "Support Passport did not register an MDS extension submenu.\n");
    exit(1);
}

$onboarding_items = apply_filters('million-dollar-script/extension/onboarding/items', []);
$support_onboarding = is_array($onboarding_items['mds-support-passport'] ?? null) ? $onboarding_items['mds-support-passport'] : [];
$setup_pages = is_array($support_onboarding['setup_pages'] ?? null) ? $support_onboarding['setup_pages'] : [];
$setup_page_slugs = array_map(static function ($page) {
    return is_array($page) ? (string) ($page['page_slug'] ?? '') : '';
}, $setup_pages);
if (!in_array('support-badges', $setup_page_slugs, true) || !in_array('supporter-directory', $setup_page_slugs, true)) {
    fwrite(STDERR, "Support Passport setup page declarations are missing.\n");
    exit(1);
}

$badge_id = $repo::save_badge([
    'title' => 'Fixture Supporter ' . wp_generate_password(6, false, false),
    'description' => 'Fixture badge for local smoke checks.',
    'color_token' => 'green',
    'eligibility_source' => 'fixture',
    'eligibility_note' => 'Created by fixture.',
    'status' => 'active',
]);
if (!$badge_id) {
    fwrite(STDERR, "Badge was not created.\n");
    exit(1);
}

$repo::save_badge([
    'title' => 'Updated Fixture Supporter',
    'description' => 'Updated fixture badge for local smoke checks.',
    'color_token' => 'gold',
    'eligibility_source' => 'fixture',
    'eligibility_note' => 'Updated by fixture.',
    'status' => 'inactive',
], $badge_id);
$updated_badge = $repo::badge($badge_id);
if (!$updated_badge || 'Updated Fixture Supporter' !== $updated_badge['title'] || 'inactive' !== $updated_badge['status']) {
    fwrite(STDERR, "Badge edit did not persist.\n");
    exit(1);
}
$repo::update_badge_status($badge_id, 'active');

// The eligibility note says how a badge is earned. It stays with the site's
// administrators until the site turns it on for that badge, so the public
// badge read has to leave it out and the admin read has to keep it.
$public_unopted = [];
foreach ($repo::public_badges(['active_only' => true, 'limit' => 0]) as $public_badge) {
    if (absint($public_badge['id']) === $badge_id) {
        $public_unopted = $public_badge;
    }
}
if (!$public_unopted || array_key_exists('eligibility_note', $public_unopted) || array_key_exists('public_eligibility', $public_unopted)) {
    fwrite(STDERR, "A badge's eligibility note reached the public read without the site opting it in.\n");
    exit(1);
}
$admin_view = $repo::badge($badge_id);
if (!$admin_view || '' === (string) ($admin_view['eligibility_note'] ?? '') || !empty($admin_view['public_eligibility'])) {
    fwrite(STDERR, "The admin read lost the eligibility note or reported the wrong opt-in.\n");
    exit(1);
}
$repo::save_badge([
    'title' => (string) $admin_view['title'],
    'description' => (string) $admin_view['description'],
    'color_token' => (string) $admin_view['color_token'],
    'eligibility_source' => (string) $admin_view['eligibility_source'],
    'eligibility_note' => 'Public note chosen by the site owner.',
    'public_eligibility' => true,
    'status' => 'active',
], $badge_id);
$public_opted = [];
foreach ($repo::public_badges(['active_only' => true, 'limit' => 0]) as $public_badge) {
    if (absint($public_badge['id']) === $badge_id) {
        $public_opted = $public_badge;
    }
}
if ('Public note chosen by the site owner.' !== (string) ($public_opted['eligibility_note'] ?? '')) {
    fwrite(STDERR, "An opted-in eligibility note did not reach the public read.\n");
    exit(1);
}

$unused_badge_id = $repo::save_badge([
    'title' => 'Delete Fixture ' . wp_generate_password(6, false, false),
    'description' => 'Unused badge deletion fixture.',
    'color_token' => 'slate',
    'eligibility_source' => 'fixture',
    'status' => 'active',
]);
if (!$unused_badge_id || !$repo::delete_badge($unused_badge_id) || $repo::badge($unused_badge_id)) {
    fwrite(STDERR, "Unused badge deletion did not work.\n");
    exit(1);
}

$email = 'support-passport-fixture+' . time() . '@example.test';
$grant = $repo::grant_badge([
    'badge_id' => $badge_id,
    'email' => $email,
    'display_name' => 'Fixture Supporter',
    'public_handle' => 'fixture-supporter-' . time(),
    'source_extension' => 'fixture',
    'source_entity_type' => 'test',
    'source_entity_id' => (string) time(),
    'public_display' => false,
]);
if (is_wp_error($grant) || empty($grant['grant_id']) || empty($grant['manage_token']) || empty($grant['proof_token'])) {
    fwrite(STDERR, "Grant did not return expected IDs and tokens.\n");
    exit(1);
}

$profile = $repo::profile_by_manage_token($grant['profile_id'], $grant['manage_token']);
if (!$profile || absint($profile['id']) !== absint($grant['profile_id'])) {
    fwrite(STDERR, "Signed manage token lookup failed.\n");
    exit(1);
}

$proof = $repo::proof_by_token($grant['proof_token']);
if (!$proof || !empty($proof['public']) || 'private' !== $proof['status']) {
    fwrite(STDERR, "Private proof state was not enforced before consent.\n");
    exit(1);
}

if (!$repo::set_grant_public_state($grant['grant_id'], $grant['profile_id'], 'public', 'fixture')) {
    fwrite(STDERR, "Could not enable public display through consent state.\n");
    exit(1);
}

$proof = $repo::proof_by_token($grant['proof_token']);
if (!$proof || empty($proof['public']) || 'public' !== $proof['status']) {
    fwrite(STDERR, "Public proof state was not enabled after consent.\n");
    exit(1);
}

$previous_settings = $repo::settings();
$repo::save_settings(array_merge($previous_settings, [
    'directory_enabled' => '1',
    'directory_search_enabled' => '1',
]));
$directory = $repo::public_directory('fixture', 10);
if (!$directory) {
    fwrite(STDERR, "Public directory did not include opted-in grant.\n");
    exit(1);
}
$repo::save_settings($previous_settings);

$new_proof_token = $repo::issue_proof_token($grant['grant_id']);
if (is_wp_error($new_proof_token) || !$repo::proof_by_token($new_proof_token)) {
    fwrite(STDERR, "Fresh proof token was not issued.\n");
    exit(1);
}

$repo::update_grant($grant['grant_id'], [
    'display_name' => 'Updated Fixture Supporter',
    'public_handle' => 'updated-fixture-supporter',
    'source_extension' => 'fixture-updated',
    'source_entity_type' => 'updated-test',
    'source_entity_id' => 'updated-' . time(),
    'order_reference' => 'fixture-order-' . time(),
    'public_display_state' => 'private',
]);
$updated_grant = $repo::grant($grant['grant_id']);
if (!$updated_grant || 'fixture-updated' !== $updated_grant['source_extension'] || 'private' !== $updated_grant['public_display_state']) {
    fwrite(STDERR, "Grant edit did not persist.\n");
    exit(1);
}

if (!$repo::archive_grant($grant['grant_id'])) {
    fwrite(STDERR, "Grant archive failed.\n");
    exit(1);
}
$archived_grant = $repo::grant($grant['grant_id']);
if (!$archived_grant || 'archived' !== $archived_grant['public_display_state']) {
    fwrite(STDERR, "Grant archive state did not persist.\n");
    exit(1);
}

$delete_used_badge = $repo::delete_badge($badge_id);
if (!is_wp_error($delete_used_badge)) {
    fwrite(STDERR, "Used badge should not be hard deleted.\n");
    exit(1);
}

$export = $repo::export_by_email($email, 1);
if (empty($export['done']) || empty($export['data'])) {
    fwrite(STDERR, "Privacy export did not include fixture profile data.\n");
    exit(1);
}

$eraser = $repo::erase_by_email($email, 1);
if (empty($eraser['done']) || empty($eraser['items_removed'])) {
    fwrite(STDERR, "Privacy eraser did not anonymize fixture profile.\n");
    exit(1);
}

do_action('rest_api_init');
$badges_response = rest_do_request(new WP_REST_Request('GET', '/million-dollar-script/v1/support-passport/badges'));
if ($badges_response->is_error() || 200 !== $badges_response->get_status()) {
    fwrite(STDERR, "Badge REST endpoint failed.\n");
    exit(1);
}

$proof_response = rest_do_request(new WP_REST_Request('GET', '/million-dollar-script/v1/support-passport/proofs/' . rawurlencode($new_proof_token)));
if (404 !== $proof_response->get_status()) {
    fwrite(STDERR, "Private or archived proof was exposed through the public REST endpoint.\n");
    exit(1);
}

$manifest = \MillionDollarScript\Extensions\SupportPassport\Main::instance()->api_endpoint_manifest([]);
$manifest_by_id = [];
foreach ($manifest as $endpoint) {
    $manifest_by_id[$endpoint['id'] ?? ''] = $endpoint;
}
$expected_security = [
    'support-passport-badges-read' => 'public_read',
    'support-passport-profile-read' => 'signed_manage_token',
    'support-passport-profile-write' => 'signed_manage_token',
    'support-passport-consent-write' => 'signed_manage_token',
    'support-passport-grants-read' => 'signed_manage_token',
    'support-passport-badge-grant' => 'wp_capability',
    'support-passport-grant-revoke' => 'wp_capability',
    'support-passport-proof-read' => 'public_read',
    'support-passport-privacy-export' => 'signed_manage_token',
    'support-passport-privacy-delete' => 'signed_manage_token',
];
foreach ($expected_security as $endpoint_id => $security_level) {
    if ($security_level !== (string) ($manifest_by_id[$endpoint_id]['minimum_security_level'] ?? '')) {
        fwrite(STDERR, "Support Passport API manifest security is incomplete for {$endpoint_id}.\n");
        exit(1);
    }
}

$exporters = apply_filters('wp_privacy_personal_data_exporters', []);
$erasers = apply_filters('wp_privacy_personal_data_erasers', []);
if (empty($exporters['mds-support-passport']) || empty($erasers['mds-support-passport'])) {
    fwrite(STDERR, "Privacy exporter or eraser was not registered.\n");
    exit(1);
}

echo "Success: Support Passport fixture passed.\n";
