# Przekazanie: zwarte kolumny strony powitalnej — #560

Najnowszy punkt po scaleniu: PR #562 zawiera head
2b221f3ae97f03b0a24d68e375b3abc70be0e7b4; zwykły push i hook przeszły.
PR CI34936291390: 10/10 success, PHP3814/76612, port kolumn24 PASS.
Normalny merge: bc76db12dc1e628a37325491bb7b2d4a0246401d.
MainCI34938070574: pierwsza próba cancelled przez limit25 minut
(adnotacja job104280296986); kroki pomiarowe i zrzuty success, ale całość
nie była zielona. Ponowiono wyłącznie nieudane zadanie, bez zmiany
limitu/testów. Próba2 ma success, 10/10 zadań. Railway6453239381
pozostało inactive. Panel proponował wdrożenie pomijające zapamiętany
czerwony status: anulowano. Kod ma pełne zielone CI; raporty można
przeprowadzić normalnym PR-em i odebrać kolejny zwykły deploy.
Produkcja nadal0.32; nie deklarować wdrożenia0.33 przed odbiorem.
Gałąź docs/562-odbior-kolumn zbiera aktualizację raportów; przed wysyłką
sprawdzić stan i uzupełnić faktyczny odbiór. Osobny brak fokusu zdjęć
jest zapisany jako #561; nie zmieniono asercji, aby go ukryć.

Późniejsza aktualizacja po wznowieniu: środowisko jest kompletne w
/home/mateusz/kuking-work560, serwer8033 i PostgreSQL127.0.0.1:55439 działają.
Bazy kuking_560_browser oraz kuking_560_tests są osobne. Raport
[ZWARTE_KOLUMNY_560.md](ZWARTE_KOLUMNY_560.md) zapisuje wykonane48 konfiguracji,
akcje, pięć fizycznych negatywów, review oraz osobny problem fokusu zdjęcia
odtworzony także na bazowym main. Opis niepełnego vendor poniżej jest
historyczny. Wyniki push i PR CI są potwierdzone powyżej; wdrożenie czeka.

W nowym środowisku testy wymagają UTC w bazie (ALTER DATABASE dla
kuking_560_tests), APP_URL=http://localhost dla PHP oraz wartości
domyślnych z .env.example, w tym konfiguracji dysków. Pierwszy hook
prawidłowo zatrzymał wysyłkę przy tych brakach; po naprawie środowiska
przeszedł cały. Automat dostępności korzysta z dozwolonej nazwy
kuking_a11y na127.0.0.1:55439, strefaUTC; exit0, axe44/44, układ49/49.
Helpery output/git560.sh, prepare-push560.py, php560.py i a11y560.py
wskazują nową kopię. prepare-push560.py ma strażnika gałęzi #560 — przed
użyciem na gałęzi dokumentacji trzeba go świadomie dostosować.

Stan historyczny: 15 września 2026, po godzinie 07:27 czasu polskiego. Ten dokument ma
pierwszeństwo przed starym opisem środowiska /tmp i przed historycznym punktem
#557 w KONTYNUACJA_AUTONOMICZNA.md. Właściciel poprosił o przekazanie innemu
modelowi. Nie zaczynaj całego audytu od zera.

## Repo i uprawnienia

Repo: https://github.com/woogitsu/kuking.pl
Produkcja: https://kuking.pl
Repo kanoniczne: C:\Users\matma\Documents\Codex\kuking.pl
Gałąź WIP: fix/560-zwarte-kolumny.
Baza gałęzi/main ostatnio odczytany: 320c1d7173377f0f293afbab905b0f1bb6a806f7.
Najpierw przeczytaj aktualne AGENTS.md, dokumenty produktu, marki i
PULAPKI_TESTOW.md. Masz wcześniejszą zgodę użytkownika na autonomiczne
poprawki, testy, PR i merge po wymaganych kontrolach oraz wdrożenia. Nie
obchodź hooków/CI, nie nadpisuj cudzej pracy, chroń dane i prywatność.
Interfejs i dokumentacja po polsku, Laravel/Blade/Livewire/Alpine/Tailwind.

## Co rzeczywiście wdrożono

Alfa0.32 jest potwierdzona na produkcji. Końcowy SHA:
320c1d7173377f0f293afbab905b0f1bb6a806f7.
Railway6449248342 success2026-09-15T00:39:33Z, Deploy34914086222 success,
mainCI34914019647 success w zakresie dokumentacji.
HTTP pokazał0.32/320c1d7. CSS app-D1YYNQqe.css, SHA256
325c79a3d134a2b21fadd50cbe5cb023d6470df819eaf10047d9e9d6ec9d648e;
JS app-DXNAnudp.js i oba lokalne pliki Inter200.

PR558 (zmiana wyglądu publicznej tablicy), head
55ba96f77c89c6d1f635661256c712f0d0cf9491, merge
181b93f6f1f06c437b4bd93413a5bed23c136960. CI PR34908849825 oraz
main34910661018: wszystkie10 zadań success; PHP3814/76612 potwierdzone
w logach104191632718 i104197225378. Railway6448719034 i Deploy34912846146
success. Odbiór produkcyjny390/1440, oba motywy,12 kliknięć zdjęć, obejrzane
układy dań i osób. To nie jest produkcyjny odbiór całej macierzy zoom200%.

PR559 zawiera końcowe raporty, zrzuty i uzgodnienie statusów. Head
305d47a88bad05939c218025f9317074290b0390; zwykły hook/push PASS245,71s.
CI dokumentacji34913985482 success (ciężkie joby prawidłowo skipped).
Pierwszy push raportu zatrzymała wzmianka niepełnego adresu w backtickach;
naprawiono sam opis, bez osłabiania testu. Potem3 testy dokumentacji/41
asercji i pełny obowiązkowy hook przeszły.

Raport: docs/design/TABLICA_PUBLICZNA_557.md.
Dowody: docs/design/evidence/landing557/.
Odbiór końcowy: https://github.com/woogitsu/kuking.pl/pull/559#issuecomment-5672902753
#557, #555 i #551 są zamknięte jako wykonane. Profil0.30 i menu0.31 są
wdrożone; nie ponawiaj tych poprawek. Pełny port marki nadal CZĘŚCIOWO.

## Aktualne zgłoszenie #560

https://github.com/woogitsu/kuking.pl/issues/560
Właściciel pokazał sekcję „Świeżo z Kuking” na publicznej stronie głównej.
Karta On The Plate po lewej ma zaczynać się bezpośrednio pod krótszą kartą
bigosu, bez pustej przestrzeni wynikającej z wysokiej karty bułek po prawej.
To INNA sekcja niż „Co się dziś gotuje”, ukończona w #557.
Zrzut: C:/Users/matma/AppData/Local/Temp/codex-clipboard-355070ac-ae7c-450a-a7cc-8ea044ed5de8.png.

Przyczyna potwierdzona w kodzie: zwykły CSS Grid, dwie kolumny od64rem,
rząd ma wysokość najwyższej karty. Stara decyzja #365 świadomie akceptowała
te przerwy; najnowsze żądanie właściciela je zmienia. Nie używamy CSS columns
ani dzielenia serwerowo na dwie listy, bo zmieniłoby to kolejność czytania.

## Przygotowana implementacja — NIEODEBRANA

- resources/js/landing-wpisy.js: nowy mały moduł ResizeObserver.
  Obserwuje kontener i naturalne wysokości kart. W dwóch kolumnach nadaje
  jawny grid-row/grid-column: karta3 pod1,4 pod2 itd. DOM pozostaje bez zmian.
  requestAnimationFrame scala pomiary. Przy jednej kolumnie usuwa atrybut
  i style pozycjonowania. Obsługuje livewire:navigating/navigated i cleanup.
- resources/js/app.js: import modułu.
- resources/css/app.css: data-zwarte-kolumny, grid-auto-rows1px, row-gap0,
  margin-bottom kart z tokena spacing5. Bazowy układ i próg64rem zachowane.
  Uproszczono sprzeczny historyczny komentarz o wymaganych wyrównanych rzędach.
- resources/views/pages/landing.blade.php: aktualny komentarz zakresu;
  foreach oraz post-card bez zmiany danych.
- config/kuking.php i CHANGELOG.md: przygotowana Alfa0.33.
- docs/DECISIONS.md: nowa D-216, koszt wizualnego przechodzenia między różnymi
  wysokościami przy zachowaniu chronologicznej kolejności DOM/Tab.
- docs/brand/KONSTYTUCJA_MARKI.md: robocza1.13 z D-216.
- scripts/zwarte-kolumny.mjs: nowa, JESZCZE NIEURUCHOMIONA regresja
  rzeczywistych kart; odstępy, brak overflow, kontrolowana zmiana wysokości
  w DOM bez zapisu danych, przywrócenie, kolejność linków i wszystkich Tab.
  Funkcja sprawdzZwarteKolumny ma24 warianty (6 szerokości,2 motywy,2 skale).
- scripts/port-projektu.mjs: import i wywołanie tej funkcji, aby istniejący
  job CI faktycznie ją uruchamiał. Niczego z poprzednich testów nie usunięto.

NIE MA jeszcze PR #560 ani push tej gałęzi. Nie scalać tego jako gotowe.
Nie ma pomiarów browser, fizycznych negatywów ani końcowego CI dla #560.
Wykonano tylko build Vite i72 kontrasty przed utratą starego /tmp; build
aplikacji był udany (app-D7c_rylF.css / app-CCGAM7xp.js). Nowy test
przeglądarkowy dopisano później, więc NIE twierdzić, że był uruchomiony.

Readonly review agenta /root/review_formularzy_524527: brak blokera
w statycznym landing. ResizeObserver powinien się stabilizować dzięki
align-items:start; naturalne wysokości nie zależą od spanów. Zmiany
szerokości/fontu/mediów wymagają rzeczywistego sprawdzenia. Lista cards
jest zapamiętana przy inicjalizacji: dopisywanie kart do tego samego DOM
bez nawigacji nie jest obsługiwane. Obecny landing jest serwerowy,
więc to jawna granica, nie wykryta regresja. Agent nie wykonywał testów.

## Środowisko — WAŻNA ZMIANA PO WZNOWIENIU

Ubuntu WSL jest nadal dostępne, ale /tmp/kuking-final-20260913 oraz inne
stare katalogi /tmp ZNIKNĘŁY. Nie zakładaj, że stare bazy, serwer8033,
sesje, logi i pliki przeglądarki z /tmp istnieją. Przy ostatnim odczycie
port55439 nie nasłuchiwał. Nigdy nie używaj współdzielonego5432.

Zachowane:
- PHP /opt/kuking-php-8.4-avif/bin/php;
- PostgreSQL18 /usr/lib/postgresql/18/bin;
- Chromium /home/mateusz/.cache/ms-playwright/chromium-1243/chrome-linux64/chrome;
- Node24.19.0 i npx;
- vendor i node_modules w repo kanonicznym (sprawdź lock i aktualność).

Rozpoczęto odtwarzanie w trwalszym katalogu /home/mateusz/kuking-work560.
Helper output/rebuild560.py kopiuje źródła i zależności, potem miał tworzyć
osobny klaster /home/mateusz/kuking-local/pg55439, wiązać go WYŁĄCZNIE
z127.0.0.1:55439 oraz bazy kuking_560_browser i kuking_560_tests.
NA ŻĄDANIE PRZEKAZANIA zatrzymano dokładnie własny proces Python PID1069
(SIGTERM); sesja56251 zakończona. Nie ma aktywnego push ani testu.
W chwili zatrzymania istniał katalog vendor, ale nie node_modules;
kopiowanie vendor jest NIEKOMPLETNE. Klaster nie został uruchomiony,
port55439 nadal pusty. Nie uruchamiaj helpera bez poprawki: pomija istniejący
vendor, więc przy ponowieniu uznałby częściową kopię za gotową. Dokończ
kopię lub użyj poprawnego composer install, zweryfikuj platformę i lock.
Nie kasuj kanonicznego vendor ani cudzych katalogów. Zweryfikuj wszystkie
te fakty ponownie; stan mógł się zmienić po przekazaniu.

Helper skopiował źródła sprzed dopisania scripts/zwarte-kolumny.mjs;
przed testami ponownie synchronizuj aktualne pliki. Nie kopiuj .git
z kopii wykonawczej do repo kanonicznego. Metadanych Git w nowej kopii
jeszcze nie przygotowano. Stare output/git-native.sh, sync-final.py,
metadata-*.py i push-*.py wskazują /tmp i wymagają świadomej adaptacji.

output/fixture560.php i fixture560.py są tylko nieuruchomionym szkicem.
Wywołanie zatrzymało się na nieistniejącym cwd, zanim ruszyło PHP: nie
stworzono żadnego rekordu. Szkic zakłada nieistniejącą już bazę i użytkownika
landing557-1@example.test; NIE ponawiaj go w ciemno. Dla nowej izolowanej
bazy użyj poprawnie skonfigurowanego DemoSeeder i sprawdź gotowe media.
Nie odtwarzaj fikcyjnych danych na produkcji.

## Co zrobić dalej

1. Sprawdź branch/status/main, aktywne procesy i środowisko. Przeczytaj
   właściwe dokumenty; nie wracaj do zakończonego audytu #557.
2. Dokończ izolowane środowisko i odczytaj rzeczywiste DB host/port/nazwę
   przed jakąkolwiek migracją. Oddziel bazę pełnego PHP od browser.
3. Uruchom aplikację, pokaż rzeczywiste karty o różnych wysokościach.
   Odtwórz różnicę przed/po. Test musi zobaczyć minimum3 karty i różne wysokości.
4. Uruchom i popraw ewentualne błędy nowej regresji. Nie osłabiaj progów,
   by przepuścić kod. Sprawdź oba motywy,320/360/390/414/768/1440,
   tekst140%, rzeczywisty zoom200% (chrome.tabs.setZoom/getZoom jak
   w scripts/tablica-publiczna.mjs), font przeglądarki32px osobno.
   Sprawdź zmianę szerokości w tej samej stronie, ładowanie obrazów,
   kliknięcia Czytaj dalej, Tab i brak zasłaniania przez nagłówek.
5. Zaplanuj fizyczne negatywy prawdziwego JS/CSS: np. brak inicjalizacji,
   błędna wielkość wiersza oraz brak obserwacji rozmiaru kart. Kopia poza
   repo, zapis MD5 i mtime, przywrócenie + weryfikacja i wynik dodatni.
   Nie traktuj samego tekstowego testu źródła jako pomiaru geometrii.
6. Obejrzyj zrzuty. Poproś o końcowe niezależne review po testach.
7. Dodaj raport #560 z faktycznymi wynikami i ograniczeniami, uaktualnij
   macierz i KONTYNUACJA_AUTONOMICZNA.md. Uruchom istniejące testy rodzin
   landing/kart/dwóch kolumn; testy dokumentacji oraz pełny obowiązkowy hook.
8. Zwykły push, PR po odczycie zdalnego stanu (bez duplikatów po błędzie API).
   Wymagane CI, normalny merge, Railway i odbiór produkcyjnego SHA.
   Nie ogłaszaj Alfy0.33 wdrożonej na podstawie samego scalenia.

Helpery GitHub output/github_api.py używają istniejącego GCM bez wypisywania
sekretów. Po błędzie API odczytaj stan przed retry. Dotychczasowy job portu
marki trwał około22 minut; to nie jest samo w sobie zawieszenie.

Godzinna automatyzacja kuking-kontynuacja-prac pozostaje ACTIVE w tej rozmowie
01a09a2b-ecf9-78f0-9b57-e81c952fbb1b; nie twórz drugiej. Jeśli przenosisz
pracę do innej rozmowy, uzgodnij/zmień jej cel, żeby nie pracowały dwa modele.
Nie uruchomiono nowego agenta; istniejący reviewer zakończył readonly review.

Po #560 wróć do istniejącej kolejki #548 (odmiana minut), #549 (wyjaśnienie419)
i pozostałej macierzy #492. Nie ruszaj cudzego PR456, nie scalaj ponownie493,
nie twórz nowych funkcji bez potrzeby. Raportuj osobno kod, testy, CI i produkcję.
