# Hasło, konto i instrukcje pomocy — #810, #812, #816, #817, #818

Zakres: poprawki na gałęzi `gpt/haslo-konto`, baza
`534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`. Pomiary lokalne z 20.09.2026;
nie są odbiorem produkcji.

## Zachowanie

- **#810:** zmiana hasła i wylogowanie innych urządzeń korzystają z istniejącego
  kontekstu `WierszFormularza`. Kontekst nadaje kontroler na podstawie operacji.
  Błąd, `aria-invalid`, opis pola i odnośnik z podsumowania należą do właściwego
  formularza. Hasła nie są odtwarzane. Nazwy przesyłanych pól zostają zgodne
  z dotychczasowym endpointem.
- **#812:** po niepoprawnym haśle wraca wyłącznie wybór `usun_tresci`.
  Hasło i potwierdzenie konsekwencji nie są kopiowane z tej gałęzi.
  Brak zaznaczenia nadal oznacza minimum; poprawne ponowienie realizuje
  zakres widoczny w formularzu. Brak hasła nadal obsługuje zwykła walidacja.
- **#816:** utrata telefonu i kodów oznacza brak samodzielnego logowania.
  Ekran zachęca do zachowania kodów, nie gwarantuje nieodwracalnej utraty
  konta ani odzyskania przez obsługę. Komenda operatora pozostaje poza UI.
- **#817:** błąd kodu zapasowego wskazuje `backup_code` i otwiera jego sekcję.
  Rada dotyczy innego niewykorzystanego kodu, nie telefonu. Błąd limitu
  wskazuje tę samą metodę. Limit nadal jest wspólny dla konta i obu metod.
  Kontroler zwraca błędy bez odkładania kodów w `old input`, także gdy
  kształt żądania jest błędny. Informację o metodzie niesie klucz błędu.
- **#818:** pomoc wskazuje istniejący panel „Wygląd” i „Rozmiar tekstu”,
  rozróżnia zapis w przeglądarce gościa od konta. Reset hasła odsyła do
  aktualnych wskazówek właściwego ekranu; brak poczty prowadzi do kontaktu.
  Nie obiecuje listu ani odzyskania konta. Nie dodaje nowego przycisku.

Nie zmieniono reguł sprawdzania hasła, tokenów resetu, autoryzacji ani
ujawniania istnienia konta. Nie ma zmian schematu bazy.

## Pomiar

Własne żądania POST/PUT → przekierowanie → GET i parsowanie DOM:
`HasloKontoKomunikatyTest`. Zestaw obejmuje oba kierunki izolacji błędów,
zaznaczony i niezaznaczony zakres przy pustym i błędnym haśle, ponowienie
usunięcia, błędny i wykorzystany kod zapasowy, TOTP, limit konta, brak
sekretów w sesji i polach oraz pomoc gościa/konta z pocztą `array`/`smtp`.
Test konfiguracji SMTP nie wysyła wiadomości.

Przed poprawką własny przebieg zastanego `SecuritySettingsTest`:
7 zaliczonych, 1 porażka — błąd nowego hasła po żądaniu wylogowania.
Nowe regresje potwierdziły utratę zaznaczonego zakresu, zapis kodu w sesji
oraz błędne instrukcje kodów i pomocy. Trzy warianty zakresu, które już
działały, pozostają kontrolą dodatnią.

Zastany niezacommitowany test w `SecuritySettingsTest` zachowano i wykonano
samodzielnie; jego autorstwo nie jest przypisywane tej sesji.

Kontrole ujemne wykonuje `scripts/kontrola-ujemna.sh` na odizolowanej kopii
runtime, z kontrolą dodatnią, potwierdzeniem zmiany bajtów, oczekiwanej
przyczyny porażki i przywróceniem MD5 oraz mtime. Wyniki znajdują się
w `output/haslo-konto/`.

### Wyniki końcowe

- `vendor/bin/pint`: PASS, 1155 plików.
- `npm run build`: PASS, 72 pary kontrastu, 20 testów JavaScript i kompilacja Vite.
- Szeroki przebieg: 4397 zaliczonych, 4 porażki (83685 asercji).
  Cztery porażki wynikały z oczekiwań dotychczasowych identyfikatorów pól
  i klucza błędu kodu zapasowego w testach. Oczekiwania dostosowano do
  rozdzielenia formularzy; nie uznano tych porażek za zastane.
- Końcowy przebieg wszystkich dotkniętych klas i sąsiednich zabezpieczeń:
  **112 testów, 4356 asercji, PASS**. Obejmuje każdą z czterech wcześniejszych
  porażek. Całego szerokiego zestawu po aktualizacji tych oczekiwań nie
  uruchamiano ponownie. Nowa klasa zawiera 19 przypadków.
- `ProbaOdtworzeniaTest` pominięto zgodnie z instrukcją floty: używa wspólnej
  bazy odtwarzania. Nie wykonywano go na tej bazie.
- Siedem kontroli ujemnych: **PASS → FAIL z właściwej przyczyny → PASS**.
  Surowe JSON-y narzędzia mają pole `przywrocenie: nie wykonane`, ponieważ
  zapisują się przed końcowym trapem. Końcowe porównanie MD5 i mtime
  potwierdza zachowany `kontrole-ujemne.txt`; nie poprawiano ręcznie JSON-ów.
- Chromium, lokalna strona `/pomoc`, gość: szerokość 320 px bez poziomego
  przepełnienia; otwarcie panelu Enter, przejście Tab do rozmiaru i zapisu,
  wybór strzałką, zapis Enter, zachowanie 125% po odświeżeniu.
- Rzeczywisty zoom Chromium 200% (`chrome.tabs.getZoom() = 2`), viewport
  640 px: `innerWidth = 320`, `scrollWidth = 320`, `devicePixelRatio = 2`.
  Link do resetu działa przez Enter i prowadzi do lokalnego ekranu bez
  wysyłki poczty, z widocznym kontaktem. Zrzuty: `pomoc-320-wyglad.png`,
  `pomoc-zoom200.png`, `reset-320.png` w `output/haslo-konto/`.

Wszystkie liczby powyżej pochodzą z własnych uruchomień. Zalogowaną pomoc
oraz konfigurację SMTP sprawdzono testami HTTP, nie osobnym oglądem
przeglądarkowym. Nie sprawdzano dostarczenia rzeczywistej wiadomości.

## Granice i wycofanie

Nie uruchamiano produkcyjnych operacji, nie wysyłano wiadomości ani zmian
do GitHuba. Lokalna baza: `kuking_flota_gpt-haslo-konto`, właściciel
`kuking`, adres `127.0.0.1:55439`. Runtime powstał z katalogu `gpt-haslo-konto`:
nazwa `haslo-konto` z pierwotnej instrukcji wskazywała nieistniejący katalog.

Wycofanie: odwrócenie commita aplikacji i testów, bez migracji i bez
zmiany danych. Przywróci również opisane usterki.

Decyzja właściciela jest potrzebna dopiero dla publicznej obietnicy pomocy
w odzyskaniu konta po utracie obu składników: wymaga określenia rzeczywistej
procedury i sposobu potwierdzenia tożsamości. Ta poprawka takiej obietnicy
nie wprowadza i nie zależy od jej podjęcia.
