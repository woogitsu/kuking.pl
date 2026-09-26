## D-004 · Wyszukiwarka na PostgreSQL, bez Scout i bez osobnego silnika

**Data:** wrzesień 2026 · Status: **obowiązuje**

`pg_trgm` + `unaccent` radzą sobie z literówkami i brakiem polskich znaków
lepiej niż stemming, którego dla polskiego w Postgresie po prostu nie ma.
„zurek" znajduje „żurek".

**Zmiana wymaga:** przekroczenia SLA wyszukiwania przy realnym ruchu.

📄 `ARCHITECTURE.md` · `app/Domain/Search/SearchQuery.php`
