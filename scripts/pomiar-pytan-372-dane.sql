-- Pomiar licznika „Czeka na odpowiedź (N)” (#372) — schemat i dane syntetyczne.
--
-- Uruchamia `scripts/pomiar-pytan-372.py` na WŁASNEJ, jednorazowej bazie.
-- Nie jest migracją i nie wolno go puszczać na bazie aplikacji: tworzy
-- od zera PODZBIÓR tabel (users, posts, comments, blocks, follows, tags,
-- post_tags) z tymi kolumnami, CHECK-ami i indeksami, które czyta zapytanie
-- licznika. Definicje indeksów przepisane 1:1 z migracji:
--   posts_author_published_idx, posts_published_idx   2026_09_05_000500
--   posts_recipe_idx                                  2026_09_25_100000_indeksy_kluczy_obcych…
--   comments_post_idx, comments_author_idx, parent_id 2026_09_05_000700
--   follows PK + follows_followed_idx, blocks PK + blocks_blocked_idx 2026_09_05_000300
--   post_tags PK + tag_id + (post_id, position), tags.slug UNIQUE 2026_09_07_100000
-- Kolumny, których zapytanie nie dotyka (topic_id, display_mode, …), są
-- pominięte — zmieniają szerokość wiersza, nie plan. Patrz „Czego nie
-- sprawdzono” w docs/product/WLACZENIE_PYTAN_372.md.
--
-- Rozkład jest DETERMINISTYCZNY (md5 z numeru wiersza), więc każde
-- uruchomienie daje te same dane i porównywalne plany.
\set ON_ERROR_STOP on
SET timezone = 'UTC';

DO $$ BEGIN
  IF current_database() NOT LIKE 'kuking_pomiar_pytan_%' THEN
    RAISE EXCEPTION 'Odmowa: baza % nie jest jednorazową bazą pomiaru (kuking_pomiar_pytan_*).', current_database();
  END IF;
  IF to_regclass('public.posts') IS NOT NULL THEN
    RAISE EXCEPTION 'Odmowa: w bazie % są już tabele. Pomiar tworzy świeżą bazę za każdym razem.', current_database();
  END IF;
END $$;

-- Pseudolosowa liczba 0..999, stała dla (sól, n).
CREATE FUNCTION pg_temp.h(salt text, n bigint) RETURNS int
LANGUAGE sql IMMUTABLE AS $$ SELECT (('x' || substr(md5(salt || ':' || n), 1, 7))::bit(28)::int % 1000) $$;
CREATE FUNCTION pg_temp.uid(kind text, n bigint) RETURNS uuid
LANGUAGE sql IMMUTABLE AS $$ SELECT md5('p372:' || kind || ':' || n)::uuid $$;

CREATE TABLE users (
    id uuid PRIMARY KEY,
    status varchar(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active','suspended','banned','pending_delete','erased')),
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE posts (
    id uuid PRIMARY KEY,
    author_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body varchar(4000),
    visibility varchar(20) NOT NULL DEFAULT 'public' CHECK (visibility IN ('public','followers','private')),
    status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','hidden','removed')),
    recipe_id uuid,
    published_at timestamptz,
    created_at timestamptz,
    updated_at timestamptz,
    deleted_at timestamptz,
    kind varchar(20) NOT NULL DEFAULT 'dish',
    title varchar(180),
    CONSTRAINT posts_kind_check CHECK (kind IN ('dish', 'question')),
    CONSTRAINT posts_kind_title_check CHECK (
        (kind = 'dish' AND title IS NULL)
        OR (kind = 'question' AND title IS NOT NULL
            AND char_length(btrim(title, E' \t\n\r' || chr(11))) BETWEEN 10 AND 180))
);
CREATE INDEX posts_author_published_idx ON posts (author_id, published_at DESC, id DESC) WHERE deleted_at IS NULL;
CREATE INDEX posts_published_idx ON posts (published_at DESC, id DESC) WHERE deleted_at IS NULL AND status = 'published' AND visibility = 'public';
CREATE INDEX posts_recipe_idx ON posts (recipe_id) WHERE recipe_id IS NOT NULL;

CREATE TABLE comments (
    id uuid PRIMARY KEY,
    author_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    post_id uuid REFERENCES posts(id) ON DELETE CASCADE,
    parent_id uuid REFERENCES comments(id) ON DELETE CASCADE,
    body varchar(4000) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'published' CHECK (status IN ('published','hidden','removed')),
    created_at timestamptz,
    updated_at timestamptz,
    deleted_at timestamptz,
    body_removed_at timestamptz
);
CREATE INDEX comments_parent_id_index ON comments (parent_id);
CREATE INDEX comments_post_idx ON comments (post_id, created_at);
CREATE INDEX comments_author_idx ON comments (author_id, created_at DESC);

CREATE TABLE follows (
    follower_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    followed_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at timestamptz,
    PRIMARY KEY (follower_id, followed_id)
);
CREATE INDEX follows_followed_idx ON follows (followed_id, created_at);
CREATE TABLE blocks (
    blocker_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    blocked_id uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at timestamptz,
    PRIMARY KEY (blocker_id, blocked_id)
);
CREATE INDEX blocks_blocked_idx ON blocks (blocked_id);

CREATE TABLE tags (
    id uuid PRIMARY KEY,
    name varchar(30) NOT NULL,
    slug varchar(40) NOT NULL UNIQUE,
    status varchar(10) NOT NULL DEFAULT 'active'
);
CREATE TABLE post_tags (
    post_id uuid NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    tag_id uuid NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    position smallint NOT NULL DEFAULT 0,
    PRIMARY KEY (post_id, tag_id),
    UNIQUE (post_id, position)
);
CREATE INDEX post_tags_tag_id_index ON post_tags (tag_id);

-- ---------------------------------------------------------------------------
-- Ludzie: 20 000 kont + widz pomiaru (nr 0). Konta 1–50 zbanowane, 51–100
-- zgłoszone do usunięcia (autorzy odpowiedzi, które NIE liczą się jako odzew),
-- reszta: 98,5% aktywne, 1% zawieszone, 0,5% zbanowane.
-- ---------------------------------------------------------------------------
INSERT INTO users (id, status)
SELECT pg_temp.uid('user', n),
       CASE WHEN n = 0 THEN 'active'
            WHEN n <= 50 THEN 'banned'
            WHEN n <= 100 THEN 'pending_delete'
            WHEN pg_temp.h('us', n) < 985 THEN 'active'
            WHEN pg_temp.h('us', n) < 995 THEN 'suspended'
            ELSE 'banned' END
FROM generate_series(0, 20000) n;

-- ---------------------------------------------------------------------------
-- Wpisy: 200 000, co dwudziesty to pytanie (5% = 10 000). Rok publikacji,
-- 85% publicznych / 10% dla obserwujących / 5% prywatnych, 95% opublikowanych,
-- 1% miękko usuniętych. Autorzy 101–20000 (bez kont zbanowanych z góry).
-- ---------------------------------------------------------------------------
INSERT INTO posts (id, author_id, body, visibility, status, published_at, created_at, updated_at, deleted_at, kind, title)
SELECT pg_temp.uid('post', n),
       pg_temp.uid('user', 101 + (pg_temp.h('pa', n) * 20 + pg_temp.h('pb', n)) % 19900),
       'Dziś ugotowałam coś dobrego — wpis próbny ' || n,
       CASE WHEN pg_temp.h('pv', n) < 850 THEN 'public' WHEN pg_temp.h('pv', n) < 950 THEN 'followers' ELSE 'private' END,
       CASE WHEN pg_temp.h('ps', n) < 950 THEN 'published' WHEN pg_temp.h('ps', n) < 975 THEN 'draft'
            WHEN pg_temp.h('ps', n) < 990 THEN 'hidden' ELSE 'removed' END,
       CASE WHEN pg_temp.h('ps', n) BETWEEN 950 AND 974 THEN NULL
            ELSE '2025-09-25'::timestamptz + n * interval '157 seconds' END,
       '2025-09-25'::timestamptz + n * interval '157 seconds',
       '2025-09-25'::timestamptz + n * interval '157 seconds',
       CASE WHEN pg_temp.h('pd', n) < 10 THEN '2026-09-01'::timestamptz END,
       CASE WHEN n % 20 = 0 THEN 'question' ELSE 'dish' END,
       CASE WHEN n % 20 = 0 THEN 'Jak uratować przesoloną zupę? Pytanie ' || n END
FROM generate_series(1, 200000) n;

-- Komentarze pod daniami: 0–4 na danie (średnio 2), wszystkie główne.
INSERT INTO comments (id, author_id, post_id, body, status, created_at, updated_at)
SELECT pg_temp.uid('dc', n * 10 + k), pg_temp.uid('user', 101 + pg_temp.h('dca', n * 10 + k) * 19 % 19900),
       pg_temp.uid('post', n), 'Wygląda pysznie!', 'published',
       '2025-09-25'::timestamptz + n * interval '157 seconds' + k * interval '2 hours',
       '2025-09-25'::timestamptz + n * interval '157 seconds' + k * interval '2 hours'
FROM generate_series(1, 200000) n
CROSS JOIN LATERAL generate_series(1, pg_temp.h('dcn', n) % 5) k
WHERE n % 20 <> 0;

-- ---------------------------------------------------------------------------
-- Pytania — koszyki odzewu (h('qa', n)):
--   0–549   55%  1–4 widoczne główne odpowiedzi innych osób
--   550–749 20%  zero komentarzy
--   750–849 10%  tylko dopiski pod komentarzem (zagnieżdżone) — to nie odpowiedź
--   850–919  7%  odpowiedzi ukryte / miękko usunięte / z usuniętą treścią
--   920–969  5%  odpowiedzi tylko od kont zbanowanych lub w usuwaniu
--   970–999  3%  odpowiedzi tylko od osób w blokadzie z widzem pomiaru
-- ---------------------------------------------------------------------------
CREATE TEMP TABLE q AS
SELECT n, pg_temp.uid('post', n) AS post_id, pg_temp.h('qa', n) AS b,
       '2025-09-25'::timestamptz + n * interval '157 seconds' AS t
FROM generate_series(20, 200000, 20) n;

INSERT INTO comments (id, author_id, post_id, body, status, created_at, updated_at)
SELECT pg_temp.uid('qa', n * 10 + k), pg_temp.uid('user', 101 + pg_temp.h('qaa', n * 10 + k) * 19 % 19900),
       post_id, 'Dodaj surowego ziemniaka i gotuj jeszcze 10 minut.', 'published',
       t + k * interval '3 hours', t + k * interval '3 hours'
FROM q CROSS JOIN LATERAL generate_series(1, 1 + b % 4) k WHERE b < 550;

-- Koszyk dopisków: główny komentarz jest ukryty, pod nim widoczna odpowiedź.
INSERT INTO comments (id, author_id, post_id, body, status, created_at, updated_at)
SELECT pg_temp.uid('qp', n), pg_temp.uid('user', 5000 + n % 1000), post_id, 'Też mnie to ciekawi.', 'hidden', t + interval '1 hour', t + interval '1 hour'
FROM q WHERE b BETWEEN 750 AND 849;
INSERT INTO comments (id, author_id, post_id, parent_id, body, status, created_at, updated_at)
SELECT pg_temp.uid('qr', n), pg_temp.uid('user', 6000 + n % 1000), post_id, pg_temp.uid('qp', n), 'Mnie też.', 'published', t + interval '2 hours', t + interval '2 hours'
FROM q WHERE b BETWEEN 750 AND 849;

INSERT INTO comments (id, author_id, post_id, body, status, created_at, updated_at, deleted_at, body_removed_at)
SELECT pg_temp.uid('qh', n), pg_temp.uid('user', 7000 + n % 1000), post_id, 'Odpowiedź usunięta',
       CASE WHEN b % 3 = 0 THEN 'hidden' ELSE 'published' END,
       t + interval '1 hour', t + interval '1 hour',
       CASE WHEN b % 3 = 1 THEN '2026-09-01'::timestamptz END,
       CASE WHEN b % 3 = 2 THEN '2026-09-01'::timestamptz END
FROM q WHERE b BETWEEN 850 AND 919;

INSERT INTO comments (id, author_id, post_id, body, status, created_at, updated_at)
SELECT pg_temp.uid('qb', n), pg_temp.uid('user', 1 + n % 100), post_id, 'Odpowiedź konta wyłączonego', 'published', t + interval '1 hour', t + interval '1 hour'
FROM q WHERE b BETWEEN 920 AND 969;

-- Widz pomiaru blokuje konta 8001–8100, konta 8101–8200 blokują jego.
INSERT INTO comments (id, author_id, post_id, body, status, created_at, updated_at)
SELECT pg_temp.uid('qx', n), pg_temp.uid('user', 8001 + n % 200), post_id, 'Odpowiedź osoby w blokadzie', 'published', t + interval '1 hour', t + interval '1 hour'
FROM q WHERE b >= 970;

-- ---------------------------------------------------------------------------
-- Relacje: 5 000 losowych blokad + 200 widza, ~200 000 obserwacji + 500 widza.
-- ---------------------------------------------------------------------------
INSERT INTO blocks (blocker_id, blocked_id, created_at)
SELECT pg_temp.uid('user', 0), pg_temp.uid('user', n), now() FROM generate_series(8001, 8100) n
UNION ALL
SELECT pg_temp.uid('user', n), pg_temp.uid('user', 0), now() FROM generate_series(8101, 8200) n;
INSERT INTO blocks (blocker_id, blocked_id, created_at)
SELECT pg_temp.uid('user', 101 + pg_temp.h('bl', n) * 19 % 19900), pg_temp.uid('user', 101 + pg_temp.h('bd', n) * 17 % 19900), now()
FROM generate_series(1, 5000) n
ON CONFLICT DO NOTHING;

INSERT INTO follows (follower_id, followed_id, created_at)
SELECT pg_temp.uid('user', 0), pg_temp.uid('user', 101 + n * 37 % 19900), now() FROM generate_series(1, 500) n
ON CONFLICT DO NOTHING;
INSERT INTO follows (follower_id, followed_id, created_at)
SELECT pg_temp.uid('user', 101 + (n * 7919) % 19900), pg_temp.uid('user', 101 + pg_temp.h('fd', n) * 19 % 19900), now()
FROM generate_series(1, 200000) n
WHERE (n * 7919) % 19900 <> pg_temp.h('fd', n) * 19 % 19900
ON CONFLICT DO NOTHING;

-- ---------------------------------------------------------------------------
-- Tagi: 600, 5% ukrytych. Rozkład skośny (tag-0 najpopularniejszy),
-- 0–3 tagi na wpis.
-- ---------------------------------------------------------------------------
INSERT INTO tags (id, name, slug, status)
SELECT pg_temp.uid('tag', n), 'Tag ' || n, 'tag-' || n, CASE WHEN n % 20 = 19 THEN 'hidden' ELSE 'active' END
FROM generate_series(0, 599) n;
INSERT INTO post_tags (post_id, tag_id, position)
SELECT pg_temp.uid('post', n), pg_temp.uid('tag', floor(600 * power(pg_temp.h('pt', n * 10 + k) / 1000.0, 3))::int), k
FROM generate_series(1, 200000) n
CROSS JOIN LATERAL generate_series(1, pg_temp.h('ptn', n) % 4) k
ON CONFLICT DO NOTHING;

VACUUM ANALYZE;

SELECT 'posts' AS tabela, count(*) AS wiersze FROM posts
UNION ALL SELECT 'posts (pytania)', count(*) FROM posts WHERE kind = 'question'
UNION ALL SELECT 'comments', count(*) FROM comments
UNION ALL SELECT 'users', count(*) FROM users
UNION ALL SELECT 'follows', count(*) FROM follows
UNION ALL SELECT 'blocks', count(*) FROM blocks
UNION ALL SELECT 'post_tags', count(*) FROM post_tags
UNION ALL SELECT 'post_tags z tag-0', count(*) FROM post_tags WHERE tag_id = pg_temp.uid('tag', 0);
