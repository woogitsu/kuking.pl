# Rejestr czynności przetwarzania — art. 30 ust. 1 RODO

Stan: gałąź `robota/bramka-startowa`, od `main` = `534e0a51`, 20 września 2026.
Uzupełnienie z 29 września 2026 (#1816, wersja polityki `2026-09-29`):
§3.5 (obserwowane tagi), §3.16 (odpięcie zdarzeń po wymazaniu konta),
§3.17 (zakres paczki danych), nowe §3.20–3.22 (ukrycia, reakcja „Smakowicie
wygląda”, lista „Co mam w domu”).
Uzupełnienie z 30 września 2026 (#1751, D-332, wersja polityki `2026-09-30`,
zmiana drobna, obowiązuje od dnia publikacji): §3.2 (forma zwracania się).
Uzupełnienie z 30 września 2026 (#2283, audyt prywatności Z7, Z8, Z11):
§3.8 (zakres `profile` przy Google obejmuje zdjęcie, którego nie zapisujemy),
§3.12 (ślad nieudanej wysyłki w `failed_jobs` — 30 dni), §3.19 i §4
(kanał alarmów Discord działa — odbiorca techniczny bez danych osobowych).
Uzupełnienie z 1 października 2026 (#2377, decyzja właściciela „Budujemy
z ostrzeżeniem”, D-333, wersja polityki `2026-09-30`, zmiana drobna): §3.3
(dyktowanie w przeglądarce — Kuking nie przetwarza dźwięku).

**Skąd wzięła się treść tego dokumentu.** Każda czynność niżej jest
**wyprowadzona z kodu tego repozytorium**, nie z wyobraźni i nie z polityki
prywatności. Tam, gdzie twierdzenie stoi na pliku, plik jest wskazany.
Tam, gdzie czegoś z kodu wyprowadzić się nie da — dane rejestrowe,
inspektor ochrony danych, umowy — stoi jawne
`DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:`. Pole zmyślone byłoby tu gorsze niż
puste: rejestr pokazuje się organowi, a nie sobie.

**To nie jest porada prawna.** Przed pokazaniem tego dokumentu komukolwiek
z zewnątrz powinien go przeczytać prawnik — w szczególności kolumnę
„podstawa prawna", bo to jedyna kolumna, której kod nie rozstrzyga.

**Dlaczego rejestr jest obowiązkowy mimo jednoosobowej skali.** Zwolnienie
z art. 30 ust. 5 RODO obejmuje wyłącznie przetwarzanie *okazjonalne*,
bez ryzyka dla praw i wolności. Prowadzenie kont użytkowników serwisu
społecznościowego okazjonalne nie jest. Szerzej: `COMPLIANCE.md` §2.5.

---

## 1. Administrator i dane kontaktowe (art. 30 ust. 1 lit. a)

Wszystkie poniższe dane stoją w `config/kuking.php` (`kuking.podmiot`)
i są porównywane z treścią regulaminu i polityki przez
`DokumentyPrawneNieKlamiaTest::test_tozsamosc_administratora_zgadza_sie_z_konfiguracja`.

| Pole | Wartość |
|---|---|
| Administrator | SAMSUFI Spółka z ograniczoną odpowiedzialnością |
| Adres | Jagiellońska 4A, 19-120 Knyszyn, Polska |
| KRS | 0000901262 |
| NIP | 5423435334 |
| REGON | 388971059 |
| Adres e-mail spółki | biuro@samsufi.pl |
| Adres kontaktowy serwisu | kontakt@kuking.pl (`config/kuking.php` → `kuking.community.contact_email`) |
| Serwis | Kuking.pl |

**DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:**

- **Przedstawiciel administratora** (art. 27 RODO) — nie dotyczy, jeżeli
  spółka ma siedzibę w Polsce; wpisać „nie dotyczy" albo dane, jeśli jednak
  jest.
- **Inspektor ochrony danych (IOD)** — czy został wyznaczony, a jeśli tak:
  imię, nazwisko i adres kontaktowy. Z kodu tego nie widać i nie da się
  wywnioskować. Jeśli IOD nie ma, właściwym wpisem jest **„nie wyznaczono,
  bo nie zachodzi żadna z przesłanek art. 37 ust. 1 RODO"** — ale ocena,
  czy nie zachodzi, należy do prawnika, nie do tego dokumentu.
- **Współadministrowanie** — czy z kimkolwiek zawarto umowę z art. 26 RODO.
  Z kodu widać jeden przypadek, w którym pojawia się **osobny**
  administrator (Meta przy logowaniu Facebookiem, §4), ale to nie jest
  współadministrowanie; patrz `REJESTR_UMOW_POWIERZENIA.md`.
- **Osoba, która utrzymuje ten rejestr** i data ostatniego przeglądu.

---

## 2. Kategorie osób, których dane dotyczą (art. 30 ust. 1 lit. c)

Kod zna dokładnie cztery kategorie. Trzecia i czwarta bywają pomijane
w rejestrach pisanych „z głowy", a obie są tu realne:

1. **Zarejestrowani użytkownicy serwisu** — osoby, które założyły konto.
2. **Osoby niezalogowane, które korzystają z formularzy publicznych** —
   „Napisz do nas" i zgłoszenie nielegalnej treści działają **bez konta**
   (`config/kuking.php`, uzasadnienie przy formularzu zgłoszenia: wymóg
   konta wykluczałby dokładnie tych, dla których formularz istnieje).
3. **Osoby trzecie widoczne w treściach publikowanych przez użytkowników** —
   ktoś na zdjęciu w tle, ktoś opisany w przepisie „po mamie". Serwis ich
   nie zbiera, ale je przetwarza, bo leżą w cudzej treści. Polityka
   prywatności mówi to wprost w §2.
4. **Osoby zgłaszające treści i osoby zgłaszane** — w sprawie moderacyjnej
   występują obie strony i obie są podmiotami danych.

---

## 3. Czynności przetwarzania (art. 30 ust. 1 lit. b, c, d, f)

Kolumna „termin usunięcia" podaje **to, co egzekwuje kod**, a nie to, co
byłoby ładne. Gdzie kod nie egzekwuje niczego, napisane jest, że nie
egzekwuje.

**Podstawy prawne: przegląd z 2 października 2026 (#2708).** Wg analizy
[`../prawo/OPINIA_AI_2026-10-02.md`](../prawo/OPINIA_AI_2026-10-02.md), pytanie 5
(analiza AI, nie podpisana opinia prawnika), nie każda funkcja opisana w
regulaminie jest „niezbędna do wykonania umowy”. Podstawa jest przypisana do
celu, nie do całego serwisu:

| Cel | Podstawa | Sekcje |
|---|---|---|
| Konto, logowanie, profil, własne treści, relacje, prywatne funkcje (zeszyty, planer, spiżarnia, porcje, dopisek, udostępnienie) | art. 6 ust. 1 lit. b | 3.1–3.5, 3.20–3.31 |
| Obsługa zgłoszeń nielegalnych treści i obowiązkowe uzasadnienia (art. 16–18 DSA) | lit. c | 3.6 |
| Moderacja naruszeń własnych zasad, skarga przy zwolnieniu z art. 20, logi bezpieczeństwa, ochrona formularzy, wstępna klasyfikacja przez AI, własna analityka | lit. f, każdy cel z osobnym **testem równowagi** (stan: brak, patrz §7 pkt 4) | 3.6, 3.7, 3.9, 3.10, 3.15, 3.16 |
| Odpowiedź na żądania osób (dostęp, usunięcie) | lit. c; minimalny ślad po załatwieniu: rozliczalność i lit. f, z własnym terminem | 3.17 |
| Tygodniowy list, urodziny (wpisanie daty, list, przypomnienie) | lit. a (oraz PKE); zgoda na list nie obejmuje pomiaru otwarć | 3.13, 3.18 |
| Dane o zdrowiu w treściach prywatnych | **art. 9 ust. 2**: sama lit. b nie wystarcza; patrz niżej | 3.22, 3.26, 3.29 (dopisek) |

**Dane o zdrowiu (art. 9).** „Mniej soli” nie musi być daną o zdrowiu, ale
„dieta po operacji”, diagnoza, alergia konkretnej osoby czy informacja o
leczeniu mogą nią być. Art. 6 ust. 1 lit. b nie zastępuje przesłanki z art. 9
ust. 2, a „użytkownik podał dobrowolnie” jej nie jest. Serwis o dane
zdrowotne **nie pyta**; mogą się pojawić w notatkach, dopiskach i spiżarni.
Do czasu odrębnej koncepcji z art. 9 (wyraźna zgoda, ograniczenie zakresu albo
rezygnacja z funkcji) rejestr tego nie rozstrzyga i **nie dopisuje** ogólnego
checkboxa. Nie wolno natomiast deklarować, że danych o zdrowiu w serwisie nie
ma (zob. też Railway DPA, Exhibit A, `REJESTR_UMOW_POWIERZENIA.md` §2.1).

Dostawcy: Google i Meta (§3.8) są **odrębnymi administratorami**, a
Cloudflare Turnstile (§3.9) ma **rolę mieszaną** (procesor oraz administrator
dla własnego celu), zob. `REJESTR_UMOW_POWIERZENIA.md`. Obowiązek umów
powierzenia dotyczy **obecnego przetwarzania**, nie „pierwszej osoby spoza
bety”.

### 3.1 Prowadzenie konta i uwierzytelnianie

- **Cel:** założenie konta, wejście na nie, odzyskanie dostępu, potwierdzenie
  adresu e-mail, dwuetapowa weryfikacja.
- **Dane:** adres e-mail, hasło jako nieodwracalny skrót, status konta,
  ustawienia (język, skala tekstu, motyw), oświadczenie o wieku ≥ 16 lat
  (`app/Http/Controllers/Auth/RegisterController.php` — `age_confirmed`),
  data ostatniej wizyty.
- **Akceptacja regulaminu (#2217):** przy rejestracji (hasło, Google, Facebook)
  zapisujemy w `dziennik_zgod` wiersz `cel = regulamin`: wersja regulaminu
  i polityki obowiązujące w chwili akceptacji, moment i droga rejestracji —
  bez IP i danych przeglądarki. Dziennik jest append-only (D-072); wiersz
  zostaje przy zanonimizowanym koncie. Retencja do ustalenia z prawnikiem (#8).
- **Podstawa:** art. 6 ust. 1 lit. b RODO — wykonanie umowy (regulamin).
- **Odbiorcy:** Railway (hosting i baza), EmailLabs (listy z potwierdzeniem
  i linkiem), Cloudflare Turnstile (ochrona formularzy — §3.9).
- **Termin usunięcia:** do usunięcia konta; po zgłoszeniu usunięcia konto
  czeka **30 dni** w stanie `pending_delete`
  (`config/kuking.php` → `delete_grace_days`), potem dane kasuje
  `kuking:usun-wygasle-konta`. Wygasłe żądania zmiany adresu e-mail kasuje
  `kuking:sprzataj-zmiany-adresu`, wygasłe zaproszenia —
  `kuking:sprzataj-zaproszenia`. Żetony resetu hasła (`password_reset_tokens`, klucz: adres
  e-mail) kasuje co noc `kuking:sprzataj-resety-hasel`, a przy wymazaniu konta —
  `EraseAccountData` (audyt B5 pkt 6). Wygasłe dowody połączenia z Facebookiem
  (`facebook_connection_proofs`: `user_id` i skróty HMAC, ważne 10 minut) kasuje co noc
  `kuking:sprzataj-dowody-facebooka`, a przy wymazaniu konta — `EraseAccountData` (#2319).

### 3.2 Profil publiczny

- **Cel:** pokazanie użytkownika innym ludziom w serwisie.
- **Dane:** nazwa użytkownika, nazwa wyświetlana, opis, zdjęcie profilowe,
  jeśli je wpisze — region („Skąd jesteś”, `profiles.region`) i „Na czym się
  znasz” (`profiles.speciality`), oba widoczne na publicznym profilu także
  bez logowania (#2281), a jeśli ją wybierze — forma zwracania się (żeńska albo męska; brak wyboru
  = forma neutralna). Forma to preferencja językowa, **nie płeć**; jest
  widoczna dla innych w tekstach o tej osobie, nie jest zgadywana ani brana
  z Google/Facebooka i nie służy statystykom ani segmentacji (D-332).
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Railway, Cloudflare R2 (zdjęcie profilowe). Od D-240
  zdjęcie profilowe **nie** idzie do OpenAI — brak potwierdzonej zgody.
- **Termin usunięcia:** do zmiany przez użytkownika albo do usunięcia konta.

### 3.3 Publikowanie treści

- **Cel:** publikowanie wpisów, przepisów, komentarzy, „Ugotowałem"
  i zeszytów — to jest sama usługa.
- **Dane:** tekst treści, wcześniejsze wersje przepisu, powiązania między
  treściami; a także **wszystko, co użytkownik sam o sobie albo o kimś
  napisze** — łącznie z danymi, o które serwis nie pyta (dieta, zdrowie,
  osoby trzecie). Polityka prywatności §2 mówi o tym wprost.
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Dyktowanie dłuższych pól po zalogowaniu (#2377, etap 2):** przycisk „Dyktuj” korzysta
  wyłącznie z Web Speech API **przeglądarki** (`resources/js/dyktowanie.js`).
  Dźwięk może trafić do dostawcy przeglądarki (np. Google, Apple) na jego
  zasadach — to nie jest nasz podmiot przetwarzający i nie dostaje od nas
  żadnych danych. **Kuking nie nagrywa dźwięku, nie ma endpointu na dźwięk
  ani transkrypcję i nie dostaje niczego poza tekstem, który człowiek sam
  wstawi do pola i wyśle formularzem** — od tej chwili tekst podlega celowi,
  podstawie i terminowi właściwemu dla danej czynności (przepis, wykonanie,
  wpis, komentarz, notatka, profil, kontakt, odwołanie lub moderacja). Mikrofon
  jest odblokowany w nagłówku `Permissions-Policy` tylko dla zalogowanego
  na udanym ekranie HTML z dłuższym polem
  (`ApplySecurityHeaders::TRASY_DYKTOWANIA`); gość, błędy, przekierowania,
  JSON i pozostałe ekrany nie dostają mikrofonu. Wariant zalogowanego jest
  `private, no-store`, bez nagłówków cache CDN. Nie powstaje osobny zapis
  dźwięku ani transkrypcji. Przegląd prawny rejestru przed publicznym startem
  pozostaje w #8.
- **Odbiorcy:** Railway, OpenAI — tylko treść publiczna (§3.7).
- **Termin usunięcia:** do usunięcia treści albo konta; **wcześniejsze
  wersje przepisu** krócej — wersja starsza niż 24 miesiące (data w Polsce)
  jest kasowana, poza 3 najnowszymi wersjami każdego przepisu
  (`kuking:sprzataj-wersje-przepisow`, D-333, #2024). Treść usunięta przez
  autora znika z serwisu od razu, a z bazy i z R2 (tekst, oryginały
  i warianty zdjęć) po `kuking.usuniete_tresci.retention_days` = **30 dni**
  (`kuking:sprzataj-usuniete-tresci`, audyt B5 pkt 1). Wyjątki: przepis
  ugotowany przez inną osobę zostaje pustym nagrobkiem, żeby nie zabrać
  cudzego „Ugotowałem”; treść ze zgłoszeniem albo decyzją moderacji czeka
  na retencję sprawy (36 miesięcy, §3.6). Przy usunięciu konta
  **decyduje użytkownik** (`users.delete_scope`, D-022): domyślnie tekst
  zostaje zanonimizowany („Użytkownik usunięty"), po zaznaczeniu haczyka
  jest kasowany razem z wpisami, przepisami, komentarzami, wykonaniami
  i zeszytami.

### 3.4 Zdjęcia

- **Cel:** publikowanie zdjęć potraw i zdjęć profilowych.
- **Dane:** piksele (mogą przedstawiać osoby, wnętrza, dokumenty);
  w oryginale także EXIF, w tym data i współrzędne GPS.
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Cloudflare R2 (`config/filesystems.php`), OpenAI — ale
  **wyłącznie miniatura zdjęcia publicznego wpisu**, przekodowana, bez
  EXIF-u, najwyżej 320 px; zdjęcie profilowe nie (§3.7, D-240).
- **Termin usunięcia:** do usunięcia zdjęcia przez użytkownika; **przy
  usunięciu konta kasowane są WSZYSTKIE**, razem z cache CDN-u (D-018) —
  bo anonimizacja podpisu nie zmienia niczego w pikselach. Zdjęcia
  nieprzypięte do żadnej treści kasuje `kuking:sprzataj-osierocone-zdjecia`.
  Zdjęcia treści usuniętej przez autora (oryginał i warianty) kasuje
  `kuking:sprzataj-usuniete-tresci` razem z treścią, po 30 dniach (§3.3).

### 3.5 Relacje w serwisie

- **Cel:** obserwowanie i blokowanie innych użytkowników, obserwowanie tagów.
- **Dane:** identyfikator obserwującego i obserwowanego, identyfikator
  obserwowanego tagu (`tag_follows`, bez własnego `id` — klucz `(user_id,
  tag_id)`), przy blokadzie — adres IP osoby blokującej
  (`app/Http/Controllers/SocialController.php`).
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Railway.
- **Termin usunięcia:** do usunięcia relacji albo konta. W paczce danych:
  `obserwuje`, `obserwuja_mnie`, `zablokowane_osoby`, `obserwowane_tagi`;
  to, kto zablokował to konto, jest poza paczką (§3.17).

### 3.6 Moderacja treści, zgłoszenia i odwołania (DSA)

- **Cel:** przyjęcie zgłoszenia nielegalnej treści (art. 16 DSA), decyzja
  moderatora z uzasadnieniem (art. 17 DSA), rozpatrzenie odwołania.
- **Dane:** dane zgłaszającego (także bez konta — wtedy sam adres e-mail),
  dane zgłaszanego, treść zgłoszenia, powód, decyzja, uzasadnienie,
  odwołanie i jego wynik.
- **Podstawa (wg analizy z 2.10.2026, pyt. 5):** rozpatrywanie zgłoszeń
  nielegalnych treści i obowiązkowe uzasadnienia, czyli czynności z art. 16–18
  DSA (przyjęcie zgłoszenia, potwierdzenie, uzasadnienie decyzji, zawiadomienie
  z art. 18): **art. 6 ust. 1 lit. c RODO**, ze wskazaniem przepisu
  odpowiednio do czynności. **Moderacja naruszeń własnych zasad** oraz
  dobrowolna skarga (przy zwolnieniu z art. 20): zwykle **lit. f** (konkretny
  interes: bezpieczeństwo i rzetelność społeczności, z testem równowagi), a
  dla niezbędnej obsługi relacji także właściwy zakres lit. b. Lit. c nie
  obejmuje całej moderacji obyczajowej ani dowolnie długiej retencji;
  przeniesienie podstawy retencji na lit. f jest decyzją właściciela po
  zewnętrznej ocenie prawnej — `docs/decyzje/ADR_RETENCJE.md` §10.
- **Odbiorcy:** Railway, EmailLabs (potwierdzenie przyjęcia i informacja
  o decyzji idą e-mailem), Cloudflare Turnstile (formularz zgłoszenia).
- **Termin usunięcia:** **36 miesięcy od zamknięcia sprawy**
  (`config/kuking.php` → `moderation.case_retention_months`), egzekwuje
  `kuking:sprzataj-sprawy-moderacyjne`. Sprawy otwarte nie są kasowane
  niezależnie od wieku.

### 3.7 Automatyczna wstępna ocena treści (OpenAI) — przekazanie poza EOG

- **Cel:** podniesienie do kolejki moderatora treści, które mogą dotyczyć
  przemocy, nienawiści, treści seksualnych albo samookaleczenia — po to,
  żeby jedyny moderator zobaczył je szybciej.
- **Dane, które faktycznie wychodzą** (sprawdzone w kodzie,
  `app/Moderacja/KlientOpenAI.php`): **wyłącznie oceniana treść** —
  tekst wpisu albo komentarza (przycięty do 8000 znaków, `ocenTekst()`)
  albo zdjęcie wpisu jako `data:` URI z **wariantu przekodowanego**, czyli bez
  EXIF-u i bez GPS-u (`ocenObraz()`), o dłuższym boku najwyżej 320 px
  zmierzonym z bajtów. Wychodzi **wyłącznie treść publiczna** — widoczna
  dla gościa bez konta w chwili wysyłki (`app/Moderacja/GranicaWysylki.php`).
  **Zdjęcie profilowe nie wychodzi** (D-240). Żądanie niesie dwa pola: `model`
  i `input`. **Nie wychodzi** adres e-mail, nazwa konta, identyfikator
  wpisu ani adres IP — kod nie ma gdzie ich wpisać, bo `zapytaj()` buduje
  ciało żądania wyłącznie z przekazanej treści.
- **Czego ten kanał nie robi:** wynik nie ukrywa treści, nie ogranicza jej
  zasięgu i nie blokuje konta — trafia wyłącznie do kolejki człowieka.
  To nie jest więc zautomatyzowane podejmowanie decyzji w rozumieniu
  art. 22 RODO. **Ta kwalifikacja jest do potwierdzenia przez prawnika.**
- **Podstawa:** art. 6 ust. 1 lit. f RODO — uzasadniony interes
  w bezpieczeństwie serwisu (analiza z 2.10.2026, pyt. 5 i 9: co do zasady
  lit. f). **Test równowagi do przeprowadzenia i spisania**, z uwzględnieniem
  błędów klasyfikacji, danych szczególnych kategorii, zakresu dostępu
  moderatora i braku wtórnego profilowania. Granicę publiczności sprawdzamy
  w chwili wysyłki (`GranicaWysylki`), nie przy dodaniu zadania do kolejki.
- **Odbiorca:** OpenAI. Dla klientów z EOG strona umowy to co do zasady
  **OpenAI Ireland Ltd**, nie „OpenAI, L.L.C.” (publiczny DPA OpenAI od
  1.01.2026). **DO POTWIERDZENIA PRZEZ WŁAŚCICIELA** w panelu: przyjęta
  umowa, organizacja, region. Irlandzka strona umowy nie dowodzi, że całe
  przetwarzanie zostaje w EOG.
- **Dane osobowe w treści:** nie wysyłamy identyfikatora konta, e-maila ani
  IP, ale sama treść lub zdjęcie mogą zawierać dane osobowe (nazwisko, opis
  osoby, rozpoznawalna twarz). Traktujemy to jako przetwarzanie mogące
  obejmować dane osobowe; zdanie „nic, co pozwoliłoby Cię wskazać” jest
  zbyt mocne (zmiana tekstu publicznego jest osobnym zadaniem).
- **Podstawa przekazania poza EOG:** **DO USTALENIA.** „DPF + SCC” wymaga
  dowodu: który podmiot, czy jego wpis DPF obejmuje właściwą kategorię
  danych, które SCC i moduł, jak oceniono dalsze transfery.
  **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** data sprawdzenia wpisu na liście
  `dataprivacyframework.gov` dla właściwego podmiotu i numer/data SCC.
  Do czasu wyjaśnienia możliwa jest moderacja ręczna (analiza pyt. 9); o
  pozostawieniu integracji zdecydował właściciel 2.10.2026 (D-333).
- **Termin usunięcia:** po stronie OpenAI, zgodnie z jego warunkami usługi.
  **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** okres przechowywania odczytany z
  dokumentacji **dla rzeczywistego endpointu `/v1/moderations`**, modelu i
  trybu organizacji. **Nie wpisujemy „30 dni”** (to informacja właściwa
  innym konfiguracjom API).
- **Wyłączenie jest możliwe bez zmiany kodu:** brak klucza
  (`kuking.moderation.model.klucz`) znaczy, że funkcja nie działa
  i **żadne żądanie nie wychodzi** (`KlientOpenAI::oceniamy()`).

### 3.8 Logowanie kontem Google i kontem Facebooka

- **Cel:** dodatkowa, dobrowolna droga wejścia na konto obok hasła i linku.
- **Dane:** od Google — potwierdzenie tożsamości, adres e-mail razem
  z informacją, czy jest potwierdzony, oraz imię. Zakres `profile`
  (`App\Support\Google::ZAKRES`) Google opisuje na ekranie zgody jako imię
  **i zdjęcie profilowe**, a w tokenie tożsamości może przyjść adres
  zdjęcia — kod go nie czyta, nie pobiera i nie zapisuje
  (`app/Google/KlientGoogle.php` bierze tylko `sub`, `email`,
  `email_verified` i `given_name`/`name`); polityka mówi
  o tym wprost (audyt Z11, #2283). Od Facebooka —
  identyfikator konta (inny dla każdego serwisu), imię i adres e-mail,
  **bez informacji, czy adres jest potwierdzony**, a bywa, że bez adresu
  w ogóle. W bazie zostaje identyfikator zewnętrzny i data połączenia
  (tabela `tozsamosci_zewnetrzne`). Tokenów dostępu serwis nie
  przechowuje.
- **Kod:** `app/Http/Controllers/Auth/GoogleLoginController.php`,
  `app/Http/Controllers/Auth/FacebookLoginController.php`,
  `app/Http/Controllers/Auth/FacebookDeauthorizeController.php`.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — wykonanie umowy na żądanie
  osoby, która tę drogę wybrała.
- **Odbiorcy:** Google Ireland Limited / Google LLC; Meta Platforms Ireland
  Limited. **Google i Meta występują tu jako ODRĘBNI ADMINISTRATORZY**,
  nie jako podmioty przetwarzające (analiza z 2.10.2026, §5.1 i §5.2):
  każdy przetwarza dane użytkownika dla własnych etapów i celów
  (uwierzytelnianie, bezpieczeństwo), a SAMSUFI odpowiada za użycie danych
  otrzymanych do utworzenia lub połączenia konta Kuking. Nie szukamy DPA
  Google Cloud: logowanie przez projekt OAuth nie jest hostingiem w Google
  Cloud. Analiza nie wyklucza z góry współadministrowania etapu osadzenia
  (TSUE C-40/17 Fashion ID), jeśli doszłyby SDK, piksele lub analityka
  przed kliknięciem. Obecny przepływ to zwykłe przekierowanie OAuth;
  brak dodatkowych integracji po naszej stronie **do potwierdzenia
  konfiguracją**.
- **Lista pól** (wymóg z analizy §5.2): **odbieramy** od Google `sub`,
  `email`, `email_verified`, imię; od Facebooka identyfikator, imię, e-mail.
  **Ignorujemy** adres zdjęcia z tokenu Google. **Zapisujemy** identyfikator
  zewnętrzny i datę połączenia; tokenów dostępu nie zapisujemy. Prywatnych
  zeszytów, planera i listy zakupów dostawcom nie udostępniamy.
- **Przekazanie poza EOG:** przy Google — grupa Google przetwarza dane
  także w USA na własnych podstawach jako administrator. Przy Meta
  kontrahentem jest spółka irlandzka, ale **sama tożsamość kontrahenta nie
  rozstrzyga całego przepływu ani dostępu z innego państwa**, więc rejestr
  nie twierdzi już, że „administrator nie przekazuje danych poza EOG”. To,
  co dzieje się w grupie dostawcy, dzieje się na jego własnych podstawach.
- **Termin usunięcia:** do usunięcia konta albo rozłączenia powiązania.

### 3.9 Ochrona formularzy przed automatami (Cloudflare Turnstile)

- **Cel:** odróżnienie człowieka od automatu przy **siedmiu** publicznych
  formularzach: rejestracja, logowanie, link do zalogowania, odzyskiwanie
  hasła, cofnięcie usunięcia konta, „Napisz do nas", zgłoszenie
  nielegalnej treści (`config/kuking.php` → `turnstile.miejsca`; pilnuje
  `RozjazdyAudytuZgodnosciTest::test_polityka_wymienia_kazdy_formularz_za_turnstile`).
- **Dane:** adres IP i techniczne cechy przeglądarki. **Treść formularza
  ani adres e-mail do Cloudflare nie idą.**
- **Podstawa:** art. 6 ust. 1 lit. f RODO — uzasadniony interes w obronie
  przed zakładaniem kont automatem (z testem równowagi). Osobno ocenić PKE
  i niezbędność każdego sygnału dla bezpieczeństwa formularza.
- **Odbiorca:** Cloudflare, Inc. (USA) — przekazanie poza EOG, podstawą
  DPF i SCC. **Rola mieszana** (analiza §5.3, Turnstile Privacy Addendum z
  18.06.2025): procesor dla przetwarzania sygnałów na naszą rzecz oraz
  **odrębny administrator** dla własnego celu Cloudflare, czyli ulepszania
  wykrywania botów. Ogólne zdanie „wszystkim odbiorcom powierzamy dane na
  podstawie art. 28” nie opisuje tego przetwarzania.
- **Termin usunięcia:** po stronie Cloudflare; serwis nie trzyma kopii.
- **Wyłączenie jest możliwe bez zmiany kodu:** puste klucze znaczą, że
  widget się nie renderuje i nikt nikogo nie odpytuje.

### 3.10 Bezpieczeństwo i dziennik zdarzeń

- **Cel:** wykrywanie nadużyć, próby logowania, dowód wykonania żądań
  usunięcia konta.
- **Dane:** **skrót** adresu IP (samego adresu w bazie nie ma; skrót liczony
  z kluczem żyjącym poza bazą), znacznik czasu, typ zdarzenia.
- **Podstawa:** art. 6 ust. 1 lit. f RODO (logi bezpieczeństwa i
  przeciwdziałanie nadużyciom; art. 32 RODO wyjaśnia obowiązek
  bezpieczeństwa). Wymaga minimalizacji, uzasadnienia okresu i testu
  równowagi; dla dowodów żądań usunięcia dochodzi rozliczalność, ale
  „bezterminowo, bo dziennik jest dopisywany” nie jest uzasadnieniem
  (analiza, pyt. 5 i 7). Zdanie o wpisach „na stałe” poniżej opisuje stan
  kodu, nie jest zatwierdzone przez analizę.
- **Odbiorcy:** Railway.
- **Termin usunięcia:** **12 miesięcy**
  (`config/kuking.php` → `audit_log.retention_months`), egzekwuje
  `kuking:sprzataj-audyt`. **Wpisy `account.*`** (złożenie, cofnięcie, wykonanie żądania usunięcia
  konta) nie są już bezterminowe (#2708, D-333 → D-233): podlegają tej samej
  retencji, ale `kuking:sprzataj-audyt` kasuje je dopiero po przeniesieniu do
  `potwierdzenia_zadan_rodo` (`kuking:przenies-potwierdzenia-rodo`) i nigdy dla
  konta z zabezpieczonym dowodem. Dowód obsługi żądania: `potwierdzenia_zadan_rodo`
  (numer sprawy, daty, wynik, zakres, wersja procedury, wyjątki, `konto_id`).
  **`konto_id` jest daną osobową** (wskaźnik na zanonimizowane konto i jego
  treści); termin: **36 miesięcy od zamknięcia sprawy**, kasowanie automatyczne
  (`kuking:sprzataj-potwierdzenia-rodo`, 02:00), pominięte tylko wiersze ze
  `wstrzymanie_do` w przyszłości (udokumentowane postępowanie) i konta z
  zabezpieczonym dowodem. Bez IP w całym okresie. Dziennik wykonanych wymazań
  poza bazą (niżej) żyje 120 dni.

### 3.10a Sesja logowania (tabela `sessions`)

- **Cel:** utrzymanie zalogowania między żądaniami; wylogowanie pozostałych
  urządzeń po zmianie hasła, adresu e-mail albo stanu konta.
- **Dane:** zgrubny adres IP (IPv4 do `/24`, IPv6 do `/48` —
  `App\Support\MaskaAdresuIp`, `UchwytSesjiBezPelnegoAdresu`), pełny nagłówek
  `User-Agent`, `user_id`, czas ostatniej aktywności, zawartość sesji.
- **Podstawa:** art. 6 ust. 1 lit. b RODO; w części bezpieczeństwa — lit. f.
- **Odbiorcy:** Railway.
- **Termin usunięcia:** **30 dni** od ostatniej aktywności —
  `max(kuking.sessions.retention_days, SESSION_LIFETIME)`, na produkcji
  `SESSION_LIFETIME=43200` minut (`.railway/railway.ts`); egzekwuje
  `kuking:sprzataj-sesje` co noc. Wylogowanie i wymazanie konta kasują wiersz
  od razu (audyt B5 pkt 7).
- **Ciasteczko „zapamiętaj mnie” (#2278):** każde logowanie idzie przez
  `Auth::login(..., remember: true)`, więc przeglądarka dostaje
  `remember_web_<sha1>` (zaszyfrowane: identyfikator konta, `remember_token`,
  skrót hasła) na **400 dni** od zalogowania — domyślny czas bramki Laravela
  (`SessionGuard::$rememberDuration`), bez nadpisania w kodzie. Po wygaśnięciu
  sesji przeglądarka loguje się nim sama i zaczyna nową sesję (nowy wiersz
  `sessions`). „Wyloguj” usuwa ciasteczko i rotuje `users.remember_token`, co
  unieważnia je na wszystkich urządzeniach. Polityka §2 i §5 podaje nazwę
  i termin; pilnuje `PolitykaNazywaPamiecPrzegladarkiTest` (termin czytany
  z samej bramki).

### 3.11 Powiadomienia w serwisie

- **Cel:** poinformowanie o zdarzeniach dotyczących użytkownika.
- **Dane:** treść powiadomienia, informacja o przeczytaniu.
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Railway.
- **Termin usunięcia:** **3 miesiące**
  (`config/kuking.php` → `notifications.retention_months`), egzekwuje
  `kuking:sprzataj-powiadomienia`. **Wyjątek:** powiadomienia o decyzji
  moderacyjnej i o wyniku odwołania żyją do upływu terminu na odwołanie —
  co najmniej 6 miesięcy (regulamin §8). Skrócenie tego wyjątku odbierałoby
  prawo, które jeszcze przysługuje.

### 3.12 Poczta transakcyjna

- **Cel:** potwierdzenie adresu, przypomnienie hasła, link do zalogowania,
  powiadomienia e-mailem.
- **Dane:** adres e-mail odbiorcy, treść listu. **Liczenie otwarć jest
  wyłączone** w panelu dostawcy (D-333, 2.10.2026), więc do listów nie trafia
  obrazek liczący otwarcia. Śledzenie odnośników wyłącza nagłówek
  `X-TRACKING-OFF`.
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorca:** EmailLabs (Vercom S.A., Poznań) — dane zostają w Polsce.
  Kod: `config/mail.php` (własny sterownik `emaillabs`),
  `app/Poczta/DziennyBudzetListow.php`.
- **Listy bezpieczeństwa konta (#2565):** po zmianie i po resecie hasła oraz po
  zgłoszonej zmianie adresu e-mail idzie list z potwierdzeniem. Cel: bezpieczeństwo
  konta; podstawa: wykonanie umowy (art. 6 ust. 1 lit. b RODO); dane: adres
  e-mail, imię i data zmiany. Bez zgód marketingowych i bez treści reklamowej.
- **Ślad nieudanego listu (`mail_failures`):** rodzaj listu, powód odmowy,
  zamaskowany komunikat, `user_id` odbiorcy — bez adresu i treści. Odhaczone
  ślady kasowane po `kuking.poczta.retencja_dni` (90) dniach przy kolejnym
  zapisie (`ZapiszNieudanyList`); przy wymazaniu konta `user_id` → `NULL`
  (audyt B5 pkt 9).
- **Ładunek nieudanego listu (`failed_jobs`):** list, który przepadł po
  wszystkich próbach, zostaje w `failed_jobs` razem z ładunkiem zadania —
  czyli z adresem odbiorcy i treścią listu (część listów ma ładunek
  szyfrowany kluczem aplikacji, `ShouldBeEncrypted`, ale zaszyfrowany adres
  dalej jest daną osobową). Retencja **30 dni** od `failed_at`:
  `queue:prune-failed --hours=720` codziennie o 05:20 (decyzja właściciela
  z 25.09.2026, `CzyszczenieNieudanychZadanTest`). Ani wymazanie konta,
  ani `kuking:sprzataj-zaproszenia` tych wierszy wcześniej nie kasują —
  dotyczy to także zaproszenia do założenia konta, którego sam wiersz
  `registration_invites` znika najwyżej dobę po wygaśnięciu. Polityka mówi
  to wprost od 30.09.2026 (audyt Z8, #2283). Wcześniejsze kasowanie
  (np. po `mail_failures.failed_job_uuid` przy sprzątaniu zaproszeń i przy
  wymazaniu konta) byłoby zmianą retencji — **do decyzji właściciela**.
- **Termin usunięcia:** do usunięcia konta. **DO UZUPEŁNIENIA PRZEZ
  WŁAŚCICIELA:** jak długo EmailLabs trzyma logi wysyłek i otwarć —
  to jest okres po jego stronie i widać go tylko w umowie albo w panelu.

### 3.13 Tygodniowe podsumowanie e-mailem

- **Cel:** jeden list na tydzień z tym, co i tak widać w serwisie.
- **Dane:** adres e-mail, data wysłania ostatniego listu
  (`users.weekly_digest_sent_at`), rezerwacja tygodnia wysyłki
  (`weekly_digest_sends`: konto, poniedziałek tygodnia, chwila rezerwacji).
- **Podstawa:** **art. 6 ust. 1 lit. a RODO — zgoda** (oraz zgoda z PKE na
  informację handlową; analiza z 2.10.2026, pyt. 5 i 14). Zgoda na wysyłkę nie
  obejmuje pomiaru otwarć, który jest wyłączony (D-333). Na zgodzie opiera
  się też §3.18 (urodziny i list z życzeniami). Wycofanie: odnośnik na dole
  każdego listu, bez logowania i bez pytania o powód.
- **Odbiorcy:** Railway, EmailLabs.
- **Termin usunięcia:** data ostatniej wysyłki i rezerwacja ostatniego
  tygodnia żyją tak długo jak konto — zapis ostatniej wysyłki, nie historia:
  `OdbiorcyDigestu::zarezerwuj()` kasuje starsze rezerwacje tej osoby w tej
  samej transakcji co nową (bariera potrzebuje tylko bieżącego tygodnia),
  a `EraseAccountData` kasuje rezerwacje i zeruje datę (#2280; do 30.09.2026
  każdy tydzień dokładał wiersz, którego nic nie kasowało, także po wymazaniu).
  Test: `RezerwacjePodsumowaniaNieSaHistoriaTest`. Treść listu nie jest
  archiwizowana.

### 3.14 Formularz „Napisz do nas"

- **Cel:** przyjęcie zgłoszenia usterki albo pytania i odpisanie na nie.
- **Dane:** treść wiadomości, adres e-mail (jeśli podany), adres strony
  w serwisie bez części po znaku zapytania, numer wydania serwisu,
  treść i data naszej odpowiedzi. **Adresu IP ani danych przeglądarki
  serwis tu nie zapisuje.**
- **Podstawa:** art. 6 ust. 1 lit. f RODO; przy osobie z kontem także
  lit. b.
- **Odbiorcy:** Railway, EmailLabs, Cloudflare Turnstile.
- **Termin usunięcia:** **12 miesięcy od załatwienia sprawy**
  (`config/kuking.php` → `kontakt.retention_months`), egzekwuje
  `kuking:sprzataj-wiadomosci`. Wiadomości niezałatwione nie są kasowane
  w ogóle.

### 3.15 Statystyka odwiedzin (Cloudflare Web Analytics)

- **Cel:** wiedza, czy serwis komukolwiek się przydaje.
- **Dane:** adres otwieranej strony i adres odnośnika — **oba bez części po
  znaku zapytania**, rodzaj i wersja przeglądarki, czasy wczytania; kraj
  dolicza Cloudflare z samego połączenia. **Bez ciasteczek i bez zapisu
  na urządzeniu**; identyfikator odsłony losowany w pamięci na jedno
  wczytanie strony.
- **Kod:** `app/Support/AnalitykaCloudflare.php`; bezciasteczkowość pilnują
  `AnalitykaBezCiasteczekTest` i `WdrozenieAnalitykiOdwiedzinTest`.
- **Podstawa:** art. 6 ust. 1 lit. f RODO, jeśli test równowagi to
  uzasadnia. **PKE oceniamy odrębnie:** analiza z 2.10.2026 (pyt. 15) uznaje,
  że brak ciasteczek to za mało, bo art. 399 PKE obejmuje też odczyt informacji
  z urządzenia (skrypt wysyła adres strony, referrer i cechy przeglądarki), a
  stanowiska UODO zatwierdzającego taki wariant nie ma. Dotychczasowe
  twierdzenie „PKE nie wchodzi w grę” (D-092, `COMPLIANCE.md` §5.5) nie jest
  już podtrzymywane. Właściciel zdecydował 2.10.2026, że analityka zostaje i
  przyjmuje to ryzyko (D-333).
- **Odbiorca:** Cloudflare, Inc. (USA) — przekazanie poza EOG, DPF i SCC.
- **Sprzeciw (art. 21, #2277):** zalogowana osoba klika „Nie licz mnie
  w statystykach” (`/ustawienia/prywatnosc`, `users.sprzeciw_statystyk_at`);
  układ strony nie wstawia jej wtedy skryptu
  (`AnalitykaCloudflare::widzNieSprzeciwilSie()`). Gościa nie da się rozpoznać
  bez zapisu na urządzeniu — polityka §3 mówi mu, że skrypt z
  `static.cloudflareinsights.com` może zablokować w przeglądarce. Test:
  `SprzeciwWobecStatystykTest`.
- **Termin usunięcia:** agregaty po stronie Cloudflare; serwis nie trzyma
  kopii.

### 3.16 Własne sygnały produktowe

- **Cel:** sprawdzenie, czy ludzie wracają i czy funkcje działają.
- **Dane:** zdarzenia korzystania z aplikacji, w miarę możliwości bez
  danych wskazujących wprost; osobno jedna nadpisywana data ostatniej
  wizyty na konto.
- **Podstawa:** art. 6 ust. 1 lit. f RODO.
- **Odbiorcy:** Railway. **Nikt poza nim** — te liczby powstają w naszej
  bazie i nigdzie nie wychodzą.
- **Termin usunięcia:** **90 dni**
  (`config/kuking.php` → `analytics.signal_retention_days`), egzekwuje
  `kuking:sprzataj-sygnaly`. Dane zbiorcze zostają dłużej.
- **Po wymazaniu konta (#1324):** zdarzenia zostają do końca tych 90 dni,
  ale `EraseAccountData` ustawia w nich `user_id = NULL` w tej samej
  transakcji co wymazanie (ponowienie wymazania domyka też sygnały sprzed
  poprawki), a `ZapiszSygnal` zapisuje sygnał wymazanego konta bez
  `user_id` (`FOR SHARE` na wierszu konta, sprawdzenie `data_erased_at`).
  Liczniki zbiorcze się nie zmieniają. Test:
  `WymazanieKontaOdpinaSygnalyProduktoweTest`.
- **Sprzeciw (art. 21, #2277):** po kliknięciu „Nie licz mnie w statystykach”
  `ZapiszSygnal` nie zapisuje zdarzeń tej osoby wcale (sprawdzenie pod tą samą
  blokadą `FOR SHARE` co `data_erased_at`), `ZanotujOstatniaWizyte` nie
  zapisuje daty wizyty, a samo zgłoszenie zeruje `ostatnio_widziany_at`
  i odpina zapisane zdarzenia (`user_id = NULL`, jak przy wymazaniu).
  Cofnięcie („Licz mnie znowu”) niczego nie odtwarza. Skutek uboczny:
  zachęta do instalacji aplikacji liczy powrót z tej samej daty, więc po
  sprzeciwie się nie pokazuje — polityka §2 to mówi. Kolumna
  `users.sprzeciw_statystyk_at` jest w paczce (`konto`); rollback migracji
  odmawia przy choć jednym sprzeciwie (D-088). Test: `SprzeciwWobecStatystykTest`.

### 3.17 Obsługa praw osób — eksport i usunięcie konta

- **Cel:** realizacja art. 15, 17 i 20 RODO.
- **Dane:** paczka z danymi użytkownika, zamówienie usunięcia konta,
  zakres usunięcia (`users.delete_scope`).
- **Podstawa:** art. 6 ust. 1 lit. c RODO — obowiązek prawny (odpowiedź na
  żądanie dostępu lub usunięcia). Zachowanie minimalnego śladu po załatwieniu
  sprawy wymaga własnego uzasadnienia i terminu (rozliczalność, lit. f), a nie
  jest objęte lit. c automatycznie (analiza, pyt. 5 i 7).
- **Odbiorcy:** Railway, Cloudflare R2 (paczka leży na dysku obiektowym).
- **Termin usunięcia:** paczka **7 dni**, kasuje `kuking:sprzataj-eksporty`
  (także plik próby, która padła przed zapisaniem paczki); przy wymazaniu
  konta znika od razu cały katalog paczek konta (audyt B5 pkt 4);
  konto po karencji 30 dni — `kuking:usun-wygasle-konta`.
- **Dziennik wykonanych wymazań (poza bazą, audyt B5 pkt 3):** obiekt
  `dziennik-wymazan/<user_id>.json` na dysku `kuking.dziennik_wymazan.dysk`
  (Cloudflare R2, bucket eksportów) — identyfikator konta, chwila wymazania,
  wykonany zakres; bez e-maila i nazwy. Cel: ponowne wymazanie po odtworzeniu
  bazy z kopii (`kuking:wymaz-ponownie`, `docs/infra/KOPIE_I_ODTWORZENIE.md`
  §3.1). Termin: **120 dni** od wymazania (dłużej niż najstarsza kopia),
  przycina `kuking:dziennik-wymazan`. Podstawa: art. 6 ust. 1 lit. c w zw.
  z art. 17 RODO.
- **Zakres paczki (art. 15 i 20, #953, #1816):** źródłem prawdy jest
  `App\Domain\Users\Exports\InwentarzDanychKonta` — test
  `EksportObejmujeKazdaTabeleKontaTest` oblewa, gdy tabela z kolumną
  wskazującą na konto nie ma tam rozstrzygnięcia. **W paczce** (`dane.json`):
  konto, profil, przepisy z wcześniejszymi wersjami, wpisy, „Ugotowałem”,
  komentarze, zeszyty, obserwowane i zablokowane osoby, **obserwowane tagi**,
  **„Co mam w domu”**, **ukrycia**, **reakcje** (własne i otrzymane),
  powiadomienia, zdjęcia, dziennik zgód, połączone konta, sesje, urządzenia,
  zdarzenia w serwisie, **wiadomości „Napisz do nas” z naszymi odpowiedziami**,
  **zgłoszenia własne, decyzje moderacji i odwołania**, planer, importy.
  **Tylko na żądanie** (`kategorie_poza_paczka`): dziennik bezpieczeństwa,
  zgłoszenia cudzych treści o tej osobie, notatki moderacji i obsługi,
  powiadomienia, które o jej działaniach dostały inne osoby, ślad nieudanych
  wysyłek poczty, rejestr żądań RODO, czynności moderatora; kto zablokował
  albo ukrył to konto — nie wydajemy (art. 15 ust. 4). Polityka §4 mówi to
  samo; test: `PolitykaOpisujePaczkeUkryciaIReakcjeTest`. Wcześniejszy zapis
  „paczka nie zawiera ośmiu kategorii” (`DECYZJE_WLASCICIELA_R1_R6_DPA.md`
  §R1) jest nieaktualny.

### 3.18 Urodziny — życzenia od gospodarza (issue #1755)

- **Cel:** życzenia urodzinowe od gospodarza serwisu.
- **Dane:** dzień i miesiąc urodzin, **bez roku** (`users.birthday_day`,
  `users.birthday_month`).
- **Podstawa:** art. 6 ust. 1 lit. a RODO — zgoda wyrażona dobrowolnym
  podaniem daty (analiza z 2.10.2026, pyt. 5: możliwa lit. a przy obecnym
  modelu; samo wpisanie jest działaniem potwierdzającym tylko przy jasnej
  informacji, a publikacja i e-mail wymagają rozdzielenia wyborów, co tu
  jest spełnione osobnymi polami); wycofanie przyciskiem „Usuń datę” w `/ustawienia/urodziny`.
- **List z życzeniami (etap c):** wyłącznie za **osobną** zgodą
  (`users.wants_birthday_email`, domyślnie `false`; podanie daty zgody na
  list nie daje). Każda zmiana zgody zapisuje wiersz w dzienniku zgód
  z celem `zyczenia_urodzinowe` (D-072). Dane: adres e-mail i dzień
  ostatniej wysyłki (`users.birthday_email_sent_on`). Wycofanie: odnośnik
  w liście bez logowania, odznaczenie pola albo „Usuń datę”.
- **Przypomnienie obserwującym (etap d):** tylko po jawnym włączeniu
  (`users.birthday_visible_to_followers`, domyślnie `false`). Wtedy
  w dniu urodzin obserwujący dostają powiadomienie w serwisie
  (`notifications`, typ `birthday.today`, retencja jak §3.11) — bez roku,
  bez wpisu w feedzie. Kategoria odbiorców: osoby obserwujące.
- **Odbiorcy:** Railway; przy liście także EmailLabs.
- **Termin usunięcia:** do usunięcia daty przez osobę albo do wymazania
  konta (`EraseAccountData` zeruje daty i dzień wysyłki oraz wycofuje zgodę
  z wpisem w dzienniku). W eksporcie: `konto.urodziny`,
  `konto.pokazuj_zyczenia_urodzinowe`, `konto.chce_zyczen_urodzinowych_mailem`,
  `konto.pokazuj_urodziny_obserwujacym`.

### 3.19 Dziennik serwera (błędy techniczne)

- **Cel:** wykrywanie i naprawa błędów technicznych.
- **Dane:** zapis błędu (bez zamierzonego zbierania treści prywatnych),
  kod żądania (`docs/infra/MONITORING_BLEDOW.md`).
- **Podstawa:** art. 6 ust. 1 lit. f RODO.
- **Odbiorcy:** Railway. Produkcja pisze dziennik na `stderr`
  (`.railway/railway.ts` → `LOG_CHANNEL`), a Railway przechwytuje
  `stdout`/`stderr` do własnego narzędzia dzienników — wpisy **przeżywają**
  restart i wymianę instancji.
- **Termin usunięcia:** okres przechowywania dzienników u Railway, zależny
  od planu konta. **Plan Hobby — 7 dni** (decyzja właściciela 24.09.2026;
  liczba wg dokumentacji Railway: Hobby 7, Pro 30). Przy publicznym starcie
  produkcji — przejście na Pro, 30 dni. Procedura po zmianie planu albo
  odbiornika: `docs/DEPLOYMENT.md` → „Dziennik serwera i polityka
  prywatności”.
- **Kanał alarmów na Discordzie (Discord Inc., USA) — działa.** Właściciel
  potwierdził 29.09.2026 (D-333, wiersz „Odbiorca alarmów operacyjnych”),
  że `LOG_BLAD_WEBHOOK_URL` jest ustawiony na produkcji i alarmy dochodzą.
  Na kanał idzie **dzwonek, nie zapis błędu**: treść jest budowana
  wyłącznie z listy dozwolonych pól — klasa wyjątku, kod błędu, plik:linia,
  **wzorzec** trasy (nie adres z parametrami), odcisk i ślad bez argumentów
  (`App\Logging\WebhookBleduHandler`); przy „Napisz do nas” tylko rodzaj
  i identyfikator wiadomości, bez treści i adresu (`DzwonekOperatora`);
  czujki wysyłają liczby i stany (`App\Domain\Monitoring\KanalAlarmowy`).
  Pilnują tego `BladTrafiaNaWebhookBezDanychOsobowychTest`
  i `WiadomoscNaWebhookuBezDanychOsobowychTest`. Discord jest więc
  **odbiorcą technicznym bez danych osobowych** (§4). Czy mimo to wymienić
  go w polityce prywatności — **do decyzji właściciela / prawnika (#8)**;
  dziś polityka go nie wymienia, bo nie przekazujemy mu danych osobowych.
  Gdyby na kanał miała kiedyś pójść dana osobowa (adres, treść, nazwa
  konta), to już nie jest poprawka kanału, tylko nowy odbiorca: wpis
  w polityce, §4, §5 i podstawa przekazania do USA — zanim pójdzie
  pierwsza wiadomość.
- **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA (#994):**
  - fizyczna lokalizacja (kraj/region) przechowywania logów przez Railway —
    polityka mówi dziś, że tego nie potwierdziliśmy;
  - czy istnieją eksporty logów poza Railway (drain, pobrane pliki).

### 3.20 Ukrycia wpisów i osób (issue #1810, D-278)

- **Cel:** układanie własnego ekranu: „Ukryj ten wpis”, „Ukryj tę osobę”.
- **Dane:** identyfikator ukrywającego, ukrytego wpisu albo ukrytej osoby,
  `hidden_until` (`hides`); domyślnie **30 dni**
  (`config/kuking.php` → `ukrycia.dni`), `NULL` = „Zostaw ukryte”.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — **do potwierdzenia przez
  prawnika** (funkcja wybrana przez osobę; alternatywa: lit. f).
- **Odbiorcy:** Railway. Nikt poza osobą ukrywającą tego nie widzi:
  tabelę czytają tylko filtry strumieni tego widza, lista `/ustawienia/ukryte`,
  eksport i wymazanie konta — **nigdy** moderacja ani analityka
  (`UkryjWpisIOsobeTest::test_bez_agregacji…`). Nie ma licznika „ilu ukryło”.
- **Termin usunięcia:** do „Przywróć” albo do wymazania konta
  (`EraseAccountData` kasuje wiersze po stronie widza). **Luka:** wiersze po
  terminie nic nie ukrywają, ale osobne czyszczenie ich dziś nie istnieje —
  zostają do wymazania konta; polityka mówi to wprost. Do decyzji
  właściciela, czy dodać sprzątanie.
- **Eksport:** `hides.user_id` → `ukryte`; `hides.hidden_user_id` (kto ukrył
  to konto) — na żądanie, nie wydajemy (art. 15 ust. 4).

### 3.21 Reakcja „Smakowicie wygląda” (issue #1813, D-280)

- **Cel:** lekka reakcja pod cudzym wpisem.
- **Dane:** wpis, autor reakcji, chwila (`post_reactions`); `notified_at` —
  kiedy weszła do zbiorczego powiadomienia.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — **do potwierdzenia przez
  prawnika**.
- **Odbiorcy:** Railway; **pod wpisem nazwę osoby, która zareagowała, widzi
  każdy, kto wpis widzi, także bez logowania** (bez liczby, bez osób z blokadą
  autora albo widza, `Smakowicie::ktoDla()`). Autor dostaje jedno
  zbiorcze powiadomienie dziennie (`kuking:powiadom-smakowicie`, retencja jak
  §3.11). Żadna lista nie sortuje wpisów według reakcji.
- **Termin usunięcia:** do cofnięcia reakcji, do usunięcia wpisu (twarde
  usunięcie kasuje wiersz kaskadą) albo do wymazania konta
  (`EraseAccountData`).
- **Eksport:** `moje_reakcje`, `reakcje_otrzymane` (nazwa tylko przy osobach
  widocznych dla autora), `reakcje_otrzymane_od_osob_niewidocznych` (liczba).

### 3.21a „Dziękuję” pod komentarzem (issue #2355, F11)

- **Cel:** autor wpisu, przepisu albo wykonania kwituje cudzy komentarz
  jednym kliknięciem, bez pisania odpowiedzi.
- **Dane:** komentarz, autor treści, który podziękował, chwila
  (`comment_thanks`); powiadomienie `comment.thanked` dla autora komentarza
  (jak §3.11).
- **Podstawa:** art. 6 ust. 1 lit. b RODO — **do potwierdzenia przez
  prawnika**.
- **Odbiorcy:** Railway; stan „podziękowano” widzą wyłącznie dwie osoby —
  dziękujący i autor komentarza. Bez licznika i bez wpływu na kolejność
  treści.
- **Termin usunięcia:** do usunięcia komentarza (twarde usunięcie kasuje
  wiersz kaskadą) albo do wymazania konta — każdej ze stron (`EraseAccountData`).
  Wycofania samego podziękowania nie ma.
- **Eksport:** `moje_podziekowania`; otrzymane — w `powiadomienia`.

### 3.22 Lista „Co mam w domu” (V2, D-285)

- **Cel:** „Co ugotuję z tego, co mam” — podpowiedź przepisów.
- **Dane:** nazwa produktu wpisana przez osobę i data dodania
  (`pantry_items`); kolumny `rdzenie` i `klucz` są wyliczone z nazwy.
  Od #1903 (D-333) opcjonalnie także: termin z opakowania i jego rodzaj
  (`expires_on`, `expiry_kind`: `use_by` „Należy zużyć do”, `best_before`
  „Najlepiej spożyć przed”), ilość jako wolny tekst do 40 znaków
  (`quantity_note`) i oznaczenie „mrożone” (`frozen`). Termin jest notatką
  osoby; serwis nie ocenia, czy produkt nadaje się do jedzenia.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — **do potwierdzenia przez
  prawnika**. Nazwy produktów i notatki mogą ujawniać dietę lub alergię
  konkretnej osoby, czyli potencjalnie dane o zdrowiu: wtedy lit. b nie
  zastępuje przesłanki z art. 9 ust. 2 (analiza z 2.10.2026, pyt. 5).
- **Sobotnie przypomnienie o produktach do zużycia (#1903):** wyłącznie za
  **osobną** zgodą (`users.wants_pantry_reminder`, domyślnie `false`; założenie
  listy ani ustawienie terminu zgody nie daje). Podstawa: art. 6 ust. 1 lit. a
  RODO. Każda zmiana zgody zapisuje wiersz w dzienniku zgód z celem
  `przypomnienie_spizarni` (D-072). Jeden list tygodniowo, w sobotę rano,
  tylko gdy jest co wymienić; w liście nazwy, ilości i terminy pilnych
  produktów (termin minął albo upływa w ciągu 3 dni, bez mrożonych). Dane
  techniczne: skrót adresu z datą wysyłki w `przypomnienia_dobowe` (do 30
  dni). Bez push (D-303). Wycofanie: odnośnik w liście bez logowania albo
  odznaczenie pola na stronie „Co mam w domu”; `EraseAccountData` gasi zgodę.
- **Odbiorcy:** Railway; przy liście z przypomnieniem także EmailLabs. Lista
  jest prywatna (`PantryItemPolicy`, także termin i ilość), porównanie ze
  składnikami przepisów odbywa się w bazie; nic nie jest wysyłane do
  podmiotów trzecich poza opisanym wyżej listem do samej osoby.
- **Termin usunięcia:** do usunięcia produktu albo wymazania konta
  (`EraseAccountData`).
- **Eksport:** `co_mam_w_domu` (z terminem, rodzajem terminu, ilością i
  „mrożone”) oraz `konto.chce_sobotniego_przypomnienia_o_produktach`.

### 3.23 Odczyt przepisu przez model na żądanie (OpenAI) — zdjęcie kartki, tekst strony, skan PDF — przekazanie poza EOG (issue #2031)

**Stan: kod gotowy, funkcja NIEWŁĄCZONA.** Żadne żądanie nie wychodzi, dopóki
`OPENAI_IMPORT_KEY` (`kuking.import.model.klucz`) jest pusty
(`KlientLuna::skonfigurowany()`), a ma pozostać pusty do podpisania umowy
powierzenia (poniżej i `REJESTR_UMOW_POWIERZENIA.md` §2.5). Wiersz stoi w
rejestrze **przed** włączeniem, bo polityka obiecuje opisać nowy cel, zanim
trafi tam pierwszy rekord.

- **Cel:** przepisanie przepisu do **prywatnego szkicu** na wyraźne żądanie
  osoby, która dodaje źródło. Trzy różne źródła, trzy osobne zgody — zgoda na
  jedno **nie obejmuje** pozostałych (D-296, D-300 pkt 9):

| Źródło | Co dokładnie wychodzi (kod) | Zgoda i jej dowód | Kiedy w ogóle wychodzi |
|---|---|---|---|
| **Zdjęcie kartki lub zeszytu** | stała instrukcja, schemat odpowiedzi i zdjęcie jako JPEG ≤ 2000 px z wariantu przekodowanego, bez EXIF/XMP/GPS; `store: false` (`KlientLuna`, `ObrazDoOdczytu`, `OdczytKartki`) | **trwała** zgoda `odczyt_ai` w `dziennik_zgod` (D-296) z wersją informacji (`InformacjaOdczytuAi::WERSJA`); sprawdzana przed każdą wysyłką; wycofanie w ustawieniach prywatności | zawsze, gdy osoba wybrała „Przepisz z kartki” |
| **Tekst strony z adresu** | stała instrukcja, schemat odpowiedzi i **ponumerowane wiersze czystego tekstu strony** — najwyżej 12 000 znaków i 400 wierszy, bez HTML-a, bez adresu strony, bez zdjęć, bez nawigacji, formularzy i sekcji komentarzy czytelników (`TekstStrony`, `ZadanieFragmentow`); model odsyła tylko numery wierszy i etykiety, tekst szkicu składa PHP z oryginału; `store: false` | zgoda **jednorazowa**, zaznaczana w formularzu adresu, z wersją informacji (`InformacjaTekstuZrodlaAi::WERSJA`, ukryte pole formularza); nieaktualna albo brakująca wersja = zgody nie ma; dowód: `proby_importu.zgoda_ai_at` (data) | tylko gdy strona **nie ma** danych JSON-LD `Recipe` (inaczej odczyt lokalny, nic nie wychodzi) |
| **Skan PDF (bez warstwy tekstu)** | stała instrukcja, schemat odpowiedzi i **obrazy stron** pliku (JPEG ≤ 1600 px, `pdftoppm`, bez metadanych pliku; strony w granicach `kuking.import.pdf.max_stron`, najwyżej 5) (`OdczytajSkanPdf`); `store: false` | jak wyżej — zgoda jednorazowa w formularzu PDF, ta sama wersjonowana informacja | tylko dla PDF **bez** warstwy tekstu (PDF z tekstem odczytujemy lokalnie) |

- **Czego nie wysyłamy w żadnym z trzech źródeł:** e-maila, nazwy konta, adresu
  IP, identyfikatorów konta, przepisu i zlecenia, pola `user`/`safety_identifier`
  (`KlientLuna`). Adres strony nie wychodzi przy tekście strony.
- **Kategorie danych:** treść cudzej strony albo kartki/PDF; **przypadkowo**
  dane osób trzecich (imiona, telefony, adresy) i możliwe dane o zdrowiu
  (art. 9). Na stronie internetowej ograniczamy to wycięciem komentarzy
  i formularzy; na kartce i w skanie PDF — prośbą o zasłonięcie albo usunięcie
  takich stron przed dodaniem (informacja przy zgodzie). Cudzy tekst strony
  jest wysyłany do wskazania granic wierszy, nie do przepisania — a wynik jest
  wyłącznie prywatnym szkicem (D-300).
- **Podstawa:** art. 6 ust. 1 lit. a RODO — zgoda. Dowód: dla kartki wpis
  `odczyt_ai` w `dziennik_zgod` (data, wersja polityki, źródło zapisu);
  dla tekstu strony i skanu PDF — data zgody przy próbie importu
  (`proby_importu.zgoda_ai_at`), a **wersję informacji, którą osoba widziała,
  odtwarza się z daty** według historii `InformacjaTekstuZrodlaAi::WERSJA`
  (wersja jest datą zmiany treści, a formularz z inną wersją zgody nie
  zapisuje). Zgoda na jedno źródło **nie odblokowuje** pozostałych —
  pilnuje tego `PlatnyOdczytImportu` i test
  `ZgodaPrzedTekstemZrodlaTest`.
- **Odbiorca:** OpenAI (dla EOG co do zasady OpenAI Ireland Ltd; strona
  umowy do potwierdzenia przez właściciela, zob. §3.7) — podmiot
  przetwarzający; **DPA: DO PODPISANIA PRZED WŁĄCZENIEM** (`REJESTR_UMOW_POWIERZENIA.md` §2.5).
- **Przekazanie poza EOG:** EU-US Data Privacy Framework + SCC (jak §3.7).
  **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** data sprawdzenia wpisu OpenAI na
  liście DPF, dokument SCC i jego data, ewentualne Zero Data Retention.
- **Terminy usunięcia:**
  - po naszej stronie: zdjęcie kartki i szkic — jak treść autora (do usunięcia
    przepisu albo konta); zlecenie odczytu kartki (`importy_przepisow`) — 90 dni,
    surowa odpowiedź modelu — 30 dni; księga rezerwacji budżetu
    (`ai_rezerwacje`, tylko kwoty i identyfikator próby, bez treści) — 90 dni;
    księga prób importu (`proby_importu`, w tym data zgody) — nie krócej niż
    31 dni, domyślnie 90 dni (`kuking:sprzataj-importy`, `PrzedawnioneImporty`);
    **obrazy stron PDF i plik nie są zapisywane** — powstają w katalogu
    tymczasowym i są kasowane po odczycie; **odpowiedź modelu dla tekstu
    strony i PDF nie jest zapisywana** (model oddaje granice wierszy albo
    odczytany przepis, który trafia do szkicu); dziennik serwera przy błędzie
    modelu zapisuje tylko nazwę zadania i kod odpowiedzi, **bez treści**
    (`KlientLuna`, §3.19);
  - po stronie OpenAI: `store: false` znaczy, że odpowiedź nie jest zapisywana
    do późniejszego pobrania — to **nie jest** obietnica zerowej retencji.
    **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** okres przechowywania danych
    wysłanych do API (m.in. na potrzeby wykrywania nadużyć), odczytany z
    aktualnych warunków; po uzupełnieniu — zdanie w polityce prywatności i
    **podbicie `InformacjaTekstuZrodlaAi::WERSJA`** (i `InformacjaOdczytuAi::WERSJA`),
    bo zmienia się fakt, o którym mówi informacja przy zgodzie.
- **Środki:** osobny klucz API (osobny projekt OpenAI z limitem wydatków),
  host i ścieżka w kodzie (D-250), wspólny budżet dzienny i miesięczny w bazie
  (D-297), limit 5/30 prób na osobę wspólny dla wszystkich źródeł importu
  (D-300 pkt 6), rezerwacja budżetu przed wysłaniem, brak auto-publikacji
  (D-298, D-300 pkt 1 i 8).
- **Wyłączenie bez zmiany kodu:** pusty `OPENAI_IMPORT_KEY` (model),
  `KUKING_IMPORT_URL=false`, `KUKING_IMPORT_PDF=false` (D-300).

### 3.24 Zapamiętany postęp gotowania między urządzeniami (V2, issue #2016)

- **Cel:** dokończenie gotowania na innym urządzeniu tego samego konta.
- **Dane:** identyfikator konta i przepisu, lista identyfikatorów odhaczonych
  kroków, lista identyfikatorów składników zaznaczonych jako przygotowane,
  wybrana liczba porcji, numer rewizji, daty ostatniej zmiany i wygaśnięcia
  (`cooking_progress`). Tylko na świadome włączenie przez osobę, osobno dla
  każdego przepisu; domyślnie (i dla gości) postęp zostaje w sesji przeglądarki.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — funkcja, którą osoba włącza na
  własne życzenie, tak jak „Co mam w domu” i planer; tak mówi też polityka §2
  (#2281, 30.09.2026 — wcześniej stała tu lit. a, której polityka nie
  podawała). Weryfikacja przez prawnika zostaje w #8.
- **Odbiorcy:** Railway. Dane widzi wyłącznie właściciel
  (`CookingProgressPolicy`); nic nie jest wysyłane do podmiotów trzecich.
- **Termin niedostępności:** po 24 godzinach od ostatniej zmiany
  (`kuking.cooking_progress.retention_hours`; odczyt ignoruje wiersz już przy
  `expires_at <= now()`). **Fizyczne usunięcie:** następny nocny przebieg
  `kuking:sprzataj-postep-gotowania` o 03:00 UTC, przy prawidłowo działającym
  harmonogramie najpóźniej po około 48 godzinach od zmiany;
  wyłączenie funkcji przez osobę albo wymazanie konta (`EraseAccountData`).
- **Eksport:** `postep_gotowania` (przepis, numery odhaczonych kroków, wybrana
  liczba porcji, przygotowane składniki, daty; tytuł przepisu i teksty składników
  tylko gdy przepis jest dziś widoczny dla osoby).

### 3.24a Wspólne gotowanie (V2, issue #2385)

- **Cel:** gotowanie jednego przepisu przez gospodarza i do trzech pomocników ze wspólnym postępem kroków.
- **Dane:** identyfikatory gospodarza, pomocników i przepisu, identyfikatory
  odhaczonych kroków z informacją, kto i kiedy je odhaczył, numer rewizji,
  termin wygaśnięcia, skrót SHA-256 wielorazowego (do trzech osób) linku zaproszenia
  (`cooking_sessions`, `cooking_session_participants`, `cooking_session_steps`,
  `cooking_session_invitations`). Tylko na świadome założenie sesji przez
  gospodarza i przyjęcie zaproszenia przez pomocnika. Bez wiadomości, bez adresu
  IP, bez publikacji.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — funkcja uruchamiana na własne życzenie.
- **Odbiorcy:** Railway. Dane widzą wyłącznie osoby w sesji (gospodarz i do trzech pomocników; każdy widzi nazwy pozostałych)
  (`CookingSessionPolicy`; obcy i moderator dostają 404). Każde wejście sprawdza
  też `RecipePolicy::view` — link nie daje dostępu do treści, której osoba nie
  mogłaby zobaczyć. Nic nie jest wysyłane do podmiotów trzecich.
- **Termin niedostępności:** po 24 godzinach od założenia
  (`kuking.wspolne_gotowanie.retencja_godziny`; `expires_at <= now()` oznacza
  sesję wygasłą). **Fizyczne usunięcie:** następny nocny przebieg
  `kuking:sprzataj-wspolne-gotowanie` o 02:30 UTC, przy prawidłowo działającym
  harmonogramie najpóźniej po około 48 godzinach od założenia;
  zakończenie przez gospodarza (kasuje od razu), blokada między osobami (kończy
  udział pomocnika wobec gospodarza, a gdy zablokowani są dwaj pomocnicy — udział zablokowanego), wymazanie konta (`EraseAccountData`).
- **Eksport:** `wspolne_gotowanie` (rola, tytuł przepisu tylko gdy widoczny dla
  osoby, numery kroków odhaczonych przez tę osobę, daty). Bez danych drugiej osoby.
- **Projekt i uzasadnienia:** `docs/product/PROJEKT_WSPOLNE_GOTOWANIE_2385.md`.

### 3.25 Plan na tydzień (V2, #27, D-310)

- **Cel:** prywatny plan posiłków jednej osoby.
- **Dane:** dzień, przepis albo krótka notatka (`meal_plan_entries`: `day`,
  `recipe_id`, `label` do 120 znaków; od #2549 także `note` — prywatny dopisek
  przy pozycji z przepisem, do 80 znaków).
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Railway. Widzi wyłącznie właściciel.
- **Termin usunięcia:** do usunięcia pozycji albo konta (`EraseAccountData`
  kasuje plan bezwarunkowo). Automatycznej retencji starych pozycji nie ma —
  polityka §2 tego nie obiecuje.
- **Eksport:** `planer`.

### 3.26 Wspólne zeszyty (V2, #1743, D-302)

- **Cel:** zapraszanie innych osób do zapisywania w swoim zeszycie.
- **Dane:** zaproszenie (`collection_invitations`: kto zaprasza, kogo — albo
  odnośnik bez adresata — do którego zeszytu, status, termin ważności, chwila
  odpowiedzi), członkostwo (`collection_members`), podpis „kto dodał”
  przy pozycji (`collection_items.added_by_id`). Osoby w zeszycie widzą się
  nawzajem; zaproszona osoba dostaje powiadomienie
  (`Notification::TYPE_COLLECTION_INVITED`). Obcy przy publicznym zeszycie nie
  widzi współpracowników.
- **Podstawa:** art. 6 ust. 1 lit. b RODO dla wybranej funkcji. Osobnej
  oceny wymaga informacja o zaproszonych nieużytkownikach i kontakt z nimi
  (analiza z 2.10.2026, pyt. 5); notatki w zeszycie mogą dotyczyć zdrowia
  (art. 9 ust. 2, zob. akapit na początku §3).
- **Odbiorcy:** Railway.
- **Termin usunięcia:** członkostwo — do wyjścia, usunięcia przez właściciela,
  blokady między osobami (`ZerwijWspoldzielenie::miedzy()`), usunięcia zeszytu
  albo konta (`ZerwijWspoldzielenie::przyWymazaniu()`). **Zaproszeń odrzuconych
  i wygasłych nic nie kasuje** poza usunięciem zeszytu albo konta którejś ze
  stron — polityka §2 mówi to wprost (#2281). Retencja zaproszeń to osobna
  decyzja.
- **Eksport:** `zeszyty_udostepnione_mi`, `zaproszenia_do_zeszytow`.

### 3.27 Wczytanie własnej paczki z danymi (V2, #1985)

- **Cel:** przeniesienie własnych przepisów, wpisów i zeszytów z paczki
  eksportu Kuking (art. 20 w drugą stronę).
- **Dane:** wybrany ZIP w prywatnym magazynie (`MagazynPaczek`, dysk `local`,
  `import-paczek/<id osoby>/`) do zatwierdzenia; po zatwierdzeniu treści jak
  w §3.3 oraz znacznik pochodzenia (`wczytane_z_paczki`: rodzaj, odcisk,
  wskaźnik treści — bez treści).
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Railway.
- **Termin niedostępności ZIP:** po przekroczeniu
  `kuking.import_paczki.przechowanie_godzin` = **2 godziny** od zapisu
  (porównanie `mtime < now() - 2 godziny`; dokładnie na granicy plik nadal
  jest dostępny). **Fizyczne usunięcie:** po wczytaniu, odrzuceniu, wymazaniu
  konta albo przy następnym nocnym przebiegu
  `kuking:sprzataj-paczki-importu` o 03:10 UTC; przy prawidłowo działającym
  harmonogramie najpóźniej w ciągu doby po przekroczeniu terminu.
  Znacznik — do usunięcia treści (klucz obcy `cascade`) albo konta
  (`EraseAccountData`). Wczytane treści — jak §3.3.
- **Eksport:** znacznik jest `NIE_DOTYCZY` w `InwentarzDanychKonta` (sama
  treść jest w paczce).

### 3.28a Pokazanie przepisu wybranej osobie (V2, #2650)

- **Cel:** autor pozwala JEDNEMU wskazanemu kontu czytać jeden swój
  opublikowany przepis, bez zmiany tego, kto widzi przepis w serwisie.
- **Dane:** `recipe_shares` — przepis, konto odbiorcy (`recipient_id`), daty.
  Odbiorcę wskazuje się publiczną nazwą konta, nie e-mailem. Jedno
  powiadomienie w serwisie (`notifications`, typ `recipe.shared`, w `data`
  sam `recipe_id`; tytuł tylko przy bieżącym prawie odczytu), bez listu
  i Web Push — decyzja właściciela z 2.10.2026. Odbiorca widzi nazwę autora i treść
  przepisu (bez skanu kartki i historii wersji); autor widzi listę odbiorców.
- **Podstawa:** art. 6 ust. 1 lit. b RODO.
- **Odbiorcy:** Railway.
- **Termin usunięcia:** wiersz znika przy odebraniu dostępu, rezygnacji
  odbiorcy, blokadzie między stronami (`ZerwijUdostepnieniaPrzepisow::miedzy()`),
  usunięciu przepisu przez autora albo wymazaniu konta którejkolwiek strony
  (`ZerwijUdostepnieniaPrzepisow::przyWymazaniu()`). Bez historii odebranych dostępów.
- **Eksport:** `udostepnione_przepisy` (`udostepniam`, `udostepnione_mi` — data
  własnej relacji; bieżący tytuł i autor cudzego przepisu tylko wtedy, gdy
  w chwili tworzenia paczki odbiorca ma `readShared()`. Przy wstrzymanym
  dostępie oba pola są `null`, a historia samego udostępnienia pozostaje).

### 3.29 Zapamiętana liczba porcji przy przepisie (V2, issue #2602)

- **Cel:** wygoda osoby, która zwykle robi dany przepis na inną liczbę porcji
  niż autor.
- **Dane:** identyfikator konta i przepisu, jedna liczba porcji (1-100), daty
  zapisu i zmiany (`recipe_serving_preferences`). Tylko po świadomym przycisku
  „Zapamiętaj dla mnie”, osobno dla każdego przepisu; bez zapisu automatycznego,
  bez backfillu, bez wniosków o składzie rodziny. Gość nie zapisuje niczego.
- **Podstawa:** art. 6 ust. 1 lit. b RODO - funkcja włączana na własne życzenie,
  jak „Co mam w domu” i postęp gotowania; opis w polityce §2.
- **Odbiorcy:** Railway. Widzi wyłącznie właściciel; liczba nie wpływa na feed,
  rankingi ani powiadomienia.
- **Termin usunięcia:** do przycisku „Zapomnij moje ustawienie” albo do
  wymazania konta (`EraseAccountData`). Limit 500 przepisów na osobę.
- **Eksport:** `zapamietane_porcje` (przepis i adres tylko gdy przepis jest dziś
  widoczny dla osoby, liczba, daty).

### 3.28 Sieć, CDN i ochrona przed atakami (Cloudflare jako pośrednik, #2282)

- **Cel:** dostarczenie serwisu: zakończenie połączenia HTTPS, podawanie
  plików, odsiewanie ataków i ruchu automatów, przekazanie żądania do
  Railway.
- **Dane:** **całe żądanie i cała odpowiedź** każdego wejścia — adres IP,
  nagłówki, ciasteczka, treść formularzy (także hasło przy logowaniu), treść
  stron po zalogowaniu. Cloudflare kończy TLS, więc widzi je w postaci jawnej.
  Sygnałem w kodzie jest token krawędziowy (`App\Support\TokenKrawedzi`,
  `KUKING_EDGE_TOKEN`) i liczenie `X-Forwarded-For` od prawej
  (`NormalizeForwardedFor`).
- **Podstawa:** art. 6 ust. 1 lit. b RODO (bez tego nie ma usługi) i lit. f
  (bezpieczeństwo).
- **Odbiorca:** Cloudflare, Inc. (USA) — przekazanie poza EOG, DPF i SCC
  (§5). Ten sam globalny DPA konta co R2, Turnstile i Web Analytics
  (`REJESTR_UMOW_POWIERZENIA.md` §2.9).
- **Termin usunięcia:** po stronie Cloudflare — **DO UZUPEŁNIENIA PRZEZ
  WŁAŚCICIELA** z DPA i ustawień konta (dzienniki żądań). W repozytorium nie
  ma żadnego pobierania dzienników Cloudflare do serwisu.

### 3.29 Prywatny roboczy dopisek z gotowania (V2, issue #2587)

- **Cel:** zanotowanie przy garnku zmiany w przepisie i świadome użycie jej
  później w formularzu „Ugotowałem”.
- **Dane:** identyfikator konta i przepisu, krótki tekst wpisany przez osobę
  (do 500 znaków), numer rewizji, daty ostatniej zmiany i wygaśnięcia
  (`cooking_notes`). Tylko dla zalogowanych; goście tej funkcji nie mają.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — funkcja, z której osoba świadomie
  korzysta, tak jak z planu na tydzień. Weryfikacja przez prawnika zostaje w #8.
  **Dopisek może zawierać dane o zdrowiu** (np. „bez glutenu po operacji”):
  wtedy sama lit. b nie wystarcza i potrzebna jest przesłanka z art. 9 ust. 2
  (analiza z 2.10.2026, pyt. 5); rejestr tego nie rozstrzyga, zob. akapit
  o danych o zdrowiu na początku §3.
- **Odbiorcy:** Railway. Dane widzi wyłącznie właściciel
  (`CookingNotePolicy`); nic nie jest wysyłane do podmiotów trzecich, nie
  trafia do adresu, cache, telemetrii ani do autora przepisu. Do pola „Coś po
  swojemu?” (widocznego przy wykonaniu) przechodzi tylko na prośbę osoby.
- **Termin niedostępności:** po 24 godzinach od ostatniej zmiany
  (`kuking.cooking_note.retention_hours`; odczyt ignoruje wiersz już przy
  `expires_at <= now()`). **Fizyczne usunięcie:** następny nocny przebieg
  `kuking:sprzataj-postep-gotowania` o 03:00 UTC, przy prawidłowo działającym
  harmonogramie najpóźniej po około 48 godzinach od zmiany;
  zapisanie wykonania tego przepisu, przycisk „Usuń dopisek” albo wymazanie
  konta (`EraseAccountData`).
- **Eksport:** `dopiski_z_gotowania` (tytuł przepisu tylko gdy przepis jest
  dziś widoczny dla osoby, treść dopisku, daty).

### 3.30 Kopia odzyskania usuniętego prywatnego zeszytu (V2, issue #2567)

- **Cel:** umożliwienie osobie odzyskania prywatnego zeszytu, który usunęła
  przez pomyłkę, razem z jej własnymi dopiskami i datami zapisania.
- **Dane:** identyfikator konta i dawnego zeszytu, nazwa i opis zeszytu, data
  założenia, a dla każdej pozycji: identyfikator przepisu albo wpisu, własny
  dopisek osoby i data zapisania (`deleted_collections`). Bez tytułów i tekstów
  cudzych treści. Tylko dla zeszytów prywatnych, bez członków i zaproszeń.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — funkcja, z której osoba świadomie
  korzysta (ochrona jej własnej pracy). Weryfikacja przez prawnika zostaje w #8.
- **Odbiorcy:** Railway. Kopię widzi wyłącznie właściciel
  (`DeletedCollectionPolicy`); nic nie trafia do podmiotów trzecich,
  moderatorów, adresu, cache ani telemetrii.
- **Termin usunięcia:** `kuking.usuniete_tresci.retention_days` (30 dni) od
  usunięcia zeszytu (to samo okno co inne treści usunięte przez autora); co
  noc kasuje je `kuking:sprzataj-usuniete-tresci`. Wcześniej: odzyskanie
  zeszytu albo wymazanie konta (`EraseAccountData`).
- **Eksport:** `usuniete_zeszyty` (nazwa, opis, daty, pozycje z dopiskami;
  tytuł przepisu tylko gdy przepis jest dziś widoczny dla osoby).

### 3.31 Opcjonalna, prywatna lista ostatnio oglądanych przepisów (V2, issue #2553)

- **Cel:** powrót do przepisu, który osoba obejrzała, ale nie zapisała w
  zeszycie. Wyłącznie ten cel: lista nie służy do polecania treści, układania
  ekranów, statystyk, moderacji ani powiadomień.
- **Dane:** identyfikator konta i przepisu oraz czas ostatniej wizyty
  (`recent_recipe_views`), a w koncie data świadomego włączenia funkcji
  (`users.ostatnio_ogladane_wlaczone_at`). Bez treści, zdjęć, adresu z
  parametrami, wyszukiwanych fraz i wyboru alergenów. Tylko dla zalogowanych;
  goście niczego nie zapisują.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — funkcja, którą osoba włącza na
  własne życzenie; **domyślnie wyłączona**, bez zapisu wstecz. Weryfikacja
  przez prawnika zostaje w #8.
- **Odbiorcy:** Railway. Dane widzi wyłącznie właściciel konta (ekran w
  ustawieniach, bez identyfikatora w adresie); nic nie jest wysyłane do
  podmiotów trzecich, nie trafia do adresu, cache przeglądarki ani brzegu,
  telemetrii ani do autora przepisu.
- **Termin usunięcia:** `kuking.ostatnio_ogladane.dni` (7) dni od ostatniej
  wizyty i najwyżej `kuking.ostatnio_ogladane.limit` (10) różnych przepisów
  (wartości do potwierdzenia przez właściciela, D-333); starsze i nadliczbowe
  pozycje są niewidoczne już przy odczycie, a `kuking:sprzataj-ostatnio-ogladane`
  kasuje je co noc o 02:15. Od razu: „Wyczyść listę”, wyłączenie funkcji
  (kasuje też zgodę) albo wymazanie konta (`EraseAccountData`).
- **Eksport:** `ostatnio_ogladane` (przepis — tytuł tylko gdy przepis jest dziś
  widoczny dla osoby — i czas) oraz `konto.ostatnio_ogladane_wlaczone_od`.

### 3.32 Kopia tekstu szkicu do odzyskania po pomyłce (V2, issue #2512)

- **Cel:** umożliwienie autorowi powrotu do tekstu własnego, nieopublikowanego
  szkicu, który przypadkiem zastąpił albo skasował, a który zdążył się
  zapisać automatycznie.
- **Dane:** identyfikator konta i szkicu, data zrobienia kopii oraz tekst
  szkicu sprzed sesji pisania: nazwa, opis, porcje, czasy, trudność, pochodzenie
  („od kogo”, historia, rok), składniki z grupami, uwagami i zamiennikami, kroki
  z nazwą etapu i minutnikiem oraz wskazanie zdjęcia przy kroku
  (`draft_restore_points`). Bez zdjęć, alergenów, widoczności i danych innych
  osób. Jedna kopia na szkic; powstaje przy otwarciu szkicu do pisania, nie przy
  każdym autozapisie.
- **Podstawa:** art. 6 ust. 1 lit. b RODO — funkcja, z której osoba świadomie
  korzysta (ochrona jej własnej pracy). Weryfikacja przez prawnika zostaje w #8.
- **Odbiorcy:** Railway. Kopię widzi wyłącznie autor szkicu
  (`RecipePolicy::restoreDraftText`); nie trafia do historii wersji, strony
  publicznej, SEO, kanału, wspólnego zeszytu, moderatorów, podmiotów trzecich,
  cache ani telemetrii.
- **Termin usunięcia:** `kuking.przepisy.szkic_punkt_odzyskania_dni` (14 dni od
  zrobienia kopii; wartość do potwierdzenia przez właściciela, D-333); co noc
  kasuje ją `kuking:sprzataj-usuniete-tresci`. Wcześniej: opublikowanie albo
  usunięcie szkicu (sprzątanie / klucz obcy) i wymazanie konta
  (`EraseAccountData`).
- **Eksport:** `kopie_tekstu_szkicow` (tytuł szkicu, daty i tekst kopii bez
  identyfikatorów zdjęć).

---

### 3.29 Wskazówki od gotujących (V2, #2352, D-333)

- **Cel:** pokazanie uwagi z cudzego „Ugotowałem” przy przepisie jako
  wskazówki — wyłącznie za zgodą osoby, która ugotowała.
- **Dane:** prośba i odpowiedź (`recipe_hints`: przepis, wykonanie, autor
  przepisu, kucharz, stan, daty prośby, odpowiedzi i wycofania zgody, numer
  wersji przepisu z chwili prośby). Tekstu nie kopiujemy — wskazówką jest
  `cooked_events.note`. Przy przepisie widać uwagę, nazwę i datę ugotowania
  kucharza; autor przepisu nie dostaje wiadomości o odmowie ani o wycofaniu.
  Od 1.10.2026: prośba bez odpowiedzi wygasa po 30 dniach (liczone z daty
  prośby, bez zmiany stanu), autor może anulować własną czekającą prośbę
  (stan `cancelled`), a zgoda kucharza powiadamia autora w serwisie. Zakres
  danych się nie zmienia — to te same pola, nowe znaczenia stanu.
- **Podstawa:** art. 6 ust. 1 lit. a RODO — zgoda kucharza, udzielana osobno
  przy każdej prośbie („Zgadzam się”), brak odpowiedzi to brak publikacji,
  wycofanie w każdej chwili przyciskiem „Wycofaj zgodę” (art. 7 ust. 3), także
  przy blokadzie i zawieszeniu konta. Weryfikacja przez prawnika zostaje w #8.
- **Odbiorcy:** Railway. Zgodna wskazówka jest widoczna dla każdego, kto
  widzi przepis i to wykonanie (blokady i konta zbanowane ją chowają).
- **Termin usunięcia:** do wycofania zgody (wskazówka znika ze strony
  przepisu, wiersz zostaje jako ślad, że prośba nie wraca), usunięcia
  wykonania (kaskada) albo konta (`EraseAccountData` kasuje wiersze kucharza
  niezależnie od zakresu usunięcia, a czekające i anulowane prośby autora też).
  Zapis wygasłej, anulowanej i odrzuconej prośby zostaje, żeby prośba o to
  samo wykonanie nie wróciła.
- **Eksport:** `wskazowki_z_moich_wykonan` (uwaga, stan zgody, daty) i
  `wskazowki_do_moich_przepisow` (stan próśb, bez danych kucharza).
- **Moderacja:** wskazówka ma własny cel zgłoszenia (`recipe_hint`, #2352,
  decyzja z 1.10.2026): moderacja ukrywa samą wskazówkę
  (`recipe_hints.moderation_hidden_at`), wykonanie zostaje; adresatem decyzji
  i odwołania jest kucharz. Zgłoszenie całego wykonania (`cooked_event`) działa
  osobno.

## 4. Kategorie odbiorców (art. 30 ust. 1 lit. d)

Lista jest zweryfikowana wobec kodu (`COMPLIANCE.md` §7.2) i potwierdzona
wobec zmiennych środowiskowych usługi produkcyjnej
[pomiar cudzy: sesja prowadząca floty, Railway, 20.09.2026 — zmienne
`AWS_*`, `EMAILLABS_*`, `FACEBOOK_CLIENT_*`, `GOOGLE_CLIENT_*`,
`OPENAI_MODERATION_KEY`, `CLOUDFLARE_ANALYTICS_TOKEN`, `TURNSTILE_*`;
wartości zamaskowane, więc potwierdzony jest **fakt konfiguracji**, nie
treść kluczy]. Żaden odbiorca z tej listy nie jest martwy. **Brakowało
jednego** — Cloudflare jako pośrednika całego ruchu (sieć, CDN, ochrona).
Znała go tylko `REJESTR_UMOW_POWIERZENIA.md`, a polityka wspominała o nim
zdaniem pobocznym; dopisany 30.09.2026 (#2282, §3.28). Obecności wiersza
w polityce pilnuje `PolitykaPrywatnosciWymieniaKazdaUslugeTest`.

| Odbiorca | Rola | Co dostaje | Kraj |
|---|---|---|---|
| Railway | podmiot przetwarzający | cała aplikacja, baza i dziennik serwera | deklarowana UE — **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** region usługi odczytany z panelu |
| Cloudflare (sieć, CDN i ochrona przed atakami) | podmiot przetwarzający | całe żądanie i odpowiedź każdego wejścia: adres IP, nagłówki, ciasteczka, treść formularzy i stron — połączenie jest odszyfrowywane u niego (§3.28) | USA |
| Cloudflare R2 | podmiot przetwarzający | zdjęcia i ich warianty, paczki eksportu, zaszyfrowane zrzuty bazy (`AWS_KOPIE_BUCKET`, retencja 30 dni — `KOPIA_RETENCJA_DNI`) | jurysdykcja UE — właściciel potwierdził 24.09.2026, że `AWS_ENDPOINT` ma segment `.eu.`, a buckety są w jurysdykcji UE; od D-255 (PR #1463) aplikacja odmawia endpointu bez `.eu.` (`App\Support\Storage\DozwolonyHostR2`, `/health`) |
| Cloudflare Turnstile | podmiot przetwarzający | adres IP i cechy przeglądarki przy siedmiu formularzach | USA |
| Cloudflare Web Analytics | podmiot przetwarzający | adres strony, odnośnik, rodzaj przeglądarki, czas wczytania | USA |
| OpenAI | podmiot przetwarzający | treść wpisu i pomniejszone zdjęcie, bez danych wskazujących osobę; **oraz — tylko na żądanie i za osobną zgodą, po włączeniu funkcji (§3.23)** — zdjęcie kartki, tekst strony bez danych przepisu albo obrazy stron skanu PDF | USA |
| EmailLabs (Vercom S.A.) | podmiot przetwarzający | adres e-mail odbiorcy i treść listu | Polska |
| Google | podmiot przetwarzający przy logowaniu | potwierdzenie tożsamości, e-mail, imię (zakres `profile` obejmuje też adres zdjęcia profilowego — nie zapisujemy go, §3.8) | Irlandia / USA |
| Meta | **osobny administrator** | zakres po stronie Meta przy logowaniu Facebookiem | Irlandia (dalej w grupie Meta) |
| Discord (kanał alarmów, `LOG_BLAD_WEBHOOK_URL`) | **odbiorca techniczny bez danych osobowych** — nie podmiot przetwarzający | dzwonek o błędzie albo alarmie: klasa i miejsce błędu, wzorzec trasy, liczby czujek; bez adresów, treści i nazw kont (§3.19) | USA |

**Odbiorcy, których NIE ma i nigdy nie było:** Sentry, PostHog, Google
Analytics, Matomo, Plausible. Wpisanie ich do rejestru byłoby deklaracją
przetwarzania, którego nie ma; pilnuje tego
`DokumentyPrawneNieKlamiaTest::test_dokument_wewnetrzny_nie_wymienia_narzedzi_ktorych_nie_uzywamy`.

**Odbiorcami nie są** organy publiczne, którym dane mogą zostać przekazane
w ramach konkretnego postępowania (art. 4 pkt 9 RODO) — w tym organy
ścigania przy ścieżce z art. 18 DSA (`MODERATION_PLAYBOOK.md` §7.1).

---

## 5. Przekazania do państw trzecich (art. 30 ust. 1 lit. e)

| Przekazanie | Co wychodzi | Deklarowana podstawa | Czego brakuje |
|---|---|---|---|
| OpenAI (strona umowy dla EOG co do zasady OpenAI Ireland Ltd, do potwierdzenia; USA) | treść wpisu/komentarza i pomniejszone zdjęcie, bez EXIF-u, bez identyfikatora konta, e-maila i IP; sama treść może zawierać dane osobowe (`app/Moderacja/KlientOpenAI.php`) | deklarowane: EU-US Data Privacy Framework + standardowe klauzule umowne, **bez dowodu** (analiza z 2.10.2026, pyt. 9) | **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** właściwy podmiot, data sprawdzenia jego wpisu na liście DPF, dokument SCC i moduł, retencja dla `/v1/moderations` (bez „30 dni”) |
| OpenAI (USA) — odczyt przepisu na żądanie (§3.23), **funkcja niewłączona** | zdjęcie kartki (JPEG ≤ 2000 px, bez EXIF/GPS), wiersze tekstu strony (≤ 12 000 znaków, bez adresu i komentarzy), obrazy stron PDF bez warstwy tekstu (JPEG ≤ 1600 px); bez e-maila, nazwy konta, IP i identyfikatorów (`app/Domain/Import/KlientLuna.php`) | EU-US Data Privacy Framework + SCC, umowa powierzenia **niepodpisana** (`REJESTR_UMOW_POWIERZENIA.md` §2.5) | **DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:** DPA, data sprawdzenia DPF, SCC, okres przechowywania po stronie OpenAI |
| Cloudflare, Inc. (USA) — sieć i ochrona (pośrednik całego ruchu, §3.28), Turnstile i Web Analytics | całe żądania i odpowiedzi (sieć); adres IP i cechy przeglądarki (Turnstile); adresy stron (Web Analytics) | EU-US Data Privacy Framework + SCC | jw. |
| Google LLC (USA) — tylko przy logowaniu kontem Google | potwierdzenie tożsamości, e-mail, imię | EU-US Data Privacy Framework + SCC | jw. |

**Meta nie jest w tej tabeli, bo to odrębny administrator** (zob. §3.8):
nie przekazujemy jej danych na zlecenie, a przetwarzanie w grupie Meta dzieje
się na jej własnych podstawach. Nie opieramy tego na zdaniu „kontrahent jest
spółką irlandzką, więc nie ma transferu”: tożsamość kontrahenta nie
rozstrzyga całego przepływu ani dostępu z innego państwa (analiza z
2.10.2026, §5.2). Google LLC w wierszu wyżej to również odrębny
administrator, nie nasz procesor; wiersz zostaje informacyjnie.

**Najważniejsze zdanie tej sekcji:** z kodu widać, **co** wychodzi i **do
kogo**. Tego, **na jakiej podstawie**, z kodu nie widać nigdy — to jest
dokument w szafie, nie plik w repozytorium. Dlatego każdy wiersz ma kolumnę
„czego brakuje", i dlatego ta sekcja nie jest zamknięta.

---

## 6. Ogólny opis środków bezpieczeństwa (art. 30 ust. 1 lit. g)

Wszystkie poniższe są zmierzone w kodzie; szczegóły i uzasadnienia stoją
w `SECURITY_BASELINE.md`.

- **Hasła** przechowywane jako nieodwracalne skróty, nigdy jako tekst
  (polityka mówi to wprost, a `DokumentyPrawneNieKlamiaTest::test_nie_mowimy_ze_haslo_jest_zaszyfrowane`
  pilnuje, żeby dokument nie nazywał tego szyfrowaniem).
- **Połączenie wyłącznie po HTTPS**; ciasteczko sesji na produkcji jest
  oznaczone `secure`, `httponly` i `samesite=lax`. Podstawą jest pomiar
  `https://kuking.pl/login` z 20 września 2026, a nie test w repozytorium:
  wartość `SESSION_SECURE_COOKIE` ustawiona jest w panelu dostawcy i z kodu
  jej nie widać. Domyślnik chroniący przed jej usunięciem nie został
  zatwierdzony — patrz gałąź `bramka-startowa`.
- **Adres IP w dzienniku audytu przechowywany jako skrót** liczony z kluczem
  żyjącym poza bazą — sam zrzut tabeli adresu nie oddaje.
- **EXIF i GPS zdejmowane ze zdjęć** przez przekodowanie
  (`SECURITY_BASELINE.md` §5.3); poza serwer wychodzi wyłącznie wariant
  przekodowany.
- **Autoryzacja przez Policy przy każdym wejściu** — UUID w adresie nie jest
  autoryzacją (`AGENTS.md`).
- **Ograniczenia liczby prób** przy logowaniu, rejestracji, odzyskiwaniu
  hasła i wysyłce poczty (`SECURITY_BASELINE.md` §4).
- **Ochrona formularzy publicznych** przez Cloudflare Turnstile (§3.9).
- **Kanał błędów nie wynosi danych osobowych** —
  `BladTrafiaNaWebhookBezDanychOsobowychTest`.
- **Automatyczne kasowanie po terminie** — dziesięć komend retencji,
  wymienionych przy poszczególnych czynnościach; ich istnienia pilnuje
  `DokumentyPrawneNieKlamiaTest::test_komendy_wymienione_w_procedurach_istnieja`.
- **Dostęp po stronie administratora ma jedna osoba** — ta, która prowadzi
  serwis; dwuetapowa weryfikacja obowiązkowa dla kont z uprawnieniami
  moderatora (`SECURITY_BASELINE.md` §2).
- **Kopie zapasowe bazy** — `scripts/kopia-lokalna.sh`,
  `kuking:sprawdz-kopie`. Zrzuty offsite (serwis `kopia-bazy`) są szyfrowane
  kluczem publicznym i trzymane **30 dni** (`KOPIA_RETENCJA_DNI`,
  `docs/infra/KOPIE_I_ODTWORZENIE.md` §7); PITR Railwaya ok. 4 tygodni według
  dokumentacji dostawcy — niepotwierdzone na produkcji (#594).

**DO UZUPEŁNIENIA PRZEZ WŁAŚCICIELA:**

- **Maksymalny czas życia kopii zapasowej u dostawcy hostingu** (Volume
  Backups/PITR Railwaya) zawierającej dane osoby, która usunęła konto.
  Własne zrzuty offsite mają już liczbę (30 dni, wyżej); kopie po stronie
  Railwaya — nie, dopóki #594 nie potwierdzi ustawień produkcji
  (`COMPLIANCE.md` §7.1 i §2.8).
- **Umowy powierzenia** z każdym podmiotem przetwarzającym — stan do
  odhaczenia: `REJESTR_UMOW_POWIERZENIA.md`.
- **Data ostatniego przeglądu tego rejestru** i osoba, która go zrobiła.

---

## 7. Czego ten rejestr świadomie nie rozstrzyga

1. **Czy podstawa prawna wpisana przy każdej czynności jest właściwa.**
   Kod pokazuje, co się dzieje; kwalifikacja prawna należy do prawnika.
2. **Czy potrzebna jest ocena skutków (DPIA).** `COMPLIANCE.md` §2.5 mówi,
   że prawdopodobnie nie dla podstawowego zakresu, i wskazuje dwa obszary
   do rozważenia — moderację i ewentualne skanowanie zdjęć.
3. **Czy trzeba wyznaczyć IOD.**
4. **Czy test równoważenia przy art. 6 ust. 1 lit. f wypada na naszą
   korzyść** w każdym z siedmiu miejsc, w których się na tę podstawę
   powołujemy. Testu równoważenia w repozytorium nie ma i nie powinno go
   pisać narzędzie, które sprawdza samo siebie.
