# Decyzje z researchu publicznych repozytoriów

Wynik issue #19. Podstawą jest siedem notatek w `docs/research/repos/`,
powstałych z lektury kodu, migracji i testów siedmiu publicznych projektów.

**Ten plik jest listą decyzji, nie listą pomysłów.** Każda pozycja ma
znacznik, jedno zdanie uzasadnienia i odesłanie do notatki, w której jest
pełna analiza z odwołaniami do plików i linii.

## Jak czytać znaczniki

| Znacznik | Znaczenie |
|---|---|
| **ADOPT** | Bierzemy tak, jak jest — wzorzec pasuje do Kuking bez zmian |
| **ADAPT** | Bierzemy pomysł, ale w innej postaci niż w źródle; różnica jest opisana |
| **REJECT** | Świadomie nie bierzemy; powód jest zapisany, żeby nie wracać do tematu |
| **LATER** | Dobre, ale nie teraz — z warunkiem, kiedy wrócić |

## Zasada licencyjna (nienegocjowalna)

Z **Pixelfed (AGPL-3.0)**, **Tandoor (AGPL-3.0 + Commons Clause)**,
**Mealie (AGPL-3.0)**, **Discourse (GPL-2.0)** i **Recipya (GPL-3.0)**
**nie wzięliśmy ani jednej linii kodu** i nie wolno jej brać. Przenosimy
wyłącznie modele danych, przepływy, przypadki brzegowe i wnioski —
kształt tabeli i fakt „najpierw zastosuj orientację EXIF, potem re-enkoduj”
nie są utworem.

**Laravel.io (MIT)**, **Fresns (Apache-2.0)** i **Filament (MIT)** pozwalają na
kod; gdybyśmy cokolwiek przenieśli dosłownie, dopisujemy notę licencyjną
w sekcji „Third-party notices”.

**Sprostowanie:** `docs/research/PUBLIC_REPOS.md` podaje dla Tandoora samo
AGPL-3.0. W repozytorium jest **AGPL-3.0 + „Commons Clause” v1.0**, która
odbiera prawo do sprzedaży i płatnego hostingu opartego na tym oprogramowaniu
— różnica istotna wobec `docs/MONETIZATION.md`.

---

## 1. Bezpieczeństwo i prywatność — najpilniejsze

Cztery pozycje z tej sekcji to **realne błędy znalezione w naszym kodzie**,
nie propozycje funkcji. Zgodnie z zakresem issue #19 nie zostały naprawione;
każda ma opisane kryteria akceptacji w notatce źródłowej.

| # | Decyzja | Znacznik | Uzasadnienie | Notatka |
|---|---|---|---|---|
| 1.1 | Zastosować orientację EXIF przed re-enkodowaniem zdjęcia | **ADOPT** | Sterownik GD nie czyta EXIF-u, więc dziś zdjęcie z telefonu trzymanego pionowo publikuje się obrócone o 90°, a użytkownik 50+ tego nie zgłosi — po prostu przestanie wrzucać. | `pixelfed-pixelfed.md` §4.1, §8 R1 |
| 1.2 | Usunąć z `Media::url()` fallback na `object_key` | **ADOPT** | Przy braku wariantu w `metadata` serwujemy dziś surowy plik od użytkownika z GPS-em w EXIF, wbrew `docs/legal/SECURITY_BASELINE.md:118` i wbrew własnej deklaracji `exif_stripped => true`. | `pixelfed-pixelfed.md` §4.2, §8 R2 |
| 1.3 | Normalizować e-mail do małych liter (zapis + CHECK w bazie + wyszukiwanie) | **ADOPT** | PostgreSQL rozróżnia wielkość znaków, a klawiatury telefonów domyślnie kapitalizują pierwszą literę — konto założone jako `Jan@…` jest nie do zalogowania i nie do odzyskania. | `reaper47-recipya.md` §2.1, §8 R1 |
| 1.4 | Szukać nazwy użytkownika bez rozróżniania wielkości znaków | **ADOPT** | Ten sam mechanizm co 1.3, druga ścieżka logowania. | `reaper47-recipya.md` §8 R2 |
| 1.5 | Middleware odcinający konto `banned` przy każdym żądaniu | **ADOPT** | `User::ban()` zmienia status, ale nie unieważnia sesji — zbanowany spamer z otwartą kartą działa dalej. | `laravelio-laravel.io.md` §4.1, §8 R1 |
| 1.6 | Lista zastrzeżonych nazw użytkownika (`admin`, `moderator`, `kuking`, `pomoc`…) | **ADOPT** | Podszycie się pod obsługę serwisu to najskuteczniejszy phishing wobec osób starszych, a dziś CHECK sprawdza tylko format nazwy. | `pixelfed-pixelfed.md` §4.7, §8 R8 |
| 1.7 | Walidacja „bio i nazwa nie zawierają URL-a” | **ADOPT** | Jedna reguła zdejmuje najczęstszą formę spamu profilowego. | `laravelio-laravel.io.md` §4.4, §8 R6 |
| 1.8 | Sprawdzać identyfikatory z formularza (`unit_id`, `ingredient_id`, `media_id`) tak jak te z URL-a | **ADOPT** | „UUID w adresie nie jest autoryzacją” (`AGENTS.md` §7) dotyczy tak samo pól ukrytych w formularzu. | `mealie-recipes-mealie.md` §2.3, §4.7 |
| 1.9 | Pełna lista wymagań SSRF dla importera z URL, zapisana **przed** napisaniem kodu | **LATER (V2)** | Sprawdzenie nazwy zamiast rozwiązanego IP, pominięty zakres CGNAT, adres IPv4 zapisany jako IPv6 i przekierowanie na `127.0.0.1` to cztery dziury, których sami byśmy nie przewidzieli. | `mealie-recipes-mealie.md` §4.1-4.6 |
| 1.10 | Limit długości wejścia przed każdym wyrażeniem regularnym na tekście użytkownika | **ADOPT** | Tandoor ma w kodzie warunek `len < 1000` postawiony wprost przed regexem — ślad po realnym incydencie z ReDoS. | `TandoorRecipes-recipes.md` §4.1-4.2 |
| 1.11 | Captcha przy rejestracji | **ADAPT** | **Odwrócone w [D-050](decyzje/D-050-cloudflare-turnstile-na-szesciu-formularzach.md#d-050--cloudflare-turnstile-na-sześciu-formularzach-publicznych--warunek-wysłania-nie-filtr-brak-tokenu-odrzuca) (9 września 2026, decyzja właściciela, issue #217).** Pierwotny REJECT dotyczył captchy z obrazkami, na której osoba 65-letnia utyka; Turnstile w trybie Managed zwykle nie prosi o nic. Wchodzi na **sześć** formularzy publicznych — wszędzie tam, gdzie wchodzi ktoś niezalogowany (`/register`, `/login`, `/nie-pamietam-hasla`, `/cofnij-usuniecie-konta`, `/napisz-do-nas`, `/zglos-nielegalna-tresc`). Istota tamtego sprzeciwu zostaje w mocy, ale kształtuje decyzję inaczej, niż wyglądało to pierwszego dnia: brak tokenu **odrzuca wysłanie** (zaostrzenie z 9 września 2026 — właściciel uznał JavaScript za obowiązkowy w tych newralgicznych miejscach), więc obroną przed „bramką, przez którą można nie przejść" jest teraz `<noscript>` przy każdym formularzu, osobny komunikat dla niedociągniętego widgetu i adres e-mail jako droga wyjścia. Sygnały pasywne (poz. 3.6) zostają, Turnstile ich nie zastępuje. | `discourse-discourse.md` §7 · D-050 |
| 1.12 | Dane dokumentu tożsamości w bazie przy weryfikacji konta | **REJECT** | Fresns trzyma numer dokumentu obok e-maila i hasła; u nas moderator dokument ogląda, a w bazie zostaje wyłącznie `verified_at` i kto weryfikował. | `fresns-fresns.md` §4.6, §8 R10 |

## 2. Model danych

| # | Decyzja | Znacznik | Uzasadnienie | Notatka |
|---|---|---|---|---|
| 2.1 | Kolumny `*_normalized` + indeks `gin_trgm_ops` **na nich**; zapytania odpytują tylko te kolumny | **ADOPT** | Nasze trzy indeksy trigramowe stoją na surowych kolumnach, a `SearchQuery` odpytuje `unaccent(lower(...))`, więc PostgreSQL ich nie używa i każde wyszukiwanie to pełny skan. | `TandoorRecipes-recipes.md` §6, `mealie-recipes-mealie.md` §2.2 |
| 2.2 | `users.status_expires_at` — kara wygasa sama | **ADOPT** | Trzy niezależne projekty trzymają karę jako datę, a nasz playbook przewiduje blokady 7- i 30-dniowe, których przy jednym moderatorze (**D-012**) nikt nie odklika ręcznie. | `fresns-fresns.md` §2.1, `discourse-discourse.md` §2.1 |
| 2.3 | Rozdzielić wyciszenie (czyta, nie pisze) od zawieszenia (nie loguje się) | **ADAPT** | Bierzemy rozróżnienie Discourse, ale jako dodatkową wartość `users.status`, nie jako drugą kolumnę z datą — odebranie dostępu do własnego zeszytu za ostry komentarz traci tę osobę na zawsze. | `discourse-discourse.md` §2.2, §8 R2 |
| 2.4 | `recipe_ingredients.no_amount` | **ADOPT** | „Sól do smaku” i „nie udało się rozpoznać ilości” to dwa różne przypadki, a przy skalowaniu porcji pierwszego nie wolno pomnożyć. | `TandoorRecipes-recipes.md` §2.1 |
| 2.5 | `UNIQUE (collection_id, recipe_id)` na `collection_items` | **ADOPT** | `syncWithoutDetaching()` nie chroni przed dwoma równoległymi żądaniami, a `AGENTS.md` §6 wymaga constraintu w bazie, nie tylko w PHP. | `reaper47-recipya.md` §2.2, §8 R3 |
| 2.6 | `UNIQUE (post_id, media_id)` i `UNIQUE (owner_id, name)` na kolekcjach | **ADOPT** | Dziś to samo zdjęcie może wejść dwa razy do albumu, a dwa zeszyty mogą nazywać się tak samo. | `pixelfed-pixelfed.md` §2.6, `reaper47-recipya.md` §8 R4 |
| 2.7 | Tabela `moderation_notices` (uzasadnienie + `read_at` + odwołanie) | **ADOPT** | `docs/legal/MODERATION_PLAYBOOK.md:73-79` opisuje ścieżkę odwołania, a w bazie nie ma pod nią żadnej tabeli — art. 17 DSA jest obowiązkowy niezależnie od wielkości. | `pixelfed-pixelfed.md` §2.4, §8 R3 |
| 2.8 | `moderation_actions`: dopuścić decyzję automatu (`is_automated`) | **ADOPT** | DSA wymaga informacji, czy decyzja była zautomatyzowana, a nasze `moderator_id` jest `NOT NULL`. | `pixelfed-pixelfed.md` §2.3, §8 R4 |
| 2.9 | `reports.first_seen_at` (moment pierwszego kontaktu moderatora) | **ADOPT** | Bez tego nie da się odróżnić „zgłoszenie leżało trzy dni” od „moderator zobaczył je od razu, ale sprawa jest trudna”. | `pixelfed-pixelfed.md` §2.2, §8 R5 |
| 2.10 | `reports.target_author_id` zdenormalizowany | **ADOPT** | Pytanie „czy ta osoba miała już sprawy” zadaje się przy każdej decyzji, także pierwszej, a dziś wymaga rozwiązywania polimorfizmu joinami. | `discourse-discourse.md` §2.3, §8 R7 |
| 2.11 | `media_blocklists` (hash pliku usuniętego za naruszenie) | **ADOPT** | Mamy już `media.checksum_sha256` z indeksem, więc brakuje tylko tabeli i sprawdzenia przy wgraniu — najtańsza obrona przed spamerem wracającym z nowym kontem. | `pixelfed-pixelfed.md` §2.5, §8 R6 |
| 2.12 | Wyciszenie konta („Ukryj wpisy tej osoby”) obok blokady | **ADAPT** | Bierzemy rozróżnienie block/mute z Pixelfeda, ale bez ich polimorficznej tabeli — w małej społeczności blokada jest zbyt mocnym gestem społecznym. | `pixelfed-pixelfed.md` §2.1, §8 R7 |
| 2.13 | `profiles.comment_policy` (wszyscy / obserwowani / nikt) | **ADOPT** | Dwa niezależne projekty doszły do tego samego, a osoba 50+, której pierwszy wpis dostanie 40 komentarzy, częściej się wycofuje, niż cieszy. | `fresns-fresns.md` §2.3, `mealie-recipes-mealie.md` §2.4 |
| 2.14 | `ingredient_aliases` (synonimy jako wiersze, nie reguły) | **LATER (V1)** | Aliasy Mealie są czystsze niż konfigurowalne automatyzacje Tandoora, ale dopóki nie mamy przepisów, nie mamy czego scalać. | `mealie-recipes-mealie.md` §2.1 |
| 2.15 | `unit_conversions` z `ingredient_id` nullable | **LATER (V1)** | „Szklanka mąki” to nie to samo co „szklanka cukru”, a polskie przepisy rodzinne są pisane w szklankach — bez tego funkcja „przelicz na gramy” podaje złe liczby. | `TandoorRecipes-recipes.md` §2.3 |
| 2.16 | `recipe_steps.linked_recipe_id` (krok jako inny przepis) | **LATER (V1)** | Zakwas, beszamel i ciasto na pierogi są w polskiej kuchni osobnymi przepisami, ale trzeba najpierw rozstrzygnąć widoczność: krok nie może ujawniać treści przepisu prywatnego. | `TandoorRecipes-recipes.md` §2.5 |
| 2.17 | `ingredients.parent_id` (płaska hierarchia produktów) | **LATER (V1)** | „Przepisy z mąką” powinny znaleźć „mąkę krupczatkę”, ale bez maszynerii dziedziczenia pól, która u Tandoora zajmuje 130 linii. | `TandoorRecipes-recipes.md` §2.4 |
| 2.18 | `ingredient_substitutes` (zamienniki jako dane) | **LATER (V1)** | Zamiennik to skończona lista, którą można poprawić, a nie odpowiedź modelu językowego generowana za każdym razem (`AGENTS.md` §9). | `TandoorRecipes-recipes.md` §2.4, §8 R10 |
| 2.19 | `recipe_share_links` z **hashem** tokenu i terminem ważności | **ADAPT** | Bierzemy funkcję z Mealie, ale token trzymamy jako skrót (u nich leży jawnie), a termin ważności jest obowiązkowy (u Recipyi go nie ma i link zostaje w cudzej skrzynce na zawsze). | `mealie-recipes-mealie.md` §2.5, `reaper47-recipya.md` §2.4 |
| 2.20 | Limit częstotliwości zmiany nazwy + przekierowania ze starej nazwy profilu | **LATER (V1)** | Konto buduje zaufanie pod jedną nazwą i zmienia ją na podobną do cudzej; mamy już `recipe_slug_redirects` jako gotowy wzór dla profili. | `fresns-fresns.md` §2.5, §8 R5 |
| 2.21 | `users.banned_at` + powód bana jako kolumny | **LATER** | Ślad jest w `moderation_actions` z indeksem po celu; najpierw sprawdzić, czy zapytanie po nim nie wystarcza. | `laravelio-laravel.io.md` §8 R3 |
| 2.22 | Sklejenie „Ugotowałem”, komentarzy i zdarzeń systemowych w jedną tabelę `timeline_events` | **REJECT** | Nasze `cooked_events` mają własne pola i CHECK-i, a `comments` własny cykl moderacji — scalenie byłoby cofnięciem i osłabiłoby **D-005**. | `mealie-recipes-mealie.md` §2.6, §7 |
| 2.23 | Stany jako liczby (`digest_state = 2`, `users.type = 3`) | **REJECT** | Nasze CHECK-i ze stringami są czytelne w `psql`; ich wersja wymaga zaglądania do kodu, żeby wiedzieć, kto jest moderatorem. | `fresns-fresns.md` §7, `pixelfed-pixelfed.md` §7 |
| 2.24 | Wielodzierżawność (`space_id` / `group_id` w każdej tabeli) | **REJECT** | Kuking to jeden serwis; kolumna w każdym indeksie i każdym `UNIQUE` to koszt bez korzyści. | `TandoorRecipes-recipes.md` §7, `mealie-recipes-mealie.md` §7 |
| 2.25 | `dislike_count` i publiczne liczniki negatywne | **REJECT** | Publiczny licznik „nie lubię” przy przepisie rodzinnym to najkrótsza droga do wyłączenia publikowania (`AGENTS.md` §12). | `fresns-fresns.md` §7 |
| 2.26 | Ocena gwiazdkowa przepisu (`rating`) | **REJECT** | Naszym sygnałem jakości jest „Ugotowałem”, a nie średnia ocen (`AGENTS.md` §1). | `mealie-recipes-mealie.md` §7 |
| 2.27 | Wiele tożsamości na jedno konto | **REJECT** | Moderacyjny koszmar i funkcja niezrozumiała dla naszej grupy; sam rozdział `users`/`profiles` potwierdza za to naszą decyzję. | `fresns-fresns.md` §3.1, §7 |
| 2.28 | Definicje pól formularza w bazie (EAV: `archives`, `Automation`, `site_settings`) | **REJECT** | Trzy niezależne projekty robią to samo i w każdym kończy się to reguła nietestowalną — nasze progi zostają w `config/kuking.php`, z testem i historią w gicie (`AGENTS.md` §3). | `fresns-fresns.md` §7, `TandoorRecipes-recipes.md` §7, `discourse-discourse.md` §7 |

## 3. Moderacja i zaufanie

Priorytety w tej sekcji są przefiltrowane przez **D-012** (zamknięta alfa,
~20 osób): mechanizmy skali są `LATER`, jakość pierwszego kontaktu jest wyżej.

| # | Decyzja | Znacznik | Uzasadnienie | Notatka |
|---|---|---|---|---|
| 3.1 | Powiadomienie do **zgłaszającego** o wyniku zgłoszenia | **ADOPT** | Bez odpowiedzi zgłaszanie wygląda jak wrzucanie kartek do zamkniętej skrzynki i wygasa — a w alfie zgłoszenia są jedynym sygnałem; wymaga tego też art. 16 ust. 5 DSA. | `discourse-discourse.md` §3.1, §8 R3 |
| 3.2 | Wpisy w `audit_log` przy **oglądaniu** danych osobowych, nie tylko przy zmianie | **ADOPT** | Na pytanie „kto oglądał moje dane” odpowiedź „nie wiemy” jest zła, a zdarzeń nieodnotowanych nie da się odtworzyć wstecz; nasz schemat już to unosi. | `discourse-discourse.md` §2.5, §8 R6 |
| 3.3 | Akcje „cofnięcia decyzji” w `moderation_actions.action` od pierwszego dnia | **ADOPT** | Log zawierający wyłącznie kary wygląda jak akt oskarżenia i nie pozwala pokazać, że pomyłkę naprawiono. | `discourse-discourse.md` §4.6, §8 R5 |
| 3.4 | Karencja przed ponownym zgłoszeniem tej samej treści po odrzuceniu sprawy | **ADOPT** | Nasza deduplikacja obejmuje tylko sprawy otwarte, więc po odrzuceniu ta sama osoba może zgłaszać w kółko — gotowy kanał nękania. | `discourse-discourse.md` §3.2, §8 R4 |
| 3.5 | Zakaz zgłaszania własnej treści oraz treści moderatora | **ADOPT** | Bez tego grupa użytkowników zgłasza moderatora, żeby zapchać kolejkę albo podważyć decyzję. | `laravelio-laravel.io.md` §4.2, §8 R2 |
| 3.6 | Sygnały pasywne (czas wypełniania formularza, powtórzona treść, wiele kont z jednego IP) → **oznaczenie do przeglądu**, nigdy blokada | **ADAPT** | Bierzemy sygnały, odrzucamy automatyczne konsekwencje: nasz użytkownik legalnie wkleja przepis z notatnika, a mąż i żona rejestrują się z jednego łącza. | `discourse-discourse.md` §4.1, §4.4-4.5 |
| 3.7 | Akcja „Zbanuj” z wymaganym powodem i opcją „ukryj też wszystkie treści” w jednym kroku | **ADOPT** | Rozdzielenie na dwie akcje znaczy, że przy banie spamera trzeba pamiętać o drugim kroku. | `laravelio-laravel.io.md` §3.1, §8 R9 |
| 3.8 | Liczniki trafień (`match_count`, `last_match_at`) przy każdej liście blokad | **ADOPT** | Lista blokad bez liczników rośnie w nieskończoność, bo nikt nie wie, który wpis wolno usunąć. | `discourse-discourse.md` §2.4, §8 R9 |
| 3.9 | Zasada: autor **zawsze** może usunąć swoją treść, także wyróżnioną | **ADOPT** | Fresns blokuje usunięcie treści wyróżnionej; my odwracamy zależność — to widok wyróżnień sprawdza, czy treść istnieje. | `fresns-fresns.md` §4.2, §8 R9 |
| 3.10 | Automat nigdy nie decyduje sam (flagowanie tak, ban nie) | **ADOPT** *(już obowiązuje)* | Najbardziej doświadczony projekt w tym zestawieniu robi dokładnie to, co mamy w `AGENTS.md` §9 — potwierdzenie, nie zmiana. | `discourse-discourse.md` §4.7 |
| 3.11 | Rozdzielenie „sprawy” od „zgłoszenia” (jedna pozycja w kolejce, wiele zgłoszeń) | **LATER** | Przy 20 osobach (**D-012**) kolejka nie zapycha się duplikatami; wrócić, gdy jedno zgłoszenie zbierze kilku zgłaszających. | `discourse-discourse.md` §2.3, §8 R11 |
| 3.12 | Przejmowanie sprawy przez moderatora (`claimed_by`) | **LATER** | Jeden moderator nie kolizjuje sam ze sobą (**D-012**). | `discourse-discourse.md` §2.3 |
| 3.13 | Poziomy zaufania (TL0–TL4) i uprawnienia od nich zależne | **LATER / REJECT na dziś** | Przy 20 osobach to mechanika bez treści, a wprowadzona za wcześnie jest blisko publicznego rankingu użytkowników zakazanego w `AGENTS.md` §12. | `discourse-discourse.md` §7 |
| 3.14 | Automatyczne wyciszanie po N zgłoszeniach | **REJECT na dziś** | Trzy zgłoszenia to 15% naszej alfy — narzędzie do wykluczania kogoś przez trzech znajomych. | `discourse-discourse.md` §7 |
| 3.15 | Ważenie zgłoszeń zaufaniem zgłaszającego (`reviewable_scores`) | **LATER** | Wymaga poziomów zaufania z 3.13. | `discourse-discourse.md` §7 |
| 3.16 | **Shadow filtering** — ciche ograniczanie zasięgu konta bez informowania go | **REJECT** | Sprzeczne z art. 17 DSA i z zasadą „jasny powód decyzji”: kto nie wie, że jest ograniczony, nie może się odwołać. | `pixelfed-pixelfed.md` §7 |
| 3.17 | `impersonate` (wejście moderatora na konto użytkownika) | **REJECT** | Brak funkcji jest lepszym zabezpieczeniem niż funkcja z logiem. | `discourse-discourse.md` §7 |

## 4. Produkt i UX

| # | Decyzja | Znacznik | Uzasadnienie | Notatka |
|---|---|---|---|---|
| 4.1 | Wyróżnienie treści jako jawna decyzja człowieka (`featured_at`), zamiast sortowania propozycji osób po liczbie wpisów | **ADAPT** | Dzisiejsze `DiscoverFeed::suggestedPeople()` sortuje po `posts_count`, czyli premiuje ilość — to zalążek rankingu, którego `AGENTS.md` §12 zabrania; ręczne wyróżnienie daje ten sam efekt bez algorytmu. | `fresns-fresns.md` §2.4, §8 R4 |
| 4.2 | Widok przepisu jako jedna oś czasu (wykonania + komentarze chronologicznie) | **ADOPT** | Wzmacnia „Ugotowałem” jako główny sygnał produktu i nie wymaga żadnej migracji — to `UNION` dwóch zapytań. | `mealie-recipes-mealie.md` §2.6, §8 R5 |
| 4.3 | Parser składników **nigdy** nie blokuje zapisu przepisu | **ADOPT** *(zasada już w schemacie)* | `ingredient_text NOT NULL` mamy; brakuje zapisanej zasady, żeby przy implementacji parsera jej nie odwrócić — „nie rozumiem składnika: 1 szklanka mąki” przy przepisie babci kończy korzystanie z serwisu. | `TandoorRecipes-recipes.md` §3.1, §8 R3 |
| 4.4 | Nie tworzyć wpisów w `ingredients` automatycznie z tekstu użytkownika | **ADOPT** | Tandoor zakłada nowy wpis słownika przy każdej literówce; u nas słownik jest wspólny i publiczny, więc pierwszy spamer wpisałby do niego 500 wierszy z linkami. | `TandoorRecipes-recipes.md` §4.4, §8 R4 |
| 4.5 | Polska lista słów, które nigdy nie są jednostką (jajko, cebula, ząbek, garść) | **ADOPT** | Klasyczny błąd parsera: „1 jajko” → ilość 1, jednostka „jajko”, składnik pusty; ich lista jest niemiecka i bezużyteczna. | `TandoorRecipes-recipes.md` §4.5, §8 R5 |
| 4.6 | Przepływ „Schowaj wpis” / „Przywróć” dla autora, odróżniony od usunięcia | **ADAPT** | Soft delete już mamy; brakuje przepływu — a „usunąłem i już tego nie ma” jest dla naszej grupy źródłem realnego stresu. | `pixelfed-pixelfed.md` §3.1, §8 R11 |
| 4.7 | Lista zablokowanych osób w ustawieniach, nie tylko na profilu | **ADOPT** | Kto zablokował kogoś przez pomyłkę, nie znajdzie jego profilu, bo go nie widzi. | `laravelio-laravel.io.md` §3.2 |
| 4.8 | Blurhash / dominujący kolor jako placeholder zdjęcia | **ADAPT** | Realny zysk na wolnym łączu (nasz scenariusz bazowy), a mieści się w `media.metadata` bez migracji. | `pixelfed-pixelfed.md` §2.6, §8 R10 |
| 4.9 | Okładka i kolejność pozycji w zeszycie | **LATER (V1)** | „Zeszyt” jest jedną z pięciu pozycji nawigacji, więc nie powinien wyglądać jak tabela — ale kolumnę kolejności warto dodać, póki tabela jest pusta. | `reaper47-recipya.md` §3.1, §8 R6-R7 |
| 4.10 | Udostępnianie całego zeszytu linkiem | **LATER (V1)** | „Wysyłam córce zeszyt po babci” jest bardzo w duchu Kuking, ale to rozszerzenie 2.19, nie osobna funkcja. | `reaper47-recipya.md` §8 R8 |
| 4.11 | Album ograniczony do 4 zdjęć zamiast 6 | **REJECT (na razie)** | Dojrzały serwis zdjęciowy zatrzymał się na 4, bo przy większej liczbie ludzie wrzucają serie zamiast wybierać — ale to zmiana produktowa do decyzji właściciela, nie wniosek z researchu. | `pixelfed-pixelfed.md` §3.3 |
| 4.12 | Wyszukiwarka jako strona startowa | **REJECT** | Kuking startuje feedem obserwowanych, a przy pustym „Świeżo z Kuking” (`AGENTS.md` §8) — Recipya to menedżer biblioteki, my jesteśmy społecznością. | `reaper47-recipya.md` §3.3 |
| 4.13 | Wybór wyglądu oddany autorowi (`landscape_view`, `show_as_header`) | **REJECT** | Niespójne ekrany są sprzeczne z `docs/UX_50_PLUS.md`; jeden dobry układ jest lepszy niż pięć konfigurowalnych. | `mealie-recipes-mealie.md` §7, `TandoorRecipes-recipes.md` §7 |
| 4.14 | Treść prywatna publikowana automatycznie po N dniach (`private_end_after`) | **REJECT** | Użytkownik nie przewidzi konsekwencji — to pułapka prywatności, nie funkcja. | `fresns-fresns.md` §7 |
| 4.15 | Import z URL kończący się zapisem bez ekranu „sprawdź i popraw” | **REJECT** | 600 linii samego czyszczenia danych w Mealie pokazuje, ile rzeczy wychodzi źle; podgląd przed zapisem jest warunkiem, nie ozdobą. | `mealie-recipes-mealie.md` §3.3 |

## 5. Jakość, testy, wydajność

| # | Decyzja | Znacznik | Uzasadnienie | Notatka |
|---|---|---|---|---|
| 5.1 | Klasa bazowa testów widoczności („każdy stan × każdy typ obserwatora”) dla `Post`, `Recipe`, `Comment`, `CookedEvent`, `Collection` | **ADAPT** | Odpowiednik ich testów wielodzierżawności: wyciek prywatnej treści nie wywala testu, tylko cicho pokazuje za dużo — a nowy typ treści to wtedy jeden plik, nie pięć zapomnianych asercji. | `mealie-recipes-mealie.md` §5, §8 R2 |
| 5.2 | Test: kluczowe formularze mają w wyrenderowanym HTML-u `action` i `method` | **ADOPT** | Formularze HTMX Recipyi bez `action` nie robią nic bez JavaScriptu — dokładnie ta pułapka grozi naszym komponentom Livewire, a **D-007** inaczej nikt nie sprawdzi. | `reaper47-recipya.md` §4.2, §8 R5 |
| 5.3 | Helpery testowe `loginAsModerator()` / `loginAsAdmin()` / `createUser()` | **ADOPT** | Test moderacji ma mieć jedną linijkę setupu, inaczej dodanie roli `senior_moderator` to zmiana w każdym teście. | `laravelio-laravel.io.md` §5, §8 R5 |
| 5.4 | Testy jednostkowe funkcji tekstowych (normalizacja składnika, slug, parser) jako słownik wejście → wynik | **ADOPT** | Przypadki brzegowe dopisuje się jedną linijką, a te, które parsujemy źle, zostają w teście z komentarzem zamiast udawać, że ich nie ma. | `TandoorRecipes-recipes.md` §5, §8 R6 |
| 5.5 | Testy negatywne przed pozytywnymi w pliku | **ADOPT** | Agent AI czytający plik najpierw widzi granice reguły, a nie happy path. | `laravelio-laravel.io.md` §5 |
| 5.6 | Stałe nazw akcji na policies (`RecipePolicy::COOK`) | **ADOPT** | Literówka w stringu `'cook'` daje cichy `false` w autoryzacji — najgorszy rodzaj błędu, bo wygląda jak działające zabezpieczenie. | `laravelio-laravel.io.md` §5, §8 R4 |
| 5.7 | Katalog `tests/Integration` na testy akcji domenowych bez HTTP | **ADOPT** | `SearchQuery`, `PublishRecipe` i `BlockUser` da się testować szybciej i czytelniej bez warstwy HTTP. | `laravelio-laravel.io.md` §5, §8 R10 |
| 5.8 | `DB::whenQueryingForLongerThan(300 ms)` → Sentry | **ADOPT** | Sześć linijek daje odpowiedź na pytanie „co jest wolne na produkcji” bez APM-a. | `laravelio-laravel.io.md` §6, §8 R8 |
| 5.9 | Larastan na `app/Domain` | **ADAPT** | Bierzemy analizę statyczną z ich stosu jakości, ale zaczynamy od jednego katalogu; **D-010** daje gdzie to uruchamiać bez zużywania minut GitHuba. | `filamentphp-filament.md` §5, §8 R7 |
| 5.10 | Komentarz przy każdym indeksie mówiący, jakie zapytanie obsługuje | **ADOPT** | Bez tego za rok nikt nie wie, czy indeks wolno usunąć — Tandoor ma indeks na kluczu głównym, Mealie usuwał indeks migracją. | `fresns-fresns.md` §2.2, §8 R6 |
| 5.11 | Scope `withoutBlocked($viewer)` na modelach zamiast liczenia listy ID w każdej klasie feedu | **ADOPT** | Dziś nowe zapytanie może po prostu „zapomnieć” o blokadach. | `laravelio-laravel.io.md` §8 R7 |
| 5.12 | Cache listy zablokowanych z jawnym sentynelem pustki | **LATER** | Konto bez blokad (czyli większość) odpytuje dziś bazę przy każdym wejściu na stronę główną — ale to zmiana po pomiarze, nie z góry. | `pixelfed-pixelfed.md` §6, §8 R12 |
| 5.13 | `profiles.last_post_at` zamiast `whereHas` + `withCount` w propozycjach osób | **LATER** | Zapytanie na ekranie widzianym przez każdego nowego użytkownika; do zmiany po pomiarze. | `fresns-fresns.md` §6, §8 R7 |
| 5.14 | Rozdzielić w `ProcessUploadedImage` błąd trwały (`fail()`) od przejściowego | **ADOPT** | Dziś plik, który nie jest obrazem, jest ponawiany trzy razy, mimo że status `rejected` jest już finalny. | `pixelfed-pixelfed.md` §5, §8 R15 |
| 5.15 | Fanout-on-write feedu (11 jobów utrzymujących materializowane feedy) | **REJECT** | Pixelfed pokazuje, ile to kosztuje: każda operacja społeczna wymaga własnego joba naprawiającego — `AGENTS.md` §8 i **D-001** są potwierdzone. | `pixelfed-pixelfed.md` §6, §7 |
| 5.16 | Osobny indeks wyszukiwania (Scout/Meilisearch) | **REJECT (potwierdza D-004)** | Recipya musi utrzymywać trigger kasujący wpis z indeksu przy usunięciu przepisu — drugie źródło prawdy, które może się rozjechać; my szukamy po tabelach z warunkiem widoczności. | `reaper47-recipya.md` §5 |

## 6. Panel administracyjny — odpowiedź na issue #20

| # | Decyzja | Znacznik | Uzasadnienie | Notatka |
|---|---|---|---|---|
| 6.1 | **Filament na `/admin`** | **LATER — blokada techniczna** | Filament 4.x wymaga `livewire/livewire: ^3.7`, a my mamy 4.4.3, więc dziś instalacja kończy się konfliktem zależności; powód jest techniczny i sprawdzalny, nie światopoglądowy. | `filamentphp-filament.md` §2, §8 R1 |
| 6.2 | Warunek powrotu do tematu: wydanie Filamenta z `livewire/livewire: ^4.0` | **ADOPT (jako zapis w issue #20)** | Sprawdzenie to jedna komenda `composer require filament/filament --dry-run`, więc decyzja ma jednoznaczny warunek zniknięcia blokady. | `filamentphp-filament.md` §8 R2 |
| 6.3 | Własny panel zostaje **celowo ubogi**: kolejka, decyzja, powód, historia | **ADOPT** | Filtry, akcje zbiorcze i pulpit to jedyna praca w tym projekcie, którą gotowy pakiet MIT wykona lepiej — budowanie ich teraz to budowanie czegoś do wyrzucenia. | `filamentphp-filament.md` §8 R3 |
| 6.4 | Zapisać, że **D-007 i `UX_50_PLUS.md` nie obejmują panelu** | **ADOPT** | Odbiorcą panelu jest moderator na własnym sprzęcie, nie nasza grupa docelowa; bez tego zapisu przy każdej dyskusji o panelu wraca ten sam spór. | `filamentphp-filament.md` §4.4-4.5, §8 R6 |
| 6.5 | 2FA na kontach moderatora i admina | **ADOPT** | Konto moderatora widzi dane osobowe wszystkich użytkowników; jeśli Filament wejdzie, dostajemy to z pakietem, jeśli nie — trzeba zaplanować osobno. | `filamentphp-filament.md` §3.4, §8 R5 |
| 6.6 | Cofnięcie Livewire do 3.x albo osobna aplikacja z panelem | **REJECT** | Pierwsze podporządkowuje stack produktu narzędziu wewnętrznemu, drugie łamie **D-001** i tworzy dwa miejsca na reguły autoryzacji. | `filamentphp-filament.md` §2 |
| 6.7 | Ustawienia produktu edytowalne w panelu | **REJECT** | Czwarte wystąpienie tego samego wniosku w researchu: progi zostają w `config/kuking.php`, z testem i historią w gicie. | `filamentphp-filament.md` §7 |

---

## 7. Co research mówi o już podjętych decyzjach

`docs/DECISIONS.md` jest poza zakresem zapisu tego issue. Poniżej wnioski
jako **propozycje** — żadna decyzja nie wymaga zmiany.

| Decyzja | Co pokazuje research | Wniosek |
|---|---|---|
| **D-001** — modularny monolit | Pixelfed przy federacji i fanoucie utrzymuje 11 jobów tylko do naprawiania feedów; Recipya obsługuje rodzinę z jednego pliku SQLite | **Potwierdzona.** Argumentów za zmianą brak |
| **D-003** — własny model `media` | Mealie trzyma zdjęcie jako pojedynczy string bez statusu i checksumy; wtyczka Filamenta do MediaLibrary zakłada dokładnie ten uproszczony model | **Potwierdzona.** Żaden z siedmiu projektów nie ma cyklu `pending → processing → ready \| rejected` |
| **D-004** — PostgreSQL zamiast Scouta | Mealie robi to samo (trigramy w PostgreSQL) na większym zbiorze przepisów; Recipya pokazuje koszt osobnego indeksu (trigger utrzymujący spójność) | **Potwierdzona — ale zagrożona własną implementacją.** Nasze indeksy trigramowe nie są używane (poz. 2.1); bez tej poprawki „przekroczenie SLA” nastąpi z powodu, który nie ma nic wspólnego z PostgreSQL-em i może doprowadzić do niesłusznego odwrócenia decyzji |
| **D-005** — brak `UNIQUE` w `cooked_events` | Mealie ma `last_made` jako jeden znacznik czasu, czyli „ostatnio zrobione” zamiast historii gotowania | **Potwierdzona.** Ich model gubi dokładnie to, co u nas jest najcenniejsze |
| **D-007** — bez JavaScriptu | Recipya renderuje wszystko po stronie serwera, a mimo to rejestracja bez JS nie działa, bo formularz ma tylko `hx-post` | **Potwierdzona i wzmocniona.** „Lekki frontend” nie oznacza działania bez JS — stąd test z poz. 5.2 |
| **D-010** — CI na własnym runnerze | Discourse tej wielkości utrzymuje się, bo każda reguła moderacyjna ma test; Filament trzyma Larastan, Pint i Pest w jednym stosie | **Potwierdzona.** Wzorce CI są dla nas użyteczne — poz. 5.9 i podział testów na tanie/drogie zakładają runner, nie minuty GitHuba |
| **D-012** — zamknięta alfa | Progi Discourse (3 zgłoszenia wyciszają nowe konto) przy 20 osobach oznaczają 15% społeczności | **Potwierdzona.** Cała sekcja 3 jest pod tym kątem przepriorytetyzowana; mechanizmy skali są `LATER` |

---

## 8. Znaleziska w naszym kodzie (do osobnych issues)

Research znalazł **sześć realnych błędów**. Zgodnie z zakresem issue #19 żaden
nie został naprawiony; każdy ma kryteria akceptacji w notatce źródłowej.

| Waga | Błąd | Gdzie | Notatka |
|---|---|---|---|
| **P0** | Zdjęcia z telefonu publikują się obrócone — brak orientacji EXIF przed re-enkodowaniem | `app/Jobs/ProcessUploadedImage.php:68-70` | `pixelfed-pixelfed.md`, issue 1 |
| **P0** | `Media::url()` może zwrócić surowy plik użytkownika z GPS-em w EXIF | `app/Models/Media.php:80-84` | `pixelfed-pixelfed.md`, issue 2 |
| **P0** | Konto założone z telefonu (`Jan@…`) może być nie do zalogowania i nie do odzyskania | `RegisterController.php:81`, `LoginController.php:91,94` | `reaper47-recipya.md`, issue 1 |
| **P1** | Indeksy trigramowe nie są używane — każde wyszukiwanie to pełny skan | `SearchQuery.php:40-52,73-78` + cztery migracje | `TandoorRecipes-recipes.md`, issue |
| **P1** | Zbanowane konto działa do końca sesji | `app/Models/User.php:243`, brak middleware | `laravelio-laravel.io.md`, issue |
| **P2** | Ten sam przepis może wejść dwa razy do jednego zeszytu; ten sam plik dwa razy do albumu | `collection_items`, `post_media` | `reaper47-recipya.md` issue 2, `pixelfed-pixelfed.md` issue 3 |

## 9. Gdyby trzeba było wybrać pięć rzeczy

Kolejność wynika z dwóch kryteriów: **czego nie da się odtworzyć wstecz**
oraz **co psuje pierwszy kontakt z serwisem**.

1. **Poz. 1.3** — normalizacja e-maila. Konto, którego nie da się odzyskać,
   to koniec korzystania z serwisu, zanim się zaczęło.
2. **Poz. 1.1** — orientacja EXIF. Pierwsze zdjęcie obiadu wychodzi obrócone,
   a użytkownik 50+ tego nie zgłosi.
3. **Poz. 1.2** — wyciek GPS. Jedyny błąd na tej liście, który jest
   nieodwracalny dla użytkownika, a nie tylko wstydliwy dla nas.
4. **Poz. 2.2 + 3.1** — kara wygasa sama i zgłaszający dostaje odpowiedź.
   Tanie, niemożliwe do odtworzenia wstecz, dotyczą zaufania, a nie skali.
5. **Poz. 2.1** — indeksy trigramowe. Chroni **D-004** przed odwróceniem
   z niewłaściwego powodu.

---

## 10. Trzy pakiety Laravela — odpowiedź na issue #21

**Ta sekcja pochodzi z innego researchu niż reszta pliku.** Sekcje 1–9 są
wynikiem lektury siedmiu publicznych repozytoriów (issue #19); ta jest
wynikiem `docs/research/PAKIETY.md`, czyli konfrontacji trzech pakietów
rekomendowanych przez `docs/research/PUBLIC_REPOS.md` (poz. 16, 17, 20)
z tym, co w Kuking już działa. Trzymam ją tutaj, bo issue #21 wprost o to
prosi („zapisać ten próg w `docs/INSPIRATION_DECISIONS.md`”) — a decyzje mają
mieszkać w jednym pliku, nie w dwóch.

Kryterium było jedno i wspólne, wzięte z `AGENTS.md` §3: **pakiet wchodzi
tylko wtedy, gdy usuwa nazwany, dziś istniejący problem.** „Przyda się
później” jest tym sformułowaniem, przed którym ta sekcja AGENTS.md ostrzega.

| # | Decyzja | Znacznik | Uzasadnienie | Notatka |
|---|---|---|---|---|
| 10.1 | `spatie/laravel-permission` zamiast kolumny `users.role` z `CHECK` | **LATER** — próg: **trzeci moderator** albo pierwszy przypadek rozdzielenia uprawnień w ramach jednej roli („może ukrywać treść, ale nie może banować”) | Dziś jest 1–2 moderatorów (**D-012**) i zero takich przypadków w kodzie. Trzy role w kolumnie z `CHECK`-iem obsługują to bez zależności, a próg jest tani do sprawdzenia — jedno spojrzenie na listę moderatorów. | `PAKIETY.md` §a |
| 10.2 | `laravel/pennant` — flagi funkcji | **LATER** — próg: pierwsza funkcja z `ROADMAP.md` V1 (grupy, forki, planer, import) wchodzi w fazę pisania kodu na scalonym `main`, albo pojawia się potrzeba pokazania niedokończonej funkcji tylko administratorowi | Koszt wdrożenia jest niski (jedna tabela `features`, sterownik `database`, zero Redisa) i **nie rośnie** od czekania — a dziś nie ma ANI JEDNEJ funkcji czekającej na flagę. Trzykrokowy kreator przepisu, podawany w issue #21 jako przypadek użycia, jest już na produkcji i działa bez flagi. | `PAKIETY.md` §b |
| 10.3 | `spatie/laravel-activitylog` do ogólnego audytu | **REJECT** | Własny `AuditLogEntry` jest wdrożony w 16 miejscach i ma **bezpieczniejszy domyślny kierunek**: trzeba świadomie coś dopisać, nie świadomie coś wykluczyć. `record()` przyjmuje `action` i `subject`, więc nie ma jak przekazać mu treści wpisu ani adresu e-mail. Pakiet domyślnie loguje wartości pól. | `PAKIETY.md` §c |
| 10.4 | `spatie/laravel-activitylog` do historii zmian modeli | **REJECT** | Jedyny realny przypadek („historia zmian jednego rekordu” — przepisy) ma już dedykowane, celowo zaprojektowane `recipe_versions`/`RecipeVersion`. Pakiet dublowałby istniejący mechanizm i dodawał ryzyko, którego dziś nie ma. | `PAKIETY.md` §c |

### Trzy rzeczy, które issue #21 kazał sprawdzić — i co z tego wyszło

**„Czy Activitylog da się skonfigurować tak, żeby NIGDY nie zapisywał
wartości pól?”** — **da się** (`logOnly()` z jawną listą pól). I to jest
właśnie argument przeciw, nie za: pakiet skonfigurowany tak, żeby był
bezpieczny, przestaje robić cokolwiek, czego nie robi już `AuditLogEntry`.
Zostaje sama zależność i drugi mechanizm do pilnowania. Werdykt **REJECT**
zostaje w mocy i to sprawdzenie go wzmocniło.

**„Przygotować migrację przejściową `users.role` → role pakietu, żeby to nie
było później niespodzianką.”** — **odradzam pisanie jej teraz** i tego punktu
świadomie nie realizuję. Migracja do pakietu, którego nie przyjmujemy, jest
kodem do wyrzucenia, gdyby próg z 10.1 nie nadszedł — czyli dokładnie
budowaniem na zapas, którego zabrania `AGENTS.md` §3. Próg jest zapisany
i tani do sprawdzenia; to wystarcza, żeby nie było niespodzianki.

**„Czy istnieje czwarty pakiet warty rozważenia?”** — szukano świadomie
i **nie znaleziono**. Rate limiting robi już `config('kuking.rate_limits')`
plus wbudowany throttle; panel administracyjny jest rozstrzygnięty w poz. 6.1
jako `LATER` z powodu blokady technicznej (Filament 4.x wymaga
`livewire/livewire: ^3.7`, projekt ma `^4.0`); wyszukiwanie, kolejki i media
rozstrzygają **D-003** i **D-004**. `PAKIETY.md` sekcja końcowa.

---

**Źródła:** `docs/research/repos/laravelio-laravel.io.md`,
`pixelfed-pixelfed.md`, `fresns-fresns.md`, `TandoorRecipes-recipes.md`,
`mealie-recipes-mealie.md`, `reaper47-recipya.md`, `discourse-discourse.md`,
`filamentphp-filament.md`.

**Nieprzeanalizowane** (fazy 4 i 5, dla V1/V2): `TomBursch/kitchenowl`,
`grocy/grocy`, `openfoodfacts/openfoodfacts-server`, importery Mealie
i Tandoora, `recipe-scrapers`. Wnioski o imporcie z URL zebrane przy okazji
Mealie (poz. 1.9, 4.15) wystarczą, żeby otworzyć issue o imporcie —
ale nie zastępują osobnej analizy parserów.
