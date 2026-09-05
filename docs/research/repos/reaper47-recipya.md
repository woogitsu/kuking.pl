# reaper47/recipya — notatka researchowa

**Licencja: GPL-3.0** (`LICENSE`). Nie AGPL, ale skutek dla nas jest ten sam:
GPL to copyleft, więc pochodna musiałaby być GPL. Kodu nie bierzemy.
Praktycznie jest to mniej dolegliwe niż AGPL (GPL nie obejmuje samego
udostępniania przez sieć), ale my nie planujemy dystrybuować binariów, więc
różnica jest teoretyczna. **Zero kodu, wnioski projektowe i UX — tak.**

Recipya był w planie jako **kontrapunkt dla rozbudowanego Tandoora**: Go +
SQLite + `templ` + HTMX, jeden plik binarny, przepis dla rodziny. I ta rola
się potwierdziła — wartość tej notatki to nie funkcje, a dwa konkretne
znaleziska w naszym kodzie i jedno potwierdzenie decyzji **D-007**.

Snapshot: `git clone --depth 1` z 2026-09-05.

---

## 1. Co wynika z licencji

- GPL-3.0: kopiowanie kodu do zamkniętego produktu wykluczone.
- Ich migracje SQL są krótkie i czytelne — i to jest tekst chroniony.
  Przenosimy **regułę** („e-mail musi być zapisany małymi literami”),
  nie zapis SQL.

## 2. Użyteczny model danych

To najprostszy schemat z całego researchu — kilkanaście tabel na całą
aplikację. Trzy rzeczy warte uwagi:

### 2.1 CHECK wymuszający małe litery w adresie e-mail

`internal/services/migrations/20221227180503_init.sql`:

```sql
email TEXT NOT NULL UNIQUE CHECK ( LOWER(email) = email )
```

Jedna linijka, która zamyka całą klasę błędów: nie da się mieć dwóch kont
różniących się tylko wielkością litery, a logowanie nie zależy od tego, jak
klawiatura podpowiedziała adres.

**To jest realna dziura u nas i jest poważna.** Nasze `users.email` ma
`unique()`, ale bez normalizacji
(`database/migrations/0001_01_01_000001_create_users_table.php:31`),
`RegisterController` zapisuje adres tak, jak przyszedł
(`app/Http/Controllers/Auth/RegisterController.php:81`), a
`LoginController::findUser()` szuka przez
`User::where('email', $login)` (`app/Http/Controllers/Auth/LoginController.php:91`).
W PostgreSQL porównanie tekstu jest **wrażliwe na wielkość znaków** (inaczej
niż w MySQL z domyślnym collation), więc:

- klawiatura telefonu **domyślnie zapisuje wielką literę na początku pola** —
  osoba rejestruje się jako `Jan@example.com`;
- następnym razem wpisuje `jan@example.com` (albo autouzupełnienie podaje
  małymi literami) i **nie może się zalogować**;
- komunikat brzmi „Nie udało się zalogować. Sprawdź, czy nazwa i hasło są
  wpisane poprawnie” — czyli sugeruje złe hasło;
- osoba klika „Nie pamiętam hasła”, gdzie wyszukanie prawdopodobnie ma ten sam
  problem, więc mail nie przychodzi;
- konto jest nie do odzyskania, a użytkownik jest przekonany, że to jego wina.

Dla grupy 50+ to jest scenariusz, po którym się nie wraca. To samo dotyczy
nazwy użytkownika: `Profile::where('username', $login)`
(`LoginController.php:94`), a CHECK na `profiles.username` dopuszcza
`[a-zA-Z0-9_]`, więc `JanKowalski` i `jankowalski` to dwie różne nazwy —
i dwa różne konta, których nie da się odróżnić na wizytówce.
→ **propozycja issue nr 1.**

### 2.2 Kolekcja z jawną kolejnością i unikalnością pozycji

`internal/services/migrations/20231008170402_cookbooks.sql`:

```sql
cookbook_recipes: cookbook_id FK, recipe_id FK, order_index NOT NULL,
                  UNIQUE (cookbook_id, recipe_id)
cookbooks: ..., count INTEGER DEFAULT 0, UNIQUE (title, user_id)
```

Trzy rzeczy, których nam brakuje w `collection_items`
(`database/migrations/2026_09_05_000800_create_collections_tables.php:37-45`):

1. **`UNIQUE (collection_id, recipe_id)`.** Dziś tabela ma tylko indeks na
   `recipe_id`. Aplikacja broni się `syncWithoutDetaching()`
   (`app/Domain/Collections/Actions/SaveRecipeToCollection.php:29`), co działa
   w normalnym przebiegu, ale nie chroni przed dwoma równoległymi żądaniami
   — czyli przed podwójnym kliknięciem „Zapisuję” na wolnym łączu, które
   u naszej grupy jest regułą, nie wyjątkiem. `AGENTS.md` sekcja 6 mówi wprost:
   „prawdziwe klucze obce i prawdziwe CHECK-i w bazie — walidacja w PHP jest
   dodatkiem, nie zamiennikiem”. → **propozycja issue nr 2.**
2. **`UNIQUE (owner_id, name)`** — dziś da się mieć dwa zeszyty o tej samej
   nazwie, a wtedy okno „do którego zeszytu zapisać” pokazuje dwie
   identyczne pozycje.
3. **Kolejność pozycji.** Nasz zeszyt jest sortowany chronologicznie
   (`collection_items.created_at`). To jest w porządku na MVP, ale przy
   „Zeszycie” jako centralnym miejscu produktu ludzie będą chcieli układać
   po swojemu (najpierw obiady, potem ciasta) — a dodanie kolumny kolejności
   później oznacza wypełnianie jej wstecznie.

### 2.3 „Remember me” jako selektor + zahashowany walidator

`internal/auth/remember_me.go` + tabela `auth_tokens (selector CHAR(12),
hash_validator CHAR(64), expires, user_id)`.
Wzorzec: w bazie szukamy po **selektorze** (jawnym), a sekret porównujemy
z jego skrótem SHA-256. Wyciek kopii bazy nie daje możliwości zalogowania się
na cudze konto, a wyszukiwanie nie odbywa się po sekrecie.

**Dla nas wniosek jest odwrotny do oczywistego.** Nie przenosimy tego wzorca —
przenosimy obserwację, że **implementowanie tego samemu jest łatwe do
zepsucia**. Ich funkcja `DecodeHashValidator` dekoduje base64 z surowego skrótu
SHA-256, co wygląda na pomyłkę w warstwie kodowania `[do weryfikacji — nie
prześledziłem całej ścieżki porównania]`. Laravel daje `remember_token`,
`Auth::attempt(..., remember: true)` i rotację sesji z pudełka, a nasz
`LoginController` już z tego korzysta (`LoginController.php:57`).
To jest argument za `AGENTS.md` sekcja 3 („kolejna biblioteka, gdy Laravel ma
to w standardzie” — tu: kolejna **własna implementacja**).

### 2.4 Udostępnianie przepisu linkiem

`share_recipes (link, user_id, recipe_id, UNIQUE (link, recipe_id))` oraz
`share_cookbooks` — to samo dla całego zeszytu.
Trzecie repozytorium z tą funkcją (po `recipe_share_tokens` w Mealie), tylko
**bez terminu ważności**. Zbieżność potwierdza, że to realna potrzeba, a brak
`expires_at` u nich pokazuje, czego nie kopiować: link bez wygaśnięcia zostaje
w cudzej skrzynce na zawsze.
Warte przeniesienia: **udostępnianie całego zeszytu**, nie tylko pojedynczego
przepisu („wysyłam córce zeszyt po babci”). To jest bardzo w duchu Kuking.

## 3. Przepływy UX warte adaptacji

1. **Zeszyt („cookbook”) jako pierwszorzędny obiekt z okładką i licznikiem.**
   `cookbooks.image` + `cookbooks.count`. Zeszyt wygląda jak książka na półce,
   a nie jak lista zakładek. Nasz `collections` ma `name`, `description`,
   `visibility`, `is_default` — bez okładki. Dla produktu, w którym „Zeszyt”
   jest jedną z pięciu pozycji nawigacji (`AGENTS.md` sekcja 5), okładka
   (choćby zdjęcie pierwszego przepisu) jest tanim sposobem, żeby ekran nie
   wyglądał jak tabela.
2. **Minimalizm ekranu przepisu**: nazwa, opis, zdjęcie, `yield`, składniki,
   kroki. Bez ocen, bez wartości odżywczych na wierzchu, bez tagów.
   To jest dobry punkt odniesienia dla naszego ekranu przepisu —
   Tandoor i Mealie pokazują, co się dzieje, gdy każda możliwa funkcja
   dostanie swoje miejsce w widoku.
3. **Wyszukiwanie jako domyślna strona startowa** (`default_page = SEARCH`
   w Tandoorze, u Recipyi wyszukiwarka jest głównym wejściem do biblioteki).
   Dla nas **nie**: `AGENTS.md` sekcja 8 przesądza, że startem jest feed
   obserwowanych, a przy pustym feedzie „Świeżo z Kuking”. Recipya to menedżer
   biblioteki, Kuking to społeczność — i tu ta różnica jest najlepiej widoczna.

## 4. Przypadki brzegowe bezpieczeństwa i moderacji

Aplikacja rodzinna, więc moderacji nie ma. Cenne są dwie rzeczy:

1. **`CHECK (LOWER(email) = email)` jako obrona w bazie, nie w kontrolerze**
   (sekcja 2.1). To jest dokładnie ta filozofia, którą mamy zapisaną
   w `AGENTS.md` sekcja 6 — i tym razem to my jesteśmy tą stroną, która
   trzyma regułę tylko w aplikacji (a właściwie nie trzyma jej wcale).
2. **HTMX bez `action` w formularzu = brak rejestracji przy wyłączonym
   JavaScripcie.** `web/components/auth.templ:124`:
   ```
   <form hx-boost="true" hx-post="/auth/register" hx-target="body">
   ```
   Formularz nie ma `action` ani `method`, więc bez skryptu przycisk nie robi
   nic. To jest **konkretny dowód pod decyzję D-007**: „server-side rendering”
   i „lekki frontend” nie oznaczają automatycznie, że aplikacja działa bez
   JavaScriptu. Nasze formularze Livewire mają dokładnie ten sam potencjalny
   problem: `wire:submit` bez `action` na `<form>` daje martwy przycisk.
   → **rekomendacja R5**: test sprawdzający, że formularze rejestracji,
   logowania, publikacji wpisu, przepisu, komentarza i „Ugotowałem” mają
   `action` i `method` w wyrenderowanym HTML-u. Taki test kosztuje kilka
   asercji i pilnuje decyzji, której inaczej nikt nie sprawdzi, dopóki komuś
   nie urwie się skrypt.

## 5. Wzorce testowe i jakościowe

- **Trigger utrzymujący indeks pełnotekstowy w spójności**
  (`20240220132005_recipes_fts.sql`): `AFTER DELETE ON recipes` usuwa wiersz
  z `recipes_fts`. Bez tego wyszukiwarka zwraca przepisy, które już nie
  istnieją — z punktu widzenia użytkownika „serwis pokazuje przepis, którego
  nie da się otworzyć”. U nas problem nie występuje, bo szukamy bezpośrednio
  po tabelach z warunkiem widoczności (`Recipe::publiclyVisible()`), zamiast
  utrzymywać osobny indeks. **To jest niedoceniona zaleta D-004**: brak
  osobnego indeksu to brak drugiego źródła prawdy, które może się rozjechać.
  Gdybyśmy kiedyś wracali do Scouta/Meilisearch, ten koszt trzeba doliczyć.
- **Migracje z jawną sekcją `-- +goose Down`** przy każdej zmianie, także
  przy triggerach. Odpowiada naszemu wymogowi z `AGENTS.md` sekcja 6
  („opis rollbacku albo wyjaśnienie, dlaczego nie jest bezpieczny”).
- **Tabela `reports` u nich to raporty techniczne importu**
  (`report_types`, `report_logs`, `exec_time_ns`, `success`, `error_reason`),
  a nie zgłoszenia treści. Pomysł wart zapamiętania na V2: **import z URL
  zapisuje log z czasem wykonania i powodem porażki**, więc da się
  odpowiedzieć na pytanie „dlaczego import z tej strony nie działa”,
  nie zgadując.

## 6. Wzorce wydajnościowe

- Jeden proces, SQLite, FTS5 — nic do przeniesienia przy PostgreSQL, ale
  wart odnotowania kontrast: cały ten projekt działa na jednym pliku bazy
  i to wystarcza rodzinie. To zdrowe przypomnienie przy każdej dyskusji
  „czy nie potrzebujemy Redisa” (`AGENTS.md` sekcja 3, **D-001**).
- **`cookbooks.count` jako licznik w kolumnie** — trzecie repozytorium
  z tym wzorcem (po Fresns i Pixelfedzie). Dla nas nadal „po pomiarze”,
  ale zbieżność trzech niezależnych projektów jest sygnałem, że przy
  listach z licznikami `COUNT(*)` przestaje wystarczać szybciej, niż się
  zakłada.

## 7. Czego świadomie nie przenosić

| Rzecz | Dlaczego nie |
|---|---|
| Własna implementacja „remember me” | Laravel to ma; ich wersja wygląda na obarczoną błędem w warstwie kodowania (2.3) |
| HTMX zamiast Livewire | Mamy wybrany stack (`AGENTS.md` sekcja 3); ich formularze pokazują, że sam HTMX nie daje działania bez JS |
| SQLite + FTS5 | **D-002** i **D-004**: PostgreSQL z `pg_trgm` i `unaccent`, jeden schemat w testach i produkcji |
| Wyszukiwarka jako strona startowa | Kuking startuje feedem (`AGENTS.md` sekcja 8) |
| Link udostępniania bez terminu ważności | Zostaje w cudzej skrzynce na zawsze; Mealie robi to lepiej (`expires_at`) |
| `image` generowane jako losowy UUID przez wyrażenie DEFAULT w SQL | U nas klucz obiektu nadaje pipeline (`media.object_key`), z checksumą i statusem (**D-003**) |
| Kategorie i kuchnie jako słowniki wypełnione z zewnętrznego serwisu | Import cudzych taksonomii bez sprawdzenia licencji danych; u nas tagi to V1 |

## 8. Rekomendacje dla Kuking

| # | Rekomendacja | Nasz plik / tabela | Waga |
|---|---|---|---|
| R1 | **Normalizacja e-maila do małych liter przy zapisie + CHECK `lower(email) = email` w bazie + jednorazowa migracja danych**; wyszukiwanie przy logowaniu i resecie hasła po znormalizowanej wartości | `database/migrations/0001_01_01_000001_create_users_table.php:31`, `app/Http/Controllers/Auth/RegisterController.php:81`, `app/Http/Controllers/Auth/LoginController.php:91`, `app/Http/Controllers/Auth/PasswordResetController.php` | **P0 — dziś konto założone z telefonu może być nie do zalogowania** |
| R2 | **Wyszukiwanie nazwy użytkownika bez rozróżniania wielkości znaków** przy logowaniu i przy sprawdzaniu unikalności (albo CHECK wymuszający małe litery w `profiles.username`) | `app/Http/Controllers/Auth/LoginController.php:94`, CHECK w `database/migrations/2026_09_05_000200_create_profiles_table.php:41` | **P0 — razem z R1** |
| R3 | `UNIQUE (collection_id, recipe_id)` na `collection_items` | `database/migrations/2026_09_05_000800_create_collections_tables.php:37-45`, `app/Domain/Collections/Actions/SaveRecipeToCollection.php:29` | P1 — `AGENTS.md` sekcja 6: constraint w bazie, nie tylko w PHP |
| R4 | `UNIQUE (owner_id, name)` na `collections` | ta sama migracja | P2 |
| R5 | Test: formularze rejestracji, logowania, publikacji wpisu i przepisu, komentarza oraz „Ugotowałem” mają w wyrenderowanym HTML-u `action` i `method` | `tests/Feature/`, `resources/views/`, pilnuje **D-007** | P1 — inaczej nikt nie sprawdzi tej decyzji, dopóki komuś nie urwie się skrypt |
| R6 | Okładka zeszytu (`collections.cover_media_id` albo zdjęcie pierwszego przepisu w widoku) | `collections`, `resources/views/pages/zeszyt/` | P2 |
| R7 | Kolejność pozycji w zeszycie (`collection_items.position`) — dodać, póki tabela jest pusta | `database/migrations/2026_09_05_000800_create_collections_tables.php` | P2 |
| R8 | Udostępnianie **całego zeszytu** linkiem z terminem ważności (rozszerzenie R6 z notatki o Mealie) | przyszłe `share_links`, `collections` | LATER (V1) |
| R9 | Log importu z URL: czas wykonania, sukces, powód porażki | V2, `docs/ROADMAP.md` | LATER |

### Propozycje issues (nie naprawiam, zgodnie z zakresem)

**Issue 1 — „Konto założone z telefonu może być nie do zalogowania”.**
`users.email` nie jest normalizowany ani przy rejestracji
(`RegisterController.php:81`), ani przy szukaniu konta
(`LoginController.php:91`), a PostgreSQL porównuje tekst z uwzględnieniem
wielkości znaków. Klawiatury mobilne domyślnie zapisują wielką pierwszą
literę, więc `Jan@example.com` przy późniejszym wpisaniu `jan@example.com`
daje „Nie udało się zalogować” i — jeśli reset hasła szuka tak samo — konto
bez drogi odzyskania. To samo dotyczy nazwy użytkownika
(`LoginController.php:94`).
Kryteria akceptacji: (1) rejestracja zapisuje adres małymi literami;
(2) CHECK `lower(email) = email` w bazie plus migracja istniejących danych
z obsługą kolizji; (3) test: rejestracja `Jan@Example.com`, logowanie
`jan@example.com`; (4) test: logowanie nazwą `JanKowalski` na konto
`jankowalski`; (5) plan rollbacku (`AGENTS.md` sekcja 6).

**Issue 2 — „Ten sam przepis może wejść dwa razy do jednego zeszytu”.**
`collection_items` nie ma `UNIQUE (collection_id, recipe_id)`; jedyną obroną
jest `syncWithoutDetaching()` w `SaveRecipeToCollection`, które nie chroni
przed dwoma równoległymi żądaniami (podwójne kliknięcie na wolnym łączu).
Przy okazji do rozważenia: powtarzane „zapisz / usuń / zapisz” generuje za
każdym razem powiadomienie do autora (`SaveRecipeToCollection.php:33-42`),
co daje kanał do zasypania kogoś powiadomieniami — do rozstrzygnięcia razem
z limitami z notatki o Discourse.
Kryteria akceptacji: (1) `UNIQUE (collection_id, recipe_id)` w migracji;
(2) test na podwójny zapis; (3) plan rollbacku.
