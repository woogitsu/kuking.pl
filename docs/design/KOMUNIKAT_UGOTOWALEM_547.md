# Prawdziwe komunikaty Ugotowałem — #547

## Problem i zmiana

Lokalna rzeczywista publikacja wykonania własnego przepisu ujawniła obietnicę powiadomienia autora w formularzu i potwierdzeniu. Wykonanie było zapisane, a powiadomień prawidłowo zero. To istniejący wyjątek AGENTS.md §1, nie błąd mechanizmu powiadamiania. Ta sama sprzeczność dotyczy wymazanego autora oraz bezwarunkowej liczby powiadomień przy powtórnym wysłaniu.

W pakiecie Alfa 0.29 / PR #546:

- instrukcja własnego przepisu i autora, który nie może czytać: „Zapisz wykonanie tego przepisu.”;
- inny autor zdolny czytać, także zawieszony, nadal ma zapowiedź powiadomienia;
- sukces: „Wykonanie zapisane.”;
- ponowny klucz: „To wykonanie już zapisaliśmy.” z zachowaną instrukcją następnego gotowania;
- COPY_STYLE odpowiada rzeczywistemu formularzowi, bez obietnicy pojedynczego kliknięcia ani liczby powiadomień.

Nie zmieniono autoryzacji, polityki powiadomień, danych ani idempotencji. Nie ujawnia się statusu autora.

## Regresje

Pint: trzy pliki bez uwag. Cztery rodziny PHP (`KomunikatUgotowalemMowiPrawdeTest`, `IdempotencjaUgotowalemTest`, `UgotowalemZawszePowiadamiaAutoraTest`, `AwariaPowiadomieniaNieRozdzielaWykonaniaTest`): **31 testów / 148 asercji**.

Nowa regresja wykonuje GET, POST z kluczem odczytanym z formularza i powtórzenie tego klucza. Sprawdza komunikaty, ten sam adres wyniku, jedno wykonanie i właściwą liczbę powiadomień dla własnego przepisu oraz autora aktywnego, wymazanego i zawieszonego. Fixture używają rzeczywistych metod zmiany statusu i kontrolują zapisany status; początkowy pomocniczy `update` chronionego pola status był błędem przygotowania testu i został poprawiony.

Sześć fizycznych kontroli ujemnych prawdziwych źródeł, każda FAIL → przywrócenie → PASS:

1. stara instrukcja Blade;
2. brak wyjątku własnego przepisu;
3. brak sprawdzenia możliwości czytania;
4. tylko active zamiast możliwości czytania — wykrywane dla suspended;
5. stare potwierdzenie sukcesu;
6. stara obietnica liczby powiadomień po powtórzeniu.

Kopie poza repo: `/tmp/kuking547-negatywy-78k37c5p/`. MD5 Blade `6dc58df4f9cd0012c487a2867d103c54`, kontrolera `5f30edaf4ed48851aa6d44e9bfc8a858`; mtime zachowane, widoki czyszczone po każdej mutacji i przywróceniu. [Pełne wyniki](evidence/publikacja492/negatywy547.json). Niezależny review kodu zaakceptował zakres; nie jest to drugi niezależny przebieg testów.

## Końcowa przeglądarka

Po poprawce: **48 konfiguracji instrukcji** (sześć szerokości CSS 320–1440, oba motywy, tekst 100/140%, prawdziwy zoom 100/200%). Cztery wąskie warianty axe bez naruszeń. [Macierz](evidence/publikacja492/matrix547.json).

Sukces i powtórzenie: **8/8 wariantów**, CSS 320 / tekst 140%, oba motywy i zoomy, axe bez naruszeń. Pierwsze wysłania przez rzeczywisty formularz, powtórzenia przez HTTP z tym samym kluczem; ostatni wariant odtworzono także w formularzu z pierwotnym kluczem lokalnego wykonania. Zapisany adres był ten sam, końcowa liczba wykonań nie wzrosła po powtórzeniu. [Wyniki](evidence/publikacja492/flow547.json).

Obejrzano końcową instrukcję na małym i dużym ekranie, sukces oraz długie potwierdzenie powtórzenia przy zoomie 200%. Poprawiony zrzut ostatniego wariantu używa rzeczywistego okna Chromium z `viewport: null`; wcześniejsze próby CDP z emulowanym viewport/clip dawały przyciętą albo zmienioną powierzchnię przechwytywania. To ograniczenie zrzutu, nie naprawa CSS.

Pomocniczy automat początkowo szukał sukcesu w `.notice`, podczas gdy rzeczywisty komponent używa `.komunikaty .flash`. Zbyt ścisłe oczekiwanie HTTP 302 również przerywało poprawny odbiór w przeglądarce z odpowiedzią 200. Końcowy pomiar sprawdza rzeczywisty adres i komunikat oraz brak dodatkowego zapisu. Hipotezy 429 nie potwierdzono; nie zmieniano progów ani nie czyszczono limitera.

Wszystkie dane są lokalne w `kuking_publikacja492` na 55439. Pozostawiono sześć kontrolowanych wykonań, zero powiadomień o własnym gotowaniu. Wizualnie sprawdzano własny przepis; pozostałe stany autora mają regresję PHP. CI, scalenie i wdrożenie końcowego pakietu należy potwierdzić osobno. Pełny port marki nadal **CZĘŚCIOWO**.
## Dostarczenie pakietu

PR546, head8e4da2f, scalony443da38. CI PR i main10/10success, PHP3807/76412. [Potwierdzony odbiór produkcji Alfy0.29](ODBIOR_PRODUKCJI_ALFA_029.md) rozdziela wdrożenie od ograniczonego oglądu zalogowanej strony. Historyczny opis przygotowania wyżej nie jest bieżącym statusem wysyłki.
