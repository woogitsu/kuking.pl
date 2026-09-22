# Odbiór pełnej wiadomości w panelu moderacji — 14 września 2026

## Zakres i źródło

Kod: `fc7473ee936323b97f00842e424a8c3923712a48` (PR #525, przed scaleniem).
Uruchomiona rzeczywista aplikacja Laravel, lokalny Chromium, izolowana baza
`kuking_audit429` na PostgreSQL 55439. Moderator utworzony wyłącznie jako
lokalna próbka przeszedł logowanie i rzeczywiste włączenie 2FA w interfejsie.
Otworzono listę wiadomości, następnie szczegóły rzeczywistego rekordu tej bazy.
Nie wysyłano odpowiedzi i nie odczytywano wiadomości produkcyjnych.

## Wykonane sprawdzenia

- Pełna wiadomość „Coś nie działa”, długa treść (30 powtórzeń zdania próbki),
  adres z 60-znakową częścią lokalną i 60-znakową domeną oraz długi adres strony.
- 320 i 1440 px, tekst aplikacji 140%, jasny i ciemny motyw: 4 konfiguracje.
- Inter w przeliczonym stylu. Szerokość dokumentu równa szerokości widoku.
  Przyciski miały 59,5 px wysokości; „Wyślij odpowiedź” przy 320 px 91 px.
- Rzeczywiste przejście Tab od pierwszego odnośnika głównej treści przez
  odpowiedź, wysyłanie, rozwijaną pomoc, grupę statusu, notatkę i zapis.
  Wszystkie sześć przystanków odwiedzone w każdej konfiguracji. Grupa radio
  jest jednym przystankiem Tab — nie sprawdzono tu zmiany wyboru strzałkami.
- Obejrzano zrzuty szerokiego nagłówka i treści, wąskiej sekcji pochodzenia,
  formularza odpowiedzi i notatki. Długie ciągi łamią się bez obcinania.
- Przyciski osiągnięte Tab pozostawały w widoku. Widoczny fokus przycisków
  jest realizowany cieniem, nie samym `outline-width`: po odczekaniu 400 ms
  na „Zapisz” w motywie ciemnym odczytano obwódkę tła 2 px i niebieską 5 px.
  Samo `outline-width: 3px` byłoby niewystarczającym dowodem (styl `none`).

## Dowody lokalne

Zrzuty w ignorowanym `output/playwright/`:
`admin-message-1440-false-h1.png`, `admin-final-320-false-origin.png`,
`admin-final-320-true-save-focus.png`, `admin-final-1440-false-send-focus.png`,
`admin-focus-settled.png`. Wczesny pełnostronicowy zrzut 320×9832 był zbyt
pomniejszony do oceny; zastąpiły go zrzuty obszarów. Wczesny selektor `h2`
wybierał nagłówek nawigacji, dlatego sekcję treści sprawdzono ponownie przez
`main h2`. Nie traktujemy tych wczesnych pomiarów jako odbioru sekcji treści.

## Dodatkowe stany

Przygotowano wyłącznie lokalne rekordy historii odpowiedzi: wysłana,
nieudana i nierozstrzygnięta. Nie wysyłano żadnego listu. Osobny rekord
wiadomości nie ma autora ani adresu kontaktowego. Osiem dalszych
konfiguracji (historia/brak adresu × 320/1440 × dwa motywy, tekst 140%)
nie miało poziomego przepełnienia. Bez adresu nie występował przycisk
wysyłania. Obejrzano reprezentatywny błąd wysyłki, długi techniczny tekst
przyczyny, początek nierozstrzygniętej odpowiedzi i brak adresu.
To odbiór wyglądu zapisanych stanów, nie dowód rzeczywistego działania
dostawcy poczty. Zrzuty: `output/playwright/admin-history-*.png`
i `output/playwright/admin-noaddress-*.png`.

Nieprawidłowy POST notatki ujawnił mylący nagłówek walidacji.
Naprawę i cztery konfiguracje po poprawce opisuje
[raport #527](PODSUMOWANIE_WALIDACJI_527.md). Zachowano 2001 znaków
nieprawidłowej notatki; nie zapisano ich do wiadomości.

## Wynik i ograniczenia

Nie potwierdzono błędu układu w tym zakresie. Nie zmieniano kodu tego ekranu.
To lokalny odbiór wymienionych stanów, a nie całego panelu moderacji.
Nie obejmuje wysyłania e-mail, pełnych zgłoszeń i odwołań, klawiatury
telefonu ani prawdziwego zoomu 200%. Tekst 140% nie jest zoomem przeglądarki.
Pełny port marki pozostaje **CZĘŚCIOWO** odebrany.
