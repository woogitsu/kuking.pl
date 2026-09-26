## D-270 — Publiczne API `/api/v1`: tokeny Sanctum, domyślnie zamknięte, jeden format błędu (25 września 2026)

**Data:** 25 września 2026 · **Decyzja właściciela** (uruchomić API pod aplikację mobilną) + zasady wykonania z etapu 1 · Status: **obowiązuje**

Zmienia D-014. Etap 1 to fundament: nie ma w nim jeszcze żadnej trasy
produkcyjnej, jest wszystko, na czym trasy staną.

### Co

1. **Laravel Sanctum, wyłącznie tokeny osobistego dostępu.** Nagłówek
   `Authorization: Bearer <id>|kuking_<sekret>`. Droga „SPA na ciasteczku
   sesji" jest zamknięta z trzech stron (`config/sanctum.php`): pusta lista
   domen stanowych, pusta lista strażników sesji (`guard => []` — zalogowana
   przeglądarka NIE przechodzi przez `auth:sanctum`) i brak trasy
   `/sanctum/csrf-cookie`. Grupa `api` nie ma sesji, ciasteczek ani CSRF.
2. **Tabela `personal_access_tokens` pod nasze zasady**, nie kopia z pakietu:
   UUID, klucz obcy do `users`, CHECK-i, `timestamptz`, skrót poza
   `$fillable` (`docs/DATABASE.md`). Tokeny giną razem z sesjami
   (`User::invalidateSessions()` → `invalidateApiTokens()`).
3. **Wersja w adresie: `/api/v1`** (`bootstrap/app.php`, `apiPrefix`).
   Zmiana niezgodna wstecz to `/api/v2` obok, nie przeróbka `v1` — aplikacji
   w telefonach nie da się zaktualizować w dniu wdrożenia.
4. **Wyłącznik `KUKING_API_ENABLED`, domyślnie `false`.** Przy zamkniętym API
   każdy adres pod `/api/*` odpowiada tym samym 404 co adres nieistniejący
   (`App\Http\Middleware\BramaApi`, pierwsza na liście priorytetów
   middleware'u — przed `auth:sanctum` i `throttle`). Zamknięte API nie
   zdradza swojej mapy i nie zjada liczników limitu.
5. **Jeden format błędu, po polsku** (`App\Http\Api\BledyApi`):
   `{"message": "…", "code": "…"}`, przy 422 dodatkowo `errors` z komunikatami
   z `lang/pl/validation.php`. 401, 403, 404, 405, 429 i 500 mają własne
   zdania mówiące, co zrobić. Treść wyjątku technicznego nie wychodzi NIGDY,
   także przy `APP_DEBUG=true`; wychodzi tylko `BladDlaCzlowieka` i zdanie
   z `Response::deny()` Policy.
6. **Dwa limity na każde żądanie**, liczby w `config/kuking.php`
   (`api.limity`): na adres IP (300/min) liczony w `BramaApi` PRZED
   sprawdzeniem tokenu — inaczej fałszywe tokeny odbijałyby się na 401
   niepoliczone — i na token (120/min) w limiterze `api`. Limity logowania
   (etap 2) dochodzą do tego osobno.

### Dlaczego tak, a nie inaczej

- **Token bez terminu.** Wylogowanie z telefonu co miesiąc to dla osoby 50+
  koniec korzystania z aplikacji. Ochroną jest odwołanie (lista urządzeń na
  WWW, etap 2) i kasowanie razem z sesjami, nie termin.
- **Rollback tabeli bez odmowy.** D-088 chroni decyzje człowieka; token jest
  poświadczeniem. Po `down()` + `migrate` każde urządzenie loguje się
  jeszcze raz — kierunek bezpieczny.
- **Limit na adres w middlewarze, nie w `throttle:`.** Sortowanie
  `Kernel::$middlewarePriority` stawia `ThrottleRequests` za
  `AuthenticatesRequests` i nie da się tego zmienić dla jednej grupy bez
  zmiany dla WWW.

### Czego to NIE zmienia

PWA zostaje drogą mobilną dla przeglądarki. AGENTS.md §3 dalej zabrania SPA,
GraphQL-a i osobnych serwisów — API to drugi adapter w tym samym monolicie.

**Zmiana wymaga:** decyzji właściciela (zamknięcie API) albo zmierzonego
problemu z tokenami bez terminu (np. wycieku), który lista urządzeń nie
rozwiązuje.

📄 `config/sanctum.php` · `config/kuking.php` (`api`) · `routes/api.php` ·
`app/Http/Middleware/BramaApi.php` · `app/Http/Api/BledyApi.php` ·
`app/Providers/ApiServiceProvider.php` · `app/Models/PersonalAccessToken.php` ·
`tests/Feature/Api/`
