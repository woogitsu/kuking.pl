## D-275 — Reguła doboru treści: zamknięta lista dozwolonych reguł (#1806, #1781, sprostowanie D-194, 25 września 2026)

**Data:** 25 września 2026 · Decyzja właściciela (#1781) · Status: **obowiązuje**

### Problem

`AGENTS.md` §8 mówił o zakazie „skomplikowanego rankingu bez danych”, a §12
o „algorytmicznym feedzie — nigdy”. Tymczasem w kodzie już działają reguły
doboru: najwyżej jeden wpis od osoby w „Świeżo z Kuking” (#940), blokady,
tablica „kuKINGi na dziś” układana przez gospodarza, propozycje osób. Zdanie
„żadnego doboru, nigdy” było więc sprzeczne z kodem, a „bez danych” brzmiało
tak, jakby ranking po popularności był tylko odłożony do czasu, aż dane się
pojawią. D-194 dopuszczał dosłownie podnoszenie widoczności **za**
ugotowanie i twierdził, że „lajk jest i zostaje” — polubienia w kodzie nie ma.

### Decyzja

Zamknięta lista dozwolonych reguł, łącznie ze zwijaniem serii w Obserwowanych.
Pełne brzmienie stoi w `AGENTS.md` §8; w skrócie:

- **żadna lista wpisów ani osób** nie jest układana ani przycinana według reakcji
  innych (obserwujący, „Ugotowałem”, reakcje, zapisy, komentarze, odsłony) ani
  według przewidywania gustu z zachowania widza;
- dozwolone **wyłącznie**: kolejność po czasie, równość autorów, wybór gospodarza
  oznaczony jako jego wybór, bramki widoczności i blokady, jawne polecenia widza
  (obserwuj, ukryj) z listą do cofnięcia;
- w **Obserwowanych** nic nie znika poza bramkami i blokadami; dopuszczalne jest
  tylko zwinięcie serii jednej osoby bez zmiany kolejności;
- każda nowa reguła = wpis w tym dzienniku + aktualizacja „Jak dobieramy wpisy”
  + strażnik.

`AGENTS.md` §12: w anty-wzorcach „algorytmiczny feed” zastępuje „ranking po
popularności i uczenie z zachowania (§8)”.

### Dlaczego zamknięta lista, a nie zakaz „algorytmu”

„Algorytm” to każda reguła, łącznie z `ORDER BY published_at`. Zakaz
sformułowany tym słowem albo łamie się sam (dzisiejsze reguły równości
autorów i blokad), albo jest interpretowany dowolnie. Lista mówi, co **wolno**,
więc spór przy kolejnej funkcji brzmi „czy to jest na liście”, a nie „czy to
już algorytm”. Szkoda, której zapobiega, jest ta sama co w starym §8: dobór po
popularności dzieli ludzi na widzianych i niewidzianych, a niewidziani
przestają publikować — w społeczności ludzi, którzy gotują zwyczajnie, to jest
większość. Podstawa: zestawienie ośmiu opinii zewnętrznych i trzech researchy
(UX 50+, prawo, mechanika feedu) w #1781, research projektu w PR #1783.

### Co to zmienia w kodzie dziś

Nic w działaniu serwisu. Przegląd `app/Domain/Digest` przy tej decyzji:
tygodniowy list układa i przycina wszystkie trzy sekcje po czasie
(`cooked_at`, `follows.created_at`, `published_at`), a kolejka wysyłki po czasie
ostatniego listu — reguła nie jest łamana. Strażnik
`FeedNieSortujePoMierzeReakcjiTest` obejmuje od teraz także `app/Domain/Digest`
(czwarta powierzchnia z wpisami), zna słowa odsłon i przyszłej reakcji
„Smakowicie wygląda” (#1813), a kontrola ujemna w
`scripts/kontrole-negatywne-alfa08.py` podmienia sortowanie wpisów w liście
na licznik wykonań i wymaga czerwieni.

Strażnik mierzy tylko **sortowanie po mierze cudzych reakcji**. „Przewidywania
gustu z zachowania widza” i „nic nie znika w Obserwowanych” nie da się dziś
zmierzyć gripem — pilnuje ich przegląd człowieka z tym wpisem w ręku.

Strony „Jak dobieramy wpisy” na `main` jeszcze nie ma (stan na 25 września
2026); jej powstanie jest osobną częścią wdrożenia #1781. Do tego czasu
wymóg jej aktualizacji oznacza opis nowej reguły w tym dzienniku.

### Sprostowanie D-194

D-194 dostaje zdanie „Liczba »Ugotowałem« ani reakcji nie wpływa na kolejność
ani dobór”. Fragment o lajku poprawiony: polubienia nie ma, lżejszą reakcją
będzie „Smakowicie wygląda” (#1813). Hierarchia sygnałów zostaje — dotyczy tego,
co człowiek widzi przy wpisie i o czym dostaje powiadomienie, nie doboru list.

### Wycofanie

Tylko decyzją właściciela. Technicznie: przywrócić poprzednie brzmienie
`AGENTS.md` §8 i §12 oraz D-194 i zawęzić `pliki()` strażnika z powrotem do
`app/Domain/Feed`. Schemat bazy się nie zmienia.

📄 `AGENTS.md` · `tests/Feature/FeedNieSortujePoMierzeReakcjiTest.php` ·
`scripts/kontrole-negatywne-alfa08.py` · `app/Domain/Digest/ZbierzTresciDigestu.php` · D-194
