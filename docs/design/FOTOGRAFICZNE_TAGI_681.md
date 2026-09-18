# Fotograficzny spis tagów — #681

Stan: roboczy, częściowy odbiór lokalny, bez wdrożenia.

Na prośbę właściciela spis A–Z wykorzystuje szerszą ramę oraz duże karty
ze zdjęciem publicznego wpisu. Fotografia pochodzi z istniejącego TagCollage,
który zachowuje gotowy wariant medium, publiczność, dostępność przepisu
i blokady. Nazwa, licznik oraz autor fotografii mają osobny ciemny podkład.
Bez zdjęcia karta pokazuje znak marki. Kolejność i paginacja pozostają.

## Dowody lokalne, 18 września 2026

- Izolowany runtime `/home/mateusz/kuking-681-tests`, baza `kuking_681_tests`
  na `127.0.0.1:55439`; bez danych produkcji.
- `TagPlacePagesTest`, `TagCollageTest`, `TagPublicStatsPagesTest`:
  **20 testów, 385 asercji, PASS**.
- Build Vite PASS; 72 istniejące pary kontrastu i 7 testów JS PASS.
  To nie jest pomiar kontrastu nowej fotografii i jej podpisów.
- Fizyczne usunięcie znacznika zdjęcia z prawdziwego komponentu Blade:
  test kart oblał na liczbie obrazów (0 zamiast 2). Kopia poza repo,
  przywrócone MD5 `73ce103da5f32a576527bee93869e098` i mtime
  `1789739595242536700`. Po przywróceniu **1 test / 11 asercji PASS**.
- Pierwsza próba przywrócenia zachowała bajty i mtime, ale użyła skompilowanego
  widoku mutacji. Po jawnym `view:clear` przed oboma przebiegami ponowiona
  kontrola wykazała właściwą czerwień i zieleń. Nie zmieniano asercji.

## Pozostaje

Ogląd lokalny Chromium: obejrzano pełną stronę 1440 px w jasnym motywie
i 390 px / 140% w ciemnym. Pięć tagów, cztery zdjęcia oraz jedna karta
bez zdjęcia. Fotografia fixture jest wspólna dla kilku tagów; nie przedstawia
produkcyjnych treści ani poprawnego dopasowania potraw do nazw.
36 konfiguracji (320/360/390/414/768/1440 × dwa motywy × 70/100/140%)
bez poziomego overflow strony i kart. To pomiar układu, nie ogląd wszystkich
36 konfiguracji. Kliknięcie myszą i Enter na karcie Sernik otwierają jego
stronę; fokus ma obrys solid 3 px. Pływający Wygląd pozostaje widoczny,
co nie dowodzi braku zasłaniania każdej kontrolki.

Lokalna baza oglądu: `kuking_681_browser`, port 55439, serwer 8681.
Pierwszy fixture odrzucono na nazwie dłuższej niż schematowe 30 znaków.
Poprawiono dane testowe i uzupełniono je bez kasowania istniejącego wpisu;
nie zmieniano ograniczenia aplikacji.

Ogląd telefonu i komputera, oba motywy, skale 70/100/140%, prawdziwy zoom
200%, długie nazwy i podpisy, pusty/jeden/wiele tagów, mysz/dotyk/fokus,
pomiar kontrastu i braku zasłaniania; dalsze kontrole ujemne i niezależne
review. Potem wersja, changelog, zwykły hook, CI i odbiór produkcji.

Zgłoszone przez właściciela „0 wpisów” pozostaje osobnym nieustalonym
przypadkiem. Odczyt publicznej strony oraz kodu nie dowodzi widoczności
ani powiązania konkretnego wpisu użytkownika. Nie zmieniano danych ani
warunków prywatności, żeby podnieść licznik.

## Dodatkowy pomiar rzeczywistego zoomu

Ponowiono cztery przebiegi Chromium: natywne okna 640 i 1440 px,
oba motywy, zoom przeglądarki 200% i skala tekstu aplikacji 140%.
`chrome.tabs.getZoom` potwierdza 2, DPR wynosi 2, szerokość CSS odpowiednio
320 i 720 px. Wszystkie cztery przebiegi bez poziomego overflow; środek
karty trafia w jej link, a akcja „Zobacz wpisy” nie przecina przycisku Wygląd
po uzyskaniu fokusu. Dane: `evidence/tagi681/zoom200.json`.

Pomiar czeka na dekodowanie zdjęcia i reakcję interfejsu na fokus.
Każdy wariant otrzymuje fokus na nowo: nie jest to dowód zachowania przy
zmianie szerokości z nieprzerwanym fokusem. Ten przypadek pozostaje do
sprawdzenia. To emulacja okna desktopowego, nie fizyczny telefon.

Niezależny przegląd kodu wskazał podpis autora w rozmiarze pomocniczym.
Zmieniono go na token tekstu podstawowego; poprawka znajduje się również
w przebudowanym lokalnym runtime. Review nie zastępuje oglądu ani testów.

Stała regresja `scripts/katalog-tagow.mjs` została podłączona do istniejącego
pomiaru `tagi-marki.mjs` po przygotowaniu rzeczywistych danych tagów.
Lokalny przebieg: 36 konfiguracji PASS; mierzy szerokość ramy desktopowej,
minimalną powierzchnię kafla, zmieszczenie podpisów i poziome przepełnienie.
Nie zastępuje testów prywatności ani dekodowania zdjęć.

Kontrola ujemna prawdziwego `resources/css/marka-tagi.css`: wyłączenie
proporcji karty i minimalnej wysokości powoduje `K681_KAFEL`. Przywrócono
kopię spoza repo: MD5 `fa0ae3f196fc6fbf0edd56d420d9f995`, mtime ns
`1789740220230601192`; ponowny build i wszystkie 36 konfiguracji PASS.
Pełnego przebiegu CI z nowym wywołaniem jeszcze nie wykonano.

Pomiar kolorów odczytanych z Chromium: podpis 18 px, biały tekst i czarny
podkład 82% w obu motywach. Dla najjaśniejszego możliwego zdjęcia (białego)
kontrast wynosi 13,60:1. Obliczenie i kolory w `evidence/tagi681/contrast.json`.

Końcowy recenzent wskazał lukę integracji: fixture kompozycje-515 tworzy
same tagi/promocje, więc CI nie gwarantuje obecności fotograficznej karty.
Lokalne 36 konfiguracji obejmuje zdjęcia, ale CI wymaga uzupełnienia danych
oraz jawnej asercji zdjęcia i podpisu. Nie uznawać tej części odbioru za pełną.

Lukę fixture uzupełniono trybem `fotografia`: lokalny opublikowany wpis,
aktywna osoba z długim podpisem oraz gotowy plik WebP z rzeczywistymi
wymiarami i bajtami. Obraz jest jednolitym polem testowym, nie zdjęciem
potrawy ani dowodem dopasowania treści. Regresja wymaga fotograficznej karty,
podpisu i poprawnego dekodowania obrazu. Lokalnie ponownie 36 PASS.

Cykl fixture wykonany na nowej izolowanej bazie `kuking_port681_fixture`
na 55439: po utworzeniu 1 wpis / 1 medium / 1 użytkownik; po przywróceniu
0 wpisów / 0 mediów / 0 użytkowników / 0 tagów. Nie użyto bazy pełnych testów
ani bazy bieżącego oglądu. Pełne połączenie tej ścieżki z portem CI pozostaje
do uruchomienia.

Połączony przebieg fixture → HTTP → Chromium wykonano również na czystej
bazie `kuking_port681_fixture` i tymczasowym serwerze 8682: 36 PASS,
obejmujących nowo utworzoną fotograficzną kartę. Po przebiegu przywrócono
dane i zakończono serwer. To lokalny odbiór integracji, nie wynik GitHub CI.

Pełny przebieg PHP na źródłach przed końcową korektą pomocnika:
4154 testy, 81914 asercji, 9 porażek. Osiem pochodziło z pomocnika
SpisTematowTest wyszukującego wyłącznie `.chip`. Dostosowano odczyt do
nazwy i licznika nowej karty; oczekiwane wartości, prywatność, alfabet,
paginacja i pomiar N+1 pozostały. Celowany przebieg: 10 testów / 117 asercji
PASS, 5 zapytań zarówno przy 2, jak i 20 tagach.

Dziewiąta porażka: ProbaOdtworzeniaTest — w bazie próby 82 migracje,
w źródle 81. Skrypt wylicza sufiks z metadanych worktree, nie z jawnego
DB_DATABASE; runtime bez .git wpada w wspólną nazwę `glowny`. To ustalona
luka izolacji środowiska, ale współbieżna ingerencja jako przyczyna tej
konkretnej porażki wymaga jeszcze potwierdzenia. Nie oznaczono suity jako
zielonej. Przed kolejnym pełnym przebiegiem zapewnić prawdziwy osobny
worktree runtime i sprawdzić nazwy pomocniczych baz.

Po utworzeniu prawdziwego odrębnego worktree runtime
`/home/mateusz/kuking-681-check` pomocniczy sufiks wynosi
`kuking_681_check`, zamiast wspólnego `glowny`. Test odtworzenia:
1 test / 7 asercji PASS. Historyczna kolizja pozostaje hipotezą, natomiast
nowy przebieg potwierdza działanie testu przy rozdzielonych nazwach.

Kontrola ujemna poprawionego pomocnika SpisTematowTest: w prawdziwym
Blade licznik podniesiono o 1. Test prywatności licznika oblał na oczekiwanej
wartości „Temat widoczności (1 wpis)”. Przywrócono kopię poza repo,
MD5 `73ce103da5f32a576527bee93869e098`, mtime ns `1789739595242536700`;
po wyczyszczeniu widoków 1 test / 5 asercji PASS.

Obsługa: Tab przechodzi przez wszystkie pięć kart w kolejności DOM,
z obrysem solid co najmniej 2 px. Kliknięcie myszą przy 1440 px oraz
emulowany dotyk przy 390 px otwierają stronę Sernik. Nie jest to test
fizycznego telefonu. Wyniki: `evidence/tagi681/input.json`.

Axe dla samej siatki katalogu: 390/1440 px, oba motywy — brak wykrytych
naruszeń WCAG A/AA. Kontrast zgłoszony jako incomplete z powodu zdjęć;
nie zaliczono go na podstawie axe, służy mu osobny pomiar 13,60:1.
Początkowe wstrzyknięcie skryptu zablokowała CSP; pomiar uruchomiono przez
kontekst automatyzacji przeglądarki, bez zmiany CSP aplikacji.

Ponowne niezależne review nie wskazało nowych blokerów w sprzątaniu fixture
ani odczycie liczników. Zwróciło uwagę na wybór pierwszego zdjęcia zamiast
konkretnego fixture: integracja wymaga teraz karty „Pomiar515 1” i pełnego
podpisu „Aleksandra Katarzyna z kuchni”. Ponowny połączony przebieg na
izolowanej bazie: 36 PASS, przywrócenie i zakończenie serwera wykonane.

Ogląd pustego katalogu i pojedynczego tagu (1440 px) ujawnił rozciąganie
jednego polecanego tagu na całą nową ramę. Ograniczono siatkę polecanych
wyłącznie w katalogu do stałych kolumn auto-fill. Dodano pomiar stanu jednej
rekomendacji; po poprawce 36 konfiguracji i ten stan przechodzą. Nowy zrzut
obejrzano po dekodowaniu zdjęć. Wymaga to jeszcze kontroli ujemnej nowej reguły.

Pełne check.sh zakończyło się kodem 1 w testach; składnia, PHPStan,
skrypty powłoki, migracje i build przeszły. Celowany odczyt błędu wskazał
brak lokalnej zmiennej AWS_DEFAULT_REGION, wymaganej do konstrukcji klienta
S3, nie błąd połączenia z produkcją. Uzupełniono tylko środowisko testowe
(region auto, testowa nazwa bucketu); KonfiguracjaDyskowTest: 6/6 PASS.
Pełny wynik pozostaje niezaliczony do ponownego przebiegu.

Kontrola ujemna ostatniej reguły: w prawdziwym CSS wymuszono jedną kolumnę
polecanych. Pomiar odrzucił zmianę przez `K681_JEDNA_POLECANA`.
Przywrócono kopię spoza repo (MD5 `ceefda49a4d969d4fe41220195bbc401`,
mtime ns `1789741516102299727`), przebudowano assety; połączony przebieg
36 konfiguracji z pojedynczą rekomendacją ponownie PASS.

### Uzupełnienie bramki CI
Filtry trzech zadań przeglądarkowych uwzględniają teraz również zmianę samego scripts/katalog-tagow.mjs. Istniejąca regresja PortMarkiMaWlasnaBramkeCiTest: 2 testy, 79 asercji PASS. Fizyczne usunięcie wpisu z rzeczywistego workflow dało oczekiwaną porażkę: pominięto scripts/katalog-tagow.mjs. Kopia poza repo, przywrócone MD5 b41c579815acb5ae9c333a6deec3198f i mtime_ns 1789742883821854100; ponowny wynik dodatni 2/79. To pomiar lokalny, końcowy push i CI tego uzupełnienia pozostają do wykonania.

## Odbiór końcowy — 18 września 2026

Poniższy wynik zastępuje historyczne oczekiwanie na push, CI i wdrożenie
w powyższych wpisach. Nie zmienia zakresu wcześniejszych pomiarów.

- Zwykły push z obowiązkowym hookiem zakończył się sukcesem dla
  `a228675b40e3c80a1c0f2802f9a76d1e0b16df94`; zdalny SHA potwierdzono.
- [PR #688](https://github.com/woogitsu/kuking.pl/pull/688) scalono po
  [CI 35360132759](https://github.com/woogitsu/kuking.pl/actions/runs/35360132759):
  12/12 zadań zakończonych sukcesem na tym SHA.
- Commit main i wdrożenia: `0f30f07b4bb9c481d4a2e6fc0d9d18cec93c8af7`.
  [CI main 35363170161](https://github.com/woogitsu/kuking.pl/actions/runs/35363170161)
  zakończyło się sukcesem 12/12. Pełny PHP: 4154 testy, 81992 asercje.
  Log rodzin ekranów zawiera `K681_OK 36`, `ZOOM200_OK 88` i `PORT_OK []`.
  Liczba 88 dotyczy szerszego zestawu zoomu, nie samych kart tagów.
- GitHub deployment Railway `6527600446`: success, 16:15:50 UTC.
  Panel Railway potwierdził aktywne wdrożenie
  `79060bd2-ced3-48e6-b11c-c64558587556`.

### Rzeczywista produkcja

Chrome wyświetlił Alfę 0.67 / `0f30f07`. Obejrzano katalog przy szerokości
1717 px w jasnym motywie, 390 px w obu motywach i 1440 px w ciemnym.
Zachowano istniejącą preferencję tekstu 80%. Rama katalogu na komputerze
miała 1120 px, karta bez publicznej fotografii była kwadratowa; nie wystąpił
poziomy overflow. Kliknięcie karty prowadziło do
[strony tagu sernik](https://kuking.pl/tag/sernik), powrót działał.
Motyw i rozmiar okna po odbiorze przywrócono.

W chwili odbioru tag miał zero publicznych wpisów, więc poprawnie pokazywał
wariant ze znakiem garnka. Wpis widoczny właścicielowi na stronie tagu był
prywatny: jego zdjęcie nie mogło trafić do publicznego katalogu. Nie publikowano
danych demonstracyjnych. Fotograficzny wariant ma dowód lokalny i CI, lecz
nie ma odbioru zdjęcia na rzeczywistych publicznych danych produkcji.

Dowód odbioru:
[komentarz #681](https://github.com/woogitsu/kuking.pl/issues/681#issuecomment-5732860881).
To ogląd Chrome i emulacja szerokości, nie fizyczny telefon ani Safari.
Znane zasłanianie treści przez widżet „Wygląd” pozostaje w #684; ten raport
nie ogłasza pełnej dostępności całego serwisu. Pełny port marki: **CZĘŚCIOWO**.
