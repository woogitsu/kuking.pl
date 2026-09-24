# Zgoda na list i wspomnienia — #880 (oraz stan #881)

## Zakres i punkt wyjścia

Gałąź `gpt/wspomnienia-prywatnosc`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
Pomiary własne w izolowanym runtime WSL, PostgreSQL `127.0.0.1:55439`,
baza `kuking_flota_gpt-wspomnienia-prywatnosc`, właściciel `kuking`.
Nie wykonano zmian produkcyjnych, wysyłki poczty, pushowania ani PR.

## #880 — konflikt stanu, bez pierwszeństwa którejkolwiek drogi

Na kodzie przed poprawką istniejący `DowodZgodyNaDigestTest` przeszedł:
19 testów, 78 asercji. Nowa regresja pokazała 4 porażki i 1 kontrolę dodatnią:
stary formularz przywracał zgodę po wypisaniu odnośnikiem i formularzem,
wyłączał nowszą zgodę oraz przywracał nowsze wyłączenie wspomnień.
To własny pomiar HTTP i aktualnej bazy, nie przejęty wniosek ze zgłoszenia.

Formularz przesyła dwa stany początkowe. Akcja `UpdatePrivacySettings`
odczytuje konto pod `FOR UPDATE`, porównuje oba stany i przy rozbieżności
odrzuca cały zapis. Nie powstaje wtedy nowy dowód zgody. Wszystkie drogi
`PrzestawZgodeNaDigest` odczytują bieżący stan pod tą samą blokadą.
Pozostają: dziennik append-only, atomowe udzielenie oraz wypisanie mimo
awarii zapisu dowodu. Nie ma migracji ani nowych zależności.

Po błędzie wybory i stany początkowe pozostają w formularzu. Odnośnik
„Otwórz aktualne ustawienia” pozwala odczytać bieżący stan i wybrać ponownie.
Stary formularz bez stanów początkowych także jest odrzucany; nie dostaje
automatycznie nowych stanów pod stare zaznaczenia. Kontrola porównuje stan,
nie historię wszystkich zmian: cykl A→B→A kończący się stanem początkowym
nie jest konfliktem. Testy HTTP wykonują żądania kolejno. Osobny pomiar
dwóch procesów PHP potwierdził blokowanie w PostgreSQL (opis poniżej).

## #881 — flaga Poradźcie (już na main)

Filtr `enabledKinds()` w `Wspomnienia::dlaOsoby()` i jego regresja
(`QuestionMemoryGateTest`: wyłączone pytanie nie jest wybierane ani
renderowane, własny prywatny wpis zostaje, `hide_as_memory` działa) weszły
na main wcześniej (#1171). Ta gałąź nie dokłada już własnej kopii testu —
dwa testy tego samego zachowania rozjeżdżają się przy pierwszej zmianie.

**Decyzja właściciela pozostaje otwarta:** czy przy włączonym Poradźcie
pytania mają być wspomnieniami? Wariant A: pozostawić dotychczasowy wybór
wszystkich włączonych rodzajów. Wariant B: przypominać tylko gotowanie.
Żaden test nie utrwala wariantu A ani B.

## #882 i #879 — poza tą gałęzią

Pierwotnie gałąź zmieniała też potwierdzenie układu zdjęć na „Układ zdjęć
zapisany.” (#882) i zdanie po zdjęciu blokady (#879). Na main weszło #1454,
w którym potwierdzenie zdjęć mówi prawdę o odbiorcach wpisu
(`PostMediaController::ktoZobaczy()`), więc zmiana z tej gałęzi była z nim
sprzeczna i została usunięta razem z testem. Zmiana zdania o blokadzie
(#879, zamknięte, zdanie z main ma strażnika
`OdblokowanieNieWznawiaObserwowaniaTest`) również wypadła — nie należy
do zakresu #880/#881.

## Pomiary końcowe — własne, 20 września 2026

### Dwa połączenia PostgreSQL

Każda próba utworzyła osobne konto testowe. Proces nadrzędny rozpoczął
transakcję, wykonał zmianę zgody i pozostawił transakcję otwartą. Drugi
proces uruchomił akcję na drugim połączeniu. Przed zatwierdzeniem pierwszej
transakcji odczyt `pg_stat_activity` potwierdził `wait_event_type = Lock`,
różne PID-y i PID rodzica w `pg_blocking_pids`. Dopiero wtedy nastąpił COMMIT.

| Pierwsza akcja | Druga akcja | PID rodzica / dziecka | Wynik drugiej / zgoda końcowa |
|---|---|---|---|
| Wypisanie odnośnikiem | Stary formularz z zaznaczoną zgodą | 1730987 / 1731123 | konflikt / false |
| Wypisanie formularzem | Stary formularz z zaznaczoną zgodą | 1730987 / 1731273 | konflikt / false |
| Udzielenie zgody formularzem | Wypisanie ze starym modelem konta | 1730987 / 1731291 | wypisano / false |

To pomiar współbieżności akcji domenowych, nie dwóch serwerów HTTP.
Regresja HTTP dodatkowo sprawdza odwrotny konflikt: stary formularz
z odznaczoną zgodą nie wyłącza nowszego zapisu do listu.

### Macierz wspomnień

Własny prywatny wpis, wspomnienia włączone, zegar 20.09.2026 w południe,
wpis sprzed dokładnie roku. Wynik wyboru (pomiar, bez asercji rozstrzygającej
produktowo włączone pytania):

| Rodzaj | Poradźcie wyłączone | Poradźcie włączone |
|---|---|---|
| Danie | wybrane | wybrane |
| Pytanie | pominięte | wybrane |

### Regresje i narzędzia

- Końcowy pełny przebieg: **4413 poprawnych, 83 961 asercji, 337,23 s**,
  kod zakończenia 0. Filtr pomija wyłącznie `ProbaOdtworzeniaTest`.
- Testy celowane przed ostatnim rozszerzeniem: 91 poprawnych, 513 asercji.
- Cztery kontrole ujemne przez `scripts/kontrola-ujemna.sh` (pomiar sprzed
  zawężenia zakresu; dziś w gałęzi zostaje z nich ochrona konfliktu):
  usunięcie ochrony konfliktu, filtra rodzaju, prawdziwego komunikatu zdjęć
  oraz objaśnienia odblokowania. Każda: PASS → FAIL z właściwej przyczyny → PASS;
  skrypt potwierdził przywrócenie MD5 i mtime źródła.
- Pierwszy pełny przebieg: 4412 poprawnych, 1 porażka. Porażka
  `KomunikatWyjatkuNieWchodziSurowyDoDziennikaTest` została odtworzona osobno:
  stary mock rzucał wyjątek z KAŻDEJ transakcji, także nowej blokady konta.
  Symulacja została ograniczona do tworzenia wpisu zgody; dodano odczyt
  faktycznie wycofanej zgody z bazy. Klasa: 2 poprawne, 17 asercji.
  Dodatkowa kontrola ujemna wstawiła surowy komunikat wyjątku do logowania:
  PASS → FAIL na testowym adresie e-mail → PASS po przywróceniu.
- Pint wykonany; PHPStan zmienionych sześciu plików aplikacji bez błędów.
- `npm run build`: poprawne, w tym 20 testów JavaScript i 72 pary kontrastu.
- `git diff --check`: poprawne.

### Przeglądarka lokalna

Chromium, prawdziwe logowanie formularzem, dwie karty ustawień. Wypisanie
w drugiej karcie, następnie wyłączenie wspomnień w starej pierwszej karcie:
widoczny konflikt, zgoda w formularzu nadal zaznaczona, wspomnienia
odznaczone. Kliknięcie „Otwórz aktualne ustawienia” pokazało rzeczywisty
stan: list wyłączony, wspomnienia włączone (konflikt nie zapisał połowy).

Przy 320 × 900 px `scrollWidth = innerWidth = 320`, komunikat przy polu
18 px, przycisk „Zapisz” 50,5 px wysokości. Obejrzano zrzut
`output/playwright/privacy-conflict-320.png` (lokalny artefakt ignorowany
przez Git). Nie wykonano osobnej próby rzeczywistego zoomu przeglądarki 200%.

## Wycofanie i granice

Wycofanie kodu nie wymaga operacji na bazie, ale cofnięcie #880 przywraca
ryzyko nadpisania nowszej decyzji — preferowana jest poprawka do przodu.
Nie wykonano oglądu zalogowanej produkcji ani badania z użytkownikami.
Nie uruchomiono `ProbaOdtworzeniaTest`: zgodnie z jawnym wyjątkiem zadania
używa wspólnej bazy `kuking_zrodlo_proby_glowny`. Pozostałe pomiary wykonano
samodzielnie; zgłoszenia służyły jako hipotezy, nie przejęte wyniki testów.
