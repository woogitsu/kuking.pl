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

Przygotowane lokalnie. Przed wdrożeniem wymagane pełny hook, PR i CI.
Pakiet bazuje na #573; scalić po poprzedniku. Brak migracji. Rollback przez
zwykły revert; zapisane dopuszczalne preferencje pozostają zgodne ze schematem.
Pełny port marki nadal CZĘŚCIOWO.

Dodatkowy odbiór konta ujawnił nieuwzględnione display:none dolnej belki
na desktopie. Poprawiono pomiar jej rzeczywistej wysokości. Test przechodzi
kolejno1440→320→390→1440 na tej samej stronie. Piąty fizyczny negatyw usuwa
warunek widocznej wysokości; FAIL i restore MD5/mtime oraz PASS potwierdzone.
Pierwszy pełny hook przeszedł przed tą poprawką JS; wymagany ponowny hook.
