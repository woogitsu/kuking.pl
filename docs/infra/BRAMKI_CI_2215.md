# Bramki CI: wyścigi i audyt zależności blokują (#2215)

## Polityka

„Zielony CI” znaczy: przeszły też kontrole bezpieczeństwa i współbieżności.
Żadna z nich nie ma `continue-on-error`.

| Kontrola | Job w `ci.yml` | Co blokuje | Jak odblokować pilny hotfix |
|---|---|---|---|
| Wyścigi na dwóch połączeniach (D-105) | `dwa-polaczenia` | każda czerwona grupa | nie ma wyjątku: napraw test (D-105, „Gdy grupa zacznie migać”) |
| Audyt Composer | `audit` | podatność `high`, `critical` albo bez podanego poziomu | wyjątek w `.github/wyjatki-audytu.json` |
| Audyt NPM | `audit` | podatność `high`, `critical` (razem z devDependencies: cały front-end tam siedzi) | wyjątek w `.github/wyjatki-audytu.json` |

Poziomy niższe (low, medium, moderate) trafiają do podsumowania joba jako
informacja i nie blokują. Werdykt wydaje `scripts/audyt-zaleznosci.py`
(testy: `scripts/test_audyt_zaleznosci.py`, biegną w jobie `lint`).
Jeśli `composer audit` albo `npm audit` nie wypiszą poprawnego JSON-a
(sieć, rejestr), job jest czerwony: bramka nie zgaduje.

Pilnuje tego `tests/Feature/KrytyczneKontroleCiBlokujaTest.php` (flaga nie wraca
na `audit`, żadne nowe `continue-on-error` w `ci.yml` bez wpisu na listę
dozwolonych, nazwa joba nie obiecuje „nie blokuje”) oraz
`WyscigiDwochPolaczenBlokujaCiTest` (to samo dla `dwa-polaczenia`).

## Stan 20 przebiegów race testu

Warunek zdjęcia flagi (20 kolejnych przebiegów bez czerwieni) był spełniony
29.09.2026: w oknie 40 przebiegów `main` i 40 przebiegów PR job `dwa-polaczenia`
miał 38 zakończeń `success` (11 na `main`, 27 na PR) i żadnego czerwonego kroku;
reszta to `cancelled` przez `concurrency`. Czas 2,0–4,5 min. Liczono kroki, nie
`conclusion` joba, bo przy `continue-on-error` ono maskuje porażkę. Szczegóły:
komentarz przy jobie w `ci.yml` (#611, etap 9). Jeśli grupa zacznie migać, nie
przywracaj flagi, tylko napraw test.

## Wyjątek od audytu zależności

Plik `.github/wyjatki-audytu.json`, lista `wyjatki`. Każdy wpis ma komplet pól:

```json
{
  "id": "GHSA-xxxx-xxxx-xxxx",
  "narzedzie": "npm",
  "zakres": "tylko narzędzie buildu; nie trafia do przeglądarki ani na serwer",
  "wlasciciel": "kto odpowiada za usunięcie wyjątku",
  "zgoda": "odnośnik do issue albo PR ze zgodą właściciela",
  "wygasa": "RRRR-MM-DD, najdalej 30 dni od dziś"
}
```

- `id` to CVE, GHSA albo PKSA (bramka porównuje z każdym identyfikatorem zalecenia).
- Wyjątek bez pola, z datą w przeszłości albo dalszą niż 30 dni **blokuje CI**
  (wygasły wyjątek wymusza decyzję: usuń albo świadomie odnów).
- Wyjątek wchodzi zwykłym PR-em, więc zgoda właściciela jest widoczna w historii.

## Kto i kiedy ocenia wynik

- Czerwony `audit` na PR-ze: autor PR-a w tym samym dniu roboczym decyduje:
  aktualizacja pakietu (preferowana) albo wyjątek do zatwierdzenia przez właściciela.
- Nowe zalecenie pojawia się na `main` bez zmiany kodu (kolejny push, harmonogram
  Dependabota): właściciel ocenia w ciągu 2 dni roboczych (high) albo tego samego
  dnia (critical, gdy pakiet jest w ścieżce produkcyjnej).
- Cotygodniowy przegląd z `DEPLOYMENT_RUNBOOK.md` (krok 15) obejmuje listę
  wyjątków i ich terminy.

## Ochrona gałęzi i bramka Railway

Ustawienia „required status checks” w GitHub Settings zmienia właściciel. Lista
nazw dokładnie tak, jak GitHub je pokazuje (jobs pominięte przez bramkę `zakres`
liczą się jako przechodzące):

1. `Testy (PostgreSQL 18)` (job zbiorczy, czeka na 4 części testów i 3 części kontroli ujemnych)
2. `Pint (styl kodu)`
3. `Larastan (analiza statyczna)`
4. `Wyścigi na dwóch połączeniach`
5. `Audyt zależności (blokuje high i critical)`
6. `Build assetów (Vite)`
7. `Build obrazu (weryfikacja)`
8. `Dostępność (axe-core) i wydajność (Lighthouse)`
9. `Port marki (kompozycje, zoom i kontrole ujemne)`
10. `Panel marki — puste i pełne widoki` (od #2299 job zbiorczy `panel_marki`,
    czeka na dwie części `port_panelu`; nazwa się nie zmieniła, więc ochrona
    gałęzi nie wymaga zmiany)

**Nie oznaczaj jako wymaganych części panelu** (`Panel marki — puste i pełne
widoki (część 1/2)` i `(część 2/2)`) — z tego samego powodu co części
`port_funkcje` niżej. Wymagany jest job zbiorczy o dawnej nazwie.

**Nie oznaczaj jako wymaganych dwóch części `port_funkcje`**
(`Port marki — rodziny ekranów, zoom i kreator (część 1/2)` i `(część 2/2)`).
To job z macierzą i warunkiem `if` na poziomie joba: gdy bramka `zakres`
go pomija (PR bez zmian widoku), GitHub nie rozwija macierzy i zgłasza jeden
pominięty check z nierozwiniętą nazwą, więc wymagane „część 1/2” i „część 2/2”
nigdy by nie powstały i PR czekałby w nieskończoność. Tak samo zrobiono przy
macierzy `test`: wymagany jest job zbiorczy `Testy (PostgreSQL 18)`. Jeśli
`port_funkcje` ma być wymagany, najpierw trzeba dodać analogiczny job zbiorczy.

Pozycje 1–5 są minimum dla #2215 (1–3 i 6–8 to lista z `scripts/stan-ci.sh`).
Nazwy 4 i 5 (oraz nazwy części `port_funkcje`) zmieniły się w tym PR-ze i w #611 (etap 9): po scaleniu
sprawdź je w zakładce Checks pierwszego przebiegu, zanim oznaczysz jako wymagane
(zła nazwa = check, który nigdy nie powstaje, i PR czeka w nieskończoność).
Nie oznaczaj `Zakres zmiany` ani `Przyrząd testu obciążeniowego (#605)`.

**Draft PR-y (od 2.10.2026, wiersz w D-333).** Na drafcie CI biegnie w zakresie
skróconym: Pint, Larastan i testy w częściach 1–4. Pozycje 4–9 są wtedy
`skipped` (czyli dla GitHuba „przechodzące”), a agregaty 1 i 10 są zielone
z adnotacją „draft — zakres skrócony”. Pełny przebieg rusza po
`ready_for_review`. Szczegóły i lista jobów: `SELF_HOSTED_RUNNER.md` →
„Zakres CI na draft PR-ach”.

### #2025: wdrożenie mimo czerwonego albo anulowanego CI

Bramka Railway (`docs/infra/RAILWAY_CI_GATE.md`,
`.github/workflows/railway-ci-gated-deploy.yml`) wymaga, żeby CAŁY przebieg CI
dla SHA na `main` miał `completed/success`, oraz sukcesu joba `Testy (PostgreSQL 18)`.
Anulowany albo czerwony przebieg nie wdraża. Ponieważ `dwa-polaczenia` i `audit`
nie mają już `continue-on-error`, ich czerwień czerwieni przebieg, więc bramka też
ich pilnuje. Bramka jest jednak **domyślnie wyłączona** (zmienna
`KUKING_CI_GATED_RAILWAY_DEPLOY`); dopóki właściciel jej nie włączy według
kroków z `RAILWAY_CI_GATE.md`, deploy z autodeploy Railway opiera się na
„Wait for CI”, który anulowany workflow może przepuścić. To ustawienie jest po
stronie panelu i właściciela, nie repozytorium.
