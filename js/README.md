# yourimageshare

[![npm version](https://img.shields.io/npm/v/yourimageshare.svg)](https://www.npmjs.com/package/yourimageshare)
[![npm downloads](https://img.shields.io/npm/dm/yourimageshare.svg)](https://www.npmjs.com/package/yourimageshare)
[![license](https://img.shields.io/npm/l/yourimageshare.svg)](https://github.com/yourimageshare/yourimageshare/blob/main/js/LICENSE)

Official JavaScript/TypeScript SDK for the [YourImageShare](https://yourimageshare.com)
upload API. Works in Node.js (18+) and in browsers - zero runtime dependencies,
built on native `fetch`/`FormData`/`Blob`.

- **Get an API key:** sign in and open the **API** tab at
  [yourimageshare.com/my-account](https://yourimageshare.com/my-account).
- **Full HTTP reference:** [yourimageshare.com/about/api](https://yourimageshare.com/about/api)
  or [API.md in the yourimageshare-api repo](https://github.com/yourimageshare/yourimageshare/blob/main/API.md).

## Install

```bash
npm install yourimageshare
```

## Usage

```ts
import { YourImageShare } from 'yourimageshare';

const client = new YourImageShare({ apiKey: process.env.YIS_API_KEY! });

// Upload (browser: a File from an <input type="file">, or any Blob)
const upload = await client.upload(fileOrBlob);
console.log(upload.direct); // https://yourimageshare.com/ib/aB3xY9qRz1

// Upload with auto-delete after 1 hour
await client.upload(fileOrBlob, { expiresIn: 3600 });

// List your uploads (paginated, 50 per page)
const { data, meta } = await client.list();

// Delete an upload
await client.delete(upload.id);
```

### Uploading a local file in Node.js

The main entry point stays dependency-free and browser-safe, so it doesn't
import `fs`. For Node, use the `yourimageshare/node` helper to turn a file
path into a `Blob`:

```ts
import { YourImageShare } from 'yourimageshare';
import { blobFromFile } from 'yourimageshare/node';

const client = new YourImageShare({ apiKey: process.env.YIS_API_KEY! });
const blob = await blobFromFile('./screenshot.png', 'image/png');
const upload = await client.upload(blob, { filename: 'screenshot.png' });
console.log(upload.direct);
```

### Error handling

Failed requests throw `YourImageShareError` (message + HTTP `status`):

```ts
import { YourImageShare, YourImageShareError } from 'yourimageshare';

try {
  await client.upload(blob);
} catch (err) {
  if (err instanceof YourImageShareError) {
    console.error(err.status, err.message);
  }
}
```

## API

### `new YourImageShare({ apiKey, baseUrl? })`

`baseUrl` defaults to `https://yourimageshare.com/api` - override only for
testing against a different environment.

### `client.upload(file, options?)`

`file` is a `Blob`/`File`, or a raw `Buffer`/`Uint8Array`/`ArrayBuffer` (pass
`options.filename` in that case). `options.expiresIn` is seconds, 60 to
2,592,000 (30 days) - omit for a permanent upload. Resolves to:

```ts
{ id, type, path, src, direct, expires_at }
```

### `client.list(page?)`

Resolves to `{ data: [...], meta: { current_page, last_page, total } }`.

### `client.delete(id)`

Resolves once the upload is deleted; throws on a 404/401.

## Rate limits

20 requests/minute and 500/day per key by default (2,000/day per IP as a
backstop). Every response carries `X-RateLimit-Limit`/`X-RateLimit-Remaining`
headers - not currently exposed by this SDK's return values; read them from
`fetch` directly if you need them, or open an issue if you'd like them
surfaced on the client.

## License

MIT

## Support

[yourimageshare.com/contact](https://yourimageshare.com/contact)
