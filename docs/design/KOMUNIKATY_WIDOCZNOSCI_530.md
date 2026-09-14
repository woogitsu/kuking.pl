# Komunikaty widoczności przepisu — #530

## Odtworzenie i przyczyna

Zgłoszenie: https://github.com/woogitsu/kuking.pl/issues/530. Baza pracy: `a28703e3b86535fbffd35474b49287226a86d86d`; gałąź `fix/530-komunikaty-widocznosci`.

Podczas lokalnego odbioru zwykłego formularza powstał prywatny przepis `/przepisy/lokalny-odbior-zapisu-skladnika-526`. Potwierdzenie mówiło „Przepis opublikowany. Teraz ktoś może z niego ugotować.”, a instrukcja udostępniania — „Ten przepis widzą tylko wybrane osoby”. Odbiór przeglądarkowy zgłosiło zadanie główne; ta poprawka odtwarza problem niezależnymi żądaniami HTTP oraz aktualizacjami Livewire.

`RecipePolicy::view()` dopuszcza dla opublikowanego `private` wyłącznie autora. Sam stan `published` jest prawidłowy: prywatność i stan publikacji to różne pola. Błąd dotyczył obietnicy innych odbiorców. Dla `followers` stary opis pustej strony był także nieprawdziwy: obserwujący autora może otworzyć link.

## Zakres

Trzy zmienione źródła aplikacji:

- `RecipeController::store`: potwierdzenie zależy od zapisanej widoczności. Warunkowe zaproszenie dopisania szczegółów nadal zależy od `CoMoznaDopisac`. Ścieżki szkicu i ponowionego kliknięcia pozostają bez zmian.
- `recipe-wizard.blade.php`: to samo rozróżnienie przy pierwszej publikacji; istniejące „Szczegóły zapisane.” pozostaje przy edycji opublikowanego przepisu.
- `Udostepnianie::powodBrakuPrzycisku`: prywatny przepis lub wpis „widzisz tylko Ty”, dla obserwujących „widzisz Ty oraz osoby, które Cię obserwują”. Instrukcja zmiany na „wszyscy” dotyczy dostępności przycisku „Podziel się”, nie dowolnego ręcznego wysłania adresu. Dla pozostałej odmowy `Gate` tekst mówi o braku dostępu bez zalogowania, bez sugerowania nieistniejących wybranych odbiorców.

Potwierdzenia publikacji:

| Widoczność | Tekst |
|---|---|
| private | Przepis zapisany. Widzisz go tylko Ty. |
| followers | Przepis opublikowany dla osób, które Cię obserwują. |
| public | Przepis opublikowany. Teraz ktoś może z niego ugotować. |

Nie zmieniono polityk, statusu publikacji, zapisywanych danych, uprawnień ani schematu. Decyzję o przycisku nadal podejmuje `Gate::forUser(null)`. Rozróżnienie widoczności w tekście nie jest drugą implementacją autoryzacji. Dotychczasowa instrukcja dla szkicu pozostaje poza zakresem.

## Wykonane sprawdzenia

Osobna kopia wykonawcza `/tmp/kuking-visibility530-exec`, baza PostgreSQL `kuking_proof530` utworzona po sprawdzeniu nieobecności, host `127.0.0.1:55439`, strefa UTC. Jawne zmienne połączenia, `APP_BASE_PATH` i `APP_URL=http://localhost`. Bez demo, zmian wspólnych mediów i pełnego zestawu PHP.

Nowa regresja wykonuje prawdziwy POST do `recipes.store` i publikację przez Livewire dla każdej z trzech widoczności. Sprawdza zapisany status `published`, czas publikacji, widoczność, komunikat sesji i HTML strony autora. Następnie wysyła GET jako obserwujący, obca osoba i gość: private odmawia wszystkim trzem, followers wpuszcza obserwującego, public wpuszcza wszystkich. Osobne trzy przypadki sprawdzają wspólną instrukcję na stronie wpisu.

Końcowy zakres: nowa regresja, `PodzielSieTest`, `RecipeWizardTest`, `DodawaniePrzepisuSzescKontrolekTest`, `IdempotencjaPrzepisuTest`: **60 testów, 400 asercji, PASS**. Obejmuje istniejące przypadki szkiców, idempotencji i warunkowego dopisywania szczegółów. Pint czterech plików i PHPStan dwóch zmienionych klas oraz nowego testu: PASS przy dotychczasowym limicie 1G. Wyniki są w `output/proof530/green-final.txt`, `pint.txt`, `phpstan.txt`.

Trzy kontrole ujemne zmieniają rzeczywiste źródło aplikacji, przywracając mylący komunikat w kontrolerze, Blade i instrukcji udostępniania. Każda daje dwa błędy asercji (private i followers), a public przechodzi. Kopie na dysku poza repo: `/tmp/kuking-negative-backups/proof530-post.source`, `proof530-livewire.source`, `proof530-sharing.source`. Przywracanie odbywa się z tych plików, nie z pamięci; zgodność MD5 i mtime dokumentuje `output/proof530/negatives.json`. Cache Blade jest czyszczony przed i po każdej mutacji. Skrypt i surowe wyniki: `output/proof530/proof530-negatives.py`, `negative-*.txt`. Końcowy zielony przebieg wykonano po wszystkich przywróceniach.

Nie wykonywano publikacji produkcyjnej, commitów, push ani zmiany wersji i changeloga. Integracja pozostaje w zadaniu głównym.

Po review doprecyzowano wyłącznie komentarz klasy `Udostepnianie`: Gate rozstrzyga obecność przycisku, a `match` dobiera opis ustawienia. Poprzedni MD5 w `negatives.json` dotyczy wersji przed tą korektą komentarza. Negatyw udostępniania powtórzono dla końcowego źródła, z kopią `/tmp/kuking-negative-backups/proof530-comment-sharing.source`; MD5 `c576851100956d26332ef44d5cb2976e`, mtime i wynik są w `negatives-sharing-comment.json`. Zielony przebieg nowej regresji i `PodzielSieTest` po przywróceniu: `green-comment.txt`.

## Doprecyzowanie po odbiorze przeglądarkowym

Rzeczywisty przycisk po publikacji niepełnego przepisu nazywa się „Dopisz szczegóły”, podczas gdy komunikat kierował do „Edytuj”. Potwierdzono to na prywatnym przepisie z lokalnego odbioru. Instrukcja wskazuje teraz właściwą nazwę, a regresja sprawdza tekst rzeczywistego linku do edycji. Warunek kompletności przepisu pozostaje bez zmian.

## Integracja z Alfą 0.25

Końcowy zestaw po integracji z #526/#528 i poprawieniu nazwy przycisku: **104 testy / 1194 asercje PASS**. Cztery negatywy końcowych źródeł (POST, Livewire, udostępnianie, nazwa przycisku) zakończyły się oczekiwanymi błędami. Źródła przywrócono z fizycznych kopii poza repo, z kontrolą MD5 i mtime; wyniki w `output/integrated530/`. Po przywróceniu zestaw 104/1194 ponownie przeszedł.

W lokalnej przeglądarce utworzono prywatny przepis prawdziwym formularzem. Potwierdzenie oraz opis udostępniania mówiły „tylko Ty”, a instrukcja wskazywała „Dopisz szczegóły”. Cztery konfiguracje 320/1440 px, oba motywy i tekst 140%: brak poziomego przewijania. Obejrzano zrzuty jasnego motywu na obu szerokościach. Pozostałe widoczności sprawdzono w HTTP/Livewire, nie w tej serii zrzutów. Nie tworzono treści na produkcji.

Niezależne review kodu nie wykazało blokera; sprawdzono również warunek kompletności i zachowanie edycji już opublikowanego przepisu. Wersja i changelog są częścią wspólnego, jeszcze niewdrożonego pakietu Alfy 0.25.
