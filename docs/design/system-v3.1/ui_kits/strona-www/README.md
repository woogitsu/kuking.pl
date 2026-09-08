# UI kit — publiczna twarz serwisu

Odtworzenie trzech stron z `04-strona-www/` paczki źródłowej. Wszystkie napisy
pochodzą z `COPY_STYLE.md` i `BRAND_EXTENDED.md`; źródło każdego zdania jest
wypisane w `STRONA-WWW.md` §6.

| Plik | Ekran |
|---|---|
| `index.html` | klikalna makieta trzech stron plus przełącznik motywu w stopce |
| `Powitalna.jsx` | strona główna dla gościa — jedyne miejsce, które ma przekonać do założenia konta |
| `OKuking.jsx` | kto to prowadzi, po co i czego tu nie ma |
| `PrzepisPubliczny.jsx` | przepis widziany przez kogoś, kto trafił z wyszukiwarki |

## Reguły, które trzymają te strony

- **Pasy, nie siatka.** Ekran zalogowanego jest siatką trzykolumnową; strona
  publiczna używa pełnoszerokich pasów z treścią wyśrodkowaną w środku.
  Szerokość pasa to `--container-strona` (1040 px) — dokładnie ta sama, jaką ma
  ekran zalogowanego bez szyny, więc przejście do serwisu nie jest zmianą
  szerokości strony. Strona przepisu ma własny pas 1104 px = kolumna czytania
  plus panel.
- **Rytm teł:** jasny → wgłębiony → jasny → **ciemny** → ciepły → jasny. Ciemny
  pas wypada w połowie i jest jedynym mocnym akcentem: dostaje go „Ugotowałem”,
  czyli ta rzecz, której nie ma nikt inny.
- **Belka nie jest przyklejona.** Przyklejona zabiera wiersz tekstu przy każdym
  przewinięciu, a przy skali 150% dwa — i pokazywałaby grę słowem „kuKING” na
  każdym ekranie. Gra słowem pada na stronie powitalnej dokładnie raz, w belce.
- **Jedno wezwanie do działania:** założyć konto. Przycisk główny występuje dwa
  razy i za każdym razem prowadzi w to samo miejsce; „Najpierw się rozejrzę”
  jest cichym linkiem, nie drugą akcją główną.
- **Żadnych liczb, których nie ma z czego policzyć.** Sekcja „Komu wyszło” na
  stronie przepisu pokazuje pusty stan z zaproszeniem, a nie wymyślone wykonania.

## Czego tu nie ma i dlaczego

- **Bloku JSON-LD** (`Recipe`) z `przepis-publiczny.html` — makieta go nie
  potrzebuje, ale wdrożenie tak. Zasada: w bloku nie ma ani jednej rzeczy,
  której nie ma na stronie widocznej dla człowieka; bez `aggregateRating`.
- **Trzech małych zdjęć** (`soup`, `cake`, `pasta`, po 92 px szerokości) —
  w rozmiarze, w którym cokolwiek widać, byłyby rozmyte.
- **Sześciu zdań wymagających potwierdzenia właściciela** przed publikacją —
  są wypisane w `STRONA-WWW.md` §8 i stoją tu w brzmieniu z paczki.
