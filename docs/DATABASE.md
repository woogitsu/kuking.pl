# Model danych

## Zasady

- UUID dla publicznych encji;
- `timestamptz`;
- realne foreign keys;
- constraints w bazie;
- soft delete tam, gdzie pomaga odzyskiwaniu/moderacji;
- JSONB tylko dla półstrukturalnych danych;
- recipe versions od początku.

## Tabele MVP

### users
Konto:
- id;
- email;
- password;
- status;
- locale;
- text_scale;
- verified timestamps.

### profiles
- user_id;
- username;
- display_name;
- bio;
- avatar.

### follows
`follower_id + followed_id` unique.

### blocks
Blokada ma pierwszeństwo przed follow.

### media
Tylko metadata, nie binary:
- owner;
- disk;
- object key;
- MIME;
- bytes;
- width/height;
- status;
- checksum;
- perceptual hash;
- metadata.

### posts + post_media
Najprostszy content społecznościowy.

### recipes
Aktualny stan.

### recipe_versions
Snapshot po istotnych zmianach.

### ingredients + units
Podstawa search i późniejszego planera.

### recipe_ingredients
Musi mieć `ingredient_text`, nawet jeśli normalizacja nie rozpozna składnika.

### recipe_steps
Pozycja + instruction + opcjonalny timer/media.

### cooked_events
Jedno realne gotowanie. Brak unique `(user_id, recipe_id)`.

### comments
Komentarz dotyczy dokładnie jednego:
- post;
- recipe;
- cooked event.

### collections + collection_items
Osobisty zeszyt.

### notifications
In-app.

### reports
Zgłoszenia.

### moderation_actions
Decyzje moderatorów.

### audit_log
Wysokiego znaczenia zmiany.

## V1 / V2

Później:
- groups;
- group_members;
- recipe_forks;
- family_books;
- questions;
- answers;
- meal_plans;
- shopping_lists;
- pantry_items;
- tags;
- subscriptions;
- payments.

Pełny referencyjny DDL jest w `database/schema_mvp.sql`.
