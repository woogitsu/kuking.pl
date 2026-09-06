# Dziennik decyzji

Decyzje, które **zostały podjęte** i których nie należy otwierać na nowo bez
nowej informacji. Każdy agent AI i każda osoba dołączająca do projektu czyta
ten plik, żeby nie proponować rzeczy już rozstrzygniętych.

Format: co, kiedy, kto zdecydował, dlaczego, i **co musiałoby się stać**,
żeby decyzję zmienić.

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

Zmiana stanu konta jest zawsze jawną, nazwaną operacją: `suspend()`, `ban()`,
`markForDeletion()`, `promoteTo()`. To zamyka drogę do przejęcia uprawnień
przez dołożenie pola do formularza.

Ta decyzja została podjęta **po znalezieniu realnego błędu**: usunięcie konta
nie działało, bo `update(['status' => ...])` było ciche.

**Zmiana wymaga:** niczego. To jest zabezpieczenie, nie preferencja.

📄 `AGENTS.md` §7 · `app/Models/User.php` · `tests/Feature/SecurityTest.php`

---

## D-007 · Ważne funkcje działają bez JavaScriptu

**Data:** wrzesień 2026 · Status: **obowiązuje**

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

**Data:** 5 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

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

**Data:** 5 września 2026 · **Decyzja właściciela** · Status: **wykonane w części repozytorium**

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
- ⬜ zmienna repozytorium `CI_RUNNER` usunięta albo `ubuntu-latest`
  (ustawienia GitHuba, nie plik w repozytorium)
- ⬜ ochrona gałęzi `main` wymagająca zielonego CI
- ⬜ `KUKING_WAIT_FOR_CI=true` — **na samym końcu**

**Zmiana wymaga:** wyczerpania limitu nowej organizacji albo potrzeby
kontroli nad środowiskiem, której runnery GitHuba nie dają.

📄 `infra/CI_BEZ_ACTIONS.md` · `infra/SELF_HOSTED_RUNNER.md` (plan B) · issue #4

---

## D-011 · Deploy odłożony, praca idzie w kodzie

**Data:** 5 września 2026 · **Decyzja właściciela** · Status: **obowiązuje do odwołania**

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

**Data:** 6 września 2026 · **Decyzja właściciela** · Status: **obowiązuje**

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

## Jak dopisywać decyzje

Nowa decyzja trafia tutaj, gdy: zamyka dyskusję, którą ktoś mógłby otworzyć
ponownie, albo gdy odrzuca oczywiste na pierwszy rzut oka rozwiązanie.

Rzeczy, które **nie są** decyzją do zapisania: wybór nazwy zmiennej, kolejność
pól w formularzu, sposób sformułowania jednego komunikatu.
