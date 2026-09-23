# Issue 583 — minimalny i kompatybilny payload digestu

> **Aktualizacja 23 września 2026 (#1383, #1328).** Opisany niżej kontrakt
> „treść pozostaje snapshotem, worker nie dobiera treści i nie odświeża kont"
> **już nie obowiązuje**. Migawka wysyłała po opóźnieniu wpis usunięty,
> poprawiony albo przestawiony na prywatny, a list szedł też do osoby, która
> zdążyła się wypisać, i na adres sprzed zmiany. Teraz:
>
> - `TrescDigestu::__serialize()` zapisuje te same sześć pól i te same klasy
>   modeli, ale każdy model ma **wyłącznie `id`**, a relacje jawnie `null`
>   (bez `body`, `note`, imion i tytułów);
> - `PodsumowanieTygodnia::send()` w chwili wysyłki woła
>   `ZbierzTresciDigestu::odswiez()`: adresat czytany od nowa przez
>   `OdbiorcyDigestu::kwalifikujacySie()` (brak zgody / konto nieczynne /
>   adres niepotwierdzony → brak listu), adres doręczenia aktualny z konta,
>   pozycje czytane świeżo tymi samymi zapytaniami i bramkami co przy
>   składaniu paczki, ograniczone do identyfikatorów z zapisu; pusty wynik →
>   brak listu;
> - stary worker (rolling deploy) dalej odczytuje zapis bez błędu i bez bazy,
>   ale list, który zdąży wysłać, jest ubogi („Użytkownik Kuking", bez
>   fragmentów wpisów) — świadomie ta strona pomyłki.
>
> Pilnują tego `DigestNieKolejkujeSekretowTest` (przepisany na nowy kontrakt)
> i `DigestSprawdzaWChwiliWysylkiTest`.

## Wynik końcowy

Obecny format zachowuje sześć dawnych pól DTO i typy modeli. Do kolejki trafiają NOWE minimalne modele z jawną listą atrybutów i relacji. Nie ma klonowania oryginałów, kopiowania pełnych attributes/relations ani własnego __unserialize. Stary worker może odczytać nowy payload podczas rolling deploy.

- Pint obu plików i pełny PHPStan: PASS.
- Nowe regresje: **4 testy / 113 asercji PASS**, również osobne procesy starego i obecnego czytnika.
- Negatyw sekretów: oczekiwany FAIL na obecności fałszywego password w całym jobs.payload.
- Negatyw dawnego formatu tablicowego: oczekiwany TypeError w OSOBNYM starym czytniku. Dedykowany test dochodzi do niego przed jakąkolwiek asercją allowlisty.
- Po każdej mutacji: dokładne odtworzenie źródła, następnie **4 testy / 113 asercji PASS**.
- Dziewięć istniejących rodzin: **85 testów / 333 asercje PASS**. Łącznie **89 testów / 446 asercji**, bez ponownego liczenia baseline/restored.

Dowody bieżącej implementacji: [report.json](evidence/digest583/compatible/report.json), [baseline](evidence/digest583/compatible/baseline.txt), [negatyw sekretów](evidence/digest583/compatible/negative_secrets.txt), [odtworzenie po sekretach](evidence/digest583/compatible/restored_secrets.txt), [negatyw starego czytnika](evidence/digest583/compatible/negative_old_reader.txt), [odtworzenie po starym czytniku](evidence/digest583/compatible/restored_old_reader.txt), [regresje](evidence/digest583/compatible/regressions.txt).

## Format i zachowanie

SerializesModels na Mailable nie zagląda do TrescDigestu. Własne __serialize buduje nowe obiekty według tabeli:

| Obiekt | Atrybuty | Relacje |
|---|---|---|
| User | id | profile |
| Profile | display_name | brak |
| CookedEvent | id, note | user, recipe |
| Post | id, body | author, recipe |
| Recipe | title | brak |

Pozostają licznik obserwujących i pytanie gospodarza. Nazwy klas modeli w payloadzie są celowe; test kontroluje dokładną zawartość, brak markerów hasła, remember_token, sekretów i kodów 2FA, nieznanej przyszłej kolumny oraz zbędnych relacji. Brak również adresów innych osób; adres odbiorcy pozostaje w Mailable do doręczenia.

Allowlista pokrywa oba szablony i temat: powitanie, imiona, nazwy potraw, notatki, wpisy, licznik pozostałych osób, linki do wykonania/wpisu oraz wypisanie. Metody miary/jestPusty/maCosOsobistego zachowują wynik. Note/body/pytanie i relacje mogą być null. Relacje ustawiamy jawnie także na null, aby uniknąć lazy-load. DisplayName zachowuje istniejący fallback.

Treść pozostaje snapshotem. Worker nie dobiera treści i nie odświeża kont. Zgoda, budżet i dobór D-057, rezerwacja osoba/tydzień D-077 oraz rozróżnienie kolejkowania i doręczenia D-078 pozostają bez zmian. Oryginalne modele współdzielone przez paczkę nie są mutowane. Nie włączono digestu, nie czyszczono kolejki i nie ponawiano wiadomości.

Producent ma eager loading: OdbiorcyDigestu:115 profile odbiorców; ZbierzTresciDigestu:154 user.profile/recipe, :196 profile obserwujących, :250 author.profile/recipe. Ręcznie skonstruowany DTO z brakującymi relacjami może je załadować przy serializacji po stronie producenta; nie obiecujemy zero zapytań dla dowolnego ręcznego obiektu. Nowe payloady mają komplet potrzebnych relacji. Test potwierdza zero zapytań przy odczycie/renderowaniu i brak wpływu późniejszej zmiany nazwy w DB. Zbieracz: 11 zapytań dla 5 i dla 50 odbiorców.

Wypisanie używa signedRoute bez expires; porównanie nie wymaga zamrażania czasu. Kontrakt nie zabrania szyfrowania, ale usuwa niepotrzebne dane zamiast maskować je ShouldBeEncrypted.

## Rolling deploy i stare wiadomości

Stary DTO ma readonly User odbiorca i nie ma własnego czytnika. Tablica w tym polu powoduje TypeError. Długotrwały queue:work, recykling i graceful shutdown nie gwarantują atomowej wymiany wszystkich producentów i konsumentów. Sama zgodność nowego czytnika ze starym payloadem nie wystarcza.

Obecny zapis zachowuje stare sześć pól i znane klasy. Natywna deserializacja obsługuje nowe minimalne modele oraz dawne pełne modele. Ponowna serializacja starego DTO przechodzi przez allowlistę. Nie usuwa to sekretów z już zapisanych jobs/failed_jobs ani backupów; ich retencja/przegląd wymagają osobnego działania właściciela.

Test uruchamia osobne procesy PHP starego i obecnego czytnika. Stary ładuje przed bootstrapem dokładny plik z git show ac5ff9d:app/Domain/Digest/TrescDigestu.php. Fixture w tests/Fixtures/digest583 ma SHA-256 d6b8412d72f92d8219d37001391099ae3932b914e763d0eb1edfefa01a858745, sprawdzany przez test. Oba procesy odczytują payload producenta i zwracają identyczny HTML/tekst/temat/nagłówki oraz zero zapytań. Dodatkowy samodzielny test starego czytnika nie ma wcześniejszej asercji allowlisty.

## Wykonanie kontroli ujemnych

Runtime /home/mateusz/kuking-digest583-runtime, PHP 8.4.24, PostgreSQL 127.0.0.1:55439, baza kuking_583_tests, użytkownik kuking, timezone UTC. Tożsamość bazy/port/użytkownik/strefa sprawdzone zapytaniem. APP_BASE_PATH jawny; mail=array, cache/session=array, AWS_BUCKET=kuking-local-test, APP_DEBUG=true. Procesy czytników mają DB skierowaną na niedostępny port 1. Bez produkcyjnych danych i wysyłki do dostawcy.

Backup poza repo: /home/mateusz/digest583-compatible-7rygrt_w/source.backup. MD5 aa88de62ac3b87be5c71d9b7c23fde9c, mtime_ns 1789499139952970055. Każde odtworzenie w finally potwierdzono bajtami, MD5 i mtime; potem cały nowy plik testów przeszedł poprawnie. Backup źródła nie trafił do repo.

1. Po baseline rzeczywiste ciało __serialize zastąpiono zwracaniem sześciu pól z oryginalnymi modelami. Test pełnego payloadu: exit 1, 1 test, 2 asercje, 1 failure, dokładnie does not contain "FAKE_SECRET_583_0_password". Odtworzenie i PASS.
2. Rzeczywisty plik DTO zastąpiono wersją tablicową z f007ec3. Dedykowany test rolling: exit 2, 1 test, 1 asercja, 1 error. ProcessFailedException pochodzi z potomnego PHP; stderr zawiera TypeError: Cannot assign array to property App\Domain\Digest\TrescDigestu::$odbiorca of type App\Models\User. Nie zaliczono błędu wcześniejszej asercji w procesie producenta. Odtworzenie i PASS.

Pierwsza próba pobrania historycznego pliku przez Git WSL zatrzymała przygotowanie przed mutacją, ponieważ .git worktree zawiera ścieżkę Windows. Pobrano go Git dla Windows do prywatnego katalogu; test i oczekiwany błąd nie zostały zmienione.

Rodziny: TygodniowePodsumowanieTest, PodsumowanieSzanujePrywatnoscTest, PodsumowanieBezWachlarzaZapytanTest, DigestNieWysylaDwaRazyTest, SygnalDigestuMowiZakolejkowanoTest, DowodZgodyNaDigestTest, RollbackNieWlaczaDigestuTest, WypisanieZPodsumowaniaTest, PodzialLimituPocztyTest.

Formatowanie dwóch plików PHP przeniesiono do worktree; SHA-256 runtime i worktree zgodne. Procesy zakończone. Bez commit/push w tej kontroli.

## Historia

Pierwsza próba f007ec3 używała skalarów/tablic i własnego __unserialize. Przeszła 88 testów / 407 asercji oraz negatyw sekretów, lecz nie gwarantowała zgodności ze starym workerem. Jej logi pozostają bezpośrednio w evidence/digest583 jako historia. Bieżący format i wyniki mają osobne dowody w compatible; tylko one opisują wersję końcową.