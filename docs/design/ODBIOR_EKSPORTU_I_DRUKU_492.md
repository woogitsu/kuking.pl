# Odbiór paczki z danymi: rozpakowanie, wygląd offline i wydruk — #492

Zakres: **wiersz „ZIP eksportu: HTML/README, foto jeszcze przetwarzane, druk"**
z `docs/design/MACIERZ_KOMPLETNOSCI_517.md`. Nic poza nim.

Macierz mówiła o tym wierszu: odbiór lokalny przy 320/1440, **brak dowodu
rzeczywistego zoomu**, ograniczenie „odczyt pobranego HTML w Chromium nie
obejmuje wszystkich przeglądarek/offline czy klientów ZIP". Brak pomiaru nie
jest potwierdzonym błędem aplikacji — ten odbiór dokłada pomiar i naprawia
wyłącznie to, co pomiar pokazał.

Podstawa: `main` **2a17a5be38adf3d71fa9eab625f8d6830cf32a43** (Alfa 0.65).

---

## 1. Co zostało zmierzone, a nie założone

Archiwa powstawały **normalną drogą aplikacji**, nie renderem pojedynczego
Blade: `POST /ustawienia/twoje-dane/eksport` → kolejka → `GenerateUserExport`
→ adres pobrania **wyjęty z HTML-a ekranu ustawień**, nie zbudowany w teście
→ `GET` podpisanego adresu → bajty na dysk. Razem **siedem archiwów** w dwóch
niezależnych środowiskach:

| Wariant | Po co |
|---|---|
| puste konto | katalogi, których w paczce nie ma; pusty stan |
| konto pełne | 5 przepisów (w tym szkic, przepis bez zdjęcia, bardzo długi tytuł), wpisy, wykonania, cudze komentarze, zeszyt z cudzą treścią, 8 zdjęć |
| zdjęcia w drodze | ostrzeżenie o niekompletnej paczce (#113) |
| konto bez zdjęć | brak martwego odnośnika do `zdjecia/` |
| zdjęcie odrzucone | co paczka mówi o zdjęciu, które nie wejdzie NIGDY |
| długi przepis 15 kroków i 8 układów wydruku | podziały stron; to jedno archiwum obsługuje oba cele |

Zdjęcia były prawdziwymi plikami (GD), nie łańcuchami tekstu — inaczej
przeglądarka nie miałaby czego wyrenderować. Plików ZIP jest siedem:
pięć w środowisku badającym archiwum, dwa w środowisku badającym wygląd
i druk.

**Wszystkie pomiary wyglądu i druku na `file://`, bez serwera.** Ruch sieciowy
był blokowany i liczony: `offline: true`, `--host-resolver-rules=MAP * ~NOTFOUND`,
proxy w próżnię. Wynik na wszystkich przebiegach: **zero żądań poza `file://`,
zero żądań nieudanych.**

### Co przeszło bez zastrzeżeń

- **Rozpakowanie i integralność** — `unzip`, `python3 zipfile.testzip()`: brak
  uszkodzonych wpisów we wszystkich archiwach.
- **Odnośniki** — każdy `href` i `src` w każdym pliku HTML sprawdzony
  programowo (DOM-em, nie regexpem) pod kątem istnienia celu w archiwum.
  W archiwum pełnym: 28 odnośników, zero martwych.
- **Przeniesienie archiwum** — rozpakowanie w dwóch różnych ścieżkach, w tym
  `Moje Przepisy Żółć ĄĘ/druga kopia`: wynik identyczny.
- **Polskie znaki i długie nazwy** — treść po polsku renderuje się poprawnie,
  nazwy plików są slugowane do ASCII i przycinane (70 znaków dla stron
  przepisów, 60 dla zdjęć), z fallbackiem `przepis-<uuid>.html`.
- **Samodzielność** — żaden `href` ani `src` w archiwum nie wychodzi poza
  paczkę, zero `signature=`/`expires=`, zero `APP_KEY`, tokenów, ciasteczek
  sesji i adresów e-mail innych osób. Jedyny adres e-mail to adres
  właściciela konta (to jego dane) i adres kontaktowy serwisu.

  **Sprostowanie po niezależnym review.** Pierwsza wersja tego zdania mówiła
  „w całym archiwum zero adresów `http(s)://`" i to była nieprawda: pole
  „Skąd masz ten przepis" (`recipes.source_url`) jest zwykłym polem
  formularza i paczka wypisuje je jako TEKST — w `dane.json` i na stronie
  przepisu. Adres w treści niczego nie ściąga; ściąga dopiero odnośnik.
  Pilnujący tego test skanował na początku całą treść, więc **oblałby się
  u pierwszej osoby, która ten adres poda** — z komunikatem „paczka wymaga
  Kuking albo sieci" o zdaniu, które ta osoba sama napisała. Test pyta
  teraz o `href`/`src`, a scena testowa ma adres źródła wpisany celowo,
  z asercją, że on w paczce ZOSTAJE.
- **Escapowanie** — `<script>`, `"`, `&`, `<` wpisane w tytuł i treść
  wychodzą jako tekst; zero żywych tagów `<script>`, zero atrybutów `on*`.
- **Ekran** — 320/390/768/1440 px × jasny/ciemny, 8 stron: **128 przebiegów
  bez usterki** (64 Chromium + 64 Firefox). Zero poziomego przewijania, zero
  tekstu poniżej 18 px, wszystkie zdjęcia wczytane.
- **Rzeczywisty zoom 200 %** — `chrome.tabs.setZoom` przez tymczasowe
  rozszerzenie w usuwanym profilu, potwierdzone `chrome.tabs.getZoom() === 2`
  **oraz** `devicePixelRatio === 2` **oraz** `innerWidth` równym połowie okna.
  Okno 640 px → 320 CSS px i 1536 px → 768 CSS px, 6 stron: **12/12 bez
  usterki**. To jest dowód, którego macierz nie miała („brak Z").
- **Klawiatura** — Tab dochodzi do pierwszego odnośnika przepisu w jednym
  kroku, `:focus-visible` prawdziwe, obrys 3 px w obu motywach, Enter otwiera.
- **Kolory wobec `tokens.css`** — 13/13 wartości zgodnych w obu motywach
  (potwierdzone niezależnie w review). Kontrast tekstu 16,33:1 (jasny)
  i 16,47:1 (ciemny), fokusu 4,90:1 / 7,48:1, `.uwaga` 9,75:1,
  `.plakietka` 6,53:1. Odnośnik: **5,77:1 na białej karcie i 5,23:1 na tle
  strony** — oba przechodzą AA; pierwsza wersja raportu podawała tylko
  korzystniejszą z tych dwóch liczb.

---

## 2. Co zostało naprawione

Siedem potwierdzonych usterek. **Wszystkie są usterkami tekstu albo wyglądu.
Zakres danych eksportu, prywatność, retencja, podpisy i uprawnienia pobierania
pozostają bez zmian.**

### Teksty, które obiecywały więcej, niż paczka niesie

1. **`CZYTAJ-TO-NAJPIERW.txt` opisywał katalogi, których w paczce nie ma.**
   Na koncie bez przepisów archiwum ma cztery pliki (`unzip -l`), bo
   `ZipArchive` nie tworzy pustych katalogów — a README opisywał `przepisy/`
   i `zdjecia/` jako coś, co jest w środku. `index.html` tego samego archiwum
   mówił poprawnie „nie masz żadnego zdjęcia, **więc w tej paczce nie ma
   katalogu ze zdjęciami**". Ta sama usterka była już raz naprawiona
   w `index.html` (#113) i tam ma swój test; w README została.

2. **„To jest kopia wszystkiego, co masz w Kuking".** Zmierzone: cudzy przepis
   odłożony do zeszytu wychodzi w paczce jako `{tytul, autor, moja_notatka,
   zapisano}` — bez składników, kroków i zdjęć. Zdanie obiecywało pełne cudze
   przepisy. Nowe nazywa jedno ograniczenie, które naprawdę może zaskoczyć,
   zamiast wyliczać wszystko. Wypadło też słowo „notatki": w Kuking nie ma
   encji „notatka", więc udawało osobny rodzaj treści.

3. **„Wszystkie Twoje zdjęcia".** Zmierzone na koncie z czterema zdjęciami
   w bazie (1 `ready`, 2 `rejected`, 1 `deleted`, wszystkie z plikiem
   w storage): do paczki wchodzi jedno. Liczby („Zdjęć w paczce: 1") były
   prawdziwe — nieprawdziwe było słowo „wszystkie".

4. **`dane.json` mówił o sobie „Wszystkie treści tego konta".** Poza paczką
   zostają m.in. wcześniejsze wersje własnych przepisów (`recipe_versions`,
   zapisywane przez `SnapshotRecipeVersion` przy każdej publikacji),
   obserwowane tagi, dziennik zgód i tożsamości zewnętrzne. Do `czego_nie_zawiera`
   dołączyła też granica dotycząca cudzych przepisów w zeszycie — paczka
   stosowała ją od początku, ale nie mówiła o niej w żadnym swoim pliku.

### Wygląd i wydruk

5. **Cel dotknięcia 22 px na jedynej drodze do zdjęć.** Odnośnik „Otwórz
   katalog ze zdjęciami" jest osobnym akapitem, więc nie łapała go reguła
   48 px pisana dla `.spis` i `.powrot`. Po poprawce 49 px na wszystkich
   szerokościach; odnośnik w środku zdania celowo zostaje słowem.

6. **Krok przepisu rozerwany między kartki.** Zmierzone w PDF (`pdfimages
   -list`): tekst kroku 2 na stronie 2, **zdjęcie tego samego kroku na
   stronie 3** — przy garnku człowiek ma instrukcję na jednej kartce,
   a obrazek do niej na drugiej. Doszło `break-inside: avoid` dla kroków,
   składników, kart, ostrzeżeń i zdjęć, oraz `break-after: avoid` dla
   nagłówków (zmierzone: „Składniki" kończyło stronę 1, pierwszy składnik
   zaczynał stronę 2).

7. **Skan zajmował na wydruku około 24 cm i spychał resztę przepisu.**
   Zmierzone `pdfimages -list`: skan 1600 px szedł przy 168 ppi, czyli
   24,2 cm wysokości; po poprawce 255 ppi, czyli 15,9 cm — `max-height: 16cm`
   działa. Skutek na liczbie kartek zależy od tego, ile tekstu ma przepis:
   w niezależnym review przepis z długim skanem zszedł z **7 kartek do 4**.

   **Sprostowanie po review.** Pierwsza wersja tego punktu mówiła „przepis
   rósł z 5 kartek do 6". Tego nie da się odtworzyć z zachowanych PDF-ów:
   `pdfinfo` na `pdf-przed/rosol-z-tlem.pdf` i `pdf/rosol-z-tlem.pdf` daje
   **5 stron w obu**. Efekt jest realny i zmierzony wysokością zdjęcia —
   ta konkretna para liczb nie była.

Niespójności wyglądu naprawiono we wspólnym `styles.blade.php`, nie w każdym
pliku osobno.

---

## 3. Czego świadomie NIE zmieniono

- **Zwykły zapis „Kuking" w plikach eksportu jest wymagany wprost.**
  `AGENTS.md` §11: „**nigdy tam, gdzie koloru nie ma** — `alt`, `title`,
  `aria-label`, tytuł strony, `meta`, temat listu, **pliki eksportu**. Tam
  piszemy zwyczajnie »Kuking«". Cytat sprawdzony w źródle, także przez
  niezależne review.

  **Uściślenie po review.** O **znaku garnka** nie mówi ani §11, ani
  konstytucja — jego brak w paczce nie jest więc ani nakazany, ani zakazany,
  i zdanie „to jest poprawne" było w tej części opinią, nie cytatem.
  Konstytucja wciąga przy tym „pobraną paczkę danych" w zakres portu marki
  z paletą marki — i ten warunek paczka spełnia: 13/13 wartości kolorów
  zgodnych z `resources/css/tokens.css` w obu motywach. Decyzja, czy paczka
  ma dostać znak, należy do właściciela; ten pakiet jej nie podejmuje
  i niczego w tej sprawie nie zmienia.
- **Zakres danych eksportu.** Ani jedno pole nie zostało dodane ani usunięte.
- **Gałąź o zdjęciach w drodze** (`$photosStillProcessing`) — jest poprawna
  i ma własne testy (#113).
- **Odnośnik „spisu treści" w zdaniu stopki ma 20 px** — WCAG 2.2 AA 2.5.8
  wyłącza cele „inline" w zdaniu, a ta sama akcja ma 48 px na górze strony.
- **`← Wróć do spisu treści` drukuje się na papierze** i tam nic nie robi
  (49 px). To decyzja projektowa, nie zmierzona usterka.
- **`.uwaga` i `.plakietka` nie mają odpowiedników w ciemnym motywie**
  z `tokens.css` — zostaje jasne tło. Obejrzane, czytelne (kontrast 9,75:1).
  Do decyzji właściciela, nie do cichej zmiany.

---

## 4. Rzecz do osobnego issue

`app/Domain/Users/Exports/ExportPhotoPlan.php` (ll. 60–64) mówi o zdjęciu
odrzuconym: „**to jest inna wiadomość**. Zdjęcie odrzucone nie pojawi się
w niej NIGDY". Takiej wiadomości w paczce nie ma — `grep` po całym archiwum
konta z dwoma odrzuconymi zdjęciami nie znajduje ani jednego zdania
skierowanego do człowieka. Kod uznaje osobny komunikat za należny i nigdy go
nie napisano.

Napisanie go wymaga nowego pola w eksporcie, czyli decyzji o zakresie danych —
poza tym odbiorem. Ten pakiet ogranicza się do przestania obiecywać
„wszystkie". **Sugerowane osobne, wąskie issue.**

---

## 5. Środowiska i granice pomiaru

| Rzecz | Czym zmierzone |
|---|---|
| Silniki | **Chromium 151.0.7922.34** i **Firefox 155.0** (doinstalowany w trakcie). Playwright 1.63 |
| Wydruk | 16 PDF-ów (8 układów × `printBackground` false/true), A4, margines 10 mm; paginacja czytana `pdftotext`/`pdfimages` strona po stronie; obejrzano 11 wyrenderowanych stron. Niezależnie powtórzone w review: 7 → 4 kartki na przepisie z długim skanem |
| Zoom | rzeczywisty `chrome.tabs.setZoom`, potwierdzony trzema niezależnymi odczytami |
| Baza | wyłącznie `127.0.0.1:55439`, bazy `kuking_492a_tests`, `kuking_492b_tests`, `kuking_492c_tests` |
| Poczta | `MAIL_MAILER=array` / `log` — nic nie wyszło na zewnątrz |
| Konta | wyłącznie lokalne, demonstracyjne. **Żadnego eksportu prawdziwego użytkownika nie pobierano ani nie oglądano** |

### Czego ten odbiór NIE dowodzi

- **To nie jest test fizycznej drukarki.** Zmierzono PDF z Chromium i układ
  w `emulateMedia({media:'print'})`. Nie sprawdzono sterowników, skalowania
  „dopasuj do strony", trybu oszczędzania tuszu, druku dwustronnego ani
  silnika druku innej przeglądarki.
- **Safari i iOS nie są sprawdzone.** WebKita nie było w środowisku i nie
  udawano go innym silnikiem.
- **Telefon nie jest sprawdzony fizycznie.** 320/390 px to emulacja szerokości
  na silniku desktopowym.
- **Nie sprawdzono rozpakowania wbudowanym rozpakowywaczem Windows, 7-Zip ani
  macOS Archive Utility** — użyto `unzip` i `python3 zipfile`. Nie sprawdzono
  zachowania przy ścieżkach dłuższych niż 260 znaków (najdłuższa nazwa
  w archiwum: **91 znaków** z prefiksem katalogu, 82 bez; limity sluga to
  70/60). Pierwsza wersja raportu podawała 81.
- **Nie sprawdzono dużego archiwum** (setki MB, tysiące zdjęć, wielokrotne
  domykanie ZIP-a co `photo_flush_every`). Największe archiwum: **92 KB**
  (`492b-claude/pelna.zip`, 94 652 B). Pierwsza wersja raportu podawała
  27 KB — to był największy plik tylko jednego z dwóch środowisk.
- **Nie sprawdzono README w starym Notatniku Windows** — sygnaturę UTF-8 (BOM)
  i CRLF potwierdzono bajtowo, nie okiem.
- **Nie sprawdzono kolejki w osobnym procesie** przy `disk=r2_eksporty`.
- Nie dotykano uprawnień pobierania, podpisów ani retencji — pilnują ich
  istniejące testy (`DataExportTest`, `JedenAktywnyEksportNaKontoTest`,
  `Wyscigi/EksportDanychRaceTest`, `PaczkaDanychPrzezywaOsobneKontenderyTest`).

---

## 6. Co to znaczy dla macierzy

Wiersz „ZIP eksportu" ma teraz dowód rzeczywistego zoomu 200 %, dowód
korzystania offline z odciętą siecią i drugi silnik (Firefox). Zostaje
ograniczenie: **Safari, fizyczny telefon, fizyczna drukarka i klienty ZIP
spoza `unzip`/`zipfile`**.

**To nie jest zamknięcie #492 ani ogłoszenie pełnego portu marki.** To jest
domknięcie jednego wiersza macierzy.

---

## 7. Co zmieniło niezależne review

Pakiet przeszedł przez recenzenta pracującego na osobnej kopii, osobnej bazie
i własnych archiwach. **Werdykt: do scalenia po poprawkach** — i poprawki
zostały zrobione. Recenzja znalazła rzeczy, których pomiar autorów nie złapał:

1. **Test „paczka nie odwołuje się do serwera" był miną.** Skanował całą treść
   wzorcem `https?://`, a `recipes.source_url` to zwykłe pole formularza, które
   paczka wypisuje jako tekst. Test przechodził tylko dlatego, że scena była
   uboższa od prawdziwego konta. Teraz pyta o `href`/`src`, a scena ma adres
   źródła wpisany celowo — z asercją, że on w paczce zostaje.
2. **`index.html` dalej obiecywał komplet** — „Wszystkie dane w formacie, który
   zrozumie inny serwis" stało dwa ekrany pod zdaniem, które właśnie przestało
   obiecywać komplet, i opisywało plik, którego własne `co_zawiera` też
   przestało. Paczka przeczyła sama sobie.
3. **„DOBRA RADA" w README dalej odsyłała do katalogu `przepisy`**, którego na
   pustym koncie w paczce nie ma — dwie sekcje po zdaniu „Tego katalogu w tej
   paczce NIE MA".
4. **Pułapka 3b: jedna asercja zaspokajała dwie gałęzie.** Skasowanie CAŁEJ
   gałęzi o zdjęciach przechodziło na zielono, bo napis „Tego katalogu w tej
   paczce NIE MA" znajdował się przy przepisach. Asercje są teraz rozdzielone
   na wycinki opisu każdego katalogu; kontrola ujemna po poprawce oblewa.
5. **Pomocnik `EksportWygladStylPaczki` obiecywał w docblocku więcej, niż
   robi** — bierze regułę późniejszą w pliku, nie tę o wyższej wadze, i nie
   widzi `display: none`. Docblock mówi o tym teraz wprost i wskazuje, gdzie
   leży druga połowa dowodu.
6. **Dowody przeglądarkowe były cytowane spoza repozytorium.** Kompaktowy
   zestaw (`ekran.json`, `zoom.json`, `druk.json`, `klawiatura.json`,
   `kontrola-ujemna.log`, dwa zrzuty) leży teraz w
   `docs/design/evidence/eksport492/`.
7. **Trzy liczby w tym raporcie były nieprawdziwe** — sprostowane w tekście
   wyżej wraz z powodem: „zero adresów `http(s)://`", „27 KB", „81 znaków",
   „5 → 6 kartek" i podana jednostronnie wartość kontrastu odnośnika.
   Twierdzenie o znaku garnka zostało uściślone: cytat z §11 jest prawdziwy,
   ale o samym znaku nie mówi ani reguła, ani konstytucja.

Uzasadnienia reguł CSS przeniesiono przy okazji z komentarzy `/* */` do
komentarza Blade: arkusz jedzie w całości do **każdego** pliku HTML paczki,
więc komentarz deweloperski dokładał ~2,5 KB do każdego z nich. Komentarz
Blade zostaje w repozytorium i do paczki nie trafia.

### Czego review nie potwierdziło własnym pomiarem

Firefox i rzeczywisty zoom 200 % recenzent przyjął z zapisanych dowodów
(spójnych wewnętrznie), nie z własnego przebiegu — mierzył wyłącznie
Chromium. Pozostałe granice z §5 potwierdził jako uczciwie zadeklarowane.

### Co review zostawiło jako otwarte, świadomie nietknięte

Puste konto dostaje w `index.html` zdanie o cudzych przepisach w zeszycie,
choć zeszytu nie ma; zdanie „tytuł i autor" zaniża zakres (pól są cztery:
tytuł, autor, notatka, data zapisania); wydruk przepisu kończy się pustą
kartką — usterka starsza od tego pakietu i przez niego nietknięta.
