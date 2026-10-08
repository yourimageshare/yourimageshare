# Changelog

All notable changes to this repository (API docs, SDKs, MCP server, forum
plugins, and screenshot tool configs) are documented here. Format loosely
follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

Each SDK also has its own version, tracked in its `package.json`/
`pyproject.toml` (currently `yourimageshare` JS SDK 1.1.0, `yourimageshare`
Python SDK 1.1.0, `yourimageshare-mcp` 1.1.0, `yourimageshare-discord-bot`
0.1.0) - this log covers the repo as a whole rather than duplicating
per-package release notes.

## [Unreleased]

- **API: files up to 200 MB in pieces.** New `POST /api/chunk` takes 5 MB
  pieces (`upload_id`, `index`, `total`, `chunk`); finish with `POST /api`
  and `upload_id` + `filename`. Gets past the 100 MB per-request cap, which
  previously only `url` uploads could. Pieces have their own limit (300 a
  minute per key) and only the finishing request counts as an upload.
- **API: richer upload/list responses.** New fields `thumb` (280 px WebP),
  `width`, `height`, `size`, `locked`, and on uploads `duplicate`. `path`
  is now documented as changing after conversion - store `src`.
- **API: duplicate uploads are reused.** The exact same file uploaded again
  by the same account returns the existing upload with `duplicate: true`
  (not for expiring uploads; `allow_duplicate=1` opts out).
- **API: Upload-only keys get 2,000 uploads a day** (full keys stay at 500).
- **Direct file links are cacheable:** `/ib/<id>.<ext>` for a public file
  now redirects to its stable `i.yourimageshare.com` URL (cached for an
  hour; a minute while a new upload may still be converted) instead of a
  signed URL that changed every 4 minutes.
- **WordPress plugin 1.2.0/1.3.0** - see its readme changelog: permanent
  `src` links (1.1.0 links broke after WebP conversion), post content
  rewritten after bulk offload, thumbnails + srcset, background bulk
  offload via WP-Cron, `wp yis` CLI commands, chunked uploads up to 200 MB,
  WordPress.org Plugin Check clean, tested with WordPress 7.1.
- Docs: `API.md`, `openapi.yaml`/`openapi.json` and `/about/api` updated for
  all of the above (`API.md` also gained the existing `url` field and the
  full list of accepted formats).
- **SDKs 1.1.0 (JS, Python, Ruby, Rust, Go, .NET, Dart, Elixir, PHP) and
  MCP server 1.1.0:** new result fields (`thumb`, `width`, `height`, `size`,
  `locked`, `duplicate`), an allow-duplicate option, upload from a link,
  and files up to 200 MB - anything over 90 MB is sent in 5 MB pieces
  automatically, with progress callbacks.
  - **Python 1.0.x and MCP 1.0.x broke on the new response fields** (Python
    built results with `UploadResult(**data)`, the MCP server declared closed
    output schemas). The API sends those versions the old field set; 1.1.0
    of both ignores fields it doesn't know.
  - Rust: `UploadOptions` gained `allow_duplicate`; build it with
    `..Default::default()`.
  - Elixir: `delete/2` now returns `:ok` as documented (it returned
    `{:ok, :ok}`).
  - MCP server: `upload_image` takes `url` and `allowDuplicate`; the MCP
    registry name is now `io.github.yourimageshare/yourimageshare`.
- **API: visibility, titles, delete links, one-upload endpoints.** Uploads
  take `visibility` (`unlisted` default, `private` = file only with no page
  for anyone but the owner, `public` = listed), `title` and `description`;
  responses carry them plus a one-time `delete_url` on new uploads.
  New `GET /api/{id}` and `PATCH /api/{id}` (full API key). All SDKs gained
  the options and `get`/`update` methods; the MCP server gained
  `get_upload` and `update_upload` tools. Rust: `UploadOptions` is no
  longer `Copy` (it now holds the title/description strings).
- **WordPress plugin** uploads as `private`. **Forum plugins 1.1.0** (and the
  live `forum-upload.js`) upload as `private` by default, configurable per
  forum (`window.YIS_VISIBILITY` / each plugin's setting).
- Postman collection: `Upload Piece (large files)`, `Get Upload`,
  `Update Upload` requests and the new upload fields.

- **The `X-API-Key` header now takes precedence over `?key=`** when both
  are present (previously the query parameter won). `?key=` still works
  as a fallback for older integrations - this only changes which one is
  used if a caller sends both. Updated all code examples in `API.md`,
  `README.md`, the live `/about/api` docs page, `openapi.yaml`/
  `openapi.json`, and the Postman collection description to lead with the
  header, since query strings tend to end up in access/proxy logs and
  `Referer` headers in ways headers generally don't. No SDK changes needed
  - the JS/Python SDKs, MCP server, Discord bot, ShareX config, and
  Flameshot/Greenshot scripts already sent the header exclusively.
- **New: scoped Upload-only key**, alongside the existing full API key, both
  managed from the API tab on `/my-account`. The full key still does
  upload+list+delete; the new upload-only key can only upload -
  `GET /api` and `DELETE /api/{id}` reject it with a `401`. All six forum
  plugins/snippets (`smf/`, `mybb/`, `phpbb/`, and the `fluxbb`/`punbb`/
  `zetaboards` instructions) now default to `YOUR_UPLOAD_ONLY_KEY` instead
  of the full key, since that's the one case in this repo where a key
  routinely renders in every visitor's page source - a leaked upload-only
  key can only be used to upload on the account's behalf, not enumerate or
  delete its uploads. Rebuilt `yourimageshare-smf.zip`,
  `yourimageshare-mybb.zip`, and `yourimageshare-phpbb.zip` from the
  updated sources. `API.md`, `README.md`, and `openapi.yaml` all document
  the new key and when to use it over the full one; no change to the JS/
  Python SDKs, MCP server, or Discord bot - they're trusted server-side
  code, not the page-source-exposure case this addresses, and the request/
  response contract for existing keys is unchanged (fully backwards
  compatible - existing full API keys keep working exactly as before, this
  only adds a second, narrower key type).
- Hardened server-side API-key storage: keys are now encrypted at rest
  (previously plaintext), authenticated via an indexed SHA-256 lookup hash
  rather than the encrypted value directly. Purely a storage-layer change -
  no effect on how API consumers send a key (`?key=` or `X-API-Key`, same
  as before).
- Added `discord/` - a Discord bot (`/upload file:<attachment>
  [expires_in_days]`) wrapping the existing public API + JS SDK. v1 uploads
  under a single shared account (the bot's own API key), same model most
  paste-an-image-get-a-link Discord bots use; per-user linked accounts
  would need real Discord OAuth, noted as a real v2 rather than built here.
  Compiles clean under strict TypeScript; smoke-tested the upload/error
  path against the real live API with a deliberately bad key (confirmed
  the exact `401` error shape comes back correctly through the SDK). The
  Discord-specific half (slash command registration, interaction handling)
  still needs a real bot token to test end-to-end.
- Published `https://yourimageshare.com/openapi.json` - a real OpenAPI 3.1
  spec built from the actual `UploadController.php` source (not just the
  JS SDK's types), validated against the OpenAPI 3.1 JSON Schema. Linked
  from `/about/api` and the MCP README.
- Repositioned the MCP server's pitch from generic "for AI agents" to the
  concrete bug-report/PR-screenshot use case, on both `/about/api` and
  `mcp/README.md`.
- `yourimageshare-mcp` 1.0.6: fixed the actual cause of Smithery's 45/100
  quality score. `src/index.ts` was calling `process.exit(1)` if
  `YIS_API_KEY` wasn't set, *before* the server or any tool got
  registered - Smithery's quality scanner has no real credentials to
  give it, so the process almost certainly crashed before it could ever
  see the rich tool definitions added in 1.0.5, explaining a 0/40
  Capability Quality score despite Server Metadata scoring a clean
  35/35. Made the missing-key check non-fatal: tools now register and
  `tools/list` succeeds with zero credentials (verified directly, not
  assumed - spawned the built server with no `YIS_API_KEY` and confirmed
  all 3 tools list successfully); actual tool calls still fail cleanly
  through the existing API-error path (a 401 from the real API) if no
  valid key is set, same as before.
- `yourimageshare-mcp` 1.0.5: richer tool definitions to raise Smithery's
  quality score (was 45/100) - added MCP `annotations` (readOnlyHint /
  destructiveHint / idempotentHint / openWorldHint) and `outputSchema` to
  all 3 tools, server-level `title`/`description`/`websiteUrl`, and
  rewrote all 3 descriptions against Smithery's published Tool Definition
  Quality Score rubric (purpose clarity, usage guidelines, behavioral
  transparency, parameter semantics, conciseness, contextual
  completeness) - including documenting a real gotcha in the upload
  response shape (the `direct` field is actually the shareable *page*
  URL, not a direct file link; `src` is). Verified via a real MCP client
  connection (`tools/list`) that the schemas are well-formed before
  publishing, not just that it compiles.
- `yourimageshare-mcp` 1.0.4: added `mcpName` (`io.github.yourimageshare/yourimageshare`
  - note the exact GitHub org casing; the registry's namespace check is
  case-sensitive, unlike GitHub login itself, so 1.0.3 shipped with a
  lowercased value that the registry rejected) to `package.json` and
  published `mcp/server.json`, to list the server on the official MCP
  Registry (registry.modelcontextprotocol.io). Also added `mcp/glama.json`
  and `mcp/manifest.json` (MCPB bundle manifest, used to publish to
  Smithery) in prior unreleased work.
- Added `CODE_OF_CONDUCT.md`, issue templates (bug report, feature request),
  and a pull request template, rounding out GitHub's community standards
  checklist.
- Added `LICENSE`, `SECURITY.md`, `CONTRIBUTING.md`, this changelog, an
  `openapi.yaml` spec, and a CI workflow that builds the JS/TS and MCP
  packages and sanity-checks the Python package on every push/PR.

## 2026-07-24

- Added forum upload plugins: phpBB, SMF, MyBB, FluxBB, PunBB, ZetaBoards
  (`forum-plugins/`), sharing a common `forum-upload.js` widget.
- Added JS, Python, and MCP SDK source as subfolders (`js/`, `python/`,
  `mcp/`).
- Documented expiring uploads (`expires_in`) and added official client
  library links to `README.md`.

## 2026-07-23

- Initial API documentation (`README.md`, `API.md`).
- Added the Postman collection
  (`YourImageShare-API.postman_collection.json`).
- Added screenshot tool configs: ShareX (`YourImageShare.sxcu`), Flameshot
  (`yis-flameshot-upload.sh`), Greenshot (`yis-greenshot-upload.ps1`).
