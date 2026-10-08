export const DEFAULT_BASE_URL = 'https://yourimageshare.com/api';
const SDK_VERSION = '1.1.0';
/** Files above this size are sent in pieces (one request can carry at most 100 MB). */
const CHUNK_THRESHOLD = 90 * 1024 * 1024;
const CHUNK_SIZE = 5 * 1024 * 1024;

export interface Upload {
  id: string;
  type: 'image' | 'video';
  path: string;
  src: string;
  direct: string;
  thumb: string | null;
  width: number | null;
  height: number | null;
  size: number | null;
  locked: boolean;
  visibility: Visibility;
  title: string | null;
  description: string | null;
  expires_at: string | null;
}

export type Visibility = 'private' | 'unlisted' | 'public';

export interface UploadResult extends Upload {
  duplicate: boolean;
  delete_url?: string;
}

export interface ListedUpload extends Upload {
  created_at: string;
}

export interface UploadOptions {
  expiresIn?: number;
  allowDuplicate?: boolean;
  visibility?: Visibility;
  title?: string;
  description?: string;
}

export interface UpdateOptions {
  visibility?: Visibility;
  title?: string;
  description?: string;
}

export interface ListResult {
  data: ListedUpload[];
  meta: { current_page: number; last_page: number; total: number };
}

export class YourImageShareApiError extends Error {
  status: number;
  constructor(message: string, status: number) {
    super(message);
    this.name = 'YourImageShareApiError';
    this.status = status;
  }
}

/** Minimal internal client, kept self-contained rather than depending on the
 *  separate `yourimageshare` npm package (not published yet, and this server
 *  should build/publish independently of that package's release timeline). */
export class YourImageShareClient {
  private readonly apiKey: string;
  private readonly baseUrl: string;

  constructor(apiKey: string, baseUrl: string) {
    this.apiKey = apiKey;
    this.baseUrl = baseUrl.replace(/\/+$/, '');
  }

  private headers(): HeadersInit {
    return {
      'X-API-Key': this.apiKey,
      'User-Agent': `yourimageshare-mcp/${SDK_VERSION}`,
    };
  }

  private async parse<T>(res: Response): Promise<T> {
    let body: any;
    try {
      body = await res.json();
    } catch {
      throw new YourImageShareApiError(`Unexpected non-JSON response (HTTP ${res.status})`, res.status);
    }
    if (!res.ok || body?.type === 'error') {
      const message = typeof body?.errors === 'string' ? body.errors : `Request failed (HTTP ${res.status})`;
      throw new YourImageShareApiError(message, res.status);
    }
    return body as T;
  }

  private async postUpload(form: FormData, options: UploadOptions): Promise<UploadResult> {
    if (options.expiresIn !== undefined) {
      form.append('expires_in', String(options.expiresIn));
    }
    if (options.allowDuplicate) {
      form.append('allow_duplicate', '1');
    }
    if (options.visibility) form.append('visibility', options.visibility);
    if (options.title !== undefined) form.append('title', options.title);
    if (options.description !== undefined) form.append('description', options.description);
    const res = await fetch(this.baseUrl, { method: 'POST', headers: this.headers(), body: form });
    const body = await this.parse<{ data: UploadResult }>(res);
    return body.data;
  }

  /** Upload a file (up to 200 MB); files over 90 MB go up in 5 MB pieces. */
  async upload(blob: Blob, filename: string, options: UploadOptions = {}): Promise<UploadResult> {
    const form = new FormData();
    if (blob.size > CHUNK_THRESHOLD) {
      form.append('upload_id', await this.sendChunks(blob));
      form.append('filename', filename);
    } else {
      form.append('uploads', blob, filename);
    }
    return this.postUpload(form, options);
  }

  /** Upload from a public link: the server downloads the file (up to 200 MB). */
  async uploadFromUrl(url: string, options: UploadOptions = {}): Promise<UploadResult> {
    const form = new FormData();
    form.append('url', url);
    return this.postUpload(form, options);
  }

  private async sendChunks(blob: Blob): Promise<string> {
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
          await this.parse(await fetch(`${this.baseUrl}/chunk`, { method: 'POST', headers: this.headers(), body: form }));
          break;
        } catch (err) {
          // network errors and 5xx are retried; anything the server refused (4xx) is final
          const status = err instanceof YourImageShareApiError ? err.status : 0;
          if (attempt >= 3 || (status >= 400 && status < 500)) throw err;
        }
      }
    }
    return uploadId;
  }

  async list(page = 1): Promise<ListResult> {
    const url = new URL(this.baseUrl);
    if (page > 1) url.searchParams.set('page', String(page));
    const res = await fetch(url, { headers: this.headers() });
    return this.parse<ListResult>(res);
  }

  async get(id: string): Promise<ListedUpload> {
    const res = await fetch(`${this.baseUrl}/${encodeURIComponent(id)}`, { headers: this.headers() });
    return (await this.parse<{ data: ListedUpload }>(res)).data;
  }

  async update(id: string, changes: UpdateOptions): Promise<ListedUpload> {
    const res = await fetch(`${this.baseUrl}/${encodeURIComponent(id)}`, {
      method: 'PATCH',
      headers: { ...(this.headers() as Record<string, string>), 'Content-Type': 'application/json' },
      body: JSON.stringify(changes),
    });
    return (await this.parse<{ data: ListedUpload }>(res)).data;
  }

  async delete(id: string): Promise<void> {
    const res = await fetch(`${this.baseUrl}/${encodeURIComponent(id)}`, {
      method: 'DELETE',
      headers: this.headers(),
    });
    await this.parse<{ msg: string }>(res);
  }
}
