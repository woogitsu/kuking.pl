# Pomiar czasu portu marki w PR #2446

W przebiegu CI `36908054419`, job `110524036820` (`Port marki — rodziny
ekranów, zoom i kreator`, część 1/2) zakończył się anulowaniem po limicie
25 minut. `PORT_GRUPA=rozszerzenia-1` rozpoczęła się o 18:38:30 UTC.
Ostatni komunikat pomiaru, `K513_OK 96 konfiguracji`, pojawił się o 18:47:09
UTC. Po nim log nie wskazuje, który z kolejnych etapów był wykonywany.
To nie jest dowód, że przyczyną był test podpowiedzi, konkretny wariant
przeglądarki albo mutacja CSS.

Następny zwykły przebieg wypisze `PORT_START` i `PORT_END` z czasem
monotonicznym dla ośmiu etapów części 1. Test podpowiedzi wypisze
`NOTICE_START`, postęp po każdym z 24 wariantów oraz początek i koniec
każdej z pięciu mutacji. Ostatni znacznik bez odpowiadającego końca
zawęzi etap przerwany przez limit. Znaczniki zawierają tylko nazwę etapu,
licznik i czas; nie zawierają danych fixture ani treści strony.

Instrumentacja nie zmienia limitu czasu ani kryteriów testów. Wynik
następnego przebiegu trzeba odczytać przed decyzją o podziale lub
optymalizacji pomiaru.
