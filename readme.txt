=== Million Dollar Script - Proof of Support Passport ===
Contributors: milliondollarscript
Tags: million dollar script, supporters, badges, privacy
Requires at least: 6.0
Tested up to: 7.1.3
Requires PHP: 8.1
Stable tag: 0.2.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Issue privacy-first, site-local supporter badges and proof pages for Million Dollar Script.

== Description ==

Proof of Support Passport lets site owners create local supporter badges, grant badges to supporters, record public display consent, and publish proof pages only when supporters opt in.

Hosted identity and cross-site recognition are intentionally disabled in this version.

Features include badge image/color management, private manage links, searchable and paginated administration, optional paginated supporter directory display, Support Badges and Supporter Directory blocks, WordPress privacy export/erase support, working retention controls, paid grid order auto-grants, and an integration helper for compatible Million Dollar Script extensions.

Private manage links should be treated like account access links. Badge grants are private by default and public display requires supporter opt-in.

== Shortcodes ==

* `[mds_support_passport_badges]`
* `[mds_support_passport_directory]`

== Blocks ==

* Support Badges
* Supporter Directory

== Developer Integration ==

Compatible extensions can call `\MillionDollarScript\Extensions\SupportPassport\mds_support_passport_grant_badge_for_source()` with a badge ID, supporter email, source extension slug, source entity type, and source entity ID. Duplicate grants are prevented for the same source identity.

== Changelog ==

= 0.2.1 =
* Tested with WordPress 7.1.3 and WooCommerce 11.2.1, and the plugin states the exact versions it was tested with, so WordPress and WooCommerce no longer report it as untested on a current site.

= 0.2.0 =
* Hardened profile ownership, public eligibility, pagination, privacy exports, retention, and semantic notice contrast.

= 0.1.0 =
* Initial local-only implementation with badge management, grants, consent, proof pages, directory shortcodes/blocks, source integrations, and WordPress privacy tools.
