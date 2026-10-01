## D-172 · Menu „więcej" na karcie wpisu to same trzy kropki — nazwany wyjątek od „ikona nigdy sama"

**Data:** 12 września 2026 · PR #441 · Status: **obowiązuje**

### Kontekst

11 września do przycisku menu na karcie wpisu dołożyliśmy widoczny napis „Więcej",
bo `AGENTS.md` §5 mówi: **ikona nigdy sama**. Dzień później właściciel poprosił
o odwrotne: „Jak jest wpis to te «… Więcej» można skrócić do samych trzech kropek?
Seniorzy są przyzwyczajeni do tego na fb itp".

### Decyzja

Przycisk `<details class="post-card-menu">` nie ma widocznego napisu. **To nie jest
cofnięcie poprzedniej decyzji przez zapomnienie — to zmiana reguły, zapisana
w `AGENTS.md` §5 i w `docs/UX_50_PLUS.md` jako nazwany wyjątek.**

### Powód

Nie estetyka, tylko **rozpoznawalność**. Reguła ogólna mówi o ikonie, której trzeba
się **domyślić**. Nasi ludzie przyszli z Facebooka i spędzili tam lata; trzy kropki
w rogu wpisu są dla nich znakiem już znanym. Domyślania tu nie ma.

### Ryzyko przyjęte świadomie

Za tym menu stoją „Edytuj wpis" i „Usuń wpis". `docs/UX_50_PLUS.md` wymienia wzorzec
„`♡ ⋮ ↗` bez podpisów" jako słaby i **ten argument pozostaje prawdziwy**. Właściciel
dostał go wprost przed decyzją i zdecydował inaczej.

### Granica

Wyjątek dotyczy **wyłącznie tego jednego menu**. Nie obejmuje paska akcji pod wpisem,
pasków nawigacji, przycisków zamykania ani akcji moderacyjnych. Rozszerzenie wymaga
osobnej decyzji i osobnego wpisu, nie dopisania klasy CSS.

### Czego wyjątek nie zabiera

`aria-label="Więcej przy tym wpisie"` zostaje i jest jedyną nazwą dostępną tego
przycisku. Cel dotknięcia zostaje 48 × 48 px. Menu dalej otwiera się bez
JavaScriptu (`<details>`). Kropki rysuje komponent ikony, a **nie znak `···`
z klawiatury** — to jest różnica wobec stanu sprzed 11 września, gdy kropki były
schowane przed czytnikiem ekranu i nikt nie dostawał ani znaku z podpisem, ani
podpisu ze znakiem.

### Zmierzony skutek uboczny

Przycisk: **125,6 → 48 px** (czcionka 100%) i **225,1 → 96 px** (czcionka
przeglądarki 200%). Główka karty w najgorszym z dziewięciu przypadków: przy 320 px
**18 → 8 wierszy**, przy 414 px **8 → 5**. Przy 200% kolumna z nazwą i datą miała
przed zmianą **0 px szerokości** — jednakowo na 320, 360, 390 i 414 px, bo ten jeden
przycisk zjadał całą kartę.

📄 `resources/views/components/post-card.blade.php` · `AGENTS.md` §5 ·
`docs/UX_50_PLUS.md` · `KartaWpisuTest::test_menu_karty_to_same_kropki_ale_czytnik_ekranu_nie_traci_nic`
