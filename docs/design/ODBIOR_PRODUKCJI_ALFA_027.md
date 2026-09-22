# Odbiór produkcji Alfa 0.27

## Stan i dowody

Pełny port identyfikacji: **CZĘŚCIOWO**. Ten odbiór dotyczy pakietu PR #540 i wymienionych poniżej ścieżek, nie wszystkich ekranów i stanów portalu.

- PR aplikacji: [#540](https://github.com/woogitsu/kuking.pl/pull/540).
- Sprawdzony head: `f5d387fc850f5daed0d66563d4104f64aeac00bb`.
- Scalony kod: `4c537b220af20c8efdd8cc109cc36dcbb46e3377`.
- CI PR [34853279729](https://github.com/woogitsu/kuking.pl/actions/runs/34853279729): 10/10 zadań success. PHP: 3799 testów, 76314 asercji. Dostępność: axe 44/44, układ 49/49, zero wykrytych naruszeń i przepełnień. Lighthouse: 8/8.
- Pierwszy CI `34850145604` był nieudany: błąd końcowego zapisu raportu karuzeli. Naprawiono go w drugim commicie; historia nie jest przedstawiana jako zielona.

Railway deployment **6439273567** zakończył się sukcesem **14 września 2026, 15:03:36 UTC**, dla `4c537b220af20c8efdd8cc109cc36dcbb46e3377`. CI main [34855902800](https://github.com/woogitsu/kuking.pl/actions/runs/34855902800): 10/10 zadań success. PHP ponownie: 3799 testów / 76314 asercji. Test po wdrożeniu, Deploy [34859662013](https://github.com/woogitsu/kuking.pl/actions/runs/34859662013): success. Wstępny Deploy 34855910323 był skipped; nie użyto go jako dowodu wdrożenia.

Publiczny HTTP i zalogowany Chrome pokazały **Alfa 0.27 / 4c537b2**. Odczytane assety:

- CSS `app-BhNKF9Y-.css`, HTTP 200, SHA256 `cd85131fae3d9dcda461b9e9cc6133387dd5da652a69045e061c730536c3b59a`.
- JS `app-DXNAnudp.js`, HTTP 200, SHA256 `e94fdd3ddd8d45e79644a222e092c5a0b80d3a21f4941d8af8684425a4749d75`.
- Oba lokalne pliki Inter (`latin` i `latin-ext`) zwróciły HTTP 200 i font/woff2.

Wyjaśniono różnicę wobec lokalnego CSS `app-DxHVMNxU.css`: pełny katalog wykonawczy zawiera więcej źródeł klas narzędziowych Tailwind. Powtórzono build z dokładnym zestawem plików kopiowanych przez etap assets w Dockerfile (resources, app, routes, konfiguracja i skrypt kontrastu). Wynik w `/tmp/kuking-assets027-_01chfbe` miał identyczną nazwę, długość i SHA256 jak produkcja. To nie stary cache ani nieaktualny CSS. Ponowny build zaliczył 72 pary kontrastu.

## Rzeczywisty odbiór w przeglądarce

Na zalogowanej produkcji, przy zwykłym tekście:

| Widok / stan | Wynik |
|---|---|
|Start 320×500, jasny|Górna belka zmieniła sticky na relative, dolna fixed na relative. Ich wysokości pozostały 187,36 i 95,44 px; treść można przewijać bez stałych nakładek. Brak poziomego przepełnienia.|
|Pięć dolnych linków 320×500|Start / Szukaj / Dodaj / Moje / Profil; około 55,8×77,44 px każdy.|
|Fokus Dodaj zdjęcie 320×500|Rzeczywiste Shift+Tab/Tab: focus-visible, przycisk cały widoczny na y=301,38–353,38 przy wysokości500; nagłówek przewinięty poza jego obszar. Zrzut obejrzany.|
|Start 390×844, jasny|Nagłówek sticky, dolna belka fixed. Pozycja kafla bez licznika powiadomień: y=322,61 px, taka sama jak przed wdrożeniem. Nie przypisujemy mu lokalnej delty scenariusza z dwoma powiadomieniami.|
|Przycisk podpowiedzi na własnym wpisie, 390×844, jasny i ciemny|Rzeczywisty fokus klawiatury; po zakończeniu animacji halo2px i zewnętrzny pierścień o spread5px. Obejrzano oba motywy. Kolory odpowiadają zmierzonej lokalnie parze kontrastu. Hover+zoom pozostają osobnym dowodem lokalnym/CI, nie zostały ponowione w tym odczycie produkcji.|

Podczas ustawiania wymiarów wykryto, że narzędzie przeglądarki stosuje rozmiar do aktywnej karty. Odrzucono próbę, w której strona miała nadal1680px, wyzerowano override i powtórzono pomiar w nowej karcie, potwierdzając rzeczywiste innerWidth/innerHeight. Nie zaliczono odczytu niewłaściwej karty.

Motyw przełączono rzeczywistym przyciskiem strony (POST dark, następnie POST light); przywrócono jasny wygląd i wyłączono nadpisanie wymiarów. Nie jest to twierdzenie o niezmienionych wszystkich metadanych konta. Nie publikowano, nie komentowano, nie usuwano ani nie zmieniano widoczności treści. Prywatnych treści i identyfikatora własnego wpisu nie zapisano w repo. Odstęp usuwania komentarzy oraz błędny zapis nazwy testowano na danych lokalnych, bez tworzenia produkcyjnych komentarzy lub błędnych kont.


## Co zmieniono

| Zgłoszenie | Zmiana / dowód | Granice |
|---|---|---|
|#434, #492|Warunkowe przewijanie obu belek w niskim widoku; powiadomienia i Konto w jednym rzędzie przy 390 px/100%|Przy 320 px oraz dużym tekście pełne etykiety mogą zajmować osobne rzędy. Lokalna delta kafla: 59,89 px przy 390/100%, zero w pozostałych badanych konfiguracjach.|
|#444|Systemowy odstęp przed usunięciem komentarza i odpowiedzi|Lokalne rzeczywiste karty, bez wykonywania DELETE na produkcji.|
|#538|Komunikat imienia pobiera faktyczny limit reguły walidacji|Bez zmiany limitu ani danych kont.|
|#539|Dwuwarstwowy fokus przycisków w podpowiedziach|88 wierszy pomiarów, pięć kontroli ujemnych CSS, cztery próby prawdziwego zoomu; niezależny Tab 14/14 także z hover w ciemnym motywie.|
|#431|Rzeczywista próbka pionowego i poziomego zdjęcia, kliknięcia oraz Tab/Enter bez JS|8 wariantów / 40 stanów; raport zachowuje także przerwane pomiary.|

Szczegóły i macierze: [pakiet Alfa 0.27](POPRAWKI_ISSUES_ALFA_027.md), [nawigacja](NAWIGACJA_NISKI_WIDOK_492.md), [awatary i pozycja kafla](ODBIOR_434_UZUPELNIENIE.md), [fokus](FOKUS_PODPOWIEDZI_539.md), [karuzela](KARUZELA_MIESZANA_431.md), [naprawa raportowania](RAPORTOWANIE_KARUZELI_431.md).

## Pozostałe ograniczenia

Ogląd 18 szablonów wiadomości obejmuje 36 renderów w Chromium przy 320/640 px, a nie rzeczywiste programy Gmail, Outlook lub Apple Mail. Nie potwierdzono fizycznej klawiatury ekranowej ani wszystkich kombinacji urządzeń. Próby produkcyjne nie publikowały, nie komentowały i nie usuwały danych. Pełna macierz pozostaje w [MACIERZ_KOMPLETNOSCI_517.md](MACIERZ_KOMPLETNOSCI_517.md).
