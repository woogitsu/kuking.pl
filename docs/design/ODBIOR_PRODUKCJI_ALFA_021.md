# Odbiór produkcji — Alfa 0.21

14 września 2026, około 01:38 czasu Warszawy. Kod:
`ae6b519cd5b7ccf68bc43e7b2e61ff46d54de8c2`, merge PR #520.
Head PR: `c2a05d015ba6e197f3be5bb3334864c3967ec582`.

## Dowód wdrożenia

- CI PR34788400687 oraz main34789154494: wszystkie dziesięć zadań success.
- Railway6427633492: success od 2026-09-13T23:36:17Z dla pełnego SHA powyżej.
- Właściwy Deploy34790184134: success. Wstępny34789158280 był skipped.
- Rzeczywisty GET produkcji HTTP200: „Alfa 0.21”, „wydanie14września2026,01:35 · ae6b519”.
- Zalogowany Chrome na `/ustawienia/bezpieczenstwo` pokazał tę samą metryczkę.

CSS `app-B59Zb5HG.css` HTTP200, SHA256
`9f0d3bf70965c52d4fda1c15a3baf2915f249e3ca8a4020bcedce0da81e749cf`.
JS `app-DXNAnudp.js` HTTP200, SHA256
`e94fdd3ddd8d45e79644a222e092c5a0b80d3a21f4941d8af8684425a4749d75`.
Lokalne fonty Inter latin-extDO1Apj_S (85068b) oraz latinDx4kXJAl (48256b)
odpowiedziały200 z typemfont/woff2. Te same hashe co0.20 są prawidłowe:
0.21 zmienia treści PHP/Blade, bez zmiany źródeł CSS/JS.

## Co rzeczywiście obejrzano

Odczyt i zrzut zalogowanej strony bezpieczeństwa, desktop/jasny motyw:
instrukcja wejścia kontem Facebooka mówi o użyciu przycisku i przekierowaniu,
bez obietnicy jednego kliknięcia. Formularze i boczna nawigacja są widoczne.
Nie wysyłano formularzy, nie zmieniano hasła, nie łączono kont, nie
wylogowywano innych urządzeń. Zamknięto własną kartę odbioru.

Nie odtworzono produkcyjnego pełnego OAuth, wysyłki maila automatu ani
kolejki ze starym zadaniem. Te zakresy mają dowód lokalnych testów opisany
w PRECYZJA_KOMUNIKATOW_514.md, nie pozorny odbiór produkcyjny.
Nie rozszerzamy desktopowego oglądu na wszystkie szerokości i motywy.
Pełna kompletność marki pozostaje CZĘŚCIOWO.


## Dodatkowy odbiór Startu — Alfa 0.21

14 września 2026. Zalogowany Chrome, własna karta GET /home, desktop i jasny motyw. Metryczka: Alfa 0.21 / ae6b519. Obejrzano zrzut przed i po reload.

Widoczna nowa kompozycja: duży nagłówek „Dzień dobry” z prawdziwym imieniem konta, ciemna karta dodawania z okręgiem, krótka instrukcja, ciemna karta „Z innych kuchni” i osobne białe karty osób oraz dań. Zachowany znak garnka, prawdziwe treści, znaczniki widoczności i zakładki strumienia. Nie skopiowano przykładowych osób/liczb z makiety.

Nie odtworzono ramki nagłówka po wejściu i reload. To obserwacja tej ścieżki, nie ustalenie przyczyny dawnego objawu #518. Nie zmieniono fokusu aplikacji. Nie wykonywano POST, nie zapisywano wpisów, nie obserwowano kont i nie zmieniano ustawień. Własną kartę zamknięto. Nie zapisano prywatnych treści ani zrzutów konta do repo.

Ten odbiór nie obejmuje mobile, ciemnego motywu ani wszystkich stanów strumienia.
