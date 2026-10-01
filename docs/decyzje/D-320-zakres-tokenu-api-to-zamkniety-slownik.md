## D-320 — Zakres tokenu API to zamknięty słownik, nigdy wildcard `*` (#1928, doprecyzowanie D-270, 26 września 2026)

**Data:** 26 września 2026 · Znalezisko bezpieczeństwa (#1928) · Status: **obowiązuje**

### Co

`User::createToken()` wydawał domyślnie pakietowy wildcard Sanctum —
`array $abilities = ['*']`. Etap 1 D-270 nie miał jeszcze ani jednej trasy
produkcyjnej, więc problem był na razie teoretyczny, ale wildcard sam się
nie naprawia: pierwsza trasa dodana bez świadomej migracji tokenów
odziedziczyłaby uprawnienia każdego telefonu, który kiedykolwiek się
zalogował.

1. **Zamknięty słownik zakresów**, `App\Http\Api\ZakresyTokenu`: `profil:czytaj`,
   `tresc:czytaj`, `tresc:pisz`. `ZakresyTokenu::waliduj()` odrzuca każdy
   string spoza tej listy — w tym `*` — nawet gdy wołający poda abilities
   jawnie, nie tylko przy domyślnym wywołaniu.
2. **Domyślny zakres to CAŁY dzisiejszy słownik, wypisany jawnie**
   (`User::DOMYSLNE_UPRAWNIENIA_API`), nie „nic" i nie `*`. Jedyny dziś klient
   API to nasza własna aplikacja mobilna, więc zawężanie zakresu domyślnego
   poniżej całego słownika byłoby teatrem bez realnego zysku — ale lista jest
   jawna, więc dopisanie NOWEGO zakresu do słownika w przyszłości nie
   rozszerza automatycznie uprawnień tokenów wydanych wcześniej (leżą
   w bazie jako zapisana lista) ani tokenów wydanych tą metodą po dopisaniu
   zakresu do słownika, dopóki ktoś świadomie nie doda go też do stałej
   domyślnej.
3. **Middleware `ability` / `abilities`** (`Laravel\Sanctum\Http\Middleware\CheckAbilities`
   / `CheckForAnyAbility`) zarejestrowany jako alias w `bootstrap/app.php` —
   Sanctum w Laravel 11+ nie robi tego sam. Trasa mutująca w `routes/api.php`
   ma mieć `ability:<zakres>` OBOK `auth:sanctum`, nie zamiast niego.

### Dlaczego tak, a nie inaczej

- **Nie osobny „poziom zaufania" per token teraz.** Różnicowanie zakresów
  między telefonami tej samej osoby (np. „tylko odczyt" dla urządzenia
  gościa) nie ma dziś interfejsu, który by to ustawiał — dodanie tego bez
  ekranu byłoby funkcją bez konsumenta (AGENTS.md §1). Zakres chroni dziś
  przed przyszłymi trasami, nie przed różnicowaniem urządzeń.
- **Żadnych wydanych tokenów do migracji.** `KUKING_API_ENABLED` jest
  domyślnie zamknięte (D-270), `routes/api.php` nie ma jeszcze ani jednej
  trasy `Route::`, a tabela `personal_access_tokens` powstała 25 września
  2026 — nie ma więc na dziś ani jednego wydanego tokenu z `*`, którego
  trzeba by wygaszać albo migrować. Gdyby taki token kiedyś się znalazł (np.
  po odtworzeniu ze starszej kopii bazy), odwołanie należy do właściciela
  (`invalidateApiTokens()` na koncie) — nie robimy tego tutaj z automatu.
- **Strażnik CI dla nowych tras bez `ability`** z pierwotnego zgłoszenia
  #1928 zostaje odłożony: dziś nie ma ani jednej trasy produkcyjnej do
  pilnowania, a pisanie skanera pod pustą listę tras jest zgadywaniem
  kształtu przyszłego kodu. Wraca razem z pierwszą prawdziwą trasą
  mutującą.

**Zmiana wymaga:** decyzji właściciela o różnicowaniu zakresów per
urządzenie (potrzebny ekran) albo znalezienia w bazie tokenu z zakresem
spoza słownika (dziś niemożliwe — `waliduj()` odrzuca go przy wydaniu).

📄 `app/Http/Api/ZakresyTokenu.php` · `app/Models/User.php`
(`createToken()`, `DOMYSLNE_UPRAWNIENIA_API`) · `bootstrap/app.php`
(aliasy `ability`/`abilities`) · `routes/api.php` ·
`tests/Feature/Api/ZakresyTokenuTest.php`
