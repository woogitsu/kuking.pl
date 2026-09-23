# Uzupełnienie odbioru trybu gotowania — 14.09.2026

Źródło kanoniczne: 8e4da2f2ff51178df57d7dd118978e441b8c5130. Lokalny GET na http://127.0.0.1:8033/przepisy/lokalny-szkic-odbioru-publikacji/gotuj; istniejący prywatny przepis i sesja autora. Bez nowych wykonań, zmian źródeł i POST postępu.

## Wykonane

Cztery konfiguracje: szerokość CSS 320 i rzeczywisty zoom Chromium 200%, desktop 1440 i zoom 100%, obie przy tekście aplikacji 140%, oba motywy. Zoom przez chrome.tabs.setZoom/getZoom, nie CSS ani deviceScaleFactor. Fizyczne okno przy mobile ma 640 px szerokości, wynikowy viewport CSS 320; nie oznacza to fizycznego telefonu 320 px. Chromium 153, lokalny transport ograniczony do origin 8033.

W każdej konfiguracji kliknięto rozwinięcie składników: widoczne „1 litr wody”. Zdjęcie kroku poprawnie załadowane i zdekodowane. Dokument nie przewija się poziomo. Przyciskiem minutnika osiągnięto fokus po Shift+Tab/Tab; aktywny element miał :focus-visible i trafialny środek. Nie był to pełny obchód Tab całej strony. Metryki stylu fokusu są chwilowym odczytem przed ukończeniem renderowania; PNG pokazują pierścień, nie wyprowadzamy z tych metryk pomiaru kontrastu.

Minutnik 60 s uruchomiono rzeczywistym Enter w ciemnym desktopie. Bez przyspieszania czasu, po 60127 ms komunikat aria-live zmienił się na „Czas minął!”, licznik pokazał 0:00, przycisk „Uruchom minutnik jeszcze raz” ponownie aktywny. Nie uruchamiano drugiego cyklu. Odświeżenie zakończyło lokalny stan minutnika. Postęp kroku niezmieniony: ukryte zrobiono=1 przed i po (krok pozostaje nieodhaczony). Zero zarejestrowanych pageerror.

Obejrzane PNG:320-dark-focus,320-light-ingredients,1440-dark-photo,1440-light-focus,1440-dark-focus,timer-finished. Zrzuty wykonane CDP bez składania pełnej przewijanej strony.

## Potwierdzona drobna usterka

Widoczna instrukcja brzmi „Ustaw sobie kuchenny minutnik na 1 minuta.” (w interfejsie normalne spacje). Po starcie aria-live również „Minutnik ustawiony na 1 minuta.”. Poprawna odmiana po „na” to „1 minutę”. Reprodukcja: otworzyć podany krok z timer_seconds=60, przeczytać instrukcję, uruchomić minutnik. RecipeStep::timerLabel():70 zwraca mianownik „minuta”, cooking.blade.php i app.js używają etykiety po przyimku „na”. Dowody: PNG i timer-start.json. Bez zmian implementacji.

## Granice

Minutnik funkcjonalnie sprawdzono raz, nie we wszystkich konfiguracjach. Nie potwierdzono dźwięku, wibracji, fizycznego telefonu, WakeLock, pracy w tle ani zachowania przy zablokowanym ekranie. Nie wykonano pełnego axe ani audytu kontrastu. Stałe belki zajmują istotną część powiększonego mobile, a długi napis przycisku zawija nawet pojedynczą literę; nie wykazano utraty tekstu lub braku dostępu do przycisku. Nie rozszerzamy tym odbiorem wcześniejszej macierzy wielokrokowej: ten fixture ma jeden krok. Istniejący raport ODBIOR_TRYBU_GOTOWANIA_2026_09_14.md pozostaje dowodem innych przejść.

Dane: results.json, timer-start.json, initial.txt; reproduktor run.mjs. Pozostawiono wyłącznie pliki output/cooking492-final, zamknięto własną przeglądarkę i usunięto jej prywatny profil/rozszerzenie. Nie zatrzymywano współdzielonego serwera 8033.

## Dowody w repo

Dane: [wyniki](evidence/gotowanie492/results.json) i [komunikat po uruchomieniu](evidence/gotowanie492/timer-start.json). Potwierdzoną odmianę czasu zgłoszono jako [#548](https://github.com/woogitsu/kuking.pl/issues/548). PNG i reproduktor pozostają w lokalnym output/cooking492-final.
