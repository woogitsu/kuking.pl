# Poczta konta i konflikt nazw — #888, #889, #890, #887

Stanowisko: `gpt/konto-poczta`, baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`.
Pomiary własne z 20 września 2026. Wyłącznie lokalny PostgreSQL
`127.0.0.1:55439`, baza `kuking_flota_gpt-konto-poczta`, właściciel `kuking`.
To nie jest odbiór produkcji ani dowód doręczenia poczty.

Commity aplikacji, w kolejności do kolejki:

- #888: `c4db01c3ff529a0a474954f5b1ef541a689d33d2`.
- #889: `ce394d8c8b2c7f7772fee2fc970aecf04b28617b`.
- #890: `9377de92c7432ab938585dcd62a30cf828456276`.
- #887: `c784730dc0c3733c91804994843b1d89e0c19657`.

## Pomiar przed poprawkami

- Nietknięte drzewo: `ZmianaAdresuEmailTest`, 27 testów, 149 asercji, PASS.
- #888: serializacja rzeczywistych `SendQueuedNotifications`, wykonanie
  potwierdzenia do transportu `ArrayTransport`, potwierdzenie zmiany w SQL,
  deserializacja i wykonanie ostrzeżenia. Przed potwierdzeniem odbiorca był
  poprawny; po potwierdzeniu (także po usunięciu profilu) był NOWY zamiast
  STAREGO. Regresja: 3 czerwone, 1 zielony. Trzecia czerwień dotyczyła
  zapewnienia „Na razie nic się nie zmieniło” w opóźnionym liście.
- #889: sześć czerwonych przypadków na niezmienionej klasie powiadomienia:
  wysyłka od razu i sekundę przed terminem z mylnym opisem ważności;
  wysyłka dokładnie w terminie i po nim; wysyłka zastąpionego linku po
  nowszym; starsze zadanie bez daty wysyłające po wygaśnięciu tokenu.
  Odbiornikiem był wyłącznie transport pamięciowy, tokeny zapisane w SQL
  nie były odnawiane. Konfiguracja zmieniona po zleceniu wpływała na treść.
- #890: dwa czerwone przypadki pełnego POST — ścisk na rzeczywistej
  blokadzie cache i wyczerpany budżet. Obie odpowiedzi miały brak
  `_old_input`, mimo poprawnego adresu i braku wysyłki.
- #887: dwa osobne procesy PHP, różne `pg_backend_pid()`, oba zatrzymane
  zdarzeniem `Profile::updating` PO walidacji wolnej nazwy. Zwolnienie
  pierwszej, a następnie drugiej bariery dało 302 i 500. Istniały oba
  indeksy UNIQUE, duplikat nie powstał, przegrywający profil pozostał
  niezmieniony, lecz odpowiedź nie miała błędu `username` ani starych pól.
  Wynik zapisano lokalnie (`output/konto-poczta/pomiar-887.json`); tego
  katalogu NIE MA w repozytorium, więc odsyłacz jest tylko opisem pomiaru,
  nie dowodem do odczytania. To pomiar prawdziwych
  żądań przez kernel HTTP, nie tylko próba bezpośredniego UPDATE.

## Zachowanie po zmianie

- #888: odbiorca ostrzeżenia jest utrwalany pod blokadą konta przy
  zamówieniu zmiany i przekazywany przez `Notification::route`. Nazwa
  wyświetlana też jest kopią; worker nie potrzebuje profilu. Nowy adres
  pozostaje zamaskowany. Treść opisuje prośbę, nie obiecuje bieżącego
  stanu konta. Wyjaśnia, że zmiana hasła nie cofa potwierdzonej zmiany adresu.
- #889: przed wysyłką powiadomienie sprawdza istniejący token, jego
  właściciela i termin w bazie oraz przekazaną datę. Starszy job z `null`
  korzysta z bazy. Brak aktualnego tokenu oznacza pominięcie listu —
  decyzja właściciela w tej sesji, dopisana do D-056. Nie ma nowego tokenu,
  wydłużenia TTL ani zastępczej wiadomości. Treść podaje czas ważności
  od zamówienia, wyliczony z dat konkretnego tokenu, nie z nowej konfiguracji.
- #890: odmowa rezerwacji odkłada wyłącznie e-mail. Dotychczasowe rozróżnienie
  komunikatów zostaje; Turnstile, limit po adresie i budżet nadal obowiązują.
  Test sprawdza sesję, pole przez DOM, brak wysyłki oraz udane ponowienie.
- #887: właściciel po otrzymaniu pomiaru 302/500 wyraźnie rozszerzył zgodę
  na naprawę obsługi konfliktu mimo działającego UNIQUE. Indeksy zostają.
  Zapis jest transakcyjny, a tylko konflikt `profiles_username_unique`
  lub `profiles_username_lower_unique` staje się błędem pola `username`.
  Inne ograniczenia nadal zgłaszają wyjątek, także gdy treść DETAIL zawiera
  mylącą nazwę indeksu. Ponowny pomiar dwóch procesów: 302/302, jeden zapis,
  bez duplikatu; pięć pól zachowane w sesji i w HTML po GET, przegrany profil
  niezmieniony. Odbiór zapisano lokalnie (`output/konto-poczta/odbior-887.json`,
  16 asercji) — również poza repozytorium. Dowodem, który da się tu
  uruchomić, jest `tests/Feature/KonfliktNazwyProfiluTest.php`.
  Test regresyjny sprawdza też oba indeksy, błąd przy polu i w podsumowaniu
  oraz zwykłą udaną zmianę.

## Weryfikacja

Pięć fizycznych kontroli ujemnych przez `scripts/kontrola-ujemna.sh`:
odbiorca #888, strażnik i treść #889, zachowanie adresu #890, konflikt #887.
Każda: PASS → rzeczywista zmiana MD5 → FAIL z właściwej przyczyny → PASS.
Skrypt potwierdził odtworzenie MD5 i mtime. Pole `przywrocenie` w zapisanych
JSON-ach ma wartość sprzed wykonania końcowego trap; końcowe komunikaty
procesów potwierdziły przywrócenie. JSON-y i powtarzalny `kontrole.sh`
zostały w lokalnym `output/konto-poczta/` i NIE weszły do repozytorium —
nie da się ich tu odczytać ani powtórzyć.

Pierwsze oczekiwanie tekstu w kontroli #887 nie pasowało do wyjścia PHPUnit;
przyrząd prawidłowo odmówił uznania dowodu. Po użyciu rzeczywistej diagnostyki
`SQLSTATE[23505]` z nazwą indeksu cała kontrola przeszła.

Pierwszy szeroki przebieg: 4403 PASS, 2 FAIL. Obie porażki odtworzono osobno
w `MartweZadaniaTest`; nie były zastane. Nowy strażnik #889 poprawnie odrzucił
fikcyjny tekst tokenu bez wiersza SQL, przez co test awarii transportu nie
dochodził do transportu. Fixture zakłada teraz rzeczywisty ważny token,
a atrapa nadal zgłasza awarię. Po zmianie wszystkie 10 testów tej klasy
przechodzi. Nie wyłączono żadnej z jej asercji.

Formatowanie: `vendor/bin/pint`, PASS (1161 plików, w tym lokalne sondy).
Końcowy szeroki przebieg po wszystkich poprawkach: **4410 PASS,
83 819 asercji, 525,15 s**, kod wyjścia 0. Pliki aplikacji i testów w runtime
porównano bajtowo z worktree (SHA-256, 12 zgodnych plików).
Wyniki tamtego przebiegu zostały wyłącznie lokalnie
(`output/konto-poczta/wynik-testow.txt`, `pelne-testy.txt`); w repozytorium
ich nie ma. Przebieg powtarzalny tutaj to `php artisan test`.
Jedynym świadomie wyłączonym testem szerokiego przebiegu jest
`ProbaOdtworzeniaTest`: zgodnie ze wskazanym przez właściciela wyjątkiem
korzysta ze wspólnej bazy `kuking_zrodlo_proby_glowny`
[pomiar cudzy: instrukcja właściciela tego zadania]. Nie odtwarzano tego
wyjątku ponownie na współdzielonej bazie.

Wszystkie powyższe wyniki zmierzono samodzielnie. Rozwiązanie z gałęzi
`gpt/haslo-konto` (commit `a3f15d79`) przeczytano jako wzorzec użycia
`Poczta::dziala()`, nie przejęto jego wyników testów jako własnych.

## Granice

`Poczta::dziala()` sprawdzono jako istniejącą kontrolę konfiguracji testowej.
Zbudowanie transportu nie jest połączeniem z dostawcą ani dowodem doręczenia.
Nie wywoływano komendy wysyłającej list testowy. Nie wysłano prawdziwej
poczty, wiadomości do ludzi, pushów ani PR-ów. Nie zmieniano produkcji.
Formularze sprawdzono żądaniami HTTP i analizą wyrenderowanego DOM;
nie wykonywano oglądu przeglądarki ani osobnego pomiaru wyglądu, ponieważ
zmiany nie dotyczą układu i rozmiarów istniejących komponentów.

Odczyt analogicznych klas (bez pomiaru doręczenia):
`UstawienieNowegoHasla` nadal buduje czas z konfiguracji brokera, a
`ZaproszenieDoZalozeniaKonta` nadal ignoruje przekazaną datę. Obie nie mają
`shouldSend`. To osobne cykle tokenów; nie zastosowano do nich automatycznie
rozwiązania logowania. Ich ewentualna poprawka wymaga osobnego zakresu.

Zmiana #888 chroni zadania utworzone nowym kodem. W starym payloadzie
z odbiorcą `User` nie zapisano historycznego adresu, więc nie da się go
wiarygodnie odtworzyć samą aktualizacją klasy. Przed wdrożeniem operator
powinien rozstrzygnąć obsługę już oczekujących starych ostrzeżeń; tutaj
nie odczytywano ani nie zmieniano kolejki produkcyjnej. Wprost: ostrzeżenie
zakolejkowane PRZED wdrożeniem, a wykonane PO potwierdzeniu zmiany, może
nadal trafić na NOWY adres — nowy kod tego nie naprawi wstecz.

Ślad nieudanego ostrzeżenia (`App\Poczta\ZapiszNieudanyList`, sprawdzone
w kodzie i testem `test_list_na_adres_zostawia_slad_bez_konta`): wiersz
w `mail_failures` powstaje jak dotąd, z `rodzaj = ZgloszonaZmianaAdresu`,
ale z `user_id = NULL`. Odbiorcą jest teraz `AnonymousNotifiable`, a
`ktoCzekal()` rozpoznaje wyłącznie `User` i niczego nie zgaduje.
`kuking:kto-nie-dostal-listu` pokaże „— (odbiorca spoza kont)". Właściciel
nie dowie się ze śladu, czyje to konto; adres zostaje w `failed_jobs`
(`php artisan queue:failed`). Tak samo działało to już wcześniej dla
`PotwierdzenieNowegoAdresu`, więc nie jest to nowa klasa luki.

Kontrola przed wysyłką #889 nie gwarantuje ważności przy czytaniu:
link może później wygasnąć albo zostać zastąpiony. Sprawdzenie przy wejściu
pozostaje bez zmian. Budżet nadal liczy zlecone próby według istniejącej
reguły; pominięcie starego joba nie zmienia licznika innego dnia.

Brak zmian schematu. Wycofanie: odwrócić lokalne commity aplikacji i testów,
bez migracji i operacji na danych użytkowników. Przywróci to opisane usterki.
Nie cofać samej klasy #888 przy oczekujących zadaniach nowego formatu:
stara klasa oczekuje modelu `User`, a nowe zadania zawierają jawny adres.
Operator musi skoordynować zatrzymanie workerów, obsługę zaległości
i wersję kodu. Nie wolno zamieniać zapamiętanego odbiorcy na bieżący adres.
