# Integracja nowego stylu Kuking

12 września 2026. Dokument tego pakietu zmian; nie jest potwierdzeniem deployu.

## Zakres

Paleta i cienie zmieniają się w istniejących tokenach aplikacji. Komponenty
Blade, lokalny Inter, warstwy kart, ustawienia czytelności oraz progi układu
pozostają podstawą. Nie przenosimy statycznego prototypu w miejsce aplikacji.
Znak garnka z koroną i uśmiechem jest już komponentem i zostaje zachowany.

| Rola | Jasny | Ciemny |
|---|---|---|
| Tło strony | `#F3F4F1` | `#151714` |
| Karta | `#FFFFFF` | `#222620` |
| Powierzchnia wgłębiona | `#ECEEE9` | `#10120F` |
| Tekst | `#151714` | `#F4F5F1` |
| Tekst pomocniczy | `#555E53` | `#CBD0C6` |
| Link marki | `#BE3025` | `#FF9586` |
| Przycisk marki | `#BE3025` | `#C83B2E` |
| Obramowanie kontrolki | `#737A70` | `#929A8C` |

Kolor `#E43D30` ze starego szkicu nie jest tłem przycisku z białym napisem.
Nowy odcień czerwieni ma oddzielny wariant dla tekstu i dla wypełnienia
w ciemnym motywie. Kolory stanów i fokus pozostają osobnymi rolami.

## Strona główna

Powitanie ma odrębny nagłówek z istniejącym hasłem „Gotujemy po swojemu”.
Kafel dodawania zachowuje całą klikalną powierzchnię i opis. Podpis korzysta
z `--text-body`, czyli 18 px przy domyślnych ustawieniach, zamiast 16 px.
To miejscowa zmiana dotycząca issue #478; globalny `--text-help` nie rośnie.
Własny wiersz tekstu przy dużej czcionce z PR #479 pozostaje.

## Co mierzymy

`node scripts/kontrast-marki.mjs` czyta wartości bezpośrednio z tokenów.
Sprawdza 72 pary: oba motywy, powierzchnie, napisy, stany, obramowania
i fokus. Nie zaokrągla wyniku przed porównaniem z progiem. Brak tokenu,
niepełny odczyt albo zmiana liczby sprawdzanych par kończy pomiar błędem.
Skrypt jest częścią `npm run build`.

To nie jest pełny audyt WCAG. Obliczenie nie dowodzi kontrastu tekstu na
fotografii, rzeczywistej kaskady przy opacity ani reflow w przeglądarce.
Za te rzeczy nadal odpowiadają pomiary aplikacji w CI i kontrola ekranów.

## Stan weryfikacji lokalnej

Obliczenie par kolorów wykonano z wynikiem pozytywnym. Kontrola ujemna
i stan pozostałych bramek są zapisane w opisie PR. W tej sesji nie ma PHP,
Composera, PostgreSQL ani binarki przeglądarki. Próba `apt-get update`
zakończyła się błędami zmiany uprawnień; połączenie z repozytorium Ubuntu
zakończyło się błędem proxy. Następnie pełne testy aplikacji, kontrole
negatywne i pomiar nowej wysokości kafla zaliczono w CI. Wyniki są
w `docs/design/WERYFIKACJA_ALFA_08.md`. Historyczne wymiary z PR #479
nie są wynikami tego pakietu.

## Wycofanie

Wycofanie commita przywraca poprzednie tokeny, nagłówek, podpis i numer
wersji. Pakiet stylu nie dodaje migracji i nie zmienia danych użytkowników.
