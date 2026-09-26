## D-042 · Zgłaszający ze zwykłego formularza dostaje pouczenie, nie formularz skargi

**Data:** 9 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

> **Rozstrzyga pytanie zadane w PR #186, żeby nie wracało.** Ten sam wybór
> stawał już pod trzema nazwami: „Luka 1" w issue #10, „czy art. 20 obejmuje
> zgłaszających" w `docs/research/DSA-LUKI.md` §5 i pytanie autora #186.
> Odpowiedź jest jedna i stoi tutaj.

Osoba, która zgłasza treść przyciskiem „Zgłoś", dostaje z DSA art. 16:
potwierdzenie przyjęcia z numerem sprawy (ust. 4), informację o decyzji
(ust. 5) i przy niej **pouczenie o dostępnych środkach**. Nie dostaje
formalnego wewnętrznego systemu rozpatrywania skarg. Ten zostaje —
tak jak dotąd — przy zgłoszeniach prawnych (`Report::jestZgloszeniemPrawnym()`).

**DLACZEGO TO NIE JEST OSZCZĘDZANIE NA LUDZIACH.** Wewnętrzny system skarg
to art. 20 DSA, a art. 20 leży w **Sekcji 3**, z której Kuking jest zwolniony
jako małe przedsiębiorstwo (art. 19; kwalifikacja przez D-040 i
`docs/legal/COMPLIANCE.md` §1.2). Art. 16 leży w Sekcji 2 i wiąże niezależnie
od wielkości — i jest spełniony. Pouczenie mówi człowiekowi, co może zrobić
dalej: podaje numer sprawy, adres kontaktowy, zdanie o organie pozasądowym
i o sądzie. To nie jest odesłanie z kwitkiem.

**DLACZEGO NIE OTWORZYLIŚMY TEGO „PRZY OKAZJI", SKORO KOD JUŻ JEST.**
Bo koszt nie leży w kodzie. `FileReporterAppeal` istnieje i zdjęcie z niego
jednego warunku to praca na jeden PR. Kosztem jest **druga kolejka spraw
do rozpatrzenia przez jedną osobę** — a `docs/product/SOUL.md` i teza 2
z audytu A6 mówią to samo: jednoosobowa obsługa musi mieć jawny limit,
nie ukrytą obietnicę dyżuru. Obietnica rozpatrzenia skargi, na którą nie ma
czasu, jest gorsza niż jej brak.

**CO BY TO ZMIENIŁO.** Gdyby prawnik uznał, że zwolnienie z Sekcji 3 nie
obejmuje tej sytuacji, zakres jest znany i policzony: zdjąć warunek
`jestZgloszeniemPrawnym()` z `FileReporterAppeal` i dać zgłaszającemu
z kontem wejście na istniejący formularz. Ta decyzja nie zamyka tamtej drogi,
tylko mówi, że dziś nią nie idziemy.

**CZEGO TA DECYZJA NIE ROZSTRZYGA.** Nie rozstrzyga, czy zgłoszenie ze
zwykłego formularza jest w ogóle „zawiadomieniem o treści nielegalnej"
w rozumieniu art. 16, czy tylko zgłoszeniem naruszenia regulaminu. Nasza
lista powodów miesza jedno z drugim: „spam" to nasza zasada, ale „mowa
nienawiści" i „dotyczy dziecka" to zarzuty nielegalności. Postąpiliśmy
najostrożniej — odpowiedź i pouczenie idą do **wszystkich** zgłaszających,
niezależnie od wybranego powodu, bo nadmiar odpowiedzi nikomu nie szkodzi,
a jej brak jest naruszeniem. **Kwalifikacji prawnej nie rozstrzyga model** —
to pytanie zostaje otwarte dla prawnika i jego odpowiedź może dołożyć wymogi
(termin odpowiedzi, informacja o użyciu narzędzi automatycznych).

📄 `app/Domain/Moderation/Actions/FileReporterAppeal.php` ·
`app/Models/Report.php` (`jestZgloszeniemPrawnym()`) ·
`app/Domain/Moderation/OdpowiedzDlaZglaszajacego.php` ·
`docs/legal/COMPLIANCE.md` §1.2 · `docs/research/DSA-LUKI.md` §5 · D-040
