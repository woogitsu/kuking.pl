# Pomiar lokalny DR zdjęć — 20.09.2026

Źródło: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź `gpt/dr-zdjecia`.
Wszystkie wyniki tej próby są własne. Produkcji ani Cloudflare nie badano.
Procedura i decyzje do podjęcia: [DR_ZDJEC_617_602.md](../../DR_ZDJEC_617_602.md).

## Nietknięte drzewo

`testuj.sh gpt-dr-zdjecia --filter OryginalTraci`: **18 PASS, 63 asercje**,
2,29 s. PostgreSQL 127.0.0.1:55439, własna baza `kuking_flota_gpt-dr-zdjecia`,
użytkownik `kuking`. Pomiar nie korzysta z SQLite.

Nieudane uruchomienia oprzyrządowania: pierwsze wyprzedziło zakończenie
kopiowania `vendor`, drugie miało źle przekazany znak `|` w filtrze powłoki.
Oba powtórzono po zakończeniu przygotowania, filtrem bez alternatywy.
Nie są ani regresją aplikacji, ani dowodem jej sprawności.

## Przed ustawieniem retencji — czerwień

Własny MinIO, bucket utworzony `--with-lock`, lecz bez domyślnego okresu
retencji. Test wykonał **27 asercji** i oblał:

```text
BRAK_ODMOWY: pisarz kasuje chronioną wersję
```

Token pisarza wcześniej utworzył i skutecznie skasował obiekt kontrolny.
Tą samą tożsamością dało się usunąć konkretną wersję kopii. To czerwień
z właściwego powodu, zobaczona **przed** dodaniem konfiguracji retencji.
Kontener po próbie został usunięty.

Wcześniejsza próba fixture miała niepoprawną nazwę użytkownika z łącznikiem
i zatrzymała się na CHECK bazy — nie zaliczono jej do kontroli ujemnych.
Pierwsze sprawdzenie po włączeniu ochrony zakładało status 403; rzeczywisty
MinIO zwrócił **400 InvalidRequest: Object is WORM protected and cannot be
overwritten**. Test rozróżnia teraz dokładny kod/komunikat WORM od 403
AccessDenied i od dowolnej awarii API. Nie akceptuje „jakiegokolwiek błędu”.

## Po ustawieniu retencji

MinIO `RELEASE.2025-09-07T16-13-09Z`, digest zapisany w przyrządzie.
Domyślna retencja testowego bucketu: **COMPLIANCE, 1 dzień**.
To ustawienie do lokalnego pomiaru, nie wybór retencji produkcyjnej.

W pierwszym kompletnym zielonym przebiegu:

| Miara | Wynik |
|---|---:|
| zdjęcia utworzone przez StoreUploadedImage i ProcessUploadedImage | 3 |
| oryginały + podgląd/thumb/feed/large | 15 obiektów |
| rozmiar zestawu | 33 223 B |
| faktycznie usunięte źródła, nie tylko wpisy w bazie | 15/15 |
| odzyskane rozmiary i sumy SHA-256 | 15/15 |
| obrazy odczytane przez dekoder po odzyskaniu | 15/15 |
| strony wpisów po odzyskaniu | 3/3 HTTP 200 |
| trasy wariantów → podpisany URL → rzeczywisty GET MinIO | 12/12 HTTP 200 i poprawny obraz |
| przygotowanie + kopia i próby ochrony | 0,589 s |
| wiek zakończonej kopii w chwili utraty źródeł | 0,053 s |
| odtworzenie + weryfikacja HTTP i odmowy dla usuniętego wpisu | 0,520 s |

Ten przebieg: **2 PASS, 159 asercji**. Później zakres przyrządu rozszerzono
o porównanie każdej kopii jeszcze przed utratą źródeł oraz dodatkowe próby
uprawnień. Aktualne liczby końcowego przebiegu zapisuje `DR_WYNIK`.

**RTO produkcji: niezmierzone.** 0,520 s to lokalne odtworzenie 33 kB,
bez czasu wykrycia, decyzji operatora, odzyskania bazy i poświadczeń, bez
odtwarzania strony w przeglądarce. Nie wolno przeliczać liniowo na 111 GB.
**RPO produkcji: nieustalone.** W kontrolnym zbiorze utrata wyniosła 0 z 15
obiektów zapisanych w kopii; wiek kopii nie jest obietnicą RPO. Proponowany
codzienny snapshot daje docelowo do doby plus czas przebiegu, wyłącznie jeśli
harmonogram działa i kończy się kompletną kopią. Awaria kopiowania zwiększa
to okno i musi alarmować.

## Co dokładnie sprawdza lokalna ochrona

- Aplikacja nie odczyta ani nie usunie kopii: 403 AccessDenied.
- Czytelnik nie usunie kopii: 403 AccessDenied.
- Pisarz ma DELETE, lecz nie usunie chronionej wersji: 400 InvalidRequest/WORM.
- Administrator też nie usunie wersji COMPLIANCE: ten sam błąd WORM.
- Pisarz nie zmieni konfiguracji retencji: 403 AccessDenied.
- Ten sam pisarz usuwa niezablokowany obiekt kontrolny — kontrola dodatnia.
- Nadpisanie oraz zwykły DELETE w MinIO tworzą wersję/znacznik usunięcia;
  oryginalna wersja wskazana przez manifest nadal daje pierwotną SHA-256.
- Po miękkim usunięciu wpisu strona wpisu i trasa jego zdjęcia zwracają 404,
  chociaż bajty istnieją. Nie sprawdzono odtwarzania bazy sprzed żądania usunięcia.
- Rozszerzony przyrząd odrzuca też zapis kopii przez aplikację/czytelnika
  i usunięcie źródła przez pisarza, a przed kasowaniem źródeł porównuje ich
  kopie po odczycie zwrotnym.

To **nie dowodzi R2 Bucket Locks ani izolacji kont Cloudflare**. MinIO
sprawdza własny silnik S3 i własne polityki; COMPLIANCE jest silniejsze od
reguły R2, którą administrator może zdjąć. Odbiór R2 ma osobną procedurę.
Nie zmierzono wygaśnięcia jednodniowej retencji ani wykonania lifecycle.

## #602 — pomiar bajtów

Syntetyczny JPEG z traitu `JpegZeWspolrzednymiGps` zawiera rozpoznawany przez
PHP GPS i producenta `TestPhone`; test najpierw sprawdza tę kontrolę dodatnią.
Po obecnym uploadzie odczyt z MinIO nie ma GPS, ale zachowuje producenta.
Po surowym PUT do testowej kwarantanny odczyt jest bajt w bajt identyczny
z wejściem i **ma GPS**. Worker nie został uruchomiony: dokładnie takie okno
istnieje między bezpośrednim uploadem a czyszczeniem asynchronicznym.

Nie jest to benchmark ani pełny test aplikacji direct upload — takiej
funkcji nie wdrożono. Nie testowano wszystkich możliwych metadanych;
ograniczenia D-023 pozostają jawne.

## Powtarzalne kontrole ujemne

`bash scripts/kontrola-dr-zdjec.sh` w runtime uruchamia projektowy przyrząd
`scripts/kontrola-ujemna.sh`. Każdy przypadek wymaga zieleni przed zmianą,
rzeczywistej podmiany, czerwieni z nazwanej przyczyny oraz przywrócenia
MD5 i mtime. Nie mutuje worktree ani kanonicznego repozytorium.

1. Wyłączenie konfiguracji retencji → `BRAK_ODMOWY: pisarz kasuje`.
2. Podmiana jednego bajtu odczytanej kopii bez zmiany długości →
   `NIEZGODNA_SUMA_KOPII` (sam rozmiar dalej się zgadza).
3. Pominięcie `UsunGps` przed zapisem → wykryte `GPSLatitude` w oryginale.

**Wykonano wszystkie trzy kontrole:** `POTWIERDZONA`, każda miała dokładnie
jedną rzeczywistą podmianę i sekwencję PASS → FAIL → PASS. Pliki JSON:
[brak retencji](bez-retencji.json), [uszkodzony bajt](uszkodzona-kopia.json),
[pominięcie sanitatora](gps-przed-storage.json).

**Ograniczenie formatu dowodu:** istniejący projektowy `kontrola-ujemna.sh`
zapisuje JSON przed zakończeniem pułapki EXIT, więc pole `przywrocenie` ma
tam jeszcze wartość `nie wykonane`. Plików dowodowych nie poprawiano ręcznie.
Każdy proces następnie wypisał w stdout:

```text
Źródło przywrócone: MD5 i mtime PORÓWNANE ze stanem sprzed przebiegu.
```

Wszystkie trzy procesy zakończyły się kodem 0; własny kontener po każdym
przebiegu znikał. Pole JSON nie jest dowodem wykonania EXIT — dowodem tutaj
jest odczyt jego końcowego stdout i kodu wyjścia. Nie zmieniano wspólnego
przyrządu w ramach #617.

## Końcowa weryfikacja pakietu

- **MinIO: 2 PASS, 195 asercji**, 1,80 s czasu PHP; cały przyrząd dodatkowo
  tworzy kontener i role. [Pełny stdout](minio.txt).
- **15/15 obiektów**, 33 223 B, 3 wpisy; przygotowanie i kopia 0,466 s,
  wiek zakończonej kopii przy utracie 0,033 s, odtworzenie i kontrola HTTP
  **0,324 s**. Te liczby zastępują wcześniejszy przykład przy cytowaniu
  końcowego przebiegu; ograniczenia RPO/RTO wyżej pozostają bez zmian.
- **9 odmów**: 7 × 403 AccessDenied (zakres poświadczeń) i 2 × 400
  InvalidRequest/WORM (ochrona konkretnej wersji, również przed administratorem).
- **Zestaw projektu: 4393 PASS, 83 691 asercji**, 472,78 s, kod wyjścia 0.
  [Końcowy fragment stdout](pelna-suita.txt). Polecenie:
  `testuj.sh gpt-dr-zdjecia --compact --filter '^(?!.*ProbaOdtworzeniaTest)'`.
  **Pominięto ProbaOdtworzeniaTest** zgodnie z instrukcją właściciela:
  korzysta ze współdzielonej `kuking_zrodlo_proby_glowny`. Nie twierdzimy,
  że uruchomiono ten test ani domyślnie wyłączoną grupę `dwa-polaczenia`.
- `vendor/bin/pint tests/Dr/DrZdjecMinioTest.php`: PASS. Składnia obu
  skryptów Bash i kompilacja składni Python: PASS. `git diff --check`: PASS.
- `StoreUploadedImage.php` w runtime i worktree po kontrolach ma identyczny
  MD5 `a1828e85d61e3bca9ff7f34b2d9850f4`; kod aplikacji nie jest zmieniony.
- Nie uruchomiono budowania assetów ani testów przeglądarkowych: brak zmian
  interfejsu i assetów. HTTP sprawdzono testem Laravela oraz rzeczywistym
  pobraniem z MinIO, bez pomiaru renderowania w przeglądarce.
- Nie wdrożono harmonogramu kopii, nie zmieniono R2, polityki prywatności ani
  schematu bazy. Nie wykonano push, PR ani zamknięcia zgłoszeń.

Do decyzji: konto/dostawca kopii, budżet, okres ochrony i wygasania, rejestr
usunięć odporny na odtworzenie starej bazy. Do odbioru u dostawcy: odmowy
DELETE/overwrite, scope tokenów, rzeczywiste lifecycle i produkcyjne RPO/RTO.
