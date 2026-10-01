## D-134 · Cyfra wersji rośnie przy każdej widocznej zmianie, a każde podbicie ma wpis w `CHANGELOG.md`

**Data:** 11 września 2026 · Zgłosił i rozstrzygnął właściciel · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Pierwsza połowa reguły
> działa: `config/kuking.php` stoi na `'etykieta' => 'Alfa 0.67'`, a metryczki
> w stopce pilnuje `tests/Feature/StopkaPoziomyTest.php:119`. Druga połowa
> jest złamana — w `CHANGELOG.md` NIE MA wpisu dla **Alfy 0.46**: `:101` to
> „## Alfa 0.47 — kolaż po wyczyszczeniu wyboru", a następna pozycja pod nim,
> `:107`, to „## Alfa 0.45 — przycisk rejestracji przy dużym tekście". To jest
> dokładnie „numer bez treści", przed którym ostrzega zdanie „jedno pilnuje
> drugiego". Przyczyna jest strukturalna: sprzężenie nie ma strażnika — `grep
> CHANGELOG` po `tests/` i `scripts/` nie daje ani jednego trafienia. Ten wpis
> przewiduje ryzyko „strażnika poprawianego bezmyślnie przy każdym wydaniu";
> zrealizowało się ryzyko odwrotne — strażnika nie ma wcale.

### Zgłoszenie

„aktualna wersja to alfa 0.1, czemu tego nie zmieniasz? chyba dużo zmian zrobiliśmy
od pierwszej alfy 0.1".

### Co było nie tak z regułą

Komentarz przy `kuking.wersja.etykieta` mówił, kiedy zmienia się **słowo** (Alfa →
Beta → 1.0, przy kamieniach milowych z ROADMAP-y) — i ani słowa o tym, kiedy zmienia
się **cyfra**. Przez to `0.1` nie ruszyło się ani razu od pierwszego dnia, mimo
kilkunastu scaleń samego 11 września.

**Numer, którego nikt nigdy nie podbija, nie niesie żadnej informacji.** Prawdę
o tym, co działa, mówił w stopce wyłącznie skrót commita obok.

### Decyzja

Cyfra rośnie przy każdej zmianie, którą **człowiek zobaczy**: nowy ekran, zmieniony
układ, nowa funkcja, inne zachowanie formularza. Poprawki bez śladu w interfejsie
(testy, refaktor, dokumentacja) jej nie ruszają.

Każde podbicie ma wpis w `CHANGELOG.md`, pisany **językiem użytkownika, nie
commitów**. Jedno pilnuje drugiego: wersja bez wpisu jest numerem bez treści, a wpis
bez wersji nie da się z niczym powiązać.

Historii sprzed 11 września nie odtwarzamy wstecz — wpisy pisane z pamięci po fakcie
są gorsze niż ich brak.

### Etap zostaje „Alfa"

Bramki zamkniętej alfy nie przeszliśmy: sześć kont przy wymaganych dwudziestu, kopia
produkcyjnej bazy to nadal zero, a blocker UX był otwarty w dniu tej decyzji.

### Test stopki nie zna wersji na pamięć

`StopkaPoziomyTest` miał w regeksie wpisane `Alfa 0\.1`. Czyta teraz etykietę
z konfiguracji i pilnuje, że etap produktu **stoi** w metryczce — a nie że akurat
dziś brzmi tak, a nie inaczej. Strażnik, który trzeba poprawiać przy każdym
wydaniu, zostaje prędzej czy później poprawiony bezmyślnie.
