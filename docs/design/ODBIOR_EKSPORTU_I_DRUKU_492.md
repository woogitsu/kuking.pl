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
| długi przepis 15 kroków | podziały stron na wydruku |
| konto demonstracyjne do druku | 8 różnych układów wydruku |

Zdjęcia były prawdziwymi plikami (GD), nie łańcuchami tekstu — inaczej
przeglądarka nie miałaby czego wyrenderować.

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
- **Samodzielność** — w całym archiwum zero adresów `http(s)://`, zero
  `signature=`/`expires=`, zero `APP_KEY`, tokenów, ciasteczek sesji
  i adresów e-mail innych osób. Jedyny adres e-mail to adres właściciela
  konta (to jego dane) i adres kontaktowy serwisu.
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
- **Kolory wobec `tokens.css`** — zgodne co do wartości w obu motywach;
  kontrast tekstu 16,33:1 (jasny) i 16,47:1 (ciemny), odnośników 5,77:1,
  fokusu 4,9:1 / 7,48:1.

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

7. **Skan zajmował całą kartkę i dokładał stronę.** Skan 1200 × 1600 schodził
   na wydruku do około 24 cm, więc przepis rósł z 5 kartek do 6, a jedna
   zostawała zapełniona w kilkunastu procentach. `max-height: 16cm` **tylko
   w druku**; ekran bez zmian.

Niespójności wyglądu naprawiono we wspólnym `styles.blade.php`, nie w każdym
pliku osobno.

---

## 3. Czego świadomie NIE zmieniono

- **Nazwa „Kuking" bez znaku garnka i bez dwukolorowego zapisu — to jest
  poprawne.** `AGENTS.md` §11: „**nigdy tam, gdzie koloru nie ma** — `alt`,
  `title`, `aria-label`, tytuł strony, `meta`, temat listu, **pliki eksportu**.
  Tam piszemy zwyczajnie »Kuking«". Konstytucja marki mówi to samo o tekście
  bez formatowania. Reguła i konstytucja się nie rozjeżdżają, więc nie ma tu
  pytania do właściciela ani powodu do zmiany.
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
| Wydruk | 16 PDF-ów (8 układów × `printBackground` false/true), A4, margines 10 mm; paginacja czytana `pdftotext`/`pdfimages` strona po stronie; obejrzano 11 wyrenderowanych stron |
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
  w archiwum: 81 znaków, limity sluga to 70/60).
- **Nie sprawdzono dużego archiwum** (setki MB, tysiące zdjęć, wielokrotne
  domykanie ZIP-a co `photo_flush_every`). Największe archiwum: 27 KB.
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
