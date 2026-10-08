import 'dart:convert';
import 'dart:math';

import 'package:http/http.dart' as http;

import 'errors.dart';
import 'file_io_stub.dart' if (dart.library.io) 'file_io.dart';
import 'types.dart';

const _defaultBaseUrl = 'https://yourimageshare.com/api';
const _sdkVersion = '1.1.0';

/// Files above this size are sent in pieces (one request can carry at most 100 MB).
const _chunkThreshold = 90 * 1024 * 1024;

/// Size of one piece of a chunked upload (the API accepts at most 5 MB).
const _chunkSize = 5 * 1024 * 1024;

/// Called after each piece of a chunked upload with the bytes sent so far and the total.
typedef ProgressCallback = void Function(int sent, int total);

/// Official Dart/Flutter client for the YourImageShare upload API
/// (https://yourimageshare.com/about/api). Mirrors the existing JS, Python,
/// PHP, Go, Rust, and Ruby SDKs - same method names, same result shapes,
/// same error type - just idiomatic Dart on top (throws
/// [YourImageShareException] instead of returning an error value).
class YourImageShareClient {
  final String _apiKey;
  final String _baseUrl;
  final http.Client _httpClient;

  /// [apiKey] is required - get one from the API tab at
  /// https://yourimageshare.com/my-account. [baseUrl] overrides the API
  /// base URL (mainly for testing). [httpClient] overrides the underlying
  /// `http.Client`, e.g. to inject a custom timeout or a mock for tests.
  YourImageShareClient(
    String apiKey, {
    String baseUrl = _defaultBaseUrl,
    http.Client? httpClient,
  })  : _apiKey = apiKey,
        _baseUrl = baseUrl,
        _httpClient = httpClient ?? http.Client() {
    if (_apiKey.isEmpty) {
      throw ArgumentError('yourimageshare: apiKey is required');
    }
  }

  /// Uploads a local file by path (up to 200 MB). Streams from disk via
  /// `http.MultipartFile.fromPath` - doesn't buffer the whole file in
  /// memory first; files over 90 MB are sent in 5 MB pieces automatically.
  /// [expiresIn] auto-deletes the upload after this many seconds (60 to
  /// 2,592,000 = 30 days); omit for a permanent upload. If your account
  /// already uploaded this exact file, that upload is returned with
  /// `duplicate == true` - pass [allowDuplicate] to store a new copy.
  Future<UploadResult> upload(
    String filePath, {
    int? expiresIn,
    bool allowDuplicate = false,
    String? visibility,
    String? title,
    String? description,
    ProgressCallback? onProgress,
  }) async {
    final size = await fileLength(filePath);
    final name = filePath.split(RegExp(r'[/\\]')).last;
    if (size > _chunkThreshold) {
      final uploadId = await _sendChunks(
          size, (start, end) => readRange(filePath, start, end), onProgress);
      return _finish(uploadId, name, expiresIn, allowDuplicate, visibility,
          title, description);
    }
    final request = http.MultipartRequest('POST', Uri.parse(_baseUrl));
    request.files.add(await http.MultipartFile.fromPath('uploads', filePath));
    _addOptions(
        request, expiresIn, allowDuplicate, visibility, title, description);
    return _sendUpload(request);
  }

  /// Uploads from raw bytes - useful when the data isn't already a file on
  /// disk (e.g. a network response, an in-memory buffer). [filename]
  /// should include a real extension so the server can infer the content
  /// type correctly. Over 90 MB the bytes are sent in 5 MB pieces.
  Future<UploadResult> uploadBytes(
    List<int> bytes,
    String filename, {
    int? expiresIn,
    bool allowDuplicate = false,
    String? visibility,
    String? title,
    String? description,
    ProgressCallback? onProgress,
  }) async {
    if (bytes.length > _chunkThreshold) {
      final uploadId = await _sendChunks(bytes.length,
          (start, end) async => bytes.sublist(start, end), onProgress);
      return _finish(uploadId, filename, expiresIn, allowDuplicate, visibility,
          title, description);
    }
    final request = http.MultipartRequest('POST', Uri.parse(_baseUrl));
    request.files.add(
      http.MultipartFile.fromBytes('uploads', bytes, filename: filename),
    );
    _addOptions(
        request, expiresIn, allowDuplicate, visibility, title, description);
    return _sendUpload(request);
  }

  /// Uploads from a public http(s) link: the server downloads the file
  /// itself (up to 200 MB).
  Future<UploadResult> uploadUrl(
    String url, {
    int? expiresIn,
    bool allowDuplicate = false,
    String? visibility,
    String? title,
    String? description,
  }) async {
    final request = http.MultipartRequest('POST', Uri.parse(_baseUrl));
    request.fields['url'] = url;
    _addOptions(
        request, expiresIn, allowDuplicate, visibility, title, description);
    return _sendUpload(request);
  }

  void _addOptions(
      http.MultipartRequest request,
      int? expiresIn,
      bool allowDuplicate,
      String? visibility,
      String? title,
      String? description) {
    if (expiresIn != null && expiresIn > 0) {
      request.fields['expires_in'] = expiresIn.toString();
    }
    if (allowDuplicate) {
      request.fields['allow_duplicate'] = '1';
    }
    if (visibility != null) request.fields['visibility'] = visibility;
    if (title != null) request.fields['title'] = title;
    if (description != null) request.fields['description'] = description;
  }

  Future<UploadResult> _finish(
      String uploadId,
      String filename,
      int? expiresIn,
      bool allowDuplicate,
      String? visibility,
      String? title,
      String? description) {
    final request = http.MultipartRequest('POST', Uri.parse(_baseUrl));
    request.fields['upload_id'] = uploadId;
    request.fields['filename'] = filename;
    _addOptions(
        request, expiresIn, allowDuplicate, visibility, title, description);
    return _sendUpload(request);
  }

  Future<String> _sendChunks(
    int size,
    Future<List<int>> Function(int start, int end) read,
    ProgressCallback? onProgress,
  ) async {
    final random = Random.secure();
    final uploadId = List.generate(
            16, (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0'))
        .join();
    final total = (size + _chunkSize - 1) ~/ _chunkSize;
    final chunkUri = Uri.parse(
        '${_baseUrl.endsWith('/') ? _baseUrl.substring(0, _baseUrl.length - 1) : _baseUrl}/chunk');

    for (var index = 0; index < total; index++) {
      final start = index * _chunkSize;
      final end = min(start + _chunkSize, size);
      final piece = await read(start, end);
      for (var attempt = 1;; attempt++) {
        final request = http.MultipartRequest('POST', chunkUri)
          ..fields['upload_id'] = uploadId
          ..fields['index'] = index.toString()
          ..fields['total'] = total.toString()
          ..files.add(
              http.MultipartFile.fromBytes('chunk', piece, filename: 'piece'));
        _setCommonHeaders(request);
        try {
          final response =
              await http.Response.fromStream(await _httpClient.send(request));
          _decodeOrThrow(response);
          break;
        } catch (e) {
          // network errors and 5xx are retried; anything the server refused (4xx) is final
          final status = e is YourImageShareException ? e.status : 0;
          if (attempt >= 3 || (status >= 400 && status < 500)) rethrow;
        }
      }
      onProgress?.call(end, size);
    }
    return uploadId;
  }

  Future<UploadResult> _sendUpload(http.MultipartRequest request) async {
    _setCommonHeaders(request);
    final streamed = await _httpClient.send(request);
    final response = await http.Response.fromStream(streamed);
    final raw = _decodeOrThrow(response);
    return UploadResult.fromJson(raw['data'] as Map<String, dynamic>);
  }

  /// Returns your uploads, newest first, 50 per page. [page] < 2 fetches
  /// the first page.
  Future<ListResult> list({int page = 1}) async {
    var uri = Uri.parse(_baseUrl);
    if (page > 1) {
      uri = uri.replace(queryParameters: {'page': page.toString()});
    }
    final request = http.Request('GET', uri);
    _setCommonHeaders(request);
    final streamed = await _httpClient.send(request);
    final response = await http.Response.fromStream(streamed);
    final raw = _decodeOrThrow(response);
    return ListResult.fromJson(raw);
  }

  /// One of your uploads (needs the full API key).
  Future<ListedUpload> get(String id) async {
    final request = http.Request(
        'GET', Uri.parse('${_trimmedBase()}/${Uri.encodeComponent(id)}'));
    _setCommonHeaders(request);
    final response =
        await http.Response.fromStream(await _httpClient.send(request));
    return ListedUpload.fromJson(
        _decodeOrThrow(response)['data'] as Map<String, dynamic>);
  }

  /// Changes the [visibility] (`private`, `unlisted`, `public`), [title] or
  /// [description] of one of your uploads (needs the full API key). Only
  /// the arguments you pass change; an empty string clears a
  /// title/description.
  Future<ListedUpload> update(String id,
      {String? visibility, String? title, String? description}) async {
    final request = http.Request(
        'PATCH', Uri.parse('${_trimmedBase()}/${Uri.encodeComponent(id)}'))
      ..headers['Content-Type'] = 'application/json'
      ..body = jsonEncode({
        if (visibility != null) 'visibility': visibility,
        if (title != null) 'title': title,
        if (description != null) 'description': description,
      });
    _setCommonHeaders(request);
    final response =
        await http.Response.fromStream(await _httpClient.send(request));
    return ListedUpload.fromJson(
        _decodeOrThrow(response)['data'] as Map<String, dynamic>);
  }

  String _trimmedBase() => _baseUrl.endsWith('/')
      ? _baseUrl.substring(0, _baseUrl.length - 1)
      : _baseUrl;

  /// Removes one of your uploads by id. Throws a
  /// [YourImageShareException] on a 404/401.
  Future<void> delete(String id) async {
    final uri = Uri.parse('$_baseUrl/${Uri.encodeComponent(id)}');
    final request = http.Request('DELETE', uri);
    _setCommonHeaders(request);
    final streamed = await _httpClient.send(request);
    final response = await http.Response.fromStream(streamed);
    _decodeOrThrow(response);
  }

  void _setCommonHeaders(http.BaseRequest request) {
    request.headers['X-API-Key'] = _apiKey;
    request.headers['User-Agent'] = 'yourimageshare-dart/$_sdkVersion';
  }

  /// Decodes the `{"type": "success"|"error", ...}` envelope, throwing
  /// [YourImageShareException] for any non-2xx response or a
  /// `type == "error"` payload - mirrors the Go SDK's `do()`.
  Map<String, dynamic> _decodeOrThrow(http.Response response) {
    Map<String, dynamic> envelope;
    try {
      envelope = jsonDecode(response.body) as Map<String, dynamic>;
    } on FormatException {
      throw YourImageShareException(
        response.statusCode,
        'unexpected non-JSON response (HTTP ${response.statusCode})',
      );
    }

    final isError = response.statusCode < 200 ||
        response.statusCode >= 300 ||
        envelope['type'] == 'error';
    if (isError) {
      final errors = envelope['errors'] as String?;
      final message = (errors == null || errors.isEmpty)
          ? 'request failed (HTTP ${response.statusCode})'
          : errors;
      throw YourImageShareException(response.statusCode, message);
    }

    return envelope;
  }
}
