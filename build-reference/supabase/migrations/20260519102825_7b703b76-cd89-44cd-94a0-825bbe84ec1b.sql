
-- helper: is_admin() with no args (uses auth.uid())
create or replace function public.is_admin()
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select exists (select 1 from public.users u where u.id = auth.uid() and u.role = 'admin');
$$;

-- programme status enum
do $$ begin
  create type public.programme_status as enum ('Open','Registering','Coming Soon','Closed');
exception when duplicate_object then null; end $$;

-- programmes
create table public.programmes (
  id text primary key,
  title text not null,
  category text not null,
  age_range text not null,
  organiser text not null,
  provider_short text not null,
  duration_weeks int not null,
  period_start date,
  period_end date,
  description text not null,
  tagline text,
  brand_color text not null,
  status public.programme_status not null default 'Open',
  capacity int not null,
  enrolled_count int not null default 0,
  featured bool not null default false,
  sign_in_method text not null default 'standard',
  progress_updates text not null default 'realtime',
  external_lms_url text,
  created_at timestamptz default now()
);

create table public.programme_content (
  programme_id text primary key references public.programmes(id) on delete cascade,
  why_join jsonb,
  curriculum jsonb,
  testimonials jsonb,
  stats jsonb,
  gallery_labels jsonb
);

create table public.cms_landing (
  id int primary key default 1 check (id = 1),
  hero_title text not null,
  hero_subtitle text not null,
  hero_cta text not null,
  featured_programme_id text references public.programmes(id),
  featured_eyebrow text not null,
  featured_cta text not null,
  announcements_title text not null,
  announcements jsonb not null,
  categories_title text not null,
  programmes_title text not null,
  stats jsonb not null,
  updated_at timestamptz default now()
);

alter table public.programmes enable row level security;
alter table public.programme_content enable row level security;
alter table public.cms_landing enable row level security;

create policy "read programmes" on public.programmes for select using (true);
create policy "read programme_content" on public.programme_content for select using (true);
create policy "read cms_landing" on public.cms_landing for select using (true);
create policy "admin write programmes" on public.programmes for all using (public.is_admin()) with check (public.is_admin());
create policy "admin write programme_content" on public.programme_content for all using (public.is_admin()) with check (public.is_admin());
create policy "admin write cms_landing" on public.cms_landing for all using (public.is_admin()) with check (public.is_admin());

-- scheme-images storage bucket (public read)
insert into storage.buckets (id, name, public) values ('scheme-images', 'scheme-images', true)
on conflict (id) do update set public = true;

create policy "scheme-images public read" on storage.objects for select using (bucket_id = 'scheme-images');
create policy "scheme-images admin write" on storage.objects for all
  using (bucket_id = 'scheme-images' and public.is_admin())
  with check (bucket_id = 'scheme-images' and public.is_admin());
