# Testing

## Bramka testów risky (#1056)

`phpunit.xml` ustawia `failOnRisky="true"`: test bez asercji nie może
dać zielonego kodu wyjścia. Nie zmienia to polityki pominięć ani deprecjacji.
`RiskyTestFailsGateTest` uruchamia przez `php artisan test` dwie osobne
fixture z `tests/Fixtures/PhpunitRisky/`: brak asercji musi dać kod 1
z właściwą diagnozą, a rzeczywista asercja kod 0. Fixture nie należą do
domyślnych zestawów i nie korzystają z bazy. Proces ma limit 60 sekund.
Strażnik czyta wspólną konfigurację, nie wymusza flagi w argumentach.
Wycofanie atrybutu przywraca lukę: risky może kończyć się kodem 0,
a `scripts/check.sh` uzna wtedy etap za udany i usunie jego log.

## Unit
- visibility;
- permissions;
- recipe states;
- moderation transitions.

## Feature
- register;
- profile;
- follow;
- block;
- publish post;
- publish recipe;
- Ugotowałem;
- comment;
- save;
- report;
- export;
- delete account.

## Macierz widoczności

`tests/Feature/Visibility/` — issue #41.

**Po co osobna konstrukcja, a nie zwykłe testy.** Wyciek prywatnej treści
**nie wywala testu**. Widok cicho pokazuje za dużo, odpowiedź ma status 200,
a asercja „czy strona się otwiera" przechodzi. Trzeba testować NIEOBECNOŚĆ
treści — a tego nikt nie napisze z własnej woli dla każdej kombinacji, bo
kombinacji jest piętnaście na typ treści.

Dlatego macierz jest generowana z tabeli prawdy w `WidocznoscTestCase`,
a nie przepisywana ręcznie. Nowy model z widocznością = klasa potomna
z trzema metodami (`widocznosci()`, `utworz()`, `adres()`).

### Kanoniczna tabela prawdy

| widz | public | followers | private |
|---|---|---|---|
| autor | ✅ | ✅ | ✅ |
| obserwujący | ✅ | ✅ | ❌ |
| obcy | ✅ | ❌ | ❌ |
| zablokowany | ❌ | ❌ | ❌ |
| niezalogowany | ✅ | ❌ | ❌ |

Klasa potomna może tę tabelę nadpisać, gdy typ treści ma inne reguły — tak
robi zeszyt, którego trasa żyje w grupie `auth`, więc gość nie zobaczy nawet
zeszytu publicznego.

### Trzy drogi wycieku — testowane OSOBNO

Treść może wyciec przez każdą z nich niezależnie i **naprawienie jednej nie
naprawia pozostałych**:

1. **Widok** — wejście na adres treści. Pilnuje Policy.
2. **Lista** — profil, feed, tablica. Pilnuje zapytanie w kontrolerze.
   Policy tu **nie działa**.
3. **Wyszukiwarka** — osobne zapytanie z własnymi filtrami. Ani Policy, ani
   filtry listy tu **nie działają**.

To nie jest teoria. Przy budowie tej macierzy każda z trzech dróg miała
realny wyciek, mimo że Policy była poprawna:

- zakładka „Przepisy" na profilu pokazywała przepisy `private` i `followers`
  każdemu (droga 2),
- wyszukiwarka w ogóle nie znała blokad, w obie strony (droga 3),
- komentarz osoby zablokowanej wyświetlał się pod cudzym wpisem.

### Uwaga przy pisaniu takich testów

`actingAs()` utrzymuje zalogowanie na **kolejne** żądania w tym samym teście.
Przypadek „niezalogowany" wymaga jawnego `Auth::logout()`, inaczej dziedziczy
użytkownika z poprzedniej iteracji i cicho sprawdza coś innego — czyli
dokładnie tę fałszywą zieleń, przed którą ta macierz ma chronić.

## Accessibility
Każdy krytyczny ekran:
- keyboard;
- visible focus;
- 200% zoom;
- larger text;
- 320 px;
- labels;
- error association;
- screen reader smoke.

## Media
Testy:
- fałszywe rozszerzenie;
- corrupt image;
- przekroczony bytes limit;
- ogromne dimensions;
- EXIF GPS;
- unsupported format.

## Security
- IDOR;
- blocked user;
- private visibility;
- CSRF;
- XSS;
- rate limits;
- mass assignment.

## UX usability
Najważniejsze nie są automaty:
przed public beta testować realne flow z osobami 50–70+.
