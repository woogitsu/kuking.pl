# Panel kontaktu: odbiór lokalny #844, #846, #845, #843, #839, #840

Data: 20 września 2026. Gałąź `gpt/kontakt-panel`, baza `534e0a51`.
Pomiary wykonane w tej sesji na PostgreSQL `127.0.0.1:55439`, baza
`kuking_flota_gpt-kontakt-panel`. Zero rzeczywistych listów: `Mail::fake`,
`Http::fake` z zakazem nieprzechwyconych żądań albo transport `array`.

Commity własne: `71df45f3` (weryfikacja i doprecyzowanie #844), `2a3bcf8c`
(naprawa przyrządu) oraz `3076d6d3` (pozostałe naprawy panelu i testy).
Przejęty commit: `aae8ca8e`.

## Co przejąłem

- Commit `aae8ca8e`: migrację #844, dokumentację, zastępczy podpis operatora
  i trzy testy. Nie uznałem samej obecności testów za dowód. Sam wykonałem
  kontrolę dodatnią, odtworzyłem stary CHECK, zobaczyłem PostgreSQL 23514
  przy usunięciu operatora, przywróciłem migrację i zobaczyłem zieleń.
- Niezatwierdzony test `EdycjaNotatkiZamknietejWiadomosciTest.php`.
  Początkowo oblewał na 403, bo operatorzy nie mieli 2FA. Poprawiłem fixture;
  dopiero wtedy zobaczyłem właściwą czerwień: edycja notatki zmieniała autora
  załatwienia. Następnie poprawiłem zachowanie i rozszerzyłem testy.
- Wzorzec `e89f28a5` odczytałem w repozytorium kanonicznym. Zastosowałem
  atomowy znacznik i zapis audytu oraz dokończenie po awarii. To odczyt wzorca,
  nie przejęty pomiar jego testów.

Nie opieram odbioru na cudzych wynikach testów. Opisy i komentarze zgłoszeń
posłużyły do ustalenia zakresu; opisane niżej pomiary wykonałem sam.

## Wyniki końcowe

- Pełny przebieg: **4404 testy, 83 608 asercji**, 345,93 s, kod 0.
  Następnie dopisałem dwa przypadki i poprawiłem widoczność przycisku nowej
  odpowiedzi po użyciu starego formularza. Ta ostatnia poprawka miała
  własną czerwień: brak tekstu „Wyślij jako nową odpowiedź”.
- Po ostatniej poprawce: **59 testów obszaru, 334 asercje**, kod 0.
  Obejmuje też pewną odmowę: powtórzony klucz nie ponawia transportu,
  a świadoma próba z nowym kluczem nadal działa.
- Wyścig dwóch procesów ponowiony na końcu: **1 test, 9 asercji**, kod 0.
- `vendor/bin/pint` na zmienionych plikach oraz końcowy
  `vendor/bin/pint --test`: **1165 plików**, kod 0.
- `npm run build`: **20 testów Node, 72 pary kontrastu**, Vite zbudował assety.
- Chromium: rzeczywiste logowanie i 2FA, dwa kierunki zachowania szkiców,
  dwie karty, świadome uzgodnienie konfliktu, nowy list po użytym formularzu,
  klawiatura przy 320 px. Tekst pola co najmniej 18 px, przycisk co najmniej
  48 px, brak przewijania w bok. Osobna karta z `chrome.tabs.setZoom(2)`:
  potwierdzone `innerWidth=320` i `devicePixelRatio=2`, zapis szkicu działa,
  nadal bez przewijania w bok. Zrzuty: `kontakt-panel-320.png` i
  `kontakt-panel-zoom200.png`.
- `git diff --check`: kod 0. `zgodnosc-zrodel.json` potwierdza własnym
  odczytem MD5 zgodność mutowanych źródeł runtime z drzewem roboczym.

Skrót pełnego przebiegu jest w `pelny-przebieg.txt`; pełne wyjście tekstowe
zachowano lokalnie w `%TEMP%\kuking-kontakt-panel-pelny-przebieg-20260920.txt`.

## Zachowanie i dowody

| Zgłoszenie | Wynik | Własny dowód |
|---|---|---|
| #844 | Fizyczne usunięcie operatora zachowuje wiadomość, stan i datę; usuwa powiązanie. Anonimizacja zachowuje istniejący wiersz operatora. | 6 testów, stary CHECK powoduje 23514; test odmowy rollbacku i kontroli dodatniej. Właściciel zatwierdził zachowanie w tej rozmowie. |
| #843 | Edycja notatki i zapis tego samego stanu nie zmieniają daty ani autora obsługi. Ponowne otwarcie i zamknięcie nadal zmieniają metrykę. | Czerwień przed poprawką, kontrola ujemna oraz pomiar retencji wiadomości starszej niż 12 miesięcy. |
| #846 | Wersja formularza jest sprawdzana pod blokadą wiersza. Konflikt pokazuje bieżące ustalenia i zachowuje szkic do świadomego uzgodnienia. | HTTP i dwie karty Chromium; usunięcie porównania wersji zmienia chroniony stan i oblewa test. |
| #845 | Zapis stanu zachowuje szkic odpowiedzi; wysłanie listu zachowuje szkic notatki. Każdy przycisk wykonuje tylko swoją operację. | Oba przypadki czerwone przed poprawką. HTTP i rzeczywiste formularze; usunięcie zachowania pól oblewa również test przeglądarkowy. |
| #839 | Jeden klucz formularza daje jeden rekord i najwyżej jedną próbę wysyłki. Nowy klucz pozwala świadomie napisać kolejny list. | Powtórzony POST; dwa procesy PHP czekające na rzeczywistą blokadę PostgreSQL, różne PID-y, suma wywołań poczty = 1. |
| #840 | Brak wyniku połączenia pozostaje niepewnością. Dopiero strukturalna informacja o potwierdzonej odmowie daje `nieudana`. | Prawdziwy transport z atrapą HTTP rzucającą `ConnectionException`; stan w bazie, renderowane zdania i zachowany tekst. Bezwarunkowe oznaczenie odmowy oblewa test. |

Pierwszy zestaw nowych prób HTTP dla #846/#845/#839/#840 dał pięć porażek
przed implementacją. Dodatkowa próba HTTP z awarią zapisu wyniku po wysyłce
dała 500; po poprawce wraca do formularza z oboma szkicami i ostrożnym
komunikatem. Nie udaje potwierdzonego niewysłania.

`AwarieOdpowiedziKontaktuTest` wstrzykuje rzeczywiste wyjątki przez
`DB::listen` po INSERT odpowiedzi, rezerwacji wysyłki, zapisie wyniku,
UPDATE znacznika audytu i INSERT audytu. Awaria transakcji cofa oba elementy
audytu; następny GET go dokańcza, bez ponownego listu. Usunięcie audytu przez
retencję nie odtwarza go z powrotem. Usunięcie transakcji w kontroli ujemnej
pozostawiło znacznik i oblało oba scenariusze awarii audytu.

Pliki `kontakt-*.json` zapisują kontrole PASS → FAIL → PASS. Narzędzie
sprawdzało przy przywracaniu zarówno MD5, jak i mtime. Pole `przywrocenie`
w tym starszym formacie JSON jest zapisywane przed końcowym `trap` i nadal
ma wartość „nie wykonane”; nie jest samodzielnym dowodem zakończenia trap.
Potwierdzenie powrotu pochodzi z jego wyniku oraz zielonej kontroli po
przywróceniu, nie z tego pola.

## Oprzyrządowanie

Sam odtworzyłem fałszywe odrzucenie trafienia przy 1 MiB wyjścia przez
`printf | grep -q` pod `pipefail`. Nowy test przed poprawką: kod 4 zamiast 0.
Po zmianie na here-string: 14/14 prób przyrządu przechodzi. Osobny commit
`2a3bcf8c`, bez zmian w cudzym stanowisku ani w skryptach wspólnej floty.

Próba przeglądarkowa najpierw zatrzymała się przed logowaniem: lokalny router
PHP był uruchamiany z niewłaściwego katalogu. Po ustawieniu katalogu `public`
rzeczywiste logowanie, 2FA i scenariusze panelu przeszły. Fixture tworzy losowe
hasło i sekret tylko w prywatnym pliku tymczasowym; usuwa swoje dane po próbie.

## Odtworzenie

Wspólny skrypt wymaga rzeczywistej nazwy katalogu `gpt-kontakt-panel`.
Argument `kontakt-panel` podany w zleceniu nie wskazuje istniejącego worktree.
Z Git Bash:

```bash
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/przygotuj-runtime.sh gpt-kontakt-panel
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/gpt-kontakt-panel/scripts/kontakt-kontrola-844.sh
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/gpt-kontakt-panel/scripts/kontakt-kontrole-ujemne.sh
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/gpt-kontakt-panel/scripts/kontakt-testy-pelne.sh
MSYS_NO_PATHCONV=1 wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-kontakt-panel --group=dwa-polaczenia tests/Dwa/OdpowiedzKontaktuNiePowielaSieTest.php
```

Nie uruchamiaj próby przeglądarkowej i testów niszczących fixture jednocześnie.
Po synchronizacji runtime ponownie zbuduj assety przed oglądem przeglądarki.

## Granice i wycofanie

Trzy migracje mają testy i opis w `docs/DATABASE.md`. Nie kasuj powiązań ani
kluczy po to, by wymusić rollback. Migracja #844 odmawia powrotu do starego
CHECK przy osieroconych metrykach; migracja odpowiedzi odmawia utraty użytych
kluczy. Wycofanie licznika wersji wymaga wyłączenia zapisu i unieważnienia
otwartych formularzy przed ponownym uruchomieniem.

Nie było pushu, PR, wdrożenia, oglądu produkcji ani prawdziwej wysyłki.
Nie uruchamiałem `ProbaOdtworzeniaTest`: wskazany w zleceniu wyjątek używa
wspólnej bazy. Nie uruchamiałem całej grupy obcych wyścigów na bazach innych
stanowisk; wykonałem własną próbę dwóch procesów. Nie obiecuję dokładnie
jednego doręczenia przez dostawcę. Niepewny wynik wymaga jego sprawdzenia
przed świadomym utworzeniem następnego listu.

Nie pozostaje decyzja produktowa: #844 został rozstrzygnięty przez właściciela.
