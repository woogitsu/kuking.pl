# Publiczne statystyki tagów — #369

Stan: **scalone i wdrożone**, odbiór produkcji niedomknięty.
PR #665 scalony 18.09.2026 jako `e22b79d`. Produkcja stoi na `55877e2` (Alfa 0.65),
CI main 35331870558 oraz Deploy 35333641106: success. Nagłówek poniżej opisujący
pracę na `27df931` / `feat/369-statystyki-publiczne` był snapshotem sprzed scalenia.
Blokuje wyłącznie punkt „Granica dowodu produkcji — 18 września 2026”.

## Zachowanie

Strona tagu i spis korzystają ze zbiorczego agregatu `TagPublicStats`. Liczy unikalne gotowe media publicznych opublikowanych wpisów i ich aktywnych autorów. Uwzględnia dostępność powiązanego przepisu dla gościa, usunięcia i status tagu. Wpis bez gotowego zdjęcia nie zwiększa liczb. Zalogowanie nie rozszerza licznika o prywatne treści.

Próg prezentacji: pięć zdjęć i trzy osoby, oba warunki łącznie, w konfiguracji `kuking.tag_public_stats`. Kilka zdjęć jednego autora nie udaje kilku kuchni. Poniżej progu strona tagu zaprasza do dodania wpisu; spis nie powiela zaproszenia wewnątrz odnośników. Istniejąca liczba wpisów pozostaje bez zmian. Nie dodano rankingu, cache ani publicznego API. Agregat obejmuje razem promowane tagi i stronę alfabetu. Puste wejście nie pyta bazy.

## Testy lokalne

Pierwsza próba integracji ujawniła niezgodny runtime i autoload starej kopii. Po przygotowaniu własnych zależności i weryfikacji źródeł: **13 testów / 147 asercji PASS**. Pint pięciu plików PHP oraz PHPStan agregatu i kontrolera przeszły. Nie jest to pełny hook ani CI.

Sześć fizycznych kontroli ujemnych pojedynczo usuwało filtr publiczności, aktywnego autora, widocznego przepisu, gotowego medium lub unikalność liczenia zdjęć/autorów. Każda spowodowała porażkę testu. Źródło przywracano po każdej mutacji z kontrolą MD5 i mtime; końcowy przebieg ponownie 13/147 PASS. Dowody: `evidence/tags369/negative-matrix.json`.

Szerszy przebieg powiązanych regresji: **55 testów / 315 asercji PASS**.
Obejmuje nowe statystyki, widoczność i kolumny przepisów w strumieniu tagu,
obserwowanie tagów, listę promowanych tagów i spójne nazewnictwo.

## Pomiar dużego zbioru

Izolowana transakcyjna fixture: 10 000 wpisów, 30 000 zdjęć, 1000 autorów, 30 tagów. Pozostały też nieprzypięte rekordy wzorcowe fabryk. WSL, Intel Core Ultra 7 270K Plus. Transakcję wycofano po pomiarze.

Przed ANALYZE świeżo wstawionych danych agregat 30 tagów zajmował około 1,30 s, a GET około 1,62 s. Po ANALYZE tych samych tabel, bez zmiany zapytania:

| Zakres | 2 tagi | 30 tagów | Zapytania |
|---|---:|---:|---:|
| Agregat | 5,1–7,6 ms | 29,8–31,4 ms | 1 |
| GET /tagi | 12,9–24,6 ms | 55,7–57,3 ms | 4 |

Po cztery próbki; GET mierzony przez kernel aplikacji, bez sieci i ruchu równoległego. EXPLAIN ANALYZE: GroupAggregate około 40 ms, sortowanie 30 000 wierszy w pamięci, około 2,6 MB. Nie jest to gwarancja czasu na produkcji. Oba surowe pomiary i plan zapisano w `evidence/tags369`. Wynik nie uzasadnia teraz cache ani zmiany schematu. Aktualne statystyki bazy po dużym imporcie mają istotny wpływ na plan zapytania.

## Odbiór przeglądarkowy

Lokalny Laravel na porcie 8070, osobna baza `kuking_369_browser`, bez działań
na produkcji. Zapisano 48 układów dla szerokości 320, 360, 390, 414, 768 i 1440,
obu motywów oraz tekstu 140%. Brak przewijania poziomego. Osobno sześć
przypadków rzeczywistego zoomu 200% potwierdzonego przez API przeglądarki.
Cztery scenariusze klawiatury potwierdzają widoczny fokus, Enter prowadzący
do tagu i kliknięcie następnej strony spisu.

Pierwsza próba paginacji zatrzymała się przy 63 tagach i limicie strony 100:
brak następnej strony był prawidłowy. Po zwiększeniu lokalnej fixture do 113
tagów pełny przebieg zakończył się powodzeniem. Zachowano wynik końcowy,
nie przedstawiamy pierwszej próby jako porażki aplikacji.

Fizyczny negatyw Blade wyłączył pierwszy warunek wyświetlania licznika.
Przeglądarka wykryła `PUBLIC_STATS_MISSING`; po odtworzeniu identycznych
MD5/mtime kontrola dodatnia przeszła. JSON-y i dwa przykładowe zrzuty są
w `evidence/tags369`. Nie wykonano testu na fizycznym telefonie.

## Pozostałe warunki odbioru

Ogląd wykrył problem przy 320 px i dużym tekście: dotychczasowy promień
`pill` wysokiego odnośnika przecinał nazwę oraz dolny wiersz statystyki.
Samo sprawdzenie poziomego overflow tego nie wykrywało. Dla odnośników
spisu ustawiono istniejący token `--radius-md`; trwa ponowny odbiór tej
poprawki i fizyczna kontrola ujemna CSS. Powyższe pierwsze zrzuty dokumentują
stan przed poprawką promienia, nie końcowy wygląd.

Końcowy odbiór poprawki obrysu zakończony: ponownie 48 układów i zoom 200%
w obu motywach bez problemu. Fizyczny powrót do `radius-pill` wykrył cztery
znaki poza obrysem (`TEXT_OUTSIDE_ROUNDED_BORDER`); przywrócenie MD5/mtime,
ponowny build i kontrola dodatnia przeszły. Dowody `outline-final.json`,
`outline-negative.json` i końcowe zrzuty `outline-final-*.png`.

Niezależny przegląd implementacji nie znalazł blokera funkcjonalnego ani
prywatności. Pełny raport agenta: `evidence/tags369/ODBIOR.md`.
Pozostają zwykły hook, wymagane CI i odbiór produkcji.
Pełny port marki nadal **CZĘŚCIOWO**.

Codex niezależnie obejrzał `dark-320-eligible.png` i `dark-1440-index.png`
z lokalnego odbioru: licznik i długa nazwa zawijają się, a statystyka w spisie
ma osobny wiersz. Ten ogląd dwóch obrazów nie zastępuje pełnej macierzy agenta.


## Granica dowodu produkcji — 18 września 2026

Odbiór wyłącznie odczytowy: GET-y HTTP bez sesji, bez żadnego zapisu, bez zakładania
konta i bez danych demonstracyjnych na produkcji. To **odczyt HTML**, nie interakcja
w przeglądarce; miejsca oznaczone niżej jako obejrzane pochodzą z runtime lokalnego.

Publicznych tagów na produkcji **nie ma żadnego**, więc niezerowych statystyk
i zachowania progu nie było na czym pokazać. Stan sprawdzony czterema niezależnymi
drogami, nie samą listą:

| Powierzchnia | Kod | Co zwróciła |
|---|---:|---|
| `https://kuking.pl/tagi` | 200 | `<p class="empty-state-title">Tagi jeszcze się nie pojawiły</p>` |
| `https://kuking.pl/szukaj?sekcja=przepisy` | 200 | `<h2 id="polecane-tagi-title">Polecane tagi</h2>` + `<p>Nie ma jeszcze polecanych tagów.</p>` |
| strona pojedynczego tagu (`/tag/{tag}`) dla pięciu slugów: obiad, zupy, deser, cukinia, bigos | 404 | brak jakiejkolwiek strony tagu |
| `/`, `/odkryj`, trzy wpisy z `/odkryj`, `/@izazpodlasia`, `/@on_the_plate`, `/@ulalala` | 200 | zero odnośników `href` prowadzących na stronę pojedynczego tagu |

Stopka każdej z tych stron podaje `Alfa 0.65` i `55877e2`, czyli ten sam commit,
na którym stoi wdrożenie. Pusty stan `/tagi` jest poprawny i zgodny z zamierzeniem,
ale **pusty stan nie jest dowodem działania licznika**.

Pozostaje więc jeden warunek: zobaczyć na produkcji tag, który ma co najmniej
pięć publicznych gotowych zdjęć od co najmniej trzech osób, i potwierdzić na nim
zarówno zdanie ze statystyką, jak i to, że poniżej progu pojawia się zaproszenie.
Warunek ten spełni się dopiero po redakcyjnym dodaniu tagów (#18) albo po
pierwszych wpisach z własnym tagiem. Nie tworzymy w tym celu treści na produkcji.

Lokalnie, na izolowanym runtime opisanym w raporcie #370 (`kuking_d_a_tests`,
127.0.0.1:55439, serwer 8074), strona tagu powyżej progu renderuje
`Publicznie: 5 zdjęć od 5 osób.` — zrzut `evidence/tags370/pointer/A-light-100-1440.png`
obejrzany. To potwierdzenie lokalne, nie produkcyjne.

Konkretnych adresów z próbami 404 nie zapisujemy tu jako literałów ścieżek:
`DokumentyMdNieMajaMartwychOdnosnikowTest` pilnuje, żeby dokumenty nie cytowały
martwych tras, a te akurat są martwe celowo. Pełna lista prób wraz z kodami
odpowiedzi jest w `evidence/tags369/production-read-20260918.json`.
