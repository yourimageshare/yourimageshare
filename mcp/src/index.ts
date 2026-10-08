#!/usr/bin/env node
import { readFile } from 'node:fs/promises';
import { basename } from 'node:path';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { DEFAULT_BASE_URL, YourImageShareApiError, YourImageShareClient } from './client.js';

const apiKey = process.env.YIS_API_KEY;
if (!apiKey) {
  // Deliberately not fatal: tool schemas (name, description, input/output
  // shape) must stay introspectable without credentials - clients and
  // registry scanners call tools/list before any key is available. Actual
  // calls fail cleanly through the normal API-error path in client.ts
  // instead (a 401 from the real API), not a process crash.
  console.error('yourimageshare-mcp: YIS_API_KEY environment variable is not set.');
  console.error('Tool calls will fail until it is. Get a key from the "API" tab at https://yourimageshare.com/my-account');
}

const client = new YourImageShareClient(apiKey ?? '', process.env.YIS_BASE_URL ?? DEFAULT_BASE_URL);

const server = new McpServer({
  name: 'yourimageshare',
  title: 'YourImageShare',
  version: '1.1.0',
  description:
    'Upload, list, and delete images and videos on YourImageShare (free hosting, 200MB limit, no account required for end viewers) and get back a shareable link.',
  websiteUrl: 'https://yourimageshare.com',
});

/** Only the fields declared in the output schemas: MCP clients reject structured content with fields the
 *  schema doesn't list, so a field the API adds later must not reach them unannounced. */
const LINK_FIELDS = ['id', 'type', 'path', 'src', 'direct', 'thumb', 'width', 'height', 'size', 'locked', 'expires_at'] as const;
function pick(item: Record<string, unknown>, extra: string[]) {
  const out: Record<string, unknown> = {};
  for (const key of [...LINK_FIELDS, ...extra]) {
    if (item[key] !== undefined) out[key] = item[key];
  }
  return out;
}

function errorResult(err: unknown) {
  const message = err instanceof YourImageShareApiError ? `${err.message} (HTTP ${err.status})` : String(err);
  return { content: [{ type: 'text' as const, text: `Error: ${message}` }], isError: true };
}

// Shared field descriptions for the link object returned by upload_image and
// present in each list_uploads row - kept as one definition so the two tools
// stay consistent instead of drifting.
const uploadFields = {
  id: z.string().describe('Unique identifier for this upload. Pass to delete_upload to remove it.'),
  type: z.enum(['image', 'video']),
  path: z
    .string()
    .describe('Storage URL of the file as uploaded. It can change shortly afterwards when the file is converted (WebP/MP4) - store `src` instead.'),
  src: z.string().describe('Permanent direct/embeddable file URL - use this for <img>/<video> src attributes and when saving the link.'),
  direct: z
    .string()
    .describe(
      'The shareable page URL (title, description, comments, share buttons). Despite the field name, this is NOT a direct file link - use `src` for that.',
    ),
  thumb: z.string().nullable().optional().describe('280px wide WebP thumbnail (for a video, its first frame), or null.'),
  width: z.number().nullable().optional().describe('Width in pixels, or null if unknown.'),
  height: z.number().nullable().optional().describe('Height in pixels, or null if unknown.'),
  size: z.number().nullable().optional().describe('File size in bytes as stored.'),
  locked: z.boolean().optional().describe('True if the upload is password-protected.'),
  expires_at: z.string().nullable().describe('ISO 8601 auto-delete timestamp, or null if the upload never expires.'),
};

server.registerTool(
  'upload_image',
  {
    title: 'Upload an image or video',
    description:
      'Upload an image or video to YourImageShare and get back a shareable link. Accepts JPG, PNG, GIF, WEBP, ' +
      'AVIF, BMP, TIFF, HEIC/HEIF images and MP4, WEBM, AVI, MOV, M4V, MKV, MPEG, WMV, FLV, 3GP video, up to 200MB ' +
      '(large files are sent in pieces automatically). Provide exactly one of: `path` for a file on disk this ' +
      'server can read, `base64` + `filename` for in-memory content, or `url` for a public link the server ' +
      'downloads itself. Uploading a file your account already uploaded returns that upload with `duplicate: true`. Returns three different URLs for the same upload (see output fields): a raw ' +
      "storage link, a direct embeddable link, and a shareable page link - pick whichever fits where it's " +
      'going. Errors (oversized/unsupported file, bad API key, rate limit) come back as a normal tool error, ' +
      'not a thrown exception.',
    inputSchema: {
      path: z.string().optional().describe('Local file path to upload.'),
      base64: z.string().optional().describe('Base64-encoded file contents. Requires `filename`.'),
      filename: z.string().optional().describe('Filename to use. Required with `base64`; inferred from `path` otherwise.'),
      url: z.string().url().optional().describe('Public http(s) link to an image or video; the server downloads it (up to 200MB).'),
      allowDuplicate: z
        .boolean()
        .optional()
        .describe('Store a new copy even if this account already uploaded the exact same file.'),
      expiresIn: z
        .number()
        .int()
        .min(60)
        .max(2592000)
        .optional()
        .describe('Auto-delete after this many seconds (60 to 2,592,000 = 30 days). Omit for a permanent upload.'),
    },
    outputSchema: {
      ...uploadFields,
      duplicate: z.boolean().optional().describe('True if this exact file was already on your account and that upload was returned.'),
    },
    annotations: {
      title: 'Upload an image or video',
      readOnlyHint: false,
      destructiveHint: false,
      idempotentHint: false,
      openWorldHint: true,
    },
  },
  async ({ path, base64, filename, url, expiresIn, allowDuplicate }) => {
    try {
      let blob: Blob;
      let name: string;

      if ([path, base64, url].filter((v) => v !== undefined).length > 1) {
        return { content: [{ type: 'text', text: 'Error: provide only one of `path`, `base64` or `url`.' }], isError: true };
      } else if (url) {
        const result = pick({ ...(await client.uploadFromUrl(url, { expiresIn, allowDuplicate })) }, ['duplicate']);
        return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }], structuredContent: result };
      } else if (path) {
        const buffer = await readFile(path);
        blob = new Blob([buffer as unknown as BlobPart]);
        name = filename ?? basename(path);
      } else if (base64) {
        if (!filename) {
          return { content: [{ type: 'text', text: 'Error: `filename` is required when uploading via `base64`.' }], isError: true };
        }
        blob = new Blob([Buffer.from(base64, 'base64') as unknown as BlobPart]);
        name = filename;
      } else {
        return { content: [{ type: 'text', text: 'Error: provide `path`, `base64` + `filename`, or `url`.' }], isError: true };
      }

      const result = pick({ ...(await client.upload(blob, name, { expiresIn, allowDuplicate })) }, ['duplicate']);
      return {
        content: [
          {
            type: 'text' as const,
            text: JSON.stringify(result, null, 2),
          },
        ],
        structuredContent: result,
      };
    } catch (err) {
      return errorResult(err);
    }
  },
);

server.registerTool(
  'list_uploads',
  {
    title: 'List your uploads',
    description:
      "List your YourImageShare uploads, newest first, 50 per page - use this to find an upload's `id` (needed " +
      "by `delete_upload`) when you only have its URL or remember it by content, since there's no lookup-by-URL " +
      'endpoint. Each result includes the same three link fields `upload_image` returns, plus `title` and ' +
      '`created_at`. An out-of-range page number returns an empty `data` array, not an error.',
    inputSchema: {
      page: z.number().int().min(1).optional().describe('Page number. Defaults to 1.'),
    },
    outputSchema: {
      data: z.array(
        z.object({
          ...uploadFields,
          title: z.string().nullable().describe('User-set title, or null if never set.'),
          created_at: z.string().describe('ISO 8601 upload timestamp.'),
        }),
      ),
      meta: z.object({
        current_page: z.number(),
        last_page: z.number(),
        total: z.number().describe('Total uploads across all pages, not just this one.'),
      }),
    },
    annotations: {
      title: 'List your uploads',
      readOnlyHint: true,
      destructiveHint: false,
      idempotentHint: true,
      openWorldHint: true,
    },
  },
  async ({ page }) => {
    try {
      const raw = await client.list(page ?? 1);
      const result = {
        data: raw.data.map((item) => pick({ ...item }, ['title', 'created_at'])),
        meta: { current_page: raw.meta.current_page, last_page: raw.meta.last_page, total: raw.meta.total },
      };
      return { content: [{ type: 'text' as const, text: JSON.stringify(result, null, 2) }], structuredContent: result };
    } catch (err) {
      return errorResult(err);
    }
  },
);

server.registerTool(
  'delete_upload',
  {
    title: 'Delete an upload',
    description:
      "Permanently delete one of your YourImageShare uploads by `id` - irreversible, the file and its links " +
      "stop working immediately. Get the `id` from upload_image's response right after uploading, or from " +
      'list_uploads if you no longer have it. Errors (not found, already deleted, wrong owner) come back as a ' +
      'normal tool error.',
    inputSchema: {
      id: z.string().describe('The upload id to delete (the `id` field returned by upload_image/list_uploads).'),
    },
    outputSchema: {
      deleted: z.literal(true),
      id: z.string(),
    },
    annotations: {
      title: 'Delete an upload',
      readOnlyHint: false,
      destructiveHint: true,
      idempotentHint: true,
      openWorldHint: true,
    },
  },
  async ({ id }) => {
    try {
      await client.delete(id);
      return {
        content: [{ type: 'text' as const, text: `Deleted ${id}.` }],
        structuredContent: { deleted: true as const, id },
      };
    } catch (err) {
      return errorResult(err);
    }
  },
);

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((err) => {
  console.error('yourimageshare-mcp: fatal error', err);
  process.exit(1);
});
