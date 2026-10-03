# #2861 — literalne ciasteczko moderatora i okno potwierdzenia 2FA

Data pomiaru: 3 października 2026. Baza kodu:
`09f8af1c738789c4498d35158f940ac237c30eee` (wydanie S, PR #2886).

## Zakres

Uzupełnienie dwóch wykonawczych kryteriów #2861. Kod aplikacji, Policy,
middleware, konfiguracja produktu i sześć dotychczasowych scen wyścigu
#2861/#2862 pozostają bez zmian. Nowe pliki to klasa Dwa, jej osobny
uczestnik HTTP, kontrola ujemna i test wyniku tej kontroli. Wpięcia dodają
wyłącznie nowe wywołania oraz ścieżkę nowej kontroli do zakresu wyścigów.

Nie powstał drugi produktowy sposób włączania 2FA. Test wywołuje rzeczywiste
trasy przez HTTP kernel. To pomiar lokalny w trybie `testing`, z normalnym
testowym kontraktem Laravela dla CSRF; nie zmienia ochron aplikacji.
Nie jest oglądem przeglądarki ani produkcji.

## Izolacja

Własny runtime jest rzeczywistym worktree, z plikiem `.git` wskazującym na
`registry-2861-literalny-odbior/.git/worktrees/race_repo_2861_literalny_review`.
Przed uruchomieniem `tests/Dwa/bin/przygotuj-baze.php` odczytano jego kontrakt
i wyliczono nazwę czystą funkcją `kuking_nazwa_testowej_bazy()`:

| Parametr | Potwierdzony stan |
|---|---|
| Runtime | `/home/codex-admin/kuking-koordynacja-20261003-codex/race_repo_2861_literalny_review` |
| Baza Dwa | `kuking_race_race_repo_2861_literalny_review` — przed przygotowaniem nie istniała |
| Host / port | `127.0.0.1:55488` |
| Silnik | PostgreSQL 18.6 |
| Rola i właściciel bazy | `kuking_pg18_owner` |
| Vendor | fizyczna kopia; identyczny `composer.lock`, własny `dump-autoload` |
| Aplikacja | jawne `APP_BASE_PATH` i `APP_ENV=testing`, własny klucz |

Nie używano domyślnego `kuking_race`. Procesy Git miały jawną lokalizację
i własną kopię środowiska bez `GIT_*`; środowisko sesji i globalna
konfiguracja Git nie zostały zmienione. Zastane zabezpieczenia fixture
#2871 pozostają bez zmian.

Przyrząd porównuje cel z nazwą wyliczoną dla aktualnego checkoutu. Gołe
`kuking_race` obsługuje wyłącznie w zastanym kontrakcie izolowanego CI
(`CI=true`); lokalnie odmawia tego celu. Nie zmienia sposobu nazywania baz.

## Co wykonują testy

### Literalne ciasteczko A i panel moderatora — trzy warianty

`Potwierdzenie2faPodWspolnaBlokadaTest::test_spoznione_potwierdzenie_nie_otwiera_panelu_literalnym_cookie`
mierzy reset, zmianę hasła i reset do identycznego hasła.

1. Moderator loguje się prawdziwym POST-em hasła i POST-em TOTP.
   GET `admin.reports` z otrzymanym ciasteczkiem daje **200**.
2. Ta przeglądarka legalnie wyłącza 2FA POST-em z hasłem i przygotowuje
   nowy składnik przez rzeczywisty GET ustawień. Ustawienia nadal dają 200.
   Kontrola panelu następuje przed tym świadomym wyłączeniem 2FA: panel
   z niepotwierdzonym składnikiem nie mógłby być poprawną kontrolą dodatnią.
3. A wysyła POST potwierdzenia nowego składnika. Bariera zatrzymuje A
   po sprawdzeniu hasła i ważnego kodu, przed blokadą finalnego zapisu.
4. B kończy właściwą zmianę/reset; następnie bariera zwalnia A.
5. Osobny proces wykonuje GET ustawień oraz GET panelu z **dosłownym
   ciasteczkiem odpowiedzi A**. Oba żądania odsyłają do logowania.
   Tak samo sprawdzane jest dosłowne ciasteczko sprzed A. Odczyt nie loguje
   ponownie i nie podkłada użytkownika, generacji ani dowodu 2FA.
6. Hasło, generacja, `remember_token`, sekret i audyt pozostają stanem B;
   2FA nie jest potwierdzone, kody zapasowe nie powstają, listów jest zero,
   `old` nie zawiera wpisanych poświadczeń.

Między żądaniami przyrząd usuwa guardy, sterowniki sesji i singleton
`session.store`. Bez usunięcia singletona kontrola w jednym procesie
mogłaby czytać poprzednią sesję zamiast dosłownego ciasteczka. Przeniesienie
ciasteczka do kolejnego procesu rzeczywiście wykryło ten błąd przyrządu
podczas przygotowania, zanim zaczęto zbierać dowód domenowy.

### Okno `confirmTwoFactor()` → `invalidateSessions()` — dwa warianty

`Potwierdzenie2faPodWspolnaBlokadaTest::test_potwierdzenie_i_odwolanie_sesji_serializuja_reset`
mierzy reset do nowego oraz identycznego hasła.

Bariera `beforeExecuting` staje bezpośrednio przed pierwszym SQL-em
`invalidateSessions()`, po wykonanym przez akcję `confirmTwoFactor()`.
Na połączeniu A potwierdzenie istnieje i poziom transakcji wynosi 1.
Obserwator nadal widzi niepotwierdzone 2FA. Prawdziwy proces B resetu
czeka na `FOR UPDATE` konta: `pg_blocking_pids(B)` zawiera PID A.
To potwierdza blokadę konkretnego procesu, a nie samą obecność dowolnego
oczekującego zapytania. Synchronizacja opiera się na kolejce blokad,
nie na założeniu, że uczestnik zdążył po upływie czasu.

Po zwolnieniu bariery A kończy jedną transakcję, a B kończy reset.
Końcowe hasło należy do B, generacja wzrasta o dwa, 2FA jest potwierdzone,
a literalne ciasteczka sprzed A i z odpowiedzi A nie otwierają ustawień
ani panelu moderatora.

## Wyniki i kontrole ujemne

| Pomiar | Wynik |
|---|---|
| Nowa klasa Dwa | **5/219 PASS**, zero failure/error/skip |
| Stare dwie klasy S4 oraz nowa klasa | **20/722 PASS**, zero failure/error/skip |
| Usunięcie świeżego sprawdzenia sesji | **3 właściwe FAIL + 2 PASS**; marker `2FA_2861_COOKIE_A_PANEL_ODMOWA`, panel zwracał 200 |
| Wyniesienie `invalidateSessions()` poza blokadę/transakcję | **2 właściwe FAIL + 3 PASS**; marker `2FA_2861_OKNO_WSPOLNA_BLOKADA`, reset nie czekał na A |
| Po każdej fizycznej mutacji | dokładne odtworzenie bajtów i mtime, ponownie **5/219 PASS** |
| Przyrząd JUnit | **8 PASS**; odmawia braku/uszkodzenia raportu, obcej klasy/metody, duplikatu, braku przypadku, skip/error, złego markera, dwóch porażek, złej rodziny i kodu fatalu |
| Pint dwóch nowych źródeł | PASS |
| PHPStan dwóch nowych źródeł | 0 błędów |
| Składnia trzech zmienionych skryptów powłoki | PASS |
| Zakres dla samego nowego skryptu kontroli | `kod=true`, `wyscigi=true` |
| YAML CI | poprawny; nowe wywołanie przyrządu w lint dokładnie raz |

Kontrola ujemna wymaga wszystkich pięciu dokładnych przypadków JUnit,
właściwych pełnych `class`/`classname`, osobnej przyczyny każdej porażki
i kodu wyjścia 1 mutanta. Zielony przypadek z drugiej rodziny także musi
pozostać zielony. Przyrząd zachowuje surowe bajty i nanosekundowe mtime
w `finally`, również przy nieudanym przebiegu.

Odtworzone źródło `app/Domain/Security/Actions/WlaczDwuetapowa.php`:

- SHA256: `d2ed765f07e0333d32bd8c040fbc377b6d371f5740f29c76a24cadcbc74a7c1f`;
- mtime_ns własnego runtime: `1791026108811417807`;
- bajty są identyczne z bazowym commitem przed i po obu kontrolach.

Zastane `SpoznioneWlaczenieIWylaczenie2faTest.php`, `bin/scenariusz.php`
i `kontrola-negatywna-2861-2862.py` mają identyczne bajty z bazowym commitem.
Stare sześć scen, ich wzorce i trzy dodatnie przypadki wykonano ponownie
w przebiegu 20/722.

Artefakty pomiaru na tym samym stanowisku, w katalogu
`/home/codex-admin/kuking-koordynacja-20261003-codex/transfer/`:

- `2861-uzupelnienie-positive.xml` — 5 przypadków dodatnich;
- `2861-uzupelnienie-regression.xml` — 20 przypadków wspólnych;
- `2861-uzupelnienie-mutants.log` — dwie fizyczne mutacje, własne porażki,
  dokładne restore i zielone przebiegi po odtworzeniu.

## Granica odbioru

To uzupełnienie dowodu, bez poprawki działającego kodu aplikacji. Nie
uruchomiono pełnego `check.sh`, całej grupy Dwa, całego PHPStan ani nowego CI.
Pełny odbiór i wydanie paczki Y należą do sesji głównej. Lokalny dowód tych
dwóch scen nie zamyka issue i nie zastępuje bramki produkcyjnej właściwego
wydania. Powrót polega na cofnięciu samych testów i nowych wywołań.
