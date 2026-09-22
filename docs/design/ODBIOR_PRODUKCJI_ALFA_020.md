# Odbiór produkcji Alfa 0.20

14 września 2026, około 00:55 czasu polskiego.

## Kod i wdrożenie

- PR #519: head `dcd1c5958c4cf23ef2a7fb6194f1aa37fc3e855d`.
- Merge i SHA produkcji: `ce82638dc6a69be3f73dbc0094db7cb7cede6e97`.
- CI main `34787351885`: wszystkie dziesięć zadań success.
- Railway deployment `6427314758`: success od `2026-09-13T22:53:21Z`; poprzedni `6426180376` inactive.
- Deploy `34788101667`: success. Wstępny `34787356016` skipped był zdarzeniem przed sukcesem.
- Publiczne HTTP 200: Alfa 0.20, wydanie 14 września 00:52, `ce82638`.

## Zasoby

| Zasób | Dowód |
|---|---|
| CSS app-B59Zb5HG.css | HTTP 200, SHA256 `9f0d3bf70965c52d4fda1c15a3baf2915f249e3ca8a4020bcedce0da81e749cf` |
| JS app-DXNAnudp.js | HTTP 200, SHA256 `e94fdd3ddd8d45e79644a222e092c5a0b80d3a21f4941d8af8684425a4749d75` |
| Inter latin-ext DO1Apj_S | HTTP 200, font/woff2, 85068 bajtów |
| Inter latin Dx4kXJAl | HTTP 200, font/woff2, 48256 bajtów |

## Ogląd i ograniczenia

Osobna karta zalogowanego Chrome po odświeżeniu pokazała Alfa 0.20 / ce82638.
Obejrzano rzeczywisty zrzut powiadomień: zwykłe odpowiedzi i obserwowania
mają akcję obok treści. Pierwszy wpis zachowuje oddzielną kompozycję,
zgodnie z zakresem pięciu zwykłych typów. Pełne decyzje są nadal w drzewie strony.

Odbiór GET zainteresowań: duży nagłówek i prawdziwy pusty stan z przyciskiem
„Dalej”. Brak promowanych tagów na produkcji oznacza brak kafli. Nie dodawano
danych ani nie klikano akcji odczytu, zapisu zainteresowań czy zmiany motywu.
Prywatnych treści i zrzutów użytkowników nie zapisano w repo.

Produkcję obejrzano na komputerze w jasnym motywie. Pełna macierz skal,
oba motywy, kafle zainteresowań i symulowane stany pozostają dowodem
lokalnym oraz CI. To nie jest odbiór wszystkich ekranów portalu.
Pełny port marki: **CZĘŚCIOWO**.
