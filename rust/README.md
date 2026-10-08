# yourimageshare-api/rust

[![Crates.io](https://img.shields.io/crates/v/yourimageshare.svg)](https://crates.io/crates/yourimageshare)
[![docs.rs](https://docs.rs/yourimageshare/badge.svg)](https://docs.rs/yourimageshare)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official Rust SDK for the [YourImageShare](https://yourimageshare.com)
upload API. Blocking/synchronous - no async runtime required. Two small
dependencies: [`ureq`](https://crates.io/crates/ureq) for HTTP and
[`serde`](https://crates.io/crates/serde)/[`serde_json`](https://crates.io/crates/serde_json)
for JSON.

- **Get an API key:** sign in and open the **API** tab at
  [yourimageshare.com/my-account](https://yourimageshare.com/my-account).
- **Full HTTP reference:** [yourimageshare.com/about/api](https://yourimageshare.com/about/api)
  or [API.md in this repo](../API.md).
- Same API, same result shapes, in [JavaScript/TypeScript](https://www.npmjs.com/package/yourimageshare), [Python](https://pypi.org/project/yourimageshare/), [PHP](https://packagist.org/packages/yourimageshare/yourimageshare-php), and [Go](https://pkg.go.dev/github.com/yourimageshare/yourimageshare/go) too.

## Install

```bash
cargo add yourimageshare
```

## Usage

```rust
use yourimageshare::{Client, UploadOptions};

fn main() {
    let client = Client::new("YOUR_API_KEY");

    // Upload a file by path
    let result = client.upload("photo.jpg", None).unwrap();
    println!("{}", result.direct); // https://yourimageshare.com/ib/aB3xY9qRz1

    // Upload with auto-delete after 1 hour
    client.upload("photo.jpg", Some(UploadOptions { expires_in: Some(3600), ..Default::default() })).unwrap();

    // Upload from any std::io::Read (a network stream, an in-memory buffer, ...)
    // client.upload_reader(reader, "photo.jpg", None);

    // List your uploads (paginated, 50 per page)
    let listing = client.list(Some(1)).unwrap();
    for item in listing.data {
        println!("{} {}", item.id, item.direct);
    }

    // Delete an upload
    client.delete(&result.id).unwrap();
}
```

### Large files, duplicates, links and thumbnails

Files up to 200 MB are supported. Anything over 90 MB is sent in 5 MB pieces automatically (one request can carry at most 100 MB). If your account already uploaded the exact same file, the existing upload is returned with `duplicate` set - opt out with the allow-duplicate option. Results also carry `thumb` (a 280 px WebP thumbnail), `width`, `height`, `size` and `locked`. Store `src`, not `path`: `path` can change shortly after upload when the file is converted (WebP/MP4).

```rust
let result = client.upload("video.mp4", None)?; // over 90 MB: sent in pieces
println!("{} {:?} {:?} {}", result.src, result.thumb, result.width, result.duplicate);

// a fresh copy even if this exact file is already on your account
client.upload("photo.jpg", Some(UploadOptions { allow_duplicate: true, ..Default::default() }))?;

// let the server download a public link (up to 200 MB)
client.upload_url("https://example.com/photo.jpg", None)?;
```

Pieces are only used by `upload()` (a path, so the size is known); `upload_reader()` always sends one request. **1.1.0 note:** `UploadOptions` gained `allow_duplicate` - build it with `..Default::default()` as above.

### Visibility, titles and changing an upload

Each upload has a `visibility`: `unlisted` (the default - file and page work for anyone with the link, not listed anywhere), `private` (file only - the page link sends everyone but you to the file) or `public` (listed on the site). Uploads can carry a `title` (90 characters) and `description` (500). New uploads return a one-time `delete_url` that deletes the upload without an API key. `get`/`update` need the full API key.

```rust
use yourimageshare::{UpdateOptions, UploadOptions, Visibility};

let result = client.upload("photo.jpg", Some(UploadOptions {
    visibility: Some(Visibility::Private),
    title: Some("Sunset".into()),
    ..Default::default()
}))?;
println!("{:?}", result.delete_url); // shown once - keep it if you need it

let one = client.get(&result.id)?;
client.update(&result.id, &UpdateOptions { visibility: Some(Visibility::Public), ..Default::default() })?;
```

### Error handling

Failed requests return an `ApiError` (`.status` is the HTTP status code,
`0` for a transport/IO failure with no HTTP response; `.message` is the
server's error text):

```rust
match client.upload("photo.jpg", None) {
    Ok(result) => println!("{}", result.direct),
    Err(e) => eprintln!("{} {}", e.status, e.message),
}
```

## API

### `Client::new(api_key: impl Into<String>) -> Client`

`.with_base_url(url)` overrides the API base URL (mainly for testing).
`.with_timeout(Duration)` overrides the per-request timeout. Defaults to
30s.

### `client.upload(file_path, opts: Option<UploadOptions>) -> Result<UploadResult, ApiError>`

Streams the file from disk (doesn't buffer the whole thing in memory -
uploads can be up to 200MB). `opts.expires_in` is seconds, 60 to
2,592,000 (30 days); `None` or `Some(0)` means a permanent upload.
Returns an `UploadResult` with `id`, `kind` (`"image"`/`"video"` - named
`kind` since `type` is a Rust keyword), `path`, `src`, `direct`,
`expires_at`.

### `client.upload_reader(reader: impl Read, filename, opts) -> Result<UploadResult, ApiError>`

Same as `upload`, but from any `std::io::Read` instead of a file path.

### `client.list(page: Option<u32>) -> Result<ListResult, ApiError>`

Returns a `ListResult` with `data` (a `Vec<ListedUpload>` - `id`, `kind`,
`title`, `path`, `src`, `direct`, `expires_at`, `created_at`) and `meta`
(`ListMeta` - `current_page`, `last_page`, `total`).

### `client.delete(id: &str) -> Result<(), ApiError>`

Returns an `ApiError` on a 404/401; `Ok(())` on success.

## Rate limits

20 requests/minute and 500/day per key by default (2,000/day per IP as a
backstop). Not currently surfaced on the return values from this SDK - read
the `X-RateLimit-Limit`/`X-RateLimit-Remaining` response headers yourself if
you need them, or open an issue to request them on the result types.

## License

MIT

## Support

[yourimageshare.com/contact](https://yourimageshare.com/contact)
