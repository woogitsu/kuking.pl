## D-083 · Zdjęcie przypina się i kasuje pod JEDNĄ blokadą wiersza `media`, a pliki znikają dopiero PO commicie — wiersz ze znacznikiem `deleted` jest uchwytem do ponowienia

**Issue:** #285 (MEDIA-01, P1). **Data:** 10.09.2026.
**Stoi na:** D-079 (jedna kolejność blokad + rewalidacja POD blokadą).

> **Adnotacja z 20 września 2026 (audyt rejestru).** Sekcja „Co zostaje
> otwarte" jest nieaktualna od **D-103**, która sama nazywa się wykonaniem
> tego wpisu. Zdanie „awatar, zdjęcie główne przepisu, skan zeszytu i zdjęcie
> kroku **przypinają się nadal bez blokady**" nie opisuje dzisiejszego kodu:
> `app/Domain/Media/Actions/PrzypnijAwatar.php:113` woła
> `ZdjeciaDoPrzypiecia::zablokuj(...)`, a
> `app/Domain/Recipes/Actions/PublishRecipe.php:227` robi jedno wspólne
> `zablokuj()` na `hero_media_id`, `source_scan_media_id` i
> `recipe_steps.media_id`. Rdzeń — jedna blokada wiersza `media`,
> `status='deleted'` jako uchwyt do ponowienia, pliki dopiero po commicie —
> obowiązuje i jest wdrożony
> (`app/Domain/Media/ZdjeciaDoPrzypiecia.php:66-78`). D-103 nie postawiła tu
> adnotacji, więc czytany samodzielnie wpis wprowadzał w błąd co do stanu
> czterech ścieżek przypinania.

### Stan sprzed zmiany — sprawdzony w plikach, nie przepisany z audytu

Audyt jest materiałem zewnętrznym, a `docs/research/audyt-2026-09-10/SPRAWDZENIE.md`
wymienia MEDIA-01 wprost jako **niesprawdzone**. Sprawdzone teraz:

- `PublishPost::handle()` wybierał należące do autora `media_id` zwykłym
  `SELECT`-em **przed** transakcją i nigdy do tego wyboru nie wracał;
  `attach()` szedł kilkanaście linijek dalej, już w transakcji.
- `RecordCookedEvent::handle()` miał dokładnie ten sam kształt.
- `KasujZdjecie::jesliNieuzywane()` pytał `exists()` po sześciu tabelach
  (też bez blokady), a potem — **wewnątrz** transakcji otwartej przez
  `OsieroconeZdjecia::posprzataj()` — kasował pliki z R2 i dopiero na końcu
  wiersz `media`.

Żadna z tych operacji nie brała czegokolwiek na wspólnym wierszu `media`.
Między `exists()` sprzątacza a skasowaniem plików mieściła się cała
publikacja wpisu.

### Jedna poprawka do opisu issue

Issue przewiduje, że sprzątacz „wchodzi w konflikt z FK". **Nie wchodzi.**
`post_media.media_id` ma w migracji `2026_09_05_000500_create_posts_tables`
`cascadeOnDelete()` (tak samo `cooked_event_media.media_id`), więc skasowanie
wiersza `media` po cichu zabiera świeżo wstawiony wiersz `post_media`.

Objaw jest więc **gorszy** niż w opisie: nie ma ani wyjątku, ani wpisu
w logu. Wpis zostaje bez zdjęcia, plik znika z R2, a jedyny egzemplarz
zdjęcia człowieka nie istnieje już nigdzie. Przy produkcie, którego cała
obietnica brzmi „zabierzesz stąd wszystko, co dodasz", to jest najgorsza
klasa błędu, jaką ten kod może mieć.

### Decyzja

**1. Przypinanie wybiera zdjęcia POD BLOKADĄ, w tej samej transakcji co
`attach()`.** Robi to jedna klasa, `App\Domain\Media\ZdjeciaDoPrzypiecia`,
używana przez `PublishPost` i `RecordCookedEvent` — nie dwie kopie tego
samego protokołu, z tego samego powodu, dla którego lista `ODWOLANIA` żyje
w jednym miejscu.

- `SELECT … FOR UPDATE` — zderza się z blokadą `FOR KEY SHARE`, którą
  PostgreSQL bierze sam przy sprawdzaniu klucza obcego przy `INSERT`-cie do
  `post_media`. Przypięcie i przejęcie do skasowania ustawiają się przez to
  w kolejkę zamiast się mijać.
- `ORDER BY id` — deterministyczna kolejność blokowania. Bez niej dwa
  równoległe wysłania formularza z częściowo wspólnym zestawem zdjęć
  zakleszczyłyby się nawzajem.
- Warunki `owner_id` i `status` stoją w **tym samym** zapytaniu co blokada,
  więc są sprawdzane dopiero po jej uzyskaniu (D-079 §3: blokada serializuje,
  ale nie mówi żądaniu, że świat zmienił się, gdy ono czekało).
- Wywołanie poza transakcją rzuca `LogicException`. Blokada wiersza żyje
  wyłącznie w transakcji, więc bez tego strażnika ta klasa dałaby się
  przenieść „wyżej dla czytelności" i po cichu wrócić do zwykłego `SELECT`-a.

**2. Sprzątacz przejmuje zdjęcie w krótkiej transakcji, a pliki kasuje PO
commicie** — wzorzec z `EraseAccountData`, nie nowy pomysł:

1. `KasujZdjecie::przejmij()` — świeży odczyt `FOR UPDATE`, **ponowne**
   pytanie „czy używane" pod blokadą, znacznik `status = deleted`. Zero
   wejść na dysk, więc nikt nie czeka na R2 z założoną blokadą.
2. dopiero po zatwierdzeniu — pliki, a na samym końcu wiersz.

`OsieroconeZdjecia` przestaje otwierać własną transakcję: obejmowała także
kasowanie plików w R2, a jej wycofanie i tak nie przywróciłoby ani jednego
skasowanego pliku.

**3. Znacznik `status = 'deleted'` to „kasowanie trwa", nie „skasowane".**
Pełni tu tę samą rolę co `data_erased_at` przy wymazywaniu konta:
zatwierdzoną, widoczną dla innych transakcji deklarację „to zdjęcie
odchodzi". `ZdjeciaDoPrzypiecia` takiego wiersza nie przepuści, więc okno
nie wraca po zwolnieniu blokady, a przed skasowaniem plików.

Wiersz ze znacznikiem jest **uchwytem do ponowienia**: nieudane kasowanie
plików zostawia go na miejscu, a kolejny przebieg
`kuking:sprzataj-osierocone-zdjecia` wybiera go po wieku tak samo jak każdy
inny. To zachowanie z issue #17 zostaje nietknięte.

### Czego świadomie NIE zrobiono

- **Nowej kolumny ani migracji.** `media_status_check` dopuszcza wartość
  `deleted` od pierwszej migracji tabeli (`2026_09_05_000100_create_media_table`),
  tylko nikt jej nie używał. Osobna kolumna „zarezerwowane do kasowania"
  byłaby szóstym mechanizmem blokowania w repozytorium, w którym pięć
  wjechało tego samego dnia.
- **Optymalizacji liczby zapytań przy autoryzacji zdjęć** — to jest MEDIA-03
  (#286) i idzie osobno.
- **Trzech pozostałych dróg przypięcia** (`profiles.avatar_media_id`,
  `recipes.hero_media_id`/`source_scan_media_id`, `recipe_steps.media_id`).
  Mają ten sam kształt i tę samą lukę; nie zamknięto ich tutaj, żeby zmiana
  została przy utracie danych na dwóch najważniejszych ścieżkach produktu
  („Opublikuj" i „Ugotowałem"). **To jest dług, nie stan docelowy** — patrz
  „Co zostaje otwarte".

### Czego test NIE pilnuje

`tests/Feature/ZdjecieNieZnikaPrzyPrzypinaniuTest.php` **nie odtwarza**
wymuszonego przeplotu na dwóch połączeniach do PostgreSQL, którego domaga
się issue. `RefreshDatabase` trzyma dane testu w niezatwierdzonej transakcji,
więc drugie połączenie nie zobaczyłoby ani konta, ani zdjęcia.

Testowany jest kontrakt, na czterech osobnych elementach (blokada przy
przypinaniu, rewalidacja pod blokadą u sprzątacza, nieprzypinalność wiersza
ze znacznikiem, pliki po commicie + uchwyt do ponowienia). Brak przeplotu
z nich **wynika**, ale nie jest zmierzony — i tak trzeba to czytać.

Nie jest sprawdzone maszynowo, że PostgreSQL faktycznie serializuje
`FOR UPDATE` z `FOR KEY SHARE` branym przy kluczu obcym; to własność silnika,
przyjęta z dokumentacji. Nie jest też pilnowany strażnik
`DB::transactionLevel() === 0`, bo pod `RefreshDatabase` poziom transakcji
nigdy nie jest zerem.

### Co zostaje otwarte

Awatar, zdjęcie główne przepisu, skan zeszytu i zdjęcie kroku przypinają się
nadal bez blokady. Sam znacznik `deleted` daje im węższe okno niż przedtem,
ale go nie zamyka. Do osobnego zadania: przepuścić te cztery drogi przez
`ZdjeciaDoPrzypiecia`.

**Pliki:** `app/Domain/Media/ZdjeciaDoPrzypiecia.php` ·
`app/Domain/Media/KasujZdjecie.php` · `app/Domain/Media/OsieroconeZdjecia.php` ·
`app/Domain/Posts/Actions/PublishPost.php` ·
`app/Domain/Recipes/Actions/RecordCookedEvent.php` · `app/Models/Media.php` ·
`tests/Feature/ZdjecieNieZnikaPrzyPrzypinaniuTest.php`
