## D-039 · Odwołanie zamyka administrator, nie rola pierwszej linii

**Data:** 8 września 2026 · **Decyzja właściciela** · Status: **obowiązuje,
wdrożona 8 września**

DSA art. 20 daje prawo do odwołania od decyzji moderacyjnej. Do 8 września
odwołanie zamykał każdy moderator — jedyną barierą było 24 godziny karencji,
zanim ten sam moderator PODTRZYMA własną decyzję, a ta nie przeszkadzała ani
cofnąć własnej od razu, ani zamknąć sprawy dowolnemu INNEMU moderatorowi bez
żadnego opóźnienia. Właściciel rozstrzygnął: **rozdzielić role** — decyzję
o odwołaniu przyjmuje wyłącznie konto z rolą `admin`
(`UserPolicy::resolveAppeals()`). Kolejkę odwołań widzi dalej każdy moderator;
formularz odpowiedzi widzi tylko administrator.

**Dlaczego karencja to za mało.** Doba nie robi z tej samej osoby drugiej
instancji. Człowiek, którego treść usunięto, ma dostać spojrzenie kogoś
innego, a nie tego samego spojrzenia po przespanej nocy.

**CO TA ZMIANA NAPRAWDĘ ROBI — bo pierwsza wersja tego wpisu mówiła za
dużo.** To jest bramka na ROLĘ, nie na osobę. Administrator przechodzi też
przez `moderate()`, więc jeden człowiek z tą rolą dalej może wydać decyzję
i zamknąć odwołanie od niej samej; powstrzymuje go wtedy wyłącznie karencja
`ResolveAppeal::sprawdzKarencje()` i tylko przy PODTRZYMANIU. Wartość
pojawia się przy DRUGIEJ osobie w zespole: moderator bez roli administratora
przestaje móc zamknąć sprawę, którą sam rozstrzygał.

**WARUNEK WDROŻENIA BYŁ REALNY I ZOSTAŁ SPEŁNIONY.** `User::promoteTo()`
i `User::isAdmin()` nie miały w tym repozytorium **ani jednego wywołania**,
a żaden seeder nie nadawał roli `admin`. Samo zawężenie Policy zamknęłoby
odwołania na głucho: nie byłoby kto ich rozstrzygnąć, a termin z DSA biegłby
dalej. Dlatego razem z zawężeniem weszła komenda
`php artisan kuking:nadaj-role <login> admin` — z powłoki produkcyjnej, bez
ekranu w produkcie, bo ekran znaczyłby, że przejęcie jednego konta
administratora wystarcza, żeby zrobić administratorów z kolejnych. Komenda
odmawia odebrania roli OSTATNIEMU czynnemu administratorowi i zapisuje każdą
zmianę w `audit_log` jako `user.role_changed`.

**PIERWSZA CZYNNOŚĆ PO WDROŻENIU:** nadać sobie tę rolę na produkcji. Do
tego czasu nie ma tam nikogo, kto może zamknąć odwołanie — otwartych spraw
nie było w chwili wdrożenia, więc okno jest bezpieczne, ale tylko dopóki
nikt się nie odwoła.

**Zmiana wymaga:** drugiego moderatora, przy którym rozdzielenie ról da się
zrobić bez jednoosobowego wąskiego gardła.

📄 `app/Policies/UserPolicy.php` (`resolveAppeals`) ·
`app/Http/Controllers/Admin/AppealController.php` ·
`app/Console/Commands/NadajRole.php` · `tests/Feature/NadanieRoliTest.php` ·
`docs/MODERATION.md` · DSA art. 20
