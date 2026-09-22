# Pomiar kroku „Checkout" w jobach pomiarowych CI — wartość „przed"

**Po co ten dokument.** Usuwamy `fetch-depth: 0` z trzech jobów pomiarowych
(`port_marki`, `port_funkcje`, `dostepnosc`). Issue #611 ostrzega wprost:
„Nie zmieniać tego w ciemno na `fetch-depth: 1`". Właściciel zdecydował:
**zmierzyć na prawdziwym runnerze, zanim się rozstrzygnie**. Żeby po scaleniu
dało się w ogóle powiedzieć, czy coś się zmieniło, wartość „przed" musi być
zapisana **teraz** — po scaleniu nie da się jej już odtworzyć bez cofania kodu.

Data zebrania: 21 września 2026. Repozytorium: `woogitsu/kuking.pl`,
workflow `ci.yml`. Źródło: GitHub Actions API (`.../jobs`, pole `steps[]`,
krok `Run actions/checkout@v7`). Liczony czas to `completed_at - started_at`
samego kroku checkoutu, nie całego joba.

## Jaki stan opisuje ta tabela

Wszystkie przebiegi poniżej wykonały się na `ci.yml`, w którym trzy joby
pomiarowe **miały** `fetch-depth: 0`. Stan ten potwierdzony odczytem
`.github/workflows/ci.yml` z gałęzi `main` (`fetch-depth: 0` przy checkoutach
`zakres`, `port_marki`, `port_funkcje`, `dostepnosc`). Zmiana, którą mierzymy,
nie była wypchnięta w chwili zbierania danych.

## Tabela „przed" — czas kroku Checkout

| Przebieg | Gałąź | Start (UTC) | port_marki | port_funkcje | dostepnosc |
|---|---|---|---|---|---|
| 35550606725 | main | 2026-09-21 01:21 | 4 s | 5 s | 8 s |
| 35545852050 | codex/audyt-ux50plus | 2026-09-20 23:51 | 3 s | 3 s | 4 s |
| 35543262466 | flota/komentarze | 2026-09-20 23:20 | 3 s | 3 s | 4 s |
| 35543256752 | flota/relacje | 2026-09-20 23:19 | 4 s | 4 s | 4 s |
| 35543256461 | robota/martwe-reguly-css | 2026-09-20 23:18 | 4 s | 4 s | 3 s |
| 35543246435 | codex/audyt-ux50plus | 2026-09-20 23:16 | 3 s | 3 s | 3 s |
| 35543259956 | flota/zdjecia-formularze | 2026-09-20 23:14 | 3 s | 5 s | 3 s |
| 35543242698 | flota/gotowanie | 2026-09-20 22:59 | 4 s | 3 s | 4 s |
| 35543233585 | main | 2026-09-20 22:58 | 5 s | 3 s | 5 s |
| 35541818179 | flota/dsa-odwolania | 2026-09-20 22:29 | 5 s | 4 s | 6 s |
| 35541797391 | main | 2026-09-20 22:29 | 3 s | 5 s | 3 s |
| 35540263733 | naprawa/klient-pg18-w-ci | 2026-09-20 21:59 | 4 s | 4 s | 4 s |
| 35535369797 | flota/dsa-odwolania | 2026-09-20 20:23 | 4 s | 4 s | 4 s |
| 35535179533 | naprawa/klient-pg18-w-ci | 2026-09-20 20:20 | 6 s | 3 s | 4 s |
| 35529770686 | flota/komentarze | 2026-09-20 18:48 | 3 s | 4 s | 4 s |
| 35529754953 | flota/zdjecia-formularze | 2026-09-20 18:42 | 4 s | 6 s | 5 s |
| 35529752465 | flota/relacje | 2026-09-20 18:39 | 5 s | 4 s | 4 s |
| 35526140472 | robota/martwe-reguly-css | 2026-09-20 18:11 | 3 s | 5 s | 3 s |
| 35525964621 | flota/dsa-odwolania | 2026-09-20 17:58 | 7 s | 5 s | 5 s |
| 35526106460 | codex/audyt-ux50plus | 2026-09-20 17:46 | 4 s | 4 s | 5 s |
| 35525961722 | flota/gotowanie | 2026-09-20 17:32 | 3 s | 3 s | 5 s |
| 35518782260 | main | 2026-09-20 15:16 | 3 s | 5 s | 2 s |
| 35504452271 | main | 2026-09-20 10:20 | 1 s | — | — |
| 35501113990 | bledy-ekrany | 2026-09-20 09:39 | 2 s | 2 s | 2 s |
| 35501115635 | feat/l1-wyjmij-wpis-z-zeszytu | 2026-09-20 09:03 | 1 s | 2 s | 1 s |
| 35500778283 | main | 2026-09-20 08:59 | 1 s | 1 s | 3 s |
| 35498048735 | main | 2026-09-20 07:54 | 1 s | 3 s | 1 s |
| 35496520284 | fix/737-tagi-w-tresci | 2026-09-20 07:33 | 3 s | 1 s | 4 s |
| 35495564690 | audyt/8-procedury-bramka | 2026-09-20 07:30 | 1 s | 1 s | 8 s |
| 35495316307 | chore/611-uproszczenie-ci | 2026-09-20 07:28 | 3 s | 3 s | 1 s |
| 35495117221 | fix/przyrzad-mtime | 2026-09-20 07:18 | 1 s | 3 s | 2 s |
| 35495116398 | fix/wyciek-zetonu-w-adresie | 2026-09-20 07:01 | 2 s | 2 s | 1 s |
| 35470625027 | codex/717-panel-kolejki | 2026-09-19 21:32 | 2 s | 3 s | 4 s |
| 35470502611 | main | 2026-09-19 21:29 | 3 s | 1 s | 2 s |
| 35462113208 | fix/713-kod-z-danymi | 2026-09-19 18:44 | 1 s | 3 s | 2 s |

Myślnik = job pominięty albo bez kroku checkoutu w tym przebiegu
(warunek `needs.zakres.outputs.widok`).

### Podsumowanie „przed"

| Job | n | min | mediana | średnia | max |
|---|---|---|---|---|---|
| `port_marki` | 35 | 1 s | 3 s | 3,1 s | 7 s |
| `port_funkcje` | 34 | 1 s | 3 s | 3,4 s | 6 s |
| `dostepnosc` | 34 | 1 s | 4 s | 3,6 s | 8 s |

## Dwa odstające przypadki z issue #611 — potwierdzone własnym odczytem

To są **te same** przebiegi, o których mówi #611. Sprawdzone przez API,
nie przepisane z komentarza:

| Job | Przebieg | Data | Czas kroku Checkout |
|---|---|---|---|
| `dostepnosc` (job 105188950373) | 35217360716 | 2026-09-17 11:46 UTC | **616 s** (11:46:15 → 11:56:31) |
| `port_marki` (job 104815343707) | 35102532509 | 2026-09-16 13:33 UTC | **982 s** (13:33:18 → 13:49:40) |

Pierwszy z nich zjadł ponad połowę dwudziestominutowego limitu zadania
i Lighthouse został przerwany komunikatem o przekroczeniu czasu.

## Co z tego już widać, zanim zmierzymy „po"

Mediana checkoutu z `fetch-depth: 0` to **3–4 sekundy**, maksimum w 35
przebiegach to **8 sekund**. Historia tej gałęzi waży ok. 6 MB, czyli ok. 3 %
pobrania. Dwa przypadki po 10 i 16 minut są od typowego przebiegu **dwa rzędy
wielkości** dalej. To zgadza się z ostrzeżeniem z #611: przyczyną tamtych
zawieszeń **najpewniej nie była objętość historii**, tylko coś w sieci albo
w samej sesji fetch — czego #611 jawnie nie ustalił.

## Czego ten pomiar NIE rozstrzygnie

**Nie rozstrzygnie, czy usunięcie `fetch-depth: 0` usuwa awarie z issue #611.**
Mierzymy medianę i maksimum w normalnym przebiegu, a problem z #611 jest
zdarzeniem rzadkim (2 przypadki na kilkadziesiąt przebiegów) i o nieustalonej
przyczynie. Nawet komplet zielonych, szybkich przebiegów „po" **nie będzie
dowodem**, że zawieszenie 10-minutowe już nie wróci — będzie tylko dowodem, że
w typowym przebiegu oszczędzamy sekundy, nie minuty. Żeby powiedzieć cokolwiek
o awariach, trzeba obserwować **ogon rozkładu przez porównywalną liczbę
przebiegów**, nie średnią z kilku.

Ten pomiar rozstrzyga dokładnie jedno: **ile kosztuje pełna historia w zwykłym
przebiegu**. Nic więcej.

## Jak zebrać wartość „po" tym samym sposobem

Po scaleniu, z tokenem w środowisku:

```sh
gh api "repos/woogitsu/kuking.pl/actions/runs/<ID>/jobs?per_page=100" \
  -q '.jobs[] | select(.name|test("^Port marki|^Dostępność")) | . as $j
      | $j.steps[] | select(.name=="Run actions/checkout@v7")
      | "\($j.name)\t\(.started_at)\t\(.completed_at)"'
```

Porównuj **medianę i maksimum**, osobno dla każdego z trzech jobów, na
zbiorze co najmniej tak licznym jak tabela wyżej (34–35 przebiegów).
Porównanie pojedynczego przebiegu z medianą 35 nic nie powie.
