# Monitoring #599 — co jest w repozytorium i co zostaje w panelach

> **Dopisane 25.09.2026.** Ten dokument nie powtarza mechanizmów opisanych
> w [`MONITORING_BLEDOW.md`](MONITORING_BLEDOW.md) i
> [`MONITORING_ODBIOR_2026_09_20.md`](MONITORING_ODBIOR_2026_09_20.md).
> Zbiera w jednym miejscu **kroki właściciela** i to, co aplikacja daje
> dziś, żeby je wykonać. Żaden punkt z części B nie jest wykonany tylko
> dlatego, że ten dokument istnieje — to są stany paneli zewnętrznych.

## A. Co daje repozytorium (kod na `main` + ten pakiet)

| Sygnał z #599 | Mechanizm | Stan domyślny |
|---|---|---|
| błędy 500, awarie tła | kanał `blad_webhook` (D-041) | wyłączony bez `LOG_BLAD_WEBHOOK_URL` |
| dostępność z zewnątrz | `GET /health` (200 `ok`/`degraded`, 503 przy awarii bazy) | działa; brakuje monitora z zewnątrz |
| zaległość i martwy worker, świeże `failed_jobs` | `kuking:sprawdz-kolejke` co 15 min | działa; alarm na kanale webhooka |
| **kolejki osobno: `high` / `default` / `media` / `low`** | pole `kolejki` w linii `kuking:sprawdz-kolejke {...}` kanału `pomiary` i druga tabela w konsoli — **nowe w tym pakiecie** | działa; tylko szereg czasowy, alarm zostaje na sumie |
| presja na połączenia PostgreSQL | `kuking:budzet-polaczen` co godzinę, progi 50 / 125 (`docs/DATABASE.md` §598) | działa |
| **stojący harmonogram** (milkną wszystkie czujki naraz) | `kuking:puls-harmonogramu` co 5 min → zewnętrzny monitor typu *heartbeat* — **nowe w tym pakiecie** | **wyłączony** bez `KUKING_PULS_HARMONOGRAMU_URL` |
| podgląd kolejki bez konsoli | `/admin/kolejka` (tylko administrator) | działa |

Czego repozytorium **nie** daje i świadomie nie udaje: percentyli HTTP
(p50/p95/p99), RPS i concurrency per klasa tras, czasu zapytań, lock waits,
IOPS, cache hit ratio Cloudflare. Railway i Cloudflare mierzą część z tego
w swoich panelach (część C). Własna instrumentacja albo APM to osobna
decyzja z kosztem — patrz §D.

## B. Kroki właściciela — w tej kolejności

Każdy krok ma wynik do zapisania w #599 (data + co zobaczyłeś). Szacunki
czasu są orientacyjne.

### B1. Kanał alarmów (10 min) — bez niego nic niżej nie dzwoni

1. Załóż webhook Discorda albo Slacka: [`MONITORING_BLEDOW.md`](MONITORING_BLEDOW.md) §1.
2. W Railway ustaw `LOG_BLAD_WEBHOOK_URL` **najpierw na stagingu**.
3. Na stagingu: `php artisan kuking:sprawdz-alarm` — wiadomość ma dojść.
   Nie wywołuj celowego błędu na produkcji.
4. Dopiero potem ta sama zmienna na produkcji.

**Wynik do zapisania:** godzina testu i to, że wiadomość doszła.

### B2. Zewnętrzny monitor dostępności (15 min)

Szczegóły i wybór dostawcy: [`MONITORING_BLEDOW.md`](MONITORING_BLEDOW.md) §6.
Minimum z #599:

| Monitor | Adres | Warunek alarmu |
|---|---|---|
| strona główna | `https://kuking.pl/` | kod ≠ 200 przez 2 kolejne sprawdzenia |
| zdrowie | `https://kuking.pl/health` | kod ≠ 200 przez 2 kolejne sprawdzenia |

Na domenie `kuking.pl`, **nie** na adresie `*.up.railway.app` — inaczej
awaria DNS albo Cloudflare przejdzie niezauważona. Interwał 1–5 min.
Jeden jawny kontakt alarmowy (e-mail albo telefon właściciela).

**Kontrolowany test niedostępności — tylko staging:** dodaj taki sam monitor
na adres stagingu, zatrzymaj serwis stagingu w Railway na 5 minut, potwierdź,
że alarm doszedł, uruchom serwis i potwierdź wiadomość o powrocie.

### B3. Puls harmonogramu (10 min) — nowe

1. U dostawcy z B2 (albo w Healthchecks.io) załóż monitor typu
   *heartbeat* / *cron job*: oczekiwany sygnał **co 5 minut**, tolerancja
   (*grace*) **10 minut**. Dostawca da adres z tokenem.
2. W Railway ustaw `KUKING_PULS_HARMONOGRAMU_URL` na ten adres — w usłudze,
   w której chodzi harmonogram (dziś rola `all`; po #595 rola `scheduler`).
   Adres ma być `https://`; inny komenda odrzuci bez wysyłania.
   **Nie wpisuj go do repozytorium ani do issue** — kto zna adres, może
   „karmić" monitor i zagłuszyć prawdziwą awarię.
3. Po wdrożeniu poczekaj 10 minut: monitor ma pokazać regularne sygnały.
4. Test na stagingu: ustaw tę zmienną na stagingu na osobny monitor,
   zatrzymaj harmonogram (np. zatrzymaj serwis) na 20 minut i potwierdź
   alarm.

Wyłączenie: usuń zmienną — komenda przestaje wysyłać cokolwiek.

### B4. Budżet kosztów Railway (5 min)

Railway → *Workspace settings* → *Usage* / *Billing*: ustaw alert użycia
(np. 80% miesięcznego budżetu) i twardy limit, jeśli plan go ma. Nazwy
opcji w panelu **nie były sprawdzane** w sesji, która to pisała.

**Wynik do zapisania:** próg alertu w złotówkach/dolarach i adres, na który
przychodzi.

### B5. Panele zasobów (15 min, raz)

- Railway → serwis → *Metrics*: CPU, RAM, sieć, restarty. Po #595
  sprawdź, że `web`, `worker` i `scheduler` są osobnymi serwisami — wtedy
  metryki są rozdzielone same.
- Railway → Postgres → *Metrics*: CPU, RAM, dysk.
- Cloudflare → *Analytics & Logs*: żądania do originu vs z cache, transfer,
  błędy 5xx. To jest źródło dla „cache hit ratio" z #599.

**Wynik do zapisania:** zrzut ekranu każdego panelu przed i po najbliższym
wdrożeniu — to jest porównanie „przed/po" z definicji gotowości #599.

### B6. Szereg czasowy kolejek i połączeń (5 min, raz w tygodniu)

Railway → *Logs*, filtr `kuking:sprawdz-kolejke` i `kuking:budzet-polaczen`.
Pole `kolejki.media.zaleglosc_sekundy` pokazuje, ile czekają zdjęcia,
niezależnie od resetów hasła w `high`. Szczyt połączeń:
`php scripts/szczyt-polaczen-z-dziennika.php` (gałąź #598, `docs/DATABASE.md`
§598 G), jeśli ta zmiana jest już na `main`.

## C. Co mierzą dostawcy, a czego nie trzeba budować

| Z listy #599 | Gdzie to już jest |
|---|---|
| CPU / RSS per usługa, restarty, redeploy failures | Railway *Metrics* i *Deployments* |
| DB CPU / storage | Railway, usługa Postgres |
| cache hit, origin requests, transfer | Cloudflare *Analytics* |
| error rate 5xx na brzegu | Cloudflare *Analytics* (bez rozbicia na trasy) |

## D. Czego ten pakiet nie robi — decyzja właściciela

**Percentyle HTTP, RPS i concurrency per klasa tras (feed, search, przepis,
`/zdjecia/*`)** wymagają instrumentacji każdego żądania. Dwie drogi:

1. własny pomiar w middleware (czas, kod, nazwa trasy; bez URL, IP
   i użytkownika) z agregacją w oknach — osobny pakiet i pomiar narzutu;
2. APM/error tracker (np. Sentry, D-041 jako kierunek docelowy) — koszt
   zależny od ingestu, nowa zależność i nowa usługa.

Ten pakiet nie wybiera za właściciela: obie drogi dokładają albo koszt,
albo stały narzut na każde żądanie. Bezpieczniejsze i odwracalne było
przygotowanie sygnałów, które nie wymagają ani jednego, ani drugiego.

## E. Wycofanie

Odwrócenie commita. Nie ma migracji. Puls bez zmiennej nie wysyła nic,
a pole `kolejki` jest tylko dodatkowym kluczem w linii dziennika.
