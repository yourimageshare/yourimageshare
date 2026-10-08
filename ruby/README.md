# yourimageshare-api/ruby

[![Gem Version](https://badge.fury.io/rb/yourimageshare.svg)](https://rubygems.org/gems/yourimageshare)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official Ruby SDK for the [YourImageShare](https://yourimageshare.com)
upload API. Zero gem dependencies (standard library only), Ruby 2.7+.

- **Get an API key:** sign in and open the **API** tab at
  [yourimageshare.com/my-account](https://yourimageshare.com/my-account).
- **Full HTTP reference:** [yourimageshare.com/about/api](https://yourimageshare.com/about/api)
  or [API.md in this repo](../API.md).
- Same API, same result shapes, in [JavaScript/TypeScript](https://www.npmjs.com/package/yourimageshare), [Python](https://pypi.org/project/yourimageshare/), [PHP](https://packagist.org/packages/yourimageshare/yourimageshare-php), and [Go](https://pkg.go.dev/github.com/yourimageshare/yourimageshare/go) too.

## Install

```bash
gem install yourimageshare
```

Or add to your Gemfile:

```ruby
gem "yourimageshare"
```

## Usage

```ruby
require "yourimageshare"

client = YourImageShare::Client.new("YOUR_API_KEY")

# Upload a file by path
result = client.upload("photo.jpg")
puts result.direct # https://yourimageshare.com/ib/aB3xY9qRz1

# Upload with auto-delete after 1 hour
client.upload("photo.jpg", expires_in: 3600)

# Upload from any IO-like object (a network stream, an in-memory buffer, ...)
# client.upload_io(io, "photo.jpg")

# List your uploads (paginated, 50 per page)
listing = client.list
listing.data.each { |item| puts "#{item.id} #{item.direct}" }

# Delete an upload
client.delete(result.id)
```

### Error handling

Unlike the Go SDK's `(result, error)` return style, the Ruby SDK follows
Ruby convention and raises `YourImageShare::APIError` (`.status` is the
HTTP status code, `.message` is the server's error text):

```ruby
begin
  client.upload("photo.jpg")
rescue YourImageShare::APIError => e
  puts "#{e.status}: #{e.message}"
end
```

## API

### `YourImageShare::Client.new(api_key, base_url: "https://yourimageshare.com/api", timeout: 30)`

`base_url` and `timeout` are keyword overrides, mainly for testing.

### `client.upload(file_path, expires_in: nil) -> UploadResult`

Streams the file from disk rather than buffering the whole thing in memory
first - uploads can be up to 200MB. `expires_in` is seconds, 60 to
2,592,000 (30 days); nil means a permanent upload. Returns an
`UploadResult` with `id`, `type`, `path`, `src`, `direct`, `expires_at`.

### `client.upload_io(io, filename, expires_in: nil) -> UploadResult`

Same as `upload`, but from any IO-like object instead of a file path.

### `client.list(page = nil) -> ListResult`

Returns a `ListResult` with `data` (an array of `ListedUpload` - `id`,
`type`, `title`, `path`, `src`, `direct`, `expires_at`, `created_at`) and
`meta` (`ListMeta` - `current_page`, `last_page`, `total`).

### `client.delete(id) -> nil`

Raises `APIError` on a 404/401.

## Rate limits

20 requests/minute and 500/day per key by default (2,000/day per IP as a
backstop). Not currently surfaced on the return values from this SDK - read
the `X-RateLimit-Limit`/`X-RateLimit-Remaining` response headers yourself if
you need them, or open an issue to request them on the result types.

## License

MIT

## Support

[yourimageshare.com/contact](https://yourimageshare.com/contact)
