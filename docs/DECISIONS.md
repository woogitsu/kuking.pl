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
