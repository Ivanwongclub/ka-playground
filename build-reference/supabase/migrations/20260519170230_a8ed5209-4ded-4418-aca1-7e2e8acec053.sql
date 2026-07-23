create or replace function public.refresh_enrolled_count()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  pid text;
begin
  pid := coalesce(new.programme_id, old.programme_id);
  update public.programmes
     set enrolled_count = (
       select count(*) from public.enrolments
        where programme_id = pid and status = 'active'
     )
   where id = pid;
  return null;
end;
$$;

drop trigger if exists trg_enrolments_refresh_count on public.enrolments;
create trigger trg_enrolments_refresh_count
  after insert or update or delete on public.enrolments
  for each row
  execute function public.refresh_enrolled_count();

update public.programmes p
   set enrolled_count = (
     select count(*) from public.enrolments e
      where e.programme_id = p.id and e.status = 'active'
   );