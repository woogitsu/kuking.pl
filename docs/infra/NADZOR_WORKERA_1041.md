# Nadzorca kolejki: błąd procesu nie jest recyklingiem (#1041)

`docker/entrypoint.sh` zachowuje kod wyjścia każdego przebiegu kolejki.
Planowy recykling wymaga **obu** warunków: kodu 0 oraz osiągnięcia
`NADZOR_MIN_CZAS` (domyślnie 30 sekund). Kod niezerowy po dowolnym czasie,
a także podejrzanie szybkie wyjście z kodem 0, zwiększa licznik awarii.
Prawidłowy recykling zeruje ten licznik.

Po kolejnych błędach przerwy nadal rosną: 2, 4, 8, 16 sekund przy domyślnym
limicie pięciu prób. Piąta awaria kończy nadzorcę kodem 1, bez następnej
przerwy. Log podaje proces, kod wyjścia, czas życia oraz numer awarii.

## Rola `all`

Obserwowane są trzy długowieczne procesy: WWW, **nadzorca** kolejki i pętla
harmonogramu. Nie pojedynczy `queue:work`: jego poprawny recykling nie kończy
nadzorcy ani serwisu. Zakończenie dowolnej usługi jest zauważane przy kolejnym
sprawdzeniu PID-ów (co sekundę), odczytywane przez `wait`, a następnie kończy
kontener kodem 1 przez istniejącą funkcję `shutdown`. Niezerowy status nie
omija sprzątania przez `set -e`.

Nie zmieniono konfiguracji Railway ani żadnego działającego procesu.
Zastosowanie poprawki wymaga normalnego przetestowanego wdrożenia. Polityka
restartu platformy nadal jest oddzielnym ustawieniem — test lokalny nie
dowodzi, że platforma wznowi kontener.

## Weryfikacja i ograniczenia

`bash tests/skrypty/entrypoint-nadzor.sh` wykonuje funkcję i blok `all`
wyciągnięte z rzeczywistego entrypointu. Oprócz dotychczasowych przypadków
sprawdza błędy 23 po 31 sekundach czasu symulowanego, dokładny limit prób,
przerwy 2/4 sekundy oraz log. Dla `all` osobno kończy każdą z trzech usług
i wymaga kodu 1 oraz zakończonego sprzątania. Atrapy nie uruchamiają PHP,
serwera HTTP ani bazy. To test sterowania procesami, nie odbiór produkcji.

**Rollback:** wycofanie zmian tego zgłoszenia w entrypoincie i teście,
ponowny standardowy proces wdrożenia. Brak migracji i zmian danych.
Wycofanie przywraca ryzyko niewidocznych powtarzających się awarii kolejki.
