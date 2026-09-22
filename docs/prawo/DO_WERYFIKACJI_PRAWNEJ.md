# Do weryfikacji prawnej — Kuking.pl

**Dla kogo:** dla prawnika, który ma ocenić serwis przed publicznym startem.
**Data zestawienia:** 21 września 2026 · **stan kodu:** `origin/main` `cd966aae`.
**Zlecenie:** issue #8 („Regulamin i polityka prywatności: weryfikacja prawnika
przed publicznym startem”) plus kilkanaście osobnych znalezisk narosłych wokół
niego.

Dokument **zestawia dwie rzeczy obok siebie**: co obiecuje polityka prywatności
i regulamin, oraz co naprawdę robi kod. Każdy rozjazd to jedna pozycja.

**Każde zdanie o kodzie ma tu dowód** — ścieżkę pliku z numerem wiersza albo
nazwę testu. Zdania bez dowodu są oznaczone jako `niezweryfikowane` i tak
zostają; nie zgadywaliśmy.

**Czego ten dokument NIE robi:** nie ocenia zgodności z prawem, nie proponuje
brzmienia zapisów i nie rozstrzyga żadnego z pytań niżej. To jest materiał
faktograficzny, nie opinia.

**Skróty dowodów:** `polityka:88` = `resources/legal/polityka-prywatnosci.md`
wiersz 88; `regulamin:117` = `resources/legal/regulamin.md` wiersz 117.

---

## 1. Streszczenie — co jest do rozstrzygnięcia, od najpilniejszego

Serwis jest napisany i działa. Dokumenty prawne są opublikowane pod
`/regulamin`, `/prywatnosc` i `/zasady`, napisane prostym językiem, i
**pięć plików testów pilnuje, żeby nie kłamały o kodzie**. Problemy nie
polegają więc na bałaganie, tylko na kilkunastu konkretnych miejscach, w
których obietnica i kod się rozjechały — oraz na rzeczach, których w
repozytorium nie ma wcale.

**Cztery rzeczy blokujące start, w kolejności:**

1. **Nie ma umów powierzenia (DPA) z żadnym z ośmiu odbiorców danych.**
   Polityka mówi o tym wprost (`polityka:81`), a dane już do nich płyną.
   Dwóch odbiorców jest poza EOG (Cloudflare Inc., OpenAI L.L.C.).
   → §2, poz. **R-06**.
2. **Nie ma rejestru czynności przetwarzania (art. 30 RODO).** Własna
   analiza projektu uznaje go za obowiązkowy i odnotowuje jego brak:
   `docs/legal/COMPLIANCE.md:312` — „`BRAK:` takiego dokumentu nie ma w
   repozytorium ani nigdzie indziej”. → §2, poz. **R-14**.
3. **Regulamin obiecuje zgłaszanie CSAM organom; w kodzie nie ma na to ani
   jednej ścieżki.** `regulamin:117` vs zerowy wynik `grep` po `app/` i
   `resources/views/`. → §2, poz. **R-05**.
4. **Paczka z danymi deklaruje art. 15 RODO, a świadomie pomija całe
   kategorie danych osobowych.** Człowiek czyta ją zwykle przed
   skasowaniem konta. → §2, poz. **R-01**.

**Osobno, i to jest jedyne pytanie, w którym właściciel już podjął decyzję
i prosi o jej ocenę, a nie o naprawę:** projektowany rejestr potwierdzeń
żądań RODO ma **na stałe zachowywać `konto_id`**, także po wykonaniu
żądania usunięcia. → §3, pytanie **P-1**.

**Ile tego jest:** 17 pozycji w §2, z czego 4 blokujące start, 4 wymagające
decyzji prawnika o brzmieniu dokumentu, reszta to usterki inżynierskie
o skutkach prawnych. Białe plamy — §4.

---

## 2. Rozjazdy: obietnica ↔ kod

Każda pozycja: **co obiecujemy** (cytat) · **co robi kod** (dowód) · **czego
dotyczy w RODO/DSA** · **realne ryzyko dla człowieka**.

---

### R-01 · Polityka obiecuje „pełną kopię” danych; paczka świadomie pełna nie jest

**Co obiecujemy** — `polityka:88`:
> „**dostępu** do swoich danych — możesz je zobaczyć w ustawieniach konta lub
> poprosić o **pełną kopię**”

**Co robi kod.** Paczka sama deklaruje podstawę
(`app/Domain/Users/Exports/CollectUserExportData.php:115`):
> `'podstawa_prawna' => 'RODO art. 15 (dostęp do danych) i art. 20 (przenoszenie danych)'`

a jednocześnie niesie **zamkniętą listę 13 sekcji**
(`CollectUserExportData.php:56, 132–143`: `o_tym_pliku`, `konto`, `profil`,
`przepisy`, `wpisy`, `ugotowalem`, `moje_komentarze`, `kolekcje`, `obserwuje`,
`obserwuja_mnie`, `zablokowane_osoby`, `powiadomienia`, `zdjecia`) i
**w komentarzu sama wymienia, czego nie niesie**
(`CollectUserExportData.php:60–70`, wyliczenie w `:64–67`): wcześniejszych
wersji własnych przepisów, obserwowanych tagów, dziennika zgód i tożsamości
zewnętrznych.

Poza paczką zostają dodatkowo (tabele istnieją i zawierają dane tej osoby):

| kategoria | tabela / migracja |
|---|---|
| wcześniejsze wersje przepisów | `database/migrations/2026_09_05_000400_create_recipes_tables.php:99` (`recipe_versions`) |
| obserwowane tagi | `database/migrations/2026_09_07_100000_create_tags_tables.php:213` (`tag_follows`) |
| dziennik zgód | `database/migrations/2026_09_10_400000_create_dziennik_zgod_table.php:115` |
| tożsamości zewnętrzne (Google/Facebook) | `database/migrations/2026_09_10_500000_create_tozsamosci_zewnetrzne_table.php` |
| aktywne sesje: IP, przeglądarka, czas | `database/migrations/0001_01_01_000001_create_users_table.php:71, :72, :74` |
| wiadomości „Napisz do nas” i nasze odpowiedzi | polityka deklaruje ich przechowywanie: `polityka:37` |
| zgłoszenia, decyzje moderatora, odwołania | polityka deklaruje 36 miesięcy: `polityka:29` |
| dziennik zdarzeń bezpieczeństwa (skrót IP) | polityka deklaruje 12 miesięcy: `polityka:30` |

Test pilnuje granicy **tylko po stronie paczki**, nie po stronie polityki:
`tests/Feature/PaczkaNieObiecujeKompletuTest.php:50` i `:77`.

**Czego dotyczy** — RODO art. 15 ust. 1 i ust. 3 (kopia danych podlegających
przetwarzaniu, niezależnie od źródła) vs art. 20 (dane dostarczone przez
osobę). Issue #953 opisuje to jako dwie różne granice.

**Ryzyko dla człowieka.** Dostaje archiwum, które wygląda na komplet, i zwykle
czyta je **przed skasowaniem konta** — czyli w chwili, gdy nie ma już jak
dopytać. Jeśli chciał zabrać ze sobą historię wersji swoich przepisów albo
sprawdzić, co wiemy o jego logowaniach, nie dowie się nawet, że tego nie
dostał.

**Uwaga:** kierunek naprawy to rozstrzygnięcie **albo–albo** (zawęzić zdanie
w polityce, albo poszerzyć paczkę) i dlatego stoi tu, a nie na liście usterek.

---

### R-02 · Eksport gubi tytuł pytania — pytanie bez opisu traci całą wypowiedź

**Co obiecujemy** — `polityka:88` (jw.) oraz ekran zamawiania paczki
(`resources/views/pages/settings/data.blade.php:28`):
> „Przygotujemy paczkę z Twoimi wpisami, przepisami, zdjęciami i komentarzami.”

**Co robi kod.** Wpisy typu „pytanie” mają osobny tytuł (10–180 znaków),
a opis może być pusty — kolumny `kind` i `title`:
`database/migrations/2026_09_18_100000_add_kind_and_title_to_posts.php:17–18`.
Mapowanie do eksportu **nie przenosi żadnej z tych kolumn**:
`app/Domain/Users/Exports/CollectUserExportData.php:255–274` (klucze: `tresc`,
`widocznosc`, `status`, `utworzono`, `opublikowano`, `dotyczy_przepisu`, `tagi`,
`zdjecia`, `komentarze`). Wersja czytelna dla człowieka też nie:
`app/Jobs/GenerateUserExport.php:279–291` i
`resources/views/exports/posts.blade.php:43` (renderowana wyłącznie `tresc`).

**Czego dotyczy** — RODO art. 15 ust. 3 i art. 20 ust. 1. Issue #832.

**Ryzyko dla człowieka.** Pytanie, które zadał bez opisu, znika z paczki jako
pusty wpis z datą — a odpowiedzi innych osób zostają, bez pytania, którego
dotyczyły. To nie jest brak etykiety: to utrata tekstu, który ten człowiek
sam napisał.

---

### R-03 · Polityka obiecuje, że do OpenAI idzie *pomniejszone* zdjęcie; kod tego nie gwarantuje

**Co obiecujemy** — `polityka:73`:
> „wysyłamy do OpenAI **sam tekst tej treści**, a przy wpisie ze zdjęciem także
> **pomniejszone zdjęcie** (miniaturę przekodowaną u nas, więc bez danych
> z aparatu — bez daty i bez współrzędnych miejsca, w którym zdjęcie zrobiono)”

To samo w `docs/legal/COMPLIANCE.md:314` i `:340`.

**Co robi kod.** `app/Moderacja/OcenaModelem.php:178` prosi o wariant `thumb`.
Ale `app/Models/Media.php:253–259` przy braku tego wariantu **nie odmawia,
tylko podstawia pierwszy dostępny**:
> `// Wariant nieznany, ale jakieś istnieją — bierzemy pierwszy lepszy.`
> `// (…) obraz będzie w złym rozmiarze, ale bezpieczny.`

Przekodowanie do JPEG (`OcenaModelem.php:176`) zmienia format, nie wymiary.

**Czego dotyczy** — RODO art. 5 ust. 1 lit. a (rzetelność i przejrzystość),
art. 13 ust. 1 lit. f i art. 44–49 (przekazanie poza EOG na podstawie DPF/SCC —
zakres przekazania ma odpowiadać deklarowanemu). Issue #912.

**Ryzyko dla człowieka.** Jego zdjęcie może wyjść poza EOG w rozmiarze innym
niż miniatura, którą mu obiecano — a przy dużym wariancie rośnie to, co da
się z obrazu odczytać. **To jest odczyt kodu, nie dowód incydentu**: nikt nie
policzył, ile zdjęć na produkcji nie ma wariantu `thumb` (§4).

---

### R-04 · Polityka opisuje ciasteczka jako sesyjne; dwa żyją rok

**Co obiecujemy** — `polityka:101`:
> „Używamy technicznie niezbędnych plików cookies (np. do utrzymania sesji
> logowania) — te nie wymagają Twojej zgody, bo bez nich serwis nie mógłby
> działać.”

**Co robi kod.** Poza sesją serwis stawia dwa ciasteczka preferencji
z ważnością **roku** (`60 * 24 * 365` minut):
`app/Http/Controllers/Settings/AccessibilitySettingsController.php:46`
(rozmiar tekstu) oraz `app/Http/Controllers/ThemeController.php:69` i `:72`
(motyw i rozmiar tekstu).

**Czego dotyczy** — art. 5 ust. 3 dyrektywy 2002/58/WE i odpowiadające przepisy
Prawa komunikacji elektronicznej (2024). Pytanie: czy ciasteczko preferencji
ustawiane **na wyraźne życzenie użytkownika** mieści się w „technicznie
niezbędnych”.

**Ryzyko dla człowieka.** Materialnie niskie — żadne z tych ciasteczek nie
służy statystyce ani reklamie. Rzecz w tym, że polityka ich nie nazywa, więc
człowiek nie wie, co zostaje na jego urządzeniu przez rok.

---

### R-05 · Procedura CSAM jest kompletna na papierze, ale w kodzie nie ma jej wcale

**Co obiecujemy** — `regulamin:117`:
> „Wyjątkiem są sytuacje wymagające natychmiastowego działania ze względów
> bezpieczeństwa (np. treści dotyczące wykorzystywania dzieci) — tam działamy
> od razu i **zgłaszamy sprawę odpowiednim organom**, bez wcześniejszego
> kontaktu z autorem.”

**Co robi kod.** Nic. `grep -rniE "csam|dyzurnet|dyżurnet|policj|prokurat"` po
`app/` i `resources/views/` → **zero trafień** (sprawdzone na `cd966aae`).
Procedura istnieje wyłącznie jako tekst: `docs/legal/MODERATION_PLAYBOOK.md`
§7.1, a własna lista gotowości trzyma to jako P0 do potwierdzenia —
`docs/legal/COMPLIANCE.md:310`: „`DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` (…)
potwierdzić właściwy organ **przed startem, nie w trakcie incydentu**”.
Audyt procedur nazwał kiedyś „sprzeczną instrukcję przy CSAM — najgorsze możliwe
miejsce” (`docs/legal/AUDYT_PROCEDUR_2026_09_20.md:95`), ale **ta sprzeczność
została już naprawiona** — §4.1 tego samego audytu kończy się słowem
„Poprawione”, a playbook §7.0 ma dziś tabelę ostrzegającą, że podstawa
„Treść niezgodna z prawem” wymusza wiadomość do autora (`required_if`
w `ModerationController::decide()`, w. 199), a „Krzywdzenie dzieci — usuwamy
natychmiast” przyjmuje puste pole. Nie traktować tego cytatu jako stanu bieżącego.

**Trzy ustalenia z kodu, dodane 21.09 po przeglądzie zewnętrznym i zweryfikowane
odczytem (żadnego testu nie uruchomiono).** Są mocniejsze niż pierwotna treść tej
pozycji, bo dotyczą skutku, a nie braku:

**(a) Powiadomienie nazywa kategorię, mimo pustego pola wiadomości.**
`PodstawaDecyzji.php:267` przy podstawie `krzywdzenie-dzieci` zwraca:
*„Podstawą tej decyzji jest zasada, od której nie ma u nas wyjątku: treści
krzywdzące dzieci usuwamy natychmiast."* Playbook §2 i §7.1 mówią autorowi
procedury, że „pójdzie samo neutralne zdanie domyślne" — neutralna jest
wiadomość, ale ekran dokleja do niej zdanie o podstawie. **To stoi w napięciu
z krokiem 4 tego samego playbooka**: „nie opisuj szczegółowo powodu
w komunikacji z użytkownikiem — to może zaszkodzić postępowaniu, jeśli sprawa
trafi do organów". Pytanie dla prawnika: czy przy tej kategorii wolno — albo
trzeba — odstąpić od uzasadnienia wymaganego przez art. 17 DSA.

**(b) Usunięcie treści nie odcina zdjęcia.** `DostepDoZdjecia` przepuszcza plik,
gdy **którykolwiek** rodzic jest widoczny. Jeśli ten sam rekord `media` jest
zdjęciem publicznego przepisu B, usunięcie wpisu A nie zmienia dostępności.
Reguła jest **zamierzona i dla zwykłych zdjęć poprawna** — kod uzasadnia ją tak:
*„gdyby wygrywał rodzic najwęższy, publiczny przepis pokazywałby pustą ramkę
tylko dlatego, że autor wrzucił to samo zdjęcie gdzieś jeszcze"*.

**(c) Na samym zdjęciu moderator nie ma żadnego działania poza banem autora.**
`ModerationAction::DOZWOLONE['media']` to `none`, `warn`, `suspend`, `ban` —
świadomie bez `hide` i `remove`. Powód też jest dobry: `Media` nie ma miękkiego
kasowania, więc `remove` byłoby nieodwracalne, a **od decyzji `remove`
przysługuje odwołanie z art. 17 DSA**. Kod pisze wprost: *„Decyzja, od której nie
da się skutecznie odwołać, nie może stać na tym ekranie."* Dodanie przycisku
byłoby więc złą poprawką — brakuje miękkiego kasowania zdjęć, nie przycisku.

**ZAWĘŻENIE (21.09, po kontrargumencie zewnętrznym i sprawdzeniu w kodzie).**
Poprzednia wersja tego akapitu mówiła, że po usunięciu treści i banie konta
„plik nadal jest serwowany". **To było za mocne i nie było wynikiem pomiaru.**

`PostPolicy` i `RecipePolicy` zawierają jawny warunek:
```php
if (! $isOwnerOrModerator && ! $post->author->jestDostepnyJakoAutor()) {
    return false;
}
```
Ban odcina zwykłym odbiorcom dostęp do wpisów i przepisów autora. **Jeżeli oba
miejsca użycia zdjęcia należą do zbanowanego konta, reguła „którykolwiek rodzic"
nie znajdzie widocznego rodzica** i plik przestaje być serwowany nowym żądaniom
aplikacyjnym.

**Co zostaje prawdziwe po tym zawężeniu** — i tylko to należy pokazywać jako
ryzyko:
1. **Kolejność ma znaczenie.** Między usunięciem treści a banem (dwie osobne
   sprawy, bo jedno zgłoszenie przyjmuje jedną decyzję) istnieje okno, w którym
   plik jest dostępny przez drugiego rodzica.
2. **Zdjęcie z rodzicem na INNYM, niezbanowanym koncie** nie jest odcięte —
   reguła „którykolwiek rodzic" działa wtedy w pełni.
3. **Wcześniej wydane podpisy, cache i ewentualny publiczny magazyn `r2_legacy`**
   są poza zasięgiem obu polityk. Ban nie unieważnia podpisu, który już wyszedł.
4. **Moderator nie ma jak potwierdzić żadnego z powyższych** — panel mówi
   „Decyzja zapisana", nie „dostęp odcięty".

**OSTRZEŻENIE DO CAŁEJ TEJ SEKCJI (dopisane po recenzji kodu testu).**
Poniższa tabela to **zaobserwowane odczyty, nie asercje**. Test
`PomiarOdcieciaDostepuDoPlikuTest` ma **dwie** asercje i obie dotyczą wyłącznie
starego podpisu (`200` po ostatniej decyzji, `403` po odczekaniu). Statusy gościa,
właściciela i moderatora oraz cele przekierowań są **zapisywane do CSV, ale nigdy
sprawdzane** — zielony wynik testu **nie potwierdza tej tabeli**. Cztery konkretne
granice:

1. **Nie dowiedziono unieważnienia wcześniejszej sesji właściciela.** Po banie test
   wykonuje ponownie `actingAs($widz->fresh())`, więc mierzy reakcję aplikacji na
   żądanie zbanowanego użytkownika. Wyniku `302 → /login` **nie wolno przypisywać**
   skasowaniu sesji przez `User::ban()`.
2. **Samo `403` nie dowodzi przyczyny ani momentu wygaśnięcia.** Test nie odczytuje
   czasu wystawienia ani wygaśnięcia z podpisu, nie sprawdza treści błędu magazynu
   i nie kontroluje, czy świeży podpis do tego samego pliku daje `200`. Sformułowania
   „przez cały czas ważności" i „odcina wyłącznie zegar" **nie mają w tym teście oparcia**.
3. **Dostęp moderatora kończy się na `302`.** Test **nie pobiera pliku** nowo wydanym
   adresem i nie porównuje skrótu bajtów. Potwierdzono więc, że moderator dostaje
   przekierowanie — nie, że otrzymuje zawartość.
4. **Nie sprawdzono, czy oba wpisy naprawdę zostały miękko usunięte** — test ufa
   temu, że decyzja się zapisała (`STATUS_RESOLVED`), nie sprawdza skutku na treści.

**Plik CSV z surowym przebiegiem nie istnieje** — zapisywał się do `storage_path()`
runtime'u, który po pomiarze usunięto. Wyniki są więc **zaraportowane, nie odtworzone**.
Commit testu (`62cf279b`) do chwili pisania **nie był na GitHubie**.

**ZMIERZONE 21.09 — ta pozycja przestaje być analizą.** Pełny przebieg na
wygenerowanym neutralnym zdjęciu, kod `cd966aae`, dysk o sterowniku `r2` (MinIO
`127.0.0.1:59310`), ważność podpisu odczytana z konfiguracji: **5 minut**,
`Cache-Control` **`public, max-age=150`** potwierdzony i na przekierowaniu,
i na odpowiedzi magazynu.

| stan | gość (trasa aplikacji) | moderator (trasa) | podpis wydany wcześniej |
|---|---|---|---|
| przed czymkolwiek | **302** → magazyn | **302** | **200** |
| po `Usuń treść` | **302** → magazyn | **302** | **200** |
| **po banie autora** | **404** | **302** → magazyn | **200** |
| po usunięciu drugiej treści | **404** | **302** → magazyn | **200** |
| po 5 min 20 s | — | — | **403** |

**Trzy ustalenia z tego pomiaru, istotne prawnie:**

1. **Podpisany adres wydany przed decyzją działa przez cały czas ważności podpisu.**
   Ani usunięcie treści, ani ban go nie unieważniają — odcina go **wyłącznie zegar**.

   **UWAGA, poprzednia redakcja tego zdania była BŁĘDNA.** Pisała o „gwarantowanym
   oknie co najmniej pięciominutowym". Nieprawda: podpis zachowuje **pozostały**
   czas ważności, liczony **od chwili wystawienia**, a nie od decyzji. Podpis wydany
   cztery minuty przed banem daje około minuty, nie pięciu. Okno zależy więc od tego,
   kiedy kto ostatnio otworzył stronę, i **nie ma jednego wspólnego końca** — moderator,
   który nie jest odcięty (patrz pkt 2), może wydawać kolejne podpisy w nieskończoność.
   Cache brzegowy liczy się osobno i pozostaje niezmierzony.
2. **Moderator nie jest odcięty w żadnym stanie**, także po usunięciu obu treści
   i banie. Wynika to z `DostepDoZdjecia::wlascicielLubModerator()`, który przepuszcza
   **przed** odpytaniem rodziców. Przy żądaniu odcięcia konkretnego pliku ta reguła
   też przepuszcza.
3. **Odcięcie gościa następuje na BANIE, nie na usunięciu treści.** Po `Usuń treść`
   gość dalej dostawał `302` przez drugiego rodzica. To ma znaczenie dla oceny
   „niezwłoczności": sama decyzja o usunięciu treści nie kończy udostępniania.

**Obalone tym pomiarem:** podejrzenie, że `MediaController` wydaje podpis przed
sprawdzeniem uprawnień. `abort_unless($decyzja->dlaWidza, 404)` stoi **przed**
`temporaryUrl()`, a odmowa nie niesie nagłówka `Location`. Kolejność jest poprawna.

**Zastrzeżenie do wiarygodności pomiaru:** zdjęcie o dwóch rodzicach zbudowano
**wprost w bazie**, bo zwykłą ścieżką użytkownika się nie dało
(`PostController::zebranZdjecia()` ma `->whereDoesntHave('posts')`). Scenariusz jest
więc osiągalny po stronie danych, ale nie wykazano trasy, którą otworzyłby go
zwykły użytkownik.

**Co ten pomiar NIE obejmuje — i jedno z tego może go unieważnić:**
- **Czy produkcja używa jeszcze magazynu `r2_legacy` z publicznym, bezterminowym
  URL-em.** Jeżeli tak, powyższa tabela **nie opisuje tej drogi w ogóle.**
  To wymaga odczytu zmiennych produkcji i jest pytaniem do właściciela.
- **Cache brzegowy Cloudflare** — pomiar szedł na MinIO bez CDN, więc nie wiadomo,
  jak długo bajty żyją na brzegu po wygaśnięciu podpisu. Przy `max-age=150` to okno
  realne, a `403` z magazynu go nie zamyka.
- Właściciel zdjęcia na innym, niezbanowanym koncie; cache przeglądarki i proxy.

**Czego nadal nie zmierzono i bez czego ta pozycja nie jest zamknięta:** przejście
całej ścieżki na neutralnym zdjęciu — dwa miejsca użycia, usunięcie, ban, stary
podpis, cache. Dopóki tego nie ma, powyższe jest analizą kodu, nie dowodem.

**Czego NIE zmierzono i czego nie należy stąd wnioskować.** Nikt nie uruchomił
żadnego testu ani aplikacji. Wartości „podpis ważny 5 minut" i „publiczny
`Cache-Control` 150 s" pochodzą z odczytu `config/`, **nie z pomiaru produkcji**.
Nie potwierdzono, czy produkcja używa jeszcze magazynu `r2_legacy` z publicznym
URL-em. `ZdjeciaChronioneNieWyciekajaTest::test_zdjecie_o_dwoch_rodzicach_widac_przez_szerszego`
istnieje i potwierdza **zamiar** reguły — nie uruchomiono go.

**Czego dotyczy** — DSA art. 18 (zawiadamianie organów ścigania), art. 14
(warunki korzystania muszą odpowiadać praktyce).

**Ryzyko dla człowieka.** Moderator **wie, gdzie zadzwonić** — §7.1 playbooka
podaje Dyżurnet.pl, Policję (997 albo 112) i 116 123 przy zagrożeniu
suicydalnym, w siedmiu ponumerowanych krokach. Ryzyko nie polega więc na
niewiedzy. Polega na tym, że **cała ścieżka zewnętrzna jest ręczna i nic jej nie
przypomina**: żaden ekran nie pyta „czy zgłoszono?", nic nie pilnuje terminu
i nic nie zapisuje numeru referencyjnego. Sprawa zgłoszona w nocy, przy jednej
osobie w zespole, nie ma technicznego śladu do rana.

Zaznaczamy to wprost, bo pierwsza wersja tego wpisu mówiła, że wszystko zależy
od tego, czy ktoś będzie wiedział, gdzie zadzwonić. **To było nieprawdziwe** —
sprawdzone przez odczytanie §7.1 w całości.

---

### R-06 · Nie mamy podpisanych żadnych umów powierzenia, a dane już płyną

**Co obiecujemy** — `polityka:81`:
> „**Umów powierzenia przetwarzania danych z tymi dostawcami jeszcze nie mamy
> podpisanych.** Twoje dane przetwarzają dziś na swoich standardowych
> warunkach usługi. Podpiszemy te umowy przed otwarciem rejestracji dla
> wszystkich.”

To zdanie jest prawdziwe i pilnuje go test
`DokumentyPrawneNieKlamiaTest::test_nie_twierdzimy_ze_mamy_umowy_powierzenia`.
**Rozjazd nie jest tu między dokumentem a kodem, tylko między stanem a art. 28
ust. 3 RODO.**

**Kto naprawdę dostaje dane** (lista zweryfikowana wobec kodu,
`docs/legal/COMPLIANCE.md:334–343`):

| odbiorca | co dostaje | dowód w kodzie | gdzie |
|---|---|---|---|
| Railway | aplikacja i baza | poza repozytorium | UE |
| Cloudflare R2 | zdjęcia i warianty | `config/filesystems.php:124–132` | deklarowane UE — patrz **R-07** |
| Cloudflare Turnstile | IP i cechy przeglądarki przy 7 formularzach | `config/kuking.php:938`, `:1045` | USA (DPF + SCC) |
| Cloudflare Web Analytics | adres strony, odnośnik, przeglądarka, czas | `app/Support/AnalitykaCloudflare.php` | USA (DPF + SCC) |
| OpenAI, L.L.C. | treść wpisu i zdjęcie | `app/Moderacja/KlientOpenAI.php` | USA (DPF + SCC) — patrz **R-03** |
| EmailLabs (Vercom S.A.) | adres e-mail i treść listu | `config/mail.php` | Polska — patrz **R-16** |
| Google | przy logowaniu: tożsamość, e-mail, imię | `app/Http/Controllers/SocialController.php` | osobny administrator |
| Meta Platforms Ireland | przy logowaniu | `app/Http/Controllers/SocialController.php` | osobny administrator |

**Czego dotyczy** — RODO art. 28 ust. 3 (umowa powierzenia), art. 26 (Google
i Meta jako osobni administratorzy — czy na pewno osobni, a nie
współadministratorzy), art. 44–49 (podstawa transferu dla trzech podmiotów
w USA).

**Ryzyko dla człowieka.** Dziś jego dane przetwarza osiem podmiotów na
warunkach, których nikt po naszej stronie nie wynegocjował ani nie przeczytał
pod kątem tego serwisu.

---

### R-07 · Polityka mówi, że zdjęcia leżą „w Unii Europejskiej”; w repozytorium nie ma na to dowodu

**Co obiecujemy** — `polityka:51` (tabela dostawców):
> „Cloudflare R2 | Przechowywanie zdjęć | **Unia Europejska**”

oraz `polityka:83`: „staramy się, żeby wszystkie dane pozostawały w UE, i dziś
tak jest w przypadku hostingu, bazy, **zdjęć** i poczty”.

**Co robi kod.** Konfiguracja bucketów: `config/filesystems.php:124–132`
(oryginały), `:179–187` (publiczne), `:250–255` (eksporty), `:290–304`
(kopie). **W kodzie i w konfiguracji nie ma ani jednego ustawienia
jurysdykcji ani location hintu** — `grep` po `jurisdiction`, `weur`, `eeur`
trafia wyłącznie w komentarze, dokumentację i jedną kontrolę runtime:
`app/Console/Commands/BramkaR2.php:873–890` (`endpointNiesieJurysdykcjeUE()`),
która oblewa bramkę, gdy `AWS_ENDPOINT` nie niesie segmentu jurysdykcji; test
`tests/Feature/BramkaR2MowiPrawdeTest.php:401`. Sama wartość `AWS_ENDPOINT`
jest środowiskowa — **czy produkcja używa endpointu `eu`: niezweryfikowane**.

Issue #619 opisuje różnicę, na której to stoi: Cloudflare Location Hint
(`weur`/`eeur`) jest „best effort”, a jurysdykcję `eu` dokumentuje jako
gwarancję — i jurysdykcji istniejącego bucketu nie da się zmienić.

**Czego dotyczy** — RODO art. 13 ust. 1 lit. f i art. 44–49; art. 5 ust. 1
lit. a (rzetelność deklaracji).

**Ryzyko dla człowieka.** Zdanie „Twoje zdjęcia leżą w UE” może być prawdziwe
i prawdopodobnie jest — ale dziś nikt nie umie tego pokazać inaczej niż
zaglądając do panelu Cloudflare.

---

### R-08 · Trzy rodzaje wpisów w dzienniku zostają na stałe — i zewnętrzna ocena to podważyła

**Co obiecujemy** — `polityka:30`:
> „**12 miesięcy** — co noc usuwamy wpisy starsze niż 12 miesięcy. Wyjątek:
> wpisy, które dokumentują złożenie albo cofnięcie żądania usunięcia konta,
> albo że je wykonaliśmy, **zostają na stałe** — są dowodem, że usunięcie się
> odbyło”

**Co robi kod — dokładnie to.** `app/Models/AuditLogEntry.php:66–70`:
```php
public const NIGDY_NIE_KASUJ = [
    'account.data_erased',
    'account.delete_requested',
    'account.delete_cancelled',
];
```
Egzekucja wyjątku: `app/Domain/Compliance/PrzedawnioneWpisyAudytu.php:49`.
Test iterujący po całej stałej: `tests/Feature/RetencjaAudytuTest.php:93`.
Wpisy noszą przy tym `actor_id`, `ip_hash` i `metadata`
(`app/Models/AuditLogEntry.php:72–79`).

**Co mówi ocena zewnętrzna.** `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md:97`:
> „Lista »nigdy nie kasuj«: **nie do obrony w opisanym kształcie**”
> (`:99`) „Nie ma uzasadnienia dla bezterminowego zachowywania wszystkich
> trzech zdarzeń wraz z dowolnymi metadanymi.”

Ocena nie każe skracać retencji, tylko **zastąpić** wyjątki minimalnym
potwierdzeniem obsługi żądania, przechowywanym 36 miesięcy (`:101`). To jest
przedmiot pytania **P-1** w §3.

**Czego dotyczy** — RODO art. 5 ust. 1 lit. e (ograniczenie przechowywania)
vs art. 5 ust. 2 i 24 (rozliczalność).

**Ryzyko dla człowieka.** Osoba, która skasowała konto, zostawia po sobie
bezterminowy wpis z identyfikatorem konta, skrótem adresu IP i dowolną
metadaną — czyli dokładnie to, o czego usunięcie prosiła.

---

### R-09 · „Odpowiemy w ciągu miesiąca” — nic tego nie liczy

**Co obiecujemy** — `polityka:95`:
> „Odpowiadamy na takie wnioski **bez zbędnej zwłoki, najpóźniej w ciągu
> miesiąca**”

**Co robi kod.** Nic tego nie liczy i nic o tym nie przypomina. Jedyny
działający licznik terminu dotyczy odwołań moderacyjnych:
`app/Console/Commands/PilnujTerminowOdwolan.php`. Przy wnioskach o dane
i przy `contact_messages` nie ma pola terminu.

**Czego dotyczy** — RODO art. 12 ust. 3.

**Ryzyko dla człowieka.** Przy jednej osobie obsługującej serwis obietnica
jest realna. Przy większej skali będzie łamana bez niczyjej wiedzy — bo nie
ma czego przekroczyć.

---

### R-10 · Stary formularz prywatności może cofnąć wypisanie z tygodniowego listu

**Co obiecujemy** — `polityka:36`:
> „**Twoja zgoda** (art. 6 ust. 1 lit. a RODO) (…) wycofać ją możesz w każdej
> chwili: **na dole każdego listu jest odnośnik, który wyłącza wysyłkę jednym
> kliknięciem, bez logowania i bez pytania o powód**.”

**Co robi kod.** Formularz prywatności wysyła oba checkboxy naraz i nie
przesyła wersji odczytanego stanu:
`resources/views/pages/settings/privacy.blade.php:4–35`;
`PrivacySettingsController::update()` przekazuje
`$request->boolean('wants_weekly_digest')` bezwarunkowo do
`app/Domain/Zgody/PrzestawZgodeNaDigest.php` (wywołanie:
`app/Http/Controllers/Settings/PrivacySettingsController.php:49–51`). Akcja
porównuje stan bieżący z przesłanym, ale nie zna stanu widzianego przy
otwarciu formularza (`PrzestawZgodeNaDigest.php:77–121`) — więc dla `false →
true` **włącza list z powrotem i zapisuje nowy dowód udzielenia zgody**
(`:84–90`, zapis dowodu `:123–138`). Osobna, działająca droga wypisania:
`app/Http/Controllers/PodsumowanieTygodniaController.php:52`.

**Czego dotyczy** — RODO art. 7 ust. 3 (wycofanie zgody ma być równie łatwe
jak jej udzielenie i ma być skuteczne).

**Ryzyko dla człowieka.** Wypisał się z listu klikając odnośnik w mailu, potem
w otwartej od rana zakładce zmienił coś zupełnie innego — i listy wracają,
a w dzienniku zgód stoi, że sam ich sobie zażyczył. **To jest odtworzenie
przepływu ze źródła, nie zmierzony incydent** (issue #880).

---

### R-11 · Formularz kontaktu zapisuje ścieżkę, która może nieść token resetu hasła

**Co obiecujemy** — `polityka:37` (wiersz o formularzu „Napisz do nas”):
> „adres strony w Kuking, z której piszesz (samo `/przepisy/...`, bez tego,
> czego szukasz, i bez adresów spoza naszego serwisu) (…) **Nie zapisujemy tu
> Twojego adresu IP ani informacji o przeglądarce**”

**Co robi kod.** Zapisuje ścieżkę po usunięciu części po znaku zapytania, ale
**nie usuwa wrażliwych segmentów samej ścieżki**. Trasa resetu hasła to
`/nowe-haslo/{token}` (`routes/web.php:275`). Droga danych opisana w issue
#836: `app/Http/Controllers/NapiszDoNasController.php`
(`sciezkaZFormularzaAlboReferera` → `oczyscSciezke`) → ukryte pole
w `resources/views/pages/napisz-do-nas.blade.php:106` → zapis
w `app/Domain/Contact/Actions/PrzyjmijWiadomosc.php:67` (`'page_path' => $sciezka`)
→ wyświetlenie w panelu `resources/views/pages/admin/wiadomosc.blade.php:68–69`.

**Czego dotyczy** — RODO art. 5 ust. 1 lit. c (minimalizacja) i art. 32
(bezpieczeństwo przetwarzania).

**Ryzyko dla człowieka.** Osoba, której nie działa reset hasła, klika
„Napisz do nas” — i jej token resetu ląduje w bazie zgłoszeń oraz na ekranie
panelu. **Pomiar wykonano na syntetycznym znaczniku, nie na prawdziwym
tokenie**; nie stwierdzono przejęcia konta.

---

### R-12 · Cichy błąd zapisu może ogłosić gotową paczkę, której nie ma

**Co obiecujemy** — ekran ustawień i list „paczka gotowa”; `polityka:88`
(prawo dostępu).

**Co robi kod.** `app/Jobs/GenerateUserExport.php:157` wywołuje
`Storage::disk($disk)->writeStream(...)` i **nie sprawdza zwróconej wartości**
— opiera się wyłącznie na `catch` (`:158`). `writeStream()` przy cichej
odmowie zwraca `false` bez wyjątku, a job i tak ustawia `status => READY`
(`:171–179`) i wysyła powiadomienie. Dotyczy to dysku z `throw=false`
(`config/filesystems.php`, dysk `local`); dyski R2 mają `throw=true` —
**czy produkcja używa R2: niezweryfikowane** (issue #821).

**Czego dotyczy** — RODO art. 12 ust. 3 i art. 15 (realizacja żądania).

**Ryzyko dla człowieka.** Dostaje list „Twoja paczka jest gotowa”, klika
i nic nie pobiera. Jeśli zamawiał ją przed skasowaniem konta, może skasować
konto w przekonaniu, że dane ma u siebie.

---

### R-13 · Dwa przepisy o tym samym tytule mogą trafić na jedną ścieżkę w paczce

**Co obiecujemy** — paczka ma zawierać wszystkie przepisy konta
(`resources/views/pages/settings/data.blade.php:28`).

**Co robi kod.** Nazwa pliku przepisu bierze **6 pierwszych znaków UUID**
(`app/Domain/Users/Exports/ExportFileNames.php:29`), a klucz obiektu paczki —
**8 znaków** (`:45`). Identyfikatory są UUID v7, w którym prefiks jest czasem,
a nie losowym wyróżnikiem. Issue #825 podaje zmierzoną kolizję dwóch
rzeczywistych wywołań: `recipe1=rosol-01a0be.html`, `recipe2=rosol-01a0be.html`.

**Czego dotyczy** — RODO art. 15 ust. 3 (kompletność kopii).

**Ryzyko dla człowieka.** Dwa szkice o tym samym tytule, zapisane blisko
siebie, dostają jedną ścieżkę — spis w paczce nie może pod nią otworzyć
dwóch różnych przepisów. **Dokładny efekt na gotowym ZIP-ie: niezweryfikowany.**

---

### R-14 · Nie ma rejestru czynności przetwarzania (art. 30 RODO)

**Co obiecujemy** — nic; dokumenty widoczne dla ludzi o tym nie mówią.

**Co robi kod / repozytorium.** `docs/legal/COMPLIANCE.md:312`:
> „| P0 | Rejestr czynności przetwarzania (Art. 30 RODO) sporządzony |
> `BRAK:` takiego dokumentu nie ma w repozytorium ani nigdzie indziej,
> o czym wiadomo | **Tak — przegląd** |”

Ta sama analiza uzasadnia, dlaczego zwolnienie z art. 30 ust. 5 tu nie działa
(`docs/legal/COMPLIANCE.md:137`): przetwarzanie danych kont na platformie
społecznościowej nie jest okazjonalne. Do potwierdzenia przez prawnika.

**Czego dotyczy** — RODO art. 30.

**Ryzyko dla człowieka.** Pośrednie: bez rejestru nikt — łącznie z samym
właścicielem — nie ma jednego miejsca, w którym widać wszystkie cele
przetwarzania obok siebie. Materiał wyjściowy do niego istnieje:
tabela celów w `polityka:23–37` i lista odbiorców w
`docs/legal/COMPLIANCE.md:334–343`.

---

### R-15 · Regulamin nie ma daty wejścia w życie, a obiecuje 14 dni wyprzedzenia

**Co obiecujemy** — `regulamin:140`:
> „O istotnych zmianach poinformujemy z wyprzedzeniem (…) co najmniej
> **14 dni** przed ich wejściem w życie.”

**Co robi kod / dokument.** `regulamin:3`:
> „Ten dokument **opisuje stan serwisu na 7 września 2026** i jest
> aktualizowany razem z nim.”

To jest data opisu, nie data wejścia w życie — a 14 dni wyprzedzenia trzeba
liczyć od czegoś. Polityka nosi analogiczne zdanie z datą 11 września
(`polityka:3`).

**Czego dotyczy** — DSA art. 14 ust. 2 (zmiany warunków), przepisy o wzorcach
umownych i prawie konsumenckim.

**Ryzyko dla człowieka.** Nie wie, która wersja go wiąże, ani od kiedy.

---

### R-16 · Dostawca poczty dokłada obrazek liczący otwarcia — do każdego listu, także transakcyjnego

**Co obiecujemy** — `polityka:35`, `:77` i `:79`. Polityka mówi o tym uczciwie
i w trzech miejscach, m.in.:
> „**Tego liczenia otwarć nie da się wyłączyć z naszego kodu** — jest
> ustawieniem konta u dostawcy i wyłączamy je po jego stronie. Do tego czasu
> obrazek jedzie w każdym liście, który do Ciebie wysyłamy.”

**Co robi kod.** Wszystko, co może: śledzenie **odnośników** jest wyłączone
nagłówkiem (`X-TRACKING-OFF`), jest wykrywacz śladów
(`app/Poczta/SladySledzeniaOtwarc.php`), komenda weryfikacyjna
(`app/Console/Commands/SprawdzPiksel.php`, `kuking:sprawdz-piksel`) i test
(`tests/Feature/PikselSledzacyOtwarciaTest.php`). Śledzenia **otwarć** API
dostawcy nie pozwala wyłączyć. Stan wpisany w runbook jako
„NIE WYŁĄCZONE, do zrobienia po stronie właściciela”
(`docs/infra/POCZTA_URUCHOMIENIE.md`, „Krok 6”). Pomiar z 9 września 2026 na
prawdziwym liście wykazał dwa znaczniki: `<img>` 1×1 i zapasowy `background:url()`
(issue #204). **[pomiar cudzy: issue #204, nie powtarzany w tym zestawieniu]**

**Czego dotyczy** — art. 5 ust. 3 dyrektywy 2002/58/WE i PKE (dostęp do
informacji w urządzeniu końcowym), RODO art. 5 ust. 1 lit. c i art. 6.
`docs/legal/COMPLIANCE.md:323` stawia to jako pytanie: czy wystarczy rzetelna
informacja w polityce, czy trzeba wyłączyć po stronie dostawcy.

**Ryzyko dla człowieka.** Przy każdym liście — także „ustaw nowe hasło”
i przy czterech listach moderacyjnych DSA — dostawca rejestruje moment
otwarcia, adres IP i program pocztowy. Dla listu transakcyjnego nie ma to
żadnego zastosowania po naszej stronie.

---

### R-17 · Rejestracja jest otwarta, chociaż start miał czekać na przegląd prawnika

**Co obiecujemy** — issue #8 stawia przegląd prawnika jako warunek publicznego
startu.

**Co robi kod.** `/register` stoi w grupie `guest` bez żadnej bramki:
`routes/web.php:262–264`. Mechanizm zaproszeń (`routes/web.php:374–382`) jest
**drogą równoległą**, nie przełącznikiem zamykającym wejście.

**Czego dotyczy** — nie przepisu, tylko decyzji właściciela.

**Ryzyko dla człowieka.** Ludzie mogą zakładać konta i wgrywać rodzinne
przepisy w stanie, w którym umów powierzenia nie ma (R-06), a dokumenty nie
były sprawdzone.

---

### Pozycje, które sprawdziliśmy i które się ZGADZAJĄ

Wymieniamy je, bo w zestawieniu tego rodzaju brak rozjazdu jest wynikiem,
a nie milczeniem.

| Obietnica | Dowód, że kod robi to samo |
|---|---|
| Sześć okresów retencji z `polityka:29–37` ma realne mechanizmy kasujące | konfiguracja: `config/kuking.php:2575` (36 mies.), `:2337` (12 mies.), `:867` (3 mies.), `:2169` (90 dni), `:2423` (12 mies.), `:447` (30 dni karencji); komendy: `SprzatajSprawyModeracyjne.php`, `SprzatajAudyt.php`, `SprzatajPowiadomienia.php`, `SprzatajSygnaly.php`, `SprzatajWiadomosci.php`, `PurgeExpiredAccountDeletions.php`; harmonogram `routes/console.php:93, 104, 116, 125, 138, 150` |
| Powiadomienie o decyzji moderacyjnej przeżywa 3-miesięczną retencję do końca terminu odwołania (`polityka:31`, `regulamin:121` — 6 miesięcy) | `app/Console/Commands/SprzatajPowiadomienia.php:11–17`, `app/Models/Notification.php:115` |
| „Konto przechodzi w stan tymczasowy na 30 dni” (`polityka:115`) | `config/kuking.php:447`; `app/Console/Commands/PurgeExpiredAccountDeletions.php:69–78` |
| „Wszystkie Twoje zdjęcia są usuwane” niezależnie od wyboru (`regulamin:69`) | `app/Domain/Users/Actions/EraseAccountData.php:128`, `:374–376`, `:530–538` |
| Dwa zakresy usunięcia wybierane przez człowieka (`polityka:118–119`) | `app/Models/User.php:1110` (`DELETE_SCOPE_MINIMUM` domyślnie), `:548`; wykonanie `EraseAccountData.php:136–138`, `:570–583`; kolumna `database/migrations/2026_09_07_500000_add_erased_status_and_delete_scope_to_users.php:100`, CHECK `:139–140` |
| „Teksty zostają, ale bez Twojego podpisu, jako »Użytkownik usunięty«” | `app/Domain/Users/Actions/EraseAccountData.php:275–283`, `:285–304` |
| Paczka mówi o zdjęciach odrzuconych i skasowanych, które nie wejdą nigdy (issue #692 — **domknięte**) | `app/Domain/Users/Exports/ExportPhotoPlan.php:74–91`, `:92–98`; testy `tests/Feature/EksportMowiOZdjeciachKtoreNieWejdaNigdyTest.php:90, 139, 185, 222` |
| Ekran zamawiania paczki nie obiecuje już „wszystkich zdjęć” | `resources/views/pages/settings/data.blade.php:28–35` |
| Polityka wymienia wszystkie **siedem** formularzy za Turnstile | `polityka:63` vs `config/kuking.php:938`, `:1045` |
| Polityka nie naddeklaruje historii edycji wpisów i komentarzy | `polityka:27` mówi „przepisy wraz z ich wcześniejszymi wersjami” |
| Sesje konta są kasowane przy wykonaniu żądania usunięcia | `app/Domain/Users/Actions/EraseAccountData.php:354–358` |
| Dokumenty nie wymieniają narzędzi, których nie używamy (Sentry, PostHog) | `DokumentyPrawneNieKlamiaTest::test_nie_wymieniamy_narzedzi_ktorych_nie_uzywamy`; `docs/legal/COMPLIANCE.md:345–348` |
| Brak banera cookies opiera się na zmierzonym braku ciasteczek w narzędziu statystyk | `tests/Feature/AnalitykaBezCiasteczekTest.php`, `WdrozenieAnalitykiOdwiedzinTest`; uzasadnienie `polityka:103` — patrz jednak **P-4** |

---

## 3. Pytania otwarte — na te odpowiedzieć może tylko prawnik

### P-1 · Rejestr potwierdzeń RODO ma **na stałe** zachowywać `konto_id`. To jest decyzja właściciela z 21 września 2026 i wymaga oceny prawnej, nie inżynierskiej.

**Kontekst.** Zewnętrzna ocena retencji
(`docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md:101`) każe zastąpić trzy
bezterminowe wyjątki z `AuditLogEntry::NIGDY_NIE_KASUJ` (**R-08**)
minimalnym potwierdzeniem obsługi żądania, trzymanym 36 miesięcy. Projekt
takiego rejestru istnieje na gałęzi `naprawa/minimalne-potwierdzenie-rodo`
(`9fc7cfc3`) — sama tabela `potwierdzenia_zadan_rodo` z ograniczeniami
i testami, **nic jeszcze nie zapisuje i nic nie kasuje**. Opis:
`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`.

**Co projektant zaproponował.** Powiązanie z wnioskodawcą opiera się na
**losowym numerze sprawy** wręczanym człowiekowi w chwili przyjęcia żądania.
`konto_id` istnieje tylko dopóki konto istnieje w pełni (30 dni karencji,
cofnięcie żądania), a **z chwilą wykonania żądania znika** — i nie jest to
obietnica w komentarzu, tylko ograniczenie bazy:
```
ADD CONSTRAINT potwierdzenia_zadan_rodo_wykonane_bez_konta_check
CHECK (wynik <> 'wykonane' OR konto_id IS NULL)
```
(`database/migrations/2026_09_21_100000_utworz_potwierdzenia_zadan_rodo.php:254–255`,
na tamtej gałęzi). Wariant „`konto_id` zawsze” został w projekcie **odrzucony**
(`PROJEKT_POTWIERDZENIA_RODO.md` §3.1 pkt A).

**Co postanowił właściciel (21.09.2026).** Rejestr ma zachowywać `konto_id`
**na stałe**, także po wykonaniu żądania usunięcia. Powód: żeby dało się
odpowiedzieć regulatorowi o konkretną osobę bez proszenia jej o numer sprawy.
Projektant nazwał ten koszt wprost w §3.1 pkt A i §3.3:

- rejestr **umie wtedy odpowiedzieć na pytanie „czy ta osoba usunęła konto”**;
- przy zakresie usunięcia `minimum` treści tej osoby **zostają w serwisie pod
  tym samym `user_id`** — wiersz `users` jest anonimizowany, nie kasowany
  (`app/Domain/Users/Actions/EraseAccountData.php:285–304`; potwierdzone
  w komentarzu `app/Models/AuditLogEntry.php:40–42`). Stały `konto_id`
  związałby więc potwierdzenie usunięcia danych osobowych z całym dorobkiem,
  który po koncie w serwisie został;
- projekt uznaje, że nawet **bez** `konto_id` wiersz jest pseudonimizowany,
  nie anonimowy (motyw 26 RODO), bo korelacja po dacie zostaje
  (`PROJEKT_POTWIERDZENIA_RODO.md` §3.3 pkt 5) — i dlatego podlega retencji
  36 miesięcy. Ze stałym `konto_id` pytanie o charakter tych danych staje się
  łatwiejsze, nie trudniejsze.

**Po drugiej stronie stoi konkretny spór, który ten wskaźnik rozstrzyga.**
`User::cancelDeletion()` zeruje `delete_requested_at`
(`app/Models/User.php:1137–1146`), więc poza tym rejestrem nie zostaje ślad,
że ktoś w ogóle prosił o usunięcie i zmienił zdanie
(`docs/decyzje/ADR_RETENCJE.md:242–275`).

**Pytanie do prawnika:**
1. Czy zachowanie `konto_id` na stałe w rejestrze potwierdzeń daje się
   obronić na art. 5 ust. 2 i 24 RODO (rozliczalność) wobec art. 5 ust. 1
   lit. c i e oraz art. 17 ust. 1?
2. Czy zmienia to kwalifikację całego wiersza — pseudonimizacja czy dane
   osobowe wprost — i czy wpływa to na 36-miesięczną retencję?
3. Czy zdolność odpowiedzenia regulatorowi „ta osoba usunęła konto” jest
   uzasadnionym celem, czy jest właśnie tym zbiorem danych, którego ocena
   zewnętrzna każe nie trzymać (`OCENA_RETENCJI_ZEWNETRZNA.md:103`)?
4. Jeśli obrona jest możliwa — co musi znaleźć się w polityce prywatności,
   żeby człowiek wiedział o tym przed złożeniem żądania?

**To nie jest zgłoszenie błędu.** Właściciel podjął tę decyzję świadomie,
znając cenę, którą projektant nazwał. Prosimy o ocenę, nie o naprawę.

---

### P-2 · Czy zakres paczki z danymi wystarcza pod art. 15, skoro resztę można dostać mailem?

Dotyczy **R-01**. Polityka wymienia obie drogi w jednym zdaniu
(`polityka:88`). Pytanie: czy samoobsługowa paczka może deklarować art. 15,
skoro świadomie pomija kategorie danych — czy musi albo je objąć, albo
przestać się na art. 15 powoływać i wskazać drogę do reszty.

Druga strona tego samego pytania jest techniczna, nie prawna: które
z pominiętych kategorii zawierają dane innych osób i muszą być przycięte.
Paczka już dziś tnie cudze komentarze do treści, daty i nazwy.

---

### P-3 · Google i Meta: osobni administratorzy czy współadministratorzy?

Polityka twierdzi, że osobni (`polityka:46`, `polityka:57`,
`docs/legal/COMPLIANCE.md:343`). Od tego zależy, czy potrzebne jest
porozumienie z art. 26 RODO, czy umowa z art. 28.

---

### P-4 · Czy brak banera zgody obroni się w świetle ePrivacy i praktyki UODO?

Rozumowanie jest zmierzone, nie wzięte ze strony dostawcy: w pobranym
`beacon.min.js` nie ma odwołania do `document.cookie`, `localStorage`,
`sessionStorage`, `indexedDB`, `setItem` ani `getItem`
(`docs/legal/COMPLIANCE.md`, akapit po §5.3; testy
`AnalitykaBezCiasteczekTest`). Pytanie nie brzmi „czy baner”, tylko: czy to
rozumowanie wystarczy — **i czy dwa roczne ciasteczka preferencji z R-04
go nie podważają**.

---

### P-5 · Licencja na treści użytkownika

`regulamin:56–71`. Wskazywana w issue #8 jako najbardziej newralgiczny zapis
całego dokumentu; ma być minimalna konieczna, nie „szeroka na wszelki
wypadek”. Projekt klauzuli: `docs/legal/LICENCJA_UGC_PROJEKT.md`.

**Uwaga o zależności:** `resources/views/pages/landing.blade.php:75–76` opiera
na tej klauzuli podpis autorów pod kolażem — komentarz w widoku cytuje §10
projektu wprost („prawo do oznaczenia autorstwa jest prawem osobistym i nie
przenosi go żadna licencja”), a sam podpis powstaje w `:89–95`. Zmiana
klauzuli wymaga sprawdzenia tego miejsca. (Issue #8 podaje tę zależność pod
ścieżką `landing.blade.php:88–96`; plik leży dziś w `resources/views/pages/`.)

---

### P-6 · Ograniczenie odpowiedzialności i procedura odwoławcza

`regulamin:131–136` (odpowiedzialność) i `regulamin:119–125` (odwołanie).
W procedurze odwoławczej jest jeden fakt, o którym prawnik powinien wiedzieć:
serwis prowadzi jedna osoba, więc **odwołanie rozpatruje zwykle autor
pierwszej decyzji** (`regulamin:123`). Regulamin mówi o tym wprost i ogranicza
to zasadą „podtrzymać własną decyzję może najwcześniej po 24 godzinach”.

---

### P-7 · Status krajowej ustawy wdrażającej DSA i rola UKE

`regulamin:125` wskazuje Prezesa UKE jako koordynatora usług cyfrowych.
Stan prawny zmienia się poza repozytorium —
`docs/legal/COMPLIANCE.md:321` trzyma to jako `DO SPRAWDZENIA`.

---

### P-8 · Obowiązki z art. 28 DSA (ochrona małoletnich)

Minimalny wiek 16 lat jest wymuszony oświadczeniem przy rejestracji
(`app/Http/Controllers/Auth/RegisterController.php:132` —
`'age_confirmed' => ['accepted']`), co eliminuje obowiązek zgody rodzica
z art. 8 RODO. Obszar art. 28 DSA i wytycznych Komisji z 2025 r. jest świeży
i zmienny — `docs/legal/COMPLIANCE.md:400`.

---

### P-9 · Czy potrzebna jest DPIA dla modułu moderacji?

Własna analiza uznaje ją za prawdopodobnie niewymaganą, ale zaleca
dobrowolną, uproszczoną — `docs/legal/COMPLIANCE.md:131–135`. Zgłoszenia
moderacyjne zbliżają się do danych o naruszeniach prawa (art. 10 RODO).

---

## 4. Czego NIE sprawdzaliśmy — jawna lista białych plam

Dokument, który udaje kompletny, jest gorszy niż krótki i uczciwy.

**Produkcja — nic z poniższych nie było oglądane:**
- **Wartości zmiennych środowiskowych na produkcji.** Wszystkie okresy
  retencji poza 30-dniową karencją są nadpisywalne przez `env`
  (`config/kuking.php`). Sprawdziliśmy wartości domyślne w repozytorium, nie
  to, co naprawdę działa.
- **Czy harmonogram w ogóle chodzi.** `routes/console.php` definiuje siedem
  zadań; czy `schedule:run` pracuje na produkcji — niezweryfikowane.
- **Jurysdykcja bucketów Cloudflare R2** (**R-07**). W repozytorium nie ma
  ani ustawienia, ani zapisanego wyniku sprawdzenia w panelu.
- **Który dysk jest naprawdę używany dla eksportów** (**R-12**).
- **Ile zdjęć nie ma wariantu `thumb`** (**R-03**). Bez tego nie wiadomo, czy
  ryzyko jest teoretyczne, czy zdarza się codziennie.
- **Retencja kopii zapasowych bazy.** Polityka mówi wprost, że liczby nie ma,
  bo nie została ustalona z dostawcą (`polityka:122`). To jest otwarta luka
  odnotowana także w `docs/legal/COMPLIANCE.md:381`.
- **Czy umowy powierzenia istnieją** (**R-06**). Widać to wyłącznie w panelach
  dostawców i w szafie z umowami.
- **Czy śledzenie otwarć listów zostało wyłączone w panelu EmailLabs**
  (**R-16**).

**Kod i testy:**
- **Nie uruchamialiśmy testów.** Wszystkie odwołania do testów to odczyt ich
  nazw i położenia, nie zielony przebieg.
- **Nie czytaliśmy ciał wszystkich testów.** Nie wiemy, czy którykolwiek
  sprawdza wprost tekst „RODO art. 15 … art. 20” w `dane.json`, ani czy
  istnieje test pokrywający tytuł pytania w eksporcie (**R-02**).
- **Nie zbudowaliśmy żadnej paczki ZIP.** Skutek kolizji nazw (**R-13**)
  i skutek `false` z zapisu (**R-12**) są wnioskami z odczytu kodu.
- **Nie odtwarzaliśmy scenariusza z dwiema zakładkami** (**R-10**) ani drogi
  tokenu przez formularz kontaktu (**R-11**) end-to-end.
- **Nie przeglądaliśmy wszystkich 245 otwartych issues.** Przeszukanie objęło
  hasła: RODO, prywatność, art. 15, retencja, zgoda, polityka, regulamin,
  dane osobowe, eksport — oraz etykiety `obszar: prawo` i `prywatność`.
  75% otwartych issues nie ma żadnej etykiety, więc etykieta nie jest sitem.
- ~~Nie czytaliśmy `docs/legal/MODERATION_PLAYBOOK.md` w całości~~ — **uzupełnione
  21.09 po południu.** §7.1 przeczytana w całości; okazała się kompletną,
  siedmiopunktową procedurą z nazwanymi adresatami. R-05 poprawione w §2.
  Ta biała plama była przyczyną przesadzonej oceny ryzyka. Pierwotny zapis: R-05
  opiera się na cytatach z `COMPLIANCE.md` i z audytu procedur.

**Prawo:**
- **Nie oceniamy zgodności z prawem żadnej z powyższych pozycji** i nie
  rozstrzygamy, czy któraś jest naruszeniem. Kwalifikacje „czego dotyczy
  w RODO” są wskazaniem, gdzie szukać, nie tezą prawną.
- **Nie sprawdzaliśmy stanu prawnego poza repozytorium** — ustawy wdrażającej
  DSA, praktyki UODO ani wytycznych Komisji z 2025 r.

**Cudze pomiary przejęte bez powtórzenia** (oznaczone w tekście): issue #204
(dwa znaczniki śledzące w prawdziwym liście, 9.09.2026), issue #825 (zmierzona
kolizja nazw), issue #836 (pomiar na syntetycznym znaczniku), issue #8
(zależność klauzuli UGC od `landing.blade.php`).

---

## 5. Materiały źródłowe

**Dokumenty widoczne dla ludzi:** `resources/legal/regulamin.md`,
`resources/legal/polityka-prywatnosci.md`, `resources/legal/zasady.md`.

**Analizy wewnętrzne:** `docs/legal/COMPLIANCE.md`,
`docs/legal/AUDYT_ZGODNOSCI_2026_09_19.md`,
`docs/legal/AUDYT_PROCEDUR_2026_09_20.md`,
`docs/legal/MODERATION_PLAYBOOK.md`, `docs/legal/LICENCJA_UGC_PROJEKT.md`,
`docs/decyzje/ADR_RETENCJE.md`, `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md`,
`docs/decyzje/OPERATOR.md`, `docs/decyzje/DSA_POMIAR.md`.

**Praca w toku (nie scalona):** gałąź `naprawa/minimalne-potwierdzenie-rodo`
(`9fc7cfc3`) — `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` i migracja
`2026_09_21_100000_utworz_potwierdzenia_zadan_rodo.php`.

**Issues:** #8 (przegląd prawny), #953, #832, #825, #823, #824, #821, #692
(rodzina eksportu), #912 (zdjęcie do OpenAI), #880 (zgoda na list), #836
(token w kontekście kontaktu), #619 (jurysdykcja R2), #617 (DR zdjęć),
#204 (piksel otwarć).
