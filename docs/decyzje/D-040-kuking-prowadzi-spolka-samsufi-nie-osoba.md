## D-040 · Kuking prowadzi spółka SAMSUFI, nie osoba fizyczna

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

> **Rozstrzyga lukę, nie zmienia decyzji.** Regulamin i polityka prywatności
> mówiły dotąd „serwis prowadzi osoba fizyczna" i obiecywały podanie danych
> „zanim otworzymy rejestrację dla wszystkich". To nie była decyzja — to było
> puste miejsce, które blokowało otwarcie.

Administratorem danych i podmiotem prowadzącym serwis jest **SAMSUFI Spółka
z ograniczoną odpowiedzialnością**, ul. Jagiellońska 4A, 19-120 Knyszyn,
KRS 0000901262, NIP 5423435334, REGON 388971059.

**DLACZEGO TO NIE MOGŁO POCZEKAĆ.** RODO art. 13 ust. 1 lit. a każe podać
tożsamość administratora **w momencie zbierania danych** — czyli na ekranie
rejestracji, a nie po e-mailu na żądanie. Zdanie „możesz o nie poprosić
i je otrzymasz" brzmiało uczciwie, ale przenosiło na człowieka obowiązek,
który spoczywa na nas.

**DANE STOJĄ W KONFIGURACJI, NIE TYLKO W DOKUMENCIE.** `config/kuking.php`
(`kuking.podmiot`) jest źródłem, a `DokumentyPrawneNieKlamiaTest` porównuje
z nim treść obu dokumentów. Numer KRS zmienia się w rejestrze, nie w pliku
markdown — bez tego porównania poprawka w jednym miejscu zostawiłaby
w drugim nieprawdę na żywej stronie. To ten sam mechanizm, którym D-038
pilnuje okresów retencji.

**ADRES KONTAKTOWY TO `biuro@samsufi.pl`, NIE `kontakt@kuking.pl`** — i to
jest świadome. Adres w domenie kuking.pl zależy od poczty, której 8 września
jeszcze nie ma (`MAIL_MAILER=log`). Dokument prawny musi podawać adres,
o którym wiadomo, że ktoś go czyta; adres serwisowy stoi obok jako drugi.
Gdy poczta na kuking.pl ruszy i zostanie potwierdzone, że odbiera, kolejność
można odwrócić — ale nie wcześniej.

**Zmiana wymaga:** zmiany w rejestrze przedsiębiorców albo przeniesienia
serwisu do innego podmiotu.

📄 `config/kuking.php` (`kuking.podmiot`) · `resources/legal/regulamin.md` §1 ·
`resources/legal/polityka-prywatnosci.md` §1 ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` ·
`docs/legal/BRAMKA_BETY.md` §8 · RODO art. 13 ust. 1 lit. a
