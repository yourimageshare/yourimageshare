const DEFAULT_BASE_URL = 'https://yourimageshare.com/api';
const SDK_VERSION = '1.1.0';
/** Files above this size are sent in pieces (one request can carry at most 100 MB). */
const CHUNK_THRESHOLD = 90 * 1024 * 1024;
/** Size of one piece of a chunked upload (the API accepts at most 5 MB). */
const CHUNK_SIZE = 5 * 1024 * 1024;
/** Node's default `fetch` User-Agent is the bare string "node", which yourimageshare.com's
 *  bot-blocklist treats as a scanner and 403s. Identify the SDK explicitly instead. Only set
 *  in non-browser environments (`window` is absent) - browsers control their own UA header. */
const isBrowser = typeof window !== 'undefined';

export interface YourImageShareOptions {
  /** Your API key, from the "API" tab at https://yourimageshare.com/my-account */
  apiKey: string;
  /** Override the base URL (mainly for testing). Defaults to https://yourimageshare.com/api */
  baseUrl?: string;
}

export interface UploadOptions {
  /** Auto-delete this upload after N seconds (60 to 2,592,000 = 30 days). Omit for a permanent upload. */
  expiresIn?: number;
  /** Filename to send when `file` is a Buffer/Uint8Array/ArrayBuffer rather than a File/Blob that already has one. */
  filename?: string;
  /** Store a new copy even if your account already uploaded this exact file (otherwise the existing upload is returned with `duplicate: true`). */
  allowDuplicate?: boolean;
  /** Called after each piece of a chunked upload (files over 90 MB) with bytes sent so far and the total. */
  onProgress?: (sent: number, total: number) => void;
}

export type UploadType = 'image' | 'video';

export interface Upload {
  id: string;
  type: UploadType;
  /** Storage URL of the file as uploaded. It can change shortly afterwards when the file is converted (WebP/MP4) - store `src`. */
  path: string;
  /** Permanent direct file URL - always opens the current file. */
  src: string;
  /** The file's page on YourImageShare. */
  direct: string;
  /** 280 px wide WebP thumbnail (a video's first frame), or null. */
  thumb: string | null;
  width: number | null;
  height: number | null;
  /** File size in bytes as stored. */
  size: number | null;
  /** True if the upload is password-protected. */
  locked: boolean;
  expires_at: string | null;
}

export interface UploadResult extends Upload {
  /** True if your account had already uploaded this exact file and that upload was returned. */
  duplicate: boolean;
}

export interface ListedUpload extends Upload {
  title: string | null;
  created_at: string;
}

export interface ListMeta {
  current_page: number;
  last_page: number;
  total: number;
}

export interface ListResult {
  data: ListedUpload[];
  meta: ListMeta;
}

/** Thrown for any non-2xx response or `{"type":"error"}` payload. `status` is the HTTP status code. */
export class YourImageShareError extends Error {
  status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = 'YourImageShareError';
    this.status = status;
  }
}

export class YourImageShare {
  private readonly apiKey: string;
  private readonly baseUrl: string;

  constructor(options: YourImageShareOptions) {
    if (!options || !options.apiKey) {
      throw new Error('YourImageShare: `apiKey` is required.');
    }
    this.apiKey = options.apiKey;
    this.baseUrl = (options.baseUrl ?? DEFAULT_BASE_URL).replace(/\/+$/, '');
  }

  private headers(): HeadersInit {
    const headers: Record<string, string> = { 'X-API-Key': this.apiKey };
    if (!isBrowser) {
      headers['User-Agent'] = `yourimageshare-js/${SDK_VERSION}`;
    }
    return headers;
  }

  private async parseJson<T>(res: Response): Promise<T> {
    let body: any = null;
    try {
      body = await res.json();
    } catch {
      throw new YourImageShareError(`Unexpected non-JSON response (HTTP ${res.status})`, res.status);
    }
    if (!res.ok || body?.type === 'error') {
      const message = typeof body?.errors === 'string' ? body.errors : `Request failed (HTTP ${res.status})`;
      throw new YourImageShareError(message, res.status);
    }
    return body as T;
  }

  private uploadFields(form: FormData, options: UploadOptions): FormData {
    if (options.expiresIn !== undefined) {
      form.append('expires_in', String(options.expiresIn));
    }
    if (options.allowDuplicate) {
      form.append('allow_duplicate', '1');
    }
    return form;
  }

  private async postUpload(form: FormData): Promise<UploadResult> {
    const res = await fetch(this.baseUrl, { method: 'POST', headers: this.headers(), body: form });
    const body = await this.parseJson<{ data: UploadResult }>(res);
    return body.data;
  }

  /**
   * Upload a file (up to 200 MB). `file` accepts a browser File/Blob, or a raw Buffer/Uint8Array/ArrayBuffer
   * (in which case pass `options.filename` so the server sees a real extension). Files over 90 MB are sent
   * in 5 MB pieces automatically.
   */
  async upload(file: Blob | ArrayBuffer | Uint8Array, options: UploadOptions = {}): Promise<UploadResult> {
    const blob = file instanceof Blob ? file : new Blob([file as BlobPart]);
    const filename = options.filename ?? ((file as any)?.name || 'upload');
    if (blob.size > CHUNK_THRESHOLD) {
      return this.uploadChunked(blob, filename, options);
    }
    const form = new FormData();
    form.append('uploads', blob, filename);
    return this.postUpload(this.uploadFields(form, options));
  }

  /** Upload from a public http(s) link: the server downloads the file itself (up to 200 MB). */
  async uploadFromUrl(url: string, options: Omit<UploadOptions, 'filename' | 'onProgress'> = {}): Promise<UploadResult> {
    const form = new FormData();
    form.append('url', url);
    return this.postUpload(this.uploadFields(form, options));
  }

  private async uploadChunked(blob: Blob, filename: string, options: UploadOptions): Promise<UploadResult> {
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);
    const uploadId = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    const total = Math.ceil(blob.size / CHUNK_SIZE);

    for (let index = 0; index < total; index++) {
      const piece = blob.slice(index * CHUNK_SIZE, (index + 1) * CHUNK_SIZE);
      for (let attempt = 1; ; attempt++) {
        const form = new FormData();
        form.append('upload_id', uploadId);
        form.append('index', String(index));
        form.append('total', String(total));
        form.append('chunk', piece, 'piece');
        try {
          const res = await fetch(`${this.baseUrl}/chunk`, { method: 'POST', headers: this.headers(), body: form });
          await this.parseJson<{ type: string }>(res);
          break;
        } catch (err) {
          // network errors and 5xx are retried; anything the server refused (4xx) is final
          const status = err instanceof YourImageShareError ? err.status : 0;
          if (attempt >= 3 || (status >= 400 && status < 500)) {
            throw err;
          }
        }
      }
      options.onProgress?.(Math.min(blob.size, (index + 1) * CHUNK_SIZE), blob.size);
    }

    const form = new FormData();
    form.append('upload_id', uploadId);
    form.append('filename', filename);
    return this.postUpload(this.uploadFields(form, options));
  }

  /** List your uploads, newest first. Paginated 50 per page. */
  async list(page = 1): Promise<ListResult> {
    const url = new URL(this.baseUrl);
    if (page > 1) {
      url.searchParams.set('page', String(page));
    }
    const res = await fetch(url, { headers: this.headers() });
    return this.parseJson<ListResult>(res);
  }

  /** Delete one of your uploads by id. */
  async delete(id: string): Promise<void> {
    const res = await fetch(`${this.baseUrl}/${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: this.headers(),
    });
    await this.parseJson<{ msg: string }>(res);
  }
}

export default YourImageShare;
