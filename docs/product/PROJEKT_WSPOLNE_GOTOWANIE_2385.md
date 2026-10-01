# Wspólne gotowanie — projekt autoryzacji i rdzenia (#2385, etap 1)

**Status:** projekt do wdrożenia w etapie 1 · 1 października 2026
**Decyzja właściciela (1.10.2026):** budujemy PEŁNĄ sesję wspólnego gotowania
(link zaproszenia, role, wspólny postęp). Wiersz w D-333 („bez nowego numeru”).
**Offline (#1904) jest wstrzymany** decyzją właściciela — sesja działa online
i NIE obiecuje pracy bez sieci. Z kryteriów zgłoszenia „offline/reconnect”
odpada więc to, co wymaga trybu offline; zostaje uczciwy komunikat przy braku
sieci (patrz „Odświeżanie”).

Ten dokument jest pisany PRZED kodem i kod go wykonuje. Tam, gdzie wymagana
jest decyzja właściciela, wybrano wariant bezpieczniejszy i zapisano pytanie
(sekcja „Pytania do właściciela”).

## 1. Czym jest sesja (i czym nie jest)

Sesja = jeden przepis, jedna osoba **gospodarz** (zakłada), do niej dołącza
**pomocnik** (po jednorazowym linku). Obie osoby widzą ten sam przepis i **ten
sam wspólny postęp kroków**. Nie ma czatu, wiadomości prywatnych, rankingu,
feedu, publikacji ani powiadomień — AGENTS.md §12, zgłoszenie #2385
(„bez DM”). To nie jest grupa w rozumieniu #22 (jeden przepis, krótkie życie).

Sesja jest **prywatna dla uczestników** i **niezależna od zapisu „Ugotowałem”**:
zakończenie sesji niczego nie publikuje i nie zapisuje wykonania (wykonanie
przepisu pozostaje prywatne do osobnej publikacji — `RecordCookedEvent`,
nietknięte). Sesja nie zmienia przepisu.

Świadomie MAŁY zakres etapu 1 (wg „Proponowanego kierunku” ze zgłoszenia):
jeden przepis, dwie role, **dokładnie dwie osoby** (gospodarz + jeden
pomocnik). Limit to `kuking.wspolne_gotowanie.max_pomocnikow` (domyślnie 1);
kod i schemat nie zakładają „dokładnie jednego”, ale blokada i wymazanie konta
są w etapie 1 rozumowane dla pary.

## 2. Kto może założyć sesję

- Zalogowane, **aktywne** konto (zawieszone czyta, nie zakłada — jak
  `CookingProgressPolicy::create`).
- Przepis, który ta osoba **widzi** wg `RecipePolicy::view` (UUID/slug nie jest
  autoryzacją). Sesja na własnym prywatnym przepisie jest dozwolona, ale
  pomocnik go nie zobaczy (sekcja 4) — ekran zakładania mówi to wprost,
  zanim powstanie link.
- Jedna sesja na parę (gospodarz, przepis) — unikalny indeks. Ponowne
  „Gotuj z kimś” zwraca istniejącą, niewygasłą sesję. Najwyżej
  `max_sesji_gospodarza` (5) naraz.
- Tylko przepis, który ma kroki (sesja jest o krokach).

## 3. Zaproszenie

- **Jednorazowy link** z losowym tokenem (40 znaków). W bazie leży wyłącznie
  **SHA-256 tokenu** (`token_hash`, jak `collection_invitations`, D-302);
  token istnieje w odpowiedzi jeden raz i nie da się go odczytać z bazy ani
  z eksportu. Kolumna jest poświadczeniem — poza `$fillable`.
- **Wygasa po 24 h** (`link_godziny`), nigdy później niż sesja; kończąc sesję
  gospodarz kasuje też zaproszenia.
  Jeden aktywny link naraz: nowy link unieważnia poprzedni.
- **Odwołanie przez gospodarza:** „Odwołaj link” (status `revoked`, kasuje
  skrót tokenu). Gospodarz kończąc sesję kasuje też wszystkie zaproszenia.
- **Jednorazowość:** przyjęcie zużywa link (status `accepted`) pod blokadą
  wiersza sesji i zamkiem pary kont; dwa równoległe przyjęcia dają jedno
  członkostwo. Przyjęty link zachowuje skrót tokenu do końca sesji (CHECK
  w bazie), żeby drugie kliknięcie „Dołączam” tej samej osoby było sukcesem bez
  skutku; ten sam link nie wpuszcza nikogo innego ani osoby usuniętej z sesji.
  Odwołany link traci skrót.
- Wejście na adres linku (GET) **niczego nie zużywa** i nie ujawnia treści
  przepisu osobie bez uprawnień (sekcja 4). Link bez dostępu = jedno zdanie:
  „Nie możesz dołączyć do tej sesji. Poproś gospodarza o nowy link.” — to samo
  zdanie dla tokenu nieznanego, wygasłego, odwołanego, zużytego i dla braku
  dostępu do przepisu (adres nie jest wyrocznią).
- Limit żądań: istniejący `zaproszenia` (20/10 min) dla tras zaproszeń,
  `cooking_krok` (60/min) dla odhaczeń i odczytu stanu. Nowych kluczy limitów
  nie dokładamy.
- Odwołany/zużyty wiersz zaproszenia żyje do końca sesji (to ślad „kto kiedy”),
  potem znika razem z nią.

## 4. Kto może dołączyć (autoryzacja — sedno projektu)

Przyjęcie linku wymaga **wszystkich** warunków, sprawdzanych **pod zamkiem pary
kont** (`ZamekPary`, ta sama kolejność blokad co przy zaproszeniu do zeszytu,
D-080/D-302) i na wierszach odczytanych pod zamkiem:

1. osoba jest **zalogowana** (gość trafia na logowanie i wraca na link);
2. osoba ma **aktywne** konto (`mozeCzytac()` do podglądu, `isActive()` do
   dołączenia);
3. osoba **nie jest gospodarzem**;
4. **nie ma blokady** między osobą a gospodarzem w żadną stronę (odmowa
   tym samym zdaniem — nie zdradzamy, że ktoś kogoś zablokował);
5. osoba **widzi przepis wg `RecipePolicy::view`** (to obejmuje blokadę wobec
   AUTORA przepisu, widoczność „dla obserwujących”, konto autora zbanowane,
   stan przepisu). **Link nie daje dostępu do treści osobie bez uprawnień.**
   Prywatny przepis gospodarza = pomocnik nie dołączy (nikt poza autorem go
   nie widzi). Tytuł przepisu na stronie linku pokazujemy dopiero PO
   pozytywnym `view`;
6. sesja jest aktywna (nie zakończona, nie wygasła) i jest wolne miejsce.

**Dostęp jest sprawdzany przy każdym żądaniu, nie tylko przy dołączeniu.**
Ekran sesji, odhaczenie i odczyt stanu przechodzą przez `CookingSessionPolicy`
(członek sesji) **oraz** `RecipePolicy::view` dla oglądającego. Gdy autor
zmieni widoczność przepisu na prywatną, zablokuje pomocnika albo przepis
zniknie — pomocnik traci treść natychmiast (widzi komunikat bez treści
przepisu). Wyjątek: gospodarz zawsze musi widzieć przepis (inaczej sesja jest
martwa i zostaje sprzątnięta przy wygaśnięciu).

Osoba niebędąca członkiem sesji dostaje **404** (jakby sesji nie było), także
zalogowana i także gospodarz innej sesji — `denyAsNotFound`. UUID sesji w
adresie niczego nie otwiera.

## 5. Role i uprawnienia

| Akcja | Gospodarz | Pomocnik |
|---|---|---|
| Zobaczyć przepis i postęp (jeśli widzi przepis) | tak | tak |
| Odhaczyć / cofnąć krok | tak | tak (jeśli aktywne konto) |
| Minutniki | lokalne w przeglądarce każdej osoby (D-333/#2016 — minutniki nie są synchronizowane); nie ma uprawnień do różnicowania | j.w. |
| „Zacznij od początku” (wyczyść odhaczenia) | tak | nie |
| Utworzyć / odwołać link | tak | nie |
| Usunąć pomocnika | tak | — |
| Wyjść z sesji | — (kończy sesję) | tak |
| Zakończyć sesję (kasuje dane) | tak | nie |

Dlaczego pomocnik może odhaczać: to cały sens „pomagam”. Dlaczego nie może
wyczyścić postępu: jedno przypadkowe kliknięcie nie powinno kasować pracy
dwóch osób. Odhaczenie jest **ustawieniem** („ten krok: zrobiony/nie”), nie
przełączeniem — idempotentne.

Przypisywanie kroków i składników do osób (z treści zgłoszenia) **nie wchodzi
do etapu 1**; wchodzi „kto odhaczył” (audyt), a przypisanie jest propozycją na
etap 2 (pytanie 3).

## 6. Wspólny postęp i odświeżanie

- Stan: tabela `cooking_session_steps` — jeden wiersz = „ten krok jest
  zrobiony”, klucz główny `(session_id, step_id)`. Zapis `INSERT … ON CONFLICT
  DO NOTHING`, więc **dwa równoczesne odhaczenia tego samego kroku dają jeden
  wiersz i żadnego błędu**; cofnięcie to `DELETE`. Kolumny `done_by_id` i
  `done_at` to **audyt** („Zrobiła Basia, 18:42”). Krok usunięty z przepisu
  znika razem z wierszem (klucz obcy).
- `revision` sesji rośnie o 1 przy każdej **realnej** zmianie (odhaczenie
  już odhaczonego nie zmienia niczego i rewizji nie podnosi). To jest to, co
  widzi druga osoba, żeby zauważyć zmianę. Formularz niesie rewizję, którą
  osoba widziała; przy rozbieżności dostaje **jedno zdanie** („Druga osoba
  zmieniła postęp. Widzisz jego aktualny stan, a Twoje kliknięcie zostało
  zapisane.”) — jak w #2016.
- **Bez WebSocketów i bez `wire:poll`** (AGENTS.md §3). Dwie ścieżki:
  1. **Przycisk „Odśwież”** (zwykły link, działa bez JS) — zawsze widoczny.
  2. **Krótki polling tylko jako ulepszenie**: istniejący skrypt
     `postep-gotowania.js` (ten sam co w #2016) co 30 s, tylko gdy karta jest
     widoczna, pyta `GET /gotowanie-razem/{cookingSession}/stan` o samą rewizję (JSON, jedno zapytanie po
     kluczu) i, gdy jest inna, pokazuje pas „Druga osoba zmieniła postęp —
     Odśwież”; po pokazaniu pasa przestaje pytać. **Nie przeładowuje strony
     sam** (gotujący ma brudne ręce i czyta krok; niespodziewane
     przeładowanie jest gorsze od pasa). Koszt: dwie osoby × 2 żądania/min ×
     czas gotowania (1–2 h) ≈ 240–480 lekkich żądań na sesję; limit
     `cooking_krok` 60/min jest szeroko powyżej. Uzasadnienie (AGENTS.md §3 wymaga pomiaru kosztu):
     bez pasa wspólny postęp bywa nieaktualny aż do ręcznego odświeżenia, a
     to jest istota funkcji; bez JS zostaje przycisk. Pomiar wdrożeniowy:
     `http_requests` dla trasy `wspolne-gotowanie.stan`.
  Błąd sieci przy pytaniu o rewizję jest po cichu pomijany (tak jak w #2016);
  odhaczenie wykonuje się zwykłym formularzem, więc przeglądarka sama zgłosi
  brak połączenia. **Nie** kolejkujemy odhaczeń offline i nie pokazujemy stanu
  „niesynchronizowane” (#1904 wstrzymany).

## 7. Retencja

- Sesja ważna **24 h od założenia** (`retencja_godziny`), stały termin
  (bez przedłużania „ruchem” — sesja nie staje się trwałą historią konta).
- Po terminie: wygasła sesja jest **niewidoczna** (odczyt ignoruje ją, 404/
  komunikat), a nocne `kuking:sprzataj-wspolne-gotowanie` **kasuje** sesję
  z odhaczeniami, pomocnikami i zaproszeniami. Odczyt nie zależy od tego, że
  harmonogram żyje (jak `cooking_progress`).
- **Zakończenie przez gospodarza kasuje sesję od razu**, w jednej
  transakcji (wiersze potomne znikają kluczem obcym). Pomocnik wchodzący potem
  na adres dostaje 404 / komunikat „Tej sesji już nie ma”. Dostęp ginie w
  chwili kasowania — nie ma stanu „zakończona, ale jeszcze czytelna”.
- Odejście pomocnika / usunięcie go przez gospodarza kasuje jego wiersz
  uczestnika; **odhaczenia zostają** (to praca w gotowaniu), ale tracą
  podpis (`done_by_id = NULL`) tylko przy usunięciu KONTA; przy zwykłym
  wyjściu zachowują podpis do końca sesji (sesja trwa najwyżej 24 h).

## 8. Blokada, usunięcie konta, eksport, wymazanie

- **Blokada** w którąkolwiek stronę między gospodarzem a pomocnikiem kończy
  jego udział natychmiast i unieważnia link (kontrakt `Users` → implementacja
  w `Recipes`, wołane przez `BlockUser` pod zamkiem pary — tak jak
  `KoniecWspolnychZeszytow`, graf modułów bez cykli #971). Przyjęcie linku
  wyścigujące się z blokadą jest szeregowane tym samym zamkiem pary.
- **Usunięcie konta** (każdy zakres): sesje gospodarza znikają w całości;
  udziały pomocnika znikają; `done_by_id` tej osoby w cudzych sesjach
  → `NULL` („osoba, która usunęła konto”).
- **Eksport (RODO art. 15):** sekcja `wspolne_gotowanie` w `dane.json` — sesje
  niewygasłe, w których osoba jest gospodarzem lub pomocnikiem: rola, tytuł
  przepisu (tylko gdy osoba go widzi — jak w `postep_gotowania`), numery
  kroków odhaczonych PRZEZ TĘ OSOBĘ, termin ważności, liczba zaproszeń.
  Bez tokenów i skrótów, bez danych drugiej osoby (nazwa drugiej osoby jest
  widoczna na ekranie sesji, ale nie jest wydawana w paczce — to jej dane).
  Wpisy w `InwentarzDanychKonta` dla każdej kolumny wskazującej na konto.
- **Wymazanie:** kończenie sesji i „wyjdź” są jawnymi akcjami na ekranie;
  nie ma osobnego „wymaż wszystko” — sesja i tak żyje najwyżej 24 h.

## 9. Prywatność i zgodność

- Dane: identyfikator osoby, rola, który krok zrobiła i kiedy — **do 24 h**
  (albo do zakończenia). Brak adresu IP, brak treści wpisanych przez
  użytkownika (nie ma pól tekstowych), brak lokalizacji.
- Podstawa: wykonanie usługi, którą osoba świadomie uruchomiła (art. 6 ust. 1
  lit. b). Brak profilowania, brak zgód, brak ciasteczek. Polityka
  prywatności dostaje drobną poprawkę bieżącej wersji (nowa kategoria danych
  i jej retencja), bez nowej wersji z paskiem — decyzja koordynatora w
  zleceniu; patrz pytanie 5.
- Druga osoba widzi: nazwę wyświetlaną/konta gospodarza (pomocnik) i pomocnika
  (gospodarz) oraz „kto zrobił krok”. Nic ponad to. Sesja nie pojawia się
  w profilu, w feedzie, w wyszukiwarce ani w statystykach publicznych.
- `X-Robots`/`noindex` i `Cache-Control: private` na ekranach sesji; token w
  adresie linku: `Referrer-Policy: no-referrer` na stronie linku (token nie
  wycieka do zewnętrznych zasobów).
- Wgląd moderatora: brak. Sesja nie jest treścią do moderacji; moderator nie
  ma drogi do jej ekranu (`CookingSessionPolicy` bez wyjątku dla ról).

## 10. Model danych (skrót; szczegóły w `docs/DATABASE.md`)

- `cooking_sessions` — `id`, `recipe_id` (FK, cascade), `host_id` (FK,
  cascade), `status` (pole sterujące: `active`), `revision`, `expires_at`,
  znaczniki. Unikalny `(host_id, recipe_id)`.
- `cooking_session_participants` — `(session_id, user_id)` klucz główny,
  `role` (CHECK = `helper`), `joined_at`. Gospodarz jest w `host_id`, nie tu.
- `cooking_session_steps` — `(session_id, step_id)` klucz główny, `done_by_id`
  (FK `ON DELETE SET NULL`), `done_at`.
- `cooking_session_invitations` — `id`, `session_id` (FK, cascade),
  `token_hash` (częściowy unikalny indeks), `status`
  (`pending|accepted|revoked`), `expires_at`, `accepted_by_id`
  (FK `SET NULL`), `responded_at`. Oczekujący i przyjęty link mają skrót,
  odwołany go nie ma (CHECK).
- Pola sterujące i poświadczenia (`status`, `role`, `token_hash`, klucze
  osób, `revision`, `expires_at`) **nigdy w `$fillable`** (wszystkie modele
  mają pusty `$fillable`; zapis przez nazwane akcje).
- Rollback (D-088): `down()` odmawia, gdy istnieje niewygasła sesja
  (decyzje ludzi w toku), wymuszenie `KUKING_ROLLBACK_KASUJE_WSPOLNE_GOTOWANIE=1`;
  na świeżej bazie i przy samych wygasłych sesjach przechodzi bez pytania.

## 11. Warstwy

`App\Domain\Recipes\Gotowanie\Wspolne\` — `ZalozWspolneGotowanie`,
`ZaprosDoWspolnegoGotowania`, `DolaczDoWspolnegoGotowania`,
`PostepWspolnegoGotowania`, `KoniecWspolnegoGotowania` (impl. kontraktu z
`Users`), `SprzatanieWspolnegoGotowania`. Kontroler cienki:
`WspolneGotowanieController`. Polityka: `CookingSessionPolicy`. Każdy zapis
pod `lockForUpdate` wiersza sesji; zamek pary kont przy dołączaniu.

## 12. Testy (z kontrolą ujemną)

Token cudzy / nieznany / wygasły / odwołany / zużyty; przepis prywatny
(link nie otwiera, tytuł się nie ujawnia); przepis „dla obserwujących” bez
obserwowania; blokada gospodarz↔pomocnik przed i po dołączeniu; zawieszone
konto (czyta, nie odhacza); nie-członek = 404 dla ekranu, odhaczenia i
stanu; pomocnik nie wyczyści, nie zaprosi, nie zakończy; wyścig dwóch
odhaczeń tego samego kroku (grupa `dwa-polaczenia`) i dwóch przyjęć
jednego linku; wygaśnięcie i sprzątanie; wymazanie konta; eksport; migracja
(schemat, CHECK-i, rollback odmawia/przechodzi).

## Pytania do właściciela (z wybranym bezpieczniejszym wariantem domyślnym)

1. **Ile osób?** Wybrano: gospodarz + 1 pomocnik (zgłoszenie mówi o „dwóch
   rolach”; mniej osób = prostsza blokada i mniejsze ryzyko linku
   przekazanego dalej). Czy dopuścić więcej pomocników? (Zmiana jednej liczby
   w konfiguracji + test blokady wielu osób.)
2. **Wersja przepisu.** Zgłoszenie mówi i „konkretna wersja”, i „aktualna
   wersja”. Wybrano **aktualną** (jak tryb gotowania i #2016; krok usunięty z
   przepisu wypada z sesji). Czy sesja ma zamrażać wersję z chwili startu?
3. **Przypisywanie kroków i składników** do osób — poza etapem 1 (dużo pojęć
   na ekranie 50+). Czy budować w etapie 2, czy zostawić samo „kto zrobił”?
4. **Powiadomienie gospodarza o dołączeniu pomocnika.** Wybrano: **brak**
   (gospodarz widzi to na ekranie sesji; brak nowego typu powiadomienia i
   kanału). Czy chcesz powiadomienie w serwisie?
5. **Polityka prywatności.** Wybrano drobną poprawkę bieżącej wersji (zdanie
   w tabeli kategorii: sesja do 24 h) bez nowego numeru wersji i bez paska.
   Czy to wystarczy prawnie, czy ma być wersja „istotna” (pasek, D-327)?
6. **Czas życia.** Stałe 24 h od założenia. Czy sesja ma się przedłużać
   przy aktywności (kosztem dłuższego przechowywania)?
7. **Sesja na prywatnym przepisie gospodarza.** Wybrano: dozwolone, ale
   pomocnik nie dołączy (przepis niewidoczny dla niego). Czy ma istnieć
   świadome „pokaż ten prywatny przepis pomocnikowi na czas sesji”? (Nie
   budujemy: to inna zasada widoczności niż `RecipePolicy`, wymaga osobnej
   decyzji.)
