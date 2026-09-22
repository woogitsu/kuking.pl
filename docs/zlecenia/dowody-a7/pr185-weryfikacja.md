# A7-3 — weryfikacja pomiarów PR #185 / issue #116

Nie wykonywano pomiaru od zera, zgodnie z korektą zlecenia. Przeczytano metodę, plany `EXPLAIN` i testy PR #185.

## Co się broni

- Skala jest zgodna z A7: 10 000 kont, 40 000 przepisów, 80 000 wpisów; dodatkowo 80 000 składników i 80 blokad. Dane syntetyczne, ale cardinality jest reprezentatywne.
- Pomiar PRZED/PO wykonano w tej samej bazie i sesji, ciepłe bufory, pierwsza próba odrzucona, mediana z 5 przebiegów.
- Teza „OR blokuje indeks trigramowy” została poprawnie obalona jako reguła: wyszukiwanie ludzi używa `BitmapOr` z trzema `Bitmap Index Scan`.
- Po zmianie gałąź tytułu dla `pierogi` faktycznie używa `recipes_title_trgm_idx`: `Bitmap Index Scan`, 17 644 kandydatów, 1 783 po rechecku. To nie jest indeks istniejący tylko na papierze.
- Dla frazy dwuznakowej `ry` brak skutecznego trigramu i skan/recheck bardzo dużej części tabeli jest oczekiwanym ograniczeniem, nie dowodem regresji migracji.

Przykładowy pełniejszy wycinek podany w PR: `recipes('pierogi')` PRZED 156,580 ms, PO 111,678 ms. Gałąź trigramowa tytułu spadła z ok. 118,8 do 81,8 ms przy tym samym zbiorze 17 644 kandydatów i 1 783 trafień po rechecku.

## Czego dowód nie rozstrzyga

- Pomiar był na PostgreSQL 16.13, nie na wymaganym w zleceniu PostgreSQL 18. To nie unieważnia kształtu planu, ale ogranicza przenoszenie bezwzględnych czasów.
- Do repo trafiły wycinki planów, nie surowe pełne drzewa JSON. Da się zweryfikować węzły rozstrzygające tezę, ale nie odtworzyć całej analizy planera z samego PR.
- Trafność wyników nie jest zweryfikowana na realnym rozkładzie zapytań. Test regresyjny sprawdza kilka przypadków, a dokument sam pokazuje istniejący problem jakościowy progu 0,12 (np. `sajgonki z krewetkami` daje wiele luźnych wyników). Nie ma dowodu, że migracja pogorszyła trafność, ale nie ma też pomiaru wystarczającego, by ogłosić brak regresji trafności dla populacji zapytań.

**Werdykt:** wniosek wydajnościowy i faktyczne użycie indeksu są wiarygodne. Teza o niepogorszonej trafności pozostaje **NIEROZSTRZYGNIĘTA** poza kilkoma testami regresyjnymi.
