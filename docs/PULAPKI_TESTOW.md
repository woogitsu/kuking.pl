# Pułapki testów — sześć rzeczy, które w tym repozytorium naprawdę przeszły

Ten plik nie jest wykładem o testowaniu. To lista pomyłek, które **w tym
projekcie** przeszły przez zielone CI i zostały wykryte dopiero przez
kontrolę ujemną albo przez zewnętrzny audyt.

Każda ma tu wpis, bo każda kosztowała czyjąś pracę i każda wróci.

Zasada nadrzędna, z której cała ta lista wynika (`AGENTS.md` §10):

> **Test, który przechodzi także po zepsuciu tego, czego pilnuje, nie jest
> testem.** Kontrola ujemna nie jest formalnością — jest jedynym dowodem,
> że test cokolwiek mierzy.

---

## 1. Asercja na całym HTML-u łapie to samo słowo skądinąd

**Złapała: sześć osób, w tym autora tego pliku.**

Strona zawiera nawigację, prawą szynę, licznik komentarzy, stopkę i pusty
stan. Każde z nich ma te same słowa i te same liczby, co element, który
sprawdzasz. `assertSee('3')` na stronie wpisu przechodzi, bo „3" jest
w liczniku powiadomień.

Najgorszy wariant: test przechodzi z **innego powodu, niż myślisz**, i mówi
Ci, że funkcja działa, gdy nie działa. Zdarzyło się dokładnie tak przy
eager-loadingu tagów na profilu: wynik był poprawny, ale z powodu odnośników
w prawej szynie, nie z powodu karty wpisu.

**Co robić:** wycinaj konkretną sekcję i sprawdzaj w niej. W repozytorium są
trzy gotowe wzorce — użyj któregoś, nie wymyślaj czwartego:

| Wzorzec | Gdzie |
|---|---|
| `wycinek($html, $od, $do)` | `tests/Feature/OnboardingZnajdzZnajomychTest.php` |
| `DOMXPath` z zakresem sekcji | `tests/Feature/LandingJakDzialaPrzedTablicaTest.php` |
| wycinanie pasa/sekcji po identyfikatorze | `tests/Feature/StronaPowitalnaPasyTest.php`, `tests/Feature/OdkrywaniePustyStanTest.php` |

---

## 2. Test skanujący pliki przechodzi, gdy nie znajduje ŻADNEGO pliku

**Złapała: test, który był zielony przy pięciu żywych ukośnikach rodzajowych.**

Test przechodzący po `resources/views/` i szukający wzorca przechodzi także
wtedy, gdy glob jest zły, ścieżka się zmieniła albo wzorzec nie łapie niczego.
Zero trafień to dla niego sukces.

**Co robić — kontrola ujemna jest tu ODWROTNA niż zwykle:** wstaw tymczasowo
treść, która łamie regułę, i sprawdź, że test **OBLEWA**. Do tego asercja na
minimalną liczbę przeskanowanych plików:

```php
$this->assertGreaterThan(100, $przeskanowane, 'Skan nie czyta plików — zła ścieżka?');
```

Bez tej asercji przeniesienie katalogu wyłącza test bez jednego czerwonego
przebiegu.

---

## 3. Twój sabotaż może być za słaby — i uznasz dobry test za atrapę

**Złapała: dwóch agentów niezależnie, w tym samym tygodniu.**

Oba próbowały zepsuć `catch (UniqueConstraintViolationException)` podmieniając
go na `\RuntimeException`. Test nadal przechodził — i obaj byli o krok od
wniosku, że test niczego nie pilnuje.

Przyczyna: `UniqueConstraintViolationException → QueryException →
PDOException → RuntimeException`. Sabotaż łapał wyjątek równie dobrze jak
oryginał. Prawdziwym sabotażem było dopiero `\LogicException`, czyli inna
gałąź hierarchii.

**Co robić:** kontrola ujemna, która nie oblewa, ma **dwie** możliwe
przyczyny — zły test albo zły sabotaż. Sprawdź drugą, zanim uwierzysz
w pierwszą.

## 3b. …a może być też za mocna i trafiać nie tam, gdzie myślisz

Odwrotny przypadek z tej samej sesji: dwie kontrole ujemne **przeszły**, i to
wykryło błąd w testach, nie w kodzie. Dwa testy bariery bazodanowej trafiały
w tę samą gałąź warunku wyzwalacza, więc drugi nie sprawdzał niczego.

**Każda gałąź warunku potrzebuje własnego testu i własnego sabotażu.**

---

## 4. Asercja tylko negatywna przechodzi, gdy mechanizm nie działa wcale

**Złapała: trzy testy widoczności, sprawdzające, że zbanowany nie wychodzi
w wynikach wyszukiwania.**

`assertStringNotContainsString('zbanowany_kucharz', $html)` przechodzi
również wtedy, gdy wyszukiwarka **nie zwraca nikogo** — bo wtedy nie ma
w HTML-u nikogo, więc nie ma i zbanowanego. Przechodzi też po literówce
w szukanym łańcuchu.

Zmierzone: podstawienie `->take(0)` na wyniku zapytania nie oblało żadnego
z tych trzech testów.

**Co robić:** przy każdej asercji „czegoś nie ma" dołóż **kontrolę dodatnią**
w tym samym teście — drugą, widoczną rzecz pasującą do tego samego warunku.
Dopiero para „widoczna wyszła, ukryta nie wyszła" dowodzi, że mechanizm
pracował.

To samo dotyczy testów sprawdzających, że coś się **nie zmieniło**: trzy
testy „adres konta zostaje bez zmian" przeszłyby także wtedy, gdyby zmiana
adresu nie działała nigdy. Czwarty test musi być kontrolą dodatnią.

---

## 5. Narzędzie może zameldować sukces, nie robiąc nic

**Złapała: własny skrypt czekający na CI i job „Test dymny po deployu".**

Pierwszy odpowiedział „ZIELONE" na odpowiedź, w której wszystkie joby stały
w kolejce. Drugi kończył się jako `success`, mając sam krok testu dymnego
`skipped` — 249 przebiegów pod rząd.

**Co robić:** pytaj o **wynik kroku**, nie o status całości.
`steps.<id>.outcome`, nie `job.status`. I wymagaj, żeby każdy oczekiwany
element był obecny i ukończony — brak wyniku to nie „w porządku", to „nie
wiemy".

Ta sama zasada rządzi bramkami w `docs/OTWARCIE.md`: **`NIE WIEMY` liczy się
jako nieprzejście, nie jako sukces.**

---

## 6. Test na jednym połączeniu nie dowodzi zachowania przy dwóch

**Dotyczy: wszystkich ośmiu P1 współbieżnościowych z audytu.**

`RefreshDatabase` trzyma dane w niezatwierdzonej transakcji, więc drugie
połączenie ich nie zobaczy. Prawdziwego przeplotu dwóch procesów w PHPUnicie
nie odtworzysz.

To **nie zwalnia z testu** — zwalnia z udawania, że dowodzi więcej, niż
dowodzi. Da się deterministycznie wymusić ten przeplot, który był usterką:
pobrać model, wykonać drugą operację, potem dokończyć pierwszą. To pokazuje
dokładnie tę pomyłkę, która była w kodzie (akcja ufa modelowi podanemu
z zewnątrz), i oblewa się po jej cofnięciu.

**Co robić:** napisz test wymuszający przeplot, a **ograniczenie napisz wprost
w docblocku** — czego ten test nie dowodzi. W tym repozytorium jest na to
wzorzec: `tests/Feature/PotwierdzenieAdresuNieWyprzedzaAnulowaniaTest.php` oraz cały
katalog `tests/Feature/Wyscigi/` (siedem testów, m.in.
`IdempotencjaZgloszeniaWyscigTest`, `EksportDanychRaceTest`,
`ModerationDecideRaceTest`).

Nie pisz zamiast tego testu pozornego, który „sprawdza współbieżność" przez
dwa wywołania pod rząd.

---

## Skąd ta lista

Trzy warstwy zewnętrznego audytu z 10.09.2026
(`docs/research/audyt-2026-09-10/`) znalazły osiem P1 w kodzie i wszystkie
były jednym rodzajem błędu: **inwariant sprawdzany, a potem wykonywany,
zamiast wykonany atomowo.** Przy weryfikowaniu tych ośmiu poprawek wyszło
sześć pułapek wyżej — i to one, nie same poprawki, są tu najtrwalszą
wartością.

Dwie zasady o kodzie, które z tego zostają (D-079, obowiązują szerzej niż
miejsce zapisu):

1. **Gwarancję daje constraint albo blokada, nie `exists()` w PHP.**
   `exists()` jest dobre na ładny komunikat i tam zostaje.
2. **Blokada bez rewalidacji pod nią nie pilnuje niczego** — serializuje, ale
   nie mówi żądaniu, że świat zmienił się, gdy ono czekało.
