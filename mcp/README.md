# yourimageshare-mcp

[![npm version](https://img.shields.io/npm/v/yourimageshare-mcp.svg)](https://www.npmjs.com/package/yourimageshare-mcp)
[![npm downloads](https://img.shields.io/npm/dm/yourimageshare-mcp.svg)](https://www.npmjs.com/package/yourimageshare-mcp)
[![smithery badge](https://smithery.ai/badge/team-54m5/yourimageshare)](https://smithery.ai/servers/team-54m5/yourimageshare)
[![yourimageshare MCP server](https://glama.ai/mcp/servers/MediaShareORG/yourimageshare/badges/card.svg)](https://glama.ai/mcp/servers/MediaShareORG/yourimageshare)

MCP (Model Context Protocol) server for [YourImageShare](https://yourimageshare.com).
Built for AI coding agents (Claude Code, Cursor, and other MCP-compatible
tools) - paste a screenshot into a bug report, a PR description, or a
support thread as a real URL instead of a base64 blob or a manual upload
step. Works the same way with Claude Desktop and any other MCP client.

- **Get an API key:** sign in and open the **API** tab at
  [yourimageshare.com/my-account](https://yourimageshare.com/my-account).
- **Full HTTP reference:** [yourimageshare.com/about/api](https://yourimageshare.com/about/api).
- **OpenAPI 3.1 spec:** [yourimageshare.com/openapi.json](https://yourimageshare.com/openapi.json),
  if your tooling prefers that to this README.

## Install / run

No install needed - run directly with `npx`:

```bash
npx yourimageshare-mcp
```

Also listed on [Smithery](https://smithery.ai/servers/team-54m5/yourimageshare), if your MCP client installs from there instead.

### Claude Desktop / Claude Code config

Add to your MCP client's config (e.g. `claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "yourimageshare": {
      "command": "npx",
      "args": ["-y", "yourimageshare-mcp"],
      "env": {
        "YIS_API_KEY": "YOUR_API_KEY"
      }
    }
  }
}
```

## Environment variables

| Variable | Required | Purpose |
|---|---|---|
| `YIS_API_KEY` | yes | Your YourImageShare API key. |
| `YIS_BASE_URL` | no | Override the API base URL (default `https://yourimageshare.com/api`). For testing only. |

## Tools

### `upload_image`

Upload a file (up to 200 MB - files over 90 MB are sent in pieces
automatically). Provide **exactly one** of:
- `path` - a local file path this server process can read,
- `base64` + `filename` - inline file contents, for MCP clients with no local
  filesystem access, or
- `url` - a public http(s) link the YourImageShare server downloads itself.

Optional `expiresIn` (seconds, 60 to 2,592,000 = 30 days) auto-deletes the
upload later. If the account already uploaded this exact file, the existing
upload is returned with `duplicate: true`; set `allowDuplicate` to store a
new copy. Returns `id`, `type`, `path`, `src`, `direct`, `thumb` (280 px
WebP thumbnail), `width`, `height`, `size`, `locked`, `expires_at` and
`duplicate` - store `src`, since `path` can change after conversion.

Also optional: `visibility` (`unlisted` by default - file and page for
anyone with the link; `private` - file only, no page for others; `public` -
listed), `title` (90 characters) and `description` (500). New uploads return
a one-time `delete_url`.

### `get_upload`

Requires `id`. Returns that upload's links, thumbnail, size, visibility,
title and description.

### `update_upload`

Requires `id`, plus any of `visibility`, `title`, `description`. Only what
you pass changes; an empty string clears a title or description.

### `list_uploads`

Optional `page` (defaults to 1, 50 per page). Returns your uploads newest
first.

### `delete_upload`

Requires `id` (from `upload_image`/`list_uploads`). Permanently deletes the
upload.

## Notes

- This server only ever acts as the account tied to `YIS_API_KEY` - there's
  no way for a connected agent to act as any other account.
- Errors from the API (bad key, rate limit, file rejected by moderation,
  etc.) come back as a tool error result with the API's own message, not a
  crashed tool call.
- Built as a small, self-contained client rather than depending on the
  separate [`yourimageshare`](https://www.npmjs.com/package/yourimageshare)
  npm package, so this server's releases aren't coupled to that package's.

## License

MIT

## Support

[yourimageshare.com/contact](https://yourimageshare.com/contact)
