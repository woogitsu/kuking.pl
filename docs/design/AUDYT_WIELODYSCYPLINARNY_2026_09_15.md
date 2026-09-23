# Audyt uzupełniający i kolejność rozwoju — 15 września 2026

Źródła: 43faa3b006f0e08b7e2c9ac573229e5b5ea9963b. Dwa niezależne
audyty readonly: użyteczność istniejących funkcji oraz dostępność.
Nie zastępują pełnej macierzy #492 ani badań z prawdziwymi osobami #15.

| Ustalenie | Dowód | Dalsza praca |
|---|---|---|
| Zeszyt ukrywa przepis, lecz liczy tylko niedostępne wpisy | CollectionController::show i collections/show | #567: lokalnie odtworzyć fałszywy pusty stan, naprawić bez ujawniania treści |
| Link dalszych wyników zatrzymuje się na ile=200 | SearchController i pages/search | #568: odtworzyć na 201 przepisach i osobach, zachować koszt i prywatność |
| Pozostały czas minutnika ukryty przed drzewem dostępności | cooking.blade.php aria-hidden oraz app.js | #569: lokalna próba, dostęp na żądanie bez komunikatu co sekundę |

Wszystkie trzy mają status: **potwierdzone odczytem kodu, bez odtworzenia
scenariusza w aplikacji**. Są zapisane na GitHubie z kryteriami i granicami
dowodów. Sprawdzono otwarte i zamknięte tytuły oraz powiązane opisy.
#511, #548, #116 i #187 nie rozwiązują tych konkretnych braków.

Agent odczytał produkcyjne /szukaj na Alfa 0.34/e6d94d8; nie znalazł
nowego problemu. „Odkrywaj” jest dopuszczone późniejszą decyzją, więc
rozbieżność ze starszym komentarzem nie została zgłoszona jako błąd marki.
Odejmowanie sekundy na callback minutnika to hipoteza możliwego dryfu
w tle, wymagająca pomiaru; nie ogłaszamy potwierdzonej awarii.

## Kolejność

1. Domknąć #549: zwykły hook, PR, CI, wdrożenie, odbiór komunikatu.
2. Odtworzyć i naprawić #567 — zapis nie może wyglądać jak utrata danych.
3. Odtworzyć #568 i #569, osobne małe pakiety z regresją.
4. Wrócić do #561 i nieodebranych stanów #492; brak pomiaru sam nie jest
   błędem. Zachować wcześniejsze odbiory zamiast powtarzać cały audyt.
5. Przygotować istniejące badania #15: publikacja, wyszukiwanie, zapis,
   czytanie podczas gotowania. Dopiero obserwacje uzasadniają nowe funkcje.

Każdy pakiet: odtworzenie → regresja z rzeczywistym negatywem → ogląd →
review → hook → CI → merge → Railway → produkcja. Audyt nie dodaje
fikcyjnych danych produkcyjnych, nowego stosu ani funkcji V2.

## Późniejsze odtworzenie #567

Lokalny Laravel/PHP, osobna baza kuking_test_audyt567 na55439: zapis
publicznego przepisu pozostał w collection_items po zmianie na prywatny,
tytuł prawidłowo zniknął, lecz ekran mówił „W tym zeszycie nic jeszcze
nie ma”. Pierwszy test wykrył błąd, drugi potwierdził prawdziwie pusty
zeszyt: 2 testy, 11 asercji, 1 oczekiwana porażka. Dowody w
evidence/audyt567. To odtworzenie odpowiedzi HTTP i stanu DB, bez oglądu
przeglądarkowego. #568 i #569 nadal wymagają odtworzenia.

## Dalsze dowody i research

#568 odtworzono na201 pasujących przepisach i201 osobach: HTTP200,
200 pokazanych wyników i kolejny link identyczny z bieżącym adresem.
1 test/10 asercji potwierdził obecny błąd, nie naprawę. Wynik zapisany
w komentarzu issue568.

#569: [WAI-ARIA timer](https://www.w3.org/TR/wai-aria-1.2/#timer)
ma domyślnie aria-live=off. Zalecenie: udostępnić istniejący licznik
w drzewie dostępności, zachować osobne ogłoszenia startu/końca.
Zachowanie i wymowę musi odebrać prawdziwy czytnik; AX nie dowodzi ciszy.
Przycisk odczytu jest opcją do zbadania, nie wymogiem standardu.

#571: wcześniejsza hipoteza dryfu otrzymała ograniczone odtworzenie.
Lokalny60s minutnik:0:59 przed pauzą JS4002,55ms;0:57 po niej;0:56
po kolejnych1100ms. Około2s opóźnienia. To kontrolowana pauza CDP,
nie badanie telefonu ani karty w tle. Osobne issue i kryteria regresji.
