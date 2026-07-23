// Shared programme + CMS types

export type ProgrammeStatus = "Open" | "Registering" | "Coming Soon" | "Closed";

export type Programme = {
  id: string;
  title: string;
  category: string;
  age_range: string;
  organiser: string;
  provider_short: string;
  duration_weeks: number;
  period_start: string | null;
  period_end: string | null;
  tagline: string | null;
  description: string;
  brand_color: string;
  status: ProgrammeStatus | string;
  capacity: number;
  enrolled_count: number;
  featured: boolean;
  sign_in_method: string;
  progress_updates: string;
};

export type StatItem = { v: string; l: string };
export type WhyItem = { t: string; d: string; icon?: string };
export type Testimonial = { n: string; r: string; t: string };
export type CurriculumItem = { wk: string; n: string; d: string };

export type ProgrammeContent = {
  gallery_labels: string[] | null;
  stats: StatItem[] | null;
  why_join: WhyItem[] | null;
  testimonials: Testimonial[] | null;
  curriculum: CurriculumItem[] | null;
  format: string | null;
  class_size: string | null;
  certification: string | null;
};


export type Announcement = { id: string; title: string; body: string; date?: string };
export type Stat = { v: string; l: string };

export type Cms = {
  hero_title: string | null;
  hero_subtitle: string | null;
  stats: Stat[] | null;
  announcements_title: string | null;
  announcements: Announcement[] | null;
  featured_programme_id: string | null;
  featured_eyebrow: string | null;
  featured_cta: string | null;
};
