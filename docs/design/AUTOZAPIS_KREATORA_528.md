# Autozapis kreatora przed zapisem danych — #528

## Problem i zakres

Baza pracy: `fc7473ee936323b97f00842e424a8c3923712a48`, gałąź `fix/528-autozapis-walidacja`. Rzeczywiste `Livewire::actingAs($user)->test('recipe-wizard')->set('title', str_repeat('a', 181))` uruchamiało zapis przed walidacją i kończyło się PostgreSQL SQLSTATE 22001 dla varchar(180). Pozostałe pola również omijały istniejącą walidację publikacji; czyszczenie wierszy mogło skrócić nadmiernie długi tekst.

Autozapis sprawdza teraz surowe wartości istniejącymi regułami opisu i wierszy przed `persist()`. Błędne wartości pozostają w formularzu, poprzedni poprawny szkic i jego wiersze pozostają bez zmian, a komunikat jasno mówi, że zmiany nie zostały zapisane. Po poprawieniu wartości następuje zwykły zapis. Nadal można budować niepełny szkic: krótki tytuł zachowuje dotychczasowy stan oczekiwania, a puste wiersze nie wymagają treści.

Walidacja automatyczna nie przenosi użytkownika między krokami. Przycisk „Dalej” po odrzuceniu zapisu wskazuje ekran błędnych pól. Pozwala to wrócić do błędnego składnika lub instrukcji po wcześniejszym cofnięciu, bez utraty tekstu i bez zapisu błędnych danych. Błędy poprawionych pól są usuwane bez kasowania niezwiązanych błędów zdjęć. Publikacja zachowuje dotychczasową nawigację walidacji wierszy.

## Granice i interfejs

Używane są istniejące reguły: tytuł 180, opis 2000, osoba źródłowa 120, notatka źródłowa 2000, poprawny URL do 2000 znaków, porcje 0,5–999, czasy 0–10080 minut, rok 1850–2100 oraz dotychczasowe wyliczenia trudności, widoczności i źródła. Wiersze zachowują limity: składnik 240, grupa 120, notatka 300, instrukcja 4000 oraz dotychczasową walidację minutnika.

Nie zmieniono limitów produktu ani schematu bazy. Nie dodano `maxlength`: współdzielony komponent pola go obecnie nie przekazuje, a ograniczenie przeglądarki nie zabezpiecza aktualizacji Livewire i mogłoby uciąć wklejany tekst. Regresja sprawdza pełny tekst w rzeczywistym polu DOM oraz oznaczenie błędu. Nie zmieniono wspólnego komponentu pola, nagłówka podsumowania z #527 ani obsługi przesyłania zdjęć. Dotychczasowy etap obsługi zdjęć poprzedzający walidację pozostaje poza zakresem tej poprawki.

## Weryfikacja wykonana

Izolowana kopia `/tmp/kuking-autosave528-exec`, PostgreSQL `127.0.0.1:55439`, wyłącznie baza `kuking_proof528`, UTC, jawne zmienne połączenia i `APP_URL=http://localhost`. PHP 8.4.24. Nie uruchamiano pełnego zestawu PHP.

- Nowa regresja oraz `RecipeWizardTest` i `MinutnikIZdjecieKrokuTest`: **73 testy, 652 asercje**, zielone. Wynik: `output/proof528/green-final.txt`.
- 18 przypadków niepoprawnych wartości: rzeczywiste aktualizacje Livewire, dokładna wartość w stanie formularza, komunikat błędu, porównanie całego wcześniejszego rekordu i wierszy z ich identyfikatorami, ponowne ręczne zapisanie oraz poprawienie wartości.
- Pierwszy zbyt długi tytuł nie tworzy szkicu; po wyczyszczeniu pola nie zostaje stary błąd; poprawne 180 znaków zapisuje się w całości.
- Pint: dwa pliki poprawne (`output/proof528/pint.txt`); `git diff --check` bez błędów.

Sześć rzeczywistych mutacji źródłowego Blade ujawnia brak walidacji opisu, brak walidacji wierszy, nieprawdziwy stan „zapisano”, pozostawiony stary błąd, zmianę kroku podczas autozapisu oraz brak możliwości dotarcia do ukrytego błędnego pola po cofnięciu. Wyłączenie walidacji opisu ponownie wywołuje SQLSTATE 22001. Precyzyjna kontrola instrukcji 4001 znaków kończy się brakiem oczekiwanego błędu walidacji; jej wynik nie zależy od osobnego błędu słownika składników #526. Szeroka kontrola wierszy ujawniła także znany #526 — nie jest to dowód tej poprawki.

Negatywy powtórzono z fizycznymi kopiami źródła poza repo: `/tmp/kuking-negative-backups/proof528-final-negatives.py.source` i `/tmp/kuking-negative-backups/proof528-row-negative.py.source`. Skrypty `*-disk.py` przywracają źródło z tych plików; pliki `*.disk-backup.json` dokumentują zgodność MD5 i mtime kopii oraz przywróconego źródła. Cache Blade czyszczono przed i po mutacjach. Wyniki i reproduktory pozostają w `output/proof528/`. Oczekiwany MD5 poprawnego Blade: `5ab90afe7f0f1c8c9e1a1b15a9d56823`.

## Ograniczenia

Nie zmieniano semantyki zapisu zdjęć, nie dodawano funkcji ani migracji. Aktualizacja zależnego, już wypełnionego pola może pokazać jego istniejący błąd wcześniej niż publikacja; jest to zamierzona ochrona poprzedniego szkicu. Niepoprawny lub jeszcze niedokończony URL nie jest zapisywany do czasu poprawienia. Wersja, changelog, commit i publikacja pozostają do integracji przez zadanie główne.

## Odbiór po integracji

Alfa 0.25 obejmuje tę poprawkę oraz migrację słownika #526. Zachowano nowy nagłówek „Sprawdź formularz” z #527. Zintegrowany zestaw: 72 testy / 891 asercji.

W rzeczywistej lokalnej przeglądarce wpisano 181 znaków w nazwie istniejącego przepisu. Autozapis pokazał błąd, zachował wszystkie znaki, a odczyt przepisu w drugiej karcie potwierdził poprzednią nazwę. Poprawienie pola wznowiło zapis. Sprawdzono 12 konfiguracji: szerokości 320, 360, 390, 414, 768 i 1440 px, oba motywy, tekst aplikacji 140%. Bez poziomego przewijania dokumentu. Obejrzano zrzut 320 px w motywie ciemnym. To zwiększony tekst, nie zoom przeglądarki.

Wpisanie 241 znaków składnika, cofnięcie i „Dalej” wróciło do błędnego pola bez utraty tekstu. Po poprawieniu do 240 znaków zapisano całość; odczyt strony potwierdził nową nazwę przepisu i składnik. Nie badano klawiatury ekranowej. Skrypty i obrazy lokalne: `output/browser528.js`, `output/correct528.js`, `output/navigation528.js`, `output/playwright/autosave528-*.png`.

Po integracji ponownie wykonano sześć negatywów prawdziwego Blade, z kopią poza repo i przywróceniem MD5 `60ec2188d6a8a0cca70b85d566f888c0` oraz mtime; cache Blade czyszczono przed i po zmianach. Sześć negatywów migracji również powtórzono, zachowując MD5 `147a5e4d79f8ccccf08e07089a7642ce`. Wyniki w `output/integrated528/` i `output/integrated526/`; po przywróceniu 72/891 ponownie przeszło.

Dodatkowo zmierzono cztery prawdziwe zoomy 200% (`chrome.tabs.getZoom() = 2`, DPR 2, szerokość CSS 320 px): oba motywy i tekst 100/140%. Całe 181 znaków pozostało w polu, błąd był aktywny, dokument nie przewijał się poziomo. Obejrzano obraz jasnego motywu z tekstem 140% oraz zwykły widok 1440 px. Przy jednoczesnym zoomie i dużym tekście stałe nawigacje zajmują znaczną część wysokości, a etykieta i błąd wymagają przewijania. Nie jest to dowód pełnego odbioru użyteczności tego skrajnego układu ani kompletnego przejścia klawiaturą.
