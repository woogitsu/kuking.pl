# Bramka R2 przed wystawieniem `cdn.kuking.pl`

**Zgłoszenie:** issue #120 (P0, `typ: bezpieczeństwo`), audyt fali 2 — G-02 i G-11.
**Ostatnia aktualizacja tego pliku:** 2026-09-17.
**Stan:** strona aplikacyjna ZAMKNIĘTA · część serwerowa **do uruchomienia jedną komendą** (§2) · część panelowa **NIEPRZEJŚCIONA** — kroki krok po kroku w §2a.

> **Odczyt produkcji 17.09.2026 (Railway MCP, same nazwy zmiennych, bez
> wartości): `KUKING_R2_PUBLICZNE_ADRESY` na produkcji NIE ISTNIEJE.** Krok 4
> z §2a nie został więc wykonany, a bramka uruchomiona dziś na produkcji da
> w sprawdzeniach 7 i 8 `NIE WIEMY` i obleje. Nie znaczy to, że bucket jest
> otwarty — znaczy, że nikt o to nie zapytał.

> **Twarda zasada, dopóki komenda z §2 nie przechodzi i tabela w §3 nie jest wypełniona na zielono:**
> nie wystawiaj produkcyjnego bucketu mediów pod `cdn.kuking.pl`.

Ten plik ma **datę w nagłówku i datę w każdym wierszu tabeli**, bo konfiguracja
bucketu w Cloudflare może się zmienić bez jednej linijki w tym repozytorium.
Dowód bez daty nie mówi nic o dzisiejszym stanie.

---

## 1. Co jest zamknięte w kodzie (i czego to NIE dowodzi)

### Zapis do R2 bez `x-amz-acl`

Cloudflare R2 nie implementuje S3-owych ACL na obiektach: `x-amz-acl` jest
w tabeli zgodności oznaczony jako **nieobsługiwany** dla `PutObject`,
a `GetObjectAcl` i `PutObjectAcl` nie istnieją tam wcale. Publiczność w R2 jest
cechą **bucketu**, nie obiektu.

Wbudowany sterownik Laravela `s3` wysyłał ACL i tak — przy **każdym** zapisie.
`League\Flysystem\AwsS3V3\AwsS3V3Adapter::upload()`:

```php
$acl = $options['params']['ACL'] ?? $this->determineAcl($config);
$this->client->upload($this->bucket, $key, $body, $acl, $options);
```

`determineAcl()` przy braku podanej widoczności zwraca `private`. Zdjęcie
trzeciego argumentu z `put()` (poprzedni krok, G-01) usunęło więc `public-read`,
ale **nie usunęło nagłówka**. Prywatność oryginałów — plików z pełnym EXIF-em,
czyli ze współrzędnymi GPS kuchni — zależała od tego, jak cudza implementacja
zareaguje na nagłówek, którego nie obsługuje. Cloudflare tego nie gwarantuje.

**Rozwiązanie:** własny sterownik dysku `r2`:

| plik | co robi |
|---|---|
| `app/Support/Storage/R2Adapter.php` | adapter Flysystem: `PutObject` (albo multipart) bez `ACL` i bez `Grant*`, `copy` bez `GetObjectAcl`, `setVisibility`/`visibility` rzucają wyjątek |
| `app/Support/Storage/DyskR2.php` | fabryka dysku — odpowiednik `FilesystemManager::createS3Driver()`, ten sam `S3Client` i ta sama klasa dysku, więc `url()` i `temporaryUrl()` działają jak dotąd |
| `app/Providers/AppServiceProvider.php` | `Storage::extend('r2', …)` |
| `config/filesystems.php` | `r2`, `r2_publiczne`, `r2_legacy`, `r2_eksporty` mają `driver => 'r2'` |
| `tests/Feature/ZapisDoR2BezAclTest.php` | test regresyjny na **prawdziwym, podpisanym żądaniu HTTP** |

Dlaczego adapter, a nie osobna wąska usługa magazynu (issue dopuszczało obie
drogi): `docs/MEDIA_PIPELINE.md` już rozstrzyga, że kod biznesowy korzysta
z Laravel Filesystem — dzięki temu dysk lokalny w testach i R2 na produkcji to
jedna ścieżka kodu. Własna usługa wokół `putObject/getObject` wymagałaby
przepisania każdego miejsca zapisu i odczytu (zdjęcia, warianty, paczki RODO,
`/health`, komenda przenosząca buckety), a przy okazji zabrałaby `Storage::fake()`
w testach i `temporaryUrl()` w `MediaController`. ACL trzeba wyciąć z jednego
miejsca — z żądania `PutObject`.

### Jak to jest sprawdzane

`Storage::fake()` ani mock na poziomie polecenia AWS **nie pokazują ani jednego
nagłówka**: nagłówki powstają dopiero przy serializacji żądania. Test podstawia
więc własny `handler` klienta S3 — ostatnie ogniwo stosu AWS SDK, już za
middleware podpisującym — i patrzy na kompletne żądanie HTTP. Nic nie wychodzi
do sieci.

Sprawdzone przez sam test: powrót ACL oblewa 6 z 12 przypadków, w tym cały
potok zdjęcia. Osobny przypadek (`test_wbudowany_sterownik_s3_wysyla_acl_…`)
dowodzi, że dawny sterownik **naprawdę** ten nagłówek wysyła — bez tego reszta
asercji przechodziłaby zawsze i nie pilnowałaby niczego.

### Czego kod nie dowodzi

Że prawdziwy bucket przyjmie takie żądanie, i że oryginał naprawdę nie jest
publiczny. **Tego z PHP nie widać.** Testy nie mają dostępu do panelu
Cloudflare, a to on stawia granicę: własna domena bucketu, `r2.dev`, klucze API.
Stąd §2 i §3.

### 1a. Czy ta bramka w ogóle MIERZY — sprawdzone na prawdziwym serwerze S3 (18 IX 2026)

> **To nie jest odbiór R2 i nie zastępuje ani jednego wiersza z §3.** To jest
> odpowiedź na inne pytanie: czy werdykty tej komendy biorą się z odpowiedzi
> prawdziwego serwera S3, czy z konfiguracji i atrap. Narzędzie, które nigdy
> nie powiedziało „NIE", nie jest bramką — jest ozdobą.

Komendę uruchomiono na **MinIO w kontenerze** (wydanie `2025-09-07`), postawionym
w miejsce R2: dwa osobne buckety (oryginały i warianty), prawdziwy obiekt
z blokiem EXIF pod kluczem `incoming/`, trzy warianty bez EXIF-u, adresowanie
przez host (`use_path_style_endpoint = false`), sterownik `r2` z repozytorium.

**Werdykty wyszły z serwera, nie z konfiguracji.** Sprawdzenia 1, 2, 3, 4, 5,
9, 10 i 11 odpowiedziały `TAK` na podstawie prawdziwych odpowiedzi HTTP
(m.in. **403** na adres wariantu bez sygnatury). Sprawdzenie 11 zapisało
i skasowało prawdziwy obiekt bez `x-amz-acl`. Przebieg zakończył się
**kodem 1** — `NIEPRZEJŚCIONA, 0 oblanych, 4 niesprawdzone` — i to też jest
prawidłowe zachowanie, bo:

| Punkt | Werdykt | Dlaczego tak, i czy to wada narzędzia |
|---|---|---|
| 7, 8 | `NIE WIEMY` | nie zadeklarowano `KUKING_R2_PUBLICZNE_ADRESY`. Adres niezapytany nie jest dowodem — **zachowanie poprawne** |
| 12 | `NIE WIEMY` | endpoint MinIO nie ma kształtu `<konto>[.<jurysdykcja>].r2.cloudflarestorage.com`. Komenda **nie orzekła** o jurysdykcji mimo udanego odczytu prawdziwego obiektu — dokładnie tak, jak opisuje `LOKALIZACJA_DANYCH_R2.md` §3. **Zachowanie poprawne**; sprawdzenia 12 **nie da się** wykonać na czymkolwiek innym niż R2 |
| 6 | `NIE WIEMY` | droga „wirtualny host" jest w kodzie zapisana na stałe jako `https://`, a MinIO w tej próbie stał na `http`. **Na R2 endpoint jest zawsze `https`, więc produkcji to nie dotyczy** — to artefakt zastępnika, nie usterka. Droga „ścieżkowy" odpowiedziała **HTTP 403** |

**Cztery kontrole ujemne — każda psuła jedną rzecz PO STRONIE SERWERA, nie
w kodzie, i każda zaczerwieniła dokładnie ten punkt, który miała:**

| Sabotaż na buckecie | Punkt | Przed | Po | Po przywróceniu |
|---|---|---|---|---|
| bucket wariantów udostępniony anonimowo | **5** | TAK | **NIE** („ALARM: bucket wariantów oddaje pliki BEZ podpisu") | TAK |
| plik z prefiksu `incoming/` podrzucony do bucketu wariantów | **9** | TAK | **NIE** („leży tam 1 plik(ów) z prefiksu oryginałów") | TAK |
| wariant podmieniony na plik z blokiem EXIF | **10** | TAK | **NIE** („w wariancie siedzi blok EXIF") | TAK |
| oryginał przeniesiony spod klucza z bazy | **1** | TAK | **NIE** („Pliku nie ma pod kluczem z bazy") | TAK |

Czego to **nie** dowodzi: niczego o Cloudflare. MinIO mówi tym samym
protokołem S3, ale nie ma ani jurysdykcji, ani `r2.dev`, ani Bucket Locks.
Tabela w §3 pozostaje pusta i tylko jej wypełnienie zamyka #120.

---

## 2. Część serwerowa: jedna komenda

```
railway ssh -- php artisan kuking:bramka-r2 --zapis
```

Dwanaście ręcznych punktów to bramka, której nikt nie przejdzie dwa razy:
pierwszy raz z zapałem, drugi nigdy. A konfiguracja bucketu może się zmienić
bez jednej linijki w tym repozytorium, więc bramka nie jest jednorazowa.

`kuking:bramka-r2` robi z serwera wszystko, co da się zrobić bez panelu
Cloudflare — prawdziwymi żądaniami do prawdziwego R2, na **prawdziwym
zdjęciu z bazy** (najnowsze gotowe albo wskazane przez `--media=<uuid>`).
Odpowiedź 404 na wymyślony klucz nie mówi nic o tym, czy bucket jest
publiczny, więc komenda nigdy nie pyta o klucz, którego nie ma.

| # | Co komenda sprawdza | Odpowiada punktowi z §3 |
|---|---|---|
| 1 | oryginał widoczny przez API S3 z serwera | 3 |
| 2 | wariant widoczny przez API S3 | 1 (część serwerowa) |
| 3 | worker wytworzył `thumb`, `feed` i `large` | 9 |
| 4 | **podpisany** adres wariantu oddaje 200 | 1 |
| 5 | **ten sam adres bez podpisu** jest odrzucany | 2 |
| 6 | oryginał nie do pobrania bez podpisu — ścieżkowo i przez wirtualny host | 2 (endpoint konta) |
| 7 | **oryginał odmawia się pod KAŻDYM zadeklarowanym publicznym adresem** | 2 (własna domena, `r2.dev`) i 4 |
| 8 | **wariant odmawia się pod tymi samymi adresami** (W7-02) | §4, pierwsza pozycja |
| 9 | w publicznym buckecie nie ma ani jednego klucza `incoming/` | 5 |
| 10 | wariant nie niesie bloku EXIF | 10 (część o wariancie) |
| 11 | `PutObject` przechodzi bez `x-amz-acl` (tylko z `--zapis`) | 6 |
| 12 | **endpoint R2 jest endpointem jurysdykcji UE** (issue #619) | 13 |

### Sprawdzenie 12 pyta o co innego niż cała reszta — i to jest celowe

Sprawdzenia 1–11 pytają, czy zdjęcia nie wychodzą do niepowołanych. Dwunaste
pyta, **gdzie te zdjęcia w ogóle leżą** — bo
`resources/legal/polityka-prywatnosci.md` mówi użytkownikom, że w Unii
Europejskiej, a do 17.09.2026 nic w tym repozytorium tego nie sprawdzało.

Dowód jest w kształcie `AWS_ENDPOINT` i da się go zebrać z serwera, bez panelu.
Bucket z ograniczeniem jurysdykcyjnym jest osiągalny **wyłącznie** przez
endpoint `https://<KONTO>.<JURYSDYKCJA>.r2.cloudflarestorage.com`, więc udany
odczyt prawdziwego obiektu przez endpoint **bez** tego segmentu dowodzi, że te
buckety jurysdykcji nie mają. Location Hint (`weur`/`eeur`) to co innego —
Cloudflare nazywa go „best effort", nie gwarancją — i z endpointu go nie widać.

Pełny wywód, granice tego dowodu, lista do odczytania w panelu i oba warianty
wyjścia: **`docs/infra/LOKALIZACJA_DANYCH_R2.md`**.

To sprawdzenie **liczy się do werdyktu**, jak każde inne. Zdanie w polityce
prywatności jest obietnicą złożoną człowiekowi; „nie wiemy" nie jest tu
łagodniejsze od „nie".

### Sprawdzenia 7 i 8 pytają WYŁĄCZNIE o to, co im zadeklarujesz

To jest jedyna rzecz w tej komendzie, którą da się zrozumieć na odwrót,
i dlatego stoi tu osobnym nagłówkiem.

Sprawdzenie 6 pyta **endpoint konta S3** — a tym adresem nikt z zewnątrz nie
chodzi i on jest prywatny z definicji, bo bez podpisu nie oddaje nic.
Przeglądarka chodzi **własną domeną** (`cdn.kuking.pl`) albo **`r2.dev`**.
Tych dwóch adresów nie ma w tym repozytorium NIGDZIE: klucz `url` został
z dysków mediów świadomie zdjęty (W7-02), a panel Cloudflare jest poza
zasięgiem PHP. Bramka przed 11.09.2026 pytała więc o adres, którym nikt nie
chodzi, milczała o adresie, którym chodzi przeglądarka, i kończyła się
zielono — ta sama klasa usterki, przed którą sama ostrzega w punkcie 3 niżej.

Dlatego publiczne adresy trzeba jej **wypisać**:

```
KUKING_R2_PUBLICZNE_ADRESY=https://cdn.kuking.pl,https://pub-abc123.r2.dev
```

**Wypisz tam też adresy, które mają być WYŁĄCZONE.** To nie pomyłka —
wyłączenie `r2.dev` jest udowodnione dopiero wtedy, gdy spod
`pub-….r2.dev` przyszła odmowa. Adres, którego nikt nie zadeklarował, nie
zostanie zapytany, a niezapytany adres nie jest dowodem na nic. Pusta lista
daje `NIE WIEMY` i **oblewa** bramkę.

Żądanie idzie zwykłym `GET`-em, **bez podpisu i bez nagłówka autoryzacji**,
po klucz PRAWDZIWEGO oryginału z kolumny `media.object_key` — dokładnie tak,
jak zrobi to ktoś, kto w publicznym adresie wariantu podmienił `media/`
na `incoming/`. Kod odpowiedzi z każdego adresu idzie na wyjście dosłownie,
w osobnej linii, bo to jest cała treść dowodu. Sama ścieżka do oryginału na
wyjście **nie** idzie — wypisywany jest host i kod.

**Odmową jest kod 400 albo wyższy, nie „cokolwiek poza 200".** Issue #120
żąda dosłownie `403/404`, i słusznie: `301` na inny host nie mówi „nie
wolno", tylko „plik jest tam" — a bramka chodzi z `withoutRedirecting()`
i sama za tym wskazaniem nie idzie. Wszystko poniżej 400 (`206`, `301`,
`304`) liczy się więc jak wystawienie; który to dokładnie kod, widać
w linii dowodowej. Sprawdzenia 5 i 6 (endpoint konta, bez podpisu) zostały
przy dawnym `!== 200` — tam przekierowanie jest nierealne, bo bez sygnatury
R2 nie ma gdzie odsyłać, a zmiana ich semantyki nie jest częścią tej
poprawki.

Cztery rzeczy, o których warto wiedzieć, zanim się ją uruchomi:

1. **Bez `--zapis` bramka NIE JEST domknięta.** Punkt 11 zostaje wtedy
   niesprawdzony, a niesprawdzony liczy się jak oblany. `--zapis` dokłada
   jeden plik tekstowy w prefiksie `bramka/` i kasuje go po odczycie —
   komenda mówi o tym przed zrobieniem tego i sprząta także wtedy, gdy
   sprawdzenie po drodze rzuci wyjątkiem.
2. **„Nie wiemy" nigdy nie znaczy „jest dobrze".** Brak odpowiedzi z sieci,
   nieudane listowanie bucketu albo pusta lista publicznych adresów daje
   `NIE WIEMY` i **oblewa** bramkę. Bez tego komenda meldowałaby zamknięty
   bucket w sytuacji, w której nikt niczego nie odmówił, bo żądanie nie
   doszło.

   Jeden wyjątek jest w tym rozstrzygnięty osobno i warto o nim wiedzieć:
   host, który **nie odpowiedział wcale** (na przykład własna domena, której
   nie ma w DNS-ie), liczy się jako odmowa — ale **tylko wtedy**, gdy w tym
   samym przebiegu udało się dojść do R2 żądaniem z podpisem. Bez tej
   kontroli dodatniej kontener bez wyjścia na świat przechodziłby bramkę
   zawsze: odmawiałaby sieć, nie Cloudflare, a raport brzmiałby identycznie
   jak przy prawdziwie zamkniętym buckecie.
3. **Adres bez protokołu nie jest adresem.** Panel Cloudflare pokazuje
   publiczne adresy bucketu bez `https://` (`cdn.kuking.pl`,
   `pub-abc123.r2.dev`). Przepisany tak do zmiennej wpis nie ma hosta, żądanie
   z niego nie powstaje, a bramka do 17.09.2026 liczyła to milczenie jak
   odmowę — i kończyła się **kodem 0**, nie zapytawszy o nic. Dziś taki wpis
   daje `NIE WIEMY`, oblewa i jest wypisany dosłownie, żeby dało się go
   poprawić. Pisz `https://cdn.kuking.pl`, nie `cdn.kuking.pl`.
4. **Na dysku lokalnym komenda odmawia działania.** Lokalnie każde
   sprawdzenie wychodzi ładnie — nie ma bucketu ani publicznego adresu,
   więc „oryginał nie jest publiczny" jest prawdą, która o R2 nie mówi
   nic. Zielona bramka na dysku lokalnym byłaby narzędziem, które melduje
   sukces, nie robiąc nic; to jest ta sama klasa usterki co martwy
   `kuking.media_disk` czy `MAIL_MAILER=log` na produkcji. **Dotyczy to
   obu dysków:** od 17.09.2026 komenda odmawia także wtedy, gdy sterownikiem
   `r2` nie jest dysk WARIANTÓW. Wcześniej dysk lokalny w tej roli
   przepuszczał ją do sprawdzeń 2, 8, 9 i 10, które z tego dysku czytają —
   a sprawdzenie 9 listowało wtedy pusty katalog lokalny i odpowiadało TAK.

Czego komenda **nie robi i nie będzie robić**: nie kasuje niczyich zdjęć
(punkt 11 z §3 wymagałby usunięcia czyjegoś zdjęcia z produkcji), nie
przeczyta za Ciebie z panelu, jakie adresy publiczne bucket ma włączone
(punkt 4 — potrafi tylko udowodnić, że zadeklarowany adres odmawia), nie
wgra zdjęcia z aparatu (punkty 7 i 8) i nie podmieni sekretu, żeby zobaczyć
ścieżkę błędu (punkt 12). Punkty 4, 7, 8, 11 i 12 z §3 komenda wypisuje na
końcu jako pozostałe do zrobienia — zamiast udawać, że ich nie ma.

Pilnuje jej `tests/Feature/BramkaR2MowiPrawdeTest.php` — 28 przypadków,
z których **przejście sprawdza siedem, a nieprzejście dwadzieścia jeden**, bo
komenda, która melduje przejście, nie sprawdziwszy niczego, jest gorsza od
jej braku. Jeden z nich (`test_bramka_pyta_kazdy_zadeklarowany_adres_…`)
nie patrzy na wyjście komendy, a na to, co naprawdę poszło w sieć: pod
każdym zadeklarowanym adresem musi być `GET` po klucz prawdziwego oryginału,
bez nagłówka `Authorization`. Pozostałe testy przeszłyby także wtedy, gdyby
ktoś zamienił żądania na `return true`.

---

## 2a. Krok po kroku dla właściciela — co zrobić w panelu i czym to potwierdzić

Ta sekcja jest po to, żeby nie trzeba było czytać całego pliku. Osiem kroków,
w tej kolejności. Po każdym jest napisane, **co ma wyjść** — jeśli wyszło co
innego, nie idź dalej.

**Czego potrzebujesz:** dostępu do panelu Cloudflare, prawa do zmiany
zmiennych środowiskowych serwisu (Railway) i `railway ssh`.

1. **Wejdź do panelu R2** → bucket **oryginałów** (`AWS_BUCKET`) →
   **Settings → Public access**. Przepisz sobie na boku dwie rzeczy: czy
   `r2.dev` jest **Enabled** czy **Disabled**, i jaki dokładnie ma adres
   (`pub-….r2.dev`) — adres widać także wtedy, gdy jest wyłączony. Przepisz
   też wszystkie **Custom Domains**, jeśli są.
2. **Wyłącz na tym buckecie `r2.dev`** i **usuń z niego każdą custom domain.**
   W buckecie oryginałów leżą pliki z pełnym EXIF-em, czyli ze
   współrzędnymi GPS kuchni, w której zrobiono zdjęcie. **Potwierdzenie:**
   panel pokazuje `r2.dev` jako **Disabled** i pustą listę custom domains.
3. **To samo dla bucketu wariantów** (`AWS_PUBLIC_BUCKET`). Po audycie W7-02
   warianty też nie mają publicznego adresu — adresem zdjęcia jest trasa
   aplikacji `/zdjecia/{id}/{wariant}`, która pyta Policy i przekierowuje na
   adres podpisany na kilka minut. **Jeśli `cdn.kuking.pl` wskazuje dziś ten
   bucket, zdejmij ją tutaj** — zdjęcie klucza `url` z konfiguracji tego nie
   zrobiło i nigdy nie robiło. **Potwierdzenie:** jak w kroku 2.
4. **Wpisz do zmiennych środowiskowych serwisu wszystkie adresy z kroku 1** —
   te, które właśnie wyłączyłeś, też, i to jest sedno:

   ```
   KUKING_R2_PUBLICZNE_ADRESY=https://cdn.kuking.pl,https://pub-abc123.r2.dev,https://pub-def456.r2.dev
   ```

   **Z `https://` na początku każdego z nich.** Panel pokazuje te adresy bez
   protokołu; przepisane dosłownie nie dadzą się zamienić w żądanie i bramka
   je odrzuci, wypisując, który wpis poprawić.

   Bramka pyta tylko o to, co jest w tej zmiennej. Adres pominięty tutaj nie
   został sprawdzony — i bramka nie ma jak się o nim dowiedzieć.
   **Potwierdzenie:** nie ma tu potwierdzenia panelowego; to krok 6 powie,
   czy zmienna doszła.
5. **Sprawdź, że na tym środowisku jest co najmniej jedno gotowe zdjęcie.**
   Wgraj jedno przez formularz, jeśli nie ma. Bramka nigdy nie pyta o klucz
   wymyślony: **404 na nieistniejącym kluczu nie mówi nic o tym, czy bucket
   jest publiczny.** **Potwierdzenie:** w tabeli `media` jest wiersz ze
   `status = ready` i niepustym `object_key`.
6. **Uruchom bramkę:**

   ```
   railway ssh -- php artisan kuking:bramka-r2 --zapis
   ```

   **Potwierdzenie:** ostatnia linia mówi `Część serwerowa bramki PRZESZŁA
   w całości` i **kod wyjścia jest 0** (`echo $?`). Jeśli jest 1, przeczytaj,
   który punkt dostał `NIE` albo `NIE WIEMY` — komenda mówi po polsku, co
   z tym zrobić. Przy punktach 7 i 8 zobaczysz **po jednej linii na każdy
   zadeklarowany adres, z dosłownym kodem odpowiedzi**; tak wygląda dowód.
   Sprawdź przy okazji, że liczba tych linii zgadza się z liczbą adresów
   z kroku 1 — brakująca linia znaczy, że któryś adres nie doszedł do
   zmiennej z kroku 4.
7. **Wklej wyjście komendy do §3 tego pliku**, do kolumn *wynik* i *data* —
   razem z tymi liniami z kodami odpowiedzi. **Z datą**, bo konfiguracja
   bucketu w Cloudflare zmienia się bez jednej linijki w tym repozytorium
   i dowód bez daty nie mówi nic o dzisiejszym stanie.
8. **Zrób ręcznie to, czego bramka nie umie** — punkty 7, 8, 11 i 12 z §3
   (plik ~14,9 MB, cztery prawdziwe próbki formatów, kasowanie zabierające
   wszystkie warianty, ścieżka błędu przy złym sekrecie). To jeden przebieg
   przez formularz **na środowisku testowym**, nie na produkcji.

Dopiero gdy krok 6 daje kod 0, a §3 jest wypełniona na zielono z datą,
`cdn.kuking.pl` wolno postawić przed bucketem mediów.

---

## 3. Część panelowa — do wykonania po stronie właściciela

**Gdzie:** panel Cloudflare R2 plus jeden przebieg zapisu i odczytu na
środowisku staging podłączonym do prawdziwego R2.

**Które buckety** — nazwy są wartościami, więc rozstrzyga to, do której
zmiennej trafia która nazwa (`config/filesystems.php`):

| Bucket | Zmienna czytana przez kod | Zmienna w panelu Railway | Dysk | Czego dotyczy poniżej |
|---|---|---|---|---|
| `kuking-oryginaly` | `AWS_BUCKET` | `R2_BUCKET` | `r2` | punkty 2, 3, 4, 10, 11 |
| `kuking-media` | `AWS_PUBLIC_BUCKET` | `R2_PUBLIC_BUCKET` | `r2_publiczne` | punkty 1, 5, 9, 10, 11 |
| `kuking-eksporty` | `AWS_EXPORTS_BUCKET` | `R2_EXPORTS_BUCKET` | `r2_eksporty` | poza tą bramką — paczki RODO, ale ten sam wymóg: żadnej domeny, `r2.dev` wyłączone |

> **Ta tabela jest tu, bo trzy dokumenty opisywały trzy różne układy bucketów.**
> `DEPLOYMENT_RUNBOOK.md` §2.1 kazał utworzyć **jeden** bucket `kuking-media`,
> `.railway/railway.ts` podawał **trzy** zmienne, a ten plik wymieniał **dwa**
> pod jeszcze innymi nazwami. Żadnego z tych opisów nie dało się wykonać do
> końca bez zgadywania. Rozstrzyga kod: `config/filesystems.php` czyta
> `AWS_BUCKET`, `AWS_PUBLIC_BUCKET`, `AWS_EXPORTS_BUCKET` (plus `AWS_LEGACY_BUCKET`
> dla starego, jednego bucketu i `AWS_KOPIE_BUCKET` dla kopii bazy — ten ostatni
> z **osobnym poświadczeniem tylko do odczytu**).

**Czego potrzebujesz:** dostępu do konta Cloudflare, klucza API S3 do R2
(`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`) i `railway ssh` do serwisu.
Z kontenera agenta AI ani jeden z tych punktów nie jest wykonalny — nie ma tam
ani konta Cloudflare, ani bucketu.

Wypełnij kolumny **wynik** i **data**. Puste = nieprzejście.

**Punkty 1, 2, 3, 5, 6, 9 i 10 (część o wariancie) odhacza za Ciebie
`kuking:bramka-r2` z §2** — wklej tu jej werdykt z datą. Poniżej zostaje
to, czego z serwera nie widać.

**Punkt 2 odhacza jednak tylko te adresy, które wpisałeś do
`KUKING_R2_PUBLICZNE_ADRESY`** (krok 4 w §2a). Adres pominięty w tej
zmiennej nie został sprawdzony przez nikogo — ani przez bramkę, ani przez
Ciebie — i o tym właśnie jest punkt 4 niżej: kompletną listę publicznych
adresów da się przeczytać wyłącznie z panelu.

| # | Co udowodnić | Jak | Wynik | Data |
|---|---|---|---|---|
| 1 | `GET https://cdn…/media/…_feed.webp` → **403/404** | `curl -sI` na adresie prawdziwego wariantu. **Ten punkt issue #120 odwrócił audyt W7-02:** pierwotnie żądał 200, dziś wariant też nie ma publicznego adresu (sprawdzenie 8 komendy) | | |
| 2 | `GET https://cdn…/incoming/….jpg` znanego oryginału → **403/404**, przez KAŻDĄ publiczną ścieżkę (własna domena, `r2.dev`, endpoint konta) | sprawdzenia 6 i 7 komendy — wklej tu linie z dosłownymi kodami odpowiedzi, po jednej na adres | | |
| 3 | ten sam oryginał przez API S3 z serwera → **sukces** | `railway ssh -- php artisan tinker` → `Storage::disk('r2')->exists($klucz)` | | |
| 4 | `r2.dev` **wyłączone** na buckecie oryginałów, i **kompletna lista** publicznych adresów obu bucketów przepisana do `KUKING_R2_PUBLICZNE_ADRESY` | panel R2 → bucket → Settings → Public access (kroki 1–4 w §2a). Bramka udowodni, że zadeklarowany adres odmawia — ale co jest włączone, widać tylko tutaj | | |
| 5 | w publicznym buckecie **ani jednego** klucza `incoming/` | `aws s3api list-objects-v2 --bucket kuking-media --prefix incoming/ --endpoint-url …` → pusto | | |
| 6 | `PutObject` przechodzi **bez** `x-amz-acl` | wgraj zdjęcie przez formularz na stagingu i sprawdź, że wiersz `media` dostaje `ready` | | |
| 7 | plik bliski **15 MB** przechodzi | wgraj zdjęcie ~14,9 MB (limit `kuking.media.max_bytes`) | | |
| 8 | JPEG, PNG, WebP i AVIF — po jednej **prawdziwej** próbce | cztery wgrania z telefonu/aparatu, nie pliki generowane | | |
| 9 | worker wytwarza wszystkie **trzy** warianty | `metadata->variants` ma `thumb`, `feed`, `large` | | |
| 10 | oryginał ma EXIF, wariant **nie ma** | `exiftool` na pliku z bucketu oryginałów i na wariancie | | |
| 11 | skasowanie zabiera oryginał **i wszystkie** warianty | skasuj wpis, potem `list-objects-v2` na oba buckety | | |
| 12 | błąd zapisu do R2 daje bezpieczny komunikat i alert dla operatora | podmień sekret na błędny, spróbuj wgrać, sprawdź Sentry i to, co widzi człowiek | | |
| 13 | **typ lokalizacji KAŻDEGO bucketu z danymi** (Automatic / Location Hint / Jurisdiction, a jeśli jurysdykcja — czy `eu`) | panel R2 → bucket → Settings. Sprawdzenie 12 komendy rozstrzyga to dla bucketów, po które sięga aplikacja; panel jest jedynym miejscem dla bucketu kopii (#193) i kwarantanny (#602). Lista i procedura: `docs/infra/LOKALIZACJA_DANYCH_R2.md` §5 | | |

Punkty 6–11 to jeden przebieg przez formularz — nie ma sensu robić ich osobno.

**Uwaga do punktu 2:** to jest jedyny punkt, którego nie wolno odhaczyć „bo
bucket nie ma domeny". Sprawdź adres, który **naprawdę istnieje** — weź klucz
oryginału z kolumny `media.object_key`. Odpowiedź 404 na wymyślony klucz nie
mówi nic o tym, czy bucket jest publiczny.

**Uwaga do punktu 12:** dyski R2 mają `throw => true`, więc nieudany zapis jest
wyjątkiem, nie cichym `false`. Człowiek ma zobaczyć polski komunikat, a nie
„opublikowano" i pustą ramkę.

---

## 4. Co pozostaje otwarte niezależnie od tej bramki

- **Domena `cdn.kuking.pl` przy buckecie wariantów.** Po W7-02 warianty nie mają
  publicznego adresu (adresem zdjęcia jest trasa `media.show`), ale zdjęcie
  klucza `url` z konfiguracji **nie zdejmuje domeny z bucketu** — dopóki
  `cdn.kuking.pl` tam wskazuje, stare adresy działają dalej. Zdjęcie domeny
  pozostaje czynnością w panelu Cloudflare (krok 3 w §2a), ale **wynik da się
  od 11.09.2026 zmierzyć**: to sprawdzenie 8 komendy, pod warunkiem że domena
  jest zadeklarowana w `KUKING_R2_PUBLICZNE_ADRESY`.
- **`ResponseCacheControl` na odpowiedzi R2.** Że AWS SDK potrafi zbudować taki
  podpisany adres, jest zmierzone offline
  (`ZdjecieObiektuDostajeTenSamNoStoreCoPrzekierowanieTest`). Że R2 ten parametr
  honoruje — nie jest zmierzone i wymaga prawdziwego bucketu.
- **Stary, jeden bucket (`r2_legacy`).** Publiczności nie zdejmujemy, dopóki
  `kuking:przenies-zdjecia` nie dojdzie do końca.
- **Zgodność wierszy `media` z zawartością bucketów (#1031).** Bramka patrzy na
  konfigurację i na to, co bucket oddaje na zewnątrz — **nie** sprawdza, czy
  plik, na który wskazuje wiersz w bazie, w ogóle istnieje. Do tego jest osobny,
  wyłącznie odczytujący raport: `php artisan kuking:sprawdz-zdjecia-po-przenosinach`.
  Warto go puścić przed migracją bucketów (#619) i po niej. Powód, dla którego
  w ogóle powstał: do 22.09.2026 `kuking:przenies-zdjecia` uznawało brakujący
  plik za poprawnie przeniesiony i przestawiało `disk` — wiersz wypadał wtedy
  z kolejki migracji i nic już go nie znajdowało.
- **Lokalizacja danych (#619).** Sprawdzenie 12 rozstrzyga jurysdykcję
  bucketów, po które sięga aplikacja. Nie widzi bucketu kopii bazy (#193),
  przyszłej kwarantanny (#602) ani Location Hintu — te zostają do odczytania
  w panelu. Szczegóły: `docs/infra/LOKALIZACJA_DANYCH_R2.md`.
- **Ochrona przed logicznym usunięciem obiektów (#617).** Bramka sprawdza, czy
  zdjęcia nie wychodzą do niepowołanych — **nie** sprawdza, czy da się je
  odzyskać po poprawnym `DELETE`. Trwałość R2 nie jest kopią zapasową i tak jej
  nazywać nie wolno. Temat jest w #617.
