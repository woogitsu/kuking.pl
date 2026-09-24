-- Diagnostyka grafu scaleń tagów (issue #996) — TYLKO ODCZYT.
--
-- Uruchom, gdy migracja 2026_09_24_100000_scalenia_tagow_jednym_skokiem_do_aktywnego
-- odmówi. Skrypt niczego nie zmienia (transakcja READ ONLY). Wynik to lista
-- tagów scalonych, które NIE prowadzą jednym skokiem do aktywnego tagu,
-- z pełną ścieżką po `merged_into_tag_id` i oceną, czym jest problem.
--
-- Naprawa to decyzja redakcyjna („dokąd ma prowadzić stary adres”), nie
-- część tego skryptu. Typowa poprawka łańcucha A → B → C to przepięcie A
-- bezpośrednio na C; pętli i cyklu — wybór jednego tagu jako aktywnego.
--
--   psql "$DATABASE_URL" -f docs/diagnostyka/996_graf_scalen_tagow.sql

BEGIN TRANSACTION READ ONLY;

WITH RECURSIVE sciezka AS (
    SELECT
        t.id AS start_id,
        t.merged_into_tag_id AS nastepny_id,
        ARRAY[t.id] AS odwiedzone,
        ARRAY[t.slug::text] AS slugi,
        false AS cykl
    FROM tags AS t
    JOIN tags AS cel ON cel.id = t.merged_into_tag_id
    WHERE t.merged_into_tag_id = t.id OR cel.status <> 'active'

    UNION ALL

    SELECT
        s.start_id,
        n.merged_into_tag_id,
        s.odwiedzone || n.id,
        s.slugi || n.slug::text,
        n.id = ANY (s.odwiedzone)
    FROM sciezka AS s
    JOIN tags AS n ON n.id = s.nastepny_id
    WHERE NOT s.cykl
      AND cardinality(s.odwiedzone) < 50
)
SELECT DISTINCT ON (s.start_id)
    start.slug AS tag_scalony,
    array_to_string(s.slugi, ' → ') AS sciezka,
    CASE
        WHEN start.merged_into_tag_id = start.id THEN 'scalony sam w siebie'
        WHEN s.cykl THEN 'cykl'
        WHEN koniec.status = 'hidden' THEN 'kończy się tagiem ukrytym'
        WHEN cardinality(s.odwiedzone) > 2 THEN 'łańcuch'
        ELSE 'inny'
    END AS problem,
    koniec.slug AS ostatni_tag,
    koniec.status AS status_ostatniego
FROM sciezka AS s
JOIN tags AS start ON start.id = s.start_id
JOIN tags AS koniec ON koniec.id = s.odwiedzone[cardinality(s.odwiedzone)]
ORDER BY s.start_id, cardinality(s.odwiedzone) DESC;

ROLLBACK;
