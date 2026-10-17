# Proof of Support Passport

Proof of Support Passport issues privacy-first, site-local supporter badges and proof pages for Million Dollar Script.

The extension is local-only. It does not create a centralized identity, shared reputation system, hosted account, or cross-site recognition network.

## Practical Examples

- Give project backers a private proof page and let each supporter decide whether their name or profile appears publicly.
- Recognize event volunteers, community sponsors, members, or campaign contributors with different locally managed badge designs.
- Publish an opt-in supporter directory while keeping grants private by default and removing expired, revoked, or archived recognition automatically.

### Example workflow: recognize project supporters privately first

1. Open **Million Dollar Script -> Support Passport -> Badges** and create an active **Founding Supporter** badge with its description and image.
2. Under **Grants**, issue the badge to a supporter without attesting to public-display consent.
3. Give the supporter the private manage link so they can review the grant and choose whether to opt into the proof page and directory.
4. Add the **Support Badges** and optional **Supporter Directory** blocks to the pages created during extension setup. Only eligible, unexpired grants with recorded consent become public.

## Admin Workspace

The workspace opens on **Grants** for supporter eligibility, proof status, and private links. Use **Overview** for setup status, **Badges** for badge definitions, **Consent & Privacy** for visibility and retention controls, and **Settings** for directory defaults. Each tab has a direct URL and identifies the active section to assistive technology.

## Setup

1. Activate Million Dollar Script and Proof of Support Passport.
2. Open **Million Dollar Script -> Support Passport**.
3. Review the Overview tab.
4. Create or edit badges on the Badges tab.
5. Grant badges manually on the Grants tab, or configure automatic grants from supported sources.
6. Configure directory, proof URL, retention, and automatic grant settings on the Settings tab.
7. Add public pages with the blocks or shortcodes.

## Badges

Badges have a title, description, optional media image, color token, eligibility source, eligibility note, and status.

Badge grants can be private, public, expired, revoked, or archived depending on settings and moderation. Revocation preserves audit and consent history. Expired grants and grants for inactive or archived badge types are removed from public proofs, directory results, public API results, and public metrics automatically.

## Grants And Private Links

When a grant is created, the admin screen can show a private manage link and proof link. Copy these links when they appear. Private manage links should be treated like account access links because anyone with a valid link may change display preferences.

Supporters are private by default. Names, handles, profiles, proof links, and directory entries are not public unless public display consent is recorded.

The **Supporter has authorized public display** option is an administrator attestation. Enable it only after the supporter has clearly agreed to public display. Otherwise, leave the grant private and give the supporter the private manage link so they can opt in directly.

## Public Display

Use the blocks in the Million Dollar Script block category:

- **Support Badges**
- **Supporter Directory**

Or use the shortcodes:

```text
[mds_support_passport_badges]
[mds_support_passport_directory]
```

The directory can be enabled or disabled in settings. Search can also be disabled. Directory results are shown 24 at a time with accessible pagination. Badge and grant administration is shown 20 records at a time and preserves active grant filters while navigating pages.

## Automatic Grants And Integrations

Paid Million Dollar Script grid orders can grant a configured badge automatically. Compatible extensions can grant badges with:

```php
\MillionDollarScript\Extensions\SupportPassport\mds_support_passport_grant_badge_for_source([
    'badge_id' => 123,
    'email' => 'supporter@example.com',
    'display_name' => 'Supporter Name',
    'source_extension' => 'example-extension',
    'source_entity_type' => 'campaign',
    'source_entity_id' => 'campaign-123',
]);
```

Duplicate grants are prevented for the same source extension, entity type, and entity ID.

## Privacy Tools

The extension registers WordPress personal data export and erasure hooks. It stores support profiles, badges, grants, consent records, revocations, proof records, and audit logs in extension-owned local tables.

Privacy exports are paginated so profiles with large grant or consent histories are not silently truncated. The REST badge, grant, and export collections also accept `page` and `per_page`; `per_page` is capped at 100 and responses include pagination metadata.

## Retention

The extension schedules a daily WordPress cron event. **Private profile retention months** anonymizes a profile only when all of these conditions are true:

- the profile is private and older than the configured period;
- it is not linked to a WordPress account; and
- it has no live grant. Every associated grant must be expired, revoked, or archived.

Anonymization removes the email, display identity, avatar reference, and private manage token while preserving minimal grant and audit records. **Audit retention years** removes audit rows older than the configured period. Scheduled cleanup depends on WordPress cron being able to run.

## Legal And Privacy

Proof of Support Passport can contribute a **Support Badge and Proof Page Privacy Notice** through the Million Dollar Script extension setup flow. Review it before publishing.

Site owners are responsible for badge eligibility rules, supporter consent, directory moderation, takedown handling, privacy requests, and retention policies.

## REST API

The extension registers REST endpoints for badges, profiles, consent, grants, proof lookup, export, and deletion. Public access is limited to active badge definitions and unexpired proof records that have public display enabled. Private, expired, revoked, inactive, and archived proofs return a not-found response from the public REST endpoint. Profile, consent, grant-list, export, and deletion routes require the profile's signed manage token or its authenticated owner session. Granting and revoking badges requires a WordPress administrator.

When an authenticated supporter creates a profile, the extension uses the verified email on that WordPress account and ignores a different submitted email. An existing unowned profile can be linked only when its email matches the account email. Profile updates preserve the existing email and owner relationship.

Use the Million Dollar Script API Access screen to review active route policy. Signed manage-token and administrator-only routes are shown in discovery but are not selectable as ordinary API-key scopes.
