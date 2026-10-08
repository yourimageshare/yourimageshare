/// Platforms without `dart:io` (the web): file paths can't be read, so
/// uploads go through [YourImageShareClient.uploadBytes] instead.
Future<int> fileLength(String path) => throw UnsupportedError(
    'Uploading by file path needs dart:io; use uploadBytes on the web.');

Future<List<int>> readRange(String path, int start, int end) =>
    throw UnsupportedError(
        'Uploading by file path needs dart:io; use uploadBytes on the web.');
