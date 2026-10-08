# yourimageshare-api/dotnet

[![NuGet](https://img.shields.io/nuget/v/YourImageShare.svg)](https://www.nuget.org/packages/YourImageShare)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official .NET SDK for the [YourImageShare](https://yourimageshare.com)
upload API. Zero third-party dependencies beyond `System.Text.Json`
(a first-party Microsoft package, needed since it isn't part of the BCL on
`netstandard2.0`), targets `netstandard2.0` (works from .NET Framework 4.6.1+
and every modern .NET/.NET Core version).

- **Get an API key:** sign in and open the **API** tab at
  [yourimageshare.com/my-account](https://yourimageshare.com/my-account).
- **Full HTTP reference:** [yourimageshare.com/about/api](https://yourimageshare.com/about/api)
  or [API.md in this repo](../API.md).
- Same API, same result shapes, in [JavaScript/TypeScript](https://www.npmjs.com/package/yourimageshare), [Python](https://pypi.org/project/yourimageshare/), [PHP](https://packagist.org/packages/yourimageshare/yourimageshare-php), and [Go](https://pkg.go.dev/github.com/yourimageshare/yourimageshare/go) too.

## Install

```bash
dotnet add package YourImageShare
```

## Usage

```csharp
using YourImageShare;

var client = new YourImageShareClient("YOUR_API_KEY");

// Upload a file by path
var result = await client.UploadAsync("photo.jpg");
Console.WriteLine(result.Direct); // https://yourimageshare.com/ib/aB3xY9qRz1

// Upload with auto-delete after 1 hour
await client.UploadAsync("photo.jpg", new UploadOptions { ExpiresIn = 3600 });

// Upload from any Stream (a network stream, an in-memory buffer, ...)
// await client.UploadAsync(stream, "photo.jpg");

// List your uploads (paginated, 50 per page)
var listing = await client.ListAsync(page: 1);
foreach (var item in listing.Data)
{
    Console.WriteLine($"{item.Id} {item.Direct}");
}

// Delete an upload
await client.DeleteAsync(result.Id);
```

### Error handling

.NET SDK throws instead of returning an error value, unlike the
synchronous Go SDK - failed requests throw a `YourImageShareException`
(`.Status` is the HTTP status code, `.ApiMessage` is the server's error text):

```csharp
try
{
    var result = await client.UploadAsync("photo.jpg");
}
catch (YourImageShareException ex)
{
    Console.WriteLine($"{ex.Status}: {ex.ApiMessage}");
}
```

## API

This SDK is async-only (idiomatic for .NET HTTP work) - there are no
synchronous method overloads.

### `new YourImageShareClient(string apiKey, string baseUrl = YourImageShareClient.DefaultBaseUrl, HttpClient? httpClient = null)`

`baseUrl` overrides the API base URL (mainly for testing). `httpClient`
overrides the `HttpClient` used for requests, e.g. for a custom timeout or
handler - defaults to a client with a 30s timeout.

### `Task<UploadResult> UploadAsync(string filePath, UploadOptions? options = null, CancellationToken ct = default)`

Streams the file from disk (doesn't buffer the whole thing in memory -
uploads can be up to 200MB). `options.ExpiresIn` is seconds, 60 to
2,592,000 (30 days); null or zero means a permanent upload. Returns an
`UploadResult` with `Id`, `Type`, `Path`, `Src`, `Direct`, `ExpiresAt`.

### `Task<UploadResult> UploadAsync(Stream stream, string filename, UploadOptions? options = null, CancellationToken ct = default)`

Same as the path overload, but from any `Stream` instead of a file path.

### `Task<ListResult> ListAsync(int page = 1, CancellationToken ct = default)`

Returns a `ListResult` with `Data` (a `List<ListedUpload>` - `Id`,
`Type`, `Title`, `Path`, `Src`, `Direct`, `ExpiresAt`, `CreatedAt`) and
`Meta` (`ListMeta` - `CurrentPage`, `LastPage`, `Total`).

### `Task DeleteAsync(string id, CancellationToken ct = default)`

Throws `YourImageShareException` on a 404/401; completes normally on
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
