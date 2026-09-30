## D-318 — Numer wersji z końcówką wdrożenia: dziennik `wdrozenia` w bazie, nie licznik z gita (issue #1932, 26 września 2026)

**Data:** 26 września 2026 · Status: **obowiązuje** · Decyzja właściciela

### Problem
`App\Support\Wersja::etykieta()` („Alfa 0.68") podbija się ręcznie, przy
większych zmianach — stoi tygodniami bez ruchu. Między dwoma podbiciami
ląduje na produkcji po kilkanaście wdrożeń dziennie, a stopka i strona
„Co nowego" (D-317) nie miały jak ich rozróżnić: dwa różne wdrożenia tego
samego dnia wyglądały identycznie, dopóki ktoś nie porównał skrótów commitów
z pamięci.

### Decyzja właściciela
1. **Format wersji:** `Alfa 0.69.001`. Duży numer (`0.69`, `0.70`…) podbija
   się ręcznie, przy większych zmianach — zasada się nie zmienia
   (`config/kuking.php`, komentarz nad `wersja.etykieta`, AGENTS.md §3).
   Końcówka `.001`, `.002`, `.003`… rośnie SAMA przy każdym wdrożeniu i
   wraca do `.001` przy nowym dużym wydaniu — to jest NOWA sekwencja, nie
   kontynuacja poprzedniej.
2. **Licznik NIE liczy się z historii gita.** Build Railwaya może mieć
   płytki klon — `git rev-list --count` liczyłby wtedy nie to, co trzeba,
   bez żadnego widocznego błędu (licząca się liczba po prostu byłaby zła).
   Zamiast tego: dziennik wdrożeń w bazie, dwie tabele —
   `wdrozenia` (który commit pod jakim numerem) i `wdrozenia_funkcje`
   (pod jakim numerem pojawiła się każda funkcja z „Najnowsze zmiany") —
   opisane w `docs/DATABASE.md`.
3. **Komenda w kroku wdrożenia**: `kuking:zarejestruj-wdrozenie`.
   **Dopisek 29 września 2026 (audyt z 28 września, #1932): NIE w
   `preDeployCommand`, tylko po gotowości nowego kontenera.** Pierwsza wersja
   wpięła ją w pre-deploy zaraz po migracjach, czyli przed seedem, importem
   i healthcheckiem — nieudany rollout zużywał numer, a funkcje z „Najnowszych
   zmian” dostawały trwały dopisek „od Alfa …” (globalnie unikalny
   `naglowek_slug` nie pozwalał go poprawić następnym, udanym wdrożeniem).
   Teraz `docker/entrypoint.sh` (role `web` i `all`) uruchamia w tle
   `kuking:zarejestruj-wdrozenie --po-gotowosci`, które czeka na 2xx z
   lokalnego `/health` (limit 300 s) i dopiero wtedy zapisuje; bez odpowiedzi
   nie zapisuje nic. Zamiast stanu „w toku” w schemacie — bez migracji:
   wiersz w `wdrozenia` znaczy „kontener wstał”. Nie opieramy się na
   `deployment_status` z platformy kodu (część rolloutów nie niesie zdarzenia
   sukcesu). Pilnują: `RejestracjaWdrozeniaPoGotowosciTest` i reguła
   w `scripts/railway/iac.test.mjs`. Opis pierwotny poniżej: komenda
   uruchamiana tam, gdzie dziś `migrate --force`, PO migracjach. Jeśli bieżący
   `RAILWAY_GIT_COMMIT_SHA` nie ma jeszcze wiersza, wstawia
   `numer = MAX(numer) dla tej etykiety + 1` pod
   `pg_advisory_xact_lock(hashtext(etykieta))` — dwa równoległe starty nie
   dają tego samego numeru (test na dwóch połączeniach:
   `tests/Dwa/RejestracjaWdrozeniaNaDwochPolaczeniachTest.php`). Jest
   idempotentna: ten sam commit drugi raz nie zużywa kolejnego numeru
   (`UNIQUE (commit)`).
4. **Przy tym samym przebiegu** komenda zapisuje, które nagłówki funkcji
   z `resources/nowosci/tresc.md` (sekcja „## Najnowsze zmiany") pojawiły
   się pierwszy raz — strona „Co nowego" pokazuje przy nich
   „_od Alfa 0.69.NNN_".
   **Dopisek doprecyzowany 26 września 2026, tego samego dnia (D-318,
   dopisek):** zostaje NA STAŁE. Gdy opis funkcji przechodzi z „Najnowsze
   zmiany" do sekcji nazwanego wydania (np. „## Alfa 0.69" po kolejnym
   podbiciu dużego numeru), strona „Co nowego" dalej pokazuje numer, pod
   którym funkcja pojawiła się PIERWSZY RAZ — nie znika i nie przeskakuje na
   numer bieżącego wdrożenia. Nagłówek nie zmienia tekstu (ani slugu) przy
   przenosinach, więc `wdrozenia_funkcje.naglowek_slug` jest `UNIQUE` SAM
   W SOBIE, nie para (etykieta, slug) — `ZarejestrujWdrozenie` dalej zapisuje
   nowe nagłówki WYŁĄCZNIE ze skanu „Najnowsze zmiany" (skanowanie już
   wydanych sekcji przy pierwszym uruchomieniu tej funkcji przypisałoby
   świeży numer funkcjom sprzed tygodni — patrz komentarz klasy), a
   `NowosciController` dopasowuje po samym slugu W CAŁYM dokumencie, biorąc
   etykietę i numer z WŁASNEGO wiersza nagłówka, nie z bieżącej
   `Wersja::etykieta()`.
5. **Stopka** (`App\Support\Wersja::etykietaZNumerem()`) pokazuje
   „Alfa 0.69.NNN · data · skrót commita", z cache'em (10 minut, klucz niesie
   commit — inne wdrożenie samo unieważnia poprzedni wpis). Bez wiersza
   w bazie (lokalnie, w testach, przy awarii bazy) zostaje dzisiejszy opis,
   bez błędu — `Wersja::etykieta()` SAMA zostaje bez końcówki, celowo: czyta
   ją dosłownie `PodbicieWersjiWymagaWpisuWChangelogTest`, porównując
   z nagłówkiem CHANGELOG-a w formacie „Alfa 0.N", bez żadnej końcówki.

### Rollback (D-088)
`down()` migracji, która zakłada obie tabele, ODMAWIA, gdy którakolwiek ma
choć jeden wiersz — numer wdrożenia jest wartością semantyczną, już
pokazaną ludziom (stopka, „od Alfa 0.69.NNN"), a cofnięcie na wypełnionej
bazie i kolejny `migrate` zacząłby liczyć numery od 1 dla każdej etykiety,
mieszając je ze starymi. Na świeżej bazie przechodzi bez pytania. Test
odmowy i kontrola dodatnia: `tests/Feature/DziennikWdrozenCofnieciePrzyWartosciachTest.php`.

### Dowody
`tests/Feature/ZarejestrujWdrozenieTest.php` (numeracja, idempotencja, mapa
funkcji), `tests/Dwa/RejestracjaWdrozeniaNaDwochPolaczeniachTest.php`
(bezpieczeństwo przy równoległym starcie, dwa prawdziwe połączenia),
`tests/Feature/DziennikWdrozenCofnieciePrzyWartosciachTest.php` (rollback),
`tests/Feature/WersjaWStopceTest.php` (numer w stopce, cache),
`tests/Feature/StronaCoNowegoOdNumeruTest.php` („od Alfa 0.NN.NNN" przy
funkcji, dopisek przeżywa przenosiny nagłówka do sekcji nazwanego wydania,
brak wiersza nie wywala strony i nic nie dokleja).

### Wycofanie
Usunąć komendę `kuking:zarejestruj-wdrozenie` z `preDeployCommand`, cofnąć
`Wersja::etykietaZNumerem()` do `Wersja::etykieta()` w stopce (D-088:
migracja sama się nie cofa na wypełnionej bazie — patrz sekcja Rollback
wyżej). Strona „Co nowego" wraca do samych nagłówków bez dopisku „od …".
