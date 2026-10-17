<?php
/** Proof of Support Passport uninstall cleanup. */
if (!defined('WP_UNINSTALL_PLUGIN') || !class_exists('\\MillionDollarScript\\Extensions\\CleanupPolicy')) { exit; }
\MillionDollarScript\Extensions\CleanupPolicy::cleanup('mds-support-passport', [
    'tables' => ['mds_support_passport_profiles', 'mds_support_passport_badges', 'mds_support_passport_grants', 'mds_support_passport_consent', 'mds_support_passport_revocations', 'mds_support_passport_proofs', 'mds_support_passport_audit_log'],
    'option_prefixes' => ['mds_support_passport_'],
    'transient_prefixes' => ['mds_support_passport_'],
]);
