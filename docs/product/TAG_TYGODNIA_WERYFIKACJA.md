# Weryfikacja danych do #18 — 20 września 2026

Gałąź: `gpt/tag-tygodnia`. Baza pracy:
`4c811cc7bff365fb8f86d87eabac93b7738a45cd`, początkowo czyste drzewo.
Wszystkie wyniki poniżej są pomiarami własnymi, lokalnymi.

## Środowisko

Worktree Windows: `C:\Users\matma\Documents\kuking-flota\gpt-tag-tygodnia`.
Runtime WSL: `/home/mateusz/flota/gpt-tag-tygodnia-run`.
PostgreSQL: `127.0.0.1:55439`, baza `kuking_flota_gpt-tag-tygodnia`,
rola `kuking`. Użyto skryptów floty `przygotuj-runtime.sh` i `testuj.sh`
z obowiązkowym `MSYS_NO_PATHCONV=1` przy wywołaniu przez Git Bash.
Nie używano produkcji, portu 5432 ani cudzej bazy.

## Wyniki

| Kontrola | Wynik |
|---|---|
| Nietknięte drzewo, `--filter Tag` | 258 testów, 49 992 asercje, 17,55 s; zaliczone |
| `--filter KalendarzKuchniDaneTest` | 2 testy, 378 asercji, 2,61 s; zaliczone |
| `vendor/bin/pint tests/Feature/KalendarzKuchniDaneTest.php` | PASS, 1 plik; brak zmian formatowania |
| Odczyt JSON niezależnie przez PowerShell | 12 miesięcy, 12 okazji, 50 propozycji; najdłuższa notatka 82 znaki |
| `git diff --check` | zaliczone |
| Pełny zestaw z wyłączeniem `ProbaOdtworzeniaTest` | 4394 zaliczone, 1 porażka, 84 070 asercji, 490,58 s — przyczyna i ponowienie poniżej |
| `DokumentyMdNieMajaMartwychOdnosnikowTest` po odświeżeniu runtime | 3 zaliczone, 49 asercji, 1,14 s |

Jedyna porażka pełnego przebiegu była **w tym pakiecie**, nie zastana:
`DokumentyMdNieMajaMartwychOdnosnikowTest` wykrył brak pliku
`docs/product/TAG_TYGODNIA_WERYFIKACJA.md`, do którego prowadziła już
instrukcja. Raport został utworzony po ostatniej synchronizacji runtime.
Po zakończeniu pełnego przebiegu ponownie wykonano `przygotuj-runtime.sh`
i cały test odnośników przeszedł. Nie uruchamiano ponownie wszystkich
4395 testów: kod aplikacji i test danych były bez zmian; poprawka polegała
na dostarczeniu brakującego dokumentu. Wynik pełnego przebiegu pozostaje
zapisany jako 4394 + 1, a zielone ponowienie jest odrębnym pomiarem.

Test danych używa prawdziwego `TagSeeder` na PostgreSQL. Kontrola dodatnia
wymaga obecności znanego tagu w bazie, wszystkich miesięcy i niepustych
propozycji. Nie może zaliczyć braku pliku ani pustego skanu. Nie wprowadza
asercji dotyczących wyboru tygodnia, progu zawartości ani publikacji.

## Kontrole ujemne

Wykonane w odizolowanym runtime istniejącym przyrządem
`scripts/kontrola-ujemna.sh`, bez zmian pliku roboczego Windows.
Każda: zielony test → jedna potwierdzona podmiana → czerwony test
z oczekiwanej przyczyny → przywrócenie → zielony test.

| Podmiana | Oczekiwana i zaobserwowana przyczyna czerwieni |
|---|---|
| `"tag": "krupnik"` → `"tag": "nieistniejacy-tag-18"` | `Nieznany tag redakcyjny: nieistniejacy-tag-18` |
| `"miesiac": 1,` → `"miesiac": 2,` | `Kalendarz musi zawierać każdy miesiąc dokładnie raz.` |

MD5 oryginału: `06f734a89e9cb838c16a208cf2784f34`.
Po pierwszej podmianie: `5ef61a13cb690f3b02c8b9d4557e7a5b`.
Po drugiej: `48488d97eb338b848f6e34ae153add3e`.
Przyrząd potwierdził w wyjściu konsoli zgodność MD5 **i mtime** po
przywróceniu. Jego JSON powstaje przed końcowym `trap` i nadal zawiera
`przywrocenie: nie wykonane`; tego pola nie przedstawiam jako dowodu
przywrócenia. Dodatkowo porównano plik runtime z plikiem worktree.

Powtórzenie pierwszej kontroli (w przygotowanym runtime, ze zmiennymi bazy
jak w `testuj.sh`):

```bash
bash scripts/kontrola-ujemna.sh \
  --plik docs/product/dane/kalendarz-polskiej-kuchni.json \
  --zamien '"tag": "krupnik"' --na '"tag": "nieistniejacy-tag-18"' \
  --oczekuj 'Nieznany tag redakcyjny: nieistniejacy-tag-18' \
  -- vendor/bin/phpunit tests/Feature/KalendarzKuchniDaneTest.php
```

## Granice odbioru

To pakiet redakcyjny, nie ukończenie wszystkich kryteriów #18. Decyzje
i zakres pozostały w [instrukcji](TAG_TYGODNIA.md). Brak nowego UI oznacza,
że nie wykonano nowego audytu przeglądarkowego 320 px / 200%; testy HTTP
nie są takim audytem. Nie wysyłano wiadomości, nie otwierano PR, nie
pushowano, nie zmieniano produkcji ani repozytorium kanonicznego.

W pełnym przebiegu świadomie wyłączono `ProbaOdtworzeniaTest`, zgodnie
z wyjątkiem podanym w zleceniu: ten test używa wspólnej bazy
`kuking_zrodlo_proby_glowny`. Nie uruchamiano osobnej grupy `dwa-polaczenia`
wyłączonej domyślnie w `phpunit.xml`. Nie wykonywano `scripts/check.sh`
(brak PR); formatowanie i testy wykonano osobno.
