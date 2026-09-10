# Kuking.pl

**Kuking to polska społeczność ludzi, którzy naprawdę gotują.**

> Pokaż, co dziś ugotowałeś.

Nie kolejna anonimowa baza przepisów. Centrum produktu stanowią **ludzie**:
ich codzienne gotowanie, zdjęcia, komentarze, rodzinne receptury i relacje.
Główna grupa to osoby **50+**, ale produkt nie jest oznaczany jako „dla seniorów” —
ma być po prostu wyjątkowo czytelny i spokojny.

---

## Trzy rzeczy, na których stoi ten produkt

1. **Najniższy możliwy próg publikacji.** `zdjęcie + kilka słów → Opublikuj`.
   Bez wymaganego tytułu, kategorii i opisu. Poniżej minuty.
2. **„Ugotowałem” zamiast lajka.** Realne wykonanie cudzego przepisu, najlepiej
   ze zdjęciem. To jest najsilniejszy sygnał jakości w serwisie i najmilsze
   powiadomienie, jakie może dostać autor przepisu.
3. **Twoje przepisy nie zginą.** Pobranie wszystkich swoich danych działa
   od pierwszego dnia, nie „kiedyś”. Garnek.pl i Durszlak.pl zniknęły
   z treściami użytkowników — nie powtarzamy tego.

---

## Stan repozytorium

To jest **działająca aplikacja Laravel 13**, nie sam blueprint.

Co już działa i jest pokryte testami (72 testy, PostgreSQL):

- konto, logowanie e-mailem **albo nazwą użytkownika**, reset hasła, weryfikacja e-maila,
- onboarding (zainteresowania → osoby → gotowe), z możliwością pominięcia każdego kroku,
- profil publiczny `/@nazwa` z archiwum pogrupowanym po miesiącach,
- obserwowanie, blokowanie (blokada kasuje obserwowanie w obie strony),
- feed obserwowanych — chronologiczny, z kursorem; przy pustym feedzie „Świeżo z Kuking”,
- wpis: zdjęcie + kilka słów, z wyborem widoczności,
- przepis z sekcją **„Skąd ten przepis”**, polem „po kim” i rokiem „w rodzinie od”,
- **„Ugotowałem”** wraz z powiadomieniem autora,
- komentarze i odpowiedzi (jeden poziom),
- zeszyt (kolekcje), wyszukiwarka po przepisach, składnikach i ludziach,
- powiadomienia w aplikacji,
- ustawienia: profil, **rozmiar tekstu zapisywany na koncie**, prywatność, Twoje dane,
- zgłaszanie treści i panel moderacji z uzasadnieniem decyzji (DSA art. 16-17),
- eksport danych i usunięcie konta z 30-dniowym okresem na zmianę zdania,
- pipeline zdjęć: walidacja magic bytes, limit megapikseli, **re-enkodowanie
  zdejmujące EXIF/GPS**, warianty thumb/feed/large,
- PWA (manifest, service worker, strona offline), sitemap, robots, JSON-LD,
- nagłówki bezpieczeństwa, limity zapytań, dziennik audytu.

Co jest zaplanowane i opisane w issues: patrz [`BACKLOG.md`](./BACKLOG.md)
i zakładka Issues.

---

## Stack

| Warstwa | Wybór |
|---|---|
| Backend | Laravel 13 |
| PHP | 8.4 (minimum frameworka: 8.3) |
| UI | Blade + Livewire 4 + Alpine.js |
| CSS | Tailwind CSS 4 (konfiguracja CSS-first, `@theme`, bez `tailwind.config.js`) |
| Baza | PostgreSQL 18 (lokalnie i w CI wystarczy 16+) |
| Kolejka | Laravel database queue |
| Hosting | Railway |
| DNS / CDN / storage | Cloudflare + R2 |
| Wyszukiwarka | PostgreSQL FTS + `pg_trgm` + `unaccent` |
| Monitoring | dziennik serwera + kanał `blad_webhook` na Slack/Discord (D-041) |
| Analityka | własna, serwerowa (`App\Domain\Analytics\*`) + Cloudflare Web Analytics (bez ciasteczek — D-092) |
| Mobile | PWA |

Ta tabela jest **kopią pierwszych dwóch kolumn** tabeli z [`AGENTS.md` §3](./AGENTS.md#3-stack-i-czego-nie-wolno-dokładać)
i musi się z nią zgadzać co do znaku — pilnuje tego
`tests/Feature/TabelaStackuMowiPrawdeTest.php`. Tam stoi też trzecia kolumna,
„Gdzie to sprawdzić”, mówiąca, gdzie każdej z tych rzeczy szukać w repozytorium.
Powód, dla którego zgodność pilnuje test, a nie uważność: obie kopie mówiły przez
miesiące to samo nieprawdziwe zdanie — „Monitoring | Sentry”, przy Sentrym,
którego w projekcie nigdy nie było. Poprawienie jednej bez drugiej zostawiłoby
tę nieprawdę na stronie tytułowej repozytorium.

Decyzja architektoniczna: **modularny monolit**. Uzasadnienie i ścieżka skalowania
w [`docs/ARCHITECTURE.md`](./docs/ARCHITECTURE.md).

---

## Uruchomienie lokalnie

Wymagania: PHP 8.4, Composer, Node 22, PostgreSQL 16+.

```bash
git clone https://github.com/woogitsu/kuking.pl.git
cd kuking.pl

composer install
npm install

cp .env.example .env
php artisan key:generate

createdb kuking
createdb kuking_test        # baza testowa — testy NIE działają na SQLite

php artisan migrate --seed  # dane demo: Basia, Marek, Ania + 2 przepisy
npm run build

php artisan serve
```

Konta demo: `basia@example.test`, `marek@example.test`, `ania@example.test`,
moderator `moderacja@example.test` — hasło `haslo-testowe-123`.

### Testy i kontrola przed wysłaniem

```bash
./scripts/check.sh            # to samo, co robiłoby CI
./scripts/install-hooks.sh    # hook pre-push — raz, na starcie
```

Repozytorium jest prywatne, więc GitHub Actions kosztują minuty. Dopóki ich nie ma,
**bramką jakości jest kontrola lokalna**, a workflowy w `.github/workflows/`
czekają gotowe do włączenia. Porównanie opcji i kosztów:
[`docs/infra/CI_BEZ_ACTIONS.md`](./docs/infra/CI_BEZ_ACTIONS.md).

Pojedyncze kroki:

```bash
php artisan test        # 72 testy, PostgreSQL
vendor/bin/pint         # formatowanie
npm run build           # assety
```

Testy chodzą na PostgreSQL, **nie na SQLite** — schemat używa indeksów
częściowych, `num_nonnulls()`, `gen_random_uuid()`, `pg_trgm` i `unaccent`.
Test na SQLite przechodziłby, nic nie sprawdzając.

---

## Wdrożenie

`main` → Railway (produkcja), PR → środowisko preview.
Domena i CDN w Cloudflare, zdjęcia w R2.

- decyzja i uzasadnienie: [`docs/infra/INFRA_DECISION.md`](./docs/infra/INFRA_DECISION.md)
- krok po kroku dla właściciela: [`docs/infra/DEPLOYMENT_RUNBOOK.md`](./docs/infra/DEPLOYMENT_RUNBOOK.md)
- infrastruktura jako kod: [`.railway/railway.ts`](./.railway/railway.ts)
- obraz produkcyjny: [`Dockerfile`](./Dockerfile) (FrankenPHP)

---

## Praca z agentami AI

Nad repozytorium pracują różne modele (Claude, GPT/Codex, Gemini, Copilot).
Wszystkie czytają **jeden** dokument:

### → [`AGENTS.md`](./AGENTS.md) ←

`CLAUDE.md`, `GEMINI.md`, `.github/copilot-instructions.md`, `.cursor/rules/`
i `.windsurfrules` to cienkie wskaźniki na ten plik. Zasady zmieniamy tylko
w `AGENTS.md`, żeby modele nie dostawały sprzecznych instrukcji.

Szczegóły workflow: [`docs/AI_WORKFLOW.md`](./docs/AI_WORKFLOW.md).

```text
issue  →  agent czyta AGENTS.md  →  branch  →  kod + testy + docs
       →  Pull Request  →  GitHub Actions  →  review  →  main  →  Railway
```

---

## Dokumentacja

Pełny indeks: [`docs/README.md`](./docs/README.md).

Najważniejsze:

| Dokument | O czym |
|---|---|
| [`AGENTS.md`](./AGENTS.md) | zasady dla wszystkich agentów AI — **czytaj pierwszy** |
| [`docs/PRODUCT.md`](./docs/PRODUCT.md) | czym jest produkt, persony, North Star |
| [`docs/UX_50_PLUS.md`](./docs/UX_50_PLUS.md) | twardy standard interfejsu |
| [`docs/ROADMAP.md`](./docs/ROADMAP.md) | kolejność budowania |
| [`docs/product/SOUL.md`](./docs/product/SOUL.md) | co daje temu produktowi duszę |
| [`docs/product/COLD_START.md`](./docs/product/COLD_START.md) | plan 0 → 200 → 2000 użytkowników |
| [`docs/research/COMPETITIVE_LANDSCAPE.md`](./docs/research/COMPETITIVE_LANDSCAPE.md) | Garnek.pl, Cookpad, grupy FB — lekcje |
| [`docs/research/AUDIENCE_50_PLUS.md`](./docs/research/AUDIENCE_50_PLUS.md) | jak realnie korzystają z internetu Polacy 50+ |
| [`docs/design/DESIGN_SYSTEM.md`](./docs/design/DESIGN_SYSTEM.md) | paleta, typografia, komponenty, kontrasty |
| [`docs/legal/COMPLIANCE.md`](./docs/legal/COMPLIANCE.md) | RODO, DSA, prawo autorskie |
| [`docs/brand/MASCOT_CONCEPT.md`](./docs/brand/MASCOT_CONCEPT.md) | Garnuś — maskotka („w KU**KING** siedzi KING”) |

---

## Licencja

Patrz [`LICENSE`](./LICENSE).
