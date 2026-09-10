# Audyt 4 — wydajność, skalowalność i media

**Bazowy commit:** `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 2026-09-10

## Wniosek

Aplikacja ma dobre mechanizmy wydajnościowe (paginacja, warianty zdjęć, kolejki, PostgreSQL 18, OPcache, Lighthouse w CI). Najważniejszym realnym ryzykiem przed większą kampanią pozostaje ciężka ścieżka uploadu przechodząca przez proces PHP. Sam limit 50 Mpx **nie jest dowodem przekroczenia pamięci**: repo zawiera nowsze sprostowanie D-064, że kontener ma 1024 MB, a zmierzony szczyt RSS dla 50 Mpx wynosił ok. 452 MB.

**Ocena: 8/10.** Dla ruchu alpha profil jest wystarczający; przed szerokim pozyskaniem zmierzyłbym zachowanie mediów pod współbieżnym obciążeniem i ograniczył koszt requestu uploadowego.

## Ustalenia

### PERF1 — P2 — limit 50 Mpx nie przekracza zmierzonego sufitu, ale dokumentacja pamięci jest sprzeczna i brakuje testu pod współbieżnym obciążeniem

**Stan po korekcie:** `config/kuking.php` dopuszcza `max_megapixels = 50`. `docs/MEDIA_PIPELINE.md` / D-064 dokumentują zmierzony szczyt RSS około **452 MB** dla 50 Mpx. Nowsza decyzja D-064 prostuje wcześniejsze założenie: realny limit kontenera to **1024 MB**, nie 384 MB. `PHP_WORKER_MEMORY_LIMIT=512M` nie jest dobrym przybliżeniem RSS, ponieważ GD alokuje znaczną część bitmapy poza licznikiem PHP.

Czyli wcześniejszy wniosek „50 Mpx przekracza budżet workera” był błędny i został wycofany. 452 MB mieści się w 1024 MB.

Jednocześnie repo ma trzy niespójne komentarze:

- `config/kuking.php`: 50 Mpx,
- `.railway/railway.ts`: komentarz mówi, że worker dekoduje do 24 Mpx,
- `ProcessUploadedImage::failed()`: komentarz nadal wspomina `--memory=384`, choć D-064 wprost stwierdza, że taka wartość nie występuje w konfiguracji.

**Pozostałe ryzyko:** obecna produkcja jest opisana jako jeden serwis w trybie `all`, więc ciężki proces GD może współdzielić limit kontenera z webem i innymi jobami. Sam benchmark pojedynczego obrazu nie mówi jeszcze, jaki jest p95 przy jednoczesnych requestach/uploadach.

**Naprawa:** nie obniżać arbitralnie limitu do 32–36 Mpx. Najpierw wykonać benchmark na konfiguracji zbliżonej do produkcji: 2–4 równoległe requesty web + jeden job 24/36/50 Mpx, mierząc RSS/cgroup OOM, czas i queue latency. Limit zmienić dopiero na podstawie wyniku. Równocześnie poprawić stale comments `24 Mpx` / `384 MB`.

### PERF2 — P1 — główna ścieżka uploadu nadal przechodzi przez proces PHP

**Dowód:** `StoreUploadedImage::handle()` odbiera `UploadedFile`, wykonuje `getimagesize`, odczyt EXIF, `$file->get()`, `UsunGps::zBajtow(...)` i dopiero potem `Storage::disk(...)->put(...)`. W repo nie znaleziono `temporaryUploadUrl`/presigned uploadu. `docker/php.ini` nazywa bezpośredni upload do R2 „docelowym”, ale aktualny kod wciąż przyjmuje do sześciu plików po 15 MB przez formularz HTTP (`post_max_size=112M`).

**Skutek:** na słabym LTE osoba może wysyłać dziesiątki MB przez web dyno, a request pozostaje zajęty podczas walidacji, usuwania GPS i zapisu do storage. To zwiększa ryzyko timeoutu/retry/dwukliku dokładnie w głównej akcji produktu „dodaj zdjęcie”.

**Naprawa:** zbudować dwuetapowy upload: inicjalizacja → podpisany PUT/multipart bezpośrednio do prywatnego `incoming/` → finalize w Laravelu → asynchroniczna walidacja/przetwarzanie. Granicę bezpieczeństwa należy zachować: obiekt nie może zostać uznany za `ready` ani widoczny przed weryfikacją i re-encodingiem. Jeśli zachowanie GPS w oryginale nie jest konieczne, jeszcze prościej: nie przechowywać oryginału przed bezpiecznym przetworzeniem.

### PERF3 — P2 — obraz jest dekodowany osobno dla każdego wariantu

`ProcessUploadedImage` w każdej iteracji wariantu robi `$manager->read($original)`, czyli dekoduje pełne źródło trzy razy. Dla dużych zdjęć zwiększa to CPU i czas kolejki.

**Naprawa:** benchmark dwóch strategii: jedno dekodowanie + klon/skalowanie od największego wariantu w dół versus obecne trzy niezależne dekodowania. Nie zmieniać bez pomiaru jakości i pamięci — kopie obrazu też kosztują RAM.

### PERF4 — P2 — nieograniczona lista blokowanych w ustawieniach prywatności

`PrivacySettingsController::edit()` wykonuje `blocking()->with('profile.avatar')->get()` bez paginacji. Dla zwykłego użytkownika lista będzie mała, ale przy koncie intensywnie blokującym spam/boty może rosnąć bez granicy.

**Naprawa:** `paginate(25)` lub limit + „Pokaż więcej”. To nie jest bramka startu.

## Mocne strony

- feed i rosnące listy korzystają z paginacji;
- komentarze i zapisane wpisy mają jawne rozmiary stron;
- warianty zdjęć 320/960/1600 WebP ograniczają transfer w feedzie;
- processing zdjęć jest w osobnej kolejce `media`;
- CI uruchamia Lighthouse i przechowuje artefakty;
- OPcache i realpath cache są świadomie skonfigurowane;
- nie ma potrzeby Redis ani fanout-on-write bez danych wskazujących na wąskie gardło.

## Kolejność działań

1. zmierzyć profil pamięci/czasu przy współbieżnym web + przetwarzaniu 24/36/50 Mpx i dopiero wtedy ustalić limit;
2. wprowadzić direct-to-R2 upload albo inną redukcję kosztu requestu przed większą kampanią pozyskania użytkowników;
3. po pierwszych realnych 500–1000 uploadach zmierzyć p50/p95: upload, queue wait, processing, rejection reasons;
4. dopiero potem optymalizować dekodowanie wariantów.


## Korekta audytora

Pierwsza wersja tego raportu powtórzyła nieaktualne założenie `384 MB` z komentarza `ProcessUploadedImage`. Nowsza, obowiązująca decyzja D-064 w `docs/DECISIONS.md` wprost prostuje tę liczbę do **1024 MB limitu kontenera** i pokazuje ok. **452 MB RSS** dla 50 Mpx. Raport został skorygowany przed spakowaniem ZIP.
