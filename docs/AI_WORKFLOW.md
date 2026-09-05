# Praca nad Kuking z modelami AI

Nad tym repozytorium pracuje kilka modeli (Claude, GPT/Codex, Gemini, Copilot).
Ten dokument opisuje, jak to zorganizować, żeby nie deptały sobie po palcach
i nie kosztowały więcej, niż dają.

---

## 1. Jedno źródło prawdy

Wszystkie zasady projektu są w [`AGENTS.md`](../AGENTS.md).

Pliki, które modele czytają automatycznie, są **cienkimi wskaźnikami** na ten plik:

| Plik | Kto czyta |
|---|---|
| `AGENTS.md` | Codex/GPT, Claude Code, Jules i coraz więcej narzędzi — **kanoniczny** |
| `CLAUDE.md` | Claude Code |
| `GEMINI.md` | Gemini CLI |
| `.github/copilot-instructions.md` | GitHub Copilot |
| `.cursor/rules/kuking.mdc` | Cursor |
| `.windsurfrules` | Windsurf |

**Zasadę zmieniasz wyłącznie w `AGENTS.md`.** Rozjazd między plikami
instrukcji jest gorszy niż brak instrukcji: każdy model dostaje wtedy inną
wersję prawdy i różnice wychodzą dopiero na review.

---

## 2. Jak pracujemy

```text
issue (P0 → P1 → P2)
  → agent czyta AGENTS.md i dokument obszaru
  → branch
  → kod + testy + docs
  → Pull Request
  → GitHub Actions (pint, PHPStan, testy na PostgreSQL 18, build assetów)
  → review
  → main
  → Railway (auto-deploy)
```

Zasady, które oszczędzają najwięcej czasu i tokenów:

- **Jedno issue = jeden PR.** Pomysł, który wpadł po drodze, ląduje jako nowe
  issue, nie jako dodatkowy commit w niepowiązanym PR-ze.
- **Bugfix zawsze z testem regresyjnym.** Poprawka bez testu to zaproszenie
  do powtórki.
- **Agent nie potrzebuje SSH na produkcję.** Wszystko idzie przez PR i CI.

---

## 3. Automatyczne przygotowanie środowiska

`.claude/hooks/session-start.sh` uruchamia się na starcie sesji Claude Code
i robi to, czego inaczej każdy agent szuka po omacku: startuje PostgreSQL,
tworzy bazy `kuking` i `kuking_test`, dociąga zależności, tworzy `.env`
i puszcza migracje.

Dla innych narzędzi to samo ręcznie:

```bash
pg_ctlcluster 16 main start
createdb kuking && createdb kuking_test
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
```

---

## 4. Skille projektowe

W `.claude/skills/` są procedury specyficzne dla tego repozytorium:

| Skill | Kiedy |
|---|---|
| `kuking-ekran` | nowy ekran, strona albo formularz — z checklistą UX 50+ |
| `kuking-migracja` | zmiana schematu bazy — migracja + test + docs + rollback |

Zawierają rzeczy, których model nie zgadnie: wzorzec migracji z `CHECK`-ami,
listę gotowych komponentów Blade, zakazy (`UNIQUE` w `cooked_events`,
`status` w `$fillable`) i pułapki, na które już się tu nadziano.

---

## 5. Skille i wtyczki spoza repozytorium, które realnie pomagają

Kolejność jest według stosunku korzyści do kosztu tokenów.

### Wbudowane w Claude Code — używaj od razu

| Narzędzie | Do czego w tym projekcie |
|---|---|
| `/code-review` | przegląd diffa przed PR-em; łapie IDOR-y, brak `authorize()`, nieobsłużone wyjątki |
| `/security-review` | przegląd bezpieczeństwa zmian — obowiązkowo przy dotykaniu uploadu, autoryzacji i moderacji |
| `/simplify` | sprzątanie po sobie: powtórzenia, zbędne warstwy, martwy kod |
| `/init` | odświeżenie `CLAUDE.md`, gdy struktura repo mocno się zmieni |
| `/run` | uruchomienie i sprawdzenie aplikacji w przeglądarce zamiast zgadywania |
| `/loop` | cykliczne zadania, np. pilnowanie zielonego CI |
| `/fewer-permission-prompts` | mniej pytań o zgodę na komendy, które i tak wykonujemy stale |

### Warte rozważenia

| Narzędzie | Uwaga |
|---|---|
| `efficient-delegation` | zanim odpalisz kilku agentów naraz — realnie ogranicza dublowanie pracy i zużycie kontekstu |
| `superpowers-manager` / `dev-superpack` | metodyka pracy + zestaw skilli specjalistycznych; sensowne przy dłuższych, wieloetapowych zadaniach |
| `fullstack-dev-skills-manager` | skille językowe i frameworkowe; przydatne, jeśli ktoś dojdzie do projektu bez doświadczenia w Laravelu |
| `skill-creator` | gdy jakaś procedura powtarza się trzeci raz — zamień ją na skill w `.claude/skills/` |

### Czego świadomie NIE używamy

- **Generatorów treści pod SEO.** Masowo generowane przepisy skasowałyby jedyny
  realny wyróżnik Kuking i są nieodwracalne (`AGENTS.md`, sekcja 9).
- **Automatycznych „ulepszaczy” architektury**, które proponują mikroserwisy,
  Redisa albo osobne SPA. To jest wprost na liście zakazów.

---

## 6. Praca wieloagentowa — kiedy się opłaca

Opłaca się przy zadaniach, które da się **rozdzielić bez wspólnych plików**:
research, analiza konkurencji, dokumentacja obszarowa, przegląd kilku
niezależnych modułów.

Nie opłaca się przy jednej spójnej zmianie w kodzie — dwóch agentów piszących
w te same pliki kosztuje więcej niż jeden i produkuje konflikty.

Zasady, które sprawdziły się przy budowie tego repozytorium:

1. Każdy agent ma **rozłączny katalog wyjściowy** i wprost zapisany zakaz
   dotykania cudzych plików.
2. Agenci researchowi piszą do katalogu roboczego, a wyniki dopiero potem
   trafiają do `docs/` — dzięki temu nie kolidują z osobą piszącą kod.
3. Każdy agent dostaje na wejściu ścieżkę do blueprintu i wymóg podania źródeł
   oraz jawnego oznaczania rzeczy niepotwierdzonych.
4. Agent, który generuje kod albo konfigurację, ma obowiązek **zweryfikować
   składnię** (`bash -n`, `python3 -m json.tool`, `yaml.safe_load`, `php -l`).

---

## 7. Definicja gotowości

Zmiana jest gotowa do PR-a, kiedy:

```bash
vendor/bin/pint        # bez zmian do wprowadzenia
php artisan test       # zielone, na PostgreSQL
npm run build          # assety się budują
```

oraz:

- [ ] dotknięte ekrany spełniają checklistę z `.claude/skills/kuking-ekran/SKILL.md`,
- [ ] zmiana schematu ma migrację, test, wpis w `docs/DATABASE.md` i rollback,
- [ ] bugfix ma test regresyjny,
- [ ] dokumentacja obszaru jest aktualna,
- [ ] w PR-ze jest: co, dlaczego, ryzyka, rollback.
