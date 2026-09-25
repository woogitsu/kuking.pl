# DR zdjęć w R2 — ochrona przed logicznym i skryptowym usunięciem (#617)

**Stan na 24 IX 2026: kopii zdjęć NIE MA.** RPO dla zdjęć jest dziś
nieskończone: obiekt skasowany poprawnym `DELETE` (przez aplikację,
operatora albo błędny skrypt) jest stracony natychmiast i bezpowrotnie.
Trwałość R2 („jedenaście dziewiątek”) chroni przed awarią nośnika, nie
przed poprawnie wykonanym `DELETE`, i **nie jest kopią zapasową**.

Ten dokument to runbook dla **właściciela**. Kod po stronie aplikacji
(D-257) jest gotowy. Wszystko, co wymaga panelu Cloudflare, tokenów albo
dotknięcia produkcji, jest opisane jako krok do wykonania, a nie jako
fakt. Tło i porównanie wariantów: `LOKALIZACJA_DANYCH_R2.md` §6a
oraz komentarze w #617.

> **Oznaczenie `[do potwierdzenia w dokumentacji Cloudflare]`** stoi przy
> każdym zdaniu o funkcjach Cloudflare, którego repozytorium nie
> sprawdziło bezpośrednio. Zanim wykonasz taki krok, sprawdź aktualną
> dokumentację. Nazwy przycisków i uprawnień zmieniają się szybciej niż
> ten plik.

---

## 1. Odpowiedź na pytanie z #617: czy aplikacja musi mieć prawo kasowania?

**Tak — w bucketach, na których pracuje. Nie — w żadnym innym.**

| Kto kasuje | Bucket | Po co | Czy da się odebrać |
|---|---|---|---|
| `EraseAccountData` → `KasujZdjecie` | `r2`, `r2_publiczne`, `r2_legacy` | usunięcie konta po karencji; zdjęcia znikają **od razu** | **nie** — łamie procedurę usuwania danych |
| `OsieroconeZdjecia` → `KasujZdjecie` | te same | zdjęcia wgrane i nigdy nieprzypięte | nie bez zostawiania cudzych plików na zawsze |
| `StoreUploadedImage`, `ProcessUploadedImage` | te same | kompensacja nieudanego wgrania (plik bez wiersza) | nie — bez tego zostają pliki bez wiersza |
| `CleanUpDataExports`, `GenerateUserExport` | `r2_eksporty` | paczki RODO po 7 dniach | nie |
| `HealthController`, `kuking:bramka-r2` | `r2`, `r2_publiczne` | sprzątanie własnej próbki po teście zapisu | tak, kosztem sond; nie warto |

**Czego NIE proponuję i dlaczego:**

- **kasowania przez kolejkę z opóźnieniem albo „kosz” w tym samym buckecie
  (prefiks `do-usuniecia/`).** Przeniesienie w R2 to kopia plus `DELETE`,
  więc token nadal musi mieć prawo kasowania. Przejęty token kasuje wtedy
  i kosz, i oryginały. Zysk jest wyłącznie przy błędzie w samej aplikacji,
  a kosztem jest opóźnienie usunięcia danych, którego polityka prywatności
  nie przewiduje;
- **rygla (Bucket Lock) na żywych bucketach** — uzasadnienie w
  `LOKALIZACJA_DANYCH_R2.md` §6a: rygiel na `incoming/` wyjmuje usuwanie
  danych z automatu.

**Co zawęża zasięg (D-257, zrobione w kodzie):** każdy bucket może dostać
**własną parę** tokenu — `AWS_ORIGINALS_*`, `AWS_PUBLIC_*`, `AWS_LEGACY_*`,
`AWS_EXPORTS_*` (`.env.example`). Bez tych zmiennych działa jak dotąd,
na wspólnym `AWS_ACCESS_KEY_ID`. Połowa pary zatrzymuje start aplikacji
z nazwą brakującej zmiennej. Nie da się jej po cichu pominąć.

Zawężenie **nie** chroni przed przejęciem całej aplikacji (ma wtedy
wszystkie cztery pary). Ogranicza skutek wycieku jednego sekretu
i przygotowuje podział per rola usługi (#1459). **Przed logicznym
usunięciem chroni wyłącznie kopia, do której aplikacja nie ma prawa
zapisu** — sekcje 2–6.

---

## 2. Sprostowanie do §6a: kopia 1:1 plus lifecycle „31 dni” wygasiłaby wszystko

`LOKALIZACJA_DANYCH_R2.md` §6a proponował jeden bucket kopii z regułą
lifecycle „usuń po 31 dniach od zapisu”. **Przy kopii lustrzanej
(`rclone copy` bez zmian w obiektach, które już są) to kasuje każde
zdjęcie starsze niż 31 dni**, bo wiek obiektu w kopii liczy się od chwili
zapisu do kopii. Po miesiącu w kopii zostaje ostatni miesiąc wgrań.

Z tego samego powodu rygiel z warunkiem wieku (`MaxAgeSeconds`) chroni
w kopii lustrzanej **tylko obiekty młodsze niż 30 dni**. Starsze token
zapisu kopii może już skasować.

**Poprawka: datowane migawki.** Każde kopiowanie pisze komplet do nowego
katalogu `migawka-RRRR-MM-DD/`. Wtedy każdy obiekt w kopii jest młodszy
niż okres rygla, więc rygiel obejmuje całą kopię. Lifecycle kasuje całe
migawki starsze niż 31 dni, a zdjęcie usunięte przez użytkownika znika
z kopii razem z ostatnią migawką, która je zawierała.

---

## 3. Docelowy układ

```
kuking-zdjecia-kopia                (osobny bucket, jurysdykcja EU, bez domeny, r2.dev wyłączone)
└── migawka-2026-09-28/
    ├── oryginaly/<klucz z AWS_BUCKET>          (np. incoming/…/sernik.jpg)
    └── warianty/<klucz z AWS_PUBLIC_BUCKET>    (np. media/…/sernik_feed.webp)
```

Ten układ zna `kuking:sprawdz-kopie-zdjec`. Inny układ da raport „brakuje
wszystkiego” i niezerowy kod wyjścia.

**Czego kopia nie obejmuje, świadomie:**
- `r2_eksporty` — paczki RODO żyją 7 dni, da się je zbudować od nowa, a są
  kopią całego konta. Druga kopia byłaby wyłącznie dodatkowym ryzykiem;
- `r2_legacy` — dopóki jakiekolwiek wiersze mają `disk = r2_legacy`,
  najpierw dokończ przenosiny (`kuking:przenies-zdjecia`,
  `kuking:sprawdz-zdjecia-po-przenosinach`). Sprawdzenie kopii pokaże takie
  wiersze jako BRAK W KOPII, bo migawka ich nie zawiera;
- baza — ma własną warstwę (`KOPIE_I_ODTWORZENIE.md` §7).

**Tokeny — trzy osobne, aplikacja dostaje tylko ostatni:**

| Token | Uprawnienie | Bucket | Kto go trzyma |
|---|---|---|---|
| odczyt źródła | tylko odczyt obiektów | `AWS_BUCKET`, `AWS_PUBLIC_BUCKET` | proces kopiujący |
| zapis kopii | zapis obiektów | `kuking-zdjecia-kopia` | proces kopiujący — **nigdy aplikacja** |
| odczyt kopii | tylko odczyt obiektów | `kuking-zdjecia-kopia` | aplikacja: `AWS_ZDJECIA_KOPIA_*`, wyłącznie dla komendy sprawdzającej |

`[do potwierdzenia w dokumentacji Cloudflare]` czy token R2 da się
ograniczyć do pojedynczych bucketów (oczekiwane: tak, „Apply to specific
buckets only”) i czy istnieje uprawnienie „zapis bez kasowania”.
Zakładaj, że **nie istnieje**. Przed kasowaniem migawek tokenem zapisu
chroni rygiel, nie uprawnienie tokenu.

---

## 4. Kroki właściciela — od zera do pierwszej sprawdzonej migawki

Każdy krok ma pole na datę. Krok bez daty nie jest wykonany.

**Warunek wstępny (#8):** zdanie do polityki prywatności, np. „Po usunięciu
zdjęcia albo konta kopia techniczna zdjęć może istnieć jeszcze do 32 dni.
Nie jest dostępna w serwisie i nie jest przywracana”. Bez niego kroki 4
i 5 nie mają prawa ruszyć (`LOKALIZACJA_DANYCH_R2.md` §6a krok 3).

| # | Krok | Data | Kto |
|---|---|---|---|
| 1 | Odczytać w panelu, czy żywe buckety mają dziś rygiel albo lifecycle (tabela w `LOKALIZACJA_DANYCH_R2.md` §5, kolumna „Bucket Lock”) | | |
| 2 | Utworzyć bucket `kuking-zdjecia-kopia` **z jurysdykcją EU**, na tym samym koncie. Jurysdykcji nie da się zmienić później (§1 tamtego dokumentu). Strażnik D-255 dopuszcza tylko `<konto>.eu.r2.cloudflarestorage.com`, więc bucket bez EU będzie dla aplikacji nieosiągalny | | |
| 3 | W ustawieniach bucketu: **bez własnej domeny, `r2.dev` wyłączone** | | |
| 4 | Rygiel: jedna reguła, bez prefiksu (cały bucket), warunek wieku **30 dni** (`MaxAgeSeconds = 2592000`). Nigdy „indefinite” | | |
| 5 | Lifecycle: „delete objects” po **31 dniach**. Rygiel ma pierwszeństwo przed lifecycle, a usuwanie jest asynchroniczne, zwykle do 24 h (cytaty w §6a) `[do potwierdzenia w dokumentacji Cloudflare]` | | |
| 6 | Utworzyć trzy tokeny z tabeli §3. Wartości tylko w menedżerze haseł i w Railway, **nigdy w repozytorium ani w zgłoszeniu** | | |
| 7 | Railway, **Shared Variables** środowiska: `R2_ZDJECIA_KOPIA_BUCKET`, `R2_ZDJECIA_KOPIA_ODCZYT_ACCESS_KEY_ID`, `R2_ZDJECIA_KOPIA_ODCZYT_SECRET_ACCESS_KEY` (token **odczytu** kopii, „Sealed”). `railway.ts` przekazuje je jako `AWS_ZDJECIA_KOPIA_*` **tylko schedulerowi** (dziś rola `all` w `kuking.pl`). Zmienna wpisana wprost w serwisie po rozdzieleniu usług (#595) nie dojdzie do procesu | | |
| 8 | Pierwsza migawka (§5) | | |
| 9 | Sprawdzenie migawki (§6) | | |
| 10 | Próby rygla i lifecycle (§7.2, §7.3) | | |
| 11 | Próba odtworzenia (§7.1) i wpis RPO/RTO w §8 | | |

---

## 5. Migawka — polecenie dla procesu kopiującego

Proces kopiujący działa **poza aplikacją**: na komputerze właściciela albo
w osobnej usłudze, na wzór `docker/kopia/` dla bazy (automatyzacja to
osobne zgłoszenie). Przykład z `rclone`. `[do potwierdzenia w dokumentacji
rclone]`: składnia zdalnych magazynów dla dostawcy Cloudflare R2 i nazwy
flag w używanej wersji.

```bash
# Dwa zdalne magazyny rclone, każdy z WŁASNYM tokenem z §3:
#   zrodlo:  token odczytu AWS_BUCKET + AWS_PUBLIC_BUCKET
#   kopia:   token zapisu kuking-zdjecia-kopia
MIGAWKA="migawka-$(date -u +%F)"

# `copy`, NIGDY `sync`: sync kasuje w celu to, czego nie ma w źródle —
# czyli powtórzyłby w kopii dokładnie ten DELETE, przed którym kopia chroni.
rclone copy "zrodlo:<AWS_BUCKET>"        "kopia:kuking-zdjecia-kopia/$MIGAWKA/oryginaly/" --immutable --checksum
rclone copy "zrodlo:<AWS_PUBLIC_BUCKET>" "kopia:kuking-zdjecia-kopia/$MIGAWKA/warianty/"  --immutable --checksum
```

**Częstotliwość = RPO.** Rekomendacja na start: **raz w tygodniu**.
Przy retencji 31 dni równocześnie leży do 5 migawek.

**Koszt (cennik R2 odczytany 18 IX 2026, §6a; `[do potwierdzenia w cenniku
Cloudflare]` przed decyzją):** storage 0,015 USD za GB-miesiąc, pierwsze
10 GB-miesiąc bez opłat. Pięć migawek to pięciokrotność rozmiaru zdjęć:

| Rozmiar zdjęć | W kopii (5 migawek) | Storage ponad darmowy próg |
|---|---|---|
| 2 GB | 10 GB | ok. 0 USD / mies. |
| 20 GB | 100 GB | ok. 1,35 USD / mies. |
| 111 GB (szacunek dla 30 tys. zdjęć, `INFRA_DECISION.md`) | 555 GB | ok. 8,2 USD / mies. |

Operacje klasy A (zapis): jedna na obiekt na migawkę, czyli przy ok. 5 obiektach
na zdjęcie (oryginał i warianty) i 4–5 migawkach w miesiącu ok. 20–25 zapisów
na zdjęcie na miesiąc,
przy darmowym progu 1 mln. Codzienne migawki dają RPO 1 dzień przy ok.
6-krotnie wyższym koszcie storage. Decyzja należy do właściciela.

---

## 6. Sprawdzenie migawki — `kuking:sprawdz-kopie-zdjec` (tylko odczyt)

```bash
# dziś (rola `all`): --service kuking.pl; po rozdzieleniu usług (#595): --service scheduler
railway ssh --service kuking.pl -- php artisan kuking:sprawdz-kopie-zdjec \
    --prefiks=migawka-2026-09-28/ --sumy --nadmiarowe
```

| Wynik | Znaczy | Co zrobić |
|---|---|---|
| `BRAK W KOPII` | wiersz `ready` bez pliku w migawce | kopiowanie niepełne. Powtórzyć migawkę, **nie** uznawać jej za kopię |
| `INNY ROZMIAR` / `INNA SUMA` | plik jest, ale nie ten | jak wyżej. Sama zgodność rozmiaru niczego nie dowodzi (próba z #617) |
| `NADMIAROWE (nie odtwarzać)` | obiekt bez wiersza `media`, zwykle konto usunięte po migawce | nic. Wygaśnie z migawką. **Nigdy go nie przywracać** |
| `KOPII NIE MA` | `AWS_ZDJECIA_KOPIA_BUCKET` puste | krok 7 z §4 |
| `Ustaw AWS_ZDJECIA_KOPIA_ACCESS_KEY_ID…` | brak własnego tokenu odczytu | krok 7. Bez własnej pary biblioteka wzięłaby token aplikacji |

Kod wyjścia jest niezerowy przy każdej rozbieżności i przy każdym błędzie
odczytu. Komenda niczego nie zapisuje (test
`KopiaZdjecSprawdzanaTylkoOdczytemTest` podstawia dysk, który wybucha przy
zapisie). `--sumy` pobiera bajty oryginałów, więc przy dużej bazie zaczynaj
od `--limit=200`.

---

## 7. Próby — bez nich nic z powyższego nie jest stanem, tylko zamiarem

**Wyłącznie na obiektach własnego konta testowego. Nigdy na cudzych
zdjęciach.** Agenci tych kroków nie wykonują. Kasowanie wykonuje
właściciel, świadomie, na obiektach, które sam wgrał.

### 7.1 Próba odtworzenia (definicja gotowości #617)

1. Na koncie testowym wgrać 3 zdjęcia i poczekać na `ready`. Zapisać ich
   `media.id`.
2. Zrobić migawkę (§5) i sprawdzić ją (§6) z `--sumy`. Musi być czysto.
3. **Symulacja utraty:** usunąć z żywych bucketów oryginał i jeden wariant
   **jednego** z tych zdjęć (właściciel, tokenem z prawem zapisu do tych
   obiektów, np. `rclone deletefile`). Zapisać godzinę.
4. Potwierdzić utratę: `php artisan kuking:sprawdz-zdjecia-po-przenosinach`
   pokazuje ten wiersz jako `UTRACONE`, a wpis nie pokazuje zdjęcia.
5. **Lista odtworzenia pochodzi z bazy, nie z bucketu kopii:** wyłącznie
   klucze z raportu z kroku 4 (wiersz istnieje, więc zdjęcie nie jest objęte
   żądaniem usunięcia). Obiekty z `NADMIAROWE` nie wracają nigdy.
6. Odtworzyć te klucze z migawki do żywych bucketów **tymczasowym** tokenem
   zapisu (po próbie unieważnić), np. `rclone copyto
   "kopia-odczyt:kuking-zdjecia-kopia/<migawka>/oryginaly/<klucz>" "zywy:<AWS_BUCKET>/<klucz>"`.
   Zapisać godzinę zakończenia.
7. Weryfikacja: `kuking:sprawdz-zdjecia-po-przenosinach` czysty;
   `kuking:sprawdz-kopie-zdjec --sumy` czysty; wpis pokazuje zdjęcie
   w każdym wariancie (miniatura, feed, duże).
8. Wpisać do §8: RPO = odstęp od ostatniej migawki, RTO = od kroku 3 do
   końca kroku 7.

### 7.2 Rygiel trzyma

Tokenem **zapisu kopii** spróbować skasować jeden obiekt z najnowszej
migawki. Oczekiwane: odmowa. Zapisać treść błędu i datę. **Udane
skasowanie znaczy, że reguła nie obejmuje tego klucza.** Wygląda to
identycznie jak „reguła jest”, dopóki ktoś nie spróbuje.

### 7.3 Lifecycle kasuje

Obiekt kontrolny z pierwszej migawki sprawdzać (`rclone lsl` albo `HEAD`)
codziennie od 31. dnia, do skutku. Zapisać datę i godzinę, kiedy naprawdę
zniknął. Dopiero wtedy wolno napisać „kopie wygasają”, a nie „reguła jest
założona”.

---

## 8. Wynik — do wypełnienia przez właściciela

| Pomiar | Wartość | Data | Kto |
|---|---|---|---|
| Rygiel na żywych bucketach (krok 1) | | | |
| Pierwsza migawka: liczba obiektów, rozmiar | | | |
| `sprawdz-kopie-zdjec --sumy`: wynik | | | |
| Rygiel odrzuca `DELETE` (7.2): treść odmowy | | | |
| Lifecycle usunął obiekt kontrolny (7.3): data i godzina | | | |
| **RPO** zmierzone (7.1) | | | |
| **RTO** zmierzone (7.1), liczba obiektów | | | |

Próba z 17 IX 2026 (komentarz w #617) była na katalogach w scratchpadzie,
nie na R2. Dowodzi, że suma łapie podmieniony bajt. **Niczego nie mówi
o prawdziwej kopii**, więc nie wpisuj jej tutaj.

---

## 9. Kto może usunąć albo odtworzyć kopię

| Czynność | Kto | Czym |
|---|---|---|
| zrobić migawkę | proces kopiujący | token zapisu kopii |
| skasować migawkę młodszą niż 30 dni | **nikt** tokenem obiektów. Wyłącznie ktoś z prawem edycji konfiguracji bucketu, po zdjęciu rygla | panel / Wrangler / API `[do potwierdzenia w dokumentacji Cloudflare]` |
| skasować migawkę starszą niż 30 dni | lifecycle (automatycznie) albo proces kopiujący | — |
| sprawdzić migawkę | aplikacja | token odczytu kopii (`AWS_ZDJECIA_KOPIA_*`) |
| odtworzyć do serwisu | **tylko właściciel**, wg §7.1, lista z bazy | tymczasowy token zapisu żywego bucketu |

Rygiel **nie** chroni przed właścicielem konta Cloudflare ani przed tokenem
z prawem edycji konfiguracji R2 (§6a, sprostowanie z 18 IX). Takiego tokenu
nie dostaje ani aplikacja, ani proces kopiujący.

---

## 10. Wariant C, jeśli właściciel odłoży decyzję

Wolno — ale **zapisany wprost** w D-257: data, zdanie „skasowane zdjęcie
jest dziś stracone bezpowrotnie” i data powrotu do decyzji. Obecny stan
jest akceptacją ryzyka **niezapisaną**. Wygląda jak decyzja, a nią nie jest.
