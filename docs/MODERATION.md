# Moderacja

UGC oznacza moderację od pierwszej publicznej wersji.

## Powody zgłoszeń

- spam;
- scam/phishing;
- podszywanie;
- nękanie;
- hate;
- treści seksualne;
- dane osobowe;
- naruszenie praw autorskich;
- niebezpieczna porada;
- nieletni;
- reklama bez oznaczenia.

## UX zgłoszenia

Wyraźny tekst:
**Zgłoś**

Nie tylko ikonka flagi.

## Statusy

```text
open
→ triage
→ reviewing
→ resolved / rejected
```

## Kolejność w kolejce — priorytet (D-236)

`/admin/zgloszenia` sortuje `priorytet ASC, created_at DESC, id DESC`.
Priorytet **liczy się z danych** (`App\Domain\Moderation\PriorytetSprawy`),
nie ma kolumny w bazie i nie da się go wpisać ręcznie.

| Priorytet | Skąd | Napis na karcie |
|---|---|---|
| **P0** — nie może czekać | `reason` ∈ `minor`, `sexual` — ta sama para, co `KategorieModeracji::PILNE` | „Nie może czekać" |
| **P1** — na dziś | `reason` ∈ `scam`, `harassment`, `hate`, `personal_data`; **oraz podłoga** dla `source = legal_notice` (termin z DSA art. 16 ust. 5) | „Na dziś" |
| **P2** — kolejka | reszta (`spam`, `impersonation`, `copyright`, `dangerous_advice`, `other`) | bez plakietki |

Wewnątrz jednego priorytetu porządek jest ten sam co zawsze: najnowsze na
górze, remis rozstrzygany po `id` (stabilne stronicowanie —
`KolejkiModeracjiMajaStabilnyPorzadekTest`).

Podział zgodny z tabelą SLA w `docs/legal/MODERATION_PLAYBOOK.md`, gdzie
opisane są też dwie granice: „groźby zagrażające życiu" i „aktywny doxxing"
są tam P0, ale formularz nie ma takich pozycji, więc wchodzą jako P1.

**Kategorię wybiera zgłaszający i nikt jej jeszcze nie sprawdził.** Priorytet
zmienia WYŁĄCZNIE kolejność czytania i wysyła jeden list — nie ukrywa treści,
nie ogranicza jej zasięgu i nie powiadamia autora.

**Alarm pocztą przy P0.** `AlarmujOPilnymZgloszeniu` woła obie drogi
zgłoszenia (społecznościową i prawną, bo ta druga działa bez konta) i wysyła
`PilneZgloszenieOdCzlowieka` na `kuking.moderation.model.alarm_email` — ten
sam adres, co alarm automatu (D-055). Pusty adres znaczy „bez poczty" i jest
normalnym stanem lokalnie oraz w testach. List nie niesie treści zgłoszonej
ani pola `details`.

**Alarm nie daje się zalać (D-236).** Najwyżej jeden list o danym celu
w oknie `moderation.alarm_czlowieka.okno_celu_godzin` (6 h), najwyżej
`moderation.alarm_czlowieka.dzienny_sufit` (10) listów na dobę dla wszystkich
celów — ostatni mówi, że kolejnych dziś nie będzie — i każdy list zajmuje
miejsce we wspólnym liczniku poczty (D-239, klasa `wejscie`). Powyżej sufitu
sprawa stoi w kolejce z plakietką, a dziennik mówi, dlaczego bez listu.

**Plakietka i priorytet tylko dla otwartych.** Sprawa w innym stanie nie ma
napisu „Nie może czekać"/„Na dziś", a w zakładce „Wszystkie" stoi za
wszystkimi otwartymi, po dacie (`PriorytetSprawy::wKolejce`,
`wyrazenieSqlKolejki`).

## Akcje

- no action;
- ograniczenie widoczności (`hide`);
- **przywrócenie ukrytej treści (`unhide`)** — patrz niżej;
- usunięcie;
- ostrzeżenie;
- czasowe ograniczenie;
- ban.

Nie każda akcja ma sens dla każdego celu — macierz `ModerationAction::DOZWOLONE`
rozstrzyga to jawnie, a formularz pokazuje tylko decyzje możliwe dla danego
zgłoszenia. „Ugotowałem" nie ma `hide`, bo `cooked_events` nie ma kolumny
`status`: przycisk istniał i nie robił nic.

### Kto może rozstrzygnąć i kogo ukarać (issue #1408, D-244)

- **Nikt nie rozstrzyga zgłoszenia, które sam złożył** — także administrator
  i także decyzją „Bez działania" (`ReportPolicy::decide()`). Zgłoszenie
  prawne bez konta rozstrzyga każdy moderator.
- **Zawieszenie i ban tylko wobec niższej roli** (`UserPolicy::sanctionAccount()`):
  moderator karze zwykłe konta, administrator także moderatorów. Konta
  administratora nie zawiesza ani nie banuje nikt z panelu — sprawa idzie
  do właściciela serwisu, rolę odbiera `kuking:nadaj-role`.
- Ukrycie, usunięcie i ostrzeżenie treści nie zależą od roli autora.
- **Cudzą treść moderator usuwa wyłącznie z panelu** (issue #932). Zwykły
  `DELETE` ze strony wpisu, przepisu, komentarza i „Ugotowałem” należy do
  autora (przy komentarzu także do autora treści, pod którą stoi) — moderator
  i administrator dostają tam 403, także z 2FA. Tamta droga omijała 2FA
  panelu, uzasadnienie, wiersz w `moderation_actions` i odwołanie. Pilnuje
  tego `tests/Feature/ModeratorUsuwaCudzaTrescTylkoZPaneluTest.php`.
  Treść bez zgłoszenia zdejmuje się z panelu akcją „Zdejmij z urzędu”
  (niżej, D-251).

Odmowa nie zamyka zgłoszenia i nie zostawia decyzji, powiadomienia ani wpisu
w dzienniku.

### Zdjęcie z urzędu — treść, której nikt nie zgłosił (G31, D-251)

Panel usuwa cudzą treść rozstrzygnięciem zgłoszenia, a własnego zgłoszenia
moderator nie rozstrzyga (D-244). Spamu, którego nikt nie zgłosił, nie dało
się więc zdjąć wcale. Stąd akcja **„Zdejmij z urzędu”**.

- **Gdzie:** przycisk „Zdejmij z urzędu” przy wpisie, przepisie i komentarzu
  (także odpowiedzi), w `.danger-zone`. Prowadzi na ekran panelu
  `/admin/z-urzedu/{typ}/{id}` z formularzem; usuwa dopiero przycisk
  „Zdejmij tę treść” na tamtym ekranie. Działa bez JavaScriptu.
- **Kto:** czynny moderator albo administrator z potwierdzonym 2FA — ta
  sama reguła wejścia co panel (`moderator.2fa`), sprawdzana też w Policy
  (`removeExOfficio` → `UserPolicy::takeDownContentOf()`). Przycisk widzi
  tylko ten, komu Policy pozwala.
- **Czyją treść:** wyłącznie konta o **niższej** roli. Moderator nie zdejmie
  tą drogą treści moderatora ani administratora, administrator — innego
  administratora, nikt — własnej. Inaczej niż przy zgłoszeniu (tam ocena
  treści od roli autora nie zależy, D-244 pkt 3): z urzędu jedna osoba jest
  naraz tą, która sprawę znalazła, i tą, która ją rozstrzyga. Treść
  równej albo wyższej rangi idzie zwykłym „Zgłoś” do kogoś innego.
- **Co trzeba podać:** podstawę z zamkniętej listy (`PodstawaDecyzji`, jak
  przy decyzjach) i **obowiązkowe** uzasadnienie dla autora.
- **Co zostaje:** wiersz w `moderation_actions` — tym samym rejestrze co
  decyzje ze zgłoszeń — z `action = 'remove'` i **pustym `report_id`**.
  Bez sztucznego zgłoszenia. Wpis w dzienniku: `moderation.ex_officio`.
- **Autor** dostaje powiadomienie z uzasadnieniem. Pouczenie mówi
  „Nikt tego nie zgłosił — sprawę znaleźliśmy sami, przeglądając serwis”
  (DSA art. 17 ust. 3 lit. b). Odwołanie idzie **tą samą ścieżką** co od
  decyzji ze zgłoszenia (`/odwolanie/{decyzja}`, rozstrzyga administrator),
  a „cofam” przywraca treść.
- **Jak zdejmuje:** tym samym mechanizmem co „Usuń” ze zgłoszenia — miękkie
  usunięcie, także komentarza z odpowiedziami. Moderacja nie zostawia napisu
  „Komentarz usunięty.” (ten zostawia tylko autor, `DeleteComment`).
  Przywrócenie — ten sam `RestoreContent` co przy decyzji ze zgłoszenia.
  Decyzja właściciela z 24.09.2026 (D-251).
- **Tylko treść widoczna dla innych:** opublikowana, publiczna albo dla
  obserwujących (komentarz — pod taką treścią). Szkic, treść prywatna
  i ukryta dają **404** — moderator nie ogląda prywatnych treści po UUID.
  Nielegalna treść prywatna trafia do moderacji zgłoszeniem nielegalnej
  treści (DSA art. 16, wklejony adres), nakazem organu albo kolejką
  automatu — i tam się ją rozstrzyga (D-251 pkt 7).
- **Kiedy nie:** treść już zdjęta (ekran mówi to od razu, przycisk się nie
  rysuje, druga karta dostaje błąd bez drugiej decyzji) albo z **otwartym
  zgłoszeniem** — wtedy decyzja zapada w kolejce zgłoszeń, żeby zgłaszający
  dostał odpowiedź (art. 16 ust. 5) i żeby o jedną treść nie toczyły się
  dwie sprawy.
- **Po decyzji** panel przechodzi do historii konta autora
  (`/admin/uzytkownicy/{id}`) — tam decyzja z urzędu jest widoczna; w kolejce
  zgłoszeń jej nie ma, bo zgłoszenia nie było.
- **„Ugotowałem” — nie.** `cooked_events` nie ma soft delete, więc zdjęcie
  kasowałoby wpis na stałe, a „cofam” po odwołaniu nie miałoby czego
  przywrócić. Ten sam powód, dla którego zdjęcie (`media`) nie ma `remove`.
  Wraca po dodaniu soft delete do tej tabeli.

### Przywracanie treści (issue #65)

Ukrycie **musi** dać się cofnąć z poziomu serwisu. Podręcznik moderacji sam
każe ukrywać tymczasowo („najpierw ukryć, dać szansę poprawy" przy prawach
autorskich), a bez przycisku jedyną drogą powrotu był `UPDATE` w produkcyjnej
bazie — operacja zakazana bez zgody właściciela (AGENTS.md §6).

- Przywrócenie zapisuje wiersz w `moderation_actions` (akcja `unhide`)
  i wymaga powodu — cofnięcie kary też zostawia ślad.
- Przywrócenie to jedna transakcja z blokadą wiersza treści: dwa kliknięcia
  naraz (dwie karty, „Przywróć” i „cofam”) dają jedną decyzję `unhide`
  i jedno powiadomienie, a awaria w środku nie zostawia decyzji „przywrócone”
  przy treści, która nie wróciła.
- Treść wraca do statusu **sprzed ukrycia**, nie na sztywno do `published`.
  Ukryty szkic po przywróceniu jest dalej szkicem (`moderation_actions.previous_status`,
  patrz `docs/DATABASE.md`).
- Autor dostaje powiadomienie tym samym mechanizmem co przy każdej innej decyzji.

## Co dostaje ZGŁASZAJĄCY (issue #10, DSA art. 16 ust. 4 i 5)

Art. 16 leży w **Sekcji 2** DSA i obowiązuje niezależnie od wielkości firmy —
zwolnienie z art. 19, na którym stoi `docs/legal/COMPLIANCE.md` §1.2, dotyczy
wyłącznie Sekcji 3. Zgłaszającemu należą się więc dwie rzeczy: potwierdzenie
przyjęcia bez zbędnej zwłoki (ust. 4) i informacja o decyzji wraz z
pouczeniem o dostępnych środkach (ust. 5).

Drogi są dwie, bo są dwa rodzaje zgłoszeń — i to jest jedyna różnica między
nimi, nie dwa różne obowiązki:

| Droga | Kto zgłasza | Potwierdzenie (ust. 4) | Decyzja i pouczenie (ust. 5) |
|---|---|---|---|
| „Zgłoś" pod treścią (`/zglos/...`) | osoba **zalogowana** | powiadomienie `report.received` + karta sprawy | powiadomienie `report.decided` + karta sprawy |
| „Zgłoś nielegalną treść" (`/zglos-nielegalna-tresc`) | **każdy, także bez konta** | list na podany adres | list na podany adres, z podpisanym linkiem do odwołania |

Zgłoszenie bez podanych danych (art. 16 ust. 2 lit. c) nie ma komu odpowiedzieć
i to jest zgodne z przepisem, a nie brak w naszych danych.

**Ekran zgłaszającego** — `/zgloszenia` (lista własnych spraw) i
`/zgloszenia/{report}` (karta jednej sprawy). Wejścia: przycisk „Zobacz" przy
powiadomieniu, odnośnik w stopce, przekierowanie zaraz po wysłaniu zgłoszenia.
Nie mylić z `/admin/zgloszenia` — tamto jest kolejką moderatora. Bramką jest
`ReportPolicy::view()`, nie sam UUID w adresie (`AGENTS.md` §7); moderator
świadomie tędy **nie** wchodzi, bo ma własny ekran pokazujący więcej.

**Czego zgłaszający się NIE dowiaduje.** Kto opublikował zgłoszoną treść,
jaką dokładnie karę zastosowaliśmy, ani co ustaliliśmy o tej osobie w trakcie
sprawy. Wie tylko, czy zgłoszenie uznaliśmy za zasadne i czy treść zostaje
w serwisie. Zdania liczy jedna klasa dla obu kanałów —
`App\Domain\Moderation\OdpowiedzDlaZglaszajacego` — żeby list i ekran mówiły
to samo, a nie coś podobnego.

**Zmiana decyzji po odwołaniu autora (#1024).** Gdy autor wygra odwołanie od
`hide`/`remove` i treść wróci, zgłaszający dostaje korektę tym samym kanałem co
pierwszą odpowiedź: nowe `report.decided` w serwisie albo list
`ZmianaDecyzjiWSprawieZgloszenia` przy zgłoszeniu prawnym z adresem. Lista
`/zgloszenia` pokazuje aktualny skutek, karta sprawy — pierwszą decyzję i pod
nią „Zmiana decyzji". Sprawę wiąże łańcuch kluczy obcych (zgłoszenie → decyzja
→ odwołanie autora `overturned`), a nie `reason_code`; dodatkowo ostatnią
decyzją o stanie treści musi być `unhide` (`ZmianaDecyzjiPoOdwolaniu`).
Ręczne „Przywróć treść" bez odwołania **nie** jest zmianą decyzji — zwykle
znaczy, że autor poprawił treść — i karty zgłaszającego nie zmienia. Korekta
nie zdradza autora, treści odwołania ani sankcji wobec konta.

**Skarga na odrzucenie.** Formularz odwołania dla zgłaszającego
(`FileReporterAppeal`, issue #23) obsługuje **wyłącznie zgłoszenia prawne**
z podanym adresem e-mail; wewnętrzny system skarg z art. 20 leży w Sekcji 3,
z której Kuking jest zwolniony. Zgłaszający ze zwykłego formularza dostaje to,
czego wymaga art. 16 ust. 5: pouczenie z numerem sprawy, adresem kontaktowym
i informacją, że decyzja nie zamyka drogi do organu pozasądowego ani do sądu.
Rozszerzenie systemu skarg na wszystkie zgłoszenia to decyzja właściciela,
nie konfiguracja.

**Retencja.** `report.decided` żyje do upływu terminu odwołania od decyzji,
której dotyczy (`Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`) —
niesie pouczenie i jest jedynym miejscem, gdzie zgłaszający przeczyta je drugi
raz. `report.received` idzie ogólnym okresem retencji powiadomień: nie niesie
decyzji ani pouczenia, a trwałym zapisem sprawy jest sam wiersz w `reports`
(`moderation.case_retention_months`, domyślnie 36 miesięcy) czytany na
`/zgloszenia`.

## Sygnały automatu (D-052)

Wykrywacz czyta świeżo opublikowane wpisy i komentarze i — gdy znajdzie znany
wzorzec ogłoszenia, odnośnik zewnętrzny u świeżego konta albo powtórzoną treść
tego samego konta — **stawia jedną pozycję w kolejce moderatora**. Na tym
kończy się wszystko, co robi: treść zostaje widoczna, autor niczego nie
zauważa, nikomu nic się nie dzieje.

- Kolejka: `/admin/sygnaly` — **osobno** od `/admin/zgloszenia`, bo tam czekają
  ludzie i biegną terminy z DSA art. 16 ust. 5. Grupowana po autorze,
  uszeregowana od najcięższego sygnału, z jednym przyciskiem zamykającym całą
  grupę.
- Pełna decyzja (ukryj, usuń, zawieś) zapada tam gdzie zawsze:
  `/admin/zgloszenia?zrodlo=automat`, tym samym formularzem z art. 17.
- „To nic takiego" zamyka sprawę **na zawsze** — automat nie postawi drugiego
  oznaczenia dla tej samej treści.
- Zamyka dokładnie te oznaczenia, które były na ekranie (#1059): formularz
  niesie ich identyfikatory, a gdy automat dopisał do grupy coś nowego po
  otwarciu strony, nic nie zostaje zamknięte i moderator dostaje prośbę
  o ponowny przegląd.
- Wyłącznik: `KUKING_SYGNALY_AUTOMATU=false`.
- Pomiar: `php artisan kuking:raport-sygnalow --dni=30`.

### Druga para oczu: model OpenAI (D-055)

Ta sama kolejka dostaje pozycje z **`omni-moderation-latest`**, który ocenia
tekst i **zdjęcia** pod kątem nienawiści, przemocy, treści seksualnych
i samookaleczenia. Zdjęcia są tu największą wartością: to jedyna treść, której
nikt nie przeczyta, dopóki ktoś jej nie zgłosi.

- **Spamu ten model nie ocenia w ogóle** — jest uzupełnieniem sygnałów wyżej,
  nie ich zamiennikiem.
- Wynik **niczego nie ukrywa i nie blokuje**; kończy się pozycją w kolejce
  z powodem po polsku („Model ocenił zdjęcie: treść seksualna (pewność 82%)").
- Do OpenAI idzie sama treść — **bez adresu e-mail, nazwy konta,
  identyfikatora i adresu IP**. Treści prywatne nie wychodzą w ogóle.
  Opisuje to `resources/legal/polityka-prywatnosci.md`, a użytkownikowi mówi
  o tym punkt 12 zasad.
- Poczta: **jedno podsumowanie dziennie** o 07:00
  (`kuking:podsumowanie-automatu`); list natychmiastowy wyłącznie przy
  treściach seksualnych i wszystkim, co dotyczy dzieci.
- Wyłącznik: pusty `OPENAI_MODERATION_KEY`.

Sygnały, progi, spodziewane fałszywe alarmy, lista rzeczy świadomie
NIEROBIONYCH i moment, w którym to podejście przestaje wystarczać:
**`docs/legal/SYGNALY_AUTOMATU.md`**.

## Copyright

Źródło przepisu:
- własny;
- rodzinny;
- adaptacja;
- zewnętrzny.

Nie publikować pełnych cudzych treści bez praw.

## Food safety

Wrażliwe:
- grzyby;
- wekowanie/botulizm;
- surowe mięso;
- żywienie niemowląt;
- alergie.

Możliwe działania:
- neutralne warningi;
- flagi;
- szybka ścieżka moderacji.

## Odwołania (issue #10, DSA art. 17 i 20)

Odwołanie, po którym nic nie da się zmienić, nie jest odwołaniem. Ścieżka
działa w produkcie, nie tylko na papierze.

**Gdzie się składa**

| Kto | Droga |
|---|---|
| Osoba aktywna albo zawieszona | Przycisk „Odwołanie od tej decyzji" w powiadomieniu → `/odwolanie/{decyzja}` |
| Osoba **zablokowana** | `/odwolanie` — formularz **przed logowaniem**, zamknięty loginem i hasłem. Nie loguje nikogo i nie zdejmuje blokady; służy tylko do przypisania sprawy do konta. Link jest na ekranie logowania. |
| Kto zapomniał hasła | Adres kontaktowy z `config('kuking.community.contact_email')` — droga zapasowa, wypisana na obu formularzach |

Formularz całkiem otwarty odrzucono: przy jednym moderatorze byłby gotowym
celem spamu. Sam adres e-mail odrzucono: odwołania nie ma wtedy w logu, terminu
nie da się pilnować, a odpowiedź nie trafia do produktu.

**Ile razy** — raz od jednej decyzji (`UNIQUE (moderation_action_id)`).
Nowe okoliczności idą adresem e-mail.

**Terminy** (`config('kuking.moderation')`, zgodne z
`docs/legal/MODERATION_PLAYBOOK.md`):

- **sześć miesięcy od decyzji** na złożenie odwołania — to jest twarda
  podłoga wymagana przez DSA (art. 20 ust. 1). `appeal_days` z konfiguracji
  (`KUKING_APPEAL_DAYS`, domyślnie 180 dni) może ten termin tylko wydłużyć,
  nigdy skrócić: `ModerationAction::appealDeadline()` bierze większą z dwóch
  wartości, więc realny termin to zawsze co najmniej sześć miesięcy —
  egzekwowane. Do 7 IX 2026 stało tu 14 dni — liczba wzięta z rozsądku
  operacyjnego, nie z przepisu; podręcznik
  (`docs/legal/MODERATION_PLAYBOOK.md` pkt 3) odnotowuje tę samą poprawkę;
- 7 dni roboczych na odpowiedź — pokazywane moderatorowi w kolejce, z
  oznaczeniem spraw po terminie;
- 24 godziny karencji, zanim ten sam moderator **podtrzyma** własną decyzję.
  Cofnąć własną decyzję może od razu.

**Kto zamyka sprawę — administrator, nie moderator** (D-039). Kolejkę
odwołań WIDZI każdy moderator; decyzję („podtrzymuję" / „cofam") przyjmuje
wyłącznie konto z rolą `admin` (`UserPolicy::resolveAppeals()`). Powód:
odwołania nie ma zamykać rola pierwszej linii, która wydaje decyzje.

**To jest bramka na ROLĘ, nie na osobę** — i lepiej wiedzieć to teraz niż
przy pierwszej trudnej sprawie. Administrator przechodzi też przez
`moderate()`, więc jeden człowiek z tą rolą dalej może wydać decyzję
i rozstrzygnąć odwołanie od niej samej; powstrzymuje go wtedy wyłącznie
karencja i tylko przy podtrzymaniu. Wartość tego zawężenia pojawia się
przy DRUGIEJ osobie w zespole: moderator bez roli administratora
przestaje móc zamknąć sprawę, którą sam rozstrzygał. Wcześniej
jedyną barierą było 24 godziny karencji na PODTRZYMANIE własnej decyzji —
a ta nie przeszkadzała ani cofnąć własnej od razu, ani zamknąć sprawy
dowolnemu innemu moderatorowi bez opóźnienia. Moderatorowi bez tej roli
formularz odpowiedzi się nie pokazuje (zamiast tego stoi zdanie mówiące, kto
sprawę zamyka) — nie po to, żeby coś ukryć, tylko żeby nie stracił napisanego
uzasadnienia na błędzie 403.

**Skąd się bierze administrator** — `php artisan kuking:nadaj-role <login> admin`,
z powłoki produkcyjnej. Nie ma na to ekranu w produkcie: przejęcie jednego
konta administratora wystarczyłoby wtedy, żeby zrobić administratorów
z kolejnych. Komenda odmawia odebrania roli OSTATNIEMU czynnemu
administratorowi i zapisuje każdą zmianę w `audit_log`
(`user.role_changed`).

Zmiana roli i wpis audytu zatwierdzają się w jednej transakcji. Równoległe
polecenia serializuje `ChangeUserRole`: wspólna blokada ról poprzedza blokadę
konta, a status, poprzednia rola i liczba pozostałych czynnych administratorów
są sprawdzane ponownie po oczekiwaniu. Pytanie o potwierdzenie nie trzyma
transakcji. Ta sama blokada (`OstatniAdministrator`) chroni zawieszenie,
ban i własne żądanie usunięcia konta: ostatniego czynnego administratora nie
da się ani zdegradować, ani odebrać mu aktywności, także dwiema równoległymi
operacjami na dwóch różnych kontach. Zakres i pomiar:
[`OSTATNI_ADMINISTRATOR_1016.md`](security/OSTATNI_ADMINISTRATOR_1016.md).

**Zawieszone konto obsługi nie ma uprawnień moderacji** (issue #1336, #1351).
Zawieszenie nie zmienia roli, ale `User::isModerator()` i `User::isAdmin()`
zwracają `true` tylko dla czynnego konta (`status = active`). Zawieszony
moderator albo administrator czyta własne treści, może się wylogować i złożyć
odwołanie jak każdy zawieszony — ale panel `/admin/**` daje mu 404, a Policy
nie otwierają mu cudzych szkiców, prywatnych treści ani zdjęć.
Zawiadomienia o odwołaniach (`appeal.filed`) widzi na liście, w liczniku
i przez „Zobacz" tylko czynny administrator; po odebraniu roli albo przy
zawieszeniu wiersz zostaje w bazie i wraca razem z uprawnieniami.
`reinstate()` przywraca dostęp bez ponownego nadawania roli (2FA dalej
obowiązuje). Zakaz wejścia kontem obsługi linkiem, przez Google albo
Facebooka patrzy na samą rolę (`User::hasStaffRole()`), więc zawieszenie go
nie zdejmuje.

**Jak moderator zamyka sprawę** — `/admin/odwolania`: widzi słowa
odwołującego się, decyzję wraz z powodem oraz dokładnie tę wiadomość, którą ta
osoba wtedy dostała. Wybiera „podtrzymuję" albo „cofam" i **musi** napisać
uzasadnienie. Cofnięcie realnie przywraca treść albo odblokowuje konto.

**Jak odpowiedź dociera** — powiadomieniem typu moderacyjnego. Osoba
zablokowana czyta je na ekranie logowania (`LoginController`), bo do serwisu
nie wejdzie.
