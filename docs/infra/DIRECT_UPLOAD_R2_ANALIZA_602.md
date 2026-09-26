# Direct upload do prywatnego R2 quarantine — analiza decyzyjna (#602)

> **25.09.2026. Dokument decyzyjny, bez zmian w kodzie.** Rozdziela to, co
> odczytano z kodu `main`, to, co zmierzono w cudzych pomiarach (#605), i to,
> czego nie sprawdzono na prawdziwym R2. Rekomendacja jest w §6 i jest
> odwracalna: nic tu nie zostało wdrożone ani skonfigurowane.

## 1. Pytanie

Czy przenieść wgrywanie zdjęć z PHP na bezpośredni upload przeglądarki do
prywatnego bucketu/prefiksu *quarantine* w R2 — i jak zrobić to bez utraty
własności, że nieoczyszczony z GPS oryginał nie staje się trwałym plikiem
widocznym dla kogokolwiek (D-023).

Definicja gotowości z #602 dopuszcza dwa wyjścia: (1) pomiar pokazuje
wystarczający zapas obecnej ścieżki → `not planned`, albo (2) działający
quarantine flow z testami. Ten dokument przygotowuje decyzję między nimi.

## 2. Stan dziś — odczyt kodu `main`

Są **dwie** drogi wejścia zdjęcia i #602 opisywało tylko pierwszą.

### 2a. Formularze zwykłe (`/dodaj/zdjecie`, wpis, „Ugotowałem", awatar)

`app/Domain/Media/Actions/StoreUploadedImage.php`, w kolejności:

1. rozmiar (`kuking.media.max_bytes`, 15 MB; PHP `upload_max_filesize=24M`),
2. `getimagesize()` i MIME z bajtów, nie z nagłówka klienta,
3. limit megapikseli (`kuking.media.max_megapixels`, 50 — obrona przed
   „decompression bomb"),
4. `UsunGps::zBajtow()` — zerowanie GPS w EXIF, wymazanie XMP i surowych
   profili (D-023, #1004),
5. zapis **oczyszczonego** oryginału do prywatnego dysku (`kuking.media.disk`),
6. synchroniczny `podglad` 640 px poniżej 25 Mpx (`PodgladOdRazu`, #430),
7. `ProcessUploadedImage` w kolejce `media`: `thumb`/`feed`/`large`.

Plik z przeglądarki leży do tej chwili wyłącznie w katalogu tymczasowym PHP
kontenera i znika z końcem żądania. **Na tej drodze nieoczyszczony oryginał
rzeczywiście nie trafia do trwałego storage.**

### 2b. Kreator przepisu (Livewire) — ta własność NIE zachodzi

`resources/views/components/recipe-wizard.blade.php` używa
`WithFileUploads`. Livewire zapisuje plik **zanim** zobaczy go
`StoreUploadedImage`, na dysk `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2`
(`.railway/railway.ts`, [#608](DYSK_UPLOADOW_LIVEWIRE_608.md)), prefiks
`livewire-tmp/`. To jest bucket prywatny, ale plik jest **surowy** — z GPS.

Kiedy znika: Livewire (`WithFileUploads::cleanupOldUploads()`) kasuje pliki
starsze niż 24 h, i to **tylko przy kolejnym uploadzie** przez Livewire.
`FileUploadConfiguration::isUsingS3()` sprawdza `driver === 's3'`, a nasz
dysk ma sterownik `r2` — więc Livewire nie zakłada reguły lifecycle, tylko
przy każdym uploadzie listuje cały `livewire-tmp/` (`allFiles()`) i kasuje
stare. Wniosek z odczytu kodu (nie zmierzone na R2):

- surowy plik z GPS leży w prywatnym R2 **co najmniej 24 h**, a przy braku
  ruchu w kreatorze — dowolnie długo;
- każdy upload w kreatorze płaci listowanie całego prefiksu; przy wzroście
  ruchu to rośnie liniowo z liczbą plików w oknie 24 h.

Czyli **quarantine de facto już istnieje** — tylko bez nazwy, bez
lifecycle i bez gwarancji czasu życia. To jest ważniejsze dla D-023 niż
sam direct upload i da się to domknąć **bez** przebudowy (§6, krok A).

## 3. Czy upload jest dziś wąskim gardłem — co mówią pomiary

Pomiar cudzy, lokalny: [`evidence/obciazenie605/2026-09-20-gpt/RAPORT.md`](evidence/obciazenie605/2026-09-20-gpt/RAPORT.md).
Mieszanka z 1% uploadów, rampa 5 → 120 żądań/s:

- 15/s bez błędów, 30/s — 72% niepowodzeń (timeouty);
- **pierwszym objawem jest zaleganie żądań przy kosztownych odczytach SQL,
  nie kolejka zdjęć** (kolejka maks. 7 zadań, wiek ≤ 4 s we wszystkich
  seriach);
- autorzy wprost: kolejny krok to koszt SQL i zaległość HTTP, nie silniki obrazów.

[`evidence/obciazenie605/PRZYGOTOWANIE.md`](evidence/obciazenie605/PRZYGOTOWANIE.md) §2.1
(głośna maszyna, liczby **nie do cytowania**, nośna tylko relacja):
żądanie wgrania 12–24 Mpx jest kilkukrotnie droższe niż 48 Mpx, bo
powyżej 25 Mpx nie powstaje synchroniczny `podglad`. Koszt ścieżki
żądania to więc w dużej mierze **dekodowanie podglądu**, a nie przesył
bajtów przez PHP.

Co z tego wynika dla #602: direct upload zdejmuje z PHP **przesył**
(do 15 MB na żądanie) i trzymanie wątku FrankenPHP w czasie wysyłania
pliku przez wolne łącze. Nie zdejmuje dekodowania — to i tak robi worker.
Żaden pomiar nie pokazał dotąd, że przesył ogranicza web tier.

**Czego nie zmierzono nigdzie:** wgrania przez wolne łącze mobilne
(długie trzymanie wątku przy `max_threads=4`, `docs/DATABASE.md` §598 A)
ani odsetka uploadów w ruchu produkcyjnym. To są jedyne dwa pomiary,
które mogłyby uzasadnić direct upload.

## 4. Docelowy wariant, gdyby był potrzebny — i co w nim jest trudne na R2

Architektura z #602 (presigned URL → quarantine → job `media` → czyszczenie →
oczyszczony oryginał → warianty → kasowanie z quarantine → lifecycle) jest
poprawna. Poniżej tylko rzeczy, które ten opis pomija.

| Wymaganie z #602 | Jak na R2 | Stan wiedzy |
|---|---|---|
| quarantine bez publicznej domeny | osobny bucket (bez własnej domeny i bez `r2.dev`) — prefiks w buckecie oryginałów też jest prywatny, ale osobny bucket daje osobny token i osobny lifecycle | do decyzji; rekomendacja: osobny bucket |
| presigned URL = kontrolowany PUT do jednego klucza | Laravel ma `temporaryUploadUrl()` w `Illuminate\Filesystem\AwsS3V3Adapter`; nasz `R2Adapter` dziedziczy po adapterze League, a dysk budowany przez `DyskR2` — **trzeba sprawdzić, czy ta metoda jest osiągalna** na naszym dysku | niesprawdzone |
| twardy limit rozmiaru | S3 robi to polityką `POST` z `content-length-range`. **Według dokumentacji R2 nie obsługuje `PostObject`** — zostaje presigned `PUT` z `Content-Length` wpisanym do podpisanych nagłówków | niesprawdzone na R2; bez tego limit egzekwuje dopiero worker, po zapłaceniu za przesył i storage |
| backend nie ufa `Content-Type` | worker robi to samo co dziś `StoreUploadedImage` kroki 1–3 — kod do ponownego użycia, nie do napisania | gotowe w kodzie |
| plik niewidoczny przed walidacją | `media.status = pending` i widoki pokazują tylko `ready` — ta sama zasada co dziś | gotowe w kodzie |
| GPS/EXIF nie przechodzi do finalnego oryginału | `UsunGps::zBajtow()` w workerze zamiast w żądaniu | gotowe w kodzie; testy `OryginalTraciGps*` trzeba przepiąć na nową ścieżkę |
| porzucone pliki kasowane automatycznie | reguła lifecycle R2 na bucket quarantine (np. 1 dzień) | czynność w panelu Cloudflare |
| błędny plik nie może zostać awansowany | awans = zapis oczyszczonych bajtów pod NOWYM kluczem w buckecie oryginałów, nigdy `copy` z quarantine | do zaprojektowania; `copy` byłby tu błędem, bo kopiuje surowe bajty |
| — (brak w #602) | CORS na bucket quarantine: tylko `PUT` z `https://kuking.pl` | czynność w panelu |
| — (brak w #602) | UX 50+: dziś jeden formularz z plikiem działa bez własnego JS; direct upload **wymaga** JS (żądanie URL, `PUT`, potwierdzenie) i stanu „wysyłam…" z ponawianiem — AGENTS.md §5 i D-053 dopuszczają JS, ale nie martwy przycisk | koszt po stronie UI |
| — (brak w #602) | nowy stan pośredni: plik w quarantine bez wiersza `media`, wiersz bez pliku, awans w połowie — każdy potrzebuje sprzątania i testu (por. #962, #1003) | koszt po stronie domeny |

Szacunek zakresu wdrożenia (2): nowy endpoint podpisu z Policy i limitem
żądań, endpoint potwierdzenia, zmiana `ProcessUploadedImage` na wejście
z quarantine, JS na każdym formularzu z plikiem i w kreatorze, dwa buckety
i lifecycle w panelu, odbiór na stagingu z prawdziwym R2. To jest pakiet
rzędu kilku dni z odbiorem infrastruktury, a zysk jest dziś niezmierzony.

## 5. Warianty decyzji

| Wariant | Co daje | Koszt | Odwracalność |
|---|---|---|---|
| **A. Nazwać i domknąć istniejący quarantine Livewire** | reguła lifecycle R2 na prefiks `livewire-tmp/` (np. 1 dzień) — surowy plik z GPS ma twardy czas życia niezależny od ruchu | minuty w panelu Cloudflare, zero kodu | usunięcie reguły |
| **B. Zostać przy obecnej ścieżce, zamknąć #602 jako `not planned`** | nic się nie zmienia | zero | ponowne otwarcie issue |
| **C. Odłożyć z warunkiem wejścia** | decyzja wraca sama, gdy liczby ją uzasadnią | pomiar z §3 „czego nie zmierzono" | — |
| **D. Wdrożyć direct upload teraz** | zdjęcie przesyłu z PHP | §4 | trudna: nowe stany danych i JS na formularzach |

## 6. Rekomendacja

**A + C.** Bezpieczniejsze i w pełni odwracalne:

1. **Teraz (A):** reguła lifecycle na `livewire-tmp/` w buckecie
   oryginałów, wiek 1 dzień. To realnie poprawia D-023 dziś, bez kodu.
   Reguła nie może objąć innych prefiksów (`incoming/` to oczyszczone
   oryginały potrzebne do eksportu RODO).
2. **Nie wdrażać direct uploadu (C)**, dopóki co najmniej jeden warunek nie
   jest spełniony pomiarem, a nie odczuciem:
   - szczyt zajętych wątków FrankenPHP w czasie uploadów zbliża się do
     `max_threads` (dziś 4) przy normalnym ruchu,
   - p95 `POST` z plikiem na produkcji przekracza kilka sekund przy
     zdrowej bazie i pustej kolejce,
   - po #595 web tier skaluje się replikami głównie z powodu uploadów.
   Źródło tych liczb to monitoring z #599, nie ten dokument.
3. Jeśli właściciel woli jawne domknięcie: **B jest uczciwe** na podstawie
   #605 (upload nie był pierwszym objawem nasycenia) — z zastrzeżeniem, że
   #605 był lokalny i nie mierzył wolnych łączy.

Czego rekomendacja **nie** zmienia: `StoreUploadedImage`, `UsunGps`,
dysków, zmiennych środowiskowych. Nie ma migracji ani numeru decyzji.

## 7. Kroki dla właściciela (wariant A, ok. 10 minut)

1. Cloudflare → R2 → bucket oryginałów (ten sam, na który wskazuje dysk
   `r2`) → *Settings* → *Object lifecycle rules* → *Add rule*.
2. Prefiks: `livewire-tmp/` (dokładnie; jeśli `config/livewire.php`
   `temporary_file_upload.directory` kiedyś się zmieni — ten sam prefiks).
3. Akcja: usuń obiekty po **1 dniu**.
4. **Zanim zapiszesz:** sprawdź, że reguła nie ma pustego prefiksu — pusta
   obejmuje cały bucket, czyli także oczyszczone oryginały.
5. Zapisz datę i zrzut reguły w #602. Po 2 dniach: lista obiektów pod
   `livewire-tmp/` nie powinna mieć niczego starszego niż ~1 dzień.

Nazwy przycisków w panelu **nie były sprawdzane** w tej sesji (brak dostępu
do konta, świadomie). Wycofanie: usunięcie reguły.

## 8. Czego ten dokument nie dowodzi

- że Livewire faktycznie zostawia pliki na produkcyjnym R2 — to odczyt kodu
  (`WithFileUploads.php`, `FileUploadConfiguration::isUsingS3()`) i
  konfiguracji (`railway.ts`), nie listing bucketu;
- że R2 odrzuca `PostObject` i egzekwuje podpisany `Content-Length` — to
  dokumentacja dostawcy, niesprawdzona na prawdziwym buckecie;
- że upload nie jest wąskim gardłem na produkcji — #605 był lokalny.
