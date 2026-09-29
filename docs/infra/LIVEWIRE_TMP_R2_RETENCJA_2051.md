# Retencja surowych uploadów Livewire w R2 — runbook (#2051)

> **28.09.2026. Stan: reguła NIEPOTWIERDZONA.** Ten dokument to audyt
> repozytorium i instrukcja dla właściciela. Nikt z agentów nie ma dostępu do
> Cloudflare; nic tu nie zostało skonfigurowane ani zmierzone na prawdziwym R2.
> Tabela w §6 jest pusta i to jest jej sens: wiersz bez daty nie mówi nic
> o dzisiejszym stanie (ta sama zasada co w [`BRAMKA_R2.md`](BRAMKA_R2.md) §3).

Prefiks reguły lifecycle: `livewire-tmp/`

Ten prefiks pilnuje `PrefiksRetencjiLivewireZgodnyZKonfiguracjaTest`: musi być
równy efektywnemu `config('livewire.temporary_file_upload.directory')`
z ukośnikiem na końcu, a gdy tam jest `null` — domyślnemu `livewire-tmp`
pakietu. Zmiana katalogu w `config/livewire.php` bez zmiany tego dokumentu
(i reguły w panelu) oblewa test.

## 1. Co leży pod tym prefiksem

Kreator przepisu (`resources/views/components/recipe-wizard.blade.php`,
`WithFileUploads`) wgrywa zdjęcie natychmiast po wyborze pliku. Livewire
zapisuje je **zanim** zobaczy je `StoreUploadedImage`:

- dysk: `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2` we wspólnym `appEnv`
  (`.railway/railway.ts`, [#608](DYSK_UPLOADOW_LIVEWIRE_608.md)) — czyli
  bucket oryginałów `AWS_BUCKET`, ten sam co `incoming/`;
- klucz: `livewire-tmp/<losowa nazwa>` plus plik `.json` z metadanymi, w tym
  **oryginalną nazwą pliku od klienta** (`FileUploadConfiguration::storeTemporaryFile()`);
- treść: **surowe bajty z telefonu**, z EXIF-em i GPS-em. `UsunGps` działa
  dopiero w `StoreUploadedImage`, na lokalnej kopii.

To jedyne miejsce, w którym Livewire przyjmuje plik (#2178): tylko
`recipe-wizard.blade.php` używa `WithFileUploads` — zdjęcie gotowego dania
i zdjęcia kroków, oba przez `StoreUploadedImage`. Wpis, pytanie, „Ugotowałem”,
avatar, import i zwykły formularz przepisu to zwykłe żądania `multipart`
(`<input name="…">` bez `wire:model`): plik ląduje w katalogu tymczasowym PHP
i nie dotyka `livewire-tmp/`.

Dysk `r2` nie ma `root` (`config/filesystems.php`), więc prefiks w buckecie
to dokładnie katalog Livewire. Test wyżej pilnuje także tego.

## 2. Co sprząta kod — i dlaczego nadal potrzeba reguły R2

| Mechanizm | Co robi naprawdę | Czy to gwarancja retencji |
|---|---|---|
| `cleanup => true` w `config/livewire.php` | `WithFileUploads::_finishUpload()` woła `cleanupOldUploads()`. Nasz dysk ma sterownik `r2`, nie `s3`, więc `isUsingS3()` zwraca `false` i Livewire **nie** pomija sprzątania: listuje cały `livewire-tmp/` i kasuje pliki starsze niż 24 h. | **Nie.** Działa tylko przy **następnym** uploadzie przez Livewire. Bez ruchu w kreatorze nic się nie kasuje. Koszt: listowanie całego prefiksu przy każdym uploadzie. Gdyby dysk dostał sterownik `s3`, Livewire przestałby sprzątać w ogóle i zdał się na regułę bucketu (dokumentacja Livewire 4, *Configuring automatic file cleanup*). |
| `StoreUploadedImage` + `LokalnaKopiaZdjecia` | Czyta plik z R2 do lokalnej kopii. Po udanym zapisie oryginału w `incoming/` usuwa źródło `livewire-tmp/` i sąsiedni `.json`; lokalną kopię sprząta zawsze (#2178). Gdy usunięcie z R2 zawiedzie, zapis zdjęcia pozostaje udany, a w dzienniku jest ślad bez klucza obiektu i nazwy pliku. | **Nie.** Nie widzi uploadu porzuconego przed zapisem ani nie gwarantuje usunięcia przy awarii magazynu. |
| `kuking:sprzataj-porzucone-uploady` (`PorzuconePlikiLivewire`, codziennie 03:30 UTC, #2178) | Listuje `livewire-tmp/` i kasuje obiekty (także `.json`) starsze niż 24 h, niezależnie od ruchu w kreatorze. Rusza tylko ten katalog; pusty katalog w konfiguracji = brak działania. Porażka usunięcia kończy przebieg kodem ≠ 0 (widać w harmonogramie), w dzienniku bez klucza i nazwy pliku. Ręcznie: `--na-sucho`, `--godziny=N` (min. 1). | **Częściowo.** Linia obrony w kodzie: porzucony plik nie leży dłużej niż ok. 25 h, gdy scheduler i dostęp do R2 działają. Nie jest dowodem — nikt nie zmierzył go na prawdziwym R2 — a przy awarii schedulera nie ma niczego poza regułą bucketu. |
| `OsieroconeZdjecia` (`kuking:sprzataj-osierocone-zdjecia`) | Kasuje zdjęcia mające wiersz `media`, do których nic nie prowadzi. | **Nie.** Obiekt z `livewire-tmp/` nie ma wiersza `media` — ktoś wybrał plik i zamknął kartę albo zapis się nie udał. |
| Usuwanie konta (`EraseAccountData`) | Kasuje zdjęcia z wierszy `media`. | **Nie** obejmuje `livewire-tmp/`. |
| Reguła lifecycle R2 na `livewire-tmp/` | Wygasza obiekty po N dniach od zapisu, niezależnie od ruchu. | **Tak — jedyna.** Stan: w repozytorium **nie ma żadnego dowodu**, że istnieje (brak zrzutu, eksportu, wpisu w `evidence/`, komentarza w #602 ani w #2051). |

Wniosek: po wdrożeniu #2178 udany zapis usuwa surowy plik i `.json` od razu,
a plik porzucony przed zapisem (zły format, zamknięta karta) albo pozostawiony
przez awarię usuwania zbiera codzienne `kuking:sprzataj-porzucone-uploady`
(starsze niż 24 h). Reguła lifecycle z §4 zostaje drugą, niezależną linią
obrony — na wypadek awarii schedulera lub zmiany katalogu — i nadal wymaga
osobnego odbioru przez właściciela.

### Co obiecują dokumenty

- `resources/legal/polityka-prywatnosci.md` — **milczy** o plikach
  tymczasowych. Nie obiecuje ich retencji, ale też nie mówi, że istnieją.
- `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.4 — podaje terminy
  usunięcia zdjęć z wierszem `media` i `kuking:sprzataj-osierocone-zdjecia`;
  o `livewire-tmp/` **milczy**.
- [`DIRECT_UPLOAD_R2_ANALIZA_602.md`](DIRECT_UPLOAD_R2_ANALIZA_602.md) §6–7 —
  rekomenduje regułę 1 dzień; to zamiar, nie stan.

Po odbiorze z §6 (i dopiero wtedy) rejestr §3.4 powinien dostać zdanie
o regule — patrz §8. Do tego czasu wolno pisać najwyżej „reguła jest
założona”, nie „pliki tymczasowe wygasają”.

## 3. Które buckety

Reguła należy do **bucketu oryginałów** każdego trwałego środowiska —
tego, na który wskazuje zmienna współdzielona `R2_BUCKET` w danym środowisku
Railway (w kodzie `AWS_BUCKET`, dysk `r2`). Nazwę bucketu odczytaj
w Railway → środowisko → *Shared Variables* → `R2_BUCKET`. **Nie wpisuj tu
ani w issue identyfikatora konta ani nazw tokenów.**

| Środowisko | Bucket | Reguła |
|---|---|---|
| produkcja | `R2_BUCKET` produkcji | tak |
| staging | `R2_BUCKET` stagingu | tak, **osobno** |
| preview (jeśli ma własny `R2_BUCKET`) | ten bucket | tak; jeśli preview dzieli bucket ze stagingiem, reguła stagingu go obejmuje |

**Jeśli staging i produkcja mają TEN SAM `R2_BUCKET`:** jedna reguła obejmuje
oba, ale obiektu kontrolnego z §5.3 **nie wolno** wtedy położyć „na
stagingu”, bo trafi do bucketu produkcji. Zapisz to w tabeli §6 i zrób
kontrolę na osobnym, nieprodukcyjnym buckecie (może być nowy, pusty, tylko do
tej próby) — sama reguła jest cechą bucketu, nie aplikacji.

**Nie zakładaj reguły** na bucketach wariantów (`R2_PUBLIC_BUCKET`), paczek
RODO (`R2_EXPORTS_BUCKET`), kopii bazy ani kopii zdjęć. Livewire tam nie pisze.

## 4. Krok właściciela — reguła (ok. 10 minut na bucket)

Nazwy przycisków panelu **nie były sprawdzane** (brak dostępu, świadomie).
Jeśli panel wygląda inaczej, trzymaj się sensu kroków, nie nazw.

1. **Bucket Lock — najpierw odczyt.** Cloudflare → R2 → bucket oryginałów →
   *Settings* → *Bucket lock rules*. Zapisz: brak / są (prefiks, okres).
   Dokumentacja Cloudflare: rygiel ma pierwszeństwo przed lifecycle —
   obiekt objęty ryglem nie zostanie skasowany, dopóki rygiel trwa
   (cytat i kontekst: [`LOKALIZACJA_DANYCH_R2.md`](LOKALIZACJA_DANYCH_R2.md)
   §6a). Rekomendacja projektu to **brak rygla na buckecie oryginałów**
   (tamże, „Dlaczego NIE WOLNO zaryglować żywego bucketu oryginałów”;
   [`DR_ZDJEC_R2.md`](DR_ZDJEC_R2.md) powtarza to na liście tego, czego nie
   rekomendujemy). Jeśli rygiel jest i obejmuje
   pusty prefiks albo `livewire-tmp/` — **zatrzymaj się**, nie zakładaj
   reguły jako „działającej”, opisz rygiel w #2051. Wtedy czas życia surowego
   pliku to okres rygla, nie 1 dzień, i to jest decyzja właściciela.
2. *Settings* → *Object lifecycle rules* → *Add rule*.
3. Nazwa: `livewire-tmp-1-dzien`.
4. Zakres: **prefiks** `livewire-tmp/` — dokładnie tak, **z ukośnikiem na
   końcu**. Bez ukośnika reguła objęłaby też każdy przyszły prefiks
   zaczynający się od tych samych liter.
5. **Zanim zapiszesz:** prefiks nie jest pusty. Pusty = cały bucket, czyli
   także `incoming/` — oczyszczone oryginały potrzebne do wariantów
   i eksportu RODO. Kasowanie ich jest nieodwracalne.
6. Akcja: usuń obiekty (*Expire objects*) po **1 dniu**.
7. Ta sama reguła albo osobna: przerwij niedokończone *multipart uploady*
   po **1 dniu** dla tego samego prefiksu. Dziś zapis Livewire idzie jednym
   `PutObject` (limit zdjęcia 15 MB < próg multipartu 16 MB,
   `App\Support\Storage\R2Adapter`), więc to obrona na wypadek zmiany limitu,
   nie naprawa obecnego stanu. Według dokumentacji Cloudflare nowy bucket ma
   domyślną regułę przerywania multipartów po 7 dniach — **nie usuwaj jej**;
   odczytaj i zapisz, czy jest `[do potwierdzenia w panelu]`.
8. Zapisz regułę. Sprawdź listę reguł: żadna inna reguła „expire” nie ma
   pustego prefiksu ani prefiksu `incoming/`.
9. Powtórz 1–8 osobno dla bucketu stagingu (§3).

Równoważnie z wiersza poleceń (składnia do sprawdzenia w
`wrangler r2 bucket lifecycle --help` przed użyciem):
`wrangler r2 bucket lifecycle add <bucket> livewire-tmp-1-dzien livewire-tmp/ --expire-days 1`.

## 5. Oczekiwany dowód

Każdy punkt z datą i osobą. Wklejany do #2051 **bez**: identyfikatora konta,
nazw i wartości tokenów, podpisanych adresów, nazw kluczy prawdziwych
uploadów (to losowe nazwy, ale obok nich leżą pliki `.json` z oryginalną
nazwą pliku człowieka).

### 5.1 Konfiguracja reguły — produkcja i staging osobno

Jedno z dwóch:

- **zrzut ekranu** listy reguł bucketu z widocznym prefiksem, akcją
  i liczbą dni (nazwę bucketu można zamazać do roli: „oryginały, produkcja”);
- **eksport konfiguracji** przez API S3 (R2 obsługuje odczyt lifecycle):

  ```bash
  aws s3api get-bucket-lifecycle-configuration \
    --bucket "$R2_BUCKET" --endpoint-url "$AWS_ENDPOINT"
  ```

  Oczekiwane w wyniku: reguła z `"Prefix": "livewire-tmp/"`,
  `"Expiration": {"Days": 1}`, `"Status": "Enabled"`, oraz **brak** reguły
  „expire” z pustym prefiksem. Wklejając do issue, zamień wartość
  `--endpoint-url` na `<endpoint>` (zawiera identyfikator konta).

Plus odczyt Bucket Lock z §4 krok 1 (brak / zakres).

### 5.2 Wiek najstarszego obiektu — bez nazw kluczy

Po co najmniej **2 dobach** od założenia reguły, na każdym buckecie:

```bash
aws s3api list-objects-v2 --bucket "$R2_BUCKET" --endpoint-url "$AWS_ENDPOINT" \
  --prefix livewire-tmp/ \
  --query '{liczba: length(Contents || `[]`), najstarszy: min_by(Contents || `[]`, &LastModified).LastModified}'
```

Polecenie zwraca **tylko liczbę i datę**, nie klucze. Oczekiwane:
`najstarszy` nie starszy niż ok. **2 doby** (1 dzień reguły + do 24 h
asynchronicznego usuwania, patrz §7). Nie uruchamiaj wersji bez `--query` —
wypisze klucze.

### 5.3 Obiekt kontrolny — TYLKO na buckecie nieprodukcyjnym

1. Połóż plik bez danych osobowych:
   `livewire-tmp/kontrola-2051.txt` z treścią `kontrola 2051`, oraz dla
   porównania `incoming/kontrola-2051.txt` z tą samą treścią.
2. Zapisz datę i godzinę zapisu (UTC).
3. Od 24 h po zapisie sprawdzaj `HEAD` (np. `aws s3api head-object --key
   livewire-tmp/kontrola-2051.txt`) co kilka godzin, **do skutku**. Jeden
   `HEAD` dokładnie po dobie niczego nie rozstrzyga.
4. Zapisz datę i godzinę pierwszego `404`. Oczekiwane: w ciągu ok. **48 h**
   od zapisu.
5. W tym samym czasie `incoming/kontrola-2051.txt` **ma istnieć**. Po
   odczycie skasuj go ręcznie.

Dopiero punkt 4 pozwala napisać „surowe uploady wygasają”. Sama reguła
z 5.1 pozwala napisać tylko „reguła jest założona”.

## 6. Wynik — do wypełnienia przez właściciela

| Pomiar | Produkcja | Staging | Data | Kto |
|---|---|---|---|---|
| Staging i produkcja: ten sam `R2_BUCKET`? (tak / nie) | — | | | |
| Bucket Lock na buckecie oryginałów (brak / zakres) | | | | |
| Reguła `livewire-tmp/`, 1 dzień: zrzut albo eksport (5.1) | | | | |
| Reguła przerywania multipartów (dni) | | | | |
| Brak reguły „expire” z pustym prefiksem lub `incoming/` | | | | |
| Najstarszy obiekt pod `livewire-tmp/` po 2 dobach (5.2) | | | | |
| Obiekt kontrolny: zapis → pierwszy `404` (5.3, bucket nieprodukcyjny) | — | | | |
| `incoming/kontrola-2051.txt` przetrwał okno (5.3) | — | | | |

## 7. Granice i przypadki brzegowe

- **Granica doby.** R2 liczy wiek od zapisu obiektu i kasuje asynchronicznie;
  dokumentacja Cloudflare podaje „zwykle w ciągu 24 h” od terminu. Realny
  czas życia surowego pliku po regule: **do ok. 48 h**, nie 24 h.
- **Kreator otwarty ponad dobę.** Kreator zapisuje zdjęcie do `media` przy
  autozapisie, zaraz po wyborze pliku. Plik wybrany i niezapisany (np. błąd
  zapisu), a kartę zostawioną na ponad dobę, reguła skasuje — tak samo jak
  dziś robi to `cleanupOldUploads()` Livewire po 24 h. Człowiek zobaczy błąd
  zapisu zdjęcia i wybierze plik jeszcze raz. To nie jest nowe zachowanie.
- **Zmiana `temporary_file_upload.directory`.** Test oblewa; trzeba
  zmienić ten dokument **i** regułę w panelu na obu bucketach, a starą regułę
  zostawić, aż stary prefiks się opróżni.
- **Upload przed utworzeniem wiersza `media`.** To dokładnie przypadek, który
  obejmuje wyłącznie reguła (§2).
- **`cleanup => true` zostaje.** Po regule jest nadmiarowe i kosztuje
  listowanie prefiksu przy każdym uploadzie, ale wyłączenie go to osobna
  decyzja — dopiero po odbiorze §5.3, bo do tego czasu to jedyne, co sprząta.

## 8. Kontrola okresowa i alarm

- **Właściciel kontroli:** właściciel serwisu — jedyna osoba z dostępem do
  panelu Cloudflare.
- **Kiedy:** po odbiorze raz w miesiącu, oraz po każdej zmianie bucketu,
  `R2_BUCKET` albo `config/livewire.php`.
- **Jak:** §5.1 (odczyt reguły) i §5.2 (liczba i najstarszy obiekt).
- **Alarm:** najstarszy obiekt pod `livewire-tmp/` starszy niż **3 doby**
  albo reguły nie ma na liście → otwórz issue `P1` „reguła lifecycle
  `livewire-tmp/` nie działa”, z samymi liczbami z §5.2, bez kluczy.
  Do naprawy nie kasuj obiektów ręcznie całym prefiksem na produkcji bez
  jawnej zgody (AGENTS.md: brak destrukcyjnych operacji na produkcji).
- **Po odbiorze** (§6 wypełnione, §5.3 zmierzone): dopisać do
  `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.4 zdanie o regule
  i realnym czasie życia (do ok. 48 h). Polityka prywatności — decyzja
  właściciela, czy wspominać pliki tymczasowe.

## 9. Wycofanie

Usunięcie reguły `livewire-tmp-1-dzien` w panelu (albo
`wrangler r2 bucket lifecycle remove`). Reguła kasuje tylko obiekty pod
`livewire-tmp/`; skasowanych nie da się odzyskać, ale to pliki tymczasowe,
których kopia oczyszczona z GPS-u jest w `incoming/`, jeśli zdjęcie zostało
zapisane.
