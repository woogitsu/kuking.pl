# Niezależny audyt kompletności marki — Alfa 0.14

Data: 13 września 2026. Zakres: issue #498. **Status pełnego odbioru: CZĘŚCIOWO.**
Nie jest to deklaracja sprawdzenia każdej kombinacji danych i stanów. Raport oddziela odczyt kodu, pomiary, oglądanie zrzutów i rzeczywiste wdrożenie.

Późniejsze porównanie właściciela z wizualizacją „Dzień dobry, Basiu”
ujawniło brak zgodności kompozycji. Ten audyt nie dowiódł takiej zgodności.
Odtworzenie wzorca i jego osobny odbiór opisuje [raport #501](KOMPOZYCJA_STARTU_501.md).

## Źródło prawdy

Punkt wyjścia zweryfikowany po pobraniu z GitHuba: `651def47122a3bd07896c165c32d2706d2462a3d`, Alfa 0.13. Historyczny PR #494 jest scalony, #493 nie wymaga ponownego scalenia. Otwarty PR #456 jest osobną historyczną pracą; audyt go nie zmienia.

Wersje odczytane z plików blokad: Laravel 13.30.1, Livewire 4.4.3 i Tailwind 4.3.3. Lokalne wykonania używały PHP 8.4.24 i osobnego PostgreSQL 18.6; aplikacja zachowuje Blade i Alpine dostarczany z Livewire.

Przeczytano AGENTS, dokumenty produktu i UX, architekturę, bazę, roadmapę, decyzje, konstytucję 1.3, COPY_STYLE, GLOS_MARKI oraz wcześniejsze audyty 010/012/013, fokus #485 i bramkę #484. D-206 określa pełny port działającej aplikacji. D-056 wymaga potwierdzenia logowania przez POST po otwarciu linku. Numeracji decyzji nie zmieniono.

Sprzeczność: przykład C 3 w GLOS_MARKI oraz dwa ekrany logowania nadal sugerowały wejście po jednym kliknięciu w list. Rozstrzygnięcie wynika z D-056 i `LoginLinkController::confirmForm/store`: GET pokazuje potwierdzenie, POST zużywa token. Poprawiono przykład i instrukcje, zachowując zabezpieczenie. Niepotwierdzony adres ma osobną drogę ustawienia hasła; instrukcja uwzględnia ten wyjątek.

GLOS_MARKI zawierał też tabelę starej palety z komentarzem brzmiącym jak aktualna instrukcja. Pomiar z 11 września zachowano jako historię, ale jawnie oddzielono od tokenów po D-206 i bieżącej kontroli 72 par. DESIGN_SYSTEM już oznaczał swoje stare tabele jako historyczne. Nie przywracano dawnej palety w aplikacji.

Standard pozostaje: Inter lokalnie na stronie, grafit/czerwień/neutrale, bazowe 18 px przy skali 100%, cele 48 px, znak garnka, pięć pozycji nawigacji. E-mail używa Arial/Helvetica; nie wymaga pobrania Inter. Wyjątek drobnej stopki strony nie usprawiedliwia drobnego linku wypisania z listu.

## Dowód produkcji przed poprawką

- Railway `b20c4543-71a2-426b-957a-0d2da162d92d`: SUCCESS, production, pełny SHA `651def47122a3bd07896c165c32d2706d2462a3d`, zakończenie 12:03:53 UTC.
- Żywa stopka kuking.pl: Alfa 0.13 /651def4. Sprawdzone także po zalogowaniu.
- CI 934 `34756005585`: success; był to zakres dokumentacyjny, a nie ponowne wykonanie całego zestawu aplikacji. Deploy 614 `34756069474`: success.
- `deploy.yml` opisuje natywny build/deploy Railway z Wait for CI; workflow Deploy sprawdza wynik. Zielony CI sam nie potwierdza uruchomienia.
- Produkcyjny DOM wskazuje `/build/assets/app-vD-_dZDP.css`; HTTP 200, typ CSS, cache immutable. Wspólna rama, ciemny profil i lokalny Inter są rzeczywiście zastosowane. Ustawiona na koncie skala 90% daje 16,2 px zgodnie z preferencją; nie pomylono jej z bazowym standardem 18 px.
- Niezależny build z katalogami kopiowanymi w etapie `assets` Dockerfile wygenerował dokładnie ten sam CSS: SHA256 `4406024af5f49424190406974ebd585c4fbd7b7529cb885a13c8772c9bcdca48`, identyczny z pobraną produkcją. Zwykły build całego checkoutu generuje dodatkowe utilities z dokumentów i przyrządów; różnica nazwy takiego pliku nie była dowodem starej produkcji. JavaScript: `app-DXNAnudp.js`. Oba WOFF2 Inter (latin i latin-ext) zwracają HTTP 200 i `font/woff2`.

## Potwierdzone braki i poprawki

| Problem | Zmiana | Regresja |
|---|---|---|
| Własne maile zawierały teksty 14–17 px | Dziesięć plików `resources/views/mail/*`; jedenasty już miał właściwy rozmiar | Render wszystkich 11, `CzytelnoscWlasnychListowTest`, pomiar Chromium 320 px |
| Digest: Zobacz 21 px, wypisanie 46,19 px | Powiększony obszar linków bez zmiany adresów | Odczyt dziedziczonej interlinii/paddingu/min-height; rzeczywisty negatyw 47 px |
| Eksport pokazywał tylko datę zamiast rzeczywistego terminu | `data-export-ready.blade.php`: data i godzina w polskiej strefie | Stały termin UTC→czas polski |
| Login pomijał potwierdzenie oraz wyjątek niepotwierdzonego adresu | `auth/login*.blade.php`, przykład GLOS_MARKI | `InstrukcjaLogowaniaMarkiTest`; zachowane POST i CSRF |
| Potwierdzenie obiecywało wyłączność dostępu do konta | Tekst opisuje wyłącznie jednorazowość danego linku | Negatyw przywracający starą obietnicę |
| Status wysłania wymagał tego samego urządzenia | `LoginLinkController`: otwarcie także na innym urządzeniu | Dwa rzeczywiste POST, identyczny status i redirect przy tej samej masce adresu |
| Ustawienia sugerowały przesyłanie hasła e-mailem | `ustawienia-nawigacja.blade.php`: adres do wiadomości | Render komponentu i negatyw |
| Bramki 2FA obiecywały niezmierzony czas poniżej dwóch minut | Instrukcje wskazują potrzebną aplikację, bez obietnicy czasu | Rzeczywiste GET bramki 403 i ustawień 200, negatywy źródeł |
| Edycja przepisu mówiła, że nic nie jest obowiązkowe, mimo wymaganej nazwy i kroku przy publikacji | Rozróżnienie szkicu i publikacji w obu formularzach; pole przygotowania oznaczone jako wymagane w formularzu publikacji | GET formularzy, POST/PUT odrzucające brak kroku; szkic bez kroku nadal dozwolony |
| Kreator nazywał zapis opublikowanego przepisu szkicem | Komunikaty, przycisk i opis powrotu mówią o zmianach widocznych w opublikowanym przepisie | Rzeczywiste zapisanie przez Livewire, stan publikacji zachowany |
| Parser tras w dokumentacji ucinał profile i rozszerzenia | Odczyt pełnej trasy; tylko zmienny prefiks instalacji Livewire jest normalizowany | Poprawne profile/pliki oraz nieistniejące sufiksy; negatyw prawdziwego parsera |
| Pobrany eksport pozostawał jasny, a HTML i README obiecywały komplet zdjęć za kilka minut | Samodzielny ciemny motyw systemowy, jasny wydruk; spójna instrukcja w obu plikach zależna od zakończenia przygotowania zdjęć | Render i rzeczywisty ZIP z brakującymi zdjęciami i bez nich, trzy negatywy; Chromium: jasny/ciemny ekran i jasny druk |

Nie zmieniono danych użytkowników, uprawnień, mechanizmu sesji, tokenów, widoczności, mediów ani odbiorców poczty. Audyt nie wysyłał rzeczywistych e-maili. „Czytam wszystkie odpowiedzi” w digescie jest zapisanym zobowiązaniem gospodarza; kod potwierdza Reply-To, lecz nie pozwala sprawdzić realizacji tej obietnicy. Nie uznano jej automatycznie za fałsz ani za fakt operacyjnie zweryfikowany.

## Macierz ekranów i stanów

Pełna lista 103 tras GET i powierzchni poza routerem: [INWENTARZ_EKRANOW_MARKI_014.md](INWENTARZ_EKRANOW_MARKI_014.md). Wszystkich tras jest 192. Tras plikowych, callbacków i przekierowań nie zaliczamy jako pozytywnie obejrzanych ekranów.

P = produkcyjny odczyt w przeglądarce; L = lokalny render/pomiar; V = zrzut rzeczywiście obejrzany; K = inwentaryzacja kodu. Samo K nie dowodzi zgodności wyglądu ani wykonania testu.

| Ekran/stan | Wygląd | Teksty | Mobile | Test | Braki / ograniczenia |
|---|---|---|---|---|---|
| Start zalogowany, kafel, zakładki, tablica | P,V; zgodna rama i ciemny kafel | Odczyt etykiet; treści użytkowników bez zmian | P 320; L 320–1440 | Port, kafel, fokus, szeroka macierz | Zakres Tab i kliknięć określa skrypt fokusu |
| Szukanie, wyniki i brak wyników | P,L; Inter i aktualny CSS | Odczyt etykiet i pustego wyniku | P 320; L 320–1440 | Szeroka macierz i dostępność | Nie każda kombinacja filtrów |
| Odkryj / aktualności | P,L; aktualna rama | Treści społeczności wyłączone z redakcji | P 320; L 320–1440 | Szeroka macierz | Bez kontroli całej bazy treści |
| Własny profil | P,V; ciemny nagłówek | Odczyt nazw działań | P 320 | Port i odczyt produkcji | Bez zmiany profilu |
| Cudzy profil i listy obserwacji | L,V; dwa konta demo, długa nazwa | Nazwa nie jest obcinana | L 320–1440, oba motywy i skale | 48 końcowych wariantów profili: 200, bez overflow | Wcześniejszy błędny adres 404 nie zaliczony jako profil |
| Dodawanie przepisu | P,L,V; aktualny formularz | Poprawione wymagania nazwy i kroku | P 320; L 320/1440 | Końcowy odbiór formularzy i PHP | Bez publikacji na produkcji |
| Zdjęcie i formularz przepisu na jednej stronie | L; aktualna rama | Wymagane i opcjonalne dane rozróżnione | L 320–1440 | Szeroka macierz, GET i walidacja PHP | Walidacja wyłącznie lokalnie |
| Wpis, komentarze, menu i edycja | L; realny wpis demo i przejście menu → edycja | Odczyt działań | L 320–1440; edycja 320/1440 | Szeroka macierz i odbiór edycji | Nie każda kombinacja komentarzy i dialogów |
| Przepis, gotowanie, szczegóły i wykonanie | L,V; edycja także jako właściciel | Poprawione instrukcje oraz zapis szkicu/publikacji | L 320–1440 | Końcowe 12 wariantów formularzy, regresje POST/PUT/Livewire | 403 cudzego przepisu nie zaliczony jako odbiór edycji |
| Zeszyt i kolekcja | P,L,V; także kolekcja z przepisem | Odczyt etykiet | P 320; L 320/140%, oba motywy | Szeroka i dodatkowa macierz | Bez przenoszenia zapisów produkcyjnych |
| Powiadomienia | P,L; aktualna rama | Odczyt komunikatów | P 320; L 320–1440 | Szeroka macierz | Bez działań zbiorczych na produkcji |
| Ustawienia ogólne | P,L; aktualna rama | Poprawiony opis adresu e-mail | P 320; L 320–1440 | Render komponentu, kontrola ujemna | Odczyt produkcji |
| Czytelność, prywatność, bezpieczeństwo, dane | P,L,V; spójne widoki | Odczyt instrukcji | P 320; L 320–1440 | Szeroka macierz i port | Bez zmiany ustawień produkcyjnych |
| Profil, zdjęcie, tagi, e-mail, 2FA | L; wszystkie widoki ustawień | Usunięta niezmierzona obietnica czasu 2FA | L 320–1440 | Macierz; rzeczywiste włączenie 2FA lokalnie | Potwierdzenia sekretów tylko lokalnie |
| Login, link, potwierdzenie, odzyskanie | L,V; brak overflow | Poprawione instrukcje i obietnice; rzeczywisty błąd loginu | L 320/1440, oba motywy, tekst 140% | 32 warianty wejścia, render SMTP, regresje PHP | Bez rzeczywistej wysyłki poczty |
| Rejestracja, onboarding, OAuth | K,L,V; trzy strony onboarding | Odczyt instrukcji dostępnych ekranów | L 320/140%, oba motywy | Dodatkowa macierz | Bez pełnego POST rejestracji i zewnętrznych dostawców |
| Pomoc, kontakt, informacyjne i prawne | L; aktualna rama | Odczyt; treści prawne bez zmian | L 320–1440 | Szeroka macierz | Bez wysłania kontaktu |
| 500/503, offline, eksport | L,V; samodzielne widoki, eksport także ciemny | HTML i README bez gwarancji terminu zdjęć | L 320/1440 | 32 pomiary, dodatkowo ikona offline i ekran/druk eksportu | Emulacja fontu nie jest rzeczywistym zoomem |
| Moderacja i obsługa | L,V; dziewięć paneli | Poprawiona bramka 2FA | L 320/1440, oba motywy | 36 wariantów, rzeczywiste 2FA | Puste kolejki i testowa lista osób; bez działań produkcyjnych |
| 11 własnych maili | L,V; minimum 18 px, linki minimum 48 px | Sprawdzone instrukcje i termin; zobowiązanie Reply-To opisane wyżej | L 320 | Render 11 listów, pomiar i negatywy | Bez rzeczywistych klientów pocztowych |
| 7 standardowych wiadomości Laravel | L; render w CI | Bieżące regresje treści | Historia 012 + bieżące testy | Render PHP w CI | Bez odbioru Outlook/Gmail/Apple Mail |
| PWA i istniejąca instalacja | K,L; ikona offline wczytana | Odczyt instrukcji offline | L 320/1440 | Bieżące regresje i 8 końcowych wariantów offline | Instalacja z audytu 013 pozostaje historycznym dowodem |
| Wykonanie, „Komuś wyszło”, zgłoszenie | L,V; prawdziwe lokalne zasoby | Odczyt działań i formularza | L 320/140%, oba motywy | Dodatkowa macierz, sprawdzona własność | Formularz zgłoszenia bez wysłania |
| 403/404 i 419/429 | L,V; aktualne widoki | Odczyt dalszych kroków | L 320/140%, oba motywy | Realne 403/404; osobne rendery 419/429 | Dla 419/429 nie wywołano middleware w tym odbiorze |

## Kontrole ujemne

Zmieniano prawdziwe źródła, z kopiami poza repo i kontrolą MD5 po przywróceniu. Dodatkowo zachowano mtime. Po przywróceniu czasu pliku wymuszono aktualną kompilację Blade, żeby nie odczytać skompilowanego sabotażu z cache.

- Własne maile: font 16, link bez paddingu, termin bez godziny, interlinia 1 na „Podziękuj” (rzeczywiste 47 px w Chromium). Każda mutacja wykryta.
- Instrukcje: cztery źródła (login, formularz linku, potwierdzenie, nawigacja ustawień). Każda mutacja oblała właściwą asercję; po przywróceniu 3 testy/12 asercji zielone.
- Status: przywrócenie „tym samym” oblało nowy test; po przywróceniu 1 test/10 asercji zielony.
- Bramki 2FA: dwa rzeczywiste źródła, wykryte stare obietnice czasu; po przywróceniu 2 testy/13 asercji.
- Przepisy: pięć końcowych mutacji instrukcji, wymagalności pola, komunikatu i przycisku; każda wykryta, MD5 przywrócone. Zestaw sąsiednich regresji: 38 testów/240 asercji.
- Parser: obcięcie rozszerzenia w rzeczywistym źródle wykryte; MD5 przywrócony, 3 testy / 41 asercji po odtworzeniu.
- Eksport: przywrócenie obietnicy czasu osobno w HTML i README oraz zamiana ciemnego media query na jasny — trzy negatywy wykryte, MD5 przywrócone; 9 testów / 113 asercji wraz z istniejącą regresją samodzielnych ekranów i rzeczywistego ZIP.
- Pierwszy lokalny przebieg pełnej kontroli wykrył wymagane formatowanie nowego testu. Poprawiono Pint; ten przebieg nie jest oznaczony jako pełny sukces.

## Zakres bieżących pomiarów

Szeroka macierz lokalna wykonała 912 odczytów 38 adresów: szerokości 320/360/390/414/768/1440, dwa motywy i tekst 100/140%. Nie stwierdzono poziomego przepełnienia dokumentu. 864 odpowiedzi miały status 200;24 odpowiedzi 404 dotyczyły nieistniejącego profilu testowego, a 24 odpowiedzi 403 — edycji cudzego przepisu. Tych 48 odpowiedzi nie zaliczamy jako odbioru profilu i kreatora. W całej macierzy faktyczna czcionka treści wynosiła 18 px lub 25,2 px, zgodnie ze skalą. Atrybuty motywu i skali ustawiano do pomiaru; nie jest to test zapisywania preferencji.

Obejrzano reprezentatywne zrzuty strony głównej, profilu, formularza przepisu, prywatności, zeszytu, wejścia, moderacji, błędu 500, eksportu i listów. Szeroka macierz była wykonywana podczas dopracowywania tekstów; końcowe zmienione formularze mają dodatkowy odbiór. Nie utożsamiamy wykonania pomiaru DOM z obejrzeniem wszystkich 912 ekranów.

Końcowy niezależny odbiór świeżych źródeł: 12 wariantów trzech formularzy właściciela (320/1440, oba motywy, tekst 140%) oraz 48 wariantów dwóch cudzych profili (sześć szerokości, oba motywy, tekst 100/140%). Wszystkie 60 odpowiedzi HTTP 200, bez poziomego overflow. Obejrzano między innymi kreator 320 ciemny, edycję 320 jasną, profil 1440 ciemny oraz długą nazwę profilu przy 320 i tekście 140%. Nie oceniano jakości oczekujących na przygotowanie zdjęć demo.

Dodatkowe 22 warianty obejmują kolekcję z przepisem, wykonanie, ekran „Komuś wyszło”, trzy strony onboarding, zgłoszenie i błędy. Wyniki: 14 odpowiedzi 200, 2 rzeczywiste 403, 2 rzeczywiste 404 oraz 4 rendery szablonów 419/429 pod lokalnym adresem testowym (HTTP 200). Wszystkie bez poziomego overflow. Obejrzane kadry nie ujawniły nowego błędu funkcjonalnego; przy 320 i tekście 140% pytajnik nagłówka onboarding może zawinąć się osobno — pozostaje drobną uwagą estetyczną.

Stronę offline sprawdzono dodatkowo w 8 wariantach z rzeczywiście wczytaną ikoną garnka (320/1440, jasny/ciemny, font 16/32). Poprzednia kopia testowa pomijała plik ikony; uzupełniono przyrząd i powtórzono pomiar: zero brakujących obrazów i zero overflow. Obejrzano końcowy ciemny ekran 320 px. Nie była to zmiana ani awaria produkcyjnego pliku ikony.

Bieżące wykonania obowiązkowych skryptów:

- `port-projektu.mjs`: wynik poprawny, włącznie z kontrolami ujemnymi rzeczywistych stylów i przywróceniem MD5.
- `fokus-karty-dania.mjs`:36 wariantów oraz próby po przywróceniu; rzeczywisty Tab, kompletność linków i kliknięcia zdjęcia/opisu/wolnego obszaru; trzy kontrole ujemne wykryte.
- `kafel-dodawania.mjs`:15 wariantów, sześć kategorii naruszeń równych zeru.
- `kafel-dodawania-bramka.test.mjs`:1 test poprawny, obejmuje sześć kontroli ujemnych.
- `dostepnosc.mjs`: axe 44/44 i układ 49/49 poprawne. Wariant podwojonego fontu jest emulacją czcionki, nie rzeczywistym zoomem przeglądarki.

W trakcie pracy pełna kontrola wykryła błąd parsera tras nowego inwentarza, a regresja eksportu pomogła objąć także tekstowy README. Nie wykluczano dokumentów ani nie usuwano kontroli. Końcowa lokalna bramka przed wysłaniem przeszła: Pint, składnia, skrypty powłoki, Larastan, pełne testy PHP i odwracalność migracji. Build w kontekście Docker także przeszedł.

## Odbiór PR i wdrożenia Alfa 0.14

Poniższy odbiór dotyczy wdrożenia aplikacji z PR #499. Zapisanie wyników
w dokumentacji jest osobnym commitem i nie zmienia opisanych źródeł aplikacji.

- [PR #499](https://github.com/woogitsu/kuking.pl/pull/499) scalono dopiero po dziewięciu poprawnych zadaniach [CI #935](https://github.com/woogitsu/kuking.pl/actions/runs/34759245908). Sprawdzony SHA PR: `66d9806cbbeea70252e13a6e35f876158aa2fd50`; commit scalenia: `87f854177487629b5bf932e75949b9d06510f6b7`.
- Z odczytanego logu CI #935: **3692 testy PHP / 74 604 asercje**. Wyścigi: **5 testów / 44 asercje**. Dostępność: **axe 44/44, układ 49/49**, bez naruszeń; Lighthouse: **8/8 ekranów**, bez niezaliczonych wyników. Build Vite, oba obrazy, Pint, Larastan i audyt zależności poprawne.
- [CI main #936](https://github.com/woogitsu/kuking.pl/actions/runs/34759668117) oraz [Deploy #616](https://github.com/woogitsu/kuking.pl/actions/runs/34760121820) zakończyły się sukcesem. Pominięty Deploy #615 nie jest dowodem wdrożenia.
- Railway: deployment `f20d1859-470b-406e-88c1-df383b7c2a88`, środowisko production, **SUCCESS**, SHA `87f854177487629b5bf932e75949b9d06510f6b7`, zakończenie 13 września o **13:32:19 UTC**. Przed tym wynikiem deployment miał stan WAITING, co w raporcie nie było zaliczane jako działająca wersja.
- Po wdrożeniu żywa stopka pokazała **Alfa 0.14 / 87f8541** zarówno anonimowo, jak i w istniejącej zalogowanej sesji. Publiczny odbiór loginu, formularza linku i odzyskiwania: **12 wariantów** (320/1440, dwa motywy), wszystkie HTTP 200, bez poziomego overflow. Lokalny Inter w obu podzbiorach załadowany, bazowy tekst 18 px; CSS `app-vD-_dZDP.css` zgodny z końcowym buildem. Obejrzano produkcyjny zrzut ciemnego formularza linku z dodatkowym potwierdzeniem i wyjątkiem niepotwierdzonego adresu.
- Zalogowana produkcja: odczyt ustawień oraz trzech formularzy przy 320 px. Nowy opis adresu e-mail obecny; przygotowanie w formularzu publikacji ma `required`; rozbudowany formularz podaje wymaganie kroku; ustawienia 2FA nie zawierają obietnicy czasu. Wszystkie mieszczą się w szerokości. Zachowano istniejącą preferencję konta 90% (16,2 px), bez udawania testu produkcji przy 140%. Obejrzano końcowy formularz dodawania.
- Maile i wnętrze nowej paczki danych sprawdzono lokalnie oraz w CI; nie wysyłano produkcyjnych wiadomości ani nie zamawiano eksportu konta na potrzeby audytu. Ich obecność we wdrożonym kodzie wynika z potwierdzonego SHA, nie z deklaracji wysyłki lub odczytu produkcyjnego archiwum.

## Historyczne czerwone wyścigi

Odczytano ponownie oryginalną adnotację [job 103702460527](https://github.com/woogitsu/kuking.pl/actions/runs/34749126034/job/103702460527): „The job was not started because it repeatedly failed to be acquired (5 attempts).” Test aplikacji nie wystartował. Sam brak logu 404 nie był podstawą diagnozy. Głębsza przyczyna nieudanego pobierania zadania pozostaje nieustalona. Nie zmieniono D-105, progów ani `continue-on-error`.

## Ograniczenia i dalszy odbiór

- Narzędzie sterowania systemowym Chrome zatrzymało pracę, ponieważ nie mogło wiarygodnie ustalić bieżącego URL. **Rzeczywisty zoom przeglądarki 200% nie został sprawdzony.** Emulacja podwojonej czcionki w dotychczasowych skryptach jest osobnym pomiarem.
- Nie testowano fizycznej klawiatury ekranowej, wszystkich klientów pocztowych, wszystkich callbacków OAuth ani wszystkich historycznych danych i skrajnych kombinacji stanów.
- Zalogowana produkcja jest dostępna do odczytu; stany wymagające publikacji, usuwania, zmian prywatności i wysyłki sprawdzamy wyłącznie lokalnie.
- Potwierdzone poprawki Alfa 0.14 są wdrożone. Status pełnego odbioru pozostaje **CZĘŚCIOWO** z powodu wymienionych granic testów, przede wszystkim blokady rzeczywistego zoomu 200%. Tabela nie przypisuje wyniku pozytywnego nieuruchomionym stanom.
