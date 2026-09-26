## D-089 · Panel moderacji na szerokim ekranie: bez zarezerwowanej pustej szyny, a z dwóch „Wróć do Kuking" zostaje jedno — to które jest `position: fixed`

**Data:** 10 września 2026 · **Zgłoszenie właściciela, issue #294 (Galaxy Fold
rozłożony)** · Status: **obowiązuje**

Zgłoszenie wskazywało sześć rzeczy naraz na `/admin/uzytkownicy`. Cztery
z nich (nawigacja jako surowa lista zamiast kolumny, samotny „|" nad „Wróć do
Kuking", ucięty rząd zakładek, tabela wypychająca stronę) naprawiła wcześniej
ta sama gałąź, przenosząc `.side-nav-item` poza próg `64rem` i dodając
`flex-wrap` do `.tabs` oraz `position: relative` do kontenera tabeli. Dwie
pozostałe (punkty 2 i 4 zgłoszenia) wymagały osobnej decyzji — issue wprost
mówi „do decyzji, które" przy drugiej z nich. Ten wpis zapisuje obie, żeby
nikt ich nie odkręcił, uznając za przeoczenie.

### 1. Panel nie dostaje trzeciej kolumny (`.app-rail`) od `80rem`

**Stan sprzed zmiany, sprawdzony w kodzie:** `.app-body` od `80rem`
rezerwowała trzy kolumny (nawigacja, treść, szyna) na **każdym** ekranie
serwisu — nawet gdy żaden slot `rail` nic do trzeciej nie wkładał. To jest
świadomy koszt z 7 września, opisany w tym samym miejscu w `app.css`:
nawigacja ma stać w tym samym `x` na każdym ekranie, a alternatywą byłaby
siatka skacząca w bok zależnie od tego, czy dana podstrona akurat ma szynę.
Dla zwykłych ekranów serwisu (Powiadomienia, Ustawienia) ten koszt jest
niewielki — kolumna szyny na monitorze 1920 px to wąski pasek.

Na panelu moderacji ten sam koszt wygląda inaczej, bo panel nigdy w życiu
nie poda slotu `rail` — to narzędzie pracy moderatora, nie treść typu „Mój
zeszyt" czy „kuKINGi na dziś", którym szyna służy. Zmierzone na
`/admin/uzytkownicy`, okno 1280 px, **przed** poprawką: kolumna treści miała
576 px zamiast swoich zwykłych 720, bo sztywna kolumna szyny (22rem) obok
sztywnej kolumny nawigacji (15rem) ściskała środkową, elastyczną kolumnę —
a to jest dokładnie zgłoszenie właściciela, „treść wciśnięta w lewe ~55%
ekranu, po prawej duży pusty obszar".

**Decyzja:** `.app-body` dostaje `data-tryb-panelu` (ten sam atrybut co
`<nav class="side-nav">` obok), a reguła w bloku `80rem` czyta go i zwraca
panel do dwóch kolumn — bez `var(--container-rail)`. Zwykłe ekrany serwisu
**zachowują** trzecią, zarezerwowaną kolumnę bez zmian; to nie jest cofnięcie
decyzji z 7 września, tylko wyjątek dla jedynego miejsca, które nigdy nie
skorzysta z tego, za co ta kolumna płaci.

**Czego to NIE rozstrzyga:** kolumna czytania (`--container-content`,
45rem) zostaje wszędzie, panel włącznie — „szerokość strony ma być
identyczna na każdej podstronie" to osobna, wcześniejsza decyzja właściciela
i ta zmiana jej nie rusza. Szeroka tabela kont dalej przewija się we własnym
kontenerze (`.tabela-kont-przewijanie`), nie rośnie do pełnej szerokości
ekranu.

### 2. Z dwóch „Wróć do Kuking" na szerokim telefonie zostaje dolne

**Stan sprzed zmiany:** poniżej `64rem` panel pokazuje wyjście w dwóch
miejscach naraz — na górze pionowej listy menu (`.side-nav-powrot`) i w
stałym pasku dolnym (`.bottom-nav-panel`, `position: fixed`). To jest
zamierzone i ma własny test (`TrybPaneluWMenuTest`): na wąskim telefonie
(320–414 px) obie pozycje rzadko widać jednym spojrzeniem, bo trzeba
przewinąć stronę, żeby dojść od jednej do drugiej. Na szerokim, rozłożonym
telefonie obie mieszczą się w jednym kadrze bez przewijania — i to czyta się
jak pomyłka, nie jak zamierzona redundancja.

**Decyzja:** znika górny odnośnik, zostaje dolny — **wyłącznie** w paśmie
`48rem`–`63.999rem` (768–1023 px), czyli dokładnie w przedziale z opisu
zgłoszenia („Fold rozłożony ląduje między 768 a 1280"). Poniżej `48rem`
zachowanie z `TrybPaneluWMenuTest` zostaje nietknięte — oba odnośniki dalej
są. Od `64rem` górny odnośnik jest jedynym wyjściem (pasek dolny znika tam
niezależnie, regułą sprzed tej zmiany), więc pasmo dolne tej reguły musi
kończyć się dokładnie na progu, na którym zaczyna działać nawigacja
desktopowa.

**Dlaczego zostaje dolny, nie górny:** uzasadnienie górnego odnośnika
(„musi być widoczny bez przewijania") spełnia pasek dolny **lepiej**, bo jest
`position: fixed` — widoczny na każdej szerokości telefonu, niezależnie od
tego, gdzie w danej chwili jest przewinięta strona. Usunięcie odwrotne
(zostaje górny, znika dolny) zdjęłoby z panelu jedyne wyjście, które nie
zależy od pozycji przewijania.

**Realizacja jest czysto wizualna:** `display: none` w CSS, znacznik HTML
zostaje bez zmian. Dzięki temu żaden test czytający treść strony (w tym
`TrybPaneluWMenuTest::test_w_trybie_panelu_widac_powrot_do_serwisu`, który
sprawdza obecność OBU odnośników w HTML-u) nie wymagał poprawki — sprawdza
znacznik, nie to, co akurat pokazuje arkusz stylów przy danej szerokości.

**Zmiana wymaga:** przy każdej przyszłej zmianie progu `64rem` (granicy
między trybem telefonu a trybem desktopowym panelu) — dopasowania górnej
granicy tego pasma (`63.999rem`) w tej samej regule, inaczej powstanie
przerwa albo zakładka pasm.

**Pliki:** `resources/css/app.css` (`.app-body[data-tryb-panelu]`,
`.side-nav[data-tryb-panelu] .side-nav-powrot`) ·
`resources/views/components/layout.blade.php` ·
`tests/Feature/PanelSzerokiTelefonTest.php`
