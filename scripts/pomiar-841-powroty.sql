-- Pomiar #841 (krok właściciela): ile razy człowiek WRACA do sprawy, którą już załatwiono.
--
-- SAME AGREGATY. Zapytanie nie wypisuje treści, adresów, identyfikatorów kont ani
-- wierszy — tylko liczby. Nie kopiuj do raportu niczego poza tymi liczbami.
-- Transakcja tylko do odczytu; nic nie zapisuje, nie zakłada indeksów i niczego
-- nie loguje. Wolno je uruchomić na kopii bazy albo na produkcji (odczyt).
--
--   psql "$DATABASE_URL" -X -f scripts/pomiar-841-powroty.sql
--
-- CO ZNACZY „POWRÓT”: wiadomość N tej samej osoby przyszła PO tym, jak jej
-- wcześniejsza wiadomość została załatwiona (status `done`, `handled_at` przed
-- `created_at` wiadomości N). „Ta sama osoba” to konto (`user_id`) albo, dla gościa,
-- ten sam adres `contact_email` (bez wielkości liter). Konto i gość z tym samym
-- adresem liczą się jako dwie osoby, a konto wymazane (`user_id` = NULL, adres
-- usunięty) nie jest widoczne wcale — liczba powrotów jest więc DOLNYM oszacowaniem.
--
-- OGRANICZENIE RETENCJI: załatwione wiadomości znikają po 12 miesiącach
-- (`kuking:sprzataj-wiadomosci`), więc widać najwyżej rok wstecz. Nie wydłużaj
-- retencji na potrzeby pomiaru (issue #841).
BEGIN READ ONLY;

WITH osoby AS (
    SELECT id, created_at, handled_at, status,
           coalesce(user_id::text, 'adres:' || lower(btrim(contact_email))) AS klucz
    FROM contact_messages
    WHERE user_id IS NOT NULL
       OR (contact_email IS NOT NULL AND btrim(contact_email) <> '')
),
powroty AS (
    SELECT n.id,
           n.created_at - (
               SELECT max(w.handled_at)
               FROM osoby w
               WHERE w.klucz = n.klucz
                 AND w.id <> n.id
                 AND w.status = 'done'
                 AND w.handled_at < n.created_at
           ) AS odstep
    FROM osoby n
)
SELECT
    (SELECT count(*) FROM contact_messages)                                   AS wiadomosci_razem,
    (SELECT count(*) FROM osoby)                                              AS wiadomosci_z_rozpoznana_osoba,
    (SELECT count(DISTINCT klucz) FROM osoby)                                 AS osob,
    (SELECT count(*) FROM (SELECT klucz FROM osoby GROUP BY klucz HAVING count(*) >= 2) x) AS osob_z_wiecej_niz_jedna_wiadomoscia,
    count(*) FILTER (WHERE odstep IS NOT NULL)                                AS powrotow_razem,
    count(*) FILTER (WHERE odstep <= interval '7 days')                       AS powrotow_do_7_dni,
    count(*) FILTER (WHERE odstep > interval '7 days'  AND odstep <= interval '30 days') AS powrotow_8_do_30_dni,
    count(*) FILTER (WHERE odstep > interval '30 days' AND odstep <= interval '90 days') AS powrotow_31_do_90_dni,
    count(*) FILTER (WHERE odstep > interval '90 days')                       AS powrotow_ponad_90_dni,
    (SELECT min(created_at)::date FROM contact_messages)                      AS najstarsza_wiadomosc
FROM powroty;

ROLLBACK;
