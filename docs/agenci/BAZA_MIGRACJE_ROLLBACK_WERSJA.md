# Baza danych: DDL na żywej bazie, odmowa w down(), numer wersji

Przeniesione z `AGENTS.md` §6 bez zmian treści; zasada w skrócie i odnośnik do tego pliku zostają w `AGENTS.md`.

### DDL na istniejącej tabeli nie może zatrzymać serwisu (audyt B3 W3)

Migracje chodzą na **żywej** bazie (rola `migrate`). `ALTER TABLE posts`
czekający na blokadę za długim zapytaniem ustawia za sobą w kolejce KAŻDE
następne zapytanie do `posts`, także zwykły `SELECT` z feedu. Dlatego:

- **Każda migracja chodzi z `lock_timeout = 5s`** — ustawia go
  `App\Support\Baza\LimitBlokadMigracji` na zdarzeniach migratora, nie
  trzeba nic dopisywać. DDL, który nie dostał blokady, pada i daje się
  powtórzyć. Pilnuje `tests/Feature/MigracjeMajaLimitBlokadTest.php`.
- **Indeks na istniejącej tabeli** to `CREATE INDEX CONCURRENTLY IF NOT EXISTS`
  w migracji z `public $withinTransaction = false;` (`CONCURRENTLY` nie działa
  w transakcji). Przerwana budowa zostawia indeks INVALID pod tą samą nazwą —
  migracja ma go przed budową zdjąć, bo `IF NOT EXISTS` by go przepuściło.
  `down()`: `DROP INDEX CONCURRENTLY IF EXISTS`.
- **CHECK i klucz obcy na istniejącej tabeli** to `ADD CONSTRAINT … NOT VALID`,
  a potem osobno `VALIDATE CONSTRAINT` — obie rzeczy poza jedną transakcją
  (`$withinTransaction = false`), inaczej blokada z pierwszego kroku trwa do
  końca drugiego. Wzorzec:
  `2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem.php`.
- **Nowa tabela** tych reguł nie potrzebuje — nikt jeszcze na nią nie czeka.
- **Unikaj przepisania tabeli** (`ADD COLUMN … GENERATED … STORED`, zmiana
  typu kolumny) na gorących tabelach bez osobnego planu wdrożenia.
- **Migracja `2026_09_24_120000_add_appeal_id_to_moderation_actions.php`
  łamie powyższe** (indeks i FK/CHECK na istniejącej tabeli bez CONCURRENTLY
  i bez NOT VALID) — jest już na produkcji i świadomie jej NIE poprawiamy,
  ale `tests/Feature/NoweMigracjeTrzymajaSieParagrafu6Test.php`
  (`App\Support\Baza\StraznikNowychMigracji`) pilnuje, żeby ten sam błąd nie
  powtórzył się w żadnej migracji dodanej po wprowadzeniu strażnika, nawet
  jeśli jej datownik jest wcześniejszy. Wyjątki historyczne są jawnie zapisane
  w `app/Support/Baza/migracje-historyczne-par6.txt`.

### `down()` przy wartościach semantycznych — uzasadnienie (D-088)

Powód jest jeden i nie jest teoretyczny: **`down()` prawie nigdy nie
występuje sam.** Po nim idzie kolejny `migrate` — `migrate:refresh` w CI albo
awaryjny rollback WDROŻENIA, który pociąga bazę za sobą. Kolumna wraca, CHECK-i
wracają, żaden wiersz nie ginie, więc nie ma błędu do zauważenia — a wartość
jest już ta, którą umie nadać `DEFAULT` albo backfill z `up()`, czyli zwykle
ODWROTNOŚĆ tego, co człowiek wybrał.

Trzy przypadki tej jednej choroby, złapane w tym repozytorium:

| Gdzie | Co się cicho odwracało |
|---|---|
| `..._default_weekly_digest_to_off` (DB2) | `DEFAULT true` wracał, czyli nowe konta znów zapisywane na mailing bez zgody |
| `..._add_erased_status_and_delete_scope_to_users` (#287, MIG-01) | „usuń wszystkie moje treści" wracało jako „usuń minimum" |
| `..._add_memories_to_users_and_posts` (#287, przeoczone przy MIG-01) | wyłącznik wspomnień osoby w żałobie włączał się sam, schowany wpis wracał na stronę główną |

**Napisanie w komentarzu migracji „przy cofaniu na produkcji najpierw kopia
kolumny" NIE jest zabezpieczeniem.** Trzeci wiersz tabeli wyżej miał dokładnie
takie zdanie — prawdziwe, konkretne i bezwartościowe, bo przenosiło ochronę na
czyjąś pamięć w jedynym momencie, w którym nikt nie czyta komentarzy
w migracjach. Zabezpieczeniem jest `throw` w `down()`.

**Odmowa musi być WĄSKA.** Rollback blokuje się tylko wtedy, gdy w bazie
naprawdę jest wartość, której `up()` nie odtworzy — na wartościach domyślnych
i na świeżej bazie przechodzi bez pytania. Zablokowanie rollbacku na zawsze
jest błędem tej samej wagi w drugą stronę, więc każdy taki strażnik ma test
odmowy **i** kontrolę dodatnią (wzorce:
`tests/Feature/CofniecieMigracjiNiePodmieniaZakresuUsunieciaTest.php`,
`tests/Feature/CofniecieMigracjiNieWlaczaWspomnienTest.php`).

Preferencja WYGLĄDU to nie wartość semantyczna: `theme` i `posts.display_mode`
zostają świadomie bez strażnika (uzasadnienie w D-088).

### Numer wersji: DUŻY numer ręcznie, KOŃCÓWKA sama (issue #1932, D-318)

`kuking.wersja.etykieta` w `config/kuking.php` (np. „Alfa 0.68") to DUŻY
numer wydania — podbijasz go RĘCZNIE, w Pull Requeście, razem z wpisem na
górze `CHANGELOG.md` (pilnuje tego
`tests/Feature/PodbicieWersjiWymagaWpisuWChangelogTest.php`). Zasada, KIEDY
go podbić, stoi w komentarzu nad samą wartością w `config/kuking.php`: przy
każdej zmianie, którą człowiek ZOBACZY — nowy ekran, zmieniony układ, nowa
funkcja, inne zachowanie formularza. Poprawki bez śladu w interfejsie (testy,
refaktor, dokumentacja) go nie ruszają.

KOŃCÓWKA (`.005` w „Alfa 0.68.005") jest INNĄ rzeczą i NIE dotykasz jej
ręcznie nigdy — rośnie sama, o jeden, przy KAŻDYM wdrożeniu, licząc od
dziennika w tabeli `wdrozenia` (`kuking:zarejestruj-wdrozenie`, uruchamiana
przez `docker/entrypoint.sh` po tym, jak nowy kontener przejdzie `/health` —
nie w `preDeployCommand`, żeby nieudany rollout nie zużywał numeru). Gdy podbijasz DUŻY numer, końcówka
WRACA DO `.001` SAMA — to jest nowa sekwencja liczona od nowa, nie ciąg
dalszy poprzedniej, i nie ma tu nic do ustawienia ręcznie: pierwsze
wdrożenie pod nową etykietą po prostu dostaje numer 1. Pełny mechanizm,
tabele i bezpieczeństwo przy równoległym starcie: `docs/baza/migracje-danych-i-wdrozenia.md`
(sekcja „`wdrozenia` i `wdrozenia_funkcje`") i D-318.
