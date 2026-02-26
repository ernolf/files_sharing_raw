# Changelog

## 0.5.0

### Breaking Changes
- **App renamed** from `raw` to `files_sharing_raw`; PHP namespace from `OCA\Raw` to `OCA\FilesSharingRaw`. All routes are now exclusively available under the `/raw/` root alias (requires `files_sharing_raw` entry in Nextcloud core `RouteParser.php`). Config keys (`raw_*`) are **unchanged**.
- Routes without root alias removed: `/apps/raw/s/{token}`, `/apps/raw/{token}`, `/apps/raw/{token}/{path}`, `/apps/raw/u/{userId}/{path}`. Private access now requires `/raw/u/{userId}/{path}`

### Added
- **UI-based raw share management** (Files sidebar integration):
  - "Enable raw link" toggle in the share's Advanced settings panel.
  - REST API: `GET/POST /api/v1/raw-share/{shareId}`, `GET /api/v1/raw-shares/{fileId}`, `GET /api/v1/raw-public-url`
  - DB table `raw_shares`: stores per-share enabled state and optional
    custom CSP override per share.
  - `ShareDeletedListener`: auto-purges DB entry on share deletion.
  - `ShareUpdatedListener`: handles token rotation and share updates.
  - `FilesLoadAdditionalScriptsListener`: injects sidebar JS bundle into
    the Files app.
- **DB-based share authorization** (additive to config allowlist):
  - Config (`allowed_raw_tokens`, `allowed_raw_token_wildcards`) retains highest priority.
  - UI toggle creates a DB row that additively allows the share.
- **Canonical URL redirect**: when `files_sharing_raw` is in `rootUrlApps`, requests to `/apps/files_sharing_raw/{token}/...` are 301-redirected to canonical `/raw/{token}/...`
- **`PublicUrlBuilder`** service + `PublicUrlController` (`/api/v1/raw-public-url`) for generating canonical raw URLs.
- **Per-share DB CSP override**: `CspManager` reads a custom CSP string from the DB for raw-enabled shares (priority: config token > DB CSP > path/extension/mimetype rules).
- **`IBootstrap`** interface implemented; event listeners registered via `register()` (modern Nextcloud pattern).
- **SPDX copyright/license headers** added to all source files.

### Changed
- Requirement bump: PHP min-version 8.0 → 8.1, Nextcloud min-version 26 → 31.
- `CspManager`: all URL forms (`/raw/...`, `/rss/...`) normalized to `/apps/files_sharing_raw/...` before CSP matching rules are applied.
- `PubPageController`: share type now validated (must be `SHARE_TYPE_LINK`); non-directory shares accessed with a sub-path return plain 404 instead of throwing an exception.

## 0.4.1
### Added
- Root aliases: /raw/{token}, /raw/{token}/{path}, and /rss shortcuts (requires rootUrlApps allowlist).
- Optional webserver offload helpers (Apache X-Sendfile / Nginx X-Accel-Redirect).
- Offload debug header support.

### Changed
- Raw responses stream file bodies where possible to reduce memory usage.
- Conditional requests optimized (avoid content reads for 304/HEAD).

### Fixed
- MIME detection avoids forcing content reads when not needed.

## 0.4.0
### Added
- README overhaul:
  - Added “Design goals”, “Table of contents”, “Quickstart”, and a structured “HTTP behavior & performance” section.
  - Added explicit documentation for:
    - Cookie-free responses (best effort)
    - Directory handling via `index.html`
    - HEAD support
    - Plain text 404 behavior for public endpoints (invalid/disallowed token, missing share/path)
  - Added reference links for CSP directive docs (MDN) and expanded CSP docs structure (matching priority, policy formats, allowed directives list, testing section).
  - Recommended keeping raw-specific config in a dedicated `config/raw.config.php` (loaded from `config/` alongside config.php).
- Public controller behavior:
  - Introduced a minimal plaintext `404 Not found` response helper for public routes (`plainNotFound()`), removing cookies and disabling caching.
### Changed
- Public share failure mode:
  - Public endpoints now return a minimal `text/plain` 404 (“Not found”) instead of Nextcloud error pages / framework NotFoundResponse.
  - `getShareByToken()` failures are caught and mapped to plaintext 404 to keep responses quiet and lightweight.
- MIME detection behavior:
  - `RawResponse::getMimeType()` now also sniffs content when Nextcloud reports `application/octet-stream` (not only when filename has no extension).
  - `getMimeType()` accepts the already-loaded `$content` buffer to avoid a second `getContent()` read.
- CSP manager defaults and normalization:
  - Updated the hard-coded fallback CSP string ordering (same semantics, re-ordered directives).
  - Tightened/updated the allowlisted directives list (removed deprecated/less-used entries like `prefetch-src`, `block-all-mixed-content`).
  - CSP canonical directive ordering now prefers `child-src` earlier (after style/script) when present.
### Fixed
- Avoids double-reading file contents for MIME sniffing:
  - By passing `$content` into `getMimeType()`, the code can sniff from the existing buffer instead of calling `$fileNode->getContent()` again.
- Public 404 responses are now explicitly non-cacheable and cookie-free:
  - Sends `Cache-Control: no-store, max-age=0`
  - Removes `Set-Cookie` and closes active session (best effort)
  - Sends deterministic `Content-Length: 9` + body “Not found”
### Notes
- This release is heavily documentation-focused (large README rewrite) plus a behavioral change for public errors (plaintext 404) and small performance improvements around MIME sniffing.

## 0.3.1
### Added
- `appinfo/info.xml`: Explicitly documents that CSP is configurable via `raw_csp` (per token/path/extension/mimetype, with hard-coded fallback).
- App dependency requirements updated:
  - Nextcloud min-version set to 26
  - PHP min-version set to 8.0
### Changed
- Private controller storage access wiring:
  - Switched private file resolution from `IServerContainer->getUserFolder()` to `IRootFolder->getUserFolder()`
  - DI wiring in `Application.php` updated accordingly (inject `IRootFolder` + `IUserSession`)
- CSP service wiring simplified:
  - `CspManager` no longer depends on `ILogger` (constructed with `IConfig` only)
- Public route handler signature corrected:
  - `getByTokenWithoutS()` now correctly takes only `$token` (no unused `$path` argument)
- Response headers improved:
  - `Content-Length` prefers node size (`getSize()`) when available, falling back to `strlen($content)`
### Fixed
- CSP token parsing no longer mis-detects private URLs as tokens:
  - `CspManager` now explicitly detects `/apps/raw/u/{userId}/...` as private
  - Token extraction is skipped for private URLs (prevents accidental token-based CSP matching)
  - Relative path computation for CSP matching correctly strips `/apps/raw/u/{userId}` for private URLs
- Minor README text fix:
  - “resource exits” → “resource exists” (If-None-Match wildcard description)
- Minor code hygiene:
  - `\Exception` fully-qualified where thrown (avoids missing import / ambiguity)
### Documentation
- README formatting polish

## 0.3.0
### Added
- Configurable Content-Security-Policy support via Nextcloud system config key `raw_csp`, with selector-based matching:
  - `token` (public share token, exact match)
  - `path_prefix` (absolute and relative prefixes; longest match wins)
  - `path_contains` (substring match, checked against both full request path and the path after `/apps/raw`)
  - `extension` (file extension)
  - `mimetype` (MIME type)
  - fallback to a strict hard-coded CSP if nothing matches
- Conditional request / cache validation support:
  - `ETag` + `If-None-Match` (supports `*`, multiple values, weak ETags)
  - `Last-Modified` + `If-Modified-Since` (HTTP-date, plus optional unix timestamp convenience)
- Cookie-minimizing responses (best-effort): remove queued `Set-Cookie` headers and close session early where applicable.
- Centralized DI wiring via `lib/AppInfo/Application.php`:
  - registers `CspManager` as a shared service (IConfig-backed)
  - injects dependencies into controllers instead of manual/implicit wiring
### Changed
- Controllers migrated from AppFramework docblock annotations to PHP attributes (`#[PublicPage]`, `#[NoCSRFRequired]`, `#[NoAdminRequired]`).
- Private access controller no longer receives a userId via constructor; it derives the logged-in user from `IUserSession`.
- Raw response handling now delegates CSP selection to `CspManager` (instead of a fixed hard-coded CSP only).
### Fixed
- More robust raw response behavior for caching/conditional requests:
  - consistent 304 handling with validators
  - HEAD requests supported (headers-only)
- MIME type detection behavior retained but documented and integrated with the new response flow.
### Documentation
- README substantially expanded/reworked:
  - detailed `raw_csp` configuration section (formats, matching priority, examples, testing)
  - added documentation for ETag / Last-Modified conditional requests and cookie behavior
- `appinfo/info.xml` description extended with a short “Caching and conditional requests” section and CSP link placement cleanup.

## 0.2.0
### Added
- Token allowlist for public raw access via Nextcloud system config:
  - `allowed_raw_tokens` (exact matches)
  - `allowed_raw_token_wildcards` (simple `*` patterns)
- Additional public routes without `/s/` for convenience:
  - `/apps/raw/{token}`
  - `/apps/raw/{token}/{path}`
  (in addition to the existing `/apps/raw/s/...` routes)
### Changed
- Public endpoints now deny non-allowlisted tokens by returning a Not Found response.
- MIME type detection now uses content sniffing when the filename has no extension (via finfo(FILEINFO_MIME_TYPE)), instead of relying only on Nextcloud’s reported MIME type.
### Documentation
- README gained a detailed section explaining the allowlist / wildcard mechanism and includes example config.
- README clarifies which URL forms work (and that private /u/... cannot omit /u/).
- Added reference to the optional “human-readable share tokens” helper app (`cfg_share_links`).

## 0.1.1
### Added
- Directory handling: when a shared path points to a folder, raw now tries to serve index.html from that folder (and otherwise returns a plain 404).
### Changed
- Project metadata moved to GitHub (repository + issue tracker) and the maintainer/author was added to `appinfo/info.xml`
- Nextcloud dependency constraint was relaxed by removing the explicit `max-version` pin (keeps `min-version`).
### Fixed
- `Content-Type` header interpolation was corrected to use proper PHP variable expansion (`{$mimetype}`), avoiding potential header formatting issues.
### Documentation
- Installation instructions were clarified with an explicit git clone example and a short “enable in Apps” step.

## 0.1.0
### Added
- Initial release (forked from `gerben/nextcloud-raw` on Codeberg).

