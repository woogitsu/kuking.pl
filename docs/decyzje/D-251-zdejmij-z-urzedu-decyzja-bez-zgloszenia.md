## D-251 — „Zdejmij z urzędu”: decyzja bez zgłoszenia w tym samym rejestrze, z `report_id = NULL` (G31, 23 września 2026)

**Data:** 23 września 2026 · **Decyzja właściciela** (dodać akcję z urzędu),
projekt zapisu — decyzja zespołu · Status: **obowiązuje**

### Co było

Od #1446 (issue #932) moderator usuwa cudzą treść wyłącznie z panelu, a panel
usuwał wyłącznie rozstrzygnięciem zgłoszenia. Własnego zgłoszenia moderator
nie rozstrzyga (D-244). Spamu, którego nikt nie zgłosił, nie dało się więc
zdjąć wcale — przy jednoosobowej moderacji nawet przez „zgłoszę sam i poproszę
drugą osobę”.

### Decyzja

1. **Akcja „Zdejmij z urzędu”** dla wpisu, przepisu i komentarza: przycisk
   przy treści → ekran `/admin/z-urzedu/{typ}/{id}` → decyzja `remove`.
   Podstawa z zamkniętej listy `PodstawaDecyzji` i uzasadnienie dla autora
   są obowiązkowe.
2. **Zapis w tym samym rejestrze:** wiersz `moderation_actions` z
   `report_id = NULL`. **Bez „zgłoszenia z urzędu”** i bez nowej kolumny
   źródła. Powody:
   - decyzja z urzędu nie jest „własną sprawą” z D-244 — nie ma zgłaszającego,
     któremu moderator mógłby rozstrzygnąć na korzyść, ani nikogo, komu
     DSA art. 16 każe odpisać. Sztuczne zgłoszenie wstawiłoby moderatora
     w rolę zgłaszającego, czyli dokładnie w sytuację, której
     `ReportPolicy::decide()` zabrania, i wymagałoby wyjątku od tej reguły;
   - fikcyjny wiersz w `reports` zasiliłby kolejkę, liczniki, statystyki
     zgłoszeń i terminy z art. 16 czymś, czego nikt nie zgłosił;
   - schemat był na to gotowy: `report_id` jest `NULL`-owalne, a
     `UzasadnienieDecyzji::skadSprawa()` od początku miało gałąź „Nikt tego
     nie zgłosił — sprawę znaleźliśmy sami” (DSA art. 17 ust. 3 lit. b).
     Pusty `report_id` przy decyzji odwoływalnej znaczy odtąd decyzję z urzędu;
     przy `unhide` — przywrócenie, jak dotąd.
3. **Kto:** czynny moderator albo administrator z potwierdzonym 2FA
   (`removeExOfficio` → `UserPolicy::takeDownContentOf()`), i tylko wobec
   konta o **niższej** roli. To świadomie ostrzej niż D-244 pkt 3 („ocena
   treści nie zależy od roli autora”). Przy zgłoszeniu sprawę wnosi ktoś
   drugi. Z urzędu jedna osoba jest naraz tą, która sprawę znalazła, i tą,
   która ją rozstrzyga. Treść równej albo wyższej rangi idzie zwykłym „Zgłoś”.
   Ta sama reguła wyklucza zdejmowanie własnej treści.
4. **Odwołanie** — ta sama ścieżka co od decyzji ze zgłoszenia (`FileAppeal`,
   `ResolveAppeal`); „cofam” przywraca treść.
5. **Otwarte zgłoszenie wygrywa:** z urzędu nie zdejmuje się treści, przy
   której czeka zgłoszenie. Decyzja zapada w kolejce, a zgłaszający dostaje
   odpowiedź.
6. **Zdjęcie z urzędu działa tym samym mechanizmem co „Usuń” ze
   zgłoszenia** — miękkie usunięcie (`$cel->delete()`), także komentarza
   z odpowiedziami; „cofam” po odwołaniu przywraca je tym samym
   `RestoreContent` co decyzję ze zgłoszenia. Moderacja nie zostawia napisu
   „Komentarz usunięty.”. „Usuń” działa jak dotąd — decyzja właściciela
   24.09.2026.
7. **Zakres: tylko treść widoczna dla innych.** Wpis i przepis opublikowane,
   publiczne albo dla obserwujących; komentarz opublikowany pod taką treścią
   (`ZdejmijZUrzedu::widocznaDlaInnych()`). Szkic, treść prywatna i ukryta
   dają **404** już na ekranie (`ZUrzeduController::cel()`), także przy
   wysyłce formularza — moderator nie ogląda prywatnych treści po samym UUID
   i nie dowiaduje się nawet, że istnieją. Powody:
   - „z urzędu” znaczy „znaleźliśmy, przeglądając serwis” — a treści, której
     nie widzi nikt poza autorem, przy przeglądzie serwisu znaleźć nie
     można. Gdyby ekran ją pokazywał, UUID w adresie stawałby się drogą do
     cudzego szkicu i prywatnych notatek (AGENTS.md §7: UUID to nie
     autoryzacja);
   - brak ogólnego obowiązku monitorowania (DSA art. 8) — nie ma powodu
     przeglądać treści prywatnych „na wszelki wypadek”.
   Treść prywatna, która mimo to jest nielegalna, trafia do moderacji innymi
   drogami, każdą z własnym śladem: formularzem zgłoszenia nielegalnej
   treści (DSA art. 16 — przyjmuje wklejony adres), nakazem organu (art. 9,
   przez właściciela serwisu) albo kolejką automatu (D-052; lokalne wzorce
   spamu sprawdzają także treść niepubliczną, D-241). Tam decyzja zapada
   przy zgłoszeniu — `decide()` widoczności nie ogranicza.
8. **Treść już zdjęta:** ekran od razu mówi „już zdjęta” zamiast formularza
   (także komentarz, który autor sam usunął i po którym stoi napis
   „Komentarz usunięty.” z `DeleteComment`), przycisk przy treści się nie
   rysuje, a drugie
   wysłanie (druga karta) kończy się błędem bez drugiej decyzji — blokada
   wiersza w `ZdejmijZUrzedu`. Treść miękko usunięta → 404.
9. **Przywrócenie treści to jedna transakcja z blokadą wiersza celu**
   (przegląd G31, `RestoreContent`). Decyzja `unhide` i zapis treści
   przechodzą razem albo wcale; stan czytany pod blokadą, więc drugie
   równoległe przywrócenie (dwie karty, „Przywróć” i „cofam” naraz) widzi
   „już widoczna” i nie zapisuje drugiej decyzji ani powiadomienia.
10. **„Najnowsza decyzja” = `created_at`, potem `id`** (status sprzed
    ukrycia w `RestoreContent`). `created_at` ma pełne sekundy
    (`timestamptz(0)`), więc remis w jednej sekundzie rozstrzyga `id`:
    UUIDv7 z `HasUuids` (milisekundy + licznik rosnący w procesie).
    Kolumny sekwencyjnej w `moderation_actions` nie ma. **Ryzyko, które
    zostaje:** wiersz wstawiony z pominięciem modelu (surowy SQL, ręczna
    naprawa) dostaje `id` z `gen_random_uuid()` — v4, losowe — i przy
    remisie sekundy kolejność byłaby przypadkowa. Kod aplikacji tak nie
    wstawia; ręczne wstawki do rejestru i tak wymagają zgody (AGENTS.md §6).

### Czego ta decyzja nie robi

- **„Ugotowałem” nie ma „Zdejmij z urzędu”.** `cooked_events` nie ma soft
  delete: `remove` kasuje wiersz na stałe razem z komentarzami, a „cofam”
  nie ma czego przywrócić. Ten sam powód, dla którego `media` nie ma
  `remove`. Ta sama luka istnieje dziś przy decyzji `remove` **ze
  zgłoszenia** na wykonaniu (komentarz w `ModerationAction::DOZWOLONE`
  twierdził „soft delete” — poprawiony). Domknięcie wymaga soft delete
  `cooked_events` — osobna praca.
- Nie dodaje ukrywania ani ostrzeżeń z urzędu — tylko zdjęcie.

📄 `app/Domain/Moderation/Actions/ZdejmijZUrzedu.php`,
`app/Http/Controllers/Admin/ZUrzeduController.php`,
`app/Policies/UserPolicy.php` (`takeDownContentOf`),
`app/Domain/Moderation/Actions/RestoreContent.php`,
`tests/Feature/ZdejmijZUrzeduTest.php`,
`tests/Feature/ZdejmijZUrzeduPoPrzegladzieTest.php`,
`tests/Feature/PrzywrocenieWTransakcjiTest.php`

### Wycofanie

Odwrócić commit. Bez migracji — schemat się nie zmienia.
Decyzje z urzędu już zapisane zostają w rejestrze jako zwykłe `remove`
z pustym `report_id`.
