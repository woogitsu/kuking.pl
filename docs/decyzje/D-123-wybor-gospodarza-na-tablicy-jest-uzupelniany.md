## D-123 · Wybór gospodarza na tablicy jest UZUPEŁNIANY automatem do sufitu, a nie zamyka tablicy na resztę serwisu

**Data:** 11 września 2026 · Zgłosił i rozstrzygnął właściciel · Status: **obowiązuje**

### Zgłoszenie

„w »co się dziś gotuje« można zrobić dwie kolumny i dać więcej tych ludzi (chyba
że za mało wpisów i temu tak pusto)". Właściciel zgadywał przyczynę i zgadywał
źle — i to jest najciekawsze w tym wpisie.

### Co było nie tak

`DailyBoard::forViewer()` sprawdzał, czy na dziś istnieje choć jeden wybór
redakcyjny, i jeśli tak — zwracał **wyłącznie** jego:

```php
if ($picks->isNotEmpty()) {
    return $this->fromCuratedPicks($picks, $viewer);   // i koniec
}
```

Właściciel zaznaczył w `/kuking-na-dzis` cztery pozycje i zobaczył na stronie
powitalnej dwie osoby i dwa dania tam, gdzie mieści się dwa razy tyle. Pustka nie
brała się ani z układu, ani z braku treści.

### Decyzja

Wybór gospodarza ma **wyróżniać** kilka rzeczy, a nie zamykać tablicę. Najpierw
idzie to, co wskazał człowiek, potem dobór automatu do sufitu.

Trzy rzeczy są częścią tej decyzji, nie szczegółem implementacji:

**Kolejność.** Wybór człowieka stoi pierwszy. Inaczej wyróżnienie przestaje być
wyróżnieniem — w teście osoba niewybrana publikuje później i bez tej reguły
stałaby na pierwszym miejscu.

**Dziura po pozycji schowanej też się zapełnia.** Brakujące miejsca liczymy z tego,
co NAPRAWDĘ zostało po odsianiu pozycji niedostępnych dla tego widza (autor
zablokowany, wpis schowany przez moderację już po wyborze), a nie z liczby
zaznaczeń w panelu. Inaczej widz z jedną blokadą dostawałby tablicę krótszą od
cudzej, bez żadnego powodu.

**Dobór pomija AUTORÓW wybranych dań, nie same dania.** Reguła „najwyżej jedno
danie od osoby" obowiązuje w całej tablicy, a nie osobno w części redakcyjnej
i osobno w dobranej. Bez tego automat dołożyłby drugi wpis dokładnie tej osoby,
którą gospodarz przed chwilą wyróżnił — czyli zrobiłby to, przed czym broni
`docs/product/COLD_START.md`.

### Co to NIE zmienia

Zakaz rankingów (AGENTS.md §12) stoi bez zmian. Automat dobiera po tym, KIEDY ktoś
ostatnio coś pokazał; żadna miara popularności nie wchodzi ani w wybór, ani
w kolejność.

### Szczegół, który łatwo zrobić źle

Wykluczenia idą **parametrem do zapytania**, a nie odsiewaniem po pobraniu. Limit
jest narzucany w SQL, więc odsianie „po fakcie" zwracałoby mniej pozycji niż
proszono — i błąd wyglądałby dokładnie jak ten, który naprawiamy.
