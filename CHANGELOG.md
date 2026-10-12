# Changelog

All notable changes to Client Forum are documented here.

## [2.2.1] — Free Release

This is the current free version. All features below are included at no cost, with no license key required.

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
