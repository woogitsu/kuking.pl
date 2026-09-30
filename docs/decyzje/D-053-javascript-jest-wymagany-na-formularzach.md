## D-053 · JavaScript jest wymagany na formularzach chronionych captchą, a nigdzie nie wolno zostawić martwego przycisku

**Data:** 9 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

**Zmienia zasadę z `AGENTS.md` §5**, która brzmiała: „Rejestracja, logowanie,
publikacja wpisu, przepis, komentarz i »Ugotowałem« **muszą działać bez
JavaScriptu**".

### Co powiedział właściciel i dlaczego ma rację

> „Nie wiem czemu masz tę blokadę na JS, przecież ci starsi ludzie mają
> nowoczesne telefony, to nie będzie wchodziła babcia 80-letnia z Nokii 3310,
> tylko seniorka, która ma Samsunga A23 czy coś, Xiaomi itp., więc ma
> JavaScript, albo na kompie to robi."

I dalej, już jako rozstrzygnięcie:

> „W tych newralgicznych miejscach niech będzie obowiązkowo, jak ta rejestracja
> itp., tam gdzie można się obejść to spoko, ale lepiej żeby był z wygody."

Przesłanka faktyczna jest prawdziwa. Grupa docelowa Kukinga to nie jest ktoś
bez JavaScriptu — to ktoś z niedrogim, ale współczesnym telefonem albo
z komputera. Stara reguła zakazywała przy okazji rzeczy, które nikomu nie
szkodzą: podglądu zdjęcia przed wysłaniem, licznika znaków, kadrowania awatara.

### Co z niej zostaje, i to nie jest kompromis dla świętego spokoju

Uzasadnienie starej reguły NIGDY nie brzmiało „telefon nie ma JavaScriptu".
Brzmiało: **„przy słabym zasięgu skrypt się nie dociąga"**. To zdanie jest
prawdziwe niezależnie od tego, jaki ktoś ma telefon. Widget Turnstile nie
dociągnie się po wsi na jednej kresce zasięgu, w piwnicy, w pociągu i przy
blokadzie reklam — na sprzęcie, który JavaScript ma i ma go włączonego.

Różnica jest taka, że dawniej odpowiedzią było „zrób to samo bez skryptu",
a teraz jest: **powiedz człowiekowi po polsku, co się stało i co ma zrobić**.
Zakazane zostaje jedno, za to bezwarunkowo: **przycisk, który po kliknięciu
milczy**. `<noscript>` z konkretną instrukcją, osobny komunikat dla „widget
się nie dociągnął" i dla „token podrobiony", oraz adres kontaktowy dla kogoś,
kto naprawdę utknął.

### Skutki

| Miejsce | Przed | Po |
|---|---|---|
| `/register`, `/login`, `/nie-pamietam-hasla`, `/cofnij-usuniecie-konta`, `/napisz-do-nas`, `/zglos-nielegalna-tresc` | brak tokenu Turnstile przepuszczał wysłanie | brak tokenu **odrzuca** wysłanie (D-050 przepisane) |
| ulepszenia oparte na skrypcie w pozostałych miejscach | wymagały wersji zapasowej bez JS | wolno bez wersji zapasowej, z `<noscript>` przy tym, co bez skryptu nie działa |
| Cloudflare nie odpowiada, zły sekret | przepuszczamy | **bez zmian — przepuszczamy** |

Ostatni wiersz jest częścią decyzji, nie wyjątkiem od niej: wymóg dotyczy
skryptu u człowieka, a nie sprawności cudzej usługi ani naszej konfiguracji.

### Czego pilnujemy, żeby ta decyzja nie kosztowała nas ludzi

Odrzucenie z powodu braku tokenu **zostawia ślad w dzienniku** (bez adresu IP
i bez danych osobowych — sam fakt i nazwa formularza). Po tygodniu ma dać się
odpowiedzieć na pytanie „ilu osobom zamknęliśmy drzwi". Jeśli okaże się, że to
zauważalny odsetek rejestracji, wracamy do tej decyzji z danymi, a nie
z przeczuciem.

### Uczciwie o progu, który postawiła D-007

D-007 kończyła się zdaniem: **„Zmiana wymaga danych pokazujących, że nasi
użytkownicy nie mają tego problemu"**. Takich danych nie mamy — mamy rozumowanie
właściciela o sprzęcie grupy docelowej, które jest trafne, ale rozumowanie to
nie pomiar. Zapisuję to wprost, zamiast udawać, że próg został spełniony.

Odpowiedzią na ten brak jest ostatni akapit wyżej: **zaczynamy te dane zbierać**
od pierwszego dnia obowiązywania nowej reguły. Za tydzień będzie wiadomo, ilu
osobom brak tokenu zamknął drzwi — i wtedy albo D-053 zostaje potwierdzona
pomiarem, albo wracamy do niej z liczbami.

### Droga wycofania

Jedna wartość w `config/kuking.php` (sekcja `turnstile`) wyłącza wymóg
w wybranym miejscu albo wszędzie. Kod przepuszczający wysłanie bez tokenu
nie znika — zmienia się warunek, przy którym się uruchamia.
