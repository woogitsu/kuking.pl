## D-182 · Odstęp pod podpisem należy się podpisowi, nie jego podpowiedzi

**Data:** 12 września 2026 · PR #460 · issue #445 · Status: **obowiązuje**

### Co było nie tak

Odstęp między podpisem pola a ramką pola dostawała w praktyce **podpowiedź**: 12 px
deklarowała reguła `.field-help + .field-input` (D-133), a pole bez podpowiedzi nie
dostawało nic własnego — zostawało mu 8 px z `label { margin-bottom }` w warstwie base.
Czyli **dokładnie tyle, ile dzieli podpis od jego własnego wyjaśnienia**. Rzecz
powiązana i rzecz odrębna stały w tej samej odległości, a pole bez podpowiedzi miało
odstęp **mniejszy** niż pole z podpowiedzią.

### Decyzja

Odstęp należy się **podpisowi** i jest ten sam niezależnie od tego, czy ktoś podpowiedź
dopisał. Deklaruje go blok dolny, jako `margin-top` (D-154), a warunkiem jest
sąsiedztwo (D-158): jedna deklaracja, dwa selektory — podpis albo podpowiedź stojąca
zaraz nad polem.

Pole z podpisem **i** podpowiedzią nie dostaje obu odstępów naraz, i wynika to
z kształtu reguły, nie z ostrożności autora widoku: bezpośrednim sąsiadem pola jest
albo podpowiedź, albo etykieta, nigdy oboje. Rytm pola z podpowiedzią zostaje 8 / 12 px.

Podpis schowany przed okiem nie dostaje nic (`:not(.visually-hidden)`). Na
`/admin/kuking-na-dzis` pole notatki jest podpisane wyłącznie dla czytnika ekranu,
a 12 px pustki pod napisem, którego nie widać, zsumowałoby się w liście wierszy
w ekran przewijania.

### Zmierzone

Chromium, „dół etykiety → góra ramki pola", pola **bez** podpowiedzi, na `/login`,
`/register`, `/ustawienia/profil`, `/ustawienia/bezpieczenstwo`, `/dodaj/zdjecie`
i `/dodaj/przepis`, przy 320 / 360 / 390 / 414 px — wszystkie ekrany i wszystkie
szerokości tak samo: **8 → 12 px**, a przy czcionce przeglądarki 200% **16 → 24 px**.
Pola **z** podpowiedzią: 8 / 12 px przed i po.

### Sprostowanie, bo liczba w komentarzu nie była prawdziwa

W kodzie stało „zmierzone 0 px, np. «Nowe hasło» na /ustawienia". Zmierzone jest 8 px,
nie 0, a „Nowe hasło" **ma** podpowiedź, więc było akurat jednym z pól z odstępem
12 px. Usterka jest realna, tylko dotyczy innych pól tego ekranu: „Obecne hasło"
i „Powtórz nowe hasło". Zła liczba w uzasadnieniu jest gorsza niż brak liczby —
wygląda jak pomiar i zatrzymuje sprawdzanie.

📄 `resources/css/app.css` · `OdstepPodPodpisemPolaTest` · D-133 · D-154 · D-158
