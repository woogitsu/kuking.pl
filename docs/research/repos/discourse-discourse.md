# discourse/discourse — notatka researchowa

**Licencja: GPL-2.0** (`LICENSE`). Copyleft — kodu nie kopiujemy. Bierzemy to,
co w tym repozytorium jest najcenniejsze i czego licencja nie obejmuje:
**listę problemów moderacyjnych, które ktoś już przeżył**. Discourse ma
kilkanaście lat pracy z realnym spamem i realnymi konfliktami; ich
`config/site_settings.yml` czyta się jak spis incydentów.

Snapshot: `git clone --depth 1` z 2026-09-05.

> **Filtr priorytetów: D-012 (zamknięta alfa, ~20 osób).**
> Wszystko, co dotyczy **skali** moderacji — poziomy zaufania, automatyczne
> progi wyciszania, ważenie zgłoszeń, przejmowanie spraw przez moderatorów —
> jest oznaczone `LATER`. Przy 20 osobach moderacja to jedna osoba i kolejka,
> którą już mamy. Wyżej wyceniam to, co dotyczy **jakości pierwszego kontaktu**
> i przypadków brzegowych, bo tego nie da się nadrobić później: zła decyzja
> o kształcie danych albo o tonie komunikatu zostaje na lata.

---

## 1. Co wynika z licencji

- GPL-2.0: zero kodu w Kuking.
- Wartości domyślne ustawień (np. „nowy użytkownik: najwyżej 2 linki”) to
  fakty o zachowaniu spamerów, nie utwór. Przenosimy je jako **liczby
  do dyskusji**, weryfikowane na naszym ruchu.

## 2. Użyteczny model danych

### 2.1 Wyciszenie i zawieszenie jako **znaczniki czasu**, nie flagi

`db/migrate/20171113175414_add_silenced_till_to_users.rb`,
`app/models/user.rb:308-312`:

```
users.silenced_till   timestamp NULL
users.suspended_till  timestamp NULL
scope :silenced   -> silenced_till IS NOT NULL AND silenced_till > now()
scope :suspended  -> suspended_till IS NOT NULL AND suspended_till > now()
```

Kara wygasa sama, bo stan **jest** datą, a nie flagą, którą trzeba odklikać.
Blokada „na zawsze” to data w roku 3018 — jeden mechanizm zamiast dwóch.

To jest **trzecie niezależne potwierdzenie** rekomendacji, którą zapisałem
przy Fresns (`user_roles.expired_at` + `restore_role_id`): nasze
`users.status` z CHECK-iem `('active','suspended','banned','pending_delete')`
nie ma żadnego terminu, a `docs/legal/MODERATION_PLAYBOOK.md:34` przewiduje
blokady 7- i 30-dniowe. Przy jednym moderatorze (D-012) ręczne odklikiwanie
kar po tygodniu **na pewno** się nie wydarzy.

### 2.2 Wyciszenie ≠ zawieszenie

Rozróżnienie, którego u nas nie ma nazwanego:

| Stan u nich | Znaczenie | Nasz odpowiednik |
|---|---|---|
| `silenced` | czyta, nie może pisać | brak — `suspended` nie mówi, czego dotyczy |
| `suspended` | nie może się zalogować | `users.status = 'suspended'` |
| usunięcie konta | — | `pending_delete` |

Dla naszej grupy to jest istotne produktowo: wyciszenie na 7 dni z jasnym
powodem jest karą **naprawczą**, a odcięcie logowania — karą **końcową**.
Odbierając dostęp do własnego zeszytu z przepisami babci za ostry komentarz,
tracimy tę osobę na zawsze. Dziś `LoginController.php:65` blokuje logowanie
przy każdym statusie innym niż `active`, więc de facto mamy tylko karę
końcową.

### 2.3 `reviewables` — kolejka jako osobna encja od zgłoszenia

`db/migrate/20190103160533_create_reviewables.rb`:

```
reviewables: type, status, created_by_id,
             reviewable_by_moderator bool, reviewable_by_group_id,
             claimed_by_id,            -- żeby dwie osoby nie robiły tego samego
             score float, potential_spam bool,
             target_id, target_type, target_created_by_id,
             payload json
reviewable_scores:    reviewable_id, user_id, reviewable_score_type,
                      status, score, take_action_bonus,
                      reviewed_by_id, reviewed_at
reviewable_histories: reviewable_id, reviewable_history_type, status,
                      created_by_id, edited json
```

Kluczowy podział: **`reviewable` to sprawa, `reviewable_score` to pojedyncze
zgłoszenie.** Pięć osób zgłaszających ten sam wpis daje jedną sprawę
i pięć wpisów punktowych — moderator widzi jedną pozycję w kolejce, a nie pięć.

U nas `reports` łączy oba pojęcia: pięć zgłoszeń tego samego wpisu to pięć
wierszy ze statusem `open` (deduplikacja w
`app/Domain/Moderation/Actions/ReportContent.php:55-64` działa tylko **per
zgłaszający**). Przy 20 osobach to nie boli; przy 200 kolejka zaczyna się
zapychać duplikatami. **LATER**, ale warto wiedzieć, że rozwiązaniem nie jest
mocniejsza deduplikacja, tylko rozdzielenie „sprawy” od „zgłoszenia”.

`target_created_by_id` zdenormalizowany na sprawie — to samo, co zauważyłem
przy Pixelfedzie (`reported_profile_id`): pozwala odpowiedzieć „ile spraw
dotyczy tego konta” bez rozwiązywania polimorfizmu. **P2, niezależnie od skali**
— bo pytanie „czy ta osoba miała już sprawy” zadaje się przy **każdej**
decyzji, także pierwszej.

`claimed_by_id` — przejęcie sprawy. **LATER** (D-012: jeden moderator).

### 2.4 Listy odsiewowe, które liczą trafienia

`db/migrate/20130813204212_create_screened_urls.rb`,
`20131017205954_create_screened_ip_addresses.rb`, `screened_emails`:

```
screened_urls:        url UNIQUE, domain, action_type,
                      match_count, last_match_at
screened_ip_addresses: ip_address INET UNIQUE, action_type,
                      match_count, last_match_at
```

Dwie rzeczy warte przeniesienia niezależnie od skali:

1. **`match_count` i `last_match_at` przy każdej regule.** Po roku widać,
   które wpisy realnie coś łapią, a które są martwe. Lista blokad bez
   liczników rośnie w nieskończoność, bo nikt nie wie, co wolno usunąć.
   To jest ta sama myśl, co „komentuj indeks zapytaniem” (notatka o Fresns, R6).
2. **Typ `inet` dla adresu IP**, nie `varchar`. PostgreSQL potrafi wtedy
   sprawdzić przynależność do podsieci (`<<`), więc „zablokuj całą podsieć”
   to warunek, a nie pętla po tekstach. U nas ten problem wygląda inaczej —
   `audit_log.ip_hash` przechowuje **skrót** adresu
   (`database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php`),
   co jest dobrą decyzją prywatnościową, ale **z hasha nie da się policzyć
   podsieci**. Gdybyśmy kiedyś potrzebowali blokad sieciowych, będzie to
   wymagało osobnego, świadomego wyjątku od zasady „nie logujemy PII” —
   a nie jest to sprzeczność, tylko decyzja do podjęcia z otwartymi oczami.

### 2.5 `user_history` — log obejmujący także **oglądanie** danych

`app/models/user_history.rb:41-112`. Poza oczekiwanym `suspend_user`,
`silence_user`, `delete_user` są tam wpisy:

- **`check_email` (16)** — moderator **zobaczył** adres e-mail użytkownika;
- **`impersonate` (19)** — wszedł na konto jako ten użytkownik;
- `change_site_setting`, `removed_suspend_user` (cofnięcie kary), itd.

Logowanie **odczytu** danych osobowych, nie tylko ich zmiany, to wzorzec,
którego sami byśmy nie wprowadzili, a który jest wprost po stronie RODO:
przy pytaniu „kto oglądał moje dane” odpowiedź „nie wiemy” jest zła.
Nasz `audit_log` ma `action`, `subject_type`, `subject_id`, `metadata` —
czyli **schemat już to unosi**, brakuje samych wywołań w panelu.
To jest tanie teraz i drogie później: zdarzenia nieodnotowane nie dają się
odtworzyć wstecz.

## 3. Przepływy UX warte adaptacji

1. **Zgłaszający dowiaduje się, co się stało z jego zgłoszeniem**
   (`auto_respond_to_flag_actions: true`, `config/site_settings.yml:3388`).
   Bez tego zgłaszanie wygląda jak wrzucanie kartek do zamkniętej skrzynki
   i ludzie przestają zgłaszać — a przy 20 osobach w alfie to jedyne źródło
   sygnału. Dodatkowo DSA art. 16 ust. 5 wymaga poinformowania zgłaszającego
   o decyzji (`docs/legal/COMPLIANCE.md`). U nas `reports` ma
   `resolution_note` i `resolved_at`, ale **nie ma powiadomienia do
   zgłaszającego** — `App\Models\Notification::TYPE_*` warto o to rozszerzyć.
2. **Karencja przed ponownym zgłoszeniem tej samej rzeczy**
   (`cooldown_hours_until_reflag: 24`). Nasza deduplikacja obejmuje tylko
   sprawy otwarte, więc po odrzuceniu zgłoszenia ta sama osoba może zgłosić
   ponownie natychmiast. To jest gotowy kanał nękania moderatora **i** autora.
3. **Powód decyzji pisany do użytkownika osobno od notatki wewnętrznej.**
   `reviewable_notes` (notatka moderatora) obok komunikatu dla użytkownika.
   **U nas to już jest**: `moderation_actions.note` (wewnętrzna) i
   `moderation_actions.user_message` (dla użytkownika)
   (`database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php`).
   Zrealizowane i dobrze rozdzielone.
4. **Wyciszenie zamiast usunięcia treści przy pierwszym przewinieniu.**
   Zgodne z naszym `docs/legal/MODERATION_PLAYBOOK.md:39` („najpierw ukryć,
   dać szansę poprawy”). Potwierdzenie kierunku, nie nowa wiedza.

## 4. Przypadki brzegowe bezpieczeństwa i moderacji

Najcenniejsza sekcja tej notatki. To są rzeczy, których nie wymyśliliśmy,
bo nie prowadziliśmy jeszcze serwisu ze spamem.

1. **Czas pisania pierwszego wpisu** (`min_first_post_typing_time: 3000`,
   `auto_silence_fast_typers_on_first_post: true`). Bot wkleja treść
   w zero milisekund; człowiek pisze kilka sekund. Sygnał kosztuje jedno pole
   ukryte w formularzu i **nie wymaga captcha**, która dla osób 50+ jest
   realną barierą wejścia (`docs/UX_50_PLUS.md`).
   **Adaptacja obowiązkowa dla nas**: sygnał może **tylko oznaczać do
   przeglądu**, nigdy blokować — bo nasz użytkownik legalnie wkleja przepis
   z notatnika albo z maila od siostry, i wtedy czas pisania też jest zerowy.
2. **Ta sama treść wysłana do wielu miejsc** (`newuser_spam_host_threshold: 3`
   — nowy użytkownik linkujący trzy razy do tej samej domeny).
   Nasz odpowiednik: ta sama treść wpisu albo ten sam adres w kilku
   komentarzach w krótkim czasie. Mamy `media.checksum_sha256`
   i `media.perceptual_hash`, czyli po stronie zdjęć narzędzie jest —
   po stronie tekstu nie ma nic.
3. **Adresy e-mail podobne do znanego spamera**
   (`levenshtein_distance_spammer_emails: 2`). `jan.kowalski1@`, `2@`, `3@`
   to standardowy wzorzec zakładania kont seryjnych.
4. **Limit kont na adres IP przy rejestracji**
   (`max_new_accounts_per_registration_ip: 3`).
   **Ostrożnie u nas**: w naszej grupie mąż i żona rejestrują się z jednego
   łącza, a na wsi cała okolica bywa za jednym CGNAT-em (patrz też lista
   zakresów w notatce o Mealie). Ten limit powinien **oznaczać do przeglądu**,
   nigdy odrzucać rejestrację.
5. **Wykrywanie „drugiego konta tej samej osoby”**
   (`SpamRule::FlagSockpuppets`, `app/services/spam_rule/flag_sockpuppets.rb`):
   nowy użytkownik odpowiada na własny wątek z drugiego konta o tym samym IP.
   Ta sama uwaga o fałszywych trafieniach co wyżej — u nas dwie osoby z jednego
   domu komentujące ten sam przepis to normalne życie, nie oszustwo.
   Wart odnotowania jest za to szczegół implementacyjny: reguła **nie flaguje
   ponownie**, jeśli wcześniejsze zgłoszenie wobec tego konta zostało
   **odrzucone** (`flag_post`, warunek `ReviewableFlaggedPost.rejected`).
   Bez tego automat kłóci się z moderatorem w kółko.
6. **Wpisy dziennika o cofnięciu kary** (`removed_suspend_user`,
   `removed_silence_user`, `user_history.rb:109-112`) — historia moderacji
   musi zapisywać także **wycofanie się z decyzji**. Log, w którym są tylko
   kary, wygląda jak akt oskarżenia i nie pozwala pokazać, że pomyłka została
   naprawiona. Nasz `moderation_actions.action` powinien mieć wartości
   „cofnięcia” od pierwszego dnia.
7. **Automat nigdy nie decyduje sam.** Wszystkie reguły spamowe kończą się
   utworzeniem sprawy w kolejce albo wyciszeniem (odwracalnym), nie usunięciem
   treści ani banem. Zbieżne z `AGENTS.md` sekcja 9 — **potwierdzenie decyzji,
   nie nowa wiedza**, ale potwierdzenie z najbardziej doświadczonego źródła.

## 5. Wzorce testowe i jakościowe

- **Ustawienia jako jeden plik z wartościami domyślnymi**
  (`config/site_settings.yml`, tysiące linii z `default`, `min`, `max`,
  `type: enum`). Odpowiednik naszego `config/kuking.php` — i potwierdzenie
  reguły z `AGENTS.md` sekcja 7, że progi mają być w jednym miejscu.
  Czego **nie** kopiować: u nich każda z tych wartości jest edytowalna
  w panelu i zapisana w bazie, co jest tym samym problemem, co
  `configs` w Fresns (nietestowalna reguła). U nas próg zmienia się
  w repozytorium, z testem i historią w gicie.
- **Nazwane typy w historii** (`user_history.rb`: `delete_user: 1`,
  `suspend_user: 10`, …) z **utrwalonymi liczbami** — nie da się przenumerować,
  bo w bazie leżą stare wpisy. U nas odpowiednikiem są stringi w
  `moderation_actions.action`, co jest odporniejsze i czytelne w `psql`.
  **Nasza wersja jest lepsza.**
- Ich testy systemowe i „smoke” są poza zakresem tej notatki
  (Ruby/Rails, inny ekosystem), ale w kontekście **D-010** (własny runner)
  warto odnotować sam fakt: projekt tej wielkości utrzymuje się wyłącznie
  dlatego, że każda reguła moderacyjna ma test. Reguła bez testu, przy
  jednym moderatorze i agentach AI zmieniających kod, zniknie po trzech
  miesiącach niezauważona.

## 6. Wzorce wydajnościowe

Discourse jest zoptymalizowany pod skalę, której nie mamy i mieć nie będziemy
w alfie. Dwie rzeczy warte odnotowania:

- **Komentarz w migracji: „na niektórych serwisach z dużym ruchem chcą,
  żeby sprawy dało się przejąć”** (`create_reviewables.rb:14-15`) — kolumna
  dodana **z powodu**, opisanym w migracji. Dokładnie ten styl komentarza
  mają nasze migracje (np. `media`, `cooked_events`) i warto go trzymać.
- **`score` jako liczba zmiennoprzecinkowa na sprawie** pozwala sortować
  kolejkę bez liczenia agregatu. Dla nas `LATER` — przy 20 osobach kolejka
  sortuje się po dacie.

## 7. Czego świadomie nie przenosić

| Rzecz | Dlaczego nie |
|---|---|
| **Poziomy zaufania (TL0–TL4)** i uprawnienia od nich zależne | **D-012**: przy 20 osobach to mechanika bez treści. Wprowadzone za wcześnie tworzy warstwy w społeczności, których nikt nie prosił, i jest blisko rankingu użytkowników zakazanego w `AGENTS.md` sekcja 12 |
| **Automatyczne wyciszanie po N zgłoszeniach** (`num_users_to_silence_new_user: 3`) | Przy 20 osobach trzy zgłoszenia to 15% społeczności — narzędzie do wykluczania kogoś przez trzech znajomych. Ma sens dopiero przy tysiącach kont |
| **Automatyczne zamykanie tematu przez zgłoszenia** (`num_flaggers_to_close_topic`) | Jak wyżej |
| **Ważenie zgłoszeń zaufaniem zgłaszającego** (`reviewable_scores.score`, `take_action_bonus`) | Wymaga poziomów zaufania; `LATER` |
| **Przejmowanie spraw** (`claimed_by_id`) | Jeden moderator (**D-012**) |
| **Ustawienia edytowalne w panelu i zapisane w bazie** | Reguła w bazie jest nietestowalna; progi zostają w `config/kuking.php` |
| **Blokada rejestracji po IP** jako twarda odmowa | Współdzielone łącza w gospodarstwie domowym i CGNAT; u nas najwyżej oznaczenie do przeglądu |
| **Captcha** | Bariera wejścia dla 50+; zamiast tego sygnały pasywne (czas pisania, powtórzenia treści) |
| **`impersonate`** (wejście na konto użytkownika) | Nie potrzebujemy i nie chcemy tej możliwości — brak funkcji jest lepszy niż funkcja z logiem |
| Cały model kategorii, grup, wiadomości prywatnych, tematów | Kuking to nie forum |

## 8. Rekomendacje dla Kuking

| # | Rekomendacja | Nasz plik / tabela | Waga |
|---|---|---|---|
| R1 | `users.status_expires_at` — kara wygasa sama (trzecie potwierdzenie: Fresns `expired_at`, Discourse `suspended_till`, nasz playbook) | `database/migrations/0001_01_01_000001_create_users_table.php`, `routes/console.php`, `docs/legal/MODERATION_PLAYBOOK.md:34` | **P1 — przy jednym moderatorze (D-012) ręczne odklikiwanie kar się nie wydarzy** |
| R2 | Rozróżnić wyciszenie (czyta, nie pisze) od zawieszenia (nie loguje się): rozszerzyć CHECK `users.status` o `silenced` i przepuścić ten stan w `LoginController` | `app/Http/Controllers/Auth/LoginController.php:65`, CHECK w migracji `users`, policies z `isActive()` | P1 — dziś mamy tylko karę końcową |
| R3 | Powiadomienie do **zgłaszającego** o wyniku zgłoszenia (DSA art. 16 ust. 5) | `app/Models/Notification.php`, `app/Http/Controllers/Admin/ModerationController.php`, `reports.resolution_note` | P1 — w alfie zgłoszenia są jedynym sygnałem, a bez odpowiedzi wygasają |
| R4 | Karencja przed ponownym zgłoszeniem tej samej treści po odrzuceniu sprawy | `app/Domain/Moderation/Actions/ReportContent.php:55-64` | P1 — dziś kanał nękania |
| R5 | Akcje „cofnięcia decyzji” w `moderation_actions.action` od pierwszego dnia | `database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php`, `docs/MODERATION.md` | P1 — tanie teraz, niemożliwe do odtworzenia wstecz |
| R6 | Wpisy w `audit_log` przy **oglądaniu** danych osobowych przez moderatora (podgląd e-maila, historii konta), nie tylko przy zmianie | `app/Http/Controllers/Admin/ModerationController.php`, `app/Models/AuditLogEntry.php` (schemat już to unosi) | P1 — RODO; zdarzeń nieodnotowanych nie da się odtworzyć |
| R7 | `reports.target_author_id` zdenormalizowany — „czy ta osoba miała już sprawy” bez rozwiązywania polimorfizmu | `database/migrations/2026_09_05_001000_create_trust_and_safety_tables.php` | P2 — pytanie zadawane przy każdej decyzji, także pierwszej |
| R8 | Ukryte pole z czasem wypełniania formularza pierwszego wpisu → **oznaczenie do przeglądu**, nigdy blokada | `app/Http/Controllers/PostController.php`, `app/Domain/Moderation/` | P2 — tańsze i mniej wykluczające niż captcha |
| R9 | Liczniki trafień (`match_count`, `last_match_at`) przy każdej przyszłej liście blokad (domeny, hashe zdjęć z rekomendacji R6 z notatki o Pixelfedzie) | przyszłe `media_blocklists`, `screened_domains` | P2 |
| R10 | Wykrywanie powtórzonej treści tekstowej (ten sam wpis/komentarz kilka razy w krótkim czasie) | `app/Domain/Posts/Actions/PublishPost.php`, `app/Domain/Comments/Actions/PublishComment.php` | P2 |
| R11 | Rozdzielenie „sprawy” od „zgłoszenia” (jedna pozycja w kolejce, wiele zgłoszeń) | `reports`, `docs/MODERATION.md` | **LATER** — przy 20 osobach kolejka się nie zapycha (D-012) |
| R12 | Poziomy zaufania, ważenie zgłoszeń, automatyczne progi, przejmowanie spraw | — | **LATER / REJECT na dziś** — patrz sekcja 7 |

### Uwaga o kolejności

Gdyby trzeba było wybrać z tej notatki trzy rzeczy do zrobienia przed
pierwszym zaproszeniem do alfy, byłyby to: **R1** (kara wygasa sama),
**R3** (zgłaszający dostaje odpowiedź) i **R6** (log oglądania danych).
Wszystkie trzy są tanie, wszystkie trzy są niemożliwe do odtworzenia wstecz,
i wszystkie trzy dotyczą zaufania, a nie skali — czyli dokładnie tego,
co przy 20 osobach decyduje o wszystkim.
