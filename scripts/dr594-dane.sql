-- Wyłącznie syntetyczny pomiar DR. Bez zmian schematu aplikacji.
\set ON_ERROR_STOP on
\timing on
SET timezone = 'UTC';
DO $$ BEGIN
  IF current_database() <> 'kuking_flota_gpt_dr_baza_source'
     OR inet_server_addr() IS DISTINCT FROM '127.0.0.1'::inet
     OR inet_server_port() IS DISTINCT FROM 55439
     OR current_user <> 'kuking' THEN
    RAISE EXCEPTION 'Wybierz własną bazę kuking_flota_gpt_dr_baza_source na 127.0.0.1:55439, rolę kuking.';
  END IF;
  IF EXISTS (SELECT 1 FROM users WHERE email LIKE 'dr594-%@example.invalid') THEN
    RAISE EXCEPTION 'Dane próby już istnieją. Nie uruchamiaj generatora ponownie.';
  END IF;
END $$;
BEGIN;
-- Funkcja tymczasowa nie wchodzi do zrzutu. Identyfikatory dają odtwarzalne relacje.
CREATE FUNCTION pg_temp.dr_id(kind text, n bigint) RETURNS uuid
LANGUAGE sql IMMUTABLE AS $$ SELECT md5('dr594:' || kind || ':' || n)::uuid $$;
INSERT INTO users (id,email,password,created_at,updated_at)
SELECT pg_temp.dr_id('user',n), 'dr594-'||n||'@example.invalid', '!konto-syntetyczne-bez-logowania',
       '2025-01-01'::timestamptz + n * interval '1 minute', '2026-09-01'::timestamptz
FROM generate_series(1,50000) n;
INSERT INTO profiles (user_id,username,display_name,bio,created_at,updated_at)
SELECT pg_temp.dr_id('user',n), 'dr594_'||n, 'Kucharz próbny '||n,
       'Dane syntetyczne do odtwarzania bazy. Gotuję zupy, piekę chleb i zapisuję rodzinne przepisy.',
       '2025-01-01'::timestamptz, '2026-09-01'::timestamptz
FROM generate_series(1,50000) n;
INSERT INTO recipes (id,author_id,title,slug,summary,servings,prep_minutes,cook_minutes,difficulty,status,published_at,created_at,updated_at)
SELECT pg_temp.dr_id('recipe',n), pg_temp.dr_id('user',1+(n%50000)),
       'Zupa warzywna — próba '||n, 'dr594-zupa-'||n,
       'Rodzinny przepis z marchewką, ziemniakami i pietruszką. Porcja '||n,
       4,15,35,'easy','published','2026-01-01'::timestamptz+n*interval '1 minute',
       '2026-01-01'::timestamptz+n*interval '1 minute','2026-09-01'::timestamptz
FROM generate_series(1,100000) n;
INSERT INTO recipe_ingredients (id,recipe_id,ingredient_text,quantity,position)
SELECT pg_temp.dr_id('ingredient',n*10+p),pg_temp.dr_id('recipe',n),
       (ARRAY['marchew','ziemniaki','pietruszka','cebula','czosnek','oliwa','sól','pieprz'])[p],
       1+(n%5),p FROM generate_series(1,100000) n CROSS JOIN generate_series(1,8) p;
INSERT INTO recipe_steps (id,recipe_id,position,instruction)
SELECT pg_temp.dr_id('step',n*10+p),pg_temp.dr_id('recipe',n),p,
       'Obierz warzywa, pokrój, włóż do garnka. Gotuj powoli i sprawdź miękkość. Krok '||p||', przepis '||n
FROM generate_series(1,100000) n CROSS JOIN generate_series(1,4) p;
INSERT INTO recipe_versions (id,recipe_id,editor_id,version_number,snapshot)
SELECT pg_temp.dr_id('version',n),r.id,r.author_id,1,
       jsonb_build_object('title',r.title,'summary',r.summary,'servings',4,
         'ingredients',(SELECT jsonb_agg(to_jsonb(i) ORDER BY position) FROM recipe_ingredients i WHERE i.recipe_id=r.id),
         'steps',(SELECT jsonb_agg(to_jsonb(s) ORDER BY position) FROM recipe_steps s WHERE s.recipe_id=r.id))
FROM generate_series(1,100000) n JOIN recipes r ON r.id=pg_temp.dr_id('recipe',n);
INSERT INTO posts (id,author_id,body,visibility,status,recipe_id,published_at,created_at,updated_at)
SELECT pg_temp.dr_id('post',n),pg_temp.dr_id('user',1+(n%50000)),
       'Dziś ugotowaliśmy zupę. Marchew, pietruszka i ziemniaki z targu, do tego domowy chleb. '||
       repeat('Warzywa kroję drobno, gotuję powoli, doprawiam na końcu. Rodzina prosi o dokładkę. ',3+(n%6))||
       'Syntetyczny wpis numer '||n||' — '||md5(n::text),
       (ARRAY['public','public','public','followers','private'])[1+n%5],'published',
       CASE WHEN n%10=0 THEN pg_temp.dr_id('recipe',1+n%100000) ELSE NULL END,
       '2025-01-01'::timestamptz+n*interval '30 seconds',
       '2025-01-01'::timestamptz+n*interval '30 seconds','2026-09-01'::timestamptz
FROM generate_series(1,1000000) n;
INSERT INTO comments (id,author_id,post_id,body,created_at,updated_at)
SELECT pg_temp.dr_id('comment',n),pg_temp.dr_id('user',1+(n%50000)),pg_temp.dr_id('post',1+n%1000000),
       'Dziękuję za pomysł. U mnie w domu dodajemy więcej czosnku i świeżego koperku. Komentarz próbny '||n||' '||md5(n::text),
       '2026-02-01'::timestamptz+n*interval '1 second','2026-09-01'::timestamptz
FROM generate_series(1,2000000) n;
INSERT INTO follows (follower_id,followed_id,created_at)
SELECT pg_temp.dr_id('user',n),pg_temp.dr_id('user',1+(n+p)%50000),'2026-01-01'::timestamptz
FROM generate_series(1,50000) n CROSS JOIN generate_series(1,10) p;
-- Metadane oczekujących uploadów, nie fikcyjne zdjęcia ready. Bajtów zdjęć ta próba nie odtwarza.
INSERT INTO media (id,owner_id,disk,object_key,status,created_at,updated_at)
SELECT pg_temp.dr_id('media',n),pg_temp.dr_id('user',1+n%50000),'local',
       'dr594/nieistniejacy-upload/'||n,'pending','2026-09-01'::timestamptz,'2026-09-01'::timestamptz
FROM generate_series(1,250000) n;
INSERT INTO post_media (post_id,media_id,position)
SELECT pg_temp.dr_id('post',n),pg_temp.dr_id('media',n),0 FROM generate_series(1,250000) n;
COMMIT;
ANALYZE;
SELECT pg_database_size(current_database()) AS database_bytes;
