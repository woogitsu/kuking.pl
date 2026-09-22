# Pakiet dowodów load581

Wybrany i sprawdzony zestaw z kanonicznego output/load581. Nie kopiowano katalogu hurtowo. Nie zawiera sesji, pliku manifestu z cookies, środowiska .env, haseł, tokenów, archiwum źródeł ani payloadów/formularzy/zdjęć. Odczyt wszystkich wybranych plików i kontrola pól JSON nie wykazały poświadczeń ani danych sesyjnych. Harness zawiera nazwy zmiennych cookie/manifest, ale żadnych ich wartości.

W environment.json pozostawiono runtime OPcache/PHP, wersję k6, kernel oraz podstawowe cechy CPU; pominięto zbędny pełny wykaz flag i podatności procesora. Liczby wyników zachowano. RAPORT.md otrzymał wyłącznie poprawki odstępów. Harness zachowuje dokładne bajty i SHA256. Dane są historycznym pomiarem z 15.09.2026, nie nowym uruchomieniem.

Nie dołączono skryptów tworzenia bazy, kont ani logowania, ponieważ pakiet jest dowodem, a nie automatyczną zgodą na odtworzenie danych. Ponowienie wymaga świeżej izolowanej kopii wskazanego SHA, lokalnej bazy na 55439 i prywatnych sesji uzyskanych rzeczywistym logowaniem; parametry, scenariusze i limity opisuje RAPORT.md. Sam baseline-v1.k6.js wymaga prywatnego LOAD_MANIFEST i celowo odrzuca adres poza 127.0.0.1:8185.

## Lista wybranych plików
- authenticated-1.json
- authenticated-1.txt
- authenticated-10.json
- authenticated-10.txt
- authenticated-20.json
- authenticated-20.txt
- authenticated-5.json
- authenticated-5.txt
- authenticated-resources.json
- authenticated-runs.json
- authenticated-warmup.json
- authenticated-warmup.txt
- baseline-v1.k6.js
- cold-warm.json
- environment.json
- harness-sha256.txt
- image-type-smoke.json
- login-results.json
- metadata.json
- public-1.json
- public-1.txt
- public-10.json
- public-10.txt
- public-20.json
- public-20.txt
- public-5.json
- public-5.txt
- public-resources.json
- public-runs.json
- public-warmup.json
- public-warmup.txt
- public-warmup-redirect-diagnostic.json
- public-warmup-redirect-diagnostic.txt
- RAPORT.md
- runtime-after.json
- search-1.json
- search-1.txt
- search-5.json
- search-5.txt
- search-resources.json
- search-runs.json
- smoke.json
- summary.json
- TABELE.md
- upload.txt
- upload-final.json
- upload-resources.json
- upload-results.json
- worker.txt
