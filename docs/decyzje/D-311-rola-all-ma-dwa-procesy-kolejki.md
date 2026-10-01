## D-311 — Rola `all` ma dwa procesy kolejki: lekki `high,default` i ciężki `media,low` (#1860, luka po #1030, 26 września 2026)

Dotyczy **#1030**, **#1860**, PR-a #1622

**Problem.** #1030 kazał dać każdej kolejce z producentem niezerową
przepustowość także przy stałej zaległości kolejki wyżej. PR #1622 zrobił to
w roli `worker` (proces na kolejkę), a rolę `all` — czyli produkcję dziś,
jeden kontener 1024 MB z FrankenPHP i harmonogramem — świadomie zostawił przy
jednym procesie `high,default,media,low`. Powodem była pamięć: trzy procesy
mogłyby mieć szczyt naraz (zdjęcie 50 Mpx ~452 MB, eksport do 512M, WWW).
Skutek: fala maili na `default` wstrzymywała zdjęcia i eksport RODO dokładnie
tak, jak opisywał #1030 — na jedynej topologii, która dziś działa.

**Decyzja.** Rola `all` uruchamia domyślnie **dwa** procesy:

| Proces | Kolejki | Co tam jest |
|---|---|---|
| lekki | `high,default` | listy wejścia na konto, powiadomienia, purge CDN |
| ciężki | `media,low` | przetwarzanie zdjęć, eksport danych, analiza treści i awatara |

Argument pamięciowy z #1622 zostaje w mocy i właśnie dlatego procesy są dwa,
a nie trzy: `media` i `low` dzielą jeden proces, więc ich szczyty się nie
schodzą. Lekki proces dokłada tyle, ile pusty PHP z frameworkiem — ta sama
miara, którą `.railway/railway.ts` przyjął dla czwartego procesu (`high`)
w roli `worker`.

**Świadomie zostaje jedno ograniczenie:** `low` czeka za stałą zaległością
`media`. Zdjęć przybywa tylko z publikacji ludzi, nie z automatu, więc stała
zaległość `media` sama jest awarią widoczną w `kuking:sprawdz-kolejke`.
Pełnym lekarstwem pozostaje wydzielony worker (`PRODUCTION_SPLIT_SERVICES`).

**W kodzie.** `listy_kolejek()` w `docker/entrypoint.sh`
(`wspolnyKontener="high,default media,low"`). `QUEUE_WORKERS` i `QUEUE_NAMES`
działają jak dotąd i wygrywają z wartością domyślną. Pilnują:
`KolejkiBezGlodzeniaTest::test_rola_all_zdjecie_i_eksport_ruszaja_mimo_stalej_zaleglosci_default`
(prawdziwy `Worker` na sterowniku `database`; kontrola ujemna — `low`
w procesie z `default` — wywraca go),
`UmowaKolejkiTest::test_rola_all_ma_lekki_proces_i_ciezki_ze_zdjeciem_przed_eksportem`
i `tests/skrypty/entrypoint-nadzor.sh`.

**Czego nie zmierzono.** Rzeczywistego RSS lekkiego procesu na produkcji.
Po wdrożeniu właściciel sprawdza w panelu Railway szczyt pamięci serwisu
w oknie 7 dni (przed zmianą: 0,53 GB z 1,0 GB, odczyt z 17.09.2026, #598).

### Wycofanie
Bez zmian schematu i danych. Natychmiast, bez wdrożenia kodu: zmienna
`QUEUE_WORKERS="high,default,media,low"` w panelu Railway i restart — wraca
jeden proces. Trwale: przywrócenie jednej listy w `listy_kolejek()` razem
z testami.
