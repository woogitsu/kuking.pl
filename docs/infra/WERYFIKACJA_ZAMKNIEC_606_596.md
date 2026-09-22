# Weryfikacja zamknięć #606 i #596

Data odczytu i pomiaru: 20.09.2026. Nietknięte drzewo aplikacji:
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`, gałąź `gpt/widmo-zamkniec`.

## Werdykt

**Obie pozycje są luką dowodową w przeglądzie, nie potwierdzonym rozjazdem
wykonania.** GitHub rzeczywiście pokazuje CLOSED. #606 dotyczy mechanizmu
istniejącego w zależności, #596 operacji poza repozytorium. Nie znaleziono
odwrócenia decyzji wymagającego wpisu do `docs/DECISIONS.md`; nie zmieniono
tego rejestru. Brak słów w `app/` nie dowodzi braku mechanizmu frameworka,
a brak commita nie dowodzi niewykonania operacji w panelu.

## #606 — własny pomiar mechanizmu kolejki

Źródło: [zgłoszenie #606](https://github.com/woogitsu/kuking.pl/issues/606).
Własny odczyt `gh issue view 606 --repo woogitsu/kuking.pl` i timeline API:
CLOSED, zamknięte **16.09.2026 09:16:26 UTC**. Zdarzenie zamknięcia ma
`commit_id: null`; timeline nie wskazuje PR-a zamykającego. Opis zgłoszenia
mówi o sprawdzeniu kodu Laravel 13, nie o napisaniu nowej implementacji.

`config/queue.php` domyślnie wybiera `database`; `.railway/railway.ts`
deklaruje `QUEUE_CONNECTION=database` i `DB_CONNECTION=pgsql`.
To odczyt konfiguracji repozytorium, nie wartości zmiennych produkcji.
`composer.lock` przypina Laravel **v13.30.1**, commit
`718d17db56861e0a49f644217c8853dab1bff8ce`.
[Dokładne źródło frameworka](https://github.com/laravel/framework/blob/718d17db56861e0a49f644217c8853dab1bff8ce/src/Illuminate/Queue/DatabaseQueue.php#L513):
`getLockForPopping()` wybiera `FOR UPDATE SKIP LOCKED` dla PostgreSQL >= 9.5;
`getNextAvailableJob()` stosuje tę blokadę przy pobraniu.

**Własna próba przed zmianą dokumentacji i bez zmiany kodu aplikacji:**
PostgreSQL **18.6**, host `127.0.0.1`, port **55439**, użytkownik/właściciel
bazy testowej `kuking`, baza `kuking_flota_gpt-widmo-zamkniec`.
Dwa połączenia, własna tabela o losowej nazwie, dwa sztuczne zadania.
Pierwsze połączenie trzyma blokadę rekordu 1; drugie wywołuje prawdziwe
`DatabaseQueue::pop()` z limitem oczekiwania na blokadę 500 ms.

- Pobrano **rekord 2**, mimo blokady rekordu 1.
- Dziennik zapytań zawiera `order by "id" asc limit 1 FOR UPDATE SKIP LOCKED`.
- Kontrola ujemna: zapytanie drugiego połączenia z samym `FOR UPDATE`
  trafia na blokadę i zwraca **SQLSTATE 55P03**. Blokada naprawdę istnieje.
- Wycofano transakcję pierwszego połączenia, usunięto tylko własną tabelę;
  `to_regclass` potwierdziło sprzątnięcie.
- Zainstalowana wersja i reference frameworka zgadzają się z lockiem.

Odtworzenie po przygotowaniu runtime i założeniu własnej bazy przez
`testuj.sh`: w katalogu runtime uruchomić
`php docs/infra/evidence/widmo-zamkniec/probe606.php`.
Próbnik ma na sztywno wyłącznie powyższą lokalną bazę i port; nie wykonuje
zadań ani nie dotyka tabeli `jobs` aplikacji. Pierwsza próba przyrządu ujawniła
brak kontenera w ręcznie utworzonym `DatabaseQueue`; po dodaniu kontenera
przebieg zakończył się sukcesem. To błąd przyrządu, nie czerwień aplikacji.

Granice: to próba rezerwacji dwoma połączeniami, **nie benchmark wielu
procesów `queue:work`**, nie pomiar queue lag, przepustowości, produkcyjnych
blokad ani uzasadnienie migracji na Redis. Nie ma bugfixu aplikacji ani
potrzeby dorabiania czerwonego testu pod nieistniejącą usterkę.

## #596 — dowód operacyjny i jego zakres

Źródło: [zgłoszenie #596](https://github.com/woogitsu/kuking.pl/issues/596).
Własny odczyt issue i timeline: CLOSED, zamknięte **16.09.2026 22:10:50 UTC**,
czyli **17.09.2026 00:10:50 CEST**. Daty nie są sprzeczne.
Zdarzenie zamknięcia ma `commit_id: null`; timeline nie wskazuje PR-a
zamykającego. Definicja gotowości wymagała odłączenia albo uzasadnionego
pozostawienia montowania, **nie skasowania wolumenu**.

[pomiar cudzy: [zapis zabezpieczenia plików](https://github.com/woogitsu/kuking.pl/issues/596#issuecomment-5704995653)]
Zabezpieczono cztery pliki, 3 948 898 B; porównano 4/4 sumy z manifestem.
SHA-256 archiwum: `a0021273d1d6d45d1e58f9a4caad0b816d4d2b633fa53901d3dce584ea43a88f`.
Wybrano „Keep Volume”, cofając usunięcie. To kopia pozostałości, nie odzyskanie
pięciu historycznie brakujących oryginałów. Nie pobierałem ani nie
weryfikowałem ponownie prywatnego archiwum.

[pomiar cudzy: [odbiór operacji](https://github.com/woogitsu/kuking.pl/issues/596#issuecomment-5705280772)]
17.09 około 00:05–00:07 CEST zastosowano odłączenie wraz ze zmienną dysku
Livewire. Po około dwóch minutach niedostępności przywrócono obraz `6afbf711`.
Aktywne wdrożenie: `98aea956-e334-4ae8-93f0-d77d49e6c316`, instancja `0302928e`;
oczekujące `bb48b09f-ae5f-416f-ab03-bdffe103e160` usunięto.
`findmnt -T /app/storage/app` w przywróconej instancji dało `/ overlay`.
Wolumen pozostał osobno, strona i pięć oglądanych obrazów działały.
Przywrócenie obrazu cofnęło zmienną Livewire, dlatego #608 było osobną sprawą.

**Ślad w historii repozytorium jednak istnieje:**
[commit 30b4cff693a1f25ec9ac674120a4820abd0f1521](https://github.com/woogitsu/kuking.pl/commit/30b4cff693a1f25ec9ac674120a4820abd0f1521),
`docs/infra/KOPIE_I_ODTWORZENIE.md` §5.2: odczyt panelu z 17.09,
500 MB, `mountedOn: null`, odsyłacz #596.
Znaleziony przez `git log --all -S 'mountedOn: null' -- docs/infra/KOPIE_I_ODTWORZENIE.md`.
To cudzy historyczny odczyt, a commit dokumentuje go, nie wykonuje odłączenia.
Skan `git log --all -G 'SKIP LOCKED|skipLocked|#606|#596' -- app tests docs/DECISIONS.md`
nie zwrócił trafień; jego zakres pomija właśnie dokument operacyjny i vendor.

**Własny odczyt Railway 20.09:** projekt `ideal-exploration`
(`77044ca0-2cf4-4be1-bcd8-fdb6c4d83047`), środowisko `production`
(`ea146c13-dc55-4a4f-a386-0835f650f9ce`), usługa `kuking.pl`
(`200d68a6-24b0-46f3-bd7a-924cd00e532e`). Narzędzie
`get_service_config`, obejmujące montowania, nie zwróciło montowania
wolumenu; `staged: null`. Konfiguracja wskazuje repo `woogitsu/kuking.pl`,
domenę `kuking.pl`, start `kuking-entrypoint all`, jedną replikę.
To aktualne potwierdzenie konfiguracji zgodnej z odłączeniem.
Nie wykonywałem `findmnt` w dzisiejszym kontenerze, odbioru zdjęć/uploadu,
listowania odłączonych wolumenów ani odtwarzania archiwum. Ich dzisiejszy
stan nie jest tym odczytem potwierdzony.

## Zakres zmiany, kontrole i dalsze decyzje

`PLAN_TECHNICZNY_614.md` nie istniał w HEAD tej gałęzi. Przeniesiono dokument
z przekazanego stanowiska `gpt-ci-architektura` do własnego katalogu i
uzupełniono **wyłącznie dwa wiersze #596/#606**. Pozostałe ustalenia planu
pochodzą z tamtego dokumentu i nie były ponownie audytowane w tym zadaniu.
Nie zapisano niczego w cudzym stanowisku. Przy integracji z jego gałęzią
należy zachować późniejszą wersję planu i przenieść te dwa uzupełnienia.

Nie zmieniono kodu produktu, schematu, rejestru decyzji ani infrastruktury.
Wycofanie: usunąć niniejszy raport i próbnik, cofnąć dwa uzupełnienia planu
(zachowując dokument pochodzący z osobnej pracy). Nie ma migracji.

Własna kontrola `UmowaKolejkiTest`: **4 testy, 8 asercji, PASS**. Test dotyczy
umowy timeoutów i kolejek; nie zastępuje opisanej wyżej próby blokowania.
Po przeniesieniu sformatowanego próbnika i ponownej synchronizacji runtime:
`vendor/bin/pint --test`: **1156 plików, PASS**; próbę blokady i test
`UmowaKolejkiTest` powtórzono z tym samym wynikiem. Pierwsza pełna kontrola
Pint wskazała wyłącznie roboczą kopię próbnika w `output/`; tę tymczasową
kopię usunięto, docelowy próbnik sformatowano przez `vendor/bin/pint`.
Pełnego zestawu testów aplikacji nie uruchamiano: zmiana dotyczy dokumentacji
i osobnego przyrządu; zakres weryfikacji nie jest pełnym odbiorem aplikacji.

Zamknięcia tych dwóch zgłoszeń nie wymagają nowej decyzji właściciela.
**Dalsza retencja wolumenu i kopii** pozostaje osobną decyzją: zachowanie
utrzymuje koszt magazynu i możliwość odzyskania pozostałości; usunięcie po
inwentaryzacji i przyjęciu retencji ograniczy koszt, ale odbierze tę drogę
odzyskania. Kwoty nie były mierzone. Żaden wariant nie jest realizowany ani
wymuszany testem w tym zadaniu; #596 wprost zabrania kasowania przy zamykaniu.
