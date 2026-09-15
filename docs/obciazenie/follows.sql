\set ON_ERROR_STOP on
TRUNCATE follows;
-- Każdy obserwuje 5..101 osób (ogon długi, jak w prawdziwym serwisie).
INSERT INTO follows (follower_id, followed_id)
SELECT ('00000000-0000-4000-8000-' || lpad(to_hex(u), 12, '0'))::uuid,
       ('00000000-0000-4000-8000-' || lpad(to_hex(tgt), 12, '0'))::uuid
FROM generate_series(1, :nusers) u,
     LATERAL generate_series(1, 5 + (u % 97)) j,
     LATERAL (SELECT 1 + ((u * 7919 + j * 104729) % :nusers) AS tgt) t
WHERE tgt <> u
ON CONFLICT DO NOTHING;

TRUNCATE blocks;
INSERT INTO blocks (blocker_id, blocked_id)
SELECT ('00000000-0000-4000-8000-' || lpad(to_hex(u), 12, '0'))::uuid,
       ('00000000-0000-4000-8000-' || lpad(to_hex(tgt), 12, '0'))::uuid
FROM generate_series(1, :nusers) u,
     LATERAL (SELECT 1 + ((u * 5749 + 3) % :nusers) AS tgt) t
WHERE u % 200 = 0 AND tgt <> u
ON CONFLICT DO NOTHING;
