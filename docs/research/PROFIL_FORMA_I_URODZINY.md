# Forma zwracania się i urodziny w profilu — research i rekomendacja

> **Status:** research, nie decyzja. Nic tu nie zmienia zasad, dopóki
> właściciel nie rozstrzygnie pytań z §9 i nie powstanie wpis w
> `docs/DECISIONS.md`.
> **Data:** 25 września 2026 · **Stan repo:** `origin/main` @ `9dddf0f01`
> **Pomysł właściciela (25.09):** w profilu (1) ustawienie płci / formy
> gramatycznej, żeby teksty mówiły „Co dziś ugotowałaś?” albo „ugotowałeś”,
> (2) rok albo data urodzenia, żeby wysłać życzenia urodzinowe „i inne”.

## Krótko

1. **Forma zwracania się: tak, ale jako „jak mamy do Ciebie pisać”, a nie
   „płeć”.** Trzy odpowiedzi: „ugotowałaś”, „ugotowałeś”, „nie wybieram”
   (domyślnie). Zmienia się **kilka miejsc po zalogowaniu**, nie cały serwis.
   Najcenniejsze z nich to przycisk **„Ugotowałem” → „Ugotowałam”**, dziś
   męski dla każdej kobiety. Rozmiar M. Najpierw trzeba zmienić trzy
   obowiązujące reguły (§1.4), więc potrzebna jest decyzja właściciela.
2. **Urodziny: tylko dzień i miesiąc, bez roku, opcjonalnie, prywatnie.**
   Na życzenia rok nie jest potrzebny. Do weryfikacji wieku też się nie
   nadaje, bo to deklaracja, tak samo jak dzisiejszy checkbox „16+”. Życzenia
   wysyła **gospodarz, jednym zdaniem na stronie głównej** (bez maila
   i powiadomień). Rozmiar S–M. Zakres przed rozszerzeniem mierzymy.
3. **„I inne”:** rocznica dołączenia do Kukingu — **tak** (zero nowych danych,
   rozmiar S). Imieniny — **V1, po testach**: dla 50+ są ważne, ale imię
   w Kukingu to wolny tekst, a jedno imię ma kilka dat. Przypomnienia dla
   obserwujących („Dziś urodziny Ani”) — **odrzucić w MVP**: to
   upublicznienie danych, pętla powiadomień i jedyny w serwisie mechanizm
   podsuwający „do kogo się odezwać”, czyli zaczątek algorytmu.

---

## 1. Stan w repozytorium

### 1.1 Jak dziś teksty radzą sobie z rodzajem

Obowiązuje reguła **„bez rodzaju w tekstach do czytelnika”**. Pilnuje jej test:

- `docs/brand/COPY_STYLE.md` §2: „Rodzaju wolno użyć, gdy wiemy, o kim
  mówimy. Nie wolno, gdy mówimy DO czytelnika albo w jego imieniu.”
  Poprawka to zawsze **przebudowa zdania**. Nigdy nie zamieniamy formy
  żeńskiej na męską i nigdy nie piszemy „ugotowałeś/aś”.
- `tests/Feature/TekstyNiePrzypisujaPlciTest.php` + `tests/Support/WzorceRodzaju.php`
  skanują widoki, teksty prawne, tłumaczenia i napisy w PHP. Wyjątki są
  nazwane i są tylko trzy frazy: „co dziś ugotowałeś” i „co ugotowałeś”
  (hasło główne) oraz „Ugotowałem” (nazwa przycisku, w cudzysłowie).
- Test powstał z **issue #274**. Właściciel, który nigdy nie podawał płci,
  zobaczył „Przypominaj mi, co **gotowałam**”. Inwentaryzacja znalazła
  kilkanaście takich miejsc (profil, paczka RODO, bezpieczeństwo konta,
  komentarze, regulamin).
- Powiadomienia o cudzej aktywności też są już bezrodzajowe (issue #38,
  `resources/views/pages/notifications.blade.php`): „Halina — ugotowane
  z Twojego przepisu”, „zaczyna Cię obserwować”. Z tego samego powodu
  zniknęło najdłuższe słowo w serwisie („ugotowała/ugotował”), które przy
  320 px i skali tekstu 150% łamało się w środku.
- Powitanie: „Witaj/Dzień dobry, {imię}. Co dziś gotujesz?” (D-206/D-207).
  Wprost: „o strefę czasową nie pytamy tak samo, jak nie pytamy o płeć”
  oraz „nie wprowadzamy rozpoznawania […] płci ani automatycznej odmiany
  nazwy”.

**Co zostało rodzajowe** (skan widocznych tekstów, bez komentarzy):

| Fraza | Wystąpienia | Gdzie | Ocena |
|---|---:|---|---|
| „ugotowałeś” (hasło) | 21 | stopki 12 maili, landing, rejestracja, `<title>`, puste stany (`home`, `discover`, `notifications`), koniec onboardingu | Przed zalogowaniem nie znamy czytelnika. Po zalogowaniu **znamy** i tam wybór formy ma sens |
| „Ugotowałem” (przycisk / nazwa funkcji) | 26 | przycisk pod przepisem, licznik profilu, formularz wykonania, pomoc | **Największy realny problem.** Kobieta 60+, czyli główna persona (Basia, 61), klika „Ugotowałem” o własnym obiedzie |
| `w_czym_jestem_dobra` | 1 | klucz JSON w eksporcie RODO (`app/Domain/Users/Exports/CollectUserExportData.php:192`) | **Usterka** z tej samej rodziny co #274 (pierwsza osoba, rodzaj żeński). Test jej nie łapie, bo skanuje teksty, a nie klucze. Do poprawy niezależnie od tej decyzji |

Wniosek: pomysł właściciela **nie jest „dodaniem żeńskich form”**, tylko
**odwróceniem obecnej strategii** („unikaj rodzaju”) na „znaj formę i jej
użyj” — na razie w ograniczonym zakresie.

### 1.2 Czy jakaś decyzja już to rozstrzyga

| Dokument | Co mówi | Wpływ |
|---|---|---|
| `docs/SECURITY_PRIVACY_LEGAL.md` „Data minimization” | Nie zbierać bez potrzeby: telefonu, adresu, GPS, **płci**, **pełnej daty urodzenia**, danych zdrowotnych | **Nie wyklucza**, ale wymusza, żeby (a) pytać o **formę**, a nie o płeć, i żeby cel był nazwany, (b) nie brać pełnej daty. Dzień + miesiąc to nie „pełna data” |
| `docs/legal/COMPLIANCE.md` §4 | Wiek min. **16 lat**, samo oświadczenie. „Data urodzenia zamiast checkboxa **nie jest rekomendowana** […] świadomie nie zbiera pełnej daty urodzenia” | Rok urodzenia **nie** służy weryfikacji wieku. Potwierdza, że roku nie bierzemy |
| `tests/Feature/LogowanieKontemFacebookiemTest.php:316` | Zakres Facebooka pilnowany jako `public_profile,email`, jawnie **bez** `user_birthday` i `user_gender` | Świadoma minimalizacja. Nie importujemy tych danych z Facebooka ani z Google, nawet jeśli pole dojdzie |
| `docs/brand/COPY_STYLE.md` §2, `GLOS_MARKI.md`, D-009, D-145 | „Formy żeńskiej **nie tworzymy**” | Dotyczy **rzeczownika „kuKING”** (kuKINGini, kuKINGówka). Czasowników nie dotyczy. Trzeba to w decyzji rozdzielić, bo w dokumentach bywa zlewane |
| `docs/brand/BRAND_EXTENDED.md` §2.4 | Hasło „ugotowałeś” zostaje. Istnieje „wariant równoległy […] gdzie odbiorcą jest wprost kobieta: «Pokaż, co dziś ugotowałaś»” | **Już dopuszcza** formę żeńską, gdy odbiorca jest znany. Pomysł właściciela to rozszerzenie tej linii |
| D-206 / D-207 | Nie pytamy o płeć ani strefę, bez automatycznej odmiany | Zakazuje **zgadywania**, np. z imienia („Anna” → kobieta) albo z nazwy konta. Deklaracja zgadywaniem nie jest, ale D-206 trzeba jawnie uzupełnić |
| `AGENTS.md` §1 | Grupa 50+, ale produkt **nie jest oznaczany „dla seniorów”** | Rok urodzenia kusiłby segmentacją („sekcja 60+”, statystyki wieku). Kolejny argument, żeby go nie brać |
| `AGENTS.md` §8, §12 | Feed chronologiczny, bez algorytmu, bez rankingów i streaków | „Dziś urodziny Ani” w feedzie obserwujących = wstawka spoza chronologii. Patrz §5 |
| `docs/FEATURES.md`, `docs/ROADMAP.md` | Ani forma, ani urodziny nie występują w MVP, V1 ani V2 | Nowy zakres. Web Push jest w **V1** |

**Otwarte issues:** wyszukiwanie w `woogitsu/kuking.pl` („płeć”, „forma
gramatyczna”, „urodziny”, „data urodzenia”) — **brak**. Nic nie dubluję.

### 1.3 Profil, powiadomienia, gospodarz — na czym można budować

- **`profiles`** (`docs/DATABASE.md`): `username`, `display_name` („Jak mamy
  Cię nazywać?”, wolny tekst, D-153 zabrania go odmieniać), `bio`, `region`
  (wolny tekst, „nie adres”), `speciality`, `avatar_media_id`. Ustawienia
  wygody siedzą w `users`: `text_scale`, `theme`, `memories_enabled`,
  `wants_weekly_digest`.
- **Ekrany:** `resources/views/pages/settings/` — `profile`, `privacy`,
  `accessibility`, `data`… „Forma zwracania się” pasuje do **profilu** (to
  o mnie) albo do **wyglądu i czytelności** (to preferencja interfejsu).
  Rekomenduję profil, patrz §4.
- **Powiadomienia w serwisie:** tabela `notifications`, retencja 3 miesiące,
  zamknięta lista typów w `App\Models\Notification`.
- **Powiadomienia poza serwisem:** `TerminPowiadomieniaZewnetrznego`: limit
  **1 na dobę** (`kuking.notifications.zewnetrzne.dzienny_limit`), **cisza
  nocna 21:00–8:00**, odkładanie zamiast kasowania. Dziś **wyłączone**,
  flaga `KUKING_POWIADOMIENIA_ZEWNETRZNE=false`. Web Push dopiero w V1.
- **Tygodniowe podsumowanie od gospodarza** (D-057): opt-in, codziennie
  08:30, dobowy sufit 60 listów, trzy sekcje chronologiczne + jedno pytanie
  od gospodarza, podpis „Ula”. Świadomie **bez** rankingów i bez „osób,
  które warto poznać”.
- **Wspomnienia „Rok temu…”** (issue #34): gotowy wzorzec „daty
  wracającej co rok”, z wyłącznikiem `users.memories_enabled` (bo żałoba)
  i poprawnym liczeniem rocznicy w strefie Europe/Warsaw. **Życzenia
  urodzinowe powinny skopiować ten wzorzec 1:1**: wyłącznik, strefa, blok
  na `/home`, zero powiadomień.
- **Eksport RODO:** `CollectUserExportData` składa profil z nazwanych
  kluczy. Nowe pola trzeba tam dopisać (art. 15/20), a anonimizacja
  z `data_erased_at` musi je zerować (art. 17).

### 1.4 Co trzeba by zmienić w zasadach, jeśli właściciel powie „tak”

1. `COPY_STYLE.md` §2 i `BRAND_EXTENDED.md` §2.4: nowa granica. „Do
   czytelnika **bez znanej formy**: bez rodzaju (jak dziś). Do czytelnika,
   **który wybrał formę**: w jego formie, tylko przez helper.” Zakaz
   ukośników i zakaz zgadywania zostają.
2. `WzorceRodzaju` / `TekstyNiePrzypisujaPlciTest`: dopuścić rodzaj
   **wyłącznie** w wywołaniach helpera (np. `forma('ugotowałaś', 'ugotowałeś',
   'gotujesz')`). Gołe „ugotowałaś” w widoku dalej oblewa. Dodać test, że
   przy formie „nie wybieram” żaden ekran nie pokazuje rodzaju.
3. `SECURITY_PRIVACY_LEGAL.md`: dopisek „forma zwracania się ≠ płeć; cel:
   brzmienie interfejsu; nie pokazujemy innym; nie używamy w statystykach”.
   Przy urodzinach: „dzień i miesiąc, bez roku”.
4. D-206: uzupełnienie „nie **zgadujemy** płci; forma wybrana przez
   człowieka jest dozwolona”.
5. Nazwa funkcji „Ugotowałem” (reguła „jedna nazwa, bez synonimów”,
   `BRAND_EXTENDED.md` §3, D-sprawa cudzysłowu). Patrz pytanie P2.

---

## 2. Jak robią to inni

### 2.1 Forma gramatyczna / zwracania się

| Kto | Jak | Wniosek dla Kuking |
|---|---|---|
| **Android 14+** — Grammatical Inflection API | Aplikacja pyta **w swoich ustawieniach albo przy pierwszym uruchomieniu** i ustawia `feminine` / `masculine` / `neuter`. Gdy wartości nie ma, pokazuje **tekst domyślny**. Ustawienie jest per aplikacja ([Android Developers](https://developer.android.com/about/versions/14/features/grammatical-inflection), [API](https://developer.android.com/reference/android/app/GrammaticalInflectionManager)) | Wzorzec 1:1: trzy wartości, domyślnie „brak” → forma neutralna. Google nazywa to formą **zwracania się do oglądającego**, nie płcią |
| **Apple iOS 17+** — „Form of Address” | Ustawienia → Język i region: forma żeńska / męska / neutralna, opcjonalnie „udostępnij wszystkim aplikacjom”. Na razie dla części języków, np. hiszpańskiego ([Apple Support](https://support.apple.com/guide/iphone/change-the-language-and-region-iphce20717a3/ios)) | Neutralna jest **równorzędną** opcją, nie brakiem odpowiedzi. Polskiego jeszcze nie ma, więc nie możemy się opierać na systemie |
| **Facebook** | Płeć: kobieta / mężczyzna / niestandardowa + **„zaimek”** (on / ona / neutralny), osobna widoczność pola ([Pocket-lint](https://www.pocket-lint.com/apps/news/facebook/127250-facebook-s-new-custom-gender-option-here-s-how-to-choose-your-preferred-gender-and-pronoun/), [Technipages](https://www.technipages.com/how-to-change-gender-on-facebook/)) | Rozdział „płeć” vs „jak o Tobie piszemy” jest ugruntowany. My bierzemy tylko drugie |
| **Banki PL** (mBank, PKO, Pekao…) | Znają płeć z PESEL i KYC, bo mają obowiązek prawny. Forma wynika z danych, które i tak muszą mieć | **Nieporównywalne**: my PESEL-u nie mamy i mieć nie chcemy |
| **Garnek.pl** | Serwis zamknięty 25.11.2024 ([Archiveteam](https://wiki.archiveteam.org/index.php/Garnek.pl)) | Brak wzorca do sprawdzenia. Kontekst migracji: `docs/research/MIGRACJA_Z_GARNKA.md` |
| **Cookpad** | Edycja profilu: nazwa, lokalizacja, bio, zdjęcie. Ani płci, ani urodzin ([Cookpad FAQ](https://cookpad.com/us/faq)) | Największy serwis „ludzi, którzy gotują” obywa się bez obu pól |
| Allegro, LinkedIn PL, Duolingo PL, Przepisy.pl, Kwestia Smaku | Nie znalazłem publicznej dokumentacji ustawienia formy gramatycznej. Z obserwacji interfejsów: przewaga form bezosobowych i męskich domyślnych. **Niezweryfikowane źródłem**, nie traktować jako faktu | Warto sprawdzić ręcznie na koncie testowym, jeśli ma to przesądzić |

### 2.2 Urodziny, imieniny, rocznice

| Kto | Jak | Wniosek |
|---|---|---|
| **Facebook** | Data urodzenia z **osobną widocznością dnia+miesiąca i roku**. Przypomnienia znajomym idą tylko, gdy dzień+miesiąc są widoczne dla znajomych ([Antyweb](https://antyweb.pl/jak-wylaczyc-urodziny-na-facebooku), [Internet Standard](https://www.internetstandard.pl/jak-wylaczyc-urodziny-na-fb-ustawienia-prywatnosci-w-praktyce/)) | Liczne poradniki „jak wyłączyć urodziny na FB” to sygnał, że domyślne przypominanie znajomym **męczy**. U nas domyślnie wyłączone |
| **LinkedIn** | Urodziny = sam **miesiąc i dzień**, rok nie jest wyświetlany. Widoczność: tylko Ty / kontakty / sieć / wszyscy ([LinkedIn Help](https://www.linkedin.com/help/linkedin/answer/a550097/adjusting-your-birthday-privacy-settings?lang=en)) | Duża platforma działa bez roku. Model dla nas |
| **Nasza-klasa** (historycznie, reaktywacja 2025) | Znana z odnajdywania znajomych ze szkoły. Publicznej dokumentacji funkcji imienin nie znalazłem ([dobreprogramy](https://www.dobreprogramy.pl/zegnaj-nasza-klaso-nie-bedziemy-po-tobie-plakali-ale-bylas-wazna-opinia,6644202810538848a), [App Store](https://apps.apple.com/pl/app/naszaklasa/id6745783950)) | Brak twardego wzorca |
| **Imieniny w PL** | W średnim i starszym pokoleniu obchodzone często uroczyściej niż urodziny, młodsi przechodzą na urodziny. Twardego sondażu CBOS nie znalazłem ([TVP „Pytanie na śniadanie”](https://pytanienasniadanie.tvp.pl/42904405/imieniny-czy-urodziny-co-czesciej-swietuja-polacy), [poland.us](https://poland.us/imieniny-polska-tradycja-dlaczego-obchodzimy/), [Centrum Prasowe](https://centrumpr.pl/artykul/zyczenia-imieninowe-jak-polacy-obchodza-imieniny,177394.html)) | Dla 50+ imieniny mogą być **ważniejsze niż urodziny** i nie wymagają żadnej daty. Ale jedno imię ma kilka dat w roku (Anna, Maria, Jan…), więc człowiek musiałby wskazać **swój** dzień |
| Rocznice dołączenia (LinkedIn „work anniversary”, Facebook „Wspomnienia”, Kuking „Rok temu…”) | Automatyczne, z daty założenia konta | Zero nowych danych. U nas wzorzec już jest (Wspomnienia) |

---

## 3. RODO i prawo

### 3.1 Forma zwracania się

- **Nie jest to kategoria szczególna** (art. 9 RODO), bo płeć nią nie jest.
  Ale forma pozwala **wywnioskować** płeć, więc minimalizacja (art. 5 ust. 1
  lit. c) każe pytać o **najwęższą rzecz, która spełnia cel**: „jak mamy do
  Ciebie pisać”, a nie „jakiej jesteś płci”. Trzecia opcja („nie wybieram”)
  jest domyślna i w pełni równorzędna, bez zachęt do wyboru (DSA art. 25,
  zakaz dark patterns).
- **Podstawa:** art. 6 ust. 1 lit. b (świadczenie usługi zgodnie z wyborem
  użytkownika), tak jak `text_scale` i `theme`. Zgoda nie jest potrzebna, bo
  pole jest dobrowolne i służy wyłącznie brzmieniu interfejsu. Rozważyć
  lit. a tylko wtedy, gdyby forma miała być widoczna dla innych (§4.3).
- **Czego nie robić:** nie pokazywać na profilu publicznym, nie używać
  w analityce ani w segmentacji digestu, nie pobierać z Google/Facebooka,
  nie zgadywać z imienia.
- **Polityka prywatności:** nowy wiersz albo dopisek do „Twój publiczny
  profil”: „forma, w jakiej do Ciebie piszemy (jeśli ją wybierzesz) —
  wykonanie umowy — do zmiany lub usunięcia konta; nie jest widoczna dla
  innych”.
- **Eksport:** klucz `forma_zwracania_sie` (`zenska` / `meska` / `brak`).
  **Usunięcie konta:** zerowanie razem z polami opisowymi profilu.

### 3.2 Urodziny

- **Pełna data jest wprost na liście „nie zbierać”** (`SECURITY_PRIVACY_LEGAL.md`),
  a do życzeń rok **nie jest potrzebny**. Rok bez celu = naruszenie
  minimalizacji. Dzień+miesiąc bez roku zwykle nie identyfikuje osoby, ale
  razem z imieniem i regionem to już quasi-identyfikator, np. pytanie
  pomocnicze w banku. **Nie pokazujemy innym.**
- **Podstawa:** dobrowolne pole + cel „życzenia od serwisu” → art. 6 ust. 1
  lit. b albo **zgoda** (lit. a). Rekomenduję **zgodę wyrażoną samym
  wypełnieniem opcjonalnego pola z jasnym opisem celu**, z możliwością
  usunięcia jednym kliknięciem. Polska praktyka przy „rabatach
  urodzinowych” jest taka: pole musi być **opcjonalne**, a wymaganie daty
  urodzenia do celów, które jej nie potrzebują, uznaje się za nadmiarowe
  ([PARP](https://www.parp.gov.pl/component/content/article/88972:zasada-minimalizacji-danych-osobowych-kiedy-mniej-znaczy-wiecej),
  [poradyodo.pl](https://www.poradyodo.pl/dane-osobowe-a-marketing/przetwarzanie-daty-urodzin-klienta-do-celow-marketingowych-czy-jest-potrzebna-jego-zgoda-8355.html),
  [Bieluk i Partnerzy](https://bieluk.pl/blog/2025/12/08/zasada-minimalizacji-danych/)).
- **Czy życzenia to marketing?** Jedno zdanie od gospodarza **w serwisie**
  nie jest informacją handlową. **Mail z życzeniami** byłby już bliżej
  komunikacji marketingowej (art. 398 PKE: zgoda na komunikację
  elektroniczną). Dlatego w MVP **bez maila** (§5).
- **Dzieci < 16 lat.** Rok urodzenia **nie jest** narzędziem weryfikacji
  wieku. Deklarację da się wpisać dowolnie, tak samo jak checkbox. UODO:
  weryfikacja wieku „nie może oznaczać masowej identyfikacji […] ani
  zbierania nadmiarowych danych” ([prawo.pl](https://www.prawo.pl/prawo/weryfikacja-wieku-w-internecie-unijny-portfel-tozsamosci-cyfrowej-albo-mobywatel,1545721.html)).
  `COMPLIANCE.md` §4 już to rozstrzygnęło: oświadczenie 16+ i reakcja przy
  sygnale. **Skutek uboczny roku:** gdyby ktoś wpisał rok dający < 16 lat,
  mielibyśmy **wiedzę** o małoletnim, a z nią obowiązek zamknięcia konta
  (`polityka-prywatnosci.md`: „Jeśli dowiemy się…”). Kolejny powód, żeby
  roku nie brać.
- **Retencja:** do usunięcia przez użytkownika albo do usunięcia konta.
  Wpis w `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` (nowa kategoria
  danych + cel).
- **Eksport:** `urodziny: "12-03"` (DD-MM), bez roku. **Usunięcie:** zerowanie.

### 3.3 Imieniny

Jeśli wejdą w V1: człowiek wybiera **swój dzień imienin** z kalendarza
(dzień+miesiąc), a nie imię z listy. Imienia nie zgadujemy z `display_name`
(D-153/D-206). Prawnie to ten sam reżim co urodziny bez roku.

---

## 4. UX 50+

### 4.1 Zasady

- **Opcjonalne, domyślnie „nie wybieram”** = dzisiejsze brzmienie
  bezrodzajowe. Nikt po wdrożeniu nie zobaczy zmiany, dopóki sam nie wybierze.
- **Bez ukośników i bez wymuszania.** Brak wyboru nie jest błędem
  i nie wywołuje przypomnień („Uzupełnij profil!” = nagabywanie).
- **Etykiety pokazują skutek, a nie kategorię.** Zamiast „Płeć: K/M/inna”:

  ```text
  Jak mamy do Ciebie pisać?
  ( ) „Co dziś ugotowałaś?”
  ( ) „Co dziś ugotowałeś?”
  (•) „Co dziś gotujesz?” — bez formy żeńskiej ani męskiej
  Widzisz to tylko Ty. Możesz zmienić w każdej chwili.
  ```

  Radio z dużym polem (≥ 48 px), tekst ≥ 18 px, bez ikon, zapis zwykłym
  formularzem (działa bez JS — ustawienia nie są newralgicznym formularzem
  w rozumieniu D-053).
- **Neutralna forma musi brzmieć naturalnie.** „Co dziś ugotowano?” to
  bezosobowe „-no”, które dla 50+ brzmi urzędowo, więc odradzam. „Co dziś
  było na obiad?” jest ciepłe, ale zawęża do obiadu. Rekomenduję sprawdzone
  już **„Co dziś gotujesz?”** (COPY_STYLE §2, powitanie D-206) i imiesłowy
  („ugotowane z Twojego przepisu”).

### 4.2 Gdzie zapytać

| Miejsce | Za | Przeciw | Rekomendacja |
|---|---|---|---|
| Ustawienia → Profil | Zero tarcia przy rejestracji, łatwo znaleźć i zmienić | Większość nie wejdzie | **Tak, od razu** |
| Onboarding, krok profilu, pole opcjonalne | Wybierze więcej osób | Dodatkowe pole w 3-krokowym kreatorze, a „ponad połowa 50+ nic nie publikuje” (D-145), więc każde tarcie kosztuje | **Dopiero po testach z ludźmi (#15)**. Jeśli już, to jako jedno pytanie z domyślnym „nie wybieram” |
| Jednorazowa podpowiedź przy pierwszym „Ugotowałem” | Moment, w którym forma boli najbardziej | Wyskakujące okno = wzorzec, którego nie lubimy | Nie w MVP |

### 4.3 Ryzyka

- **Nietrafiona forma** (literówka w wyborze, konto współdzielone przez
  małżeństwo — realne w 50+). Łagodzi to: wybór widać w ustawieniach
  dosłownie („Piszemy do Ciebie: «ugotowałaś»”), zmiana jednym kliknięciem.
- **Trzecia osoba o innych** („Halina **ugotowała** Twój przepis”) to
  pokusa, bo powiadomienia brzmiałyby cieplej. Ale wtedy forma staje się
  **widoczna dla innych**, a to zmienia charakter pola (upublicznienie,
  ewentualna zgoda, pytanie o osoby niebinarne). **Rekomenduję: tylko druga
  osoba, do samego siebie.** Trzecia osoba ewentualnie później, z osobnym
  ostrzeżeniem „Tak będziemy o Tobie pisać też innym”.
- **Przycisk „Ugotowałem”** jest nazwą funkcji na cudzym przepisie. Przy
  formie żeńskiej przycisk **u niej** brzmiałby „Ugotowałam”. Licznik na
  cudzym profilu („4 razy „Ugotowałem””) zostaje nazwą funkcji. Wymaga
  decyzji (P2), bo łamie regułę „jedna nazwa”. Alternatywa bez zmiany
  nazwy: nic nie robić z przyciskiem i wtedy właściwie połowa sensu całej
  zmiany znika.
- **Rozjazd tekstów:** każde nowe zdanie z rodzajem wymaga trzech wersji.
  Bez helpera i testu wróci stan sprzed #274.

### 4.4 i18n i koszt

- Repo **nie używa** `__()` / `trans()` w widokach (0 wywołań w
  `resources/views`; `lang/pl/*` to tylko pliki Laravela). Teksty stoją
  w Blade. Przejście na ICU/`trans` z wariantami dla całego serwisu to
  duża praca (183 widoki), której ten pomysł **nie uzasadnia**.
- **Tańsza droga:** jeden helper Blade/PHP, np.
  `@forma('ugotowałaś', 'ugotowałeś', 'gotujesz')` albo
  `$user->forma()->wybierz(z: …, m: …, n: …)`, z **obowiązkową** wersją
  neutralną. Test: każde wywołanie ma trzy argumenty, a przy `brak`
  wyrenderowany ekran przechodzi dzisiejszy skan rodzaju. Gdyby kiedyś
  przyszło ICU (`{forma, select, zenska{…} meska{…} other{…}}`,
  [ICU SelectFormat](https://unicode-org.github.io/icu-docs/apidoc/dev/icu4j/com/ibm/icu/text/SelectFormat.html)),
  helper przepina się w jednym miejscu.
- **Zakres zmiany tekstów (propozycja, ~10 miejsc):** główne pole/CTA po
  zalogowaniu i pusty stan feedu, pusty stan powiadomień, koniec
  onboardingu, przycisk „Ugotowałem” i formularz wykonania (jeśli P2 = tak),
  stopki maili **do zalogowanego adresata** (digest, eksport, zmiana
  adresu). Landing, rejestracja, `<title>` i maile przed założeniem konta
  **zostają** przy haśle „ugotowałeś”, bo tam czytelnika nie znamy.

---

## 5. „I inne” — ocena pomysłów

| Pomysł | Wartość dla 50+ | Prywatność | Spam / algorytm | Werdykt |
|---|---|---|---|---|
| **Rocznica na Kukingu** („Gotujesz z nami od roku”, wzór z COPY_STYLE §2) — blok na `/home` u samego zainteresowanego | Średnia–wysoka, podkreśla przynależność bez rankingu | **Zero nowych danych** (`users.created_at`) | Brak, jeden blok raz w roku, bez powiadomienia | **MVP, S** |
| **Urodziny: życzenia od gospodarza** — jedno zdanie na `/home` w dniu urodzin („Wszystkiego dobrego, {imię}! Ula i cały Kuking”), wyłącznik jak przy Wspomnieniach | Wysoka, ciepło, „ktoś pamięta” | Dzień+miesiąc, prywatnie, opcjonalnie | Brak powiadomienia, brak maila, raz w roku | **MVP / wczesne V1, S–M** |
| Urodziny: **mail** od gospodarza | Wyższa (dociera bez logowania) | Jak wyżej + bliżej marketingu (PKE) | +1 mail rocznie; musi wejść w sufit 60/dobę i kolejkę | **V1**, osobna zgoda albo checkbox przy polu |
| Urodziny: **wzmianka w tygodniowym podsumowaniu** adresata | Średnia | Jak wyżej | Brak nowego kanału | V1, razem z mailem |
| **Imieniny**: własny dzień z kalendarza, życzenia od gospodarza | **Wysoka** w 50+ | Dzień+miesiąc | Jak urodziny | **V1, po testach (#15)**: zapytać ludzi, czy chcą urodzin, imienin, czy obu |
| **Przypomnienie obserwującym** („Dziś urodziny Ani — złóż życzenia”) | Wysoka na FB, ale to dokładnie ta funkcja, którą ludzie masowo wyłączają | **Upublicznienie** daty obserwującym (a obserwować może każdy, bez akceptacji) | Pętla powiadomień, presja („nie złożyłam, wypada”). Wstawka spoza chronologii feedu (§8) i pierwszy mechanizm „do kogo się odezwać” | **Odrzucić w MVP.** Wrócić najwyżej w V1+ jako opt-in właściciela urodzin, tylko dla **wzajemnie obserwujących**, bez powiadomienia (np. drobna notka w podsumowaniu tygodnia) |
| **Życzenia od obserwowanych jako komentarze pod automatycznym wpisem** („Ania ma dziś urodziny”) | — | Upublicznienie | Automatyczne wpisy w feedzie = sztuczna treść (§12: „sztuczne konta” w duchu) | **Odrzucić** |
| **Rok urodzenia / wiek** (np. „60+ ugotowało…”, statystyki) | — | Pełna data na liście „nie zbierać” | Segmentacja wbrew „nie oznaczamy jako dla seniorów” | **Odrzucić** |
| **Święta ogólne** (Dzień Babci 21.01, Dzień Matki, Wigilia) | Wysoka | Zero danych | Już obsługuje to tag tygodnia i tablica (`TAG_TYGODNIA.md`, `SOUL.md`) | Bez nowej funkcji, redakcja gospodarza |

**Zgodność z zasadami.** Wszystko z „Tak” działa jako **blok u samego
zainteresowanego** na `/home` (jak Wspomnienia). Nie wchodzi do feedu
obserwowanych, nie tworzy powiadomień, nie sortuje ludzi. Limit powiadomień
zewnętrznych (1/dobę, cisza 21–8) nie jest dotknięty. Gdyby w V1 doszedł
mail, idzie przez istniejący sufit dobowy digestu i rano (08:30), nigdy
w ciszy nocnej. **Zasada żałoby:** wyłącznik życzeń musi istnieć od dnia
pierwszego i być w tym samym miejscu co pole. Urodziny po śmierci bliskiej
osoby bywają trudne, a konta bywają prowadzone przez rodzinę.

---

## 6. Rekomendacja

| Co | Kiedy | Rozmiar | Uwagi |
|---|---|---|---|
| Poprawić klucz eksportu `w_czym_jestem_dobra` → bezrodzajowy (np. `w_czym_sie_znam`) + test | **Teraz**, niezależnie | S | Regresja #274. Zmiana kontraktu eksportu, więc odnotować w CHANGELOG |
| **Forma zwracania się** (3 opcje, domyślnie brak, tylko 2. osoba, ~10 miejsc, helper + test) | **MVP po decyzji właściciela** (P1–P3) | **M** | Migracja `users.forma_zwracania_sie` (lub `profiles`), CHECK na 3 wartości, rollback odmawiający przy niedomyślnych wartościach (D-088), DATABASE.md, eksport, anonimizacja, polityka prywatności, COPY_STYLE, WzorceRodzaju |
| Rocznica dołączenia na `/home` | MVP | S | Bez migracji. Tekst z COPY_STYLE („Gotujesz z nami od…”). Wyłącznik wspólny ze Wspomnieniami |
| **Urodziny (dzień+miesiąc)** + zdanie od gospodarza na `/home` + wyłącznik | MVP albo wczesne V1 | S–M | Migracja dwóch kolumn `smallint` z CHECK (albo jednej `char(5)`), walidacja 29.02 (życzenia 28.02 w latach nieprzestępnych), strefa Europe/Warsaw, polityka, rejestr czynności, eksport |
| Mail urodzinowy / wzmianka w digeście | V1 | S | Po sprawdzeniu, czy blok na `/home` ktoś w ogóle zauważa |
| Imieniny | V1, po testach #15 | S–M | Wybór dnia z kalendarza, nie imienia |
| Forma w 3. osobie (jak inni o mnie czytają) | V2 / może nigdy | M | Zmienia charakter danych na widoczne dla innych |
| Przypomnienia obserwującym, automatyczne wpisy urodzinowe, rok urodzenia, pole „płeć” | **Odrzucić** | — | Minimalizacja, spam, algorytm, „nie dla seniorów” |

Priorytety: poprawka eksportu **P2** (usterka, niska szkodliwość). Forma
zwracania się **P2**, nie P0/P1: to jakość, nie blokada. Urodziny
i rocznica **P2**.

---

## 7. Proponowane issues (do założenia po decyzji — NIE założone)

### I-1 · Eksport RODO: klucz `w_czym_jestem_dobra` przypisuje czytelnikowi rodzaj (regresja #274)
- Klucz w `CollectUserExportData` bezrodzajowy (np. `w_czym_sie_znam`).
- Test, że żaden klucz eksportu nie zawiera form 1./2. osoby z rodzajem
  (wzorce z `WzorceRodzaju`).
- Wpis w CHANGELOG (zmiana formatu eksportu). Opis w `DATABASE.md`/dokumentacji
  eksportu zaktualizowany.

### I-2 · Decyzja: forma zwracania się zamiast „nie pytamy o płeć”
- Wpis D-xxx w dzienniku decyzji (numer wg bieżącej puli), rozdzielający
  „nie tworzymy żeńskiej formy rzeczownika kuKING” od „forma czasownika do
  czytelnika”.
- Aktualizacja `COPY_STYLE.md` §2, `BRAND_EXTENDED.md` §2.4,
  `SECURITY_PRIVACY_LEGAL.md` (minimalizacja), D-206 (uzupełnienie).
- Rozstrzygnięty przycisk „Ugotowałem/Ugotowałam” (P2) i zakres miejsc.

### I-3 · Ustawienie „Jak mamy do Ciebie pisać?” (po I-2)
- Migracja: kolumna z CHECK (`zenska`, `meska`) i `NULL` = nie wybieram.
  Test migracji. `DATABASE.md`. `down()` odmawia, gdy ktoś ma wartość
  niedomyślną (D-088). Pole **poza** `$fillable` nie jest wymagane (nie
  jest polem sterującym), ale zapis przez nazwane pole formularza z walidacją `in:`.
- Ekran `/ustawienia/profil`: trzy radia z przykładowym zdaniem, domyślnie
  „nie wybieram”, zdanie „Widzisz to tylko Ty”. Działa bez JS; ≥ 18 px / ≥ 48 px.
- Policy: zmiana tylko własnego konta. Wpis w `audit_log` niepotrzebny
  (preferencja wygody), do potwierdzenia.
- Eksport RODO zawiera pole. Anonimizacja (art. 17) je zeruje.
- Polityka prywatności: wiersz o celu i widoczności.
- Nigdy nie wypełniane z Google/Facebooka (test zakresu OAuth zostaje).

### I-4 · Helper formy i przejście wybranych tekstów (po I-3)
- Helper z obowiązkowym wariantem neutralnym. `TekstyNiePrzypisujaPlciTest`
  dopuszcza rodzaj tylko w wywołaniu helpera.
- Lista ~10 miejsc z §4.4 przełączona. Landing/rejestracja/maile przed
  kontem bez zmian.
- Test: dla `NULL` wyrenderowane ekrany z listy nie zawierają rodzaju. Dla
  `zenska`/`meska` pokazują właściwą formę.
- Kontrola wizualna 320 px przy skali 150% (najdłuższe słowo).

### I-5 · Rocznica dołączenia na stronie głównej
- Blok na `/home` w dniu rocznicy `created_at` (strefa Europe/Warsaw, jak
  Wspomnienia), tekst bez rodzaju i bez „kuKINGiem”.
- Wyłączany tym samym wyłącznikiem co Wspomnienia (albo nowym — decyzja).
- Brak powiadomień i maili. Test granicy doby (wpis z 00:30).

### I-6 · Urodziny w profilu (dzień i miesiąc) + życzenia od gospodarza
- Migracja: `birthday_day smallint` + `birthday_month smallint` z CHECK
  zakresów i spójności (np. 31.04 odrzucone), oba `NULL` albo oba ustawione.
  Rollback odmawia przy danych. `DATABASE.md`.
- Ustawienia: dwa pola wyboru (dzień, miesiąc — **bez roku**), opis celu,
  przycisk „Usuń datę”, wyłącznik „Nie pokazuj mi życzeń”. Nigdy nie
  pokazywane innym (test profilu publicznego).
- `/home` w dniu urodzin: jedno zdanie od gospodarza (`host_name`). 29.02 →
  28.02 w latach nieprzestępnych.
- Polityka prywatności, rejestr czynności przetwarzania, eksport (DD-MM),
  anonimizacja.
- Bez powiadomień, bez maila (to I-7 w V1).

### I-7 (V1) · Życzenia mailem / w podsumowaniu tygodnia
- Osobna, jawna zgoda przy polu urodzin. Wysyłka w ramach sufitu dobowego
  i o 08:30. Wypisanie bez logowania.

### I-8 (V1, po #15) · Imieniny — własny dzień z kalendarza
- Po testach z użytkownikami: urodziny vs imieniny vs oba.

---

## 8. Czego ten dokument nie dowodzi

- Nie zmierzyłem, **ile kobiet** jest wśród aktywnych kont. Persona „Basia,
  61” to wybór projektowy, nie pomiar (COPY_STYLE §2 mówi to wprost).
- Tabela w §2.1 dla Allegro/LinkedIn/Duolingo/Kwestii Smaku jest
  **niezweryfikowana źródłem**. Wymaga ręcznego sprawdzenia na koncie.
- Prawne wnioski w §3 to analiza, nie opinia prawnika. Przy mailu
  urodzinowym (PKE) warto potwierdzić u prawnika, tak jak przy newsletterze
  (`docs/research/NEWSLETTER.md`).

## 9. Pytania do właściciela

- **P1.** Czy akceptujesz „formę zwracania się” (trzy opcje, domyślnie
  neutralna, widoczna tylko dla Ciebie) **zamiast** pola „płeć”?
- **P2.** Przycisk: czy przy formie żeńskiej ma brzmieć **„Ugotowałam”**?
  Łamie to regułę „jedna nazwa funkcji”, ale bez tego zmiana traci połowę
  sensu.
- **P3.** Czy forma ma działać **tylko w tekstach do Ciebie** (rekomendacja),
  czy też w tym, jak inni czytają o Tobie („Halina ugotowała…”)?
- **P4.** Urodziny: czy wystarczy **dzień+miesiąc bez roku**? Jeśli rok ma
  być, to do jakiego konkretnego celu?
- **P5.** Życzenia: tylko zdanie na stronie głównej (MVP), czy od razu mail?
  Podpis „Ula”?
- **P6.** Imieniny: czy chcesz je przed urodzinami (wg intuicji o 50+), czy
  dopiero po testach z ludźmi?
- **P7.** Pytanie o formę w onboardingu czy tylko w ustawieniach?
- **P8.** Przypomnienia obserwującym o cudzych urodzinach: czy zgadzasz się
  je odrzucić w MVP?

## Źródła

- Android Developers — [Grammatical Inflection API](https://developer.android.com/about/versions/14/features/grammatical-inflection), [GrammaticalInflectionManager](https://developer.android.com/reference/android/app/GrammaticalInflectionManager)
- Apple — [Change the language and region on iPhone](https://support.apple.com/guide/iphone/change-the-language-and-region-iphce20717a3/ios)
- Facebook: płeć i zaimki — [Pocket-lint](https://www.pocket-lint.com/apps/news/facebook/127250-facebook-s-new-custom-gender-option-here-s-how-to-choose-your-preferred-gender-and-pronoun/), [Technipages](https://www.technipages.com/how-to-change-gender-on-facebook/)
- Facebook: urodziny — [Antyweb](https://antyweb.pl/jak-wylaczyc-urodziny-na-facebooku), [Internet Standard](https://www.internetstandard.pl/jak-wylaczyc-urodziny-na-fb-ustawienia-prywatnosci-w-praktyce/)
- LinkedIn — [Adjust your birthday privacy settings](https://www.linkedin.com/help/linkedin/answer/a550097/adjusting-your-birthday-privacy-settings?lang=en)
- Cookpad — [FAQ](https://cookpad.com/us/faq)
- Garnek.pl — [Archiveteam](https://wiki.archiveteam.org/index.php/Garnek.pl)
- Nasza-klasa — [dobreprogramy](https://www.dobreprogramy.pl/zegnaj-nasza-klaso-nie-bedziemy-po-tobie-plakali-ale-bylas-wazna-opinia,6644202810538848a), [App Store](https://apps.apple.com/pl/app/naszaklasa/id6745783950)
- Imieniny — [TVP „Pytanie na śniadanie”](https://pytanienasniadanie.tvp.pl/42904405/imieniny-czy-urodziny-co-czesciej-swietuja-polacy), [poland.us](https://poland.us/imieniny-polska-tradycja-dlaczego-obchodzimy/), [Centrum Prasowe](https://centrumpr.pl/artykul/zyczenia-imieninowe-jak-polacy-obchodza-imieniny,177394.html)
- RODO / minimalizacja — [PARP](https://www.parp.gov.pl/component/content/article/88972:zasada-minimalizacji-danych-osobowych-kiedy-mniej-znaczy-wiecej), [poradyodo.pl](https://www.poradyodo.pl/dane-osobowe-a-marketing/przetwarzanie-daty-urodzin-klienta-do-celow-marketingowych-czy-jest-potrzebna-jego-zgoda-8355.html), [Bieluk i Partnerzy](https://bieluk.pl/blog/2025/12/08/zasada-minimalizacji-danych/), [prawo.pl — weryfikacja wieku, stanowisko UODO](https://www.prawo.pl/prawo/weryfikacja-wieku-w-internecie-unijny-portfel-tozsamosci-cyfrowej-albo-mobywatel,1545721.html)
- ICU — [SelectFormat](https://unicode-org.github.io/icu-docs/apidoc/dev/icu4j/com/ibm/icu/text/SelectFormat.html)
- W repo: `AGENTS.md` §1, §8, §12 · `docs/brand/COPY_STYLE.md` §2 · `docs/brand/BRAND_EXTENDED.md` §2.4 · `docs/SECURITY_PRIVACY_LEGAL.md` · `docs/legal/COMPLIANCE.md` §4 · `docs/DECISIONS.md` D-009, D-057, D-145, D-153, D-206, D-207 · `docs/DATABASE.md` (profiles, notifications, Wspomnienia) · `tests/Feature/TekstyNiePrzypisujaPlciTest.php` · `tests/Support/WzorceRodzaju.php` · `app/Domain/Notifications/TerminPowiadomieniaZewnetrznego.php` · `config/kuking.php` · `resources/legal/polityka-prywatnosci.md`
