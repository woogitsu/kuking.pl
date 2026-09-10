# Audyt 11/13 — infrastruktura, backupy, disaster recovery, monitoring i poczta

**Repozytorium:** `woogitsu/kuking.pl`  
**Punkt odniesienia:** `main` @ `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 10.09.2026

## Werdykt

Projekt ma ponadprzeciętnie dobrą dokumentację infrastruktury i disaster recovery, ale kilka kluczowych zabezpieczeń istnieje nadal **jako procedura, nie jako udowodniony stan produkcyjny**.

Najważniejsze:

- **P0:** #9 — brak wykonanego restore drillu.
- **P0:** #120 — R2 nadal jest bramką produkcyjną; bez niego media mogą pozostać na ulotnym `local`.
- **P0/P1:** #193 — brak automatycznego, zaszyfrowanego backupu logicznego poza Railway.
- **P0/P1:** #33 — brak zewnętrznego monitoringu `/health` i potwierdzonego alertu operatorskiego.
- **P1:** #234 — e-mail po 3 nieudanych próbach może wpaść do `failed_jobs` bez alarmu, a użytkownik nadal widzi komunikat sugerujący sukces.

## 1. P0 — restore drill nadal nie został wykonany

Issue **#9 „Backup bazy i przeprowadzony restore drill”** jest otwarte z etykietą P0. Repo zawiera bardzo dobrą procedurę w `docs/infra/KOPIE_I_ODTWORZENIE.md`, ale sam dokument mówi, że tabela wyników restore drillu pozostaje pusta.

To rozróżnienie jest kluczowe:

- **runbook istnieje** — dobrze,
- **backup może istnieć** — niewystarczające,
- **odtworzenie zostało wykonane i zmierzone** — tego brakuje.

### Co powinno być wynikiem drillu

- data i osoba wykonująca,
- źródło kopii,
- punkt w czasie,
- zmierzony RPO,
- zmierzony RTO,
- integralność kluczowych tabel,
- kontrola logowania i 2FA po restore,
- kontrola usuniętych/pending-delete kont po restore,
- kontrola media references,
- lista poprawek do runbooka.

**Bramka:** nie traktować backupu jako gotowego do produkcji, dopóki przynajmniej jedno pełne odtworzenie na staging nie jest udokumentowane.

---

## 2. P0 — media mogą być na ulotnym filesystemie kontenera

`docs/infra/KOPIE_I_ODTWORZENIE.md` stwierdza wprost, że bucket R2 na moment najnowszej weryfikacji jeszcze nie istniał. Dokument ostrzega, że przy `FILESYSTEM_DISK=local` media siedzą wewnątrz kontenera i mogą zniknąć przy redeployu/restarcie.

Issue **#120** pozostaje otwartą bramką P0 przed wystawieniem produkcyjnego `cdn.kuking.pl`.

### Najważniejszy warunek

Przed przyjęciem prawdziwych zdjęć trzeba udowodnić na realnym staging R2:

- wariant publiczny → 200,
- oryginał → brak publicznego dostępu,
- API aplikacji ma dostęp do oryginału,
- `r2.dev` wyłączone dla oryginałów,
- brak `incoming/` w publicznym buckecie,
- zapis bez zależności od public ACL,
- EXIF znika z wariantu,
- delete usuwa oryginał i warianty,
- błąd zapisu generuje bezpieczny komunikat i alert.

### Werdykt

**Jeżeli aktualna produkcja nadal ma `FILESYSTEM_DISK=local`, nie wpuszczać prawdziwych uploadów.** To nie jest problem wydajności — to ryzyko utraty unikalnych zdjęć użytkowników.

---

## 3. P0/P1 — brak niezależnej warstwy backupu poza Railway

Issue **#193** dokumentuje, że planowana trzecia warstwa `pg_dump` poza Railway nie istnieje.

Co istotne, repo wyklucza uruchomienie `pg_dump` z głównego kontenera PHP bez osłabienia hardeningu:

- `proc_open` jest wyłączone,
- aplikacyjny scheduler świadomie nie używa shellowych commandów,
- zdejmowanie tego ograniczenia byłoby złym kompromisem.

Wybrany kierunek z issue jest sensowny: **minimalny osobny serwis Railway**, który:

1. łączy się do Postgresa po sieci wewnętrznej,
2. robi `pg_dump` klientem zgodnym z wersją serwera,
3. szyfruje dump **przed** wysłaniem,
4. wysyła do niepublicznego bucketu/prefiksu poza główną awarią bazy,
5. ma retencję,
6. alarmuje, gdy backup nie powstanie,
7. jest okresowo odtwarzany testowo.

### Ważna korekta dokumentacyjna

Starsze #9 mówi o Daily/Weekly Volume Backups + PITR, ale najnowszy `KOPIE_I_ODTWORZENIE.md` odnotowuje decyzję D-043, że Volume Backups nie są dostępne na bieżącym planie Free/Hobby. Zatem checklisty #9/Deployment Runbook należy ujednolicić z realnym planem Railway. Inaczej ktoś może „odhaczyć” zabezpieczenie, którego konto nie oferuje.

---

## 4. P0/P1 — zewnętrzny uptime nadal nie jest udowodniony

Issue **#33** ma P0. Najnowszy komentarz potwierdza:

- `/health` jest rozbudowane i sprawdza bazę/migracje/media,
- zamiast Sentry istnieje własny webhook błędów z testowanym scrubbingiem PII,
- **zewnętrzny monitor `/health` nie jest skonfigurowany**,
- alert idzie potencjalnie na Discord/Slack, ale wymaga konfiguracji poza repo.

### Dlaczego `/health` samo nie wystarcza

Endpoint monitoruje usługę tylko wtedy, gdy **ktoś z zewnątrz go pyta**. Awaria całego frontu, DNS, proxy albo Railway może jednocześnie uniemożliwić aplikacji samodzielne wysłanie alarmu.

### Minimum

- monitor z innej infrastruktury,
- check co 1–5 min,
- alert po maks. 2–5 min,
- drugi kanał alarmowy dla P0,
- test kontrolowany: celowo wyłączyć usługę i zmierzyć czas alarmu.

---

## 5. P1 — `failed_jobs` poczty może rosnąć po cichu

Issue **#234** jest szczególnie ważne przy kampanii migracyjnej z Garnek.pl.

Stan:

- worker ma `--tries=3 --backoff=10,60,300`,
- po około 6 minutach permanentnej odmowy list trafia do `failed_jobs`,
- `/health` może nadal być zielony,
- użytkownik może widzieć komunikat sugerujący, że list został wysłany,
- operator dowie się dopiero po ręcznym sprawdzeniu.

Przy limicie EmailLabs 300 wiadomości/dzień może to uderzyć dokładnie w:

- potwierdzanie rejestracji,
- reset hasła,
- magic-link login,
- moderację,
- digest.

### Rekomendacja

1. `failed_jobs` z ostatniego okna czasu → `/health: degraded`.
2. Alarm przy pierwszej nowej porażce finalnej.
3. Rozróżnić quota/rate-limit od błędu trwałego.
4. Alarm przy 70–80% budżetu dziennego.
5. UI ma mówić „wiadomość jest wysyłana / możesz wysłać ponownie”, a nie gwarantować dostarczenie, zanim system to potwierdzi.
6. Panel operatorski: liczba wysłanych, odrzuconych, failed, pozostały budżet.

---

## 6. P1 — direct Railway origin pozostaje częścią modelu zagrożeń

Z audytu bezpieczeństwa: `NormalizeForwardedFor` sam opisuje problem wejścia bezpośrednio przez `*.up.railway.app`. Jeżeli origin jest publicznie osiągalny z pominięciem Cloudflare, klient może wpływać na nagłówki proxy, a tym samym na rate limiting i `ip_hash`.

To trzeba traktować jako infrastrukturę, nie tylko middleware:

- origin powinien być niedostępny publicznie lub akceptować wyłącznie zaufany edge,
- host allowlist / edge secret / mTLS / private networking — zależnie od realnych możliwości Railway/Cloudflare,
- test negatywny z internetu: bezpośredni hostname Railway ma nie obsłużyć zwykłego requestu użytkownika.

---

## 7. P2 — Cloudflare pozostaje konfiguracją ręczną bez IaC

`KOPIE_I_ODTWORZENIE.md` odnotowuje, że DNS, WAF i Cache Rules Cloudflare są odtwarzane z runbooka, ale nie są zakodowane jako Infrastructure-as-Code.

Dla jednoosobowego projektu to akceptowalne na MVP, ale ma dwa koszty:

- configuration drift,
- brak szybkiego diffu po błędnej zmianie w panelu.

### Rekomendacja

Nie wdrażać Terraformu „bo wypada”. Najpierw eksportować regularnie krytyczne reguły i utrzymywać **konkretną tabelę stanu oczekiwanego** w repo. IaC dopiero gdy liczba ręcznych zmian zacznie generować realne błędy.

---

## 8. P2 — monitoring błędów jest sensowny, ale nie pełni roli systemu obserwowalności

Własny `blad_webhook` z testem PII to dobry kompromis dla MVP. Nie daje jednak:

- deduplikacji exception fingerprint,
- trendów,
- release correlation,
- breadcrumbs,
- łatwej analizy częstotliwości,
- alertów na regresję po deployu.

Nie oznacza to, że trzeba natychmiast instalować Sentry. Przy obecnej skali można zostać przy webhooku, ale uzupełnić go o:

- `release/commit SHA`,
- typ błędu / hash fingerprint bez PII,
- licznik w określonym oknie,
- prosty dashboard operacyjny.

PostHog nie jest potrzebny do zamknięcia bramki produkcyjnej, jeśli wewnętrzna analityka PostgreSQL odpowiada na pytania produktowe.

---

## 9. Co jest bardzo dobre

- osobny dokument disaster recovery z procedurami, a nie ogólnikami,
- wyraźne rozróżnienie „repo to potwierdza” vs „panel właściciela musi potwierdzić”,
- `APP_KEY` potraktowany jako rzeczywisty single point of decryption,
- plan offsite backupu nie kopiuje hasła do produkcyjnej bazy do GitHub Secrets,
- dump ma być szyfrowany przed wysłaniem,
- healthcheck rozróżnia `degraded` i krytyczne błędy,
- log/error webhook ma regresyjny test braku PII,
- hardening PHP nie jest rozluźniany tylko po to, by zmieścić backup w tym samym procesie.

## Kolejność działań

1. **R2 / #120 i potwierdzenie, że prawdziwe media nie trafiają na `local`.**
2. **Restore drill / #9.**
3. **Offsite encrypted pg_dump / #193.**
4. **Zewnętrzny uptime + test alarmu / #33.**
5. **Alarmowanie `failed_jobs` poczty / #234.**
6. Zamknięcie direct-origin bypass.
7. Dopiero potem poprawki komfortu obserwowalności.

## Ograniczenia audytu

Nie mam dostępu do paneli Railway, Cloudflare, EmailLabs ani zewnętrznego monitora. Repo może wykazać procedurę i konfigurację kodową, ale nie dowodzi, że przełącznik w panelu jest faktycznie ustawiony. Próba niezależnego odczytu `kuking.pl` z lokalnego środowiska narzędziowego nie powiodła się z powodu braku rozwiązywania DNS w tym środowisku, więc nie wykorzystuję jej jako dowodu dostępności/niedostępności serwisu.

---

## 10. P0/P1 — post-deploy smoke test nie ma jeszcze udowodnionego działania

Podczas końcowego przeglądu `.github/workflows/deploy.yml` znalazłem ważny fakt historyczny zapisany **w samym aktualnym workflow**: według pomiaru z 9 września 2026 workflow `Deploy` miał **249 przebiegów i wszystkie kończyły się jako `skipped`**. Oznacza to, że automatyczny test dymny po wdrożeniu — `/health`, `/`, `/login`, `robots.txt`, kontrola 404, HTTP→HTTPS i brak debug leak — **nie uruchomił się ani razu**.

Aktualny plik zawiera już próbę naprawy: warunek joba został uproszczony do zdarzenia `deployment_status` ze stanem `success`, a filtrowanie nazwy środowiska przeniesiono do kroku diagnostycznego. To jest właściwy kierunek, ale **kod poprawki nie jest dowodem, że mechanizm już działa**.

### Kryterium zamknięcia

Po najbliższym prawdziwym deployu trzeba zapisać dowód, że:

1. workflow `Deploy` uruchomił job `Test dymny po deployu`,
2. job nie był `skipped`,
3. rozpoznał `production` albo `staging`,
4. wszystkie testy HTTP wykonały się przeciw właściwemu URL,
5. wynik joba jest powiązany z tym samym commitem, który Railway właśnie wdrożył.

Dopóki tego nie ma, **zielony status Railway nie powinien być traktowany jako dowód przejścia post-deploy verification**.

### Rekomendacja

Dodać do `docs/OTWARCIE.md` / bramki startowej jedno literalne pole: „ostatni produkcyjny deploy: smoke test po deployu = SUCCESS, run ID / data”. Pozwoli to uniknąć ponownego pomylenia „workflow istnieje” z „workflow naprawdę się wykonał”.
