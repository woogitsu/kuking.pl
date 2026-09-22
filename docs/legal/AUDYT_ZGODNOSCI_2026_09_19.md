# Audyt zgodności dokumentów prawnych z kodem — 19 września 2026

Issue **#8**. Podstawa: `origin/main` = `e306842c`.

**To jest audyt ZGODNOŚCI, nie porada prawna.** Odpowiadam na jedno pytanie:
czy dokumenty prawne serwisu opisują to, co kod naprawdę robi. **Czy same
zapisy są zgodne z prawem — rozstrzyga prawnik, nie ten dokument i nie jego
autor.** Wszędzie, gdzie poniżej pada słowo „ryzyko", chodzi o ryzyko
rozjazdu dokumentu z kodem, a nie o ocenę prawną.

Powód pilności: #29 wpuszcza pierwszych dwudziestu prawdziwych użytkowników.
Do tego momentu rozjazd jest tani; potem dotyczy cudzych danych.

---

## 1. Co sprawdzono i czym

| Warstwa | Zakres |
|---|---|
| Dokumenty | `resources/legal/` (3 pliki, 320 wierszy) i `docs/legal/` (6 plików, 2292 wiersze) — przeczytane w całości |
| Kod | harmonogram, retencja, eksport, usunięcie konta, integracje zewnętrzne, ciasteczka, rejestracja |
| Testy wykonane | 88 testów / 665 asercji, PostgreSQL `127.0.0.1:55439`, baza jednorazowa `kuking_legal_claude`, PHP 8.4.24 — **OK** |
| Produkcja | **nie dotykano** — audyt wyłącznie z repozytorium |

`phpunit.xml` ma zaszyte `DB_PORT=5432`; nadpisane jawnymi zmiennymi
środowiska. Potwierdzone w przebiegu: `127.0.0.1:55439 / kuking_legal_claude`.

**Wynik ogólny: dokumenty i kod trzymają się razem znacznie lepiej, niż to
bywa.** Retencja, usunięcie konta, granica OpenAI, obie drogi logowania
zewnętrznego, hasła i wiek mają pokrycie w kodzie co do liczby i co do
zachowania. Rozjazdy, które znalazłem, są w **siedmiu** miejscach i żaden nie
polega na tym, że kod robi coś ukrytego — polegają na tym, że **dokument
obiecuje więcej albo mniej, niż kod wykonuje**.

---

## 1a. Co z tego zostało zamknięte — 19 września 2026, wieczorem

Poprawki wykonane **po** audycie, na tej samej gałęzi. R1 i R6 czekają
na decyzję właściciela i nie były ruszane.

| Rozjazd | Co zmieniono | Strażnik, żeby nie odrosło |
|---|---|---|
| **R2** | `resources/views/pages/settings/data.blade.php` — zdanie nie obiecuje już „wszystkich" zdjęć i mówi to samo, co pole `czego_nie_zawiera` w samej paczce | `RozjazdyAudytuZgodnosciTest::test_ekran_paczki_nie_obiecuje_wszystkich_zdjec` czyta REGUŁĘ z `CollectUserExportData`, nie zapamiętane brzmienie zdania; osobna kontrola dodatnia sprawdza, że zalogowana osoba naprawdę to widzi |
| **R3** | polityka wymienia siódmy formularz za Turnstile („wysłaniu linku do zalogowania") | `test_polityka_wymienia_kazdy_formularz_za_turnstile` porównuje politykę z `config('kuking.turnstile.miejsca')`. Ósme miejsce w konfiguracji **zapali czerwone światło**, bo test nie będzie znał jego nazwy |
| **R5** | polityka mówi „przepisy wraz z ich wcześniejszymi wersjami" zamiast „komentarze i ich historia edycji" | `test_polityka_nie_obiecuje_historii_edycji_ktorej_nie_ma` pyta SCHEMAT: dopóki nie ma `post_versions` ani `comment_versions`, polityka nie ma prawa ich obiecywać. Gdy taka tabela powstanie, sprawdzenie samo się wyłączy |
| **R4** | `COMPLIANCE.md` — lista gotowości: realni podprocesorzy zamiast Sentry i PostHog, kanał błędów zamiast scrubbingu Sentry, baner cookies odniesiony do bezciasteczkowej statystyki; cztery martwe odsyłacze do plików `*_DRAFT.md` wskazują teraz `resources/legal/` | **rozszerzony `DokumentyPrawneNieKlamiaTest`** — patrz niżej |

### Dlaczego rozszerzenie testu było ważniejsze od samych poprawek

R4 przeżył nie dlatego, że strażnika nie było, tylko dlatego, że **patrzył
obok**: data provider `DokumentyPrawneNieKlamiaTest` wymieniał trzy
opublikowane dokumenty, a `docs/legal/` było poza zasięgiem.

Rozszerzenie **skanuje katalog**, nie wylicza plików. Gdyby wyliczało,
następny dokument dołożony do `docs/legal/` znowu wypadłby spod nadzoru.

Zakres strażnika jest świadomie **wąski — tylko wiersze listy kontrolnej**
(`| P0 |`, `| P1 |`, `| P2 |`). W `docs/legal/` słowo „PostHog" pada
szesnaście razy, ale prawie zawsze w PROZIE opisującej, że PostHog był
kandydatem i został odrzucony — razem z pomiarem, na podstawie którego go
odrzucono. Test zakazujący tego kazałby skasować powód, dla którego czegoś
**nie** zrobiliśmy. Groźna jest lista, bo to ona jest bramką „czy można
startować" dla #29.

Z tego samego powodu **nie ma** tam kontroli na `[do ustalenia]`.
W dokumencie publikowanym taki ślad jest usterką, bo widzi go użytkownik;
w wewnętrznej analizie prawnej to uczciwe oznaczenie pytania czekającego na
prawnika. Cisza w liście gotowości jest groźniejsza niż jawne „do ustalenia".

### Co przy okazji znalazł sam strażnik

Odsyłacz `ADR_RETENCJE.md` w `BRAMKA_BETY.md` wyglądał na martwy przy
pierwszej wersji sprawdzenia — plik **istnieje** (`docs/decyzje/`), a mój
test pilnował formy zapisu zamiast istnienia pliku. Poprawione: goła nazwa
jest szukana w całym drzewie `docs/`. To jest dokładnie ta klasa błędu,
przed którą ten dokument ostrzega, tyle że popełniona w teście.

### Dowody

- `RozjazdyAudytuZgodnosciTest`: 4 testy / 26 asercji **PASS**
- `DokumentyPrawneNieKlamiaTest` po rozszerzeniu: 39 testów / 436 asercji **PASS**
  (przed rozszerzeniem: 30 testów)
- **Pięć fizycznych kontroli ujemnych** na prawdziwych plikach — cofnięcie
  każdej poprawki (R2, R3, R5 oraz oba rozjazdy R4) daje czerwień; po
  przywróceniu zgodność `cmp` bajt w bajt i komplet zielony
- Pint: 1140 plików PASS. PHPStan: bez błędów
- Pełny przebieg: **4312 testów PASS, 1 porażka** — `ProbaOdtworzeniaTest`,
  która uruchomiona osobno przechodzi (1 test / 7 asercji). To znana kolizja
  izolacji tego testu przy równoległych przebiegach na wspólnej maszynie,
  nie skutek tych zmian

### Czego te poprawki NIE rozstrzygają

Wiersz o umowach powierzenia wymienia teraz **realnych** odbiorców danych,
ale **czy i jakie umowy są z nimi podpisane — to pytanie do prawnika i do
właściciela**, nie do audytu kodu. Zmiana poprawia listę, nie stan prawny.
Brzmienie obu poprawionych zdań polityki (R3, R5) także należy pokazać
prawnikowi — tekst prawny nie jest rzeczą do redagowania przez automat.

---

## 2. Rozjazdy w kolejności ryzyka

### R1 — Polityka obiecuje „pełną kopię" danych; paczka świadomie nie jest pełna

| | |
|---|---|
| **Co mówi dokument** | `resources/legal/polityka-prywatnosci.md:88` — „**dostępu** do swoich danych — możesz je zobaczyć w ustawieniach konta lub poprosić o **pełną kopię**" |
| **Co robi kod** | `app/Domain/Users/Exports/CollectUserExportData.php:71` — `'co_zawiera' => 'Treści tego konta — także wpisy prywatne i szkice przepisów.'` Paczka niesie 12 sekcji (`:132–143`) i **świadomie pomija** kilka kategorii danych osobowych |
| **Dowód** | Klucze najwyższego poziomu: `CollectUserExportData.php:132–143`. Komentarz nazywający pominięcia: `:60–70` — „poza nią zostają m.in. wcześniejsze wersje własnych przepisów (`recipe_versions`…), obserwowane tagi, dziennik zgód i tożsamości zewnętrzne" |

Czego paczka nie niesie, a polityka wymienia jako dane, które trzymamy:

| Kategoria | Gdzie polityka to deklaruje | Tabela |
|---|---|---|
| tożsamości zewnętrzne (id konta Google/Facebooka + data połączenia) | `polityka:65` — „W naszej bazie zostaje **identyfikator Twojego konta Google i data połączenia**" | `tozsamosci_zewnetrzne` |
| wiadomości „Napisz do nas" + nasza odpowiedź | `polityka:33` — przechowywane 12 miesięcy od załatwienia | `contact_messages` |
| zgłoszenia, decyzje moderatora, odwołania | `polityka:28` — 36 miesięcy | `reports`, `moderation_actions`, `appeals` |
| dziennik zdarzeń bezpieczeństwa (skrót IP) | `polityka:29` — 12 miesięcy | dziennik audytu |
| zdarzenia analityczne | `polityka:31` — 90 dni | `product_signals` |
| wcześniejsze wersje własnych przepisów | `polityka:27` — „historia edycji" | `recipe_versions` |
| dziennik zgód (digest) | — (nie deklarowany wprost) | `dziennik_zgod` |
| obserwowane tagi | — | `tag_follows` |

**To nie jest zarzut wobec paczki.** Zakres eksportu był świadomie zawężany
w #492, #678 i #692 i jest w kodzie opisany lepiej niż większość funkcji tego
repozytorium. Rzecz w tym, że **praca nad zdaniami zatrzymała się na granicy
paczki**: `co_zawiera` i `czego_nie_zawiera` mówią prawdę, a dwa miejsca,
w których człowiek dowiaduje się, co dostanie — polityka i ekran ustawień —
nadal obiecują komplet.

Pilnuje tego test, ale **tylko po stronie paczki**:
`tests/Feature/PaczkaNieObiecujeKompletuTest.php:77` sprawdza wyłącznie
`$opis['co_zawiera']` z JSON-a. Ani polityki, ani ekranu ustawień nie dotyka
żaden test.

**Co trzeba zmienić:** dokument albo kod — i **to jest decyzja, której nie
podejmuję**. Dwie drogi wykluczają się nawzajem:
- albo zawęzić zdanie w polityce (wtedy pytanie do prawnika: czy zakres
  paczki wystarcza pod art. 15 i 20 RODO, skoro człowiek może i tak poprosić
  o resztę mailem — polityka:88 taką drogę wymienia),
- albo rozszerzyć paczkę o brakujące kategorie (wtedy pytanie techniczne:
  które z nich zawierają dane innych osób i muszą być przycięte — paczka już
  dziś tnie komentarze innych do treści, daty i nazwy, `:112`).

**Propozycja brzmienia — do decyzji właściciela, nie do wprowadzenia bez
niej:** „możesz je zobaczyć w ustawieniach konta, pobrać paczkę ze swoimi
treściami albo poprosić nas o kopię pozostałych danych, które o Tobie mamy".

---

### R2 — Ekran ustawień obiecuje „wszystkie zdjęcia"; paczka zdjęć odrzuconych i skasowanych nie niesie nigdy

| | |
|---|---|
| **Co mówi dokument** | `resources/views/pages/settings/data.blade.php:13` — „Przygotujemy paczkę ze **wszystkimi** Twoimi wpisami, przepisami, **zdjęciami** i komentarzami" |
| **Co robi kod** | Paczka liczy, ale nie załącza: `CollectUserExportData.php:120` `zdjec_jeszcze_w_przygotowaniu`, `:129` `zdjec_odrzuconych_przy_przygotowaniu`, `:130` `zdjec_skasowanych` |
| **Dowód** | `CollectUserExportData.php:99–112` — „zdjęcie odrzucone albo skasowane nie wejdzie do ŻADNEJ paczki, także przyszłej" |

To ten sam rozjazd co R1, tylko węższy i tańszy: paczka **mówi o swoich
brakach**, a ekran, który ją zamawia, o nich nie mówi. Zdanie z ekranu jest
pierwszym, które człowiek czyta, i jedynym, zanim kliknie.

**Co trzeba zmienić:** zdanie na ekranie (kod widoku, nie dokument prawny).
To jest **zwykła usterka**, nie sprawa dla prawnika.

---

### R3 — Polityka wymienia sześć formularzy za Turnstile; kod ma siedem

| | |
|---|---|
| **Co mówi dokument** | `polityka:56` — „Przy **rejestracji, logowaniu, odzyskiwaniu hasła, cofnięciu usunięcia konta** oraz przy formularzach **»Napisz do nas«** i **zgłoszenia nielegalnej treści**" |
| **Co robi kod** | Siedem miejsc. Siódme: **„Wyślij mi link do zalogowania"** |
| **Dowód** | `config/kuking.php:938` — „warunek wysłania **siedmiu** formularzy publicznych"; `config/kuking.php:1035–1044` — „SIÓDME MIEJSCE, DOŁOŻONE 10 WRZEŚNIA 2026 (issue #25, D-056)"; `app/Http/Controllers/Auth/LoginLinkController.php:128` — `TurnstileJestPotwierdzony::reguly('logowanie_linkiem')` |

Sześć wymienionych w polityce jest w kodzie obecnych — **żadnego nie
brakuje**. Brakuje jednego w dokumencie. Turnstile dołożono 10 września,
polityka nosi datę 11 września: zdanie rozjechało się **następnego dnia** po
zmianie i nikt tego nie złapał, bo żaden test nie porównuje listy z polityki
z listą z konfiguracji.

Kierunek rozjazdu jest tu łagodniejszy niż w R1: człowiek dostaje *więcej*
ochrony, niż obiecano, ale jego adres IP trafia do Cloudflare także na
ścieżce, której polityka nie wymienia.

**Co trzeba zmienić:** dokument — dopisać siódmy formularz. Decyzja
o brzmieniu tekstu prawnego należy do właściciela.

---

### R4 — Lista gotowości P0 w COMPLIANCE.md opisuje inny serwis niż ten

| | |
|---|---|
| **Co mówi dokument** | `docs/legal/COMPLIANCE.md:297` — „P0 \| DPA/umowy powierzenia z Railway, Cloudflare R2, **Sentry, PostHog**, dostawcą e-mail"; `:300` — „P0 \| **Sentry**: reguły scrubbingu PII skonfigurowane przed pierwszym prawdziwym użytkownikiem"; `:301` — „P1 \| Baner cookies (jeśli **PostHog**…)" |
| **Co robi kod** | **Sentry i PostHog nie istnieją w tym projekcie i nigdy nie istniały.** Każde wystąpienie słowa „Sentry" w `app/` to komentarz mówiący, że go nie ma |
| **Dowód** | `app/Support/Wersja.php:39` — „**Sentry'ego w tym projekcie nie ma**: nie ma pakietu w `composer.json`"; `app/Domain/Security/DziennyBudzetListow.php:413`; `app/Http/Controllers/HealthController.php:360`. `PostHog` — zero trafień w `app/`, `config/`, `composer.json` |

Do tego dwa martwe odnośniki w tej samej tabeli: `:291` i `:292` odsyłają do
`REGULAMIN_DRAFT.md` i `POLITYKA_PRYWATNOSCI_DRAFT.md` — **obu plików nie ma**
(dokumenty żyją w `resources/legal/`).

Dokument **częściowo już się poprawił**: `COMPLIANCE.md:258` mówi wprost, że
sekcje 5.2–5.4 powstały, gdy kandydatem był PostHog, i że wdrożono co innego.
Poprawka objęła jednak **prozę, a nie listę kontrolną** — a to lista jest
bramką „czy można startować". Dziś ta bramka zawiera dwa blokujące punkty P0
dotyczące usług, których nie ma, i nie zawiera ani jednego punktu
o Cloudflare Web Analytics, OpenAI ani logowaniu Facebookiem, czyli o trzech
integracjach, które **naprawdę** wysyłają dane na zewnątrz.

Dlaczego to jest wysoko na liście ryzyka mimo że nie dotyka użytkownika
bezpośrednio: #29 ma być odblokowane na podstawie tej listy. Lista, która
wymienia nieistniejące usługi, nie daje się odhaczyć w dobrej wierze —
i skłania do odhaczenia „na oko", co jest gorsze niż brak listy.

Istnieje test pilnujący, żeby dokumenty **nie wymieniały nieużywanych
narzędzi** — `tests/Feature/DokumentyPrawneNieKlamiaTest.php:121`
`test_nie_wymieniamy_narzedzi_ktorych_nie_uzywamy`, z jawnym wpisem
`'Sentry' => class_exists(...)` i `'PostHog' => false` (`:147–149`). **Jego
zakres to jednak wyłącznie trzy opublikowane dokumenty** — data provider
`:46–52` wymienia `/prywatnosc`, `/regulamin`, `/zasady`. `docs/legal/` jest
poza zasięgiem tego strażnika i dlatego rozjazd przeżył.

**Co trzeba zmienić:** dokument (`COMPLIANCE.md`) — i osobno warto rozważyć
rozszerzenie zakresu istniejącego testu na `docs/legal/`. To jest **zwykła
usterka dokumentacyjna**, nie sprawa dla prawnika, z jednym wyjątkiem:
wiersz `:297` o umowach powierzenia trzeba przepisać na **realną** listę
podprocesorów, a tę powinien zobaczyć prawnik.

---

### R5 — Polityka deklaruje historię edycji komentarzy i wpisów; w bazie jej nie ma

| | |
|---|---|
| **Co mówi dokument** | `polityka:27` — „Publikowanie treści \| zdjęcia, przepisy, wpisy, komentarze **i ich historia edycji**" |
| **Co robi kod** | Historia wersji istnieje **wyłącznie dla przepisów** (`recipe_versions`, `database/migrations/2026_09_05_000400_create_recipes_tables.php`). Dla wpisów i komentarzy nie ma żadnej tabeli ani kolumny historii |
| **Dowód** | Brak migracji pasujących do `version|revision|histor`; brak kolumn `edited*` na `comments` i `posts`; `docs/DATABASE.md:1573` opisuje `recipe_versions` jako jedyną tabelę wersji |

Rozjazd w drugą stronę niż R1: dokument **naddeklarowuje zbieranie**.
Skutek dla człowieka jest odwrotny — myśli, że trzymamy o nim więcej, niż
trzymamy. Ryzyko niskie, naprawa tania.

**Co trzeba zmienić:** dokument — zawęzić do „przepisy i ich wcześniejsze
wersje". Decyzja o brzmieniu należy do właściciela.

---

### R6 — Polityka opisuje ciasteczka wyłącznie jako sesyjne; są jeszcze dwa preferencyjne, roczne

| | |
|---|---|
| **Co mówi dokument** | `polityka:95` — „Używamy technicznie niezbędnych plików cookies (**np. do utrzymania sesji logowania**)" |
| **Co robi kod** | Poza sesją i `XSRF-TOKEN` serwis stawia `kuking_text_scale` i `motyw`, oba z ważnością **roku** |
| **Dowód** | `app/Http/Controllers/Settings/AccessibilitySettingsController.php:46`; `app/Http/Controllers/ThemeController.php:69` i `:72–76`; nazwy w `config/kuking.php:416` i `:435` |

**Obietnica merytoryczna trzyma się w całości**: żadne ciasteczko nie służy
statystyce ani reklamie, sprawdzone po kolei. Niuans jest inny: oba są
**ciasteczkami preferencji ustawianymi na wyraźne życzenie użytkownika**,
a nie „niezbędnymi" w wąskim sensie, i polityka ich nie nazywa. Czy mieszczą
się w zdaniu o technicznej niezbędności — **to jest pytanie do prawnika**, nie
do audytu kodu.

---

### R7 — Termin „najpóźniej w ciągu miesiąca" nie ma w kodzie żadnego licznika

| | |
|---|---|
| **Co mówi dokument** | `polityka:97` — „Odpowiadamy na takie wnioski bez zbędnej zwłoki, **najpóźniej w ciągu miesiąca**"; `COMPLIANCE.md:152` — zgłoszenie do UODO w ciągu 72 godzin |
| **Co robi kod** | Nic tego nie liczy i nic o tym nie przypomina. Jedyny działający licznik terminu dotyczy odwołań moderacyjnych (DSA art. 20): `app/Console/Commands/PilnujTerminowOdwolan.php:36`, oparty o `Appeal::responseDeadline()` |
| **Dowód** | Brak jakiegokolwiek pola terminu przy wnioskach o dane i przy `contact_messages`; własny audyt repozytorium odnotował to samo dla zgłoszeń — `docs/AUDYT_2026-09.md:558` |

To **nie jest** rozjazd dokumentu z kodem w ścisłym sensie: obietnica
dotyczy zachowania człowieka, nie maszyny, i jedna osoba obsługująca
dwadzieścia kont dotrzyma jej bez przypominajki. Zapisuję ją, bo przy
skali większej niż #29 obietnica bez licznika jest obietnicą, o której nikt
się nie dowie, że została złamana — dokładnie tak jak przy SLA zgłoszeń.

**Co trzeba zmienić:** nic natychmiast. Do rozważenia przy skalowaniu.

---

## 3. Co sprawdzono i co się ZGADZA

Wszystko poniżej potwierdzone w kodzie z dowodem; wymieniam, bo w audycie
zgodności brak rozjazdu jest wynikiem, nie milczeniem.

| Obietnica | Dowód |
|---|---|
| Karencja 30 dni przed usunięciem | `config/kuking.php:447` `delete_grace_days => 30`; `PurgeExpiredAccountDeletions.php:69–76` |
| Domyślnie: zdjęcia znikają wszystkie, teksty zostają jako „Użytkownik usunięty" | `EraseAccountData.php:277` `'display_name' => 'Użytkownik usunięty'`, `:279` `avatar_media_id => null`, `:44` „KASUJE WSZYSTKIE ZDJĘCIA TEJ OSOBY" |
| Haczyk „usuń także treści" kasuje wszystko | `EraseAccountData.php:545` `usunTresci()` przy `delete_scope = everything` |
| Moderacja 36 mies. | `config/kuking.php:2575` `case_retention_months => 36` + `kuking:sprzataj-sprawy-moderacyjne` (`routes/console.php:138`) |
| Dziennik zdarzeń 12 mies. | `config/kuking.php:2337` + `routes/console.php:116` |
| Powiadomienia 3 mies., moderacyjne ≥6 mies. | `config/kuking.php:867` i `:2496` `appeal_days => 180` + `routes/console.php:125` |
| Analityka 90 dni | `config/kuking.php:2169` `signal_retention_days => 90` + `routes/console.php:104` |
| „Napisz do nas" 12 mies. od załatwienia | `config/kuking.php:2423` + `routes/console.php:150` |
| Ostatnia wizyta: jedna wartość, nie częściej niż co ~15 min | `config/kuking.php` (`analytics`, odstęp 15); w paczce jako `ostatnio_widziany` — `CollectUserExportData.php:166` |
| OpenAI: prywatny wpis nie przechodzi | `app/Jobs/PrzeanalizujTresc.php:163` — `whereIn('visibility', [VISIBILITY_PUBLIC, VISIBILITY_FOLLOWERS])`, uzasadnienie `:148` |
| OpenAI: żadna ocena maszynowa nie ukrywa treści | wynik idzie wyłącznie do kolejki moderatora (`OcenaModelem`, `PrzeanalizujTresc`) |
| Google: zakres `openid email profile`, bez zdjęcia, bez tokenów | `app/Support/Google.php:66`; `app/Google/KlientGoogle.php:276, 284`; `:122` `access_type => online` |
| Facebook: e-mail zawsze niepotwierdzony | `FacebookLoginController.php:513` `emailPotwierdzony: false` |
| Facebook: nigdy nie łączy się sam z istniejącym kontem, właściciel dostaje list | `FacebookLoginController.php:334–361`, `:781` `notify(new ProbaWejsciaKontemFacebooka)` |
| Facebook: brak adresu → osobny ekran, konto nie powstaje | `FacebookLoginController.php:328–331`; `resources/views/auth/facebook-bez-adresu.blade.php` |
| Facebook: deauthorize nie kasuje konta | `FacebookDeauthorizeController.php:88`; `User.php:1009–1015` — tylko `dostep_odebrany_at` |
| Żadnych tokenów dostępu w bazie | `KlientFacebook.php:205–209`; schemat `tozsamosci_zewnetrzne` bez kolumny na token |
| Brak piksela i skryptu Facebooka | zero trafień `fbq(`, `fbevents`, `connect.facebook`, `FB.init` w `resources/views/` i `public/` |
| Cloudflare Web Analytics tylko przy tokenie; beacon dostaje sam token | `AnalitykaCloudflare.php:59–61`, `:115–121`; `layout.blade.php:342–346` |
| `/health` pilnuje, żeby dokument nie obiecywał analityki bez tokenu | `HealthController.php:259` + `AnalitykaCloudflare::obiecanaWDokumencie()` |
| Wypis z podsumowania: jedno kliknięcie, bez logowania, bez pytania o powód | `routes/web.php:166–168` (poza grupą `auth`, autoryzacja podpisem); `PodsumowanieTygodniaController.php:70–82` |
| Najwyżej jeden list na tydzień | `config/kuking.php:2098` `odstep_dni => 7`; `OdbiorcyDigestu.php:111–113, 145–147` |
| Zero obrazków śledzących w naszych szablonach | `podsumowanie-tygodnia.blade.php` — zero `<img` |
| Wiek 16 lat wymagany, także na drogach Google i Facebook | `RegisterController.php:132`; `config/kuking.php:443`; `FacebookLoginController.php:457`; `GoogleLoginController.php:400` |
| Hasła jako nieodwracalny skrót | `app/Models/User.php:307` `'password' => 'hashed'`, `:898` `Hash::make()` |
| Umowy powierzenia niepodpisane — i polityka mówi o tym wprost | `polityka:78`; pilnuje tego `DokumentyPrawneNieKlamiaTest::test_nie_twierdzimy_ze_mamy_umowy_powierzenia` |

---

## 4. Podział: prawnik czy usterka

**Wymaga decyzji prawnika**
1. **R1** — czy zakres paczki wystarcza pod art. 15 i 20 RODO, skoro część
   danych osobowych zostaje poza nią, a polityka wskazuje drogę mailową. Od
   tej odpowiedzi zależy, czy poprawiamy zdanie, czy paczkę.
2. **R6** — czy ciasteczka preferencji (`motyw`, `kuking_text_scale`, rok)
   mieszczą się w „technicznie niezbędnych", czy wymagają osobnego zdania.
3. **R4 wiersz `:297`** — realna lista podprocesorów do umów powierzenia
   (Railway, Cloudflare R2 + Turnstile + Web Analytics, EmailLabs/Vercom,
   OpenAI, Google, Meta), zamiast dzisiejszej z Sentry i PostHogiem.
4. Brzmienie każdej zmiany w `resources/legal/` — **nie przepisuję dokumentów
   prawnych**, propozycje wyżej są propozycjami.

**Zwykłe usterki do naprawienia bez prawnika**
1. **R2** — zdanie na ekranie `settings/data.blade.php:13` (widok, nie
   dokument prawny).
2. **R4** — martwe odnośniki do `REGULAMIN_DRAFT.md`
   i `POLITYKA_PRYWATNOSCI_DRAFT.md`; punkty P0/P1 o Sentry i PostHogu.
3. **R3 i R5** — poprawki faktograficzne w polityce (siódmy formularz;
   historia edycji tylko przepisów) — treść do zatwierdzenia przez
   właściciela, ale to nie są zmiany o skutku prawnym, tylko sprostowania.
4. Rozszerzenie `DokumentyPrawneNieKlamiaTest` na `docs/legal/` — bez tego
   R4 wróci.
5. Test porównujący listę formularzy z polityki z `config/kuking.php`
   (`turnstile`) — bez tego R3 wróci przy ósmym formularzu.

---

## 5. Czego NIE sprawdziłem

Każdy punkt to brak dowodu, nie wynik pozytywny.

- **Zgodności zapisów z prawem.** Poza zakresem z założenia. Nie oceniam
  podstaw prawnych, testu równoważenia przy uzasadnionym interesie, ani czy
  36/12/3 miesiące to okresy właściwe.
- **Produkcji.** Audyt wyłącznie z repozytorium; nie sprawdzałem, czy
  wdrożona instancja ma te same wartości konfiguracji ani czy zadania nocne
  naprawdę chodzą.
- **Zachowania usług zewnętrznych.** Że beacon Cloudflare nic nie zapisuje na
  urządzeniu, że OpenAI nie trenuje na przesłanych treściach, że EmailLabs
  dokłada piksel otwarcia — **to są twierdzenia o cudzym kodzie i cudzych
  warunkach**, nieweryfikowalne z tego repozytorium. `COMPLIANCE.md:260`
  opisuje pomiar `beacon.min.js`, ale ja go nie powtórzyłem.
- **Faktycznego ruchu sieciowego.** Nie przechwytywałem żądań do OpenAI,
  Google, Facebooka ani Cloudflare — sprawdzałem, co kod *składa*, a nie co
  poszło drutem.
- **`docs/legal/BRAMKA_BETY.md`, `MODERATION_PLAYBOOK.md`,
  `SYGNALY_AUTOMATU.md`, `SECURITY_BASELINE.md`, `LICENCJA_UGC_PROJEKT.md`** —
  przeczytane, ale **nie zweryfikowane wobec kodu punkt po punkcie**. To
  łącznie 1915 wierszy procedur; audyt objął `COMPLIANCE.md` i trzy dokumenty
  opublikowane. Tam mogą siedzieć kolejne rozjazdy tej samej klasy co R4.
- **Rejestru czynności przetwarzania (art. 30 RODO)** — `COMPLIANCE.md:298`
  mówi, że go nie ma; nie szukałem, czy powstał gdzie indziej.
- **Treści samej paczki na żywych danych.** Czytałem kod budujący eksport;
  nie wygenerowałem paczki i nie obejrzałem jej zawartości.
- **Czy `contact_messages` po usunięciu konta faktycznie tracą powiązanie**
  (`polityka:33` to obiecuje) — nie prześledziłem tej ścieżki w kodzie.

---

## 6. Stan pakietu

Commit lokalny na gałęzi `audyt/8-przeglad-prawny`. **Nie wypchnięto,
nie utworzono PR-a** — zgodnie z poleceniem. Żadnego dokumentu prawnego nie
zmieniono; ten plik jest raportem, nie poprawką. **#8 pozostaje otwarte.**
