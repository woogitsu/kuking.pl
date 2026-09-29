# Handover sesji koordynatora — 29.09.2026, popołudnie

Stan na ok. 15:30 UTC. Ten plik jest punktem startu dla następnej sesji albo właściciela. Szczegóły są w dwóch plikach obok:

- [`REJESTR.md`](REJESTR.md): chronologiczny rejestr pracy, z każdą gałęzią i SHA, wynikiem recenzji i decyzją.
- [`PROMPT_ROBOTNIKA.md`](PROMPT_ROBOTNIKA.md): pełny prompt, z którym uruchamiani byli agenci Sonnet.

Lista kroków właściciela (decyzje, panele, odbiory, odczyty) jest w
[`../KROKI_WLASCICIELA_2026-09-29.md`](../KROKI_WLASCICIELA_2026-09-29.md), w paczce G.

## Co jest na `main`

- Paczka F, PR #2210, scalona `734bed9c6`. Wdrożona na produkcji 14:18 UTC, CI na `main` zielone (25/25).
- Zamknięte w tej sesji z dowodem w komentarzu:
  - #988, #2050, #2178, #35, #1985;
  - PR #2145, wchłonięty przez F;
  - automatycznie przez F: #2149, #2038, #1687.

## Otwarte PR

- **#2211, paczka G** (`claude/paczka-g-kandydat`, ostatni push `fc0c8fdfe`).
  - Zamyka #1731, #970, #1387, #1000.
  - Na poprzednim commicie wszystko było zielone poza kontrolą ujemną 2/3. `fc0c8fdfe` poprawia jej wzorzec (lokalnie POTWIERDZONA).
  - Scalić merge commitem, gdy CI zielone.
  - Po scaleniu zamknąć z dowodem #28. Kod OCR, adresu, PDF i mikrodanych jest w repo, a skuteczność na prawdziwych skanach to krok właściciela.

## Gałęzie gotowe do paczki H

Wszystkie są od `claude/paczka-g-kandydat` albo nowsze. Recenzja oznaczona w [`REJESTR.md`](REJESTR.md).

| Gałąź | Issue | Stan |
|---|---|---|
| `claude/970-ugotowalem-na-g` | #970 (D13) | recenzja GOTOWE |
| `claude/v2-odblokowanie` | D-331 | `FEATURES.md` i `DECISIONS.md`, testy dokumentów zielone |
| `claude/2130-wersja-slownika` | #2130, wpis w dzienniku dla #2130 | w recenzji H2 |
| `claude/health-kontrakt` | #2212 | w recenzji H2 |
| `claude/599-wolna-strona-glowna` | #599 (test zapytań `/`) | w recenzji H2 |
| `claude/1997-zakresy-czasu` | #1997 | w recenzji H1 (z testem przeglądarkowym) |
| `claude/2000-udostepnianie-zeszytu` | #2000 | w recenzji H1 |
| `claude/1996-kalorie-jsonld` | #1996 | w recenzji H1 |
| `claude/1751-forma-zwracania` | #1751, #1752, #1753 (część), wpis w dzienniku dla #1751 | w toku: nowa wersja polityki (zmiana istotna, D-327) |
| `claude/railway-pro-wykorzystanie` | D1 | dokument `docs/infra/RAILWAY_PRO_WYKORZYSTANIE.md` |
| `claude/paczka-f-poprawki` | #1011 | dwa wzorce kontroli (drugi objaw „insert into”) |
| `claude/handover-2909-b` | — | ten handover |

W toku u agentów, gałęzie jeszcze niegotowe:

- #2024, historia wersji przepisu;
- #2016, synchronizacja postępu gotowania;
- #1011, wzorce oczekiwanej przyczyny (gałąź `claude/1011-oczekiwana-przyczyna-v2`).

## Decyzje właściciela z 29.09

| Kod | Decyzja |
|---|---|
| D1 | Płatny plan Railway, raczej Pro; limit 100 USD i alert 60 USD |
| D2 | #2130: wersja rosnąca słownika |
| D3 | Okno 130 s zostaje na alfę |
| D4 | #22: P3, po bramce WAC/D30 |
| D7 | #1751: forma neutralna domyślna, bez czekania na prawnika; zmiana polityki ISTOTNA (nowa wersja i pasek) |
| D8 | Nic nowego w AI; podpisać DPA z OpenAI |
| D9 | Claim „nie zginą”: nie, do czasu przetestowanego odtworzenia |
| D10 | #602: odłożyć z warunkami |
| D11 | Alarmy idą na Discord (już działa przez zmienną w Railway) |
| D12 | DMARC przez Cloudflare Email Routing |
| D13 | Scalić refaktor `CookedEventController` |
| D14 | `HealthController`: osobne issue #2212, najpierw test kształtu |
| D15 | Odblokowane #1997, #2000, #1996, #2024, #2016 (D-331) |
| D16 | Akceptacja poprawionych ścieżek w `DECISIONS.md` |
| D17 | #1687 bez ręcznej kontroli ujemnej |
| D19 | Kasować zbędne gałęzie po sprawdzeniu scalenia |

Numery decyzji: wpis w dzienniku dla #2130 (#2130), D-331 (V2), wpis w dzienniku dla #1751 (#1751). Następny wolny to D-333.

## Po stronie właściciela (sesja tego nie zrobi)

- Plan Pro w Railway i limit 100/60 USD (D1); okres próbny kończy się ok. 6.10.
- Podpisanie DPA z OpenAI (D8) i włączenie Cloudflare Email Routing (D12).
- Kasowanie gałęzi (D19): sesja dostaje 403 przy `push --delete`.
  - Do skasowania: `codex/2130-kontrola-ujemna` (scalona), `codex/2059-kontrola-ujemna` („sabotaż poprawki”) i `codex/2014-date-modified-jsonld` (dubel).
  - Do oceny: `codex/2066-urgent-alert-negative`, `codex/hide-expiry-local-date`, `claude/larastan-test-zamiaru-ugotowania`.
- Alarm „Łączny czas zapytań SQL” na `GET /` (12:28 i 12:40 UTC, 1,1–1,3 s) wystąpił zaraz po wdrożeniu 0.77.001.
  - Podejrzenie: kompilacja JIT w PostgreSQL przy feedzie obserwowanych.
  - Sprawdzić na bazie produkcyjnej `SHOW jit; SHOW jit_above_cost;`.
  - Szczegóły w [`REJESTR.md`](REJESTR.md) i w raporcie gałęzi `claude/599-wolna-strona-glowna`.

## Pułapki tej sesji

- Test `/health` lokalnie oblewa, gdy powłoka ma `AWS_ACCESS_KEY_ID` i `AWS_SECRET_ACCESS_KEY` bez `AWS_ENDPOINT`; przed testami `unset`. Zmienna `AI_AGENT` przełącza `artisan test` na wyjście JSON, przez co `RiskyTestFailsGateTest` oblewa.
- Strażnik `PodzialWierszyNieRozrywaLiterTest` traktuje każdy literał z ukośnikiem i `R` jako wzorzec. Nazwy klas w stylu `...\Http\Request` w stringach trzeba sklejać.
- Kontrole ujemne: werdykt wymaga, żeby KAŻDY oblany test pasował do wzorca. Gdy mutacja ma kilka objawów, trzeba albo dodać alternatywę we wzorcu, albo zawęzić filtr do jednego testu (przykład: autozapis kreatora).
- Miernik panelu (`scripts/panel-marki-run.mjs`) lokalnie potrzebuje świeżej, zmigrowanej bazy, `CHROMIUM_PATH=/opt/pw-browsers/chromium` i pustego `storage/port-panelu`.
- `git worktree add` z `-C <repo>` i ścieżką względną tworzy worktree w repo; używać ścieżek bezwzględnych.
