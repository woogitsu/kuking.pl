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

**Data:** 5 września 2026 · **Decyzja właściciela** · Status: **zmienione przez D-028
w części dotyczącej runnerów** (organizacja i prywatność repozytorium zostają)

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

## D-016 · Odwołanie składa się w produkcie, formularzem zamkniętym hasłem

**Data:** 6 września 2026 · Status: **obowiązuje** · issues #10, #65

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

📄 `app/Domain/Media/UsunGps.php` ·
`app/Domain/Media/Actions/StoreUploadedImage.php` ·
`tests/Feature/OryginalTraciGpsTakzeWPngIWebpTest.php` ·
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

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **przyjęta,
niezbudowana** · **poprawia D-017**

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

📄 `database/migrations/…_recipe_ingredients_*` · issue #44 · D-017 ·
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

## D-083 · Zdjęcie przypina się i kasuje pod JEDNĄ blokadą wiersza `media`, a pliki znikają dopiero PO commicie — wiersz ze znacznikiem `deleted` jest uchwytem do ponowienia

**Issue:** #285 (MEDIA-01, P1). **Data:** 10.09.2026.
**Stoi na:** D-079 (jedna kolejność blokad + rewalidacja POD blokadą).

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

## D-092 · Analityka odwiedzin to Plausible hostowany w UE — bo obietnica „nie ma banera zgody" jest warta więcej niż Google Analytics

**Data:** 10 września 2026 · **Decyzja właściciela po przedstawieniu kosztu**
(prośba: „dodaj Google Analytics, ja dodam variables do Railway") · Status:
**obowiązuje**

### Skąd to pytanie i dlaczego odpowiedź nie brzmi „Google Analytics"

Właściciel chciał wiedzieć dwie rzeczy, których nasza własna analityka nie
umie powiedzieć: **skąd ludzie przychodzą** i **które strony oglądają**.
`App\Domain\Analytics\*` liczy zdarzenia, które powstają W BAZIE — publikacje,
„Ugotowałem", tygodniowe WAC. O kimś, kto wszedł na stronę powitalną i wyszedł,
nie wie nic i wiedzieć nie może. Pytanie było więc dobre.

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

### Co odrzucono i dlaczego — mierzone, nie brane na słowo

Kandydaci: **Plausible** i **Umami**. Twarde wymagania: zero ciasteczek, zero
zapisu na urządzeniu człowieka, dane w UE, brak profilowania między serwisami.

**Zachowanie skryptów sprawdziłem, pobierając je i czytając**, zamiast wierzyć
stronom marketingowym — bo to jest zdanie, które trafia do dokumentu prawnego:

| | Plausible (`plausible.io/js/script.js`) | Umami (`cloud.umami.is/script.js`) |
|---|---|---|
| `document.cookie` | 0 wystąpień | 0 wystąpień |
| `sessionStorage`, `indexedDB` | 0 | 0 |
| `localStorage` | tylko **odczyt** flagi `plausible_ignore`, którą człowiek ustawia sam | tylko **odczyt** flagi `umami.disabled` |
| zapis na urządzeniu (`setItem`) | brak | brak |

Czyli **na tym kryterium oba przechodzą** i nie ono rozstrzygnęło. Rozstrzygnęły
dwie rzeczy:

1. **Kto jest podmiotem.** Umami Cloud prowadzi Umami Software, Inc. — spółka
   z Delaware z siedzibą w San Francisco. Nawet z regionem UE dla danych sam
   dostawca zostaje spoza EOG, czyli w naszej polityce dopisujemy TRZECI
   akapit o przekazywaniu danych poza EOG, obok Turnstile i OpenAI. Plausible
   prowadzi Plausible Insights OÜ z Estonii, na serwerach Hetznera
   w Falkenstein — cały łańcuch w UE i **żadnego akapitu o transferze**.
   Przy dokumencie, który właściciel czyta linijka po linijce, to jest
   różnica na korzyść zrozumiałości, nie tylko formalna.
2. **Self-host odpada z powodu architektury, nie niechęci.** Umami
   samodzielnie hostowany to druga usługa (Node) z własną bazą na Railwayu;
   Plausible samodzielnie hostowany dokłada do tego jeszcze ClickHouse.
   `AGENTS.md` mówi: modularny monolit, bez mikroserwisów. Dokładanie
   drugiego procesu do utrzymywania po to, żeby wiedzieć, skąd przychodzą
   odwiedzający, jest złą wymianą dla serwisu prowadzonego przez jedną osobę.

**Nie rozważano ponownie Google Analytics** — stawia ciasteczka, więc wywraca
całą przesłankę. **Nie wraca temat pikseli śledzących** (osobna otwarta
sprawa, #204). **Nie znika nasza analityka serwerowa**: Plausible jest jej
uzupełnieniem, nie zamiennikiem, i `App\Domain\Analytics\*` zostaje bez zmian.

### Dlaczego dalej NIE MA banera — i dlaczego to nie jest naciąganie

`docs/legal/COMPLIANCE.md` §5.2 stawia granicę tam, gdzie stawia ją ePrivacy
i PKE: zgody wymaga **przechowywanie informacji na urządzeniu końcowym albo
uzyskiwanie dostępu do tej, która już tam jest** — a nie sam fakt liczenia
czegokolwiek. Plausible nie robi ani jednego, ani drugiego (patrz tabela
wyżej).

Ten sam dokument, w §5.3, **odradza** próbę „cookieless analytics" bez
konsultacji prawnej. Ta rada dotyczyła jednak PostHoga i zachowuje ważność
tam, gdzie dotyczyła: PostHog bez identyfikatorów to **konfiguracja**, którą
da się cofnąć jednym przełącznikiem w panelu — i wtedy dokument prawny
przestaje być prawdziwy, a nikt się o tym nie dowie. W Plausible nie ma czego
przestawiać: brak ciasteczek jest właściwością narzędzia, nie ustawieniem.
Ryzyko, przed którym ostrzegała §5.3 — cicha zmiana zachowania pod
niezmienionym dokumentem — tu po prostu nie występuje. Zapisane w
COMPLIANCE.md §5.5.

### Wpięcie — trzy rzeczy, które łatwo zrobić źle

1. **Bez zmiennej środowiskowej nie ma ANI ŚLADU znacznika w HTML-u.**
   Nie „wyłączona flagą", tylko nieobecna. Lokalnie, w testach i w CI cisza.
   Ten sam wzorzec co puste klucze Turnstile (D-050), i tak samo bez osobnej
   flagi „włącz analitykę" — dałaby stan „włączone, ale bez domeny", czyli
   skrypt wysyłający zdarzenia donikąd.
2. **Konfiguracja przez `config/kuking.php`, nigdy `env()` w widoku.**
   Na produkcji konfiguracja jest zbuforowana i `env()` poza plikiem configu
   oddaje `null` — czyli znacznik z pustym `data-domain`: skrypt, który się
   ładuje i nic nie liczy.
3. **CSP w DWÓCH dyrektywach, nie w jednej.** To jest najczęstsza cicha
   porażka takiego wpięcia i osobny test tylko na to. `script-src` pozwala
   POBRAĆ plik; zdarzenia idą potem POST-em na `<host>/api/event`, czyli
   podlegają `connect-src` — która w naszej polityce jest wypisana osobno,
   więc nie dziedziczy nic z `default-src 'self'`. Brak drugiej linijki daje
   stronę bez usterki, pusty dziennik i pusty panel Plausible.

Host analityki wchodzi do CSP **tylko wtedy, gdy analityka jest włączona** —
tak samo jak host Turnstile (issue #12): polityka opisuje to, co strona
naprawdę ładuje, a każdy obcy host w `script-src` poszerza powierzchnię ataku.

### Co właściciel musi zrobić ręcznie

Założyć stronę w panelu Plausible i dodać w Railwayu dwie zmienne:
`PLAUSIBLE_DOMENA=kuking.pl` (dokładnie jak w panelu) oraz
`PLAUSIBLE_HOST=https://plausible.io`. Do tego czasu serwis chodzi bez
analityki i nic nie pada. Opis obu zmiennych stoi w `.env.example`.

### Uboczne znalezisko: `DokumentyPrawneNieKlamiaTest` był za słaby

Kontrola ujemna do tej zmiany wykryła usterkę w istniejącym teście, starszą
niż ta decyzja. `test_nie_wymieniamy_narzedzi_ktorych_nie_uzywamy` pytał
o CAŁY dokument („czy gdziekolwiek stoi zdanie zaprzeczające"), więc jedno
prawdziwe zdanie usprawiedliwiało każde inne wystąpienie nazwy: dopisanie do
polityki zdania **„Do statystyk używamy Google Analytics"** testu NIE OBLAŁO.
Sprawdzenie chodzi teraz po KAŻDYM wystąpieniu nazwy z osobna, w jego własnym
zdaniu — i po poprawce ten sam sabotaż oblewa. Lista narzędzi zakazanych
liczy się przy tym z kodu, więc Plausible wypadło z niej samo, a jego
obecności w dokumencie pilnuje z drugiej strony
`PolitykaPrywatnosciWymieniaKazdaUslugeTest`.

**Pliki:** `config/kuking.php` · `app/Support/Plausible.php` ·
`app/Http/Middleware/ApplySecurityHeaders.php` ·
`resources/views/components/layout.blade.php` · `.env.example` ·
`resources/legal/polityka-prywatnosci.md` · `docs/legal/COMPLIANCE.md` ·
`tests/Feature/AnalitykaBezCiasteczekTest.php` ·
`tests/Feature/DokumentyPrawneNieKlamiaTest.php` ·
`tests/Feature/PolitykaPrywatnosciWymieniaKazdaUslugeTest.php`
