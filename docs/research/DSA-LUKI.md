# DSA art. 16 i 20 — luki po stronie zgłaszającego

> Ten dokument uzupełnia `docs/legal/COMPLIANCE.md` i `docs/legal/MODERATION_PLAYBOOK.md`.
> Nie jest poradą prawną.
>
> **Uczciwość źródła (aktualizacja).** Pierwsza wersja tej sekcji opierała się
> wyłącznie na pamięci modelu. W trakcie audytu koordynator zespołu sprawdził
> kluczowe artykuły na `eu-digital-services-act.com` — stronie per-artykułowej
> z tekstem rozporządzenia. **To jest źródło wtórne, nie EUR-Lex** (pełny tekst
> na EUR-Lex ucinał się przy pobraniu, stąd to zastępcze źródło). Cytaty
> poniżej pochodzą stamtąd i są oznaczone wprost jako takie. **Nie są
> cytatem z Dziennika Urzędowego UE** i prawnik powinien je zweryfikować na
> tekście pierwotnym przed poleganiem na nich w jakimkolwiek dokumencie
> zewnętrznym (regulamin, komunikacja z UKE, spór). Sekcja „Czego nie
> sprawdziłem" na końcu to rozwija.

**Zakres zawężony celowo.** DSA art. 17 (uzasadnienie decyzji i odwołanie dla
autora ukrytej/usuniętej treści albo zawieszonego/zbanowanego konta) **jest
zaimplementowany** i działa — patrz sekcja 2. Ten dokument dotyczy wyłącznie
brakującej połowy: ścieżki dla **zgłaszającego**, czyli osoby, która złożyła
zgłoszenie i nie wie, co się z nim stało, albo się z decyzją nie zgadza.

---

## 1. Podstawa prawna: art. 16(4)+(5) obowiązuje już dziś, art. 20 jest warunkowy

**To jest zmieniona wersja tej sekcji.** Pierwsza wersja pytała „czy jesteśmy
zwolnieni z art. 20?" i na tej podstawie zakładała, że priorytet całego
zadania może spaść. Po sprawdzeniu artykułów źródłowo (patrz zastrzeżenie na
górze dokumentu) obraz jest inny: **Kuking ma obowiązek wynikający z art. 16
już dziś, niezależnie od wielkości firmy — art. 20 jest tym, co jest
warunkowe.**

### 1.1 Art. 16(4) i 16(5) — obowiązek bezwarunkowy wobec zgłaszającego

Art. 16 („Notice and action mechanisms") jest w Sekcji 2 rozporządzenia,
adresowanej do „**providers of hosting services**" — nie do „providers of
online platforms" (Sekcja 3), którą zwalnia art. 19. Innymi słowy: **zwolnienie
mikro/małego przedsiębiorstwa nie dotyka art. 16 w ogóle**, bo leży poza
sekcją, którą to zwolnienie obejmuje. Cytaty (źródło wtórne, patrz
zastrzeżenie na górze):

> **Art. 16(4):** „Where the notice contains the electronic contact
> information of the individual or entity that submitted it, the provider of
> hosting services shall, without undue delay, send a confirmation of receipt
> of the notice to that individual or entity."

> **Art. 16(5):** „The provider shall also, without undue delay, notify that
> individual or entity of its decision in respect of the information to which
> the notice relates, providing information on the possibilities for redress
> in respect of that decision."

Przełożone na Kuking: dostawca (czyli Kuking) ma **obowiązek** (a) potwierdzić
zgłaszającemu odbiór jego zgłoszenia, i (b) poinformować go o podjętej
decyzji wraz z informacją o możliwościach odwołania — **bez względu na to,
czy Kuking jest mikro/małym przedsiębiorstwem, czy nie.** To jest dziś
**podstawa prawna Luki 1** (sekcja 3) — nie art. 20.

### 1.2 Art. 19 — zwolnienie mikro/małych z Sekcji 3 (w tym art. 20)

`docs/legal/COMPLIANCE.md:26-33` twierdzi, że Kuking jako mikro/małe
przedsiębiorstwo jest zwolniony z art. 20 DSA na mocy art. 19. Cytat
potwierdza tę strukturę:

> **Art. 19:** „This Section, with the exception of Article 24(3) thereof,
> shall not apply to providers of online platforms that qualify as micro or
> small enterprises […]"

Do tego: zwolnienie ma **12-miesięczną karencję** po utracie statusu
mikro/małego przedsiębiorstwa (czyli obowiązki Sekcji 3 nie włączają się z
dnia na dzień w chwili przekroczenia progu) i **nie dotyczy** podmiotów
wyznaczonych jako VLOP niezależnie od wielkości.

**Wniosek:** `COMPLIANCE.md` miał rację co do struktury. Art. 20 (wewnętrzny
system rozpatrywania skarg) **rzeczywiście jest, jako całość, warunkowy** —
zależy od tego, czy Kuking spełnia definicję mikro/małego przedsiębiorstwa
(pytanie do prawnika, sekcja 5, pytanie 1). Ale to nie zdejmuje obowiązku
z art. 16 opisanego w 1.1 — te dwa artykuły trzeba oceniać osobno, nie jako
jeden pakiet „ścieżka dla zgłaszającego".

### 1.3 Art. 20(1) — kto ma prawo do skargi, GDYBY art. 20 miał zastosowanie

> **Art. 20(1):** prawo skargi mają „recipients of the service, including
> individuals or entities that have submitted a notice."

Czyli zgłaszający **jest** wprost objęty art. 20 — tak jak zakładała pierwsza
wersja tego dokumentu — ale **tylko wtedy, gdy art. 20 w ogóle stosuje się do
Kuking** (czyli gdy zwolnienie z 1.2 nie ma zastosowania, np. po utracie
statusu mikro/małego przedsiębiorstwa, po 12-miesięcznej karencji).

### 1.4 Brak terminu liczbowego w art. 20 — „7 dni roboczych" to obietnica produktu, nie ustawy

> **Art. 20(4):** decyzje w systemie skarg mają zapadać „in a timely,
> non-discriminatory, diligent and non-arbitrary manner" — bez podania liczby
> dni.

Sześciomiesięczne okno, jakie art. 20 przewiduje, to (z odczytu koordynatora)
**termin na złożenie skargi przez odbiorcę usługi**, nie termin na odpowiedź
dostawcy. Wniosek: **„7 dni roboczych" z `MODERATION_PLAYBOOK.md` §3 pkt 6 to
własna, dobrowolna obietnica produktu wobec użytkownika — nie liczba
wymagana przez rozporządzenie.** To nie znaczy, że jest bez znaczenia:
obietnica złożona wprost w szablonie wiadomości do użytkownika (playbook,
szablony 4.1–4.5) wiąże Kuking **z innego powodu** — bo to jest zapisana,
publiczna deklaracja wobec konkretnej osoby, a niedotrzymana obietnica szkodzi
zaufaniu niezależnie od tego, czy prawo ją narzuca (dokładnie to, przed czym
ostrzega `MODERATION_PLAYBOOK.md` §3: „nie obiecuj więcej, niż jesteś w
stanie dotrzymać").

### 1.5 Konsekwencja dla priorytetu i dla kształtu implementacji

`P0` na issue #10 **zostaje uzasadnione, ale z innego powodu, niż pierwsza
wersja tego dokumentu twierdziła**: nie dlatego, że art. 20 na pewno
obowiązuje, tylko dlatego, że **art. 16(4)+(5) obowiązuje już dziś i nie ma
żadnego zwolnienia rozmiaru, które by go zdjęło** (Luka 1, sekcja 3, jest
więc pewnym naruszeniem obowiązku, nie tylko rekomendacją). Art. 20 (pełna
ścieżka skargi z rozpatrzeniem — Luka 2) jest **warunkowa**: wchodzi w grę
dopiero, gdy Kuking przestanie kwalifikować się jako mikro/małe
przedsiębiorstwo (i to z 12-miesięczną karencją po przekroczeniu progu, patrz
1.2).

**Rekomendacja implementacyjna mimo to: zbudować Lukę 2 w kształcie pełnego
art. 20 już teraz**, nie czekać na przekroczenie progu. Powody:

1. `MODERATION_PLAYBOOK.md` §3 i tak **obiecuje** to użytkownikom (bez
   rozróżniania, czy to zgłaszający, czy autor) — usunięcie tej obietnicy dla
   jednej grupy osłabia zaufanie, które jest jedynym realnym wyróżnikiem
   produktu (AGENTS.md §1),
2. przy 1-2-osobowym zespole dogodniejsze jest zbudować mechanizm raz, w
   kształcie, który **przetrwa przekroczenie progu bez przepisywania**, niż
   budować dziś „lekką" wersję bez SLA i uzasadnienia, a potem
   przerabiać ją pod presją czasu, gdy próg zostanie przekroczony i
   12-miesięczna karencja zacznie płynąć,
3. koszt dodatkowy (uzasadnienie zamiast samego statusu, CHECK w bazie
   pilnujący uzasadnienia) jest, patrząc na analogiczną tabelę `appeals`,
   niewielki względem korzyści.

---

## 2. Co już istnieje (strona autora/konta — art. 17 i częściowo art. 20)

Zweryfikowane w kodzie, nie na słowo z issue. Lista poniżej to rzeczy, których
**świadomie nie zgłaszam jako braku**, bo są zrobione:

1. **Zgłaszanie treści (art. 16).** `app/Http/Controllers/ReportController.php`
   + `app/Domain/Moderation/Actions/ReportContent.php` — formularz z opisowym
   przyciskiem „Zgłoś" (nie ikonka), powody po polsku (`Report::REASONS`,
   `app/Models/Report.php:34-46`), deduplikacja tego samego zgłoszenia od tej
   samej osoby (`ReportContent.php:54-63`).
2. **Uzasadnienie decyzji dla autora/konta (art. 17).**
   `app/Domain/Moderation/Actions/NotifyModerationDecision.php` — wysyła
   powiadomienie w serwisie z tytułem, treścią napisaną przez moderatora
   (albo domyślną), wskazaniem czy od decyzji można się odwołać
   (`ModerationAction::ODWOLYWALNE`) i identyfikatorem decyzji do formularza
   odwołania. Wywoływane z `ModerationController::decide()` (linie 131-142) dla
   **każdej** decyzji dotykającej konkretnej osoby.
3. **Odwołanie dla autora/konta (art. 17 i 20, w zakresie w jakim dotyczy
   autora).** Kompletna ścieżka:
   - model `app/Models/Appeal.php` — `UNIQUE (moderation_action_id)`,
     `responseDeadline()` liczący 7 dni roboczych z konfiguracji,
   - akcja złożenia `app/Domain/Moderation/Actions/FileAppeal.php` — limit
     jedno odwołanie na decyzję, termin 14 dni (`appealDeadline()`),
     autoryzacja `subject_user_id === $osoba->getKey()` (linia 43),
   - formularz dla zalogowanych: `app/Http/Controllers/AppealController.php::show/store`,
     trasa `GET/POST /odwolanie/{action}`,
   - formularz dla **zablokowanych** (zamknięty loginem/hasłem, nie odblokowuje
     konta): `AppealController::guestForm/guestStore`, trasa `/odwolanie` (bez
     parametru), limit throttle `appeal` = 5/godz. (`config/kuking.php:164-168`),
   - rozpatrzenie: `app/Domain/Moderation/Actions/ResolveAppeal.php` — wymaga
     uzasadnienia (`RuntimeException` bez treści), karencja 24h zanim TEN SAM
     moderator podtrzyma własną decyzję (`sprawdzKarencje()`), natychmiastowe
     cofnięcie bez karencji, realne cofnięcie skutków (`RestoreContent`,
     `User::reinstate()`),
   - panel: `app/Http/Controllers/Admin/AppealController.php`, trasa
     `/admin/odwolania`,
   - odpowiedź: `app/Domain/Moderation/Actions/NotifyAppealOutcome.php` —
     dociera powiadomieniem, a dla zbanowanego przez ekran logowania
     (komentarz w pliku odsyła do `EnsureAccountIsActive`/`LoginController`).
4. **Realne cofanie decyzji (potrzebne, żeby odwołanie „coś znaczyło").**
   `app/Domain/Moderation/Actions/RestoreContent.php` — przywraca treść do
   stanu **sprzed** ukrycia (kolumna `moderation_actions.previous_status`,
   patrz `docs/DATABASE.md:276-309`), a nie na sztywno do `published`.
5. **Log audytowy.** Każdy krok (`content.reported`, `moderation.decided`,
   `moderation.restored`, `appeal.filed`, `appeal.resolved`) zapisuje wpis w
   `AuditLogEntry` (`app/Models/AuditLogEntry.php`) z aktorem, obiektem,
   metadanymi i IP.
6. **Migracja + dokumentacja zgodnie z AGENTS.md §6.** Tabela `appeals` ma
   migrację `2026_09_06_100100_create_appeals_table.php` z CHECK-ami w bazie
   (status musi być spójny z obecnością `decided_at`/`decision_note`), opis w
   `docs/DATABASE.md:311-346` łącznie z planem rollbacku (`DROP TABLE
   appeals`, z zastrzeżeniem o `COPY` przed cofnięciem na produkcji).

To wszystko istnieje i działa dla **autora treści albo posiadacza zawieszonego/
zbanowanego konta**. Ani jeden z tych sześciu punktów nie dotyczy zgłaszającego.

---

## 3. Czego brakuje — strona zgłaszającego

### Luka 1 — zgłaszający nie dowiaduje się, co się stało z jego zgłoszeniem

**Dowód nieistnienia.**

- `grep -rn "reporter" app/` poza `reporter_id`/relacją `belongsTo` zwraca
  tylko: przekazanie `$reporter` do `ReportContent::handle()` (zapis, nie
  powiadomienie) i `->with(['reporter.profile', ...])` w
  `app/Http/Controllers/Admin/ModerationController.php:43` — czyli
  **wyłącznie do wyświetlenia moderatorowi w panelu admina**, nigdy do
  wysłania czegokolwiek do samego zgłaszającego.
- `app/Domain/Moderation/Actions/NotifyModerationDecision.php` przyjmuje
  argument `User $osoba` i w całym pliku ani razu nie odwołuje się do
  `report->reporter` — powiadamia wyłącznie osobę, której decyzja **dotyczy**
  (autora treści / posiadacza konta), zgodnie z komentarzem w nagłówku pliku
  („KTOŚ COŚ ZROBIŁ Z MOIMI TREŚCIAMI ALBO Z MOIM KONTEM").
- `app/Http/Controllers/Admin/ModerationController.php:144-151` — po
  rozstrzygnięciu zgłoszenia (`$report->update([...status...])`) **nie ma
  żadnego wywołania powiadomienia do `$report->reporter`**. Zmienia się tylko
  wiersz w bazie.
- `app/Http/Controllers/ReportController.php:62-64` — jedyna informacja, jaką
  dostaje zgłaszający, to **jednorazowy flash-komunikat sesji** w chwili
  wysłania zgłoszenia („Dziękujemy. Zgłoszenie trafiło do nas…"). Znika po
  odświeżeniu strony, nie jest zapisany nigdzie trwale, nie mówi nic o
  wyniku — bo w tym momencie wyniku jeszcze nie ma.
- Model `Report` (`app/Models/Report.php`) nie ma kolumny w rodzaju
  `reporter_notified_at`; `Notification` (`app/Models/Notification.php`) nie
  ma typu dla „wynik Twojego zgłoszenia" — jest tylko `TYPE_MODERATION` dla
  decyzji dotykającej autora.
- Trasa `admin.reports` (`GET /admin/zgloszenia`) jest wewnątrz
  `Route::middleware(['auth', 'moderator'])->prefix('admin')` (`routes/web.php`)
  — to panel moderatora, nie strona zgłaszającego. **Nie istnieje żadna trasa
  typu `/zgloszenia` albo `/moje-zgloszenia` dostępna zwykłemu użytkownikowi** —
  `grep -n "Route::" routes/web.php` po stronie `auth`-owej grupy (poza
  `admin`-em) nie zwraca nic takiego.

**Scenariusz.** Halina zgłasza komentarz, w którym ktoś nazywa ją oszustką.
Dostaje na ekranie zdanie „Dziękujemy, sprawdzimy" i wraca do przeglądania
serwisu. Moderator w ciągu tygodnia zgłoszenie odrzuca, bo uznaje, że
komentarz to niemiła, ale dozwolona opinia. Status zgłoszenia zmienia się w
bazie na `rejected`. Halina **nigdy się o tym nie dowiaduje** — nie ma
powiadomienia, nie ma e-maila, nie ma miejsca, w którym mogłaby sprawdzić, co
się stało. Po dwóch tygodniach, przekonana że nikt tego nie przeczytał,
zgłasza ten sam komentarz drugi raz — trafia do tej samej kolejki, moderator
odrzuca go po raz drugi, tym razem z lekkim zniecierpliwieniem. Dla Haliny
zgłaszanie robi się rytuałem bez odpowiedzi; następnym razem, gdy zobaczy coś
naprawdę groźnego, może już nie zgłosić.

**Koszt: M.** Wymaga: nowego typu powiadomienia (`Notification::TYPE_REPORT_RESOLVED`
czy podobny), wywołania go z `ModerationController::decide()` obok istniejącego
wywołania dla `$osoba`, oraz decyzji produktowej o TREŚCI tego powiadomienia
przy odrzuceniu (żeby nie ujawnić zgłaszającemu więcej niż trzeba o decyzji
dotyczącej cudzej treści — patrz Luka 3). Nie wymaga nowej tabeli — `reports`
i `notifications` już istnieją.

**Czego się wyrzekamy.** Nic — to domknięcie obowiązku z art. 16(4) i 16(5)
(sekcja 1.1), który obowiązuje Kuking już dziś, niezależnie od statusu art. 20.

---

### Luka 2 — zgłaszający, którego zgłoszenie odrzucono, nie ma jak się odwołać

**Dowód nieistnienia.**

- `app/Domain/Moderation/Actions/FileAppeal.php:43-45` — pierwszy warunek:
  `if ($decyzja->subject_user_id === null || $decyzja->subject_user_id !== $osoba->getKey())`
  rzuca wyjątek „Ta decyzja nie dotyczy Twojego konta." `subject_user_id` na
  `ModerationAction` to zawsze **autor treści albo zgłoszone konto**
  (`ModeratedContent::osoba()`), nigdy zgłaszający — nie ma w ogóle kolumny,
  która trzymałaby „kto zgłosił", bo tę wie `Report::reporter_id`, a `Appeal`
  łączy się z `ModerationAction`, nie z `Report`.
- Sam kod **wprost przyznaje**, że to świadomie pominięty zakres — komentarz
  w `app/Models/ModerationAction.php:96-101`: „Brakuje tu `no_action` —
  zgłoszenie odrzucone nie dotknęło nikogo, więc nie ma się od czego
  odwoływać (zgłaszający ma osobną drogę: dopisać nowe fakty do
  zgłoszenia)." — ta „osobna droga" nie istnieje jako mechanizm produktowy;
  jedyne co zostaje to adres e-mail kontaktowy (`kuking.community.contact_email`),
  identyczny co przy zwykłym kontakcie, bez śladu w `moderation_actions` ani
  w `appeals`.
- `app/Models/Appeal.php:38-46` (`$fillable`) nie ma kolumny `report_id` —
  tabela `appeals` fizycznie **nie potrafi** dziś reprezentować „odwołania od
  odrzucenia zgłoszenia", tylko „odwołania od decyzji dotykającej konta".

**Scenariusz.** Krzysztof zgłasza przepis sąsiada jako podejrzanie
skopiowany słowo w słowo z bloga. Moderator sprawdza, uznaje że to
przetworzenie, nie kopia, i odrzuca zgłoszenie (`no_action`). Nawet gdyby
Krzysztof dostał teraz powiadomienie o wyniku (Luka 1), **nie ma żadnego
przycisku ani formularza, którym mógłby powiedzieć „sprawdźcie jeszcze raz,
mam link do oryginału"** — jedyna droga to napisanie maila od zera, bez
odniesienia do konkretnego zgłoszenia, bez terminu odpowiedzi, bez śladu w
logu moderacji.

**Koszt: M–L.** To jest nowa funkcja, nie rozszerzenie istniejącej: nowa
tabela albo nowa kolumna łącząca `appeals` z `reports` zamiast (albo obok)
`moderation_actions`, nowe reguły autoryzacji („odwołać się może zgłaszający
tego konkretnego zgłoszenia, i to raz"), nowy ekran, nowa gałąź w kolejce
moderatora. Szkic w sekcji 4.

**Czego się wyrzekamy.** Przy zespole 1-2 osób (patrz `MODERATION_PLAYBOOK.md`
§3) każda nowa kolejka to czas odjęty od rozpatrywania pierwotnych zgłoszeń.
Art. 20 jest, w odróżnieniu od art. 16, **warunkowy** — dziś prawdopodobnie nie
obowiązuje Kuking wprost (sekcja 1.2, do potwierdzenia pytaniem 1 w sekcji 5).
Mimo to rekomendacja (sekcja 1.5) jest **zbudować to od razu w kształcie
pełnego art. 20** (uzasadniona odpowiedź, ślad w logu, jeden formularz na
sprawę), a nie w wersji tymczasowej bez uzasadnienia — bo to jest dokładnie
to, co już obiecuje `MODERATION_PLAYBOOK.md`, i bo przerabianie „lekkiej"
wersji pod presją czasu po przekroczeniu progu mikro/małego przedsiębiorstwa
(z 12-miesięczną karencją od utraty statusu) jest droższe niż zrobienie tego
raz, porządnie, teraz.

---

### Luka 3 — napięcie między „poinformuj zgłaszającego" a prywatnością osoby zgłoszonej

To nie jest brak funkcji, tylko decyzja produktowa, którą trzeba podjąć
**zanim** ktoś zaimplementuje Lukę 1 i 2, bo źle podjęta psuje coś, co dziś
działa dobrze.

**Kontekst.** `NotifyModerationDecision.php` (komentarz „DLACZEGO `actor_id`
JEST PUSTE") już rozwiązuje podobny problem po stronie autora: powiadomienie
o zawieszeniu konta nie pokazuje, KTO z zespołu moderacji podjął decyzję,
żeby przy 1-2-osobowym zespole nie było to wskazaniem palcem konkretnej
osoby. Analogiczne ryzyko istnieje przy informowaniu zgłaszającego: jeśli
powiadomienie o odrzuceniu zgłoszenia opisze zbyt dokładnie, co moderator
ustalił o zgłoszonej osobie („sprawdziliśmy, X ma potwierdzenie zakupu
licencji na ten przepis"), zgłaszający dostaje więcej informacji o cudzym
koncie, niż powinien.

**Rekomendacja (do potwierdzenia produktowo, nie tylko prawnie).** Szablon
4.6 z `MODERATION_PLAYBOOK.md` już to robi dobrze dla ręcznych maili —
„Po analizie uznaliśmy, że treść nie narusza Zasad Kuking, dlatego zostaje
bez zmian [opcjonalnie: krótkie wyjaśnienie]" — bez ujawniania szczegółów
wyjaśnienia złożonego przez drugą stronę. Automatyczne powiadomienie powinno
przyjąć ten sam, celowo ogólny ton.

**Koszt.** Wliczony w koszt Luki 1 — to jest decyzja o TREŚCI szablonu, nie
osobna praca inżynierska.

---

## 4. Szkic implementacji

To jest specyfikacja do budowy, nie kod produkcyjny. Sygnatury i migracja
niżej są ilustracją zakresu, nie gotowym patchem.

### 4.1 Trasy

```
GET  /zgloszenia                          — lista własnych zgłoszeń (zgłaszający)
GET  /zgloszenia/{report}                 — szczegóły jednego zgłoszenia + status
GET  /zgloszenia/{report}/skarga          — formularz skargi na odrzucenie
POST /zgloszenia/{report}/skarga          — złożenie skargi

# panel moderatora — obok istniejącej kolejki /admin/zgloszenia
GET  /admin/skargi-zglaszajacych          — kolejka skarg zgłaszających
POST /admin/skargi-zglaszajacych/{skarga} — rozpatrzenie
```

Wszystkie wewnątrz istniejącej grupy `Route::middleware('auth')` (jak
`reports.create/store` dziś). Nazwa `/zgloszenia` (bez `/admin`) jest wolna —
dzisiejsza trasa moderatora to `/admin/zgloszenia`, więc nie ma kolizji.
`{report}` w adresie to UUID — **nie jest autoryzacją** (AGENTS.md §7), patrz
4.5.

### 4.2 Ekrany

**Lista własnych zgłoszeń (`/zgloszenia`).** Tabela/lista: data, typ treści,
powód wybrany przy zgłoszeniu, status w języku polskim, bez żargonu:

| status w bazie | tekst dla zgłaszającego |
|---|---|
| `open`, `triage`, `reviewing` | „Sprawdzamy" |
| `resolved` | „Sprawdziliśmy — coś zmieniliśmy" |
| `rejected` | „Sprawdziliśmy — bez zmian" |

Przycisk (≥48px, z podpisem, nie ikoną) „Zobacz szczegóły" przy każdym
wierszu. Paginacja przyciskiem „Pokaż więcej", zgodnie z `AGENTS.md` §5.

**Szczegóły zgłoszenia (`/zgloszenia/{report}`).** Gotowy tekst do wklejenia
(rejestr jak w `docs/brand/COPY_STYLE.md`, bez gry słowem `kuKING`, zero
emoji):

> ### Twoje zgłoszenie
>
> Zgłosiłeś/-aś: [powód, np. „Ktoś podaje się za inną osobę"], [data].
>
> **Co ustaliliśmy:** [tekst zależny od statusu — patrz niżej].
>
> [Jeśli `resolved`:] Sprawdziliśmy zgłoszenie i coś zmieniliśmy.
>
> [Jeśli `rejected`:] Sprawdziliśmy zgłoszenie. Po analizie uznaliśmy, że ta
> treść nie narusza Zasad Kuking, dlatego zostaje bez zmian. Jeśli masz
> dodatkowe informacje, których nie uwzględniliśmy — [przycisk „Nie zgadzam
> się z tą decyzją"].

Przycisk „Nie zgadzam się z tą decyzją" widoczny **tylko** gdy
`status === 'rejected'` i termin (patrz 4.4) jeszcze nie minął, i tylko gdy
skarga nie została już złożona (jedna skarga na jedno zgłoszenie, jak przy
`appeals`).

**Formularz skargi (`/zgloszenia/{report}/skarga`).** Jedno pole tekstowe
(etykieta zawsze widoczna, min. 18px): „Napisz, dlaczego uważasz tę decyzję za
błędną" + opcjonalnie pole „Nowe informacje, o których mogliśmy nie wiedzieć".
Przycisk „Wyślij skargę" (≥48px). Po wysłaniu:

> Skargę dostaliśmy. Odpowiemy najszybciej, jak się da — zobaczysz odpowiedź
> na tej stronie i w powiadomieniach.

Termin „7 dni roboczych" jest tu propozycją analogiczną do odwołania autora,
nie wymogiem ustawowym (art. 20(4) nie narzuca liczby dni — sekcja 1.4) —
zanim wejdzie do gotowego tekstu ekranu, warto potwierdzić z prawnikiem
(pytanie 5, sekcja 5), czy taka własna obietnica rodzi ryzyko z innego
tytułu, i sprawdzić produktowo, czy dwie równoległe kolejki SLA (odwołania
autorów + skargi zgłaszających) są w ogóle do udźwignięcia przy 1-2 osobach.

### 4.3 Dane

Rekomendacja: **nowa tabela `report_complaints`**, osobna od `appeals`, bo
`appeals.moderation_action_id` ma `UNIQUE` i sens „jedna decyzja, jedno
odwołanie" — zgłoszenie odrzucone (`no_action`) **nie zawsze** tworzy wiersz w
`moderation_actions` z sensownym `subject_user_id` (bo `no_action` na
zgłoszeniu treści dziś w ogóle nie musi tworzyć wiersza, jeśli zdecydujemy się
to zmienić — do potwierdzenia przy budowie, czy dzisiejsze `decide()` zawsze
zapisuje `ModerationAction` nawet dla `no_action`; z lektury kontrolera —
`ModerationController.php:110-121` — **tak, zapisuje zawsze**, więc alternatywą
jest dodanie kolumny `reporter_complaints` wprost w `appeals` z `report_id`
zamiast osobnej tabeli; decyzja architektoniczna do podjęcia przy
implementacji, nie tutaj).

Szkic (ilustracyjny, wariant osobnej tabeli):

```php
Schema::create('report_complaints', function (Blueprint $table): void {
    $table->uuid('id')->primary();

    // Jedna skarga na jedno zgłoszenie — ten sam wzorzec co appeals.
    $table->foreignUuid('report_id')->unique()
        ->constrained('reports')->cascadeOnDelete();

    // Zgłaszający. Musi się zgadzać z reports.reporter_id — sprawdzane
    // w kodzie (Policy), nie tylko przez klucz obcy.
    $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

    $table->string('body', 2000);
    $table->string('status', 20)->default('open');

    $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
    $table->string('decision_note', 2000)->nullable();
    $table->timestampTz('decided_at')->nullable();

    $table->timestampTz('created_at')->useCurrent();
});

DB::statement("ALTER TABLE report_complaints ADD CONSTRAINT report_complaints_status_check
    CHECK (status IN ('open','upheld','dismissed'))");

// Ten sam wzorzec co w appeals: rozpatrzone = data + uzasadnienie razem.
DB::statement("ALTER TABLE report_complaints ADD CONSTRAINT report_complaints_decision_check
    CHECK ((status = 'open'  AND decided_at IS NULL     AND decision_note IS NULL)
        OR (status <> 'open' AND decided_at IS NOT NULL AND decision_note IS NOT NULL))");
```

Indeksy analogiczne do `appeals`: `(status, created_at)`,
`(user_id, created_at DESC)`.

Zgodnie z AGENTS.md §6 to wymaga: migracji + testu (najlepiej rozszerzenie
istniejących `tests/Feature/OdwolanieOdDecyzjiTest.php` o analogiczny plik dla
skarg zgłaszającego) + wpisu w `docs/DATABASE.md` + planu rollbacku (ten sam
wzorzec co `appeals`: `DROP TABLE`, utrata danych, `COPY` przed cofnięciem na
produkcji).

**Retencja.** Ta sama tabela co dane zgłoszenia — `COMPLIANCE.md:71`
rekomenduje 12–24 miesiące od zamknięcia sprawy. Do potwierdzenia z prawnikiem
razem z resztą retencji zgłoszeń (pytanie w sekcji 5, blok RODO).

### 4.4 Terminy

- Termin na złożenie skargi na odrzucenie: analogicznie do `appeal_days` (14
  dni) — nowa albo współdzielona wartość konfiguracyjna, np.
  `kuking.moderation.complaint_days`, licząca się od `reports.resolved_at`
  (nie `created_at` — to data ROZSTRZYGNIĘCIA, nie zgłoszenia).
- Termin odpowiedzi: **do decyzji produktowej** (art. 20(4) nie narzuca liczby
  dni — sekcja 1.4 — więc to nie jest pytanie prawne, tylko operacyjne; pytanie
  5 w sekcji 5 dotyczy tego, czy sama obietnica rodzi ryzyko) — nie kopiować
  mechanicznie `appeal_response_working_days` (7 dni roboczych) bez
  sprawdzenia, czy dwie równoległe kolejki SLA są w ogóle do udźwignięcia przy
  1-2 osobach (`MODERATION_PLAYBOOK.md` §8 już ostrzega
  przed obiecywaniem więcej niż da się dotrzymać).

### 4.5 Policy / autoryzacja

Dzisiejszy kod nie ma osobnych klas `Policy` dla `Report`/`Appeal` — używa
`$this->authorize('moderate', User::class)` (Gate) dla moderatora i ręcznej
metody `sprawdzWlascicielaSprawy()` w `AppealController` dla zwykłego
użytkownika. Nowy kod powinien iść tą samą drogą dla spójności:

```php
private function sprawdzWlascicielaZgloszenia(?User $osoba, Report $report): void
{
    abort_if($osoba === null, 403);
    // UUID w adresie nie jest autoryzacją (AGENTS.md §7) — sprawdzamy
    // właściciela zgłoszenia jawnie, tak jak AppealController sprawdza
    // subject_user_id.
    abort_unless($report->reporter_id === $osoba->getKey(), 403);
}
```

Ewentualnie osobna klasa `ReportPolicy::view()`/`ReportPolicy::complain()`,
jeśli zespół programistów przy tej okazji chce ujednolicić wzorzec — to jest
decyzja stylu kodu, nie wymóg tego dokumentu.

### 4.6 Powiadomienia

- Nowy typ: `Notification::TYPE_REPORT_DECIDED` (`'report.decided'`),
  analogicznie do `TYPE_MODERATION`.
- Wysyłane z `ModerationController::decide()` **obok** istniejącego wywołania
  `$this->powiadom->handle($osoba, ...)` — do `$report->reporter`, jeśli
  `$report->reporter_id !== null` (dziś zawsze prawda, bo trasa wymaga
  logowania, ale kod powinien się bronić przed `null` tak jak
  `NotifyModerationDecision` broni się dziś).
- Treść zgodna z Luką 3 — ogólna, bez ujawniania szczegółów dotyczących
  zgłoszonej osoby.
- Odpowiedź na skargę (`report_complaints.decision_note`) — osobne
  powiadomienie tego samego typu, analogicznie do `NotifyAppealOutcome`.

### 4.7 Limity zapytań

Nowy formularz skargi wymaga wpisu w `config/kuking.php` → `limits`, tak jak
dziś ma to `appeal` (5/60 min) — rekomendacja: taki sam limit, bo ryzyko jest
identyczne (formularz tekstowy, jedna sprawa na wpis, niski wolumen z natury
ograniczenia „jedna skarga na zgłoszenie").

### 4.8 Log audytowy

Dwa nowe zdarzenia w `AuditLogEntry`: `report.complaint_filed`,
`report.complaint_resolved` — ten sam wzorzec co `appeal.filed`/`appeal.resolved`.

---

## 5. Pytania do prawnika

Pogrupowane, każde zamknięte i odpowiadalne. Cel: godzinne spotkanie
wystarczy.

### 5.1 DSA — zakres i priorytet

Ta sekcja była w pierwszej wersji dokumentu sformułowana jako „czy jesteśmy
zwolnieni z art. 20?". Po sprawdzeniu artykułów (sekcja 1) wiadomo już, że
struktura zwolnienia z `COMPLIANCE.md` jest prawdopodobnie poprawna, a
otwarte zostało co innego — pytania niżej odzwierciedlają to, co **naprawdę**
wymaga potwierdzenia prawnika.

1. **Czy Kuking na dzień publicznego startu faktycznie spełnia definicję
   mikro/małego przedsiębiorstwa z załącznika do zalecenia Komisji
   2003/361/WE (mikro: <10 zatrudnionych i obrót/suma bilansowa ≤2 mln EUR;
   małe: <50 zatrudnionych i obrót/suma bilansowa ≤10 mln EUR), kto w
   strukturze operatora to stwierdza i jak to udokumentować na wypadek
   kontroli, i co dokładnie oznacza 12-miesięczna karencja z art. 19 w razie
   utraty statusu w przyszłości?** Dlaczego pytamy: to, czy art. 20 (Luka 2 —
   pełna ścieżka skargi zgłaszającego z rozpatrzeniem) jest dziś obowiązkiem
   czy rekomendacją, zależy wyłącznie od tego ustalenia faktycznego — sam
   tekst art. 19 (sekcja 1.2) już nie budzi wątpliwości co do struktury.
2. **Czy dzisiejszy jednorazowy komunikat na ekranie przy wysłaniu zgłoszenia
   (`ReportController.php:62-64`) spełnia wymóg „potwierdzenia odbioru" z
   art. 16(4), i czy planowane powiadomienie o decyzji (Luka 1, sekcja 3)
   spełni art. 16(5) w pełnym zakresie — łącznie z „informacją o
   możliwościach odwołania"?** Dotyczy: dokładnego kształtu komunikatu w
   sekcji 4.2, żeby nie trzeba było go poprawiać drugi raz po wdrożeniu.
3. **Czy „informację o możliwościach odwołania", której wymaga art. 16(5),
   można — dopóki art. 20 nie wiąże Kuking wprost (pytanie 1) — wypełnić samym
   wskazaniem drogi sądowej (i ewentualnie art. 21, też warunkowego), bez
   pełnego wewnętrznego mechanizmu rozpatrywania skarg, czy to za mało w
   świetle celu przepisu?** Dotyczy: czy można wdrożyć samą Lukę 1
   (powiadomienie) bez Luki 2 (skarga) i uznać obowiązek z art. 16(5) za
   spełniony, czy obie luki trzeba domykać razem, żeby art. 16(5) był
   spełniony w praktyce, nie tylko na papierze.
4. **Jak polska ustawa wdrażająca DSA i praktyka UKE jako Koordynatora ds.
   Usług Cyfrowych traktują wyjątek dla mikro/małych przedsiębiorstw z
   art. 19 — czy prawo krajowe dokłada tu jakiekolwiek wymogi ponad tekst
   rozporządzenia unijnego?** Dotyczy: czy poleganie wyłącznie na unijnym
   tekście art. 19/20 (nawet potwierdzonym na EUR-Lex) jest wystarczające dla
   podmiotu działającego w Polsce, czy trzeba sprawdzić też krajową ustawę
   (ten sam wątek co issue #8, „status krajowej ustawy wdrażającej DSA").
5. **Czy potwierdzenie (na tekście pierwotnym, nie na źródle wtórnym użytym w
   tym dokumencie), że art. 20(4) nie narzuca konkretnego terminu w dniach na
   odpowiedź, a jedynie „terminowo, rzetelnie, w sposób nieadowolny", jest
   prawidłowe — i czy własna, dobrowolna obietnica Kuking („7 dni roboczych",
   `MODERATION_PLAYBOOK.md` §3 pkt 6) rodzi jakiekolwiek dodatkowe
   zobowiązanie prawne z innego tytułu (np. nieuczciwe praktyki rynkowe wobec
   konsumentów), jeśli nie zostanie dotrzymana?** Dotyczy: czy termin „7 dni
   roboczych" powtarzany w szablonach wiadomości i w kolejce moderatora
   (sekcja 4.4) jest bezpieczny do utrzymania jako czysto operacyjny cel, czy
   trzeba go traktować ostrożniej.

### 5.2 UŚUDE (ustawa o świadczeniu usług drogą elektroniczną)

6. **Czy obowiązki regulaminu z UŚUDE (art. 8 i nast.) pokrywają się z art. 14
   DSA, czy trzeba osobno spełnić oba reżimy w treści `/regulamin`?** Dotyczy:
   czy `resources/legal/regulamin.md` (patrz issue #8) wymaga osobnej sekcji
   „UŚUDE" obok sekcji DSA, czy jedno sformułowanie wystarcza na oba.
7. **Czy tryb notice-and-takedown z UŚUDE (art. 14) i wyłączenie
   odpowiedzialności hostingu nadal obowiązuje w praktyce obok DSA, czy DSA
   go w całości zastąpiło dla tego typu usług?** Dotyczy: czy formularz
   „Zgłoś" (dzisiejszy, spełniający DSA art. 16) wymaga dodatkowego pola albo
   dodatkowej ścieżki, żeby spełnić też UŚUDE.
8. **Czy Kuking potrzebuje zgody na informację handlową (UŚUDE art. 10) przed
   wysłaniem jakiegokolwiek e-maila marketingowego, i czy powiadomienia
   moderacyjne/transakcyjne (np. „Twoje zgłoszenie zostało rozpatrzone") są
   z tego wyłączone jako komunikacja niezbędna do świadczenia usługi?**
   Dotyczy: czy przyszłe powiadomienia e-mail o wyniku zgłoszenia (dziś
   w ogóle nie wysyłane, patrz issue #10 „Żadnego wysyłania e-maili") wymagają
   osobnej zgody, czy idą jako komunikacja usługowa.
9. **Jakie dane dostawcy usługi (firma, adres, NIP/KRS, kontakt) UŚUDE
   wymaga publikować, i czy pokrywają się z punktami kontaktowymi z art. 11–12
   DSA?** Dotyczy: treść stopki/`/regulamin` (issue #8, placeholdery
   `[NAZWA OPERATORA]`, `[ADRES]`).

### 5.3 RODO

10. **Jaka jest podstawa prawna (art. 6 RODO) dla przetwarzania danych w
   procesie „skarga zgłaszającego na odrzucenie zgłoszenia" — ta sama co dla
   samego zgłoszenia (art. 6(1)(c) obowiązek prawny + art. 6(1)(f) uzasadniony
   interes, jak dziś opisuje `COMPLIANCE.md:71`), czy inna, skoro ten
   konkretny etap może nie wynikać z art. 20 wprost?**
11. **Ile czasu trzymać treść USUNIĘTĄ (nie tylko ukrytą) po to, żeby dało się
    rozpatrzyć ewentualną skargę zgłaszającego na decyzję `no_action` (a więc
    treść, która w ogóle NIE została usunięta) — czy w tym wariancie w ogóle
    jest to samo pytanie retencyjne co przy odwołaniu autora, czy inny
    przypadek, bo treść zostaje nienaruszona?** Dotyczy: czy potrzebna jest
    osobna polityka retencji dla `report_complaints` (sekcja 4.3 tego
    dokumentu), czy wystarczy istniejąca dla `reports`.
12. **Czy admin/moderator, który widzi w panelu treść skargi zgłaszającego
    razem z uzasadnieniem decyzji dotyczącej trzeciej osoby, wymaga dodatkowej
    podstawy prawnej albo ograniczenia dostępu, skoro w jednym ekranie łączą
    się dane dwóch różnych osób (zgłaszającego i zgłoszonego)?**
13. **Status administratora vs. procesora Kuking wobec Railway, Cloudflare,
    Sentry, PostHog w kontekście PRZECHOWYWANIA treści skarg i zgłoszeń —
    czy dotychczasowa analiza w `COMPLIANCE.md` obejmuje już ten konkretny typ
    danych, czy trzeba osobno ocenić (bo to dane o sporze między dwiema
    osobami, wrażliwsze niż zwykła treść kulinarna)?**

### 5.4 Kwestie produktowe

14. **Czy poinformowanie zgłaszającego o wyniku zgłoszenia może w praktyce
    naruszać prawa zgłoszonej osoby (np. ujawnić jej dane albo szczegóły
    obrony), i jak dokładnie sformułować szablon odpowiedzi (Luka 3), żeby
    tego uniknąć bez łamania jednocześnie obowiązku informacyjnego wobec
    zgłaszającego?** Dotyczy: dokładna treść szablonu w sekcji 4.2 tego
    dokumentu — dziś to propozycja produktowa, nie zweryfikowana prawnie.
15. **Czy Kuking, publikując informację, że decyzje moderacyjne NIE są
    podejmowane wyłącznie automatycznie (co dziś jest prawdą — patrz sekcja
    1.3), musi to gdzieś formalnie zadeklarować (np. w regulaminie), czy
    wystarczy że jest to fakt operacyjny?**

---

## 6. Czego nie sprawdziłem

- **Cytaty w sekcji 1 pochodzą ze źródła wtórnego** (`eu-digital-services-act.com`,
  sprawdzone przez koordynatora audytu, nie przeze mnie bezpośrednio), **nie z
  EUR-Lex ani z Dziennika Urzędowego UE.** Poprawiają one moją pierwszą,
  opartą wyłącznie na pamięci wersję tego dokumentu, ale same wciąż wymagają
  potwierdzenia przez prawnika na tekście pierwotnym — zwłaszcza dokładna
  numeracja ustępów (16(4), 16(5), 19, 20(1), 20(4)) i to, czy strona
  wtórna cytuje aktualną, skonsolidowaną wersję rozporządzenia.
- **Nie zweryfikowałem niezależnie**, czy sześciomiesięczne okno z art. 20 to
  rzeczywiście termin na złożenie skargi (nie na odpowiedź) — to jest odczyt
  koordynatora na podstawie tego samego źródła wtórnego, przekazany mi w
  poleceniu, nie coś, co sam sprawdziłem w drugim źródle.
- **Nie sprawdziłem stanu polskiej ustawy wdrażającej DSA** ani praktyki UKE
  jako koordynatora usług cyfrowych — issue #8 już to zaznacza jako otwarte
  („proces legislacyjny toczył się jeszcze pod koniec 2025 r."), nie
  weryfikowałem tego dalej.
- **Nie przeglądałem historii commitów ani innych gałęzi** — całość ustaleń
  o kodzie opiera się na stanie plików w katalogu roboczym w chwili
  czytania (6 września 2026). Cztery inne agenty pracują równolegle w tym
  samym repozytorium; jeśli ktoś domknął którąkolwiek z opisanych tu luk
  między moim czytaniem a scaleniem tego dokumentu, ten opis może być już
  nieaktualny w momencie czytania.
- **Nie liczyłem faktycznego wolumenu zgłoszeń** — cena „nowa kolejka
  odciąga czas od pierwotnych zgłoszeń" (Luka 2) jest jakościowa, nie
  poparta liczbą zgłoszeń miesięcznie, bo serwis jeszcze nie wystartował
  publicznie.
- **Nie weryfikowałem A4/A5/A6 z `docs/research/USPRAWNIENIA-2.md`** poza
  przeczytaniem ich jako tła (polecenie zadania) — to są osobne ustalenia
  o widoczności treści i banach, przypisane innym agentom; nie sprawdzałem,
  czy ich stan opisany w tamtym dokumencie nadal jest aktualny, i świadomie
  nie powtarzam ich tutaj.
