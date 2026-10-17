# Proof of Support Passport

Proof of Support Passport issues privacy-first, site-local supporter badges and proof pages for Million Dollar Script.

The first version is local-only. It does not create centralized supporter identity, automatic cross-site recognition, shared reputation, or hosted accounts.

## Features

- Extension-owned local tables for profiles, badges, grants, consent, revocations, proofs, and audit logs.
- Admin badge builder and grant/revoke workflow.
- Media-backed badge images with color/initial fallbacks.
- Private-by-default supporter profiles and proof pages.
- Optional public supporter directory for supporters who explicitly opt in.
- Searchable, paginated badge, grant, and public-directory collections.
- Private manage links so supporters can edit display names/handles and choose whether each badge is public.
- Automatic badge grants for paid Million Dollar Script grid orders when an admin maps a badge in settings.
- Source integration helper for compatible extensions: `mds_support_passport_grant_badge_for_source()`.
- Gutenberg blocks for Support Badges and Supporter Directory.
- REST endpoints for badges, profiles, consent, grants, proof lookup, export, and deletion.
- WordPress personal data exporter and eraser.
- Daily retention cleanup that anonymizes eligible closed profiles and removes audit records after the configured period.

## Shortcodes

- `[mds_support_passport_directory]`
- `[mds_support_passport_badges]`

## Blocks

Both blocks appear under the Million Dollar Script category in the WordPress editor:

- Support Badges
- Supporter Directory

The blocks save to the matching shortcode and can be converted back from raw shortcode content by the shared Million Dollar Script block system.

## Source Integrations

Paid grid orders can grant a configured badge automatically. Compatible extensions can also grant badges through:

```php
\MillionDollarScript\Extensions\SupportPassport\mds_support_passport_grant_badge_for_source([
    'badge_id' => 123,
    'email' => 'supporter@example.com',
    'display_name' => 'Supporter Name',
    'source_extension' => 'example-extension',
    'source_entity_type' => 'campaign',
    'source_entity_id' => 'campaign-123',
    'order_reference' => 'optional-reference',
]);
```

Grants are private by default. Duplicate grants are prevented for the same source extension, entity type, and entity ID.

## Privacy Notes

Support Passport does not publish supporter names, handles, profiles, proof links, or directory entries unless public display consent is recorded. Private manage links should be treated like account access links because anyone with a valid link may be able to change display preferences.

Expired grants and inactive, archived, or revoked badges are excluded from every public surface. The retention job preserves WordPress-linked profiles and profiles with live grants; it anonymizes only stale, private, unowned profiles after all grants are expired, revoked, or archived.

## Changelog

### 0.2.0

- Hardened profile ownership, public eligibility, pagination, privacy exports, retention, and semantic notice contrast.

### 0.1.0

- Initial local-only implementation with badge management, grants, consent, proof pages, directory shortcodes/blocks, source integrations, and WordPress privacy tools.
