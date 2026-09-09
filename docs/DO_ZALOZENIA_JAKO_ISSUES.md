# Znaleziska z przeglądu cudzych repozytoriów — WSZYSTKIE ZROBIONE

Ten plik powstał, bo limit API GitHuba wyczerpał się w trakcie pracy i sześć
znalezisk trzeba było gdzieś odłożyć. Napis na górze brzmiał: „Każda pozycja
jest gotowa do przeklejenia jako issue. Po założeniu issue — usuń pozycję
stąd."

**Żadnej z nich nie trzeba już zakładać.** Wszystkie sześć zostało w
międzyczasie zaimplementowanych, a plik został z listą do zrobienia —
czyli mówił następnej osobie, żeby zbudowała to, co już działa (audyt
zewnętrzny, pozycja G15).

Nie kasuję tego dokumentu, bo sam przegląd był trafiony: sześć na sześć
znalezisk okazało się prawdziwymi brakami i wszystkie zostały zamknięte.
To jest wynik warty zapamiętania przy następnym przeglądzie cudzych repo.

Zamiast listy „do zrobienia" stoi tu teraz **wynik weryfikacji**: gdzie
mieszka każde zabezpieczenie i co go pilnuje. Zgodność tej tabeli z kodem
sprawdza `tests/Feature/BacklogNieProponujeIstniejacychZabezpieczenTest`.

---

## Wynik weryfikacji — stan na 9 września 2026

### 1. Zbanowane konto działa do końca sesji · było P0 · ZROBIONE

Sesje są unieważniane w momencie zmiany statusu, nie przy następnym
logowaniu.

- `App\Models\User::invalidateSessions()` — czyści wiersze w tabeli
  `sessions` bezpośrednio, bo unieważnia sesje CUDZE. Wołane przy
  zmianie statusu konta.
- `App\Http\Middleware\EnsureAccountIsActive` — sprawdza status przy
  **każdym** uwierzytelnionym żądaniu, nie tylko przy logowaniu.
  Konto zawieszone dostaje dostęp do odczytu i do ścieżki odwoławczej.
- Pilnuje: `tests/Feature/AccountStatusTest`.

### 2. Kara bez terminu wygaśnięcia · było P0 · ZROBIONE

- Kolumna `users.status_expires_at`, migracja
  `2026_09_05_001400_add_status_expires_at_to_users`.
- `App\Console\Commands\RestoreExpiredSuspensions` — przywraca `active`
  po upływie terminu, z harmonogramu.
- Opis kolumny i retencji: `docs/DATABASE.md`.

### 3. Macierz widoczności: każdy stan × każdy typ obserwatora · było P1 · ZROBIONE

To była najcenniejsza pozycja z całej szóstki i wyrosła w osobny katalog.

- `tests/Feature/Visibility/WidocznoscTestCase` — klasa bazowa
  z **kanoniczną tabelą prawdy** widoczności. Nie może istnieć druga.
- Zastosowana w dziesięciu plikach: wpis, przepis, wykonanie, komentarz,
  zeszyt, profil, galeria, tryb gotowania, „komuś wyszło", szukajka.
- Stąd korzysta też `ZdjeciaChronioneNieWyciekajaTest` — zdjęcie nie ma
  własnej widoczności, ma widoczność treści, do której jest przypięte.

### 4. Lista zastrzeżonych nazw użytkownika · było P1 · ZROBIONE

- Lista: `config/kuking.php`, klucz `account.reserved_usernames`.
- Walidacja: `App\Rules\ReservedUsername`, przy rejestracji i przy zmianie
  nazwy w ustawieniach.
- Komunikat brzmi dokładnie tak, jak proponowała ta pozycja:
  „Ta nazwa jest zarezerwowana. Wybierz inną."
- Pilnuje: `tests/Feature/ZastrzezoneNazwyTest`.

### 5. `recipe_ingredients.no_amount` · było P2 · ZROBIONE

- Migracja `2026_09_06_130000_add_no_amount_to_recipe_ingredients`, plus
  CHECK `recipe_ingredients_no_amount_check` — składnik „bez ilości" nie
  może mieć jednocześnie liczby.
- Obsługa: `App\Models\RecipeIngredient`, `App\Domain\Recipes\Actions\PublishRecipe`.
- Pilnuje: `tests/Feature/SkladnikBezIlosciTest`.

Skalowanie porcji jest dalej w V2 — ale kolumna, która miała „wejść przy
najbliższej migracji na tej tabeli", weszła.

### 6. Brakujące ograniczenia `UNIQUE` · było P1 · ZROBIONE

Wszystkie trzy, choć dwa inaczej, niż zakładała ta pozycja:

| Co miało być | Co jest |
|---|---|
| ten sam przepis dwa razy w zeszycie | `collection_items_recipe_unique` — indeks **częściowy**, `WHERE recipe_id IS NOT NULL`. Zeszyt przyjmuje też wpisy, więc `recipe_id` bywa NULL i zwykły `UNIQUE` by nie wystarczył. Osobno `collection_items_post_unique`. Szczegóły i **ostrzeżenie, żeby nie przywracać tam klucza głównego**: `docs/DATABASE.md`. |
| dwa zeszyty „Na święta" u jednej osoby | `collections_owner_name_lower_unique` — indeks **funkcyjny** na `(owner_id, lower(name))`, bo dla człowieka „Obiady" i „obiady" to ta sama nazwa. |
| `post_media` na samej parze | `post_media_pkey (post_id, media_id)` — było od migracji zakładającej tabelę; ta pozycja opisywała stan nieaktualny już w chwili pisania. |

Pilnuje: `tests/Feature/UnikalnoscZeszytowTest`.

---

## Czego ten plik NIE zawiera

Otwartych zadań. Jeśli szukasz, co robić dalej — `docs/ROADMAP.md` i lista
issues na GitHubie. Jeśli szukasz nierozwiązanych znalezisk z audytu —
`docs/AUDYT_GPT_2026-09.md` i `docs/AUDYT_2026-09.md`.
