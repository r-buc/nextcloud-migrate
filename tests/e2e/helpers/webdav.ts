/**
 * WebDAV helpers for seeding source files and verifying target files.
 *
 * Nextcloud WebDAV base path: /remote.php/dav/files/<userId>/
 */

export interface DavFile {
  path: string;       // relative path within the user's root, e.g. "folder/file.txt"
  content: string;    // UTF-8 text content
  /** Optional mtime override in seconds since epoch */
  mtime?: number;
}

/** Build a full WebDAV URL for a user's file path. */
export function davUrl(baseUrl: string, userId: string, filePath: string): string {
  return `${baseUrl}/remote.php/dav/files/${userId}/${filePath}`;
}

/** Basic-auth header value for a user:password pair. */
export function basicAuth(user: string, password: string): string {
  return 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64');
}

/** Upload (PUT) a single file over WebDAV. */
export async function uploadFile(
  baseUrl: string,
  userId: string,
  password: string,
  file: DavFile,
): Promise<void> {
  const url = davUrl(baseUrl, userId, file.path);
  // Ensure parent collection exists (MKCOL is idempotent on most servers)
  const parts = file.path.split('/');
  if (parts.length > 1) {
    await ensureCollection(baseUrl, userId, password, parts.slice(0, -1).join('/'));
  }
  const res = await fetch(url, {
    method: 'PUT',
    headers: {
      Authorization: basicAuth(userId, password),
      'Content-Type': 'application/octet-stream',
      ...(file.mtime ? { 'X-OC-Mtime': String(file.mtime) } : {}),
    },
    body: file.content,
  });
  if (!res.ok && res.status !== 204 && res.status !== 201) {
    throw new Error(`PUT ${url} failed: ${res.status} ${await res.text()}`);
  }
}

/** Upload multiple files, creating parent directories as needed. */
export async function uploadFiles(
  baseUrl: string,
  userId: string,
  password: string,
  files: DavFile[],
): Promise<void> {
  for (const file of files) {
    await uploadFile(baseUrl, userId, password, file);
  }
}

/** Ensure a WebDAV collection (directory) exists by sending MKCOL. */
export async function ensureCollection(
  baseUrl: string,
  userId: string,
  password: string,
  collectionPath: string,
): Promise<void> {
  const parts = collectionPath.split('/').filter(Boolean);
  let built = '';
  for (const part of parts) {
    built = built ? `${built}/${part}` : part;
    const url = davUrl(baseUrl, userId, built);
    const res = await fetch(url, {
      method: 'MKCOL',
      headers: { Authorization: basicAuth(userId, password) },
    });
    // 405 = already exists – that's fine
    if (!res.ok && res.status !== 405 && res.status !== 201) {
      throw new Error(`MKCOL ${url} failed: ${res.status}`);
    }
  }
}

/** Check whether a file exists on a WebDAV server. Returns true if it does. */
export async function fileExists(
  baseUrl: string,
  userId: string,
  password: string,
  filePath: string,
): Promise<boolean> {
  const url = davUrl(baseUrl, userId, filePath);
  const res = await fetch(url, {
    method: 'HEAD',
    headers: { Authorization: basicAuth(userId, password) },
  });
  return res.ok;
}

/**
 * Download a file's content from WebDAV.
 * Throws if the file is not found.
 */
export async function downloadFile(
  baseUrl: string,
  userId: string,
  password: string,
  filePath: string,
): Promise<string> {
  const url = davUrl(baseUrl, userId, filePath);
  const res = await fetch(url, {
    method: 'GET',
    headers: { Authorization: basicAuth(userId, password) },
  });
  if (!res.ok) throw new Error(`GET ${url} failed: ${res.status}`);
  return res.text();
}

/**
 * List all file href paths returned by a PROPFIND on a collection.
 * Returns an array of decoded relative paths under the collection.
 */
export async function listCollection(
  baseUrl: string,
  userId: string,
  password: string,
  collectionPath: string,
): Promise<string[]> {
  const url = davUrl(baseUrl, userId, collectionPath);
  const res = await fetch(url, {
    method: 'PROPFIND',
    headers: {
      Authorization: basicAuth(userId, password),
      Depth: '1',
      'Content-Type': 'application/xml',
    },
    body: `<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:href/></d:prop></d:propfind>`,
  });
  if (!res.ok) throw new Error(`PROPFIND ${url} failed: ${res.status}`);
  const xml = await res.text();
  const hrefs: string[] = [];
  const re = /<[dD]:href>([^<]+)<\/[dD]:href>/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(xml)) !== null) {
    hrefs.push(decodeURIComponent(m[1]));
  }
  return hrefs;
}

/**
 * Build a tree of relative file paths to seed a test scenario.
 * Returns an array of DavFile objects with synthetic content.
 */
export function buildFileTree(prefix: string, count: number): DavFile[] {
  const files: DavFile[] = [];
  const mtime = Math.floor(Date.now() / 1000) - 3600; // 1 hour ago
  for (let i = 0; i < count; i++) {
    const folder = `${prefix}/folder${Math.floor(i / 3)}`;
    files.push({
      path: `${folder}/file${i}.txt`,
      content: `Content of file ${i} in ${prefix}`,
      mtime,
    });
  }
  return files;
}
