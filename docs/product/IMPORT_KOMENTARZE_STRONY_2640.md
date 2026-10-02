# Kontenery komentarzy w imporcie strony — #2640

Tryb fragmentów importu URL przekazuje modelowi ponumerowane wiersze strony bez HTML. Sekcje komentarzy czytelników nie są materiałem przepisu. Dotychczasowy regex kończył dopasowanie na pierwszym zamykającym znaczniku tej samej nazwy: przy zagnieżdżonym `div` część cudzej wypowiedzi zostawała w wierszach.

`TekstStrony` usuwa teraz całe poddrzewo rozpoznanego `div`, `section`, `ol` albo `ul` na drzewie HTML. Rozpoznawanie `comment`/`comments` w `id` lub `class` zachowuje dotychczasową granicę słowa. Sąsiednia treść pozostaje w kolejności. Skrypty, nawigacja i formularze są nadal wykluczane, a limity 12 000 znaków i 400 wierszy pozostają bez zmian. Nie zmienia się wybór drogi JSON-LD/mikrodane, zgoda na AI ani budżet.

Regresja obejmuje publiczny ekstraktor z zagnieżdżeniem i zwykłą sekcję komentarzy oraz prywatny szkic z rzeczywistego żądania importu na podstawionej stronie HTTP i odpowiedzi modelu. Dwie kontrole ujemne przywracają dawny regex: test wierszy i test HTTP muszą oblać z własnymi markerami. Żaden test nie pobiera prawdziwej strony ani nie wykonuje płatnego wywołania.

Wycofanie samej poprawki przywróci błąd jakości tekstu wejściowego; bez migracji i cofania danych. Istniejące szkice pozostają prywatne i podlegają sprawdzeniu przez człowieka przed publikacją.
