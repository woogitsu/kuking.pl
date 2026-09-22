# Tagi w opisie wpisu — #647

Stan 17.09.2026: **lokalna implementacja, odbiór w toku**. Bez PR i wdrożenia.
Zintegrowano main b988139 po lokalnym zapisie WIP de82796 (merge 6f0de3d).
Na połączonych źródłach, z roboczą wersją Alfa 0.57, ponowiono build,
PHPStan, testy tagów i wyboru zeszytu oraz poniższy odbiór przeglądarkowy.

## Zachowanie

Wpisanie `#` w opisie uruchamia podpowiedzi istniejących tagów z liczbą
publicznych wpisów dostępnych widzowi. Wyszukiwanie wysyła wyłącznie aktywną
frazę, nie cały opis. Wybór podpowiedzi wstawia token w miejscu kursora.
Publikacja rozpoznaje także hashtagi wpisane lub wklejone bez wyboru sugestii.
Dotychczasowy formularz ręcznych tagów pozostaje dostępny.

Serwer rozstrzyga końcowy zbiór tagów i limit pięciu różnych kanonicznych tagów.
Istniejący slug może mieć 40 znaków; limit nowej nazwy pozostaje 30.
Fragment adresu URL nie staje się tagiem. Alias lub slug istniejącego taga
nie tworzy nowego duplikatu.

`post_tags.dodany_recznie` rozróżnia ręczny wybór od powiązania wyłącznie
z opisu. Usunięcie hashtagu nie usuwa ręcznie dodanego taga. Migracja przypisuje
starym relacjom ręczne pochodzenie. Scalanie zachowuje ręczne pochodzenie, jeśli
miała je którakolwiek relacja. Cofnięcie migracji odmawia utraty tego rozróżnienia,
jeżeli istnieją relacje wyłącznie z opisu, również dla miękko usuniętych wpisów.

Ukryte tagi nie są proponowane ani pokazywane jako odnośniki pod wpisem.
Istniejące powiązania mogą zostać zachowane podczas edycji. Treść autora
nie jest przepisywana. Stare dane formularza ręcznych tagów są przypisane
do konkretnego edytowanego wpisu; błąd innego formularza nie zeruje jego tagów.

## Wykonane kontrole lokalne

- 92 testy / 1747 asercji: publikacja i edycja, schemat, scalanie, podpowiedzi,
  parser, pochodzenie relacji, migracja i eksport. Wszystkie przeszły po poprawkach.
- 2 testy / 7 asercji istniejącej regresji wyścigu tworzenia taga: wstrzyknięcie
  konkurencyjnego INSERT. To nie jest pomiar dwóch niezależnych procesów.
- Dodatkowy pomiar dwóch i pięciu podpowiedzi potwierdza stałą liczbę zapytań
  oraz poprawne liczniki. Po jego dodaniu plik endpointu: 7 testów / 62 asercje,
  wszystkie przeszły.
- Build Vite, 72 pary kontrastu i 7 testów JS (3 tagów, 4 PWA): przeszły.
- PHPStan bez błędów na wcześniejszym snapshotcie; powtórzyć na końcowym źródle.
- Niezależny przegląd domeny oraz poprawki markera i ukrytych odnośników:
  bez pozostałego blokera w odczytanym zakresie. Nie zastępuje testów przeglądarkowych.

Fizyczne kontrole ujemne rzeczywistych źródeł:

1. Błędny pierwszy wybór ArrowUp i przyjęcie tokenu z podkreśleniem — obie
   mutacje JS wykryte. Przywrócono MD5 `3cea1a78363ca598e26fbe2d23c14124`
   i czas modyfikacji; dodatni przebieg 3/3.
2. Zamiana ręcznego pochodzenia na wyłącznie opisowe w resolverze runtime —
   wykryta; przywrócono MD5 `a0228ce05e13c8cf83fad42952901740` i czas modyfikacji.
   Dodatni przebieg 19 testów / 88 asercji.

Kopie kontrolne przechowywano poza repozytorium. Lokalne logi i wyniki są
w katalogu `output` kanonicznego repo: `negative647-js-result.json`,
`negative647-origin-result.json`, `negative647-origin.log`, `positive647-origin.log`.

## Do zakończenia

### Powtórka po integracji main

- 100 testów PHP / 1859 asercji: tagi i wybór zeszytu — wszystkie przeszły.
- PHPStan bez błędów; build, 72 pary kontrastu i 7 testów JS przeszły.
- 24/24 konfiguracje: 320/360/390/414/768/1440, oba motywy, tekst 100/140%.
- 4/4 rzeczywisty zoom 200%: `chrome.tabs.getZoom() = 2`, DPR 2,
  szerokość CSS 320, oba motywy i obie skale tekstu.
- Rzeczywiste kliknięcie, ArrowDown/Enter, publikacja i GET wpisu, edycja
  z usunięciem hashtagu oraz GET bez jego odnośnika — przeszły.
- Wklejenie przez Control+V, Escape, zastąpienie tokenu w środku tekstu,
  wybór nowego taga bez ukrytego ręcznego pola i błąd transportu — przeszły.

Dowody lokalne powtórki: `output/tagi647-browser/integrated/` w repo kanonicznym.
To Chromium na lokalnych danych; nie fizyczny telefon ani odbiór produkcji.
Wybrane wyniki i zrzuty zachowano w `docs/design/evidence/tagi647/`.
Przy wyłączonym JavaScript rzeczywista publikacja hashtagu oraz usunięcie go
przy edycji również przeszły. Osiem uwag Pint poprawiono i sprawdzono ponownie.

Wcześniejszy odbiór przeglądarkowy na snapshotcie sprzed integracji main: przejście
wybór taga → publikacja → odczyt → edycja z usunięciem hashtagu działa lokalnie.
Zebrano 16 konfiguracji (320/390/768/1440, dwa motywy, tekst 100/140%).
Sprawdzono również Escape, zastąpienie tokenu w środku tekstu i brak propozycji
tworzenia nowego taga po błędzie transportu. Nie jest to jeszcze pełny odbiór:
uzupełnienie szerokości 360/414 i rzeczywistego zoomu 200% zapisano osobno.

- Odbiór rzeczywistych podpowiedzi, publikacji i edycji w przeglądarce,
  obu motywów, szerokości mobilnych i desktopowych, powiększenia oraz błędów sieci.
- Pomiar zapytań i adekwatne kontrole współbieżności nowych blokad/scalania.
- Końcowy pełny hook na dokładnym commicie i review kompletnego pakietu.
- Zwykły push, PR i wymagane CI. Wersja Alfa 0.57 i changelog są przygotowane.
- Wdrożenie oraz potwierdzenie rzeczywistego produkcyjnego SHA.

## Korekta po pierwszym pełnym hooku

Pierwszy push został zatrzymany przez testy; gałąź nie została wysłana.
Model `PostTag` wymagał jawnej nazwy tabeli `post_tags`, aby także utworzony
poza relacją wskazywał prawidłowy schemat. Osobna baza dostarczenia miała
strefę `Europe/Warsaw`; ustawiono UTC tylko dla tej izolowanej bazy na 55439.
Po obu korektach wszystkie rodziny wcześniejszych porażek oraz pochodzenie
tagów przeszły: 259 testów / 2246 asercji. Pint modelu przeszedł.

Fizyczna zamiana nazwy tabeli na błędną została wykryta przez istniejący test
inwentaryzacji modeli. Przywrócono plik z kopii poza repo, MD5
`81c7d42384b8f40eb7b1228f72e7e2d9` i mtime; dodatni przebieg: 6 testów / 147
asercji. To nie zastępuje ponownego pełnego hooka.

Dodatkowo cztery rzeczywiste procesy PHP sprawdziły `TagMutationLock`:
dwa zapisy mogą trzymać blokadę wspólnie, scalanie czeka na oba, a kolejny
zapis czeka na scalanie. Nie wykonywano zmian danych; nie jest to pełny
równoległy scenariusz publikacji i scalania. Przeglądarka potwierdziła też
odrzucenie spóźnionej odpowiedzi po zmianie wpisywanego tokenu. Wyniki tych
trzech dodatkowych kontroli zapisano obok pozostałych dowodów.

## Poprawka po CI #649: semantyka podpowiedzi

CI dla `984806a` wykryło cztery naruszenia `aria-allowed-attr`: pole opisu
miało `aria-expanded`, którego rola wielowierszowego textboxu nie obsługuje.
Lokalny skan otwartej listy ujawnił też umieszczenie popupu poza landmarkiem.
Usunięto `aria-expanded`, dodano `aria-haspopup="listbox"`, a listę umieszczono
wewnątrz formularza w `main`. Zachowano natywną rolę textarea, powiązanie
`aria-controls`, aktywną opcję i komunikaty stanu.

Do istniejącej macierzy CI dodano oddzielny ekran otwartych podpowiedzi.
Pobiera propozycje rzeczywistą trasą, naciska ArrowDown i sprawdza powiązania,
fokus oraz semantykę przed axe. Lokalna próba zamkniętej i otwartej listy:
zero naruszeń. Build, 72 pary kontrastu i siedem testów JS przeszły.

Fizyczne negatywy JS: przywrócenie `aria-expanded` oraz umieszczenie listy
w `body` zostały wykryte. Kopia poza repo:
`/tmp/kuking647-aria-negative-gnj7utpf/tagi-w-opisie.js`.
Przywrócono MD5 `e2c70b439b411e6f969c7de856afb0a1` i mtime; ponowny build
oraz dodatni skan zamkniętej/otwartej listy przeszły. Dowód lokalny:
`output/negative647-aria-result.json` w repo kanonicznym.
Niezależny przegląd diffu nie wykazał blokera; nie zastępuje pomiaru geometrii.
Zmiana wymaga ponownego hooka, wysyłki i CI; pierwszy przebieg CI nie jest sukcesem.

Po zmianie rodzica popupu: 24/24 konfiguracje geometrii, wybór myszą i klawiaturą oraz 4/4 rzeczywistego zoomu 200% przeszły. Obejrzano mobilny zrzut 320 px w ciemnym motywie przy 140%. Dowody macierzy, zoomu i negatywów zachowano w evidence/tagi647/aria*.json.

## Odbiór PR #701 na produkcji 8a2ecb2a (19.09.2026)

Ten rozdział jest ODBIOREM, nie implementacją: kod #647 był już scalony.
Sprawdzono, co z kryteriów zgłoszenia naprawdę stoi na produkcji, czym to
udowodniono i czego udowodnić się nie dało.

### Co scalił #701

Dwa pliki, `5ecbcce`, scalony 18.09.2026 22:50 UTC, jest przodkiem
produkcyjnego `8a2ecb2a` (sprawdzone `git merge-base --is-ancestor`).

1. `resources/views/components/tagi-formularz.blade.php` (+15/−8) — dolna
   wyszukiwarka tagów schowana w `<details>` z podsumowaniem „Dodaj tag
   bezpośrednio…”, „Znajdź tag” przemianowane na „Sprawdź tag”, tekst
   pomocniczy kieruje do hashtagu w opisie.
2. `.github/workflows/ci.yml` (+12/−0) — trzy joby utrwalają `APP_KEY`
   w `.env` przed `php artisan serve`, bo proces `php -S` czyta `.env`,
   a nie środowisko joba.

Produkcyjne potwierdzenia: `meta kuking-service-worker` →
`/sw.js?v=8a2ecb2a91bec7f7fef0f40e9d1c59b8c4735257`, deployment GitHuba
z 19.09 10:28 UTC na tym samym SHA, CI main run `35437520171` = success,
stopka „Alfa 0.67”. `/health` zgłasza `degraded`, ale jedyny nieudany
podsystem to `kolejka: zadania_nieudane` — z tagami nie ma związku.

### Usterka znaleziona i naprawiona: wynik „Sprawdź tag” był niewidoczny bez JS

`<details>` z #701 nie ma `open` i nie miał żadnego warunku, a WYNIK
wyszukiwania renderuje się w jego środku. Bez JavaScriptu (AGENTS.md §5)
przebieg wyglądał tak: człowiek wpisuje frazę, klika „Sprawdź tag”, strona
się przeładowuje — i nie widzi NICZEGO. Podpowiedzi, komunikat „Ten tag jest
już dodany.”, „Nic nie znaleźliśmy…” i przycisk „Dodaj … jako nowy tag”
wszystkie są w HTML, ale w zwiniętej sekcji.

Zmierzone na `8a2ecb2a` sondą renderującą prawdziwą odpowiedź
`GET /dodaj/zdjecie` po kroku `szukaj_tagu`:
`PODPOWIEDZI_W_HTML=1`, `WEWNATRZ_DETAILS=1`, `DETAILS_MA_OPEN=false`,
`PRZYCISKI_DODAJ=2`.

Dlaczego nie złapały tego istniejące testy: cała sekcja „bez JavaScriptu”
w `TagiWpisowTest` sprawdza `assertSessionHasInput` i `assertRedirect`,
czyli STAN SESJI. Żaden test nie patrzył na wyrenderowany formularz, więc
schowanie wyniku przed człowiekiem przeszło bokiem — 19/19 było zielone
i przed poprawką, i po wprowadzeniu usterki.

Poprawka jest jednolinijkowa: `<details class="mt-3" @if($zapytanie !== '')
open @endif>`. Wszystkie cztery bloki w środku są warunkowane frazą, więc
jeden warunek wystarcza, a pusty formularz nadal pokazuje sekcję zwiniętą —
czyli intencja #701 (jedna główna droga, bez drugiego interfejsu) zostaje.

### Dowody

- `tests/Feature/TagiWpisowTest.php`: +3 testy patrzące na RENDER
  (`DOMXPath`, `ancestor::details[not(@open)]`). Plik: **22 testy /
  70 asercji**, zielony. Przed poprawką: 19/60.
- Rodzina tagów i formularzy (`--filter "Tag|Tagi|QuestionForm|PostForm|Podpowiedzi"`):
  **275 testów / 50 075 asercji**, zielone.
- Pełna suita na PostgreSQL 127.0.0.1:55439, osobna baza
  `kuking_647_odbior_claude`: **4283 testy / 82 783 asercje**, zero porażek,
  3 notice'y PHPUnit, 5 min 09 s.
- `node --test resources/js/tagi-w-opisie.test.mjs`: 3/3.
- Pint na zmienionym teście: PASS. PHPStan na zmienionym teście: No errors.

**Fizyczna kontrola ujemna** (`evidence/tagi647/odbior701-kontrola-ujemna.txt`):
cofnięcie `open` w blade. MD5 `781aaea9357a2533f2aa9526d12d2c2c` →
`1e5c009bb9737e9e4b141b7851b9f85f`, grep potwierdził `open @endif` = 0
wystąpień i `<details class="mt-3">` = 1 wystąpienie, linia 68 wypisana.
Testy oblały 2 z 22 (te dwa nowe, asercje 68 zamiast 70), dokładnie z
komunikatem o zwiniętej sekcji. Po przywróceniu z kopii spoza repo MD5
i mtime zgodne, przebieg dodatni 22/70.

Pierwsze podejście do tej kontroli było NO-OPEM (wzorzec `perl -pe` się nie
dopasował, MD5 się nie zmienił). Skrypt ma wbudowaną bramkę „MD5 bez zmian →
błąd” i sam to zatrzymał, zanim testy zdążyły dać fałszywy zielony wynik.

### Tabela kryteriów

| Kryterium z #647 | Stan | Warstwa dowodu |
|---|---|---|
| Autocomplete po `#` przy kursorze; mysz/dotyk, strzałki, Enter, Escape; Enter nie publikuje | spełnione | kod (`tagi-w-opisie.js`: `preventDefault` na Escape/strzałkach/Enter), 3 testy JS, wcześniejszy odbiór przeglądarkowy (17.09, 24/24 konfiguracje), bundle obecny na produkcji |
| Uczciwy licznik: tylko publiczne wpisy dostępne widzowi, blokady, dostępność przepisu | spełnione lokalnie | kod (`PodpowiedziTagow`: `publiclyVisible`+`tylkoOdAktywnychAutorow`+`widoczneDla`+`zWidocznymPrzepisem`), 7 testów `PodpowiedziTagowEndpointTest` w zielonej suicie, CI main success |
| Dodawanie i edycja; polskie znaki, wklejanie, kursor w środku, usuwanie, brak wyników, powtórki, limit 5 | spełnione lokalnie | testy `TagiWpisowTest`/`TagiInlinePochodzenieTest` w suicie 4283, wcześniejszy odbiór przeglądarkowy |
| Sprzeczność ze zrzutu: „już dodany” równocześnie z ofertą nowego | spełnione | kod: `$pasujeDokladnie = $juzWybrany \|\| …` (linie 23–28 komponentu) |
| Zachowanie opisu, zdjęć i tagów po walidacji | spełnione lokalnie | testy ratowania danych w `TagiWpisowTest` |
| **Bez JS nadal da się oznaczyć wpis** | **było ZŁAMANE przez #701, naprawione lokalnie, NIE JEST na produkcji** | sonda renderu na `8a2ecb2a` + kontrola ujemna (wyżej) |
| Podstawowa ścieżka z JS bez osobnego szukania przyciskiem | spełnione | `<details>` z #701, test `test_sekcja_awaryjna_jest_zwinieta_gdy_nie_ma_czego_pokazac` |
| Mobile/desktop, oba motywy, skala 100/140, zoom 200, brak zasłaniania | spełnione lokalnie | 24/24 konfiguracje i 4/4 zoom 200% z wcześniejszego odbioru; NIE powtórzone na `8a2ecb2a` |
| Testy działań i autoryzacji liczników, kontrola ujemna źródła, review | spełnione | liczby wyżej |
| Podpowiedzi po `#` i licznik DZIAŁAJĄ NA PRODUKCJI | **nieudowodnione** | formularz i `/tagi/podpowiedzi` są za `auth` (anon: 302 → `/login`); logowanie na cudze konto zabronione |
| Krótka lista przypisanych tagów **przy opisie** (wymaganie z 18.09) | częściowo | dolna wyszukiwarka schowana ✔, ale lista tagów nadal stoi PO sekcji widoczności, nie przy opisie (`pages/posts/create.blade.php:154`) |

### Dwie uwagi, których świadomie nie naprawiono

1. Teksty wskazują na nieistniejący już widoczny element: pod opisem stoi
   „Tagi możesz też znaleźć poniżej.”, a JS mówi „…znajdź tag poniżej”
   i „…skorzystać z wyszukiwania tagów poniżej”. Po #701 „poniżej” jest
   zwiniętą sekcją o zupełnie innej etykiecie. To zmiana copy — należy do
   właściciela i `docs/brand/COPY_STYLE.md`, nie do wąskiej poprawki odbioru.
   Obecne na produkcji: oba zwroty są w `/build/assets/app-Tz-bEy9s.js`.
2. Lista przypisanych tagów nie została przeniesiona „przy opis”. To zmiana
   układu formularza, nie usterka scalonego kodu.

### Czego ten odbiór NIE dowodzi

- Niczego o zachowaniu popupu NA PRODUKCJI — cała ścieżka autora jest za
  logowaniem, a konto jest cudze. Produkcyjnie potwierdzono wyłącznie: SHA,
  CI, obecność skryptu w bundlu i publiczne liczniki `/tagi` (`ciasto` — 1,
  `sernik` — 0), które pochodzą z #369/#370, nie z popupu #647.
- Licznika DODATNIEGO w samym popupie. Dane do tego na produkcji JUŻ SĄ
  (tag `ciasto` ma 1 publiczny wpis, czego 18.09 jeszcze nie było), ale
  odczytać go może tylko zalogowany.
- Fizycznej klawiatury ekranowej i IME — ograniczenie znane od 17.09,
  nie zmienione.
- Macierzy 24/24 i zoomu 200% NA `8a2ecb2a` — te liczby pochodzą
  ze snapshotu sprzed #701, a #701 zmienił układ tej sekcji.
