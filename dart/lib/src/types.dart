/// The response shape for a successful upload - same fields as the
/// JS/Python/PHP/Go/Rust/Ruby SDKs' upload result.
class UploadResult {
  final String id;
  final String type;

  /// Storage URL of the file as uploaded. It can change shortly afterwards
  /// when the file is converted (WebP/MP4) - store [src].
  final String path;

  /// Permanent direct file URL - always opens the current file.
  final String src;
  final String direct;

  /// 280 px wide WebP thumbnail (a video's first frame), or null.
  final String? thumb;
  final int? width;
  final int? height;

  /// File size in bytes as stored.
  final int? size;

  /// True if the upload is password-protected.
  final bool locked;

  /// `private` (file only), `unlisted` (page for anyone with the link) or
  /// `public` (listed).
  final String visibility;
  final String? title;
  final String? description;
  final String? expiresAt;

  /// True if your account had already uploaded this exact file and that
  /// upload was returned instead of a new one.
  final bool duplicate;

  /// New uploads only: a private link that deletes the upload without an
  /// API key. Shown once.
  final String? deleteUrl;

  UploadResult({
    required this.id,
    required this.type,
    required this.path,
    required this.src,
    required this.direct,
    this.thumb,
    this.width,
    this.height,
    this.size,
    this.locked = false,
    this.visibility = 'unlisted',
    this.title,
    this.description,
    this.expiresAt,
    this.duplicate = false,
    this.deleteUrl,
  });

  factory UploadResult.fromJson(Map<String, dynamic> json) => UploadResult(
        id: json['id'] as String,
        type: json['type'] as String,
        path: json['path'] as String,
        src: json['src'] as String,
        direct: json['direct'] as String,
        thumb: json['thumb'] as String?,
        width: json['width'] as int?,
        height: json['height'] as int?,
        size: json['size'] as int?,
        locked: json['locked'] == true,
        visibility: (json['visibility'] as String?) ?? 'unlisted',
        title: json['title'] as String?,
        description: json['description'] as String?,
        expiresAt: json['expires_at'] as String?,
        duplicate: json['duplicate'] == true,
        deleteUrl: json['delete_url'] as String?,
      );
}

/// One row of a [YourImageShareClient.list] result.
class ListedUpload {
  final String id;
  final String type;
  final String? title;
  final String path;
  final String src;
  final String direct;
  final String? thumb;
  final int? width;
  final int? height;
  final int? size;
  final bool locked;
  final String visibility;
  final String? description;
  final String? expiresAt;
  final String createdAt;

  ListedUpload({
    required this.id,
    required this.type,
    this.title,
    required this.path,
    required this.src,
    required this.direct,
    this.thumb,
    this.width,
    this.height,
    this.size,
    this.locked = false,
    this.visibility = 'unlisted',
    this.description,
    this.expiresAt,
    required this.createdAt,
  });

  factory ListedUpload.fromJson(Map<String, dynamic> json) => ListedUpload(
        id: json['id'] as String,
        type: json['type'] as String,
        title: json['title'] as String?,
        path: json['path'] as String,
        src: json['src'] as String,
        direct: json['direct'] as String,
        thumb: json['thumb'] as String?,
        width: json['width'] as int?,
        height: json['height'] as int?,
        size: json['size'] as int?,
        locked: json['locked'] == true,
        visibility: (json['visibility'] as String?) ?? 'unlisted',
        description: json['description'] as String?,
        expiresAt: json['expires_at'] as String?,
        createdAt: json['created_at'] as String,
      );
}

/// Pagination info for a [YourImageShareClient.list] result.
class ListMeta {
  final int currentPage;
  final int lastPage;
  final int total;

  ListMeta({
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  factory ListMeta.fromJson(Map<String, dynamic> json) => ListMeta(
        currentPage: json['current_page'] as int,
        lastPage: json['last_page'] as int,
        total: json['total'] as int,
      );
}

/// The response shape for [YourImageShareClient.list].
class ListResult {
  final List<ListedUpload> data;
  final ListMeta meta;

  ListResult({required this.data, required this.meta});

  factory ListResult.fromJson(Map<String, dynamic> json) => ListResult(
        data: (json['data'] as List)
            .map((e) => ListedUpload.fromJson(e as Map<String, dynamic>))
            .toList(),
        meta: ListMeta.fromJson(json['meta'] as Map<String, dynamic>),
      );
}
