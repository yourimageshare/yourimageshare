# YourImageShare API

[![smithery badge](https://smithery.ai/badge/team-54m5/yourimageshare)](https://smithery.ai/servers/team-54m5/yourimageshare)
[![yourimageshare MCP server](https://glama.ai/mcp/servers/MediaShareORG/yourimageshare/badges/card.svg)](https://glama.ai/mcp/servers/MediaShareORG/yourimageshare)

Free JSON/REST API for [YourImageShare](https://yourimageshare.com) - a free
image and video hosting platform for anonymous and registered users. Use the
API to upload, list, and delete files programmatically instead of going
through the browser uploader.

- **Base URL:** `https://yourimageshare.com/api`
- **Auth:** an API key, sent as an `X-API-Key` header (preferred) or `?key=` fallback
- **Format:** JSON in and out, except uploads which are `multipart/form-data`
- **Full reference:** [API.md](API.md)
- **Live docs:** [yourimageshare.com/about/api](https://yourimageshare.com/about/api)
- **Postman collection:** [YourImageShare-API.postman_collection.json](YourImageShare-API.postman_collection.json)

## Get an API key

Sign in to your [account](https://yourimageshare.com/my-account) and open
the **API** tab. Your key is shown there, along with a **Regenerate**
button.

That tab also has a separate, scoped **Upload-only key** - use that one
instead anywhere the key ends up in something other people can see (a
forum plugin's page-source snippet, a public config file), since it can
only upload and not list/delete. See [Upload-only key](API.md#upload-only-key)
in the full reference. The [forum plugins](#forum-plugins) below already
use it.

## Quick example

```bash
curl "https://yourimageshare.com/api" \
  -H "X-API-Key: YOUR_API_KEY" \
  -F "uploads=@/path/to/photo.jpg"
```

```json
{
  "type": "success",
  "msg": "success",
  "data": {
    "id": "aB3xY9qRz1",
    "type": "image",
    "path": "https://i.yourimageshare.com/aB3xY9qRz1.webp",
    "src": "https://yourimageshare.com/ib/aB3xY9qRz1.webp",
    "direct": "https://yourimageshare.com/ib/aB3xY9qRz1"
  }
}
```

## Endpoints

| Method | Path | Description |
|---|---|---|
| `POST` | `/api` | Upload a file |
| `GET` | `/api` | List your uploads (paginated) |
| `DELETE` | `/api/{id}` | Delete one of your uploads |

Full request/response shapes, rate limits, error codes, and code samples in
curl, JavaScript, Python, PHP, and Go are in [API.md](API.md).

## Client libraries

Ten official SDKs (across nine languages/runtimes, JS/TS counted once with
two registries), so you don't have to hand-roll the HTTP calls, plus a
Discord bot built on top of one of them:

- **JavaScript/TypeScript:** [`yourimageshare`](https://www.npmjs.com/package/yourimageshare) on npm - `npm install yourimageshare`. Also on [JSR](https://jsr.io/@yourimageshare/yourimageshare) as `@yourimageshare/yourimageshare` - `npx jsr add @yourimageshare/yourimageshare`, same source, published as TypeScript directly. Zero dependencies, works in Node.js and browsers. Source: [/js](js).
- **Python:** [`yourimageshare`](https://pypi.org/project/yourimageshare/) on PyPI - `pip install yourimageshare`. One dependency (`requests`), Python 3.8+. Source: [/python](python).
- **PHP:** [`yourimageshare/yourimageshare-php`](https://packagist.org/packages/yourimageshare/yourimageshare-php) on Packagist - `composer require yourimageshare/yourimageshare-php`. Zero Composer dependencies (uses PHP's own `curl` extension), PHP 7.4+. Own repo ([MediaShareORG/yourimageshare-php](https://github.com/yourimageshare/yourimageshare-php)) - Packagist requires a root-level `composer.json`, not a monorepo subdirectory.
- **Go:** [`github.com/yourimageshare/yourimageshare/go`](https://pkg.go.dev/github.com/yourimageshare/yourimageshare/go) - `go get github.com/yourimageshare/yourimageshare/go`. Zero dependencies (standard library only), Go 1.18+. Source: [/go](go).
- **Rust:** [`yourimageshare`](https://crates.io/crates/yourimageshare) on crates.io - `cargo add yourimageshare`. Two dependencies (`ureq`, blocking/no async runtime, plus `serde`), Rust 1.70+. Source: [/rust](rust).
- **Ruby:** [`yourimageshare`](https://rubygems.org/gems/yourimageshare) on RubyGems - `gem install yourimageshare`. Zero gem dependencies (stdlib `Net::HTTP` + `json`), Ruby 2.7+. Source: [/ruby](ruby).
- **.NET:** [`YourImageShare`](https://www.nuget.org/packages/YourImageShare) on NuGet (pending first publish) - `dotnet add package YourImageShare`. One dependency (Microsoft's own `System.Text.Json`), targets netstandard2.0 (.NET Framework and modern .NET both). Source: [/dotnet](dotnet).
- **Dart/Flutter:** [`yourimageshare`](https://pub.dev/packages/yourimageshare) on pub.dev (pending first publish) - `dart pub add yourimageshare`. One dependency (`http`, the Dart team's own package), Dart 2.17+. Source: [/dart](dart).
- **Elixir:** [`yourimageshare`](https://hex.pm/packages/yourimageshare) on Hex.pm - add `{:yourimageshare, "~> 1.0"}` to `mix.exs`. One dependency (`Req`), Elixir 1.14+. Returns `{:ok, result} | {:error, reason}` tagged tuples (the direct equivalent of Go's return style) plus bang variants that raise. Docs: [hexdocs.pm/yourimageshare](https://yourimageshare.hexdocs.pm). Source: [/elixir](elixir).
- **MCP server** (for AI coding agents - Claude Code, Cursor, and other MCP-compatible clients): [`yourimageshare-mcp`](https://www.npmjs.com/package/yourimageshare-mcp) on npm - `npx yourimageshare-mcp`, no install step. Also listed on [Smithery](https://smithery.ai/servers/team-54m5/yourimageshare) and [Glama](https://glama.ai/mcp/servers/MediaShareORG/yourimageshare). Source: [/mcp](mcp).
- **Discord bot**: `/upload file:<attachment>` slash command, built on the JS SDK. Self-hosted - run your own instance with your own bot token, same model as the ShareX/Flameshot integrations below, not a public bot we run. Source: [/discord](discord).

All SDKs wrap the same three endpoints below (`upload`/`list`/`delete`) with
typed results and a proper error type on API failures instead of raw HTTP
handling, and are MIT licensed. .NET and Dart are built and verified
against the live API but not yet published - each package name/ID is
confirmed available and reserved by this doc. .NET needs a NuGet API key
(Microsoft account); Dart needs an interactive `dart pub login` (Google
OAuth, browser-based - can't be scripted).

No official library for your language? The [full HTTP reference](API.md)
covers everything needed to call the API directly.

## Expiring uploads

Pass `expires_in` (seconds, 60 to 2,592,000) on upload to auto-delete a
file later - the storage object is removed and the page starts 410ing
within about 5 minutes of expiry. Omit it for a normal, permanent upload.

```bash
curl "https://yourimageshare.com/api" \
  -H "X-API-Key: YOUR_API_KEY" \
  -F "uploads=@/path/to/photo.jpg" \
  -F "expires_in=3600"
```

## Screenshot tools

Ready-made configs/scripts to upload straight from a screenshot tool, no
browser tab required:

- [`YourImageShare.sxcu`](YourImageShare.sxcu) - ShareX custom uploader
- [`yis-flameshot-upload.sh`](yis-flameshot-upload.sh) - Flameshot (Linux)
- [`yis-greenshot-upload.ps1`](yis-greenshot-upload.ps1) - Greenshot (Windows)

Setup instructions are in the comments at the top of each file, and on the
[live docs page](https://yourimageshare.com/about/api#screenshot-tools).

## Forum plugins

A real "Upload Image" button next to your forum's post editor - each one
uploads through `/api` and inserts BBCode at your cursor. All six are
configured with your **Upload-only key** (not your main API key), since
the key renders in every visitor's page source and the upload-only key
can only upload on your behalf - see [Upload-only key](API.md#upload-only-key).

- [`phpbb/`](forum-plugins/phpbb) - a real phpBB 3.2/3.3 extension ([download](forum-plugins/yourimageshare-phpbb.zip))
- [`smf/`](forum-plugins/smf) - a real SMF package ([download](forum-plugins/yourimageshare-smf.zip))
- [`mybb/`](forum-plugins/mybb) - a real MyBB 1.8 plugin ([download](forum-plugins/yourimageshare-mybb.zip))
- [`fluxbb/`](forum-plugins/fluxbb), [`punbb/`](forum-plugins/punbb), [`zetaboards/`](forum-plugins/zetaboards) - these three don't have a formal plugin system, so it's a small copy-paste snippet into your template/Admin CP instead

Forum uploads are `private` by default (the image file only, no YourImageShare page);
each plugin has a setting to switch to `unlisted`.

All six share one underlying widget, [`forum-upload.js`](forum-plugins/forum-upload.js)
(also served live from `yourimageshare.com/assets/js/forum-upload.js`), so
fixes/improvements apply everywhere at once. Full setup instructions and
downloads are also on the [live docs page](https://yourimageshare.com/about/api#forum-plugins).

## WordPress plugin

[`yourimageshare-media-offload/`](wordpress-plugin/yourimageshare-media-offload)
([download](wordpress-plugin/yourimageshare-media-offload.zip)) - offloads
your Media Library to YourImageShare instead of local disk: new image/video
uploads go through `/api` automatically, the local file (and every
generated thumbnail size) is deleted once the remote copy is confirmed, and
every WordPress attachment URL (block editor, featured images, galleries,
REST API) resolves to the YourImageShare-hosted file - no shortcode, no
workflow change. Built for the common shared-hosting problem of a Media
Library outgrowing a small storage quota. Uses the same **Upload-only key**
pattern as the forum plugins above; an optional full API key enables
deleting the remote copy when an attachment is deleted in WordPress (or
when restoring a file to local storage and choosing to remove the remote
copy).

v1.1.0 adds: bulk-offload for an *existing* Media Library (rate-limit-aware
batches, not just new uploads going forward), a one-click **Restore to
local** action so offloading isn't a one-way door, a Media Library status
column + row actions, and a dismissible admin notice for offload failures.
Dedicated top-level admin menu, icon is the site's own Pacifico "Y"
wordmark extracted as a real vector path from the font file. Audited
against the [WordPress.org detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
(external-service disclosure in both the readme and the settings page,
nonces + capability checks on every AJAX action, no bundled jQuery/CDN
assets, conservative `uninstall.php`). Not yet published to the
WordPress.org Plugin Directory - the code is complete and installable
manually (zip the folder, upload via Plugins > Add New > Upload Plugin) in
the meantime.

## Rate limits

Each API key gets 20 requests/minute and 500 requests/day by default, plus a
2,000/day ceiling per IP address as a backstop. Every response includes
`X-RateLimit-Limit`/`X-RateLimit-Remaining` headers; a `429` includes
`Retry-After`.

## Support

Questions or issues: [yourimageshare.com/contact](https://yourimageshare.com/contact).
