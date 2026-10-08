from __future__ import annotations

import os
import secrets
from dataclasses import dataclass, fields
from typing import Any, BinaryIO, Callable, List, Optional, Union

import requests

DEFAULT_BASE_URL = "https://yourimageshare.com/api"
SDK_VERSION = "1.1.0"
# Files above this size are sent in pieces (one request can carry at most 100 MB).
CHUNK_THRESHOLD = 90 * 1024 * 1024
# Size of one piece of a chunked upload (the API accepts at most 5 MB).
CHUNK_SIZE = 5 * 1024 * 1024


def _build(cls, data: dict):
    """Dataclass from an API object, ignoring fields this SDK version doesn't know (the API may add more)."""
    names = {f.name for f in fields(cls)}
    return cls(**{k: v for k, v in data.items() if k in names})


class YourImageShareError(Exception):
    """Raised for any non-2xx response or a `{"type": "error"}` payload.

    `status` is the HTTP status code; `message` is the server's error text
    (falls back to a generic message if the server didn't send one).
    """

    def __init__(self, message: str, status: int):
        super().__init__(f"[{status}] {message}")
        self.message = message
        self.status = status


@dataclass
class UploadResult:
    id: str
    type: str
    #: Storage URL of the file as uploaded. It can change shortly afterwards when the file
    #: is converted (WebP/MP4) - store ``src``.
    path: str
    #: Permanent direct file URL - always opens the current file.
    src: str
    direct: str
    expires_at: Optional[str] = None
    #: 280 px wide WebP thumbnail (a video's first frame), or None.
    thumb: Optional[str] = None
    width: Optional[int] = None
    height: Optional[int] = None
    #: File size in bytes as stored.
    size: Optional[int] = None
    locked: bool = False
    #: "private" (file only), "unlisted" (page for anyone with the link) or "public" (listed).
    visibility: str = "unlisted"
    title: Optional[str] = None
    description: Optional[str] = None
    #: True if your account had already uploaded this exact file and that upload was returned.
    duplicate: bool = False
    #: New uploads only: a private link that deletes the upload without an API key. Shown once.
    delete_url: Optional[str] = None


@dataclass
class ListedUpload:
    id: str
    type: str
    title: Optional[str]
    path: str
    src: str
    direct: str
    expires_at: Optional[str]
    created_at: str
    thumb: Optional[str] = None
    width: Optional[int] = None
    height: Optional[int] = None
    size: Optional[int] = None
    locked: bool = False
    visibility: str = "unlisted"
    description: Optional[str] = None


@dataclass
class ListMeta:
    current_page: int
    last_page: int
    total: int


@dataclass
class ListResult:
    data: List[ListedUpload]
    meta: ListMeta


class YourImageShare:
    """Client for the YourImageShare upload API.

    Example:
        client = YourImageShare(api_key="...")
        result = client.upload("photo.jpg")
        print(result.direct)
    """

    def __init__(self, api_key: str, base_url: str = DEFAULT_BASE_URL, timeout: float = 30.0):
        if not api_key:
            raise ValueError("YourImageShare: `api_key` is required.")
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self._session = requests.Session()
        self._session.headers.update(
            {
                "X-API-Key": self.api_key,
                # requests' own default UA identifies itself fine, but a distinctive
                # one makes SDK traffic easy to pick out of server logs.
                "User-Agent": f"yourimageshare-py/{SDK_VERSION}",
            }
        )

    def _parse(self, response: requests.Response) -> dict:
        try:
            body = response.json()
        except ValueError as exc:
            raise YourImageShareError(
                f"Unexpected non-JSON response (HTTP {response.status_code})", response.status_code
            ) from exc
        if not response.ok or body.get("type") == "error":
            message = body.get("errors")
            if not isinstance(message, str):
                message = f"Request failed (HTTP {response.status_code})"
            raise YourImageShareError(message, response.status_code)
        return body

    def _post_upload(self, data: dict, files: Optional[dict] = None, timeout: Optional[float] = None) -> UploadResult:
        response = self._session.post(self.base_url, data=data, files=files, timeout=timeout or self.timeout)
        return _build(UploadResult, self._parse(response)["data"])

    @staticmethod
    def _fields(
        expires_in: Optional[int],
        allow_duplicate: bool,
        visibility: Optional[str] = None,
        title: Optional[str] = None,
        description: Optional[str] = None,
    ) -> dict:
        data: dict[str, Any] = {}
        if expires_in is not None:
            data["expires_in"] = str(expires_in)
        if allow_duplicate:
            data["allow_duplicate"] = "1"
        if visibility is not None:
            data["visibility"] = visibility
        if title is not None:
            data["title"] = title
        if description is not None:
            data["description"] = description
        return data

    def upload(
        self,
        file: Union[str, "os.PathLike[str]", BinaryIO],
        *,
        filename: Optional[str] = None,
        expires_in: Optional[int] = None,
        allow_duplicate: bool = False,
        visibility: Optional[str] = None,
        title: Optional[str] = None,
        description: Optional[str] = None,
        on_progress: Optional[Callable[[int, int], None]] = None,
    ) -> UploadResult:
        """Upload a file (up to 200 MB).

        `file` is a path (str or PathLike) or an already-open binary file object.
        `expires_in` is seconds (60 to 2,592,000 = 30 days) to auto-delete the
        upload later; omit for a permanent upload. If your account already
        uploaded this exact file, that upload is returned with
        ``duplicate=True`` - pass ``allow_duplicate=True`` to store a new copy.
        Files over 90 MB are sent in 5 MB pieces automatically;
        ``on_progress(sent, total)`` is called after each piece.

        ``visibility``: "unlisted" (server default - file and page work for
        anyone with the link, not listed), "private" (file only, no page for
        others) or "public" (listed and indexable). ``title`` up to 90
        characters, ``description`` up to 500. New uploads carry a one-time
        ``delete_url``.
        """
        data = self._fields(expires_in, allow_duplicate, visibility, title, description)

        opened = None
        try:
            if isinstance(file, (str, os.PathLike)):
                opened = open(file, "rb")
                fileobj: BinaryIO = opened
                name = filename or os.path.basename(os.fspath(file))
            else:
                fileobj = file
                name = filename or os.path.basename(getattr(file, "name", "upload") or "upload")

            size = _remaining_size(fileobj)
            if size is not None and size > CHUNK_THRESHOLD:
                return self._upload_chunked(fileobj, name, size, data, on_progress)
            return self._post_upload(data, files={"uploads": (name, fileobj)})
        finally:
            if opened is not None:
                opened.close()

    def upload_from_url(
        self,
        url: str,
        *,
        expires_in: Optional[int] = None,
        allow_duplicate: bool = False,
        visibility: Optional[str] = None,
        title: Optional[str] = None,
        description: Optional[str] = None,
    ) -> UploadResult:
        """Upload from a public http(s) link: the server downloads the file itself (up to 200 MB)."""
        data = self._fields(expires_in, allow_duplicate, visibility, title, description)
        data["url"] = url
        return self._post_upload(data, timeout=max(self.timeout, 180))

    def _upload_chunked(self, fileobj: BinaryIO, name: str, size: int, data: dict, on_progress) -> UploadResult:
        upload_id = secrets.token_hex(16)
        total = -(-size // CHUNK_SIZE)
        sent = 0
        for index in range(total):
            piece = fileobj.read(CHUNK_SIZE)
            for attempt in range(1, 4):
                try:
                    response = self._session.post(
                        f"{self.base_url}/chunk",
                        data={"upload_id": upload_id, "index": str(index), "total": str(total)},
                        files={"chunk": ("piece", piece)},
                        timeout=self.timeout,
                    )
                    self._parse(response)
                    break
                except (requests.RequestException, YourImageShareError) as exc:
                    # network errors and 5xx are retried; anything the server refused (4xx) is final
                    status = getattr(exc, "status", 0)
                    if attempt == 3 or 400 <= status < 500:
                        raise
            sent += len(piece)
            if on_progress:
                on_progress(sent, size)
        return self._post_upload({**data, "upload_id": upload_id, "filename": name}, timeout=max(self.timeout, 180))

    def list(self, page: int = 1) -> ListResult:
        """List your uploads, newest first. Paginated 50 per page."""
        params = {"page": page} if page > 1 else {}
        response = self._session.get(self.base_url, params=params, timeout=self.timeout)
        body = self._parse(response)
        uploads = [_build(ListedUpload, item) for item in body["data"]]
        meta = _build(ListMeta, body["meta"])
        return ListResult(data=uploads, meta=meta)

    def get(self, upload_id: str) -> ListedUpload:
        """One of your uploads (needs the full API key)."""
        response = self._session.get(f"{self.base_url}/{upload_id}", timeout=self.timeout)
        return _build(ListedUpload, self._parse(response)["data"])

    def update(
        self,
        upload_id: str,
        *,
        visibility: Optional[str] = None,
        title: Optional[str] = None,
        description: Optional[str] = None,
    ) -> ListedUpload:
        """Change the visibility, title or description of one of your uploads (needs the full API key).

        Only the arguments you pass are changed; an empty string clears a title/description.
        """
        changes = {k: v for k, v in (("visibility", visibility), ("title", title), ("description", description)) if v is not None}
        response = self._session.patch(f"{self.base_url}/{upload_id}", json=changes, timeout=self.timeout)
        return _build(ListedUpload, self._parse(response)["data"])

    def delete(self, upload_id: str) -> None:
        """Delete one of your uploads by id."""
        response = self._session.delete(f"{self.base_url}/{upload_id}", timeout=self.timeout)
        self._parse(response)

    def close(self) -> None:
        self._session.close()

    def __enter__(self) -> "YourImageShare":
        return self

    def __exit__(self, *exc_info: object) -> None:
        self.close()


def _remaining_size(fileobj: BinaryIO) -> Optional[int]:
    """Bytes left to read in a seekable file object, or None if it can't be measured."""
    try:
        position = fileobj.tell()
        end = fileobj.seek(0, os.SEEK_END)
        fileobj.seek(position)
        return end - position
    except (AttributeError, OSError, ValueError):
        return None
