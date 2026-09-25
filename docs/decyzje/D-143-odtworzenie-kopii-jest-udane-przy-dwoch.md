## D-143 · Odtworzenie kopii jest udane przy DWÓCH warunkach naraz, nie przy jednym

**Data:** 11 września 2026 · Issue #9 · Status: **obowiązuje**

### Pytanie, na które trzeba było odpowiedzieć

„Po czym poznajemy, że kopia jest dobra, a odtworzenie się udało?" Bez odpowiedzi
próba odtworzenia jest rytuałem: skrypt się wykonał, więc chyba dobrze.

### Decyzja

Odtworzenie jest zaliczone, gdy `psql` kończy się kodem 0 **i** w odtworzonej
bazie stoi spodziewana lista obiektów. Sam kod wyjścia nie wystarcza.

Symetrycznie po drugiej stronie: **sonda zachowania** — zapis, który MUSI zostać
odrzucony — jest zaliczona, gdy `psql` kończy się **błędem** i w treści błędu
jest spodziewany napis. Znowu dwa warunki: sam niezerowy kod wyjścia potwierdziłby
także **literówkę w SQL-u samej sondy**, a taka „zielona" sonda dowodziłaby
dokładnie niczego.

To nie jest ostrożność na wyrost. Odtworzona baza może przyjąć wszystkie dane
i zgubić po drodze ograniczenie, które ich pilnowało — a wtedy pierwszy warunek
świeci na zielono, i dopiero drugi mówi prawdę.

### Czego próba nie zostawia po sobie

Każda sonda idzie w transakcji i kończy się `ROLLBACK`. Po sondzie w bazie
próbnej nie zostaje ani jeden wiersz — i to jest sprawdzane osobnym testem
(`tests/skrypty/proba-odtworzenia.sh`), a nie założone.

### Stan faktyczny w dniu tej decyzji

Produkcyjna baza ma **zero** kopii. Ten wpis mówi, po czym poznamy, że kopia jest
dobra — nie mówi, że jakaś jest.
