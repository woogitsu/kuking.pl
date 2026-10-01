## D-280 — Reakcja „Smakowicie wygląda”: bez licznika, zbiorczo raz dziennie (#1813, #1781, 25 września 2026)

**Data:** 25 września 2026 · Decyzja właściciela (#1781, kryteria #1813) · Status: **obowiązuje**

### Decyzja

Lżejsza reakcja niż „Ugotowałem”, o nazwie **„Smakowicie wygląda”** (D-194 —
„Ugotowałem” stoi wyżej):

- przycisk drugiego planu na karcie cudzego wpisu, za komentarzami; cofnięcie
  tym samym przyciskiem („Smakowicie wygląda — cofnij”), bez pytania, bez JS;
- **bez licznika** — nikt, także autor, nie widzi liczby; na stronie wpisu
  **każdy widz** (także niezalogowany) widzi, KTO napisał — nazwy dosłownie,
  bez osób z blokadą autora albo widza i bez kont niedostępnych (zmiana
  26 września 2026, dopisek niżej);
- **powiadomienie zbiorczo raz dziennie** (17:47 czasu polskiego — `->timezone(Czas::strefa())`, `kuking:powiadom-smakowicie`):
  jedno na autora, „N osób napisało: Smakowicie wygląda”, liczy różne osoby, bez
  zablokowanych i nieaktywnych; tylko w serwisie, bez poczty. „Ugotowałem”
  powiadamia od razu i zostaje najcenniejszą wiadomością (AGENTS.md §1);
- **nigdy nie sortuje i nie przycina list** (D-275) — strażnik zna słowa
  „smakowic”, „reakcj” i „reaction” (tabela `post_reactions`);
- pod własnym wpisem przycisku nie ma; blokady działają w obie strony (Policy
  wpisu i akcja).

„Respektuje ustawienia powiadomień”: jedynym ustawieniem powiadomień w serwisie
jest zgoda na tygodniowy list (AGENTS.md §1), która tych powiadomień nie dotyczy;
obowiązują granice `NotifyUser` (konto, które nie może czytać, nic nie dostaje).

Dane: tabela `post_reactions` (docs/DATABASE.md), rollback odmawia przy
niepustej tabeli (D-088). Eksport: `moje_reakcje`, `reakcje_otrzymane`.
Reakcje nie są źródłem analityki (#1814).

> **Dopisek (26 września 2026, decyzja właściciela, #1781).** Lista osób,
> które napisały „Smakowicie wygląda”, jest widoczna dla **wszystkich** pod
> wpisem (strona wpisu), nie tylko dla autora. Nadal bez licznika i bez
> „i N innych”. Filtry: te same co dotąd dla autora (konto dostępne jako
> autor, bez blokady z autorem w którąkolwiek stronę) oraz — dla
> zalogowanego widza — bez osób, z którymi ma blokadę w którąkolwiek stronę.
> Komunikat po reakcji mówi wprost: „Twoja nazwa jest teraz pod tym wpisem —
> widzą ją wszyscy”. Polityka prywatności (#1816) opisuje, że nazwa
> reagującego jest publiczna pod wpisem. Eksport bez zmian (`reakcje_otrzymane`
> filtruje po autorze). Test:
> `SmakowicieWygladaTest::test_kazdy_widzi_kto_napisal_bez_liczby_a_blokady_autora_i_widza_odcinaja`.

### Wycofanie

Wymaga decyzji, co z zapisanymi reakcjami (rollback migracji odmawia).

📄 `app/Domain/Reakcje/Smakowicie.php` · `app/Domain/Reakcje/PowiadomOSmakowicie.php` ·
`tests/Feature/SmakowicieWygladaTest.php` · D-194 · D-275
