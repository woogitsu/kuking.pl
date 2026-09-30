## D-186 · Pomiar zmienia stan DOM-u przed motywem, nie po nim

**Data:** 12 września 2026 · PR #461 · issue #454 · Status: **obowiązuje**

### Objaw

Automat dostępności meldował raz na kilkaset przebiegów `[serious] color-contrast`
na ekranie usuwania konta, przy **nietkniętej palecie**. Ze złapanego wystąpienia
(wariant ciemny, 1280 px):

    tekst  #2b241d   ← --color-ink z motywu JASNEGO
    tło    #1e1a16   ← --color-surface z motywu CIEMNEGO
    kontrast 1.13:1  przy wymaganym 4.5:1

Ta para nie występuje w żadnym motywie. Pomiar zestawiał tekst sprzed przełączenia
z tłem po przełączeniu. Wszystkie cztery węzły tego naruszenia leżały wewnątrz
`<details>`, ani jeden poza nim.

### Przyczyna

Zamknięty `<details>` jest w Chromium poddrzewem pominiętym w przeliczaniu stylu.
Zmiana `data-theme` na `<html>` go nie dotyka, a `getComputedStyle` nie wymusza
przeliczenia. Dopóki otwarcie `<details>` stało tuż przed `analyze()`, axe czytał
z tego poddrzewa kolory sprzed przełączenia motywu.

### Decyzja

Każda zmiana stanu DOM-u, która odsłania poddrzewo, idzie **przed** `data-text-scale`
i `data-theme`, a po niej automat czeka na pełny obieg klatki. Palety nie ruszamy —
poprawka jest w kolejności, nie w kolorach.

Zmierzone, 100 powtórzeń na `/ustawienia/twoje-dane`: motyw, potem `<details>` —
rozjazd pary tekst/tło **100 na 100**; `<details>`, potem motyw — **0 na 100**.

### Wyścig szedł też w drugą stronę

Migotanie widać było jako fałszywą czerwień, więc rzucało się w oczy. Ta sama kolejność
po cichu **przepuszczała** naruszenia: przeczytany kolor sprzed przełączenia bywa
zgodny, choć po przełączeniu zgodny nie jest. Fałszywa zieleń nie melduje o sobie nigdy.

### Kontrola ujemna złapała błąd w drugim teście

Test „czeka na obieg klatki po otwarciu `<details>`" brał wycinek źródła od otwarcia do
`wlaczSkaleTekstu`. Przy sabotażu przenoszącym otwarcie na koniec pętli ta druga kotwica
stoi **wcześniej**, długość wychodzi ujemna, a `substr` zwraca wtedy kawałek liczony od
końca pliku — test przechodził, mierząc nie to miejsce (pułapka 3b).

📄 `scripts/dostepnosc.mjs` · `PomiarDostepnosciOtwieraDetailsPrzedMotywemTest` ·
`docs/PULAPKI_TESTOW.md` · D-132
