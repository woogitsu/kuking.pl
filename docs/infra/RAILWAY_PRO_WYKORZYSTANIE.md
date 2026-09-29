# Railway Pro — co dostajemy za plan i jak to wykorzystać

29 września 2026. Materiał do wykonania przez właściciela; **nic z tego nie
zostało zrobione** — ani w panelu, ani w `.railway/railway.ts`. Ten dokument
nie zmienia konfiguracji, nie zawiera sekretów i nie łączył się z Railway
(dokumentacja czytana publicznie, repozytorium i zgłoszenia tylko do odczytu).

Powód: **decyzja właściciela z 29 IX 2026** — przechodzimy na płatny plan
Railway, „Hobby albo Pro, raczej Pro, żeby był też backup i inne funkcje;
sprawdzić, co Pro ma, żeby się nie marnowało”.

## 1. Wniosek

**Rekomendacja: plan Pro (20 USD/mies.).**

1. **Koszt netto jest bliski zeru względem Hobby.** Oba plany mają opłatę
   zaliczaną na poczet zużycia (Hobby 5 USD = 5 USD zużycia, Pro 20 USD = 20 USD
   zużycia; to samo zapisano wcześniej w `INFRA_DECISION.md`, sekcja kosztów). Plan
   trzech serwisów (#595) kosztuje według repozytorium **40–65 USD/mies.**
   zużycia — znacznie powyżej obu progów. Przy zużyciu ≥ 20 USD **Pro i Hobby
   kosztują tyle samo**; różnica (do 15 USD) pojawia się tylko w miesiącach
   o niskim zużyciu, czyli w praktyce na samym początku alfy, zanim zostanie
   wykonane #595. Dane: odczyt planów 29 IX (§2), szacunek z #595.
2. **Pro odblokowuje to, czego Hobby nie ma**: Monitory zasobów (alerty
   CPU/RAM/dysk/egress — #599), 30 dni logów zamiast 7, 30 dni dziennika
   audytu zamiast 48 godzin, SMTP, 10 równoległych buildów, 20 domen własnych,
   nielimitowanych członków workspace (§2).
3. **Backupy Railway (Volume Backups, PITR) uzupełniają, nie zastępują
   naszego zrzutu offsite** (§5). Kopia poza Railwayem (`kopia-bazy`, #193/#594)
   jest jedyną, która przetrwa usunięcie projektu, wyczyszczenie wolumenu albo
   zablokowanie konta.
4. **Nie kupujemy Pro „na zapas” pod HA ani Redis.** HA Postgresa (#604) i druga
   replika web (#600) da się technicznie zrobić także na Hobby (repliki do 6),
   a decyzje o nich mają wynikać z pomiaru (#599), nie z planu.

**Ostrzeżenie o cenie, do sprawdzenia w panelu przed potwierdzeniem zakupu:**
strona cennika, którą czytałem, mówi „unlimited team members” dla Pro, ale
strona o workspace (`reference/teams`) **wprost przyznaje, że nie opisuje cen
za miejsce**. Historycznie Railway naliczał Pro za każdego członka. Przy
jednym właścicielu to bez różnicy; przy dodaniu drugiej osoby trzeba
sprawdzić cenę w ekranie planu. Nie dodawać członków przed tym sprawdzeniem.

## 2. Hobby a Pro — zestawienie (odczyt 29 IX 2026)

Wszystkie wartości z publicznej dokumentacji Railway, czytane 29 IX 2026.
Rozbieżności między stronami Railway zaznaczam wprost, zamiast wybierać
wygodniejszą liczbę.

| Pozycja | Hobby | Pro | Źródło (odczyt 29 IX 2026) |
|---|---|---|---|
| Opłata bazowa | 5 USD/mies. | 20 USD/mies. | [reference/pricing/plans](https://docs.railway.com/reference/pricing/plans) |
| Zużycie w cenie | 5 USD/mies. | 20 USD/mies. | jw. |
| Stawki zużycia (oba plany) | RAM 0,00000386 USD/GB-s (około 10 USD/GB/mies.), CPU 0,00000772 USD/vCPU-s (około 20 USD/vCPU/mies.), wolumen około 0,15 USD/GB/mies., egress 0,05 USD/GB, bucket 0,015 USD/GB/mies. (egress z bucketu 0) | to samo | jw. |
| RAM i CPU na serwis | 48 GB, 48 vCPU | 1 TB, 1000 vCPU wg cennika; strona o skalowaniu podaje przykład 24 vCPU/24 GB na replikę Pro — **rozbieżność, dla nas bez znaczenia** (nasze serwisy mają 0,5–2 GB) | jw.; [reference/scaling](https://docs.railway.com/reference/scaling) |
| Repliki na serwis | do 6 | do 42 | [reference/pricing/plans](https://docs.railway.com/reference/pricing/plans) |
| Repliki a wolumen | „Replicas cannot be used with volumes” — dotyczy obu planów | jw. | [reference/volumes](https://docs.railway.com/reference/volumes) |
| Rozmiar wolumenu | do 5 GB | strona o wolumenach: 50 GB; cennik: 1000 GB — **rozbieżność**; samoobsługowe powiększanie wolumenu jest cechą Pro | [reference/volumes](https://docs.railway.com/reference/volumes), [reference/pricing/plans](https://docs.railway.com/reference/pricing/plans) |
| Live resize wolumenu | tak (oba płatne plany) | tak | [reference/volumes](https://docs.railway.com/reference/volumes) |
| Projekty / usługi w workspace | 50 / 50 | 100 / 100 | [reference/pricing/plans](https://docs.railway.com/reference/pricing/plans) |
| Domeny własne | 2 | 20 | jw. |
| Równoległe buildy | 3 | 10 | jw. |
| Członkowie workspace | 3 | bez limitu (cena za miejsce nieopisana — patrz §1) | jw.; [reference/teams](https://docs.railway.com/reference/teams) |
| Role w workspace | Admin, Member, Deployer — nowe workspace’y wymagają Pro lub Enterprise | jw. | [reference/teams](https://docs.railway.com/reference/teams) |
| RBAC na poziomie środowiska | brak | brak — „available on Railway Enterprise” | [reference/environments](https://docs.railway.com/reference/environments) |
| Logi (retencja) | 7 dni | 30 dni | [observability/logs](https://docs.railway.com/observability/logs) |
| Limit logów | 500 linii/s na replikę, oba plany | jw. | jw. |
| Dziennik audytu (Audit Logs) | 48 godzin | 30 dni (Enterprise 18 miesięcy) | [reference/audit-logs](https://docs.railway.com/reference/audit-logs) |
| Monitory zasobów (alerty CPU/RAM/dysk/egress) | brak | **tylko Pro** („Monitors require the Pro plan”) | [guides/alerts-crashes-failed-deploys](https://docs.railway.com/guides/alerts-crashes-failed-deploys) |
| Webhooki projektu (deploy failed/crashed, alert wolumenu) | tak, dokumentacja nie stawia bramki planu | tak | [observability/webhooks](https://docs.railway.com/observability/webhooks) |
| Limit wydatków (twardy) i alert e-mail | tak, dostępne na wszystkich planach; minimum twardego limitu 10 USD | tak | [reference/usage-limits](https://docs.railway.com/reference/usage-limits) |
| SMTP wychodzący | **zablokowany** (tylko API HTTPS) | dostępny; po zmianie planu trzeba przeładować serwis | [networking/outbound-networking](https://docs.railway.com/networking/outbound-networking) |
| Wsparcie | społeczność (Central Station), bez gwarancji | bezpośrednia pomoc Railway przez Central Station, „zwykle w 72 godziny”, **bez SLA** | [reference/support](https://docs.railway.com/reference/support) |
| Business Class (SLO, P1 w 1 godzinę 24/7) | nie | dodatek dla Pro, ale kwalifikacja od **5000 USD/mies.** wydatków — poza naszym zasięgiem | jw. |
| SLA umowne | brak | brak (tylko Enterprise) | jw. |
| Volume Backups | dokumentacja nie wskazuje planu; **panel 17 IX pokazał komunikat „only available for customers on the Pro plan”** (`KOPIE_I_ODTWORZENIE.md` §5.3) | dostępne | [reference/backups](https://docs.railway.com/reference/backups) |
| PITR Postgresa | dokumentacja nie wskazuje planu | dostępne | [volumes/point-in-time-recovery](https://docs.railway.com/volumes/point-in-time-recovery) |
| PostgreSQL HA (Patroni, etcd, HAProxy) | dokumentacja nie wskazuje planu | dostępne | [databases/postgresql-ha](https://docs.railway.com/databases/postgresql-ha) |
| PgBouncer (pula połączeń) | dokumentacja nie wskazuje planu | dostępne | [databases/postgresql-pgbouncer](https://docs.railway.com/databases/postgresql-pgbouncer) |
| Regiony | ten sam zestaw (US West, US East, EU West Amsterdam, Azja Singapur); repliki wieloregionowe opisane na stronie skalowania bez wskazania planu | jw. | [reference/regions](https://docs.railway.com/reference/regions), [reference/scaling](https://docs.railway.com/reference/scaling) |
| Sieć prywatna | bez opłaty; dokumentacja nie stawia bramki planu | jw. | [reference/private-networking](https://docs.railway.com/reference/private-networking), [FAQ cen](https://docs.railway.com/reference/pricing/faqs) |
| Środowiska i PR Environments | dokumentacja nie podaje różnic planów | jw. | [reference/environments](https://docs.railway.com/reference/environments) |
| Buildy | „Service builds are free” | jw. | [reference/pricing/plans](https://docs.railway.com/reference/pricing/plans) |

**Jak czytać wiersze „dokumentacja nie wskazuje planu”:** to nie znaczy „jest na
Hobby”. Znaczy: żadna przeczytana strona nie stawia bramki. Sprzeczny z nimi jest
tylko jeden dowód — komunikat z panelu z 17 IX (Volume Backups). Rozstrzygnięcie
jest w §5, krok 1: **po wejściu na Pro otworzyć zakładkę Backups i zapisać
z datą, co widać.**

## 3. Stan repozytorium, z którym porównuję (BAZA: `claude/paczka-g-kandydat`)

| Element | Stan w repo | Gdzie |
|---|---|---|
| Serwisy | `web` (`kuking.pl`), `worker`, `scheduler` + cron `kopia-bazy` (02:17 UTC); Postgres jako serwis z wolumenem | `.railway/railway.ts` (`PRODUCTION_SPLIT_SERVICES = true`, region Amsterdam) |
| Repliki | wszędzie `numReplicas: 1`; scheduler „NIE ZMIENIAĆ” | `.railway/railway.ts` |
| Zasoby | web 1 GB / 2 vCPU (staging 768 MB / 1), worker 1 GB / 2 vCPU, scheduler 512 MB / 1, kopia-bazy 512 MB / 1 | `.railway/railway.ts` `limitOverride` |
| Wolumeny | żadnego na aplikacji (zdjęcia w R2); Postgres ma wolumen 500 MB | `KOPIE_I_ODTWORZENIE.md`, `DR594_PIERWSZY_ZRZUT_WLASCICIEL.md` |
| Stan produkcji wg odczytu 17–25 IX | `railway config apply` **nie był uruchomiony**; produkcja to jeden serwis `kuking.pl` (`all`) + Postgres; serwis `kopia-bazy` nie istnieje | `DR594_PIERWSZY_ZRZUT_WLASCICIEL.md`, `LISTA_KROKOW_ALFA.md` A1 |
| Limit wydatków | decyzja 25 IX: twardo 100 USD, alert 60 USD; do wpisania w panelu (A3) | `LISTA_KROKOW_ALFA.md` A3, `DEPLOYMENT_RUNBOOK.md` §12 |
| Poczta | EmailLabs przez API HTTPS, bo Hobby blokuje SMTP (D-116) | `DEPLOYMENT_RUNBOOK.md` KROK 3 |
| Backupy Railway | „nie mogą być włączone, bo Pro” (D-043, 9 IX), **sprostowane 18 IX**: dokumentacja bramki nie potwierdza, panel 17 IX potwierdził | `KOPIE_I_ODTWORZENIE.md` §5.3 |
| Bramka wdrożenia po CI | przygotowana, wyłączona (`KUKING_CI_GATED_RAILWAY_DEPLOY`) | `RAILWAY_CI_GATE.md` |
| Staging i PR Environments | **odłożone** decyzją 25 IX | `LISTA_KROKOW_ALFA.md` A7/A8 |
| Redis | zakazany bez pomiaru (`AGENTS.md`, sekcja „Zakaz overengineeringu”); kolejka, cache i sesje w Postgresie | `REDIS_HA_DECYZJE_603_604.md` |

Zgłoszenia (odczyt 29 IX): #594 (P0, otwarte — brak zweryfikowanego DR), #193 (P1,
otwarte — offsite `pg_dump`, korekta z 16 IX: nie zakładamy, że backupy Railway są
tylko na Pro), #599 (P0, otwarte — monitoring, uptime, budżet), #600 (P1 — druga
replika web, PgBouncer wg budżetu, osobny worker `media`; punkt 4: „nie traktować
wyższego planu jako celu samego w sobie”), #595 (P0 — `config apply`, koszt
40–65 USD), #617 (P1 — DR zdjęć R2). **Treści #604 nie udało mi się odczytać
(narzędzie GitHub zwróciło błąd „token store temporarily unavailable”, trzy próby);
opieram się na `REDIS_HA_DECYZJE_603_604.md`, który opisuje #604.
Wniosek dla #604 do potwierdzenia po odzyskaniu odczytu.

## 4. Funkcje Pro, które wykorzystamy

Kolejność: najpierw to, co domyka P0 przy najmniejszym koszcie i ryzyku.

### 4.1. Volume Backups Postgresa (Daily + Weekly)

- **Co daje Kukingowi:** szybkie cofnięcie bazy do jednego z zapisanych
  punktów po nieudanej migracji albo omyłkowym `DELETE`, bez zestawiania
  zrzutu z R2. Daily trzymane 6 dni, Weekly 27 dni, Monthly 89 dni
  ([reference/backups](https://docs.railway.com/reference/backups)).
- **Domyka lub upraszcza:** część „wiadomo, czy Volume Backups są aktywne” z #594
  i D8 w `LISTA_KROKOW_ALFA.md`. **Nie domyka** #594 ani #193 (patrz §5).
- **Kroki w panelu:**
  1. Railway, projekt `kuking`, środowisko `production`, serwis Postgres,
     zakładka **Backups**. Zapisz dosłownie, co widać (i datę) w protokole
     z `DR594_PIERWSZY_ZRZUT_WLASCICIEL.md` (pole P1). To zamyka sprzeczność
     „panel 17 IX kontra dokumentacja 18 IX”.
  2. Włącz harmonogram **Daily**, potem **Weekly**. Monthly opcjonalnie (89 dni
     retencji przy groszowym koszcie).
  3. Zrób jedną kopię ręczną i sprawdź, że pojawia się na liście z rozmiarem.
  4. **Nie klikaj Restore na produkcji.** Próbę odtworzenia zrób w osobnym
     środowisku albo na kopii projektu (Restore montuje nowy wolumen w tym samym
     miejscu i przełącza usługę).
- **Co zmienić w repo (osobno, nie w tej gałęzi):**
  `docs/infra/KOPIE_I_ODTWORZENIE.md` §5.3 — wpisać wynik odczytu panelu i
  zamknąć sprzeczność D-043; ewentualna nowa decyzja w `docs/DECISIONS.md`
  (numer: pierwszy wolny w dniu pisania) korygująca D-043 do stanu faktycznego.
  IaC nie deklaruje harmonogramów backupów; nie zakładać, że `railway config apply`
  je utworzy ani nie zniszczy — sprawdzić w `railway config plan`.
- **Ryzyko:** (a) „Wiping a volume deletes all backups” — usunięcie wolumenu
  kasuje też kopie; (b) restore działa tylko w tym samym projekcie i
  środowisku, kopii nie da się pobrać; (c) dokumentacja nazywa funkcję „still
  under development”; (d) koszt to opłata za wolumen tylko za dane wyłączne
  dla kopii — przy 500 MB wolumenu grosze (szacunek z §5.3).

### 4.2. PITR Postgresa (odtwarzanie do dowolnej chwili)

- **Co daje:** cofnięcie bazy do konkretnej minuty (okno około 4 tygodni), np.
  sprzed błędnej migracji. Nowa usługa siostrzana z odtworzonymi danymi;
  źródło nietknięte ([volumes/point-in-time-recovery](https://docs.railway.com/volumes/point-in-time-recovery)).
  Volume Backups mają rozdzielczość doby, PITR minutową.
- **Domyka lub upraszcza:** #594 pkt 5–6 („włączyć właściwe warstwy tam, gdzie
  dostępne i ekonomicznie uzasadnione”); RPO z dni do minut.
- **Kroki:**
  1. Najpierw **4.1** (żeby był punkt bezpieczeństwa).
  2. Utwórz **Storage Bucket Railway** w projekcie (nie mylić z R2 — to osobny
     bucket wyłącznie na archiwum WAL).
  3. Na serwisie Postgres ustaw zmienne archiwizacji według strony PITR
     (nazwy i wartości z dokumentacji w dniu wdrożenia; **nie wklejać kluczy do
     repozytorium ani do czatu**).
  4. Poczekaj na pierwszą pełną kopię bazową — okno zaczyna się dopiero od niej.
  5. Zrób **próbę odtworzenia do nowej usługi siostrzanej** i ją usuń.
- **Repo:** wpis do `KOPIE_I_ODTWORZENIE.md` (RPO minuty, RTO z próby); jeśli
  będzie potrzebna deklaracja bucketu w IaC — osobne PR-y z `railway config plan`.
  Sprawdzić obraz Postgresa: PITR wymaga tagu major (nasz `postgres-ssl:18` go
  spełnia — odczyt 18 IX).
- **Ryzyko:** wymaga zmiany konfiguracji serwisu Postgres (może wymagać
  restartu bazy — **okno serwisowe, po świeżym zrzucie offsite, nie w godzinach
  ruchu**); okno nie sięga wstecz; dodatkowy egress bazy → bucket 0,05 USD/GB;
  odtworzenie jest w tym samym projekcie i koncie (nie chroni przed utratą konta).

### 4.3. Monitory zasobów Railway + webhook (część #599)

- **Co daje:** powiadomienie e-mail i w aplikacji (oraz webhook projektu, więc
  ten sam kanał co alarmy błędów), gdy CPU, RAM, dysk albo egress przekroczy próg —
  zanim wystąpi OOM lub pełny wolumen Postgresa
  ([guides/alerts-crashes-failed-deploys](https://docs.railway.com/guides/alerts-crashes-failed-deploys)).
  **Tylko Pro.**
- **Domyka lub upraszcza:** #599 (resource pressure, restarty/OOM, budżet
  połączeń — bez własnego kodu); część E6 i B6 z `LISTA_KROKOW_ALFA.md`.
  Nie zastępuje zewnętrznego uptime (E2) — monitor Railway działa wewnątrz tej
  samej platformy, a #599 wprost wymaga monitora spoza procesu i usługi.
- **Kroki:**
  1. Projekt, **Settings, Webhooks**: dodaj webhook na ten sam kanał, który
     obsługuje `LOG_BLAD_WEBHOOK_URL` (Slack lub Discord — Railway sam dopasowuje
     format). Wyzwól „test” z panelu.
  2. **Observability**, dla każdego z serwisów (web, worker, scheduler, Postgres):
     widget CPU, RAM, dysk (Postgres), egress; menu trzech kropek, **Add monitor**.
  3. Progi startowe (do skorygowania po tygodniu, nie od razu): RAM 80% limitu
     z `limitOverride`; dysk Postgresa 70% wolumenu (500 MB to mało, patrz 4.7);
     CPU i egress bez alertu na start — najpierw zebrać dwa tygodnie linii bazowej.
  4. Zapisz w #599 zrzut ustawień (bez adresów webhooków).
- **Repo (osobno):** `docs/infra/MONITORING_BLEDOW.md` — opis progów i kanału;
  Railway IaC nie deklaruje monitorów (nie zakładać, że są w `railway.ts`).
  Uwaga na lukę w #599 (potwierdzona luka dostarczenia alarmu): webhook
  Railway to niezależny kanał, więc nie dziedziczy błędu z `WebhookBleduHandler`.
- **Ryzyko:** hałas alertów (dlatego progi po pomiarze); webhook wyłączany po
  „100 błędach w 6 godzin” na 24 godziny ([observability/webhooks](https://docs.railway.com/observability/webhooks)).

### 4.4. Limit wydatków i alert kosztu (decyzja D1: twardo 100 USD, alert 60 USD)

- **Co daje:** twardy sufit rachunku i wcześniejsze ostrzeżenie. **Dostępne także
  na Hobby** — nie jest to funkcja tylko-Pro, ale wykonujemy ją razem ze zmianą
  planu, bo limity są liczone od zużycia workspace’u ([reference/usage-limits](https://docs.railway.com/reference/usage-limits)).
- **Domyka:** A3 w `LISTA_KROKOW_ALFA.md`; punkt „alert/budżet kosztów Railway”
  w #599; zgoda na koszt dla #595.
- **Kroki:** Railway, **Workspace, Usage, Set Usage Limits**: alert e-mail
  **60 USD**, twardy limit **100 USD** (dla „Compute Usage”; „Agent Usage” ma
  osobne ustawienie i domyślnie 20 USD na Pro — jeśli nie używamy agenta Railway,
  zostawić domyślne). Sprawdź adres e-mail powiadomień. Railway wysyła ostrzeżenia
  przy **75%, 90% i 100% limitu**.
- **Uwaga o sensie liczb:** twardy limit **wyłącza wszystkie usługi** (produkcję
  też), a plan trzech serwisów szacowany jest na 40–65 USD. 100 USD to około
  1,5–2,5 raza więcej — margines wystarcza także na dodanie PITR (grosze) czy
  klastra HA (szacunek około 11–23 USD zużycia wg `REDIS_HA_DECYZJE_603_604.md` §5),
  ale **nie** wystarczy przy jednoczesnej pełnej HA, drugiej replice web i
  osobnym workerze `media`. Przy każdym rozszerzeniu wracamy do arytmetyki.
  Alert 60 USD zadziała dopiero od 60% twardego limitu, więc jest sygnałem do
  przeglądu, nie zabezpieczeniem.
- **Repo:** komentarz w `railway.ts` o szacunku 40–65 USD i `DEPLOYMENT_RUNBOOK.md`
  §12 — uzupełnić o rzeczywisty rachunek po pierwszym pełnym miesiącu.
- **Ryzyko:** hard limit wyłącza produkcję; cofnięcie: podnieść limit w tym samym
  miejscu (A3).

### 4.5. Dziennik audytu i 30 dni logów

- **Co daje:** kto i kiedy zmienił zmienną, serwis, wdrożenie lub ustawienie
  workspace (30 dni zamiast 48 godzin) — przydatne po incydentach typu
  „`MAIL_MAILER` ustawiony ręcznie”; logi aplikacji 30 dni zamiast 7, więc
  rozbieżności z czwartkowego deploya da się jeszcze zbadać w następnym
  tygodniu ([reference/audit-logs](https://docs.railway.com/reference/audit-logs),
  [observability/logs](https://docs.railway.com/observability/logs)).
- **Domyka lub upraszcza:** #599 (obserwowalność bez własnego kodu), A4
  „zmienne ustawione tylko w panelu” (dowód, kto co ustawił), rozliczenia
  incydentów. Zdarzenie niedoręczenia alarmu trafia dziś do pliku kontenera, nie do
  Railway Logs (#599) — 30 dni logów tego nie naprawia, ale wydłuża pamięć
  wszystkiego, co logi jednak łapią.
- **Kroki:** brak — działa po zmianie planu. Audit Logs: **Workspace, Audit Logs**
  (tylko Admin). Sprawdzić raz po włączeniu Pro, że wpisy się pojawiają.
- **Repo:** `docs/infra/MONITORING_BLEDOW.md` — zaktualizować retencję logów z 7 na
  30 dni; jeśli gdzieś w dokumentach jest mowa o „7 dniach” logów Railway, poprawić.
- **Ryzyko:** brak. Limit 500 linii/s na replikę pozostaje (oba plany).

### 4.6. SMTP — wykorzystujemy tylko jako furtkę, nie zmieniamy poczty

- **Co daje:** możliwość użycia SMTP dostawców (EmailLabs, Brevo) zamiast HTTPS API
  ([networking/outbound-networking](https://docs.railway.com/networking/outbound-networking)).
- **Rekomendacja: NIE zmieniać.** Produkcyjna droga poczty to EmailLabs po API HTTPS
  (D-116, D-047); jest przetestowana i nie ma powodu jej ruszać przy zmianie planu.
  Ta funkcja jest w zestawieniu tylko po to, żeby **nie marnować** informacji: jeśli
  EmailLabs kiedyś odmówi API, w awarii jest droga zapasowa bez zmian w kodzie
  (`MAIL_MAILER=smtp` + cztery zmienne, opisane w `DEPLOYMENT_RUNBOOK.md` KROK 3).
- **Kroki w panelu:** żadne. Po zmianie planu **nie ustawiać** `MAIL_HOST` ani
  pozostałych zmiennych SMTP „na próbę”.
- **Repo (osobno):** `DEPLOYMENT_RUNBOOK.md` — usunąć ostrzeżenia „na Hobby SMTP nie
  działa” lub zmienić na „na Pro działa, ale nie używamy”; poprawić także
  `docs/infra/POCZTA_URUCHOMIENIE.md`, jeśli powtarza ten warunek. Wymaga
  przeglądu testów pilnujących treści tych ostrzeżeń (D-116).
- **Ryzyko:** cicha awaria poczty, jeśli ktoś przełączy `MAIL_MAILER=smtp` bez
  zmiennych; dlatego **nie zmieniamy**.

### 4.7. Live resize wolumenu Postgresa (i większy wolumen)

- **Co daje:** wolumen Postgresa ma dziś 500 MB (`KOPIE_I_ODTWORZENIE.md`), a limit
  Hobby wynosi 5 GB; Pro pozwala samoobsługowo powiększać dalej, bez przestoju
  ([reference/volumes](https://docs.railway.com/reference/volumes)). Dla
  bazy społeczności ze zdjęciami w R2 (w bazie tylko metadane) 500 MB starcza
  długo, ale przy alarmie „dysk 70%” z 4.3 jest szybka droga wyjścia.
- **Kroki:** serwis Postgres, **Settings, Volume**: zwiększenie rozmiaru
  (live resize) — dopiero po alarmie z 4.3, nie „na zapas”; wolumen kosztuje około
  0,15 USD/GB/mies. Po zwiększeniu sprawdzić, że backupy z 4.1 nadal się wykonują
  (kopia ręczna maks. 50% rozmiaru wolumenu — rośnie razem z nim).
- **Repo:** brak zmian; uzupełnić `KOPIE_I_ODTWORZENIE.md` o nowy rozmiar.
- **Ryzyko:** wolumenu nie da się zmniejszyć; nie powiększać zapobiegawczo.

### 4.8. Więcej domen własnych, buildów i projektów (bez pracy)

- **Co daje:** 20 domen zamiast 2 — dziś `kuking.pl` + `www` zajmują cały limit
  Hobby (`DEPLOYMENT_RUNBOOK.md`, uwaga o 2 domenach); 10 równoległych buildów
  zamiast 3, co przy trzech usługach budowanych z jednego commita (i przyszłym
  `media`) usuwa kolejkę buildów. Bez potrzeby konfiguracji.
- **Domyka lub upraszcza:** miejsce na domenę stagingu, gdyby zapadła decyzja
  o A7 (dziś odłożone), oraz na dodatkową usługę (np. worker `media` w #600)
  bez zderzenia z limitem usług.
- **Kroki:** brak; dopiero przy nowej domenie: Railway, serwis, **Settings,
  Networking, Custom Domain**, potem rekordy w Cloudflare (`DEPLOYMENT_RUNBOOK.md`).
- **Repo:** usunąć z dokumentów założenie „limit 2 domen na plan” tam, gdzie
  jest podane jako ograniczenie bieżące.
- **Ryzyko:** brak.

## 5. Backupy Railway a nasz offsite `pg_dump` (#193, #594) — zastępują czy uzupełniają

**Uzupełniają. Nie zastępują.** Uzasadnienie z dokumentacji Railway
([guides/postgres-backups-restores](https://docs.railway.com/guides/postgres-backups-restores),
odczyt 29 IX 2026) i z naszego DR:

| Cecha | Volume Backups | PITR | Nasz zrzut offsite (`kopia-bazy`, R2) |
|---|---|---|---|
| Okno | dobowe/tygodniowe/miesięczne migawki (6/27/89 dni) | dowolna minuta, około 4 tygodni | kolejna doba (cron 02:17 UTC), retencja z `KOPIE_I_ODTWORZENIE.md` |
| Gdzie żyje | ten sam projekt i konto Railway | Storage Bucket Railway w tym samym projekcie | **Cloudflare R2, osobne konto i dostawca, zaszyfrowany** |
| Odtworzenie | tylko do tego samego projektu i środowiska | do nowej usługi w tym samym projekcie | **gdziekolwiek** (nowy klaster, laptop, inny dostawca) |
| Przetrwa usunięcie wolumenu | **nie** („Wiping a volume deletes all backups”) | zależy od bucketu, ale tylko w projekcie | tak |
| Przetrwa usunięcie projektu lub blokadę konta | **nie** | **nie** | **tak** — dokumentacja Railway: po usunięciu projektu przetrwają tylko zrzuty offsite |
| Do pobrania poza Railway | **nie** | **nie** | tak (to jest cel) |
| Weryfikacja | restore w panelu | restore do usługi siostrzanej | restore drill z `DR594_RUNBOOK_LOKALNY.md` |

Wnioski:

1. **Warstwa offsite zostaje warunkiem #193 i #594** (pkt 7 w #594: „co najmniej
   jedna kopia logiczna/offsite, niezależna od mechanizmu snapshot/PITR tego
   samego dostawcy”). Plan Pro tego nie zmienia.
2. **Volume Backups i PITR skracają czas i utratę danych przy zwykłych awariach**
   (zła migracja, omyłkowy `DELETE`, uszkodzenie wolumenu) — bez ściągania i
   deszyfrowania zrzutu z R2. To znacząca poprawa RPO i RTO, ale **nie zamyka**
   #594 (definicja gotowości wymaga zrzutu odtworzonego do osobnej bazy).
3. **Kolejność wdrożenia się nie zmienia:** D1 (ręczny zrzut i odtworzenie, przed
   `apply`) → D2–D6 (`kopia-bazy`) → 4.1 → 4.2. Zmiana planu nie może wyprzedzać
   pierwszego ręcznego zrzutu offsite z D1: ten zrzut jest warunkiem `apply`
   (`LISTA_KROKOW_ALFA.md`, pozycja 6).
4. **Zdjęcia w R2 nie są objęte żadną z tych warstw** — Volume Backups i PITR
   dotyczą Postgresa. DR zdjęć to osobne #617 i `DR_ZDJEC_R2.md` (zależy od
   zdania w polityce prywatności, F1). Plan Railway tu nic nie zmienia.
5. **HA nie jest kopią zapasową.** Omyłkowy `DELETE` replikuje się na standby
   (`REDIS_HA_DECYZJE_603_604.md` §5); klaster HA nie zastępuje żadnej z trzech
   warstw powyżej.
6. **Do zapisania w protokole odbioru #594 po zmianie planu:** data odczytu
   zakładki Backups, aktywne harmonogramy, data pierwszej kopii bazowej PITR,
   RPO z Volume Backups (24 h) i z PITR (minuty) — obok RPO/RTO z offsite (D6).

## 6. Funkcje Pro, których na razie nie używamy — i dlaczego

| Funkcja | Dlaczego nie |
|---|---|
| **Redis (Railway lub szablon)** | Zakazany w `AGENTS.md` (sekcja „Zakaz overengineeringu”) bez zmierzonej potrzeby; `REDIS_HA_DECYZJE_603_604.md`: „na razie zostawić kolejkę i cache w PostgreSQL”. Posiadanie planu Pro nie jest pomiarem. |
| **PostgreSQL HA (#604)** | Konwersja kosztuje szacunkowo 43–90 zł/mies. netto (dokument #603/#604), wiąże się z zerwaniem połączeń i zmianą adresów, a decyzja wymaga pomiaru z #599 i zadeklarowanego wymogu dostępności. **Wstrzymane; wraca po 4 tygodniach danych z Monitorów (4.3) i po zamknięciu #594.** Dostępna także bez Pro — nie jest powodem zmiany planu. Uwaga: zanim ktokolwiek ją włączy, przeczytać `docs/infra/REDIS_HA_DECYZJE_603_604.md` §5 (wpływ na klienta, obraz musi być oficjalny, bez PostGIS). |
| **Druga replika web (#600)** | Repliki do 6 są już na Hobby; wymaga #595, #596, #599, #598. Decyzja na podstawie metryk i wymogu dostępności, nie planu. Web nie ma wolumenu (repliki się nie zderzają), ale scheduler musi zostać w jednej replice. |
| **PgBouncer (#600)** | Tylko jeśli budżet połączeń z #598 tego wymaga. Włączenie zmienia `DATABASE_URL`; migracje muszą używać połączenia bez puli (`DATABASE_UNPOOLED_URL`). Nie „na zapas” — #600 pkt 2. |
| **Repliki wieloregionowe, inne regiony** | Użytkownicy w Polsce, region Amsterdam już ustawiony; replikom z wolumenem (Postgres) to nie pomoże, a dla web nie ma mierzonego problemu opóźnień. |
| **Staging i PR Environments** | Odłożone decyzją 25 IX (A7/A8); każdy dodatkowy serwis zwiększa zużycie w limicie 100 USD. Funkcja jest dostępna, przełącznik zostaje OFF. |
| **RBAC środowiska** | Wyłącznie Enterprise („available on Railway Enterprise”) — nie kupimy planu Pro i nie dostaniemy tego. |
| **Business Class / SLA** | Od 5000 USD/mies. wydatków; poza zasięgiem. Wsparcie Pro „zwykle w 72 godziny” bez SLA — **nie liczyć na nie w incydencie**; procedura awaryjna zostaje własna (`DEPLOYMENT_RUNBOOK.md`). |
| **Serverless (App Sleeping)** | Dostępny na każdym planie; na produkcji świadomie wyłączony (cold start i 502 dla odbiorców 50+). |
| **Railway Cron dla schedulera** | Minimum co 5 minut, a Laravel wymaga co minutę (komentarz w `railway.ts`); Cron stosujemy tylko dla `kopia-bazy`. |
| **Członkowie dodatkowi** | Nielimitowani, ale cena za miejsce nieopisana (§1). Dodać dopiero po sprawdzeniu w panelu i po A2 (2FA). |
| **Sandbox/VM Railway** | Inna kategoria produktu, nic w Kukingu tego nie potrzebuje. |

## 7. Kolejność wykonania (dla właściciela)

Wszystko w tej kolejności; punkty 1–3 nie zależą od `apply`.

1. **Zmiana planu**: Railway, **Workspace, Plans**, wybór Pro. Zapisz w #594
   datę zmiany. Sprawdź na ekranie planu **cenę za miejsce** (§1) i nie dodawaj
   członków przed tym sprawdzeniem.
2. **A3 — limit wydatków** (§4.4): alert 60 USD, twardo 100 USD.
3. **Odczyt zakładki Backups** (§4.1 krok 1) i włączenie Daily + Weekly. Jeśli
   zakładka **nadal** pokazuje komunikat o planie — nie powtarzać zakupu,
   otworzyć zgłoszenie na Central Station i zapisać w #594 (dokumentacja
   i panel się rozeszły).
4. **D1** (ręczny zrzut offsite) i reszta z `LISTA_KROKOW_ALFA.md` bez zmian —
   zmiana planu ich nie skraca.
5. **Monitory + webhook** (§4.3) po `apply` #595 — bo monitory mają liczyć
   usługi `web`, `worker`, `scheduler`, a nie stary jeden serwis.
6. **PITR** (§4.2) po 4.1 i po pierwszym udanym przebiegu `kopia-bazy` (D4–D6).
7. Po miesiącu: porównać rachunek z szacunkiem 40–65 USD i zaktualizować A3,
   ewentualnie wrócić do #600 i #604 z danymi.

## 8. Rozbieżności i czego nie sprawdziłem

- **Cennik a dokumentacja**: rozmiar wolumenu Pro (50 GB kontra 1000 GB), RAM/CPU
  Pro (1 TB/1000 vCPU kontra przykład 24/24 na replikę). Dla Kukinga bez znaczenia,
  ale nie przepisywać żadnej z liczb do innych dokumentów jako pewnika.
- **Bramka planu dla Volume Backups, PITR, HA i PgBouncer**: dokumentacja
  ich nie stawia; panel 17 IX wskazał Pro dla Volume Backups. Ostatecznie rozstrzyga
  zakładka Backups po zmianie planu (§4.1).
- **Cena za miejsce w Pro**: nie opisana na przeczytanych stronach (§1).
- **#604**: treść zgłoszenia nieodczytana (błąd narzędzia GitHub, §3).
- **Wsparcie i limity mogą się zmienić**: wszystkie wartości z odczytu 29 IX 2026;
  przed decyzją o HA lub drugiej replice czytać dokumentację jeszcze raz.
- Nie sprawdzałem rzeczywistego stanu panelu Railway (nie wolno było się łączyć);
  stan produkcji w §3 pochodzi z odczytów udokumentowanych w repozytorium
  (17–25 IX 2026).

## 9. Spis źródeł (odczyt 29 IX 2026)

- [Plans](https://docs.railway.com/reference/pricing/plans)
- [Pricing FAQ](https://docs.railway.com/reference/pricing/faqs)
- [Usage limits](https://docs.railway.com/reference/usage-limits)
- [Volume backups](https://docs.railway.com/reference/backups)
- [PITR](https://docs.railway.com/volumes/point-in-time-recovery)
- [Postgres backups i odtwarzanie](https://docs.railway.com/guides/postgres-backups-restores)
- [PostgreSQL HA](https://docs.railway.com/databases/postgresql-ha)
- [PgBouncer](https://docs.railway.com/databases/postgresql-pgbouncer)
- [Wolumeny](https://docs.railway.com/reference/volumes)
- [Skalowanie i repliki](https://docs.railway.com/reference/scaling)
- [Regiony](https://docs.railway.com/reference/regions)
- [Środowiska](https://docs.railway.com/reference/environments)
- [Workspace i role](https://docs.railway.com/reference/teams)
- [Dziennik audytu](https://docs.railway.com/reference/audit-logs)
- [Logi](https://docs.railway.com/observability/logs)
- [Webhooki](https://docs.railway.com/observability/webhooks)
- [Alerty i monitory](https://docs.railway.com/guides/alerts-crashes-failed-deploys)
- [SMTP](https://docs.railway.com/networking/outbound-networking)
- [Wsparcie](https://docs.railway.com/reference/support)
- [Sieć prywatna](https://docs.railway.com/reference/private-networking)
