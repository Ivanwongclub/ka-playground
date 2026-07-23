import { supabase } from "@/integrations/supabase/client";

const BUCKET = "scheme-images";

/**
 * Returns the public Storage URL for a path inside the scheme-images bucket.
 * Example: imageUrl("hero-tiles/hero-tile-sc5.jpg")
 */
export function imageUrl(path: string): string {
  const clean = path.replace(/^\/+/, "");
  const { data } = supabase.storage.from(BUCKET).getPublicUrl(clean);
  return data.publicUrl;
}

// Convenience helpers for the canonical asset folders
export const heroTile = (id: string) => imageUrl(`hero-tiles/hero-tile-${id}.jpg`);
export const announcementImg = (slug: string) => imageUrl(`announcements/${slug}.jpg`);
export const featuredImg = (id: string) => imageUrl(`featured/featured-${id}.jpg`);
export const cardImg = (id: string) => imageUrl(`cards/card-${id}.jpg`);
export const heroImg = (id: string) => imageUrl(`heroes/hero-${id}.jpg`);
export const galleryImg = (id: string, n: 1 | 2 | 3) =>
  imageUrl(`galleries/${id}/gallery-${id}-${n}.jpg`);
