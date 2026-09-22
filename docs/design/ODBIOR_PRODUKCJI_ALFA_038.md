# Odbiór produkcji Alfa0.38 — #574 i przewijany pasek

## Potwierdzona produkcja — 15 września 2026

Main CI34986762320: 11/11 success, pełny PHP3834/76883 odczytany
w logu104440808326. Railway6462041446: success o15:36:08UTC.
Deploy34989544692: success. HTTP potwierdza Alfa0.38 oraz
`0e1bdbe80ffe25d72accf2f773a3a533675ef458` (metryczka0e1bdbe),
CSS `app-Bx-MzRPK.css`, JS `app-BlSF1GKB.js` i oba lokalne Inter HTTP200.

W izolowanej anonimowej przeglądarce produkcyjnej wykonano prawdziwe
zmiany przez formularz: skala70%, motyw ciemny, odświeżenie zachowujące
wybór, zamknięcie podpowiedzi pierwszego wejścia, reset100%/jasny i ponowny
odczyt. Rzeczywiste przewijanie kołem chowało i przywracało górny pasek.
Regresja paska:24 warianty (320/360/390/414/768/1440, oba motywy,
tekst100/140) oraz reduced motion PASS. To pomiar skali tekstu, nie zoomu.

Panel odebrano przy320/390/1440 w obu motywach i skali70:6/6,
bez przepełnienia i z kontrolkami w oknie. Obejrzano sześć końcowych zrzutów
panelu i dwa paska. Pierwsze zrzuty wymuszały motyw przezDOM, pozostawiając
starą wartość selecta; zastąpiono je ponownym pomiarem z rzeczywistymi
wyborami i odpowiedziami POST200. Nie był to błąd aplikacji.
Dowody: `evidence/wyglad574/produkcja/`.

To odbiór gościa, bez modyfikacji kont i treści. Zalogowane stany, rzeczywisty
zoom200% oraz fizyczne negatywy mają osobne dowody lokalne i CI powyżej.
Nie odebrano fizycznego telefonu, czytnika ani klawiatury ekranowej.
Pełny port marki nadal CZĘŚCIOWO. Alfa0.37 nie miała osobnego odbioru;
jej przewijany pasek potwierdzono tutaj jako część0.38.
