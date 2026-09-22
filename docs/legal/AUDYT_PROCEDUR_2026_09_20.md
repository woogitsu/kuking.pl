# Audyt procedur wobec kodu — 20 września 2026

Zakres: `BRAMKA_BETY.md`, `MODERATION_PLAYBOOK.md`, `SYGNALY_AUTOMATU.md`,
`SECURITY_BASELINE.md` (1815 wierszy) plus lista gotowości w `COMPLIANCE.md`.
Podstawa: `origin/main` = `b34c2973`. Produkcja odczytana wyłącznie przez
`GET`, bez logowania.

Dla każdego twierdzenia jedno z trzech rozstrzygnięć:

- **POKRYTE** — jest w kodzie, z plikiem i linią;
- **BEZ POKRYCIA** — procedura opisuje coś, czego nie ma;
- **KOD ROBI WIĘCEJ** — procedura opisuje mniej, niż kod wykonuje.

Trzeciego nikt nie szuka i dlatego dostał tu osobną sekcję.

---

## 1. Metoda — najpierw pomiar, potem czytanie

Najpierw przeszedł pomiar mechaniczny: z czterech procedur wyciągnięto
**każdą nazwę klasy testu, metody, ścieżkę pliku i nazwę komendy**, po czym
skonfrontowano je z `git ls-files` i z rejestrem Artisana.

Wynik tego przebiegu jest dobry i warto go zapisać: **zero martwych nazw klas,
zero martwych metod, jedna martwa ścieżka.** Dokumenty nie są zapuszczone —
rozjazdy, które znalazłem, siedzą w treści twierdzeń, nie w odsyłaczach.

Dopiero potem szło czytanie punkt po punkcie, ukierunkowane na dwa pytania
z zlecenia: **czy procedura każe człowiekowi robić to, co system już
pilnuje** i **czy gdzieś liczymy na czujność człowieka bez strażnika**.

---

## 2. KOD ROBI WIĘCEJ, niż procedura opisuje

### 2.1. Automat ma czwarty sygnał, i to najcięższy — `SYGNALY_AUTOMATU.md` §3

| | |
|---|---|
| **Co mówiła procedura** | §3 „Sygnały wdrożone DZIŚ": trzy sygnały — `automat_wzorzec` (3), `automat_odnosnik` (2), `automat_powtorzenie` (1), a pod nagłówkiem zdanie „Wszystkie **trzy** opisują zachowanie jednego konta" |
| **Co robi kod** | `app/Models/Report.php:128` i `:156` — sygnałów jest **cztery**, a `automat_model` ma wagę **4**, wyższą niż każdy opisany |
| **Dlaczego to groźne** | Moderator czyta §3, żeby wiedzieć, co automat potrafi podnieść. Nie wiedział o najcięższym sygnale, który dotyczy **innej klasy treści** niż pozostałe trzy: nienawiści, przemocy, treści seksualnych i samookaleczenia (D-055) |

Model jest opisany w §8 tego samego dokumentu — więc nie chodzi o ukrytą
funkcję, tylko o **listę, która nie wymienia najważniejszej pozycji**.
Poprawione: §3 ma teraz tabelę czterech sygnałów z wagami i odesłaniem do §8.

**Strażnik:** `DokumentyPrawneNieKlamiaTest::test_procedura_wymienia_kazdy_sygnal_automatu`
— czyta `Report::REASONS_AUTOMAT` i wymaga, żeby każdy klucz padł w dokumencie.
Piąty sygnał zapali czerwone światło w dniu dodania.

### 2.2. Panel wymusza podstawę decyzji — `MODERATION_PLAYBOOK.md` nie wiedział

| | |
|---|---|
| **Co mówiła procedura** | nic. Słowa „podstawa decyzji", `reason_code` ani nazwy którejkolwiek podstawy nie było w całym playbooku |
| **Co robi kod** | `app/Domain/Moderation/PodstawaDecyzji.php` — **zamknięta lista dwunastu podstaw**, każda wskazuje punkt `zasady.md`; `ModerationController::decide()` nie przyjmie decyzji bez niej |
| **Dlaczego to groźne** | Playbook jest jedynym dokumentem, z którego moderator uczy się pracy. Prowadził go przez decyzję, pomijając pole, bez którego formularz nie przejdzie |

Poprawione: nowa sekcja §7.0 z listą, odesłaniem do kodu i wskazaniem dwóch
podstaw, które zachowują się inaczej niż reszta.

### 2.3. Retencja: siedem komend zamiast dwóch — już poprawione wcześniej

`BRAMKA_BETY.md` §8 pkt 7 opisuje to poprawnie i sam mówi, że wcześniejsze
zdanie było nieprawdziwe. **Sprawdzone: wszystkie siedem komend istnieje**
i są w harmonogramie (`routes/console.php`). POKRYTE.

**Strażnik dołożony mimo to:** `test_komendy_wymienione_w_procedurach_istnieja`
— wyciąga z procedur każdą nazwę `kuking:*` i sprawdza w rejestrze Artisana.
Zmiana nazwy komendy nie przejdzie po cichu.

---

## 3. Procedura każe człowiekowi to, co system pilnuje sam

### 3.1. „Sprawdź kanał błędów pod kątem danych osobowych" — jest na to test

Wiersz P0 listy gotowości (dopisany 19 września, **przeze mnie**) kazał przed
startem ręcznie sprawdzić, czy kanał błędów nie wynosi danych osobowych.
Tymczasem istnieje `BladTrafiaNaWebhookBezDanychOsobowychTest`.

Zostawienie tego jako zadania dla człowieka jest szkodliwe podwójnie: każe
robić robotę już zrobioną i sugeruje, że test jest niewystarczający, choć nikt
tego nie stwierdził. Poprawione: wiersz wskazuje test i mówi wprost
„**pilnowane testem, nie trzeba sprawdzać ręcznie**".

To jest jedyny przypadek tej klasy, jaki znalazłem — i znalazłem go we
własnej pracy sprzed doby.

---

## 4. Liczymy na czujność człowieka tam, gdzie nie ma strażnika

### 4.1. Sprzeczna instrukcja przy CSAM — najgorsze możliwe miejsce

| | |
|---|---|
| **Co mówi procedura** | tabela §5, wiersz CSAM: „Zostaw »Wiadomość do użytkownika« **PUSTĄ** — pójdzie wtedy samo neutralne zdanie domyślne" |
| **Co robi kod** | `ModerationController::decide()` — `'user_message' => [… 'required_if:reason_code,niezgodne-z-prawem']`. Przy podstawie **„Treść niezgodna z prawem"** pusta wiadomość **nie przejdzie** |
| **Dlaczego to groźne** | CSAM jest jedyną kategorią, w której playbook każe działać natychmiast. Moderator wybierający najbardziej naturalną prawnie podstawę („treść niezgodna z prawem") dostaje błąd walidacji — w chwili, w której dokument sam mówi, że liczy się szybkość |

Rozstrzygnięcie: właściwa jest podstawa **„Krzywdzenie dzieci — usuwamy
natychmiast"** (`krzywdzenie-dzieci`), przy której pusta wiadomość działa
zgodnie z opisem. Poprawione: wiersz tabeli podaje teraz nazwę podstawy
i ostrzega przed drugą.

Zdanie o neutralnym zdaniu domyślnym jest **prawdziwe** —
`NotifyModerationDecision:118` podstawia `self::DOMYSLNE[$decyzja]`, gdy
wiadomość jest pusta. POKRYTE.

### 4.2. Pozycje, które z założenia zostają dla człowieka

Nie każda taka pozycja jest wadą. Cztery są nieusuwalne i mają to teraz
napisane wprost w liście gotowości: ścieżka zgłoszenia do organów (wymaga
potwierdzenia u prawnika), umowy powierzenia (widać je w szafie, nie w kodzie),
`SESSION_SECURE_COOKIE` na produkcji (wartość spoza repozytorium) oraz
`zadania_nieudane` w `/health` (brak konsoli produkcyjnej, #713 A1).

Różnica względem stanu sprzed audytu: **każda z nich mówi teraz, gdzie
patrzeć**, zamiast wyglądać na odhaczalną z pamięci.

---

## 5. BEZ POKRYCIA

### 5.1. Bramka bety blokowała na rozwiązanym problemie

`BRAMKA_BETY.md` §8 pkt 6: „Dziś `MAIL_MAILER=log`: reset hasła nie dochodzi
do nikogo". To zdanie opisuje `.env.example`, czyli ustawienie **lokalne**.
Odczyt `https://kuking.pl/health` z 20 września: `poczta: ok`; jedyny
niezdrowy element to `kolejka: zadania_nieudane`.

**To jest lustrzane odbicie problemu, od którego zaczęło się to zlecenie.**
Lista blokowała start dwoma P0 o usługach, których nigdy nie było; bramka
blokowała start problemem, który już rozwiązano. Skutek obu jest ten sam:
człowiek uczy się, że listy nie trzeba czytać serio.

### 5.2. `config/hashing.php` nie istnieje

`SECURITY_BASELINE.md:85` odsyła do `config/hashing.php` przy sugestii
rozważenia `argon2id`. Pliku nie ma — Laravel go tu nie publikował.
Zdanie jest warunkowe („rozważ"), więc szkoda jest mała, ale czytelnik szuka
pliku, którego nie znajdzie. Zostawione bez zmiany treści merytorycznej;
odnotowane tutaj.

Sama zawartość akapitu **POKRYTA**: `.env.example:19` ma `BCRYPT_ROUNDS=12`,
czyli zalecane „rounds ≥ 12".

### 5.3. Rejestr czynności przetwarzania (Art. 30 RODO)

Nie istnieje. Był na liście gotowości jako P0 i **pozostaje** — z jawnym
`BRAK:` zamiast pustego pola.

---

## 6. POKRYTE — sprawdzone, zgadza się

Wybór z twierdzeń, które zweryfikowałem i które okazały się dokładne. Wypisuję
je, bo audyt pokazujący same wady daje fałszywy obraz dokumentu.

| Twierdzenie | Gdzie w kodzie |
|---|---|
| hasło: min. 10 znaków, sprawdzane wobec wycieków, w trzech miejscach | `RegisterController:131`, `PasswordResetController:97`, `SecuritySettingsController:45` |
| zmiana i reset hasła unieważniają pozostałe sesje | `PasswordResetController:146`, `SecuritySettingsController:69` |
| statusy treści: `draft/published/hidden/removed`, komentarz bez `draft`; `quarantine` nie istnieje | `2026_09_05_000500_create_posts_tables.php:45`, `…000700_create_comments_table.php:48` |
| ciasteczko sesji `httponly` i `samesite=lax` | `config/session.php:187`, `:204` |
| siedem komend retencji istnieje i jest w harmonogramie | `routes/console.php` |
| neutralne zdanie domyślne przy pustej wiadomości moderatora | `NotifyModerationDecision:118` |
| CSP bez `unsafe-inline` i `unsafe-eval` w `script-src` | `ApplySecurityHeaders.php`, `PolitykaBezpieczenstwaTest` |
| ścieżka CSAM: zero tolerancji, miękkie usunięcie, zachowanie dowodu | `MODERATION_PLAYBOOK.md` §7.1 zgodne z zachowaniem `RemoveContent` |

---

## 7. Liczba, która przestała być prawdziwa

`SECURITY_BASELINE.md` tłumaczył, dlaczego `style-src` ma jeszcze
`unsafe-inline`: „w widokach zostało **355** atrybutów `style="…"`
w **57** plikach".

Zmierzone 20 września: **198 atrybutów w 14 plikach**. Dług skurczył się
o ponad połowę, a dokument nadal straszył starą liczbą — bo liczba raz
wpisana w dokument nigdy więcej nie była mierzona.

Poprawione na zmierzone. **Strażnik:**
`test_liczba_atrybutow_style_w_dokumencie_zgadza_sie_z_pomiarem` liczy
atrybuty przy każdym przebiegu i porównuje ze zdaniem. Nie pilnuje konkretnej
liczby — pilnuje, żeby zdanie nadal opisywało rzeczywistość. **To on powie,
kiedy dojdzie do zera, czyli kiedy dyrektywę wolno wreszcie docisnąć.**

---

## 8. Co dostała lista gotowości

Każdy z 25 wierszy ma teraz **dowód**: nazwę testu albo `plik:linia`, jawne
`DO SPRAWDZENIA PRZEZ CZŁOWIEKA:` z podaniem gdzie, albo jawne `BRAK:`.
Doszły trzy wiersze o realnych kanałach wyjścia danych (Cloudflare Web
Analytics, OpenAI, logowanie Google/Meta), których lista wcześniej nie
wymieniała, oraz §7.2 — lista odbiorców zweryfikowana wobec kodu.

**Strażnik:** `test_kazdy_wiersz_listy_gotowosci_ma_dowod`. Wiersz bez dowodu
nie przejdzie, bo punkt bez dowodu jest gorszy niż brak punktu.

---

## 9. Czego ten audyt nie obejmuje

Nie czytałem `LICENCJA_UGC_PROJEKT.md` (projekt, nie procedura) ani
`COMPLIANCE.md` poza listą gotowości i §7.1–7.3 — prozę analizy zostawiłem
nietkniętą świadomie, bo opisuje decyzje historyczne razem z powodami.

Nie jest to ocena prawna żadnego zapisu. Nie sprawdzałem produkcji inaczej
niż `GET` na `/health` i na stronach publicznych. Nie uruchamiałem żadnej
procedury moderacyjnej na prawdziwych danych — CSAM, odwołania i zgłoszenia
sprawdzone wyłącznie przez odczyt kodu i istniejące testy.
