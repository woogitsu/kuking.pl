## D-241 — Lokalne wzorce spamu sprawdzają także treść niepubliczną; wysyłka do OpenAI bez zmian (22 września 2026)

**Data:** 22 września 2026 · **Decyzja właściciela** (22.09, doprecyzowana
o 23:30) · Uzupełnia D-240 w części „lokalne sygnały”, D-052 bez zmian ·
Status: **obowiązuje**

Decyzja w brzmieniu właściciela: OpenAI ocenia całą treść publiczną (obrazy
pomniejszone). Treść niepubliczna nie wychodzi poza serwer, ale nadal sprawdzają
ją lokalne wzorce spamu. #1298 (D-240) miał wejść bez zmian, bo zamyka
incydent. Lokalną analizę przywraca osobny PR.

### Co było zepsute

D-240 pytało o granicę lokalną (`GranicaWysylki::pozaAutorem()`) tę samą
Policy gościa co o wysyłkę. Podmieniało tylko widoczność samego wpisu
z „dla obserwujących” na publiczną. Policy odrzucała jednak także:

- wpis i przepis **zbanowanego konta**, oraz komentarz pod nim;
- komentarz, którego autor jest zbanowany;
- komentarz pod **zapowiedzią przepisu „dla obserwujących”**. Bramką
  zapowiedzi jest przepis, a przepisu nikt nie podmieniał.

Wszystkie te treści traciły lokalne oznaczenie, choć przed D-240 je
dostawały. Sama wysyłka była w porządku: żadna z nich nie wychodziła do
OpenAI i dalej nie wychodzi.

### Co obowiązuje

- `pozaAutorem()` pyta Policy gościa o **kopię** treści, której nigdy się
  nie zapisuje (`kopiaDlaLokalnej()`). W kopii „dla obserwujących” zmienia
  się w „publiczny”, konto **zbanowane** udaje aktywne, a przepis zapowiedzi
  (rekurencyjnie) przechodzi te same dwie podmiany. O resztę warunków decyduje
  dalej Policy: status, usunięcie, ślad usunięcia komentarza, prywatność.
- Komentarz zbanowanego autora znów dostaje lokalną analizę.
- **Poza granicą lokalną zostają:** treść prywatna (D-052 pkt 3), ukryta,
  usunięta oraz konto **w karencji usunięcia** albo **wymazane**. Ban jest
  karą nałożoną przez nas. Karencję człowiek wybrał sam, bo obiecujemy mu
  „konto zniknie od razu”, więc nie kładziemy jego treści przed moderatorem.
- `publiczna()` i `zdjecieWpisu()`, czyli granica wysyłki, **nie zmieniają
  się ani o wiersz**. `OcenaModelem` pyta tylko je.

### Czego nie przywrócono i dlaczego

`ZgodaNaOceneAwatara` z lokalnej wersji równoległej (`2acfc098`). D-240 usunęło
drogę awatara na sztywno: nie ma zlecenia, zadanie jest puste. Klasa zgody,
która dziś zawsze odpowiada „nie”, dodałaby odczyt pliku i zlecenie tylko po
to, żeby je zaraz zatrzymać. Awatar nie ma też lokalnych wzorców, bo te
pracują na tekście, więc do przywrócenia nie ma tu nic. Mechanizm zgody
wymaga osobnej decyzji (D-240, „Awatar nie wychodzi”).

### Dowód

`tests/Feature/LokalnaAnalizaTresciNiepublicznejTest.php` — 7 przypadków,
tylko `Http::fake()` przy **ustawionym** kluczu. Każdy przypadek niepubliczny
sprawdza naraz, że lokalne oznaczenie istnieje i że `Http::assertNothingSent()`.
Na kodzie D-240 **oblewają 4**: wpis zbanowanego konta, komentarz pod
zapowiedzią przepisu „dla obserwujących”, komentarz zbanowanego autora
i komentarz pod wpisem zbanowanego konta. Trzy pozostałe to kontrole:
zapowiedź przepisu prywatnego i konto w karencji dalej bez oznaczenia
i bez wysyłki, a publiczny komentarz dalej wychodzi (zabezpieczenie przed
zepsutą atrapą). W `GranicaWysylkiDoOpenAiTest` oczekiwania dla zbanowanego
autora zmieniono z „0 oznaczeń” na „1 oznaczenie”. `Http::assertNothingSent()`
zostało w tych przypadkach bez zmian.

### Wycofanie

Odwrócić commit. Nic się nie zapisuje w bazie ani nie wychodzi poza serwer.
Po odwróceniu wracają tylko luki w lokalnych oznaczeniach opisane wyżej.
