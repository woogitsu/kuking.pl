## D-224 — Wpis wychodzi z zeszytu tam, gdzie widać, że w nim jest (audyt L1, 20 września 2026)

Trasa `DELETE /wpisy/{post}/zapisz` (`collections.unsave-post`) istniała,
była otestowana i bezpieczna, ale żaden widok jej nie wołał. Zdanie z D-081
„wyjąć z zeszytu można nadal w samym zeszycie" było nieprawdziwe: ekran
zeszytu renderuje tę samą kartę wpisu. Właściciel rozstrzygnął: przycisk
stoi wszędzie tam, gdzie widać „Masz to w zeszycie" — w zeszycie i na karcie.
Trasy nie kasujemy.

> **Sprostowane 20 września 2026 — patrz D-231.** Zdanie „przycisk stoi
> wszędzie tam, gdzie widać »Masz to w zeszycie«" przestało być prawdziwe na
> JEDNYM ekranie: w środku konkretnego zeszytu nie ma już ani odnośnika „Masz
> to w zeszycie", ani przycisku „Usuń z zeszytu" — stoi tam wyłącznie „Usuń
> z tego zeszytu" o zakresie lokalnym. Poza zeszytem wszystko poniżej zostaje
> bez zmian. Reszta D-224 — brak potwierdzenia przed akcją, droga powrotu po
> niej, brak JavaScriptu, granica ostrzejsza niż Policy — obowiązuje dalej.
> Zmienił się też sam komunikat: nazywa teraz FAKTYCZNY zakres (D-231).

Przycisk stoi OBOK odnośnika „Masz to w zeszycie", nie zamiast niego. Miejsce,
w które przed chwilą kliknięto „Zapisuję", zajmuje dalej odnośnik do zeszytu,
więc drugie kliknięcie (norma w tej grupie, issue #43) niczego nie zabiera.
Zmierzone: przycisk 207 × 50,5 px przy 320 px i 260 × 59,5 px przy tekście
140%, pismo 18 i 25,2 px, 10 px przerwy od odnośnika, bez przewijania w bok.

Bez potwierdzenia i bez JavaScriptu. Wyjęcie nie kasuje treści i cofa się
jednym kliknięciem, więc pytanie „czy na pewno" zostaje dla rzeczy
nieodwracalnych — kasowania wpisu i kasowania zeszytu. Zamiast pytania PRZED
akcją jest droga powrotu PO niej: komunikat „Wpis wyjęty z zeszytu. Nie
usunęliśmy go z serwisu — możesz go zapisać ponownie." i przycisk „Zapisz
ponownie" w tym samym obszarze `aria-live` (`status_powrot` w sesji).

Nazwa jest ta sama co przy przepisie — „Usuń z zeszytu" (`BRAND_EXTENDED.md`
§3: jedna czynność, jedna nazwa). Audyt proponował „Wyjmij"; to byłby drugi
synonim na tę samą rzecz.

Zakres akcji pozostaje przypięty do zeszytów osoby, która wysłała żądanie
(`SavePostToCollection::remove()`), czyli jest ostrzejszy niż Policy: obca
osoba nie rusza cudzego wiersza, a wpis, którego nie wolno już oglądać, daje
się z zeszytu wyjąć. Dowody: `tests/Feature/WpisDaSieWyjacZZeszytuTest.php`
i `scripts/wyjecie-z-zeszytu.mjs`.
