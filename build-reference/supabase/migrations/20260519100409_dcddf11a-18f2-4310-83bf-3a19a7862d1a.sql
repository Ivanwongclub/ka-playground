-- handle_new_user is only called by the auth trigger
revoke execute on function public.handle_new_user() from public, anon, authenticated;

-- is_admin is only needed by RLS evaluation as authenticated users
revoke execute on function public.is_admin(uuid) from public, anon;
grant execute on function public.is_admin(uuid) to authenticated;