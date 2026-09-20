# PWA #278 i fundament Web Push #35 — pomiar 20.09.2026

Gałąź `gpt/pwa-push`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
Przed zmianą kodu drzewo było czyste. Wszystkie pomiary niżej wykonano
w tym zadaniu, chyba że oznaczono je wyraźnie inaczej.

## Nietknięty kod PWA

- 25 testów PHP dotyczących PWA: **243 asercje, zielone**.
- 5 testów rzeczywistego modułu JavaScript: zielone.
- #278 i `docs/design/INSTALACJA_PWA_278.md` opisują istniejącą implementację.
  Nie znaleziono w mierzonym zakresie potrzeby jej przepisywania.
- Odczyt #35 i kodu: brak kanału Web Push oraz osobistej strefy na koncie.
  Nie mylono globalnego `kuking.strefa` ze strefą odbiorcy mieszkającego
  poza Polską. Odczyt #746 służył wskazaniu tej granicy; widoków z #746
  nie zmieniano.

## Własny pomiar prawdziwej przeglądarki

Chrome for Testing **151.0.7922.34**, binarium Chromium-1234 z lokalnego
Playwrighta, WSL, profil trwały. Lokalny Laravel pod `127.0.0.1:8878`.
Osobna baza `kuking_flota_gpt-pwa-push`, właściciel `kuking`, PostgreSQL
`127.0.0.1:55439`. Logowanie przez rzeczywisty formularz; konto pomiarowe
utworzone wyłącznie w tej bazie. Żadnych zapisów do produkcji.

| Próba | Wynik |
|---|---|
| Bezpieczny kontekst, zwykły profil, odświeżenie | `Page.getInstallabilityErrors`: pusta lista. Rzeczywiste `beforeinstallprompt`, `isTrusted=true`, `platforms=[web]`, `prompt` jest funkcją |
| Gość | Brak panelu mimo dostępnego sygnału przeglądarki |
| Pierwsza zalogowana wizyta | Brak panelu mimo dostępnego sygnału przeglądarki |
| Powrót | Po ustawieniu **wyłącznie lokalnej fixture** poprzedniej wizyty na dwa dni wcześniej i nawigacji HTML pojawia się zwykła karta instalacji |
| Rzeczywisty brak instalowalności | Nowy kontekst incognito z sesją tego konta: `in-incognito`, przez 5 sekund zero `beforeinstallprompt`, komponent istnieje, ale `hidden=true` |
| 320/360/390/414/768/1440 px × jasny/ciemny × tekst 100/140% | 24/24: brak poziomego przewijania, wszystkie przyciski mieszczą się w szerokości; tekst minimum 18 px, wysokość przycisku minimum 50,5 px |
| Klawiatura 320 px, ciemny, 140% | Tab po „Dodaj przepis” prowadzi kolejno do „Zainstaluj aplikację” i „Nie teraz”; Enter zamyka |
| Trwałe zamknięcie | Własny odczyt bazy: `dismissed`. Po odświeżeniu brak komponentu |

Panel w tej macierzy został wyzwolony **rzeczywistym zdarzeniem Chromium**,
nie syntetycznym `dispatchEvent`. Zrzut obejrzano:
[320 px, ciemny motyw, tekst 140%](evidence/pwa278/2026-09-20-320-dark-140.png).

Nie wykonano natywnej instalacji, testu fizycznego Androida/iOS ani
rzeczywistego zoomu karty 200%. To nie jest pełne zamknięcie #278.
Historyczne pomiary tych obszarów nie zostały przepisane jako własne.

## Fundament #35

Decyzja właściciela w tej sesji: maksymalnie jeden zbiorczy push dziennie.
Stan implementacji i brakujące etapy: [WEB_PUSH_35.md](../product/WEB_PUSH_35.md).

- Przed implementacją nowe 15 przypadków testowych było czerwonych
  z powodu braku klas i migracji. To pomiar braku funkcji, nie mutacja.
- Po implementacji i rozszerzeniu: 18 przypadków, 53 asercje, zielone.
  Obejmują granice 21:00/08:00, lato i zimę, obie zmiany czasu, Tokio,
  Nowy Jork, zmianę strefy, lokalną dobę, niezależność kont, zamknięcie
  i skasowanie konta oraz rollback pustej i wykorzystanej tabeli.
- Dodatkowa czerwień podczas pracy: skasowane konto ujawniło `TypeError`
  callbacka; po obsłudze `null` ten sam test przeszedł.
- `scripts/push-budget-race.php`: trzy **różne** identyfikatory połączeń
  PostgreSQL, dwaj uczestnicy zmierzeni jako oczekujący na blokadę,
  po zwolnieniu wyniki `[false,true]`, dokładnie jeden wiersz rezerwacji.
  Własne konto i jego rezerwacja usunięte po próbie.

Pomiar wyścigu uruchamia się po przygotowaniu runtime i migracji, z jawnym
połączeniem do własnej bazy floty, przez `php scripts/push-budget-race.php`.
Skrypt odmawia pracy poza lokalną bazą `kuking_flota_*` na porcie 55439.
Nie wykonuje migracji, nie zrzuca bazy i sprząta wyłącznie własne konto.

## Ograniczenia i decyzje

- Brak pushowania, PR-a, zmiany produkcji i wysyłki wiadomości.
- Web Push nieaktywny. To fundament limitu i obliczania ciszy, nie gotowy
  etap 1 ani kanał. Nocne powiadomienia w aplikacji pozostają nietknięte;
  trwałą kolejkę odroczeń i preferencje trzeba dobudować przed transportem.
- Do decyzji właściciela pozostaje godzina zbiorczej wysyłki: pierwszy
  dozwolony moment daje mniejsze opóźnienie, stała późniejsza pora zbiera
  więcej zdarzeń kosztem oczekiwania. Limit jednej próby już wybrano.
- Pełny zestaw pomija wyłącznie `ProbaOdtworzeniaTest`, zgodnie z jawnym
  wyjątkiem użytkownika dotyczącym współdzielonej bazy odtworzeniowej.

## Końcowa weryfikacja własna

- Pełny zestaw PostgreSQL: **4411 testów, 83 747 asercji, zielone**
  (468,77 s). Jedyny pominięty test: `ProbaOdtworzeniaTest`, zgodnie
  z wyjątkiem opisanym wyżej.
- Kontrole ujemne rzeczywistego kodu: wyłączenie ciszy oblało 6 przypadków;
  obejście granicy doby oblało test zmiany strefy; usunięcie klucza głównego
  oblało test drugiej rezerwacji. Każdą mutację cofnięto, zachowując
  oryginalne bajty i czas modyfikacji. Po ponownym przygotowaniu runtime:
  **18 testów / 53 asercje zielone**.
- PHPStan: bez błędów. `npm run build`: sukces, w tym 20 testów JavaScript
  i 72 pary kontrastu.
- Uruchomiono Pint dla nowych plików PHP; końcowy `vendor/bin/pint --test`:
  **1160 plików, bez uwag**. `git diff --check`: bez uwag.
- Lokalny serwer pomiarowy i sesję przeglądarki zamknięto.