# Korpus prawdziwych zdjęć w przyrządzie #605

Generator i rampa są istniejącymi narzędziami `scripts/*obciazenia-605*`.
Opcja `--korpus /bezwzgledna/sciezka/korpus.json` rozszerza scenariusz
`upload`: kolejne wgrania przechodzą po wszystkich pozycjach, niezależnie
od wyboru pozostałych scenariuszy. Nie zastępuje mieszanki testem samych zdjęć.
Rampa i pojedyncza seria przekazują tę opcję przez `KORPUS_ZDJEC`.

```json
[
  {
    "path": "/lokalny/korpus/telefon.jpg",
    "sha256": "suma SHA-256 dokładnych bajtów pliku",
    "mime": "image/jpeg",
    "expected": "accepted"
  },
  {
    "path": "/lokalny/korpus/uciety.jpg",
    "sha256": "suma SHA-256 kontrolowanie uszkodzonego pliku",
    "mime": "image/jpeg",
    "expected": "rejected"
  }
]
```

Przed napływem generator sprawdza wszystkie sumy. Korpus pusty, brak pliku,
nieznany MIME lub zmienione bajty zatrzymują bieg. Źródło, autor, licencja,
wymiary i sposób utworzenia uszkodzeń należą do osobnego manifestu źródeł.
Zdjęcia oraz manifest sesji pozostają poza repozytorium. Nie publikować
ciasteczek, tokenów, haseł ani pełnego zrzutu EXIF/GPS.

`uploady` w wyniku pokazuje każdy plik, jego sumę, oczekiwany wynik, liczbę
wysłań, liczbę zgodnych odpowiedzi i rozkład statusów. Zero wysłań konkretnego
pliku oznacza brak jego pomiaru w tym stopniu rampy. Nie wolno na podstawie
samej obecności pliku w korpusie twierdzić, że został zmierzony.

Dla korpusu HTTP 302 nie wystarcza: przyjęcie wymaga przekierowania do
lokalnego `/wpisy/{uuid}`, oczekiwane odrzucenie — do `/dodaj/zdjecie`.
Generator wysyła ten formularz jako Referer. Oczekiwane odrzucenie jest
poprawnie obsłużonym żądaniem i wchodzi do przepustowości; nie oznacza
utworzenia wpisu ani przetworzenia zdjęcia. Raport musi osobno sprawdzić
liczby wpisów, stan mediów, warianty i kolejkę. Sam redirect nie dowodzi
pomyślnego wykonania zadania w tle ani konkretnej przyczyny walidacji.

Bez `--korpus` pozostaje historyczny pojedynczy plik
`kuking-b605-12mpx.jpg`. **Pliki tworzone przez
`scripts/zdjecia-obciazenia-605.php` są syntetyczne**, mimo określenia
„prawdziwe zdjęcia” w dawnej metodzie. Nie spełniają wymogu fotografii
z aparatów i telefonów. Historycznych czasów nie przeliczać na nowy korpus.

Sprawdzenie przyrządu: `node scripts/przyrzad-605.test.mjs` uruchamia także
`korpus-605.test.mjs`. Mały serwer kontrolny sprawdza przesłane nazwy plików,
publikację, odrzucenie i odmowę zmienionej sumy. Jego bajty są atrapą
transmisji, nie zdjęciami do benchmarku.
Pomiar tego korpusu, rampa do nasycenia i ograniczenia przyrządu:
[raport z 20.09.2026](../infra/evidence/obciazenie605/2026-09-20-gpt/RAPORT.md).
Próbnik po poprawce `e96b6b71` zapisuje `null` w CPU procesu przy zmianie PID
lub cofnięciu licznika. Odbiorca wyników musi traktować to jako brak pomiaru,
a nie zero. Historyczne ujemne wartości CPU workera są nieważne.