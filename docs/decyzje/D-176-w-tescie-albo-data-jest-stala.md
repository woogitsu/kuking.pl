## D-176 · W teście albo data jest stała i zegar przymrożony, albo obie są względne

**Data:** 12 września 2026 · PR #438 · Status: **obowiązuje**

### Kontekst

12 września o **10:00 UTC `main` zrobił się czerwony bez ani jednego commita.**
`AccountStatusTest::test_zawieszony_widzi_date_konca_kary_po_polsku` zawieszał konto
do `2026-09-12 10:00:00` i sprawdzał jej polski zapis. Gdy test powstawał, data była
w przyszłości. Tego dnia o 10:00 termin minął, `EnsureAccountIsActive` zdjął karę przy
pierwszym żądaniu, ekran **słusznie** przestał cokolwiek pokazywać — i test zaczął
padać **na sprawnym kodzie**.

### Decyzja

Test sprawdzający **format** albo **treść zależną od daty** przymraża zegar
(`travelTo`) i podaje datę jawnie. Test sprawdzający **upływ czasu** liczy względem
`now()` i nie wpisuje żadnej daty. **Jedno i drugie w jednym teście to bomba
z opóźnionym zapłonem**, tykająca dokładnie tyle, ile wynosi różnica między dniem
napisania a wpisaną datą.

### Sprawdzenie, które kończy poszukiwania w pół minuty

Taka czerwień wygląda nie tak, jak jest: pada w środku dnia, na gałęzi, która nie
tknęła ani moderacji, ani layoutu, i pierwszy odruch to szukać winnego wśród świeżo
scalonych PR-ów. **Uruchom ten jeden test na czystym `main`.** Jeśli pada i tam, to
nie jest niczyja zmiana.

Zapisane jako **pułapka 9** w `docs/PULAPKI_TESTOW.md`. Należy do tej samej rodziny co
pułapka 8b: czerwień, którą czytasz, nie musi pochodzić ze zmiany, którą oglądasz.

📄 `docs/PULAPKI_TESTOW.md` §9 · `tests/Feature/AccountStatusTest.php` · `AGENTS.md`
