# yourimageshare-api/go

[![Go Reference](https://pkg.go.dev/badge/github.com/yourimageshare/yourimageshare/go.svg)](https://pkg.go.dev/github.com/yourimageshare/yourimageshare/go)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official Go SDK for the [YourImageShare](https://yourimageshare.com)
upload API. Zero third-party dependencies (standard library only), Go 1.18+.

- **Get an API key:** sign in and open the **API** tab at
  [yourimageshare.com/my-account](https://yourimageshare.com/my-account).
- **Full HTTP reference:** [yourimageshare.com/about/api](https://yourimageshare.com/about/api)
  or [API.md in this repo](../API.md).
- Same API, same result shapes, in [JavaScript/TypeScript](https://www.npmjs.com/package/yourimageshare), [Python](https://pypi.org/project/yourimageshare/), and [PHP](https://packagist.org/packages/yourimageshare/yourimageshare-php) too.

## Install

```bash
go get github.com/yourimageshare/yourimageshare/go
```

## Usage

```go
package main

import (
	"fmt"

	yourimageshare "github.com/yourimageshare/yourimageshare/go"
)

func main() {
	client, err := yourimageshare.NewClient("YOUR_API_KEY")
	if err != nil {
		panic(err)
	}

	// Upload a file by path
	result, err := client.Upload("photo.jpg", nil)
	if err != nil {
		panic(err)
	}
	fmt.Println(result.Direct) // https://yourimageshare.com/ib/aB3xY9qRz1

	// Upload with auto-delete after 1 hour
	client.Upload("photo.jpg", &yourimageshare.UploadOptions{ExpiresIn: 3600})

	// Upload from any io.Reader (a network stream, an in-memory buffer, ...)
	// client.UploadReader(r, "photo.jpg", nil)

	// List your uploads (paginated, 50 per page)
	listing, err := client.List(1)
	if err != nil {
		panic(err)
	}
	for _, item := range listing.Data {
		fmt.Println(item.ID, item.Direct)
	}

	// Delete an upload
	client.Delete(result.ID)
}
```

### Error handling

Failed requests return a `*yourimageshare.APIError` (`.Status` is the
HTTP status code, `.Message` is the server's error text):

```go
result, err := client.Upload("photo.jpg", nil)
if err != nil {
	var apiErr *yourimageshare.APIError
	if errors.As(err, &apiErr) {
		fmt.Println(apiErr.Status, apiErr.Message)
	}
}
```

## API

### `yourimageshare.NewClient(apiKey string, opts ...Option) (*Client, error)`

`WithBaseURL(url string)` overrides the API base URL (mainly for testing).
`WithHTTPClient(*http.Client)` overrides the HTTP client, e.g. for a custom
timeout or transport. Defaults to a 30s timeout.

### `client.Upload(filePath string, opts *UploadOptions) (*UploadResult, error)`

Streams the file from disk (doesn't buffer the whole thing in memory -
uploads can be up to 200MB). `opts.ExpiresIn` is seconds, 60 to 2,592,000
(30 days); nil or zero means a permanent upload. Returns an `UploadResult`
with `ID`, `Type`, `Path`, `Src`, `Direct`, `ExpiresAt`.

### `client.UploadReader(r io.Reader, filename string, opts *UploadOptions) (*UploadResult, error)`

Same as `Upload`, but from any `io.Reader` instead of a file path.

### `client.List(page int) (*ListResult, error)`

Returns a `ListResult` with `Data` (a slice of `ListedUpload` - `ID`,
`Type`, `Title`, `Path`, `Src`, `Direct`, `ExpiresAt`, `CreatedAt`) and
`Meta` (`ListMeta` - `CurrentPage`, `LastPage`, `Total`).

### `client.Delete(id string) error`

Returns a `*APIError` on a 404/401; `nil` on success.

## Rate limits

20 requests/minute and 500/day per key by default (2,000/day per IP as a
backstop). Not currently surfaced on the return values from this SDK - read
the `X-RateLimit-Limit`/`X-RateLimit-Remaining` response headers yourself if
you need them, or open an issue to request them on the result types.

## License

MIT

## Support

[yourimageshare.com/contact](https://yourimageshare.com/contact)
