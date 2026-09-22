# Media pipeline

Zdjęcia są kluczową częścią Kuking i jednym z głównych kosztów.

## Flow

```text
client
→ backend: prepare upload
→ signed URL
→ object storage
→ complete
→ background job
→ validation
→ EXIF/GPS strip
→ resize
→ variants
→ moderation
→ ready
```

## Walidacja

Sprawdzić:
- magic bytes;
- realny MIME;
- rozmiar;
- wymiary;
- limit megapikseli;
- możliwość dekodowania;
- checksum.

Nie ufać rozszerzeniu.

### Lista formatów to lista tego, co UMIEMY OTWORZYĆ

`config/kuking.php` → `media.accepted_mime_types` może zawierać wyłącznie
formaty, które to środowisko naprawdę przetworzy — nie te, które umie nazwać.
To rozróżnienie już raz kosztowało: HEIC i HEIF stały na liście, bo rozpoznaje
je `mime_content_type()`, ale PHP 8.4 nie ma stałej `IMAGETYPE_HEIC`
(`getimagesize()` zwraca `false`), a GD ich nie dekoduje. Siedem formularzy
podpowiadało więc format, po którym serwis odpowiadał „ten plik nie wygląda
na zdjęcie" — komuś, kto właśnie zrobił zdjęcie telefonem.

Pilnuje tego `ObiecujemyTylkoFormatyKtoreUmiemyTest`. Wartość atrybutu `accept`
w formularzach bierze się z tej samej listy, przez `LimityZdjec::atrybutAccept()`.

Plik HEIC/HEIF, który mimo to trafi na serwer, jest rozpoznawany po magic
bytes (`mime_content_type()`, nie po rozszerzeniu) i dostaje własny, polski
komunikat mówiący CO ZROBIĆ, oraz własny kod powodu w sygnale
`photo_upload_failed` (`heic_unsupported`, `App\Support\RozpoznanieZdjecia`) —
patrz **D-064** w `docs/DECISIONS.md` po pełną decyzję (czy dokładać libheif
do obrazu Dockera) i rachunek kosztu za nią stojący.

### Ile to kosztuje pamięci

Zmierzone: szczyt RSS procesu przy przetworzeniu jednego zdjęcia razem z trzema
wariantami (gd, PHP 8.4, `ProcessUploadedImage`).

| wymiary | szczyt RSS | czas |
|---|---:|---:|
| 12 Mpx (4000×3000) | 161 MB | 1,3 s |
| 24 Mpx (5657×4243) | 254 MB | 2,4 s |
| 50 Mpx (8165×6124) | 452 MB | 4,6 s |

50 Mpx to limit z `media.max_megapixels`, czyli najgorszy dozwolony przypadek.
Worker ma 1024 MB (`.railway/railway.ts`), więc zapas jest ponad dwukrotny.

**`memory_limit` PHP tych liczb NIE WIDZI.** Przy 50 Mpx licznik PHP pokazuje
28 MB, a RSS 452 MB — libgd alokuje bitmapę poza licznikiem PHP. Wynikają z tego
dwie rzeczy, obie wcześniej zapisane w komentarzach odwrotnie:

- podnoszenie `PHP_WORKER_MEMORY_LIMIT` nie pomoże na brak pamięci przy
  dekodowaniu obrazu;
- taka awaria nie zgłosi „Allowed memory size exhausted" — proces zniknie,
  zabity przez OOM kontenera, bez śladu w Sentry. Objawem będzie zdjęcie
  w statusie `processing` (łapie to `ProcessUploadedImage::failed()`).

Podniesienie `max_megapixels` wymaga sprawdzenia tej tabeli wobec pamięci
kontenera i wobec `--memory` w `docker/entrypoint.sh`; pilnuje tego
`BudzetPamieciZdjecTest`.

## Prywatność

Usuwać:
- GPS;
- zbędny EXIF;
- dane urządzenia.

## Warianty

W tle (`ProcessUploadedImage`, lista w `config/kuking.php`,
`kuking.media.variants`):
- thumb 320 px;
- feed 960 px;
- large 1600 px.

Wszystkie trzy warianty są kodowane do WebP z jakością 82
(`ProcessUploadedImage::handle()`, `toWebp(quality: 82)`). AVIF jest
obsługiwanym formatem wejściowym, ale pipeline nie generuje wariantów AVIF
ani zestawu alternatywnych formatów wyjściowych.

### `podglad` — 640 px, robiony SYNCHRONICZNIE (issue #430)

Wgranie zdjęcia i publikacja wpisu to **jedno żądanie**, więc w chwili
pierwszego renderu strony wpisu wariantów z kolejki nie ma jeszcze żadnych —
nie z przeciążenia, tylko z kolejności. Autorka widziała przez to napis
„Twoje zdjęcie się jeszcze przygotowuje" zamiast swojego obiadu, i to nie
w rzadkim przypadku, tylko zawsze.

`App\Domain\Media\PodgladOdRazu` robi więc jeden wariant 640 px jeszcze
w `StoreUploadedImage`, zanim powstanie wiersz `media`. Trafia do
`metadata.variants.podglad` i do **publicznego** bucketu wariantów, tak samo
jak każdy inny wariant — czyli przekodowany do WebP, a więc bez EXIF-u.

**Nie pokazujemy oryginału i nie ma takiego planu.** Zmierzone 12.09.2026 dla
zdjęcia 4032×3024 (12,2 Mpx) z telefonu:

| co                | rozmiar     |
|-------------------|-------------|
| oryginał          | 6 438 105 B |
| wariant `podglad` |    62 974 B |
| wariant `feed`    |   177 404 B |

Oryginał waży 102 razy więcej od podglądu i niesie EXIF. Przeglądarka na
stronie wpisu pobiera tuż po publikacji **66,8 kB** łącznie (zmierzone
w Chromium, 320–414 px).

**Górny próg megapikseli** (`kuking.media.podglad.max_megapixels`, domyślnie
25) nie jest ostrożnością na zapas, tylko granicą pamięci kontenera web.
Zmierzone szczyty RSS procesu przy robieniu podglądu: 12,2 Mpx → 101 MB,
24,5 Mpx → 154 MB, 49,9 Mpx → 239 MB. libgd alokuje bitmapę **poza**
licznikiem PHP, więc `memory_limit` tego nie zatrzyma — proces znika zabity
przez OOM kontenera, bez wyjątku i bez śladu w dzienniku (patrz
`docker/php.ini`). Kontener web ma 1 GB na wszystkie procesy PHP-FPM naraz.
Powyżej progu podglądu nie ma i zdjęcie czeka na workera, który ma własną
pamięć (`QUEUE_MEMORY`).

Ustawienie progu na 0 wyłącza podgląd w całości, bez wdrożenia — serwis
zachowuje się wtedy tak jak przed #430.

`ProcessUploadedImage` **zachowuje** `podglad` przy zapisie swoich wariantów.
Nie jest to uprzejmość: `KasujZdjecie` chodzi po `metadata.variants` i nie ma
innego uchwytu do tego pliku, więc zgubienie wpisu zostawiłoby w publicznym
buckecie sierotę, której nie kasuje ani usunięcie wpisu, ani wymazanie konta.
Po przetworzeniu podgląd zostaje w `srcset` jako kandydat między `thumb`
(320) a `feed` (960) — zmierzone: telefon 320 px pobiera dzięki temu 66,8 kB
zamiast 178,6 kB.

Ten sam uchwyt jest potrzebny wariantom, które **dopiero powstają** (#601).
`metadata.variants` zapisuje się dopiero z ostatnim wariantem, razem ze
statusem `ready`, a `put()` idą do bucketu jeden po drugim — więc zadanie
przerwane w połowie (wyjątek albo `$timeout`, który ubija proces sygnałem,
bez `catch`) zostawiało pliki, których `KasujZdjecie` nie umiało nazwać.
Dlatego job zapisuje policzone z góry klucze wariantów **przed pętlą**, pod
`Media::METADANE_WARIANTY_W_TRAKCIE`, i `KasujZdjecie` sprząta także tę
listę; po sukcesie lista znika. Lista jest osobna od `variants`, bo
`wariantDoSerwowania()` pokazałaby po niej zdjęcie pod nazwą wariantu,
którego plik może jeszcze nie istnieć. Pilnuje tego
`tests/Feature/PrzerwanePrzetwarzanieNieZostawiaSierotyTest.php`.

### Co wolno pokazać

Bramką widoków **nie jest status wiersza**, tylko istnienie wariantu:
`Media::maWariantDoPokazania()`. Reguła brzmi: pokazujemy wyłącznie to, co
wyszło z naszego kodera. Oryginał nie jest wariantem, `Media::url()` go nie
zna, `MediaController` serwuje wyłącznie klucze z `wariantDoSerwowania()`,
a trasa `media.show` przyjmuje wyłącznie nazwy z białej listy. Status
`deleted` nie przechodzi nigdy, nawet z kompletem wariantów.

## Oryginał

Nie musi być publicznie serwowany. Można go trzymać krótko do reprocessingu zgodnie z retention policy.

## Adresem zdjęcia jest trasa aplikacji, nie plik w buckecie (W7-02)

```text
<img src="/zdjecia/{uuid}/{wariant}">
        ↓
MediaController
        ↓
DostepDoZdjecia  →  Policy treści NADRZĘDNEJ (Recipe/Post/CookedEvent/Profile/RecipeStep)
        ↓
302 → https://<bucket>/media/...?X-Amz-Signature=...   (do 5 minut; publiczne do 60)
```

**Bajty nie idą przez PHP.** Przez PHP idzie wyłącznie decyzja.

### Co to naprawia

Do W7-02 adresem zdjęcia był adres pliku w buckecie z własną domeną CDN.
Taki adres nikogo o nic nie pyta i nie przestaje działać. Kto raz go skopiował
— z podglądu źródła strony, z historii przeglądarki, z podglądu linku
w komunikatorze — otwierał zdjęcie także:

- po zablokowaniu,
- po cofnięciu obserwowania,
- po przełączeniu przepisu na prywatny,
- po ukryciu treści przez moderatora,
- po usunięciu wpisu.

Cała macierz widoczności obowiązywała stronę HTML i nie obowiązywała ani
jednego piksela. Najgorszy przypadek nazwał audyt wprost:
`recipes.source_scan_media_id` — skan odręcznej kartki z rodzinnym przepisem,
a na niej nazwiska, adresy i czyjeś pismo.

### Zdjęcie nie zna swojej widoczności i nie będzie znało

Nie ma na `media` kolumny `visibility` i nie ma jej dostać. Byłaby to siódma
kopia tej samej reguły, a powtarzającą się przyczyną błędów w tym repozytorium
jest „reguła istnieje poprawnie w jednej warstwie, a druga implementuje ją
inaczej".

Widoczność zdjęcia to widoczność treści, do której jest przypięte.
`App\Domain\Media\DostepDoZdjecia` odwraca więc listę rodziców
(tę samą co `KasujZdjecie::ODWOLANIA`, i test pilnuje, żeby były zgodne)
i woła ich Policy przez `Gate`. Nie ma tam ani jednego własnego warunku
widoczności. Rodzicom, którzy Policy nie mieli, dopisano ją delegującą do
przepisu albo do konta: `RecipeStepPolicy`, `ProfilePolicy`.

### Najszerszy rodzic wygrywa

To samo zdjęcie da się przypiąć do kilku treści — ktoś dodaje niedzielny rosół
jako wpis i to samo zdjęcie ustawia jako główne w przepisie. Przepuszcza więc
KTÓRYKOLWIEK rodzic. Gdyby wygrywał rodzic najwęższy, publiczny przepis
pokazywałby pustą ramkę tylko dlatego, że autor wrzucił to zdjęcie gdzieś
jeszcze — a bajty i tak byłyby jawne przez ten przepis. To nie jest
poluzowanie, tylko uczciwe nazwanie stanu faktycznego.

### Odmowa to 404, nie 403

I ma wyglądać dokładnie tak samo jak zdjęcie, którego nie ma — z treścią
odpowiedzi włącznie (pilnuje tego test). 403 na cudzym zdjęciu odpowiada na
pytanie, którego nikt nie miał prawa zadać: „czy taki plik istnieje". Przy
skanie kartki z nazwiskami sama ta odpowiedź jest już informacją.

Zdjęcie w stanie innym niż `ready` też jest 404, również dla właściciela
(AGENTS.md §7): dopóki `ProcessUploadedImage` nie przekodował pliku, w EXIF-ie
siedzi jeszcze lokalizacja GPS kuchni.

### Nagłówki

| kiedy | `Cache-Control` |
|---|---|
| zdjęcie publiczne, odczyt bez Cookie/Authorization i bez logowania | `public, max-age=1800` |
| wszystko inne, razem z odmową | `private, no-store` |

Wspólny cache wolno dopuścić wyłącznie dla odpowiedzi, która jest taka sama
dla każdego — czyli dla zdjęcia, które i tak zobaczyłby ktoś bez konta.
`max-age` wynosi połowę ważności podpisu. Decyzja właściciela z 20.09.2026
(#597): podpis zdjęcia publicznego trwa do 60 minut
(`kuking.media.public_signed_url_minutes`), chronionego do 5 minut
(`kuking.media.signed_url_minutes`). Odpowiedź zalogowanego zawsze ma
`private, no-store`, także przy publicznym zdjęciu. Podpis sprzed zmiany
widoczności nie jest unieważniany; z cache bajtów okno może sięgnąć 90 minut.
Kontrola, reguły i granice pomiaru: `docs/infra/CLOUDFLARE_CACHE_597_610.md`.

**Ten nagłówek chroni SAM ADRES, nie treść, do której on prowadzi — chyba że
adres niesie tę regułę dalej (audyt zewnętrzny N02).** Nagłówek `Cache-Control`
wyliczony wyżej trafia na odpowiedź 302 (`Location: <podpisany adres>`). Bez
dodatkowego kroku odpowiedź, którą R2 odda NA TEN podpisany adres — czyli
odpowiedź z bajtami zdjęcia, jedyna, którą pośrednik (proxy, CDN, cache
przeglądarki) miałby faktycznie co zapisywać — nie niesie żadnego zakazu:
`no-store` na przekierowaniu nie zabrania nikomu zapisać treści, na którą ono
wskazuje. `MediaController` przekazuje więc tę samą regułę jako
`ResponseCacheControl` w opcjach `temporaryUrl()` — to parametr GetObject S3,
który staje się `response-cache-control` w podpisanym zapytaniu i każe
serwerowi obiektów dołożyć ten nagłówek do SWOJEJ odpowiedzi.

Że AWS SDK potrafi zbudować taki podpisany adres, jest zmierzone lokalnie
(`ZdjecieObiektuDostajeTenSamNoStoreCoPrzekierowanieTest`, offline — signing
nie łączy się z siecią). Że Cloudflare R2 naprawdę uwzględnia ten parametr
w odpowiedzi na żądanie GET, **nie jest zmierzone z tego repozytorium** —
wymaga prawdziwego bucketu R2. Do czasu takiego pomiaru to jest zamknięcie
w aplikacji, analogiczne do samego W7-02: strona aplikacyjna robi, co może;
czy infrastruktura to honoruje, rozstrzyga wyłącznie test na produkcji albo
na stagingu z prawdziwym R2.

### Dlaczego 302, a nie strumień przez PHP

Jedno zdjęcie z feedu to kilkaset kilobajtów, a jedna strona feedu potrafi ich
mieć kilkadziesiąt. Proces PHP zajęty przepisywaniem obrazka to proces, który
nie obsługuje nikogo innego.

Wariantu „serwer oddaje plik po nagłówku od aplikacji" (`X-Accel-Redirect`)
nie ma i nie będzie: przed PHP stoi **Caddy, nie nginx**, a Caddy takiego
mechanizmu nie zna.

Na dysku lokalnym (praca lokalna, testy) nie ma ani S3, ani podpisów, więc tam
i tylko tam kontroler oddaje plik sam. Ma to osobny test, żeby środowisko
deweloperskie nie różniło się od produkcji akurat w miejscu, którego nikt by
wtedy nie sprawdzał.

### Czego ta zmiana NIE załatwia

Usunięcie `url` z dysku `r2_publiczne` nie zdejmuje własnej domeny z bucketu
po stronie Cloudflare. **Dopóki `cdn.kuking.pl` wskazuje bucket wariantów,
stare adresy działają dalej.** To jest ręczna czynność w panelu Cloudflare —
**issue #120** — i nie da się jej ani wykonać, ani sprawdzić z poziomu kodu.
Do tego czasu W7-02 jest naprawione w aplikacji i nie jest naprawione
w infrastrukturze.

### Co jeszcze zostało otwarte

Krok 1 świadomie nie optymalizuje. Każde żądanie zdjęcia to dziś kilka zapytań
o rodziców. Przy stronie feedu z kilkudziesięcioma zdjęciami to widać —
i dopiero pomiar z produkcji ma rozstrzygnąć, czy potrzebny jest cache decyzji,
czy `Cache-Control` wystarczy.

## Moderacja

Automatyka może flagować, ale nie powinna samodzielnie permanentnie banować bez odpowiedniej polityki.

## Kasowanie: plik, wiersz i cache CDN-u

Skasowanie obiektu w buckecie **nie jest** skasowaniem go z internetu.
Cloudflare ostrzega wprost w dokumentacji spójności R2: przy włączonym cache
na własnej domenie usunięty obiekt bywa dalej serwowany aż do wygaśnięcia albo
wypchnięcia z cache.

Dla miniatury to niedogodność. Dla wymazania konta po karencji, żądania z RODO,
decyzji moderacyjnej albo zdjęcia wgranego przez pomyłkę to jest awaria
prywatności — serwis mówi „skasowane", a plik nadal się otwiera pod tym samym
adresem (audyt G-03).

`KasujZdjecie` zbiera więc publiczne adresy wariantów **przed** skasowaniem
plików (potem nie ma z czego ich zbudować) i zleca `PurgePublicMediaCache`.

Po W7-02 zbiera **dwa rodzaje adresu na wariant**:

- adres pliku w buckecie — istnieje już tylko dla dysku `r2_legacy`, jedynego
  z własną domeną. Dla `r2_publiczne` `Storage::url()` rzuca wyjątek i nie ma
  tam czego czyścić, bo bez domeny nie ma cache;
- adres **trasy** `media.show` — dzisiejszy adres zdjęcia. Dla treści
  chronionej odpowiedź ma `private, no-store` i w żadnym cache nie leży, ale
  dla treści naprawdę publicznej przekierowanie wolno trzymać we wspólnym
  cache.

Zadanie jest osobne, bo cudze API bywa niedostępne, a kasowanie zdjęcia nie
może się przez to nie udać: awaria Cloudflare zatrzymałaby wtedy wymazywanie
kont. Jest idempotentne — czyszczenie adresu, którego w cache nie ma, to
poprawna operacja bez skutku.

Brak konfiguracji (`CLOUDFLARE_ZONE_ID`, `CLOUDFLARE_PURGE_TOKEN`) wyłącza
czyszczenie, ale **głośno**, wpisem w logu. Ciche wyłączenie wygląda dokładnie
tak samo jak czyszczenie, które działa. Po wyczerpaniu prób w logu zostają
konkretne adresy — bez nich nie da się tego dokończyć ręcznie, a przy wymazaniu
konta ktoś dokończyć musi.

## Storage

Kod biznesowy korzysta z Laravel Filesystem.
### Sterownik dysków R2 to `r2`, nie `s3` (issue #120)

Wbudowany sterownik `s3` wysyła `x-amz-acl` przy **każdym** zapisie, także
wtedy, gdy nikt o widoczność nie prosił — `AwsS3V3Adapter::upload()` liczy ACL
zawsze i przy braku widoczności wypada `private`. Na R2 ten nagłówek jest
nieobsługiwany dla `PutObject`, a Cloudflare nie gwarantuje, jak na niego
zareaguje. Zależała od tego prywatność oryginałów, czyli plików z pełnym
EXIF-em.

Dyski `r2`, `r2_publiczne`, `r2_legacy` i `r2_eksporty` chodzą więc na własnym
sterowniku (`app/Support/Storage/R2Adapter.php`, rejestrowanym w
`AppServiceProvider`), który:

- zapisuje przez `PutObject` bez `ACL` i bez `Grant*` (powyżej 16 MB —
  multipartem, też bez ACL: paczki RODO bywają większe niż zdjęcie),
- kopiuje bez `GetObjectAcl`,
- rzuca wyjątek przy `setVisibility()`, `getVisibility()` i przy zapisie
  z jawnie podaną widocznością — bo ciche nic byłoby powtórzeniem błędu,
  który to zgłoszenie naprawia.

Poza tym jest to ten sam dysk co dotąd: ten sam `S3Client`, te same `url()`
i `temporaryUrl()`. Kod biznesowy nic o tej zmianie nie wie i nie musi.

Że prawdziwy bucket R2 przyjmie takie żądanie, **nie jest** sprawdzone z tego
repozytorium — patrz `docs/infra/BRAMKA_R2.md`.


Dzięki temu:
```text
Railway/S3-compatible storage
→ Cloudflare R2
```
jest migracją infrastruktury, a nie przepisywaniem domeny.
