# Przyrząd do pilotów #815, #813, #814

To eksperyment lokalny, nie funkcja portalu. Wyniki i ograniczenia:
[`RAPORT.md`](../../docs/research/ai-pilots/RAPORT.md).
Nie uruchamia Laravelowego zapisu przepisów. Nie czyta `.env` aplikacji.
Domyślne testy nie łączą się z modelem; klucz nie jest do nich potrzebny.

## Powtórzenie bez dostawcy

W Git Bash na Windows, po każdej zmianie kodu najpierw przygotowanie:

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-ai-piloty
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-ai-piloty --filter AiPilot
```

Testy wymagają PostgreSQL. Helper wybiera izolowaną bazę na porcie 55439.
Nie uruchamiaj ich równolegle z innym pomiarem w tej samej bazie.
Kontrole ujemne, po przygotowaniu i zakończeniu zwykłych testów:

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /home/mateusz/flota/gpt-ai-piloty-run/scripts/ai-pilots/controls.sh
```

Pomiar rozmiarów, w WSL z przygotowanego runtime:

```bash
cd /home/mateusz/flota/gpt-ai-piloty-run
/opt/kuking-php-8.4-avif/bin/php scripts/ai-pilots/measure-inputs.php
```

Wynik JSON jest na stdout. Przekieruj go do nowego pliku we własnym katalogu
Windows, jeżeli ma przeżyć kolejne przygotowanie runtime. Zbiór publiczny
jest opcjonalny i ignorowany przez git. `collect-public.ps1` pobiera go
z publicznej strony wskazanej przez właściciela; wymaga sieci i nie kontaktuje
się z dostawcą AI. Nie pobiera zdjęć ani zewnętrznych blogów. Jego ponowne
wykonanie może pobrać inne wpisy — porównuj adresy i skróty źródeł w dowodach.

Pomiar SQL zapisuje JSON tylko przy ustawionym `KUKING_AI_SQL_OUTPUT`.
W WSL ustaw tę zmienną na pełną ścieżkę własnego pliku Windows i uruchom
helper `testuj.sh gpt-ai-piloty --filter AiPilotSearchBenchmarkTest`.
`summarize.py` czyta `docs/research/ai-pilots/evidence/sql-baseline.json`
i zapisuje obok podsumowanie oraz ocenę każdego pytania. Nie nadpisuj
zatwierdzonych dowodów nowym pomiarem bez jawnego oznaczenia daty i warunków.

## Późniejszy pomiar płatny — obecnie niewykonany

Wymaga `OPENAI_API_KEY` dostępnego w procesie WSL. Nie wpisuj wartości klucza
w argumentach polecenia, plikach repo ani rozmowie. Sprawdź aktualny cennik
i warunki danych. Przyrząd sam niczego nie uruchamia w tle.
W WSL, w przygotowanym runtime, przykład jednego przebiegu #815:

```bash
export KUKING_AI_PILOT_OUTPUT=/mnt/c/Users/matma/Documents/kuking-flota/gpt-ai-piloty/storage/ai-pilots
/opt/kuking-php-8.4-avif/bin/php scripts/ai-pilots/run.php --live --task=search --repeat=1
```

Pozostałe zadania: `--task=help`, `--task=recipe`; `--public` dotyczy wyłącznie
opisu przepisu. `--limit=N` ogranicza pierwsze N wejść; `--repeat=1..3`
umożliwia ocenę powtarzalności. Jeden przebieg wszystkich zbiorów to 265 prób;
dwa przekraczają domyślną kopertę 500 prób. Najpierw najwęższy pilot #815.

Używaj stale tego samego katalogu wyników. Plik `reservations` jest wspólnym
licznikiem prób tych uruchomień: nie kasuj go ani nie obchodź nowym katalogiem.
Każda próba, także timeout, zużywa jedną rezerwację. Limit nie jest blokadą
wydatków całego konta dostawcy. Przyrząd zatrzymuje się na odmowie dostępu,
braku środków/limicie oraz po trzech błędach transportu z rzędu.

JSONL zawiera identyfikator wejścia, rundę, skróty zbioru i kodu, liczbę żądań,
bajty, czas, błąd, `usage`, koszt oraz surową i zweryfikowaną propozycję.
Pliki mogą zawierać tekst źródłowy szkicu, pozostają poza gitem. Nie publikuj
ich bez przeglądu. Sam `code_sha256` obejmuje `Pilot.php`; do powtarzalności
zapisz także SHA całego commita i wersję środowiska.

Aby zmierzyć zapisaną propozycję #815 na testowych danych SQL, ustaw
`KUKING_AI_SEARCH_RESULTS` na JSONL i `KUKING_AI_SQL_OUTPUT` na nowy plik,
potem uruchom test pomiarowy. Test odczytuje odpowiedzi, nie wysyła HTTP.
Oddzielnie policz odrzucone odpowiedzi i brak odpowiedzi; nie usuwaj ich
z mianownika jakości modelu. Dla #813 oceń intencję względem zbioru, a dla
#814 ręcznie sens podziału, alternatywy, negacje i oszczędność pracy autora.
Poprawny JSON i zachowany tekst nie są dowodem użyteczności.

## Wycofanie

Usunięcie przyrządu, jego dwóch testów i dokumentacji nie wymaga migracji.
Nie ma flagi produkcyjnej do wyłączenia ani zapisanych szkiców do cofania.
Zachowaj dowody i lokalny licznik rezerwacji, jeżeli eksperyment ma być
kontynuowany. Zmiana modelu, limitów lub sposobu porządkowania to nowy wariant
pomiaru, nie podmiana już zapisanych wyników.
