# Automatyczne linki — #634

Stan 16 września 2026: implementacja lokalna, przed pełnym hookiem i CI.
Nie potwierdzono wdrożenia. Numer 0.48 wymaga uzgodnienia z oczekującymi
pakietami 0.46 (PWA) i 0.47 (kolaż) przed scaleniem.

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
