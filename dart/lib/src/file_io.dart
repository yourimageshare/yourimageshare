import 'dart:io';
import 'dart:typed_data';

/// Size of the file at [path] in bytes.
Future<int> fileLength(String path) => File(path).length();

/// Bytes [start] (inclusive) to [end] (exclusive) of the file at [path].
Future<List<int>> readRange(String path, int start, int end) async {
  final builder = BytesBuilder(copy: false);
  await for (final chunk in File(path).openRead(start, end)) {
    builder.add(chunk);
  }
  return builder.takeBytes();
}
