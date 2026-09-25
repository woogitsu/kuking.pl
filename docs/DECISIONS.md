# Dziennik decyzji

Decyzje, które **zostały podjęte** i których nie należy otwierać na nowo bez
nowej informacji. Każdy agent AI i każda osoba dołączająca do projektu czyta
ten plik, żeby nie proponować rzeczy już rozstrzygniętych.

Format: co, kiedy, kto zdecydował, dlaczego, i **co musiałoby się stać**,
żeby decyzję zmienić.

> ### Numeracja przeskakuje D-108 … D-112 — i to jest celowe
>
> System projektowy w `docs/design/system-v3.1/` ma **własny, niezależny
> dziennik** z numerami `D-101 … D-112`. Pięć z nich (D-103 … D-107) zajmuje
> już oba dzienniki naraz i cytat „D-105" znaczy co innego w jednym, a co
> innego w drugim. Numery są darmowe, a odplątywanie takiej dwuznaczności
> po fakcie nie jest — więc dziennik główny przechodzi z **D-107 od razu na
> D-113**, czyli pierwszy numer wolny w obu miejscach.
>
> **Numery 108 – 112 w TYM pliku zostają na zawsze puste.** Nie są luką do
> uzupełnienia; są odstępem od cudzej numeracji. Osobno i wcześniej puste
> są **D-084, D-086 i D-094**.
>
> Decyzje systemu projektowego cytujemy z nazwą jego dziennika
> („system-v3.1 D-111"), nigdy samym numerem.

---

## D-001 · Modularny monolit Laravel, bez mikroserwisów

**Data:** wrzesień 2026 · **Decyzja właściciela (blueprint)** · Status: **obowiązuje**

Największym ryzykiem pierwszych miesięcy nie jest skala serwera, tylko pusta
społeczność i słaba retencja. Monolit zmniejsza liczbę ruchomych części.

**Zmiana wymaga:** zmierzonego problemu wydajnościowego, którego nie da się
rozwiązać w monolicie. Nie „przewidywanego".

📄 `ARCHITECTURE.md`

---

## D-002 · Testy na PostgreSQL, nigdy na SQLite

**Data:** wrzesień 2026 · Status: **obowiązuje**

Schemat używa indeksów częściowych, `num_nonnulls()`, `gen_random_uuid()`,
`pg_trgm` i `unaccent`. Test na SQLite przechodziłby, nic nie sprawdzając —
a to jest gorsze niż brak testu, bo daje fałszywe poczucie bezpieczeństwa.

**Zmiana wymaga:** rezygnacji z tych mechanizmów w schemacie. Czyli: nie.

📄 `TESTING.md` · `phpunit.xml`

---

## D-003 · Własny model `media` zamiast Spatie MediaLibrary

**Data:** wrzesień 2026 · Status: **obowiązuje**

MediaLibrary nie obsługuje cyklu życia z moderacją, którego potrzebujemy:
`pending → processing → ready | rejected`, checksuma, hash percepcyjny,
re-enkodowanie zdejmujące EXIF/GPS przed pokazaniem zdjęcia komukolwiek.

**Zmiana wymaga:** wykazania, że pakiet obsługuje ten cykl bez obchodzenia go
własnym kodem.

📄 `research/PUBLIC_REPOS.md` · `app/Models/Media.php`

---

## D-004 · Wyszukiwarka na PostgreSQL, bez Scout i bez osobnego silnika

**Data:** wrzesień 2026 · Status: **obowiązuje**

`pg_trgm` + `unaccent` radzą sobie z literówkami i brakiem polskich znaków
lepiej niż stemming, którego dla polskiego w Postgresie po prostu nie ma.
„zurek" znajduje „żurek".

**Zmiana wymaga:** przekroczenia SLA wyszukiwania przy realnym ruchu.

📄 `ARCHITECTURE.md` · `app/Domain/Search/SearchQuery.php`

---

## D-005 · Brak `UNIQUE (user_id, recipe_id)` w `cooked_events`

**Data:** wrzesień 2026 · Status: **obowiązuje, nienaruszalne**

Ta sama osoba gotuje ten sam przepis co dwa tygodnie od dziesięciu lat
i każde takie wykonanie jest osobnym, wartościowym wydarzeniem. Dodanie
unikalności zepsułoby sedno produktu.

**Zmiana wymaga:** zmiany istoty produktu. Nie zmieniamy.

📄 `DATABASE.md` · `database/migrations/2026_09_05_000600_*`

---

## D-006 · `status` i `role` użytkownika poza `$fillable`

**Data:** wrzesień 2026 · Status: **obowiązuje**

> **Uzupełnienie z 20 września 2026 (audyt rejestru).** Reguła jest dziś
> TRZYelementowa, nie dwuelementowa. Commit `ed6cbf00` (19 września) wyjął
> `posts.kind` z `$fillable` i nazwał je polem STERUJĄCYM „tej samej rodziny
> co `users.status` i `users.role`": `kind` rozstrzyga, czy wpis jest daniem,
> czy pytaniem, a przez to do których strumieni trafia (`scopeEnabledKinds`),
> pod jakim adresem stoi (`url()`) i co przepuści `PostPolicy`. Jedyną drogą
> jest nazwana metoda `Post::oznaczJakoPytanie()`. Tytuł i treść tego wpisu
> mówią o dwóch kolumnach i nie zostały przepisane — reguła obejmuje trzy.

Zmiana stanu konta jest zawsze jawną, nazwaną operacją: `suspend()`, `ban()`,
`markForDeletion()`, `promoteTo()`. To zamyka drogę do przejęcia uprawnień
przez dołożenie pola do formularza.

Ta decyzja została podjęta **po znalezieniu realnego błędu**: usunięcie konta
nie działało, bo `update(['status' => ...])` było ciche.

**Zmiana wymaga:** niczego. To jest zabezpieczenie, nie preferencja.

📄 `AGENTS.md` §7 · `app/Models/User.php` · `tests/Feature/SecurityTest.php`

---

## D-007 · Ważne funkcje działają bez JavaScriptu

**Data:** wrzesień 2026 · Status: **ZMIENIONE PRZEZ D-053 (9 września 2026)**

> **Uwaga.** Ta decyzja nie obowiązuje już w brzmieniu poniżej. Formularze
> chronione captchą (rejestracja, logowanie, odzyskanie hasła, cofnięcie
> usunięcia konta, „Napisz do nas", zgłoszenie nielegalnej treści) **wymagają
> JavaScriptu**. To, co z D-007 zostało — zakaz zostawiania człowieka przed
> przyciskiem, który po kliknięciu milczy — jest w **D-053**. Treść poniżej
> zostaje, bo tłumaczy, skąd ta reguła się wzięła i co dokładnie zostało
> zmienione.

Rejestracja, logowanie, publikacja wpisu, przepis, komentarz i „Ugotowałem"
muszą działać przy niewczytanym skrypcie. Powód nie jest ideologiczny: przy
słabym zasięgu skrypt się nie dociąga, a użytkownik zostaje z formularzem,
który nic nie robi po kliknięciu. Dla osoby niepewnej, „czy dobrze klika",
to koniec korzystania z serwisu.

JavaScript jest **ulepszeniem** — podgląd zdjęcia, autosave, timery.

**Zmiana wymaga:** danych pokazujących, że nasi użytkownicy nie mają tego problemu.

📄 `AGENTS.md` §5

---

## D-008 · `kuKING` to nazwa mieszkańca, nie komplement

**Data:** wrzesień 2026 · **Decyzja właściciela** · Status: **obowiązuje**

W słowie Ku-KING siedzi KING i gramy tym — ale żart jest **o nazwie serwisu**,
nigdy o użytkowniku.

- ✅ „Zostań kuKINGiem", „kuKINGi na dziś", „2 431 kuKINGów"
- ❌ „Jesteś prawdziwym kuKINGiem!", „Top kuKINGi tygodnia"

Uzasadnienie produktowe: ponad połowa osób 50+ w mediach społecznościowych
nigdy nic nie publikuje. Komplement za publikację **podnosi** poprzeczkę,
nazwa przynależności ją **obniża**.

Rozstrzyga to też pozorną sprzeczność z zakazem z `brand/MASCOT_CONCEPT.md`
(„nigdy «Jesteś królem kuchni!»") — zakaz dotyczy komplementu, nie nazwy.

📄 `brand/COPY_STYLE.md` §2

---

## D-009 · Dawka gry słowem: umiarkowana

**Data:** 5 września 2026 · **Decyzja właściciela** · Status: **zmienione przez D-145** (limit „raz na ekran") **i D-147** (odrzucenie czasownika). Lista miejsc zakazanych zostaje w mocy.

Wybrana spośród trzech przedstawionych wariantów (minimalna / umiarkowana / mocna).

`kuKING` pojawia się w **3-4 miejscach**: rejestracja, tablica „kuKINGi na dziś",
licznik społeczności, digest. **Maksymalnie raz na ekran.**

**Nigdy** w: komunikacie błędu, wiadomości moderacyjnej, tekście prawnym,
powiadomieniu o cudzej aktywności, formularzu w trakcie wypełniania.

Odrzucone świadomie: `kuKINGujesz` (nowy czasownik wymaga zrozumienia,
a nasz odbiorca nie lubi zgadywać), `Mój kuKING` w nawigacji,
forma żeńska (żadna nie brzmi po polsku dobrze).

**Zmiana wymaga:** reakcji realnych użytkowników w testach (#15).

📄 `brand/COPY_STYLE.md`

---

## D-010 · CI na runnerach GitHuba, repozytorium w nowej organizacji

**Data:** 5 września 2026 · **Decyzja właściciela** · Status: **zmienione przez D-028
w części dotyczącej runnerów** (organizacja i prywatność repozytorium zostają)

> **Adnotacja z 20 września 2026 (audyt rejestru).** Sekcja „Stan wykonania"
> opisuje świat, którego już nie ma. Pozycja „pierwszy zielony przebieg —
> `main` to dziś pusty commit inicjalizacyjny, więc GitHub nie widzi żadnego
> workflow" jest nieaktualna: `main` stoi na `61686213` z historią do PR #731,
> a cztery workflow-y leżą w `.github/workflows/` na tej gałęzi. Nieaktualne
> jest też uzasadnienie przy „zmienna `CI_RUNNER` usunięta": po drugiej
> poprawce z D-028 `runs-on` czyta `CI_RUNS_ON`
> (`.github/workflows/ci.yml:167`), czyli inną zmienną niż ta, o której mówi
> tamto zdanie. Reszta wpisu — organizacja `woogitsu`, prywatność
> repozytorium, wyzwalacze — ma pokrycie.

> **Zmiana wcześniejszej decyzji.** Pierwotnie: własny self-hosted runner.
> Powód zmiany: plan Free daje **2 000 minut miesięcznie także dla repozytoriów
> prywatnych**, a pula jest liczona per konto. Właściciel wykorzystał ją na inny
> projekt, więc zakłada **nową organizację** — Kuking dostaje wtedy pełny,
> nieużywany limit.

Repozytorium zostaje **prywatne** i przenosi się pod nową organizację.
CI chodzi na standardowych runnerach GitHuba.

Szacunek zużycia — **skorygowany po pomiarze**: pierwotnie zakładaliśmy 4-8 minut
na przebieg (czas `./scripts/check.sh`), co dawało 250-500 przebiegów. Zła
jednostka: GitHub nalicza **per job, zaokrąglając każdy w górę do minuty**,
a mamy sześć równoległych jobów. Zmierzone: **≈10 minut na przebieg**, czyli
**około 200 przebiegów miesięcznie**. Zapas nadal jest, ale mniejszy niż
zapisano. Rozbicie na joby: `infra/CI_BEZ_ACTIONS.md`.

Własny runner **zostaje jako plan awaryjny**, nie jako droga podstawowa.
Instrukcja pozostaje w repozytorium (`infra/SELF_HOSTED_RUNNER.md`) i jest
aktualna — przyda się, gdyby limit organizacji też się skończył albo gdyby
przebiegi zrobiły się długie.

⚠️ **Kolejność ma znaczenie:** `KUKING_WAIT_FOR_CI=true` w `railway.ts`
włączamy **dopiero po** pierwszym zielonym przebiegu CI. Wcześniej Railway
czekałby na check suite, który nie powstaje, i nic by się nie zdeployowało.

**Stan wykonania:**

- ✅ organizacja `woogitsu`, transfer repozytorium, zachowane numery issues
- ✅ `git remote` i wszystkie odwołania w repozytorium na `woogitsu/kuking.pl`
- ✅ automatyczne wyzwalacze w `.github/workflows/ci.yml` (`push` i
  `pull_request` na `main` i `staging`)
- ⬜ **pierwszy zielony przebieg** — wymaga, żeby workflow znalazł się na
  gałęzi domyślnej; `main` to dziś pusty commit inicjalizacyjny, więc do
  czasu scalenia GitHub nie widzi żadnego workflow
- ⬜ zmienna repozytorium `CI_RUNNER` **usunięta** (ustawienia GitHuba, nie
  plik w repozytorium) — po D-028 nie czyta jej już żaden workflow
- ⬜ ochrona gałęzi `main` wymagająca zielonego CI
- ⬜ `KUKING_WAIT_FOR_CI=true` — **na samym końcu**

**Zmiana wymaga:** wyczerpania limitu nowej organizacji albo potrzeby
kontroli nad środowiskiem, której runnery GitHuba nie dają.

📄 `infra/CI_BEZ_ACTIONS.md` · `infra/SELF_HOSTED_RUNNER.md` (plan B) · issue #4

---

## D-011 · Deploy odłożony, praca idzie w kodzie

**Data:** 5 września 2026 · **Decyzja właściciela** ·
Status: **NIEAKTUALNE — serwis JEST na produkcji (zmierzone 7 września 2026)**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Ramka ostrzegawcza mówi,
> że `docs/infra/DEPLOYMENT_RUNBOOK.md` §2.3 i §7 „dalej KAŻĄ" utworzyć
> `cdn.kuking.pl` z regułą „Cache Everything". To zostało naprawione:
> `docs/infra/DEPLOYMENT_RUNBOOK.md:227-247` niesie wycofanie rozdziału i
> zdanie „**Zamiast tego kroku: nic.**", stara instrukcja zjechała do bloku
> opisanego jako „do czytania, NIE do wykonywania", a
> `tests/Feature/RunbookNieKazeTworzycDomenyZdjecTest.php` pilnuje tego
> maszynowo. Zostawione bez adnotacji ostrzeżenie szkodzi dokładnie tak, jak
> ten wpis to opisuje: następna osoba „naprawi" rzecz już naprawioną. Sam
> status NIEAKTUALNE potwierdzony 20 września 2026 — `GET
> https://kuking.pl/health` odpowiada 200.

> **UWAGA, TA DECYZJA JUŻ NIE OPISUJE RZECZYWISTOŚCI.** `https://kuking.pl`
> odpowiada HTTP/2 200 z `server: cloudflare` i pełnym zestawem nagłówków
> bezpieczeństwa tej aplikacji (własne CSP z nonce, `kuking-session`).
> Zgłoszenia właściciela z 6 września — ciemny motyw na telefonie i strona
> „Za dużo prób" przy dodawaniu zdjęcia — pochodzą więc z produkcji, nie
> z lokalnego środowiska.
>
> **Dlaczego to jest zapisane, a nie po prostu skasowane:** ta nieaktualność
> ma konsekwencje. Agent analizujący storage oparł na niej wniosek, że
> `cdn.kuking.pl` „na pewno jeszcze nie istnieje", a `docs/infra/
> DEPLOYMENT_RUNBOOK.md` §2.3 i §7 dalej KAŻĄ tę domenę utworzyć razem
> z regułą „Cache Everything" na 30 dni — czyli odtworzyć lukę zamkniętą
> przez D-020. Patrz ostrzeżenie dopisane w runbooku.
>
> Zmierzone przy okazji: `cdn.kuking.pl` dziś **nie odpowiada** (tak samo jak
> nieistniejący `www.kuking.pl`), więc luka z issue #120 najprawdopodobniej
> nie jest otwarta — ale potwierdzić to musi właściciel z panelu Cloudflare,
> bo pomiar z kontenera roboczego nie odróżnia „host nie istnieje" od
> „proxy nie przepuściło".
>
> Właściciel powinien zamknąć albo przepisać tę decyzję i issue #3.

Pierwszy deploy (#3) czeka. Praca skupia się na funkcjach, które nie wymagają
produkcji.

**Zablokowane przez tę decyzję:** #3 (deploy), #33 (Sentry, PostHog, uptime),
#9 (restore drill), #7 w części dotyczącej realnego ruchu, #15 (testy
z użytkownikami — potrzebują strony pod adresem).

**Zmiana wymaga:** decyzji właściciela o założeniu kont.

📄 `infra/DEPLOYMENT_RUNBOOK.md` · issue #3

---

## D-012 · Tryb zamkniętej alfy, bez publicznej bety

**Data:** 5 września 2026 · **Decyzja właściciela** · Status: **obowiązuje do odwołania**

Nie ma jeszcze osoby, która da 2 godziny dziennie przez pół roku na
komentowanie każdego wpisu. Research mówi wprost: **lepiej zostać w trybie
20 osób niż uruchomić betę bez odzewu**, bo wpis bez żadnej reakcji kończy
korzystanie z serwisu.

To jest decyzja rozsądna, nie porażka. Zamknięta alfa z 20 osobami daje
prawdziwe dane.

**Czeka:** #29 (cold start do 200 i 2000), #15 (testy z 13 osobami 50+),
publiczny start. #37 (tablica „kuKINGi na dziś") budujemy, ale do czasu
realnych treści pokazuje pusty stan.

📄 `product/COLD_START.md` · issue #29

---

## D-013 · „kuKINGi na dziś" zostaje, z weryfikacją w testach

**Data:** 5 września 2026 · **Decyzja właściciela** · Status: **obowiązuje warunkowo**

Research językowy zgłosił realne zastrzeżenie: dla **rzeczownika osobowego**
forma `kuKINGi` jest w polszczyźnie **deprecjatywna** — ta sama, która daje
„profesory" i „chłopy", a Poradnia PWN pisze, że służy „wyrażaniu oceny
negatywnej".

Nazwa zostaje, bo kontrargument też jest mocny:

- `kuKING` jest równocześnie nazwą **rzeczy**, nie tylko osoby — a dla
  rzeczowników nieosobowych `-ing → -ingi` jest formą całkowicie zwyczajną
  („mityng → mityngi", „leasing → leasingi");
- tablica pokazuje **ludzi i dania obok siebie**, więc odczyt „rzeczy warte
  zobaczenia" jest naturalny;
- `brand/COPY_STYLE.md` §2 od początku definiuje `kuKINGi` wyłącznie
  w znaczeniu rzeczy.

**Warunek:** rozstrzygamy to na realnych ludziach w testach z osobami 50+
(issue #15), jednym pytaniem: *„o czym jest ta sekcja?"*. Jeśli ktokolwiek
odczyta to jako lekceważące określenie ludzi — zmieniamy.

Przygotowane alternatywy, gdyby test wypadł źle: **„Dziś u kuKINGów"**
(dopełniacz mnogi nie jest formą deprecjatywną, gra słowem zostaje) albo
**„Co się dziś gotuje"** (nie odmienia słowa wcale).

Koszt zmiany: jedna linijka w `components/kuking-board.blade.php`.

📄 `brand/COPY_STYLE.md` §5 · `decyzje/KUKING_JEZYK.md` · issue #15, #37

---

## D-014 · Nie budujemy API „pod przyszłą aplikację mobilną"

**Data:** 5 września 2026 · **Propozycja do zatwierdzenia** · Status: **do decyzji właściciela**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Konkluzja — „nie budujemy
> API" — obowiązuje i ma pokrycie: nie ma `routes/api.php` ani Sanctuma w
> `composer.json`. Nieprawdziwy jest POMIAR, na którym ten wpis stoi. Zdanie
> „logika biznesowa nie siedzi w kontrolerach, tylko w 14 Akcjach w 11
> modułach domenowych… kontrolery mają 109–146 linii (najgrubszy 329)" nie
> opisuje dzisiejszego kodu: Akcji jest 43, a
> `app/Http/Controllers/HealthController.php` ma 858 linii,
> `RecipeController.php` 827, `PostController.php` 749 — czyli 2,5× więcej niż
> deklarowany „najgrubszy 329". Zobowiązanie „trzymamy dyscyplinę Akcji:
> logika nigdy nie wycieka do kontrolerów ani do Blade" przestało być podparte
> liczbami, które ten wpis przytacza jako swój dowód.

Pytanie z rozmowy: skoro kiedyś powstanie wersja mobilna, czy nie pisać już
teraz API, żeby potem było gotowe?

**Odpowiedź: nie — bo ubezpieczenie, o które chodzi, już istnieje.**

### Dlaczego to nie jest ryzyko, na które trzeba płacić z góry

Logika biznesowa nie siedzi w kontrolerach, tylko w **14 Akcjach w 11 modułach
domenowych** (`app/Domain/*/Actions/`). Kontrolery mają 109–146 linii
(najgrubszy 329) i tylko wołają Akcje.

Kontroler HTML jest więc **adapterem, nie logiką**. API to drugi adapter nad
tymi samymi Akcjami — nie przepisywanie aplikacji. Różnica między „dni"
a „miesiące". Haczyk jest już wpięty: `bootstrap/app.php` renderuje błędy jako
JSON dla `api/*`.

### Dlaczego budowanie go teraz byłoby błędem

1. `AGENTS.md` zabrania dodawania bez zmierzonej, udokumentowanej potrzeby.
   API na zapas to podręcznikowe naruszenie tej zasady.
2. **API bez konsumenta rozjeżdża się z rzeczywistością.** Nikt go nie wywołuje,
   więc nikt nie zauważa, że przestało działać. Po roku jest to powierzchnia,
   której nie da się zaufać — i tak pisana od nowa.
3. Wersjonowanie, osobne testy, osobna autoryzacja: koszt od pierwszego dnia,
   korzyść kiedyś.

**SPA odpada osobno:** `AGENTS.md` wymaga, żeby ważne funkcje działały bez
JavaScriptu, a przy grupie 50+ to nie jest kaprys.

### Co robimy zamiast tego

Trzymamy dyscyplinę Akcji: **logika nigdy nie wycieka do kontrolerów ani do
Blade**. Dopóki „opublikuj wpis" jest Akcją, a nie sześćdziesięcioma liniami
w kontrolerze, API pozostaje decyzją, a nie przepisywaniem. To jedyny koszt
i wynosi zero — tak już jest napisane.

Gdy przyjdzie czas: `routes/api.php` + Laravel Sanctum (tokeny zamiast sesji)
+ kontrolery API nad tymi samymi Akcjami.

### Warunek, przy którym wracamy do tematu

Stack wybiera **PWA** (`AGENTS.md`), a `ROADMAP.md` pkt 11 planuje manifest
i service worker. Dla większości to wystarczy: ikona na ekranie głównym,
aparat, offline, powiadomienia.

PWA nie załatwia jednak jednej rzeczy i trzeba to nazwać wprost:
**„Dodaj do ekranu głównego" jest dla osoby po sześćdziesiątce trudniejsze niż
„pobierz z Play"**. To argument dystrybucyjny, nie techniczny.

**Wracamy do tej decyzji, gdy dane pokażą, ilu ludzi nie kończy instalacji
PWA.** To jest ta „zmierzona potrzeba" z `AGENTS.md` — nie przeczucie, tylko
liczba z PostHoga. Wtedy natywna skorupka może mieć sens, a API pod nią
powstanie nad istniejącymi Akcjami.

📄 `AGENTS.md` (tabela stacku, §12, zakaz overengineeringu) ·
`docs/ROADMAP.md` pkt 11 · `app/Domain/*/Actions/`

---

## D-015 · Logotyp brzmi „KuKing.pl", teksty dalej piszą „Kuking"

**Data:** 6 września 2026 · **Decyzja właściciela** · Status: **zmienione przez D-145** — tekst ciągły pisze dziś `kuKING` dwukolorowo, a akcent koloru w samym logotypie leży wyłącznie na „King" (PR #394). Rozróżnienie logotypu od zapisu w zdaniu zostaje w mocy.

Wybrana spośród trzech wariantów zapisu w logotypie: `KUKING` (stan poprzedni),
`KuKing.pl` (UI kit v2) i `Kuking.pl`.

W repozytorium żyły równolegle **trzy** zapisy nazwy: belka u góry pokazywała
`KUKING`, teksty na stronie mówiły „Kuking" („Świeżo z Kuking", „Głos Kuking"),
a użytkownika nazywamy `kuKING` (D-009). Do tego przysłany UI kit dokładał
czwarty: `KuKing.pl`.

**Rozstrzygnięcie: logotyp to `KuKing.pl`, z `.pl` w kolorze marki.**
Wersalik w środku jest częścią znaku, nie zasadą ortograficzną.

**W tekście ciągłym nadal piszemy `Kuking`** — „Świeżo z Kuking", „zasady
Kuking". Logotyp rządzi się swoim prawem, tak jak eBay czy iPhone na początku
zdania. Zapis `kuKING` o człowieku zostaje bez zmian (D-009).

**Dlaczego nie ujednolicamy wszystkiego do jednego zapisu:** logotyp ma być
rozpoznawalny, a tekst czytelny. To dwie różne prace i wymaganie od nich tego
samego zapisu psuje jedną z nich. Zapis `KUKING` wersalikami w zdaniu czyta się
jak krzyk, a `KuKing` w środku akapitu wygląda na literówkę.

**Cena:** ktoś, kto zna serwis z logotypu, napisze w wyszukiwarce „KuKing".
Domena i tak jest jedna, a wyszukiwarki nie rozróżniają wielkości liter.

📄 `docs/brand/COPY_STYLE.md` §2 · `resources/views/components/layout.blade.php` ·
D-009

---

## D-016 · Odwołanie składa się w produkcie, formularzem zamkniętym hasłem

**Data:** 6 września 2026 · Status: **obowiązuje** · issues #10, #65

> **Adnotacja z 20 września 2026 (audyt rejestru).** Ograniczenie cytowane w
> sekcji „Ile razy: raz od jednej decyzji" — `UNIQUE
> (appeals.moderation_action_id)` — zostało zdjęte tego samego dnia:
> `database/migrations/2026_09_07_800000_appeals_open_to_reporters.php:127-128`
> robi `DROP CONSTRAINT appeals_moderation_action_id_unique` i zakłada `UNIQUE
> (moderation_action_id, appellant)`. Od jednej decyzji moderacyjnej mogą więc
> powstać DWA odwołania — autora i zgłaszającego. Druga nieścisłość jest w
> tytule: zgłaszający składa odwołanie podpisanym linkiem
> (`routes/web.php:567-569`, middleware `signed`), a hasło jest bramką
> wyłącznie na ścieżce autora
> (`app/Http/Controllers/AppealController.php:116-130`). Reszta wpisu —
> karencja 24 h, `previous_status`, powrót do szkicu — ma pokrycie. Słowo
> `appellant` nie pada w tym dzienniku ani razu poza tą adnotacją.

Ścieżka odwołania (DSA art. 17 i 20) mogła pójść jedną z trzech dróg. Wybór
zapadł tak, a nie inaczej, i obie odrzucone drogi miały realne zalety.

### Co odrzucono

**Sam adres e-mail.** Nic nie kosztuje i działa dla każdego, także dla kogoś,
kto zapomniał hasła. Cena jest jednak taka, że odwołania nie ma w logu, nikt
nie wie, ile ich leży ani od kiedy, terminu z podręcznika (7 dni roboczych)
nie da się pilnować, a odpowiedź nie trafia do produktu. Przy audycie zostaje
zdanie „odpowiadamy na maile" i nic więcej.

**Formularz publiczny bez żadnej bramki.** Dostępny dla zablokowanych, ale
otwarty na oścież — czyli gotowy kanał do wpisywania czegokolwiek prosto
w kolejkę jedynego moderatora (D-012: zespół to 1–2 osoby).

### Co wybrano

Formularz **w produkcie**, a dla osób zablokowanych ten sam formularz **przed
logowaniem, zamknięty loginem i hasłem**. Sprawdzenie hasła nie loguje nikogo
i nie zdejmuje blokady — służy wyłącznie przypisaniu sprawy do konta.

**Cena, wprost:** kto zapomniał hasła, nie złoży odwołania tą drogą. Zostaje mu
„Nie pamiętam hasła" (działa też przy koncie zablokowanym) albo adres e-mail,
który zostaje jako droga zapasowa i jest wypisany na obu formularzach.
Odwołanie z e-maila moderator wprowadza ręcznie.

### Ile razy: raz od jednej decyzji

`UNIQUE (appeals.moderation_action_id)`. DSA art. 20 wymaga dostępu do
wewnętrznego rozpatrzenia skargi, nie nieskończonej liczby instancji, a bez
limitu jedna sprawa potrafi zająć jedynego moderatora na tydzień. Nowe
okoliczności idą adresem e-mail.

### Karencja zamiast „ktoś inny"

Playbook chce, żeby odwołanie oceniał ktoś inny niż pierwotny decydent. Przy
jednej osobie to jest nie do wyegzekwowania, więc system egzekwuje to, co da
się spełnić: **ten sam moderator nie podtrzyma własnej decyzji przez 24
godziny**. Cofnąć własną decyzję może natychmiast — karencja ma powstrzymać
odruchowe „podtrzymuję", a nie przyznanie się do pomyłki.

### Przywracanie treści wraca do stanu SPRZED ukrycia

Nie na sztywno do `published`. Stan sprzed decyzji zapisuje
`moderation_actions.previous_status` — w logu moderacji, nie w tabelach
z treścią (uzasadnienie: `docs/DATABASE.md`). Gdy stanu nie znamy, treść wraca
jako **szkic**: pomyłkę w tę stronę autor cofa jednym kliknięciem, pomyłki
w drugą — upublicznienia cudzego szkicu — nie cofnie nikt.

**Zmiana wymaga:** danych o tym, ile odwołań przepada na barierze hasła, albo
zmiany wielkości zespołu moderacji (wtedy karencja przestaje być potrzebna).

📄 `docs/MODERATION.md` · `docs/legal/MODERATION_PLAYBOOK.md` §3 ·
`config/kuking.php` → `kuking.moderation` · `app/Domain/Moderation/`

---

## D-017 · Przepis zostaje wolnym tekstem; to kit dopasowuje się do danych

**Data:** 6 września 2026 · Status: **częściowo nieaktualna — patrz D-033**
· issues #44, #92, UI kit v2 etap C

> **Poprawka z 8 września.** Zdanie „składniki z kolumną ilości: nie i nie
> będzie" **przestało być prawdziwe dzień po napisaniu**:
> `recipe_ingredients` ma `quantity`, `unit_id` i `no_amount` od 5 września.
> Aktualny stan: ilość JEST, skalowania porcji nie ma, grup składników nie ma
> — a właściciel przyjął oba do zbudowania (D-033). Reszta tego wpisu, czyli
> zasada „kit dopasowuje się do danych, nie odwrotnie", obowiązuje dalej.

Ekran przepisu w UI kicie v2 rysuje składniki jako wiersze **nazwa + ilość**
w dwóch kolumnach, a kroki z **pogrubionymi tytułami**. Produkt tych danych
świadomie NIE zbiera: formularz przepisu mówi „Pisz tak, jak mówisz:
«szklanka mąki»", a kroki są zwykłym tekstem bez nagłówka.

Zbudowanie układu z kitu oznaczało więc jedno z dwojga: albo udawać strukturę,
której nie ma, albo zmienić to, o co pytamy człowieka. Właściciel rozstrzygnął.

### Co odrzucono

**Rozbicie składnika na ilość i nazwę w formularzu.** Ma realną zaletę:
odblokowuje skalowanie porcji i zamienniki (AGENTS.md §9) oraz zamyka #44
(„sól do smaku nie skaluje się razy trzy"). Cena jest jednak dokładnie tam,
gdzie produkt najmniej może sobie na nią pozwolić — w pierwszym formularzu,
który wypełnia osoba przepisująca zeszyt babci. „Szczypta soli", „tyle, żeby
ciasto było miękkie" i „pół szklanki, ale mama dawała więcej" nie mają pola
na ilość. Formularz, który każe je rozbić, każe też zdecydować, czego nie
zapisać — a to jest odwrotność obietnicy „Twoje przepisy nie zginą".

**Wpisanie struktury na siłę do widoku.** Rysowanie kolumny „ilość" wypełnianej
zgadywanką z tekstu daje ekran, który wygląda jak kit i kłamie w połowie
wierszy. Gorzej: kłamie akurat tam, gdzie autor był najbardziej precyzyjny.

### Co wybrano

**Model wpisywania zostaje bez zmian.** Układ ekranu przepisu budujemy według
kitu — panel boczny ze zdjęciem, kafle liczb, akcje, „Skąd ten przepis?" —
ale **składniki idą jako czytelna lista bez kolumny ilości**, a kroki jako
numerowane akapity bez wymyślonych tytułów.

**Cena, wprost:** ekran przepisu nie będzie wyglądał jeden do jednego jak
plansza z kitu. To jest świadome: kit rysował dane, których ten produkt nie ma
i mieć nie chce. Zamknięte zostaje też, na teraz, skalowanie porcji po stronie
danych — #44 zostaje otwarte i czeka.

**Zmiana wymaga:** danych z realnego użycia, że ludzie sami wpisują ilości
w przewidywalnym kształcie, albo gotowego parsera/AI, który proponuje rozbicie
JAKO PODPOWIEDŹ DO POTWIERDZENIA, nigdy jako wymagane pole (AGENTS.md §9,
ROADMAP → V2). Źródłem prawdy zostaje wtedy nadal to, co człowiek napisał.

📄 `docs/design/kit-v2/IMPLEMENTATION_GUIDE.md` etap C ·
`docs/brand/COPY_STYLE.md` · `docs/ROADMAP.md` → V2 · issue #44

---

## D-018 · Usunięcie konta kasuje wszystkie zdjęcia, tekst zostaje zanonimizowany

**Data:** 6 września 2026 · Status: **obowiązuje** · audyt W4-01, issue #93

Ekran usuwania konta kazał potwierdzić: „Rozumiem, że po 30 dniach moje wpisy,
przepisy i zdjęcia zostaną usunięte na stałe". `EraseAccountData` kasował
jednak wyłącznie zdjęcie profilowe, a resztę — wpisy, przepisy, komentarze
i WSZYSTKIE pozostałe zdjęcia — zostawiał przy zanonimizowanym koncie,
z komentarzem tłumaczącym, dlaczego tak jest lepiej.

To nie był spór o interpretację RODO. To była obietnica złożona konkretnym
zdaniem, pod którym człowiek musiał postawić haczyk, i niedotrzymana.

### Co odrzucono

**Kasowanie wszystkiego, tak jak mówił ekran.** Kod robiłby wtedy dokładnie to,
co obiecuje, bez żadnych gwiazdek — i to jest realna zaleta. Cena: znikają
cudze wątki. Komentarz, na który ktoś odpowiedział, urywa się w połowie.
Przepis, który ktoś ugotował i ma w swoim zeszycie, przestaje istnieć. Przy
społeczności liczonej w dziesiątkach osób to są widoczne dziury, a zabieramy
je ludziom, którzy o nic nie prosili.

**Zostawienie kodu i poprawienie samego ekranu.** Najtańsze. Ale wymagałoby
świadomej podstawy prawnej na trzymanie CZYJEGOŚ ZDJĘCIA po tym, jak ta osoba
poprosiła o usunięcie konta — a takiej podstawy nie ma sensu szukać, skoro
zdjęcie da się skasować bez straty dla nikogo innego.

### Co wybrano

**Zdjęcia kasujemy wszystkie. Tekst zostaje, zanonimizowany.**

Ze zdjęciem jest inaczej niż z tekstem i to jest sedno tej decyzji. Tekst
przepisu po podmianie podpisu przestaje być danymi osobowymi. Zdjęcie nie:
dane są w pikselach — twarz, wnętrze mieszkania, dokument na stole — a
w oryginale jeszcze EXIF z datą, modelem telefonu i miejscem. Anonimizacja
podpisu nie zmienia tam absolutnie niczego.

Kasujemy oryginały, warianty i czyścimy cache CDN-u — bo skasowanie pliku
w buckecie to nie to samo co zniknięcie z internetu (audyt G-03).

**Cena, wprost:** wpis, w którym było zdjęcie, zostaje bez niego. Komponent
`x-photo` pokazuje w takim stanie komunikat, a nie pustą ramkę. Kto chce
usunąć konkretny przepis albo wpis w całości, ma to zrobić sam przed
skasowaniem konta — i ekran mówi mu to wprost.

**Ekran mówi teraz dokładnie to, co kod robi**, w dwóch listach: co znika i co
zostaje. Test wiąże te dwie rzeczy ze sobą, bo raz już się rozjechały i nikt
tego nie zauważył przez kilkanaście commitów.

**Zmiana wymaga:** potwierdzenia prawnika przy okazji weryfikacji regulaminu
(issue #8), gdyby uznał, że zanonimizowany tekst też wymaga innej podstawy.

📄 `app/Domain/Users/Actions/EraseAccountData.php` ·
`resources/views/pages/settings/data.blade.php` · `docs/legal/COMPLIANCE.md` ·
issue #8

---

## D-019 · Jasny motyw zawsze domyślny; ciemny wyłącznie na jawne życzenie

**Data:** 6 września 2026 · Status: **obowiązuje** · zgłoszenie właściciela

Właściciel, cytat: „Na telefonie pokazuje mi się tryb nocny, jak wchodzę na
kuking.pl w nocy, na komputerowej wersji tego nie ma. Trzeba gdzieś dodać
w menu albo stopce przycisk zmiany trybu. Jasny zawsze domyślny i użytkownik
decyduje, czy chce nocny w ogóle mieć, bo większość starszych osób woli
jasne."

Arkusz stylów szedł za `@media (prefers-color-scheme: dark)` — czyli telefon
(albo komputer) przełączał WYGLĄD SERWISU sam, za każdym razem, gdy system
miał włączony harmonogram „tryb nocny" albo był ustawiony na ciemny z innego
powodu. Nikt tego nie zamawiał, a część naszej grupy (50+) nie kojarzy, że to
WŁASNE urządzenie zmieniło wygląd strony — dla niej to wygląda na awarię
serwisu, nie na ustawienie telefonu.

### Co odrzucono

**Zostawienie `prefers-color-scheme` jako jedynego wejścia, z samym
przełącznikiem obok.** Nawet z widocznym przełącznikiem ktoś, kto nigdy go
nie dotknął, nadal dostawałby ciemny motyw w nocy — dokładnie to zgłoszenie
by nie zamykało, tylko dawało furtkę awaryjną komuś, kto już zauważył
problem.

**Trzecia wartość „jak w systemie", ustawiona jako domyślna.** Rozważona
wprost (patrz komentarz w migracji `2026_09_06_210000_add_theme_to_users`).
Odrzucona, bo jako wartość DOMYŚLNA odtwarzałaby identyczne zachowanie, które
właściciel zgłosił jako błąd — czyli byłaby tym samym problemem pod nową
nazwą. Jako opcja NIEdomyślna (obok „jasny" i „ciemny", z jasnym jako
domyślnym) jest dopuszczalna później, jeśli ktoś jej zażąda — ale nie ma dla
niej dziś ani jednego zgłoszenia, więc dokładanie jej teraz byłoby budowaniem
funkcji bez popytu (AGENTS.md → zakaz overengineeringu).

### Co wybrano

**Jasny jest teraz jedynym motywem domyślnym — dla każdego konta, także już
istniejącego, i dla każdego gościa.** Ciemny włącza się WYŁĄCZNIE atrybutem
`data-theme="dark"` na `<html>`, ustawianym jawnie przez człowieka —
`/ustawienia/czytelnosc` (obok rozmiaru tekstu: to ta sama sprawa,
czytelność) albo szybki przełącznik w stopce, widoczny na każdej stronie
i dla gościa też. Zalogowany ma wybór na koncie (kolumna `users.theme`, ten
sam wzorzec co `text_scale`); gość — w ciasteczku
(`App\Http\Controllers\ThemeController`), bo `localStorage` wymaga
JavaScriptu i dałby błysk złego wyglądu przy pierwszym renderze (AGENTS.md
§5: ważne funkcje działają bez JavaScriptu).

Arkusz stylów (`resources/css/tokens.css`) stracił CAŁKOWICIE ścieżkę
systemową — nie ma tam już żadnego `@media (prefers-color-scheme)`. Test
`tests/Feature/WyborMotywuTest.php` sprawdza to wprost na treści pliku
(bez komentarzy), żeby reguła nie wróciła po cichu przy kolejnej zmianie
kolorów.

**Cena, wprost:** ktoś, kto NAPRAWDĘ woli, żeby serwis podążał za jego
systemem (a nie tylko dostał ciemny raz i zapomniał), musi teraz przełączać
ręcznie, gdy zmienia porę dnia. To jest świadomy kompromis: badana grupa
(50+) w cytowanym zgłoszeniu wyraźnie woli stabilność nad automatykę, którą
łatwo pomylić z usterką.

**Zmiana wymaga:** zgłoszenia od użytkowników, że chcą automatycznego
podążania za systemem — wtedy wraca jako TRZECIA, nadal niedomyślna opcja
(patrz wyżej), nie jako powrót do obecnego zachowania.

📄 `database/migrations/2026_09_06_210000_add_theme_to_users.php` ·
`app/Http/Controllers/ThemeController.php` ·
`resources/views/components/layout.blade.php` · `resources/css/tokens.css` ·
`resources/views/pages/settings/accessibility.blade.php` ·
`tests/Feature/WyborMotywuTest.php`

---

## D-020 · Adresem zdjęcia jest trasa aplikacji, a bucket wariantów traci domenę

**Data:** 6 września 2026 · **Decyzja właściciela** · Status: **obowiązuje** ·
audyt W7-02 (P0, prywatność), issue #120

Adresem każdego zdjęcia w serwisie był adres pliku w buckecie z własną domeną
CDN. Taki adres nikogo o nic nie pyta i nie przestaje działać: kto raz go
skopiował — z podglądu źródła strony, z historii przeglądarki, z podglądu
linku w komunikatorze — otwierał zdjęcie także po zablokowaniu, po cofnięciu
obserwowania, po przełączeniu przepisu na prywatny i po decyzji moderacyjnej.

Cała macierz widoczności obowiązywała stronę HTML i nie obowiązywała ani
jednego piksela. Najgorszy przypadek nazwał audyt wprost:
`recipes.source_scan_media_id` — skan odręcznej kartki z rodzinnym przepisem,
a na niej nazwiska, adresy i czyjeś pismo.

### Co odrzucono

**Adres nie do zgadnięcia („security through obscurity").** Klucze i tak są
UUID-ami, więc to jest stan obecny opisany ładniejszym słowem. Nie rozwiązuje
niczego, o co chodzi: adres raz ujawniony zostaje ważny na zawsze.

**Podpisywanie adresów CDN-u bez trasy aplikacji.** Krótszy termin ważności
zamiast kontroli dostępu. Nadal odpowiada „tak" komuś, kogo autor właśnie
zablokował, tyle że przez pięć minut zamiast przez lata — i nie da się tego
związać z Policy, bo podpis powstaje bez wiedzy o tym, kto pyta.

**Strumieniowanie bajtów przez PHP.** Najprostsze do napisania i najdroższe
w działaniu: jedna strona feedu to kilkadziesiąt zdjęć po kilkaset kilobajtów,
a proces zajęty przepisywaniem obrazka nie obsługuje nikogo innego.
`X-Accel-Redirect` odpadł osobno i twardo: **przed PHP stoi Caddy, nie nginx**,
a Caddy takiego mechanizmu nie zna.

**Kolumna `visibility` na `media`.** Wygląda najtaniej i jest najdroższa:
byłaby SIÓDMĄ kopią reguły widoczności w tym repozytorium, w dodatku
denormalizowaną, więc rozjeżdżającą się przy każdej zmianie widoczności
rodzica. Powtarzającą się przyczyną błędów jest tu dokładnie to — „reguła
istnieje poprawnie w jednej warstwie, a druga implementuje ją inaczej".

### Co wybrano

**Wszystkie warianty w buckecie BEZ domeny publicznej. Adresem zdjęcia jest
trasa aplikacji, która pyta Policy treści nadrzędnej i przekierowuje (302) na
krótko podpisany adres. Bajty nie idą przez PHP.**

Reguła widoczności zdjęcia nie powstaje na nowo. `DostepDoZdjecia` odwraca
listę rodziców (tę samą, co `KasujZdjecie::ODWOLANIA`) i woła ICH Policy przez
`Gate`. Rodzicom, którzy Policy nie mieli, dopisano ją delegującą do przepisu
albo do konta, zamiast wpisywać warunek u siebie.

**Najszerszy rodzic wygrywa.** Zdjęcie da się przypiąć do kilku treści naraz,
a przez rodzica publicznego bajty i tak są jawne. Rodzic najwęższy dawałby
pustą ramkę w publicznym przepisie bez żadnego zysku dla prywatności.

**Odmowa to 404 nieodróżnialne od zdjęcia nieistniejącego**, z treścią
odpowiedzi włącznie.

**Cena, wprost:** każde żądanie zdjęcia to teraz żądanie do Laravela i kilka
zapytań o rodziców. Krok 1 świadomie tego nie optymalizuje — dopiero pomiar
z produkcji ma rozstrzygnąć, czy potrzebny jest cache decyzji. Druga cena:
podgląd linku w serwisach społecznościowych idzie teraz przez przekierowanie
(`og:image` wskazuje trasę), co część scraperów obsługuje wolniej.

**Czego to NIE załatwia i nie da się załatwić z kodu:** zdjęcie klucza `url`
z konfiguracji nie zdejmuje domeny `cdn.kuking.pl` z bucketu po stronie
Cloudflare. Dopóki ta domena tam wskazuje, stare adresy działają dalej.
To jest **issue #120** i należy do właściciela.

**Zmiana wymaga:** zmierzonego kosztu tej trasy na produkcji (wtedy zmienia się
sposób, nie zasada) albo image CDN-u z własną autoryzacją na brzegu, który
umiałby zapytać Kuking o decyzję, zanim odda plik.

📄 `app/Domain/Media/DostepDoZdjecia.php` ·
`app/Http/Controllers/MediaController.php` · `app/Models/Media.php` ·
`config/filesystems.php` · `docs/MEDIA_PIPELINE.md` ·
`tests/Feature/ZdjeciaChronioneNieWyciekajaTest.php` · issue #120

---

## Jak dopisywać decyzje

Nowa decyzja trafia tutaj, gdy: zamyka dyskusję, którą ktoś mógłby otworzyć
ponownie, albo gdy odrzuca oczywiste na pierwszy rzut oka rozwiązanie.

Rzeczy, które **nie są** decyzją do zapisania: wybór nazwy zmiennej, kolejność
pól w formularzu, sposób sformułowania jednego komunikatu.

---

## D-021 · Tematy znikają, zostają same tagi

**Data:** 7 września 2026 · **Decyzja właściciela** · Status: **obowiązuje** ·
zastępuje mechanizm z issue #31 (`topics`, `topic_follows`, `posts.topic_id`)

Właściciel: „Tematy usuwamy, tylko tagi."

Zamknięty słownik redakcyjny (`Topic`) ustępuje otwartym tagom użytkowników.
Jeden mechanizm klasyfikacji treści zamiast dwóch — bo dwa znaczyłyby, że
osoba 50+ musi zrozumieć, czym „temat" różni się od „tagu", a to jest
pytanie, na które sam produkt nie ma dobrej odpowiedzi.

### Czego ta decyzja NIE rozstrzyga, a co trzeba rozstrzygnąć

**Tematy powstały dzień przed tą decyzją i powstały po coś.** Migracja
`2026_09_06_100000_create_topics_tables.php`, commity `eb235fc` (#31 część A)
i `ce6f48d` — „Nowe konto przestaje widzieć pusty ekran" (#31 część B). Nie
jest to stary dług, tylko świeża odpowiedź na udokumentowany problem
cold-startu.

Na tematach stoi w `docs/product/COLD_START.md` cały plan startu, nie tylko
pierwszy feed:

- **temat tygodnia** ogłaszany przez gospodarza,
- **ambasadorzy tematów** — 8–12 osób z osobistym zaproszeniem „prowadź temat
  »chleb i zakwas«", z widoczną rolą i zadaniem trzech komentarzy dziennie,
- przygotowane tematy na **Wigilię, tłusty czwartek i Wielkanoc**, planowane
  trzy tygodnie wcześniej.

Otwarte tagi nie unoszą żadnej z tych trzech rzeczy: nie da się powierzyć
komuś prowadzenia tagu, który każdy może utworzyć, ani zagwarantować, że nowe
konto trafi tydzień przed Wigilią na coś sensownego.

**POTWIERDZONE PRZEZ WŁAŚCICIELA 7 września 2026:** rolę redakcyjną przejmuje
**wąska lista tagów promowanych**, prowadzona przez gospodarza — te same trzy
funkcje (temat tygodnia, ambasador, tag sezonowy) realizowane na tagach, bez
drugiego typu obiektu w interfejsie. To nie jest powrót Tematów: promowany tag
jest zwykłym tagiem, który dodatkowo stoi na liście gospodarza, z kolejnością
i opcjonalnym jednym zdaniem od niego. `docs/product/COLD_START.md` wymaga
aktualizacji pod tym kątem.

**Co odrzucono i dlaczego.** Rozważane były trzy inne warianty.
*Lista po popularności* — najprostsza, ale przy zerowym ruchu popularność nie
istnieje, więc nowe konto zobaczyłoby pustą albo losową listę, czyli dokładnie
problem, który Tematy rozwiązywały. *Wykorzystanie istniejących mechanizmów
redakcyjnych* — projekt ma już tablicę dnia („kuKINGi na dziś": do 6 wpisów
i 6 osób z notatką) oraz publiczne zeszyty, i one pokrywają „co gospodarz dziś
pokazuje" oraz „zestaw, który gospodarz złożył". Nie pokrywają jednego:
NAZWANEJ RZECZY, DO KTÓREJ SPOŁECZNOŚĆ SAMA DOSYPUJE TREŚĆ — zeszyt składa
gospodarz, tag rośnie od użytkowników, a „temat tygodnia" ma z definicji
rosnąć. *Odłożenie decyzji* — odrzucone, bo rdzeń tagów był budowany w tej
chwili, a dodanie promocji później oznaczałoby przebudowę onboardingu, strony
tagu, strony głównej i panelu.

**Opiekun tagu (ambasador) NIE jest jeszcze zbudowany.** Zatwierdzona została
sama możliwość promowania tagu. Przypisanie konkretnej osoby do prowadzenia
tagu to osobny krok.

### Stan danych w chwili decyzji

W lokalnej bazie deweloperskiej: **0 tematów, 0 wpisów z tematem, 0
obserwacji tematów**. Stanu produkcji nie da się sprawdzić z kontenera
roboczego — właściciel musi to zrobić przed migracją, bo od tego zależy, czy
usunięcie tematów jest zmianą schematu, czy rozmową z ludźmi, którym coś
zniknie z profilu.

### Co konkretnie znika

`topics`, `topic_follows`, `posts.topic_id`, `TopicFeed`, `TopicController`,
trasy `topics.*`, strona tematu, wybór tematów w onboardingu i wpisy tematów
w mapie strony. Każde z tych miejsc jest dziś pokryte testami — po usunięciu
testy mają zniknąć razem z kodem, a nie zostać wyciszone.

### Poprawka techniczna, która wychodzi razem z tagami

Normalizacja nazwy tagu do UNIKALNOŚCI nie może używać `unaccent` — inaczej
`zurek` i `żurek` stają się jednym tagiem, a to są dwie różne rzeczy.
Istniejąca funkcja `kuking_normalize()` (`pg_trgm` + `unaccent`, migracja
`2026_09_05_001300_fix_search_indexes.php`) służy do SZUKANIA i PODPOWIADANIA,
nie do rozstrzygania tożsamości tagu.
## D-022 · Zakres usunięcia konta wybiera człowiek; domyślnie tekst zostaje

**Data:** 7 września 2026 · Status: **obowiązuje** · rozszerza D-018 ·
weryfikacja W1 (pomiar), issue #8

D-018 rozstrzygnęło: zdjęcia kasujemy wszystkie, tekst zostaje
zanonimizowany. **Pomiar z 7 września pokazał, że druga połowa tej decyzji
nigdy nie działała.**

### Co było zepsute i dlaczego nikt tego nie zauważył

`EraseAccountData` nie zmienia `users.status` — po zakończonej anonimizacji
konto zostaje na `pending_delete`. Na tym statusie stoi
`User::jestDostepnyJakoAutor()` i sześć Policy. Zmierzone na żywej bazie:

| Co | Przed anonimizacją | Po anonimizacji |
|---|---|---|
| przepis | 200 | **403** |
| wpis | 200 | **403** |
| profil | 200 | **403** |
| przepis w CUDZYM zeszycie | widoczny | **wypada z listy** |
| komentarz | widoczny | **niewidoczny nawet dla autora wpisu** |

Czyli: tekst zostawał w bazie, ale znikał ze serwisu. D-018 obiecało jedno,
a serwis robił drugie — i to jest **dokładnie ten nawracający wzorzec, który
opisuje `docs/HANDOVER.md`**: reguła istnieje poprawnie w jednej warstwie,
a druga implementuje ją inaczej.

Nie zauważono tego, bo test `test_tekst_zostaje_ale_bez_nazwiska` asertuje
**wyłącznie obecność wiersza w bazie**. Widoczności nie sprawdza wcale. Test
przechodził i „dowodził" czegoś, czego nie było. Drugi test,
`KomentarzeGranicaStatusuAutoraTest:62`, **aktywnie pilnował zaprzeczenia**
tej obietnicy — zamroził stan faktyczny jako oczekiwany.

### Co odrzucono

**Sam nowy status końcowy, bez pytania człowieka.** Naprawiłoby D-018
dosłownie i było najtańsze. Odrzucone, bo zostawia jedno rozstrzygnięcie
narzucone wszystkim: część ludzi usuwa konto właśnie po to, żeby ich słowa
zniknęły, i dla nich „tekst zostaje, tylko bez podpisu" nie jest tym, o co
prosili. Anonimizacja jest naszą oceną, że tak jest lepiej dla społeczności
— a to nie jest ocena, którą wolno robić za kogoś przy jego własnych
danych.

**Kasowanie wszystkiego, na powrót do wariantu odrzuconego w D-018.**
Argument z D-018 nadal obowiązuje: cudze wątki urywają się w połowie, cudze
zeszyty gubią przepisy. Nie ma powodu unieważniać tamtej analizy.

### Co wybrano

**Ekran usuwania konta pyta, a domyślnie kasuje MINIMUM.**

Haczyk „usuń także moje wpisy, przepisy i komentarze" jest **odhaczony**.
Kto go nie tknie, dostaje D-018: zdjęcia znikają, tekst zostaje
zanonimizowany i — po tej naprawie — **nadal widoczny**. Kto go zaznaczy,
dostaje pełne usunięcie razem z tekstem.

Uzasadnienie domyślnej wartości: domyślna opcja ma być tą, której skutków
nie da się cofnąć w mniejszym stopniu. Zostawiony tekst da się skasować
później; skasowanego nie da się przywrócić. Domyślne odhaczenie nie jest
więc wygodą dla serwisu, tylko wyborem mniej nieodwracalnej ścieżki dla
osoby, która klika w pośpiechu.

### Co to wymaga od kodu

1. **Stan końcowy konta** obok `pending_delete` — inaczej granica
   autoryzacji dalej ukrywa tekst i cała ta decyzja jest fasadą.
   `data_erased_at` już istnieje, ale `jestDostepnyJakoAutor()` go nie
   czyta.
2. Wybór człowieka **zapisany razem z żądaniem usunięcia**, nie odczytany
   w chwili wykonania — między jednym a drugim mija 30 dni i ekran, na
   którym stawiano haczyk, może już nie istnieć w tej formie.
3. `KomentarzeGranicaStatusuAutoraTest:62` do świadomego przepisania. To
   nie jest test do wyciszenia — to jest test, który trzeba zmienić razem
   z decyzją, którą zamroził.
4. Test na WIDOCZNOŚĆ, nie na obecność wiersza. Poprzedni test przechodził
   właśnie dlatego, że sprawdzał to drugie.

📄 `app/Domain/Users/Actions/EraseAccountData.php` · `app/Models/User.php` ·
`resources/views/pages/settings/data.blade.php` · D-018

---

## D-023 · Oryginał zdjęcia traci współrzędne GPS przy wgraniu

**Data:** 7 września 2026 · Status: **obowiązuje** · weryfikacja W5 (pomiar)

Warianty pokazywane w serwisie powstają przez przekodowanie do WebP, więc
EXIF w nich nie ma. **Oryginał był zapisywany bajt w bajt** —
`StoreUploadedImage.php:180`, `put($objectKey, $file->get())` — i komentarz
w kodzie mówił to wprost: *„ORYGINAŁ zachowuje go w całości — łącznie ze
współrzędnymi GPS, czyli adresem kuchni użytkownika"*.

Oryginał nie jest kasowany po przetworzeniu (eksport RODO ma oddać
człowiekowi jego zdjęcie, nie zmniejszoną kopię) i **trafia do paczki
danych**.

**Dlaczego to jest problem, a nie świadomy kompromis:** obecna, opublikowana
polityka prywatności mówi *„Nie zbieramy: numeru telefonu, dokładnego adresu
zamieszkania, **lokalizacji GPS**"*. To zdanie było nieprawdziwe. RODO patrzy
na przechowywanie, nie na użycie — „nie czytamy tego pola" nie znaczy „nie
zbieramy".

### Co odrzucono

**Zostawić oryginał w całości i poprawić politykę.** Uczciwe i tanie.
Odrzucone, bo cena jest realna: przechowujemy adres domu grupy 50+ w pliku,
którego do niczego nie używamy. Zdanie w polityce nie jest tu problemem —
problemem jest samo dane. Poprawianie dokumentu, żeby pasował do
niepotrzebnego zbierania, jest odwrotnością minimalizacji.

**Wyczyścić też oryginały już wgrane.** Najczystszy stan końcowy. Odrzucone
NA TERAZ, bo modyfikuje pliki, które ludzie już wgrali, i tego nie da się
cofnąć. Do zrobienia osobno, świadomie, po sprawdzeniu, ile takich plików
w ogóle jest.

### Co wybrano

**Blok GPS wypada z oryginału w chwili wgrania. Reszta EXIF zostaje.**

Aparat, obiektyw, data, orientacja — wszystko to zostaje, bo to jest
informacja o zdjęciu, którą właściciel może chcieć odzyskać z eksportu.
Wypada wyłącznie lokalizacja, bo to jest informacja o CZŁOWIEKU, nie
o zdjęciu.

Zdanie w polityce staje się prawdziwe bez zmiany dokumentu — a to jest
lepszy kierunek naprawy niż przepisywanie obietnicy pod kod.

> **Uzupełnienie z 9 września — decyzja bez zmian, wykonanie było dziurawe
> (A6-02).** `UsunGps` deklarowała cztery kontenery, a szukała bloku TIFF
> przez `strpos($bajty, "Exif\0\0")`. Ten prefiks jest częścią segmentu APP1
> **w JPEG-u**; w PNG (chunk `eXIf`) i WebP (chunk `EXIF`) dane chunku to
> zgodnie ze specyfikacją już sam blok TIFF, bez prefiksu. Poprawnie zapisane
> PNG i WebP przechodziły więc przez sanitator NIETKNIĘTE, ze współrzędnymi
> w środku. Znalazł to audyt zewnętrzny, odczytując zapisane pliki
> niezależnym dekoderem. Blok TIFF jest teraz znajdowany po strukturze
> kontenera, a `OryginalTraciGpsTakzeWPngIWebpTest` pilnuje PNG i WebP osobno.
>
> **Czego to nadal nie obejmuje, wprost:** EXIF-u zapisanego w PNG jako tekst
> (`zTXt`/`iTXt` z profilem „Raw profile type exif"), metadanych XMP w żadnym
> kontenerze — XMP potrafi nieść własne pola lokalizacji — ani AVIF-a inaczej
> niż przez awaryjne szukanie nagłówka w bajtach. To są znane, nieprzykryte
> luki, nie przeoczenie.
>
> **Decyzja „nie ruszamy oryginałów już wgranych" zostaje** — właściciel
> potwierdził ją ponownie 9 września. Naprawa dotyczy wyłącznie nowych wgrań.
>
> **Uzupełnienie z 23 września — XMP i tekstowy profil EXIF w PNG (#1004).**
> Dwie z trzech luk wyżej są zamknięte. XMP niesie własne współrzędne
> (`exif:GPSLatitude`, `GPSDest*`, lokalizacje IPTC, pola producentów)
> w dowolnych przestrzeniach nazw, więc nie szukamy w nim pól: **cały pakiet
> XMP zamieniamy na spacje**, w miejscu, bez zmiany długości. To jest świadome,
> wąskie odstępstwo od „reszta metadanych zostaje": aparat, obiektyw, data
> i orientacja żyją w EXIF-ie, który zostaje; z XMP wypada zwykle historia
> edycji. Tak samo wypadają PNG-owe „Raw profile type …". AVIF nadal jest
> czyszczony wyłącznie szukaniem w bajtach (EXIF po nagłówku, XMP po ramce
> pakietu) — bez parsera ISOBMFF. Decyzja o starych oryginałach bez zmian.

📄 `app/Domain/Media/UsunGps.php` ·
`app/Domain/Media/Actions/StoreUploadedImage.php` ·
`tests/Feature/OryginalTraciGpsTakzeWPngIWebpTest.php` ·
`tests/Feature/OryginalTraciGpsZXmpTest.php` ·
`resources/legal/polityka-prywatnosci.md`

---

## D-024 · Dokumenty prawne idą na produkcję poprawione, a nieprawda z nich wypada od razu

**Data:** 7 września 2026 · Status: **obowiązuje** · weryfikacja W1–W5 · issue #8

Właściciel dostarczył trzy kompletne szkice (polityka prywatności,
regulamin, zasady) przygotowane do przeglądu przez prawnika. Pięć
przebiegów weryfikacyjnych sprawdziło każde twierdzenie o systemie
przeciwko kodowi.

### Rzeczy, które obecna, PUBLICZNIE SERWOWANA treść twierdzi nieprawdziwie

`GET https://kuking.pl/prywatnosc` → HTTP 200 (zmierzone). Czyli poniższe
zdania są dziś obowiązującą obietnicą, nie wersją roboczą:

1. **Sentry i PostHog w tabeli podprocesorów, z lokalizacjami.** Żadnego
   z nich nie ma w kodzie: brak `config/sentry.php`, brak pakietu
   w `composer.json`, brak integracji; PostHog to dwie puste zmienne
   w `.env.example`. Dokument wymienia podmioty, które nie przetwarzają
   niczego — to wprowadza w błąd co do tego, kto ma dane użytkownika.
2. **„Każdy z tych dostawców ma podpisaną z nami umowę powierzenia."**
   Właściciel potwierdził: **żadna nie jest podpisana.**
3. **„Nie zbieramy lokalizacji GPS"** — patrz D-023.
4. **„hasło przechowywane w postaci zaszyfrowanej"** — jest bcrypt o koszcie
   12 (zmierzone: `$2y$12$`), czyli nieodwracalny skrót, nie szyfrowanie.
5. **Notatki redakcyjne w treści widocznej dla użytkownika**: „[Wariant A —
   jeśli wdrożony baner:] … [Wariant B …]".
6. **Opublikowane placeholdery** w zdaniach o retencji: „[X dni — do
   ustalenia]".

### Co wybrano

**Poprawiona treść wchodzi teraz; usunięcie nieprawdy nie czeka na
prawnika.**

Rozróżnienie, na którym stoi ta decyzja: **wykreślenie zdania
nieprawdziwego nie jest decyzją prawną.** Nie wymaga niczyjej opinii — kod
mówi, że jest fałszywe. Czekanie z tym na przegląd oznaczałoby świadome
utrzymywanie fałszu przez czas, którego nie kontrolujemy.

Osobno i inaczej traktujemy zdania, które są PROPOZYCJĄ, nie stanem: okresy
retencji. Tu obowiązuje zasada autora szkicu, przyjęta bez zmian:
**proponowanego okresu nie wolno opublikować, dopóki automatyczne zadanie
go nie wykonuje.** Zmierzone: kod egzekwuje dokładnie dwa okresy —
`product_signals` 90 dni i paczki eksportu 7 dni. `audit_log`,
`notifications`, `reports`, `appeals` i `moderation_actions` nie mają
retencji żadnej, więc żadna liczba przy nich nie może się pojawić.

### Co zostaje jawną luką, bo należy do właściciela albo prawnika

- **Umowy powierzenia z Railway i Cloudflare — do zawarcia przed betą.**
  To warunek zgodności, nie formalność: bez DPA powierzenie danych
  procesorowi nie ma podstawy.
- **Dostawca poczty nie jest wybrany.** A maile weryfikacyjne i resetu hasła
  są dziś czymś wysyłane — więc jakiś podmiot przetwarza adresy e-mail
  wszystkich kont i nie wiemy który. `docs/decyzje/POCZTA.md` rekomenduje
  EmailLabs, ale decyzji nie ma w tym pliku.
- **Jurysdykcja bucketów R2.** Z kodu nieudowadnialna, a poszlaka jest
  NEGATYWNA: udokumentowany endpoint nie zawiera `.eu.`, a bucket
  z ograniczeniem jurysdykcyjnym UE jest osiągalny tylko pod
  `<ACCOUNT_ID>.eu.r2.cloudflarestorage.com`. Pogrubione zdanie „Dane
  przechowujemy na serwerach w Unii Europejskiej" wymaga potwierdzenia
  w panelu, zanim zostanie utrzymane.
- **Minimalny wiek: 16 lat** — to NIE jest luka, odpowiedź jest w kodzie
  (`config/kuking.php:228`) i w obu opublikowanych dokumentach. Otwarte
  zostaje węższe pytanie do prawnika: czy 16 lat wystarcza wobec
  ograniczonej zdolności do czynności prawnych osób 13–17.

### Czego nie wolno wpisać, bo kod nie zna celu

`media.checksum_sha256` jest zapisywany i **nigdy nieczytany** (indeks
`media_checksum_idx` nie obsługuje żadnego zapytania).
`media.perceptual_hash` **nie jest nawet zapisywany** przez kod produkcyjny
— zmierzone `count(perceptual_hash) = 0`. Kolumna zapisywana i nieczytana
nie ma celu przetwarzania, a wpisanie do polityki, że służy „moderacji"
albo „wykrywaniu duplikatów", byłoby wymyśleniem podstawy prawnej pod
funkcję, której nie ma.

📄 `resources/legal/*.md` · `docs/legal/BRAMKA_BETY.md` · issue #8

---

## D-025 · Treść zaląźkowa wchodzi na produkcję, ale jawnie oznaczona

**Data:** 7 września 2026 · Status: **obowiązuje; wygląd plakietki odwrócony
przez D-032**

> **Poprawka z 8 września.** Sama zasada — treść zalążkowa wchodzi, ale
> oznaczona — obowiązuje bez zmian. Odwrócony został WYGLĄD oznaczenia:
> plakietka jest krótka („konto przykładowe") i cicha, a głośna wersja
> zostaje wyłącznie na profilu, raz na ekran. Powód i warunki: **D-032**.

Serwis działa i nie jest promowany — nikt z niego nie korzysta. Powstała
treść zaląźkowa: 12 kont, 40 przepisów, 80 wpisów, 60 komentarzy, bez zdjęć.

### Co odrzucono

**Pusty serwis.** `docs/product/COLD_START.md` §6.3 stawia właśnie na to:
„jest nas tu 87 osób" jako przewagę, nie wstyd. Odrzucone, bo obecna skala
to nie 87 osób, a zero — a pierwsza osoba, która wejdzie na pusty feed, nie
ma po co wrócić.

**Treść bez oznaczenia.** Serwis wyglądałby na żywy od pierwszego dnia.
Odrzucone wprost jako wprowadzanie w błąd co do skali — a grupa 50+ opiera
decyzję o zostaniu właśnie na zaufaniu. To jest cena, której nie warto
zapłacić za wrażenie ruchu.

### Co wybrano

**Konta zaląźkowe z widocznym oznaczeniem, że są przykładowe.**

Nowa osoba nie trafia na pustkę, a nikt nie jest wprowadzony w błąd.
Kosztuje jedną kolumnę i etykietę w interfejsie — przy koncie, nie tylko
w regulaminie, bo nikt nie czyta regulaminu, żeby dowiedzieć się, czy pisze
do człowieka.

**Otwarte, do rozstrzygnięcia przed końcem bety:** co się stanie z tymi
kontami, gdy przyjdą prawdziwi ludzie. Zostawienie ich na zawsze zamienia
oznaczenie w stały element serwisu; usunięcie zabiera treść, do której
prawdziwi ludzie mogli już coś dopisać. Ta decyzja nie musi paść teraz, ale
musi paść przed otwarciem rejestracji.

📄 `database/seeders/dane/tresc-zalazkowa.json` · `docs/product/COLD_START.md`

---

## D-026 · Baza tagów pochodzi ze słownika w pliku, a stare nazwy są scalane, nie dublowane

**Data:** 7 września 2026 · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Zdanie „dane w DWÓCH
> plikach JSON, czytanych przez `TagSeeder`… razem 1419 tagów i 2448 aliasów"
> opisuje nieaktualne źródło danych. `database/seeders/TagSeeder.php:104-106`
> deklaruje TRZY pliki — doszedł `slownik-tagow-v1.1.json` (27 nazw
> kanonicznych), którego nie opisuje żadna decyzja w tym rejestrze. Liczby
> 1419/2448 są przez to zaniżone, a `docs/DATABASE.md:3203` powtarza je za tym
> wpisem, więc ta sama nieprawda stoi w dwóch dokumentach naraz. Mechanizmy
> opisane niżej — scalanie starych nazw zamiast dublowania, zakaz tagów
> dietetycznych — działają i mają test
> (`tests/Feature/SlownikTagowTest.php:47-51`).

Początkowa baza tagów (SPEC §1.4) była wpisana na sztywno w `TagSeeder`:
651 nazw i 53 aliasy, ułożone przeze mnie przy okazji implementacji D-021.
Zamówiony osobno słownik ma 1250 nazw kanonicznych i 2366 aliasów, w 13
kategoriach, i jest ułożony pod polską kuchnię domową oraz pod grupę 50+ —
dwie kategorie istnieją tylko dlatego: `pamiec` („przepis po babci",
„z rodzinnego zeszytu") i `okolicznosci` („dla wnuków", „z czerstwego
chleba", „mało zmywania", „dla niejadka"). Poprzednia baza nie miała ani
jednego takiego tagu.

### Co odrzucono

**Zostawienie starej bazy i wpięcie słownika obok.** Zmierzone: 43 nazwy ze
starej bazy nowy słownik traktuje jako alias czegoś innego („marchewka" →
„marchew", „schabowy" → „kotlet schabowy", „pieczenie" → „pieczone").
Wpięcie obok daje 43 pary żywych tagów na jedno pojęcie — czyli dokładnie
to rozsypanie taksonomii, przed którym cała ta baza ma chronić („zakwas /
na zakwasie / chleb zakwas / ZAKWAS — po miesiącu nie ma czego obserwować").
**Zaniechanie nie było tu neutralne.**

**Wyrzucenie starej bazy w całości.** Zmierzone: słownik nie ma 293 pojęć,
które stara baza miała — w tym podstawowych składników („kapusta", „seler",
„fasola", „olej", „orzechy"), części mięsa i klasyków bez odpowiednika
(„zrazy", „tatar", „sękacz"). Wymiana jednego kompletu na drugi zabierałaby
je bez powodu.

**Dodanie kolumny na `sezonowy`.** 226 tagów w słowniku ma podpowiedź
sezonu. Nic w kodzie nie umie z niej korzystać, a funkcji sezonowości nie
ma. Kolumna bez drogi zapisu i odczytu to ten sam błąd, który opisuje
zadanie o minutniku kroku (kompletna funkcja za polem, którego nikt nie
umie ustawić). Informacja zostaje w pliku.

### Co wybrano

**Dane w dwóch plikach JSON, czytanych przez `TagSeeder`:**
`slownik-tagow.json` (dostarczony, nietknięty — razem z polem `uwagi`,
44 rozstrzygnięciami autora, z odsyłaczami do WSJP PAN i Listy Produktów
Tradycyjnych MRiRW) oraz `slownik-tagow-uzupelnienia.json` (169 pojęć,
których słownik nie ma ani jako nazwy, ani jako aliasu).
Razem **1419 tagów i 2448 aliasów**. Dwa pliki, a nie jeden, żeby kolejna
wersja słownika podmieniała JEDEN plik bez scalania cudzych zmian w środku
listy.

Z poprzedniej bazy świadomie NIE przeniesiono nazw angielskich i modnych
(„cookies", „smoothie bowl", „chia pudding"), fraz zamiast pojęć („obiad
w piętnaście minut"), nazwy marki („termomix" — słownik ma potoczne
`w termomiksie` małą literą) oraz tagów **„fit", „dieta odchudzająca"
i „dieta sportowca"**, które łamią tę samą regułę o języku dietetycznym,
jaką postawiono słownikowi. Test tego pilnuje, więc nie wrócą.

**Dziesięć pojęć ogólnych dołożonych po pomiarze podpowiedzi.** Wgranie
słownika pozwoliło zmierzyć coś, czego na 651 tagach nie było widać: dla
każdej złożonej nazwy sprawdzone, czy jej pierwsze słowo istnieje
samodzielnie. Nie istniało dla `barszcz`, `kotlety`, `krem`, `kasza`, `sok`,
`syrop`, `pasta`, `placki`, `nalewka`, `ser` — więc wpisanie samego słowa
„barszcz" podpowiadało „barszcz biały", rozstrzygając za człowieka, którego
barszczu mu trzeba. Nie jest to zarzut do słownika: jego uwaga 25 mówi, że
nazwy ogólne i odmiany celowo współistnieją, po prostu tych dziesięciu
zabrakło.

**Ranking podpowiedzi doprecyzowany, bo przy 1419 tagach przestał
wystarczać.** SPEC §1.5 mówił „dokładne dopasowanie początku nazwy →
dokładny alias → trigram", a wszystkie trafienia z pierwszej gałęzi miały
tę samą wagę — czyli ich kolejność brała się z fizycznej kolejności wierszy.
Zmierzone: wpisane „chleb" dawało jako pierwszą podpowiedź „chlebek
bananowy", a wpisane „marchewka" — „marchewkę z groszkiem", mimo że
„marchewka" jest dokładnym aliasem „marchwi". Ten sam błąd w dwóch
miejscach: dopasowanie DOKŁADNE przegrywało z częściowym. Nowa kolejność:
dokładna nazwa → dokładny alias → początek nazwy od najkrótszej → trigram,
a na końcu alfabet, żeby ta sama fraza dawała ZAWSZE tę samą listę
(`docs/UX_50_PLUS.md`: przewidywalność przed bogactwem).

**Kolizja aliasu z istniejącym tagiem: scalenie, nie odrzucenie** —
`MergeTags` (SPEC §1.8), ale WYŁĄCZNIE gdy stary tag jest pusty
i redakcyjny: `is_seeded`, `active`, bez wpisów, bez obserwujących, bez
promocji i sam nieobecny w słowniku. Tag, którego ktoś już użył albo który
powstał z ręki człowieka, zostaje nietknięty — alias jest wtedy odrzucany
i zgłaszany w raporcie, a decyzja zostaje przy człowieku. Cena tego
zaniechania (dwa tagi na jedno pojęcie do czasu decyzji) jest niższa niż
cena scalenia komuś tagu, którego używa.

**`MergeTags` powstało przy tej okazji i to jest osobne ustalenie.**
Kolumny `tags.status = 'merged'` i `tags.merged_into_tag_id` istniały od
migracji `create_tags_tables`, a mechanizm ich czytania był kompletny:
strona tagu przekierowuje, podpowiedzi wykluczają, `ResolveTagsForPost`
rozwiązuje wpisaną nazwę do tagu kanonicznego. Ustawiał je natomiast
wyłącznie `forceFill` w testach — mimo że komentarz modelu `Tag` i komentarz
migracji odsyłały do `MergeTags` jako do istniejącej klasy. **Trzeci taki
przypadek w tym repozytorium** (po minutniku kroku i po D-018): reguła
zapisana w jednej warstwie, a w drugiej niewykonalna.

**Zmierzony efekt uboczny:** `php artisan db:seed` na czystej bazie kończył
się wyjątkiem, bo `DemoSeeder` tworzył tag „chleb na zakwasie", który
`TagSeeder` już wstawił (`UNIQUE(normalized_name)`). Naprawione: `DemoSeeder`
idzie teraz przez `ResolveTagsForPost`, czyli tę samą bramkę, co prawdziwy
formularz wpisu — a „zupy" rozwiązuje się przy okazji do kanonicznego
„zupa", zamiast tworzyć drugi tag na to samo.

📄 `database/seeders/dane/slownik-tagow.json` ·
`database/seeders/dane/slownik-tagow-uzupelnienia.json` ·
`database/seeders/dane/README.md` · `database/seeders/TagSeeder.php` ·
`app/Domain/Tags/Actions/MergeTags.php` ·
`app/Domain/Tags/TagSuggester.php` · `tests/Feature/SlownikTagowTest.php` ·
`tests/Feature/PodpowiedziNaPelnymSlownikuTest.php` ·
`tests/Feature/TagSeederZeSlownikaTest.php` ·
`tests/Feature/ScalanieTagowTest.php`

---

## D-027 · Jedno wysłanie formularza to jeden zapis — klucz wysłania, nie okno czasowe

**Data:** 7 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

Podwójne kliknięcie nie jest w grupie 50+ pomyłką, tylko sposobem obsługi
komputera: strona myśli chwilę, więc klika się drugi raz. Zmierzone (audyt
wyścigów, 7 września 2026): dwa kliknięcia „Opublikuj" dawały dwa wpisy,
dwa kliknięcia „Ugotowałem" — dwa wykonania i **dwa powiadomienia** u autora
przepisu, a `reports` przyjmowało drugie identyczne otwarte zgłoszenie bez
oporu bazy.

Rozstrzygnięte DWA mechanizmy, nie jeden, bo to są dwa różne problemy:

1. **Wpis i „Ugotowałem": klucz wysłania.** Formularz dostaje przy
   renderowaniu jednorazowy klucz w ukrytym polu; tabela dostaje kolumnę
   `klucz_wyslania` i częściowy indeks UNIQUE. Zapis idzie
   „wstaw i złap wyjątek", a przy kolizji człowiek trafia na swój
   pierwszy wpis — drugie kliknięcie jest nieodróżnialne od pierwszego.
   **To NIE jest `UNIQUE (user_id, recipe_id)` i D-005 zostaje
   nienaruszone**: nowe gotowanie z nowego formularza przechodzi
   (zmierzone).
2. **Zgłoszenie: częściowy indeks UNIQUE w bazie** na otwartych
   zgłoszeniach pary (osoba, treść). Dedup w PHP już działał w zwykłym
   ruchu, ale nie chronił przed seederem, komendą ani wyścigiem — ten sam
   argument, który stoi za `moderation_actions_one_per_report`.

Odrzucone i dlaczego:

- **Blokada przycisku w JavaScripcie** jako mechanizm — publikacja musi
  działać bez JS (D-007), a brak skryptu to ten sam ruch, w którym strona
  ładuje się wolno, czyli ten, w którym klika się drugi raz. Zostaje
  wyłącznie jako niewiążący dodatek.
- **`lockForUpdate()` dla zgłoszeń** — zmierzone, że nie działa:
  `SELECT ... FOR UPDATE`, który nie zwrócił wiersza, nie blokuje niczego
  i oba połączenia wstawiają bez czekania.
- **Okno czasowe „ta sama treść w ciągu N sekund"** — przy pustym
  wykonaniu „Ugotowałem" odcisk treści degeneruje się do
  `(user_id, recipe_id)`, czyli do tego, czego D-005 zakazuje, na N sekund.

Mechanizm **zawodzi otwarcie**: nieznany albo brakujący klucz oznacza
„wyślij normalnie", nigdy „odmawiam". Zduplikowany wpis jest dla odbiorcy
50+ mniej szkodliwy niż utracony wpis, a ekran mówiący „ta strona wygasła"
jest gorszy od jednego i drugiego (issue #81, `errors/419.blade.php`).

**Wyłącznik awaryjny wchodzi razem z mechanizmem, nie później:**
`kuking.formularze.klucz_wyslania_wlaczony` (zmienna `KUKING_KLUCZ_WYSLANIA`).
Po ustawieniu na `false` formularze renderują się bez ukrytego pola, kolumna
dostaje `NULL`, częściowy indeks takiego wiersza nie obejmuje i serwis wraca
do zachowania sprzed tej decyzji. To jedyna droga wycofania, która nie wymaga
wdrożenia migracji — dlatego jest w konfiguracji, a nie w kodzie. Nie cofa
natomiast `reports_one_open_per_pair`: tamten indeks nie zależy od niczego,
co wysyła formularz, więc jego wycofanie to osobna migracja.

**Zmiana wymaga:** zmierzonego przypadku, w którym klucz wysłania blokuje
prawdziwe wysyłki, i to takiego, którego nie da się naprawić bez zmiany
samego mechanizmu.

📄 `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` · `docs/DATABASE.md` ·
`config/kuking.php` ·
`app/Domain/Posts/Actions/PublishPost.php` ·
`app/Domain/Recipes/Actions/RecordCookedEvent.php` ·
`app/Domain/Moderation/Actions/ReportContent.php`

---

## D-028 · CI wraca na własne runnery — wybierane etykietami, nie nazwą

**Data:** 7 września 2026 · **Decyzja właściciela** · Status: **obowiązuje
co do zasady, ZAWIESZONA w praktyce od 8 września — patrz poprawka niżej**

> **Zmiana D-010.** D-010 przeniosło CI na runnery GitHuba, bo nowa
> organizacja `woogitsu` dawała nieużywane 2 000 minut miesięcznie. Ta decyzja
> to odwraca: wszystkie joby chodzą na własnej puli
> `woogitsu-linux-01`–`woogitsu-linux-10`.

> **Poprawka z 8 września, wieczorem — decyzja właściciela.** Wszystkie
> **14 jobów** chodzi tymczasowo na `ubuntu-latest`. Zasada zapisana wyżej
> (własna pula, wybierana etykietami) NIE jest odwołana; zmieniła się
> sytuacja, nie strategia. Ta decyzja przewidywała własny warunek zmiany —
> „dłuższa niedostępność puli, która ten koszt zamieni z hipotetycznego
> na zmierzony" — i dokładnie to zaszło.
>
> **Zmierzony koszt, dla którego to piszemy.** Trzy z sześciu runnerów były
> wyłączone. W kolejce stało dziesięć przebiegów po siedem jobów; job
> „Testy (PostgreSQL 18)" czekał na wolną maszynę **ponad godzinę**. Railway
> ma włączone „Wait for CI", więc przez ten czas **nie wdrożył na produkcję
> ani jednej scalonej zmiany** — pięć kolejnych scaleń stało bez efektu.
> Ten skutek był w D-028 wypisany jako hipotetyczny; 8 września przestał być.
>
> **Zmierzony zysk.** Ten sam pełny zestaw na runnerach GitHuba: siedem jobów
> **równolegle**, całość w **3 min 21 s** (Vite 16 s, audyt 23 s, Larastan
> 28 s, Pint 38 s, build obrazu 58 s, testy 3:18, dostępność 3:21).
>
> **Czego to kosztuje.** Repozytorium jest prywatne, więc minuty są płatne.
> Pełny przebieg to orientacyjnie 25–35 minut maszynowych z puli ~2000
> miesięcznie, którą właściciel kazał wykorzystać.
>
> **Jak wrócić.** W każdym z czterech workflow-ów podmienić
> `runs-on: ubuntu-latest` na
> `runs-on: [self-hosted, Linux, X64, woogitsu, i5-10400f, nvidia-gtx1070]`.
> Nagłówek każdego pliku mówi to samo w miejscu, w którym się na to patrzy.
> Nic poza `runs-on` nie wymagało zmiany: usługa `postgres` i odczyt
> zmapowanego portu przez `job.services.postgres.ports[5432]` działają
> jednakowo na obu rodzajach runnerów — sprawdzone przebiegiem, nie założone.
>
> **Czego ta poprawka NIE rozstrzyga.** Kiedy wrócić. To jest decyzja
> właściciela i wymaga jednej informacji, której z repozytorium nie widać:
> czy pula stoi z powodu, który minie sam.

> **Druga poprawka, tego samego wieczoru — powrót przestaje wymagać PR-a.**
> `runs-on` we wszystkich czterech workflow-ach czyta teraz zmienną
> repozytorium `CI_RUNS_ON`; bez niej stoi `ubuntu-latest`. Ustawienie jej
> na listę etykiet własnej puli przełącza CI **bez zmiany w kodzie i bez
> cyklu przeglądu**, a skasowanie wraca na runnery GitHuba.
>
> **To nie jest cofnięcie tego, co D-028 zrobiła z `CI_RUNNER`.** Tamta
> zmienna została usunięta, bo **nic jej nie czytało** — była atrapą
> wyglądającą na przełącznik. Tę czyta `runs-on` w czterech plikach, a powód
> jej istnienia jest zmierzony: przełącznik, który wymaga PR-a i przeglądu,
> nie jest przełącznikiem awaryjnym. 8 września produkcja stała, a zmiana
> puli musiała przejść przez pełną ścieżkę zmiany kodu.
>
> **Przy okazji ucięte marnotrawstwo:** nowy job `zakres` w `ci.yml` pomija
> ciężkie zadania, gdy zmiana dotyka wyłącznie `docs/` albo `README.md`.
> Tego samego wieczoru sześć PR-ów dotykało tylko dokumentacji i każdy
> przepuścił testy na PostgreSQL, axe-core, build obrazu i build assetów.
> **Skutek dla kryterium scalania:** „Testy (PostgreSQL 18)" mogą teraz stać
> jako `skipped` i jest to poprawny stan dla zmiany w dokumentacji — regułą
> jest odtąd „zielone ALBO pominięte".
>
> **Czego NIE dało się zmierzyć i dlaczego to ważne:** ile minut Actions
> realnie zostało. Endpoint `get_workflow_run_usage` zwraca dla każdego
> przebiegu — także sprzed godzin — zerowy czas rozliczeniowy, co przy
> repozytorium PRYWATNYM znaczy najpewniej, że token nie ma dostępu do
> danych rozliczeniowych, a **nie** że przebiegi są darmowe. Jedynym
> wiarygodnym źródłem jest strona rozliczeń organizacji. Nie należy wyciągać
> z tych zer wniosku, że limit nie jest zużywany.

Wszystkie **14 jobów** w czterech workflow-ach (`ci.yml` 7, `deploy.yml` 2,
`preview.yml` 3, `railway-iac.yml` 2) ma dokładnie:

```yaml
runs-on: [self-hosted, Linux, X64, woogitsu, i5-10400f, nvidia-gtx1070]
```

**Etykiety, nie nazwa runnera.** Nazwa w `runs-on` przypina job do jednej
maszyny, więc jej awaria zatrzymuje całe CI, a dziesięciu maszyn nie da się
tak obsłużyć bez macierzy.

**Dlaczego akurat te dwie dodatkowe.** Stara pula WSL-owa
(`woogitsu-wsl-DOM-NEW-01`–`04`) ma etykiety `self-hosted`, `Linux`, `X64`,
`wsl2`, `woogitsu` — czyli samo `self-hosted` wpuściłoby joby także na nie.
`i5-10400f` i `nvidia-gtx1070` występują wyłącznie na nowej puli i to one
są tu bramką.

**Co zniknęło.** Poprzednio runnera wybierała zmienna repozytorium
`CI_RUNNER` z fallbackiem `ubuntu-latest`. Zmiennej nie czyta już nic i można
ją usunąć. Zmierzone przed zmianą (przebieg CI nr 141 dla `main`, commit
`e24f30d`): wszystkie siedem jobów wykonało się na runnerach GitHuba
(`runner_group_name: "GitHub Actions"`, etykiety `["ubuntu-latest"]`), czyli
zmienna nie była ustawiona, a stare runnery WSL-owe nigdy w tym repozytorium
nie pracowały — nie było też w nim ani jednego odwołania do ich nazw.

**Koszt, żeby był zapisany.** Joby nie mają już zapasu w runnerach GitHuba.
Gdy cała pula jest offline, przebiegi stoją w kolejce bez końca — a CI jest
bramką deployu (Railway ma „Wait for CI"), więc stoi wtedy także wdrożenie.
Właściciel wybrał tę opcję świadomie, znając ten skutek.

**Zmiana wymaga:** decyzji właściciela — albo dłuższej niedostępności puli,
która ten koszt zamieni z hipotetycznego na zmierzony.

**Co pula musi mieć, żeby joby przeszły** — etykiety, Docker, rozszerzenia
PHP, sieć wychodząca, miejsce na dysku i pułapka z portem 5432 przy dwóch
runnerach na jednej maszynie — jest wypisane w
`docs/infra/WYMAGANIA_RUNNERA.md`.

📄 `.github/workflows/ci.yml` · `.github/workflows/deploy.yml` ·
`.github/workflows/preview.yml` · `.github/workflows/railway-iac.yml` ·
`docs/infra/WYMAGANIA_RUNNERA.md` · `docs/infra/SELF_HOSTED_RUNNER.md` ·
`docs/infra/CI_BEZ_ACTIONS.md` · `docs/infra/PRZENIESIENIE_DO_ORGANIZACJI.md`

---

## D-029 · Numer sprawy ma własną kolumnę z UNIQUE, nie jest wycinkiem UUID-a

**Data:** 7 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

Numer sprawy pokazywany zgłaszającemu liczył się w **pięciu miejscach kodu
i dwóch widokach** jako osiem pierwszych znaków UUID-a v7 wiersza `reports`.
**Nie był przez to unikalny.** Zmierzone: w UUID-zie v7 pierwsze 48 bitów to
znacznik czasu w milisekundach, więc osiem znaków szesnastkowych to jego 32
GÓRNE bity — zmieniają się raz na 2^16 ms, czyli raz na 65,5 sekundy.

```text
Str::uuid7('2026-09-07 19:00:30') → 01a07d3e-4cb0-7099-…  → 01A07D3E
Str::uuid7('2026-09-07 19:01:10') → 01a07d3e-e8f0-739c-…  → 01A07D3E
```

Dwa różne wiersze, 40 sekund odstępu, jeden numer sprawy.

**Dlaczego to nie jest niezręczność.** Dla zgłaszającego **bez konta** ten
numer jest jedynym śladem sprawy: nie ma konta, nie ma listy zgłoszeń, a
poczty serwis dziś nie wysyła. Numer powtórzony znaczy, że ani on, ani
moderator nie umie powiedzieć, o którą z dwóch spraw chodzi — a każda ma
własny termin odpowiedzi z DSA art. 16.

**Co jest teraz:** kolumna `reports.numer_sprawy varchar(12) NOT NULL`
z indeksem UNIQUE i CHECK-iem na format. Numer nadaje MODEL (hak `creating`),
więc dostaje go każda droga powstania wiersza; `numer_sprawy` nie jest
w `$fillable`, bo to tożsamość nadana przez serwer, nie dana od człowieka.

Format `KU-XXXX-XXXX` z 30-znakowego alfabetu **bez `0`, `1`, `I`, `L`, `O`
i `U`**. Pięć pierwszych znika, bo numer jest przepisywany ręcznie z ekranu
i dyktowany przez telefon — w tej grupie odbiorców `0`/`O`, `1`/`I` i `1`/`L`
to ten sam znak. `U` znika, żeby z ośmiu losowych znaków nie ułożyło się
przypadkiem słowo; ten numer trafia do pisma.

Pierwsza wersja tej stałej miała `U` w alfabecie, mimo że komentarz obok
mówił, że go nie ma — wyszło to na wygenerowanym numerze `KU-F6XC-9U7Y`,
bo test sprawdzał WYLOSOWANY wynik i przechodził w około trzech na cztery
przebiegi. Sprawdza teraz sam alfabet. Zapisane tu, bo to trzeci raz w tym
repozytorium, gdy reguła stała w komentarzu, a nie w kodzie.

**Odrzucone: dłuższy wycinek UUID-a** (np. cztery znaki czasu plus osiem
losowych). Byłoby taniej — bez migracji — ale unikalność zostałaby
STATYSTYCZNA i niepilnowana przez nic. `AGENTS.md` §6 mówi o prawdziwych
ograniczeniach w bazie i tutaj to nie jest formalizm: przy kolumnie z UNIQUE
powtórzony numer jest niemożliwy, a nie tylko nieprawdopodobny.

**Backfill istniejących wierszy jest bezpieczny dokładnie dziś:** poczty nie
ma, więc żaden numer nie został jeszcze nikomu przekazany i nikt nie trzyma
starego w ręku. Po pierwszym wysłanym liście ta sama zmiana byłaby zmianą
numeru pod ręką zgłaszającego i wymagałaby innego planu.

**Zmiana wymaga:** zmierzonej liczby spraw zbliżającej się do rzędu, w którym
30^8 kombinacji przestaje wystarczać (~954 tys. spraw dla 50% szansy kolizji),
albo powodu, dla którego format ma wyglądać inaczej.

📄 `app/Support/NumerSprawy.php` ·
`database/migrations/2026_09_07_910000_add_numer_sprawy_to_reports.php` ·
`app/Models/Report.php` · `tests/Feature/NumerSprawyTest.php` ·
`docs/DATABASE.md`

---

---

## D-030 · Wpis nie dostaje pola „tytuł" — tytuł należy do przepisu

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Zdanie „pola na tytuł nie
> ma, `posts.title` nie istnieje w żadnej migracji" przestało obowiązywać 18
> września 2026 — patrz **D-163**. Migracja
> `database/migrations/2026_09_18_100000_add_kind_and_title_to_posts.php:18`
> dodaje `title varchar(180) NULL`. Sedno tej decyzji przetrwało i jest dziś
> wymuszone bazą: `posts_kind_title_check` wymaga `(kind = 'dish' AND title IS
> NULL)`, więc wpis-danie tytułu nadal nie ma i mieć nie może. Zmieniła się
> klasa obiektu: `kind = 'question'` tytułu WYMAGA (10–180 znaków). D-163 nie
> odesłała tutaj, więc do dziś ten wpis odpowiadał nieprawdziwie na pytanie
> „czy wpis ma tytuł" dla połowy wierszy w `posts`.

System projektowy v3.1 wprowadza `.karta-tytul` i opisuje go wprost jako nowy
element: „dziś karta ma tylko treść, przez co nazwa autora jest największym
napisem w karcie". D-110 daje mu 24 px i wagę 800 — czyli szczyt hierarchii.
Makieta tablicy używa go trzy razy.

Produkt mówi co innego i mówi to od początku: wpis to **„zdjęcie i kilka
słów"** (`docs/brand/BRAND_EXTENDED.md` §1.1), a formularz dodania zdjęcia ma
pola „Napisz kilka słów" i „Kto to widzi". Pola na tytuł nie ma, `posts.title`
nie istnieje w żadnej migracji.

**Rozstrzygnięcie: tytuł zostaje tam, gdzie już jest — w przepisie.**
`.karta-tytul` obsługuje kartę przepisu, nie kartę wpisu.

**Dlaczego nie odwrotnie.** Główna akcja serwisu brzmi „Co dziś ugotowałeś?" —
zdjęcie i kilka słów. Pole tytułu dokłada do niej **jedną decyzję przed
opublikowaniem**, a każda taka decyzja to miejsce, w którym ktoś przestaje
publikować. Grupa 50+ jest na to szczególnie czuła: pusty formularz z trzema
polami jest trudniejszy niż z dwoma, a wpisów bez tytułu jest dziś
osiemdziesiąt i nie ma sensownej odpowiedzi na pytanie, co z nimi zrobić.

**Skutek dla kitu:** największym napisem w karcie wpisu zostaje nazwa autora.
To jest świadome odstępstwo od v3.1, nie przeoczenie — kit dopasowuje się do
danych, nie odwrotnie (ta sama zasada co D-017).

**Zmiana wymaga:** zmierzonego problemu z przeglądaniem feedu, którego nie
rozwiązuje pierwsze zdanie treści wpisu użyte jako podpis.

📄 `docs/design/DESIGN_SYSTEM.md` §2.1 · `resources/views/components/post-card.blade.php` ·
`docs/brand/BRAND_EXTENDED.md` §1.1

---

## D-031 · Zeszyt przyjmuje wpisy, nie tylko przepisy

**Data:** 8 września 2026 · **Decyzja właściciela** (potwierdzenie stanu
wdrożonego 6 września) · Status: **obowiązuje**

Zeszyt powstał na przepisy. Od 6 września przyjmuje też cudze wpisy: migracja
`2026_09_06_150000_collection_items_accept_posts`, trasa
`collections.save-post`, `CollectionController::savePost()`, akcja
`App\Domain\Collections\Actions\SavePostToCollection`, przycisk na karcie
wpisu, test `ZeszytPrzyjmujeWpisyTest`.

Pytanie postawione właścicielowi brzmiało: **zostaje czy cofamy?** — bo
funkcja weszła szybciej, niż powstał wpis, który ją uzasadnia, a dokumenty
projektowe dalej opisywały ją jako „nową funkcję produktową wymagającą
decyzji". Odpowiedź: **zostaje**.

**Dlaczego to nie jest to samo, co zapisanie przepisu.** Zapisany przepis
znaczy „chcę to kiedyś ugotować". Zapisany wpis znaczy „chcę kiedyś zrobić coś
TAKIEGO" — przy wpisie zwykle nie ma żadnego przepisu, jest zdjęcie i kilka
słów. To są dwie różne potrzeby i dlatego zawartość zeszytu pokazuje się
w dwóch grupach, nie wymieszana.

**Co za tym poszło w tej samej zmianie:** teksty, które dalej obiecywały same
przepisy — nagłówek Zeszytu i jego pusty stan
(`resources/views/pages/collections/index.blade.php`) oraz tabela porównawcza
w `docs/design/STAN_WDROZENIA_KITU.md`. Obietnica węższa niż produkt jest
akurat tym rodzajem nieprawdy, którego nikt nie zgłosi — człowiek po prostu
nie spróbuje.

**Zmiana wymaga:** zmierzonego dowodu, że dwie grupy w jednym zeszycie mylą
ludzi bardziej, niż pomaga im samo zapisywanie wpisów.

📄 `app/Domain/Collections/Actions/SavePostToCollection.php` ·
`tests/Feature/ZeszytPrzyjmujeWpisyTest.php` · `docs/design/STAN_WDROZENIA_KITU.md`

---

## D-032 · Plakietka „konto przykładowe" jest krótka i cicha; głośna wolno raz na ekran

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**
· **odwraca D-025 w części o wyglądzie plakietki**

D-025 kazało oznaczać treść zalążkową tak, żeby grupa 50+ zauważyła to bez
czytania drobnego druku: `.badge-przykladowe` na 18 px, z ramką i tłem
akcentu, w każdym miejscu, gdzie widać autora. Zmierzony skutek: w strumieniu
ta sama plakietka powtarzała się kilkanaście razy na jednym ekranie i była
**najgłośniejszym elementem strony** — głośniejszym niż zdjęcia potraw, po
które ludzie tu przychodzą. Oznaczenie, które powtarza się piętnaście razy pod
rząd, przestaje cokolwiek znaczyć.

**Dwie zmiany naraz, bo to jedna sprawa.**

**Waga.** Domyślna plakietka jest cicha (`.badge-cichy`: bez tła, bez ramki,
16 px, waga 600) i stoi w wierszu metadanych, po kropce, obok daty — czytelna
dokładnie wtedy, gdy ktoś patrzy na autora. Głośna (`.badge-przykladowe`,
wygląd bez zmian) zostaje wyłącznie na **profilu** konta przykładowego, czyli
w jedynym miejscu, gdzie stoi dokładnie raz na ekran.

**Treść.** Jedno brzmienie w całym serwisie: **„konto przykładowe"**, bez
członu „— nie prawdziwa osoba". W obiegu były cztery brzmienia w pięciu
miejscach, a `BRAND_EXTENDED.md` §3 mówi: nazwa funkcji jest jedna i nie ma
synonimów.

**Skrócenie nie kasuje informacji, tylko ją przenosi** — i to jest warunek tej
decyzji, nie dopisek. Pełne zdanie („To konto jest przykładowe: nie ma za nim
prawdziwej osoby") stoi **raz**, jako osobny akapit na profilu konta
przykładowego. Bez niego skrót odbierałby ostrzeżenie zamiast je przesunąć.
Test `KontoPrzykladoweWidoczneTest` pilnuje obu połówek naraz: liczy
wystąpienia obu klas na ekranie, a nie samą obecność napisu — usterka, o którą
tu chodzi, polega na POWTÓRZENIU głośnej plakietki, nie na jej braku.

**Zmiana wymaga:** dowodu z testów z osobami 50+ (#15), że cicha plakietka
w wierszu metadanych bywa przeoczona. Nie „wrażenia, że jest za mała".

📄 `resources/views/components/konto-przykladowe.blade.php` ·
`resources/css/app.css` (`.badge-cichy`, `.badge-przykladowe`) ·
`tests/Feature/KontoPrzykladoweWidoczneTest.php` · D-025 · D-103 (system v3.1)

---

## D-033 · Składniki dostają grupy, a przepis przeliczanie porcji

> **Doprecyzowanie właściciela, 20 września 2026, #878:** „Bez ilości” nie
> oznacza „do smaku”. Pokazujemy wyłącznie tekst autora i jego uwagę, bez
> automatycznego dopisku. Zmiana dotyczy prezentacji z #44; flaga i CHECK
> zostają. W zadaniu #741 właściciel polecił poprawić opisy, bez budowania
> skalowania porcji: jest ono nadal niewdrożonym planem V2 (`FEATURES.md`).

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **przyjęta,
niezbudowana** · **poprawia D-017**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Status „przyjęta,
> **niezbudowana**" jest dziś prawdziwy tylko dla połowy tego wpisu. Zdanie
> „grupy składników… **w bazie nie ma na to kolumny**" jest nieprawdziwe:
> kolumna `recipe_ingredients.group_name varchar(120) NULL` stoi w schemacie,
> zapis i ujednolicanie pisowni idą przez
> `app/Domain/Recipes/Actions/PublishRecipe.php:399,452`, scalanie przez
> `app/Domain/Recipes/GrupySkladnikow.php`, nagłówki grup renderuje
> `resources/views/pages/recipes/show.blade.php:468`, a pole w formularzu
> działa bez JavaScriptu
> (`resources/views/pages/recipes/szczegoly.blade.php:339-346`). Druga połowa
> — przeliczanie porcji — faktycznie nie istnieje: `servings` jest zwykłym
> polem liczbowym i nic nie skaluje ilości.
>
> **Proponowany kształt, NIE wykonany przez audyt — do rozstrzygnięcia przez
> właściciela.** Jeden status nie może opisywać rzeczy zbudowanej i
> niezbudowanej naraz, więc wpis prosi się o rozdzielenie: część „grupy
> składników" zostaje pod D-033 ze statusem **obowiązuje, wdrożone**, a część
> „przeliczanie porcji" dostaje pierwszy wolny numer na końcu dziennika ze
> statusem **przyjęta, niezbudowana** i zdaniem „wydzielone z D-033".
> Rozdzielenie zmienia strukturę rejestru i numerację — to nie jest adnotacja
> i audyt tego nie robi.

Pierwotne pytanie („czy składnik ma osobne pole na ilość") było nieaktualne
w chwili zadawania: `recipe_ingredients` ma `quantity` (decimal 12,4),
`unit_id` i `no_amount` od 5 września. **D-017 rozjechało się przez to ze
schematem własnej bazy** — mówi „składniki z kolumną ilości: nie i nie
będzie", a kolumna jest.

Zostały dwie rzeczy, których naprawdę nie ma, i obie właściciel przyjął do
zbudowania:

1. **Grupy składników.** Strona przepisu w systemie v3.1 grupuje je pod
   nagłówkami („Ciasto", „Farsz", „Do podania"). W bazie nie ma na to kolumny.
2. **Przeliczanie porcji.** Makieta kroku 2 obiecuje pod polami „żeby dało się
   je potem przeliczyć na inną liczbę porcji". Nic tego nie liczy.

**Czego to nie wolno złamać.** `no_amount` istnieje dokładnie po to, żeby „sól
do smaku" nie skalowała się razy trzy (issue #44), a CHECK
`recipe_ingredients_no_amount_check` pilnuje, że składnik bez ilości nie ma
ani `quantity`, ani `unit_id`. Przeliczanie porcji musi te wiersze zostawić
w spokoju — to jest warunek wbudowany w bazę, nie uprzejmość.

**Odrzucone: zostawić jak jest i skasować obietnicę.** Byłoby tanie (jedno
zdanie z pomocy przy kroku 2), ale przepis bez grup jest listą dwudziestu
pozycji bez podziału na ciasto i farsz — a to jest dokładnie ten przepis,
który się drukuje i kładzie obok blatu.

**Zanim to powstanie:** D-017 ma opisywać stan faktyczny — ilość JEST,
skalowania nie ma — a nie zaprzeczać schematowi.

**Zmiana wymaga:** nowej decyzji właściciela; ta jest świeża i nie ma jeszcze
kodu, który mogłaby unieważnić.

📄 `database/migrations/2026_09_06_130000_add_no_amount_to_recipe_ingredients.php` ·
`database/migrations/2026_09_08_100000_add_group_name_check_to_recipe_ingredients.php` ·
issue #44 · D-017 ·
`docs/ROADMAP.md`

---

## D-034 · Kreator przepisu dostaje trzy adresy, po jednym na krok

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **przyjęta,
niezbudowana**

Dziś są dwa adresy: `/dodaj/przepis` (kreator Livewire w trzech krokach,
wymaga JavaScriptu) i `/dodaj/przepis/jedna-strona` (ten sam formularz zwykłym
POST-em). Kroki istnieją — „Krok 1 z 3", „Krok 2 z 3", „Krok 3 z 3" —
ale **wszystkie trzy mieszkają pod jednym adresem**.

Właściciel przyjął wariant z trzema adresami (`/dodaj/przepis`,
`/dodaj/przepis/skladniki`, `/dodaj/przepis/kroki`) plus jednostronicowy
`/dodaj/przepis/wszystko`.

**Dlaczego adres, a nie stan w komponencie.** Krok bez własnego adresu nie ma
przycisku „wstecz" przeglądarki, nie da się go dodać do zakładek, nie wraca po
odświeżeniu i nie działa bez JavaScriptu — a „ważne funkcje działają bez
JavaScriptu" jest zasadą projektu, nie preferencją. Dla osoby, która spisuje
przepis babci przez dwadzieścia minut, odświeżona strona bez adresu kroku
znaczy: od początku.

**Co musi wejść razem z tym:** zapis szkicu na serwerze po każdym kroku
(inaczej trzy adresy tylko rozkładają utratę danych na trzy razy),
przekierowanie ze starego adresu jednostronicowego i sprawdzenie
podświetlenia „Dodaj" w nawigacji na wszystkich czterech adresach — to już raz
było zepsute.

**Zmiana wymaga:** nowej decyzji właściciela.

📄 `resources/views/components/recipe-wizard.blade.php` · `routes/web.php` ·
D-108 (system v3.1)

---

## D-035 · Natywne pole wyboru pliku znika za własnym obszarem

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **przyjęta,
niezbudowana**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Ten wpis nosi status
> „przyjęta, **niezbudowana**" i opisuje stan zastany zdaniem: „dziś `<input
> type="file">` jest w pełni widoczny wewnątrz dużego obszaru »Dodaj zdjęcie«,
> a komentarz mówi wprost, że zostaje widoczny celowo". Dziś jest dokładnie
> odwrotnie: `resources/views/pages/posts/create.blade.php:73-90` ma pole z
> klasą `visually-hidden pole-zdjecia-input`, a komentarz nad nim brzmi
> „Natywne pole pliku jest tu SCHOWANE DLA OKA (decyzja właściciela D-035)".
> Warunki wykonania postawione w tym wpisie są spełnione: nie `display:none`,
> prawdziwa `<label for>`, `aria-labelledby` i obwódka `:focus-visible`.
> **Decyzja jest wdrożona — nieaktualny jest jej status i akapit „Dziś…".**

Dziś `<input type="file">` jest w pełni widoczny wewnątrz dużego obszaru
„Dodaj zdjęcie", a komentarz w `pages/posts/create.blade.php` mówi wprost, że
zostaje widoczny celowo. Skutek: w środku polskiego formularza siedzi
angielskie „Choose File / No file chosen", którego nie da się przetłumaczyć —
rysuje je przeglądarka.

Właściciel rozstrzygnął, że wolno je schować i klikalna zostaje sama etykieta.

**Strata jest świadoma i zapisana tutaj, żeby nikt jej potem nie odkrył jako
usterki.** Nazwa pliku w natywnym polu była jedynym potwierdzeniem, że wybór
się udał. Po schowaniu pola — **bez JavaScriptu między kliknięciem
a wysłaniem człowiek nie dostaje nic**. Potwierdzenie przychodzi dopiero
z serwera: po wysłaniu widać miniaturę i „Zmień zdjęcie" (to już działa).

**Warunek wykonania:** samo pole musi zostać w drzewie dostępności i pod
klawiaturą (nie `display: none`), a etykieta musi być prawdziwą `<label>`
związaną z polem — inaczej zamiast jednego angielskiego napisu mamy
formularz, którego nie da się wypełnić czytnikiem ekranu.

**Zmiana wymaga:** dowodu z testów z osobami 50+ (#15), że brak potwierdzenia
między kliknięciem a wysłaniem powoduje porzucanie formularza.

📄 `resources/views/pages/posts/create.blade.php` · D-107 (system v3.1) ·
`docs/UX_50_PLUS.md`

---

## D-036 · Zapisanie do Zeszytu nazywa się „Zapisuję"

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

Ta sama czynność miała w produkcie dwie nazwy naraz: karta wpisu mówiła
„Zapisz", a karta przepisu i pusty Zeszyt — „Zapisuję". `BRAND_EXTENDED.md` §3
zabrania synonimów: nazwa funkcji jest jedna.

Wybrane brzmienie: **„Zapisuję"**, w pierwszej osobie, tak jak „Ugotowałem".
Serwis mówi głosem człowieka, który klika, nie głosem systemu wydającego
polecenie — to jest ten sam wybór, co przy głównej akcji produktu.

**Czego to nie dotyczy:** „Zapisz szkic", „Zapisz zmiany", „Zapisz poprawkę".
To są inne czynności — zapisanie **swojej** pracy, nie odłożenie **cudzej**
rzeczy do Zeszytu — i mają zostać w trybie rozkazującym.

**Zmiana wymaga:** wyniku testów z osobami 50+ (#15) mówiącego, że pierwsza
osoba w przycisku myli.

📄 `docs/brand/BRAND_EXTENDED.md` §1.2 · `docs/brand/COPY_STYLE.md` ·
`resources/views/components/post-card.blade.php`

---

## D-037 · Gospodarzem, który podpisuje wiadomości, jest Ula

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

`COPY_STYLE.md` §8 trzymał to jako otwarte od początku projektu: „Imię
gospodarza w e-mailach. Bez prawdziwego imienia digest traci większość swojej
wartości". Rozstrzygnięcie: **Ula**.

**To nie jest to samo pole, co `host_username`.** `host_username`
(dziś `woogitsu`) to nazwa KONTA, którą czyta mechanizm — auto-obserwowanie
gospodarza przy rejestracji — i która musi dać się znaleźć w bazie.
`host_name` to imię, którym serwis PODPISUJE się przed człowiekiem. Dwie różne
rzeczy, dwa pola, jeden plik.

Imię mieszka w jednym miejscu, `config('kuking.community.host_name')`, i stamtąd
składa się nazwa nadawcy poczty („Ula z Kuking"). Nie jest wpisane osobno
w żadnym szablonie — gospodarz może się zmienić i wtedy to ma być jedna
linijka, nie przeszukiwanie widoków.

**Uwaga wdrożeniowa:** `MAIL_FROM_NAME` ustawione w panelu Railway **wygrywa**
z tą konfiguracją. Jeśli tam stoi stara wartość, e-maile dalej będą podpisane
po staremu — trzeba ją usunąć albo zaktualizować ręcznie.

**Zmiana wymaga:** zmiany osoby, która prowadzi społeczność.

📄 `config/kuking.php` (`community.host_name`) · `config/mail.php` ·
`docs/brand/COPY_STYLE.md` §6 · `docs/product/RETENTION_LOOPS.md` §4

---

## D-038 · Gdy dokument i kod mówią co innego, poprawiamy to, co jest nieprawdą

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

Dwa rozjazdy postawione właścicielowi tego samego dnia, oba rozstrzygnięte
w tę samą stronę — **dokument dogania kod, bo to dokument kłamał**:

**Polityka prywatności.** Twierdziła wytłuszczonym drukiem, że automatycznego
usuwania zgłoszeń, dziennika zdarzeń i powiadomień **nie ma**. Trzy komendy
kasują je codziennie o 04:10, 04:20 i 04:30. Wpisane prawdziwe okresy:
36 miesięcy od zamknięcia sprawy moderacyjnej, 12 miesięcy dziennika zdarzeń,
3 miesiące powiadomień — każdy z wyjątkami, które kod naprawdę stosuje.
Wariant odwrotny (wyłączyć automaty, żeby dokument znów był prawdziwy)
odrzucony: usuwanie danych po terminie jest obowiązkiem, nie funkcją.

**Termin odwołania.** `docs/MODERATION.md` mówiło 14 dni. Kod bierze WIĘKSZĄ
z dwóch wartości: `appeal_days` (180) i sztywnych sześciu miesięcy
(`ModerationAction::appealDeadline()`), a regulamin §8 i podręcznik moderatora
mówią 6 miesięcy. Dokument techniczny był po prostu ostatni, który o tym
nie wiedział.

> **Skąd naprawdę bierze się te sześć miesięcy — dopisane 9 września po
> audycie zewnętrznym (G17).** Stało tu zdanie „bo tyle WYMAGA DSA art. 20
> ust. 1. Skrócenie do 14 dni byłoby złamaniem przepisu". To było fałszywe
> uzasadnienie prawdziwej liczby. Art. 20 leży w Sekcji 3 rozdziału III DSA,
> a **art. 19 wyłącza całą tę sekcję** dla mikro- i małych przedsiębiorstw.
> Serwis prowadzi SAMSUFI sp. z o.o. (D-040) — spółka handlowa jest
> przedsiębiorstwem bez cienia interpretacji i przy dzisiejszej skali mieści
> się w progu mikroprzedsiębiorstwa, więc **art. 20 nas nie wiąże**.
>
> **Termin zostaje i to się nie zmienia.** Zmienia się tylko to, CZYM jest:
> nie obowiązkiem z rozporządzenia, tylko **obietnicą złożoną człowiekowi
> w regulaminie §8**. To wiąże nas mocniej niż przepis, z którego jesteśmy
> zwolnieni — bo ktoś tę obietnicę przeczytał i na niej polega. Skrócenie
> wymaga zmiany regulaminu i powiadomienia użytkowników, nie samej zmiany
> `config/kuking.php`.
>
> **Dlaczego to w ogóle zapisujemy, skoro liczba się nie zmienia:** fałszywe
> uzasadnienie jest groźniejsze niż jego brak. Kto przeczyta „art. 20 nas
> wiąże", wyprowadzi z tego resztę Sekcji 3 — pozasądowe rozstrzyganie
> sporów (art. 21), zaufanych sygnalistów (art. 22), pełne sprawozdanie
> przejrzystości (art. 24) — i zacznie budować miesiące pracy, której robić
> nie trzeba. Zakres i granice zwolnienia: `docs/legal/COMPLIANCE.md` §1.2.

**Reguła na przyszłość, bo to trzeci taki przypadek w tym repozytorium:**
rozjazd między dokumentem a kodem rozstrzyga się **od strony faktu**, nie od
strony tego, co łatwiej poprawić. Jeśli faktem jest kod — poprawiamy dokument.
Jeśli faktem jest przepis albo obietnica dana człowiekowi — poprawiamy kod.
Nigdy nie zostawiamy obu wersji „do wyjaśnienia": z dwóch sprzecznych zdań
o serwisie jedno na pewno wprowadza kogoś w błąd.

**Zmiana wymaga:** nic — to jest zasada porządkowa, nie wybór produktowy.

📄 `resources/legal/polityka-prywatnosci.md` · `docs/MODERATION.md` ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` · D-024

---

## D-039 · Odwołanie zamyka administrator, nie rola pierwszej linii

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje,
wdrożona 8 września**

DSA art. 20 daje prawo do odwołania od decyzji moderacyjnej. Do 8 września
odwołanie zamykał każdy moderator — jedyną barierą było 24 godziny karencji,
zanim ten sam moderator PODTRZYMA własną decyzję, a ta nie przeszkadzała ani
cofnąć własnej od razu, ani zamknąć sprawy dowolnemu INNEMU moderatorowi bez
żadnego opóźnienia. Właściciel rozstrzygnął: **rozdzielić role** — decyzję
o odwołaniu przyjmuje wyłącznie konto z rolą `admin`
(`UserPolicy::resolveAppeals()`). Kolejkę odwołań widzi dalej każdy moderator;
formularz odpowiedzi widzi tylko administrator.

**Dlaczego karencja to za mało.** Doba nie robi z tej samej osoby drugiej
instancji. Człowiek, którego treść usunięto, ma dostać spojrzenie kogoś
innego, a nie tego samego spojrzenia po przespanej nocy.

**CO TA ZMIANA NAPRAWDĘ ROBI — bo pierwsza wersja tego wpisu mówiła za
dużo.** To jest bramka na ROLĘ, nie na osobę. Administrator przechodzi też
przez `moderate()`, więc jeden człowiek z tą rolą dalej może wydać decyzję
i zamknąć odwołanie od niej samej; powstrzymuje go wtedy wyłącznie karencja
`ResolveAppeal::sprawdzKarencje()` i tylko przy PODTRZYMANIU. Wartość
pojawia się przy DRUGIEJ osobie w zespole: moderator bez roli administratora
przestaje móc zamknąć sprawę, którą sam rozstrzygał.

**WARUNEK WDROŻENIA BYŁ REALNY I ZOSTAŁ SPEŁNIONY.** `User::promoteTo()`
i `User::isAdmin()` nie miały w tym repozytorium **ani jednego wywołania**,
a żaden seeder nie nadawał roli `admin`. Samo zawężenie Policy zamknęłoby
odwołania na głucho: nie byłoby kto ich rozstrzygnąć, a termin z DSA biegłby
dalej. Dlatego razem z zawężeniem weszła komenda
`php artisan kuking:nadaj-role <login> admin` — z powłoki produkcyjnej, bez
ekranu w produkcie, bo ekran znaczyłby, że przejęcie jednego konta
administratora wystarcza, żeby zrobić administratorów z kolejnych. Komenda
odmawia odebrania roli OSTATNIEMU czynnemu administratorowi i zapisuje każdą
zmianę w `audit_log` jako `user.role_changed`.

**PIERWSZA CZYNNOŚĆ PO WDROŻENIU:** nadać sobie tę rolę na produkcji. Do
tego czasu nie ma tam nikogo, kto może zamknąć odwołanie — otwartych spraw
nie było w chwili wdrożenia, więc okno jest bezpieczne, ale tylko dopóki
nikt się nie odwoła.

**Zmiana wymaga:** drugiego moderatora, przy którym rozdzielenie ról da się
zrobić bez jednoosobowego wąskiego gardła.

📄 `app/Policies/UserPolicy.php` (`resolveAppeals`) ·
`app/Http/Controllers/Admin/AppealController.php` ·
`app/Console/Commands/NadajRole.php` · `tests/Feature/NadanieRoliTest.php` ·
`docs/MODERATION.md` · DSA art. 20

---

## D-040 · Kuking prowadzi spółka SAMSUFI, nie osoba fizyczna

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

> **Rozstrzyga lukę, nie zmienia decyzji.** Regulamin i polityka prywatności
> mówiły dotąd „serwis prowadzi osoba fizyczna" i obiecywały podanie danych
> „zanim otworzymy rejestrację dla wszystkich". To nie była decyzja — to było
> puste miejsce, które blokowało otwarcie.

Administratorem danych i podmiotem prowadzącym serwis jest **SAMSUFI Spółka
z ograniczoną odpowiedzialnością**, ul. Jagiellońska 4A, 19-120 Knyszyn,
KRS 0000901262, NIP 5423435334, REGON 388971059.

**DLACZEGO TO NIE MOGŁO POCZEKAĆ.** RODO art. 13 ust. 1 lit. a każe podać
tożsamość administratora **w momencie zbierania danych** — czyli na ekranie
rejestracji, a nie po e-mailu na żądanie. Zdanie „możesz o nie poprosić
i je otrzymasz" brzmiało uczciwie, ale przenosiło na człowieka obowiązek,
który spoczywa na nas.

**DANE STOJĄ W KONFIGURACJI, NIE TYLKO W DOKUMENCIE.** `config/kuking.php`
(`kuking.podmiot`) jest źródłem, a `DokumentyPrawneNieKlamiaTest` porównuje
z nim treść obu dokumentów. Numer KRS zmienia się w rejestrze, nie w pliku
markdown — bez tego porównania poprawka w jednym miejscu zostawiłaby
w drugim nieprawdę na żywej stronie. To ten sam mechanizm, którym D-038
pilnuje okresów retencji.

**ADRES KONTAKTOWY TO `biuro@samsufi.pl`, NIE `kontakt@kuking.pl`** — i to
jest świadome. Adres w domenie kuking.pl zależy od poczty, której 8 września
jeszcze nie ma (`MAIL_MAILER=log`). Dokument prawny musi podawać adres,
o którym wiadomo, że ktoś go czyta; adres serwisowy stoi obok jako drugi.
Gdy poczta na kuking.pl ruszy i zostanie potwierdzone, że odbiera, kolejność
można odwrócić — ale nie wcześniej.

**Zmiana wymaga:** zmiany w rejestrze przedsiębiorców albo przeniesienia
serwisu do innego podmiotu.

📄 `config/kuking.php` (`kuking.podmiot`) · `resources/legal/regulamin.md` §1 ·
`resources/legal/polityka-prywatnosci.md` §1 ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` ·
`docs/legal/BRAMKA_BETY.md` §8 · RODO art. 13 ust. 1 lit. a

---

## D-041 · Błędy 500 dziś idą webhookiem na Slack/Discord, nie Sentry

**Data:** 8 września 2026 · Status: **obowiązuje do czasu, aż `composer
install` znów zadziała w środowisku pracy**

`docs/ROADMAP.md` §0 nazywa monitoring błędów fundamentem, a do dziś strona
mogła wywalić się na 500 i nikt po naszej stronie by się o tym nie dowiedział
— pierwszy sygnał dostawałby użytkownik. Docelowym wyborem jest **Sentry**
(patrz tabela w `AGENTS.md` §3 i `docs/infra/MONITORING_BLEDOW.md`), ale w środowisku,
w którym ta praca powstała, `composer install` odbija się od proxy na
paczkach z GitHuba — nie da się więc uczciwie zaktualizować
`composer.lock`, żeby dodać `sentry/sentry-laravel`. Instalowanie pakietu
bez działającego `composer install` (np. ręczne dopisanie do `composer.lock`)
zostało odrzucone: taki lock plik kłamie o tym, co naprawdę zostało
rozwiązane przez Composera, i pęka przy pierwszym prawdziwym `composer
install` kogokolwiek innego.

**Co wybrano zamiast tego.** Kanał `blad_webhook` w `config/logging.php`:
`$exceptions->report()` w `bootstrap/app.php` wysyła każdy realnie
raportowany wyjątek (Laravel i tak pomija 4xx/419/429 — patrz komentarz przy
`ThrottleRequestsException`) na webhook zgodny z formatem Slacka, na który
Discord odpowiada pod końcówką `/slack` — czyli darmowe powiadomienie na
telefon bez zakładania jakiegokolwiek konta płatnego. Zero nowych zależności
Composera: Monolog i klient HTTP są już częścią Laravela.

**Dlaczego to NIE jest wbudowany sterownik `slack` Laravela.**
`Monolog\Handler\SlackWebhookHandler` łączy się przez `curl_init()`
z pominięciem klienta HTTP Laravela — nie da się tego przechwycić
`Http::fake()`, więc nie dałoby się TESTEM dowieść, że treść wysyłana na
zewnątrz nie niesie danych osobowych. Domyślnie dokleja też do wiadomości
CAŁY kontekst rekordu logu, czyli m.in. obiekt wyjątku z argumentami wywołań
ze stosu. `App\Logging\WebhookBleduHandler` buduje treść ręcznie, z jawnie
wybranych pól (klasa, komunikat, plik:linia, wzorzec trasy, ślad BEZ
argumentów) i wysyła przez `Illuminate\Support\Facades\Http` — w pełni
testowalne, w pełni pod kontrolą co do treści. AGENTS.md §7 zakazuje PII
w logach, a to jest jedyny log w serwisie, który wychodzi do usługi, nad
którą nie mamy żadnej kontroli.

**Czego ta decyzja świadomie NIE rozstrzyga.** Czy sam fakt wysyłania
(nawet pozbawionej PII) telemetrii błędów do Discorda/Slacka wymaga wpisu
w tabeli podprocesorów polityki prywatności — D-024 i D-038 pilnują, żeby
ta tabela nigdy nie mijała się z prawdą, w żadną stronę. Projekt tego kanału
zakłada, że nic osobowego tam nie trafia (i to jest przetestowane —
`tests/Feature/BladTrafiaNaWebhookBezDanychOsobowychTest.php`), więc na dziś
nic nie zmieniono w `resources/legal/polityka-prywatnosci.md`. Właściciel
powinien to potwierdzić przed włączeniem `LOG_BLAD_WEBHOOK_URL` na
produkcji — patrz `docs/infra/MONITORING_BLEDOW.md`.

**Zmiana wymaga:** działającego `composer install` w środowisku pracy (żeby
dało się dodać `sentry/sentry-laravel` i zaktualizować `composer.lock`
uczciwie) ORAZ decyzji właściciela o założeniu konta Sentry. Kanał
webhookowy zostaje jako zapasowa, tania sieć bezpieczeństwa nawet po
wdrożeniu Sentry — nie ma powodu go kasować.

📄 `config/logging.php` (kanał `blad_webhook`) · `bootstrap/app.php` ·
`app/Logging/WebhookBleduLogger.php` · `app/Logging/WebhookBleduHandler.php` ·
`tests/Feature/BladTrafiaNaWebhookBezDanychOsobowychTest.php` ·
`docs/infra/MONITORING_BLEDOW.md` · `docs/ROADMAP.md` §0

---

## D-042 · Zgłaszający ze zwykłego formularza dostaje pouczenie, nie formularz skargi

**Data:** 9 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

> **Rozstrzyga pytanie zadane w PR #186, żeby nie wracało.** Ten sam wybór
> stawał już pod trzema nazwami: „Luka 1" w issue #10, „czy art. 20 obejmuje
> zgłaszających" w `docs/research/DSA-LUKI.md` §5 i pytanie autora #186.
> Odpowiedź jest jedna i stoi tutaj.

Osoba, która zgłasza treść przyciskiem „Zgłoś", dostaje z DSA art. 16:
potwierdzenie przyjęcia z numerem sprawy (ust. 4), informację o decyzji
(ust. 5) i przy niej **pouczenie o dostępnych środkach**. Nie dostaje
formalnego wewnętrznego systemu rozpatrywania skarg. Ten zostaje —
tak jak dotąd — przy zgłoszeniach prawnych (`Report::jestZgloszeniemPrawnym()`).

**DLACZEGO TO NIE JEST OSZCZĘDZANIE NA LUDZIACH.** Wewnętrzny system skarg
to art. 20 DSA, a art. 20 leży w **Sekcji 3**, z której Kuking jest zwolniony
jako małe przedsiębiorstwo (art. 19; kwalifikacja przez D-040 i
`docs/legal/COMPLIANCE.md` §1.2). Art. 16 leży w Sekcji 2 i wiąże niezależnie
od wielkości — i jest spełniony. Pouczenie mówi człowiekowi, co może zrobić
dalej: podaje numer sprawy, adres kontaktowy, zdanie o organie pozasądowym
i o sądzie. To nie jest odesłanie z kwitkiem.

**DLACZEGO NIE OTWORZYLIŚMY TEGO „PRZY OKAZJI", SKORO KOD JUŻ JEST.**
Bo koszt nie leży w kodzie. `FileReporterAppeal` istnieje i zdjęcie z niego
jednego warunku to praca na jeden PR. Kosztem jest **druga kolejka spraw
do rozpatrzenia przez jedną osobę** — a `docs/product/SOUL.md` i teza 2
z audytu A6 mówią to samo: jednoosobowa obsługa musi mieć jawny limit,
nie ukrytą obietnicę dyżuru. Obietnica rozpatrzenia skargi, na którą nie ma
czasu, jest gorsza niż jej brak.

**CO BY TO ZMIENIŁO.** Gdyby prawnik uznał, że zwolnienie z Sekcji 3 nie
obejmuje tej sytuacji, zakres jest znany i policzony: zdjąć warunek
`jestZgloszeniemPrawnym()` z `FileReporterAppeal` i dać zgłaszającemu
z kontem wejście na istniejący formularz. Ta decyzja nie zamyka tamtej drogi,
tylko mówi, że dziś nią nie idziemy.

**CZEGO TA DECYZJA NIE ROZSTRZYGA.** Nie rozstrzyga, czy zgłoszenie ze
zwykłego formularza jest w ogóle „zawiadomieniem o treści nielegalnej"
w rozumieniu art. 16, czy tylko zgłoszeniem naruszenia regulaminu. Nasza
lista powodów miesza jedno z drugim: „spam" to nasza zasada, ale „mowa
nienawiści" i „dotyczy dziecka" to zarzuty nielegalności. Postąpiliśmy
najostrożniej — odpowiedź i pouczenie idą do **wszystkich** zgłaszających,
niezależnie od wybranego powodu, bo nadmiar odpowiedzi nikomu nie szkodzi,
a jej brak jest naruszeniem. **Kwalifikacji prawnej nie rozstrzyga model** —
to pytanie zostaje otwarte dla prawnika i jego odpowiedź może dołożyć wymogi
(termin odpowiedzi, informacja o użyciu narzędzi automatycznych).

📄 `app/Domain/Moderation/Actions/FileReporterAppeal.php` ·
`app/Models/Report.php` (`jestZgloszeniemPrawnym()`) ·
`app/Domain/Moderation/OdpowiedzDlaZglaszajacego.php` ·
`docs/legal/COMPLIANCE.md` §1.2 · `docs/research/DSA-LUKI.md` §5 · D-040

---

## D-043 · Kopia poza Railwayem robi osobny serwis Railway, nie scheduler aplikacji

**Data:** 9 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**,
**wykonanie PILNE** (patrz sprostowanie niżej)

> **SPROSTOWANIE Z TEGO SAMEGO DNIA — CZYTAJ RAZEM Z WPISEM.**
> Pierwsza wersja tego wpisu nazywała zrzut offsite „trzecią warstwą" i pisała,
> że do jego powstania chronią nas Volume Backups i PITR w Railwayu. **To była
> nieprawda.** Właściciel sprawdził panel: **Volume Backups i PITR są dostępne
> wyłącznie w planie Pro**, a Kuking jest na Free i przechodzi na Hobby.
>
> Nie ma więc trzech warstw ani dwóch. **Jest zero.** Zrzut z #193 nie jest
> ostatnią linią obrony — jest jedyną, i przestaje być pracą „po R2".
>
> Sam kierunek decyzji zostaje bez zmian i jest teraz jeszcze mocniejszy:
> osobny serwis, bo w kontenerze aplikacji `proc_open` jest zablokowany;
> nie GitHub Actions, bo poświadczenie do bazy nie ma opuszczać Railwaya.

> **Rozstrzyga sprzeczność w istniejącym planie, nie dokłada nowej warstwy.**
> `docs/infra/INFRA_DECISION.md` §10 zakładał trzy warstwy kopii i trzeciej —
> zrzutu `pg_dump` poza Railwayem — nie da się uruchomić tam, gdzie tamten
> dokument ją umieścił.

Trzecia warstwa mieszka w **osobnym, minimalnym serwisie Railway**
uruchamianym harmonogramem: `pg_dump` → szyfrowanie → R2. Praca opisana
w #193.

**DLACZEGO NIE W KONTENERZE APLIKACJI — TO NIE JEST WYGODA, TYLKO ŚCIANA.**
`docker/php.ini` ma `disable_functions=...,proc_open,...`, a `pg_dump` wołany
z PHP potrzebuje dokładnie `proc_open` (`Symfony\Process`). To nie jest
przeoczenie: `routes/console.php` używa wyłącznie `Schedule::call()`, a jedyne
dwa wystąpienia `Schedule::command()` w tym pliku stoją w komentarzu
zaczynającym się od „UWAGA — NIE UŻYWAMY GO TUTAJ", który podaje tę samą
przyczynę i dopisuje, że na produkcji kończyło się to natychmiastowym błędem. Osłabienia tego hardeningu zabrania
`AGENTS.md`, więc „zrzut na schedulerze" nie jest do naprawienia — jest do
przeniesienia.

**DLACZEGO NIE GITHUB ACTIONS**, mimo że to najtańsze i nie wymaga nowego
serwisu: produkcyjne poświadczenie do bazy musiałoby trafić do sekretów
GitHuba. Powstałaby **druga kopia najwrażliwszego klucza, w innym systemie
niż baza**. Wybrany wariant trzyma poświadczenie wewnątrz Railwaya i łączy
się po sieci wewnętrznej.

**DLACZEGO NIE RĘCZNIE RAZ W TYGODNIU.** Bo zależy od tego, że człowiek
pamięta. Przy jednoosobowej obsłudze to jest obietnica, która łamie się po
trzech tygodniach — a łamie się cicho.

**DLACZEGO W OGÓLE TRZECIA WARSTWA, SKORO RAILWAY ROBI KOPIE SAM.** Bo Volume
Backups i PITR leżą **w tym samym miejscu, co baza**. Utrata konta, pomyłka
w panelu albo awaria po stronie dostawcy zabiera jednocześnie bazę i obie jej
kopie. Warstwa offsite istnieje dokładnie na ten jeden scenariusz.

**CO JEST WAŻNIEJSZE OD SAMEGO ZRZUTU.** Dwie rzeczy, obie w kryteriach #193:
**alarm, gdy zrzut nie powstanie** (backup, który po cichu przestał się robić,
jest gorszy niż jego brak, bo daje fałszywe poczucie bezpieczeństwa), oraz
**jedno prawdziwe odtworzenie z tej warstwy**, wpisane do tabeli w
`KOPIE_I_ODTWORZENIE.md` §5. Zrzut, którego nikt nigdy nie odtworzył, nie
jest kopią — to plik, o którym się zakłada, że jest kopią.

**CO CHRONI NAS DO TEGO CZASU — NIC.** Tak brzmi poprawna odpowiedź po
sprawdzeniu panelu. Volume Backups i PITR to funkcje planu Pro; na Free
i Hobby ich nie ma. Pytania 1 i 2 z `KOPIE_I_ODTWORZENIE.md` §2.3
(„czy backupy są włączone", „od kiedy liczy się okno PITR") są **bezprzedmiotowe
przy obecnym planie** i trzeba je tam przeformułować.

**CO Z TEGO WYNIKA DLA KOLEJNOŚCI.** `docs/OTWARCIE.md` stawia etap 0 (kopia
i ćwiczenie odtworzenia) przed wszystkim innym i to zostaje — ale etap 0 nie
sprowadza się już do przeklikania dwóch przełączników. Wymaga wykonania #193,
a #193 potrzebuje miejsca do lądowania zrzutu, czyli bucketu z #120.
**R2 ma darmowy pułap 10 GB**, więc pieniądze nie są tu przeszkodą — przeszkodą
jest tylko to, że bucket jeszcze nie istnieje.

📄 `docs/infra/INFRA_DECISION.md` §10 · `docs/infra/KOPIE_I_ODTWORZENIE.md`
§2.1, §2.3, §5 · `docs/OTWARCIE.md` etap 0 · `docker/php.ini` ·
`routes/console.php` · #193 · #120 · audyt A6, bramka A6-07

---

## D-044 · „Podziel się": arkusz systemowy nad jawną listą, bez Messengera w wersji podstawowej

**Data:** 9 września 2026 · **Decyzja właściciela (mechanizm) + pomiar (lista dróg)** ·
Status: **obowiązuje**

### Mechanizm — decyzja właściciela

Na telefonie jeden duży przycisk „Podziel się" otwiera **arkusz systemu**
(`navigator.share`) — tam człowiek widzi swojego Messengera, WhatsAppa
i SMS-y. Na komputerze i wszędzie tam, gdzie tego arkusza nie ma, stoi
**jawna lista** dróg plus adres do skopiowania. **Zawsze widać coś, co
działa** — nigdy pusty przycisk, nigdy „twoja przeglądarka nie obsługuje".

Kolejność warstw wynika z `AGENTS.md` §5 i jest odwrotna, niż podpowiada
intuicja: `navigator.share` jest JavaScriptem z definicji, więc **wersją
podstawową, renderowaną przez serwer, jest jawna lista**, a arkusz jest
ulepszeniem nałożonym na ten sam przycisk.

### Czego NIE ma na jawnej liście i dlaczego (zmierzone 9 września 2026)

| Droga | Wynik pomiaru | Decyzja |
|---|---|---|
| `wa.me/?text=…` | 200, przekierowanie na `api.whatsapp.com/send/?text=…&type=custom_url` | **jest** — działa bez żadnej rejestracji |
| `mailto:?subject=…&body=…` | zawsze | **jest** |
| `facebook.com/sharer/sharer.php?u=…` | 200, przekierowanie na `facebook.com/share_channel/?type=reshare&link=…&app_id=966242223397117` — Facebook podstawia WŁASNY `app_id` | **jest**, opisane uczciwie jako „wstawisz na swoją tablicę" |
| `facebook.com/dialog/send` (Messenger, wyślij osobie) | bez `app_id` kończy się na `facebook.com/login` — okno wysyłania w ogóle się nie otwiera | **nie ma** |
| `fb-messenger://share?link=…` | protokół aplikacji: na komputerze bez Messengera przeglądarka pokazuje błąd nieznanego protokołu | **nie ma** |
| `sms:?body=…` | na telefonie działa, na komputerze najczęściej nie robi nic | **nie ma** |

**Messengera nie da się dziś dać jako linku bez zarejestrowania własnej
aplikacji na Facebooku** (`app_id` + weryfikacja domeny + regulamin Meta).
To jest pytanie do właściciela, nie do agenta — więc funkcja jest zbudowana
tak, że Messenger i tak działa tam, gdzie ludzie z niego korzystają
naprawdę: w arkuszu systemowym na telefonie.

**Decyzja do podjęcia przez właściciela:** czy zakładamy aplikację na
Facebooku, żeby dołożyć „Wyślij w Messengerze" także na komputerze.
Koszt: konto dewelopera Meta, weryfikacja domeny i utrzymanie
`app_id` w konfiguracji. Zysk: jedna droga więcej dla osób, które
Messengera używają na laptopie.

### Przy jakiej treści przycisk się pokazuje

Wyłącznie przy treści, którą zobaczy **ktoś bez konta** — pyta o to
`Gate::forUser(null)->allows('view', …)`, czyli te same `PostPolicy`
i `RecipePolicy`, co całe wejście na stronę. Wpis „tylko dla obserwujących"
i „tylko dla mnie" przycisku nie dostaje **nawet u własnego autora**:
wysłany adres pokazałby odbiorcy 403, a autor byłby przekonany, że coś
wysłał. Autor widzi w tym miejscu jedno zdanie mówiące, co zrobić.

Blokada między dwiema osobami **nie** zmienia tego, co wolno wysłać —
przepis dalej jest publiczny dla całej reszty świata, a zablokowany i tak
nie zobaczy strony, więc do przycisku nie dojdzie.

### Nazwa przycisku

`BRAND_EXTENDED.md` §1.2 zakazuje „Podziel się" jako etykiety **publikacji
dania** (tam jest „Opublikuj"). To jest inna czynność — wysłanie linku poza
serwis — i właściciel wybrał dla niej właśnie „Podziel się", bo tak nazywa
się ta rzecz w Facebooku, czyli tam, gdzie nasza grupa nauczyła się jej
używać. Zakaz z tabeli zostaje w mocy dla publikacji.

**Zmiana wymaga:** wyniku testów z osobami 50+ (#15) mówiącego, że „Podziel
się" przy cudzym przepisie jest mylone z publikowaniem u siebie — albo
decyzji właściciela o założeniu aplikacji na Facebooku (wtedy dochodzi
Messenger).

📄 `app/Domain/Sharing/Udostepnianie.php` ·
`resources/views/components/podziel-sie.blade.php` ·
`resources/js/app.js` · `tests/Feature/PodzielSieTest.php` ·
`docs/FEATURES.md`

---

## D-045 · „Napisz do nas" to strona pod własnym adresem, nie dymek w rogu

**Data:** 9 września 2026 · Status: **obowiązuje**

Kontakt z operatorem serwisu ma jedną drogę podstawową: **zwykłą stronę
`/napisz-do-nas`**, renderowaną serwerowo, wysyłaną POST-em, z odnośnikiem
w stopce każdej strony i w nawigacji bocznej zalogowanego. Wiadomość zapisuje
się w tabeli `contact_messages`, a operator obsługuje ją na osobnym ekranie
`/admin/wiadomosci`.

**DLACZEGO NIE DYMEK PRZYKLEJONY DO ROGU EKRANU.** Dymek jest łatwiejszy do
znalezienia dokładnie o tyle, o ile zasłania treść. Dwa powody, oba
zmierzone gdzie indziej w tym repozytorium:

1. **Bez skryptu się nie otwiera.** AGENTS.md §5: ważne funkcje działają bez
   JavaScriptu. Człowiek, który pisze „coś nie działa", jest bardzo często
   tym samym człowiekiem, do którego nie dociągnął się skrypt — dymek byłby
   wtedy przyciskiem, który nic nie robi po kliknięciu. To ta sama decyzja,
   co przy menu pod awatarem w `layout.blade.php`.
2. **Element o stałej pozycji zasłania i rozpycha.** WCAG 1.4.10 (Reflow)
   i 2.4.11 (Focus Not Obscured) — przy 320 px i przy czcionce przeglądarki
   podkręconej do 200% element w rogu zabiera największą część ekranu
   i potrafi zakryć właśnie sfokusowany przycisk. Issues #80 i #162 w tym
   repozytorium dotyczyły dokładnie tej klasy usterki i oba zaczęły się od
   elementu, który „tylko trochę" wystawał poza ekran.

Dymek albo panel wolno kiedyś dołożyć, ale **wyłącznie jako skrót do tego
adresu**, nigdy zamiast niego — i dopiero po przebiegu
`scripts/dostepnosc.mjs`, który mierzy `/napisz-do-nas` przy 320 px i przy
czcionce 200%.

**PISAĆ MOŻE KAŻDY, TAKŻE BEZ KONTA.** Najczęstsze zdanie, jakie ludzie mają
nam do powiedzenia na starcie, brzmi „nie mogę się zalogować" albo „nie udało
mi się założyć konta". Formularz za logowaniem wykluczałby dokładnie te
osoby, dla których w pierwszej kolejności istnieje. Ochroną jest limit
zapytań (`kuking.limits.kontakt`, pięć na godzinę), nie konto — ta sama
konstrukcja, co przy publicznej drodze z DSA art. 16, tylko z luźniejszym
progiem, bo tu nadużycie kosztuje wiersz w tabeli, a nie sprawę z terminem
odpowiedzi.

**TO NIE JEST ZGŁASZANIE TREŚCI I NIE WOLNO TEGO ZLEWAĆ.** Trzy drogi, trzy
kolejki, trzy różne obowiązki:

| Droga | Czego dotyczy | Czym się kończy |
|---|---|---|
| „Zgłoś" pod treścią (`reports`, `community`) | cudzy wpis łamiący nasze zasady | decyzja moderatora, prawo do odwołania |
| `/zglos-nielegalna-tresc` (`reports`, `legal_notice`) | treść niezgodna z prawem (DSA art. 16) | decyzja z pouczeniem o środkach odwoławczych |
| `/napisz-do-nas` (`contact_messages`) | działanie serwisu | odpowiedź człowieka albo poprawka w kodzie |

Rozdział jest zrobiony w schemacie (osobna tabela), w panelu (osobny ekran)
i na obu formularzach (blok „Chodzi o czyjś wpis?" z linkami w obie strony).
Rodzaje wiadomości (`blad`, `pomysl`, `inne`) są świadomie rozłączne
z `Report::REASONS` — gdyby na formularzu technicznym stało „Mowa
nienawiści", ludzie zgłaszaliby tędy sąsiada.

**RETENCJA: 12 MIESIĘCY OD ZAŁATWIENIA**
(`kuking.kontakt.retention_months`), nie od napisania, i **nigdy** dla
wiadomości jeszcze niezałatwionej. Krócej niż 36 miesięcy spraw
moderacyjnych, bo tamten okres broni się tym, że sprawa może wrócić jako
spór prawny — tutaj nie ma decyzji, od której da się odwołać. Dłużej niż
3 miesiące powiadomień, bo pomysł zgłoszony w marcu bywa wdrażany jesienią
i trzeba wtedy wiedzieć, komu odpisać.

**WEBHOOK OPERATORA NIESIE DZWONEK, NIE TREŚĆ.** Po zapisie idzie na kanał
`blad_webhook` (D-041) jedno zdanie: rodzaj z zamkniętej listy,
identyfikator wiersza i adres ekranu w panelu. Treść wiadomości, adres
e-mail i adres strony **nie wychodzą stąd nigdy** — to jest ta sama lista
dozwolonych pól, którą wprowadził audyt A6-01, i pilnuje jej
`tests/Feature/WiadomoscNaWebhookuBezDanychOsobowychTest.php`.

**CO ZOSTAJE DO ROZSTRZYGNIĘCIA WŁAŚCICIELOWI.** Czy na potwierdzenie
odbioru ma iść e-mail (dziś jest wyłącznie potwierdzenie NA EKRANIE, bo
serwis nie ma jeszcze dostawcy poczty — D-040) i czy 12 miesięcy retencji to
właściwa liczba.

**Zmiana wymaga:** decyzji właściciela — dymek/panel wolno dołożyć tylko jako
skrót do tego adresu i tylko z pomiarem dostępności w ręku.

📄 `routes/web.php` · `app/Http/Controllers/NapiszDoNasController.php` ·
`app/Domain/Contact/` · `app/Models/ContactMessage.php` ·
`app/Policies/ContactMessagePolicy.php` ·
`database/migrations/2026_09_09_100000_create_contact_messages_table.php` ·
`config/kuking.php` (`limits.kontakt`, `kontakt.retention_months`) ·
`docs/DATABASE.md` (`contact_messages`) ·
`resources/legal/polityka-prywatnosci.md` §2 · `scripts/dostepnosc.mjs`

---

## D-046 · Wyszukiwarka pyta operatorem `<%` (`word_similarity`) z progiem 0,5, nie `%` z 0,12

**Data:** 9 września 2026 · **Decyzja właściciela** (issue #187) · Status: **obowiązuje**

Operator `%` z pg_trgm mierzy podobieństwo frazy do **całego** tytułu, więc
żeby literówka w długim tytule w ogóle trafiała („sernk" wobec „sernik babci
haliny" to 0,18), próg musiał zjechać do 0,12. Przy takim progu długa fraza
jest podobna do prawie wszystkiego. Zmierzone na bazie 40 000 przepisów,
w której nie ma ani jednej sajgonki: fraza „sajgonki z krewetkami" zwracała
**1 526 wyników**, „rosół" znajdował „Rogaliki", „barszcz" — „Bogracz",
„pierogi" — „Piernik". To nie wygląda na wyszukiwarkę, która czegoś nie ma;
wygląda na zepsutą.

**Co wybrano.** Operator `<%` — „czy fraza jest podobna do najlepiej
pasującego FRAGMENTU tekstu". Długość tytułu przestaje karać trafienie
(„sernk" wobec „sernik babci haliny" to już 0,67), więc próg może być wysoki,
a wysoki próg wycina śmieci. Ten sam indeks GIN, **zero migracji**.

**Dlaczego próg 0,5, a nie domyślne 0,6 z issue.** Bo 0,6 gubi rzeczy, po
które ludzie przychodzą: „rosul" (tak wygląda „rosuł" bez ogonków) przestaje
znajdować rosół, „piergi" przestaje znajdować pierogi, a „kotlet schabowy
z ziemniakami" znajduje 45 przepisów zamiast 232. Przy 0,5 wszystkie trzy
wracają, a kanarki („sajgonki z krewetkami", „kartacze", „tortilla
z kurczakiem") dalej zwracają zero.

**Co ta decyzja KOSZTUJE — zmierzone, nie oszacowane.** Ciężka literówka
fonetyczna przestaje działać: „gołombki" nie znajduje już „Gołąbków" (0,42
przy progu 0,5) ani w wyszukiwarce, ani w podpowiedziach tagów. Długa fraza
opisowa przestaje zaciągać dania pokrewne po jednym słowie: „pierogi ruskie
babci haliny" nie pokazuje już „Pierogów z mięsem". Pełna lista zgubionych
trafień, z nazwami, jest w `docs/research/WYDAJNOSC.md` §3.4b — właściciel
podejmował tę decyzję, widząc cenę.

**Zakres.** Zmiana objęła OBIE ścieżki podobieństwa: `SearchQuery::recipes()`
i czwartą gałąź `TagSuggester`. Dwie ścieżki z dwoma różnymi progami
znaczyłyby, że słowo „podobne" ma w jednym produkcie dwa znaczenia zależnie
od pola, w które człowiek pisze. `SearchQuery::people()` nie używa operatora
podobieństwa (dopasowuje `LIKE`) i została bez zmian.

**Kolejność wyników** poszła za operatorem: `word_similarity` DESC, potem
`similarity` DESC. Rozstrzygnięte pomiarem, nie teorią — przy samym
`similarity` 722 przepisy „Pierogi …" stały za pierwszym „Piernikiem".

**Zmiana wymaga:** powtórzenia pomiaru z §3.4b. Próg to jedna stała
(`App\Support\ProgPodobienstwa::PROG`) i jedno miejsce — jeśli ktoś uzna, że
„gołombki" są ważniejsze niż czystość wyników przy „pierogach", zejście do
0,4 jest zmianą jednej liczby. Ale to jest decyzja produktowa, nie techniczna.

📄 `app/Support/ProgPodobienstwa.php` · `app/Domain/Search/SearchQuery.php` ·
`app/Domain/Tags/TagSuggester.php` · `tests/Feature/TrafnoscWyszukiwarkiTest.php` ·
`docs/research/WYDAJNOSC.md` §3.4b · `docs/DATABASE.md`

---

## D-047 · Pocztę wysyłamy przez API HTTPS EmailLabs, własnym transportem Symfony

**Data:** 9 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

`MAIL_MAILER=emaillabs`. Wysyłka idzie zwykłym `POST`-em HTTPS na
`https://api.emaillabs.io/v2.1/email`, przez transport napisany w tym
repozytorium (`App\Poczta\TransportEmailLabs`), zarejestrowany jako sterownik
Laravela przez `Mail::extend()` w `App\Providers\PocztaServiceProvider`.
**Żadnej nowej paczki Composera.**

### DLACZEGO NIE SMTP — to nie jest kwestia gustu, tylko planu hostingu

Dokumentacja Railwaya mówi wprost: *„SMTP is only available on the Pro plan
and above. Free, Trial, and Hobby plans must use transactional email services
with HTTPS APIs. SMTP is disabled on these plans to prevent spam and abuse."*
([docs.railway.com/networking/outbound-networking#email-delivery](https://docs.railway.com/networking/outbound-networking#email-delivery),
sprawdzone 9 września 2026.) Właściciel jest na planie Free i przechodzi na
Hobby — **obie blokady obowiązują**. Ta sama strona dodaje, że usługi po HTTPS
są rekomendowane **na wszystkich planach**, także tam, gdzie SMTP działa.

Objaw zmierzony na produkcji tego samego dnia jest gorszy niż zwykły błąd:
pakiety idą w próżnię, więc połączenie nie tyle pada, co **wisi**. Zadanie
`App\Notifications\UstawienieNowegoHasla` wchodziło w `RUNNING`
i **nigdy się nie kończyło** — ani `DONE`, ani `FAIL`. W panelu Railwaya
wyglądało to jak zawieszony worker, nie jak awaria poczty, więc nic tego nie
nazwało po imieniu.

To była **trzecia warstwa cichej awarii poczty tego samego dnia**, po
`MAIL_MAILER=log` (przyjmuje list i zgłasza sukces) i `MAIL_SCHEME=tls`
(schemat, którego Symfony nie zna). Stąd nacisk na to, żeby nowa droga
wywracała się głośno.

### DLACZEGO NADAL EMAILLABS, SKORO TRZEBA PISAĆ WŁASNY TRANSPORT

Powód jest prawny i produktowy, nie techniczny. **EmailLabs to Vercom S.A.
z Poznania, serwery w EOG** — dzięki temu w polityce prywatności zostaje
zdanie „Twój adres e-mail przetwarzamy w Polsce", a umowa powierzenia jest po
polsku, na polskim prawie.

Każdy dostawca z **gotowym** sterownikiem Laravela (Mailgun, SES, Postmark,
Resend) to spółka amerykańska: CLOUD Act, nowe DPA, ocena transferu (TIA)
i dodatkowy akapit o wywozie danych poza EOG w polityce prywatności. Przy
serwisie dla grupy 50+, gdzie zaufanie jest walutą, pół dnia pracy nad
transportem jest tańsze niż ten akapit. Pełna analiza sześciu dostawców:
[`docs/decyzje/POCZTA.md`](decyzje/POCZTA.md) §2.

Rozważona i odrzucona alternatywa: **przejście na plan Railway Pro tylko po
to, żeby odblokować SMTP.** To jest stały koszt miesięczny za możliwość
używania protokołu, który i tak jest wolniejszy i gorzej diagnozowalny niż
HTTPS — a transport po API jest jednorazowy i działa na każdym planie.

### CO Z TEGO WYNIKA DLA KODU

- **Reszta serwisu nie wie o zmianie.** `Mail::`, wszystkie `Notification`,
  kolejka i `kuking:sprawdz-poczte` chodzą przez `MailManager`.
- **Klucze są sekretami i nie wychodzą nigdzie.** Komunikat odmowy budujemy
  z listy dozwolonych pól odpowiedzi (kod błędu, tytuł z wyciętym adresem,
  nazwa parametru, `uniqId`) — nigdy z `errors[].message` ani
  `errors[].meta.value`, bo dokumentacja mówi wprost, że to drugie jest
  „the value of this parameter passed", czyli przy błędnym adresie odbiorcy
  byłby to jego adres e-mail. To jest ta sama lekcja, co audyt A6-01
  w `App\Logging\WebhookBleduHandler`.
- **Cisza jest zakazana.** Sukcesem jest wyłącznie HTTP 2xx *i* zero błędów
  *i* co najmniej jedna przyjęta wiadomość. HTTP 207 („część adresatów
  przyjęta") jest tu porażką, bo nasze listy mają po jednym adresacie.
- **`App\Support\Poczta::dziala()` sprawdza teraz dwie rzeczy**: czy sterownik
  dostarcza ORAZ czy Laravel potrafi zbudować dla niego transport. Sama nazwa
  sterownika okazała się za słabym pomiarem dwa razy tego samego dnia.
  Skutek uboczny, świadomy: `postmark` i `resend` przestały uchodzić za
  działające, bo ich paczek nie ma w `composer.json` i pierwszy list padłby na
  „Class not found".
- **Śledzenie odnośników domyślnie wyłączone** (`X-TRACKING-OFF`). Włączone
  podmienia link do zmiany hasła na adres przekierowujący dostawcy, a link
  prowadzący pod obcą domenę to dla osoby 60+ kształt phishingu, przed którym
  ostrzegają banki.

### CO ZOSTAJE NIETKNIĘTE

Konfiguracja SMTP w `config/mail.php`, w `.railway/railway.ts` i w
`.env.example` **zostaje, uśpiona**: `MAIL_MAILER` jej nie wybiera, ale
wszystkie zmienne są na miejscu. Powód: po przejściu na plan Pro Railway
odblokowuje SMTP i wtedy jest to gotowa droga powrotna oraz gotowe drugie
ramię `failover` u innego dostawcy. Razem z nią zostaje
`SchematPocztyJestObslugiwanyTest` — bo dopóki `MAIL_SCHEME` jest w pliku,
dopóty ktoś może wpisać tam z powrotem `tls`.

**Zmiana wymaga:** przejścia na plan Railway Pro (wtedy SMTP staje się
możliwy, ale nadal nie obowiązkowy) — albo decyzji właściciela o zmianie
dostawcy, co jest decyzją prawną, nie techniczną, i wymaga ponownego
przeczytania `docs/decyzje/POCZTA.md` §2.

📄 `app/Poczta/TransportEmailLabs.php` · `app/Poczta/OdmowaEmailLabs.php` ·
`app/Poczta/BrakKonfiguracjiEmailLabs.php` ·
`app/Providers/PocztaServiceProvider.php` · `app/Support/Poczta.php` ·
`config/mail.php` · `config/services.php` · `.railway/railway.ts` ·
`tests/Feature/PocztaPrzezApiEmailLabsTest.php` ·
`docs/infra/POCZTA_URUCHOMIENIE.md` §2A

---

## D-048 · Nowy adres e-mail obowiązuje po kliknięciu w link, a zajętość adresu rozstrzyga się dopiero tam

**Data:** 9 września 2026 · Issue #195 · Status: **obowiązuje**

Zmiana adresu e-mail w Kuking jest **zmianą stanu konta**, nie edycją profilu.
Idzie osobnym ekranem (`/ustawienia/e-mail`) i pełną drogą: obecne hasło →
list z podpisanym odnośnikiem na NOWY adres → kliknięcie → zmiana, plus
natychmiastowe ostrzeżenie na STARY adres. Do kliknięcia obowiązuje adres
dotychczasowy: logowanie i „nie pamiętam hasła" działają tak jak wczoraj.

**DLACZEGO NIE POLE W `/ustawienia/profil`.** Bo adres e-mail jest jedyną
drogą odzyskania konta — kto go przestawi, przejmuje konto resetem hasła.
Pole obok „bio", zapisywane jednym `PUT`, byłoby przejęciem konta na jedno
kliknięcie u każdego, kto usiadł przy niezablokowanej przeglądarce. Z tego
samego powodu `email` i `email_verified_at` wypadły z `User::$fillable` —
ta sama reguła co przy `status` i `role` (AGENTS.md §7).

**OCZEKUJĄCA ZMIANA MIESZKA W OSOBNEJ TABELI** (`pending_email_changes`),
nie w kolumnach na `users`. To nie jest cecha konta, tylko żądanie z własnym
życiorysem: powstaje, wygasa, zostaje skasowane albo skonsumowane. Wiersz
znikający w całości nie wymaga CHECK-a wiążącego nullowość dwóch kolumn,
nie obciąża najczęściej czytanej tabeli w bazie wartościami, które w 99,9%
wierszy są NULL-em, i znika jednym `DELETE`, a nie `UPDATE`-em na `users`.
Pełny wywód: migracja i `docs/DATABASE.md`.

**ADRES ZAJĘTY PRZEZ INNE KONTO NIE ODBIJA SIĘ W FORMULARZU** — i to jest
druga połowa tej decyzji. `Rule::unique('users','email')` w walidacji byłby
wyciekiem: zalogowany wpisuje dowolny adres i po odpowiedzi wie, czy ta osoba
ma konto w Kuking. Serwis, w którym da się sprawdzić, czy sąsiadka albo była
żona tu gotuje, nie jest bezpieczną izbą (`docs/product/SOUL.md`, filar
czwarty). Dlatego odpowiedź formularza jest identyczna dla adresu wolnego
i zajętego, żądanie powstaje w obu przypadkach, a o kolizji dowiaduje się
dopiero ten, kto **kliknie odnośnik** — czyli osoba czytająca pocztę pod tym
adresem, której i tak wolno wiedzieć, że ma u nas konto. Kosztem jest jeden
list wysłany „w próżnię"; zyskiem — brak wyroczni obecności konta.

**REJESTRACJA ZOSTAJE JAK BYŁA** i to nie jest niekonsekwencja do
posprzątania. `RegisterController` mówi wprost „na ten adres jest już
założone konto", bo tam ta odpowiedź jest jedyną drogą, żeby powiedzieć
człowiekowi „masz już konto, zaloguj się". Tam nie mamy wyboru, tutaj mamy
i wybieramy nieprzeciekającą stronę. Zmiana rejestracji to osobna decyzja
o osobnym ekranie.

**ZMIANA I RESET HASŁA UNIEWAŻNIAJĄ OCZEKUJĄCE ŻĄDANIE.** List ostrzegawczy
do starego adresu radzi „jeśli to nie Ty — zmień hasło", więc ta rada musi
być prawdziwa: bez tego napastnik dokończyłby przejęcie konta swoim
odnośnikiem właśnie wtedy, gdy właściciel zrobił dokładnie to, o co go
poprosiliśmy.

**Zmiana wymaga:** przemyślenia obu połówek naraz. Dopisanie `Rule::unique`
do formularza „dla wygody" przywraca wyciek; przeniesienie adresu na `users`
w chwili wysłania listu przywraca przejęcie konta na jedno kliknięcie.
Pilnują tego `ZmianaAdresuEmailTest` i `AdresEmailPozaMasowymPrzypisaniemTest`.

📄 `app/Domain/Users/Actions/RequestEmailChange.php` ·
`app/Domain/Users/Actions/ConfirmEmailChange.php` ·
`app/Domain/Users/Actions/CancelEmailChange.php` ·
`app/Models/PendingEmailChange.php` ·
`app/Http/Controllers/Settings/EmailSettingsController.php` ·
`docs/DATABASE.md` (`pending_email_changes`) ·
`docs/SECURITY_PRIVACY_LEGAL.md` (RODO art. 16)

---

## D-049 · Zrzut szyfrujemy KLUCZEM PUBLICZNYM, a podpis do R2 liczymy sami w powłoce

**Data:** 9 września 2026 · Status: **obowiązuje** · Wykonanie: issue #193

> **To nie jest nowa decyzja o kierunku — kierunek rozstrzyga D-043.**
> To są cztery rozstrzygnięcia W ŚRODKU tamtej decyzji, podjęte przy pisaniu
> `docker/kopia/`. Trafiają do dziennika, bo każde z nich będzie kiedyś
> wyglądało na dziwne i zaproszy do „uproszczenia", które cofnie własność,
> o którą chodziło.

**1. SZYFROWANIE KLUCZEM PUBLICZNYM (CMS/PKCS#7), NIE HASŁEM.**
Zrzut to komplet danych osobowych wszystkich kont w jednym pliku. Gdyby
szyfrował go `openssl enc` z hasłem, to hasło musiałoby leżeć w Railwayu —
czyli w tym samym miejscu, co baza i co bucket. Przejęcie konta Railway
dawałoby wtedy jednocześnie bazę, kopie i klucz do kopii. Klucz publiczny
odwraca to: w Railwayu leży certyfikat, którym **da się tylko zaszyfrować**.
Klucz prywatny nie istnieje w żadnym środowisku uruchomieniowym.

Cena jest symetryczna i trzeba ją znać: **utrata klucza prywatnego czyni
wszystkie kopie nieczytelnymi na zawsze.** Dlatego dwie kopie klucza,
w dwóch różnych miejscach — dokładnie ta sama zasada, co przy `APP_KEY`
(`KOPIE_I_ODTWORZENIE.md` §1.1 i §7.1).

**Skrypt odmawia pracy, gdy w tej zmiennej znajdzie klucz prywatny** (kod
wyjścia 64). Dopisane przy przeglądzie tego PR-a, bo sama deklaracja „w
Railwayu leży tylko część publiczna" nie miała w kodzie żadnego oparcia:
`openssl x509` przechodzi również na wartości będącej wynikiem
`cat kuking-kopie-publiczny.pem kuking-kopie-PRYWATNY.pem`, a to jest jedna
z dwóch najprawdopodobniejszych pomyłek przy wklejaniu do panelu (drugą,
pomylenie plików o jedną literę w nazwie, §7.1 wymienia wprost). Kopie
powstawałyby dalej — tylko klucz do ich odczytu leżałby od tego momentu w tym
samym Railwayu, co baza i co bucket, czyli cała własność z tego punktu byłaby
cofnięta i **nic by o tym nie powiedziało**. Cisza jest tu droższa niż brak
kopii, bo brak kopii widać w panelu.

**DLACZEGO CMS, A NIE `age` ANI `gpg`.** Bo `openssl` jest wszędzie.
Odtworzenie kopii ma się udać w dniu, w którym wszystko inne się wali,
z dowolnego komputera, bez instalowania czegokolwiek — jedną komendą
(`KOPIE_I_ODTWORZENIE.md` §7.4). `age` to binarka do pobrania, `gpg` to
keyring i cała ceremonia. Obie dokładają krok „najpierw zdobądź narzędzie"
do procedury awaryjnej, a to jest najgorsze możliwe miejsce na taki krok.

**2. PODPIS AWS SIGV4 LICZYMY SAMI, NAD `openssl` i `curl` — bez `aws` CLI
i bez `rclone`.** Ten kontener trzyma w rękach zrzut całej bazy; każda
dołożona paczka to kod z prawem przeczytania tego pliku i prawem gadania po
sieci. `aws` CLI to Python i botocore z własnym łańcuchem zależności,
`rclone` to binarka pobierana z internetu przy budowie obrazu. SigV4 to sto
linii nad narzędziami, które w tym obrazie i tak muszą być (openssl szyfruje,
curl dzwoni na webhook). Ta sama zasada, z której powstał własny transport
poczty (D-047): nie dokładamy paczki, gdy da się bez niej.

**Warunek, na którym to stoi:** algorytm jest sprawdzany **urzędowymi
wektorami AWS** (klucz podpisu z dokumentacji Signature Version 4 i sygnatura
`get-vanilla` z `aws-sig-v4-test-suite`) w `tests/skrypty/kopia-bazy.sh`,
a nie porównaniem z drugą własną implementacją. Dwie implementacje jednej
osoby potwierdzają wspólne nieporozumienie równie chętnie, jak poprawność.
**Gdyby te wektory kiedykolwiek zniknęły z testów, ta decyzja przestaje
obowiązywać** — wtedy lepsza jest paczka od nieweryfikowanego podpisu.

**3. RAILWAY CRON, MIMO ŻE `railway.ts` ODRZUCA GO PRZY SCHEDULERZE.**
Nie jest to niespójność. Przy schedulerze Laravela problemem była
GRANULACJA: `everyMinute()` wymaga odpytywania co minutę, a Railway Cron ma
minimum 5 minut. Tutaj granulacja nie ma żadnego znaczenia — kopia raz na
dobę może wystartować pięć minut później. Cron jest za to jedyną formą, w
której kontener wstaje, robi swoje i **umiera**, nie płacąc za czas
pomiędzy. `restartPolicyType: "NEVER"`, bo nieudany zrzut ma zostać nieudany
i zaalarmować, a nie wstawać w pętli i zrzucać całą bazę co kilkadziesiąt
sekund.

**4. CZUJKA W APLIKACJI JEST OSOBNYM ZABEZPIECZENIEM, NIE DUBLOWANIEM.**
Serwis kopii alarmuje, gdy jego przebieg się nie udał. Nie zaalarmuje, gdy
przebiegu NIE BYŁO: serwis skasowany, harmonogram wyłączony, limit konta
wyczerpany, token wygasł. **Kod, który wtedy nie chodzi, nie może o sobie
donieść** — a #193 nazywa ten stan najgorszym z możliwych, bo „myślisz, że
masz kopię". Dlatego `kuking:sprawdz-kopie` patrzy z drugiej strony: raz na
dobę listuje bucket i dzwoni, gdy najnowsza kopia jest za stara.

Aplikacja dostaje do tego bucketu token **TYLKO DO CZYTANIA**, osobny od
tokenu serwisu kopii. Gdyby miała prawo zapisu, udany atak na nią mógłby
**skasować kopie** — czyli dokładnie to, przed czym ta warstwa ma chronić.
Kopia, którą da się zniszczyć z zaatakowanego serwisu, nie jest kopią
offsite.

**PIĄTA RZECZ, KTÓREJ NIE MA I TRZEBA O NIEJ WIEDZIEĆ.** Nie ma trzeciego,
niezależnego świadka. Jeśli padnie i serwis kopii, i aplikacja, milczenie
będzie zupełne — do cotygodniowego przeglądu z `KOPIE_I_ODTWORZENIE.md` §6.
Zewnętrzny „dead man's switch" (usługa, która dzwoni, gdy PRZESTANIE
dostawać sygnał) byłby na to właściwą odpowiedzią i jest świadomie odłożony:
to szósta usługa w projekcie obsługiwanym przez jedną osobę, a przegląd raz
na tydzień zamyka lukę do tygodnia, nie do nieskończoności.

**Zmiana wymaga:** przeczytania D-043 najpierw. Punkty 1 i 4 są granicami
bezpieczeństwa, nie preferencjami — hasło zamiast klucza publicznego albo
token z prawem zapisu w aplikacji cofają całą własność, o którą chodziło.

📄 `docker/kopia/Dockerfile` · `docker/kopia/kopia-bazy.sh` ·
`docker/kopia/s3.sh` · `.railway/railway.ts` (serwis `kopia-bazy`) ·
`app/Domain/Kopie/StanKopiiBazy.php` · `app/Domain/Kopie/AlarmKopii.php` ·
`app/Console/Commands/SprawdzKopieBazy.php` ·
`tests/skrypty/kopia-bazy.sh` ·
`docs/infra/KOPIE_I_ODTWORZENIE.md` §7 · D-043 · D-041 · D-047 · #193 · #120

---

## D-050 · Cloudflare Turnstile na sześciu formularzach publicznych — warunek wysłania, nie filtr. Brak tokenu odrzuca

**Data:** 9 września 2026 · Issue #217 · **Decyzja właściciela** · Status: **obowiązuje**
· **Zaostrzone tego samego dnia, po wdrożeniu PR #218 — patrz sekcja o braku tokenu**

Turnstile w trybie **Managed** stoi na **sześciu** formularzach publicznych —
wszędzie tam, gdzie do serwisu wchodzi ktoś niezalogowany: `/register`,
`/login`, `/nie-pamietam-hasla`, `/cofnij-usuniecie-konta`, `/napisz-do-nas`
i `/zglos-nielegalna-tresc`. Weryfikacja tokenu idzie po stronie serwera,
na `https://challenges.cloudflare.com/turnstile/v0/siteverify`, **własnym
cienkim klientem** na `Illuminate\Support\Facades\Http` — żadnej nowej paczki
Composera, tak samo jak transport poczty w D-047.

### CO TA DECYZJA ODWRACA

`docs/INSPIRATION_DECISIONS.md` poz. **1.11** brzmiała: „Captcha przy
rejestracji — **REJECT**: bariera wejścia dla osób 50+ jest większa niż zysk;
zamiast tego sygnały pasywne (poz. 3.6)". Ta pozycja jest od dziś **ADAPT**
i wskazuje na ten wpis.

Odwraca ją **właściciel**, słowami: *„captcha trzeba normalnie zrobić, ten od
cloudflare jest nieinwazyjny"*. I ma rację co do faktu: rozstrzygnięcie z 1.11
dotyczyło captchy, jaką się wtedy znało — obrazków z przejściami dla pieszych,
na których osoba 65-letnia utyka i rezygnuje. **Turnstile w trybie Managed
w przeważającej większości przypadków nie prosi o nic**: sprawdza sygnały
przeglądarki i przepuszcza w tle. Bariera, o której mówiła poz. 1.11, po
prostu nie ma tu miejsca.

Drugi powód jest niezależny od Turnstile: dwa nasze dokumenty mówiły w tej
sprawie co innego (`SECURITY_BASELINE.md` §4 przewidywał captchę przy
logowaniu), a rozjazd między dokumentami jest gorszy niż brak dokumentów.

Zostaje jednak istota tamtego sprzeciwu i to ona kształtuje całą resztę tej
decyzji: **nie wolno postawić przed człowiekiem 50+ bramki, przez którą może
nie przejść** — a jeśli już się ją stawia, to razem z drogą obok niej.

### BRAK TOKENU ODRZUCA WYSŁANIE — I PIERWOTNIE BYŁO ODWROTNIE

Stan faktyczny:

```text
brak tokenu          → ODRZUCAMY     (osobny komunikat mówiący, co zrobić,
                                      + wpis w dzienniku)
token nieprawdziwy   → ODRZUCAMY     (inny komunikat: sprawdzenie wygasło)
Cloudflare nie odpowiada → PRZEPUSZCZAMY + ostrzeżenie w dzienniku
zły sekret po naszej stronie → PRZEPUSZCZAMY + `Log::error`
brak kluczy w konfiguracji → PRZEPUSZCZAMY, nikogo nie pytamy
```

**Pierwsza wersja tej decyzji (PR #218, ten sam dzień) mówiła co innego: brak
tokenu PRZEPUSZCZAŁ.** Wynikało to wprost z zasady „ważne funkcje działają bez
JavaScriptu" (`AGENTS.md` §5): Turnstile jest widgetem JS, wersji bez JS nie
ma, więc jedynym sposobem pogodzenia obu rzeczy było przepuszczanie pustego
pola. Reguła nazywała się wtedy `TurnstileNieJestPodrobiony`, a testy
`test_*_bez_tokenu_*` pilnowały, żeby nikt tego „nie dokręcił".

**Właściciel zmienił tę zasadę dla tych sześciu miejsc**, dosłownie: *„w tych
newralgicznych miejscach niech JS będzie obowiązkowo jak ta rejestracja itp,
tam gdzie można się obejść to spoko, ale lepiej żeby był z wygody"*.
Uzasadnienie jest faktyczne, nie ideologiczne: nasi ludzie wchodzą
z nowoczesnych telefonów albo z komputera i JavaScript mają — przeglądarka
z wyłączonym skryptem to dziś przypadek pojedynczy, a captcha przepuszczająca
puste pole nie chroni przed niczym, bo skrypt masowo zakładający konta po
prostu tego pola nie wysyła. Filtr, który każdy automat obchodzi jedną
pominiętą wartością, jest ozdobą. Zmianę w samym `AGENTS.md` §5 wprowadza
właściciel.

Turnstile jest więc **warunkiem wysłania tych sześciu formularzy**, a nie
filtrem taniego ruchu. Reguła nazywa się `App\Rules\TurnstileJestPotwierdzony`
i jest **implicit** (`public bool $implicit = true`) — bez tego Laravel nie
wołałby jej dla pola pustego albo nieobecnego, czyli dokładnie dla przypadku,
o który tu chodzi, i zaciśnięcie byłoby pozorne. `required` w sześciu
kontrolerach dałoby ten sam skutek, ale z laravelowym komunikatem o „polu
cf-turnstile-response", którego nikt na ekranie nie zrozumie.

### CO MUSI IŚĆ RAZEM Z ZACIŚNIĘCIEM — TO JEST WAŻNIEJSZE NIŻ SAMO ZACIŚNIĘCIE

Samo odrzucanie to jedna linijka. Wartość tej zmiany leży w tym, żeby **nikt
nie został przed martwym przyciskiem**. Bez poniższych czterech rzeczy
zaciśnięcie zamienia rzadką awarię w cichą utratę użytkownika — człowiek
klika „Załóż konto", dostaje komunikat o czymś, czego nie widzi na ekranie,
i odchodzi.

1. **`<noscript>` przy każdym z sześciu formularzy**, w miejscu, gdzie
   normalnie stoi widget (`resources/views/components/turnstile.blade.php`).
   Zdanie jest **osobne dla każdego formularza**, bo człowiek ma się
   dowiedzieć nie tego, jakiej technologii wymagamy, tylko czego konkretnie
   nie da się teraz zrobić: „Do założenia konta potrzebny jest włączony
   JavaScript…", „Do wysłania linku do nowego hasła…", „Do wysłania
   zgłoszenia…". „Wymagany JavaScript" nad formularzem odzyskiwania hasła nie
   mówi nikomu, że właśnie nie odzyska hasła.
2. **Osobny komunikat na wypadek, gdy JavaScript JEST włączony, a token i tak
   nie przyszedł** — bo skrypt widgetu się nie dociągnął (słabe łącze,
   blokada reklam, Cloudflare nieosiągalny z tej sieci). To NIE jest ten sam
   przypadek co token podrobiony i nie wolno im dać wspólnego tekstu:
   przy podrobionym sprawdzenie było widoczne i wygasło („wyślij formularz
   jeszcze raz"), przy braku tokenu na ekranie nie ma NICZEGO, czego brakuje,
   więc trzeba powiedzieć wprost, że sprawdzenie się nie wczytało, i co z tym
   zrobić. Kolejność rad jest celowa: najpierw „wyślij jeszcze raz" (nieudana
   walidacja przerysowuje stronę z `old()`, więc przy okazji drugi raz próbuje
   pobrać skrypt i nie kosztuje ani jednego wpisanego znaku), dopiero potem
   JavaScript i blokada reklam, na końcu adres e-mail.
3. **Droga wyjścia dla człowieka, który utknął: adres e-mail, pod którym
   siedzi człowiek** (`kuking.community.contact_email`) — w `<noscript>` jako
   klikalny `mailto:` i w komunikacie odrzucenia jako tekst. Dotyczy to także
   rejestracji i logowania, i nie jest ozdobą: **nie wolno odesłać takiej
   osoby na `/napisz-do-nas`**, bo tamten formularz ma dokładnie to samo
   sprawdzenie i jest dla niej równie zamknięty. Adres jest jedyną drogą,
   która nie zależy od tego, co się właśnie zepsuło. Przy `/zglos-nielegalna-tresc`
   ma to dodatkowy ciężar: DSA art. 16 ust. 1 każe trzymać mechanizm „łatwo
   dostępny", a formularz, który potrafi odmówić, przestaje nim być bez
   drugiej drogi.
4. **Licznik, czyli ślad w dzienniku.** Każde odrzucenie z powodu braku tokenu
   zapisuje `Log::warning` z nazwą miejsca — **bez adresu IP i bez czegokolwiek,
   co człowiek wpisał w formularz** (`AGENTS.md` §7). Zaciśnięcie jest
   zakładem („nasi ludzie mają JavaScript"), a zakład bez licznika jest wiarą,
   nie decyzją: po tygodniu musi dać się odpowiedzieć na pytanie, ilu ludzi
   odbiło się od którego formularza. Świadomie **nie** idzie to do
   `product_signals` (`ZapiszSygnal`): `signal_name` jest tam zamknięty
   CHECK-iem w bazie, więc nowa nazwa zdarzenia znaczy migrację — a droga
   wycofania niżej obiecuje „bez migracji, bez danych do posprzątania" i ta
   obietnica jest tu więcej warta niż wygodniejszy wykres. Gdyby liczby
   okazały się niepokojące, przeniesienie tego do sygnałów jest osobną,
   świadomą pracą z migracją i wpisem w `docs/DATABASE.md`.

**Niedostępność cudzej usługi nadal nie zamyka rejestracji i to się NIE
zmieniło.** Timeout, HTTP 5xx, odpowiedź w nieznanym kształcie, literówka
w `TURNSTILE_SECRET_KEY` — w każdym z tych przypadków formularz przechodzi,
a ostrzeżenie idzie do dziennika. Odwrotna decyzja („nie wiem" = odrzucamy)
wyglądałaby na bezpieczniejszą i byłaby najgorszym możliwym błędem w tym
miejscu: awaria u Cloudflare albo jeden zły znak w panelu Railway zamykałby
naraz rejestrację, odzyskiwanie hasła i formularz z DSA art. 16 — a z zewnątrz
wyglądałoby to jak działający serwis. Zaciśnięcie dotyczyło człowieka, który
nie przysłał tokenu, a nie naszej ani cudzej awarii.

**Brak kluczy w konfiguracji też nie blokuje niczego** — patrz sekcja niżej.
Inaczej CI i praca lokalna (jedno i drugie bez kluczy) stanęłyby na sześciu
formularzach naraz, a `<noscript>` straszyłby brakiem JavaScriptu na
formularzu, który i tak przechodzi bez tokenu.

### LOGOWANIE I COFNIĘCIE USUNIĘCIA KONTA — TAK, ZAWSZE (DECYZJA WŁAŚCICIELA)

Pierwotny szkic #217 przewidywał na `/login` i `/cofnij-usuniecie-konta`
wariant „dopiero po nieudanych próbach", z obawy przed podatkiem od wieku:
codzienna droga naszych ludzi obłożona captchą za cudze skrypty.
**Właściciel tę obawę oddalił** — i argument jest rzeczowy, nie autorytatywny:
skoro widget zwykle nie wymaga żadnej interakcji, to nie ma bariery, przed
którą trzeba by bronić. Wariant „po nieudanych próbach" **nie powstał** i nie
jest już potrzebny; gdyby kiedyś miał powstać, musiałby czytać koszyki
z `login_limits` i jest osobną pracą.

**Trzy koszyki `login_limits` zostają bez zmian.** Turnstile ich nie zastępuje
i nie wolno go traktować jak ich zamiennika: limity widzą atak rozproszony po
adresach (W7-01), captcha widzi automat w przeglądarce. To dwie różne obrony
i chcemy obu naraz.

Pierwsza wersja tego wpisu miała tu jeszcze jedno zdanie: „bez JavaScriptu
logowanie działa dalej". **Już nie działa** — wypowiedź właściciela o JS
w newralgicznych miejscach objęła również logowanie, wprost („jak ta
rejestracja itp"). Bez tokenu logowanie jest odrzucane tak samo jak
rejestracja, z tym samym komunikatem i tą samą drogą wyjścia; pilnuje tego
`test_logowanie_bez_tokenu_jest_odrzucane_ze_zrozumialym_komunikatem`.

Cena jest realna i trzeba ją nazwać: człowiek, któremu widget się nie
dociągnie, nie wejdzie na własne konto. Dlatego przy logowaniu — tak samo jak
przy rejestracji — w komunikacie stoi adres e-mail, a nie odesłanie na
`/napisz-do-nas`, które byłoby dla niego ślepą uliczką.

`docs/legal/SECURITY_BASELINE.md` §4 mówi o tym teraz to samo, co kod.

### BRAK KLUCZY NIC NIE PSUJE — I WŁAŚNIE DLATEGO MUSI BYĆ WIDOCZNY

Bez `TURNSTILE_SITE_KEY` i `TURNSTILE_SECRET_KEY` widget się nie renderuje,
reguła nikogo nie odpytuje i nikogo nie zatrzymuje. To jest dobre zachowanie
domyślne (lokalnie, w CI, w testach i do czasu wgrania kluczy na produkcję nic
się nie psuje) — i jednocześnie **dokładnie ta klasa awarii, na którą ten
projekt nadział się już kilka razy: narzędzie melduje sukces, nie robiąc nic**
(`MAIL_MAILER=log`, martwy `kuking.media_disk`, limit `upload` niepodpięty do
żadnej trasy, job dostępności z #215).

Dlatego jest twardy sygnał, **spójny z tym, co już mamy, zamiast nowego
mechanizmu**: `/health` dostał czwarte sprawdzenie, `turnstile`. Gdy
`APP_ENV=production`, którekolwiek miejsce jest włączone, a kluczy nie ma —
odpowiedź niesie `status: degraded` i `checks.turnstile.error =
turnstile_bez_kluczy`, a `HealthController::check()` zapisuje `Log::error`,
czyli sygnał idzie też na webhook błędów i do Sentry.

Sprawdzenie jest **NIEKRYTYCZNE** (HTTP 200, nie 503) i to jest ta sama
decyzja co przy dysku ze zdjęciami: healthcheck oddający 503 już raz położył
ten serwis, a serwis bez captchy jest o wiele lepszy niż serwis w pętli
restartów. Monitoring ma pilnować **treści** odpowiedzi.

Poza produkcją i przy świadomie wyłączonych wszystkich miejscach sygnału nie
ma — stały `degraded` byłby szumem, który uczy ignorować to pole.

### UX 50+ I POLITYKA BEZPIECZEŃSTWA

Widget **nie jest jedynym nośnikiem informacji**: nad obcą ramką stoi zdanie
po polsku („Zanim wyślesz, sprawdzamy, że formularza nie wypełnia automat.
Zwykle dzieje się to samo i nie musisz nic robić."), bo inaczej osoba 60+
widzi w środku formularza ramkę nie wiadomo czego i nie wie, czy czekać.
Blok stoi **nad** `.form-actions`, więc przycisk wysyłki zostaje tam, gdzie
był, i zostaje przy swoich 48 px; tekst zostaje przy 18 px.

Komunikat odrzucenia **nie każe odświeżać strony** — najczęstszym powodem
odrzucenia jest wygaśnięcie sprawdzenia (token żyje 5 minut), czyli trafia to
w osobę, która pisała długo, a odświeżenie skasowałoby jej tekst. Mówi więc:
wyślij formularz jeszcze raz (wszystkie pola wracają przez `old()`, widget
wystawia świeży token), a jeśli nie pomoże — napisz do nas.

Drugi komunikat, ten o braku tokenu, jest **osobnym tekstem** i tak ma zostać
(uzasadnienie w sekcji o zaciśnięciu wyżej). `<noscript>` stoi wewnątrz tego
samego bloku co widget, więc trafia dokładnie tam, gdzie człowiek szuka
brakującego elementu, i nie rusza przycisku wysyłki. Ramka `.notice`, nie
`.field-help`: to jest zdanie do przeczytania, a nie podpowiedź pod polem —
tekst zostaje przy pełnym rozmiarze, a nie przy rozmiarze pomocniczym.

CSP dostaje `https://challenges.cloudflare.com` w `script-src` i `frame-src`,
**wyłącznie wtedy, gdy Turnstile ma klucze** — polityka opisuje to, co strona
naprawdę ładuje. Nie dokładamy `style-src 'unsafe-inline'`, o którym mówią
niektóre poradniki: style widgetu żyją wewnątrz jego ramki, a `unsafe-inline`
skasowałoby cały efekt issue #107.

### DROGA WYCOFANIA (bez wdrożenia, bez migracji)

1. **Wyłączenie w jednym miejscu:** wyczyść `TURNSTILE_SITE_KEY`
   i `TURNSTILE_SECRET_KEY` w Railway i zrestartuj serwis. Widget znika,
   walidacja przestaje kogokolwiek odpytywać, wszystkie sześć formularzy
   działa jak przed tą zmianą. Żeby `/health` nie zgłaszał wtedy `degraded`,
   ustaw też `TURNSTILE_NA_REJESTRACJI=false` i pozostałe pięć — brak kluczy
   jest błędem tylko wtedy, gdy konfiguracja obiecuje ochronę.
2. **Wyłączenie punktowe:** jeden formularz sprawia kłopot — ustaw jego
   zmienną na `false` (np. `TURNSTILE_NA_ZGLOSZENIU=false`).
3. **Wycofanie samego zaciśnięcia, bez zdejmowania Turnstile:** takiej
   zmiennej NIE MA i nie została dodana świadomie. Turnstile, który przepuszcza
   puste pole, nie chroni przed niczym (automat po prostu tego pola nie wysyła),
   więc przełącznik „captcha, ale bez wymagania tokenu" byłby przełącznikiem
   między ochroną a jej pozorem — a takie wpisy w konfiguracji to dokładnie ta
   klasa usterki, której pilnuje reszta tego repozytorium. Wycofanie idzie
   punktem 1 albo 2 wyżej: `TURNSTILE_NA_LOGOWANIU=false` zdejmuje z jednego
   formularza widget, walidację i wymóg tokenu naraz.
4. **Wycofanie kodu:** rewert commita. Nie ma migracji, nie ma zmiany
   schematu, nie ma danych do posprzątania — Turnstile nie zapisuje niczego
   do bazy.

**Zmiana wymaga:** pomiaru, nie wrażenia — i teraz jest czym mierzyć.
Odrzucenia z braku tokenu są w dzienniku, z nazwą miejsca, więc pytanie „czy
zamknęliśmy komuś drzwi" ma odpowiedź liczbową, a nie tylko wrażeniową.
Gdyby ktoś chciał poluzować zaciśnięcie „bo przeszkadza", potrzebny jest ten
ślad plus to, co przyszło na adres kontaktowy — a nie odwrotna intuicja.
Gdyby ktoś chciał zdjąć `<noscript>` albo połączyć oba komunikaty w jeden
„bo się powtarzają" — to jest cofnięcie tej decyzji do połowy: zostaje
zamknięta bramka bez tabliczki, co jest gorsze niż jedno i drugie osobno.

📄 `app/Support/Turnstile.php` · `app/Turnstile/KlientTurnstile.php` ·
`app/Turnstile/WynikTurnstile.php` · `app/Rules/TurnstileJestPotwierdzony.php`
(do 9 września 2026: `TurnstileNieJestPodrobiony`) ·
`resources/views/components/turnstile.blade.php` ·
`app/Http/Controllers/HealthController.php` ·
`app/Http/Middleware/ApplySecurityHeaders.php` · `config/kuking.php`
(`turnstile`) · `.env.example` · `.railway/railway.ts` ·
`tests/Feature/TurnstileWymagaPotwierdzeniaTest.php` ·
`docs/infra/DEPLOYMENT_RUNBOOK.md` (krok 8A) ·
`docs/INSPIRATION_DECISIONS.md` poz. 1.11 ·
`docs/legal/SECURITY_BASELINE.md` §4

## D-051 · Stopka: metryczka wersji 8 px i przełącznik motywu bez widocznego napisu — świadomy wyjątek od AGENTS.md §5

**Data:** 9 września 2026 · Issue #205 · Decyzja właściciela · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Wyjątek obowiązuje i ma
> pokrycie (`resources/css/app.css:4404-4406`,
> `resources/views/components/layout.blade.php:1244`, `AGENTS.md:233`), ale
> nie jest tym, czym brzmi. Strażnik minimum 18 px
> (`tests/Feature/MinimalnyRozmiarTekstuTest.php:29-36`) chodzi po ZAMKNIĘTEJ
> BIAŁEJ LIŚCIE sześciu selektorów i nie skanuje CSS w poszukiwaniu małych
> rozmiarów. Reguła i wyjątek „nie kolidują" wyłącznie dlatego, że reguła do
> `.site-version` w ogóle nie dochodzi — a samej wartości 8 px nie asertuje
> żaden test, więc podniesienie jej do `--text-meta` (wariant tu odrzucony)
> nie obleje niczego. To ten sam kształt co w D-091 i D-163: gwarancja na
> papierze, mierzona przez coś, co jej nie obejmuje. Naprawa nie należy do
> tego audytu — zgłoszona osobno.

Przy przebudowie stopki na kilka poziomów (issue #205) właściciel poprosił
wprost o dwie rzeczy, które łamią `AGENTS.md` §5:

1. metryczkę wersji („Alfa 0.1 · data wydania · commit") **drukiem 5–8 px**,
   podczas gdy §5 mówi „tekst ≥ 18 px" (najmniejszy token w ogóle,
   `--text-meta`, to 15 px — 8 px jest poniżej NAJMNIEJSZEGO tokenu
   w systemie, nie tylko poniżej minimum produktowego);
2. przełącznik motywu jako **samą ikonę**, bez widocznego napisu obok,
   podczas gdy §5 mówi „ikona nigdy nie jest jedynym opisem ważnej akcji".

Właściciel dostał przed decyzją trzy warianty, w tym wariant zgodny z §5
(wersja na `--text-meta`, przełącznik jako ikona + krótki podpis „Ciemny" /
„Jasny"). **Wybrał świadomie wariant, który regułę łamie w tych dwóch
punktach** — bo w jego ocenie wynik wygląda lepiej i zajmuje mniej miejsca
w stopce niż jakikolwiek z wariantów zgodnych. To jest jego produkt i jego
decyzja o tym, jak ma wyglądać stopka — a nie pomyłka do poprawienia przy
najbliższej okazji.

### DLACZEGO TO JEST WYJĄTEK, NIE ZMIANA REGUŁY

`AGENTS.md` §5 zostaje **dokładnie taki, jaki jest, wszędzie indziej**.
Ten wpis nie obniża minimum 18 px ani nie znosi zakazu samej ikony dla
reszty serwisu — od jutra nowy ekran, który spróbuje 12-pikselowego tekstu
albo przycisku bez podpisu, dalej jest błędem, nie precedensem. D-051 jest
nazwaną, zapisaną dziurą w regule, nie furtką.

### ZAKRES WYJĄTKU — TYLKO TE DWA ELEMENTY

- `.site-version` w `resources/views/components/layout.blade.php`
  (metryczka wersji: etap produktu, data wydania, skrót commita) —
  **8 px**, górny kraniec przedziału 5–8 px, który podał właściciel: to
  najczytelniejszy wybór z tego, o co poprosił.
- `.site-footer-motyw` / `.site-footer-motyw-przycisk` (przełącznik
  motywu w stopce) — **sama ikona (`ksiezyc` przy jasnym motywie, `slonce`
  przy ciemnym — patrz „IKONA WŁASNA, NIE POŻYCZONA" niżej), bez
  widocznego napisu obok**.

Nigdzie indziej. W szczególności: nawigacja mobilna, przyciski akcji,
podpisy pod ikonami w innych miejscach serwisu i wszystkie pozostałe
teksty stopki (odnośniki, nagłówki grup, hasło marki) trzymają się §5 bez
zmian — odnośniki w stopce są zwykłymi linkami ≥16 px z widocznym tekstem,
tak jak przed przebudową.

### CO MIMO TO ZOSTAJE NIENARUSZONE

Złamanie §5 dotyczy WYŁĄCZNIE rozmiaru tekstu i widoczności napisu.
Cztery rzeczy nie są częścią tego kompromisu i zostały utrzymane wprost:

1. **Przycisk motywu ma nazwę dostępną.** `aria-label` i `title` niosą
   dokładnie ten sam tekst, co dawny widoczny napis („Włącz ciemny
   wygląd" / „Włącz jasny wygląd"), plus `<span class="visually-hidden">`
   jako drugie, tanie zabezpieczenie. Sama ikona bez nazwy dostępnej jest
   dla czytnika ekranu przyciskiem-widmem — tego właściciel nie prosił
   złamać, i to jest różnica między „mniej miejsca" a „zepsute".
2. **Pole kliknięcia zostaje ≥48×48 px.** To, co zajmowało miejsce
   w stopce, był NAPIS OBOK ikony, nie wysokość ani szerokość samego
   przycisku — `.btn` już dawało `min-height: 3rem` (48 px) i padding,
   który przy samej ikonie daje ~64 px szerokości. Zdjęcie napisu nie
   zmniejszyło obszaru dotyku ani o piksel.
3. **Kontrast metryczki wersji zostaje AA.** `--color-ink-muted` na
   `--color-surface-raised` liczy 7,54:1 (`docs/design/DESIGN_SYSTEM.md`),
   daleko od progu 4,5:1 — i to jest niezależne od rozmiaru czcionki.
   Rozmiar tekstu jest decyzją właściciela; nieczytelny kolor byłby
   dodatkową, nikim nie zamówioną usterką, i to jest granica, której ten
   wpis broni.
4. **Metryczka wersji jest widoczna zawsze, nie za `hover` ani za
   `title`.** Właściciel prosił o mały druk, nie o ukrycie — informacja
   dostępna tylko przez najazd kursorem jest dla części osób (telefon,
   dotyk) niedostępna w ogóle (`docs/UX_50_PLUS.md`). `.site-version`
   nie ma `display: none`, `hidden` ani odpowiednika schowanego za
   interakcją; stoi w HTML-u i na ekranie tak samo, jak dziś.

### IKONA WŁASNA, NIE POŻYCZONA

Pierwsza wersja tego wpisu i tego PR-a używała do przełącznika istniejącej
ikony `settings` (zębatka) jako „najbliższego sensownego zamiennika" — zestaw
`<x-ikona>` nie miał wtedy księżyca ani słońca. To był błąd, złapany przy
przeglądzie: `settings` to DOKŁADNIE ten sam kształt, którym w menu bocznym
oznaczona jest pozycja „Ustawienia" (`route('settings.*')`,
`resources/views/components/layout.blade.php`). Po zmianie w serwisie
istniałyby więc dwa różne przyciski o tym samym kształcie.

Przy zwykłym przycisku z podpisem dwie różne rzeczy pod tym samym kształtem
dałoby się wybaczyć — podpis rozstrzyga. Ale przełącznik motywu z tego
wpisu jest z definicji BEZ widocznego podpisu (punkt 2 wyżej), więc kształt
jest jedyną wskazówką, co przycisk robi. Pożyczony kształt zamieniał więc
oszczędność miejsca w gotową pomyłkę do kliknięcia — dokładnie tego typu
usterkę, przed którą ostrzega `docs/UX_50_PLUS.md`.

Naprawa: `resources/views/components/ikona.blade.php` dostał dwa nowe,
własne kształty — `ksiezyc` i `slonce`, tym samym stylem co reszta zestawu
(sam obrys, `stroke-width: 1.8`, bez wypełnień, ten sam `viewBox`). Ikona
pokazuje WYNIK kliknięcia, spójnie z tekstem, który już tam jest: jasny
motyw → napis „Włącz ciemny wygląd" → `ksiezyc`; ciemny motyw → napis
„Włącz jasny wygląd" → `slonce`. `WyborMotywuTest` sprawdza, że kształt
zmienia się razem z motywem, żeby ta sama pomyłka (jeden kształt na oba
stany) nie wróciła po cichu.

### DLACZEGO NIE „NAJMNIEJSZY TOKEN" (`--text-meta`, 15 px)

Rozważona i odrzucona: użycie istniejącego, udokumentowanego tokenu
zamiast nowej wartości `0.5rem`. 15 px jest wciąż wyraźnie większe niż to,
o co poprosił właściciel („małym druczkiem, np. 5–8 px") — użycie tokenu
zamiast liczby z jego przedziału byłoby po cichu cofnięciem decyzji, a nie
jej wykonaniem. Zamiast tego metryczka dostaje własną wartość
(`calc(0.5rem * var(--user-text-scale, 1))`), skalowaną tak samo jak reszta
typografii serwisu — patrz punkt niżej.

### SKALOWANIE Z USTAWIENIEM CZYTELNOŚCI

8 px to rozmiar BAZOWY, nie sztywny. `.site-version` mnoży go przez
`var(--user-text-scale, 1)`, dokładnie jak każdy inny token typografii
w `tokens.css`. Bez tego osoba, która celowo powiększyła sobie tekst na
`/ustawienia/czytelnosc`, dostałaby jedno miejsce w całym serwisie, którego
jej własne ustawienie nie dotyczy — czyli nowy, nikim nie zamówiony błąd
obok tego, na który właściciel świadomie się zgodził.

### DROGA WYCOFANIA

Właściciel zobaczy efekt na produkcji i może uznać, że jednak wolałby
jeden z odrzuconych wariantów (np. ikona z krótkim podpisem „Ciemny" /
„Jasny", albo wersja na `--text-meta`). To jest zwykła zmiana wizualna:
podnieść `font-size` `.site-version` do tokenu (np. `--text-meta`) i/lub
dopisać widoczny tekst obok `<x-ikona>` w `.site-footer-motyw`, usunąć ten
wpis albo oznaczyć go jako uchylony. Żadna z tych zmian nie rusza schematu
bazy, tras ani logiki `ThemeController` — cofnięcie jest kosmetyczne
i jednoplikowe (`resources/views/components/layout.blade.php` +
`resources/css/app.css`).

📄 `resources/views/components/layout.blade.php` (`.site-footer`) ·
`resources/css/app.css` (`.site-version`, `.site-footer-motyw*`) ·
`resources/views/components/ikona.blade.php` ·
`tests/Feature/WyborMotywuTest.php` ·
`tests/Feature/StopkaPoziomyTest.php` ·
`docs/design/DESIGN_SYSTEM.md` (kontrast `ink-muted`)

---

## D-052 · Automat oznacza podejrzane treści do przeglądu — trzecie źródło w `reports`, nigdy konsekwencja dla autora

**Data:** 9 września 2026 · **Prośba właściciela** · Status: **obowiązuje**

Właściciel: *„dodaj, żeby algorytm jakoś sam sprawdzał podejrzane wpisy,
zachowania, teksty itp, by szybciej wyłapać to"*.

Serwis dostaje **wykrywacz, który podnosi rękę** — trzy sygnały liczone
w kolejce po opublikowaniu wpisu albo komentarza, kończące się JEDNĄ pozycją
w kolejce moderatora z powodem napisanym po polsku. Treść zostaje widoczna,
autor niczego nie zauważa, nikomu nic się nie dzieje.

### CO TA DECYZJA REALIZUJE, A CZEGO NIE RENEGOCJUJE

To jest wykonanie poz. **3.6** (ADAPT: sygnały pasywne → oznaczenie do
przeglądu, nigdy blokada) i poz. **3.10** (automat nigdy nie decyduje sam)
z `docs/INSPIRATION_DECISIONS.md`. Nie rusza i nie osłabia:
poz. **3.14** (żadnego wyciszania po N zgłoszeniach — REJECT),
poz. **3.16** (żadnego cichego ograniczania zasięgu — REJECT, art. 17 DSA),
`AGENTS.md` §9 („flagowanie, nigdy samodzielny ban").

### TRZY SYGNAŁY, NIE SIEDEM

Wdrożone dziś: **znany wzorzec ogłoszenia** (numer telefonu do kontaktu,
„zarabiaj z domu", `t.me/`), **odnośnik zewnętrzny u świeżego konta** (konto
młodsze niż 7 dni ORAZ pierwsze trzy treści) i **powtórzona treść tego samego
konta** (≥ 92% podobieństwa, w ciągu 60 minut, przy tekście dłuższym niż
40 znaków).

Świadomie ODŁOŻONE, mimo że przy setkach kont wreszcie miałyby dane:
wiele kont z jednego IP, nagła seria wpisów, ta sama treść u RÓŻNYCH kont,
czas wypełnienia formularza. **Powód jest jeden i nazywa się falą migracyjną
z Garnek.pl:** grupa osób 50+ przechodzi do nas razem, rejestruje się w tym
samym tygodniu, część z jednego łącza (koło gospodyń, biblioteka, dom
seniora), i od razu przenosi archiwum — dziesiątki przepisów w godzinę,
wklejanych z notatnika, czasem tych samych u kilku osób, bo krążyły w tej
grupie latami. Każdy z tych czterech sygnałów opisuje dokładnie to zachowanie.
Wykrywacz, który by je złapał, oznaczyłby w pierwszym dniu dokładnie te osoby,
dla których ten serwis powstał.

**Wzrost skali NIE odblokował więc sygnałów „na dużą skalę".** Odblokował
dane, ale ruch, który je wnosi, jest ruchem, na którym te sygnały się mylą.
Odblokował za to pracę nad KOLEJKĄ — i to jest część, w której skala zmieniła
projekt naprawdę.

### DLACZEGO `reports` Z NOWYM `source`, A NIE DRUGA TABELA

Bo koniec drogi jest ten sam: decyzja moderatora, wiersz
w `moderation_actions`, ścieżka odwołania z DSA art. 17, wspólna retencja
(`kuking:sprzataj-sprawy-moderacyjne`). Druga tabela znaczyłaby drugą kolejkę,
drugi ekran i drugą okazję, żeby jedna z nich została z tyłu — ten sam
argument, którym `docs/DATABASE.md` uzasadnia trzymanie drogi społecznościowej
i prawnej razem.

`source = 'automat'` różni się od tamtych dwóch trzema rzeczami:
nie ma zgłaszającego (więc nie uruchamia obowiązków z art. 16 ust. 4 i 5 —
nikt nic nie zgłosił), MUSI mieć cel, i **powstaje najwyżej raz na treść**.

### JEDNO OZNACZENIE NA TREŚĆ, NA ZAWSZE

Indeks częściowy `reports_jeden_automat_na_tresc` obejmuje WSZYSTKIE statusy,
także `rejected`. To jest obietnica złożona moderatorowi: „to nic takiego"
zamyka sprawę i automat już z tym nie wraca. Discourse rozwiązał to tym samym
warunkiem (`docs/research/repos/discourse-discourse.md` §4.5: reguła nie
flaguje ponownie, jeśli wcześniejsze zgłoszenie zostało odrzucone) —
bez tego automat kłóci się z człowiekiem w kółko.

Cena jest nazwana wprost: wpis opublikowany niewinnie i poprawiony edycją nie
jest analizowany drugi raz. Ta luka jest opisana
w `docs/legal/SYGNALY_AUTOMATU.md` §4 i zamykana zgłoszeniem od człowieka.
Dla komentarzy lukę zamyka D-256 (#909) — bez naruszania tej obietnicy.

### OSOBNY EKRAN, BO TO JEST INNA PRACA

`/admin/sygnaly` — grupowane po autorze, uszeregowane od najcięższego sygnału,
z jednym przyciskiem zamykającym całą grupę. Oznaczenia automatu **nie
wchodzą** do `/admin/zgloszenia`: tam czekają ludzie i biegną terminy z DSA
art. 16 ust. 5, a maszynowe podejrzenia zasypałyby tamtą listę przy pierwszej
fali nowych kont. Pełny formularz decyzji jest jeden, na ekranie zgłoszeń
(`?zrodlo=automat`) — druga jego kopia rozjechałaby się z oryginałem przy
pierwszej zmianie w pouczeniu z art. 17.

### CO Z TEGO WYNIKA DLA DOKUMENTÓW UŻYTKOWNIKA

`resources/legal/zasady.md` punkt 12 mówił „reagujemy na zgłoszenia, nie
inwigilujemy — sprawdzamy to, co ktoś zgłosił". Od tej decyzji to nie była
już prawda, więc punkt został przepisany: mówi, że narzędzie istnieje, co
wychwytuje i **że niczego samo nie ukrywa, nie usuwa ani nie ogranicza**
(DSA art. 14 ust. 1 wymaga opisania narzędzi automatycznych).

`UzasadnienieDecyzji::skadSprawa()` dostało trzecią gałąź. Bez niej autor
treści wskazanej przez automat przeczytałby „sprawa zaczęła się od zgłoszenia,
które dostaliśmy od innej osoby" — nieprawdę każącą mu szukać wśród znajomych
kogoś, kto go zgłosił, choć nikt tego nie zrobił (art. 17 ust. 3 lit. b i c).
Zdanie „nie mamy w Kuking automatu, który sam ukrywa, usuwa albo blokuje"
zostaje prawdą i po tej zmianie.

### POMIAR — BO INACZEJ PO MIESIĄCU NIKT NIE BĘDZIE WIEDZIAŁ

`php artisan kuking:raport-sygnalow --dni=30`: ile pozycji dziennie i jaki
odsetek okazał się niczym, w rozbiciu na sygnały. Progi reakcji (70% fałszywych
alarmów, 30 pozycji dziennie, pozycje starsze niż tydzień) —
`docs/legal/SYGNALY_AUTOMATU.md` §6. Tam też stoi §7: przy jakiej skali to
podejście się kończy i na co je wtedy zamienić.

### WYCOFANIE

1. **Wyłączenie bez wdrożenia:** `KUKING_SYGNALY_AUTOMATU=false`. Zadanie
   w kolejce kończy się na pierwszej linijce, nowe oznaczenia nie powstają,
   istniejące zostają do rozpatrzenia.
2. **Wycofanie kodu:** rewert commita.
3. **Wycofanie schematu:** `migrate:rollback` tej jednej migracji. `down()`
   **odmawia**, gdy w bazie są oznaczenia już ROZSTRZYGNIĘTE — niosą powód,
   dla którego moderator coś zrobił, i są dokumentem przy odwołaniu.
   Świadome wymuszenie (najpierw kopia tabeli):
   `KUKING_ROLLBACK_KASUJE_SYGNALY_AUTOMATU=1`.

**Zmiana wymaga:** pomiaru z komendy wyżej, nie wrażenia. Dołożenie sygnału
z listy odłożonych wymaga pokazania, że fala migracyjna już go nie zapala —
odwrotna intuicja nie wystarcza.

📄 `app/Domain/Moderation/Sygnaly/WykrywaczSygnalow.php` ·
`app/Domain/Moderation/Sygnaly/Sygnal.php` ·
`app/Domain/Moderation/Actions/OznaczDoPrzegladu.php` ·
`app/Jobs/PrzeanalizujTresc.php` ·
`app/Http/Controllers/Admin/SygnalyController.php` ·
`app/Console/Commands/RaportSygnalow.php` ·
`resources/views/pages/admin/sygnaly.blade.php` ·
`database/migrations/2026_09_09_400000_sygnaly_automatu_w_zgloszeniach.php` ·
`config/kuking.php` (`moderation.sygnaly`) ·
`resources/legal/zasady.md` (punkt 12) ·
`tests/Feature/SygnalyAutomatuTest.php` ·
`tests/Feature/CofniecieMigracjiSygnalowAutomatuTest.php` ·
`docs/legal/SYGNALY_AUTOMATU.md` · `docs/DATABASE.md` · `docs/MODERATION.md` ·
`docs/INSPIRATION_DECISIONS.md` poz. 3.6 i 3.10

## D-053 · JavaScript jest wymagany na formularzach chronionych captchą, a nigdzie nie wolno zostawić martwego przycisku

**Data:** 9 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

**Zmienia zasadę z `AGENTS.md` §5**, która brzmiała: „Rejestracja, logowanie,
publikacja wpisu, przepis, komentarz i »Ugotowałem« **muszą działać bez
JavaScriptu**".

### Co powiedział właściciel i dlaczego ma rację

> „Nie wiem czemu masz tę blokadę na JS, przecież ci starsi ludzie mają
> nowoczesne telefony, to nie będzie wchodziła babcia 80-letnia z Nokii 3310,
> tylko seniorka, która ma Samsunga A23 czy coś, Xiaomi itp., więc ma
> JavaScript, albo na kompie to robi."

I dalej, już jako rozstrzygnięcie:

> „W tych newralgicznych miejscach niech będzie obowiązkowo, jak ta rejestracja
> itp., tam gdzie można się obejść to spoko, ale lepiej żeby był z wygody."

Przesłanka faktyczna jest prawdziwa. Grupa docelowa Kukinga to nie jest ktoś
bez JavaScriptu — to ktoś z niedrogim, ale współczesnym telefonem albo
z komputera. Stara reguła zakazywała przy okazji rzeczy, które nikomu nie
szkodzą: podglądu zdjęcia przed wysłaniem, licznika znaków, kadrowania awatara.

### Co z niej zostaje, i to nie jest kompromis dla świętego spokoju

Uzasadnienie starej reguły NIGDY nie brzmiało „telefon nie ma JavaScriptu".
Brzmiało: **„przy słabym zasięgu skrypt się nie dociąga"**. To zdanie jest
prawdziwe niezależnie od tego, jaki ktoś ma telefon. Widget Turnstile nie
dociągnie się po wsi na jednej kresce zasięgu, w piwnicy, w pociągu i przy
blokadzie reklam — na sprzęcie, który JavaScript ma i ma go włączonego.

Różnica jest taka, że dawniej odpowiedzią było „zrób to samo bez skryptu",
a teraz jest: **powiedz człowiekowi po polsku, co się stało i co ma zrobić**.
Zakazane zostaje jedno, za to bezwarunkowo: **przycisk, który po kliknięciu
milczy**. `<noscript>` z konkretną instrukcją, osobny komunikat dla „widget
się nie dociągnął" i dla „token podrobiony", oraz adres kontaktowy dla kogoś,
kto naprawdę utknął.

### Skutki

| Miejsce | Przed | Po |
|---|---|---|
| `/register`, `/login`, `/nie-pamietam-hasla`, `/cofnij-usuniecie-konta`, `/napisz-do-nas`, `/zglos-nielegalna-tresc` | brak tokenu Turnstile przepuszczał wysłanie | brak tokenu **odrzuca** wysłanie (D-050 przepisane) |
| ulepszenia oparte na skrypcie w pozostałych miejscach | wymagały wersji zapasowej bez JS | wolno bez wersji zapasowej, z `<noscript>` przy tym, co bez skryptu nie działa |
| Cloudflare nie odpowiada, zły sekret | przepuszczamy | **bez zmian — przepuszczamy** |

Ostatni wiersz jest częścią decyzji, nie wyjątkiem od niej: wymóg dotyczy
skryptu u człowieka, a nie sprawności cudzej usługi ani naszej konfiguracji.

### Czego pilnujemy, żeby ta decyzja nie kosztowała nas ludzi

Odrzucenie z powodu braku tokenu **zostawia ślad w dzienniku** (bez adresu IP
i bez danych osobowych — sam fakt i nazwa formularza). Po tygodniu ma dać się
odpowiedzieć na pytanie „ilu osobom zamknęliśmy drzwi". Jeśli okaże się, że to
zauważalny odsetek rejestracji, wracamy do tej decyzji z danymi, a nie
z przeczuciem.

### Uczciwie o progu, który postawiła D-007

D-007 kończyła się zdaniem: **„Zmiana wymaga danych pokazujących, że nasi
użytkownicy nie mają tego problemu"**. Takich danych nie mamy — mamy rozumowanie
właściciela o sprzęcie grupy docelowej, które jest trafne, ale rozumowanie to
nie pomiar. Zapisuję to wprost, zamiast udawać, że próg został spełniony.

Odpowiedzią na ten brak jest ostatni akapit wyżej: **zaczynamy te dane zbierać**
od pierwszego dnia obowiązywania nowej reguły. Za tydzień będzie wiadomo, ilu
osobom brak tokenu zamknął drzwi — i wtedy albo D-053 zostaje potwierdzona
pomiarem, albo wracamy do niej z liczbami.

### Droga wycofania

Jedna wartość w `config/kuking.php` (sekcja `turnstile`) wyłącza wymóg
w wybranym miejscu albo wszędzie. Kod przepuszczający wysłanie bez tokenu
nie znika — zmienia się warunek, przy którym się uruchamia.

---

## D-054 · Zdjęcie profilowe ma własny, krótki ekran `/ustawienia/zdjecie` — pole zostało z formularza profilu PRZENIESIONE, nie skopiowane

**Data:** 9 września 2026 · **Prośba właściciela** · Status: **obowiązuje**

Właściciel: *„zdjęcie profilowe łatwiej niż teraz, trzeba teraz wyklikać
ustawienia, coś tam, profil, coś tam, zjechać itp."*

Funkcja istniała od dawna — brakowało **drogi** do niej. Pole `name="avatar"`
stało jako **szóste** pole formularza `/ustawienia/profil`, pod imieniem,
nazwą użytkownika, opisem, regionem i specjalnością.

Trzy rzeczy, w tej kolejności:

1. **Własny awatar na `/@ja` jest odnośnikiem** wprost do ustawienia zdjęcia.
   To jest miejsce, w które człowiek klika instynktownie, a do tej zmiany nie
   robiło ono nic.
2. **Podpis pod awatarem jest widoczny zawsze** — „Dodaj zdjęcie profilowe"
   albo „Zmień zdjęcie profilowe". Bez zdjęcia stoi tam sama litera i nic nie
   mówi, że da się to zmienić; klikalna ikona bez opisu łamie AGENTS.md §5.
   Nazwa jest długa CELOWO: wiersz niżej stoi „Dodaj zdjęcie", które prowadzi
   do dodania WPISU ze zdjęciem potrawy.
3. **Osobny ekran, nie kotwica.** `#f-avatar` w starym formularzu wyrzucałaby
   na telefonie w środek ekranu pełnego cudzych pól.

### DLACZEGO PRZENIESIONE, A NIE SKOPIOWANE

Zostawienie pola w obu miejscach dałoby dwa formularze robiące to samo —
drugą okazję do rozjazdu, tę samą, przed którą broni się `LimityZdjec`
i `KasujZdjecie::ODWOLANIA`. Przy okazji znika usterka, o którą nikt nie
pytał: formularz profilu wysyła wszystkie pola naraz, więc zmiana samego
zdjęcia odbijała się od błędu przy **nazwie użytkownika** (zajęta,
zastrzeżona) — czyli od czegoś, czego człowiek nie dotykał.

**Potok zdjęć nie zmienia się ani o krok**: `StoreUploadedImage` →
`UsunGps` → `ProcessUploadedImage`, oryginał pod `incoming/`, status
`pending`, warianty dopiero z zadania w tle. Pilnuje tego osobny test na tej
konkretnej trasie (`test_zdjecie_profilowe_idzie_tym_samym_potokiem_i_traci_gps`),
bo „ta sama akcja jest wołana" i „ta trasa naprawdę przez nią idzie" to dwa
różne zdania.

### USUNIĘCIE ZDJĘCIA — FUNKCJA, KTÓREJ NIE BYŁO WCALE

Dało się tylko podmienić. Kto wgrał zdjęcie przez pomyłkę, nie miał jak go
zdjąć. Usunięcie kasuje pliki **od razu** (`KasujZdjecie`), a nie zostawia ich
dobowej karencji `kuking:sprzataj-osierocone-zdjecia`: serwis odpowiada
„Zdjęcie usunięte", a plik z czyjąś twarzą otwierałby się dalej pod tym samym
adresem (ta sama klasa błędu co issue #93). **Podmiana** zostaje przy
karencji — tam takiej obietnicy nie ma, a kasowanie plików to ruch po sieci
doklejony do żądania, które właśnie przyjęło kilkumegabajtowy plik.

**Zmiana wymaga:** pomiaru mówiącego, że ludzie szukają zdjęcia w formularzu
profilu i go tam nie znajdują. Wtedy właściwą odpowiedzią i tak nie jest drugie
pole, tylko wyraźniejszy odnośnik — który już tam stoi, z podglądem awatara.

📄 `app/Http/Controllers/Settings/AvatarSettingsController.php` ·
`app/Policies/ProfilePolicy.php` (`update`) ·
`resources/views/pages/settings/avatar.blade.php` ·
`resources/views/pages/profile/show.blade.php` ·
`routes/web.php` · `config/kuking.php` (`limits.ustawienia_profil`) ·
`tests/Feature/ZdjecieProfiloweNaSkrotyTest.php` ·
`tests/Support/JpegZeWspolrzednymiGps.php`

---

## D-055 · Druga para oczu to model OpenAI, który podnosi rękę — nigdy nie zamyka drzwi

**Data:** 9 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

Właściciel: *„model AI będzie, OpenAI daje darmowy model moderation coś tam"*,
a doprecyzowując: *„omni-moderation-latest, jego wprowadzić trzeba do
moderowania takiego, że przetwarza i daje »alarm« w panelu i ewentualnie na
maila"*.

Publikowane wpisy i komentarze — a przy wpisach także **zdjęcia** — idą do
`omni-moderation-latest`. Wynik powyżej naszego progu staje się kolejnym
`Sygnal`-em w tym samym zadaniu, które liczy sygnały lokalne z **D-052**,
i kończy się dokładnie tak samo: jedną pozycją w kolejce moderatora z powodem
napisanym po polsku. Treść zostaje widoczna, autor niczego nie zauważa.

### TO ŁAPIE INNĄ KLASĘ TREŚCI NIŻ NASZ REALNY PROBLEM

Moderation API ocenia **nienawiść, przemoc, treści seksualne
i samookaleczenie**. **Spamu nie ocenia w ogóle** — a spam jest tym, co
przyjdzie razem z falą z Garnek.pl: „zarobki z domu", odnośniki, numery
telefonu. To jest **uzupełnienie** sygnałów z D-052, nie ich zamiennik.
Zapisane wprost, bo inaczej ktoś uzna, że skoro jest AI, to spam mamy
załatwiony, i wyłączy tamte trzy jako zbędne.

### NAJWIĘKSZA WARTOŚĆ SĄ TU ZDJĘCIA

Kuking stoi na fotografiach obiadów wrzucanych przez nieznajomych. To jest
jedyna treść w tym serwisie, której **nikt nie przeczyta**, dopóki ktoś jej
nie zgłosi — tekst przynajmniej mija się z ludzkim okiem w feedzie. Wersja
`omni` ocenia obrazy i to jest powód, dla którego ta decyzja w ogóle ma
wartość większą niż „mamy AI".

Zdjęcie idzie jako `data:` z wariantu `thumb` przekodowanego do JPEG: wariant
nie ma EXIF-u, czyli współrzędnych kuchni, a `data:` zamiast adresu, bo
publiczny adres dla OpenAI byłby publiczny także dla wszystkich innych.

### DANE WYCHODZĄ POZA EOG — I DLATEGO NAJPIERW DOKUMENTY

Wysłanie treści do OpenAI to powierzenie przetwarzania podmiotowi w USA.
Zrobione RAZEM z kodem, nie po nim:

- `resources/legal/polityka-prywatnosci.md` — OpenAI w tabeli podmiotów
  przetwarzających, osobny akapit „co wysyłamy i czego NIE wysyłamy" oraz
  drugi wyjątek w akapicie o przekazywaniu poza EOG;
- `resources/legal/zasady.md` punkt 12 — informacja dla użytkownika, że treść
  jest oceniana maszynowo, i wprost, że **żadne z tych narzędzi niczego nie
  ukrywa, nie usuwa, nie blokuje ani nie ogranicza zasięgu** (DSA art. 14
  ust. 1);
- `UzasadnienieDecyzji::skadSprawa()` — autor decyzji dowiaduje się, że treść
  wskazało narzędzie oceniające maszynowo, a nie czyjeś zgłoszenie (art. 17
  ust. 3 lit. b i c).

Pilnuje tego `PolitykaPrywatnosciWymieniaKazdaUslugeTest` z PR #224: obecność
klasy `App\Moderacja\KlientOpenAI` w kodzie oblewa test, dopóki w polityce nie
padnie słowo „OpenAI".

**Do API nie idzie NIC identyfikującego autora** — ani adres e-mail, ani nazwa
konta, ani identyfikator wpisu, ani adres IP. To nie jest ostrożność na zapas,
tylko warunek tego, co napisaliśmy w polityce, i jedyny powód, dla którego ta
funkcja mieści się w minimalizacji danych (`AGENTS.md` §7). Treści prywatne
nie wychodzą w ogóle.

### GRANICA TA SAMA CO W D-052, TYLKO WAŻNIEJSZA

Model podnosi rękę, nigdy nie zamyka drzwi. Żadnego automatycznego ukrywania,
wyciszania ani blokowania na podstawie wyniku — poz. 3.10
(`docs/INSPIRATION_DECISIONS.md`) powstała dokładnie na taką sytuację. Model
uczony głównie na angielszczyźnie będzie się mylił na polskim, a już
zwłaszcza na języku, jakim mówi o jedzeniu siedemdziesięcioletnia kobieta
z Podkarpacia. Fałszywy alarm kosztuje jedną pozycję w kolejce i nic więcej.

**Nie używamy pola `flagged` z API.** Progi trzymamy u siebie
(`moderation.model.prog`, domyślnie 0,5): cudza decyzja przy polszczyźnie
i kuchni bywa hojna — „zabiłam kurę na rosół", „krwisty stek", „ubić pianę" —
a każde trafienie kosztuje uwagę jedynego moderatora. Kategorie pilne mają
próg NIŻSZY (0,2): tam wolimy fałszywy alarm od przeoczenia.

### POCZTA: ZBIORCZO, BO INACZEJ PRZESTANIE BYĆ CZYTANA

Jeden list na każdą oznaczoną treść zamieniłby przy fali migracyjnej skrzynkę
moderatora w śmietnik — a skończyłoby się tym, że przestałby te listy
otwierać, czyli alarm przestałby działać dokładnie wtedy, gdy jest potrzebny.

- **Podsumowanie zbiorcze** raz dziennie o 07:00
  (`kuking:podsumowanie-automatu`). Nie wychodzi, gdy nie ma o czym pisać.
- **List natychmiastowy** wyłącznie dla `KategorieModeracji::PILNE` — treści
  seksualnych i wszystkiego, co dotyczy dzieci. To jest CAŁA lista i ma taka
  zostać: gdyby „pilne" znaczyło pięć rzeczy, rozróżnienie przestałoby
  cokolwiek znaczyć.

Drugi, niezależny powód tego ograniczenia: EmailLabs na planie darmowym daje
**300 listów dziennie**, dzielone z potwierdzeniami rejestracji. Alarmy
moderacyjne nie mogą zjeść limitu potrzebnego na to, żeby ktoś w ogóle mógł
założyć konto.

### JEDNO ZADANIE, NIE DWA

Ocena modelem dolicza się do sygnałów lokalnych w tym samym
`PrzeanalizujTresc`. Dwa osobne zadania próbowałyby postawić dwa oznaczenia
tej samej treści, a indeks `reports_jeden_automat_na_tresc` (D-052)
przepuściłby tylko to, które wygrało wyścig — ocena modelu potrafiłaby wtedy
przepaść dlatego, że wpis zawierał numer telefonu.

### WYCOFANIE

1. **Wyłączenie bez wdrożenia:** wyczyszczenie `OPENAI_MODERATION_KEY`.
   `KlientOpenAI::oceniamy()` oddaje wtedy `false`, żadne żądanie nie
   wychodzi, sygnały lokalne z D-052 działają dalej bez zmian.
2. **Wyłączenie samych listów:** wyczyszczenie `KUKING_MODEL_ALARM_EMAIL` —
   zostaje sama kolejka w panelu.
3. **Wycofanie kodu:** rewert commita. **Nie ma migracji ani zmiany
   schematu** — `automat_model` to kolejna wartość w `reports.reason`, kolumna
   bez CHECK-u.
4. Przy trwałym wycofaniu trzeba zdjąć OpenAI z polityki prywatności
   i z punktu 12 zasad — dokument nie może wymieniać dostawcy, do którego nic
   nie wychodzi.

**Zmiana wymaga:** pomiaru z `kuking:raport-sygnalow`, nie wrażenia.
Podniesienie albo obniżenie progu to zmiana liczby pozycji w kolejce —
i wyłącznie tego.

📄 `app/Moderacja/KlientOpenAI.php` · `app/Moderacja/OcenaModelem.php` ·
`app/Moderacja/WynikOceny.php` · `app/Moderacja/KategorieModeracji.php` ·
`app/Notifications/PilnyAlarmModeracyjny.php` ·
`app/Notifications/PodsumowanieKolejkiAutomatu.php` ·
`app/Console/Commands/PodsumowanieAutomatu.php` ·
`app/Jobs/PrzeanalizujTresc.php` · `config/kuking.php` (`moderation.model`) ·
`routes/console.php` · `resources/legal/polityka-prywatnosci.md` ·
`resources/legal/zasady.md` (punkt 12) ·
`app/Domain/Moderation/UzasadnienieDecyzji.php` ·
`tests/Feature/ModeracjaModelemTest.php` ·
`docs/legal/SYGNALY_AUTOMATU.md` §8 · `docs/MODERATION.md`

---

## D-056 · Logowanie linkiem e-mail: link prowadzi na ekran z przyciskiem, ważny 30 minut, hasło zostaje drogą równoległą

**Data:** 10 września 2026 · Issue #25 · Status: **obowiązuje**

**Doprecyzowanie właściciela, 20 września 2026 (#889):** jeśli przed
wykonaniem kolejki link wygasł albo został zastąpiony, pomijamy list.
Nie wysyłamy dodatkowej wiadomości i nie tworzymy nowego tokenu przy
ponowieniu zadania. Starsze zadania bez zapisanego terminu sprawdzają
aktualność tokenu w bazie. Czas w liście odnosimy do chwili zamówienia,
nie do doręczenia. Kontrola przy kliknięciu pozostaje rozstrzygająca:
ważny przy wysyłce link może utracić ważność przed przeczytaniem listu.

Kuking wpuszcza na konto **linkiem wysłanym pocztą**. Droga jest równorzędna
z hasłem i widoczna wprost na ekranie logowania, a nie schowana pod „innymi
opcjami". Adres: `/logowanie/link`.

### DLACZEGO — TO NIE JEST WYGODA

`docs/research/AUDIENCE_50_PLUS.md`: **tylko 12,3% osób w wieku 65-74 ma
podstawowe umiejętności cyfrowe** (GUS 2025), hasło i e-mail są murem,
a „ktoś mi pomógł założyć konto" jest normą. Właściciel spodziewa się fali
migracyjnej z Garnek.pl — setek kont zakładanych w kilka dni przez osoby, dla
których to jest pierwsze własne konto od lat. Część z nich zapomni hasła
w tym samym tygodniu, w którym je ustawiła.

Dla nich logowanie linkiem jest **drogą podstawową**, nie awaryjną, i tak jest
zaprojektowane: budżet listów, limity i teksty na ekranie liczone są na duży
odsetek użytkowników, nie na garstkę.

**Hasło zostaje jako droga równoległa.** Nie odbieramy nikomu tego, co już
umie — a przy okazji nie zamykamy nikogo w skrzynce pocztowej, do której może
stracić dostęp. Ekran logowania pokazuje obie drogi obok siebie.

### ROZSTRZYGNIĘCIE 1: LINK NIE LOGUJE OD RAZU — PROWADZI NA EKRAN Z PRZYCISKIEM

Kliknięcie w list otwiera stronę Kuking z jednym przyciskiem „Zaloguj mnie".
Dopiero ten przycisk (POST) zużywa token i tworzy sesję. Samo wejście pod
adres (GET) **niczego nie zużywa i nikogo nie loguje**.

Powód jest zmierzalny, nie estetyczny: **skanery odnośników w poczcie
otwierają linki z listów, zanim zrobi to człowiek.** Robią to Outlook Safe
Links, bramki antywirusowe operatorów i część klientów pocztowych — i robią to
metodą GET. Gdyby GET logował i kasował token jednorazowy, właściciel konta
dostawałby „ten link już nie działa" **przy pierwszym własnym kliknięciu**,
za każdym razem, bez żadnego wytłumaczenia. Ryzyko było wypisane wprost
w issue #25 z dopiskiem `[do sprawdzenia w praktyce]`; rozstrzygamy je
projektem, a nie obserwacją, bo koszt jednego kliknięcia więcej jest zerowy,
a koszt pomyłki to zamknięta droga wejścia dla całej grupy.

Przy okazji wraca zasada, którą HTTP ma od zawsze: **GET nie zmienia stanu.**
Skaner prawie nigdy nie wykonuje POST-a z tokenem CSRF.

Drugi zysk jest ludzki: człowiek **widzi, na jakie konto wchodzi**, zanim
wejdzie („zalogujesz się jako Basia, b***@wp.pl"). Z jednej skrzynki korzysta
czasem całe małżeństwo.

### ROZSTRZYGNIĘCIE 2: LINK ŻYJE 30 MINUT, NIE 15

Issue #25 proponowało 15 minut. **Odstępujemy od tego świadomie.**

Piętnaście minut to liczba z serwisów, w których człowiek siedzi przy
komputerze i czeka na list. Nasza droga wygląda inaczej i wynika wprost
z researchu: prośba idzie z komputera, a poczta jest w telefonie w drugim
pokoju. „Idź po telefon, odblokuj, znajdź list wśród czterdziestu innych,
przeczytaj, kliknij" to realnie kilkanaście minut. Link wygasający w połowie
tej drogi jest gorszy niż jego brak, bo daje komunikat o błędzie komuś, kto
zrobił wszystko dobrze — **i sam generuje ruch pocztowy**, bo ta osoba prosi
o drugi list z tej samej, skończonej puli.

Górna granica bierze się z `docs/legal/SECURITY_BASELINE.md` §3: link resetu
hasła ma żyć **maksymalnie 60 minut**. Link logujący jest **mocniejszy** od
tamtego (wchodzi na konto od razu, nie prosi o ustawienie nowego hasła), więc
jego okno nie ma prawa być dłuższe — połowa tamtego jest właściwą proporcją.

Wartość to jedna liczba w `config/kuking.php`
(`login_link.waznosc_minut`, `KUKING_LOGOWANIE_LINKIEM_WAZNOSC`).

### ROZSTRZYGNIĘCIE 3: LINK DZIAŁA NA KAŻDYM URZĄDZENIU

Link **nie jest** związany z sesją ani z przeglądarką, z której poszła prośba.
Kto otworzy go na innym telefonie, zobaczy dokładnie ten sam ekran
z przyciskiem i wejdzie na konto normalnie.

Wiązanie linku z sesją proszącego jest znaną praktyką i tutaj byłoby błędem:
**droga „poproś na komputerze, kliknij na telefonie" jest u nas drogą typową,
a nie brzegową.** Zabezpieczenie, które zamyka główną ścieżkę, nie jest
zabezpieczeniem, tylko usterką z dobrym uzasadnieniem.

Rekompensujemy to gdzie indziej: ekran przed zalogowaniem mówi, na jakie konto
wchodzi, a token żyje krótko i tylko raz.

### ROZSTRZYGNIĘCIE 4: KOMU LINKU NIE WYSYŁAMY

Nie wysyłamy go na adres bez konta, na konto **zamknięte** (zablokowane,
zgłoszone do usunięcia, wymazane) i na konto **moderatora albo administratora**
(wprost z zakresu issue #25: tam obowiązuje hasło + 2FA). Konto z 2FA link
dostaje — ale go **nie omija**, patrz niżej.

We wszystkich tych przypadkach **odpowiedź formularza jest identyczna** jak
przy wysłaniu listu. Inaczej formularz odpowiadałby na pytania „czy tu jest
konto", „czy zostało zablokowane" i „czy ta osoba jest moderatorem".

Żeby cisza nie zamieniła się w pułapkę, ekran `/logowanie/link` **mówi wprost
i dla wszystkich jednakowo**, że kont obsługi serwisu ta droga nie obejmuje.
Moderator czyta więc wyjaśnienie zamiast czekać na list, a nikt niczego się
o cudzym koncie nie dowiaduje.

### CO TA DROGA NIE OMIJA

- **2FA.** Konto z potwierdzoną weryfikacją dwuetapową po kliknięciu „Zaloguj
  mnie" trafia tam, gdzie trafia po poprawnym haśle: na `/logowanie/kod`, tą
  samą sesyjną ścieżką (`logowanie.2fa.user_id`) obsługiwaną przez
  `TwoFactorChallengeController`. **Link zastępuje hasło, nie drugi składnik.**
- **Panel moderacji.** `EnsureModeratorHasTwoFactor` zostaje nietknięty, a kont
  z rolą `moderator`/`admin` ta droga w ogóle nie dotyczy.
- **Blokadę konta.** Stan konta sprawdzamy **ponownie przy wejściu** — między
  prośbą a kliknięciem mogła zapaść decyzja moderacyjna.

### CO UNIEWAŻNIA OCZEKUJĄCY LINK

Kasowanie tokenu wisi na `User::invalidateSessions()`, czyli na tej samej
metodzie, którą wołają: zmiana hasła, reset hasła, „wyloguj mnie z innych
urządzeń", blokada, zawieszenie i zgłoszenie usunięcia konta. **Jedno miejsce,
a nie sześć wywołań do zapamiętania** — bo link e-mail jest wejściem na konto
tak samo jak sesja, a każda z tych sytuacji ma jeden powód: „ktoś inny mógł
mieć dostęp". Zostawienie wtedy ważnego linku znaczyłoby, że po zmianie hasła
napastnik dalej ma otwarte drzwi (ten sam błąd, który przy oczekującej zmianie
adresu naprawiało #195).

Do tego: **nowa prośba unieważnia poprzedni link** (`user_id` jest unikalne),
a użycie kasuje wiersz w tej samej transakcji, pod `lockForUpdate()` — bez tego
dwa równoległe kliknięcia mogłyby wpuścić dwa razy.

### TOKEN W BAZIE LEŻY WYŁĄCZNIE JAKO SKRÓT

`login_link_tokens.token_hash` to **HMAC-SHA256** (`App\Support\Skrot`, ta sama
konstrukcja co `audit_log.ip_hash` i klucze limitera). Token jawny żyje przez
jedno wywołanie akcji i wychodzi tylko do listu. CHECK w bazie wymusza kształt
skrótu (`^[0-9a-f]{64}$`), a token jest z alfabetu `Str::random()` — więc
zapisanie go wprost baza odrzuci.

Skrót **szybki**, a nie bcrypt jak w `password_reset_tokens`: bcrypt spowalnia
zgadywanie wartości o niskiej entropii (hasło człowieka), a tu wartością jest
64 losowe znaki. Za to bcrypt uniemożliwiłby wyszukanie wiersza po skrócie —
trzeba by wstawić do adresu jeszcze identyfikator wiersza, czyli wynieść do
listu jedną informację więcej bez żadnego zysku.

**Znana i przyjęta własność:** powiadomienie jest kolejkowane (`ShouldQueue`),
więc token w postaci jawnej przechodzi przez payload zadania w `jobs`, a przy
nieudanej wysyłce zostaje w `failed_jobs`. Jest to dokładnie ta sama własność
co przy resecie hasła, gdzie Laravel serializuje token tak samo. Wiersz `jobs`
żyje sekundy; token z `failed_jobs` i tak przestaje działać po 30 minutach,
a listu, którego wysyłka padła, nikt nie dostał.

### RACHUNEK LISTÓW — I CO SIĘ DZIEJE, GDY PULA PADNIE W ŚRODKU DNIA

EmailLabs na planie darmowym daje **300 listów na dobę na cały serwis**
(D-047). Z tego samego wiadra idą potwierdzenia rejestracji, przypomnienia
hasła, powiadomienia i decyzje moderacyjne.

**Ile ta funkcja realnie dołoży przy 500 kontach.** Sesja trwa 7 dni
i „zapamiętaj mnie" jest domyślne, więc jedna osoba potrzebuje nowego
logowania mniej więcej raz w tygodniu. Przy 500 kontach i 30% wracających
dziennie (150 osób) daje to około **20 logowań dziennie**; jeśli 60% z nich
wybierze link, to **12 listów**, a z powtórkami („nie doszło", „wygasł") —
**15-20 listów na dobę**. To jest 5-7% puli i nie jest problemem.

**Problemem jest tydzień migracji, nie stan ustalony.** Gdy 200 osób zakłada
konto jednego dnia (200 potwierdzeń rejestracji) i 100 z nich prosi jeszcze
tego samego dnia o link, pula 300 listów kończy się **przed wieczorem** — i to
nie przez logowanie linkiem samo w sobie, tylko przez sumę. Przy tysiącach
kont, o których mówi właściciel, plan darmowy nie wystarcza w ogóle.

Stąd **dobowy budżet listów tej jednej funkcji**: `login_link.dzienny_budzet`,
domyślnie **120** (dwie piąte puli). To nie jest limit zapytań i nie zastępuje
go: limity chronią pojedyncze konto i pojedynczy adres IP, a ten sufit chroni
**potwierdzenia rejestracji przed logowaniem linkiem**. Bez niego pierwszą
rzeczą, która przestaje działać w dniu fali, jest wejście nowych ludzi — przy
czym przyczyna siedzi kilka warstw dalej i nie widać jej znikąd.

Budżet zajmuje się **dopiero przy wysłanym liście**, nigdy przy samym wysłaniu
formularza — inaczej automat wpisujący nieistniejące adresy wyczerpałby pulę
w kilka minut, nie wysławszy ani jednego listu.

**Po wyczerpaniu budżetu nie milczymy.** Formularz mówi wprost: „dzisiaj
wysłaliśmy już wszystkie listy z linkiem, jakie mieliśmy na dziś, więc ten nie
wyjdzie — nie czekaj na niego", i odsyła do hasła oraz do człowieka pod
adresem kontaktowym. Cicha odmowa byłaby tu najgorszym możliwym zachowaniem —
to ten sam kształt awarii co `MAIL_MAILER=log`.

**CO SIĘ DZIEJE, GDY LIMIT DOSTAWCY PADNIE MIMO TO — sprawdzone w kodzie.**
`TransportEmailLabs` traktuje odmowę API jako `OdmowaEmailLabs`, czyli
`TransportException`. Zadanie w kolejce **nie czeka do jutra**: worker chodzi
z `--tries=3 --backoff=10,60,300` (`docker/entrypoint.sh`), więc ponawia po
10 s, 60 s i 300 s — łącznie **około sześciu minut** — a potem list ląduje
w `failed_jobs` i **przepada**. Odpowiedź „spróbuje jutro" jest nieprawdziwa.

Kto się o tym dowiaduje? **Człowiek — nikt.** Widział „wysłaliśmy list" i będzie
czekał. Operator dowie się tylko wtedy, gdy sam zajrzy: `php artisan
queue:failed` albo `kuking:sprawdz-poczte`, które ostrzega o niepustej tabeli
`failed_jobs`. Automatycznego powiadomienia o nieudanym liście **nie ma** —
i to jest luka szersza niż to issue (dotyczy też potwierdzeń rejestracji
i resetu hasła), więc zostaje zapisana tutaj jako znana, a nie załatana przy
okazji. Dobowy budżet jest odpowiedzią na tę lukę od strony **zapobiegania**:
skoro nie umiemy zauważyć utraconego listu, mamy nie doprowadzać do sytuacji,
w której listy zaczynają przepadać seriami.

### LIMITY

| Gdzie | Ile | Po czym liczone |
|---|---|---|
| `limits.login_link` | 5 / 60 min | adres IP (trasa `POST /logowanie/link`) |
| `login_link.limit_na_adres` | 3 / 60 min | **skrót adresu e-mail** |
| `limits.login_link_wejscie` | 10 / 10 min | adres IP (trasa `POST /logowanie/link/wejdz`) |
| `login_link.dzienny_budzet` | 120 listów / dobę | cały serwis |

Licznik po adresie e-mail rusza przy **każdym** wysłaniu formularza, także dla
adresu bez konta — inaczej samo „ten formularz mnie jeszcze nie zatrzymał"
odpowiadałoby na pytanie, czy konto istnieje. Klucz liczy się po skrócie
(`Skrot::hmac`), żeby cudzy adres nie leżał jawnie w tabeli `cache` — ta sama
lekcja co przy `App\Support\KluczeLimitow`.

### TURNSTILE — SIÓDME MIEJSCE

Formularz „wyślij mi link" dołącza do rodziny chronionej Turnstile (D-050,
D-053) jako `logowanie_linkiem`, na tych samych zasadach co
`/nie-pamietam-hasla`: brak tokenu **odrzuca**, `<noscript>` z osobnym zdaniem
o tym, czego konkretnie nie da się teraz zrobić, i osobne komunikaty dla
„nie ma tokenu" i „token zły". Bez kluczy Turnstile nic się nie renderuje
i nic nie blokuje.

### ZNANE, PRZYJĘTE RYZYKO

Dobowy budżet jest **teoretycznie** wąskim kanałem enumeracyjnym: licznik
rusza tylko przy realnie wysłanym liście, więc ktoś, kto ustawi się dokładnie
na ostatniej jednostce budżetu, może z zachowania formularza wywnioskować
jeden bit („czy tamten adres ma konto"). Wymaga to trafienia w granicę co do
jednego listu i daje najwyżej jeden bit na dobę. Alternatywa — zajmowanie
budżetu przy każdym wysłaniu formularza — otwiera **realną** blokadę usługi
za kilka złotych. Wybieramy ryzyko teoretyczne zamiast praktycznego i zapisujemy
je tutaj, zamiast udawać, że go nie ma.

### JAK TO WYŁĄCZYĆ

`KUKING_LOGOWANIE_LINKIEM=false` — jedna zmienna, restart, **bez wdrażania
migracji i bez danych do posprzątania**. Wejście z ekranu logowania znika
(martwego przycisku nie zostaje, D-053), formularz i wszystkie linki będące
w drodze odpowiadają ekranem „ta droga jest teraz zamknięta, zaloguj się
hasłem". Konta działają dalej, bo hasło nigdy nie przestało być drogą
równoległą.

Węższe zakręcenia bez wyłączania całości: `KUKING_LOGOWANIE_LINKIEM_BUDZET=0`
(dziś nie wysyłamy już nic, ale linki w drodze dalej działają),
`TURNSTILE_NA_LOGOWANIU_LINKIEM=false` (zdejmuje captchę z tego formularza).

Tabelę kasuje `php artisan migrate:rollback --step=1` i jest to bezstratne dla
kont — kolejność wycofywania: **najpierw kod, potem migracja**.

**Zmiana wymaga:** przemyślenia trzech rzeczy naraz. Skrócenie linku poniżej
30 minut wraca do problemu „telefon w drugim pokoju" i podnosi zużycie poczty;
zalogowanie od razu po GET oddaje link skanerom pocztowym; podniesienie
budżetu bez zmiany planu u dostawcy przenosi awarię na potwierdzenia
rejestracji.

📄 `app/Http/Controllers/Auth/LoginLinkController.php` ·
`app/Domain/Security/WyslijLinkDoLogowania.php` ·
`app/Domain/Security/DziennyBudzetListow.php` ·
`app/Models/LoginLinkToken.php` · `app/Models/User.php`
(`invalidateLoginLinks()`) · `app/Notifications/LinkDoLogowania.php` ·
`resources/views/mail/link-do-logowania.blade.php` ·
`resources/views/auth/login-link*.blade.php` ·
`resources/views/auth/login.blade.php` · `routes/web.php` ·
`config/kuking.php` (`login_link`, `limits.login_link*`,
`turnstile.miejsca.logowanie_linkiem`) ·
`database/migrations/2026_09_10_100000_create_login_link_tokens_table.php` ·
`tests/Feature/LogowanieLinkiemTest.php` ·
`tests/Feature/TurnstileWymagaPotwierdzeniaTest.php` ·
`docs/DATABASE.md` · `docs/legal/SECURITY_BASELINE.md` §3

## D-057 · Tygodniowe podsumowanie: dobowy sufit 60 listów, wysyłka rozłożona na dni, wypisanie bez logowania

**Data:** 10 września 2026 · Issue #11 · Status: **obowiązuje**

Tygodniowe podsumowanie od gospodarza **istnieje**. Zgoda była zbierana od
7 września (`users.wants_weekly_digest`, opt-in), ale nie było czym wysyłać —
ekran `/ustawienia/prywatnosc` mówił wprost „Tych listów jeszcze nie
wysyłamy". Teraz mówi prawdę w drugą stronę.

Jedno polecenie (`kuking:wyslij-podsumowania`), jedno zadanie w harmonogramie
(**codziennie o 08:30**, `withoutOverlapping()`), jeden wyłącznik
(`KUKING_DIGEST_WLACZONY`, domyślnie `false`).

### 1. Co jest w liście — i czego w nim NIE ma

Trzy sekcje, w tej kolejności:

1. **„Ktoś ugotował z Twojego przepisu"** — imię, nazwa potrawy, cytat
   z notatki, przycisk **„Podziękuj"** prowadzący na ekran „Komuś wyszło".
   Pierwsza, bo `AGENTS.md` §1 stawia „Ugotowałem" wyżej niż jakikolwiek lajk,
   a `docs/product/RETENTION_LOOPS.md` §1 nazywa „ktoś zwrócił się do mnie"
   najsilniejszym powodem powrotu, jaki ten produkt ma.
2. **„Nowe osoby przy Twoim gotowaniu"** — kto zaczął obserwować. Też
   osobiste, a przy tym jedyna rzecz, którą ma nowa osoba bez ani jednego
   przepisu.
3. **„Co pokazali ludzie, których obserwujesz"** — do trzech wpisów,
   chronologicznie.

Na końcu **jedno pytanie od gospodarza** z konfiguracji
(`KUKING_DIGEST_PYTANIE`) — jedyna część treści, którą właściciel zmienia co
tydzień bez wdrożenia — i podpis imieniem (`config('kuking.community.host_name')`,
D-037). Adres nadawcy jest skrzynką, na którą da się odpisać, i list mówi
o tym wprost.

**Czego nie ma i dlaczego:**

| Nie ma | Powód |
|---|---|
| jakiegokolwiek rankingu („najaktywniejsi", „najpopularniejsze", „top") | `AGENTS.md` §12 zabrania publicznych rankingów. „Najaktywniejsi w tym tygodniu" jest rankingiem, choćby był miły — każda sekcja jest chronologiczna |
| propozycji nieznajomych („osoby, które warto poznać") | To jest redakcyjny wybór gospodarza, nie coś, co wolno złożyć zapytaniem. Każde automatyczne „warto poznać" jest rankingiem pod inną nazwą |
| komentarzy pod treściami adresata | Mają już własne, natychmiastowe powiadomienie (`RETENTION_LOOPS.md` §3.1). W liście po tygodniu byłyby drugą wiadomością o tej samej rzeczy |
| **zdjęć** | `Media::url()` prowadzi na trasę `media.show`, która sprawdza uprawnienia PATRZĄCEGO — a klient pocztowy jest niezalogowany. Zdjęcia albo by się nie pokazały, albo trzeba by dla poczty poluzować dostęp do cudzych zdjęć. Pierwsze jest brzydkie, drugie jest wyciekiem. Do tego większość klientów pocztowych blokuje obrazki domyślnie, więc list i tak musi działać bez nich. **To jest odstępstwo od zakresu w issue #11** („alt teksty przy zdjęciach") — zakres zakładał, że zdjęcia będą |
| śledzenia otwarć i kliknięć | Patrz §6 |

**Pustego listu nie wysyłamy.** Gdy żadna z trzech sekcji nic nie ma, list nie
wychodzi — „lepiej nic niż e-mail o niczym" (issue #11 pkt 7). Pytanie
gospodarza **nie liczy się do treści**: jest jedno dla wszystkich i takie samo
co tydzień, więc gdyby wystarczało, serwis rozsyłałby pięciuset osobom to samo
zdanie i nazywał je podsumowaniem.

> **Odstępstwo od litery issue #11, świadome.** Kryterium akceptacji brzmiało
> „użytkownik bez zdarzeń osobistych nie dostaje pustego digestu". U nas list
> wychodzi także wtedy, gdy nie ma nic osobistego, ale **jest** coś od osób,
> które adresat obserwuje. Powód: spodziewana fala z Garnek.pl to setki osób,
> które w pierwszym tygodniu nie mają ani jednego przepisu, więc nie mogą mieć
> nic osobistego — a digest jest dla nich głównym powodem powrotu. „Halina,
> którą obserwujesz, pokazała pierogi" nie jest pustym listem. Pusty jest
> dopiero list bez żadnej z trzech sekcji i taki nie wychodzi.

### 2. Limit 300 listów na dobę — rachunek, nie życzenie

Konto EmailLabs na planie STARTUP daje **300 listów na dobę na CAŁY serwis**
(`docs/decyzje/POCZTA.md` §1). Jedno wiadro: potwierdzenia rejestracji,
przypomnienia haseł, ostrzeżenia o zmianie adresu, powiadomienia moderacyjne,
logowanie linkiem (issue #25) i to podsumowanie.

Podział wiadra (`config/kuking.php`, sekcja `poczta`):

| Funkcja | Sufit na dobę |
|---|---:|
| logowanie linkiem e-mail (issue #25) | 120 |
| **tygodniowe podsumowanie** | **60** |
| rezerwa na pocztę bez sufitu (rejestracja, hasła, moderacja) | 100 |
| zapas | 20 |
| **razem** | **300** |

**Podsumowanie bierze 60, nie 120.** Kierunek pomyłki jest wybrany świadomie:
podsumowanie, które nie doszło, jest niczym — potwierdzenie rejestracji, które
nie doszło, kończy komuś przygodę z serwisem, zanim się zaczęła. W tygodniu
fali z Garnek.pl rejestracje mają wygrać, nie biuletyn.

Sumy nikt nie policzy sam z siebie — to trzy liczby w trzech sekcjach
konfiguracji, a każdy sufit widzi tylko siebie. Dlatego rachunek jest
wykonywany w teście: **`PodzialLimituPocztyTest`**. Gdy padnie, obniża się
sufit, a nie podnosi limit dostawcy: ta liczba opisuje cudzy plan taryfowy.

### 3. Ile to naprawdę zajmie listów — 100, 500 i 2 000 kont

Zgoda jest opt-in (`DEFAULT false` od 7 września), więc pisze się tylko do
tych, którzy się zapisali. Kolumna „zapisanych" niżej to założenie o połowie
kont, a wiersz „przy pełnej zgodzie" pokazuje najgorszy przypadek.

| Kont | Zapisanych (~50%) | Listów/tydzień | Dni wysyłki przy 60/dobę | Mieści się w tygodniu? |
|---:|---:|---:|---:|---|
| 100 | ~50 | ~50 | 1 | **tak**, z dużym zapasem |
| 100 | 100 (pełna zgoda) | 100 | 2 | **tak** |
| 500 | ~250 | ~250 | 5 | **tak**, ale bez zapasu |
| 500 | 500 (pełna zgoda) | 500 | 9 | **NIE** — patrz niżej |
| 2 000 | ~1 000 | ~1 000 | 17 | **NIE** |

**Próg jest jeden i twardy: 60 × 7 = 420 listów tygodniowo.** Powyżej niego
obietnica „jeden e-mail tygodniowo" przestaje być prawdą po DRUGIEJ stronie:
część ludzi dostaje list co ósmy, dziewiąty, dziesiąty dzień. Nic się nie
psuje i nic nie krzyczy — kolejka po prostu przestaje schodzić do zera.

Dlatego komenda **zapisuje w dzienniku**, ilu ludzi zostało w kolejce po
dzisiejszej wysyłce (`OdbiorcyDigestu::ileCzeka()`). To jedyny widoczny
objaw, że plan darmowy przestał wystarczać. Wtedy przechodzi się na
**EmailLabs Essential (99–129 zł/mies. do 100 tys. listów, bez limitu
dziennego)** — `docs/decyzje/POCZTA.md` §4. Do tego czasu 500 kont z połowiczną
zgodą mieści się w pięciu dniach.

**Co się przez to traci: wspólny piątek.** `RETENTION_LOOPS.md` §4 chciał
jednej wysyłki w piątek o 17:00. Przy 60 listach dziennie „wszyscy w piątek"
kończy się na sześćdziesięciu kontach. Wybieramy obietnicę, którą da się
dotrzymać („jeden list na tydzień"), a nie tę, której nie da się („zawsze
w piątek"). Kolejność wysyłki to `weekly_digest_sent_at ASC NULLS FIRST` —
„kto czeka najdłużej, ten pierwszy" — więc dzień tygodnia ustala się dla
każdej osoby sam i potem jest stały.

### 4. Co się dzieje, gdy limit padnie w połowie wysyłki — sprawdzone, nie założone

**„Wróci do kolejki i spróbuje jutro" jest NIEPRAWDĄ.** Ustalone w kodzie:

1. odmowa EmailLabs (limit dobowy odrzuca list tak samo jak każdy inny błąd)
   kończy się wyjątkiem `OdmowaEmailLabs` z
   `App\Poczta\TransportEmailLabs::rozstrzygnij()`;
2. to wywraca zadanie w kolejce, a worker chodzi z `--tries=3
   --backoff=10,60,300` (`docker/entrypoint.sh`);
3. trzy próby mieszczą się więc w **sześciu minutach od pierwszej** — czyli
   wszystkie tej samej doby, wszystkie ponad limitem, wszystkie odrzucone;
4. czwartej nie ma. List ląduje w `failed_jobs` i **przepada**.

**Kto się o tym dowie: nikt sam z siebie.** Adresat — nigdy. Sentry nie ma
(D-041: błędy 500 idą webhookiem, ale nieudane zadania kolejki nie idą
nikąd). Jedyne miejsce, które w ogóle liczy `failed_jobs`, to
`kuking:sprawdz-poczte`, uruchamiane ręcznie. Przekroczenie limitu w środku
wysyłki **cicho zjadłoby część biuletynów**.

Dlatego sufit działa **przed** wstawieniem listu do kolejki, po naszej
stronie, a nie „wyślijmy i zobaczmy, co odbije". Odrzucenie przez dostawcę
jest wtedy awarią, a nie normalnym trybem pracy.

Sufitu pilnuje `App\Domain\Security\DziennyBudzetListow` — **ta sama klasa co
przy logowaniu linkiem**, z własnym kluczem licznika i własnym kluczem
konfiguracji. Dwa niezależne liczniki jednego wiadra rozjechałyby się przy
pierwszej zmianie którejkolwiek liczby, a rozjazd dwóch kopii tej samej
reguły jest w tym repozytorium usterką, nie niedogodnością (ta sama lekcja co
martwy wpis `limits.upload` i `kuking.media_disk`).

Różnica wobec logowania linkiem: tam miejsce w budżecie zajmuje się **po**
udanej wysyłce, żeby automat z fałszywymi adresami nie wyczerpał puli
formularzem. Tutaj zajmuje się **przy wstawieniu do kolejki**, bo listę
odbiorców składa harmonogram z kont, które mają zgodę i potwierdzony adres —
nie ma tu nikogo, kto mógłby zalać formularz, a jest ryzyko odwrotne: sześćdziesiąt
listów w kolejce, z których część przepadnie po cichu.

**Tempo.** Listy wychodzą rozsunięte o `KUKING_DIGEST_ODSTEP_SEKUND`
(domyślnie 20 s), czyli cała paczka schodzi w około dwadzieścia minut. Sto
wiadomości w jednej minucie jest samo w sobie sygnałem spamowym
(`docs/decyzje/POCZTA.md` §5 pkt 5).

### 5. Zgoda i wypisanie się

To jest poczta **produktowa, nie transakcyjna** — podstawą jest zgoda
(art. 6 ust. 1 lit. a RODO), nie wykonanie umowy. Stąd cztery rzeczy:

1. **Ustawienie zostaje na `/ustawienia/prywatnosc`**, tam gdzie już było.
   Osobnego `/ustawienia/powiadomienia` **nie zakładamy** — sprawdzone, taki
   ekran nie istnieje, a zakładanie go w tym samym tygodniu, w którym trzy
   inne gałęzie dotykają ustawień i tekstów interfejsu, byłoby dokładaniem
   kolizji do funkcji, która i tak działa. Sam tekst pola wyboru zmieniony:
   przestał obiecywać listy, których nie ma, i zaczął mówić, co w nich będzie.
2. **Odnośnik wypisania w KAŻDYM liście** — w wersji HTML, w wersji tekstowej
   (pełnym adresem, bo w zwykłym tekście nie ma czego kliknąć poza tym, co
   widać) oraz w nagłówkach `List-Unsubscribe` i `List-Unsubscribe-Post`
   (RFC 8058), którymi Gmail i Outlook pokazują własny przycisk przy nadawcy.
3. **Wypisanie działa BEZ LOGOWANIA i jednym kliknięciem.** Autoryzacją jest
   **podpis** (`URL::signedRoute`), nie identyfikator w adresie — `AGENTS.md`
   §7 („UUID w adresie NIE JEST autoryzacją") zostaje w mocy. Bez daty
   ważności, w odróżnieniu od paczki z danymi: odnośnik ma działać także
   w liście sprzed pół roku, wyciągniętym z archiwum skrzynki, bo dokładnie
   wtedy ktoś się rozmyśla. Wygasający odnośnik wypisania mówiłby wtedy „nie
   da się wypisać".
4. **Bez ankiety „dlaczego"** (issue #11 pkt 6).

Powód nie jest tylko uprzejmościowy. Człowiek, który nie pamięta hasła,
zamiast wypisać się klika w skrzynce „to jest spam" — a to psuje
dostarczalność **całej** poczty Kuking, łącznie z resetami haseł
(`docs/decyzje/POCZTA.md` §3). Wyjście musi być łatwiejsze niż donos.

**Trasa działa na `GET` i to jest wybór, nie przeoczenie.** Skanery odnośników
w firmowej poczcie otwierają linki z treści, więc `GET` potrafi kogoś wypisać
bez jego wiedzy. Ekran po wypisaniu ma dlatego przycisk powrotny — jeden,
duży, na tej samej stronie — żeby naprawa też była jednym kliknięciem.
Odwrotna kolejność (najpierw zapytaj, potem wypisz) byłaby wyborem, w którym
pomyłka skanera kosztuje mniej, a pomyłka człowieka więcej.

`podsumowanie/wypisz/*` jest **drugim i jedynym poza `_csp`** adresem wyjętym
spod ochrony CSRF, bo `POST` z nagłówka `List-Unsubscribe-Post` wysyła klient
pocztowy, który tokenu nie ma skąd wziąć. Ochroną tej trasy jest podpis.
Droga powrotna (`podsumowanie/wracam/*`) **świadomie** pod CSRF zostaje — tam
klika człowiek na naszej stronie, a bez tokenu byłaby drogą do zapisania
kogoś z powrotem.

**Polityka prywatności** dostała osobny wiersz w §2: co wysyłamy, na jakiej
podstawie, jak zgodę wycofać i że nie sprawdzamy otwarć ani kliknięć.

### 6. Zdarzenia analityczne: dwa z czterech

Issue #11 wymieniało cztery: wysłany, otwarty, kliknięty, wypisany. Wdrożone
są **`weekly_digest_queued` i `weekly_digest_unsubscribed`** (`product_signals`,
zbiór nazw rozszerzony migracją, nie zdjęciem CHECK-a). Pierwszy nazywał się
do 10 września `weekly_digest_sent` — przemianowany przy **D-078**, bo
powstaje zaraz po `Mail::queue()` i nie wie nic o doręczeniu.

„Otwarty" wymaga niewidzialnego obrazka śledzącego w treści listu,
„kliknięty" — podmiany każdego odnośnika na przekierowanie przez nasz serwer.
Obie techniki zapisują, kiedy konkretna osoba czytała pocztę i z jakiego
adresu IP. Polityka prywatności obiecuje czegoś takiego nie robić, a własny
transport ma nawet wyłącznik śledzenia po stronie dostawcy
(`X-TRACKING-OFF`) — **domyślnie włączony**. Dokładanie własnego śledzenia
byłoby cofnięciem tamtej decyzji tylnymi drzwiami.

Do jedynego progu, po którym cokolwiek robimy — **„wypisy > 1% na wysyłkę"**,
`RETENTION_LOOPS.md` §6 wiersz 5 — wystarczy wiedzieć, ile listów wyszło
i ile osób się wypisało. Otwarcia byłyby miłe, ale nie są progiem.

`properties` niosą **wyłącznie liczby** (ile pozycji miała każda sekcja) —
żadnego adresu, żadnych nazw, żadnych tytułów (AGENTS.md §7).

### 7. Godzina: 08:30, nie piątek 17:00

- **Po 8:00**, czyli po ciszy nocnej z `RETENTION_LOOPS.md` §3.2. List
  przychodzący w nocy jest rano jednym z wielu, a przy telefonie na szafce
  nocnej bywa też budzikiem.
- **Rano, nie o 17:00.** Tamta godzina jest dobra dla kogoś, kto wychodzi
  z biura i planuje weekend. Nasza grupa czyta pocztę przy porannej kawie,
  a o 17:00 jest w kuchni — czyli robi dokładnie to, o czym ten list
  opowiada, i nie patrzy wtedy w telefon.
- **Nie równo o pełnej godzinie:** o 08:00 tyka `kuking:zdejmij-wygasle-kary`
  (`hourly()` = minuta 00 każdej godziny). Cała lista zadań jest świadomie
  porozsuwana — patrz komentarz przy sprzątaniu zmian adresu.
- **Daleko od nocnego bloku sprzątania** (03:20–04:50).

### 8. Jak to wyłączyć

`KUKING_DIGEST_WLACZONY=false`. Jedna zmienna, bez wdrożenia, bez migracji,
bez ruszania harmonogramu — zadanie dalej chodzi i po prostu nic nie robi.
**Domyślnie jest wyłączone** i to nie jest ostrożność na zapas: digest to
jedyna poczta w tym serwisie wychodząca bez czynności człowieka bezpośrednio
przed wysyłką, więc pomyłka w danych albo w treści rozchodzi się od razu do
wszystkich zapisanych i nie da się jej cofnąć. Włącza się ją po sprawdzeniu
listu na własnej skrzynce:

```bash
php artisan kuking:wyslij-podsumowania --na-sucho
php artisan kuking:wyslij-podsumowania --tylko=woogitsu
```

### 9. Zmiana wymaga

Przemyślenia obu połówek naraz. Podniesienie `digest.dzienny_limit` bez
obniżenia innego sufitu przewraca rachunek z §2 i pierwszą rzeczą, która
przestaje działać, jest potwierdzenie rejestracji. Postawienie znacznika
`weekly_digest_sent_at` po doręczeniu zamiast przy kolejkowaniu psuje
obietnicę „jeden list w tygodniu" w dniu, w którym kolejka się zatka.
Usunięcie warunku o pustym liście zamienia digest z powodu powrotu w powód
do wypisania się.

Pilnują tego: `TygodniowePodsumowanieTest`, `WypisanieZPodsumowaniaTest`,
`PodsumowanieSzanujePrywatnoscTest`, `PodsumowanieBezWachlarzaZapytanTest`,
`PodzialLimituPocztyTest`, `ObietnicaTygodniowegoMailaTest`.

📄 `app/Console/Commands/WyslijPodsumowaniaTygodnia.php` ·
`app/Domain/Digest/OdbiorcyDigestu.php` ·
`app/Domain/Digest/ZbierzTresciDigestu.php` ·
`app/Domain/Digest/TrescDigestu.php` ·
`app/Domain/Digest/OdnosnikWypisania.php` ·
`app/Domain/Security/DziennyBudzetListow.php` ·
`app/Mail/PodsumowanieTygodnia.php` ·
`app/Http/Controllers/PodsumowanieTygodniaController.php` ·
`resources/views/mail/podsumowanie-tygodnia.blade.php` (+ `-tekst`) ·
`routes/console.php` · `routes/web.php` · `config/kuking.php` (`poczta`, `digest`) ·
`docs/DATABASE.md` (`users.weekly_digest_sent_at`, `product_signals`) ·
`resources/legal/polityka-prywatnosci.md` §2

---

## D-058 · Na wiadomość z „Napisz do nas" odpisuje się Z PANELU, synchronicznie, ze stanem wysyłki przy każdym liście

**Data:** 10 września 2026 · **Zgłoszenie właściciela** · Status: **obowiązuje**

Zgłoszenie brzmiało dosłownie: *„Wiadomości do nas — widzę je, przychodzą,
ale jak mam odpisać? Nie ma nigdzie funkcji »odpisz osobie«, tylko notatka
dla siebie."* I tak było: ekran `/admin/wiadomosci/{id}` miał stan (Nowa /
W trakcie / Załatwiona), notatkę wewnętrzną i odnośnik `mailto:`. Odpisywało
się więc z własnego programu poczty, a w serwisie nie zostawał ŻADEN ślad,
że odpowiedź poszła — poza zdaniem, które moderator sam sobie zapisał.

Od teraz na karcie wiadomości jest pole „Treść odpowiedzi" i przycisk
„Wyślij odpowiedź". List wychodzi pocztą serwisu, a jego treść i stan
wysyłki zostają przy wiadomości, widoczne po odświeżeniu.

### WYSYŁKA JEST SYNCHRONICZNA — TO NAJWAŻNIEJSZA DECYZJA W TYM WPISIE

Każdy inny list w Kuking idzie kolejką i słusznie: nikt nie czeka przed
ekranem na powiadomienie. Ten jeden czeka, i to nie jest niekonsekwencja.

Issue #234 ustaliło, co dzieje się z listem, którego EmailLabs nie przyjmie:
`TransportException`, `--tries=3`, po ~6 minutach wiersz w `failed_jobs`
i **cisza**. Przy powiadomieniu to zła, ale znośna cena. Przy odpowiedzi na
wiadomość od człowieka cena jest inna i nie do przyjęcia: moderator kliknąłby
„Wyślij", zobaczył „wysłano", oznaczył sprawę jako załatwioną i przeszedł do
następnej — a osoba po drugiej stronie nigdy nie dostałaby odpowiedzi i nikt
by o tym nie wiedział. Kolejka zamieniłaby więc jedną cichą awarię (brak
funkcji „odpisz") w drugą, gorszą, bo z fałszywym potwierdzeniem.

Zamiast tego:

```text
1. zapis wiersza odpowiedzi ze stanem „wysyłka w toku"   ← PRZED wysyłką
2. wysyłka w tym samym żądaniu HTTP
3. zapis PRAWDZIWEGO wyniku: „wysłana" + godzina  albo  „nie udało się" + powód
4. wpis w `audit_log` — przy obu wynikach
```

Krok 1 jest przed krokiem 2 świadomie. Gdyby wiersz powstawał po udanej
wysyłce, przerwanie procesu (koniec limitu czasu PHP, restart kontenera na
Railway) zostawiłoby list w drodze i ZERO śladu w serwisie — moderator
napisałby to samo drugi raz. Przy dzisiejszej kolejności ten sam wypadek
zostawia na ekranie zdanie „Nie wiadomo, czy ten list wyszedł", czyli prawdę.

Koszt: żądanie trwa tyle, ile odpowiedź API EmailLabs
(`services.emaillabs.limit_czasu`). Płaci go jedna osoba, kilka razy dziennie,
i to ona ten koszt wybrała.

### `mailto:` ZOSTAJE — ALE JAKO DROGA AWARYJNA, NAZWANA PO IMIENIU

Rozważona alternatywa: **wyrzucić `mailto:` całkowicie**, bo dwie drogi to
zaproszenie do rozjazdu („odpisałem z Gmaila i zapomniałem odhaczyć").
Argument jest prawdziwy, ale przegrywa z jednym scenariuszem: gdy poczta
serwisu nie działa, wyrzucenie `mailto:` znaczy, że **nie da się odpisać
w ogóle** — a wiadomości, które w takim momencie przychodzą, to bardzo często
„nie dostałem od was maila". Zabranie drogi awaryjnej dokładnie wtedy, kiedy
jest potrzebna, jest gorsze niż ryzyko rozjazdu. Drugi taki scenariusz:
odpowiedź wymagająca załącznika (zrzut ekranu, plik z danymi) — tego formularz
w panelu świadomie nie umie.

Rozjazd ograniczamy inaczej, kosztem trzech linii w widoku: `mailto:` **nie
stoi już obok adresu jako główna droga**, tylko w zwiniętym bloku
`<details>` pod formularzem, podpisanym „Poczta nie działa albo trzeba wysłać
załącznik", z jednym zdaniem: *„zapisz w notatce, co odpisałeś — bo tej drogi
serwis nie widzi"*. Domyślna droga jest jedna i jest nią formularz.

### JEDNA ODPOWIEDŹ CZY WĄTEK: **WIELE ODPOWIEDZI, ALE NIE WĄTEK**

Trzy możliwości i granica przebiega między drugą a trzecią:

1. **Jedna odpowiedź na wiadomość** (trzy kolumny w `contact_messages`) —
   ODRZUCONE. Moderator pisze „sprawdzamy" i dwa dni później „naprawione";
   to są dwa listy, oba wysłane. Kolumna kazałaby drugi albo nadpisać
   (znika ślad tego, co naprawdę wyszło — czyli dokładnie to, czego ten
   ekran ma zacząć pilnować), albo uniemożliwić. Twardszy powód: **każdy list
   ma własny stan wysyłki**, a jedna kolumna `status` nie ma jak opowiedzieć
   „pierwszy nie wyszedł, drugi wyszedł".
2. **Wiele odpowiedzi wychodzących, każda z własnym stanem** (osobna tabela
   `contact_message_replies`) — WYBRANE.
3. **Pełny wątek z odpowiedziami człowieka** — ODRZUCONE i to jest granica
   tej zmiany. Kuking **nie odbiera poczty**: nie ma ani webhooka
   przychodzącego, ani IMAP-a, ani skrzynki, do której serwis by zaglądał.
   Odpowiedź człowieka na nasz list wraca na `kontakt@kuking.pl` — i wraca
   tam CELOWO, bo `Reply-To` wskazuje właśnie tę skrzynkę. Wątek w panelu
   wymagałby odbierania poczty, czyli osobnej funkcji z własnym ryzykiem
   (parsowanie cudzych listów, załączniki, spam) i bez zmierzonej potrzeby
   przy kilku wiadomościach dziennie.

Panel pokazuje więc **to, co wyszło Z NIEGO**, w kolejności wysyłania, i nie
udaje pełnej korespondencji. To ograniczenie jest napisane na ekranie wprost,
a nie zostawione do odkrycia: *„Odpowiedź tej osoby wróci na
kontakt@kuking.pl — nie na ten ekran, bo Kuking poczty nie odbiera"*.

### NADAWCĄ JEST SERWIS, `Reply-To` PROWADZI TAM, GDZIE KTOŚ CZYTA

```text
From:     kontakt@kuking.pl  (config('mail.from'))       ← serwis
Reply-To: kontakt@kuking.pl  (kuking.community.contact_email)
```

Prywatny adres moderatora nie wychodzi na zewnątrz ani w `From`, ani
w `Reply-To`. Trzy powody: osoba pisała do serwisu i odpowiedź ma przyjść od
serwisu; moderator ma prawo do własnej skrzynki bez cudzej korespondencji;
lista moderatorów nie jest informacją publiczną.

`Reply-To` ustawiamy JAWNIE, choć dziś to ten sam adres co `From` — bo te
dwie wartości są w konfiguracji niezależne (`MAIL_FROM_ADDRESS`
i `KUKING_CONTACT_EMAIL`), a pierwszego dnia, w którym ktoś ustawi nadawcę na
adres techniczny, `Reply-To` będzie tym, co decyduje, czy odpowiedź człowieka
dotrze do skrzynki, którą ktokolwiek otwiera.

### LIMIT 300 LISTÓW DZIENNIE: **SPRAWDZONE — WŁASNEGO SUFITU NIE POTRZEBUJE**

Sprawdzone, nie założone. Stan faktyczny na dziś: **wspólnego licznika całej
poczty w repozytorium nie ma** — mówi to o sobie wprost
`App\Domain\Security\DziennyBudzetListow`, jedyny sufit dzienny w kodzie,
należący do **jednej** funkcji (logowanie linkiem, D-056,
`login_link.dzienny_budzet` = 120). Reszta puli EmailLabs (300/dobę na planie
darmowym, dzielone z potwierdzeniami rejestracji, przypomnieniami hasła
i alarmami moderacyjnymi) jest pilnowana **projektowo**: listy natychmiastowe
tylko dla kategorii pilnych, resztę zbiera jedno podsumowanie na dobę
(`PilnyAlarmModeracyjny`, `kuking:podsumowanie-automatu`).

**Dlaczego odpowiedzi nie potrzebują tego, co potrzebowało logowanie
linkiem.** Tamten sufit powstał, bo prośbę o list wywołuje ktokolwiek
z zewnątrz, a pięciuset ludzi zachowujących się zupełnie normalnie zjada
dobową pulę, nie przekraczając żadnego limitu. Tutaj list wywołuje jedna
osoba, po zalogowaniu, z obowiązkowym 2FA, pisząc treść własnymi słowami —
fan-outu nie ma z czego zrobić. Odpowiedzi to garść listów dziennie, czyli
**poniżej 2% puli**.

Własny sufit dzienny nic by więc nie chronił, a zrobiłby rzecz szkodliwą:
odmówiłby wysłania odpowiedzi człowiekowi, który już czeka, w imieniu
budżetu, którego nikt nie mierzy. Gdyby kiedyś powstał prawdziwy, **wspólny**
licznik poczty (np. razem z tygodniowym digestem), **to on** ma być jednym
miejscem tej decyzji.

Co ZOSTAŁO zrobione zamiast sufitu: **własny klucz limitu zapytań**
`limits.kontakt_odpowiedz` = **20 na 10 minut**, a nie wspólny `moderacja`
(120/10). Tamten limit jest świadomie najwyższy w serwisie, bo jego skutkiem
jest wiersz w bazie; tutaj skutkiem jest list wysłany do człowieka i zjedzony
budżet poczty. Sesja moderatora użyta maszynowo pod limitem `moderacja`
wypaliłaby połowę dobowej puli w dziesięć minut i zabrała ludziom możliwość
odzyskania hasła. Dwadzieścia to wielokrotność tego, co człowiek zdąży
napisać, i szósta część tego, co mogłaby wysłać przejęta sesja.

### DANE OSOBOWE: CO DOKŁADNIE ZOSTAJE W BAZIE

- **Treść odpowiedzi** — w `contact_message_replies.body`, z kaskadą
  `ON DELETE CASCADE` na wiadomość. Znika więc **razem z wiadomością**, czyli
  12 miesięcy od jej załatwienia (D-045). Kaskada jest W BAZIE, nie
  w modelu, bo retencja robi masowy `DELETE` i modeli nie dotyka.
- **Adresu, na który list poszedł, NIE ZAPISUJEMY.** Jest już w bazie raz —
  `contact_messages.contact_email` (gość) albo `users.email` (konto).
  Trzecia kopia tej samej danej przeżywałaby anonimizację konta
  (`EraseAccountData` anonimizuje, nie kasuje) i zamieniłaby wiersz techniczny
  w mały, niezależny zbiór adresów e-mail.
- **Powód odmowy** (`error`) przechodzi przez redakcję adresów — ta sama
  lekcja co audyt A6-01: komunikat od cudzej strony niesie dane, których
  autor kodu tam nie włożył (Symfony wypisuje odrzucony adres wprost).
- **`audit_log`** dostaje `admin.contact_reply_sent` albo
  `admin.contact_reply_failed`: aktor, wiadomość jako podmiot i `reply_id`
  w metadanych. Bez treści i bez adresu — dziennik zapisuje FAKT i AKTORA
  (poz. 3.2 z `docs/INSPIRATION_DECISIONS.md`). Nieudana próba też zostawia
  ślad, bo „ktoś próbował odpisać i nie wyszło" jest odpowiedzią na pytanie,
  które kiedyś padnie.
- **Polityka prywatności** dostała o tym jedno zdanie w wierszu o „Napisz do
  nas". Dokument nie może milczeć o danych, które trzymamy.

### CZEGO W LIŚCIE NIE MA: CYTATU ORYGINALNEJ WIADOMOŚCI

Standardowe w każdym systemie zgłoszeń, a tu ryzykowne: adresu gościa nikt nie
weryfikuje — wpisuje się go ręcznie i można się pomylić o literę. Cytat
znaczyłby, że pod obcy adres idzie zdanie w rodzaju „nie mogę się zalogować,
mieszkam z siostrą, która ma to samo nazwisko". Wysyłamy więc datę i rodzaj
wiadomości — tyle, żeby człowiek rozpoznał własną sprawę.

### WYSŁANIE ODPOWIEDZI NIE ZMIENIA STANU WIADOMOŚCI

„Odpisałem" nie znaczy „załatwione": odpowiedź bywa pytaniem dodatkowym, po
którym sprawa jest bardziej otwarta niż przedtem. Automatyczne przestawienie
na „Załatwiona" ruszyłoby przy okazji `handled_at`, czyli **zegar retencji**
(D-045), dla sprawy, której nikt nie zamknął. Ekran mówi to wprost, przy
wyborze stanu.

### CO JESZCZE NAPRAWIŁ TEN PR

`ContactMessage::adresDoOdpowiedzi()` oddawało adres konta **wymazanego**
(`usuniete+<uuid>@konto.kuking.pl` — adres z naszej domeny technicznej, bez
skrzynki). Dopóki panel tylko pokazywał `mailto:`, było to niedogodnością.
Odkąd naprawdę wysyła listy, byłaby to wysyłka w próżnię z zielonym
„wysłano" — więc konto wymazane nie ma teraz adresu do odpowiedzi i ekran
mówi, że nie da się odpisać.

### WYCOFANIE

1. **Wyłączenie bez wdrożenia** — nie ma przełącznika i celowo: pole
   odpowiedzi albo istnieje, albo nie. Najbliższą rzeczą jest zdjęcie trasy
   `admin.contact.reply`; formularz przestaje się wtedy renderować
   (`route()` rzuci), więc to nie jest droga produkcyjna.
2. **Wycofanie kodu:** rewert commita, potem
   `php artisan migrate:rollback --step=1` (kolejność ma znaczenie:
   `contact_messages` nie da się cofnąć, dopóki stoi tabela odpowiedzi).
   Znika formularz, wraca `mailto:` jako droga główna, wiadomości zostają.
3. **Przed cofnięciem migracji na produkcji:**
   `pg_dump --data-only --table=contact_message_replies > odpowiedzi.sql` —
   w tabeli leżą listy, które naprawdę poszły do ludzi.
4. Przy trwałym wycofaniu trzeba zdjąć zdanie o zapisywaniu odpowiedzi
   z polityki prywatności — dokument nie może opisywać danych, których nie ma.

**Zmiana wymaga:** przy „wątku" — funkcji odbierania poczty, nie samego
pomysłu. Przy wysyłce w kolejce — mechanizmu, który pokazuje moderatorowi
PRAWDZIWY wynik wysyłki po fakcie (dzisiejsze `failed_jobs` nim nie jest).
Przy sufitcie dziennym — prawdziwego, wspólnego licznika poczty.

📄 `app/Http/Controllers/Admin/WiadomosciController.php` ·
`app/Domain/Contact/Actions/WyslijOdpowiedz.php` ·
`app/Domain/Contact/Actions/BrakAdresuDoOdpowiedzi.php` ·
`app/Mail/OdpowiedzNaWiadomosc.php` ·
`resources/views/mail/odpowiedz-na-wiadomosc.blade.php` ·
`app/Models/ContactMessageReply.php` · `app/Models/ContactMessage.php` ·
`app/Policies/ContactMessagePolicy.php` · `app/Support/OdzyskiwalneDane.php` ·
`database/migrations/2026_09_10_200000_create_contact_message_replies_table.php` ·
`resources/views/pages/admin/wiadomosc.blade.php` · `routes/web.php` ·
`config/kuking.php` (`limits.kontakt_odpowiedz`) ·
`resources/legal/polityka-prywatnosci.md` §2 ·
`docs/DATABASE.md` (`contact_message_replies`) ·
`tests/Feature/OdpowiedzNaWiadomoscDoNasTest.php` ·
`tests/Feature/CofniecieMigracjiWiadomosciTest.php`

---

## D-059 · Newslettera redakcyjnego nie budujemy — tygodniowe podsumowanie (D-057) jest odpowiedzią na to pytanie

**Data:** 10 września 2026 · Research: `docs/research/NEWSLETTER.md` (#241) · Status: **obowiązuje**

Właściciel pytał, czy da się wysyłać cotygodniowy newsletter z wyróżnionymi
przepisami — czy pozwala na to polityka prywatności i EmailLabs. Research
odpowiedział: prawnie da się, ale wymaga **osobnej zgody marketingowej**
(art. 398 Prawa komunikacji elektronicznej), a darmowy budżet poczty
(300 listów/dobę) jest już w całości rozdysponowany, więc newsletter kosztuje
od pierwszego dnia. Po przeczytaniu właściciel zdecydował, dosłownie:
*„z tym newsletterem to odpuszczamy w tej formie, którą ja pisałem. Rób jak
zrobiłeś"*.

Czyli:

1. **Nie powstaje** drugi kanał pocztowy, druga zgoda ani ekran zapisu na
   newsletter. `users` nie dostaje kolumny `wants_newsletter`.
2. **Zostaje to, co jest** — tygodniowe podsumowanie od gospodarza (D-057):
   opt-in, spersonalizowane, wysyłane tylko do osób, które je włączyły, na
   podstawie zgody, którą już mamy udokumentowaną.
3. **Wyróżnianie przepisów, jeśli wróci, idzie na stronę, nie w pocztę** —
   cotygodniowa kolekcja redakcyjna (Pętla 4 z `docs/product/RETENTION_LOOPS.md`),
   linkowana z istniejącego podsumowania. Bez rankingu (AGENTS.md §12), bez
   nowego kanału i bez nowej zgody.

### Dlaczego to jest zapisane, choć „nic nie robimy"

Bo pytanie wróci — newsletter jest pierwszą rzeczą, którą się proponuje przy
rozmowie o wzroście. Bez tego wpisu ktoś (agent albo właściciel po pół roku)
zacznie research od zera i skończy na tej samej odpowiedzi, albo — gorzej —
dobuduje kanał, którego prawnej podstawy nikt nie sprawdził.

**Warunek powrotu do tematu:** zgoda prawnika na brzmienie zgody
marketingowej (issue #8), płatny plan poczty i jawna zgoda właściciela, że
redakcja to trwały, cotygodniowy obowiązek człowieka. Sam research zostaje
w `docs/research/NEWSLETTER.md` — nie trzeba go robić drugi raz.

**Pliki:** `docs/research/NEWSLETTER.md` (nagłówek stanu) · ten wpis.
Kodu ta decyzja nie zmienia — to jej cały sens.

---

## D-060 · Kolejka z terminem sama się zgłasza: powiadomienie dla administratora i liczniki przy pozycjach panelu liczone poza żądaniem

**Data:** 10 września 2026 · **Zgłoszenie właściciela** · Status: **obowiązuje**

Właściciel przeszedł pierwszy raz całą ścieżkę moderacyjną na produkcji: ukrył
treść, dostał jako użytkownik powiadomienie o decyzji, złożył odwołanie —
i wtedy: *„Odwołanie jest w »Odwołania«, ale nie mam jako admin/moderator
powiadomienia, że jakieś odwołanie jest, i w »Odwołania« nie ma takiego
kwadracika jak przy Powiadomieniach, że np. są 2 nieodczytane odwołania. Plus
ten panel jest nieczytelny, zlewa się cały tekst"*.

### 1. ODWOŁANIE POWIADAMIA ADMINISTRATORA, NIE WSZYSTKICH MODERATORÓW

Odwołanie ma **termin odpowiedzi** (`Appeal::responseDeadline()` — siedem dni
roboczych z `docs/legal/MODERATION_PLAYBOOK.md` §3, obiecane człowiekowi
w każdym szablonie decyzji i wypisane na ekranie kolejki). Kolejka, o której
nikt nie wie, że coś w niej leży, to termin, który upływa po cichu — a to jest
zobowiązanie z DSA art. 20 i z regulaminu §8, nie uprzejmość.

Nowy typ powiadomienia w serwisie (`Notification::TYPE_APPEAL_FILED`) idzie
**wyłącznie do kont z rolą `admin`**, bo tylko one mogą sprawę zamknąć
(`UserPolicy::resolveAppeals()`, **D-039**). Moderator kolejkę widzi, ale nie
rozstrzyga — powiadomienie dla niego byłoby wezwaniem do czynności, której nie
może wykonać, a to najkrótsza droga do tego, żeby ludzie przestali czytać
powiadomienia z panelu w ogóle. Moderator dostaje zamiast tego licznik przy
pozycji „Odwołania" w menu, na każdej stronie panelu.

Powiadomienie powstaje **bez `actor`**, choć osobę składającą odwołanie znamy.
`NotifyUser` odmawia utworzenia powiadomienia, gdy między nadawcą a odbiorcą
jest blokada — i tutaj byłoby to dziurą: wystarczyłoby zablokować konto
administratora, żeby zawiadomienie o własnym odwołaniu nigdy nie powstało,
a termin płynął dalej. Ta sama decyzja obsługuje odwołanie zgłaszającego,
który konta nie musi mieć wcale (art. 16 ust. 2 lit. c).

### 1a. POCZTA: NIE NA KAŻDE ODWOŁANIE, ALE TAK NA TERMIN, KTÓRY ZARAZ MINIE

**Listu na każde odwołanie nie ma i mieć nie będzie.** EmailLabs daje 300
listów na dobę na CAŁY serwis (**D-047**), z tego samego wiadra co
potwierdzenia rejestracji, a jego jawny podział (**D-057**,
`config/kuking.php` → `poczta`) zostawia dziś 20 listów zapasu. Termin to
siedem DNI ROBOCZYCH, nie godzin, więc list wysłany w sekundzie złożenia
odwołania nie kupuje nic, czego nie kupuje powiadomienie w panelu z licznikiem
widocznym na każdym ekranie — a przy fali migracyjnej z masową moderacją
potrafiłby konkurować o wiadro z rejestracjami. Sufit na taki list byłby
w dodatku gorszy niż jego brak: odcinałby dokładnie te zawiadomienia
o terminie prawnym, które miał chronić.

**List wychodzi natomiast wtedy, gdy termin jest BLISKO albo już MINĄŁ, a
sprawy nikt nie zamknął** — czyli wtedy, gdy powiadomienie w serwisie właśnie
zawiodło, bo nikt do panelu nie zajrzał. `kuking:pilnuj-terminow-odwolan`
(codziennie 07:10) wysyła **najwyżej jeden list na dobę**, na jeden adres
(`moderation.model.alarm_email`), i tylko w dniach, w których naprawdę coś
wisi. Próg: `moderation.appeal_reminder_working_days` (2 dni robocze).

**Jak to się wpisuje w podział wiadra:** bez własnego sufitu, wewnątrz
**rezerwy transakcyjnej** — razem z dobowym podsumowaniem kolejki automatu
(**D-055**) to najwyżej 2 listy z tych 100. Sufit jest narzędziem na funkcje,
które wysyłają wiele listów naraz (logowanie linkiem, digest), a nie na te,
które wysyłają jeden. Rachunek jest dopisany wprost w komentarzu sekcji
`poczta` w `config/kuking.php`, żeby nie stał w drugim miejscu obok tamtego.

List nie niesie treści odwołania ani nazw ludzi — tylko ile spraw wisi i do
kiedy. Ten sam powód co przy `PilnyAlarmModeracyjny`: poczta leży potem
w cudzej skrzynce, a przeczytać sprawę trzeba w panelu, za logowaniem i 2FA.

### 2. LICZNIKI PRZY POZYCJACH PANELU — ZERO `COUNT(*)` NA ODSŁONĘ

Pięć kolejek („Bez odpowiedzi", „Zgłoszenia", „Sygnały automatu",
„Odwołania", „Wiadomości do nas") dostaje plakietkę z liczbą tego, co czeka.
Menu stoi na KAŻDEJ stronie panelu, więc pięć `COUNT(*)` w widoku byłoby pięcioma
zapytaniami na każdą odsłonę — najdroższymi dokładnie wtedy, gdy kolejki są
pełne. Rozwiązanie jest wzięte w całości z licznika społeczności w stopce
(`LiczbaKukingow`, issue #38): przeliczanie schodzi poza ścieżkę żądania,
a widok tylko czyta gotową wartość.

- **jeden wpis w cache na wszystkie pięć liczb**, nie pięć wpisów: produkcja
  chodzi na `CACHE_STORE=database`, więc pięć kluczy zamieniłoby pięć
  `COUNT(*)` na pięć `SELECT`-ów i nie rozwiązałoby niczego;
- **świeżość ze zdarzeń modeli** `Appeal`, `Report`, `ContactMessage`
  (`AppServiceProvider`) — te trzy tabele zmieniają się kilka razy na dobę,
  a licznik pokazujący „1" po zamknięciu ostatniej sprawy kłamie raz i traci
  zaufanie na zawsze;
- **`Post` i `Comment` haka nie mają** — publikacja wpisu i komentarz to
  główna akcja produktu (AGENTS.md §1) i nie dokładamy do niej zapytań po to,
  żeby licznik miękkiej kolejki „Bez odpowiedzi" (progi 6 i 24 godziny) był
  świeży co do sekundy. Tę liczbę odświeża harmonogram co pięć minut
  (`kuking:policz-kolejki`), który jest jednocześnie siatką bezpieczeństwa na
  świeże wdrożenie z pustym cache;
- **zero nie pokazuje niczego**, a nie „0": pięć zer na każdym ekranie panelu
  mówi tyle samo, co ich brak, tylko zajmuje uwagę;
- **licznik znaczy „to czeka na Ciebie"**, nie „tyle jest wszystkiego" —
  sprawy w stanie `reviewing`/`in_progress` są już u człowieka i nie są
  liczone;
- **czytnik ekranu słyszy pełne zdanie**: widoczna cyfra ma `aria-hidden`,
  obok stoi ten sam licznik słowami, więc pozycja czyta się jako „Odwołania,
  2 czekają". Słowo („czeka"/„czekają", `Odmiana::rzeczownik`) jest jedno dla
  wszystkich pięciu pozycji, bo „nowe" wymagałoby trzech różnych form
  przymiotnika dla trzech różnych rodzajów rzeczowników.

Pomiar, nie założenie (AGENTS.md §3): `LicznikiKolejekBezZapytanTest` mierzy,
że odczyt liczników nie wykonuje ani jednego zapytania i że liczba zapytań
ekranu panelu nie rośnie, gdy kolejki puchną z 2 do 22 pozycji.

### 3. HIERARCHIA NA `/admin/odwolania`

Karta odwołania miała osiem bloków o jednej wadze i jednym odstępie, a trzy
z jej nagłówków sekcji stały na `--text-title-sm` (24 px) — tam, gdzie stoi
tytuł karty. Moderator szuka tam DWÓCH rzeczy: co ta osoba napisała i jaka
była pierwotna decyzja.

Panel nie jest przemalowany. Zmieniają się cztery rzeczy: odstęp między
blokami rośnie (a wewnątrz bloku maleje), treść do czytania dostaje formę
cytatu z krawędzią z boku, nagłówki sekcji schodzą do roli etykiet (18 px,
`--color-ink-muted`, dalej `<h3>` w znaczniku), a formularz jest odcięty
kreską i największym odstępem na karcie. **Nic, co się CZYTA, nie schodzi
poniżej 18 px** — mniejsze (16 px, wspólna klasa `.meta`) są wyłącznie
metadane, ten sam nazwany wyjątek co metryczka wersji w stopce (**D-051**).

### 4. DWIE USTERKI ZE ZRZUTU Z PRODUKCJI

- **„powód: tresci-dla-doroslych"** — surowy kod z bazy pokazany człowiekowi.
  Polska nazwa („Nagość albo przemoc (punkt 5)") leżała w `PodstawaDecyzji` od
  początku i szła już w tej postaci do autora treści. Nowa metoda
  `PodstawaDecyzji::etykieta()` jest jednym źródłem tej nazwy dla panelu;
  kody techniczne serwisu (`appeal_overturned`, `automat-falszywy-alarm`)
  dostały własne etykiety, a swobodny tekst z decyzji sprzed słownika jest
  pokazywany NAZWANY tym, czym jest („powód wpisany ręcznie: …"), bo dla
  starych spraw to jedyny ślad tego, co wtedy postanowiono. Ta sama usterka
  siedziała na `/admin/uzytkownicy/{konto}` i jest naprawiona tym samym
  wywołaniem.
- **„decyzję podjął(-ęła) Mateusz"** — konstrukcji zakładającej rodzaj nie da
  się przeczytać na głos (AGENTS.md §11). Zostaje forma bezosobowa
  („decyzję podjęto — Mateusz"), ten sam zabieg co w PR #235, który usunął
  jedenaście takich miejsc z serwisu i tego jednego nie objął.

**Ryzyko:** menu boczne (`components/layout.blade.php`) jest plikiem, który
w tym samym tygodniu ruszały prace nad stopką, prawą szyną i trybem panelu.
Zmiana jest tu wąska (jeden blok `@php` i pięć pozycji listy), ale scalanie
wymaga uwagi.

**Wycofanie:** nie ma migracji ani zmiany schematu. Cofnięcie to usunięcie
komponentu `<x-licznik-kolejki>` z pięciu pozycji menu, zadania
`kuking:policz-kolejki` z harmonogramu i haków ze `AppServiceProvider`;
powiadomienia typu `appeal.filed`, które zostaną w tabeli, wyświetlą się jako
pozycja bez własnego brzmienia, więc przy trwałym wycofaniu należy je usunąć
razem z gałęzią `@case` w widoku powiadomień.

📄 `app/Domain/Moderation/KolejkiPanelu.php` ·
`app/Domain/Moderation/Actions/PowiadomOOdwolaniu.php` ·
`app/Domain/Moderation/Actions/FileAppeal.php` ·
`app/Domain/Moderation/Actions/FileReporterAppeal.php` ·
`app/Domain/Moderation/PodstawaDecyzji.php` ·
`app/Console/Commands/PoliczKolejki.php` ·
`app/Console/Commands/PilnujTerminowOdwolan.php` ·
`app/Notifications/TerminOdwolaniaBlisko.php` ·
`config/kuking.php` (`moderation.appeal_reminder_working_days`, komentarz `poczta`) ·
`app/Models/Notification.php` ·
`app/Providers/AppServiceProvider.php` · `routes/console.php` ·
`resources/views/components/licznik-kolejki.blade.php` ·
`resources/views/components/layout.blade.php` ·
`resources/views/pages/admin/appeals.blade.php` ·
`resources/views/pages/admin/uzytkownik.blade.php` ·
`resources/views/pages/notifications.blade.php` ·
`resources/css/ekran-odwolan.css` · `resources/css/app.css` ·
`tests/Feature/PowiadomienieOOdwolaniuTest.php` ·
`tests/Feature/LicznikiKolejekBezZapytanTest.php` ·
`tests/Feature/LicznikiKolejekPaneluTest.php` ·
`tests/Feature/KolejkaOdwolanCzytelnoscTest.php` ·
`tests/Feature/TerminOdwolaniaPilnowanyPocztaTest.php` ·
`docs/legal/MODERATION_PLAYBOOK.md` §3

---

## D-061 · Zdjęcie profilowe przechodzi przez model, a celem oznaczenia jest PLIK, nie konto

**Data:** 10 września 2026 · Issue #237 · Status: **obowiązuje**

Pytanie właściciela było jednozdaniowe: *„czy zdjęcie profilowe jest
przetwarzane przez moderation omni model?"*. Odpowiedź brzmiała **nie** —
i to była luka większa, niż wyglądała.

Model oceniał zdjęcia **wpisów**. Awatar szedł zupełnie inną drogą
(`AvatarSettingsController` → `StoreUploadedImage` → `ProcessUploadedImage`)
i nikt na niej nie zlecał analizy, więc w kolejce automatu nie pojawiał się
nigdy, dopóki ktoś nie zgłosił go ręcznie.

### 1. Dlaczego awatar jest ważniejszy niż wpis

**Jest widoczny częściej.** Wpis widzą obserwujący i ci, którzy trafią na
niego w feedzie. Awatar chodzi za człowiekiem po całym serwisie: przy każdym
komentarzu pod cudzym przepisem, na tablicy dnia, na listach obserwujących,
w wynikach szukania osób. Jedno zdjęcie trafia przed oczy większej liczby
osób niż wpis, w którym stało.

**I jest najtańszym miejscem dla kogoś, kto chce zaszkodzić:** nie wymaga
napisania ani jednego słowa, więc nie rusza `WykrywaczSygnalow` (pracuje na
tekście). Przy fali migracyjnej nikt nie przejrzy kilkuset nowych awatarów
po kolei — a od D-054 ustawienie zdjęcia jest o trzy kliknięcia krótsze,
czyli częstsze.

### 2. Celem oznaczenia jest KONKRETNE ZDJĘCIE

`reports.target_type = 'media'`, `target_id` = `media.id`. To jest cała
decyzja tego wpisu i jedyna rzecz, którą łatwo zrobić źle.

Indeks `reports_jeden_automat_na_tresc` przepuszcza **jedno** oznaczenie
automatu na (typ, identyfikator) — na zawsze, także po odrzuceniu. Przy celu
`user` znaczyłoby to: oceniony pierwszy awatar konta i **żaden następny**.
A podmiana zdjęcia to sekunda pracy, więc cała funkcja dałaby się obejść
jednym klikiem. Ma to własny test (`test_drugie_zdjecie_tego_samego_konta…`).

Nazwa typu to `media`, a nie `avatar`, bo `ModeratedContent::TYPY` mapuje
**klasę** modelu, a klasa jest ta sama dla awatara i dla zdjęcia we wpisie.
`avatar` byłoby prawdą dziś i nieprawdą pierwszego dnia, w którym oznaczymy
zdjęcie z wpisu osobno. Że chodzi o zdjęcie profilowe, mówi treść powodu
(„Zdjęcie profilowe: …") i podgląd w kolejce.

Osoba, której to dotyczy, siedzi w `autor_tresci_id` — kolejka grupuje po
człowieku, bo kara zawsze dotyczy człowieka, nie pliku.

### 3. Zadanie CZEKA na warianty, zamiast cicho nie zrobić nic

Model dostaje wariant `thumb` (przekodowany, bez EXIF-u), a wariant powstaje
w `ProcessUploadedImage` — w innym zadaniu, na kolejce `media`. Gdyby
`PrzeanalizujAwatar` kończyło się powodzeniem przy zdjęciu w stanie
`processing`, funkcja działałaby wyłącznie wtedy, gdy worker mediów wyprzedzi
worker kolejki `low` — **czyli losowo, i nikt by tego nie zauważył**. Dlatego
zadanie wraca do kolejki (`release(30)`, do trzech prób), a nie kończy się
powodzeniem. To jest ta sama klasa usterki, którą w tym repozytorium tępimy
od pierwszego dnia: narzędzie melduje sukces, nie robiąc nic.

### 4. Co moderator może zrobić — i czego NIE MOŻE

`ModerationAction::DOZWOLONE['media']` = `none`, `warn`, `suspend`, `ban`.
Świadomie **bez `hide`** i **bez `remove`**:

| Decyzja | Dlaczego jej nie ma |
|---|---|
| `hide` | `Media` nie ma statusu w rozumieniu moderacji. Przycisk robiłby to, co robił przy „Ugotowałem": nic, przy powiadomieniu „ukryliśmy Twoją treść" |
| `remove` | `$target->delete()` na zdjęciu jest nieodwracalne (brak miękkiego kasowania), a odwołanie od `remove` ma treść **przywrócić** (DSA art. 17, `ResolveAppeal`). Decyzja, od której nie da się skutecznie odwołać, nie może stać na tym ekranie |

Zostaje ostrzeżenie (od D-058 razem z odpowiedzią pocztą wprost z panelu),
zawieszenie i ban. **Usunięcie cudzego zdjęcia profilowego przez moderatora
wymaga najpierw miękkiego kasowania zdjęć** — osobna praca, świadomie nie
w tym wpisie. Do tego czasu na ekranie nie ma przycisku, który by tego nie
robił.

### 5. Granica bez zmian: automat podnosi rękę, nigdy nie zamyka drzwi

Awatar **zostaje widoczny**, autor niczego się nie dowiaduje, decyzję
podejmuje człowiek (D-052 poz. 3.6 i 3.10, D-055). Przy zdjęciu profilowym
pokusa jest większa niż zwykle — „przecież wystarczy podmienić na literę" —
ale ciche podmienienie komuś awatara przez maszynę to jest dokładnie shadow
filtering z poz. 3.16, odrzucone jako sprzeczne z art. 17 DSA.

### 6. Co poszło do OpenAI i co o tym mówimy

Ta sama droga co przy zdjęciach wpisów: wariant `thumb` przekodowany do
JPEG, wysłany jako `data:` (nie adres — publiczny adres dla OpenAI byłby
publiczny dla wszystkich). **Zakres wysyłanych danych się rozszerzył**, więc
polityka prywatności mówi o tym wprost, w akapicie „Co wysyłamy do OpenAI",
i wiersz w tabeli dostawców też. Dokument nie może milczeć o danych, które
wychodzą z serwisu.

**Pliki:** `app/Jobs/PrzeanalizujAwatar.php` ·
`app/Domain/Moderation/Actions/AlarmujModeratora.php` (wyjęte z
`PrzeanalizujTresc`, bo alarmują teraz dwa zadania) ·
`app/Moderacja/OcenaModelem::dlaZdjecia()` ·
`app/Domain/Moderation/ModeratedContent.php` · `app/Models/ModerationAction.php`
· `app/Models/Report.php` · `app/Http/Controllers/Admin/SygnalyController.php`
· migracja `2026_09_10_300000_zdjecie_jako_cel_oznaczenia` ·
`docs/legal/SYGNALY_AUTOMATU.md` §9 · `docs/DATABASE.md` ·
`resources/legal/polityka-prywatnosci.md`

---

## D-064 · HEIC zostaje odrzucany, z komunikatem mówiącym co zrobić — libheif do obrazu Dockera NIE wchodzi teraz

**Data:** 10 września 2026 · Issue #119 (F-01 audytu z 6 września 2026) · Status: **obowiązuje**

Pytanie z issue: dokładać `libheif` (przez Imagick) do obrazu Dockera i przyjmować
HEIC naprawdę, czy zostać przy dzisiejszym odrzuceniu? Odpowiedź: **zostać przy
odrzuceniu — ale odrzuceniu, które mówi CO ZROBIĆ**, plus jedna zmiana, która nie
czekała na tę decyzję: HEIC dostaje teraz **własny kod powodu** w sygnale
`photo_upload_failed`, żeby dało się w ogóle zmierzyć, ile to jest osób.

### 1. Stan faktyczny, sprawdzony w tej sesji, nie przepisany z audytu

Zmierzone bezpośrednio w kontenerze agenta (PHP 8.4.19), to samo, co audyt #119
znalazł 6 września — powtórzone tutaj, żeby nie polegać na cudzym pomiarze bez
sprawdzenia:

```
$ php -r 'var_dump(defined("IMAGETYPE_HEIC"), defined("IMAGETYPE_HEIF"));'
bool(false)
bool(false)

$ php -m | grep -i imagick
(pusto — rozszerzenie nie jest zainstalowane)

$ php -r 'print_r(gd_info());'
...
[JPEG Support] => 1  [PNG Support] => 1  [WebP Support] => 1  [BMP Support] => 1
[AVIF Support] => 1
(brak jakiegokolwiek wpisu HEIC/HEIF)
```

**Zastrzeżenie, wprost:** ten kontener NIE JEST obrazem produkcyjnym — to
środowisko agenta, nie `dunglas/frankenphp:1-php8.4-trixie` z `Dockerfile`.
W tej sesji nie było demona Dockera (`docker info` kończy się błędem połączenia
z `/var/run/docker.sock`), więc **obrazu produkcyjnego nie dało się tu zbudować
ani uruchomić** — to zostaje otwarte dla właściciela (§5). Wniosek o braku HEIC
nie zależy jednak od tego, który kontener się sprawdza: `Dockerfile` (etapy
`vendor` i `runtime`, linie 90–99 i 132–141) instaluje przez
`install-php-extensions` dokładnie: `pdo_pgsql pgsql intl gd zip exif pcntl
bcmath opcache` — **bez `imagick`**, i nie doinstalowuje `libheif` żadnym
`apt-get`. Skoro GD w tym samym PHP 8.4 (ten sam `dunglas/frankenphp` bazowy
obraz co etap `vendor`) nie zna HEIC, a obraz produkcyjny nie dokłada niczego,
co by to zmieniło, wniosek „produkcja też nie otwiera HEIC" nie wymaga
zbudowania obrazu, żeby być prawdziwym — wymaga tylko przeczytania, czego
`Dockerfile` NIE instaluje. Dokładny numeryczny pomiar (rozmiar warstwy, czas
dekodowania) to już inna sprawa — patrz §5.

**Co się dzieje dziś, krok po kroku, gdy ktoś wgra HEIC** (dwie niezależne
drogi, obie kończą się tym samym komunikatem):

1. Formularz (`PostController::store` i trzy pozostałe) waliduje polem
   `photos.*` regułą `App\Rules\ObslugiwaneZdjecie` (`app/Http/Controllers/
   PostController.php:125`). Reguła woła `RozpoznanieZdjecia::rozpoznaj()`
   (`app/Rules/ObslugiwaneZdjecie.php:86`).
2. `RozpoznanieZdjecia::rozpoznaj()` (`app/Support/RozpoznanieZdjecia.php:83`)
   wywołuje `getimagesize()`. Dla HEIC/HEIF to zawodzi (`bool(false)`) —
   PHP 8.4 nie ma stałej `IMAGETYPE_HEIC` ani `IMAGETYPE_HEIF`, więc nawet nie
   próbuje.
3. **Zmiana z tego zgłoszenia:** zamiast wpadać do ogólnej gałęzi „plik
   nieczytelny", kod sprawdza `mime_content_type()` (magic bytes — nagłówek
   ISO BMFF `ftyp` z marką `heic`/`heif`/`mif1`, NIE rozszerzenie pliku i NIE
   `Content-Type` od przeglądarki) i przy HEIC/HEIF zwraca osobny kod
   `heic_unsupported` z komunikatem mówiącym co zrobić (§3).
4. Ten sam plik, wysłany OMIJAJĄC formularz, odbija się identycznie —
   `StoreUploadedImage::handle()` (`app/Domain/Media/Actions/
   StoreUploadedImage.php:90`) woła to samo `RozpoznanieZdjecia::rozpoznaj()`.
   To jest prawdziwa granica (AGENTS.md §7: nie ufamy niczemu od klienta) i
   trzyma niezależnie od formularza.
5. Człowiek widzi błąd PRZY POLU `photos.0`, z resztą błędów, bez utraty
   wpisanego tekstu (`old()`) — nigdy 500, nigdy pustą ramkę, nigdy zdjęcie
   w `pending`/`rejected` bez wyjaśnienia. Zdjęcie **nigdy nie trafia do
   `Media::create()`** — dociera do niego zero wierszy HEIC, więc worker
   (`ProcessUploadedImage`) nigdy nie widzi tego pliku i nie ma szans utknąć
   na jego dekodowaniu.

**Co NIE jest dziś prawdą** (i audyt też to mylił): to nie jest awaria w
środku potoku, workera ani „zdjęcie wisi w `processing`". Plik odpada na
SAMYM WEJŚCIU, zanim cokolwiek trafi do kolejki `media`. Konsekwencja: cały
rachunek kosztu pamięci/czasu workera z `docs/MEDIA_PIPELINE.md` (tabela
12/24/50 Mpx → 161/254/452 MB) dziś **nie dotyczy HEIC w ogóle** — dotyczyłby
dopiero, gdyby ta decyzja brzmiała „tak, dokładamy libheif" (§4).

### 2. Sprostowanie założenia z treści zadania: worker ma 1024 MB, nie 384 MB

Zadanie, z którego powstał ten wpis, zakładało limit workera `--memory=384`.
To nieprawda i warto to powiedzieć wprost, zamiast pisać rachunek kosztu pod
liczbę, która nie istnieje w tym repozytorium (AGENTS.md, sekcja o
sprostowaniu z 9 września: liczy się to, co jest w kodzie).

Sprawdzone:
- `.railway/railway.ts:768` — kontener serwisu `worker` ma
  `memoryBytes: 1024 * MB` (twardy limit Railway, OOM-kill powyżej).
- `docker/entrypoint.sh:369` — `queue:work --memory="${QUEUE_MEMORY:-700}"`:
  to jest MIĘKKI limit Laravela (kończy proces między jobami po przekroczeniu
  700 MB RSS), niezależny od twardego limitu kontenera.
- `docker/entrypoint.sh:363` — `memory_limit` PHP ustawiony na
  `${PHP_WORKER_MEMORY_LIMIT:-512M}` (`.railway/railway.ts:454`), ale
  `docs/MEDIA_PIPELINE.md` już ostrzega, że TA liczba nie widzi bufora GD:
  zmierzony szczyt RSS dla 50 Mpx to 452 MB przy liczniku PHP pokazującym
  28 MB. Realny sufit, o który trzeba się martwić przy większym pliku, to
  1024 MB kontenera, nie 384 i nie 512.

`--memory=384` nie pojawia się nigdzie w tym repozytorium (`grep -rn 384
docker/ .railway/` nic nie znajduje). Skąd wzięła się ta liczba w treści
zadania — nie wiadomo; mogła być pomyłką przy przepisywaniu z innego miejsca.
Rachunek w §4 liczy więc wobec PRAWDZIWYCH 1024 MB.

### 3. Co jest prawdą dla iPhone'a — sprawdzone, nie zgadywane

Zadanie wprost każe to sprawdzić, a nie zgadywać. Dwa źródła: oficjalna pomoc
Apple (`support.apple.com/en-us/116944`, „Using HEIF or HEVC media on Apple
devices") i — tam, gdzie Apple milczy — zgodne relacje z wielu niezależnych
wątków Apple Community.

**Sprawdzone i prawdziwe:**
- „Ustawienia → Aparat → Formaty → Najbardziej zgodny" to **dosłowna** ścieżka
  menu z dokumentacji Apple: *„Open Settings, then tap Camera. Tap Formats,
  then tap Most Compatible."* Efekt: *„All new photos and videos will now use
  JPEG or H.264 format."* Dotyczy PRZYSZŁYCH zdjęć.
- Apple wprost: *„If sharing this media using other methods, such as AirDrop,
  Messages, or email, and the receiving device doesn't support the newer
  media formats, the media might automatically be shared in a more
  compatible format, such as JPEG or H.264."* Zgodnie z wieloma niezależnymi
  wątkami Apple Community, w praktyce udostępnienie przez Mail konwertuje
  HEIC do JPEG jako zachowanie domyślne (stąd wątki „jak to WYŁĄCZYĆ", nie
  „jak to włączyć") — to jest droga dla zdjęcia, które JUŻ leży w telefonie.

**Sprawdzone i USUNIĘTE z komunikatu** (wcześniejsza wersja to zgadywała):
poprzedni tekst radził też „otwórz w Zdjęciach i użyj «Duplikuj», żeby dostać
wersję JPG". Fałsz: wykrywanie duplikatów w Photos porównuje pliki po
FORMACIE, nie po treści — co oznacza, że funkcja Duplikuj tworzy drugą kopię
W TYM SAMYM formacie (HEIC), nie konwertuje niczego. `app/Support/
RozpoznanieZdjecia.php` (`komunikatHeic()`) ma teraz tylko dwie rady, obie
sprawdzone: zmianę ustawienia na przyszłość i wysyłkę e-mailem dla zdjęcia,
które już jest zrobione.

**Czego NIE sprawdzono i co zostaje otwarte** (kryterium akceptacji #119,
niewykonalne z tego repozytorium): pomiar na prawdziwym urządzeniu — czy
Safari na iOS naprawdę wysyła JPEG zamiast HEIC, gdy formularz (przez
`LimityZdjec::atrybutAccept()`) nie deklaruje `image/heic` w `accept`. Są na
to poszlaki z dokumentacji dla programistów (starsze Safari konwertowały
zgodnie z `accept`; Safari 17+ ma zgłoszony wyjątek od tego zachowania w
niektórych warunkach), ale żadna z nich nie zastępuje testu na fizycznym
iPhonie — patrz §5.

### 4. Rachunek kosztu dołożenia libheif (przez Imagick) — dlaczego NIE teraz

**Rozmiar obrazu.** Zmierzone jako przybliżenie z metadanych pakietów Debian/
Ubuntu (`apt-cache show`, `Installed-Size` — NIE zbudowany obraz produkcyjny,
bo nie ma tu demona Dockera; realne bajty w warstwie `dunglas/frankenphp:
1-php8.4-trixie` mogą się różnić i wymagają prawdziwego builda przed decyzją
ostateczną):

| Pakiet | Installed-Size |
|---|---:|
| `libheif1` | 803 KB |
| `libde265-0` (dekoder HEVC — format większości zdjęć iPhone) | 375 KB |
| `libheif-plugin-libde265` | 41 KB |
| `libmagickcore-6.q16` (silnik ImageMagick) | 6 648 KB |
| `libmagickwand-6.q16` | 1 352 KB |
| rozszerzenie `imagick` (`.so`) | 18 KB |
| **Razem (bez `libaom`/AVIF — patrz niżej)** | **≈ 9,2 MB nieskompresowane** |

Świadomie POMINIĘTE: `libheif-plugin-aomdec` + `libaom3` (dekoder AV1, **5,3 MB
samo `libaom3`**) — obsługuje HEIF-w-AV1, rzadki wariant. AVIF (to samo
kodowanie AV1, inny kontener) GD **już** dekoduje natywnie (`gd_info()` →
`AVIF Support => 1`), więc dokładanie drugiej drogi do tego samego kodeka nie
ma uzasadnienia. Rzeczywisty dodatek do warstwy obrazu to więc rząd
**kilku–dziesięciu MB nieskompresowanych**, prawdopodobnie mniej po kompresji
warstwy Docker — ale to jest SZACUNEK, nie pomiar na tym `Dockerfile`.

**Czas builda.** Dodatkowy `apt-get install` w DWÓCH etapach (`vendor` i
`runtime` — `Dockerfile` instaluje rozszerzenia PHP w obu, linie 90 i 132, z
komentarzem „te same rozszerzenia co w etapie vendor, trzymaj listy
zsynchronizowane"): rząd dodatkowych 10–30 sekund na etap przy zimnym cache
warstwy `apt`, niezmierzone dokładnie tutaj.

**Pamięć/czas dekodowania HEIC — TO JEST GŁÓWNA NIEWIADOMA, nie rozmiar.**
Kryterium akceptacji #119 wprost tego wymaga i NIE DA SIĘ tego zmierzyć bez
`libheif`+`imagick` w środowisku (nie są tu zainstalowane). Software'owy
dekoder HEVC (`libde265`) jest z natury cięższy niż dekodowanie JPEG baseline
przez GD — o ile cięższy, dla zdjęcia 48 Mpx (główny sensor iPhone 14 Pro i
nowszych), NIE JEST zmierzone ani w tym repozytorium, ani w tej sesji. Zanim
ta decyzja mogłaby brzmieć „tak", ten pomiar musi istnieć — patrz próg
rewizji niżej.

**Drugi sterownik obrazu do utrzymania.** `intervention/image` w wersji
`3.11.8` (`composer.lock`) ma DWA sterowniki: `ImageManager::gd()` (używany
dziś, `app/Jobs/ProcessUploadedImage.php:119`) i `ImageManager::imagick()`.
Orientacja EXIF w tym repo jest naprawiona SPECYFICZNIE pod zachowanie GD
(`ProcessUploadedImage.php:91–118`: `autoOrientation: false` naprawia
PODWÓJNY obrót, bo dekoder GD Interventionu czyta EXIF sam) — przejście na
Imagick jako główny sterownik oznaczałoby ponowne sprawdzenie tej samej
klasy błędu dla innego dekodera, nie przepisanie jednej linijki. Węższa,
bezpieczniejsza architektura, GDYBY ta decyzja kiedyś brzmiała „tak": użyć
Imagicka WYŁĄCZNIE jako wąski adapter „bajty HEIC wchodzą → bajty JPEG
wychodzą", wołany tylko dla plików rozpoznanych jako HEIC, PRZED
`ImageManager::gd()` — reszta potoku (warianty, orientacja, testy) zostaje
nietknięta. To nie jest dzisiejsza implementacja, to zapisany kierunek na
wypadek rewizji.

**Nowa powierzchnia CVE.** `libheif` miał w swojej historii zgłoszenia CVE
(dekodery formatów obrazu/wideo są klasycznym źródłem przepełnień bufora —
ta sama rodzina ryzyka co libwebp, libjpeg). Dodanie go to zobowiązanie do
pilnowania łatek w kontenerze, który dziś ma zamkniętą, przewidywalną listę
rozszerzeń (`Dockerfile`, komentarz przy etapie `vendor`: „DETERMINISTYCZNY
zestaw rozszerzeń PHP").

### 5. Alternatywy rozważone i odrzucone (albo odłożone)

| Opcja | Werdykt |
|---|---|
| **Biblioteka PHP bez zależności systemowych** | Nie istnieje sensowna. Dekodowanie HEVC to dekodowanie wideo — nie ma czystego PHP-owego dekodera, z tego samego powodu, dla którego nie ma czystego PHP-owego dekodera H.264. Każda opcja i tak schodzi do biblioteki C (libheif) przez rozszerzenie. |
| **Odrzucenie z dobrym komunikatem** | **To jest dzisiejsza decyzja** — patrz §1 i §3. Jedyna opcja bez kosztu infrastruktury, bez nowego kodeka do utrzymania, i już zaimplementowana. |
| **Konwersja po stronie przeglądarki (JS, dozwolone na newralgicznych ścieżkach — D-053)** | **Najbardziej obiecujący NASTĘPNY krok, świadomie NIE w tym zgłoszeniu.** `heic2any`/`libheif.js` (WASM) mogłyby dekodować HEIC na telefonie użytkownika i wysłać JPEG — zero kosztu pamięci/CPU workera, zero zmiany obrazu Dockera. Cena: waga paczki JS (WASM dekodera HEVC to rząd setek KB), czas CPU na telefonie (który już raz zdekodował to zdjęcie robiąc je — więc sprzętowo go stać), i **realna pułapka projektowa**: żeby okno wyboru pliku w ogóle POKAZAŁO pliki HEIC do wybrania, atrybut `accept` musiałby je wymieniać — a to jest DOKŁADNIE to ustawienie, które dziś (za sprawą jego BRAKU) może już włączać darmową konwersję Safari opisaną w §3. Ta praca wymaga więc jednocześnie: sprawdzenia na prawdziwym urządzeniu (§3, nadal otwarte) I świadomego zaprojektowania koegzystencji z zachowaniem Safari, żeby nie wyłączyć jednej sieci bezpieczeństwa, dokładając drugą. Nie robimy tego przy okazji tego zgłoszenia (AGENTS.md §3: nie dokładamy rzeczy bez zmierzonej potrzeby, a próg z §6 jeszcze nie jest zmierzony). |
| **libheif + Imagick w obrazie Dockera** | Odłożone — patrz §4. Nie „nigdy", tylko „nie bez pomiaru z §6". |
| **vips** (`libvips`) | Odrzucone bez dalszej analizy: `intervention/image` 3.x nie ma sterownika vips (tylko `gd` i `imagick`) — wymagałoby albo czekania na wsparcie biblioteki, albo pisania własnej integracji. Nieproporcjonalne do problemu. |

### 6. Próg, przy którym ta decyzja wraca na stół

Ta decyzja NIE jest „nigdy" — jest „nie bez tych trzech rzeczy naraz":

1. **Dane z produkcji**, nie przeczucie: `SELECT count(*) FROM product_signals
   WHERE signal_name = 'photo_upload_failed' AND properties->>'reason' =
   'heic_unsupported' AND occurred_at > now() - interval '30 days'` (zapytanie
   działa od tego wpisu — patrz `docs/research/ANALITYKA_STAN_WDROZENIA.md`
   §2.3) pokazujące, że odrzucenie HEIC jest **regularną**, a nie brzegową,
   przyczyną nieudanej publikacji głównej akcji produktu.
2. **Pomiar na prawdziwym iPhonie** (kryterium akceptacji #119, nadal
   niewykonane) — bo jeśli Safari i tak konwertuje większość ruchu do JPEG
   przy wysyłce (§3), sygnał z punktu 1 może zostać mały sam z siebie, a
   dokładanie libheif rozwiązywałoby problem, który już zniknął.
3. Jeśli 1 i 2 pokażą realną skalę: **najpierw** spróbować konwersji po
   stronie przeglądarki (§5) — dopiero jej niewystarczalność (np. przeglądarki
   bez WASM wśród realnego ruchu, awaria dekodowania w praktyce) uzasadnia
   dokładanie zależności systemowej do obrazu produkcyjnego.

### Co zaimplementowane w tym zgłoszeniu (bez zmiany obrazu Dockera)

- `App\Support\RozpoznanieZdjecia`: HEIC/HEIF dostaje własny kod powodu
  `heic_unsupported` (było: dzielony z każdym innym nieczytelnym plikiem pod
  `not_an_image`) — bez tego punkt 1 z §6 nie dałby się w ogóle policzyć.
  Rozpoznanie dalej po magic bytes (`mime_content_type()`), NIE po
  rozszerzeniu ani nagłówku od przeglądarki.
- Komunikat dla człowieka poprawiony do dwóch sprawdzonych rad zamiast dwóch,
  z których jedna była zgadywana i fałszywa (§3).
- Testy: `tests/Feature/ObiecujemyTylkoFormatyKtoreUmiemyTest.php` — nowy
  test przez PRAWDZIWY formularz (`posts.store`), z prawdziwymi magic bytes
  HEIC (pudełko `ftyp`/`heic`, nie plik `.heic` z bajtami JPEG — dokładnie
  pułapka, przed którą ostrzegało zadanie), sprawdzający błąd przy polu,
  zachowanie wpisanego tekstu, zero wierszy `media`, i kod powodu w sygnale.
- Dokumentacja: ten wpis, `docs/research/ANALITYKA_STAN_WDROZENIA.md` §2.3,
  `docs/MEDIA_PIPELINE.md`, `config/kuking.php` (komentarz przy
  `accepted_mime_types`).

### Aktualizacja 20 września 2026 — obietnica bez pokrycia poprawiona (#119 follow-up)

Ta decyzja **nie jest otwierana na nowo**: HEIC nadal jest odrzucany, `libheif`
nadal nie wchodzi do obrazu Dockera. Poprawiono wyłącznie TEKST komunikatu
z §3 pkt 2, po pomiarze stanowiska `gpt/heic-format`
(`docs/research/heic-119/RAPORT.md`).

Znaleziony błąd: komunikat obiecywał **bezwarunkowo**, że wysłanie HEIC do
siebie e-mailem da JPG („wyślij najpierw do siebie e-mailem — przyjdzie jako
JPG"). Apple (support.apple.com/pl-pl/116944) opisuje to jako zależne od
sposobu udostępniania i możliwości odbiorcy — „może" zostać wysłane w formacie
zgodnym, nie „zostanie". Naprawiono `App\Support\RozpoznanieZdjecia::komunikatHeic()`:
wynik dla TEGO zdjęcia nazwany jako niepewny („telefon czasem sam zamienia
je wtedy na JPG, ale zależy to od modelu telefonu"), z prostą alternatywą
(wybrać inne, gotowe zdjęcie), i osobno, jasno opisane ustawienie na
PRZYSZŁOŚĆ, które nie przerabia zdjęcia już zrobionego. Nie zastąpiono jednej
niepewnej obietnicy inną równie pewną — żadna sprawdzona na 100% droga
konwersji ISTNIEJĄCEGO pliku nie jest znana (patrz RAPORT.md §5: Mail,
„Duplikuj" i zewnętrzny konwerter odradzane jako pewniki).

Drugi błąd, drobniejszy: polska pomoc Apple podaje etykietę „Najbardziej
zgodne" (rodzaj nijaki), a komunikat (i ten wpis w §3 pkt 1 wyżej) miał
błędną odmianę „Najbardziej zgodny". Poprawiono w obu miejscach.

Test regresyjny (RED przed poprawką, GREEN po):
`tests/Feature/ObiecujemyTylkoFormatyKtoreUmiemyTest.php::test_komunikat_heic_nie_obiecuje_bezwarunkowo_konwersji_mailem`.

### Co CZEKA na właściciela (opisane, nie wykonane)

- **Pomiar na prawdziwym iPhonie** (§3, §6 pkt 2) — nie do wykonania z tego
  środowiska (brak fizycznego urządzenia i brak Safari zza proxy sesji —
  AGENTS.md, sekcja o przeglądarce w kontenerze agenta).
- **Build i pomiar realnego rozmiaru/czasu obrazu Dockera z `libheif`+
  `imagick`**, GDYBY próg z §6 kiedyś został przekroczony — wymaga demona
  Dockera (niedostępny w tej sesji: `docker info` nie łączy się z
  `/var/run/docker.sock`) i **jawnej zgody właściciela na zmianę
  `Dockerfile`** (dotyka wdrożenia produkcji — poza mandatem tego zgłoszenia).
- **Odczyt `product_signals` po 30 dniach** od wdrożenia tej zmiany, żeby
  ocenić próg z §6 pkt 1 na prawdziwych danych zamiast zera.

**Zmiana wymaga:** trzech rzeczy z §6 naraz — danych z produkcji pokazujących
realną skalę, pomiaru na prawdziwym iPhonie, i próby konwersji po stronie
przeglądarki jako tańszego pierwszego kroku. Samo „iPhone jest popularny"
(prawdziwe, ale znane już w dniu, gdy HEIC zdjęto z listy formatów) tego progu
nie przekracza.

**Pliki:** `app/Support/RozpoznanieZdjecia.php` ·
`app/Support/WynikRozpoznania.php` · `app/Domain/Analytics/ZapiszSygnal.php` ·
`tests/Feature/ObiecujemyTylkoFormatyKtoreUmiemyTest.php` ·
`docs/research/ANALITYKA_STAN_WDROZENIA.md` · `docs/MEDIA_PIPELINE.md` ·
`config/kuking.php` · `Dockerfile` (przeczytane, NIE zmienione) ·
`.railway/railway.ts` · `docker/entrypoint.sh`

---

## D-065 · Trzy pakiety zostają na później albo na nie: role w kolumnie, flagi w `.env`, audyt własny (issue #21)

**Data:** 10 września 2026 · Issue #21 · Status: **obowiązuje**

`docs/research/PUBLIC_REPOS.md` rekomendował trzy pakiety Laravela do
„bardzo wczesnego" wdrożenia: `spatie/laravel-permission`, `laravel/pennant`,
`spatie/laravel-activitylog`. Pełna analiza z cytatami `plik:linia` już
istniała — `docs/research/PAKIETY.md` i `docs/INSPIRATION_DECISIONS.md` §10
— ale bez wpisu w tym dzienniku, więc formalnie nierozstrzygnięta (issue #21
zostało otwarte właśnie z tego powodu). Ten wpis **potwierdza** tamte
werdykty po ponownym sprawdzeniu w dzisiejszym kodzie (nie tylko w notatce
z 6 września) i domyka issue.

**Kryterium jest jedno, z `AGENTS.md` §3: pakiet wchodzi tylko wtedy, gdy
usuwa nazwany, dziś istniejący problem.** „Przyda się później" nie jest
uzasadnieniem. Żaden z trzech pakietów **nie jest** dziś w
`composer.json`/`composer.lock` (`grep -iE "spatie|pennant|permission|activitylog"`
— zero trafień w obu plikach) i żaden nie został tu dodany — to jest wpis
decyzyjny, nie wdrożenie.

### 1. `spatie/laravel-permission` → **PÓŹNIEJ**

Dziś: `users.role`, string, **trzy** wartości (nie dwie), z `CHECK` w bazie:

```php
// database/migrations/0001_01_01_000001_create_users_table.php:35,58
$table->string('role', 20)->default('user');
DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('user','moderator','admin'))");
```

Sprawdzanie roli, zmiana roli i egzekwowanie idą przez jedno źródło prawdy —
`app/Models/User.php:137-141` (`ROLE_USER`/`ROLE_MODERATOR`/`ROLE_ADMIN`),
`:617-620` (`isModerator()`), `:637-639` (`isAdmin()`), `:1128-1132`
(`promoteTo()`, jedyna droga zmiany, rola poza `$fillable` — D-006). Realny
problem, na który wskazywało issue #21 — rozdział ról moderator/administrator
przy odwołaniach — **już jest rozwiązany bez pakietu**:
`UserPolicy::resolveAppeals()` woła `isAdmin()`, nie `isModerator()`
(`app/Policies/UserPolicy.php:70-73`, D-039), i ma test regresyjny
(`tests/Feature/OdwolanieOdDecyzjiTest.php::test_moderator_bez_roli_administratora_nie_rozstrzyga_odwolania`)
sprawdzający dokładnie tę granicę na żywym żądaniu HTTP.

**Sprostowanie wobec wcześniejszej notatki (`PAKIETY.md`):** ta notatka
twierdziła, że `Gate::` nie występuje w `app/` ani razu. Dziś **występuje w
sześciu miejscach** (`app/Domain/Sharing/Udostepnianie.php:52`,
`app/Domain/Moderation/Actions/ReportContent.php:108`,
`app/Domain/Recipes/Actions/RecordCookedEvent.php:99`,
`app/Domain/Media/DostepDoZdjecia.php:100`,
`app/Http/Controllers/RecipeController.php:217`) — kod poszedł naprzód od
6 września. Nie zmienia to wniosku: każde z tych wywołań to
`Gate::forUser($x)->allows(...)`/`->denies(...)`, czyli wejście do **tych
samych** klas Policy przez fasadę frameworka, nie druga ścieżka autoryzacji
obok nich. `grep -rn "hasPermissionTo\|->can(" app/` — zero trafień; jest
dokładnie jedna rodzina bramek, i to jest dokładnie to, co ma zostać, gdyby
pakiet kiedyś wszedł.

**Dlaczego nie teraz:** pakiet dodaje pięć tabel
(`permissions`, `roles`, `model_has_permissions`, `model_has_roles`,
`role_has_permissions`)[^1] dla kombinacji uprawnień, których w kodzie **zero**
— nawet `isAdmin()` jest dziś wołany tylko z jednego miejsca
(`UserPolicy::resolveAppeals()`). Cache pakietu (`store: default`, TTL 24h)[^2]
działa poprawnie na `CACHE_STORE=database` — to nie jest powód przeciw.
Realne ryzyko to nie koszt instalacji, tylko dyscyplina po niej: trzeba by
pilnować, żeby `hasPermissionTo()`/`$user->can()` z pakietu były wołane
wyłącznie **z wnętrza** Policy, tak jak dziś `isModerator()`, a nie **obok**
nich — inaczej powstają dwa niezależne miejsca egzekwowania, dokładnie to,
przed czym ostrzega `AGENTS.md` §7.

**Rekomendacja: PÓŹNIEJ. Próg powrotu: trzeci moderator, albo pierwszy
przypadek, w którym trzeba rozdzielić uprawnienia w ramach jednej roli**
(„może ukrywać treść, ale nie może banować kont"). Dziś jest 1–2 moderatorów
(D-012). Migracji przejściowej `users.role` → tabele pakietu **świadomie nie
piszemy teraz** — byłby to kod do wyrzucenia, gdyby próg nie nadszedł, czyli
dokładnie budowanie na zapas z `AGENTS.md` §3. Próg jest tani do sprawdzenia:
jedno spojrzenie na listę kont z rolą `moderator`/`admin`.

**Mała poprawka wykonana w tym PR-ze:** cała ta rekomendacja stoi na zdaniu
„`role IN (...)` jest pilnowane w jednym miejscu prawdy — w bazie, nie tylko
w PHP". To zdanie nie miało testu. `NadanieRoliTest::test_nieznana_rola_jest_odrzucana()`
sprawdza wyłącznie walidację PHP w `promoteTo()`; omija ją każdy zapis, który
nie przechodzi przez model (migracja danych, ręczny `UPDATE`, przyszły bug
gdzie indziej). Dodany test
`test_baza_odrzuca_role_spoza_trzech_dozwolonych_wartosci` pisze wprost przez
`DB::table('users')->update(...)`, z pominięciem `User`, i sprawdza, że
`users_role_check` naprawdę odrzuca wartość spoza trzech dozwolonych —
patrz tabela kontroli ujemnej niżej.

### 2. `laravel/pennant` → **PÓŹNIEJ**

Dziś: brak jakiegokolwiek mechanizmu flag funkcji — potwierdzone ponownie
(`grep -rniE "feature.?flag|pennant|toggle\(" app/ config/ database/ routes/`
nie znajduje nic poza niepowiązanym komentarzem w opublikowanym pliku
Livewire, `config/livewire.php:188`). „Zamknięta alfa" to jeden globalny
bool, przełącznik CAŁEGO serwisu dla nowych kont, nie flaga POJEDYNCZEJ
funkcji dla wybranych kont:

```php
// config/kuking.php:250
'registration_open' => (bool) env('KUKING_REGISTRATION_OPEN', true),
```

Trzy nazwy z treści zadania (`KUKING_SYGNALY_AUTOMATU`,
`KUKING_DIGEST_WLACZONY`, `KUKING_MODEL_*`) potwierdzają ten sam wzorzec —
`config/kuking.php:1304,1709,1795-1847` — jeden bool albo liczba na całą
funkcję, czytane raz przy starcie procesu. Różnica wobec Pennanta: zmienna
środowiskowa przełącza funkcję **dla wszystkich naraz i wymaga restartu
procesu** (na Railwayu: redeploy), podczas gdy Pennant przełącza **per
użytkownik** (np. tylko dla konta administratora) **bez restartu**, bo stan
czyta z tabeli `features`[^3] przy każdym żądaniu.

**Czy to zysk przy jednym właścicielu i jednym wdrożeniu:** dla dzisiejszych
sześciu przełączników — nie. Żaden z nich nie potrzebuje „włączone dla mnie,
wyłączone dla reszty" — to globalne ustawienia operacyjne (czy automat
moderacyjny działa, czy digest wychodzi), nie wydania funkcji stopniowane po
koncie. Restart na Railwayu przy zmianie zmiennej środowiskowej jest tu
kosztem, nie problemem: to i tak redeploy, który już się dzieje przy każdej
zmianie kodu. Konkretny przykład z issue #21 — 3-krokowy kreator przepisu —
**jest już na produkcji bez żadnej flagi**, jako osobna trasa
(`routes/web.php:195-196`, `recipes.create` obok `recipes.create.simple`),
więc to nie jest dziś przypadek czekający na Pennanta.

**Rekomendacja: PÓŹNIEJ. Próg powrotu: pierwsza funkcja z `ROADMAP.md` V1**
(grupy, forki, planer, import AI) **trafia w fazę aktywnego pisania kodu na
scalonym `main`**, albo pojawia się konkretna potrzeba pokazać niedokończoną
funkcję tylko administratorowi przed pełnym wydaniem. Koszt wdrożenia jest
niski i nie rośnie od czekania (jedna tabela `features`, sterownik
`database` domyślny[^3], zero Redisa — `AGENTS.md` §3 nie jest tu naruszone),
więc nie ma powodu wchodzić wcześniej, tylko dlatego że wejście jest tanie.

### 3. `spatie/laravel-activitylog` → **NIE**, bez warunku powrotu

Dziś: własny, celowo minimalistyczny `AuditLogEntry` + tabela `audit_log`
(`app/Models/AuditLogEntry.php`) — append-only, aktor, akcja, podmiot, IP
**wyłącznie jako hash**, metadane dobierane jawnie. `record()` przyjmuje
`action` i `subject`, **nie ma parametru na treść modelu** — więc nie da się
przez pomyłkę przekazać mu wpisu, e-maila ani hasła. Wołany z 16 miejsc w
`app/Domain/*/Actions` — to jest przyjęty wzorzec, nie martwy kod. Retencja:
`kuking:sprzataj-audyt` (`app/Console/Commands/SprzatajAudyt.php`) kasuje
wpisy starsze niż `config('kuking.audit_log.retention_months')` miesięcy,
**z wyjątkiem** zamkniętej listy `AuditLogEntry::NIGDY_NIE_KASUJ`
(`account.data_erased`, `account.delete_requested`, `account.delete_cancelled`
— `app/Models/AuditLogEntry.php:66-70`), bo to jedyny dowód w całej bazie, że
prawo do usunięcia konta (RODO art. 17) zostało faktycznie wykonane, albo że
ktoś zgłosił i cofnął takie żądanie.

**Co dałby pakiet:** gotowe śledzenie zmian modeli (`LogsActivity`,
`activity()`), jedną tabelę `activity_log`[^4] zamiast ręcznych wywołań
`AuditLogEntry::record()` w 16 miejscach.

**Co by zabrał — i to jest sedno odpowiedzi „nie":** domyślny setup pakietu
loguje **wartości pól** (`logAll()` + `logOnlyDirty()`); da się to zawęzić
przez `logOnly()`/`logExcept()`[^5], **ale to jest lista do ręcznego
utrzymania per model**. Domyślny kierunek ryzyka się odwraca: dziś trzeba
**świadomie dopisać coś** do `metadata`, żeby trafiło do logu; z pakietem
trzeba **świadomie wykluczyć pole**, inaczej nowa kolumna na modelu (np.
`Post::$body`, `User::$email`) domyślnie wejdzie do `properties` następnym
razem, gdy ktoś zapomni dopisać ją do `logExcept()`. To jest dokładnie
sytuacja, przed którą broni się `AGENTS.md` §7, wybierając jawne nazwane
metody zamiast ogólnego `$fillable` — automatyczne logowanie zmian modelu to
automatyczne logowanie **cudzych treści**, a w logu audytowym Kuking treści
użytkowników nie ma nigdy, z zasady.

**Czy migracja istniejących wpisów byłaby bezpieczna:** nie, z dwóch
niezależnych powodów.

1. Trzy kategorie z `NIGDY_NIE_KASUJ` są chronione dziś **jedną zamkniętą
   listą w kodzie PHP**, czytaną przez `PrzedawnioneWpisyAudytu::posprzataj()`.
   Przeniesienie tych wierszy do `activity_log` wyprowadza je spod tej
   ochrony w tabelę z **własnym**, generycznym poleceniem retencji pakietu
   (`activitylog:clean`[^6], kasującym po wieku, bez pojęcia „kategorii
   dowodowej"). Odtworzenie tej samej ochrony nad pakietem oznaczałoby
   napisanie tego samego zamkniętego wyjątku po raz drugi, na wierzchu
   zależności — więcej kodu do utrzymania, nie mniej.
2. Migracja jednorazowa musiałaby przepisać `actor_id`/`action`/
   `subject_type`/`subject_id`/`metadata` na kształt `causer`/`subject`/
   `description`/`properties`/`event` pakietu. To jest dokładnie miejsce,
   w którym trzeba by ręcznie przejrzeć **każdy** historyczny wiersz, żeby
   upewnić się, że żadne `metadata` z 16 miejsc wywołania nigdy nie
   przemyciło czegoś, czego być tam nie powinno — czyli dokładnie tę pracę,
   którą pakiet miał oszczędzić.

Jedyny realny kandydat na „historię zmian pojedynczego modelu" — przepisy —
ma już dedykowane, celowo zaprojektowane rozwiązanie: `recipe_versions` /
`RecipeVersion` (`app/Models/RecipeVersion.php`).

**Rekomendacja: NIE, dla ogólnego audytu i dla historii zmian modeli, bez
warunku powrotu.** Żadne z dwóch zastosowań nie ma dziś nienazwanej potrzeby,
a domyślny profil bezpieczeństwa pakietu jest gorszy niż to, co już działa.
Gdyby to się kiedyś zmieniło, powodem musiałby być nowy, nazwany przypadek —
nie „mniej kodu do utrzymania" w oderwaniu od tego, co ten kod dziś chroni.

### Kontrola ujemna (dowód, że nowy test coś sprawdza)

| Krok | Stan `users_role_check` | Wynik `test_baza_odrzuca_role_spoza_trzech_dozwolonych_wartosci` |
|---|---|---|
| 1. Bazowo | obecny (migracja bez zmian) | **zielony** — `DB::table('users')->update(['role' => 'superadmin'])` rzuca `QueryException` |
| 2. Zepsute | `DB::statement(...)` z `CHECK` zakomentowany, `migrate:fresh` na bazie testowej | **czerwony** — „Failed asserting that exception of type Illuminate\\Database\\QueryException is thrown." |
| 3. Przywrócone | ograniczenie z powrotem w migracji | **zielony**, cały plik `NadanieRoliTest` (10/10) przechodzi |

### Co zostaje nierozstrzygnięte, jeśli próg kiedyś nadejdzie

`docs/INSPIRATION_DECISIONS.md` §10 (poz. 10.1–10.4) ma te same cztery
werdykty z odesłaniem do `docs/research/PAKIETY.md` — ten wpis jest ich
formalnym potwierdzeniem w dzienniku decyzji, nie nową analizą. Kolejny
agent, który natrafi na pytanie „czy wziąć jeden z tych trzech pakietów",
ma zacząć **tutaj**, nie od nowa.

**Zmiana wymaga:** dla (1) trzeciego moderatora albo potrzeby rozdzielenia
uprawnień w jednej roli; dla (2) pierwszej funkcji V1 wchodzącej w aktywne
pisanie kodu na `main`; dla (3) — nic przewidzianego, próg nie istnieje.

📄 `app/Models/User.php` · `app/Policies/UserPolicy.php` ·
`app/Console/Commands/NadajRole.php` ·
`database/migrations/0001_01_01_000001_create_users_table.php` ·
`config/kuking.php` (`account.registration_open`, `digest.wlaczony`, `moderation.sygnaly.wlaczone`, `moderation.model.*`) ·
`app/Models/AuditLogEntry.php` · `app/Console/Commands/SprzatajAudyt.php` ·
`app/Domain/Compliance/PrzedawnioneWpisyAudytu.php` ·
`app/Models/RecipeVersion.php` ·
`tests/Feature/OdwolanieOdDecyzjiTest.php` ·
`tests/Feature/NadanieRoliTest.php` ·
`docs/research/PAKIETY.md` · `docs/INSPIRATION_DECISIONS.md` §10 ·
issue #21

---

## D-077 · Tygodniowe podsumowanie ma trwały klucz idempotencji w bazie: rezerwacja `(osoba, tydzień)` PRZED wysłaniem, a przy awarii wolimy pominięcie niż duplikat

**Data:** 10 września 2026 · Audyt drugiej warstwy QUEUE-01 / MAIL-02 /
RACE-04 (P1) · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Rdzeń obowiązuje i ma
> pokrycie: rezerwacja `(osoba, tydzień)` PRZED `Mail::queue()`, klucz główny
> `(user_id, week_start)` i `CHECK` na poniedziałek
> (`database/migrations/2026_09_10_400000_create_weekly_digest_sends_table.php:146,152`).
> Nieaktualna jest §8 „Czego ta decyzja NIE dotyka". Zdanie
> „**`DziennyBudzetListow` zostaje bez zmian** … pętla woła budżet tak jak
> dotąd, **po udanej rezerwacji tygodnia**" opisuje odwrotną kolejność niż
> kod: `app/Console/Commands/WyslijPodsumowaniaTygodnia.php:226` rezerwuje
> budżet dobowy, `:282` dopiero tydzień, a `:287` zwalnia miejsce przy
> niepowodzeniu — pod nagłówkiem „KOLEJNOŚĆ TYCH DWÓCH REZERWACJI JEST
> MERYTORYCZNA" (`:233-254`), który tę zmianę uzasadnia. Sam
> `DziennyBudzetListow` też „bez zmian" nie został — D-076 dołożyła mu
> `sprobujZarezerwowac()` i `zwolnij()`.

### 1. Co dokładnie było zepsute — kolejność, nie brak sprawdzenia

`WyslijPodsumowaniaTygodnia` robiło dla każdej osoby w pętli:

```text
1. Mail::to(...)->queue($list)    ← SKUTEK ZEWNĘTRZNY JUŻ SIĘ STAŁ
2. $budzetDnia->zajmij()
3. sygnał WEEKLY_DIGEST_SENT
4. $wyslane[] = $osoba
```

a `OdbiorcyDigestu::oznaczWyslane($wyslane)` — jedyny zapis mówiący „ta osoba
jest obsłużona" — wykonywało się **dopiero po całej pętli**, jednym
zapytaniem. Awaria po zakolejkowaniu N wiadomości, ale przed tym zbiorczym
zapisem, zostawiała N listów w kolejce i **zero** śladu w bazie. Następny
przebieg kwalifikował te same osoby ponownie i pisał do nich drugi raz.

Okno tej awarii miało rozmiar CAŁEJ PACZKI — do sześćdziesięciu osób
(`kuking.digest.dzienny_limit`) — i nie było hipotetyczne: wysyłka trwa około
czterdziestu minut (odstęp 20 s na list), chodzi w tym samym procesie co
serwer WWW (`Schedule::call()`, bo `proc_open` jest wyłączone) i mieszka na
kontenerze Railway, który wolno zrestartować w każdej chwili.

**`withoutOverlapping()` tego nie chronił i nigdy nie chronił.** Zapobiega
dwóm przebiegom JEDNOCZEŚNIE, a duplikat powodował przebieg KOLEJNY — po
awarii. To jest różnica, którą łatwo przeczytać jako „już się tym zajęliśmy",
i komentarz w `routes/console.php` faktycznie tak brzmiał. Został poprawiony.

### 2. Dlaczego to boli bardziej niż zwykły duplikat

Ekran `/ustawienia/prywatnosc` obiecuje **jeden e-mail tygodniowo, nigdy
więcej**. Ta obietnica jest złożona ludziom 50+, którzy nie chcą, żeby serwis
ich zasypywał, i którzy przy drugim identycznym liście w tym samym tygodniu
mają prawo pomyśleć, że coś jest zepsute albo że to spam. Digest jest do tego
funkcją **na zgodę** (art. 6 ust. 1 lit. a RODO): wysyłka ponad obiecany rytm
podważa to, na co ktoś się zgodził, a nie tylko psuje wrażenie.

Ma to też cenę techniczną, której nie widać z ekranu: każdy duplikat zjada
list z wiadra 300 na dobę, dzielonego z potwierdzeniami rejestracji (D-057).
Duplikat biuletynu potrafi więc zamknąć komuś rejestrację.

### 3. Decyzja: bariera w bazie, nie sprawdzenie w PHP

Nowa tabela `weekly_digest_sends` z **kluczem głównym (a więc unikalnym) na
parze `(user_id, week_start)`** i wiersz zajmowany **przed** `Mail::queue()`:

```text
1. INSERT weekly_digest_sends (osoba, poniedziałek tygodnia)
   + UPDATE users.weekly_digest_sent_at       ← JEDNA TRANSAKCJA
2. dopiero teraz Mail::to(...)->queue($list)
3. budżet, sygnał
```

Konflikt unikalności znaczy „ta osoba ma ten okres obsłużony" i wtedy po
prostu ją pomijamy — bez błędu, bez listu, z jednym zdaniem na wyjściu
komendy, bo to jedyny moment, w którym widać, że poprzedni przebieg nie
doszedł do końca.

**Dlaczego constraint, a nie `exists()`.** Sprawdzenie w PHP jest odczytem,
po którym następuje zapis, a między nimi jest luka. Dwa przebiegi (dwa
kontenery, albo ręczny przebieg właściciela obok harmonogramu po wygaśnięciu
blokady) przechodzą oba przez ten sam `SELECT`, oba widzą „jeszcze nie
wysłano" i oba wysyłają — to jest RACE-04. `UNIQUE` tej luki nie ma. `exists()`
w PHP zostaje, ale jako sposób na ŁADNE zachowanie, nie jako gwarancja.

**Dlaczego OBIE warstwy zostają.** `weekly_digest_sends` mówi „najwyżej jeden
list na tydzień kalendarzowy", `users.weekly_digest_sent_at` — „nie częściej
niż raz na siedem dni" plus kolejność „kto czeka najdłużej". Sam tydzień
kalendarzowy pozwoliłby na list w niedzielę i w poniedziałek; sam odstęp jest
porównaniem z luką. Kolumna nadal istnieje i nadal jest potrzebna — zmieniło
się to, że jest zapisywana **osobno dla każdej osoby i przed wysłaniem**.

### 4. Okres to DATA PONIEDZIAŁKU w strefie człowieka, nie numer tygodnia ISO

Numer tygodnia sam z siebie nie jest identyfikatorem: `2026-12-28` należy do
tygodnia 1 **roku 2027**, więc numer wymaga pary (rok ISO, tydzień) — a klucz
idempotencji zapisany niepełny przestaje być unikalny. Data poniedziałku to
jedna kolumna `date`: porównywalna, sortowalna, czytelna w zrzucie bazy
i zgodna z tym, co w PostgreSQL znaczy `date_trunc('week', …)` (tygodnie
Postgresa zaczynają się w poniedziałek).

Liczy ją `App\Support\Czas::poczatekTygodniaData()`, a nie `now()`, i to nie
jest formalność: `app.timezone` musi zostać UTC (patrz komentarz klasy
`Czas`), a poniedziałek UTC zaczyna się w Polsce w niedzielę o 22:00.
Przebieg uruchomiony w poniedziałek nad ranem trafiałby więc do tygodnia
POPRZEDNIEGO — czyli do klucza, który dla części osób jest już zajęty.

Tydzień jest liczony **raz na cały przebieg**, przed pętlą. Gdyby każda osoba
pytała o „teraz" osobno, paczka schodząca przez północ z niedzieli na
poniedziałek rozpadłaby się na dwa różne klucze.

Do tego CHECK w bazie: `extract(isodow from week_start) = 1`. Bez niego data
ze środka tygodnia dałaby tej samej osobie dwa różne, oba wolne klucze
w jednym tygodniu — czyli dwa listy przy nietkniętym `UNIQUE`. Bariera bez
tego CHECK-a broni się przed powtórzeniem, ale nie przed pomyłką w kluczu.

**Kalendarzowy tydzień nikogo nie opóźnia.** Dzień `x` i dzień `x + 7` zawsze
mają różne poniedziałki, więc bariera nie blokuje wysyłki, na którą odstęp
siedmiu dni już pozwala. Dwie warstwy razem dają zdanie mocniejsze niż każda
z osobna: **najwyżej jeden list na tydzień kalendarzowy i nie częściej niż raz
na siedem dni.**

### 5. Wybór, którego nie da się uniknąć: rezerwacja została, wysyłka padła

To jest prawdziwy rozstrzygnięty wybór, nie szczegół implementacji, więc jest
nazwany wprost:

> **Rezerwacja ZOSTAJE. Ta osoba nie dostaje listu za ten tydzień.**

Nie da się mieć naraz „nikt nie dostanie dwa razy" i „nikt nie zostanie
pominięty", bo po wyjściu z `Mail::queue()` nie wiemy, czy wiadomość weszła do
kolejki. Wycofanie rezerwacji przy złapanym wyjątku wyglądałoby na
ostrożność, a byłoby przywróceniem usterki dokładnie w tym jednym przypadku,
w którym stan jest niejednoznaczny — a niejednoznaczny jest zawsze, bo proces
może padnąć MIĘDZY udanym `queue()` a naszym `catch`.

Uzasadnienie kierunku, a nie przemilczenie:

1. **Przy tygodniowym podsumowaniu pominięcie jest odwracalne, a duplikat
   nie.** Kto nie dostał listu, dostanie go za tydzień i najprawdopodobniej
   nie zauważy — treść to trzy pozycje z ostatnich siedmiu dni, nie termin
   ani nie decyzja. List wysłany drugi raz jest u człowieka w skrzynce na
   zawsze.
2. **Digest jest funkcją powrotu, nie funkcją krytyczną.** Nic się nie psuje
   w serwisie, gdy list nie przyjdzie. Psuje się, gdy przyjdzie dwa razy.
3. **Ta strona pomyłki jest już wybrana w tym samym miejscu** — przy
   `oznaczWyslane()` stoi od D-057: „lepiej, żeby ktoś dostał o jeden list za
   mało, niż żeby dostał trzy". Odwrócenie jej tylko przy awarii dałoby dwie
   sprzeczne reguły w jednej pętli.
4. **Skala jest znana i mała.** Pominięcie dotyczy najwyżej tych osób, dla
   których przebieg padł — nie całej paczki, bo rezerwacja jest per osoba.
   Wcześniej duplikat dotyczył wszystkich obsłużonych do momentu awarii.

Świadoma cena: nie ma sposobu, żeby dowiedzieć się z bazy, którym osobom list
przepadł — wiersz rezerwacji wygląda identycznie dla „wysłano" i dla „padło
po rezerwacji". Rozróżnienie wymagałoby stanu wiersza i potwierdzeń doręczenia
od dostawcy, czyli dokładnie tego, czego produkt nie chce (#204). Widać za to
liczbę: komenda wypisuje, ile osób pominięto jako już obsłużone.

### 6. Dlaczego NIE transactional outbox

Outbox rozwiązuje inny problem: **at-least-once** przy niepewnym transporcie
(zapisz zamiar w tej samej transakcji co dane, osobny proces dowozi i ponawia).
Tutaj potrzebne jest **at-most-once na parę (osoba, tydzień)** — i to daje
jeden indeks unikalny, bez ani jednej nowej ruchomej części.

Co by doszło z outboxem: tabela z zamiarem wysyłki, proces ją opróżniający
(a więc druga kolejka przed kolejką Laravela), retencja tej tabeli, obsługa
zamiarów zawieszonych i nowy tryb awarii „outbox rośnie, nikt nie zauważył".
Za to nie doszłoby ani jedno powiadomienie więcej: pominięcie po awarii
zostaje pominięciem, bo o ponawianiu listu rozstrzyga §5, a nie mechanizm.

AGENTS.md §3 mówi wprost: bez zmierzonej, udokumentowanej potrzeby nie
dokładamy mechanizmów. Pomiaru mówiącego, że tracimy listy w kolejce, nie ma
— jest pomiar mówiący, że wysyłamy je dwa razy. Na to wystarcza `UNIQUE`.

**Zmiana wymaga:** zmierzonej straty listów w kolejce (np. z `failed_jobs`
poczty, PR #253), której nie da się przyjąć jako „ta osoba czeka tydzień".

### 7. Rollback — i dlaczego `down()` nie przywraca stanu groźnego po cichu

`down()` kasuje tabelę. Nie ginie ani jedno słowo od człowieka i nie ginie
pamięć o wysyłce (`users.weekly_digest_sent_at` zostaje) — ale **ginie
bariera**. Po wycofaniu jedyną ochroną przed drugim listem zostaje porównanie
w PHP, czyli dokładnie ten mechanizm, którego luka jest powodem tej migracji.
Stan po rollbacku jest więc stanem sprzed poprawki, tylko z mniejszym oknem
awarii (znacznik jest już zapisywany per osoba, nie po pętli).

Dlatego:

- rollback robi się **wyłącznie razem z `KUKING_DIGEST_WLACZONY=false`**,
  nigdy „przy okazji" innej zmiany;
- kolejność: **najpierw kod, potem migracja.** Nowy kod bez tabeli pada na
  pierwszej osobie i nie wysyła nikomu nic — kierunek awarii bezpieczny, ale
  wysyłka staje, więc wycofanie samej migracji jest wyłączeniem digestu
  okrężną drogą. Do wyłączania jest zmienna środowiskowa.

### 8. Czego ta decyzja NIE dotyka

- **`DziennyBudzetListow` zostaje bez zmian.** Atomowa rezerwacja dobowego
  budżetu jest osobnym zadaniem (gałąź `claude/atomowy-budzet-listow`);
  pętla woła budżet tak jak dotąd, po udanej rezerwacji tygodnia.
- **Treść listu i harmonogram** (codziennie 08:30) — nietknięte.
- **`KUKING_DIGEST_WLACZONY`** nadal domyślnie `false`; włącza właściciel.
- **Żadnego śledzenia otwarć ani doręczeń** — otwarta sprawa #204, produkt
  świadomie tego nie chce. Nowa tabela nie jest do tego furtką: nie ma w niej
  stanu wiersza ani niczego o doręczeniu.
- **Droga listu próbnego `--tylko` omija barierę świadomie.** Flaga istnieje,
  żeby właściciel zobaczył list TERAZ, i już dziś pomija odstęp tygodniowy.
  Gdyby zajmowała klucz tygodnia, drugi list próbny w tym samym tygodniu byłby
  niemożliwy, a konto użyte do próby straciłoby prawdziwe podsumowanie.
  Bariera pilnuje wysyłki masowej; jednego adresu wpisanego ręcznie w konsoli
  pilnuje człowiek, który tę komendę wpisał.

📄 `database/migrations/2026_09_10_400000_create_weekly_digest_sends_table.php` ·
`app/Domain/Digest/OdbiorcyDigestu.php` ·
`app/Console/Commands/WyslijPodsumowaniaTygodnia.php` ·
`app/Support/Czas.php` · `routes/console.php` ·
`tests/Feature/DigestNieWysylaDwaRazyTest.php` ·
`docs/DATABASE.md` (sekcja `weekly_digest_sends`) ·
D-057 · audyt `docs/research/audyt-2026-09-10/` (QUEUE-01, MAIL-02, RACE-04)

---

## D-078 · Sygnał digestu mówi „zakolejkowano", a „jeden aktywny eksport na konto" pilnuje baza, nie `exists()`

**Data:** 10 września 2026 · **Audyt drugiej warstwy z 10.09.2026, ustalenia
MAIL-03 oraz QUEUE-04 / RACE-05 (oba P2)** · Status: **obowiązuje**

Dwie niezależne sprawy o tym samym charakterze: **kod twierdził coś
mocniejszego, niż faktycznie zaszło.** Raz w nazwie zdarzenia analitycznego,
raz w obietnicy schematu, której schemat nie składał.

### 1. `weekly_digest_sent` → `weekly_digest_queued` (MAIL-03)

**Stan sprzed zmiany, sprawdzony w pliku:**
`WyslijPodsumowaniaTygodnia::handle()` zapisywał sygnał
`ZapiszSygnal::WEEKLY_DIGEST_SENT` **jedną linijkę po `Mail::queue()`** —
przed jakimkolwiek kontaktem workera z dostawcą poczty.

Nazwa sklejała w jedno trzy różne zdarzenia: **zakolejkowano**, **dostawca
przyjął**, **doręczono**. Kuking ma prawdziwy sygnał tylko o pierwszym.
Skutek był mierzalny i przewrotny: list, który przewróci się w workerze
i wyląduje w `failed_jobs`, **nadal był policzony jako wysłany** — czyli
metryka zawyżała skuteczność wysyłki najbardziej właśnie wtedy, gdy wysyłka
przestawała działać. To jest ta sama klasa usterki co dryf dokumentacji
(patrz `docs/research/audyt-2026-09-10/SPRAWDZENIE.md`): liczba nie jest
fałszywa przez pomyłkę w kodzie, tylko przez nazwę obiecującą więcej, niż kod
może wiedzieć.

**Nazwa jest angielska, `snake_case`** — `AGENTS.md` §11 mówi to wprost
o zdarzeniach analitycznych, a pozostałe nazwy w tym zbiorze
(`photo_upload_failed`, `search_performed`, `weekly_digest_unsubscribed`)
trzymają tę konwencję. Polskie `zakolejkowano` wyłamywałoby jedną nazwę
z ustalonego podziału (nazwy po angielsku, `properties` po polsku).

#### Migracja przepisująca stare wiersze, nie dwie nazwy przy odczycie

To była jedyna realna decyzja w tej połowie i rozstrzygnęło ją **sprawdzenie,
kto tę nazwę czyta. Nikt.** Na `main` `weekly_digest_sent` znały wyłącznie:
`ZapiszSygnal` (zapis), komenda wysyłkowa (zapis) i testy. `kuking:raport`
liczy powroty z `users.ostatnio_widziany_at`, nie z `product_signals`; żaden
ekran panelu nie sięga do `signal_name`; próg z `RETENTION_LOOPS.md` §6
wiersz 5 (wypisy > 1% na wysyłkę) nie jest dziś liczony przez żaden kod.
**Nie ma więc panelu, który po tej zmianie przestaje cokolwiek pokazywać** —
i to jest powód, dla którego dwie nazwy przy odczycie byłyby kosztem bez
korzyści: rozgałęziałyby każde przyszłe zapytanie, a pierwszy człowiek, który
napisze `where('signal_name', 'weekly_digest_queued')` bez tej gałęzi,
dostałby po cichu za małą liczbę.

Migracja `2026_09_10_400000_rename_weekly_digest_sent_signal` robi więc trzy
kroki w tej kolejności: poszerza CHECK o obie nazwy, przepisuje wiersze
(`UPDATE`, nie `DELETE`), zwęża CHECK do nowej. Odwrotna kolejność odbiłaby
`UPDATE` o ograniczenie, którego wiersze jeszcze nie spełniają. `down()` jest
symetryczne i też nie kasuje wierszy — cofnięcie kodu przywraca kod, który
tę nazwę zapisywał, a kasowanie telemetrii przy rollbacku byłoby karą za
cofnięcie wdrożenia. Na produkcji takich wierszy jest prawdopodobnie zero
(digest jest domyślnie wyłączony, D-057 §8), ale migracja tego nie zakłada.

#### Czego świadomie NIE zrobiliśmy: `delivered` i `opened`

Nie emitujemy ani jednego, ani drugiego, i **nie wracamy do pikseli
śledzących, żeby mieć ładniejszą metrykę.** „Doręczono" wymaga webhooka
o odbiciach od dostawcy — osobna, niezrobiona robota (`docs/decyzje/POCZTA.md`
§5 pkt 6). „Otwarto" wymaga niewidzialnego obrazka w treści listu, czyli
zapisywania, kiedy konkretna osoba czyta pocztę i z jakiego adresu IP.
Polityka prywatności obiecuje wprost tego nie robić, transport ma własny
wyłącznik śledzenia u dostawcy (`X-TRACKING-OFF`) domyślnie WŁĄCZONY, a sprawa
jest otwarta jako **#204** i produkt świadomie tego nie chce. Zatrzymujemy się
na uczciwym „zakolejkowano".

Pilnuje tego test, nie tylko zdanie w tym wpisie:
`SygnalDigestuMowiZakolejkowanoTest::test_zamkniety_zbior_nazw_nie_obiecuje_doreczenia_ani_otwarcia`
czyta CHECK wprost z `pg_constraint` i przechodzi po stałych `ZapiszSygnal`
przez refleksję. Nazwa mówiąca „doręczono", „otwarto" albo „kliknięto" oblewa
go. Gdy prawdziwy webhook o odbiciach kiedyś powstanie, `delivered` zdejmuje
się z tamtej listy **jawnie**, jedną decyzją — śledzenia otwarć i kliknięć
nie zdejmuje się wcale.

### 2. Jeden aktywny eksport danych na konto — indeks częściowy (QUEUE-04 / RACE-05)

**Stan sprzed zmiany, sprawdzony w pliku:**
`DataSettingsController::requestExport()` robił `exists()` na stanach
`queued`/`processing`, a potem **osobny `INSERT`**. Schemat nie wymuszał
niczego: `data_exports` miało CHECK na `status` i indeks `(user_id,
created_at)`, ale żadnego ograniczenia unikalności.

Między `SELECT`-em a `INSERT`-em jest okno. Przy izolacji `read committed`
dwa równoległe żądania widzą „nie ma aktywnego eksportu" **jednocześnie**
i oba wstawiają swój wiersz, żadne nie czeka. Skutkiem są **dwa ciężkie
eksporty tego samego konta**: `GenerateUserExport` pakuje wszystkie zdjęcia,
ma 15 minut limitu czasu, chodzi na kolejce `low` przy jednym workerze — plus
dwa listy do jednej osoby z tego samego dobowego wiadra 300 wiadomości.
Wejściem jest podwójne kliknięcie „Zamów swoje dane", a **przy grupie 60+
dwuklik jest scenariuszem typowym, nie skrajnym** (`docs/UX_50_PLUS.md`,
`docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`).

```sql
CREATE UNIQUE INDEX data_exports_one_active_per_user
    ON data_exports (user_id)
 WHERE status IN ('queued', 'processing');
```

**`exists()` W PHP ZOSTAJE — ale robi coś innego niż indeks.** `exists()` daje
ŁADNY KOMUNIKAT, indeks daje GWARANCJĘ. `AGENTS.md` §6: „prawdziwe klucze obce
i prawdziwe CHECK-i w bazie — walidacja w PHP jest dodatkiem, nie
zamiennikiem". Tutaj było odwrotnie. To jest dokładnie ten przypadek, w którym
PostgreSQL potrafi wyrazić inwariant, a `exists()` w PHP nie potrafi.

**`lockForUpdate()` NIE jest tu rozwiązaniem i nie został dodany** — `SELECT
... FOR UPDATE`, który nie zwrócił żadnego wiersza, nie blokuje niczego. To
wstawienie fantomu, nie konflikt na wierszu (zmierzone przy
`reports_one_open_per_pair`, ADR §1.4.2).

**Indeks jest CZĘŚCIOWY, bo inwariant brzmi „jeden AKTYWNY", nie „jeden
w historii".** RODO art. 15 nie jest jednorazowe, a ekran ustawień pokazuje
pięć ostatnich paczek. Zwykły `UNIQUE (user_id)` zamieniłby usterkę
współbieżności na usterkę produktową: człowiek nie mógłby już nigdy zamówić
swoich danych po raz drugi.

**Konflikt kończy się TYM SAMYM zdaniem co zwykły dwuklik, nigdy 500.**
Kontroler łapie `UniqueConstraintViolationException`, upewnia się, że aktywny
eksport naprawdę istnieje (inaczej wyjątek leci dalej — to samo, co robi
`ReportContent`), i oddaje `back()->with('status', …)` z jedną, wspólną
treścią. Człowiek, który kliknął dwa razy, ma zobaczyć to samo co ten, który
kliknął raz; ekran błędu byłby karą za dwuklik. `INSERT` jest owinięty
w `DB::transaction()` — nie z ostrożności, a dlatego, że na PostgreSQL nieudany
`INSERT` wewnątrz szerszej transakcji zatruwa całą transakcję i sprawdzenie po
konflikcie odbiłoby się o „current transaction is aborted" (ta sama pułapka co
w `ZapiszSygnal`).

**Migracja odmawia, gdy w bazie już leżą dwa aktywne eksporty jednego konta**
— bo `CREATE UNIQUE INDEX` i tak by się o nie odbił, tylko komunikatem
PostgreSQL, z którego nie wynika, co zrobić. Komunikat migracji mówi: zostaw
NAJSTARSZY aktywny wiersz na konto, nadmiarowe skasuj — gotowy `DELETE` stoi
w komentarzu migracji. Kasowanie jest tu bezpieczne, **w odróżnieniu od
`reports`**, i to jest osobna decyzja: wiersz w stanie aktywnym nie ma jeszcze
`object_key` ani `disk` (nie ma osieroconego pliku),
`GenerateUserExport::handle()` przy braku wiersza po prostu wraca, a paczka
z pozostawionego wiersza jest bajt w bajt tą samą paczką. To nie jest sprawa
z terminem odpowiedzi z DSA art. 16.

### Ryzyka i rollback

| Zmiana | Rollback | Co wraca |
|---|---|---|
| Nazwa sygnału | `migrate:rollback` na `2026_09_10_400000_*` — przepisuje wiersze z powrotem, nic nie kasuje | stara, nieprawdziwa nazwa; cofać razem z kodem, inaczej w tabeli mieszają się obie |
| Indeks eksportu | `DROP INDEX IF EXISTS` — bezstratnie, żaden wiersz nie ginie | `exists()` łapie zwykły dwuklik, baza nie broni niczego, `catch` staje się gałęzią, w którą nic nie wchodzi |

Największe ryzyko po tej stronie to **migracja odmawiająca na produkcji**
przy istniejących duplikatach. Jest świadome: lepiej zatrzymać wdrożenie
komunikatem mówiącym co zrobić, niż wdrożyć się w połowie.

### Sprostowanie po drodze

`docs/DATABASE.md` twierdził przy `product_signals.occurred_at`, że dla
`weekly_digest_sent` kolumna jest **czytana jako licznik dobowego limitu
poczty**. Nieprawda — sprawdzone w kodzie: sufit liczy
`App\Domain\Security\DziennyBudzetListow`, a ten trzyma licznik w **cache**
i do `product_signals` nie sięga ani razu. Poprawione tam na miejscu.

### Zmiana wymaga

Dla (1) — prawdziwego sygnału od dostawcy poczty, jawnie zdjętego z listy
zakazanych cząstek w teście, plus wpisu tutaj. Śledzenia otwarć i kliknięć
nie dotyczy: to obietnica z polityki prywatności, nie brak funkcji.
Dla (2) — zmiany słownika stanów `data_exports`; wtedy warunek `WHERE` indeksu
i lista w `maAktywnyEksport()` muszą pójść razem, inaczej rozjadą się cicho
(obie gałęzie kończą się tym samym ekranem).

📄 `app/Console/Commands/WyslijPodsumowaniaTygodnia.php` ·
`app/Domain/Analytics/ZapiszSygnal.php` ·
`app/Http/Controllers/Settings/DataSettingsController.php` ·
`database/migrations/2026_09_10_400000_rename_weekly_digest_sent_signal.php` ·
`database/migrations/2026_09_10_400100_one_active_data_export_per_user.php` ·
`tests/Feature/SygnalDigestuMowiZakolejkowanoTest.php` ·
`tests/Feature/JedenAktywnyEksportNaKontoTest.php` ·
`tests/Feature/Wyscigi/EksportDanychRaceTest.php` ·
`tests/Feature/TygodniowePodsumowanieTest.php` ·
`docs/DATABASE.md` (`data_exports`, `product_signals`) ·
audyt `docs/research/audyt-2026-09-10/` (MAIL-03, QUEUE-04 / RACE-05) ·
issue #204 (otwarta: śledzenie otwarć — nie robimy)

[^1]: [spatie/laravel-permission — migracja `create_permission_tables.php.stub`](https://raw.githubusercontent.com/spatie/laravel-permission/main/database/migrations/create_permission_tables.php.stub) — pięć `Schema::create()`: `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`.
[^2]: [spatie/laravel-permission — `config/permission.php`](https://raw.githubusercontent.com/spatie/laravel-permission/main/config/permission.php) — `'store' => 'default'`, `'expiration_time' => DateInterval::createFromDateString('24 hours')`.
[^3]: [Laravel 13.x Docs — Pennant](https://laravel.com/docs/13.x/pennant) — sterownik `database` jest domyślnym mechanizmem trwałego zapisu wartości flag; migracja pakietu tworzy tabelę `features`.
[^4]: [spatie/laravel-activitylog — README](https://raw.githubusercontent.com/spatie/laravel-activitylog/main/README.md) — jedna tabela `activity_log`, kolumny `subject_id`/`subject_type`, `causer_id`/`causer_type`, `description`, `properties`, `event`.
[^5]: `spatie/laravel-activitylog` dokumentacja, sekcja „Log Options" (`docs/advanced-usage/log-options.md` w repozytorium pakietu) — `logOnly()`/`logExcept()`/`dontLogEmptyChanges()`.
[^6]: [spatie/laravel-activitylog — README, sekcja „Clean log"](https://raw.githubusercontent.com/spatie/laravel-activitylog/main/README.md) — komenda `activitylog:clean`, kasuje wpisy starsze niż skonfigurowana liczba dni, bez pojęcia kategorii wyłączonych z kasowania.

---

## D-069 · Wejście kontem Google: własny kod, `email_verified` jako warunek, konta łączymy tylko za zgodą człowieka, bez Turnstile

**Data:** 10 września 2026 · Issue #258 · **Decyzja właściciela co do terminu**
· Status: **obowiązuje**

> **JEDNA RZECZ W TYM WPISIE ZOSTAŁA ZMIENIONA, ZANIM COKOLWIEK POSZŁO NA
> PRODUKCJĘ: gdzie leży powiązanie.** Rozstrzygnięcie 4 mówi niżej „w bazie
> zostają DWIE kolumny na `users`" i wskazuje tabelę `tozsamosci_zewnetrzne`
> jako właściwy kształt „przy drugim dostawcy". Drugi dostawca (Facebook)
> został w tym czasie **zamówiony przez właściciela wprost**, więc próg
> powrotu został przekroczony jego własnym kryterium — powiązania leżą od
> tej pory w tabeli `tozsamosci_zewnetrzne`: patrz **D-098**. Reszta tego
> wpisu — trzy reguły łączenia kont, `email_verified` jako warunek, zakres
> `openid email profile`, brak Turnstile, brak sprawdzania podpisu tokenu —
> **obowiązuje bez zmian**, a D-098 jej nie podważa. Czytając niżej
> „`google_sub`" i „`google_connected_at`", czytaj „wiersz
> w `tozsamosci_zewnetrzne`".

Kuking wpuszcza na konto **kontem Google**. Droga jest **dodatkowa**, nigdy
jedyna: hasło i wiadomość z linkiem (D-056) zostają na ekranie logowania
obok niej. Adresy: `/wejdz/google`, `/wejdz/google/wroc`,
`/wejdz/google/domknij`, `/wejdz/google/polacz`.

### DLACZEGO TERAZ, PRZED PODPISANIEM UMOWY POWIERZENIA

Właściciel rozstrzygnął, że funkcja wchodzi **przed kampanią startową**,
świadomie przyjmując, że umowa powierzenia z Google jeszcze nie jest
podpisana (issue #8, prawnik). Powód biznesowy: kampania idzie przez
Facebooka, ludzie klikają z telefonu z Androidem, gdzie konto Google jest już
zalogowane — jedno kliknięcie zamiast wymyślania hasła.

**To jest jedyne otwarte ryzyko tej funkcji i jest ono formalne, nie
techniczne.** Polityka prywatności mówi o tym wprost i nie udaje inaczej:
akapit „Umów powierzenia przetwarzania danych z tymi dostawcami jeszcze nie
mamy podpisanych" obejmuje od dziś także Google. Wszystko, co dało się
domknąć kodem, jest w tym wpisie domknięte.

Bezpośredni powód biznesowy jest zmierzony na człowieku: 63-letnia mama
właściciela nie założyła konta — odbiła się o walidację nazwy, potem czekała
na wiadomość, która nie miała przyjść (issue #258, issue #25).

### ROZSTRZYGNIĘCIE 1: WŁASNY KOD, BEZ `laravel/socialite`

Kryterium jest to samo, którym **D-065** odesłał trzy pakiety: pakiet wchodzi
wtedy, gdy usuwa **nazwany, dziś istniejący problem**. Zestawienie:

| | `laravel/socialite` | własny kod (`App\Google\KlientGoogle`) |
|---|---|---|
| Co daje | ~30 klas dostawców, z których używamy jednego | jedno przekierowanie + jedno żądanie `POST` z sześcioma polami |
| Na czym stoi | własny klient HTTP i własna warstwa sesji | `Illuminate\Support\Facades\Http` i sesja Laravela — to, co już mamy |
| PKCE dla Google | nie domyślnie — trzeba dopisać kod na jego wnętrznościach | jest od pierwszego dnia, ~10 linii |
| `email_verified` | surowy element tablicy `$user->user` obok wygodnego `getEmail()` | osobne, wymagane pole `TozsamoscGoogle::$emailPotwierdzony` |
| Zdjęcie z Google | pobiera awatar domyślnie | nie prosimy o nie wcale |
| Aktualizacje | cudze | nasze |
| Rozmiar | jedna zależność w `composer.json` na jedną funkcję | ~200 linii w dwóch klasach |

**Rozstrzygnięcie: własny kod.** Dwa powody są decydujące i oba są
o TYM repozytorium, nie o zwyczajach:

1. **`AGENTS.md` §3 wymienia „kolejną bibliotekę, gdy Laravel ma to
   w standardzie" wśród zakazów.** Ta rozmowa to jeden `POST` po TLS-ie.
   Tą samą drogą poszedł transport poczty (**D-047**) i weryfikacja
   Turnstile (**D-050**) — oba działają i oba są krótsze od integracji
   z pakietem.
2. **Dwie rzeczy, których u nas naprawdę potrzebujemy, pakiet robi
   trudniejszymi, nie łatwiejszymi.** `email_verified` jest u nas
   WARUNKIEM wejścia (rozstrzygnięcie 2), a Socialite ustawia je w cieniu
   wygodnego `getEmail()`; PKCE i tak trzeba dopisać samemu. Kod, który
   MUSI zapytać o potwierdzenie adresu, jest bezpieczniejszy niż kod,
   który MOŻE.

**Cena jest uczciwa i zapisana tutaj:** zmiany po stronie Google są od dziś
naszą robotą. Powierzchnia jest jednak mała i stabilna — dwa adresy i jeden
format tokenu, niezmienione u Google od lat. **Próg powrotu do pakietu:**
drugi dostawca tożsamości (Facebook z issue #258 jako „ewentualnie") ALBO
pierwsza zmiana po stronie Google, której nie da się obsłużyć zmianą jednej
stałej. Wtedy Socialite przestaje być „pakietem na jedną funkcję".

**Podpisu tokenu tożsamości świadomie NIE sprawdzamy** i to nie jest
skrót — OpenID Connect Core §3.1.3.7 pkt 6 zwalnia z tego token odebrany
**wprost z punktu tokenu, po TLS-ie**, czyli dokładnie nasz przypadek (nie
bierzemy tokenu z przekierowania w przeglądarce). Alternatywą byłoby
pobieranie i odświeżanie kluczy publicznych Google (JWKS) plus weryfikacja
RS256 — czyli nowa klasa awarii „logowanie padło, bo nie dociągnęliśmy
kluczy" za zerowy zysk. **Sprawdzamy natomiast wszystko inne z tego
paragrafu:** wydawcę (`iss`), odbiorcę (`aud` musi być NASZ identyfikator
klienta — bez tego token wystawiony dla innej aplikacji Google byłby u nas
dobry), termin (`exp`) i `nonce` wiążący token z tą sesją.

### ROZSTRZYGNIĘCIE 2: ŁĄCZENIE KONT — TRZY REGUŁY, KAŻDA ZAMYKA INNY ATAK

To jest najgroźniejsze miejsce całej funkcji: zaufanie adresowi e-mail bez
dowodu jest gotowym przejęciem konta.

**REGUŁA 1. `email_verified` od Google jest WARUNKIEM. Bez niego nie robimy
nic — ani logowania, ani rejestracji.**

Konto Google nie musi być kontem Gmail: da się je założyć na dowolny cudzy
adres (`ktos@wp.pl`) i korzystać z niego, dopóki nie zostanie potwierdzone.
Token niesie wtedy `email_verified: false`. Bez tego warunku napastnik
zakłada konto Google na adres wybranej osoby i wchodzi u nas na JEJ konto —
albo zakłada u nas konto na jej adres, zabierając jej drogę wejścia, zanim
ona przyjdzie. **To jest najkrótsza znana droga przejęcia konta przy
„zaloguj się przez…" i zamyka ją pierwszy warunek.**

**REGUŁA 2. Konta z NIEPOTWIERDZONYM U NAS adresem nie łączymy nigdy, nawet
gdy Google adres potwierdziło. To jest ta reguła, dla której ta funkcja
w ogóle jest bezpieczna.**

Atak, który tym zamykamy, nazywa się **przejęciem z wyprzedzeniem**
(„pre-hijacking") i u nas jest realny, a nie teoretyczny, bo nasza
rejestracja **świadomie nie wymaga potwierdzenia adresu** przed pierwszą
publikacją (`RegisterController`: osoba, która z trudem założyła konto, nie
może zostać odesłana do skrzynki). Przebieg:

```text
1. napastnik zakłada DZIŚ konto na basia@wp.pl — adresu nie kontroluje,
   hasło zna tylko on, adres zostaje niepotwierdzony;
2. konto jest puste, więc niczym nie zwraca uwagi. Napastnik czeka;
3. przychodzi prawdziwa Basia, kontem Google, z adresem POTWIERDZONYM;
4. automatyczne połączenie wpuszcza JĄ do konta NAPASTNIKA;
5. on zna hasło, widzi wszystko, co ona napisze, i ma wejście, o którym
   ona nie wie. Ona nie zauważa niczego — konto jest puste, więc wygląda
   dokładnie jak świeżo założone.
```

Odmowa jest tu **jedynym poprawnym zachowaniem**, bo z naszej strony te dwa
przypadki („to ta sama osoba" i „to napastnik") są **nieodróżnialne**. Ekran
mówi, co zrobić: wejdź hasłem albo linkiem i **potwierdź adres** — wtedy
wejście kontem Google zacznie działać. Napastnik adresu nie potwierdzi, bo
nie ma skrzynki.

**REGUŁA 3. Konta z potwierdzonym adresem łączymy WYŁĄCZNIE po jawnym
potwierdzeniu przez człowieka na naszym ekranie — jedno kliknięcie więcej.**

Sam atak zamykają już reguły 1 i 2: kto przeszedł weryfikację Google dla
tego adresu, kontroluje skrzynkę, a kto kontroluje skrzynkę, mógł dzisiaj
przejąć to konto przez „Nie pamiętam hasła". Ten ekran zamyka coś innego:
**niespodziankę**. Człowiek widzi, na jakie konto wchodzi („zalogujesz się
jako Basia"), zanim wejdzie — ta sama zasada, dla której link z wiadomości
nie loguje od razu, tylko prowadzi na ekran z przyciskiem (D-056). Z jednej
skrzynki korzysta czasem całe małżeństwo, a Google wchodzi domyślnie kontem
ostatnio używanym (dlatego prosimy go o `prompt=select_account`).

**Czego te reguły nie ukrywają.** Ekran odmowy z reguły 2 mówi wprost, że na
tym adresie jest już konto — czyli odpowiada na pytanie, na które formularz
„Nie pamiętam hasła" świadomie nie odpowiada. **Nie jest to nowa wyrocznia:**
żeby tam dojść, trzeba przejść weryfikację adresu po stronie Google, czyli
mieć dostęp do skrzynki — a kto ma do niej dostęp, dowie się tego samego,
prosząc o przypomnienie hasła i czytając własną pocztę. Cena milczenia byłaby
za to realna: odmowa bez powodu, po której człowiek odchodzi.

**Kolejne wejścia rozpoznajemy po `sub`, NIGDY po adresie.** Adres u Google
da się zmienić, a w Google Workspace da się nadać komuś innemu adres osoby,
która odeszła z firmy. Adres służy dokładnie raz — przy pierwszym połączeniu.

### ROZSTRZYGNIĘCIE 3: NAZWA I ZGODY NA EKRANIE DOMKNIĘCIA KONTA

Google nie da nam trzech rzeczy, których wymaga nasza rejestracja: nazwy do
adresu profilu, oświadczenia o wieku i akceptacji regulaminu. Dlatego droga
przez Google **nie zakłada konta po cichu** — prowadzi na
`/wejdz/google/domknij`:

- **dwa pola, tak jak przy rejestracji hasłem** (decyzja właściciela
  z 10 września: imię widoczne dla innych ORAZ nazwa w adresie profilu),
  z **nazwą PODPOWIEDZIANĄ** przez `App\Support\NazwaUzytkownika::wolnaPropozycja()`
  — z imienia z Google, a gdy go nie ma, z początku adresu. Podpowiedź liczy
  SERWER i sprawdza przy tym, czy nazwa jest wolna, więc nie proponujemy
  czegoś, co odbije się o walidację. Adres profilu ma być świadomym wyborem,
  nie czymś, co człowiek odkrywa po fakcie;
- **dwa oświadczenia, PUSTE.** Zaznaczenie ich za człowieka jest ciemnym
  wzorcem, a przy oświadczeniu o wieku dodatkowo bez wartości: oświadczenie
  złożone przez serwer nie jest niczyim oświadczeniem;
- **konto powstaje z adresem OD RAZU POTWIERDZONYM** i **bez hasła**.
  Google potwierdziło adres, więc nie prosimy o to samo drugi raz (ten sam
  wywód co w `User::assignEmail()`); zysk uboczny to ani jeden list
  z dobowej puli 300 (D-047). W kolumnie `password` (`NOT NULL`) leży skrót
  wartości losowej, **której nie zna nikt, także my** — konto ma wtedy dwie
  drogi wejścia: Google i wiadomość z linkiem, a hasło ustawi sobie przez
  „Nie pamiętam hasła", jeśli zechce.

**Zakładanie konta przeniosło się przy tym do `app/Domain`**
(`App\Domain\Users\Actions\ZalozKonto`), bo od dziś woła je DWA miejsca.
Dwie kopie listy „profil, powitanie, oświadczenie o wieku, wiadomość
z potwierdzeniem, wpis w dzienniku, obserwowanie gospodarza" rozjechałyby
się przy pierwszej zmianie (`AGENTS.md` §4), a rozjazd byłby cichy: konto
bez obserwowanego gospodarza wygląda jak konto, tylko pusto się z niego
patrzy. Z tego samego powodu tekst odmowy dla konta zamkniętego (DSA
art. 17 ust. 3) wyszedł z `LoginController` do
`App\Domain\Security\KomunikatZamknietegoKonta` — obowiązek prawny spełniony
na jednym ekranie z dwóch nie jest spełniony.

### ROZSTRZYGNIĘCIE 4: ZAKRES `openid email profile` I NIC WIĘCEJ

Prosimy Google o **potwierdzenie tożsamości, adres e-mail razem z informacją,
czy jest potwierdzony, oraz imię**. Imię służy raz, jako podpowiedź nazwy.

Czego **nie** bierzemy i dlaczego:

- **zdjęcia z Google.** D-061: każde zdjęcie profilowe przechodzi u nas przez
  moderację modelem i własny pipeline przekodowania. Zdjęcie zaciągnięte
  z zewnątrz weszłoby **poza** tę drogę;
- **tokenu odświeżania** — i to nie tak, że go nie zapisujemy: żądanie idzie
  z `access_type=online`, więc Google **nam go nie wystawia**. Token
  odświeżania w naszej bazie byłby trwałym pełnomocnictwem do cudzego konta
  Google, leżącym w serwisie, który go do niczego nie używa;
- **tokenu dostępu i tokenu tożsamości.** Żyją przez jedno wywołanie akcji.
  Nie wołamy żadnego API Google po zalogowaniu — jedno żądanie na całą drogę;
- kontaktów, kalendarza, dysku. Każdy dodatkowy zakres to punkt na ekranie
  zgody, na którym człowiek 60+ ma prawo się wystraszyć i wyjść — i słusznie.

**W bazie zostają DWIE kolumny na `users` i to jest całość:** `google_sub`
(trwały identyfikator konta Google — jedyna wartość, którą Google obiecuje
jako niezmienną) i `google_connected_at` (od kiedy, dla ekranu ustawień
i dla sporu o dostęp; `audit_log` jest sprzątany z czasem, a to jest cecha
konta). Baza pilnuje trzech rzeczy, których PHP nie musi: **unikalności
`google_sub`** (jedno konto Google = jedno konto Kuking; indeks częściowy),
**„obie kolumny albo żadna"** (`num_nonnulls(...) IN (0,2)`) i kształtu
`sub`. Obie kolumny są **poza `$fillable`** — kto ustawi komuś `google_sub`
masowym przypisaniem, ten wchodzi na jego konto jednym kliknięciem; wchodzą
wyłącznie przez `User::connectGoogle()` (ta sama zasada co `status`, `role`
i `email`, `AGENTS.md` §7). **Powiązanie znika razem z hasłem przy
anonimizacji konta** (`EraseAccountData`, D-022) — bez tego losowe hasło nie
chroniłoby niczego.

**Dlaczego kolumny, a nie osobna tabela.** `login_link_tokens`
i `pending_email_changes` (D-048) mają własne tabele, bo to są ŻĄDANIA
Z ŻYCIORYSEM (powstają, wygasają, są zużywane). Powiązanie z Google jest
TRWAŁĄ CECHĄ KONTA, jak adres e-mail. Tabela `tozsamosci_zewnetrzne`
(`provider`, `subject`) będzie właściwym kształtem przy **drugim** dostawcy —
próg powrotu jest tani, a migracja przejściowa to jeden `INSERT ... SELECT`.
Budowanie jej dziś to `AGENTS.md` §3.

> **PRÓG POWROTU ZOSTAŁ PRZEKROCZONY TEGO SAMEGO DNIA, PRZED SCALENIEM.**
> Właściciel poprosił wprost o Google **oraz** Facebooka, więc „przy drugim
> dostawcy" nastąpiło, zanim ta migracja kiedykolwiek chodziła na produkcji.
> Powiązania leżą w tabeli `tozsamosci_zewnetrzne` — **D-098**. Migracja
> przejściowa nie była potrzebna wcale: gałąź nie była scalona, więc
> wystarczyło przepisać migrację. To jest ten tani próg powrotu, o którym
> mowa w akapicie wyżej, wykorzystany w praktyce.

### ROZSTRZYGNIĘCIE 5: TURNSTILE NA TEJ DRODZE NIE STOI

**Rozstrzygnięcie: nie.** Rodzina chronionych formularzy zostaje siedmioosobowa
(D-050, D-053) i ta droga do niej **nie dochodzi** — a to jest odstępstwo,
które trzeba uzasadnić, nie przemilczeć.

Kryterium z D-050 brzmi: Turnstile stoi tam, gdzie **automat wysyła formularz
publiczny i coś nas to kosztuje** — konto do moderowania, list z dobowej
puli, pozycję w kolejce jedynego moderatora, sprawę z terminem z DSA,
zgadywanie haseł. Zestawmy z tym dwie trasy tej funkcji:

- **`/wejdz/google` (GET, kliknięcie przycisku).** Nie jest formularzem
  i nic nie zapisuje — zakłada wartości w sesji i przekierowuje. Turnstile
  jest **warunkiem wysłania formularza**; tutaj nie ma formularza, do którego
  mógłby się przyczepić. Kosztem nadużycia jest jeden wiersz sesji, na który
  odpowiada limit zapytań (`limits.google_wejscie`).
- **`/wejdz/google/domknij` (POST, TU POWSTAJE KONTO).** Ten formularz
  **publiczny nie jest**, i to jest sedno: żeby na niego wejść, trzeba mieć
  w sesji tożsamość, która przeszła ekran zgody Google **i** ma adres
  potwierdzony przez Google. Automat zakładający konta seriami musi więc mieć
  **jedno potwierdzone konto Google na każde konto Kuking** — czyli przejść
  zabezpieczenia antyautomatowe Google, mocniejsze od Turnstile i stojące
  **przed** nim. Dołożenie captchy za tą bramką dodałoby jeden ekran, na
  którym osoba 60+ może utknąć, i ani jednego zamkniętego nadużycia.

Zostają za to obie rzeczy, które D-050 nazywa niezastępowalnymi: **limity
zapytań** — dwa osobne koszyki, żeby kliknięcia nie zjadały budżetu wysłań
formularza — i **wpisy w dzienniku** przy każdej odmowie.

**Zysk uboczny, którego nie planowaliśmy:** to jedyna droga wejścia na tym
ekranie, która **nie potrzebuje JavaScriptu** (Turnstile go potrzebuje,
D-053). Dla osoby, u której skrypt widgetu się nie dociągnął — słabe łącze,
blokada reklam — wejście kontem Google jest od dziś drogą, która zadziała.
To jest argument za tą decyzją, nie przeciw.

### CO TA DROGA NIE OMIJA

- **2FA.** Konto z potwierdzoną weryfikacją dwuetapową trafia na
  `/logowanie/kod`, tą samą sesyjną ścieżką (`logowanie.2fa.user_id`).
  **Google zastępuje hasło, nie drugi składnik.**
- **Konta obsługi serwisu.** Moderator i administrator tą drogą nie wchodzą
  wcale (ten sam zakres co D-056: tam obowiązuje hasło + 2FA). Rolę
  sprawdzamy przy KAŻDYM wejściu, więc powiązanie zrobione przed awansem
  przestaje działać z chwilą nadania roli.
- **Blokadę.** Konto zamknięte (`banned`, `pending_delete`, `erased`) nie
  wchodzi i czyta to samo uzasadnienie z DSA art. 17 co na ekranie hasła.
  Konto **zawieszone wchodzi** — kara jest „tylko do odczytu" i odmowa
  wejścia zamieniałaby ją w blokadę na zawsze (dokładnie ten sam wywód co
  w `LoginController`).
- **Zamkniętej rejestracji.** `account.registration_open` zamyka także tę
  drogę — inaczej zamknięcie rejestracji zamykałoby jedną z dwóch dróg do
  tego samego skutku, czyli nie zamykałoby jej wcale.

### GDY GOOGLE NIE ODPOWIADA ALBO CZŁOWIEK ODMÓWI ZGODY

```text
człowiek klika „Anuluj" u Google  → /login + zdanie po polsku („zgoda nie
                                     została udzielona, nic się nie stało")
Google nie odpowiada / 5xx        → /login + zdanie + `Log::error`
zły sekret albo zły adres powrotu → /login + zdanie + `Log::error` z tym,
                                     co sprawdzić (runbook 8D)
`state` nie pasuje / brak kodu    → /login + JEDNO zdanie dla wszystkich
                                     tych przypadków + `Log::warning`
brak kluczy w konfiguracji        → przycisku NIE MA na ekranie w ogóle
```

**Angielskiego kodu od dostawcy nie pokazujemy nigdy** — nikomu nic nie
mówi. Na `/login`, gdzie człowiek wraca, stoi hasło i „Wyślij mi link do
zalogowania", więc żadna z tych awarii nie zostawia go bez drogi dalej
(D-053: nigdzie martwego przycisku).

**Wyłącznik jest podwójny i to jest celowe.** Puste `GOOGLE_CLIENT_ID` albo
`GOOGLE_CLIENT_SECRET` = tej funkcji nie ma (to jest stan lokalny, w CI
i w testach — środowisko bez kluczy zachowuje się dokładnie jak przed tą
zmianą). `KUKING_WEJSCIE_GOOGLE=false` = świadome wyłączenie z kluczami na
miejscu, bez migracji i bez utraty powiązań. Na produkcji cisza ma być
zakazana: funkcja włączona bez kluczy ma dawać `/health` → `degraded`
z powodem `google_bez_kluczy`, tak samo jak Turnstile bez kluczy — bo inaczej
mielibyśmy drogę wejścia, która melduje sukces, nie istniejąc.

> **SPROSTOWANIE: tego sygnału w `/health` JESZCZE NIE MA.** Kod był napisany
> i został **świadomie wycofany z tego PR-a**: `HealthController` przerabia
> równolegle inne zlecenie (#253/#255), a dwóch agentów w jednym pliku kosztuje
> więcej niż jeden dzień bez tego sygnału. Wywód wyżej obowiązuje i sygnał ma
> dojść — jednym `check('google', ...)` obok tego od Turnstile, z gotowym już
> zdaniem `App\Support\Google::komunikatBrakuKluczy()`. Do tego czasu runbook
> (krok 8D) każe sprawdzić to okiem: jest przycisk na `/login` czy nie ma.

**Cofnięcie MIGRACJI odmawia**, gdy w bazie jest choć jedno powiązane konto,
i mówi, co zrobić. To nie jest ostrożność na zapas: konto założone tą drogą
nigdy nie miało hasła, więc skasowanie `google_sub` zabiera mu jedyną drogę
wejścia, jaką ta osoba zna. `KUKING_ROLLBACK_KASUJ_POWIAZANIA_GOOGLE=true`
przepuszcza cofnięcie dla kogoś, kto naprawdę tego chce — ta sama konstrukcja
co przy zeszytach (`CofniecieMigracjiNieKasujeZeszytowTest`).

### CZEGO ŚWIADOMIE NIE ZROBILIŚMY

- **Ekranu „połącz / odłącz konto Google" w ustawieniach.** Dziś powiązanie
  powstaje wyłącznie na drodze wejścia, a odłączenia nie ma w interfejsie —
  jest za to w polityce prywatności zdanie, że wystarczy napisać na adres
  kontaktowy. Powód: `AGENTS.md` §3, jedna funkcja na raz. **Próg powrotu:**
  pierwsza prośba o odłączenie ALBO drugi dostawca. Uwaga na wtedy: odłączenie
  konta, które **nie ma innej drogi wejścia** (nie ustawiło hasła), musi
  odmawiać albo najpierw poprosić o hasło — inaczej ekran ustawień będzie
  zamykał ludziom drzwi jednym kliknięciem.
- **Logowania kontem Facebooka.** Issue #258 wymienia je jako „ewentualnie".
  Drugi dostawca to moment na tabelę `tozsamosci_zewnetrzne`, nie na trzecią
  kolumnę.
- **Sprawdzania podpisu tokenu (JWKS).** Uzasadnienie wyżej,
  rozstrzygnięcie 1.
- **Zdjęcia profilowego z Google.** D-061; opisane w rozstrzygnięciu 4.

📄 `app/Support/Google.php` · `app/Google/KlientGoogle.php` ·
`app/Google/TozsamoscGoogle.php` ·
`app/Http/Controllers/Auth/GoogleLoginController.php` ·
`app/Domain/Users/Actions/ZalozKonto.php` ·
`app/Domain/Security/KomunikatZamknietegoKonta.php` ·
`app/Models/User.php` (`connectGoogle()`, `findByGoogleSub()`) ·
`app/Models/TozsamoscZewnetrzna.php` (D-098) ·
`app/Domain/Users/Actions/EraseAccountData.php` ·
`app/Http/Controllers/HealthController.php` ·
`database/migrations/2026_09_10_500000_create_tozsamosci_zewnetrzne_table.php` ·
`config/kuking.php` (`google.*`, `limits.google_*`) ·
`resources/views/components/wejdz-google.blade.php` ·
`resources/views/auth/google-finish.blade.php` ·
`resources/views/auth/google-link.blade.php` ·
`resources/legal/polityka-prywatnosci.md` ·
`docs/infra/DEPLOYMENT_RUNBOOK.md` krok 8D ·
`tests/Feature/LogowanieKontemGoogleTest.php` ·
`tests/Feature/CofniecieMigracjiGoogleOdmawiaTest.php` ·
issue #258, issue #8 (umowa powierzenia), issue #25 (D-056)

---

## D-071 · Granica zaufania do nagłówka `Host` jest zamknięta z dwóch stron: `X-Forwarded-Host` wypada z zaufanych nagłówków, a `Host` przechodzi przez `TrustHosts`

**Data:** 10 września 2026 · **Znalezisko:** S2 (P1) z `docs/research/audyt-2026-09-10/02_BEZPIECZENSTWO_APLIKACJI.md`

### Co było zmierzone PRZED zmianą — bo od tego zależy, jak to nazwać

Pomiar, nie założenie (tymczasowy test na `origin/main` @ `e3cf6ab`):

| Żądanie | Odpowiedź | `url('/przepisy')` |
|---|---|---|
| bez nagłówków | 200 | `http://localhost:8000/przepisy` |
| `Host: attacker.invalid` | 200 | host z żądania |
| `X-Forwarded-Host: attacker.invalid` | 200 | **`http://attacker.invalid/przepisy`** |

Linki w listach przy `X-Forwarded-Host: attacker.invalid`, gdy adres powstaje
w żądaniu HTTP — wszystkie cztery wychodziły na `http://attacker.invalid/…`:
reset hasła, potwierdzenie adresu, logowanie linkiem, potwierdzenie zmiany
adresu e-mail.

**Ale to nie znaczy, że wszystkie cztery były na produkcji do wykorzystania,
i nie wolno tego tak sprzedać.** Trzy pierwsze powiadomienia są `ShouldQueue`,
a produkcyjna kolejka to `database` (`.railway/railway.ts`), więc adres
powstaje w WORKERZE. Zmierzone w kontekście konsoli: `url()` zwraca
`https://kuking.pl/…`, bo `SetRequestForConsole` buduje żądanie z `APP_URL`.
Dla resetu hasła, potwierdzenia adresu i logowania linkiem to była więc
granica **formalnie otwarta, praktycznie zasłonięta** przez asynchroniczną
kolejkę — czyli **hardening, nie naprawa dziury**.

Jedno miejsce nie miało tej osłony: `RequestEmailChange::linkPotwierdzajacy()`
buduje podpisany adres **w żądaniu HTTP**, przed zakolejkowaniem listu. Tam
`X-Forwarded-Host` wchodził do treści listu wprost i to jest **realnie otwarta
droga**, nie hipoteza.

### Decyzja

**1. `Request::HEADER_X_FORWARDED_HOST` wypada z bitmaski `trustProxies()`.**
To jest zamknięcie mocniejsze niż allowlista, bo nagłówka, którego aplikacja
nie czyta, nie da się podstawić żadną wartością. Wolno go było wyjąć, bo
w naszym łańcuchu **nikt go nie wystawia i nikt nie przepisuje `Host`**:
Cloudflare w trybie proxy przekazuje `Host` na origin nietknięty (routing po
nim właśnie działa), a brzeg Railway kieruje ruch po `Host`/SNI i też go
zachowuje — inaczej nie odróżniłby `kuking.pl` od `staging.kuking.pl` na tym
samym koncie. Aplikacja ma oryginalny host w `Host` i drugiego źródła nie
potrzebuje.

**2. `Host` przechodzi przez `TrustHosts` z jawną listą** — `App\Support\ZaufaneHosty`,
`subdomains: false`. Na liście: `kuking.pl`, `www.kuking.pl`,
`healthcheck.railway.app`, host z `APP_URL`, pętla zwrotna
(`localhost`/`127.0.0.1`/`[::1]`) i pusty domyślnie zawór
`config('proxy.dodatkowe_hosty')`. Uzasadnienie każdego wpisu — i tego, co się
stanie po jego pominięciu — stoi w komentarzu tamtej klasy.

**3. Adresy w listach budowane z konfiguracji, zawsze** — `App\Support\AdresKanoniczny`.
Dla linku, który **daje sesję** (`LinkDoLogowania`, D-056 — nasza główna droga
wejścia dla osób 60+), „host był na liście dozwolonych" jest gwarancją słabszą
niż „host w ogóle nie zależał od żądania": lista ma kilka pozycji, kanoniczny
adres jest jeden.

### Czego świadomie NIE zrobiliśmy

**Produkcyjnego `*.up.railway.app` nie ma na liście.** Wejście na origin
z pominięciem Cloudflare to znalezisko **S1** — osobne, większe, wymaga zmian
w panelu Cloudflare i decyzji właściciela. Ta zmiana go NIE rozstrzyga.
Skutkiem ubocznym jest to, że ten host przestaje być drogą do zbudowania
adresu na cudzej domenie, ale **to nie jest zamknięcie S1** i nie wolno tak
raportować. Gdyby właściciel potrzebował wejść na origin wprost, służy do tego
zawór z punktu 2.

**Nie ruszaliśmy `NormalizeForwardedFor`** ani liczby `zaufane_przeskoki`
(SEC-01, W7-01) — to jest `X-Forwarded-For`, inna granica.

### Ryzyko wdrożeniowe — jedyne, które tu jest, i jak je zamknięto

`TrustHosts` bez `healthcheck.railway.app` oddaje healthcheckowi Railwaya 400,
a wtedy **deploy nigdy się nie kończy i nie ma jak wypchnąć poprawki**, bo
poprawka też idzie deployem. Dlatego: host jest na liście, pilnuje go test
`ZaufaneHostyTest::test_healthcheck_railwaya_przechodzi` (oblewa po usunięciu
wpisu — sprawdzone), a na wypadek zmiany po stronie Railwaya istnieje zawór
`KUKING_ZAUFANE_HOSTY`, którym da się naprawić produkcję **bez deployu**.
Brak tej zmiennej jest stanem domyślnym, bezpiecznym i działającym — nie trzeba
jej ustawiać, żeby serwis wstał.

**Zmiana wymaga:** dowodu z produkcji, że coś w łańcuchu przepisuje `Host`
(objaw: adresy w serwisie wskazują wewnętrzną domenę platformy). Wtedy wraca
`HEADER_X_FORWARDED_HOST` — ale razem z zapisanym pomiarem, nie „na wszelki
wypadek".

📄 `bootstrap/app.php` · `app/Support/ZaufaneHosty.php` ·
`app/Support/AdresKanoniczny.php` · `config/proxy.php` ·
`app/Notifications/UstawienieNowegoHasla.php` ·
`app/Notifications/PotwierdzenieAdresu.php` ·
`app/Notifications/LinkDoLogowania.php` ·
`app/Domain/Users/Actions/RequestEmailChange.php` ·
`tests/Feature/ZaufaneHostyTest.php` ·
`.railway/railway.ts` · `docs/legal/BRAMKA_BETY.md` ·
`docs/infra/DEPLOYMENT_RUNBOOK.md`

---

## D-076 · Dobowy budżet listów jest twardym sufitem: jedna atomowa rezerwacja pod blokadą `Cache::lock()`, bez nowej tabeli

**Data:** 10 września 2026 · Źródło: audyt drugiej warstwy, **MAIL-01 / RACE-03 (P1)** · Status: **obowiązuje**

### Co było źle

`App\Domain\Security\DziennyBudzetListow` rozdzielał odczyt licznika od jego
zapisu — i tak też był wołany:

```php
if (! $budzet->jestMiejsce()) { odmów; }   // odczyt: 119 ze 120
// …dziesięć linii dalej…
$budzet->zajmij();                          // zapis: 120
```

Tak stało w `LoginLinkController::send()` (sprawdzenie i zajęcie w odległości
dziesięciu linii) i w `kuking:wyslij-podsumowania` (rozmiar paczki liczony raz
z `zostalo()`, przed pętlą). Przy suficie 120 i zużyciu 119 dwa równoległe
żądania czytają oba 119, oba widzą wolne miejsce, oba wysyłają list i oba
inkrementują licznik. Wychodzi 121 listów przy sufcie 120.

`Cache::increment()` jest atomowy jako POJEDYNCZA operacja — i to właśnie
usypiało czujność. Para „sprawdź, a potem zajmij" nie jest atomowa jako para
i żadna liczba komentarzy w kodzie tego nie zmienia.

Nie da się tego naprawić sprawdzeniem PO inkrementacji („czy przekroczyliśmy?").
Wiadomość jest wtedy już zakolejkowana, przekroczenie już nastąpiło, a listu
z drogi nie cofniemy.

### Dlaczego to nie jest usterka kosmetyczna

Wiadro u dostawcy to 300 listów na dobę na CAŁY serwis (D-047), a z tego samego
wiadra idzie **potwierdzenie rejestracji**, które sufitu nie ma i mieć nie może
— nie da się go przełożyć na jutro. Sufit przeciekający o kilka listów pod
obciążeniem zabiera je dokładnie tam. Właściciel spodziewa się fali migracyjnej
z Garnek.pl, czyli dnia, w którym logowanie linkiem i rejestracja mają szczyt
w tej samej godzinie.

### Decyzja

1. **Jedna atomowa operacja rezerwacji:** `sprobujZarezerwowac(): bool`.
   Zajmuje miejsce i zwraca `true`, albo nie zajmuje niczego i zwraca `false`.
   W środku, pod blokadą, chodzi ta sama para co dawniej — ale nikt z zewnątrz
   nie może już wejść między jej dwa kroki.
2. **Wszystkie miejsca decydujące o wysyłce przeszły na tę metodę.** Sprawdzone
   `grep`iem: w `app/` nie została ani jedna para sprawdź-potem-zajmij.
3. **`jestMiejsce()` i `zostalo()` zostają jako ODCZYT** — do pokazania
   człowiekowi, do diagnostyki i do oszacowania rozmiaru paczki
   (`kuking:wyslij-podsumowania` nie pobiera z bazy stu odbiorców, gdy zostało
   pięć miejsc). Docblocki mówią teraz wprost, czego nimi robić nie wolno.
4. **Nie udało się zdobyć blokady w 2 sekundy → ODMOWA wysyłki.** Nie „wyślij
   na wszelki wypadek": przekroczony budżet u dostawcy odbija się na całej
   poczcie serwisu, a jedna niewysłana wiadomość odbija się na jednej osobie,
   która dostaje uczciwy komunikat i klika drugi raz.
5. **Miejsce, z którego nic nie wyszło, wraca do puli** (`zwolnij()`) — patrz
   niżej, „Rezerwacja przed wysyłką kontra stara reguła".

### Dlaczego blokada na istniejącym mechanizmie, a nie własna tabela z `UPDATE ... WHERE used < limit`

Warunkowy `UPDATE` byłby poprawny i byłby atomowy bez żadnej blokady — to
uczciwa alternatywa i została rozważona. Kosztuje jednak: nową tabelę,
migrację, wpis w `docs/DATABASE.md`, opisany rollback i sprzątanie starych
wierszy. Czyli **drugi mechanizm obok tego, który już mamy**, przy zasadzie
projektu mówiącej odwrotnie: żadnych nowych mechanizmów bez zmierzonej
potrzeby (AGENTS.md §3).

Rozstrzyga to, czym jest tu `Cache::lock()`. Sterownik cache w tym projekcie to
`database` (`config/cache.php` → `env('CACHE_STORE', 'database')`,
`.env.example` → `CACHE_STORE=database`), więc blokada jest **prawdziwa,
współdzielona między procesami i trwała**, oparta o tabelę `cache_locks`
z migracji `0001_01_01_000002_create_cache_table`. To ta sama tabela w tej
samej bazie, do której poszedłby własny warunkowy `UPDATE` — z tą różnicą, że
nie musimy jej pisać, migrować ani sprzątać.

Gdyby sterownikiem był `array`, blokada nie wychodziłaby poza jeden proces PHP
i cały ten sufit byłby atrapą. Ten warunek nie jest już domysłem: pilnuje go
`AtomowaRezerwacjaBudzetuTest::test_produkcyjny_sterownik_cache_daje_prawdziwa_wspoldzielona_blokade`.

**Redisa nie dodajemy** — projekt świadomie go nie ma (AGENTS.md §3), a
`database` tu wystarcza.

### Rezerwacja przed wysyłką kontra stara reguła „licz dopiero wysłane listy"

Sufit musi być zajmowany PRZED wysyłką, bo po niej jest już za późno na
cokolwiek. Ale przy logowaniu linkiem list wychodzi tylko wtedy, gdy pod
podanym adresem NAPRAWDĘ jest konto — i to nie jest szczegół: gdyby licznik
ruszał przy każdym wysłaniu formularza, byle automat wpisujący nieistniejące
adresy wyczerpałby dobowy budżet w kilka minut, nie wysławszy ani jednego
listu prawdziwej osobie.

Obie reguły trzymamy naraz: rezerwacja stoi przed wysyłką, a nieużyte miejsce
wraca do puli przez `zwolnij()`. Nieudane zdobycie blokady przy oddawaniu
zostawia licznik zawyżony o jeden i tak ma być — pomyłka idzie wtedy w stronę
„wyślemy o jeden list mniej", nie w stronę przekroczenia limitu dostawcy.
Regresję pilnuje istniejący `test_adresy_bez_konta_nie_zjadaja_dobowego_budzetu`.

### Dwa powody odmowy, dwa różne zdania dla człowieka

Rezerwacja mówi tylko „nie", a te dwa „nie" znaczą dla człowieka coś zupełnie
innego. Przy wyczerpanym budżecie czekanie na list jest bezcelowe („nie czekaj
na niego"); przy ścisku na blokadzie budżet jest wolny i drugie kliknięcie
zwykle wystarcza. Zdanie „wysłaliśmy już wszystkie e-maile na dziś" w drugim
przypadku byłoby po prostu **nieprawdą**, a komunikaty w tym serwisie nie
opowiadają rzeczy, które się nie stały (D-056, ekran linku). Treść komunikatu
dobiera odczyt `jestMiejsce()` — już PO tym, jak rezerwacja rozstrzygnęła
o wysyłce.

### Czego świadomie nie zmieniono

- **Wartości sufitów w `config/kuking.php`** — ani jednej liczby. Podział
  wiadra pilnuje `PodzialLimituPocztyTest` i nie ma z tą usterką nic wspólnego.
- **List próbny `kuking:wyslij-podsumowania --tylko` stoi ponad sufitem**, tak
  jak przed tą zmianą: to jedna wiadomość wypuszczana ręcznie przez właściciela,
  który chce ZOBACZYĆ list. Ale musi się policzyć, więc gdy rezerwacja odmówi,
  miejsce zajmowane jest bezwarunkowo (`zajmij()`). To jedyne miejsce w kodzie,
  w którym wolno wołać `zajmij()` wprost.
- **Idempotencja tygodniowego digestu** — osobne zadanie, osobna gałąź.

**Zmiana wymaga:** zmierzonego problemu z blokadą na sterowniku `database`
(np. przy dziesiątkach żądań na sekundę na ten jeden klucz). Wtedy — i tylko
wtedy — wraca do rozważenia warunkowy `UPDATE` we własnej tabeli z pełnym
kompletem: migracja, test, `docs/DATABASE.md`, rollback.

📄 `app/Domain/Security/DziennyBudzetListow.php` ·
`app/Http/Controllers/Auth/LoginLinkController.php` ·
`app/Console/Commands/WyslijPodsumowaniaTygodnia.php` ·
`tests/Feature/AtomowaRezerwacjaBudzetuTest.php` ·
`tests/Feature/LogowanieLinkiemTest.php` ·
`tests/Feature/TygodniowePodsumowanieTest.php` ·
`config/cache.php` · `database/migrations/0001_01_01_000002_create_cache_table.php` ·
D-047 · D-056 · D-057

---

## D-079 · Operacje na jednej rzeczy tego samego konta idą przez JEDNĄ kolejność blokad, a pod blokadą sprawdzamy stan jeszcze raz

**Data:** 10 września 2026 · Ustalenie AUTH-01 / RACE-01 z drugiej warstwy
audytu (`docs/research/audyt-2026-09-10/`) · Status: **obowiązuje**

### Co było złamane

Serwis obiecuje w trzech miejscach jedną własność: **ustawienie nowego hasła
unieważnia oczekującą zmianę adresu e-mail**. Wołają to
`PasswordResetController::reset()` i `SecuritySettingsController::
updatePassword()` przez `CancelEmailChange`, a `PendingEmailChange` wymienia
to jako jedną z trzech dróg wygaszenia żądania.

Ta własność **nie obowiązywała**. `EmailSettingsController::confirm()`
pobierał wiersz `pending_email_changes`, sprawdzał go i oddawał MODEL do
`ConfirmEmailChange::handle()`, które wchodziło do transakcji, blokowało
`users` — i nigdy nie czytało tego wiersza ponownie. `CancelEmailChange`
kasowało wiersz **bez żadnej blokady**. Między odczytem w kontrolerze
a transakcją w akcji było okno:

1. żądanie A czyta ważne `PendingEmailChange`;
2. żądanie B ustawia nowe hasło i kasuje ten wiersz;
3. żądanie A wchodzi do transakcji, przypisuje NOWY ADRES i woła
   `$zmiana->delete()`, które kasuje zero wierszy — i nie zgłasza błędu.

**Nie jest to teoretyczne.** Kontrola ujemna (usunięcie rewalidacji
i uruchomienie testów regresyjnych) pokazuje, że adres konta faktycznie
zmienia się na nowy mimo anulowania.

### Dlaczego to była najpoważniejsza rzecz z całego audytu

Scenariusz, w którym ta obietnica ma sens, to dokładnie ten, w którym ktoś
obcy miał chwilowy dostęp do konta: zamówił zmianę adresu na swój,
a właściciel odzyskuje konto ustawiając nowe hasło. Właściciel wykonuje
**dokładnie tę czynność, którą serwis mu każe** — i mimo tego link
napastnika może później przestawić adres konta, czyli przenieść na niego
logowanie i reset hasła.

Mechanizm zaprojektowany na wypadek przejęcia konta dawał się przejęciu
obejść. Audyt sklasyfikował to jako P1; w praktyce jest to jedyne znalezisko
z obu warstw, które prowadzi do utraty konta bez żadnego błędu właściciela.

### Zasada, która z tego zostaje

**1. Jedna kolejność blokad, w jednym miejscu.** `App\Domain\Users\ZamekKonta`
ustala: najpierw wiersz `users`, potem rzecz zależna. Wszystkie trzy operacje
na zmianie adresu (zamówienie, potwierdzenie, anulowanie) wchodzą przez to
gardło. Kolejność jest ważniejsza niż sam fakt blokowania — dwie różne
kolejności w jednym repozytorium to zakleszczenie, a nie zabezpieczenie.
Dlatego kolejność stoi w jednej klasie, nie w trzech akcjach osobno.

**2. Blokujemy wiersz KONTA, nie rzeczy zależnej.** Bo rzecz zależna może nie
istnieć, a `SELECT ... FOR UPDATE` na nieistniejącym wierszu nie blokuje
niczego i nie powstrzyma drugiego `INSERT`. Konto istnieje zawsze i jest
wspólne dla wszystkich operacji.

**3. Sama blokada nie wystarczy — pod blokadą czytamy stan JESZCZE RAZ.**
Blokada serializuje, ale nie mówi żądaniu A, że świat zmienił się, gdy ono
czekało. Akcja, która dostaje model z zewnątrz, **nie ma prawa mu ufać**:
model mógł zostać odczytany przed sekundą albo przed godziną. Rewalidacja
pyta o to samo co sprawdzenie przed blokadą: czy wiersz istnieje, czy jest
nasz, czy nie wygasł i czy dotyczy tej samej rzeczy.

**4. `exists()` w PHP jest dobre na ładny komunikat, nie na gwarancję.**
Gwarancję daje constraint w PostgreSQL albo blokada. Tam, gdzie inwariant da
się wyrazić w bazie, ma być w bazie.

### Zasięg tej decyzji

Wpis dotyczy zmiany adresu e-mail, ale zasada jest ogólna i audyt wskazuje
te same wzorce w co najmniej pięciu innych miejscach (wystawianie linku do
logowania, dobowy budżet listów, idempotencja digestu, jeden aktywny eksport
danych, harmonogram przy wielu replikach). Każde z nich jest rozstrzygane
osobnym wpisem — ale **kolejność blokad wprowadzona tutaj obowiązuje w całym
repozytorium** i nowa operacja na koncie nie zakłada własnej.

### Czego ta decyzja NIE rozstrzyga

Nie dowodzi poprawnej kolejności blokad przy dwóch równoległych połączeniach
do PostgreSQL — do tego trzeba dwóch procesów i wymuszonego przeplotu na
poziomie bazy, a audyt 20 słusznie stawia to jako osobne kryterium zamknięcia.
Testy regresyjne dowodzą rzeczy węższej i akurat tej, która była złamana:
że akcja nie ufa modelowi podanemu z zewnątrz.

**Pliki:** `app/Domain/Users/ZamekKonta.php` ·
`app/Domain/Users/Actions/ConfirmEmailChange.php` ·
`app/Domain/Users/Actions/CancelEmailChange.php` ·
`app/Domain/Users/Actions/RequestEmailChange.php` ·
`tests/Feature/PotwierdzenieAdresuNieWyprzedzaAnulowaniaTest.php`

---

## D-075 · Wymiana tokenu logowania linkiem idzie pod blokadą wiersza konta — a konflikt unikalności kończy się tą samą neutralną odpowiedzią co adres bez konta

**Data:** 10 września 2026 · Ustalenie **AUTH-02 / RACE-02 (P1)** z audytu
drugiej warstwy · Status: **obowiązuje**

### Co było złamane — zmierzone, nie wywnioskowane

`WyslijLinkDoLogowania` kasowało poprzedni token i zakładało nowy w jednej
transakcji, ale **bez blokady wiersza konta**:

```php
// app/Domain/Security/WyslijLinkDoLogowania.php, stan sprzed tej zmiany
LoginLinkToken::query()->where('user_id', $user->getKey())->delete();
// … a potem INSERT nowego wiersza
```

`login_link_tokens.user_id` jest unikalne (i **ma takie zostać** — to jest
własność bezpieczeństwa z D-056: jeden ważny link na konto, nowa prośba
unieważnia poprzednią). Bez serializacji dwie prośby naraz przechodziły
`DELETE` — każda kasując zero wierszy, bo każda widziała już posprzątane — i
obie szły do `INSERT`. Jedna odbijała się o constraint.

**Brakowało serializacji, nie constraintu.** To jest cała diagnoza.

### Dlaczego to była sprawa bezpieczeństwa, a nie tylko brzydki błąd

Ten formularz jest **świadomie zaprojektowany jako nieodróżnialny** dla adresu
z kontem i bez konta (D-056): ekran mówi „Jeśli na adres … jest konto
w Kuking, wysłaliśmy tam wiadomość", właśnie po to, żeby nie dało się
sprawdzać, kto tu gotuje. Tymczasem:

- dla adresu **bez konta** obie równoległe prośby kończą się spokojną ścieżką
  „nic nie wysyłamy" — bo `handle()` wychodzi, zanim dojdzie do zapisu;
- dla adresu **z kontem** jedna z nich wywalała `UniqueConstraintViolationException`.

**Zmierzone w tym repozytorium przed poprawką**
(`tests/Feature/WyscigLinkuDoLogowaniaTest.php` na `main` @ `fd164ad`,
z wymuszonym konfliktem):

| adres | odpowiedź HTTP |
|---|---|
| jest konto | **500** |
| nie ma konta | **302** |

Nic tego wyjątku nie przechwytywało: leciał do HTTP jako 500. Para
równoległych żądań była więc kanałem enumeracji, i to takim, którego żaden
wspólny komunikat nie zasłania — bo różnicę robił sam kod odpowiedzi.
Turnstile (D-050) i limit trzech próśb na adres na godzinę utrudniają masowe
użycie, ale **nie usuwają złamania kontraktu**: pytanie „czy tu jest konto"
dawało się zadać.

Drugą stroną tej samej usterki jest rzecz zwyczajna: **dwuklik „Wyślij" dawał
500**. Logowanie linkiem jest dla osób 60+ drogą podstawową, nie awaryjną
(`docs/research/AUDIENCE_50_PLUS.md`, D-056), więc podwójne kliknięcie
przycisku jest tam scenariuszem typowym, nie skrajnym.

### Co jest teraz

1. **Blokada wiersza konta przed `DELETE`** — `SELECT … FROM users … FOR
   UPDATE` w tej samej transakcji, w której idzie `DELETE` + `INSERT`. Dwie
   równoległe prośby o link na to samo konto ustawiają się w kolejce, zamiast
   wyprzedzać się nawzajem.
2. **Świeży odczyt konta pod blokadą.** Blokada serializuje, ale nie mówi
   żądaniu, które czekało, że świat się w tym czasie zmienił. Konto mogło
   między odczytem po adresie a wejściem pod blokadę zostać zablokowane albo
   dostać rolę moderatora — a link wchodzący tam, gdzie nie wchodzi hasło,
   byłby obejściem blokady moderacyjnej. Pod blokadą pytamy o to ponownie.
3. **Defensywne przechwycenie `UniqueConstraintViolationException`** →
   `null` → dokładnie ta sama neutralna odpowiedź, którą dostaje adres bez
   konta: bez listu, bez wpisu w dzienniku audytu, bez zajmowania budżetu
   poczty. Blokada powinna wystarczyć, ale **kontrakt antyenumeracyjny nie
   może zależeć od tego, że blokada nigdy nie zawiedzie** — zawieść może
   z powodów spoza tej metody (przyszły drugi punkt wystawiający token,
   komenda konsolowa, seeder, wywołanie akcji wewnątrz cudzej transakcji).
   Ta sama konstrukcja co w `ReportContent` i `ZglosNielegalnaTresc`.

Czego świadomie **nie** ruszono: treści komunikatu na ekranie logowania
linkiem (napisana po realnej pomyłce 63-letniej testerki, PR #257),
konsumpcji tokenu w `LoginLinkController::store()` (audyt sprawdził ją
osobno — transakcja + `lockForUpdate`, jednorazowość, GET nie konsumuje),
`UNIQUE(user_id)` i wykluczenia moderatorów oraz administratorów z tej drogi.

### Blokada własna, nie `App\Domain\Users\ZamekKonta` — i dlaczego

Ta sama sesja dodała `App\Domain\Users\ZamekKonta` — jedną kolejność blokad
dla operacji na zmianie adresu e-mail (ustalenie AUTH-01 / RACE-01, gałąź
`claude/wyscig-zmiany-adresu`). Ta zmiana **nie używa tamtej klasy**, i to
jest wybór, nie przeoczenie:

- **`ZamekKonta` nie istnieje jeszcze na `main`** ani na żadnej wypchniętej
  gałęzi. Oparcie się na niej robi z tej poprawki bezpieczeństwa zakładnika
  cudzego, niescalonego PR-a — a to jest poprawka P1, która ma dać się
  scalić samodzielnie i samodzielnie być zielona.
- **Dokumentacja by kłamała.** Cały komentarz `ZamekKonta` opisuje wyścig
  przy zmianie adresu e-mail. Skopiowany tutaj wcześniej niż tamta poprawka
  wnosiłby do repozytorium klasę tłumaczącą usterkę, której na `main` nikt
  jeszcze nie naprawił. To jest dokładnie ten dryf dokumentacji, który audyt
  wskazuje jako największe ryzyko tego repozytorium.
- **Druga klasa o tej samej roli byłaby gorsza od obu wyjść.** Własna
  `ZamekTokenu`/`ZamekKonta2` obok tamtej to gwarantowana kolizja nazw
  i fałszywy wybór dla następnej osoby. Dlatego nie ma tu **żadnej** nowej
  klasy: blokada siedzi w prywatnej metodzie
  `WyslijLinkDoLogowania::wymienToken()`, jedynym miejscu, które jej dziś
  potrzebuje.

**KOLEJNOŚĆ BLOKAD ZOSTAJE JEDNA W CAŁYM REPOZYTORIUM: KONTO NAJPIERW.**
To jest ta część, która nie podlega negocjacji, bo dwie różne kolejności
blokad to zakleszczenie, które PostgreSQL rozwiązuje zabiciem jednego
z żądań. Tu blokujemy `users`, a potem dopiero piszemy po
`login_link_tokens` — tak samo, jak `ZamekKonta` blokuje `users`, a potem
`pending_email_changes`. Kolejność jest **pilnowana testem**
(`test_wymiana_tokenu_idzie_pod_blokada_wiersza_konta`), nie tylko
komentarzem: test oblewa się zarówno wtedy, gdy blokady nie ma, jak i wtedy,
gdy jest brana po `DELETE`.

Blokujemy wiersz **konta**, a nie wiersz tokenu, z tego samego powodu co
w `ZamekKonta`: wiersza tokenu może nie być, a `SELECT … FOR UPDATE` na
nieistniejącym wierszu nie blokuje niczego i nie powstrzyma drugiego
`INSERT`-a. Konto istnieje zawsze i jest wspólne dla obu próśb.

**Do zrobienia po scaleniu `claude/wyscig-zmiany-adresu`:** przenieść to
jedno wywołanie na `ZamekKonta::zablokuj()` i skasować prywatną transakcję
tutaj. To jest sprzątanie, nie poprawka — kolejność blokad jest już zgodna,
więc zwłoka nie tworzy ryzyka zakleszczenia.

**Zmiana wymaga:** drugiego miejsca wystawiającego token logowania linkiem
(wtedy blokada MUSI wyjść z tej klasy do wspólnego zamka, bo dwie kopie tej
samej kolejności rozjadą się przy pierwszej zmianie) albo rezygnacji
z `UNIQUE(user_id)` na `login_link_tokens` — czyli z zasady „jeden ważny
link na konto" (D-056), a to jest osobna decyzja i dziś nie ma dla niej
powodu.

📄 `app/Domain/Security/WyslijLinkDoLogowania.php` ·
`app/Http/Controllers/Auth/LoginLinkController.php` ·
`database/migrations/2026_09_10_100000_create_login_link_tokens_table.php` ·
`tests/Feature/WyscigLinkuDoLogowaniaTest.php` ·
`tests/Feature/LogowanieLinkiemTest.php` ·
`app/Domain/Users/ZamekKonta.php` (po scaleniu `claude/wyscig-zmiany-adresu`) ·
D-048 · D-050 · D-056

---

## D-080 · Blokada i obserwowanie nie mogą współistnieć: jedna kolejność blokad na PARZE osób, rewalidacja pod blokadą i twarda bariera w bazie

**Data:** 10 września 2026 · **Znalezisko:** SOCIAL-01 (P1) z audytu trzeciej
warstwy · Status: **obowiązuje**

### Co było zmierzone PRZED zmianą

`FollowUser::handle()` sprawdzał blokadę zwykłym `exists()`
(`hasBlockRelationWith`) i zaraz potem robił `attach()` — **bez transakcji
i bez blokady wiersza**. `BlockUser::handle()` robił swoje dwie rzeczy (zapis
blokady i `detach` obserwowania w obie strony) w jednej transakcji, ale
transakcja jednej strony nie pomaga, gdy druga strona nie blokuje niczego.

Przeplot odtworzony deterministycznie (`BlokadaWygrywaZObserwowaniemTest`,
wstrzyknięcie przez `DB::listen` w chwilę po odczycie tabeli `blocks`):

| Krok | Żądanie A („Obserwuj") | Żądanie B („Zablokuj") |
|---|---|---|
| 1 | pyta o blokadę → nie ma | |
| 2 | | zapisuje blokadę, zdejmuje obserwowanie w obie strony |
| 3 | dopina `follows` | |

Zmierzony wynik na `origin/main` @ `94081ff`: **po blokadzie w tabeli
`follows` zostaje wiersz.** Test oblewał się z komunikatem „Po blokadzie
zostało obserwowanie".

**Jedna teza znaleziska NIE potwierdziła się w tym pomiarze.** Audyt mówi, że
człowiek dostaje wtedy także powiadomienie „X zaczyna Cię obserwować" po
zablokowaniu. Na jednym połączeniu tego nie widać: `NotifyUser` ma własny
filtr blokad i odczytuje relację jeszcze raz, już po zapisie żądania B, więc
powiadomienie było wyciszane. Ta teza zostaje **niepotwierdzona i możliwa
zarazem** — przy dwóch prawdziwych połączeniach odczyt `NotifyUser` też
mógłby nie zobaczyć jeszcze niezatwierdzonej blokady. Nie sprzedajemy jej
jako zmierzonej.

### Dlaczego to jest granica prywatności, nie kosmetyka

Blokada w tym serwisie ma jedno zadanie: żeby ktoś przestał widzieć moje
rzeczy i przestał się pojawiać w moim życiu. Zostawione obserwowanie znaczy,
że zablokowana osoba dalej dostaje moje wpisy w swoim **feedzie
obserwowanych** — czyli blokada nie zrobiła tej jednej rzeczy, po którą
człowiek po nią sięgnął. Reszta filtrów widoczności (`visibleTo`,
wyszukiwarka ludzi, listy obserwujących) stoi na założeniu, że relacja
blokady jest **ostateczna**.

I to nie jest wyścig o milisekundy: człowiek blokuje kogoś zwykle **w momencie
konfliktu**, czyli dokładnie wtedy, gdy druga strona jest aktywna i klika.
Dwie osoby robiące coś naraz w tej samej sprawie, nie zbieg okoliczności.

### Decyzja

**1. Obie operacje na parze osób wchodzą przez jedno gardło —
`App\Domain\Social\ZamekPary`.** Transakcja plus `SELECT … FOR UPDATE` na
wierszach OBU kont. Wiersze kont, nie wiersz relacji: wiersza relacji może
nie być, a `FOR UPDATE` na nieistniejącym wierszu nie blokuje niczego i nie
powstrzyma cudzego `INSERT`-a (ten sam powód co w `ZamekKonta`).

**2. NAJWAŻNIEJSZA RZECZ W CAŁEJ TEJ ZMIANIE: przy dwóch wierszach kolejność
blokowania musi być ustalona przez DANE, nie przez wywołanie.** Wiersze są
blokowane **rosnąco po identyfikatorze**. `ZamekKonta` (D-079) tego pytania
nie rozstrzyga i nie mógł — tam jest jeden wiersz, więc nie ma czego
szeregować. Tutaj wierszy są dwa, i gdyby każda operacja brała je w kolejności
swoich argumentów, dwie równoległe operacje na tej samej parze w przeciwnych
kierunkach zakleszczyłyby się nawzajem:

```text
żądanie A (Basia → Marek):  blokuje wiersz Basi,  czeka na Marka
żądanie B (Marek → Basia):  blokuje wiersz Marka, czeka na Basię
```

PostgreSQL wykryłby to po `deadlock_timeout` i **zabiłby jedną transakcję** —
człowiek zobaczyłby błąd serwera zamiast założonej blokady. A „Basia blokuje
Marka" i „Marek obserwuje Basię" w tej samej sekundzie to dokładnie ten
scenariusz, o który w tym zadaniu chodzi. Kolejność stoi w JEDNYM miejscu, bo
dwie kopie tej samej reguły rozjadą się przy pierwszej zmianie. Pilnuje jej
`ZamekParyTest::test_kolejnosc_blokad_nie_zalezy_od_kolejnosci_argumentow` —
sprawdzany **wprost, przez podejrzenie wykonanych zapytań**, bo złamanie
kolejności nie objawia się złym wynikiem, tylko zakleszczeniem, którego żaden
test sekwencyjny nie zobaczy.

**3. Blokady wierszy brane są dwoma osobnymi zapytaniami, nie jednym
z `ORDER BY`.** `WHERE id IN (a, b) ORDER BY id FOR UPDATE` blokuje wiersze
w kolejności, w jakiej wypuszcza je plan (`LockRows` nad `Sort`) — w praktyce
dobrze, ale to zależy od planu, a plan od statystyk i wersji bazy. Gwarancja
trzymająca się na kształcie planu nie jest gwarancją.

**4. Warunek jest sprawdzany PONOWNIE pod blokadą.** Blokada tylko ustawia
w kolejce; nie mówi żądaniu A, że świat zmienił się, gdy ono czekało.
Sprawdzenie przed blokadą **zostaje** — służy taniej odmowie bez transakcji
w najczęstszym przypadku (ktoś klika „Obserwuj" u osoby, którą już
zablokował). Oba komunikaty są **identyczne**: człowiek nie ma prawa
dowiedzieć się z treści zdania, czy trafił w wyścig.

**5. Powiadomienie o nowym obserwującym powstaje POD blokadą**, w tej samej
transakcji co wiersz `follows` — albo są oba, albo nie ma żadnego.
`NotifyUser` tylko zapisuje do bazy (nie wysyła poczty, nie kolejkuje
zadania), więc wejście z nim do transakcji nic nie kosztuje i nie wysyła
niczego przed `COMMIT`-em.

**6. Do tego twarda bariera w bazie: wyzwalacz
`follows_blokada_ma_pierwszenstwo_trg`** (`BEFORE INSERT ON follows`).
Odrzuca zapis, jeśli dla tej pary istnieje **zatwierdzona** blokada
w którąkolwiek stronę. Zasada z D-079 §4 bez zmian: `exists()` w PHP jest
dobre na ładny komunikat, gwarancję daje constraint albo lock.

Bariera i blokada **nie zastępują się wzajemnie i trzeba obu**:

| | pilnuje | nie pilnuje |
|---|---|---|
| wyzwalacz | każdej DROGI ZAPISU — druga akcja dopisana za pół roku, komenda, seeder, ręczny `INSERT` w psql | równoległości: przy `READ COMMITTED` nie widzi blokady jeszcze niezatwierdzonej |
| `ZamekPary` | RÓWNOLEGŁOŚCI dwóch żądań na tej samej parze | dróg zapisu, które go omijają |

### Czego świadomie NIE zrobiliśmy

**`CHECK` ani `EXCLUDE` zamiast wyzwalacza.** Inwariant dotyczy DWÓCH tabel,
a `CHECK` w PostgreSQL ma prawo patrzeć tylko na sprawdzany wiersz
(podzapytanie jest zabronione, a `CHECK` na funkcji czytającej drugą tabelę
nie jest wymuszany przy zmianie tamtej tabeli i zawodzi przy
`pg_restore`). `EXCLUDE` działa w obrębie jednej tabeli.

**Symetrycznego wyzwalacza na `blocks` NIE MA — i to jest decyzja, nie
przeoczenie.** Obie tabele nie są równorzędne: **blokada musi się udać
zawsze.** To jedyna czynność, jaką człowiek ma, gdy ktoś staje się dla niego
problemem, a bariera potrafiąca jej ODMÓWIĆ (bo istnieje jakiś wiersz
`follows`) byłaby zamkniętymi drzwiami w najgorszym możliwym momencie.
Konflikt na tej stronie rozstrzyga `BlockUser`, kasując obserwowanie w obie
strony pod blokadą wierszy — jawnie, w kodzie, który da się przeczytać.
Wyzwalacz, który zamiast odmawiać cicho KASOWAŁBY wiersze w drugiej tabeli,
byłby jeszcze gorszy: ukryta mutacja za plecami wywołującego zamienia każdą
przyszłą sesję debugowania w zgadywanie.

**Migracja nie sprząta danych istniejących.** Gdyby na produkcji leżał już
wiersz-sierota z tego wyścigu, wyzwalacz go nie ruszy — pilnuje nowych
zapisów. Kasowanie relacji społecznych migracją, bez wglądu w to, co zostało
skasowane, jest tą destrukcyjną operacją, której zabrania `AGENTS.md` §6.
Zapytanie diagnostyczne jest w `docs/DATABASE.md`; sprzątanie to osobna,
jawna decyzja.

**Nie ruszaliśmy `visibleTo` ani wyszukiwarki ludzi** — one czytają relację
blokady i są poprawne. Nie ruszaliśmy `ZamekKonta`; `ZamekPary` jest osobną
klasą w osobnej domenie, bo szereguje coś innego (parę, nie konto) i ma
regułę, której `ZamekKonta` nie ma (kolejność).

### Skutek uboczny, który wyszedł za darmo

Znalezisko P2 „równoległe podwójne follow powinno być idempotentne" jest
zamknięte przy okazji. Wcześniej dwa równoległe kliknięcia „Obserwuj" oba
widziały „nie obserwuję" i oba robiły `attach`, więc drugie dostawało
naruszenie klucza głównego `follows` — błąd serwera za powtórzone kliknięcie.
Teraz drugie żądanie czyta stan po pierwszym i zwraca `false`, a kontroler
mówi „Już obserwujesz tę osobę." Pilnuje tego
`test_powtorne_obserwowanie_nie_dubluje_wiersza`.

### Plan rollbacku

`down()` migracji zdejmuje wyzwalacz i funkcję. Jest bezstratny — nie zmienia
danych — i wolno go wykonać na produkcji pod ruchem: żaden kod nie zależy od
wyzwalacza, a gwarancję dla drogi przez `FollowUser` trzyma dalej
`ZamekPary`. Cofnięcie samego `ZamekPary` wymaga rewertu commita.

**Zmiana wymaga:** zmierzonego kosztu wyzwalacza przy zapisie do `follows`
(dziś to jedno indeksowane `EXISTS` na kliknięcie „Obserwuj", a `FollowUser`
i tak wykonuje to samo pytanie) albo przypadku, w którym blokada dwóch
wierszy kont okazuje się zbyt szeroka. Kolejność blokad rosnąco po
identyfikatorze **nie podlega zmianie bez zmiany jej we WSZYSTKICH miejscach
naraz** — połowiczna zmiana daje zakleszczenia.

📄 `app/Domain/Social/ZamekPary.php` · `app/Domain/Social/Actions/FollowUser.php` ·
`database/migrations/2026_09_10_400000_obserwowanie_nie_wspolistnieje_z_blokada.php` ·
`tests/Feature/BlokadaWygrywaZObserwowaniemTest.php` ·
`tests/Feature/ZamekParyTest.php` · `docs/DATABASE.md`

---

## D-081 · Pod wpisem widać, ile OSÓB zapisało go do zeszytu — autor od pierwszej, obcy od trzeciej; liczba, nie imiona; nigdzie sortowania

**Data:** 10 września 2026 · **Decyzja właściciela** (issue #275) · Status: **obowiązuje**

### Co zdecydował właściciel i czego to nie znaczy

Właściciel powiedział wprost, po wysłuchaniu argumentów przeciw:

> „ale jednak to trzeba pokazać ile osób zapisało, żeby autor wiedział i inni
> wiedzieli, i autor czuł się doceniony, **nie chodzi o rywalizację
> a docenienie**"

Ta decyzja **nie odwraca** żadnej z zasad, które licznika dotyczyły:
`AGENTS.md` §12 (zakaz publicznych rankingów użytkowników) i `CLAUDE.md`
(feed chronologiczny, bez punktów i grywalizacji) obowiązują dalej. Zmienia się
jedna rzecz: pod wpisem stoi zdanie o tym, ilu LUDZIOM ten wpis się przydał.
Liczba, która DOCENIA, i liczba, która USTAWIA W SZEREGU, mają ten sam
kształt — różni je to, komu i od kiedy się ją pokazuje, i gdzie się jej
NIE pokazuje. Cała reszta tego wpisu jest o tej różnicy.

### Próg: autor od 1, ktokolwiek inny od 3

Powód progu jest **arytmetyczny, nie ideologiczny**: serwis jest na starcie
prawie pusty (`docs/product/COLD_START.md`). Licznik liczony od jednego
pokazywałby pod większością dań „1 osoba zapisała", a pod czyimś pierwszym
daniem — nic. „1 osoba zapisała" docenia autora **słabiej niż brak liczby**,
a zero obok cudzej dziesiątki jest dokładnie tym, przed czym ostrzega
`docs/brand/COPY_STYLE.md` przy zakazie komplementów za publikację
(ponad połowa osób 50+ w mediach społecznościowych nigdy nic nie publikuje —
`docs/research/AUDIENCE_50_PLUS.md`).

**Autor widzi liczbę od pierwszego zapisu**, bo to jest cała treść decyzji
właściciela: autor ma prawo wiedzieć, że jego danie komuś się przydało. Reszta
świata nie ma czego porównywać, dopóki liczby są jednocyfrowe.

**Dla obcych próg wynosi 3, nie 2** — i to jest wybór, nie zaokrąglenie.
Właściciel w tym samym zgłoszeniu sam nazwał dwójkę liczbą, która wypada
słabo: *„ludzie widzieli że to zapisało 10 osób a to tylko 2"*. Skoro „tylko 2"
czyta się jak porażka, próg musi stać NAD dwójką — inaczej licznik pokazywałby
obcym dokładnie tę liczbę, która autorowi szkodzi. Precedens na próg
widoczności licznika jest w projekcie od #38: stopka nie pokazuje liczby
kuKINGów poniżej 20 (`LiczbaKukingow`, D-012) z tego samego powodu.

### Liczba, nie imiona — bo zeszyt jest PRYWATNY (sprawdzone w kodzie)

Rozważane było „zapisali to: Halina, Marek i jeszcze 3 osoby" — informacja
o LUDZIACH zamiast wyniku, lepiej pasująca do serwisu, który nigdzie nie ma
punktów. **Odpada**, i to nie z ostrożności, a z ustalenia w kodzie:

- `collections.visibility` ma `DEFAULT 'private'`
  (migracja `2026_09_05_000800_create_collections_tables`), a komentarz tej
  migracji mówi to wprost: *„Ktoś, kto zapisuje przepis »na potem«, nie ogłasza
  tego światu. Publiczna kolekcja jest świadomą decyzją, nie ustawieniem
  domyślnym"*;
- ekran zeszytu (`CollectionController::show()`) i `CollectionPolicy` traktują
  zeszyt jak cudzy pojemnik z własną granicą widoczności.

Zapisanie cudzego wpisu do zeszytu **nie jest dziś czynnością publiczną**,
więc nie wolno jej taką zrobić bez osobnej decyzji właściciela. Imiona
wyciągnęłyby na wierzch zawartość prywatnych zeszytów. Sama liczba — i to od
trzech dla obcych — niczyjego zeszytu nie zdradza.

### Kto się liczy

`users.status = active`, czyli granica „promocyjna", ta sama co
`Post::scopeTylkoOdAktywnychAutorow()`, `DiscoverFeed` i `SearchQuery`
(audyt A5). Nie `widocznyJakoOsoba()`, bo tamten zakres przepuszcza konta
**zawieszone**, a zapis od konta pod sankcją nie ma podbijać liczby
pokazywanej nieznajomym. Jednym warunkiem wypadają konta zbanowane,
zawieszone, w trakcie usuwania i usunięte.

**Blokada — w obie strony i bezwarunkowo** (`AGENTS.md` §4), tym samym
wzorcem co `Comment::scopeWidoczneDla()` i `CookedEvent::scopeWidoczneDla()`.
Liczba jest więc policzona OCZAMI WIDZA.

**Własny zapis autora się nie liczy.** Licznik, który autor może sobie sam
podbić, nie jest informacją o niczym.

`count(distinct users.id)`, nie `count(*)`: liczymy LUDZI, a jedna osoba
z dwoma zeszytami może wrzucić ten sam wpis dwa razy.

### Po „Zapisuję" widać, że się zapisało (część 1 — usterka, nie decyzja)

Potwierdzenie **istniało**: `CollectionController::savePost()` ustawiał
komunikat „Zapisane w zeszycie …", a `components/layout.blade.php` pokazuje go
w `.flash` z `aria-live`. Czego nie było: śladu **w miejscu, gdzie człowiek
kliknął**. Komunikat stoi na górze strony, „Zapisuję" klika się w połowie
feedu, a karta po powrocie wyglądała identycznie jak przed kliknięciem.
Przy grupie 50–75 to jest ta cisza, po której człowiek klika drugi raz.

Naprawa nie dokłada drugiego mechanizmu komunikatów: karta pokazuje **stan**,
tak jak ekran przepisu robi to od dawna (`$isSaved`). Stanem jest zdanie
„Masz to w zeszycie" z odnośnikiem do zeszytu, a **nie** przycisk kasujący
— w feedzie przycisk usuwający pod tym samym palcem zabierałby z zeszytu to,
co ktoś właśnie do niego włożył (podwójne kliknięcie w tej grupie to norma,
issue #43). Wyjąć z zeszytu można nadal w samym zeszycie.

> **Poprawione 20 września 2026 — patrz D-224.** Ostatnie zdanie było
> nieprawdziwe: ekran zeszytu renderuje TĘ SAMĄ kartę, więc przycisku
> wyjęcia nie było tam, gdzie to zdanie obiecywało (audyt L1). Przycisk
> „Usuń z zeszytu" stoi teraz na karcie, OBOK odnośnika „Masz to
> w zeszycie" — a nie zamiast niego, więc opisana wyżej obawa o podwójne
> kliknięcie zostaje zaadresowana układem.

Wszystko działa **bez JavaScriptu**: formularz `POST`, przekierowanie, `GET`.

### Gdzie liczba stoi, a gdzie CELOWO nie

**Jest — dla ZALOGOWANEGO:** feed obserwowanych, „Świeżo z Kuking"
(`/odkryj`), feed tagów, strona tematu, profil, zeszyt, ekran pojedynczego
wpisu — czyli tam, gdzie wpis stoi w chronologicznym strumieniu albo sam.

**Nie ma i to jest część decyzji:**

- **„kuKINGi na dziś"** (`DailyBoard`) — cztery dania wybrane redakcyjnie,
  obok siebie; liczba pod nimi byłaby zestawieniem, nie docenieniem;
- **wyniki wyszukiwania** — liczby jedna pod drugą to porównanie;
- **wszędzie dla GOŚCIA** — liczba mówi „przydało się ludziom z tej
  społeczności" i jest adresowana do jej członków, nie do otwartego
  internetu. Praktyczny powód dokłada się do zasady: strona powitalna układa
  wpisy w siatkę (`landing-wpisy`), a liczby jedna obok drugiej to
  zestawienie. Wychodzi z tego jedna reguła zamiast wyjątku na ekran: **nie
  ma widza, nie ma liczby** — więc gość nie zobaczy jej ani na stronie
  powitalnej, ani na `/odkryj` bez logowania.

Technicznie robi to jedna rzecz: karta pokazuje liczbę tylko wtedy, gdy
zapytanie ekranu ją doliczyło (`ZapisyWpisu::dolicz()`), więc ekran, który jej
nie dolicza, nie pokazuje nic i **nie odpala zapytania na kartę**. Ten sam
wzorzec co `relationLoaded('tags')`.

### Czego ta decyzja NIE rozstrzyga i co wymaga OSOBNEJ decyzji właściciela

1. **Sortowanie, ważenie ani promowanie po liczbie zapisów.** Feed jest
   chronologiczny (`CLAUDE.md`, `AGENTS.md`), a audyt z 10.09 stawia „nie
   budować algorytmu feedu" jako punkt 3 listy „czego NIE robić". Właściciel
   wspomniał o „algorytmie, żeby pokazywał ciekawe tematy" — to jest **punkt 3
   issue #275**, sprawa osobna i wyłączona z tej zmiany; droga do „ludzie widzą
   ciekawe rzeczy" prowadzi przez jawne tematy (#273), nie przez popularność.
2. **Żadnych zestawień** typu „najczęściej zapisywane".
3. **Imiona osób, które zapisały** — wymagają najpierw rozstrzygnięcia, czy
   zapisywanie do zeszytu ma być czynnością publiczną. Dziś nie jest.
4. **Powiadomienie autora o zapisaniu WPISU.** Przy przepisie takie
   powiadomienie jest, przy wpisie nie ma i ta zmiana tego nie dokłada —
   powód stoi w `SavePostToCollection` (ekran powiadomień renderuje każdy typ
   osobno, więc nowy typ bez własnego tekstu dałby pusty wiersz).
5. **Liczba pod wspomnieniem** („rok temu") — jeden ekran, którego ta zmiana
   nie dotknęła; do dołożenia, gdyby właściciel chciał.

**Zmiana wymaga:** decyzji właściciela. Próg jest pilnowany testem
(`LicznikZapisowWidacOdProguTest::test_prog_dla_obcych_jest_decyzja_wlasciciela`),
żeby nie dało się go przesunąć po cichu.

**Pliki:** `app/Domain/Collections/ZapisyWpisu.php` ·
`resources/views/components/post-card.blade.php` ·
`app/Domain/Feed/{FollowingFeed,DiscoverFeed,TagFeed}.php` ·
`app/Http/Controllers/{PostController,ProfileController,TagController,CollectionController}.php` ·
`tests/Feature/LicznikZapisowWidacOdProguTest.php` ·
`tests/Feature/LicznikZapisowBezWachlarzaZapytanTest.php` ·
`tests/Feature/PoZapisaniuWidacPotwierdzenieTest.php`

---

## D-072 · Zgoda na tygodniowy digest ma dziennik append-only `dziennik_zgod`; rollback migracji zgody nie przywraca `DEFAULT true`

**Data:** 10 września 2026 · Audyt 10.09.2026 (DB1, DB2, część 10 §4) ·
Status: **obowiązuje**

Dwa rozstrzygnięcia z jednego audytu, obydwa o tej samej rzeczy: o tym, żeby
mailing wychodził **wyłącznie** do ludzi, którzy o niego poprosili, i żeby dało
się to **wykazać**.

### 1. Dowód zgody: append-only `dziennik_zgod`, nie dwie kolumny z datami

**Stan przed zmianą, sprawdzony w kodzie:**
`PrivacySettingsController::update()` zapisywał wyłącznie boolean
`wants_weekly_digest`; `users` miało `weekly_digest_sent_at` (kiedy poszedł
ostatni list), ale ani śladu, **kiedy i skąd** zgoda została udzielona i kiedy
wycofana. `PodsumowanieTygodniaController` przestawiał ten sam boolean
`forceFill()`-em, a `EraseAccountData` gasił go przy anonimizacji konta —
trzy niezależne miejsca, zero zapisu historii.

RODO art. 7 ust. 1 wymaga, żeby administrator **był w stanie wykazać** zgodę.
„Dziś pole ma wartość `true`" jest ostatnią klatką filmu, którego nikt nie
nagrywał: nie odpowiada, kiedy człowiek kliknął, czy wcześniej tego nie
odklikał ani czy po wycofaniu wysyłka nie szła dalej. A wysyłka **już
istnieje** (D-057, `kuking:wyslij-podsumowania` od 10 września), więc to
przestało być rozważaniem teoretycznym.

**Odrzucone: dwie kolumny (`..._consented_at` / `..._withdrawn_at`).** Audyt
dopuszczał je jako minimum. To minimum jest za małe i widać to na jednym
przykładzie: przy ciągu włącz → wyłącz → włącz trzecia zmiana nadpisuje
pierwszą, więc zostaje obraz, w którym nie da się odróżnić osoby zapisanej raz
od osoby, która zmieniała zdanie. Dwie kolumny odpowiadają na „kiedy
ostatnio", a pytanie dowodowe brzmi „co się działo".

**Odrzucone: JSONB z historią na `users`.** AGENTS.md §6 — JSONB tylko dla
danych półstrukturalnych. Zdarzenie zgody ma cztery zawsze te same pola
i zamknięte zbiory wartości; to są dane strukturalne, więc dostają kolumny
i `CHECK`-i, których baza umie pilnować.

**Przyjęte:** tabela `dziennik_zgod` (`user_id`, `cel`, `czynnosc`, `zrodlo`,
`wersja_polityki`, `wystapilo_at`), pełny opis w `docs/DATABASE.md`. Wszystkie
cztery miejsca, w których zgoda się zmienia, idą teraz przez jedną klasę
domenową `App\Domain\Zgody\PrzestawZgodeNaDigest`: ekran
`/ustawienia/prywatnosc` (`ustawienia`), podpisany odnośnik ze stopki listu
i nagłówka `List-Unsubscribe` (`link_wypisania`), przycisk powrotny na ekranie
po wypisaniu (`link_powrotny`) i anonimizacja konta (`usuniecie_konta`).
Wiersz powstaje **tylko przy realnej zmianie** — formularz prywatności wysyła
stan haczyków przy każdym zapisie, a na odnośnik wypisania wchodzi się dwa
razy (odświeżenie, skaner odnośników w firmowej poczcie); dziennik pełen
„wycofań", których nikt nie wykonał, byłby gorszym dowodem niż brak dziennika.

**Bez IP i bez `User-Agent`.** Wiele bibliotek do zgód zapisuje jedno i drugie
„na wszelki wypadek". Do wykazania zgody nie są potrzebne: dowodem jest fakt,
moment, cel i droga, a nie numer, z którego ktoś wtedy korzystał. AGENTS.md §7
zabrania PII tam, gdzie nie musi być, a `audit_log` trzyma IP wyłącznie jako
HMAC i tylko tam, gdzie służy wykrywaniu nadużyć — zgoda nie jest nadużyciem.
Pilnuje tego asercja na **pełną listę kolumn** tabeli, więc oblewa się także
wtedy, gdy ktoś doda kolumnę nazwaną neutralnie (`kontekst`, `meta`) i włoży
tam to samo.

**Append-only naprawdę, nie z nazwy.** Wyzwalacze w bazie
(`dziennik_zgod_bez_zmian` na `UPDATE`/`DELETE`, `dziennik_zgod_bez_czyszczenia`
na `TRUNCATE`) rzucają wyjątek. Świadomie `RAISE EXCEPTION`, a nie reguła
`DO INSTEAD NOTHING`: reguła połknęłaby zmianę bez słowa i kod „poprawiający"
dziennik działałby dalej w przekonaniu, że coś zmienił. Model `WpisZgody`
blokuje `update`/`delete` również w PHP — to pierwsza linia (czytelniejszy
błąd), nie jedyna, bo `DB::table('dziennik_zgod')->update(...)` i ręczny `psql`
jej nie widzą. `DROP TABLE` **nie** jest blokowany: `migrate:refresh` w CI
i `RefreshDatabase` w testach muszą działać, a append-only dotyczy wierszy
w działającym serwisie, nie istnienia schematu.

### Napięcie, którego nie ma sensu ukrywać: dowód zgody vs prawo do usunięcia

RODO art. 17 każe usunąć dane na żądanie; art. 7 ust. 1 i art. 5 ust. 2 każą
móc wykazać zgodę — także po tym, jak ktoś konto usunął, bo właśnie wtedy
najczęściej pojawia się spór („nigdy się na to nie zapisałem"). Skasowanie
dziennika razem z kontem oznacza brak dowodu; zachowanie go w pierwotnej
postaci oznacza dane po osobie, która poprosiła o usunięcie.

**Wybór:** dziennik zgód **zostaje**, bez anonimizacji własnych wierszy,
i jest to możliwe wyłącznie dzięki temu, co Kuking robi już od D-022:
**konta się nie kasuje, tylko anonimizuje.** Po `EraseAccountData` wiersz
`users` nie ma adresu e-mail, hasła, nazwy ani awatara, więc `user_id`
w dzienniku wskazuje na rekord, który sam z siebie nikogo nie identyfikuje —
zostaje dowód, że wysyłka miała podstawę prawną, bez trzymania danych
osobowych dłużej niż konto. To ten sam wzorzec, którym `EraseAccountData`
świadomie nie tyka zgłoszeń, odwołań ani `audit_log`, i ta sama logika, która
trzyma `account.data_erased` w `AuditLogEntry::NIGDY_NIE_KASUJ`.

Do tego anonimizacja **domyka historię**: dopisuje wiersz `wycofana` ze
źródłem `usuniecie_konta`. Bez niego dziennik kończyłby się na „udzielona"
i w papierach wyglądałby na zgodę obowiązującą do dziś, choć konto zostało
wymazane.

**Kasowanie konta nadal działa i to jest warunek, nie nadzieja** —
`DowodZgodyNaDigestTest` uruchamia prawdziwe `EraseAccountData` na koncie ze
zgodą i na koncie bez zgody. Klucz obcy ma `ON DELETE RESTRICT`: nie `CASCADE`
(skasowałby dowód dokładnie wtedy, gdy jest potrzebny) i nie `SET NULL`
(byłby `UPDATE` na tabeli append-only, a dowód niczyj to dowód żaden).
**Nazwany skutek uboczny:** twardy `DELETE FROM users` dla konta, które
kiedykolwiek ruszyło tę zgodę, odmówi wykonania. Nic w serwisie tego nie robi;
gdyby kiedyś było naprawdę potrzebne, jest to świadoma decyzja człowieka po
zdjęciu wyzwalacza, a nie skutek uboczny kaskady.

**Punkt otwarty, świadomie niedokończony w tym PR-ze: retencja.** Dziennik
zgód nie ma komendy sprzątającej (dlatego nie ma też indeksu po samym czasie —
nie budujemy indeksu bez czytelnika). Wiersz to siedem krótkich pól na jedną
zmianę zgody, więc tabela rośnie wolniej niż `product_signals`. Docelowy okres
przechowywania dowodów zgód należy rozstrzygnąć razem z resztą retencji,
w `docs/decyzje/ADR_RETENCJE.md`, przy przeglądzie prawnym (issue #8) — a nie
tu, zgadując liczbę miesięcy.

**Drugi punkt otwarty:** dziennik nie jest jeszcze widoczny dla samego
człowieka — nie ma go ani w paczce danych (`CollectUserExportData`), ani na
ekranie prywatności. To osobna zmiana produktowa (dwie decyzje UX: co pokazać
i jak nazwać), a nie warunek dowodowy z art. 7 ust. 1, który ten PR zamyka.

### 2. Rollback migracji zgody nie przywraca groźnego zachowania

**Stan przed zmianą:**
`database/migrations/2026_09_07_400000_default_weekly_digest_to_off.php`
w `down()` wykonywał `ALTER TABLE users ALTER COLUMN wants_weekly_digest SET
DEFAULT true`.

Ta migracja powstała **właśnie dlatego**, że `DEFAULT true` zapisywał ludzi na
mailing bez ich decyzji — formularz rejestracji o tę zgodę nie pyta ani jednym
polem i nadal nie pyta. Gdy migrację pisano, `DEFAULT true` był niegroźny:
digestu nie było w kodzie w ogóle. **Od 10 września wysyłka istnieje**, więc
techniczny rollback tworzyłby NOWE konta z aktywnym mailingiem bez zgody.
Komentarz w migracji słusznie pilnował, żeby nie ruszać istniejących wierszy,
ale o nowych milczał.

**Decyzja: `down()` jest pusty i `DEFAULT false` zostaje także po cofnięciu.**
Asymetria jest pełna i jawna: `up()` przestawia `DEFAULT` oraz istniejące
wiersze, `down()` nie przywraca ani jednego, ani drugiego. Tak, tej migracji
nie da się cofnąć „wiernie historycznie" — i tak ma być. **Wierny rollback
przywraca też wadę, którą migracja naprawiła**, a przy poczcie wychodzącej ta
wada jest nieodwracalna: listu wysłanego bez zgody nie da się odwołać.
Bezpieczny rollback bije wierny wszędzie tam, gdzie wierny wraca do stanu
groźnego. Metoda `down()` **zostaje** (pusta, z uzasadnieniem): bez niej
`migrate:rollback` przewracałby się na tej migracji, a `migrate:refresh` — CI
je uruchamia — nie zszedłby poniżej niej.

**Do tego twarda bramka.** `App\Domain\Digest\BramkaDomyslnejZgody` pyta
`information_schema` przy każdym uruchomieniu `kuking:wyslij-podsumowania`
i przy `DEFAULT true` kończy kodem 1, z komunikatem mówiącym, co zrobić.
Komentarz w migracji przeczyta ten, kto ją otworzy; bramka łapie **także** trzy
inne drogi powrotu tej samej wady, przy których nikt nie czyta migracji:
ręczny `ALTER` przy grzebaniu w bazie, przywrócenie bazy z kopii sprzed
migracji i `migrate:rollback` uruchomiony na starszym wydaniu kodu, w którym
`down()` jeszcze przywracał `true`. Przebieg `--na-sucho` przechodzi mimo
zamkniętej bramki (nic nie wysyła, nic nie zapisuje) i tylko ostrzega —
przy takim schemacie chce się właśnie policzyć, ilu ludzi dotyczyłaby pomyłka.

**Zmiana wymaga:** przy (1) — nowej informacji prawnej, z której wynika, że
historia zdarzeń zgody jest zbędna albo że IP jest do jej wykazania konieczne;
przy retencji — rozstrzygnięcia w `ADR_RETENCJE.md`. Przy (2) — dopisania pola
zgody do formularza rejestracji; dopóki rejestracja o zgodę nie pyta, żaden
`DEFAULT true` nie jest zgodą i decyzja stoi.

### Czego pilnują testy, a czego nie — spisane po kontroli ujemnej

Każdy test z obu plików był zepsuty osobnym sabotażem i każdy oblał; tabelka
`test → sabotaż → wynik` jest w opisie PR #270. Przy tym przeglądzie wyszło,
że trzy rozstrzygnięcia z tej decyzji były opisane, ale przez nikogo
niesprawdzane — i dostały własne testy:

1. **Asymetria udzielenia i wycofania przy awarii zapisu dowodu.**
   `PrzestawZgodeNaDigest` nazywa ją najważniejszą rzeczą w pliku, a nie
   pilnował jej ani jeden test. Awarię wymusza się bez ruszania kodu
   produkcyjnego: `kuking.zgody.wersja_polityki` dłuższa niż `varchar(20)`
   psuje wyłącznie `INSERT` do dziennika. Udzielenie ma się wtedy cofnąć
   w całości, wycofanie ma dojść do skutku mimo wszystko.
2. **Zamknięte zbiory wartości.** CHECK-i `cel`, `czynnosc` i `zrodlo` były
   obietnicą w komentarzu; każdy ma teraz własne sprawdzenie (asercja idzie
   na NAZWĘ naruszonego ograniczenia, żeby jeden CHECK nie zaliczył się trzy
   razy) i kontrolę dodatnią na komplecie poprawnych wartości.
3. **`restrictOnDelete()` zamiast kaskady.** Rozstrzygnięcie napięcia „dowód
   zgody vs prawo do usunięcia" nie miało testu, więc podmiana na
   `cascadeOnDelete()` przechodziła bez jednego czerwonego przebiegu.

Czego te testy NADAL nie dowodzą, i trzeba to nazwać: nikt nie broni bazy
przed człowiekiem z prawami właściciela tabeli, który zdejmie wyzwalacz
(`ALTER TABLE … DISABLE TRIGGER`) albo klucz obcy. Append-only pilnuje przed
POMYŁKĄ i przed kodem, nie przed świadomą decyzją administratora — i tak ma
być, bo `down()` migracji też musi działać.

📄 `database/migrations/2026_09_10_400000_create_dziennik_zgod_table.php` ·
`database/migrations/2026_09_07_400000_default_weekly_digest_to_off.php` ·
`app/Models/WpisZgody.php` · `app/Domain/Zgody/PrzestawZgodeNaDigest.php` ·
`app/Domain/Digest/BramkaDomyslnejZgody.php` ·
`app/Http/Controllers/Settings/PrivacySettingsController.php` ·
`app/Http/Controllers/PodsumowanieTygodniaController.php` ·
`app/Domain/Users/Actions/EraseAccountData.php` ·
`app/Console/Commands/WyslijPodsumowaniaTygodnia.php` ·
`config/kuking.php` (`zgody.wersja_polityki`) ·
`tests/Feature/DowodZgodyNaDigestTest.php` ·
`tests/Feature/RollbackNieWlaczaDigestuTest.php` ·
`docs/DATABASE.md` (`dziennik_zgod`) ·
`docs/research/audyt-2026-09-10/03_BAZA_DANYCH_I_INTEGRALNOSC.md` (DB1, DB2) ·
`docs/research/audyt-2026-09-10/10_RODO_DSA_PRAWO_I_PRYWATNOSC.md` §4 ·
D-022 · D-057

---

## D-082 · Dolna belka zostaje `position: fixed`, a rezerwa miejsca pod nią jest LICZONA — 2.4.11 nie kupujemy kosztem 2.5.8

**Data:** 10 września 2026 · **Decyzja techniczna** (audyt 60+, PR #269) · Status: **obowiązuje**

### Problem: dwa kryteria WCAG, które ciągną w przeciwne strony

Audyt 60+ (`docs/research/AUDYT_60_PLUS.md`, ranking napraw pkt 1) wskazał
naruszenie **WCAG 2.2 AA 2.4.11 — Focus Not Obscured (Minimum)**: `.bottom-nav`
jest `position: fixed`, więc nie ma jej w przepływie dokumentu i wysokość
strony NIE rośnie o jej wysokość. Rezerwa na końcu dokumentu (dolne wypełnienie
`.site-footer`) była stałą wartością `--spacing-20` (80 px), a belka ma
`flex-wrap: wrap` i przy dużym tekście rozpada się na kilka wierszy. Gdy belka
urośnie ponad rezerwę, treść przewija się POD nią — a fokus klawiaturowy ląduje
w całości za paskiem.

Zmierzone maksima wysokości belki (`scripts/dostepnosc.mjs`, sześć ekranów,
320/360/414 px):

| wariant | wysokość belki |
|---|---|
| bez powiększania tekstu | 66,6 px (korzeń 16 px) |
| nasze ustawienie „tekst 140%" | 105,5 px (korzeń 16 px) |
| czcionka przeglądarki 200% | 376,2 px (korzeń 32 px) |

### Droga odrzucona: `position: sticky`

Pierwsze podejście wiązało rezerwę z rzeczywistą wysokością belki, wstawiając ją
w przepływ (`sticky` zamiast `fixed`). **Naprawiało 2.4.11 i łamało 2.5.8
(Target Size Minimum).**

Powód leży w axe, nie w naszym układzie: reguła `target-size` liczy sąsiadów
przez `findNearbyElms`, a ta funkcja porównuje kandydatów warunkiem
`selfIsFixed === isFixedPosition(vNeighbor)`. Nakładka `fixed` nie jest więc
zestawiana z treścią nie-`fixed` — i słusznie, bo przypięty pasek stoi nad inną
treścią przy KAŻDYM położeniu przewijania. `sticky` do tego wyjątku nie należy:
staje się zwykłym sąsiadem w przepływie i przycina „bezpieczne pole kliknięcia"
tego, co akurat widać za nim.

Zmierzone przy 320 px, przewinięcie 0, **jednakowe prostokąty belki** dla obu
wariantów (833,4…900 px):

| element | wolne pole przy `fixed` | przy `sticky` |
|---|---|---|
| profil (własny) | 48 px | 14,5 px |
| dodaj przepis | 55,9 px | 10,1 px |
| twoje tagi | 40 px | 4,5 px |

Nakładanie istniało więc także przed zmianą — zmieniło się tylko to, czy axe je
widzi. Kluczowa obserwacja: **te trzy naruszenia nie są usterkami tych trzech
elementów.** Każdy z nich ma prostokąt większy niż wymagane 24 × 24 px
i przechodzi 2.5.8 z samego rozmiaru. To jedna cena `sticky`, płacona przez ten
element, który akurat wpadnie w pasek przy przewinięciu 0 — a więc zależna od
DŁUGOŚCI STRONY, nie od tych elementów. „Naprawa u każdego z trzech" byłaby
przesuwaniem treści do czasu, aż w pasek wpadnie czwarty.

### Droga odrzucona: sam `scroll-padding` przy obu przypiętych paskach

`scroll-padding` przesuwa fokus spod belki, ale belka zostaje na ekranie. Przy
320 × 740 px i czcionce przeglądarki 200% górny pasek ma 263 px, dolny 376,2 px —
razem 639,2 z 740 px, czyli 86% widoku. Żeby fokus wyjechał spod OBU, suma
wartości musiałaby pokryć te 639,2 px, zostawiając pasmo 100,8 px przy **zerowym**
zapasie na obu — a to pasmo musi zmieścić naszą najmniejszą kontrolkę, czyli
48 px. Taka para liczb nie jest poprawką, tylko zakładem.

Dlatego **górny** pasek poniżej progu `15rem` odpina się (`position: relative`):
przy tej wielkości tekstu problemem nie jest margines przy przewijaniu, tylko to,
że przypięty pasek zabiera trzecią część ekranu na stałe. Górny pasek można
odpiąć — jest nad treścią i przewija się z nią. Dolna belka to główna nawigacja
produktu i odpięcie jej zabrałoby jedyną drogę do „Co dziś ugotowałeś?".

### Decyzja

Belka zostaje `fixed`, a rezerwa jest **liczona jawnie** tokenem
`--rezerwa-pod-belka` i **wydawana w dwóch miejscach**, bo to dwie różne rzeczy:

* **dolne wypełnienie `.site-footer`** — stopka jest ostatnia w dokumencie, więc
  to ona decyduje, czy treść da się wyprowadzić spod belki na końcu strony;
* **`scroll-padding-bottom` na `:root`** — przewijanie fokusu w widok, które
  przeglądarka robi sama po Tab, liczy się do krawędzi okna i nie wie, że stoi
  tam nakładka. Nic nie rysuje, działa wyłącznie przy celowanym przewijaniu.

**Trzy stopnie, nie jedna wartość**, bo dwa powiększenia działają inaczej:
czcionka przeglądarki podwaja KORZEŃ (16 → 32 px), więc `rem` rośnie razem
z belką; nasze `data-text-scale` korzenia nie rusza (`--user-text-scale` mnoży
tylko tokeny `--text-*`), więc `rem` stoi, a belka rośnie.

| zakres | rezerwa | pod co liczona |
|---|---|---|
| domyślnie | `calc(8rem * var(--user-text-scale, 1))` | 128 px przy tekście 100%, 179,2 px przy 140% |
| `max-width: 15rem` | `calc(15rem * var(--user-text-scale, 1))` | 480 px przy korzeniu 32 px (czcionka przeglądarki 200%) |
| `min-width: 64rem` | `0rem` | belki nie ma — rezerwa nie ma czego chronić |

### Poprawka po CI: liczy się LUZ, nie sama rezerwa

Pierwsza wersja tej decyzji miała stopnie `8rem` i `13rem` — bez mnożnika.
Rezerwa była wtedy WIĘKSZA od belki w każdym wariancie, więc sprawdzenie
„czy rezerwa pokrywa belkę" świeciło na zielono przez cały czas trwania
usterki. CI (job „Dostępność" na `c492ed1`) zgłosiło mimo to 2.4.11 FAIL
w trzech miejscach i wyłącznie przy „tekst 140%": `szukaj / 320 px`
(a „Wszystko"), `szukaj / 360 px` (a „Do 30 minut") i `wpis / 360 px`
(a „Napisz komentarz").

Zawodziła nie rezerwa, tylko **luz** — to, co z rezerwy zostaje POWYŻEJ
belki, bo tylko w tym pasku przeglądarka ma gdzie postawić element, który
dostał fokus. Zmierzone (Chromium 141, okno 740 px, rezerwa stała 128 px):

| szerokość | belka przy 140% | luz | wynik na CI |
|---|---|---|---|
| 320 px | 105,5 px | 22,5 px | ✗ |
| 360 px | 89,5 px | 38,5 px | ✗ |
| 414 px | 75,2 px | 52,8 px | ✓ |

Oblewały dokładnie te szerokości, na których luz zszedł **poniżej 48 px**,
czyli poniżej jednej naszej kontrolki. Element wyższy od luzu nie ma jak
stanąć nad belką w całości — i dlatego usterka wychodziła losowo (raz jeden
element, raz trzy, w obrazie deweloperskim wcale): trafiała w ten, który
akurat wpadł w ten pasek. To wyjaśnia też, czemu na `65daf90` CI zgłaszało
jedno naruszenie, a na `c492ed1` trzy, przy tej samej regule CSS.

Przyczyną są dwie jednostki, które miały iść razem, a szły osobno:
`--user-text-scale` mnoży tokeny `--text-*`, ale **korzenia nie rusza**.
Belka rośnie więc z tekstem, a rezerwa w `rem` stoi w miejscu — luz zapada
się dokładnie wtedy, gdy tekst jest największy, czyli u osoby, dla której
ten produkt jest robiony. Mnożnik w `calc()` wiąże rezerwę z tą samą
wielkością, która rozpycha belkę. Luz po poprawce: **73,7 / 89,7 / 104 px**
przy 320 / 360 / 414 px.

Stopień dla bardzo dużego tekstu idzie z `13rem` na `15rem` z tego samego
powodu, liczonego przy korzeniu 32 px: belka 376,2 px kontra 416 px rezerwy
to 39,8 px luzu przy kontrolce 96 px (48 px × podwojony korzeń). `15rem`
to 480 px, czyli 103,8 px luzu. Ten wariant przechodził na CI mimo cienkiego
luzu — poprawiony razem z tamtym, bo to jedna usterka tej samej klasy.

Próg w `rem`, nie w pikselach, bo porównuje okno z KORZENIEM i mówi dokładnie to,
o co chodzi: „tekst jest tak duży w stosunku do ekranu, że przypięty pasek
zabiera jego znaczną część". Telefon 320 px przy korzeniu 16 px to 20rem (próg
nie łapie), ten sam telefon przy 200% to 10rem (łapie). Przy zwykłym korzeniu
próg odpowiadałby oknu 240 px — węższemu niż jakikolwiek telefon, więc nie
zadziała przez pomyłkę.

Rezerwa jest JEDNA DLA WSZYSTKICH, także dla gościa, który dolnej belki nie ma
(`@auth` w `layout.blade.php`). Warunkowanie jej klasą układu gościa
rozdzieliłoby jeden token na dwie wartości dla dwóch jego zastosowań
(wypełnienie stopki dziedziczy po `body`, a `scroll-padding-bottom` rozwiązuje
się na `:root`) — czyli zamieniłoby 48 px pustego miejsca na pułapkę do
nadepnięcia.

### Czego ta decyzja NIE robi

**Nie podnosi progu tolerancji w `scripts/dostepnosc.mjs`** i nie wyłącza żadnej
reguły. Obie — 2.4.11 i 2.5.8 — chodzą i obie zatrzymują CI kodem 1. Podniesienie
progu byłoby zamianą usterki na kłamstwo w pomiarze (`AGENTS.md`, zakaz
„naprawiania" przez rozluźnianie automatu).

### Czym to jest pilnowane

Właściwym automatem jest `scripts/dostepnosc.mjs` — układu strony nie da się
stwierdzić z CSS-a. Ale job `dostepnosc` w CI chodzi WARUNKOWO: czyta `git diff`
i startuje tylko wtedy, gdy zmiana dotyka `resources/`, `public/`,
`scripts/dostepnosc.mjs` albo plików npm. Zmiana w samym `app/` przechodzi obok
niego. Dlatego niezmienniki widoczne w źródle pilnuje dodatkowo
`tests/Feature/RezerwaPodDolnaBelkaTest.php`, który chodzi w jobie `test`, czyli
zawsze: belka dalej `fixed` (nie `sticky`), token wydany w obu miejscach oraz
— po poprawce opisanej wyżej — każdy niezerowy stopień rezerwy mnożony przez
`--user-text-scale`, a największy nie niższy niż `15rem`.

**Kontrola ujemna poprawki** (pełny przebieg `scripts/dostepnosc.mjs` po
przywróceniu stałych 80 px): **109 kontrolek zasłoniętych w 100%**, wśród nich
odnośnik stopki „Prywatność" na wszystkich czterech badanych ekranach przy 320 px
i „tekst 140%". Po poprawce: 0.

**Pliki:** `resources/css/app.css` · `scripts/dostepnosc.mjs` ·
`tests/Feature/RezerwaPodDolnaBelkaTest.php`

---

## D-088 · Rollback migracji ODMAWIA, zamiast po cichu zamienić „usuń wszystko" na „usuń minimum" (MIG-01, #287)

**Data:** 10 września 2026 · **Naprawa błędu z audytu** (issue #287, trzecia
warstwa audytu 10.09.2026, znalezisko MIG-01) · Status: **obowiązuje**

### Co było zepsute — potwierdzone na prawdziwej bazie, nie w teorii

Migracja `2026_09_07_500000_add_erased_status_and_delete_scope_to_users`
dodaje kolumnę `users.delete_scope` (`minimum` | `everything`, D-022) —
zakres, jaki człowiek wybrał na ekranie usuwania konta. Jej `down()` kasowała
tę kolumnę bez warunku, a `up()` przy ponownym uruchomieniu backfillowała
brakującą wartość jako `minimum` dla każdego konta w usuwaniu, bo to jedyna
wartość, jaką umiała wtedy nadać.

Sprawdzone ręcznie na `kuking_test_wt_mig01`, cyklem, który CI wykonuje jako
`migrate:refresh`: konto zgłoszone realną metodą `markForDeletion('everything')`
→ `php artisan migrate:rollback` → `php artisan migrate` → w bazie
`delete_scope = 'minimum'`. Kolumna nie zniknęła z widoku, CHECK-i wróciły
poprawne, żaden wiersz nie zginął — i właśnie dlatego nikt by tego nie
zauważył: to jest cicha podmiana ZNACZENIA decyzji, nie usterka techniczna.
`EraseAccountData::chceUsunacTresci()` czyta tę kolumnę 30 dni później i
zrealizowałaby węższy zakres, niż człowiek naprawdę wybrał.

**Ta sama choroba, którą audyt znalazł już raz jako DB2**
(`2026_09_07_400000_default_weekly_digest_to_off`, `down()` przywracający
`DEFAULT true` dla zgody na cotygodniowy przegląd) — a ten drugi przypadek
zostaje jawnie POZA tą naprawą: sprawdza go równolegle inna gałąź
(`claude/dowod-zgody-na-digest`, PR #270).

### Zasada, nie tylko łatka na jedną migrację

> `down()` nie ma prawa przywracać stanu groźnego ani zmieniać znaczenia
> decyzji człowieka. Przy wartościach semantycznych (zgoda, zakres usunięcia,
> widoczność, prywatność zeszytu) rollback ma **odmówić**, gdy nie da się
> wartości odtworzyć wiernie — zgadywanie cichą wartością domyślną jest
> najgorszą z opcji, bo nie zostawia śladu błędu.

Przegląd całego `database/migrations/` pod tym kątem (krok obowiązkowy przy
tej naprawie) znalazł jeszcze trzy miejsca o podobnym kształcie
(`down()` kasuje kolumnę, `up()` nadaje jej DEFAULT przy ponownym uruchomieniu),
świadomie ZOSTAWIONE poza zakresem #287:

- `2026_09_06_210000_add_theme_to_users` (`theme`, `DEFAULT 'light'`) —
  preferencja WYGLĄDU, nie zgoda ani dane osobowe; rollback zresetowałby
  wybór ciemnego motywu, nie decyzję o danych;
- `2026_09_06_120000_add_display_mode_to_posts` (`display_mode`,
  `DEFAULT 'normal'`) — decyzja AUTORA o prezentacji TREŚCI wpisu, nie
  o własnych danych ani zgodzie;
- `2026_09_06_120000_add_two_factor_to_users_table` — `down()` kasuje sekret
  i kody zapasowe 2FA CAŁKOWICIE (nie ma backfillu, bo nie ma jak odtworzyć
  sekretu), a nie podmienia go cichą wartością domyślną; udokumentowane
  w samej migracji jako świadomy, awaryjny powrót do stanu sprzed funkcji.

Żadne z tych trzech nie dotyczy zgody ani zakresu usunięcia danych — nie
zostały naprawione w tym PR-ze, zgodnie z zawężeniem zlecenia do „decyzji
użytkownika o jego danych albo o zgodzie". Jeśli produkt kiedyś uzna
preferencję wyglądu albo prezentacji treści za wartą tej samej ochrony,
to osobna decyzja, nie rozszerzenie tej.

### Uzupełnienie z 11 września 2026: ten przegląd był NIEKOMPLETNY

**Data uzupełnienia:** 11 września 2026 · PR #327 · zamyka #287

Zdanie „żadne z tych trzech nie dotyczy zgody ani zakresu usunięcia danych"
jest prawdziwe o tych trzech i **niekompletne jako przegląd**. Przegląd
`database/migrations/` przy #287 przeoczył czwarty przypadek tej samej
choroby — `2026_09_06_140000_add_memories_to_users_and_posts` — i nie
wymienił go wcale, ani jako naprawionego, ani jako świadomie pominiętego.
Migracja łamie regułę tego wpisu **w jej własnych słowach**, bo reguła
nazywa **widoczność** wprost.

Zmierzone na prawdziwej bazie cyklem `migrate:rollback` → `migrate`, czyli
tym, co robi `migrate:refresh` w CI i awaryjny rollback wdrożenia:

```text
PRZED:    memories_enabled=false  hide_as_memory=true
PO CYKLU: memories_enabled=true   hide_as_memory=false
```

Po ludzku: **wyłącznik, którym osoba w żałobie wyłączyła wspomnienia, włącza
się sam, a schowany wpis z przepisem po mamie wraca na stronę główną.** Obie
kolumny są `NOT NULL DEFAULT`, więc kolejny `migrate` odtwarza je jako
**odwrotność** obu decyzji.

Dlaczego to NIE jest ten sam przypadek co `theme` i `posts.display_mode`,
pominięte wyżej świadomie i słusznie: to nie jest preferencja wygody.
Własna migracja nazywa pokazanie takiego wpisu bez ostrzeżenia „okrutnym",
`WspomnieniaTest` mówi o „zrobieniu komuś przykrości drugi raz, po tym jak
poprosił, żeby przestać", a kolumna siedzi w ustawieniach **prywatności**
(`PrivacySettingsController`), nie wyglądu.

**Naprawione:** `down()` liczy osobno konta z wyłączonymi wspomnieniami
i schowane wpisy **przed pierwszym `dropColumn`** i odmawia z instrukcją.
Dwie gałęzie warunku mają **osobne** sabotaże w kontroli ujemnej, bo dwa
liczniki nie są ozdobą: konto z włączonymi wspomnieniami i jednym schowanym
wpisem nie ma nic w pierwszym liczniku, a ma co stracić. Sabotaż
„strażnik za `dropColumn`" oblewa wszystkie cztery testy. Stan zakładany
przez **prawdziwe trasy** (`settings.privacy`, `wspomnienia.ukryj`), nie
ręcznym `UPDATE`.

**Czego nie zrobiono i to jest decyzja, nie przeoczenie:** testu skanującego
wszystkie migracje pod tym wzorcem. Heurystyka „`down()` kasuje kolumnę,
którą `up()` nadaje z `DEFAULT`" trafia w każdą zwykłą kolumnę i wymagałaby
ręcznie utrzymywanej listy wyjątków — czyli tego samego co reguła
w `AGENTS.md` §6, tylko z pozorem automatu.

**Wniosek szerszy od jednej migracji, i to jest właściwa treść tego
uzupełnienia:** reguła żyła **tylko** w `docs/DECISIONS.md`, a jedno z jej
złamań chodziło dalej po `main`. Dlatego reguła stoi od 11 września
w `AGENTS.md` §6 — tam, gdzie miała trafić od początku — razem z tabelką
trzech przypadków tej choroby. **Zapisanie reguły w dzienniku nie jest jej
wdrożeniem.**

Osobno, z tego samego PR-a: w komentarzu tamtego `down()` stało „Przy
cofaniu na produkcji najpierw kopia obu kolumn". Zdanie prawdziwe
i konkretne, a jako zabezpieczenie bezwartościowe — przenosiło całą ochronę
na czyjąś pamięć w jedynym momencie, w którym nikt nie czyta komentarzy
w migracjach. **Opis rollbacku nie jest strażnikiem rollbacku**;
zabezpieczeniem jest `throw`.

**Pliki:** `database/migrations/2026_09_06_140000_add_memories_to_users_and_posts.php` ·
`tests/Feature/CofniecieMigracjiNieWlaczaWspomnienTest.php` · `AGENTS.md` §6 ·
`docs/DATABASE.md`

### Naprawa

`down()` liczy `delete_scope = 'everything'` w całej tabeli PRZED jakąkolwiek
operacją i rzuca `RuntimeException` z instrukcją (nie cichym `DELETE` ani
`UPDATE`), gdy choć jedno konto ma tę wartość — ten sam wzorzec odmowy co
`2026_09_10_400100_one_active_data_export_per_user` (D-078) i
`2026_09_07_800000_appeals_open_to_reporters`. Na koncie z `minimum`, albo na
świeżej bazie bez żadnego wyboru, rollback nadal przechodzi bez pytania —
inaczej „naprawą" byłoby zablokowanie rollbacku na zawsze, błąd tej samej
wagi w drugą stronę.

### Kontrola

Test `tests/Feature/CofniecieMigracjiNiePodmieniaZakresuUsunieciaTest.php`
przechodzi PRAWDZIWY cykl `markForDeletion()` → `migrate:rollback --path`,
nie sprawdza tylko kształtu schematu. Kontrola ujemna: przywrócenie
oryginalnego `down()` (bez strażnika) obala test na asercji treści wyjątku;
przywrócenie poprawki — zielono, `git diff` puste.

**Pliki:** `database/migrations/2026_09_07_500000_add_erased_status_and_delete_scope_to_users.php` ·
`tests/Feature/CofniecieMigracjiNiePodmieniaZakresuUsunieciaTest.php` ·
`docs/DATABASE.md`

---

## D-090 · `BlockUser` wchodzi przez `ZamekPary` — dokończenie D-080, bo dwie strony tej samej pary brały wiersze `users` w przeciwnych kolejnościach

**Data:** 10 września 2026 · Audyt kolejności blokad
(`docs/research/2026-09-10-kolejnosc-blokad.md`) · Status: **obowiązuje**

### Co było złamane

D-080 §1 mówi: „**Obie** operacje na parze osób wchodzą przez jedno gardło —
`App\Domain\Social\ZamekPary`". W kodzie weszła **jedna**. Commit realizujący
D-080 (`ab5f4c6`, PR #290) ruszył `FollowUser.php` i `ZamekPary.php` — i tyle.
`BlockUser::handle()` został przy własnej `DB::transaction()` bez ani jednej
blokady wiersza, a `ZamekPary` był importowany wyłącznie w `FollowUser`.

To nie jest rozbieżność stylu. To dwie różne kolejności blokad na tej samej
parze wierszy, czyli dokładnie to, przed czym ostrzega D-079 („dwie różne
kolejności w jednym repozytorium to zakleszczenie, a nie zabezpieczenie").

### Co z tego NIE wynikało — podejrzenie zmierzone i OBALONE

Naturalny wniosek brzmi: skoro `BlockUser` nic nie blokuje, to wyścig
SOCIAL-01 jest nadal otwarty i po blokadzie zostaje obserwowanie. **Ten
wniosek jest nieprawdziwy** i został obalony pomiarem na dwóch połączeniach
do PostgreSQL (opis i skrypty: `docs/research/2026-09-10-kolejnosc-blokad.md`,
pomiar E8).

Powód: `INSERT INTO blocks` **i tak bierze blokady obu wierszy `users`** — bierze
je za niego sprawdzenie kluczy obcych, zapytaniem
`SELECT 1 FROM ONLY "public"."users" x WHERE "id" = $1 FOR KEY SHARE OF x`.
`FOR KEY SHARE` jest w konflikcie z `FOR UPDATE`, więc żądanie „Obserwuj"
ustawiało się w kolejce mimo wszystko. Zmierzony przeplot: przy
niezatwierdzonej transakcji `BlockUser` żądanie „Obserwuj" **nie weszło** na
żaden z dwóch wierszy, a stan końcowy to jedna blokada i zero obserwowań.

Zapisujemy to tak wyraźnie jak znalezisko, bo fałszywy alarm kosztuje tyle
samo co przeoczony błąd (D-064). Ale własność trzymała się na **kształcie
kluczy obcych**, czyli na czymś, czego nie widać w żadnej linijce PHP i czego
nie pilnuje żaden test — a to jest gwarancja przez przypadek, nie przez
projekt.

### Co z tego WYNIKAŁO — zakleszczenie, zmierzone

Blokady z kluczy obcych idą w kolejności **ról**, nie identyfikatorów:
`blocks_blocker_id_foreign` powstało przed `blocks_blocked_id_foreign`, więc
`INSERT` bierze najpierw wiersz blokującego, potem blokowanego. `ZamekPary`
bierze wiersze **rosnąco po identyfikatorze**. Gdy blokujący ma identyfikator
wyższy, obie strony idą pod prąd:

```text
„Obserwuj" (ZamekPary):  bierze wiersz NIŻSZY, czeka na WYŻSZY
„Zablokuj" (BlockUser):  bierze wiersz WYŻSZY, czeka na NIŻSZY
```

PostgreSQL wykrywa cykl i zabija jedną transakcję. W pomiarze (E3) ofiarą
padło **„Zablokuj"**:

```text
ERROR: deadlock detected
CONTEXT: while locking tuple (0,9) in relation "users"
  SQL statement "SELECT 1 FROM ONLY "public"."users" x WHERE "id" = $1 FOR KEY SHARE OF x"
```

Czyli człowiek dostawał błąd serwera zamiast założonej blokady — dokładnie
w sytuacji, dla której D-080 powstało, i wprost przeciw jego zdaniu „blokada
musi się udać zawsze". Ofiarę wybiera baza, więc równie dobrze mogło paść
„Obserwuj"; gorszy z tych dwóch wyników jest ten zmierzony.

### Decyzja

`BlockUser::handle()` wchodzi przez `ZamekPary::zablokuj()`, tak jak
`FollowUser`. Obie strony biorą te same dwa wiersze w tej samej, wyliczonej
z danych kolejności, więc jedna czeka na drugą zamiast zakleszczać się z nią
(kontrola dodatnia naprawy: pomiar E7 — cykl znika).

**Żadnego szóstego mechanizmu.** Nie powstaje nowa klasa, nie zmienia się
`ZamekPary`, nie zmienia się reguła kolejności. Zmienia się jedno: druga
akcja wchodzi przez istniejące gardło, zgodnie z tym, co D-080 już
postanowiło.

**Rewalidacja pod blokadą.** Zamek podaje świeże modele; `null` znaczy „konta
już nie ma" i kończy się `BladDlaCzlowieka` („To konto jest niedostępne."),
a nie naruszeniem klucza obcego i pięćsetką.

**Dziennik audytu zostaje POZA transakcją**, tak jak był. Wpis ma powstać
wtedy, gdy blokada naprawdę się zapisała; wciągnięty pod blokadę zniknąłby
razem z wycofaną transakcją, a jest osobnym śladem, nie częścią relacji.
Pilnuje tego osobny test.

**Tania odmowa „nie można zablokować samego siebie" zostaje przed zamkiem** —
nie ma po co otwierać transakcji, żeby odmówić. Gwarancję i tak trzyma
`blocks_no_self_check` w bazie.

### Czego ta decyzja NIE rozstrzyga

Nie usuwa pozostałych rozjazdów kolejności wykrytych w tym samym audycie
(kasowanie konta rusza `follows`/`blocks` bez `ZamekPary`; `LoginLinkController::
store()` bierze wiersz tokenu bez wiersza konta). Są opisane w raporcie
z naprawami **opisanymi, nie wdrożonymi** — każda jest osobną decyzją.

Nie da się jej też dowieść w istniejącym zestawie testów: `RefreshDatabase`
trzyma cały test w jednej niezatwierdzonej transakcji na jednym połączeniu,
więc drugiego uczestnika wyścigu po prostu nie ma. Testy pilnują
**kontraktu** (że akcja wchodzi przez zamek, w ustalonej kolejności, tej
samej co obserwowanie), a nie skutku. Skutek zmierzono poza zestawem, na
dwóch połączeniach; propozycja wprowadzenia takich testów do repozytorium
jest w raporcie.

**Zmiana wymaga:** rezygnacji z `ZamekPary` jako wspólnego gardła dla pary
osób — a wtedy razem z nią z D-080. Kolejność rosnąco po identyfikatorze nie
podlega zmianie inaczej niż we wszystkich miejscach naraz.

📄 `app/Domain/Social/Actions/BlockUser.php` ·
`app/Domain/Social/ZamekPary.php` ·
`tests/Feature/ZamekParyObejmujeBlokowanieTest.php` ·
`docs/research/2026-09-10-kolejnosc-blokad.md` ·
D-079 · D-080

---

## D-089 · Panel moderacji na szerokim ekranie: bez zarezerwowanej pustej szyny, a z dwóch „Wróć do Kuking" zostaje jedno — to które jest `position: fixed`

**Data:** 10 września 2026 · **Zgłoszenie właściciela, issue #294 (Galaxy Fold
rozłożony)** · Status: **obowiązuje**

Zgłoszenie wskazywało sześć rzeczy naraz na `/admin/uzytkownicy`. Cztery
z nich (nawigacja jako surowa lista zamiast kolumny, samotny „|" nad „Wróć do
Kuking", ucięty rząd zakładek, tabela wypychająca stronę) naprawiła wcześniej
ta sama gałąź, przenosząc `.side-nav-item` poza próg `64rem` i dodając
`flex-wrap` do `.tabs` oraz `position: relative` do kontenera tabeli. Dwie
pozostałe (punkty 2 i 4 zgłoszenia) wymagały osobnej decyzji — issue wprost
mówi „do decyzji, które" przy drugiej z nich. Ten wpis zapisuje obie, żeby
nikt ich nie odkręcił, uznając za przeoczenie.

### 1. Panel nie dostaje trzeciej kolumny (`.app-rail`) od `80rem`

**Stan sprzed zmiany, sprawdzony w kodzie:** `.app-body` od `80rem`
rezerwowała trzy kolumny (nawigacja, treść, szyna) na **każdym** ekranie
serwisu — nawet gdy żaden slot `rail` nic do trzeciej nie wkładał. To jest
świadomy koszt z 7 września, opisany w tym samym miejscu w `app.css`:
nawigacja ma stać w tym samym `x` na każdym ekranie, a alternatywą byłaby
siatka skacząca w bok zależnie od tego, czy dana podstrona akurat ma szynę.
Dla zwykłych ekranów serwisu (Powiadomienia, Ustawienia) ten koszt jest
niewielki — kolumna szyny na monitorze 1920 px to wąski pasek.

Na panelu moderacji ten sam koszt wygląda inaczej, bo panel nigdy w życiu
nie poda slotu `rail` — to narzędzie pracy moderatora, nie treść typu „Mój
zeszyt" czy „kuKINGi na dziś", którym szyna służy. Zmierzone na
`/admin/uzytkownicy`, okno 1280 px, **przed** poprawką: kolumna treści miała
576 px zamiast swoich zwykłych 720, bo sztywna kolumna szyny (22rem) obok
sztywnej kolumny nawigacji (15rem) ściskała środkową, elastyczną kolumnę —
a to jest dokładnie zgłoszenie właściciela, „treść wciśnięta w lewe ~55%
ekranu, po prawej duży pusty obszar".

**Decyzja:** `.app-body` dostaje `data-tryb-panelu` (ten sam atrybut co
`<nav class="side-nav">` obok), a reguła w bloku `80rem` czyta go i zwraca
panel do dwóch kolumn — bez `var(--container-rail)`. Zwykłe ekrany serwisu
**zachowują** trzecią, zarezerwowaną kolumnę bez zmian; to nie jest cofnięcie
decyzji z 7 września, tylko wyjątek dla jedynego miejsca, które nigdy nie
skorzysta z tego, za co ta kolumna płaci.

**Czego to NIE rozstrzyga:** kolumna czytania (`--container-content`,
45rem) zostaje wszędzie, panel włącznie — „szerokość strony ma być
identyczna na każdej podstronie" to osobna, wcześniejsza decyzja właściciela
i ta zmiana jej nie rusza. Szeroka tabela kont dalej przewija się we własnym
kontenerze (`.tabela-kont-przewijanie`), nie rośnie do pełnej szerokości
ekranu.

### 2. Z dwóch „Wróć do Kuking" na szerokim telefonie zostaje dolne

**Stan sprzed zmiany:** poniżej `64rem` panel pokazuje wyjście w dwóch
miejscach naraz — na górze pionowej listy menu (`.side-nav-powrot`) i w
stałym pasku dolnym (`.bottom-nav-panel`, `position: fixed`). To jest
zamierzone i ma własny test (`TrybPaneluWMenuTest`): na wąskim telefonie
(320–414 px) obie pozycje rzadko widać jednym spojrzeniem, bo trzeba
przewinąć stronę, żeby dojść od jednej do drugiej. Na szerokim, rozłożonym
telefonie obie mieszczą się w jednym kadrze bez przewijania — i to czyta się
jak pomyłka, nie jak zamierzona redundancja.

**Decyzja:** znika górny odnośnik, zostaje dolny — **wyłącznie** w paśmie
`48rem`–`63.999rem` (768–1023 px), czyli dokładnie w przedziale z opisu
zgłoszenia („Fold rozłożony ląduje między 768 a 1280"). Poniżej `48rem`
zachowanie z `TrybPaneluWMenuTest` zostaje nietknięte — oba odnośniki dalej
są. Od `64rem` górny odnośnik jest jedynym wyjściem (pasek dolny znika tam
niezależnie, regułą sprzed tej zmiany), więc pasmo dolne tej reguły musi
kończyć się dokładnie na progu, na którym zaczyna działać nawigacja
desktopowa.

**Dlaczego zostaje dolny, nie górny:** uzasadnienie górnego odnośnika
(„musi być widoczny bez przewijania") spełnia pasek dolny **lepiej**, bo jest
`position: fixed` — widoczny na każdej szerokości telefonu, niezależnie od
tego, gdzie w danej chwili jest przewinięta strona. Usunięcie odwrotne
(zostaje górny, znika dolny) zdjęłoby z panelu jedyne wyjście, które nie
zależy od pozycji przewijania.

**Realizacja jest czysto wizualna:** `display: none` w CSS, znacznik HTML
zostaje bez zmian. Dzięki temu żaden test czytający treść strony (w tym
`TrybPaneluWMenuTest::test_w_trybie_panelu_widac_powrot_do_serwisu`, który
sprawdza obecność OBU odnośników w HTML-u) nie wymagał poprawki — sprawdza
znacznik, nie to, co akurat pokazuje arkusz stylów przy danej szerokości.

**Zmiana wymaga:** przy każdej przyszłej zmianie progu `64rem` (granicy
między trybem telefonu a trybem desktopowym panelu) — dopasowania górnej
granicy tego pasma (`63.999rem`) w tej samej regule, inaczej powstanie
przerwa albo zakładka pasm.

**Pliki:** `resources/css/app.css` (`.app-body[data-tryb-panelu]`,
`.side-nav[data-tryb-panelu] .side-nav-powrot`) ·
`resources/views/components/layout.blade.php` ·
`tests/Feature/PanelSzerokiTelefonTest.php`

---

## D-083 · Zdjęcie przypina się i kasuje pod JEDNĄ blokadą wiersza `media`, a pliki znikają dopiero PO commicie — wiersz ze znacznikiem `deleted` jest uchwytem do ponowienia

**Issue:** #285 (MEDIA-01, P1). **Data:** 10.09.2026.
**Stoi na:** D-079 (jedna kolejność blokad + rewalidacja POD blokadą).

> **Adnotacja z 20 września 2026 (audyt rejestru).** Sekcja „Co zostaje
> otwarte" jest nieaktualna od **D-103**, która sama nazywa się wykonaniem
> tego wpisu. Zdanie „awatar, zdjęcie główne przepisu, skan zeszytu i zdjęcie
> kroku **przypinają się nadal bez blokady**" nie opisuje dzisiejszego kodu:
> `app/Domain/Media/Actions/PrzypnijAwatar.php:113` woła
> `ZdjeciaDoPrzypiecia::zablokuj(...)`, a
> `app/Domain/Recipes/Actions/PublishRecipe.php:227` robi jedno wspólne
> `zablokuj()` na `hero_media_id`, `source_scan_media_id` i
> `recipe_steps.media_id`. Rdzeń — jedna blokada wiersza `media`,
> `status='deleted'` jako uchwyt do ponowienia, pliki dopiero po commicie —
> obowiązuje i jest wdrożony
> (`app/Domain/Media/ZdjeciaDoPrzypiecia.php:66-78`). D-103 nie postawiła tu
> adnotacji, więc czytany samodzielnie wpis wprowadzał w błąd co do stanu
> czterech ścieżek przypinania.

### Stan sprzed zmiany — sprawdzony w plikach, nie przepisany z audytu

Audyt jest materiałem zewnętrznym, a `docs/research/audyt-2026-09-10/SPRAWDZENIE.md`
wymienia MEDIA-01 wprost jako **niesprawdzone**. Sprawdzone teraz:

- `PublishPost::handle()` wybierał należące do autora `media_id` zwykłym
  `SELECT`-em **przed** transakcją i nigdy do tego wyboru nie wracał;
  `attach()` szedł kilkanaście linijek dalej, już w transakcji.
- `RecordCookedEvent::handle()` miał dokładnie ten sam kształt.
- `KasujZdjecie::jesliNieuzywane()` pytał `exists()` po sześciu tabelach
  (też bez blokady), a potem — **wewnątrz** transakcji otwartej przez
  `OsieroconeZdjecia::posprzataj()` — kasował pliki z R2 i dopiero na końcu
  wiersz `media`.

Żadna z tych operacji nie brała czegokolwiek na wspólnym wierszu `media`.
Między `exists()` sprzątacza a skasowaniem plików mieściła się cała
publikacja wpisu.

### Jedna poprawka do opisu issue

Issue przewiduje, że sprzątacz „wchodzi w konflikt z FK". **Nie wchodzi.**
`post_media.media_id` ma w migracji `2026_09_05_000500_create_posts_tables`
`cascadeOnDelete()` (tak samo `cooked_event_media.media_id`), więc skasowanie
wiersza `media` po cichu zabiera świeżo wstawiony wiersz `post_media`.

Objaw jest więc **gorszy** niż w opisie: nie ma ani wyjątku, ani wpisu
w logu. Wpis zostaje bez zdjęcia, plik znika z R2, a jedyny egzemplarz
zdjęcia człowieka nie istnieje już nigdzie. Przy produkcie, którego cała
obietnica brzmi „zabierzesz stąd wszystko, co dodasz", to jest najgorsza
klasa błędu, jaką ten kod może mieć.

### Decyzja

**1. Przypinanie wybiera zdjęcia POD BLOKADĄ, w tej samej transakcji co
`attach()`.** Robi to jedna klasa, `App\Domain\Media\ZdjeciaDoPrzypiecia`,
używana przez `PublishPost` i `RecordCookedEvent` — nie dwie kopie tego
samego protokołu, z tego samego powodu, dla którego lista `ODWOLANIA` żyje
w jednym miejscu.

- `SELECT … FOR UPDATE` — zderza się z blokadą `FOR KEY SHARE`, którą
  PostgreSQL bierze sam przy sprawdzaniu klucza obcego przy `INSERT`-cie do
  `post_media`. Przypięcie i przejęcie do skasowania ustawiają się przez to
  w kolejkę zamiast się mijać.
- `ORDER BY id` — deterministyczna kolejność blokowania. Bez niej dwa
  równoległe wysłania formularza z częściowo wspólnym zestawem zdjęć
  zakleszczyłyby się nawzajem.
- Warunki `owner_id` i `status` stoją w **tym samym** zapytaniu co blokada,
  więc są sprawdzane dopiero po jej uzyskaniu (D-079 §3: blokada serializuje,
  ale nie mówi żądaniu, że świat zmienił się, gdy ono czekało).
- Wywołanie poza transakcją rzuca `LogicException`. Blokada wiersza żyje
  wyłącznie w transakcji, więc bez tego strażnika ta klasa dałaby się
  przenieść „wyżej dla czytelności" i po cichu wrócić do zwykłego `SELECT`-a.

**2. Sprzątacz przejmuje zdjęcie w krótkiej transakcji, a pliki kasuje PO
commicie** — wzorzec z `EraseAccountData`, nie nowy pomysł:

1. `KasujZdjecie::przejmij()` — świeży odczyt `FOR UPDATE`, **ponowne**
   pytanie „czy używane" pod blokadą, znacznik `status = deleted`. Zero
   wejść na dysk, więc nikt nie czeka na R2 z założoną blokadą.
2. dopiero po zatwierdzeniu — pliki, a na samym końcu wiersz.

`OsieroconeZdjecia` przestaje otwierać własną transakcję: obejmowała także
kasowanie plików w R2, a jej wycofanie i tak nie przywróciłoby ani jednego
skasowanego pliku.

**3. Znacznik `status = 'deleted'` to „kasowanie trwa", nie „skasowane".**
Pełni tu tę samą rolę co `data_erased_at` przy wymazywaniu konta:
zatwierdzoną, widoczną dla innych transakcji deklarację „to zdjęcie
odchodzi". `ZdjeciaDoPrzypiecia` takiego wiersza nie przepuści, więc okno
nie wraca po zwolnieniu blokady, a przed skasowaniem plików.

Wiersz ze znacznikiem jest **uchwytem do ponowienia**: nieudane kasowanie
plików zostawia go na miejscu, a kolejny przebieg
`kuking:sprzataj-osierocone-zdjecia` wybiera go po wieku tak samo jak każdy
inny. To zachowanie z issue #17 zostaje nietknięte.

### Czego świadomie NIE zrobiono

- **Nowej kolumny ani migracji.** `media_status_check` dopuszcza wartość
  `deleted` od pierwszej migracji tabeli (`2026_09_05_000100_create_media_table`),
  tylko nikt jej nie używał. Osobna kolumna „zarezerwowane do kasowania"
  byłaby szóstym mechanizmem blokowania w repozytorium, w którym pięć
  wjechało tego samego dnia.
- **Optymalizacji liczby zapytań przy autoryzacji zdjęć** — to jest MEDIA-03
  (#286) i idzie osobno.
- **Trzech pozostałych dróg przypięcia** (`profiles.avatar_media_id`,
  `recipes.hero_media_id`/`source_scan_media_id`, `recipe_steps.media_id`).
  Mają ten sam kształt i tę samą lukę; nie zamknięto ich tutaj, żeby zmiana
  została przy utracie danych na dwóch najważniejszych ścieżkach produktu
  („Opublikuj" i „Ugotowałem"). **To jest dług, nie stan docelowy** — patrz
  „Co zostaje otwarte".

### Czego test NIE pilnuje

`tests/Feature/ZdjecieNieZnikaPrzyPrzypinaniuTest.php` **nie odtwarza**
wymuszonego przeplotu na dwóch połączeniach do PostgreSQL, którego domaga
się issue. `RefreshDatabase` trzyma dane testu w niezatwierdzonej transakcji,
więc drugie połączenie nie zobaczyłoby ani konta, ani zdjęcia.

Testowany jest kontrakt, na czterech osobnych elementach (blokada przy
przypinaniu, rewalidacja pod blokadą u sprzątacza, nieprzypinalność wiersza
ze znacznikiem, pliki po commicie + uchwyt do ponowienia). Brak przeplotu
z nich **wynika**, ale nie jest zmierzony — i tak trzeba to czytać.

Nie jest sprawdzone maszynowo, że PostgreSQL faktycznie serializuje
`FOR UPDATE` z `FOR KEY SHARE` branym przy kluczu obcym; to własność silnika,
przyjęta z dokumentacji. Nie jest też pilnowany strażnik
`DB::transactionLevel() === 0`, bo pod `RefreshDatabase` poziom transakcji
nigdy nie jest zerem.

### Co zostaje otwarte

Awatar, zdjęcie główne przepisu, skan zeszytu i zdjęcie kroku przypinają się
nadal bez blokady. Sam znacznik `deleted` daje im węższe okno niż przedtem,
ale go nie zamyka. Do osobnego zadania: przepuścić te cztery drogi przez
`ZdjeciaDoPrzypiecia`.

**Pliki:** `app/Domain/Media/ZdjeciaDoPrzypiecia.php` ·
`app/Domain/Media/KasujZdjecie.php` · `app/Domain/Media/OsieroconeZdjecia.php` ·
`app/Domain/Posts/Actions/PublishPost.php` ·
`app/Domain/Recipes/Actions/RecordCookedEvent.php` · `app/Models/Media.php` ·
`tests/Feature/ZdjecieNieZnikaPrzyPrzypinaniuTest.php`

---

## D-087 · Spis wszystkich tematów (`tags.index`) — dwie sekcje, zero rankingu

**Data:** 10 września 2026 · Status: **obowiązuje**

Druga połowa issue #273 (pierwsza — słownik tagów, D-026 — jest na `main`
od 7 września). Baza tagów bez strony, na której da się je zobaczyć, nie
rozwiązuje problemu; strona bez tagów też nie — cytat z issue.

### Co strona pokazuje

Nowa trasa publiczna `GET /tagi` (`tags.index`), bez logowania, w dwóch
sekcjach:

1. **„Polecane tematy"** — `Tag::promowane()` (D-021, „tag promowany —
   lista gospodarza"), w kolejności redakcyjnej z panelu
   `/admin/tagi-promowane` (`tag_promotions.position`). To jest dosłownie
   „promowanymi tagami" z cytatu PROD3 w issue #273.
2. **„Wszystkie tematy A-Z"** — `Tag::aktywne()` (czyli bez tagów
   ukrytych i scalonych), **alfabetycznie po `name`**, stronicowane
   istniejącym wzorcem „Pokaż więcej" (`<x-show-more>`), nie infinite
   scroll.

Każdy temat pokazuje swoją **prawdziwą** liczbę wpisów — także zero, bo
issue zakazuje wprost udawania żywej treści („wolno wgrać puste tematy do
przeglądania, nie wolno wgrać fałszywych wpisów, żeby wyglądały na żywe").
Pusty temat na tej liście prowadzi do tej samej strony `/tag/{slug}`,
która już dziś pokazuje `x-empty-state` „Tu jeszcze nikt nic nie ugotował"
— żadnego nowego stanu pustego nie trzeba było wymyślać.

### Dlaczego kolejność NIE jest rankingiem

Obie sekcje sortują po czymś, co nie zależy od popularności ani od tego, co
ktokolwiek zrobił z treścią:

- sekcja 1 sortuje po **wyborze gospodarza** — to samo pole, którego panel
  admina już używa do ustawienia kolejności listy promowanej; zmiana tej
  kolejności wymaga wejścia do panelu, nie zbierania „Ugotowałem";
- sekcja 2 sortuje **po alfabecie** — deterministyczne, przewidywalne,
  niezależne od ruchu na tagu. Dwa uruchomienia tego samego dnia dają
  identyczną kolejność, niezależnie od tego, ile osób odwiedziło który tag
  w międzyczasie.

Żadna z dwóch sekcji nie sortuje po `posts_count`, po liczbie
obserwujących ani po dacie ostatniego wpisu — to jest właśnie „ważenie
popularności", którego zakazuje `AGENTS.md` i przekazanie pracy z 10.09
(§9 pkt 3-4). Liczba wpisów jest wyłącznie **etykietą przy nazwie**, tak
jak Garnkowe „Jedzonko 34203 zdj." — samo w sobie nigdy nie decyduje
o miejscu tematu na liście.

### Skąd liczba wpisów, żeby była prawdziwa i tania

Liczba przy każdym tagu to `posts` policzone przez
`Post::publiclyVisible()->tylkoOdAktywnychAutorow()` — **ten sam** zakres,
którego komentarz w `Post::scopeTylkoOdAktywnychAutorow()` wymienia wprost
jako przeznaczony m.in. dla „feedu tematów". Świadomie NIE jest to
`Post::widoczneDla($widz)` z widoku pojedynczego tagu:

- `widoczneDla()` liczy się PER WIDZ (blokady, obserwowanie) — na liście
  z jednego zapytania dla setek tagów naraz oznaczałoby to inny wynik dla
  każdej zalogowanej osoby, czyli liczbę, której nie da się ani zmierzyć
  raz, ani wytłumaczyć („dlaczego u mnie 4, a u sąsiada 5?");
- `publiclyVisible()+tylkoOdAktywnychAutorow()` daje **jedną, tę samą**
  liczbę każdej osobie — i jest dokładnie tym, co zobaczy GOŚĆ wchodząc na
  `/tag/{slug}` (bo dla widza `null` `widoczneDla()` redukuje się do tego
  samego warunku). Dla zalogowanej osoby liczba na liście może być **niższa**
  niż to, co zobaczy po wejściu (jej własne wpisy, wpisy obserwowanych z
  widocznością „obserwujący") — nigdy wyższa. Niedoszacowanie w dobrą
  stronę jest bezpieczne z punktu widzenia zakazu sztucznego ruchu; zawyżenie
  nie byłoby.

Liczenie idzie jednym zapytaniem (`withCount(['posts' => ...])`), tą samą
techniką, którą `docs/product/PROSTOTA_JAK_GARNEK.md` §3 pkt 1 proponuje
wprost dla tej strony — bez zapytania na tag, czyli bez N+1. Pilnuje tego
`SpisTematowTest::test_strona_nie_generuje_zapytania_na_kazdy_tag`.

### Czego ta decyzja NIE robi tak, jak sugerował pierwotny szkic

`docs/product/PROSTOTA_JAK_GARNEK.md` §3 pkt 1 (napisany 10 września, przed
tym PR-em) proponował **jedną, niestronicowaną listę** wszystkich aktywnych
tagów — bo w chwili pisania tamtego dokumentu D-021 mówiło o „zamkniętej
liście ok. 30 tagów" (cytat z §5a tego samego pliku). Słownik z D-026,
scalony 7 września, ma **~1250 nazw kanonicznych** — jedna strona bez
podziału renderowałaby więc naraz ponad tysiąc odnośników, co jest dokładnie
tym rodzajem gęstości, przeciw któremu stoi cały ten dokument (por. sekcja
1a tamtego pliku o stronie głównej). Stąd stronicowanie w sekcji 2 —
zachowuje alfabetyczny, nieranking'owy porządek, tylko w kawałkach po
`config('kuking.tags.index_page_size')` (domyślnie 100).

### Co świadomie pominięto

1. **Filtrowanie / szukanie po literze albo kategorii.** `internal_category`
   jest jawnie „nigdy niepokazywana użytkownikowi" (`docs/DATABASE.md`,
   opis kolumny `tags.internal_category`) — użycie jej jako nagłówka sekcji
   publicznej strony złamałoby tę już zapisaną decyzję. Skok alfabetyczny
   (kotwice `#litera-a` itp.) też został pominięty: to jest wzbogacenie UX,
   nie brakujący element zakresu z issue #273, i zwiększa powierzchnię do
   testowania bez potrzeby MVP. Zgłoszone jako pomysł do osobnego issue,
   nie zrobione po cichu.
2. **Wykluczenie tagów promowanych z sekcji „Wszystkie tematy A-Z".**
   Tag promowany pojawia się w obu sekcjach. Wykluczenie wymagałoby
   dodatkowego warunku `whereNotIn` na liście promowanych ID przy każdym
   stronicowaniu; podwójne wystąpienie tego samego tematu (raz w sekcji
   redakcyjnej, raz w alfabetycznej) nie jest mylące — to ten sam wzorzec,
   co "polecane" i "wszystko" w sklepach czy bibliotekach.
3. **Odznaka „obserwujesz" przy tagu dla zalogowanej osoby.** Istnieje już
   na `/ustawienia/tagi` i na `/tag/{slug}`; dokładanie jej tutaj to kolejne
   zapytanie (`tag_follows` dla widza × strona wyników) bez wymogu z issue
   #273 — możliwe do dołożenia później, jeśli ktoś tego zabraknie w testach
   z użytkownikami.

**Pliki:** `routes/web.php` · `app/Http/Controllers/TagController.php` ·
`resources/views/pages/tags/index.blade.php` · `config/kuking.php` ·
`tests/Feature/SpisTematowTest.php`.

---

## D-091 · Liczby o osobie idą do prawej szyny na szerokim ekranie, a na wąskim zostają w karcie — dwa egzemplarze w HTML, jeden na ekranie, bez JavaScriptu

**Zgłoszenie właściciela, dosłownie:** „jestem na profilu użytkownika, patrz
prawa kolumna jest marnowana, można tam dać info o użytkowniku (ile wpisów,
przepisów, obs, obserwuj itp itd, a nie na środku przez co wpisy są dużo
niżej".

> **Adnotacja z 20 września 2026 (audyt rejestru) — ROZBIEŻNOŚĆ OPISANA,
> NIEROZSTRZYGNIĘTA.** Tytuł obiecuje „dwa egzemplarze w HTML, jeden na
> ekranie". W kodzie egzemplarz jest JEDEN:
> `resources/views/pages/profile/show.blade.php:257` renderuje
> `<x-liczby-profilu … wariant="karta" />`, a `wariant="szyna"` ani klasy
> `profil-liczby-szyna` nie emituje żaden plik w `resources/views/`.
> `resources/views/components/szyna-profilu.blade.php` deklaruje wprawdzie
> `$stats`, ale nigdzie ich nie używa i kontroler ich tam nie podaje.
>
> Strażnik podany w tym wpisie egzekwuje dziś **zakaz** obiecanego wariantu:
> `tests/Feature/ProfilLiczbyWPrawejSzynieTest.php:28` nazywa się
> `test_liczby_wystepuja_raz_w_dokumencie` i asertuje
> `assertStringNotContainsString('profil-liczby-szyna', $html)` oraz
> `assertSame(1, substr_count($html, 'class="profil-liczniki '))`. W
> `resources/css/ekran-profilu.css:294-316` zostały reguły
> `.profil-liczby-szyna` / `.profil-liczby-karta`, których nic nie trafia.
>
> **Czego audyt NIE ustalił:** czy decyzję wdrożono i później odwrócono, czy
> nigdy jej nie wykonano, a strażnik napisano pod stan zastany. To dwie różne
> historie i dwie różne naprawy — przywrócić wariant szyny albo wycofać wpis
> razem z martwym CSS-em. Żaden późniejszy wpis tego nie odwraca, a dziennik
> cytuje D-091 dalej jako obowiązujące. **Werdykt należy do właściciela.**

**Stan przed zmianą.** Karta profilu (`pages/profile/show.blade.php`) miała pod
opisem osoby pięć osobnych wierszy po 48 px: wpisy, przepisy, „razy
Ugotowałem", obserwujący, obserwowani. Pod nimi rząd przycisków, dopiero pod
całą kartą zakładki, nagłówek miesiąca i pierwszy wpis. Prawa szyna profilu
ISTNIAŁA od issue #205 (`x-szyna-profilu`: „Twoje skróty" na własnym profilu,
„Co gotuje" i „Zeszyty" na cudzym), ale na cudzym profilu bez tagów i bez
publicznych zeszytów nie dostawała ANI JEDNEGO bloku — i to jest ten pusty
pas z prawej strony na zrzucie właściciela.

### Dlaczego dwa egzemplarze w dokumencie, a nie jeden przestawiany

Bo przestawić się nie da. `<aside class="app-rail">` jest RODZEŃSTWEM
`<main>`, nie jego wnętrzem: żadne `order`, `float` ani `grid-area` nie wsunie
elementu z szyny do środka karty profilu. Jedynym narzędziem byłby skrypt
przenoszący węzeł przy zmianie szerokości okna — a `AGENTS.md` §5 wymaga, żeby
ważne rzeczy działały bez JavaScriptu. Zostaje więc jedna treść wypisana dwa
razy (składnik `x-liczby-profilu`, żeby nie były to dwie kopie do rozjechania)
i PARA reguł w `ekran-profilu.css`, która pokazuje dokładnie jeden egzemplarz.

Egzemplarz schowany przez `display: none` wypada z drzewa dostępności, więc
czytnik ekranu czyta te liczby raz, a nie dwa razy.

### Dlaczego próg 80rem, a nie 64rem

80rem to próg, na którym w `app.css` w ogóle POWSTAJE trzecia kolumna.
Poniżej niego `.app-rail` nie znika — **ląduje pod treścią**, czyli pod całym
archiwum wpisów. Przeniesienie liczb do szyny „na stałe" zepchnęłoby je na
telefonie kilkanaście ekranów przewijania w dół. Na wąskim widać więc
egzemplarz w karcie i to jest stan sprawdzany przy 320, 360 i 414 px.

### Gość to osobny przypadek, nie powtórka

Gość dostaje `.app-body-solo` — JEDNĄ kolumnę na każdej szerokości. Jego szyna
leci pod treścią nawet przy 1512 px. Dlatego reguła chowająca liczby w karcie
jest zawężona przez `:not(.app-body-solo):not(.app-body-powitalny)`, a bloku
w szynie w ogóle mu nie wysyłamy (`@auth`). Bez tego zawężenia gość przy
1280 px straciłby liczby z karty, a jedyny drugi egzemplarz leżałby na dole
strony — czyli poprawka układu byłaby dla niego regresją.

### Co się NIE zmieniło i dlaczego to jest ważne

Liczby dalej pochodzą z jednej tablicy `stats` w `ProfileController::show()`.
Drugi egzemplarz NIE liczy sobie sam: pomiar zapytań na cudzym profilu
oglądanym przez zalogowanego daje siedem agregatów (pięć z `stats`, jeden
z paginacji archiwum, jeden z licznika powiadomień w belce) — tyle samo co
przed zmianą. Kolejność liczb, ich odmiana (`x-licznik-profilu`,
`App\Support\Odmiana`) i adresy odnośników obserwujących/obserwowanych są bez
zmian; w szynie odnośnik dalej obejmuje całą komórkę i ma 48 px pola
klikalnego.

### §12 (bez rankingów) — granica przesunięta w opisie, nie w rzeczy

Komentarz w `x-szyna-profilu` mówił dotąd „żadnej liczby obserwujących",
jednym tchem z zakazem rankingów. To było zlanie dwóch różnych rzeczy.
`AGENTS.md` §12 zakazuje PORÓWNYWANIA LUDZI ZE SOBĄ — miejsc w tabeli, odznak,
„najaktywniejszych". Nie zakazuje pokazania, ile ta osoba ma własnych wpisów;
te same pięć liczb stało przez cały ten czas w karcie dwa centymetry wyżej.
Granica zostaje ostra: blok nie sortuje, nie wyróżnia, nie nagradza i nie ma
progu „od ilu to już dużo". **Zera pokazujemy** — chowanie ich zamieniłoby
informację w wyróżnienie, czyli w ranking wpisany w puste miejsce.

### Osobno: nazwa przycisku „Ugotowałem" dostaje cudzysłów

Właściciel zauważył, że „0 razy Ugotowałem" na CUDZYM profilu brzmi jak zdanie
w pierwszej osobie. Sprawdzone: samo brzmienie jest umyślne i udokumentowane
dwa razy — `BRAND_EXTENDED.md` §3 każe nazwy własne funkcji pisać z wielkiej
litery i nie odmieniać („trzy razy Ugotowałem"), a wyjątek
w `TekstyNiePrzypisujaPlciTest::WYJATKI` brzmi „nazwa przycisku
**w cudzysłowie**". Cudzysłowu w interfejsie jednak nie było — i bez niego nic
nie odróżniało nazwy przycisku od czasownika. Poprawiona została więc
INTERPUNKCJA, a nie brzmienie: „4 razy „Ugotowałem”". Zamiana na neutralny
rzeczownik („4 wykonania") byłaby szóstą nazwą tej samej funkcji i złamałaby
regułę „nazwa funkcji jest jedna i nie ma synonimów" z tego samego dokumentu.

### Czego ta zmiana NIE dowodzi

Testy PHP dowodzą, że oba egzemplarze są w dokumencie po jednym razie i że
para reguł w arkuszu istnieje w dokładnie jednej postaci. **Nie dowodzą, że na
ekranie widać jeden.** To sprawdza dopiero `scripts/dostepnosc.mjs` w sekcji
„Liczby o osobie (karta czy prawa szyna)": mierzy `getClientRects()` obu
egzemplarzy przy 360, 1280 i 1512 px, dla gościa i dla zalogowanego, i oblewa,
gdy widać oba naraz albo żadnego.

📄 `resources/views/components/liczby-profilu.blade.php` ·
`resources/views/components/szyna-profilu.blade.php` ·
`resources/views/components/licznik-profilu.blade.php` ·
`resources/views/pages/profile/show.blade.php` ·
`resources/css/ekran-profilu.css` ·
`scripts/dostepnosc.mjs` ·
`tests/Feature/ProfilLiczbyWPrawejSzynieTest.php` ·
`tests/Feature/NaglowekProfiluOdmieniaLicznikiTest.php` ·
`docs/brand/BRAND_EXTENDED.md` §3 · issue #205 · D-054

---

## D-085 · Adres bez konta dostaje zaproszenie do rejestracji, a nie ciszę — z adresem potwierdzonym klikiem i jednorazowością liczoną na utworzeniu konta

**Data:** 10 września 2026 · Zgłoszenie właściciela (ta sama sprawa co #258) ·
Status: **obowiązuje**

> **O numerze.** Szkic tej funkcji powstał w sesji zabitej przez limit i w całym
> kodzie powoływał się na „D-067" — wpis o tym numerze **nigdy nie został
> napisany**, a numer należy do innej pracy. Cały kod odwołuje się teraz do
> D-085, czyli do tego wpisu.

### Skąd to się wzięło — prawdziwe zdarzenie, nie hipoteza

63-letnia osoba chciała założyć konto. Odbiła się o walidację nazwy
użytkownika, przeszła na ekran „Wyślij mi link do zalogowania" (bo jego tekst
brzmiał „podaj adres, na który zakładasz konto"), wpisała swój adres i zobaczyła
zielone **„Wysłaliśmy wiadomość na e\*\*\*@gmail.com"**. Nie przyszło nic — pod
tym adresem nie było konta, a ten ekran świadomie odpowiada identycznie dla
adresu z kontem i bez konta (D-056). Czekała na wiadomość, która nie miała
przyjść.

Prośba właściciela brzmiała wprost: *„jak nie ma konta, a zrobi zaloguj się
przez email, to żeby dostała tego maila i po kliknięciu w link w mailu przechodzi
do strony już tworzenia konta"*.

### Decyzja

Adres, na którym **nie ma konta**, dostaje wiadomość z linkiem prowadzącym na
dokończenie **zakładania konta**. Konto powstaje z adresem **już potwierdzonym**
i bez drugiej wiadomości weryfikacyjnej.

Ta funkcja **nie zamyka #258** (logowanie kontem Google). Ma to samo źródło —
tę samą osobę i to samo zdarzenie — ale to inna droga i tamto zgłoszenie zostaje
otwarte.

### Prywatność z D-056 wychodzi z tego MOCNIEJSZA, nie słabsza

To był warunek, pod którym ta zmiana w ogóle mogła powstać.

D-056 zapisało znane, przyjęte ryzyko: dobowy budżet poczty zajmował się tylko
przy realnie wysłanym liście, więc adres bez konta go nie ruszał — a kto ustawił
się dokładnie na ostatniej jednostce budżetu, mógł z zachowania formularza
wyczytać jeden bit („czy tamten adres ma konto"). **Po tej zmianie oba przypadki
wysyłają wiadomość i oba zajmują ten sam budżet, więc tej różnicy nie ma już
wcale.**

Zostaje różnica słabsza i o piętro niżej: sufit zaproszeń jest osobny i niższy
(40 wobec 120), więc na jego granicy adres bez konta przestaje generować wysyłkę
wcześniej. Ekran milczy o tym identycznie w obu przypadkach — cena jest taka, że
w dniu nadużycia osoba bez konta wiadomości nie dostanie, i dlatego sufit stoi
2–4 razy wyżej niż realne zapotrzebowanie.

### Co rozstrzygnięto po drodze

**Sufit wewnątrz sufitu, nie obok.** Zaproszenie zajmuje miejsce w budżecie
logowania linkiem (120) **i dodatkowo** we własnym (40), więc podział wiadra 300
listów nie zmienia się ani o jeden list. Drugi licznik jest konieczny, bo ta
zmiana otwiera wektor, którego wcześniej nie było: do 10 września adres bez konta
nie generował żadnej wysyłki, więc automat wpisujący wymyślone adresy nie
potrafił wysłać ani jednej wiadomości. Teraz potrafi, a limit po IP (5/60 min)
przepuszcza z jednego łącza 120 próśb na dobę — dokładnie tyle, ile ma cały
budżet logowania linkiem.

**Adres bierze się z wiersza w bazie, nie z pola w formularzu.** To jest
warunek, pod którym wolno postawić `email_verified_at` bez wysłania listu.
Zaproszenie leży w sesji jako sam **identyfikator wiersza**; kto podmieni pole
`email` w formularzu rejestracji, nie zmieni niczego, bo ta wartość nie jest
wtedy w ogóle czytana. Gdyby dało się ją podmienić, powstałoby konto
z potwierdzonym adresem, którego nikt nigdy nie potwierdził — czyli gotowa droga
do konta na cudzej skrzynce, z „nie pamiętam hasła" jako dalszym ciągiem.

**Jednorazowość liczy się na UTWORZENIU KONTA, nie na przejściu ekranu.**
Ani GET z wiadomości, ani POST przyjmujący zaproszenie niczego nie zużywają —
GET dlatego, że skanery odnośników w programach pocztowych otwierają każdy adres
przed człowiekiem (ta sama lekcja co w D-056), a POST dlatego, że **za nim stoi
jeszcze cały formularz rejestracji, o który ta osoba już raz się odbiła**. Gdyby
zaproszenie ginęło wcześniej, pierwsza pomyłka w nazwie użytkownika odbierałaby
jej link bezpowrotnie — czyli powtarzałaby ten sam błąd, który to wszystko
naprawia. Wiersz kasuje `RegisterController::store()` w tej samej transakcji,
w której powstaje konto, pod `lockForUpdate()`.

**24 godziny, nie 30 minut.** Poświadczenie jest słabsze niż link do logowania
(nie wpuszcza nigdzie, prowadzi na pusty formularz), a droga po jego kliknięciu
dłuższa. Nie więcej niż 24 h, bo tyle żyje zamówiona zmiana adresu, a tamto
poświadczenie jest mocniejsze od tego.

**Musi być wyjście „chcę konto na inny adres".** Adres z zaproszenia jest
w formularzu niezmienny, a sesja żyje długo — bez przycisku porzucenia człowiek,
który kliknął link ze złej skrzynki (z jednej korzysta czasem całe małżeństwo),
widziałby ten sam adres przy każdym wejściu na `/register` i nie miałby jak z tego
wyjść.

### Wyścig o unikalność adresu — usterka, którą ta praca znalazła i naprawiła

Szkic kasował poprzednie zaproszenie i zakładał nowe **bez przechwycenia
konfliktu**, mimo że `registration_invites.email` jest unikalne. Dwie prośby
naraz o ten sam adres przechodziły oba `DELETE` i obie szły do `INSERT`; druga
odbijała się o constraint i przewracała żądanie na **500**.

Przewracała je **wyłącznie na ścieżce adresu BEZ konta** — adres z kontem nie
dochodzi do tej klasy, a jego własny wyścig `WyslijLinkDoLogowania` sprowadza do
302 (D-075). Para równoległych próśb odpowiadała więc **500 dla adresu bez konta
i 302 dla adresu z kontem**: dokładnie ta wyrocznia „kto ma konto w Kuking",
którą D-075 dopiero co zamknęło, tylko odbita w lustrze. Po ludzkiej stronie tego
wyścigu stoi zwykły dwuklik „Wyślij".

Blokady wiersza konta — tej z D-075 — nie ma tu na czym postawić: konta nie ma,
a `SELECT ... FOR UPDATE` na nieistniejącym wierszu nie blokuje niczego. Zostaje
druga połowa tamtej konstrukcji: konflikt sprowadzamy do tej samej neutralnej
odpowiedzi co każda inna odmowa, oddając przy okazji miejsce w dobowym suficie.

Przy okazji sufit zaproszeń przeszedł na **`sprobujZarezerwowac()`** — szkic
używał pary „sprawdź, a potem zajmij", czyli tego, co D-076 usunęło z reszty
serwisu.

### Jak to wycofać

`KUKING_ZAPROSZENIA_DO_REJESTRACJI=false` — jedna zmienna, bez wycofywania
migracji i bez ruszania logowania linkiem. Adres bez konta wraca do zachowania
z D-056, ekran odpowiada dalej identycznie, a linki będące w drodze dostają
ekran po polsku zamiast błędu. Wycofanie samej migracji: patrz
`docs/DATABASE.md`, sekcja `registration_invites`.

**Pliki:** `app/Domain/Security/WyslijZaproszenieDoRejestracji.php` ·
`app/Domain/Security/ZaproszenieWSesji.php` ·
`app/Http/Controllers/Auth/RegistrationInviteController.php` ·
`app/Http/Controllers/Auth/RegisterController.php` ·
`app/Models/RegistrationInvite.php` ·
`app/Domain/Compliance/PrzedawnioneZaproszenia.php` ·
`database/migrations/2026_09_10_400000_create_registration_invites_table.php` ·
`tests/Feature/ZaproszenieDoRejestracjiTest.php` ·
`tests/Feature/EkranZaproszeniaTest.php` ·
`tests/Feature/RejestracjaZZaproszeniaTest.php`
---

## D-092 · Analityka odwiedzin to Cloudflare Web Analytics — bo cena, a przy okazji żaden nowy dostawca i żaden nowy przepływ danych

**Data:** 10 września 2026 · **Decyzja właściciela; rozstrzygnęła CENA** · Status:
**obowiązuje** · Zastępuje pierwszą wersję tego wpisu (Plausible), niescaloną

> **O numerze.** Ten wpis miał już raz treść — kończył się wnioskiem
> „Plausible hostowany w UE". Właściciel tę wersję **odrzucił tego samego dnia**,
> zanim gałąź została scalona. Numer **zostaje D-092**, bo decyzja jest ta sama
> („jaka analityka odwiedzin"), tylko z inną odpowiedzią. Branie nowego numeru
> na zmienioną odpowiedź rozsypuje dziennik na dwa wpisy, z których jeden trzeba
> by czytać jako „nieaktualny" — a nic w kodzie nie powiedziałoby, który.

### Skąd to pytanie i dlaczego odpowiedź nie brzmi „Google Analytics"

Właściciel chciał wiedzieć dwie rzeczy, których nasza własna analityka nie
umie powiedzieć: **skąd ludzie przychodzą** i **które strony oglądają**.
`App\Domain\Analytics\*` (jedenaście klas) liczy zdarzenia, które powstają
W BAZIE — publikacje, „Ugotowałem", tygodniowe WAC. O kimś, kto wszedł na
stronę powitalną i wyszedł, nie wie nic i wiedzieć nie może. Pytanie było
więc dobre.

Kosztem Google Analytics są ciasteczka. A opublikowana polityka prywatności
mówi dziś użytkownikom dwie rzeczy, sprawdzone w kodzie przed tą decyzją
i wtedy prawdziwe:

> Statystyki liczymy sami, w naszej własnej bazie — **nie korzystamy z żadnego
> zewnętrznego narzędzia analitycznego** (ani Google Analytics, ani żadnego
> innego).

> **Nie używamy żadnych plików cookies do statystyk ani do reklam.** Dlatego
> nie pytamy Cię o zgodę na cookies i nie zasłaniamy serwisu banerem — nie ma
> na co jej udzielać.

Pierwsze zdanie i tak musiało się zmienić — każde zewnętrzne narzędzie je
łamie. Drugie **nie musiało**, i to jest cała treść tej decyzji. GA kazałoby
postawić baner zgody: dodatkową przeszkodę na wejściu, do klikania przez
osoby 50+, przy produkcie, którego całym założeniem jest, żeby nie stawiać
przeszkód. Zapłacilibyśmy banerem za odpowiedź, którą da się dostać za darmo.
**Google Analytics nie wraca do rozważenia** i nie wraca też PostHog (patrz
niżej, „Martwa deklaracja w AGENTS.md").

### Co rozstrzygnęło: cena, powiedziana wprost

Pierwsza wersja tej decyzji wybrała **Plausible** — hostowany w UE, bez
ciasteczek, 9 € miesięcznie. Właściciel odrzucił go po przedstawieniu kosztu,
jego słowami:

> „Szkoda mi 9 eur miesięcznie na takie coś… Wolę w coś innego zainwestować"

To jest **prawdziwy powód tej zmiany i dlatego stoi tu wprost**, a nie
przebrany w argument techniczny. Dziennik decyzji, który ukrywa, że o czymś
zdecydowała cena, jest gorszy niż brak wpisu: następny agent szukałby
technicznej wady Plausible, której nie ma.

### Co rozstrzygnęło drugi raz: Cloudflare już tu jest

Po przedstawieniu alternatyw właściciel wybrał **Cloudflare Web Analytics**
(darmowy beacon JS). Rozstrzygnął argument, którego pierwsza wersja tego wpisu
nie miała, bo nie było wtedy powodu go szukać:

**Cloudflare przetwarza już KAŻDE żądanie do kuking.pl.** Jest naszym DNS-em,
CDN-em i WAF-em przed Railwayem (`docs/infra/INFRA_DECISION.md`: „DNS dla
`kuking.pl`, CDN/WAF przed Railway"), i właśnie dlatego `bootstrap/app.php`
ma ustawione zaufane proxy — bez tego „Laravel widzi IP Cloudflare zamiast
użytkownika". Włączenie analityki tej samej firmy **nie wysyła jej ani jednego
nowego bajta**: pokazuje nam to, co ona i tak obsługuje.

Z tego wynika rzecz, która jest połową wartości tej zmiany i której nie dawał
żaden inny kandydat: **w polityce prywatności nie doszedł ani nowy dostawca,
ani trzeci akapit o przekazywaniu danych poza EOG.** Cloudflare, Inc. (USA)
stoi tam od Turnstile'a (D-050) — w tabeli dostawców i w akapicie o transferze,
z podstawą **EU-US Data Privacy Framework** plus standardowe klauzule umowne.
Polityka obiecuje sama sobie: „Jeśli w przyszłości dojdzie kolejny dostawca
spoza EOG, dopiszemy go do tabeli wyżej i napiszemy tutaj, na jakiej podstawie
dane do niego trafiają — zanim trafi tam pierwszy rekord". Tutaj obietnica
była spełniona z góry; dopisany został **nowy CEL** przy tym samym dostawcy
(analityka odwiedzin obok „sprawdzenia, czy formularz wypełnia człowiek"),
tak żeby czytelnik widział, że ta sama spółka robi u nas teraz dwie rzeczy.

### Co odrzucono i dlaczego — mierzone, nie brane na słowo

Kandydaci: **Plausible**, **Umami** i **beacon Cloudflare**. Twarde
wymagania: zero ciasteczek, zero zapisu na urządzeniu człowieka, brak
profilowania między serwisami.

**Zachowanie skryptów sprawdziłem, pobierając je i czytając**, zamiast wierzyć
stronom marketingowym — bo to jest zdanie, które trafia do dokumentu prawnego:

| | Plausible (`plausible.io/js/script.js`) | Umami (`cloud.umami.is/script.js`) | **Cloudflare** (`static.cloudflareinsights.com/beacon.min.js`) |
|---|---|---|---|
| `document.cookie` | 0 wystąpień | 0 wystąpień | **0 wystąpień** (słowo „cookie" nie pada w pliku w żadnej postaci) |
| `sessionStorage`, `indexedDB` | 0 | 0 | **0** |
| `localStorage` | tylko **odczyt** flagi `plausible_ignore`, którą człowiek ustawia sam | tylko **odczyt** flagi `umami.disabled` | **0 — nie zagląda tam wcale** |
| zapis na urządzeniu (`setItem`) | brak | brak | **brak** (`setItem` i `getItem`: 0 wystąpień) |

Czyli **na tym kryterium przechodzą wszystkie trzy** i nie ono rozstrzygnęło —
tak samo jak w pierwszej wersji tego wpisu. Beacon Cloudflare wypada tu
o włos lepiej niż dwaj pozostali (nie ma w nim NIC, co dotyka pamięci
przeglądarki), ale gdyby chodziło tylko o to, Plausible wystarczyłby.

Rozstrzygnęły trzy rzeczy, w tej kolejności:

1. **Cena.** Plausible: 9 € miesięcznie. Cloudflare Web Analytics: 0 zł,
   w planie, który już mamy. Przy serwisie prowadzonym przez jedną osobę to
   jest argument, nie wymówka — patrz cytat wyżej.
2. **Kto jest podmiotem i ilu ich jest.** Umami Cloud prowadzi Umami
   Software, Inc. — spółka z Delaware z siedzibą w San Francisco. Nawet
   z regionem UE dla danych sam dostawca zostaje spoza EOG, czyli w naszej
   polityce dopisujemy **TRZECI akapit o przekazywaniu danych poza EOG**,
   obok Turnstile i OpenAI. Plausible (Plausible Insights OÜ, Estonia,
   serwery Hetznera) nie dokładał akapitu o transferze, ale dokładał
   **czwartego dostawcę** do tabeli. Cloudflare nie dokłada ani jednego, ani
   drugiego. Przy dokumencie, który właściciel czyta linijka po linijce, to
   jest różnica na korzyść zrozumiałości, nie tylko formalna.
3. **Self-host odpada z powodu architektury, nie niechęci.** Umami
   samodzielnie hostowany to druga usługa (Node) z własną bazą na Railwayu;
   Plausible samodzielnie hostowany dokłada do tego jeszcze ClickHouse.
   `AGENTS.md` §3 mówi: modularny monolit, bez mikroserwisów i bez kolejnej
   bazy. Dokładanie drugiego procesu do utrzymywania po to, żeby wiedzieć,
   skąd przychodzą odwiedzający, jest złą wymianą. Cloudflare Web Analytics
   **nie ma wariantu samodzielnie hostowanego wcale** — i dlatego przestaje
   obowiązywać uzasadnienie z pierwszej wersji tego wpisu („host w zmiennej
   środowiskowej, żeby dało się przenieść na własną instancję"): nie ma czego
   przenosić, a zmienna sugerowałaby, że jest.

**Nie wraca temat pikseli śledzących** (osobna otwarta sprawa, #204).
**Nie znika nasza analityka serwerowa**: beacon jest jej uzupełnieniem, nie
zamiennikiem. Jedno odpowiada na „ile osób ugotowało w tym tygodniu", drugie
na „skąd przychodzą i które strony oglądają, zanim cokolwiek u nas zrobią" —
i `App\Domain\Analytics\*` zostaje bez jednej zmiany.

### Dlaczego dalej NIE MA banera — i dlaczego to nie jest naciąganie

`docs/legal/COMPLIANCE.md` §5.2 stawia granicę tam, gdzie stawia ją ePrivacy
i PKE: zgody wymaga **przechowywanie informacji na urządzeniu końcowym albo
uzyskiwanie dostępu do tej, która już tam jest** — a nie sam fakt liczenia
czegokolwiek. Beacon Cloudflare nie robi ani jednego, ani drugiego (patrz
tabela wyżej).

Ten sam dokument, w §5.3, **odradza** próbę „cookieless analytics" bez
konsultacji prawnej. Ta rada dotyczyła jednak **PostHoga** i zachowuje ważność
tam, gdzie dotyczyła: PostHog bez identyfikatorów to **konfiguracja**, którą
da się cofnąć jednym przełącznikiem w cudzym panelu — i wtedy dokument prawny
przestaje być prawdziwy, a nikt się o tym nie dowie.

**Czy o beaconie Cloudflare da się uczciwie napisać to samo, co napisałem
o Plausible — że brak ciasteczek jest właściwością narzędzia, a nie
ustawieniem? Da się, i jest to zmierzone mocniej.** W pliku nie ma ani jednego
odwołania do `document.cookie`, `localStorage`, `sessionStorage`, `indexedDB`,
`setItem` ani `getItem`; słowo „cookie" nie pada w nim w żadnej postaci.
Kodu, który nie ma czym zapisać na urządzeniu, nie da się do tego namówić
przełącznikiem w panelu. Identyfikator odsłony powstaje z
`crypto.randomUUID()` w pamięci karty i ginie razem z nią, bo nie ma go gdzie
odłożyć. Skrypt dodatkowo **czyści adresy przed wysłaniem** (funkcja
`cleanLocation`): usuwa query string, fragment oraz login i hasło z URL-a,
więc identyfikator wklejony w link typu `?utm_id=…` do Cloudflare nie dojedzie.
Ryzyko, przed którym ostrzegała §5.3 — cicha zmiana zachowania pod
niezmienionym dokumentem — tu nie występuje. Zapisane w COMPLIANCE.md §5.5.

**Czego ten pomiar NIE uprawnia napisać, i dlatego nie napisałem tego nigdzie:**
że na urządzeniu nie ma żadnego ciasteczka Cloudflare. Proxy i WAF Cloudflare
to warstwa stojąca przed serwisem niezależnie od tej decyzji (np. bot
management), której nie mierzyłem. Beacon nie dokłada do niej nic, ale to jest
osobna sprawa do przeglądu konfiguracji Cloudflare — wypisana wprost
w COMPLIANCE.md §5.5 jako niezamknięta.

### Wpięcie — trzy rzeczy, które łatwo zrobić źle

1. **Bez zmiennej środowiskowej nie ma ANI ŚLADU znacznika w HTML-u.**
   Nie „wyłączona flagą", tylko nieobecna. Lokalnie, w testach i w CI cisza.
   Ten sam wzorzec co puste klucze Turnstile (D-050), i tak samo bez osobnej
   flagi „włącz analitykę" — dałaby stan „włączone, ale bez tokenu", czyli
   skrypt wysyłający zdarzenia donikąd.
2. **Konfiguracja przez `config/kuking.php`, nigdy `env()` w widoku.**
   Na produkcji konfiguracja jest zbuforowana i `env()` poza plikiem configu
   oddaje `null` — czyli znacznik z pustym tokenem: skrypt, który się ładuje
   i nic nie liczy. Z tego samego powodu **ani widok, ani reguła CSP nie
   powtarzają adresów literałem**: liczą je z konfiguracji przez
   `App\Support\AnalitykaCloudflare`. Powtórzenie w dwóch miejscach
   gwarantuje, że przy zmianie jedno zostanie w tyle i skrypt zostanie po
   cichu zablokowany.
3. **CSP w DWÓCH dyrektywach, z DWOMA RÓŻNYMI HOSTAMI** — i to jest tu
   pułapka grubsza niż przy Plausible, gdzie oba adresy były tym samym hostem
   i jedna wartość obsługiwała obie dyrektywy. Zmierzone w `beacon.min.js`:

   | co | adres | dyrektywa |
   |---|---|---|
   | pobranie pliku | `https://static.cloudflareinsights.com/beacon.min.js` | `script-src` |
   | wysyłka zdarzeń (`navigator.sendBeacon`, zapasowo `XMLHttpRequest`) | `https://cloudflareinsights.com/cdn-cgi/rum` | `connect-src` |

   Host zdarzeń jest **bez `static.`** i jest to podłańcuch hosta skryptu —
   czyli `str_contains` na nagłówku CSP dawałby wynik dodatni dla hosta,
   którego tam nie ma. `connect-src` jest w naszej polityce wypisana osobno,
   więc **nie dziedziczy nic z `default-src 'self'`**. Brak drugiej linijki
   daje stronę bez usterki, pusty dziennik i pusty panel Cloudflare. Pilnują
   tego **dwa osobne testy**, po jednym na dyrektywę, plus trzeci na to, czego
   żaden z nich nie widzi: że te dwa hosty są RÓŻNE (wpisanie jednego w oba
   miejsca zdałoby oba pierwsze testy).

   Znacznik ma kształt (`token` z panelu, świadomie BEZ pola `version`:
   zmierzone w skrypcie, jego obecność przełącza adres zdarzeń na ścieżkę
   względną na naszej domenie — tak działa automatyczne wstrzyknięcie przez
   proxy — i wtedy host dopuszczony w `connect-src` opisywałby nieprawdę):

   ```html
   <script defer src="https://static.cloudflareinsights.com/beacon.min.js"
           data-cf-beacon='{"token":"<token>"}'></script>
   ```

Host analityki wchodzi do CSP **tylko wtedy, gdy analityka jest włączona** —
tak samo jak host Turnstile (issue #12): polityka opisuje to, co strona
naprawdę ładuje, a każdy obcy host w `script-src` poszerza powierzchnię ataku.

### Co właściciel musi zrobić ręcznie — DWIE rzeczy, nie jedna

1. **Założyć serwis w panelu i wpisać token w Railwayu.** Cloudflare →
   Web Analytics → Add a site → `kuking.pl`. Cloudflare pokaże gotowy
   znacznik `<script>`; z niego potrzebna jest sama wartość pola `token`.
   W Railwayu jedna zmienna: `CLOUDFLARE_ANALYTICS_TOKEN=<token>`. Do tego
   czasu serwis chodzi bez analityki i nic nie pada. Opis stoi w `.env.example`.
   Beacon identyfikuje serwis **tokenem**, a nie nazwą domeny — dlatego jedna
   zmienna, a nie dwie jak przy Plausible.
2. **Wyłączyć automatyczne wstrzykiwanie beacona** (Web Analytics →
   ustawienia serwisu). Cloudflare umie wstrzyknąć ten sam skrypt w locie, na
   ruchu przechodzącym przez proxy. **O tym najłatwiej zapomnieć i skutek jest
   cichy:** my stawiamy znacznik w layoucie, więc przy włączonym wstrzykiwaniu
   strona dostanie **dwa** beacony — każda odsłona policzy się dwa razy, a
   nasze testy CSP będą opisywać nieprawdę (wstrzyknięta wersja podaje
   `version`, czyli wysyła zdarzenia na INNY adres niż ten, który dopuszczamy
   w `connect-src`). Znacznik stawiamy sami świadomie: wstrzyknięcie jest poza
   repozytorium, poza recenzją i poza testami, więc nie da się go ani
   przejrzeć, ani zepsuć w kontrolowany sposób — a to jest dokładnie ten
   rodzaj „działa, dopóki ktoś czegoś nie przestawi w cudzym panelu", przed
   którym broni cała reszta tej decyzji.

### Uboczne znalezisko 1: `DokumentyPrawneNieKlamiaTest` był za słaby

Kontrola ujemna do tej zmiany wykryła usterkę w istniejącym teście, starszą
niż ta decyzja i **niezależną od tego, którego dostawcę wybraliśmy**.
`test_nie_wymieniamy_narzedzi_ktorych_nie_uzywamy` pytał o CAŁY dokument
(„czy gdziekolwiek stoi zdanie zaprzeczające"), więc jedno prawdziwe zdanie
usprawiedliwiało każde inne wystąpienie nazwy: dopisanie do polityki zdania
**„Do statystyk używamy Google Analytics"** testu NIE OBLAŁO, bo obok stało
prawdziwe „Nie korzystamy z Google Analytics".

Sprawdzenie chodzi teraz po KAŻDYM wystąpieniu nazwy z osobna, w jego własnym
zdaniu — i po poprawce ten sam sabotaż oblewa (powtórzony jako kontrola ujemna
przy tej zmianie dostawcy, żeby poprawka nie została po cichu cofnięta).
Lista narzędzi zakazanych liczy się przy tym z kodu, więc wpięty dostawca
wypada z niej sam, a jego obecności w dokumencie pilnuje z drugiej strony
`PolitykaPrywatnosciWymieniaKazdaUslugeTest` — szukając nazwy
**„Cloudflare Web Analytics"**, a nie samego „Cloudflare", które stoi
w polityce od Turnstile'a i od R2.

### Uboczne znalezisko 2: martwa deklaracja `PostHog (EU)` w AGENTS.md

`AGENTS.md` §3 wymieniał w tabeli stacku `| Analityka | PostHog (EU) |`.
Zmierzone tego dnia: **PostHoga nie ma w kodzie ani jednej linijki** — ani
pakietu, ani `env('POSTHOG_KEY')` w `config/`, ani jednego wywołania. Były
tylko puste `POSTHOG_KEY` w `.env.example` i w dwóch jobach `ci.yml`, których
**nic nie czytało**. Ta linijka była nieprawdziwa od dawna.

To ta sama klasa błędu co martwy odnośnik `D-066`: **deklaracja, która wygląda
na rozstrzygnięcie i zatrzymuje szukanie.** Agent czytający tabelę stacku
kończył temat analityki na „jest PostHog", zamiast zobaczyć, że nie ma nic.
Poprawione w `AGENTS.md` i w `README.md`, martwe `POSTHOG_KEY` usunięte
z `.env.example` i z `ci.yml`.

**Zostało jedno miejsce, świadomie nietknięte:** `.railway/railway.ts` (linie
422–423) dalej podaje `POSTHOG_KEY` i `POSTHOG_HOST` na środowiska Railwaya.
Nic tych zmiennych nie czyta, więc są martwe, ale ten plik nie należał do tej
pracy i pilnuje wdrożenia produkcyjnego — do usunięcia osobno, razem
z przeglądem zmiennych na Railwayu.

**Pliki:** `config/kuking.php` · `app/Support/AnalitykaCloudflare.php`
(z przemianowania `app/Support/Plausible.php`) ·
`app/Http/Middleware/ApplySecurityHeaders.php` ·
`resources/views/components/layout.blade.php` · `.env.example` ·
`.github/workflows/ci.yml` · `AGENTS.md` · `README.md` ·
`resources/legal/polityka-prywatnosci.md` · `docs/legal/COMPLIANCE.md` §2.2, §5.2, §5.5 ·
`tests/Feature/AnalitykaBezCiasteczekTest.php` ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` ·
`tests/Feature/PolitykaPrywatnosciWymieniaKazdaUslugeTest.php`

---

## D-062 · List, który nie wyszedł, zostawia ślad w bazie i zapala `/health` — a alarmu pocztą o awarii poczty nie wysyłamy

**Data:** 10 września 2026 · Issue #234 · Status: **obowiązuje**

Do dziś odmowa dostawcy kończyła się tak: `OdmowaEmailLabs` wywracała zadanie,
worker robił trzy próby (`--tries=3 --backoff=10,60,300`, czyli wszystkie
w około sześciu minutach), zadanie lądowało w `failed_jobs` — i **cisza**.
Adresat nie dowiadywał się nigdy. Właściciel tylko wtedy, gdy sam z siebie
uruchomił `php artisan queue:failed` albo `kuking:sprawdz-poczte`.

Najgorsze było to, że **wyglądało to identycznie jak sukces**: kolejka pusta,
`/health` zielony, w panelu nic. A chodziło o potwierdzenia rejestracji,
przypomnienia hasła i logowanie linkiem — czyli listy, na które człowiek
czeka przed ekranem. W grupie 50+ osoba, która nie dostała potwierdzenia, nie
napisze reklamacji: uzna, że serwis nie działa, i odejdzie.

### 1. Co powstało

Jedna tabela (`mail_failures`), jedna kategoria odmowy, jedno sprawdzenie
w `/health`, jedna komenda i jedno zdanie na ekranie dla człowieka.

| Warstwa | Co robi |
|---|---|
| `App\Poczta\PowodOdmowy` | Cztery kategorie: `limit_dobowy`, `przejsciowa`, `trwala`, `nieznana`. Każda z gotowym zdaniem „co zrobić" |
| `App\Poczta\TransportEmailLabs` | Ustala kategorię **w chwili odmowy** — jako jedyny widzi kod HTTP i kody błędów dostawcy |
| `App\Poczta\ZapiszNieudanyList` | Słuchacz `JobFailed`: zamienia ostatnią, przegraną próbę w wiersz `mail_failures` i wpis `Log::error` |
| `/health` → `checks.listy` | `degraded` z kodem `listy_przepadaja` albo `limit_poczty_wyczerpany`, dopóki ktoś nie odhaczy |
| `kuking:nieudane-listy` | Co przepadło, komu, dlaczego, co z tym zrobić. `--odhacz` gasi alarm |
| ekran „Potwierdź adres e-mail" | Przestaje obiecywać list, który nie wyszedł, i przestaje odsyłać do „Spamu" po wiadomość, której tam nie ma |

### 2. Dlaczego `JobFailed`, a nie zdarzenia poczty ani wnętrze transportu

`MessageSending` leci przed wysyłką, `MessageSent` tylko po udanej — żadne
z nich nie mówi o porażce nic. Wyjątek transportu leci przy **każdej** z trzech
prób, więc zapisywanie śladu stamtąd dałoby trzy wiersze o jednym liście
i wpis nawet wtedy, gdy druga próba się udała.

`JobFailed` leci **dokładnie raz**: w chwili, w której worker uznaje zadanie za
przegrane. To jest ta sama chwila, w której list naprawdę przepada. Warunkiem
zapisu jest `TransportExceptionInterface` gdziekolwiek w łańcuchu przyczyn —
czyli **typ, nie treść komunikatu** — więc ślad powstaje tak samo przy naszym
`emaillabs`, jak przy uśpionym `smtp` (D-047), a zgadywanie z tekstu nie
przestanie działać po cichu przy zmianie wersji biblioteki (lekcja z W7-07).

**Słuchacz nie ma prawa rzucić i cała jego treść jest w `try`.** To jest
najważniejsza własność tego kodu: wiersz w `failed_jobs` zapisuje INNY
słuchacz tego samego zdarzenia (`WorkCommand::logFailedJob`, rejestrowany
dopiero przy starcie `queue:work`), a nasz — zarejestrowany w dostawcy usług —
leci pierwszy. Gdyby padł, zabrałby diagnostyce payload, czyli jedyną rzecz,
z której da się list ponowić. Zamiana „nikt się nie dowie" na „nikt się nie
dowie i nie ma czego ponowić" byłaby poprawką w złą stronę. Pilnuje tego
`NieudanyListZostawiaSladTest::test_awaria_zapisu_sladu_nie_przewraca_obslugi_bledu`.

### 3. Alarmu pocztą NIE WYSYŁAMY — i to jest decyzja, nie zapomnienie

Kusi, żeby wysłać list na `KUKING_MODEL_ALARM_EMAIL`, tak jak robią to
`kuking:podsumowanie-automatu` i `kuking:pilnuj-terminow-odwolan`. Nie robimy
tego z trzech powodów, w tej kolejności:

1. **Najczęstszy przypadek to wyczerpany limit dobowy.** Wtedy list o awarii
   odbije się identycznie jak ten, o którym miał donieść — alarm nie dojdzie
   dokładnie wtedy, gdy jest najbardziej potrzebny.
2. **Pętla.** Nieudany alarm jest sam nieudanym listem, więc zostawia własny
   ślad, o którym trzeba by donieść. Wersja warunkowa („alarmuj tylko przy
   odmowie trwałej") wymaga bezpiecznika, którego nie da się sprawdzić inaczej
   niż na produkcji — a issue #234 zabrania wprost powiadomień, które mogą się
   zapętlić.
3. **Ślad ma nie zależeć od kanału, który właśnie padł.** Stąd trzy miejsca,
   z których żadne nie jest pocztą: **wiersz w bazie** (przeżywa restart,
   wdrożenie i `queue:flush`), **`/health`** (odpytywany przez Railway
   i monitoring zewnętrzny co kilka minut — to on jest tu automatem) oraz
   **`Log::error`** w dzienniku serwera.

> **SPROSTOWANIE DO §3, ZROBIONE PRZY SCALANIU Z `main` 10 września 2026.**
> Ten punkt twierdził wcześniej, że `Log::error` „przy ustawionym
> `LOG_BLAD_WEBHOOK_URL` (D-041) idzie na webhook, a przy `vars.SENTRY_ORG`
> do Sentry". **Sprawdzone w kodzie: nieprawda**, i to nieprawda kosztowna,
> bo cała ta decyzja opierała na niej zdanie „właściciel ma szansę dowiedzieć
> się bez zaglądania". Kanał `blad_webhook` NIE jest częścią stosu domyślnego
> (`config/logging.php`: `stack` → `LOG_STACK`, a `.env.example` ustawia
> `LOG_STACK=single`); woła się do niego JAWNIE, z `bootstrap/app.php` przy
> raportowaniu wyjątku i z `App\Domain\Contact\DzwonekOperatora`. Sentry'ego
> nie ma w `composer.json` wcale (D-041,
> `docs/infra/INFRA_DECISION.md` — sprostowanie z tego samego dnia).
>
> Zwykłe `Log::error()` zostaje więc w pliku na serwerze i **nikogo nie budzi**.
> Wynika z tego dwie rzeczy, obie już zrobione:
>
> 1. **Ostrzeżenie o kończącej się puli (§6) dzwoni na `blad_webhook` JAWNIE**,
>    obok wpisu w dzienniku serwera — bo `/health` o suficie nie mówi nic
>    i bez tego był to sygnał, którego nie widzi nikt.
> 2. **Automatem, który zamienia `checks.listy` w dzwonek, jest `/health`, nie
>    dziennik** — a `/health` dzwoni na ten kanał dopiero z PR #255
>    (`HealthController::powiadomWebhook()`). Do czasu scalenia #255 jedynym
>    automatem nad `checks.listy` jest monitoring zewnętrzny czytający TREŚĆ
>    odpowiedzi. Zależność jest więc jednokierunkowa i nazwana: #253 zostawia
>    ślad, #255 sprawia, że ktoś go usłyszy.
>
> Nie zmienia się nic w kierunku decyzji: obowiązkowe zostają **wiersz
> w bazie i `/health`**, bo tylko one nie zależą od tego, czy właściciel
> ustawił adres webhooka.

Odpowiedź na pytanie z issue („skąd właściciel się dowie") brzmi więc:
**z monitoringu `/health`, który już istnieje i już jest odpytywany**, a przy
zaglądaniu — z `kuking:nieudane-listy` i z `kuking:sprawdz-poczte`, które teraz
liczy przepadłe listy osobno od resztki `failed_jobs`.

### 4. Człowiek, który czekał, dowiaduje się na ekranie — jednym zdaniem o faktach

Ekran „Potwierdź adres e-mail" mówił zawsze „Wysłaliśmy wiadomość" i kończył
radą „Zajrzyj do folderu «Spam»". Gdy dostawca listu nie przyjął, oba zdania
były nieprawdą, a rada wysyłała człowieka na poszukiwanie wiadomości, która
nigdy nie powstała.

Teraz, gdy w `mail_failures` leży ślad dla TEGO konta świeższy niż
`kuking.poczta.okno_prawdy_godzin` (domyślnie 24 h), ekran mówi: *„Ostatnia
wiadomość nie dotarła. Wysłaliśmy ją 10.09 o 14:22, ale nasz dostawca poczty
jej nie przyjął — więc nie ma jej ani w Twojej skrzynce, ani w folderze
«Spam»"* — i podaje adres kontaktowy. Rada o „Spamie" jest wtedy
**podmieniona**, nie dołożona.

Zdanie opisuje **zdarzenie z przeszłości, z datą**, a nie stan („listy do
Ciebie nie wychodzą"). Dzięki temu zostaje prawdziwe także po udanym
ponowieniu i nie potrzebuje w bazie żadnego znacznika „już naprawione", czyli
drugiego stanu do pilnowania.

**Świadomie zawężone do potwierdzenia adresu.** Reset hasła i logowanie
linkiem odpowiadają identycznie dla adresu z kontem i bez konta (D-056), więc
zdanie „Twój list nie wyszedł" na tamtych ekranach byłoby wyrocznią „kto ma
konto w Kuking" — czyli wyciekiem. Tam prawdę mówi już `App\Support\Poczta`,
gdy poczta nie wychodzi w ogóle.

### 5. „Nie wyszedł teraz" ≠ „nie wyjdzie nigdy"

Kategoria nie steruje ponawianiem i `--tries` **zostaje na trzech** (issue #234
mówi wprost: nie podnosić). Kategoria mówi CZŁOWIEKOWI, co zrobić, i rozdziela
kod w `/health`:

| Kategoria | Skąd się bierze | Co robi właściciel |
|---|---|---|
| `limit_dobowy` | HTTP 429 **albo** słowo o limicie w błędzie dostawcy — także przy HTTP 2xx | Nie powtarza dziś. Po północy `queue:retry`, a jeśli się powtarza — płatny plan |
| `przejsciowa` | HTTP 5xx, 408, zerwane połączenie | `queue:retry` — najprawdopodobniej wystarczy |
| `trwala` | pozostałe 4xx i 207 (zły adres, zły klucz, odrzucony nadawca) | Powtarzanie nic nie da; trzeba coś zmienić |
| `nieznana` | odpowiedź, której nie umiemy odczytać | Traktuje jak awarię, nie jak coś, co samo przejdzie |

**Słowa o limicie sprawdzamy PRZED kodem HTTP** i to nie jest kolejność
przypadkowa: dostawcy wysyłają „limit exceeded" także z kodem 2xx i 4xx,
a klasyfikacja po samym kodzie kazałaby wtedy właścicielowi szukać usterki
w konfiguracji przez cały dzień, w którym wystarczyło poczekać do północy.

### 6. Ostrzeżenie ZANIM pula się skończy

`DziennyBudzetListow::sprobujZarezerwowac()` zapisuje `Log::error` i dzwoni na
kanał `blad_webhook`, gdy zużycie sufitu przekroczy
`kuking.poczta.prog_ostrzezenia_procent` (domyślnie 80%) — **raz na dobę na
funkcję**, bo alarm, który się powtarza, uczy się ignorować. To jedyny
wyprzedzający sygnał, jaki ten serwis ma, i odpowiada na czwarty punkt
issue #234: przejście na płatny plan ma dać się zrobić dzień wcześniej, nie
w dniu awarii. Na webhook idzie **jedno zdanie z samymi liczbami i nazwą
funkcji**, w dzienniku serwera zostaje pełny kontekst — bo webhook wychodzi do
usługi, nad którą nie mamy kontroli (audyt A6-01).

**OSTRZEŻENIE STOI PO ODDANIU BLOKADY SUFITU, NIE W `zajmij()`** — i to jest
poprawka zrobiona przy scalaniu z D-076, nie szczegół stylu. Gałąź #253
powstała, gdy `zajmij()` chodziło samo; D-076 zrobiło z niego drugi krok
atomowej rezerwacji **pod `Cache::lock()`**. Wołanie ostrzeżenia stamtąd
wkładałoby do sekcji krytycznej dobowego sufitu zapis do dziennika,
`Cache::add()` i żądanie HTTP z limitem trzech sekund — a `CZEKANIE_SEKUND`
w tej klasie to **dwie**. Jedno ostrzeżenie „zaraz zabraknie listów"
odmawiałoby więc wysyłki wszystkim żądaniom, które w tym czasie czekają na
blokadę: sygnał o kończącej się puli sam by ją zabierał. Pilnuje tego
`SufitPocztyOstrzegaZawczasuTest::test_ostrzezenie_pada_dopiero_po_oddaniu_blokady_sufitu`,
który zdobywa tę samą blokadę z wnętrza słuchacza dziennika.

Skutek uboczny, przyjęty świadomie: droga listu próbnego
`kuking:wyslij-podsumowania --tylko`, która woła `zajmij()` wprost, nie
ostrzega o niczym. I nie powinna — ten list wychodzi ŚWIADOMIE ponad sufitem
(D-076), przy właścicielu patrzącym w konsolę, a stan puli wypisuje mu
`php artisan kuking:sprawdz-poczte`.

### 7. Alarm gaśnie po ODHACZENIU, nie po czasie

`/health` mówi `degraded`, dopóki w `mail_failures` leży choć jeden wiersz
z `zauwazony_at IS NULL`. **Bez okna czasowego** — i to jest sedno: alarm
z oknem „ostatniej godziny" gaśnie sam, czyli awaria z drugiej w nocy jest
o świcie znowu niewidoczna. To ta sama cicha porażka, tylko o kilka godzin
późniejsza.

Cena, przyjęta świadomie: `/health` może stać w `degraded` przez wiele godzin.
`listy` nie są na liście `KRYTYCZNE`, więc trasa oddaje dalej **HTTP 200**
i Railway nie restartuje z tego powodu niczego (ta lekcja jest stara —
healthcheck oddający 503 już raz położył ten serwis). Odhaczenie to jedna
komenda, po przeczytaniu: `php artisan kuking:nieudane-listy --odhacz`.

### 8. Czego świadomie NIE zrobiliśmy

- **Pozycji w panelu moderacji.** Trzy inne gałęzie pracują równolegle
  w `resources/views/pages/admin/**`, a alarm, który już działa bez panelu
  (`/health` + dziennik + komenda), nie jest wart kolizji. Panel jest
  naturalnym miejscem na to później — jako osobna praca, nie doklejona tutaj.
- **Podnoszenia `--tries` i własnego ponawiania.** Issue #234 zabrania
  pierwszego, a drugie byłoby drugim systemem kolejek obok tego, który już
  jest.
- **Drugiej tabeli na ostrzeżenia o limicie.** Ostrzeżenie z §6 nie jest
  awarią i nie ma czego odhaczać — wiersz w bazie sugerowałby, że jest.
- **Zapisu adresu odbiorcy.** Jest w payloadzie zadania i w koncie; trzecia
  kopia byłaby trzecią rzeczą do skasowania przy żądaniu RODO.

### 9. Zmiana wymaga

Przemyślenia obu połówek naraz. Dodanie piątej kategorii do `PowodOdmowy` bez
migracji kończy się cichym `QueryException` w słuchaczu, czyli **utratą śladu
przy pierwszej awarii nowego typu** — dlatego CHECK w bazie powstaje z listy
`PowodOdmowy::wartosci()`, a `NieudanyListZostawiaSladTest` porównuje jedno
z drugim. Dołożenie okna czasowego do sondy `/health` cofa §7. Owinięcie
`ZapiszNieudanyList` czymkolwiek, co rzuca, cofa §2.

Pilnują tego: `NieudanyListZostawiaSladTest`,
`SufitPocztyOstrzegaZawczasuTest`, `PocztaPrzezApiEmailLabsTest`,
`HealthNieZdradzaSzczegolowTest`, `PodzialLimituPocztyTest`.

### 10. Spotkanie z D-076, D-077 i D-078 (dopisane przy scalaniu z `main`)

Ta gałąź powstała PRZED trzema decyzjami, które weszły na `main` tego samego
dnia. `git` nie pokazał ani jednego konfliktu tekstowego w kodzie poza jedną
metodą — a mimo to trzy rzeczy wymagały rozstrzygnięcia:

1. **D-076 (atomowa rezerwacja dobowego budżetu).** Jedyny prawdziwy konflikt
   merytoryczny i jedyny naprawiony kodem: ostrzeżenie z §6 przeniesione
   z `zajmij()` do `sprobujZarezerwowac()`, poza blokadę. Wyprowadzenie
   w §6.
2. **D-077 (rezerwacja `(osoba, tydzień)` przed wysłaniem digestu).**
   **Nie koliduje — a `mail_failures` domyka jedną świadomą lukę tamtej
   decyzji.** D-077 §5 pisze wprost: „nie ma sposobu, żeby dowiedzieć się
   z bazy, którym osobom list przepadł — wiersz rezerwacji wygląda identycznie
   dla «wysłano» i dla «padło po rezerwacji»". Od tej gałęzi jest sposób:
   wiersz `mail_failures` z `user_id` i `rodzaj`. Nie zmienia to kierunku
   D-077 (rezerwacja ZOSTAJE, ta osoba nie dostaje listu za ten tydzień) —
   zmienia tylko to, że właściciel WIE, komu przepadł, i może napisać innym
   kanałem.
   **Czego to nadal nie łapie, powiedziane wprost:** ślad powstaje na
   `JobFailed`, czyli dla zadania, które WESZŁO do kolejki i tam przegrało.
   Gdyby samo `Mail::queue()` rzuciło synchronicznie (np. padła baza kolejki),
   rezerwacja tygodnia zostaje, listu nie ma i śladu też nie ma. To jest
   dokładnie kierunek pomyłki wybrany w D-077 §5 i tej gałęzi nie wolno go
   po cichu odwracać — dlatego niczego tu nie „naprawiono".
3. **D-078 (sygnał digestu mówi `weekly_digest_queued`).** Nie koliduje i nie
   dubluje się: D-078 poprawiło NAZWĘ metryki, która zawyżała skuteczność
   wysyłki, bo liczyła zakolejkowanie jako wysłanie. Ta gałąź dokłada to,
   czego tamta nazwa nie mogła mieć — **twardy ślad o liście, który przegrał
   w workerze**. Jedno mówi „zakolejkowaliśmy tyle", drugie „tyle przepadło";
   razem dają liczbę, której do dziś nie było. Żadnego `delivered` ani
   `opened` ta gałąź nie wprowadza (#204 zostaje zamknięte na „nie").

📄 `app/Poczta/PowodOdmowy.php` · `app/Poczta/BezpiecznyKomunikat.php` ·
`app/Poczta/ZapiszNieudanyList.php` · `app/Poczta/OdmowaEmailLabs.php` ·
`app/Poczta/TransportEmailLabs.php` · `app/Models/MailFailure.php` ·
`app/Providers/PocztaServiceProvider.php` ·
`app/Http/Controllers/HealthController.php` ·
`app/Http/Controllers/Auth/EmailVerificationController.php` ·
`app/Console/Commands/NieudaneListy.php` ·
`app/Console/Commands/SprawdzPoczte.php` ·
`app/Domain/Security/DziennyBudzetListow.php` ·
`resources/views/auth/verify-email.blade.php` ·
migracja `2026_09_10_500000_create_mail_failures_table` ·
`config/kuking.php` (`poczta`) · `docs/DATABASE.md` ·
`docs/infra/MONITORING_BLEDOW.md`

---

## D-063 · PostHog: nie teraz — statystyki zostają własne, w naszej bazie

**Data:** 10 września 2026 · Issue #33 (audyt monitoringu) · Status: **obowiązuje do spełnienia warunków powrotu niżej**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Dwa zdania z zakończenia
> tego wpisu przestały obowiązywać przy **D-092** i nie zostały wtedy
> odnotowane. „PostHog **zostaje w tabeli stacku jako wybór docelowy**
> (`AGENTS.md` §3)" — nie zostaje: `AGENTS.md:116` ma dziś wiersz „Analityka |
> własna, serwerowa (`App\Domain\Analytics\*`) + Cloudflare Web Analytics (bez
> ciasteczek — D-092)". „`.railway/railway.ts` i `.env.example` mają już
> przygotowane `POSTHOG_KEY`/`POSTHOG_HOST` (EU) — ten kawałek nie wymaga
> zmian" — `.env.example` nie zawiera ani jednego `POSTHOG`; zostało wyłącznie
> `.railway/railway.ts:439`. Zmieniła się też przesłanka tytułu („statystyki
> zostają własne, w naszej bazie"): zewnętrzne narzędzie analityki odwiedzin
> JEST wpięte (`app/Support/AnalitykaCloudflare.php`,
> `resources/legal/polityka-prywatnosci.md:54`). Instrukcja wdrożeniowa niżej
> odsyła do zmiennych, których nie ma.

`AGENTS.md` §3 nazywa PostHog (EU) docelową analityką w tabeli stacku, a
issue #33 prosił o „projekt na EU Cloud" i taksonomię zdarzeń. Audyt
zewnętrzny z 9 września (`docs/research/ANALITYKA_STAN_WDROZENIA.md` §0)
potwierdza stan faktyczny: **PostHog nie jest wpięty nigdzie w kodzie.**
Cała taksonomia z `docs/seo/ANALYTICS.md` jest planem, nigdy nie
uruchomionym. Ta decyzja nie zmienia tego stanu — mówi, dlaczego, i pod
jakim warunkiem miałby się zmienić.

### 1. Co PostHog realnie by dał

- **Lejki i porzucenia bez pisania SQL-a** — `docs/seo/ANALYTICS.md` opisuje
  dziesiątki zdarzeń (`onboarding_step_failed`, `recipe_create_abandoned`,
  `photo_upload_failed` z powodem) i budowanie z nich lejków w interfejsie,
  zamiast ręcznie liczonych zapytań w `app/Domain/Analytics`.
- **Session replay i heatmapy** — dziś nie mamy NIC, co pokazuje, GDZIE
  na ekranie ludzie się gubią, tylko ŻE się gubią (z liczby zdarzeń).
- **Segmentację i kohorty** bez pisania nowej migracji za każdym razem, gdy
  ktoś chce inaczej podzielić użytkowników.
- **Feature flagi / A-B testy** — infrastruktura, której dziś nie ma wcale.

### 2. Czego PostHog NIE daje przy tej skali

- **Nie zastępuje statystyk, które produkt pokazuje UŻYTKOWNIKOM** — liczba
  „Ugotowań", licznik obserwujących, statystyki panelu admina. Te muszą
  zostać w PostgreSQL niezależnie od PostHoga, bo muszą być widoczne w
  interfejsie, nie tylko w dashboardzie analitycznym (polityka prywatności,
  sekcja 3: „Statystyki liczymy sami, w naszej własnej bazie" — zdanie,
  nie tylko intencja tego dokumentu).
- **Nie rozwiązuje niczego, czego nie da się dziś policzyć zapytaniem SQL.**
  Przy kilkuset użytkownikach i JEDNYM właścicielu bez zespołu produktowego
  ani analityka, pytania typu „ile osób porzuciło kreator przepisu na kroku
  2" da się odpowiedzieć zapytaniem do `recipe_versions`/`product_signals`
  w kilka minut — dokładnie tak, jak dziś liczy to
  `app/Domain/Analytics/*` (audyt z 9 września to potwierdza).
- **Nie jest za darmo w danych osobowych.** Nawet w konfiguracji „bez
  identyfikatorów" (`docs/legal/COMPLIANCE.md` §5.3) PostHog widzi adres IP
  i cechy przeglądarki każdego zdarzenia — to jest NOWY podprocesor,
  którego dziś nie ma, i NOWE zdanie w tabeli sekcji 3 polityki prywatności
  („Statystyki liczymy sami, w naszej własnej bazie — nie korzystamy z
  żadnego zewnętrznego narzędzia analitycznego" przestałoby być prawdą —
  `PolitykaPrywatnosciWymieniaKazdaUslugeTest` i `DokumentyPrawneNieKlamiaTest`
  oblałyby się tego samego dnia, i słusznie).
- **Nie jest za darmo bez banera zgody.** `docs/legal/COMPLIANCE.md` §5.3-5.4
  ustala wprost: Polska (UODO) nie ma wyjątku dla analityki, jaki mają
  Francja, Włochy czy Hiszpania (ten warunek issue #33 oznaczał `[do
  weryfikacji]` — sprawdzone: UODO nie wydała takich wytycznych, więc
  ostrożne założenie „potrzebny baner" zostaje). Realistyczny wariant
  „bez identyfikatorów, bez cookies" wymaga rygorystycznej konfiguracji
  i konsultacji prawnej, których dziś nikt nie zamówił — dokładnie tak,
  jak COMPLIANCE.md to rekomenduje dla zespołu tej wielkości.
- **Zakaz publicznych rankingów w AGENTS.md/FEATURES.md nie zmienia się
  przez PostHoga** — PostHog to narzędzie WEWNĘTRZNE dla właściciela, nie
  publiczny ranking; to nie jest argument za ani przeciw, tylko potwierdzenie,
  że ta decyzja nie dotyka tamtej.

### 3. Rachunek przy jednym właścicielu i kilkuset użytkownikach

Koszt wdrożenia PostHoga to nie composer/npm (SDK JS jest darmowy do
wpięcia), tylko:

1. decyzja produktowa o banerze zgody ALBO konfiguracji bez-zgodowej
   (`docs/legal/COMPLIANCE.md` §5.4) — pracy prawnej i UX, nie kodu;
2. zmiana `resources/legal/polityka-prywatnosci.md` (nowy wiersz w tabeli
   dostawców, akapit o EOG — PostHog EU Cloud siedzi we Frankfurcie, więc
   przekazywanie poza EOG raczej nie dotyczy, ale to trzeba SPRAWDZIĆ przy
   wdrożeniu, nie założyć);
3. utrzymywanie DRUGIEGO źródła prawdy o zachowaniu użytkowników obok
   `app/Domain/Analytics` — ryzyko dokładnie takiego rozjazdu, jakiego
   ten projekt unika wszędzie indziej (`kuking.media_disk`, dwa liczniki
   budżetu poczty, D-060).

Przy jednym właścicielu, kilkuset użytkownikach i istniejącej, działającej
analityce w PostgreSQL ten koszt dziś przewyższa korzyść. Lejki i porzucenia
da się policzyć zapytaniem; session replay i feature flagi są przydatne przy
zespole i skali, których jeszcze nie ma.

### 4. Decyzja: nie teraz. Warunki powrotu

PostHog **zostaje w tabeli stacku jako wybór docelowy** (`AGENTS.md` §3) —
ta decyzja go stamtąd nie usuwa, tylko mówi, że jeszcze nie teraz. Wracamy
do tematu, gdy zajdzie **którykolwiek** z tych warunków:

1. serwis przekroczy rząd wielkości, przy którym zapytanie SQL przestaje
   wystarczać na odpowiedź w rozsądnym czasie (w praktyce: tysiące aktywnych
   użytkowników dziennie, nie setki);
2. do zespołu dojdzie osoba odpowiedzialna za produkt/wzrost, dla której
   lejki bez pisania SQL-a są codzienną pracą, nie ciekawostką raz na
   miesiąc;
3. pojawi się konkretne pytanie produktowe, którego `app/Domain/Analytics`
   NIE umie dziś odpowiedzieć (nie „może się przyda", tylko realne pytanie
   bez odpowiedzi);
4. właściciel PODEJMIE decyzję o banerze zgody (albo o konfiguracji
   bez-zgodowej po konsultacji prawnej) — to jest warunek WSTĘPNY, nie
   następstwo wdrożenia.

Gdy któryś z warunków zajdzie: wybrać PostHog Cloud **EU** (nigdy US —
`docs/legal/COMPLIANCE.md` §4), wpiąć zgodnie z taksonomią
`docs/seo/ANALYTICS.md`, i **W TYM SAMYM PR-ze** dopisać wiersz w tabeli
dostawców `resources/legal/polityka-prywatnosci.md` oraz usunąć/przeformułować
zdanie „nie korzystamy z żadnego zewnętrznego narzędzia analitycznego" —
dokładnie ten sam mechanizm, który D-024/D-038 pilnują w drugą stronę.
`.railway/railway.ts` i `.env.example` mają już przygotowane
`POSTHOG_KEY`/`POSTHOG_HOST` (EU) — ten kawałek nie wymaga zmian.

**Pliki:** `docs/seo/ANALYTICS.md` · `docs/legal/COMPLIANCE.md` §4-5 ·
`docs/research/ANALITYKA_STAN_WDROZENIA.md` §0 ·
`app/Domain/Analytics/*` · `resources/legal/polityka-prywatnosci.md` §3 ·
`tests/Feature/PolitykaPrywatnosciWymieniaKazdaUslugeTest.php` ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` · `AGENTS.md` §3

---

## D-093 · Kasowanie konta bierze wiersze `follows` i `blocks` po jednym, w kolejności ustalonej PRZEZ DANE — `ZamekPary` się tu nie da i to jest zmierzone

**Data:** 10 września 2026 · Audyt kolejności blokad, znalezisko Z-2
(`docs/research/2026-09-10-kolejnosc-blokad.md`) · Status: **obowiązuje**

### Co było złamane

`EraseAccountData` bierze wiersz `users` pod `FOR UPDATE` — zgodnie z regułą
„konto najpierw" z D-075 — ale relacje kasowało **bez żadnej ustalonej
kolejności wierszy**, dwoma hurtowymi `detach()` w stałej kolejności RÓL:

```php
$fresh->following()->detach();   // wiersze (X, *)
$fresh->followers()->detach();   // wiersze (*, X)
```

Dla pary, która obserwuje się wzajemnie, w `follows` leżą DWA wiersze:
`(X,Y)` i `(Y,X)`. Egzekucja konta X brała najpierw `(X,Y)`, potem `(Y,X)`;
egzekucja konta Y — dokładnie odwrotnie. Każda trzymała to, na co czekała
druga, i PostgreSQL zabijał jedną z nich (pomiar E5 audytu, odtworzony przy
tej poprawce na dwóch połączeniach):

```text
ERROR: deadlock detected
CONTEXT: while deleting tuple (0,5) in relation "follows"
```

**Co widział człowiek.** Nocna komenda `kuking:usun-wygasle-konta` przerywa
się w połowie, a konto, które **prosiło o usunięcie**, nie zostaje tej nocy
wymazane. Przy kolejnym przebiegu zwykle przejdzie — ale „zwykle" nie jest
obietnicą, a to jest obowiązek prawny (RODO art. 17), nie wygoda.

**Jak realne.** `routes/console.php:96` ma `withoutOverlapping()`, więc
harmonogram nie zderzy się sam ze sobą. Zderzy się z **ręcznym przebiegiem
właściciela**, a D-077 §3 wymienia ten scenariusz wprost jako realny
w tym projekcie.

### Decyzja

Wiersze `follows` i `blocks` kasujemy **po jednym, w kolejności wyliczonej
z klucza głównego wiersza** — nowa metoda prywatna
`EraseAccountData::usunRelacjeWKolejnosciDanych()`. Który identyfikator siada
w której pozycji klucza, wynika z DEFINICJI RELACJI (`following()` to zawsze
`follower_id → followed_id`, dla każdego konta jednakowo), a nie z tego, KTÓRE
konto jest wymazywane. Obie egzekucje wyliczają więc dla wiersza `(X,Y)` ten
sam klucz, ustawiają się w kolejce i nie mają z czego zbudować cyklu.

Kontrola dodatnia naprawy, zmierzona na dwóch połączeniach do PostgreSQL:
kolejność po rolach → `deadlock detected`; kolejność po danych → druga
egzekucja **czeka w kolejce**, po obu zostaje zero wierszy.

**`detach()` zostaje.** Kasujemy nadal przez relację, tylko z jawnym
identyfikatorem: `detach([$id])` jest zawężony do tego konta ORAZ do jednego
wskazanego wiersza, więc najgorsza możliwa awaria tej ścieżki — zabranie
relacji dwóch obcych osób — pozostaje niemożliwa z konstrukcji (pilnuje tego
`WymazanieKontaUsuwaRelacjeI2FATest::test_relacje_innych_osob_zostaja_nietkniete`).
Sam odczyt listy wierszy idzie po surowej tabeli: odczyt niczego nie kasuje,
a branie go przez relację dokładałoby złączenie z `users` i narażało listę na
dowolny przyszły zakres globalny na modelu konta.

**Schemat bazy się NIE zmienia.** Żadnej migracji, żadnego wpisu
w `docs/DATABASE.md` — to poprawka kolejności operacji, nie modelu danych.

### Dlaczego NIE `ZamekPary` — nie „drożej", a **nie da się**

Raport dawał drugą drogę: przepuścić kasowanie relacji przez `ZamekPary` dla
każdej pary z osobna, z ceną „tyle transakcji, ile relacji". Ta droga jest
odrzucona nie z powodu ceny, tylko dlatego, że **jest niepoprawna w tym
miejscu** — i to jest zmierzone, nie wydedukowane.

`handle()` trzyma już wiersz `users` wymazywanego konta pod `FOR UPDATE`
(D-075). `ZamekPary` bierze OBA wiersze pary rosnąco po identyfikatorze —
czyli dla pary, w której wymazywane konto ma identyfikator wyższy, chciałby
wziąć najpierw wiersz drugiej osoby. Dwie egzekucje na parze wzajemnej
odtwarzają wtedy **dokładnie cykl z Z-1/D-090**: każda trzyma własny wiersz
`users` i czeka na cudzy. Zmierzone:

```text
ERROR: deadlock detected
CONTEXT: while locking tuple … in relation "users"
```

Czyli lekarstwo wprowadzałoby tę samą chorobę, którą D-090 właśnie
wyleczyło — tylko na innej tabeli. Dodatkowo zagnieżdżone `DB::transaction()`
jest w Laravelu tylko **punktem powrotu**, a nie osobną transakcją, więc
obiecana cena („tyle transakcji, ile relacji") i tak nie jest osiągalna
z wnętrza tej transakcji: blokady żyłyby do commitu transakcji zewnętrznej.

`ZamekPary` zostaje więc **nietknięty** i nadal jest jedynym gardłem dla
operacji na parze osób wykonywanych Z ZEWNĄTRZ (`FollowUser`, `BlockUser`).
Kasowanie konta nie jest taką operacją: dotyczy JEDNEGO konta i wszystkich
jego par naraz, a wchodzi od strony blokady konta, nie pary.

### Dlaczego NIE jedno zapytanie z `ORDER BY … FOR UPDATE`

To była pierwsza, tańsza droga z raportu — dwa zapytania na tabelę zamiast
tylu, ile relacji. Odrzucona z tego samego powodu, dla którego `ZamekPary`
bierze swoje dwa wiersze `users` dwoma osobnymi zapytaniami, i ten powód jest
już zapisany w tym repozytorium:

> `SELECT … ORDER BY id FOR UPDATE` blokuje wiersze w kolejności, w jakiej
> wypuszcza je plan zapytania. (…) Gwarancja, która trzyma się na kształcie
> planu, nie jest gwarancją.

Druga, słabsza reguła kolejności blokad w tej samej dziedzinie to dokładnie
ten rozjazd, przed którym ostrzega D-079 („dwie różne kolejności w jednym
repozytorium to zakleszczenie, a nie zabezpieczenie"). `DELETE` w PostgreSQL
nie przyjmuje przy tym `ORDER BY` wcale, więc wariant „jedno zapytanie"
i tak wymagałby osobnego `SELECT … FOR UPDATE` przed nim.

Rozważony i odrzucony był też wariant naprawdę tani: dwa hurtowe `DELETE`
rozdzielone warunkiem na danych (`follower_id < followed_id` i odwrotnie).
Dla DWÓCH równoległych egzekucji jest poprawny, ale dla trzech już nie —
w obrębie jednego hurtowego `DELETE` kolejność wierszy nadal ustala plan,
więc trzy egzekucje na trzech parach mogą domknąć cykl `X→Y→Z→X`. Poprawność
zależna od liczby równoległych przebiegów nie jest poprawnością, a nic nie
gwarantuje, że przebiegów będzie najwyżej dwa.

**Cena, którą płacimy, nazwana wprost:** tyle zapytań `DELETE`, ile relacji
ma kasowane konto — ale w JEDNEJ transakcji (nie tyle transakcji, ile
relacji) i w nocnej komendzie, nie w żądaniu HTTP. Przy koncie z setką relacji
to setka zapytań do lokalnej bazy, czyli rzędu kilkudziesięciu milisekund.

### `blocks` ma ten sam kształt — i został naprawiony razem

Sprawdzone przy migracji, nie założone.
`2026_09_05_000300_create_follows_and_blocks_tables` daje `blocks` klucz
główny `(blocker_id, blocked_id)` i CHECK `blocker_id <> blocked_id`, ale
**nic nie zabrania pary wzajemnej** — „X zablokował Y" i „Y zablokował X" to
dwa osobne wiersze, dokładnie jak w `follows`. Ta sama usterka, ta sama
naprawa, osobny test (bo osobne wywołanie łatwo poprawić tylko w jednym
z dwóch miejsc).

`tag_follows` **zostaje jednym hurtowym `detach()`** i to nie jest
niedokończona robota: kluczem jest `(user_id, tag_id)`, więc dwie egzekucje
różnych kont nie mają ani jednego wspólnego wiersza — nie ma czego szeregować
i nie ma jak zbudować cyklu. Wiersz `tags` po drugiej stronie klucza obcego
też nie tworzy wspólnego punktu, i to jest zmierzone: `DELETE` z tabeli
odsyłającej nie bierze na wierszu rodzica ŻADNEJ blokady (0 blokad krotek
i 0 wpisów w `pg_locks` dla relacji rodzica). Blokady kluczy obcych, które
dały Z-1, bierze `INSERT`, nie `DELETE`.

### Czego ta decyzja NIE rozstrzyga

**Nie zakłada grupy testów `dwa-polaczenia`.** Rozdział 7 raportu wycenia ją
na pół dnia szkieletu i nazywa najwyższy koszt: testy na prawdziwej
równoległości bywają niestabilne, a niestabilny test jest tu gorszy niż jego
brak. To zostaje osobną decyzją. Skutkiem jest to, że **brak `40P01` przy
prawdziwej równoległości nie jest dziś pilnowany żadnym testem** — zmierzono
go poza zestawem, a `KasowanieKontaBierzeRelacjeWKolejnosciDanychTest`
pilnuje dwóch rzeczy słabszych, ale sprawdzalnych na jednym połączeniu:
że każde zapytanie kasujące wskazuje dokładnie jeden wiersz (więc kolejności
nie ustala plan) i że dwie egzekucje na tej samej parze biorą wiersze w tej
samej kolejności (więc kolejność jest funkcją danych, nie roli). Ograniczenie
jest wypisane w docblocku tego pliku, zgodnie z `docs/PULAPKI_TESTOW.md` §6.

**Nie rusza `usunTresci()`, a znaleziono tam podobny kształt.** Przy zakresie
`everything` kasowanie przepisu zabiera kaskadą CUDZE komentarze i CUDZE
wykonania pod nim (to jest świadome, D-022) — a to znaczy, że dwie egzekucje
mogą dotknąć tego samego wiersza `comments` z dwóch stron: jedna przez
`$user->comments()`, druga kaskadą od swojego przepisu. Kształt jest podobny
do Z-2, ale **nie jest zmierzony** i nie ma zgłoszenia; zapisuję go tu jako
znalezisko z czytania, nie jako ustalenie. Naprawianie go „przy okazji" tej
poprawki byłoby dokładnie tym, przed czym raport ostrzegał przy Z-2.

**Nie rusza Z-3** (`LoginLinkController::store()` bierze wiersz tokenu bez
wiersza konta). Osobna decyzja.

### Jak to wycofać

Jedna metoda prywatna i dwa wywołania. Przywrócenie czterech hurtowych
`detach()` w miejsce dwóch wywołań `usunRelacjeWKolejnosciDanych()` wraca do
stanu sprzed poprawki — bez migracji, bez zmiany schematu i bez wpływu na
dane już wymazane. Cena wycofania to powrót zakleszczenia Z-2.

**Zmiana wymaga:** rezygnacji z zasady „kolejność blokad ustalają dane, nie
role" — a wtedy razem z nią z D-079, D-080 i D-090. Sama kolejność (rosnąco
po kluczu) nie podlega zmianie inaczej niż we wszystkich miejscach naraz.

**Pliki:** `app/Domain/Users/Actions/EraseAccountData.php` ·
`tests/Feature/KasowanieKontaBierzeRelacjeWKolejnosciDanychTest.php` ·
`docs/research/2026-09-10-kolejnosc-blokad.md` ·
D-022 · D-075 · D-077 · D-079 · D-080 · D-090

---

## D-099 · Automat dostępności mierzy stronę W TYM STANIE, W KTÓRYM WYDAJE JĄ PRODUKT — czerwone 2.4.11 na `main` było usterką POMIARU, nie belki

**Data:** 11 września 2026 · **Naprawa automatu** (job „Dostępność" oblewający
na `main`, blokujący #306 i #213) · Status: **obowiązuje**

### Objaw: ten sam kod, pięć przebiegów, pięć różnych wyników

Job „Dostępność (axe-core) i wydajność (Lighthouse)" oblewał na `main`
i na każdym PR-ze z niego zbudowanym. Zawsze **WCAG 2.2 AA — 2.4.11 Focus Not
Obscured**, zawsze wariant **`tekst 140%`**, zawsze pod `.bottom-nav` —
a za każdym razem inny ekran, inny element i inna szerokość:

| przebieg | wynik |
|---|---|
| #303 (`142e645`) | 2: `szukaj / 320 px` „Rosół babci Zofii”, `ustawienia profilu / 360 px` `input#f-region` |
| #307 (`8372d10`) | 1: `tablica / 360 px`, odnośnik z datą |
| #308 (`d62bee1`) | 1: `tablica / 414 px`, odnośnik „Ania” |
| #306 (`84f67f3`) | 0 |
| #306 (`4f045d9`) | 1, w powtórce 2 |

Wynik zależny od przebiegu, łącznie z zerem, przy niezmienionym kodzie.

### Co się okazało: strona była mierzona W TRAKCIE PRZELICZANIA UKŁADU

Produkt wydaje `data-text-scale` na `<html>` **po stronie serwera**
(`layout.blade.php` w. 165), więc strona osoby, która włączyła większy tekst,
jest ułożona dużym pismem od pierwszego ułożenia i nigdy się z tego powodu
nie przelicza. Automat robił odwrotnie: wczytywał stronę w rozmiarze
domyślnym, dokładał atrybut po wczytaniu — i **od razu zaczynał chodzić
Tabem**.

Przeliczenie układu po zmianie atrybutu na korzeniu nie jest natychmiastowe.
Zmierzone w kontenerze deweloperskim (Chromium 153, `/tagi` i `/szukaj` przy
320 px), pomiar wykonany zaraz po `setAttribute` potrafił jeszcze zobaczyć
układ SPRZED skalowania — i to w stanie mieszanym, który sam w sobie jest
dowodem:

```text
--user-text-scale (styl policzony)   1.4      ← już nowe
scroll-padding-bottom na :root       179,2px  ← już nowe
.bottom-nav, wysokość ułożona         66,6px  ← jeszcze stare (skala 1)
.site-footer, wypełnienie dolne      128px    ← jeszcze stare (skala 1)
```

Strona przy tekście 140% jest o mniej więcej jedną trzecią wyższa (zmierzone
na `/szukaj` przy 320 px: `scrollHeight` 2796 → 3744 px). Jeżeli przeliczenie
wypadło **w trakcie** chodzenia Tabem, to element, który przeglądarka przed
chwilą przewinęła nad belkę, zjeżdżał razem z rosnącą stroną w dół — a drugi
raz nikt go już nie przewija, bo fokus się nie zmienił. Element lądował pod
belką i automat notował FAIL.

**Kontrola dodatnia mechanizmu.** Przy przeliczeniu opóźnionym o sześć kroków
Taba wychodzą dokładnie te elementy, które zgłaszało CI — odnośnik „Ania” na
tablicy przy 360 i 414 px (por. #308); przy opóźnieniu o trzy i o dziesięć
kroków — zero. To jest cała zmienność wyniku, łącznie z przebiegami zielonymi.

### Hipoteza, którą to OBALA: „`scroll-padding` nie ma na czym zadziałać”

Naturalne wyjaśnienie brzmiało: `scroll-padding-bottom` działa tylko wtedy,
gdy przeglądarka coś przewija, a element leżący już w oknie — wizualnie pod
belką, formalnie „widoczny” — przewijania nie wywoła. Gdyby to była prawda,
D-082 byłoby naprawą pozorną, a jedynym wyjściem `position: sticky`.

**Zmierzone i nieprawdziwe** (Chromium 153, okno 740 px, tekst 140%).
Chromium liczy „czy element jest widoczny” względem *scroll snapport*, czyli
okna POMNIEJSZONEGO o `scroll-padding` — więc element leżący w pasie pod
belką jest dla niego niewidoczny i zostaje przewinięty. Bezpośredni pomiar:
odnośnik „Ania” na `/home` przy 414 px, przy przewinięciu 0, ramka
676…706 px (czyli w całości pod belką zaczynającą się na 664,8 px), po
nadaniu fokusu — `scrollY` 0 → 411 i ramka 265…295 px.

Pomiar wyczerpujący, bez loterii kolejności: **każdy** element ogniskowalny na
`/home`, `/szukaj`, `/ustawienia/profil` i `/tagi`, przy 320/360/414 px
i tekście 140%, ogniskowany osobno po wyzerowaniu przewinięcia (167 kontrolek
na szerokość):

| stan arkusza | kontrolek kończących w 100% pod belką |
|---|---|
| `main` (z `scroll-padding-bottom`) | **0** |
| bez `scroll-padding-bottom` (kontrola ujemna) | **13** |

Lista tych trzynastu zawiera „Ludzie” (`szukaj / 414 px`), „Ania”
(`tablica / 414 px`), odnośnik z datą, `input#f-username`
(`ustawienia profilu / 360 px`) i „Do 30 minut” — czyli **dokładnie te
elementy, które zgłaszało CI**. To jest ostatni brakujący dowód: CI zgłaszało
te elementy, które `scroll-padding-bottom` ratuje, w przebiegach, w których
nie zdążyło ono zadziałać na właściwym układzie.

### Decyzja

**1. Wariant skali tekstu czeka na PRZELICZONY układ i sprawdza, że wszedł.**
`wlaczSkaleTekstu()` w `scripts/dostepnosc.mjs` nadaje atrybut, po czym czeka
na STAN, a nie na zegar: aż ułożona strona pokaże wielkość pisma
podstawowego odpowiadającą żądanej skali (`body` ma `font-size:
var(--text-body)`, czyli `1.125rem × --user-text-scale`). Czekanie na czas
byłoby zakładem o szybkość maszyny — czyli tym samym błędem z innym progiem,
bo runner GitHuba bywa wolniejszy od tego kontenera.

Sprawdzana jest wielkość UŁOŻONA, nie sama wartość zmiennej: to właśnie
zmienna była już nowa wtedy, gdy układ był jeszcze stary.

**Niepowodzenie jest BŁĘDEM, nie pominięciem.** Ekran, którego nie udało się
przeliczyć, nie zostaje zapisany jako zbadany — skrypt kończy kodem 1. Pomiar
w nieznanej skali jest gorszy niż jego brak; to ten sam wzorzec, co
istniejące sprawdzenie korzenia przy wariancie „czcionka przeglądarki 200%”.

**2. Pierwszeństwo ma przeglądarka, którą mierzy CI.** `znajdzChromium()`
brało ścieżkę z obrazu deweloperskiego zawsze, gdy tylko istniała — a to
rewizja 1194 (Chromium 141), podczas gdy `playwright` 1.63 przypina 1243
(Chrome 153) i to ją pobiera runner. Dopóki tak było, „u mnie zielone,
na CI czerwone” mogło znaczyć wyłącznie tyle, że to były dwie różne
przeglądarki — i nie dało się tego rozstrzygnąć bez ręcznego ustawiania
zmiennej. Teraz bierzemy tę, którą weźmie CI; ścieżka z obrazu zostaje
zapasem. `CHROMIUM_PATH` dalej przebija wszystko.

### Czego ta decyzja NIE robi — i to jest w niej najważniejsze

**Nie zmienia ani jednej linijki CSS-a i nie rusza D-082.** Belka zostaje
`position: fixed`, rezerwa zostaje liczona. Sprzeczności 2.4.11 z 2.5.8 nie
trzeba było rozstrzygać, bo nie było czego kupować: pomiar wyczerpujący mówi
0 naruszeń 2.4.11 przy nietkniętym arkuszu. Zamiana `fixed` na `sticky`
kosztowałaby trzy naruszenia 2.5.8 (D-082 ma je zmierzone) w zamian za
naprawę usterki, której nie ma.

**Dowód, że jedno naruszenie nie zostało wymienione na drugie**, jest w tym
samym przebiegu, nie w osobnym: axe chodzi tu z `wcag22aa`, czyli
z regułą `target-size` (2.5.8), na wariantach 1280 px i 320 px. Pełny
przebieg po zmianie: `naruszeń: 0, blokujących: 0, przepełnień w poziomie: 0,
focus zasłonięty w 100%: 0`.

**Nie podnosi żadnego progu, nie wyłącza żadnej reguły i nie przenosi
niczego do „ostrzeżeń produktowych".** 23 ostrzeżenia „focus częściowo
zasłonięty" (25–75%) zostają tam, gdzie były — nie są naruszeniem 2.4.11
i nadal nie liczą się do kodu wyjścia. Zestaw kontrol zatrzymujących CI jest
niezmieniony i pilnowany testem.

**Nie usuwa zależności od dat zalążkowych.** `DemoSeeder` liczy daty od
`now()`, więc treść (a przez nią wysokość strony) różni się między
przebiegami. Po tej poprawce ta zmienność przestaje mieć skutek: przy
ułożonym układzie miejsce elementu nie decyduje o wyniku, bo przewijanie
fokusu każdy element wyprowadza spod belki, a rezerwa na końcu dokumentu
(179,2 px przy 140%) jest większa od belki (105,5 px) na każdej mierzonej
szerokości. Zamrożenie zegara siewu wymagałoby zmiany w `DemoSeeder` i jest
osobną pracą.

### Przy okazji, w tym samym pliku — dwie zaległości z §14.6 przekazania

* **`/tagi` wchodzi na listę `EKRANY`** (jako gość, bo taka to strona).
  Powstało w #303 (D-087) i przez dwie doby było jedyną stroną publiczną
  serwisu, której automat nie oglądał — nie z decyzji, tylko dlatego, że plik
  trzymały wtedy trzy gałęzie naraz. Brak ekranu na liście niczego nie psuje
  i dlatego jest groźny: raport wygląda na kompletny.
* **`liczbyProfilu` wchodzą do `storage/dostepnosc.json`** — kontrola
  `rozjazdyLiczb` (D-091) była w linii podsumowania i w warunku wyjścia,
  a w artefakcie jej nie było. Artefakt jest jedynym miejscem, z którego
  da się odczytać przyczynę po skończonym przebiegu.

### Czym to jest pilnowane

Dwa nowe testy chodzą w jobie `test`, czyli ZAWSZE — także wtedy, gdy job
`dostepnosc` się nie odpali, bo ten startuje warunkowo (`git diff`).

`PomiarDostepnosciKonczyKodemJedenTest` sprawdza wszystkie **trzy miejsca
zbiorcze** tego pliku (§14.5 przekazania) osobno: linię podsumowania, warunek
wyjścia kodem 1 i artefakt JSON. Lista siedmiu kontrol jest w teście wpisana
z ręki, a nie czytana ze źródła — kontrola wyprowadzona z badanego pliku
znika razem z nim. Ten sam test pilnuje, że skala tekstu wchodzi wyłącznie
przez `wlaczSkaleTekstu` we wszystkich trzech pomiarach; powrót do gołego
`setAttribute` przywraca wyścig, i to po cichu, bo objawia się on dopiero na
obciążonej maszynie i raz na kilka przebiegów.

`PomiarDostepnosciObejmujeStronyPubliczneTest` porównuje tablicę tras z listą
`EKRANY` i wymaga, żeby każda strona publiczna była mierzona albo stała na
wypisanej liście świadomych wyjątków z powodem. Skan ujawnił przy okazji
**siedem stron publicznych, których automat nie ogląda** — `o-kuking`,
`pomoc`, `odwolanie` (gość), `nie-pamietam-hasla`, `logowanie/link`,
`logowanie/kod`, `cofnij-usuniecie-konta`. Stoją na tej liście jako
**dług nazwany**, nie jako wyjątek merytoryczny: dopisanie ich to osobna
praca, bo każdy nowy ekran może przynieść własne znaleziska, a tego nie robi
się w PR-ze, który ma odblokować `main`.

### Jak to wycofać

Trzy niezależne kawałki, każdy osobno. Przywrócenie gołego `setAttribute`
w trzech miejscach wraca do stanu sprzed poprawki (i do losowego czerwonego
CI). Przywrócenie starej kolejności w `znajdzChromium()` wraca do mierzenia
lokalnie inną przeglądarką niż na CI. Skreślenie `/tagi` i `liczbyProfilu`
wraca do niepełnego pomiaru i niepełnego artefaktu. Żadne z nich nie dotyka
danych, schematu ani wyglądu serwisu.

**Zmiana wymaga:** rezygnacji z zasady „automat mierzy stronę w tym stanie,
w którym wydaje ją produkt". Gdyby produkt kiedyś zaczął zmieniać skalę
tekstu bez przeładowania strony, ta decyzja przestaje być tylko o pomiarze
i trzeba ją napisać od nowa — razem z odpowiedzią na pytanie, co wtedy dzieje
się z fokusem, który już stoi na elemencie.

**Pliki:** `scripts/dostepnosc.mjs` ·
`tests/Feature/PomiarDostepnosciKonczyKodemJedenTest.php` ·
`tests/Feature/PomiarDostepnosciObejmujeStronyPubliczneTest.php` ·
D-082 · D-087 · D-091

---

## D-098 · Powiązania z dostawcami tożsamości mieszkają w tabeli `tozsamosci_zewnetrzne`, a nie w kolumnach na `users` — bo drugi dostawca jest zamówiony, nie wyobrażony

**Data:** 10 września 2026 · Issue #258 · **Prośba właściciela wprost:**
„trzeba pilnie dać logowanie przez Gmail i Facebook i łączenie konta z nimi"
· Status: **obowiązuje**

### Co ta decyzja zmienia w D-069, a czego nie rusza

**D-069 zostaje w całości** — łącznie z trzema regułami łączenia kont,
`email_verified` jako warunkiem, brakiem Turnstile na tej drodze i zakresem
`openid email profile`. Zmienia się jedno: **gdzie leży powiązanie.**

D-069 rozstrzygnęło: dwie kolumny na `users` (`google_sub`,
`google_connected_at`), a tabelę `tozsamosci_zewnetrzne` wskazało jako
„właściwy kształt **przy drugim dostawcy**". To rozstrzygnięcie było wtedy
poprawne i nie jest tu podważane: przy jednym dostawcy tabela byłaby
budowaniem „na przyszłość", czego zabrania `AGENTS.md` §3.

**Drugi dostawca został w tym czasie ZAMÓWIONY.** Właściciel poprosił wprost
o Google **oraz** Facebooka. To przestaje być „na przyszłość" i staje się
„na jutro", więc próg powrotu z D-069 jest przekroczony jego własnym
kryterium — a nie moim domysłem, jak może kiedyś będzie.

### Dlaczego TERAZ, a nie po scaleniu Google'a

Bo teraz jest to darmowe, a potem nie będzie. Gałąź z Google nie jest
scalona, migracja **nigdy nie chodziła na produkcji**, więc wolno ją
przepisać. Alternatywa to migracja przenosząca dane na żywej tabeli `users`
w kilka dni: nowa tabela, `INSERT ... SELECT`, kod czytający chwilowo z dwóch
miejsc, drugie wdrożenie kasujące kolumny — cztery kroki i jedno okno, w którym
droga wejścia na konto działa „w połowie". Przepisanie dziś to jeden plik.

### Kształt

```sql
CREATE TABLE tozsamosci_zewnetrzne (
    id            bigserial PRIMARY KEY,
    user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    dostawca      varchar(20)  NOT NULL,
    identyfikator varchar(255) NOT NULL,
    connected_at  timestamptz  NOT NULL DEFAULT now()
);

ALTER TABLE tozsamosci_zewnetrzne
    ADD CONSTRAINT tozsamosci_dostawca_check CHECK (dostawca IN ('google')),
    ADD CONSTRAINT tozsamosci_identyfikator_check CHECK (identyfikator ~ '^\S{1,255}$'),
    ADD CONSTRAINT tozsamosci_dostawca_identyfikator_unique UNIQUE (dostawca, identyfikator),
    ADD CONSTRAINT tozsamosci_dostawca_konto_unique UNIQUE (dostawca, user_id);
```

**Cztery rzeczy pilnuje BAZA, nie PHP** (`AGENTS.md` §6 — walidacja w PHP
jest dodatkiem, nie zamiennikiem):

1. **`UNIQUE (dostawca, identyfikator)` — jedno konto u dostawcy prowadzi
   do najwyżej jednego konta Kuking.** Bez tego dwa nasze konta mogłyby
   wskazywać ten sam `sub`, a „wejdź kontem Google" wpuszczałoby na to,
   które baza akurat poda pierwsze. To jest odpowiednik indeksu częściowego
   `users_google_sub_unique` z D-069 — z tą różnicą, że nie trzeba go
   powtarzać dla każdego kolejnego dostawcy.
2. **`UNIQUE (dostawca, user_id)` — jedno konto Kuking nie ma DWÓCH
   Google'i.** Tego ograniczenia wersja na kolumnach **nie potrzebowała**
   (kolumna jest jedna) i właśnie dlatego trzeba je było napisać wprost:
   bez niego „połącz" wołane dwa razy dokłada drugi wiersz. Pilnuje go test
   `test_jedno_konto_kuking_nie_ma_dwoch_polaczen_z_google`.
3. **Zamknięta lista dostawców.** Literówka („googel") nie ma prawa cicho
   założyć nowego rodzaju powiązania, a `INSERT` z nazwą `facebook` ma się
   ODBIĆ, dopóki Facebooka nie dopuści osobna migracja — patrz sekcja
   o Facebooku niżej, bo to jest cała jej pointa.
4. **Kształt identyfikatora** — niepusty, bez znaków białych, do 255 znaków.
   Nie zawężamy do samych cyfr, choć dziś Google nadaje wartości
   21-cyfrowe: zawężenie do dzisiejszego kształtu CUDZEGO identyfikatora
   zamknęłoby logowanie w dniu, w którym Google go zmieni, i nie chroniłoby
   przed niczym.

**Czego w tabeli NIE MA:** tokenu dostępu, tokenu odświeżania, tokenu
tożsamości, zdjęcia z dostawcy i **adresu e-mail** (mamy go na `users`;
dwie kopie rozjechałyby się przy pierwszej zmianie adresu, a adres
z dostawcy służy dokładnie raz — przy pierwszym połączeniu). Zakres jest ten
sam co w D-069 i pilnuje go test na liście kolumn, żeby dołożenie piątej
było decyzją, a nie refaktorem.

**Zysk uboczny, który jest ważniejszy, niż wygląda:** CHECK „obie kolumny
albo żadna" (`num_nonnulls(google_sub, google_connected_at) IN (0, 2)`)
**znika całkiem**. Nie dlatego, że przestał być potrzebny — dlatego, że
stan, którego pilnował, przestał być wyrażalny. Wiersz istnieje albo nie
istnieje. To jest jedyny rodzaj naprawy ograniczenia, który nie może się
zepsuć.

### `$fillable` jest PUSTE i to jest zabezpieczenie, nie przeoczenie

`TozsamoscZewnetrzna` nie ma ani jednego pola do masowego przypisania.
Wiersz w tej tabeli **JEST drogą wejścia na konto** dokładnie tak samo jak
hasło: kto go założy, wchodzi jednym kliknięciem. Gdyby te pola stały na
liście, dowolny dzisiejszy i przyszły `create($request->all())` — także
taki, który o dostawcach tożsamości w ogóle nie myśli — byłby przejęciem
konta. Ta sama zasada co `status`, `role` i `email` (`AGENTS.md` §7).
Wiersze powstają wyłącznie przez `User::connectGoogle()`.

### Łączenie idzie POD BLOKADĄ WIERSZA KONTA

`GoogleLoginController::link()` sprawdzał warunki i zapisywał powiązanie bez
blokady. Między jednym i drugim mieści się cała klasa wyścigów, które kończą
się wejściem na konto: nadanie roli moderatora, blokada konta, zmiana adresu,
zdjęcie potwierdzenia adresu, drugie takie samo żądanie z sąsiedniej karty.
Rewalidacja i zapis idą teraz przez `ZamekKonta::zablokuj()` (**D-079**), więc
o dostępie nie rozstrzyga stan sprzed sprawdzenia. Bez blokady zabezpieczenie
„moderator tą drogą nie wchodzi" byłoby prawdziwe tylko przez chwilę.

### Kasowanie konta — dwie drogi i obie są potrzebne

- **`ON DELETE CASCADE`** dla realnego `DELETE` na `users`
  (`migrate:fresh`, sprzątanie danych zasianych, przyszłe twarde usunięcie).
  Wiersz-sierota trzymałby identyfikator konta Google wskazujący w pustkę
  i **BLOKOWAŁBY** ponowne połączenie tego konta Google z czymkolwiek — czyli
  jedna osierocona linijka zamykałaby człowiekowi drogę wejścia na zawsze.
- **Jawne `$fresh->tozsamosciZewnetrzne()->delete()`** w `EraseAccountData`
  (D-022), bo **kont z Kuking się nie kasuje, tylko anonimizuje** — kaskada
  nigdy by tu nie zadziałała. To jest dokładnie ten sam wywód, dla którego
  jawnie kasuje się `pending_email_changes`. Bez tej linii nadpisanie hasła
  wartością losową nie chroniłoby niczego: kto miał to konto Google,
  wchodziłby dalej jednym kliknięciem.

### ROLLBACK

`down()` **odmawia**, gdy w tabeli jest choć jeden wiersz, i mówi, ilu kont
to dotyczy oraz co zrobić zamiast tego. Powód jest ten sam co w D-069 i nie
osłabł, tylko wzmocnił się: konto założone tą drogą **nigdy nie miało hasła**
(w `password` leży skrót wartości losowej, której nie zna nikt, także my),
a `DROP TABLE` jest ostrzejszy od dawnego `dropColumn` — razem z tabelą
znikają identyfikatory, więc powiązań nie da się potem odtworzyć.

```bash
# WŁAŚCIWA DROGA WYCOFANIA — bez migracji, bez utraty powiązań:
KUKING_WEJSCIE_GOOGLE=false     # + restart serwisu

# JEŚLI NAPRAWDĘ trzeba skasować powiązania — powiedz to wprost:
KUKING_ROLLBACK_KASUJ_TOZSAMOSCI_ZEWNETRZNE=true php artisan migrate:rollback --step=1
```

Zmienna nazywa się inaczej niż w D-069
(`KUKING_ROLLBACK_KASUJ_POWIAZANIA_GOOGLE`), bo cofnięcie kasuje teraz
powiązania **wszystkich** dostawców, nie tylko Google. Stara nazwa nie
istnieje w żadnym środowisku — migracja nigdy nie była scalona.

Kolejność przy wycofywaniu kodu i migracji razem: **NAJPIERW KOD, POTEM
MIGRACJA**, inaczej trasy `/wejdz/google` odwołują się do nieistniejącej
tabeli.

### CO BĘDZIE POTRZEBOWAŁ FACEBOOK — I CZEGO U NIEGO NIE DA SIĘ POWTÓRZYĆ

To jest najważniejsza sekcja tego wpisu i jedyny powód, dla którego jest
osobnym wpisem, a nie akapitem w D-069.

**Co Facebook dostanie za darmo:** tabelę (jeden nowy wiersz na osobę,
zero migracji przenoszącej dane), `UNIQUE` na obu parach, puste `$fillable`,
kasowanie razem z kontem, rewalidację pod `ZamekKonta`, odmowę cofnięcia
migracji, `state` i PKCE, dwa koszyki limitów, brak Turnstile na tej drodze,
2FA i blokadę konta po drodze przez `wpusc()`.

**Co Facebook będzie musiał dołożyć:** jedną migrację dopisującą `'facebook'`
do CHECK-a `tozsamosci_dostawca_check` (`DROP CONSTRAINT` + `ADD CONSTRAINT`
— tabela jest mała, blokada trwa milisekundy), własny klient
(inny punkt tokenu, inny format odpowiedzi), własne klucze w `.env`
i własny wpis w polityce prywatności.

**CZEGO U FACEBOOKA NIE DA SIĘ POWTÓRZYĆ — przeczytaj to, zanim skopiujesz
kod Google'a:**

> **Facebook NIE ODDAJE `email_verified`.** Jego Graph API oddaje pole
> `email` albo go nie oddaje wcale (człowiek mógł zarejestrować się numerem
> telefonu), ale **nigdy nie mówi, czy ten adres został potwierdzony**.
> Warunek z D-069 — „bez potwierdzenia adresu nie robimy nic" — jest dla
> Facebooka **niespełnialny**. Nie „trudny": niespełnialny, bo danych, na
> których stoi, po prostu nie ma.
>
> Wniosek, który z tego wynika i którego nie wolno obejść:
> **łączenie konta po adresie e-mail musi być dla Facebooka ZAKAZANE.**
> Nie „ostrożne", nie „za dodatkowym potwierdzeniem" — zakazane. Reguła 3
> z D-069 (adres się zgadza, adres potwierdzony u nas, jedno kliknięcie
> człowieka) opiera się CAŁYM ciężarem na tym, że przejście weryfikacji
> Google dla tego adresu dowodzi kontroli nad skrzynką. U Facebooka ten
> dowód nie istnieje, więc ta sama ścieżka staje się przejęciem konta na
> życzenie: zakładam konto na Facebooku, podaję cudzy adres, klikam
> „to moje konto" i wchodzę.
>
> **Bezpieczna droga dla Facebooka jest tylko jedna:** powiązanie powstaje,
> gdy człowiek jest JUŻ ZALOGOWANY na swoje konto Kuking (hasłem albo
> linkiem e-mail) i dopiero wtedy klika „połącz z Facebookiem" —
> czyli dowodzi, że konto jest jego, czynnością, a nie twierdzeniem. Wejście
> Facebookiem dla kogoś NIEZALOGOWANEGO wolno wtedy potraktować tylko dwoma
> sposobami: rozpoznaniem po `identyfikator` (powiązanie już istnieje) albo
> założeniem NOWEGO konta z adresem **niepotwierdzonym**, jak przy zwykłej
> rejestracji hasłem. Adres z Facebooka nie ma prawa nigdy trafić do bazy
> jako potwierdzony.
>
> Dlatego `'facebook'` NIE JEST dziś na liście CHECK-a. Baza ma odbić
> `INSERT` z tą nazwą, dopóki ktoś nie napisze migracji — a napisanie
> migracji zmusza do przeczytania tego akapitu.

### Czego ta decyzja świadomie NIE robi

- **Nie dokłada ekranu „połącz / odłącz" w ustawieniach.** D-069 odłożyło go
  świadomie i to zostaje (`AGENTS.md` §3, jedna funkcja na raz). Uwaga na
  wtedy jest w D-069 i nadal obowiązuje: odłączenie konta, które nie ma
  innej drogi wejścia, musi odmawiać albo najpierw poprosić o hasło.
- **Nie implementuje Facebooka.** Ta decyzja jest o modelu danych, w który
  Facebook wejdzie bez migracji przenoszącej dane — i o jednym zdaniu wyżej,
  którego nie wolno przy tym pominąć.
- **Nie rusza `laravel/socialite`.** Próg powrotu z D-069 („drugi dostawca")
  jest formalnie przekroczony, ale to jest osobne rozstrzygnięcie i osobny
  PR: dziś nie ma jeszcze ani jednej linijki kodu Facebooka, więc nie ma
  czego porównywać. Kto będzie pisał Facebooka, ma ten próg przeczytać
  w D-069 i rozstrzygnąć jawnie.

📄 `database/migrations/2026_09_10_500000_create_tozsamosci_zewnetrzne_table.php` ·
`app/Models/TozsamoscZewnetrzna.php` ·
`app/Models/User.php` (`tozsamosciZewnetrzne()`, `connectGoogle()`,
`hasGoogleConnected()`, `findByGoogleSub()`) ·
`app/Domain/Users/Actions/EraseAccountData.php` ·
`app/Http/Controllers/Auth/GoogleLoginController.php` (`link()` pod `ZamekKonta`) ·
`docs/DATABASE.md` (`tozsamosci_zewnetrzne`) ·
`docs/infra/DEPLOYMENT_RUNBOOK.md` krok 8D ·
`tests/Feature/LogowanieKontemGoogleTest.php` ·
`tests/Feature/CofniecieMigracjiGoogleOdmawiaTest.php` ·
issue #258, D-069, D-079 (`ZamekKonta`), D-022 (anonimizacja)

---

## D-104 · Tabela stacku w `AGENTS.md` §3 opisuje STAN, ma trzecią kolumnę „Gdzie to sprawdzić" i jest sprawdzana testem

**Data:** 10 września 2026 · Status: **obowiązuje**

> **Ta decyzja NIE dotyczy Sentry.** Sprostowanie nieprawdziwego wiersza
> („Monitoring | Sentry" przy braku Sentry) jest poprawką faktu i numeru nie
> wymaga — decyzja o samym Sentry stoi w **D-041** i nie zmienia się tutaj ani
> o słowo. Numer bierze **zmiana kształtu tabeli**: od dziś każdy wiersz musi
> powiedzieć, gdzie tej rzeczy szukać, a test to sprawdza. To jest obowiązek
> nałożony na każdego, kto tę tabelę zmienia, więc ma prawo do wpisu
> w dzienniku — inaczej pierwsza osoba, której trzecia kolumna wyda się
> nadmiarem, skasuje ją jako ozdobę.

### Co było złamane

Tabela w `AGENTS.md` §3 nosi nagłówek „Stack (i czego NIE wolno dokładać)"
i jest czytana jako opis tego, **co jest**. Wymieniała jednak
`| Monitoring | Sentry |`, przy czym Sentry'ego w projekcie nie ma i nigdy nie
było. Zmierzone 10 września 2026 na `main`: `grep -c -i sentry composer.json`
→ **0**, brak `config/sentry.php`, a `SENTRY_LARAVEL_DSN` — przewleczone przez
`.env.example`, `.railway/railway.ts` i **oba** workflow CI — **nie jest czytane
przez ani jedną linijkę PHP**. Ta sama nieprawda stała w drugiej kopii tabeli,
w `README.md` §Stack.

**Kosztowało to cudzą pracę, i to nie hipotetycznie.** Autor PR-a #253 zbudował
całe zdanie „właściciel ma szansę dowiedzieć się o awarii bez zaglądania" na
założeniu, że `Log::error()` dojdzie do Sentry. Nie dochodzi: Sentry'ego nie
ma, a kanał `blad_webhook` nie jest częścią domyślnego stosu
(`LOG_STACK=single`) i wisi na `$exceptions->report()` w `bootstrap/app.php` —
czyli na wyjątkach, nie na dowolnym `Log::error()`. Ktoś inny musiał to
sprostować w trzech plikach i we wpisie D-062.

To jest **ta sama klasa błędu, co martwy numer decyzji**
(`tests/Feature/NumeryDecyzjiMajaWpisyTest.php`), tylko pod inną nazwą:
**deklaracja wygląda na rozstrzygnięcie, więc zatrzymuje szukanie — a jest
nieprawdziwa.** Trzeci raz tego samego jednego dnia.

### Co wybrano

**1. Wiersz opisuje stan dzisiejszy, zamiar idzie do roadmapy i dziennika.**
Wiersz `Monitoring` brzmi teraz „dziennik serwera + kanał `blad_webhook` na
Slack/Discord (D-041)", bo to jest to, co naprawdę działa. Sentry jako wybór
docelowy nie znika — stoi w `docs/ROADMAP.md` §0 i w D-041, razem z wypisanym
warunkiem wejścia. Rozważano wariant drugi (wiersz zostaje z dopiskiem
„zamiar, nie stan") i został **odrzucony**: tabela z nagłówkiem „Stack" jest
czytana skrótem, a dopisek przy jednym wierszu ginie dokładnie tak samo, jak
zginął fakt, że Sentry'ego nie ma.

**2. Trzecia kolumna „Gdzie to sprawdzić", z zamkniętą listą czterech
kształtów** (`composer.json`: pakiet · `package.json`: pakiet ·
w repozytorium: ścieżka · usługa zewnętrzna). Kształt piąty oblewa test.

**Dlaczego kolumna, a nie lista wyjątków w teście.** Naturalne „każda nazwa
z tabeli musi być w `composer.json`" nie działa, bo większość tej tabeli
**słusznie** nie jest pakietem Composera: PostgreSQL, Railway, Cloudflare, R2
i PWA to usługi i platformy, a Tailwind jest pakietem npm. Lista dozwolonych
wyjątków rosłaby przy każdej zmianie tabeli i **sama stałaby się kolejną
deklaracją, która się rozjedzie** — czyli tą chorobą, którą leczy. Kolumna
odwraca ciężar dowodu: nowy wiersz nie wejdzie do tabeli bez odpowiedzi na
pytanie „a gdzie to jest", i odpowiada na nie ten, kto go dopisuje.

**3. `README.md` §Stack ma powtarzać pierwsze dwie kolumny CO DO ZNAKU.**
Druga kopia tabeli była drugim egzemplarzem tej samej nieprawdy. Zamiast
dublować w README lokalizatory (dwa miejsca do utrzymania, czyli ten sam
problem w mniejszej skali) test wymaga, żeby kopia zgadzała się z oryginałem
znak w znak. Skutkiem ubocznym README przestał skracać trzy wiersze po swojemu
— i dobrze, bo skrót w kopii jest pierwszym krokiem rozjazdu.

### Co ustalono o `SENTRY_LARAVEL_DSN` — i dlaczego NIE jest usuwane

Zmienna jest **martwa jako kod**: nie czyta jej żadna linijka PHP; jest tylko
*ustawiana* (`.railway/railway.ts`, `ci.yml` ×2) i *deklarowana*
(`.env.example`). Mimo to **nie usuwamy jej**, i to jest świadome:

- usunięcie z `.env.example` i CI, a pozostawienie w `.railway/railway.ts`,
  w `docs/infra/DEPLOYMENT_RUNBOOK.md` (kroki 4 i 20), `MONITORING_BLEDOW.md`,
  `INFRA_DECISION.md` i `KOPIE_I_ODTWORZENIE.md`, zamieniłoby jedną nieprawdę
  na drugą — konfigurację niekompletną wobec własnych instrukcji wdrożenia;
- `tests/Feature/KopiaBazyPozaRailwayemTest.php` używa tej nazwy jako
  przykładu sekretu, który nie ma prawa wyciec do zrzutu bazy;
- audyt D3-03 (`docs/AUDYT_2026-09.md`) zbadał dokładnie ten przypadek
  i zakwalifikował go jako „nieużywane, bo **przygotowane**", nie jako śmieć;
- D-041 wprost popiera to przygotowanie.

Zamiast usunięcia zmienna dostała w `.env.example` zdanie mówiące wprost, że
nic nie robi i że wpisanie tam DSN-u niczego nie włączy. **Skasowanie całej tej
instalacji jest osobną decyzją właściciela, nie sprostowaniem faktu** — i gdyby
zapadła, obejmuje wszystkie osiem miejsc naraz, nie trzy.

### Czego ta decyzja świadomie NIE rozstrzyga

- **Czy „usługa zewnętrzna" jest prawdą.** Z repozytorium nie da się sprawdzić,
  czy ktoś ma konto na Cloudflare. Wpisanie tego przy pakiecie PHP test
  przepuści — ale tabela kłamałaby wtedy JAWNIE, w jednym widocznym wierszu,
  zamiast po cichu przez zwykłą nazwę w kolumnie „Wybór". Żeby nie dało się
  uciszyć testu przepisaniem całej tabeli na tę wartość, test wymaga minimalnej
  liczby wierszy sprawdzalnych w repozytorium.
- **Wersji.** „Laravel 13" wobec `^13.0` nikt tu nie porównuje. Rozjazd wersji
  jest realny, ale to inna usterka i inna naprawa.
- **Losu samego Sentry.** To jest D-041 i nic tu się w nim nie zmienia. Warto
  jednak zapisać, że warunek wejścia z D-041 („działający `composer install`
  w środowisku pracy") jest dziś częściowo nieaktualny: `AGENTS.md` §10 opisuje
  działające obejście (`composer install --prefer-source`). Rozstrzygnięcie tego
  należy do D-041, nie tutaj.
- **Zdania w D-041, które odsyła po wybór docelowy „do tabeli w `AGENTS.md` §3".**
  Po tej zmianie tabela mówi o stanie, więc tamten odnośnik prowadzi już tylko
  do wiersza o webhooku. Wpisu D-041 nie przepisujemy — dziennik jest
  append-only — ale kto go czyta, ma wiedzieć, że wybór docelowy stoi w samym
  D-041 i w `docs/ROADMAP.md` §0.
- **Numeracji `docs/design/system-v3.1/`.** Tamten katalog prowadzi WŁASNY
  dziennik `D-1NN` i ma swoje D-104 („karta wpisu dostaje wariant zwarty").
  To dwie niezależne numeracje o tym samym kształcie; kolizja jest znana
  i opisana przy `OBCA_NUMERACJA` w `tests/Feature/NumeryDecyzjiMajaWpisyTest.php`.

### Jak to wycofać

Skasować `tests/Feature/TabelaStackuMowiPrawdeTest.php` i trzecią kolumnę
tabeli. Nic od tego nie zależy w kodzie produkcyjnym — cena wycofania to powrót
do stanu, w którym tabela może obiecywać rzeczy, których nie ma, i nikt tego
nie zauważy przez trzy tygodnie.

**Zmiana wymaga:** wskazania innego mechanizmu, który sprawdza tabelę
**maszynowo**. Powrót do „pilnujemy tego uważnością" nie jest zmianą tej
decyzji — jest powrotem do stanu, który już raz zawiódł, i to trzy razy
w jeden dzień.

📄 `AGENTS.md` §3 · `README.md` §Stack ·
`tests/Feature/TabelaStackuMowiPrawdeTest.php` · `docs/ROADMAP.md` §0 ·
`.env.example` (`SENTRY_LARAVEL_DSN`) · `app/Support/Wersja.php` ·
`config/kuking.php` (`kuking.wersja.commit`) ·
`resources/views/components/layout.blade.php` (stopka z wersją) ·
`app/Poczta/OdmowaEmailLabs.php` · `app/Console/Commands/BramkaR2.php` ·
issue #33, D-041, D-062, `docs/PULAPKI_TESTOW.md` §2
## D-103 · Cztery ostatnie drogi zdjęcia idą pod tę samą blokadę co wpis — a awatar dostaje osobne rozwiązanie na ODPINANIE

**Issue:** #285 (MEDIA-01, P1). **Data:** 10.09.2026.
**Stoi na:** D-083 (jedna blokada wiersza `media`, pliki po commicie),
D-079 (jedna kolejność blokad + rewalidacja POD blokadą).

> **Adnotacja z 20 września 2026 (audyt rejestru).** Ten wpis domyka D-083,
> ale nie postawił tam adnotacji — została dopisana 20 września 2026 przy
> D-083. Sam D-103 ma pokrycie: cztery drogi są zamknięte
> (`app/Domain/Recipes/Actions/PublishRecipe.php:227` przed
> `Recipe::create()`, `app/Domain/Media/Actions/PrzypnijAwatar.php:149`,
> odpinanie awatara atomowym `UPDATE … WHERE` w
> `app/Http/Controllers/Settings/AvatarSettingsController.php:158`).

To jest **wykonanie D-083**, nie nowa decyzja o mechanizmie — sekcja „Co
zostaje otwarte" tamtego wpisu wskazuje dokładnie te cztery drogi. Numer jest
tu potrzebny z jednego powodu: **jedna z nich nie dała się zamknąć wzorcem**
i została rozwiązana inaczej (§3 niżej). Reszta to skopiowanie protokołu,
który już stoi.

### Stan sprzed zmiany — sprawdzony w plikach

- `AvatarSettingsController::update()` — `$profile->update(['avatar_media_id' => …])`
  bez transakcji i bez blokady wiersza `media`.
- `PublishRecipe::handle()` — `hero_media_id` i `source_scan_media_id`
  wkładane prosto z `$attributes` do `$payload`.
- `PublishRecipe::stepMediaId()` — zwracało `media_id` po sprawdzeniu samej
  WŁASNOŚCI (`exists()`, bez blokady).

Wszystkie cztery kolumny mają `nullOnDelete()` (migracje
`2026_09_05_000200_create_profiles_table` i `2026_09_05_000400_create_recipes_tables`),
więc skasowanie wiersza `media` przez sprzątacza **nie zgłaszało konfliktu
klucza obcego** — po cichu zerowało kolumnę. Objaw jest ten sam co przy
`post_media` z `cascadeOnDelete()` opisanym w D-083: ani wyjątku, ani wpisu
w logu. Zostaje profil albo przepis wskazujący na nic, a jedyny egzemplarz
zdjęcia człowieka nie istnieje już nigdzie.

### 1. Trzy drogi przepisu — jedno wywołanie `zablokuj()`, przed `INSERT`-em

`recipes.hero_media_id`, `recipes.source_scan_media_id` i `recipe_steps.media_id`
kończą się w tej samej akcji, więc blokada jest **jedna, na wszystkie zdjęcia
tego zapisu naraz**. Trzy osobne wywołania blokowałyby w trzech grupach, a dwa
równoległe zapisy, w których to samo zdjęcie raz jest główne, a raz stoi przy
kroku, zakleszczyłyby się nawzajem. `zablokuj()` sortuje po `id`, więc jedno
zapytanie daje jedną, globalnie deterministyczną kolejność.

Lista kandydatów obejmuje też zdjęcia **przenoszone**: główne i skan
przepisywane przez `RecipeController::update()` z poprzedniego stanu oraz
zdjęcia kroków dziedziczone po tożsamości. Kusi, żeby ich nie blokować —
przecież są już przypięte. Ale `syncSteps()` **kasuje wiersze kroków i tworzy
je od nowa**, więc odwołanie do zdjęcia kroku przestaje i zaczyna istnieć
w tej samej transakcji: zwykła edycja tytułu przepisu byłaby oknem na
skasowanie zdjęcia, które od miesięcy wisi przy kroku.

Mapa tożsamości kroków (`$istniejaceKroki`) przeniosła się z `syncSteps()` do
`handle()`, bo lista do zablokowania musi być gotowa **przed** zapisem wiersza
przepisu. Odczyt jest teraz jeden zamiast dwóch; dwa odczyty tej samej rzeczy
w jednej transakcji to dwie okazje, żeby się rozjechały.

**Zdjęcie, którego nie udało się zablokować, wypada — przepis zapisuje się bez
niego.** Tak samo jak wpis w D-083 i z tego samego powodu: zdjęcie wypada
z listy tylko wtedy, gdy sprzątacz już je przejął, czyli nie z winy człowieka,
który właśnie zapisuje przepis. Odmowa zapisu zabrałaby mu wszystko, co
wpisał, żeby ukarać go za cudze sprzątanie.

`exists()` w `stepMediaId()` **zostaje** mimo blokady pytającej o to samo
(D-079 §4). Ono służy KOMUNIKATOWI, nie gwarancji, i te dwie odpowiedzi znaczą
co innego: „to nie jest Twoje zdjęcie" jest pomyłką do poprawienia, a „to
zdjęcie właśnie odchodzi" nie jest niczyją pomyłką.

### 2. Kolejność blokad — `media` przed `users`, i to jest ZMIERZONE

D-083 przyjęło z dokumentacji, że `SELECT … FOR UPDATE` zderza się z blokadą,
którą PostgreSQL bierze sam przy sprawdzaniu klucza obcego, i zapisało wprost,
że **nie jest to sprawdzone maszynowo**. Sprawdzone zostało teraz — dwiema
sesjami `psql` na PostgreSQL 18, z `statement_timeout` jako miarą „czeka /
nie czeka":

| sesja 1 trzyma | operacja sesji 2 | wynik |
|---|---|---|
| `users FOR UPDATE` | `UPDATE profiles SET avatar_media_id = …` | **przechodzi** |
| `media FOR UPDATE` | `UPDATE profiles SET avatar_media_id = …` | **czeka** |
| `media FOR KEY SHARE` | `UPDATE profiles SET avatar_media_id = …` | **przechodzi** |
| `users FOR UPDATE` | `INSERT INTO recipes (…)` | **czeka** |
| `media FOR UPDATE` | `INSERT INTO recipes (…)` | **czeka** |

Z tego wynikają dwie rzeczy:

1. **Blokada zdjęć musi stać przed `Recipe::create()`.** `INSERT INTO recipes`
   bierze i wiersz `users` (klucz obcy `author_id`), i wiersz `media` (klucz
   obcy `hero_media_id`), więc kolejność wychodzi `media` → `users` — ta sama,
   którą po D-083 biorą `PublishPost` i `RecordCookedEvent`.
2. **Awatar NIE wchodzi przez `ZamekKonta`.** D-075 ogłasza „konto najpierw"
   i pierwszy odruch każe je tu zastosować. Pomiar mówi, że zapis awatara
   **nie dotyka wiersza `users`** — klucz obcy `profiles.user_id` się nie
   zmienia, więc silnik pomija jego sprawdzenie. Wciągnięcie tej drogi pod
   `ZamekKonta` dołożyłoby `users FOR UPDATE` PRZED wierszem `media`, czyli
   **drugą kolejność blokad w repozytorium** — dokładnie tę rodzinę usterek,
   którą tego samego dnia naprawiały D-093 i PR #311.

„Konto najpierw" obowiązuje tam, gdzie operacja konta naprawdę dotyka —
nie jest zaklęciem do dopisania wszędzie, gdzie w pobliżu stoi użytkownik.

### 3. Awatar: odmienność, której wzorzec z D-083 NIE zamyka

Issue przewiduje, że awatar może się wyłamać, bo **zastępuje** poprzednie
zdjęcie zamiast dokładać nowe. Sprawdzone: samo zastąpienie wzorca nie łamie.
Stare zdjęcie przestaje być używane w chwili commitu, ale sprzątacz pyta „czy
używane" pod blokadą TEGO starego wiersza i czyta `profiles` ponownie — przed
naszym commitem widzi „używane", po commicie „nieużywane". Obie odpowiedzi są
prawdziwe w chwili, w której padają. Stare zdjęcie ma zniknąć; po to człowiek
wgrał nowe.

Wyłamuje się co innego, o czym issue nie mówi: **odpinanie**.
`AvatarSettingsController::destroy()` czytał `$profile->avatar`, a potem zerował
kolumnę bezwarunkowo — czyli ufał modelowi podanemu z zewnątrz (D-079 §3):

1. karta A otwiera „Zdjęcie profilowe" i czyta awatar = X;
2. karta B wgrywa nowe zdjęcie Y — kolumna wskazuje już Y;
3. karta A klika „Usuń zdjęcie" i zapisuje `NULL`, **odpinając Y**.

Y zostaje bez odwołania, więc po dobie karencji zabiera je sprzątacz razem
z plikami. Człowiek traci zdjęcie, którego nie usuwał, i nie dostaje o tym ani
jednego zdania. W grupie 50+ dwie otwarte karty tej samej strony to nie
przypadek brzegowy, tylko sposób obsługi komputera.

**Rozwiązane jednym zdaniem SQL, nie blokadą.** `PrzypnijAwatar::odepnij()`
zeruje kolumnę warunkiem `avatar_media_id = <to zdjęcie>` i patrzy, ile wierszy
zmieniła; zero znaczy „profil wskazuje już na co innego" i wtedy nie kasujemy
ani wiersza, ani plików, tylko mówimy o tym człowiekowi. Gwarancję daje wtedy
atomowy `UPDATE … WHERE` (D-079 §4), a nie `exists()` w PHP — i nie dokłada
blokady, której inne drogi na `profiles` nie biorą, więc nie ma jak ustawić się
w kolejce w innej kolejności niż one.

**Przypięcie awatara odmawia WYJĄTKIEM, nie cichym pominięciem** — i tym różni
się od trzech pozostałych dróg. Przy wpisie i przy przepisie zniknięcie jednego
zdjęcia zostawia całą treść, którą człowiek wpisał. Ten ekran ma jedno pole
i jedną czynność: „Zdjęcie zapisane." przy niezmienionym zdjęciu byłoby
kłamstwem — tym samym, które ten formularz już raz naprawiał (`required` przy
pustym pliku).

Reguła wyszła przy okazji z kontrolera do `App\Domain\Media\Actions\PrzypnijAwatar`
(`AGENTS.md` §4): dopóki stała w kontrolerze, drugi endpoint na to samo pole
zaczynałby od zera.

### Czego świadomie NIE zrobiono

- **Żadnej migracji ani zmiany schematu.** Znacznik `status = 'deleted'`
  wprowadzony w D-083 wystarcza; `media_status_check` dopuszcza tę wartość od
  pierwszej migracji tabeli.
- **Nie ruszono `KasujZdjecie` ani `OsieroconeZdjecia`.** Strona sprzątająca
  została domknięta w D-083 i nic w niej nie brakuje — brakowało wyłącznie
  drugiej strony sekcji krytycznej.
- **Nie dodano `zablokujJedno()`** ani innego cukru na `ZdjeciaDoPrzypiecia`.
  Jedno miejsce blokuje listę, drugie jeden element z listy — druga metoda
  robiłaby to samo pod inną nazwą, a metoda z jednym wywołującym to API
  wymyślone na zapas.
- **Nie odtworzono przeplotu na dwóch połączeniach.** Ograniczenie jest to samo
  co w D-083 i wynika z `RefreshDatabase` (`docs/PULAPKI_TESTOW.md`, pułapka 6);
  nowe jest tylko to, że sama serializacja blokad przestała być założeniem
  i jest zmierzona (tabela w §2).

### Czego test NIE pilnuje

`tests/Feature/CzteryDrogiZdjeciaPodBlokadaTest.php` sprawdza **kontrakt**, po
jednym teście na drogę, każdy z własną kontrolą dodatnią w tej samej kolumnie.
Rozdzielność jest sprawdzona kontrolą ujemną: zepsucie jednej drogi oblewa
dokładnie jeden test (tabelka w opisie PR).

Nie jest pilnowany strażnik `DB::transactionLevel() === 0` w
`ZdjeciaDoPrzypiecia` — pod `RefreshDatabase` poziom transakcji nigdy nie jest
zerem. Nie jest też pilnowany pomiar z §2: to własność silnika zmierzona raz,
poza zestawem testów, a nie zachowanie naszego kodu.

📄 `app/Domain/Media/Actions/PrzypnijAwatar.php` ·
`app/Domain/Recipes/Actions/PublishRecipe.php` ·
`app/Http/Controllers/Settings/AvatarSettingsController.php` ·
`app/Domain/Media/ZdjeciaDoPrzypiecia.php` (bez zmian, wzorzec) ·
`tests/Feature/CzteryDrogiZdjeciaPodBlokadaTest.php` ·
`tests/Feature/ZdjecieNieZnikaPrzyPrzypinaniuTest.php` ·
issue #285, D-083, D-079, D-075, D-093

---

## D-105 · Grupa testów `dwa-polaczenia`: osobna baza, osobne procesy, osobny przebieg — i wyłączona ze zwykłego `php artisan test`

**Data:** 11 września 2026 · Audyt kolejności blokad, rozdział 7
(`docs/research/2026-09-10-kolejnosc-blokad.md`) · issue #314 · Status: **obowiązuje**

### Co było złamane

**Żaden z 2854 testów tego repozytorium nie chodził na dwóch połączeniach do
PostgreSQL.** `RefreshDatabase` opakowuje każdy test w transakcję, której nigdy
nie zatwierdza, więc drugie połączenie nie widzi ani jednego wiersza
pierwszego. Skutek jest strukturalny, a nie drobny: **zakleszczenia
i odwrócone kolejności blokad są dla całego zestawu niewidzialne**
(`docs/PULAPKI_TESTOW.md` §6).

Nie jest to problem teoretyczny. 10.09.2026 naprawiono dwa zakleszczenia z tej
rodziny — Z-2 (kasowanie konta, D-093) i Z-3 (zużycie tokenu linku do
logowania) — i **obaj agenci napisali wprost, że ich testy tego nie dowodzą**:

> Nie pilnuje braku `40P01` przy prawdziwej równoległości. Że z (1) i (2) cyklu
> nie da się zbudować, to rozumowanie, nie pomiar.

Oba zakleszczenia zmierzono **ręcznie, poza zestawem testów**. Przy każdym
następnym mechanizmie blokowania trzeba by to powtarzać; dziś takich
mechanizmów jest siedem.

### Decyzja

Powstaje osobna grupa testów `dwa-polaczenia` — katalog `tests/Dwa/`, klasa
bazowa `Tests\Dwa\TestDwochPolaczen`, trzy testy startowe i jedno polecenie
`./scripts/testy-dwa-polaczenia.sh`.

**Sześć zasad, bez których to nie mierzy niczego** (każda jest osobną
pułapką, każda ma odpowiadający jej bezpiecznik w kodzie):

1. **Bez `RefreshDatabase`** — dane są zatwierdzane naprawdę.
2. **Własna baza na przebieg**: `kuking_race_<worktree>`, liczona
   `kuking_nazwa_bazy_wyscigow()` w `tests/bootstrap.php` — TĄ SAMĄ metodą, co
   nazwa bazy testowej, żeby w repozytorium nie było dwóch reguł nazywania
   baz. Klasa bazowa **odmawia startu**, gdy połączenie nie wskazuje na
   `kuking_race*`; ten sam bezpiecznik jest osobno w procesie potomnym, bo to
   on wykonuje `EraseAccountData`.
3. **Sprzątanie po identyfikatorach z testu**, nie `truncate` całych tabel.
4. **Twardy `lock_timeout`, `statement_timeout`
   i `idle_in_transaction_session_timeout` na KAŻDYM połączeniu**, łącznie
   z połączeniami procesów potomnych.
5. **Naprawdę osobne połączenia**: osobne instancje `PDO`
   (bez `ATTR_PERSISTENT`) i osobne PROCESY uczestników wyścigu.
6. **Kontrola pozytywna w każdym teście**: `setUp()` sprawdza, że dwa
   połączenia to dwa różne backendy (`pg_backend_pid()`) I że blokada
   z jednego jest widziana z drugiego; każdy test dokłada własną asercję na
   to, że mierzona operacja naprawdę się wykonała.

**Grupa jest wyłączona ze zwykłego przebiegu** (`<groups><exclude>`
w `phpunit.xml`). `php artisan test` ma po tej zmianie **2854 testy, tyle samo
co przed** — zmierzone przed i po. Uruchamia się ją osobno, osobnym zadaniem
w CI, i jej czerwień **nie blokuje** pracy nad niepowiązaną zmianą.

### Zestaw startowy i to, że każdy z trzech BYŁ czerwony

Rozdział 7 zakładał, że `KasowanieKontaNieZakleszczaSieTest` będzie czerwony
od razu (Z-2 nienaprawione). Po D-093 wszystkie trzy są zielone — a **zielony
test, który nigdy nie był czerwony, nie jest dowodem niczego**. Dlatego każdy
został pokazany czerwonym przeciwko kodowi bez zabezpieczenia:

| Test | Sabotaż | Wynik |
|---|---|---|
| `KasowanieKontaNieZakleszczaSieTest` | `EraseAccountData`: powrót do dwóch hurtowych `detach()` w kolejności ról (stan sprzed D-093) | **czerwony**: `SQLSTATE[40P01] deadlock detected … while deleting tuple (0,7) in relation "follows"` |
| `ZablokujIObserwujNieZakleszczajaSieTest` | `BlockUser`: powrót do `DB::transaction()` zamiast `ZamekPary` (stan sprzed D-090) | **czerwony**: `SQLSTATE[40P01] deadlock detected … while locking tuple (0,70) in relation "users"`, w `SELECT … FOR KEY SHARE` z klucza obcego przy `insert into blocks` |
| `WyzwalaczFollowsWidziZatwierdzonaBlokadeTest` | `DROP TRIGGER follows_blokada_ma_pierwszenstwo_trg`, osobno każda gałąź `OR` w funkcji wyzwalacza | **czerwony** na obu gałęziach: „Bariera przepuściła obserwowanie mimo ZATWIERDZONEJ blokady" |

Trzeci sabotaż **nie daje zakleszczenia i nie ma dawać**: ten test mierzy
widoczność zatwierdzonego stanu między dwoma backendami, a nie kolejność
blokad.

### Dlaczego uczestnikami są PROCESY, a nie drugie połączenie w tym samym PHP

Bo w jednym procesie PHP nie da się zatrzymać `EraseAccountData::handle()`
w połowie i puścić w tym czasie drugiej egzekucji — kod jest synchroniczny.
Zostawałoby przepisanie jego zapytań do testu i odegranie ich ręcznie, czyli
test mierzący SQL napisany w teście: zielony także po zmianie kodu, którego
pilnuje. To jest definicja atrapy z `AGENTS.md` §10.

Zatrzymaniem uczestnika w wybranym miejscu zajmuje się **bariera** — wiersz
trzymany pod `FOR UPDATE` na osobnym połączeniu. Tym, że uczestnik NAPRAWDĘ
już stoi w kolejce, zajmuje się `czekajNaZablokowane()`, pytające
`pg_stat_activity` — **nie `sleep`**. Powtarzalność bierze się stąd, że kolejka
po blokadę w PostgreSQL jest obsługiwana w kolejności zgłoszeń: test ustawia
uczestników w znanej kolejności i dopiero potem zwalnia barierę.

### Cena — uczciwie, łącznie z utrzymaniem

- **Szkielet:** pół dnia (zgodnie z wyceną rozdziału 7). Klasa bazowa,
  uruchamianie procesów, bariery, limity czasu, sprzątanie.
- **Każdy kolejny test:** pół godziny do godziny, głównie na ustawienie
  przeplotu.
- **Czas przebiegu:** 4 testy w ~1,7 s, plus jednorazowe migracje bazy wyścigów.
- **Utrzymanie — koszt najwyższy i nazwany wprost:** testy na prawdziwej
  równoległości bywają niestabilne, **a niestabilny test w tym repozytorium
  jest gorszy niż jego brak, bo uczy ludzi ignorować czerwone**. Zmierzone przy
  zakładaniu grupy: **20 przebiegów pod rząd, 20 zielonych** (drugi pomiar,
  przy obciążonym kontenerze: patrz opis PR). To jest warunek wejścia, nie
  ciekawostka.
- **Koszt zaniechania:** każdy następny mechanizm blokowania trzeba mierzyć
  ręcznie, poza zestawem, i wynik zostaje w podsumowaniu sesji, a nie w CI.

### Gdy grupa zacznie migać

Kolejność działań jest ustalona z góry, żeby nikt nie musiał jej wymyślać pod
presją czerwonego CI:

1. **Nie „powtórzyć i jechać dalej".** Zapisz, KTÓRY test i z jakim
   komunikatem — `40P01` (zakleszczenie, czyli usterka kodu) to coś zupełnie
   innego niż `55P03`/`lock_timeout` albo „przeplot się nie ustawił"
   (usterka testu).
2. **Usterka testu → wyłącz TEN test**, zostaw resztę grupy, otwórz issue
   z komunikatem. Nie wyłączaj całej grupy i nie zwiększaj `SEKUNDY_NA_KOLEJKE`
   „żeby przestało migać" — to jest zamiana pomiaru na czekanie.
3. **Grupa miga jako całość → nie scalać jej do CI jako zadania blokującego**
   i powiedzieć to wprost, zamiast zostawiać migający alarm. Rozdział 7 mówi
   to samo i ta decyzja to powtarza: **wolimy nie mieć tego narzędzia, niż mieć
   je migające.**

### Czego ta decyzja NIE rozstrzyga

- **Nie dowodzi, że kod jest wolny od zakleszczeń w ogóle.** Każdy test
  odtwarza JEDEN przeplot — ten, który był zmierzoną usterką. Inny przeplot
  wymaga innego testu i to jest napisane w docblocku każdego z nich.
- **Nie zamyka Z-3** (zużycie tokenu linku do logowania kontra
  `wymienToken()`). Rozdział 7 wymienia go jako zadanie dodatkowe „jeśli
  szkielet stanie" — szkielet stanął, test nie powstał, zostaje w issue #314.
- **Nie zmienia niczego w kodzie produkcyjnym.** Żadnej migracji, żadnej
  zmiany schematu, żadnej zmiany w `app/`. Sabotaże z tabeli wyżej były
  tymczasowe i zostały cofnięte (`git diff` pusty).
- **Nie rusza `tests/bootstrap.php` poza dopisaniem jednej funkcji.** Ten plik
  liczy nazwę bazy dla WSZYSTKICH przebiegów w repozytorium; nowa funkcja
  niczego w środowisku nie ustawia i nie dotyka istniejącej ścieżki.

### Jak to wycofać

Skasować `tests/Dwa/`, `scripts/testy-dwa-polaczenia.sh`, zadanie
`dwa-polaczenia` z `.github/workflows/ci.yml`, wpis `<testsuite name="Dwa">`
i `<groups>` z `phpunit.xml` oraz `kuking_nazwa_bazy_wyscigow()`
z `tests/bootstrap.php`. Nic więcej — grupa nie ma zależności w kodzie
produkcyjnym, a bazy `kuking_race_*` usuwa się `dropdb`. Ceną wycofania jest
powrót do stanu, w którym „brak zakleszczenia" jest w tym repozytorium
rozumowaniem, a nie pomiarem.

**Zmiana wymaga:** zmierzonej niestabilności (patrz „Gdy grupa zacznie migać")
albo znalezienia sposobu na ten sam pomiar wewnątrz zwykłego przebiegu — czyli
bez `RefreshDatabase`, ale i bez osobnej bazy. Dziś taki sposób nie jest znany.

📄 `tests/Dwa/TestDwochPolaczen.php` ·
`tests/Dwa/ProcesRownolegly.php` ·
`tests/Dwa/bin/scenariusz.php` ·
`tests/Dwa/KasowanieKontaNieZakleszczaSieTest.php` ·
`tests/Dwa/ZablokujIObserwujNieZakleszczajaSieTest.php` ·
`tests/Dwa/WyzwalaczFollowsWidziZatwierdzonaBlokadeTest.php` ·
`scripts/testy-dwa-polaczenia.sh` · `phpunit.xml` · `tests/bootstrap.php` ·
`docs/PULAPKI_TESTOW.md` (pułapka 7) ·
`docs/research/2026-09-10-kolejnosc-blokad.md` §7 ·
issue #314, #66, D-079, D-080, D-090, D-093

---

## D-106 · Automat dostępności stawia serwer z DZIAŁAJĄCĄ pocztą — bo dwa ekrany odzyskania dostępu mają w stanie zapasowym nagłówek i dwa przyciski zamiast formularza

**Data:** 11 września 2026 · Spłata długu siedmiu stron publicznych z **D-099**
· Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Rdzeń obowiązuje
> (`MAIL_MAILER=smtp` dla procesu serwera,
> `przeszkodaWFormularzachOdzyskania()` kończąca kodem 1 —
> `scripts/dostepnosc.mjs:811,824,1449`). Nieaktualna jest sekcja „Czego ta
> decyzja NIE robi". Zdanie „**nie spłaca dwóch ekranów wejścia kontem
> Google** — `wejdz/google/domknij` i `wejdz/google/polacz` zostają długiem, a
> powód i sprawdzone drogi na skróty są wypisane przy nich w
> `PomiarDostepnosciObejmujeStronyPubliczneTest`" jest dziś nieprawdziwe w obu
> członach. Dług spłacił moduł OAuth (#345): oba adresy są mierzone
> (`scripts/fixtures/oauth-dostepnosc.mjs:8-13`), a job CI zbiera z tego
> artefakt (`.github/workflows/ci.yml:1535`). Odesłanie „po powód" prowadzi
> dziś donikąd: na liście `WYJATKI` w
> `tests/Feature/PomiarDostepnosciObejmujeStronyPubliczneTest.php:75-79` tych
> dwóch adresów już nie ma, a argumentacja została usunięta razem z długiem.
> Na granicy: wyliczenie „automat wykonuje **wyłącznie** żądania GET oraz trzy
> formularze logowania i włączenie 2FA" przestało być pełne — moduł OAuth
> przechodzi też przez formularze `…/polacz` i `…/domknij`.

### Co się okazało przy dopisywaniu siedmiu ekranów

D-099 zostawiło **siedem stron publicznych** na liście świadomych wyjątków
w `PomiarDostepnosciObejmujeStronyPubliczneTest` jako dług nazwany:
`o-kuking`, `pomoc`, `odwolanie` (gość), `nie-pamietam-hasla`,
`logowanie/link`, `logowanie/kod` i `cofnij-usuniecie-konta`. Ten PR dopisuje
je do `EKRANY`. Najważniejsza z nich jest `/nie-pamietam-hasla`: to droga,
którą człowiek wchodzi **dopiero wtedy, gdy mu coś nie wyszło**.

Dopisanie jej samo z siebie niczego jednak nie mierzyło. Ekran ma **dwa
stany**, rozstrzygane przez `App\Support\Poczta::dziala()` — czyli przez to,
czy Laravel w ogóle ma czym wysłać list:

Zmierzone 11 września przy 320 px, Chromium 153 — liczby są z pomiaru, nie
z oka:

| ekran | stan | węzłów w `<main>` | znaków tekstu | pól formularza |
|---|---|---|---|---|
| `/nie-pamietam-hasla` | poczta działa | 14 | 315 | **1** |
| `/nie-pamietam-hasla` | poczta nie działa | 6 | 257 | **0** |
| `/logowanie/link` | poczta działa | 21 | 891 | **1** |
| `/logowanie/link` | poczta nie działa | 6 | 271 | **0** |

W stanie „poczta działa" jest akapit, **karta z formularzem** (pole adresu
z etykietą i podpowiedzią, Turnstile, dwa przyciski) i bloki wyjaśnień pod
spodem. W stanie zapasowym — nagłówek, jedno zdanie „napisz do nas" i dwa
przyciski.

**Różnica w samej liczbie węzłów nie jest tu pointą** (osiem i piętnaście
węzłów to niewiele). Pointą jest ta ostatnia kolumna: w stanie zapasowym
**nie ma ani jednego pola formularza**, więc cała klasa rzeczy, których ten
automat pilnuje — etykieta pola, opis pod polem, nazwa dostępna przycisku
wysyłki, kontrast pola, rozmiar celu, zawijanie rzędu przycisków przy 320 px
— nie ma na czym zadziałać. Zielony wynik nad takim ekranem nie mówi nic
o formularzu, bo formularza tam nie było.

**I to właśnie wariant zapasowy widziałby każdy przebieg, wszędzie.** `.env`
deweloperski ma `MAIL_MAILER=log`, a job `dostepnosc` w `ci.yml` —
`MAIL_MAILER=array`; oba są dla `Poczta` niedostarczające. Formularza
odzyskania hasła nie oglądał więc dotąd ani jeden przebieg tego automatu,
w żadnym środowisku — a raport zapisałby dla tego ekranu „✓".

To jest dokładnie ta klasa fałszywej zieleni, przed którą ostrzega nagłówek
`scripts/dostepnosc.mjs` („pusty ekran przechodzi każdy test dostępności, nie
sprawdzając niczego") — tylko o jeden stopień dalej niż w D-099. Tam ekranu
na liście NIE BYŁO. Tu ekran jest na liście, wynik jest zielony, a mierzona
jest jego wersja bez tego, co może się zepsuć: bez etykiety pola, bez opisu
pod polem, bez rzędu przycisków zawijającego się przy 320 px.

### Decyzja

**1. Serwer stawiany przez automat dostaje sterownik poczty, który
dostarcza.** `podnies_serwer()` uruchamia `php artisan serve`
z `MAIL_MAILER=smtp` (stała `STEROWNIK_POCZTY_DO_POMIARU`), celującym
w `MAIL_HOST`/`MAIL_PORT` z konfiguracji.

Nie wysyła to ani jednego listu i nic nie osłabia. Automat wykonuje wyłącznie
żądania GET oraz trzy formularze logowania i włączenie 2FA — żadna z tych dróg
poczty nie rusza (`grep` po `Mail::`, `notify(` i `Notification::`
w `app/Http/Controllers` nie trafia w żaden z tych kontrolerów).
`EsmtpTransport` powstaje lokalnie i otwiera gniazdo dopiero przy pierwszym
liście, którego tu nie ma — to ta sama własność, na której stoi samo
`Poczta::dziala()`.

**2. Wejście w ten stan jest SPRAWDZANE, nie zakładane.** Zaraz po podniesieniu
serwera `przeszkodaWFormularzachOdzyskania()` pobiera oba adresy i wymaga
w odpowiedzi pola `name="email"`. Brak pola kończy przebieg **kodem 1
z nazwanym ekranem**, zamiast przepuścić pomiar wariantu zapasowego.

Niepowodzenie jest tu BŁĘDEM, nie pominięciem — z tego samego powodu co przy
przeliczonym układzie i przy sprawdzeniu korzenia dla czcionki 200% (D-099):
**pomiar w nieznanym stanie jest gorszy niż jego brak.** Bez tej kontroli
zmiana `Poczta::dziala()`, inny sterownik w środowisku albo zapamiętana
konfiguracja po cichu wracają do dwóch akapitów, a raport dalej pokazuje ✓ —
czyli usterkę nie do odróżnienia od poprawnego wyniku.

**3. Przy `ADRES=…` niczego nie przestawiamy.** Wtedy mierzymy cudzą instancję
w stanie, w jakim ją zastaliśmy; sprawdzenie zostaje i powie, w którym stanie
ona jest.

### Dlaczego to NIE jest sprzeczne z D-099, choć wygląda podobnie

D-099 mówi: „automat mierzy stronę W TYM STANIE, W KTÓRYM WYDAJE JĄ PRODUKT".
Ktoś czytający tamto zdanie dosłownie mógłby tu zarzucić odwrotność: przecież
dziś produkt poczty nie wysyła, więc stanem produktowym jest właśnie wariant
zapasowy.

Różnica jest w tym, czego tamto zdanie broniło. D-099 zabrania mierzyć stan,
**w którym nie jest żaden człowiek** — układ złapany w połowie przeliczania,
desktop z podwojonym tekstem, kolory czytane w trakcie przemalowania. Tu jest
odwrotnie: oba stany są prawdziwe i oba ktoś zobaczy, tylko jeden z nich ma
formularz, a drugi jest jego podzbiorem (akapit i dwa `.btn`, czyli komponenty
mierzone na kilkudziesięciu innych ekranach). Wybieramy ten, który niesie
ryzyko — a nie ten, który akurat wychodzi z domyślnej zmiennej środowiskowej.

Gdyby kiedyś wariant zapasowy dostał własny układ (nie akapit i dwa przyciski,
tylko coś swojego), ma wejść na listę jako **osobny ekran**, a nie zastąpić ten
z formularzem.

### Czego ta decyzja NIE robi

**Nie podnosi żadnego progu, nie wyłącza reguły i nie przenosi niczego do
„ostrzeżeń produktowych".** 23 ostrzeżenia „focus częściowo zasłonięty"
(25–75%) zostają tam, gdzie były. Zestaw kontrol zatrzymujących CI jest
niezmieniony i dalej pilnowany przez `PomiarDostepnosciKonczyKodemJedenTest`.

**Nie zmienia ani jednej linijki produktu.** Zero zmian w `app/`, w widokach
i w arkuszu stylów — siedem dopisanych ekranów przy pełnym przebiegu (4
warianty axe, 6 szerokości × 3 skale układu) dało **0 naruszeń, 0 przepełnień
w poziomie, 0 focusów zasłoniętych w 100%**. Zmienia się wyłącznie to, ile
serwis jest oglądany.

**Nie rusza konfiguracji poczty w `.env`, w `.env.example` ani w `ci.yml`.**
Sterownik jest podstawiany procesowi serwera na czas przebiegu i nigdzie nie
zostaje.

**Nie spłaca dwóch ekranów wejścia kontem Google.**
`wejdz/google/domknij` i `wejdz/google/polacz` zostają długiem — powód
i sprawdzone drogi na skróty są wypisane przy nich w
`PomiarDostepnosciObejmujeStronyPubliczneTest`. Krótko: obie wymagałyby albo
włazu pozwalającego zapisać do sesji tożsamość „potwierdzoną przez Google" bez
przejścia przez Google (a wiersz w `tozsamosci_zewnetrzne` JEST drogą wejścia
na konto — D-098), albo atrapy dostawcy tożsamości, bo wymiana kodu na token
idzie z serwera i Playwright nie ma czego przechwycić.

### Kontrola ujemna

| kontrola | sabotaż | wynik |
|---|---|---|
| kompletność listy `EKRANY` | skreślenie `nie-pamietam-hasla` z `EKRANY` | test OBLAŁ i wymienił `nie-pamietam-hasla` z nazwy |
| axe ogląda nowy ekran | `<img>` bez `alt` w `forgot-password.blade.php` | kod 1, `[critical] nie pamiętam hasła / jasny: image-alt` |
| pomiar układu ogląda nowy ekran | `<pre>` z długim ciągiem tamże | kod 1, `nie pamiętam hasła / 320 px: 880 px przy 320 px okna` |
| kontrola stanu poczty | `STEROWNIK_POCZTY_DO_POMIARU = 'log'` | kod 1 z nazwanym ekranem, przed pierwszym pomiarem |
| czwarty kontekst dla `/logowanie/kod` | usunięcie gałęzi `przedKodem2FA` | kod 1: „odesłał na /login" + „axe nie zbadał 1 z 39" |

Każdy sabotaż był przed uruchomieniem sprawdzony w pliku (`grep`), a po
kontroli cofnięty — `git diff` na widoku jest pusty.

### Jak to wycofać

Dwa niezależne kawałki. Skreślenie `MAIL_MAILER` ze środowiska procesu
w `podnies_serwer()` razem z `przeszkodaWFormularzachOdzyskania()` wraca do
mierzenia wariantu zapasowego obu ekranów. Skreślenie siedmiu pozycji
z `EKRANY` wraca do niepełnego pomiaru — i oblewa `PomiarDostepnosciObejmuje…`,
bo te siedem nie stoi już na liście wyjątków. Żadne z nich nie dotyka danych,
schematu ani wyglądu serwisu.

**Zmiana wymaga:** rozstrzygnięcia, który z dwóch stanów ekranu odzyskania
dostępu jest tym mierzonym — i napisania tego wprost, razem z odpowiedzią na
pytanie, kto ogląda drugi.

📄 `scripts/dostepnosc.mjs` ·
`tests/Feature/PomiarDostepnosciObejmujeStronyPubliczneTest.php` ·
`app/Support/Poczta.php` (czytane, nietknięte) ·
D-099 · D-056 · D-098 · D-022 · D-050

---

## D-107 · Minima dolnej belki są FIZYCZNE, nie typograficzne — przy czcionce przeglądarki 200% belka układa się poziomo i schodzi z 51% ekranu na 25%

**Data:** 11 września 2026 · Ciąg dalszy **D-082** (issue #295, PR #269) ·
Status: **obowiązuje**

### Co zostało po D-082

D-082 zamknęło naruszenie **WCAG 2.2 AA 2.4.11**: rezerwa
`--rezerwa-pod-belka` wyprowadza treść spod przypiętej belki, więc fokus
nigdzie nie ginie. Zostało to, czego rezerwa nie dotyka — **przypięta belka
przy czcionce przeglądarki 200% zajmuje połowę telefonu**. Zmierzone na
`/home` jako zalogowany, okno 320 × 740 px, korzeń 32 px, Chromium 153:
**376,2 px, czyli 50,8% ekranu.** Na treść zostawało 364 px z 740.

Rezerwa działa na tym, GDZIE LĄDUJE FOKUS. Nie zmienia tego, ile ekranu
belka zabiera na stałe — a przy 51% problemem nie jest już przewijanie,
tylko to, że nawigacja jest większa niż treść.

### Przyczyna: to nie była wysokość tekstu

Pozycja belki miała tam **120 px**, a jej zawartość — ikona 26 px, odstęp
2 px, wiersz podpisu 43,2 px — potrzebuje **71,2 px**. Różnicę robiły dwie
liczby zapisane w `rem`, przez co podwajały się razem z korzeniem:

| co | zapis | przy korzeniu 16 px | przy 32 px |
|---|---|---|---|
| minimum pozycji | `var(--control-height-touch)` | 60 px | **120 px** |
| kółko przy „Dodaj" | `3rem` | 48 px | **96 px** |

**Obie te liczby są fizyczne, nie typograficzne.** 60 px pozycji i 48 px
kółka istnieją dla palca i dla ekranu, a palec nie rośnie, gdy ktoś powiększy
czcionkę w przeglądarce — piksel CSS przy powiększeniu SAMEJ czcionki zostaje
tej samej wielkości (to nie jest zoom strony; różnicę opisuje komentarz przy
`Page.setFontSizes` w `scripts/dostepnosc.mjs`). Podwojone nie dawały ani
jednego czytelnego piksela w zamian.

Druga połowa przyczyny to układ kolumnowy: podpis POD ikoną sumuje w pionie
ikonę i wiersz tekstu, choć ikona w poziomie nic nie kosztuje — pozycja ma
i tak 160 px szerokości, bo tyle dyktuje sam podpis.

### Decyzja

Za progiem `max-width: 15rem` (ten sam, co rezerwa i odpięcie `.topbar` —
porównuje okno z KORZENIEM, więc znaczy „tekst jest duży w stosunku do
ekranu"):

* `.bottom-nav-item` układa się **poziomo** — ikona obok podpisu;
* minimum pozycji to **60 px w pikselach ekranu**, czyli dokładnie tyle, ile
  `--control-height-touch` daje na zwykłym telefonie — nie mniej, tylko bez
  podwajania;
* kółko przy „Dodaj" to **48 px**, czyli minimum celu dla palca z `AGENTS.md`
  §5 (cel kliknięcia jest większy: odnośnikiem jest cała pozycja, zmierzone
  160 × 60 px);
* **główna akcja dostaje własny wiersz** (`flex-basis: 100%`), bo przy dwóch
  pozycjach na wiersz zostaje jej 96 px na podpis, a „Dodaj" potrzebuje 105 —
  i reguła bazowa `overflow-wrap: anywhere` łamała to słowo gdziekolwiek. Na
  zrzucie sprzed tej linijki stało **„Doda / j"**.

Zmierzone po poprawce (`/home`, korzeń 32 px): **181 px, czyli 24,5% okna**,
pięć pozycji w trzech wierszach 2 + 1 + 2, każdy podpis w całości.

| wariant | przed | po |
|---|---|---|
| bez powiększania | 66,6 px (9,0%) | 66,6 px (9,0%) |
| nasze „tekst 140%" | 105,5 px (14,3%) | 105,5 px (14,3%) |
| czcionka przeglądarki 200% | 376,2 px (50,8%) | **181 px (24,5%)** |

### Czego ta decyzja NIE robi — to są zakazy z issue #295

Pismo podpisu zostaje przy `--text-body` (36 px przy tym korzeniu; minimum
produktowe 18 px, issue #110), pięć pozycji zostaje pięcioma, każda ikona
zostaje z podpisem, `flex-wrap: wrap` zostaje włączone (issue #80), a **próg
w `scripts/dostepnosc.mjs` nie został podniesiony ani jeden ekran nie wszedł
na listę wyjątków.** Dolna belka zostaje przypięta — jej odpięcie to zmiana
kierunku produktu (zabiera główną akcję z zasięgu kciuka) i wymaga osobnej
decyzji właściciela.

**Zwykły telefon nie zmienia się ani o piksel** — i to jest kontrola dodatnia
wpisana w sam mechanizm, a nie dołożona obok: próg `15rem` przy korzeniu
16 px odpowiada oknu 240 px, węższemu niż jakikolwiek telefon.

### Czym to jest pilnowane

Wysokości belki nie da się stwierdzić z CSS-a, więc mierzy ją
`scripts/dostepnosc.mjs` — nowa sekcja „Dolna belka", próg **1/3 okna**,
liczona przy tych samych trzech szerokościach i trzech skalach co fokus.
Przekroczenie zatrzymuje przebieg kodem 1; pomiar oblewa też wtedy, gdy belka
ma inną liczbę pozycji niż pięć, żeby „naprawa" przez okrojenie nawigacji nie
mogła zazielenić tej liczby. Wszystkie pomiary (nie tylko przekroczenia) idą
do `storage/dostepnosc.json` pod `wysokoscBelki`.

Job `dostepnosc` chodzi w CI warunkowo (tylko przy zmianie w `resources/`,
`public/`, `scripts/dostepnosc.mjs` albo w plikach npm), więc niezmienniki
widoczne w źródle pilnuje dodatkowo
`tests/Feature/BelkaPrzyDuzymTekscieTest.php` w jobie `test`, czyli zawsze:
układ poziomy za progiem, minimum w pikselach **zgodne z tokenem**
`--control-height-touch` (żeby te dwie liczby nie rozjechały się po cichu),
kółko 48 px, własny wiersz głównej akcji oraz — jako kontrola dodatnia —
belka bazowa bez zmian, pięć pozycji w HTML-u i podpis przy każdej ikonie.

### Jak to wycofać

Skreślenie dwóch bloków `@media (max-width: 15rem)` przy `.bottom-nav-item`
i `.bottom-nav-kolko` wraca do stanu sprzed poprawki. Oblewa wtedy
`BelkaPrzyDuzymTekscieTest` i sekcję „Dolna belka" w automacie. Nie dotyka
danych, schematu ani niczego, co widzi zwykły telefon.

**Zmiana wymaga:** zmierzenia belki tym automatem przy 320 px i czcionce
przeglądarki 200% — i podania liczby, a nie zrzutu z domyślnej czcionki.

📄 `resources/css/app.css` · `scripts/dostepnosc.mjs` ·
`tests/Feature/BelkaPrzyDuzymTekscieTest.php` · D-082 · D-099 · D-051

---

## D-113 · Adres e-mail z Facebooka nigdy nie wchodzi na istniejące konto — a właściciel tego konta dostaje POWIADOMIENIE, nie klucz

**Data:** 11 września 2026 · Rozstrzygnął właściciel · Ciąg dalszy **D-069**
i **D-098** (issue #259) · Status: **obowiązuje**

### Pytanie, które trzeba było rozstrzygnąć

Reguła 1 z **D-069** brzmi: `email_verified` od Google jest WARUNKIEM wejścia,
bez niego nie robimy nic. D-069 nazywa ten warunek najkrótszą znaną drogą
przejęcia konta przy „zaloguj się przez…".

**Facebook takiego pola nie ma.** Pełny opis pola `email` w Graph API to
*„The User's primary email address listed on their profile. This field will not
be returned if no valid email address is available."* — ani słowa
o potwierdzeniu. Reguły 1 nie da się dla Facebooka spełnić, więc trzeba ją było
czymś zastąpić, a to nie jest decyzja do podjęcia przy klawiaturze.

### Co zostało rozstrzygnięte

Słowami właściciela: *„trzeba i tak mu założyć to konto i ewentualnie wysłać
maila żeby potwierdził email i tyle"*.

1. **Nowa osoba** — konto powstaje, adres jest oznaczony jako
   **niepotwierdzony**, wychodzi nasz własny list z potwierdzeniem. Dokładnie
   tak jak przy rejestracji hasłem.
2. **Adres pasuje do istniejącego konta Kuking** — wejście **odmawia**
   i niczego nie łączy. Facebook nie dowiódł, że ta skrzynka należy do osoby
   siedzącej przed ekranem; wystarczyłoby wpisać cudzy adres w swoim koncie
   na Facebooku.
3. Dochodzi **list do właściciela konta**, bo odmowę widział wyłącznie ten, kto
   ją wywołał — właściciel nie dowiadywał się o próbie w ogóle.

### Dlaczego ten list nie ma odnośnika „to ja, połącz konta"

To jest najważniejsze zdanie tego wpisu. Taki odnośnik byłby wygodny i byłby
dziurą, przez którą przechodzi dokładnie ten atak, przed którym stoi odmowa:
obcy zakłada konto na Facebooku, wpisuje w nim cudzy adres, klika „Wejdź
kontem Facebooka" — i wtedy **my** wysyłamy właścicielowi wiarygodny list,
którym ten jednym kliknięciem oddaje obcemu wejście na swoje konto. Napastnik
nie musiałby nawet mieć dostępu do skrzynki; wystarczyłoby, żeby właściciel
kliknął.

**Żaden list nie niesie u nas uprawnienia do zmiany stanu konta.** Ta sama
granica co w `ZgloszonaZmianaAdresu` (issue #195), z tego samego powodu.
List mówi więc: zaloguj się jak zwykle i połącz konta w `Ustawienia →
Bezpieczeństwo`.

### Cena, którą płacimy — wypisana, żeby nie wyglądała na przeoczenie

Człowiek, który ma już konto w Kuking, **nie wejdzie na nie kontem Facebooka**,
dopóki sam nie połączy kont z ustawień. Dostanie odmowę. Zdanie na ekranie musi
więc mówić, CO ZROBIĆ — odmowa bez drogi wyjścia kończy się odejściem człowieka.

Runbook (§7.1) stawiał to jako wybór „albo–albo": albo adres nigdy nie łączy,
albo powiązanie powstaje wyłącznie z ustawień. **Kod robi jedno i drugie** —
wejście zakłada nowe konto, a ścieżka z ustawień istnieje dla tych, którzy
konto już mają. Dylemat był pozorny.

### Ograniczenie jednego listu na godzinę na konto

Bez niego ta funkcja jest zdalnym zalewaniem cudzej skrzynki: wystarczy
w kółko wracać na adres powrotu. Ogranicznik trasy liczy żądania **napastnika**
i jego nie boli — zalewana jest skrzynka **ofiary**, więc licznik stoi przy
koncie odbiorcy. Pominięcie listu jest niewidoczne z zewnątrz; gdyby było
widoczne, dałoby się nim sprawdzać, czy konto istnieje (**D-056**).

### Co musiałoby się stać, żeby to zmienić

Facebook musiałby zacząć oddawać potwierdzenie adresu — wtedy droga wraca do
reguły 3 z D-069 i wygląda jak przy Google. Albo: pomiar pokazałby, że odmowa
odbija ludzi masowo, a nie pojedynczo — wtedy szukamy trzeciej drogi, ale
**nie** przez list z odnośnikiem łączącym.

---

## D-114 · Obietnica z miarą wymaga pomiaru — inaczej jej nie piszemy

**Data:** 11 września 2026 · Audyt copy, `docs/research/audyt-copy-2026-09-11/`
· Status: **obowiązuje**

### Zasada

„Zajmuje minutę", „to najczęściej czytana część", „teraz idzie najszybciej" to
zdania o czasie, liczbie albo cudzym zachowaniu. **Wchodzą do interfejsu tylko
wtedy, gdy w repozytorium stoi mechanizm albo pomiar, który je pokrywa.**

### Dlaczego to nie jest czepianie się

Człowiek, któremu obiecano minutę, a dodawanie zdjęcia zajęło pięć — bo zasięg
był słaby — nie myśli „ładny copywriting". Myśli, że serwis nie mówi prawdy.
Przy grupie 50+, która i tak podchodzi do nowego serwisu ostrożnie, **jedna
niesprawdzalna obietnica kosztuje więcej niż dziesięć nudnych zdań.**

Żadnej z sześciu obietnic „minuty" nikt nie zmierzył. Nie zostały uznane za
fałszywe — zostały uznane za **niepokryte**, a to wystarczy, żeby ich nie pisać.

### Czego ta zasada NIE obejmuje

Liczb, które serwis naprawdę liczy: czasu gotowania z przepisu, okna na
poprawienie komentarza, terminu odwołania. Te mają pokrycie w kodzie i wolno
je pisać wprost.

### Gdzie to stoi

Pozycja listy kontrolnej w `docs/brand/COPY_STYLE.md` §7, pilnowana przez
`tests/Feature/TekstyMowiaPrawdeTest.php` (dziesięć miejsc, A1–A10). Każdy test
zawężony do elementu, bo słowo „minut" pada w serwisie także legalnie.

### Co musiałoby się stać, żeby to zmienić

Ktoś zmierzyłby któryś z tych czasów na prawdziwych kontach i prawdziwych
łączach. Wtedy obietnica wraca — z liczbą, która ma pokrycie.

---

## D-115 · Skala tekstu schodzi do 70% — bo ustawienie czytelności działające w jedną stronę jest ustawieniem połowicznym

**Data:** 11 września 2026 · Zgłosił i rozstrzygnął właściciel · Zastępuje
**system-v3.1 D-111** w zakresie dolnej granicy · Status: **obowiązuje**

### Co się zmienia

CHECK na `users.text_scale` przechodzi z `BETWEEN 90 AND 140` na
`BETWEEN 70 AND 140`. Dochodzą trzy rozmiary: 90% „Trochę mniejszy" (16,2 px),
80% „Mały" (14,4 px), 70% „Bardzo mały" (12,6 px).

**Domyślna skala zostaje 100%, czyli `--text-body` = 18 px.** Nic jej nie rusza.

### Dlaczego to nie łamie zasady „tekst ≥ 18 px"

Zasada z `AGENTS.md` opisuje, **co człowiek widzi, zanim czegokolwiek dotknie**
— czyli domyślny wygląd serwisu. Niżej schodzi wyłącznie ten, kto sam tak
ustawi, i tylko na swoim koncie.

Kuking jest robiony dla grupy 50+, ale „dla 50+" nie znaczy „nieczytelny dla
reszty". Zgłosił to właściciel — trzydziestokilkulatek czytający własny
produkt, dla którego 18 px jest za duże.

### Stosunek do system-v3.1 D-111

Tamten wpis mówi: *„`90%` istnieje dla osób, którym 18 px jest za duże na małym
telefonie. Schodzi do 16.2 px, czyli nigdy poniżej progu, który dla tekstu
podstawowego jest powszechnie przyjęty."* **To zostaje prawdą o 90% i przestaje
być dolną granicą.** Decyzja właściciela jest późniejsza i wygrywa.

Czego ta zmiana NIE rusza: górnej granicy ani tezy z system-v3.1 D-111, że
układ trzymamy do 150%. Zmniejszanie tekstu nie zagraża układowi — zagraża mu
powiększanie, a tam nic się nie zmieniło.

### Cztery miejsca, które muszą się zgadzać

`kuking.text.scales`, `resources/css/tokens.css`, `resources/css/app.css`
(podgląd w ustawieniach) i CHECK w migracji. **Rozjazd między nimi już raz się
zdarzył i milczał:** 8 września konfiguracja oferowała 140, arkusz znał 150,
a CHECK nie pozwalał 150 powstać — skutkiem czego „Bardzo duży" zapisywał się
na koncie i NIE ROBIŁ NIC. Pilnuje tego `SkalaTekstuDzialaTest`, od 11 września
razem z podpisami (`kuking.text.scale_labels`).

### Rollback odmawia (D-088)

Zwężenie CHECK-a wymagałoby podniesienia skali kontom, które świadomie wybrały
mniejszą. Po `down()` prawie zawsze idzie kolejny `migrate`, CHECK wraca i nie
ma błędu do zauważenia — człowiek zobaczyłby większe litery i nie dowiedziałby
się dlaczego.

### Co musiałoby się stać, żeby to zmienić

Pomiar pokazałby, że ludzie ustawiają 70% przez pomyłkę i potem nie umieją
wrócić. Wtedy znika najmniejszy stopień, a nie całe ustawienie.

---

## D-116 · Poczta na planie Hobby idzie wyłącznie przez API HTTPS — runbookowi nie wolno pokazywać SMTP jako drogi domyślnej

**Data:** 11 września 2026 · Status: **obowiązuje** · Incydent z 9 września

**Railway blokuje ruch SMTP na planach Free, Trial i Hobby.** Awaria jest CICHA:
zadanie wisi w `RUNNING` bez końca, w logach zero błędu, rejestracja się udaje,
a list nie dochodzi nigdzie.

`DEPLOYMENT_RUNBOOK.md` uczył w **trzech** miejscach konfiguracji SMTP — na planie
Hobby, który sam zaleca w KROK 0.2 i KROK 16. Zmiennych wariantu, który działa
(`MAIL_MAILER=emaillabs` + `EMAILLABS_APP_KEY` / `EMAILLABS_SECRET_KEY` /
`EMAILLABS_SMTP_ACCOUNT`), nie wymieniał wcale. Do tego `POCZTA_URUCHOMIENIE.md`
§6 „Rekomendacja" przeczyło §2A tego samego pliku — a §6 jest ostatnim rozdziałem,
więc czyta się go jak podsumowanie.

**Skutek odwrócenia tej decyzji:** serwis, w którym rejestracja się udaje, a listy
nie dochodzą. To nie jest hipoteza — to jest opis 9 września.

Zmienne SMTP zostają uśpione w `railway.ts` jako droga na plan Pro. **Każde
miejsce, które je wymienia, musi mówić, że na Hobby nie działają.**

---

## D-117 · Układ bucketów R2 rozstrzyga `config/filesystems.php`, nie dokument

**Data:** 11 września 2026 · Status: **obowiązuje**

Trzy dokumenty opisywały trzy różne układy: runbook jeden bucket `kuking-media`,
`railway.ts` trzy, `BRAMKA_R2.md` dwa pod innymi nazwami. Nie dało się wykonać
żadnego z nich do końca bez zgadywania.

**Rozstrzyga kod.** Czyta cztery osobne buckety: `AWS_BUCKET` (oryginały z EXIF),
`AWS_PUBLIC_BUCKET` (warianty), `AWS_EXPORTS_BUCKET` (eksporty RODO) i
`AWS_KOPIE_BUCKET` (kopie bazy, z **osobnym poświadczeniem tylko do odczytu**),
plus `AWS_LEGACY_BUCKET` na stan sprzed rozdziału.

**Pułapka warta nazwania: brak `AWS_PUBLIC_BUCKET` CICHO cofa konfigurację do
jednego bucketu** — `config/filesystems.php` ma tam zapas na `AWS_BUCKET`. Wtedy
warianty lądują tam, gdzie oryginały ze współrzędnymi kuchni.

Dokumenty podają **mapowanie zmienna → dysk → zawartość**. Nazwy bucketów są
wartościami i same w sobie niczego nie dowodzą.

---

## D-118 · Bucket R2 istnieje — ryzyko z #120 jest BIEŻĄCE, nie przyszłe

**Data:** 11 września 2026 · Potwierdził właściciel · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Sprostowanie, po które
> ten wpis powstał, nigdy nie weszło do dokumentu.
> `docs/infra/KOPIE_I_ODTWORZENIE.md:58` nadal niesie ramkę „Najpilniejsza
> pozycja z tej tabeli" z tezą „Bucket R2 nie istnieje jeszcze", a `:130`
> powtarza dokładnie zdanie cytowane w tym wpisie jako nieprawdziwe: „**Bucket
> R2 nie istnieje.** Potwierdzone wprost przez właściciela". Czytelnik tamtego
> dokumentu jest więc dalej kierowany na ryzyko, o którym ten wpis mówi, że go
> nie ma, i dalej odwracana jest jego uwaga od ryzyka bieżącego. Wniosek
> metodyczny tego wpisu — „zdanie o stanie świata ma nosić datę pomiaru" — nie
> został zastosowany do samego naprawianego zdania: żadne z dwóch miejsc daty
> nie ma i nic tego nie pilnuje testem.

`KOPIE_I_ODTWORZENIE.md` twierdziło od 8 września: „Bucket R2 nie istnieje jeszcze.
Potwierdzone wprost przez właściciela". **Zdanie zestarzało się w trzy dni** i przez
ten czas kierowało czytelnika na ryzyko, którego nie ma (utrata zdjęć przy
redeployu), odwracając uwagę od tego, które jest.

Stan faktyczny: zdjęcia leżą na R2, a **bramka `kuking:bramka-r2` nie chodziła na
produkcji ani razu** — więc publiczność bucketu oryginałów jest NIESPRAWDZONA.

Osobno, i to nie znika razem z tym sprostowaniem: **bucketów ze zdjęciami nie
kopiuje dziś nic**, a R2 nie ma wersjonowania obiektów ani kosza. Kopie z §7
dotyczą wyłącznie bazy.

**Wniosek metodyczny:** zdanie o stanie świata ma nosić datę pomiaru, a nie samo
nazwisko osoby, która je potwierdziła.

---

## D-119 · Plik-wskaźnik nie powtarza reguły, tylko odsyła — a punkt bez nazwanego wyjątku jest rozjazdem tej samej wagi co punkt nieprawdziwy

**Data:** 11 września 2026 · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Z czterech rozjazdów w
> `CLAUDE.md`, które ten wpis wymienia, naprawiony został jeden — punkt o
> JavaScripcie odsyła dziś do D-053. Dwa nazwane tu wprost stoją dalej.
> `CLAUDE.md:27` każe „Przed PR-em: `vendor/bin/pint` i `php artisan test`",
> gdy `AGENTS.md:454` podaje `./scripts/check.sh` jako JEDNĄ komendę
> obejmującą formatowanie, składnię, testy, migracje i assety. `CLAUDE.md:22`
> mówi „Zmiana schematu = migracja + test + `docs/DATABASE.md` + rollback" bez
> wyjątku z **D-088** (`down()` przy wartościach semantycznych ODMAWIA), który
> `AGENTS.md:318` ma jako osobny podrozdział. Realizuje się więc dokładnie
> ryzyko nazwane w tym wpisie: agent czytający wskaźnik jako pierwszy
> „poprawi" decyzję właściciela, będąc przekonanym, że egzekwuje zasadę.
>
> **Naprawa nie weszła razem z tą adnotacją.** Zlecenie audytu ograniczało
> zmiany do `docs/DECISIONS.md`, a `CLAUDE.md` jest plikiem instrukcji dla
> agentów — jego zmiana należy do człowieka, nie do łańcucha zadań. Potrzebna
> edycja jest dwuliniowa i zgodna z regułą tego wpisu („wskaźnik odsyła, nie
> powtarza"): w `CLAUDE.md:27` zastąpić `pint` i `artisan test` odesłaniem do
> `./scripts/check.sh` z `AGENTS.md` §10; w `CLAUDE.md:22` dopisać „rollback
> może ODMÓWIĆ — patrz D-088 i `AGENTS.md` §6".

`CLAUDE.md` sam o sobie pisze, że jest tylko wskaźnikiem na `AGENTS.md`, i sam
ostrzega, że „rozjazd między plikami instrukcji jest gorszy niż brak instrukcji".
**Był tym rozjazdem w czterech z siedemnastu punktów ściągi** — i jest to plik,
który każdy agent czyta jako PIERWSZY.

Rozjazdy były dwojakiego rodzaju i oba liczą się tak samo:

1. **Wprost nieprawdziwe** — „ważne funkcje działają bez JavaScriptu" po tym, jak
   D-053 tę zasadę zniósł; „przed PR-em `pint` i `test`", gdy `AGENTS.md` §10 mówi
   `./scripts/check.sh`.
2. **Prawdziwe, ale bez nazwanego wyjątku** — reguły UX 50+ bez wyjątku z D-051
   i „migracja + rollback" bez tego, że przy wartościach semantycznych `down()` ma
   ODMÓWIĆ (D-088). Agent czytający taki punkt „poprawia" decyzję właściciela,
   będąc przekonanym, że egzekwuje zasadę.

---

## D-120 · Nagłówek workflow opisuje stan faktyczny wyzwalaczy; wyłącznikiem wdrożeń jest bramka na jobie, nie blok `on:`

**Data:** 11 września 2026 · Status: **obowiązuje**

`deploy.yml`, `preview.yml` i `railway-iac.yml` miały identyczny nagłówek: „ten
workflow jest wyłączony z automatycznego uruchamiania […] żeby WŁĄCZYĆ, odkomentuj
blok `on:` poniżej". **Bloki były aktywne od pierwszego commita** — sprawdzone
w historii. Commit „Wyłączenie workflowów wdrożeniowych" bloków nie ruszył: wyłączył
joby bramką `KUKING_DEPLOY_ENABLED`.

Trzy dokumenty powtarzały to samo polecenie („odkomentuj blok `on:` w `ci.yml`"),
a `ci.yml` ma w nagłówku „CI JEST WŁĄCZONE".

**Nagłówek pliku wykonywalnego to nie jest miejsce na zamiar.** Ma opisywać, co ten
plik robi teraz.

---

## D-121 · Runnera wybiera zmienna repozytorium `CI_RUNS_ON`, a dziś wskazuje starą pulę WSL

**Data:** 11 września 2026 · Status: **obowiązuje** · Ciąg dalszy **D-028** · issue #342

> **Adnotacja z 20 września 2026 (audyt rejestru).** Reguła obowiązuje —
> `runs-on` czyta `vars.CI_RUNS_ON` z fallbackiem `ubuntu-latest`. Liczba nie:
> zdanie „wszystkie **dziewięć jobów** w `ci.yml`" opisuje stan sprzed
> rozrostu workflow. Dziś jobów jest TRZYNAŚCIE (`zakres`, `lint`,
> `przyrzad_605`, `static-analysis`, `test`, `dwa-polaczenia`, `assets`,
> `port_panelu`, `port_marki`, `port_funkcje`, `dostepnosc`, `audit`,
> `docker-build`) i każdy ma tę samą linię `runs-on`. Liczba „dziewięć"
> została przepisana także do komentarza strażnika
> (`tests/Feature/DokumentyCiMowiaPrawdeORunnerzeTest.php:18`), więc rozjazd
> stoi w dwóch miejscach naraz. Drugi człon tytułu — „a dziś wskazuje starą
> pulę WSL" — opiera się na jednym pomiarze (CI nr 661) i dotyczy zmiennej
> żyjącej w ustawieniach repozytorium, więc z kodu nie da się go ani
> potwierdzić, ani obalić. Zgodnie z wnioskiem z D-118 słowo „dziś" w tytule
> powinno nosić datę pomiaru.

Wszystkie dziewięć jobów w `ci.yml` ma
`runs-on: ${{ fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"') }}` — czyli **dokładnie**
zmienną repozytorium, z zapasem w runnerach GitHuba. Nagłówek twierdził odwrotnie:
że joby tej zmiennej nie biorą i zapasu nie mają.

Zmierzone na żywym przebiegu (CI nr 661): `labels: ["self-hosted"]`,
`runner_name: kuking-wsl-DOM-NEW-02`. Zmienna jest ustawiona na **samo**
`self-hosted`, więc joby lądują na starej puli WSL-owej — tej, którą komplet sześciu
etykiet miał wykluczać. Ten sam pomiar stał już w `SELF_HOSTED_RUNNER.md`, 160 linii
niżej niż zdanie, któremu przeczy.

**Zmienna żyje w ustawieniach repozytorium i żaden agent jej nie zmieni.** Wybór
(komplet etykiet / usunięcie zmiennej / świadome zostawienie) należy do właściciela.

---

## D-122 · Gość dostaje prawą szynę obok treści, a nie pod nią — bo komentarz mówił, że gość szyny nie ma, i był nieprawdziwy od 7 września

**Data:** 11 września 2026 · Zgłosił i rozstrzygnął właściciel · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Decyzja obowiązuje — gość
> dostaje szynę obok treści — ale WSZYSTKIE liczby w niej są dziś martwe, bo
> nadpisał je późniejszy port marki. `resources/css/marka-rama.css:185` daje
> szynę **330 px** (wpis mówi 352), `:39` ramę `min(1120px, …)` (wpis: sufit
> 1152), `:50` kolumnę treści **760 px** (wpis: 720). Do tego `:49` ukrywa
> `.side-nav` całkowicie, co znosi przesłankę odróżniającą gościa od
> zalogowanego, na której zbudowana jest sekcja „Ekran gościa BEZ szyny
> zostaje jednokolumnowy"; atrybut `data-marka="kuking-2026"` siedzi na
> `<body>` bezwarunkowo (`resources/views/components/layout.blade.php:359`).
> Strażnik `tests/Feature/SzynaGosciaTest.php:277-310` niczego nie zauważył,
> bo czyta TEKST ARKUSZA, a nie wynik kaskady.

### Zgłoszenie

„niektóre podstrony jak napisz do nas jest bardzo wąskie, gdzie po prawej i lewej
można coś dodać na kompie". Audyt UI/UX niezależnie nazwał to §4 i było to jego
jedyne P0.

### Co było nieprawdą i od kiedy

`resources/css/app.css` zwijał układ niezalogowanego do JEDNEJ kolumny 768 px na
każdej szerokości, a uzasadniał to zdaniem „Gość nie ma nawigacji bocznej ANI
SZYNY". Pierwsza połowa jest prawdą do dziś. **Druga była prawdą jeden dzień:**
zdanie powstało 6 września, 7 września `/szukaj` dostało `<x-slot:rail>`,
10 września doszły `/napisz-do-nas` i `/@nazwa` (#231). Komentarz został i przez
kolejne dni tłumaczył regułę, której już nie uzasadniał.

Zmierzone 11 września, okno 1920 px, gość na `/napisz-do-nas`: treść 720 px
w ramce 768 px, a blok „Nie możesz się zalogować" — czyli odpowiedź, po którą ta
osoba przyszła — na **y = 1964 px**, dwa ekrany niżej. Na `/@nazwa` szyna zaczynała
się na y = 5573 px.

### Decyzja

Gość na ekranie Z SZYNĄ dostaje od 80rem dwie kolumny: treść 720 px + szyna 352 px,
sufit `--container-strona-solo-z-szyna` = 1152 px. Trzech kolumn nie dostaje, bo
nawigacji bocznej nie ma. Po zmianie blok szyny stoi na **y = 96 px** przy 1920
i przy 1280 px.

### Ekran gościa BEZ szyny zostaje jednokolumnowy i wyśrodkowany

Zalogowany ma trzecią kolumnę zarezerwowaną NAWET bez szyny (#294), żeby nawigacja
boczna stała na każdym ekranie w tym samym miejscu. U gościa ten powód nie istnieje
— rezerwacja dołożyłaby 384 px pustki po prawej i zepchnęła treść w lewo, czyli
powtórzyłaby zgłoszenie właściciela o panelu moderacji. Cena: strony gościa mają
dwie szerokości, 768 i 1152 px. Przyjęta świadomie.

### Liczy się TREŚĆ slotu, nie sam slot

`<x-slot:rail>` bywa podany i pusty — cudzy profil bez tagów i bez publicznych
zeszytów, oglądany przez gościa, nie wypisuje ani jednego bloku (blok z liczbami
stoi pod `@auth`). Samo `isset($rail)` dałoby tam pustą kolumnę 352 px, a pusta
kolumna wygląda na usterkę układu, nie na wybór.

### `app-body-solo` znaczy „układ gościa", nie „jedna kolumna"

Klasa `app-body-solo-z-szyna` **dochodzi** do `app-body-solo`, nie zastępuje jej.
`ekran-profilu.css` czyta `:not(.app-body-solo)`, żeby zostawić gościowi liczby
o osobie w karcie profilu — bloku w szynie gość nie dostaje (D-091), więc
zastąpienie klasy zabrałoby mu te liczby całkiem.

### Czego pilnują pomiary

`SzynaGosciaTest` (klasy układu, publiczne ekrany z szyną, pusta szyna, kolejność
reguł w arkuszu), `UkladGosciaTest` oraz sekcja „Szyna gościa (D-122)"
w `scripts/dostepnosc.mjs`. Lista publicznych ekranów z szyną **nie jest wpisana
z ręki** — test skanuje katalog widoków, więc czwarty taki ekran wejdzie do pomiaru
sam.

### Co musiałoby się stać, żeby to zmienić

Pomiar pokazałby, że na ekranie gościa szyna odciąga uwagę od treści, po którą
przyszedł. Wtedy znika treść szyny na tych ekranach, a nie kolumna.

---


> **Uwaga (20.09.2026, pomiar do D-223).** Liczby tej decyzji nadal obowiązują
> jako ROZSTRZYGNIĘCIE, ale reguły CSS, w których je zapisano, **w większości nie
> dochodzą do przeglądarki**. Zmierzone `getComputedStyle` na wyrenderowanych
> stronach, 72 konfiguracje: `.app-body { grid-template-columns:
> var(--container-sidenav) … }`, `.app-body { max-width: var(--container-strona) }`
> oraz `.uklad-solo .topbar-inner, .uklad-solo .site-… { max-width:
> var(--container-strona-solo…) }` są **całkowicie przykryte** przez arkusze
> `resources/css/marka-*.css`, które nie są owinięte w żadną warstwę — a kod
> spoza warstw bije każdą warstwę nazwaną, także `utilities`.
>
> Znaczy to, że układ, który widzi gość, ustala dziś warstwa marki, a nie te
> reguły. Sama decyzja zostaje bez zmian i nic tu nie usuwamy: usunięcie martwej
> reguły JEST zmianą zachowania na wypadek zniknięcia arkuszy marki i wymaga
> osobnego rozstrzygnięcia. Pilnuje tego `scripts/kaskada-martwe-reguly.mjs`.
> Strażnik, który czytał TEKST arkusza, opisywał tu stan nieistniejący — po to
> powstało D-223.

## D-123 · Wybór gospodarza na tablicy jest UZUPEŁNIANY automatem do sufitu, a nie zamyka tablicy na resztę serwisu

**Data:** 11 września 2026 · Zgłosił i rozstrzygnął właściciel · Status: **obowiązuje**

### Zgłoszenie

„w »co się dziś gotuje« można zrobić dwie kolumny i dać więcej tych ludzi (chyba
że za mało wpisów i temu tak pusto)". Właściciel zgadywał przyczynę i zgadywał
źle — i to jest najciekawsze w tym wpisie.

### Co było nie tak

`DailyBoard::forViewer()` sprawdzał, czy na dziś istnieje choć jeden wybór
redakcyjny, i jeśli tak — zwracał **wyłącznie** jego:

```php
if ($picks->isNotEmpty()) {
    return $this->fromCuratedPicks($picks, $viewer);   // i koniec
}
```

Właściciel zaznaczył w `/kuking-na-dzis` cztery pozycje i zobaczył na stronie
powitalnej dwie osoby i dwa dania tam, gdzie mieści się dwa razy tyle. Pustka nie
brała się ani z układu, ani z braku treści.

### Decyzja

Wybór gospodarza ma **wyróżniać** kilka rzeczy, a nie zamykać tablicę. Najpierw
idzie to, co wskazał człowiek, potem dobór automatu do sufitu.

Trzy rzeczy są częścią tej decyzji, nie szczegółem implementacji:

**Kolejność.** Wybór człowieka stoi pierwszy. Inaczej wyróżnienie przestaje być
wyróżnieniem — w teście osoba niewybrana publikuje później i bez tej reguły
stałaby na pierwszym miejscu.

**Dziura po pozycji schowanej też się zapełnia.** Brakujące miejsca liczymy z tego,
co NAPRAWDĘ zostało po odsianiu pozycji niedostępnych dla tego widza (autor
zablokowany, wpis schowany przez moderację już po wyborze), a nie z liczby
zaznaczeń w panelu. Inaczej widz z jedną blokadą dostawałby tablicę krótszą od
cudzej, bez żadnego powodu.

**Dobór pomija AUTORÓW wybranych dań, nie same dania.** Reguła „najwyżej jedno
danie od osoby" obowiązuje w całej tablicy, a nie osobno w części redakcyjnej
i osobno w dobranej. Bez tego automat dołożyłby drugi wpis dokładnie tej osoby,
którą gospodarz przed chwilą wyróżnił — czyli zrobiłby to, przed czym broni
`docs/product/COLD_START.md`.

### Co to NIE zmienia

Zakaz rankingów (AGENTS.md §12) stoi bez zmian. Automat dobiera po tym, KIEDY ktoś
ostatnio coś pokazał; żadna miara popularności nie wchodzi ani w wybór, ani
w kolejność.

### Szczegół, który łatwo zrobić źle

Wykluczenia idą **parametrem do zapytania**, a nie odsiewaniem po pobraniu. Limit
jest narzucany w SQL, więc odsianie „po fakcie" zwracałoby mniej pozycji niż
proszono — i błąd wyglądałby dokładnie jak ten, który naprawiamy.

---

## D-124 · Sufit tablicy dnia to sześć pozycji — bo panel przyjmował sześć od początku, a automat stawał na czterech

**Data:** 11 września 2026 · Status: **obowiązuje**

### Rozjazd

`Admin\DailyBoardController` przyjmował `max:6` osób i `max:6` dań. `DailyBoard`
miał `PEOPLE = 4` i `POSTS = 4`. Gospodarz mógł wskazać więcej, niż tablica była
w stanie pokazać — i nic o tym nie mówiło.

### Decyzja

Sufit to sześć. Sufit zmienia, ILE pozycji widać, a nie to, KTÓRE stoją wyżej —
więc §12 pozostaje nietknięty. Limit gościa na landingu (3/3) zostaje bez zmian,
bo to osobna decyzja z audytu 60+.

### Dlaczego zmiana sufitu niczego nie przelicza

Regułę „najwyżej jedna pozycja od osoby" trzyma `DISTINCT ON (posts.author_id)` —
**struktura zapytania**, nie zgadywany zapas nad limitem. Przy poprzednim
podejściu („pobierz `POSTS * 6` i odsiej") każda zmiana sufitu wymagałaby
przeliczenia zapasu od nowa.

### Zmierzone

Liczba zapytań jest identyczna przed i po: `peopleToFollow()` 4 zapytania przy
limicie 4 i 4 przy 6; całe `forViewer()` 10 i 10. Limit idzie w SQL, relacje przez
`with()`/`withCount()`, więc liczba zapytań nie zależy od liczby wierszy.

Pomiar `28,6 ms` kontra `12,9 ms` z komentarza przy `peopleToFollow` zostaje ważny:
obie wersje płacą za agregację i sortowanie całości, a `LIMIT` obcina dopiero
posortowany wynik. Koszt rośnie z liczbą kont i wpisów, nie z sufitem.

---

## D-125 · Klasa `.card` niosła 126 ról naraz — sześć warstw powierzchni zamiast jednej

**Data:** 11 września 2026 · Status: **obowiązuje** · Inwentarz: `docs/design/ROLE_KART.md`

> **Adnotacja z 20 września 2026 (audyt rejestru).** Zdanie „hierarchia bierze
> się z **uniesienia i mocy obwódki**, nie z koloru… warstwy 1, 3 i 4 różni
> wyłącznie cień" przestało opisywać stan faktyczny po porcie marki.
> `resources/css/marka-rama.css:238-241` nadaje globalnie `[data-marka]
> :is(.card, .panel-formularza, .empty-state)` ten sam `border-radius: 24px` i
> ten sam `box-shadow`, więc karta treści (warstwa 1) i panel formularza
> (warstwa 2) różnią się dziś już tylko obwódką — uniesienie, czyli połowa
> nośnika hierarchii z cytatu, między nimi zniknęło. Sześć klas i tokeny
> `--warstwa-*` nadal istnieją (`resources/css/tokens.css:1204-1270`). Pomiar
> opisany w tym wpisie (`scripts/warstwy-pomiar.mjs`, wyniki „3/1/3 → 3/2/2")
> nie jest wywoływany ani w `scripts/check.sh`, ani w
> `.github/workflows/ci.yml`, więc liczby stąd nie są odtwarzalne w bramce i
> nikt nie zauważy ich zmiany.

### Co było nie tak

Jedno tło, jedna obwódka, jeden promień i jeden cień były jednocześnie kartą wpisu,
sekcją strony, blokiem prawej szyny, panelem formularza, ramką z wyjaśnieniem
i kaflem, w który się klika.

Widać to było na `/napisz-do-nas`: karta „Chodzi o czyjś wpis?", formularz i karta
„Co się stanie dalej" wyglądały identycznie, choć tylko **jedna** z tych trzech
rzeczy czegokolwiek od człowieka chciała.

### Decyzja

Sześć warstw: karta treści · panel formularza · sekcja strony · blok szyny · ramka
pomocnicza · kafel akcji. Hierarchia bierze się z **uniesienia i mocy obwódki**,
nie z koloru. Warstwy 1, 3 i 4 różni wyłącznie cień i tak ma być.

**Obwódka mocna tam, gdzie czegoś od człowieka chcemy.** Panel formularza i kafel
akcji dostają `--color-border-strong` — tę samą, którą mają pola formularza. To
jedyne dwie warstwy, które czegoś WYMAGAJĄ, więc niosą obwódkę kontrolki, a nie
linię dekoracyjną: obwódka kontrolki musi mieć 3:1 do tła (WCAG 1.4.11),
a `--color-border` tego progu nie ma.

### Miara, która pokazuje płaski ekran

`scripts/warstwy-pomiar.mjs` podaje **powierzchnie / sygnatury / największą grupę
jednakowych**. Gdy pierwsza liczba równa się trzeciej, ekran nie ma hierarchii.
`/napisz-do-nas` szło z 3/1/3 na 3/2/2, `/logowanie` z 2/1/2 na 2/2/1.

### Gdy wszystko ma tę samą rangę, nic jej nie ma

Najbardziej traci na tym ktoś, kto czyta wolniej albo powiększa tekst — bo
skanowanie wzrokiem przestaje być skrótem.

---


> **Uwaga (20.09.2026, pomiar do D-223).** Podział `.card` na sześć warstw
> powierzchni obowiązuje jako rozstrzygnięcie, ale zmierzone w przeglądarce
> deklaracje samej `.card` z warstwy `components` (m.in. tło, obramowanie
> i promień) są **całkowicie przykryte** przez `[data-marka] .post-card`
> i pokrewne z arkuszy `marka-*.css` spoza warstw. O wyglądzie karty decyduje
> dziś warstwa marki, nie te reguły.
>
> Nic tu nie usuwamy ani nie zmieniamy statusu — to jest adnotacja o tym, GDZIE
> wartość naprawdę obowiązuje. Poprawka wpisana w regułę `.card` z `components`
> nie dojdzie do nikogo. Szczegóły i strażnik: D-223.

## D-126 · Panel formularza nie pojawia się tam, gdzie w danym stanie ekranu nie ma czego wypełnić

**Data:** 11 września 2026 · Status: **obowiązuje**

Panel z mocną obwódką **obiecuje**, że jest co wypełnić. To ta sama zasada, co zakaz
martwego przycisku (D-053), przeniesiona na warstwę powierzchni.

Dlatego warstwę wybiera tam warunek, a nie stała: `errors/419`, `errors/429`, zmiana
adresu e-mail (gdy poczta nie działa) i odpowiedź w panelu moderacji (gdy nie ma
adresu do odpowiedzi) schodzą wtedy na sekcję.

**Przypadek otwarty, świadomie:** `pages/collections/index.blade.php` ma
`<details class="panel-formularza">` z podsumowaniem „Załóż nowy zeszyt". W stanie
zwiniętym mocna obwódka otacza sam przycisk — pola są w środku, ale niewidoczne.
To nie jest martwa obietnica, ale przez większość czasu panel nie ma czego
wypełniać. Zostawione bez zmian i nazwane wprost, żeby nie udawać, że problemu nie
ma.

---

## D-127 · Droga równorzędna nigdy nie schodzi na warstwę wgłębioną

**Data:** 11 września 2026 · Rozstrzygnął właściciel · Status: **obowiązuje**

Warstwa wgłębiona (`.ramka-pomocnicza`) mówi wizualnie **„to jest coś obok"**.
Postawienie na niej drogi, która jest równorzędna, byłoby cofnięciem tamtej decyzji
w warstwie wyglądu.

Cofnięte na sekcję i objęte tą regułą: logowanie linkiem (D-056) · wejścia Google
i Facebooka (D-113) · pouczenie DSA art. 16 ust. 5 (D-042) · opis skutków usunięcia
konta (D-022) · oczekująca zmiana adresu (D-048) · kod do ręcznego wpisania
przy włączaniu 2FA.

### Rozstrzygnięcie z 11 września: kod zapasowy przy logowaniu 2FA

`auth/two_factor_challenge.blade.php` trzymał **pełny, działający formularz
logowania kodem zapasowym** („Nie mam dostępu do telefonu") w `<details>` na warstwie
wgłębionej. Kod w tym miejscu uzasadniał to tym, że kod z aplikacji jest metodą
podstawową, a zapasowy — ratunkową.

Właściciel rozstrzygnął: **sekcja**. Człowiek, który stracił telefon, jest
w najgorszym momencie kontaktu z serwisem, a wgłębiona ramka mówi mu „to jest coś
obok". Strukturalnie to ta sama sytuacja, którą rozstrzyga D-056.

---

## D-128 · Rolę powierzchni nadaje MIEJSCE, a nie obiekt

**Data:** 11 września 2026 · Status: **obowiązuje**

To samo zgłoszenie jest **kartą treści** na liście zgłoszeń i **sekcją** na ekranie
swoich szczegółów. Nie dlatego, że zmienia się obiekt, tylko dlatego, że zmienia się
pytanie, które człowiek ma w głowie.

### Rozstrzygnięcie z 11 września

Na `/zgloszenia/{id}` trzy bloki miały jedną sygnaturę, w tym „Nasza decyzja".
Właściciel rozstrzygnął, że **„Nasza decyzja" dostaje kartę treści**, a pozostałe
dwa zostają sekcjami — bo decyzja jest tym, po co człowiek tam wszedł.

---

## D-129 · Menu poza panelem pokazuje JEDNO wejście do moderacji, a liczba z kolejek się sumuje

**Data:** 11 września 2026 · Zgłosił i rozstrzygnął właściciel · Status: **obowiązuje**

### Zgłoszenie

„po co w menu cały panel moderacji i pod spodem przycisk otwórz panel moderacji?
niech będzie tylko otwórz panel moderacji".

### Co było nie tak

Dziewięć ekranów moderacji stało w **zwykłym** menu, obok „Profil"
i „Powiadomienia", a pod nimi przycisk prowadzący dokładnie tam, gdzie prowadziła
ich pierwsza pozycja. Ta sama rzecz powiedziana dwa razy, kosztem dziewięciu wierszy
menu z pracą, której się w tym miejscu nie wykonuje.

### Decyzja

Poza panelem — jedno wejście. W panelu — pełny spis, bo tam się tę pracę wykonuje.

**Nagłówka grupy poza panelem też nie ma.** Grupa jednego elementu nie jest grupą,
a napis „Panel moderacji" nad odnośnikiem „Otwórz panel moderacji" to jedna nazwa
dwa razy pod rząd — czytnik ekranu przeczytałby obie.

### Liczba nie znika, tylko się sumuje

Plakietka przy pozycji „Bez odpowiedzi" była jedynym sygnałem „jest robota" poza
panelem. Usunięcie listy bez niczego w zamian byłoby cichą stratą poprawnej
informacji. Plakietka przenosi się więc na wejście i pokazuje sumę wszystkich pięciu
kolejek; rozbicie zostaje w panelu, jedno kliknięcie dalej. Puste kolejki nie
pokazują „0" — zero to sam hałas.

Sumowanie wypisuje nazwy kolejek **wprost**, a nie `array_sum()`: nowy klucz
w `KolejkiPanelu`, który nie jest kolejką do przejrzenia, nie doliczy się po cichu.

---

## D-130 · Długi wpis na karcie skraca się do „Czytaj dalej"; próg patrzy na WIERSZE i na znaki

**Data:** 11 września 2026 · Zgłosił właściciel · Status: **obowiązuje**

### Zgłoszenie

„Na głównej długie wpisy można skrócić dać czytaj dalej" — jeden przepis ze
składnikami wypełniał na telefonie cały ekran i wypychał wszystko poniżej.

### Dlaczego próg w samych znakach by nie działał

`.post-card-body` ma `white-space: pre-line`, więc **każde przełamanie autora
zostaje osobnym wierszem**. „500 g mąki / 350 ml wody / 7 g drożdży" ma mało znaków
i dużo wierszy — a ekran zjadają wiersze. Skracamy, gdy przekroczony JEDEN z progów:
osiem wierszy albo czterysta znaków.

Cięcie po pełnych wierszach, potem po całych wyrazach (`Str::words()`, nie
`Str::limit()`).

### „Czytaj dalej" prowadzi na stronę wpisu, nie rozwija w miejscu

Nic nie skacze pod palcem, działa bez JavaScriptu, a czytelnik ląduje tam, gdzie
i tak są komentarze.

### Dlaczego odpada `-webkit-line-clamp`

Klamra nie potrafi powiedzieć szablonowi, **czy** przyciąć — odnośnik pokazałby się
także pod wpisem dwuzdaniowym, czyli byłby martwym przyciskiem (D-053). Do tego
wysokość klamry trzeba by podać w `rem`, a wtedy próg mierzyłby co innego u każdego
czytelnika (D-082, D-107).

### Wyjątek, bez którego byłby martwy przycisk

Ta sama karta stoi też na stronie pojedynczego wpisu. Bez warunku „czy jestem na
stronie TEGO wpisu" całej treści nie dałoby się przeczytać nigdzie, a „Czytaj dalej"
prowadziłoby samo do siebie.

### Progi są hipotezą, nie pomiarem

400 znaków i 8 wierszy to liczby z rozumowania, nie ze zmierzenia na telefonie. Są
publicznymi stałymi, żeby zmiana była jedną cyfrą.

---

## D-131 · Napis przycisku jest JEDNYM elementem — `inline-flex` rozbija tekst na osobne elementy flex

**Data:** 11 września 2026 · Zgłosił właściciel · Status: **obowiązuje**

### Objaw

Na telefonie główny przycisk strony powitalnej wyglądał tak:

```
Zost    kuKINGi      — to
ań        em      darmowe
```

### Przyczyna — nie „za długi napis"

`.btn` jest `display: inline-flex`, a kontener flex robi z KAŻDEGO kawałka tekstu
między elementami inline **osobny element flex**. Napis
`Zostań <x-kuking-word/> — to darmowe` to trzy węzły, czyli trzy niezależnie
zawijane elementy rozdzielone `gap`. Do tego `.btn` ma `overflow-wrap: anywhere`
(obrona przed wypchnięciem strony przy 200% czcionki), więc każdy z nich łamał się
w ŚRODKU WYRAZU, bo osobno był za wąski.

### Zmierzone, ramka 320 px

Bez owinięcia: 3 elementy flex, napis w pięciu kawałkach, przycisk 114 px wysokości.
Z owinięciem: 1 element, dwa wiersze łamane na spacjach, 84 px.

### Reguła

Napis przycisku owinięty jednym elementem. **Przycisk z ikoną to POPRAWNE dwa
elementy flex** — `gap` między ikoną a podpisem jest tam po to, żeby był, i tego się
nie „naprawia".

Strażnik pyta o wyrenderowany HTML: dla każdego `.btn` liczy bezpośrednie węzły
tekstowe. Dwa lub więcej to błąd, bo dwa węzły tekstowe mogą być rozdzielone tylko
elementem inline.

---

## D-132 · Strażnik `down()` bez testu wołającego ten `down()` nie jest strażnikiem

**Data:** 11 września 2026 · Status: **obowiązuje**

Pięć migracji miało poprawną obronę — `RuntimeException`, zgoda przez `getenv()`,
opis zgodny z `docs/DATABASE.md` — i **ani jednego testu, który by ją wywołał**.
`down()` nie chodzi w normalnym przebiegu testów, więc taka obrona może zniknąć przy
pierwszym refaktorze i nikt tego nie zauważy.

### Każda taka migracja dostaje trzy rzeczy

1. **odmowę** plus asercję, że dane NADAL SĄ — odmowa, która zdążyła skasować, to
   tylko ładniejszy komunikat o stracie;
2. **kontrolę dodatnią** na pustym stanie — bez niej test przechodzi także dla
   migracji, która nie cofa się NIGDY;
3. **wąskość** — odmowa nie rusza niczego poza swoim zakresem.

Migracja z furtką przez zmienną środowiskową dostaje czwartą: furtka opisana
w komunikacie ma naprawdę działać.

### Co pokazały sabotaże

Bez strażnika odmawia sama baza — surowym `SQLSTATE[23514]` albo `SQLSTATE[23502]`
zamiast zdaniem po polsku. To jest cała różnica między „nie da się" a „nie da się,
oto ilu osób to dotyczy i co zrobić zamiast tego".

### Komunikat nie odmienia rzeczownika przez liczbę

„jest 1 zgłoszeń" i „że 1 osób odebrało" to formy błędne, a jeden wiersz jest stanem
bardziej prawdopodobnym niż pięć. Liczbę podaje się w formie odpornej: „Liczba
zapisów, które znikną: 1".

---

## D-133 · `$this->fail()` nie stoi wewnątrz `try` w teście łapiącym odmowę

**Data:** 11 września 2026 · Status: **obowiązuje**

`PHPUnit\Framework\AssertionFailedError` dziedziczy po `RuntimeException`. Test
napisany tak:

```php
try {
    $this->migracja()->down();
    $this->fail('Cofnięcie przeszło.');
} catch (RuntimeException) {
    // ...
}
```

**łapie własne `fail()` we własnym `catch`.** Przy teście wąskości, gdzie `catch`
jest z natury pusty, taki test byłby zielony także wtedy, gdyby strażnika w ogóle
nie było.

Wzorzec: odłóż wyjątek do zmiennej, oceń **poza** blokiem.

Znalezione przez agenta w cudzym pliku wzorcowym (`CofniecieDziennikaZgodOdmawiaTest`),
gdzie ratowały to asercje w `catch` — czyli było bezpieczne **przez przypadek, nie
z konstrukcji**.

---

## D-134 · Cyfra wersji rośnie przy każdej widocznej zmianie, a każde podbicie ma wpis w `CHANGELOG.md`

**Data:** 11 września 2026 · Zgłosił i rozstrzygnął właściciel · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Pierwsza połowa reguły
> działa: `config/kuking.php` stoi na `'etykieta' => 'Alfa 0.67'`, a metryczki
> w stopce pilnuje `tests/Feature/StopkaPoziomyTest.php:119`. Druga połowa
> jest złamana — w `CHANGELOG.md` NIE MA wpisu dla **Alfy 0.46**: `:101` to
> „## Alfa 0.47 — kolaż po wyczyszczeniu wyboru", a następna pozycja pod nim,
> `:107`, to „## Alfa 0.45 — przycisk rejestracji przy dużym tekście". To jest
> dokładnie „numer bez treści", przed którym ostrzega zdanie „jedno pilnuje
> drugiego". Przyczyna jest strukturalna: sprzężenie nie ma strażnika — `grep
> CHANGELOG` po `tests/` i `scripts/` nie daje ani jednego trafienia. Ten wpis
> przewiduje ryzyko „strażnika poprawianego bezmyślnie przy każdym wydaniu";
> zrealizowało się ryzyko odwrotne — strażnika nie ma wcale.

### Zgłoszenie

„aktualna wersja to alfa 0.1, czemu tego nie zmieniasz? chyba dużo zmian zrobiliśmy
od pierwszej alfy 0.1".

### Co było nie tak z regułą

Komentarz przy `kuking.wersja.etykieta` mówił, kiedy zmienia się **słowo** (Alfa →
Beta → 1.0, przy kamieniach milowych z ROADMAP-y) — i ani słowa o tym, kiedy zmienia
się **cyfra**. Przez to `0.1` nie ruszyło się ani razu od pierwszego dnia, mimo
kilkunastu scaleń samego 11 września.

**Numer, którego nikt nigdy nie podbija, nie niesie żadnej informacji.** Prawdę
o tym, co działa, mówił w stopce wyłącznie skrót commita obok.

### Decyzja

Cyfra rośnie przy każdej zmianie, którą **człowiek zobaczy**: nowy ekran, zmieniony
układ, nowa funkcja, inne zachowanie formularza. Poprawki bez śladu w interfejsie
(testy, refaktor, dokumentacja) jej nie ruszają.

Każde podbicie ma wpis w `CHANGELOG.md`, pisany **językiem użytkownika, nie
commitów**. Jedno pilnuje drugiego: wersja bez wpisu jest numerem bez treści, a wpis
bez wersji nie da się z niczym powiązać.

Historii sprzed 11 września nie odtwarzamy wstecz — wpisy pisane z pamięci po fakcie
są gorsze niż ich brak.

### Etap zostaje „Alfa"

Bramki zamkniętej alfy nie przeszliśmy: sześć kont przy wymaganych dwudziestu, kopia
produkcyjnej bazy to nadal zero, a blocker UX był otwarty w dniu tej decyzji.

### Test stopki nie zna wersji na pamięć

`StopkaPoziomyTest` miał w regeksie wpisane `Alfa 0\.1`. Czyta teraz etykietę
z konfiguracji i pilnuje, że etap produktu **stoi** w metryczce — a nie że akurat
dziś brzmi tak, a nie inaczej. Strażnik, który trzeba poprawiać przy każdym
wydaniu, zostaje prędzej czy później poprawiony bezmyślnie.

---

## D-135 · Ekran dodawania przepisu pyta o SZEŚĆ rzeczy, a przepis wolno opublikować bez ani jednego składnika

**Data:** 11 września 2026 · Issue #364 · Zgłosił i zgodził się właściciel · Status: **obowiązuje**

### Zgłoszenie

„te dodawanie przepisów jest zbyt skomplikowane dla mnie, 32 latka który ogarnia
programowanie itp a co dopiero dla seniora", a po obejrzeniu ekranu: „trzeba uprościć
to i usunąć te tysiące pól, przycisków, informacji itp bo seniorzy dostaną oczopląsu".

### Co było na ekranie

Policzone, nie oszacowane: **98 kontrolek** na jednym ekranie dodawania przepisu.
Pola porcji, czasów, trudności, pochodzenia, roku „w rodzinie od", skanu kartki,
grup składników, jednostek, uwag przy składniku, czasów przy kroku — wszystko naraz,
przed pierwszym zdjęciem.

### Decyzja

Ekran dodawania pyta o **sześć** rzeczy: zdjęcie, tytuł, składniki, przygotowanie,
widoczność, przycisk publikacji. Reszta przechodzi na osobny ekran „Dopisz szczegóły",
dostępny **po** opublikowaniu. Limit jest pilnowany testem
(`DodawaniePrzepisuSzescKontrolekTest::LIMIT_KONTROLEK = 6`), a nie dobrą wolą —
inaczej wróciłby po jednym polu naraz.

### Przepis bez składników wolno opublikować — i to jest najtrudniejsza część tej decyzji

Walidacja ma `ingredients` jako `nullable`, a `skladniki_tekst` może być puste.
Test `test_przepis_z_samym_zdjeciem_tytulem_i_tekstem_da_sie_opublikowac` stwierdza
wprost: zero składników, jeden krok, przepis opublikowany — i asercja
„Przepis bez składników nie ma prawa ich sobie dorobić".

Zgłosiłem to właścicielowi jako świadomy koszt: baza przepisów bez składników jest
gorsza do wyszukiwania i do „co mam w lodówce". Odpowiedź brzmiała **„ok daję zgodę"**.

Uzasadnienie, które za tym stoi: **przepis, którego ktoś nie opublikował, ma zero
składników tak samo.** Ktoś, kto zna rosół z głowy, opisze go zdaniem i nie będzie
rozpisywał gramatury — a jeśli wymusimy listę, nie opublikuje nic. Brakujące
składniki da się dopisać później; nieopublikowany przepis nie wraca.

### Zaproszenie do dopisania szczegółów istnieje tylko wtedy, gdy jest co dopisać

`App\Domain\Recipes\CoMoznaDopisac` pyta o dziesięć pól, o zdjęcie główne oraz
o to, czy przepis ma **ani jednego** składnika albo kroku. Komunikat po publikacji
kieruje do „Edytuj" tylko wtedy, gdy odpowiedź brzmi „tak". Przepis wysłany
z wypełnionym wszystkim dostałby inaczej przycisk prowadzący do formularza bez ani
jednego pustego pola — czyli martwy przycisk z D-053.

Reguła stoi w domenie, nie w widoku, bo pyta o nią więcej niż jedno miejsce
i wszystkie muszą odpowiadać tak samo.

---

## D-136 · Składniki i kroki wpisuje się jako TEKST w jednym polu; baza dalej trzyma wiersze

**Data:** 11 września 2026 · Issue #364 · Status: **obowiązuje**

### Decyzja

Formularz przyjmuje `skladniki_tekst` i `przygotowanie_tekst` — dwa zwykłe pola
wielowierszowe. `App\Domain\Recipes\TekstNaWiersze` rozbija je na wiersze:
składniki po liniach, kroki po pustej linii.

**Schemat bazy się nie zmienia.** `recipe_ingredients` i `recipe_steps` zostają
takie, jakie były. To jest zmiana wyłącznie po stronie wejścia — dlatego nie ma
tu migracji ani wpisu w `docs/DATABASE.md`.

### Dlaczego nie zostawić tablicy pól

Lista składników jako osobne pola z jednostką, ilością, grupą i uwagą to przy
dziesięciu składnikach czterdzieści kontrolek. Człowiek, który ma przepis
przepisany na kartce albo w mailu, chce go **wkleić**. Rozbicie na wiersze
robi za niego to, co i tak zrobiłby ręcznie, tylko czterdzieści razy.

### Czego pilnują testy

Że wklejona lista daje **tyle wierszy, ile niepustych linii** (a nie „jakieś"),
że pusta linia rozdziela kroki, i że cztery składniki po dopisaniu szczegółów
dalej są czterema **i w tej samej kolejności**. Ten ostatni jest tu najważniejszy:
konwersja tekst → wiersze → tekst → wiersze to miejsce, w którym kolejność gubi
się po cichu i nikt tego nie zauważa aż do skargi.

---

## D-137 · Każde wejście do dodawania pokazuje OBIE drogi, a nie tę, przez którą się weszło

**Data:** 11 września 2026 · Issue #366 · Zgłosił właściciel · Status: **obowiązuje**

### Zgłoszenie

„użytkownicy nie widzą że w »Dodaj« można wybrać »Zdjęcie i kilka słów« i »Cały
przepis«… trzeba to ujednolicić".

### Co było nie tak

Drzwi do dodawania policzone: **osiem** prowadziło prosto do zdjęcia, **trzy**
prosto do przepisu, **trzy** do ekranu wyboru. Czyli w jedenastu przypadkach na
czternaście człowiek nie dowiadywał się, że druga droga w ogóle istnieje.

To tłumaczy zjawisko, o które właściciel pytał osobno — „wszyscy dodają zdjęcie
i kilka słów". Nie dlatego, że wybrali; dlatego, że nie mieli czego wybierać.

### Decyzja

Nad **każdym** formularzem dodawania stoi ten sam komponent
`x-zakladki-dodawania` z dwiema zakładkami: „Zdjęcie i kilka słów" oraz
„Cały przepis". Bieżąca jest oznaczona, druga jest odnośnikiem.

Kontrast policzony **przed** wklejeniem, nie po: napis bieżącej 5,72:1 w jasnym
i 4,72:1 w ciemnym (próg 4,5), obwódka niebieżącej 4,16:1 i 3,83:1 (próg 3 dla
obwódki kontrolki, WCAG 1.4.11). Token `--color-border` dałby 1,40:1 i nie nadawał
się tu w ogóle.

---

## D-138 · Panel moderacji bierze całą szerokość; reguła 45rem broni CZYTANIA, a nie tabeli

**Data:** 11 września 2026 · Issue #365 · Zgłosił właściciel · Status: **obowiązuje**

### Zgłoszenie

„dla admina i moderatora jest wąskie, przez co informacje trzeba przewijać, zrób
dla admina i moderatora 100% szerokości".

### Przyczyna miała DWIE warstwy i to jest sedno tego wpisu

Pierwsza warstwa to sufit ramy. Druga to `max-width: var(--container-content)`
na `.app-main`. **Samo podniesienie sufitu zostawiłoby tabelę przy 720 px** —
czyli zmiana wyglądałaby na zrobioną, a zgłoszenie zostałoby otwarte.

Zmierzone na `/admin/uzytkownicy`: przy 1920 px kontener 686 → 1566 px przy
tabeli 1358 px — **przewijanie znika**. Przy 1280 px 686 → 926 px — **dalej
przewija**, bo tabela potrzebuje 1358 px. Zgłoszenie jest więc zamknięte
od 1600 px w górę, nie wszędzie, i tak to nazywam.

### Dlaczego wolno było zdjąć 45rem akurat tutaj

Reguła 45rem istnieje dla **wiersza tekstu** — oko gubi początek następnego
wiersza przy zbyt długiej linii. Tabela kont to siedem kolumn porównywanych
w poziomie; na zwężeniu nie zyskuje nic, a traci wszystko.

Przy 320 px nic się nie zmienia: tabela dalej jeździ w **swoim** kontenerze.
WCAG 2.2 AA 1.4.10 broni przed przewijaniem CAŁEJ strony, nie przed przewijaniem
tabeli, która z natury jest szeroka.

### Czego tu NIE zrobiono, mimo że brzmiało jak część tego samego

Sufit ramy (1424 px) **nie został podniesiony**, a przepisanie go na procenty
jest zmianą zapisu, nie pikseli — zmierzone 0 px różnicy. Powód: trzecia kolumna
`.app-body` to sztywne `var(--container-rail)`. Przy szerszej ramie nadwyżka
wpadłaby w kolumnę środkową, którą `.app-main` i tak przycina — pustka
przeniosłaby się z prawej krawędzi na środek strony. Gorzej, nie lepiej.

---

## D-139 · Strona przepisu używa drugiej kolumny, ale NIE przez `<x-slot:rail>`

**Data:** 11 września 2026 · Issue #365 · Status: **obowiązuje**

### Sprawa

Prawa strona ekranu przepisu marnowała się pusta, bo strona nie miała szyny
w ogóle — a brak szyny zbijał ramę z 1424 px do ~1040 px. Naturalnym odruchem
było przenieść panel akcji do `<x-slot:rail>`.

### Dlaczego tego nie zrobiono

Slot renderuje się w kodzie **za** całym `<main>`. Na telefonie szyna ląduje pod
treścią — czyli „Ugotowałem", główna akcja produktu, zeszłoby pod składniki
i komentarze. `EkranPrzepisuWedlugKituTest` wymaga, żeby „Ugotowałem" stało
w kodzie PRZED „Składnikami", i ma rację.

### Rozwiązanie

`<main>` zajmuje obie kolumny, a panel przechodzi do drugiej siatką
`.przepis-uklad`. Efekt dla oka jest ten z issue, mechanizm inny.

Zmierzone, dane demo: gość przy 1920 px — wysokość strony 3872 → 3607 px,
treść 720 → 1104 px; zalogowany przy 1280 px — 4634 → 4303 px, treść 576 → 960 px;
„Ugotowałem" przy 1920 px przesuwa się z y=538 na **y=316**, i dalej jest pierwsze
w pasku akcji oraz pierwsze w kodzie.

Przy 200% czcionki wraca jedna kolumna, panel pod zdjęciem nad składnikami.

### Reguła ogólna

**Efekt wizualny nie jest powodem, żeby użyć konkretnego mechanizmu.** Slot
i siatka dają tu ten sam obraz na szerokim ekranie i różny na telefonie —
a telefon jest tym, na którym ta grupa czyta.

---

## D-140 · Dokumenty prawne nie mówią o sobie, że nie były sprawdzone przez prawnika

**Data:** 11 września 2026 · Zgłosił właściciel · Status: **obowiązuje**

### Zgłoszenie

„Usuń ze strony info że coś nie było weryfikowane przez prawnika… Przejrzyj wszystko".

### Co usunięto

Pięć wystąpień zdania „Dokument nie był weryfikowany przez prawnika"
w `resources/legal/regulamin.md` i `resources/legal/polityka-prywatnosci.md`.

### Dlaczego to nie jest ukrywanie prawdy

Ta nota nie była informacją o **usłudze** — była informacją o **procesie jej
powstawania**, adresowaną do nas samych. Czytelnikowi nie mówiła nic, co
mógłby wykorzystać, a podważała dokument, który ma być wiążący: regulamin,
który sam o sobie mówi, że nie wiadomo, czy jest poprawny, jest gorszy niż brak
regulaminu.

**Wszystkie zdania mówiące o faktach dotyczących usługi zostały nietknięte** —
także te niewygodne. Usunięta została wyłącznie nota o tym, kto dokumentu nie
czytał.

---

## D-141 · Wyścig o binarkę Composera usuwa własny katalog narzędzi per job, a nie kolejkowanie

**Data:** 11 września 2026 · Issue #262 · Status: **obowiązuje**

### Objaw

Job „Testy (PostgreSQL 18)" oblewał z kodem **126** bez ani jednego oblanego
testu: `…/setup-php/tools/composer: /usr/bin/env: bad interpreter: Text file busy`.
Ten sam commit lokalnie — komplet testów zielony.

### Diagnoza z samego issue była BŁĘDNA i warto to zapisać

Issue mówiło: „gdy dwa joby z RÓŻNYCH gałęzi startują w tej samej sekundzie".
Logi mówią co innego. `kuking-wsl-DOM-NEW-01`…`-03` to **trzy rejestracje na
jednej maszynie** (jedno `/home/mateusz`, jedno `/usr/local/bin`), a siedem jobów
tego workflow startuje równolegle. W przebiegu, na którym to złapano, pisał job
„Dostępność" na runnerze `-02`, a wykonywał job „Testy" na `-03` — **ten sam
przebieg i ta sama gałąź**.

To zmienia rozwiązanie: skoro biją się joby JEDNEGO przebiegu, żadna grupa
`concurrency` po `github.ref` ich nie rozdziela. Grupa wspólna dla wszystkich
przebiegów też nie — a przy tym trzyma jeden bieg działający i jeden oczekujący,
więc trzeci ANULUJE oczekującego. Zamieniłoby to losową czerwień na anulowane
joby i dłuższą kolejkę.

### Decyzja

Każdy job dostaje **własny** katalog na binarki narzędzi
(`$RUNNER_TEMP` + numer przebiegu + numer próby + nazwa joba). Nie ma już pliku,
do którego jeden job pisze, a drugi go wykonuje. **Wyścig znika konstrukcyjnie,
nie statystycznie.**

Katalog zakładamy sami, a nie zostawiamy tego akcji: akcja robi `sudo mkdir -p`,
więc katalog byłby rootowy, a rootowy katalog w `_temp` blokuje potem sprzątanie
katalogu roboczego przez runnera.

Koszt: Composer (~3 MB) pobiera się raz na job. Sekundy — i po nich CI przestaje
zależeć od tego, czy sąsiedni job właśnie nie podmienia binarki.

### Czego świadomie NIE zrobiono

**Ponowienia kroku.** Retry ukrywa wyścig, nie usuwa go — i uczy, że czerwone CI
się powtarza, a nie czyta. To jest ta sama zasada, którą trzymamy przy testach.

### Node tego nie potrzebuje i to jest ZMIERZONE, nie założone

`actions/setup-node` trzyma Node w `_work/_tool` **każdego runnera osobno**
(„Found in cache @ …/actions-runner-kuking-03/_work/_tool/node/22.23.2/x64"
w logu joba dostępności z 10 września), a jeden runner wykonuje jeden job naraz.
Wspólnej ścieżki dla Node'a tu nie ma.

---

## D-142 · Bramka R2 sprawdza z serwera to, co się da; pusta lista publicznych adresów to NIEPRZEJŚCIE, nie zieleń

**Data:** 11 września 2026 · Issue #120 · Status: **obowiązuje**

### Sprawa

Issue #120 zamknęło stronę aplikacyjną (własny sterownik `r2` bez ACL, osobne
buckety, dysk oryginałów bez klucza `url`), ale zostawiło **dwanaście punktów
do sprawdzenia ręcznie** na prawdziwym R2 — bo z PHP nie widać panelu Cloudflare.

Dwanaście ręcznych punktów to bramka, której nikt nie przejdzie dwa razy:
pierwszy raz z zapałem, drugi nigdy. A konfiguracja bucketu może się zmienić
bez jednej linijki w tym repozytorium.

### Decyzja

Komenda `kuking:bramka-r2` robi z serwera wszystko, co się da: pyta prawdziwe R2
prawdziwymi żądaniami i mówi po polsku, co z nich wyszło. Punkty, których
z serwera sprawdzić **nie da się** (wgranie zdjęcia z telefonu, kasowanie
z bazy), wypisuje na końcu jako pozostałe do zrobienia — zamiast udawać, że
ich nie ma.

### Najważniejsze: pusta lista adresów znaczy „NIE WIEMY", a nie „nic nie jest publiczne"

Issue żąda dowodu, że oryginał nie wyjdzie „przez KAŻDĄ publiczną ścieżkę":
własną domenę, `r2.dev` i endpoint konta. Z konfiguracji dawał się wyprowadzić
**jeden** z tych adresów — endpoint — bo klucz `url` został z dysków mediów
świadomie zdjęty, a domena i `r2.dev` żyją wyłącznie w panelu Cloudflare.

Bramka pytała więc o adres, którym nikt nie chodzi, milczała o adresie, którym
chodzi przeglądarka, i **świeciła na zielono**. Dokładnie ta klasa usterki,
przed którą sama ostrzega: narzędzie melduje sukces, oglądając co innego, niż
się wydaje.

Dlatego publiczne adresy trzeba bramce **zadeklarować**
(`KUKING_R2_PUBLICZNE_ADRESY`), razem z tymi, które mają być wyłączone.
Wyłączenie `r2.dev` jest udowodnione dopiero wtedy, gdy spod adresu `pub-….r2.dev`
przyszła odmowa. Pusta lista to nieprzejście.

Odmową jest `403`/`404`. `301` na inny host nie jest odmową — jest przekierowaniem
w miejsce, którego bramka nie sprawdziła.

### Granice, które komenda trzyma

Nie kasuje niczego i domyślnie nic nie zapisuje. `--zapis` dokłada JEDEN plik
tekstowy w prefiksie `bramka/` i kasuje go po sprawdzeniu — i mówi o tym
przed zrobieniem. Klucze API nigdy nie idą na wyjście, nawet fragmentami;
adresów podpisanych też nie wypisujemy w całości, bo sygnatura w podpisanym
adresie jest jednorazowym prawem dostępu do czyjegoś zdjęcia, a wyjście tej
komendy trafia do zgłoszeń i do dokumentacji.

---

## D-143 · Odtworzenie kopii jest udane przy DWÓCH warunkach naraz, nie przy jednym

**Data:** 11 września 2026 · Issue #9 · Status: **obowiązuje**

### Pytanie, na które trzeba było odpowiedzieć

„Po czym poznajemy, że kopia jest dobra, a odtworzenie się udało?" Bez odpowiedzi
próba odtworzenia jest rytuałem: skrypt się wykonał, więc chyba dobrze.

### Decyzja

Odtworzenie jest zaliczone, gdy `psql` kończy się kodem 0 **i** w odtworzonej
bazie stoi spodziewana lista obiektów. Sam kod wyjścia nie wystarcza.

Symetrycznie po drugiej stronie: **sonda zachowania** — zapis, który MUSI zostać
odrzucony — jest zaliczona, gdy `psql` kończy się **błędem** i w treści błędu
jest spodziewany napis. Znowu dwa warunki: sam niezerowy kod wyjścia potwierdziłby
także **literówkę w SQL-u samej sondy**, a taka „zielona" sonda dowodziłaby
dokładnie niczego.

To nie jest ostrożność na wyrost. Odtworzona baza może przyjąć wszystkie dane
i zgubić po drodze ograniczenie, które ich pilnowało — a wtedy pierwszy warunek
świeci na zielono, i dopiero drugi mówi prawdę.

### Czego próba nie zostawia po sobie

Każda sonda idzie w transakcji i kończy się `ROLLBACK`. Po sondzie w bazie
próbnej nie zostaje ani jeden wiersz — i to jest sprawdzane osobnym testem
(`tests/skrypty/proba-odtworzenia.sh`), a nie założone.

### Stan faktyczny w dniu tej decyzji

Produkcyjna baza ma **zero** kopii. Ten wpis mówi, po czym poznamy, że kopia jest
dobra — nie mówi, że jakaś jest.

---

## D-144 · Panel moderacji wchodzi do pomiaru dostępności Z DANYMI; pusty stan przechodzi każdy audyt

**Data:** 11 września 2026 · Issue #294 · Status: **obowiązuje** · rozwinięcie D-106

### Co przeoczyliśmy przez pół roku

Lista ekranów w `scripts/dostepnosc.mjs` miała `/zgloszenia` — czyli ekran
**zgłaszającego**. Adres wygląda podobnie, a to inna strona, inny układ i inna
rola. Przez to **najbardziej osobna warstwa układu w tym serwisie** — tryb panelu
(`.side-nav[data-tryb-panelu]`, własne reguły poniżej 64rem, własny pasek dolny
`.bottom-nav-panel`) — nie była mierzona **nigdy**: ani na przepełnienie
w poziomie, ani przy powiększonej czcionce.

### I drugi raz to samo, już wewnątrz poprawki

Zmierzone 11 września na świeżo wysianej bazie demo, przy 900 px:

| ekran | co stało na ekranie | węzłów w `<main>` |
|---|---|---|
| `/admin/uzytkownicy` | tabela czterech kont | 126 |
| `/admin/zgloszenia` | „Nic tu nie ma" | 19 |
| `/admin/sygnaly` | „Nic tu nie ma" | 18 |

Dwie z trzech kolejek panelu były mierzone jako **pusty stan**. A cała rzecz,
przez którą panel w ogóle wszedł do tego pomiaru — karta sprawy z formularzem
decyzji (`choice-grid`, dwa zestawy pól wyboru, pole terminu, lista podstaw
prawnych) i karta grupy automatu z paskiem podglądów — nie była na ekranie
ani razu.

`DemoSeeder` nie tworzy ani jednego zgłoszenia (sprawdzone: `grep -n 'Report::'`,
zero wyników).

### Decyzja

Automat **zakłada dane** przed pomiarem panelu i **twardo sprawdza, że wszedł**:
jeśli `/admin/uzytkownicy` nie odpowie `200` pod tym właśnie adresem, automat
przerywa z błędem mówiącym, że ekrany panelu nie zostałyby zmierzone. Cichego
„zmierzono ekran logowania" tu nie ma.

Sesja moderatora jest **osobną funkcją**, nie parametrem zwykłego logowania:
za formularzem stoją jeszcze dwa kroki, których nie ma żaden inny ekran w tym
automacie — włączenie weryfikacji dwuetapowej i twarde sprawdzenie, że panel
naprawdę się otworzył. 2FA moderatora **nie jest obchodzone** na potrzeby pomiaru.

### Punkt 900 px, a nie cała macierz

Między 768 a 1280 px była dziura, w którą wpada cała klasa urządzeń liczących
układ INACZEJ niż oba brzegi: telefon składany rozłożony (zgłoszenie przyszło
z Galaxy Fold), tablet postawiony poziomo i okno przeglądarki na pół ekranu
laptopa. Wszystkie trzy są szersze niż telefon, a mimo to poniżej progu 64rem —
czyli dostawały układ telefonu na szerokim ekranie, którego nikt nigdy nie
zobaczył w pomiarze.

Dołożony został **jeden** punkt, nie cała macierz: każdy punkt kosztuje czas
każdego przebiegu CI, a 900 px pokrywa te trzy przypadki naraz.

---

## D-145 · Dwukolorowy zapis `kuKING` obowiązuje wszędzie, także jako nazwa serwisu w tekście bieżącym

**Data:** 11 września 2026 · **Decyzja właściciela** (issue #392, B2) · PR #398 ·
Status: **obowiązuje** · zmienia D-009 i D-015

### Co zostało odwrócone

Dwie rzeczy zapisane wcześniej przestają obowiązywać w części o zapisie nazwy:

1. **limit „maksymalnie raz na ekran"** (D-009, `AGENTS.md` §11, `COPY_STYLE.md` §2);
2. **podział na rejestry z D-015** — do tej pory tekst ciągły pisał `Kuking`,
   a `kuKING` był zarezerwowany dla człowieka.

Od teraz `kuKING` w zapisie dwukolorowym — **„ku" w kolorze tekstu, „KING"
w kolorze marki** — stoi wszędzie, gdzie nazwa jest czytana jako nazwa:
w nagłówku, w tekście bieżącym, w nawigacji, w stopce, w zaproszeniu.

**Co z D-015 zostaje w mocy:** logotyp `KuKing.pl` jest znakiem i rządzi się
swoim prawem. Akcent koloru w logotypie leży dziś wyłącznie na „King" — „Ku"
i „.pl" biorą kolor tekstu (PR #394).

### Dlaczego limit „raz na ekran" nie został podniesiony, a usunięty

Sufit był liczbą, a liczba nie jest tym, co ta reguła chroniła — chroniła
**czytania**. Ekran z jednym żartem w komunikacie błędu jest gorszy niż ekran
z trzema w dobrych miejscach. Podbicie sufitu z 1 na 6 byłoby udawaniem reguły,
więc `test_gra_slowem_wystepuje_najwyzej_raz_na_ekranie` został **usunięty, nie
wyłączony**, i zastąpiony przez `test_nazwa_nie_powtarza_sie_w_jednym_bloku_tekstu`.

W miejsce limitu wchodzą **dwa kryteria**:

> **Charakter marki wolno tam, gdzie nie konkuruje z zadaniem.** Konkuruje,
> jeśli stoi między człowiekiem a przyciskiem, którego szuka; wydłuża zdanie,
> które ma być wykonane, nie przeczytane; opisuje ton zamiast podać informację;
> albo trzeba go zrozumieć, żeby pójść dalej.

> **W jednym akapicie, nagłówku albo punkcie listy nazwa pojawia się raz.** Nie
> dlatego, że dwa to „za dużo" — dlatego, że dwa dwukolorowe słowa w polu
> jednego spojrzenia migoczą, a to jest już koszt czytania.

### Pięć miejsc, w których nazwa zostaje zwykłym „Kuking"

„Wszędzie" ma granicę i to nie jest cofanie decyzji. Cztery z pięciu wyjątków
stały w D-009 i w `COPY_STYLE.md` §2 na długo przed tą decyzją i mówią o czymś
innym niż zasięg nazwy — **ta część D-009 zostaje w mocy**. Piąty bierze się
z tego, że dwukolorowości fizycznie tam nie ma.

| # | Gdzie | Dlaczego |
|---|---|---|
| 1 | `alt`, `title`, `aria-label`, `<title>`, `meta`, JSON-LD, temat listu, pliki eksportu, tekst tylko dla czytnika | **koloru tam nie ma**, a znacznik w atrybucie wypisze się dosłownie; wersaliki w środku wyrazu bez koloru czytają się jak literówka |
| 2 | błąd, moderacja, tekst prawny, ekran bezpieczeństwa, list techniczny | hierarchia tonu; zakaz jest bezwarunkowy i **starszy** niż ta decyzja — człowiek ma wtedy problem, nie ochotę na markę |
| 3 | powiadomienie o cudzej aktywności | „Halina — ugotowane z Twojego przepisu" jest doskonałe; to zdanie należy do Haliny, nie do marki |
| 4 | pole formularza, który ktoś właśnie wypełnia (etykieta, podpowiedź, walidacja) | tam marka konkuruje z zadaniem. Nagłówek **tego samego** ekranu wolno — „Zostań kuKINGiem" nad `/register` zostaje |
| 5 | tło w kolorze marki (przycisk podstawowy) | czerwień na czerwieni ma kontrast **1,00:1** i nie ma odcienia, który to naprawia; „KING" bierze kolor otoczenia, a nośnikiem zostają wersaliki (`kuking-word--bez-koloru`) |

### Kontrast — policzony, nie założony

„KING" jest pisane kolorem, więc jest **tekstem**, nie dekoracją: obowiązuje go
WCAG 2.2 AA, kryterium 1.4.3 (**4,50**). Zmierzone dla `--color-brand` =
`#B3401F` (motyw jasny) i `#F2986A` (ciemny):

| Tło | Jasny | Ciemny |
|---|---:|---:|
| `--color-surface` (strona) | 5,31 | 7,80 |
| `--color-surface-raised` (karta, stopka) | 5,72 | 6,92 |
| `--color-surface-sunken` (ramka, pole) | 4,83 | 8,49 |
| `--color-surface-brand-wash` (ciepły pas) | 4,83 | 7,03 |
| `--color-brand-tint` (podkład marki) | **4,64** | 6,55 |
| tło w kolorze marki (przycisk podstawowy) | **1,00** | **1,00** |

**Najciaśniej jest w motywie jasnym na `brand-tint`: 4,64 przy progu 4,50, czyli
zapas 0,14.** Jedno rozjaśnienie `--color-brand` ten zapas zabiera — dlatego
liczby stoją w teście, a nie tylko w dokumencie. Kontrola samego licznika
kontrastu (21:1, 1:1, symetria, para, która ma oblać) jest w tym samym pliku.

**Kolor nie jest jedynym nośnikiem znaczenia** (WCAG 1.4.1): słowo czyta się
identycznie bez koloru, bo grę niosą wersaliki.

### Gęstość po wdrożeniu — zmierzona, nie oszacowana

| Ekran | na stronę | z tego w stopce | na 1000 znaków | maks. w jednym akapicie |
|---|---:|---:|---:|---:|
| `/` | 6 | 2 | 2,6 | **1** |
| `/o-kuking` | 6 | 2 | 2,9 | **1** |
| `/odkryj` | 4 | 2 | 5,4 | **1** |
| `/home`, `/szukaj` | 4 | 2 | 3,4–4,0 | **1** |

Ani jeden akapit nie ma dwóch. Uczciwe zastrzeżenie do kolumny „na 1000 znaków":
licznik bierze też tekst dla czytnika ekranu — na stronie powitalnej 42 znaki
z 2336, czyli **1,8%** zaniżenia. Na wniosek to nie wpływa.

### Cena, wprost

Na przycisku podstawowym dwukolorowości **nie widać i widać nie może** — dotyczy
to także flagowego przycisku na stronie powitalnej. Jedyne wyjście, gdyby miała
być widoczna, to przycisk na tle `surface` (5,72:1); **nierozstrzygnięte, do
decyzji właściciela.**

Drugi koszt został zmierzony i naprawiony po drodze: pierwsza wersja reguły
łamania wyrazu użyła `white-space: nowrap` i **oblała skan dostępności** — na
`/register` przy oknie 320 px i czcionce przeglądarki 200% samo słowo brało
**315 px** zaczynając od x = 32, czyli strona przewijała się w bok o **27 px**
(naruszenie 1.4.10 Reflow). Obowiązuje `overflow-wrap: anywhere`: łamie wyraz
wyłącznie wtedy, gdy inaczej wyszedłby poza wiersz.

### Gdzie to stoi

Zapis żyje w jednym komponencie — `resources/views/components/kuking-word.blade.php`
— i nigdzie indziej; odmiana idzie atrybutem `forma`, a wersja dla czytnika
ekranu jest osobnym tekstem, bo `aria-label` na `<span>` czytniki **ignorują**
(specyfikacja „ARIA in HTML" zakazuje go na roli `generic`).

**Zmiana wymaga:** zmierzonej trudności w czytaniu u prawdziwych użytkowników
(#15) albo liczby 2 w kolumnie „maks. w jednym akapicie" — ta druga nie jest
decyzją do podjęcia w locie: test oblewa, a rozstrzyga właściciel.

📄 `docs/brand/GLOS_MARKI.md` §2 i §4 · `docs/brand/COPY_STYLE.md` §2 ·
`AGENTS.md` §11 · `tests/Feature/TekstyWedlugCopyStyleTest.php` · D-009 · D-015

---

## D-146 · Kuking nie ma reklam i nie pobiera opłat za korzystanie — na stałe

**Data:** 11 września 2026 · **Decyzja właściciela** (issue #392 §4, B1) · PR #398 ·
Status: **obowiązuje**

### Decyzja

> „w przyszłości jak bd chciał zarabiać to bardziej założę patreon albo coś żeby
> zbiórki robić na hosting"

**Za korzystanie z Kuking nikt nigdy nie płaci i w serwisie nie ma reklam.**
Przyszłe zarabianie: najwyżej dobrowolna zbiórka albo Patreon na koszty hostingu.

### Granica tej obietnicy — bez niej zostanie odczytana za wąsko albo za szeroko

„Bez opłat" znaczy „za korzystanie z Kuking nikt nigdy nie płaci", a nie „Kuking
nigdy nie sprzeda niczego".

| Zgodne z tą decyzją | Zakazane na stałe |
|---|---|
| dobrowolna zbiórka albo Patreon na koszty hostingu — nikomu nic nie odbiera | reklamy |
| wydrukowana książka rodzinna (`docs/product/SOUL.md` §4.3) — to produkt, nie opłata za wejście | płatny dostęp do cudzych przepisów |
| | funkcje odbierane za brak subskrypcji |

### Dlaczego to nie jest obietnica bez pokrycia

Zgadza się niezależnie z trzema rzeczami, które już są w repozytorium:

- `docs/research/MONETYZACJA.md` §6 — „nic poza opcjonalnym linkiem do
  dobrowolnego wsparcia kosztów hostingu… i to jest dopuszczalna, prawdopodobnie
  właściwa odpowiedź na tym etapie";
- ta sama analiza pokazuje, że reklama display żyje ze skali odsłon, której
  Kuking nie ma i długo nie będzie miał;
- po stronie odbiorcy: „strach o pieniądze i oszustwa" jest dominującą obawą tej
  grupy (`docs/research/AUDIENCE_50_PLUS.md` §3), a zalecenie brzmi wprost —
  „w MVP nic nie kosztuje i produkt to mówi wprost".

### Reguła, która z tego wynika i obowiązuje każdy przycisk

> **Na przycisku wolno napisać zobowiązanie, którego złamanie byłoby widoczne.
> Nie wolno zalety, której nikt nie sprawdzi.**

Dlatego przycisk na stronie powitalnej brzmi **„Zostań kuKINGiem — bez opłat
i bez reklam"**. Trzy odrzucone warianty i powody:

| Odrzucone | Dlaczego |
|---|---|
| „Zostań kuKINGiem — to darmowe" | brzmi sprzedażowo |
| „Zostań kuKINGiem — za darmo, na zawsze" | obietnica na przyszłość bez gwarancji |
| „Załóż darmowe konto" (propozycja audytu) | zdejmuje nazwę mieszkańca z jedynego miejsca, w którym się ona zaprasza |

„Bez reklam" przechodzi ten test, bo reklama w serwisie byłaby widoczna
następnego dnia. „To darmowe" go nie przechodzi — to ocena, nie zobowiązanie.

**Zmiana wymaga:** jawnej decyzji właściciela. Ta decyzja nie jest kalkulacją,
tylko granicą produktu — zmiany nie uzasadnia rachunek za hosting.

📄 `docs/research/MONETYZACJA.md` §6 · `docs/brand/GLOS_MARKI.md` §5 ·
`docs/brand/COPY_STYLE.md` §6 · D-131 · D-145

---

## D-147 · Czasownik od `kuKING` wolno użyć tylko tam, gdzie obok stoi zdanie, które go tłumaczy

**Data:** 11 września 2026 · **Decyzja właściciela** (issue #392) · PR #398 ·
Status: **obowiązuje** · zmienia D-009 w części odrzucającej czasownik

### Co się zmienia

D-009 odrzuciło `kuKINGujesz` z uzasadnieniem „nowy czasownik wymaga
zrozumienia, a nasz odbiorca nie lubi zgadywać". Właściciel tę część odwrócił:
**czasownika wolno używać.** Odrzucony argument nie znika — przestaje być
zakazem, a staje się warunkiem.

| Gdzie | Czasownik | Dlaczego |
|---|---|---|
| hasło, nagłówek sekcji, digest, zaproszenie | ✅ „Dziś kuKINGujemy z resztek" | obok stoi zdanie, które tłumaczy; nic nie zależy od zrozumienia słowa |
| nawigacja | ❌ | nawigacja ma być przewidywalna, nie dowcipna (D-009, ta część zostaje) |
| jedyny przycisk realizujący akcję | ❌ „kuKINGuj to" zamiast „Opublikuj" | przycisk mówi, co robi — `AGENTS.md` §5 |
| błąd, moderacja, prawo, bezpieczeństwo | ❌ | hierarchia tonu, zakaz bezwarunkowy |

Warunek jest oparty na liczbie: **w grupie 65–74 lata tylko 12,3% osób ma według
unijnej metodologii podstawowe umiejętności cyfrowe**
(`docs/research/AUDIENCE_50_PLUS.md`). Taki czytelnik potrafi przejść ścieżkę
wyuczoną, a nie poradzić sobie z nową — i nie ma zgadywać, co znaczy słowo
stojące na **jedynej** drodze do celu.

### Granica, która się nie rusza: żart jest o nazwie serwisu, nigdy o użytkowniku

| | O czym jest zdanie | Ocena |
|---|---|---|
| „Zostań kuKINGiem" | o nazwie | ✅ |
| „Witaj w gronie kuKINGów" | o przynależności | ✅ |
| „2 431 kuKINGów" | o liczbie ludzi tutaj | ✅ |
| „Jesteś prawdziwym kuKINGiem!" | o użytkowniku, komplementem | ❌ |
| „Top kuKINGi tygodnia" | o hierarchii | ❌ |
| „Zdobądź poziom kuKING" | o nagrodzie za coś | ❌ |

### Powód trzech ostatnich jest produktowy, nie estetyczny — i jest zmierzony

Własne zdjęcie lub film zamieściło w ostatnim miesiącu **17% internautów 55–64
i 13% z 65+**, przy 70% i 61% rozmawiających przez komunikator
(`docs/research/AUDIENCE_50_PLUS.md`). `COPY_STYLE.md` §2 dokłada, że **ponad
połowa osób 50+ w mediach społecznościowych nigdy nic nie publikuje**.

Ci ludzie zdjęcia **wysyłają**, tylko ich nie **publikują** — i to jest jedyny
nawyk, który ten produkt ma zmienić. Stąd asymetria, o której cała ta decyzja:
**komplement za publikację podnosi poprzeczkę u ludzi, którzy jej nie
przeskakują. Nazwa przynależności ją obniża — wystarczy tu być.** „Top kuKINGi
tygodnia" dokłada do tego ranking, którego `AGENTS.md` §12 zabrania, a nazwa
przynależności użyta jako wyróżnienie dzieli ludzi na dwie klasy.

Formy żeńskiej nie tworzymy i to się nie zmienia; zdanie wymagające formy,
której nie używamy (`kuKINGowi`, `kuKINGu`, `kuKINGowie`), **przepisujemy**
zamiast odmieniać słowo na siłę.

**Zmiana wymaga:** reakcji prawdziwych użytkowników w testach (#15) — ten sam
warunek, który postawiło D-009.

📄 `docs/brand/GLOS_MARKI.md` §1 · `docs/brand/MASCOT_CONCEPT.md` ·
`docs/brand/COPY_STYLE.md` §8 · D-009 · D-145

---

## D-148 · Rejestr tekstów zmienia się z tłumaczącego się na zapraszający — zdań nie wycinamy, przepisujemy

**Data:** 11 września 2026 · **Decyzja właściciela** (issue #392, grupa C1–C7) ·
PR #398 · Status: **obowiązuje**

### Co audyt chciał zrobić, a co robimy

Audyt copy (`docs/research/audyt-copy-2026-09-11/`) wskazał zdania uspokajające
i chciał je **wycinać**. Rozstrzygnięcie jest inne: **zamieniamy rejestr**, bo
problemem nie jest objętość tych zdań, tylko to, że tłumaczą się zamiast
zapraszać.

```text
❌ Wrzucasz zdjęcie i kilka słów. Nic więcej nie musisz.
✅ Wrzuć zdjęcie i kilka słów, a pokażesz je komuś, kto dziś też gotował.
```

Uwaga na drugą stronę tego samego kija: **zapraszający to nie sprzedażowy.**

### Najmocniejszy przypadek: cztery zapewnienia, że odpisuje człowiek (C2)

Na `/napisz-do-nas` to samo mówiły cztery miejsca naraz: prawa szyna („Lepiej
dwa razy niż wcale"), pierwszy akapit („Po drugiej stronie jest człowiek, nie
automat"), ramka o zgłaszaniu („To jest inna droga i prowadzi do innej kolejki")
i dół strony („nie mamy całodobowego dyżuru i nie będziemy go udawać").

Właściciel: *„nie ma co naciskać że to człowiek, bo wtedy ludzie będą mieć
odwrotne odczucie"*.

**Zapewnianie czterokrotnie, że po drugiej stronie jest człowiek, brzmi jak
zaprzeczanie zarzutowi, którego nikt nie postawił** — i uruchamia dokładnie to
podejrzenie, które miało uśpić. Ten sam mechanizm co w zdaniu „to naprawdę nie
jest oszustwo": raz powiedziane jest ciepłe, cztery razy jest tłumaczeniem się.
Zostaje **jedno** miejsce — to na dole, bo tam za zdaniem stoi konkret: jedna
osoba, brak całodobowego dyżuru, odpowiedź czasem po weekendzie.

To spostrzeżenie jest cenniejsze niż zarzut o powtórzenia, od którego audyt
zaczynał: powtórzenie da się policzyć, a **efekt odwrotny do zamierzonego trzeba
zrozumieć**.

### C6 — piszemy o realnym życiu tej grupy, nie o abstrakcji

```text
❌ na wspólnym albo cudzym urządzeniu     abstrakcja, brzmi podejrzliwie
✅ u rodziny czy znajomych                realny scenariusz tej grupy
```

**„Cudze urządzenie" to język regulaminu.** Rodzina jest głównym przewodnikiem
po technologii w tej grupie (`docs/research/AUDIENCE_50_PLUS.md` §3 i §6) —
nazwanie tego po imieniu nie jest protekcjonalne. Protekcjonalne jest pisanie
*o* starszej osobie zamiast *do* niej.

### Pozostałe pięć reguł

- **C1 — zapraszaj, nie uspokajaj.** Marka nie jest kosztem do zmniejszenia.
- **C3 — instrukcja zamiast stylu autora.** „Klikasz — i jesteś w środku" →
  „Kliknij go, żeby wejść na konto". Sprzedaż wychodzi z tekstu szybciej, niż
  się ją tam wkłada.
- **C4 — nie tłumacz, czym ten krok NIE jest.** Jeśli krok jest opcjonalny,
  **postaw „Pomiń"** — przycisk powie to lepiej niż zdanie o przycisku.
  Konstrukcja „jedno i drugie jest w porządku" / „to też jest w porządku" stała
  w serwisie **trzy razy** (`/pomoc`, `/dodaj`, koniec onboardingu); zostaje
  w **jednym**, tym wskazanym przez właściciela jako wzór.
- **C5 — mniej szczegółu, więcej luzu.** „po kolei, od najnowszego. Bez żadnego
  układania przez komputer" → „po kolei, od najnowszego". Antytechnologiczny
  wtręt tłumaczy technologię komuś, kto o nią nie pytał, i sugeruje, że gdzieś
  indziej jest wróg.
- **C7 — „nic nie" zostaje, jeśli niesie informację.** Audyt policzył
  **36 wystąpień w 27 widokach ze 137** — to maniera, nie przypadek. Ale
  kasowanie hurtem jest błędem w drugą stronę:

| Zostaje | Idzie |
|---|---|
| „nic nie zginie" przy autozapisie — mówi, co robi mechanizm | „Nic nie musisz robić dalej" — nie mówi nic |
| „jeśli nic nie zaznaczysz" — opisuje skutek wyboru | „nic nie zostało zamknięte na stałe" obok zdania, które to już powiedziało |
| „nigdy nic nie napiszemy na Twojej tablicy" — konkretne zobowiązanie | „albo nic nie pisz, to też jest w porządku" — trzecia kopia tej samej konstrukcji |

### Wzór, do którego się odwołujemy

```text
Choćby jedno zdanie. Pytanie do autora też jest w porządku.
```

Pierwsze zdanie zdejmuje presję **objętości**, drugie presję **treści**,
i **żadne nie mówi, JAK pisać** — a poprzednia wersja („Napisz normalnie, po
ludzku") mówiła, i powtarzała słowo z etykiety pola.

```text
❌ Napisz normalnie, po ludzku.        mówi, JAK pisać
✅ Choćby jedno zdanie.                mówi, ILE wystarczy
```

📄 `docs/brand/GLOS_MARKI.md` §5 · `docs/research/audyt-copy-2026-09-11/` ·
D-145 · D-149

---

## D-149 · Tekst dla człowieka nie uzasadnia własnego brzmienia — na ekranie tak samo jak w dokumencie prawnym

**Data:** 11 września 2026 · Audyt copy, `docs/research/audyt-copy-2026-09-11/` ·
PR #395 i #396 · Status: **obowiązuje** · rozwinięcie D-140

### Zasada

> **Zdanie o fakcie dotyczącym usługi zostaje — także niewygodne. Znika zdanie
> o procesie pisania tego tekstu i o naszym toku rozumowania.**

D-140 zdjęło z dokumentów prawnych notę o tym, kto dokumentu nie czytał:
informację o **procesie powstawania**, adresowaną do nas samych. Ta sama choroba
chodziła po całym interfejsie, tylko w innych słowach — i stąd rozszerzenie
zasady na cały tekst, który czyta człowiek.

### Co usunięto i z czego to zostało

**W dokumentach prawnych** (`resources/legal/*`): zdania tłumaczące, skąd wiemy
to, co piszemy, i dlaczego uważamy to za uczciwe.

| było | jest |
|---|---|
| „Nie zapisuje niczego na Twoim urządzeniu… Sprawdziliśmy to, czytając ten skrypt **linijka po linijce, a nie wierząc na słowo**…" | „Nie zapisuje niczego na Twoim urządzeniu — ani pliku cookie, ani nic w pamięci przeglądarki, więc nie ma czym Cię oznaczyć." |
| „**Uważamy, że nie ma to prawa tak zostać, i mówimy dlaczego.**…" | „**Tego liczenia otwarć nie da się wyłączyć z naszego kodu** — jest ustawieniem konta u dostawcy… Do tego czasu obrazek jedzie w każdym liście." |
| „…nie podajemy tu liczby godzin, bo **nie mamy dziś w serwisie nic, co ten termin mierzy i pilnuje**." | „Odpowiadamy bez zbędnej zwłoki, a sprawy poważne bierzemy pierwsze. Nie obiecujemy konkretnej liczby godzin." |

Zdjęte także: „i mówimy to **wprost**" (×3), „**Uczciwie** o granicy…",
„Opisujemy to, bo zachodzi", odesłanie do „wewnętrznego dokumentu
bezpieczeństwa", którego czytelnik nie ma, oraz to samo twierdzenie
o Cloudflare powtórzone trzy razy w jednym dokumencie.

**Na czterech ekranach** to samo w wersji produktowej: opis dla wyszukiwarki na
spisie tematów mówi, co na stronie jest, zamiast **jak ją sortujemy** (reguła
kolejności bez zmian i nadal widoczna na stronie); z `/o-kuking` zeszły trzy
zaprzeczenia zarzutom, których nikt nie postawił; z potwierdzenia wysłanej
wiadomości zeszło „nie zginie, nawet gdyby akurat nie działała poczta" — prawda
o naszej architekturze, ale podsuwa myśl, że poczta bywa nieczynna.

### Co zostało nietknięte — i to jest połowa tej zasady

Wszystko niewygodne: brak podpisanych umów powierzenia, brak inspektora ochrony
danych, nieustalony okres życia danych w kopiach zapasowych, sekcje „Źródła",
„Tego nie da się odwrócić". Zostało też „ludzi, którzy **naprawdę** gotują" —
to hasło serwisu, nie retoryka.

### Jak to jest pilnowane, żeby nie zamieniło się w zakaz słowa

Skan wzorców samouzasadniania (`DokumentyPrawneNieKlamiaTest`) ma kontrolę
**dwustronną**: **15 zdań wziętych dosłownie z `main`, które muszą oblewać,
i 18 zdań, które muszą przechodzić** — z trzema chronionymi zdaniami o brakach
na czele oraz z „ludzi, którzy naprawdę gotują". Ta kontrola od razu się
przydała: pierwsza wersja wzorca `wewnętrzn* dokument*` **przepuszczała zdanie,
które miała łapać**, bo odmiana „dokumen**cie**" nie pasowała do rdzenia.

Kontrola ujemna na całości: po cofnięciu `resources/legal/` — **3 padnięcia
z 26**, każdy z trzech dokumentów oblewa z wypisanymi cytatami. Po przywróceniu:
**26/26**.

**Zmiana wymaga:** niczego. Ta zasada nie jest sądem o stylu — jest odpowiedzią
na pytanie, po co czytelnik przyszedł.

📄 `docs/brand/COPY_STYLE.md` ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` · D-140 · D-150 · D-152

---

## D-150 · Dowód z audytu nie jest treścią dokumentu prawnego

**Data:** 11 września 2026 · Audyt copy, `docs/research/audyt-copy-2026-09-11/` ·
PR #395 · Status: **obowiązuje** · rozwinięcie D-149

### Co stało w polityce prywatności

> „Sprawdziliśmy to **9 września 2026** na prawdziwym liście doręczonym do
> skrzynki, czytając jego **surowe źródło**, a nie wierząc na słowo."

Zdanie prawdziwe, konkretne, z datą — i w dokumencie prawnym **nie na miejscu**.
Zostało usunięte; zostało to, co dostawca rejestruje, czym to robi i że my tego
nie odczytujemy.

### Dlaczego to nie jest ukrywanie dowodu

Dowód i dokument mają dwóch różnych czytelników. **Dowód z audytu jest
adresowany do nas** — mówi, że sprawdzenie zostało wykonane, i chroni nas przed
powtórzeniem pracy. **Dokument prawny jest adresowany do czytelnika** i ma mu
powiedzieć, co się dzieje z jego danymi. Wpisany do dokumentu dowód robi trzy
szkody naraz:

1. czytelnik nie ma czym go zweryfikować, więc nie dostaje informacji, tylko
   zapewnienie;
2. **ma krótszy okres przydatności niż dokument** — data „9 września" starzeje
   się, a akapit o danych nie; dokument zaczyna po cichu mówić nieprawdę o samym
   sobie;
3. sugeruje, że reszta dokumentu sprawdzona nie była, bo przy niej takiego
   zdania nie ma.

Punkt 2 nie jest teoretyczny: **dwie z trzech usuniętych not o lukach były już
nieaktualne** — dane spółki weszły do regulaminu 8 września, EmailLabs do
polityki 10 września, a noty stały dalej.

### Gdzie dowód mieszka zamiast tego

W opisie Pull Requesta, w `docs/research/`, w komentarzu przy kodzie i w teście.
**Test jest lepszym dowodem niż zdanie w dokumencie**, bo starzeje się na
czerwono, a zdanie starzeje się cicho.

### Przy okazji, poprawka merytoryczna z tego samego przeglądu

Regulamin §8 mówił „przez pierwsze 24 godziny nie można potwierdzić własnego
rozstrzygnięcia", a kod (`ResolveAppeal::sprawdzKarencje()`,
`kuking.moderation.appeal_self_uphold_hours`) liczy 24 h **od pierwotnej
decyzji**, nie od złożenia odwołania. Zdanie mówi teraz to, co robi kod. Klasa
usterki jest ta sama: dokument mówił o czymś, czego nie sprawdził przy kodzie.

📄 `resources/legal/` · `docs/research/audyt-copy-2026-09-11/` · D-149 · D-140

---

## D-151 · Obietnica o układzie ekranu wymaga pomiaru dokładnie tak samo jak obietnica o czasie

**Data:** 11 września 2026 · PR #396 · Status: **obowiązuje** · rozwinięcie D-114

### Zdanie, które było nieprawdą

`resources/views/pages/recipes/szczegoly.blade.php` mówił:

> „Wszystko jest na jednej stronie — **nie musisz nic przewijać ani szukać**."

### Ile naprawdę trzeba przewijać

Zmierzone w Chromium wzorcem z `scripts/dostepnosc.mjs`, zalogowany przez
prawdziwy formularz, adres `/przepisy/{slug}/edycja`. Skrypt sprawdza, że `h1`
to faktycznie „Dopisz szczegóły" — żeby nie zmierzyć strony błędu:

| stan przepisu | okno 360 px | okno 1280 px |
|---|---:|---:|
| sam tytuł, 3 puste wiersze składników i kroków | **10 249 px** = 16 ekranów | **7 836 px** = 9,8 ekranu |
| 8 składników i 6 kroków | **16 586 px** = 25,9 ekranu | **13 065 px** = 16,3 ekranu |

Kontrolek renderuje się **40** (stan pusty) do **70** (wypełniony).

### Dlaczego to jest ta sama sprawa co D-114

D-114 zabroniło pisać „zajmuje minutę" bez mechanizmu albo pomiaru, który to
pokrywa. **Obietnica o układzie ekranu jest obietnicą z miarą — tylko miarą jest
piksel, a nie sekunda.** „Nie musisz nic przewijać" mówi o wysokości strony
i liczbie kontrolek; jedno i drugie da się zmierzyć w minutę, i jedno i drugie
mówiło coś innego niż zdanie. To nie jest „AI voice" — to **zdanie
nieprawdziwe**, w tej samej klasie co ekran logowania obiecujący temat
wiadomości, której część ludzi nie dostanie.

Człowiek, któremu obiecano brak przewijania, a przewija 26 ekranów, nie myśli
„ładny copywriting". Myśli, że serwis nie mówi prawdy — i przy grupie 50+
kosztuje to od razu.

### Co zostało napisane zamiast

> „Wszystko jest na jednej stronie. Nic tu nie jest obowiązkowe: wypełnij tyle,
> ile chcesz, i zapisz. Poprawnie wpisane dane nie zginą."

**Obietnica znikła, cała informacja została.** „Na jednej stronie" zostaje, bo to
prawda i odróżnia ten ekran od kreatora w trzech krokach.

### Gdzie stoją liczby

W komentarzu Blade nad tym akapitem — żeby następna osoba, która chce dopisać to
zdanie z powrotem, przeczytała najpierw pomiar. Strażniki: dwa nowe pliki,
**13 testów**, wszystko na wyrenderowanym HTML-u; przy każdej asercji „czegoś
nie ma" stoi kontrola dodatnia, żeby test nie przechodził dlatego, że strona się
nie wyrenderowała.

| sabotaż | wynik |
|---|---|
| przywrócone „nie musisz nic przewijać ani szukać" | **CZERWONE** — 2 testy, komunikat z liczbami z pomiaru |
| usunięte przy okazji zdanie o nieobowiązkowości | **CZERWONE** — 2 testy: „Zniknęła informacja, że żadne pole nie jest wymagane" |

Druga kontrola nie jest ozdobą: przy zdejmowaniu nieprawdziwej obietnicy
najłatwiej zabrać razem z nią informację, którą ta obietnica niosła.

**Zmiana wymaga:** ekranu, który naprawdę mieści się bez przewijania
w zmierzonym stanie. Wtedy zdanie wraca — z pomiarem.

📄 `docs/brand/COPY_STYLE.md` · `scripts/dostepnosc.mjs` · D-114 · D-099 · D-106

---

## D-152 · Powód naszej decyzji nie stoi przy kontrolce, której dotyczy

**Data:** 11 września 2026 · PR #396 · Status: **obowiązuje**

### Dwa zdania z ekranu „Twoje dane"

Na ekranie usuwania konta, przy haczyku wybierającym zakres usunięcia, stało:

> „Skasowanie tego zabrałoby coś ludziom, którzy o nic nie prosili."

> „Tego nie da się odwrócić. **Dlatego haczyk jest domyślnie pusty** —
> skasowanego tekstu nikt już nie przywróci."

### Co z nimi było nie tak

Pierwsze zdanie mówiło człowiekowi, **co byłoby nie w porządku, gdyby wybrał
drugą opcję** — na ekranie, na którym ma właśnie wybrać. To nie jest informacja
o skutku; to ocena wyboru przed jego dokonaniem.

Drugie mówiło, **czemu tak zrobiliśmy**. Że haczyk jest pusty, człowiek widzi
w formularzu dwa akapity niżej — a powód nie jest jego sprawą w tej sekundzie.

### Zasada

> **Uzasadnienie decyzji produktowej mieszka w `docs/DECISIONS.md`, nie przy
> kontrolce.** Przy kontrolce stoi: co się stanie, czego nie da się odwrócić
> i co zrobić, jeśli człowiek chce inaczej.

Powód domyślnego zakresu usunięcia jest decyzją **D-022** i stoi tam, gdzie ma
stać. Ekran ma wykonać wybór, nie obronić go.

### Co zostało

Wszystkie fakty: trzy listy mówiące, co dokładnie zostaje, a co znika; „Tego nie
da się odwrócić"; „usuń je samodzielnie, zanim skasujesz konto: później nie
będzie już jak, bo do usuniętego konta nie da się zalogować"; „warto najpierw
pobrać swoje dane". Że przepis może być w cudzym zeszycie, mówi lista niżej —
czyli ta sama informacja co w usuniętym kazaniu, tylko jako fakt.

Usunięte zdania stoją w komentarzu Blade razem z powodem usunięcia, żeby nie
wróciły jako „brakowało czegoś ciepłego".

### Dlaczego to nie jest ta sama reguła co D-149

D-149 dotyczy zdania mówiącego o **sobie** („napisaliśmy to tak, bo…"). Ta
dotyczy zdania mówiącego o **naszej decyzji produktowej** w miejscu, w którym
człowiek podejmuje **swoją**. Można złamać jedną, nie łamiąc drugiej, i dlatego
stoją osobno.

📄 `resources/views/pages/settings/data.blade.php` · D-022 · D-149

---

## D-153 · Nie doklejamy przyimka ani słowa niosącego przypadek do cudzego tekstu ani do nazwy konta

**Data:** 11 września 2026 · Zgłosił właściciel · PR #397 · Status: **obowiązuje**

### Zgłoszenie

> „czemu źródło przepisu ma «po» przed źródłem?"

Na zrzucie: sekcja **„Skąd ten przepis"**, a pod nią zdanie **„Po Nasze smaki."**

### Zasada

> **Nigdy nie doklejaj przyimka ani słowa niosącego przypadek do tekstu
> wpisanego przez człowieka ani do nazwy wyświetlanej konta.** Polskiej odmiany
> nie da się policzyć z dowolnego ciągu znaków, a każda próba kończy się
> zdaniem, które wygląda na zepsute oprogramowanie.

### Co było zepsute — sprawdzone przy plikach, nie domyślone

| Miejsce | Wejście | Co widział człowiek |
|---|---|---|
| `pages/recipes/show.blade.php` | `Nasze smaki` | **Po Nasze smaki.** |
| `pages/recipes/show.blade.php` | `po mamie` | **Po po mamie.** |
| `components/recipe-wizard.blade.php` (podgląd) | to samo | to samo |
| `app/Models/Recipe.php` `attributionLine()` | `Nasze smaki` + konto `Krzysztof` | **przepis Nasze smaki, spisany przez Krzysztof** — dwa błędy odmiany naraz |
| to samo, bez źródła | konto `Krzysztof` | **przepis Krzysztof** |
| formularze | — | etykieta „Po kim ten przepis", podpowiedź `po mamie, Halinie` — **formularz prosił o formę, której widok i tak nie umiał użyć** |

### Rozwiązanie idzie w PYTANIE, nie w mechanizm odmiany

1. **Pole pyta o frazę, która stoi samodzielnie.** „Po kim ten przepis" →
   **„Od kogo albo skąd masz ten przepis"**, podpowiedź
   `od mamy · z gazety · z bloga Nasze smaki`. Odpowiedź na **to** pytanie
   działa i sama („Od mamy."), i po słowie „przepis".
2. **Widok pokazuje wartość dosłownie**, pod nagłówkiem „Skąd ten przepis" —
   nagłówek niósł to znaczenie od początku, przyimek był powtórzeniem. Pierwsza
   litera przez `Str::ucfirst()` (wielobajtowe), więc „od mamy" wygląda jak
   zdanie także przy „ó", „ż", „ś". **Kropki nie doklejamy** — przy wpisanej
   wyszłyby dwie.
3. **Podpis nie odmienia niczego.** Autor zostaje w linii, ale **w mianowniku,
   w osobnym członie po „·"**, nigdy po „przez":

```text
ze źródłem:  {nazwa konta} · skąd ten przepis: {wartość pola}
bez źródła:  {nazwa konta}
```

Dwukropek zdejmuje wymaganie przypadku, więc „od mamy", „Nasze smaki" i
„z gazety Przyjaciółka" działają jednakowo.

### Czego świadomie nie zrobiono: migracji danych

Kto wpisał „po mamie" pod starym pytaniem, zobaczy „Po mamie" — czyli
poprawniej niż „Po po mamie." Kto wpisał samo imię, zobaczy „Halina" pod
nagłówkiem „Skąd ten przepis" zamiast „Po Halina." **Żadna automatyczna zamiana
wolnego tekstu nie jest bezpieczna — a to jest dokładnie ta sama pułapka,
o którą chodzi w całej tej decyzji.**

### Test na wrogich danych

`tests/Feature/ZrodloPrzepisuBezPrzyimkaTest.php` — **5 testów, 135 asercji** —
chodzi na `od mamy`, `Nasze smaki`, `z gazety Przyjaciółka`, `Halina` oraz na
dwóch nazwach kont: `Krzysztof` i `Żaneta` (odmienia się inaczej niż męskie
imię). Kontrola ujemna wykonana naprawdę: po przywróceniu starego kodu **4 z 5**
testów czerwone; piąty (o pytaniu w formularzu) przy tym sabotażu przechodził,
bo go nie dotyczył, więc dostał **własny** sabotaż — starą etykietę — i wtedy
też oblał.

📄 `app/Models/Recipe.php` · `docs/brand/COPY_STYLE.md` · `docs/product/SOUL.md` ·
`tests/Feature/ZrodloPrzepisuBezPrzyimkaTest.php`

---

## D-154 · Odstęp między blokami należy do JEDNEJ strony pary — w rytmie artykułu do `margin-top`

**Data:** 11 września 2026 · Zgłosił właściciel · PR #400 · Status: **obowiązuje**

### Zgłoszenie

> „a propos przepisw, trzeba naprawić te odstępy między tekstami w przepisach"

### Co było zmierzone

Trzynaście par bloków na `/przepisy/{slug}`, w trzech stanach: okno
**1512 px**, okno **400 px** i okno 1512 px przy czcionce przeglądarki **200%**.
**Pięć par stało dosłownie na zero pikseli**, a dwie miały różny odstęp na
telefonie i na desktopie:

| Para bloków | 1512 px | 400 px | 1512 px + 200% |
|---|---:|---:|---:|
| `<h1>` → wiersz autora | **0** | **0** | **0** |
| zdjęcie → plakietki | **0** | **0** | **0** |
| „Skąd ten przepis" `<h2>` → 1. akapit | **0** | **0** | **0** |
| „Składniki" `<h2>` → lista | **0** | **0** | **0** |
| „Przygotowanie" `<h2>` → lista kroków | **0** | **0** | **0** |
| wiersz autora → zdjęcie | 36 | 20 | 72 |
| wstęp → siatka składniki/kroki | 36 | 20 | 72 |

Po zmianie żadna para nie stoi na zerze, każda para bloków artykułu ma **tę samą
liczbę w siatce i poza nią** (24 px przy 1512 i przy 400 px, 48 px przy 200%),
a przepis ubogi — bez zdjęcia, bez plakietek, bez komentarzy — dostaje
**24 / 24 / 24 / 24 / 24 px** bez dziury po pustej liście plakietek.

### Cztery przyczyny, każda inna

1. **Reset Tailwinda zeruje marginesy nagłówków**, a `.app-main > h1` naprawia to
   na ~50 podstronach, ale nie tutaj, bo `<h1>` przepisu siedzi
   w `<article><header>`, nie wprost w `<main>`.
2. **`margin: 0` na `.recipe-facts` zjadało rytm `.stack`** — ta sama swoistość
   (0,1,0), dalsze miejsce w pliku.
3. **Marginesy raz się zlewają, a raz sumują.** Poniżej 80rem `<article>` jest
   blokiem: `mb-4` wiersza autora zlewał się z `margin-top` zdjęcia do 20 px. Od
   80rem ten sam `<article>` jest **siatką**, a marginesy elementów siatki się
   nie zlewają — więc 16 + 20 = 36 px. **Ta sama strona miała dwa różne odstępy
   zależnie od szerokości okna, czego nie widać, dopóki się nie zmierzy obu.**
4. **Klasy `mb-2` / `mt-0` / `mb-4` w szablonie.** Utility w Tailwindzie 4 leży
   w warstwie stojącej **po** `components`, więc dopóki tam były, żadna reguła
   arkusza nie mogła ich poprawić.

### Decyzja

> **Odstęp między dwoma blokami należy do jednej strony pary. Druga strona jest
> wyzerowana — jawnie, tą samą regułą.**

W rytmie artykułu to `margin-top`:

```css
.przepis-uklad > *      { margin-bottom: 0; }
.przepis-uklad > * + *  { margin-top: var(--spacing-6); }
```

`margin-bottom: 0` na dzieciach **jest częścią tej reguły, nie ozdobą**: bez
niego dolny margines dziecka raz się zlewa (blok), a raz sumuje (siatka od
80rem) — czyli wraca przyczyna nr 3.

Wewnątrz jednego bloku odstęp należy do góry pary tak samo konsekwentnie, tylko
realizuje go `margin-bottom` **z wyzerowanym ostatnim dzieckiem**
(`.przepis-uklad > header > :last-child`, `.recipe-story > :last-child`) — bo
tam odstęp od bloku do bloku należy już do rytmu artykułu wyżej. **Jedna para,
jedna strona, zawsze zadeklarowana** — mieszanie stron w jednym zakresie jest
tym, co dało pięć zer i dwie różne liczby na jednej stronie.

### Odstępy z tokenów, a nie z pikseli

To są odstępy **między blokami tekstu**, więc mają rosnąć razem z pismem: przy
czcionce przeglądarki 200% `--spacing-6` to 48 px, nie dalej 24. To druga strona
D-082 i D-107 — **tamte minima są fizyczne** (palec nie rośnie od powiększenia
czcionki), **ten odstęp jest typograficzny.** Osobny test pilnuje, żeby
`--spacing-3/4/5/6` zostały w `rem`: w pikselach asercje o tokenach dalej by
przechodziły, sprawdzając nic.

### Dwa jawne wyjątki i jeden cudzy obszar

- **`.danger-zone` zachowuje `--spacing-8`** (32 px): `AGENTS.md` §5 wymaga, żeby
  akcja destrukcyjna była odsunięta od zwykłych.
- **`.notice` zostaje przy wspólnym `--spacing-5`**: dopisanie go tu poprawiłoby
  rytm przepisu kosztem komponentu widocznego na kilkunastu innych ekranach.
- **`.komu-wyszlo-naglowek` ma 4 px** między `<h2>` i paskiem liczb, gdy pasek
  zejdzie pod nagłówek (zmierzone na 400 px). To też jest zlepione, ale to
  świadoma decyzja z `karta-ugotowania.css` i cudzy obszar — **zgłoszone jako
  obserwacja, nie zmienione przy okazji.**

### Selektory strukturalne, nie pozycyjne

`> header`, `> * + *`, `.recipe-story > p` — nie `:nth-of-type`. Pusta lista
plakietek znika z układu przez `:not(:has(li))`, **nie `:empty`** — Blade
zostawia w `<ul>` znaki nowej linii, a te są węzłami tekstowymi; sprawdzone
w Chromium: reguła z `:empty` nie zadziałała ani razu.

### Kontrola ujemna złapała dwie wady samego testu

Wzorzec `<header>…</header>` trafiał w **belkę serwisu**, nie w nagłówek
przepisu, więc test przechodził także z przywróconymi klasami utility.
A `preg_match_all` zjada `}` razem z dopasowaniem, więc kotwica „reguła musi
stać po `}`" łapała **co drugą regułę** (200 zamiast 407). Dopiero po obu
poprawkach każdy z czterech sabotaży zaświecił na czerwono.

📄 `resources/css/app.css` · `tests/Feature/RytmPionowyStronyPrzepisuTest.php` ·
D-082 · D-107 · D-099 · D-106

---

## D-155 · `/odkryj` dostaje kolumnę szyny tym samym mechanizmem co strona przepisu

**Data:** 11 września 2026 · Zgłosił właściciel · PR #401 · Status: **obowiązuje** ·
rozwinięcie D-139

### Zgłoszenie

> „tu się zepsuło albo nie było naprawione, prawa kolumna pusta wszystko na środku"

### Co było nie tak — i dla kogo inaczej

`resources/views/pages/discover.blade.php` wołało `<x-layout>` **bez szyny**.
Skutki były dwa i różne:

- **zalogowany** dostawał trzecią kolumnę **zarezerwowaną i pustą**, bo
  `.app-body` od 80rem robi trzy kolumny na każdym ekranie, żeby nawigacja
  boczna nie przeskakiwała między podstronami;
- **gość** dostawał całą stronę zwiniętą do **768 px**, bo ekran bez szyny
  bierze `--container-strona-solo` (D-122).

Tablica „kuKINGi na dziś" stała przez ten czas w kolumnie czytania. Czyli: ta
sama tablica, w dwóch zakładkach jednej listy, raz **obok** tekstu (`/`), raz
**nad** nim (`/odkryj`).

### Pustka po prawej — zmierzona

| okno | rola | pustka z prawej PRZED | PO |
|---|---|---:|---:|
| 1920 | zalogowany | **656 px** | 272 px |
| 1512 | zalogowany | **452 px** | 68 px |
| 1920 | gość (rama 768 px) | 600 px | 408 px (rama 1152) |
| 1512 | gość (rama 768 px) | 396 px | 204 px (rama 1152) |
| 400 | oba | 0 px | 0 px |
| 1512 / czcionka 200% | oba | 36 px | 36 px |

Po zmianie `/odkryj` ma te same liczby co `/` i co strona przepisu. Przy okazji
strona zrobiła się krótsza, bo tablica przestała stać nad wpisami: przy 1512 px
**10 557 → 8 898 px** (−1 659).

**Dwie liczby, które nie miały się zmienić i się nie zmieniły:** kolumna tekstu
**688 px przed i 688 px po** (`docs/UX_50_PLUS.md`: 55–75 znaków — rośnie rama
i to, co OBOK, a nie długość wiersza) oraz rytm pionowy: `h1` [96, 131], wstęp
[155, 210] przed i po, na każdej z trzech szerokości.

### Dlaczego NIE `<x-slot:rail>` — dokładnie z powodu z D-139

Slot renderuje się w kodzie **za całym `<main>`**. Tablica stoi dziś PRZED
wpisami i to jest jej miejsce na telefonie — pod slotem zjechałaby pod wszystkie
karty wpisów i przycisk „Pokaż więcej", czyli **zniknęłaby z ekranu komuś, kto
wchodzi tu z telefonu.**

Dlatego **kolejność w kodzie zostaje kolejnością z telefonu**, a w bok przesuwa
blok dopiero siatka samego ekranu (`.odkryj-uklad` / `.odkryj-szyna`) — ten sam
zabieg i z tego samego powodu co `.przepis-uklad`. Zmierzone: przy 360 px blok
szyny stoi **nad** kolumną czytania (y = 327), przy 1280 i 1512 px **obok** niej
(y = 96).

**To jest już druga strona z tym wzorcem, więc wzorzec przestaje być wyjątkiem
strony przepisu i staje się drogą domyślną dla ekranu, który ma blok do
przeniesienia w bok, a nie treść do dołożenia.**

### Co do szyny weszło i czego tam nie ma

Do kolumny szyny weszła tablica dnia, **która już była na tym ekranie** — to
przeprowadzka jednego bloku w bok, **nie wypełniacz**. Odrzucona droga: szersza
kolumna czytania (1104 px na wpisy to wiersz, którego się nie czyta). Tablica
zachowuje stopkę „Tu nie ma rankingu. Pokazujemy różne osoby, nie najlepsze.";
nie dołożono niczego, co porządkuje ludzi (`AGENTS.md` §12).

> **Reguła ogólna: pustą kolumnę zapełnia się tym, co na ekranie już jest — albo
> wcale. Wypełniacz zostaje na zawsze, a pustkę ktoś w końcu naprawi.**

### Koszt, którego nie było w zgłoszeniu

Owijka siatki **zabrała nagłówkowi regułę `.app-main > h1`** z `tokens.css` —
zmierzony odstęp spadał **24 → 0 px**, czyli wracała usterka zgłoszona
9 września. Arkusz ekranu odtwarza go **tym samym tokenem**, a pilnuje tego
osobna asercja.

Drugi koszt jest jawny: `app.css` dostał jeden wyjątek
`:not(.przepis-uklad):not(.odkryj-uklad)` — lista, która zestarzeje się przy
trzecim takim ekranie. Napisane wprost w komentarzu przy regule.

### Dwa komentarze i jedna lista przestały być prawdziwe

Komentarze w `kuking-board.blade.php` i `DwieKolumnyTamGdzieSieMieszczaTest`
mówiły „tablica stoi w głównej kolumnie `/odkryj`". `SzynaGosciaTest` trzymał
`discover` na liście „ekran gościa BEZ szyny". **Lista, która zostaje po zmianie
produktu, tłumaczy regułę, której już nie uzasadnia** — dokładnie jak komentarz
naprostowany przez D-122.

### Pomiar w automacie też ma kontrolę ujemną

`scripts/dostepnosc.mjs` mierzy od tej zmiany także szynę zajmowaną **od środka
`<main>`** (**progów nie ruszono**). Sprawdzone, że ten pomiar nie jest martwy:
po zmianie `grid-column: 2` → `1` skrypt zgłasza „blok szyny został w kolumnie
czytania… po prawej stronie treści zostaje pusty pas". Po przywróceniu: `0`.

📄 `resources/css/ekran-odkrywania.css` · `resources/views/pages/discover.blade.php` ·
`tests/Feature/OdkrywanieUzywaKolumnySzynyTest.php` · `scripts/dostepnosc.mjs` ·
D-139 · D-122 · issue #365

---

## D-156 · Autor w danych strukturalnych to konto, które treść opublikowało — pochodzenie idzie do `citation`

**Data:** 11 września 2026 · Zgłosił właściciel · PR #403 · Status: **obowiązuje** ·
rozwinięcie D-153

### Co było nieprawdą o danych

Blok JSON-LD na stronie przepisu składał obiekt `Person` **z dwóch różnych
encji**: `name` brał z `recipes.source_person`, a `url` z profilu konta
publikującego. Autor dostawał imię jednej rzeczy i adres innej.

Do tego `@type: Person` deklarował typ encji, **którego nikt nie zna** —
`source_person` jest wolnym tekstem, a właściciel potwierdził, że wpisuje tam
**nazwę grupy na Facebooku**. Widoczny tekst strony uznał to już przy D-153
(wartość idzie dosłownie, bez doklejanego przyimka); dane wypuszczane do
Google kłamały dalej, i to na dwa sposoby naraz.

Widok był przy tym niezgodny z **własną specyfikacją projektu**:
`docs/seo/SEO_TECHNICAL.md` §2.1 od początku mapuje `author.name` i
`author.url` na `profiles.display_name` i `profiles.username` autora po
`recipes.author_id`.

### Decyzja

`author` w każdym JSON-LD opisuje **konto, które treść opublikowało, i tylko
je** — `name` i `url` z tego samego konta.

**Pochodzenie treści nie jest autorem** i idzie do `citation` jako zwykły
`Text`. Wybór sprawdzony na schema.org (V30.0), nie zgadnięty:

| pole | co przyjmuje | ocena |
|---|---|---|
| `citation` | `CreativeWork`, **`Text`**; stoi na `CreativeWork` | **wybrane** |
| `isBasedOn` | `CreativeWork`, `Product`, `URL` — bez `Text` | odrzucone: „od mamy" nie jest adresem |
| `sourceOrganization` | wyłącznie `Organization` | odrzucone: ten sam fałsz z drugiej strony |
| `recipeSource` | **w schema.org nie istnieje** (HTTP 404) | odrzucone: pole ze starego mikroformatu hRecipe |

Dziedziczenie sprawdzone: `Thing > CreativeWork > HowTo > Recipe`, a `citation`
jest wymienione na stronie `Recipe`.

### Zasada ogólna, nie łatka na jedno pole

> **Jeżeli o wartości nie wiemy, jakim typem encji jest, NIE WOLNO jej wkładać
> do pola, które typ wymusza. Lepiej nie wypuścić jej do danych strukturalnych
> wcale niż wypuścić z fałszywym `@type`.**

Ryzykiem jest zaufanie do **wszystkich** danych strukturalnych domeny, nie do
jednego pola.

### Szczegół, który nie jest ozdobą

`?:` przy `citation` jest konieczne: `array_filter` na końcu bloku odrzuca
tylko `null` i `[]`, więc **pusty napis by przeszedł**. Osobna asercja tego
pilnuje.

### Strażnik

`tests/Feature/AutorPrzepisuWDanychStrukturalnychTest.php` — wrogie dane
(nazwa grupy na Facebooku, nazwa własna bez człowieka w środku, wzmianka
o gazecie, „od mamy" i wartość, która sama jest imieniem), **oba stany ekranu**
(publiczny ma blok, prywatny nie ma go wcale) i **rekurencyjny skan po całej
stronie** za fałszywą encją nazwaną, a nie tylko po `author`.

Pięć kontroli ujemnych, każda oblewa z osobna. Dwie z nich są tam z konkretnego
powodu: sabotaż samego `author.name` oblewa **dwa** twierdzenia naraz, więc bez
osobnej kontroli („`citation` poprawne, a obok dochodzi fałszywy `Person`") nie
dałoby się pokazać, że rekurencyjny skan łapie się **sam**. Druga („bramka
`isPublic` zawsze prawdziwa") dowodzi, że test przepisu prywatnego mierzy stan,
w którym blok naprawdę nie istnieje — a nie pustkę z innego powodu (D-099, D-106).

### Zauważone, nietknięte

`source_url` przy `source_type = 'external'` nie idzie do JSON-LD wcale. Tam
`isBasedOn` **byłoby** uczciwe, bo to prawdziwy URL — ale to poszerza zakres
poza naprawiany błąd. Osobno: `docs/DATABASE.md` nie opisuje kolumn
`source_person`, `source_note` ani `source_url` w ogóle.

Bez zmiany schematu — nowa kolumna do tego nie jest potrzebna.

📄 `resources/views/pages/recipes/show.blade.php` · `docs/seo/SEO_TECHNICAL.md` §2.1 ·
`tests/Feature/AutorPrzepisuWDanychStrukturalnychTest.php` · D-153

---

## D-157 · Dokument, który cytuje regułę z kodu, jest sprawdzany testem — a wariant odrzucony zostaje w nim JAWNIE

**Data:** 11 września 2026 · PR #404 (naprawa nieprawdy wniesionej przez #398) ·
Status: **obowiązuje** · rozwinięcie D-119

### Co stało w dokumencie obowiązującym

`docs/brand/GLOS_MARKI.md` §2, punkt 3 podawał jako regułę zapisu nazwy:

> „**`white-space: nowrap`.** Słowo nie łamie się między „ku" i „KING" — bez
> tego przy 320 px i czcionce 200% jedyny nośnik tej gry rozpadałby się na dwa
> wiersze."

Arkusz w tej samej chwili deklarował `overflow-wrap: anywhere`, a komentarz nad
tą regułą mówił wprost, że `nowrap` był **pierwszą wersją i OBLAŁ skan
dostępności**: na `/register` przy oknie 320 px i czcionce przeglądarki 200%
samo słowo brało **315 px** zaczynając od x = 32, czyli strona przewijała się
w bok o **27 px** — naruszenie WCAG 2.2 AA (1.4.10 Reflow).

### Dlaczego to groźniejsze niż zwykły nieaktualny akapit

Dokument nie był po prostu stary. **Podawał jako obowiązującą dokładnie tę
wersję, którą pomiar odrzucił, i podawał razem z nią jej uzasadnienie.**
Następna osoba, porządkując arkusz „zgodnie z dokumentacją", przywróciłaby
`nowrap` i zepsuła Reflow — nie z niedbalstwa, a **czytając wiążący dokument**.

To trzeci przypadek tej klasy w tym repozytorium. Dwa pierwsze to numery
decyzji, których nie napisano (stąd `NumeryDecyzjiMajaWpisyTest`); trzeci to
wiersz tabeli stacku obiecujący Sentry'ego (D-104). Każdy raz ten sam
mechanizm: **zapis wyglądał na odpowiedź, więc nikt nie szukał dalej — a
szukając, znalazłby coś innego.**

### Decyzja, trzy części

1. **Kod jest stroną prawdziwą.** Przy rozjeździe dokumentu z arkuszem
   poprawiamy dokument, a kod zostaje nietknięty — chyba że przegląd wykaże,
   że to kod jest zły, i wtedy to osobna zmiana, nie „przy okazji".
2. **Dokument, który cytuje regułę z kodu, ma test porównujący jedno
   z drugim.** Nie „nie cytujmy reguł" — D-119 tego wymaga tam, gdzie plik
   tylko odsyła, ale tu dokument **jest** miejscem uzasadnienia i musi podać,
   czego uzasadnia. **Cytat bez testu starzeje się cicho; cytat z testem
   starzeje się na czerwono.**
3. **Odrzucony wariant zostaje w dokumencie**, jawnie, razem z pomiarem.
   Usunięcie zostawiłoby regułę bez powodu, a reguła bez powodu jest następnym
   kandydatem do „uproszczenia". Konsekwencja dla testu jest konkretna:
   **zakaz nie idzie na wystąpienie napisu w dokumencie, tylko na to, co
   dokument podaje jako REGUŁĘ** — inaczej strażnik kazałby usunąć zdanie,
   które jest najcenniejsze w całym punkcie.

### Strażnik pilnuje OBU stron, bo jedna nie wystarcza

`tests/Feature/GlosMarkiOpisujeArkuszPrawdziwieTest.php` (3 testy): obietnica
z dokumentu nie może być zakazem łamania, musi stać naprawdę w regule
`.kuking-word`, a arkusz nie może zadeklarować `white-space: nowrap`,
`word-break: keep-all` ani `overflow-wrap: normal`.

Sprawdzenie samego dokumentu złapałoby połowę. **Druga połowa — cofnięcie
ARKUSZA — zostawiłaby dokument prawdziwym, a produkt przewijający się w bok.**

Dwa szczegóły, bez których ten test świeciłby na zielono z niewłaściwego
powodu: arkusz czytany **po wycięciu komentarzy** (nazwa `.kuking-word` pada
w nich wielokrotnie, a komentarz nad właściwą regułą cytuje w środku **oba**
warianty — pułapka z `MinimalnyRozmiarTekstuTest`) oraz dopasowanie po **całym**
selektorze (`.kuking-word strong` stoi w pliku wyżej, więc szukanie nazwy
„gdzieś w liście selektorów" zwraca kolor zamiast łamania wyrazu).

Kontrola ujemna w obie strony, każdy sabotaż **odczytany z pliku po nałożeniu**:
`nowrap` w dokumencie → 2 z 3 czerwone; `nowrap` w arkuszu → 2 z 3;
`word-break: keep-all` w dokumencie → 2 z 3; obietnica usunięta → 1 z 3
(kontrola dodatnia parsera). **Dwa sabotaże nie nałożyły się za pierwszym razem
i test wtedy przechodził** — złapane tylko dlatego, że każdy był czytany
z pliku i porównywany przez md5, a nie zakładany. To ta trzecia z czterech
przyczyn nieoblanej kontroli ujemnej: sabotaż się nie wykonał.

### Zakres

`docs/brand/COPY_STYLE.md` przeszukany pod tym samym kątem: **nie podaje żadnej
reguły CSS**, a jego twierdzenie o `aria-label` na `<span>` zgadza się
z komponentem. Jest jednak na liście skanowanych dokumentów, bo nosi **drugą
kopię** sekcji o zapisie nazwy — a kopia jest miejscem, w którym taka nieprawda
odrasta (README i tabela stacku, D-104).

📄 `docs/brand/GLOS_MARKI.md` §2 ·
`tests/Feature/GlosMarkiOpisujeArkuszPrawdziwieTest.php` ·
`resources/css/app.css` (nietknięty, strona prawdziwa) · D-119 · D-104 · D-145

---

## D-158 · Odstęp pod zdjęciem karty wpisu należy do bloku POD zdjęciem i wisi na sąsiedztwie, nie na klasie

**Data:** 11 września 2026 · Zgłosił właściciel · PR #405 · Status: **obowiązuje** ·
rozwinięcie D-154

### Zgłoszenie

> „«z przepisu» i «bigos z cukinii» jest zbyt blisko zdjęcia"

### Co było zmierzone

Chromium, `/home` po zalogowaniu, przerwa liczona **między treścią** bloków:

| para bloków | 1512 px | 390 px |
|---|---:|---:|
| `post-card-head` → zdjęcie | 16 → 16 | 16 → 16 |
| **zdjęcie → `post-card-recipe`** | **0 → 16** | **0 → 16** |
| **karuzela → `post-card-zapisy`** | **0 → 16** | **0 → 16** |
| `post-card-recipe` → `post-card-tagi` | 40 → 40 | 40 → 40 |
| zdjęcie → `post-card-actions` | 17 → 17 | 17 → 17 |

`diff` pomiarów przed i po: zmieniły się **dokładnie dwie pary**, w obu
szerokościach. Zgłoszenie dotyczyło jednej z nich; druga miała tę samą wadę.

### Przyczyna

Cała karta trzyma rytm **dolnym wcięciem bloku wyżej** (`padding-bottom`),
a blok zdjęć takiego wcięcia **nie ma i mieć nie może**: zdjęcie idzie od
krawędzi do krawędzi, karta ma `overflow: hidden`. Para „zdjęcie → blok
tekstu" była więc jedyną, której odstępu nie deklarowała żadna strona.

Odstęp deklaruje strona **dolna**, jako `margin-top` — zgodnie z D-154.

### Reguła wisi na SĄSIEDZTWIE, nie na klasie

```css
.post-card > :is(.photo-grid, .karuzela, .kolaz) + :is(.post-card-recipe, .post-card-zapisy) {
  margin-top: var(--spacing-4);
}
```

Pasek „Z przepisu" **nie zawsze stoi pod zdjęciem**: przy przepisie bez
zdjęcia głównego stoi pod nagłówkiem, we wpisie „ugotowane z przepisu" pod
treścią — i tam przerwa **jest**, zmierzone 16 px. Bezwarunkowy `margin-top`
na klasie zrobiłby w tych stanach 32 px, czyli **poprawiłby jeden stan ekranu
i zepsuł dwa** (D-099, D-106).

### Konsekwencja dla testów, i to jest właściwa treść tego wpisu

> **Odstęp oparty na `+` zależy od kolejności rodzeństwa w DOM-ie, więc test
> musi sprawdzać SĄSIEDZTWO w wyrenderowanym dokumencie, nie tylko obecność
> reguły w arkuszu.**

Sabotaż „wstaw obcy element między zdjęcie a pasek" **wyłącza odstęp, nie
ruszając ani jednej linii CSS-a**. Test, który tego nie łapie, pilnuje połowy
reguły. Strażnik używa więc XPath `preceding-sibling::*[1]`.

### Pomiar liczy przerwę między treścią, nie między krawędziami pudełek

Odstępy tej karty siedzą w `padding`, a padding jest **wewnątrz** pudełka —
różnica krawędzi pokazuje 0 px także tam, gdzie człowiek widzi 16 px.
**Pierwsza wersja pomiaru meldowała zero dla ośmiu par i była fałszywa**;
poprawiona, zanim cokolwiek zmieniono w arkuszu.

Karta z paskiem „Z przepisu" **nie renderuje się w danych demo** (`DemoSeeder`
nie ma ani jednego wpisu z `recipe_id`), więc skrypt pomiarowy sam dokłada taki
wpis i **przerywa z błędem**, jeśli na zmierzonej stronie paska nie znalazł.

### Świadomie nietknięte

`.post-card-tagi` **nie ma wcięcia bocznego** — chipsy dochodzą do krawędzi
karty. To usterka **pozioma**, nie ta zgłoszona. `.chipsy` (40 px)
i `.post-card-actions` (17 px) zmierzone: nie ma tam zera, raczej nadmiar —
wyrównywanie to zmiana wyglądu poza zgłoszeniem, na komponencie używanym też
w wyszukiwaniu i na szynie profilu.

📄 `resources/css/app.css` · `scripts/odstepy-karty-wpisu.mjs` ·
`tests/Feature/OdstepPodZdjeciemNaKarcieWpisuTest.php` · D-154 · D-099 · D-106

---

## D-159 · Jedno pojęcie ma na ekranie JEDNO słowo — i nazwa usunięta z modelu danych musi zejść też z napisów

**Data:** 11 września 2026 · **Decyzja właściciela** · PR #409 · Status: **obowiązuje** ·
rozwinięcie D-021

### Co było na ekranie

D-021 (7 września) usunęła obiekt `Temat`, zostawiając same tagi, i podała
powód wprost:

> „dwa znaczyłyby, że osoba 50+ musi zrozumieć, czym «temat» różni się od
> «tagu», a to jest pytanie, na które sam produkt nie ma dobrej odpowiedzi"

**Tamta decyzja usunęła OBIEKT. Słowo zostało w napisach** — i cztery dni
później interfejs mówił do człowieka dwoma słowami o jednej rzeczy:

| gdzie | co stało |
|---|---|
| `pages/tags/index.blade.php` | `<h1>Wszystkie tematy</h1>`, `<title>`, `meta description`, dwa `<h2>`, dwa `aria-label`, pusty stan, akapit wprowadzający, „Pokaż więcej tematów" |
| `pages/tags/show.blade.php` | odnośnik „wszystkie tematy" w okruszkach |
| `components/post-card.blade.php` | `aria-label="Tematy tego wpisu"` |
| **`pages/settings/tags.blade.php`** | **„Wybierz temat i kliknij «Obserwuj ten tag»"** |

Ostatni wiersz to oba słowa **w jednym zdaniu**, na jednym ekranie, o jednej
czynności. Trasa nazywała się przy tym `/tagi`, a strona mówiła „tematy".

### Decyzja

> **Jedno pojęcie ma na ekranie jedno słowo. Gdy nazwa schodzi z modelu
> danych, schodzi także z napisów — inaczej decyzja jest wykonana w bazie
> i niewykonana tam, gdzie ją widać.**

Na ekranie obowiązuje **„tag"**. Słowo „temat" w znaczeniu klasyfikacji treści
do interfejsu nie wraca.

### Dlaczego to nie jest kosmetyka

Sprawa wyszła przy rozstrzyganiu nazwy dla #369–#372. Koncept „Tematy jako
miejsca" (#376) **nie przywraca obiektu** — mówi o bogatszej stronie tagu,
z instrukcją „nie wdrażać nowej tabeli bez potrzeby". Ale przywracał **słowo**:
gdyby strona tagu nazwała się „Temat", problem z D-021 wróciłby w nazewnictwie
zamiast w schemacie, czyli dokładnie tam, gdzie czytelnik go widzi.

### Cena, wprost

Trzy z tych miejsc to `<h1>`, `<title>` i `meta description` na `/tagi` —
**stronie publicznej z ruchem z wyszukiwarki**. Zmiana napisów, które czyta
Google, nie jest zmianą wyłącznie w interfejsie i została właścicielowi
zgłoszona osobno. Sama reguła kolejności i liczniki bez zmian.

### Komentarz cytujący inny komentarz poprawia się razem z nim

`TagController` cytował komentarz `Post::scopeTylkoOdAktywnychAutorow()`, który
mówił „feed tematów". **Poprawienie tylko cytatu zrobiłoby z niego nieprawdę**,
więc zmienione są oba. To ta sama zasada co w D-157, tylko w mniejszej skali:
zapis, który cytuje inny zapis, starzeje się razem z nim.

### Strażnik jest ZAWĘŻONY do ekranów tagów, i to nie z ostrożności

`tests/Feature/JednoSlowoNaTagiTest.php` sprawdza `/tagi`, `/tag/{slug}`
i `/ustawienia/tagi`. Nie skanuje całego repozytorium, bo **„temat" ma tu
drugie, całkowicie uprawnione znaczenie: temat listu** — `app/Mail/*`,
`app/Notifications/*` (`PodsumowanieTygodnia::temat()`), a `COPY_STYLE.md`
wymienia „temat listu" wprost jako jedno z miejsc, gdzie nazwa serwisu zostaje
zwykłym „Kuking". **Zakaz globalny oblewałby na poczcie i zostałby wyłączony
w tydzień** — a strażnik, którego się wyłącza, nie jest strażnikiem.

### Dopasowanie na granicach wyrazu, nie podciągiem

Pierwsza wersja detektora szukała podciągu „temat". Miała dwie wady, i druga
jest poważna: **oblałaby na słowie „tematyczny"**, czyli na „grupach
tematycznych" z #22 — nazwie całkowicie uprawnionej. Obowiązuje
`(?<!\p{L})temat(?:y|ów|u|em|ach|owi|ami|ce)?(?!\p{L})`, a kontrola samego
detektora sprawdza **oba kierunki**: że łapie „tematy", „temat" i „tematów",
i że **przepuszcza** „grupy tematyczne" oraz „tematyka wpisu".

### Test mierzy NASZ tekst, nie treść od ludzi

Człowiek ma prawo utworzyć tag nazwany „Temat dnia" i wtedy to słowo pojawi się
na stronie **zgodnie z prawem**. Dane testowe nie zawierają go ani raz, więc
każde trafienie pochodzi z szablonu. Napisane w docbloku testu, żeby nikt nie
uznał tego za lukę i nie „naprawił" strażnika w stronę zakazu treści
użytkownika.

### Scenariusz testu musi renderować sabotowany fragment

Test strony tagu zakłada **wpis z tagiem**, nie sam tag: karta wpisu pokazuje
chipsy tylko tam, gdzie relacja jest doładowana (`relationLoaded('tags')`). Bez
wpisu sabotowany `aria-label` **nie renderuje się wcale** i test przechodziłby,
nie mierząc go — to czwarta z przyczyn nieoblanej kontroli ujemnej z `AGENTS.md`.

### Co zostało nietknięte i dlaczego

Panel gospodarza `/admin/tagi-promowane`: „Temat tygodnia" jest tam **nazwą
planowanej funkcji z #18**, a ta decyzja jest przed właścicielem — nie
przesądza się jej przy okazji. „Ta lista zastępuje dawne Tematy" zostaje, bo
jest **historycznie prawdziwe**: ta lista naprawdę zastąpiła usunięty obiekt.

Nazwy metod testowych, nazwa pliku `SpisTematowTest.php` i dane testowe — to
nie interfejs, więc poza zakresem tej decyzji.

**Zmiana wymaga:** decyzji właściciela o nazwie funkcji z #18, jeśli miałaby
pociągnąć za sobą panel gospodarza.

📄 `resources/views/pages/tags/` · `resources/views/pages/settings/tags.blade.php` ·
`resources/views/components/post-card.blade.php` ·
`tests/Feature/JednoSlowoNaTagiTest.php` · D-021 · D-157 · issue #18 · issue #22

---

## D-160 · Wcięcie boczne karty wpisu niesie każdy blok osobno, a klasa współdzielona z innym ekranem go nie dostaje

**Data:** 12 września 2026 · PR #412 · Status: **obowiązuje**

Karta wpisu nie ma własnego `padding` (`.post-card { padding: 0 }`), bo zdjęcie
idzie od krawędzi do krawędzi. Wcięcie 20 px (`--spacing-5`) deklaruje więc
**każdy blok karty u siebie**. Blok tagów był jedynym wyjątkiem: widok wziął dla
niego gotowe `.chipsy` — świadomie, pod hasłem „żadnego nowego CSS" — a `.chipsy`
powstało dla chipsów stojących wprost w kolumnie strony, czyli tam, gdzie wcięcie
daje kolumna.

**Zmierzone** (`scripts/wciecia-boczne-karty-wpisu.mjs`, Chromium,
`getBoundingClientRect()`): 0 px z lewej i 0 px z prawej, przy 1512 px i przy
390 px, podczas gdy każdy inny blok tej samej karty miał 20 px. Po poprawce
20 px na obu krawędziach, na obu szerokościach.

### Reguła

**Klasa używana na więcej niż jednym ekranie nie dostaje odstępów kontekstu,
w którym akurat stoi.** Odstęp idzie na klasę kontekstową (`.post-card-tagi`),
nie na współdzieloną (`.chipsy`) — inaczej naprawa jednego ekranu psuje dwa
inne. Tu konkretnie: wyszukiwanie i szyna profilu, gdzie 20 px doszłoby **do**
wcięcia kolumny.

Wartość zawsze z tokenu `--spacing-*`, nigdy liczbą: wcięcie ma rosnąć razem
z pismem przy czcionce przeglądarki 200% (druga strona D-082 i D-107). Pilnuje
tego osobna asercja w `tests/Feature/PorzadkiWArkuszuKartyTest.php`.

### Druga połowa tego wpisu: martwy kod wychodzi RAZEM ze swoim komentarzem

`.landing-wpisy` przeżyła przejście strony powitalnej na jedną kolumnę,
a komentarz nad nią opisywał ją jak żywą siatkę — czyli **martwy kod bronił się
własną dokumentacją**. Komentarz historyczny (ten, który tłumaczy, co było
i dlaczego tego już nie ma) zostaje tam, gdzie stoi decyzja — u nas
w `strony-publiczne.css` przy `.landing-wpisy-kolumna`.

Użycie klasy sprawdza się **po tokenach w atrybucie `class`, nigdy po podciągu**:
`landing-wpisy` „znajduje się" w `landing-wpisy-kolumna` i martwy kod zostałby
w arkuszu na zawsze, broniony przez własną nazwę. Strażnik ma na to własny test
kontrolny (`test_szukanie_uzyc_liczy_tokeny_a_nie_podciagi`).

📄 `resources/css/app.css` · `tests/Feature/PorzadkiWArkuszuKartyTest.php` ·
`scripts/wciecia-boczne-karty-wpisu.mjs` · D-082 · D-107 · D-132 · D-158

---

## D-161 · Adres strony źródłowej idzie do `isBasedOn` — bo tu typ encji jest znany

**Data:** 12 września 2026 · PR #413 · Status: **obowiązuje** · domknięcie D-156

### Co zostało otwarte

D-156 rozstrzygnęło, że `author` w JSON-LD opisuje wyłącznie konto publikujące,
a pochodzenie przepisu (`recipes.source_person`) idzie do `citation` jako zwykły
`Text`. Ta sama decyzja zostawiła jawnie drugą połowę sprawy:

> `source_url` przy `source_type = 'external'` nie idzie do JSON-LD wcale.
> Tam `isBasedOn` **byłoby** uczciwe, bo to prawdziwy URL.

Przepis przepisany z cudzej strony miał jej adres w bazie, pokazywał go
człowiekowi na ekranie — a dane strukturalne o nim milczały.

### Decyzja

Blok `Recipe` dostaje `isBasedOn` z `recipes.source_url`, ale **tylko przy
`source_type = 'external'`**.

**To nie jest wyjątek od zasady z D-156, tylko jej druga strona.** Zasada mówi:
wartości, o której nie wiemy, jakim typem encji jest, nie wolno wkładać do pola,
które typ wymusza. `source_person` jest wolnym tekstem („od mamy", nazwa grupy
na Facebooku) i dlatego poszedł do `citation`. `source_url` jest adresem strony
i niczym innym — obie drogi zapisu walidują go regułą `url`, a widok pokazuje go
człowiekowi jako link. **Typ jest znany, więc pole jest uczciwe.**

Wybór sprawdzony u źródła (schema.org V30.0, 19 marca 2026), nie zgadnięty:

| pole | co przyjmuje | ocena |
|---|---|---|
| `isBasedOn` | `CreativeWork`, `Product`, **`URL`**; stoi na `CreativeWork` | **wybrane** |
| `isBasedOnUrl` | to samo, ale schema.org oznacza je „SupersededBy: `isBasedOn`" | odrzucone: zastąpione |
| `citation` | `CreativeWork`, `Text` | zajęte przez D-156 na `source_person` — nietknięte |

Dziedziczenie sprawdzone: `Thing > CreativeWork > HowTo > Recipe`, a `isBasedOn`
jest wymienione na stronie `Recipe`. `URL` w schema.org to **goły napis**, więc
nie deklarujemy żadnego `@type`: adres nie udaje ani osoby, ani organizacji.

### Dwa szczegóły, które nie są ozdobą

1. **Bramka `source_type` jest konieczna.** Formularz nie ukrywa pola adresu przy
   pozostałych trzech odpowiedziach, więc adres bywa wpisany także przy przepisie
   własnym czy rodzinnym — a wtedy widoczna treść strony nie pokazuje go wcale.
   Google traktuje niezgodność danych strukturalnych z widoczną treścią jako
   naruszenie wytycznych (`docs/seo/SEO_TECHNICAL.md` §2), więc warunek w JSON-LD
   jest **dokładnie ten sam** co przy widocznym zdaniu „Przepis pochodzi ze strony".
2. **`?:` przed `null`**, tak samo jak przy `citation`: `array_filter` na końcu
   bloku odrzuca `null` i `[]`, ale **pusty napis by przepuścił**.

### Zauważone, nietknięte

Widoczny link w `show.blade.php` wstawia `source_url` do `href` bez sprawdzania
schematu, a walidacja `url` przepuszcza też schematy inne niż `http`/`https`.
To sprawa bezpieczeństwa widoku, nie danych strukturalnych — osobno. Dalej
aktualne z D-156: `docs/DATABASE.md` nie opisuje kolumn `source_person`,
`source_note` ani `source_url`.

Bez zmiany schematu.

📄 `resources/views/pages/recipes/show.blade.php` ·
`tests/Feature/ZrodloZewnetrzneWDanychStrukturalnychTest.php` ·
`docs/seo/SEO_TECHNICAL.md` §2 · D-156 · D-153 · D-099 · D-106

---

## D-162 · Manifest PWA nie deklaruje orientacji w ogóle, zamiast deklarować „any"

**Data:** 12 września 2026 · PR #414 · Status: **obowiązuje** ·
kontekst: SEO/PWA-01 z audytu 10 września 2026, zależność issue #278

### Decyzja

`public/manifest.webmanifest` traci klucz `orientation` w całości. Nie zostaje
zastąpiony wartością `"any"`, choć audyt dopuszczał oba warianty.

### Dlaczego w ogóle

`"orientation": "portrait-primary"` wymuszało jedną orientację zainstalowanej
aplikacji, co narusza **WCAG 2.2 §1.3.4 Orientation (AA)** — treść nie może być
ograniczona do jednej orientacji, o ile konkretna nie jest niezbędna. W Kuking
niezbędna nie jest: tryb gotowania przy blacie to typowo telefon albo tablet
położony poziomo, więc blokada uderzała dokładnie w to użycie, **dla którego ten
ekran powstał**.

### Dlaczego usunięcie, a nie „any"

Rozstrzygnięte tekstem W3C Web Application Manifest, nie z pamięci:

- **bez klucza** przetwarzanie manifestu kończy się na „If json\[„orientation"\]
  doesn't exist […] return" — aplikacja nie deklaruje niczego i zostaje
  zachowanie systemu, **łącznie z blokadą obrotu włączoną przez samego
  człowieka**;
- **`"any"`** staje się „default screen orientation for the life of the web
  application", a przeglądarka „MUST return the orientation to the default screen
  orientation any time the orientation is unlocked" — to deklaracja **czynna**.

Oba spełniają 1.3.4. Wybrany jest ten, który zostawia decyzję przy ustawieniu
telefonu: dla grupy 50+ blokada obrotu bywa włączona świadomie i ma być nadrzędna
wobec życzeń strony.

### Co zmierzono przed zdjęciem blokady

Chromium, osobna baza, 15 ekranów × 844×390 i 932×430 (390×844 jako odniesienie):
nadmiar w poziomie **0 px na każdym ekranie**; dolna belka zostaje widoczna
(`position: fixed`, 67 px), bo progi układu są **wyłącznie szerokościowe**, a
844 px = 52,75rem, poniżej progu 64rem; najmniejszy cel dotykowy w belce 66 px;
**zero kontrolek całkiem zasłoniętych** przez belkę po przewinięciu na dół;
przyciski trybu gotowania 560×72, 608×72 i 216×60 px. Miejsce na treść między
belkami: 247 px (844×390) i 287 px (932×430) wobec 701 px w pionie — widok jest
niższy, ale nic się nie rozjeżdża.

**Drugiej blokady w CSS nie ma**: w całym `resources/` nie występuje ani jedno
`@media (orientation: …)` ani zapytanie o wysokość okna.

### Strażnik

`tests/Feature/ManifestNieWymuszaOrientacjiTest.php` czyta **plik**, bo
`orientation` działa dopiero w zainstalowanej aplikacji i żaden test strony ani
`scripts/dostepnosc.mjs` nie miał jak tej blokady zobaczyć. Przechodzi wyłącznie
brak klucza albo `"any"` — **lista dozwolonych, nie zakazanych**, więc łapie
także `portrait`, `landscape`, `landscape-primary` i `natural`. Drugi test w tym
pliku jest kontrolą dodatnią (poprawny JSON + komplet pól), bez której strażnik
byłby zielony także nad pustym plikiem.

### Czego ta decyzja NIE rozstrzyga

Nie mówi, czy i jak promować instalację PWA — to jest issue #278 i osobna
decyzja. Zdejmuje tylko przeszkodę, która kazała tamto odłożyć.

📄 `public/manifest.webmanifest` ·
`tests/Feature/ManifestNieWymuszaOrientacjiTest.php` ·
`docs/research/audyt-2026-09-10/08_SEO_PWA_UDOSTEPNIANIE.md` · issue #278

---

## D-163 · Dział pytań nazywa się „Poradźcie", a osobnego miejsca na rozmowy nie o gotowaniu nie budujemy

**Data:** 12 września 2026 · **Decyzja właściciela** · Status: **obowiązuje** ·
dotyczy issue #372

> **Adnotacja z 20 września 2026 (audyt rejestru).** Nazwa i ekran istnieją
> (`resources/views/pages/questions/index.blade.php`), ale dwie rzeczy
> zatwierdzone w tym wpisie nie powstały. Po pierwsze pozycja **w menu**:
> wiersz „MENU: Poradźcie" nie ma pokrycia — `questions.index` nie występuje w
> `resources/views/components/layout.blade.php` ani w szynie bocznej, ani w
> dolnym pasku. Po drugie strażnik, który ten wpis sam stawia jako warunek
> zatwierdzenia nazwy: „asercja: nagłówek »Poradźcie« **i** zdanie
> wyjaśniające na tej samej stronie — powstaje razem z ekranem". Ekran jest,
> strażnika nie ma. Jedyna asercja z tą frazą
> (`tests/Feature/QuestionIndexTest.php:25`) dotyczy `/odkryj`, nie
> `/pytania`, i jest `assertSee` po CAŁEJ odpowiedzi — a ta sama fraza siedzi
> w atrybucie `description` renderowanym jako `<meta name="description">`.
> Usunięcie `<h1>` i akapitu z `/pytania` nie zaświeciłoby dziś na czerwono
> ani razu. To wzorzec atrapy nazwany wprost w D-164 i D-185.

### Nazwa

Dział, w którym można poprosić innych o radę, nazywa się **„Poradźcie"** —
w menu i w nagłówku strony. Brzmienie ekranu zatwierdzone co do słowa:

```
MENU:  Poradźcie

STRONA:
  # Poradźcie
  Ktoś to już robił i chętnie powie, jak.
  Pytanie do innych jest w porządku.

  [Zapytaj innych]

  Czeka na odpowiedź (3)
```

### Ryzyko przedstawione właścicielowi i przez niego przyjęte

„Poradźcie" to **czasownik w trybie rozkazującym**, więc w menu — bez kontekstu,
obok rzeczowników „Start", „Szukaj", „Dodaj" — część osób może nie wiedzieć, czy
to **ona ma radzić**, czy **jej poradzą**. To jest odstępstwo od testu czasownika
z `docs/brand/BRAND_EXTENDED.md` §3, świadome, i ma precedens: **„Ugotowałem"**
też jest formą czasownikową użytą jako nazwa własna funkcji.

Dlatego zdanie pod nagłówkiem — **„Ktoś to już robił i chętnie powie, jak."** —
**nie jest ozdobą, tylko warunkiem z D-147**: charakter wolno tam, gdzie obok
stoi zdanie, które tłumaczy. Jeśli ktoś usunie to zdanie przy porządkowaniu
tekstów, nazwa przestaje spełniać warunek, na którym została zatwierdzona.
Strażnik na to (asercja: nagłówek „Poradźcie" **i** zdanie wyjaśniające na tej
samej stronie) powstaje razem z ekranem — dziś ekranu nie ma.

Adres strony i nazwa parametru zostają techniczne (`/pytania`,
`bez-odpowiedzi`) — patrz otwarte pytania w #372.

### Brak działu off-topic

**Osobnego miejsca na rozmowy nie o gotowaniu nie budujemy.**

Powód: kącik o niczym trzeba moderować **tak samo** jak resztę serwisu — te same
zgłoszenia, te same decyzje, ten sam czas człowieka — a nie przybliża nikogo do
ugotowania czegokolwiek. Przy jednej osobie prowadzącej moderację to koszt
realny, nie teoretyczny.

To nie jest „nigdy": **wracamy do tego, jeśli ludzie sami zaczną tak pisać** —
czyli jeśli w pytaniach i komentarzach pojawi się rozmowa niekulinarna, której
nie da się nigdzie odłożyć. Wtedy będzie to odpowiedź na zachowanie, a nie zakład.

Konsekwencja dla pracy nad #372: zabieramy z forum **pytanie i odpowiedź**, nie
strukturę „forum → działy → wątki → off-topic".

📄 issue #372 · issue #370 · `docs/research/tematy-i-pytania-2026-09-11/` §3.5 ·
`docs/brand/BRAND_EXTENDED.md` §1.1, §3 · D-147 · D-159

---

## D-164 · Asercja dodatnia na tekście ekranu idzie po `<main>`, nie po całym dokumencie

**Data:** 12 września 2026 · PR #415 · Status: **obowiązuje** · rozwinięcie D-132

### Reguła

Asercja „człowiek widzi na tym ekranie napis X" (`assertSee`,
`assertStringContainsString`) sprawdza się na **wyciętej treści** ekranu
(`Tests\Support\WycinaObudoweEkranu::trescEkranu()`), nie na całej odpowiedzi.
Asercje „X nie ma" **zostają na całym dokumencie** — szersze spojrzenie jest tam
ostrożniejsze, nie słabsze.

### Dlaczego

`<title>` ekranu jest zwykle tym samym zdaniem co jego `<h1>` i powtarza się
w `<meta>` (description, og:title, og:image:alt). Stopka niesie „Napisz do nas",
„O kuKING" i licznik „{n} kuKINGów" na **każdym** ekranie; belka gościa niesie
„Zaloguj się" i „Załóż konto" na każdym ekranie. Jedno zdanie stoi więc
w dokumencie 2–5 razy, zanim ktokolwiek spojrzy na treść.

**Zmierzone:** dziesięć asercji w dziesięciu plikach przechodziło po skasowaniu
tego, czego pilnowały. Skrajny przypadek — `/o-kuking`, gdzie po D-145 napisu
„O Kuking" **nie ma w treści ani razu** (nagłówek brzmi „O kuKING", nazwa jest
rozbita na znaczniki), a asercja i tak była zielona z `<title>` i dwóch `<meta>`.

Metoda szukania reszty: 47 wyrenderowanych ekranów (gość i zalogowany), z każdego
wycięty `<main>`, z reszty zbudowany korpus obudowy, potem triaż 106 trafień po
DOM-owej lokalizacji każdego napisu na jego własnej stronie.

### Granica

Gdy ten sam napis stoi w treści **i** w prawej szynie (np. „Dodaj zdjęcie
profilowe"), `<main>` nie wystarcza — plik musi wybrać stronę, o którą mu chodzi.
Dwa pliki mierzyły tam nie to, o czym są.

**`bezStopki()` nie jest zamiennikiem:** zdejmuje stopkę i tylko stopkę. Po jego
zastosowaniu na `/o-kuking` napis „O Kuking" nadal jest w dokumencie trzy razy.
Pułapka 1 w `docs/PULAPKI_TESTOW.md` ostrzegała przed stopką i belką — dopisany
**§1b** mówi, że najczęstszym winowajcą jest `<head>`.

### Zgłoszone, nietknięte

`SpisTematowTest:90` i `JednoSlowoNaTagiTest:93` sprawdzają „Wszystkie tagi" na
całym dokumencie. Napis stoi też trzy razy w treści, więc dowód sabotażem
wymagałby usunięcia `aria-label`, co wywala **inną** asercję w tym samym pliku —
czerwień pochodziłaby nie z tego, co się mierzy. Te dwie asercje pełnią dziś rolę
„strona się wyrenderowała, to nie ekran błędu" i tę rolę pełnią poprawnie.

### Koszt cofnięcia

Cofnięcie przywraca dziesięć zielonych testów, które nie umieją zaświecić się
na czerwono.

📄 `tests/Support/WycinaObudoweEkranu.php` · `docs/PULAPKI_TESTOW.md` §1b ·
D-132 · D-145

---

## D-165 · Komentarz w pliku wykonywalnym jest dokumentem i podlega tej samej regule co dokument

**Data:** 12 września 2026 · PR #416 · issue #342 · Status: **obowiązuje** ·
rozwinięcie D-157, ciąg dalszy D-121

### Co stało w pliku obowiązującym

Nagłówek `.github/workflows/ci.yml` (linie 13-14) mówił:

> „GDZIE TO CHODZI: na własnej puli (…), wskazanej ZESTAWEM ETYKIET,
> nie nazwą runnera i **nie zmienną repozytorium**"

i cytował `runs-on: [self-hosted, Linux, X64, woogitsu, i5-10400f, nvidia-gtx1070]`.
Wszystkie dziewięć jobów **tego samego pliku** miało
`runs-on: ${{ fromJSON(vars.CI_RUNS_ON || '"ubuntu-latest"') }}`. Ten sam nagłówek
zapisywał jako koszt, że joby „NIE mają już zapasu w runnerach GitHuba" — a
`|| '"ubuntu-latest"'` jest tym zapasem i jest wartością **domyślną**.
`docs/infra/SELF_HOSTED_RUNNER.md` powtarzał obie nieprawdy, **160 linii nad
własnym pomiarem**, który mówił coś przeciwnego (D-121).

### Dlaczego to nie był „nieaktualny akapit"

**Ta nieprawda miała kierunek.** Kto czytał „nie zmienną repozytorium", ten nie
sprawdzał wartości `CI_RUNS_ON` — a to właśnie ta wartość, ustawiona na samo
`self-hosted`, wysyłała przebiegi na starą pulę WSL, czyli tam, gdzie komplet
sześciu etykiet miał ich **nie wpuścić**, i na tę samą maszynę, która dała wyścig
o binarkę Composera z #262. Dokument nie tylko mylił — kierował uwagę z dala od
jedynego miejsca, w którym leżała przyczyna.

Drugi ładunek niósł „Krok 2": kazał odkomentować blok `on:` (aktywny od dawna)
i usunąć `workflow_dispatch` (zostawiony celowo). Instrukcja, która każe zrobić
rzecz zrobioną, uczy pomijania instrukcji — a przy okazji kazałaby zabrać jedyny
ręczny wyzwalacz bramki deployu.

### Decyzja

1. **Komentarz w `ci.yml` jest dokumentem.** Obowiązuje go D-157 w całości: przy
   rozjeździe z kodem poprawiamy komentarz, nie kod. Zmiana mechanizmu wyboru
   runnera jest osobną decyzją (D-121), nie skutkiem ubocznym porządkowania opisu.
2. **Komentarz cytujący linię kodu ma test porównujący jedno z drugim.** Cytat bez
   testu starzeje się cicho; cytat z testem starzeje się na czerwono.
3. **Uzasadnienie niewybranego wariantu zostaje jawnie.** Powód, dla którego pulę
   wskazuje się kompletem sześciu etykiet, a nie nazwą runnera, jest najcenniejszą
   treścią tego nagłówka i nie znika razem z nieprawdą o mechanizmie. Zakaz idzie
   na to, co komentarz podaje jako **obowiązujący** `runs-on:`, nie na wystąpienie
   słowa „etykiety".
4. **Plik wykonywalny CI zmieniamy wyłącznie w liniach `#`**, a po zmianie
   sprawdzamy, że YAML dalej się parsuje. `ci.yml` jest bramką deployu Railway
   („Wait for CI"): zepsuty parser zatrzymuje wdrożenie.

### Strażnik pilnuje OBU stron, bo jedna nie wystarcza

`tests/Feature/DokumentyCiMowiaPrawdeORunnerzeTest.php` odczytuje mechanizm
**z jobów** i od niego uzależnia zakazy: gdy `runs-on:` czyta `vars.*`, zakazane
są zdania odmawiające zmiennej tej roli; **gdyby ktoś wpisał etykiety na sztywno,
zakazane stają się zdania oddające zmiennej wybór** — dokument ma wtedy przestać
o niej mówić. Sprawdzenie samego dokumentu złapałoby połowę; cofnięcie **kodu**
zostawiłoby dokument prawdziwym w literze, a czytelnika w złym miejscu.

Zdania porównywane po normalizacji (sklejenie linii, `**`, backticki, wielkość
liter): feralne zdanie było złamane **między liniami 13 a 14** i każdy wzorzec
jednoliniowy by je przepuścił. `ci.yml` jest czytany dwa razy i rozdzielnie —
linie `#` jako twierdzenia, `runs-on:` spoza komentarzy jako kod.

### Kontrola ujemna, i co w niej wyszło

„nie zmienną repozytorium" w `ci.yml` → 1 z 5 czerwone · stary cytat etykiet →
1 z 5 · joby przepisane na sztywne etykiety → 3 z 5 · „nie wybiera już żadna
zmienna" w dokumencie → 2 z 5 · wskrzeszony „Krok 2" → 1 z 5 · zepsuty wykrywacz
`runs-on:` → 4 z 5 (kontrola pustego skanu).

**Jeden sabotaż nie nałożył się za pierwszym razem i test wtedy przechodził na
zielono** — wzorzec podmiany łapał także linię komentarza, więc licznik się nie
zgodził i podmiana nie wykonała się wcale. Złapane wyłącznie dlatego, że md5 było
**porównane, a nie założone**. To ta sama, trzecia z czterech przyczyn nieoblanej
kontroli ujemnej co w D-157.

### Czego ten strażnik świadomie nie pilnuje

Wartości zmiennej `CI_RUNS_ON` — żyje w ustawieniach repozytorium i z kodu jej nie
widać; jej wybór należy do właściciela (D-121). Oraz nagłówków `deploy.yml`,
`preview.yml` i `railway-iac.yml`: niosą **dokładnie tę samą nieprawdę** przy
identycznym `runs-on:`, ale ich poprawka jest poza zakresem #342. Kopia jest
miejscem, w którym taka nieprawda odrasta (D-104), więc to jest dług, nie
zamknięta sprawa — dopisanie trzech ścieżek do listy `DOKUMENTY` to jedna linia.

📄 `.github/workflows/ci.yml` (linie wykonywalne nietknięte) ·
`docs/infra/SELF_HOSTED_RUNNER.md` ·
`tests/Feature/DokumentyCiMowiaPrawdeORunnerzeTest.php` ·
D-104 · D-121 · D-132 · D-157 · issue #342

---

## D-166 · `docs/DATABASE.md` nazywa każdą kolumnę TEKSTOWĄ, a pilnuje tego test

**Data:** 12 września 2026 · PR #417 · Status: **obowiązuje** ·
rozwinięcie D-104, wykonanie zauważenia z D-156

### Co było nieprawdą o dokumencie

`AGENTS.md` stawia regułę „zmiana schematu = migracja + test + `docs/DATABASE.md`
+ rollback" od pierwszego dnia. Przegląd rzeczywistego schematu (migracje
wykonane, odczyt z `information_schema` — **49 tabel, 401 kolumn**) wobec
dokumentu znalazł **22 kolumny tekstowe w 13 tabelach**, o których dokument nie
pisał ani razu; sekcja `### recipes` składała się z dwóch słów („Aktualny stan."),
więc nie było tam ani `source_person`, ani `source_note`, ani `source_url`. Drugi
przebieg znalazł jeszcze **12 kolumn w 9 tabelach** opisanych wyłącznie na
zbieżności nazw.

**Koszt jest zmierzony, nie hipotetyczny:** `source_person` nazywa się „person",
jest `varchar(120)` i nie ma w sobie człowieka. Zanim ktoś zapytał właściciela, ta
sama nieprawda została zbudowana **dwa razy** — raz na ekranie („Po Nasze smaki.",
D-153), raz w danych strukturalnych dla Google (`@type: Person`, D-156). Obie
naprawy kosztowały cudzą pracę.

### Zasada

> **Dokument modelu danych ma nazywać każdą kolumnę TEKSTOWĄ, a przy kolumnie
> niosącej treść od człowieka — powiedzieć, co w niej NAPRAWDĘ leży, i czym jest
> `NULL`. Nazwa kolumny nie jest opisem.**

### Zakres obowiązku i dlaczego akurat taki

Strażnik żądający opisu KAŻDEJ kolumny oblewałby przy każdej migracji dokładającej
`position` albo `cos_id` — i zostałby wyłączony w tydzień. **Strażnik, którego się
wyłącza, nie jest strażnikiem.** Obowiązek obejmuje więc kolumny
`text`/`varchar`/`char` poza ośmioma tabelami frameworka (dziś 129 kolumn w 41
tabelach), bo kolumna tekstowa to jedyny rodzaj kolumny, której zawartości **nie
da się odczytać z nazwy i typu**: `family_since_year smallint` mówi o sobie
wszystko, `source_person varchar(120)` mówi nieprawdę.

Lista tabel jest listą **wykluczeń**, nie objętych — nowa tabela wchodzi pod
obowiązek sama.

### Strażnik

`tests/Feature/DokumentacjaBazyOpisujeSchematTest.php`. Schemat czytany
z `information_schema` **żywej** bazy po migracjach, nie z plików migracji:
migracje bywają wielokrotne (`posts.topic_id`), a liczy się stan końcowy. Progi
`MIN_*` (30 tabel / 100 kolumn / 50 000 znaków dokumentu) są zamkiem na skanie
pustego zbioru i na wytrychu „wpisz nasze tabele do wykluczeń". Osobny test
kontroluje sam wykrywacz: musi umieć odpowiedzieć **przecząco**.

### Czego ten strażnik świadomie nie pilnuje

Czy opis jest **prawdziwy** — ze schematu tego wyprowadzić się nie da;
`source_person` był `varchar(120) NULL` także wtedy, gdy wszyscy myśleli, że to
człowiek. Ani **gdzie** w dokumencie kolumna jest nazwana — wymaganie sekcji
oblewałoby przy każdym przestawieniu dokumentu, czyli byłoby tą kruchością, przez
którą strażników się wyłącza. Cena tej granicy jest jawna i została raz zapłacona
ręcznie.

### Przy okazji zauważone, NIETKNIĘTE

> ## ⛔ SPROSTOWANIE z 12 września 2026 — ta sekcja była w DWÓCH punktach nieprawdziwa
>
> Powstała z przeglądu, który tych twierdzeń **nie zmierzył**, tylko je
> zauważył. Przy próbie ich wykonania okazało się, że:
>
> | twierdzenie poniżej | jak jest naprawdę |
> |---|---|
> | `collection_items.note` — nic nie zapisuje ani nie czyta | **żywa**: zapisują `SavePostToCollection.php:41,49` i `SaveRecipeToCollection.php:50,58`, a **wychodzi w eksporcie danych osobowych** jako `moja_notatka` (`Users/Exports/CollectUserExportData.php:335,347`, `DataExportTest.php:188`) |
> | `daily_picks.note` — nic nie czyta | **żywa i widoczna dla człowieka**: `DailyBoardController.php:188`, `DailyBoard.php:161-165`, wyświetlana w `kuking-board.blade.php:138,278` (`DailyBoardTest.php:65,317`) |
> | `users.role` nie ma CHECK-a | **ma**: `users_role_check` istnieje od pierwszej migracji (`0001_01_01_000001_create_users_table.php:58`) i ma własny test (`NadanieRoliTest.php:167`) |
>
> Prawdziwy okazał się jeden punkt: `media.perceptual_hash` była martwa
> i została usunięta. Próbne skasowanie dwóch pozostałych kolumn **oblewa pięć
> testów**.
>
> **Dlaczego zdania niżej zostają zamiast poprawki.** Sekcja „przy okazji
> zauważone" w cudzym przeglądzie jest **hipotezą, nie ustaleniem** — a ta
> podała hipotezę tonem ustalenia. Gdyby ktoś jej zaufał, z eksportu danych
> osobowych zniknęłaby treść napisana przez człowieka. Ostrzeżenie jest warte
> więcej niż czysty wpis; zdania niżej czytaj **jako przykład błędu**, nie jako
> opis stanu.

`media.perceptual_hash`, `collection_items.note` i `daily_picks.note` to kolumny,
których dziś **nic nie zapisuje ani nie czyta**. Zostają, ale dokument mówi to
wprost — opis obiecujący działające pole byłby tą samą klasą nieprawdy.
`recipes.source_url` jest `text` bez limitu w bazie przy walidacji tnącej na 2000
znaków. `users.role` i `units.unit_type` nie mają CHECK-a.

📄 `docs/DATABASE.md` · `tests/Feature/DokumentacjaBazyOpisujeSchematTest.php` ·
D-104 · D-132 · D-153 · D-156

---

## D-167 · `/health` mówi, gdy obiecana droga wejścia nie istnieje

**Data:** 12 września 2026 · PR #418 · issues #258, #259 · Status: **obowiązuje** ·
wykonanie D-069 i D-113

### Problem

Wejście kontem Google (D-069) i kontem Facebooka (D-113) były w `main` w całości —
z kodem, ekranami, polityką prywatności i 86 testami. I żadnego z nich nie dało
się wdrożyć, bo wdrożenie nie kończyło się niczym, co by sprawdziło, czy funkcja
naprawdę stanęła:

1. `DEPLOYMENT_RUNBOOK.md` nie miał kroku dla Facebooka (Google miał 8D);
2. `.railway/railway.ts` nie przepuszczał `FACEBOOK_*` do serwisu, więc klucze
   wpisane w Shared Variables nie docierały do aplikacji;
3. `/health` nie znał **żadnego** z dwóch dostawców.

**Trzecia jest najgorsza i ona nazywa klasę błędu.** Bez kluczy przycisku po
prostu nie ma na ekranie — czyli wdrożenie, w którym obiecana droga wejścia **nie
istnieje**, wygląda identycznie jak wdrożenie, na którym właściciel świadomie jej
nie chciał. Dwie pierwsze luki odkrywa się, próbując wdrożyć. Trzeciej nie
odkrywa nikt.

### Rozstrzygnięcie

**1. `/health` oddaje `degraded` z powodem `google_bez_kluczy` albo
`facebook_bez_kluczy`**, gdy `APP_ENV=production`, funkcja jest włączona
w `config/kuking.php`, a kluczy nie ma. Pytamy o **rozjazd między obietnicą
a rzeczywistością**, nie o sam brak kluczy: `KUKING_WEJSCIE_*=false` znaczy „nie
chcę tej drogi" i nie jest awarią. Inaczej jedynym sposobem uciszenia sygnału
byłoby wpisanie byle czego w klucze — czyli nauczenie właściciela kłamania
konfiguracji.

**2. HTTP 200, nie 503.** Żadna z tych kontroli nie jest `KRYTYCZNE`. Healthcheck
oddający 503 już raz położył ten serwis; serwis bez jednej z trzech dróg wejścia
działa, serwis w pętli restartów nie działa wcale. Monitoring pilnuje **treści**
odpowiedzi.

**3. Osobny powód na dostawcę**, nie wspólne `oauth_bez_kluczy`. Naprawa każdego
z nich to inny panel i inna czynność człowieka — Google Cloud Console to nie jest
panel Meta.

**4. Publicznie wychodzi sam kod.** Trasa `/health` nie ma `auth` i mieć nie może.
Zdanie dla właściciela (z nazwami zmiennych i odnośnikiem do runbooka) idzie
wyłącznie do serwerowego logu, jak przy każdym innym powodzie.

**5. Poza produkcją cisza.** Brak kluczy jest tam stanem normalnym — tak stoi
w `.env.example`, tak chodzi CI, tak chodzą **wszystkie** środowiska preview (Meta
nie przyjmuje wieloznaczników w adresach powrotu). Stały `degraded` byłby szumem,
który uczy ignorować to pole.

### To jest D-053 widziane z drugiej strony

D-053 zabrania martwych przycisków. Tu przycisku nie ma wcale, a obietnica
została — i to jest ta sama krzywda, tylko cichsza: człowiek, któremu powiedziano
„wejdziesz kontem Facebooka", nie ma gdzie tego zobaczyć, a my nie mamy skąd się
dowiedzieć, że tak jest.

### Dowód

`tests/Feature/WdrozenieWejsciaFacebookiemTest.php` (13 testów) pilnuje trzech
twierdzeń naraz: runbook opisuje krok Facebooka **i mówi, jak sprawdzić, że
działa**; `railway.ts` przepuszcza te zmienne; `/health` mówi prawdę o ich braku.
Nazwy zmiennych czytane z `config/kuking.php`, żeby runbook i `railway.ts` nie
mogły zacząć mówić o nazwie, której aplikacja nie czyta.

### Dług, świadomie zostawiony

`config/kuking.php:937–945` nadal twierdzi, że sygnału `google_bez_kluczy`
„jeszcze nie ma". Od tego wpisu to zdanie jest nieprawdziwe — do poprawienia przy
najbliższym dotknięciu tego pliku.

📄 `app/Http/Controllers/HealthController.php` ·
`docs/infra/DEPLOYMENT_RUNBOOK.md` KROK 8E · `.railway/railway.ts` ·
`tests/Feature/WdrozenieWejsciaFacebookiemTest.php` ·
D-053 · D-069 · D-104 · D-113 · issues #258, #259

---

## D-168 · Wejście do Ustawień z telefonu stoi na ekranie profilu, przy „Wyloguj się"

**Data:** 12 września 2026 · PR #419 · issue #344 (część) · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Oba zdania rozstrzygane w
> tym wpisie przestały obowiązywać 12 września 2026 — patrz **D-174**. „Jeden
> odnośnik »Ustawienia«… prowadzący na `settings.accessibility`" prowadzi dziś
> na `settings.index` (`resources/views/pages/profile/show.blade.php:197`), a
> „**ekran-rozdroże `/ustawienia` nie istnieje**" jest nieprawdą — trasa stoi
> w `routes/web.php:827` (`SettingsIndexController`). Komentarz w tym samym
> widoku (linie 180-190) nazywa zmianę po imieniu: „Do 12 września 2026 oba te
> miejsca celowały w `settings.accessibility`… Rozdroże powstało (issue
> #344)". D-174 zapisała odwrócenie u siebie, ale nie postawiła adnotacji
> tutaj, więc do dziś dziennik niósł parę sprzecznych wpisów, oba ze statusem
> „obowiązuje". Nieaktualna jest też sekcja o „koszcie przyjętym świadomie".

### Co było

Na telefonie `.side-nav` jest schowana (`app.css:1173`), awatar w pasku górnym
jest `topbar-desktop-only` i **nie ma pod nim żadnego menu**, a dolny pasek ma
pięć pozycji i szóstej mieć nie może (`AGENTS.md` §5).

**Zmierzone** (Chromium, 390 px, konto zalogowane, pięć ekranów telefonu): słowo
„Ustawienia" jest w DOM każdego z nich, ale `widoczny: false` na **wszystkich
pięciu**.

**Sprostowanie do zgłoszenia:** ekrany ustawień **były** osiągalne — przez „Zmień
swój profil", bo `/ustawienia/profil` niesie `<x-ustawienia-nawigacja>` ze spisem
wszystkich dziewięciu ekranów. Problemem nie była liczba dotknięć (2 → 2), tylko
**brak napisu, którego człowiek szuka**.

### Decyzja

Jeden odnośnik „Ustawienia" w rzędzie akcji własnego profilu, prowadzący na
`settings.accessibility` — tam, gdzie ten sam napis w nawigacji bocznej na
komputerze. Cel dotykowy **141,8 × 50,5 px**, tekst 18 px, bez przewijania w bok
przy 320 px i przy czcionce 200%, działa bez JavaScriptu.

### Koszt przyjęty świadomie

Napis „Ustawienia" prowadzi na ekran o nagłówku **„Czytelność"**. Ekran-rozdroże
`/ustawienia` nie istnieje, a ten sam napis o dwóch celach byłby gorszy niż jeden
cel dziwny. Ratuje to spis „Wszystkie ustawienia" na tym ekranie. Na komputerze
tak jest od dawna; ujednolicenie wymaga rozdroża, czyli osobnej decyzji.

### Czego ta decyzja NIE rozstrzyga

Czy awatar przestaje być `topbar-desktop-only` · czy powstaje ekran-rozdroże
`/ustawienia` · czy rząd akcji na profilu ma wjechać wyżej (zmierzone: stoi na
`y ≈ 913` przy oknie 844 px, więc wymaga przewinięcia — ale to stan **zastany**,
sąsiedni „Zmień swój profil" stał tam już wcześniej) · przełącznik motywu
i licznik powiadomień. Każda to decyzja produktowa, a żadna nie jest potrzebna,
żeby usunąć ślepy zaułek. Dlatego **#344 zostaje otwarte**.

### Co wyszło w kontroli ujemnej i jest warte zapamiętania

Sabotaż „napis schowany pod `<span class="visually-hidden">`" **początkowo nie
oblał testu**. Przyczyna to druga z czterech: *test nic nie mierzył w tym
aspekcie* — szukał odnośnika przez `normalize-space(.)`, a `textContent` zlicza
także tekst schowany dla oka. Czyli test przepuszczał dokładnie to, czego zakazuje
„ikona nigdy sama". Po poprawce odnośnik jest szukany po **widocznym** napisie.

Druga pułapka: `.side-nav` renderuje się w HTML-u **zawsze**, także na telefonie —
chowa ją wyłącznie CSS. Razem z nią w dokumencie jest pozycja „Ustawienia" i
formularz wylogowania, więc asercja po całym dokumencie przechodziłaby nawet nad
cudzym profilem (D-164).

📄 `resources/views/pages/profile/show.blade.php` ·
`tests/Feature/UstawieniaZTelefonuBezZgadywaniaTest.php` ·
D-053 · D-082 · D-107 · D-164 · issue #344

---

## D-169 · Gdy o stanie ekranu decyduje kliknięcie, a nie serwer, warstwę wybiera arkusz

**Data:** 12 września 2026 · PR #420 · issue #343 · Status: **obowiązuje** ·
rozszerzenie D-126

### Decyzja

D-126 każe panelowi formularza znikać tam, gdzie nie ma czego wypełnić, i na
czterech ekranach robi to `@class([...])` — bo tam stan zna **serwer** w chwili
renderowania. `<details>` przełącza się już **po** wyjściu odpowiedzi, więc Blade
nie ma czego wybrać. Wtedy warstwę wybiera selektor stanu w arkuszu
(`details.panel-formularza:not([open])`), i to jest **rozszerzenie D-126, nie
wyjątek od niej**.

Dwa warunki: wartości biorą się w całości z tokenów istniejącej warstwy (żadnych
połowicznych sygnatur), a reguła działa **bez JavaScriptu**.

**Zmierzony koszt** na `/zeszyt`, identycznie przy 390 i 1512 px: obwódka
`rgb(138,122,99)` → `rgb(228,218,203)`, cień → `none`, wcięcie 24 → 20 px,
wysokość 100,5 → 92,5 px. Przy rozwinięciu przycisk przesuwa się w dół o **4 px**;
jego własna wysokość zostaje 50,5 px, powyżej progu 48.

### Drugi werdykt: rolę nadaje MIEJSCE, nie obiekt

Ekran „Komuś wyszło" (`pages/cooked/celebrate.blade.php`) zostaje **sekcją**.
Zapisany argument za kartą brzmiał „warstwa 1 wymienia wykonanie wprost" — to
argument z **obiektu**, a D-128 mówi, że rolę nadaje **miejsce**. Oba miejsca
istnieją w kodzie obok siebie: stały dom wykonania `/ugotowane/{id}` → karta
treści; jednorazowe potwierdzenie `/ugotowane/{id}/wyszlo` → sekcja (drugie
wejście przekierowuje). To ten sam układ, który D-128 rozstrzygnął dla zgłoszeń,
więc **nie wymagał nowej decyzji** — tylko zastosowania istniejącej.

### Liczba z tytułu issue była nieaktualna

`.card` niesie dziś **22** żywe wystąpienia, nie 130: 35 trafień komendą z issue,
24 po odsianiu nazw z myślnikiem (`post-card-head`), 22 po wycięciu komentarzy
Blade. Trzeci wyjątek (puste stany panelu) był już domknięty w `e57b2c9` (#367) —
nieaktualny był **dokument**, nie kod.

### Zostaje otwarte

Czwarte ograniczenie z tej samej sekcji `ROLE_KART.md`: automat dostępności nie
wchodzi na trzy ekrany z ramkami (403 na koncie demo, brak 2FA na koncie demo).
To nie jest wyjątek warstwy, tylko **luka w pokryciu pomiarem** — osobna sprawa.

📄 `resources/css/tokens.css` · `docs/design/ROLE_KART.md` ·
`tests/Feature/WyjatkiRolKartTest.php` · D-053 · D-125 · D-126 · D-128 ·
issue #343

---

## D-170 · Dokumenty w `docs/brand/` podlegają własnym regułom tam, gdzie podają tekst do wklejenia

**Data:** 12 września 2026 · PR #421 · issue #38 (część) · Status: **obowiązuje**

### Co było

§6 `docs/brand/COPY_STYLE.md` przez pół roku **zalecał** frazy, które ten sam
dokument uznaje za błąd — bo żaden test nie czytał `docs/`.
`TekstyWedlugCopyStyleTest` skanuje `resources/views`, `resources/legal/*.md`,
`lang/` i PHP. Przewodnik był jedynym miejscem w repozytorium, gdzie własne zasady
wolno było łamać bezkarnie, i to akurat tam, **skąd ludzie kopiują**.

Zgłoszono dwie frazy. Skan wzorów do wklejenia w całym `docs/brand/` dał
**jedenaście trafień w czterech plikach** — pięć w §6 `COPY_STYLE.md`, dwa
w `BRAND_EXTENDED.md`, trzy w `MASCOT_CONCEPT.md` §6.4 (sekcja, która sama nazywa
swoje teksty „gotowymi do wklejenia").

Dwa z nich są szczególnie wymowne: `mail/data-export-ready.blade.php:34` ma nad
sobą komentarz „Gotowy napis z COPY_STYLE.md §6. Nie zmieniamy go" — a napis od
dawna różnił się od §6. `BRAND_EXTENDED.md:137` przeczył **słowniczkowi w tym
samym pliku** (`:46`) i produktowi.

### Zasada

> Gotowy napis w przewodniku jest traktowany jak napis w produkcie.

**Granicą jest znacznik w samym dokumencie:** wiersz `❌`, komórka skreślona
i cała proza zostają wolne — o błędach trzeba móc pisać. Obie zgłoszone frazy
dalej stoją w dokumencie jako cytaty odrzucone i strażnik ich nie rusza; że je
odróżnia, jest **zmierzone**, nie założone (sabotaż samego rozróżnienia oblewa
cztery testy).

**Gdy napis żyje już na ekranie, wiążące jest brzmienie z kodu** — dokument idzie
za produktem, nie odwrotnie.

Wszystkie poprawki to **przebudowa zdania**, nigdy dopisanie drugiej formy.

### Dlaczego osobny plik, a nie rozszerzenie istniejącego testu

`TekstyWedlugCopyStyleTest` bierze **powierzchnię produktu** i dokument jest tam
**źródłem reguły**, nie przedmiotem badania. Dołożenie `docs/brand/` wymagałoby
wniesienia do niego całego mechanizmu „wzór kontra cytat odrzucony" — wiedzy
o tym, co znaczy `❌` w bloku ```text — której tamten plik nie ma powodu mieć.

Jedno wspólne zostało uwspólnione **naprawdę**: wzorce rodzaju mieszkają
w `tests/Support/WzorceRodzaju.php` i używają ich oba testy, więc poprawka wzorca
nie może uczynić jednego z nich ślepym.

### Co wyszło w kontroli ujemnej

Przy cofnięciu całej poprawki wyszła słabość **samej frazy kontrolnej**: „Możesz
być pierwsza albo pierwszy" stała przed poprawką jednocześnie jako `❌` **i** jako
wzór, więc czerwień nie mówiłaby, czy zepsuty jest parser, czy dokument. Fraza
podmieniona na występującą wyłącznie jako cytat odrzucony, kontrola powtórzona.

Reguły „wykrzyknik" i „nazwa w rejestrze poważnym" nie mają dziś w przewodniku ani
jednego trafienia — dlatego mają **własną kontrolę na podstawionych usterkach**,
inaczej byłyby zielone bez znaczenia.

📄 `docs/brand/COPY_STYLE.md` §6 · `docs/brand/BRAND_EXTENDED.md` ·
`docs/brand/MASCOT_CONCEPT.md` §6.4 ·
`tests/Feature/PrzewodnikTrzymaSieWlasnychZasadTest.php` ·
`tests/Support/WzorceRodzaju.php` · D-104 · D-132 · issue #38

---

## D-171 · Automat dostępności mierzy ekrany wejścia z atrapami kluczy dostawców, ale nie z atrapą dostawcy

**Data:** 12 września 2026 · PR #422 · issue #345 · Status: **obowiązuje**

### Kontekst

Blok „Wejdź kontem Google / Facebooka" renderuje się pod warunkiem
`Google::dziala()` / `Facebook::dziala()`. Środowiska pomiaru (`.env`
deweloperski, job `dostepnosc` w CI) mają klucze **puste**, więc axe nie zobaczył
tych przycisków **ani razu** — mimo że `/login` i `/register` były na liście
`EKRANY` od początku.

**Zmierzone** (320 px, `<main>`): `/login` z kluczami 40 węzłów i 2 znaki marki,
bez kluczy 25 węzłów i **zero**; `/register` odpowiednio 58 i 43. To ta sama klasa
fałszywej zieleni co D-106.

Do tego `/ustawienia/bezpieczenstwo` — jedyne miejsce z „Połącz konto Facebooka"
(D-098) — **nie był mierzony wcale**, ani przez axe, ani przez układ; stoi za
`auth`, więc `PomiarDostepnosciObejmujeStronyPubliczneTest` z założenia go nie
widzi.

### Rozstrzygnięcie

Automat stawia swój serwer z **atrapami czterech kluczy**
(`KLUCZE_DOSTAWCOW_DO_POMIARU`) i **twardo sprawdza, że rząd przycisków naprawdę
wyszedł**; niepowodzenie kończy przebieg. Wartość klucza nie wchodzi do HTML-a
(widok pyta tylko *czy* klucze są), więc renderowany kod jest co do znaku ten sam
co na produkcji i nie wychodzi z tego ani jedno żądanie do dostawcy. Do listy
dochodzi `/ustawienia/bezpieczenstwo`.

Progów **nie ruszano**. Skan: 40/40 → **41/41** axe, 45/45 → **46/46** układ,
zero naruszeń przed i po. Naprawiać nie było czego.

### Czego to nie zmienia

Ekrany za zgodą dostawcy (`/wejdz/{google,facebook}/{domknij,polacz}` oraz
`auth.facebook-bez-adresu`) zostają **niezmierzone** i zostają wypisane jako dług
nazwany w `WYJATKI`. Czytają z sesji tożsamość, którą zakłada wyłącznie
`callback()` po wymianie kodu u dostawcy, a adres wymiany jest **stałą w kodzie**,
idzie z serwera i nie da się go wskazać konfiguracją — zmierzone: przy ustawionych
atrapach kluczy wszystkie cztery dalej oddają 302 na `/login`.

**Trzy drogi rozważone i odrzucone:**
* zapis klucza sesji z zewnątrz (choćby przez `tinker`) — **właz obchodzący
  D-098**, granica, której nie przekracza ani `stanModeratora()`, ani
  `stanPrzedKodem2FA()`;
* nadpisanie adresu punktu tokenu konfiguracją — wywraca model bezpieczeństwa
  opisany w `KlientGoogle` („nie sprawdzamy podpisu, bo token odbieramy wprost
  z punktu Google po TLS") i robi ze zmiennej środowiskowej drogę do podstawienia
  tożsamości oraz wycieku sekretu klienta;
* serwer-atrapa za proxy z podłożonym CA — wymaga zaufanego CA w procesie
  aplikacji, co jest gorsze niż to, co naprawia.

Atrapa **całego dostawcy** zostaje osobną pracą z #345 i osobną decyzją; **dług to
pięć ekranów, nie cztery.**

### Zauważone o środowisku

Drzewo bez zbudowanego frontu daje **mylący komunikat o poczcie**, bo każda strona
zwraca 500 (`ViteManifestNotFoundException`). Wart osobnego zgłoszenia.

📄 `scripts/dostepnosc.mjs` · D-098 · D-106 · D-132 · issue #345 · issue #278

---

## D-172 · Menu „więcej" na karcie wpisu to same trzy kropki — nazwany wyjątek od „ikona nigdy sama"

**Data:** 12 września 2026 · PR #441 · Status: **obowiązuje**

### Kontekst

11 września do przycisku menu na karcie wpisu dołożyliśmy widoczny napis „Więcej",
bo `AGENTS.md` §5 mówi: **ikona nigdy sama**. Dzień później właściciel poprosił
o odwrotne: „Jak jest wpis to te «… Więcej» można skrócić do samych trzech kropek?
Seniorzy są przyzwyczajeni do tego na fb itp".

### Decyzja

Przycisk `<details class="post-card-menu">` nie ma widocznego napisu. **To nie jest
cofnięcie poprzedniej decyzji przez zapomnienie — to zmiana reguły, zapisana
w `AGENTS.md` §5 i w `docs/UX_50_PLUS.md` jako nazwany wyjątek.**

### Powód

Nie estetyka, tylko **rozpoznawalność**. Reguła ogólna mówi o ikonie, której trzeba
się **domyślić**. Nasi ludzie przyszli z Facebooka i spędzili tam lata; trzy kropki
w rogu wpisu są dla nich znakiem już znanym. Domyślania tu nie ma.

### Ryzyko przyjęte świadomie

Za tym menu stoją „Edytuj wpis" i „Usuń wpis". `docs/UX_50_PLUS.md` wymienia wzorzec
„`♡ ⋮ ↗` bez podpisów" jako słaby i **ten argument pozostaje prawdziwy**. Właściciel
dostał go wprost przed decyzją i zdecydował inaczej.

### Granica

Wyjątek dotyczy **wyłącznie tego jednego menu**. Nie obejmuje paska akcji pod wpisem,
pasków nawigacji, przycisków zamykania ani akcji moderacyjnych. Rozszerzenie wymaga
osobnej decyzji i osobnego wpisu, nie dopisania klasy CSS.

### Czego wyjątek nie zabiera

`aria-label="Więcej przy tym wpisie"` zostaje i jest jedyną nazwą dostępną tego
przycisku. Cel dotknięcia zostaje 48 × 48 px. Menu dalej otwiera się bez
JavaScriptu (`<details>`). Kropki rysuje komponent ikony, a **nie znak `···`
z klawiatury** — to jest różnica wobec stanu sprzed 11 września, gdy kropki były
schowane przed czytnikiem ekranu i nikt nie dostawał ani znaku z podpisem, ani
podpisu ze znakiem.

### Zmierzony skutek uboczny

Przycisk: **125,6 → 48 px** (czcionka 100%) i **225,1 → 96 px** (czcionka
przeglądarki 200%). Główka karty w najgorszym z dziewięciu przypadków: przy 320 px
**18 → 8 wierszy**, przy 414 px **8 → 5**. Przy 200% kolumna z nazwą i datą miała
przed zmianą **0 px szerokości** — jednakowo na 320, 360, 390 i 414 px, bo ten jeden
przycisk zjadał całą kartę.

📄 `resources/views/components/post-card.blade.php` · `AGENTS.md` §5 ·
`docs/UX_50_PLUS.md` · `KartaWpisuTest::test_menu_karty_to_same_kropki_ale_czytnik_ekranu_nie_traci_nic`

---

## D-173 · Data wpisu w strumieniu gubi rok — ale tylko wtedy, gdy wolno

**Data:** 12 września 2026 · PR #443 · Status: **obowiązuje**

### Kontekst

Po pomiarze główki karty wpisu (D-172) zostały dwa warianty skrócenia daty. Pomiar
pokazał, że **oba dokładają 0–1 wiersza** ponad to, co dało skrócenie przycisku,
i są między sobą nie do odróżnienia. Właściciel wybrał krótką datę.

### Decyzja

`Czas::dataWpisu()`, używane **wyłącznie na karcie wpisu**. Wpis z bieżącego roku:
„12 września, 10:04". Starszy: „12 września 2025, 10:04".

### Dlaczego nie „3 godziny temu"

Bo to jest **archiwum, do którego ludzie wracają** — „2 lata temu" nie mówi, kiedy.
Zysk wobec krótkiej daty: 0–1 wiersza, czyli żaden.

### Gdzie tego nie używamy

Ekrany moderacji, odwołań, wiadomości i eksportu danych. Tam data jest **dowodem
w sprawie albo terminem, po którym coś się kończy** — pełny rok kosztuje jedno słowo
i zostaje.

### Próg roku liczony w strefie człowieka, nie w UTC

To nie jest drobiazg i ma własny test. 31 grudnia 23:30 UTC to w Polsce już
1 stycznia, 00:30. Wpis sprzed godziny jest wtedy z **poprzedniego** roku człowieka,
a z **bieżącego** roku UTC — próg liczony w UTC gubiłby rok przy wpisach z sylwestra
przez pierwsze dwie godziny polskiej doby (jedną zimą).

📄 `app/Support/Czas.php` · `DataWpisuGubiRokTylkoWTymRokuTest` · D-172 · issue #87

---

## D-174 · `/ustawienia` jest kanoniczną stroną ustawień, a menu konta stoi na `<details>`

**Data:** 12 września 2026 · PR #439 · issue #344 · Status: **obowiązuje**

### Kontekst

Napis „Ustawienia" prowadził na ekran o nagłówku **„Czytelność"**. **D-168** przyjęło
to świadomie jako koszt — bo rozdroża `/ustawienia` w serwisie nie było, a dorobienie
go to była nowa trasa, nowy ekran i decyzja o tym, co jest kanoniczną stroną ustawień.

### Decyzja

Rozdroże **istnieje** (`SettingsIndexController`, trasa w grupie `auth`) i jest spisem
wszystkich dziewięciu ekranów ustawień. Napis „Ustawienia" wszędzie — w nawigacji
bocznej, na profilu i w menu konta — celuje w `settings.index`. **Napis i nagłówek
ekranu, na który prowadzi, mówią wreszcie to samo słowo.**

### Menu konta przy awatarze stoi na `<details>`, nie na skrypcie

Działa **bez JavaScriptu** (`AGENTS.md` §5). `resources/js/app.js` dokłada tylko
zamykanie kliknięciem obok i klawiszem `Esc` — czyli wygodę, nie działanie.

### „Powiadomienia" w pasku górnym na telefonie

`.side-nav` poniżej 64rem nie ma wcale, a dolny pasek niesie pięć pozycji i szóstej
mieć nie może. Własny profil przestał być jedynym ekranem, z którego człowiek
z telefonem dochodzi do obsługi konta.

📄 `app/Http/Controllers/Settings/SettingsIndexController.php` ·
`resources/views/pages/settings/index.blade.php` · `MenuKontaPrzyAwatarzeTest` ·
`RozdrozeUstawienTest` · D-168 · D-053

---

## D-175 · 48 px celu dotknięcia należy się rzeczom, w które da się kliknąć — nie każdemu wierszowi tekstu

**Data:** 12 września 2026 · PR #442 · issue #435 · Status: **obowiązuje**

### Kontekst

Zgłoszenie właściciela brzmiało: „Mój profil jest miejsce by dać @woogitsu obok
Mateusz". Za tym jednym zdaniem stała rzecz mierzalna: główka własnego profilu przy
390 px miała **952 px** wysokości, więc rząd akcji stał na `y ≈ 851` przy oknie
844 px — **pod pierwszym ekranem**. Do tej samej główki doszło wcześniej **D-168**,
od zupełnie innej strony.

Rozbiórka na klocki pokazała, skąd ta wysokość: **liczniki 269 px**, kolumna awatara
213 px, bio 84 px.

### Decyzja

`min-height: 3rem` (48 px) dostają **tylko te liczniki, które są odnośnikami** — dwa
z pięciu prowadzą do listy osób. Trzy pozostałe to zwykły tekst.

Reguła z `AGENTS.md` §5 mówi o **celu dotknięcia**, a nie o wysokości każdego wiersza.
Zastosowana do tekstu, w który nie da się kliknąć, kosztuje 33 px na wiersz i nie
kupuje niczego.

### Przy okazji: `@nazwa` wchodzi do wiersza z nazwą

`.profil-tozsamosc` (flex z `flex-wrap` i `align-items: baseline`). Przy długiej nazwie
(`display_name` ma `max:100`) i przy czcionce 200% `@nazwa` **schodzi pod spód** —
schodzi, a nie wypycha strony w bok.

### Zmierzone

Rząd akcji, czcionka 100%: 390 px **850,77 → 767,03 px** (po raz pierwszy nad
zgięciem przy oknie 844 px), 414 px 822,88 → 739,14, 320 px 887,95 → 826,72,
768 px 593,88 → 532,64, 1280 px 380,66 → 351,86. Przy czcionce 200%: 768 px
1562,58 → 1395,16, 1280 px 1506,78 → 1339,36.

### Czego świadomie nie zrobiono

**Liczniki zostają po jednym w wierszu.** Dwie kolumny były już raz próbowane
i zmierzone jako gorsze: kolumna treści ma 229–373 px w całym zakresie okien, po
podziale zostaje ~112 px i wyrazy łamią się w środku („obserwując / ych").

### Przy okazji zapisana pułapka kaskady

Pierwsza wersja tej poprawki postawiła `@media` z progiem **przed** regułą bazową.
Obie mają tę samą specyficzność, więc wygrała ta niżej w pliku i **próg przestał
działać także przy 1280 px**. Wyglądało to na zmianę ograniczoną do telefonów, a nie
było nią — złapał to dopiero pomiar. Stąd osobny test na **kolejność reguł w pliku**.

📄 `resources/css/ekran-profilu.css` · `scripts/glowka-profilu.mjs` ·
`GlowkaProfiluScalaNazweZNazwaUzytkownikaTest` · D-168 · issue #440

---

## D-176 · W teście albo data jest stała i zegar przymrożony, albo obie są względne

**Data:** 12 września 2026 · PR #438 · Status: **obowiązuje**

### Kontekst

12 września o **10:00 UTC `main` zrobił się czerwony bez ani jednego commita.**
`AccountStatusTest::test_zawieszony_widzi_date_konca_kary_po_polsku` zawieszał konto
do `2026-09-12 10:00:00` i sprawdzał jej polski zapis. Gdy test powstawał, data była
w przyszłości. Tego dnia o 10:00 termin minął, `EnsureAccountIsActive` zdjął karę przy
pierwszym żądaniu, ekran **słusznie** przestał cokolwiek pokazywać — i test zaczął
padać **na sprawnym kodzie**.

### Decyzja

Test sprawdzający **format** albo **treść zależną od daty** przymraża zegar
(`travelTo`) i podaje datę jawnie. Test sprawdzający **upływ czasu** liczy względem
`now()` i nie wpisuje żadnej daty. **Jedno i drugie w jednym teście to bomba
z opóźnionym zapłonem**, tykająca dokładnie tyle, ile wynosi różnica między dniem
napisania a wpisaną datą.

### Sprawdzenie, które kończy poszukiwania w pół minuty

Taka czerwień wygląda nie tak, jak jest: pada w środku dnia, na gałęzi, która nie
tknęła ani moderacji, ani layoutu, i pierwszy odruch to szukać winnego wśród świeżo
scalonych PR-ów. **Uruchom ten jeden test na czystym `main`.** Jeśli pada i tam, to
nie jest niczyja zmiana.

Zapisane jako **pułapka 9** w `docs/PULAPKI_TESTOW.md`. Należy do tej samej rodziny co
pułapka 8b: czerwień, którą czytasz, nie musi pochodzić ze zmiany, którą oglądasz.

📄 `docs/PULAPKI_TESTOW.md` §9 · `tests/Feature/AccountStatusTest.php` · `AGENTS.md`

---

## D-177 · Rząd akcji zawija się dopiero, gdy naprawdę nie ma miejsca — a `.field:first-child` nie trafia w formularzu POST

**Data:** 12 września 2026 · PR #446 · issue #433 · Status: **obowiązuje**

### Kontekst

Dwa zgłoszenia właściciela ze zrzutów: „Odpowiedz i popraw jest jedno pod drugim,
gdzie jest jednak miejsce by dać obok siebie" oraz „patrz ile miejsca nad «napisz
komentarz» à żadnego między «też jest w porządku» polem do pisania".

### Trzy rzeczy warte zapamiętania

**1. `.field:first-child` nie trafia w formularzu POST.** `@csrf` i `@method()`
renderują **ukryte pola**, a ukryte pole jest elementem. Każda reguła oparta na
`:first-child` wewnątrz formularza pilnuje czegoś, czego tam nie ma — sprawdzone
pomiarem na ~50 formularzach serwisu. Naprawiona jest **przyczyna w `tokens.css`**,
nie objaw w komentarzach: kopia reguły byłaby drugim źródłem prawdy o tej samej rzeczy.

**2. Selektor sąsiedztwa naprawiający jeden układ potrafi zepsuć inny.**
`input[type="hidden"] + .field` jest poprawne wszędzie **poza** ekranami odtwarzającymi
cudzy formularz w pętli (419, 429), gdzie ukryte pole rozdziela dwa widoczne. Reguła
z `+` potrzebuje więc pary z `~`, która odwraca ją tam, gdzie pole nie jest pierwsze.

**3. Podział akcji idzie po odwracalności, nie po autorstwie.** „Zgłoś" wróciło do
rzędu akcji zwykłych: renderowało się **po** `.danger-zone`, więc w jedynym stanie,
w którym obie akcje są naraz (autor treści ogląda cudzy komentarz), kreska nie
oddzielała już niczego.

### Zmierzone

Blok akcji pod komentarzem: 360 / 390 / 414 px **196,5 → 138 px** (3 → 2 wiersze),
cudzy komentarz 117 → 66,5 px (2 → 1 wiersz). Pustka nad „Napisz komentarz"
**24 → 0 px**, odstęp podpis → pole **0 → 12 px**.

### Czego świadomie nie zrobiono

**Przy 320 px akcje muszą się zawijać** — „Odpowiedz" + „Popraw" = 252,97 px przy
wnętrzu karty 246 px. Zmieszczenie ich wymagałoby zwężenia przycisków, czego
zgłoszenie zabrania wprost. **Parytet, nie poprawa — i to jest wynik, nie przeoczenie.**

**„Wyślij komentarz" zostaje po lewej.** Pomiar jest lustrem: przesunięcie w prawo
zyskuje na prawej dokładnie tyle, ile traci na lewej. Przy czcionce 200% różnica
wynosi **0 px**, bo przycisk wypełnia panel.

`row-gap` 8 px przy `column-gap` 12 px: gdyby oba były 12 px, blok przy 320 px byłby
o 4 px **wyższy** niż przed poprawką — zgłoszenie o zmarnowanym miejscu załatwione
dołożeniem miejsca.

📄 `resources/css/tokens.css` · `resources/views/components/comment-thread.blade.php` ·
`scripts/uklad-komentarzy.mjs` · `AkcjeKomentarzaWJednymRzedzieTest` ·
`RytmFormularzaKomentarzaTest` · D-154 · D-158 · issue #444 · issue #445

---

## D-178 · Zawężenie kolumn musi obejmować klucze obce relacji dociąganych dalej

**Data:** 12 września 2026 · PR #449 · issue #447 · Status: **obowiązuje**

### Kontekst

Zgłoszenie właściciela: „klikam na bigos z cukinii, przekierowuje mnie na to okno
gdzie jest info Ula bigos napisz komentarz itp a **nie ma przepisu ani zdjęcia**".

Odtworzone na produkcji: strona wpisu zwracała 200, a w całym `<main>` było **zero
obrazków** — przy zdjęciu widocznym na tej samej karcie w strumieniu. Dwa ekrany
rysowały ten sam komponent i **nie zgadzały się, czy wpis ma zdjęcie**.

### Przyczyna

`PostController::show()` doładowywał `recipe:id,title,slug`. Zawężenie gubiło dwie
kolumny i **żadna nie zgłaszała się błędem**:

* **`hero_media_id`** — bez niej relacja `heroMedia` nie ma po czym trafić w wiersz
  i zwraca `null`. Wpis z przepisu nie ma własnych zdjęć z założenia (#368), więc
  tracił jedyne, jakie miał.
* **`visibility`** — bez niej karta bierze widoczność **wpisu**, a ta dla wpisu
  z przepisu jest zawsze `public` (bramką jest przepis). Strona pisała więc autorowi
  **„· publicznie"** także pod przepisem, który widzą wyłącznie jego obserwujący.

### Reguła

**Zawężenie kolumn (`with('rel:a,b,c')`, `load(...)`) musi obejmować klucze obce
relacji, które będą dociągane dalej.** Brak klucza nie jest błędem — jest **cichym
`null`**. To jest klasa błędu, nie jeden przypadek: nie daje żadnego sygnału ani
w logu, ani w testach, które nie patrzą na obecność treści.

### Druga decyzja tego samego PR-a: wpis bez własnej treści nie ma własnej strony

`Post::jestSamymPrzepisem()` i `Post::adresTresci()`. Karta prowadzi wprost do
przepisu, a `posts.show` takiego wpisu przekierowuje.

* **Przekierowanie, a nie 404 i nie skasowana trasa:** adres wpisu mógł już ktoś komuś
  wysłać („Podziel się"). Ma działać dalej, tylko prowadzić tam, gdzie jest danie.
* **`url()` zostaje kanonicznym adresem wpisu** i nie wolno go zamienić
  z `adresTresci()` — kanoniczny idzie do udostępniania, `<link rel="canonical">`
  i danych strukturalnych.
* **Wpis z komentarzem nie jest „samym przepisem"** i zostaje przy swojej stronie: ma
  już coś własnego — rozmowę ludzi. Gdyby warunek o to nie pytał, przekierowanie
  zostawiłoby ją pod adresem, do którego nic nie prowadzi.

### Zauważone przy okazji

W `OdstepPodZdjeciemNaKarcieWpisuTest` stał komentarz **opisujący tę usterkę jako stan
normalny**: „`PostController::show()` doładowuje przepis bez kolumny `hero_media_id`,
więc na stronie samego wpisu zdjęcia przepisu NIE MA". Ktoś to zauważył, obszedł
w teście i pojechał dalej. Komentarz przepisany.

📄 `app/Http/Controllers/PostController.php` · `app/Models/Post.php` ·
`WpisZPrzepisuProwadziDoPrzepisuTest` · issue #368

---

## D-179 · Powitanie na stronie głównej nie zależy od godziny serwera

**Data:** 12 września 2026 · PR #450 · issue #38 · Status: **obowiązuje**

> **Adnotacja z 20 września 2026 (audyt rejestru).** Teza z tytułu — powitanie
> nie zależy od godziny serwera — obowiązuje i ma pokrycie: w kodzie nie ma
> żadnej gałęzi po godzinie. Odwrócone zostało BRZMIENIE, przy **D-207**, i
> nie odnotowano tego tutaj. Zatwierdzone w tym wpisie „**Witaj, {imię}. Co
> dziś gotujesz?**" nie jest już tekstem na ekranie:
> `app/Http/Controllers/FeedController.php:200` zwraca „Dzień dobry, {imię}",
> a pytanie o gotowanie zjechało do kafla publikacji.
> `tests/Feature/PytanieDniaTest.php:24-26` egzekwuje dziś brzmienie PRZECIWNE
> do zapisanego niżej. Uzasadnienie zmiany stoi w
> `docs/brand/COPY_STYLE.md:441-448` („Zastępuje to poprzednie »Witaj, {imię}.
> Co dziś gotujesz?« i rozdziela powitanie od publikacji"). Nieaktualne są też
> liczby w sekcji „Zmierzone", bo liczono je na „Witaj, {imię}.".

### Stan zastany był inny, niż mówiło issue

**Pytanie dnia na `/home` już było.** `git grep 'Co dziś gotujesz' -- resources/`
wracał pusto tylko dlatego, że napis składa się w PHP (`FeedController::greeting()`),
a widok renderuje gotowy tekst. Nie brakowało funkcji — brakowało **prawdy w tekście**
i jakiegokolwiek testu.

### Co było nieprawdziwe

Cztery warianty po godzinie, a w nich dwa błędy naraz: **„Dobry wieczór" witało od
15:00**, a godzinę brał `now()`, czyli **UTC** (issue #87). Latem o **11:50 czasu
polskiego serwis liczył 9:50**, a po 23:00 witał „Dzień dobry".

### Decyzja

Jedno zdanie, które nie kłamie o żadnej godzinie: **„Witaj, {imię}. Co dziś
gotujesz?"**.

Naprawa progów dałaby cztery gałęzie do utrzymania i dalej mówiłaby o porze dnia
**czytelnika**, której nie znamy: o strefę czasową nie pytamy tak samo, jak nie pytamy
o płeć, a kuKINGi mieszkają też poza Polską.

### Gdy imienia nie ma, zostaje samo pytanie

`User::displayName()` podstawia „Użytkownik Kuking" — dobre wszędzie, gdzie trzeba
kogoś **nazwać**, złe w powitaniu, bo udaje zwrot po imieniu, którego nie mamy.
Powitanie czyta `profile?->display_name` wprost.

### Zmierzone

Pole dodawania na `/home`: 390 px `top` 189,5 → 189,5 px, 1512 px 154,5 → 154,5 px.
„Witaj, {imię}." jest o sześć znaków krótsze, więc przy żadnym imieniu nie wypada
gorzej: dla „Halina z Podlasia" nagłówek zszedł ze **105 px na 70 px**, a pole
dodawania podniosło się z **225 px na 190 px**.

### Dwa sabotaże znalazły dziury w samym teście

Zwrot z pustym imieniem („Dzień dobry, . Co dziś gotujesz?") **przechodził**, bo
asercja szła po fragmencie zamiast po całym nagłówku. Podmiana źródła imienia na
`displayName()` **przechodziła** przy nazwie z samych spacji — `"   "` jest w PHP
prawdziwe i `?:` jej nie podmienia.

📄 `app/Http/Controllers/FeedController.php` · `PytanieDniaTest` ·
`docs/brand/COPY_STYLE.md` · issue #87

---

## D-180 · Widoki pokazują wariant, nie status — i nigdy oryginału

**Data:** 12 września 2026 · PR #451 · issues #430, #432 · Status: **obowiązuje**

### Kontekst

Zgłoszenie właściciela: „pisałem że trzeba jakoś od razu im pokazywać zdjęcie które
dodali, a nie napis że jest przetwarzane. […] Starzy ludzie nie czytają i będzie
panika co się stało".

Przyczyna nie była w wydajności, tylko **w kolejności**: wgranie zdjęcia i publikacja
wpisu to **jedno żądanie**, więc w chwili pierwszego renderu strony zadanie w tle nie
mogło policzyć ani jednego wariantu. Komunikat „Twoje zdjęcie się jeszcze
przygotowuje" nie był rzadkim widokiem na wypadek opóźnienia — **był tym, co po
opublikowaniu wpisu widziała każda osoba, zawsze**.

### Co obowiązywało do tego dnia

„Widoki nigdy nie pokazują zdjęcia, które nie jest `ready`". Reguła była zapisana
przez **stan wiersza**, a chroniła **bajty**: żeby na stronę nie trafił plik przysłany
przez użytkownika, z nietkniętym EXIF-em. `ready` było skrótem na „ten plik przeszedł
już przez nasz koder".

### Dlaczego skrót przestał być prawdziwy

Odkąd `StoreUploadedImage` robi wariant `podglad` synchronicznie, istnieje plik bez
EXIF-u, a wiersz stoi na `pending`. Reguła po staremu kazała ukryć plik, który **jest
bezpieczny**.

### Decyzja

Pokazujemy wyłącznie to, co wyszło z **naszego kodera** — czyli wariant zapisany
w `metadata.variants`. **Oryginał nie jest wariantem** i nie ma drogi, którą mógłby
tam trafić: `url()` go nie zna, trasa `media.show` ma białą listę nazw, status
`deleted` nie przechodzi nigdy. Reguła jest **węższa** od poprzedniej — mówi
o bajtach, nie o etykiecie — i nie ma w niej wyjątku dla właściciela.

### Dlaczego nie pokazujemy oryginału, nawet za bramką dostępu

Bramka odpowiada na pytanie **kto patrzy**, a problemem jest **co dostaje**. Oryginał
niesie EXIF (aparat, data, a w wierszach sprzed D-023 także GPS) i **6 438 105 B**.
Wariant rozbraja oba zarzuty naraz i waży **62 974 B** — **102× mniej**.

### Cena, przyjęta świadomie

**~450 ms w żądaniu publikacji** (zmierzone end-to-end: 400–471 ms wobec 19–29 ms bez
podglądu) i **próg 25 Mpx**, powyżej którego podglądu nie robimy. Próg to granica
pamięci kontenera web, nie ostrożność: libgd alokuje bitmapę **poza** licznikiem PHP,
więc `memory_limit` jej nie zatrzyma — proces znika zabity przez OOM, bez wyjątku
i bez śladu w dzienniku. Zmierzone: 12,2 Mpx → 101 MB, 24,5 Mpx → 154 MB,
49,9 Mpx → 239 MB, przy 1 GB na wszystkie procesy PHP-FPM naraz.

**Te 450 ms porównuje się do złej rzeczy, jeśli zestawić je z 19 ms.** Zanim serwer
cokolwiek zrobi, 6,14 MB musi do niego dojechać — to 51,5 megabita. Żeby sam transfer
zmieścił się w 400 ms, trzeba by **~130 Mbps w górę**; przy typowym LTE trwa on
kilka sekund.

### Skutek uboczny na korzyść

Po przetworzeniu `podglad` zostaje w `srcset`, więc telefon 320 px pobiera
**66,8 kB zamiast 178,6 kB**.

📄 `app/Domain/Media/PodgladOdRazu.php` · `app/Models/Media.php` ·
`docs/MEDIA_PIPELINE.md` · `PrzygotowywanieZdjeciaWpisuTest` · D-023 · issue #448

---

## D-181 · Podgląd przed wysłaniem idzie z pamięci przeglądarki, a jego układ mieszka w arkuszu

**Data:** 12 września 2026 · PR #451 · issue #430 · Status: **obowiązuje**

### Kontekst

Pytanie właściciela: „Nie można zrobić coś by z cache przeglądarki pokazywało chwilowo
a nie z serwera?".

Odpowiedź ma dwie części. **Cache przeglądarki tego zdjęcia nie ma** — cache trzyma to,
co przeglądarka **pobrała**, a zdjęcie poszło w drugą stronę, w ciele żądania POST.
To, co istnieje, to **plik wybrany przez człowieka**, żyjący w pamięci strony do
przejścia dalej. I tego właśnie używa `resources/js/app.js` (`URL.createObjectURL`) —
**od dawna, jeszcze przed tym pytaniem**.

### Dlaczego to nie zastępuje pracy po stronie serwera

Publikacja wpisu to POST → przekierowanie → **nowy dokument**, a adres `blob:`
poprzedniego dokumentu jest wtedy martwy — czyli znika dokładnie w tym momencie,
w którym zaczyna się problem z D-180. Przetrwanie wymagałoby IndexedDB, JavaScriptu na
dwóch ekranach i drugiego źródła prawdy o tym, jak wygląda to zdjęcie — a pomagałoby
**wyłącznie osobie wgrywającej, na tym jednym urządzeniu**.

**Obie połowy zostają i nie kolidują:** przed wysłaniem — z pamięci przeglądarki, po
opublikowaniu — wariant z serwera.

### Co było zepsute po stronie podglądu

Siatka była wpisana **w skrypcie** jako `repeat(auto-fill, minmax(120px, 1fr))`, więc
**jedno** zdjęcie dostawało jedną kolumnę z dwóch. Zmierzone: **150 px z 308 px** przy
oknie 390 px, czyli 49%. Człowiek, który właśnie wybrał zdjęcie swojego obiadu, widział
znaczek mniejszy niż połowa ekranu. To ta sama usterka, którą właściciel zgłosił przy
bloku zastępczym w kolażu (#432), tylko o jeden ekran wcześniej.

### Decyzja

Skrypt mówi **ile** jest zdjęć (`data-ile`), a **układ jest w arkuszu**, na tokenach.
Dopóki `gridTemplateColumns`, `borderRadius` i `marginTop` były przypisywane przez
`element.style`, istniały **dwa źródła prawdy** o wyglądzie tego bloku — a to
w skrypcie wygrywało z arkuszem i nie znało żadnego tokenu.

### Zmierzone (obrazek / szerokość pojemnika)

320 px: 238/238 (100%) → bez zmian · 360 px: 135/278 (49%) → **278/278 (100%)** ·
390 px: 150/308 (49%) → **308/308 (100%)** · 414 px: 162/332 (49%) → **332/332 (100%)**.
Źródło `blob:`, **63–103 ms** od wyboru pliku, zero naruszeń CSP (`img-src` ma `blob:`),
zero wyjazdu w bok.

### Czego pilnuje test, skoro wszystko dzieje się w przeglądarce

PHPUnit widzi HTML **sprzed** wykonania skryptu, więc o tym ekranie nie powie nic.
Pilnuje trzech rzeczy, które cicho cofają tę poprawkę: reguły dla jednego zdjęcia,
tego że skrypt mówi arkuszowi liczbę zdjęć zamiast wpisywać style, i tego że **bez
JavaScriptu nie zostaje na ekranie pusty `aria-live`**. Pomiar strony przeglądarkowej
robi `scripts/podglad-przed-wyslaniem.mjs`.

📄 `resources/js/app.js` · `resources/css/ekran-dodawania.css` ·
`scripts/podglad-przed-wyslaniem.mjs` · `PodgladWybranegoZdjeciaTest` · D-180 · D-035

---

## D-182 · Odstęp pod podpisem należy się podpisowi, nie jego podpowiedzi

**Data:** 12 września 2026 · PR #460 · issue #445 · Status: **obowiązuje**

### Co było nie tak

Odstęp między podpisem pola a ramką pola dostawała w praktyce **podpowiedź**: 12 px
deklarowała reguła `.field-help + .field-input` (D-133), a pole bez podpowiedzi nie
dostawało nic własnego — zostawało mu 8 px z `label { margin-bottom }` w warstwie base.
Czyli **dokładnie tyle, ile dzieli podpis od jego własnego wyjaśnienia**. Rzecz
powiązana i rzecz odrębna stały w tej samej odległości, a pole bez podpowiedzi miało
odstęp **mniejszy** niż pole z podpowiedzią.

### Decyzja

Odstęp należy się **podpisowi** i jest ten sam niezależnie od tego, czy ktoś podpowiedź
dopisał. Deklaruje go blok dolny, jako `margin-top` (D-154), a warunkiem jest
sąsiedztwo (D-158): jedna deklaracja, dwa selektory — podpis albo podpowiedź stojąca
zaraz nad polem.

Pole z podpisem **i** podpowiedzią nie dostaje obu odstępów naraz, i wynika to
z kształtu reguły, nie z ostrożności autora widoku: bezpośrednim sąsiadem pola jest
albo podpowiedź, albo etykieta, nigdy oboje. Rytm pola z podpowiedzią zostaje 8 / 12 px.

Podpis schowany przed okiem nie dostaje nic (`:not(.visually-hidden)`). Na
`/admin/kuking-na-dzis` pole notatki jest podpisane wyłącznie dla czytnika ekranu,
a 12 px pustki pod napisem, którego nie widać, zsumowałoby się w liście wierszy
w ekran przewijania.

### Zmierzone

Chromium, „dół etykiety → góra ramki pola", pola **bez** podpowiedzi, na `/login`,
`/register`, `/ustawienia/profil`, `/ustawienia/bezpieczenstwo`, `/dodaj/zdjecie`
i `/dodaj/przepis`, przy 320 / 360 / 390 / 414 px — wszystkie ekrany i wszystkie
szerokości tak samo: **8 → 12 px**, a przy czcionce przeglądarki 200% **16 → 24 px**.
Pola **z** podpowiedzią: 8 / 12 px przed i po.

### Sprostowanie, bo liczba w komentarzu nie była prawdziwa

W kodzie stało „zmierzone 0 px, np. «Nowe hasło» na /ustawienia". Zmierzone jest 8 px,
nie 0, a „Nowe hasło" **ma** podpowiedź, więc było akurat jednym z pól z odstępem
12 px. Usterka jest realna, tylko dotyczy innych pól tego ekranu: „Obecne hasło"
i „Powtórz nowe hasło". Zła liczba w uzasadnieniu jest gorsza niż brak liczby —
wygląda jak pomiar i zatrzymuje sprawdzanie.

📄 `resources/css/app.css` · `OdstepPodPodpisemPolaTest` · D-133 · D-154 · D-158

---

## D-183 · Martwe zadanie z kolejki kasuje się po wygaśnięciu żetonu, nie ponawia

**Data:** 12 września 2026 · PR #460 · Status: **obowiązuje**

### Kontekst

`/health` stoi w `degraded` przez cztery zadania `UstawienieNowegoHasla` z 9 września
2026, czyli z awarii SMTP naprawionej przez D-047.

### Dlaczego `queue:retry` jest tu odpowiedzią złą

`config/auth.php` daje żetonowi resetu hasła **60 minut od wystawienia**. Ponowienie po
dniach wysłałoby ludziom list o zmianie hasła z linkiem, który już nie działa. Człowiek,
który o nic dziś nie prosił, klika i widzi „link wygasł". Odruch „ponów, co padło" jest
tu gorszy niż nicnierobienie.

### Decyzja

`kuking:martwe-zadania` rozdziela dwa przypadki, których `queue:retry` i `queue:flush`
nie rozdzielają:

- pokazuje, co stoi w `failed_jobs` — kiedy, jaka klasa i **ilu ludzi** to dotyczy
  (różne konta, nie wiersze: jedna osoba klikała zwykle kilka razy);
- kasuje **wyłącznie** zadania, których żeton już nie żyje, a próg czyta z konfiguracji
  osobno dla każdej klasy (60 min z `config/auth.php`, 30 min z `config/kuking.php`);
- trybem domyślnym jest `--na-sucho`, a przy `--skasuj --na-sucho` naraz wygrywa ta
  intencja, **którą da się cofnąć**;
- zadania spoza listy żetonów i te z żetonem jeszcze żywym zostają nietknięte.

### Żeton nie wychodzi na ekran w żadnej gałęzi

Także w tej, w której komenda nie umie odczytać wiersza. Surowego `payload` ani
`exception` nie drukujemy nigdzie, a `unserialize()` dostaje listę dozwolonych klas,
na której klas powiadomień nie ma. Pilnuje tego osobny test z **kontrolą dodatnią**
(asercją, że żeton naprawdę leży w ładunku) — bez niej asercja „żetonu nie widać"
przechodziłaby też wtedy, gdyby żetonu tam w ogóle nie było.

📄 `app/Console/Commands/MartweZadania.php` · `app/Http/Controllers/HealthController.php` ·
`MartweZadaniaTest` · D-047 · D-057

---

## D-184 · Rezerwa nad przypiętym paskiem jest liczona ze zmierzonej wysokości i ma sufit

**Data:** 12 września 2026 · PR #460 · issue #26 · Status: **obowiązuje**

### Co było nie tak

`scripts/dostepnosc.mjs` meldował „focus częściowo zasłonięty: 27". Dwadzieścia jeden
z tych ostrzeżeń mówiło o `.topbar` i miało jedną przyczynę: przy dolnej belce stało
`scroll-padding-bottom`, a przy górnym pasku nie stało nic. Przewinięcie fokusu w widok,
które przeglądarka robi sama po Tab, liczy się wtedy do krawędzi okna — a na tej
krawędzi siedzi przypięty pasek. Osoba chodząca po serwisie klawiszem Tab przestawała
widzieć, gdzie jest.

### Decyzja

Rezerwa jest liczona od **zmierzonej** wysokości paska, nie z palca: 170,7 px bez
powiększania i 194,6 px przy tekście 140% (320/360/414 px), 75,5 / 84,5 px od 768 px.
Stąd `calc(7rem + 5rem * var(--user-text-scale, 1))` i osobny, niższy stopień od 64rem.

Część stała jest konieczna, bo pasek prawie nie jest typografią: urósł o 14%, gdy tekst
urósł o 40%. Reszta jego wysokości to minima przycisków i wypełnienia w `rem`.

### Rezerwa ma sufit i to on, a nie pasek, jest tu trudny

Gdy kontrolka nie mieści się w pasie między rezerwami, przeglądarka równa ją górą
i **każdy piksel rezerwy spycha jej dół pod belkę dolną**. Zmierzone przy 320 px
i tekście 140%: sufit **244,5 px**, a wariant 14rem × skala (313,6 px) dokładał nowe
ostrzeżenie zamiast zbijać stare. Więcej rezerwy nie jest tu lepiej.

Rezerwa znika dokładnie na obu progach, na których pasek przestaje być przypięty
(D-107) — nad odpiętym paskiem byłaby czystą stratą ekranu.

### Zmierzone

Tym samym skryptem, baza `kuking_a11y_c`, axe 42/42, układ 47/47: „focus częściowo
zasłonięty" **27 → 6**, pozostałe liczniki bez zmian. Kontrola ujemna: samo zdjęcie
reguły `scroll-padding-top` przywraca 27.

Sześć ostrzeżeń zostaje i nie da się ich zdjąć przewijaniem — to kontrolki **wyższe niż
okno** przy czcionce przeglądarki 200% (2087, 1070 i 783 px przy oknie 740 px).

📄 `resources/css/app.css` · `RezerwaNadPaskiemTest` · `scripts/dostepnosc.mjs` · D-107

---

## D-185 · Asercję podejrzaną o atrapę się mierzy, a nie przepisuje

**Data:** 12 września 2026 · PR #460 · Status: **obowiązuje**

### Kontekst

Pułapka 1 i 1b z `docs/PULAPKI_TESTOW.md`: `assertSee('X')` na całej odpowiedzi łapie X
z tytułu karty przeglądarki, z `<meta>`, ze stopki i z menu — czyli przechodzi, choć
mierzonej rzeczy na ekranie nie ma.

### Decyzja

Asercja **podejrzana** nie jest asercją **złą**. Każdą poddajemy pomiarowi: usunięcie
mierzonej rzeczy z widoku, przebieg testu, a po naprawie ten sam sabotaż jeszcze raz.
Dopiero czerwień albo jej brak rozstrzyga.

Sabotowane widoki wracają z kopii spoza repozytorium (pułapka 8), nie przez
`git checkout` — i w diffie zostaje wyłącznie `tests/`.

### Dlaczego to nie jest formalność

Poddane pomiarowi **22** asercje. Atrapami okazało się **17**, dobrych było **5**:
„Wrócimy dziś" na 503, „Bezpieczeństwo" i „Twoje dane" w spisie ustawień oraz
„Obserwuj" i „Zablokuj" w główce profilu. Gdyby przepisać wszystkie 22 „na wszelki
wypadek", pięć zmian byłoby ruchem bez powodu, a to w tym pliku nie do odróżnienia od
ruchu z powodu.

Naprawy używają wzorców z tabeli w §1, bez wymyślania piątego. Asercje „czegoś nie ma"
zostają na całym dokumencie — tam szersze spojrzenie jest **ostrożniejsze**, nie słabsze.

📄 `docs/PULAPKI_TESTOW.md` · `StronyBleduPoPolskuTest` · `UstawieniaNawigacjaTest` ·
`SpisTematowTest` · D-132

---

## D-186 · Pomiar zmienia stan DOM-u przed motywem, nie po nim

**Data:** 12 września 2026 · PR #461 · issue #454 · Status: **obowiązuje**

### Objaw

Automat dostępności meldował raz na kilkaset przebiegów `[serious] color-contrast`
na ekranie usuwania konta, przy **nietkniętej palecie**. Ze złapanego wystąpienia
(wariant ciemny, 1280 px):

    tekst  #2b241d   ← --color-ink z motywu JASNEGO
    tło    #1e1a16   ← --color-surface z motywu CIEMNEGO
    kontrast 1.13:1  przy wymaganym 4.5:1

Ta para nie występuje w żadnym motywie. Pomiar zestawiał tekst sprzed przełączenia
z tłem po przełączeniu. Wszystkie cztery węzły tego naruszenia leżały wewnątrz
`<details>`, ani jeden poza nim.

### Przyczyna

Zamknięty `<details>` jest w Chromium poddrzewem pominiętym w przeliczaniu stylu.
Zmiana `data-theme` na `<html>` go nie dotyka, a `getComputedStyle` nie wymusza
przeliczenia. Dopóki otwarcie `<details>` stało tuż przed `analyze()`, axe czytał
z tego poddrzewa kolory sprzed przełączenia motywu.

### Decyzja

Każda zmiana stanu DOM-u, która odsłania poddrzewo, idzie **przed** `data-text-scale`
i `data-theme`, a po niej automat czeka na pełny obieg klatki. Palety nie ruszamy —
poprawka jest w kolejności, nie w kolorach.

Zmierzone, 100 powtórzeń na `/ustawienia/twoje-dane`: motyw, potem `<details>` —
rozjazd pary tekst/tło **100 na 100**; `<details>`, potem motyw — **0 na 100**.

### Wyścig szedł też w drugą stronę

Migotanie widać było jako fałszywą czerwień, więc rzucało się w oczy. Ta sama kolejność
po cichu **przepuszczała** naruszenia: przeczytany kolor sprzed przełączenia bywa
zgodny, choć po przełączeniu zgodny nie jest. Fałszywa zieleń nie melduje o sobie nigdy.

### Kontrola ujemna złapała błąd w drugim teście

Test „czeka na obieg klatki po otwarciu `<details>`" brał wycinek źródła od otwarcia do
`wlaczSkaleTekstu`. Przy sabotażu przenoszącym otwarcie na koniec pętli ta druga kotwica
stoi **wcześniej**, długość wychodzi ujemna, a `substr` zwraca wtedy kawałek liczony od
końca pliku — test przechodził, mierząc nie to miejsce (pułapka 3b).

📄 `scripts/dostepnosc.mjs` · `PomiarDostepnosciOtwieraDetailsPrzedMotywemTest` ·
`docs/PULAPKI_TESTOW.md` · D-132

---

## D-187 · Cytat numeru decyzji wskazuje tę decyzję, a brakującego numeru się nie wymyśla

**Data:** 12 września 2026 · PR #458 · Status: **obowiązuje**

### Kontekst

Audyt znalazł w `HealthController` cytat „D-042" przy sprawdzeniu kolejki, a pod tym
numerem stoi decyzja o czymś zupełnie innym. `NumeryDecyzjiMajaWpisyTest` pilnował
tylko tego, że numer **ma wpis** — nie tego, że wpis mówi o tym samym, co kod obok.

To jest klasa błędu, nie jeden przypadek: numer decyzji czyta się jak uzasadnienie
i **zatrzymuje szukanie**. Zły numer jest gorszy niż brak numeru, bo wygląda na
sprawdzony.

### Zmierzone

Przejrzane **3701** wystąpień `D-NNN` w **651** plikach, każde sprawdzone wobec treści
wpisu o tym numerze. Błędnych: **11 wystąpień w 6 plikach**, czyli cztery pomyłki.

### Decyzja

Gdy cytowana decyzja istnieje pod innym numerem — przepinamy odnośnik. Gdy decyzji
w dzienniku **nie ma** — numeru nie wymyślamy: cytat znika, a zostaje odesłanie do
dokumentu, który tę zasadę naprawdę niesie.

Tak rozstrzygnięte zostało „D-004" przy zdaniu „ugotowanie jest ważniejsze niż lajk"
na stronie powitalnej i w `GLOS_MARKI.md`. D-004 dotyczy wyszukiwarki na PostgreSQL,
a decyzji o hierarchii „ugotowałem" ponad lajkiem w dzienniku po prostu nie było —
patrz D-194, który tę lukę zamyka.

📄 `app/Http/Controllers/HealthController.php` · `NumeryDecyzjiMajaWpisyTest` ·
`docs/infra/MONITORING_BLEDOW.md` · D-029 · D-041 · D-057 · D-194

---

## D-188 · Linia 📄 ma strażnika, a martwy odnośnik znika, zamiast zgadywać cel

**Data:** 12 września 2026 · PR #459 · Status: **obowiązuje**

### Kontekst

Linia `📄` na końcu każdego wpisu jest **jedyną** drogą od decyzji do kodu, który ją
realizuje. Pliki się przenoszą, klasy testowe zmieniają nazwy — a dziennik nie miał
żadnego automatu, który by to zauważył. Przy 181 wpisach nikt nigdy nie przeszedł tych
referencji ręcznie.

Zmierzone: **112** bloków referencji, ok. **780** pojedynczych referencji, martwe trzy.

### Decyzja

`OdnosnikiDziennikaDecyzjiIstniejaTest` chodzi po tych liniach przy każdym przebiegu.
Lista znanych wyjątków jest **pusta i ma taka zostać** — pierwszy dopisany wyjątek
zamienia strażnika w formalność, bo następny martwy odnośnik trafi tam odruchowo.

Martwy odnośnik, którego celu nie da się ustalić, **usuwamy**. D-149 wskazywał plik
`…/WZORCE_SAMOUZASADNIANIA`, którego nigdy nie było pod żadną ścieżką. Kusiło, żeby
wskazać sąsiedni dokument z tego samego katalogu — ale w żadnym pliku tamtego katalogu
nie ma słowa „samouzasadnianie", więc byłoby to zgadnięcie podane jako referencja.
Zgadnięty odnośnik jest dokładnie tym samym błędem co zły numer decyzji (D-187), tylko
o jedną warstwę niżej.

### Próg minimalnej liczby sprawdzonych pozycji

Skan, który nic nie znalazł, wygląda identycznie jak skan, który znalazł wszystko
i wszystko było w porządku (pułapka 2). Dlatego test oblewa także wtedy, gdy bloków
albo sprawdzonych celów jest mniej, niż być powinno.

📄 `docs/DECISIONS.md` · `OdnosnikiDziennikaDecyzjiIstniejaTest` ·
`docs/PULAPKI_TESTOW.md` · D-187

---

## D-189 · Trasy w dokumentach sprawdza `Route::getRoutes()`, nie nazwa pliku

**Data:** 12 września 2026 · PR #462 · Status: **obowiązuje**

### Kontekst

Audyt wszystkich **241** plików `.md`: odnośniki markdown do plików i kotwic, ścieżki
w backtickach, wzmianki tras serwisu.

Jeden z martwych odnośników nie był tylko dokumentacją. `resources/legal/zasady.md`
prowadziło **z żywej strony** do `/polityka-prywatnosci`, którego nie ma; właściwy
adres to `/prywatnosc`. Dokumentacja i treść serwisu leżą w tym repozytorium obok
siebie, więc „to tylko dokument" nie jest tu bezpiecznym założeniem.

### Decyzja

Prawdziwość trasy rozstrzyga `Route::getRoutes()`, a nie podobieństwo do nazwy pliku
ani angielski odpowiednik. Cała „Mapa ekranów MVP" w `FLOWS_AND_SCREENS.md` to było
**29** adresów, które nigdy nie istniały — dokument opisywał serwis, którego nie
zbudowaliśmy, i wyglądał przy tym zupełnie wiarygodnie.

Świadomym wyjątkiem zostają `/login`, `/register` i `/wyloguj` → `/logout`: to jedyne
angielskie trasy w serwisie i jest to wyjątek zapisany w `AGENTS.md` §11, nie
przeoczenie.

Dokument opisujący **projekt** API, a nie kod z repozytorium, ma to napisać u siebie.
`COMPONENTS_BLADE.md` miał 8 z 12 sekcji w tym stanie od dawna; złapał to audyt
z września i nigdy nie trafiło to do samego dokumentu.

### Co jest wyłączone z testu i dlaczego

Historyczne audyty, zlecenia i dziennik decyzji. Te dokumenty **opisują stan z dnia
zapisu** — poprawianie w nich adresu znaczyłoby przepisywanie historii, a nie naprawę.

📄 `tests/Feature/DokumentyMdNieMajaMartwychOdnosnikowTest.php` · `resources/legal/zasady.md` ·
`docs/FLOWS_AND_SCREENS.md` · `AGENTS.md` · D-188

---

## D-190 · Próbka pomiaru jest powtarzalna, a powtarzalność nie może zabrać zasięgu

**Data:** 12 września 2026 · PR #463 · issue #440 · Status: **obowiązuje**

### Co było nie tak

`scripts/dostepnosc.mjs` wybierał przykładowy przepis, wpis i konta przez
`->first()`/`->value()` **bez `ORDER BY`**. PostgreSQL nie obiecuje przy takim zapytaniu
żadnej kolejności — który obiekt zostanie zmierzony, potrafi się zmienić od samego
dołożenia wierszy. Pomiar, którego nie da się powtórzyć, nie jest dowodem, a na nim
stoją progi bramki dostępności.

Poprawionych **16** zapytań: `->orderBy('id')` (UUID v7, klucz główny), a tam, gdzie
kolejność ma znaczyć „najnowsze", `->orderByDesc('published_at')->orderByDesc('id')`,
czyli tak, jak sortuje feed (AGENTS.md §8). `created_at` świadomie nie rozstrzyga tu
remisu: seeder zapisuje wiersze w jednej sekundzie.

### Rzecz, której nie dało się przewidzieć

**Samo uczynienie wyboru powtarzalnym odebrałoby próbkę.** Karta wpisu autora
o stuznakowej nazwie wchodziła do pomiaru fokusu bocznymi drzwiami: na `EKRANY_FOCUS`
nie ma jej ani razu, a mierzona była dlatego, że „wpis (przykładowy)" rozwiązywał się
przez zapytanie bez `ORDER BY` i to jej wpis wypadał pierwszy. Po `orderBy('id')` wybór
ląduje na innym koncie, a trzy naruszenia WCAG 2.2 AA 2.4.11 przestają być mierzone —
**bez jednego oblanego testu**.

### Decyzja

Każda próbka, która ma być mierzona, ma **własną pozycję na liście ekranów i własne
zapytanie**, pytające o to, co ją czyni ciekawą (tu: o autora), a nie o kolejność.
To, co wpada do pomiaru przypadkiem, wypadnie z niego równie cicho.

Przy okazji dopisany `/ustawienia/zdjecie`, którego na liście nie było, a który pękał:
przy czcionce przeglądarki 200% i oknie 320 px `scrollWidth` **333 px** — przepełnienie
13 px. Winowajcą jest akapit opisu: element flex przy `align-items: flex-start` ma
szerokość `fit-content`, a ta nie schodzi poniżej najdłuższego słowa (252 px przy piśmie
32 px). Zamyka to `overflow-wrap: anywhere`; globalne `break-word` z `tokens.css` nie
wystarcza.

📄 `scripts/dostepnosc.mjs` · `ProfilZNajdluzszaNazwaWchodziDoPomiaruTest` ·
`resources/css/ekran-profilu.css` · D-107 · D-184

---

## D-191 · Zdjęcie pionowe obok poziomego: jedna kolumna na wąskim ekranie, kwadrat w karuzeli

**Data:** 12 września 2026 · PR #455 · issue #431 · Status: **obowiązuje**

### Zgłoszenie

„Jedno zdjęcie pionowe, drugie poziome, przez to jest rozjazd i bierze całą wysokość
najwyższego zdjęcia nawet jak nie jest wyświetlane."

### Dane demo nie miały czego pokazać — i to jest połowa tego zgłoszenia

`DemoSeeder` tworzy każde zdjęcie jako 1600×1200, a te zdjęcia nie mają wygenerowanych
wariantów, więc podstawia się znak serwisu o `viewBox="0 0 64 64"`, czyli **kwadrat**.
Pomiar na samym demo mierzyłby galerię, w której zgłoszonej usterki **nie da się
zrobić**, i meldował „w porządku". Dlatego `scripts/galeria-orientacje.mjs` sam dokłada
wpisy z prawdziwymi plikami 1200×1600 i 1600×900 i **zatrzymuje się z błędem**, jeśli na
mierzonej stronie nie stanęły obok siebie zdjęcie pionowe i poziome.

### Decyzja

`.photo-grid` poniżej 30rem schodzi do **jednej kolumny** — ten sam próg i ten sam
argument co przy kolażu: przy 320 px kolumna miała 142 px, a zdjęcie poziome mieściło
się w niej na 80 px wysokości. Martwe pole nie zostaje zasłonięte, tylko przestaje
istnieć. Dochodzi `align-items: start`, bo rozciągał się **odnośnik** „powiększ
zdjęcie": kliknięcie w puste miejsce pod zdjęciem otwierało powiększenie.

Slajdy karuzeli dostają pole o proporcji **1/1** z `object-fit: contain`. Wysokości
taśmy zależnej od widocznego slajdu nie da się zrobić bez JavaScriptu, a karuzela ma
działać bez skryptu (AGENTS.md §5) — to nie jest opcja odrzucona, tylko nieistniejąca.

### Cena, wprost

Zdjęcie pionowe przy 320 px ma teraz 214,8 × 286 px zamiast 286 × 381,3 px. Pole 4/3
zbijało martwe piksele mocniej, ale zabierało zdjęciu pionowemu **44%** wysokości —
a to najczęstszy kształt tego, co ktoś robi telefonem nad garnkiem. Kwadrat zabiera 25%
i nie wyróżnia żadnej orientacji.

Zostające w karuzeli ~125 px to co innego niż 220 px sprzed poprawki: tamte brały się
z sąsiada, którego nie było widać, te są dwoma równymi pasami nad i pod zdjęciem —
**ramą, nie dziurą**.

### Zmierzone: martwe piksele pod zdjęciem poziomym

`.photo-grid`: 320 px 109,5 → 0,0 · 360 px 124,9 → 0,0 · 390 px 136,4 → 0,0 ·
414 px 145,7 → 0,0 (przy czcionce 200% analogicznie, wszystkie → 0,0).
Karuzela: 320 px 220,5 → 125,1 · 414 px 292,9 → 167,1.

`min-height: 0` na polu slajdu nie jest ozdobą: bez niego proporcja działa tylko na
zdjęciach poziomych, czyli poprawka poprawiałaby połowę przypadków i **wyglądała
w pomiarze prawie jak poprawka**.

📄 `resources/css/app.css` · `scripts/galeria-orientacje.mjs` ·
`GaleriaMieszanychOrientacjiTest` · D-092

---

## D-192 · Próba odtworzenia kopii kończy się liczbami i nie dowodzi, że kopia istnieje

**Data:** 12 września 2026 · PR #455 · issue #193 · Status: **obowiązuje**

### Czego brakowało

Liczba kopii produkcyjnej bazy wynosi **zero** i z repozytorium zmienić się nie może.
Brakowało czego innego: **dowodu, że z kopii da się mieć bazę z powrotem**. Sygnał
„dawno nie było kopii" istniał i miał testy; ćwiczenie odtworzenia było rozpisane na
siedem komend do przepisania z dokumentu — czyli było ćwiczeniem, którego nikt nie zrobi.

### Decyzja

`scripts/proba-odtworzenia.sh --petla-lokalna` robi całość **jedną komendą**: kopia tym
samym skryptem, którym robi się kopię naprawdę (nie zrzutem zrobionym obok, innymi
flagami) → odtworzenie do świeżej bazy → porównanie liczby wierszy w **każdej** tabeli →
`migrate:status` na odtworzonej bazie.

Dwa kroki są nowe, bo dwa pytania zostawały bez odpowiedzi:

- **wszystkie tabele, nie cztery wybrane z nazwy.** Zrzut, który zgubił piątą,
  przechodził bez ostrzeżenia — bo o piątą nikt nie pytał.
- **`migrate:status`.** Komplet wierszy w schemacie sprzed trzech migracji to nie jest
  działająca baza.

Przebieg kończy się **liczbami, nie ptaszkiem**. Zmierzone 12.09.2026: 50 tabel po obu
stronach, 50 porównanych co do jednego wiersza, 159 wierszy, 75 migracji wykonanych,
0 czekających, odtworzenie 1 s.

### Czego to nie dowodzi

Że kuking.pl ma kopię. **Nie ma.** Produkcji ten skrypt nie dotyka w żadnym trybie
i pilnują tego dwa bezpieczniki. Zielony przebieg znaczy „mechanizm kopii i odtworzenia
działa", nie „dane są bezpieczne". Pierwsza prawdziwa kopia produkcyjna i ćwiczenie
odtworzenia zostają po stronie właściciela i są bramką alfy.

### Przy okazji złapana pułapka 1

Pierwsza wersja kroku `migrate:status` meldowała migrację czekającą na bazie, w której
wszystkie były wykonane: `grep -i 'Pending'` trafiał w **nazwę pliku**
`create_pending_email_changes_table`. Ta pomyłka wypadła w stronę fałszywej **czerwieni**
— gdyby wypadła w drugą, nikt by jej nie zauważył.

📄 `scripts/proba-odtworzenia.sh` · `tests/skrypty/proba-odtworzenia.sh` ·
`PetlaOdtworzeniaJestJednaKomendaTest` · `docs/PULAPKI_TESTOW.md`

---

## D-193 · Obserwowanie tagu nie jest obejściem widoczności przepisu

**Data:** 12 września 2026 · PR #465 · issue #464 · Status: **obowiązuje**

### Co było nie tak

`TagFeed` filtrował wpisy przez `widoczneDla($viewer)` i robił to poprawnie. Ale
zapowiedź przepisu (issue #368) jest na stałe `public` — widoczność ma trzymać
**przepis**, nie jego zapowiedź. Filtr po widoczności **wpisu** przepuszczał więc
zapowiedź przepisu, którego widz zobaczyć nie miał prawa.

`FollowingFeed`, `DiscoverFeed` i `DailyBoard` mają na to `zWidocznymPrzepisem()`
od issue #368. `TagFeed` był jedynym z czterech bez tej bramki — i jednocześnie jedynym,
do którego wpisy trafiają **bez żadnej relacji między widzem a autorem**. Wystarczyło
obserwować ten sam tag.

### Co wyciekało

Nie sam przepis — w niego nie dało się wejść, `RecipePolicy` trzyma. Wyciekał **tytuł**
i **zdjęcie główne**, czyli to, co karta rysuje bez pytania o zgodę. Dla przepisu „tylko
dla obserwujących" to cała treść widoczna z zewnątrz.

Zmierzone na `/home` oczami osoby, która autora nie obserwuje: pełna karta ze zdjęciem,
tytułem, przyciskiem „Ugotowałem" — i plakietką **„Tylko dla obserwujących"** pod
spodem. Karta uczciwie pisała, że to treść dla obserwujących, pokazując ją komuś, kto
nie obserwuje.

### Decyzja

Widoczność treści wskazywanej przez wpis jest **osobną bramką** i musi stać w każdym
zapytaniu, które taki wpis wydaje. Filtr po widoczności samego wpisu jej nie zastępuje
i nigdy nie zastępował — po prostu w trzech strumieniach z czterech stały obok siebie.

`maTresci()` dostaje ten sam warunek co `paginate()`. Gdyby pytała szerzej,
odpowiadałaby „jest co pokazać" o treści, której `paginate()` i tak nie odda, a widz
dostałby pusty strumień zamiast ekranu pustego stanu, który mówi, co zrobić dalej.

### Czego ta decyzja nie zmienia

Świadomy wyjątek w `DailyBoard` (bramka pominięta w agregacie „kto ostatnio
publikował", ze zmierzonym powodem w komentarzu) stoi dalej. Dotyczy wyłącznie
**kolejności** propozycji, nie tego, co widać — pokazuje o jedno konto za dużo, nigdy
o jedną treść za dużo.

📄 `app/Domain/Feed/TagFeed.php` · `FeedTagowNiePokazujeCudzegoPrzepisuTest` ·
`FeedTagowNieGubiKolumnPrzepisuTest` · `app/Models/Post.php`

---

## D-194 · „Ugotowałem" jest ważniejsze niż lajk

**Data:** 12 września 2026 · PR #466 · Status: **obowiązuje**

### Dlaczego ten wpis powstaje dopiero teraz

Ta zasada działa w Kuking od początku: niesie ją `AGENTS.md` §1 i `CLAUDE.md`, stoi
w tekście strony powitalnej i w `GLOS_MARKI.md`, i wynikła z niej niejedna decyzja
w tym dzienniku. **Wpisu o niej nie było.** Kod odsyłał w dwóch miejscach do „D-004",
a D-004 dotyczy wyszukiwarki na PostgreSQL (D-187).

Zdjęcie złego numeru zostawiło zdanie bez odnośnika. Ten wpis zamyka lukę, zamiast
kazać następnej osobie wyprowadzać tę zasadę z czterech dokumentów naraz.

### Zasada

Kuking to **społeczność ludzi, którzy gotują**, a nie baza przepisów. Najmocniejszym
sygnałem w serwisie jest **„ugotowałem"** — bo kosztuje wieczór przy garnku, a nie
jedno dotknięcie ekranu. Dlatego:

- „ugotowałem" **zawsze** powiadamia autora przepisu, a lajk nie ma takiej mocy;
- liczba ugotowań stoi wyżej niż jakakolwiek liczba polubień i to ona jest widoczna
  na karcie;
- feed obserwowanych jest **chronologiczny**, bez algorytmu: ranking zamienia dzielenie
  się jedzeniem w konkurs, a w konkursie przegrywa ten, kto gotuje zwyczajnie.

### Co z tej zasady wynika w praktyce

Każda funkcja, która podnosi widoczność treści za coś tańszego niż ugotowanie, wymaga
osobnego uzasadnienia — nie odwrotnie. Domyślną odpowiedzią na „dodajmy licznik
polubień na widoczne miejsce" jest **nie**.

### Czego ten wpis nie rozstrzyga

Czy lajk w serwisie **jest**. Jest i zostaje — ludzie potrzebują taniego sposobu, żeby
powiedzieć „widzę cię". Rozstrzygnięta jest wyłącznie **hierarchia** tych dwóch
sygnałów wszędzie tam, gdzie trzeba wybrać, który zobaczy człowiek.

📄 `AGENTS.md` · `CLAUDE.md` · `docs/brand/GLOS_MARKI.md` ·
`resources/views/pages/landing.blade.php` · D-187

---

## D-195 · Trasy z identyfikatorem sprawdzamy żądaniem, również poza wiązaniem modelu

**Data:** 12 września 2026 · PR #471 · Status: **obowiązuje**

### Decyzja

Skan sygnatur kontrolerów nie obejmuje wszystkich sposobów wczytania obiektu. Obchód musi uwzględniać gościa, właściciela, obcą osobę i blokadę oraz jawnie rozliczać każdą nową trasę z parametrem. Podpisane adresy i prywatne pliki wymagają osobnych przypadków. Wyniki pomiarów są w opisie PR; ten wpis ich nie przedstawia jako ponownego pomiaru.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #471; opisuje pracę już obecną na `main`.

📄 `tests/Feature/KazdaTrasaZIdentyfikatoremPodPolicyTest.php`

---

## D-196 · Koszt zapytań mierzymy przy rosnącej liczbie rzeczy na ekranie

**Data:** 12 września 2026 · PR #474 · Status: **obowiązuje**

### Decyzja

Doładowanie relacji ma obejmować ścieżkę rzeczywiście czytaną przez komponent, również gdy komponent zaczyna od innego modelu. Sprawdzamy wzrost zapytań wraz z liczbą wyników, a kontrola dodatnia potwierdza obecność treści. Sam płaski wynik dla pustej strony nie jest dowodem.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #474; opisuje pracę już obecną na `main`.

📄 `tests/Feature/WynikiSzukaniaLudziBezWachlarzaZapytanTest.php`

---

## D-197 · Komunikat walidacji mierzymy przez wywołanie błędu

**Data:** 12 września 2026 · PR #475 · Status: **obowiązuje**

### Decyzja

Komunikat nazywa pole tak jak ekran i mówi, co zrobić. Podsumowanie prowadzi do istniejącego pola, pole wskazuje swój błąd, a poprawne wartości zostają. Test wyzwala walidację żądaniem HTTP; samo znalezienie tekstu w pliku językowym nie wystarcza.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #475; opisuje pracę już obecną na `main`.

📄 `tests/Feature/BledyMowiaCoZrobicTest.php`

---

## D-198 · Brak skryptów sprawdzamy na rzeczywistych drogach użytkownika

**Data:** 12 września 2026 · PR #476 · Status: **obowiązuje**

### Decyzja

Obowiązuje D-053: formularze chronione przez Turnstile mogą wymagać JavaScriptu. Brak skryptu ma dawać konkretną instrukcję, a nie martwą kontrolkę. Sprawdzenie drogi bez skryptów nie oznacza wyłączenia ochrony ani powrotu do dawnego obowiązku działania każdego formularza bez JavaScriptu.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #476; opisuje pracę już obecną na `main`.

📄 `AGENTS.md`

---

## D-199 · Wycofanie migracji nie może wymazać znaczenia ustawienia

**Data:** 12 września 2026 · PR #477 · Status: **obowiązuje**

### Decyzja

Obchód migracji obejmuje wycofanie i ponowne zastosowanie. Przy danych, których znaczenia nie da się odtworzyć, wycofanie odmawia wąsko i z instrukcją. Przypadek domyślny ma nadal przechodzić. To rozwinięcie D-088, nie zgoda na bezwarunkowe blokowanie rollbacku.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #477; opisuje pracę już obecną na `main`.

📄 `tests/Feature/KazdaMigracjaMaWycofanieTest.php`

---

## D-200 · Duży tekst dostaje szerokość zamiast mniejszej czcionki

**Data:** 12 września 2026 · PR #479 · Status: **obowiązuje**

### Decyzja

Kafel dodawania przy dużym tekście oddaje opisowi osobny wiersz, ogranicza wzrost wcięć i chowa ozdobną ikonę. Tytuł i podpis zostają. Pomiar wysokości należy do przeglądarki; test kształtu reguły CSS nie zastępuje pomiaru. Osobny problem rozmiaru podpisu pozostaje zgłoszony w issue #478.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #479; opisuje pracę już obecną na `main`.

📄 `tests/Feature/KafelDodawaniaPrzyDuzymTekscieTest.php`

---

## D-201 · Dokumentację tabel porównujemy ze schematem w obie strony

**Data:** 12 września 2026 · PR #480 · Status: **obowiązuje**

### Decyzja

Strażnik wykrywa zarówno tabelę pominiętą w opisie, jak i opis tabeli nieistniejącej. Dokument historycznego schematu nie może być nazywany pełnym aktualnym DDL. Liczba sprawdzonych pozycji jest częścią kontroli, bo pusty odczyt nie dowodzi zgodności.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #480; opisuje pracę już obecną na `main`.

📄 `tests/Feature/SchematBazyTrzymaSieDokumentuTest.php`

---

## D-202 · Obchód odnośników obejmuje stany i drogi bez wejścia z menu

**Data:** 12 września 2026 · PR #481 · Status: **obowiązuje**

### Decyzja

Pomiar obejmuje także szkice, puste stany, moderację, błędy i podpisane adresy. Odnośnik musi mieć nazwę, formularz cel, a przycisk obsługę. Kontrola ujemna sprawdza również sam przyrząd. Szkic pokazuje tekst zamiast pustego odnośnika daty; pytanie o opis przyszłej widoczności jest osobną sprawą.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #481; opisuje pracę już obecną na `main`.

📄 `tests/Feature/ObchodEkranowNieZostawiaMartwegoPrzyciskuTest.php`

---

## D-203 · Nowy styl korzysta z tokenów i zachowuje czytelność

**Data:** 12 września 2026 · PR #483 · Status: **obowiązuje**

### Decyzja

Nowa paleta jasna i grafitowa ciemna działa w istniejących komponentach aplikacji.
Znak pozostaje garnkiem z pokrywką w formie korony i uśmiechem. Podpis kafla
dodawania ma bazowo 18 px; rozmiar pisma nie jest ceną za zmieszczenie układu.
Zmiana zamyka kwestię podpisu z issue #478, pozostawioną w D-200.
Konstytucja marki zachowuje społecznościowy charakter, „Ugotowałem” ponad lajkiem
i brak fikcyjnej aktywności. Plan przepisów redakcyjnych nie oznacza ich publikacji.

### Dowód

Pomiar obu motywów, kafla oraz granice wyniku zapisano po scaleniu kodu.
Nie przenosimy statycznego prototypu w miejsce Laravel.

📄 `docs/brand/KONSTYTUCJA_MARKI.md` · `docs/design/NOWY_STYL.md` ·
`docs/design/WERYFIKACJA_ALFA_08.md` · `resources/css/tokens.css`

---

## D-204 · Zły wybór zeszytu daje widoczny komunikat bez ujawniania własności

**Data:** 12 września 2026 · PR #483 · Status: **obowiązuje**

### Decyzja

Oba endpointy zapisu sprawdzają UUID przed zapytaniem do PostgreSQL oraz istnienie
zeszytu w obrębie właściciela. Cudzy i nieistniejący zeszyt mają ten sam komunikat.
Autoryzacja treści pozostaje pierwsza, a pobranie modelu nadal ogranicza właściciel.
Błąd jest dostępny po powrocie na strumień, nie tylko w sesji walidacji.
Puste pole lub brak wyboru zachowuje zapis do zeszytu domyślnego.

### Dowód

Czternaście przypadków HTTP, rzeczywiste ciasteczko sesji przy powrocie oraz
oddzielne kontrole usunięcia UUID, własności i komunikatu.
Usunięcie zeszytu pomiędzy walidacją a pobraniem może nadal dać 404; ta zmiana
nie przebudowuje transakcji.

📄 `app/Http/Controllers/CollectionController.php` ·
`tests/Feature/WyborZeszytuMaWalidacjeTest.php` ·
`docs/product/WALIDACJA_WYBORU_ZESZYTU.md`

---

## D-205 · Przyrząd kontroli ma sprawdzać źródło i udowadniać wykrycie regresji

**Data:** 12 września 2026 · PR #483 · Status: **obowiązuje**

### Decyzja

Pomiar kontrastu czyta produkcyjne tokeny i jest częścią builda Vite oraz Docker.
Nie zastępuje pomiaru kaskady ani reflow. Kontrole negatywne w izolowanym CI
najpierw wymagają dodatniego testu, potem rzeczywistej porażki po mutacji,
przywrócenia z kopii poza repo i zgodności md5. Pełna suita idzie na przywróconym kodzie.
Raport i kod wyjścia nie są synonimami; wykrytą lukę starego pomiaru zapisano w #484.

📄 `scripts/kontrast-marki.mjs` · `scripts/kontrole-negatywne-alfa08.py` ·
`docs/design/WERYFIKACJA_ALFA_08.md`


---

## D-206 · Pełny port marki obejmuje układ działającej aplikacji

**Data:** 13 września 2026 · PR #488 · Status: **obowiązuje**

### Decyzja

Alfa 0.9 przenosi zaakceptowany projekt do istniejących ekranów Laravel:
pływającą ramę, menu desktop w nagłówku, pięć pozycji mobilnych, ciemny
kafel publikacji i własnego profilu, mocną typografię oraz wspólne karty
i formularze. Zwykły użytkownik nie ma widocznego lewego paska; panel
moderacji zachowuje swój tryb roboczy. Wejście do niego w menu konta
pokazuje sumę kolejek wyłącznie poza panelem.

Konstytucja v1.2 opisuje całą rodzinę ekranów i głos marki. Garnek z koroną
i uśmiechem pozostaje znakiem; ikony instalowanej aplikacji i udostępniania
korzystają z nowej palety. Statyczne osoby, liczniki i funkcje demonstracji
nie są przenoszone jako dane lub zachowanie produkcji.

### Dowód i granice

Kod scalono jako `66980acc83ea8298b76771682e4bda96264a6484`.
Pomiar nowej ramy sprawdza jej własną szerokość, wyśrodkowanie, padding
i zawartość, zamiast wymagać wyrównania szerszej belki do węższej treści.
Kontrole ujemne wykrywają rzeczywiste regresje. Wymagane są zielone CI
oraz sprawdzenie dostarczonego HTML i CSS po wdrożeniu. Publiczny test
dymny nie zastępuje odbioru ekranu zalogowanego.

Sześć ostrzeżeń częściowego zasłonięcia fokusu długiej nazwy przy
powiększeniu pozostaje jawnie w #485; test nie podnosi progów ani nie
usuwa nazw. Wyniki i zakres oglądanych zrzutów zapisuje raport Alfa 0.9.

📄 `docs/brand/KONSTYTUCJA_MARKI.md` · `docs/design/PORT_PROJEKTU.md` ·
`docs/design/WERYFIKACJA_ALFA_09.md`


## D-207 · Kompozycja wizualizacji jest kryterium portu, nie sama paleta

13 września 2026, zgłoszenie właściciela i issue #501. Właściciel porównał
wzorzec „Dzień dobry, Basiu” z produkcją „Witaj, Mateusz” i wymagał
odtworzenia wzorca. Poprzedni audyt tokenów i reflow nie dowiódł zgodności
kompozycji. Dokładnego źródła HTML tego obrazu nie znaleziono w dostępnej
historii, również w gałęzi PR #456; źródłem odniesienia jest przesłany obraz.

Odtwarzamy hierarchię powitania, kafel z pierścieniem, trzy pozycje menu
komputerowego, nagłówek strumienia oraz ciemną i jasne karty boczne.
„Odkrywaj” wraca wyłącznie jako nazwa wejścia do publicznego strumienia
w menu komputerowym; „Szukaj” pozostaje wyszukiwarką. Stałe „Dzień dobry”
zastępuje „Witaj” zgodnie z nowym wzorcem; nie wprowadzamy rozpoznawania
pory dnia, płci ani automatycznej odmiany nazwy. Uaktualniono COPY_STYLE
i konstytucję 1.4, zamiast pozostawić sprzeczne aktywne instrukcje.

Granice: potwierdzony znak garnka, prawdziwe dane i uprawnienia, pięć
mobilnych pozycji, oba źródła strumienia oraz fallback tagów, wspomnienia,
notatki i podglądy tablicy pozostają. Brak demonstracyjnego ostrzeżenia
i fikcyjnych liczb z makiety jest zamierzony. Wymagane są pomiary
rzeczywistego CSS, oglądane zrzuty oraz kontrole ujemne z kopią poza repo
i MD5. Wynik scalenia i wynik produkcji raportujemy oddzielnie.

## D-208 · Publiczne kroki i blok „Ugotowałem” według wskazanej wizualizacji

13 września 2026, kolejne porównanie właściciela, issue #506. Otwarte
kolumny 01–03 i duży tytuł „Zdjęcie. Kilka słów. I rozmowa przy okazji.”
zastępują białe kafle „Jak działa”. Zaraz za nimi stoi ciemny blok
„Ugotowałem / Twój przepis. Czyjś dobry obiad.”, następnie dotychczasowa
tablica i wpisy. To zmiana kompozycji, nie usunięcie funkcji.

Nowy wzorzec odwraca wcześniejsze wymaganie zachowania na tym bloku
nagłówka „Przepis jest dobry wtedy, kiedy ktoś go ugotował” (GLOS_MARKI,
omówienie audytu, punkt 3). Teza o realnym wykonaniu i funkcja pozostają.
Tytuł hero „Pokaż, co dziś ugotowałeś” nie jest częścią tej zmiany.

Fotografia pochodzi z pierwszego elementu istniejącego publicznego kolażu,
z jego filtrami widoczności, gotowości mediów i aktywności autora. Podpis
wskazuje autora zdjęcia, nie udaje wykonania konkretnego przepisu. Przy
pustym kolażu blok jest tekstowy; nie tworzymy fotografii zastępczej ani
fikcyjnej aktywności. Linki prowadzą do prawdziwych tras, logowanie i
autoryzacja pozostają. Na małym ekranie kolumny układają się pionowo.

Odbiór wymaga obejrzenia kompozycji oraz pomiarów i kontroli ujemnych
rzeczywistego źródła. Sam brak overflow nie dowodzi zgodności ze wzorcem.

## D-209 · Dostarczony oryginał identyfikacji i audyt jej kompletności

13 września 2026, issue #508. Właściciel dostarczył pełny ZIP z pierwotną
wizualizacją oraz snapshotem plików marki z Alfa 0.11. Zachowujemy archiwum
w `docs/design/references/KuKing-styl-wizualizacja-konstytucja.zip` wraz
z sumą kontrolną i macierzą w `docs/design/AUDYT_PACZKI_MARKI_508.md`.
To uzupełnia historyczny brak źródłowego HTML odnotowany w D-207.

Archiwum nie zastępuje aktualnych decyzji, backendu ani danych. Nie cofamy
garnka do K, Inter do szeryfu, tekstów do nieprawdziwych obietnic ani
działających formularzy do symulacji. Jednocześnie nie uznajemy różnic
kompozycji za zgodność tylko dlatego, że kolory są podobne. Pełny port
pozostaje częściowy; konkretne luki są wymienione w macierzy.

Usuwamy sprzeczne instrukcje DESIGN_SYSTEM dotyczące SideNav, szerokości,
skali150% i menu karty. AGENTS kieruje najpierw do aktualnej konstytucji
przy pracy nad marką. Konfiguracja aplikacji jest sprawdzanym źródłem
listy dostępnych skal; historyczna tabela nie może jej zastępować.

## D-210 · Kompozycje wejścia, przepisu, profilu i własnych treści

13 września 2026, issue #509. Kontynuacja pełnego portu zamówionego przez
właściciela, na podstawie oryginalnego ZIP z D-209. Kolorystyczna zgodność
nie zastępuje układu: zaproszenie stoi obok formularza, opis przepisu obok
zdjęcia, a pojedynczy zestaw liczb profilu pod ciemnym nagłówkiem.
Akcje przepisu są poniżej hero, przed składnikami. Zastępuje to dawny wymóg
lokalizacji panelu w prawej szynie oraz dwóch responsywnych kopii liczb.

Nie zmienia to polityk, filtrowania statystyk, sposobów logowania ani danych.
Na stronie publicznej trzy karty wyjaśniają zeszyty, widoczność i eksport;
nie udają wykonania tych czynności bez zalogowania. Odbiór i ograniczenia
zapisuje docs/design/KOMPOZYCJE_MARKI_509.md. Brak wyniku nie oznacza sukcesu.

## D-211 · Zeszyty i zapisane przepisy w kompozycji marki

13 września 2026, issue #511. Oryginał dostarczony w D-209 pokazuje ciemne
karty zeszytów, ostatnie zapisy w głównej części strony oraz duże zdjęcia
nad tytułami przepisów w otwartym folderze. Przenosimy ten układ do
istniejących widoków. Zastępuje to dawny wymóg umieszczania ostatnich
zapisów w prawej szynie indeksu; inne zeszyty w szczególe pozostają w szynie.

Pełne tytuły, opisy, liczniki i odnośniki pochodzą z prawdziwych danych.
Nie kopiujemy fikcyjnych liczników ani niedziałających akcji prototypu.
Zachowujemy filtrowanie dostępu, kolejność zapisów, paginację oraz formularz
tworzenia zeszytu. Tekst pustego stanu nie obiecuje bezterminowego dostępu
do cudzej treści. Odbiór: docs/design/ZESZYTY_MARKI_511.md.

Pomiar klawiatury wykazał, że pełny długi tytuł jako link przekracza
dostępną wysokość ekranu. Kafel zachowuje tytuł jako tekst i jeden krótki
odnośnik „Zobacz przepis” z pełną nazwą dostępną; pseudo-element rozszerza
kliknięcie na kartę. Nie obcinamy tytułu ani nie zmniejszamy pisma.
Tę samą zasadę stosujemy do ostatnich zapisów oraz skrótów zeszytów
na profilu i w folderze: pełna nazwa i opis, jeden krótki fokus.

## D-212 · Kafle zainteresowań i zwykłe powiadomienia

13 września 2026, issue #513. Kompozycja dostarczonego prototypu obejmuje
duże kafle zainteresowań oraz zwykłe powiadomienie z awatarem, treścią
i akcją obok siebie, jeśli pozwala na to dostępne miejsce.
Przenosimy ją do istniejących formularzy, bez fikcyjnych tematów i danych.
Zaznaczenie pozostaje zielone zgodnie z semantyką wyborów aplikacji.

Zachowujemy trzy opcjonalne kroki, natywne checkboxy, prawdziwe promowane
tagi, pomijanie i POST z CSRF. Układ powiadomień obejmuje wyłącznie pięć
zwykłych typów: ugotowanie, komentarz, odpowiedź, obserwowanie i zapis.
Decyzje, zgłoszenia i pozostałe typy zachowują pełną treść i wszystkie akcje.
Kliknięcie nadal zapisuje odczyt przez formularz POST, nie odnośnik GET.

Stan implementacji i granice odbioru: docs/design/ZAINTERESOWANIA_POWIADOMIENIA_513.md.


## D-213 · Polecane tagi przed wyszukiwaniem

14 września 2026, issue #515. Pełny port kompozycji wskazanej w D-209
obejmuje otwarte kafle wyboru przed wpisaniem frazy. Dane pochodzą z
istniejącego Tag::promowane(), zgodnie z D-021: aktywne tagi, redakcyjna
kolejność i opis promocji. Nie powstaje nowy byt „temat” ani fikcyjna
popularność. Nazwa funkcji pozostaje „Polecane tagi”.

Brak promowanych danych daje uczciwy pusty stan. W obu stanach pozostaje
droga do wszystkich tagów i aktualności;
samo wdrożenie kodu nie tworzy produkcyjnych tagów. Wyszukiwanie wyników,
zakresy, prywatność, GET oraz tablica dań zachowują dotychczasowe działanie.
Krótki link w kaflu ma pełną nazwę dostępną; długi tytuł pozostaje widoczny.

## D-214 · Zdjęcia w pustej szynie cudzego profilu

14 września 2026, zgłoszenie właściciela #551. Cudzy profil bez tagów i
zeszytów zostawiał pustą prawą część szerokiej ramy. Uzupełniamy ją trzema
ostatnimi widocznymi wpisami z gotowymi zdjęciami. Tagi i zeszyty nadal mają
pierwszeństwo; własny profil zachowuje skróty. Próbka jest niezależna od
zakładki, roku i strony archiwum. Brak zdjęć nie tworzy pustego bloku.

Dane wybiera kontroler przez published i ten sam filtr widoczności co
archiwum, przed limitem. Nie powstaje publiczna galeria prywatnych mediów.
Krótka data jest rzeczywistym odnośnikiem do wpisu; pseudo-element rozszerza
kliknięcie na miniaturę bez dodatkowego przystanku Tab. To uzupełnienie
istniejącej szyny, nie nowy licznik aktywności ani ranking.

## D-215 · Publiczna tablica zaczyna się od dużych fotografii

15 września 2026, ponowne zgłoszenie właściciela #557 ze zrzutem strony
dla gościa. Historyczne ustawienie osób obok dań z #365 nie jest już
akceptowaną kompozycją tego pasa. Na stronie powitalnej najpierw pokazujemy
duże fotografie dań i ich opisy, następnie zwarte wizytówki osób oraz jedno
wspólne zaproszenie gościa do rejestracji. Kolejność jest w DOM, nie przez
CSS order. Pozostałe szyny zachowują dotychczasowy wariant i jego testy.

Dobór osób i wpisów, uprawnienia, notatki redakcyjne oraz informacja o braku
rankingu pozostają. Większa fotografia korzysta z feed; gotowość mediów
i fallback przepisu nadal obowiązują. Odnośnik dania pozostaje pojedynczy,
z fokusem na nazwie i klikaniem karty przez pseudo-element. Nie wprowadzamy
fikcyjnych treści ani pustych kart do wyrównania siatki. Wariant bez danych
zachowuje dotychczasowe prawdziwe wyjaśnienie i link odkrywania.

## D-216 — Niezależna wysokość kolumn świeżych wpisów (15 września 2026)

Właściciel w #560 wskazał pustkę pod krótszą kartą. Zmieniamy wyrównywanie
rzędów z #365: karta trzecia zaczyna się pod pierwszą, czwarta pod drugą.
Nie dzielimy DOM na dwie listy ani nie używamy CSS columns. Chronologiczna
kolejność HTML, czytnika i Tab zostaje; na telefonie nadal jest jedna kolumna.
Na szerokim ekranie czytanie wzrokiem może przechodzić między różnymi
wysokościami — to koszt żądanej kompozycji, nie powód zmiany kolejności danych.
Mały moduł ResizeObserver aktualizuje pozycje po zmianie rozmiaru kart;
brak skryptu zachowuje funkcjonalną siatkę, choć z dawnymi przerwami.

## D-217 — Szybki wygląd bez wymogu konta (15 września 2026)

Właściciel zaakceptował panel Aa · Wygląd: istniejące skale70–140, jasny
i ciemny motyw, reset100/light, natychmiastowy podgląd i zapis. Konto
pozostaje źródłem dla zalogowanego; gość dostaje serwerowe cookie skali
i motywu. LocalStorage zapisuje tylko zamknięcie jednorazowej podpowiedzi.
Nie dodajemy motywu systemowego, nowej skali całej strony ani migracji.
Panel nie zastępuje dostępności aplikacji i powiększenia przeglądarki.

## D-218 — Panel moderacji korzysta z aktualnej marki (15 września 2026)

Właściciel pokazał ekran Zgłoszeń w dawnej oprawie i zażądał przeniesienia
panelu do nowego stylu (#581). Odrębny tryb moderacji pozostaje: nazwa,
tarcza, tytuł karty, nawigacja narzędzi i powrót do serwisu. Zmienia się
jego kompozycja — neutralna powierzchnia nawigacji, czytelne nagłówki,
karty, formularze, filtry i tabela zgodne z aktualnymi tokenami marki.
Historyczny akcent musztardowy na całej szynie nie jest już wzorcem.

D-089, D-129 i D-138 pozostają: bez pustej prawej szyny, jedno wejście do
panelu poza nim, pełna szerokość pracy w panelu. D-144 wymaga odbioru
rzeczywistych pełnych danych, nie tylko pustych ekranów. Pozostają wszystkie
uprawnienia, 2FA, trasy, formularze, ostrzeżenia i potwierdzenia operacji.
Zmiana oprawy nie zmienia znaczenia moderacyjnych decyzji ani danych.

Zakres testów i status wdrożenia: docs/design/PANEL_MODERACJI_MARKA_581.md.

## D-219 — Mniejszy tekst zagęszcza układ (#589, 15 września 2026)

Na jawne polecenie właściciela wartości 70/80/90% zmniejszają również odstępy i zapas wewnątrz kontrolek, zamiast pozostawiać mały tekst w dużych powierzchniach. Osobny współczynnik min(1, user-text-scale) zachowuje domyślne odstępy przy 100% i 140%; duży tekst naturalnie zwiększa potrzebną wysokość. Ważne cele dotykowe mają nadal minimum 48 px. Nie zmieniamy szerokości kontenerów, breakpointów ani proporcji zdjęć i nie stosujemy globalnego zoomu/transform. Nie obiecujemy liniowej skali całej geometrii. To uzupełnienie D-217.

## D-220 — Zwijanie narzędzi panelu na telefonie (#581, 17 września 2026)

Odbiór produkcyjny wykazał, że pełny spis narzędzi odsuwa kolejkę poza pierwszy
ekran telefonu. Poniżej 64rem JavaScript początkowo zwija tę samą listę;
przycisk „Nawigacja panelu” pozwala ją rozwinąć. Powrót do Kuking pozostaje
poza zwijanym obszarem. Bez JavaScriptu i na komputerze lista jest widoczna.
Nie zmieniamy linków, uprawnień ani 2FA. Escape zamyka menu i oddaje fokus
przyciskowi; zmiana szerokości nie chowa skupionego linku. To uzupełnia
D-218, a nie zmienia zakresu uprawnień moderacji.

## D-221 — Poradźcie korzysta z wpisów i wspólnej rozmowy (#372, 18 września 2026)

Pytanie jest wpisem kind=question z tytułem 10–180 znaków, opcjonalnym opisem,
jednym zdjęciem i najwyżej trzema tagami. Nazwa Poradźcie i adresy /pytania
realizują D-147/D-163. Formularz działa zwykłym POST; podpowiedzi hashtagów
są ulepszeniem. Nie powstaje drugi system komentarzy ani powiadomień.

Główne komentarze są odpowiedziami, zagnieżdżone zachowują rozmowę. Widoczność
odpowiedzi respektuje dotychczasowe blokady i statusy. Kolejka gospodarza
czeka na główną odpowiedź innej osoby widoczną dla pytającego. Publiczny
szczegół emituje QAPage z widocznymi odpowiedziami, bez udawania odpowiedzi
zaakceptowanej. Lista jest chronologiczna, z filtrem bez odpowiedzi i tagiem.

Usunięcie treści odpowiedzi z dziećmi zapisuje body_removed_at, zachowując
wątek. Ślad nie jest odpowiedzią w licznikach ani QAPage. Nie odgadujemy
historycznych usunięć z samego zdania „Komentarz usunięty.”. Down migracji
odmawia utraty istniejących znaczników, także w miękko usuniętych wierszach.

Flaga kuking.questions.enabled pozostaje domyślnie false i obejmuje także
profile, feedy oraz powiadomienia. Surowe operacje utrzymaniowe zachowują dane.
Uruchomienie publiczne wymaga odbioru i etapów z #372; samo scalenie kodu
nie jest dowodem uruchomienia funkcji. Stan kontroli i ograniczenia są w
docs/product/ODBIOR_PORADZCIE_372.md.

## D-222 — Fotograficzny katalog tagów (#681, 18 września 2026)

Na prośbę właściciela katalog A–Z korzysta z szerszej ramy i dużych kart.
Zdjęcie pochodzi z publicznego wpisu dostępnego dla oglądającego, przez
istniejący TagCollage. Podpis wskazuje autora; brak zdjęcia daje znak marki,
bez obrazów zastępczych udających treść użytkowników. Cała karta prowadzi
do tagu. Alfabet, paginacja i zasady publicznych liczników pozostają.

Pobieranie zdjęć obejmuje jednym batchem tagi promowane i bieżącą stronę
katalogu. Poszerzenie ramy dotyczy katalogu, nie formularzy ani wszystkich
stron tekstowych. Tekst na zdjęciu ma stały ciemny podkład również po
zawinięciu. Odbiór i ograniczenia: docs/design/FOTOGRAFICZNE_TAGI_681.md.

## D-223 — Martwe reguły CSS: strażnik pyta o wynik kaskady, nie o tekst arkusza (20 września 2026)

`resources/css/app.css` linia 1 ustawia `@layer theme, base, components, marka,
utilities;`. Warstwa późniejsza bije wcześniejszą niezależnie od szczegółowości
selektora i niezależnie od zapytania medialnego. W repozytorium żyją przez to
reguły z komentarzami uzasadniającymi konkretne wartości, których przeglądarka
nigdy nie widzi. Komentarz opisuje wtedy stan nieistniejący, a następny człowiek
czyta go jak prawdę i na nim buduje.

### Co zmierzono przy `.przepis-liczby` — i dlaczego wynik jest inny, niż zakładano

Zlecenie pytało, czy `10rem` z `marka-ekrany.css` zamiast `7rem` z `app.css`
psuje coś realnego przy 320 px i powiększonym piśmie. Odpowiedź: **nie psuje, bo
ŻADNA z tych dwóch wartości nie działa.** Jedyny nosiciel `.przepis-liczby`
(`pages/recipes/show.blade.php`) stoi wewnątrz `.marka-przepis-tekst`, a
`marka-przepis.css` robi z niego `display: flex`. Na kontenerze flex
`grid-template-columns` nie znaczy nic. Przykrycie `7rem` przez `10rem` było
prawdziwe i zarazem bez znaczenia — spór o wartość toczył się o własność, która
i tak nie dochodzi.

Zmierzone w przeglądarce, nie wyczytane z arkusza: wymuszenie `7rem` tam, gdzie
wartość naprawdę by obowiązywała, dało geometrię kafel-w-kafel **identyczną co do
piksela w 30 konfiguracjach na 30** (dwa przepisy × 320/360/1280 px × pięć
wariantów pisma). Zero przewijania w poziomie, zero ucięcia tekstu.
Dowód: `docs/design/evidence/kaskada223/`.

Dlatego **wartości nie ruszamy i reguł nie usuwamy** — poprawiono wyłącznie
komentarze, żeby przestały uzasadniać liczbę, której nie ma. Usunięcie martwej
reguły JEST zmianą zachowania na wypadek, gdyby `marka-przepis.css` zniknął,
i jest osobną decyzją.

### Trzy rzeczy, które ten pomiar ujawnił przy okazji

1. **Osiem arkuszy nie jest owiniętych w żadną warstwę** (`marka-przepis`,
   `marka-panel`, `marka-powiadomienia`, `marka-rama`, `marka-szukaj`,
   `marka-wejscie`, `marka-zeszyt`, `pasek-przewijany`, `szybki-wyglad`).
   Kod spoza warstw bije KAŻDĄ warstwę nazwaną, także `utilities` — istnieje
   więc faktyczna warstwa najwyższa, której instrukcja `@layer` nie wymienia.
   Komentarz przy imporcie twierdzi, że „każdy z nich dopisuje własne klasy do
   @layer components". Dla tych ośmiu to nieprawda.
2. **Instrukcja `@layer a, b, c;` NIE PRZEŻYWA BUDOWANIA.** W zbudowanym
   arkuszu zostają same bloki `@layer nazwa { … }`, a kolejność wynika z ich
   pierwszego wystąpienia. Dochodzi też wewnętrzna warstwa Tailwinda
   `properties`, PRZED `theme` — w źródle jej nie ma.
3. **Zapytania medialne są budowane w składni zakresowej** (`(width >= 48rem)`),
   nie `(min-width: 48rem)`. Narzędzie szukające `min-width` znajduje zero
   progów i wygląda wtedy na zielone.

Wszystkie trzy są argumentem za tym samym: **o CSS trzeba pytać przeglądarkę, nie
plik.** Strażnik czytający źródło mierzyłby tu co innego, niż widzi użytkownik.

### Strażnik

`scripts/kaskada-martwe-reguly.mjs` wykrywa deklarację z warstwy wcześniejszej
całkowicie przykrytą przez warstwę późniejszą na tej samej własności i tym samym
elemencie. Tekst arkusza służy wyłącznie do ZAWĘŻENIA listy kandydatów.
Rozstrzyga pomiar: deklarację zdejmujemy z żywej reguły na wyrenderowanej
stronie, porównujemy `getComputedStyle` każdego pasującego elementu przed i po,
i przywracamy. Brak różnicy we wszystkich mierzonych konfiguracjach znaczy, że
deklaracja nie zmienia nic.

Strażnik nie jest listą znanych przypadków: kolejność warstw czyta z przeglądarki
(pierwsze wystąpienie warstwy), reguły obchodzi rekurencyjnie przez `@layer`,
`@media` i `@supports`, a szerokości bierze z progów znalezionych w arkuszu —
więc czwarta warstwa i piąty arkusz wchodzą do pomiaru same. Jedyna lista nazw
w tym pliku to WYJĄTKI i każdy ma przy sobie powód.

Strażnik ma własną samokontrolę: brak wykrytych warstw albo zero przepytanych
deklaracji to BŁĄD PRZYRZĄDU (kod 2), nie wynik pozytywny. Nie jest to ozdoba —
pierwsza wersja tego strażnika czytała kolejność warstw z instrukcji `@layer`,
której zbudowany arkusz nie zawiera, i meldowała „✓ żadna reguła nie jest
przykryta", nie sprawdziwszy ani jednej. Samokontrola to złapała.

### Czego ten strażnik nie mierzy

Selektorów ze stanem interakcji (`:hover`, `:focus`), selektorów bez nosiciela na
mierzonych stronach i reguł o zasięgu masowym (ponad 300 elementów — wewnętrzne
reguły Tailwinda). Wszystkie trzy są RAPORTOWANE jako `niezmierzone`, nigdy
pomijane po cichu: cisza wyglądałaby jak wynik pozytywny.

## D-1009-ROBOCZA — Pierwszy wkład jest jednorazowym zdarzeniem (21 września 2026)

Numer ostateczny przydziela koordynator przy scalaniu. Właściciel rozstrzygnął
wprost: pierwszy wkład nie powtarza się po usunięciu wpisu. Zatwierdził także
odtworzenie tylko na podstawie zachowanych danych, bez zaległych alertów;
pełna gwarancja zaczyna się od wdrożenia.

Pamięć należy do autora, nie do powiadomienia ani aktualnego gospodarza.
`first_post_events` utrwala jeden nośnik; usunięcie go pozostawia zdarzenie,
zmiana gospodarza nie wywołuje reemisji. Inny moderator zobaczy oznaczenie
tylko przy tym nośniku i tylko jeśli ma dostęp. Nie dostaje alternatywnego
„pierwszego” publicznego wpisu, gdy nośnik był followers poza jego zasięgiem.
Bez gospodarza pierwszy publiczny wkład zużywa pierwszeństwo bez alertu;
followers bez dostępnego odbiorcy nie zużywa go. Historia jest odtwarzana
najpierw z alertów, potem z zachowanych dostępnych wpisów, w tym soft-deleted.

Publikacja serializuje autora po blokadach mediów (D-103), przed INSERT.
Wpis, znacznik, audyt, alert i enqueue są jedną transakcją. Standardowy
dispatch pozostaje: gwarancja trwałego enqueue dotyczy database queue na
identycznym obiekcie połączenia. Odmienny connection database odmawia przed
zapisem; sync/fake zachowują dotychczasowy kontrakt testowy, nie stanowią
dowodu trwałości. Zlecenie `low` ma beforeCommit, worker widzi je po commit.
Nie naprawiamy historycznych częściowych publikacji z #935 ani retencji.
Rollback jest wąsko chroniony zgodnie z D-088 (szczegóły: DATABASE.md).

## D-224 — Wpis wychodzi z zeszytu tam, gdzie widać, że w nim jest (audyt L1, 20 września 2026)

Trasa `DELETE /wpisy/{post}/zapisz` (`collections.unsave-post`) istniała,
była otestowana i bezpieczna, ale żaden widok jej nie wołał. Zdanie z D-081
„wyjąć z zeszytu można nadal w samym zeszycie" było nieprawdziwe: ekran
zeszytu renderuje tę samą kartę wpisu. Właściciel rozstrzygnął: przycisk
stoi wszędzie tam, gdzie widać „Masz to w zeszycie" — w zeszycie i na karcie.
Trasy nie kasujemy.

> **Sprostowane 20 września 2026 — patrz D-231.** Zdanie „przycisk stoi
> wszędzie tam, gdzie widać »Masz to w zeszycie«" przestało być prawdziwe na
> JEDNYM ekranie: w środku konkretnego zeszytu nie ma już ani odnośnika „Masz
> to w zeszycie", ani przycisku „Usuń z zeszytu" — stoi tam wyłącznie „Usuń
> z tego zeszytu" o zakresie lokalnym. Poza zeszytem wszystko poniżej zostaje
> bez zmian. Reszta D-224 — brak potwierdzenia przed akcją, droga powrotu po
> niej, brak JavaScriptu, granica ostrzejsza niż Policy — obowiązuje dalej.
> Zmienił się też sam komunikat: nazywa teraz FAKTYCZNY zakres (D-231).

Przycisk stoi OBOK odnośnika „Masz to w zeszycie", nie zamiast niego. Miejsce,
w które przed chwilą kliknięto „Zapisuję", zajmuje dalej odnośnik do zeszytu,
więc drugie kliknięcie (norma w tej grupie, issue #43) niczego nie zabiera.
Zmierzone: przycisk 207 × 50,5 px przy 320 px i 260 × 59,5 px przy tekście
140%, pismo 18 i 25,2 px, 10 px przerwy od odnośnika, bez przewijania w bok.

Bez potwierdzenia i bez JavaScriptu. Wyjęcie nie kasuje treści i cofa się
jednym kliknięciem, więc pytanie „czy na pewno" zostaje dla rzeczy
nieodwracalnych — kasowania wpisu i kasowania zeszytu. Zamiast pytania PRZED
akcją jest droga powrotu PO niej: komunikat „Wpis wyjęty z zeszytu. Nie
usunęliśmy go z serwisu — możesz go zapisać ponownie." i przycisk „Zapisz
ponownie" w tym samym obszarze `aria-live` (`status_powrot` w sesji).

Nazwa jest ta sama co przy przepisie — „Usuń z zeszytu" (`BRAND_EXTENDED.md`
§3: jedna czynność, jedna nazwa). Audyt proponował „Wyjmij"; to byłby drugi
synonim na tę samą rzecz.

Zakres akcji pozostaje przypięty do zeszytów osoby, która wysłała żądanie
(`SavePostToCollection::remove()`), czyli jest ostrzejszy niż Policy: obca
osoba nie rusza cudzego wiersza, a wpis, którego nie wolno już oglądać, daje
się z zeszytu wyjąć. Dowody: `tests/Feature/WpisDaSieWyjacZZeszytuTest.php`
i `scripts/wyjecie-z-zeszytu.mjs`.

## D-225 — Godzinny podpis zdjęcia publicznego, bez cache sesji (#597/#610)

20 września 2026, jawna decyzja właściciela w zadaniu `gpt/cloudflare-cache`:
„Zaakceptuj godzinę dla wcześniej publicznego zdjęcia”. Podpis wydany, gdy
Policy dopuszcza anonima, może działać po zmianie widoczności do końca tej
godziny. Z 30-minutowym cache bajtów okno może sięgnąć 90 minut od wydania.
Treści dostępne wyłącznie prywatnie zachowują podpis do 5 minut i no-store.
Najszerszy rodzic i kontrola Policy z D-020 pozostają bez zmian.

Odpowiedzi z sesją, ciasteczkiem albo logowaniem nie trafiają do wspólnego
cache, także dla publicznych zdjęć. Publiczny odczyt zdjęcia bez stanu
klienta nie wystawia sesji. Ta decyzja nie dopuszcza cache HTML z sesją
ani nie ustala opóźnienia ukrycia HTML. Projekt reguł, bramka i ograniczenia:
`docs/infra/CLOUDFLARE_CACHE_597_610.md`. Konfiguracji Cloudflare nie zmieniono.

## Uzupełnienie #369 — Próg prezentacji publicznej aktywności (20 września 2026)

Właściciel zatwierdził pozostawienie **5 zdjęć / 3 osób wyłącznie jako progu
prezentacji publicznej aktywności, bez obietnicy anonimowości**. Nie jest to
próg ochrony tożsamości ani ograniczenie dostępu do publicznych wpisów.

Pomiar lokalny na syntetycznych danych: gość bez JavaScriptu mógł odczytać
wszystkich autorów z kart dla tagów z 2, 3, 5, 10 i 42 osobami; ostatni
przypadek wymagał przejścia trzech stron. Podnoszenie samego progu nie
ukrywa autorstwa kart. Wynik nie jest badaniem danych ani użytkowników
produkcji. Decyzja zachowuje istniejące liczby i zachowanie; nie rozszerza
zakresu statystyk o prywatne treści ani ranking.

Dowody i granice odbioru: [pomiar tagów](research/tagi-miejsce-2026-09-20/RAPORT.md).

## D-227 — PostgreSQL 18 jest wymaganiem, nie preferencją

Data: 20 września 2026. Decyzja właściciela.

**Co zdecydowano.** Wymagana wersja PostgreSQL to **18** — lokalnie, w CI
i na produkcji. Wcześniej `AGENTS.md` mówił „lokalnie i w CI wystarczy 16+".

**Dlaczego.** Szesnastka opisywała stan, którego już nigdzie nie ma: CI stawia
`postgres:18-alpine` w sześciu usługach, produkcja ma 18, lokalny klaster
18.6. Reguła, która dopuszcza konfigurację nieistniejącą u nikogo, nie chroni
przed niczym — a przy tym usypia: każdy czyta ją jako „przetestowane na 16".

**Numer.** Ta decyzja nosiła najpierw D-223. Po awarii 20.09 o ten sam
numer stanęły trzy różne rozstrzygnięcia z trzech odzyskanych gałęzi, a
`NumeryDecyzjiMajaWpisyTest` łapie duplikat numeru dopiero PO scaleniu —
czyli wtedy, gdy odnośniki w kodzie już wskazują na dwie decyzje naraz.
Numer przyznano tej pracy, która ma najmniej odnośników z zewnątrz:
tutaj dwa, oba w `DEPLOYMENT_RUNBOOK.md`. Strażnik martwych reguł CSS
zostaje przy D-223, bo jego numer siedzi w jedenastu miejscach i w nazwie
katalogu dowodów `docs/design/evidence/kaskada223/`.

**Kolejność zmiany jest częścią decyzji.** Najpierw reguła w `AGENTS.md`
(`68099722`), dopiero potem próg w strażniku R60 (`d2ffccac`). Odwrotna
kolejność uczyłaby, że regułę wolno wyprzedzić testem — a `AGENTS.md` jest
jedynym źródłem prawdy projektu.

**Zakres.** Zmienione cztery miejsca stawiające wymóg: tabela stacku
w `AGENTS.md` i jej kopia w `README.md`, wymagania uruchomienia w `README.md`
oraz wymagania własnego runnera w `docs/infra/CI_BEZ_ACTIONS.md`.

**Czego świadomie NIE zmieniono.** Zapisów o POMIARACH wykonanych na 16.13
(`SearchQuery`, `ProgPodobienstwa`, migracja z 9 września) ani notek „od
PostgreSQL 17…" w migracjach i `docs/DATABASE.md`. To są fakty o silniku
i cudze pomiary — przepisanie ich na 18 sfałszowałoby czyjś wynik.

**Skutek dla runbooka.** `DEPLOYMENT_RUNBOOK.md` §6.3 zachowuje wariant „weź
17 i zrób upgrade in-place", ale **wyłącznie jako drogę awaryjną odtworzenia
po awarii**, gdy dostawca nie oferuje 18 w danej chwili. Nie jest to
dopuszczalny stan docelowy, a upgrade staje się wtedy zadaniem do domknięcia.
Procedurę trzymamy, bo improwizowanie jej w kryzysie kosztuje więcej niż
zapisanie z góry.

**Dowód, że próg nie jest martwą liczbą.** Podbicie go na chwilę na 19 oblewa
strażnika komunikatem „PostgreSQL 18 jest starszy niż wymagane 19+". Bez tego
„18" byłoby liczbą stojącą obok porównania, które i tak zawsze przechodzi.

## D-229 — Powiadomienie śledzi treść komentarza (#758, 20 września 2026)

**Przenumerowane z D-223.** Ta decyzja nosiła pierwotnie numer D-223 — pod tym
samym numerem stała też rodzina strażnika martwej kaskady CSS (`flota/kaskada`
/ `flota/martwe-kaskady` / `flota/scal-915`). Rozstrzygnięcie właściciela
21 września 2026: numer D-223 zostaje przy rodzinie kaskady, ta decyzja
przechodzi na D-229 (pierwszy naprawdę wolny numer, sprawdzony przeglądem
wszystkich gałęzi repozytorium). **Kryterium: koszt przeniesienia.** Rodzina
kaskady miała w kodzie **16 odwołań** do D-223, ta decyzja tylko **13** —
przenosi się ta strona, po której trzeba poprawić mniej miejsc.

Decyzja właściciela. Wycinek treści komentarza w powiadomieniu jest **liczony
przy wyświetlaniu, z aktualnej treści** — jedno źródło prawdy, nie zamrożona
kopia w `notifications.data`. Do tej zmiany `PublishComment` wpisywał do
`data.excerpt` 120 znaków z chwili publikacji i nikt tego nigdy nie odświeżał:
autor poprawiał „dodaję dwie łyżki masła" na „dwie łyżeczki" w dozwolonym
oknie 15 minut, wątek pokazywał poprawkę, a powiadomienie dalej mówiło „łyżki".

**Eksport RODO zmienia się tak samo**, i to jest część decyzji, a nie jej
skutek uboczny: paczka z danymi ma pokazywać, co o kimś trzymamy **dziś**,
a nie historyczną wersję. Zamrożony wycinek opisywałby stan, którego w bazie
już nie ma.

Ta decyzja **uchyla dawne zdanie o powiadomieniu jako migawce zdarzenia
z przeszłości — ale wyłącznie dla wycinka treści**. D-052 w części
o nieuruchamianiu skutków ubocznych przy edycji zostaje w mocy: edycja
komentarza nadal nie zleca ponownej analizy moderacyjnej, nie tworzy nowego
powiadomienia i nie przywraca `read_at` do `null`.

Granica z #757 obowiązuje niezależnie i jest ważniejsza od tej decyzji:
komentarz usunięty (soft delete albo `body_removed_at` przy usunięciu
komentarza z odpowiedziami), ukryty przez moderację albo niedostępny dla
odbiorcy **nadal nie pokazuje treści** — ani na ekranie, ani w paczce.
Brak żywego wycinka **nigdy** nie sięga po starą kopię z `data` jako plan
zapasowy; dla tych typów powiadomień `data.excerpt` w ogóle nie jest już
czytany, a nowe wiersze przestają go zapisywać.

Koszt liczymy zbiorczo (D-196): `Notification::zyweWycinkiKomentarzy()`
dociąga wycinki **jednym zapytaniem** na całą stronę listy i jednym na cały
eksport, wzorem `NotificationController::decyzje()`. Zmierzone:
lista powiadomień 16 → 17 zapytań przy 2 wierszach i 66 → 67 przy 12
(koszt wiersza bez zmiany, 5 zapytań — to oś #759, nie ta zmiana);
eksport 25 → 26 zapytań, niezależnie od liczby powiadomień.

**Stare kopie znikają z bazy, nie tylko z ekranu.** Migracja danych
`2026_09_23_120000_usun_zamrozone_wycinki_komentarzy` zdejmuje `excerpt`
z `notifications.data` wyłącznie w `comment.created` i `comment.replied` —
„nie trzymamy", a nie tylko „nie czytamy". Decyzja właściciela z 23 września
2026: wchodzi **bez kopii bazy** („to jeszcze nie produkcja, nie ma
prawdziwych użytkowników"), więc skasowanych wycinków nic już nie odtworzy.
`down()` jest świadomie pusty i nie odmawia — uzasadnienie według D-088
w `docs/DATABASE.md`, w sekcji tej migracji.

## D-231 — Jedna droga wyjęcia wpisu z zeszytu, a zakres wybiera ekran (#775, #776 + D-224, 20 września 2026)

Dwie prace powstały równolegle i nie wiedziały o sobie. #789 (D-224) dało
przycisk wyjęcia wszędzie tam, gdzie widać stan zapisu, o zakresie GLOBALNYM
(wszystkie zeszyty widza). #776 dało przycisk wyjęcia o zakresie LOKALNYM
(`collection_id`), ale tylko wtedy, gdy karta stoi w środku zeszytu, którego
widz jest właścicielem. Złożone wprost renderowały się OBOK SIEBIE: na ekranie
zeszytu stały dwa przyciski o prawie identycznych nazwach — „Usuń z zeszytu"
i „Usuń z tego zeszytu" — i różnym zasięgu. Dla grupy 50+ to gorsze niż brak
którejkolwiek drogi: zły wybór kosztuje tu dane w zeszytach, o których nikt
w tym momencie nie myślał.

**Zakres wybiera EKRAN, nie człowiek.**

1. W środku konkretnego zeszytu, gdy widz jest jego właścicielem, stoi
   wyłącznie **„Usuń z tego zeszytu"** — `collection_id` wskazuje ten zeszyt,
   zapis w pozostałych zeszytach zostaje razem z notatką i datą (#775).
   Odnośnik „Masz to w zeszycie" w tym miejscu znika: prowadzi do listy
   zeszytów, a człowiek stojący W zeszycie już wie, że wpis tam leży.
2. Poza zeszytem — w strumieniu, na profilu, w wyszukiwarce, na stronie wpisu
   i na stronie przepisu — stoi wyłącznie **„Usuń z zeszytu"** o zakresie
   globalnym, obok odnośnika „Masz to w zeszycie" (D-224). Nie ma tam „tego
   zeszytu", do którego dałoby się odnieść.
3. **Nigdy oba naraz.** Dowodem jest scena
   `WpisDaSieWyjacZZeszytuTest::test_na_ekranie_jest_dokladnie_jedna_droga_wyjecia`
   — liczy formularze wyjęcia na obu ekranach i sprawdza, że nazwa tej drugiej
   drogi nie pada tam wcale.

**Potwierdzenie PRZED akcją nie wraca.** #775 dokładało na stronie przepisu
`x-confirm-button` z pytaniem „czy na pewno ze wszystkich zeszytów". D-224
rozstrzygnęło odwrotnie i to rozstrzygnięcie zostaje: wyjęcie z zeszytu jest
odwracalne, a pytanie przed każdą odwracalną czynnością uczy odklikiwania
i psuje wagę pytań przy rzeczach naprawdę nieodwracalnych (kasowanie wpisu,
kasowanie zeszytu). Strona przepisu wraca więc do zwykłego formularza DELETE.

**Ale zarzut #775 był słuszny i jest spełniony inaczej.** Brzmiał „usuwa ze
wszystkich zeszytów BEZ UJAWNIENIA ZAKRESU", nie „usuwa bez pytania". Zakres
nazywa więc komunikat PO akcji, i nazywa go LICZBĄ FAKTYCZNĄ:
`SavePostToCollection::remove()` i `SaveRecipeToCollection::remove()` oddają,
z ilu zeszytów naprawdę wyjęto.

- zakres lokalny: „Wpis wyjęty z zeszytu „Obiady". Nie usunęliśmy go
  z serwisu — możesz go zapisać ponownie."
- zakres globalny, kilka zeszytów: „Wpis wyjęty z 3 Twoich zeszytów. …"
- zakres globalny, jeden zeszyt: „Wpis wyjęty z zeszytu. …" — bo zdanie
  o „wszystkich Twoich zeszytach" przy jednym zeszycie straszy bez powodu,
  a straszenie bez powodu uczy ignorowania komunikatów tak samo jak pytanie
  bez powodu.

**Droga powrotu wraca TAM, SKĄD WYJĘTO.** „Zapisz ponownie" (D-224) dostaje
`pola` — po wyjęciu lokalnym niesie `collection_id` tego zeszytu. Bez tego
cofnięcie odkładałoby wpis do zeszytu DOMYŚLNEGO, czyli cicho przenosiłoby go
gdzie indziej; cofnięcie ma przywracać stan, nie tworzyć nowy.

**Nazwy.** „Usuń z zeszytu" i „Usuń z tego zeszytu" nigdy nie stoją razem,
więc jedna nie jest pułapką na drugą, a ekran zawsze niesie kontekst. Trzecie
słowo na tę samą czynność („Wyjmij") byłoby złamaniem `BRAND_EXTENDED.md` §3.

**D-081 zostaje w mocy** — tablica „kuKINGi na dziś" dalej świadomie nie
dolicza stanu zeszytu.

Dowody: `tests/Feature/WpisDaSieWyjacZZeszytuTest.php`,
`tests/Feature/ZeszytUsuwaZapisanyWpisTest.php`,
`tests/Feature/UsuniecieZZeszytuMaZakresTest.php`,
`scripts/wyjecie-z-zeszytu.mjs`.


## D-230 — Złożenie `zeszyty` i `jedna-droga`: pytanie na ekranie globalnym wraca, komunikat mówi prawdę o notatce (#775, D-224, D-231, 21 września 2026)

*Ta decyzja nosiła najpierw numer D-229. Straciła go, bo tego samego dnia
dwaj agenci floty niezależnie dostali od właściciela informację, że „pierwszy
wolny numer to D-229" — jeden z nich (gałąź `gpt-n1-powiadomienia`) zajął go
jako pierwszy. Ponieważ ta gałąź miała mniej odwołań do numeru (9 wobec 14 w
`gpt-n1-powiadomienia`), koszt przenumerowania był tu niższy, więc numer
D-229 zostaje przy tamtej decyzji, a ta dostaje D-230.*

> **Sprostowane 22 września 2026 — patrz D-242.** Dwa rozstrzygnięcia poniżej
> przestały obowiązywać, bo przestała być prawdziwa przesłanka, na której obie
> stały: że „`detach()` kasuje notatkę i żadna droga powrotu jej nie odtwarza".
> Rdzeń z #1110 dołożył `restore()`, które przywraca zdjęte wiersze RAZEM
> z notatką i pierwotną datą zapisu. Dlatego:
>
> - **pytanie przed akcją na stronie przepisu (`<x-confirm-button>`) zostało
>   zdjęte** — wyjęcie jest teraz odwracalne w całości, więc wraca reguła
>   D-224 (nie pytamy przed czynnością, którą da się cofnąć), a zakres stoi
>   napisany NAD przyciskiem zamiast w pytaniu;
> - **zdanie „notatka przy nim już nie wróci" zostało zastąpione** zdaniem,
>   które obiecuje przywrócenie — bo notatka wraca.
>
> Reszta tej decyzji — droga wyjęcia na każdym ekranie ze stanem zapisu, jeden
> przycisk na ekran (D-231), liczba zeszytów w komunikacie — obowiązuje dalej.

Dwie gałęzie floty rozwiązały ten sam spór (#775) inaczej i obie miały rację
w jednej połowie. `zeszyty` dodała na stronie przepisu `<x-confirm-button>`
z pytaniem „czy na pewno ze wszystkich zeszytów", ale nie dotknęła
`post-card.blade.php` — na karcie wpisu poza zeszytem nie było żadnej drogi
wyjęcia (`WpisDaSieWyjacZZeszytuTest` obalał to na 4 z 12 scen). `jedna-droga`
dała tę drogę wszędzie i rozstrzygnęła D-231 (jeden przycisk na ekran, zakres
wybiera ekran, licznik zeszytów w komunikacie), ale przy okazji cofnęła
pytanie przed akcją na stronie przepisu — bo D-224 uznało wyjęcie z zeszytu za
w pełni odwracalne.

**Właściciel rozstrzygnął: żadna z tych prac osobno nie zamyka #775, razem
zamykają.** Bierzemy oba mechanizmy:

1. **Z `jedna-droga`**: drogę wyjęcia na każdym ekranie pokazującym „Masz to
   w zeszycie" (D-231 bez zmian) — `post-card.blade.php` dostaje przycisk
   lokalny w środku zeszytu i globalny poza nim, liczbę zeszytów w komunikacie
   (`Odmiana::rzeczownik()`), i „Zapisz ponownie" jako drogę powrotu, która
   wraca DOKŁADNIE tam, skąd wyjęto (`pola['collection_id']`).
2. **Z `zeszyty`**: `<x-confirm-button>` na stronie przepisu, jedynym ekranie
   o zasięgu GLOBALNYM (wyjmuje ze WSZYSTKICH zeszytów naraz).

**Dlaczego pytanie wraca tylko tam.** D-224 miało rację, że pytanie przed
KAŻDĄ odwracalną czynnością uczy odklikiwania. Ale wyjęcie globalne nie jest
w pełni odwracalne: `SavePostToCollection::remove()` i
`SaveRecipeToCollection::remove()` wołają `detach()`, który kasuje wiersz
pivotu RAZEM z `note` (`withPivot(['note'])`). „Zapisz ponownie" przywraca
sam fakt bycia w zeszycie — nie treść notatki, która przy nim stała. To jest
różnica jakościowa, nie kosmetyczna: przy zasięgu lokalnym (jeden, wybrany
zeszyt) ryzyko jest małe i znane z kontekstu ekranu, ale przy zasięgu
globalnym człowiek może stracić notatki w zeszytach, o których w tej chwili
nie myśli. Stąd pytanie PRZED akcją zostaje wyłącznie na ekranie globalnym,
a lokalne wyjęcie (D-231) zostaje jednym kliknięciem bez pytania.

**Komunikat po akcji przestaje obiecywać więcej, niż daje.** Obie gałęzie
pisały po usunięciu „Nie usunęliśmy go z serwisu — możesz go zapisać
ponownie", co sugerowało pełną odwracalność. Nowe brzmienie
(`CollectionController::komunikatPoWyjeciu()`):

- zakres lokalny: „{Przepis/Wpis} wyjęty z zeszytu „{nazwa}”. Możesz zapisać
  go ponownie, ale notatka przy nim już nie wróci."
- zakres globalny, N zeszytów: „{Przepis/Wpis} wyjęty z {N} Twoich zeszytów.
  Możesz zapisać go ponownie, ale notatka przy nim już nie wróci."
- zakres globalny, jeden zeszyt: „{Przepis/Wpis} wyjęty z zeszytu. Możesz
  zapisać go ponownie, ale notatka przy nim już nie wróci."

Zachowanie się nie zmienia — `remove()` i `detach()` robią dokładnie to samo,
co przed tą decyzją. Zmienia się wyłącznie zdanie: mówi teraz, co się NIE
wraca, zamiast sugerować, że wraca wszystko.

**Testy dwóch gałęzi wzajemnie się wykluczały** (`zeszyty` wymagała
`<details class="confirm">` na stronie przepisu, `jedna-droga` wymagała jego
braku) — złożone dają jeden zestaw sprawdzający stan docelowy:
`UsuniecieZZeszytuMaZakresTest::test_strona_przepisu_pyta_przed_usunieciem_i_nazywa_zakres_po_akcji`
zastępuje obie sprzeczne sceny i dokłada kontrolę dodatnią
(`test_strona_przepisu_nie_usuwa_zwyklym_delete_bez_potwierdzenia`).
`WpisDaSieWyjacZZeszytuTest` (issue #776, D-231) zostaje bez zmian zachowania
— dotyczy wyłącznie wpisów (Post), których ekran przepisu (Recipe) nie
obejmuje.

Dowody: `tests/Feature/WpisDaSieWyjacZZeszytuTest.php`,
`tests/Feature/UsuniecieZZeszytuMaZakresTest.php`,
`resources/views/pages/recipes/show.blade.php`,
`app/Http/Controllers/CollectionController.php`.

## D-233 — Rejestr potwierdzeń RODO tak, automatyczne kasowanie wpisów NIE (#1222 nie dotyczy)

22 września 2026, jawna decyzja właściciela przy odbiorze gałęzi
`naprawa/minimalne-potwierdzenie-rodo`. Gałąź robiła dwie rzeczy: zakładała
rejestr potwierdzeń obsługi żądań RODO z zapisem **atomowym, w tej samej
transakcji co skutek**, i włączała **automatyczne kasowanie tych wpisów po 36
miesiącach, domyślnie, bez przełącznika**. Właściciel przyjmuje pierwszą część
i wstrzymuje drugą.

Autor gałęzi uzasadniał brak przełącznika zdaniem „wyłącznik retencji to
bezterminowość pod inną nazwą”. Argument zostaje zapisany, bo jest sensowny
i bo za tydzień ktoś wyprowadzi go ponownie. Nie przeważa jednak dwóch rzeczy.
Po pierwsze, **okresu nie potwierdził prawnik**: 36 miesięcy to analogia do
dokumentacji sprawy moderacyjnej (art. 442¹ k.c., D-057 i ADR_RETENCJE §4), nie
ustalenie dla tej kategorii. Po drugie, kasowanie jest **twardym `DELETE`,
nieodwracalnym** — bez soft-delete i bez eksportu. Po jego włączeniu, dla kont,
których ostatnie zdarzenie RODO jest starsze od progu, na pytanie „czy i kiedy
usunęliście dane tej osoby” nie zostaje nic. Polityka prywatności mówi przy tym
o kopiach zapasowych: „Nie podajemy tu liczby dni, bo nie ustaliliśmy jej
jeszcze z dostawcą” — czyli nie jest znana nawet długość drogi odzysku.

Wyłączenie stoi na dwóch niezależnych barierach, żeby nie zdejmowała go jedna
pomyłka: `kuking.potwierdzenia_rodo.retencja_wlaczona` jest `false`, a zadanie
`kuking:sprzataj-potwierdzenia-rodo` **nie jest wpięte w `routes/console.php`**.
`retention_months` jest `null`, nie 36, więc samo przestawienie flagi nie
uruchamia kasowania według okresu, którego nikt nie potwierdził. Komenda
istnieje i jest przetestowana; `--na-sucho` działa mimo wyłączenia, bo tym mają
zostać przygotowane dane historyczne.

Ta decyzja **nie cofa** niczego, co gałąź zrobiła dobrze: dziewięciu ograniczeń
CHECK, braku ekranu dla tej tabeli (osobny test skanuje trasy i widoki),
zapamiętania zakresu żądania **przed** anonimizacją ani atomowości zapisu.
Wyłączenie ma być zdjęte świadomie, po potwierdzeniu okresu — droga w trzech
krokach stoi przy kluczu `potwierdzenia_rodo` w `config/kuking.php`
i w `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` §6.

Numer wzięty po sprawdzeniu gałęzi, nie tylko `main`: D-223 (kaskada), D-227
(#1164), D-228 (#966), D-229 (#1180), D-230 (#1168) są zajęte, a D-232 jest
zarezerwowany dla poprawki kolizji numeru w #1222. Niczego nie przenumerowano.

Pilnuje tego `tests/Feature/RetencjaPotwierdzenRodoTest.php` — obie strony:
że domyślnie nic się nie kasuje i że po jawnym włączeniu automat działa.

## D-238 — Cofnięcie migracji 2FA ODMAWIA, zamiast po cichu zdjąć drugi składnik (DB-01, 22 września 2026)

**Data:** 22 września 2026 · **Naprawa znaleziska z audytu** (DB-01 z
`docs/AUDYT_2026-09-13.md`, gałąź `claude/laughing-edison-sz4k69`) ·
Status: **obowiązuje**

### Co było zepsute

`down()` migracji `2026_09_06_120000_add_two_factor_to_users_table` kasowało
bezwarunkowo cztery kolumny: `two_factor_secret`, `two_factor_backup_codes`,
`two_factor_confirmed_at`, `two_factor_last_used_at` — a wcześniej zdejmowało
CHECK `users_two_factor_confirmed_requires_secret_check`.

Sekret TOTP jest zaszyfrowany i nie ma go skąd odtworzyć. Kody zapasowe są
trzymane wyłącznie jako skróty. Po cofnięciu nie da się przywrócić ani
jednego, ani drugiego.

### Dlaczego to nie było „świadome", tylko przeoczone

Migracja **broniła się własnym komentarzem**: „nikt nie zostaje zablokowany,
bo wymóg drugiego składnika znika razem z kolumnami, które go przechowywały".
To samo zdanie stało w `docs/DATABASE.md`. I ono jest prawdziwe — dlatego
właśnie było groźne.

> Cofnięcie nie wybija nikogo z serwisu. Ono ZDEJMUJE OCHRONĘ.

Cykl `rollback` → `migrate`, który CI wykonuje jako `migrate:refresh`,
zostawia kolumny puste, a razem z nimi znika CHECK pilnujący niezmiennika.
Konto moderatora, o którym właściciel wie, że jest chronione dwoma
składnikami, wraca do logowania samym hasłem — bez błędu, bez komunikatu,
bez śladu. Moderator widzi zgłoszenia, cudze ukryte treści i odwołania;
`docs/SECURITY_PRIVACY_LEGAL.md` obiecuje „MFA obowiązkowe dla adminów".

To jest dokładnie „przywracanie stanu groźnego" z zasady **D-088**, tylko
w postaci trudniejszej do zauważenia niż w #287: tam cofnięcie po cichu
zmieniało ZNACZENIE decyzji człowieka, tu po cichu USUWA jego zabezpieczenie.
Objaw jest ten sam — brak śladu błędu.

### Ile było takich strażników przed tą naprawą

W `database/migrations/` odmowę miało już kilkanaście migracji, a w
`tests/Feature/` stało **dziewiętnaście** testów `Cofniecie*` — m.in. dziennik
zgód, zaproszenia, zgłoszenia prawne, odwołania zgłaszających, tożsamość
Google, tożsamość Facebooka, skala tekstu, znacznik odebrania dostępu,
zeszyty, kolaż powitalny, numer sprawy, sygnały automatu i wiadomości.

Dla 2FA — czyli dla najbardziej wrażliwej z tych wartości — **nie było ani
jednego**. Nie dlatego, że ktoś to rozważył i odrzucił: przeciwnie,
komentarz przy migracji pokazuje, że ryzyko było zauważone i uznane za
akceptowalne, zanim powstała zasada D-088.

### Rozstrzygnięcie

`down()` liczy konta z `two_factor_confirmed_at IS NOT NULL` i przy
niezerowym wyniku rzuca wyjątek z instrukcją — **przed jakąkolwiek operacją
niszczącą**, także przed zdjęciem CHECK-a. Świadome cofnięcie przepuszcza
`KUKING_ROLLBACK_KASUJE_DRUGI_SKLADNIK=1`, zgodnie z konwencją furtek z
`KUKING_ROLLBACK_KASUJE_ZAPISANE_WPISY` i `KUKING_ROLLBACK_KASUJE_ZGLOSZENIA_PRAWNE`
(`getenv()`, nie `env()` — na produkcji konfiguracja bywa zbuforowana).

**Granica jest przy POTWIERDZENIU, nie przy sekrecie.** Sekret zapisany bez
`confirmed_at` to konto w trakcie włączania 2FA — ekran włączenia pokazuje
sekret, zanim człowiek wpisze pierwszy kod. To nie jest ochrona, którą można
stracić; człowiek zaczyna włączanie od nowa. Gdyby strażnik liczył sam
sekret, jedno porzucone włączanie blokowałoby rollback na stałe.

Na świeżym środowisku cofnięcie działa bez pytania, więc `migrate:refresh`
w `scripts/check.sh` i w CI chodzi jak dotąd.

### Czego ta decyzja NIE zmienia

Nie zmienia schematu, zachowania logowania ani niczego, co widzi użytkownik.
Kolumny, CHECK i limit prób zostają bez zmian. Zmienia się wyłącznie to, co
`down()` robi, gdy ktoś ma 2FA naprawdę włączone.

### Dowód

`tests/Feature/CofniecieMigracji2faOdmawiaTest.php` — pięć przypadków, obie
strony granicy: odmowa z danymi nietkniętymi po niej, świeże środowisko bez
pytania, sam sekret bez potwierdzenia nieblokujący, furtka przepuszczająca
oraz kolejność (strażnik przed zdjęciem CHECK-a i przed `dropColumn`).

Kontrola ujemna: na kodzie sprzed tej naprawy **oblewają dwa przypadki z
pięciu** — odmowa i kolejność. Pozostałe trzy przechodzą w obie strony i to
jest zamierzone: pilnują, żeby strażnik nie blokował za dużo.

---

## D-239 — Wspólny licznik całej poczty i kolejność wygaszania (#732, 22 września 2026)

> Numer: gałąź `fix/732-wspolny-licznik-poczty` niosła tę decyzję jako D-225,
> a ten numer (i D-226, D-227) zajęły w międzyczasie inne decyzje na `main`.
> D-239 to pierwszy numer wolny na `origin/main` i na wszystkich gałęziach
> zdalnych w dniu przeniesienia (reguła D-235: ustępuje gałąź, której numeru
> nie ma jeszcze na `main`). Treść to intencja tamtej gałęzi przeniesiona na
> obecny kod, bez części o drodze zgłoszenia DSA (osobna decyzja, nie ta).

Do tej zmiany każda funkcja wysyłająca wiele listów miała własny sufit dobowy
i widziała **tylko swój**, a listy bez sufitu — potwierdzenie rejestracji
i przypomnienie hasła — nie były liczone wcale. Rezerwa transakcyjna (100 listów
z puli 300) istniała wyłącznie jako zdanie w komentarzu `config/kuking.php`
i nic jej nie pilnowało.

Zmierzono dwie dziury tej samej rodziny. `/nie-pamietam-hasla` nie ma ani sufitu
na adres, ani budżetu poczty: `limits.password_reset` to 5 próśb na 10 minut
z adresu IP, czyli 720 na dobę, a każda może iść na **inny** adres. Jeden sprawca
z jednego łącza wysyła listy na 300 różnych skrzynek i opróżnia pulę EmailLabs
300/dobę w około 70 minut. Ponawianie potwierdzenia adresu
(`limits.verification_resend`, 6 na minutę z konta, bez sufitu dobowego) robi to
samo z jednego niepotwierdzonego konta w około 50 minut. W obu przypadkach
pierwszą rzeczą, która przestaje działać, jest **potwierdzenie rejestracji
i logowanie linkiem** — czyli wejście dla nowych ludzi.

**Decyzja właściciela: jeden wspólny licznik poczty dla wszystkich dróg, nie
osobne sufity.** Wpis przy `limits.kontakt_odpowiedz` zapowiadał to wprost —
gdyby taki licznik powstał, ma być **jednym** miejscem tej decyzji. Osobnych
progów przy poszczególnych drogach więc nie dopisujemy.

Licznik jest rozszerzeniem `App\Domain\Security\DziennyBudzetListow`, a nie nową
warstwą nad nią: licznik zagnieżdżony w drugim liczniku jest w tej klasie od
D-085 (zaproszenia leżą wewnątrz budżetu logowania linkiem), a osobna warstwa
oznaczałaby drugą implementację atomowej rezerwacji — czyli drugą kopię reguły.

Sam wspólny licznik nie dokłada ochrony przed przekroczeniem 300; tego pilnuje
dostawca. Dokłada **kolejność wygaszania**, bo odrzucony list przepada (worker ma
trzy próby w sześć minut). Progi w `kuking.poczta.progi_wygaszania` mówią, ile
listów z puli dana klasa ma zostawić nietkniętych:

- **240 — `podsumowanie`, gaśnie pierwsze.** Liczba wynika z rachunku
  300 − `digest.dzienny_limit` (60). Podsumowanie, które nie doszło, jest niczym.
- **100 — `zwykla`.** Równe `poczta.rezerwa_transakcyjna`; próg jest pierwszym
  mechanizmem, który tę rezerwę naprawdę dowozi. Tu stoi **przypomnienie hasła**:
  też jest drogą powrotu na konto, ale prosi o nie ktokolwiek z zewnątrz, na cudzy
  adres, bez dowodu, że adres do niego należy — czyli jest to dokładnie ta droga,
  którą zmierzony sprawca opróżniał pulę. Tu stoi też odpowiedź z „Napisz do nas".
- **0 — `wejscie`, gaśnie ostatnie.** Potwierdzenie rejestracji (także jego
  ponowienie) i logowanie linkiem sięgają po ostatni list doby.

Komunikat po odmowie mówi, **co zrobić teraz**, i ma dwa warianty: pusta pula
(„nie czekaj na niego, spróbuj jutro albo napisz do nas") i ścisk na blokadzie
licznika („kliknij jeszcze raz"). Wzorcem jest komunikat wyczerpanego budżetu
logowania linkiem.

### Zwrot rezerwacji trafia w dobę rezerwacji (#1061)

Przy przenoszeniu naprawiona została wada znana z audytu: `zwolnij()` liczył
klucz z `now()` w chwili zwrotu, więc rezerwacja z 23:59:59 oddana po północy
zdejmowała miejsce z **nowej** doby. Po dołożeniu wspólnego licznika błąd
dotyczyłby dwóch liczników naraz. Obiekt pamięta teraz doby swoich rezerwacji
i oddaje ostatnią do jej własnego klucza; doba jest wyznaczana raz na
sprawdzenie i zajęcie. `zajmij()` wołane wprost (list próbny `--tylko`) liczy się
też we wspólnej puli.

### Czego ta zmiana nie robi — powiedziane wprost

**Nie gwarantuje, że list logowania wyjdzie zawsze.** Chroni klasę `wejscie`
przed biuletynem, przed zalaniem przypomnienia hasła i przed odpowiedziami
moderatora — te drogi nie ruszą ostatnich 100 listów doby. Ale klasa `wejscie`
dzieli te listy **między siebie**: kto zakłada dziesiątki kont (rejestracja jest
otwarta z decyzji właściciela; `limits.register` = 5 na 10 minut z IP) albo
klika „Wyślij wiadomość jeszcze raz" z niepotwierdzonego konta
(`verification_resend` = 6 na minutę), ten nadal może zjeść pulę do zera, a wtedy
link do logowania nie wyjdzie. Człowiek dostaje wtedy jawny komunikat: że listu
nie będzie, żeby nie czekał, że może zalogować się hasłem i gdzie odpisuje
człowiek (`BiuletynNieZabieraListowWejsciaTest`). Osobna klasa albo sufit dla
ponowienia potwierdzenia to osobna decyzja, tutaj świadomie niepodjęta.

Nie dzieli też puli między konkretnych ludzi: jeden sprawca nadal wypali klasę
`zwykla` i zabierze tego dnia odpowiedzi z „Napisz do nas". Poza licznikiem
pozostają listy niskonakładowe z rodziny moderacyjnej (decyzje w sprawie
zgłoszeń, potwierdzenia odwołań, dobowe podsumowanie automatu, eksport danych,
ostrzeżenia o zmianie adresu) — pojedyncze sztuki na dobę, ale dopóki się nie
liczą, wspólna pula pokazuje mniej, niż serwis naprawdę wysłał. To jest znana
i nazwana niedokładność, nie przeoczenie.

📄 `app/Domain/Security/DziennyBudzetListow.php`,
`app/Domain/Security/WyslijPotwierdzenieAdresu.php`,
`tests/Feature/WspolnyLicznikPocztyTest.php`,
`tests/Feature/PodzialLimituPocztyTest.php`,
`tests/Feature/ZwrotRezerwacjiPoPolnocyTest.php`,
`tests/Feature/BiuletynNieZabieraListowWejsciaTest.php`

---

## D-240 — Do OpenAI wychodzi wyłącznie pomniejszona, publiczna treść; awatar nie wychodzi wcale (22 września 2026)

**Data:** 22 września 2026 · **Decyzja właściciela** (pozycja nr 1 listy,
„incydent trwający": `OPENAI_MODERATION_KEY` jest ustawiony na produkcji) ·
Uzupełnia D-055, **uchyla D-061** w części „zdjęcie profilowe idzie do modelu" ·
Zamyka #827 · Status: **obowiązuje**

Decyzja dosłownie: `gpt-openai-granice` + `gpt-moderacja-ai` połączyć ręcznie
w jedną poprawkę; do OpenAI ma wychodzić wyłącznie pomniejszona, publiczna
treść; awatary bez potwierdzonej zgody — nie wysyłać.

### Co było zepsute

1. **Komentarz wychodził bez pytania o rodzica (#827).** `PrzeanalizujTresc`
   sprawdzało u komentarza tylko `status = published`. Komentarz pod wpisem,
   przepisem albo wykonaniem, które w międzyczasie przestały być publiczne
   (prywatne, „dla obserwujących", ukryte, usunięte, konto autora zbanowane
   albo w karencji usunięcia), szedł do OpenAI i stawiał oznaczenie
   w kolejce moderatora. To samo dla śladu „Komentarz usunięty."
   (`body_removed_at`) i komentarza zbanowanej osoby.
2. **Zdjęcie mogło wyjść w pełnym rozmiarze.** `jakoJpeg()` brało
   `wariantDoSerwowania('thumb')`, które przy braku miniatury podstawia
   pierwszy lepszy wariant. Zmierzone w teście: zdjęcie z samym `large`
   wychodziło jako JPEG 1600 × 1200, a `thumb` wskazujący na duży plik —
   2048 × 1536. Wymiarów nikt nie sprawdzał.
3. **Awatar wychodził zawsze** (D-061), bez żadnej zgody.
4. **Uszkodzona odpowiedź udawała czystą ocenę.** `category_scores: []`,
   wyniki-napisy, wyniki spoza 0–1 i odpowiedź bez znanej kategorii
   kończyły się jako „nic nie znaleziono", bez śladu w dzienniku. Brak klucza
   na produkcji był tak samo cichy jak lokalnie.

### Co obowiązuje

- **„Publiczna" = widoczna dla gościa bez konta w chwili wysyłki.**
  `app/Moderacja/GranicaWysylki.php` pyta te same Policy co strona dla gościa
  (`Gate::forUser(null)`, `PostPolicy`/`CommentPolicy`, a ta dalej o rodzica),
  czytając stan świeżo z bazy. Pytana jest przed tekstem, przed **każdym**
  zdjęciem i jeszcze raz przed postawieniem oznaczenia. **Treść „dla
  obserwujących" przestaje być oceniana modelem** — to świadome zawężenie
  wobec D-055, wynikające wprost ze słowa „publiczna" w decyzji.
- **Lokalne sygnały (D-052) mają osobną, szerszą granicę** —
  `GranicaWysylki::pozaAutorem()`: „dla obserwujących" wolno, prywatne nie,
  jak przed tą zmianą. Nowe jest to, że komentarz pyta o aktualny stan
  rodzica (#827) i o ślad usunięcia. Sygnały lokalne nie opuszczają
  serwera, więc zawężanie ich do „publicznej" byłoby zmianą poza zakresem
  tej decyzji. Jedyny skutek uboczny: zapowiedź przepisu „dla
  obserwujących" pyta `PostPolicy` o bramkę przepisu i przez to nie stawia
  lokalnego oznaczenia.
- **Zdjęcie: tylko wariant `thumb`, bez zastępstwa, najwyżej 320 px
  zmierzone z bajtów** — przed dekodowaniem i na gotowym JPEG.
  `OcenaModelem::MAX_BOK` celowo nie jest czytany z konfiguracji wariantów.
  Brak miniatury = zdjęcie pominięte, ostrzeżenie `stage=image_boundary`.
- **Awatar nie wychodzi.** W serwisie nie ma mechanizmu potwierdzonej zgody
  na ocenę zdjęcia profilowego (`dziennik_zgod` zna jeden cel —
  `tygodniowy_digest`), więc nie ma jej nikt. `AvatarSettingsController`
  nie zleca oceny; `PrzeanalizujAwatar` zostaje pustym zadaniem wyłącznie
  dla zleceń czekających w kolejce sprzed wdrożenia. Przywrócenie wymaga
  osobnej decyzji: celu zgody, ekranu udzielania i wycofania, sprawdzenia
  przed każdą wysyłką.
- **Awaria nie udaje „czyste".** `KlientOpenAI` odrzuca odpowiedź pustą,
  z polem nieliczbowym, nieskończonym albo spoza 0–1 i odpowiedź bez znanej
  kategorii — z ostrzeżeniem. Limit czasu przycięty do 1–8 s, połączenie
  3 s (`0` w Guzzle znaczy „bez limitu"). Brak klucza **na produkcji**
  zostawia ostrzeżenie `stage=openai_disabled` przy każdej nieocenionej
  treści; lokalnie i w CI zostaje cichy. Lokalne sygnały działają
  niezależnie od stanu modelu.

### Co wzięto z gałęzi źródłowych, a czego nie

Z `gpt-openai-granice` (7fd9aa8): zasada „dokładnie `thumb`, wymiary
z bajtów, 320 px, bez zamiennika" i przypadki testowe zdjęć; pominięcie
śladu usunięcia komentarza. **Pominięto:** ponowną analizę po edycji
komentarza (#909), transakcyjne `DeleteComment` (#911) i uzupełnianie
otwartych oznaczeń — to inne pozycje, nie granica wysyłki. Pominięto też
decyzję tamtej gałęzi, by awatary wysyłać „wspólną ochroną" — właściciel
rozstrzygnął odwrotnie.

Z `gpt-moderacja-ai` (33ebfd0): walidacja wyników (`poprawneWyniki()`,
`KategorieModeracji::jestZnana()`), przycięcie limitu czasu i zasada
„aktualny stan rodzica przy wykonaniu, a nie przy zleceniu" (#827).
**Pominięto:** `AutomaticAnalysisAccess` w tamtym kształcie (klonował
rodzica i przestawiał mu widoczność na publiczną, żeby przepuścić
„dla obserwujących" — sprzeczne z „wyłącznie publiczna"), rozbicie zdjęć
na osobne zadania `PrzeanalizujZdjecieWpisu` i zapis lokalnego sygnału
przed HTTP (#829/#830) — to niezawodność kolejki, nie granica wysyłki.
Logowanie klasy wyjątku zamiast treści jest już na `main` (#1072,
`ExceptionContext`).

### Czego ta decyzja NIE zmienia

Schematu (brak migracji), progów, alarmu pocztowego, wyglądu kolejki
moderatora. Oznaczenia awatarów sprzed D-240 zostają w kolejce i dają się
rozpatrzyć.

### Dowód

`tests/Feature/GranicaWysylkiDoOpenAiTest.php` — 46 przypadków, wszystkie
przez `Http::fake()`. Na kodzie sprzed tej zmiany **oblewa 35**: 18 rodziców
komentarza, 2 stany komentarza, 2 stany wpisu, zmiana na prywatny w trakcie
oceny, 5 złych miniatur, 2 drogi awatara, brak klucza na produkcji
i 4 uszkodzone odpowiedzi. Pozostałe 11 to kontrole dodatnie (publiczny
rodzic × 3, poprawna miniatura 320 × 240) i zabezpieczenia, które `main`
już miał (prywatny/ukryty/usunięty wpis, ukryty/usunięty komentarz,
nieczytelny plik, HTTP 503) — pilnują, żeby granica nie przepuszczała
za mało i nie blokowała za dużo. Przypadki „dla obserwujących" sprawdzają
obie granice naraz: zero żądań do dostawcy i jedno lokalne oznaczenie.

### Wycofanie

Odwrócić commit. **Przed** odwróceniem wyczyścić `OPENAI_MODERATION_KEY`
na produkcji, bo odwrócenie przywraca znane drogi wysyłki treści
niepublicznej, pełnowymiarowego zdjęcia i awatara. Danych nie trzeba
cofać: zmiana niczego nie zapisuje w bazie.

---

## D-241 — Lokalne wzorce spamu sprawdzają także treść niepubliczną; wysyłka do OpenAI bez zmian (22 września 2026)

**Data:** 22 września 2026 · **Decyzja właściciela** (22.09, doprecyzowana
o 23:30) · Uzupełnia D-240 w części „lokalne sygnały”, D-052 bez zmian ·
Status: **obowiązuje**

Decyzja w brzmieniu właściciela: OpenAI ocenia całą treść publiczną (obrazy
pomniejszone). Treść niepubliczna nie wychodzi poza serwer, ale nadal sprawdzają
ją lokalne wzorce spamu. #1298 (D-240) miał wejść bez zmian, bo zamyka
incydent. Lokalną analizę przywraca osobny PR.

### Co było zepsute

D-240 pytało o granicę lokalną (`GranicaWysylki::pozaAutorem()`) tę samą
Policy gościa co o wysyłkę. Podmieniało tylko widoczność samego wpisu
z „dla obserwujących” na publiczną. Policy odrzucała jednak także:

- wpis i przepis **zbanowanego konta**, oraz komentarz pod nim;
- komentarz, którego autor jest zbanowany;
- komentarz pod **zapowiedzią przepisu „dla obserwujących”**. Bramką
  zapowiedzi jest przepis, a przepisu nikt nie podmieniał.

Wszystkie te treści traciły lokalne oznaczenie, choć przed D-240 je
dostawały. Sama wysyłka była w porządku: żadna z nich nie wychodziła do
OpenAI i dalej nie wychodzi.

### Co obowiązuje

- `pozaAutorem()` pyta Policy gościa o **kopię** treści, której nigdy się
  nie zapisuje (`kopiaDlaLokalnej()`). W kopii „dla obserwujących” zmienia
  się w „publiczny”, konto **zbanowane** udaje aktywne, a przepis zapowiedzi
  (rekurencyjnie) przechodzi te same dwie podmiany. O resztę warunków decyduje
  dalej Policy: status, usunięcie, ślad usunięcia komentarza, prywatność.
- Komentarz zbanowanego autora znów dostaje lokalną analizę.
- **Poza granicą lokalną zostają:** treść prywatna (D-052 pkt 3), ukryta,
  usunięta oraz konto **w karencji usunięcia** albo **wymazane**. Ban jest
  karą nałożoną przez nas. Karencję człowiek wybrał sam, bo obiecujemy mu
  „konto zniknie od razu”, więc nie kładziemy jego treści przed moderatorem.
- `publiczna()` i `zdjecieWpisu()`, czyli granica wysyłki, **nie zmieniają
  się ani o wiersz**. `OcenaModelem` pyta tylko je.

### Czego nie przywrócono i dlaczego

`ZgodaNaOceneAwatara` z lokalnej wersji równoległej (`2acfc098`). D-240 usunęło
drogę awatara na sztywno: nie ma zlecenia, zadanie jest puste. Klasa zgody,
która dziś zawsze odpowiada „nie”, dodałaby odczyt pliku i zlecenie tylko po
to, żeby je zaraz zatrzymać. Awatar nie ma też lokalnych wzorców, bo te
pracują na tekście, więc do przywrócenia nie ma tu nic. Mechanizm zgody
wymaga osobnej decyzji (D-240, „Awatar nie wychodzi”).

### Dowód

`tests/Feature/LokalnaAnalizaTresciNiepublicznejTest.php` — 7 przypadków,
tylko `Http::fake()` przy **ustawionym** kluczu. Każdy przypadek niepubliczny
sprawdza naraz, że lokalne oznaczenie istnieje i że `Http::assertNothingSent()`.
Na kodzie D-240 **oblewają 4**: wpis zbanowanego konta, komentarz pod
zapowiedzią przepisu „dla obserwujących”, komentarz zbanowanego autora
i komentarz pod wpisem zbanowanego konta. Trzy pozostałe to kontrole:
zapowiedź przepisu prywatnego i konto w karencji dalej bez oznaczenia
i bez wysyłki, a publiczny komentarz dalej wychodzi (zabezpieczenie przed
zepsutą atrapą). W `GranicaWysylkiDoOpenAiTest` oczekiwania dla zbanowanego
autora zmieniono z „0 oznaczeń” na „1 oznaczenie”. `Http::assertNothingSent()`
zostało w tych przypadkach bez zmian.

### Wycofanie

Odwrócić commit. Nic się nie zapisuje w bazie ani nie wychodzi poza serwer.
Po odwróceniu wracają tylko luki w lokalnych oznaczeniach opisane wyżej.

---

## D-242 — Wyjęcie z zeszytu jest odwracalne co do notatki: rdzeń z #1110 na ekranach z #1168 (#775, D-224, D-230, D-231, 22 września 2026)

**Decyzja właściciela: rdzeń z #1110, ekrany z #1168.** #1168 weszło na
`main` samo, z rdzeniem, który przy wyjęciu nadal kasował notatkę
bezpowrotnie. Tabela `collection_items` nie ma miękkiego kasowania ani
historii, więc po `detach()` notatki własnej („mniej soli", „dla Ani bez
orzechów") nie ma skąd odtworzyć. Utrata cudzej notatki jest nieodwracalna,
a brak ekranu da się naprawić później — dlatego cofanie ma pierwszeństwo,
a ekrany z #1168 zostają, bo bez nich nie ma jak wskazać zeszytu.

### Co się zmienia wobec stanu po #1168

1. **`remove()` oddaje zdjęte wiersze, nie liczbę.**
   `SaveRecipeToCollection::remove()` i `SavePostToCollection::remove()`
   zwracają listę `{collection_id, note, created_at}` zamiast `int`. Liczba
   w komunikacie to `count()` tej listy, więc nadal jest faktyczna, nie
   deklarowana (D-230, D-231).
2. **Nowe `restore()`** odkłada zdjęte wiersze tam, skąd zeszły — z notatką
   i z pierwotnym `created_at`, więc zeszyt nie przestawia się na górę listy.
   Nie nadpisuje świeższego wiersza (ktoś zdążył zapisać ponownie), nie sięga
   zeszytu, który zniknął albo nigdy nie był tej osoby.
3. **Droga powrotu czeka w sesji, nie we flashu.** Flash żyje jedno żądanie,
   a droga powrotu ma trzy (DELETE, GET z przyciskiem, POST po kliknięciu).
   `saveRecipe()` i `savePost()` sprawdzają najpierw, czy to nie jest powrót
   po wyjęciu; gdyby zadziałały jak zwykły zapis, rzecz wróciłaby do zeszytu
   DOMYŚLNEGO z pustą notatką. Powrót jest jednorazowy. Przycisk brzmi
   **„Przywróć do zeszytu"**, nie „Zapisz ponownie", bo to jest teraz prawda.
4. **Pytanie przed akcją na stronie przepisu zdjęte** (sprostowanie D-230).
   Zakres ujawnia zdanie NAD przyciskiem, związane z nim przez
   `aria-describedby`: przy jednym zeszycie formularz niesie `collection_id`
   i nazywa ten zeszyt, przy kilku stoi ostrzeżenie z liczbą zeszytów
   i zapowiedzią przycisku powrotu.
5. **Jedna reguła własnego zeszytu** (`regulyWlasnegoZeszytu()`) dla zapisu
   i dla wyjęcia — dwie kopie granicy „nie wyjmiesz z cudzego zeszytu"
   rozjechałyby się przy pierwszej poprawce, a kontrola ujemna
   `scripts/kontrole-negatywne-alfa08.py` wymaga, żeby ta lista stała w kodzie
   dokładnie raz.

**Bez zmian:** jedna droga wyjęcia na ekran i napisy „Usuń z tego zeszytu" /
„Usuń z zeszytu" (D-231), edycja zeszytu (#777), licznik karty zeszytu (#774).

**Numer.** D-230 i D-231 są na `main` zajęte przez #1168, a D-232–D-241 oraz
D-243 przez inne gałęzie. Ta decyzja nosiła najpierw D-241, który wcześniej
wypchnęła `flota/scal-786` (#966), więc ustąpiła na D-242 (D-235: ustępuje
strona, która wzięła cudzy numer). Potem obie gałęzie ustąpiły sobie
nawzajem naraz: o 23:54Z `flota/scal-786` oddała D-242 tej decyzji i wzięła
D-243, a o 23:59Z ta decyzja — nie widząc tamtego pchnięcia, bo hak
`pre-push` trwa kilkanaście minut — przeszła na D-243. D-243 pierwsza
opublikowała `flota/scal-786`, więc ta decyzja wraca na D-242, który tamta
gałąź jej zostawiła. Cudzej gałęzi nie przenumerowano.

Dowody: `tests/Feature/WyjecieZZeszytuNieKasujeInnychZeszytowTest.php`,
`tests/Feature/UsuniecieZZeszytuMaZakresTest.php`,
`tests/Feature/WpisDaSieWyjacZZeszytuTest.php`,
`scripts/wyjecie-z-zeszytu.mjs`.

---

## D-244 — Nikt nie rozstrzyga własnego zgłoszenia i nie karze konta równej lub wyższej roli (#1408, 23 września 2026)

**Data:** 23 września 2026 · **Decyzja właściciela** (triaż nocny, P1) ·
Status: **obowiązuje**

### Co było

`ModerationController::decide()` pytał wyłącznie `authorize('moderate', User::class)`,
czyli „czy aktor jest moderatorem". Nie porównywał `reports.reporter_id`
z aktorem ani roli osoby, którą decyzja karze (`ModeratedContent::osoba()`),
a `applyAction()` wołał `suspend()` / `ban()` bez osobnej reguły. Jeden
moderator mógł więc zgłosić administratora, sam to zgłoszenie rozstrzygnąć
i go zbanować (ban unieważnia sesje). Przy kilku administratorach i w połączeniu
z #1016 była to droga do odcięcia od panelu wszystkich, którzy rozpatrują
odwołania.

### Reguły

1. **Nikt nie rozstrzyga sprawy, którą sam wniósł** — `ReportPolicy::decide()`.
   Dotyczy każdej decyzji, także „Bez działania" (ona też zamyka sprawę
   i odpisuje zgłaszającemu). Dotyczy także administratora. Zgłoszenie prawne
   bez konta (`reporter_id IS NULL`) rozstrzyga każdy moderator.
   **Uzupełnienie (#1408, 24 września 2026):** stroną sprawy jest też ten,
   KOGO ona dotyczy. Zgłoszenia własnej treści albo własnego profilu nie
   rozstrzyga ani moderator, ani administrator — reguła rangi blokowała
   tylko karę na sobie, a „Bez działania” pozwalało oddalić skargę na siebie.
   Autora wyznacza `ModeratedContent::osoba()`, cel szukany razem z miękko
   usuniętymi.
2. **Zawieszenie i blokada konta tylko wobec niższej roli** —
   `UserPolicy::sanctionAccount()`. Moderator karze zwykłe konta,
   administrator także moderatorów. **Konta administratora nie zawiesza ani
   nie blokuje nikt z panelu** (równa ranga); sprawa administratora idzie do
   właściciela serwisu, a rolę odbiera `kuking:nadaj-role`, która pilnuje
   ostatniego czynnego administratora (#1016). Ta sama reguła rangi wyklucza
   karanie samego siebie.
3. **Ocena treści nie zależy od roli autora.** Ukrycie, usunięcie
   i ostrzeżenie wpisu administratora działają jak przy każdym innym.

Obie reguły są sprawdzane w `decide()` **pod blokadą wiersza zgłoszenia,
przed `ModerationAction::create()`**. Odmowa wycofuje transakcję: zgłoszenie
zostaje otwarte, nie powstaje decyzja, powiadomienie ani wpis
`moderation.decided`. Wstępne sprawdzenie „własnej sprawy" przed transakcją
służy tylko komunikatowi. Reguły żyją w politykach, więc przyszła droga
wykonująca sankcję (endpoint, zadanie, komenda) pyta o te same ability.

### Czego ta zmiana nie robi

Nie rozwiązuje #1016 (ochrona ostatniego czynnego administratora przy
zmianie statusu i współbieżności) — zamyka tylko drogę przez panel moderacji,
która tamten problem czyniła osiągalnym dla moderatora. Nie ukrywa formularza
decyzji przy własnym zgłoszeniu: formularz zostaje, a serwer odmawia
z komunikatem, co zrobić.

📄 `app/Policies/ReportPolicy.php`, `app/Policies/UserPolicy.php`,
`app/Http/Controllers/Admin/ModerationController.php`,
`tests/Feature/ModeratorNieJestSedziaWeWlasnejSprawieTest.php`

---

## D-245 — Włączenie 2FA prosi o obecne hasło, jak jej wyłączenie (#1376, 23 września 2026)

**Data:** 23 września 2026 · **Decyzja właściciela** (triaż nocny, P1) ·
Status: **obowiązuje**

### Co było

`POST /ustawienia/2fa/wlacz` (`TwoFactorSettingsController::confirm()`)
sprawdzał wyłącznie sześciocyfrowy kod z sekretu wygenerowanego chwilę
wcześniej na tym samym ekranie. Kod dowodzi, że **nowy** telefon jest dobrze
ustawiony — nie tego, że sesję obsługuje właściciel konta. Kto przejął ważną
sesję (30 dni), podpinał własny telefon, zabierał kody zapasowe, a właściciel
przy następnym logowaniu stawał przed kodem, którego nie ma. Wyłączenie 2FA
i nowe kody zapasowe o hasło już prosiły; włączenie — nie.

### Reguła

Włączenie 2FA wymaga **obecnego hasła do Kuking** obok kodu z aplikacji.
Zmiana telefonu idzie przez wyłączenie (hasło) i ponowne włączenie (hasło),
więc obejmuje ją ta sama reguła. Hasło jest sprawdzane **przed** kodem: przy
złym haśle kod nie jest ani weryfikowany, ani zużywany, a 2FA zostaje
wyłączona. Trasa dostaje oba limity: `two_factor` (kod) i `confirm_password`
(`Hash::check()` to ta sama wyrocznia co przy wyłączeniu, więc nie ma prawa
mieć luźniejszego limitu).

**Dlaczego pole hasła, a nie middleware `password.confirm` z Laravela.**
Repozytorium nigdzie go nie używa. Każda wrażliwa akcja w ustawieniach
(zmiana hasła i adresu, wyłączenie 2FA, nowe kody, usunięcie konta) prosi
o hasło w tym samym formularzu i sprawdza je `Hash::check()` pod limitem
`confirm_password`. Włączenie idzie tą samą drogą: jedno pole więcej na
ekranie, który człowiek i tak wypełnia, zamiast osobnego ekranu z własnym
oknem ważności.

### Konta bez własnego hasła (Google, #876)

Nie wymyślamy dla nich drugiej drogi: tak samo jak przy wyłączaniu, ekran
mówi, jak ustawić hasło do Kuking przez „Nie pamiętam hasła"
(`two_factor/_password-help`), i ostrzega, żeby nie wpisywać hasła do Google.
Świadomy koszt: dopóki serwis nie wysyła poczty (`Poczta::dziala()`), konto
założone wyłącznie przez Google nie włączy 2FA samo — ekran mówi to wprost
i kieruje do „Napisz do nas". Świeże ponowne logowanie przez Google jako
dowód tożsamości to osobna decyzja, tu niepodjęta.

### Włączenie 2FA gasi poświadczenia sprzed niego (#930)

Po udanym potwierdzeniu (dobre hasło **i** dobry kod) `confirm()` woła
istniejące `User::invalidateSessions()` z wyjątkiem bieżącej sesji — tą samą
drogą co zmiana hasła i „Wyloguj inne urządzenia" (#584). Znika więc każda
inna sesja `database`, rotuje `remember_token` (stare ciasteczka „zapamiętaj
mnie" przestają odtwarzać logowanie) i giną oczekujące linki do logowania.
Wszystkie te poświadczenia powstały bez drugiego składnika; zostawione,
otwierałyby konto bez kodu, a konto moderatora od tej chwili także `/admin`,
bo `moderator.2fa` sprawdza stan konta, nie przebieg logowania. Bieżąca sesja
zostaje, kody zapasowe są pokazane jak dotąd. Złe hasło albo zły kod kończą
się przed tą linią, więc niczego nie odwołują. Nowe ciasteczko pamiętania dla
bieżącej przeglądarki nie jest wystawiane — jak w #584.

📄 `app/Http/Controllers/Settings/TwoFactorSettingsController.php`,
`routes/web.php`, `resources/views/pages/settings/two_factor/enable.blade.php`,
`resources/views/pages/settings/two_factor/_password-help.blade.php`,
`tests/Feature/WlaczenieDwuetapowejWymagaHaslaTest.php`,
`tests/Feature/ZapamietaneLogowanieUniewaznienieTest.php`,
`docs/security/ZAPAMIETANE_LOGOWANIE_584.md`

---

## D-249 — Wpis w dzienniku audytu: atomowy z decyzją albo pomocniczy za nią — i nic pomiędzy (#1343, #1373, #1363, 23 września 2026)

**Data:** 23 września 2026 · Decyzja zespołu (przegląd kodu gałęzi
`claude/audyt-w-transakcji-g7`) · Prostuje podstawę `AuditLogEntry::recordBezWywracania()` ·
Status: **obowiązuje**

### Co było źle

`recordBezWywracania()` i jego trzy miejsca wywołania powoływały się na
**D-088** cytatem „dziennik audytu zostaje POZA transakcją… jest osobnym
śladem, nie częścią relacji". D-088 dotyczy **odmowy rollbacku migracji**
i o dzienniku audytu nie mówi nic. Cytat pochodzi z **D-090** i opisuje
wyłącznie `BlockUser` (wpis ma powstać wtedy, gdy blokada naprawdę się
zapisała). Na tej złej podstawie zbiorcze zamknięcie sygnałów automatu
(#1343) poszło drogą „za transakcją" — wbrew issue, które wymagało wpisu
w transakcji decyzji.

### Reguła

Każdy wpis `audit_log` należy do jednej z dwóch klas. Trzeciej nie ma.

1. **Atomowy z decyzją — `record()` WEWNĄTRZ `DB::transaction` zmiany.**
   Dla decyzji podjętych przez człowieka z uprawnieniami wobec cudzej
   treści albo konta i dla zmian uprawnień: `moderation.decided`,
   `moderation.automat_dismissed`, `user.role_changed`, a także
   `post.published` (już tak zapisany). Tu wpis jest częścią decyzji —
   „kto, kiedy i ile jednym kliknięciem" nie ma innego zapisu. Awaria
   dziennika **cofa decyzję**, człowiek dostaje komunikat „nic się nie
   zmieniło, spróbuj jeszcze raz", a ponowienie daje jeden komplet.
   Decyzja bez wpisu jest gorsza niż decyzja, którą trzeba kliknąć drugi raz.
2. **Pomocniczy — `recordBezWywracania()` PO zatwierdzeniu zmiany.** Dla
   czynności samego człowieka, których autorytatywny ślad żyje w tabeli
   zmiany: `account.registered` (wiersz `users` z `created_at`
   i `age_confirmed_at`), `content.reported` (wiersz `reports` z terminami
   DSA). Tu cofnięcie zmiany przez awarię dziennika byłoby szkodą dla
   człowieka (utracone zgłoszenie z biegnącym terminem, rejestracja
   odbijająca się od własnego adresu), a 500 po `COMMIT` — kłamstwem.
   Awaria idzie do `report()` z nazwą brakującego wpisu; to nie jest cichy
   sukces.

Rozstrzyga pytanie: **czy bez tego wpisu zostaje w bazie pełny ślad tego,
kto i co zdecydował?** Nie — klasa 1. Tak — klasa 2.

Ta sama zasada dotyczy innych skutków po `COMMIT` rejestracji (#1373):
`event(new Registered)` i obserwowanie gospodarza stoją w punkcie zapisu,
ich awaria idzie do `report()`, a `ZalozKonto` zwraca `ZalozoneKonto`
z flagą „list z potwierdzeniem nie wyszedł", żeby ekran po rejestracji nie
kazał czekać na wiadomość, której nie ma. Ponowienie listu należy do
człowieka („Wyślij potwierdzenie jeszcze raz" w Ustawieniach), naprawa
obserwowania — do operatora (jedno `FollowUser` dla konta z raportu).

### Czego ta decyzja NIE rozstrzyga

Nie przegląda wszystkich pozostałych wywołań `record()` za transakcją
(`BlockUser` z D-090, zmiany adresu e-mail, logowania i inne). Zostają,
jak są; każde następne przeniesienie ma przypisać wpis do jednej z dwóch
klas powyżej, a nie wymyślać trzeciej. D-090 zostaje w mocy dla `BlockUser`.

### Dowód

`tests/Feature/AwariaAudytuNiePrzewracaZatwierdzonejZmianyTest.php`:
awaria `moderation.automat_dismissed` → brak `ModerationAction`, grupa
otwarta, komunikat błędu; ponowienie → jedna decyzja i jeden wpis. Awarie
`account.registered`, `content.reported`, `Registered` i obserwowania
gospodarza → konto albo sprawa istnieje, odpowiedź udana, `report()`
z nazwą braku (rejestracja hasłem, Google i Facebook).

### Wycofanie

Odwrócić commit. Schemat się nie zmienia; danych nie trzeba cofać.

---

## D-254 — Adres źródła przepisu: tylko HTTP/HTTPS, dawny adres może zostać (#900, 20 września 2026)

**Data:** 20 września 2026 · Decyzja właściciela

Pole „Adres strony, z której jest przepis” przyjmuje **nowy lub zmieniony**
adres tylko z protokołem HTTP albo HTTPS (`url:http,https`) — w formularzu
jednostronicowym (`RecipeController`) i w kreatorze (`recipe-wizard`), z tym
samym komunikatem. Pomoc pola od zawsze mówiła o stronie internetowej; ogólna
reguła `url` przepuszczała też `ftp://` i `ssh://`.

**Niezmieniony dawny adres** z bazy (np. FTP zapisany przed tą zmianą) nie
blokuje edycji innych pól — nie zmuszamy autora do poprawiania go przy okazji
i nie migrujemy cudzych danych automatycznie. Kreator porównuje wartość z
**bazą**, nie ze stanem komponentu, więc autozapis nie zrobi z dopiero
wpisanego FTP „historycznego wyjątku”: taki adres jest odrzucany przed
zapisem szkicu.

Strona przepisu linkuje wyłącznie adresy HTTP/HTTPS; inne zachowane wartości
pokazuje zwykłym tekstem, bez `href`.

### Wycofanie
Powrót do ogólnego `url` w obu regułach i do bezwarunkowego linku w
`show.blade.php`. Danych nie trzeba cofać — zmiana niczego nie zapisuje.

---

## D-232 — Dopisek przy składniku bez ilości: dwa ekrany, dwa świadomie różne zachowania (#878, #764/#1197, #1222)

22 września 2026, jawne rozstrzygnięcie właściciela w PR #1222. Strona
przepisu i tryb gotowania traktują `no_amount` INACZEJ i tak ma zostać.
To nie jest rozjazd do naprawienia ani przeoczenie po scaleniu — jest to
decyzja, i jest zapisana tutaj właśnie po to, żeby następna osoba nie wzięła
jej za usterkę i nie „ujednoliciła" dwóch ekranów jednym commitem.

**Strona przepisu (`resources/views/pages/recipes/show.blade.php`) nie dopisuje
niczego.** Ten ekran jest tekstem autora — co do znaku. „Sól do smaku",
„mleko ile weźmie", „olej do smażenia" to zdania, które człowiek napisał
świadomie, i serwis nie dokłada do nich swoich słów. Każdy dopisek o dozowaniu
byłby zgadywaniem za autora: „mleko ile weźmie" mówi o konsystencji ciasta,
„olej do smażenia" o zastosowaniu — żadne z nich nie jest doprawianiem, a to
właśnie mierzyło #878. Pilnuje tego
`tests/Feature/SkladnikBezIlosciTest::test_przepis_zachowuje_tekst_autora_bez_dopisywania_sposobu_dozowania`,
porównując wiersze listy znak w znak.

**Tryb gotowania (`resources/views/pages/recipes/cooking.blade.php`) dopisuje
„— do smaku".** Ten ekran nie jest tekstem autora, tylko widokiem roboczym:
człowiek stoi przy garnku, zerka znad patelni i ma jedną rękę wolną. Gołe
„sól" w rozwiniętej liście wygląda w tej sytuacji jak brak informacji — jak
coś, co się zgubiło po drodze — i wysyła gotującego z powrotem na stronę
przepisu, żeby sprawdził, czy czegoś nie brakuje. Dopisek jest tam po to, żeby
jednoznacznie powiedzieć „nic nie zginęło, sypnij ile lubisz", i nie musi być
dosłownym cytatem z autora, bo ten ekran niczego nie cytuje. Pilnuje tego
`tests/Feature/CookingModeTest::test_skladniki_pokazuja_grupy_i_do_smaku`.
Warunek z #44 zostaje: dopisku nie ma, gdy autor sam napisał „do smaku"
w tekście składnika, żeby nie wyszło „sól do smaku — do smaku".

**Trzecia treść odpada.** PR #1222 proponował jedno neutralne „— bez podanej
ilości" na obu ekranach, w nowym wspólnym komponencie
`resources/views/components/wiersz-skladnika.blade.php`. Komponent nie miał
wołającego — żaden widok go nie renderował — a jego test
`WierszSkladnikaJedenKontraktTest` pilnował treści sprzecznej z OBOMA
istniejącymi testami naraz: wymagał dopisku tam, gdzie #878 wymaga jego braku,
i innego dopisku tam, gdzie #764/#1197 wymaga „do smaku". Oba pliki zostały
z gałęzi usunięte. Samo słowo „bez podanej ilości" jest zresztą nadal
zgadywaniem — mówi czytelnikowi, że czegoś na ekranie nie ma, zamiast pomóc
mu gotować.

**Konsekwencja dla przyszłych zmian.** `SkladnikBezIlosciTest`
i `CookingModeTest` pilnują DWÓCH RÓŻNYCH zachowań i żadnego z nich nie wolno
osłabić ani skasować „dla spójności". Czerwień jednego z nich po wprowadzeniu
wspólnego komponentu nie jest dowodem, że test jest zły — jest dowodem, że
komponent zgubił tę różnicę. Jeden wspólny wiersz składnika jest dopuszczalny
tylko wtedy, gdy rozróżnia ekran-cytat od ekranu-roboczego, i tylko po
ponownej decyzji właściciela.

---

## D-252 — Aplikacja sama dosyła zaległe potwierdzenia przyjęcia zgłoszeń, co godzinę (#797, DSA art. 16 ust. 4, 23 września 2026)

**Data:** 23 września 2026 · Decyzja właściciela · Status: **obowiązuje**

### Problem

Potwierdzenie przyjęcia zgłoszenia (powiadomienie w serwisie
`report.received` + znacznik `reports.receipt_sent_at`) powstaje POZA
transakcją zapisu sprawy — celowo, żeby awaria powiadomienia nie zabrała
człowiekowi przyjętego zgłoszenia. Awaria zostawia sprawę z pustym
znacznikiem. Dokańczał ją tylko powrót człowieka do tej samej sprawy;
sprawa, do której nikt nie wraca, zostawała bez potwierdzenia na zawsze,
a art. 16 ust. 4 DSA wymaga potwierdzenia „bez zbędnej zwłoki".

### Decyzja

**TAK — aplikacja co godzinę dosyła zgłaszającym potwierdzenia, które
wcześniej nie wyszły.** Robi to komenda
`kuking:dosylaj-potwierdzenia-zgloszen` w harmonogramie (minuta 35 każdej
godziny; 25 zajmuje `kuking:budzet-polaczen`).

- **Najwyżej jedno potwierdzenie na zgłoszenie.** Komenda woła tę samą
  akcję co formularz (`NotifyReporterReceipt::handle()`); zamkiem jest
  warunkowy `UPDATE ... WHERE receipt_sent_at IS NULL` w jednej transakcji
  z utworzeniem powiadomienia. Gdy dosyłka zbiegnie się z człowiekiem
  wracającym do tej samej sprawy, wiersz dostaje dokładnie jedno
  potwierdzenie — ten jeden przeplot mierzy
  `tests/Dwa/DosylkaNieDublujePotwierdzeniaTest` na dwóch połączeniach.
  Dwóch przebiegów dosyłki naraz nikt nie mierzy osobno: nie dopuszczają
  ich `onOneServer()` i `withoutOverlapping(50)`, a gdyby do nich doszło,
  chroni ten sam warunkowy `UPDATE`.
- **Partiami.** `--ile` (domyślnie 200) ogranicza jeden przebieg,
  najstarsze sprawy idą pierwsze, reszta czeka na następną godzinę.
- **Sprawa, która pada stale, nie zatyka kolejki.** Porażki są liczone
  per sprawa (cache, klucz `kuking:dosylka-potwierdzen:porazki`, 30 dni);
  po 3 porażkach z rzędu sprawa idzie na koniec kolejki i dostaje próbę
  dopiero, gdy w partii zostaje miejsce po sprawach zdrowych. Nie przepada:
  dalej liczy się jako zaległość, a jej porażka dalej daje kod ≠ 0. Udana
  próba albo zniknięcie zaległości zeruje licznik. Licznik nie jest
  w kolumnie, bo to stan roboczy dosyłki, nie fakt o sprawie — jego utrata
  kosztuje tylko kilka dodatkowych prób.
- **Nie jest zaległością** (i nie wchodzi do licznika): zgłoszenie bez
  konta (droga prawna ma własne potwierdzenie mailowe), zgłaszający
  z kontem wymazanym (brak czytelnika), sprawa, której rozstrzygnięcie
  (art. 16 ust. 5, `decision_sent_at`) już doszło — potwierdzenie mówi
  „sprawdzimy i napiszemy, co postanowiliśmy", więc po decyzji byłoby
  nieprawdą, a informacja o decyzji niesie ten sam numer sprawy. Warunek
  decyzji stoi także w samym zamku, więc decyzja doręczona w trakcie
  przebiegu wygrywa. Sprawa rozstrzygnięta BEZ doręczonej decyzji
  potwierdzenie dostaje — to wtedy jedyny ślad, że zgłoszenie doszło.
- **Ping skasowany przez retencję nie jest zaległością** — komenda pyta
  o znacznik, nigdy o istnienie powiadomienia.
- **Porażka widoczna.** Awaria jednej sprawy nie zatrzymuje partii, ale
  komenda kończy się kodem ≠ 0, a wspólny adapter
  `App\Support\Harmonogram::artisan()` zamienia go w wyjątek (#835). Zadanie ma `onOneServer()` (#595)
  i `withoutOverlapping(50)` (#1002).

### Czego ta decyzja NIE rozstrzyga

Górnej granicy wieku sprawy: komenda dośle potwierdzenie także do
zgłoszenia sprzed roku. „Od kiedy jest za późno" wymaga osobnej decyzji.
Nie dotyczy też informacji o rozstrzygnięciu, która nie doszła —
to osobna zaległość bez własnej dosyłki.

### Dowód

`tests/Feature/DosylkaZaleglychPotwierdzenTest.php`,
`tests/Dwa/DosylkaNieDublujePotwierdzeniaTest.php`.

### Wycofanie

Usunąć zadanie z `routes/console.php` (komenda może zostać do ręcznego
użycia). Schemat się nie zmienia; wysłanych powiadomień nie trzeba cofać.

---

## D-253 — Decyzja właściciela #926: prywatne czynności podczas zawieszenia (20 września 2026)

Właściciel wybrał wariant 2: zeszyt, odhaczanie i reset (`cooking.restart`) pozostają dostępne;
komentarze i obserwowanie pozostają zablokowane. Wyjątek obejmuje zapis
do własnych prywatnych zeszytów, bez powiadamiania autora przepisu,
oraz porządkowanie własnych zapisów. Nie otwiera publikacji w publicznym
zeszycie ani dostępu do cudzych prywatnych treści. Formularze komentarza
i obserwowania pytają politykę przed wyświetleniem. Pełny zakres,
koszt wariantów, testy i wycofanie: [ZAWIESZENIE_926.md](product/ZAWIESZENIE_926.md).

---

## D-251 — „Zdejmij z urzędu”: decyzja bez zgłoszenia w tym samym rejestrze, z `report_id = NULL` (G31, 23 września 2026)

**Data:** 23 września 2026 · **Decyzja właściciela** (dodać akcję z urzędu),
projekt zapisu — decyzja zespołu · Status: **obowiązuje**

### Co było

Od #1446 (issue #932) moderator usuwa cudzą treść wyłącznie z panelu, a panel
usuwał wyłącznie rozstrzygnięciem zgłoszenia. Własnego zgłoszenia moderator
nie rozstrzyga (D-244). Spamu, którego nikt nie zgłosił, nie dało się więc
zdjąć wcale — przy jednoosobowej moderacji nawet przez „zgłoszę sam i poproszę
drugą osobę”.

### Decyzja

1. **Akcja „Zdejmij z urzędu”** dla wpisu, przepisu i komentarza: przycisk
   przy treści → ekran `/admin/z-urzedu/{typ}/{id}` → decyzja `remove`.
   Podstawa z zamkniętej listy `PodstawaDecyzji` i uzasadnienie dla autora
   są obowiązkowe.
2. **Zapis w tym samym rejestrze:** wiersz `moderation_actions` z
   `report_id = NULL`. **Bez „zgłoszenia z urzędu”** i bez nowej kolumny
   źródła. Powody:
   - decyzja z urzędu nie jest „własną sprawą” z D-244 — nie ma zgłaszającego,
     któremu moderator mógłby rozstrzygnąć na korzyść, ani nikogo, komu
     DSA art. 16 każe odpisać. Sztuczne zgłoszenie wstawiłoby moderatora
     w rolę zgłaszającego, czyli dokładnie w sytuację, której
     `ReportPolicy::decide()` zabrania, i wymagałoby wyjątku od tej reguły;
   - fikcyjny wiersz w `reports` zasiliłby kolejkę, liczniki, statystyki
     zgłoszeń i terminy z art. 16 czymś, czego nikt nie zgłosił;
   - schemat był na to gotowy: `report_id` jest `NULL`-owalne, a
     `UzasadnienieDecyzji::skadSprawa()` od początku miało gałąź „Nikt tego
     nie zgłosił — sprawę znaleźliśmy sami” (DSA art. 17 ust. 3 lit. b).
     Pusty `report_id` przy decyzji odwoływalnej znaczy odtąd decyzję z urzędu;
     przy `unhide` — przywrócenie, jak dotąd.
3. **Kto:** czynny moderator albo administrator z potwierdzonym 2FA
   (`removeExOfficio` → `UserPolicy::takeDownContentOf()`), i tylko wobec
   konta o **niższej** roli. To świadomie ostrzej niż D-244 pkt 3 („ocena
   treści nie zależy od roli autora”). Przy zgłoszeniu sprawę wnosi ktoś
   drugi. Z urzędu jedna osoba jest naraz tą, która sprawę znalazła, i tą,
   która ją rozstrzyga. Treść równej albo wyższej rangi idzie zwykłym „Zgłoś”.
   Ta sama reguła wyklucza zdejmowanie własnej treści.
4. **Odwołanie** — ta sama ścieżka co od decyzji ze zgłoszenia (`FileAppeal`,
   `ResolveAppeal`); „cofam” przywraca treść.
5. **Otwarte zgłoszenie wygrywa:** z urzędu nie zdejmuje się treści, przy
   której czeka zgłoszenie. Decyzja zapada w kolejce, a zgłaszający dostaje
   odpowiedź.
6. **Zdjęcie z urzędu działa tym samym mechanizmem co „Usuń” ze
   zgłoszenia** — miękkie usunięcie (`$cel->delete()`), także komentarza
   z odpowiedziami; „cofam” po odwołaniu przywraca je tym samym
   `RestoreContent` co decyzję ze zgłoszenia. Moderacja nie zostawia napisu
   „Komentarz usunięty.”. „Usuń” działa jak dotąd — decyzja właściciela
   24.09.2026.
7. **Zakres: tylko treść widoczna dla innych.** Wpis i przepis opublikowane,
   publiczne albo dla obserwujących; komentarz opublikowany pod taką treścią
   (`ZdejmijZUrzedu::widocznaDlaInnych()`). Szkic, treść prywatna i ukryta
   dają **404** już na ekranie (`ZUrzeduController::cel()`), także przy
   wysyłce formularza — moderator nie ogląda prywatnych treści po samym UUID
   i nie dowiaduje się nawet, że istnieją. Powody:
   - „z urzędu” znaczy „znaleźliśmy, przeglądając serwis” — a treści, której
     nie widzi nikt poza autorem, przy przeglądzie serwisu znaleźć nie
     można. Gdyby ekran ją pokazywał, UUID w adresie stawałby się drogą do
     cudzego szkicu i prywatnych notatek (AGENTS.md §7: UUID to nie
     autoryzacja);
   - brak ogólnego obowiązku monitorowania (DSA art. 8) — nie ma powodu
     przeglądać treści prywatnych „na wszelki wypadek”.
   Treść prywatna, która mimo to jest nielegalna, trafia do moderacji innymi
   drogami, każdą z własnym śladem: formularzem zgłoszenia nielegalnej
   treści (DSA art. 16 — przyjmuje wklejony adres), nakazem organu (art. 9,
   przez właściciela serwisu) albo kolejką automatu (D-052; lokalne wzorce
   spamu sprawdzają także treść niepubliczną, D-241). Tam decyzja zapada
   przy zgłoszeniu — `decide()` widoczności nie ogranicza.
8. **Treść już zdjęta:** ekran od razu mówi „już zdjęta” zamiast formularza
   (także komentarz, który autor sam usunął i po którym stoi napis
   „Komentarz usunięty.” z `DeleteComment`), przycisk przy treści się nie
   rysuje, a drugie
   wysłanie (druga karta) kończy się błędem bez drugiej decyzji — blokada
   wiersza w `ZdejmijZUrzedu`. Treść miękko usunięta → 404.
9. **Przywrócenie treści to jedna transakcja z blokadą wiersza celu**
   (przegląd G31, `RestoreContent`). Decyzja `unhide` i zapis treści
   przechodzą razem albo wcale; stan czytany pod blokadą, więc drugie
   równoległe przywrócenie (dwie karty, „Przywróć” i „cofam” naraz) widzi
   „już widoczna” i nie zapisuje drugiej decyzji ani powiadomienia.
10. **„Najnowsza decyzja” = `created_at`, potem `id`** (status sprzed
    ukrycia w `RestoreContent`). `created_at` ma pełne sekundy
    (`timestamptz(0)`), więc remis w jednej sekundzie rozstrzyga `id`:
    UUIDv7 z `HasUuids` (milisekundy + licznik rosnący w procesie).
    Kolumny sekwencyjnej w `moderation_actions` nie ma. **Ryzyko, które
    zostaje:** wiersz wstawiony z pominięciem modelu (surowy SQL, ręczna
    naprawa) dostaje `id` z `gen_random_uuid()` — v4, losowe — i przy
    remisie sekundy kolejność byłaby przypadkowa. Kod aplikacji tak nie
    wstawia; ręczne wstawki do rejestru i tak wymagają zgody (AGENTS.md §6).

### Czego ta decyzja nie robi

- **„Ugotowałem” nie ma „Zdejmij z urzędu”.** `cooked_events` nie ma soft
  delete: `remove` kasuje wiersz na stałe razem z komentarzami, a „cofam”
  nie ma czego przywrócić. Ten sam powód, dla którego `media` nie ma
  `remove`. Ta sama luka istnieje dziś przy decyzji `remove` **ze
  zgłoszenia** na wykonaniu (komentarz w `ModerationAction::DOZWOLONE`
  twierdził „soft delete” — poprawiony). Domknięcie wymaga soft delete
  `cooked_events` — osobna praca.
- Nie dodaje ukrywania ani ostrzeżeń z urzędu — tylko zdjęcie.

📄 `app/Domain/Moderation/Actions/ZdejmijZUrzedu.php`,
`app/Http/Controllers/Admin/ZUrzeduController.php`,
`app/Policies/UserPolicy.php` (`takeDownContentOf`),
`app/Domain/Moderation/Actions/RestoreContent.php`,
`tests/Feature/ZdejmijZUrzeduTest.php`,
`tests/Feature/ZdejmijZUrzeduPoPrzegladzieTest.php`,
`tests/Feature/PrzywrocenieWTransakcjiTest.php`

### Wycofanie

Odwrócić commit. Bez migracji — schemat się nie zmienia.
Decyzje z urzędu już zapisane zostają w rejestrze jako zwykłe `remove`
z pustym `report_id`.

---

## D-246 — Ponowne wysłanie potwierdzenia adresu ma sufit na konto i własną klasę w puli (audyt 23.09, znalezisko 2; 23 września 2026)

> Numer: D-239..D-245 są zajęte na `main`, na gałęziach zdalnych albo
> w otwartych stanowiskach floty w dniu tej decyzji. D-246 to pierwszy wolny.

D-239 zostawiło to wprost jako „osobną decyzję, tutaj świadomie niepodjętą".
Audyt bezpieczeństwa scaleń z 23 września zmierzył, ile to kosztuje:
„Wyślij wiadomość jeszcze raz" stało w klasie `wejscie` (próg 0), a jedynym
limitem było `limits.verification_resend` = 6 na minutę, bez sufitu dobowego.
**Jedno niepotwierdzone konto zużywa całą dobową pulę (300 listów) w około
50 minut.** Po D-239 odmawia wtedy już sama aplikacja: do końca doby nikt nie
dostaje linku do logowania ani potwierdzenia rejestracji. Komentarz przy
`DziennyBudzetListow::dlaPotwierdzeniaAdresu()` twierdził przy tym, że wspólny
licznik „pilnuje, żeby jedno niepotwierdzone konto nie wypaliło puli całemu
serwisowi" — nie pilnował.

**Decyzja właściciela (23.09): osobny sufit dla ponowienia. Rejestracja
i logowanie linkiem zostają jak są.**

Dwie granice, bo są dwa różne zagrożenia:

- **Sufit dobowy na konto — 5 ponowień na dobę kalendarzową**
  (`kuking.poczta.ponowienie_potwierdzenia_na_dobe`,
  `KUKING_PONOWIENIE_POTWIERDZENIA_NA_DOBE`). Broni przed jednym kontem.
  Ten sam rząd co `login_link.limit_na_adres` (3 na godzinę), tylko na dobę, bo
  ten list nie jest drogą na konto: z konta korzysta się normalnie bez
  potwierdzonego adresu. Pierwszy list przy rejestracji się nie liczy. Licznik
  chodzi po identyfikatorze konta i dacie (bez adresu e-mail w `cache`), rusza
  atomowo (`RateLimiter::increment`) i oddaje miejsce, gdy list nie wyszedł.
- **Własna klasa `ponowienie` we wspólnej puli, próg 100**
  (`kuking.poczta.progi_wygaszania.ponowienie`, `KUKING_POCZTA_PROG_PONOWIENIE`).
  Broni przed wieloma kontami naraz: ponowienia razem gasną, gdy w puli zostaje
  100 listów, więc nie ruszą rezerwy dla pierwszego potwierdzenia rejestracji
  i logowania linkiem. 100 to znowu `rezerwa_transakcyjna` — ta sama obietnica
  co przy klasie `zwykla`, a nie nowa liczba. Osobna klasa zamiast dopisania do
  `zwykla`, bo właściciel może ją przesunąć bez ruszania przypomnienia hasła.
  `PodzialLimituPocztyTest` pilnuje, że `ponowienie` > `wejscie`.

Obie wartości mają wartość domyślną w `config/kuking.php`, więc **produkcja nie
potrzebuje żadnej nowej zmiennej środowiskowej**.

Do wyczerpania klasy `ponowienie` (200 listów) trzeba teraz 40 kont, a każde
z nich to osobna rejestracja pod `limits.register`.

**Komunikaty.** Po przekroczeniu sufitu konta ekran mówi, ile dodatkowych
wiadomości już wysłaliśmy, że kolejną można zamówić jutro po północy, że
z konta korzysta się normalnie bez potwierdzenia i gdzie odpisuje człowiek.
Po wygaszeniu klasy mówi, że skończyły się e-maile przeznaczone na ponowne
wysyłki (a nie „wszystkie e-maile" — rezerwa dla wejścia jeszcze jest).

### Czego ta zmiana nie robi

Nie zmienia rejestracji ani logowania linkiem: pierwsze potwierdzenie i link
nadal są w klasie `wejscie` i dzielą ostatnie 100 listów między siebie. Kto
zakłada dziesiątki kont, dalej może zjeść tę rezerwę samymi rejestracjami —
to jest granica `limits.register`, nie tej decyzji. Nie rusza też
`/nie-pamietam-hasla` (sufit na adres to nadal osobna decyzja z D-239).

📄 `app/Domain/Security/WyslijPotwierdzenieAdresu.php`,
`app/Domain/Security/WynikPonowieniaPotwierdzenia.php`,
`app/Domain/Security/DziennyBudzetListow.php`,
`app/Http/Controllers/Auth/EmailVerificationController.php`,
`config/kuking.php`,
`tests/Feature/SufitPonowieniaPotwierdzeniaTest.php`,
`tests/Feature/PodzialLimituPocztyTest.php`,
`tests/Feature/WspolnyLicznikPocztyTest.php`

---

## D-236 — Kolejka moderacji czyta się od rzeczy, która nie może czekać (22 września 2026)

Kolejka `/admin/zgloszenia` sortowała `created_at DESC, id DESC`. Zmierzone:
zgłoszenie „Dotyczy dziecka" sprzed dwóch dni leży pod trzydziestoma
zgłoszeniami spamu z ostatniej godziny, czyli na DRUGIEJ stronie — a spam
jest jedyną kategorią przychodzącą falami, więc im gorszy dzień, tym głębiej
schodzi rzecz najcięższa. Osobno: alarm mailowy istniał WYŁĄCZNIE po stronie
automatu (D-055), więc model podejrzewający treść seksualną z udziałem
dziecka budził moderatora listem, a człowiek, który to samo zgłosił
przyciskiem, nie budził nikogo. Cichsza była droga, na której ktoś to już
zobaczył.

**Priorytet liczy się z danych, nie jest wpisywany.** `PriorytetSprawy`
czyta `reason` (kategoria wybrana przez zgłaszającego) i `source`
(zgłoszenie prawne ma podłogę na P1, bo niesie termin z DSA art. 16 ust. 5).
Lista P0 to DOKŁADNIE ta sama para, którą za pilną uznaje automat
(`KategorieModeracji::PILNE`): treść seksualna i wszystko, co dotyczy
dziecka. Jedna definicja „pilnego" na cały serwis.

**Bez kolumny i bez migracji.** Wartość idzie do `ORDER BY CASE`, tak samo
jak `Report::WAGA` w kolejce automatu: to jest reguła produktu, nie fakt
o wierszu. Zmiana listy kategorii ma być jedną linijką i jednym czerwonym
testem, a nie migracją przepisującą historyczne wiersze na nową skalę.

**Sprzeczność z `KolejkiModeracjiMajaStabilnyPorzadekTest` rozstrzygnięta
świadomie.** Gwarancja „najnowsze na górze" upada W CAŁEJ KOLEJCE, bo
obiecywała porządek nie do obronienia przy falach spamu. Zastępują ją dwie
węższe: wewnątrz jednego priorytetu porządek jest NIETKNIĘTY (najnowsze na
górze, remis po `id`), a stabilne stronicowanie zostaje bez osłabienia —
mierzy to dopisany test przy mieszanych priorytetach. Kierunku wewnątrz wagi
NIE odwracamy, choć odrzucona gałąź `claude/priorytet-w-kolejce-moderacji`
to proponowała: to osobna decyzja, bez dowodu i z ceną (góra kolejki
przestaje się odświeżać). Kolejki odwołań zmiana nie dotyczy.

**Alarm zostaje pocztą i nie dokłada kanału.** `AlarmujOPilnymZgloszeniu`
woła obie drogi zgłoszenia (społecznościową i prawną, bo ta druga działa bez
konta) i wysyła list tylko przy P0, na ten sam `alarm_email` co alarm
automatu; pusty adres znaczy „bez poczty" i jest normalnym stanem lokalnie.
List NIE niesie treści zgłoszonej ani pola `details` — to niesprawdzony tekst
od dowolnej osoby, a poczta idzie przez zewnętrznego dostawcę.

**Czego świadomie NIE zbudowano.** Kolumny `priorytet` z ręczną zmianą przez
moderatora, oznaczenia „P0 nieprzejrzane" w pasku panelu i historii sankcji
autora — wszystkie trzy były w odrzuconej gałęzi. Historia sankcji nie wchodzi
do priorytetu, bo na pytanie „czy to może poczekać do jutra" odpowiada rodzaj
szkody, a nie kartoteka osoby; recydywa jest argumentem przy DECYZJI.
Kosztowałaby przy tym dwa zapytania na pozycję ekranu, bo `reports` nie ma
kolumny z autorem zgłoszonej treści.

**Czego priorytet nie twierdzi.** Że sprawa jest tym, czym nazwał ją
zgłaszający — nikt tej treści jeszcze nie obejrzał. Zmienia wyłącznie
kolejność czytania i wysyła jeden list: nie ukrywa treści, nie ogranicza jej
zasięgu i nie powiadamia autora.

**Uzgodnione z podręcznikiem moderacji, nie obok niego.**
`docs/legal/MODERATION_PLAYBOOK.md` ma własną tabelę SLA P0–P3 i wchodziła na
`main` równolegle z tą pracą. Zdanie „priorytetu nie ma w narzędziu… sprawa P0
sprzed dwóch dni leży niżej niż spam sprzed godziny" przestało być prawdziwe
i zostało w podręczniku poprawione razem z mapowaniem kategorii na to, co robi
kod. Jedna rzecz zmieniła się PO MOJEJ STRONIE: `dangerous_advice` miał u mnie
P1, a podręcznik stawia „niebezpieczne porady" w P2 — zostaje P2, bo podręcznik
jest dokumentem operacyjnym właściciela, a mój argument („zła rada o weku
kończy się szpitalem") jest opinią, nie pomiarem. `scam` nie ma pozycji
w tabeli SLA i kładę go w P1 własnym osądem; to jedna linijka do zmiany.

Dwie rzeczy, których z samej kategorii odczytać się NIE DA i które zostają
przy człowieku: „groźby zagrażające życiu" (P0 w tabeli) wchodzą jako
`harassment`, czyli P1, bo formularz nie ma takiej pozycji; „aktywny doxxing"
(P0) wchodzi jako `personal_data`, czyli P1, z tego samego powodu. Oba są
nazwane wprost w podręczniku, zamiast udawać, że kod je rozpoznaje.

**Obok D-244, D-249 i #1446 (scalenie 23 września).** Priorytet zmienia
wyłącznie kolejność czytania, więc nie dotyka reguł, KTO rozstrzyga i CO się
wtedy zapisuje: własnej sprawy nadal nie rozstrzyga nikt (D-244), decyzja
i jej wpis w dzienniku audytu powstają razem albo wcale (D-249), a usunięcie
treści idzie wyłącznie przez formularz decyzji w panelu (#1446). Alarm nie
zapisuje niczego do audytu i nie prowadzi do żadnej akcji poza kolejką.
Jedno zdanie listu trzeba było zmienić: „Decyzja należy do Ciebie" byłoby
nieprawdą, gdy zgłosił sam moderator, a list trafia na wspólny adres
alarmowy — list mówi teraz, że rozstrzyga moderator, który zgłoszenia nie
wniósł.

### Poprawki z przeglądu PR #1284 (23 września 2026)

**Ryzyko: zalanie alarmu.** Pierwsza wersja wysyłała list na KAŻDE
zgłoszenie P0 i zakładała, że nadużyciu wystarczy `reports_one_open_per_pair`.
Nie wystarczało: ten indeks pilnuje pary osoba–treść, a kategorię wybiera
zgłaszający — także w formularzu DSA bez konta. 40 zgłoszeń jednego wpisu
dawało 40 listów, jedno konto zaznaczające „Dotyczy dziecka" przy kolejnych
celach — do 60 listów na godzinę. Listy szły poza wspólnym licznikiem poczty
(wbrew D-239: jeden licznik dla wszystkich dróg) i zjadały pulę EmailLabs
300/dobę, wypychając listy logowania i rejestracji.

**Rozwiązanie — trzy zamki.** (1) Najwyżej jeden list o danym celu
(`target_type` + `target_id`, a przy zgłoszeniu prawnym bez rozpoznanego celu —
`target_url` bez `?`/`#` i końcowego ukośnika) w oknie
`moderation.alarm_czlowieka.okno_celu_godzin` (6 h), atomowo przez
`Cache::add()`; kolejne zgłoszenia tego celu i tak stoją na górze kolejki.
(2) Dobowy sufit `moderation.alarm_czlowieka.dzienny_sufit` (10) dla
wszystkich celów razem; ostatni list doby mówi wprost, że kolejnych nie
będzie, powyżej zostaje wpis w dzienniku i sprawa w kolejce z plakietką.
(3) Sufit jest licznikiem `DziennyBudzetListow::dlaAlarmuModeracji()`
zagnieżdżonym we wspólnym liczniku — jedna atomowa rezerwacja na oba, tak
jak przy podsumowaniu. Gdy list nie wychodzi po zajęciu klucza celu, klucz
wraca.

**Klasa `wejscie`, nie `zwykla` — i dlatego własny sufit.** Klasę `zwykla`
wypala zalanie `/nie-pamietam-hasla` z jednego łącza (D-239); alarm w tej
klasie dawałby sprawcy przepis na to, żeby zgłoszenie o dziecku przeleżało
noc bez listu. W klasie `wejscie` alarm sięga po ostatnie listy doby, a sufit
ogranicza, ile z nich może zabrać: najwyżej 10 ze 100 zostawianych wejściu.
Suma z `PodzialLimituPocztyTest` się nie zmienia, bo te listy leżą w rezerwie.

**Plakietka i priorytet tylko dla spraw otwartych.** Rozstrzygnięte P0
z napisem „Nie może czekać" było nieprawdą, a sortowanie po priorytecie
obejmowało wszystkie stany — w „Wszystkie" archiwum P0/P1 stało nad
dzisiejszym otwartym P2. Teraz karta czyta `PriorytetSprawy::wKolejce()`
(`null` poza `open`), a `ORDER BY` — `wyrazenieSqlKolejki()`: sprawy
nieotwarte dostają `NIE_CZEKA` i idą za otwartymi, po dacie. Test pilnuje,
że PHP i SQL liczą to samo dla każdego stanu.

**Wydajność.** `ORDER BY CASE` nie ma indeksu i liczy się na każdym wierszu
po filtrze stanu i źródła. W MVP to jest akceptowalne: filtr domyślny to
`open` od ludzi, czyli dziesiątki wierszy, a priorytet dotyczy tylko
otwartych. Kolumna z indeksem wraca do rozmowy, gdy kolejka otwartych
urośnie do tysięcy.

**`scam` jako P1 — DO POTWIERDZENIA PRZEZ WŁAŚCICIELA.** Tabela SLA
w podręczniku nie ma tej pozycji; P1 to mój osąd (oszustwo trwa i dotyka
kolejnych ludzi, dopóki wisi), nie decyzja. Zmiana to jedna linijka
w `PriorytetSprawy::MAPOWANIE`.

Dowody: `tests/Feature/KolejkaModeracjiStawiaPilneNaGorzeTest.php`
i `tests/Feature/KolejkiModeracjiMajaStabilnyPorzadekTest.php`.

---

## D-255 — Adres magazynu R2 za strażnikiem hostów: tylko `<konto>.eu.r2.cloudflarestorage.com` (24 września 2026)

**Data:** 24 września 2026 · **Decyzja właściciela 24.09.2026** · Status: **obowiązuje**

**Co.** `AWS_ENDPOINT` — adres, pod który dyski R2/S3 wysyłają żądania
podpisane kluczami z `AWS_*` (zdjęcia `r2`/`r2_publiczne`/`r2_legacy`,
eksporty RODO `r2_eksporty`, kopie bazy `r2_kopie`, ogólny `s3`) — podlega
strażnikowi hostów, tak jak adresy API z kluczami (#991). Dozwolony jest
wyłącznie adres:

```text
https://<32 znaki hex>.eu.r2.cloudflarestorage.com
```

— wzór `^[0-9a-f]{32}\.eu\.r2\.cloudflarestorage\.com$`, schemat `https`,
bez portu (także bez wpisanego `:443`), bez danych logowania, bez ścieżki
poza „/”, bez `?` i `#`.

**Dlaczego.** (1) Adres niósł sekrety i dane ludzi (oryginały z EXIF-em, paczki
RODO, zrzuty bazy), a nikt go nie sprawdzał: literówka albo podmieniona
zmienna wysyłała podpisane żądania pod obcy host, a pusty adres — do Amazona
(domyślny endpoint AWS SDK). (2) Segment `eu` przypina dane do jurysdykcji
UE: bucket z jurysdykcją jest osiągalny wyłącznie przez endpoint
jurysdykcyjny, a endpoint `eu` nie sięga bucketów spoza niej
(`docs/infra/LOKALIZACJA_DANYCH_R2.md` §2). Polityka prywatności składa
obietnicę o UE — strażnik sprawia, że aplikacja nie zapisze zdjęcia nigdzie
indziej.

**Co robi strażnik** (`App\Support\Storage\DozwolonyHostR2`):

- `DyskR2::utworz()` (sterownik `r2`) i `DyskR2::utworzS3()` (wbudowany `s3`,
  przepięty w `AppServiceProvider`) sprawdzają adres **przed** zbudowaniem
  `S3Client`. Zły adres → dysk się nie buduje, wyjątek po polsku nazywa
  zmienną i SAM host (bez klucza, userinfo i ścieżki); żadne żądanie nie
  wychodzi.
- `/health` ma sondę `magazyn`: zły adres któregoś dysku R2/S3 → `ok:false`,
  kod `magazyn_r2_zly_host`; host (z identyfikatorem konta) idzie tylko do
  logu. Dysk bez klucza i bez adresu (produkcja bez R2) jest pomijany.
- W `local`/`testing` dopuszczone są też adresy, które nie wychodzą z maszyny:
  pętla zwrotna (`localhost`, `127.0.0.1`, `::1` — MinIO z
  `PomiarOdcieciaDostepuDoPlikuTest`) i zarezerwowane domeny `.test`,
  `.invalid`, `.example`, `.localhost` (RFC 2606/6761), oraz pusty adres.
  Na każdym innym środowisku — wyłącznie wzór.
- Wzór jest w kodzie, nie w `.env`: kto może podmienić adres, nie może też
  dopisać wyjątku.

**Jak wymienić konto albo region.**

- *Inne konto Cloudflare, dalej jurysdykcja UE:* wystarczy nowa wartość
  `R2_ENDPOINT` na Railway (`https://<nowe konto>.eu.r2.cloudflarestorage.com`)
  — identyfikator konta pasuje do wzoru. Buckety na nowym koncie muszą być
  utworzone z jurysdykcją `eu` (inaczej endpoint `eu` ich nie zobaczy).
- *Inna jurysdykcja albo inny dostawca:* to jest zmiana tej decyzji —
  nowa decyzja właściciela, zmiana `DozwolonyHostR2::WZOR_HOSTA`,
  `BramkaR2::JURYSDYKCJA_Z_POLITYKI` i polityki prywatności w jednym PR,
  z testem. Jurysdykcji istniejącego bucketu nie da się zmienić — dane
  trzeba przenieść do nowego bucketu (`kuking:przenies-zdjecia`).

**Co musiałoby się stać, żeby to zmienić:** właściciel zmienia obietnicę
o lokalizacji danych albo dostawcę magazynu.

Dowody: `tests/Feature/StraznikHostaR2Test.php`, kontrola ujemna
„Strażnik R2 bez segmentu eu” w `scripts/kontrole-negatywne-alfa08.py`.

### Wycofanie
Odwrócić commit. Schemat bazy się nie zmienia; danych nie trzeba cofać.

## D-256 — Poprawiony komentarz przechodzi analizę automatu jeszcze raz (24 września 2026)

**Data:** 24 września 2026 · Issue #909 · Status: **do decyzji właściciela**
(zmienia jeden wiersz „ODŁOŻONE” z D-052)

**Co.** Gdy autor w 15-minutowym oknie **rzeczywiście zmieni** tekst
opublikowanego komentarza, `CommentController::update()` zleca
`PrzeanalizujTresc::dlaKomentarza()` — to samo zadanie, w tej samej kolejce
`low`, co po publikacji. Zapis bez zmiany (`wasChanged('body')` fałszywe)
nic nie zleca. Wpisy i przepisy zostają bez zmian.

**Dlaczego.** D-052 odłożył ponowną analizę po edycji „ze świadomą luką”,
z dwóch powodów. Oba przy komentarzu nie trzymają:

1. *Obietnica „odrzucone nie wraca”* — nienaruszona. Zadanie kończy się
   w `OznaczDoPrzegladu`, a tam `juzOgladane()` i indeks
   `reports_jeden_automat_na_tresc` przepuszczają jedno oznaczenie na
   komentarz, na zawsze. Edycja NIE otwiera sprawy odrzuconej i nie stawia
   drugiej pozycji przy otwartej (moderator i tak ogląda aktualny tekst).
2. *Koszt zadania za każdą literówkę* — ograniczony: okno 15 minut, limit
   trasy `comment`, tylko rzeczywista zmiana. Kilka szybkich poprawek daje
   kilka zadań, ale każde czyta komentarz po ID, więc każde ocenia
   najnowszy tekst, a wynik to najwyżej jedna pozycja w kolejce.

Luka była najtańszym obejściem wykrywacza: neutralny komentarz → zakończona
analiza → dopisany spam.

**Czego to nie zmienia.** Wynik jest sygnałem dla moderatora (D-052, D-055):
treść zostaje opublikowana, autor nie dostaje powiadomienia. Wyłącznik
`KUKING_SYGNALY_AUTOMATU` i granica widoczności (`GranicaWysylki`, D-240)
działają jak przy publikacji — zadanie ogląda tylko opublikowany komentarz.

**Znana granica.** Komentarz, którego oznaczenie moderator już odrzucił,
po edycji nie wraca do kolejki automatu — to cena obietnicy z D-052.
Zostaje zgłoszenie od człowieka.

Dowody: `tests/Feature/AnalizaPoEdycjiKomentarzaTest.php` (zakończona
pierwsza analiza, pierwsze zadanie wciąż w kolejce, zapis bez zmiany,
wyłącznik, odrzucone nie wraca).

### Wycofanie
Odwrócić commit. Schemat bazy się nie zmienia; oznaczenia postawione po
edycji zostają w kolejce jak każde inne.
