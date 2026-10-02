# Formularz kontaktowy

> Część modelu danych Kuking. Spis plików i tabel: [`docs/DATABASE.md`](../DATABASE.md).

### contact_messages

Wiadomości z formularza **„Napisz do nas"** (`/napisz-do-nas`), migracja
`2026_09_09_100000_create_contact_messages_table`. Kontakt z **operatorem
serwisu**: „coś nie działa", „mam pomysł", „chcę wam coś powiedzieć".

**To NIE JEST `reports` i dlatego nie jest w `reports`.** Różnica nie jest
kosmetyczna:

| | `reports` | `contact_messages` |
|---|---|---|
| Czego dotyczy | cudzej treści (`target_type` + `target_id`) | działania serwisu |
| Czym się kończy | decyzją moderatora w `moderation_actions` | odpowiedzią człowieka albo poprawką w kodzie |
| Odwołanie | tak, DSA art. 20, `appeals` | nie ma od czego |
| Retencja | **36 miesięcy** od zamknięcia sprawy | **12 miesięcy** od załatwienia |
| Kolejka w panelu | `/admin/zgloszenia`, najnowsze na górze | `/admin/wiadomosci`, **najstarsze na górze** |

Wrzucenie jednego w drugie kosztuje w obie strony: skarga na wpis w tej
tabeli nigdy nie dostanie decyzji, a opis awarii w `reports` zapycha
najwęższe gardło serwisu (D-012 — zespół to 1–2 osoby) i żyje w bazie trzy
razy dłużej, niż potrzeba. Pilnuje tego
`tests/Feature/WiadomosciDoOperatoraSaOddzielneOdZgloszenTest.php`.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID, `gen_random_uuid()` — wiersz jest adresowany z zewnątrz (`/admin/wiadomosci/{id}`), więc nie `bigserial`. |
| `user_id` | Nullable, `nullOnDelete()`. `NULL` znaczy „gość bez konta" **albo** „konto usunięte" — wiadomość zostaje, bo może być w trakcie załatwiania. Konto jest anonimizowane, nie kasowane (D-022), więc `nullOnDelete()` się nie uruchamia — `user_id` zeruje jawnie `EraseAccountData` w tej samej transakcji co wymazanie (#995, pilnuje `WymazanieKontaOdlaczaWiadomosciDoNasTest`). |
| `klucz_wyslania` | Tożsamość jednego wysłania formularza (D-027). Częściowy `UNIQUE` `contact_messages_one_per_klucz_wyslania` `WHERE klucz_wyslania IS NOT NULL` — wyłącznik `kuking.formularze.klucz_wyslania_wlaczony` zdejmuje mechanizm, wpisując `NULL`. |
| `kind` | `blad` \| `pomysl` \| `inne`. CHECK w bazie (`contact_messages_kind_check`). **Świadomie rozłączne z `Report::REASONS`** — gdyby tu było „Mowa nienawiści", ludzie zgłaszaliby sąsiada formularzem technicznym. |
| `message` | `text`, nie `string`: to jedyne miejsce, gdzie człowiek OPISUJE awarię. Górną granicę (5000 znaków) trzyma walidacja; w bazie stoi CHECK `contact_messages_message_not_blank`, żeby nie dało się zapisać samych spacji. |
| `contact_email` | Tylko dla GOŚCIA. Dla zalogowanego zostaje `NULL` — jego adres jest już na koncie, a kopiowanie go tutaj byłoby powielaniem danych osobowych bez powodu (RODO, minimalizacja). Odpowiedni adres podaje `ContactMessage::adresDoOdpowiedzi()`. |
| `page_path` | **Sama ścieżka z naszego serwisu**, bez domeny, bez parametrów zapytania i bez fragmentu. `PageContext::clean()` z adresu trasy niosącej sekret (lista NAZW tras w `PageContext::SENSITIVE_ROUTES`: reset hasła, link logowania, zaproszenie, linki `signed`) zapisuje tylko stałą część ścieżki, np. `/nowe-haslo`. Wpisy sprzed poprawki czyści `php artisan kuking:oczysc-kontekst-kontaktu` — domyślnie podgląd, zapis dopiero z `--wykonaj`; nie wypisuje wartości i niczego nie kasuje. Kontroler oczyszcza przed walidacją (ochrona sesji), a akcja domenowa ponawia ochronę przed zapisem. Obca domena, parametry, fragmenty i niejednoznaczne ścieżki nie trafiają do bazy (#836). |
| `wydanie` | `App\Support\Wersja::opisWydania()` w chwili wysłania. Nie jest daną osobową — to numer naszej wersji, i przy „u mnie nie działa" połowa diagnozy. |
| `status` | `new` \| `in_progress` \| `done`, CHECK `contact_messages_status_check`. **Nie ma go w `$fillable`** — ta sama zasada, co dla `status` i `role` użytkownika (AGENTS.md §7). Jedyna droga zmiany: `ContactMessage::oznaczJako()`. |
| `handled_by`, `handled_at` | Kto i kiedy. CHECK `contact_messages_handled_complete` wymusza: status `new` MUSI mieć `num_nonnulls(handled_by, handled_at) = 0`, a status inny niż `new` MUSI mieć `handled_at IS NOT NULL`. `handled_by` ma `nullOnDelete()` — usunięcie konta operatora zeruje tę kolumnę i nie wywraca bazy (poprawka w `2026_09_24_100000_allow_null_handled_by_on_contact_messages`, #844). `handled_at` pozostaje nienaruszone, bo od niego liczy się retencja. |
| `handler_note` | Notatka operatora, widoczna wyłącznie w panelu. |
| `version` | Licznik wersji notatki i stanu, `bigint NOT NULL DEFAULT 0`, poza `$fillable`. Migracja `2026_09_24_110000_add_contact_message_version` (#846). `UpdateContactMessage` porównuje wersję formularza pod blokadą wiersza, a notatkę, stan i licznik zapisuje atomowo. Sama edycja notatki nie zmienia `handled_at` ani `handled_by` (#843). |

**Wycofanie licznika wersji:** usunięcie kolumny nie zmienia treści ani dat.
Na czas rollbacku wyłącz zapis w panelu i unieważnij otwarte sesje/formularze;
ponowne wdrożenie zaczyna licznik od zera i nie może przyjąć starej karty.

Indeksy: `contact_messages_status_created_idx (status, created_at, id)` —
kolejka; `contact_messages_handled_at_idx (handled_at) WHERE handled_at IS
NOT NULL` — nocna retencja.

**Wycofanie poprawki #844:** migracja
`2026_09_24_100000_allow_null_handled_by_on_contact_messages` przywraca stary
CHECK tylko wtedy, gdy nie ma obsłużonych wiadomości bez operatora.
W przeciwnym razie odmawia; pozostaw migrację i wycofaj sam kod aplikacji.
Nie usuwaj historii i nie przypisuj przypadkowego operatora dla rollbacku.
Decyzja właściciela z 20 września 2026: po fizycznym usunięciu operatora
zachowujemy wiadomość i datę, usuwamy tylko powiązanie z kontem.

**Retencja:** `config('kuking.kontakt.retention_months')` (domyślnie 12)
miesięcy od `handled_at`, komenda `kuking:sprzataj-wiadomosci`, harmonogram
04:40 (`routes/console.php`). Wiadomość **otwarta nie jest kasowana nigdy**,
niezależnie od wieku — ta sama zasada, co przy otwartych sprawach
moderacyjnych: kasowanie tego, czego nikt nie przeczytał, byłoby sprzątaniem
dowodu zaniedbania, nie ochroną danych. Próg liczy
`subMonthsNoOverflow()`, nie `subMonths()` (A6-04).

**Rollback:** `DROP TABLE contact_messages` — migracja nie rusza żadnej
innej tabeli, więc cofnięcie nie może uszkodzić niczego poza tym, co samo
utworzyło (sprawdza to `CofniecieMigracjiWiadomosciTest`). Znika formularz
i ekran w panelu, zostaje adres e-mail w stopce, czyli stan sprzed zmiany.
**Strata jest jednak NIEODWRACALNA** — w tabeli leżą zdania napisane przez
ludzi. Przed cofnięciem na czymkolwiek z prawdziwym ruchem:
`pg_dump --data-only --table=contact_messages > wiadomosci.sql`.

### contact_message_replies

Odpowiedzi operatora na wiadomości z „Napisz do nas", migracja
`2026_09_10_200000_create_contact_message_replies_table` (**D-058**).
Do 10 września 2026 panel miał tu wyłącznie odnośnik `mailto:` — odpisywało
się z własnego programu poczty, a w serwisie nie zostawał żaden ślad, że
odpowiedź poszła.

**Osobna tabela, nie trzy kolumny w `contact_messages`**, z dwóch powodów.
Pierwszy: odpowiedź nie jest jedna („sprawdzamy" dziś, „naprawione" w piątek),
a kolumna na wierszu wiadomości kazałaby drugą odpowiedź albo nadpisać, albo
uniemożliwić. Drugi, ważniejszy: **każdy list ma własny stan wysyłki** —
pierwszy mógł nie wyjść, drugi wyjść, i jedna kolumna nie ma jak tego
opowiedzieć.

| Kolumna | Uwagi |
|---|---|
| `id` | UUID, `gen_random_uuid()`. |
| `contact_message_id` | **`ON DELETE CASCADE`** i to jest wymóg RODO, nie wygoda: retencja (`kuking:sprzataj-wiadomosci`) robi masowy `DELETE` na `contact_messages`, omijając modele. Bez kaskady W BAZIE odpowiedzi zostałyby sierotami, których nic już nigdy nie usunie. |
| `author_id` | Moderator, który wysłał. `nullOnDelete()` — konto może zniknąć, fakt wysłania zostaje (ekran pokazuje wtedy „obsługa Kuking"). |
| `body` | Treść listu, dokładnie ta, którą dostał człowiek. `text`; górną granicę (5000 znaków, tyle samo co wiadomość) trzyma walidacja, w bazie stoi CHECK `contact_message_replies_body_not_blank`. |
| `status` | `w_toku` \| `wyslana` \| `nieudana`, CHECK `contact_message_replies_status_check`. **Nie ma go w `$fillable`** — ustawia go wyłącznie `App\Domain\Contact\Actions\WyslijOdpowiedz`, po tym jak dostawca poczty coś powiedział. `w_toku` zapisujemy PRZED wysyłką, żeby przerwanie procesu zostawiło „nie wiadomo, czy wyszło", a nie ciszę. |
| `sent_at` | Kiedy dostawca potwierdził przyjęcie. CHECK `contact_message_replies_sent_complete` wiąże to ze stanem: `wyslana` MUSI mieć `sent_at`, każdy inny stan NIE MOŻE go mieć. |
| `error` | Powód odmowy, przepuszczony przez redakcję adresów (`WyslijOdpowiedz::bezpiecznyPowod()` — ta sama lekcja co audyt A6-01). Ma odpowiadać moderatorowi na pytanie „co teraz zrobić", nie przechowywać cudzych danych. |
| `reply_key` | UUID jednego wysłania odpowiedzi. `UNIQUE (contact_message_id, reply_key)`; `NULL` tylko w historycznych wierszach. Poza `$fillable`, zapis przez akcję domenową. Powtórzony klucz odczytuje istniejący wynik; zmieniona treść lub autor z tym samym kluczem są odrzucani. |
| `sending_started_at` | Nullable `timestamptz`, atomowa rezerwacja przez `UPDATE ... WHERE sending_started_at IS NULL`, zatwierdzona przed pocztą. Ustawiony znacznik wyklucza ponowną wysyłkę tego formularza, również przy niepewnym wyniku. |
| `audit_recorded_at` | Nullable `timestamptz`; znacznik i wpis `audit_log` powstają w jednej transakcji. Brak znacznika po awarii pozwala dokończyć audyt na ponowionym POST lub po wejściu na kartę. Retencja audytu nie zeruje znacznika. |

Znaczniki dodaje `2026_09_24_120000_add_contact_reply_delivery_markers` (#839).
Historyczne odpowiedzi dostają oba znaczniki z `created_at`; nie wysyłamy ich
ani nie tworzymy ponownie dawnych wpisów audytu. `down()` odmawia, jeśli jest
choć jeden niepusty `reply_key`, bo jego utrata umożliwiłaby duplikaty.
Bez nowych kluczy rollback usuwa wyłącznie nowe kolumny i indeks. Przy danych
zachowaj migrację i ochronę ponowień; nie kasuj kluczy dla wymuszenia rollbacku.

Po potwierdzonej odmowie poczty stan to `nieudana`. Zerwane połączenie albo
brak jednoznacznej odpowiedzi zachowuje `w_toku`, z ograniczonym,
zredagowanym powodem (#840). `OdmowaEmailLabs::isConfirmedRejection()` niesie
pewność odmowy oddzielnie od kategorii awarii; pozostałych wyjątków nie
traktujemy jako dowodu niewysłania. Nowa świadoma próba ma nowy klucz;
przy niepewności operator najpierw sprawdza dostawcę. Nie jest to gwarancja
dokładnie jednego doręczenia przez zewnętrzną pocztę.

**Czego tu świadomie NIE MA: adresu, na który list poszedł.** Adres jest już
w bazie raz — `contact_messages.contact_email` (gość) albo `users.email`
(konto) — i wskazuje go `ContactMessage::adresDoOdpowiedzi()`. Trzecia kopia
tej samej danej osobowej przeżywałaby anonimizację konta i zamieniłaby wiersz
techniczny w mały zbiór adresów e-mail. Ta sama minimalizacja, dla której
`contact_email` jest `NULL` u zalogowanego.

Indeks: `contact_message_replies_message_idx (contact_message_id, created_at,
id)` — historia jednej sprawy, najstarsza odpowiedź na górze.

**Retencja:** własnej nie ma i nie potrzebuje. Odpowiedzi znikają razem
z wiadomością (kaskada wyżej), czyli 12 miesięcy od jej załatwienia —
pilnuje tego `OdpowiedzNaWiadomoscDoNasTest::test_odpowiedzi_znikaja_razem_z_wiadomoscia_przy_retencji`.

**Rollback:** `DROP TABLE contact_message_replies` — nie rusza żadnej innej
tabeli. **Kolejność ma znaczenie:** `contact_messages` nie da się cofnąć,
dopóki ta tabela stoi (PostgreSQL odmawia `DROP TABLE` z zależnym kluczem
obcym, `SQLSTATE 2BP01`), więc rollback idzie od najnowszej migracji —
tak jak `php artisan migrate:rollback`. Po cofnięciu znika formularz
odpowiedzi w panelu i wraca stan sprzed zmiany (`mailto:` na karcie
wiadomości); same wiadomości zostają nietknięte. **Strata jest jednak
NIEODWRACALNA** — w tabeli leżą listy, które naprawdę poszły do ludzi.
Przed cofnięciem na czymkolwiek z prawdziwym ruchem:
`pg_dump --data-only --table=contact_message_replies > odpowiedzi.sql`.
