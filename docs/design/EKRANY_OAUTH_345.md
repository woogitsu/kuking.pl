# Ekrany po powrocie z Google i Facebooka — #345 / #542

14 września 2026. Baza zmian: `8339b373e7ee6909ee3e69b58ec4de22dc7b5eb0`.

## Zakres

Dług z #345 i D-175 obejmuje pięć stanów, nie tylko cztery adresy formularzy:

| Ekran | Droga dojścia |
|---|---|
| Google — domknięcie | Prawdziwy start OAuth, kontrolowany kod dostawcy, callback, formularz nowego konta |
| Google — połączenie | Ta sama droga, adres istniejącego zweryfikowanego konta testowego |
| Facebook — domknięcie | Prawdziwy start i callback, nowa testowa tożsamość |
| Facebook — połączenie | Najpierw rzeczywiste logowanie hasłem do własnego konta testowego, następnie start i callback |
| Facebook — bez adresu | Callback dostaje tożsamość bez e-maila i wyświetla właściwy stan, bez zmiany adresu konta |

Wszystkie stany mierzy moduł `scripts/fixtures/oauth-dostepnosc.mjs`, wywołany z głównego automatu dostępności. Dwa motywy, szerokość320×740 i tekst aplikacji140% dają dziesięć pomiarów. To nie jest prawdziwy zoom200%, pełna obsługa zewnętrznej usługi OAuth ani test na fizycznym telefonie.

## Oddzielenie od produkcji

Router pomiarowy żyje wyłącznie w `scripts/fixtures/`; nie dodano tras, middleware'u ani komendy aplikacji. Start i callback wykonują normalne kontrolery, w tym obsługę `state`; Google dostaje nonce pochodzący z rzeczywistego przekierowania. Atrapa dotyczy transportu HTTP dostawcy. Nie zapisuje tożsamości w sesji za kontroler.

Własny serwer słucha na127.0.0.1, a połączenie bazy wymaga jawnych parametrów i nazwy dopuszczonej do pomiaru. Zbyt szerokie początkowe dopuszczenie `kuking_*` odrzucono w niezależnym review i zastąpiono listą. Poczta używa `array`; zewnętrzne żądania PHP i przeglądarki są blokowane. Używany jest rzeczywisty middleware CSRF. Moduł sprząta własne dane, sesje i proces serwera, bez resetowania współdzielonej bazy. Odmowa usunięcia danych przerywa pomiar; katalog tymczasowy pozostaje wtedy do diagnozy, więc wynik nie obiecuje pełnego sprzątnięcia po takiej odmowie.

## Znaleziony błąd tekstu — #542

`auth/facebook-link.blade.php` nadal obiecywał wejście „jednym kliknięciem”. Poprzednia regresja precyzji pomijała ten widok. Ponadto zwykłe szukanie frazy ze spacją nie wykrywało tekstu rozdzielonego nową linią w HTML.

Instrukcja nazywa teraz „Wejdź kontem Facebooka” i uprzedza, że Facebook może poprosić o potwierdzenie. Powiązanie nadal wymaga tego samego POST z CSRF; nie zmieniono logowania, autoryzacji ani danych kont. Wersja widocznej poprawki: Alfa0.28. COPY_STYLE obejmuje jawnie także etap łączenia kont.

Regresja renderuje pominięty widok i normalizuje białe znaki. Osobno sprawdza tekst w `main` oraz formularz POST z tokenem. Lokalnie: **5 testów /44 asercje PASS**. Dwa rzeczywiste negatywy Blade: przywrócenie starej wielowierszowej obietnicy i usunięcie informacji o potwierdzeniu — oba FAIL, po odtworzeniu źródła PASS. Backup poza repo, MD5 `497c0a4489720e50cd28da5a0a6acdd1`, mtime_ns przywrócony. Po pierwszym odtworzeniu oryginalnego czasu kompilowany Blade nadal zawierał sabotaż; tego rerunu nie zaliczono. Całą parę kontroli powtórzono z `view:clear` przed każdym przebiegiem.

## Raport i granice odbioru

Główny JSON zachowuje częściowe wyniki i błąd. Sprawdzany jest dokładny zestaw ekran/stan/motyw, nie sama liczba: duplikat nie może zastąpić brakującego ekranu. Regresja wykonuje prawdziwy blok integracji i końcowy zapis JSON; zastępuje tylko kosztowny pomiar. CI zachowuje raport oraz zrzuty OAuth również po błędzie.

Pierwszy eksperyment usunięcia etykiety nie oblał axe, bo pole dostało nazwę z placeholdera. Nie zaliczono go jako udanego negatywu. Wymóg widocznej etykiety wymaga osobnej kontroli, niezależnej od dostępnej nazwy pola.

Wyniki końcowego odbioru, kontroli ujemnych i CI należy czytać w dalszej części tego raportu. Samo przygotowanie kodu nie potwierdza scalenia ani wdrożenia.

## Historyczny stan przy przekazaniu pracy

Użytkownik poprosił o zakończenie i przekazanie przed końcowym odbiorem całej gałęzi. Pakiet jest roboczy; nie scalać na podstawie tego dokumentu.

- Zintegrowane regresje JSON: **9/9 PASS** (pięć OAuth i cztery istniejącej karuzeli). Cztery rzeczywiste negatywy źródeł: brak sekcji JSON, brak kodu błędu, sama liczba zamiast kompletności i usunięty formularz z listy — wszystkie FAIL, po restore PASS. MD5/mtime w `evidence/oauth345/negatywy345-root.json`.
- Rodziny PHP kompletności, kodu wyjścia, precyzji i wejść: **18 testów /157 asercji PASS** przed ostatnim rozszerzeniem tekstu braku adresu. Pierwszy lokalny przebieg wymagał przywrócenia LF w skrypcie; nie zmieniano warunku końcowego ani testu, który oczekiwał LF.
- Po rozszerzeniu precyzji o ekran bez adresu: **6 testów /49 asercji PASS**. Ten ekran wcześniej sugerował tylko dwa pola rejestracji i wiadomość e-mail po ugotowaniu przepisu. Zastąpiono to odesłaniem do pełnego formularza i opisem adresu do odzyskania dostępu. `NotifyUser` zapisuje powiadomienie w serwisie; nie jest wysyłką e-maila.
- Negatyw powrotu starego ekranu bez adresu FAIL, restore MD5/mtime i rerun PASS: `negatywy542-bez-adresu.json`. Dwa wcześniejsze negatywy Facebook-link powtórzono na finalnych plikach LF: MD5 **08c9aed33d54d681a245510ae2e277f8** (wcześniejszy zapis CRLF wyżej jest historyczny).
- Dodatkowy agent: **10/10 pomiarów**, trzy negatywy Blade/CSS, sześć odmów niebezpiecznej konfiguracji. Końcowy pomiar agenta zawiera poprawiony Facebook-link, ale jest **sprzed ostatniej zmiany Facebook-bez-adresu**. Dowody w `evidence/oauth345/`; dziesięć zrzutów w lokalnym worktree agenta. Root obejrzał pięć reprezentatywnych pełnych stron. Nie deklarujemy oglądu każdego zrzutu.
- Niezależny review zaakceptował integrację, guardy i cleanup oraz pierwszą poprawkę Facebook-link. Ostatni tekst Facebook-bez-adresu nie ma jeszcze tego review.

**Brakuje końcowego pomiaru przeglądarkowego po ostatnim tekście, pełnej lokalnej kontroli przed push (jej wynik trzeba odczytać z logu), CI PR, scalenia i wdrożenia Alfa0.28.** Samo wysłanie gałęzi nie zmienia tych statusów. Szczegółowa kolejność kontynuacji: `PRZEKAZANIE_OAUTH_2026_09_14.md`.

## Odbiór końcowego źródła — 14 września 2026

Poprzednia sekcja opisuje moment przekazania, a nie aktualną blokadę. Brakujący odbiór wykonano na dokładnym head **795dc2f9a5257143a6aa18d02a1c2266c5fa7ad9**. PR [543](https://github.com/woogitsu/kuking.pl/pull/543) scalono normalnie po kontrolach jako **5fd45db896f07e0bb415b31856c5a43fbbb1c742**.

- Obowiązkowy hook push zakończony sukcesem; log lokalny `/tmp/kuking-push345.log`.
- [CI 34864822658](https://github.com/woogitsu/kuking.pl/actions/runs/34864822658): wszystkie **10 zadań success**, również wyścigi. Odczyt logu PHP: **3802 testy / 76343 asercje**. Oczekiwane błędy kontroli ujemnych w logu nie są błędem końcowej serii.
- Log dostępności: główne axe **44/44**, układ **49/49** oraz osobne OAuth **10/10**. To oddzielne zbiory pomiarów, nie 54 zwykłe strony.
- Ponowny lokalny pomiar Chromium **153.0.8010.12**: **10/10**, 22,316 s, viewport 320×740, tekst aplikacji 140%, oba motywy; zero naruszeń axe i overflow. Zawiera ostatnią zmianę Facebook-bez-adresu, wersja stopki Alfa 0.28.
- Dodatkowa powtórka z preferencją ograniczenia animacji: **10/10**, 16,207 s. Pierwsze pełne zrzuty ciemnego motywu uchwyciły przejście kolorów; powtórka usunęła ten artefakt odbioru. Nie zmieniano produkcyjnego CSS ani progów testów.
- Obejrzano wszystkie pięć ekranów w obu motywach oraz viewport z fokusem końcowego przycisku ekranu bez adresu. Pełne zrzuty mają ograniczenie pozycjonowania stałych belek opisane wyżej.
- Niezależny, odczytowy review ostatniego Facebook-bez-adresu: bez blokera. Porównano z rzeczywistymi polami i oświadczeniami rejestracji oraz `NotifyUser`. Nie zastępuje to testu dostarczenia poczty ani rzeczywistego dostawcy OAuth.
- Po pomiarze lokalna wyłączna baza `kuking_oauth345` na 55439: **users=0**. Pozostawiony wcześniejszy serwer portu 8029 nie był procesem tego pomiaru; nie zamykano cudzej sesji.

| Ekran | Motywy | Axe / overflow | Tab w main na motyw | Ogląd |
|---|---|---|---|---|
| Google — domknięcie | jasny, ciemny | 0 / 0 | 9/9 | oba |
| Google — połączenie | jasny, ciemny | 0 / 0 | 3/3 | oba |
| Facebook — domknięcie | jasny, ciemny | 0 / 0 | 8/8 | oba |
| Facebook — połączenie | jasny, ciemny | 0 / 0 | 3/3 | oba |
| Facebook — bez adresu | jasny, ciemny | 0 / 0 | 2/2 | oba, także viewport Tab |

Lokalne końcowe artefakty: `/tmp/kuking-final-20260913/storage/dostepnosc/oauth345/` (`final-795dc2f.json`, log i PNG). CI przechowuje swój komplet jako artefakt zadania dostępności. Rzeczywiste negatywy z wcześniejszych sekcji zachowują ważność: końcowy odbiór nie zmienił źródeł objętych kontrolami.

Odbiór wdrożenia: [ODBIOR_PRODUKCJI_ALFA_028.md](ODBIOR_PRODUKCJI_ALFA_028.md). Pełny port marki nadal **CZĘŚCIOWO**: ten pakiet zamyka pięć wskazanych stanów, nie cały audyt ani prawdziwy zoom 200%, fizyczny telefon czy rzeczywiste konto dostawcy.
