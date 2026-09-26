## D-002 · Testy na PostgreSQL, nigdy na SQLite

**Data:** wrzesień 2026 · Status: **obowiązuje**

Schemat używa indeksów częściowych, `num_nonnulls()`, `gen_random_uuid()`,
`pg_trgm` i `unaccent`. Test na SQLite przechodziłby, nic nie sprawdzając —
a to jest gorsze niż brak testu, bo daje fałszywe poczucie bezpieczeństwa.

**Zmiana wymaga:** rezygnacji z tych mechanizmów w schemacie. Czyli: nie.

📄 `TESTING.md` · `phpunit.xml`
