## D-179 · Powitanie na stronie głównej nie zależy od godziny serwera

**Data:** 12 września 2026 · PR #450 · issue #38 · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Teza z tytułu — powitanie
> nie zależy od godziny serwera — obowiązuje i ma pokrycie: w kodzie nie ma
> żadnej gałęzi po godzinie. Odwrócone zostało BRZMIENIE, przy **D-207**, i
> nie odnotowano tego tutaj. Zatwierdzone w tym wpisie „**Witaj, {imię}. Co
> dziś gotujesz?**" nie jest już tekstem na ekranie:
> `app/Http/Controllers/FeedController.php:200` zwraca „Dzień dobry, {imię}",
> a pytanie o gotowanie zjechało do kafla publikacji.
> `tests/Feature/PytanieDniaTest.php:24-26` egzekwuje dziś brzmienie PRZECIWNE
> do zapisanego niżej. Uzasadnienie zmiany stoi w
> `docs/brand/COPY_STYLE.md:441-448` („Zastępuje to poprzednie »Witaj, {imię}.
> Co dziś gotujesz?« i rozdziela powitanie od publikacji"). Nieaktualne są też
> liczby w sekcji „Zmierzone", bo liczono je na „Witaj, {imię}.".

### Stan zastany był inny, niż mówiło issue

**Pytanie dnia na `/home` już było.** `git grep 'Co dziś gotujesz' -- resources/`
wracał pusto tylko dlatego, że napis składa się w PHP (`FeedController::greeting()`),
a widok renderuje gotowy tekst. Nie brakowało funkcji — brakowało **prawdy w tekście**
i jakiegokolwiek testu.

### Co było nieprawdziwe

Cztery warianty po godzinie, a w nich dwa błędy naraz: **„Dobry wieczór" witało od
15:00**, a godzinę brał `now()`, czyli **UTC** (issue #87). Latem o **11:50 czasu
polskiego serwis liczył 9:50**, a po 23:00 witał „Dzień dobry".

### Decyzja

Jedno zdanie, które nie kłamie o żadnej godzinie: **„Witaj, {imię}. Co dziś
gotujesz?"**.

Naprawa progów dałaby cztery gałęzie do utrzymania i dalej mówiłaby o porze dnia
**czytelnika**, której nie znamy: o strefę czasową nie pytamy tak samo, jak nie pytamy
o płeć, a kuKINGi mieszkają też poza Polską.

### Gdy imienia nie ma, zostaje samo pytanie

`User::displayName()` podstawia „Użytkownik Kuking" — dobre wszędzie, gdzie trzeba
kogoś **nazwać**, złe w powitaniu, bo udaje zwrot po imieniu, którego nie mamy.
Powitanie czyta `profile?->display_name` wprost.

### Zmierzone

Pole dodawania na `/home`: 390 px `top` 189,5 → 189,5 px, 1512 px 154,5 → 154,5 px.
„Witaj, {imię}." jest o sześć znaków krótsze, więc przy żadnym imieniu nie wypada
gorzej: dla „Halina z Podlasia" nagłówek zszedł ze **105 px na 70 px**, a pole
dodawania podniosło się z **225 px na 190 px**.

### Dwa sabotaże znalazły dziury w samym teście

Zwrot z pustym imieniem („Dzień dobry, . Co dziś gotujesz?") **przechodził**, bo
asercja szła po fragmencie zamiast po całym nagłówku. Podmiana źródła imienia na
`displayName()` **przechodziła** przy nazwie z samych spacji — `"   "` jest w PHP
prawdziwe i `?:` jej nie podmienia.

📄 `app/Http/Controllers/FeedController.php` · `PytanieDniaTest` ·
`docs/brand/COPY_STYLE.md` · issue #87
