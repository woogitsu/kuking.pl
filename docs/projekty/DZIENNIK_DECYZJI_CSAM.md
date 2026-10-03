# Projekt: dziennik decyzji CSAM poza bazą (#2708, pytanie 17)

> **Status: PROJEKT do akceptacji. Bez kodu.** Kod powstaje dopiero po
> akceptacji właściciela **i** prawnika (treść wpisu, retencja, miejsce).
> Do tego czasu obowiązuje ręczna procedura z
> [`docs/infra/KOPIE_I_ODTWORZENIE.md`](../infra/KOPIE_I_ODTWORZENIE.md) §3.2
> (PR #2776).

## 1. Problem

Akcja „CSAM — natychmiast ukryj i zabezpiecz” (`App\Domain\Moderation\Actions\ZabezpieczDowodCsam`)
w **jednej transakcji PostgreSQL** robi wszystko:

- miękkie usunięcie treści (`deleted_at`),
- `media.status = secured` dla zdjęć treści,
- wiersze `zabezpieczenia_dowodow` (chronią przed retencjami i wymazaniem konta),
- decyzję w `moderation_actions`, wpis `audit_log` (`moderation.csam_secured`),
- blokadę konta autora, zamknięcie zgłoszenia,
- zadanie `PrzeniesPubliczneWariantyDowodu` w `jobs` (ta sama baza).

Odtworzenie kopii bazy sprzed decyzji cofa **wszystko naraz**: treść znów jest
publiczna, zdjęcie ma status `ready`, rejestr zabezpieczeń go nie zna (kasują
go nocne retencje i `kuking:wymaz-ponownie`), autor jest odblokowany. Poza bazą
zostaje tylko ślad przypadkowy (prefiks `zabezpieczone/<id zdjęcia>/` na
prywatnym dysku, notatki moderatora), niepełny dla treści bez zdjęcia.

Wzór rozwiązania istnieje dla wymazań kont: `App\Domain\Compliance\DziennikWymazan`
— jeden mały obiekt JSON na zdarzenie w prywatnym magazynie obiektów,
czytany po odtworzeniu przez `kuking:wymaz-ponownie`. Ten projekt przenosi ten
wzór na decyzje CSAM, z **jedną świadomą różnicą** (§4): wymazanie czeka na
zapis do dziennika, ukrycie CSAM czekać nie może.

## 2. Co zapisujemy — minimum

Dziennik ma pozwolić **powtórzyć decyzję** na odtworzonej bazie i nic ponadto.

### 2.1 Pola (wersja 1)

| Pole | Przykład | Po co |
|---|---|---|
| `wersja` | `1` | Format wpisu; komenda odtwarzająca odrzuca nieznaną wersję |
| `id_proby` | UUID | Identyfikator jednej próby akcji (powstaje przed transakcją) |
| `typ_celu` | `post` / `recipe` / `comment` / `media` | Klucz z `ZabezpieczDowodCsam::TYPY` |
| `id_celu` | UUID | Co ukryć i zabezpieczyć |
| `id_zdjec` | lista UUID | Zdjęcia zabezpieczone razem z treścią — także te, które mogą zniknąć z treści w kopii |
| `id_konta_autora` | UUID albo `null` | Kogo zablokować ponownie |
| `konto_zablokowane` | `true` / `false` | Czy pierwsza decyzja zablokowała konto (rola moderatora mogła nie pozwalać) |
| `id_decyzji` | UUID `moderation_actions.id` | Powiązanie z decyzją w bazie (uzgadnianie, §5.3) |
| `id_zgloszenia` | UUID albo `null` | Zamknięcie zgłoszenia po odtworzeniu bez ponownej odpowiedzi — **pytanie P-3** |
| `id_moderatora` | UUID | Kto podjął decyzję — **pytanie P-3** |
| `stan` | `zamiar` / `zatwierdzono` / `wycofano` | Patrz §4 |
| `chwila` | ISO 8601 UTC | Do okna „kopia–awaria” |

### 2.2 Czego NIE zapisujemy — nigdy

- **żadnej treści**: tekstu wpisu, przepisu, komentarza, tytułu, opisu;
- **żadnego pliku, obrazu, miniatury, wariantu** ani adresu/klucza pliku
  w magazynie (`path`, `variants`), ani nazwy pliku od klienta;
- **żadnego skrótu pliku** (SHA-256, perceptual hash) — skrót materiału CSAM
  może sam być informacją o sprawie; jeśli prawnik uzna go za potrzebny do
  weryfikacji dowodu, to osobna decyzja (**P-4**);
- notatki moderatora (`note`), uzasadnienia, IP, e-maila, nazwy konta;
- numeru sprawy z zawiadomienia (Policja, prokuratura, Dyżurnet.pl) — ten
  zostaje w notatkach procedury (`docs/flota/CSAM_JEDNA_KARTKA.md` pkt 9).

Zasada sprawdzalna testem: wpis zawiera **wyłącznie** klucze z tabeli 2.1;
każdy inny klucz oblewa test (lista dozwolonych, nie lista zakazanych).

### 2.3 Kształt kluczy w magazynie

```text
dziennik-csam/<typ_celu>/<id_celu>/proby/<id_proby>/zamiar.json
dziennik-csam/<typ_celu>/<id_celu>/proby/<id_proby>/wycofano.json
dziennik-csam/<typ_celu>/<id_celu>/zatwierdzono.json
```

- Każdy obiekt zapisywany **warunkowo** (`IfNoneMatch: *` na R2, `fopen('x')`
  lokalnie — ten sam mechanizm co `DziennikWymazan::putJesliBrak()`), więc
  wpisy są niezmienne i dopisanie dwa razy jest nieszkodliwe.
- `zatwierdzono.json` jest **jeden na cel**: ta sama treść nie może być
  zabezpieczona dwa razy (akcja odmawia, gdy `ZabezpieczoneDowody::dotyczy()`),
  więc klucz po celu daje idempotencję odtworzenia za darmo.
- `zamiar`/`wycofano` są per próba, bo nieudana próba (np. „Treść zmieniła
  się w trakcie zabezpieczania”) może być powtórzona przez moderatora.
- Identyfikator w kluczu nie jest tajemnicą, ale listowanie prefiksu ujawnia
  liczbę spraw — stąd dostęp tylko z prywatnego dysku (§3).

## 3. Gdzie

- Nowy klucz `kuking.dziennik_csam.dysk` (zmienna
  `KUKING_DZIENNIK_CSAM_DYSK`). **Domyślnie** ten sam prywatny dysk co
  `kuking.dziennik_wymazan.dysk` (na produkcji `r2_eksporty`), osobny prefiks
  `dziennik-csam/`. `CleanUpDataExports` kasuje wyłącznie klucze z
  `data_exports`, więc prefiksu nie dotyka (test, jak dla `dziennik-wymazan/`).
- **Zakazane dyski** (test konfiguracji oblewa): `r2_legacy` (publiczny bucket
  z `url`), `r2_publiczne` (warianty), każdy dysk z kluczem `url`, dysk kopii
  bazy `r2_kopie` (aplikacja nie ma tam prawa zapisu, D-043) oraz baza
  danych. Na produkcji (`FILESYSTEM_DISK=r2`) zakazany jest też `local` —
  dysk kontenera znika przy wdrożeniu.
- **Krótkie limity czasu**: zapis „zamiaru” idzie przed transakcją (§4), więc
  klient S3 nie może wisieć domyślnie długo. Projekt zakłada osobną definicję
  dysku `r2_dziennik_csam` w `config/filesystems.php` — ta sama konfiguracja
  bucketu co `r2_eksporty`, ale `http.connect_timeout = 2`, `http.timeout = 3`
  i brak wbudowanych ponowień SDK (`retries = 0`). Bez nowej zależności.
- **Do decyzji właściciela (P-5):** osobny bucket z osobnym tokenem R2
  (tylko zapis warunkowy + listowanie, bez kasowania) zamiast prefiksu
  w `r2_eksporty`. Lepiej chroni przed skasowaniem dziennika tym samym
  tokenem, którym aplikacja sprząta paczki RODO; kosztuje czynność w panelu
  Cloudflare. Projekt działa w obu wariantach — zmienia się tylko wartość
  `KUKING_DZIENNIK_CSAM_DYSK`.

## 4. Kiedy — względem transakcji

**Twarda zasada: awaria magazynu nigdy nie opóźnia ani nie cofa ukrycia
treści.** To jest odwrotność `DziennikWymazan` (tam wymazanie czeka, bo
opóźnienie wymazania jest mniejszym złem). Tu minuta dłużej widocznego
materiału CSAM jest gorsza niż brak wpisu w dzienniku.

Trzy kroki, każdy z innym zadaniem:

### Krok A — „zamiar”, PRZED transakcją, najlepszy wysiłek

- `ZabezpieczDowodCsam::handle()` po wstępnym `Gate::authorize()` generuje
  `id_proby` i zapisuje `zamiar.json` (pola z §2.1 znane przed transakcją:
  typ, id celu, id moderatora, id zgłoszenia, chwila; bez listy zdjęć — tę zna
  dopiero transakcja).
- **Jedna próba**, limit 3 s (dysk z §3). Porażka = `Log::warning` z
  identyfikatorami (bez treści) i akcja idzie dalej. Bez `Sleep`, bez ponowień.
- Po co: zamyka okno „commit się udał, baza padła, zanim worker zapisał krok B”.
  Wtedy po odtworzeniu jest przynajmniej zamiar.

### Krok B — „zatwierdzono”, outbox w TEJ SAMEJ transakcji

- Wewnątrz transakcji, obok `PrzeniesPubliczneWariantyDowodu::dispatch()`,
  akcja wstawia zadanie `ZapiszDecyzjeCsamPozaBaza` (kolejka `database`,
  `QueueConfigurationGuard::assertAtomicDatabaseQueue()`, **bez**
  `afterCommit()` — z tych samych powodów co w `docs/infra/CSAM_KOLEJKA_2437.md`).
  Zadanie powstaje wtedy i tylko wtedy, gdy decyzja się zatwierdzi.
- Worker zapisuje `zatwierdzono.json` (komplet pól §2.1) warunkowo. Wpis
  już istniejący (412) = sukces. Ponowienia: `tries = 0` z `retryUntil()`
  = 7 dni, `backoff` rosnący do 1 h. Awaria magazynu nie dotyka niczego poza
  tym zadaniem.
- Treść zadania w `jobs.payload` = te same identyfikatory co wpis (nic więcej);
  `failed_jobs` jest już objęte istniejącą retencją.

### Krok C — „wycofano”, gdy transakcja padła

- Gdy `handle()` kończy się wyjątkiem po kroku A (odmowa „treść zmieniła się”,
  „to Twoja własna treść”, błąd bazy), w `catch` — poza transakcją — zapisujemy
  `wycofano.json` (jedna próba, 3 s), potem wyjątek leci dalej bez zmian.
- Po co: komenda odtwarzająca odróżnia „zamiar bez zatwierdzenia, bo
  wycofano” (nic nie robić) od „zamiar bez zatwierdzenia i bez wycofania”
  (nieznane — człowiek decyduje).

### Okno, które zostaje (świadomie)

Krok A zawiódł **i** baza padła po commicie, zanim worker zapisał krok B.
Śladem zostaje wtedy linia `Log::warning` z kroku A (logi Railwaya, poza bazą,
z ich własną krótką retencją) oraz ręczna procedura §3.2. Zamknięcie tego okna
wymagałoby blokowania ukrycia na magazynie — czego właśnie nie chcemy.

### Siatka bezpieczeństwa — uzgadnianie nocne

Komenda `kuking:dziennik-csam --uzupelnij` (harmonogram jak
`kuking:dziennik-wymazan`): dla każdego wiersza `zabezpieczenia_dowodow`
będącego celem decyzji bez `zatwierdzono.json` dopisuje wpis z danych w bazie
(warunkowo). Obejmuje decyzje sprzed wdrożenia i zgubione zadania. Alarm
(`/health`, jak `warianty_dowodu_zalegle`): decyzja starsza niż 15 min bez
wpisu — sam kod błędu, bez identyfikatorów.

## 5. Jak użyć po odtworzeniu kopii

### 5.1 Komenda

```bash
php artisan kuking:odtworz-decyzje-csam --od="<chwila kopii>" --wykonawca=<UUID konta administratora> --na-sucho
php artisan kuking:odtworz-decyzje-csam --od="<chwila kopii>" --wykonawca=<UUID konta administratora>
```

- `--od` — ta sama chwila co w `kuking:wymaz-ponownie`; komenda bierze wpisy
  od `--od` minus 1 h zapasu (zegar kopii vs zegar aplikacji). Wpisy starsze
  są pomijane — ich decyzje są w kopii.
- `--wykonawca` — istniejące w odtworzonej bazie konto z `secureCsam`
  (Policy jak w panelu, `Gate::forUser()`); bez tego komenda odmawia.
  UUID w argumencie nie jest autoryzacją — Policy rozstrzyga.
- `--na-sucho` — tylko lista: co zostanie zrobione, co pominięte, co wymaga
  człowieka. Zero zapisów.

### 5.2 Co robi z każdym celem

| Stan w dzienniku | Stan w odtworzonej bazie | Działanie |
|---|---|---|
| `zatwierdzono` | cel już zabezpieczony | pomiń (idempotencja) |
| `zatwierdzono` | cel istnieje, niezabezpieczony | powtórz decyzję (niżej) |
| `zatwierdzono` | celu nie ma w kopii (powstał po kopii) | nic do ukrycia; **zgłoś** w podsumowaniu: plik może leżeć w magazynie, sprawa dla prawnika |
| tylko `zamiar` + `wycofano` | — | pomiń |
| tylko `zamiar` | — | **nie rób nic automatem**; lista „do decyzji człowieka”, kod wyjścia ≠ 0 |

**Powtórzenie decyzji** = ta sama ścieżka co panel
(`ZabezpieczDowodCsam` w nowym, jawnym trybie „odtworzenie z dziennika”):
ukrycie, `secured`, `zabezpieczenia_dowodow`, blokada konta (jeśli
`konto_zablokowane = true`), zadanie przeniesienia wariantów,
`audit_log` z `zrodlo = dziennik_csam` i `id_proby`. **Bez** powiadomień: autor
i zgłaszający dostali je za pierwszym razem (**P-6**). Zgłoszenie zamykane bez
ponownej odpowiedzi. Zdjęcia z `id_zdjec`, których już nie ma przy treści
w kopii, i tak dostają `secured`.

Odtworzenie **nie** pisze nowych wpisów do dziennika (`zatwierdzono.json`
celu już istnieje); uzgadnianie nocne też ich nie zdubluje (zapis warunkowy).

### 5.3 Kolejność w runbooku (zmiana §3.1/§3.2 przy wdrożeniu)

Komenda musi pójść **przed** `kuking:wymaz-ponownie` i przed przyjęciem ruchu
oraz przed pierwszym przebiegiem harmonogramu: wymazanie konta i nocne
retencje omijają tylko dowody znane z `zabezpieczenia_dowodow`, więc bez
odtworzenia decyzji skasowałyby materiał dowodowy autora z okna.
Kod wyjścia: 0 = wszystko zrobione albo pominięte; 2 = są cele „do decyzji
człowieka” lub „brak w kopii”; 1 = błąd (magazyn, Policy). Ruch dopiero przy 0
albo przy 2 po spisaniu decyzji człowieka.

## 6. Retencja — pytania do prawnika

Technicznie dziennik jest potrzebny tak długo, jak istnieje kopia sprzed
decyzji: najstarsza kopia to dziś miesięczny Volume Backup Railwaya, 89 dni
(`KOPIE_I_ODTWORZENIE.md` §5.3), stąd 120 dni dla `DziennikWymazan`.
Propozycja: **120 dni od `chwila` wpisu `zatwierdzono`**, potem kasowanie
nocne (`kuking:dziennik-csam --pielegnuj`), cel i próby razem.

Pytania (do odpowiedzi przed kodem):

- **P-1.** Czy wpis z identyfikatorami konta i treści, oznaczony jako decyzja
  „krzywdzenie dzieci”, jest danymi dotyczącymi czynów zabronionych (art. 10
  RODO)? Jaka podstawa przetwarzania i czy wymaga wpisu w rejestrze czynności?
- **P-2.** Czy 120 dni jest dopuszczalne, skoro wiersz `zabezpieczenia_dowodow`
  w bazie żyje dłużej (do decyzji organów)? Czy dziennik może/powinien żyć
  tak długo jak zabezpieczenie, czy krócej (minimalizacja)?
- **P-3.** Czy `id_moderatora` i `id_zgloszenia` (pośrednio dane osoby
  zgłaszającej) mogą być w dzienniku? Bez nich odtworzenie działa, ale
  zgłoszenie zostaje otwarte, a audyt nie wie, kto decydował.
- **P-4.** Czy skrót pliku jest potrzebny (weryfikacja integralności dowodu
  po odtworzeniu), czy zakazany? Projekt: zakazany.
- **P-6.** Czy po odtworzeniu trzeba ponownie powiadomić autora o blokadzie
  (DSA art. 17), skoro pierwsze powiadomienie zostało wysłane, ale jego ślad
  w bazie zniknął razem z kopią?
- **P-7.** Czy dziennik podlega prawu dostępu/eksportowi danych osoby,
  której dotyczy (art. 15), czy ograniczeniu ze względu na postępowanie?
  Dziś paczka eksportu go nie obejmuje (dziennik nie jest w bazie).

## 7. Ryzyka

| Ryzyko | Skutek | Ograniczenie |
|---|---|---|
| Okno A-zawiódł + baza padła przed workerem | decyzja bez wpisu | log `warning`, ręczna procedura §3.2; uzgadnianie nocne nie pomoże (baza zniknęła) |
| Krok A wydłuża akcję | do 3 s przy awarii R2 | jedna próba, krótki timeout; mierzymy w teście z dyskiem, który wisi |
| Wyciek prefiksu | liczba i identyfikatory spraw | prywatny dysk bez `url`, test konfiguracji; P-5 (osobny token) |
| Ten sam token kasuje paczki RODO | ktoś/coś kasuje dziennik | warunkowy zapis nie chroni przed `DELETE`; P-5 |
| Odtworzenie „zamiaru” bez zatwierdzenia | ukrycie, którego nikt nie zatwierdził | automat nie wykonuje — człowiek decyduje |
| Konto `--wykonawca` nie istnieje w kopii | komenda odmawia | runbook: wybrać administratora sprzed kopii |
| Wpis z cudzej, starszej kopii (`--od` za wcześnie) | powtórzenie już obecnych decyzji | idempotencja: zabezpieczony cel = pomiń |
| Rozjazd formatu wpisu po zmianie kodu | stare wpisy nieczytelne | pole `wersja`, test zgodności wstecz |
| Dysk `r2_legacy` (#2708 luka 4) | warianty w publicznym buckecie | poza zakresem; odtworzenie ponawia `PrzeniesPubliczneWariantyDowodu`, które zostawia ten stan jawnie jako błąd |

## 8. Testy, które powstaną

Wszystkie na PostgreSQL, dysk dziennika `Storage::fake()` albo lokalny
katalog tymczasowy; każdy z kontrolą ujemną.

1. **Treść wpisu**: po akcji `zatwierdzono.json` ma dokładnie klucze z §2.1;
   dodanie `note` albo ścieżki pliku do wpisu oblewa test.
2. **Ukrycie nie czeka na magazyn**: dysk dziennika rzuca wyjątek przy każdym
   zapisie → akcja kończy się sukcesem, treść ukryta, `secured`, zadanie
   `ZapiszDecyzjeCsamPozaBaza` w `jobs`, log `warning`.
3. **Ukrycie nie wisi**: dysk, którego zapis trwa dłużej niż limit → akcja
   kończy się w czasie poniżej progu (pomiar w teście, nie `sleep`).
4. **Outbox w tej samej transakcji**: błąd po dispatchu cofa i decyzję, i
   zadanie (wzór `tests/Dwa/ZabezpieczenieDowoduIKolejkaWJednejTransakcjiTest.php`);
   `afterCommit` w kodzie oblewa test.
5. **Wycofano**: odmowa „treść zmieniła się” → `zamiar` + `wycofano`, brak
   `zatwierdzono`.
6. **Worker idempotentny**: dwa przebiegi zadania → jeden wpis, drugi przebieg
   to sukces (412 / plik istnieje).
7. **Odtworzenie**: decyzja → zrzut stanu sprzed decyzji (cofnięcie wierszy
   jak w `DziennikWymazanPrzezOdtworzenieTest`) → komenda → treść ukryta,
   `secured`, rejestr, konto zablokowane, **zero** nowych powiadomień.
8. **Idempotencja komendy**: drugi przebieg nic nie zmienia, kod 0.
9. **Zamiar bez zatwierdzenia**: komenda nic nie robi automatem, kod 2,
   identyfikator na liście „do decyzji człowieka”.
10. **Cel spoza kopii**: kod 2, nic się nie wywraca.
11. **Policy**: `--wykonawca` bez `secureCsam` → odmowa, zero zmian.
12. **Kolejność**: `kuking:wymaz-ponownie` na koncie autora PO odtworzeniu
    decyzji nie kasuje zabezpieczonego zdjęcia.
13. **Konfiguracja**: `r2_legacy`, `r2_publiczne`, `r2_kopie`, dysk z `url`
    jako `kuking.dziennik_csam.dysk` → test oblewa; `CleanUpDataExports` nie
    dotyka prefiksu `dziennik-csam/`.
14. **Uzgadnianie nocne**: zabezpieczenie bez wpisu → wpis dopisany; z wpisem
    → bez zmian.
15. **Retencja**: wpis starszy niż `retention_days` znika, młodszy zostaje.

## 9. Czego ten projekt NIE robi

- nie wysyła niczego na zewnątrz (Policja, Dyżurnet) — tak jak akcja;
- nie przenosi plików z `r2_legacy` (#2708 luka 4, osobna decyzja);
- nie zmienia schematu bazy — dziennik żyje wyłącznie w magazynie obiektów;
- nie dodaje zależności (Flysystem S3 i kolejka `database` już są).

## 10. Decyzje do podjęcia przed kodem

- **Właściciel:** budujemy czy zostaje sama procedura §3.2? Prefiks
  w `r2_eksporty` czy osobny bucket i token (P-5)?
- **Prawnik:** P-1, P-2, P-3, P-4, P-6, P-7.
- Po akceptacji: issue z kryteriami z §8, potem kod w jednym PR-ze
  (akcja, zadanie, dwie komendy, konfiguracja, runbook §3.1/§3.2, testy).
