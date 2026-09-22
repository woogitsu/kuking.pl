# Odbiór materiału #603 / #604

20 września 2026. Zakres: raport, surowe wyniki i odtwarzalny lokalny
przyrząd. Bez zmian kodu aplikacji, schematu, konfiguracji produkcyjnej
ani zależności. Punkt odniesienia: `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.

## Kontrole wykonane samodzielnie

- Przed instrumentacją: `UmowaKolejkiTest`, 4 zaliczone, 8 asercji.
- Dwa kompletne pomiary opisane w
  [raporcie decyzji](REDIS_HA_DECYZJE_603_604.md); zachowano także wolniejszy
  przebieg, bez wybierania wygodniejszych wyników.
- `npm run build` przed pomiarem HTTP; każde z 60 żądań obu serii
  miało status 200 i niepustą treść. Pierwsza próba wykryła brak manifestu
  Vite; po buildzie uruchomiono całą serię ponownie.
- Kontrola dodatnia miernika: odczytał zapytania do danych, sesji, cache,
  blokad i kolejki; 6 przetworzonych zdjęć osiągnęło `ready`, GC usunęło
  2000 przygotowanych sesji, każdy rozmiar `jobs` dał 600 próbek.
  Asercje sprawdzają sprawność przyrządu, nie wybierają progów produktu.
- Strażnik bazy: poprawne połączenie wskazało własną bazę i port 55439;
  podmieniona nazwa bazy zakończyła się kodem 2 przed próbą pracy.
- Pint: **PASS, 1156 plików** (`php vendor/bin/pint --test`).
  Zapis: `evidence/redis-ha/pint.txt`; wyniki strażnika w `guard-*.txt`.
- Pełny domyślny zestaw PHPUnit: **4391 zaliczonych, 2 porażki kontroli
  dokumentów, 83 692 asercje, 437,99 s**. Obie przyczyny poprawiono i
  ponowiono całą klasę `DokumentyMdNieMajaMartwychOdnosnikowTest`:
  **3 zaliczone, 49 asercji, 1,09 s**. Pełnego zestawu po tych zmianach
  dokumentacyjnych nie powtarzano; nie opisujemy tego jako jednego
  bezbłędnego pełnego przebiegu. Dowód: `evidence/redis-ha/tests-full.txt`.
  Jawnie pominięto `ProbaOdtworzeniaTest`, zgodnie ze zgodą właściciela:
  test używa wspólnej bazy próby. Grupa `dwa-polaczenia` pozostaje wyłączona
  przez repozytoryjny `phpunit.xml`; nie uruchamiano osobnej próby tej grupy.

## Błąd własnego uruchomienia, nie uznany za zastaną usterkę

Pierwszy pełny przebieg dał 864 porażki i 3529 zaliczonych testów
(74 974 asercje, 400,48 s). Skrypt przyrządu przeniósł `APP_ENV=local`
z pomiaru do PHPUnit; wpis `APP_ENV=testing` bez `force` w `phpunit.xml`
nie zastępuje eksportowanej zmiennej. W wynikach były m.in. odpowiedzi
419 dla poprawnych testów formularza zmiany adresu.

Poprawiono wyłącznie własny `scripts/infra603/verify.sh`: usunięto
`APP_ENV` ze środowiska przed testami, jawnie wskazano `APP_BASE_PATH`.
Po ponownej synchronizacji runtime samodzielnie uruchomiona klasa
`ZmianaAdresuEmailTest` przeszła: **27 testów, 149 asercji, 5,17 s**.
Następnie ponowiono cały zestaw. Pierwszego przebiegu nie traktujemy jako
pomiaru regresji projektu ani nie ukrywamy go jako „problemu innych gałęzi”.
W drugim pełnym przebiegu dwie porażki dotyczyły nowych dokumentów:
odnośnik do pliku odbioru wyprzedził jego skopiowanie do runtime, a skaner
tras portalu uznał ścieżkę sondy Patroni za trasę Kuking. Zsynchronizowano
kompletny pakiet i opisano zewnętrzną sondę zwykłym tekstem z kontekstem
Patroni. Nie osłabiano skanera ani jego asercji. Cała klasa kontrolna
przeszła po tych korektach.

Nazwy porażek i podsumowanie zachowano w
[evidence/redis-ha/tests-first.txt](evidence/redis-ha/tests-first.txt),
bez treści odpowiedzi i danych fixture.

## Jak sprawdzić ponownie

Po każdym edytowaniu przyrządu zsynchronizować runtime (Git Bash):

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-redis-ha
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /home/mateusz/flota/gpt-redis-ha-run/scripts/infra603/verify.sh
```

Nie uruchamiać obok `run.sh`: obie komendy korzystają z tej samej własnej
bazy. Synchronizacja usuwa wyniki runtime nieobecne w worktree; najpierw
zachować potrzebne dowody. Pliki w `evidence/redis-ha` zawierają wartości
liczbowe i konfigurację bez wartości sekretów; próbnik nie zapisuje SQL,
bindings ani danych ludzi.

## Granica odbioru

Zalecenie i progi wymagające decyzji są w głównym raporcie. Nie zmierzono
produkcji na poziomie klas SQL ani `pg_database_size`, nie wyznaczono
produkcyjnego QPS nasycenia i nie uruchomiono HA/Redisa. Dlatego **nie ma
podstaw do zamknięcia #603 / #604 jako wdrożonych lub w pełni zmierzonych**.
Koszty przyszłych usług są scenariuszami na zweryfikowanych stawkach,
z jawnymi założeniami RAM/CPU i rozmiarem dysku usługi jako przybliżeniem.

Nie wykonano push ani PR; nie zmieniono Railway, nie wysłano komentarzy
ani wiadomości. Nie wykonywano `git worktree prune`, resetu ani stash.
Wycofanie pakietu nie wymaga migracji ani zmiany konfiguracji portalu.
