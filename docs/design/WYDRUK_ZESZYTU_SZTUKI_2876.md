# Gotowe sztuki na wydruku zeszytu — #2876

Wydruk całego zeszytu i wybranych przepisów korzysta z tego samego widoku.
Metryczka każdego przepisu pokazuje istniejące `Recipe::yieldLabel()` obok
liczby porcji i czasu. Są to trzy odrębne dane autora: wydruk niczego nie
przelicza. Gdy któregoś brakuje, nie drukuje pustego separatora. Sztuki
pozostają widoczne także wtedy, gdy nie podano ani porcji, ani czasu.

Zmiana nie wpływa na wybór przepisów, uprawnienia, zdjęcia ani prywatne
notatki. Nazwa sztuk jest zwykłym tekstem escapowanym przez Blade.

Regresja HTTP obejmuje oba rodzaje wydruku, kombinacje brakujących danych,
wybór, opcje zdjęć i notatek oraz tekst przypominający HTML. Kontrola ujemna
usuwa odczyt etykiety i musi oblać asercję `SZTUKI_2876_WYDRUK`.

Rollback kodu: przywrócenie wcześniejszej metryczki widoku. Nie ma migracji
ani zmiany zapisanych danych. Odbiór papieru/PDF wymaga osobnego obejrzenia
rzeczywistego wydruku z przeglądarki; sam test HTTP go nie zastępuje.
