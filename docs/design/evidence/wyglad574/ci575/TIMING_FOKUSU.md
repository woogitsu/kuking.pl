# Natychmiastowy pomiar fokusu — CI #575

Odbiór lokalny z 15 września 2026, Chromium 1234. Nie jest to odbiór
produkcji ani pełny audyt dostępności. Źródłowy błąd CI: przebieg
34978064181, tablica i ustawienia profilu przy szerokości 320 px i czcionce
przeglądarki 32 px.

Pomocniczy przebieg odczytał funkcję `przejdzTabemIZmierzFocus` bezpośrednio
z `scripts/dostepnosc.mjs` i wykonał ją na istniejącej lokalnej instancji.
Nie uruchamiał przygotowania bazy z pełnego automatu. Limit: 400 kroków,
Tab i natychmiastowy evaluate, siatka pokrycia 4×4, reduced-motion,
CDP Page.setFontSizes przed nawigacją, oczekiwanie na fonty przed pierwszym
Tab. Trzy powtórzenia na każdej z dwóch tras; zapis obejmuje przycisk Wygląd.

| Plik | Znaczenie |
|---|---|
| `timing-ci-before.json` | Sześć przebiegów odtwarzających błąd przed poprawką |
| `timing-ci.json` | Sześć przebiegów końcowych; pokrycie nawigacją 0 |
| `fokus-bez-czekania.json` | Cztery warianty trwałej regresji bez pauzy po Tab |
| `negative-transition-css.txt` | Fizyczne usunięcie reguły transition-property; próba 0 zielona, próba 1 czerwona |
| `negative-transition-restore.json` | MD5 i mtime CSS przed oraz po odtworzeniu; lokalizacja zewnętrznej kopii z chwili próby |
| `final-transition-positive.txt` | Ponowne sześć dodatnich przebiegów po odtworzeniu CSS |

Negatyw jest podatny na wyścig klatek. Obie próby zachowano, bez usuwania
zielonej próby ani twierdzenia, że pojedynczy przebieg zawsze odtworzy błąd.
Plik kopii wskazany w metadanych był poza repo; jego dalsza dostępność
nie jest warunkiem odczytu dowodu zgodności MD5 i mtime.

Do tej paczki nie kopiowano skryptów logowania, cookies, tokenów ani stanu
sesji. Dane zawierają wyłącznie lokalne ścieżki stron, geometrię interfejsu,
wyniki pomiarów i metadane odtworzenia pliku. Kopie sześciu plików porównano
z wynikami roboczymi przez SHA-256.
