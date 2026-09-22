# Dowody — `#tag` w treści wpisu jest odnośnikiem (issue #737)

Co tu leży i jak to powstało. Wszystko zmierzone lokalnie, na izolowanym
klastrze PostgreSQL i izolowanych bazach zadania. Produkcji nie dotykano.

## 1. Kontrole ujemne — `scripts/kontrola-ujemna.sh`

Pięć mutacji, każda z osobnym plikiem JSON. Werdykt `POTWIERDZONA` znaczy
pełny przebieg `PASS → FAIL (z oczekiwanego powodu) → PASS`, z dowodem MD5,
że mutacja naprawdę weszła.

| Plik | Co zepsuto | Który test miał oblać |
|---|---|---|
| `a-mapa-adresow.json` | widok przestaje podawać mapę adresów do `LinkiWTekscie::render()` | `test_tag_przypiety_do_wpisu_jest_odnosnikiem_w_tresci` |
| `b-adres-z-tekstu.json` | adres liczony z samego tokenu, a nie z relacji wpisu | `test_slowo_z_kratka_ktore_nie_jest_tagiem_wpisu_zostaje_tekstem` |
| `c-ucieczka-html.json` | tekst przed odnośnikiem przestaje przechodzić przez `e()` | `test_html_autora_pozostaje_tekstem_obok_odnosnika_do_tagu` |
| `d-wspolne-wyrazenie.json` | renderowanie przestaje pytać `InlineTagTokens` | `test_tag_przypiety_do_wpisu_jest_odnosnikiem_w_tresci` |
| `e-skrocona-zapowiedz.json` | feed renderuje całą treść zamiast zapowiedzi | `test_tag_poza_skrocona_zapowiedzia_nie_jest_w_niej_odnosnikiem` |

**Jedna pułapka warta zapamiętania.** Przyrząd przywraca plikowi nie tylko
bajty, ale i **mtime sprzed przebiegu**. Przy mutacji pliku `.blade.php`
znaczy to, że Blade uznaje skompilowany (zmutowany!) widok za aktualny
i NASTĘPNY przebieg mierzy kod, którego już nie ma w źródle. Pierwsza próba
z tego powodu dała fałszywe `BRAK_KONTROLI_DODATNIEJ`. Polecenie testowe
musi więc czyścić `storage/framework/views` przed uruchomieniem.

## 2. Ogląd dostępności — `scripts/tag-w-tresci.mjs`

`ogled-dostepnosci.txt` — osiem wariantów (motyw jasny i ciemny × 320 px,
320 px przy skali tekstu 140%, 360 px przy czcionce przeglądarki 200%,
1280 px). Kod wyjścia 0.

- axe-core (`wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22aa`): **0 naruszeń**
  w każdym wariancie;
- przewijanie w bok: **0 px** w każdym wariancie;
- fokus z klawiatury: `3px solid rgb(21, 94, 239)` (`--color-focus`), odsunięcie
  2 px, w obu motywach; w trybie wymuszonych kolorów obwódka idzie po `Highlight`;
- pole dotknięcia, **skuteczne / nominalne**: 38–46 / 45,8 px bez powiększania,
  44–64 / 63,9 px przy tekście 140%, 62–77 / 91,6 px przy czcionce przeglądarki
  200%. Sam wiersz tekstu ma 31,4 px.

**Dlaczego „skuteczne", a nie po prostu `getClientRects()`.** Wcięcie pionowe
elementu liniowego nie podbija wysokości wiersza, więc prostokąt odnośnika
wystaje ponad i pod wiersz — a ta wystająca część bywa przykryta przez wiersz
następny i kliknięcie w nią NIE trafia w odnośnik. Liczba nominalna kłamałaby
o kilka pikseli w górę. Skrypt skanuje `elementFromPoint` co piksel i liczy
ciągły pas, który naprawdę należy do odnośnika.

**Czego automat `scripts/dostepnosc.mjs` nie zmierzył.** Pełny przebieg na 47
ekranach przeszedł (0 naruszeń, 0 przepełnień w poziomie), ale **ani jeden
wpis w `DemoSeeder` nie ma hashtaga w treści**, więc tego elementu nie widział.
Stąd osobny skrypt i osobny fixture — zielony przebieg, który niczego nie
ogląda, jest gorszy niż brak przebiegu, bo wygląda jak dowód.
