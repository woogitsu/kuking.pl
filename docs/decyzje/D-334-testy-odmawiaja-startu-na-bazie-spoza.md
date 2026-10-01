## D-334 — Testy odmawiają startu na bazie spoza rodziny testowej (#966, 29 września 2026)

**Decyzja właściciela z 29 września 2026: tak.** PHPUnit nie rusza bazy, która
nie należy do rodziny testowej, żeby `RefreshDatabase` nie zrzucił schematu
bazy deweloperskiej (`kuking`), produkcyjnej ani cudzej. Numer D-334 zastępuje
D-228 ze starej gałęzi `flota/scal-786` (PR #966, daleko za `main`, nie scalany).
Z #966 przeniesiono ręcznie tylko bezpiecznik; reguła nazw baz testowych była
już na `main` (`tests/Support/kuking_nazwa_testowej_bazy.php`).

**Co robi.** `tests/bootstrap.php`, po wyliczeniu `DB_DATABASE` i przed
`vendor/autoload.php`, woła `kuking_wymus_baze_testowa()`
(`tests/Support/kuking_bezpiecznik_bazy_testowej.php`). Jeśli `DB_URL` jest
niepusty, ocenia nazwę bazy z niego (`DB_URL` przebija `DB_DATABASE`, a nieczytelny
adres to odmowa); inaczej ocenia `DB_DATABASE`. Odmowa kończy proces kodem 2
i wypisuje po polsku, co zrobić (`unset DB_DATABASE DB_URL`). Hasła z adresu nie ma
w komunikacie. Nic nie łączy się z bazą.

**Rodzina (lista zgód, nie zakazów; porównanie z rozróżnianiem wielkości liter):**

| wzorzec | skąd |
|---|---|
| `kuking_test`, `kuking_test_<worktree>`, `kuking_test_kat_…` | zwykły przebieg, job `test` i `dostepnosc` w CI |
| `kuking_race`, `kuking_race_<sufiks>` | grupa `dwa-polaczenia` (D-105), job `dwa-polaczenia` |
| `kuking_flota_<stanowisko>` (myślnik dozwolony) | lokalne kontrole ujemne i skrypty stanowisk floty |

Poza rodziną celowo: `kuking`, `railway*`, bazy systemowe i bazy przyrządów
`kuking_581_*`, `kuking_port_*`, `kuking_a11y`, `kuking_wydajnosc`, `*_pomiar`,
`kuking_qa_*`. Obsługują skrypty Node i `artisan` (`migrate:fresh --seed`, fixtury),
nigdzie nie uruchamiają PHPUnita (sprawdzone grepem po `.github`, `scripts/`,
`tests/`). Gdyby któryś przebieg PHPUnita miał tam chodzić, rodzinę dopisuje się
w pliku bezpiecznika razem z testem, nie obchodzi odmowy.

**Co jest sprawdzone przeciw fałszywym odmowom.** `BezpiecznikBazyTestowejStartTest`
uruchamia bootstrap jako osobny proces dla baz z rodziny i spoza niej oraz skanuje:
kroki `ci.yml` z `artisan test`, skrypty w `scripts/` i `tests/skrypty/`.
`BezpiecznikBazyTestowejTest` sprawdza też, że każda nazwa wyliczana przez
`kuking_nazwa_testowej_bazy()` i `kuking_nazwa_bazy_wyscigow()` mieści się w rodzinie
(trzy przypadki reguły). `scripts/kontrole-negatywne-alfa08.py` w trybie lokalnym
sprawdza rodzinę na starcie: inaczej każdy test w pętli odmawiałby startu,
a kontrola ujemna czyta niepowodzenie testu jako „mutacja złapana”.

**Czego to nie robi.** Nie sprawdza hosta (`DB_HOST`): baza o nazwie z rodziny na
cudzym serwerze przejdzie. Nie obejmuje `php artisan migrate:refresh --env=testing`
z `scripts/check.sh`, które czyta `.env`. To osobna ścieżka i osobne zgłoszenie,
jeśli właściciel zechce. Nie widzi też `DB_URL` wpisanego do pliku `.env`
(a nie do środowiska procesu): bezpiecznik stoi przed wczytaniem `.env`, więc
ocenia tylko `DB_DATABASE`, a Laravel potem bierze `DB_URL` z `.env` i nim
przebija nazwę bazy. `.env.example` ma `DB_URL` zakomentowane; nie odkomentowuj
go w kopii roboczej, w której uruchamiasz testy.

**Wycofanie.** Usunąć wywołanie `kuking_wymus_baze_testowa()` z `tests/bootstrap.php`
(jedna linia); pozostałe pliki nie mają skutków ubocznych.

Dowody: `tests/Unit/BezpiecznikBazyTestowejTest.php`,
`tests/Feature/BezpiecznikBazyTestowejStartTest.php`; pułapka 18
w `docs/PULAPKI_TESTOW.md`.
