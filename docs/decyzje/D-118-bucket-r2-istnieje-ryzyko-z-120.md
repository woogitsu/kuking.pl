## D-118 · Bucket R2 istnieje — ryzyko z #120 jest BIEŻĄCE, nie przyszłe

**Data:** 11 września 2026 · Potwierdził właściciel · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Sprostowanie, po które
> ten wpis powstał, nigdy nie weszło do dokumentu.
> `docs/infra/KOPIE_I_ODTWORZENIE.md:58` nadal niesie ramkę „Najpilniejsza
> pozycja z tej tabeli" z tezą „Bucket R2 nie istnieje jeszcze", a `:130`
> powtarza dokładnie zdanie cytowane w tym wpisie jako nieprawdziwe: „**Bucket
> R2 nie istnieje.** Potwierdzone wprost przez właściciela". Czytelnik tamtego
> dokumentu jest więc dalej kierowany na ryzyko, o którym ten wpis mówi, że go
> nie ma, i dalej odwracana jest jego uwaga od ryzyka bieżącego. Wniosek
> metodyczny tego wpisu — „zdanie o stanie świata ma nosić datę pomiaru" — nie
> został zastosowany do samego naprawianego zdania: żadne z dwóch miejsc daty
> nie ma i nic tego nie pilnuje testem.

`KOPIE_I_ODTWORZENIE.md` twierdziło od 8 września: „Bucket R2 nie istnieje jeszcze.
Potwierdzone wprost przez właściciela". **Zdanie zestarzało się w trzy dni** i przez
ten czas kierowało czytelnika na ryzyko, którego nie ma (utrata zdjęć przy
redeployu), odwracając uwagę od tego, które jest.

Stan faktyczny: zdjęcia leżą na R2, a **bramka `kuking:bramka-r2` nie chodziła na
produkcji ani razu** — więc publiczność bucketu oryginałów jest NIESPRAWDZONA.

Osobno, i to nie znika razem z tym sprostowaniem: **bucketów ze zdjęciami nie
kopiuje dziś nic**, a R2 nie ma wersjonowania obiektów ani kosza. Kopie z §7
dotyczą wyłącznie bazy.

**Wniosek metodyczny:** zdanie o stanie świata ma nosić datę pomiaru, a nie samo
nazwisko osoby, która je potwierdziła.
