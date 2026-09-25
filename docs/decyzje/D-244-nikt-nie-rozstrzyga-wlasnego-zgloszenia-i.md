## D-244 — Nikt nie rozstrzyga własnego zgłoszenia i nie karze konta równej lub wyższej roli (#1408, 23 września 2026)

**Data:** 23 września 2026 · **Decyzja właściciela** (triaż nocny, P1) ·
Status: **obowiązuje**

### Co było

`ModerationController::decide()` pytał wyłącznie `authorize('moderate', User::class)`,
czyli „czy aktor jest moderatorem". Nie porównywał `reports.reporter_id`
z aktorem ani roli osoby, którą decyzja karze (`ModeratedContent::osoba()`),
a `applyAction()` wołał `suspend()` / `ban()` bez osobnej reguły. Jeden
moderator mógł więc zgłosić administratora, sam to zgłoszenie rozstrzygnąć
i go zbanować (ban unieważnia sesje). Przy kilku administratorach i w połączeniu
z #1016 była to droga do odcięcia od panelu wszystkich, którzy rozpatrują
odwołania.

### Reguły

1. **Nikt nie rozstrzyga sprawy, którą sam wniósł** — `ReportPolicy::decide()`.
   Dotyczy każdej decyzji, także „Bez działania" (ona też zamyka sprawę
   i odpisuje zgłaszającemu). Dotyczy także administratora. Zgłoszenie prawne
   bez konta (`reporter_id IS NULL`) rozstrzyga każdy moderator.
   **Uzupełnienie (#1408, 24 września 2026):** stroną sprawy jest też ten,
   KOGO ona dotyczy. Zgłoszenia własnej treści albo własnego profilu nie
   rozstrzyga ani moderator, ani administrator — reguła rangi blokowała
   tylko karę na sobie, a „Bez działania” pozwalało oddalić skargę na siebie.
   Autora wyznacza `ModeratedContent::osoba()`, cel szukany razem z miękko
   usuniętymi.
2. **Zawieszenie i blokada konta tylko wobec niższej roli** —
   `UserPolicy::sanctionAccount()`. Moderator karze zwykłe konta,
   administrator także moderatorów. **Konta administratora nie zawiesza ani
   nie blokuje nikt z panelu** (równa ranga); sprawa administratora idzie do
   właściciela serwisu, a rolę odbiera `kuking:nadaj-role`, która pilnuje
   ostatniego czynnego administratora (#1016). Ta sama reguła rangi wyklucza
   karanie samego siebie.
3. **Ocena treści nie zależy od roli autora.** Ukrycie, usunięcie
   i ostrzeżenie wpisu administratora działają jak przy każdym innym.

Obie reguły są sprawdzane w `decide()` **pod blokadą wiersza zgłoszenia,
przed `ModerationAction::create()`**. Odmowa wycofuje transakcję: zgłoszenie
zostaje otwarte, nie powstaje decyzja, powiadomienie ani wpis
`moderation.decided`. Wstępne sprawdzenie „własnej sprawy" przed transakcją
służy tylko komunikatowi. Reguły żyją w politykach, więc przyszła droga
wykonująca sankcję (endpoint, zadanie, komenda) pyta o te same ability.

### Czego ta zmiana nie robi

Nie rozwiązuje #1016 (ochrona ostatniego czynnego administratora przy
zmianie statusu i współbieżności) — zamyka tylko drogę przez panel moderacji,
która tamten problem czyniła osiągalnym dla moderatora. Nie ukrywa formularza
decyzji przy własnym zgłoszeniu: formularz zostaje, a serwer odmawia
z komunikatem, co zrobić.

📄 `app/Policies/ReportPolicy.php`, `app/Policies/UserPolicy.php`,
`app/Http/Controllers/Admin/ModerationController.php`,
`tests/Feature/ModeratorNieJestSedziaWeWlasnejSprawieTest.php`
