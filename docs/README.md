# Dokumentacja Kuking.pl

Zasady pracy dla agentów AI są w [`AGENTS.md`](../AGENTS.md) w katalogu głównym.
Decyzje już podjęte — i to, co musiałoby się stać, żeby je zmienić — są
w [`DECISIONS.md`](./DECISIONS.md). **Przeczytaj go, zanim zaproponujesz
zmianę architektury albo nowy pakiet.**

Materiały pod konkretne decyzje właściciela (poczta transakcyjna, widoczność
repozytorium, forma prawna operatora, kontrola językowa gry słowem „kuKING")
są w [`decyzje/`](./decyzje/).

Ten plik jest indeksem reszty.

## Audyty

| Dokument | O czym |
|---|---|
| [`AUDYT_2026-09-13.md`](./AUDYT_2026-09-13.md) | audyt wielodyscyplinarny z 13 września 2026: bezpieczeństwo, baza, język, dokumentacja, kod, SEO/infra, UX 50+; status znalezisk z 8 września |
| [`AUDYT_GPT_2026-09.md`](./AUDYT_GPT_2026-09.md) | audyt wielodyscyplinarny GPT z 8 września 2026 (G01–G17) |
| [`AUDYT_2026-09.md`](./AUDYT_2026-09.md) | audyt niespójności repozytorium z 8 września 2026 |

## Produkt

| Dokument | O czym |
|---|---|
| [`PRODUCT.md`](./PRODUCT.md) | pozycjonowanie, główne obiekty, persony, North Star |
| [`FEATURES.md`](./FEATURES.md) | co jest w MVP, co w V1, co w V2, czego nie robimy |
| [`ROADMAP.md`](./ROADMAP.md) | kolejność budowania i bramki jakościowe |
| [`FLOWS_AND_SCREENS.md`](./FLOWS_AND_SCREENS.md) | przepływy użytkownika i mapa ekranów |
| [`product/SOUL.md`](./product/SOUL.md) | mechaniki, które dają temu produktowi duszę |
| [`product/COLD_START.md`](./product/COLD_START.md) | plan 0 → 200 → 2000, playbook gospodarza |
| [`product/RETENTION_LOOPS.md`](./product/RETENTION_LOOPS.md) | pętle retencji, powiadomienia, digest |
| [`product/TAG_TYGODNIA.md`](./product/TAG_TYGODNIA.md) | kalendarz kuchni na 12 miesięcy, ręczna praca redakcji i decyzje do #18 |
| [`MONETIZATION.md`](./MONETIZATION.md) | stanowisko właściciela: zarabianie nie jest celem (nie hipotezy — decyzja) |

## Research

| Dokument | O czym |
|---|---|
| [`research/COMPETITIVE_LANDSCAPE.md`](./research/COMPETITIVE_LANDSCAPE.md) | Garnek.pl, Cookpad, Ravelry, grupy FB, luka rynkowa |
| [`research/AUDIENCE_50_PLUS.md`](./research/AUDIENCE_50_PLUS.md) | dane o Polakach 50+ online, bariery, motywacje |
| [`research/PUBLIC_REPOS.md`](./research/PUBLIC_REPOS.md) | publiczne repozytoria do inspiracji (Pixelfed, Tandoor, Discourse, Filament…) + stan wdrożenia |
| [`RESEARCH.md`](./RESEARCH.md) | notatki źródłowe z blueprintu |
| [`SOURCES.md`](./SOURCES.md) | lista źródeł |

## Interfejs i dostępność

| Dokument | O czym |
|---|---|
| [`UX_50_PLUS.md`](./UX_50_PLUS.md) | **twardy standard**, obowiązuje każdy ekran |
| [`design/DESIGN_SYSTEM.md`](./design/DESIGN_SYSTEM.md) | paleta z policzonymi kontrastami, typografia, komponenty |
| [`design/COMPONENTS_BLADE.md`](./design/COMPONENTS_BLADE.md) | referencyjne implementacje komponentów |
| [`design/A11Y_CHECKLIST.md`](./design/A11Y_CHECKLIST.md) | checklista PR i plan testów dostępności |
| [`design/prototype/`](./design/prototype/) | statyczny prototyp ekranów (materiał referencyjny) |

## Technika

| Dokument | O czym |
|---|---|
| [`ARCHITECTURE.md`](./ARCHITECTURE.md) | modularny monolit, warstwy, ścieżka skalowania |
| [`DATABASE.md`](./DATABASE.md) | model danych i zasady schematu |
| [`MEDIA_PIPELINE.md`](./MEDIA_PIPELINE.md) | pipeline zdjęć |
| [`TESTING.md`](./TESTING.md) | co testujemy i dlaczego na PostgreSQL |
| [`AI_WORKFLOW.md`](./AI_WORKFLOW.md) | jak pracować nad tym repozytorium z modelami AI |
| [`AI_DEVELOPMENT.md`](./AI_DEVELOPMENT.md) | notatki z blueprintu |

Referencyjny DDL: [`../database/reference/schema_mvp.sql`](../database/reference/schema_mvp.sql)
oraz [`schema_future.sql`](../database/reference/schema_future.sql) (funkcje V1/V2).
Obowiązującym źródłem prawdy o schemacie są **migracje**, nie te pliki.

## Zaufanie, bezpieczeństwo i prawo

| Dokument | O czym |
|---|---|
| [`legal/COMPLIANCE.md`](./legal/COMPLIANCE.md) | RODO, DSA, prawo autorskie, cookies, EAA |
| [`legal/MODERATION_PLAYBOOK.md`](./legal/MODERATION_PLAYBOOK.md) | katalog naruszeń, SLA, szablony wiadomości |
| [`legal/SECURITY_BASELINE.md`](./legal/SECURITY_BASELINE.md) | nagłówki, hasła, limity, upload, backupy |
| [`MODERATION.md`](./MODERATION.md) | zarys moderacji z blueprintu |
| [`SECURITY_PRIVACY_LEGAL.md`](./SECURITY_PRIVACY_LEGAL.md) | zarys z blueprintu |

Teksty publikowane na stronie (`/regulamin`, `/prywatnosc`, `/zasady`) żyją jako
Markdown w [`../resources/legal/`](../resources/legal/) — tak, żeby mógł je
poprawiać prawnik, a zmianę dało się zobaczyć w Pull Requeście.

## Wzrost

| Dokument | O czym |
|---|---|
| [`seo/SEO_TECHNICAL.md`](./seo/SEO_TECHNICAL.md) | slugi, JSON-LD, sitemapy, indeksacja, Core Web Vitals |
| [`seo/ANALYTICS.md`](./seo/ANALYTICS.md) | taksonomia zdarzeń, definicja Weekly Active Cooks, SQL |
| [`seo/GROWTH.md`](./seo/GROWTH.md) | kanały realne dla grupy 50+, kalendarz sezonowy |
| [`SEO_ANALYTICS_GROWTH.md`](./SEO_ANALYTICS_GROWTH.md) | zarys z blueprintu |

## Marka

| Dokument | O czym |
|---|---|
| [`BRAND.md`](./BRAND.md) | nazwa, claim, osobowość |
| [`brand/MASCOT_CONCEPT.md`](./brand/MASCOT_CONCEPT.md) | Garnuś — garnek z koroną, 7 konceptów, due diligence znaku |
| [`brand/COPY_STYLE.md`](./brand/COPY_STYLE.md) | **głos Kuking** — jak piszemy, system „kuKING”, gotowe teksty do wklejenia |
| [`brand/BRAND_EXTENDED.md`](./brand/BRAND_EXTENDED.md) | słownik marki, słowa zakazane, ton komunikatów |
| [`brand/mascot-winner.svg`](./brand/mascot-winner.svg) | zwycięski koncept w kilku rozmiarach |

Znaki używane w produkcie: [`../public/icons/`](../public/icons/).

## Wdrożenie

| Dokument | O czym |
|---|---|
| [`infra/INFRA_DECISION.md`](./infra/INFRA_DECISION.md) | Railway + Cloudflare, dlaczego bez Workers, koszty |
| [`infra/DEPLOYMENT_RUNBOOK.md`](./infra/DEPLOYMENT_RUNBOOK.md) | krok po kroku, dla osoby nietechnicznej |
| [`infra/CI_BEZ_ACTIONS.md`](./infra/CI_BEZ_ACTIONS.md) | dlaczego CI było wyłączone, jak je włączyliśmy i co zrobić, gdy limit się skończy |
| [`infra/PRZENIESIENIE_DO_ORGANIZACJI.md`](./infra/PRZENIESIENIE_DO_ORGANIZACJI.md) | transfer repo pod organizację `woogitsu` (wykonany) — i co trzeba było ustawić ponownie |
| [`infra/SELF_HOSTED_RUNNER.md`](./infra/SELF_HOSTED_RUNNER.md) | własny runner — plan awaryjny, gdyby limit organizacji nie wystarczył |
| [`DEPLOYMENT.md`](./DEPLOYMENT.md) | zarys z blueprintu |
| [`COSTS.md`](./COSTS.md) | koszty startowe |
