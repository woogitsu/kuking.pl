## D-331 — Pięć funkcji z listy „V2, ale nie teraz” odblokowanych (29 września 2026)

**Decyzja właściciela (29 września 2026).** Z listy „V2, ale nie teraz”
w `docs/FEATURES.md` (decyzja z 26 września) wolno budować:

- kilka jawnych zakresów czasu w wyszukiwarce przepisów (#1997);
- udostępnianie publicznego zeszytu (#2000);
- widoczne kalorie na porcję w danych SEO przepisu / JSON-LD (#1996);
- historię i porównanie publicznych wersji przepisu (#2024);
- opcjonalną synchronizację postępu gotowania między urządzeniami (#2016).

Pozostałe pozycje tej listy (#1902, #1903, #1904, #1906, #1999, #2067)
zostają zakazane bez nowej decyzji. Lista „Nie wcześnie” bez zmian.

**Granice, które zostają.** Każda z funkcji trzyma się zasad z `AGENTS.md`:
autoryzacja przez Policy, UX 50+, bez nowego stacku (zero Redisa, SPA,
osobnego search engine), zmiana schematu z rollbackiem (D-088). Udostępnianie
dotyczy tylko zeszytu publicznego — zeszyt rodzinny (D-302) i „Tylko ja” nie
dostają przycisku. Kalorie trafiają do JSON-LD tylko wtedy, gdy ta sama
wartość jest widoczna na stronie przepisu (D-299).

### Wycofanie
Przywrócenie pozycji na listę „V2, ale nie teraz” w `docs/FEATURES.md`;
wdrożone już funkcje wycofuje się osobno, każdą swoim PR-em.
