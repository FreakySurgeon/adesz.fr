/** Prefix a path with the Astro base URL. Handles trailing/leading slashes. */
export function url(path: string): string {
  const base = import.meta.env.BASE_URL;
  // BASE_URL is "/" in prod, "/test" or "/test/" in staging
  if (base === '/' || base === '') return path;
  // Avoid double slashes
  const cleanBase = base.endsWith('/') ? base.slice(0, -1) : base;
  return path === '/' ? cleanBase + '/' : cleanBase + path;
}
