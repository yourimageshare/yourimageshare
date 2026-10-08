# YourImageShare API Reference

A small JSON/REST API for uploading, listing, and deleting files on
[YourImageShare](https://yourimageshare.com). No SDK required, just an HTTP
client and an API key - official JavaScript and Python SDKs are also
available, see [Client libraries](#client-libraries) below.

- **Base URL:** `https://yourimageshare.com/api`
- **Format:** JSON in and out, except uploads which are `multipart/form-data`
- **Auth:** an API key on every request

## Table of contents

- [Getting an API key](#getting-an-api-key)
- [Authentication](#authentication)
- [Upload-only key](#upload-only-key)
- [Endpoints](#endpoints)
  - [POST /api - Upload a file](#post-api---upload-a-file)
  - [GET /api - List your uploads](#get-api---list-your-uploads)
  - [DELETE /api/{id} - Delete an upload](#delete-apiid---delete-an-upload)
- [Rate limits](#rate-limits)
- [Errors](#errors)
- [Client libraries](#client-libraries)
- [Code examples](#code-examples)

## Getting an API key

Sign in, go to [My account](https://yourimageshare.com/my-account), and open
the **API** tab. Your key is shown there, along with a **Regenerate**
button. Regenerating a key invalidates the old one immediately, so update
anywhere it's in use before you click it.

That same tab also has a second, independent **Upload-only key** - see
[Upload-only key](#upload-only-key) below before you paste any key into a
forum plugin or other place that renders in page source.

## Authentication

Send your key as a header - preferred, since query strings tend to end up
in access logs, proxy logs, and `Referer` headers:

```
X-API-Key: YOUR_API_KEY
```

A `?key=` query parameter also works, kept for compatibility with older
integrations and quick one-off `curl` commands:

```
?key=YOUR_API_KEY
```

If both are present, the header wins.

Treat your key like a password - anyone with it can upload, list, and
delete on your account. Prefer the `X-API-Key` header over the query
parameter where you can: query strings tend to end up in access logs,
proxy logs, and `Referer` headers, while headers generally don't.

## Upload-only key

Your account has two keys, both shown on the API tab:

- **API key** - full access: upload, list, and delete. Use this for
  server-side code and trusted tools you control (scripts, the SDKs, the
  MCP server, a bot with its own server-side config).
- **Upload-only key** - upload only; `GET /api` (list) and
  `DELETE /api/{id}` both reject it with a `401`. Use this anywhere the key
  ends up visible to other people, most commonly a forum plugin's embedded
  `<script>window.YIS_API_KEY = "...";</script>` tag, which every visitor
  can see in page source. If that value leaks, the worst case is someone
  uploads files under your account - not that they can enumerate or delete
  your existing uploads.

Both key types work with the same `?key=`/`X-API-Key` mechanics above -
the server enforces the scope, not the client. Regenerate either key
independently from the API tab at any time.

## Endpoints

All endpoints share the same authentication. Uploads, lists and deletes
share the rate limits below; upload pieces (`POST /api/chunk`) have their
own, higher limit.

### POST /api - Upload a file

Uploads a single file for the authenticated account. Send the file in one
of three ways:

| Field | Type | Required | Description |
|---|---|---|---|
| `uploads` | file | one of these three | The file itself (multipart/form-data), up to 100 MB - the most a single request can carry. |
| `url` | string | | A public http(s) link to an image or video. We download it ourselves, up to 200 MB. |
| `upload_id` | string | | A file sent in pieces to `POST /api/chunk` beforehand (32 hex characters), up to 200 MB. See [Large files](#large-files-post-apichunk). |
| `filename` | string | no | With `upload_id`: the original file name (used for the file type check and shown on the upload's page). |
| `expires_in` | integer | no | Auto-delete this upload after this many seconds (60 to 2,592,000, i.e. 1 minute to 30 days). Omit for a normal, permanent upload. |
| `allow_duplicate` | boolean | no | Store a new copy even if this account already uploaded the exact same file (see `duplicate` below). |

Accepted types: JPEG, PNG, GIF, WebP, AVIF, BMP, TIFF and HEIC/HEIF images;
MP4, WebM, AVI, MOV, M4V, MKV, MPEG, WMV, FLV and 3GP video. HEIC/HEIF and
TIFF are stored as WebP; video other than MP4/WebM is converted to MP4.
Larger JPEG/PNG images are converted to WebP shortly after upload when
that makes the file noticeably smaller. Max size is 200 MB; oversized or
unreadable files are rejected with a `422`.

Response - `200 OK`:

```json
{
  "type": "success",
  "msg": "success",
  "data": {
    "id": "aB3xY9qRz1",
    "type": "image",
    "path": "https://i.yourimageshare.com/aB3xY9qRz1.png",
    "src": "https://yourimageshare.com/ib/aB3xY9qRz1.png",
    "direct": "https://yourimageshare.com/ib/aB3xY9qRz1",
    "thumb": "https://i.yourimageshare.com/thumb-aB3xY9qRz1.webp",
    "width": 1920,
    "height": 1080,
    "size": 482113,
    "locked": false,
    "expires_at": null,
    "duplicate": false
  }
}
```

| Field | Description |
|---|---|
| `id` | The file's unique identifier. |
| `type` | `image` or `video`. |
| `path` | The storage URL of the file as uploaded. It can change shortly afterwards when the file is converted (WebP/MP4), so **store `src`, not `path`**. |
| `src` | Permanent direct file URL - always opens the current file, suitable for an `<img>`/`<video>` `src` and for saving. |
| `direct` | The file's page on YourImageShare (title, description, comments, share options). |
| `thumb` | A 280 px wide WebP thumbnail (for a video, its first frame), or `null`. |
| `width`, `height` | Display size in pixels (upright, after EXIF rotation; a video's frame size), or `null` if unknown. |
| `size` | File size in bytes as stored. |
| `locked` | `true` if the upload is password-protected. |
| `expires_at` | ISO 8601 timestamp this upload will be auto-deleted at, or `null` if it doesn't expire. |
| `duplicate` | `true` if this account had already uploaded the exact same file: the existing upload is returned and nothing new is stored (it doesn't count as a new file, but the request still counts toward rate limits). Not applied to expiring uploads or with `allow_duplicate=1`. |

**Expiring uploads:** once `expires_at` passes, the file is deleted from
storage and the upload's page starts returning `410 Gone` within about 5
minutes. This can't be undone or extended after the fact - delete early
with the DELETE endpoint if you need to remove it sooner, or re-upload
with a new `expires_in` if you need it to last longer.

### Large files (POST /api/chunk)

A single request can carry at most 100 MB. For bigger files (up to
200 MB), or to keep each request small on a slow connection, send the
file in pieces of at most 5 MB, then finish with `POST /api`:

1. Pick an upload id: 32 random hex characters.
2. For each piece, `POST /api/chunk` (multipart/form-data) with `upload_id`,
   `index` (0-based), `total` (number of pieces) and the bytes in `chunk`.
   The answer is `{"type": "success", "chunk_size": 5242880}`.
3. `POST /api` with `upload_id` and `filename` (instead of `uploads`), plus
   any other upload field. The answer is the normal upload response.

Pieces of an upload that isn't finished are deleted after 2 hours. Only
the finishing `POST /api` counts toward the upload rate limits.

### GET /api - List your uploads

Returns a paginated list of the authenticated account's uploads (from any
source - API, website, or editor), newest first, 50 per page.

| Parameter | In | Required | Description |
|---|---|---|---|
| `page` | query | no | Page number, defaults to 1. |

Response - `200 OK`:

```json
{
  "type": "success",
  "data": [
    {
      "id": "aB3xY9qRz1",
      "type": "image",
      "title": null,
      "path": "https://i.yourimageshare.com/aB3xY9qRz1.webp",
      "src": "https://yourimageshare.com/ib/aB3xY9qRz1.webp",
      "direct": "https://yourimageshare.com/ib/aB3xY9qRz1",
      "thumb": "https://i.yourimageshare.com/thumb-aB3xY9qRz1.webp",
      "width": 1920,
      "height": 1080,
      "size": 482113,
      "locked": false,
      "expires_at": null,
      "created_at": "2026-07-23T15:43:28+01:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "total": 1 }
}
```

The fields mean the same thing here as they do on the upload response
above (for a password-protected upload, `path` and `thumb` are short-lived
signed links). There's no single "get one upload"
endpoint yet, so this list is also the way to look up a file's `id` after
the fact.

### DELETE /api/{id} - Delete an upload

Permanently deletes one of the authenticated account's uploads. This
cannot be undone from the API. `{id}` is the value returned as `id` by the
upload or list endpoints.

Response - `200 OK`:

```json
{ "type": "success", "msg": "Deleted." }
```

`404` if no upload with that id exists on your account (either it was
never yours, or it's already been deleted).

## Rate limits

Each API key has independent per-minute and per-day quotas, plus a coarser
per-IP daily ceiling as a backstop against one IP cycling through multiple
keys. Uploads, lists and deletes share the same limits.

| Window | Default limit | Scope |
|---|---|---|
| Per minute | 20 requests | per API key |
| Per day | 500 requests | per full API key |
| Per day | 2,000 requests | per Upload-only key (plugins that offload whole media libraries) |
| Per day | 2,000 requests | per IP address |
| Per minute | 300 pieces | per API key, `POST /api/chunk` only |

These defaults are admin-configurable and may change. Every response
includes standard rate-limit headers:

```
X-RateLimit-Limit: 20
X-RateLimit-Remaining: 14
```

A `429` is returned once a limit is exceeded, with a `Retry-After` header
telling you how many seconds to wait.

## Errors

Every error response uses the same shape, regardless of endpoint or cause:

```json
{ "type": "error", "errors": "A human-readable description of what went wrong." }
```

| Status | Meaning |
|---|---|
| 401 | Missing or invalid API key. |
| 403 | The account or your current IP has been banned from uploading. |
| 404 | No matching upload found for that id on this account (delete only). |
| 413 | The request body is over 100 MB. Send the file in pieces (`POST /api/chunk`) or as a `url` instead. |
| 422 | Validation failure - no file provided, an unsupported file type, unreadable image data, dimensions over the 30000x30000px limit, a file over 200 MB, or an unfinished/expired `upload_id`. |
| 429 | Rate limit exceeded - see [Rate limits](#rate-limits) above. |
| 500 | Something failed unexpectedly server-side. Safe to retry. |

## Client libraries

Official SDKs wrap the endpoints below with typed results and raise on API
errors instead of raw HTTP handling. Source for all three is in this repo:

- **JavaScript/TypeScript:** [`yourimageshare`](https://www.npmjs.com/package/yourimageshare) on npm - `npm install yourimageshare`. Zero dependencies, works in Node.js and browsers. Source: [/js](js).
- **Python:** [`yourimageshare`](https://pypi.org/project/yourimageshare/) on PyPI - `pip install yourimageshare`. One dependency (`requests`), Python 3.8+. Source: [/python](python).
- **MCP server** (for AI agents): [`yourimageshare-mcp`](https://www.npmjs.com/package/yourimageshare-mcp) on npm - `npx yourimageshare-mcp`. Source: [/mcp](mcp).

The raw HTTP examples below still apply for any other language, or if you'd
rather not add a dependency.

## Code examples

### curl

```bash
# Upload a file
curl "https://yourimageshare.com/api" \
  -H "X-API-Key: YOUR_API_KEY" \
  -F "uploads=@/path/to/photo.jpg"

# Upload a file that auto-deletes after 1 hour
curl "https://yourimageshare.com/api" \
  -H "X-API-Key: YOUR_API_KEY" \
  -F "uploads=@/path/to/photo.jpg" \
  -F "expires_in=3600"

# List uploads
curl "https://yourimageshare.com/api" -H "X-API-Key: YOUR_API_KEY"

# Delete an upload
curl -X DELETE "https://yourimageshare.com/api/aB3xY9qRz1" -H "X-API-Key: YOUR_API_KEY"
```

### JavaScript (fetch)

```javascript
const API_KEY = 'YOUR_API_KEY';
const BASE = 'https://yourimageshare.com/api';

async function uploadFile(file) {
  const form = new FormData();
  form.append('uploads', file);

  const res = await fetch(BASE, {
    method: 'POST',
    headers: { 'X-API-Key': API_KEY },
    body: form,
  });
  const json = await res.json();
  if (json.type !== 'success') throw new Error(json.errors);
  return json.data;
}

async function listUploads(page = 1) {
  const res = await fetch(`${BASE}?page=${page}`, {
    headers: { 'X-API-Key': API_KEY },
  });
  return res.json();
}

async function deleteUpload(id) {
  const res = await fetch(`${BASE}/${id}`, {
    method: 'DELETE',
    headers: { 'X-API-Key': API_KEY },
  });
  return res.json();
}
```

### Python (requests)

```python
import requests

API_KEY = "YOUR_API_KEY"
BASE = "https://yourimageshare.com/api"

HEADERS = {"X-API-Key": API_KEY}

# Upload a file
with open("/path/to/photo.jpg", "rb") as f:
    res = requests.post(BASE, headers=HEADERS, files={"uploads": f})
res.raise_for_status()
upload = res.json()["data"]

# List uploads
res = requests.get(BASE, headers=HEADERS, params={"page": 1})
uploads = res.json()

# Delete an upload
res = requests.delete(f"{BASE}/{upload['id']}", headers=HEADERS)
```

### PHP (curl)

```php
<?php

$apiKey = 'YOUR_API_KEY';
$base = 'https://yourimageshare.com/api';

// Upload a file
$curl = curl_init($base);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ["X-API-Key: $apiKey"],
    CURLOPT_POSTFIELDS => ['uploads' => new CURLFile('/path/to/photo.jpg')],
    CURLOPT_RETURNTRANSFER => true,
]);
$upload = json_decode(curl_exec($curl), true)['data'];
curl_close($curl);

// List uploads
$curl = curl_init("$base?page=1");
curl_setopt($curl, CURLOPT_HTTPHEADER, ["X-API-Key: $apiKey"]);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$uploads = json_decode(curl_exec($curl), true);
curl_close($curl);
```

### Go (net/http)

```go
package main

import (
    "bytes"
    "encoding/json"
    "io"
    "mime/multipart"
    "net/http"
    "os"
)

const apiKey = "YOUR_API_KEY"
const base = "https://yourimageshare.com/api"

func uploadFile(path string) (map[string]any, error) {
    file, err := os.Open(path)
    if err != nil {
        return nil, err
    }
    defer file.Close()

    body := &bytes.Buffer{}
    writer := multipart.NewWriter(body)
    part, _ := writer.CreateFormFile("uploads", path)
    io.Copy(part, file)
    writer.Close()

    req, _ := http.NewRequest("POST", base, body)
    req.Header.Set("Content-Type", writer.FormDataContentType())
    req.Header.Set("X-API-Key", apiKey)

    res, err := http.DefaultClient.Do(req)
    if err != nil {
        return nil, err
    }
    defer res.Body.Close()

    var result map[string]any
    json.NewDecoder(res.Body).Decode(&result)
    return result, nil
}
```
