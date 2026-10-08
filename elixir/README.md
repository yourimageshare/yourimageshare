# yourimageshare-api/elixir

[![Hex.pm](https://img.shields.io/hexpm/v/yourimageshare.svg)](https://hex.pm/packages/yourimageshare)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official Elixir SDK for the [YourImageShare](https://yourimageshare.com)
upload API. One dependency ([`Req`](https://hex.pm/packages/req), for HTTP +
native streaming multipart), Elixir 1.14+.

- **Get an API key:** sign in and open the **API** tab at
  [yourimageshare.com/my-account](https://yourimageshare.com/my-account).
- **Full HTTP reference:** [yourimageshare.com/about/api](https://yourimageshare.com/about/api)
  or [API.md in this repo](../API.md).
- Same API, same result shapes, in [JavaScript/TypeScript](https://www.npmjs.com/package/yourimageshare), [Python](https://pypi.org/project/yourimageshare/), [PHP](https://packagist.org/packages/yourimageshare/yourimageshare-php), [Go](https://pkg.go.dev/github.com/yourimageshare/yourimageshare/go), [Rust](https://crates.io/crates/yourimageshare), and [Ruby](https://rubygems.org/gems/yourimageshare) too.

## Install

Add to `mix.exs`:

```elixir
def deps do
  [
    {:yourimageshare, "~> 1.0"}
  ]
end
```

## Usage

```elixir
client = YourImageShare.Client.new("YOUR_API_KEY")

# Upload a file by path
{:ok, result} = YourImageShare.Client.upload(client, "photo.jpg")
result.direct
#=> "https://yourimageshare.com/ib/aB3xY9qRz1"

# Upload with auto-delete after 1 hour
YourImageShare.Client.upload(client, "photo.jpg", expires_in: 3600)

# Upload from any Enumerable/File.Stream (a network stream, an in-memory buffer, ...)
# YourImageShare.Client.upload_stream(client, stream, "photo.jpg")

# List your uploads (paginated, 50 per page)
{:ok, listing} = YourImageShare.Client.list(client)
Enum.each(listing.data, fn item -> IO.puts("#{item.id} #{item.direct}") end)

# Delete an upload
YourImageShare.Client.delete(client, result.id)
```

### Large files, duplicates, links and thumbnails

Files up to 200 MB are supported. Anything over 90 MB is sent in 5 MB pieces automatically (one request can carry at most 100 MB). If your account already uploaded the exact same file, the existing upload is returned with `duplicate` set - opt out with the allow-duplicate option. Results also carry `thumb` (a 280 px WebP thumbnail), `width`, `height`, `size` and `locked`. Store `src`, not `path`: `path` can change shortly after upload when the file is converted (WebP/MP4).

```elixir
{:ok, result} =
  YourImageShare.Client.upload(client, "video.mp4",
    on_progress: fn sent, total -> IO.puts("#{div(sent * 100, total)}%") end
  )

result.thumb
#=> "https://i.yourimageshare.com/thumb-aB3xY9qRz1.webp"

# a fresh copy even if this exact file is already on your account
YourImageShare.Client.upload(client, "photo.jpg", allow_duplicate: true)

# let the server download a public link (up to 200 MB)
YourImageShare.Client.upload_url(client, "https://example.com/photo.jpg")
```

### Error handling

Every function returns `{:ok, result} | {:error, %YourImageShare.APIError{}}`
by default - the direct equivalent of Go's `(result, error)` return, just
tagged-tuple style:

```elixir
case YourImageShare.Client.upload(client, "photo.jpg") do
  {:ok, result} -> IO.puts(result.direct)
  {:error, %YourImageShare.APIError{status: status, message: message}} ->
    IO.puts("#{status}: #{message}")
end
```

Every function also has a bang (`!`) variant that raises
`YourImageShare.APIError` instead, for callers who prefer that style
(`upload!/3`, `upload_stream!/4`, `list!/2`, `delete!/2`):

```elixir
result = YourImageShare.Client.upload!(client, "photo.jpg")
```

## API

### `YourImageShare.Client.new(api_key, opts \\ [])`

`opts[:base_url]` overrides the API base URL (mainly for testing).
`opts[:req_options]` merges extra options into every request (e.g.
`receive_timeout:` or a custom `:finch` pool).

### `Client.upload(client, file_path, opts \\ [])`

Streams the file from disk via `File.stream!/1` - doesn't buffer the whole
thing in memory. `opts[:expires_in]` is seconds, 60 to 2,592,000 (30 days);
omit for a permanent upload. Returns an `%UploadResult{}` with `id`, `type`,
`path`, `src`, `direct`, `expires_at`.

### `Client.upload_stream(client, stream, filename, opts \\ [])`

Same as `upload/3`, but from any `Enumerable`/`File.Stream` instead of a
file path.

### `Client.list(client, opts \\ [])`

Returns a `%ListResult{}` with `data` (a list of `%ListedUpload{}` - `id`,
`type`, `title`, `path`, `src`, `direct`, `expires_at`, `created_at`) and
`meta` (`%ListMeta{}` - `current_page`, `last_page`, `total`).
`opts[:page]` < 2 fetches the first page.

### `Client.delete(client, id)`

Returns `{:error, %APIError{}}` on a 404/401; `:ok` on success.

## Rate limits

20 requests/minute and 500/day per key by default (2,000/day per IP as a
backstop). Not currently surfaced on the return values from this SDK - read
the `X-RateLimit-Limit`/`X-RateLimit-Remaining` response headers yourself if
you need them, or open an issue to request them on the result types.

## License

MIT

## Support

[yourimageshare.com/contact](https://yourimageshare.com/contact)
