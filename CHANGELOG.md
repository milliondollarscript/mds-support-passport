# Changelog

## Unreleased

- Kept each badge's eligibility note out of the public badge feed unless the site turns it on for that badge; the note stays visible in badge administration either way.
- Added opt-in uninstall cleanup through the core extension policy for extension-owned profiles, badges, grants, consent, proofs, audit records, settings, and transients.

## 0.2.0 - 2026-07-13

- Open the admin workspace on Grants and keep Overview available for setup status and aggregate counts.
- Added an active-page state to the existing Overview, Badges, Grants, Consent & Privacy, and Settings workspace tabs.
- Prevented authenticated users from creating or claiming supporter profiles for another account's email address.
- Preserved protected profile identity and ownership fields during supporter, grant, and manage-link updates.
- Excluded expired grants and inactive or archived badges from public proofs, directories, public-only queries, and dashboard metrics.
- Added accessible pagination for badge administration, grant administration, the public directory, REST collections, and privacy exports.
- Implemented daily retention cleanup with safeguards for linked accounts and live grants.
- Schedule retention during `init` so first activation remains warning-free alongside plugins that add translated cron intervals.
- Added explicit light and dark semantic notice colors and responsive pagination styles.
- Added security and lifecycle fixture coverage for ownership, eligibility, pagination, and retention.

## 0.1.1 - 2026-07-13

- Published every REST route through the flat Million Dollar Script endpoint manifest.
- Classified public badge/proof reads, signed manage-token profile operations, and administrator-only grant operations separately.
- Added manifest security regression coverage and API authorization documentation.

## 0.1.0

- Initial local-only Proof of Support Passport implementation.
