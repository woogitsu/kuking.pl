# Motyw w stopce — KuKING.pl v3.1

## Wygląd i zachowanie

Jedna kontrolka w stopce: księżyc + **Ciemny** + niewielki wskaźnik włączenia. Cała kontrolka jest klikalna, a nie tylko mały suwak. Podpis pozostaje stały. Włączony stan pokazują położenie suwaka i wyróżnienie przycisku; nie polegamy na samej barwie. Dostępna nazwa: „Ciemny wygląd”.

Kontrolka ma `margin-inline-start: auto` i jest wyśrodkowana pionowo w wierszu stopki. Prawa krawędź jej obszaru klikalnego odpowiada prawej krawędzi ostatniej akcji nagłówka. Na wąskim ekranie może zająć osobny wiersz, nadal po prawej. Nie jest pozycjonowana absolutnie ani przyklejona do okna. Stopka aplikacji zostawia przestrzeń nad dolną nawigacją.

## Co działa w plikach HTML

Osiem ekranów produktu ma `data-preview="true"`. Natywne pole `input type="checkbox" role="switch"` przełącza motyw przez `:has()` w **wygenerowanym arkuszu podglądu**. Działa kliknięcie całej etykiety oraz Tab i Spacja. Nie dodano JavaScriptu, ciasteczek ani localStorage.

Wybór dotyczy bieżącego dokumentu. Paczka nie zapewnia zapisu preferencji ani jej przenoszenia na inne strony. Przywracanie stanu formularza przez konkretną przeglądarkę nie jest gwarancją trwałego zapisu. Motyw systemowy nie narzuca wyglądu. Galerie techniczne nie otrzymały dodatkowego interfejsu motywu.

Selektor `:has()` jest dodatkiem do podglądu, nie wymaganiem wobec renderowania motywu przez serwer. Produkcyjne `tokens.css` nadal korzysta z `data-theme="dark"` na `<html>`.

## Wdrożenie SSR bez JavaScriptu

Przykład `motyw.blade.php` pokazuje przycisk formularza POST z `aria-pressed`, stałą nazwą i tym samym wyglądem. Nazwa trasy i zmienne są **przykładowe**, nie zostały zweryfikowane w repozytorium aplikacji. Nie kopiuj komponentu jako gotowego endpointu.

Serwer powinien przyjmować wyłącznie `light` lub `dark`, chronić POST przed CSRF, zapisać wybór zgodnie z polityką konta/sesji i wyrenderować atrybut na `<html>` już w pierwszej odpowiedzi. W ten sposób dokument nie musi najpierw pokazać niewłaściwego motywu. Adres powrotu wolno ograniczyć do zaufanych tras wewnętrznych; nie przekierowywać na dowolny adres przesłany przez użytkownika.

**Uwaga na formularz przepisu:** osobny POST zmiany motywu i przeładowanie strony mogą usunąć niezapisane pola, zwłaszcza wybrany plik. W kreatorze zmiana motywu musi współpracować z zapisem całego kroku i zdjęcia albo pozostawać lokalnym podglądem do czasu zapisu. Nie wdrażać przykładu jako osobnego formularza w kreatorze bez rozwiązania tego problemu. Przycisk „Zapisz szkic” w bocznym panelu v3.1 jest już przypisany do głównego formularza przez atrybut `form`.

## Odbiór przed publikacją

Sprawdzić zmianę w obie strony, zachowanie po przejściu na inną stronę i odświeżeniu, poprawność `aria-pressed`, obsługę klawiaturą, fokus po odpowiedzi serwera, błąd zapisu preferencji oraz brak utraty danych kreatora. Testować także 320 px, powiększenie 200%, tryb wysokiego kontrastu i rzeczywisty czytnik ekranu. Potwierdzić zachowanie bez nowoczesnego selektora `:has()` w docelowej macierzy przeglądarek.

Dokumentacja wzorców: [W3C Switch](https://www.w3.org/WAI/ARIA/apg/patterns/switch/) i [W3C Button](https://www.w3.org/WAI/ARIA/apg/patterns/button/).
