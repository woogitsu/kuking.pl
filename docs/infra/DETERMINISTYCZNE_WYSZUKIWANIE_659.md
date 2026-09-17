# Deterministyczne dane testu panelu — #659

CI main 35279390921 na `84186922f2c9c5bb91d2fd9f352a86eb9b8cf935`
zakończyło pełne PHP wynikiem 3982 poprawnych testów i jednej porażki:
`PanelUzytkownicyTest::test_wyszukiwanie_naprawde_zaweza_wynik`.
Test oczekiwał wykluczenia Zenona Nowaka po wpisaniu „kowalska”.

Scena określała nazwę i username, ale losowała adres e-mail. Kontroler
przeszukuje także e-mail, więc założenie wykluczenia nie było deterministyczne.
Rzeczywisty Faker z lokalizacją `pl_PL`, seed 654, losowanie 538 zwrócił
`kowalska.oskar@example.net`. Taki syntetyczny adres jest kontrprzykładem:
Zenon z tym adresem powinien zostać znaleziony. Faktycznego adresu i ziarna
feralnego CI nie zachowano; nie przypisujemy mu tego konkretnego rekordu.

Poprawka nadaje obu osobom jawne adresy `.test`. Zachowuje wszystkie
asercje i sąsiedni test wyszukiwania po e-mailu oraz username. Nie zmienia
kodu aplikacji, filtrów, danych produkcyjnych ani wersji interfejsu.

Odczyt zmian przez roota: zakres odpowiada diagnozie, brak osłabienia
asercji. `git diff --check` przeszedł.

Kontrole wykonano na osobnym archiwum źródeł z poprawionym testem,
w `/home/mateusz/kuking-659-fixture-tests`, na nowej bazie
`kuking_659_fixture_tests` (`127.0.0.1:55439`, UTC). Odczyt efektywnej
konfiguracji potwierdził `testing`, mailer `array`, Faker `pl_PL`
oraz klasę kontrolera z własnego runtime. Nie używano baz innych przebiegów.

- Cały `PanelUzytkownicyTest`: 25 testów, 101 asercji — PASS.
- Fizycznie wyłączono warunek filtrowania frazy w kopii kontrolera.
  Sam test zawężania wyniku zakończył się oczekiwaną porażką: Zenon Nowak
  nadal występował w odpowiedzi (linia 138 testu).
- Kontroler przywrócono z kopii spoza repo; MD5 i czas modyfikacji
  w nanosekundach przed/po były identyczne. Ponowny cały plik:
  25 testów, 101 asercji — PASS.
- Pint `--test` dla zmienionego pliku — PASS.

Surowe logi, `receipt.json` z MD5/mtime oraz `effective-config.json`
zapisano w kanonicznym `output/659-fixture/`. Nie uruchamiano pełnej suity;
zwykły hook i CI pozostają oddzielnym etapem dostarczenia.
