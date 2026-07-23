-- Enum for user roles
create type public.user_role as enum ('admin','school','teacher','parent','student');

-- Profiles table
create table public.users (
  id uuid primary key references auth.users(id) on delete cascade,
  email text unique not null,
  full_name text not null,
  full_name_zh text,
  role public.user_role not null,
  region text default 'HK',
  language text default 'en',
  created_at timestamptz default now()
);

alter table public.users enable row level security;

-- SECURITY DEFINER helper to check admin without triggering recursive RLS
create or replace function public.is_admin(_uid uuid)
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select exists (select 1 from public.users u where u.id = _uid and u.role = 'admin');
$$;

create policy "users read own"
  on public.users for select
  using (auth.uid() = id);

create policy "admins read all"
  on public.users for select
  using (public.is_admin(auth.uid()));

create policy "users update own"
  on public.users for update
  using (auth.uid() = id);

-- Trigger: auto-create public.users row from auth.users signup metadata
create or replace function public.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
  insert into public.users (id, email, full_name, full_name_zh, role, region, language)
  values (
    new.id,
    new.email,
    coalesce(new.raw_user_meta_data ->> 'full_name', new.email),
    new.raw_user_meta_data ->> 'full_name_zh',
    coalesce((new.raw_user_meta_data ->> 'role')::public.user_role, 'parent'::public.user_role),
    coalesce(new.raw_user_meta_data ->> 'region', 'HK'),
    coalesce(new.raw_user_meta_data ->> 'language', 'en')
  )
  on conflict (id) do nothing;
  return new;
end;
$$;

create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();