## D-189 · Trasy w dokumentach sprawdza `Route::getRoutes()`, nie nazwa pliku

**Data:** 12 września 2026 · PR #462 · Status: **obowiązuje**

### Kontekst

Audyt wszystkich **241** plików `.md`: odnośniki markdown do plików i kotwic, ścieżki
w backtickach, wzmianki tras serwisu.

Jeden z martwych odnośników nie był tylko dokumentacją. `resources/legal/zasady.md`
prowadziło **z żywej strony** do `/polityka-prywatnosci`, którego nie ma; właściwy
adres to `/prywatnosc`. Dokumentacja i treść serwisu leżą w tym repozytorium obok
siebie, więc „to tylko dokument" nie jest tu bezpiecznym założeniem.

### Decyzja

Prawdziwość trasy rozstrzyga `Route::getRoutes()`, a nie podobieństwo do nazwy pliku
ani angielski odpowiednik. Cała „Mapa ekranów MVP" w `FLOWS_AND_SCREENS.md` to było
**29** adresów, które nigdy nie istniały — dokument opisywał serwis, którego nie
zbudowaliśmy, i wyglądał przy tym zupełnie wiarygodnie.

Świadomym wyjątkiem zostają `/login`, `/register` i `/wyloguj` → `/logout`: to jedyne
angielskie trasy w serwisie i jest to wyjątek zapisany w `AGENTS.md` §11, nie
przeoczenie.

Dokument opisujący **projekt** API, a nie kod z repozytorium, ma to napisać u siebie.
`COMPONENTS_BLADE.md` miał 8 z 12 sekcji w tym stanie od dawna; złapał to audyt
z września i nigdy nie trafiło to do samego dokumentu.

### Co jest wyłączone z testu i dlaczego

Historyczne audyty, zlecenia i dziennik decyzji. Te dokumenty **opisują stan z dnia
zapisu** — poprawianie w nich adresu znaczyłoby przepisywanie historii, a nie naprawę.

📄 `tests/Feature/DokumentyMdNieMajaMartwychOdnosnikowTest.php` · `resources/legal/zasady.md` ·
`docs/FLOWS_AND_SCREENS.md` · `AGENTS.md` · D-188
