-- Szczyt połączeń PostgreSQL — próbka do wklejenia w psql (issue #598).
--
-- Liczy dokładnie to, co `App\Domain\Polaczenia\StanPolaczenBazy`: tylko
-- `client backend`, na CAŁYM serwerze. Procesy wewnętrzne (autovacuum,
-- walwriter, checkpointer) nie zajmują miejsc z `max_connections`.
--
-- Wyłącznie odczyt. Po wklejeniu w psql dopisz w nowej linii:  \watch 1
-- (próbka co sekundę; Ctrl+C kończy). Instrukcja: docs/DATABASE.md §598 G.
SELECT
    to_char(clock_timestamp(), 'HH24:MI:SS') AS godzina,
    count(*) FILTER (WHERE backend_type = 'client backend') AS zajete_serwer,
    count(*) FILTER (WHERE backend_type = 'client backend' AND state = 'active') AS aktywne,
    count(*) FILTER (WHERE backend_type = 'client backend' AND state = 'idle') AS bezczynne,
    count(*) FILTER (WHERE backend_type = 'client backend' AND state LIKE 'idle in transaction%') AS w_transakcji,
    (SELECT setting::int FROM pg_settings WHERE name = 'max_connections') AS max_connections
FROM pg_stat_activity
