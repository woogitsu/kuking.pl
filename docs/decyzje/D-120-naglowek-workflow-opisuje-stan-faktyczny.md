## D-120 · Nagłówek workflow opisuje stan faktyczny wyzwalaczy; wyłącznikiem wdrożeń jest bramka na jobie, nie blok `on:`

**Data:** 11 września 2026 · Status: **obowiązuje**

`deploy.yml`, `preview.yml` i `railway-iac.yml` miały identyczny nagłówek: „ten
workflow jest wyłączony z automatycznego uruchamiania […] żeby WŁĄCZYĆ, odkomentuj
blok `on:` poniżej". **Bloki były aktywne od pierwszego commita** — sprawdzone
w historii. Commit „Wyłączenie workflowów wdrożeniowych" bloków nie ruszył: wyłączył
joby bramką `KUKING_DEPLOY_ENABLED`.

Trzy dokumenty powtarzały to samo polecenie („odkomentuj blok `on:` w `ci.yml`"),
a `ci.yml` ma w nagłówku „CI JEST WŁĄCZONE".

**Nagłówek pliku wykonywalnego to nie jest miejsce na zamiar.** Ma opisywać, co ten
plik robi teraz.
