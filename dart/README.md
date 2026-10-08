# yourimageshare-api/dart

[![pub package](https://img.shields.io/pub/v/yourimageshare.svg)](https://pub.dev/packages/yourimageshare)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official Dart/Flutter client for the [YourImageShare](https://yourimageshare.com)
upload API. One dependency (the Dart team's own `http` package), Dart 2.17+.

- **Get an API key:** sign in and open the **API** tab at
  [yourimageshare.com/my-account](https://yourimageshare.com/my-account).
- **Full HTTP reference:** [yourimageshare.com/about/api](https://yourimageshare.com/about/api)
  or [API.md in this repo](../API.md).
- Same API, same result shapes, in [JavaScript/TypeScript](https://www.npmjs.com/package/yourimageshare), [Python](https://pypi.org/project/yourimageshare/), [PHP](https://packagist.org/packages/yourimageshare/yourimageshare-php), [Go](https://pkg.go.dev/github.com/yourimageshare/yourimageshare/go), [Rust](https://crates.io/crates/yourimageshare), and [Ruby](https://rubygems.org/gems/yourimageshare) too.

## Install

```bash
dart pub add yourimageshare
```

## Usage

```dart
import 'package:yourimageshare/yourimageshare.dart';

Future<void> main() async {
  final client = YourImageShareClient('YOUR_API_KEY');

  // Upload a file by path
  final result = await client.upload('photo.jpg');
  print(result.direct); // https://yourimageshare.com/ib/aB3xY9qRz1

  // Upload with auto-delete after 1 hour
  await client.upload('photo.jpg', expiresIn: 3600);

  // Upload from raw bytes (a network response, an in-memory buffer, ...)
  // await client.uploadBytes(bytes, 'photo.jpg');

  // List your uploads (paginated, 50 per page)
  final listing = await client.list();
  for (final item in listing.data) {
    print('${item.id} ${item.direct}');
  }

  // Delete an upload
  await client.delete(result.id);
}
```

### Large files, duplicates, links and thumbnails

Files up to 200 MB are supported. Anything over 90 MB is sent in 5 MB pieces automatically (one request can carry at most 100 MB). If your account already uploaded the exact same file, the existing upload is returned with `duplicate` set - opt out with the allow-duplicate option. Results also carry `thumb` (a 280 px WebP thumbnail), `width`, `height`, `size` and `locked`. Store `src`, not `path`: `path` can change shortly after upload when the file is converted (WebP/MP4).

```dart
final result = await client.upload('video.mp4',
    onProgress: (sent, total) => print('${sent * 100 ~/ total}%'));
print('${result.src} ${result.thumb} ${result.width} ${result.duplicate}');

// a fresh copy even if this exact file is already on your account
await client.upload('photo.jpg', allowDuplicate: true);

// let the server download a public link (up to 200 MB)
await client.uploadUrl('https://example.com/photo.jpg');
```

### Visibility, titles and changing an upload

Each upload has a `visibility`: `unlisted` (the default - file and page work for anyone with the link, not listed anywhere), `private` (file only - the page link sends everyone but you to the file) or `public` (listed on the site). Uploads can carry a `title` (90 characters) and `description` (500). New uploads return a one-time `delete_url` that deletes the upload without an API key. `get`/`update` need the full API key.

```dart
final result = await client.upload('photo.jpg', visibility: 'private', title: 'Sunset');
print(result.deleteUrl); // shown once - keep it if you need it

final one = await client.get(result.id);
await client.update(result.id, visibility: 'public', description: 'Lake at dusk');
```

### Error handling

Failed requests throw a `YourImageShareException` (`.status` is the HTTP
status code, `.message` is the server's error text):

```dart
try {
  final result = await client.upload('photo.jpg');
} on YourImageShareException catch (e) {
  print('${e.status}: ${e.message}');
}
```

## API

### `YourImageShareClient(String apiKey, {String baseUrl, http.Client? httpClient})`

`baseUrl` overrides the API base URL (mainly for testing). `httpClient`
overrides the underlying `http.Client`, e.g. to inject a custom timeout or
a mock for tests.

### `client.upload(String filePath, {int? expiresIn})`

Streams the file from disk (doesn't buffer the whole thing in memory -
uploads can be up to 200MB). `expiresIn` is seconds, 60 to 2,592,000 (30
days); omit for a permanent upload. Returns an `UploadResult` with `id`,
`type`, `path`, `src`, `direct`, `expiresAt`.

### `client.uploadBytes(List<int> bytes, String filename, {int? expiresIn})`

Same as `upload`, but from raw bytes instead of a file path.

### `client.list({int page = 1})`

Returns a `ListResult` with `data` (a list of `ListedUpload` - `id`,
`type`, `title`, `path`, `src`, `direct`, `expiresAt`, `createdAt`) and
`meta` (`ListMeta` - `currentPage`, `lastPage`, `total`).

### `client.delete(String id)`

Throws a `YourImageShareException` on a 404/401; completes normally on
success.

## Rate limits

20 requests/minute and 500/day per key by default (2,000/day per IP as a
backstop). Not currently surfaced on the return values from this SDK - read
the `X-RateLimit-Limit`/`X-RateLimit-Remaining` response headers yourself if
you need them, or open an issue to request them on the result types.

## License

MIT

## Support

[yourimageshare.com/contact](https://yourimageshare.com/contact)
