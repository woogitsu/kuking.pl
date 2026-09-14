# Macierz pokrycia identyfikacji — odbiory i ograniczenia

## Uzupełniony ogląd wiadomości — 14 września 2026

[Odbiór 18 wiadomości](ODBIOR_WIADOMOSCI_027.md) uzupełnia historyczne
wiersze poczty poniżej: obejrzano każdy z 11 własnych szablonów i siedmiu
Laravel MailMessage przy 320 oraz 640 px, razem 36 zrzutów. Nie znaleziono
błędu układu w tych wariantach. To lokalny Chromium na źródłach 595f41f,
bez wysyłki, nie dowód zgodności z Gmail/Outlook/Apple Mail. Nie sprawdzono
każdego wariantu treści, trybu ciemnego klienta ani obsługi odpowiedzi.

## Lokalne poprawki zgłoszeń — Alfa 0.27, przed wdrożeniem

[Raport poprawek](POPRAWKI_ISSUES_ALFA_027.md) opisuje #538 (komunikat
rzeczywistego limitu imienia) i #444 (systemowy odstęp przed usuwaniem
komentarza i odpowiedzi), ich regresje oraz fizyczne kontrole ujemne.
[Nawigacja przy małej wysokości](NAWIGACJA_NISKI_WIDOK_492.md) uzupełnia
wcześniejsze ograniczenie zasłaniania treści; [mieszana karuzela](KARUZELA_MIESZANA_431.md)
dodaje kontrolowaną próbkę obu orientacji do automatu.
Zamknięcie historycznych #440/#445/#448 i nieodtwarzalnego #518 nie
oznacza ponownego odbioru całego portalu. Pełny port: **CZĘŚCIOWO**.

## Potwierdzone wdrożenie Alfy 0.26 — 14 września 2026

[Odbiór produkcji](ODBIOR_PRODUKCJI_ALFA_026.md): PR #535 scalony jako
`d7f92870bf9a0a3471e305c370ab79524c316d79`, dziesięć sukcesów CI PR i main,
Railway **6435698933 success o 11:36:07 UTC**, Deploy 34839034097 success.
HTTP i zalogowany Chrome potwierdziły **0.26 / d7f9287**, nowy arkusz
z poprawionym selektorem oraz czytelne przyciski w podpowiedziach.
Pełny port pozostaje **CZĘŚCIOWO**; dokładne zakresy odbiorów są poniżej.

## Dodatkowy lokalny odbiór moderacji — 14 września 2026

[Raport ścieżek moderacji](ODBIOR_MODERACJI_2026_09_14.md) uzupełnia
historyczne wiersze panelu i odwołań. Prawdziwe lokalne formularze z CSRF
potwierdziły ukrycie wpisu, brak działania, odwołanie, cofnięcie decyzji,
przywrócenie wpisu i odmowy 403. Logowanie moderatora i administratora
obejmowało rzeczywiste wyzwanie TOTP; wiadomości pozostały lokalne.

| Ekran lub stan | Odbiór wyglądu i treści | Mobile i zoom | Ograniczenie |
|---|---|---|---|
| Pełna kolejka zgłoszeń i formularz odwołania | Długie treści, realne działania, zrzuty | 320/768/1440, dwa motywy, 100/140; osiem wariantów zoomu 200% | Pierwsza tablica pomiarów JSON nie zachowała się; są zrzuty; późniejszy Tab opisano w osobnym wierszu |
| Pełna kolejka odwołań, wynik autora i oba puste stany | Działania i stan bazy potwierdzone; reprezentatywne zrzuty obejrzane | 48 zachowanych pomiarów; osiem zachowanych pomiarów zoomu | Brak poziomego overflow nie oznacza pełnej dostępności fokusu |
| Otwarte formularze zgłoszenia i odwołania, normalne oraz z błędem | 42/42 oczekiwanych przystanków Tab; podsumowanie otrzymuje fokus | Osiem wariantów: zoom 200%, CSS 320, tekst 140%, oba motywy | Widoczny fragment każdej kontrolki; częściowe zasłanianie długiego błędu, bez deklaracji pełnej zgodności WCAG |
| Zamknięte kolejki | 16 trafień Tab w linki treści z obrysem | 320 / tekst 140% | Nie jest to odbiór wszystkich pól ani zasłaniania przy zoomie |

Nie potwierdzono nowej usterki w tym zakresie. Niezależny review porównał
raport z zachowanymi dowodami. Pełny port pozostaje **CZĘŚCIOWO**:
przy zoomie 200% i tekście 140% stała nawigacja zajmuje znaczną część
wysokości. Uzupełnienie Tab nie obejmuje każdej kombinacji decyzji, sterowania
wszystkimi opcjami radiowymi ani jednoczesnej widoczności całego długiego błędu.

## Regresja gotowości strony w teście fokusu #536

[Diagnoza i kontrole ujemne](FOKUS_ZDJECIA_BEZ_JS_536.md) wyjaśniają
czerwone zadanie pierwszego CI PR #535: pomiar bez JS zaczynał przed
załadowaniem CSS. Dodano kontrolowane opóźnienie arkusza i oczekiwanie
na zasoby, zachowując Tab i obrys. To poprawka testu, nie usunięcie
fokusu w aplikacji. Końcowy head przeszedł pełne CI; wdrożenie opisano powyżej.

## Lokalna regresja kontrastu #534 — zakres dowodu przed wdrożeniem

Potwierdzono, że szeroki selektor `.notice a` nadpisywał kolory przycisków.
[Raport regresji](KONTRAST_PODPOWIEDZI_534.md): 24 konfiguracje, 72 pomiary
normal/hover/fokus programowy, trzy kontrole ujemne rzeczywistego CSS
z odtworzeniem MD5 i mtime oraz 12 wariantów prawdziwego zoomu 200%.
Reprezentatywne zrzuty obejrzano. To zakres odbioru lokalnego; późniejsze wdrożenie potwierdza raport powyżej. Pełny port marki nadal **CZĘŚCIOWO**.

## Potwierdzone wdrożenie Alfy 0.25

[Odbiór produkcji](ODBIOR_PRODUKCJI_ALFA_025.md) potwierdza Railway
`6433957176` success o 09:34:16 UTC, zielone main CI i Deploy oraz
rzeczywistą stopkę **0.25 / b4d5d8e**. Na istniejącym prywatnym wpisie
obejrzano poprawiony komunikat widoczności. Nie wykonywano zapisów danych
produkcyjnych. Ogląd ujawnił słaby kontrast przycisku w podpowiedzi `.notice`;
późniejsza diagnoza i poprawka lokalna są opisane powyżej jako #534. Pełny port nadal
**CZĘŚCIOWO**, z ograniczeniami opisanymi w raporcie.

## Historyczny odczyt bezpośrednio po scaleniu PR #531 — 14 września 2026

PR #531 scalono jako `b4d5d8e875006ad165660f7f2af8cc5b2b7ca77e`.
CI `34822658081`: wszystkie dziesięć zadań success, w tym port marki.
PHP z logu joba `103908931994`: **3793 testy / 76259 asercji**.
To potwierdza scalenie Alfy 0.25, ale jeszcze nie jej wdrożenie.

Ostatnia potwierdzona produkcja to **Alfa 0.24**,
`b0712cf8df4a3508ec76e9fada31b4f839c99169`: Railway `6433191800` success
14 września o 08:44:33 UTC, Deploy `34824265942` success, main CI
`34822427307` success. Rzeczywisty HTTP ponownie zwrócił stopkę 0.24 /
`b0712cf`; CSS, JS i oba lokalne fonty Inter odpowiadają 200. Odczyt wersji
i zasobów nie jest pełnym oglądem wszystkich stanów produkcyjnych.

Poniższe etapy i wyniki pozostają zapisem historycznym. Aktualizacja nie
przypisuje ich automatycznie nowszym wydaniom. Pełny port: **CZĘŚCIOWO**.

## Dodatkowy odbiór lokalny — 14 września 2026, kod 24afa9e

[Tryb gotowania](ODBIOR_TRYBU_GOTOWANIA_2026_09_14.md) uzupełnia historyczny
wiersz tej trasy: 48 konfiguracji, w tym rzeczywisty zoom 200%, długa
instrukcja 4000 znaków, faktyczne przejścia, Tab/Enter i zachowanie
odhaczenia. Reprezentatywne zrzuty obejrzano; nie testowano fizycznego
telefonu, Wake Lock ani wysłania wykonania.

[Fokus szczegółów przepisu](ODBIOR_FOKUSU_SZCZEGOLY_530.md): cztery
przebiegi po 25 elementów przy prawdziwym zoomie. Nie odtworzono całkowitego
zasłonięcia fokusu, ale przy tekście 140% pozostaje tylko 101,5 px między
nawigacjami, a napis etykiety zdjęcia wymaga przewinięcia. Ten pomiar nie
obejmuje błędów walidacji. Pełny port nadal **CZĘŚCIOWO**.

[Sprostowanie indeksów #532](ZRODLO_STYLU_532.md) usuwa kierowanie modeli
do historycznego kitu jako bieżącej identyfikacji. Nie zmienia oryginalnych
paczek ani wyglądu aplikacji.

## Odbiór produkcji Alfy 0.23 i dalsze poprawki

Railway deployment 6432286380 dla `6db1b8978f8d7e2229a77e8dd4701ffd510a1b6a`: success 14 września 2026 o 07:43:37 UTC. CI main 34817233565: completed/success. Rzeczywisty odczyt produkcji pokazał Alfę 0.23 i `6db1b89`; CSS, JS i oba lokalne pliki Inter zwróciły 200. W zalogowanej przeglądarce Chrome po odświeżeniu wyszukiwarki również odczytano Alfę 0.23 i `6db1b89`. Obejrzano produkcyjne `/szukaj` (puste pole i brak polecanych tagów) oraz `/home` (rzeczywisty strumień, ciemna karta dodawania i boczne sekcje). To ogląd bieżącego widoku desktopowego, nie pełna macierz skal i stanów produkcji.

[Składniki #526](SLOWNIK_SKLADNIKOW_526.md) i [autozapis #528](AUTOZAPIS_KREATORA_528.md) mają lokalną regresję po integracji i odbiór przeglądarkowy opisany w raportach. [Komunikaty widoczności #530](KOMUNIKATY_WIDOCZNOSCI_530.md) uzupełniają ten pakiet. Końcowy zakres lokalny: 104/1194, 16 kontroli ujemnych, opisane odbiory przeglądarkowe. Alfa 0.25 jest przygotowywana, bez potwierdzenia wdrożenia. Pełny port nadal **CZĘŚCIOWO**.

## Uzupełnienie lokalne #524 i #527

[Pełny przepis po 419/429](ODZYSKIWANIE_PELNEGO_PRZEPISU_524.md):
48 konfiguracji, osiem prawdziwych zoomów, 51 kroków odzyskanych jeden do
jednego, rzeczywiste ponowienie utworzenia i edycji z kontrolą CSRF w PHP.
[Podsumowanie walidacji](PODSUMOWANIE_WALIDACJI_527.md): poprawione sześć
widoków i historyczne zalecenie COPY_STYLE. Razem 93 testy / 671 asercji
oraz 13 negatywów źródła w opisanych zakresach. Alfa 0.24 przygotowana
lokalnie; wynik nie jest potwierdzeniem wdrożenia.

[Odbiór pełnej wiadomości moderatora](ODBIOR_WIADOMOSCI_MODERACJI_2026_09_14.md)
uzupełnia historyczny wiersz paneli: pełna treść, historia trzech stanów,
brak adresu, walidacja notatki. Wyłącznie lokalne dane; bez wysyłania listów.
Pełny port marki nadal **CZĘŚCIOWO** odebrany.

## Uzupełnienie lokalne #523 — 419 i 429

[Odbiór odzyskiwania formularzy](ODZYSKIWANIE_FORMULARZA_523.md) uzupełnia
historyczny wiersz 419/429: rzeczywiste odpowiedzi middleware, trzy stany,
144 konfiguracje układu, osiem prawdziwych zoomów częściowego odzyskania
i reprezentatywny ogląd. Usuwa potwierdzone sprzeczności komunikatów oraz
nakaz logowania na publicznej trasie. Zakres nie obejmuje wszystkich
formularzy, naturalnego wygaśnięcia sesji ani klawiatury ekranowej.
Wdrożenie Alfy 0.23 wymaga osobnego potwierdzenia; pełna marka: CZĘŚCIOWO.

## Aktualny stan — 14 września 2026, po scaleniu PR #521

Pakiet #515–516 jest scalony: PR #521, head
`1cb2ab5f485a0130a992b1cd4e5ba8db2a02b672`, merge
`a3cb64df819351b18450603c1dcabe775aa748f0`.
Obowiązkowy lokalny hook i zwykły push zakończyły się sukcesem.
CI PR `34792102646`: **10 zadań success**, PHP **3726 testów / 75240 asercji**.
Port marki job `103818145082` zakończył się sukcesem po 17 min 54 s;
moduł tagów zaliczył 192 konfiguracje, cztery przejścia bez JS oraz
sześć rzeczywistych negatywów CSS z przywróceniem końcowego źródła.
Wyniki wcześniejszych prób poniżej pozostają zapisem historycznym.

Potwierdzenie wdrożenia i granice odbioru opisuje
[odbiór Alfa 0.22](ODBIOR_PRODUKCJI_ALFA_022.md).
Pełny port marki nadal ma status **CZĘŚCIOWO**; pozytywny CI nie oznacza
osobistego oglądu każdej strony, stanu i klienta poczty.

13.09.2026. Źródło przekazane do przeglądu: `667ace890492f02b1221e259a73977cac0897ee3`, PR #517 / #511. CI `34780301310` trwało przy rozpoczęciu tej notki; ta notka nie weryfikuje jego zakończenia ani wdrożenia. Inwentaryzacja opiera się na `routes/web.php`, istniejących raportach i przyrządach, uzupełnionych aktualnym spisem tras oraz oddzielnym lokalnym odbiorem 20 wariantów stanów #511 opisanym poniżej. Aktualizacja tej notki nie uruchamia kolejnych testów ani nie mutuje źródeł.

**Pełna kompozycja wszystkich ekranów i stanów nie ma kompletnego odbioru.** Zielony pełny suite nie oznacza obejrzenia każdego stanu. Historyczne wyniki są oznaczone datą/raportem; późniejsza poprawka wspólnego CSS nie aktualizuje automatycznie ich zrzutów.

## Źródła i znaczenie oznaczeń

- **R014**: `docs/design/AUDYT_KOMPLETNOSCI_MARKI_ALFA_014.md`, zwłaszcza macierz, zakres pomiarów i ograniczenia. Zawiera historyczny ogląd reprezentatywnych zrzutów, nie wszystkich 912 odczytów.
- **R012**: `docs/design/AUDYT_SPOJNOSCI_ALFA_012.md:90–118`; produkcyjne odczyty i lokalne samodzielne ekrany/poczta. 200% oznacza tam font, nie zoom.
- **R508**: `docs/design/AUDYT_PACZKI_MARKI_508.md`: mapowanie 19 widoków oryginału; K to kod, nie pomiar.
- **R509**: `docs/design/KOMPOZYCJE_MARKI_509.md`: 432 konfiguracje kompozycji, 48 prawdziwych zoomów, ograniczenie Tab do zamkniętych menu.
- **R511**: `docs/design/ZESZYTY_MARKI_511.md`: końcowy lokalny port; 96 konfiguracji zeszytów i 64 zoomy ośmiu tras. Nie jest potwierdzeniem produkcji #517.
- **S511**: `docs/design/ODBIOR_STANOW_ZESZYTU_511.md` i `output/stany511/results.json`: dodatkowy lokalny odbiór 20 wariantów pięciu stanów, reprezentatywny ogląd zrzutów oraz przebiegi Tab. 320/1440, oba motywy, tekst140 przy320 i100 przy1440; **bez rzeczywistego zoomu200**.
- **V**: raport jawnie mówi o obejrzeniu zrzutu / obraz obejrzany w tej sesji. **L**: lokalny pomiar lub render. **P**: historyczny odczyt produkcji. **K**: inwentaryzacja źródła. Brak indywidualnego V nie oznacza, że ekran nie działa; oznacza brak takiego dowodu.
- **M6**: 320/360/390/414/768/1440, oba motywy. R509/R511 dodają 100/140%, bazowy font 32 px i jego połączenie z 140%. **Z**: rzeczywiste `chrome.tabs.setZoom(2)`, sprawdzone API/DPR/połowa viewportu; nie CDP zmieniające wyłącznie font.

`docs/design/INWENTARZ_EKRANOW_MARKI_014.md` pozostaje historycznym inwentarzem. Aktualny `output/routes-517.json` wygenerowano rzeczywistym `php artisan route:list --json` w kopii native po synchronizacji gałęzi roboczej opartej na main `34b4b61` (trasy niezmienione względem PR #517): **192 wpisy**, w tym **100 GET|HEAD**, **3 GET|POST|HEAD**, 63 POST, 14 DELETE i 12 PUT. Zatem 103 wpisy obsługują GET; ani100, ani103 nie jest liczbą ekranów lub obejrzanych stanów. Spis obejmuje też pliki, przekierowania i endpointy systemowe. Bieżący `routes/web.php` stanowi źródło rodzin poniżej. Prefiksy Livewire zależą od instalacji i nie są ekranami. Wspólny kod wzorca poza 19 makietami to `layout.blade.php`, `tokens.css`, `marka-rama.css`, dokumenty marki i komponenty formularzy; brak oddzielnego widoku w ZIP nie zwalnia z tych zasad.

## Historyczna macierz odbioru #511 — późniejsze uzupełnienia poniżej

Ścieżki widoków poniżej są względem `resources/views/`. Nazwa testu oznacza konkretny przyrząd lub rodzinę regresji; wyniku indywidualnego testu nie dopisujemy z samego faktu jego istnienia.

| Ekran / stan i trasy | Kod wzorca | Faktycznie oglądany dowód | Mobile / tekst / zoom | Test / wykonany zakres | Brak lub ograniczenie |
|---|---|---|---|---|---|
| Publiczna `/`: kroki, wykonanie, własność | `pages/landing`, `marka-wlasnosc.css`; D208/D210 | V w odbiorach506/R509; R511 zachowuje port landingu | M6 R509, Z R509/R511 | `port-projektu`, `kompozycje-marki`, `zoom-marki`, `KompozycjaWejsciaMarkiTest` | Nie każda kombinacja brakującego kolażu, tablicy i wspomnienia ma osobny V |
| Zalogowane `/` i `/home`: strumień, composer, tablica | `pages/home`, composer, kuking-board; D207 | P/V R014 i odbiór501 | Historyczne mobile/tekst, port; nie przypisywać Z publicznego `/` zalogowanemu `/home` | Port/kafel/fokus; wcześniejszy kontrakt GET home/root | Sam Z publicznego Startu nie sprawdza zalogowanego strumienia |
| `/odkryj`, `/szukaj`: wyniki/bez wyników; `/tagi`, `/tag/{tag}` | `FeedController`, `SearchController`, `TagController`; rama/listy | P/L R012/R014, wybrane V wyszukiwarki i R508 | Historyczne 320–1440 i font; brak Z w zestawie ośmiu tras R511 | Szeroka macierz i dostępność | Kafle były zakresem #515 — zakończonym w PR #521; wszystkie kombinacje filtrów nadal nieobejrzane |
| `/@{username}` własny/cudzy, długie nazwy i rzeczywiste liczby | `pages/profile/show`, `marka-profil.css`; D210 | V R509; `output/final511/zoom200-ania-false.png`, `…true.png` | M6, Z; nowa fixture ujawniła i naprawiła obrys szyny | 432 konfiguracje, `KompozycjaProfiluMarkiTest`, pomiar pasa, Z64 i negatyw szyny | Nie wszystkie stany relacji/blokad kont mają indywidualny ogląd |
| `/@{username}/obserwowani`, `/@{username}/obserwujacy` | `pages/profile/connections` | L R014; brak wskazanego osobnego aktualnego V | Historyczna macierz, bez bieżącego Z | Dostępność i polityki | Pusta lista vs wielostronicowa nie mają osobno przypisanego V |
| `/login`, `/register` formularz podstawowy | `components/marka-wejscie`; D210 | V R509 | M6; Z obu motywów; Tab7/7 i10/10 w fixture | `KompozycjaWejsciaMarkiTest`, kompozycje, zoom | Sukces pełnej rejestracji i realne zewnętrzne uwierzytelnienie nie wynikają z GET |
| Błąd loginu, `/logowanie/link`, token linku, `/nie-pamietam-hasla`, `/nowe-haslo/{token}` | auth views/controllers | V/L R014; P/V formularza linku po wdrożeniu014 | R014:32 warianty wejścia; produkcja12; brak Z tych podtras w R511 | `InstrukcjaLogowaniaMarkiTest`, regresje tokenów/enumeracji, render SMTP | Brak rzeczywistej wysyłki; nie każdy token ważny/zużyty/wygasły ma osobny V |
| `/zaproszenie/{token}`, `/potwierdz-email`, podpisane potwierdzenie, `/cofnij-usuniecie-konta`, `/logowanie/kod` | auth/account views, odpowiednie kontrolery | K; R014 zbiorczy odbiór instrukcji dostępnych ekranów | Nie ma podstaw do przypisania pełnego M6/Z wszystkim stanom | Istniejące regresje funkcjonalne; nie dopisano indywidualnego wykonania | Osobno wymagają dowodu stany wygasłe, zużyte, błędne i sukcesu |
| Google/Facebook: start, callback, domknięcie i łączenie | `Auth/*LoginController`, widoki domknięcia | K, ograniczony L R014 | Bez pełnego Z i realnego dostawcy | Testy serwerowe nie są odbiorem przeglądarki dostawcy | Callback/przekierowanie nie liczy się jako obejrzany ekran; pełny OAuth niepotwierdzony |
| `/witaj/zainteresowania`, `/witaj/ludzie`, `/witaj/gotowe` | onboarding views; prototyp onboarding | V/L R014 dla trzech kroków; K R508 | 320/140 historycznie; nie Z R511 | Dodatkowa macierz014; regresje onboardingu/N+1 | Kompozycja #513; puste dane/brak propozycji nie są automatycznie objęte trzema GET |
| `/dodaj`, `/dodaj/zdjecie`, `/wpisy/{post}/edycja`, `/wpisy/{post}/zdjecia` | add/posts views | L R014, przejście menu→edycja; wybrane V | Historyczne szerokości, edycja320/1440 | Macierz i testy upload/walidacji | Nie każde upload/loading/error/sukces ma osobny V; produkcji nie publikowano |
| `/dodaj/przepis`, `/dodaj/przepis/jedna-strona`, `/przepisy/{recipe}/edycja`, `/przepisy/{recipe}/szczegoly` | recipes views / Livewire wizard | V R014/R508, właściciel; błędny403 wykluczony z odbioru edycji | R014 końcowe12 wariantów; brak Z tych formularzy w R511 | GET/POST/PUT/Livewire: szkic, wymagany krok, publikacja | Test POST nie potwierdza wizualnego komunikatu każdego błędu |
| `/przepisy/{recipe}`: foto/no-image/pełny opis | recipes/show, `marka-przepis.css`; D210 | V R509, wcześniejsze P R012 | M6 oba warianty; Z reprezentatywnego przepisu | Kompozycje/zoom; lokalne otwarcie i zamknięcie zdjęcia | Nie każdy typ atrybucji, licznik i stan niedostępnego autora oglądany |
| `/przepisy/{recipe}/gotuj` | cooking views; prototyp gotuj | P/L R012/R014, brak nowego osobnego V511 | Historyczne mobile/font; bez Z511 | Historyczny pomiar i testy trybu | Nie przypisywać mu Z szczegółów przepisu ani testu minutnika z makiety |
| `/wpisy/{post}` i rozmowy/otwarte menu | posts/show, post-card, comment-thread | L R014, wybrane działania lokalne | Historyczny M6; zakres fokusu kart określa osobny skrypt | `fokus-karty-dania`, szeroka macierz | Z509/511 jawnie pomija ukryte wnętrza zamkniętych details; nie obejmuje wszystkich dialogów |
| `/przepisy/{recipe}/ugotowalem`, `/ugotowane/{cookedEvent}`, `/ugotowane/{cookedEvent}/wyszlo` | cooked views | V/L R014 z rzeczywistymi lokalnymi zasobami | Dodatkowe320/140 oba motywy | Dodatkowa macierz i testy własności | Brak nowego Z i wszystkich wariantów błędów formularza |
| `/zeszyt` z folderami i ostatnimi zapisami | collections/index, recent component, `marka-zeszyt.css`; D211 | V R511; `output/final511/zeszyty511-index-*` | M6×2motywy×4pisma; Z | Moduł96 (łącznie2trasy),5negCSS, PHP scopedDOM, pełny port | Zrzuty są lokalnymi danymi, nie produkcjąPR517 |
| `/zeszyt` pusty, otwarty formularz, walidacja utworzenia | ten sam widok + error-summary/details | S511: L wszystkich wariantów; V reprezentatywnego pustego indeksu, formularza i błędu | 320/1440 oba motywy; tekst140 na320; bez Z200 | Rzeczywiste rozwinięcie, Tab i lokalny niepoprawny POST; opis zachowany, formularz otwarty, niezależny odczyt0 utworzonych zeszytów | Część dodatkowych20wariantów, nie wcześniejszych96; bez poprawnego wysłania i bez osobnego oglądu każdej grafiki |
| `/zeszyt/{collection}`: foto/no-image/długi tytuł | collections/show, recipe-card wariantkafel | V R511; `output/final511/zeszyty511-show-*` | M6×2×4, Z | Moduł96, klikzdjęcia, PHP i5negCSS | Strona2 i ograniczona widoczność nie mają osobnego V tej fixture |
| `/zeszyt/{collection}` pusty i zawierający tylko wpisy | collections/show, post-card, szyna zeszytów | S511: L i reprezentatywne V obu stanów | 320/1440 oba motywy; tekst140 na320; bez Z200 | Rzeczywiste lokalne dane, Tab, pomiar bez poziomego overflow; brak fikcyjnych zdjęć/przepisów | Część dodatkowych20wariantów; nie obejmuje wszystkich akcji wpisu, publicznej cudzej kolekcji ani paginacji |
| Zeszyty: prywatność, paginatory, niedostępne zapisy | CollectionController/Policy, modele | Kod i PHP; nie V | Nie przypisywać M6 trzem przepisom wszystkim wariantom danych | R51141testów/241asercji; niezależne paginatory i filtry zachowane | Odbiór zachowania nie oznacza oglądu komunikatu „niedostępne” na każdej szerokości |
| `/powiadomienia` zwykłe i decyzyjne | notifications/index i typy zdarzeń | P/L R014, K R508 | Historyczna macierz, bez Z511 | Regresje widoczności/zdarzeń; szeroka dostępność | #513 zwykłe karty; nie wszystkie typy, brak danych i działania zbiorcze mają V |
| `/ustawienia` i profil/zdjęcie/tagi/czytelność/prywatność/bezpieczeństwo/e-mail/dane | settings views; wspólne formularze | P/L/V R014 dla reprezentatywnych widoków | Historyczny M6; ustawienia produkcji zachowane90%, nie udawane140% | Port i regresje ustawień | GET nie dowodzi zapisu każdej preferencji; realna klawiatura ekranowa niebadana |
| 2FA: `/ustawienia/2fa`, `/ustawienia/2fa/wlacz`, `/ustawienia/2fa/kody-zapasowe`; potwierdzenie e-mail | settings/two_factor, EmailSettingsController | R014 rzeczywiste lokalne włączenie2FA; sekrety tylko lokalnie | Historyczny mobile, bez Z511 | `InstrukcjaBramkiModeracjiTest`, testy2FA | Nie każdy błąd/kod zapasowy ma osobny V; nie ujawniać sekretów w artefaktach |
| Pomoc/zasady/o-kuking/regulamin/prywatnosc | static views + layout | P/L R012/R014; bez przypisania V każdej podstronie | Historyczne320/1440/font oraz macierz | Dostępność | Treści prawne nieaudytowane merytorycznie; nie ma Z511 |
| Kontakt i dziękujemy, zgłoś treść/zgłoszenia/szczegół, odwołania, zgłoszenie nielegalne/przyjęte | contact/reports/appeals views | L R014, V reprezentatywnego zgłoszenia; kontakt bez wysłania | Historyczne320/140 + macierz | Regresje formularzy; dostępność wybranych dróg | Potwierdzenia sukcesu nie wynikają z obejrzenia formularza; nie wszystkie listy puste/pełne |
| Admin: zgłoszenia/sygnały/odwołania/bez-odpowiedzi/wiadomości/kolaz/kuking-na-dzis/tagi/użytkownicy | `pages/admin/*`, własna bramka2FA | R014: V/L dziewięciu paneli, puste kolejki i lista osób | R01436 wariantów320/1440×2motywy; brak Z511 | R014 rzeczywiste2FA; bieżący dostępność wybiera część paneli | Nie rozciągać9 paneli na wszystkie 11 GET ani szczegóły wiadomości/osoby; pełne kolejki i wyniki działań niepotwierdzone V |
| Bramka moderatora bez2FA / odmowa zwykłemu kontu | admin/wymagane_2fa, middleware | V/L R014 dla bramki | Historycznie320/1440 | Dedykowany render i realny negatyw instrukcji | Nie jest dowodem wyglądu panelu za bramką |
| 403/404 | errors views / rzeczywiste odmowy | V/L R014, realneHTTP403/404 | 320/140 oba motywy historycznie | Dodatkowe warianty014 | To dowód błędu, nie odbiór niedostępnego profilu/edycji |
| 419/429 | errors views | V/L R014: osobny render lokalny **HTTP200** | 320/140 historycznie | Render szablonu | Nie wywołano właściwego middleware w tym odbiorze; brak pełnego scenariusza sesji/limitu |
| 500/503 | samodzielne errors layouts | V/L R012/R014 | 320–1440/font historycznie, nie Z | `SamodzielneEkranyMarkiTest`, niezależne rendery | Brak celowej awarii produkcji; nie przypisywać dostępności bazy/service całej ścieżce awarii |
| Offline/PWA | `public/offline.html`, serviceworker | V ciemnego320 R014, realnie wczytana ikona | 8 końcowych wariantów014; historyczne16/32font | Test prawdziwego źródłaSW/fallback/cache | Instalacja013 historyczna; brak aktualnego odbioru wszystkich systemów i rzeczywistego Z |
| ZIP eksportu: HTML/README, foto jeszcze przetwarzane, druk | exports/* | V/L R014, jasny/ciemny ekran i jasnydruk | Historyczne320/1440, brak Z | `EksportNieObiecujeTerminuTest`, `EksportMowiOZdjeciachWDrodzeTest`, prawdziwyZIP/negatywy | Odczyt pobranego HTML wChromium nie obejmuje wszystkich przeglądarek/offline czy klientówZIP |
| 11 własnych maili | mail/* | R014: 11 renderów; V reprezentatywnego digestu i listu technicznego | 320; minimum18px/link48, realne negatywy | `SpojnoscWiadomosciMarkiTest`, rodziny pocztowe | Nie wszystkie11 osobiście obejrzane; bez wysyłki i klientów pocztowych; Reply-To nie dowodzi czytania odpowiedzi |
| 7 standardowych Laravel MailMessage | vendor/mail/html/theme+header, notifications | R012: render 7 klas, Chromium320/640; R014: render CI; brak dowodu V każdej klasy | R012CTA183×56 i zawijanie długiegoURL; nie Z | `StandardoweWiadomosciMarkiTest`, treści powiadomień | Brak lokalnego vendor/notifications/email nie jest luką: nadpisanymotyw działa przezLaravel. Bez Outlook/Gmail/AppleMail; teksty automatu #514 |
| Pliki/callbacki/system | zdjęcia, robots,sitemap,health, eksportdownload, Livewire, OAuth | K; aktualny spis `output/routes-517.json`; pojedyncze odczyty nie są ekranami | Nie stosować metryki geometrii ekranów doJSON/pliku/przekierowania | Testyautoryzacji/tras | Wchodzą do192 wpisów tras, ale nie zwiększają liczby „obejrzanych ekranów” |

## Luki dowodu zapisane po #511 — stan historyczny

1. **Puste/błędne/sukcesowe stany nie dziedziczą wyników happy-path.** S511 domknął ograniczony odbiór pustego indeksu, otwartego formularza, rzeczywistej walidacji, pustej kolekcji i samych wpisów:20wariantów bez poziomego overflow, z reprezentatywnym V. Nie rozszerza to wyniku96wariantów ani rzeczywistego zoomu200 na te stany. Druga strona, ograniczona widoczność i sukces utworzenia nadal nie mają tu osobnego V.
2. **Ówczesny Z dotyczył ośmiu wybranych tras, nie całego serwisu.** Późniejszy zintegrowany port #521 obejmuje jedenaście tras; szczegółowe zakresy #513 i #515 opisano poniżej. Nie ma podstaw do pozytywnego wyniku Z dla ustawień, kreatora, gotowania, administratora, poczty i błędów. Historyczne font200 pozostaje osobnym dowodem, nawet po udanym Z509/511.
3. **Moderacja:** puste kolejki i bramka2FA są faktycznymi stanami, lecz nie dowodzą skomplikowanej wypełnionej karty, szczegółów wiadomości/użytkownika ani wyniku akcji. Pełny PHP sprawdza zachowanie, nie wygląd wszystkich takich stanów.
4. **PocztaLaravel:** istnieje port motywu i realny render; nie ma dowodu zgodności wszystkich klientów. Nie należy zgłaszać „brak portu standardowych maili” wyłącznie na podstawie braku jednego pliku vendor. Raport012 zawiera pozytywny, ale historyczny pomiar.
5. **Awaria:** render419/429 podHTTP200 nie jest end-to-end sesji/limitera; ekran500 nie dowodzi poprawnego działania podczas każdej awarii zależności. Brak takiego ćwiczenia nie jest potwierdzonym błędem aplikacji.

## Zgłoszenia na etapie #511 — stan historyczny

**Nie potwierdzono nowego błędu kompozycji ani nowej sprzeczności funkcjonalnej w odczycie pokrycia i dodatkowym odbiorze S511.** Podejrzenie zasłonięcia wielowierszowego linku błędu wyjaśniono osobną analizą czterech rzeczywistych fragmentów i zrzutem fokusu w jasnym motywie; wspólny prostokąt obejmował puste miejsca między wierszami. Szczegółowe ograniczenia tej diagnozy zawiera S511. Nie tworzymy usterek z samego braku pomiaru. Ówcześnie otwarte rodziny #513–516 zostały następnie zakończone w PR #519–521; aktualizacje poniżej rozdzielają ich zakresy i dowody. Powyższych ograniczeń nie wolno natomiast usuwać z końcowego twierdzenia o kompletności.

## Aktualizacja po scaleniu

PR #517 scalono do main `34b4b61109ebd1e1c808066191609672cd5ae332`.
CI PR `34780301310`: wszystkie dziewięć zadań success; PHP 3707 testów
i 74853 asercje. To aktualizuje status CI, nie rozszerza zakresu wizualnego
powyższej macierzy. Stan produkcji należy odczytać osobno w przekazaniu.

## Aktualizacja #513 — odbiór lokalny, przed scaleniem

Raport [zainteresowań i powiadomień](ZAINTERESOWANIA_POWIADOMIENIA_513.md)
rozszerza dwa wiersze macierzy o 96 konfiguracji, 16 nowych wariantów
rzeczywistego zoomu, pełne decyzje moderacyjne i rzeczywiste lokalne POST.
Obejrzano reprezentatywne zrzuty 320/140 oraz desktop 1440 w obu motywach. Końcowy pełny port przeszedł z 80 rzeczywistymi zoomami dziesięciu tras i sześcioma negatywami zoomu; nie rozszerza to wyniku na inne trasy. Dodatkowe osiem
pustych stron i cztery przejścia paginacji mają tylko dowód HTTP, treści
i braku poziomego overflow; nie dziedziczą pełnych skal ani Tab.

Produkcja Alfa 0.19 jest potwierdzona dla `34b4b61`: [osobny odbiór](ODBIOR_PRODUKCJI_ALFA_019.md).
Powyższy odbiór lokalny uzupełniono po scaleniu PR #519: Alfa 0.20
`ce82638dc6a69be3f73dbc0094db7cb7cede6e97` ma potwierdzone CI, Railway,
HTTP i ograniczony ogląd zalogowanej produkcji — [osobny odbiór](ODBIOR_PRODUKCJI_ALFA_020.md).
Status pełnego portu marki pozostaje **CZĘŚCIOWO**. Ta aktualizacja nie
rozszerza odbioru na wszystkie typy wiadomości, klientów poczty, panele
moderatora ani pozostałe zakresy #514–516.


## Aktualizacja #514 — 14 września 2026

PR #520, head `c2a05d015ba6e197f3be5bb3334864c3967ec582`, scalono jako
`ae6b519cd5b7ccf68bc43e7b2e61ff46d54de8c2`. CI PR `34788400687`:
10 zadań success, PHP 3719 testów / 75077 asercji. Szczegóły, rzeczywiste
negatywy, lokalny render MailMessage i ograniczenia w
[raporcie precyzji](PRECYZJA_KOMUNIKATOW_514.md). Nie przeprowadzono
wysyłki do rzeczywistych klientów poczty ani całego zewnętrznego OAuth.
Samo scalenie nie potwierdza wdrożenia Alfa 0.21.

## Aktualizacja #515–516 — prace lokalne

Kafle prawdziwych promowanych tagów na pustej wyszukiwarce oraz
[sprostowanie instrukcji modeli](SPROSTOWANIE_INSTRUKCJI_516.md)
są przygotowane lokalnie. Końcowy zintegrowany port, CI, scalenie
i produkcja tego pakietu wymagają osobnego potwierdzenia.
Nie zmienia to statusu pełnej marki: **CZĘŚCIOWO**.


Alfa 0.21 ma teraz potwierdzone wdrożenie Railway6427633492 i rzeczywisty
odczyt metryczki ae6b519. Zakres oglądu i ograniczenia:
[odbiór produkcji0.21](ODBIOR_PRODUKCJI_ALFA_021.md).


Końcowy lokalny odbiór pustej wyszukiwarki (#515):
[szczegółowy raport](POLECANE_TAGI_515.md) — 192 konfiguracje, cztery
przejścia bez JS do wszystkich tagów i 112 wariantów prawdziwego zoomu
(w tym 16 wyszukiwarki). Pełne/puste dane, gość i konto zalogowane,
pełne nazwy/opisy, widoczny fokus i rzeczywiste negatywy. Ogląd obejmuje
reprezentatywne zrzuty, nie każdy wariant. Ten odbiór nie rozszerza
pokrycia wszystkich filtrów i wyników ani innych tras z wspólnego wiersza.

## Uzupełnienie odbioru #539

Potwierdzono i poprawiono kontrast fokusu przy jednoczesnym najechaniu na przycisk podpowiedzi. Regresja: 88 wierszy pomiarów, pięć kontroli ujemnych rzeczywistego CSS i cztery próby prawdziwego zoomu. Niezależny końcowy odbiór po integracji z nawigacją: ciemny motyw, zoom 200%, tekst 140%, Tab 14/14 PASS wraz z hover + focus-visible. Wcześniejszego nieudanego przebiegu nie traktujemy jako pozytywnego. Szczegóły: [FOKUS_PODPOWIEDZI_539.md](FOKUS_PODPOWIEDZI_539.md). Wdrożenie pakietu wymaga osobnego potwierdzenia.
