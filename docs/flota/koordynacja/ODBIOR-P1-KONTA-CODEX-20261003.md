# Odbiór poprawek P1 kont — 3 października 2026

Osobna gałąź `codex/paczka-bezpieczenstwo-20261003` obejmuje M odebraną
w C oraz cztery poprawki istniejących issues: #2851, #2854, #2861, #2862.
Nie zawiera N, O, P ani Q. Wąski zakres pozwala wydać zabezpieczenia przed
dalszymi dużymi paczkami. Nie jest jeszcze wypchnięta ani wydana.

## Reguła i dowód

Zmiana hasła, wylogowanie innych urządzeń, potwierdzenie i wyłączenie 2FA
ponownie sprawdzają świeży hash oraz pierwotną generację uwierzytelnionej
sesji pod istniejącą blokadą `ZamekKonta`. Spóźnione żądanie nie może
nadpisać późniejszego resetu, zmienić 2FA ani odnowić odwołanej sesji.
Zapis i odwołanie pozostałych poświadczeń pozostają jedną operacją.
Odmowa wylogowuje wyłącznie bieżącą starą sesję i prowadzi do logowania.

- #2851/#2854: rzeczywiste dwa procesy i żądania HTTP kernela, również
  reset do identycznego hasła. Dosłowne cookie A sprzed resetu nie otwiera
  ustawień. Root na własnym Linux/PG18: **6 / 192 PASS**, po jednej fizycznej
  mutacji każdej ścieżki **3 właściwe FAIL + 3 PASS**, dokładny restore
  i **6 / 192 PASS**. Wspólne klasy funkcjonalne: **28 / 119 PASS**,
  pełny PHPStan zero błędów. Zawarto znaną poprawkę typów Livewire z M,
  bez ignorowania błędów lub zmian wersji zależności.
- #2861/#2862: autor na własnym PG18: **9 / 311 PASS**, dwa mutanty po
  **3 właściwe FAIL + 3 PASS**, dokładne przywrócenie i PASS.
  Cookie B pochodzi z rzeczywistego logowania, ewentualnego wyzwania 2FA
  i GET ustawień 200. Po odmowie A to samo literalne cookie nadal daje
  GET 200, bez kolejnego logowania. Test nie zastępuje sesji B pustym
  rekordem w tabeli. Niezależny przegląd końcowego `386937d7a`: ACCEPT.
- Świadome wyłączenie niepotwierdzonej 2FA zachowuje wcześniejszą rotację
  innych poświadczeń, bez fikcyjnego audytu wyłączenia. Bieżąca poprawna
  sesja pozostaje ważna, a błędne hasło i spóźniona generacja nic nie zapisują.

To żądania HTTP w oddzielnych procesach aplikacji, nie pomiar przeglądarki
ani TCP. Nie dodano migracji, nowych zależności, usługi lub kosztu.

## Pozostałe bramki i wycofanie

Root odświeża własne izolowane worktree Linux, z dokładnym lockiem, kluczem
i bazami PG18 na `127.0.0.1:55488`, rola `kuking_pg18_owner`:
`kuking_test_security_20261003` i `kuking_race_repo_security`.
Po odbiorze wspólnego heada: zwykły push z niezmienionym hakiem, niedraftowy
PR do C, pełne terminalne CI i merge z `expectedHeadSha`.

Rollback kodu przywróciłby znane okna spóźnionych zapisów. Wymaga wstrzymania
czterech formularzy zmian bezpieczeństwa do czasu naprawionego wdrożenia;
nie cofa się danych, haseł, generacji ani zapisanych decyzji użytkowników.

Przed zamknięciem issues: końcowy zielony PR do main wraz z CodeQL,
terminalnie zielony CI push, SUCCESS web/workera/harmonogramu dokładnego
SHA i zgodne `/wydanie` oraz `/health` 200. #2025 i czynności właściciela
z #2713 zachowują swoje osobne kryteria.
