# Zaległości #732 i #765 — odbiór lokalny

Stan początkowy: czysty `gpt/zalegle`, `534e0a51ed3a2b7dab4f7ba88ec536f4c411ad24`.
Worktree i runtime: osobne katalogi stanowiska, skopiowane zależności.
Baza: `kuking_flota_gpt-zalegle`, właściciel `kuking`, `127.0.0.1:55439`.
Nazwa runtime odpowiada istniejącemu katalogowi; podany w przekazaniu katalog
`zalegle` nie istnieje. Wspólne skrypty floty pozostały bez zmian.

> **Zakres po zawężeniu PR #1194 (24.09).** Pierwotny pakiet obejmował też
> #748, #749 i #752. Te poprawki wyjęto z tej gałęzi — należą do osobnych
> spraw i wejdą osobnymi PR-ami. Pomiary poniżej dotyczą tylko #732 i #765.

## Co zmierzono samodzielnie

### #732 — sonda bazy

Nowy `tests/skrypty/check-postgres.sh` uruchamia rzeczywisty początek
`scripts/check.sh`, przechwytując sondę i zarządzanie klastrem atrapami.
Przed poprawką brakowało jawnych argumentów, były dwie sondy i próba
uruchomienia klastra. Brak parametrów oraz niedostępność nie zatrzymywały
kroku. To czerwień wykonana przed zmianą kodu, bez kontaktu z bazą.

Po poprawce sonda pyta dokładnie o `-h "$DB_HOST" -p "$DB_PORT" -d
"$DB_DATABASE" -U "$DB_USERNAME"`, nie zarządza klastrem i odmawia przy braku
nazwy bazy lub użytkownika, przy nielokalnym hoście i przy ustawionym
`DB_URL`. Pierwsza wersja poprawki zaszywała port stanowiska (55439)
i odmawiała każdego innego — to oznaczało `exit 1` w świeżym klonie (5432)
i w CI (port losowy). Późniejszy commit bierze port i host ze środowiska
z wartościami zapasowymi `5432` / `127.0.0.1`, tak jak `.env.example`,
`phpunit.xml` i `tests/skrypty/proba-odtworzenia.sh`. Przyrząd sprawdza port
ze zmiennej (6543), 5432, brak `DB_PORT` i brak `DB_HOST`. Komunikat jawnie
oddziela gotowość serwera od uwierzytelnienia i istnienia bazy.

### #765 — wydruk A4

Chromium **153.0.8010.12**, `page.pdf`, A4, margines 12 mm, włączone drukowanie
tła. HTML pochodzi z rzeczywistych odpowiedzi HTTP Laravel na własnej bazie
(`WydrukPrzepisuFixtureTest`); CSS z `npm run build`. Nie jest to ręcznie
zbudowana makieta. Zależności pomiaru: Playwright oraz Poppler (`pdfinfo`,
`pdftotext`, do oglądu również `pdftoppm`). Bez biblioteki PDF w produkcie.

Fixture: 4 porcje, 50 minut, autor, adres zewnętrznego źródła, grupy
Ciasto/Farsz, `note`, `no_amount`, obraz główny i obraz kroku o proporcji
1600×1200. Obrazy są jawnymi grafikami kontrolnymi, nie zdjęciami użytkowników.
Krótki przepis: 6 składników, 3 kroki. Długi: 18 składników, 14 kroków;
krok piąty ma 55 powtórzeń zdania i przechodzi między stronami.

| Przepis | Motyw | Strony przed | Strony po | Widoczne kontrolki przed/po |
|---|---|---:|---:|---:|
| Krótki | jasny | 6 | 2 | 19 / 0 |
| Krótki | ciemny | 6 | 2 | 19 / 0 |
| Długi | jasny | 10 | 4 | 19 / 0 |
| Długi | ciemny | 10 | 4 | 19 / 0 |

Przed zmianą dolna stała nawigacja nakładała się na obraz/treść, drukowane
były formularze i obudowa strony, a ciemny motyw dawał ciemny papier.
Nie twierdzimy, że zaginął tekst kroku przed poprawką: wykazanym błędem
były nakładanie obudowy, kolor i drukowanie niedziałających na papierze akcji.
Pierwsza próba naprawy zostawiała ciemne marginesy; ogląd PNG to wykrył.
Końcowy wariant wymusza jasny schemat również na korzeniu dokumentu.

Po zmianie sprawdzono każdy składnik oraz pełny tekst każdego kroku
wyciągnięty z PDF, autorstwo, źródło, uwagi i „do smaku”. Obejrzano wszystkie
strony. Obrazy PNG wszystkich stron po zmianie są identyczne pikselowo
między motywem jasnym i ciemnym. Zdjęcia zachowano, ograniczając wysokość
na papierze do 40 mm. Reguły dotyczą wydruku istniejącego widoku, bez nowego
endpointu i bez obchodzenia Policy. Nie wykonano fizycznego wydruku ani
pomiarów Firefox/Safari. Przy zdjęciach o innych proporcjach i własnych
ustawieniach drukarki liczba stron może się różnić.

PDF-y porównawcze:

| Wariant | Przed | Po |
|---|---|---|
| Krótki jasny | [PDF](druk/przed/krotki-light.pdf) | [PDF](druk/po/krotki-light.pdf) |
| Krótki ciemny | [PDF](druk/przed/krotki-dark.pdf) | [PDF](druk/po/krotki-dark.pdf) |
| Długi jasny | [PDF](druk/przed/dlugi-light.pdf) | [PDF](druk/po/dlugi-light.pdf) |
| Długi ciemny | [PDF](druk/przed/dlugi-dark.pdf) | [PDF](druk/po/dlugi-dark.pdf) |

## Kontrole ujemne

Każda wykonała sekwencję PASS → FAIL z nazwanym powodem → PASS przez
`scripts/kontrola-ujemna.sh`, z potwierdzeniem zmiany bajtów oraz
przywrócenia MD5 i mtime. [Wyniki JSON](kontrole/):

- #732: usunięcie argumentu portu oraz osobno przywrócenie `pg_ctlcluster`;
- #765: usunięcie importu arkusza druku, rzeczywisty ponowny build i druk.

Ograniczenie przyrządu: pole JSON `przywrocenie` zapisuje się przed trapem
i ma wartość „nie wykonane”, mimo późniejszego potwierdzenia w wyjściu.
Przy #732 pierwsza próba na dysku Windows wykryła utratę ułamka mtime;
czas przywrócono jawnie co do 100 ns i powtórzono kontrolę na izolowanej
kopii w systemie plików WSL.

## Odtworzenie pomiarów

W runtime ustaw jawne parametry bazy (`DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`), następnie:

```bash
bash tests/skrypty/check-postgres.sh
npm run build
PRINT_FIXTURES=1 php artisan test --filter WydrukPrzepisuFixtureTest
node scripts/druk-przepisu.mjs po
```

Skrypt drukowania zapisuje PDF-y oraz `pomiar.json` do
`output/playwright/druk765/po`. `przed` pomija asercje odbioru i służy tylko
do zapisu pomiaru bazowego, nie do zgłaszania gotowości poprawki.
Wymaga Playwright oraz `pdftotext` i `pdfinfo` (Poppler); nie jest podpięty
pod CI.

## Ograniczenia

Nie wykonywano pomiarów produkcji ani wysyłania wiadomości. Nie zmieniono
schematu bazy. Rollback: wycofanie commitów i ponowny build assetów.
Tryb druku bez zdjęć pozostałby osobną decyzją właściciela; tutaj zdjęć
nie usuwano. Ta zmiana nie podbija numeru wersji — opis jest w
`CHANGELOG.md` pod „Nieopublikowane”.
