
ALTER TABLE public.programme_content
  ADD COLUMN IF NOT EXISTS format text,
  ADD COLUMN IF NOT EXISTS class_size text,
  ADD COLUMN IF NOT EXISTS certification text;

UPDATE public.programme_content SET
  format = 'Live online · 90-min sessions',
  class_size = 'Max 8 students',
  certification = 'Cambridge YLE & KET'
WHERE programme_id = 'sc1';

UPDATE public.programme_content SET
  format = 'Hybrid · weekly lab + online',
  class_size = 'Max 12 students',
  certification = 'STEM Foundation accredited'
WHERE programme_id = 'sc2';

UPDATE public.programme_content SET
  format = 'In-studio · 2-hour sessions',
  class_size = 'Max 10 students',
  certification = 'ArtSpark portfolio award'
WHERE programme_id = 'sc3';

UPDATE public.programme_content SET
  format = 'Live online · weekly contests',
  class_size = 'Max 15 students',
  certification = 'MathPro ranking certificate'
WHERE programme_id = 'sc4';

UPDATE public.programme_content SET
  format = 'Hybrid · CAD labs + race days',
  class_size = 'Teams of 3-6',
  certification = 'STEM Racing UK official'
WHERE programme_id = 'sc5';
