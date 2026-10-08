# yourimageshare

[![PyPI version](https://img.shields.io/pypi/v/yourimageshare.svg)](https://pypi.org/project/yourimageshare/)
[![PyPI downloads](https://img.shields.io/pypi/dm/yourimageshare.svg)](https://pypi.org/project/yourimageshare/)
[![license](https://img.shields.io/pypi/l/yourimageshare.svg)](https://github.com/yourimageshare/yourimageshare/blob/main/python/LICENSE)

Official Python SDK for the [YourImageShare](https://yourimageshare.com)
upload API. One dependency (`requests`), Python 3.8+.

- **Get an API key:** sign in and open the **API** tab at
  [yourimageshare.com/my-account](https://yourimageshare.com/my-account).
- **Full HTTP reference:** [yourimageshare.com/about/api](https://yourimageshare.com/about/api)
  or [API.md in the yourimageshare-api repo](https://github.com/yourimageshare/yourimageshare/blob/main/API.md).

## Install

```bash
pip install yourimageshare
```

## Usage

```python
from yourimageshare import YourImageShare

client = YourImageShare(api_key="YOUR_API_KEY")

# Upload a file by path
result = client.upload("photo.jpg")
print(result.direct)  # https://yourimageshare.com/ib/aB3xY9qRz1

# Upload with auto-delete after 1 hour
client.upload("photo.jpg", expires_in=3600)

# Upload from an already-open file object
with open("photo.jpg", "rb") as f:
    client.upload(f, filename="photo.jpg")

# List your uploads (paginated, 50 per page)
listing = client.list()
for item in listing.data:
    print(item.id, item.direct)

# Delete an upload
client.delete(result.id)
```

`YourImageShare` also works as a context manager, which closes the
underlying HTTP session on exit:

```python
with YourImageShare(api_key="YOUR_API_KEY") as client:
    client.upload("photo.jpg")
```

### Large files, duplicates, links and thumbnails

Files up to 200 MB are supported. Anything over 90 MB is sent in 5 MB pieces automatically (one request can carry at most 100 MB). If your account already uploaded the exact same file, the existing upload is returned with `duplicate` set - opt out with the allow-duplicate option. Results also carry `thumb` (a 280 px WebP thumbnail), `width`, `height`, `size` and `locked`. Store `src`, not `path`: `path` can change shortly after upload when the file is converted (WebP/MP4).

```python
result = client.upload("video.mp4", on_progress=lambda sent, total: print(f"{sent * 100 // total}%"))
print(result.src, result.thumb, result.width, result.height, result.duplicate)

# a fresh copy even if this exact file is already on your account
client.upload("photo.jpg", allow_duplicate=True)

# let the server download a public link (up to 200 MB)
client.upload_from_url("https://example.com/photo.jpg")
```

### Error handling

Failed requests raise `YourImageShareError` (`.status` is the HTTP status
code, `.message` is the server's error text):

```python
from yourimageshare import YourImageShare, YourImageShareError

client = YourImageShare(api_key="YOUR_API_KEY")
try:
    client.upload("photo.jpg")
except YourImageShareError as err:
    print(err.status, err.message)
```

## API

### `YourImageShare(api_key, base_url=DEFAULT_BASE_URL, timeout=30.0)`

`base_url` defaults to `https://yourimageshare.com/api` - override only for
testing against a different environment.

### `client.upload(file, *, filename=None, expires_in=None)`

`file` is a path (`str`/`os.PathLike`) or an open binary file object.
`expires_in` is seconds, 60 to 2,592,000 (30 days) - omit for a permanent
upload. Returns an `UploadResult(id, type, path, src, direct, expires_at, thumb, width, height, size, locked, duplicate)`.

### `client.list(page=1)`

Returns a `ListResult(data, meta)` where `data` is a list of
`ListedUpload(id, type, title, path, src, direct, expires_at, created_at)`
and `meta` is `ListMeta(current_page, last_page, total)`.

### `client.delete(upload_id)`

Raises `YourImageShareError` on a 404/401; returns `None` on success.

## Rate limits

20 requests/minute and 500/day per key by default (2,000/day per IP as a
backstop). Not currently surfaced on the return values from this SDK - read
the `X-RateLimit-Limit`/`X-RateLimit-Remaining` response headers yourself via
`client._session` if you need them, or open an issue to request them on the
result objects.

## License

MIT

## Support

[yourimageshare.com/contact](https://yourimageshare.com/contact)
