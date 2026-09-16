# Automatyczne linki — #634

Stan 17 września 2026: PR #635 scalony do main jako
`3f122d6c3b48efd0e737aec48f4235743bd1477c`, Alfa 0.48.
Pełny lokalny hook head `be9124db527e56a8e9d9be344b3d8e022cd5ae20`
przeszedł, a CI 35152795313 zakończyło wszystkie 12 zadań sukcesem.
Nie potwierdzono jeszcze wdrożenia: main CI 35157303451 pozostaje w toku.
PWA ma osobną przygotowaną wersję 0.49.

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

Dowody: `evidence/linki634/`. Dalsze bramki: pełny hook, CI, końcowe
uzgodnienie wersji z main oraz odbiór produkcji. Nie zamykać issue przed odbiorem.

Pełny hook zatrzymał wysyłkę na inwentaryzacji nowej trasy (SEO/a11y) oraz
znanej różnicy livewire.js/livewire.min.js. Dodano rzeczywisty ekran z tokenem
do obu pomiarów dostępności, adres do testu SEO i przeniesiono wąską
normalizację Livewire z #633. Celowany przebieg: 16 testów / 236 asercji PASS.
Pełna kontrola została następnie powtórzona i zakończona sukcesem przed
zwykłym push. Pozostały odbiór produkcji i aktualizacja statusu issue #634.
