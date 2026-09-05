> ## Stan na dziś — przeczytaj przed działaniem
>
> Ten research powstał równolegle z pierwszą wersją repozytorium, więc część
> rekomendacji jest **już zrealizowana**, a część **świadomie rozstrzygnięta inaczej**.
> Zanim coś stąd wdrożysz, sprawdź poniższą tabelę — inaczej zdublujesz pracę
> albo cofniesz podjętą decyzję.
>
> | Rekomendacja | Stan w repozytorium |
> |---|---|
> | Livewire | ✅ zainstalowany (v4, komponenty jednoplikowe) |
> | Intervention/image | ✅ używany w `app/Jobs/ProcessUploadedImage.php` |
> | Osobny `post` i `recipe` (lekcja z Pixelfed) | ✅ zrobione: `posts` i `recipes` to osobne encje |
> | `recipe_ingredients` + `ingredients` + `units` + zachowany surowy `ingredient_text` (lekcja z Tandoor) | ✅ zrobione dokładnie tak |
> | Własny `media` zamiast Spatie MediaLibrary | ✅ **decyzja podjęta**: własny model z `pending/processing/ready/rejected`, checksumą i re-enkodowaniem zdejmującym EXIF. Powód: MediaLibrary nie obsługuje cyklu życia z moderacją. Nie odwracać bez nowej analizy |
> | Własny `audit_log` zamiast Spatie Activitylog | 🔶 **do rozstrzygnięcia** — `App\Models\AuditLogEntry` już działa, ale porównanie warto zrobić (osobne issue) |
> | PostgreSQL FTS + `pg_trgm` zamiast Scout | ✅ zrobione, `App\Domain\Search\SearchQuery` |
> | Kolejka `database`, bez Horizona i Redisa | ✅ zgodnie z `docs/ARCHITECTURE.md` |
> | Zgłoś / Blokuj / Ukryj / kolejka moderacji / log decyzji / powód decyzji (minimum z Discourse) | ✅ **całe minimum działa** przed publiczną betą |
> | Filament na `/admin` | ⬜ do rozważenia — dziś panel jest własny i minimalny (osobne issue) |
> | Spatie Permission | ⬜ do rozważenia — dziś `users.role` z `CHECK` (osobne issue) |
> | Laravel Pennant | ⬜ do rozważenia (osobne issue) |
>
> **Research wykonany — wyniki w `docs/INSPIRATION_DECISIONS.md`.**
> Issue #19 domknięte dla faz 1-3 (Laravel.io, Pixelfed, Fresns, Tandoor,
> Mealie, Recipya, Discourse, Filament). Notatki z odwołaniami do plików
> i linii leżą w `docs/research/repos/`. Ten dokument zostaje jako mapa
> repozytoriów; **decyzje ADOPT/ADAPT/REJECT/LATER są w tamtym pliku**,
> razem z sześcioma znalezionymi przy okazji błędami w naszym kodzie.
> Fazy 4-5 (KitchenOwl, Grocy, Open Food Facts, importery) nieprzeanalizowane
> — dotyczą V1/V2.
>
> Sprostowanie licencyjne: pozycja 4 (Tandoor) to AGPL-3.0 **+ Commons Clause**,
> nie samo AGPL — szczegóły przy tej pozycji.
>
> Drobiazg do sprawdzenia przy okazji: pozycja 13 podaje `recipe-scrapers/recipe-scrapers`
> jako TypeScript — powszechnie znany projekt o tej nazwie (`hhursev`) jest w Pythonie.
> `[do weryfikacji przed pracami nad importem]`
>
> **Zasada nadrzędna z `AGENTS.md`, która nie zmienia się przez ten dokument:**
> żadnej z tych bibliotek nie dodajemy „na zapas”. Każda wchodzi dopiero wtedy,
> gdy usuwa konkretny, nazwany problem, i po spike'u zapisanym w issue.

---

# Kuking.pl — publiczne repozytoria do inspiracji

**Research:** 5 września 2026  
**Cel:** repozytoria warte analizy podczas budowy Kuking.pl: social + zdjęcia + przepisy + społeczność + moderacja + później planner/listy zakupów/spiżarnia.

> **Licencje:** AGPL/GPL traktować głównie jako źródło pomysłów, modeli UX, edge-case'ów i ogólnych wzorców. Jeśli Kuking ma pozostać zamkniętym produktem, nie kopiować z nich kodu bez osobnej analizy licencji. MIT/Apache są liberalniejsze, ale również wymagają przestrzegania warunków licencji.

## TOP 7 — najważniejsze

### 1. `pixelfed/pixelfed`
- Repo: https://github.com/pixelfed/pixelfed
- Stack: PHP / Laravel
- Licencja: AGPL-3.0
- Po co: najbliższy Kuking technicznie wzorzec dużego **photo-first social network**.
- Analizować:
  - profile;
  - follow/unfollow;
  - feed;
  - post + kilka zdjęć;
  - storage i processing media;
  - komentarze;
  - notifications;
  - block/mute;
  - visibility/privacy;
  - reports/moderation;
  - paginację i kolejki.
- Adaptacja dla Kuking:
  - `Pixelfed post ≈ Kuking "Co dziś ugotowałeś?"`;
  - prosty `post` powinien być oddzielny od pełnego `recipe`.
- Nie brać do MVP: ActivityPub/federacji i całej złożoności Fediverse.
- **Priorytet: 10/10.**

### 2. `laravelio/laravel.io`
- Repo: https://github.com/laravelio/laravel.io
- Stack: Laravel / PHP
- Licencja: MIT
- Po co: bardzo dobry wzorzec **produkcyjnej aplikacji community w Laravel**.
- Analizować:
  - organizację domen;
  - modele;
  - policies;
  - profile;
  - threads/replies;
  - notifications;
  - testy Feature;
  - routing;
  - walidację;
  - conventions.
- Kuking powinien czerpać stąd przede wszystkim **styl architektury i testowania**, nie funkcje kulinarne.
- **Priorytet: 10/10.**

### 3. `mealie-recipes/mealie`
- Repo: https://github.com/mealie-recipes/mealie
- Stack: Python + Vue
- Licencja: AGPL-3.0
- Po co: jeden z najbogatszych open-source recipe managerów.
- Analizować:
  - recipe editor;
  - ingredients/units;
  - grupy składników;
  - kroki;
  - servings/time;
  - kolekcje;
  - family/household;
  - import z URL;
  - meal planner;
  - shopping list;
  - export/import.
- Dla Kuking:
  - MVP: struktura recipe + editor;
  - V1: family/planner/shopping;
  - V2: import.
- **Priorytet: 10/10 dla UX przepisu.**

### 4. `TandoorRecipes/recipes`
- Repo: https://github.com/TandoorRecipes/recipes
- Licencja: **AGPL-3.0 + „Commons Clause” v1.0** — sprostowane 5 września 2026
  po lekturze `LICENSE.md` w repozytorium (issue #19). Commons Clause odbiera
  prawo do „Sell the Software”, w tym do **płatnego hostingu i usług wsparcia**,
  których wartość pochodzi w istotnej części z funkcjonalności tego
  oprogramowania. Przy planach z `docs/MONETIZATION.md` to różnica istotna:
  ograniczenie jest ostrzejsze niż samo AGPL.
  Szczegóły: `docs/research/repos/TandoorRecipes-recipes.md` §1.
- Po co: bardzo dobry wzorzec **modelu danych kulinarnych**.
- Analizować:
  - foods/ingredients;
  - units;
  - ilości;
  - synonimy;
  - ingredient groups;
  - recipe steps;
  - servings;
  - tagowanie;
  - search;
  - planner;
  - shopping list;
  - importer.
- Kluczowa lekcja:
  - nie trzymać składników tylko w jednym polu tekstowym;
  - mieć `recipes`, `recipe_ingredients`, `ingredients`, `units`, `recipe_steps`;
  - równocześnie zachować surowe `ingredient_text`.
- **Priorytet: 10/10 dla bazy danych.**

### 5. `discourse/discourse`
- Repo: https://github.com/discourse/discourse
- Stack: Ruby/Rails/PostgreSQL
- Licencja: GPL-2.0
- Po co: wzorzec **moderacji i community health**.
- Analizować:
  - flag/report;
  - moderator queue;
  - trust levels;
  - anti-spam;
  - rate limits;
  - block/mute;
  - ograniczenia kont;
  - powody decyzji;
  - moderator notes;
  - action history;
  - digests/notifications.
- Kuking przed public beta powinien mieć minimum:
  - Zgłoś;
  - Blokuj;
  - Ukryj;
  - moderator queue;
  - moderator action log;
  - jasny powód decyzji.
- **Priorytet: 10/10 dla trust & safety.**

### 6. `filamentphp/filament`
- Repo: https://github.com/filamentphp/filament
- Stack: Laravel + Livewire
- Licencja: MIT
- Po co: panel admin/moderation bez budowania wszystkiego od zera.
- Użyć do:
  - `/admin`;
  - users;
  - reports;
  - recipes/posts/comments;
  - moderation queue;
  - bulk actions;
  - filters;
  - dashboards.
- **Rekomendacja: bardzo poważnie rozważyć bezpośrednie użycie.**
- **Priorytet: 10/10.**

### 7. `fresns/fresns`
- Repo: https://github.com/fresns/fresns
- Stack: PHP / Laravel
- Licencja: Apache-2.0
- Po co: ogólny social/community framework.
- Analizować:
  - różne typy contentu;
  - posts/comments;
  - followers;
  - groups;
  - roles/permissions;
  - notifications;
  - modularność/API.
- Szczególnie użyteczne przy przyszłych **Grupach/Fotoforach**.
- **Priorytet: 9/10.**

---

## Community i grupy

### 8. `flarum/framework`
- Repo: https://github.com/flarum/framework
- Licencja: MIT
- Po co: lekka społeczność/forum.
- Analizować:
  - discussions/replies;
  - mentions;
  - subscriptions;
  - unread state;
  - notifications;
  - groups/permissions.
- W Kuking przydatne przy:
  - Grupach;
  - Q&A;
  - tematycznych fotoforach.
- **Priorytet: 7/10 teraz, 9/10 dla V1.**

---

## Funkcje kulinarne V1/V2

### 9. `TomBursch/kitchenowl`
- Repo: https://github.com/TomBursch/kitchenowl
- Stack: Flutter + Flask
- Licencja: AGPL-3.0
- Po co: recipe + **grocery list + household**.
- Analizować:
  - recipe → shopping list;
  - shared family lists;
  - szybkie dodawanie produktu;
  - grupowanie listy;
  - mobile UX.
- **Priorytet: 8/10 dla V1.**

### 10. `grocy/grocy`
- Repo: https://github.com/grocy/grocy
- Licencja: MIT
- Po co: pantry/inventory/household.
- Analizować:
  - zapasy;
  - ilości i jednostki;
  - daty ważności;
  - zużycie;
  - shopping;
  - barcode;
  - minimal stock.
- Inspiracja dla:
  - „Co ugotuję z tego, co mam?”;
  - `Masz wszystko / Brakuje 1 / Brakuje 2`.
- Nie zamieniać Kuking w ERP lodówki.
- **Priorytet: 7/10 dla V2.**

### 11. `reaper47/recipya`
- Repo: https://github.com/reaper47/recipya
- Stack: Go
- Licencja: GPL-3.0
- Po co: prostszy, rodzinny recipe manager.
- Analizować:
  - minimalny UX;
  - organizację biblioteki;
  - import/export;
  - wyszukiwanie.
- Dobry kontrapunkt dla bardzo rozbudowanego Tandoor.
- **Priorytet: 7/10.**

### 12. `openfoodfacts/openfoodfacts-server`
- Repo: https://github.com/openfoodfacts/openfoodfacts-server
- Licencja repo: AGPL-3.0
- Po co:
  - produkty;
  - EAN/barcode;
  - allergens;
  - nutrition;
  - taxonomy;
  - crowdsourcing danych.
- Potencjalne V2:
  - skan kodu → produkt → spiżarnia;
  - nutrition/allergens.
- Warunki API i licencję danych trzeba sprawdzić osobno.
- **Priorytet: 6/10 teraz, 9/10 dla pantry/nutrition.**

---

## Import przepisów

### 13. `recipe-scrapers/recipe-scrapers`
- Repo: https://github.com/recipe-scrapers/recipe-scrapers
- Stack: TypeScript
- Licencja: MIT
- Po co: wydobywanie struktury recipe ze stron.
- Analizować:
  - JSON-LD `Recipe`;
  - title/image/author;
  - time/servings;
  - ingredients/instructions;
  - test fixtures;
  - fallbacki i edge cases.
- V2 flow:
  - URL → podgląd → poprawki użytkownika → zapis.
- **Priorytet: 8/10 dla importu.**

### 14. `hhursev/recipe-scrapers`
- Repo: https://github.com/hhursev/recipe-scrapers
- Po co: starszy, bardzo dojrzały ekosystem parserów recipe.
- Warto głównie przejrzeć edge-case'y i testy.
- Nie zakładać, że możliwość technicznego importu = prawo do publicznej republiki cudzego tekstu/zdjęcia.
- **Priorytet: 8/10 dla researchu importu.**

---

## Biblioteki Laravel do realnego rozważenia

### 15. `livewire/livewire`
- Repo: https://github.com/livewire/livewire
- Licencja: MIT
- Już wybrany stack Kuking.
- Analizować:
  - uploads;
  - validation;
  - loading state;
  - pagination;
  - dynamic/repeatable forms;
  - autosave.
- Idealne do 3-krokowego recipe wizard.
- **Priorytet: 10/10.**

### 16. `spatie/laravel-permission`
- Repo: https://github.com/spatie/laravel-permission
- Licencja: MIT
- Role:
  - user;
  - moderator;
  - senior_moderator;
  - admin.
- Permissions:
  - reports.review;
  - content.hide;
  - content.remove;
  - users.suspend;
  - users.ban;
  - appeals.review.
- **Silny kandydat do użycia.**
- **Priorytet: 10/10.**

### 17. `spatie/laravel-activitylog`
- Repo: https://github.com/spatie/laravel-activitylog
- Licencja: MIT
- Po co:
  - audit działań moderatorów/adminów;
  - historia ważnych zmian.
- Porównać z własnym append-only `audit_log`.
- **Priorytet: 9/10.**

### 18. `spatie/laravel-medialibrary`
- Repo: https://github.com/spatie/laravel-medialibrary
- Licencja: MIT
- Po co:
  - przypinanie media do Eloquent;
  - kolekcje;
  - conversions.
- Ale Kuking potrzebuje:
  - signed upload;
  - R2;
  - pending/processing/ready/rejected;
  - EXIF/GPS strip;
  - moderation;
  - perceptual hash/checksum.
- **Nie instalować automatycznie** — najpierw spike: package vs własny `media` model.
- **Priorytet: 8/10.**

### 19. `Intervention/image`
- Repo: https://github.com/Intervention/image
- Integracja Laravel: https://github.com/Intervention/image-laravel
- Po co:
  - decode/resize/crop/encode;
  - generowanie thumb/feed/large;
  - worker `ProcessUploadedImage`.
- Przed wyborem sprawdzić aktualne formaty/driver w Railway.
- **Priorytet: 8/10.**

### 20. `laravel/pennant`
- Repo: https://github.com/laravel/pennant
- Licencja: MIT
- Po co: feature flags.
- Idealne dla AI-assisted development:
  - `groups`;
  - `recipe_forks`;
  - `new_recipe_editor`;
  - `planner`;
  - `ai_import`;
  - `premium`.
- Rollout tylko dla adminów/closed beta/wybranej części userów.
- **Priorytet: 9/10.**

### 21. `laravel/scout`
- Repo: https://github.com/laravel/scout
- Licencja: MIT
- Po co: abstrakcja search.
- MVP:
  - PostgreSQL FTS + `pg_trgm`.
- Później:
  - Meilisearch/Algolia/engine.
- **Priorytet: 5/10 teraz, 9/10 później.**

### 22. `laravel/horizon`
- Repo: https://github.com/laravel/horizon
- Licencja: MIT
- Po co: kolejki po dodaniu Redis.
- Jobs:
  - image processing;
  - exports;
  - e-mail;
  - import;
  - OCR;
  - moderation;
  - indexing.
- Niepotrzebne w alpha przy `QUEUE_CONNECTION=database`.
- **Priorytet: 4/10 MVP, 9/10 później.**

### 23. `spatie/laravel-backup`
- Repo: https://github.com/spatie/laravel-backup
- Licencja: MIT
- Po co:
  - dodatkowy backup;
  - backup DB/files;
  - alerty;
  - off-site copy.
- Niezależnie od narzędzia należy testować **restore**, nie tylko tworzenie backupu.
- **Priorytet: 8/10 przed public beta.**

---

# Macierz: funkcja Kuking → repo do researchu

| Funkcja | Najlepsze repo |
|---|---|
| Feed zdjęć | Pixelfed |
| Profile | Pixelfed, Laravel.io |
| Follow | Pixelfed, Fresns |
| Block/mute | Pixelfed, Discourse |
| Post zdjęcie + tekst | Pixelfed |
| Upload/media | Pixelfed, Spatie MediaLibrary, Intervention Image |
| Komentarze | Laravel.io, Pixelfed, Discourse |
| Notifications | Laravel.io, Discourse, Pixelfed |
| Recipe | Mealie, Tandoor |
| Składniki/jednostki | Tandoor |
| Recipe editor | Mealie, Tandoor |
| Kolekcje | Mealie, Recipya |
| **Ugotowałem** | własna funkcja Kuking; benchmarkować produktowo Cookpad |
| **Moja wersja** | własny fork model |
| Grupy/fotofora | Fresns, Flarum, Discourse |
| Q&A | Discourse, Laravel.io |
| Moderacja | Discourse + Filament |
| Admin | Filament |
| Role | Spatie Permission |
| Audit | Spatie Activitylog |
| Feature flags | Laravel Pennant |
| Planner | Mealie, Tandoor |
| Shopping list | KitchenOwl, Tandoor |
| Pantry | Grocy |
| Barcode/nutrition | Open Food Facts |
| URL import | Mealie, Tandoor, recipe-scrapers |
| Search | PostgreSQL → Scout później |
| Queues | Laravel → Horizon później |
| Backup | Railway + Spatie Backup |

---

# Co proponuję faktycznie dodać do Kuking

## Od początku / bardzo wcześnie

1. **Livewire** — już wybrane.
2. **Filament** — admin/moderation.
3. **Spatie Laravel Permission** — role i prawa moderatorów.
4. **Laravel Pennant** — bezpieczne wdrażanie nowych funkcji.
5. Rozważyć **Spatie Activitylog** — po krótkim porównaniu z własnym audit log.

## Najpierw spike, potem decyzja

- Spatie MediaLibrary;
- Intervention Image.

## Dopiero później

- Horizon + Redis;
- Scout / Meilisearch;
- URL importer;
- pantry/nutrition;
- OCR/AI.

---

# Repo, których NIE używałbym jako głównego wzorca

GitHub ma dużo tutorialowych projektów typu:

- `lvntayn/laravel-social-network`
- `bahdcoder/Laravel-5.3-and-Vue-js-2.0-social-network`
- `Hardeepcoder/LaraBook-Social-Network-in-laravel-5.4`

Można zobaczyć podstawy `users/posts/comments`, ale wiele pochodzi ze starego Laravel 5.x.

**W 2026 lepiej wzorować architekturę na Laravel.io, Pixelfed i Fresns.**

---

# Zadanie researchowe dla Codexa

```text
Przeczytaj AGENTS.md i całą dokumentację Kuking.

Następnie przeanalizuj publiczne repozytoria:
- pixelfed/pixelfed
- laravelio/laravel.io
- fresns/fresns
- mealie-recipes/mealie
- TandoorRecipes/recipes
- discourse/discourse
- filamentphp/filament

Nie kopiuj kodu z projektów AGPL/GPL do Kuking.

Dla każdego utwórz notes/research/<repo>.md:
1. użyteczny model danych;
2. flow UX warte adaptacji;
3. security/moderation edge cases;
4. testy i quality patterns;
5. performance/scaling patterns;
6. rozwiązania, których nie przenosić;
7. konkretne rekomendacje dla Kuking.

Następnie utwórz docs/INSPIRATION_DECISIONS.md i oznacz każdą decyzję:
ADOPT / ADAPT / REJECT / LATER.

Na tym etapie nie zmieniaj kodu produktu.
```

---

# Zalecana kolejność analizy

## Faza 1 — social core
1. Laravel.io
2. Pixelfed
3. Fresns

## Faza 2 — recipes
1. Tandoor
2. Mealie
3. Recipya

## Faza 3 — public beta / bezpieczeństwo
1. Discourse
2. Filament
3. Spatie Permission
4. Activitylog

## Faza 4 — utility
1. KitchenOwl
2. Grocy
3. Open Food Facts

## Faza 5 — import
1. Mealie importer
2. Tandoor importer
3. recipe-scrapers

---

# Wniosek architektoniczny

Nie szukałbym jednego repo do „przerobienia” na Kuking.

Lepszy model:

```text
Kuking
├── social/photo       → Pixelfed
├── Laravel quality    → Laravel.io
├── recipe domain      → Tandoor + Mealie
├── groups/community   → Fresns + Flarum
├── moderation         → Discourse
├── admin              → Filament
├── household utility  → KitchenOwl + Grocy
└── Laravel tooling    → Livewire + Spatie + Laravel packages
```

Kuking powinien pozostać własnym, prostym **modularnym monolitem**. Publiczne repozytoria są biblioteką sprawdzonych decyzji, edge-case'ów i UX — nie kodem do posklejania.

## Snapshot repo sprawdzonych w researchu

- https://github.com/pixelfed/pixelfed
- https://github.com/laravelio/laravel.io
- https://github.com/fresns/fresns
- https://github.com/mealie-recipes/mealie
- https://github.com/TandoorRecipes/recipes
- https://github.com/discourse/discourse
- https://github.com/flarum/framework
- https://github.com/filamentphp/filament
- https://github.com/TomBursch/kitchenowl
- https://github.com/grocy/grocy
- https://github.com/reaper47/recipya
- https://github.com/openfoodfacts/openfoodfacts-server
- https://github.com/recipe-scrapers/recipe-scrapers
- https://github.com/hhursev/recipe-scrapers
- https://github.com/livewire/livewire
- https://github.com/spatie/laravel-permission
- https://github.com/spatie/laravel-activitylog
- https://github.com/spatie/laravel-medialibrary
- https://github.com/spatie/laravel-backup
- https://github.com/laravel/pennant
- https://github.com/laravel/scout
- https://github.com/laravel/horizon
- https://github.com/Intervention/image
- https://github.com/Intervention/image-laravel
