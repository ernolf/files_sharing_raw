# `files_sharing_raw` — Nextcloud AIO: Activating root alias URLs (`/raw/`)

This guide is specifically for **Nextcloud All-in-One (AIO)** users who need to manually apply the `rootUrlApps` patch.

---

## Background

The short, clean `/raw/{token}` URLs require `files_sharing_raw` to be listed in Nextcloud core's `rootUrlApps` array. A [pull request](https://github.com/nextcloud/server/pull/58648) for this was submitted and has been merged — it ships with **Nextcloud 32.0.7+ and 33.0.1+**.

If you are running an older patch release (below 32.0.7 or 33.0.1), the entry needs to be added once manually.

> [!NOTE]
> Without the patch the app still works — it just uses longer fallback URLs (`/apps/files_sharing_raw/{token}` instead of `/raw/{token}`).

---

## When this is NOT needed

You can skip this guide entirely if:

- you are running **Nextcloud 32.0.7 or later**, or
- you are running **Nextcloud 33.0.1 or later**.

On those versions the `rootUrlApps` entry ships with the core update — no manual action required.

---

## Applying the patch manually (Nextcloud AIO / Docker)

### Step 1 — Get a shell into the container

The Nextcloud AIO container uses Alpine Linux and does not include a text editor by default. The following command starts a shell and installs `joe` (a simple, beginner-friendly editor):

```bash
docker exec -it nextcloud-aio-nextcloud /bin/sh -c "apk add --no-cache bash ncurses joe musl-locales musl-locales-lang && export LANG=en_US.UTF-8 && exec bash"
```

### Step 2 — Open the file

```bash
joe lib/private/AppFramework/Routing/RouteParser.php
```

### Step 3 — Make the change

Find the `rootUrlApps` constant. It looks like this:

```php
private const rootUrlApps = [
    'cloud_federation_api',
    'core',
    'files_sharing',
    // ...
];
```

Add `'files_sharing_raw',` to the list — alphabetical order, after `'core'` and before `'files_sharing'`:

```php
private const rootUrlApps = [
    'cloud_federation_api',
    'core',
    'files_sharing_raw',   // ← add this line
    'files_sharing',
    // ...
];
```

### Step 4 — Save and exit

In `joe`, save and exit with:

**`Ctrl-K X`**

---

### Alternative: using `vi` / `vim`

If you prefer `vi`, add `vim` to the `apk add` command in Step 1:

```bash
docker exec -it nextcloud-aio-nextcloud /bin/sh -c "apk add --no-cache bash ncurses vim musl-locales musl-locales-lang && export LANG=en_US.UTF-8 && exec bash"
```

Then open the file with:

```bash
vi lib/private/AppFramework/Routing/RouteParser.php
```

Make the same change as described above, then save and exit with:

**`:wq`**

---

## After the patch

No restart is required. The route change takes effect immediately for the next request. The Files sidebar will now display `/raw/{token}` URLs instead of the longer fallback form.

