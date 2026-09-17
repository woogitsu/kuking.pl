# Automatyczne linki — #634

Stan 17 września 2026: PR #635 scalony do main jako
`3f122d6c3b48efd0e737aec48f4235743bd1477c`, Alfa 0.48.
Pełny lokalny hook head `be9124db527e56a8e9d9be344b3d8e022cd5ae20`
przeszedł, a CI 35152795313 zakończyło wszystkie 12 zadań sukcesem.
Main CI 35157303451 zakończyło wszystkie 12 zadań sukcesem.
Railway 6491546611 i workflow Deploy 35160574780 zakończyły się sukcesem.
Rzeczywista produkcja pokazała Alfa 0.48 / `3f122d6`; odbiór zakończony.
PWA z osobnego PR #631 ma wersję 0.49 i osobny proces odbioru.

Wspólny renderer linkuje HTTP/HTTPS/www w kartach wpisów oraz komentarzach
i odpowiedziach. Nie zmienia danych autora ani reguł moderacji. HTML pozostaje
zwykłym tekstem. Ucięty w zapowiedzi adres nie staje się błędnym odnośnikiem.
Adresy z typograficznymi cudzysłowami zachowują cudzysłowy poza linkiem.

Dokładny origin z konfiguracji aplikacji prowadzi bezpośrednio. Pozostałe
adresy prowadzą do strony z domeną, pełnym URL, ostrzeżeniem i dwoma wyborami.
Adres przekazujemy w uwierzytelnionym szyfrowanym tokenie; strona ponownie go
waliduje, nie przekierowuje automatycznie i nie odpytuje zewnętrznej witryny.
Wyjście nie przekazuje Referer. Powrót prowadzi na Start, nie na adres podany
przez użytkownika. Odrzucamy dane logowania w URL, schematy inne niż HTTP(S),
kontrole kierunku tekstu, CR/LF oraz uszkodzone tokeny. Ostrzeżenie działa bez JS.

Nie wdrażamy skanera. Google Safe Browsing ogranicza użycie do niekomercyjnego;
Web Risk wymaga osobnej integracji i oceny przekazywania adresów prywatnych treści.
Źródło: https://developers.google.com/safe-browsing . Ostrzeżenie nie twierdzi,
że witryna została sprawdzona lub jest bezpieczna.

## Weryfikacja lokalna

- 8 testów PHP / 79 asercji: przykład Marcinka, interpunkcja, HTML/XSS,
  podobne domeny, schematy, tokeny, ucięcie i render rzeczywistych komentarzy.
- 3 fizyczne negatywy: usunięcie escapowania, porównanie prefiksu hosta,
  obejście ostrzeżenia. Wszystkie wykryte; przywrócone MD5 i mtime, ponowny PASS.
- Vite i 72 pary kontrastu PASS; Pint poprawił kolejność importów.
- 24 konfiguracje ostrzeżenia: 320/360/390/414/768/1440, dwa motywy,
  tekst 100/140%, bez poziomego overflow.
- 8 pomiarów prawdziwego zoomu 200% przez chrome.tabs.setZoom, dwa motywy,
  tekst 100/140%. Tab dochodzi do akcji, fokus widoczny; Enter przechodzi do
  kontrolowanego celu bez Referer. Powrót działa przy wyłączonym JS.
- Obejrzano zrzuty mobilne, duży tekst i fokus. Zrzuty zoomu zapisano przez
  CDP, ponieważ zwykły pełnostronicowy screenshot Playwright przy zoomie obcinał obraz.
- Niezależne review wykryło cudzysłowy typograficzne i stały limit w trasie;
  oba poprawiono, testy ponowiono. Review nie znalazło blockera bezpieczeństwa.

Dowody lokalne: `evidence/linki634/`. Pełny hook, CI, uzgodnienie wersji
i odbiór produkcji zakończono. Issue #634 zamknięto po odbiorze.

Pełny hook zatrzymał wysyłkę na inwentaryzacji nowej trasy (SEO/a11y) oraz
znanej różnicy livewire.js/livewire.min.js. Dodano rzeczywisty ekran z tokenem
do obu pomiarów dostępności, adres do testu SEO i przeniesiono wąską
normalizację Livewire z #633. Celowany przebieg: 16 testów / 236 asercji PASS.
Pełna kontrola została następnie powtórzona i zakończona sukcesem przed
zwykłym push.

## Odbiór produkcji — 17 września 2026

Sprawdzono istniejący publiczny wpis z przepisem na Marcinka:
https://kuking.pl/wpisy/01a0a6a7-9e64-707c-ba67-97f93f8ad19b .
Na wersji 0.47 adres był tekstem. Na 0.48 ten sam adres był linkiem
prowadzącym do „Opuszczasz Kuking”, z właściwą domeną i pełnym URL.
Rzeczywiste kliknięcie „Przejdź do strony” otworzyło przepis Marcinka
w Moich Wypiekach; „Zostań w Kuking” wróciło na stronę główną.
Potwierdzono `rel="nofollow ugc noopener noreferrer"` oraz
`referrerpolicy="no-referrer"` na docelowym odnośniku.
Ponowny odczyt HTTP wpisu i ostrzeżenia zwrócił 200 oraz Alfa 0.48 / `3f122d6`.
Nie dodawano ani nie zmieniano treści użytkowników w celu testowania.

Odbiór zapisano także w [issue #634](https://github.com/woogitsu/kuking.pl/issues/634#issuecomment-5705813764).
Ostrzeżenie nie jest skanerem antywirusowym; nie potwierdza bezpieczeństwa
witryny docelowej. Pełny port marki nadal ma status **CZĘŚCIOWO**.
