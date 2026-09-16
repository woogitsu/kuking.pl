# Propozycja instalacji PWA — #278

Status: **robocze, niewdrożone**. Raport z 16 września 2026.
Podstawa gałęzi: `e30492ca7df87a221ae6092c82a3f98c083133a1`.
Alfa 0.46 jest przygotowaną wersją, nie potwierdzeniem produkcji.

## Zachowanie

Jedna propozycja na konto, w zwykłej karcie pod publikacją na stronie Start.
Warunek powrotu: poprzednia aktywność sprzed co najmniej 24 godzin.
Pierwsza wizyta nie wystarcza. Nie rozpoznajemy zamknięcia przeglądarki.
Serwer zachowuje stan; lokalna pamięć przeglądarki nie przechowuje odmowy.

Panel pozostaje ukryty bez JavaScriptu, bez `beforeinstallprompt` lub
w trybie standalone. Kliknięcie uruchamia zachowaną propozycję przeglądarki.
`userChoice: accepted` nie jest dowodem instalacji; do stanu `installed`
prowadzi dopiero sygnał `appinstalled`. To deklaracja klienta, nie
niezależne potwierdzenie systemu operacyjnego.

Żądania mają CSRF i godzinny kontekst konta oraz sesji. Stara karta nie może
zapisać decyzji na innym koncie. Prefetch/AJAX zachowuje w sesji poprzedni
czas wizyty na godzinę, lecz kwalifikacja następuje dopiero przy nawigacji.
Nawigacja przez service worker (`empty` + `navigate`) również jest obsługiwana.
Szczegóły schematu i rollbacku: [model danych](../DATABASE.md).

## Wykonane kontrole lokalne

- PHP 8.4.24, PostgreSQL na 55439, osobne bazy `kuking_278_tests`
  i `kuking_278_browser`; żadnych zapisów do produkcji.
- 98 testów / 1504 asercje: PWA, ostatnia wizyta, eksport, wymazanie,
  sygnały produktowe, migracje, wrażliwe kolumny i polityki tras.
- Build Vite oraz 72 pary kontrastu przeszły przed ostatnią korektą PHP.
- `scripts/pwa-install.test.mjs`: cztery testy rzeczywistego modułu JS
  z atrapami DOM/API, uruchamiane przez `npm run build`, a więc także
  istniejący job budowania assetów. Bez API/standalone, odmowa rezerwacji,
  awaria HTTP, jednorazowe `prompt`, rozdzielenie accepted/appinstalled,
  zamknięcie i cleanup. Fizyczna podmiana źródła uznająca accepted za
  installed oblała test; po przywróceniu MD5/mtime cały build przeszedł.
- PHPStan po korekcie kandydata sesji: brak błędów.
- Pierwszy pełny przebieg: 3901 testów / 78049 asercji, jedna porażka
  historycznego adresu Livewire w inwentarzu. Normalizacja obejmuje teraz
  dokładne warianty livewire.js/livewire.min.js zależne od app.debug.
  Kontrola dokumentacji przechodzi w obu trybach: po 3 testy / 49 asercji.
  Fizyczna podmiana adresu w inwentarzu na nieistniejący oblała test;
  przywrócono MD5 021f4299a710962477a9d1c15b022965 i mtime,
  a ponowny przebieg przeszedł. Powtórzony pełny PHP: 3901 testów,
  78057 asercji, bez porażek, kod wyjścia 0; trzy komunikaty PHPUnit
  typu notice. Czas 5 min 16 s, pamięć 294 MB.
- Fizyczne kontrole ujemne prawdziwych źródeł: brak pola w eksporcie,
  brak czyszczenia przy usunięciu konta, brak obsługi nawigacji przez worker,
  brak użycia zachowanego czasu kandydata, rozszerzenie kasowania telemetrii
  przy rollbacku na wszystkie sygnały. Każda wykryła usterkę.
  Kopie poza repo, przywrócenie MD5 i mtime, ponowny wynik dodatni.

## Przeglądarka

Rzeczywisty formularz logowania i HTTP lokalnego Laravel, Chromium 1234.
Do sprawdzenia panelu użyto **syntetycznych zdarzeń instalacji**. Nie jest to
odbiór natywnej instalacji na Androidzie ani iOS.

| Zakres | Wynik i granica |
|---|---|
| 320, 360, 390, 414, 768, 1440 px; jasny/ciemny; tekst 100/140% | 24 kombinacje: brak poziomego overflow, przyciski w szerokości okna i wysokości co najmniej 48 px |
| Ogląd | Obejrzano desktop oraz 320 px jasny/100% i ciemny/140%; zapisano zrzuty skrajnych szerokości |
| Tab przy 320 px i 140% | Oba przyciski odwiedzone kolejno; obrys 3 px + odsunięcie 2 px mieści się w oknie i nie przecina stałych ani sticky elementów. Obejrzano fokus „Nie teraz” na zapisanym zrzucie |
| Dotyk (emulacja Chromium/CDP) | Przy 390 px touchStart/touchEnd na „Nie teraz”: HTTP 200, stan dismissed w izolowanej bazie, brak panelu po reload. Nie jest to fizyczny telefon |
| Zoom karty 200% | Osiem wariantów: okno 640/1440 px, oba motywy, tekst 100/140%. chrome.tabs.getZoom=2, DPR=2, innerWidth=320/720, bez overflow; Tab dochodzi do obu przycisków. Zrzuty Playwright były ucięte; bezpośrednie chrome.tabs.captureVisibleTab dało poprawny obraz. Obejrzano wariant 320/ciemny/140: oba przyciski i fokus „Nie teraz” widoczne między paskami |
| Zamknięcie | Prawdziwe kliknięcie zapisuje `dismissed`; po odświeżeniu panel nie wraca |
| Wybór instalacji | Jedno wywołanie `prompt`; symulowane `accepted` pozostawia `requested`, dopiero `appinstalled` ustawia `installed` |

Zrzut lokalny: [fokus przy 320 px i tekście 140%](evidence/pwa278/fokus-320-ciemny-140.png).
Powtórzony pomiar 24 kombinacji na końcowym CSS również przeszedł.

## Niezależne review

Końcowy odczyt PHP i JS przez osobnego agenta nie wykazał nowych blokerów.
Review objęło przejścia stanów, kontekst konta i sesji, kandydata powrotu,
telemetrię, eksport oraz usunięcie konta. Nie zastępuje odbioru natywnej
instalacji. Rezerwacja offered zużywa propozycję także wtedy, gdy późniejsza
nawigacja przerwie jej wyświetlenie; to świadoma granica jednorazowości.

## Do ukończenia przed odbiorem

Trwały pomiar `scripts/pwa-install-browser.mjs` jest podłączony do grupy
rozszerzeń portu. Review wykryło i usunięto brak jego nazwy w trzech filtrach
CI; sprawdzenie rzeczywistych wyrażeń grep potwierdza uruchamianie kontroli
po zmianie samego skryptu. Pierwszy przebieg pomiaru trafił na HTTP 429,
bo powtarzał odmowę dla każdej geometrii. Poprawiona macierz używa jednej
propozycji, 24 układów i jednej odmowy; limity pozostają bez zmian.
Końcowe lokalne wykonanie tego wariantu przeszło: 24/24, pełny fokus,
odmowa przez Enter, HTTP/DB/reload, accepted bez installed, następnie
appinstalled oraz cleanup nawigacji. Fixture przywróciła stan konta
i sygnały; ponowny odczyt potwierdził zgodność z kopią.

- wykonanie trwałej regresji w CI;
- natywne Android Chrome/Edge i iOS Safari albo jawne ograniczenie dostępu;
- review końcowej regresji, pełny hook, CI oraz odbiór produkcji;
- dołączenie wybranych dowodów i aktualizacja macierzy #492.

Pełny port marki pozostaje **CZĘŚCIOWO**. Nie scalać na podstawie tego raportu
ani samych testów celowanych. Przy cofnięciu wdrożenia zachować kolumnę
decyzji: migracja odmawia utraty zapisanej jednorazowości/odmowy.

Kontrola ujemna CSS: rzeczywiste min-width 600px dla akcji wywołało overflow
i błąd pomiaru. Kopia poza repo, przywrócone MD5 3a79b0f2a82cd38c5d8510f9c4fb24f5
oraz mtime; ponowny build i pomiar 24 wariantów przeszły.
Zrzut zoomu: [200%, szerokość CSS 320, tekst 140%](evidence/pwa278/zoom200-320-ciemny-140.png).

Końcowa trwała regresja również wykryła fizyczne uszkodzenie CSS: min-width
600px spowodowało PWA_FOCUS z overflow. Przywrócono powyższe MD5 i mtime;
ponowny build oraz 24/24 warianty, HTTP/DB/reload i cleanup przeszły.

Testy dwóch połączeń uruchomiono w osobnym worktree i bazie
`kuking_race_kuking_pwa278_race` na 127.0.0.1:55439 (właściciel kuking).
Wynik: 5 testów / 44 asercje, kod 0. Pierwszy przebieg zgłaszał ostrzeżenia
przy braku lokalnego pliku .env; po przygotowaniu izolowanej konfiguracji
powtórzenie przeszło bez ostrzeżeń. Jest to istniejąca grupa regresji
blokad i usuwania kont, nie osobny dowód równoczesnych żądań instalacji PWA.

## Aktualizacja po CI — 16 września, wieczór

Pierwsze CI wykazało rzeczywisty brak pliku scripts/pwa-install.test.mjs
w etapie assets Dockerfile. Dodano jawny COPY; budowanie dokładnego zestawu
plików tego etapu przeszło (4 testy JS, 72 kontrasty, Vite). Nie jest to jeszcze
pełny build obrazu. Część pozostałych zadań przerwała utrata runnera.
Zintegrowano main 5b4c748 (kolaż), zachowując jego zmiany. Przygotowana wersja
to teraz Alfa 0.49, aby nie cofnąć0.47 i oczekujących linków0.48.
Przed merge trzeba ponownie zintegrować finalny pakiet linków oraz uruchomić
pełny hook i CI. Natywna instalacja Android/iOS pozostaje nieweryfikowana.
