# Weryfikacja Alfa 0.8

Data: 12 września 2026. PR #483, scalony jako `86f84a422716b44c733cecdd80984d120c4cf84b`.
Sprawdzony przed scaleniem commit: `37e1627aac159bc1a6649eedfaea6f325c9aaaa3`.
[Pełny przebieg CI](https://github.com/woogitsu/kuking.pl/actions/runs/34715588066).

## Wyniki

- Wszystkie 9 zadań CI zaliczone.
- PostgreSQL 18: **3631 testów, 73180 asercji, zero porażek**.
- Pint, Larastan, Vite, audyt zależności, testy równoczesnego zapisu oraz obrazy Docker z testami dymnymi: zaliczone.
- Kafel dodawania: 15 wariantów (320/360/390/414/768 px × tekst zwykły, ustawienie 140%, czcionka przeglądarki 200%). Zero kafli wyższych niż okno, celów mniejszych niż 48×48 px, tytułów i podpisów poniżej 18 px, przepełnień poziomych oraz nakładek po Tab.
- axe: zero naruszeń, zero przepełnień poziomych. Dwa ostrzeżenia częściowego zasłonięcia odnośnika autora, opisane niżej.
- Lighthouse: zero niezaliczonych ekranów z 8.

## Kontrole negatywne

Skrypt `scripts/kontrole-negatywne-alfa08.py` uruchomiono w izolowanym zadaniu CI.
Najpierw przebiegi dodatnie: 14 testów wyboru zeszytu / 102 asercje oraz
6 testów kafla / 219 asercji. Potem każda mutacja oddzielnie, kopia poza repo,
przywrócenie przez `cp`, porównanie md5 i ponowny dodatni test.
Pełna suita wykonana po przywróceniu wszystkich źródeł.

| Mutacja | Porażki wykryte | md5 oryginału i przywróconego pliku | md5 mutacji |
|---|---:|---|---|
| Usunięcie UUID | 2 | `9ebda36e9134c20295bb9ea967330597` | `c0a4e103a4aee448912e202bd9e234ab` |
| Usunięcie własności | 2 | `9ebda36e9134c20295bb9ea967330597` | `b411e6bd21cfe1ccc39147cd2bc5f68f` |
| Usunięcie komunikatu | 8 | `b6a90cdc09dd635d4d89faa8a0a34211` | `7719d9a1ecabfe3748d0a5e3017c52f5` |
| Podpis 16 px | 1 | `2ed312b35c912b1e1b01bcbe101b48eb` | `17e586ac371b592111e64a8779bc44a2` |

Lokalnie sprawdzono 72 pary kolorów skryptem `scripts/kontrast-marki.mjs`.
Zmiana jasnego przycisku marki na biały spowodowała kontrast 1.000 i exit 1;
po przywróceniu ponownie 72 wyniki dodatnie.
md5 tokenów przed/po: `37449121001f817929036917d56b26ce`;
mutacja: `64898e454bc36576faf9f25555b88c47`.

## Co poprawiono w trakcie weryfikacji

Początkowy nagłówek naruszał kontrakt bezpośredniego h1; poprawiono strukturę.
Dodane @error wywoływało błąd 500 tam, gdzie middleware nie udostępniało
zmiennej errors; odczyt komunikatu przeniesiono do sesji. Skrót @php kolidował
z dalszym blokiem Blade; zastąpiono go pełnym blokiem.
Klient testowy nie przenosi automatycznie Set-Cookie: test powrotu zachowuje
rzeczywiste ID sesji przez withCookie. Nie wstrzykuje gotowego błędu do sesji.
Test usunięcia komunikatu rzeczywiście daje osiem porażek.

## Granice pomiaru

- #484: stary skrypt pomiaru kafla nie odrzuca wszystkich raportowanych naruszeń kodem wyjścia. Tutaj odczytano konkretne wartości, nie tylko status zadania.
- #485: na tablicy i w wyszukiwaniu przy 320 px oraz czcionce 200% odnośnik bardzo długiej nazwy autora jest w 25% pod dolną belką. Nie ukryto ostrzeżenia ani nie podniesiono progu.
- Brak lokalnego PHP, Composera, PostgreSQL i przeglądarki. Instalacja apt nie powiodła się z powodu uprawnień i proxy. Wyniki aplikacji pochodzą z CI.
- Raport dotyczy sprawdzonego kodu. Sam w sobie nie potwierdza przełączenia ruchu na Railway.
