# Bramka wdrożenia Railway po CI (#2025)

## Stan

Workflow `.github/workflows/railway-ci-gated-deploy.yml` jest przygotowany, lecz
**domyślnie wyłączony**. Samo scalenie kodu nie przełącza produkcji.
Uruchamia się tylko po zakończeniu `CI` dla push do `main` i tylko gdy zmienna
repozytorium `KUKING_CI_GATED_RAILWAY_DEPLOY` ma dokładnie wartość `true`.

Railway `Wait for CI` nie wystarcza do tej reguły: anulowany workflow może
przepuścić wdrożenie, gdy inny workflow tego samego commita przeszedł.
Źródło: [Railway — Controlling GitHub Autodeploys](https://docs.railway.com/deployments/github-autodeploys#wait-for-ci).

## Co sprawdza bramka

1. Pobiera z GitHub REST przebieg wskazany w zdarzeniu i potwierdza dokładny
   workflow `CI`, push z tego repo do `main`, `completed/success` oraz dokładny SHA.
2. Wymaga sukcesu joba zbiorczego `Testy (PostgreSQL 18)`. Pominięty job
   dokumentacyjny nie wdraża, bo nie zmienia kodu uruchomieniowego.
3. Potwierdza, że SHA nadal jest bieżącym `main` przed rozpoczęciem sekwencji.
4. Odczytuje zakres tokenu projektowego Railway, ID projektu, środowiska i
   nazwy usług. Wymaga jednego serwisu `kuking.pl` albo pełnego zestawu
   `kuking.pl`, `worker`, `scheduler`, w tej kolejności.
5. Dla każdej usługi wywołuje `serviceInstanceDeployV2` z **tym samym**
   `commitSha` i czeka na `SUCCESS` przed następną. Porażka web zatrzymuje
   worker i scheduler. Po rozpoczęciu sekwencji przesunięcie `main` nie
   przerywa zestawu; następny commit ma własny przebieg bramki.
6. Przy ponowieniu workflow (`GITHUB_RUN_ATTEMPT > 1`) zatrzymuje się przed
   jakąkolwiek mutacją Railway. Jeśli odpowiedź mutacji zaginie albo nie zawiera
   ID, pierwsza próba kończy się komunikatem o niejednoznacznym wyniku.
   Ponowienie nie tworzy drugiego deploymentu.

7. **Rerun CI na tym samym SHA (#611 etap 8).** `workflow_run` `completed`
   przychodzi dla każdej próby przebiegu CI, więc zielony rerun po czerwonej
   pierwszej próbie uruchamia **nowy** przebieg bramki (`GITHUB_RUN_ATTEMPT=1`)
   i wdraża ten SHA — tego nie potrafi `deployment_status`/`deploy.yml`, bo rerun
   nie tworzy nowego wdrożenia Railway. GitHub dokumentuje jedynie, że typ
   `requested` przy rerunie nie występuje („The `requested` activity type does
   not occur when a workflow is re-run”,
   [Events that trigger workflows](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows));
   że `completed` przychodzi dla każdej próby, wynika z tego a contrario i
   **wymaga potwierdzenia na żywym rerunie** (krok właściciela niżej).
8. **Idempotencja i kolejność.** Zdarzenie starszej próby (`run_attempt` w
   zdarzeniu ≠ najnowsza) jest pomijane z powodem. Przed mutacją skrypt czyta
   listę wdrożeń usługi (`deployments`, pole `meta.commitHash`): SHA ze statusem
   `SUCCESS` → usługa pominięta (dwa zielone przebiegi = jedno wdrożenie, także
   wznowienie po częściowym wdrożeniu); SHA w toku → błąd bez mutacji; tylko
   `REMOVED`/`FAILED` → wdrożenie. Brak odczytu listy = błąd (fail-closed).
   SHA starszy niż bieżący `main` kończy się pominięciem (kod 0), nie czerwienią.
   Ręczny rerun samego workflow bramki (próba > 1) nadal jest odrzucany.

Railway dokumentuje [wdrożenie wskazanego SHA](https://docs.railway.com/integrations/api/manage-services)
i [odczyt stanu deploymentu](https://docs.railway.com/integrations/api/manage-deployments).
Token projektowy używa nagłówka `Project-Access-Token` i jest ograniczony do
jednego środowiska ([Public API](https://docs.railway.com/integrations/api)).

## Przed przełączeniem przez właściciela

1. Potwierdź w panelu Railway, które serwisy production są połączone z
   `woogitsu/kuking.pl`, ich ID, ID projektu i środowiska oraz rzeczywiste
   ustawienia autodeploy i `Wait for CI`. Konfiguracja `.railway/railway.ts`
   nie dowodzi stanu zastosowanego w panelu.
2. Po scaleniu workflow ustaw sekret `RAILWAY_TOKEN_PRODUCTION` jako token
   projektowy środowiska production oraz zmienne repozytorium:
   `RAILWAY_PRODUCTION_PROJECT_ID`, `RAILWAY_PRODUCTION_ENVIRONMENT_ID`,
   `RAILWAY_PRODUCTION_SERVICES_JSON`. Przykład jednej usługi:

   ```json
   [{"name":"kuking.pl","id":"<ID serwisu>"}]
   ```

   Po rozdzieleniu wpisz pełny zestaw w kolejności `kuking.pl`, `worker`,
   `scheduler`. ID muszą pochodzić z bieżącego panelu, nie z dokumentacji.
3. Zweryfikuj testy `python3 -m unittest discover -s scripts -p
   test_railway_ci_gated_deploy.py -v`, zielony PR i zielony nowy `main`.
   Bez tokenu i odczytu panelu nie da się potwierdzić połączenia API na żywo.
4. Przed włączeniem potwierdź na żywo dwie rzeczy, których repo nie dowiedzie:
   (a) po ręcznym „Re-run all jobs” zielonego CI na `main` powstaje nowy
   przebieg `Railway deploy after CI` (a nie tylko nowa próba CI);
   (b) zapytanie `deployments(input: {serviceId, environmentId})` zwraca
   `meta.commitHash` dla wdrożeń z GitHub i z `serviceInstanceDeployV2`. Gdyby
   pole miało inną nazwę, bramka zawsze uzna SHA za niewdrożony (ryzyko dubla);
   popraw `deployment_state` przed włączeniem.
5. W wybranym oknie wyłącz autodeploy GitHub dla wszystkich usług aplikacji
   production, potem ustaw `KUKING_CI_GATED_RAILWAY_DEPLOY=true` i uruchom
   kontrolowany push ze zwykłym zielonym CI. Sprawdź w logu bramki dokładny
   SHA, ID deploymentów, wyniki Railway i SHA strony w produkcji. Nie włączaj
   bramki przy nadal działającym autodeploy, bo spowoduje podwójne wdrożenia.

Zmiana panelu, sekretu i zmiennej włączenia wymaga decyzji właściciela po
przejrzeniu konkretnego PR i planu testowego. Workflow nie wyłącza autodeploy
samodzielnie.

## Awaria lub wycofanie

Ustaw `KUKING_CI_GATED_RAILWAY_DEPLOY=false`, sprawdź, czy nie trwa już
uruchomiony job bramki, i dopiero wtedy przywróć autodeploy w panelu Railway.
Przed kolejnym scaleniem potwierdź zielone CI, końcowy deployment i SHA strony.
Jeżeli wdrożenie zatrzyma się między rolami, nie uruchamiaj kolejnej mutacji
w ciemno: sprawdź stan każdego ID deploymentu z logu oraz migracje web.
Przy zerwanym połączeniu sprawdź w Railway deploymenty wszystkich skonfigurowanych
usług w danym środowisku, ich czas, SHA i stan. Rerun workflow celowo kończy
się odmową nawet gdy poprzednia próba mogła zakończyć się przed mutacją;
automatyczne uzgodnienie nie jest dostępne bez wiarygodnego, udokumentowanego
identyfikatora tej konkretnej operacji w metadanych Railway. Po ręcznym
uzgodnieniu wykonaj osobny plan naprawczy dla brakujących ról, uwzględniając
stan migracji i aktualny `main`.
