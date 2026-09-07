# Przegląd specyfikacji 9 decyzji — co przyjąć, co poprawić, czego nie robić

Dokument zbiorczy z pięciu niezależnych raportów badawczych (R1–R5), które
sprawdzały `KUKING_SPECYFIKACJA_9_DECYZJI.md` (dalej: **SPEC**) przeciwko
stanowi repozytorium, dokumentacji dostawców i literaturze UX.

- Data przeglądu: **7 września 2026**. Stan repozytorium: `c6fe939`.
- Raporty źródłowe: R1 (tagi), R2 (AI), R3 (proxy/limity/`/health`),
  R4 (R2/łańcuch wydania), R5 (mobilna IA/moderator/zawieszenie).
- Metoda: każdy raport miał obowiązek **zmierzyć** to, co twierdzi, i oznaczyć
  osobno to, czego nie dało się potwierdzić. Rzeczy niepotwierdzone są tu
  oznaczone jako takie, nie wygładzone.

**Jedna poprawka faktograficzna do samych raportów, zanim cokolwiek innego.**
R4 zbudował całą sekcję o `cdn.kuking.pl` na D-011 („pierwszy deploy czeka").
**D-011 jest nieaktualne — serwis jest na produkcji.** `https://kuking.pl/`
odpowiada dziś `HTTP 200` przez Cloudflare (zmierzone dwukrotnie, 7 września).
Wniosek R4 co do kierunku pozostaje prawdziwy, ale przestaje być teoretyczny:
to nie jest „poprawka do runbooka na później", tylko rzecz do sprawdzenia w
panelu teraz. D-011 zostało już oznaczone jako nieaktualne w `docs/DECISIONS.md`.

---

## 1. Co przyjąć bez zmian

Rzeczy, gdzie SPEC ma rację, a repozytorium albo już to robi, albo nie ma z tym
sporu. Nie ma tu nic do decydowania — to lista do wdrożenia albo do odhaczenia.

| Obszar | Co przyjąć | Skąd |
|---|---|---|
| Tagi | Jedna taksonomia zamiast dwóch pojęć; limity 5 tagów/wpis, 2–30 znaków; kształt tabel `tags`/`tag_aliases`/pivot/`tag_follows`; ranking podpowiedzi prefiks → alias → podobieństwo → popularność → obserwowane; 8 wyników, min. 2 znaki | R1 §9 |
| Tagi | Obserwowanie tagów (publiczna strona, „Obserwuj", jeden wpis w feedzie mimo wielu tagów, „Pokaż więcej") — to prosta kontynuacja `TopicFeed` | R1 §9 |
| AI | Cała §1.7 (podpowiedzi tagów: throttling 1500 ms, min. 20 znaków, 1 wywołanie/15 s, **żadnego automatycznego dodania tagu**, awaria = neutralny komunikat) | R2 §10 |
| AI | Polityka awarii fail-open/fail-closed **warunkowa, nie jednolita**: brak sygnału ryzyka → publikuj; sygnał już jest, a druga opinia milczy → `pending_human`; lokalny `hard` → zawsze `pending_human` | R2 §7 |
| AI | `reject_recommended` **nigdy** nie usuwa treści automatycznie — zawsze człowiek. To jest mocna strona SPEC-u i chroni przed art. 22 RODO | R2 §4 |
| AI | Feature flagi i progi w `config/kuking.php` + `env()` — wpisuje się w istniejący wzorzec pliku bez tarcia | R2 §11 |
| Limity | Trzy koszyki limitera logowania z **dokładnymi liczbami SPEC-u**: para konto+adres 5/1 min, konto 15/15 min, adres 100/5 min | R3 §5 |
| Limity | `follow`/`unfollow` = 10 zmian stanu/min/konto | R3 §6 |
| Limity | Klucze limiterów jako identyfikator konta / `HMAC(login)` / `HMAC(ip)`, nigdy surowy tekst — zgodne z `AuditLogEntry` | R3 §10 |
| Granica zaufania | Cały projekt `ClientAddressResolver` + `X-Kuking-Edge-Token` + `CF-Connecting-IP` po weryfikacji, **bez zmian koncepcyjnych** | R3 §3 |
| Wydanie | Ruleset na `main`: brak force-push, brak usuwania, PR z **0 wymaganymi approvals**, wymagany status check, bypass tylko dla właściciela | R4 §11 |
| Wydanie | Railway „Wait for CI" włączone, bez ręcznego „Approve deployment" | R4 §11 |
| Wydanie | Cała lista „czego NIE wdrażać" (§4.6): `develop`, release branches, GitFlow, CODEOWNERS, obowiązkowy manual approval | R4 §7 |
| Magazyn | Cel: bucket prywatny, `r2.dev` wyłączone, brak custom domain, aplikacja jako jedyna brama | R4 §11 |
| Moderator | Trójpodział ról i zasada „administrator ma pełny **odczyt**, nie automatyczny bypass akcji destrukcyjnych" | R5 §11 |
| Moderator | Co logować, czego nie logować w audycie — zgodne z istniejącym `AuditLogEntry` | R5 §11 |
| Moderator | Informacja w polityce prywatności **tak**, powiadamianie o każdym pojedynczym wglądzie **nie** | R5 §8 |
| Zawieszenie | `suspended` zostaje widoczny w feedzie **obserwujących**; `banned` znika wszędzie | R5 §10 |
| Zawieszenie | Rozdział moderacji treści od zawieszenia konta — kod już to robi poprawnie, dwie niezależne osie | R5 §11 |

Do tego pięć rzeczy, które SPEC każe **zbudować**, a które **już istnieją** i
wymagają tylko potwierdzenia testem regresyjnym, żeby nikt ich nie cofnął:

1. Tabela `audit_log` z hashowanym IP (`AuditLogEntry::record()`, 12 miejsc wywołania).
2. Dependabot (composer + npm + github-actions, cotygodniowo, zgrupowany).
3. `unfollow` nie tworzy powiadomienia; powtórzony `follow` jest idempotentny.
4. „Pokaż więcej" wszędzie zamiast infinite scrolla (`<x-show-more>`, `cursorPaginate()`).
5. `Cache-Control: private, no-store` dla treści niepublicznej w `MediaController`
   — **lepsze** niż to, o co prosi SPEC (połowa TTL podpisu, nie cała).

---

## 2. Co poprawić w specyfikacji

Tu SPEC ma rację co do kierunku, ale liczba, założenie albo kolejność są błędne.
Każdy punkt ma zmierzone uzasadnienie.

### 2.1. Dolny pasek: 4 pozycje SPEC-u **łamią się** na telefonie

**SPEC §5.1 chce 4 pozycji (Start / Dodaj wpis / Powiadomienia / Mój profil)
zamiast dzisiejszych 5. Zmierzone w Chromium na realnym CSS aplikacji, przy
320 px i 100% skali tekstu: pasek SPEC-u zawija się na dwa wiersze, a dzisiejszy
pasek 5-pozycyjny mieści się w jednym.** Winowajcą nie jest liczba pozycji,
tylko długość etykiet — samo słowo „Powiadomienia" (131 px) wystarcza, żeby
złamać układ niezależnie od reszty.

To jest odwrotność intuicji, na której SPEC został napisany („mniej pozycji =
więcej miejsca"), i dokładnie dlatego trzeba było to zmierzyć.

Dwa dodatkowe argumenty przeciw:

- Usunięcie „Szukaj" zabrałoby **jedyną** dotykową drogę do wyszukiwarki poniżej
  64rem (pole w belce górnej jest tam świadomie ukryte). To byłaby regresja
  dostępności, nie neutralna zmiana.
- „Powiadomienia" są już osiągalne z belki górnej na **każdej** szerokości.
  Duplikowanie ich w dole nie dodaje funkcji, tylko zabiera miejsce.

**Poprawka: zostać przy 5 krótkich etykietach (Start / Szukaj / Dodaj / Moje /
Profil).** Zapas jest zerowy (320,0 px w 320 px), więc żadna etykieta nie może
urosnąć — to warto zapisać jako ograniczenie, nie zostawić przypadkowi.

### 2.2. Limity tras: problem jest większy, niż SPEC sądzi

SPEC §6.1 mówi o „domyślnym limiterze zapisów" jak o jednej sprawie. W kodzie są
dwie różne, a naprawiona jest tylko jedna.

- **Naprawione 7 września** (`ef4f6ca`): mieszanie liczników między trasami,
  które **już miały** throttle. To był powód zgłoszonego „429 przy pierwszym
  zdjęciu" — wszystkie 32 wywołania `throttle:` dzieliły jeden koszyk na osobę.
- **Wciąż otwarte**: z **63** tras zapisujących w `routes/web.php` około **50**
  nie ma żadnego throttle'a. W tym `follow`/`unfollow`/`block`/`unblock`,
  edycja i usuwanie wpisów, zeszyty, prawie wszystkie ustawienia konta i trasy
  administracyjne. SPEC liczy „35 tras" — realna liczba to 63.

**Poprawka: domyślny parasol 60/min/konto jako middleware grupowy, DODANY obok
istniejących limitów, nie zamiast nich** (Laravel liczy każdy `throttle:`
osobno). Plus: licznik `follow`+`unfollow` musi być **wspólny**, inaczej cykl
follow→unfollow mieści się w 20 zmianach/min zamiast 10 — czyli dokładnie
wzorzec, przed którym §6.3 ostrzega.

### 2.3. Test, który dziś asertuje podatność

`tests/Feature/ZaufaneProxyTest.php:62-78` **przechodzi** i **utrwala** bypass,
który SPEC każe naprawić. Powstał w dobrej wierze (naprawiał inny, prawdziwy
błąd: bez `trustProxies` wszyscy dzielili adres brzegu Railway). Ale jeśli
etap P0 nie wypisze tego pliku wprost jako do przepisania, stanie się jedno z
dwojga: albo zablokuje CI, albo ktoś „naprawi" go przywracając stare zachowanie.

**Poprawka: wpisać ten plik z nazwy do zadania etapu 1.**

### 2.4. Deduplikacja powiadomień: węższa, niż SPEC sądzi

§6.3 nazywa to „najważniejszą poprawką", jakby nic nie istniało. Istnieje:
`FollowUser::handle()` już wychodzi wcześniej, gdy relacja trwa, a `unfollow`
nigdy nie wysyłał powiadomienia. **Brakuje dokładnie jednej rzeczy: okna 24 h
dla cyklu `follow → unfollow → follow`**, bo `unfollow` robi twardy `detach()`,
więc kolejny `follow` widzi „nie obserwuję" i tworzy powiadomienie od zera.

Osobno, i tego SPEC nie ma wcale: `SocialController::follow()` nie łapie
naruszenia unikalności z bazy. Klucz główny `(follower_id, followed_id)` nie
dopuści duplikatu, ale przy podwójnym kliknięciu w dwa równoległe requesty
jeden dostanie **500** zamiast łagodnego „już obserwujesz".

### 2.5. `/health`: naprawione co innego, niż SPEC sądzi

§9 zakłada, że `/health` ujawnia szczegóły. **Ujawniał — naprawione 7 września**
(`6cdf04d`): zamknięty zbiór kodów, pełny wyjątek tylko do `Log::error`, cztery
testy pilnujące, że host/port/nazwa bazy nie pojawiają się w odpowiedzi.

Realna, wciąż otwarta luka to **wyłącznie** brak cache'u i locka na sondzie
dysku — `put()`+`get()`+`delete()` przy **każdym** żądaniu. Dobra wiadomość,
której SPEC nie miał: migracja `create_cache_table` tworzy już `cache_locks`,
więc `Cache::lock()` na store'ze `database` działa poprawnie **między
kontenerami** i nie trzeba niczego przełączać, wbrew zastrzeżeniu w §9.4.

Nie cache'ować `database` ani `migrations` — to tanie zapytania, a cache
zniweczyłby sens healthchecku w czasie prawdziwej awarii.

### 2.6. „Wymagany status check `ci`" nie istnieje

§4.3 zakłada job `ci`. W `ci.yml` jest **siedem niezależnych jobów i żadnego
agregującego**. Dwie tanie drogi: (A) zaznaczyć wszystkie nazwy w rulesecie,
(B) dopisać jeden lekki job `ci` z `needs: [...]`. **Rekomendacja: (B)** — mniej
klikania przy przyszłej zmianie nazwy joba.

Przy okazji fakt, który zmienia ocenę „czy PR do siebie samego ma sens":
„Require status checks to pass" egzekwuje się **wyłącznie w momencie scalania
PR-a**. Zwykły `git push origin main` nie ma w chwili pushowania żadnych checków
do sprawdzenia — uruchamiają się po fakcie. **PR, nawet 0-approval, nawet do
siebie, jest jedynym punktem, w którym GitHub może odmówić wpuszczenia
czerwonej zmiany na `main`.** To nie jest tarcie, to jest cała treść bramki.

### 2.7. Pinowanie zależności: SPEC prosi o rzecz już zrobioną

§4.4 każe „dodać Dependabot" — Dependabot jest od dawna. Zostaje **tylko**
przypięcie do SHA (8 akcji + 3 obrazy) i **rozszerzenie istniejącego
`dependabot.yml` o ekosystem `docker`**, czego SPEC nie wspomina. Koszt
utrzymania po przypięciu jest bliski zeru, bo Dependabot rozumie SHA i podmienia
hasz razem z komentarzem.

### 2.8. Moderacja: dostęp jest jednocześnie za wąski i za szeroki

To jest najważniejsze znalezisko R5 i nie jest to dostrajanie.

- **Za wąski dla treści**: zgłoszenie treści `private`/`followers` kończy się
  dla moderatora tym samym **404** co dla obcego, bo `ReportContent::authorize()`
  woła tę samą Policy co zwykły widz. Zgłoszenie treści prywatnej jest dziś
  **nierozpatrywalne**.
- **Za szeroki dla zdjęć**: `DostepDoZdjecia::moze()` przepuszcza moderatora
  **bezwarunkowo, przed sprawdzeniem rodzica** — bez sprawy, bez zgłoszenia.
  Moderator widzi dziś więcej przez trasę zdjęcia niż przez trasę treści, do
  której to zdjęcie należy. `PostPolicy` **nie wpuszcza** go do posta `private`,
  a `DostepDoZdjecia` wpuszcza do zdjęcia w tym samym poście.
- **Administrator nie istnieje w autoryzacji**: `isAdmin()` nie jest odpytywany
  nigdzie poza własną definicją. Obie role przechodzą przez `isModerator()`.

**Poprawka: usunąć `|| $widz->isModerator()` z wczesnego zwrotu w
`DostepDoZdjecia::moze()`** i pozwolić moderatorowi przechodzić przez zwykłą
pętlę po rodzicach. Wtedy dostęp do zdjęcia dziedziczy dokładnie to, co ustali
Policy dla treści — cokolwiek to będzie. To **usunięcie** jednej klauzuli, nie
dopisanie logiki, i jest tańsze niż każda alternatywa. Zdjęcia osierocone
(świeżo wgrane, jeszcze bez rodzica) przestaną być widoczne moderatorowi — nigdy
nie były mu potrzebne, autor widzi je przez `owner_id`, który zostaje.

Bez tej poprawki case-scoping treści byłby fasadą: tekst chroniony, zdjęcie
obok niego nie.

### 2.9. Zawieszenie: 9 z 10 miejsc już zgodne, jedno łamie ostrzejszą regułę

SPEC §8.1/8.2 traktuje to jako otwarty problem do ujednolicenia. Zmierzone: 13
z 14 sprawdzonych miejsc stosuje spójny próg. Jedyna luka — i **nie jest to
decyzja produktowa, tylko zwykły bug** — to szyna „Mój zeszyt" na stronie
głównej, która nie filtrowała autora wcale i przepuszczała **konto zbanowane**.
*(Naprawione 7 września w tej sesji; test regresyjny `SzynaZeszytuUkrywaZbanowanegoAutoraTest`.)*

Poprawka do SPEC-u: rozróżnić feed **obserwowanych** (widz już wybrał autora →
`dostepnyJakoAutor()`, `suspended` zostaje) od powierzchni **rekomendujących**
(`DiscoverFeed`, `DailyBoard`, wyszukiwarka → `status = active`, ostrzej). Dziś
oba mają identyczny, ostrzejszy próg, co karze retroaktywnie za treść
opublikowaną, gdy nic nie było zabronione.

### 2.10. Cena Sol ma datę ważności, której SPEC nie odnotował

Modele i ceny z §14 potwierdzone w dokumentacji OpenAI (7 września). **Ale cena
Sol (4/20 USD za 1M) jest promocyjna, co najmniej do 21 listopada 2026** —
oficjalny cennik mówi to wprost, SPEC podaje ją jako stan referencyjny.
Nieoficjalne źródła mówią o ~5/30 USD po promocji (niepotwierdzone).

Realny koszt przy dzisiejszym ruchu (1500 wpisów/mies.): **1,95–4,20 USD/mies.**
Przy 10× ruchu: 19,50–42,00 USD/mies. Miękki próg 5 USD jest sensowny dziś, ale
przy 10× wzroście zostanie przekroczony w pierwszym tygodniu miesiąca.

### 2.11. Tagi: model danych jest przedwczesny, a Tematy nie są długiem

SPEC pisze o `Topic` jak o starym relikcie. To kod scalony **dzień przed
specyfikacją** (#82, #95, 6 września), zbudowany po to, żeby rozwiązać
udokumentowany problem cold-startu. Kierunek „jedna taksonomia" jest słuszny,
ale SPEC nie odnotowuje, że przepisuje coś, co właśnie zaczęło działać, i nie
mówi, co zrobić z `posts.topic_id`, które od wczoraj może mieć wartości.

Z sześciu tabel `tag_relations` i `tag_merge_suggestions` da się bezpiecznie
odłożyć — obie są czysto addytywne. Repozytorium ma już wszystkie potrzebne
klocki (`kuking_normalize()`, wzorzec `canonical_name`/`normalized_name` z
`ingredients`, ratowanie zdjęć w `PostController::store`,
`recipe_slug_redirects`) — koszt jest niższy, niż SPEC sugeruje, **o ile te
wzorce zostaną powtórzone, a nie wymyślone od nowa**.

---

## 3. Czego nie robić wcale

| Nie robić | Dlaczego |
|---|---|
| **Nie dodawać hamburgera** | Stopka już robi to, co §5.4 chce osiągnąć (mniej ważne linki, zawsze widoczne, bez JS) i robi to **lepiej** niż `<details>`, bo nie wymaga interakcji, żeby zobaczyć, co jest do wyboru. Badania NN/g (2016, 179 uczestników): ukryta nawigacja obniża wykrywalność o >20%, wydłuża zadania o 15–39%. Zmiana byłaby ujemna: krok interakcji w zamian za nic |
| **Nie wdrażać moderacji zdjęć AI teraz** | Przy 20–50 kontach zapraszanych ręcznie ryzyko CSAM/pornografii jest bliskie zeru, a koszt fałszywego alarmu (surowy kurczak → „gore", krwisty stek → „przemoc") uderza dokładnie w grupę, która **rzadziej próbuje drugi raz** po złym doświadczeniu. Odłożyć do otwarcia rejestracji albo pierwszego realnego incydentu |
| **Nie wdrażać Poziomu 2 (Sol) na start** | Sol nic nie decyduje nieodwracalnie — tylko priorytetyzuje kolejkę moderatora. Przy 1500 wpisach/mies. i 1–2 osobach moderacji kolejka ludzka bez priorytetyzacji AI jest w pełni obsłużalna. Jego brak zastępuje trafienie każdego sygnału z Poziomu 1 prosto do `pending_human` — prościej i bezpieczniej |
| **Nie wpisywać zakresów IP Cloudflare do `trustProxies`** | **Strukturalnie bezużyteczne** na tej architekturze, niezależnie od częstotliwości zmian. Symfony porównuje `trustProxies` z bezpośrednim peerem TCP, a tym peerem jest **zawsze** proxy Railway, nigdy adres Cloudflare. Żaden request nigdy nie trafi w tę listę. (Komentarz w `bootstrap/app.php:44` przesadza z uzasadnieniem — zakresy zmieniają się raz na rok, nie co tydzień; ale wniosek zostaje) |
| **Nie budować teraz Cloudflare Tunnel ani mTLS/AOP** | Tunnel jest mocniejszy niż token, ale Railway nie ma integracji — wymagałby `cloudflared` obok FrankenPHP albo osobnego serwisu, czyli **nowego trybu awarii, którego dziś nie ma**. AOP prawdopodobnie niewykonalne (Railway sam terminuje TLS). Token jest dziś jedynym wykonalnym mechanizmem na tej platformie — Railway **nie oferuje** allowlisty IP na warstwie sieci |
| **Nie tworzyć custom domain na buckecie wariantów — nawet tymczasowo „do testów"** | Nieutworzenie to brak działania; utworzenie i późniejsze odpięcie to reguła cache z Edge TTL 30 dni do wyczyszczenia i osierocone adresy w cudzych mailach. `⛔` w runbooku §2.3 już to blokuje |
| **Nie robić okresu przejściowego z przekierowaniem starych adresów CDN** | Jedyny sposób, żeby stary adres dalej „działał", to dalsze serwowanie bajtów bez autoryzacji — czyli utrzymanie tej samej podatności. Nowe adresy (po W7-02) idą przez `/zdjecia/{media}/{wariant}` i nie są dotknięte |
| **Nie cache'ować licznika powiadomień w Redisie** | `AGENTS.md` §3 zakazuje dokładania Redisa bez zmierzonej potrzeby. Przy dzisiejszym ruchu to przedwczesna optymalizacja. Gdy zmierzy się potrzebę: kolumna licznikowa na `users` + test przeliczający ją z surowego zapytania |
| **Nie powiadamiać użytkownika o każdym pojedynczym wglądzie moderatora** | Przy zgłoszeniu o nękanie daje osobie badanej czas na usunięcie dowodów albo zastraszenie zgłaszającego — wprost przeciwne do celu moderacji. Właściwy moment już istnieje: DSA art. 17/20, **po** decyzji, z uzasadnieniem i prawem odwołania (`NotifyModerationDecision` to już robi) |
| **Nie budować nowej abstrakcji, żeby „scalić" 9 miejsc, które i tak się zgadzają** | Wystarczy dodać brakujący `Recipe::scopeTylkoOdAktywnychAutorow()` (dziś asymetria wobec `Post`), zastąpić nim 4 zduplikowane `whereHas` i dodać **jeden** test przekrojowy |
| **Nie zapisywać audytu wewnątrz Policy** | `Gate::allows()` jest wołane wielokrotnie „na sucho" w jednym requeście (pokazać/ukryć przycisk w widoku) — pomnożyłoby wpisy audytowe za każde sprawdzenie. Osobna, mała klasa domenowa wołana z kontrolera |
| **Nie tworzyć osobnego tokenu/kanału „awaryjnego" do ominięcia CI** | `deploy.yml` już wyjaśnia, dlaczego `railway up` jest odrzucone. Break-glass idzie przez tę samą integrację, tylko z pominięciem rulesetu na jednym pushu — nie przez równoległy kanał do utrzymywania osobno |
| **Nie upraszczać odpowiedzi `/health` do gołego `{"status":"ok"}`** | Obecna, bogatsza odpowiedź nie ujawnia żadnego sekretu (`app`, `environment`, `time` to nie dane wrażliwe) i ułatwia diagnozę. Kształt z §9.6 to **dolna granica**, nie docelowy dosłowny kształt |
| **Nie inwestować w wyrafinowane wykrywanie obchodzenia filtra wulgaryzmów** | Zbudować szkielet schematu (forma znormalizowana, severity, typ, wyjątki kontekstowe), ale nie obronę przed obejściami, dopóki nie ma dowodu, że ktoś próbuje. Dziś nie ma **żadnej** infrastruktury wulgaryzmów i **żadnego** udokumentowanego incydentu |

---

## 4. Co właściciel musi kliknąć sam — w bezpiecznej kolejności

Kolejność ma znaczenie. Trzy bloki są od siebie niezależne; **wewnątrz** bloku
kolejność jest krytyczna. Blok A można zrobić dziś, w dziesięć minut, i nic nie
psuje. Blok B wymaga wcześniejszej zmiany w kodzie. Blok C jest diagnostyczny.

### Blok A — GitHub i Railway: bramka jakości (bezpieczne od zaraz)

1. **Poczekać na kod**: agregujący job `ci` w `ci.yml` (§2.6). Bez niego nie ma
   czego zaznaczyć jako wymagane. To jedna mała zmiana, osobnym commitem.
2. **GitHub → Settings → Rules → Rulesets → nowy ruleset na `main`**:
   - zablokować force-push i usuwanie gałęzi,
   - „Require a pull request before merging" z **0 wymaganymi approvals**,
   - „Require status checks to pass" → `ci`,
   - bypass list ograniczona do właściciela.
   > 0 approvals to **oficjalnie wspierana** konfiguracja dla jednej osoby,
   > nie obejście — dokumentacja GitHuba mówi wprost, że wymóg PR-a „has no
   > effect if the ruleset requires zero approvals". Platformowa blokada
   > „autor nie może zaakceptować własnego PR" nie ma tu znaczenia, bo
   > scalenie nie czeka na żadną akceptację. Nie trzeba drugiego konta.
3. **Railway → serwis produkcyjny → Wait for CI: ON.**
   Bezpieczne **teraz**: CI od dawna daje zielone przebiegi na `main`.
   > Pułapka, którą repo już zna: przy `checkSuites: true` Railway czeka na
   > check suite, a jeśli go nie ma — czeka w nieskończoność i nic się nie
   > wdroży. Dziś na `push` do `main` uruchamia się **wyłącznie** `ci.yml`
   > (pozostałe workflow reagują na `pull_request` / `deployment_status`),
   > więc powstaje dokładnie jeden check suite i ryzyko jest niskie. **Nie
   > dodawać kolejnego workflow reagującego na `push` do `main`** bez
   > przemyślenia tej konsekwencji.
4. Zanotować w `docs/legal/BRAMKA_BETY.md`, że W7-08/09 jest zamknięte.

**Odwracalność**: pełna, każdy z tych przełączników wyłącza się tak samo łatwo.

### Blok B — Cloudflare i Railway: edge token (wymaga kodu, kolejność krytyczna)

Ten blok zamyka W7-01 — **jedyną pozostałą realną dziurę**. Robić dopiero, gdy
kod z etapu P0 jest gotowy, i **dokładnie w tej kolejności**, bo pomyłka po
kroku 4 odcina cały ruch produkcyjny.

1. **Wygenerować sekret**: `openssl rand -hex 32`.
2. **Railway → Variables → `KUKING_EDGE_TOKEN`** = ta wartość. **Przed**
   wdrożeniem kodu, który jej szuka.
3. **Wdrożyć kod w trybie „observe only"** — loguje niezgodność, **jeszcze nie
   blokuje**. SPEC tego kroku nie nazywa; chroni przed literówką w regule
   Cloudflare, która odcięłaby serwis. Zostawić na jeden dzień.
4. **Cloudflare → Rules → Request Header Transform** → ustawić
   `X-Kuking-Edge-Token` na tę samą wartość, na **wszystkich** żądaniach do
   `kuking.pl` (nie na wybranych ścieżkach — inaczej zewnętrzny monitoring
   `/health` idący przez Cloudflare dostanie 403).
5. **Sprawdzić w logach**, że **wszystkie** prawdziwe żądania niosą już token.
   Dopiero wtedy przełączyć na tryb blokujący (redeploy z flagą).
6. **Zmierzyć wynik**: `curl` wprost na `*.up.railway.app` bez tokenu powinien
   dostać odmowę; przez `kuking.pl` — przejść normalnie.
7. **Na końcu** uporządkować `trustProxies` — to zmiana porządkowa wobec
   właściwej ochrony, nie ma powodu robić jej wcześniej i mieszać w
   najważniejszej części wdrożenia.

**Fakt do zapamiętania**: Cloudflare **nie pozwala** nadpisać `X-Forwarded-For`
regułą transformacji i sam dopisuje prawdziwy adres odwiedzającego tuż przed
originem. To dobra wiadomość dla ruchu idącego **przez** Cloudflare — i nie
rozstrzyga nic dla ruchu z pominięciem Cloudflare, bo tam żadna reguła
Cloudflare w ogóle nie działa. Stąd token.

### Blok C — Cloudflare R2: sprawdzić, czy `cdn.kuking.pl` istnieje (diagnostyka)

Serwis **jest** na produkcji, więc to pytanie przestało być teoretyczne.

Z tego kontenera `cdn.kuking.pl` nie odpowiada (proxy nie zestawia tunelu, co
jest spójne z brakiem rekordu DNS, ale **nie jest dowodem** — proxy zasłania
różnicę między „nie istnieje" a „nieosiągalne"). Autorytatywna odpowiedź jest
jedna:

1. **Cloudflare → R2 → bucket wariantów → Settings → Custom Domains.**
   - **Brak wpisu** → nic do odpinania. Przejść do punktu 4.
   - **`Active`/`Initializing`** → domena istnieje, przejść do punktu 2.
2. Jeśli istnieje, sprawdzić, czy **realnie serwuje bajty bez autoryzacji** —
   sam kod 404 na `/` niczego nie dowodzi, bo pusty bucket odpowiada tak samo:
   wziąć ścieżkę obiektu z `Location:` przekierowania 302 z
   `/zdjecia/{uuid}/{wariant}` i odpytać nią **bezpośrednio** domenę CDN, bez
   podpisu. `200` + rozpoznany WebP = potwierdzony wyciek.
3. Jeśli potwierdzony, w tej kolejności:
   a. **Najpierw** dokończyć migrację: `php artisan kuking:przenies-zdjecia`,
      aż zgłosi „Komplet przeniesiony". Odpięcie publiczności `r2_legacy`
      przed tym momentem **zepsuje realne, niezmigrowane zdjęcia**.
   b. R2 → bucket wariantów → Custom Domains → odpiąć `cdn.kuking.pl`;
      sprawdzić, że `r2.dev` jest wyłączone.
   c. Caching → Cache Rules → usunąć regułę `http.host eq "cdn.kuking.pl"`.
   d. **Caching → wyczyścić cache brzegowy dla tego hosta.** Reguła z Edge TTL
      30 dni oznacza, że węzły PoP mogą trzymać kopie **do miesiąca** po
      zniknięciu domeny z DNS. To krok konieczny, nie kosmetyczny.
4. Niezależnie od wyniku: `⛔` w `DEPLOYMENT_RUNBOOK.md` §2.3 już stoi (dodane
   6 września), ale **checklista smoke-testów wciąż każe sprawdzać, że adres
   zdjęcia zaczyna się od `https://cdn.kuking.pl/`** (`DEPLOYMENT_RUNBOOK.md:853`,
   `scripts/sprawdz-wdrozenie.sh:249-251`). To trzeba poprawić, inaczej test
   będzie fałszywie czerwony albo — gorzej — ktoś „naprawi" go, tworząc domenę.
5. Zamknąć albo przeformułować issue #120 zgodnie z wynikiem.

### Blok D — sprawy prawne i administracyjne (bez panelu, ale tylko właściciel)

1. **Polityka prywatności u prawnika.** `/prywatnosc` publicznie serwuje dziś
   szkic z placeholderami (`[NAZWA OPERATORA]`) i sam się deklaruje jako
   wymagający weryfikacji. To jest publiczna obietnica, której kod nie
   dotrzymuje — czwarta rezerwacja właściciela.
2. **To blokuje AI.** Tabela podprocesorów nie wymienia żadnego dostawcy AI, a
   OpenAI **przechowuje treść requestu 30 dni** do monitorowania nadużyć (Zero
   Data Retention wymaga zgody działu sprzedaży, nie jest przełącznikiem w
   panelu — i jest **niepotwierdzone**, czy OpenAI w ogóle rozpatrzy wniosek
   klienta tej wielkości). Wysyłka treści użytkownika do AI bez tego wpisu jest
   przetwarzaniem, o którym użytkownik nie został poinformowany.
   > Tańsza droga na czas zamkniętej bety: **poinformować wprost przy
   > zaproszeniu/onboardingu**, że wpisy są sprawdzane przez AI, zamiast
   > blokować cały etap na pełną rewizję prawną. To decyzja właściciela.
3. **Podpisać DPA** wybranego dostawcy AI **przed** pierwszym produkcyjnym
   requestem z prawdziwą treścią. DPA nie jest automatyczne przez samo
   korzystanie z API.
4. **Wpisać do kalendarza 21 listopada 2026**: sprawdzić cennik Sol po
   wygaśnięciu promocji (§2.10).
5. **Zdjęcie z kuchni może zawierać dane osobowe w treści obrazu** — twarze
   domowników, wnętrze mieszkania, tablice rejestracyjne za oknem. Usunięcie
   EXIF (które SPEC słusznie wymaga) tego **nie dotyka**. Polityka powinna to
   adresować wprost, nie zakładać milcząco, że EXIF wystarcza.

---

## 5. Pytania, na które tylko właściciel odpowie

Pogrupowane po tym, co blokują. Nie są to pytania retoryczne — każde zmienia
kolejność albo zakres prac.

**Blokują wdrożenie tagów (R1):**

1. Czy w bazie są już wiersze w `topic_follows` albo wpisy z niepustym
   `posts.topic_id`? Decyduje, czy migracja potrzebuje backfillu, czy tylko
   sprawdzenia i przerwania.
2. Czy lista promowanych tagów ma zachować identyczne 30 nazw/slugów co
   dzisiejsze Tematy? Decyduje, czy `/temat/{slug}` → `/tag/{slug}` może być
   mapowaniem 1:1.
3. Kto realnie przegląda raport odrzuceń przed uruchomieniem seeda 1200 tagów +
   2500 aliasów na produkcji?

**Blokują AI (R2):**

4. Moderacja obrazu AI w zamkniętej becie — czy zgoda na odłożenie? To
   największa różnica tego przeglądu wobec SPEC-u.
5. Jeden dostawca (Anthropic, skoro Claude Code i tak jest w użyciu) kosztem
   utraty darmowego `omni-moderation-latest`, czy dwóch? **Różnica kosztowa jest
   nieistotna** (pojedyncze dolary miesięcznie) — to decyzja o liczbie umów i
   DPA, nie o pieniądzach.
6. Punktowa informacja dla betatesterów teraz, czy czekamy na pełną rewizję
   polityki?

**Blokują P0 bezpieczeństwa (R3):**

7. Czy zewnętrzny monitoring uptime odpytuje `/health` przez `kuking.pl` (czyli
   przez Cloudflare), czy bezpośrednio Railway? Rozstrzyga, czy monitoring w
   ogóle zobaczy skutek edge tokenu.
8. Akceptacja jednodniowego okna „observe only" przed przełączeniem tokenu na
   tryb blokujący? Wydłuża P0 o jeden cykl deployu, ale chroni przed literówką
   odcinającą serwis.
9. `follow` i `unfollow` — wspólny licznik 10/min (rekomendacja) czy osobne po
   10 każdy?

**Blokują magazyn (R4):**

10. Ile rekordów `media` ma dziś `disk = 'r2_legacy'`? Determinuje, czy
    `kuking:przenies-zdjecia` ma jeszcze co robić przed jakimkolwiek odpięciem.
11. Czy publiczna domena CDN dla wariantów ma być **kiedykolwiek** możliwa (np.
    przy dużym ruchu), czy D-020 jest ostateczne? Rozstrzyga, czy #120 domykać
    jego oryginalną checklistą, czy zamknąć jako nieaktualne i dopisać test
    „nigdy nie twórz custom domain na buckecie wariantów".

**Blokują moderację i UI (R5):**

12. Case-scoping moderatora: minimalna wersja przez `status` zgłoszenia
    (rekomendacja — dostęp naturalnie wygasa, gdy sprawa przestaje być otwarta),
    czy pełna z wygasaniem czasowym?
13. Administrator to zawsze właściciel, czy przewidziana jest **druga** osoba
    bez dostępu infrastrukturalnego? Jeśli druga — formalizacja `isAdmin()`
    przestaje być higieną i staje się zamknięciem realnej luki.
14. Cztery czy pięć pozycji w dolnym pasku? Rekomendacja: pięć (§2.1). Jeśli
    jednak cztery — to Start / Szukaj / Dodaj / Profil, a „Moje" przenieść do
    huba „Mój profil"; **nigdy** wariant SPEC-u z długimi etykietami.
15. „Zeszyt" czy „Moje" jako nazwa czwartej pozycji? `AGENTS.md` mówi jedno, kod
    i `UX_50_PLUS.md` drugie. Pytanie wisi od poprzedniej sesji
    (`docs/HANDOVER.md` §6) — jeden z tych dokumentów musi się ruszyć.

---

## 6. Rzeczy, których żaden raport nie potwierdził

Uczciwość wymaga wypisania tego osobno. Nie cytować tych rzeczy jako faktów.

- **„Dopisanie słowa «Menu» obok ikony zwiększa zaangażowanie o 20%"** —
  krąży w agregatorach UX, **nie ma jej** ani w artykule NN/g o hamburgerach
  (2016), ani w „Supporting Mobile Navigation in Spite of a Hamburger Menu"
  (2015). Nie wpisywać do dokumentacji produktowej.
- **Cena Sol po promocji** (~5/30 USD za 1M) — dwa drugorzędne źródła SEO,
  sprzeczne z oficjalnym cennikiem. Sprawdzić po 21.11.2026, nie zakładać.
- **Kwalifikowalność do Zero Data Retention** dla klienta wielkości Kuking —
  wymaga rozmowy z działem sprzedaży OpenAI, nie da się sprawdzić z zewnątrz.
- **Czy Railway usuwa/nadpisuje `X-Forwarded-For`** — nagłówek **nie jest
  wymieniony** w dokumentacji Railway w ogóle: ani jako ustawiany, ani jako
  usuwany. To luka informacyjna, nie potwierdzenie w żadną stronę. Rozstrzyga
  to tylko pomiar na żywej infrastrukturze (Blok B, krok 6).
- **Brak allowlisty IP na Railway** — potwierdzone forum społecznościowym, nie
  oficjalną dokumentacją. Traktować jako mocną wskazówkę, nie pewnik.
- **Czy `cdn.kuking.pl` istnieje** — z kontenera nieosiągalne, ale to nie dowód.
  Autorytatywna jest tylko lista Custom Domains w panelu R2 (Blok C).
- **Interpretacja RODO, że nie trzeba informować o każdym pojedynczym dostępie
  personelu** — standardowa i wysoce prawdopodobna, ale oznaczona do
  weryfikacji przez prawnika razem z resztą polityki.

---

## 7. Kolejność prac, gdyby robić wszystko

Jedna lista, bez podziału na raporty, uporządkowana po tym, co blokuje co.

**P0 — bezpieczeństwo, tylko kod:**
1. Test reprodukujący bypass XFF **i** świadome przepisanie
   `ZaufaneProxyTest.php:62-78` (§2.3).
2. `ClientAddressResolver` + zastąpienie `$request->ip()` w limiterach **oraz
   w 21 miejscach audytowych** — dziś każde z nich zapisuje do `audit_log`
   adres równie podrabialny jak przy logowaniu. Tania poprawka przy okazji.
3. Trzy koszyki limitera logowania **w miejsce** istniejącego throttle'a
   i ręcznego `RateLimiter` w `LoginController` — nie jako czwarty licznik obok.
   Przy sukcesie czyścić koszyk pary i konta, **nigdy** koszyka adresu (inaczej
   napastnik zaloguje się na własne konto, żeby zresetować licznik i wrócić).
4. Domyślny parasol 60/min/konto + wspólny licznik follow/unfollow (§2.2).
5. Dedup powiadomień 24 h + obsługa wyścigu w `SocialController::follow()` (§2.4).
6. Cache + lock na sondzie dysku `/health` (§2.5) — niezależne, w dowolnym momencie.
7. Kod czytający `X-Kuking-Edge-Token` (`hash_equals`) — **bezużyteczny bez
   Bloku B panelu**, musi wejść w tej samej sesji wdrożeniowej.

**P0 — moderacja (jedna zmiana, duży efekt):**
8. Usunąć `|| $widz->isModerator()` z `DostepDoZdjecia::moze()` + test
   negatywny „moderator bez sprawy nie widzi bajtów prywatnego zdjęcia" (§2.8).

**P1 — łańcuch wydania:**
9. Agregujący job `ci` w `ci.yml`, potem Blok A panelu.
10. Przypięcie akcji do SHA + `docker` w `dependabot.yml` (§2.7).
11. Sekcja break-glass w dokumentacji: kiedy wolno użyć bypassu (niedostępność
    serwisu; CI faktycznie zepsute — **nie** CI czerwone z powodu prawdziwego
    błędu w zmianie), i **reguła zamykająca**: tego samego dnia wypchnąć commit
    przywracający normalną ścieżkę i zanotować, co się stało. Ślad zostaje
    natywnie w audit logu GitHuba, **o ile** bypass idzie przez ruleset, a nie
    przez wyłączenie rulesetu.

**P1 — tagi:** trwa (osobna gałąź). Etapy: model danych → seed → podpowiadanie
bez JS → usunięcie Tematów → ekran gospodarza dla tagów promowanych.

**P2 — moderacja case-scoped:** Policy z jawnym `?reportId`, trasa podglądu
`/admin/zgloszenia/{report}/podglad`, `isAdmin()` w `view()` (**nigdy** w
`update()`/`delete()`), audyt przez osobną klasę domenową, test przekrojowy
„każde miejsce dostępu uprzywilejowanego tworzy wpis audytowy".

**P2 — AI:** dopiero po Bloku D (polityka + DPA). Zakres okrojony: Poziom 0
lokalny + Poziom 1A/1B dla **tekstu**, podpowiedzi tagów. Architektura: zapis
wpisu jako opublikowanego **natychmiast**, moderacja asynchroniczna **po**
zapisie, która dopiero potem może obniżyć widoczność. **Nie** jako bramka przed
zapisem — to jest dokładnie ten sposób, w który §1.11 zamienia się w naruszenie
§0 pkt 19.

**Przed włączeniem AI, tanio i offline:** przepuścić przez model prawdziwe
tytuły i opisy z bazy (mięsa, ryby, przetwory, wypieki na zakwasie) i **zobaczyć
wynik, zanim zobaczy go użytkownik**. Ustalić z góry próg akceptowalności (np.
„>2% wpisów do `pending_human` z powodu kontekstu kulinarnego → wyłączamy"), bo
decyzja podjęta wcześniej i chłodno jest łatwiejsza niż po fakcie.
