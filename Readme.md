# `files_sharing_raw` — **Nextcloud raw file server**

---
---
**`files_sharing_raw`** serves files **as-is** so you can link directly to the file itself (i.e. without any of Nextcloud's UI). This makes it easy to host static web pages, RSS feeds, images, or other assets and embed/link them elsewhere.

**Design goals**

* **Minimal**: deliver bytes, not UI.
* **Fast**: keep server work low (good for assets).
* **Quiet failures**: plain 404 Not found (text/plain) for invalid/missing public shares (no Nextcloud HTML error pages), ideal for asset fetches.
* **Privacy-friendly**: **cookie-free responses** (best effort).
* **Allowlist-gated:** public raw access is opt-in — only explicitly enabled public share tokens are served.
* **Secure by default**: strict CSP with optional per-scope overrides. *)
* **Streaming by default**: for normal `GET` (`200`) responses, the body is streamed whenever possible instead of loading the entire file into memory.

*) For security and privacy, the content is served with a configurable [Content-Security-Policy][] (CSP) header, allowing different policies per share token, path, file extension, or MIME type (with a safe hardcoded fallback).

> [!NOTE]
> **`files_sharing_raw`** is the actively maintained successor to [`ernolf/raw`](https://github.com/ernolf/raw), which stopped working with Nextcloud 32 due to breaking API changes (`OCP\Share` was removed). `files_sharing_raw` was rebuilt from the ground up to be compatible with Nextcloud 32 and later, while adding a proper database registry, a Files sidebar UI, per-share CSP overrides, webserver offload support, and more.  
> The longer app ID was chosen deliberately: from the outset, a [pull request to Nextcloud core](https://github.com/nextcloud/server/pull/58648) was planned to register `files_sharing_raw` in the `rootUrlApps` list — which is what enables the short, clean `/raw/{token}` URLs. Until that PR is merged and shipped, the app automatically falls back to longer URLs under `/apps/files_sharing_raw/{token}` (see [URL forms](#url-forms)).

---

## Table of contents

* [Quickstart](#quickstart)

* [URL forms](#url-forms)

  * [Public shares](#public-shares)
  * [Private user files](#private-user-files)
  * [Root aliases (`/raw` and `/rss`)](#root-aliases-raw-and-rss)
  * [Fallback URLs (without `rootUrlApps`)](#fallback-urls-without-rooturlapps)

* [Enabling raw access](#enabling-raw-access)

  * [Via the Files sidebar](#via-the-files-sidebar)
  * [Via config: `allowed_raw_tokens` and wildcards](#via-config-allowed_raw_tokens-and-wildcards)

    * [`allowed_raw_tokens`](#allowed_raw_tokens)
    * [`allowed_raw_token_wildcards`](#allowed_raw_token_wildcards)

  * [Usage with human-readable tokens](#usage-with-human-readable-tokens)

* [Raw-only mode](#raw-only-mode)

  * [Via the Files sidebar](#via-the-files-sidebar-1)
  * [`raw_only_tokens`](#raw_only_tokens)
  * [`raw_only_token_wildcards`](#raw_only_token_wildcards)
  * [Example configuration](#example-configuration)

* [Content Security Policy](#content-security-policy)

  * [Matching priority](#matching-priority)
  * [Per-share CSP (Files sidebar)](#per-share-csp-files-sidebar)
  * [Config-based CSP (`raw_csp`)](#config-based-csp-raw_csp)

    * [Policy formats accepted](#policy-formats-accepted)
    * [Allowed directives](#allowed-directives)
    * [Config examples](#config-examples)
    * [Testing](#testing)

* [Performance & caching](#performance--caching)

  * [Cache-Control](#cache-control)
  * [Webserver offload](#webserver-offload)

    * [Offload debug header](#offload-debug-header)

  * [HTTP behavior](#http-behavior)

    * [Cookie-free responses](#cookie-free-responses)
    * [ETags and Last-Modified](#etags-and-last-modified)
    * [Directory handling (`index.html`)](#directory-handling-indexhtml)
    * [HEAD requests](#head-requests)
    * [Plain 404 for invalid public shares](#plain-404-for-invalid-public-shares)

* [Notes & best practices](#notes--best-practices)

  * [Keep `raw` settings in a dedicated config file](#keep-raw-settings-in-a-dedicated-config-file)

* [Installation](#installation)

  * [From the Nextcloud App Store](#from-the-nextcloud-app-store)
  * [Manual installation (release tarball)](#manual-installation-release-tarball)
  * [Developer setup (from source)](#developer-setup-from-source)
  * [Activating root alias URLs (`/raw/`)](#activating-root-alias-urls-raw)
  * [Migrating from the `raw` app](#migrating-from-the-raw-app)

* [Updating](#updating)

  * [Via the Nextcloud App Store](#via-the-nextcloud-app-store)
  * [Manual update](#manual-update)

---

## Quickstart

1. [Install/enable the app.](#installation)
2. Create a **public share link** for a file or folder in Nextcloud.
3. In the share's **Advanced settings** panel (Files sidebar), enable the **"Enable raw link"** toggle.
4. Access the raw URL:
   * `https://my-nextcloud/raw/<token>`

   and for folders:
   * `https://my-nextcloud/raw/<token>/<path/to/file>`

5. (Optional) Alternatively or additionally, allowlist tokens in [`config/{raw.}config.php`](#via-config-allowed_raw_tokens-and-wildcards) — useful for automation or custom link names.
6. (Optional) Configure CSP policies via `raw_csp`.

> [!NOTE]
> The short `/raw/{token}` URLs require the `rootUrlApps` entry described in [Installation](#activating-root-alias-urls-raw). Without it, the app automatically falls back to longer `/apps/files_sharing_raw/{token}` URLs.

---

## URL forms

### Public shares

If the share link is:

```
https://my-nextcloud/s/aBc123DeF456xyZ
```

then this app will serve the raw file at:

```
https://my-nextcloud/raw/aBc123DeF456xyZ
```

If the share is a folder, files within it are accessible as:

```
https://my-nextcloud/raw/aBc123DeF456xyZ/path/to/file
```

### Private user files

A user can access their own private files (they must be logged in as that user). For example, a file named `test.html` in anansi's Documents folder would be available at:

```
https://my-nextcloud/raw/u/anansi/Documents/test.html
```

The `/u/` prefix is **required** and cannot be omitted.

> [!NOTE]
> Private files are served without any additional token allowlist check — the logged-in user's identity is the authorization gate.

### Root aliases (`/raw` and `/rss`)

When the `rootUrlApps` entry is active (see [Activating root alias URLs](#activating-root-alias-urls-raw)), the app uses short root alias URLs:

| Purpose | URL |
|---|---|
| Public share | `/raw/{token}` |
| Public share + path | `/raw/{token}/{path}` |
| Private file | `/raw/u/{userId}/{path}` |
| RSS alias | `/rss` or `/rss/{path}` |

> [!NOTE]
> `/rss` and `/rss/{path}` are convenience shortcuts that internally behave exactly like `/raw/rss` and `/raw/rss/{path}`. The underlying share token is `rss` — it must be enabled like any other token (UI toggle or config allowlist).

### Fallback URLs (without `rootUrlApps`)

If the `rootUrlApps` entry is not yet active (see [Installation](#activating-root-alias-urls-raw), the app falls back to longer URLs:

| Purpose | URL |
|---|---|
| Public share | `/apps/files_sharing_raw/{token}` |
| Public share + path | `/apps/files_sharing_raw/{token}/{path}` |
| Private file | `/apps/files_sharing_raw/u/{userId}/{path}` |

The sidebar UI automatically shows the correct URL depending on whether root aliases are active. When root aliases are active, requests to fallback URLs are automatically **307-redirected** to the canonical `/raw/...` form.

---

## Enabling raw access

Public raw access is **opt-in**: a token must be explicitly allowed before the app will serve it. There are two ways to allow tokens — they can be combined freely, and the config allowlist always takes priority.

### Via the Files sidebar

Open the share in the Files app (right sidebar → Advanced settings). Enable the **"Enable raw link"** toggle and click **Update share**. The share is immediately raw-accessible under `/raw/{token}`.

This toggle stores the enabled state per share in the database. The DB entry is automatically removed when the share is deleted.

Once the raw link is enabled, additional options become available via the **three-dot menu (⋯)** next to the raw link row:

* **Raw only** — see [Raw-only mode](#raw-only-mode).
* **Edit CSP** — see [Per-share CSP (Files sidebar)](#per-share-csp-files-sidebar).

### Via config: `allowed_raw_tokens` and wildcards

One or both of the following arrays in [`config/{raw.}config.php`](#keep-raw-settings-in-a-dedicated-config-file) can be defined. **Config always takes priority over the DB registry.**

#### `allowed_raw_tokens`

An array of explicitly allowed tokens. These tokens must exactly match the share token used in raw links.

#### `allowed_raw_token_wildcards`

An array of wildcard patterns (`*`) matched against the share token. Wildcards are translated into regular expressions for dynamic validation.

#### Example configuration

```php
<?php
$CONFIG = array (
// -
  'allowed_raw_tokens' =>
  array (
    0 => 'scripts',
    1 => 'aBc123DeF456xyZ',
    2 => 'includes',
    3 => 'html',
  ),
  'allowed_raw_token_wildcards' =>
  array (
    0 => '*suffix',
    1 => 'prefix*',
    2 => 'prefix*suffix',
    3 => '*infix*',
    4 => 'prefix*infix*',
  ),
// -
);
```

In this configuration:

* Tokens such as `scripts`, `aBc123DeF456xyZ`, `includes`, and `html` are explicitly allowed.
* Wildcards match the share token and can be used as:

  * suffix: `*_json` → `data_json`
  * prefix: `nc-*` → `nc-assets`
  * infix: `*holiday_img*` → `2026-02-10-holiday_img.jpg`, `2026-02-12-holiday_img.png`
  * combined: `site-*_asset_*` → `site-example.com_asset_script.js`, `site-other.example.com_asset_style.css`

### Usage with human-readable tokens

Generating human-readable tokens (instead of randomly generated ones) makes links more meaningful and easier to manage in both the UI toggle and the config allowlist.

For example: instead of a random token like `aBc123DeF456xyZ`, use a meaningful token such as `html`, `javascript` or `data_json` for shared directories, or apply prefixes/suffixes to enable wildcard matching.

---

## Raw-only mode

When a share is flagged as **raw-only**, Nextcloud's standard share page (`/s/<token>`) returns **404 Not Found**. The file (or folder) is then only accessible via the raw URL (`/raw/<token>[/<path>]`).

This is particularly useful for **shared folders**: without raw-only, anyone who has the share link can open the folder in Nextcloud's browser UI, navigate the directory tree, and discover all contained files. Enabling raw-only prevents any folder browsing entirely — only direct requests to known, explicit file paths via `/raw/` will succeed.

> [!IMPORTANT]
> Raw-only does **not** grant `/raw/` access on its own. The token must also be enabled for raw serving — either via the **"Enable raw link"** UI toggle or listed in `allowed_raw_tokens` / `allowed_raw_token_wildcards`. Raw-only only controls whether the standard `/s/<token>` share page is additionally blocked.

### Via the Files sidebar

In the Files sidebar, once **"Enable raw link"** is active for a share, open the **three-dot menu (⋯)** in the raw link row and tick **"Raw only"**. From that moment on, `/s/<token>` returns 404 for that share — the file or folder is only reachable via `/raw/<token>`.

### `raw_only_tokens`

An array of tokens for which the standard Nextcloud share page (`/s/<token>`) is blocked. Accepts the same format as `allowed_raw_tokens` (exact token strings).

### `raw_only_token_wildcards`

An array of wildcard patterns (`*`) matched against the share token. Accepts the same format and wildcard syntax as `allowed_raw_token_wildcards`.

### Example configuration

```php
<?php
$CONFIG = array (
// -
  // Grant /raw/ access to these tokens:
  'allowed_raw_tokens' =>
  array (
    0 => 'html',
    1 => 'platform',
  ),
  'allowed_raw_token_wildcards' =>
  array (
    0 => 'nc-*',
  ),

  // Additionally block /s/ for these tokens (raw-only):
  'raw_only_tokens' =>
  array (
    0 => 'html',
    1 => 'platform',
  ),
  'raw_only_token_wildcards' =>
  array (
    0 => 'nc-*',
  ),
// -
);
```

In this configuration `html`, `platform`, and any `nc-*` token are accessible via `/raw/` but their Nextcloud share pages (`/s/html`, `/s/platform`, `/s/nc-assets`, …) all return 404.

> [!NOTE]
> A token may appear in `raw_only_tokens` without being in `allowed_raw_tokens`. In that case, both `/s/<token>` and `/raw/<token>` return 404 — which is a valid but unusual setup (e.g. for tokens managed exclusively via the DB/UI toggle while still enforcing raw-only via config).

---

## Content Security Policy

`files_sharing_raw` sends a `Content-Security-Policy` header with every raw response. Policies can be set **per share** via the Files sidebar or **globally** via the system config key `raw_csp` in `config/{raw.}config.php`. Both methods share a common evaluation order — the most specific matching rule wins.

> [!NOTE]
> If no CSP matches a request (no per-share override, no matching config rule), the app falls back to this safe, very restrictive default:
> ```
> "sandbox; default-src 'none'; style-src data: 'unsafe-inline'; img-src data:; media-src data:; font-src data:; frame-src data:"
> ```
> This fallback is hardcoded inside the app (not in `config.php`).

### Matching priority

When deciding which CSP to send, the app evaluates selectors in this order:

* `token` (config) — exact match for a public share token in `raw_csp['token']` (highest priority).
* **Per-share CSP** — custom CSP stored via the UI or REST API (applies if the share is raw-enabled and a custom CSP is set; lower priority than config token, higher than path rules).
* `path_prefix` — longest matching prefix. Supports absolute prefixes (starting with `/apps/files_sharing_raw`) and relative prefixes (matched against the path after the app prefix and token).
* `path_contains` — substring match. Checked against both the full request path and the path after the app prefix, so public and private URLs are covered.
* `extension` — file extension match (e.g. `html`, `json`).
* `mimetype` — MIME type match (e.g. `text/html`, `application/json`).
* hard-coded fallback (if nothing matches).

> [!NOTE]
> `token` (config) is the share token that appears in public URLs. Private user paths (`/raw/u/...`) do not carry a share token — `token` and per-share CSP overrides cannot match on private URLs.

### Per-share CSP (Files sidebar)

> [!NOTE]
> **Edit CSP** is restricted to the `admin` group by default. To delegate this to a custom group, create the group, add the permitted users, then point the app to it:
> ```bash
> occ group:add raw_csp_allowed
> occ group:adduser raw_csp_allowed <uid>
> occ config:app:set files_sharing_raw csp_editor_group --value="raw_csp_allowed"
> ```
> Users outside the configured group (`raw_csp_allowed` is just an example name) see no **Edit CSP** entry in the menu and cannot change a share's CSP via the API.

In the **three-dot menu (⋯)** next to the raw link row, **Edit CSP** opens an inline panel for setting a per-share Content-Security-Policy override. The value is stored in the database and takes effect immediately for all subsequent raw requests to this share. Its priority in the matching chain is: below the config `token` rule, above all path/extension/mimetype rules.

The panel contains a **preset dropdown** and an **editable text field**:

| Preset | Stored CSP value | Suited for |
|---|---|---|
| Server default | *(empty — falls back to server-wide rules)* | General use; no override |
| Sandbox (strict) | `sandbox; default-src 'none'; form-action 'none'` | Maximum isolation (no sub-resources at all) |
| Images only | `default-src 'none'; img-src 'self' data: blob:; form-action 'none'` | Image files |
| Documents (PDF / text) | `default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; form-action 'none'` | PDFs, text documents |
| Audio / Video | `default-src 'none'; media-src 'self' data: blob:; img-src 'self' data:; form-action 'none'` | Audio / video files |
| Custom | *(keeps current text unchanged)* | Any hand-crafted policy |

Selecting a preset fills the text field with the corresponding CSP string. The presets are **starting points, not fixed values** — the text field is always freely editable. Refine or extend the preset value before hitting Save, and the modified string is what gets stored.

When the text field is edited manually and its content no longer matches any preset, the dropdown automatically switches to **Custom** — a visual indicator that the stored value is user-defined.

> [!NOTE]
> Setting the per-share CSP to **"Server default"** (empty string) removes the override — the server-wide `raw_csp` rules apply as usual.

### Config-based CSP (`raw_csp`)

The `raw_csp` system config key lets admins define CSP rules for different paths, file extensions, MIME types, or share tokens. These rules apply globally and are evaluated after any per-share CSP override (see [matching priority](#matching-priority) above).

#### Policy formats accepted

A policy value for a selector may be one of:

* *String* — a full, single-line CSP header value (passed through and sanitized).
* *Indexed array* — list of directive strings; entries are joined with `;`.
* *Associative array* (recommended) — `'directive' => sources`. `sources` may be a string (space separated) or an array of strings. The manager normalizes values, deduplicates and outputs a canonical single-line header.

#### Allowed directives

Allowed directive names are deliberately limited (to keep policies sane and safe):

* Fetch directives:

  * Fallbacks: [`default-src`][], [`script-src`][], [`style-src`][], [`child-src`][]
  * Common: [`connect-src`][], [`font-src`][], [`frame-src`][], [`img-src`][], [`manifest-src`][], [`media-src`][], [`object-src`][], [`worker-src`][]
* Document directives: [`base-uri`][], [`sandbox`][]
* Navigation directives: [`form-action`][], [`frame-ancestors`][]
* Other directives: [`upgrade-insecure-requests`][]

Unknown/unsupported directives are ignored by the manager.

#### Config examples

1. Extension rule — make all `.html` permissive

```php
<?php
$CONFIG = array (
// -
  // Example: make all .html files more permissive
  'raw_csp' =>
  array(
    'extension' =>
    array(
      'html' =>
      array(
        'default-src' => ["'self'"],
        'script-src'  => ["'self'", "'unsafe-inline'"],
        'img-src'     => ["'self'", "data:"],
        'media-src'   => ["data:"],
        'style-src'   => ["'self'", "'unsafe-inline'"],
        'font-src'    => ["data:"],
        'frame-src'   => ["'none'"],
      ),
    ),
  ),
// -
);
```

2. Path prefix — relative and absolute

```php
<?php
$CONFIG = array (
// -
  // Example: an absolute prefix and a relative prefix
  'raw_csp' =>
  array(
    'path_prefix' =>
    // absolute prefix: matches full URI starting at /apps/files_sharing_raw/...
    array(
      '/apps/files_sharing_raw/s/special-html/' =>
      array(
        'default-src' => ["'self'"],
        'script-src'  => ["'self'"],
      ),
      // relative prefix: matched against the path AFTER /apps/files_sharing_raw[/{token}] or /apps/files_sharing_raw/u/{user}/
      'html/' =>
      array(
        'default-src' => ["'self'"],
        'script-src'  => ["'self'", "'unsafe-inline'"],
        'img-src'     => ["'self'", "data:"],
      ),
    ),
  ),
// -
);
```

3. Path contains — substring match (public + private)

```php
<?php
$CONFIG = array (
// -
  // Example: apply when '/html/' appears anywhere in the path
  'raw_csp' =>
  array(
    'path_contains' =>
    array(
      '/html/' =>
      array(
        'default-src' => ["'self'"],
        'script-src'  => ["'self'"],
        'img-src'     => ["'self'", "data:"],
        'style-src'   => ["'self'", "'unsafe-inline'"],
      ),
    ),
  ),
// -
);
```

4. Token — per share-token policy (optional)

```php
<?php
$CONFIG = array (
// -
  // Example: apply a policy only for the public share token 'abc123'
  // This only applies when the public URL contains the token 'abc123'.
  'raw_csp' =>
  array(
    'token' =>
    array(
      'abc123' =>
      array(
        'default-src' => ["'self'"],
        'img-src'     => ["'self'", "data:"],
      ),
    ),
  ),
// -
);
```

5. Combined example

```php
<?php
$CONFIG = array (
// -
  'raw_csp' =>
  array(
    'path_prefix' =>
    array(
      'html/' =>
      array(
        'default-src' => ["'self'"],
        'script-src'  => ["'self'", "'unsafe-inline'"],
      ),
    ),
    'path_contains' =>
    array(
      '/public/static/' =>
      array(
        'default-src' => ["'self'"],
        'img-src'     => ["'self'", "data:"],
      ),
    ),
    'extension' =>
    array(
      'json' =>
      array(
        'default-src' => ["'none'"],
        'img-src'     => ["data:"],
      ),
    ),
  ),
// -
);
```

**Important note about `path_contains` matching:**

If a pattern starts with a slash (for example '`/html/`'), the pattern is used verbatim as a substring search. '`/html/`' only matches when the exact sequence "`/html/`" appears in the request path (use this to target a folder segment precisely).

If a pattern does not start with a slash (for example '`html`'), the pattern is used as a plain substring (no leading slash is added). '`html`' therefore matches anywhere the characters `html` appear — e.g. `/some_html_text/`, `/some-html-data/`, `/htmlfile` and `/html/`.

Consequence: `some-html-data` will match the pattern '`html`' but will not match '`/html/`'.

Recommendation: use '`/folder/`' when you need to match a folder segment exactly; use a plain token like '`foo`' when you intentionally want a broad substring match.

The manager checks `path_contains` against both the full request path and the path portion after the app prefix, so public and private URLs are covered.

#### Testing

After you update [`config/{raw.}config.php`](#keep-raw-settings-in-a-dedicated-config-file) (or deploy changes), test with curl:

- **Public share (root alias URL)**:
  ```sh
  curl -I 'https://your-instance.example/raw/html/calc.html'
  ```

- **Private user URL**:
  ```sh
  curl -I 'https://your-instance.example/raw/u/alice/Documents/html/calc.html'
  ```

Inspect the `Content-Security-Policy:` response header. If you do not get the expected policy:

* make sure the selector matches your URL form (token vs path vs extension),
* check `nextcloud.log` for exceptions from `CspManager` or syntax errors in your config array,
* remember that `token` only matches explicit share tokens (not private URLs).

---

## Performance & caching

### Cache-Control

Public responses use a configurable Cache-Control header:

- `raw_cache_public_max_age` (int seconds, default: 300)
- `raw_cache_public_stale_while_revalidate` (int seconds, default: 30; 0 disables)
- `raw_cache_public_stale_if_error` (int seconds, default: 86400; 0 disables)

Private raw URLs (`/raw/u/...`) default to `private, max-age=0`.

Optionally enforce no-store for private URLs:
- `raw_cache_private_no_store` (bool, default: false)

> [!NOTE]
> `304 Not Modified` responses apply the same Cache-Control policy (public vs. private) as normal `200` responses, so caches behave consistently across conditional requests.

### Webserver offload

For large files you can optionally let the webserver send the file body (PHP returns early):

- `raw_sendfile_backend` (off|apache|nginx default: off)
- `raw_sendfile_allow_private` (bool, default: false) *)
- `raw_sendfile_min_size_mb` (int, default: 0) **)
- `raw_sendfile_nginx_prefix` (string, default: /_raw_sendfile)

> [!WARNING]
> **Webserver offload requires correct webserver configuration. The app enforces its own path restriction (files must be inside Nextcloud's `datadirectory`), but the webserver-side configuration is entirely the administrator's responsibility.**
> - **Nginx — `internal;` is mandatory.** Without it, the `/_raw_sendfile/` location is reachable directly from the internet, bypassing all PHP authorization checks. Any file inside the Nextcloud data directory would be accessible to anyone without authentication. Always verify that the location block carries the `internal;` directive before enabling offload.
> - **Apache — `XSendFilePath` is recommended defense-in-depth.** The app only ever sends paths within the datadirectory via `X-Sendfile`, so a missing `XSendFilePath` does not open an independent attack path. However, configuring it explicitly limits the blast radius should any unexpected behavior occur in the module itself.

> [!NOTE]
> *) By default, offload is disabled for private raw URLs (`/raw/u/...`) to keep authenticated endpoints conservative by default. Enable `raw_sendfile_allow_private` to allow webserver offload for private raw responses too.

> [!NOTE]
> **) If `raw_sendfile_min_size_mb` is set, offload is only attempted when the file size is known and meets the threshold. If the size cannot be determined (e.g. certain storage backends), offload is skipped.

**Prerequisites** (webserver configuration required):

- **Apache**:
  - Requires `mod_xsendfile` *) (or an equivalent X-Sendfile implementation) to be installed and enabled.
  - Enable it and configure the allowed path(s) to include your Nextcloud datadirectory:
    ```apacheconf
    XSendFile On
    # Use the *real* Nextcloud datadirectory from `config/config.php` -> 'datadirectory'
    XSendFilePath /path/to/nextcloud/data
    ```
> [!NOTE]
> *) Module naming varies by distribution; the key requirement is that your Apache build supports `X-Sendfile` and that the module is enabled for the vhost serving Nextcloud.

- **Nginx**:
  - Uses `X-Accel-Redirect` (built into nginx, no extra module needed).
  - Requires an `internal` location that maps the configured prefix (default `/_raw_sendfile`) to Nextcloud's data directory via `alias`.
  - Example (must match your `raw_sendfile_nginx_prefix` and Nextcloud datadirectory):
    ```nginx
    location /_raw_sendfile/ {
        internal;
        alias /path/to/nextcloud/data/;
    }
    ```
> [!TIP]
> The app builds the Nginx `X-Accel-Redirect` target by stripping the resolved (`realpath`) Nextcloud `datadirectory` prefix from the local file path. Ensure your Nginx `alias` uses the same resolved datadirectory path (and includes a trailing `/`). If `datadirectory` is a symlink but Nginx points to the symlink path (or vice versa), the mapping can mismatch and offload will be skipped.

> [!WARNING]
> **Nginx offload bypasses PHP-set `Content-Security-Policy` headers.** When `X-Accel-Redirect` is used, nginx serves the file body directly — it does not forward custom response headers set by PHP, including `Content-Security-Policy`. As a result, offloaded responses carry **no CSP header at all**.
>
> **Recommendation:** set `raw_sendfile_min_size_mb` to a meaningful threshold (e.g. `10`) so that small files — HTML pages, text files, RSS feeds, images — where a CSP is security-relevant are served by PHP (with full CSP enforcement), while only large binary files — videos, archives, large data blobs — where a CSP is not meaningful are offloaded to nginx.
>
> If you require CSP on all responses regardless of file size, do not use `raw_sendfile_backend = 'nginx'`.

Example offload configuration in [`config/{raw.}config.php`](#keep-raw-settings-in-a-dedicated-config-file) (for apache2):

```php
<?php
$CONFIG = array (
// -
  // Private raw caching
  'raw_cache_private_no_store' => false, // true = Never save in browser

  // apache2
  'raw_sendfile_backend' => 'apache',
/*
  // nginx
  'raw_sendfile_backend' => 'nginx',
  'raw_sendfile_nginx_prefix' => '/_raw_sendfile',
*/

  // allow offload also for /raw/u/... (default false)
  'raw_sendfile_allow_private' => false,

  // only offload for files >= X MB (default 0 = no threshold)
  'raw_sendfile_min_size_mb' => 5,
// -
);
```

#### Offload debug header

To debug whether offload/streaming was used, send this request header:

- `X-Raw-Offload-Debug: 1`

The response may include:
- `X-Raw-Offload: <status>; reason=<reason>`

> [!NOTE]
> When offload is active and actually used, the response may include an `X-Raw-Offload` header (e.g. `apache-xsendfile` / `nginx-x-accel`) even without debug enabled.  
> If you send `X-Raw-Offload-Debug: 1`, the app adds `reason=...` and can also emit a "not offloaded" reason, which is useful to validate your config and thresholds.

### HTTP behavior

#### Cookie-free responses

`files_sharing_raw` intentionally aims to be **cookie-free**. It will best-effort prevent `Set-Cookie` from being emitted for raw responses (e.g. by closing any active session, disabling session cookies for the remainder of the request, and removing already queued `Set-Cookie` headers).

This keeps endpoints "naked" for asset serving and reduces overhead. (Best effort: a reverse proxy could still add cookies afterwards.)

#### ETags and Last-Modified

`files_sharing_raw` supports conditional requests (cache validation) using ETags together with the `If-None-Match` header and also supports `Last-Modified` / `If-Modified-Since` semantics.

> [!NOTE]
> The app prefers "fast" validators (mtime + size) for ETag generation and only falls back to a content hash when needed.

* **ETag / If-None-Match**: The server sends an `ETag` header identifying the current representation of the file. If the client sends `If-None-Match: "<ETag>"` and the value matches, the server responds with `304 Not Modified` and no response body. The wildcard `If-None-Match: *` is also supported.
* **Last-Modified / If-Modified-Since**: When the server can read file modification time (mtime) it sets a `Last-Modified` header. The server will honor `If-Modified-Since` when `If-None-Match` is not present. If the client date is equal to or newer than the file mtime, the server responds with `304 Not Modified`.
* **Unix timestamp convenience**: For convenience, `If-Modified-Since` accepts either an RFC-style HTTP-date (recommended) **or** a plain Unix timestamp (seconds). The server will trim optional quotes.

Examples:

- Get file and see headers + body (returns ETag and Last-Modified):

   ```bash
   curl -i 'https://your.nextcloud/raw/.../file.ext'
   ```

- Conditional GET using ETag (replace `<ETag>` with the ETag value returned by the server):

   ```bash
   curl -i -H 'If-None-Match: "<ETag>"' 'https://your.nextcloud/raw/.../file.ext'
   ```

- Conditional GET using HTTP-date:

   ```bash
   curl -i -H 'If-Modified-Since: "Sun, 25 May 2025 21:40:02 GMT"' 'https://your.nextcloud/raw/.../file.ext'
   ```

- Conditional GET using Unix timestamp (convenience):

   ```bash
   curl -i -H 'If-Modified-Since: "1748209203"' 'https://your.nextcloud/raw/.../file.ext'
   ```

- The wildcard `If-None-Match: *` is also supported (returns 304 if the resource exists):

   ```bash
   curl -i -H 'If-None-Match: *' 'https://your.nextcloud/raw/.../file.ext'
   ```

#### Directory handling (`index.html`)

If the requested node is a directory, the app attempts to serve `index.html` from that directory.

#### HEAD requests

`files_sharing_raw` supports `HEAD` requests (headers only, no response body).

#### Plain 404 for invalid public shares

For public endpoints, the app returns a minimal `text/plain` **404 Not found** response for disallowed/unknown tokens, missing shares, and missing paths. This avoids rendering large HTML error pages and keeps endpoints lightweight.

---

## Notes & best practices

* Review and update `allowed_raw_tokens` and `allowed_raw_token_wildcards` periodically to align with your security requirements. Alternatively, manage access via the Files sidebar UI per share.
* Use meaningful share tokens wherever possible for improved manageability.
* Validate CSP rules and token configurations in a test environment before applying them in production.
* Prefer `extension` or `path-based` matching for predictable results. `path_contains` with `'/html/'` is usually the safest way to target a folder named `html`.
* Avoid `script-src 'unsafe-inline'` unless absolutely necessary. When you need inline scripts, prefer nonces or restrictive policies.
* Keep the `token` selector (in `raw_csp`) only if you want per-share (per-token) policies from config. If you do not need that granularity, it is safe to remove `token` and rely on path/extension/mimetype rules. Per-share CSP can also be set via the UI (stored in DB).
* The manager normalizes directives and removes duplicates; unknown directives are ignored (no crash but check logs).

### Keep `raw` settings in a dedicated config file

* Nextcloud can load settings from multiple files in `config/`. For `files_sharing_raw`, it's recommended to keep all `raw`-related directives like `allowed_raw_tokens`, `allowed_raw_token_wildcards`, `raw_csp` etc. in a dedicated **`config/raw.config.php`** (any `*.config.php` in `config/` is loaded and merged alongside `config.php`).
* This keeps raw-specific security settings isolated, avoids accidental clutter in `config.php`, and plays nicely with config management.
* **Gotcha:** Nextcloud can consolidate config values into `config/config.php`. Don't rely on `occ` for `raw` settings if `config/raw.config.php` exists — `raw.config.php` has precedence and will override later.

---

## Installation

### From the Nextcloud App Store

The easiest way to install this app is via the Nextcloud App Store:

1. Log into Nextcloud as admin.
2. Go to **Apps** → search for **Raw Fileserver** → Install.

### Manual installation (release tarball)

1. Download the latest release tarball (`files_sharing_raw.tar.gz`) from the
   [GitHub Releases page](https://github.com/ernolf/files_sharing_raw/releases).
2. Extract it into your Nextcloud `/apps` (or `/custom_apps`) folder:
   ```bash
   tar -xzf files_sharing_raw.tar.gz -C /path/to/nextcloud/apps/
   ```
3. Enable the app:
   ```bash
   occ app:enable files_sharing_raw
   ```
   or log into Nextcloud as admin and enable it in the Apps list.

### Developer setup (from source)

1. Clone the repository into your Nextcloud `/apps` (or `/custom_apps`) folder:
   ```bash
   git clone https://github.com/ernolf/files_sharing_raw
   cd files_sharing_raw
   ```
2. Install frontend dependencies and build the JS bundle:
   ```bash
   npm ci
   npm run build
   ```
3. Enable the app:
   ```bash
   occ app:enable files_sharing_raw
   ```

### Activating root alias URLs (`/raw/`)

To use the short `/raw/{token}` URLs instead of the longer `/apps/files_sharing_raw/{token}` fallback, `files_sharing_raw` must be registered in Nextcloud core's `rootUrlApps` list. A [pull request has been submitted to Nextcloud core](https://github.com/nextcloud/server/pull/58648) for this. Until it is merged and shipped with a Nextcloud release, the entry must be added manually.

The change is a single line in `lib/private/AppFramework/Routing/RouteParser.php`:

```php
private const rootUrlApps = [
    'cloud_federation_api',
    'core',
    'files_sharing_raw',   // ← add this line
    'files_sharing',
    // ...
];
```

A patch script is included in the app directory — just make it executable and run it:

```bash
chmod +x patch-route-parser.sh && ./patch-route-parser.sh
```

The script is idempotent — it finds `RouteParser.php` automatically and is safe to run multiple times.

> [!NOTE]
> This manual step must be repeated after every Nextcloud core update that overwrites `RouteParser.php`. Once the PR is merged, no manual action will be needed.
>
> Without this entry the app still works — it simply uses the longer fallback URLs.

### Migrating from the `raw` app

If you previously used the original `raw` app (which stopped working with Nextcloud 32):

1. Disable `raw`: `occ app:disable raw`
2. Install and enable `files_sharing_raw` (see above).

All `raw_*` config keys (`allowed_raw_tokens`, `raw_csp`, etc.) are reused automatically — no data migration is needed.

## Updating

### Via the Nextcloud App Store

Update directly from the Apps page in the Nextcloud admin UI — no manual steps needed.

### Manual update

1. Disable the app:
   ```bash
   occ app:disable files_sharing_raw
   ```
2. Update the app files — either via
   - release tarball (see [Manual installation](#manual-installation-release-tarball) above)

   or via  
   - `git pull` + `npm ci && npm run build` (see [Developer setup (from source)](#developer-setup-from-source))

   in the app directory.
3. Enable the app again:
   ```bash
   occ app:enable files_sharing_raw
   ```

---

[Content-Security-Policy]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy
[`child-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/child-src
[`connect-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/connect-src
[`default-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/default-src
[`font-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/font-src
[`frame-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/frame-src
[`img-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/img-src
[`manifest-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/manifest-src
[`media-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/media-src
[`object-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/object-src
[`script-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/script-src
[`style-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/style-src
[`worker-src`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/worker-src
[`base-uri`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/base-uri
[`sandbox`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/sandbox
[`form-action`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/form-action
[`frame-ancestors`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/frame-ancestors
[`upgrade-insecure-requests`]: https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/upgrade-insecure-requests

