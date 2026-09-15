# Issue 583 — sekrety poza payloadem digestu

## Wynik wykonanych regresji i kontroli ujemnej

Wykonano na `/home/mateusz/kuking-digest583-runtime`, PHP 8.4.24, izolowanej bazie `kuking_583_tests`, PostgreSQL pod `127.0.0.1:55439`, użytkownik `kuking`, timezone UTC. Tożsamość bazy/port/użytkownik/timezone sprawdzone zapytaniem przed każdym przebiegiem zestawu. MAIL_MAILER=array; bez wysyłki do dostawcy.

- Poprawny baseline nowego pliku: **3 testy / 74 asercje PASS**.
- Rzeczywista mutacja ciała __serialize w runtime do zwracania pełnych modeli: **oczekiwany FAIL**, 1 test / 2 asercje / 1 failure. Porażka dotyczyła nieobecności `FAKE_SECRET_583_0_password` w całym jobs.payload (test:68), nie awarii składni ani DB.
- Przywrócenie z zewnętrznego backupu w finally: identyczne bajty, **MD5 `dc32d25b86d68e070af92a16e554ce64`**, **mtime_ns `1789497455626931200`**, oba sprawdzone ponownie po kontroli. Nie używano git checkout do odtwarzania.
- Przywrócone źródło: **3 testy / 74 asercje PASS**.
- Dziewięć zaplanowanych rodzin istniejących regresji: **85 testów / 333 asercje PASS**. Pomiar zbieracza: 11 zapytań dla 5 odbiorców i 11 dla 50. Łącznie nowe i istniejące przypadki: **88 testów / 407 asercji** (bez ponownego liczenia baseline/restored).

Pierwszy skrypt kontrolny oczekiwał angielskiej frazy `not to contain`, podczas gdy PHPUnit wypisał `does not contain`. Źródło zostało mimo tego od razu przywrócone, a dodatni przebieg zaliczony. Oczekiwany negatyw potwierdzono następnie na zapisanym logu po dokładnym kodzie, liczbie failures i markerze; bez zmiany testu i bez kolejnej mutacji.

Dowody: [report.json](evidence/digest583/report.json), [baseline.txt](evidence/digest583/baseline.txt), [negative.txt](evidence/digest583/negative.txt), [restored.txt](evidence/digest583/restored.txt), [regressions.txt](evidence/digest583/regressions.txt). Backup poza repo pozostał w `/home/mateusz/digest583-negative-7y78hkhd/source.backup` wraz z metadanymi. Log negatywu zawiera wyłącznie syntetyczne dane testu; żadnych produkcyjnych payloadów nie odczytywano. Procesy zakończone, zasób PHP/DB zwolniony dla root. Poniższy opis przygotowania i plan pozostaje zapisem zakresu; powyższy wynik zastępuje jego wcześniejszy status „niewykonano”.

Aktualizacja kontroli: w izolowanym `/home/mateusz/kuking-digest583-runtime`, z jawnym APP_BASE_PATH, Pint obu plików PASS (poprawił format testu), pełny PHPStan zgodny z repo PASS bez błędów. Runtime bez .env; analiza dostała jawny niedostępny endpoint DB 127.0.0.1:1 zamiast konfiguracji współdzielonej bazy. Testów i migracji nie uruchamiano. Oba pliki PHP przeniesiono do worktree; SHA-256 źródła i celu zgodne dla każdego pliku. `git diff --check` PASS. Testy integracyjne i kontrolę ujemną następnie wykonano; wyniki powyżej.

Gałąź `fix/583-sekrety-digestu`, baza `ac5ff9d`. Zmiana wyłącznie w kontrakcie serializacji `TrescDigestu` i nowej regresji digestu.

`SerializesModels` na Mailable nie przechodzi rekurencyjnie przez DTO. DTO zapisuje teraz jawną listę skalarów: identyfikatory linków, wyświetlane imiona, tytuły, teksty, licznik i pytanie gospodarza. Nie zapisuje modeli, ich atrybutów ani relacji. Deserializacja buduje lekkie modele wyłącznie w pamięci, z ustawionymi relacjami potrzebnymi obecnym szablonom. Dzięki temu mail zachowuje ten sam temat, HTML, tekst i podpisany odnośnik wypisania.

Nie odświeżamy treści w workerze: istniejący dobór widoczności i treści zostaje snapshotem. D-057 określa dobór, zgodę, limit i rozłożenie wysyłki; D-077 rezerwuje osobę/tydzień przed kolejkowaniem; D-078 odróżnia kolejkę od doręczenia. Zmiana nie dodaje odczytu/odświeżenia kont przy obsłudze zadania i nie ingeruje w te mechanizmy. Odbiorcy i relacje używane przez mail są już eager-loaded w zbieraczu. Helper nie zmienia wejściowych modeli współdzielonych przez paczkę.

Stary format DTO z modelami pozostaje czytelny; ponowna serializacja przechodzi przez nową listę pól. Sam deploy nie usuwa sekretów z już zapisanych jobs/failed_jobs/backupu. Ten pakiet nie czyści kolejki, nie ponawia wiadomości i nie włącza wysyłki. Ocena istniejących payloadów oraz ich retencji wymaga osobnego działania właściciela.

Przygotowane testy używają rzeczywistego `Mail::queue` i `DatabaseQueue`, odczytują pełny `jobs.payload`, sprawdzają brak fałszywych sekretów czterech osób i zagnieżdżonych relacji oraz zachowanie treści po deserializacji. Obejmują HTML, tekst, temat, nagłówki i odbiorcę; liczą zapytania podczas odczytu/renderowania, sprawdzają niewrażliwość na późniejszą zmianę profilu, dawny format i brak opcjonalnych relacji. Bez wysyłania maili.

Root potwierdził także `php -l` obu plików przy PHP 8.4 z AVIF: PASS. Następnie wykonano Pint, PHPStan i testy opisane powyżej. Nie wykonano commit/push ani zmian wersji.

## Ponowny review statyczny

Allowlista odpowiada wszystkim obecnym odczytom listu: odbiorca id → podpisane wypisanie, displayName → powitanie; wykonanie id/note/user.displayName/recipe.title → obie wersje i temat; obserwujący displayName + licznik → imiona i liczba pozostałych; wpis id/body/author.displayName/recipe.title → tekst i link; pytanie → zakończenie. `miary`, `jestPusty`, `maCosOsobistego` zależą wyłącznie od zachowanych list/liczników. Nie znaleziono innych użyć DTO wymagających atrybutów pominiętych w zapisie.

`note`, `body`, autor/kucharz/przepis i pytanie mogą być null; odczyt ustawia relacje również na null, aby brak relacji nie uruchamiał lazy-load. Tytuł istniejącego przepisu jest niepustą kolumną wymaganą przez schemat; null w zapisie oznacza brak przepisu. DisplayName jest już wynikiem obecnego fallbacku `Użytkownik Kuking`; po odtworzeniu tę samą nazwę przechowuje profil w pamięci.

Główna ścieżka przygotowuje wszystkie potrzebne relacje: OdbiorcyDigestu:115 profile odbiorców, ZbierzTresciDigestu:154 user.profile/recipe, :196 profile obserwujących, :250 author.profile/recipe. **Nie twierdzimy, że serializacja dowolnego ręcznie skonstruowanego DTO zawsze wykona zero zapytań:** `displayName` i odczyty relacji mogą załadować brakujące dane po stronie producenta. Dla obecnej ścieżki są już załadowane. Nowy zapis odtwarza komplet potrzebnych relacji, więc worker nie potrzebuje odczytów. Dawny payload otrzymuje publiczne pola bez konstruktora; __unserialize rozpoznaje User i przepuszcza dane przez tę samą allowlistę. Stare payloady z niezaładowanymi relacjami mogły już wcześniej ładować je przy renderowaniu; ten pakiet nie obiecuje naprawy ich historycznej kompletności.

OdnosnikWypisania::dla używa `URL::signedRoute`, bez expires i bez zegara; adresy posts/cooked też nie mają TTL. Nie ma potrzeby zamrażania czasu dla porównania tych szablonów. Usunięto asercję wymuszającą brak szyfrowania zadania: wymogiem jest minimalizacja zawartości, nie zakaz szyfrowania.

## Procedura wykonanej fizycznej kontroli ujemnej

1. Root przygotowuje izolowaną kopię wykonawczą tego worktree oraz osobną bazę PostgreSQL, jawnie sprawdza APP_BASE_PATH, DB_HOST/PORT/DATABASE, sterownik queue=database i mail=array. Żadnej produkcyjnej bazy, istniejącej kolejki ani rzeczywistych adresatów. Bez równoległego edytora/testera tego runtime.
2. Poprawne źródło: uruchomić `DigestNieKolejkujeSekretowTest` w całości; wymagany PASS trzech przypadków. Zachować kod wyjścia i pełny raport. Następnie zapisać dokładne bajty `app/Domain/Digest/TrescDigestu.php` i mtime_ns poza repo oraz MD5; odczyt ponownie musi zgadzać się z backupem.
3. Fizyczna mutacja wyłącznie kopii wykonawczej: w rzeczywistym ciele `__serialize` zastąpić allowlistę dawnymi sześcioma polami (`odbiorca`, `wykonania`, `nowiObserwujacy`, `ileNowychObserwujacych`, `wpisyObserwowanych`, `pytanieGospodarza`) bezpośrednio z `$this`. Nie zmieniać testu, modeli, $hidden, bazy ani vendor. Zachować składnię i nazwy sześciu pól. Bez zmian __unserialize: czytanie dawnego formatu nadal działa, więc regresja musi wykryć **payload**, a nie wywrócić się na typie.
4. Uruchomić wyłącznie test `test_pelny_payload_kolejki_nie_zawiera_sekretow_a_list_zachowuje_tresc_bez_odczytow_bazy`. Wymagane niezero i porażka asercji nieobecności `FAKE_SECRET_583_0_password` w pełnym jobs.payload. Błąd bootowania, SQL, składni lub serializacji callbacka nie zalicza negatywu. Markery są syntetyczne; nigdy nie podmieniać ich danymi kont.
5. W finally przywrócić z backupu bajty i mtime_ns, sprawdzić MD5 i mtime. Odtworzenie z Git nie wystarcza. Powtórzyć pełny nowy plik testów; wymagany PASS. Wynik negatywu uznać dopiero po udanym przywróconym przebiegu. Proces PHP testu jest świeży, nie potrzeba buildu front-endu ani restartowania produkcyjnych workerów. Przerwanie bez finally wymaga ręcznej kontroli backupu przed dalszą pracą.

Rodziny regresji po kontroli: `TygodniowePodsumowanieTest` (zgoda, wyłącznik, pusty tydzień, rozłożenie, temat i szablony); `PodsumowanieSzanujePrywatnoscTest` (blokady/status/widoczność); `PodsumowanieBezWachlarzaZapytanTest`; `DigestNieWysylaDwaRazyTest`; `SygnalDigestuMowiZakolejkowanoTest`; `DowodZgodyNaDigestTest`, `RollbackNieWlaczaDigestuTest`; `WypisanieZPodsumowaniaTest`; testy budżetu poczty, w szczególności `PodzialLimituPocztyTest`. Po nich Pint/PHPStan zgodnie CI. Po review uruchomiono te dziewięć rodzin: 85 testów / 333 asercje PASS, zgodnie z logiem regressions.txt.

Stan źródłowego PR #582 odczytany przez root z GitHub API 15 września 2026: otwarty, head 29e73cc820992cbfcf4d670fb3b3485c49c4742f, jeden plik dokumentacji. Kontrola Zakres zmiany: success; testy PHP, Pint, Larastan, buildy, audyt zależności i pomiary przeglądarkowe: skipped. Ten przebieg nie weryfikuje ustaleń audytu ani poprawki #583.
