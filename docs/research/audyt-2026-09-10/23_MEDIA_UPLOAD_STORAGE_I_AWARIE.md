# Kuking.pl — audyt uploadu, media, storage i awarii częściowych

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot trzeciej warstwy:** `fd164ad3a91185d1969a109fd692a269ad9710e3`  
**Data:** 10.09.2026

## Werdykt

Architektura media jest przemyślana: oryginał nie jest serwowany, pipeline re-enkoduje do WebP, usuwa metadane/EXIF, rozdziela prywatny oryginał od wariantów, ma retry oraz nie pozostawia obrazu bez końca w `processing`. Trzecia warstwa znalazła jednak dwa problemy, które happy-path i zwykłe testy transakcyjne łatwo pomijają:

1. **P1 data-loss race** między sprzątaniem osieroconego uploadu a jego przypięciem do właśnie publikowanej treści;
2. **P2 orphan leak w storage** przy awarii po zapisaniu części wariantów, zanim ich lista trafi do DB.

Dodatkowo sposób autoryzacji każdego pobrania zdjęcia generuje istotną multiplikację zapytań do PostgreSQL — osobne P1 wydajnościowe.

## MEDIA-01 — sprzątacz może skasować zdjęcie równolegle do jego przypięcia — P1

### Kod

`app/Domain/Media/OsieroconeZdjecia.php` buduje listę media starszych niż karencja i bez żadnego odwołania.  
`app/Domain/Media/KasujZdjecie.php::jesliNieuzywane()` ponownie pyta każdą tabelę „czy używane”, a jeśli nie — usuwa pliki z storage i dopiero na końcu usuwa wiersz `media`.

`app/Domain/Posts/Actions/PublishPost.php` działa inaczej:

1. przed transakcją wybiera należące do autora `media_id`;
2. później w transakcji tworzy wpis;
3. następnie `attach()` dodaje rekordy `post_media`.

Ani attach, ani cleanup nie blokują wspólnego wiersza `media`.

### Dopuszczony interleaving

```text
CLEANUP: SELECT media -> brak odwołań
PUBLISH: SELECT media -> należy do autora, więc zaakceptuj
CLEANUP: ponowny exists() -> nadal brak odwołań
PUBLISH: tworzy post
PUBLISH: attach(post_media)
CLEANUP: kasuje obiekty R2
CLEANUP: kasuje media / wchodzi w konflikt z FK/cascade
```

W zależności od dokładnego momentu i blokad PostgreSQL końcowy symptom może się różnić, ale inwariant jest złamany: dwie poprawne operacje nie mają jednej sekcji krytycznej. Użytkownik może opublikować treść, dla której media zostało fizycznie skasowane lub relacja zniknęła.

### Naprawa

Wprowadzić jeden protokół blokady dla **każdego przypięcia i kasowania media**:

- `SELECT ... FOR UPDATE` na wierszach `media` przed attach/delete;
- wiele UUID-ów zawsze blokować w deterministycznej kolejności;
- po zdobyciu locka cleanup ponownie sprawdza wszystkie referencje;
- publish po zdobyciu locka ponownie sprawdza ownership/status i dopiero wtedy attachuje;
- rozważyć jawny stan `claimed/attached/deleting` tylko jeśli upraszcza kod; nie jest wymagany do naprawy.

### Test zamykający

Prawdziwy PostgreSQL, dwa osobne połączenia/procesy i bariera wymuszająca powyższy interleaving. Zwykłe `DB::transaction()` w jednym procesie nie dowodzi zamknięcia race.

## MEDIA-02 — awaria po pierwszym `put()` zostawia wariant, którego DB nie zna — P2

### Kod

`app/Jobs/ProcessUploadedImage.php` zapisuje warianty kolejno do storage i przechowuje ich klucze chwilowo w lokalnym `$variants`. Dopiero po zakończeniu całej pętli zapisuje `metadata['variants']` w DB.

Jeżeli:

1. `thumb` zapisze się poprawnie;
2. zapis `feed` lub `large` rzuci wyjątek;
3. `catch` ustawi `status = rejected`;

to klucz pierwszego pliku nie został nigdy utrwalony w `metadata`.

`KasujZdjecie::skasujPliki()` sprząta warianty na podstawie `metadata['variants']`. Nie zna więc pliku utworzonego częściowo.

### Skutek

- obiekt może zostać w R2 po usunięciu wiersza/originalu;
- zużywa storage i łamie gwarancję pełnego lifecycle delete;
- obecny `r2_publiczne` ma być serwowany przez podpisane URL-e i nie ma własnej jawnej domeny, więc nie nazywam tego dziś udowodnionym publicznym wyciekiem;
- jeśli jednak ten sam klucz trafiłby do legacy/publicznego wariantu lub konfiguracja się zmieni, pozostawiony obiekt staje się większym ryzykiem prywatności.

### Naprawa

Najprostszy wariant: klucze wariantów są deterministyczne z `object_key` + nazwy wariantu. `KasujZdjecie` powinien próbować usunąć **wszystkie oczekiwane klucze**, nawet jeśli `metadata` jest niepełne.

Alternatywnie zapisywać każdy utworzony klucz do trwałego stanu przed przejściem do kolejnego wariantu. W `catch/failed()` wykonać best-effort cleanup, ale pozostawić locator w DB, jeśli któryś delete nie został potwierdzony.

### Test

Fake storage rzuca wyjątek przy drugim `put()`. Po jobie pierwszy obiekt ma być usunięty albo nadal jednoznacznie wskazany przez DB i możliwy do ponownego sprzątnięcia.

## MEDIA-03 — autoryzacja jednego zdjęcia wykonuje co najmniej 5 zapytań o rodziców — P1 wydajnościowe

### Kod

`app/Domain/Media/DostepDoZdjecia.php::rodzice()` zawsze buduje kompletną tablicę rodziców i wykonuje osobne query dla:

1. postów;
2. cooked events;
3. profilu/avataru;
4. przepisów hero/source scan;
5. kroków przepisu.

Dopiero **po wykonaniu wszystkich zapytań** `moze()` przechodzi po rodzicach i pyta Gate. Nie ma short-circuit na poziomie DB.

`app/Http/Controllers/MediaController.php` może dla zalogowanego widza wywołać ten resolver drugi raz jako anonymous, aby rozstrzygnąć, czy odpowiedź może dostać publiczny `Cache-Control`.

### Skala

Konfiguracja route limitu zakłada legalnie **100+ requestów obrazkowych dla jednej strony feedu**. Oznacza to setki zapytań do PostgreSQL wyłącznie na redirecty do R2, zanim policzymy session/cache/rate-limit oraz zapytania wewnątrz Policy.

To jest szczególnie istotne, bo MVP używa PostgreSQL również dla cache, session i queue. Nie jest to argument „dodaj Redis”; jest to argument „nie generuj pięciu lookupów na obrazek”.

### Naprawa

Preferowana kolejność:

1. mierzalny query-count test dla jednego media redirect i feedu 30/100 zdjęć;
2. resolver rodzica oparty na jednym/few zapytaniach zamiast 5 stałych;
3. rozdzielić raz policzoną widoczność widza od klasyfikacji `publiczne dla anonymous`, aby nie powtarzać całego grafu;
4. dopiero po pomiarze rozważać cache tej decyzji; invalidation musi objąć zmianę widoczności, block, moderation i status autora.

Nie rekomenduję denormalizowania pełnej reguły widoczności do `media.visibility`, bo powstałaby kolejna kopia logiki.

## MEDIA-04 — pipeline ma poprawne cechy ochronne — pozytywne

- nie serwuje oryginału przed re-enkodowaniem;
- świadomie obsługuje EXIF orientation;
- zapisuje warianty w WebP;
- `catch`/`failed()` nie zostawiają normalnego rekordu wiecznie w `processing`;
- worker ma osobną kolejkę `media`;
- aktualne pomiary 50 MP mieszczą się w docelowym 1 GB RAM workera z zapasem — brak podstaw do arbitralnego obniżania limitu megapikseli.

## Kolejność naprawy

1. MEDIA-01 — integralność i utrata zdjęcia;
2. MEDIA-03 — koszt requestów na głównej powierzchni;
3. MEDIA-02 — pełne sprzątanie po awarii częściowej.
