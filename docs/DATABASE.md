# Model danych

Opis schematu PostgreSQL: dla każdej tabeli — kolumny, ograniczenia, indeksy, powód decyzji i plan wycofania (rollback). Ten plik jest **tylko indeksem**; treść leży w `docs/baza/` (po jednym pliku na obszar, do ok. 40 KB każdy).

## Zasady

- UUID dla publicznych encji;
- `timestamptz`;
- realne foreign keys;
- constraints w bazie;
- soft delete tam, gdzie pomaga odzyskiwaniu/moderacji;
- JSONB tylko dla półstrukturalnych danych;
- recipe versions od początku.

## Jak szukać

1. Znajdź tabelę w kolumnie „Tabele" poniżej i otwórz plik z pierwszej kolumny.
2. W pliku szukaj nagłówka `### nazwa_tabeli` (kolumny dołożone później mają własne nagłówki `####`).
3. Reguły wspólne (FK, unikalność e-maila, wycofania) są w `baza/zasady-schematu.md`; budżet połączeń w `baza/budzet-polaczen.md`.

Z wiersza poleceń: `grep -rn "nazwa_tabeli" docs/baza/`.

## Zmiana schematu

Przy zmianie schematu dopisz opis do pliku obszaru (z planem wycofania, AGENTS.md §6). Jeśli to **nowa tabela** — dopisz też wiersz w tabeli poniżej; jeśli obszar jest nowy, załóż plik w `docs/baza/` i wymień go tu. Testy pilnują, żeby każdy plik z `docs/baza/` był w indeksie i odwrotnie, a każda tabela w bazie miała sekcję.

## Pliki i tabele

| Plik | Obszar | Tabele |
| --- | --- | --- |
| [`zasady-schematu`](baza/zasady-schematu.md) | Zasady schematu i reguły wspólne | (reguły ogólne, bez tabel) |
| [`konta-ustawienia-zgody`](baza/konta-ustawienia-zgody.md) | Konta (`users`) — ustawienia, zgody, e-mail, sesja | profile_username_redirects, users |
| [`konta-kary-i-usuniecie`](baza/konta-kary-i-usuniecie.md) | Konta (`users`) — kary, usuwanie konta, wymazanie | users |
| [`konta-2fa-i-tozsamosci`](baza/konta-2fa-i-tozsamosci.md) | Konta — 2FA, indeksy panelu, logowanie zewnętrzne | users (2FA, indeksy), facebook_connection_proofs, tozsamosci_zewnetrzne |
| [`profile-relacje-reakcje`](baza/profile-relacje-reakcje.md) | Profile, obserwowanie, blokady, reakcje, ukrywanie | profiles, follows, blocks, post_reactions, hides |
| [`media-i-wpisy`](baza/media-i-wpisy.md) | Media i wpisy | media, posts, post_media, zalegle_czyszczenia_cdn |
| [`przepisy`](baza/przepisy.md) | Przepisy, ceny i wersje | recipes, ceny_skladnikow, recipe_slug_redirects, recipe_versions |
| [`skladniki-kroki-i-miary`](baza/skladniki-kroki-i-miary.md) | Składniki, kroki, miary | ingredients, units, recipe_ingredients, recipe_steps, skladniki_odzywcze, miary_domowe, aliasy_skladnikow, users.moj_stol_enabled |
| [`wspomnienia-i-urodziny`](baza/wspomnienia-i-urodziny.md) | Wspomnienia „Rok temu gotowałaś…" i urodziny bez roku | wspomnienia (issue #34), urodziny bez roku (users) |
| [`ugotowalem-komentarze-zeszyty`](baza/ugotowalem-komentarze-zeszyty.md) | Ugotowałem, komentarze, zeszyty | collection_items, cooked_events, cooked_event_media, comment_thanks, comments, collections, collection_members, collection_invitations, first_post_events |
| [`powiadomienia-i-poczta`](baza/powiadomienia-i-poczta.md) | Powiadomienia, przegląd tygodnia, push, poczta | notifications, weekly_digest_sends, push_subscriptions, ustawienia_powiadomien_zewnetrznych, mail_failures, przypomnienia_dobowe |
| [`kontakt`](baza/kontakt.md) | Formularz kontaktowy | contact_messages, contact_message_replies |
| [`zgloszenia`](baza/zgloszenia.md) | Zgłoszenia i pilne alarmy | reports, human_urgent_alarm_attempts |
| [`moderacja-odwolania-audyt`](baza/moderacja-odwolania-audyt.md) | Moderacja, odwołania, dziennik audytu | moderation_actions, appeals, audit_log |
| [`rodo-zgody-i-eksport`](baza/rodo-zgody-i-eksport.md) | RODO — potwierdzenia, dziennik zgód, eksport danych | potwierdzenia_zadan_rodo, dziennik_zgod, data_exports |
| [`importy-i-ai`](baza/importy-i-ai.md) | Importy przepisów i budżet AI | importy_przepisow, ai_budzet_dzienny, ai_rezerwacje, proby_importu, przepisy_z_importu, wczytane_z_paczki |
| [`logowanie-sesje-tokeny`](baza/logowanie-sesje-tokeny.md) | Logowanie bez hasła, zaproszenia, sesje, tokeny | pending_email_changes, login_link_tokens, registration_invites, cache, password_reset_tokens, personal_access_tokens, sessions |
| [`sygnaly-i-tagi`](baza/sygnaly-i-tagi.md) | Sygnały produktowe i tagi | product_signals, tags, tag_aliases, post_tags, tag_follows, tag_promotions, tag_highlights |
| [`planowanie-v2`](baza/planowanie-v2.md) | Planer, lista zakupów, spiżarnia i gotowanie (V2) | meal_plan_entries, shopping_list_items, shopping_list_undos, pantry_items, cooking_progress, weekly_recipe_picks |
| [`wyszukiwarka-i-wybory-dnia`](baza/wyszukiwarka-i-wybory-dnia.md) | Wyszukiwarka i wybory redakcyjne | daily_picks, hero_picks (+ funkcja kuking_normalize, kolumny *_search) |
| [`budzet-polaczen`](baza/budzet-polaczen.md) | Budżet połączeń PostgreSQL (issue #598) | (bez tabel: połączenia PostgreSQL) |
| [`migracje-danych-i-wdrozenia`](baza/migracje-danych-i-wdrozenia.md) | Migracje danych i `wdrozenia` | wdrozenia, wdrozenia_funkcje |
