# Pomiar prywatnych ekranów „Moje”

`scripts/fixtures/nowe-ekrany-s.php` zakłada w izolowanej lokalnej bazie
pomiarowej własny komentarz Ani pod publicznym przepisem i zapamiętany postęp
gotowania. Operacje są powtarzalne. Oba ekrany są sprawdzane z rzeczywistą
pozycją na liście; sam nagłówek, pusty stan, błąd HTTP lub przekierowanie do
logowania nie wystarczają.

`scripts/dostepnosc.mjs` obejmuje `/zeszyt/moje-rozmowy` oraz
`/zeszyt/gotowanie-zapamietane` w istniejącej macierzy układu i axe. Obejmuje
ona szerokość 320 px, czcionkę przeglądarki 200% i tekst aplikacji 200%.
Pełny przebieg tego przyrządu działa w zadaniu „Dostępność” CI. Wymaga własnej
bazy PostgreSQL 18+, danych demonstracyjnych i przeglądarki.

`scripts/audyt-ux50plus.mjs` mierzy dodatkowo rozmiar tekstu i cele dotknięcia
(18/48 px) na tych samych dwóch ekranach. Ten pełny audyt jest osobnym
przyrządem lokalnym; obecne CI uruchamia z niego tylko wariant wyboru formy.
Pomyślny pomiar automatyczny nie zastępuje pilota z osobami 50+.
