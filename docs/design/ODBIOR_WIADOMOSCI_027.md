# Ogląd 18 wiadomości — uzupełnienie #492 / Alfa 0.27

Źródła: `595f41fa8a4c21642f21a566c27946c5811125a9`. Izolowana kopia `/tmp/kuking-measure444`; render rzeczywistych widoków i `Notification::toMail()->render()`, bez wysyłki i operacji na bazie. Dane z `Tests\Support\WlasneListyMarki` (11 własnych listów, digest ze wszystkimi sekcjami) oraz fixture `StandardoweWiadomosciMarkiTest` (7 MailMessage). APP_URL `https://kuking.test`, MAIL_MAILER `array`. Wszystkie żądania sieciowe Chromium blokowane; żadnych prawdziwych kont ani kliknięć w linki.

## Wynik oglądu

Obejrzano każdy z 36 pełnych zrzutów: 18 wiadomości × 320/640 CSS px, Chromium. Brak stwierdzonego błędu układu wymagającego poprawki w tych fixture. Nagłówki, akapity, przyciski i stopki mieszczą się; długie adresy z testowymi tokenami i podpisem zawijają się. Nie ma poziomego przewijania: scrollWidth równe 320/640 w każdym stanie. Pomiar tekstowych elementów nie znalazł pisma poniżej 18 px.

Dłuższe etykiety przycisków mają na 320 px dwa wiersze, bez obcięcia. Digest pokazuje wszystkie sekcje, przycisk podziękowania, odnośnik wpisu i wypisania. Standardowa decyzja ma najdłuższy układ (2007 px przy 320), z zachowanym przyciskiem odwołania i pełnym zapasowym adresem. Długość tych wiadomości oznacza przewijanie pionowe; nie jest poziomowym przepełnieniem.

## Dokładna macierz obejrzanych wiadomości

| Wiadomość | Ogląd 320 | Ogląd 640 | Wysokość dokumentu 320 / 640 |
|---|---|---|---|
| data-export-ready | obejrzano | obejrzano | 1459 / 900 px |
| haslo-zamiast-linku | obejrzano | obejrzano | 1871 / 1075 px |
| link-do-logowania | obejrzano | obejrzano | 1491 / 934 px |
| nowe-haslo | obejrzano | obejrzano | 1234 / 900 px |
| odpowiedz-na-wiadomosc | obejrzano | obejrzano | 900 / 900 px |
| podsumowanie-tygodnia | obejrzano | obejrzano | 1551 / 1136 px |
| potwierdz-adres | obejrzano | obejrzano | 1378 / 900 px |
| potwierdz-nowy-adres | obejrzano | obejrzano | 1498 / 900 px |
| proba-wejscia-kontem-facebooka | obejrzano | obejrzano | 1815 / 1070 px |
| standard-alarm | obejrzano | obejrzano | 1136 / 957 px |
| standard-decyzja | obejrzano | obejrzano | 2007 / 1439 px |
| standard-odpowiedz | obejrzano | obejrzano | 900 / 900 px |
| standard-odwolanie | obejrzano | obejrzano | 900 / 900 px |
| standard-podsumowanie | obejrzano | obejrzano | 1025 / 900 px |
| standard-termin | obejrzano | obejrzano | 1560 / 1078 px |
| standard-zgloszenie | obejrzano | obejrzano | 1082 / 900 px |
| zaproszenie-do-zalozenia-konta | obejrzano | obejrzano | 1582 / 964 px |
| zgloszona-zmiana-adresu | obejrzano | obejrzano | 1626 / 1032 px |

## Granice dowodu

To render w Chromium, **nie odbiór w Gmailu, Outlooku, Apple Mail ani w silniku Worda**. Nie badano filtrowania stylów przez klienta pocztowego, dostarczalności, trybu ciemnego, wymuszonych kolorów, czytnika ekranu ani wszystkich wariantów treści/decyzji. Nie wysyłano wiadomości, nie sprawdzano działania linków ani uprawnień na stronach docelowych. Podpis w linku jest wygenerowany wyłącznie lokalnym kluczem dla testowego modelu i hosta .test. Nie wykonano pełnego zestawu PHP ani testów aplikacji.

Lokalne dowody w `output/playwright/mail027/`: 18 HTML, 36 PNG, `measurements.json`, `render-mail027.php` i `mail027.mjs`. Macierz uzupełnia ogląd wizualny wszystkich 18 nazw, bez rozszerzania wniosku na prawdziwe programy pocztowe.
Dwa reprezentatywne zrzuty zachowano w repo: [decyzja 320](evidence/mail027/standard-decyzja-320.png) i [digest 640](evidence/mail027/podsumowanie-tygodnia-640.png). Dane i podpisy dotyczą wyłącznie lokalnych modeli i domeny testowej. Pełny ogląd wykonał subagent; agent prowadzący dodatkowo obejrzał oba zachowane przykłady.
