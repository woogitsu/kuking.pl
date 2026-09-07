# Pomiar obowiązków DSA w kodzie Kuking.pl

> **AKTUALIZACJA 7 września 2026, po tym pomiarze: część braków z art. 17
> została ZAMKNIĘTA (commit `21129a1`), a część zdań z listy „czego NIE WOLNO
> napisać" jest od tego commita PRAWDĄ.** Ten dokument zostaje w pierwotnym
> brzmieniu, bo jest zapisem pomiaru na konkretnym HEAD (`3805ac0`), a nie
> bieżącym stanem serwisu — i dlatego trzeba go czytać razem z tą ramką.
>
> Od tego commita autor treści dostaje: podstawę decyzji (punkt zasad
> z numerem, weryfikowanym testem czytającym `resources/legal/zasady.md`),
> informację, czy sprawa zaczęła się od zgłoszenia, zdanie o braku automatu
> (z trzema niezależnymi dowodami w testach), termin odwołania (sześć
> miesięcy, art. 20) oraz pouczenie o organie pozasądowym i sądzie — to samo,
> które od dawna dostawał zgłaszający. Osoba ZABLOKOWANA widzi to samo na
> ekranie logowania, bo to jej jedyny kanał.
>
> **Otwarte zostaje:** konkretny przepis prawny (lit. d) — `reason_code`
> trzyma sam rodzaj podstawy, więc przy podstawie „treść niezgodna z prawem"
> obowiązkowa jest wiadomość od moderacji i tylko na nią się powołujemy;
> oraz dostęp ZGŁASZAJĄCEGO do systemu skarg (art. 20) i odwołanie od decyzji
> „bez działania" — jedno i drugie wymaga zmiany schematu.

> **Czym jest ten dokument.** Pomiarem kodu, nie opinią prawną. Dla każdego
> obowiązku z rozporządzenia 2022/2065 podaję: co jest w kodzie (plik i linia),
> czy to wystarcza, oraz **jakie zdanie wolno wpisać do regulaminu dosłownie,
> a jakiego nie wolno, bo kod nie czyni go prawdą.**
>
> **Zasada nadrzędna:** nie wolno opublikować zdania, którego kod nie czyni
> prawdą. Tam, gdzie z kodu nie da się czegoś ustalić, jest napisane
> „nie da się ustalić" — bez zgadywania.
>
> **Warunki pomiaru.** Gałąź `claude/kuking-development-muukrs`, HEAD `3805ac0`
> (7 września 2026). Numery linii z tego commita. Schemat bazy sprawdzony
> **w żywej bazie** `kuking` przez `psql` (`\d reports`, `\d appeals`,
> `\d moderation_actions`, `pg_constraint`), nie tylko w plikach migracji.
> Testy DSA uruchomione na osobnej bazie `kuking_test_dsa`:
> `ZgloszenieNielegalnejTresci`, `OdwolanieOdDecyzji`, `JednaDecyzjaNaZgloszenie`,
> `OdpowiedzDlaZglaszajacego`, `PowiadomienieOModeracji`, `DecyzjeModeracyjne`
> — **55 testów, 264 asercje, wszystkie przechodzą.**
>
> **Uwaga o stanie drzewa roboczego.** W chwili pomiaru katalog roboczy miał
> niezacommitowane zmiany innej, niepowiązanej pracy (zdjęcia, usuwanie konta,
> sygnały analityczne — 14 plików). **Żadna z nich nie dotyka kodu moderacji
> ani DSA**: sprawdziłem, że `User::suspend()`, `User::ban()`
> i `User::reinstate()` są nietknięte, a zmieniony jest tylko podpis
> `markForDeletion()`, który do decyzji moderacyjnych nie należy
> (`app/Models/ModerationAction.php:70-73`). Numery linii poniżej odnoszą się
> do plików w drzewie roboczym; dla wszystkich plików cytowanych w tym
> dokumencie drzewo jest identyczne z HEAD.
>
> **Zakres.** Nie zmieniałem kodu aplikacji ani plików w `resources/legal/`.
> Ten dokument jest wejściem do tej pracy, nie jej wykonaniem.

---

## 0. Wynik w jednym akapicie

Mechanizm zgłaszania nielegalnej treści (art. 16) **istnieje i działa**: publiczny
formularz bez logowania, wymagane uzasadnienie nielegalności i oświadczenie
o dobrej wierze, CHECK-i w bazie, potwierdzenie odbioru i powiadomienie
o decyzji. Zewnętrzny audyt, który twierdził, że tego brakuje, **miał rację
w chwili pomiaru i przestał ją mieć** — patrz §7. Uzasadnienie decyzji dla
autora (art. 17) **dochodzi do człowieka**, ale **nie zawiera trzech
elementów, których wymaga art. 17 ust. 3**. Wewnętrzny system rozpatrywania
skarg (art. 20) istnieje dla autorów treści, **ale ma termin 14 dni zamiast
sześciu miesięcy i jest niedostępny dla zgłaszającego** — a to jest
najpoważniejsze znalezisko w całym pomiarze. **Nic w kodzie nie mierzy czasu
reakcji na zgłoszenie**, a `zasady.md` obiecuje 48 godzin. Raportu
przejrzystości (art. 15) nie ma w kodzie w żadnej postaci.

---

## 1. Art. 16 — mechanizm zgłaszania nielegalnej treści

### (b) Co jest w kodzie

**Trasy — publiczne, poza `auth`, ze świadomym uzasadnieniem:**

- `routes/web.php:477-478` — `GET /zglos-nielegalna-tresc` (`zglos.nielegalna`),
- `routes/web.php:479-481` — `POST /zglos-nielegalna-tresc`, limit
  `throttle:3,60` z `config/kuking.php:413` (`legal_notice` = `'3,60'`),
- `routes/web.php:482-483` — ekran potwierdzenia `.../przyjete`,
- `routes/web.php:462-476` — komentarz wyjaśniający, dlaczego trasa stoi poza
  logowaniem i że ochroną jest limit żądań, nie konto.

**Kontroler:** `app/Http/Controllers/ZgloszenieNielegalnejTresciController.php`

| Wymóg art. 16 ust. 2 | Miejsce w kodzie |
|---|---|
| wystarczająco uzasadnione wyjaśnienie, dlaczego treść jest nielegalna (lit. a) | `:57` — `illegality_explanation` → `required, min:20, max:5000`; komunikaty `:64-65` |
| dokładna lokalizacja elektroniczna, np. URL (lit. b) | `:55` — `target_url` → `required, max:2000`; rozpoznanie adresu `:104-126` |
| imię i nazwisko oraz e-mail zgłaszającego (lit. c) | `:50` — `notifier_name` → **`required`**; `:54` — `notifier_email` → **`nullable`** (świadomie, komentarz `:51-53`) |
| oświadczenie o dobrej wierze (lit. d) | `:58` — `good_faith` → `accepted`; komunikat `:66` |

**Akcja domenowa:** `app/Domain/Moderation/Actions/ZglosNielegalnaTresc.php:50-93`
— tworzy `Report` ze `source = legal_notice` (`:60`), zapisuje
`good_faith_at = now()` jako **znacznik czasu, nie boolean** (`:73-75`),
a `target_type = 'unknown'` z pustym `target_id`, gdy adresu nie da się
rozwiązać (`:63-69`). Duplikaty **nie są scalane** — uzasadnienie `:37-40`.

**Widok:** `resources/views/pages/zglos-nielegalna-tresc.blade.php`
— pola `:55-58` (adres), `:60-71` (powód z listy `Report::REASONS`),
`:73-75` (uzasadnienie), `:79-85` (dane kontaktowe), `:92-97` (oświadczenie
o dobrej wierze). Ekran **świadomie bez `noindex`** (`:17-26`), bo art. 16
ust. 1 wymaga mechanizmu łatwo dostępnego.

**Model:** `app/Models/Report.php:52` (`SOURCE_COMMUNITY`), `:63`
(`SOURCE_LEGAL_NOTICE`), `:65-81` (`$fillable`), `:113-116`
(`jestZgloszeniemPrawnym()`), `:126-129` (`maAdresDoOdpowiedzi()`).

**Baza — sprawdzone w żywej bazie, nie tylko w migracji.** Migracja
`database/migrations/2026_09_06_200000_add_legal_notice_fields_to_reports.php`,
stan faktyczny w `kuking`:

```
reports_source_check                 CHECK (source IN ('community','legal_notice'))
reports_target_type_check            CHECK (target_type IN ('user','post','recipe',
                                           'comment','cooked_event','unknown'))
reports_community_target_check       CHECK (source <> 'community'
                                           OR (target_type IS NOT NULL
                                               AND target_type <> 'unknown'
                                               AND target_id IS NOT NULL))
reports_legal_notice_complete_check  CHECK (source <> 'legal_notice'
                                           OR (illegality_explanation IS NOT NULL
                                               AND good_faith_at IS NOT NULL
                                               AND notifier_name IS NOT NULL))
```

Kolumny obecne w tabeli: `source`, `notifier_name`, `notifier_email`,
`target_url`, `illegality_explanation`, `good_faith_at`, `receipt_sent_at`,
`decision_sent_at`. Indeksy: `reports_source_status_idx` oraz częściowy
`reports_pending_receipt_idx` (`WHERE source='legal_notice' AND notifier_email
IS NOT NULL AND receipt_sent_at IS NULL`). `target_id` jest **nullable** —
migracja `:108`. Wszystko to potwierdzone przez `\d reports`.

**Potwierdzenie odbioru (art. 16 ust. 4):**
`app/Domain/Moderation/Actions/ZglosNielegalnaTresc.php:85-90` — wysyłka
**poza transakcją**, kolejkowana, z zapisem `receipt_sent_at`. Treść listu:
`app/Notifications/PotwierdzenieZgloszeniaNielegalnejTresci.php:42-53` —
zawiera numer sprawy, zgłoszony adres i zapowiedź decyzji **bez podania
terminu**. Ekran potwierdzenia (dla osób bez adresu e-mail):
`resources/views/pages/zglos-nielegalna-tresc-potwierdzenie.blade.php:9-26`.

**Powiadomienie o decyzji (art. 16 ust. 5):**
`app/Http/Controllers/Admin/ModerationController.php:183-188` — tylko gdy
`maAdresDoOdpowiedzi()`, z zapisem `decision_sent_at`. Treść:
`app/Notifications/DecyzjaWSprawieZgloszenia.php:83-100` (trzy różne
komunikaty zależnie od skutku decyzji) i `:102-111` (pouczenie o środkach).

**Dostępność mechanizmu:** jedyne wejście z interfejsu to stopka —
`resources/views/components/layout.blade.php:402`.

### (c) Czy to wystarcza

**W większości tak. Cztery rzeczy nie domykają się.**

1. **Zgłoszenie NIE jest anonimowe.** Imię/nazwa jest `required`
   (`ZgloszenieNielegalnejTresciController.php:50`) **i wymuszone CHECK-iem
   w bazie** (`reports_legal_notice_complete_check`). Art. 16 ust. 2 lit. c
   zwalnia z podania danych zgłaszającego przy zgłoszeniach dotyczących
   przestępstw z art. 3-7 dyrektywy 2011/93/UE — **kod zwalnia z adresu
   e-mail, ale nie z imienia.** Zgłoszenie krzywdzenia dziecka wymaga więc
   dziś podania nazwy. To jest wymóg **surowszy** niż przepis, i blokuje
   dokładnie ten scenariusz, dla którego wyjątek istnieje.
2. **Brak adresu e-mail = brak jakiejkolwiek informacji o wyniku.** To jest
   zgodne z przepisem (ust. 4 i 5 mówią o danych kontaktowych), ale w kodzie
   nie ma żadnej drogi sprawdzenia stanu sprawy po numerze. Numer sprawy
   z `:81` jest wyłącznie do powołania się w liście.
3. **`target_url` nie jest wymuszony w bazie dla zgłoszenia prawnego** —
   `reports_legal_notice_complete_check` pilnuje uzasadnienia, dobrej wiary
   i nazwy, ale nie adresu. Adres jest `required` tylko w formularzu
   (`:55`). Drugi endpoint albo seeder zapisze zgłoszenie prawne bez
   lokalizacji.
4. **Zgłoszenie społecznościowe zbiera powody, które są podstawami
   nielegalności.** `app/Models/Report.php:34-46` — lista zawiera
   `hate` („Mowa nienawiści"), `personal_data` („Ujawnia czyjeś dane
   osobowe"), `copyright` („To nie jest treść tej osoby"), `minor`
   („Dotyczy dziecka"). Ta droga **wymaga logowania**
   (`routes/web.php:405-406`, grupa `auth`), **nie zbiera oświadczenia
   o dobrej wierze ani uzasadnienia nielegalności**
   (`ReportController.php:58-63` — tylko `reason` i `details`) i **nie wysyła
   ani potwierdzenia, ani decyzji** (`ModerationController.php:183` odcina
   `source = community`). Formularz społecznościowy **nie zawiera odesłania
   do drogi prawnej** — sprawdzone: `resources/views/pages/report.blade.php`
   nie ma ani jednego wystąpienia `zglos.nielegalna`. Człowiek, który klika
   „Zgłoś" pod treścią naruszającą prawo, zostaje na ścieżce bez obowiązków
   z art. 16 i nikt go nie kieruje dalej.

### (d) Co wolno napisać w regulaminie, a czego nie

**WOLNO, dosłownie:**

> „Jeśli uważasz, że treść w Kuking narusza prawo, zgłoś ją formularzem pod
> adresem kuking.pl/zglos-nielegalna-tresc. Nie musisz mieć konta w Kuking
> i nie musisz się logować."

> „W formularzu prosimy o adres strony z tą treścią, o wyjaśnienie własnymi
> słowami, dlaczego uważasz ją za niezgodną z prawem, o imię i nazwisko albo
> nazwę instytucji oraz o oświadczenie, że w dobrej wierze uważasz podane
> informacje za prawdziwe i pełne. Adres e-mail jest nieobowiązkowy."

> „Jeśli podasz adres e-mail, wyślemy na niego potwierdzenie odbioru z numerem
> sprawy, a po rozpatrzeniu — informację o naszej decyzji wraz z powodem
> i informacją, co możesz zrobić dalej. Napiszemy także wtedy, gdy uznamy,
> że treść zostaje."

> „Jeśli nie podasz adresu e-mail, numer sprawy pokażemy na ekranie po
> wysłaniu zgłoszenia. Nie będziemy wtedy mieli jak poinformować Cię
> o decyzji."

> „Przycisk «Zgłoś» widoczny pod treścią służy do zgłaszania naruszeń zasad
> Kuking i wymaga zalogowania. Zgłoszenie treści niezgodnej z prawem ma osobny
> formularz, dostępny bez konta."

**NIE WOLNO:**

> ~~„Zgłoszenie możesz złożyć anonimowo."~~
> Nieprawda: imię/nazwa jest wymagana i przez formularz (`:50`), i przez
> CHECK w bazie. Nie wolno tego napisać nawet dla zgłoszeń dotyczących
> krzywdzenia dzieci, dopóki kod nie zwolni z tego pola.

> ~~„Każde zgłoszenie potwierdzamy."~~
> Nieprawda dla zgłoszenia bez adresu e-mail (`ZglosNielegalnaTresc.php:85`)
> i nieprawda dla całej drogi społecznościowej (`ReportController.php:83-86`
> pokazuje komunikat na ekranie, ale nie wysyła nic).

> ~~„O decyzji informujemy każdego, kto złożył zgłoszenie."~~
> Nieprawda: `ModerationController.php:183` wysyła wyłącznie przy
> `source = legal_notice` i tylko gdy jest adres. Zgłaszający przez przycisk
> „Zgłoś" nie dostaje niczego.

> ~~„Zgłoszenia rozpatrujemy w ciągu 48 godzin."~~ / ~~„...bez zbędnej
> zwłoki, zwykle tego samego dnia."~~
> Nie da się tego utrzymać: w kodzie **nie ma żadnego terminu dla zgłoszeń**
> ani żadnego pomiaru — patrz §4. Uwaga: takie zdanie **jest już** w
> `resources/legal/zasady.md:24` i nie ma pod nim nic w kodzie.

> ~~„Formularz zgłoszenia znajdziesz przy każdej treści."~~
> Nieprawda: jedyne wejście do formularza prawnego to stopka
> (`layout.blade.php:402`). Przy treści jest wyłącznie droga społecznościowa.

---

## 2. Art. 17 — uzasadnienie decyzji dla obu stron

### (b) Co jest w kodzie

**Dla AUTORA treści (art. 17):**

- `app/Http/Controllers/Admin/ModerationController.php:160-171` — wywołanie
  `NotifyModerationDecision` **w tej samej transakcji co decyzja** (`:123`),
  więc powiadomienie nie może powstać bez decyzji ani decyzja bez
  powiadomienia,
- `app/Domain/Moderation/Actions/NotifyModerationDecision.php:109-132` —
  tworzy wiersz w `notifications`: `title` (`:59-66`), `message` napisany
  przez moderatora albo tekst zastępczy (`:78-85, :117`), `appeal` = czy
  decyzja jest odwoływalna (`:126`), `action_id` = identyfikator decyzji
  (`:130`),
- `:22-47` — uzasadnienie, dlaczego ta akcja **omija** wspólną bramkę
  powiadomień: konto zawieszone i zbanowane też musi dostać wiadomość,
- `app/Domain/Moderation/Actions/NotifyModerationDecision.php:41-47` —
  kanał dla osoby zbanowanej to ekran logowania
  (`EnsureAccountIsActive`, `LoginController`), nie lista powiadomień,
- `resources/views/pages/notifications.blade.php:61-86` — render: nagłówek,
  treść moderatora i zdanie o odwołaniu; `:126-133` — przycisk „Odwołanie
  od tej decyzji",
- `moderation_actions.user_message` — kolumna trzymająca **treść realnie
  wysłaną człowiekowi**; migracja
  `2026_09_05_001000_create_trust_and_safety_tables.php:58-59`, obecna
  w żywej bazie (`\d moderation_actions`).

**Dla ZGŁASZAJĄCEGO (art. 16 ust. 5, obowiązek osobny od art. 17):**

- `app/Notifications/DecyzjaWSprawieZgloszenia.php:83-100` — **trzy** warianty
  komunikatu, nie dwa: `hide`/`remove` → „treść nie jest już dostępna";
  `warn`/`suspend`/`ban` → „zgłoszenie zasadne, ale treść zostaje";
  `no_action` → „treść zostaje, nie znaleźliśmy naruszenia". Lista akcji
  zdejmujących treść jest jawna i wąska (`:45-48`),
- `:87-93` — **świadomie nie piszemy, co zrobiliśmy z kontem autora**: to dane
  osobowe osoby trzeciej,
- `:102-111` — pouczenie: kontakt na `contact_email` z numerem sprawy,
  wzmianka o pozasądowym organie rozstrzygania sporów i o sądzie.

**Nie ma automatycznej moderacji.** Sprawdzone: `ModerationController::decide()`
wymaga `authorize('moderate', User::class)` (`:66`), macierz dozwolonych
decyzji `ModerationAction::DOZWOLONE` (`app/Models/ModerationAction.php:77-93`)
jest stosowana wyłącznie z formularza, w `app/Domain/Moderation` nie ma
żadnego automatu ani progu liczby zgłoszeń, a `ReportContent` nie ukrywa
niczego samo. To jest fakt użyteczny dla art. 14 i art. 17 ust. 3 lit. c.

### (c) Czy to wystarcza

**Nie w pełni.** Art. 17 ust. 3 wymienia elementy, których w powiadomieniu
**nie ma**:

| Element art. 17 ust. 3 | Stan w kodzie |
|---|---|
| lit. a — czego decyzja dotyczy (usunięcie, ukrycie, ograniczenie, zawieszenie) | **JEST** — `NotifyModerationDecision.php:59-66` |
| lit. b — fakty i okoliczności, na których oparto decyzję, **w tym czy decyzja jest skutkiem zgłoszenia** | **BRAK.** `reason_code` i `note` zostają wewnętrzne (`ModerationController.php:148`, kolumny `moderation_actions.reason_code`/`note`). Powiadomienie niesie tylko nieobowiązkowe `user_message` (`:86, :149`) albo tekst zastępczy (`NotifyModerationDecision.php:78-85`), który nie mówi o faktach. **Nigdzie nie jest napisane, czy decyzja wynikła ze zgłoszenia, czy z własnej inicjatywy.** |
| lit. c — czy użyto środków automatycznych | **BRAK zdania.** Automatyki nie ma (sprawdzone wyżej), więc zdanie „decyzję podjął człowiek" byłoby prawdziwe — ale w powiadomieniu go nie ma. |
| lit. d/e — podstawa prawna przy nielegalności albo podstawa umowna (punkt regulaminu) przy naruszeniu zasad | **BRAK.** `reason_code` to swobodny `string` do 80 znaków (`ModerationController.php:84`), bez słownika i bez odesłania do punktu regulaminu, i **nie trafia do człowieka**. |
| lit. f — informacja o środkach odwoławczych: skarga wewnętrzna, pozasądowe rozstrzyganie sporów, droga sądowa | **CZĘŚCIOWO.** `notifications.blade.php:74-75` mówi tylko „możesz się odwołać". **Ani słowa o organie pozasądowym, ani o sądzie.** Zgłaszający dostaje pełniejsze pouczenie niż autor (`DecyzjaWSprawieZgloszenia.php:109-110`) — to jest odwrotność tego, czego wymaga przepis. |

Dodatkowo: **termin na odwołanie nie jest podany w powiadomieniu.**
`ModerationAction::appealDeadline()` (`app/Models/ModerationAction.php:194-197`)
istnieje i jest pokazywany dopiero na stronie sprawy
(`resources/views/pages/appeals/create.blade.php:56-57`) — i tylko wtedy, gdy
termin **już minął**. Człowiek, który przeczyta powiadomienie i odłoży
sprawę, nie dowie się z niego, że ma 14 dni.

**Jedna rzecz jest lepsza niż wymaga przepis** i warto to zapisać:
`DecyzjaWSprawieZgloszenia.php:45-48, 83-100` nie pozwala już powiedzieć
zgłaszającemu nieprawdy o skutku decyzji (poprawka z commita `ed40c79`,
audyt N06), a test `tests/Feature/OdpowiedzDlaZglaszajacegoMowiPrawdeTest.php`
tego pilnuje.

### (d) Co wolno napisać w regulaminie, a czego nie

**WOLNO, dosłownie:**

> „Kiedy ukrywamy albo usuwamy Twoją treść, ostrzegamy Cię, zawieszamy albo
> blokujemy konto — dostajesz o tym powiadomienie w serwisie razem
> z wiadomością od moderacji. Jeśli konto jest zablokowane, tę wiadomość
> zobaczysz na ekranie logowania."

> „Decyzje moderacyjne podejmuje człowiek. Nie używamy automatów, które same
> ukrywają, usuwają albo blokują treści i konta."

> „Przy każdej decyzji zapisujemy jej powód i treść wiadomości, którą Ci
> wysłaliśmy — żeby dało się rozpatrzyć Twoje odwołanie."

> „O decyzji w sprawie zgłoszenia informujemy zgłaszającego także wtedy, gdy
> uznamy, że treść zostaje — razem z powodem."

**NIE WOLNO:**

> ~~„W uzasadnieniu decyzji podajemy podstawę prawną albo punkt regulaminu,
> który został naruszony."~~
> Nieprawda: `reason_code` jest swobodnym tekstem bez słownika
> (`ModerationController.php:84`) i **nie trafia do użytkownika**.

> ~~„Informujemy Cię, czy decyzja jest wynikiem zgłoszenia innego
> użytkownika."~~
> Nieprawda: powiadomienie nie zawiera tej informacji, a
> `NotifyModerationDecision.php:100-105` **świadomie** nie mówi o zgłoszeniu.

> ~~„W uzasadnieniu informujemy o możliwości skargi do organu pozasądowego
> i o drodze sądowej."~~
> Nieprawda dla autora treści: `notifications.blade.php:74-75` mówi tylko
> o odwołaniu w serwisie. (Dla zgłaszającego to zdanie **jest** prawdziwe —
> `DecyzjaWSprawieZgloszenia.php:109-110` — więc wolno je napisać tylko
> w części o zgłoszeniach, nie w części o decyzjach wobec autora.)

> ~~„W powiadomieniu podajemy termin, do którego możesz się odwołać."~~
> Nieprawda: `appealDeadline()` nie jest w powiadomieniu.

---

## 3. Art. 20 — wewnętrzny system rozpatrywania skarg

### (b) Co jest w kodzie

**Tabela `appeals`** — migracja
`database/migrations/2026_09_06_100100_create_appeals_table.php:47-83`.
Stan **w żywej bazie** (`\d appeals`):

```
moderation_action_id  uuid NOT NULL  UNIQUE  FK → moderation_actions ON DELETE CASCADE
user_id               uuid NOT NULL          FK → users ON DELETE CASCADE
body                  varchar(2000) NOT NULL
status                varchar(20)   NOT NULL DEFAULT 'open'
decided_by, decision_note, decided_at

appeals_status_check             CHECK (status IN ('open','upheld','overturned'))
appeals_decision_complete_check  CHECK ((status='open' AND decided_at IS NULL
                                         AND decision_note IS NULL)
                                     OR (status<>'open' AND decided_at IS NOT NULL
                                         AND decision_note IS NOT NULL))
```

Do tego `moderation_actions_one_per_report` — częściowy UNIQUE na
`report_id WHERE report_id IS NOT NULL` (migracja
`2026_09_06_190000_one_decision_per_report.php:61-63`), potwierdzony
w `\d moderation_actions`.

**Złożenie odwołania:** `app/Domain/Moderation/Actions/FileAppeal.php:41-96`
— trzy reguły w warstwie domenowej, nie w kontrolerze (uzasadnienie `:17-38`,
bo są **dwie** drogi wejścia): odwołuje się ten, kogo decyzja dotyczy
(`:43-45`), tylko od decyzji z listy `ODWOLYWALNE` (`:47-49`), jedno odwołanie
na decyzję (`:51-58` + UNIQUE w bazie, przechwycone `:76-82`), w terminie
(`:60-67`). Wpis do audytu `:84-93`.

**Dwie drogi wejścia:**
- zalogowany — `routes/web.php:399-402`,
  `app/Http/Controllers/AppealController.php:64-98`;
- **zablokowany, przed logowaniem** — `routes/web.php:202-205`,
  `AppealController.php:107-158`; formularz sprawdza hasło, ale **nie loguje
  i nie zdejmuje blokady** (`:130-135`), a decyzję dobiera sam
  (`:169-178`). Uzasadnienie wyboru „formularz zamknięty hasłem" wraz z ceną
  tego wyboru: `:20-50`.

**Rozpatrzenie:** `app/Domain/Moderation/Actions/ResolveAppeal.php:49-102`
— uzasadnienie **obowiązkowe** (`:64-68`), cofnięcie decyzji **realnie
cofa skutki** (`:76-78, :131-168`: `RestoreContent` albo
`User::reinstate()`), powiadomienie idzie zawsze (`:87`), audyt `:89-99`.
**Karencja 24 godzin na podtrzymanie własnej decyzji** — `:104-121`,
próg w `config/kuking.php:578`; cofnięcie własnej decyzji działa
natychmiast (uzasadnienie `:31-35`).

**Odpowiedź do człowieka:**
`app/Domain/Moderation/Actions/NotifyAppealOutcome.php:26-50` — typ
`moderation`, żeby zbanowany zobaczył ją na ekranie logowania (`:13-21`),
`message` = uzasadnienie moderatora (`:42`), `appeal = false`, bo odwołanie
od odwołania nie istnieje (`:44-46`).

**Kolejka moderatora:** `app/Http/Controllers/Admin/AppealController.php:50`
oraz `resources/views/pages/admin/appeals.blade.php:28-31` — najstarsze
otwarte na górze, przeterminowane oznaczone wprost.

### Terminy: kod kontra baza

To było pytanie zadane wprost, więc odpowiedź wprost:

| Termin | Wartość | Gdzie egzekwowany | Czy baza go zna |
|---|---|---|---|
| złożenie odwołania | **14 dni** od decyzji | `config/kuking.php:560`; `ModerationAction::appealDeadline()` (`app/Models/ModerationAction.php:194-197`), sprawdzane w `FileAppeal.php:60-67` | **NIE.** Migracja `2026_09_06_100100:26-28` mówi to wprost: CHECK nie sięga do drugiej tabeli. |
| odpowiedź na odwołanie | **7 dni roboczych** (`addWeekdays`, bez świąt) | `config/kuking.php:566`; `Appeal::responseDeadline()` (`app/Models/Appeal.php:89-94`), `isOverdue()` (`:97-100`) | **NIE.** To wyłącznie **cel operacyjny pokazywany moderatorowi**, nie zobowiązanie egzekwowane przez system. Komentarz `Appeal.php:84-87` mówi to wprost. |
| karencja na podtrzymanie własnej decyzji | **24 godziny** | `config/kuking.php:578`; `ResolveAppeal.php:104-121` | **NIE** |
| jedno odwołanie na jedną decyzję | — | `FileAppeal.php:51-58` | **TAK** — `UNIQUE (moderation_action_id)` |
| odwołanie zamknięte ma datę **i** uzasadnienie | — | `ResolveAppeal.php:64-68` | **TAK** — `appeals_decision_complete_check` |

**Rozjazdu między kodem a bazą nie ma** — baza po prostu pilnuje mniej
i dokumentuje, czego nie pilnuje. Wszystkie trzy terminy są w
`config/kuking.php`, nie rozsiane po kodzie, i wszystkie trzy zgadzają się
z `docs/legal/MODERATION_PLAYBOOK.md`.

### (c) Czy to wystarcza

**Nie. Trzy braki, z czego pierwszy jest poważny.**

1. **Termin 14 dni zamiast sześciu miesięcy.** Art. 20 ust. 1 wymaga dostępu
   do wewnętrznego systemu rozpatrywania skarg **przez co najmniej sześć
   miesięcy** od decyzji. W kodzie jest 14 dni (`config/kuking.php:560`),
   egzekwowanych twardo (`FileAppeal.php:60-67`) i po tym terminie zostaje
   wyłącznie adres e-mail (`AppealController.php:141-143`,
   `appeals/create.blade.php:59-62`). **Jeśli art. 20 stosuje się do
   Kuking, ten termin jest niezgodny.** Czy się stosuje — patrz §5.
2. **Zgłaszający nie ma dostępu do tego systemu w ogóle.**
   `appeals.user_id` jest `NOT NULL` z FK do `users` (potwierdzone
   w `\d appeals`), a `FileAppeal.php:43-45` wymaga, by decyzja dotyczyła
   konta odwołującego się. Osoba, która złożyła zgłoszenie z art. 16
   **bez konta, nie ma jak złożyć skargi** — zostaje jej adres e-mail
   z `DecyzjaWSprawieZgloszenia.php:107-108`. Art. 20 ust. 1 obejmuje
   skargi „od osób i podmiotów, które złożyły zgłoszenia".
3. **Od decyzji `no_action` nie ma odwołania.**
   `app/Models/ModerationAction.php:105-111` — `ODWOLYWALNE` świadomie
   pomija `ACTION_NONE` (uzasadnienie `:98-101`). Art. 20 ust. 1 wymienia
   wprost decyzje o **niepodjęciu działania** w reakcji na zgłoszenie.
   Razem z punktem 2 znaczy to, że **odrzucenie zgłoszenia nie da się
   zakwestionować nigdzie w produkcie.**

Jedno zdanie w kodzie warto zmierzyć osobno, bo jest obietnicą bez pokrycia:
`DecyzjaWSprawieZgloszenia.php:107-108` mówi zgłaszającemu „sprawa wróci do
człowieka, który jej wcześniej nie prowadził". **Nic w kodzie tego nie
zapewnia**, a `docs/legal/MODERATION_PLAYBOOK.md` (cytowany
w `ResolveAppeal.php:24-29`) zakłada zespół 1-2 osób, dla którego wymóg
„ktoś inny" jest nie do spełnienia. Kod egzekwuje jedynie 24 godziny
karencji przy podtrzymaniu własnej decyzji — i tylko na drodze odwołania
autora, nie na tej mailowej. **To zdanie już jest wysyłane** i regulamin nie
może go powtórzyć. Dla porównania, zdanie z
`resources/views/pages/appeals/create.blade.php:71` („trafi do osoby, która
obejrzy sprawę drugi raz") jest ostrożniejsze i **da się** obronić.

### (d) Co wolno napisać w regulaminie, a czego nie

**WOLNO, dosłownie:**

> „Od decyzji, która ukryła albo usunęła Twoją treść, dała Ci ostrzeżenie,
> zawiesiła albo zablokowała Twoje konto, możesz się odwołać w serwisie.
> Przycisk «Odwołanie od tej decyzji» jest w powiadomieniu o decyzji."

> „Jeśli Twoje konto jest zablokowane i nie możesz się zalogować, odwołanie
> złożysz na stronie kuking.pl/odwolanie — podając nazwę konta i hasło.
> To nie loguje Cię do serwisu i nie zdejmuje blokady, służy tylko do
> przypisania odwołania do konta."

> „Na odwołanie masz 14 dni od decyzji. Od jednej decyzji odwołujesz się raz."
> — **tylko** jeśli właściciel świadomie przyjmuje ryzyko z art. 20 ust. 1
> (sześć miesięcy). Patrz §5 i lista braków.

> „Odwołanie jest bezpłatne."

> „Odwołanie rozpatruje człowiek. Odpowiedź — podtrzymanie albo cofnięcie
> decyzji — zawsze zawiera wyjaśnienie."

> „Jeśli cofniemy decyzję, Twoja treść wraca do stanu sprzed decyzji,
> a zablokowane albo zawieszone konto odzyskuje dostęp."

> „Staramy się odpowiadać na odwołania w ciągu 7 dni roboczych."
> — z zastrzeżeniem: **wolno tylko z „staramy się"**. Kod pokazuje ten termin
> moderatorowi i oznacza przeterminowane sprawy, ale niczego nie wymusza
> (`Appeal.php:84-87`).

**NIE WOLNO:**

> ~~„Odwołanie możesz złożyć w ciągu 6 miesięcy od decyzji."~~
> Nieprawda: `FileAppeal.php:60-67` odrzuci je po 14 dniach.

> ~~„Odpowiadamy na odwołania w ciągu 7 dni roboczych."~~ (bez „staramy się")
> Kod nie ma ani przypomnienia, ani eskalacji, ani żadnego mechanizmu
> dowożącego ten termin. Zostaje tylko oznaczenie w kolejce moderatora.

> ~~„Odwołanie rozpatruje inna osoba niż ta, która podjęła decyzję."~~
> Nieprawda: `ResolveAppeal.php:104-121` egzekwuje wyłącznie 24 godziny
> zwłoki, gdy ten sam moderator chce **podtrzymać** własną decyzję.
> Zabronione jest też każde zdanie w tym duchu — „druga osoba", „niezależny
> weryfikator", „człowiek, który nie prowadził sprawy".

> ~~„Jeśli złożyłeś zgłoszenie i nie zgadzasz się z naszą decyzją, możesz
> złożyć skargę w naszym wewnętrznym systemie."~~
> Nieprawda: `appeals.user_id NOT NULL` + `FileAppeal.php:43-45`. Zgłaszający
> bez konta ma tylko adres e-mail.

> ~~„Od decyzji o niepodjęciu działania w sprawie zgłoszenia można się
> odwołać."~~
> Nieprawda: `ModerationAction::ODWOLYWALNE` nie zawiera `no_action`.

> ~~„Kuking spełnia wymogi art. 20 DSA."~~ / ~~„Prowadzimy wewnętrzny system
> rozpatrywania skarg zgodny z DSA."~~
> Nie da się tego obronić przy 14 dniach, braku dostępu dla zgłaszającego
> i braku odwołania od `no_action`. Opisujcie **co system robi**, nie
> **któremu artykułowi odpowiada**.

---

## 4. Terminy i statystyki — art. 15 i pomiar czasu reakcji

### (b) Co jest w kodzie

**Mierzone jest jedno: termin odpowiedzi na ODWOŁANIE.**
`app/Models/Appeal.php:89-94` (`responseDeadline()`, `addWeekdays` z
`config/kuking.php:566`), `:97-100` (`isOverdue()`),
`resources/views/pages/admin/appeals.blade.php:28-31` (kolejka pokazuje
„termin odpowiedzi minął <data>"),
`app/Http/Controllers/Admin/AppealController.php:50` (sortowanie tak, żeby
przeterminowane nie zapadały się na dno).

**Dla ZGŁOSZEŃ nie mierzy się nic.** Sprawdzone wprost:

- `reports.created_at` i `reports.resolved_at` istnieją (`\d reports`), ale
  `grep -rn "resolved_at" app/` daje **wyłącznie trzy trafienia**:
  `app/Models/Report.php:80` (`$fillable`), `:86` (cast) i
  `app/Http/Controllers/Admin/ModerationController.php:196` (zapis
  `now()`). **Żadne miejsce w kodzie nie liczy różnicy między tymi
  kolumnami.**
- `Report` **nie ma** metody `responseDeadline()` ani `isOverdue()` —
  w przeciwieństwie do `Appeal`.
- `resources/views/pages/admin/reports.blade.php` nie pokazuje żadnego
  terminu; kolejka ma tylko licznik po statusach
  (`ModerationController.php:56-60`).
- Indeks `reports_pending_receipt_idx` **istnieje** i **jest przygotowany**
  pod wyszukiwanie zgłoszeń bez potwierdzenia odbioru, ale w kodzie nie ma
  ani jednego zapytania, które by go użyło — `grep -rn "receipt_sent_at"`
  daje tylko `Report.php:88` (cast) i `ZglosNielegalnaTresc.php:89` (zapis).
  To jest indeks pod raport, którego nikt jeszcze nie pisze.

**Raportu przejrzystości (art. 15) nie ma w kodzie w żadnej postaci.**
Sprawdzone: `grep -rn "przejrzyst|transparency|art. 15|art. 24|aktywnych
odbiorc|MAU" app/ config/` nie zwraca ani jednego trafienia dotyczącego
sprawozdawczości DSA (trafienia na „art. 15" dotyczą wyłącznie RODO —
eksportu danych: `app/Jobs/GenerateUserExport.php:27`,
`app/Domain/Users/Exports/CollectUserExportData.php:61`).
Brak polecenia konsolowego, brak zadania w `routes/console.php`, brak widoku.

**Art. 24 ust. 3 (średnia miesięczna liczba aktywnych odbiorców) — też nie.**
Jest `app/Domain/Analytics/WeeklyActiveCooks.php`, ale mierzy co innego:
**tygodniową** liczbę osób **publikujących** (`:11-21`), z wykluczeniem
konta gospodarza, kont zbanowanych, `pending_delete` i testowych
(`CookEligibility::excludedUserIds()`). Art. 24 ust. 3 mówi o **miesięcznej**
średniej **odbiorców** usługi, czyli także czytających. **Ta klasa nie
odpowiada na to pytanie i nie da się jej za nią podstawić.**

### (c) Czy to wystarcza

**Do niczego, co dotyczy terminów zgłoszeń, nie.** Art. 16 ust. 6 wymaga
rozpatrywania zgłoszeń „terminowo, w sposób niearbitralny i obiektywny".
Kod tego nie mierzy, więc **nikt — ani właściciel, ani regulator — nie umie
odpowiedzieć na pytanie „ile leży najstarsze nierozstrzygnięte
zgłoszenie"**, choć dane na to są.

**Zwolnienie z art. 19 dla art. 15 — patrz §5.** Nawet gdyby zwolnienie
działało, milczenie w regulaminie jest **dopuszczalne**: art. 14 wymaga
opisania polityki moderacji, nie publikowania statystyk. **Ale** milczenie
o zwolnieniu i **twierdzenie o zgodności** to dwie różne rzeczy — patrz
zdania zakazane.

### (d) Co wolno napisać w regulaminie, a czego nie

**WOLNO, dosłownie:**

> „Zgłoszenia czyta człowiek, nie automat."

> „Staramy się rozpatrywać zgłoszenia szybko. Sprawy dotyczące
> bezpieczeństwa dzieci traktujemy jako pilne."

> „Każde zgłoszenie i każdą decyzję moderacyjną zapisujemy razem z powodem
> i datą."

Milczenie o raporcie przejrzystości jest dopuszczalne: **nie ma obowiązku
pisania w regulaminie, że się z czegoś korzysta ze zwolnienia.**

**NIE WOLNO:**

> ~~„Zgłoszenia rozpatrujemy w ciągu 48 godzin."~~ / ~~„...tego samego
> dnia."~~ / ~~„...w ciągu 24 godzin."~~ / ~~„...w ciągu 7 dni."~~
> **Żadnej liczby.** W kodzie nie ma dla zgłoszeń ani terminu, ani pomiaru,
> ani przypomnienia. Uwaga: `resources/legal/zasady.md:24` **już zawiera**
> zdanie „Staramy się odpowiadać w ciągu 48 godzin, a sprawy poważne — tego
> samego dnia" i nie ma pod nim niczego w kodzie. To jest zdanie do
> usunięcia albo do dowiezienia kodem.

> ~~„Publikujemy roczne sprawozdanie z przejrzystości."~~ /
> ~~„Raz w roku publikujemy statystyki moderacji."~~
> Nieprawda: nic tego nie liczy i nic nie publikuje.

> ~~„Publikujemy średnią miesięczną liczbę aktywnych odbiorców usługi."~~
> Nieprawda: `WeeklyActiveCooks` liczy tygodniowo, tylko publikujących
> i z wykluczeniami.

> ~~„Jako mikroprzedsiębiorstwo jesteśmy zwolnieni z obowiązków
> sprawozdawczych DSA."~~
> **Tego nie wolno napisać nie z powodu kodu, a z powodu
> `docs/decyzje/OPERATOR.md` §3** — patrz §5. Nie wolno w regulaminie
> twierdzić o własnym statusie prawnym rzeczy, która jest w tym repozytorium
> opisana jako otwarta luka interpretacyjna. To zdanie, raz opublikowane,
> jest oświadczeniem wobec regulatora.

---

## 5. Punkt kontaktowy (art. 11/12) — i pytanie o mikroprzedsiębiorstwo (art. 19)

### (b) Co jest w kodzie

**Jeden adres, jedno źródło:** `config/kuking.php:528-530` —
`'contact_email' => env('KUKING_CONTACT_EMAIL', 'kontakt@kuking.pl')`,
z komentarzem „Adres, na który idą zgłoszenia i sprawy moderacyjne".

Adres jest czytany z konfiguracji w **30 miejscach** kodu i widoków (nigdzie nie jest
wpisany na sztywno), m.in.:
`app/Notifications/DecyzjaWSprawieZgloszenia.php:107`,
`app/Domain/Moderation/Actions/FileAppeal.php:55, :64`,
`app/Http/Controllers/AppealController.php:142`,
`app/Http/Middleware/EnsureAccountIsActive.php`,
`app/Http/Controllers/Auth/LoginController.php`,
`resources/views/pages/static/about.blade.php`,
`resources/views/pages/static/help.blade.php`,
`resources/views/pages/appeals/create.blade.php:48, :61`,
`resources/views/pages/appeals/guest.blade.php`,
`resources/views/errors/500.blade.php`.

**Adres wysyłkowy jest INNY:** `.env:94` i `config/mail.php:127` —
`MAIL_FROM_ADDRESS = "kuchnia@kuking.pl"`. Czyli listy przychodzą
**od `kuchnia@`**, a odpowiedzi mają iść **na `kontakt@`**.

**W tym środowisku poczta nie wychodzi w ogóle:** `.env:88` —
`MAIL_MAILER=log` (to samo w `.env.example:98`).

**Punkt kontaktowy w interfejsie:** stopka
(`resources/views/components/layout.blade.php:390-402`) ma linki
O Kuking / Pomoc / Zasady / Regulamin / Prywatność / **Zgłoś nielegalną
treść** — **ale nie ma w niej adresu e-mail.** Adres jest na `/o-kuking`
i `/pomoc`.

**W plikach prawnych adresu nie ma wcale** — są placeholdery:
`resources/legal/regulamin.md:15` („prowadzi **[NAZWA OPERATORA]**,
z siedzibą w **[ADRES]**. Kontakt: **[E-MAIL KONTAKTOWY]**"), `:19`
(ten sam adres jako kontakt dla organów nadzorujących), `:99`, `:115`;
`resources/legal/polityka-prywatnosci.md:15, :93`.

### (c) Czy to wystarcza

**Nie da się ustalić z kodu, czy `kontakt@kuking.pl` jest obsługiwaną
skrzynką.** Kod **wysyła** z `kuchnia@`, a **wskazuje** `kontakt@`.
Repozytorium nie zawiera konfiguracji poczty przychodzącej ani rekordów MX —
`docs/decyzje/POCZTA.md` to dokument **decyzyjny** (rekomendacja EmailLabs),
nie zapis stanu wdrożenia, i dotyczy wyłącznie poczty **wychodzącej**.
Tego trzeba sprawdzić poza kodem, zanim regulamin poda ten adres jako punkt
kontaktowy — a nie odwrotnie.

Dalej: **art. 11 wymaga wskazania języka lub języków komunikacji z organami.**
W kodzie i konfiguracji nie ma o tym ani słowa. `regulamin.md:19` mówi, że
ten sam adres służy organom nadzorującym, ale języka nie podaje.

**Art. 12 wymaga, by punkt kontaktowy dla użytkowników nie był wyłącznie
formularzem ani botem.** Tu jest w porządku: adres e-mail jest podany
w wielu miejscach i jest adresem człowieka, nie `noreply@`.

### Art. 19 — zwolnienie dla mikroprzedsiębiorstw

**To nie jest pytanie o kod i nie wolno go rozstrzygnąć w regulaminie.**
Dwa dokumenty w tym repozytorium mówią co innego:

- `docs/legal/COMPLIANCE.md:5` i §1.2 **zakładają**, że Kuking jest zwolniony
  z Sekcji 3 jako mikro/małe przedsiębiorstwo, i wymieniają art. 20 jako
  „formalnie niewymagany, ale rekomendowany dobrowolnie";
- `docs/decyzje/OPERATOR.md` §3 („Paradoks DSA") mówi, że
  **to założenie jest prawdziwe dla JDG i sp. z o.o., a wątpliwe dla osoby
  fizycznej bez działalności** — bo „przedsiębiorstwo" w rozumieniu Zalecenia
  2003/361/WE to podmiot prowadzący działalność gospodarczą, a operator
  prowadzi **działalność nierejestrowaną**. Ten sam dokument oznacza to jako
  `[do weryfikacji z prawnikiem — luka interpretacyjna, nie ustalony stan
  prawny]` i stawia to jako jedno z trzech pytań do godziny u prawnika.

**Konsekwencja praktyczna dla regulaminu:** zwolnienie z art. 19 dotyczy
art. 20 (skargi), art. 21 (ODS), art. 22 (zaufani sygnaliści),
art. 23 i art. 24 poza ust. 3 — czyli **dokładnie tych obowiązków, których
braki wyliczyłem w §3 i §4**. Jeśli zwolnienie działa, te braki są ryzykiem
niskim. Jeśli nie działa, 14-dniowy termin z §3 jest naruszeniem.

**Czy wolno o tym milczeć w regulaminie? Tak — i należy.** Regulamin ma
opisywać, co serwis robi, nie kwalifikować prawnie operatora. Powołanie się
w regulaminie na zwolnienie z art. 19 byłoby oświadczeniem, które
`OPERATOR.md` §3 wprost nazywa niepewnym, i które trudno wycofać.
**Milczenie o zwolnieniu nie jest przemilczeniem obowiązku.**

### (d) Co wolno napisać w regulaminie, a czego nie

**WOLNO, dosłownie** (po podstawieniu prawdziwych danych i po sprawdzeniu
poza kodem, że skrzynka odbiera):

> „Serwis Kuking.pl prowadzi [imię i nazwisko / nazwa], [adres]. Kontakt:
> [adres e-mail]."

> „Na ten adres e-mail możesz napisać w każdej sprawie dotyczącej serwisu,
> swojego konta i decyzji moderacyjnych. Odpisuje człowiek."

> „Ten sam adres jest punktem kontaktowym dla organów państw członkowskich,
> Komisji Europejskiej i Rady Usług Cyfrowych. Komunikujemy się w języku
> polskim [i angielskim]."
> — wybór języków to decyzja właściciela; **kod nie zawiera tej informacji
> nigdzie i musi ją dostać, jeśli ma być prawdziwa.**

> „Formularz zgłoszenia treści niezgodnej z prawem znajdziesz w stopce każdej
> strony pod nazwą «Zgłoś nielegalną treść»."

**NIE WOLNO:**

> ~~Podać `kontakt@kuking.pl` jako punkt kontaktowy, dopóki nie jest
> potwierdzone poza kodem, że ta skrzynka istnieje i jest czytana.~~
> Kod wysyła z `kuchnia@kuking.pl` (`.env:94`), a wskazuje `kontakt@`
> (`config/kuking.php:530`). **Z kodu nie da się ustalić, która z nich
> odbiera.** Art. 11 i 12 wymagają punktu, który **działa**, nie który jest
> wpisany.

> ~~„Jesteśmy mikroprzedsiębiorstwem w rozumieniu art. 19 DSA i korzystamy
> ze zwolnienia z Sekcji 3."~~
> `docs/decyzje/OPERATOR.md` §3 nazywa to otwartą luką interpretacyjną przy
> działalności nierejestrowanej.

> ~~„Kuking spełnia wszystkie obowiązki wynikające z DSA."~~
> Nieprawda przy brakach z §2, §3 i §4 — niezależnie od tego, jak wypadnie
> pytanie o art. 19.

> ~~„W sprawach DSA napisz do naszego punktu kontaktowego dla organów przez
> formularz."~~
> Art. 12 wymaga bezpośredniej komunikacji, nie samego formularza. (Kod tego
> nie łamie — adres jest podany — ale zdanie byłoby regresem.)

---

## 6. Art. 18 — zgłaszanie podejrzeń przestępstw organom

**Nie ma tego w kodzie.** Sprawdzone: `grep -rn "Policj|Dyżurnet|prokurat|CSAM"
app/` nie zwraca ani jednego trafienia (dwa trafienia na „organ" dotyczą
organu pozasądowego w `DecyzjaWSprawieZgloszenia.php:109` i sformułowania
„organ odwoławczy" w komentarzu `ResolveAppeal.php:25`). Nie ma pola,
znacznika ani wpisu w audycie, który mówiłby „zgłoszono organom".
Procedura żyje wyłącznie w `docs/legal/MODERATION_PLAYBOOK.md`
i `resources/legal/zasady.md:20`.

**WOLNO:**

> „Treści przedstawiające krzywdzenie dzieci usuwamy natychmiast i zgłaszamy
> odpowiednim organom."
> — to jest **obietnica proceduralna, nie funkcja produktu**, i wolno ją
> utrzymać, o ile właściciel realnie zna ścieżkę zgłoszenia. To jest jedno
> z czterech zastrzeżeń z `AGENTS.md`-owego mandatu: **obietnica
> player-visible, której kod nie dowozi, wymaga decyzji właściciela.**

**NIE WOLNO:**

> ~~„Zgłoszenia do organów rejestrujemy w systemie."~~ /
> ~~„Prowadzimy rejestr zgłoszeń do organów ścigania."~~
> Nieprawda: nie ma takiego pola ani wpisu.

---

## 7. Czy zewnętrzny audyt miał rację co do braku formularza

**Samego dokumentu audytu nie ma w repozytorium.** Sprawdzone:
`git grep "N07"` na wszystkich gałęziach trafia wyłącznie w plik binarny
`docs/design/kit-v2/mockups/11_brand_materials.png` (przypadkowy ciąg
w obrazku). Kody `N0x` występują w **jednym** miejscu w historii — commit
`ed40c79` „Odpowiedź dla zgłaszającego przestaje mówić nieprawdę (audyt N06)",
którego treść podaje źródło: **zewnętrzny audyt GPT-6 Astra**. Ten sam audyt
jest wspomniany w `docs/research/ANALITYKA_STAN_WDROZENIA.md:19` (pozycja
U13). **Treści N07 i N08 nie da się ustalić z tego repozytorium** — nie
zgaduję, o czym mówią.

**Co da się rozstrzygnąć: samą tezę „brak formularza zgłoszenia treści
nielegalnej".**

**Ta teza była prawdziwa i przestała być.** Formularz wszedł commitem
`e45c8f2` „Zgłoszenie nielegalnej treści bez konta (DSA art. 16)". Dziś
istnieje:

- publiczna trasa poza `auth` — `routes/web.php:477-483`,
- kontroler z pełnym zestawem pól z art. 16 ust. 2 —
  `ZgloszenieNielegalnejTresciController.php:49-67`,
- akcja domenowa z potwierdzeniem odbioru —
  `ZglosNielegalnaTresc.php:50-93`,
- CHECK-i w bazie, **potwierdzone w żywej bazie** —
  `reports_legal_notice_complete_check`, `reports_source_check`,
- link w stopce, czyli mechanizm „łatwo dostępny" —
  `layout.blade.php:402`,
- ekran bez `noindex`, żeby dało się go znaleźć w wyszukiwarce —
  `zglos-nielegalna-tresc.blade.php:17-26`,
- **11 testów**, w tym `test_gosc_bez_konta_moze_zlozyc_zgloszenie`,
  `test_zglaszajacy_dostaje_potwierdzenie_odbioru`,
  `test_zgloszenie_bez_adresu_email_jest_dopuszczalne`,
  `test_nierozpoznany_adres_nie_odrzuca_zgloszenia`,
  `test_formularz_da_sie_znalezc` —
  `tests/Feature/ZgloszenieNielegalnejTresciTest.php`. Uruchomione: przechodzą.

**Ale audyt nadal ma rację w trzech węższych punktach**, jeśli jego zarzut
dotyczył czegoś innego niż samo istnienie formularza:

1. zgłoszenie **nie jest anonimowe** — imię jest wymagane także tam, gdzie
   art. 16 ust. 2 lit. c z tego zwalnia (§1 punkt 1);
2. droga społecznościowa **zbiera powody nielegalności bez obowiązków
   z art. 16** i nie kieruje nikogo do formularza prawnego (§1 punkt 4);
3. `docs/legal/COMPLIANCE.md:248-249` **nadal wymienia** „Formularz «Zgłoś»
   spełniający Art. 16 DSA" i „Szablon uzasadnienia decyzji (Art. 17)
   wdrożony w produkcie" jako **niezrobione P0** — dokumentacja jest o dwa
   commity za kodem.

---

## 8. Braki, które trzeba dowieźć KODEM, zanim regulamin je obieca

Kolejność: (waga naruszenia) × (czy blokuje zdanie, które regulamin musi
zawierać). Nie kolejność łatwości.

### P0 — blokują zdania, bez których regulamin nie może się obejść

**B-1. Termin na odwołanie: 14 dni → sześć miesięcy (art. 20 ust. 1).**
`config/kuking.php:560`, egzekwowane w `FileAppeal.php:60-67`. Sama zmiana
liczby jest jednolinijkowa, ale nie jest darmowa: wydłużenie do 180 dni
zmienia kolejkę moderatora i sens `moderation_actions_one_per_report`, więc
wymaga decyzji. **Dopóki nie zostanie rozstrzygnięte pytanie o art. 19
z `OPERATOR.md` §3, regulamin nie może twierdzić zgodności z art. 20 ani
podawać 14 dni jako terminu „zgodnego z DSA".** Wolno napisać samo „14 dni",
bez kwalifikacji prawnej.

**B-2. Zgłaszający nie ma dostępu do wewnętrznego systemu skarg
(art. 20 ust. 1) i nie ma odwołania od `no_action`.**
`appeals.user_id NOT NULL` (żywa baza), `FileAppeal.php:43-45`,
`ModerationAction::ODWOLYWALNE` (`app/Models/ModerationAction.php:105-111`).
Wymaga: dopuszczenia `user_id = NULL` z powiązaniem przez `report_id`, albo
osobnej ścieżki skargi po numerze sprawy i adresie e-mail. To jest zmiana
schematu, więc pełna czwórka z `AGENTS.md` §6 (migracja, test,
`docs/DATABASE.md`, rollback).

**B-3. Uzasadnienie decyzji dla autora nie zawiera trzech elementów
z art. 17 ust. 3.** Brakuje: podstawy (punkt regulaminu albo podstawa
prawna, lit. d/e), informacji, czy decyzja wynikła ze zgłoszenia (lit. b),
zdania o braku automatyki (lit. c) oraz pouczenia o organie pozasądowym
i o sądzie (lit. f). Miejsca: `ModerationController.php:84` (`reason_code`
bez słownika), `NotifyModerationDecision.php:109-132` (co idzie do
powiadomienia), `resources/views/pages/notifications.blade.php:67-86`
(co widzi człowiek). Najtańsze rozwiązanie: **zamknięty słownik
`reason_code`** odsyłający do punktu regulaminu, plus dwa stałe zdania
w widoku. **Dopóki tego nie ma, regulamin nie może opisywać zawartości
uzasadnienia.**

**B-4. Potwierdzić poza kodem, czy `kontakt@kuking.pl` odbiera.**
`config/kuking.php:530` kontra `.env:94` (`kuchnia@kuking.pl`) i
`.env:88` (`MAIL_MAILER=log`). To nie jest zadanie programistyczne, ale
**blokuje** podanie adresu w regulaminie jako punktu kontaktowego z art. 11
i 12. Jeśli obsługiwaną skrzynką jest `kuchnia@`, to `contact_email` w
konfiguracji jest błędem, który cicho psuje wszystkie 18 miejsc, gdzie ten
adres pokazujemy człowiekowi (30 wystąpień `config('kuking.community.contact_email')`).

### P1 — psują mechanizm, który przepis nakazuje udostępnić

**B-5. Zgłoszenie z art. 16 ust. 2 lit. c wymaga imienia.**
`ZgloszenieNielegalnejTresciController.php:50` (`required`) plus
`reports_legal_notice_complete_check` w bazie (`notifier_name IS NOT NULL`).
Przepis zwalnia z danych zgłaszającego przy zgłoszeniach dotyczących
przestępstw z art. 3-7 dyrektywy 2011/93/UE. Trzeba **rozdzielić przypadki**
— powód `minor` bez wymogu nazwy — co dotyka i formularza, i CHECK-a
w bazie. Do czasu tej zmiany **nie wolno napisać, że zgłoszenie da się
złożyć anonimowo.**

**B-6. Droga społecznościowa nie kieruje do drogi prawnej i nie daje
odpowiedzi.** `resources/views/pages/report.blade.php` (zero wystąpień
`zglos.nielegalna`), `ReportController.php:58-63` (bez uzasadnienia
nielegalności i bez dobrej wiary), `ModerationController.php:183` (bez
powiadomienia). Minimum: jedno zdanie z linkiem w formularzu „Zgłoś" —
„Jeśli ta treść łamie prawo, zgłoś ją tutaj". **Bez tego regulamin nie może
napisać, że przycisk «Zgłoś» jest drogą do zgłoszenia treści niezgodnej
z prawem** — a `resources/legal/regulamin.md:99` dziś dokładnie to sugeruje.

**B-7. Zdanie „sprawa wróci do człowieka, który jej wcześniej nie prowadził"
jest już wysyłane i nie ma pod nim kodu.**
`app/Notifications/DecyzjaWSprawieZgloszenia.php:107-108`. Przy zespole
1-2 osób (`docs/legal/MODERATION_PLAYBOOK.md` §8, cytowane
w `ResolveAppeal.php:24-29`) to jest obietnica niewykonalna. Albo zmienić
treść listu, albo dowieźć mechanizm. **To jest zmiana tekstu widocznego dla
użytkownika, czyli decyzja właściciela**, nie agenta. Regulamin nie może
tego zdania powtórzyć w żadnej formie.

### P2 — brak pomiaru: nie da się dowieźć terminu, którego nikt nie liczy

**B-8. Nic nie mierzy czasu reakcji na zgłoszenie.**
`Report` nie ma odpowiednika `Appeal::responseDeadline()` ani `isOverdue()`
(`app/Models/Appeal.php:89-100`), `resolved_at` nie jest nigdzie odejmowane
od `created_at`, a kolejka zgłoszeń
(`resources/views/pages/admin/reports.blade.php`) nie pokazuje żadnego
terminu. Najtańsza wersja: te same dwie metody na `Report` plus oznaczenie
w kolejce — indeks `reports_source_status_idx` już jest.
**Warunek dla każdej liczby godzin w regulaminie i dla usunięcia obietnicy
48 godzin z `resources/legal/zasady.md:24`.**

**B-9. Nikt nie sprawdza, czy potwierdzenie odbioru wyszło.**
Indeks częściowy `reports_pending_receipt_idx` istnieje w żywej bazie i jest
opisany jako „do znalezienia zgłoszeń czekających na potwierdzenie odbioru",
ale `receipt_sent_at` jest tylko zapisywane (`ZglosNielegalnaTresc.php:89`)
i nigdy nie odczytywane. Jeśli kolejka poczty padnie, powiadomienie wyląduje
w `failed_jobs`, a `receipt_sent_at` **będzie już ustawione** — czyli baza
powie „wysłaliśmy", gdy list nie poszedł. To jest jedno zapytanie w panelu
i jedna poprawka kolejności zapisu.

**B-10. Brak jakiegokolwiek raportu przejrzystości i brak licznika
odbiorców z art. 24 ust. 3.** Nie ma polecenia, zadania ani widoku;
`WeeklyActiveCooks` mierzy co innego (§4). Priorytet zależy w całości od
rozstrzygnięcia pytania o art. 19 — dlatego P2, a nie wyżej. Dane na
większość statystyk **są** (`reports`, `moderation_actions`, `appeals`,
`audit_log`).

**B-11. `target_url` nie jest wymuszony w bazie dla zgłoszenia prawnego.**
`reports_legal_notice_complete_check` nie obejmuje tej kolumny.
Jedna linia w migracji; ta sama klasa braku, którą ten plik już naprawił dla
`illegality_explanation` i `good_faith_at`.

**B-12. Art. 11 — język komunikacji z organami nie istnieje nigdzie
w kodzie.** Nie jest to zmiana kodu, ale musi być podjęta decyzja, zanim
regulamin poda punkt kontaktowy dla organów (`regulamin.md:19`).

**B-13. Dokumentacja jest za kodem.** `docs/legal/COMPLIANCE.md:248-249`
wymienia formularz z art. 16 i szablon uzasadnienia z art. 17 jako
niezrobione P0, a `:260` art. 20 jako „niewdrożony dobrowolnie" — wszystkie
trzy są w kodzie. `COMPLIANCE.md:5` i §1.2 zakładają zwolnienie z art. 19
bez zastrzeżenia z `OPERATOR.md` §3. To jest ta sama klasa błędu, którą
`CLAUDE.md` w innym repozytorium nazywa „plik parafrazuje kontrakt i przez
to gnije": **dwa dokumenty mówią o statusie prawnym operatora co innego,
a regulamin ma być pisany na podstawie jednego z nich.**

---

## 9. Czego nie dało się ustalić z kodu

Wypisane wprost, żeby nikt nie uzupełnił tego domysłem:

1. **Czy skrzynka `kontakt@kuking.pl` istnieje i jest czytana.** Kod wysyła
   z `kuchnia@kuking.pl`, wskazuje `kontakt@kuking.pl`, a w tym środowisku
   `MAIL_MAILER=log`. Poczty przychodzącej nie ma w repozytorium.
2. **Treść ustaleń N07 i N08 zewnętrznego audytu.** Dokumentu nie ma
   w repozytorium na żadnej gałęzi; kody `N0x` występują tylko w treści
   commita `ed40c79` (N06).
3. **Czy Kuking jest „przedsiębiorstwem" w rozumieniu art. 19 DSA.** To
   pytanie prawne, w `docs/decyzje/OPERATOR.md` §3 oznaczone jako
   nierozstrzygnięte, a od niego zależy, czy braki z §3 i §4 są naruszeniem
   czy nadwyżką.
4. **Czy istnieje realna ścieżka zgłoszenia do organów z art. 18.** Kodu nie
   ma; procedura jest wyłącznie w dokumencie.
5. **Czy jakikolwiek certyfikowany organ pozasądowego rozstrzygania sporów
   przyjmie sprawę z Kuking.** `DecyzjaWSprawieZgloszenia.php:109-110` już
   o takim organie wspomina, nie nazywając żadnego. Kod nie może tego
   ustalić.
6. **Ile zgłoszeń i odwołań realnie leży i od kiedy.** Nie z braku danych,
   a z braku zapytania — patrz B-8.
