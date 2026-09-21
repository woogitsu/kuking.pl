# Stan sesji — 20 września 2026, wieczór

Plik zapisany **przed kompaktowaniem kontekstu**. Wszystko, co trzeba
odtworzyć, żeby prowadzić dalej bez pamięci rozmowy.

---

## 1. Gdzie jest praca

- **Repozytorium kanoniczne:** `C:\Users\matma\Documents\Codex\kuking.pl`
- **Worktree stanowisk:** `C:\Users\matma\Documents\kuking-flota\<nazwa>`
- **`main` na origin:** `4c811cc7` (po scaleniu #789)
- **Produkcja:** `534e0a5`, „wydanie 20 września 2026, 12:54" — **#789 NIE jest
  wdrożone**, bo CI na `main` jest czerwone (patrz §4).
- **Kopie:** `C:\Users\matma\Documents\kuking-kopie\` — `*.bundle` (70 MB,
  wszystkie gałęzie) plus pięć części `kuking-kod.part-0*.bin` i
  `kuking-przekazanie-*.zip` (88 KB, dokumenty).

### Kolejka pchania

Listy w WSL: `/home/mateusz/flota/do-pchniecia.txt`,
`do-pchniecia-force.txt`, `pchniete.txt`, `pchniete-force.txt`, `nieudane.txt`.
Dzienniki: `kolejka9.log`, `kolejka10.log`.
Stanowisko pchania to **klon** `/home/mateusz/flota/push-run` (nie worktree).
Restart: `wsl -d Ubuntu -- bash /mnt/c/Users/matma/Documents/kuking-flota/_wspolne/restart-kolejek.sh`
Weryfikacja stanu **po SHA**: `_wspolne/odtworz-listy.sh`.

**Na origin z naszej puli są dwie gałęzie:** `fix/684-widget-zaslania`,
`flota/r47-skan`. Reszta czeka.

---

## 2. Gałęzie zweryfikowane przeze mnie niezależnie

Każda uruchomiona u mnie, nie przyjęta na słowo z meldunku.

| Gałąź | Commity | Moja weryfikacja |
|---|---|---|
| `gpt/tagi` | 2 | 17 testów / 134 asercje |
| `gpt/kontakt-formularz` | 3 | 43 testy / 421 asercji |
| `gpt/onboarding` | 4 | 97 testów / 608 asercji |
| `gpt/kontakt-panel` | 5 | 199 testów / 1087 asercji |
| `gpt/tablica` | 3 | 78 testów / 384 asercje |
| `gpt/zeszyt-zapisy` | 1 | 122 testy / 1071 asercji |
| `gpt/pytania-widoki` | 3 | 240 testów / 1309 asercji |
| `gpt/skladniki` | 3 | 598 testów / 4903 asercje |
| `gpt/turnstile-pomoc` | 1 | 113 testów / 1474 asercje |
| `gpt/harmonogram` | 2 | 37 testów / 1200 asercji |
| `robota/jedna-droga-z-zeszytu` | 6 | pełna suita 4413, zero porażek |
| `gpt/n1-powiadomienia` | 1 | 111 testów / 482 asercje; zapytania 17→8 i 67→8 |
| `gpt/moderacja-ai` | 2 | 230 testów / 1026 asercji |
| `gpt/eksport` | 4 | 125 testów / 891 asercji |
| `gpt/haslo-konto` | ? | pełna suita w toku |
| `flota/powiadomienia` | 6 | 34 testy, Pint czysty |
| `flota/zeszyty` | 4 | pełna suita 4400, 8 porażek = usterka runtime, nie gałęzi |
| `robota/poswiadczenia-decyzje` | 4 | 9 testów / 66 asercji |
| `robota/stopka-pusty-pas` | 1 | pomiar: strona krótsza o 352 px |
| `robota/straznik-format` | 7 | 9 testów / 12 asercji, mutacja zabita |
| `robota/drobne-decyzje` | 1 | 2 testy / 6 asercji, mutacja zabita |

Pozostałe gałęzie mają meldunki z własnymi liczbami, których **nie
powtarzałem** — są wypisane w `KOLEJKA_ZADAN.md` i w treściach commitów.

---

## 3. DECYZJE WŁAŚCICIELA — PODJĘTE, ALE JESZCZE NIEWYKONANE

To są rzeczy rozstrzygnięte w rozmowie, których nikt jeszcze nie zrobił.

1. **`docs/DECISIONS.md` — nadać D-223** decyzji o podniesieniu progu
   PostgreSQL z 16 na 18 (reguła w `AGENTS.md` poszła pierwsza, próg
   strażnika po niej). Gałąź `flota/prog-postgresa`, commity `68099722`
   i `d2ffccac`. Ostatni zajęty numer to D-222.
2. **`docs/infra/DEPLOYMENT_RUNBOOK.md` §6.3** — zostawiamy plan awaryjny
   „weź 17 i zrób upgrade in-place", ale trzeba **dopisać zdanie**, że 17 jest
   wariantem awaryjnym odtworzenia, nie dopuszczalnym stanem docelowym.
3. **#794** — nazwa celu zgłoszenia + krótki fragment treści. Wartości
   ustalone: 200 znaków, cięcie po granicy słowa, wielokropek w cudzysłowie,
   przy treści pustej lub usuniętej sama nazwa bez cudzysłowu. Bez miniatury.
   **Wykonane przez `gpt/794-cel-zgloszenia`** — zweryfikować i domknąć.
4. **#798** — link do śledzenia sprawy żyje, dopóki sprawa otwarta.
   **Wykonane przez `flota/odwolanie-link`.**
5. **#758** — powiadomienie śledzi treść, eksport RODO też.
   **Wykonane przez `flota/notyfikacja-zywa`** + migracja czyszcząca stare
   wycinki (`f8e6b444`).
6. **#858** — filtr tekstowy + „Załaduj więcej". **W trakcie** na
   `gpt/tagi-filtr`.
7. **Ujednolicenie dróg wyjęcia wpisu z zeszytu** (#789 kontra
   `flota/zeszyty`). **W trakcie** na `robota/jedna-droga-z-zeszytu`.

---

## 4. CZERWONE CI NA `main` — rozpoznane, NIE jest regresją

`main` = `4c811cc7`. Czerwone zadanie: **`Port marki — rodziny ekranów,
zoom i kreator`**. `Panel marki` przeszło po ponowieniu.

**Dowód, że to migotanie, a nie usterka:** asercja **przeskakuje** między
przebiegami na tym samym SHA.

| przebieg | `aktualny_czas_dostepny_bez_spamu_live` | `niezalezne_minutniki` |
|---|---|---|
| pierwszy | FAIL („AX nie zawiera aktualnej wartości: `timer: 0:08`") | PASS |
| drugi | PASS | FAIL (`1 !== 0`) |

Obie z rodziny testów **minutnika** — mierzą upływ czasu w żywej przeglądarce.
Obciążenie maszyny w obu przebiegach 13–18 przy 24 rdzeniach.

**Uściślenie reguły rejestru migotania:** „czerwony drugi raz w TYM SAMYM
miejscu = nie migotanie". Tutaj miejsce się zmieniło — a to **mocniejszy**
dowód migotania niż zieleń po ponowieniu.

**Skutek praktyczny:** czerwone CI blokuje workflow `Deploy`, więc **#789 nie
zostało wdrożone**. Trzeba ponowić `Port marki` przy `load < 8`.

---

## 5. PYTANIA DO WŁAŚCICIELA — do zadania w formie klikalnej

Zebrane, jeszcze niezadane. **Kolejność wedle wagi.**

### P1. Tokeny w historycznych danych produkcyjnych (#836)

Kontekst strony zapisywany przy wiadomościach kontaktowych zachowywał
**tokeny resetu hasła, logowania i zaproszenia**. Poprawka (`PageContext::clean()`,
gałąź `gpt/kontakt-formularz`) dotyczy **tylko przyszłości**. Autor raportu
wprost: „Nie przeglądano ani nie czyszczono historycznych danych
produkcyjnych".

Pytanie: czy mam policzyć, ile rekordów w produkcyjnej tabeli wiadomości
niesie coś wyglądającego na token — **bez wypisywania ani jednej wartości**?
Warianty: policzyć i zaraportować skalę / od razu zredagować / nie ruszać.

### P2. Formularz „Zgłoś" na osobie — ta sama luka co #793

Trasa `/zglos/{type}/{id}` z `type=user` bierze osobę **po nazwie użytkownika** jako `{id}`. Jeśli nazwa
zmieniła właściciela między wyświetleniem a wysłaniem, zgłoszenie idzie na
**niewinną osobę** i zostawia wiersz w moderacji, którego nie cofa jedno
kliknięcie. Cięższy skutek niż przy obserwowaniu.

Stanowisko `nazwa-a-relacje` **świadomie tego nie naprawiło**, bo
`ReportController::resolveTarget()` celowo daje 404 nieodróżnialne od celu,
który nie istnieje (audyt W7-05). Komunikat „ta nazwa należy teraz do kogoś
innego" zmienia profil ujawniania i staje się wyrocznią istnienia kont.

Pytanie: prawda na ekranie czy nieodróżnialność 404?

### P3. Logo wystaje o 19 px — nowe zgłoszenie do założenia

**[pomiar: stanowisko `gpt-tablica`]** Przy viewport 320 px i **powiększonym
bazowym rozmiarze fontu do 200%** logo w globalnym nagłówku wystaje o około
**19 px**. Autor **dowiódł, że to nie jego sprawka**: podmienił kontroler
i widok panelu na wersje z `534e0a51` i dostał ten sam wynik.

To **nie jest** ten sam pomiar co rzeczywisty zoom przeglądarki (ten wypada
czysto). Dotyczy globalnego nagłówka, więc **wszystkich ekranów**.

Pytanie: zakładam zgłoszenie i kto to bierze?

### P4. #797 — dwie granice, których nikt nie wybrał

Komenda dosyłająca zaległe potwierdzenia zgłoszeń (`flota/zaleglosci-zgloszen`,
`03956496`) działa, ale:
- **nie ma górnej granicy wieku** — dośle potwierdzenie także do sprawy sprzed
  roku;
- **nie ma licznika prób ani progu rezygnacji** — zaległość, która pada
  w kółko, wraca co godzinę i melduje porażkę.

### P5. Strażnik kaskady CSS — rozszerzyć zakres?

Repozytorium ma duży zaległy zbiór deklaracji przykrytych poza
`.przepis-liczba` (setki na konfigurację). Strażnik jest dziś bramką tylko na
wskazanym obszarze. Rozszerzenie wymaga tej samej ścieżki: pomiar → decyzja →
dowód niewidoczności.

Osobno: reguła `grid-template-columns` na `.przepis-liczby` jest martwa
**z innego powodu** (nosiciel jest flexem) i jej usunięcie to osobna decyzja.

### P6. `tests/skrypty/izolacja-bazy-testowej.sh` — trzy nazwy baz na sztywno

`kuking_test_izolacja_kontrola`, `…_worktree_a`, `…_worktree_b` plus
`DROP DATABASE IF EXISTS` na nich. **Dwa stanowiska naraz kasują sobie bazy
w trakcie testu.** Do tego stałe ścieżki `/tmp/izolacja-*.log`.
Ironicznie: to skrypt-dowód na naprawę issue #66.

Stanowisko `bazy-stanowisk` naprawiło rodzinę `proba-odtworzenia`/`race`
(`2fec705b`), ale tego **świadomie nie ruszyło** — uznało za osobne zadanie
wymagające własnego przebiegu.

Poza tym z tej samej rodziny: `scripts/check.sh` (`kuking_test_a11y`,
`kuking_test_wydajnosc` na sztywno), `scripts/proba-odtworzenia.sh`
(rozdzielczość jednej sekundy w nazwie) i skrypty `.mjs` z jedną
`BAZA_DOMYSLNA`.

### P7. Główny checkout dalej dostaje bezsufiksowe `kuking_test`

Zostawione świadomie przez `bazy-stanowisk` — główny checkout jest jeden.
Jeśli na maszynie ma stać więcej niż jeden, trzeba to zmienić.

### P8. Wymagany check CI — zablokowany planem GitHuba

Właściciel zdecydował **TAK**, ale API oddaje 403: ochrona gałęzi wymaga planu
płatnego albo publicznego repozytorium. Decyzja stoi, wykonanie czeka na
decyzję o planie.

### P9. #779 — wyszukiwanie we własnych zapisach

`gpt/zeszyt-zapisy` rozstrzygnęło #778 (wyścig przy tworzeniu domyślnego
zeszytu), a **#779 świadomie zostawiło bez implementacji** — zgłoszenie mówi
„rozważyć", a nie „napraw", i nie niesie ani pomiaru wielkości zeszytów, ani
wyniku badania potrzeby.

| Wariant | Koszt |
|---|---|
| **Odłożyć, najpierw sprawdzić potrzebę** (rekomendacja autora) | Zero nowego interfejsu. Krótka próba odnalezienia starszego zapisu na telefonie plus zagregowane rozmiary zeszytów. |
| Szukać we **wszystkich** własnych zeszytach | Zapytania po nazwach przepisów i treściach wpisów, deduplikacja, wskazanie zeszytu, paginacja, testy widoczności i kosztu zapytań. Rozwiązuje prawdziwy problem: człowiek nie pamięta, gdzie zapisał. |
| Filtrować **tylko bieżący** zeszyt | Węższy interfejs, ale nadal wymaga filtrów widoczności i testów — i nie pomaga temu, kto nie pamięta zeszytu. |

Autor nie dodał ani wyszukiwarki, ani testów utrwalających niepodjętą decyzję.

### P10. Pole `przywrocenie` w przyrządzie kontroli ujemnej

Pięć niezależnych raportów GPT odnotowało to samo: pole `przywrocenie` w JSON-ie
zostaje „nie wykonane", bo JSON powstaje **przed** końcowym `trap`. Każdy
z osobna zaznaczył, że nie traktuje go jako dowodu — czyli pięć razy ten sam
akapit wyjaśnień. Naprawa to pół godziny; dziś kosztuje miejsce w każdym
raporcie i chwilę nieufności przy każdym czytaniu.

### P11. #847 — świeża odpowiedź w zamkniętej sprawie znika następnego dnia

**[pomiar: stanowisko `gpt/pytania-widoki`]** Dotyczy korespondencji
z operatorem, nie odpowiedzi w Poradźcie. Zmierzone na sztucznym zegarze
(zamknięcie 20.09.2025, odpowiedź 20.09.2026, sprzątanie następnego dnia,
karencja 12 miesięcy):

| Scenariusz | Po odpowiedzi | Następnego dnia |
|---|---|---|
| Stara zamknięta sprawa | `done`, poprzednie `handled_at` | **sprawa usunięta razem ze świeżą odpowiedzią** |
| Świadomie otwarta ponownie | `in_progress`, nowe `handled_at` | zostaje |
| Otwarta i ponownie zamknięta | nowe zamknięcie | zostaje |

Formularz odpowiedzi jest dostępny także przy sprawie zamkniętej, a ekran
**nie podaje przy nim terminu usunięcia**. Ktoś dopisuje wyjaśnienie i traci
je po dobie, nie wiedząc, że tak będzie.

| Wariant | Koszt |
|---|---|
| **Pokazać termin i drogę ponownego otwarcia** (rekomendacja autora) | Mały/średni: wspólne wyliczanie terminu, tekst, test ekranu. Zachowuje możliwość dopisku bez automatycznej zmiany retencji. |
| Zabronić odpowiedzi do czasu otwarcia sprawy | Średni: blokada w Policy, akcji, formularzu i testach. Czytelny kontrakt, ale dodatkowy krok przy każdym późnym dopisku. |
| Zostawić i tylko dopisać wyjaśnienie | Mały. Nie zmienia minimalizacji danych — świeży ślad nadal zniknie. |
| Przesuwać retencję od ostatniej odpowiedzi | Większy: nowa decyzja o okresie, zmiana w polityce prywatności, granice nieudanych wysyłek. **Nie wdrażać jako przypadkowej poprawki interfejsu.** |

### P12. #750 — porcje przepisu: setne czy połówki

**[pomiar: stanowisko `gpt/skladniki`]** Formularz deklaruje `step=0.5`,
a walidacja dopuszcza `1.25`. Przeglądarka na wyrenderowanym formularzu:
`value=1.25`, `step=0.5`, `validity.stepMismatch=true`, `checkValidity()=false`
— czyli pole jest **nieprawidłowe wobec własnej deklaracji**.

Gorsze jest to, co zmierzono przy trzech miejscach po przecinku:
**`1.255` zapisuje się jako `1.26`** — ciche zaokrąglenie, bez słowa dla autora.

| Wariant | Skutek |
|---|---|
| **Setne: krok 0.01** (rekomendacja autora) | Zachowuje istniejące `1.25`. Odrzuca `1.255` z instrukcją poprawy, zamiast po cichu zaokrąglać. |
| **Połówki: krok 0.5** | Spójne z pierwotnym zamysłem, ale istniejące `1.25` w bazie zostaje, a **kolejna edycja wymusi ręczną zmianę liczby**. To koszt dla autora przepisu i nie wolno go ukryć migracją. |

Autor **nie utrwalił obecnego zaokrąglenia testem** — świadomie, żeby nie
zabetonować zachowania, którego nikt nie wybrał. #750 nie jest zgłoszone
jako naprawione.

### Naprawione #835 — dziewiętnaście zadań nocnych przestało meldować fałszywy sukces

Gałąź `gpt/harmonogram`, commity `646f87c6` i `d238a166`. Zweryfikowane
przeze mnie: **37 testów / 1200 asercji**.

To była najpoważniejsza zaległość infrastrukturalna: harmonogram traktował
**niezerowy kod `Artisan::call` jako sukces w dziewiętnastu callbackach** —
czyli dziewiętnaście zadań cyklicznych (w tym **wszystkie komendy retencji
danych**) mogło padać co noc, a panel i dziennik meldowały, że poszło.

Rozwiązane **jednym wspólnym `app/Support/ScheduledArtisanCommand.php`**,
nie dziewiętnastoma osobnymi poprawkami. Strażnik wymaga co najmniej
dziewiętnastu zarejestrowanych zdarzeń i **oblewa, gdy ktoś dopisze dwudziesty
callback ignorujący kod wyjścia** — sprawdzone.

Przy okazji #809: sonda cache assetów sprawdza teraz rzeczywiste pliki CSS i JS,
a nie sam nagłówek `immutable`.

### P13. Obietnica pomocy w odzyskaniu konta po utracie obu składników 2FA

**[stanowisko `gpt/haslo-konto`]** Ekran mówi dziś, że utrata telefonu i kodów
zapasowych oznacza brak samodzielnego logowania — **i nie obiecuje, że obsługa
konto odzyska**. Komenda operatora istnieje, ale stoi poza interfejsem.

Publiczna obietnica pomocy wymagałaby określenia **rzeczywistej procedury
i sposobu potwierdzenia tożsamości** — bez tego byłaby zdaniem bez pokrycia,
a przy koncie z drugim składnikiem to zdanie kosztowne.

Poprawka takiej obietnicy **nie wprowadza i od tej decyzji nie zależy** —
można ją scalić niezależnie.

### P14. TRZY rzeczy do policzenia na produkcji — jedna decyzja, nie trzy

Trzy niezależne stanowiska doszły do tego samego wniosku: **poprawka chroni
przyszłość, ale nikt nie wie, ile jest przeszłości.** Wszystkie trzy wymagają
odczytu danych produkcyjnych i we wszystkich trzech wystarczy **policzyć, bez
wypisywania wartości**.

1. **Tokeny w kontekście wiadomości kontaktowych** (#836, `gpt/kontakt-formularz`).
   Kontekst strony zachowywał tokeny resetu hasła, logowania i zaproszenia.
   Ile rekordów niesie coś wyglądającego na token?
2. **Zdjęcia bez wariantu `thumb`** ([#912](https://github.com/woogitsu/kuking.pl/issues/912),
   `gpt/moderacja-ai`). Gdy miniatury brak, do OpenAI **poza EOG** może wyjść
   wariant pełnowymiarowy, choć polityka obiecuje pomniejszony. Ile zdjęć nie ma
   `thumb`?
3. **Osierocone prośby o eksport i powielone klucze magazynu** (#824, #825,
   `gpt/eksport`). Zmierzone lokalnie: paczka z dwoma szkicami zawierała
   **jeden plik zamiast dwóch** — wspólny prefiks UUID dawał identyczny
   `object_key`. Ile rekordów produkcyjnych ma powielony klucz i ile próśb
   zostało bez zadania?

**Wspólny mianownik:** każda z tych trzech rzeczy dotyczy **danych osobowych
już wydanych albo już wysłanych**, a nie zachowania ekranu. Odpowiedź „policz"
jest tania; odpowiedź „nie ruszaj" też jest decyzją i trzeba ją zapisać.

---

## 6. Rzeczy zdecydowane i ZAMKNIĘTE — nie wracać

- **#773** (porządkowanie niedostępnych zapisów w zeszycie) — **odkładamy**,
  komentarz z uzasadnieniem pod zgłoszeniem.
- **#760** (okno 5–18 września, usunięcia nieodróżnialne od tekstu wpisanego
  przez człowieka) — **zostawiamy**, zgadywanie na danych ludzi jest gorsze
  niż jawna luka.
- **CHANGELOG „Alfa 0.68"** — kolizji nie ma, przeszukano 187 gałęzi.
- **Hasło demo** — wyjęte do `KUKING_DEMO_HASLO` (`8510ec82`).
- **`.gitignore`** — `.env.*` z wyjątkiem `!.env.example` (ten sam commit).
- **R1** — polityka nie obiecuje już „pełnej kopii" (`fad132b4`). UWAGA:
  warunek tej decyzji **nie jest spełniony** — nie ma procedury obsługi
  ręcznych próśb ani licznika terminu z art. 12 ust. 3. To zaległość
  właściciela, ta sama co R7.
- **Dostęp moderatora do prywatnych zdjęć** — uprawnienie **zostaje**,
  dołożony dziennik wglądów (`gemini/dziennik-wgladow-moderatora`).
- **Retencja zdarzeń usunięcia konta** — skrócona do 36 miesięcy
  (`gemini/retencja-audytu-36m`).

---

## 7. Trzy awarie oprzyrządowania z tego dnia

Opisane szczegółowo w `REJESTR_FLOTY.md`. Skrót, bo wszystkie trzy to ten sam
błąd: **fałszywe potwierdzenie**.

1. Kolejka pchania oznaczała nieudane pchnięcia jako udane — nie patrzyła na
   kod wyjścia. Trzy godziny fałszywego postępu.
2. Stanowisko pchania było **osierocone** (`.git` wskazywał na nieistniejący
   worktree) — `fetch` i `checkout` cicho nie działały, bateria mieliła stary
   kod. Objaw mylący: liczba porażek **rosła** między próbami tej samej gałęzi.
3. Weryfikacja po **nazwie** gałęzi zamiast po **SHA** — gałąź bywa na origin
   pod starym SHA, gdy force-push nie przeszedł.

Plus: wdrożenie w stanie `WAITING` zameldowane jako „wydanie w drodze";
skończyło się `SKIPPED`. **Wdrożenie potwierdza się na żywej stronie:**
`curl -s https://kuking.pl/o-kuking | grep -o "wydanie [^<]*"`.


---

## 8. Uzupełnienia po zapisaniu tego pliku

### `gpt/kontakt-panel` — trzy migracje, wymaga uwagi przy scalaniu

Commity `aae8ca8e`, `71df45f3`, `2a3bcf8c`, `3076d6d3`, `7694e955`.
Zweryfikowane przeze mnie: **199 testów / 1087 asercji**, drzewo czyste.

**Trzy migracje** z opisem w `docs/DATABASE.md`:
`allow_null_handled_by_on_contact_messages`, `add_contact_message_version`,
`add_contact_reply_delivery_markers`.

**Migracje ODMAWIAJĄ wycofania, gdy groziłoby ono utratą danych** — #844 nie
wraca do starego `CHECK` przy osieroconych metrykach, a migracja odpowiedzi
nie pozwala stracić użytych kluczy. To jest zamierzone i opisane. Wycofanie
licznika wersji wymaga wyłączenia zapisu i unieważnienia otwartych formularzy
**przed** ponownym uruchomieniem.

**Wyścig dowiedziony naprawdę:** dwa procesy PHP czekające na rzeczywistą
blokadę PostgreSQL, różne PID-y, suma wywołań poczty = 1. Nie atrapa.

**Przy okazji naprawiono przyrząd kontroli ujemnej** (`2a3bcf8c`): odtworzono
fałszywe odrzucenie trafienia przy 1 MiB wyjścia pod `pipefail`
(`printf | grep -q` → kod 4 zamiast 0), poprawiono na here-string, 14/14 prób
przechodzi. To ta sama pułapka SIGPIPE, którą projekt ma opisaną w
`PULAPKI_TESTOW.md` §5c — i która mimo opisu wróciła w nowym przyrządzie.

Brak decyzji produktowej — #844 rozstrzygnął właściciel bezpośrednio w sesji GPT.

### Wzorzec, który powtarza się w raportach GPT

Wszystkie pięć raportów GPT odnotowuje, że pole `przywrocenie` w JSON-ie
przyrządu kontroli ujemnej **zostaje „nie wykonane"**, bo JSON powstaje przed
końcowym `trap`. Każdy z nich **osobno zaznaczył, że nie traktuje tego pola
jako dowodu**. To jest usterka przyrządu warta naprawienia — dziś kosztuje
akapit wyjaśnień w każdym raporcie.

## 9. UWAGA PRZY SCALANIU — gałąź zastąpiona

**`flota/zeszyty` JEST ZASTĄPIONA przez `robota/jedna-droga-z-zeszytu`.
NIE scalać osobno.** Wyjęta z kolejki pchania, odnotowana w
`/home/mateusz/flota/zastapione.txt`.

Powód: #789 (już na `main`) i `flota/zeszyty` zbudowały **niezależnie dwie
drogi wyjęcia wpisu z zeszytu**. Na ekranie zeszytu renderowały się oba
przyciski naraz — „Usuń z zeszytu" (wszystkie) i „Usuń z tego zeszytu" (ten
jeden), o niemal identycznych nazwach i różnym zasięgu.

`robota/jedna-droga-z-zeszytu` stoi **na `4c811cc7`** (czyli z #789 w środku),
przenosi wszystkie cztery prace z `flota/zeszyty` i godzi je jedną regułą:
**zakres wybiera EKRAN, nie człowiek** — w zeszycie tylko lokalne usunięcie,
poza nim tylko globalne, nigdy oba naraz. Dowodem ujednolicenia jest scena
`test_na_ekranie_jest_dokladnie_jedna_droga_wyjecia`, widziana na czerwono przy
naiwnym złożeniu obu prac.

Dwa doprecyzowania wykonawcy **poza poleceniem**, warte odnotowania:
- komunikat nazywa **faktyczną liczbę** zeszytów („z 3 Twoich zeszytów"),
  a nie formułkę „ze wszystkich" — straszenie bez powodu uczy ignorowania
  komunikatów tak samo jak pytanie bez powodu;
- „Zapisz ponownie" wraca **do tego samego zeszytu**, z którego wyjęto.
  Bez tego cofnięcie po cichu przenosiło wpis do zeszytu domyślnego.

**Znalezisko poboczne:** #777 psuło `KazdaTrasaZIdentyfikatoremPodPolicyTest`
— trasy `collections.edit` i `collections.update` biorą identyfikator z adresu
i nie było ich w spisie przypadków. Same trasy są poprawne (stoją za
`authorize('update')`), brakowało **pomiaru**. Dopisane osobnym commitem.
To ten sam strażnik, którego wiarygodność `flota/r49-trasy` dowodziło rano
mutacją spisu tras — i właśnie zadziałał.

### Do decyzji właściciela z tej gałęzi (drobne)

1. **Nazwy „Usuń z zeszytu" i „Usuń z tego zeszytu"** różnią się jednym
   słowem. Nigdy nie stoją razem, a ekran niesie kontekst. Trzecie słowo
   („Wyjmij") łamie `BRAND_EXTENDED.md` §3; alternatywa „Usuń ze wszystkich
   zeszytów" straszy osobę mającą jeden zeszyt.
2. **Kształt komunikatu przy zakresie globalnym** — „z 3 Twoich zeszytów"
   zamiast „ze wszystkich". Interpretacja zarzutu z #775, zapisana w D-225.
3. **Pomiar pikseli ekranu zeszytu trzeba powtórzyć** — liczby z D-224 (48/18 px)
   dotyczą starego układu, a odnośnik „Masz to w zeszycie" z tego ekranu zniknął,
   więc przycisk stoi w innym otoczeniu.

### `gpt/zdjecia-publikacja` — #873, #872, #871, #874 (raport 20.09, wieczór)

Baza `534e0a5`. Pełny zestaw **4428 testów / 83 784 asercji**, Pint 1162 pliki
PASS, PHPStan czysty, `npm run build` PASS. Pięć kontroli ujemnych
PASS → FAIL z oczekiwanej przyczyny → PASS, z zewnętrznym porównaniem MD5
i mtime PO całym przebiegu (osobno od pola `przywrocenie` w JSON przyrządu —
to jest ta sama usterka oprzyrządowania, co w P10).

Zmierzony koszt #873, suma po dwóch żądaniach, identyczna dla wpisu, pytania
i „Ugotowałem”:

| Scenariusz | Media | Obiekty storage | Zadania |
|---|---:|---:|---:|
| Kolejno, przed | 2 | 4 | 2 |
| Kolejno, po | 1 | 2 | 1 |
| Jednocześnie, przed | 2 | 4 | 2 |
| Jednocześnie, po | 2 | 4 | 2 |

**Koszt równoczesnych uploadów NIE został usunięty** — i stanowisko świadomie
go nie usuwało, bo dalsze ograniczenie wymaga wyboru właściciela: blokada per
osoba/klucz obejmująca zapis plików (dłużej zajęte połączenie) albo trwała
rezerwacja wysłania (nowy stan + migracja + odzyskiwanie po awarii).
**To jest nowe pytanie do właściciela — patrz P15 niżej.**

Znalezisko poboczne: poprawne wysłanie z czasem `"90"` (tekst z formularza)
ujawniło **TypeError** przy wejściu do akcji domenowej. Naprawione jawną
zamianą na liczbę po walidacji.

Wycofanie: `git revert 6718d527`, bez migracji.

### `gpt/2fa-ustawienia` — #876, #811, #875, #877 (raport 20.09, wieczór)

Baza `534e0a5`. Końcowy przebieg **4394 testy / 83 703 asercje / 296,27 s**.
Pierwszy przebieg miał 1 porażkę (`WejscieNaEkran2faNieZdejmujeOchronyTest`
— asercja wymagała dostosowania do nazwanego zbioru błędów `disable`);
stanowisko **nie** uznało jej za zastaną, tylko naprawiło. Pint 1159 plików,
`npm run build` PASS. Sumy SHA-256 wszystkich 13 zmienionych plików
potwierdziły zgodność worktree z testowanym runtime — to jest wzorzec,
którego brakowało w kilku wcześniejszych raportach.

**#875 zmienia format kodów zapasowych: `XXXXX-XXXXX`, alfabet 32-znakowy
bez `0/O/1/I`, 50 bitów** (stary format: górna granica 41,36 bitu i rozkład
nierównomierny). Stare kody nadal działają dokładnie raz; podmiana podobnych
znaków jest odrzucana.

**#877 — pomiar CDP, nie domysł.** Po kontrolowanym usunięciu `no-store`:
`status 200`, `diskCache: true` dla obu powrotów historii z widocznymi kodami;
po przywróceniu nagłówka — 302, `diskCache: false`, kody niewidoczne.
`docker/Caddyfile` ma już regułę `@dynamic` dla `/ustawienia/*`, ale nie była
uruchomiona w lokalnym stosie pomiarowym. Nie odczytywano aktywnej
konfiguracji Cloudflare/Railway — **lokalny wynik sprzed poprawki nie opisuje
produkcji**. Nie badano Safari, Firefoksa ani telefonu.

Brak migracji w obu gałęziach.

### P15. #873 — równoczesne uploady: co dalej (NOWE)

Optymalizacja objęła tylko wysłania **zakończone**. Dwa żądania naprawdę
równoczesne nadal tworzą 2 media, 4 obiekty storage i 2 zadania. UNIQUE
rozstrzyga wyścig poprawnie, więc **nie ma tu usterki** — jest koszt.

Trzy drogi:

1. **Zostawić** — najmniejsza zmiana, kierunek #873 zrealizowany. Koszt
   dotyczy tylko podwójnego kliknięcia / dwóch kart naraz.
2. **Blokada per osoba+klucz obejmująca zapis plików** — usuwa koszt,
   ale trzyma połączenie na czas uploadu i wymaga obsługi oczekiwania.
3. **Trwała rezerwacja wysłania** — nowy stan, migracja, odzyskiwanie
   po awarii. Najdroższe.

Stanowisko świadomie nie wybrało za właściciela.

### `gpt/zalegle` — #732, #748, #749, #752, #765 (raport 20.09, wieczór)

Pięć lokalnych commitów (`424073e9`, `643ba106`, `452420bc`, `fa5af491`,
`8e05fb25`). Końcowy przebieg **4387 PASS / 83 534 asercje / 302,76 s**,
Pint 1157 plików, PHPStan 0, build PASS.

**#765 — wydruk A4, liczby zmierzone przez `page.pdf` w Chromium 153:**

| Przepis | Strony przed | Strony po | Widoczne kontrolki przed/po |
|---|---:|---:|---:|
| Krótki | 6 | 2 | 19 / 0 |
| Długi | 10 | 4 | 19 / 0 |

Strony po zmianie są **identyczne pikselowo** między motywem jasnym
i ciemnym. Stanowisko wprost NIE twierdzi, że przed poprawką ginął tekst
kroku — wykazanym błędem było nakładanie obudowy, kolor i drukowanie akcji
niedziałających na papierze. To jest dokładnie ta ostrożność, której
brakowało w kilku wcześniejszych raportach.

**#748 — NIE ZAMYKAĆ CAŁEGO ZGŁOSZENIA NA PODSTAWIE TEGO PAKIETU.**
Naprawione są DWIE listy relacji. Ekrany dopisane później w komentarzach
(powiadomienia, profil przepisów/wykonań, tagi, zeszyt, zgłoszenia) nie są
ani naprawione, ani sprawdzone. Stanowisko samo to zastrzegło.

**Dwie rzeczy do pilnowania przy scalaniu:**

1. **Krok druku wymaga `pdftotext` i `pdfinfo` NA RUNNERZE.**
   `JobDostepnosciNieWolaAptaTest` złapał w tym kroku niedozwoloną
   instalację pakietu; instalację usunięto, więc przy braku Popplera krok
   podaje instrukcję zamiast sam się doinstalować. **Zdalnego CI nie
   uruchamiano** — a właśnie przełączyliśmy joby na `ubuntu-latest`.
   Trzeba to sprawdzić PRZED scaleniem, bo inaczej pierwsze czerwone CI
   po scaleniu będzie wyglądało na regresję kodu, a będzie brakiem pakietu.
2. **Numer wersji 0.68 podnoszą co najmniej DWIE gałęzie**
   (`gpt/zalegle` i `gpt/zdjecia-publikacja`). Przy szeregowym scalaniu
   druga dostanie konflikt albo, gorzej, cicho nadpisze pierwszą.

Wycofanie offline (#749) wymaga **nowej wersji cache service workera** —
bez tego klienci zostaną ze starym dokumentem offline.

---

## 10. DECYZJE WŁAŚCICIELA Z 20.09, WIECZÓR — podjęte, do wykonania

| # | Sprawa | Decyzja |
|---|---|---|
| D-a | Wdrożenie `4c811cc7` | **Wdrożyć teraz.** Wykonanie zablokowane technicznie — patrz §11. |
| P14 | Trzy rachunki na produkcji | **Policzyć wszystkie trzy, bez wypisywania wartości.** |
| P2 | Formularz „Zgłoś" po nazwie | **Trzecia droga: przypiąć cel po UUID.** Ukryty identyfikator z chwili wyświetlenia; niezgodność = ciche odrzucenie, bez nowego komunikatu. Nie ujawnia nic nowego, więc audyt W7-05 zostaje nienaruszony. **Nikt tego jeszcze nie wycenił.** |
| P13 | Utrata obu składników 2FA | **Pośrednio: wskazać kontakt.** Bez obietnicy odzyskania. |
| P12 | #750 porcje | **Setne, krok 0.01.** Zachowuje istniejące `1.25`; `1.255` ma być ODRZUCONE z instrukcją, nie zaokrąglone. |
| P9 | #779 wyszukiwanie w zapisach | **Szukać we WSZYSTKICH własnych zeszytach.** Właściciel wybrał wariant droższy niż rekomendacja autora — bo rozwiązuje prawdziwy problem: człowiek nie pamięta, GDZIE zapisał. Koszt: deduplikacja, wskazanie zeszytu, paginacja, testy widoczności i kosztu zapytań. |
| P4 | #797 dosyłanie zaległości | **Obie granice naraz:** górna granica wieku ORAZ próg rezygnacji po N próbach. Konkretne liczby do zaproponowania i zatwierdzenia. |
| P8 | Wymagany check CI na `main` | **Zostawić jak jest**, wrócić później. Ochrony gałęzi nie ma; pilnuje tego hook `pre-push` i kolejka — to NIE jest to samo i trzeba o tym pamiętać. |

## 11. DLACZEGO RAILWAY NIE WDROŻYŁ `4c811cc7` — przyczyna ustalona

Usługa `kuking.pl` ma w konfiguracji źródła **`checkSuites: true`** — Railway
CZEKA na wynik check suite GitHuba przed wdrożeniem.

Przebieg zdarzeń:

1. 15:11 — Railway tworzy wdrożenie `81686354` dla `4c811cc7`.
2. 15:39 — check suite nie jest zielony, Railway zamyka wdrożenie jako **`SKIPPED`**.
3. 17:11 — CI staje się **zielone** (przebieg `35518782260`, `Port marki` PASS).

**Zielone CI po fakcie NIE wskrzesza wdrożenia zamkniętego jako pominięte.**
Ostatnie `SUCCESS` to `50663eeb` z 10:55 na `534e0a5` — i to właśnie stoi
na produkcji.

**Czego NIE MOGĘ zrobić dostępnymi narzędziami:** `redeploy` w MCP Railway
ponawia **najnowsze** wdrożenie usługi, a `latestDeployment` to wciąż
`50663eeb` (`534e0a5`). Wywołanie go odtworzyłoby stary build i **nie
wniosłoby #789**. Railway CLI nie jest zainstalowane.

**Drogi wyjścia:**

1. Panel Railway → usługa `kuking.pl` → wdrożenie `4c811cc7` → `Redeploy`.
   Jedno kliknięcie właściciela.
2. Następne scalenie do `main` wywoła świeże wdrożenie normalną drogą —
   a że CI na `main` jest teraz zielone, tym razem nie zostanie pominięte.

**Wniosek na przyszłość:** przy `checkSuites: true` każde czerwone CI
w chwili scalenia kosztuje nie tylko czerwień, ale i **pominięte wdrożenie**,
którego nic nie ponawia samo.

### `gpt/tagi-filtr` — #858 (raport 20.09, wieczór)

Trzy commity (`3d6e98c4` test PRZED kodem, `071abc9f` implementacja,
`079e1bcd` raport). Czerwień przed poprawką: **8 porażek na 11**, z logiem.

**100 tagów przy 320 px: 8377 → 1666 px (−80,1%).** Przy tekście 200%:
27 515 → 5484 px, ten sam spadek. Wiersze „40" i „100" są identyczne —
pierwszy ekran przestał zależeć od długości listy. **Dziesięć tagów nie
zmieniło się o piksel** i nie dostaje przycisku doładowania. HTML 73,0 → 37,6 KB.

Metoda zweryfikowana na cudzym wyniku: przy liście otwartej w całości pomiar
daje **co do piksela** te same 8377 / 27 515 / 3789 co raport bazowy. Dopiero
to pozwoliło zestawić tabele — i to jest wzorzec do naśladowania.

Wariant **bez JavaScriptu, paginacja po stronie serwera**. Odrzucono wariant
„skrypt, a bez skryptu pełna lista", bo zostawiałby 27 515 px dokładnie tym,
którym skrypt się nie dociągnął.

**Czego stanowisko NIE zmierzyło i samo to mówi:** czy ludzie szybciej
znajdują temat. To jest cel zgłoszenia. Zmierzono koszt ekranu, nie skutek.

Do decyzji właściciela z tej gałęzi: porcja = 20 (z pomiaru wysokości, nie
z obserwacji ludzi); czy przeglądanie ma własny koszyk limitera (dziś dzieli
`ustawienia`, 30/10 min — 429 oznaczałoby utratę niezapisanych zaznaczeń);
czy Enter ma filtrować, a nie zapisywać (dziś wynika z kolejności przycisków).

**Znalezisko oprzyrządowania:** `GlosMarkiOpisujeArkuszPrawdziwieTest` czyta
`app.css` wyrażeniem dopasowującym reguły **na przemian**, więc NIEPARZYSTA
liczba nowych reguł nad `.kuking-word` wywala test na czymś, czego nikt nie
ruszał. Obeszte osobnym `resources/css/ekran-tagi.css`; sam strażnik wymaga
poprawki.

## 12. DECYZJE WŁAŚCICIELA — druga paczka (20.09, wieczór)

| # | Sprawa | Decyzja |
|---|---|---|
| P3 | Logo wystaje o 19 px | **Naprawić od razu, przy okazji** — dorzucić do najbliższej gałęzi dotykającej belki. Nie zakładać osobnego zgłoszenia. |
| P5 | Strażnik kaskady CSS | **Rozszerzyć na całość repozytorium.** Wymaga tej samej ścieżki co pierwotnie: pomiar → decyzja → dowód niewidoczności, dla setek deklaracji. To osobny, duży projekt — zaplanować jako taki, nie doklejać do innej pracy. |
| P6+P7 | Izolacja baz testowych | **Naprawić OBIE, ostrożnie.** Trzy nazwy baz ze skryptu mają brać się z tej samej funkcji co reszta, a funkcja ma przestać zakładać, że główny checkout jest jeden. Jest na to `tests/Unit/NazwaTestowejBazyTest.php` — zmiana semantyki wymaga jego świadomego przepisania, nie obejścia. |

### #858 — trzy domknięcia

1. **Porcja = 20 zostaje.** Zapisane wprost: liczba pochodzi z pomiaru
   wysokości ekranu, nie z obserwacji ludzi.
2. **Limiter: OSOBNY koszyk dla przeglądania, 300 żądań / 10 min.**
   Zapis zostaje przy 30/10 min i jego ochrona NIE jest rozluźniana.
   Właściciel najpierw zaproponował podniesienie wspólnego limitu — odrzucone,
   bo osłabiałoby ochronę zapisu i nie usuwało przyczyny (przeglądanie dalej
   zjadałoby licznik zapisów).
   **Niezależnie od limitu: odmowa 429 NIE MOŻE kasować zaznaczonych,
   jeszcze niezapisanych tagów.** To jest wprost „poprawne dane nigdy nie
   znikają" i wchodzi bez względu na liczby.
3. **Enter filtruje — zatwierdzone i DO OBJĘCIA TESTEM.** Dziś to zachowanie
   wynika wyłącznie z kolejności przycisków. Test ma złapać każde ich
   przestawienie, które zamieniłoby Enter w zapis.

## 13. DECYZJE WŁAŚCICIELA — trzecia paczka, KOMPLET (20.09, wieczór)

| # | Sprawa | Decyzja |
|---|---|---|
| P11 / #847 | Świeża odpowiedź w zamkniętej sprawie | **Pokazać termin usunięcia i drogę ponownego otwarcia.** NIE przesuwać retencji — to byłaby nowa decyzja o okresie przechowywania danych i zmiana polityki prywatności. |
| P10 | Pole `przywrocenie` w przyrządzie | **Naprawić przyrząd.** Koniec z pięcioma akapitami wyjaśnień w pięciu raportach. |
| P15 / #873 | Równoczesne wysłania | **Najpierw zablokować drugie kliknięcie NA EKRANIE.** Bez blokady w bazie. |

### #873 — sprostowanie, które zmieniło odpowiedź

Właściciel postawił wymaganie: „nie może być ryzyka, że ktoś kliknie dwa
albo więcej razy i doda się kilka razy to samo". **To wymaganie jest już
spełnione** i nie jest to domysł — stanowisko `gpt/zdjecia-publikacja`
zmierzyło, że we WSZYSTKICH czterech scenariuszach (kolejno i równocześnie,
przed i po) wychodzi `Wpis/wykonanie = 1` i `Powiadomienie = 1`, a obie
odpowiedzi to przekierowanie do TEGO SAMEGO rezultatu. Pilnuje tego indeks
UNIQUE, który działał już wcześniej.

Dubluje się wyłącznie **magazyn** przy idealnie równoczesnych żądaniach:
2 media i 4 obiekty zamiast 1 i 2. Człowiek tego nie widzi; odpięty plik
zbiera istniejące sprzątanie osieroconych zdjęć. Przy zwykłym podwójnym
kliknięciu (jedno żądanie po drugim) poprawka #873 usunęła nawet ten koszt.

Dlatego decyzja brzmi: blokada drugiego kliknięcia w interfejsie, bez
dokładania blokady bazodanowej.

**WARUNEK, KTÓREGO NIE WOLNO POMINĄĆ:** blokada przycisku wymaga JavaScriptu,
a D-053 i konstytucja marki zabraniają martwych przycisków i polegania na JS
w newralgicznych formularzach. **Serwer musi zostać ostatnią linią obrony** —
UNIQUE zostaje, wcześniejszy zwrot zakończonego wysłania zostaje, a bez
JavaScriptu formularz ma działać dokładnie tak jak dziś. Blokada jest
wygodą, nie zabezpieczeniem, i tak ją trzeba opisać w teście.

---

**Wszystkie zebrane pytania P1–P15 zostały zadane i rozstrzygnięte
20 września 2026 wieczorem.** P1 jest zawarte w P14.

### `gpt/sonda-wdrozenia` — #805–#808 (raport 20.09, po 19:00)

Pierwsza gałąź z nowej piątki zleceń, oddana tego samego wieczoru.
Baza `4c811cc7`. Naprawa wyłącznie oprzyrządowania — zero zmian aplikacji,
schematu, DNS i konfiguracji Railway.

**Czerwień przed poprawką zmierzona na czysto: 22 oblane, 8 zaliczonych.**
Test dokłada wyłącznie siebie i atrapę, nie ruszając sondy — czyli mierzy
zastany stan, a nie własną zmianę. Po poprawce 30 zaliczonych, a po
rozszerzeniu zestawu **39 zaliczonych / 159 asercji**.

| Zgłoszenie | Fałszywy sukces przed poprawką |
|---|---|
| #806 | timeout nagłówków po 405 → „nie jest cache'owany", kod 0 |
| #807 | nazwa ciasteczka zawierająca `secure`, bez atrybutu → „ma flagę Secure", kod 0 |
| #808 | 301 na OBCĄ domenę → „przekierowanie na apex", kod 0 |
| #805 | atrapa CLI zwraca 73 → „Gotowe." i kod 0 |

Każda pozycja ma **kontrolę dodatnią** — o to prompt prosił wprost i zostało
to zrobione. Zestaw HIT/MISS używa **ponad 100 kB nagłówków celowo**, żeby
objąć pułapkę SIGPIPE z potokiem do `grep -q`. To jest dokładnie ten błąd,
który dał tu kiedyś „zieloną" sondę na czerwonym stanie.

Pełny przebieg: **4429 zaliczonych / 83 836 asercji / 392,84 s**.
Pint 1156 plików. Cztery kontrole ujemne PASS → FAIL → PASS, a przywrócenie
potwierdzone **porównaniem runtime z nietkniętym worktree** (MD5 i mtime
w tabeli) — nie samym polem `przywrocenie`, o którym wiadomo, że kłamie (P10).

Nie wykonano sondy przeciw produkcji ani prawdziwego Railway CLI.
Wycofanie: `git revert` lokalnego commita; przywróci opisane fałszywe sukcesy.

## 14. P14 ZABLOKOWANE TECHNICZNIE — produkcyjna baza nie jest osiągalna

Decyzja „policzyć wszystkie trzy, bez wypisywania wartości" stoi, ale
**nie da się jej wykonać obecnym dostępem.**

Usługa Postgres w Railway **nie ma żadnego TCP proxy** (sprawdzone:
lista proxy jest pusta), czyli baza żyje wyłącznie w sieci wewnętrznej
Railway i nie odpowiada z tej maszyny. Railway CLI nie jest zainstalowane.

**Czego świadomie NIE ZROBIŁEM:** nie utworzyłem TCP proxy dla bazy.
Wystawienie produkcyjnej bazy danych na publiczny internet jest zmianą
powierzchni ataku o zupełnie innym ciężarze niż „policz rekordy" — i nie
mieści się w udzielonej zgodzie.

**Trzy drogi, w kolejności od najbezpieczniejszej:**

1. **Zainstalować Railway CLI** → `railway run` / `railway ssh` uruchamia
   zapytania WEWNĄTRZ środowiska. Zero nowej powierzchni, poświadczenia
   nie opuszczają Railway. Rekomendacja.
2. **Tymczasowe TCP proxy** na czas rachunków, usunięte natychmiast po.
   Działa, ale przez ten czas baza stoi na publicznym porcie.
3. **Komenda `artisan` licząca i wypisująca same liczby**, wdrożona
   i uruchomiona z powłoki Railway. Najczystsza audytowo, najwolniejsza.

### `gpt/wersje-przepisu` — #895 #896 #898 #900 (raport 20.09, wieczór)

Baza `4c811cc7`. To jest raport z najmocniejszym dowodem wyścigu, jaki
dotąd dostałem — i warto go naśladować.

**#895 zmierzone DWOMA PROCESAMI, nie dwoma wywołaniami w transakcji:**
PID 1307432/1307435, `pg_backend_pid()` 1307434/1307437, bariera w zdarzeniu
`creating` wersji — po odczycie MAX, przed INSERT. Oba procesy wybrały
wersję nr 2; drugi padł z **SQLSTATE 23505** na
`recipe_versions_recipe_id_version_number_unique`. **To nie był deadlock.**
Po poprawce (backendy 1311628/1311633) oba kody wyjścia 0, wersje 1/2/3,
a tytuł, składnik i krok każdej publikacji odpowiadają JEJ migawce.
Test obserwuje w PostgreSQL, że drugi proces czeka na pierwszy — nie zgaduje
synchronizacji stałym uśpieniem. Stanowisko samo zastrzega, że to NIE jest
dowód braku wszystkich możliwych zakleszczeń.

**#898 kasowało dane:** zmiana samego tytułu zamieniała obie uwagi składników
w bazie na NULL. Człowiek tracił tekst, którego nawet nie widział na ekranie.

**#896:** stare migawki zostają bez zmian, a brak pola jest traktowany jako
**nieznany** — nie jako domyślne `false` ani wartość bieżąca. To jest właściwa
decyzja i warto ją zapamiętać jako wzorzec przy każdej zmianie kształtu JSONB.

### #900 — DECYZJA WŁAŚCICIELA I NIEDOMKNIĘTA POŁOWA

Właściciel rozstrzygnął w tamtej sesji 20.09.2026:
**„Zachowaj niezmieniony stary adres; nowe lub zmienione wymagają HTTP/HTTPS."**

Formularz jednostronicowy stosuje tę regułę. Widok linkuje wyłącznie
HTTP/HTTPS, inne zachowane wartości pokazuje jako tekst. Osobny fixture
wykazał, że widok linkował także `javascript:` — **co nie znaczy, że formularz
pozwalał taki adres zapisać**, i stanowisko wyraźnie tego nie myli.

**#900 NIE JEST DOMKNIĘTE DLA KREATORA.** W
`resources/views/components/recipe-wizard.blade.php` i w `validateInfo()`
została reguła `url` bez ograniczenia protokołów. Stanowisko świadomie tego
nie ruszyło, bo kreator jest przydzielony gałęzi `gpt/kreator-przepisu`.

Przekazanie dopisane do `_prompty/19-kreator-przepisu.txt`, z jedną
pułapką nazwaną wprost: **nie wolno uznać adresu FTP świeżo utrwalonego
przez autozapis za „historyczny wyjątek"**.

Nie ustalono, ile historycznych adresów FTP/SSH jest na produkcji — ale
decyzja właściciela chroni je niezależnie od liczby. Bez migracji danych.

## 15. PR-y — pierwsza paczka otwarta

Właściciel zdecydował: otwierać **paczkami po 3–4**, bez automatycznego
scalania, czekając na CI między paczkami.

| PR | Gałąź | Zakres |
|---|---|---|
| [#913](https://github.com/woogitsu/kuking.pl/pull/913) | `flota/gotowanie` | #739 #740 #751 #755 #756 #764 |
| [#914](https://github.com/woogitsu/kuking.pl/pull/914) | `flota/dsa-odwolania` | #796 #797 #799 #800 |
| [#915](https://github.com/woogitsu/kuking.pl/pull/915) | `robota/kaskada-straznik` | przyrząd kontroli ujemnej, SIGPIPE |

W opisie każdego PR-a stoi wprost, że **wyniki pochodzą ze stanowiska
i nie były weryfikowane ponownie przy otwieraniu** — rozstrzyga CI.

**Czeka jeszcze siedem gałęzi bez PR-a:** `flota/wyszukiwarka` (4 commity),
`flota/r47-skan` (3), `codex/audyt-ux50plus` (2), `flota/r73-feed`,
`robota/bazy-stanowisk`, `robota/hero-pierwszy-ekran`,
`robota/martwe-reguly-css` (po 1). Plus dwa chore: **#786** ma konflikt,
**#725** ma nieznany stan sprawdzeń.

### PR-y: paczka druga i trzecia — wszystko z origin objęte

Właściciel: **„rób regularnie PR, żeby się nie zbierało"** — od tej chwili
otwieram je na bieżąco, nie czekając na opróżnienie kolejki.

| PR | Gałąź | Zakres |
|---|---|---|
| [#916](https://github.com/woogitsu/kuking.pl/pull/916) | `flota/wyszukiwarka` | #753 #738 #737 #763 |
| [#917](https://github.com/woogitsu/kuking.pl/pull/917) | `flota/r47-skan` | strażnik R47 + mapa reguł |
| [#918](https://github.com/woogitsu/kuking.pl/pull/918) | `codex/audyt-ux50plus` | cztery miejsca poniżej minimów 50+ |
| [#919](https://github.com/woogitsu/kuking.pl/pull/919) | `flota/r73-feed` | strażnik zakazu algorytmicznego feedu |
| [#920](https://github.com/woogitsu/kuking.pl/pull/920) | `robota/bazy-stanowisk` | nazwa bazy z katalogu (P6/P7) |
| [#921](https://github.com/woogitsu/kuking.pl/pull/921) | `robota/martwe-reguly-css` | reguły bez nosiciela i przykryte kaskadą |

**Wszystkie gałęzie z `origin` mają teraz PR — z jednym wyjątkiem.**

### `robota/hero-pierwszy-ekran` — ŚWIADOMIE BEZ PR-a

Commit nazywa się `WIP` i mówi wprost: „Praca przerwana w połowie na
limicie — NIE JEST SKOŃCZONA i nie ma testu". Otwarcie PR-a zrobiłoby
z niej pracę wyglądającą na gotową, czyli dokładnie to, przed czym
ostrzega cała reszta tego repozytorium.

Co tam JEST (zmierzone, Chromium, gość, deviceScaleFactor 2): dół przycisku
przy 320 px spadł z **776 na 678 px**, a 360/375/414 px mieszczą przycisk
na pierwszym ekranie przy skali 100%. Przy 140% też się poprawiło, choć
właściciel tego nie wymagał.

Czego BRAKUJE: **110 px przy 320 px**, których sam układ nie odda. Jedyną
drogą jest skrócenie tekstu nad przyciskiem — zamysł autora to zostawić
zdanie błogosławione przez `GLOS_MARKI.md` i usunąć opisowe. Do tego
brak testu regresyjnego i ponownego pomiaru ośmiu kombinacji.

**To jest gotowe zlecenie dla kolejnej sesji**, nie PR do scalenia.

### `gpt/konto-poczta` — #888 #889 #890 #887 (raport 20.09, wieczór)

Baza `4c811cc7`. Cztery commity aplikacji, w kolejności do kolejki:
`c4db01c3` (#888), `ce394d8c` (#889), `9377de92` (#890), `c784730d` (#887).

Końcowy przebieg: **4410 PASS / 83 819 asercji / 525,15 s**, kod 0.
Pint 1161 plików. Pliki runtime porównane **bajtowo** z worktree
(SHA-256, 12 zgodnych plików) — ten wzorzec pojawia się już w trzecim
raporcie i powinien stać się normą.

**#888 zmierzone na prawdziwej serializacji zadania**, nie na wywołaniu
metody: `SendQueuedNotifications` zserializowane, potwierdzenie wykonane
do `ArrayTransport`, zmiana potwierdzona w SQL, dopiero potem deserializacja
i wykonanie ostrzeżenia. Przed potwierdzeniem odbiorca był poprawny;
**po potwierdzeniu — NOWY zamiast STAREGO**, także po usunięciu profilu.
Naprawa: odbiorca utrwalany pod blokadą konta przy zamówieniu zmiany
i przekazywany przez `Notification::route`; nazwa wyświetlana też jest kopią,
więc worker nie potrzebuje profilu.

**#887: dwa osobne procesy, różne `pg_backend_pid()`**, oba zatrzymane
w `Profile::updating` PO walidacji wolnej nazwy. Przed poprawką: 302 i **500**.
Oba indeksy UNIQUE istniały, duplikat nie powstał, przegrywający profil
został nietknięty — ale odpowiedź nie miała ani błędu `username`, ani
starych pól, czyli człowiek tracił formularz i nie wiedział dlaczego.
Po poprawce: 302/302, jeden zapis, pięć pól zachowanych.
Błędem pola staje się **wyłącznie** konflikt `profiles_username_unique`
albo `profiles_username_lower_unique`; inne ograniczenia nadal rzucają
wyjątek, **także gdy DETAIL zawiera mylącą nazwę indeksu**.

**Przyrząd zachował się wzorowo:** pierwsze oczekiwanie tekstu w kontroli
#887 nie pasowało do wyjścia PHPUnit i przyrząd **odmówił uznania dowodu**.
Dopiero użycie prawdziwej diagnostyki `SQLSTATE[23505]` z nazwą indeksu
pozwoliło kontroli przejść. Tak ma działać kontrola ujemna.

Pierwszy szeroki przebieg miał 2 porażki. Stanowisko **nie uznało ich za
zastane** — odtworzyło je osobno i znalazło przyczynę: nowy strażnik #889
poprawnie odrzucał fikcyjny token bez wiersza SQL, więc test awarii
transportu nie dochodził do transportu. Poprawiono fixture, **nie wyłączono
ani jednej asercji**.

### DWIE DECYZJE WŁAŚCICIELA PODJĘTE POZA TĄ SESJĄ

To już drugi raport z decyzją zapadłą w sesji GPT, nie tutaj. Zapisuję je,
żeby nie zginęły:

1. **#889 — brak aktualnego tokenu oznacza POMINIĘCIE listu.** Dopisane
   do D-056. Bez nowego tokenu, bez wydłużania TTL, bez wiadomości zastępczej.
2. **#887 — zgoda rozszerzona po pomiarze:** właściciel zobaczył 302/500
   i wyraźnie pozwolił naprawić obsługę konfliktu, mimo że UNIQUE działał.

### P16. #888 — CO ZROBIĆ Z ZADANIAMI, KTÓRE JUŻ CZEKAJĄ W KOLEJCE (NOWE)

Poprawka #888 chroni zadania utworzone **nowym kodem**. W starym ładunku
odbiorcą jest model `User` i **historyczny adres nie został tam zapisany**,
więc nie da się go odtworzyć samą aktualizacją klasy.

**Przed wdrożeniem trzeba rozstrzygnąć, co zrobić z już oczekującymi starymi
ostrzeżeniami.** Stanowisko nie odczytywało ani nie zmieniało kolejki
produkcyjnej — i słusznie.

**PUŁAPKA WYCOFANIA, CIĘŻSZA NIŻ SAMA POPRAWKA:** nie wolno cofnąć klasy
#888, gdy czekają zadania NOWEGO formatu. Stara klasa oczekuje modelu
`User`, a nowe zadania niosą jawny adres. Wycofanie wymaga skoordynowania
zatrzymania workerów, obsługi zaległości i wersji kodu.
**Nigdy nie zamieniać zapamiętanego odbiorcy na bieżący adres** — to
odtworzyłoby dokładnie tę usterkę, którą #888 naprawia.

### Dwa znaleziska poboczne do osobnego zakresu

`UstawienieNowegoHasla` nadal buduje czas z konfiguracji brokera, a
`ZaproszenieDoZalozeniaKonta` nadal **ignoruje przekazaną datę**. Żadna
nie ma `shouldSend`. To osobne cykle tokenów i stanowisko świadomie nie
zastosowało do nich automatycznie rozwiązania z logowania linkiem.
Odczyt, nie pomiar doręczenia — wymaga osobnego zlecenia.


---

## 16. Sześć raportów stanowisk — zapis wierny (raport 20.09)

### `gpt-kreator-przepisu` — #892 #893 #894 #897 #899 #901 (raport 20.09)

Baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Przed poprawką: istniejące
`RecipeWizardTest` i `AutozapisKreatoraWalidujePrzedZapisemTest` — **41 testów,
477 asercji, zielone** na nietkniętym kodzie. Dalej czerwień przed poprawką:
#893 trzy czerwone testy pełnego komponentu Livewire (podwójny rekord po
usunięciu szkicu przez HTTP i autozapisie/zapisie/publikacji); #897 realny PUT
odrzucony walidacją i ponowny GET gubiły tekst pod kluczami `3` i `1000000`;
#894 cztery czerwone próby Livewire z rzeczywistą odmową zdjęcia; #892
Chromium po zapisie i zmianie tytułu nadal pokazywał „Szkic zapisany.”
(oblana asercja `STARE_POTWIERDZENIE`); #901 test HTTP listy dawał 404,
test przeglądarkowy nie znajdował odnośnika „Wszystkie szkice”.

Rozszerzony pomiar ujawnił dodatkowo 404 po zmianie nazwy szkicu otwartego
przez `/przepisy/{slug}/szczegoly` — nie było to w treści żadnego zgłoszenia.

Końcowy pełny przebieg: **4413 testów, 83 920 asercji, 496,00 s**, kod
wyjścia 0. Pint: PASS, 1159 plików. `npm run build`: PASS — 20 testów JS,
72 kontrole kontrastu. Pięć kontroli ujemnych (#893, #894, #897, #901, #892):
PASS → FAIL z oczekiwanej przyczyny → PASS, z porównaniem MD5 i mtime. Trzy
niezależne sumy MD5 po wszystkich próbach: kreator `1575aedb0452afb182c91840b500c583`,
szczegóły `370d91efc1d815a291e3b00b5bf2adea`, ekran dodawania
`47c45ec5eff214e046a671d2be4044db` — wszystkie zgodne z `md5_przed`.

Czego stanowisko NIE zrobiło: pominięto `ProbaOdtworzeniaTest` —
[pomiar cudzy: instrukcja właściciela w tym zadaniu] wspólna baza
`kuking_zrodlo_proby_glowny`, sam autor nie odtwarzał kolizji. Nie wykonano
wdrożenia, CI, pomiarów produkcji ani współbieżnych transakcji usunięcia/zapisu.
Dla #899 zmierzono oba odnośniki oraz niezapisany tytuł i składnik, ale
nie wykonano pełnej macierzy plików i historii przeglądarki, ponieważ
zachowanie wymaga decyzji właściciela.

#899 — do decyzji właściciela (NIE rozstrzygnięte w tym raporcie): wariant
„ostrzeżenie tylko przy zmianach” (mała zmiana, tekst nie przechodzi do
kreatora) kontra „przeniesienie niezapisanych zmian” (większa zmiana: transfer
stanu przez serwer, walidacja niepełnych danych, obsługa plików, tożsamość
kroków i mediów; nie wolno przy okazji publikować zmian już publicznego
przepisu). Rekomendacja autora: ostrzeżenie — ale nie wprowadzono go
samodzielnie ani nie zapisano asercji wybierającej przyszły produkt.

Bez migracji, zmian schematu i zależności. Wycofanie: revert commitów;
istniejące przepisy i szkice zostają w bazie, przywraca opisane usterki.

### `gpt/odbior-wdrozen` — #568 #601 (raport 20.09)

Werdykt raportu: lokalne scenariusze działają; odbiór produkcji pozostaje
otwarty. Nie zmieniono implementacji wyszukiwarki ani joba. Nie wykonano
żadnego żądania do produkcji, nie pobrano jej danych, nie użyto R2 ani Railway.

Przed zmianami: **19 testów / 462 asercje, PASS**, 13,70 s
(`DalszeWynikiWyszukiwaniaTest`, `GraniceOkienWyszukiwaniaTest`,
`JednoDekodowanieZdjeciaTest`, `PrzerwanePrzetwarzanieNieZostawiaSierotyTest`).
#568: przy 401 publicznych przepisach — trzy rozłączne zakresy 200→200→1,
suma ID = zbiór wejściowy; przy 201 z `ile=180` — 180→200→1; przy 201
pasujących osobach — 200→1 bez utraty ID.

#601, sonda `odbior-media-601.php`, trzy fotografie (12 MP, 24 MP, 48 MP):
każda 1 dekodowanie GD w jobie / 3 dekodowania baseline (kontrola dodatnia),
wszystkie 9 wariantów identycznych bajtowo z baseline. Czasy workera:
12 MP — 1233,82 ms ścienne / 1165,32 ms CPU / RSS 229 048 KiB; 24 MP —
1114,75 ms / 1018,12 ms / 203 196 KiB; 48 MP — 1563,02 ms / 1515,98 ms /
321 900 KiB. Jedna próbka na fotografię, współdzielony host — to NIE jest
porównanie wydajności starego i nowego joba ani obietnica takiego samego
czasu na produkcji.

Trzy kontrole ujemne (licznik dekodowań, jakość, dalsze wyniki wyszukiwania):
PASS → FAIL z oczekiwanej przyczyny → PASS. Pełny domyślny zestaw:
**4393 testy / 83 692 asercje, PASS**, 511,09 s, kod wyjścia 0. Pint:
**1156 plików PASS**. Pominięto `ProbaOdtworzeniaTest` zgodnie z instrukcją
zadania (wspólna baza `kuking_zrodlo_proby_glowny`).

Czego stanowisko NIE zrobiło: nie wykonano sondy przeciw produkcji ani
prawdziwego Railway CLI; nie badano startu kontenera, nadzoru procesu ani
połączenia z R2; nie przechodzono formularza uploadu ani synchronicznego
`PodgladOdRazu`. Nie zamknięto żadnego zgłoszenia, nie wysłano komentarzy,
commitów ani PR-ów na GitHub.

Do decyzji właściciela dla #568 (pytanie postawione, NIE rozstrzygnięte w
tym raporcie): zachować dosłowne kryterium >200 na produkcji i czekać na
naturalny zbiór (koszt: zgłoszenie zostaje otwarte), czy jawnie zmienić
kryterium na lokalny dowód dużego zbioru plus produkcyjny pomiar małego
(koszt: brak dowodu pełnego okna na produkcji).

Bez migracji i zmian produkcyjnych. Wycofanie usuwa dokumentację, dowody
i lokalną sondę; nie zmienia zachowania aplikacji ani danych.

### `gpt/pwa-push` — #278 #35 (raport 20.09)

Baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Nietknięty kod PWA: 25 testów
PHP — **243 asercje, zielone**; 5 testów JS modułu — zielone. Nie znaleziono
w mierzonym zakresie potrzeby przepisywania #278.

Własny pomiar Chrome for Testing 151.0.7922.34: rzeczywiste
`beforeinstallprompt` (`isTrusted=true`, `platforms=[web]`), macierz
320/360/390/414/768/1440 px × jasny/ciemny × tekst 100/140% — 24/24: brak
przewijania poziomego, tekst min. 18 px, przycisk min. 50,5 px wysokości.
Nie wykonano natywnej instalacji, testu fizycznego Androida/iOS ani
rzeczywistego zoomu karty 200%. To nie jest pełne zamknięcie #278.

DECYZJA WŁAŚCICIELA (podjęta w tej sesji): maksymalnie jeden zbiorczy push
dziennie dla fundamentu #35.

Przed implementacją #35: 15 nowych przypadków czerwonych z powodu braku klas
i migracji (pomiar braku funkcji, nie mutacja). Po implementacji: **18
przypadków, 53 asercje, zielone** — granice 21:00/08:00, obie zmiany czasu,
Tokio, Nowy Jork, zmiana strefy, niezależność kont, zamknięcie/skasowanie
konta, rollback pustej i wykorzystanej tabeli. `push-budget-race.php`: trzy
różne identyfikatory połączeń PostgreSQL, wynik `[false,true]`, dokładnie
jeden wiersz rezerwacji.

Web Push pozostaje NIEAKTYWNY — to fundament limitu i ciszy, nie gotowy
etap 1 ani kanał. Nocne powiadomienia w aplikacji nietknięte; trwałą kolejkę
odroczeń i preferencje trzeba dobudować przed transportem.

Do decyzji właściciela (otwarte, NIE rozstrzygnięte tu): godzina zbiorczej
wysyłki — pierwszy dozwolony moment daje mniejsze opóźnienie, stała
późniejsza pora zbiera więcej zdarzeń kosztem oczekiwania.

Końcowa weryfikacja: pełny zestaw PostgreSQL — **4411 testów, 83 747 asercji,
zielone**, 468,77 s (pominięto tylko `ProbaOdtworzeniaTest`). Kontrole ujemne:
wyłączenie ciszy oblało 6 przypadków, obejście granicy doby oblało test zmiany
strefy, usunięcie klucza głównego oblało test drugiej rezerwacji; po cofnięciu
mutacji — 18 testów / 53 asercje zielone. PHPStan: bez błędów. `npm run
build`: sukces (20 testów JS, 72 pary kontrastu). Pint: 1160 plików, bez
uwag. Brak pushowania, PR-a, zmiany produkcji i wysyłki wiadomości.

### `gpt/pytania-poradzcie` — #372 #881 (raport 20.09)

Baza `4c811cc7bff365fb8f86d87eabac93b7738a45cd`. Na nietkniętym drzewie:
**74 testy pytań / 461 asercji, PASS**. Kod #372 istnieje; otwarte zgłoszenie
nie oznacza braku implementacji. Nie sprawdzano flagi produkcji.

Przeczytano `gpt/pytania-widoki` (ostatni commit `e63ba828`) i nie
skopiowano ani nie nadpisano jej poprawek kontekstu powiadomień i Pomocy —
„nie są potrzebne do wejścia do kolejki; mają trafić własną drogą przez
kolejkę integracji" (przekazanie/zależność dla innej gałęzi — kolejność
integracji ma znaczenie).

Uzupełnienie widoczności kolejki: przed poprawką dwie czerwienie nowego
wejścia + czerwień Wspomnień. Po poprawce: sekcja „Pomóż odpowiedzieć” +
licznik „Czeka na odpowiedź (N)”, jeden dodatkowy zbiorczy COUNT na ekranie —
nie jest to pomiar wydajności na wielkiej bazie produkcyjnej.

#881: odtworzono błąd na zamrożonym zegarze — własne prywatne pytanie sprzed
roku renderowane na `/home` mimo wyłączonego działu. Nie ustanowiono asercji,
że pytania MUSZĄ być wspomnieniami przy włączonym dziale — to pozostaje
decyzją produktową, zgodnie z #881 (NIE rozstrzygnięte tu).

Weryfikacja własna: przed poprawką 74/461 PASS, nowe próby 3 FAIL + 1 PASS;
po poprawce 18 testów / 76 asercji PASS; pełny przebieg **4397 testów /
83 727 asercji PASS**, 506,82 s. Pint: 4 pliki. `npm run build`: PASS
(72 pary kontrastu, 20 testów JS). Trzy kontrole ujemne: PASS → FAIL → PASS.
Pierwsza próba kontroli Wspomnień miała niewłaściwy wzorzec komunikatu —
nie zaliczono jej, poprawiono i powtórzono.

Przeglądarka lokalna: macierz 24 konfiguracje (320/360/390/414/1440 px ×
jasny/ciemny × 100/140%, plus rzeczywisty zoom Chromium 200%). To nie jest
pełny odbiór całej ramy serwisu ani badanie z osobami 50+. Przypięte belki
mogą zasłaniać treść w pośredniej pozycji przewijania — pomiar potwierdza
dostęp po przewinięciu i fokusie, nie każdą pozycję tekstu.

Do decyzji właściciela (otwarte, NIE rozstrzygnięte tu):
1. Włączenie działu, beta i odbiór produkcyjny #372 — nadal do wykonania.
2. #881 wariant: zostawić obecne przypominanie (mały koszt) albo przypominać
   tylko gotowanie (mała zmiana selekcji, świadome wykluczenie części archiwum).
3. Menu D-163: dodatkowe wejście w menu konta albo zmiana głównej nawigacji
   (większy koszt, ponowny odbiór ramy) — nie dodano szóstej pozycji paska.

Końcowa kontrola po przywróceniu mutacji: 78 testów pytań / 496 asercji PASS;
Pint 4 pliki PASS. Bez zmian schematu, nowych zależności, pushowania, PR,
zamykania zgłoszeń, wdrożenia ani wiadomości do ludzi.

### `gpt/r2-jurysdykcja` — #619 #120 (raport 20.09)

Wynik: obietnica UE pozostaje NIEPOTWIERDZONA. Odbiór prawdziwych bucketów
NIEWYKONANY. Ten raport nie zamyka żadnego zgłoszenia i nie zmienia polityki
prywatności.

Najważniejsze znalezisko: `resources/legal/polityka-prywatnosci.md:9` obiecuje
„serwery w Unii Europejskiej”, ale w badanym drzewie nie ma wyniku odczytu
jurysdykcji rzeczywistych bucketów — tabele odbioru w `BRAMKA_R2.md` §3 i
`LOKALIZACJA_DANYCH_R2.md` §5 są niewypełnione. Autor: „nie ustaliłem, że
zdjęcia są poza UE, ani że obecne buckety mają tylko Location Hint — oba
takie twierdzenia byłyby zgadywaniem".

Próby dostępu: Cloudflare dashboard → przekierowanie na /login, nie
odczytano ustawień bucketów. Railway `list_projects` → `USER_NOT_LOGGED_IN`,
nie odczytano nawet listy zmiennych. Wszystkie pięć ról bucketów
(`AWS_BUCKET`, `AWS_PUBLIC_BUCKET`, `AWS_EXPORTS_BUCKET`, `AWS_LEGACY_BUCKET`,
`AWS_KOPIE_BUCKET`) — jurysdykcja nieodczytana, dwie ostatnie nawet istnienie
niepotwierdzone.

Bramka P0 #120 — 20.09.2026: NIEWYKONANA na prawdziwym R2, brak odbioru.
Wszystkie 13 punktów tabeli `BRAMKA_R2.md` §3: NIEWYKONANE / NIEZWERYFIKOWANE.
Zakaz użytkownika obejmuje wszelkie operacje przeciw produkcyjnym bucketom;
nie udostępniono staging R2; nie uruchomiono bramki nawet bez `--zapis`.

Ograniczenie narzędzia (odczyt kodu): `BramkaR2.php:872–929` w sprawdzeniu 12
zgłasza TAK dla gałęzi `eu` bez warunku udanego odczytu — nie traktować
pojedynczej zielonej linii 12 jako dowodu odbioru wszystkich bucketów.

Własne pomiary lokalne (atrapy magazynu/HTTP, nie produkcja): `php artisan
test --filter BramkaR2MowiPrawdeTest` — 28 testów, 84 asercje, PASS;
`--filter ZapisDoR2BezAclTest` — 12 testów, 46 asercji, PASS. Pint: 1155
plików, PASS. Nie wykonano pełnej suity, migracji, pomiaru bucketów, uploadu,
kasowania, zmiany sekretów, alertów/wiadomości, push i PR.

Propozycje tekstu polityki prywatności są do decyzji, NIE opublikowane —
wariant „przy braku potwierdzenia" i wariant „po potwierdzeniu wszystkich
magazynów w eu". Zapis wprost: „przed publikacją właściciel i osoba
prowadząca #8 muszą uzgodnić cały opis transferów i umów, nie tylko tę
komórkę. Nie zamykamy #619 samym złagodzeniem zdania."

Pułapka przy przyszłej migracji: samo dopisanie „.eu." do endpointu nie
migruje danych. Jurysdykcji istniejącego bucketu nie można zmienić — Hint
jest honorowany tylko przy pierwszym utworzeniu danej nazwy. Kasowanie
starych danych dopiero po odbiorze i osobnej zgodzie właściciela; dopóki poza
eu pozostaje kopia objęta obietnicą, nie deklarować zakończonej migracji.

Zakres zmiany to raport; jego wycofanie polega na usunięciu raportu, bez
wpływu na zachowanie aplikacji.

### `gpt/rozbicie-uslug` — #595 #598 #600 (raport 20.09)

Nie wykonano planu przeciw Railway, apply, wdrożenia, zmiany zmiennych ani
zwiększenia liczby procesów na produkcji. To instrukcja przyszłego wykonania,
nie potwierdzenie odbioru produkcji.

Warunek #598: [pomiar cudzy: `WERYFIKACJA_BUDZETU_POLACZEN_598.md`, 19.09]
`max_connections=500`, rezerwa 3, 497 dostępnych miejsc; FrankenPHP
`num_threads=4`, `max_threads=4`. Budżety wyliczone (nie zmierzone dziś):

| Etap | Szczyt zwykły | Budżet przy deployu (2 komplety + migracja + 3 admin) |
|---|---:|---:|
| Jeden `all` albo web+worker+scheduler | 6 | 16 |
| Dwa weby + worker + scheduler | 10 | 24 |
| Jeden web + worker + media + scheduler | 7 | 18 |
| Dwa weby + worker + media + scheduler | 11 | 26 |

PgBouncer: na podstawie dostępnych liczb jeszcze niepotrzebny — nie dodano
go ani Redisa.

Własny pomiar: na kodzie BEZ poprawki `HarmonogramJednegoSerweraTest` pokazał
`kuking:sprzataj-osierocone-zdjecia` i `kuking:policz-kolejki` wykonane dwa
razy w jednej minucie. Istniejące 3 testy `HarmonogramBezProcOpenTest` (40
asercji) wcześniej przechodziły i nie wykrywały tej usterki. Poprawka:
wszystkich 19 zadań ma `onOneServer()` i `withoutOverlapping()`. To ochrona
przed drugim uruchomieniem, nie exactly-once — awaria po zdobyciu blokady
może opuścić termin, retry kolejki nadal może powtórzyć job. Zdanie z
dawnego #595 „dwa schedulery = dwa identyczne digesty" było zbyt mocne —
test dowodzi podwójnego wywołania, nie podwójnego doręczenia.

Pułapki przy scalaniu/wdrażaniu — DO ZAPAMIĘTANIA:
1. Nazwa usługi `media` nie jest nową rolą entrypointu — rola nadal brzmi
   `worker`. Argument startowy wygrywa z `APP_ROLE`; przy rollbacku trzeba
   zmienić oba.
2. Kolejność wdrożenia obowiązkowa: najpierw poprawka harmonogramu na `all`,
   potem worker/scheduler, na końcu web — nigdy odwrotnie.
3. Przy włączaniu #600 media: najpierw uruchomić odbiorcę `media`, potem
   odebrać tę kolejkę workerowi ogólnemu — inaczej `media` zostaje bez
   odbiorcy.
4. Nie czyścić cache/blokad przy przełączaniu usług; nie ustawiać
   `CACHE_STORE=array` ani `file` na produkcji (Laravel 13 wymaga wspólnego
   cache database do wyboru jednego wykonawcy).
5. Wycofanie: nie używać globalnego `queue:restart` jako wyłącznika —
   nadzorca wznowi worker, sygnał dotknie też workera wewnątrz `all`;
   zatrzymać konkretne usługi. Kolejność wycofania: zmniejszyć web do 1
   repliki → zmienić start na `all` → poczekać na zdrowy `all` → dopiero
   potem łagodnie zatrzymać osobne worker/media/scheduler.
6. Wyłączenie `PRODUCTION_SPLIT_SERVICES` ogranicza web do 1 repliki nawet
   gdy opcje #600 zostały na 2/true — manifest wtedy pomija
   worker/scheduler/media, co może oznaczać usunięcie usług — to NIE jest
   bezpieczna kolejność awaryjnego rollbacku.

Czas RTO nie zmierzony na Railway — szacunek operacyjny 10–20 minut z
gotowym obrazem, do zastąpienia wynikiem próby stagingowej. Worker ma
`drainingSeconds=120`, eksport może trwać do 900 s, `retry_after=960` — job
po przerwaniu może czekać do ok. 16 minut na odzyskanie rezerwacji. Media:
okno 150 s przy limicie joba 120 s.

Do decyzji właściciela (otwarte, NIE rozstrzygnięte tu): budżet miesięczny
(historyczne 12–18 → 40–65 USD/mies. to [szacunek cudzy: #595 / komentarz w
IaC], nie aktualna oferta), koszt drugiego web, koszt media worker, okno
przełączenia i dopuszczalna przerwa, aktualny plan adopcji usług Railway.

Nie wykonano push, PR, zamknięcia issues, Railway apply, zmian zmiennych,
próby na stagingu/produkcji, wysyłki listów ani bieżącego pomiaru
produkcyjnej puli połączeń. Zakres sesji to przygotowanie i lokalna
weryfikacja testowa.

## 17. DECYZJE WŁAŚCICIELA — czwarta paczka (20.09, późny wieczór)

| Sprawa | Decyzja |
|---|---|
| **#619 — R2 i obietnica „dane w UE"** | **Poprawić politykę prywatności tak, żeby mówiła prawdę o stanie dzisiejszym.** Wprost ODRZUCONO zakładanie nowego bucketu w jurysdykcji UE i migrację danych — to osobny projekt. Ustalony fakt: dopisanie `.eu.` do adresu NIE przenosi danych, a jurysdykcji istniejącego bucketu NIE DA SIĘ zmienić. |
| **Monitoring — siedem alertów zewnętrznych** | **Założyć konto u dostawcy monitoringu**, a potem dowieść każdego alertu kontrolą dodatnią. Zakładanie konta należy do właściciela — ja tego nie zrobię. Dziś żaden z siedmiu alertów (dostępność, CPU, RAM, dysk bazy, awaria wdrożenia, koszt, czas odpowiedzi) nie ma dowodu, że w ogóle się odezwie. Kontrola dodatnia transportu WEWNĘTRZNEGO jest wykonana: 16 przypadków, 12 odebranych prawdziwym HTTP. |
| **Piloty AI — klucz API** | Właściciel zapytał, gdzie umieścić klucz. **Odpowiedź: lokalnie, w `.env` runtime — ani w Railway, ani w GitHubie.** Railway to produkcja, GitHub to CI; w obu klucz żyłby dłużej i szerzej niż jeden pomiar. CI nie może wołać prawdziwego dostawcy — ruch HTTP w testach idzie na atrapę. UWAGA: w Railway stoi już `OPENAI_MODERATION_KEY` dla moderacji; **pilotów nie podpinać pod ten sam klucz**, bo nie da się potem rozdzielić rachunku ani wyłączyć jednego bez drugiego. |

### #906 rozszerzone przez właściciela — dwie rzeczy naraz

Właściciel zaproponował komunikat
„[pierwsza osoba] oraz [X] innych osób zapisało Twój przepis [nazwa]",
co opisuje mechanikę SZERSZĄ niż samo #906. Po potwierdzeniu:

1. **Jedna osoba zapisująca w kilku SWOICH zeszytach = JEDEN zapis.** To domyka #906.
2. **Zapisy różnych osób ZLEWAJĄ SIĘ w jedno powiadomienie.**
3. **Rytm: pierwsze natychmiast, kolejne zbiorczo.** Pierwsza osoba powiadamia
   od razu — to moment, który cieszy autora; dalsze dokładają się do zbiorczej.

Musi się zgadzać z decyzją z `gpt/pwa-push`: **maksymalnie jedno zbiorcze
powiadomienie push dziennie.** Nie wolno stworzyć drugiej, konkurencyjnej reguły.

### #750 wykonane, ale POŁOWICZNIE — i to jest zapisane świadomie

Gałąź `naprawa/750-porcze`, commit `46dc3d3f`. Czerwień przed poprawką
zmierzona przez cofnięcie trzech plików do `origin/main`: `1.255` **w ogóle
nie wywoływało błędu walidacji** (`Session is missing expected key [errors]`),
a formularz renderował `step="0.5"` przy walidacji dopuszczającej setne.

Poprawione: formularz jednostronicowy (`step="0.01"`, `min="0.5"`) i
walidacja serwerowa (`decimal:0,2`) z komunikatem mówiącym, co wpisać.

**W KREATORZE `1.255` DALEJ CICHO SIĘ ZAOKRĄGLA.**
`recipe-wizard.blade.php` ma nadal `step="0.5"` i regułę bez `decimal:0,2`.
Stanowisko świadomie tam nie weszło, bo plik należy do `gpt/kreator-przepisu`.
Przekazanie dopisane do `_prompty/19-kreator-przepisu.txt` — to **drugie**
przekazanie do tamtego stanowiska, pierwsze dotyczy adresu źródła (#900).
Oba mają ten sam kształt: reguła działa w formularzu, a w kreatorze jej nie ma.

Niezrobione: pełny przebieg `php artisan test` nie został dokończony
(przerwany, nie obleciany). Zielone są: nowy test regresyjny i dziewięć
powiązanych klas. Pint na czterech zmienionych plikach: PASS.

### `gpt/testy-50plus` — #15, protokół badania R1 (20.09)

Zmiana **wyłącznie dokumentacyjna**, bez kodu. Pełny przebieg 4393 PASS /
83 692 asercje / 517,15 s, Pint 1155 plików PASS.

Powstały cztery dokumenty: protokół R1 (osiem zadań T1–T8 z kryteriami
ustalonymi PRZED badaniem), karty do czytania uczestnikowi (same cele, bez
kryteriów), pusty formularz karty badania i opis przygotowania z dowodami.

**NAJWAŻNIEJSZE ZNALEZISKO — bramka #15 wymaga 13 sesji, nie 5.**
Zgłoszenie mówi: 5 osób 50–59, 5 osób 60–69, **3 osoby 70+**, na Androidzie,
iPhonie i komputerze, w tym przynajmniej dwie osoby bez doświadczenia
publikowania. Stanowisko wprost zastrzega: **pięć osób to pierwsza runda
rozpoznawcza i NIE spełnia tego kryterium.** Koszt: ~6 h 15 min dla pięciu
sesji, ~16 h 15 min dla pełnych trzynastu (bez przygotowania).

**R1 NIE OBEJMUJE pięciu rzeczy z zakresu #15:** rejestracji, komentarza,
usuwania wpisu, blokowania osoby i eksportu. Nie zostały skreślone ze
zgłoszenia — wymagają osobnej sesji i osobnej procedury. **Nie wolno uznać
#15 za zamknięte na podstawie samego R1.**

**R1 nie weryfikuje hipotezy HEIC (#119)** — używa JPG. Potrzebny osobny
wariant z kontrolowanym HEIC, zanim ktokolwiek zamknie zakres #119.

Dobre rzeczy, które warto naśladować: każde zadanie ma **kryterium sukcesu
zapisane przed badaniem** (bez tego wynik zawsze wychodzi pomyślny); jest
jawna tabela **co wolno powiedzieć prowadzącemu**, z zakazem nazywania
przycisku, wskazywania palcem i mówienia „to proste"; każda wskazówka
wyklucza sukces samodzielny i musi być zapisana dosłownie. Wszystkie pomiary
cudze są oznaczone **[pomiar cudzy: źródło]**, w tym 27 515 px z #858 —
z adnotacją, że to koszt ekranu, a nie zachowanie ludzi.

Zapisano też, czego badanie NIE dowiedzie: ani 5, ani 13 sesji nie daje
statystyki populacji ani certyfikatu dostępności; dane ćwiczeniowe obniżają
realny koszt błędu, więc nie dowodzą zaufania do publikacji własnych treści;
powrót po kilku minutach nie dowodzi powrotu po tygodniu.

### `gpt/heic-format` — #119 (raport 20.09, z próbą na prawdziwym iPhonie)

Kod aplikacji **nie zmieniony**, D-064 **nie odwrócone**. Pełny zestaw
4393 testy / 83 692 asercje / 438,14 s.

**NAJWAŻNIEJSZY WYNIK: na iPhonie 17 Pro Max z iOS 27 Safari SAMO konwertuje
HEIF do JPEG przed wysłaniem — we WSZYSTKICH pięciu sprawdzonych ścieżkach.**
Serwer za każdym razem odebrał JPEG, zastosował obrót i zbudował cztery warianty:

| Ścieżka wyboru | Co odebrał serwer | Wynik |
|---|---|---|
| Kontrola JPEG/PNG ze Zdjęć | JPEG 106 305 B, 876×1920 | opublikowane |
| Zwykłe HEIF ze Zdjęć | JPEG 3 761 919 B, 5712×4284, EXIF 6 | obrót zastosowany |
| Live Photo ze Zdjęć | JPEG 4 311 863 B, jeden plik | obrót zastosowany |
| Plik `.HEIC` przez Pliki | JPEG 4 829 560 B | obrót zastosowany |
| Aparat z formularza Safari | JPEG 2 381 909 B, 4032×3024 | obrót zastosowany |

**Granice tego wyniku, zapisane przez stanowisko:** jedno urządzenie, jedna
wersja iOS. Serwer NIE widział oryginalnych bajtów HEIF — to, że przed wyborem
był HEIF, jest informacją właściciela; to, że po wysyłce jest JPEG, jest
pomiarem. To nie dowodzi, że każde Safari i każda ścieżka zachowa się tak samo.

**USTERKA DO NAPRAWY, niezależna od D-064:** obecny komunikat odmowy
**bezwarunkowo obiecuje**, że wysłanie sobie zdjęcia e-mailem da JPG.
Apple opisuje tę konwersję jako zależną od sposobu udostępniania i możliwości
odbiorcy — **nie jako gwarancję**. To jest obietnica bez pokrycia w naszym
interfejsie. Drobiazg przy okazji: polska dokumentacja Apple mówi
„Najbardziej zgodne", a aplikacja „Najbardziej zgodny".

**Czego wciąż brakuje do zamknięcia #119:** danych z 30 dni — ale stanowisko
zastrzega rzecz kluczową: **samo odczekanie 30 dni tych danych nie stworzy.**
Dziś da się policzyć udział HEIC w SYGNAŁACH PORAŻEK, ale **nie ma mianownika
wszystkich prób** ani znacznika pierwszej publikacji. Instrumentacja wymaga
osobnego zadania: migracja + test + `docs/DATABASE.md` + rollback. To nie jest
przełącznik do włączenia w panelu.

Próbki były PRAWDZIWE, nie przemianowanym JPEG-iem: publiczne fixture osxphotos
z podanym commitem i SHA-256, zweryfikowane ExifToolem i załadowane do pełnych
pikseli przez Pillow z pillow-heif. Narzędzia stały w ignorowanym katalogu,
nie stały się zależnością aplikacji.

Koszt wariantów podany jako **widełki planistyczne, nie zmierzony cennik**:
uczciwa odmowa 1–2 dni, konwersja w przeglądarce 2–4 dni prototypu plus 3–6
wdrożenia, obsługa serwerowa 2–4 dni benchmarku plus 5–10 wdrożenia.

## 18. AWARIA REPOZYTORIUM KANONICZNEGO — 20.09, godz. ~21:00

**Co się stało:** katalog `C:\Users\matma\Documents\Codex\kuking.pl` został
opróżniony — zniknęły metadane gita ORAZ wszystkie 3135 plików. Wszystkie
stanowiska floty wskazywały do jego wnętrza (`...kuking.pl/.git/worktrees/<nazwa>`),
więc **112 ze 118 katalogów straciło działającego gita**.

**Przyczyny współtowarzyszące, obie moje:**
1. Zdalny adres `origin` w tym repozytorium wskazywał **sam na siebie** ścieżką
   WSL-ową, więc każdy `git fetch origin` po cichu padał, a `origin/main`
   zamarzł na commicie `61360bf6` — sprzed setek zmian. Tłumiłem błędy przez
   `2>/dev/null`, więc tego nie widziałem.
2. Użyłem `MSYS_NO_PATHCONV=1` przy `git worktree add` ze ścieżką dysku MSYS zaczynającą się od `/c/`.
   Ten przedrostek **wyłącza** tłumaczenie ścieżek, więc git zakładał katalogi
   w `C:\c\Users\...`. **Przy `wsl` jest obowiązkowy, przy `git worktree add`
   jest szkodliwy.**

**Co odtworzone:** repozytorium kanoniczne z GitHuba — 3135 plików, czyste,
`4c811cc7`, 71 gałęzi zdalnych, tożsamość commitów odczytana z historii
(`matmaxalez <mateusz@kapica.be>`), nie zmyślona.

**Czego NIE da się odzyskać:** lokalnych commitów, które nigdy nie trafiły na
GitHuba. Ich treść żyje w plikach stanowisk, historia nie.

**Instrukcja naprawy stanowiska:** `_prompty/00-NAPRAWA-STANOWISKA.txt`.
Najważniejszy jej punkt: kopia stanowiska mogła powstać na starszym `main`,
więc zmiany w plikach, których dane stanowisko nigdy nie dotykało, to **nie
jego praca, tylko cofnięcie cudzej** — trzeba je przywrócić, nie commitować.

**Nie użyłem `git worktree prune`** mimo kuszącej sytuacji — na liście są
cztery wpisy spod `/home/mateusz/`, które z Windows wyglądają na martwe,
a nie są. Usunąłem jeden konkretny wpis administracyjny, po kopii.

### `#914` — miernik naprawiony, praca odzyskana

Commit `d291e4a6` na `flota/dsa-odwolania` (worktree `dsa-odwolania-NOWY`).
Praca powstała w worktree, który stracił gita, i została przeniesiona ręcznie.

**Produkt był niewinny.** `AppealController::resolve()` woła `validate()`
przed jakimkolwiek zapisem. Kłamał miernik: `panel-validation-snapshot.php`
brał `SELECT *` z całej tabeli `users`, a globalny middleware
`AktualizujOstatniaWizyte` zapisuje `ostatnio_widziany_at` przy KAŻDYM
uwierzytelnionym żądaniu, throttlowany co 15 minut. Gdy próg wygasał między
migawką „przed" a „po", porównanie ogłaszało naruszenie, którego nie było.

Dowód **deterministyczny**: test cofa znacznik o próg+5 minut i pokazuje
różnicę w **dokładnie jednej komórce** — `users[<id>].ostatnio_widziany_at`.
Kontrola ujemna bez wygaszenia throttla: zero różnic.

Kontrola dodatnia: po poprawce miernik NADAL wykrywa prawdziwy zapis —
mutacja kontrolera dała czerwień z właściwego powodu (`audit_log: NOWY WIERSZ`),
plik przywrócony z MD5 `d16443274a5f1c8e372b0770d9e7da1d` przed i po.

**UZUPEŁNIENIE po commicie:** pełny przebieg JEST potwierdzony —
**4414 zaliczonych / 83 720 asercji / 1 porażka**, a ta jedna
(`ServiceWorkerOdswiezaMarkeTest`, `exec: node: not found`) wynika z okrojonego
`PATH` w wywołaniu testu, nie ze zmiany. W treści commita stoi, że przebiegu
nie potwierdzono — to było prawdą w chwili commita i zostaje jako ostrożność.

Wykluczono DWIE kolumny: `ostatnio_widziany_at` oraz `pwa_prompt_state`
(pisana przez `InstallPrompt::qualify()` po dobie — **drugie, jeszcze
nieuruchomione źródło tej samej klasy fałszywych alarmów**).
Odrzucono wariant z osobną sesją dla `runCandidate`: drugie konto przechodzi
przez ten sam middleware i tylko przesunęłoby wyścig na inną parę.
