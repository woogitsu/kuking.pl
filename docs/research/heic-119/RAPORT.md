# HEIC #119 — pomiar 20 września 2026

Status: pomiar lokalny i próba jednego rzeczywistego telefonu wykonane. Brak danych 30 dni i innych wersji iOS; #119 pozostaje otwarte. Nie wdrożono obsługi HEIC i nie zrewidowano D-064.
Baza kodu: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź `gpt/heic-format`.
Przed pierwszym pomiarem `git status --short` był pusty. Kod aplikacji nie został zmieniony.
Własna baza: `kuking_flota_gpt-heic-format`, PostgreSQL `127.0.0.1:55439`, użytkownik `kuking`.
Nie odczytywano produkcji ani danych produkcyjnych.

## 1. Co naprawdę postanawia D-064

Decyzja z 10.09.2026: serwer odrzuca HEIC/HEIF, podaje instrukcję i zapisuje
`photo_upload_failed / heic_unsupported`. Nie dokłada libheif/Imagick.
Rewizja wymaga danych o skali, rzeczywistego iPhone'a i oceny konwersji po stronie
klienta przed rozważeniem dekodera serwerowego. Bieżąca treść
[issue #119](https://github.com/woogitsu/kuking.pl/issues/119), odczytana przez
`gh issue view 119 --repo woogitsu/kuking.pl`, wymaga 30 dni danych Z MIANOWNIKIEM
oraz kilku wersji iOS/Safari. Jedno urządzenie nie zamyka tych kryteriów.

Podstawa decyzji jest mieszana:

- **[pomiar cudzy: D-064 §1]** PHP 8.4.19/GD bez obsługi HEIC w środowisku agenta.
  To nie był pomiar działającego obrazu produkcyjnego.
- **[odczyt kodu w tej sesji]** `RozpoznanieZdjecia`, `ObslugiwaneZdjecie`,
  `StoreUploadedImage`, `ProcessUploadedImage`, `PodgladOdRazu` i konfiguracja
  nadal realizują odmowę i obróbkę obsługiwanych obrazów przez GD.
- **[szacunek cudzy: D-064 §4]** około 9,2 MB z metadanych pakietów oraz 10–30 s
  dodatkowego builda na etap. Nie były to pomiary zbudowanego obrazu.
- **[hipoteza, nie pomiar]** automatyczna konwersja w Safari zależna od `accept`.
  D-064 jawnie przyznaje brak fizycznego iPhone'a. Również założenie, że telefon
  poradzi sobie z WASM, bo sam wykonuje zdjęcie, nie dowodzi wydajności dekodera
  programowego w przeglądarce: aparat może używać innej, sprzętowej ścieżki.
- Nie ma w D-064 pomiaru czasu/RSS dekodowania HEIC ani odsetka utraconych
  publikacji. Liczby RSS dla JPEG/GD nie są kosztem HEIC.

## 2. Pełne próbki, nie JPEG ze zmienioną nazwą

Źródło: publiczne fixture projektu [osxphotos](https://github.com/RhetTbull/osxphotos),
commit `b99f58636f83d0a4012217731d98fc98d4b7ed9f`. Pochodzenie aparatu odczytano
z metadanych; nie obserwowano wykonywania tych zdjęć przez ich autorów.
Pliki nie były przekodowywane ani modyfikowane przed próbą uploadu.
Nie dodano ich do repozytorium Kuking; odtworzenie przez wskazane adresy i SHA-256.

| Próbka lokalna | Ścieżka w repozytorium źródłowym | Bajty | Metadane |
|---|---|---:|---|
| `original.heic` | `tests/Test-10.15.6.photoslibrary/originals/7/7783E8E6-9CAC-40F3-BE22-81FB7051C266.heic` | 1877314 | Apple iPhone 6s, 3024×4032, EXIF Orientation 1 |
| `live.heic` | `tests/Test-Live-15.7.2.photoslibrary/originals/C/C3090F66-942C-41D3-BEC7-4F2B4876A109.heic` | 1379953 | Apple iPhone 15 Pro, 4032×3024, EXIF obrót 90° CW, transformacja kontenera 90° CW |
| `live.mov` | `tests/Test-Live-15.7.2.photoslibrary/originals/C/C3090F66-942C-41D3-BEC7-4F2B4876A109_3.mov` | 4215771 | Apple iPhone 15 Pro, 1920×1440, obrót 90°, identyfikator pary zgodny ze zdjęciem |

SHA-256:

```text
original.heic 0395929943e263e06e42d382b0bf01f3127a33dba3620d435ec904418dafb1a9
live.heic     abcc6aeb1031596b62297d4302b532edbd6d6ad4f9fdd76b783d7a83b954b5ac
live.mov      0f3a840ee174c127a463ad59b55123a88d66392202dcc3be0e6aca9f2f56b5fd
```

**[pomiar własny]** ExifTool (commit `2200871d9cef988051d2a99d67df3bda6cbb30a8`)
odczytał powyższe metadane. Osobny dekoder badawczy Pillow 12.3.0 + pillow-heif
1.7.0 na Windows załadował pełne piksele obu plików: HEIF, RGB, po jednym obrazie,
3024×4032 po uwzględnieniu obrotu. To potwierdza pełny obraz, nie sam nagłówek
`ftyp`. Te narzędzia zainstalowano tylko w ignorowanym katalogu `.codex/heic-119`;
nie są zależnością aplikacji i ich działanie nie jest wynikiem pipeline'u Kuking.

**Live Photo i orientacja są dwiema osobnymi obserwacjami na tej samej próbce.**
Nie udajemy, że są to dwa niezależne modele telefonów. Para Live Photo zawiera
obraz i osobny film o zgodnym identyfikatorze; Apple opisuje osobny zasób
[pairedVideo](https://developer.apple.com/documentation/photos/phassetresourcetype/pairedvideo).
Nie wolno zakładać, że każde Live Photo jest jednym szczególnym kontenerem HEIC
ani że HTML-owy wybór zdjęcia wyśle oba zasoby.

## 3. Pomiar niezmienionej aplikacji

**[pomiar własny]** Istniejące `ObiecujemyTylkoFormatyKtoreUmiemyTest`:
6 testów, 198 asercji, zielone przed zmianami. Ich fixture to syntetyczny nagłówek
z zerami. Dobrze sprawdzają odmowę, ale nie dekodowanie pełnego HEIC z telefonu.

**[pomiar własny]** `HeicMeasurementTest.php` obok tego raportu, ręcznie uruchamiana
sonda HTTP Laravel na PostgreSQL; 1 test, 9 asercji kontrolnych, wyniki wypisane
jako JSON. Nie jest testem Safari ani nowym kontraktem produktowym w CI.
Pierwsze dwie próby sondy miały błąd samego odczytu sesji (`get()` na tablicy),
naprawiony przez normalizację worka błędów w `assertSessionHasErrors`.
To nie były regresje aplikacji ani wymagana czerwień poprawki — poprawki aplikacji
w tym zadaniu nie wprowadzono.

| Wejście | MIME wykryty z bajtów | `getimagesize` | Wynik POST | Powód sygnału | Przyrost media / wpisów |
|---|---|---|---|---|---|
| HEIC iPhone 6s | image/heic | false | 302 do formularza, błąd `photos.0` | heic_unsupported | 0 / 0 |
| HEIC z Live Photo, EXIF 90° | image/heic | false | 302 do formularza, błąd `photos.0` | heic_unsupported | 0 / 0 |
| film z tej samej pary | video/quicktime | false | 302 do formularza, błąd `photos.0` | not_an_image | 0 / 0 |

Dla każdej próby HTML ponownie otwartego formularza zawierał wpisany tekst
„Pomiar HEIC 119” i zaznaczone `private`. Dwa HEIC wygenerowały po jednym sygnale. Sonda potwierdziła też zero zapisanych plików na dysku testowym i zero zleconych ProcessUploadedImage.

Droga pliku:

1. **Wybór:** `accept` z `LimityZdjec` oferuje JPEG, PNG, WebP, AVIF. To filtr
   wyboru, nie walidacja i nie dowód automatycznej konwersji Safari.
2. **Podgląd PRZED wysyłką:** `resources/js/app.js` tworzy `<img>` z Object URL,
   jeśli `File.type` zaczyna się od `image/`. Nie dekoduje HEIC samodzielnie;
   brak obsługi zdarzenia `error`. Wygląd w Safari wymaga próby telefonu.
3. **Walidacja:** rozpoznanie z bajtów zwraca `heic_unsupported`; tekst wraca.
4. **Przetwarzanie:** HEIC nie dociera do zapisu `Media`, `PodgladOdRazu`
   ani do `ProcessUploadedImage`. Wariantów thumb/feed/large/podglad nie ma.
5. **Karta:** dla odrzuconego HEIC wpis nie powstaje, więc nie powstaje też karta
   ze zdjęciem. Nie jest to dowód poprawności obrotu — obrót nie został wykonany.

Podgląd przed publikacją i serwerowy wariant `podglad` to różne rzeczy.
Samo dodanie dekodera do workera nie obsłuży walidacji ani synchronicznego podglądu.

## 4. Próba rzeczywistego telefonu — wykonana

Urządzenie zgłoszone przez właściciela: iPhone 17 Pro Max, iOS 27.
**[informacja właściciela, nie własny odczyt urządzenia]**.
Instancja lokalna, bez Turnstile i bez wysyłki poczty; rzeczywiste kontrolery,
walidacja, CSRF i uprawnienia aplikacji. Własna baza wskazana na początku raportu.
Dla próby kolejka pracuje synchronicznie: wynik nie jest pomiarem opóźnienia
produkcyjnego workera ani współbieżności. Router pomiarowy zapisuje wyłącznie
czas, MIME wykryty na serwerze, liczbę bajtów i wymiary, bez nazw plików i EXIF.

| Próba | Czas UTC | Co odebrał serwer — pomiar własny | Wynik |
|---|---|---|---|
| Kontrola JPEG/PNG wybrana ze Zdjęć | 18:38:36 | JPEG, 106305 B, 876×1920, bez EXIF Orientation | media ready, wpis prywatny opublikowany, cztery warianty |
| Zwykłe HEIF wybrane ze Zdjęć | 18:42:37 | JPEG, 3761919 B, 5712×4284, EXIF Orientation 6 | media ready, obrót zastosowany, cztery pionowe warianty |
| Live Photo wybrane ze Zdjęć | 18:44:29 | JPEG, 4311863 B, 5712×4284, EXIF Orientation 6; jeden plik | media ready, obrót zastosowany, cztery warianty |
| Plik .HEIC wybrany przez Pliki | 18:48:57 | JPEG, 4829560 B, 5712×4284, EXIF Orientation 6 | media ready, obrót zastosowany, cztery warianty |
| Aparat uruchomiony z formularza Safari | 18:49:48 | JPEG, 2381909 B, 4032×3024, EXIF Orientation 6 | media ready, obrót zastosowany, cztery warianty |

**[pomiar cudzy: właściciel wykonujący próbę na iPhonie w tej sesji]** Dla
kontroli JPEG/PNG potwierdzono podgląd i publikację. Dla drugiej próby właściciel
potwierdził HEIF w informacjach Zdjęć oraz poprawny podgląd i obrót po publikacji.
To jest potwierdzenie konwersji HEIF → JPEG na tej ścieżce i tym urządzeniu,
nie dowód, że każde Safari i każda ścieżka wyboru zachowuje się tak samo.
Serwer nie widział oryginalnych bajtów HEIF uploadów z telefonu; format przed
wyborem jest informacją od właściciela, format po wysyłce własnym pomiarem.

**[pomiar własny]** Dla drugiej próby warianty miały: podglad 480×640,
thumb 240×320, feed 720×960, large 1200×1600. ExifTool odczytał pliki jako
WebP bez tagów EXIF. Jest to kontrola obrotu JPEG odebranego od Safari, nie
serwerowego obrotu HEIC. W bazie `exif_orientation=6`, `orientation_applied=true`.

Właściciel potwierdził dla trzeciej próby oznaczenia LIVE + HEIF i poprawny wygląd podglądu oraz opublikowanego zdjęcia [pomiar cudzy: odpowiedź w tej sesji]. Właściciel potwierdził także wybór pliku .HEIC przez Pliki i poprawny wynik końcowych prób („heic, było ok”). To informacja o wyborze po stronie telefonu; nie mamy kopii oryginalnych bajtów HEIC z tego urządzenia. Serwer niezależnie wykazał JPEG w obu końcowych żądaniach. Nie zmieniano ustawień firewalla ani nie wystawiano instancji przez publiczny tunel.

## 5. Uczciwa odmowa: co już jest, czego brakuje

Odmowa z własnym kodem powodu już działa. Pozostaje sprawdzić, czy człowiek potrafi
z niej wyjść z TYM zdjęciem, które chciał pokazać. Zmiana formatu aparatu dotyczy
przyszłych fotografii, nie przerabia starej.

**[odczyt kodu i źródła]** Obecny komunikat bezwarunkowo obiecuje, że wysłanie
sobie zdjęcia e-mailem da JPG. [Apple](https://support.apple.com/pl-pl/116944)
opisuje konwersję jako zależną od sposobu udostępniania i możliwości odbiorcy,
nie gwarancję. Ta obietnica wymaga korekty; nie rozstrzygamy skuteczności obejścia
samą asercją na tekście. Polska dokumentacja Apple podaje też etykietę
„Najbardziej zgodne”, podczas gdy aplikacja mówi „Najbardziej zgodny”.
Brzmienie na testowanym iOS powinien potwierdzić właściciel.

Wariant odmowy do dopracowania: krótka informacja przy pliku, instrukcja wyboru
istniejącego JPG oraz osobna pomoc „Ustawienia → Aparat → Formaty → Najbardziej
zgodne” dla przyszłych zdjęć. Dla istniejącego zdjęcia potrzebna jest sprawdzona
na telefonie ścieżka eksportu/konwersji. Nie zalecamy jako pewnika Mail, Duplikuj
ani zewnętrznego konwertera. Tekst końcowy według COPY_STYLE/GLOS_MARKI, z testem
regresyjnym czerwonym przed zmianą i próbą przez człowieka 50+.

## 6. Koszt wariantów — estymacja, nie zmierzony cennik

| Wariant | Zakres | Nakład orientacyjny jednej osoby | Stały koszt |
|---|---|---|---|
| Uczciwa odmowa | korekta obietnicy, obsługa braku podglądu, sprawdzone obejście, test zachowania tekstu i wielu zdjęć | 1–2 dni + sesje użytkowników | brak nowego kodeka; utrzymanie instrukcji iOS |
| Próba konwersji w przeglądarce | wybór utrzymywanego WASM, ładowanie dopiero w razie potrzeby, Web Worker, limity, anulowanie, awaria, współpraca z `accept` i Livewire | 2–4 dni prototypu/pomiaru; kolejne 3–6 dni na wdrożenie po pozytywnym wyniku | transfer dekodera, CPU/RAM/bateria telefonu, śledzenie łatek |
| Obsługa serwerowa nieruchomej klatki | libheif/Imagick i izolacja dekodowania, limity, walidacja, GPS, obrót, podgląd, warianty, sprzątanie, kontener i regresje | 2–4 dni benchmarku; kolejne 5–10 dni wdrożenia i weryfikacji | CPU/RSS workerów, aktualizacje kodeków, większy obraz |

To widełki planistyczne przy dostępnym stanowisku i próbkach, nie zobowiązanie
ani wynik benchmarku. Nie obejmują odtwarzania filmów Live Photo: to odrębna
funkcja produktu. Dla Kuking trzeba wybrać, czy „obsługa Live Photo” oznacza
nieruchomą klatkę główną; nie można cicho obiecać animacji.

Obsłużyć naprawdę wymaga również:

- sprawdzania wymiarów i liczby obrazów przed kosztownym dekodowaniem, limitów
  pamięci, czasu, liczby wątków, obrazu głównego i dodatkowych warstw;
- wspólnej interpretacji EXIF oraz transformacji HEIF (`irot`/`imir`), bez
  podwójnego obrotu, oraz wyboru zachowania HDR i profili barwnych;
- usunięcia GPS przed trwałym zapisem: obecne `UsunGps` nie staje się obsługą
  HEIF tylko dlatego, że worker dostanie nowy sterownik;
- kontroli obrazów 12/24/48 MP, uszkodzonych i uciętych, podglądu, wszystkich
  wariantów i karty; także awarii i sprzątania plików tymczasowych;
- pomiaru całego procesu (w tym dekodowania synchronicznego dla podglądu), a nie
  wyłącznie `memory_get_peak_usage` PHP.

WASM również używa kodeka i wymaga łatek. Aktualny opis
[libheif](https://github.com/strukturag/libheif) wskazuje zależności kodekowe,
obsługę wielu obrazów/transformacji oraz ograniczone zasoby utrzymania projektu.
Nie jest to dowód, że biblioteka jest nieutrzymywana, ale jest koszt utrzymania,
którego nie usuwa opakowanie JavaScript.

Przed decyzją serwerową: dwa buildy na tym samym przypiętym obrazie bazowym
(bez/z dekoderem), rozmiar warstw i czas zimnego/ciepłego builda; po 20 prób na
12/24/48 MP, mediana i p95 czasu, peak RSS całego procesu (`/usr/bin/time -v`),
pamięć kontenera, równolegle 1/2/4 zadania i konkurencja podglądów w web.
Nie podajemy wyniku tych pomiarów, bo ich nie wykonano. Demon Dockera odpowiada
w tej sesji (29.7.2); historyczny brak Dockera z D-064 nie jest dzisiejszą blokadą.

## 7. Dokładny plan 30 dni — do uruchomienia przez właściciela

Nie wykonano żadnego zapytania na produkcji.
Już dziś można liczyć udział HEIC W SYGNAŁACH PORAŻEK, w zamkniętym oknie UTC:

```sql
SELECT
  count(*) AS failure_signals,
  count(*) FILTER (WHERE properties->>'reason' = 'heic_unsupported') AS heic_signals,
  count(DISTINCT user_id) FILTER
    (WHERE properties->>'reason' = 'heic_unsupported') AS affected_known_users,
  round(100.0 * count(*) FILTER (WHERE properties->>'reason' = 'heic_unsupported')
    / NULLIF(count(*), 0), 2) AS heic_share_of_failure_signals
FROM product_signals
WHERE signal_name = 'photo_upload_failed'
  AND occurred_at >= :start_utc AND occurred_at < :end_utc;
```

`:start_utc` i `:end_utc` wyznaczają pełne 30 dni. Dodatkowo grupa dzienna i rozkład
`properties->>'reason'`. `NULL` procentu przy braku danych oznacza brak mianownika,
nie 0%. `count(*)` liczy zdarzenia, nie osoby ani pojedyncze formularze.
Przy wielu zdjęciach żądanie może dać kilka sygnałów; limit 429 może dać sygnał
bez pliku. Nie dzielić tego bezrefleksyjnie przez liczbę wierszy `media`.
HEIC zbyt duży odpada wcześniej jako `too_large`; brak wyboru pliku, przerwane
połączenie i konwersja do JPG w Safari nie pojawią się jako `heic_unsupported`.

**Brakuje mianownika wszystkich prób i oznaczenia pierwszej publikacji.**
Samo odczekanie 30 dni nie stworzy tych danych. Proponowana, jeszcze niewdrożona
instrumentacja, wymagająca decyzji i osobnego zadania:

1. Ustalić jednostkę: próba jednego pliku; osobno próba publikacji całego
   formularza. Ponowne wysłanie liczyć jako ponowną próbę, wewnętrzne przejście
   przez walidację i akcję domenową tylko raz.
2. Na granicy przyjęcia uploadu (zwykłe kontrolery i endpoint tymczasowy Livewire)
   rejestrować `upload_attempt_id`, `flow` z zamkniętej listy
   `post/recipe/cooked/avatar/question`, oraz wynik. Każdy plik ma jeden identyfikator
   przenoszony do dalszych etapów, również przez Livewire. Nie liczyć `media_ids`
   odzyskanych po walidacji jako nowego uploadu.
3. Przed pierwszą próbą dodać serwerowy znacznik `first_publication` (brak
   wcześniejszej opublikowanej treści według ustalonej definicji), powiązać z
   krótkotrwałym `publication_attempt_id`. Zakończenie `PublishPost`/`PublishRecipe`
   /`RecordCookedEvent` daje wynik publikacji. Zdefiniować, czy szkic i „Tylko ja”
   wchodzą w tę definicję; to decyzja właściciela, nie arbitralny test.
4. Minimalne dane: czas, ID próby, flow, sukces/odmowa, zamknięty kod powodu,
   kategoria formatu rzeczywiście odebranego, first_publication. Bez nazwy pliku,
   EXIF/GPS, hasha zdjęcia, treści wpisu, pełnego User-Agent i fingerprintingu.
   Rezygnację przed wysłaniem badać sesją użytkownika; serwer jej nie widzi.
5. Po 30 pełnych dniach od uruchomienia porównać: HEIC / wszystkie próby plików,
   HEIC / odmowy plików, osoby z HEIC / osoby próbujące pierwszej publikacji,
   odzyskanie publikacji w tej samej próbie/sesji po HEIC. Ostatnia metryka wymaga
   jawnej definicji okna i powiązania prób; sam `user_id` nie dowodzi przyczyny
   porzucenia ani „tej samej sesji”. Uzupełnić o obserwowane próby 50+.
6. Zachować retencję 90 dni i istniejące reguły anonimizacji. Poszerzenie zamkniętego
   słownika sygnałów to migracja + test + `docs/DATABASE.md` + rollback. Właściciel
   powinien dostać konkretne zadanie wdrożeniowe; to nie istniejący przełącznik,
   który wystarczy włączyć w panelu.

Próg „regularne” nie ma w D-064 wartości liczbowej. Właściciel wybiera próg
akceptowalnej utraty pierwszych publikacji; raport ma pokazać licznik, mianownik,
niepewność i przykłady blokad, bez ustanawiania tego progu testem.

## 8. Odtworzenie pomiaru serwera

Pobrać trzy pliki spod wskazanego commita do `.codex/heic-119/` z nazwami z tabeli,
sprawdzić SHA-256, a następnie w Git Bash:

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-heic-format
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-heic-format docs/research/heic-119/HeicMeasurementTest.php
```

**Nie uruchamiać testów ani synchronizacji runtime podczas próby telefonu**:
korzystają z tej samej bazy i plików. Sonda przed uruchomieniem RefreshDatabase wymaga jawnej własnej bazy i portu. Wymaga pełnych plików; brak fixture
oblewa się jawnie, nie daje pustej zieleni. Assercje techniczne nie stanowią
zgody na utrwalenie odmowy w przyszłości.

## 9. Ograniczenia i decyzje

- D-064 nie zmieniono. Nie dodano zależności aplikacji, migracji ani obsługi HEIC.
- Nie wykonano push, PR, wiadomości do innych osób ani odczytu produkcji.
- Dane z 30 dni, kilka rzeczywistych wersji iOS i sesje 50+ pozostają wymagane
  do pełnego zamknięcia #119.
- Do decyzji: próg utraty publikacji, zakres klatka/animacja Live Photo,
  instrumentacja mianowników, ewentualny prototyp WASM i dopiero potem dekoder
  serwerowy. Korekta niepopartej gwarancji Mail nie wymaga odwracania D-064.
## 10. Kontrole wykonania

- Baza niezmienionego drzewa: `ObiecujemyTylkoFormatyKtoreUmiemyTest` — 6/6,
  198 asercji.
- Pełne fixture: ręczna sonda HTTP — 1/1, 9 asercji technicznych; szczegóły §3.
- `npm run build` — zaliczone; 20 testów JS i 72 pary kontrastu, Vite zbudował assety.
- `vendor/bin/pint --test` na runtime przed dodaniem sondy do dokumentacji —
  zaliczone, 1155 plików. Sonda dodatkowo sformatowana `vendor/bin/pint`.
- Przeglądarka desktop: lokalne logowanie i formularz otwarte; próba ustawienia
  pliku narzędziem przeglądarki zakończyła się timeoutem selektora pliku, więc
  nie raportujemy desktopowego podglądu HEIC jako sprawdzonego.
- Testy na telefonie wykonuje właściciel; własnym dowodem serwera są MIME,
  wymiary, statusy, warianty i orientacja. Nie wykonywano zdalnego sterowania
  telefonem i nie wnioskowano o widocznym obrazie tylko ze statusu `ready`.
- Po zakończeniu prób wyłączono lokalny serwer i przekierowanie; porty 8118/8119
  nie nasłuchują. Adres próby telefonu przestał działać.
- Pełny zestaw na własnym PostgreSQL: 4393 testy zaliczone, 83692 asercje,
  438,14 s. Wykluczono wyłącznie ProbaOdtworzeniaTest zgodnie z jawnym
  wyjątkiem właściciela (współdzielona baza kuking_zrodlo_proby_glowny).
  Uruchomienie: testuj.sh gpt-heic-format --compact --filter '^(?!.*ProbaOdtworzeniaTest)'.
