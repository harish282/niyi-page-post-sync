# Niyi Page Post Sync

Sync pages and posts from this WordPress site to one or more remote WordPress sites using the WordPress REST API.

## Features

- Configure multiple receivers (site URL, username, application password, active flag) under **Posts → Sync Receivers** / **Pages → Sync Receivers**, also linked from **Settings → Niyi Sync Setup**.
- Passwords are stored encrypted using a key derived from the WordPress auth salt.
- "Test Connection" verifies credentials against the remote `users/me` endpoint.
- On the Posts/Pages list screens:
  - A **Sync** column shows which receivers a post is synced to and their remote post IDs.
  - A **Sync to Receiver(s)** bulk action and a per-row **Sync** button are available.
  - With one active receiver, syncing starts immediately; with several, a popup lets you choose.
- If a remote post does not exist yet it is created; otherwise it is updated. Remote post IDs are stored in post meta, with a slug lookup used as a fallback to avoid duplicates.
- Media handling:
  - Featured images and images referenced in content (classic HTML, Gutenberg blocks, galleries, `srcset`) are uploaded to the remote site.
  - Local attachment IDs and URLs inside the content are rewritten to the remote IDs/URLs, including resized image variants.
  - Local → remote media ID maps are stored in post meta and reused on subsequent updates.
- Post meta / custom fields (including SEO fields) are synced in a separate REST update. Configure this under **Settings → Niyi Sync Setup → Sync Options**: enable/disable meta sync, include or skip private (`_`-prefixed) keys, and exclude keys with optional `*` wildcards. Keys must be registered with `show_in_rest` on the receiver for the REST API to accept them; failures are reported as non-fatal warnings.
- Shortcode compatibility: before syncing (when choosing receivers) and after each sync, the plugin checks shortcodes used in the content against the receiver's registered shortcodes. If a receiver is missing the plugin that provides a shortcode, an informational message names the likely plugin (for example Contact Form 7 or WPBakery Page Builder) and asks you to install/activate it on the receiver. A warning icon also appears in the **Sync** column for affected posts.

## Setup

1. On each remote site, create/generate an **Application Password** for a user who can edit posts/pages and upload media (Users → Profile → Application Passwords).
2. Enable/leave enabled the WordPress REST API on the remote site.
3. Activate this plugin and add receivers under **Posts → Sync Receivers** (also available under **Settings → Niyi Sync Setup**).
4. Use the **Sync** controls on the Posts/Pages list screens.
5. For shortcode compatibility checks, activate this plugin on the receiver too. It exposes `GET /wp-json/niyi-pps/v1/capabilities`, which reports the receiver's registered shortcodes. Without it, syncing still works and you get an informational warning asking you to verify/install the plugins that provide the shortcodes.

## Notes

- Requests use HTTP Basic Auth with the application password. SSL verification is disabled by default for local/self-signed development setups; enable it with:

  ```php
  add_filter( 'niyi_pps_sslverify', '__return_true' );
  ```

- Only `post` and `page` post types are synced by default.
- Sync meta keys are prefixed per receiver (`_niyi_pps_<receiver_id>_*`), so the same local post can be synced to many receivers independently.
- Shortcode warnings are stored per receiver in `_niyi_pps_<receiver_id>_shortcode_notices`.
- Developers can customise what is sent with two filters:

  ```php
  // Add/remove REST fields sent for a post.
  add_filter( 'niyi_pps_post_data', function ( $data, $post_id, $receiver_id ) {
      return $data;
  }, 10, 3 );

  // Add/remove meta keys sent for a post.
  add_filter( 'niyi_pps_meta_to_sync', function ( $meta, $post_id, $receiver_id ) {
      return $meta;
  }, 10, 3 );
  ```

The version is read from the plugin header. `readme.txt` / `license.txt` are staged when present (for WordPress.org releases); dev-only files such as `scripts/`, `tasks.md`, `node_modules/` and `.git/` are excluded.
