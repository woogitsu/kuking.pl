## D-016 · Odwołanie składa się w produkcie, formularzem zamkniętym hasłem

**Data:** 6 września 2026 · Status: **obowiązuje** · issues #10, #65

> **Adnotacja z 20 września 2026 (audyt rejestru).** Ograniczenie cytowane w
> sekcji „Ile razy: raz od jednej decyzji" — `UNIQUE
> (appeals.moderation_action_id)` — zostało zdjęte tego samego dnia:
> `database/migrations/2026_09_07_800000_appeals_open_to_reporters.php:127-128`
> robi `DROP CONSTRAINT appeals_moderation_action_id_unique` i zakłada `UNIQUE
> (moderation_action_id, appellant)`. Od jednej decyzji moderacyjnej mogą więc
> powstać DWA odwołania — autora i zgłaszającego. Druga nieścisłość jest w
> tytule: zgłaszający składa odwołanie podpisanym linkiem
> (`routes/web.php:567-569`, middleware `signed`), a hasło jest bramką
> wyłącznie na ścieżce autora
> (`app/Http/Controllers/AppealController.php:116-130`). Reszta wpisu —
> karencja 24 h, `previous_status`, powrót do szkicu — ma pokrycie. Słowo
> `appellant` nie pada w tym dzienniku ani razu poza tą adnotacją.

Ścieżka odwołania (DSA art. 17 i 20) mogła pójść jedną z trzech dróg. Wybór
zapadł tak, a nie inaczej, i obie odrzucone drogi miały realne zalety.

### Co odrzucono

**Sam adres e-mail.** Nic nie kosztuje i działa dla każdego, także dla kogoś,
kto zapomniał hasła. Cena jest jednak taka, że odwołania nie ma w logu, nikt
nie wie, ile ich leży ani od kiedy, terminu z podręcznika (7 dni roboczych)
nie da się pilnować, a odpowiedź nie trafia do produktu. Przy audycie zostaje
zdanie „odpowiadamy na maile" i nic więcej.

**Formularz publiczny bez żadnej bramki.** Dostępny dla zablokowanych, ale
otwarty na oścież — czyli gotowy kanał do wpisywania czegokolwiek prosto
w kolejkę jedynego moderatora (D-012: zespół to 1–2 osoby).

### Co wybrano

Formularz **w produkcie**, a dla osób zablokowanych ten sam formularz **przed
logowaniem, zamknięty loginem i hasłem**. Sprawdzenie hasła nie loguje nikogo
i nie zdejmuje blokady — służy wyłącznie przypisaniu sprawy do konta.

**Cena, wprost:** kto zapomniał hasła, nie złoży odwołania tą drogą. Zostaje mu
„Nie pamiętam hasła" (działa też przy koncie zablokowanym) albo adres e-mail,
który zostaje jako droga zapasowa i jest wypisany na obu formularzach.
Odwołanie z e-maila moderator wprowadza ręcznie.

### Ile razy: raz od jednej decyzji

`UNIQUE (appeals.moderation_action_id)`. DSA art. 20 wymaga dostępu do
wewnętrznego rozpatrzenia skargi, nie nieskończonej liczby instancji, a bez
limitu jedna sprawa potrafi zająć jedynego moderatora na tydzień. Nowe
okoliczności idą adresem e-mail.

### Karencja zamiast „ktoś inny"

Playbook chce, żeby odwołanie oceniał ktoś inny niż pierwotny decydent. Przy
jednej osobie to jest nie do wyegzekwowania, więc system egzekwuje to, co da
się spełnić: **ten sam moderator nie podtrzyma własnej decyzji przez 24
godziny**. Cofnąć własną decyzję może natychmiast — karencja ma powstrzymać
odruchowe „podtrzymuję", a nie przyznanie się do pomyłki.

### Przywracanie treści wraca do stanu SPRZED ukrycia

Nie na sztywno do `published`. Stan sprzed decyzji zapisuje
`moderation_actions.previous_status` — w logu moderacji, nie w tabelach
z treścią (uzasadnienie: `docs/DATABASE.md`). Gdy stanu nie znamy, treść wraca
jako **szkic**: pomyłkę w tę stronę autor cofa jednym kliknięciem, pomyłki
w drugą — upublicznienia cudzego szkicu — nie cofnie nikt.

**Zmiana wymaga:** danych o tym, ile odwołań przepada na barierze hasła, albo
zmiany wielkości zespołu moderacji (wtedy karencja przestaje być potrzebna).

📄 `docs/MODERATION.md` · `docs/legal/MODERATION_PLAYBOOK.md` §3 ·
`config/kuking.php` → `kuking.moderation` · `app/Domain/Moderation/`
