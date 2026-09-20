# Ustawienia profilu — #803, #802, #804, #801

Stanowisko `gpt/ustawienia-profilu`, baza kodu
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`, 20 września 2026.
Wszystkie opisane niżej przebiegi wykonano lokalnie. Nie badano produkcji.

## #803 — formularz pamięta zdjęcie

Potwierdzenie usunięcia przesyła `avatar_media_id` z GET formularza przez
slot istniejącego `confirm-button`. Kontroler nadal autoryzuje aktualny
profil zalogowanej osoby. Identyfikator z formularza jest wyłącznie
warunkiem zgodności; nie służy do wyszukiwania dowolnego cudzego zdjęcia.
Brak pola, tablica lub inne ID nie powodują kasowania. Istniejący
`PrzypnijAwatar::odepnij()` nadal wykonuje atomowy warunkowy UPDATE.

Przeszukano formularze ustawień i akcje aplikacji na tej bazie kodu:
nie znaleziono gotowego mechanizmu przekazującego oczekiwany awatar.
Wykorzystano istniejące odpięcie zamiast tworzyć drugie.

Pomiar przed poprawką: 15 istniejących testów zdjęcia przechodziło.
Nowy test GET zdjęcia A → podmiana na B → POST pól starego formularza
oblał asercję przypięcia B: w bazie było NULL. Próba bez ID także oblała.
Świeży formularz usuwał poprawnie. Po poprawce: 3 testy / 73 asercje,
z kontrolą profilu, wierszy oraz oryginałów i wariantów obu zdjęć.
Istniejące 8 testów `CzteryDrogiZdjeciaPodBlokadaTest` / 30 asercji przeszło.
To sekwencja HTTP oraz test starego modelu domenowego, nie pomiar dwóch
równoległych połączeń PostgreSQL.

## #802 — wspólna odpowiedź dla zdjęcia, opisu i przycisku

Skrót w ustawieniach profilu używa `Profile::zdjecieDoPokazania()`, tak jak
awatar. Stan przygotowania korzysta z istniejącej metody profilu.
Nie zmieniono bramki serwowania zdjęć ani bezpieczeństwa oryginałów.

Pomiar własny: przed poprawką 3 z 6 przypadków oblewały, po poprawce
6 przeszło / 32 asercje. Test renderuje konkretną sekcję ustawień,
tworzy plik WebP wariantu i rozróżnia pending z podglądem, ready,
pending bez wariantu, ready bez wariantu, deleted oraz brak zdjęcia.

Osobno odtworzono `ready` z zachowanymi metadanymi i brakującymi plikami:
test oblał się na obietnicy „Twoje zdjęcie widać”. Opis mówi teraz o
możliwości zmiany lub usunięcia zdjęcia, bez zapewnienia o dostarczeniu
bajtów. Brak wariantu nie daje też obietnicy, że zadanie nadal pracuje:
tekst kieruje do ustawień zdjęcia. Ostatecznie 7 testów / 40 asercji.
Istniejąca metoda rozpoznaje wariant po metadanych, bez odczytu magazynu;
nie dodano kosztu sprawdzania pliku do każdego awatara. Odzyskiwanie
utraconych plików nie jest częścią tej zmiany.

## #804 — niepotwierdzony zapis zatrzymuje oczekujące przejście

Decyzja właściciela w tej sesji: pozostać na stronie, pokazać błąd,
pozwolić przejść po ponownym kliknięciu linku. Przenoszenie komunikatu
na docelową stronę wymagałoby dodatkowego mechanizmu między dokumentami;
nie zostało wybrane.

`save()` zwraca wynik. Oczekujący link przechodzi tylko po sukcesie.
Porażka otwiera panel, przywraca lokalny podgląd i mówi o braku
potwierdzenia, ponieważ utrata odpowiedzi nie dowodzi cofnięcia zapisu
na serwerze. Nie wysyłamy automatycznej kompensacji starego ustawienia.
Istniejący limit oczekiwania wynosi 10 sekund.

Pomiar Chromium wykonuje pełny moduł produkcyjny na kontrolowanym DOM:
sukces, HTTP 500, offline, rzeczywisty timeout i błędny JSON.
Przed poprawką sukces przeszedł, cztery błędy powodowały przejście do celu.
Po poprawce wszystkie pięć przeszło. Sprawdzono widoczny komunikat,
przywrócenie skali, odblokowanie kontrolki i ponowne przejście linkiem.

Dodatkowy pomiar własny: lokalny Laravel, PostgreSQL `127.0.0.1:55439`,
baza `kuking_flota_gpt-ustawienia-profilu`, prawdziwe logowanie przez
formularz i zbudowane assety. Przechwycenie odpowiedzi nastąpiło dopiero
po wykonaniu POST na backendzie (200), potem przerwano jej dostarczenie
do strony. Bieżący dokument pozostał na ustawieniach ze skalą 100 i
widocznym komunikatem. Ponowne kliknięcie otworzyło profil ze skalą 125
odczytaną z konta. Potwierdza to rozdzielenie lokalnego podglądu od zapisu
serwera. [Zrzut sprawdzony wzrokowo](evidence/ustawienia-profilu/804-brak-potwierdzenia.png).

## #801 — instrukcja dotykowa wymaga odbioru na telefonie

Tekst awaryjny wskazuje przytrzymanie adresu palcem i polecenie „Kopiuj”
z menu zaznaczenia, zachowując Ctrl+C / Cmd+C. Nie twierdzi, że aplikacja
sama otworzyła menu systemowe. Pole i mechanizm kopiowania są bez zmian.

Pomiar własny wykonuje rzeczywisty handler wycięty z `app.js`, z kontrolą
granic wycinka i adapterami schowka: udany schowek, udana stara metoda,
odmowa obu. Przed poprawką 2 przeszły, instrukcja dotykowa oblała;
po poprawce wszystkie 3 przeszły.

**Nie wykonano pomiaru na fizycznym Androidzie ani iPhonie, ani odbioru
TalkBack / VoiceOver. #801 nie jest gotowe do zamknięcia.** Tekst jest
kandydatem do odbioru; emulacja dotyku nie zastępuje menu systemowego.

## Kontrole i wycofanie

Dla każdego z czterech zgłoszeń wykonano kontrolę ujemną przez
`scripts/kontrola-ujemna.sh`: PASS → celowa zmiana → oczekiwana porażka →
PASS. Przywrócenie porównało MD5 i mtime. Dla Blade przed każdym przebiegiem
czyszczono skompilowane widoki: przywrócenie starego mtime może pozostawić
w pamięci podręcznej widok z mutacją.
Wyniki przyrządu: [803](evidence/ustawienia-profilu/803.json),
[802](evidence/ustawienia-profilu/802.json),
[804](evidence/ustawienia-profilu/804.json),
[801](evidence/ustawienia-profilu/801.json).
Uwaga o formacie przyrządu: pole `przywrocenie` w JSON jest zapisywane
przed końcowym `trap` i pozostaje „nie wykonane”. Końcowe wyjście przyrządu
potwierdziło porównanie MD5 i mtime; `kontrola_dodatnia_po_przywroceniu`
we wszystkich czterech plikach wynosi PASS. Nie podmieniono tego pola
ręcznie w dowodach.

Testy JS: `npm run test:ustawienia-profilu`; podłączone do zadania assetów
CI po instalacji Chromium. Testy PHP są częścią zwykłego zestawu.
Pint przeszedł na 1157 plikach; `npm run build` przeszedł.

Pełny zestaw PHP: **4402 testy / 83797 asercji**, 436,71 s. Pominięto
`ProbaOdtworzeniaTest` zgodnie z instrukcją właściciela (wspólna baza
testu odtwarzania). Ten przebieg poprzedza ostatnie doprecyzowanie tekstu
#802; po nim wykonano ponownie cały test tej sekcji i kontrolę ujemną.

Brak zmian schematu i migracji. Wycofanie przez `git revert` odpowiedniego
commita. Cofnięcie #803 przywróci możliwość usunięcia nowszego zdjęcia
starym formularzem, więc nie jest zalecanym sposobem naprawiania problemów
wdrożenia. Stare otwarte formularze bez ID celowo odmawiają usunięcia.

Nie wykonano push, PR, wdrożenia ani zmiany produkcyjnych danych.
