# Niezależny audyt kompletności marki — Alfa 0.14

Data: 13 września 2026. Zakres: issue #498. **Status pełnego odbioru: CZĘŚCIOWO.**
Nie jest to deklaracja sprawdzenia każdej kombinacji danych i stanów. Raport oddziela odczyt kodu, pomiary, oglądanie zrzutów i rzeczywiste wdrożenie.

## Źródło prawdy

Punkt wyjścia zweryfikowany po pobraniu z GitHuba: `651def47122a3bd07896c165c32d2706d2462a3d`, Alfa 0.13. Historyczny PR #494 jest scalony, #493 nie wymaga ponownego scalenia. Otwarty PR #456 jest osobną historyczną pracą; audyt go nie zmienia.

Przeczytano AGENTS, dokumenty produktu i UX, architekturę, bazę, roadmapę, decyzje, konstytucję 1.3, COPY_STYLE, GLOS_MARKI oraz wcześniejsze audyty 010/012/013, fokus #485 i bramkę #484. D-206 określa pełny port działającej aplikacji. D-056 wymaga potwierdzenia logowania przez POST po otwarciu linku. Numeracji decyzji nie zmieniono.

Sprzeczność: przykład C3 w GLOS_MARKI oraz dwa ekrany logowania nadal sugerowały wejście po jednym kliknięciu w list. Rozstrzygnięcie wynika z D-056 i `LoginLinkController::confirmForm/store`: GET pokazuje potwierdzenie, POST zużywa token. Poprawiono przykład i instrukcje, zachowując zabezpieczenie. Niepotwierdzony adres ma osobną drogę ustawienia hasła; instrukcja uwzględnia ten wyjątek.

Standard pozostaje: Inter lokalnie na stronie, grafit/czerwień/neutrale, bazowe18px przy skali100%, cele48px, znak garnka, pięć pozycji nawigacji. E-mail używa Arial/Helvetica; nie wymaga pobrania Inter. Wyjątek drobnej stopki strony nie usprawiedliwia drobnego linku wypisania z listu.

## Dowód produkcji przed poprawką

- Railway `b20c4543-71a2-426b-957a-0d2da162d92d`: SUCCESS, production, pełny SHA `651def47122a3bd07896c165c32d2706d2462a3d`, zakończenie12:03:53UTC.
- Żywa stopka kuking.pl: Alfa0.13 /651def4. Sprawdzone także po zalogowaniu.
- CI934 `34756005585`: success; był to zakres dokumentacyjny, a nie ponowne wykonanie całego zestawu aplikacji. Deploy614 `34756069474`: success.
- `deploy.yml` opisuje natywny build/deploy Railway z Wait for CI; workflow Deploy sprawdza wynik. Zielony CI sam nie potwierdza uruchomienia.
- Produkcyjny DOM wskazuje `/build/assets/app-vD-_dZDP.css`; HTTP200, typ CSS, cache immutable. Wspólna rama, ciemny profil i lokalny Inter są rzeczywiście zastosowane. Ustawiona na koncie skala90% daje16,2px zgodnie z preferencją; nie pomylono jej z bazowym standardem18px.
- Niezależny build z katalogami kopiowanymi w etapie `assets` Dockerfile wygenerował dokładnie ten sam CSS: SHA256 `4406024af5f49424190406974ebd585c4fbd7b7529cb885a13c8772c9bcdca48`, identyczny z pobraną produkcją. Zwykły build całego checkoutu generuje dodatkowe utilities z dokumentów i przyrządów; różnica nazwy takiego pliku nie była dowodem starej produkcji. JavaScript: `app-DXNAnudp.js`. Oba WOFF2 Inter (latin i latin-ext) zwracają HTTP 200 i `font/woff2`.

## Potwierdzone braki i poprawki

| Problem | Zmiana | Regresja |
|---|---|---|
| Własne maile zawierały teksty14–17px | Dziesięć plików `resources/views/mail/*`; jedenasty już miał właściwy rozmiar | Render wszystkich11, `CzytelnoscWlasnychListowTest`, pomiar Chromium320px |
| Digest: Zobacz21px, wypisanie46,19px | Powiększony obszar linków bez zmiany adresów | Odczyt dziedziczonej interlinii/paddingu/min-height; rzeczywisty negatyw47px |
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

Pełna lista103tras GET i powierzchni poza routerem: [INWENTARZ_EKRANOW_MARKI_014.md](INWENTARZ_EKRANOW_MARKI_014.md). Wszystkich tras jest192. Tras plikowych, callbacków i przekierowań nie zaliczamy jako pozytywnie obejrzanych ekranów.

P = produkcyjny odczyt w przeglądarce; L = lokalny render/pomiar; V = zrzut rzeczywiście obejrzany; K = inwentaryzacja kodu. Samo K nie dowodzi zgodności wyglądu ani wykonania testu.

| Ekran/stan | Wizualna i tekstowa weryfikacja | Mobile | Test / braki |
|---|---|---|---|
| Start zalogowany, ciemny kafel, zakładki, tablica | P,V; zgodna rama i układ | P320 | Pełne kliknięcia/Tab sprawdza osobny skrypt fokusu |
| Wyszukiwarka i wyniki | P; Inter i aktualny CSS | P320 | Puste wyniki sprawdzane lokalnie |
| Odkryj / aktualności | P; rzeczywiste wpisy, bez ingerencji w treść | P320 | Różne dane nie oznaczają pełnej kontroli całej bazy |
| Własny profil | P,V; ciemny nagłówek i zachowana nawigacja | P320 | Bez zmiany profilu |
| Cudzy profil, obserwowani/obserwujący | L,V; dwa rzeczywiste konta demo, w tym długa nazwa, listy obserwacji | L320–1440, oba motywy i skale | 48 końcowych wariantów profili: HTTP 200 i brak overflow. Wcześniejsze 404 nie zaliczone jako profil |
| Dodawanie przepisu | P,V; formularz i widoczność | P320 | Nie publikowano na produkcji |
| Zdjęcie, wariant przepisu na jednej stronie | L; aktualna rama i formularze | L320–1440 | Walidacja wyłącznie lokalnie |
| Szczegóły wpisu, komentarze, menu | L; rzeczywisty wpis demonstracyjny, menu → edycja | L320–1440; edycja320/1440 | Nie sprawdzono każdej kombinacji komentarzy i działań |
| Przepis, gotowanie, szczegóły, wykonanie | L; widoki przepisu; edycja osobno jako właściciel | L320–1440; edycja320/1440 | 403 edycji cudzego przepisu jest wynikiem autoryzacji, nie odbiorem formularza |
| Zeszyt i kolekcje | P; strona zeszytu. L,V szczegół kolekcji z przepisem | P320; L320, tekst140%, oba motywy | Nie przenoszono zapisów użytkownika |
| Powiadomienia | P; odczyt strony | P320 | Nie wykonywano działań zbiorczych |
| Ustawienia ogólne | P; znaleziony i poprawiony opis e-mail | P320 | Regresja renderu komponentu |
| Czytelność, prywatność, bezpieczeństwo, dane | P; odczyt aktualnych stron | P320 | Bez zmiany ustawień produkcyjnych |
| Profil, zdjęcie, tagi, e-mail,2FA | L; wszystkie widoki ustawień, 2FA także rzeczywiście skonfigurowane lokalnie | L320–1440 | Potwierdzenia sekretów tylko w środowisku testowym |
| Login, link, potwierdzenie, odzyskanie | L,V; poprawione instrukcje, rzeczywisty błędny login i potwierdzenie linku | L320/1440, oba motywy, tekst140% | 32 warianty wejścia; formularze SMTP bez rzeczywistej wysyłki |
| Rejestracja, onboarding, OAuth | K,L,V; trzy strony onboarding osiągnięte lokalnie | L320, tekst140%, oba motywy | Pełnego POST rejestracji i zewnętrznych dostawców nie uznano za sprawdzonych |
| Pomoc, kontakt, informacyjne i prawne | L; render i pomiary, bez zmiany treści prawnych | L320–1440 | Nie wysyłano formularzy kontaktowych |
| Błędy500/503, offline, eksport | L,V; rzeczywiste samodzielne widoki bez Vite | L320/1440 | 32 pomiary; po poprawce eksport dodatkowo: jasny/ciemny ekran oraz jasny wydruk, bez overflow |
| Moderacja i obsługa | L,V; 9 paneli, bramka403 i rzeczywista konfiguracja2FA | L320/1440, oba motywy | 36 wariantów; puste kolejki i lista testowego użytkownika, bez operacji produkcyjnych |
|11własnych maili | L,V digest i mail techniczny; minimum18px | L320 | Brak overflow; cele minimum48px |
|7standardowych wiadomości Laravel | L, testy renderu | Historia012 + bieżące testy | Brak testu w rzeczywistym Outlook/Gmail/Apple Mail |
| PWA: ikony i aktualizacja istniejącej instalacji | K,L; bieżące regresje oraz osobny render offline | L320/1440 dla offline | Odbiór instalacji z audytu013 pozostaje historyczny; nie utożsamiamy go z nowym testem telefonu |
| Wykonane danie, „Komuś wyszło”, zgłoszenie | L,V; prawdziwe lokalne zasoby i formularz bez wysłania | L320, tekst140%, oba motywy | Potwierdzona własność ekranu świętowania; bez zgłoszeń produkcyjnych |
| 403/404 oraz 419/429 | L,V; rzeczywiste odpowiedzi403/404, osobne rendery419/429 | L320, tekst140%, oba motywy | Dla419/429 nie wykonano tu testu wywołania middleware |

## Kontrole ujemne

Zmieniano prawdziwe źródła, z kopiami poza repo i kontrolą MD5 po przywróceniu. Dodatkowo zachowano mtime. Po przywróceniu czasu pliku wymuszono aktualną kompilację Blade, żeby nie odczytać skompilowanego sabotażu z cache.

- Własne maile: font16, link bez paddingu, termin bez godziny, interlinia1 na „Podziękuj” (rzeczywiste47px w Chromium). Każda mutacja wykryta.
- Instrukcje: cztery źródła (login, formularz linku, potwierdzenie, nawigacja ustawień). Każda mutacja oblała właściwą asercję; po przywróceniu3testy/12asercji zielone.
- Status: przywrócenie „tym samym” oblało nowy test; po przywróceniu1test/10asercji zielony.
- Bramki2FA: dwa rzeczywiste źródła, wykryte stare obietnice czasu; po przywróceniu2testy/13asercji.
- Przepisy: pięć końcowych mutacji instrukcji, wymagalności pola, komunikatu i przycisku; każda wykryta, MD5 przywrócone. Zestaw sąsiednich regresji:38testów/240asercji.
- Parser: obcięcie rozszerzenia w rzeczywistym źródle wykryte; MD5 przywrócony, 3 testy / 41 asercji po odtworzeniu.
- Eksport: przywrócenie obietnicy czasu osobno w HTML i README oraz zamiana ciemnego media query na jasny — trzy negatywy wykryte, MD5 przywrócone; 9 testów / 113 asercji wraz z istniejącą regresją samodzielnych ekranów i rzeczywistego ZIP.
- Pierwszy lokalny przebieg pełnej kontroli wykrył wymagane formatowanie nowego testu. Poprawiono Pint; ten przebieg nie jest oznaczony jako pełny sukces.

## Zakres bieżących pomiarów

Szeroka macierz lokalna wykonała 912 odczytów 38 adresów: szerokości320/360/390/414/768/1440, dwa motywy i tekst100/140%. Nie stwierdzono poziomego przepełnienia dokumentu. 864 odpowiedzi miały status200;24 odpowiedzi404 dotyczyły nieistniejącego profilu testowego, a24 odpowiedzi403 — edycji cudzego przepisu. Tych48 odpowiedzi nie zaliczamy jako odbioru profilu i kreatora. W całej macierzy faktyczna czcionka treści wynosiła18px lub25,2px, zgodnie ze skalą. Atrybuty motywu i skali ustawiano do pomiaru; nie jest to test zapisywania preferencji.

Obejrzano reprezentatywne zrzuty strony głównej, profilu, formularza przepisu, prywatności, zeszytu, wejścia, moderacji, błędu500, eksportu i listów. Szeroka macierz była wykonywana podczas dopracowywania tekstów; końcowe zmienione formularze mają dodatkowy odbiór. Nie utożsamiamy wykonania pomiaru DOM z obejrzeniem wszystkich912 ekranów.

Końcowy niezależny odbiór świeżych źródeł: 12 wariantów trzech formularzy właściciela (320/1440, oba motywy, tekst 140%) oraz 48 wariantów dwóch cudzych profili (sześć szerokości, oba motywy, tekst 100/140%). Wszystkie 60 odpowiedzi HTTP 200, bez poziomego overflow. Obejrzano między innymi kreator 320 ciemny, edycję 320 jasną, profil 1440 ciemny oraz długą nazwę profilu przy 320 i tekście 140%. Nie oceniano jakości oczekujących na przygotowanie zdjęć demo.

Dodatkowe 22 warianty obejmują kolekcję z przepisem, wykonanie, ekran „Komuś wyszło”, trzy strony onboarding, zgłoszenie i błędy. Wyniki:14 odpowiedzi200,2 rzeczywiste403,2 rzeczywiste404 oraz4 rendery szablonów419/429 pod lokalnym adresem testowym (HTTP200). Wszystkie bez poziomego overflow. Obejrzane kadry nie ujawniły nowego błędu funkcjonalnego; przy320 i tekście140% pytajnik nagłówka onboarding może zawinąć się osobno — pozostaje drobną uwagą estetyczną.

Bieżące wykonania obowiązkowych skryptów:

- `port-projektu.mjs`: wynik poprawny, włącznie z kontrolami ujemnymi rzeczywistych stylów i przywróceniem MD5.
- `fokus-karty-dania.mjs`:36 wariantów oraz próby po przywróceniu; rzeczywisty Tab, kompletność linków i kliknięcia zdjęcia/opisu/wolnego obszaru; trzy kontrole ujemne wykryte.
- `kafel-dodawania.mjs`:15 wariantów, sześć kategorii naruszeń równych zeru.
- `kafel-dodawania-bramka.test.mjs`:1 test poprawny, obejmuje sześć kontroli ujemnych.
- `dostepnosc.mjs`:axe44/44 i układ49/49 poprawne. Wariant podwojonego fontu jest emulacją czcionki, nie rzeczywistym zoomem przeglądarki.

Drugi pełny przebieg wykrył błąd parsera tras nowego inwentarza. Jego wynik PHP nie był zielony; dalsze etapy (formatowanie, analiza, wyścigi, migracje i build) przeszły. Parser poprawiono zamiast wykluczać dokument z kontroli. Końcowy pełny przebieg i CI są osobnym warunkiem scalenia.

## Historyczne czerwone wyścigi

Odczytano ponownie oryginalną adnotację [job103702460527](https://github.com/woogitsu/kuking.pl/actions/runs/34749126034/job/103702460527): „The job was not started because it repeatedly failed to be acquired (5 attempts).” Test aplikacji nie wystartował. Sam brak logu404 nie był podstawą diagnozy. Głębsza przyczyna nieudanego pobierania zadania pozostaje nieustalona. Nie zmieniono D-105, progów ani `continue-on-error`.

## Ograniczenia i dalszy odbiór

- Narzędzie sterowania systemowym Chrome zatrzymało pracę, ponieważ nie mogło wiarygodnie ustalić bieżącego URL. **Rzeczywisty zoom przeglądarki200% nie został sprawdzony.** Emulacja podwojonej czcionki w dotychczasowych skryptach jest osobnym pomiarem.
- Nie testowano fizycznej klawiatury ekranowej, wszystkich klientów pocztowych, wszystkich callbacków OAuth ani wszystkich historycznych danych i skrajnych kombinacji stanów.
- Zalogowana produkcja jest dostępna do odczytu; stany wymagające publikacji, usuwania, zmian prywatności i wysyłki sprawdzamy wyłącznie lokalnie.
- Wynik finalnego CI, pomiarów i wdrożenia Alfa0.14 zostanie wpisany po ich zakończeniu. Obecna sekcja nie jest potwierdzeniem wdrożenia poprawek.
