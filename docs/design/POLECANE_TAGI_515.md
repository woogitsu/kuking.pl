# Polecane tagi w wyszukiwaniu — odbiór #515

## Aktualny stan — 14 września 2026, po scaleniu PR #521

Pakiet #515–516 jest scalony: PR #521, head
`1cb2ab5f485a0130a992b1cd4e5ba8db2a02b672`, merge
`a3cb64df819351b18450603c1dcabe775aa748f0`.
Obowiązkowy lokalny hook i zwykły push zakończyły się sukcesem.
CI PR `34792102646`: **10 zadań success**, PHP **3726 testów / 75240 asercji**.
Port marki job `103818145082` zakończył się sukcesem po 17 min 54 s;
moduł tagów zaliczył 192 konfiguracje, cztery przejścia bez JS oraz
sześć rzeczywistych negatywów CSS z przywróceniem końcowego źródła.
Wyniki wcześniejszych prób poniżej pozostają zapisem historycznym.

Potwierdzenie wdrożenia i granice odbioru opisuje
[odbiór Alfa 0.22](ODBIOR_PRODUKCJI_ALFA_022.md).
Pełny port marki nadal ma status **CZĘŚCIOWO**; pozytywny CI nie oznacza
osobistego oglądu każdej strony, stanu i klienta poczty.

14.09.2026. Praca w osobnym worktree `fix/515-tematy-i-zasady`, baza
`c2a05d0`, następnie bez zmiany drzewa przesunięta na scalone `ae6b519`.
Pełna integracja pakietu #515/#516 i konfiguracji Alfa0.22: **w toku**.
Poniższe wyniki dotyczą lokalnego modułu, nie CI ani wdrożenia produkcyjnego.

## Zmiana

`/szukaj` bez frazy pokazuje pastelowe kafle zgodne z kompozycją `topic-card`
oryginalnego prototypu z ZIP. Dane pochodzą wyłącznie z `Tag::promowane()`:
aktywny tag, kolejność `TagPromotion.position`, opcjonalny prawdziwy `note`.
Nie powstaje nowy model Temat ani licznik popularności. Nazwa „Polecane tagi”
zachowuje D-021 i późniejsze rozstrzygnięcie wspólnego nazewnictwa tagów.

Pełna nazwa i opis pozostają tekstem. Krótki odnośnik „Zobacz tag” zawiera
pełną nazwę w etykiecie dostępnej; pseudoelement rozszerza klik na kafel.
Brak promocji daje uczciwy pusty stan i dotychczasową drogę do strumienia.
Wyszukiwarka GET, jej sekcje, wyniki, widoczność i prawa do stron tagów
pozostają zachowane. Zniknięcie relacji promocji nie powoduje błędu odczytu opisu.

Źródła zmiany: `SearchController.php`, `pages/search.blade.php`,
`marka-szukaj.css` i import w `app.css`. Regresje:
`PolecaneTagiWSzukajTest.php`, `tagi-marki.mjs`, `fixtures/kompozycje-515.php`.
Integracja dodaje moduł do `port-projektu.mjs`, opcjonalną trasę do
`zoom-marki.mjs` i plik modułu do obu filtrów ścieżek CI.

## Wykonane pomiary

- **192 konfiguracje PASS:** pełna/pusta lista × gość/zalogowany ×
  320/360/390/414/768/1440 × dwa motywy ×100/140/font200/font200+140.
  To rzeczywiście96 pełnych i96 pustych konfiguracji. Weryfikowane są
  przeliczone rozmiary pisma i kolory motywów, brak poziomego overflow,
  pełne nazwy i dokładne opisy z fixture, geometria kafli i cele48px.
- Rzeczywisty Tab przy320/140 w obu motywach dla obu odbiorców i stanów;
  kliknięcie punktu nagłówka przez rozszerzony odnośnik prowadzi do rzeczywistej
  strony tagu. Nie deklarujemy osobnego przebiegu Tab dla każdej ze192 konfiguracji.
- **6 negatywów CSS PASS:** `K515_OVERFLOW`, `K515_TEKST`, `K515_KOLUMNY`,
  `K515_KOLORY`, `K515_LINK`, `ZOOM_FOCUS_CONTRAST`. Przywrócenie przez `cp -p`,
  MD5 i mtime oraz dodatni pomiar po każdej mutacji. Zestaw192+6 zajął
  **286102ms (4min46s)**. Po review fixture i dokładnego porównania opisów
  wykonano końcowy dodatni192: **247155ms (4min07s)**; niezmienionych6 negatywów
  nie powtarzano bez potrzeby.
- **112 rzeczywistych zoomów PASS:** oryginalny moduł uruchomiony dwukrotnie,
  bez wycinania wcześniejszych tras i asercji:56 przy pełnej i56 przy pustej
  fixture. Z tego **16 dotyczy `/szukaj`**, pozostałe96 to zachowane trasy
  kontrolne. `chrome.tabs.getZoom=2`, DPR2 i viewport o połowie fizycznej
  szerokości. Czas obu przebiegów: **170094ms (2min50s)**.
- **34 testy PHP /211 asercji PASS** po przywróceniu źródeł: nowa regresja,
  `SearchTest`, `TagiPromowaneZListyGospodarzaTest`, `SzukajWidocznoscTest`,
  `JednoSlowoNaTagiTest`. Gość i konto zalogowane, cztery sekcje, aktywność,
  kolejność, opis/null, escapowanie, poprawne trasy i pusty stan.
- **4 negatywy PHP PASS:** zastąpienie listy promowanej wszystkimi tagami,
  odwrócona kolejność, nieescapowany opis oraz błędny cel odnośnika.
  Każda mutacja rzeczywistego źródła w native zakończyła się błędem asercji;
  źródła przywrócono z MD5 i mtime. Pint3 plików PHP przeszedł przed ostatnim
  handlerem fixture i dodatkowymi asercjami h3; końcowy Pint całego pakietu
  pozostaje elementem pełnej integracji.

## Dowody i przywrócenie

`output/515-final/` zawiera logi `run515-final.log`,
`run515-final-positive.log`, `zoom515.log`, wyniki PHP oraz zrzuty.
`php-negatywy/results.json` zapisuje także dokładne mtime_ns.

| Rzeczywiste źródło | MD5 przywróconych bajtów |
|---|---|
| `resources/css/marka-szukaj.css` | `9f558e8ea237da0b49b5781af0f5b43b` |
| `app/Http/Controllers/SearchController.php` | `ed6c35eada2328e15e13ecfc7f7f7af7` |
| `resources/views/pages/search.blade.php` | `a5f8bab97fa4d6a4a4864d8087863c4e` |

Pomiary korzystały z izolowanych baz na PostgreSQL55439. Fixture zapisuje
promocje, pierwotne ID tagów i wyłącznie ID tagów faktycznie przez siebie
utworzonych. Restore przywraca promocje, usuwa tylko te nowe ID i porównuje
pełny początkowy zestaw ID. Osobna próba na `kuking_port515_restore` potwierdziła
utworzenie3 tagów i powrót do pustego zestawu. Nieznany tryb jest odrzucany
przed mutacją. Handler wyjątków zwraca exit1; bez niego standalone Laravel
potrafił wypisać wyjątek i oddać exit0. Dodatkowej próby usunięcia handlera
w kopii nie zaliczamy jako wymaganej kontroli rzeczywistego źródła native;
procesową regresję tego błędu domyka osobno główny agent.

## Ogląd i ograniczenia

Obejrzano reprezentatywne pełne kafle desktopowe, pusty desktop w ciemnym
motywie oraz szczegóły pełnego/pustego widoku320 przy font200+140. Przy bardzo
dużym piśmie tekst zawija się, a pełna strona jest długa. Zrzuty pełnostronicowe
nie zastępują pomiaru fokusu: dolna nawigacja może znajdować się nad aktualnie
przewijaną treścią. Właściwy fokus sprawdza rzeczywisty Tab w opisanym zakresie.

Pomiar ujawnił za niski kontrast standardowego niebieskiego fokusu na żółtym
kaflu w ciemnym motywie (1,24). Naprawiono lokalny obrys `currentColor`, zgodny
z kontrastową parą tła i pisma kafla; negatyw przywracający błędny kolor oblał
kontrolę kontrastu. Nie osłabiano progów ani nie wyłączano ochrony wyszukiwarki.
Rzeczywisty limit60 żądań/min zachowano przez odstęp1250ms między GET.

Nie oglądano osobiście każdego z192 wariantów. Zdjęcia i osoby poboczne są
danymi lokalnymi; żadnej zawartości nie dopisywano na produkcji. Historyczny
pusty stan produkcji nie gwarantuje, że samo wdrożenie doda promowane tagi.
Własne procesy browser/server zakończono i native przekazano do pełnej integracji.


## Integracja: obsługa błędu fixture i budżet czasu

Końcowy Pint sześciu plików PHP przeszedł; poprawił wyłącznie pusty wiersz
w teście instrukcji. W starszej fixture513 rzeczywisty proces odmawiający
niedozwolonej bazy zwracał 0 mimo RuntimeException. Dodano jawny handler
wyjątków z exit1, analogiczny do fixture515. Nowy
`FixturePortuSygnalizujeBladTest` uruchamia oba rzeczywiste pliki PHP,
wymaga exit1 i komunikatu odmowy bez SQLSTATE. Baza ma celowo niedozwoloną
nazwę; nie dochodzi do zapytania. Wynik: **1 test / 6 asercji PASS**.

Dwa negatywy usuwały handlery z rzeczywistych źródeł native. Każdy oblał
asercję kodu procesu; po przywróceniu bajtów i mtime następował PASS.
Backup/logi poza repo: `/tmp/fixture515-negative-6f37h8kd`.
MD5 fixture513: `f66a33540895a8ca0ae357a3de79bd12`, fixture515:
`d54d4ec107439d2cc075b1033a007fd4`. Nie oznacza to audytu wszystkich
historycznych fixture w repozytorium.

Limit czasu zadania portu CI zwiększono z 20 do 25 minut: poprzedni
rzeczywisty job103802494830 trwał 14min28s, a nowy moduł z negatywami
4min46s. Zapas obejmuje dodatkowy zoom i zmienność runnera. Nie zmieniono
asercji, progów jakości, liczby kontroli ujemnych ani continue-on-error.

## Pełny port przed dodaniem drogi do wszystkich tagów

Zintegrowany port zakończył się kodem 0 i `PORT_OK` po **1168,7588 s
(19 min 29 s)**. Log: `/tmp/kuking-port515-final.log`. Wykonał K509432,
K51196, K51396, K515192 oraz ZOOM88 wraz z końcowymi kontrolami ujemnymi.
Ten wynik dotyczy źródeł **sprzed dodania linku „Wszystkie tagi”** i nie jest
dowodem odbioru nowego celu klawiaturowego. Potwierdza też zasadność
25-minutowego budżetu wykonania całego portu, bez zmiany progów produktu.

## Uzupełnienie kryterium linku do wszystkich tagów

Wspólny link do `/tagi` dodano w pełnym i pustym stanie sekcji. Pierwsza
próba po skopiowaniu źródła zachowującego mtime odczytała stary skompilowany
Blade; po `view:clear` rzeczywisty DOM zawierał poprawną etykietę i adres.
To problem świeżości lokalnego przyrządu, nie błąd aplikacji.

Następny rzeczywisty Tab ujawnił problem nowego linku: brak odstępu od siatki
powodował zasłonięcie górnego pierścienia przez poprzedni kafel. Siatka kończyła
się dokładnie przy górnej krawędzi linku, a punkt obrysu trafiał w rozszerzony
odnośnik poprzedniego kafla. Dodano lokalny `margin-top: var(--spacing-5)`.
Tester fokusu i jego progi pozostają niezmienione. Wcześniejsze MD5 CSS/Blade
powyżej są historyczne. Po dodaniu linku i odstępu wykonano:

- **192/192 PASS**, 253417 ms; zakres pełny/pusty, gość/właściciel, sześć
  szerokości, oba motywy i cztery ustawienia tekstu jak wyżej. Nowy link jest
  sprawdzany w każdej konfiguracji: dokładna etykieta, `/tagi`, pełny tekst
  oraz prostokąt co najmniej48 ×48 px. Rzeczywisty Tab obejmuje go w istniejącym
  zakresie320/140; pełny widok ma11, pusty8 dostępnych celów.
- **4 rzeczywiste przejścia bez JavaScript PASS:** pełny/pusty stan dla gościa
  i właściciela, kliknięcie „Wszystkie tagi”, docelowe `/tagi` i HTTP200.
- **112/112 rzeczywistych zoomów PASS**, 168728 ms: oryginalny moduł obejmujący 56 wariantów
  siedmiu tras dla każdej fixture. Z tego16 wariantów dotyczy wyszukiwarki
  z nowym linkiem;96 to zachowane wcześniejsze trasy kontrolne.
- **34 testy PHP /223 asercje PASS** po rzeczywistym negatywie usuwającym
  wspólny link z Blade i przywróceniu źródła. Asercje sprawdzają drogę do tagów
  w pełnym i pustym stanie oraz rzeczywiste GET docelowej strony.
- Usunięcie lokalnego odstępu z rzeczywistego CSS ponownie wywołało dokładnie
  `ZOOM_FOCUS_OCCLUDED /szukaj` dla „Wszystkie tagi”. Po `cp -p` restore,
  weryfikacji MD5/mtime, buildzie i ponownym Tab wynik był dodatni.

| Końcowe źródło | MD5 przywróconych bajtów |
|---|---|
| `resources/views/pages/search.blade.php` | `2a23d39a2515bf970cff2e61bd040c80` |
| `resources/css/marka-szukaj.css` | `b531cedae0213089a32a1e9727170a84` |

Dowody końcowe: `output/515-link-final/` (logi browser/zoom/PHP,
`negative-link/results.json`, `negative-space/results.json` z mtime_ns
oraz końcowe zrzuty pełnej i pustej strony). Zrzut `diag515-focus.png`
pokazuje diagnozowany problem **przed odstępem**, nie końcowy fokus.
Po naprawie fokus sprawdzono pomiarem; osobnego zrzutu aktywnego nowego
linku po restore nie zapisano. Nie uruchamiano ponownie przeglądarki po
przekazaniu native do końcowej integracji. Starych sześciu negatywów CSS
nie powtarzano: dotyczą wcześniejszego hasha i są oddzielone od dwóch nowych
kontroli oraz końcowego dodatniego odbioru. Procesy własne zakończono,
fixture odtworzyła dane, native zwolniono do Pint/hooka/CI.


Końcowy Pint: **1004 pliki PASS**. Wspólny niezależny proces testów
instrukcji i kodów zakończenia fixture: **5 testów / 41 asercji PASS**.
Root obejrzał końcowy zrzut pełnej wyszukiwarki desktopowej z nowym
odnośnikiem i metryczką Alfa 0.22. Wysyłka, hook i CI wymagają jeszcze
osobnego potwierdzenia.


Pierwszy obowiązkowy hook zatrzymał wysyłkę commita f6b600c: kontrola
odnośników uznała zapis dwóch lokalnych ścieżek Windows w nowych notatkach
za trasy serwisu. Zapis ścieżek poprawiono bez zmiany walidatora. Trzy testy
dokumentów / 41 asercji przeszły, a dwa rzeczywiste powroty błędnego zapisu
zostały wykryte. Źródła przywrócono z MD5 i mtime; logi poza repo:
`/tmp/docpaths515-kpqa7eze`. Nie był to wynik pozytywny pełnego hooka;
powtórna normalna wysyłka wymagała jego ponownego przejścia.
Późniejszy zwykły push 1cb2ab5 przeszedł pełny hook; wynik CI i scalenia
opisano w aktualizacji na początku dokumentu.
