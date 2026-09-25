-- Issue #954 — odpowiedzi niezgodne z rodzicem. TYLKO ODCZYT.
--
-- Uruchom, gdy migracja 2026_09_24_100000_odpowiedz_dotyczy_tej_samej_tresci_co_rodzic
-- odmówiła założenia wyzwalacza. Skrypt niczego nie zmienia: transakcja jest
-- READ ONLY i kończy się ROLLBACK.
--
--   psql "$DATABASE_URL" -f docs/diagnostyka/954_odpowiedzi_niezgodne_z_rodzicem.sql
--
-- Kolumna `problem`:
--   sama_sobie    — parent_id = id;
--   drugi_poziom  — rodzic sam jest odpowiedzią;
--   inna_tresc    — odpowiedź i rodzic wskazują inny wpis / przepis / wykonanie.
--
-- Poprawny cel ustala człowiek na podstawie treści, dat i powiadomień.
-- Nie przepinaj rozmów automatycznie.

BEGIN TRANSACTION READ ONLY;

SELECT
    CASE
        WHEN odp.parent_id = odp.id THEN 'sama_sobie'
        WHEN rodzic.parent_id IS NOT NULL THEN 'drugi_poziom'
        ELSE 'inna_tresc'
    END AS problem,
    odp.id AS odpowiedz_id,
    odp.created_at AS odpowiedz_utworzona,
    odp.deleted_at AS odpowiedz_skasowana,
    odp.post_id AS odpowiedz_post_id,
    odp.recipe_id AS odpowiedz_recipe_id,
    odp.cooked_event_id AS odpowiedz_cooked_event_id,
    rodzic.id AS rodzic_id,
    rodzic.parent_id AS rodzic_parent_id,
    rodzic.post_id AS rodzic_post_id,
    rodzic.recipe_id AS rodzic_recipe_id,
    rodzic.cooked_event_id AS rodzic_cooked_event_id
FROM comments odp
JOIN comments rodzic ON rodzic.id = odp.parent_id
WHERE odp.parent_id = odp.id
   OR rodzic.parent_id IS NOT NULL
   OR rodzic.post_id IS DISTINCT FROM odp.post_id
   OR rodzic.recipe_id IS DISTINCT FROM odp.recipe_id
   OR rodzic.cooked_event_id IS DISTINCT FROM odp.cooked_event_id
ORDER BY problem, odp.created_at;

ROLLBACK;
