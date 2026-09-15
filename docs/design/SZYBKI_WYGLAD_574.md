# Szybkie ustawienia wyglądu — #574, Alfa 0.38

Właściciel zaakceptował panel po researchu istniejących preferencji oraz
W3C Resize Text i Focus Not Obscured. Nie jest to deklaracja pełnej
zgodności WCAG. Zakres: istniejące skale70–140%, dwa motywy, reset100/light,
podgląd, zapis gościa i konta, jednorazowa podpowiedź.

## Implementacja

Komponent szybki-wyglad, osobne JS/CSS. Native details oraz formularz POST
z CSRF działa bez JS; przyciski kroków i zamknięcia są wtedy ukryte.
ThemeController akceptuje opcjonalną skalę, nie resetuje jej przy starym
POST samego motywu. JSON jest private/no-store. Konto ma pierwszeństwo
nad serwerowym cookie; starszy ekran czytelności zapisuje też cookie.
LocalStorage zawiera tylko informację o poznaniu podpowiedzi.

Zapis rusza natychmiast. Kontrolki wyboru na czas odpowiedzi są zablokowane,
Zamknij i Escape pozostają aktywne. Zwykły link tej samej domeny czeka na
zakończenie zapisu. keepalive nie jest gwarancją zapisu po zamknięciu
przeglądarki. Timeout10s i błędna odpowiedź cofają podgląd do ostatniego
potwierdzonego wyboru, z komunikatem błędu. Nie zmieniono limitu trasy.
Panel ma przewijane wnętrze; ResizeObserver uwzględnia dolną nawigację.

## Dowody lokalne

- Pint, build Vite i72 pary kontrastu PASS.
- 21 testów/116 asercji: nowy panel, wcześniejszy motyw i skala konta.
- 36 geometrii:320/360/390/414/768/1440, jasny/ciemny, skala70/100/140.
- Rzeczywiste POST gościa, odświeżenie, reset; kontrolowany429 cofa podgląd.
- BezJS: wybór90% i zwykły POST. Opóźniony800ms zapis112% oraz przejście
  linkiem do logowania: docelowy HTML zachował112%.
- Konto: rzeczywisty zoom200%, innerWidth320, tekst140%; panel i przycisk
  mieszczą się nad dolną belką. To pomiar geometrii, nie fizyczny telefon.
- Cztery fizyczne negatywy: odczyt cookie, zapis konta, cofnięcie podglądu,
  geometria CSS. Kopie poza repo, MD5 i mtime przywrócone; każdy ponowny
  pomiar dodatni PASS. Blade wymagał view:clear po restore; samo odtworzenie
  mtime zostawiało skompilowany widok negatywu. Nie osłabiono asercji.
- Niezależne review wykryło600ms okno utraty wyboru; poprawione i odtworzone
  z opóźnionym transportem. Końcowy odczyt nie znalazł blokera zapisu.

Regresja scripts/szybki-wyglad.mjs jest częścią port-projektu.mjs.
Zrzuty, wyniki i negatywy w evidence/wyglad574. Nie odebrano fizycznego
czytnika, klawiatury ekranowej ani zmiany motywu już otwartego Turnstile.
Nie resetujemy CAPTCHA podczas zmiany wyglądu i nie kasujemy danych formularza.

## Dostarczenie

Wysłano PR #575, head 99538033e81052b9644c7cf6b3e827b198fc7524.
Zwykły pełny hook przeszedł także po poprawce desktopu.
CI 34974259551 wykryło zasłonięcie fokusu summary przez dolną nawigację
przy czcionce przeglądarki 32 px i szerokości 320 px (job 104397949295).
To rzeczywisty brak pomiaru relative nav i reakcji na scroll w nowym module,
nie awaria Lighthouse ani powód do osłabienia kontroli. Poprawiono uwzględnianie przewijanej nawigacji i aktualizację podczas scrolla.
Przed wdrożeniem wymagane ponowne kontrole na końcowym źródle i zielone CI.
Pakiet bazuje na #573; scalić po poprzedniku. Brak migracji. Rollback przez
zwykły revert; zapisane dopuszczalne preferencje pozostają zgodne ze schematem.
Pełny port marki nadal CZĘŚCIOWO.

Dodatkowy odbiór konta ujawnił nieuwzględnione display:none dolnej belki
na desktopie. Poprawiono pomiar jej rzeczywistej wysokości. Test przechodzi
kolejno1440→320→390→1440 na tej samej stronie. Piąty fizyczny negatyw usuwa
warunek widocznej wysokości; FAIL i restore MD5/mtime oraz PASS potwierdzone.
Pełny hook powtórzono po tej poprawce JS: PASS na9953803; nie obejmuje
jeszcze późniejszej naprawy ujawnionej przez CI przy relative nav.

## Regresja ujawniona przez CI #575

Naprawa obejmuje pozycję przycisku przy relative nav, otwarcie panelu w obrębie
okna przy braku miejsca i formularz w przepływie strony bez JS. Na szerokości
do400px znika wyłącznie ozdobne Aa; etykieta Wygląd i rozmiar tekstu zostają.
Kontrolki zawijają się, a kolumna formularza nie przekracza szerokości panelu.

Końcowy pomiar:12wariantów rzeczywistej czcionki przeglądarki32px
(320/390, oba motywy, tekst70/100/140) i108pozycji scroll.
Tab, Enter, oba selecty, Zamknij i Escape PASS; kontrolki wewnątrz panelu.
To odrębny pomiar od wcześniej wykonanego rzeczywistego zoomu200%.
BezJS: rzeczywisty POST70, reload i przywrócenie preferencji lokalnego konta PASS.
Dwa nowe fizyczne negatywy JS/CSS oblały odpowiednio kolizję i panel poza oknem;
MD5 i mtime przywrócone, końcowe pomiary dodatnie.
Obejrzane zrzuty fokusu i panelu obu motywów; końcowy odczyt niezależnego
review bez nowego blokera w zmierzonym zakresie. Dowody: evidence/wyglad574/ci575.
Kontrole fizycznego telefonu, czytnika i klawiatury ekranowej nadal niewykonane.

## Ponowne CI na a6958b6

Pełny zwykły hook i push a6958b658574487bddd9e226cac2e97c8e0fde49 przeszły.
CI 34978064181 zakończyło się jednak failure: ponownie wykryło zasłonięcie
przycisku przy czcionce przeglądarki 32 px na tablicy i ustawieniach profilu.
Wcześniejszy lokalny pomiar czekał po Tab 100 ms, więc nie dowodził braku
tego błędu w natychmiastowym pomiarze CI. Wymagana jest poprawka i regresja
bez tego oczekiwania. Ten sam przebieg wykrył osobno problem namalowanego
obrysu linku zmiany awatara przy rzeczywistym zoomie; diagnoza trwa.
Nie jest to odebrana ani wdrożona Alfa 0.38.

### Odsłonięcie fokusu profilu

Odtworzono drugie niepowodzenie tego CI na dokładnym zoomie 200%,
320×740 CSS px i tekście 140%. Nowy handler panelu przewijał stronę o około
84 px i wsuwał obrys zmiany awatara pod przypięty nagłówek. Samo usunięcie
przewijania zostawiało zasłonięty podpis i prawy dolny fragment obrysu.

Teraz przewijanie jest ograniczone rzeczywistym miejscem pod nagłówkiem.
Przy kolizji, której nie można tak rozwiązać, przycisk ustępuje do przepływu
strony; wraca po przewinięciu lub przejściu do panelu. Kliknięcie myszą
nie przenosi celu między naciśnięciem a puszczeniem przycisku.

Końcowe źródła: oba motywy, 53/53 przystanki Tab profilu; pełne cztery
odcinki obrysu bez kolizji; rzeczywiste kliknięcie, Shift+Tab, powrót po
scroll, Tab/Enter/Escape panelu. Ponownie 4 warianty bez czekania oraz
12 wariantów panelu / 108 pozycji scroll. Dwa fizyczne negatywy prawdziwego
JS wykryte, MD5 i mtime przywrócone, oba motywy ponownie PASS.
Regresja jest wywoływana przez istniejącą rodzinę rzeczywistego zoomu.

Dowody i obejrzane zrzuty: `evidence/wyglad574/ci575/profil/`.
Pusty wycinek `fixed-widget-tab.png` jest opisanym ograniczeniem
Playwright page.screenshot przy zoomie i dalekim przewinięciu, nie dowodem
poprawności. Końcowe obrazy przycisku pobrano bezpośrednio przez CDP,
tak samo jak raster istniejącej kontroli obrysu; pokazują przycisk i fokus.
Pełne CI oraz odbiór produkcji tej poprawki nadal wymagane.

## Diagnoza natychmiastowego fokusu po CI 34978064181

Lokalnie odtworzono błąd bez zmiany `scripts/dostepnosc.mjs`: pomocniczy
przebieg wywołał dokładną funkcję `przejdzTabemIZmierzFocus` odczytaną z tego
pliku, z limitem 400 kroków. Każdy Tab następował bez dodatkowego oczekiwania
przed pomiarem siatki 4×4. Użyto `/home` i `/ustawienia/profil`, trzech
powtórzeń, okna 320×740, rzeczywistej czcionki bazowej 32 px przez CDP oraz
`reducedMotion: reduce`. Wszystkie sześć przebiegów przed poprawką wykazało
częściowe albo pełne zasłonięcie przycisku przez dolną nawigację.

Przyczyna była podwójna. Tab przewijał stronę przed obsłużeniem zdarzenia
scroll, a handler focusin pomijał sam przełącznik. Dodatkowo globalna reguła
reduced-motion ustawia `transition-duration: 0.01ms !important` także na
elementach z domyślnym `transition-property: all`. Zmiana pozycji czekała więc
na klatkę: podczas diagnozy zmienna `--wyglad-dol` miała już około 249 px,
ale wyliczone `bottom` przełącznika nadal wynosiło 24 px. Samo skrócenie
animacji nie oznacza jej wyłączenia.

Końcowa poprawka przelicza geometrię synchronicznie na początku focusin,
przed powrotem dla elementów przełącznika. Wąski selektor wyłącza przejścia
CSS przełącznika, panelu i podpowiedzi (`transition-property: none`). Panel
wymaga tej samej ochrony przy zmianie między zwykłym układem a układem
w obrębie całego okna. Nie zmieniono globalnych reguł reduced-motion ani
timingu i asercji `dostepnosc.mjs`; eksperymentalne handlery keyup i dodatkowe
zabezpieczenie odłożonego callbacku usunięto z końcowej poprawki.

Wyniki końcowe:

- Dokładny algorytm CI: sześć pełnych przebiegów, pokrycie przycisku przez
  nawigację równe 0 w każdym z nich.
- Trwała regresja `sprawdzFokusBezCzekania` w `scripts/szybki-wyglad.mjs`:
  cztery przebiegi (320/360 px × obie trasy), pełny Tab, odczyt bez pauzy,
  siatka 4×4 i zero pokrycia. Wywołuje ją zwykły odbiór szybkiego wyglądu.
- Ponownie przeszło 12 konfiguracji otwartego panelu, jego kontrolek,
  zamknięcia i Escape oraz 108 pozycji przewijania nawigacji. Kontrolki
  mieszczą się wewnątrz panelu, nie tylko wewnątrz viewportu.
- Fizyczne usunięcie reguły wyłączającej przejścia CSS odtworzyło usterkę.
  To wyścig zależny od klatki: w zachowanym logu próba 0 była zielona,
  a próba 1 wykryła zasłonięcie. Nie przedstawiamy tej kontroli jako
  deterministycznej ani pierwszej zielonej próby jako dowodu braku błędu.
  Kopia CSS znajdowała się poza repo; po przywróceniu MD5 i mtime były
  identyczne, a wszystkie sześć pomiarów dodatnich ponownie przeszło.

Trwałe dane i opis metody: [dowody natychmiastowego fokusu](evidence/wyglad574/ci575/TIMING_FOKUSU.md).
To odbiór lokalny zmienionych źródeł, bez potwierdzenia nowego CI i wdrożenia.
Nie rozstrzyga osobnego problemu namalowanego obrysu awatara przy zoomie.
