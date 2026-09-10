# Audyt 3 — baza danych i integralność

**Bazowy commit:** `cee15a56fa82985d852b2724a880e425cb83dd9d`  
**Data:** 2026-09-10

## Wniosek

Model danych jest projektowany świadomie: UUID, `timestamptz`, FK, CHECK-i, indeksy częściowe i testy rollbacków są traktowane jako część produktu, nie detal ORM. Najpoważniejsza niespójność dotyczy jednak zgody na tygodniowy digest: funkcja wysyłki już istnieje, a baza nadal nie zapisuje dowodu czasu udzielenia/wycofania zgody. Dodatkowo rollback migracji, która naprawiła wcześniejszy opt-out-by-default, ponownie ustawia `DEFAULT true`.

**Ocena techniczna schematu: 9/10. Ocena dowodowości zgód marketingowo-komunikacyjnych: 5/10.**

## Mocne strony

- testy CI wykonują migracje na PostgreSQL 18 i `migrate:refresh`, więc sprawdzają `down()`;
- realne FK/CHECK-i w bazie, a nie wyłącznie walidacja PHP;
- `recipe_versions` od początku;
- indeks częściowy kolejki digestu zgodny z zapytaniem (`wants_weekly_digest`, `NULLS FIRST`);
- statusy kont, wygasanie kar i kasowanie danych mają jawne inwarianty bazodanowe;
- dokumentacja rollbacków jest wyjątkowo szczegółowa.

## Ustalenia

### DB1 — P1 — brak dowodu udzielenia i wycofania zgody na tygodniowy digest

**Dowód:** `PrivacySettingsController::update()` zapisuje tylko boolean `wants_weekly_digest`. `users` ma `weekly_digest_sent_at`, ale brak pól typu `weekly_digest_consented_at` / `weekly_digest_withdrawn_at` lub osobnego audytu zgody. `docs/DATABASE.md` sam stwierdza: „nie ma kolumny z datą wyrażenia i datą wycofania zgody, więc wycofania nie da się dziś wykazać”, po czym w tym samym dokumencie aktualizacja z 10 września potwierdza, że wysyłka już istnieje.

**Skutek:** da się określić obecny stan i ostatnią wysyłkę, ale nie da się wykazać historii podstawy, jeśli użytkownik włączy, wyłączy i ponownie włączy mailing.

**Naprawa:** dodać niezmienny dziennik zgód albo minimum dwie daty: ostatnie udzielenie i ostatnie wycofanie. Dla pełnej dowodowości lepsza jest append-only tabela `consent_events` (`user_id`, `purpose`, `action`, `occurred_at`, `policy_version`, opcjonalnie źródło UI bez IP/UA). Nie używać JSONB do historii, bo to dane strukturalne.

### DB2 — P1 — rollback migracji zgody przywraca `DEFAULT true`

**Dowód:** `2026_09_07_400000_default_weekly_digest_to_off.php::down()` wykonuje:

```sql
ALTER TABLE users ALTER COLUMN wants_weekly_digest SET DEFAULT true
```

Migracja powstała właśnie dlatego, że `DEFAULT true` zapisywał ludzi na digest bez ich decyzji. Gdy migrację pisano, digestu nie było; od 10 września funkcja już istnieje.

**Skutek:** techniczny rollback może ponownie tworzyć nowe konta z aktywnym digestem bez zgody. Komentarz słusznie nie przestawia istniejących wierszy, ale pomija problem NOWYCH wierszy tworzonych po rollbacku.

**Naprawa:** `down()` nie powinien przywracać niebezpiecznego zachowania. Dla tej migracji bezpieczniejszy jest rollback pozostawiający `DEFAULT false` (jawnie udokumentowana asymetria) albo twarda bramka, która przy `DEFAULT true` uniemożliwia uruchomienie wysyłki. Po uruchomieniu digestu „historycznie wierny rollback” jest gorszy od bezpiecznego rollbacku.

### DB3 — P2 — historia dokumentacji miesza stan historyczny z aktualnym w jednym miejscu

`docs/DATABASE.md` zachowuje stare, przekreślone i zastąpione opisy obok aktualnych inwariantów. Ma to wartość jako historia decyzji, ale dokument „Model danych” staje się jednocześnie specyfikacją i archiwum.

**Ryzyko:** agent/deweloper może zacytować akapit historyczny jako obowiązujący. Już w sekcji digestu trzeba czytać aktualizację z późniejszej daty, żeby wiedzieć, że zdanie „wysyłki nie ma” jest nieaktualne.

**Naprawa:** `DATABASE.md` utrzymywać jako wyłącznie stan aktualny; historię przenieść do `DECISIONS.md` / `docs/history/` z linkiem.

## Priorytet

1. DB2 przed kolejnymi operacjami migracyjnymi/rollbackiem na produkcji.
2. DB1 przed wysyłką digestu do realnej grupy użytkowników.
3. DB3 jako porządek dokumentacyjny.
