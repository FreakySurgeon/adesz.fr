const WP_API = "https://adesz.fr/wp-json/wp/v2";

export interface WPPost {
  id: number;
  title: { rendered: string };
  content: { rendered: string };
  excerpt: { rendered: string };
  slug: string;
  date: string;
  categories: number[];
  featured_media: number;
  _embedded?: {
    'wp:featuredmedia'?: Array<{ source_url: string; alt_text: string }>;
  };
}

export interface WPPage {
  id: number;
  title: { rendered: string };
  content: { rendered: string };
  slug: string;
}

export interface WPCategory {
  id: number;
  name: string;
  slug: string;
  count: number;
}

export interface WPMedia {
  id: number;
  source_url: string;
  alt_text: string;
}

// Category IDs
export const CATEGORIES = {
  PROJETS_EN_COURS: 311,
  PROJETS_A_VENIR: 312,
  PROJETS: 313,
  REALISATIONS: 314,
  PARTENAIRES: 315,
  PRESSE: 317,
  FAQ: 323,
} as const;

// Page IDs
export const PAGES = {
  PAYS: 19047,
  VILLAGE: 19058,
  ASSOCIATION: 19063,
  SANTE: 19073,
  EDUCATION: 19076,
  AGRICULTURE: 19079,
  DEVELOPPEMENT: 19082,
  URGENCES: 19085,
  ADHERER: 19094,
  PARTENAIRES: 19183,
} as const;

async function fetchAPI<T>(endpoint: string, params?: Record<string, string>): Promise<T> {
  const url = new URL(`${WP_API}/${endpoint}`);
  url.searchParams.set('_embed', 'true');
  if (params) {
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
  }
  const res = await fetch(url.toString());
  if (!res.ok) {
    throw new Error(`WP API error: ${res.status} ${res.statusText}`);
  }
  return res.json();
}

export async function getPosts(params?: Record<string, string>): Promise<WPPost[]> {
  return fetchAPI<WPPost[]>('posts', { per_page: '100', ...params });
}

export async function getPostsByCategory(categoryId: number): Promise<WPPost[]> {
  return getPosts({ categories: categoryId.toString() });
}

export async function getPostBySlug(slug: string): Promise<WPPost | undefined> {
  const posts = await getPosts({ slug });
  return posts[0];
}

export async function getPage(id: number): Promise<WPPage> {
  return fetchAPI<WPPage>(`pages/${id}`);
}

export async function getMedia(id: number): Promise<WPMedia> {
  return fetchAPI<WPMedia>(`media/${id}`);
}
