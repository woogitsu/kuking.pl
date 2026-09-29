-- Pomiar #870 (krok właściciela): ile pytań w Poradźcie jest naprawdę do odnalezienia.
--
-- SAME AGREGATY z publicznych pytań (te, które widzi gość: opublikowane, nieusunięte,
-- widoczność „public”, autor aktywny). Bez tytułów, treści i identyfikatorów.
-- Transakcja tylko do odczytu; nic nie zapisuje.
--
--   psql "$DATABASE_URL" -X -f scripts/pomiar-870-liczba-pytan.sql
--
-- Po co: jeśli publicznych pytań jest tyle, ile mieści się na jednej-dwóch stronach
-- listy /pytania (`kuking.feed.page_size`), szukanie po tytule nie ma czego szukać
-- i pomysł zostaje odłożony. Liczby dla 2 000 i 20 000 pytań:
-- docs/pomiary/870-pytania-wyszukiwanie.md.
BEGIN READ ONLY;

WITH publiczne AS (
    SELECT p.id, p.body, p.title, p.published_at
    FROM posts p
    JOIN users u ON u.id = p.author_id AND u.status = 'active'
    WHERE p.kind = 'question'
      AND p.status = 'published'
      AND p.published_at IS NOT NULL
      AND p.deleted_at IS NULL
      AND p.visibility = 'public'
)
SELECT
    count(*)                                                        AS pytan_publicznych,
    count(*) FILTER (WHERE body IS NULL)                            AS bez_opisu,
    count(*) FILTER (WHERE published_at > now() - interval '30 days') AS z_ostatnich_30_dni,
    count(*) FILTER (WHERE published_at > now() - interval '90 days') AS z_ostatnich_90_dni,
    round(avg(char_length(title)))                                  AS srednia_dlugosc_tytulu,
    min(published_at)::date                                         AS najstarsze
FROM publiczne;

ROLLBACK;
