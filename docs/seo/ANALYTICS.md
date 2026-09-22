# Analityka produktowa — Kuking.pl

Wszystkie zapytania SQL w tym dokumencie zostały uruchomione i zweryfikowane na prawdziwym `database/reference/schema_mvp.sql` (PostgreSQL, lokalny test z realnymi danymi) — nie są to zgadywane składnie.

---

## 1. Taksonomia zdarzeń (PostHog)

Zasady nazewnictwa: `snake_case`, angielski, czasownik w formie dokonanej dla zdarzeń zakończonych (`_published`, `_completed`) i rzeczownikowa/rozpoczynająca dla startu (`_started`). Zamiast mnożyć osobne nazwy zdarzeń per krok formularza, tam gdzie kroki są jednorodne (onboarding, kreator przepisu) używamy **jednego zdarzenia z właściwością `step`** — łatwiej to utrzymać i budować lejek w PostHog bez zmian schematu przy każdej zmianie UI.

Każde zdarzenie automatycznie niesie: `user_id` (lub `anonymous_id` przed rejestracją), `session_id`, `platform` (`web`|`pwa`|`ios_pwa`|`android_pwa`), `text_scale` (bieżąca wartość `users.text_scale` — ważne, bo wpływa na UX i warto korelować z porzuceniami).

### 1.1 Rejestracja i onboarding

| event_name | Właściwości | Kiedy wysyłać | Opis (PL) |
|---|---|---|---|
| `onboarding_step_viewed` | `step` (`account_start`\|`email`\|`password`\|`username`\|`email_verification`\|`interests`\|`follow_suggestions`\|`feed_shown`), `step_index` | Wejście na każdy ekran flow rejestracji z `FLOWS_AND_SCREENS.md` | Użytkownik zobaczył dany krok rejestracji/onboardingu |
| `onboarding_step_completed` | `step`, `step_index`, `seconds_on_step` | Poprawne przejście do następnego kroku | Krok ukończony poprawnie (walidacja przeszła) |
| `onboarding_step_failed` | `step`, `error_reason` (`invalid_email`\|`weak_password`\|`username_taken`\|`invalid_username_format`) | Walidacja kroku nie przeszła | Błąd walidacji na danym kroku — kluczowe do znalezienia miejsc, gdzie ludzie utykają |
| `account_created` | `signup_method` (`email`), `referrer_source` (`direct`\|`facebook`\|`invite_link`\|`search`\|`other`) | Rekord w `users` zapisany | Konto założone (odpowiednik `users.created_at`) |
| `email_verification_sent` | — | Wysłano maila weryfikacyjnego | |
| `email_verified` | `seconds_since_signup` | Kliknięcie linku weryfikacyjnego | Konto zweryfikowane (`users.email_verified_at` ustawione) |
| `onboarding_abandoned` | `last_step_completed`, `seconds_since_start` | `visibilitychange`/zamknięcie karty bez ukończenia `feed_shown` w danej sesji (best-effort, `navigator.sendBeacon`) | Porzucenie onboardingu — nie zawsze wykryje 100% przypadków, dlatego lejek liczymy też z braku kolejnego `onboarding_step_completed` w oknie 24h |
| `onboarding_completed` | `followed_count`, `selected_interests_count`, `total_seconds` | Dotarcie do `feed_shown` | Onboarding ukończony, użytkownik na feedzie |

### 1.2 Dodanie zdjęcia / wpis

| event_name | Właściwości | Kiedy wysyłać | Opis (PL) |
|---|---|---|---|
| `post_create_started` | `entry_point` (`bottom_nav_add`\|`home_cta`\|`recipe_cook_prompt`) | Kliknięcie „+ Dodaj” → „Dodaj zdjęcie” | Start tworzenia wpisu |
| `photo_upload_selected` | `file_count`, `source` (`camera`\|`gallery`) | Wybór pliku(ów) w systemowym pickerze | Użytkownik wybrał zdjęcie do wgrania |
| `photo_upload_succeeded` | `media_id`, `file_size_kb`, `width`, `height`, `duration_ms` | Zakończony upload + walidacja w `ProcessUploadedImage` | Zdjęcie wgrane i zwalidowane poprawnie |
| `photo_upload_failed` | `reason` (`too_large`\|`invalid_format`\|`network_error`\|`server_error`\|`decode_failed`), `file_size_kb` | Błąd na dowolnym etapie uploadu | Zdjęcie odrzucone — `reason` musi być mapowalne 1:1 na komunikat błędu z `UX_50_PLUS.md` (nigdy kod HTTP) |
| `post_text_added` | `char_count` | `blur` pola tekstu z niepustą wartością | Dodano tekst do wpisu |
| `post_visibility_selected` | `visibility` (`public`\|`followers`\|`private`) | Zmiana selektora widoczności | |
| `post_create_abandoned` | `last_step`, `seconds_since_start` | Wyjście z ekranu tworzenia bez publikacji | Porzucenie tworzenia wpisu |
| `post_published` | `post_id`, `has_text`, `media_count`, `visibility`, `seconds_since_start` | Zapis `posts.status='published'` | Wpis opublikowany — kluczowe zdarzenie „niski próg publikacji” z `PRODUCT.md` |

### 1.3 Kreator przepisu

| event_name | Właściwości | Kiedy wysyłać | Opis (PL) |
|---|---|---|---|
| `recipe_create_started` | `entry_point`, `recipe_draft_id` | Kliknięcie „Pełny przepis” | Start kreatora 3-krokowego |
| `recipe_step_viewed` | `step` (1\|2\|3), `recipe_draft_id` | Wejście na krok „o przepisie”/„składniki”/„przygotowanie” | |
| `recipe_step_completed` | `step`, `recipe_draft_id`, `seconds_on_step` | Kliknięcie „Dalej” z przechodzącą walidacją | |
| `recipe_draft_autosaved` | `step`, `recipe_draft_id`, `fields_changed_count` | Każdy udany autosave (debounced, patrz `SEO_TECHNICAL.md` §5.2) | Techniczne potwierdzenie działania autosave — jeśli częstotliwość tego zdarzenia gwałtownie spada, to sygnał awarii, zanim ktokolwiek się poskarży |
| `recipe_create_abandoned` | `last_step`, `recipe_draft_id`, `seconds_since_start` | Wyjście bez publikacji, szkic pozostaje jako draft | Porzucony szkic przepisu (metryka „abandoned recipe drafts” z `SEO_ANALYTICS_GROWTH.md`) |
| `recipe_published` | `recipe_id`, `ingredient_count`, `step_count`, `has_photo`, `servings`, `prep_minutes`, `cook_minutes`, `source_type` | Zapis `recipes.status='published'` | Przepis opublikowany |

### 1.4 „Ugotowałem”

| event_name | Właściwości | Kiedy wysyłać | Opis (PL) |
|---|---|---|---|
| `cooked_event_started` | `recipe_id`, `entry_point` | Kliknięcie „Ugotowałem” na stronie przepisu | |
| `cooked_event_photo_added` | `recipe_id`, `has_photo` | Dodanie/pominięcie zdjęcia w formularzu | |
| `cooked_event_abandoned` | `recipe_id`, `last_step` | Wyjście bez publikacji | |
| `cooked_event_published` | `cooked_event_id`, `recipe_id`, `has_photo`, `has_note`, `would_make_again`, `actual_minutes`, `perceived_difficulty` | Zapis w `cooked_events` | Najsilniejszy sygnał jakości wg `PRODUCT.md` — traktować priorytetowo w analizach |

### 1.5 Komentarz, follow, kolekcje

| event_name | Właściwości | Kiedy wysyłać | Opis (PL) |
|---|---|---|---|
| `comment_started` | `target_type` (`post`\|`recipe`\|`cooked_event`), `target_id` | Focus pola komentarza | |
| `comment_published` | `comment_id`, `target_type`, `target_id`, `is_reply`, `char_count` | Zapis w `comments` | |
| `user_followed` | `followed_user_id`, `entry_point` (`profile`\|`suggestion`\|`search`\|`onboarding`) | Zapis w `follows` | |
| `user_unfollowed` | `followed_user_id` | Usunięcie z `follows` | |
| `collection_created` | `collection_id`, `visibility` | Zapis w `collections` | |
| `recipe_saved` | `recipe_id`, `collection_id`, `is_new_collection` | Zapis w `collection_items` | „Zapis do kolekcji” |
| `recipe_unsaved` | `recipe_id`, `collection_id` | Usunięcie z `collection_items` | |

### 1.6 Search

| event_name | Właściwości | Kiedy wysyłać | Opis (PL) |
|---|---|---|---|
| `search_performed` | `query_text` (oczyszczony — patrz §4), `query_length`, `result_type` (`recipes`\|`people`\|`all`), `result_count`, `filters_used` (array) | Wykonanie zapytania (po debounce, nie na każdy klawisz) | |
| `search_zero_results` | `query_text`, `query_length`, `result_type` | `result_count = 0` | Kluczowe dla wykrywania luk w treści/synonimach |
| `search_result_clicked` | `result_type`, `result_position`, `target_id` | Kliknięcie w wynik | |

### 1.7 Ustawienia dostępności, moderacja, dane, PWA

| event_name | Właściwości | Kiedy wysyłać | Opis (PL) |
|---|---|---|---|
| `text_size_changed` | `from_scale`, `to_scale`, `source` (`onboarding`\|`settings`) | Zmiana `users.text_scale` | Zwiększenie/zmniejszenie rozmiaru tekstu — istotne dla grupy 50+ |
| `content_reported` | `target_type` (`user`\|`post`\|`recipe`\|`comment`\|`cooked_event`), `reason` | Zapis w `reports` | Nigdy nie wysyłaj treści zgłoszenia (`details`), tylko metadane |
| `data_export_requested` | — | Kliknięcie „Pobierz swoje dane” | |
| `data_export_completed` | `seconds_to_complete`, `file_size_mb` | Zakończenie joba `GenerateUserExport` | |
| `data_export_downloaded` | — | Kliknięcie linku pobrania gotowego ZIP | |
| `account_deletion_requested` | `reason_category` | Krok „Usuń konto” → wyjaśnienie → potwierdzenie | |
| `account_deletion_completed` | — | Zakończony proces zgodny z retention | |
| `pwa_install_prompted` | `platform` (`android`\|`ios`\|`desktop`) | Zdarzenie `beforeinstallprompt` (Android/desktop) lub pokazanie własnej instrukcji iOS | |
| `pwa_install_accepted` | `platform` | Użytkownik potwierdził instalację | |
| `pwa_install_dismissed` | `platform` | Użytkownik odrzucił baner | |
| `pwa_installed` | `platform` | Zdarzenie `appinstalled` | Potwierdzona instalacja PWA |

---

## 2. North Star: Weekly Active Cooks (WAC)

### 2.1 Definicja operacyjna

**Weekly Active Cook** = użytkownik, który w danym tygodniu kalendarzowym (poniedziałek–niedziela, wg `date_trunc('week', …)` Postgresa) wykonał **co najmniej jedną** z trzech akcji:

1. opublikował post (`posts.status = 'published'`, licząc po `published_at`);
2. opublikował przepis (`recipes.status = 'published'`, licząc po `published_at`);
3. utworzył zdarzenie „Ugotowałem” (`cooked_events`, licząc po `cooked_at`).

Zgodne 1:1 z definicją z `docs/PRODUCT.md` i `docs/SEO_ANALYTICS_GROWTH.md`. Świadomie **nie** liczymy tu samych odsłon, lajków ani komentarzy jako kwalifikujących do WAC — to metryka *tworzenia*, nie *konsumpcji* (spójne z zasadą „nie optymalizujemy pod same odsłony”).

Usunięte miękko rekordy (`deleted_at IS NOT NULL`) są wykluczone z `posts`/`recipes`; `cooked_events` nie ma soft delete w MVP, więc liczone są wszystkie.

**Wykluczenia (issue #114).** Poniższe zapytania liczą tylko konta, które mają liczyć się do North Star — nie każde konto z wierszem w `posts`/`recipes`/`cooked_events`:

- konta `status IN ('banned', 'pending_delete')`;
- konto gospodarza (`config('kuking.community.host_username')`) — publikuje z definicji co tydzień (`docs/product/COLD_START.md`), więc bez wykluczenia byłby gwarantowanym, cotygodniowym wpisem do liczby, którą zespół czyta jako dowód sukcesu przy 20-50 kontach zamkniętej alfy;
- konta testowe/deweloperskie z listy w konfiguracji (`config('kuking.account.test_usernames')` — świadomie konfiguracja, nie kolumna w bazie na tym etapie, patrz komentarz w `config/kuking.php`).

Reguła „kto się liczy" mieszka w jednym miejscu w kodzie — `App\Domain\Analytics\CookEligibility` — i jest tam, nie tutaj, źródłem prawdy: to SQL niżej jest opisem tamtej klasy, nie odwrotnie.

### 2.2 Zapytanie SQL — WAC tygodniowo (zweryfikowane na `database/reference/schema_mvp.sql`)

```sql
WITH wykluczeni_uzytkownicy AS (
    SELECT id AS user_id
    FROM users
    WHERE status IN ('banned', 'pending_delete')

    UNION

    SELECT p.user_id
    FROM profiles p
    WHERE lower(p.username) IN (
        -- gospodarz (kuking.community.host_username) i konta testowe
        -- (kuking.account.test_usernames) z configu — tu przykładowe wartości:
        'woogitsu', 'qa-wewnetrzne'
    )
),
weekly_cook_activity AS (
    SELECT author_id AS user_id, published_at AS activity_at
    FROM posts
    WHERE status = 'published'
      AND published_at IS NOT NULL
      AND deleted_at IS NULL
      AND author_id NOT IN (SELECT user_id FROM wykluczeni_uzytkownicy)

    UNION ALL

    SELECT author_id AS user_id, published_at AS activity_at
    FROM recipes
    WHERE status = 'published'
      AND published_at IS NOT NULL
      AND deleted_at IS NULL
      AND author_id NOT IN (SELECT user_id FROM wykluczeni_uzytkownicy)

    UNION ALL

    SELECT user_id, cooked_at AS activity_at
    FROM cooked_events
    WHERE user_id NOT IN (SELECT user_id FROM wykluczeni_uzytkownicy)
)
SELECT
    date_trunc('week', activity_at)::date AS week_start,
    (date_trunc('week', activity_at)::date + interval '6 days')::date AS week_end,
    count(DISTINCT user_id) AS weekly_active_cooks
FROM weekly_cook_activity
GROUP BY 1
ORDER BY 1;
```

Wariant „tylko bieżący tydzień” (do kafelka na dashboardzie, sekcja 6):

```sql
WITH wykluczeni_uzytkownicy AS (
    SELECT id AS user_id
    FROM users
    WHERE status IN ('banned', 'pending_delete')

    UNION

    SELECT p.user_id
    FROM profiles p
    WHERE lower(p.username) IN ('woogitsu', 'qa-wewnetrzne')
),
weekly_cook_activity AS (
    SELECT author_id AS user_id, published_at AS activity_at
    FROM posts
    WHERE status = 'published'
      AND published_at IS NOT NULL
      AND deleted_at IS NULL
      AND author_id NOT IN (SELECT user_id FROM wykluczeni_uzytkownicy)

    UNION ALL

    SELECT author_id AS user_id, published_at AS activity_at
    FROM recipes
    WHERE status = 'published'
      AND published_at IS NOT NULL
      AND deleted_at IS NULL
      AND author_id NOT IN (SELECT user_id FROM wykluczeni_uzytkownicy)

    UNION ALL

    SELECT user_id, cooked_at AS activity_at
    FROM cooked_events
    WHERE user_id NOT IN (SELECT user_id FROM wykluczeni_uzytkownicy)
)
SELECT count(DISTINCT user_id) AS weekly_active_cooks_current_week
FROM weekly_cook_activity
WHERE activity_at >= date_trunc('week', now())
  AND activity_at <  date_trunc('week', now()) + interval '7 days';
```

Komenda `php artisan kuking:wac` liczy dokładnie to pierwsze zapytanie (wszystkie tygodnie, opcjonalnie ograniczone do `--tygodnie=N` najnowszych) — `App\Domain\Analytics\WeeklyActiveCooks`.

Obie wersje przetestowane na lokalnej instancji PostgreSQL z realnym `database/reference/schema_mvp.sql` i przykładowymi wierszami — zwracają poprawne wyniki (3 aktywnych „cooków” w tygodniu testowym dla 3 różnych typów aktywności).

**Uwaga operacyjna:** to zapytanie liczy WAC bezpośrednio z Postgresa (nie z PostHog) — bo wszystkie trzy źródła prawdy (`posts`, `recipes`, `cooked_events`) już tam są i to jedyne miejsce ze 100% kompletnością (PostHog może gubić zdarzenia przy błędach sieci/adblockerach po stronie klienta). North Star **nigdy nie powinien zależeć wyłącznie od trackingu klienckiego** — licz go z bazy produkcyjnej (np. nocny job materializujący wynik do tabeli `metrics_weekly_snapshots`, czytany przez dashboard), a PostHog trzymaj do zdarzeń zachowania (lejki, porzucenia, UX), gdzie kompletność 100% nie jest krytyczna.

---

## 3. Drzewo metryk

```text
North Star: Weekly Active Cooks (WAC)
│
├── Aktywacja (SEO_ANALYTICS_GROWTH.md: >=3 z 4 w 7 dni)
│     ├── D1/D7 do pierwszej publikacji (post LUB przepis)
│     ├── % nowych kont z >=1 follow w 7 dni
│     ├── % nowych kont z >=3 zapisami do kolekcji w 7 dni
│     └── % nowych kont z >=1 cooked event w 7 dni
│
├── Retencja
│     ├── D1 / D7 / D30 (powrót do jakiejkolwiek aktywności "cook")
│     ├── WAU/MAU (stosunek)
│     ├── Creator D30 (autorzy postów/przepisów aktywni 30 dni po pierwszej publikacji)
│     └── Cooked-event D30 (użytkownicy z >=1 "Ugotowałem" w kolejnych 30 dniach)
│
├── Jakość treści i interakcji
│     ├── Cooksnaps na przepis (cooked_events / recipe)
│     ├── Save → cooked (% zapisanych przepisów, które faktycznie ugotowano)
│     ├── Would-make-again rate (% cooked_events.would_make_again = true)
│     ├── Przepisy z >=3 cooked events (dowód realnego zaufania społeczności)
│     ├── Komentarze na wpis / na przepis
│     └── Odsetek wpisów ze zdjęciem (post_media / posts)
│
├── Zdrowie społeczności
│     ├── Time to first interaction (czas do pierwszego komentarza/cooked event pod nową treścią)
│     ├── Reply rate (% komentarzy z odpowiedzią autora)
│     ├── % postów/przepisów z >=1 interakcją w 7 dni
│     ├── Reports / 1000 postów
│     └── Repeat offender rate (% użytkowników z >1 `moderation_action`)
│
└── Higiena operacyjna (prowadzą do WAC pośrednio)
      ├── Feed empty rate (% wizyt na `/home` z pustym feedem — brak obserwowanych/treści)
      ├── Upload error rate (`photo_upload_failed` / (`photo_upload_failed` + `photo_upload_succeeded`))
      └── Autosave failure rate (kreator przepisu)
```

### 3.1 Przykładowe zapytania — aktywacja D7 (Postgres, zweryfikowane)

```sql
WITH activation_signals AS (
    SELECT
        u.id AS user_id,
        (
            SELECT count(*) >= 5
            FROM follows f
            WHERE f.follower_id = u.id
              AND f.created_at <= u.created_at + interval '7 days'
        ) AS followed_5,
        (
            SELECT count(*) >= 3
            FROM collection_items ci
            JOIN collections c ON c.id = ci.collection_id
            WHERE c.owner_id = u.id
              AND ci.created_at <= u.created_at + interval '7 days'
        ) AS saved_3,
        (
            EXISTS (
                SELECT 1 FROM posts p
                WHERE p.author_id = u.id AND p.status = 'published'
                  AND p.published_at <= u.created_at + interval '7 days'
            )
            OR EXISTS (
                SELECT 1 FROM recipes r
                WHERE r.author_id = u.id AND r.status = 'published'
                  AND r.published_at <= u.created_at + interval '7 days'
            )
        ) AS published_once,
        EXISTS (
            SELECT 1 FROM cooked_events ce
            WHERE ce.user_id = u.id
              AND ce.created_at <= u.created_at + interval '7 days'
        ) AS cooked_once
    FROM users u
    WHERE u.created_at <= now() - interval '7 days'  -- tylko konta, które miały pełne 7 dni
)
SELECT
    count(*) FILTER (
        WHERE (followed_5::int + saved_3::int + published_once::int + cooked_once::int) >= 3
    )::numeric / NULLIF(count(*), 0) AS activation_rate_d7
FROM activation_signals;
```

### 3.2 Kohorta retencji tygodniowej (proxy „cook activity”, zweryfikowane)

**To samo wykluczenie co §2.2 (issue #114)**, po obu stronach złączenia: konto gospodarza/zbanowane/`pending_delete`/testowe nie ma wchodzić do kohorty ani jako „aktywność”, ani jako sam tydzień rejestracji (`user_weeks`). Bez wykluczenia z `user_weeks` gospodarz i tak nie pojawiłby się w wyniku — złączenie z pustą aktywnością nic by nie dało — ale dwa miejsca wykluczenia to jaśniejszy dowód, że to jest TA SAMA reguła (`App\Domain\Analytics\CookEligibility`), nie efekt uboczny czegoś innego. Implementacja: `App\Domain\Analytics\CookRetentionCohorts`.

```sql
WITH wykluczeni_uzytkownicy AS (
    SELECT id AS user_id
    FROM users
    WHERE status IN ('banned', 'pending_delete')

    UNION

    SELECT p.user_id
    FROM profiles p
    WHERE lower(p.username) IN ('woogitsu', 'qa-wewnetrzne') -- z configu, patrz §2.2
),
activity AS (
    SELECT author_id AS user_id, published_at AS activity_at
    FROM posts WHERE status = 'published' AND published_at IS NOT NULL AND deleted_at IS NULL
      AND author_id NOT IN (SELECT user_id FROM wykluczeni_uzytkownicy)
    UNION ALL
    SELECT author_id, published_at FROM recipes
    WHERE status = 'published' AND published_at IS NOT NULL AND deleted_at IS NULL
      AND author_id NOT IN (SELECT user_id FROM wykluczeni_uzytkownicy)
    UNION ALL
    SELECT user_id, cooked_at FROM cooked_events
    WHERE user_id NOT IN (SELECT user_id FROM wykluczeni_uzytkownicy)
),
user_weeks AS (
    SELECT u.id AS user_id, date_trunc('week', u.created_at)::date AS signup_week
    FROM users u
    WHERE u.id NOT IN (SELECT user_id FROM wykluczeni_uzytkownicy)
),
activity_weeks AS (
    SELECT user_id, date_trunc('week', activity_at)::date AS activity_week
    FROM activity
    GROUP BY user_id, date_trunc('week', activity_at)::date
)
SELECT
    uw.signup_week,
    ((aw.activity_week - uw.signup_week) / 7)::int AS week_offset,
    count(DISTINCT uw.user_id) AS active_users
FROM user_weeks uw
JOIN activity_weeks aw
  ON aw.user_id = uw.user_id
 AND aw.activity_week >= uw.signup_week
GROUP BY 1, 2
ORDER BY 1, 2;
```

`week_offset = 0` to tydzień rejestracji, `1` to D7-ish retencja, `4` to D30-ish. Podziel `active_users` przy danym `week_offset` przez `active_users` przy `week_offset = 0` dla tej samej kohorty, żeby dostać % retencji.

**Rozróżnienie ważne dla uczciwości metryk:** powyższe zapytania mierzą **retencję twórczą** (czy ktoś wrócił, żeby *zrobić* coś — opublikować, ugotować), nie retencję sesyjną (samo otwarcie apki). Retencja sesyjna (dowolne otwarcie, bez akcji) wymaga zdarzenia sesji w PostHog (np. `$pageview`/custom `app_opened`) — Postgres nie przechowuje logowań/sesji w MVP. Rekomendacja: raportować **oba** — twórczą z Postgresa (bardziej wiarygodna, bo 100%-kompletna) i sesyjną z PostHog (szersza, ale zależna od trackingu klienckiego).

---

## 4. Kohorty i lejki od pierwszego dnia (max 5)

1. **Lejek onboardingu** — `onboarding_step_viewed`/`_completed` per krok, od `account_start` do `feed_shown`. Cel: znaleźć krok z największym odpadem w pierwszym tygodniu po launchu.
2. **Lejek pierwszej publikacji** — `account_created` → `post_create_started` LUB `recipe_create_started` → `post_published`/`recipe_published`, w oknie 7 dni. To bezpośredni predyktor aktywacji.
3. **Lejek uploadu zdjęcia** — `photo_upload_selected` → `photo_upload_succeeded`/`photo_upload_failed`, segmentowany po `platform` i `file_size_kb`. Krytyczny, bo zdjęcie to serce produktu (`MEDIA_PIPELINE.md`) — jeśli tu jest tarcie, wszystko inne cierpi.
4. **Kohorta tygodniowa retencji „cook”** — sekcja 3.2, śledzona co tydzień od pierwszego dnia produkcyjnego, żeby mieć krzywą retencji zanim ktokolwiek zapyta „czy to działa”.
5. **Lejek „Ugotowałem”** — `cooked_event_started` → `cooked_event_photo_added` → `cooked_event_published`, segmentowany po tym, czy `recipe.author_id = viewer` (własny przepis) czy cudzy — pozwala odróżnić czy silnik social (cudze przepisy) faktycznie działa, czy ludzie tylko dokumentują swoje.

Świadomie nie więcej niż 5 — każdy kolejny lejek/kohorta to koszt utrzymania i ryzyko, że nikt ich nie czyta. Rozszerzać dopiero, gdy te pięć jest rutynowo przeglądane.

---

## 5. Prywatność analityki

Zgodnie z `docs/SECURITY_PRIVACY_LEGAL.md` („Minimalizować tracking. Nie wysyłać treści komentarzy/przepisów do analytics”) i zasadą DSA/RODO data minimization.

**Nigdy nie trafia do PostHog:**
- treść wpisów (`posts.body`), treść przepisów (`recipes.summary`, `recipe_ingredients.*`, `recipe_steps.instruction`), treść komentarzy (`comments.body`), notatki „Ugotowałem” (`cooked_events.note`);
- adresy e-mail, hasła, tokeny;
- surowe pliki/zdjęcia ani ich adresy URL wskazujące na prywatne zasoby;
- treść zgłoszeń moderacyjnych (`reports.details`);
- dokładna lokalizacja/GPS (i tak usuwane z EXIF na poziomie `MEDIA_PIPELINE.md`, ale zasada obowiązuje też dla ewentualnej geolokalizacji przeglądarki).

**Zapytania search (`query_text`) — zasada pośrednia.** Fraza wyszukiwania jest cenna produktowo (widzieć, czego ludzie szukają i nie znajdują), ale może przypadkiem zawierać dane osobowe (ktoś wpisuje czyjeś imię i nazwisko). Reguła: `query_text` wysyłany jest **po** stronie klienta przepuszczony przez prosty filtr odrzucający wzorce e-mail/telefon (regex) i ucinany do 100 znaków; nigdy nie loguj pełnej historii wyszukiwań przypisanej do `user_id` w sposób umożliwiający łatwe przeglądanie „co szukał Pan X” — agregacje tak, surowe dzienniki per-user nie.

**Maskowanie i identyfikatory:**
- PostHog `distinct_id` = `users.id` (UUID) dopiero **po** rejestracji; przed tym anonimowy `anonymous_id` generowany po stronie klienta, łączony z kontem przy `account_created` (`posthog.identify()`), zgodnie ze standardowym wzorcem PostHog.
- Adres IP: PostHog domyślnie zapisuje IP do geolokalizacji na poziomie kraju/miasta, a następnie może go odrzucać — dla Kuking wystarczy poziom **kraju/województwa**, nie trzeba dokładniejszej granulacji; rozważyć `ip: false` w evencie i poleganie na `Accept-Language`/ustawieniach użytkownika zamiast precyzyjnej geolokalizacji, skoro produkt jest jednojęzyczny (`pl-PL`, patrz `SEO_TECHNICAL.md` §6) i nie potrzebuje personalizacji regionalnej.
- `autocapture` PostHog: **wyłączony** (`autocapture: false`) — Kuking wysyła wyłącznie jawnie zdefiniowane zdarzenia z tej tabeli, nigdy automatyczny capture każdego kliknięcia/inputu, bo to najłatwiejsza droga do przypadkowego wycieku treści (np. autocapture potrafi złapać wartość pola tekstowego).

**Tryb bez cookies / Do Not Track:**
- `respect_dnt: true` w konfiguracji PostHog — użytkownik z nagłówkiem `DNT: 1` nie jest trackowany zdarzeniowo.
- Tryb cookieless PostHog (`cookieless_mode`) rozważony jako **domyślny** dla ruchu niezalogowanego (strony publiczne: `/`, `/odkryj`, `/@username`, `/przepisy/{slug}`) — pozwala liczyć unikalnych odwiedzających przez prywatność-zachowujący hash po stronie serwerów PostHog, bez potrzeby bannera cookies na stronach czysto publicznych. Po zalogowaniu (produkt wymaga konta do głównych akcji) możliwy pełny tracking zdarzeniowy z jasną informacją w `docs/SECURITY_PRIVACY_LEGAL.md`/polityce prywatności.

**Retencja danych w PostHog:** ustawić politykę retencji zdarzeń surowych (np. 12–14 miesięcy — wystarczające do porównań rok do roku bez nieskończonego gromadzenia), z agregatami (tygodniowe snapshoty WAC, retencji) trzymanymi bezterminowo w Postgresie (nie podlegają tej samej presji minimalizacji, bo są **zagregowane**, nie per-user).

---

## 6. Dashboard tygodniowy (8–10 kafelków, 5 minut czytania)

1. **WAC — bieżący tydzień** vs poprzedni tydzień (Δ%) — zapytanie z §2.2.
2. **WAC — wykres 12 tygodni** (trend, nie tylko punkt) — zapytanie z §2.2 (pełna wersja).
3. **Nowe konta w tygodniu** + % zweryfikowanych e-mailem w 24h.
4. **Aktywacja D7** — % nowych kont (sprzed >=7 dni) spełniających >=3/4 kryteriów (§3.1).
5. **Opublikowane treści w tygodniu** — rozbicie: liczba postów / liczba przepisów / liczba cooked events (3 mini-liczby obok siebie, nie jeden zlepiony wykres).
6. **Upload error rate** — `photo_upload_failed / (failed + succeeded)`, z rozbiciem top 3 `reason` — jeśli to rośnie, to najpilniejszy techniczny sygnał w całym dashboardzie.
7. **Feed empty rate** — % wizyt na `/home` bez żadnej treści w feedzie (nowi użytkownicy bez followów) — bezpośrednio steruje priorytetem pracy nad sugestiami do obserwowania.
8. **Reports / 1000 opublikowanych treści** + liczba otwartych (`status='open'`) starszych niż 48h — zdrowie moderacji, prosto z `reports`.
9. **Would-make-again rate** (tygodniowo) — jakość realnych przepisów, nie próżny wskaźnik.
10. **Core Web Vitals** (LCP/INP/CLS, 75. percentyl, z PostHog Web Vitals plugin lub Search Console) — jeden zbiorczy kafelek z trzema liczbami i kolorem (dobry/wymaga poprawy/słaby), żeby techniczne SEO (`SEO_TECHNICAL.md` §5) nie zniknęło z radaru właściciela.

Zasada układu: kafelki 1–2 na górze (North Star zawsze pierwszy), 3–5 środek (wzrost i aktywacja), 6–8 dół-lewo (higiena/ryzyko), 9–10 dół-prawo (jakość/techniczne). Żaden kafelek bez porównania do poprzedniego okresu (Δ%) — gołe liczby bez trendu nie mówią nic w 5 minut.

---

## 7. Minimalna alternatywa bez PostHog

Jeśli PostHog okaże się zbyt drogi/ciężki na wczesnym etapie (progi cenowe rosną z wolumenem zdarzeń, a Kuking generuje ich sporo przy autosave/step-viewed), minimalna alternatywa: własna tabela zdarzeń w tym samym Postgresie.

```sql
CREATE TABLE product_events (
    id bigserial PRIMARY KEY,
    user_id uuid REFERENCES users(id) ON DELETE SET NULL,
    anonymous_id varchar(64),
    event_name varchar(100) NOT NULL,
    properties jsonb NOT NULL DEFAULT '{}'::jsonb,
    session_id varchar(64),
    platform varchar(20),
    occurred_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX product_events_name_time_idx ON product_events(event_name, occurred_at DESC);
CREATE INDEX product_events_user_idx ON product_events(user_id, occurred_at DESC);
```

Zdarzenia z sekcji 1 wpisywane bezpośrednio z Laravel (job w kolejce, żeby nie blokować requestu — `QUEUE_CONNECTION=database` już jest w architekturze) zamiast do zewnętrznego SDK.

**Wady tego podejścia (świadomie, żeby nie było niespodzianek):**
- brak gotowych lejków/retencji/UI — każdy wykres to własny SQL + własny mini-dashboard (np. prosty wewnętrzny panel Blade albo arkusz odświeżany zapytaniem);
- brak session replay, heatmap, feature flags — funkcje, które PostHog daje "za darmo" w tej samej platformie;
- rosnąca tabela `product_events` w **tej samej** bazie co dane produkcyjne — przy dużym wolumenie (autosave co kilka sekund × wielu użytkowników) może zacząć konkurować o zasoby z realnymi zapytaniami produktowymi; wymaga partycjonowania po czasie (`occurred_at`) i agresywnej polityki retencji/archiwizacji dużo wcześniej niż w przypadku dedykowanego serwisu analitycznego;
- brak wbudowanego rozróżnienia DNT/cookieless — trzeba to zaimplementować ręcznie (co i tak jest dobrą praktyką, ale to dodatkowa praca, nie „włącznik” jak w PostHog).

Rekomendacja: zostać przy PostHog (self-hosted, jeśli koszt cloud będzie problemem — PostHog jest open-source i self-hosting jest realną opcją przy skali, gdzie hosted plan zaczyna boleć) zamiast budować własne od zera; własna tabela to plan B tylko na wypadek twardej blokady budżetowej, nie domyślna ścieżka.

---

## Źródła

- [How to do cookieless tracking with PostHog](https://posthog.com/tutorials/cookieless-tracking)
- Pliki wewnętrzne projektu: `docs/SEO_ANALYTICS_GROWTH.md`, `docs/PRODUCT.md`, `docs/SECURITY_PRIVACY_LEGAL.md`, `docs/MEDIA_PIPELINE.md`, `docs/ARCHITECTURE.md`, `database/reference/schema_mvp.sql`
- Wszystkie zapytania SQL w tym dokumencie: zweryfikowane uruchomieniem na lokalnej instancji PostgreSQL 16 z realnym `database/reference/schema_mvp.sql` i przykładowymi danymi (środowisko sesji, wrzesień 2026)
