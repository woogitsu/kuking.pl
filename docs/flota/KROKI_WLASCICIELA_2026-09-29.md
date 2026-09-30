# Kroki właściciela — jedna lista (29.09.2026)

Dla: właściciela. Stan: gałąź `claude/paczka-f` (PR #2210), po wydaniu Alfa 0.77 na `main`.
Źródła: rejestr koordynatora z 29.09, [handover koordynatora](HANDOVER_SESJI_KOORDYNATORA_2026-09-29.md) §5–6,
[`sesja-glowna-2909/`](sesja-glowna-2909/), issue #1895 (treść i 12 komentarzy) oraz dokumenty podlinkowane niżej.

**Ten dokument niczego nie zmienia w panelach, na produkcji ani na GitHubie.** Zbiera w jednym miejscu to, czego
nie mogą zrobić sesje Claude: decyzje, kliknięcia w panelach, odbiory z ludźmi i urządzeniami oraz odczyty
na produkcji. Szczegóły kliknięć zostają w dokumentach źródłowych (linki). **Sekretów tu nie ma — tylko nazwy zmiennych.**
Wartości wpisuj wyłącznie w panelu; do issue wklejaj datę i wynik, nigdy klucz.

Jak używać: sekcja 1 odblokowuje pracę agentów (najtańsza, same „tak/nie”), sekcja 2 to kolejne panele,
sekcja 3 to ludzie i telefony, sekcja 4 to odczyty. Każdy krok ma dowód wykonania — wpisz go w wymienione issue z datą.

## 0. Trzy rzeczy pilne

| # | Co | Dlaczego teraz | Gdzie |
|---|---|---|---|
| P1 | **Plan płatny Railway.** Komentarz w #1895 z 28.09 (odczyt panelu, 18:18 UTC): „8 days or $2.96 left” i „Upgrade to keep your services online”. | Okres próbny kończy się ok. 6.10.2026; po nim usługi mogą przestać działać. To nie jest kwestia kodu. | D1 niżej, K8 |
| P2 | **Kolejność bramki wdrożeń.** Nie włączaj `KUKING_CI_GATED_RAILWAY_DEPLOY=true`, dopóki autodeploy GitHub nie jest wyłączony w Railway. | Inaczej każdy commit wdroży się dwa razy. Dziś w Railway działa natywne „Wait for CI” (odczyt z 28.09), więc nic nie jest zepsute — bramka to ulepszenie, nie naprawa. | K1, G2, K2, G4 |
| P3 | **Web Push (#35): najpierw polityka prywatności, potem klucze.** Na bazie `claude/paczka-f` w `resources/legal/polityka-prywatnosci.md` nie ma słowa o Web Push. | Runbook wymaga wpisu w polityce przed włączeniem (komentarz #1895 z 26.09), a #1979 (wylogowanie nie odłącza subskrypcji na współdzielonym urządzeniu) trzeba rozstrzygnąć. Sprawdź stan #1979 przed K9. | K9, O6 |

---

## 1. DECYZJE do podjęcia

Format: pytanie · warianty · **rekomendacja** · issue. Po podjęciu wystarczy jedno zdanie w issue (albo „tak, jak rekomendujesz”);
zapis w `docs/DECISIONS.md` zrobi sesja. Sesje nie decydują za Ciebie.

| # | Pytanie (jedno zdanie) | Warianty | Rekomendacja i uzasadnienie | Issue |
|---|---|---|---|---|
| D1 | Czy przechodzimy na płatny plan Railway przed końcem okresu próbnego? | **A** tak, teraz, z limitem wydatków 100 USD twardo i alertem 60 USD (decyzja z 25.09, [`LISTA_KROKOW_ALFA.md`](../infra/LISTA_KROKOW_ALFA.md) A3). **B** nie, przenosimy hosting (drogie: to odtwarzanie całej infrastruktury). | **A.** Bez planu usługi wyłączą się same; alarm kosztów (K8) trzeba i tak ustawić. Uwaga z [`POCZTA_URUCHOMIENIE.md`](../infra/POCZTA_URUCHOMIENIE.md) §0: poczta idzie przez API HTTPS EmailLabs, więc plan nie zmienia drogi wysyłki. | #595, #599 |
| D2 | Czy monotoniczna wersja danych słownika odżywczego jest wymagana, żeby starszy import nie nadpisał nowszego? | **A** wersja rosnąca (liczba całkowita) zapisana w bazie w tej samej transakcji co słownik; import tylko gdy wersja pliku jest wyższa albo baza niekompletna. **B** zostaje hash plików (dziś na `main`: `e17840607`, `2fbcf353d`) — po wycofaniu wdrożenia stary hash mógłby nadpisać nowszy słownik. | **A.** Wycofanie wdrożenia (rollback obrazu) jest dokładnie tym momentem, w którym B zawodzi po cichu, a kalkulator odżywczy nie zgłosi błędu. Koszt: jedna kolumna albo wiersz manifestu, mały PR. Zostaw #2130 otwarte do tego czasu. | #2130 |
| D3 | Czy okno zamknięcia workera (130 s) ma pokryć eksport danych (limit 900 s)? | **A** zostaje 130 s; przerwany eksport wraca do kolejki po `retry_after` i jest ponawiany ([`DEPLOYMENT.md`](../DEPLOYMENT.md), tabela `drainingSeconds`). **B** podnieść okno workera do ok. 900 s (każde wdrożenie podczas eksportu czeka do 15 min). **C** dzielić eksport na etapy (większy kod). | **A** na alfę, z jednym warunkiem: gdy odczyt logów pokaże choć jeden przerwany i ponowiony eksport, wracamy do B/C. Alfa ma mały ruch, a B spowalnia każde wdrożenie. Krok kontrolny: K5 (odczyt rzeczywistego `drainingSeconds`). | #1030 (zamknięte), #1860, #2056 |
| D4 | Czy grupy tematyczne (#22) zostają P1? | **A** zostaje P1 (dziś). **B** obniżyć do P3 „za bramką WAC/D30”; tag i tagi promowane pełnią rolę grup. **C** od razu pilotaż 2–3 grup (29–45 osobodni wg [`GRUPY_TEMATYCZNE_22_PROJEKT_I_KOSZT.md`](../product/GRUPY_TEMATYCZNE_22_PROJEKT_I_KOSZT.md)). | **B.** Samo issue mówi „pusta grupa jest gorsza niż brak grup”; bramka WAC/D30 nie jest spełniona (brak użytkowników, #29). P1 zabiera uwagę agentów od P0. Etykietę zmienia właściciel albo sesja po Twoim „tak”. | #22 |
| D5 | Czy lista zakupów (#27) czeka na pomiar, a `AGENTS.md` §12 dostaje wyjątek dla planera tygodnia? | **A** lista zakupów dopiero po pomiarze „plan → ugotowanie” (narzędzie: gałąź `claude/27-pomiar-planera`, patrz Q3); §12 poprawiony: „planer tygodnia jest (D-310), lista zakupów nie”. **B** budować ręczną listę teraz. | **A.** Sam #27 (korekta z 23.09) mówi: składnik to wolny tekst, „1 jajko + 2 jajka” wymaga parsera; planer nie naprawia pustej społeczności. Rozjazd: [`AGENTS.md`](../../AGENTS.md) §12 nadal zakazuje „planera posiłków”, a D-310 (zapis w [`DECISIONS.md`](../DECISIONS.md)) go wdrożył — rozjazd instrukcji jest gorszy niż brak instrukcji. Analiza: [`PLANER_ZAKUPY_27.md`](../product/PLANER_ZAKUPY_27.md). | #27, #2037 |
| D6 | Imieniny (#1756): potwierdzasz kolejność „po testach z ludźmi #15”? | **A** tak, P3, po #15 i po formie zwracania się (D7). **B** wcześniej. | **A.** To Twoja decyzja z 25.09; zależy od #1751 (te same ustawienia i polityka) i od zebrania wniosków z #15. Nic do zrobienia teraz poza potwierdzeniem. | #1756, #15 |
| D7 | Forma zwracania się (#1751): wdrażamy „Jak mamy do Ciebie pisać?” (żeńska / męska / neutralna domyślna) bez czekania na prawnika? | **A** tak: neutralna domyślna, wiersz w polityce prywatności (dana widoczna dla innych, podstawa, eksport, usunięcie); pytanie do prawnika ograniczone do jednego zdania w polityce (#8). **B** wstrzymać PR #1759 do odpowiedzi prawnika. | **A.** Decyzja produktowa jest Twoja z 25.09 (opis #1751); domyślnie „neutralna” nie ujawnia nic o osobie. Ryzyko prawne to sformułowanie w polityce, nie sama funkcja. Blokuje #1752 i #1753. Gałąź `claude/1751-forma-decyzja` jest zawarta w `claude/1753-forma-teksty` (PR #1759, czerwony, wymaga naprawy). | #1751, #1752, #1753, #8 |
| D8 | AI: co dalej z #813, #814, #815, #1983 i kiedy podpisać DPA z OpenAI? | **A** nic nie uruchamiać, dopóki nie ma jednego wpisu D-xxx (cel zgody, D-240 „do OpenAI tylko treść publiczna”, DPA, budżet, retencja). **B** pilot #813 (pomoc „Potrzebuję pomocy”, budżet 10 USD) od razu. **C** tylko import własnych przepisów (#28) i nic więcej. | **A z wyjątkiem DPA:** podpisz DPA i ustaw limit wydatków niezależnie (K10), bo dotyczy już importu (#28, #2031). #813–815 i #1983 to P3/V2 („podpowiedzi AI — jeszcze nie” w `docs/FEATURES.md`). Kolejność wg planu z raportu #1983: najpierw #813 jako pilot, dopiero potem #814, #815, #1983. | #813, #814, #815, #1983, #2031, #28 |
| D9 | Claim „Twoje przepisy nie zginą” (#30): tak czy nie? | **A** nie — ani stały, ani kontekstowy, dopóki nie ma odtworzenia bazy z kopii i #617. **B** tak, kontekstowo. | **A** — rekomendacja z [`OFFLINE_I_OBIETNICA_30.md`](../product/OFFLINE_I_OBIETNICA_30.md) §1: nie ma wykazanego odtworzenia produkcyjnej bazy, a mierzalna obietnica wymaga D-114. Zapis „odrzucone” w `DECISIONS.md` domyka punkt 2 definicji gotowości; materiał offline dla KGW/UTW to osobna decyzja (rezygnacja albo zlecenie). Obietnicę o zamknięciu serwisu rozstrzyga prawnik (#8). | #30, #8, #617 |
| D10 | Direct upload do R2 (#602): zamknąć jako „not planned”, czy odłożyć z warunkami? | **A** wariant B: zamknąć `not planned` na podstawie #605 (lokalnego). **B** wariant C: zostaje otwarte jako odłożone z warunkami wejścia (szczyt wątków FrankenPHP zbliża się do `max_threads`; p95 `POST` z plikiem > kilka sekund; upload głównym powodem skalowania replikami). | **B (C z dokumentu).** #605 był pomiarem lokalnym i nie mierzył wolnych łączy — [`DIRECT_UPLOAD_R2_ANALIZA_602.md`](../infra/DIRECT_UPLOAD_R2_ANALIZA_602.md) §6 sam mówi, że zamknięcie jest „uczciwe z zastrzeżeniem”. Razem z tym wykonaj R2 (lifecycle na `livewire-tmp/`). | #602, #605, #2051 |
| D11 | Do kogo ma iść alarm (#599)? | **A** jeden adres pocztowy, który czytasz codziennie (ten sam co odbiorca raportów DMARC, D12/C2). **B** kanał Discord/Slack przez `LOG_BLAD_WEBHOOK_URL`. **C** oba. | **C.** Kod ma już kanał webhook; a webhook bez człowieka, który go czyta, to cisza — potrzebny jest „jeden jawny kontakt alarmowy” (definicja gotowości #599). Uwaga: alarm mailem przez ten sam Kuking przestaje działać dokładnie wtedy, gdy poczta padnie, więc dla alarmów awarii zewnętrzny monitor (X1) jest ważniejszy niż poczta z aplikacji. | #599, #2049 |
| D12 | Odbiorca raportów DMARC (`rua`): Droga A (Cloudflare Email Routing dla `kontakt@kuking.pl`) czy B (osobny adres)? | **A** rekord bez zmian, jedna skrzynka na listy od ludzi i raporty. **B** osobny alias na raporty XML. | **A** ([`POCZTA_URUCHOMIENIE.md`](../infra/POCZTA_URUCHOMIENIE.md) §3A): ta skrzynka musi i tak działać (kontakt od ludzi), a jedna praca załatwia oba. Załączniki XML zasypią skrzynkę — dodaj filtr do osobnego folderu, **nie do kosza** (raporty służą decyzji o `p=quarantine`). | #2049, #204 |
| D13 | `CookedEventController` (branch `claude/970-ugotowalem`, `70706b01d`, 569→325 linii, 269 testów zielonych): scalić czy porzucić? | **A** scalić do następnej paczki. **B** wstrzymać (zostawić gałąź), wrócić po #15/#29. **C** porzucić. | **B.** Zawężenie #970 z 28.09 wyłączyło ten kontroler, a „Ugotowałem” jest najważniejszą akcją produktu (ważniejszą niż lajk, powiadamia autora przepisu z trzema granicami z `AGENTS.md`). Refaktor bez zysku dla użytkownika tuż przed pierwszymi ludźmi to zły stosunek ryzyka do korzyści; testy zielone nie zmieniają zakresu. Gałąź nie znika — decyzja odwracalna. | #970 |
| D14 | `HealthController` (782 linie, największy kontroler): osobne issue? | **A** tak, nowe issue P3, „po odbiorze #599”. **B** wpisać do #970. **C** nic. | **A.** `/health` jest sondą zewnętrznego monitora (#599); jego przebudowa razem z refaktorem walidacji utrudni ocenę, kto zepsuł alarm. Nowe issue nie wymaga pracy teraz, tylko nie gubi wątku. Sesja założy je po Twoim „tak”. | #970, #599 |
| D15 | Lista „V2, ale nie teraz” (`docs/FEATURES.md`): potwierdzasz, że #1996, #1997, #2000, #2024, #2016 zostają zakazane? | **A** tak, agent dostaje STOP (dziś tak jest). **B** odblokować wybrane. Reszta listy: #1902 #1903 #1904 #1906 #1999 #2067. | **A.** Wszystkie są funkcjami bez użytkowników, którzy o nie proszą; V2 wolno budować od D-282, ale to wybór spośród listy, a nie automat. Nazwij, które (jeśli w ogóle), a reszta zostaje. #1997 i #966 #960 #1744 czekają z wcześniejszych pytań. | #1996, #1997, #2000, #2024, #2016 |
| D16 | Akceptujesz zmianę ścieżek `DziennyBudzetListow` w `docs/DECISIONS.md` (`8dff9f624`)? | **A** tak (`app/Domain/Security/…` → `app/Poczta/…`, 6 odwołań, treść decyzji bez zmian, po #2149 etap 3). **B** cofnąć. | **A.** Historyczne decyzje są nienaruszalne co do treści, ale ścieżka pliku musi istnieć (`OdnosnikiDziennikaDecyzjiIstniejaTest`). Zmiana jest czysto adresowa, więc to tylko akceptacja do wiadomości. | #2149 |
| D17 | Ręczna kontrola ujemna eksportu (#1687): wykonasz ją sam, czy przyjmujesz kryteria bez niej? | **A** wykonaj lokalnie: usuń `->visibleTo($user)` w `notifications()` w `app/Domain/Users/Exports/CollectUserExportData.php`, uruchom `APP_BASE_PATH=$(pwd) php artisan test --filter=test_eksport_niesie_dokladnie_te_powiadomienia_co_lista` (musi obleć), przywróć. **B** przyjąć bez. | **A** (5 minut) albo poproś sesję po zmianie reguły klasyfikatora. Kryteria 1–8 spełnione (gałąź `claude/1687-domkniecie`), a ta kontrola dowodzi, że test naprawdę pilnuje granicy paczki RODO. | #1687 |
| D18 | Protokół badania „Ukryj…” (#1818): zatwierdzasz trzy propozycje przed pierwszą sesją? | (1) wariant środowiska **A** (odizolowana instancja ćwiczeń) czy B (prawdziwe konta, poza instrukcją); (2) prowadzący ≠ właściciel; (3) retencja surowych notatek 90 dni. Plus reguły rekomendacji §8 (zmieniać po sesjach nie wolno). | **A / tak / 90 dni** — zapisane w `docs/product/PROTOKOL_BADANIA_1818.md` (tylko na gałęzi `claude/1818-protokol-badania`, `df2a5da11`; wejdzie do paczki G). Bez zgody wariant B wymagałby planu danych i zgody prawdziwych osób. | #1818, #15 |
| D19 | Kasowanie ok. 60 zbędnych gałęzi z listy „ZBĘDNE” ([`sesja-glowna-2909/REJESTR.md`](sesja-glowna-2909/REJESTR.md)). | **A** tak, po zgodzie, hurtem. **B** zostawić. | **A**, z jednym wyjątkiem: `codex/2059-kontrola-ujemna` to sabotaż poprawki — skasować, nie scalać. Klasyfikator blokuje hurtowe kasowanie bez Twojej zgody. | (meta) |
| D20 | Zamknięcia, które czekają na zgodę: #873 i #906 (zamknięcie zablokowane przez klasyfikator; #873 ma kompromis P3 — brak testu współbieżnego). | **A** zamknąć oba. **B** #873 zostawić do testu współbieżnego. | **A.** Oba są na `main` (#873: `06d07d6ce`, #906: `cc4f0e44d`, D-070). Rejestr z 10:20 wskazuje, że zgodę na zamykanie już wydałeś — potwierdź, że obejmuje te dwa. | #873, #906 |

**Pozycji w sekcji 1: 20.**

---

## 2. KROKI w panelach

Kolejność zależności jest w kolumnie „Po”. Pełna, nieduplikowana kolejność produkcyjna (kroki 1–32 do bramki zamkniętej alfy):
[`LISTA_KROKOW_ALFA.md`](../infra/LISTA_KROKOW_ALFA.md) §0. Tu są kroki dodane lub przypomniane przez #1895 i rejestr 29.09.

### 2.1 Railway

| # | Co dokładnie ustawić | Dowód wykonania | Issue | Po |
|---|---|---|---|---|
| K1 | **Odczytaj** (tylko odczyt) usługi produkcyjne: nazwy i ID serwisów połączonych z `woogitsu/kuking.pl`, ID projektu i środowiska, stan „Wait for CI” i autodeploy. Wygeneruj **token projektowy** środowiska `production` (Project Settings → Tokens). | Zapisz w #2025 nazwy i ID (bez tokenu); zrzut listy serwisów bez sekretów. | #2025, #1895 §9 | — |
| K2 | **Wyłącz autodeploy GitHub** dla wszystkich usług aplikacji `production` w wybranym oknie (patrz [`RAILWAY_CI_GATE.md`](../infra/RAILWAY_CI_GATE.md), „Przed przełączeniem”, pkt 4). Zrób to **po** G2 (sekret i zmienne w GitHubie) i **przed** włączeniem flagi. | Panel: autodeploy = off. Kontrolowany push z zielonym CI: log bramki pokazuje ten sam SHA, ID deploymentów, wynik Railway; `/wydanie` pokazuje ten SHA. | #2025 | K1, G2 |
| K3 | Zmień `preDeployCommand` serwisu web z `php artisan migrate --force --no-interaction` na `php artisan kuking:migruj-pod-blokada --no-interaction` (pozostałe kroki bez zmian; sprawdź też, że po migracji jest `php artisan kuking:zarejestruj-wdrozenie --no-interaction`). Dopiero gdy wdrożony obraz zawiera komendy (Alfa 0.77 na `main`). | Odczyt konfiguracji po zmianie (zrzut pola). W bazie: wiersz w tabeli `wdrozenia` dla ostatniego SHA; stopka strony pokazuje „Alfa 0.77”. | #2082 (PR #2102), #1932 | wdrożenie 0.77 |
| K4 | Przenieś do **Shared Variables** środowiska te z czterech, które stoją dziś tylko w serwisie `kuking.pl`: `KUKING_EDGE_TRYB`, `KUKING_HTML_EDGE_CACHE_SECONDS`, `KUKING_TAG_TYGODNIA`, `KUKING_HEALTH_TOKEN`. **Przed** najbliższym `railway config apply`, inaczej apply je usunie (pusta = domyślna z kodu). | Lista Shared Variables z czterema nazwami; wpis w #1895. | #1895 §5, #595 | przed apply |
| K5 | Odczytaj efektywne `drainingSeconds` działających usług production: nazwa usługi, rola (`all`/`web`/`worker`/`scheduler`), wartość w sekundach. Bez zmiany konfiguracji. Kod deklaruje 130 s dla `all` i `worker`, 30 s dla `web` i `scheduler`. | Tabela „usługa, rola, sekundy” wpisana w #2056. | #2056, #1860 (D3) | — |
| K6 | Ustaw `KUKING_EDGE_TOKEN` (Shared Variable, **Sealed**; `openssl rand -hex 32`) i zrób redeploy WWW. Tryb domyślny `obserwacja` nic nie blokuje. Potem, po regule Cloudflare (2.2, C1) i dobie obserwacji: `KUKING_EDGE_TRYB=egzekwowanie`. Zapisz w #1306, że domeny `*.up.railway.app` nie ma (z odczytu). | Doba logów bez ostrzeżeń o braku tokenu; wynik pomiaru topologii (`X-Forwarded-For`, peer, log Caddy) w #1306. Procedura: [`LISTA_KROKOW_ALFA.md`](../infra/LISTA_KROKOW_ALFA.md) C6. | #1306 | B3, C4 |
| K7 | Kanał alarmów: `LOG_BLAD_WEBHOOK_URL` (najpierw środowisko testowe, jeśli je masz; decyzja z 25.09 to „bez stagingu”, więc na produkcji z kontrolowanym `kuking:sprawdz-alarm`, **nie** celowym błędem). Puls harmonogramu: `KUKING_PULS_HARMONOGRAMU_URL` (Sealed, monitor typu heartbeat co 5 min, grace 10 min). | Godzina testu i potwierdzenie, że wiadomość doszła; 10 min regularnych sygnałów w monitorze. Kroki B1–B3: [`MONITORING_599_KROKI.md`](../infra/MONITORING_599_KROKI.md). | #599 | D11, X1 |
| K8 | Limit i alert kosztów: Railway → Workspace settings → Usage/Billing: **limit 100 USD twardo, alert 60 USD** (decyzja z 25.09) i adres, na który przychodzi. | Progi wpisane w #599 (kwota i adres bez haseł). | #599, #595 | D1 |
| K9 | Web Push: `VAPID_PUBLIC_KEY` (zwykła), `VAPID_PRIVATE_KEY` (**Sealed**, tylko worker), opcjonalnie `VAPID_SUBJECT`. Nie zmieniaj pary na żywym środowisku (zapisane subskrypcje przestałyby działać). Instrukcja generowania: [`DEPLOYMENT_RUNBOOK.md`](../infra/DEPLOYMENT_RUNBOOK.md) krok 8G. | Ekran ustawień powiadomień pokazuje przycisk; smoke test — sekcja 3, O6. | #35 | P3 (polityka + #1979) |
| K10 | Import AI: zmienna `OPENAI_IMPORT_KEY` (Sealed). Limit wydatków w panelu OpenAI: 5 USD dziennie i 100 USD miesięcznie (z #1895 §2). Umowa powierzenia danych (DPA) z OpenAI — patrz D8. | Nazwa zmiennej na liście; limit w panelu OpenAI (zrzut bez klucza); data DPA w #2031. | #2031, #28, #1895 §2 | D8 |
| K11 | Stan P0 infrastruktury (jeden odczyt przed apply): kolejność 1–32 z [`LISTA_KROKOW_ALFA.md`](../infra/LISTA_KROKOW_ALFA.md) §0 — zrzut i odtworzenie bazy ([`DR594_PIERWSZY_ZRZUT_WLASCICIEL.md`](../infra/DR594_PIERWSZY_ZRZUT_WLASCICIEL.md)), `railway config apply` w trybie plan → apply, budżet połączeń, `kuking:sprawdz-kolejke`. | Wg dokumentu: data i wynik w #594/#193, #595, #598, #600. | #594, #193, #595, #598, #600 | K4, K8 |

### 2.2 Cloudflare

| # | Co dokładnie ustawić | Dowód wykonania | Issue | Po |
|---|---|---|---|---|
| C1 | **Transform Rule → Modify Request Header:** ustaw `X-Kuking-Edge-Token` na **wszystkich** żądaniach do `kuking.pl` (inaczej monitor `/health` dostanie 403 po włączeniu egzekwowania). Wartość = ta z K6. | Zrzut reguły (bez wartości); doba logów bez ostrzeżeń. | #1306 | K6 |
| C2 | **Email Routing** dla `kontakt@kuking.pl` (Droga A, D12): włącz, dodaj adres docelowy (skrzynka czytana codziennie), potwierdź linkiem; MX „DNS only”; **scal** SPF w jeden rekord (`include:_spf.emaillabs.net.pl` + include Cloudflare). Rekordów A/AAAA apexa nie ruszaj. | `dig kuking.pl MX +short` zwraca host; `dig _dmarc.kuking.pl TXT +short` = `v=DMARC1; p=none; rua=mailto:kontakt@kuking.pl`; list z Gmaila dotarł; po 1–3 dniach przyszedł raport `.xml.gz`. Data i metoda w wierszu 3 [`OTWARCIE.md`](../OTWARCIE.md). | #2049 | D12 |
| C3 | Cache zdjęć (#597) i HTML gościa (#610): reguły wg [`CLOUDFLARE_CACHE_597_610.md`](../infra/CLOUDFLARE_CACHE_597_610.md) i [`cloudflare-cache-rules-597-610.json`](../infra/cloudflare-cache-rules-597-610.json); okno nieświeżości 120 s wymaga świadomej akceptacji. Nie blokują alfy. | Odczyt reguł; sonda `CACHE_KIND=html`. | #597, #610 | C1 (przegląd strefy), C4 z listy |
| C4 | Przegląd strefy (odczyt, 20 min): domeny, DNS, reguły, tokeny — odblokowuje C3, R2 i K6. | Notatka poza repo. | #1306, #597 | — |

### 2.3 R2 (Cloudflare → R2)

| # | Co dokładnie ustawić | Dowód wykonania | Issue | Po |
|---|---|---|---|---|
| R1 | **Bucket Lock — najpierw odczyt** na buckecie oryginałów (`R2_BUCKET` z Shared Variables). Jeśli rygiel obejmuje pusty prefiks albo `livewire-tmp/` — **stop**, opisz w #2051 (czas życia = okres rygla, decyzja właściciela). Rekomendacja projektu: brak rygla na buckecie oryginałów. | Wiersz „Bucket Lock” w tabeli §6 [`LIVEWIRE_TMP_R2_RETENCJA_2051.md`](../infra/LIVEWIRE_TMP_R2_RETENCJA_2051.md). | #2051 | — |
| R2 | **Reguła lifecycle** `livewire-tmp-1-dzien`: prefiks dokładnie `livewire-tmp/` (z ukośnikiem, **niepusty**), wygaśnięcie po 1 dniu; przerywanie niedokończonych multipartów po 1 dniu (domyślnej 7-dniowej **nie usuwaj**). Po jednym bucketcie produkcyjnym (staging odłożony decyzją z 25.09). Jeżeli staging i produkcja mają ten sam `R2_BUCKET`, jedna reguła obejmuje oba. | Zrzut listy reguł albo `aws s3api get-bucket-lifecycle-configuration` (endpoint zamień na `<endpoint>`). Po 2 dobach: liczba i data najstarszego obiektu pod `livewire-tmp/` — bez nazw kluczy. §5.1–5.2 dokumentu. | #2051, #602 | R1 |
| R3 | **Obiekt kontrolny na buckecie NIEprodukcyjnym** (nowy, pusty, tylko do próby): `livewire-tmp/kontrola-2051.txt` i `incoming/kontrola-2051.txt`; sprawdzaj `HEAD` po 24 h co kilka godzin do pierwszego `404`. `incoming/` ma istnieć. | Data i godzina zapisu → pierwszy `404` (oczekiwane ok. 48 h). Dopiero to pozwala napisać „surowe uploady wygasają”. | #2051 | R2 |
| R4 | Druga reguła (opcjonalna): `import-pdf-tmp-1-dzien`, prefiks `import-pdf-tmp/`, 1 dzień — druga linia obrony dla plików PDF importu (#28). Kod sprząta sam po 4 h. Te same ostrzeżenia (pusty prefiks, Bucket Lock). | Jak w R2. | #28, #2051 | R2 |
| R5 | Jurysdykcja bucketu **paczek RODO** i **kopii bazy**: odczyt i zapis w [`LOKALIZACJA_DANYCH_R2.md`](../infra/LOKALIZACJA_DANYCH_R2.md) §0 (nazwa bucketu produkcyjnego zdjęć do uzupełnienia; bucket zdjęć EU potwierdziłeś 25.09); reguły blokad, uprawnienia tokenów. Decyzja o #617: osobny bucket kopii z blokadą 30 dni albo świadome przyjęcie ryzyka. | Wpis z datą w #619 i #617; zaktualizowana tabela w dokumencie. | #619, #617, #120 | C4 |

### 2.4 GitHub Settings

| # | Co dokładnie ustawić | Dowód wykonania | Issue | Po |
|---|---|---|---|---|
| G1 | **Fine-grained PAT** (Settings → Developer settings → Fine-grained tokens): resource owner `woogitsu`, repo tylko `kuking.pl`, uprawnienia **Contents: Read and write** i **Pull requests: Read and write**, wygaśnięcie konkretną datą (np. rok, nie „No expiration”; przypomnienie w kalendarzu). Zapisz jako repo secret **`CENY_WARZYW_PAT`**. Potem uruchom raz `php artisan kuking:ceny-skladnikow` na produkcji, dopiero gdy #1870 wejdzie do `main`. | Actions → „Ceny warzyw (ZSRIR)” → Run workflow: krok „Sprawdź sekret CENY_WARZYW_PAT” zielony. Dopiero wtedy sesja scali #1870. | #1895 §1, #1870 | — |
| G2 | Repo → Settings → Secrets and variables → Actions: sekret **`RAILWAY_TOKEN_PRODUCTION`** (token projektowy z K1) oraz zmienne repozytorium `RAILWAY_PRODUCTION_PROJECT_ID`, `RAILWAY_PRODUCTION_ENVIRONMENT_ID`, `RAILWAY_PRODUCTION_SERVICES_JSON` (np. `[{"name":"kuking.pl","id":"<ID serwisu>"}]`; ID z bieżącego panelu, nie z dokumentacji). **Nie ustawiaj jeszcze** `KUKING_CI_GATED_RAILWAY_DEPLOY`. | Lista sekretów i zmiennych z czterema nazwami; `python3 -m unittest discover -s scripts -p test_railway_ci_gated_deploy.py -v` zielone. | #2025, #1925 | K1 |
| G3 | Settings → Environments → **production** → Required reviewers: dodaj siebie (i ewentualnie drugą osobę), „Prevent self-review” przy dwóch osobach. Job wdrożeniowy z tokenem produkcji poczeka wtedy na zatwierdzenie. | Zrzut ekranu ustawień środowiska. | #1925, #1895 §6 | — |
| G4 | Po K2: zmienna repozytorium **`KUKING_CI_GATED_RAILWAY_DEPLOY=true`**. Kontrolowany push z zielonym CI; potem test „czerwone lub anulowane CI nie wdraża” (np. anulowany przebieg na gałęzi próbnej). Wycofanie: `false`, sprawdź, czy nie trwa job bramki, dopiero potem włącz autodeploy w Railway. | Log bramki (SHA, ID deploymentów); wynik próby blokady wpisany w #2025. | #2025 | K2, G2 |

### 2.5 EmailLabs

| # | Co dokładnie ustawić | Dowód wykonania | Issue | Po |
|---|---|---|---|---|
| E1 | Panel EmailLabs → ustawienia serwera SMTP konta nadawczego (`1.mkapica.smtp`) → wyłącz **Open Tracking**. API tego nie robi; śledzenie kliknięć jest wyłączone w kodzie. Bez przełącznika: napisz do wsparcia Vercom i wklej odpowiedź z datą. | Wyślij list (`railway ssh -- php artisan kuking:sprawdz-poczte ty@wp.pl`), zapisz surowe źródło jako `.eml`, uruchom `php artisan kuking:sprawdz-piksel <plik.eml>` (musi być zielone, a plik musi zawierać adresy http(s)). Datowany wynik wpisz w [`POCZTA_URUCHOMIENIE.md`](../infra/POCZTA_URUCHOMIENIE.md) §5, krok 6. Jeśli nie da się wyłączyć: decyzja — zmiana dostawcy albo akapit o pikselu zostaje w polityce. | #204 | — |
| E2 | Po C2: wyślij kilka listów z produkcji na Gmail i Outlook (`kuking:sprawdz-poczte`), żeby odbiorcy zaczęli słać raporty DMARC. | Po 1–3 dniach list od odbiorcy z załącznikiem `.xml.gz`/`.zip`; nie kasuj i nie filtruj do kosza. | #2049 | C2 |

### 2.6 Inne konta zewnętrzne

| # | Co dokładnie zrobić | Dowód wykonania | Issue | Po |
|---|---|---|---|---|
| X1 | Zewnętrzny monitor dostępności (UptimeRobot lub odpowiednik, bez karty): monitory `https://kuking.pl/` i `https://kuking.pl/health`, alarm po 2 kolejnych niepowodzeniach, interwał 1–5 min, **na domenie**, nie na adresie Railway; jeden adres alarmowy z D11. Test niedostępności: bez stagingu — tylko odczyt konfiguracji i test alarmu z panelu dostawcy. | Zrzut listy monitorów; godzina próbnego alarmu. [`MONITORING_BLEDOW.md`](../infra/MONITORING_BLEDOW.md) §6. | #599 | D11 |
| X2 | OpenAI: limit wydatków (5 USD/dzień, 100 USD/mies.) i DPA — patrz K10 i D8. | Data DPA, zrzut limitu. | #2031 | D8 |

**Pozycji w sekcji 2: 28** (Railway 11, Cloudflare 4, R2 5, GitHub 4, EmailLabs 2, inne konta 2; K11 to jedna pozycja odsyłająca do 32 kroków z listy alfy).

---

## 3. ODBIORY z ludźmi i urządzeniami

Nie do zrobienia w repo: potrzebny prawdziwy telefon, czytnik ekranu albo osoba. Każdy odbiór kończy się wpisem w issue z datą i urządzeniem.

| # | Odbiór | Co dokładnie sprawdzić | Dowód | Issue |
|---|---|---|---|---|
| O1 | **iPhone (Safari i po dodaniu do ekranu głównego)** — bezpieczny obszar, D-260 (`viewport-fit=cover`, tokeny `--safe-*`; kod gotowy, `BezpiecznyObszarMaJedenKontraktTest`). | Model z wycięciem lub Dynamic Island; pion i poziom (wycięcie po lewej i prawej); górna belka, dolna nawigacja, panele przy krawędzi, długi formularz; tekst 100/140/200%; cele dotykowe ≥ 48 × 48 px; obrót bez przeładowania i utraty fokusu formularza. | Zdjęcia ekranu + wpis w #987 „obowiązuje” → sesja zmienia D-260 na obowiązującą i zamyka #987. | #987, #713 (C2) |
| O2 | **NVDA/Firefox i VoiceOver/Safari** — komunikaty po zmianie (#988 zamknięte 29.09, kryterium odbioru z issue pozostaje). | Po pełnym POST → redirect → GET: czy czytnik ogłasza sukces, ostrzeżenie i błąd (odmowa Google/Facebooka, link logowania, konflikt awatara, nieaktywny tag); osobno aktualizacje bez nawigacji; 200% i 320 px bez nachodzenia etykiety, treści i akcji. Dodatkowo #713 C1: odsłuch minutnika (`role="timer"`) i decyzja o nazwie „Pozostały czas”. | Nagranie lub notatka: co czytnik powiedział; wpis w #988 i #713. | #988, #713 |
| O3 | **Telefon: podpowiedzi tagów po `#`** (#647, kod na `main`). | Android i iOS, polska klawiatura ekranowa: podpowiedzi przy kursorze i nad klawiaturą; wybór dotykiem; Enter przy wyborze nie publikuje wpisu; IME/autokorekta nie gubi znaków; zoom 200% i skala 140% — przyciski ≥ 48 px. Na produkcji, gdy będą dane: licznik przy tagu > 0 i tylko wpisy publiczne. | Krótki opis wyniku w #1745. | #1745, #647 |
| O4 | **#713 — 11 kroków** z [`WERYFIKACJA_713_2026_09_29.md`](../audits/WERYFIKACJA_713_2026_09_29.md) („Kroki właściciela”): `failed_jobs` (odczyt), R2/Cloudflare (jurysdykcja, lifecycle), Open Tracking, ruch produkcyjny (przepis z 2/5/22 wykonaniami, tag ≥ 3 autorów), rampa #605, NVDA/VoiceOver, fizyczny telefon i drukarka (paczka danych), logowanie hasłem z prawdziwym Turnstile, `php -i | grep variables_order` na runnerze, obejrzenie na produkcji wstępu „Spis tagów” (22 px), paska górnego i bloku „UWAGA”, prawdziwy stop kontenera podczas długiego zadania. | Wg dokumentu: wynik przy każdej pozycji. | Wpis zbiorczy w #713. | #713 |
| O5 | **#1818 / #15 — badanie z ludźmi 50+.** Najpierw D18 (zatwierdzenie protokołu). Potem: 4 osoby pilotażowe + 16 właściwych (wiek 50–59 / 60–69 / 70+ — propozycja 6/6/4), własne telefony (min. 5 osób na system), min. 5 osób z czcionką 200%, prowadzący nie jest właścicielem ani autorem ekranów, instancja ćwiczeń zamiast produkcji (wariant A). Bramka #15: 13 sesji w przekroju ([`TESTY_Z_UZYTKOWNIKAMI.md`](../product/TESTY_Z_UZYTKOWNIKAMI.md), [`KARTA_BADANIA_15.md`](../product/KARTA_BADANIA_15.md)). Progi #1818: A ≥ 13/16, B ≥ 14/16, C ≥ 14/16, D = 0 osób z K1/K2 — zapisane przed testem, nie zmieniane po. | Karta rundy, karty sesji, zestawienie (formularze A–D w `PROTOKOL_BADANIA_1818.md`); rekomendacja i zapis w `DECISIONS.md`. | #1818, #15, #1781 |
| O6 | **Web Push (#35) — smoke test po K9 i P3.** | Zgoda, wysyłka, wyłączenie na jednym urządzeniu i na wszystkich; test na współdzielonym urządzeniu po zmianie konta (#1979); cisza nocna; treść szyfrowana. Zależność: polityka prywatności z operatorami transportu (Google FCM, Mozilla, Apple, Microsoft). | Zrzuty z trzech urządzeń; wpis w #35. | #35, #1979 |
| O7 | **Panel moderacji (#581) na produkcji.** Zalogowany jako moderator: kolejka zgłoszeń, karta sprawy z decyzją, odwołania, bramka 2FA — na telefonie i komputerze, w obu motywach. Uwagi wpisz w #581; gałąź `claude/581-panel-moderacji-etap` dodaje „Zdejmij z urzędu” w mierniku panelu (paczka G). | Uwagi w #581. | #581, #1895 §7 |
| O8 | **„Poradźcie” (#372) — odbiór i zniesienie flagi.** Na produkcji `KUKING_QUESTIONS_ENABLED` jest `true` (pomiar 25.09: `/pytania` → 200); po stabilizacji flaga ma zostać usunięta (kolejność z #372). Odbiór roboczy: [`ODBIOR_PORADZCIE_372.md`](../product/ODBIOR_PORADZCIE_372.md). | Wpis w #372 z datą; decyzja o zdjęciu flagi. | #372 |
| O9 | **Numer wersji po wdrożeniu Alfa 0.77.** | Stopka strony pokazuje „Alfa 0.77”, a `/wydanie` zwraca SHA `main` (wcześniejszy przypadek z 28.09: strona pokazywała „Alfa 0.68” bez końcówki, #1932). | Zrzut stopki + wynik `/wydanie`. | #1932 |

**Pozycji w sekcji 3: 9.**

---

## 4. ODCZYTY na produkcji

Dostęp: `railway ssh -- php artisan …` albo psql z `DATABASE_URL` (tylko odczyt). Wyniki to same liczby — do issue nie wklejaj treści, adresów, identyfikatorów kont ani nazw kluczy.

| # | Odczyt | Polecenie | Co zapisać | Uwagi | Issue |
|---|---|---|---|---|---|
| Q1 | Pętla „Zapisuję → Ugotowałem” (licznik, mianownik, procent) | `php artisan kuking:raport` | sekcja pętli z datą i liczbą kont w mianowniku | dopiero po realnym ruchu (#29); bez ruchu liczby nic nie znaczą | #1015 |
| Q2 | „Historie przepisów”: udział zachowanych pochodzeń (same liczniki) | `php artisan kuking:raport` (sekcja historii) | licznik, mianownik, procent | ten sam przebieg co Q1 | #1045 |
| Q3 | Planer → ugotowanie (`PlanDoUgotowania`, okno +3 dni, próg 20) | `php artisan kuking:raport` | liczniki i mianowniki, także „brak poprawy” | **dopiero po scaleniu** gałęzi `claude/27-pomiar-planera` (`f58918e6c`, paczka G); na `claude/paczka-f` tej sekcji jeszcze nie ma; bramka do D5 | #27 |
| Q4 | Ile publicznych pytań jest do odnalezienia w „Poradźcie” | `psql "$DATABASE_URL" -X -f scripts/pomiar-870-liczba-pytan.sql` | liczba publicznych pytań; porównanie z rozmiarem strony listy | jeśli mieści się na 1–2 stronach, szukanie po tytule zostaje odłożone; tabela dla 2 000 i 20 000: `docs/pomiary/870-pytania-wyszukiwanie.md` | #870 |
| Q5 | Powroty do sprawy już załatwionej (wiadomości kontaktowe) | `psql "$DATABASE_URL" -X -f scripts/pomiar-841-powroty.sql` | same agregaty | dolne oszacowanie, widać najwyżej rok wstecz; nie wydłużaj retencji na potrzeby pomiaru | #841 |
| Q6 | Koszt licznika „Czeka na odpowiedź (N)” | odczyt logów wolnych zapytań + `EXPLAIN (ANALYZE, BUFFERS)` osobnego licznika dla gościa, konta i tagu (wg #372, kontrola kosztu 22.09) | p95 strony i licznika na docelowym wolumenie | przed decyzją o zdjęciu flagi (O8) | #372 |
| Q7 | Nieudane zadania (`failed_jobs`) — liczba, klasy, daty | `railway ssh -- php artisan kuking:martwe-zadania` (podgląd) i odczyt tabeli | liczba, klasy, najstarszy wpis | zadania z 9.09 znikną same ok. 10.10 (`queue:prune-failed --hours=720`, 05:20); po tej dacie `/health` przestaje raportować `kolejka: zadania_nieudane` | #713 (A1) |
| Q8 | Kontekst kontaktu — sprzątanie `page_path` | `php artisan kuking:oczysc-kontekst-kontaktu` **najpierw bez** `--wykonaj` (podgląd), potem z `--wykonaj` po Twojej zgodzie | liczba wierszy do wyczyszczenia i po wyczyszczeniu | operacja zapisująca na produkcji — tylko z Twoją zgodą; kod jest na `main` (PR #1524) | #836 |
| Q9 | Szczyt połączeń PostgreSQL i kolejki | Railway → Logs, filtr `kuking:budzet-polaczen` i `kuking:sprawdz-kolejke`; `php scripts/szczyt-polaczen-z-dziennika.php` | szereg w normalnym ruchu i podczas wdrożenia; `kolejki.media.zaleglosc_sekundy` | dopisek z D-311: szczyt pamięci serwisu w oknie 7 dni (przed zmianą 0,53 GB z 1,0 GB) | #598, #599 |
| Q10 | Stan konfiguracji cache i pomiar artefaktu wydajności | odczyt reguł Cloudflare, `CACHE_KIND=html`; pomiar artefaktu po scaleniu #2210 | hit ratio, `origin requests` | pomiar #1000 (fonty 133→53 kB) po #2210 | #1000, #597 |

**Pozycji w sekcji 4: 10.**

---

## Powiązania i porządek

- Kolejność „co po czym”: K1 → G2 → K2 → G4 (bramka wdrożeń); K6 → C1 → doba → `KUKING_EDGE_TRYB` (proxy); R1 → R2 → R3 (retencja); C2 → E2 (raporty DMARC); D8 → K10 (AI); P3 → K9 → O6 (Web Push).
- Rejestr: [`sesja-glowna-2909/REJESTR.md`](sesja-glowna-2909/REJESTR.md); wpisy zamknięć z dowodami: [`sesja-glowna-2909/ZAMKNIECIA.md`](sesja-glowna-2909/ZAMKNIECIA.md).
- Listę zakazaną („V2, ale nie teraz”) i „Nie wcześnie” utrzymuje `docs/FEATURES.md`; ten dokument jej nie zmienia.
- Zasady ogólne: [`AGENTS.md`](../../AGENTS.md). Ten dokument nie tworzy decyzji `D-xxx`; właściciel akceptuje, sesja zapisuje.

---

## Dopisek z wieczora 29.09 (po paczce H, przed paczką I)

Decyzje z tego wieczora są w tabeli D-333 w `docs/DECISIONS.md`. Kroki po stronie właściciela:

| # | Co | Kiedy | Zgłoszenie |
|---|---|---|---|
| W1 | Po wdrożeniu paczki H ustaw w Railway `KUKING_QUESTIONS_ENABLED=true` i sprawdź `/pytania` („Poradźcie”) | po wdrożeniu H | #372 |
| W2 | Import do OpenAI: **nie** ustawiaj `KUKING_IMPORT_URL/_PDF/_ZDJECIE`. Po wdrożeniu paczki I import zniknie i wróci po podpisaniu DPA z OpenAI, gdy ustawisz te trzy zmienne na `true` | po DPA | #2214 |
| W3 | GitHub → Settings → Branches → reguła dla `main`: oznacz wymagane checki według pełnej listy z `docs/infra/BRAMKI_CI_2215.md`. Nazwy przepisz z pierwszego przebiegu CI po scaleniu paczki I. Części macierzy „Port marki — … (część N/2)” **nie** oznaczaj | po scaleniu I | #2215 |
| W4 | Bramka wdrożeń Railway (`KUKING_CI_GATED_RAILWAY_DEPLOY`) według `docs/infra/RAILWAY_CI_GATE.md`; #2025 zamykamy dopiero po jej włączeniu | kiedy wygodnie | #2025 |
| W5 | Proxy: sekret `KUKING_EDGE_TOKEN` w Railway, reguła nagłówka `X-Kuking-Edge-Token` w Cloudflare (także dla monitora `/health`), doba obserwacji, potem `KUKING_EDGE_TRYB=egzekwowanie` | kiedy wygodnie | #1306 |
| W6 | R2: reguła lifecycle `livewire-tmp/` po 1 dniu (bucket oryginałów, produkcja i staging), potem `railway ssh -- php artisan kuking:sprawdz-retencje-livewire` (oczekiwany kod 0) | po wdrożeniu I | #2051 |
| W7 | Po wdrożeniu I `php artisan tinker` na produkcji przestaje działać. Zamienniki: `kuking:liczniki-bazy`, `kuking:przetworz-zdjecia-ponownie`, `kuking:sprawdz-alarm --przez-wyjatek`, `kuking:bramka-r2 --media=` | po wdrożeniu I | #2223 |
| W8 | Regulamin: potwierdź termin odpowiedzi na reklamację **14 dni**, kanał (e-mail `biuro@…` i list) i to, że zmiana jest drobna (wiersz D-333 „do potwierdzenia”) | przed wdrożeniem I | #2220 |
| W9 | Odbiór na iPhonie (Safari i PWA): bezpieczny obszar, pion i poziom | kiedy wygodnie | #987 |
| W10 | Pomiary po pojawieniu się ruchu: `railway ssh -- php artisan kuking:raport`, sekcje „Zapis → Ugotowałem” i „Historie przepisów”; instrukcja w `docs/pomiary/` | po #29 | #1015, #1045 |
| W11 | Gałęzie do skasowania (sesja nie ma prawa kasowania): `codex/2130-kontrola-ujemna`, `codex/2059-kontrola-ujemna`, `codex/2014-date-modified-jsonld`, `codex/2066-urgent-alert-negative`, `codex/hide-expiry-local-date`, `claude/larastan-test-zamiaru-ugotowania` | kiedy wygodnie | — |
