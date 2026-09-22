\set ON_ERROR_STOP on
-- :nusers :nposts :nrecipes :nfollows  przekazywane przez -v

TRUNCATE collection_items, collections, cooked_events, post_tags, tags,
         comments, post_media, posts, recipes, profiles, media, blocks, follows, users CASCADE;

-- UŻYTKOWNICY: 90% active, reszta w stanach ukrywających treść
INSERT INTO users (id, email, password, status, email_verified_at, created_at)
SELECT
  ('00000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  'u' || i || '@example.test',
  '$2y$12$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123',
  CASE WHEN i % 100 < 90 THEN 'active'
       WHEN i % 100 < 95 THEN 'suspended'
       WHEN i % 100 < 98 THEN 'banned'
       WHEN i % 100 < 99 THEN 'pending_delete'
       ELSE 'erased' END,
  now() - (i % 700) * interval '1 day',
  now() - (i % 700) * interval '1 day'
FROM generate_series(1, :nusers) i;

INSERT INTO media (id, owner_id, object_key, mime_type, bytes, width, height, status, created_at)
SELECT
  ('10000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  ('00000000-0000-4000-8000-' || lpad(to_hex(1 + (i % :nusers)), 12, '0'))::uuid,
  'media/' || i || '.webp', 'image/webp', 200000 + i % 900000, 1600, 1200, 'ready',
  now() - (i % 700) * interval '1 day'
FROM generate_series(1, :nusers) i;

INSERT INTO profiles (user_id, username, display_name, bio, avatar_media_id, region, speciality)
SELECT
  ('00000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  'kucharz_' || i,
  (ARRAY['Anna','Krzysztof','Małgorzata','Zbigniew','Teresa','Ryszard','Jadwiga','Stanisław'])[1 + i % 8]
    || ' ' || (ARRAY['Kowalska','Nowak','Wiśniewski','Wójcik','Kamiński','Lewandowska'])[1 + i % 6],
  'Gotuję od ' || (1960 + i % 50) || ' roku. Najbardziej lubię ' ||
    (ARRAY['zupy','ciasta','pierogi','mięsa','przetwory','sałatki'])[1 + i % 6] || '.',
  ('10000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  (ARRAY['Mazowsze','Śląsk','Podlasie','Pomorze','Małopolska'])[1 + i % 5],
  (ARRAY['kuchnia domowa','wypieki','przetwory','kuchnia regionalna',NULL])[1 + i % 5]
FROM generate_series(1, :nusers) i;

-- PRZEPISY
INSERT INTO recipes (id, author_id, title, slug, summary, visibility, status, hero_media_id, published_at, created_at)
SELECT
  ('20000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  ('00000000-0000-4000-8000-' || lpad(to_hex(1 + (i % :nusers)), 12, '0'))::uuid,
  (ARRAY['Rosół','Bigos','Pierogi ruskie','Sernik','Schabowy','Żurek','Makowiec','Gołąbki'])[1 + i % 8]
    || ' babci ' || (ARRAY['Zosi','Halinki','Stasi','Marysi'])[1 + i % 4] || ' nr ' || i,
  'przepis-' || i,
  'Rodzinny przepis przekazywany od pokoleń. Sekret tkwi w ' ||
    (ARRAY['maśle','świeżym koperku','wolnym gotowaniu','domowym rosole'])[1 + i % 4] || '.',
  CASE WHEN i % 20 < 17 THEN 'public' WHEN i % 20 < 19 THEN 'followers' ELSE 'private' END,
  CASE WHEN i % 10 < 9 THEN 'published' ELSE 'draft' END,
  ('10000000-0000-4000-8000-' || lpad(to_hex(1 + (i % :nusers)), 12, '0'))::uuid,
  CASE WHEN i % 10 < 9 THEN now() - (i % 700) * interval '1 day' END,
  now() - (i % 700) * interval '1 day'
FROM generate_series(1, :nrecipes) i;

-- WPISY
INSERT INTO posts (id, author_id, body, visibility, status, recipe_id, published_at, created_at)
SELECT
  ('30000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  ('00000000-0000-4000-8000-' || lpad(to_hex(1 + (i % :nusers)), 12, '0'))::uuid,
  'Dziś ugotowałam ' || (ARRAY['rosół','bigos','pierogi','sernik','żurek'])[1 + i % 5]
    || '. Wyszło wyśmienicie, cała rodzina zadowolona. Wpis numer ' || i || '.',
  CASE WHEN i % 20 < 17 THEN 'public' WHEN i % 20 < 19 THEN 'followers' ELSE 'private' END,
  CASE WHEN i % 10 < 9 THEN 'published' ELSE 'draft' END,
  CASE WHEN i % 5 < 2 THEN ('20000000-0000-4000-8000-' || lpad(to_hex(1 + (i % :nrecipes)), 12, '0'))::uuid END,
  CASE WHEN i % 10 < 9 THEN now() - (i % 700) * interval '1 day' - (i % 1440) * interval '1 minute' END,
  now() - (i % 700) * interval '1 day'
FROM generate_series(1, :nposts) i;

-- ZDJĘCIA PRZY WPISACH: 0-2 na wpis
INSERT INTO post_media (post_id, media_id, position)
SELECT
  ('30000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  ('10000000-0000-4000-8000-' || lpad(to_hex(1 + ((i * 7 + p) % :nusers)), 12, '0'))::uuid,
  p
FROM generate_series(1, :nposts) i, generate_series(0, 1) p
WHERE (i + p) % 3 <> 2
ON CONFLICT DO NOTHING;

-- KOMENTARZE: średnio ~1.5 na wpis
INSERT INTO comments (author_id, post_id, body, status, created_at)
SELECT
  ('00000000-0000-4000-8000-' || lpad(to_hex(1 + ((i * 13 + c) % :nusers)), 12, '0'))::uuid,
  ('30000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  'Wygląda przepysznie! Muszę spróbować. (' || i || '/' || c || ')',
  CASE WHEN (i + c) % 50 = 0 THEN 'hidden' ELSE 'published' END,
  now() - (i % 700) * interval '1 day' + c * interval '1 hour'
FROM generate_series(1, :nposts) i, generate_series(0, 1) c
WHERE (i + c) % 4 <> 3;

-- TAGI
INSERT INTO tags (id, name, normalized_name, slug, status)
SELECT
  ('40000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  'tag' || i, 'tag' || i, 'tag-' || i, 'active'
FROM generate_series(1, 200) i;

INSERT INTO post_tags (post_id, tag_id, position)
SELECT
  ('30000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  ('40000000-0000-4000-8000-' || lpad(to_hex(1 + ((i * 17 + t) % 200)), 12, '0'))::uuid,
  t
FROM generate_series(1, :nposts) i, generate_series(0, 1) t
WHERE (i + t) % 3 <> 2
ON CONFLICT DO NOTHING;

-- OBSERWOWANIA: rozkład zwykły
INSERT INTO follows (follower_id, followed_id)
SELECT
  ('00000000-0000-4000-8000-' || lpad(to_hex(1 + (i % :nusers)), 12, '0'))::uuid,
  ('00000000-0000-4000-8000-' || lpad(to_hex(1 + ((i * 31 + 7) % :nusers)), 12, '0'))::uuid
FROM generate_series(1, :nfollows) i
WHERE (1 + (i % :nusers)) <> (1 + ((i * 31 + 7) % :nusers))
ON CONFLICT DO NOTHING;

-- BLOKADY: ~0.5% użytkowników
INSERT INTO blocks (blocker_id, blocked_id)
SELECT
  ('00000000-0000-4000-8000-' || lpad(to_hex(1 + (i % :nusers)), 12, '0'))::uuid,
  ('00000000-0000-4000-8000-' || lpad(to_hex(1 + ((i * 97 + 3) % :nusers)), 12, '0'))::uuid
FROM generate_series(1, greatest(:nusers / 200, 1)) i
WHERE (1 + (i % :nusers)) <> (1 + ((i * 97 + 3) % :nusers))
ON CONFLICT DO NOTHING;

-- ZESZYTY
INSERT INTO collections (id, owner_id, name)
SELECT
  ('50000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  ('00000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid,
  'Mój zeszyt'
FROM generate_series(1, :nusers) i;

INSERT INTO collection_items (collection_id, post_id)
SELECT
  ('50000000-0000-4000-8000-' || lpad(to_hex(1 + (i % :nusers)), 12, '0'))::uuid,
  ('30000000-0000-4000-8000-' || lpad(to_hex(i), 12, '0'))::uuid
FROM generate_series(1, least(:nposts, :nusers * 5)) i
WHERE i % 7 = 0
ON CONFLICT DO NOTHING;

-- WYKONANIA
INSERT INTO cooked_events (user_id, recipe_id, note, created_at)
SELECT
  ('00000000-0000-4000-8000-' || lpad(to_hex(1 + ((i * 11) % :nusers)), 12, '0'))::uuid,
  ('20000000-0000-4000-8000-' || lpad(to_hex(1 + (i % :nrecipes)), 12, '0'))::uuid,
  'Zrobione! Dziękuję za przepis.',
  now() - (i % 700) * interval '1 day'
FROM generate_series(1, :nrecipes * 2) i;
