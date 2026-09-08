# Wyniki testów v3.1

Wykonano: 7 września 2026. Badano dostarczone makiety, nie uruchomiony serwis produkcyjny.

| Kontrola | Oryginał v3 | V3.1 |
|---|---:|---:|
| Pełne dokumenty HTML | 13 | 13 |
| Kombinacje szerokość / tekst / motyw | 624 | 624 |
| Kombinacje z poziomym przewijaniem dokumentu | 12 | 0 |
| Kombinacje z za małą kontrolką w badanych klasach | 0 | 0 |
| Wykryte błędy kontrastu tekstu na jednolitym tle | 6 | 0 |
| Niedostępne względne pliki lub kotwice HTML | 0 | 0 |
| Powtórzone identyfikatory HTML | 0 | 0 |
| Linki demonstracyjne `href="#"` | 103 | 98 |
| Test 70 par tokenów w Node 22.16.0 | nie wykonał się | 70/70 |

Osobne testy v3.1: **8/8** przełączników działa myszą; **8/8** działa Spacją; **96/96** pomiarów zgodnej prawej krawędzi stopki i ostatniej akcji nagłówka (tolerancja 1 px); **0** przypadków przykrycia kontrolki dolną nawigacją po przewinięciu na koniec; **8/8** przełączników z obrysem fokusu 3 px w emulacji wymuszonych kolorów. Sprawdzono kontrakty trzech formularzy kreatora, w tym powiązanie bocznego zapisu i multipart w obu formularzach zawierających pliki.

Test samego walidatora: oryginalny skrypt zakończył się kodem 0 i pustym stdout; poprawiony przeprowadził 70 porównań; celowe ustawienie identycznego koloru tekstu i tła w tymczasowej kopii spowodowało kod błędu 1. Dowód: `wyniki/validator-proof.json`.

Wbudowany walidator paczki: kod wyjścia 0; wszystkie twarde reguły przeszły. Pozostawił ostrzeżenia słownikowe w dokumentacji; pełny log znajduje się w `wyniki/after-package.txt`. Nie usuwano ich, aby upiększać raport.

## Jak odtworzyć

Zainstalować zależności z `requirements.txt` w osobnym środowisku Pythona. Wymagany jest Chromium. W razie innej lokalizacji ustawić `CHROMIUM_PATH`. Skrypty domyślnie oczekują `/usr/bin/chromium`.

```sh
python 08-audyt/audit.py . /tmp/kuking-audit
python 08-audyt/interaction.py . /tmp/kuking-interaction
```

Skrypt układu zapisuje wyniki po każdej stronie. Po przerwaniu można dodać trzeci argument `resume`, ale tylko wtedy, gdy kod stron nie zmienił się od pierwszej części testu. Po zmianach użyć nowego katalogu wynikowego.

Pomiar układu bada 320/390/768/1024/1279/1280/1440/1920 px, tekst 100/150/200% oraz oba motywy, przy wysokości okna 900 px. Rozmiary dotyczą klas `.btn`, `.chip`, `.side-nav-item`, `.bottom-nav-item`, `.field-input`, `.wybor-zdjecia`; próg testu to około 48 px wysokości i 44 px szerokości z tolerancją ułamkowych pikseli. Nie jest to test wszystkich wyjątków normy WCAG.

Rzeczywisty HTML, CSS i lokalne obrazy są ładowane w pamięci testowej przeglądarki, bez nawigacji do blokowanych w tym środowisku adresów lokalnych. Font jest systemowy. Nie badano backendu, zapisu preferencji, wszystkich stanów aplikacji, prawdziwych czytników ekranu ani innych silników przeglądarek. Szczegółowe ograniczenia: `../AUDYT-V3.1.md`.
