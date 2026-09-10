# Audyt 5 — testy, CI/CD i jakość release

**Bazowy commit:** `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 2026-09-10

## Wniosek

Kuking ma bardzo mocną kulturę regresji: ok. 2400 testów, PostgreSQL 18 w CI, rollback migracji, Pint, PHPStan, build Vite, axe-core, Lighthouse, dependency audit i build obrazu Docker. Problemem nie jest brak narzędzi, tylko dwa niedomknięte miejsca: bardzo niski poziom analizy statycznej oraz niestabilna ścieżka powrotu na self-hosted runners.

**Ocena: 8,5/10.**

## Ustalenia

### CI1 — P1 — PHPStan działa dopiero na poziomie 1

`phpstan.neon` ma `level: 1`. Sam komentarz podaje pomiar:

- level 2 → 172 błędy;
- level 3 → 194;
- level 4 → 232;
- level 5 → 299;
- docelowo level 8.

To uczciwa konfiguracja (lepsza niż wielki baseline), ale „0 błędów PHPStan” w komunikatach commitów nie może być interpretowane jako silna gwarancja typów — oznacza 0 błędów na poziomie 1.

**Rekomendacja:** osobny epik redukcji długu statycznego. Podnosić próg stopniowo: 2 → 3 → 5 → 8, bez baseline'u. Najpierw katalog `app/Domain`, potem kontrolery, modele i testy. Przy każdym poziomie wymagać 0 błędów przed przejściem dalej.

### CI2 — P1 — self-hosted runner path nadal ma otwarty problem #262

Workflow umożliwia przełączenie `CI_RUNS_ON` bez PR-a z `ubuntu-latest` na wspólną pulę self-hosted. W komentarzach zapisano problemy współdzielonej maszyny: PPA, blokady apt, `pcntl`, współdzielony PHP/toolcache. Otwarte issue #262 opisuje dodatkowo `composer: bad interpreter: Text file busy` w `setup-php` przy równoległych jobach.

**Rekomendacja:** przed powrotem na self-hosted runners rozdzielić mutable toolcache per runner/job albo serializować etap `setup-php`; najlepiej uruchamiać każdy runner w izolowanym kontenerze/VM z własnym toolcache. Sam `flock` na instalacji Chromium nie rozwiązuje mutacji PHP/Composera.

### CI3 — P2 — stan „zielony deploy” nie jest równoważny „pełne CI przeszło”

Status Railway `success` potwierdza stan wdrożenia raportowany przez Railway, ale sam w sobie nie dowodzi wykonania wszystkich wymaganych checków. W czasie audytu użyty endpoint konektora do pobierania workflow runów dla commita okazał się ograniczony do runów wywołanych przez `pull_request`, więc **brak wyniku z tego endpointu nie jest dowodem, że push-CI się nie uruchomiło**. Wycofuję taki wniosek.

Pozostaje natomiast niezależny, udokumentowany problem post-deploy: aktualny `.github/workflows/deploy.yml` sam podaje, że wcześniejsze 249 przebiegów jego smoke testu było `skipped` (szczegółowo w audycie 11).

**Rekomendacja:** branch protection/ruleset powinien wymagać konkretnego agregującego checka CI, który rozstrzyga również poprawne `skipped`. Nie opierać merge policy na samym Railway status. Osobno udowodnić działanie post-deploy smoke.

### CI4 — P2 — filtr joba axe/Lighthouse nie obejmuje wszystkich zmian mogących zmienić wynik strony

Ciężki job uruchamia się przy zmianach `resources/`, `public/`, skryptach, package lock i samym CI. Zmiana kontrolera/route może zmienić model danych widoku, kod odpowiedzi, canonical/noindex lub dostępność konkretnego stanu strony, a job zostanie pominięty.

**Rekomendacja:** nie trzeba uruchamiać browser suite przy każdej zmianie PHP. Dodać jednak do filtra co najmniej `routes/`, `app/Http/Controllers/`, middleware odpowiedzialne za nagłówki/SEO i klasy generujące dane strukturalne. Alternatywa: bardzo szybki smoke HTTP dla kluczowych URL-i przy każdej zmianie kodu, a pełny axe/Lighthouse nadal tylko przy warstwie widoku.

## Mocne strony

- prawdziwy PostgreSQL 18 w testach;
- `migrate:refresh` sprawdza `down()`;
- build Docker jest testowany, wraz z obecnością wymaganych rozszerzeń;
- Vite manifest i konkretne wygenerowane reguły CSS są weryfikowane po buildzie;
- axe + Lighthouse są częścią CI, nie tylko ręcznym skryptem;
- dependency audit obejmuje devDependencies frontendu;
- Dependabot obejmuje Composer, npm i GitHub Actions;
- brak wielkiego PHPStan baseline'u ukrywającego błędy.

## Zalecana kolejność

1. Naprawić #262 zanim `CI_RUNS_ON` wróci na self-hosted.
2. Podnieść PHPStan do level 2 jako osobny mierzalny etap.
3. Wzmocnić filtr smoke/browser dla zmian HTTP/routes/SEO.
4. Zweryfikować branch protection/ruleset w ustawieniach GitHub jako osobną bramkę operacyjną.


## Korekta audytora

Pierwsza wersja CI3 interpretowała brak wyniku `fetch_commit_workflow_runs` zbyt szeroko. Dokumentacja konektora wskazuje, że ten odczyt filtruje runy do zdarzeń `pull_request`; dlatego nie może służyć do stwierdzenia braku push-CI. Raport został skorygowany przed finalnym ZIP.
