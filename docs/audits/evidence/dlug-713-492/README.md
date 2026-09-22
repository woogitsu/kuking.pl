# Pochodzenie dowodów

Odczyt i wykonanie: 20.09.2026; źródła 4c811cc7bff365fb8f86d87eabac93b7738a45cd.
Wszystkie pliki issue-*.json pobrano przez gh issue view NUMER --repo woogitsu/kuking.pl --json number,title,state,body,comments,url.
pr-707.json i ci-707.json: gh pr view / gh run view (35435955028). Log ci-707-regresja.txt zawiera wybrane krótkie wiersze rzeczywistego logu tego przebiegu; pominięto obrazy base64 i resztę logu. Nie jest pełnym logiem CI.

testy.txt to pełny stdout/stderr lokalnego przebiegu (usunięte sekwencje koloru ANSI i końcowe białe znaki wierszy; kod wyjścia 0). W PowerShell ustawiono MSYS_NO_PATHCONV=1, a wywołanie WSL użyło --exec, aby zachować wyrażenie filtra bez interpretowania nawiasów przez dodatkową powłokę:

    wsl -d Ubuntu --exec bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/testuj.sh gpt-dlug-weryfikacyjny --filter '^(?!.*ProbaOdtworzeniaTest)'

Wcześniej przygotowano runtime skryptem przygotuj-runtime.sh tej samej floty. Nie zmieniano aplikacji. Pominięcie ProbaOdtworzeniaTest jest jawnym wyjątkiem użytkownika dla współdzielonej bazy źródłowej.
panel-test.txt: node scripts/panel-komunikat.test.mjs z tego runtime, 12/12.
pint.txt: /opt/kuking-php-8.4-avif/bin/php vendor/bin/pint z tego runtime, PASS 1155 files.
paginatory.txt: rg -n 'paginate\(|simplePaginate\(|cursorPaginate\(' app --glob '*.php'. Skan uzupełniono odczytem własnej paginacji SearchController; sam skan nie obejmuje całego mechanizmu wyszukiwania.
produkcja.json: tylko wybrane bezpieczne pola GET /health i /login; bez cookies, nonce, tokenów sesji i HTML-u użytkowników.

Pliki JSON zgłoszeń są migawkami cudzych deklaracji, a nie wynikami lokalnych pomiarów. Raport nadrzędny jawnie oznacza ich użycie jako pomiar cudzy.