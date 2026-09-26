-- Zbiór do pomiaru #1309: raporty analityczne przy dużej historii zamkniętych kont.
-- WYŁĄCZNIE na lokalnej, pustej bazie po `php artisan migrate` (np. kuking_pomiar_1309).
-- Nigdy na produkcji ani na bazie współdzielonej — skrypt tylko dopisuje wiersze.
--
-- 3 000 aktywnych kont z bieżącą aktywnością (60 dni) i rejestracją w ostatnich 90 dniach,
-- :zamkniete zamkniętych (40% banned, 40% pending_delete, 20% erased; domyślnie 50 000)
-- z historią sprzed 1–3 lat i częścią aktywności w ostatnich 30 dniach (ta MUSI zostać
-- odcięta), 12 kont zalążkowych i gospodarz (`woogitsu`, domyślny `host_username`).
--
--   psql -v zamkniete=50000 -d kuking_pomiar_1309 -f scripts/pomiar-1309-dane.sql
\set ON_ERROR_STOP on
\if :{?zamkniete}
\else
  \set zamkniete 50000
\endif
BEGIN;
SELECT setseed(0.1309);

-- n: 1..3000 aktywne, 3001..3000+Z zamknięte, potem 12 zalążkowych i gospodarz.
CREATE TEMP TABLE konta AS
SELECT gen_random_uuid() AS id, n,
       CASE WHEN n <= 3000 THEN 'active'
            WHEN n <= 3000 + :zamkniete * 0.4 THEN 'banned'
            WHEN n <= 3000 + :zamkniete * 0.8 THEN 'pending_delete'
            WHEN n <= 3000 + :zamkniete THEN 'erased'
            ELSE 'active' END AS status,
       n > 3000 + :zamkniete AND n <= 3012 + :zamkniete AS seeded,
       n = 3013 + :zamkniete AS gospodarz
FROM generate_series(1, 3013 + :zamkniete) AS n;

INSERT INTO users (id, email, password, status, delete_requested_at, data_erased_at, delete_scope,
                   is_seeded, created_at, updated_at)
SELECT id, 'pomiar' || n || '@example.test', 'x', status,
       CASE WHEN status IN ('pending_delete', 'erased') THEN now() - interval '400 days' END,
       CASE WHEN status = 'erased' THEN now() - interval '370 days' END,
       CASE WHEN status = 'erased' THEN 'minimum' END,
       seeded,
       CASE WHEN n <= 3000 THEN now() - (random() * 90) * interval '1 day'
            ELSE now() - (365 + random() * 730) * interval '1 day' END,
       now()
FROM konta;

INSERT INTO profiles (user_id, username, display_name, created_at, updated_at)
SELECT id, CASE WHEN gospodarz THEN 'woogitsu' ELSE 'pomiar_' || n END, 'Osoba ' || n, now(), now()
FROM konta;

-- Jeden przepis na aktywne konto — cel dla wykonań.
INSERT INTO recipes (author_id, title, slug, visibility, status, published_at, created_at, updated_at)
SELECT id, 'Przepis ' || n, 'przepis-pomiar-' || n, 'public', 'published',
       now() - (random() * 60) * interval '1 day', now(), now()
FROM konta WHERE n <= 3000;

-- Wpisy: 3 na aktywne konto (60 dni), 2 na zamknięte (historia) + co dziesiąte zamknięte
-- ma wpis z ostatnich 30 dni, który raport musi odciąć.
INSERT INTO posts (author_id, body, visibility, status, published_at, created_at, updated_at)
SELECT k.id, 'Obiad ' || k.n, 'public', 'published',
       CASE WHEN k.n <= 3000 THEN now() - (random() * 60) * interval '1 day'
            WHEN g.i = 3 THEN now() - (random() * 30) * interval '1 day'
            ELSE now() - (365 + random() * 730) * interval '1 day' END,
       now(), now()
FROM konta k
CROSS JOIN generate_series(1, 3) AS g(i)
WHERE (k.n <= 3000)
   OR (k.n BETWEEN 3001 AND 3000 + :zamkniete AND (g.i < 3 OR k.n % 10 = 0))
   OR (k.seeded AND g.i = 1)
   OR (k.gospodarz);

-- Wykonania: 4 na aktywne konto, 1 na zamknięte (co piąte w ostatnich 30 dniach).
INSERT INTO cooked_events (user_id, recipe_id, cooked_at, created_at)
SELECT k.id, r.id,
       CASE WHEN k.n <= 3000 THEN now() - (random() * 60) * interval '1 day'
            WHEN k.n % 5 = 0 THEN now() - (random() * 30) * interval '1 day'
            ELSE now() - (365 + random() * 730) * interval '1 day' END,
       now()
FROM konta k
CROSS JOIN generate_series(1, 4) AS g(i)
JOIN LATERAL (SELECT id FROM recipes ORDER BY slug OFFSET ((k.n * 7 + g.i) % 3000) LIMIT 1) r ON true
WHERE (k.n <= 3000) OR (k.n BETWEEN 3001 AND 3000 + :zamkniete AND g.i = 1);

COMMIT;
ANALYZE users; ANALYZE profiles; ANALYZE posts; ANALYZE recipes; ANALYZE cooked_events;
SELECT status, count(*) FROM users GROUP BY status ORDER BY status;
SELECT (SELECT count(*) FROM posts) AS wpisy, (SELECT count(*) FROM recipes) AS przepisy,
       (SELECT count(*) FROM cooked_events) AS wykonania;
