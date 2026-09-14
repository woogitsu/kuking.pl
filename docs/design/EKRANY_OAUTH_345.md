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

## Stan przy przekazaniu pracy

Użytkownik poprosił o zakończenie i przekazanie przed końcowym odbiorem całej gałęzi. Pakiet jest roboczy; nie scalać na podstawie tego dokumentu.

- Zintegrowane regresje JSON: **9/9 PASS** (pięć OAuth i cztery istniejącej karuzeli). Cztery rzeczywiste negatywy źródeł: brak sekcji JSON, brak kodu błędu, sama liczba zamiast kompletności i usunięty formularz z listy — wszystkie FAIL, po restore PASS. MD5/mtime w `evidence/oauth345/negatywy345-root.json`.
- Rodziny PHP kompletności, kodu wyjścia, precyzji i wejść: **18 testów /157 asercji PASS** przed ostatnim rozszerzeniem tekstu braku adresu. Pierwszy lokalny przebieg wymagał przywrócenia LF w skrypcie; nie zmieniano warunku końcowego ani testu, który oczekiwał LF.
- Po rozszerzeniu precyzji o ekran bez adresu: **6 testów /49 asercji PASS**. Ten ekran wcześniej sugerował tylko dwa pola rejestracji i wiadomość e-mail po ugotowaniu przepisu. Zastąpiono to odesłaniem do pełnego formularza i opisem adresu do odzyskania dostępu. `NotifyUser` zapisuje powiadomienie w serwisie; nie jest wysyłką e-maila.
- Negatyw powrotu starego ekranu bez adresu FAIL, restore MD5/mtime i rerun PASS: `negatywy542-bez-adresu.json`. Dwa wcześniejsze negatywy Facebook-link powtórzono na finalnych plikach LF: MD5 **08c9aed33d54d681a245510ae2e277f8** (wcześniejszy zapis CRLF wyżej jest historyczny).
- Dodatkowy agent: **10/10 pomiarów**, trzy negatywy Blade/CSS, sześć odmów niebezpiecznej konfiguracji. Końcowy pomiar agenta zawiera poprawiony Facebook-link, ale jest **sprzed ostatniej zmiany Facebook-bez-adresu**. Dowody w `evidence/oauth345/`; dziesięć zrzutów w lokalnym worktree agenta. Root obejrzał pięć reprezentatywnych pełnych stron. Nie deklarujemy oglądu każdego zrzutu.
- Niezależny review zaakceptował integrację, guardy i cleanup oraz pierwszą poprawkę Facebook-link. Ostatni tekst Facebook-bez-adresu nie ma jeszcze tego review.

**Brakuje końcowego pomiaru przeglądarkowego po ostatnim tekście, pełnej lokalnej kontroli przed push (jej wynik trzeba odczytać z logu), CI PR, scalenia i wdrożenia Alfa0.28.** Samo wysłanie gałęzi nie zmienia tych statusów. Szczegółowa kolejność kontynuacji: `PRZEKAZANIE_OAUTH_2026_09_14.md`.
