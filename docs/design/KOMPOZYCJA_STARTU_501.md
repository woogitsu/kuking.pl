# Odtworzenie kompozycji Startu — Alfa 0.15

13 września 2026. Issue #501, D-207. Stan dokumentu: kontrole lokalne
ukończone poza końcową kontrolą PHP; wynik CI i produkcji zostanie dopisany po ich odczycie.

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

Końcowe PHP, CI i wdrożenie: do uzupełnienia po zakończeniu.
Nie przenosimy tu liczb z poprzedniej wersji.

## Granice dowodu

Wyniki `Page.setFontSizes` oznaczają podwojenie bazowego pisma, nie
rzeczywisty zoom przeglądarki. Próba odrębnego odbioru natywnego Chrome
nie dała okna z lokalną stroną do bezpiecznego wskazania; nie uznajemy
jej za zaliczony test zoomu. Nie sprawdzono fizycznej klawiatury ekranowej.

To naprawa konkretnego rozjazdu ze wzorcem. Nie oznacza pełnego odbioru
wszystkich ekranów i historycznych tekstów całego portalu. Konstytucja 1.4,
COPY_STYLE i GLOS_MARKI opisują D-207; wcześniejszy audyt Alfa 0.14 nie był
dowodem zgodności kompozycji z tym obrazem.
