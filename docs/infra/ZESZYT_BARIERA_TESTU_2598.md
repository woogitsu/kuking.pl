# Bariera testu zapisu do zeszytu (#2598)

Przebieg integracyjny `36955965900` miał 333 zielone testy i jedną porażkę
`ZapisDoZeszytuBiezacyStanTest.php:257`: obserwator zobaczył PID blokujący
`2936`, choć właściwa bariera miała PID `2935`. Pierwszy odczyt obejmował
**dowolny** `wait_event_type = Lock` procesu, zanim test upewnił się, że
to blokada trzymana przez wskazaną barierę. To jest wynik przyrządu, nie
dowód, że zapis do zeszytu źle rozstrzygnął wyścig.

`waitFor` sprawdza teraz konkretny PID przez `pg_blocking_pids(pid)`.
Dotyczy to zarówno bariery zapisu, jak i zmiany, która ma czekać na proces
zapisujący. Nie zwiększono limitu sześciu sekund, nie pominięto żadnego
scenariusza domenowego i nie zmieniono kodu produkcyjnego.

Regresja `test_przyrzad_czeka_na_wlasciwa_bariere_po_obcym_locku` używa
trzech procesów PostgreSQL i dwóch kolejnych blokad doradczych. Pierwszy
proces trzyma obcą blokadę i czeka na sygnał w postaci zwolnienia osobnej
bramki. Próba czeka najpierw na ten obcy proces; test potwierdza jego PID,
zwalnia bramkę i dopiero potem wymaga oczekiwania na właściwą barierę.
Synchronizacja opiera się na obserwacji rzeczywistych blokad, bez `sleep`.
Kontrola ujemna `scripts/kontrola-negatywna-2598.py` przywraca dawne
zaakceptowanie pierwszego locka; test musi oblać się z markerem
`ZESZYT_2598_OBCY_LOCK_POMINIETY`, po odtworzeniu bajtów i mtime przejść.

Rollback: cofnięcie commitu przywraca dawną identyfikację pierwszej dowolnej
blokady i usuwa nową fixture oraz kontrolę ujemną. Nie dotyka to schematu,
danych ani zachowania zapisu do zeszytu.
