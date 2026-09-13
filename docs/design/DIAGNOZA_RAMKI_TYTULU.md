# Ramka tytułu po wejściu / odświeżeniu — diagnoza w trybie kończenia pracy

Status: brak potwierdzonej reprodukcji. Root nie odtworzył problemu po odświeżeniu produkcyjnego zeszytu; użytkownik nie wskazał jeszcze konkretnej strony. Poniższe ustalenia pochodzą z odczytu kodu, nie z reprodukcji w przeglądarce. Nie zmieniono źródeł aplikacji ani nie uruchomiono pełnych testów.

## Potwierdzone w kodzie

- resources/css/tokens.css:678–685: globalne :focus usuwa outline, natomiast :focus-visible rysuje obrys3px kolorem --color-focus, offset2px, mały promień. To może wyglądać jak ramka wokół tekstu linku. Kliknięcie poza element przenosi fokus i usuwa obrys.
- resources/js/app.js:174–175: DOMContentLoaded ustawia fokus na .error-summary, jeśli podsumowanie błędów istnieje. Nie ustawia go na h1 ani main. Druga ścieżka fokusu podsumowania jest po błędzie dynamicznym, około601.
- resources/views/components/layout.blade.php:359: skiplink do #tresc; main id=tresc około880. Main nie ma jawnego autofocus ani tabindex. Nie znalazłem w źródłach widoków jawnego autofocus nagłówka.
- W przeszukanym resources/js i widokach nie znaleziono własnego handlera livewire:navigated ani wire:navigate kierującego fokus na h1. Nie jest to dowód dotyczący zachowania wewnętrznego bibliotek lub rozszerzeń przeglądarki.
- Część tytułów jest linkami (domyślna karta przepisu, nazwy zeszytów); kafle zeszytu od #511 mają zwykły h3 i osobną krótką akcję. Zatem objaw może zależeć od konkretnej strony i rodzaju tytułu.

## Hipoteza, nie ustalona przyczyna

Najbardziej zgodny z opisem „kliknięcie usuwa” jest pozostający/przywracany fokus i :focus-visible, np. po nawigacji klawiaturą lub odświeżeniu. Sama obecność tej reguły CSS nie dowodzi błędu: obrys jest potrzebny do obsługi klawiaturą. Nie należy usuwać globalnego :focus-visible ani robić blur wszystkich elementów na load.

Alternatywnie ramka może obejmować podsumowanie błędów, a nie tytuł strony, lub pochodzić z konkretnego komponentu. Bez adresu i zaznaczonego elementu nie ma podstaw do poprawki.

## Następna reprodukcja

1. Uzyskać dokładny URL i tytuł; stan zalogowania, motyw, skala, przeglądarka; sposób wejścia oraz odświeżenia (mysz / Ctrl+R / F5), ewentualny fragment # w URL.
2. Na tej stronie tuż przed kliknięciem zapisać document.activeElement (tag,id,klasy,tekst), matches(':focus-visible'), getComputedStyle(activeElement).outline oraz border; równolegle sprawdzić h1 i jego rodziców. Zrzut przed kliknięciem i po nim.
3. Powtórzyć czyste wejście, reload myszą, reload klawiaturą, powrót historii oraz wejście Tab+Enter. Sprawdzić, czy objaw znika wraz ze zmianą activeElement.
4. Jeśli potwierdzi się fokus konkretnego nagłówka: ustalić nadawcę focus/autofocus, w tym bibliotekę; poprawić wyłącznie tę ścieżkę i zachować widoczny fokus przy Tab. Jeśli activeElement nie pokrywa się z ramką, badać właściwą regułę border/outline komponentu.

Granica przekazania: gałąź #513 zawiera nieukończone zmiany innych obszarów; niniejsza diagnoza ich nie dotyka. Brak implementacji lub deklaracji naprawy ramki.
