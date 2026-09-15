# Odmiana czasu minutnika — #548

Stan: poprawka lokalna Alfa 0.34, przed CI i wdrożeniem.
Baza: main22f89ac59777e5133a5b542cd09e5b7de6824734, produkcja Alfa0.33.

RecipeStep::timerLabel ma opcjonalny wariant afterNa. Domyślny mianownik
zostaje; instrukcja gotowania i podgląd kreatora wybierają biernik.
Przekazany atrybut jest używany przez istniejący JS, dlatego komunikat
po uruchomieniu również mówi „na 1 minutę”. Sekundy i odliczanie bez zmian.
Nie dodano funkcji, migracji ani zmian prywatności.

Lokalnie 51 testów / 258 asercji (RecipeStepTimerLabelTest, CookingModeTest,
MinutnikIZdjecieKrokuTest): formy pojedyncze, mnogie, null/zero, zachowane
sekundy, render gotowania i podglądu kreatora. Trzy fizyczne negatywy
prawdziwych źródeł: model, cooking, wizard. Każdy oblał, po przywróceniu
MD5 i mtime oraz wyczyszczeniu skompilowanych widoków wynik dodatni.
Pierwszy powrót testu po negatywie cooking wymagał view:clear: przywrócony
mtime był starszy od kompilacji mutanta. Źródło było przywrócone; nie
zmieniono testu, by ukryć cache. Dowody: evidence/minutnik548/negatywy.json.

Odbiór lokalnej aplikacji: 320px z rzeczywistym zoomem200% oraz1440px/100%,
oba motywy, tekst140%. Cztery warianty PASS: rzeczywiste Enter uruchamia
minutnik, aria-live „Minutnik ustawiony na 1 minutę.”, start1:00 i tick0:59,
przycisk blokuje ponowny start, brak poziomego overflow. Obejrzano cztery
zrzuty. Przy320/140% długa etykieta przycisku łamie ostatnią literę słowa;
nie poprawiano układu przy okazji odmiany i nie uznawano tego za pełny
odbiór kompozycji. Dźwięk, wibracja i fizyczny telefon niepotwierdzone.
Zrzuty zoomu pobrano przez CDP, bo standardowy screenshot dawał puste tło.

Niezależny readonly review nie znalazł blokera. Nie zastępuje wymaganych
kontroli CI ani odbioru produkcji. Pełny port marki nadal CZĘŚCIOWO.
