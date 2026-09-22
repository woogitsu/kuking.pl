# Odbiór pełnego portu Alfa 0.9

13 września 2026.

## Wydanie

- PR: [#488](https://github.com/woogitsu/kuking.pl/pull/488).
- Sprawdzona gałąź: `6e48d5176b879be869a6ded0b38e3873d3a334b3`.
- Scalony kod: `66980acc83ea8298b76771682e4bda96264a6484`.
- [CI PR](https://github.com/woogitsu/kuking.pl/actions/runs/34734757204).
- [CI main](https://github.com/woogitsu/kuking.pl/actions/runs/34735135272).

To jest odbiór układu aplikacji, nie wcześniejszej zmiany palety Alfa 0.8.
Zakres opisuje [PORT_PROJEKTU.md](PORT_PROJEKTU.md), standard marki
[KONSTYTUCJA_MARKI.md](../brand/KONSTYTUCJA_MARKI.md), decyzję D-206
[dziennik](../DECISIONS.md).

## Wyniki sprawdzonego kodu

| Kontrola | Wynik CI PR |
|---|---|
| PostgreSQL 18 | 3631 testów, 73184 asercje — zaliczone |
| Pint, Larastan, Vite, Docker, wyścigi i audyt zależności | Wszystkie zadania zaliczone |
| axe-core | 44/44 ekranów, 4 warianty; 0 naruszeń |
| Układ | 49/49 ekranów, 320/360/414/768/900/1280 px, tekst 100%/140% i czcionka przeglądarki 200%; 0 przepełnień |
| Geometria ramy | 0 rozjazdów belki, stopki i szerokości stron |
| Tablica dnia, szyna gościa, liczby profilu | 0 niezgodności położenia |
| Fokus | 0 całkowitych zasłonięć; 6 ostrzeżeń częściowego zasłonięcia opisanych niżej |
| Dolna nawigacja | 0 przypadków przekroczenia 33% wysokości okna |
| Kafel publikacji | 15 wariantów; 0 zbyt małych celów, tekstów poniżej 18 px, przepełnień, zbyt wysokich i zasłoniętych kafli |
| Dodatkowy pomiar portu | 71 wariantów konta/gościa, motywów i rozmiarów; zaliczone |
| Lighthouse | 8/8 ekranów; wydajność 92–99; SEO 100 poza celowo nieindeksowanym logowaniem |

Lighthouse jest pomiarem na danych demonstracyjnych i runnerze CI,
nie danymi Core Web Vitals rzeczywistych użytkowników. Celowy noindex
logowania nie jest błędem SEO.

## Kontrole ujemne

Pięć kontroli PHP mutuje rzeczywisty kod: UUID, własność zeszytu,
komunikat po powrocie, wielkość podpisu i licznik w nowym menu konta.
Każda wymaga niezaliczonej asercji, przywrócenia przez `cp` z kopii
poza repo, zgodności MD5 oraz dodatniego testu po odtworzeniu.

Pomiar portu zmienia źródło `resources/css/marka-rama.css`, buduje Vite
i wykrywa zwężenie głównej kolumny do 80 px. MD5 przed i po przywróceniu:
`3bf7708c1c003a82b6afbbb6b24859ef`; mutacja:
`8492119cf8eedc7d84c0bbedfe135e20`.

Osobne próby geometrii wykryły błędną szerokość i przesunięcie nagłówka,
brak jego paddingu oraz zwężenie stopki. Tymczasowy styl korzysta z nonce
dokumentu testowego; CSP aplikacji pozostaje włączone. Tolerancja geometrii
wynosi nadal 1 px.

## Odbiór wizualny

Artefakt `port-projektu` w CI zawiera zrzuty wszystkich 44 ekranów axe
przy 320 i 1280 px oraz dodatkowe zrzuty Start, profilu i wyszukiwania.
Rzeczywiście obejrzane podczas odbioru: Start 390/1440 px, własny profil
i wyszukiwanie 390 px, strona powitalna, logowanie, przepis, kreator
przepisu i czytelność 320 px. Nie utożsamiamy zapisania zrzutu z jego
obejrzeniem.

Zrzuty pochodzą z działającego Laravel na odrębnej bazie demonstracyjnej.
Część zdjęć demonstracyjnych jest w stanie przygotowania; nie podmieniano
reguł gotowości mediów ani zdjęć produkcyjnych, by uzyskać ładniejszy obraz.
Testy otwierają również menu `details`, dlatego część zrzutów pokazuje
rozwinięte menu konta.

Niezależny przegląd kodu potwierdził zachowanie tras, formularzy,
wylogowania POST/CSRF, podziału ról i pięciu pozycji nawigacji.
Wykryty brak licznika moderacji poprawiono przed scaleniem.

## Ograniczenia

[Issue #485](https://github.com/woogitsu/kuking.pl/issues/485) pozostaje
otwarte: długi odnośnik „Małgorzata Konstantynopolitańczykowianka” jest
częściowo zasłonięty (25%) w sześciu pomiarach przy 320 px. Na tablicy
i w szukaniu, tekst aplikacji 140% daje ostrzeżenie pod górnym i dolnym
paskiem; czcionka przeglądarki 200% — pod dolnym paskiem. Wcześniej
pozostawały dwa takie ostrzeżenia przy czcionce 200%. To ograniczenie
produktowe, nie naruszenie minimum WCAG 2.4.11; nie usunięto pomiaru,
nazw ani nie podniesiono progów.

CI nie zastępuje badania użytkowników 50+. Nie przeprowadzono nowego
badania z udziałem tej grupy. Publiczny test produkcji nie loguje się
na konto użytkownika; widok zalogowanego sprawdzono w CI.

## Produkcja

**Wdrożenie potwierdzone 13 września 2026, 03:29 UTC.**
[Przebieg wdrożenia](https://github.com/woogitsu/kuking.pl/actions/runs/34735587736),
[job testu dymnego](https://github.com/woogitsu/kuking.pl/actions/runs/34735587736/job/103666318146).

Zdarzenie Railway: `ideal-exploration / production`, stan `success`,
commit `66980acc83ea8298b76771682e4bda96264a6484`.
Sam krok **Test dymny** wykonał się i zakończył jako success — nie skipped.

Pod https://kuking.pl potwierdzono /health, /, /login i /robots.txt (200),
obsługę nieistniejącej strony (404), przekierowanie HTTP → HTTPS (301)
i brak szczegółów debugowania. Serwowany HTML ma
`data-marka="kuking-2026"`; wskazany w nim rzeczywisty arkusz Vite zawiera
`.marka-topbar` oraz `.marka-rama`.

CI main również zakończyło się w całości sukcesem: 3631 testów i 73184
asercje; te same wyniki axe, układu i sześć ostrzeżeń fokusu. Lighthouse
na main: 8/8 ekranów, wydajność 92–95, bez niezaliczonych ekranów.
Test produkcji potwierdza dostarczenie nowej wersji, nie wygląd prywatnego
konta po zalogowaniu — ten zakres odebrano oddzielnie w CI.

## Wycofanie

Port nie dodaje migracji bazy. Wycofanie wyglądu polega na odwróceniu
commita `66980acc83ea8298b76771682e4bda96264a6484`, ponownym zbudowaniu
zasobów i przejściu dotychczasowego CI → Railway. Nie należy przy tym
odwracać wcześniejszych migracji ani zmieniać danych użytkowników.
