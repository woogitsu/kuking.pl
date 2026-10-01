## D-132 · Strażnik `down()` bez testu wołającego ten `down()` nie jest strażnikiem

**Data:** 11 września 2026 · Status: **obowiązuje**

Pięć migracji miało poprawną obronę — `RuntimeException`, zgoda przez `getenv()`,
opis zgodny z `docs/DATABASE.md` — i **ani jednego testu, który by ją wywołał**.
`down()` nie chodzi w normalnym przebiegu testów, więc taka obrona może zniknąć przy
pierwszym refaktorze i nikt tego nie zauważy.

### Każda taka migracja dostaje trzy rzeczy

1. **odmowę** plus asercję, że dane NADAL SĄ — odmowa, która zdążyła skasować, to
   tylko ładniejszy komunikat o stracie;
2. **kontrolę dodatnią** na pustym stanie — bez niej test przechodzi także dla
   migracji, która nie cofa się NIGDY;
3. **wąskość** — odmowa nie rusza niczego poza swoim zakresem.

Migracja z furtką przez zmienną środowiskową dostaje czwartą: furtka opisana
w komunikacie ma naprawdę działać.

### Co pokazały sabotaże

Bez strażnika odmawia sama baza — surowym `SQLSTATE[23514]` albo `SQLSTATE[23502]`
zamiast zdaniem po polsku. To jest cała różnica między „nie da się" a „nie da się,
oto ilu osób to dotyczy i co zrobić zamiast tego".

### Komunikat nie odmienia rzeczownika przez liczbę

„jest 1 zgłoszeń" i „że 1 osób odebrało" to formy błędne, a jeden wiersz jest stanem
bardziej prawdopodobnym niż pięć. Liczbę podaje się w formie odpornej: „Liczba
zapisów, które znikną: 1".
