# Odtworzenie kompozycji Startu — Alfa 0.15

13 września 2026. Issue #501, D-207. Kontrole lokalne i CI PR zakończone.
Odbiór wdrożenia aplikacji opisano osobno poniżej; zapis raportu nie zmienia
źródeł aplikacji.

## Wzorzec i zakres

Właściciel wskazał [wizualizację „Dzień dobry, Basiu”](references/start-wzor-501.png).
Niezależny subagent potwierdził, że wcześniejsza produkcja zachowywała
odmienny nagłówek, nawigację, hierarchię kafla i nieopakowaną tablicę.
Dokładnego źródła HTML wzorca nie znaleziono w dostępnych refach ani
w `origin/design/brand-constitution-v1` (PR #456, `96f6953`). Nie scalano
historycznego prototypu. Punktem wyjścia kodu jest aktualny main
`73319951ec98cd89f814ed05b548cabaea690886`.

Wzorzec jest odniesieniem kompozycji, nie źródłem danych. Pozostają garnek
z koroną i uśmiechem, nazwa z rzeczywistego profilu, widoczny podpis
Powiadomienia, przełącznik strumienia, podglądy i notatki tablicy oraz
wspomnienia. Nie przeniesiono fikcyjnych osób, liczb ani ostrzeżenia
„Podgląd nowego wyglądu”. Zdjęcie produkcji z prywatnym wpisem przekazane
przez właściciela nie zostało dodane do repozytorium.
Odnośnik przy „Może ich znasz?” brzmi „Szukaj”, bo otwiera istniejącą
wyszukiwarkę osób, a nie nieistniejącą stronę kolejnych rekomendacji.

## Potwierdzone różnice i poprawki

| Obszar | Wcześniejszy brak | Poprawka |
|---|---|---|
| Nagłówek Startu | Długie powitanie z pytaniem, brak osobnego linku | Krótkie „Dzień dobry”, pytanie w kaflu, „Poznaj Kuking” obok nagłówka |
| Kafel dodawania | Tytuł wielkości tekstu, mały krążek, akcje na całą szerokość | Tytuł 28 px, pierścień 260 px, grupa dwóch akcji do 500 px |
| Komputerowa nawigacja | Pięć jednakowo czerwonych pozycji | Start / Odkrywaj / Mój zeszyt, neutralne etykiety i osobne Szukaj |
| Szyna | Goła lista osób i dań | Ciemny wstęp, osobne powierzchnie osób i dań; zeszyt pod tablicą |
| Strumień | Brak nagłówka sekcji, ciężkie tło zakładek | Nagłówek 24 px i odnośnik do wyjaśnienia kolejności; działające zakładki pozostają |
| Wejście przez `/` | Kontekst trasy mógł przywracać starą tablicę zalogowanemu | Jawny wariant karty w szynie oraz aktywny Start na obu trasach |
| Bardzo duży tekst | Nowy nagłówek strumienia rozszerzał stronę do 380 px przy oknie 320 px | Zawijanie słów i `min-width: 0` w elemencie flex, bez zmniejszania pisma |
| Margines belki | Pierwsza wersja zmniejszyła margines do 16 px | Przywrócono wymagane 20 px; 18 niezgodności wyrównania → 0 |

Zmiany wspólne są w `marka-rama.css`, `layout.blade.php` i
`kuking-board.blade.php`; kompozycja Startu w `home.blade.php` oraz
`szyna-startowa.blade.php`. Powitanie: `FeedController`. Pomoc dostała
rzeczywistą sekcję docelową dla „Jak działa kolejność?”.

## Macierz odbioru

| Ekran / stan | Wygląd i tekst | Mały ekran / duży tekst | Dowód | Ograniczenie |
|---|---|---|---|---|
| `/home` i `/` po zalogowaniu | Nowa hierarchia, prawdziwe dane | 320, 360, 390, 414, 768, 1440; oba motywy, 100/140% | `port-projektu.mjs`, test kompozycji | Dane demonstracyjne lokalnie |
| `/odkryj`, `/szukaj` | Ta sama nawigacja i tablica | Niezależne oglądanie 320/1440, oba motywy, 140% | Drugi subagent i skaner dostępności | Inny cel strony niż Start |
| Profile, przepis, zeszyt, dodawanie, ustawienia, powiadomienia | Wspólna rama zachowana | Sześć szerokości i oba motywy | `port-projektu.mjs` | Pomiar geometrii nie zastępuje oglądania każdego stanu |
| Publiczny Start, logowanie, rejestracja, O Kuking | Wspólna rama i brak overflow | 320/1440 | `port-projektu.mjs` | Brak zmian logiki logowania |
| Długie karty tablicy | Fokus nadal na autorze, kliknięcie całej karty | 36 wariantów, oba motywy | `fokus-karty-dania.mjs` | Powiększenie pisma przeglądarki jest emulacją |
| Kafel dodawania | Tytuł i opis czytelne, obie akcje | 15 wariantów | `kafel-dodawania.mjs` | Bez fizycznej klawiatury ekranowej |

## Wyniki lokalne

- Port: **175 pomiarów**: 132 zwykłe pomiary stron zalogowanych, 24 dla
  obu wejść Startu z tekstem 140%, 8 stron gościa i 11 przy podwojonej
  czcionce przeglądarki. Brak overflow po poprawce nagłówka.
- Cztery nowe negatywy prawdziwego CSS wykryte osobno w wyniku: mały
  tytuł, brak pierścienia, jasny wstęp, przezroczysta karta osób. Zachowano
  pięć wcześniejszych kontroli portu. Kopia poza repo, przywrócenie i MD5
  `6d86363cc92903a8d780795970f153f0` potwierdzone na końcowym arkuszu.
- Fokus: 36 wariantów oraz trzy rzeczywiste kontrole ujemne; kompletność
  Tab i kliknięcia zdjęcia, opisu oraz wolnej powierzchni przeszły.
  Przywrócony MD5 `app.css`: `25e78b341aeaad7bf9c149f45988c742`.
- Kafel: **0 naruszeń w 15 wariantach** we wszystkich sześciu kategoriach.
  Bramka wykryła sześć sabotaży kodu wyjścia; źródło odtworzone.
- Powitanie: **11 testów / 73 asercje**. Negatyw w kontrolerze wykryty,
  MD5 i mtime przywrócone, ponowny przebieg zielony.

- Pełny niezmieniony `dostepnosc.mjs`: **exit 0, axe 44/44, układ 49/49**.
  Zero naruszeń, overflow i problemów fokusu. Wyrównanie belki: 18 błędów
  przed przywróceniem 20 px, zero po poprawce. Osobna kopia i baza subagenta.
- Niezależne oglądanie: 16 wariantów Startu gościa, `/home`, `/odkryj`
  i `/szukaj`, 320/1440, oba motywy, tekst 140%; zero overflow.
  Dodatkowo obejrzano desktop 100% względem przesłanego wzorca.
- Nowy test kompozycji i pięć dostosowanych klas: **59 testów / 520 asercji**.
  Negatywy wykryły brak kart na zalogowanym `/`, błędny cel kafla i zerowy
  odstęp nagłówka; źródła odtworzone z MD5 i mtime.

Końcowa kontrola lokalna przed wysłaniem przeszła: Pint, składnia PHP
i skryptów, PHPStan, pełne testy PHP i odwracalność migracji. Build Vite
przeszedł także w kontekście plików kopiowanych przez Dockerfile.
Końcowe oglądanie po zmianie odnośnika osób na „Szukaj” objęło 28 wariantów
Startu (siedem szerokości do 1654 px, oba motywy, 100/140%): bez overflow.

## Odbiór PR i wdrożenia Alfa 0.15

- [PR #502](https://github.com/woogitsu/kuking.pl/pull/502) scalono po
  dziewięciu poprawnych zadaniach [CI #939](https://github.com/woogitsu/kuking.pl/actions/runs/34762630619).
  Sprawdzony SHA PR: `7b8b772a346626763bdf63e7c8ee8f7d3598a357`;
  commit scalenia: `74a930fd00d72dc0cb2a773d6bb7ca3ad2452e6c`.
- Z odczytanego logu tego przebiegu: **3694 testy PHP / 74 656 asercji**;
  wyścigi **5 testów / 44 asercje**; dostępność **axe 44/44, układ 49/49**,
  zero naruszeń i zasłoniętych fokusów; Lighthouse **8/8**, bez
  niezaliczonych ekranów. Vite, obrazy, Pint, Larastan i audyt zależności
  także poprawne. Nie są to liczby przeniesione z Alfa 0.14.
- [CI main #940](https://github.com/woogitsu/kuking.pl/actions/runs/34763151966)
  i [Deploy #620](https://github.com/woogitsu/kuking.pl/actions/runs/34763762181)
  zakończyły się sukcesem. Deploy #619 był pominięty i nie stanowi dowodu.
- Railway: deployment `abe43197-de8c-4504-a8a0-86c37b138d90`, production,
  **SUCCESS**, SHA `74a930fd00d72dc0cb2a773d6bb7ca3ad2452e6c`,
  zakończenie **13 września 2026, 14:49:00 UTC**.
- Zalogowana produkcja `/home` i `/` pokazała **Alfa 0.15 / 74a930f**
  i „Dzień dobry, Mateusz”. Obejrzano nowy Start przy rzeczywistym oknie
  1654 × 904, po wczytaniu zdjęć. Zachowano preferencję konta 90%.
  Odkrywaj oraz wyszukiwanie mają nową nawigację i ciemny wstęp tablicy;
  nie stwierdzono poziomego overflow.
- Produkcyjny CSS `app-02Am5wsb.css` ma SHA-256
  `f47aa0613248a3427f79bad4ba408baf86b8164afe9961d25366042aba5b5a92`,
  zgodny bajt w bajt z lokalnym buildem w kontekście Dockerfile.
- Publiczny odbiór `/`, `/odkryj`, `/szukaj` i `/login`: osiem pomiarów
  przy 320/1440, wszystkie HTTP 200, bez poziomego overflow; oba lokalne
  podzbiory Inter załadowane, tekst podstawowy 18 px. Początkowe oczekiwanie
  na całkowity bezruch sieci wygasło na loginie; ponowny pomiar czekał na
  dokument i fonty, nie na zakończenie ruchu zewnętrznego widżetu.

## Uzupełnienie odbioru: proporcje tablicy (#503)

Oglądanie produkcyjnego Odkrywaj ujawniło brak, którego wcześniejszy
pomiar overflow nie wykrywał: awatar osoby miał nadal 120 px, podczas
gdy Start i wyszukiwanie miały 52 px. Ograniczenie reguł do `.app-rail`
pomijało inny kontener Odkrywaj. Podobnie miniatury dań miały 120 zamiast
72 px. Dlatego Alfa 0.15 nie jest oznaczana jako pełne domknięcie zgodności.
Brak zapisano w [issue #503](https://github.com/woogitsu/kuking.pl/issues/503).

Poprawka Alfa 0.16 przypisuje geometrię do `.marka-tablica`: awatar 52 × 52 px,
zdjęcie dania 72 × 72 px, inicjał wielkości tekstu podstawowego. Nie zmienia
pełnej tablicy publicznej strony powitalnej ani awatarów profili i wpisów.
Rozszerzony port obejmuje `/odkryj` i wymaga niepustej próbki osób i zdjęć
na czterech wejściach. Wynik lokalny niezależnego subagenta: **188 pomiarów**,
w tym 76 pomiarów tablic z poprawnymi 76 awatarami i 228 zdjęciami.
Dwa sabotaże prawdziwego CSS wykryły osobno błędny awatar i zdjęcie;
kopia poza repo, przywrócony MD5 `22c58f925ad2823d63ff06f632a0910e`,
ponowny odbiór czterech tras zielony. Pięć istniejących klas testów szyn:
**38 testów / 207 asercji**, poprawne. Obejrzano końcowy Odkrywaj przy
320/1440, oba motywy, tekst 140%; awatar 52 px i brak overflow.
Pełny niezmieniony skaner dostępności po tej poprawce: **exit 0,
axe 44/44, układ 49/49**, zero naruszeń. Zewnętrzny serwer lokalny
z wyłączoną pocztą został prawidłowo odrzucony przez warunek wstępny;
zaliczony przebieg użył własnego serwera skanera z pełnymi formularzami.

## Granice dowodu

Wyniki `Page.setFontSizes` oznaczają podwojenie bazowego pisma, nie
rzeczywisty zoom przeglądarki. Próba odrębnego odbioru natywnego Chrome
nie dała okna z lokalną stroną do bezpiecznego wskazania; nie uznajemy
jej za zaliczony test zoomu. Nie sprawdzono fizycznej klawiatury ekranowej.

To naprawa konkretnego rozjazdu ze wzorcem. Nie oznacza pełnego odbioru
wszystkich ekranów i historycznych tekstów całego portalu. Konstytucja 1.4,
COPY_STYLE i GLOS_MARKI opisują D-207; wcześniejszy audyt Alfa 0.14 nie był
dowodem zgodności kompozycji z tym obrazem.
