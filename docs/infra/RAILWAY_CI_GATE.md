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
4. W wybranym oknie wyłącz autodeploy GitHub dla wszystkich usług aplikacji
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
