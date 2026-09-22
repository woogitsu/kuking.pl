\set ON_ERROR_STOP on
-- Sondy: użytkownicy o jawnie ustalonej liczbie obserwowanych.
-- To jest oś, wzdłuż której rośnie koszt feedu obserwowanych (whereIn z PHP).
DELETE FROM follows WHERE follower_id IN (
  ('00000000-0000-4000-8000-' || lpad(to_hex(1), 12, '0'))::uuid,
  ('00000000-0000-4000-8000-' || lpad(to_hex(2), 12, '0'))::uuid,
  ('00000000-0000-4000-8000-' || lpad(to_hex(3), 12, '0'))::uuid,
  ('00000000-0000-4000-8000-' || lpad(to_hex(4), 12, '0'))::uuid
);

INSERT INTO follows (follower_id, followed_id)
SELECT ('00000000-0000-4000-8000-' || lpad(to_hex(p.who), 12, '0'))::uuid,
       ('00000000-0000-4000-8000-' || lpad(to_hex(5 + i), 12, '0'))::uuid
FROM (VALUES (1, 20), (2, 200), (3, 2000), (4, 10000)) AS p(who, cnt),
     LATERAL generate_series(1, least(p.cnt, :nusers - 10)) i
ON CONFLICT DO NOTHING;

ANALYZE;
