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
