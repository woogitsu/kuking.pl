# Kryteria odbioru #568 — warstwa dowodu przy każdym

Kod: `8a2ecb2a91bec7f7fef0f40e9d1c59b8c4735257` (main, zawiera merge #592 =
`2144a1143c51808cdc1639c984df99d5ad586fbc`). Produkcja odczytana 19 września
2026: `Alfa 0.67`, metryczka `wydanie 19 września 2026, 12:30 · 8a2ecb2` —
**ten sam commit**, więc wszystko poniżej dotyczy wersji faktycznie wdrożonej.

| # | Kryterium z issue | Stan | Warstwa dowodu |
|---|---|---|---|
| 1 | HTTP/aplikacja działa na wersji z poprawką | SPEŁNIONE | produkcja (metryczka `8a2ecb2`) + CI 35437520171 success + Deploy 35437623435 success |
| 2 | Wyszukiwanie z >200 trafieniami daje link do innego zakresu | SPEŁNIONE LOKALNIE, NIEMIERZALNE NA PRODUKCJI | test lokalny (450 wyników: okno 1 → `od_przepisu=200`) + przeglądarka + `curl` bez JS. Na produkcji takiego zbioru NIE MA (patrz „Granica dowodu”) |
| 3 | Przejście dalej pokazuje inne rekordy | SPEŁNIONE LOKALNIE | test lokalny: trzy okna 200+200+50, przecięcie zbiorów identyfikatorów puste, suma = dokładnie 450 |
| 4 | Działa powrót | SPEŁNIONE LOKALNIE **i NA PRODUKCJI** | produkcja: `od_przepisu=200` → HTTP 200, „Wróć do początku przepisów”, kliknięty href wraca do `od_przepisu=0` i przywraca wynik. Lokalnie dodatkowo Tab + Enter z klawiatury przy 320 px/ciemny/140% |
| 5 | Przepisy i osoby nie przesuwają się wzajemnie | SPEŁNIONE LOKALNIE | `DalszeWynikiWyszukiwaniaTest::test_przesuniecie_przepisow_nie_przesuwa_osob_i_pozwala_wrocic` oraz `test_powrot_osob_zachowuje_dalsze_okno_przepisow` |
| 6 | Zwykłe `ile<=200` nadal działa | SPEŁNIONE **NA PRODUKCJI** | produkcja: `?q=ka&sekcja=wszystko&ile=40` → „Znaleziono 1 przepis.”, „Znaleziono 2 osoby.” |
| 7 | Brak regresji na mobile/dużym tekście i bez JS | SPEŁNIONE LOKALNIE | 12 wariantów (320/768/1440 × jasny/ciemny × 100%/140%) na oknie 201–400: przepełnienie poziome 0 px w każdym. Bez JS: cała ścieżka trzech okien przejdzie samym `curl`-em |
| 8 | Granica dokładnie 200 (z opisu zadania) | SPEŁNIONE LOKALNIE | nowy `GraniceOkienWyszukiwaniaTest`: przy 200 wynikach `jestWiecej=false`, zero odnośników „Pokaż więcej przepisów”, komunikat „Znaleziono 200 przepisów.” |
| 9 | Granica 201 | SPEŁNIONE LOKALNIE | nowy test: dalsze okno ma dokładnie 1 przepis i komunikat „Pokazujemy przepis 201.” (liczba pojedyncza, nie „201–201”) |
| 10 | Zero wyników | SPEŁNIONE LOKALNIE | nowy test: pusty stan „Nic nie znaleźliśmy”, zero odnośników okien |
| 11 | Wynik bez frazy | SPEŁNIONE LOKALNIE | nowy test: `/szukaj?od_przepisu=400&od_osoby=400` bez `q` pokazuje zwykły ekran startowy i NIE mówi „W tym zakresie nie ma już przepisów” |
| 12 | Koszt przy dużym zbiorze | ZMIERZONE LOKALNIE | 8 zapytań SQL na żądanie przy `od_przepisu` = 0, 200 i 400 — stała, bez `COUNT` i bez N+1 |

## Granica dowodu — dlaczego kryteriów 2, 3, 5 nie da się dziś zmierzyć na produkcji

Serwis jest młody. Odczyt produkcji z 19 września 2026 (`produkcja568.txt`):

- `sitemap.xml`: **49 adresów**, z tego 41 wpisów i 3 profile publiczne,
- najszersze próbne zapytania (`bigos`, `pierogi`, `ka`, `ie`, `ni`, `za`,
  `pi`, `ma`, …) dają **maksymalnie 1 przepis i 2 osoby**,
- żadne zapytanie nie wyprodukowało ani jednego odnośnika „Pokaż więcej”.

Zbiór ponad 200 trafień **nie istnieje na produkcji** i nie da się go tam
zaobserwować bez wytworzenia setek sztucznych rekordów — czego issue wprost
zakazuje. Dlatego scena z 450 wynikami jest **lokalna**, a wszystko, co z niej
wynika, jest w tabeli oznaczone jako lokalne.

Na produkcji zmierzono natomiast to, co da się zmierzyć bez tworzenia treści:
że **kod z #592 naprawdę tam działa**. Żądania `?od_przepisu=200`
i `?od_osoby=200` zwracają HTTP 200, komunikat pustego dalszego zakresu
i działający odnośnik powrotu — kod sprzed #592 w ogóle nie znał tych
parametrów i pokazałby zwykłą listę wyników.
