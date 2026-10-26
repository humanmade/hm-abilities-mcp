# Human Made Abilities MCP

Gives an AI assistant 20 tools for running a WordPress site: reading and writing posts and pages, importing media, editing navigation menus, changing General, Reading and permalink settings, and auditing block usage across a multisite network. Each tool is an entry in WordPress's Abilities API, flagged public for MCP, so the [WordPress MCP adapter](https://github.com/WordPress/mcp-adapter) lists all of them once both plugins are active.

Every ability acts as the WordPress user the assistant connects as, and checks that user's capabilities on the site it touches. Most abilities take a `site_id`, so one connection can work on any subsite of a network.

## Requirements

- WordPress 6.9 or later, which ships the Abilities API, and PHP 8.2.
- The [WordPress MCP adapter](https://github.com/WordPress/mcp-adapter) plugin, for an MCP client. Without it the abilities are still reachable over REST, as **Calling an ability over REST** below describes.

## Installing

Install the `release` branch from GitHub. CI rebuilds it from `main` on every push. The plugin has no build step, so a checkout of `main` works as well. There are no tagged releases yet, and the package is not on Packagist.

To work on the plugin itself:

```sh
composer install && npm ci
npm run env:start        # WordPress at http://localhost:3031, with the MCP adapter and Query Monitor active
npm test                 # single-site suites
npm run test:multisite   # network suites
npm run env:test:start   # separate test site at http://localhost:8889, from .wp-env.test.json
npm run test:wp          # PHPUnit suites, run on the test site
npm run lint             # PHPCS and PHPStan, run side by side
```

## Connecting an AI assistant

With WP-CLI on the site, an MCP client can run `wp mcp-adapter serve --user=<login>` and talk to it over STDIO. Otherwise it connects over HTTP to `/wp-json/mcp/mcp-adapter-default-server` with an application password. The adapter's [CLI usage guide](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/cli-usage.md) has the client configuration for both.

Pick the login with care. The assistant can do whatever that user can. Writing content, menus and settings needs `manage_options`, so an administrator's login lets it publish, rewrite menus and change settings.

## Calling an ability over REST

Every ability is also served by core at `/wp-json/wp-abilities/v1/abilities/<name>/run`. The HTTP method follows the ability's annotations:

| Method   | Abilities                                                          |
| -------- | ------------------------------------------------------------------ |
| `GET`    | Every read-only ability. Input goes in the query string as `input[key]=value` |
| `POST`   | `create-content` and `upload-media`. Input goes in a JSON body as `{"input": {...}}` |
| `DELETE` | `update-content`, `update-navigation` and `update-settings`, which overwrite what is there |

```sh
curl -g -u admin:<application password> \
  'https://example.com/wp-json/wp-abilities/v1/abilities/hm-abilities/list-content/run?input[post_type]=post'
```

## Working across a multisite network

An ability that takes `site_id` switches to that subsite, checks the caller's capability there, and runs. Leave it out to act on the site serving the request. On a single site, any `site_id` but the current one is refused.

Writes are refused on a subsite marked archived, spam or deleted, with the error `hm_abilities_site_not_live`. Reads still work there, so a site can be inspected before it is removed. The `hm_abilities_allow_non_live_site_writes` filter lifts the guard.

`block-usage-stats` at network scope and `find-missing-block` cover one page of subsites per call. Name the sites with `site_ids`, or page through the network with `per_page` and `page`. Then follow `next_page` in the response until it is `null`.

Four abilities can reach every subsite: those two, `get-page-blocks` and `list-network-sites`. They need `manage_sites`, so on multisite only a super admin can call them. On a single site they need `edit_posts`.

## Abilities

All 20 sit in the `hm-abilities` category.

| Ability                                  | Does                                                            |
| ---------------------------------------- | --------------------------------------------------------------- |
| `hm-abilities/whoami`                    | The connected user's profile and roles on a site                |
| `hm-abilities/list-network-sites`        | The live subsites of the network, a page at a time              |
| `hm-abilities/list-content`              | Posts and pages, filtered by type, status, search, author or terms |
| `hm-abilities/list-recent-articles`      | Content published within a date window, newest first            |
| `hm-abilities/get-content`               | One post or page, with its block markup                         |
| `hm-abilities/create-content`            | Creates a post or page from block markup                        |
| `hm-abilities/update-content`            | Changes a post or page's title, status, slug or markup          |
| `hm-abilities/list-terms`                | The taxonomies on offer, and the terms in one of them           |
| `hm-abilities/list-users`                | Users by name, to filter content by author                      |
| `hm-abilities/upload-media`              | Imports a file from a URL or from base64                        |
| `hm-abilities/list-navigation`           | The site's navigation menus                                     |
| `hm-abilities/get-navigation`            | One menu and its items                                          |
| `hm-abilities/update-navigation`         | Replaces every item in a menu                                   |
| `hm-abilities/get-settings`              | General, Reading and permalink settings                         |
| `hm-abilities/update-settings`           | Changes any of those settings                                   |
| `hm-abilities/validate-blocks`           | Reports block types in markup that are not registered           |
| `hm-abilities/list-block-types`          | The registered block types                                      |
| `hm-abilities/block-usage-stats`         | How often each block type is used, on a site or across the network |
| `hm-abilities/get-page-blocks`           | The blocks on one page, found by URL or ID                      |
| `hm-abilities/find-missing-block`        | Which subsites lack a block type on their front page            |

The examples below are the `input` object an assistant sends. Every list ability answers with `items`, `count`, `total`, `page`, `total_pages`, `has_more` and `next_page`. `per_page` defaults to 20 and stops at 100.

### Who is connected

#### `hm-abilities/whoami`

Returns the connected user's ID, username, display name, email, avatar and roles, and the site they apply to. Call it first to confirm which account the assistant is using.

Needs a logged-in user who is a member of the target site. A super admin passes on every site.

```json
{ "site_id": 3 }
```

#### `hm-abilities/list-network-sites`

Lists live subsites with their `site_id`, name and URL, which the other abilities take as `site_id`. Archived, spam and deleted sites are left out. On a single site it returns that site alone.

| Input      | Default | Notes                         |
| ---------- | ------- | ----------------------------- |
| `search`   |         | Matches the domain or path    |
| `per_page` | 50      | Up to 100                     |
| `page`     | 1       |                               |

```json
{ "search": "news" }
```

### Reading content

The content abilities cover pages and posts. The `hm_abilities_post_types` filter adds custom post types.

Each post comes back with `id`, `title`, `slug`, `status`, `type`, `link`, `author` (`id`, `name`, `slug`), and `date` and `modified` in ISO 8601 in the site's timezone. `title` is the title as stored, so it can go back to `update-content` unchanged. Pass `"fields": ["terms"]` to add the taxonomy terms each one carries. They are left out by default because a post can carry many.

#### `hm-abilities/list-content`

Finds posts and pages. Needs `edit_posts` on the target site.

| Input         | Default | Notes                                                           |
| ------------- | ------- | --------------------------------------------------------------- |
| `post_type`   | `page`  | One type, or an array to search several at once                 |
| `status`      | `any`   | `any`, `draft`, `pending`, `publish` or `private`               |
| `search`      |         | Core's keyword search                                           |
| `author`      |         | A user ID, slug or login. An email needs `list_users`           |
| `terms`       |         | A map of taxonomy to one term or a list of them                 |
| `terms_field` | `slug`  | Whether `terms` holds slugs, names or IDs                       |

Several terms in one taxonomy match any of them. Separate taxonomies must all match. Without `edit_others_posts`, `any` leaves out drafts, pending and scheduled posts altogether. Ask for `draft` by name to list your own.

```json
{
  "post_type": ["post", "page"],
  "author": "jane-smith",
  "terms": { "category": ["recipes", "baking"] },
  "fields": ["terms"]
}
```

#### `hm-abilities/list-recent-articles`

Lists content published within a date window, newest first. With no dates it lists what was published today, in the site's timezone. Needs `edit_posts`.

| Input       | Default             | Notes                                       |
| ----------- | ------------------- | ------------------------------------------- |
| `post_type` | `post`              | One type, or an array                       |
| `after`     | Start of today      | Any `strtotime()` format, such as `-7 days` |
| `before`    | End of today        | Any `strtotime()` format                    |

```json
{ "after": "-7 days", "before": "now" }
```

#### `hm-abilities/get-content`

Returns one post or page by `id`, with its raw block markup in `content`. Needs `edit_posts`, and permission to read that post. A post of a type the plugin does not expose is refused.

```json
{ "id": 42 }
```

### Writing content

Both write abilities need `manage_options` on the target site, on top of core's own checks for the post type. Setting `publish` or `private` also needs that type's `publish_posts`.

#### `hm-abilities/create-content`

Creates a post or page from block markup. `post_type` and `title` are required. `status` defaults to `draft`, and can be `draft`, `pending`, `publish` or `private`. `slug` and `content` are optional.

```json
{
  "post_type": "page",
  "title": "Opening hours",
  "content": "<!-- wp:paragraph --><p>Open 9 to 5, Monday to Friday.</p><!-- /wp:paragraph -->"
}
```

`create-content` does not check block types, so run `validate-blocks` on the markup first.

#### `hm-abilities/update-content`

Changes an existing post's `title`, `status`, `slug` or `content`. Only `id` is required, and fields left out keep their value. `content` replaces the whole of the stored markup, so read it with `get-content` and edit that copy.

```json
{ "id": 42, "status": "publish" }
```

### Finding terms and authors

These turn a name used in conversation into the value `list-content` filters on. Both need `edit_posts`.

#### `hm-abilities/list-terms`

Call it with no `taxonomy` to see which taxonomies are on offer, with each one's label, post types and term count. Call it again with a `taxonomy` to page through its terms. The taxonomies are the public ones on the exposed post types, and the `hm_abilities_taxonomies` filter changes the list.

| Input        | Default | Notes                                                   |
| ------------ | ------- | ------------------------------------------------------- |
| `taxonomy`   |         | Leave out to list taxonomies only                       |
| `search`     |         | Matches part of a term name: `miso` finds "white miso paste" |
| `hide_empty` | `false` | Skips terms no content uses                             |
| `orderby`    | `name`  | `name` A to Z, or `count`, most used first              |

```json
{ "taxonomy": "post_tag", "search": "miso" }
```

#### `hm-abilities/list-users`

Finds users by part of their display name, username or slug, so "jane" finds Jane Smith. The `slug` it returns is what `list-content` takes as `author`.

| Input           | Default | Notes                                                   |
| --------------- | ------- | ------------------------------------------------------- |
| `search`        |         | A user with `list_users` also searches email addresses  |
| `roles`         |         | Needs `list_users`                                      |
| `has_published` | `false` | Only users who have published an exposed post type      |

Roles are returned only to a user with `list_users`.

```json
{ "search": "jane", "has_published": true }
```

### Importing media

#### `hm-abilities/upload-media`

Imports a file into the Media Library and returns its `id` and `url` on this site. Pass exactly one of `source_url` or `data`. Needs `upload_files`, and permission to edit the post given as `post_id`.

| Input        | Notes                                                                    |
| ------------ | ------------------------------------------------------------------------ |
| `source_url` | A URL to download                                                        |
| `data`       | Base64, or a `data:` URI. Up to 5 MB decoded, or the upload limit if lower |
| `filename`   | Defaults to the URL's basename, or for `data` to `upload` plus an extension |
| `title`      |                                                                          |
| `alt`        | Alt text, for an image                                                   |
| `post_id`    | The post to attach the file to. Unattached by default                    |

```json
{ "source_url": "https://example.org/images/hero.jpg", "alt": "The shop front at dusk", "post_id": 42 }
```

A second import of the same URL, or of the same bytes, returns the first attachment with `already_existed` true. It does not apply the new `title` or `alt`. A file replaced at the same URL is not fetched again.

The download refuses a host that resolves to a private or reserved address, follows at most 3 redirects, and stops at the site's upload limit. The `hm_abilities_media_allowed_source_hosts` filter limits downloads to a list of hosts. Two calls importing the same new file at once can both create an attachment, so import one file at a time.

### Navigation menus

These work on block navigation menus, the `wp_navigation` posts. A trashed menu is neither read nor written.

#### `hm-abilities/list-navigation`

Lists each menu's `id`, `title` and `slug`. Needs `edit_theme_options`.

```json
{}
```

#### `hm-abilities/get-navigation`

Returns one menu's items, in order, as `label`, `url`, `kind` and `children`. Needs `edit_theme_options`.

```json
{ "id": 7 }
```

#### `hm-abilities/update-navigation`

Replaces every item in a menu with the `items` given. Needs `manage_options`. Each item takes a `label`, a `url`, and an optional `kind` and `children`. `kind` defaults to `custom`.

```json
{
  "id": 7,
  "items": [
    { "label": "Home", "url": "/" },
    { "label": "Shop", "url": "/shop/", "children": [ { "label": "Gift cards", "url": "/shop/gift-cards/" } ] }
  ]
}
```

A menu takes up to 200 items at the top level, and up to 200 under each of those. Items nested deeper than 5 levels are dropped. Read the menu with `get-navigation` first, since anything left out of `items` is removed.

### Site settings

Both abilities need `manage_options`.

#### `hm-abilities/get-settings`

Returns the General, Reading and permalink settings.

```json
{}
```

#### `hm-abilities/update-settings`

Changes any of the settings below. Send only the keys to change. It returns every setting as it stands afterwards.

| Group      | Keys                                                                                       |
| ---------- | ------------------------------------------------------------------------------------------ |
| General    | `title`, `description`, `email`, `timezone`, `date_format`, `time_format`, `start_of_week`, `language` |
| Reading    | `show_on_front`, `page_on_front`, `page_for_posts`, `posts_per_page`, `blog_public`        |
| Permalinks | `permalink_structure`, `category_base`, `tag_base`                                         |

```json
{ "show_on_front": "page", "page_on_front": 12, "blog_public": 0 }
```

- The site address and WordPress address are left out, because a wrong value there locks everyone out.
- `page_on_front` and `page_for_posts` must name a published page, or `0` to clear them.
- `show_on_front` `page` needs a `page_on_front`, given now or already set.
- A value WordPress refuses, such as an unknown timezone, fails with `hm_abilities_setting_rejected`. Settings applied earlier in the same call stay applied.
- Permalink keys change only on the site serving the request, because flushing another subsite's rewrite rules from here would drop its own routes.

### Checking block markup

These run on the current site and take no `site_id`. Both need `edit_posts`.

#### `hm-abilities/validate-blocks`

Parses block markup and lists each block type in it that is not registered. `valid` is true when there are none. Markup is capped at 256 KB, and blocks nested past 50 levels are not checked, which sets `truncated`.

```json
{ "content": "<!-- wp:acme/hero /--><!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->" }
```

#### `hm-abilities/list-block-types`

Lists every registered block type with its name, title and category. `namespace` narrows it to one, such as `core`. The whole list comes back in one response.

```json
{ "namespace": "core" }
```

### Auditing blocks across a network

These need `manage_sites` on multisite and `edit_posts` on a single site.

#### `hm-abilities/block-usage-stats`

Counts how many times each block type is used. With `scope` `site`, the default, it counts one site, chosen by `site_id`. With `scope` `network` it counts a page of subsites, 10 by default and 25 at most, with a breakdown per site.

| Input       | Default   | Notes                                    |
| ----------- | --------- | ---------------------------------------- |
| `scope`     | `site`    | `site` or `network`                      |
| `post_type` | All types | One of the exposed post types            |
| `status`    | `publish` | Any status `list-content` takes          |

```json
{ "scope": "network", "per_page": 25, "page": 2 }
```

Each site's scan stops at 500 posts, lowest IDs first, and the response sets `truncated` when one did. The `hm_abilities_block_usage_scan_limit` filter changes the limit. A network response that does not cover every site carries a `note` saying its counts are for that page alone.

#### `hm-abilities/get-page-blocks`

Lists the blocks on one page, with a count for each type and a nested outline. Give a front-end `url` and the subsite is worked out from it, or give a `page_id` and an optional `site_id`. `include_outline` false drops the outline.

```json
{ "url": "https://example.com/news/about/" }
```

#### `hm-abilities/find-missing-block`

Reports which subsites have a block type on their front page, and which do not. `path` checks another page, such as `about`, on every subsite. A subsite with no page at that path counts as missing. It covers 100 subsites per call, so a network of up to 100 live sites needs one call.

```json
{ "block_name": "core/details", "path": "faq" }
```

## Filters

| Filter                                     | Receives                              | Default                                       |
| ------------------------------------------ | ------------------------------------- | --------------------------------------------- |
| `hm_abilities_post_types`                  | Post types the content abilities use  | `[ 'page', 'post' ]`                          |
| `hm_abilities_taxonomies`                  | Taxonomies `list-terms` offers        | The public taxonomies on those post types     |
| `hm_abilities_media_allowed_source_hosts`  | Hosts `upload-media` may download from | Empty: any host on a public address          |
| `hm_abilities_media_max_download_bytes`    | Largest download, in bytes, and the URL | The upload limit, or 64 MB when none is set |
| `hm_abilities_media_max_data_bytes`        | Largest base64 file, decoded          | 5 MB, held under the upload limit             |
| `hm_abilities_block_usage_scan_limit`      | Posts scanned per site, and the scope | `500`                                         |
| `hm_abilities_allow_non_live_site_writes`  | Whether to write to a site that is not live, and its ID | `false`             |

A base64 upload raises peak memory by about 5.6 times the file's size through the MCP adapter. Raise PHP's `memory_limit` alongside `hm_abilities_media_max_data_bytes`.

```php
add_filter( 'hm_abilities_post_types', function ( array $post_types ): array {
	$post_types[] = 'event';
	return $post_types;
} );
```

## Limits

- No ability deletes or trashes content, media, menus or terms.
- No ability assigns terms to a post, or edits post meta, featured images or excerpts.
- Settings cover the General and Reading screens and permalinks only. Discussion, Media and Privacy settings are not exposed.
- `list-block-types` and `validate-blocks` cannot target another subsite.
- `upload-media` matches a repeat import by URL, not by content, so the same file at two URLs is imported twice.
