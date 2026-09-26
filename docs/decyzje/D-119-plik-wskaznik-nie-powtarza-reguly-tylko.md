## D-119 · Plik-wskaźnik nie powtarza reguły, tylko odsyła — a punkt bez nazwanego wyjątku jest rozjazdem tej samej wagi co punkt nieprawdziwy

**Data:** 11 września 2026 · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Z czterech rozjazdów w
> `CLAUDE.md`, które ten wpis wymienia, naprawiony został jeden — punkt o
> JavaScripcie odsyła dziś do D-053. Dwa nazwane tu wprost stoją dalej.
> `CLAUDE.md:27` każe „Przed PR-em: `vendor/bin/pint` i `php artisan test`",
> gdy `AGENTS.md:454` podaje `./scripts/check.sh` jako JEDNĄ komendę
> obejmującą formatowanie, składnię, testy, migracje i assety. `CLAUDE.md:22`
> mówi „Zmiana schematu = migracja + test + `docs/DATABASE.md` + rollback" bez
> wyjątku z **D-088** (`down()` przy wartościach semantycznych ODMAWIA), który
> `AGENTS.md:318` ma jako osobny podrozdział. Realizuje się więc dokładnie
> ryzyko nazwane w tym wpisie: agent czytający wskaźnik jako pierwszy
> „poprawi" decyzję właściciela, będąc przekonanym, że egzekwuje zasadę.
>
> **Naprawa nie weszła razem z tą adnotacją.** Zlecenie audytu ograniczało
> zmiany do `docs/DECISIONS.md`, a `CLAUDE.md` jest plikiem instrukcji dla
> agentów — jego zmiana należy do człowieka, nie do łańcucha zadań. Potrzebna
> edycja jest dwuliniowa i zgodna z regułą tego wpisu („wskaźnik odsyła, nie
> powtarza"): w `CLAUDE.md:27` zastąpić `pint` i `artisan test` odesłaniem do
> `./scripts/check.sh` z `AGENTS.md` §10; w `CLAUDE.md:22` dopisać „rollback
> może ODMÓWIĆ — patrz D-088 i `AGENTS.md` §6".

`CLAUDE.md` sam o sobie pisze, że jest tylko wskaźnikiem na `AGENTS.md`, i sam
ostrzega, że „rozjazd między plikami instrukcji jest gorszy niż brak instrukcji".
**Był tym rozjazdem w czterech z siedemnastu punktów ściągi** — i jest to plik,
który każdy agent czyta jako PIERWSZY.

Rozjazdy były dwojakiego rodzaju i oba liczą się tak samo:

1. **Wprost nieprawdziwe** — „ważne funkcje działają bez JavaScriptu" po tym, jak
   D-053 tę zasadę zniósł; „przed PR-em `pint` i `test`", gdy `AGENTS.md` §10 mówi
   `./scripts/check.sh`.
2. **Prawdziwe, ale bez nazwanego wyjątku** — reguły UX 50+ bez wyjątku z D-051
   i „migracja + rollback" bez tego, że przy wartościach semantycznych `down()` ma
   ODMÓWIĆ (D-088). Agent czytający taki punkt „poprawia" decyzję właściciela,
   będąc przekonanym, że egzekwuje zasadę.
