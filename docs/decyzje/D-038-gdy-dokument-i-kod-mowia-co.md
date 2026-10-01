## D-038 · Gdy dokument i kod mówią co innego, poprawiamy to, co jest nieprawdą

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

Dwa rozjazdy postawione właścicielowi tego samego dnia, oba rozstrzygnięte
w tę samą stronę — **dokument dogania kod, bo to dokument kłamał**:

**Polityka prywatności.** Twierdziła wytłuszczonym drukiem, że automatycznego
usuwania zgłoszeń, dziennika zdarzeń i powiadomień **nie ma**. Trzy komendy
kasują je codziennie o 04:10, 04:20 i 04:30. Wpisane prawdziwe okresy:
36 miesięcy od zamknięcia sprawy moderacyjnej, 12 miesięcy dziennika zdarzeń,
3 miesiące powiadomień — każdy z wyjątkami, które kod naprawdę stosuje.
Wariant odwrotny (wyłączyć automaty, żeby dokument znów był prawdziwy)
odrzucony: usuwanie danych po terminie jest obowiązkiem, nie funkcją.

**Termin odwołania.** `docs/MODERATION.md` mówiło 14 dni. Kod bierze WIĘKSZĄ
z dwóch wartości: `appeal_days` (180) i sztywnych sześciu miesięcy
(`ModerationAction::appealDeadline()`), a regulamin §8 i podręcznik moderatora
mówią 6 miesięcy. Dokument techniczny był po prostu ostatni, który o tym
nie wiedział.

> **Skąd naprawdę bierze się te sześć miesięcy — dopisane 9 września po
> audycie zewnętrznym (G17).** Stało tu zdanie „bo tyle WYMAGA DSA art. 20
> ust. 1. Skrócenie do 14 dni byłoby złamaniem przepisu". To było fałszywe
> uzasadnienie prawdziwej liczby. Art. 20 leży w Sekcji 3 rozdziału III DSA,
> a **art. 19 wyłącza całą tę sekcję** dla mikro- i małych przedsiębiorstw.
> Serwis prowadzi SAMSUFI sp. z o.o. (D-040) — spółka handlowa jest
> przedsiębiorstwem bez cienia interpretacji i przy dzisiejszej skali mieści
> się w progu mikroprzedsiębiorstwa, więc **art. 20 nas nie wiąże**.
>
> **Termin zostaje i to się nie zmienia.** Zmienia się tylko to, CZYM jest:
> nie obowiązkiem z rozporządzenia, tylko **obietnicą złożoną człowiekowi
> w regulaminie §8**. To wiąże nas mocniej niż przepis, z którego jesteśmy
> zwolnieni — bo ktoś tę obietnicę przeczytał i na niej polega. Skrócenie
> wymaga zmiany regulaminu i powiadomienia użytkowników, nie samej zmiany
> `config/kuking.php`.
>
> **Dlaczego to w ogóle zapisujemy, skoro liczba się nie zmienia:** fałszywe
> uzasadnienie jest groźniejsze niż jego brak. Kto przeczyta „art. 20 nas
> wiąże", wyprowadzi z tego resztę Sekcji 3 — pozasądowe rozstrzyganie
> sporów (art. 21), zaufanych sygnalistów (art. 22), pełne sprawozdanie
> przejrzystości (art. 24) — i zacznie budować miesiące pracy, której robić
> nie trzeba. Zakres i granice zwolnienia: `docs/legal/COMPLIANCE.md` §1.2.

**Reguła na przyszłość, bo to trzeci taki przypadek w tym repozytorium:**
rozjazd między dokumentem a kodem rozstrzyga się **od strony faktu**, nie od
strony tego, co łatwiej poprawić. Jeśli faktem jest kod — poprawiamy dokument.
Jeśli faktem jest przepis albo obietnica dana człowiekowi — poprawiamy kod.
Nigdy nie zostawiamy obu wersji „do wyjaśnienia": z dwóch sprzecznych zdań
o serwisie jedno na pewno wprowadza kogoś w błąd.

**Zmiana wymaga:** nic — to jest zasada porządkowa, nie wybór produktowy.

📄 `resources/legal/polityka-prywatnosci.md` · `docs/MODERATION.md` ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` · D-024
