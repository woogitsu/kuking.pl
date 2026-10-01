## D-157 · Dokument, który cytuje regułę z kodu, jest sprawdzany testem — a wariant odrzucony zostaje w nim JAWNIE

**Data:** 11 września 2026 · PR #404 (naprawa nieprawdy wniesionej przez #398) ·
Status: **obowiązuje** · rozwinięcie D-119

### Co stało w dokumencie obowiązującym

`docs/brand/GLOS_MARKI.md` §2, punkt 3 podawał jako regułę zapisu nazwy:

> „**`white-space: nowrap`.** Słowo nie łamie się między „ku" i „KING" — bez
> tego przy 320 px i czcionce 200% jedyny nośnik tej gry rozpadałby się na dwa
> wiersze."

Arkusz w tej samej chwili deklarował `overflow-wrap: anywhere`, a komentarz nad
tą regułą mówił wprost, że `nowrap` był **pierwszą wersją i OBLAŁ skan
dostępności**: na `/register` przy oknie 320 px i czcionce przeglądarki 200%
samo słowo brało **315 px** zaczynając od x = 32, czyli strona przewijała się
w bok o **27 px** — naruszenie WCAG 2.2 AA (1.4.10 Reflow).

### Dlaczego to groźniejsze niż zwykły nieaktualny akapit

Dokument nie był po prostu stary. **Podawał jako obowiązującą dokładnie tę
wersję, którą pomiar odrzucił, i podawał razem z nią jej uzasadnienie.**
Następna osoba, porządkując arkusz „zgodnie z dokumentacją", przywróciłaby
`nowrap` i zepsuła Reflow — nie z niedbalstwa, a **czytając wiążący dokument**.

To trzeci przypadek tej klasy w tym repozytorium. Dwa pierwsze to numery
decyzji, których nie napisano (stąd `NumeryDecyzjiMajaWpisyTest`); trzeci to
wiersz tabeli stacku obiecujący Sentry'ego (D-104). Każdy raz ten sam
mechanizm: **zapis wyglądał na odpowiedź, więc nikt nie szukał dalej — a
szukając, znalazłby coś innego.**

### Decyzja, trzy części

1. **Kod jest stroną prawdziwą.** Przy rozjeździe dokumentu z arkuszem
   poprawiamy dokument, a kod zostaje nietknięty — chyba że przegląd wykaże,
   że to kod jest zły, i wtedy to osobna zmiana, nie „przy okazji".
2. **Dokument, który cytuje regułę z kodu, ma test porównujący jedno
   z drugim.** Nie „nie cytujmy reguł" — D-119 tego wymaga tam, gdzie plik
   tylko odsyła, ale tu dokument **jest** miejscem uzasadnienia i musi podać,
   czego uzasadnia. **Cytat bez testu starzeje się cicho; cytat z testem
   starzeje się na czerwono.**
3. **Odrzucony wariant zostaje w dokumencie**, jawnie, razem z pomiarem.
   Usunięcie zostawiłoby regułę bez powodu, a reguła bez powodu jest następnym
   kandydatem do „uproszczenia". Konsekwencja dla testu jest konkretna:
   **zakaz nie idzie na wystąpienie napisu w dokumencie, tylko na to, co
   dokument podaje jako REGUŁĘ** — inaczej strażnik kazałby usunąć zdanie,
   które jest najcenniejsze w całym punkcie.

### Strażnik pilnuje OBU stron, bo jedna nie wystarcza

`tests/Feature/GlosMarkiOpisujeArkuszPrawdziwieTest.php` (3 testy): obietnica
z dokumentu nie może być zakazem łamania, musi stać naprawdę w regule
`.kuking-word`, a arkusz nie może zadeklarować `white-space: nowrap`,
`word-break: keep-all` ani `overflow-wrap: normal`.

Sprawdzenie samego dokumentu złapałoby połowę. **Druga połowa — cofnięcie
ARKUSZA — zostawiłaby dokument prawdziwym, a produkt przewijający się w bok.**

Dwa szczegóły, bez których ten test świeciłby na zielono z niewłaściwego
powodu: arkusz czytany **po wycięciu komentarzy** (nazwa `.kuking-word` pada
w nich wielokrotnie, a komentarz nad właściwą regułą cytuje w środku **oba**
warianty — pułapka z `MinimalnyRozmiarTekstuTest`) oraz dopasowanie po **całym**
selektorze (`.kuking-word strong` stoi w pliku wyżej, więc szukanie nazwy
„gdzieś w liście selektorów" zwraca kolor zamiast łamania wyrazu).

Kontrola ujemna w obie strony, każdy sabotaż **odczytany z pliku po nałożeniu**:
`nowrap` w dokumencie → 2 z 3 czerwone; `nowrap` w arkuszu → 2 z 3;
`word-break: keep-all` w dokumencie → 2 z 3; obietnica usunięta → 1 z 3
(kontrola dodatnia parsera). **Dwa sabotaże nie nałożyły się za pierwszym razem
i test wtedy przechodził** — złapane tylko dlatego, że każdy był czytany
z pliku i porównywany przez md5, a nie zakładany. To ta trzecia z czterech
przyczyn nieoblanej kontroli ujemnej: sabotaż się nie wykonał.

### Zakres

`docs/brand/COPY_STYLE.md` przeszukany pod tym samym kątem: **nie podaje żadnej
reguły CSS**, a jego twierdzenie o `aria-label` na `<span>` zgadza się
z komponentem. Jest jednak na liście skanowanych dokumentów, bo nosi **drugą
kopię** sekcji o zapisie nazwy — a kopia jest miejscem, w którym taka nieprawda
odrasta (README i tabela stacku, D-104).

📄 `docs/brand/GLOS_MARKI.md` §2 ·
`tests/Feature/GlosMarkiOpisujeArkuszPrawdziwieTest.php` ·
`resources/css/app.css` (nietknięty, strona prawdziwa) · D-119 · D-104 · D-145
