# Audyt pełny — repozytorium, produkt, rynek (15 września 2026)

**Podstawa:** `d50183e` (Alfa 0.36). Audyt czytający kod, schemat bazy i dokumenty,
uruchamiający narzędzia repozytorium, mierzący zachowanie wyszukiwarki na żywej
bazie oraz zbierający zewnętrzne dane rynkowe.

**Czego ten audyt NIE robi.** Nie odtwarza scenariuszy w przeglądarce, nie
dotyka produkcji, nie ogląda paneli Railway / Cloudflare / EmailLabs i nie
zastępuje badań z ludźmi (#15). Wszystko poniżej ma dopisaną metodę dowodu.
Gdzie metody nie ma — stoi to wprost.

---

## 1. Werdykt w jednym akapicie

Kod jest w bardzo dobrym stanie i **to nie jest tu wąskie gardło**. Narzędzia
przechodzą na czysto (PHPStan 0 błędów, Pint 0 poprawek, 3828 testów /
76 814 asercji zielonych). Wąskie gardło leży gdzie indziej: **serwis nie ma
ani jednej kopii bazy**, a produkt nie ma ani jednego prawdziwego użytkownika,
przy planie startu closed alphy w pierwszym tygodniu listopada — za siedem
tygodni. Największym ryzykiem projektu nie jest błąd, tylko **utrata danych
przed pierwszym użytkownikiem** i **brak drogi powrotu** dla tego użytkownika,
gdy już przyjdzie.

---

## 2. Pomiary — liczby, nie wrażenia

| Co | Wartość | Metoda |
|---|---|---|
| PHPStan (larastan, poziom z `phpstan.neon`) | **0 błędów** | `vendor/bin/phpstan analyse` |
| Pint | **0 plików do poprawy** | `vendor/bin/pint --test` |
| Testy | **3828 testów, 76 814 asercji, 0 porażek** | `php -d memory_limit=3G vendor/bin/phpunit` |
| Czas pełnego przebiegu testów | **543 s (9 min) szeregowo** | ten sam przebieg, zegar ścienny |
| `php artisan test --parallel` | **nie działa** | Collision żąda `brianium/paratest`, którego nie ma w `composer.json` ani w `vendor/` |
| Kod aplikacji (`app/`) | 59 434 linii, z czego **27 324 to komentarz** (46%) | `find app -name '*.php'` |
| Kod wykonywalny w `app/` | ~32 000 linii | linie bez komentarza i pustych |
| Testy | 137 841 linii w 544 plikach | `find tests` |
| Widoki | 18 845 linii w 153 plikach | `find resources/views` |
| Dokumentacja | **91 135 linii w 307 plikach** | `find docs -name '*.md'` |
| `docs/DECISIONS.md` | 14 591 linii, **196 decyzji** | `grep -c '^## D-'` |
| Migracje | 77 | `ls database/migrations` |
| Sekrety w repo | **brak** — `.env` nigdy nie był w historii, `.env.example` czysty | `git log --all -- .env`, `git check-ignore` |
| Otwarte issues | 37, w tym **8 z etykietą `P0`** | GitHub API |
| Tempo pracy | 120 commitów w 4 dni (12–15.09) | `git log --since` |

**Proporcja, którą warto zobaczyć:** prozy (dokumentacja + komentarze w kodzie)
jest ok. **118 tys. linii wobec ok. 32 tys. linii kodu wykonywalnego — 3,7 : 1.**
To nie jest samo w sobie wadą; niżej (§6) opisane jest, kiedy zaczyna nią być.

---

## 3. Co jest zrobione dobrze — i warto tego nie zepsuć

Wymieniam tylko rzeczy, które sprawdziłem, a nie te, które dokumentacja
o sobie mówi.

1. **Granice autoryzacji są prawdziwe, nie deklarowane.** `CollectionController::show()`
   nakłada `widoczneDla()` *i* `dostepnyJakoAutor()` — dwie różne granice, bo
   widoczność wpisana przez autora to co innego niż status jego konta. Jest test
   trasy z identyfikatorem pod Policy (`KazdaTrasaZIdentyfikatoremPodPolicyTest`).
   To jest realizacja zasady „UUID w adresie to nie autoryzacja”, nie jej opis.
2. **Testy N+1 podają LICZBĘ, nie kolor.** Przebieg wypisuje
   `[N03 /odkryj] malo: 15 zapytan, duzo (30 wpisow x 3 zdjecia): 15 zapytan`.
   Stała liczba przy dziesięciokrotnym wzroście danych to dowód, a nie nadzieja.
   Tego wzorca nie ma w większości projektów tej wielkości.
3. **`$fillable` jest zamknięty świadomie i z uzasadnieniem przy kolumnie.**
   `status`, `role`, `email` i `email_verified_at` są poza listą, a komentarz
   przy niej tłumaczy atak, który to zamyka (przejęcie konta przez podmianę
   adresu i „nie pamiętam hasła”).
4. **Zgoda na tygodniowy przegląd została naprawiona we właściwą stronę.**
   Migracja `2026_09_07_400000` zdejmuje `DEFAULT true` i przestawia istniejące
   wiersze, bo zgoda, o którą nie zapytano, nie jest zgodą. To jest poprawne
   rozumienie art. 6 ust. 1 lit. a RODO — rzadkie w projektach tej skali.
   (Skutek produktowy tej poprawki opisuję w §5.2 — jest realny.)
5. **`docs/OTWARCIE.md` jest uczciwym dokumentem bramkowym.** Cztery znaczniki,
   z których dwa jawnie nie są sukcesem, i zasada „`NIE WIEMY` liczy się jako
   nieprzejście bramki”. Projekt nie okłamuje sam siebie co do swojego stanu.
6. **`docs/product/COLD_START.md` jest lepszy niż większość playbooków startowych.**
   Rozpoznaje podwójną pustkę (brak przepisów → brak `Ugotowałem` → przepisy
   wyglądają na porzucone), odrzuca najtańsze lekarstwo (import 20 tys. przepisów)
   i stawia twardą regułę: nie rosnąć szybciej, niż redakcja zdąży odpowiadać.
7. **Kopie i odtworzenie mają skrypty** (`scripts/kopia-lokalna.sh`,
   `tests/skrypty/proba-odtworzenia.sh`) — brakuje wyłącznie uruchomienia
   ich na produkcji (§4.1).

---

## 4. Znaleziska — w kolejności wagi

### 4.1 `P0` · Nie ma żadnej kopii bazy, a cały przekaz marki brzmi „Twoje przepisy nie zginą”

**Dowód:** `docs/OTWARCIE.md` wiersz 8: „**Dziś nie ma żadnej kopii bazy.**
Volume Backups i PITR są tylko w planie Pro, Kuking jest na Free/Hobby.”
Wiersz 9: restore drill `NIEZROBIONE`. Wiersz 7: zdjęcia sprzed R2 nadal leżą
na wolumenie kontenera — utrata kontenera to utrata oryginałów.
Issues #193, #9, #120 — wszystkie otwarte.

**Dlaczego to jest pierwsze na liście, a nie trzecie.** Issue #30 (`P0`) chce
przestawić pozycjonowanie na „Twoje przepisy nie zginą”. Dopóki wiersze 7–9
są puste, to zdanie jest **nieprawdziwe w najmocniejszym możliwym sensie** —
nie „przesadzone”, tylko fałszywe. Projekt, który ma 196 udokumentowanych
decyzji i test pilnujący, czy tabela stacku nie kłamie, nie może wejść na rynek
z obietnicą trwałości bez jednej kopii zapasowej. Ryzyko jest też
asymetryczne: utrata bazy z 30 prawdziwymi kucharzami 50+ to nie incydent
techniczny, tylko koniec projektu — tej grupy nie prosi się drugi raz.

**Do zrobienia:** #193 → #9 → dopiero potem kampania z #30.
To są bramki po stronie właściciela, kodem ich nie domkniesz.

---

### 4.2 `P1` · Tabela stacku mówi, że wyszukiwarka używa FTS. Nie używa. To jest dokładnie błąd Sentry'ego z D-104, wiersz niżej

**Dowód:**

- `AGENTS.md:114` → `| Wyszukiwarka | PostgreSQL FTS + pg_trgm + unaccent | … |`
- `grep -rn "tsvector\|tsquery\|to_tsvector\|ts_rank" app database routes config` → **zero trafień.**
- `app/Domain/Search/SearchQuery.php` używa wyłącznie `word_similarity()` /
  `similarity()` z `pg_trgm` po `public.kuking_normalize()` (czyli `unaccent(lower())`).
- **`docs/DECISIONS.md` D-004 mówi to wprost i zaprzecza tabeli:** „`pg_trgm` +
  `unaccent` radzą sobie z literówkami i brakiem polskich znaków **lepiej niż
  stemming, którego dla polskiego w Postgresie po prostu nie ma**”.

Czyli: dziennik decyzji świadomie odrzucił FTS, kod go nie ma, a tabela stacku
go ogłasza. `TabelaStackuMowiPrawdeTest` tego nie łapie, bo lokalizator
(`w repozytorium: …enable_postgres_extensions.php`) **istnieje** — test sprawdza,
czy plik jest, nie czy wiersz opisuje to, co się w nim dzieje. To jest ta sama
klasa błędu, którą AGENTS.md §3 opisuje na pół strony przy Sentrym, w wierszu
bezpośrednio pod nim.

**Ważne zastrzeżenie — decyzja D-004 jest DOBRA i to zmierzyłem.** Na żywej bazie
`kuking`:

```
pierogi        → „Pieróg z kapustą”              word_sim 0,750  ZNAJDZIE
pierogow       → „Ciasto na pierogi”             word_sim 0,667  ZNAJDZIE
schabowy       → „Kotlety schabowe z kością”     word_sim 0,778  ZNAJDZIE
zupa ogorkowa  → „Ogórkowa zupa mojej mamy”      word_sim 1,000  ZNAJDZIE
jablka         → „Szarlotka z jabłkami”          word_sim 0,857  ZNAJDZIE
ziemniaki      → „Placki ziemniaczane”           word_sim 0,800  ZNAJDZIE
rosol          → „Rosół z kury”                  word_sim 1,000  ZNAJDZIE
```

(metoda: `psql`, `word_similarity(kuking_normalize(q), kuking_normalize(t))`
wobec progu 0,5 z D-046; pary zapytanie/tytuł syntetyczne, nie z produkcji)

Trigramy radzą sobie z polską odmianą i brakiem diakrytyków **na tych parach**.
Do naprawy jest jedno słowo w jednym wierszu tabeli, nie wyszukiwarka.

**Do zrobienia:** wykreślić „PostgreSQL FTS” z `AGENTS.md:114` (i z kopii tabeli
w `README.md`, którą pilnuje `test_kopia_tabeli_w_readme_zgadza_sie_z_agents`).
Rozważyć rozszerzenie `TabelaStackuMowiPrawdeTest` o kształt piąty: nazwa
techniki w kolumnie „Wybór” musi mieć trafienie w kodzie, nie tylko plik
pod ścieżką.

---

### 4.3 `P1` · Tygodniowy przegląd — jedyna droga powrotu, jaką ten produkt ma — jest wyłączony na trzy sposoby naraz

**Dowód:**

1. `config/kuking.php:1922` → `'wlaczony' => env('KUKING_DIGEST_WLACZONY', false)`,
   a `.env.example:251` i `.env:251` mają `false`.
2. `users.wants_weekly_digest` ma `DEFAULT false` (migracja `2026_09_07_400000`).
3. Jedyne miejsce, gdzie da się tę zgodę wyrazić, to `/ustawienia/prywatnosc`
   (`grep -rln wants_weekly_digest resources/views app/Http` → dwa pliki, oba
   w `Settings`). **Formularz rejestracji o to nie pyta i onboarding `/witaj/zainteresowania`
   → `/witaj/ludzie` → `/witaj/gotowe` też nie** — `register.blade.php:86` tylko *wspomina* o przeglądzie w tekście.
4. Web Push odłożony (#35), PWA nie ma sklepu, feed jest chronologiczny.

**Skutek, złożony do kupy.** Osoba 63-letnia zakłada konto, publikuje obiad —
i nie ma **żadnego** mechanizmu, który sprowadzi ją z powrotem. Nie dostanie
maila (flaga off + zgoda off), nie dostanie pusha (#35), nie ma ikony na ekranie
(#278 otwarte), a adresu `kuking.pl` nie zapamięta. `COLD_START.md` sam nazywa
to najdroższym możliwym błędem: „50+ daje produktowi jedną szansę”.

**To nie jest argument za cofnięciem poprawki RODO.** Poprawka jest słuszna.
Argument jest odwrotny: skoro zgoda ma być wyraźna, to **trzeba o nią wyraźnie
zapytać** — niezaznaczony haczyk w rejestracji albo krok w onboardingu `/witaj/zainteresowania`
(„Chcesz w sobotę jeden list o tym, co się wydarzyło przy Twoim gotowaniu?”)
jest zgodą lepszej jakości niż odhaczony `DEFAULT` i daje realny opt-in
zamiast zerowego. Do tego `KUKING_DIGEST_WLACZONY=true` na produkcji przed
wpuszczeniem pierwszych osób.

---

### 4.4 `P1` · Z dziesięciu udokumentowanych pętli retencji dwie najtańsze nie istnieją w kodzie

`docs/product/RETENTION_LOOPS.md` opisuje 10 pętli. Sprawdziłem każdą przeciwko
kodowi:

| Pętla | Znacznik w dokumencie | Stan w kodzie | Dowód |
|---|---|---|---|
| 1 · Ugotowałem → autor | główna | **jest** | `RecordCookedEvent`, `NotifyUser`, `UgotowalemZawszePowiadamiaAutoraTest` |
| 2 · Zdjęcie dnia → odzew | główna | **jest** | `Post`, `Comment`, `DailyPick`, `HeroPick` |
| 3 · Zapisuję → sobotnie przypomnienie | **MVP, koszt S** | **NIE MA** | brak polecenia/joba; `grep` po `app/Console`, `app/Jobs`, `app/Domain/Notifications` — zero |
| 4 · Temat tygodnia | MVP | **tylko narzędzie moderatora** | `TagPromotion` wystawiony wyłącznie pod `/admin/tagi-promowane`; publicznej powierzchni „tematu tygodnia” nie ma, a „tematy” skasowała migracja `2026_09_07_300000_drop_topics` |
| 5 · Sezon / kalendarz kuchni | MVP | **NIE MA** | issue #18 otwarte; brak kodu sezonowego |
| 6 · Rodzinny przepis → zaproszenie | MVP | **jest** | `RegistrationInvite` |
| 7 · Archiwum → nostalgia | MVP | **jest** | `app/Domain/Wspomnienia` |
| 8 · Pytanie do autora | MVP | **NIE MA** | issues #371 (`posts.kind`) i #372 otwarte; `posts` nie ma kolumny `kind` |
| 9 · Digest tygodniowy | MVP | **jest, ale wyłączony** | §4.3 |
| 10 · Ambasador tematu | od ~200 użytkowników | nie dotyczy | — |

**Wniosek:** cała historia retencji przed startem to **pętla 3 + pętla 8 +
włączenie pętli 9**. Pętla 3 jest w dokumencie wyceniona na „koszt S” i jest
najtańszą rzeczą o największym zwrocie na tej liście: jedno polecenie
w harmonogramie, jedna sobotnia wiadomość o **jednym** konkretnym zapisanym
przepisie z czasem przygotowania. Infrastruktura pod nią już stoi — jest
`DziennyBudzetListow`, jest `WyslijPodsumowaniaTygodnia` jako wzorzec,
są kolekcje.

---

### 4.5 `P2` · Zapisany cudzy przepis może zniknąć z mojego zeszytu, a eksport RODO zawiera tylko jego tytuł

**Dowód:**

- `collection_items.recipe_id` ma `cascadeOnDelete()`
  (`2026_09_05_000800_create_collections_tables.php`).
- `Recipe` używa `SoftDeletes`, więc zwykłe usunięcie przez autora tylko chowa
  wiersz — i to jest obsłużone poprawnie (#567, Alfa 0.36: zeszyt mówi
  o niedostępnych zapisach zamiast udawać pustkę).
- Ale `EraseAccountData:574` robi `$user->recipes()->withTrashed()->forceDelete()`
  — a to **kasuje wiersze w cudzych zeszytach kaskadą**, nieodwracalnie.
- `CollectUserExportData::collections()` zapisuje dla przepisu wyłącznie
  `tytul`, `autor`, `moja_notatka`, `zapisano`. **Treści przepisu w eksporcie
  nie ma.**

**Uczciwe zastrzeżenie: to jest w dużej części decyzja, nie błąd.** D-018 waży
dokładnie ten koszt („cudze zeszyty gubią przepisy”), a ekran usuwania konta
domyślnie *nie* kasuje treści — pełne usunięcie wymaga świadomego haczyka.
Napięcie między prawem autora do usunięcia a trwałością cudzego zeszytu jest
realne i nie da się go rozstrzygnąć na korzyść obu stron.

**Ale jedna połowa tego problemu nie ma z tym napięciem nic wspólnego.**
Mój własny eksport RODO to moje dane — a dostaję w nim listę tytułów zamiast
tego, co sobie zapisałam. Dopisanie treści przepisu (w wersji publicznej,
z podpisem autora i datą zapisu) do sekcji `kolekcje` eksportu nie narusza
niczyjego prawa do usunięcia: to jest kopia, którą człowiek pobiera do siebie,
a nie treść, którą serwis dalej publikuje. **To jest też dokładnie ta rzecz,
której na rynku brakuje wszystkim** — patrz §5.1.

---

### 4.6 `P2` · Autoryzacja zdjęcia kosztuje 6 zapytań, a przeglądarka trzyma przekierowanie tylko 150 sekund

**Dowód:** własny test repozytorium wypisuje
`[MEDIA-03 pomiar] 3 zdjęcia: 18 zapytań; 30 zdjęć: 180 zapytań; średnio na
zdjęcie: 6,00`. Koszt jest udokumentowany i uznany za uzasadniony —
zapytania [4] i [5] to status autora i blokada, czyli reguła, nie narzut.

Nowe jest zestawienie tego z TTL: `config/kuking.php:275` →
`KUKING_MEDIA_SIGNED_URL_MINUTES=5`, a `MediaController::sekundyCache()` daje
przeglądarce połowę tego, czyli **150 s**. Po 150 sekundach każde zdjęcie na
ekranie znowu kosztuje 6 zapytań. Osoba przewijająca feed przez 10 minut
przy 30 widocznych zdjęciach płaci ~180 zapytań co 2,5 minuty. Na Postgresie
z planu Free/Hobby to jest pierwsza rzecz, która się zatka — wcześniej niż
wyszukiwarka, wcześniej niż feed.

**Do zrobienia — najtańsze najpierw:** podnieść `KUKING_MEDIA_SIGNED_URL_MINUTES`
(cache przeglądarki rośnie proporcjonalnie, bo `sekundyCache()` liczy z tej samej
wartości). Kompromis prywatności jest już opisany w audycie W7-02, więc to jest
przestawienie znanego suwaka, nie nowa decyzja. Dopiero gdyby to nie wystarczyło —
wstawianie podpisanych adresów prosto w HTML feedu, skoro autoryzacja rodzica
i tak dzieje się przy renderowaniu strony. **Pomiaru pod produkcyjnym ruchem
nie ma i tego nie udaję** — ruchu nie ma.

---

### 4.7 `P2` · `php artisan test --parallel` nie działa; 9 minut pełnego przebiegu na jednej maszynie

**Dowód:** `php artisan test --parallel` kończy się `RequirementsException:
Running Collision 8.x artisan test command in parallel requires at least
ParaTest (brianium/paratest) 7.x`. `grep -c paratest composer.json` → **0**,
`ls vendor/brianium` → nie istnieje. `ci.yml:629` i `scripts/check.sh:169`
uruchamiają szeregowo.

Do tego CI chodzi dziś na starej puli WSL — **trzech rejestracjach jednej
maszyny** (#342, D-121, świadoma decyzja właściciela z 12.09, nie usterka).
Suita rośnie w tempie ok. 30 testów dziennie przy obecnym rytmie 120 commitów
na 4 dni.

**Do zrobienia:** `composer require --dev brianium/paratest`. Jedna linijka,
która przy wielordzeniowym runnerze skraca pętlę zwrotną kilkukrotnie.
Zastrzeżenie: testy dzielące bazę wymagają sprawdzenia izolacji — repozytorium
ma już `tests/skrypty/izolacja-bazy-testowej.sh` i `scripts/cleanup-test-dbs.sh`,
więc grunt jest przygotowany, ale **nie sprawdziłem, czy cała suita przechodzi
równolegle**.

---

### 4.8 `P3` · Kontroler miał być cienki — nie jest; i 47 jednorazowych skryptów audytowych

**Kontrolery** (linie bez komentarzy i pustych):

| Plik | Linii kodu |
|---|---|
| `RecipeController` | 437 |
| `PostController` | 318 |
| `Admin/ModerationController` | 273 |
| `CollectionController` | 199 |

Razem `app/Http/Controllers` to **6581** linii kodu wobec **7691** w `app/Domain`
— mniej więcej pół na pół. AGENTS.md §4 mówi „Kontroler ma być cienki”. Przy
1:1 do warstwy domenowej to jest rozjazd z własną zasadą, nawet jeśli żadna
pojedyncza metoda nie jest dramatyczna.

**Skrypty:** `scripts/` ma 47 plików, z czego duża część to jednorazowe
weryfikacje pod konkretne issue — `awatar-kafel434.mjs`, `fokus-karty-dania.mjs`,
`zwarte-kolumny.mjs`, `kafel-dodawania.mjs`, `regresja-liczb-profilu.mjs`.
Każdy z nich był potrzebny raz. Zostają na zawsze i nikt nie wie, które
jeszcze działają. Warto rozdzielić `scripts/` na to, co uruchamia CI albo
człowiek regularnie, i `scripts/archiwum/` na resztę.

---

## 5. Rynek — czego ludziom naprawdę brakuje

Research zewnętrzny, wrzesień 2026. Trzy rzeczy, które zmieniają ocenę
kierunku produktu.

### 5.1 Największa skarga na zapisywanie przepisów na świecie to dokładnie to, co Kuking może zrobić lepiej

Przycisk „zapisz” w TikToku i Instagramie jest w 2026 **głównym** mechanizmem
zapisywania przepisów — i jest zepsuty w jeden konkretny sposób: zapisy są
**wskaźnikami, nie kopiami**. Twórca kasuje film, archiwizuje Reela albo dostaje
bana — zapis przestaje działać. W zapisanych nie ma wyszukiwania, nie ma pola
składników, nie ma przeliczania porcji.
([Recipy](https://recipyapp.com/blog/tiktok-recipe-save-broken-2026))

Drugi wątek jest jeszcze mocniejszy: **aplikacje z przepisami umierają
i zabierają zbiory ze sobą.** ZipList zamknięty; MasterCook — jeden
z najstarszych organizerów, z ludźmi zbierającymi przepisy od lat 90. —
wyłączył część chmurową 31 grudnia 2026. Wniosek, który sami formułują: jeśli
Twoje przepisy istnieją tylko w jednej aplikacji, jesteś o jedno ogłoszenie
od ich utraty.
([MoveMyRecipes](https://movemyrecipes.com/blog/when-recipe-apps-die-lessons-from-ziplist))
Użytkownicy NYT Cooking zgłaszali zniknięcie całego Recipe Box.
([JustAnswer](https://www.justanswer.com/software/vqvpc-nyt-app-missing-recipe-box-saved-recipes.html))

**Co to znaczy dla Kuking.** Issue #30 („Twoje przepisy nie zginą”) nie jest
ładnym hasłem marketingowym — to jest **najlepiej zwalidowana potrzeba na tym
rynku**, a Kuking ma już połowę odpowiedzi (eksport RODO, `DataExport`,
`GenerateUserExport`). Ale to hasło trzeba **zasłużyć w dwóch miejscach naraz**:
kopią bazy (§4.1) i pełną treścią zapisanych przepisów w eksporcie (§4.5).
Dopiero wtedy zdanie „u nas nie zginą” znaczy coś, czego konkurencja nie mówi.

### 5.2 Grupa 55–75 to NAJLICZNIEJSZA grupa w polskich mediach społecznościowych — teza produktu jest potwierdzona liczbą

Raport „Social Media 2026” (Gemius/PBI): grupa **55–75 lat to najliczniejsza
grupa użytkowników mediów społecznościowych w Polsce — średnio 6,96 mln
miesięcznie**, ze średnim czasem **1 h 41 min dziennie**.
([Gemius](https://gemius.com/pl/news/raport-social-media-2026-juz-dostepny/),
[natemat](https://natemat.pl/657655,raport-social-media-2026-raport-polacy-scrolluja-ponad-2-h-dziennie-dzieci-jeszcze-dluzej))
Wśród osób 60+ z internetu korzysta 71%; najpopularniejsze platformy to
Facebook (35%) i YouTube (24%), a z komunikatorów WhatsApp (33%) i Messenger (32%).
([Bankier](https://www.bankier.pl/wiadomosc/Polacy-50-a-technologia-Jak-z-internetu-korzystaja-przyszli-seniorzy-8637974.html),
[TELKO.in](https://www.telko.in/seniorzy-w-polsce-coraz-bardziej-internetowi))

**Co to znaczy dla Kuking.** Decyzja „50+, ale bez etykiety »dla seniorów«”
jest trafiona i ma pod sobą twardą liczbę, której warto użyć — również wobec
samego siebie, gdy przyjdzie pokusa poszerzenia grupy. Drugi wniosek jest
operacyjny: ci ludzie **już są na Facebooku i Messengerze**, a nie w App Store.
To dokładnie potwierdza kanały z `COLD_START.md` §3.2 (KGW, UTW, rodzina) —
i mówi, że warto traktować obecność „Podziel się → WhatsApp / Messenger”
poważniej niż jako dodatek.

### 5.3 Cookpad — ta sama mechanika co „Ugotowałem”, i widać, dokąd ją prowadzi

Cooksnap w Cookpadzie to ta sama rzecz co „Ugotowałem”: zgłoszenie autorowi
zdjęciem, że naprawdę to zrobiłeś.
([Cookpad](https://blog.cookpad.com/us/a-cooksnap-is-worth-1-000-words/))
Mechanika jest zwalidowana na skalę światową — to nie jest hipoteza Kuking.

Kierunek, w którym Cookpad ją rozwija w 2026: **„Cook Today”** — oglądanie
Cooksnapów **w trakcie** gotowania przypiętego przepisu, żeby mieć rady
i uwagi innych bez wychodzenia z ekranu — oraz **„Plan”**, tygodniowy
kalendarz przypiętych przepisów.

**Co to znaczy dla Kuking — i to jest najtańsza dobra rzecz w całym audycie.**
Tryb gotowania już istnieje (`/przepisy/{przepis}/gotuj`), ma wielkie kroki,
minutnik i Wake Lock. Sprawdziłem, co na nim widać: przycisk „Ugotowałem”
prowadzący do formularza — i **nic więcej z cudzych wykonań**
(`grep` po `CookingModeController.php` i `cooking.blade.php`). Tymczasem
`recipes/show.blade.php` ma wykonania w siedmiu miejscach. Pokazanie w trybie
gotowania 2–3 uwag z cudzych wykonań („Marii wyszło lepiej na mniejszym ogniu”)
to:

- zerowy nowy stack i zero nowych tabel — `CookedEvent` i jego notatki już są,
- wzmocnienie mechaniki, którą AGENTS.md §1 stawia najwyżej („realne ugotowanie > lajki”),
- **i moment, w którym człowiek jest najbliżej naciśnięcia „Ugotowałem”** —
  bo stoi przy garnku.

Zastrzeżenie: to jest funkcja, nie poprawka, więc przed nią idą bramki z §4.1.
`FEATURES.md` nie wymienia jej wprost ani w MVP, ani w V1 — proponuję ją
jako kandydatkę do V1, nie jako przemycenie przy okazji.

---

## 6. Rzecz, o której trzeba powiedzieć wprost: koszt własnego procesu

Projekt ma **91 tys. linii dokumentacji i 196 decyzji architektonicznych przy
zerze użytkowników.** Na jedną linijkę kodu wykonywalnego przypada ok. 3,7
linijki prozy. `docs/DECISIONS.md` ma 14,5 tys. linii — nikt nowy tego nie
przeczyta, a AGENTS.md §2 każe czytać dziewięć dokumentów przed pierwszą zmianą.

**Nie twierdzę, że to jest błąd, i nie proponuję kasowania dokumentacji.**
Ta dokumentacja jest wysokiej jakości, wiele razy uratowała projekt przed
powtórzeniem błędu (historia Sentry'ego w AGENTS.md §3 jest tego dowodem)
i jest sensownym wyborem przy pracy z agentami AI, którzy nie mają pamięci
między sesjami.

Twierdzę co innego, w trzech punktach, z których każdy da się zmierzyć:

1. **Ostatnie 20 wpisów w CHANGELOGu to niemal wyłącznie poprawki kosmetyczne
   i copy** — odmiana „na 1 minutę”, szerokość menu konta, zawijanie kolumn,
   fokus przycisku w podpowiedzi. To jest dobra robota, ale to jest szlifowanie
   powierzchni produktu, którego **nikt jeszcze nie widział**, przy ośmiu
   otwartych `P0` i braku kopii bazy.
2. **Audyt rodzi audyt.** W `docs/` jest już pięć plików `AUDYT_*`, a ten jest
   szóstym. `scripts/` ma 47 plików weryfikacyjnych. Jest realne ryzyko, że
   proces zaczyna produkować sam siebie — i że za trzy tygodnie ktoś napisze
   siódmy audyt zamiast uruchomić `pg_dump`.
3. **Kalendarz się nie rozciąga.** `COLD_START.md` zakłada closed alphę
   w pierwszym tygodniu listopada 2026, czyli za **siedem tygodni**. Bramki
   blokujące (#193, #9, #120) są po stronie właściciela i żadna z nich nie
   ruszy z miejsca od kolejnego PR-a.

Najuczciwsze zdanie, jakie mogę tu napisać: **projekt jest gotowy technicznie
znacznie bardziej, niż jest gotowy operacyjnie, a praca płynie dziś w tę
stronę, która i tak już jest gotowa.**

---

## 7. Proponowana kolejność

Kolejność, nie lista życzeń. Każdy punkt ma warunek wejścia w postaci punktu
poprzedniego.

**Teraz — bramki, bez których start jest nieodpowiedzialny (właściciel, nie kod)**

1. #193 — kopia bazy poza Railwayem. Bez tego nic dalej nie ma sensu.
2. #9 — restore drill z RPO, RTO i listą tego, co nie zadziałało.
3. #120 + wiersz 7 z `OTWARCIE.md` — bramka R2 i przeniesienie starych zdjęć
   z wolumenu kontenera.
4. #204 — wyłączyć piksel śledzący EmailLabs; polityka prywatności mówi dziś
   nieprawdę.

**Zaraz potem — tanie rzeczy w kodzie, które decydują o retencji**

5. `KUKING_DIGEST_WLACZONY=true` + wyraźne pytanie o zgodę w rejestracji
   albo w onboardingu `/witaj/zainteresowania` (§4.3).
6. Pętla 3 — sobotnie przypomnienie o jednym zapisanym przepisie (§4.4).
   Wyceniona przez sam projekt na „koszt S”, największy zwrot na liście.
7. Treść zapisanych przepisów w eksporcie RODO (§4.5) — to jest ta połowa
   „Twoje przepisy nie zginą”, którą da się domknąć kodem.
8. `AGENTS.md:114` — wykreślić „PostgreSQL FTS” (§4.2). Pięć minut.
9. `composer require --dev brianium/paratest` (§4.7).
10. Podnieść `KUKING_MEDIA_SIGNED_URL_MINUTES` (§4.6).

**Dopiero potem — funkcje**

11. #15 — dokończyć sesje z osobami 50–75. Wykonano jedną z trzynastu, a ta
    jedna znalazła rzeczy, których nie złapał żaden automat. To jest lepsze
    źródło kolejnych issues niż jakikolwiek audyt czytający kod, ten włącznie.
12. #29 — pierwsze 20 osób wg `COLD_START.md`, przy obietnicy odzewu.
13. Cudze wykonania w trybie gotowania (§5.3) — kandydatka do V1.
14. Pętla 8 / #371 + #372 — pytania do autora.
15. #30 — kampania „Twoje przepisy nie zginą”, **dopiero gdy punkty 1–3 i 7
    są zamknięte.** Wcześniej to jest obietnica bez pokrycia.

**Czego NIE robić teraz:** dalszych poprawek copy i mikroukładu, dopóki
punkty 1–4 stoją otwarte. Powierzchnia jest już lepsza niż u większości
polskich serwisów kulinarnych, a baza wciąż nie ma kopii.

---

## 8. Metoda i granice tego audytu

**Co uruchomiłem:** `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`,
`vendor/bin/phpunit` (pełna suita), `psql` przeciwko bazie `kuking`
(pomiar `word_similarity`), `git log` / `git check-ignore`, GitHub API
(lista otwartych issues), oraz odczyt kodu, migracji i dokumentów.

**Czego nie robiłem:** nie wchodziłem na produkcję, nie otwierałem paneli
dostawców, nie oglądałem żadnego ekranu w przeglądarce, nie mierzyłem
wydajności pod ruchem, nie rozmawiałem z żadnym użytkownikiem. Pary
zapytanie/tytuł w §4.2 są syntetyczne. Pomiary zapytań w §4.6 pochodzą
z testów repozytorium, nie z produkcji.

**Czego ten audyt nie unieważnia:** `docs/AUDYT_2026-09.md`,
`docs/AUDYT_GPT_2026-09.md` ani
`docs/design/AUDYT_WIELODYSCYPLINARNY_2026_09_15.md`. Znaleziska #567, #568,
#569 i #571 z tego ostatniego stoją w mocy i nie były tu powtarzane.

**Jedna rzecz wymagająca sprostowania w moim własnym procesie:** pierwszy
przebieg testów zakończył się kodem 2. Był to skutek dwóch moich równoległych
przebiegów na jednej bazie testowej, nie usterki repozytorium. Czysty przebieg
(`vendor/bin/phpunit`, 543 s) jest zielony: **3828/3828**.

---

# Runda druga — 15 września 2026, po południu

Kontynuacja tego samego audytu w obszarach, których pierwsza runda nie
dotknęła: narzędzia gospodarza, powierzchnia logowania, lejek „Ugotowałem",
koszty, kolejka zadań, SEO oraz świeży research rynkowy.

## 9. Gdzie szukałem i NIE znalazłem dziury

To jest połowa wyniku audytu i nie wolno jej pominąć — inaczej lista znalezisk
udaje, że opisuje całość.

| Obszar | Co sprawdziłem | Wynik |
|---|---|---|
| **OAuth (Google)** | `state` + `nonce` + **PKCE**, porównanie `hash_equals()`, `session()->pull()` (jednorazowość), `session()->regenerate()` po zalogowaniu | **bez zastrzeżeń** — podręcznikowo poprawne |
| **Link logowania** | token `Str::random`, w bazie **tylko skrót** (`token_hash` z `UNIQUE`), `expires_at`, a wiersz jest **kasowany przy użyciu** (`LoginLinkController:373,382`) | **bez zastrzeżeń** — jednorazowy i nieodwracalny |
| **Kolejka zadań** | 5 z 5 jobów ma `$tries`/`$backoff`/`failed()`; do tego `kuking:martwe-zadania` | **bez zastrzeżeń** |
| **SEO** | `robots.txt` blokuje przedrostki szukaj, home, dodaj, witaj, ustawienia, zeszyt i admin; `noindex` na 12 ekranach wejścia; `Recipe`, `HowToStep`, `BreadcrumbList`, `ProfilePage` w JSON-LD; sitemap z `chunkById` i cache 6 h | **bez zastrzeżeń** |
| **`aggregateRating`** | szukałem znaleziska „brak gwiazdek = brak rich result" — **projekt ma to rozstrzygnięte lepiej ode mnie** w `docs/seo/SEO_TECHNICAL.md:193-201`: Google zabrania ocen „self-serving", a `would_make_again` to sygnał binarny, nie skala 1–5 | **moje znalezisko było błędne, dokument ma rację** |
| **Lejek „Ugotowałem"** | **wszystkie sześć pól formularza jest `nullable`** — zdjęcia, notatka, zmiany, „zrobisz ponownie", trudność, czas. Akcja to jedno kliknięcie | **bez zastrzeżeń** — dokładnie tak, jak powinna wyglądać główna akcja produktu |
| **Retencja zdjęć** | kasowanie zdjęcia usuwa też oryginał (`KasujZdjecie:245`), jest `kuking:sprzataj-osierocone-zdjecia` | **bez zastrzeżeń** |

Policzyłem też koszt magazynu, bo podejrzewałem tam ryzyko: oryginał (do 15 MB,
realnie 3–5 MB z telefonu) to ~90% objętości wobec trzech wariantów
(320/960/1600 px, razem ~0,4 MB). Osoba publikująca obiad codziennie to
**~1,6 GB rocznie ≈ 0,29 USD rocznie** przy 0,015 USD/GB-mies., a egress z R2
jest bez opłaty. **Magazyn nie jest zagrożeniem** i `COSTS.md` ma rację
w konkluzji („nie framework”).

---

## 10. Nowe znaleziska

### 10.1 `P1` · Obietnica, na której stoi cały cold start, ma narzędzie dla JEDNEJ z trzech rzeczy, które ludzie publikują

`COLD_START.md` §2 nazywa to „zasadą nadrzędną” i pisze wprost: *„To nie jest
miły dodatek — to jest produkt”*:

> **Każdy wpis pierwszych 200 użytkowników dostaje odpowiedź od prawdziwego
> człowieka. Zwykle w ciągu 2 godzin, najpóźniej tego samego dnia.**

Gospodarz ma pod to ekran `/admin/bez-odpowiedzi` — i jest to dobry ekran:
sortuje najstarsze na górze, **wyróżnia pierwszy wpis danej osoby**, ma poziomy
pilności (`$wpis->pilnosc`) i liczy medianę czasu do pierwszej reakcji.

**Dowód dziury:**

```
Co da się skomentować (php artisan route:list):
  POST wpisy/{post}/komentarz               posts.comment
  POST przepisy/{recipe}/komentarz          recipes.comment
  POST ugotowane/{cookedEvent}/komentarz    cooked.comment
  POST ugotowane/{cookedEvent}/podziekuj    cooked.thank

Co widzi kolejka gospodarza (BezOdpowiedziController):
  Post::query()      ← i nic więcej
```

`grep -cE "Recipe::|CookedEvent::" app/Http/Controllers/Admin/BezOdpowiedziController.php` → **0**.

Czyli: **opublikowany przepis bez ani jednego komentarza i wykonanie
„Ugotowałem” bez reakcji są dla gospodarza niewidoczne.** Nie ma też żadnej
kolejki niepodziękowanych wykonań (`grep` po `app/Console`, `app/Jobs`,
`app/Domain` za `podziekuj|thank` → zero).

**Dlaczego to jest najgorsza z możliwych luk w tym konkretnym miejscu.**
Pominięty typ to nie jest przypadkowy trzeci obiekt. `AGENTS.md` §1 stawia
„Ugotowałem” ponad wszystkim innym, a `RETENTION_LOOPS.md` Pętla 1 —
*Ugotowałem → wzruszenie autora → odpowiedź → kolejne wykonanie* — jest
oznaczona jako ⭐ główna. Cisza pod cudzym wykonaniem boli mocniej niż cisza
pod wpisem: ktoś poświęcił popołudnie, ugotował z czyjegoś przepisu, zrobił
zdjęcie — i nikt się nie odezwał. To jest dokładnie ten moment, o którym
`COLD_START.md` pisze „50+ daje produktowi jedną szansę”.

**Zakres pracy jest mały**, bo cała mechanika już stoi: trasy komentarzy
istnieją dla wszystkich trzech typów, `pilnosc` i `pierwszeWpisyAutorow()` są
napisane, a zapytanie to ten sam kształt `whereDoesntHave('allComments', …)`.

---

### 10.2 `P2` · Nikt nie powie gospodarzowi, że ktoś przestał przychodzić

`COLD_START.md` opisuje pierwsze 20 osób jako klub prowadzony ręcznie, w którym
gospodarz ma reagować na zachowanie konkretnych ludzi. Kolumna `ostatnio_widziany_at`
istnieje i jest uczciwie utrzymywana (`AktualizujOstatniaWizyte`, `ZanotujOstatniaWizyte`).

Ale jedyny ekran panelu, który jej używa, to `/admin/uzytkownicy` — **narzędzie
moderacji** (filtry po statusie, dacie, braku wpisów; sortowanie trafia wprost
do `ORDER BY`). Ono odpowiada na pytanie „kogo zbanować”, nie na pytanie
**„kto z moich pierwszych dwudziestu nie był tu od dziesięciu dni”**.

Analityka serwerowa umie to policzyć — `PowrotPoDniach`, `AktywniWTygodniu`,
`CookRetentionCohorts` są napisane i dobre — ale liczą **kohorty**, czyli
odpowiadają właścicielowi na pytanie o produkt. Gospodarzowi potrzebna jest
**lista z imionami**, bo on ma zadzwonić, a nie zoptymalizować wskaźnik.

To jest ta sama klasa braku co 10.1: reguła istnieje w planie, dane istnieją
w bazie, a warstwy, która zamienia je w czynność jednej osoby, nie ma.

---

### 10.3 `P2` · `php artisan test --parallel`: zmierzona niestabilność 1 na 5 przebiegów

Zmierzone na tej maszynie (4 rdzenie), pięć pełnych przebiegów pod rząd:

| Przebieg | Testy | Porażki | Czas |
|---|---|---|---|
| 1 | 3829 | **2** | 173 s |
| 2 | 3829 | 0 | 173 s |
| 3 | 3829 | 0 | ~173 s |
| 4 | 3829 | 0 | ~173 s |
| 5 | 3829 | 0 | ~173 s |

Z dwóch porażek przebiegu 1 **jedna była moja** (martwe odnośniki do tras
w tym dokumencie — poprawione). Druga to
`NapiszDoNasTest::test_formularz_dziala_bez_javascriptu`: formularz „Napisz do
nas” przyszedł ze wstrzykniętym `livewire.js`, choć `layout.blade.php:299`
zamyka `@livewireStyles` i `@livewireScripts` za flagą `$livewire`, a ten ekran
jej nie podnosi.

**Czego NIE ustaliłem i nie udaję, że ustaliłem.** Postawiłem hipotezę, że
przecieka statyk `SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest`
między testami w jednym procesie — i **obaliłem ją własnym odtworzeniem**:
napisałem test, który najpierw renderuje kreator, a potem czyta oba statyki, i
oba były `false`. Mechanizm pozostaje nieznany. Porażka nie powtórzyła się
w czterech kolejnych przebiegach.

**Wniosek operacyjny, świadomie ostrożny:** to jest za mało, żeby przestawiać
CI na `--parallel` (`ci.yml:629` i `scripts/check.sh:169` chodzą szeregowo).
Jedna porażka na pięć przebiegów w bramce deployu to jedna zablokowana
publikacja na pięć. Za to lokalna pętla zwrotna skraca się z **543 s do 173 s
(3,1×)** i to jest realny zysk dla człowieka pracującego nad kodem.
`docs/PULAPKI_TESTOW.md` ma rację również tutaj: dopóki nie znamy mechanizmu,
słowo „flake” nie jest diagnozą.

---

### 10.4 `P3` · `COSTS.md` nie wycenia jedynej rzeczy, która blokuje start

`docs/COSTS.md` ma **42 linie** i jest najsłabiej rozwiniętym dokumentem
operacyjnym w repozytorium (dla porównania `docs/design/` ma 83 pliki,
`docs/marketing/` — jeden).

Konkret: `docs/infra/KOPIE_I_ODTWORZENIE.md` i D-043 stwierdzają, że Volume
Backups i PITR **istnieją wyłącznie w planie Pro**, a Kuking jest na Free/Hobby.
Bramka nr 8 z `OTWARCIE.md` — brak jakiejkolwiek kopii bazy — jest pierwszą
pozycją listy w §7 tego audytu. **A `COSTS.md` nie wymienia ani planu Pro, ani
kopii zapasowych, ani poczty.** Wymienia Free, Hobby i „Pro: wyższy próg”, bez
liczby.

Nie jest to wielkie znalezisko i nie udaję, że jest. Jest to jednak dokładnie
ten rodzaj braku, który każe właścicielowi podejmować decyzję blokującą start
bez liczby przed oczami.

---

## 11. Nowe kierunki rozwoju — z rynku, nie z głowy

### 11.1 Rok 2026 dał Kuking drugi, ostrzejszy przekaz — i okno, które się zamknie

Jesienią 2025 i w 2026 „AI slop” w przepisach stał się problemem opisywanym
przez Bloomberga, Fortune i Futurism: przepisy generowane bez żadnego
ludzkiego sprawdzenia, z błędnymi proporcjami i niebezpiecznymi krokami
(przykład z prasy: wersja świątecznego ciasta złożona przez AI kazała piec
6-calowy tort 3–4 godziny w 160°C). Dwudziestu dwóch niezależnych twórców
kulinarnych wystąpiło publicznie, że „recipe slop” niszczy ich pracę i wprowadza
ludzi w błąd.
([Fortune](https://fortune.com/2025/11/26/ai-slop-recipes-thanksgiving-food-blog-collapse-traffic),
[Bloomberg](https://www.bloomberg.com/news/articles/2025-11-25/ai-slop-recipes-are-taking-over-the-internet-and-thanksgiving-dinner),
[Futurism](https://futurism.com/artificial-intelligence/cooking-actual-ai-generated-recipes))

**Uczciwie o liczbach:** krążące „−40% rok do roku” to **pomiar jednej twórczyni**
(Eb Gargano, Easy Peasy Foodie, ruch na przepis na indyka), nie badanie branży.
Sprawdziłem tekst źródłowy: to zbiór **niezależnych samodzielnych pomiarów**
z własnych analityk — 30% (Marita Sinden, Google), 50% (ta sama osoba,
Pinterest), 80% w dwa lata (Carrie Forrest), 30% CTR (Adam Gallagher).
Zbieżność kierunku jest mocna, ale to nie jest jedna zmierzona liczba branżowa
i nie wolno jej tak cytować.

**Co to znaczy dla Kuking — i dlaczego to jest KIERUNEK, a nie ciekawostka.**
Issue #30 proponuje drugi przekaz obok społeczności: „Twoje przepisy nie zginą”
(trwałość). Rynek właśnie otworzył trzeci, ostrzejszy i trudniejszy do
podrobienia:

> **Każdy przepis tutaj ktoś naprawdę ugotował — i pokazał zdjęcie.**

To nie jest hasło do wymyślenia. To jest **opis mechaniki, która już działa
w kodzie**: `cooked_events` z `RecordCookedEvent`, zdjęciem wyniku,
`would_make_again` i `perceived_difficulty`. Żadna treściówka generowana
maszynowo nie podrobi czterdziestu prawdziwych osób, które w tym tygodniu
ugotowały ten przepis i wrzuciły fotografię swojego garnka. Konkurencja nie
może tego skopiować bez zbudowania społeczności — czyli bez zrobienia tego, co
Kuking robi od początku.

**Dlaczego okno się zamknie:** jak tylko duże serwisy kulinarne zrozumieją, że
„dowód wykonania” jest walutą zaufania, dorobią własną wersję. Kuking ma
przewagę mniej więcej tak długo, jak długo jest jedynym polskim serwisem,
w którym to jest główna akcja, a nie dodatek.

**Czego to NIE zmienia:** nie jest to powód, żeby ruszyć hasło przed bramkami
z §7 pkt 1–3. Przekaz „u nas jest prawdziwie” wymaga, żeby serwis miał kopię
bazy, tak samo jak przekaz „u nas nie zginie”.

### 11.2 „Grandmacore” jest nazwanym trendem 2026 — a zeszyt babci ma w Polsce instytucjonalne wsparcie

Polska publicystyka kulinarna nazywa w 2026 trend **„grandmacore”** — modę na
kuchnię babci, z rozróżnieniem na *nostalgię* (gotowanie wprost ze starych
receptur) i *newstalgię* (adaptowanie ich do dzisiejszych technik).
([Pożywka](https://www.pozywka.pl/mowie/trendy-kulinarne-2026-autentycznosc-zdrowie-doswiadczenie))

Mocniejsze: istnieje **finansowany ze środków publicznych projekt digitalizacji
polskich zeszytów kulinarnych** — „Babci Józi” (BaJa), mapowanie narzędziami AI
receptur spisanych odręcznie w latach 1946–1947, dofinansowanie **6 187 000 zł**
z programu Ministra Nauki „Regionalna inicjatywa doskonałości”
(RID/SP/0039/2024/01), okres realizacji **2024–2027**.
([Digital Heritage](https://digitalheritage.pl/dziedzictwo-kulinarne/))

**Co z tego wynika dla Kuking.** Trzy rzeczy, w kolejności pewności:

1. **Teza produktu ma zewnętrzne potwierdzenie.** „Rodzinne receptury, których
   szkoda stracić” to nie jest przeczucie właściciela — to jest przedmiot
   trwającego programu naukowego za sześć milionów złotych i nazwany trend
   konsumencki. `docs/PRODUCT.md` może się na to powołać zamiast na intuicję.
2. **V2 #28 (OCR starych zeszytów) ma precedens metodologiczny.** BaJa robi
   dokładnie to, co #28 opisuje, tylko na zbiorze archiwalnym. Zanim Kuking
   napisze własny OCR, warto przeczytać, co im wyszło — to jest tańsze niż
   pierwsza iteracja.
3. **To jest kandydat na partnera, nie na konkurenta.** Projekt naukowy
   digitalizuje zeszyty zmarłych; Kuking daje miejsce zeszytom żyjących.
   Zbiory się nie pokrywają, a obie strony mówią tym samym językiem.
   **Zastrzeżenie: kontaktu nie nawiązywałem i nie wiem, czy są zainteresowani.**

### 11.3 Kanały cold-startu są w planie potraktowane detalicznie, a to są sieci z federacjami

`COLD_START.md` §3.2 opisuje zdobywanie pierwszych osób przez KGW i UTW jako
pracę listową: „napisać do 15 kół w jednym regionie… odpowie 2–3”, UTW jako
„2–4 osoby”. Skala tych kanałów jest o dwa rzędy wielkości większa, niż ten
opis zakłada:

| Kanał | Skala | Źródło |
|---|---|---|
| Koła Gospodyń Wiejskich | **18 073 zarejestrowane koła** (2026) | [wykaz COIG](https://www.coig.com.pl/wykaz_lista_kola-gospodyn-wiejskich_w_polsce.php) |
| Uniwersytety Trzeciego Wieku | **125,9 tys. słuchaczy** (rok akad. 2024/2025), 747 badanych podmiotów, 526 z zajęciami regularnymi | [GUS](https://stat.gov.pl/files/gfx/portalinformacyjny/pl/defaultaktualnosci/5488/10/1/1/uniwersytety_trzeciego_wieku.pdf) |
| UTW prowadzące **zajęcia komputerowe** | **blisko 70%** UTW | GUS, jw. |

Ostatni wiersz jest tym, który zmienia plan. **Około pięciuset instytucji
w Polsce prowadzi regularne zajęcia komputerowe dla osób 60+** — a każde takie
zajęcia potrzebują materiału do ćwiczeń: czegoś polskiego, darmowego, prostego
i bezpiecznego, na czym słuchacz założy konto, wrzuci zdjęcie i napisze dwa
zdania. To jest opis ekranu głównego Kuking.

Precedens, że takie programy istnieją i mają pieniądze: **„Cyfrowe Koła
Gospodyń Wiejskich”** — warsztaty w 12 powiatach, ponad **3,7 mln zł** z Funduszy
Europejskich, tablet dla każdej uczestniczki.
([gov.pl](https://www.gov.pl/web/cyfryzacja/cyfrowe-kola-gospodyn-wiejskich--bezplatne-warsztaty-w-12-powiatach))
**Sprawdziłem datę i muszę to od razu ostudzić: ten konkretny projekt pochodzi
z listopada 2022 i nie jest dziś kanałem.** Podaję go wyłącznie jako dowód, że
publiczne pieniądze na uczenie tej grupy internetu istnieją i bywają
uruchamiane — a nie jako drogę, którą można pójść w listopadzie 2026.

**Proponowana zmiana w planie — jedna, konkretna.** Zamiast piętnastu listów do
pojedynczych kół, jedna rozmowa na poziomie federacji (Federacja UTW ma krajową
strukturę i wspólny program zajęć komputerowych). Koszt jest ten sam —
popołudnie — a przy powodzeniu daje kanał powtarzalny zamiast jednorazowego.
**Nie zmienia to reguły nadrzędnej z `COLD_START.md` §2:** nie wolno rosnąć
szybciej, niż redakcja zdąży odpowiadać, więc kanał o takiej pojemności trzeba
otwierać z zaworem, a nie na oścież. I wymaga najpierw 10.1 — bo klub, w którym
cisza zapada pod przepisami i wykonaniami, nie uniesie jednej grupy UTW, a co
dopiero federacji.

---

## 12. Zaktualizowana kolejność

Zmiany wobec §7 są dwie i obie wynikają z rundy drugiej:

- **10.1 wchodzi wysoko** — przed cold startem, bo jest jego warunkiem.
  Bez niego obietnica odzewu pilnuje jednej trzeciej treści.
- **11.1 dopisuje przekaz** do pakietu #30, nie zastępuje go.

Reszta kolejności z §7 zostaje bez zmian. Nadal obowiązuje zdanie stamtąd:
dopóki bramki 1–4 stoją otwarte, powierzchnia nie jest pracą do wykonania.

---

## 13. Metoda rundy drugiej

**Uruchomione:** `php artisan route:list`, `php artisan test --parallel`
(pięć pełnych przebiegów), `php artisan test --compact` (wielokrotnie),
`vendor/bin/pint`, `grep`/`find` po `app/`, `database/`, `config/`, `routes/`,
`resources/`, odczyt `docs/`, sześć zapytań do sieci z weryfikacją dwóch źródeł
przez pobranie pełnego tekstu.

**Czego nie robiłem:** nie wchodziłem na produkcję, nie oglądałem żadnego ekranu
w przeglądarce, nie kontaktowałem się z żadną instytucją wymienioną w §11.

**Dwie rzeczy, które sam sobie prostuję w tej rundzie:**

1. Szykowałem znalezisko „brak `aggregateRating` odcina Kuking od rich results
   Google”. **Było błędne** — `docs/seo/SEO_TECHNICAL.md:193-201` rozstrzyga to
   poprawnie i z powołaniem na wytyczne Google, których ja nie sprawdziłem
   przed postawieniem tezy. Zostawiam to w §9 jako wynik, a nie usuwam.
2. Postawiłem hipotezę o przecieku statyków Livewire jako przyczynie porażki
   `NapiszDoNasTest` i **obaliłem ją własnym odtworzeniem** (§10.3).
   Mechanizm jest nieznany i tak jest to zapisane.

---

# Runda trzecia — 15 września 2026, wieczór

Obszary nietknięte w rundach 1–2: powiadomienia i ich limity, dostępność,
waga front-endu, terminy moderacyjne, schemat bazy pod kątem indeksów.

## 14. Najpierw sprostowanie własnego znaleziska §4.1 — i jest to sprostowanie na korzyść projektu

W §4.1 napisałem, że nie ma żadnej kopii bazy, i opatrzyłem to zdaniem
o „bramkach po stronie właściciela”. **Było to prawdziwe co do stanu, ale
mylące co do kosztu** — i ta różnica zmienia priorytet.

**Mechanizm kopii jest w repozytorium napisany w całości:**

| Element | Gdzie |
|---|---|
| obraz osobnego serwisu (bez PHP) | `docker/kopia/Dockerfile` |
| zrzut `pg_dump` 18, szyfrowanie kluczem publicznym, wysyłka | `docker/kopia/kopia-bazy.sh`, `docker/kopia/s3.sh` |
| serwis `kopia-bazy`, cron `17 2 * * *`, `restartPolicyType: NEVER` | `.railway/railway.ts:955` |
| czujka „kopia przestała powstawać”, niezerowy kod wyjścia | `app/Console/Commands/SprawdzKopieBazy.php` |
| model stanu kopii | `app/Domain/Kopie/StanKopiiBazy.php` |

`docs/OTWARCIE.md` mówi to zresztą wprost w Etapie 0: *„Kod tej warstwy jest
już w repozytorium… Nie ma za to ani jednej z rzeczy, których nie da się zrobić
kodem — bucketu, tokenów, klucza i serwisu w Railway.”*

**Co to zmienia.** Bramka nr 1 z §7 nie jest projektem inżynierskim na tygodnie,
tylko **popołudniem pracy w panelach**: bucket, token, para kluczy, ręczne
założenie serwisu. Zostawiam ją na pierwszym miejscu — zero kopii to nadal zero
kopii — ale znika argument „to daleko”. To jest najtańsza rzecz o najwyższym
priorytecie w całym audycie i moja pierwsza runda niepotrzebnie sprawiła
wrażenie, że jest odwrotnie.

**Sprawdziłem też podejrzenie zakleszczenia** między wierszem 8 (kopia) a 13
(`railway config apply`, opatrzony ostrzeżeniem „nie uruchamiaj bez kopii
z wiersza 8”), skoro serwis kopii jest opisany właśnie w `railway.ts`.
**Zakleszczenia nie ma** — `OTWARCIE.md` rozstrzyga to akapit wyżej: *„Serwis
kopii zakłada się dziś ręcznie w panelu”*, bo `apply` skasowałby jedyny
działający serwis produkcyjny. Dokument był szybszy ode mnie.

---

## 15. `P1` · Najcenniejsze powiadomienie w serwisie nie ma jak wyjść poza serwis

To jest najważniejsze znalezisko całego audytu i powstaje dopiero ze złożenia
trzech rzeczy zmierzonych w trzech różnych rundach.

**AGENTS.md §1 mówi o „Ugotowałem”:** *„to on generuje najcenniejsze
powiadomienie w całym serwisie”*. `RETENTION_LOOPS.md` oznacza Pętlę 1
— *Ugotowałem → wzruszenie autora → odpowiedź → kolejne wykonanie* — gwiazdką
jako **główną**.

**Dowód, czym to powiadomienie jest w kodzie.**
`app/Domain/Notifications/Actions/NotifyUser.php` robi dokładnie jedną rzecz:

```php
return Notification::create([...]);
```

Wiersz w bazie. **Bez maila. Bez pusha.** Autor dowiaduje się, że ktoś ugotował
z jego przepisu, wyłącznie wtedy, gdy **sam z siebie wróci** na `/powiadomienia`.

**Dowód, że nie ma innej drogi.** Wszystkie osiemnaście listów, jakie ten serwis
umie wysłać (`app/Mail/` + `app/Notifications/`), to: uwierzytelnianie
(potwierdzenie adresu, reset hasła, link logowania, 2FA, zaproszenie),
moderacja i prawo (decyzje, odwołania, zgłoszenia, alarmy dla moderatora),
eksport danych, odpowiedź na wiadomość do redakcji — oraz `PodsumowanieTygodnia`.
`grep -rlnE "CookedEvent|ugotowa" app/Mail app/Notifications` zwraca
**jeden plik: `PodsumowanieTygodnia.php`**.

**Czyli cała droga „ktoś ugotował Twój przepis” poza serwis prowadzi przez
tygodniowe podsumowanie** — to samo, o którym §4.3 tego audytu ustalił, że jest
wyłączone na trzy sposoby naraz: flagą `KUKING_DIGEST_WLACZONY=false`, domyślną
zgodą `wants_weekly_digest DEFAULT false` i brakiem miejsca, w którym zwykły
człowiek tę zgodę wyrazi (ani rejestracja, ani onboarding o nią nie pytają).

**Złożenie, którego nie widać z żadnego pojedynczego miejsca:**

```
ktoś ugotował Twój przepis
        │
        ├─ wiersz w `notifications`  → zobaczysz, JEŚLI sam wrócisz
        ├─ e-mail natychmiastowy     → NIE ISTNIEJE
        ├─ web push                  → odłożony (#35)
        └─ tygodniowe podsumowanie   → najwcześniej za 7 dni,
                                        a domyślnie NIGDY (flaga + zgoda)
```

A `/admin/bez-odpowiedzi` — jedyne narzędzie, którym gospodarz mógłby tę ciszę
wychwycić ręcznie — **wykonań nie widzi w ogóle** (§10.1).

**Wniosek.** Pętla oznaczona w dokumentacji jako główna **nie domyka się dziś
dla nikogo, kto nie wraca na stronę z własnego nawyku** — czyli dokładnie dla
tej grupy, dla której `COLD_START.md` pisze „50+ daje produktowi jedną szansę”.
To nie jest błąd w kodzie; każdy element z osobna jest napisany poprawnie
i świadomie. To jest **dziura między elementami**, i dlatego nie znalazł jej
ani PHPStan, ani 3829 testów, ani pięć wcześniejszych audytów.

**Czego to NIE znaczy.** Nie proponuję cofania poprawki RODO ani dosypywania
maili. `RETENTION_LOOPS.md` §3.2 sam ustala sufit: transakcyjne maksimum
**jeden dziennie, zbiorczo** („Dziś w Kuking: Marek ugotował Twój przepis
i 2 osoby coś napisały”). Chodzi o to, żeby ten jeden istniał.

---

## 16. `P1` · Autoryzacja każdego zdjęcia to sześć skanów sekwencyjnych — dowiedzione planem zapytania

§4.6 zmierzył, że jedno żądanie zdjęcia kosztuje **6 zapytań**, i uznał ten
koszt za uzasadniony, bo to jest sama reguła (status autora, blokada).
**Ta ocena była niepełna, bo liczyła zapytania, a nie ich koszt.**

`app/Domain/Media/DostepDoZdjecia.php` pyta `UNION`-em siedem tabel o to, która
w ogóle wspomina dane zdjęcie:

```
post_media.media_id · cooked_event_media.media_id · profiles.avatar_media_id
recipes.hero_media_id · recipes.source_scan_media_id · recipe_steps.media_id
hero_picks.media_id
```

**Sześć z tych siedmiu kolumn nie ma indeksu, po którym da się szukać.**
`EXPLAIN` na żywej bazie `kuking`:

```
Seq Scan on post_media          Filter: (media_id = …)
Seq Scan on cooked_event_media  Filter: (media_id = …)
Seq Scan on recipes             Filter: ((hero_media_id = …) OR (source_scan_media_id = …))
```

Przyczyna jest subtelna i dlatego przeoczona: `post_media` i `cooked_event_media`
**mają** indeksy — ale klucz główny to `(post_id, media_id)` i
`(cooked_event_id, media_id)`, czyli `media_id` stoi na **drugiej** pozycji
i do wyszukiwania po samym `media_id` jest bezużyteczny. `recipe_steps.media_id`
i `profiles.avatar_media_id` nie mają indeksu żadnego. Jedyna tabela zrobiona
tu poprawnie to `hero_picks` (`hero_picks_media_id_unique`).

Szerzej: **24 klucze obce w tym schemacie nie mają indeksu wiodącego**
(zapytanie po `pg_constraint`/`pg_index`, pełna lista w wyjściu audytu). Większość
jest dziś nieszkodliwa; te sześć leżą na najgorętszej ścieżce, jaką ten produkt ma.

**Dlaczego to jest groźne mimo małych liczb dzisiaj.** Koszt rośnie
**z rozmiarem tabeli, nie z liczbą zapytań**. Przy 10 tys. wpisów po trzy
zdjęcia `post_media` ma 30 tys. wierszy — i tyle właśnie przeskanuje
**każde** żądanie miniatury, po wygaśnięciu 150-sekundowego cache przeglądarki
(`MediaController::sekundyCache()`). Produkt, którego główną treścią są
fotografie, ma tu wbudowaną degradację wprost proporcjonalną do własnego
sukcesu.

**Dlaczego nie złapał tego żaden istniejący test — i to jest osobna lekcja.**
Repozytorium ma wzorowe testy N+1, które wypisują LICZBY zamiast koloru
(`[N03 /odkryj] … 15 zapytan` przy dziesięciokrotnym wzroście danych).
`PomiarZapytanAutoryzacjiZdjeciaTest` robi dokładnie to samo dla zdjęć:
liczy `count($zapytania)` i pilnuje, żeby było ich sześć.

**Przy tej usterce liczba zapytań się NIE ZMIENIA.** Zostaje sześć — przy stu
wierszach i przy stu tysiącach. Rośnie tylko to, ile wierszy każde z nich
przeczyta. Świetna dyscyplina pomiarowa tego projektu ma tu dokładnie jeden
ślepy punkt: mierzy **liczbę** zapytań, nie **koszt** planu. Test, który by to
złapał, musiałby czytać `EXPLAIN` i oblewać na `Seq Scan` w tej ścieżce —
i jest to, moim zdaniem, wartościowszy kandydat na nowy test niż cokolwiek
innego w tym repozytorium.

---

## 17. `P2` · Siedem twardych limitów powiadomień istnieje w dokumencie, zero w kodzie

`RETENTION_LOOPS.md` §3.2 nosi nagłówek „Limity częstotliwości (**twarde**)”
i wymienia siedem reguł. Sprawdziłem każdą przeciwko kodowi:

| Reguła z §3.2 | W kodzie |
|---|---|
| E-maile transakcyjne: maks. 1 dziennie, zbiorczo | **nie ma** (i nie ma czego ograniczać — §15) |
| E-maile nietransakcyjne: maks. 1 tygodniowo każdy typ, łącznie 2 | **częściowo** — digest chodzi tygodniowo, drugiego typu nie ma |
| **Cisza nocna 21:00–8:00** | **nie ma** — `grep` po `app/` za godzinami i „cisza nocna” daje wyłącznie komentarze o nocnym sprzątaniu |
| In-app bez limitu, grupowane po typie | **jest** — `NotifyUser::TYPY_WYCISZANE_W_OKNIE` |
| Web Push | nie dotyczy (V1, #35) |
| Nowy użytkownik: maks. 3 e-maile w pierwszych 7 dniach | **nie ma** |
| Nieaktywny >60 dni: maks. 1 miesięcznie, po 6 miesiącach zero | **nie ma** |

**Uczciwe zastrzeżenie, bez którego to znalezisko byłoby nieuczciwe:** cztery
z tych reguł są dziś **puste z braku przedmiotu** — skoro nie ma maili
transakcyjnych (§15), nie ma czego ograniczać do jednego dziennie. To nie jest
zaniedbanie, tylko kolejność prac.

**Ale jedna z nich nie jest pusta i będzie potrzebna pierwszego dnia, gdy §15
zostanie domknięte: cisza nocna.** Grupa 50+ to ludzie, którzy kładą telefon
przy łóżku. Pierwszy mail o 23:40 z informacją, że ktoś ugotował ich przepis,
jest dokładnie tym rodzajem pierwszego wrażenia, po którym wyłącza się
powiadomienia i już nie wraca. `DziennyBudzetListow` (510 linii) tego nie
pilnuje — to jest **globalny dobowy sufit wysyłki** (ochrona kosztu i przed
nadużyciem, D-057), nie limit na osobę ani okno godzinowe. To są dwie różne
rzeczy i łatwo je pomylić po nazwie.

---

## 18. Gdzie jeszcze szukałem i nie znalazłem dziury

| Obszar | Pomiar | Wynik |
|---|---|---|
| **Waga front-endu** | zbudowane: CSS **125,21 kB → 22,50 kB gzip**, JS **7,90 kB → 3,22 kB gzip**, fonty 133 kB woff2 | **bardzo dobrze** — przy 486 kB źródeł Tailwind 4 ścina do 125 kB; to jest lekkie nawet na słabym łączu |
| **Dostępność** | `dostepnosc.mjs` (axe-core) + Lighthouse **chodzą w CI** jako osobne joby, na kilkunastu ekranach łącznie z panelem moderacji; `A11Y_CHECKLIST.md` celuje w WCAG 2.2 AA i każdy punkt ma metodę weryfikacji do 30 s | **bez zastrzeżeń** |
| **Terminy moderacyjne** | `PilnujTerminowOdwolan` + `TerminOdwolaniaBlisko` pilnują terminów odwołań automatem | **bez zastrzeżeń** |
| **Higiena poczty** | `SprawdzPoczte`, `KtoNieDostalListu`, `NieudaneListy`, `MartweZadania`, `SprawdzPiksel` (#204) | **bez zastrzeżeń** — wysyłka ma czujki na każdym etapie |
| **Zakleszczenie bramek 8↔13** | podejrzenie sprawdzone i **obalone** — `OTWARCIE.md` rozstrzyga to wprost (§14) | **dokument był szybszy ode mnie** |

---

## 19. Co zmienia się w kolejności po rundzie trzeciej

1. **§14 obniża koszt bramki nr 1**, nie jej priorytet. Kopia bazy zostaje
   pierwsza — ale jako popołudnie w panelach, nie jako projekt.
2. **§15 wchodzi tuż za bramkami infrastrukturalnymi** i **przed** wszystkim
   innym z listy produktowej. Jest warunkiem sensowności punktów 5–7 z §7
   i punktu 10.1 z rundy drugiej: klub, w którym najcenniejsza wiadomość nie
   ma jak wyjść poza serwis, nie utrzyma nikogo, komu nie wyrobiono nawyku.
3. **§16 wchodzi przed otwarciem na jakikolwiek kanał o pojemności UTW/KGW**
   (§11.3). Sześć indeksów to praca na godzinę; zrobienie tego po wpuszczeniu
   ludzi oznacza robienie tego pod ruchem.
4. **§17 (cisza nocna) jest częścią pakietu §15**, nie osobną pozycją —
   pierwszy mail transakcyjny i okno godzinowe mają powstać razem.

Reszta kolejności z §7 i §12 zostaje.

---

## 20. Metoda rundy trzeciej

**Uruchomione:** `npm run build` (pomiar wagi front-endu), `psql` z `EXPLAIN`
i zapytaniem po `pg_constraint`/`pg_index` na bazie `kuking`, `grep`/`find`
po `app/`, `docker/`, `.railway/`, `scripts/`, `.github/`, odczyt `docs/`.

**Czego nie robiłem:** nie zmieniałem ani jednej linijki kodu produkcyjnego
w tej rundzie, nie wchodziłem na produkcję, nie oglądałem żadnego ekranu
w przeglądarce, nie mierzyłem niczego pod realnym ruchem — bo ruchu nie ma.

**Granica znaleziska §16, którą trzeba znać:** `EXPLAIN` wykonałem na bazie
**deweloperskiej, prawie pustej**. Plan `Seq Scan` przy kilkudziesięciu
wierszach jest normalny i sam w sobie niczego nie dowodzi — dowodem jest
**brak indeksu użytecznego dla tego warunku**, sprawdzony osobno w `pg_indexes`
i w definicjach kluczy głównych. Pomiaru czasu przy realnym wolumenie nie ma
i go nie udaję; przewidywanie degradacji opieram na strukturze, nie na
stoperze.

**Trzecie sprostowanie własnej pracy w tym audycie** (po `aggregateRating`
i hipotezie o statykach Livewire): §4.1 rundy pierwszej sugerował, że kopii
bazy nie ma, bo nie została napisana. Została napisana w całości. §14 prostuje
to jawnie i zostawia oba zdania obok siebie, zamiast po cichu podmieniać
pierwsze.
