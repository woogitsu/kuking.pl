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
