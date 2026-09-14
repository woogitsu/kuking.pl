# Odbiór Alfa 0.28 — OAuth i instrukcje Facebooka

## Kod i zakres

PR [543](https://github.com/woogitsu/kuking.pl/pull/543), sprawdzony head **795dc2f9a5257143a6aa18d02a1c2266c5fa7ad9**, scalony jako **5fd45db896f07e0bb415b31856c5a43fbbb1c742**. Nie zmieniono mechaniki OAuth, danych kont ani uprawnień. Poprawiono instrukcje Facebooka i dodano pomiar pięciu końcowych stanów z kontrolą kompletności raportu.

CI PR [34864822658](https://github.com/woogitsu/kuking.pl/actions/runs/34864822658): 10/10 zadań success; pełny PHP 3802 testy /76343 asercje. OAuth10/10, główne axe44/44 i układ49/49. Wykonano niezależny review ostatniej poprawki oraz ponowny lokalny odbiór dokładnego źródła. Macierz, negatywy i ograniczenia: [EKRANY_OAUTH_345.md](EKRANY_OAUTH_345.md).

## Wdrożenie

[CI main34877108365](https://github.com/woogitsu/kuking.pl/actions/runs/34877108365) zakończony sukcesem wszystkich 10 zadań. Log PHP main potwierdza 3802 testy /76343 asercje, nie są to liczby przepisane wyłącznie z PR.

Publiczny HTTP po przełączeniu: **Alfa 0.28, wydanie 14 września 2026, 20:13, SHA5fd45db**. To odczyt działającej strony, a nie wniosek z sukcesu CI.

| Zasób produkcyjny | Wynik |
|---|---|
| Strona główna | HTTP200, Alfa0.28 /5fd45db |
| CSS `app-BhNKF9Y-.css` | HTTP200; SHA256 `cd85131fae3d9dcda461b9e9cc6133387dd5da652a69045e061c730536c3b59a` |
| JS `app-DXNAnudp.js` | HTTP200; SHA256 `e94fdd3ddd8d45e79644a222e092c5a0b80d3a21f4941d8af8684425a4749d75` |
| Inter latin-ext | HTTP200, font/woff2, 85068 bajtów |
| Inter latin | HTTP200, font/woff2, 48256 bajtów |

Nazwy i sumy assetów są takie jak w Alfa0.27. Ten pakiet zmienia treść Blade i pomiary; nie zmienia produkcyjnego CSS/JS. Ich niezmienione adresy przy nowym SHA strony nie są dowodem starego wdrożenia.

Railway6443031526 dotyczy dokładnego SHA5fd45db w środowisku `ideal-exploration / production`. Początkowy Deploy34877115716 został skipped i nie jest dowodem sukcesu. Końcowy status zostanie dopisany przed wysłaniem raportu.

## Granice

Pięć końcowych ekranów obejrzano lokalnie w rzeczywistej aplikacji po rzeczywistym starcie/callbacku, z atrapą jedynie transportu dostawcy. Nie wywoływano testowego transportu na produkcji, nie zakładano ani nie łączono tam kont. Nie wykonano odbioru prawdziwych usług Google/Facebooka. Tekst140% nie jest zoomem przeglądarki200%. Pełny port marki pozostaje **CZĘŚCIOWO**.
