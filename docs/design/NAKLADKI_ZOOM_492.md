# Nakładki Start przy rzeczywistym zoomie 200% — #492

Lokalny odbiór na źródłach `9fa1bc9740a15e23cf51149323674c87de1f85a9`.
Nie zmieniano aplikacji. Osobny runtime `kuking-492-overlay-browser`,
baza `kuking_492_overlay_browser`, PostgreSQL `127.0.0.1:55439`, UTC,
mailer `array`. Wszystkie pliki archiwum porównano po wykonaniu: zero
różnic. Build wykorzystano po porównaniu bajtowym resources oraz plików
package/lock/Vite. [Zgodność źródeł](evidence/overlay492/source-verification.json).

## Wykonany scenariusz

Gość na Start, dwa motywy, tekst 140%. Rozszerzenie pełnego Chromium
wywołało `tabs.setZoom` i odczytało `tabs.getZoom`: 2.0. Wynikowy viewport
320×740 CSS px, DPR 2 i font body 25,2 px potwierdzono osobno.
Motyw i skalę tekstu wybrano rzeczywistym panelem aplikacji. Przed każdą
próbą usunięto wyłącznie znacznik poznania podpowiedzi w nowym prywatnym
profilu, odtwarzając pierwszą wizytę. Po kliknięciu „Rozumiem” znacznika
już nie resetowano.

- Podpowiedź była widoczna; „Rozumiem” zamknęło ją w obu motywach.
  Po reload pozostała zamknięta.
- Przewinięcie o 800 px w dół schowało nagłówek: dolna krawędź −16 px.
  Przewinięcie o 200 px w górę ponownie go pokazało.
- Po reload wykonano osiem rzeczywistych Tab w każdym motywie.
  Każdy aktywny element miał focus-visible, cały prostokąt w viewport
  i trafienie środka przez elementFromPoint w siebie lub potomka.
  Pierwszym był odnośnik „Przejdź do treści”.

[Wynik pomiarów](evidence/overlay492/result.json): 2/2 PASS wyłącznie
w powyższym zakresie. Pierwszy pomiar bez oczekiwania uchwycił transformację
odnośnika pomijania w trakcie ruchu. Powtórzenie z 250 ms po Tab zachowało
wszystkie asercje i potwierdziło pełną widoczność. Początkowy headless shell
nie uruchomił rozszerzenia; końcowy przebieg użył pełnego Chromium i jawnego
odczytu zoomu. Nie zastępowano zoomu zwiększeniem font-size.

## Ogląd i granice

Obejrzano sześć zrzutów viewportu: light-first-tab, dark-before, dark-down,
light-up, dark-tab i light-before. Do repo dołączono dwa reprezentatywne
obrazy publicznego widoku gościa, bez danych kont, tokenów ani poświadczeń:
[podpowiedź przed zamknięciem](evidence/overlay492/dark-before.png) oraz
[pierwszy Tab](evidence/overlay492/light-first-tab.png).

Podpowiedź przed zamknięciem faktycznie przykrywa część tekstu. Przycisk
„Rozumiem” jest dostępny. Przywrócony nagłówek i stały przycisk „Wygląd”
także nakładają się na tekst w niektórych zatrzymanych kadrach. Wynik
nie oznacza całkowitego braku zasłaniania. W wykonanym scenariuszu treść
można odsłonić przewijaniem i zamknięciem podpowiedzi, a zmierzone aktywne
odnośniki nie były zasłonięte. Nie potwierdzono nowego defektu utraty dostępu
do treści.

Nie badano zalogowanego Start, wszystkich odnośników i fragmentów strony,
fizycznego urządzenia ani dolnej nawigacji #638. To uzupełnienie konkretnej
obserwacji #492, nie ponowny odbiór całej marki lub produkcji. Pozostałe PNG
i helper znajdują się w kanonicznym `output/492-overlay-zoom-receipt/`
oraz własnym prywatnym runtime pomiaru.
