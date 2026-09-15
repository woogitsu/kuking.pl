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
   w `Settings`). **Formularz rejestracji o to nie pyta i onboarding `/witaj/*`
   też nie** — `register.blade.php:86` tylko *wspomina* o przeglądzie w tekście.
4. Web Push odłożony (#35), PWA nie ma sklepu, feed jest chronologiczny.

**Skutek, złożony do kupy.** Osoba 63-letnia zakłada konto, publikuje obiad —
i nie ma **żadnego** mechanizmu, który sprowadzi ją z powrotem. Nie dostanie
maila (flaga off + zgoda off), nie dostanie pusha (#35), nie ma ikony na ekranie
(#278 otwarte), a adresu `kuking.pl` nie zapamięta. `COLD_START.md` sam nazywa
to najdroższym możliwym błędem: „50+ daje produktowi jedną szansę”.

**To nie jest argument za cofnięciem poprawki RODO.** Poprawka jest słuszna.
Argument jest odwrotny: skoro zgoda ma być wyraźna, to **trzeba o nią wyraźnie
zapytać** — niezaznaczony haczyk w rejestracji albo krok w `/witaj/*`
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
| 4 · Temat tygodnia | MVP | **częściowo** | `TagPromotion` + `/tagi-promowane`; „tematy” skasowane migracją `2026_09_07_300000_drop_topics` |
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
   albo w `/witaj/*` (§4.3).
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
