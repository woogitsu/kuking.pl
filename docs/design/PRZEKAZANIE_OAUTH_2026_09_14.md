# Przekazanie OAuth i porządkowania issues — 14 września 2026

## Instrukcja dla kolejnego modelu

Kontynuuj istniejącą pracę nad `woogitsu/kuking.pl`. Nie zaczynaj audytu marki od zera. Przeczytaj aktualne AGENTS.md i wskazane dokumenty. Użytkownik zlecił kilka dalszych prac, następnie poprosił o bezpieczne zakończenie, wysłanie wszystkiego na GitHub i przekazanie. Ten plik dokumentuje stan przed końcowym commitem/push; późniejsza wiadomość przekazania ma pierwszeństwo w kwestii SHA, PR i wyniku wysyłki.

Repo kanoniczne: `C:\Users\matma\Documents\Codex\kuking.pl`.
Gałąź: **test/345-dostepnosc-oauth**, od main **8339b373e7ee6909ee3e69b58ec4de22dc7b5eb0**.
Produkcja: https://kuking.pl, Railway, deploy przez GitHub. Masz zgodę na autonomiczne poprawki, instalacje, testy, PR i normalne scalanie po kontrolach. Nie obchodź hooków ani CI. Nie nadpisuj pracy innych osób. Nie dotykaj PR456 ani nie scalaj ponownie493.

Docelowy PR tego pakietu ma być **DRAFT** — ostatnie zmiany wymagają końcowego odbioru. Nie ustawiaj automerge. Jeśli API zwróci błąd, odczytaj stan przed powtórzeniem, ponieważ operacja mogła się wykonać.

## Co jest już na produkcji

Alfa **0.27**, ostatnio potwierdzony rzeczywisty publiczny HTTP: **8339b37**. CSS `app-BhNKF9Y-.css`, JS `app-DXNAnudp.js` oraz oba lokalne Inter zwracały200.

- PR aplikacji **540**: head `f5d387fc850f5daed0d66563d4104f64aeac00bb`, merge **4c537b220af20c8efdd8cc109cc36dcbb46e3377**.
- CI PR **34853279729** i main **34855902800**: po10/10 success; PHP3799 testów /76314 asercji; axe44/44, układ49/49, Lighthouse8/8.
- Railway **6439273567** dla4c537b2 success; Deploy **34859662013** success. Odbiór zalogowanej produkcji zapisany w `docs/design/ODBIOR_PRODUKCJI_ALFA_027.md`.
- PR dokumentacji **541**, head18f2572, merge **8339b373e7ee6909ee3e69b58ec4de22dc7b5eb0**. CI **34861115240** i main **34861187081** success, prawidłowo tylko zakres +9skipped dla Markdown.
- Railway dla8339b37 **6440234515** success14.09 15:21:40UTC, Deploy **34861639212** success. Wcześniejszy34861194041 skipped nie jest dowodem wdrożenia.

Alfa0.27 naprawiła niskie widoki/nawigację, fokus podpowiedzi, odstęp usuwania komentarzy i limit nazwy. Karuzela ma regresję i poprawione raportowanie. Pierwszy PR540 CI34850145604 był czerwony przez ReferenceError końcowego JSON, naprawiony w f5d387f — nie przedstawiaj historii jako samych sukcesów.

Pełny port marki nadal **CZĘŚCIOWO**. Nie każdy ekran i stan, prawdziwy zoom, telefon ani klient pocztowy ma odbiór. Raport kompletności: `MACIERZ_KOMPLETNOSCI_517.md`.

## Co zawiera robocza gałąź

### #345 — pięć ekranów OAuth

Nowe pliki:

- `scripts/fixtures/oauth-bootstrap.php`
- `scripts/fixtures/oauth-router.php`
- `scripts/fixtures/oauth-stan.php`
- `scripts/fixtures/oauth-dostepnosc.mjs`
- `scripts/fixtures/oauth-raport.test.mjs`

Integracja: `scripts/dostepnosc.mjs`, aktualizacja kontekstu istniejącego `karuzela-raport.test.mjs`, `tests/Feature/PomiarDostepnosciObejmujeStronyPubliczneTest.php`, artefakt w `.github/workflows/ci.yml`.

Issue historycznie wymienia cztery formularze, ale D-175 wskazuje też **Facebook bez adresu e-mail**. Moduł mierzy wszystkie5 ×2motywy,320×740, tekst140. Nie jest to prawdziwy zoom200.

Nie dodano żadnej trasy, middleware'u ani komendy produkcyjnej. Osobny router testowy startuje na127.0.0.1, bootuje prawdziwą aplikację i podstawia transport Http do stałych endpointów dostawców. Prawdziwy start ustala state/nonce; callback zapisuje tożsamość. Nie ma podstawiania sesji OAuth. Facebook-polacz wymaga najpierw rzeczywistego logowania hasłem na własne konto testowe. Formularzy finalnego łączenia/zakładania nie wysyłamy w pomiarze wizualnym.

APP_ENV=local jest celowe — testing może pominąć CSRF. Router ma jawne guardy lokalnego PostgreSQL, zgodnego jawnego portu, maileraarray i dopuszczonych baz: kuking_oauth345/kuking_test_a11y/kuking_a11y; kuking_test wyłącznie przy GITHUB_ACTIONS=true. Początkowy szeroki kuking_* odrzucono w review. Http::preventStrayRequests i browser routing blokują wyjście na obce domeny. Każdy przebieg ma własne sesje i cache, bez wyłączania throttle.

Cleanup poID+email sprząta własne konto i prywatny katalog. Odmowa usunięcia daje błąd, a katalog zostaje do diagnozy — nie deklaruj sprzątnięcia po takiej odmowie.

API `zmierzOauth({przegladarka, wyniki: []})`. Wspólny bufor dostaje rekord started przed pomiarem, następnie success/error. Eksporty EKRANY_OAUTH i WARIANTY_OAUTH. Root wymaga dokładnego crossproductu adres/stan/motyw,10unikatowych rekordów. Częściowe dane i błąd trafiają do głównego JSON. VM regresja wykonuje prawdziwy blok integracji i prawdziwy writer; atrapy dotyczą kosztownego pomiaru i innych sekcji. Nie przedstawiaj jej jako pełnego axe.

### #542 — rzeczywiste nieprawdziwe instrukcje Facebooka

Issue https://github.com/woogitsu/kuking.pl/issues/542 utworzono podczas oglądu. Początkowy opis dotyczy jednego kliknięcia; późniejsze rozszerzenie opisane poniżej nie zostało jeszcze dopisane do issue.

- `resources/views/auth/facebook-link.blade.php`: zamiast obietnicy „jednym kliknięciem” widoczna nazwa „Wejdź kontem Facebooka” i informacja o możliwym potwierdzeniu.
- `resources/views/auth/facebook-bez-adresu.blade.php`: ostatnia zmiana przed przekazaniem. Usunięto obietnicę tylko dwóch pól rejestracji oraz e-maila po każdym ugotowaniu. Faktyczny formularz ma więcej pól i oświadczenia; `NotifyUser` zapisuje powiadomienie w serwisie. Ekran kieruje do pełnego formularza i opisuje e-mail do odzyskania dostępu. Poprawiono też fałszywy komentarz w tym widoku.
- `tests/Feature/PrecyzjaWejscZewnetrznychTest.php`: pominięty widok włączono do renderu; białe znaki normalizowane, ponieważ fraza rozbita nową linią omijała zwykłe wyszukiwanie. Osobny main i POST/CSRF w teście linkowania, osobny test braku adresu.
- `config/kuking.php`: **Alfa0.28**, CHANGELOG oraz COPY_STYLE aktualizowane. Żadnych zmian mechaniki logowania ani danych użytkowników.

### Historyczne issues

- **#38 zamknięte completed**: gotowce poprawione wcześniej, pytanie istnieje;17testów/1242asercje terazPASS. D-179 wyjaśnia stary błędny grep; nie przywracaj starego powitania.
- **#402 zamknięte not_planned jako zastąpione przekazanie**, nie „wszystko wykonane”. #393 zostaje, podobnie #9/#193/#120 z niepotwierdzonymi czynnościami właściciela.
- Odzyskano TRIAZ ze zdalnej gałęzi `claude/audyt-uiux-triaz`, commit027b1546e54343b8a6a2761b202391de93418fc1, blob b80d198051d46bfd472b5fa9aef38263bcab4e01. Sześć pozycji opisuje **docs/design/ODZYSKANY_TRIAZ_347.md**. Nie kopiowano nieaktualnych instrukcji. Pięć pozycji rozstrzygnięto kodem/decyzjami; pozostał dług podziału app.css i porządkowania zapisu kompromisu kolumn. #347 nadalotwarte, komentarz z nowym raportem jeszcze niewysłany.
- Zamknięte wcześniej:431,434,444,538,539 oraz440,445,448,518. #518 użytkownik sam potwierdził jako niewidoczny od0.19; przyczyny nie ustalono, nie dodano globalnego blur.
- #492 nadalotwarte i ma uczciwy komentarz częściowego odbioru. #345/#542 pozostająotwarte do ukończenia PR.

## Rzeczywiście wykonane testy i ograniczenia

Raport `docs/design/EKRANY_OAUTH_345.md` oraz `docs/design/evidence/oauth345/` zawierają wyniki.

- Dodatkowy agent:10/10 modułu na Chromium153.0.8010.12,22.911s; zeroaxe/overflow. Tab main: Google finish9/9, link3/3, Facebook finish8/8, link3/3, bezadresu2/2 — oba motywy. Helper Tab rzuca przy błędzie, nie zwraca liczb; liczby są w logu ZOOM_TAB_OK.
- Ten końcowy odbiór agenta zawiera nowy Facebook-link, ale **NIE zawiera ostatniego tekstu Facebook-bez-adresu**. Stopka jego kopii miała0.27. Zrzuty nie są odbiorem produkcji.
- Trzy rzeczywiste negatywy: pusty przyciskGoogle-link→axe; min-width w app.css→overflow; pusta etykietaGoogle-finish→specjalnyguard. MD5/mtime i dodatnie10/10 po każdym.
- Pierwsza pusta etykieta nie oblałaaxe (placeholder nadał nazwę) — niezaliczone, dodano kontrolę właściwej widocznej etykiety i ponowiono.
- Sześć odmów niebezpiecznej konfiguracji przedqueryPASS. Finalna wyłącznaDBagenta users=0, serwer/przeglądarka/temp zamknięte.
- Root:9testówNode reportowaniaPASS (5OAuth+4karuzela). Cztery fizyczne negatywy integracji/sourcecoveragePASS, backupoutsideMD5/mtime/dodatniererun.
- Root:18PHP/157asercji wybranych rodzinPASS **przed ostatnim rozszerzeniem bezadresu**. Po rozszerzeniu sama precyzja: **6/49PASS**.
- Root:2negatywyFacebook-link na LF +1negatywFacebook-bez-adresuPASS, po każdym pozytywny rerun. MD5finalLink08c9aed33d54d681a245510ae2e277f8,bezadresu918d8db625852c8fe1642b4744ce03d8.
- Po restore mtime Blade może nadal używać skompilowanego sabotażu. Pierwszego takiego rerunu nie zaliczono. `view:clear` przed każdym kolejnym pomiarem; pełną parę powtórzono.
- Pint wybranych plików i PHPStanPASS przed ostatnim tekstem; pełny hook ma wynik w logu wysyłki, sprawdź go.
- Pierwszy lokalny test kodu wyjścia oblał przez CRLF skryptu. Przywrócono LF (nie zmieniano testu/progów);18/157PASS. Pilnuj bajtów przy PythonieWindows.
- Niezależny review zaakceptował integrację, guardy, cleanup i Facebook-link. **Facebook-bez-adresu jeszcze nie ma końcowego review/pomiaru.**
- Root obejrzał pięć reprezentatywnych pełnych stron; pozostałe zrzuty nie mają jego odbioru. Pełnostronicowe zdjęcie pokazuje fixed navbar na wysokości pierwszego viewportu; nie traktuj tego jako pomiaru jednoczesnej widoczności całej strony. Osobne `-tab.png` pokazują prawdziwy viewport po Tab.

## Środowisko i helpery

UbuntuWSL. Kopia wykonawcza root `/tmp/kuking-final-20260913`, PHP `/opt/kuking-php-8.4-avif/bin/php`, Node20.20.2. PG **55439**, localhost,userkuking. **Nigdy5432**, współdzielony z innymi projektami.

Pełny PHP root: **kuking_final_20260913**. OAuth: wyłączna **kuking_oauth345**, jużzmigrowana przezagenta, obecniebezusers. Agentkopia `/tmp/kuking-oauth345`, worktree `C:\Users\matma\Documents\Codex\kuking-oauth345`.

Chromium153 z Playwright: sprawdź aktualny executablePath w kopii; wcześniejszy `/tmp/kuking-browsers015/chromium-1243/chrome-linux64/chrome`. Nie zakładaj ścieżki bezodczytu. CHROMIUM_PATH dla większości, CHROME_PATH dla Lighthouse.

Nie uruchamiaj pełnego PHP jednocześnie z odbiorem korzystającym ze wspólnych mediów. Agentmawłasnąkopię/DB, ale sprawdźprocesy przednowymtestem. Nie kopiuj .git z native do canonical — tylko canonical→native po weryfikacjiSHAiźródeł. `output/sync-final.py` kopiuje pliki z wygenerowanego `output/source-files.txt`, sprawdzaidentycznebajty. Regenerujlistę po nowychplikach. Nie używaj `git stash` na współdzielonychdrzewach.

Lokalne helpery output (ignorowaneprzezgit, pozostająnadysku):

- `github_api.py`: GitHub przez GCM, nie wypisujpoświadczeń.
- `git-native.sh`: git w rootnative ze środowiskiemtestowym i obowiązkowymhookiem.
- `push345.py`, log `/tmp/kuking-push345.log`: zwykłypush. Jeśli trwa, **nie uruchamiajdrugiego**.
- `metadata345.py SHA`: canonical.git→native, wymaga prawidłowejgałęzi/SHA.
- `create-pr345.py SHA`, body `PR345.md`: idempotentnyDRAFT, kontrolazdalnegoSHA.
- `test345-integration.py`, `test542.py`, `negatywy345-root.py`, `negatywy542.py`, `negatywy542-bez-adresu.py`: wykonane/odtwarzalnecelowanekontrole. Ujemne zmieniają rzeczywiste źródła wrootnative; nie uruchamiaj równoleglezserwerem/push.
- `ci-watch027.py RUN`, `pr515-status.py PR SHA`, `deploy-sha.py SHA`, `job-test-results.py JOB`, `production018-http.py`: generyczneodczyty mimo historycznychnazw.
- Agent `output/ODBIOR_OAUTH_345.md`, `output/oauth345/`, `output/345-odebrane.log`: szczegóły i zrzuty. Nie kopiujnieprzejrzanychplikówtemp/snapshotówzhasłamidotree.

## Kolejność kontynuacji

1. Odczytaj stan canonical, remote branch, draftPR i procespush/log. W wiadomości końcowej poprzedniego modelu powinien być SHA/PR/wynik. Nie uruchamiaj drugiegohooka równolegle.
2. Sprawdźdiff, aktualny main i ewentualną cudząpracę. Nie resetujgałęzi.
3. Dopisz do #542 zakres ostatniego ekranu bez adresu; komentarz #347 powinienlinkować scalony/roboczyraport, bez deklaracjicałego audytu.
4. Wykonaj końcowy10/10moduł na dokładnych bajtachroot, wtymFacebook-bez-adresu. ObejrzyjfinalnePNG obu motywów, sprawdźtekst/przyciski/fokus. Nie wysyłajprawdziwegoOAuthani maili. W razie dalszychzmian ponówodpowiednieregresje i rzeczywistenegatywy.
5. Końcowereview ostatniegotekstu, pełnyhook, wymaganeCI orazintegracjazgłównymraportem. Nie pomijajtimeoutu/progów ani testów. Dodatkowy moduł trwaokoło23s; pełnyportCI około22–24min.
6. Dopiero gotowyPRustawready i scalnormalniepo wymaganychkontrolach. Po scaleniu odczytajmainCI, RailwaydlaSHA iHTTPwersję/assety. Nie wymuszajOAuthprodukcji abyodtworzyćfixture. BrakfinalnegozalogowanegoOAuthprodukcji jawnieoznaczograniczeniem.
7. Zaktualizujraporty zgodnieztymco rzeczywiście wykonałeś. Zamknij#345/#542 dopierozdowodami. #492pozostajeczęściowe; #347niejest„cały audytgotowy”.

Nie buduj nowych funkcji, nie zmieniaj stosu, nie zmniejszaj tekstu i nie ukrywaj globalnie overflow. Zachowaj znak garnka z koroną i uśmiechem. Testowy moduł nie może stać się drogą wejścia na produkcji.
