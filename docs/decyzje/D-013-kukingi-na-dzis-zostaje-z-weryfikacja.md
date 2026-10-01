## D-013 · „kuKINGi na dziś" zostaje, z weryfikacją w testach

**Data:** 5 września 2026 · **Decyzja właściciela** · Status: **obowiązuje warunkowo**

Research językowy zgłosił realne zastrzeżenie: dla **rzeczownika osobowego**
forma `kuKINGi` jest w polszczyźnie **deprecjatywna** — ta sama, która daje
„profesory" i „chłopy", a Poradnia PWN pisze, że służy „wyrażaniu oceny
negatywnej".

Nazwa zostaje, bo kontrargument też jest mocny:

- `kuKING` jest równocześnie nazwą **rzeczy**, nie tylko osoby — a dla
  rzeczowników nieosobowych `-ing → -ingi` jest formą całkowicie zwyczajną
  („mityng → mityngi", „leasing → leasingi");
- tablica pokazuje **ludzi i dania obok siebie**, więc odczyt „rzeczy warte
  zobaczenia" jest naturalny;
- `brand/COPY_STYLE.md` §2 od początku definiuje `kuKINGi` wyłącznie
  w znaczeniu rzeczy.

**Warunek:** rozstrzygamy to na realnych ludziach w testach z osobami 50+
(issue #15), jednym pytaniem: *„o czym jest ta sekcja?"*. Jeśli ktokolwiek
odczyta to jako lekceważące określenie ludzi — zmieniamy.

Przygotowane alternatywy, gdyby test wypadł źle: **„Dziś u kuKINGów"**
(dopełniacz mnogi nie jest formą deprecjatywną, gra słowem zostaje) albo
**„Co się dziś gotuje"** (nie odmienia słowa wcale).

Koszt zmiany: jedna linijka w `components/kuking-board.blade.php`.

📄 `brand/COPY_STYLE.md` §5 · `decyzje/KUKING_JEZYK.md` · issue #15, #37
