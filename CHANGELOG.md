# Changelog

All notable changes to Client Forum are documented here.

The current free version is 2.2.3. All features below are included at no cost, with no license key required.

## [2.2.3] — Free Release

### Fixed
- Clicking the Forum menu link while logged out no longer causes a "too many redirects" error. Logged-out visitors now go to the WHMCS login page and come back to the forum after signing in.
- The admin page now loads its stylesheet. It was looking in the WHMCS admin folder instead of the main folder, so admin pages showed without their styling.

## [2.2.2] — Free Release

### Fixed
- Posts in a thread no longer get scrambled after a server timezone change or a daylight-saving fall-back. Posts are now ordered by the order they were created, not by their timestamp.
- The opening post can no longer show "Mark as Best Answer", and the server now refuses that request too
- Moving the best answer between two posts by the same author no longer gives that author the karma bonus twice
- Search no longer comes back empty when a query includes short words (under 3 letters) or common words like "the" or "how". If every word is too short to index, the forum falls back to a plain text search.
- The editor's Link button trims stray spaces and line breaks from the selected text, so selecting a URL no longer produces a broken link. If you select a URL, it's filled in for you.
- Admin form fields are now styled properly on WHMCS's Iris admin theme
- The webhook help text now points to Configuration → System Settings → Addon Modules
- The Add Board form shows "No products set up yet" instead of an empty box when there are no products
- The module author now shows as K & K Web Services

### Added
- Plain web addresses typed into a post (https://...) now become clickable links automatically
- Links in posts and the preview are underlined so they stand out on every theme

### Packaging
- CSS and JavaScript files now load with a version number, so browsers pick up changes right after an update instead of using an old cached copy
- The download zip now contains only `modules/addons/clientforum/`, so it extracts straight into your WHMCS folder

## [2.2.1]

### Fixed
- Quotes and other special characters in posts no longer show as `&quot;` (WHMCS pre-encodes form input; it was being escaped twice). Existing posts display correctly without re-saving.
- Forum colors now adapt to dark themes instead of showing bright white post boxes and editor
- Staff, Pinned, Locked, Restricted and Best Answer labels are now visible on Bootstrap 4 themes (Twenty-One and most modern themes)

### Notes
- If your theme hides the client area menu on the forum page, see the README troubleshooting section

## [2.2.0]

This was the first free version.

### Added
- Admin panel: "Post a New Thread" form (board select, client email, title/body), attributed to the entered client with an automatic staff badge

## [2.1.0]
### Added
- Users can edit their own posts, including the thread title on the opening post

## [2.0.0] – [2.0.1]
### Added
- WHMCS Software Licensing Addon integration for license validation (Pro builds only — removed entirely in the free release)
### Fixed
- Corrected key-encoding mismatch (`json_encode` vs `serialize`) and HTTP status check in license verification

## [1.9.0]
### Added
- Slack / Discord webhook notifications on new threads and ticket promotions

## [1.8.0]
### Changed
- Search upgraded to real MySQL FULLTEXT indexes with `MATCH`/`AGAINST` for accurate ranking

## [1.7.0]
### Added
- Full-text search across thread titles and post bodies

## [1.6.0]
### Added
- Leaderboard of top contributors

## [1.5.1]
### Fixed
- Ticket-to-thread promotion no longer defaults to showing only "Awaiting Reply" tickets, which had hidden the promote button in other statuses

## [1.5.0]
### Added
- Best-answer marking
- Points / karma system
- Ticket-to-thread promotion

## [1.x] — Early Pro-Tier Work
### Added
- Markdown / rich-text editor for post bodies
- Admin: delete and reorder categories, extended to boards

## [1.0.0] — Initial Release
### Added
- Single sign-on using existing WHMCS client login
- Categories, boards, threads, and replies
- Product-gated boards
- Admin CRUD and moderation tools
- Dashboard widget
- Rate limiting
- Staff badges
- English and Spanish language support
### Fixed
- Server-side length limits on titles/bodies
- Rate-limiter concurrency issue
