## D-180 · Widoki pokazują wariant, nie status — i nigdy oryginału

**Data:** 12 września 2026 · PR #451 · issues #430, #432 · Status: **obowiązuje**

### Kontekst

Zgłoszenie właściciela: „pisałem że trzeba jakoś od razu im pokazywać zdjęcie które
dodali, a nie napis że jest przetwarzane. […] Starzy ludzie nie czytają i będzie
panika co się stało".

Przyczyna nie była w wydajności, tylko **w kolejności**: wgranie zdjęcia i publikacja
wpisu to **jedno żądanie**, więc w chwili pierwszego renderu strony zadanie w tle nie
mogło policzyć ani jednego wariantu. Komunikat „Twoje zdjęcie się jeszcze
przygotowuje" nie był rzadkim widokiem na wypadek opóźnienia — **był tym, co po
opublikowaniu wpisu widziała każda osoba, zawsze**.

### Co obowiązywało do tego dnia

„Widoki nigdy nie pokazują zdjęcia, które nie jest `ready`". Reguła była zapisana
przez **stan wiersza**, a chroniła **bajty**: żeby na stronę nie trafił plik przysłany
przez użytkownika, z nietkniętym EXIF-em. `ready` było skrótem na „ten plik przeszedł
już przez nasz koder".

### Dlaczego skrót przestał być prawdziwy

Odkąd `StoreUploadedImage` robi wariant `podglad` synchronicznie, istnieje plik bez
EXIF-u, a wiersz stoi na `pending`. Reguła po staremu kazała ukryć plik, który **jest
bezpieczny**.

### Decyzja

Pokazujemy wyłącznie to, co wyszło z **naszego kodera** — czyli wariant zapisany
w `metadata.variants`. **Oryginał nie jest wariantem** i nie ma drogi, którą mógłby
tam trafić: `url()` go nie zna, trasa `media.show` ma białą listę nazw, status
`deleted` nie przechodzi nigdy. Reguła jest **węższa** od poprzedniej — mówi
o bajtach, nie o etykiecie — i nie ma w niej wyjątku dla właściciela.

### Dlaczego nie pokazujemy oryginału, nawet za bramką dostępu

Bramka odpowiada na pytanie **kto patrzy**, a problemem jest **co dostaje**. Oryginał
niesie EXIF (aparat, data, a w wierszach sprzed D-023 także GPS) i **6 438 105 B**.
Wariant rozbraja oba zarzuty naraz i waży **62 974 B** — **102× mniej**.

### Cena, przyjęta świadomie

**~450 ms w żądaniu publikacji** (zmierzone end-to-end: 400–471 ms wobec 19–29 ms bez
podglądu) i **próg 25 Mpx**, powyżej którego podglądu nie robimy. Próg to granica
pamięci kontenera web, nie ostrożność: libgd alokuje bitmapę **poza** licznikiem PHP,
więc `memory_limit` jej nie zatrzyma — proces znika zabity przez OOM, bez wyjątku
i bez śladu w dzienniku. Zmierzone: 12,2 Mpx → 101 MB, 24,5 Mpx → 154 MB,
49,9 Mpx → 239 MB, przy 1 GB na wszystkie procesy PHP-FPM naraz.

**Te 450 ms porównuje się do złej rzeczy, jeśli zestawić je z 19 ms.** Zanim serwer
cokolwiek zrobi, 6,14 MB musi do niego dojechać — to 51,5 megabita. Żeby sam transfer
zmieścił się w 400 ms, trzeba by **~130 Mbps w górę**; przy typowym LTE trwa on
kilka sekund.

### Skutek uboczny na korzyść

Po przetworzeniu `podglad` zostaje w `srcset`, więc telefon 320 px pobiera
**66,8 kB zamiast 178,6 kB**.

📄 `app/Domain/Media/PodgladOdRazu.php` · `app/Models/Media.php` ·
`docs/MEDIA_PIPELINE.md` · `PrzygotowywanieZdjeciaWpisuTest` · D-023 · issue #448
