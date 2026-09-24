# Równoległe degradacje administratorów — #1016, etap A

## Zakres

Naprawa istniejącej odmowy z D-039, bez zmiany kryterium `role=admin AND
status=active`, 2FA, statusów kont, usuwania danych i retencji audytu.
Dotyczy komendy `kuking:nadaj-role` i jej nazwanej akcji `ChangeUserRole`.
`User::promoteTo()` pozostaje niskopoziomową metodą zapisu; nowa droga
wykonywania polecenia musi używać akcji, nie omijać jej przez tę metodę.

## Kolejność

Pytanie operatora odbywa się przed transakcją. Następnie akcja rozpoczyna
transakcję, zdobywa PostgreSQL `pg_advisory_xact_lock(1016, 1)`, odczytuje
świeże konto pod `FOR UPDATE` i ponownie sprawdza status, rolę oraz innych
czynnych administratorów. Zapis roli i `user.role_changed` należą do tej
samej transakcji; awaria audytu wycofuje rolę. Metadane pozostają `from`,
`to`, `source=console:kuking:nadaj-role`, bez aktora i IP powłoki.

Akcja nie może być wywoływana po zdobyciu blokad kont ani innych zasobów.
Wejście spod `ZamekKonta` jest jawnie odrzucane. Adapter konsolowy nie
otwiera transakcji przed wywołaniem akcji. Blokada jest wspólna również dla
awansów; blokady dwóch różnych kont nie zastępują wspólnej serializacji.
Nie jest to ochrona przed ręcznym SQL-em. Zmianę statusu obejmuje etap B.

## Własny pomiar lokalny, 21.09.2026

Baza źródłowa kodu: `65327e69ddc3423279c7324ff20639ead64c6610`.
PostgreSQL `127.0.0.1:55439`, właściciel `kuking`, osobne bazy:
`kuking_flota_codex_1016` i `kuking_race_kat_codex_1016_run_f722269b`.
Runtime ma skopiowane zależności, bez dowiązań.

- Przed poprawką rzeczywiste dwa polecenia pozostawiły **0 zamiast 1**
  czynnych administratorów. Oba procesy ukończyły operację; to nie timeout.
- `Tests\Dwa\OstatniAdministratorTest`: zatwierdzony fixture, dwa procesy
  uruchamiające komendę, kontrola PID i konfliktu z klasy bazowej. Bariera
  po rzeczywistym zapytaniu o innych administratorów oraz obserwacja
  `pg_stat_activity` deterministycznie ustawiają przeplot. Po naprawie:
  jedna zmiana, jedna odmowa, jeden administrator i jeden wpis audytu.
  Stabilność: **20 kolejnych zielonych przebiegów**, po 12 asercji każdy.
- `NadanieRoliTest` oraz `AtomowaZmianaRoliTest`: 16 testów / 47 asercji.
  Obejmują awans, odmowę, nieczynne zastępstwo, anulowanie, brak zmiany,
  awarię audytu, odczyt po pytaniu, świeże metadata i kolejność blokad.
- Pint: pięć zmienionych plików PHP bez uwag. PHPStan: komenda oraz
  nowa akcja domenowa bez błędów; nie jest to wynik analizy całego projektu.
- Kontrole przez `scripts/kontrola-ujemna.sh`: usunięcie wspólnego zamka
  odtwarza **0 administratorów**; zdjęcie transakcji zostawia rolę po
  awarii audytu; zastąpienie świeżego odczytu starym modelem pozwala
  wykonać zmianę na podstawie roli sprzed pytania. Każda kontrola ma
  PASS → oczekiwany FAIL → PASS oraz sprawdzenie odtworzenia MD5 i mtime.

Test sekwencyjny nie dowodzi współbieżności; od tego jest grupa Dwa.
Test dwóch procesów dowodzi opisanego przeplotu, nie wszystkich możliwych
wyścigów. Nie wykonano pełnej suity, CI, publikacji ani testów produkcji.
Te czynności pozostają po stronie sesji koordynującej publikację.

## Etap B — zmiana statusu

Invariant: po zatwierdzeniu każdej zmiany istnieje co najmniej jedno konto
`role=admin AND status=active`. Pilnuje go `App\Domain\Users\OstatniAdministrator`:

- `zablokuj()` — `pg_advisory_xact_lock(1016, 1)`, tylko w transakcji
  i nigdy spod `ZamekKonta` (kolejność: zamek wspólny → wiersz konta);
- `jestJedynym()` — odczyt roli i statusu z bazy, nie z modelu;
- `odbierzAktywnosc()` — zamek, `FOR UPDATE` wiersza, sprawdzenie, zapis.

Przez `odbierzAktywnosc()` idą `User::suspend()`, `User::ban()`
i `User::markForDeletion()`, więc każda obecna i przyszła ścieżka (panel
moderacji, formularz usunięcia konta, komendy) dziedziczy ochronę.
`ChangeUserRole` używa tego samego zamka i tego samego sprawdzenia.
`markDataErased()` nie jest objęte: wychodzi wyłącznie z `pending_delete`,
czyli z konta już nieczynnego. Awans i przywrócenie konta nie zmniejszają
liczby administratorów i działają także przy zerze administratorów.

Odmowa to `OdmowaOstatniegoAdministratora`. Panel moderacji bierze zamek
na początku transakcji decyzji (INSERT do `moderation_actions` trzyma
`FOR KEY SHARE` na wierszu osoby; zamek wzięty po nim zakleszczyłby się ze
zmianą roli) i zamienia odmowę na błąd pola `action`: transakcja się
wycofuje, zgłoszenie zostaje otwarte, nie ma decyzji ani powiadomienia.
Polityka `sanctionAccount` (#1408) i tak nie pozwala karać administratora;
strażnik jest drugą warstwą. Formularz usunięcia konta pokazuje błąd przy
haczyku potwierdzenia, bez sprawy w rejestrze RODO i bez wpisu audytu.

Testy: `OstatniAdministratorZmianaStatusuTest` (sekwencyjne) oraz dwa nowe
przeploty w `Tests\Dwa\OstatniAdministratorTest`: degradacja A ∥ zawieszenie
B oraz usunięcie konta A ∥ ban B. Kontrola ujemna `scripts/kontrola-ujemna.sh`
(usunięcie `pg_advisory_xact_lock(1016, 1)`): wszystkie trzy przeploty
kończą się **0 zamiast 1** czynnych administratorów; PASS → FAIL → PASS.

## Wycofanie

Brak migracji i zmian danych istniejących (etap A i B). Wycofanie kodu przywraca dawny
wyścig dwóch degradacji; przed wycofaniem należy zapewnić, że polecenia
zmiany ról nie są wykonywane równolegle. Wycofanie nie usuwa audytu.
