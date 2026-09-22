# Dodatkowa próba hipotezy JIT — warunki przed wykonaniem

Po rampie i restarcie przeciążonej własnej instancji wykonano diagnostykę
istniejącym `scripts/pomiar-feedu.php` z kopią poza repo: zmieniono wyłącznie
ścieżki bootstrapu dla obrazu aplikacji oraz strażnika na własną bazę #605.
Nie uruchamiano wariantów IN/EXISTS/JOIN. Sesyjne PGOPTIONS on/off/on dało
438,49 / 84,76 / 410,27 ms paginate. To pomiar bez HTTP, nie pojemność.

Teraz porównujemy mieszany ruch 30 żądań/s przez 120 s: kolejno JIT on, off.
Każda seria startuje z nowego własnego kontenera, z tego samego obrazu,
2 CPU / 1 GiB, topologii all, tego samego pliku środowiska i woluminu.
Jedyna różnica konfiguracji: `PGOPTIONS=-c jit=on` albo `-c jit=off`.
Nie wykonujemy ALTER SYSTEM, ALTER DATABASE ani zmiany cudzego połączenia.

Obowiązuje ta sama bramka 18 rdzeni / PSI 25 / 60 s / budżet 300 s.
Ten sam korpus, sesje i adresy; pseudo-losowanie generatora ma seed 605:
`state=(Math.imul(state,1664525)+1013904223)>>>0`, wynik `state/4294967296`.
To nadal istniejący generator, z deterministycznym Math.random przez preload.

Nie przebudowujemy danych między seriami. Zostają zapisy poprzednich prób;
liczniki mediów zapisujemy przed i po. To ograniczenie porównania ruchu
zapisującego, nie identyczny snapshot bazy. W ramach pary niczego w aplikacji
ani SQL nie poprawiamy. Po parze kontener wraca do oryginalnego środowiska
bez PGOPTIONS. Kolejny kontrolny odczyt sprawdzi dostępność.

Próbnik ma już poprawkę zmiany PID: brak pełnego okna tego samego procesu
jest `null`, nie ujemnym CPU ani zerem. Ta poprawka nie zmienia generatora,
reguł bramki, pomiaru CPU kontenera ani zachowania aplikacji.
Po skażeniu pierwszej próby JIT on i przekroczeniu progów podczas JIT off
zaplanowano jedną powtórkę niezaliczonych wariantów, od nowego kontenera,
bez podnoszenia progów. Maksymalnie dwie próby wariantu. Brak czystej pary
oznacza brak pomiaru wpływu JIT na pojemność; wyników skażonych nie wybieramy
na podstawie atrakcyjniejszych czasów.