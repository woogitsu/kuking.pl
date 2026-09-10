# Audyt 12/13 — zależności, aktualność stacku i software supply chain

**Repozytorium:** `woogitsu/kuking.pl`  
**Punkt odniesienia:** `main` @ `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 10.09.2026

## Werdykt

Stack nie jest przestarzały. Przeciwnie — główne zależności są bardzo świeże:

- `laravel/framework` w lockfile: **13.30.1**; aktualne Packagist na dzień audytu: **13.31.0**,
- `vite` w lockfile: **8.2.2**; aktualne npm: **8.2.2**,
- `playwright`: **1.63.0**; aktualne npm: **1.63.0**,
- `aws/aws-sdk-php`: **3.394.9**, wydanie z 04.09.2026.

Największe ryzyko jest w **procesie zaufania do zależności**, nie w ich wieku.

## 1. P1 — audyt podatności jest celowo nieblokujący także dla backendu produkcyjnego

W `ci.yml` oba kroki mają:

```yaml
continue-on-error: true
```

- `composer audit --locked --no-interaction`
- `npm audit --audit-level=high`

Komentarz wyjaśnia decyzję: nowe CVE w zależności tranzytywnej nie ma blokować pilnego hotfixa. Intencja jest racjonalna, ale obecna implementacja robi z tego **globalny wyjątek**: także krytyczna, zdalnie wykorzystywalna podatność w frameworku produkcyjnym nie zablokuje zwykłego feature merge/deploy.

### Rekomendacja

Rozdzielić politykę:

- `composer audit` dla **produkcyjnych** zależności → blokuje na `high/critical` według jawnej polityki,
- dev/build npm → może pozostać nieblokujące lub blokować tylko `critical`,
- pilny hotfix → świadomy mechanizm override z opisem CVE, właścicielem i terminem naprawy.

Lepsze jest „domyślnie blokuj + kontrolowany wyjątek” niż „domyślnie przepuszczaj wszystko”.

---

## 2. P1 — akcje GitHub są przypięte do ruchomych tagów, nie immutable SHA

Przykłady z workflow:

- `actions/checkout@v7`,
- `actions/setup-node@v7`,
- `actions/cache@v6`,
- `shivammathur/setup-php@v2`,
- `docker/setup-buildx-action@v4`,
- `getsentry/action-release@v3`.

GitHub w oficjalnym poradniku bezpieczeństwa mówi, że **pełny commit SHA jest jedynym niezmiennym sposobem przypięcia action**. Tag może zostać przesunięty lub przejęty.

Ryzyko rośnie w `deploy.yml`, bo `getsentry/action-release@v3` dostaje `SENTRY_AUTH_TOKEN`, a job operacyjny korzysta z tokenów Railway.

### Rekomendacja

- przypiąć wszystkie third-party actions do pełnych SHA,
- zostawić komentarz z czytelnym tagiem, np. `# v3.2.1`,
- włączyć Dependabot dla `github-actions` — już jest,
- rozważyć politykę organizacji „Require actions to be pinned to a full-length commit SHA”.

**Źródło:** GitHub Docs, Secure use reference.

---

## 3. P1 — `@railway/cli` jest instalowane jako nieprzypięte `latest` w jobie z tokenem Railway

`deploy.yml` wykonuje:

```bash
npm install -g @railway/cli
railway --version
```

Job ma w środowisku `RAILWAY_TOKEN` zależny od wybranego środowiska. To oznacza, że każde ręczne uruchomienie operacji pobiera **aktualną w tej sekundzie** wersję pakietu, a następnie uruchamia ją z dostępem do infrastruktury.

To jest słabsze niż reszta projektu, gdzie `package-lock.json` i `npm ci` zapewniają deterministyczną instalację.

### Rekomendacja

Najprościej:

- dodać Railway CLI do kontrolowanego manifestu/lockfile albo
- instalować konkretną wersję: `npm install -g @railway/cli@X.Y.Z`,
- aktualizować świadomym PR-em/zmianą po testach staging.

Dla narzędzia z uprawnieniami do production powtarzalność jest ważniejsza niż „zawsze najnowsza wersja”.

---

## 4. P2 — komentarz Dependabota mówi odwrotność tego, co robi konfiguracja

W `.github/dependabot.yml` komentarz przy backendzie mówi:

> major updates mają być osobnym PR-em i Dependabot „ma o nie pytać”.

Tymczasem konfiguracja ma:

```yaml
ignore:
  - dependency-name: "*"
    update-types: ["version-update:semver-major"]
```

To oznacza, że Dependabot **nie otworzy zwykłych PR-ów major**. Oficjalna dokumentacja GitHub potwierdza, że `ignore` z `version-update:semver-major` filtruje właśnie major version updates.

Ważne: GitHub dokumentuje, że `update-types` w tym miejscu dotyczy version updates, a nie security updates, więc nie klasyfikuję tego jako bezpośredniej dziury w łataniu CVE.

### Rekomendacja

Wybrać jedną z dwóch prawdziwych polityk i zapisać ją bez sprzeczności:

- **A. ignorujemy major automatycznie** → poprawić komentarz i raz na miesiąc kwartalny review majorów,
- **B. chcemy osobne PR-y major** → usunąć `ignore`.

Dla Kuking wybrałbym **A na MVP**, ale z kwartalnym review. Laravel 14 czy Vite 9 nie powinny wpadać przypadkiem podczas stabilizacji produktu.

---

## 5. P2 — audyt bezpieczeństwa zależności nie uruchamia się przy zmianie wyłącznie dokumentacji — poprawnie, ale nie działa jako harmonogram CVE

Job `audit` jest częścią workflow CI uruchamianego przy zmianach. Jeżeli przez kilka dni nie ma zmian kodowych, nowo opublikowane CVE nie musi automatycznie wywołać osobnego audytu tego repo.

Dependabot Security Updates częściowo to kompensuje, ale kontrola CI nie jest niezależnym cyklicznym czujnikiem.

### Rekomendacja

Dodać bardzo tani `schedule` raz dziennie lub co 2–3 dni tylko dla:

- `composer audit --locked`,
- `npm audit --audit-level=high`,

bez całego zestawu testów. Alarmować tylko o nowym stanie względem poprzedniego.

---

## 6. P2 — deklaracja PHP jest szersza niż faktyczny target produkcyjny

`composer.json` dopuszcza:

```json
"php": "^8.3"
```

ale CI i architektura projektu jednoznacznie targetują PHP **8.4**.

To nie jest błąd wykonawczy, ale manifest komunikuje bibliotekom i narzędziom, że aplikacja wspiera 8.3, mimo że CI tego nie testuje.

### Rekomendacja

Albo:

- zmienić `php` na `^8.4`, jeśli 8.3 nie jest wspierane,
- albo dodać minimalny matrix check 8.3, jeśli wsparcie 8.3 ma być prawdziwe.

Dla aplikacji wdrażanej wyłącznie na kontrolowanym obrazie wybrałbym **`^8.4`** i zmniejszył macierz stanów do utrzymania.

---

## 7. P3 — dependence freshness jest dobra; nie aktualizować dla samej liczby wersji

`laravel/framework` 13.30.1 jest tylko jeden patch/minor za 13.31.0. Nie ma podstaw, żeby robić awaryjny upgrade wyłącznie dlatego, że Packagist pokazuje nowszy numer.

Vite i Playwright są aktualne. To potwierdza, że aktualna praktyka lockfile + regularne aktualizacje działa.

## Co jest zrobione dobrze

- `package-lock.json` jest commitowany,
- CI używa `npm ci`, nie `npm install`,
- `composer.lock` jest audytowany,
- npm audit obejmuje także dev dependencies, co ma sens dla build supply chain,
- Dependabot obejmuje Composer, npm i GitHub Actions,
- minor/patch są grupowane, ograniczając alert fatigue,
- `allow-plugins` w Composerze jest jawne i wąskie,
- permissions w CI są ograniczone do `contents: read`,
- token Railway production jest oddzielony od staging.

## Kolejność napraw

1. **Pin SHA dla GitHub Actions, szczególnie tych z sekretami.**
2. **Pin wersji Railway CLI.**
3. **Polityka blokowania krytycznych CVE backendu.**
4. Ujednolicić komentarz i zachowanie Dependabota dla majorów.
5. Dodać lekki cykliczny audit CVE.
6. Ujednolicić minimalną wersję PHP z realnym targetem.

## Źródła zewnętrzne

- GitHub Docs — Secure use reference: rekomendacja pełnego SHA dla actions.
- GitHub Docs — Dependabot options reference: działanie `ignore` / `update-types`.
- Packagist — `laravel/framework`, stan 10.09.2026.
- npm — `vite` i `playwright`, stan 10.09.2026.
