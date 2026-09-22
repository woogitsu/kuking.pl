# Uzupełniający odbiór #434 — awatary, spacja i pozycja kafla

## Aktualizacja odbioru — 14 września 2026

Pakiet PR #540 jest scalony i wdrożony jako `4c537b2`. CI PR i main: 10/10 success; Railway 6439273567 i Deploy 34859662013: success. [Potwierdzony odbiór produkcji Alfa 0.27](ODBIOR_PRODUKCJI_ALFA_027.md) rozdziela ogląd produkcji od lokalnych prób oraz opisuje ograniczenia. Poniższe informacje o przygotowaniu i pierwszym CI są historią prac, nie bieżącym statusem.


Wykonano lokalnie, na Chromium153.0.8010.12, w kopii wykonawczej kuking-moderacja-537 i własnej bazie kuking_port_nav492_final (127.0.0.1:55439). Mailer array. Bez kont produkcyjnych, bez zapisów produkcji, bez zmian canonical, runnerów ani CI. Źródła obejmują końcową nawigację #492 oraz app.css #539. Nie uruchamiano pełnego PHP ani całego portu.

## Wynik awatarów

**160 pomiarów kontrolowanego komponentu i72 pomiary na rzeczywistych trasach: PASS.** Każdy zestaw wykonano przy szerokościach320 i390px, w obu motywach i przy tekście100% oraz140% (osiem wariantów).

Kontrolowana próbka renderuje prawdziwy x-avatar i prawdziwy x-layout przez Blade, ze skompilowanym CSS aplikacji. Dla każdego rozmiaru32/40/44/48/52/56/64/88/120/128 sprawdzono osobno IMG z istniejącym demonstracyjnym plikiem Media oraz SPAN z inicjałem. Wszystkie miały nominalną szerokość i wysokość, stosunek1:1; pliki IMG zostały rzeczywiście zdekodowane. To jawnie oznaczona próbka komponentu, nie nowa trasa ani fikcyjna funkcja produktu.

| Żądany rozmiar | Rzeczywista trasa / kontekst | Wynik rzeczywisty |
|---:|---|---|
|32|własny wpis, odpowiedź na komentarz|IMG32×32|
|40|własny wpis, komentarz|IMG40×40|
|44|szczegóły przepisu, autor|SPAN44×44|
|48|powiadomienia, zwykłe zdarzenie|SPAN48×48|
|52|własny wpis dla obserwujących|IMG52×52|
|56|lista obserwowanych|SPAN56×56|
|64|ustawienia profilu|IMG64×64|
|88|ustawienia zdjęcia profilowego|IMG88×88|
|120|tablica na stronie odkrywania|SPAN52×52 — świadome nadpisanie aktualnego portu marki|
|128|brak wywołania w aktualnych widokach|wyłącznie kontrolowany komponent: IMG/SPAN128×128|

Dla52px rzeczywista karta zawiera jednocześnie raster awatara, nazwę „Małgorzata Konstantynopolitańczykowianka” i plakietkę „Tylko dla obserwujących”. Kwadratowość potwierdzono w każdym z ośmiu wariantów, także320px/140%. Nie zastępowano zdjęcia inicjałem. Plik demonstracyjny jest rasterem używanym przez DemoSeeder, nie zdjęciem rzeczywistego użytkownika.

Rozmiaru120px nie opisujemy jako obecnego120×120 na produkcyjnej tablicy: aktualny CSS celowo ustawia tam52×52. Jego nominalne120×120 i niewykorzystywane obecnie128×128 pokrywa osobna kontrolowana próbka. W aktualnym profilu używany jest rozmiar170px; nie był on dodatkowo badany w tym ograniczonym odbiorze historycznej listy #434.

## Bezpośrednia delta kafla

Zmierzono y rzeczywistego elementu marka-publikacja i linku composer na /home przy dwóch nieprzeczytanych powiadomieniach. Następnie fizycznie usunięto z prawdziwego marka-rama.css wyłącznie nową regułę padding-inline4px Powiadomień/Konta i przebudowano CSS. To odtwarza oryginalne paddingi16px/8px, a nie przybliżoną atrapę. Pozostałe reguły nawigacji i dane były takie same.

Wyniki obu motywów są zgodne:

|Szerokość / tekst|y przed zmianą|y po zmianie|Przesunięcie w górę|
|---|---:|---:|---:|
|320px /100%|511,859375|511,859375|**0px**|
|320px /140%|697,968750|697,968750|**0px**|
|390px /100%|511,859375|451,968750|**59,890625px**|
|390px /140%|608,531250|608,531250|**0px**|

Delta linku composer jest identyczna z deltą całego kafla. To pomiar pozycji, nie wniosek z wysokości nagłówka. Przy320px i tekście140% pełne etykiety nie mieszczą się obok, więc pozostają oddzielne rzędy. Nie deklarujemy zysku pionowego tam, gdzie wynosi zero. Sens zmiany: odzyskany rząd w typowym390px/100%, a w niskim widoku dostęp do treści zapewnia odrębna reguła przewijania obu pasków. Nie ścieśniano pisma ani nie ukrywano etykiet.

## Widoczna spacja

Ponowiony pomiar Range obejmuje rzeczywiście ułożony końcowy znak odstępu w tab-napis na /home:4,265625px przy100% i5,984375px przy140%, przy320/390px i w obu motywach. Test wymaga dodatniej szerokości widocznego odstępu, nie tylko obecności spacji w Blade. Nie jest to ponowny przegląd wszystkich tekstów marki w stopce lub innych komponentach.

## Odtworzenie i granice

Kopia CSS poza repo: /tmp/kuking434-fT4T1v/marka-rama.css, wykonana cp-p. Po mutacji przywrócono dokładneMD5 **a669e604c7f8e83fdf0932095f52c0f6** i mtime **1789391203254.2002ms**. Po odbudowie wykonano dodatni pomiar wszystkich ośmiu pozycji kafla i porównano je z początkowym wynikiem. Proces zakończył się kodem0.

Fixture zapisuje snapshot poza repo przed commitem transakcji; po odbiorze odtwarza nazwę profilu i wszystkie wcześniejsze read_at, weryfikuje ich zgodność oraz usuwa tylko własne powiadomienia, wpis i komentarze. Wygenerowany lokalny HTML próbki usunięto. Bez formularzy publikacji, odwołań ani DELETE przez HTTP. Serwer i przeglądarka po odbiorze zamknięte.

Początkowy zapis wyników miał kolizję nazwy pola width (viewport/awatar). Poprawiono jedynie metadane skryptu na viewportWidth i powtórzono cały ograniczony pomiar; końcowy JSON zawiera oba wymiary oddzielnie. Nie poprawiano wyników ręcznie.

## Artefakty i możliwość ponowienia

- Końcowy JSON i zrzuty: folder wyniki, plik wyniki.json.
- Pełny log: odbior434-odebrane.log.
- Dodawalny skrypt: awatar-kafel434.mjs; uruchamiać z katalogu głównego kopii repo po wskazaniu jawnej lokalnej bazy i CHROMIUM_PATH.
- Fixture: awatar-kafel434.php; docelowa lokalizacja scripts/fixtures/awatar-kafel434.php. Wyłącznie local/testing, lokalny PostgreSQL z prefiksem kuking_port, port zgodny z DB_PORT i mailer array.
- Obejrzano zrzuty awatara52 przy320px/140% oraz kafla po zmianie przy390px/100%. Root może niezależnie porównać kafel-przed-390.png i kafel-po-390.png.

Oba wskazane braki lokalnego odbioru są domknięte, z jawnym ograniczeniem kontrolowanych120/128 oraz zerowej delty320/140%. Zamknięcie #434 powinno dodatkowo uwzględniać odbiór właściwego wdrożenia. Nie deklarujemy pełnegoWCAG ani kontroli wszystkich ekranów na podstawie tej próbki.

## Korekta sprzątania po niezależnym review

Wcześniejszy odbiór odtworzył nazwę profilu i read_at, ale NIE zachował wcześniejszego updated_at profilu. Nie można więc przypisać mu dokładnego odtworzenia całej historii rekordu. Poprzedniej wartości czasu nie rekonstruowano. Nowy fixture zapisuje i przywraca surowy updated_at bez automatycznej zmiany timestampu.

Próbka HTML ma teraz losową, zapisaną w stanie nazwę i powstaje przez wyłączne utworzenie pliku (tryb x). Nie nadpisuje stałego public/audyt-awatar434.html. Nieudane przygotowanie wycofuje transakcję i usuwa własną próbkę. Istniejąca kopia stanu powoduje odmowę nadpisania. Zagnieżdżony finally gwarantuje próbę sprzątania PHP także po błędzie browser.close.

Celowane kontrole zakończone sukcesem: wymuszony błąd zapisu snapshotu (brak katalogu), odmowa istniejącego snapshotu, udane utworzenie i odtworzenie pełnego rekordu profilu (łącznie z updated_at), liczby wpisów/powiadomień/komentarzy oraz usunięcie własnego HTML. Osobno wykonano prawdziwy końcowy blok JS z symulowanym błędem browser.close: cleanup został wywołany. W trakcie kontroli wykryto i poprawiono użycie klucza profiles.id na rzeczywisty profiles.user_id; następnie przywrócono pozostawioną próbkę i powtórzono cały celowany zestaw z sukcesem.

Nie powtarzano pomiarów geometrii, nie uruchamiano przeglądarki ani pełnego PHP. Te kontrole dotyczą sprzątania, nie awarii procesu SIGKILL. Finalne dwa pliki znajdują się także w scripts/awatar-kafel434.mjs i scripts/fixtures/awatar-kafel434.php własnego worktree; przeznaczone do ręcznego odbioru, bez podpięcia CI.

W repo zachowano ręczny automat `scripts/awatar-kafel434.mjs` oraz fixture `scripts/fixtures/awatar-kafel434.php`. Końcowy JSON i porównawcze zrzuty znajdują się w `docs/design/evidence/434/`. Pełne logi lokalne nie są odbiorem CI.
