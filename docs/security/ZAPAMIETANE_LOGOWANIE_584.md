# Zapamiętane logowanie po wylogowaniu innych urządzeń — #584

Stan: przygotowane lokalnie 15 września 2026, podstawa main `49a7bcf`.
Nie jest to potwierdzenie CI, scalenia ani wdrożenia.

`User::invalidateSessions()` usuwał sesje database i oczekujące linki, ale
zostawiał ważny `remember_token`. Klient ze starym zaszyfrowanym ciasteczkiem
mógł odtworzyć sesję po naciśnięciu „Wyloguj inne urządzenia”. Poprawka rotuje
token konta przed sprawdzaniem sterownika sesji. Reset hasła używa tej samej
rotacji zamiast osobnej kopii. Nie zmienia się hasło przy samym wylogowaniu.

Wyjątek identyfikatora sesji chroni aktywną sesję bieżącej przeglądarki.
Nie chroni jej starego ciasteczka zapamiętania: token jest wspólny dla konta.
Po utracie bieżącej sesji potrzebne jest ponowne logowanie. Nie wystawiamy
nowego ciasteczka przez ukryte `Auth::login()`, nie emitujemy dodatkowego
zdarzenia logowania i nie dotykamy guarda osoby wykonującej moderację.

## Wykonane kontrole

- `ZapamietaneLogowanieUniewaznienieTest`: 6 testów, 178 asercji. Każde
  żądanie przechodzi kernel Laravel w osobnym procesie PHP, z szyfrowaniem
  cookies, database sessions i włączonym CSRF. Nie ma `actingAs`, ręcznego
  wstawiania payloadu sesji ani wyłączania middleware w pomiarze HTTP.
- Dodatnia próba starego recaller przed wylogowaniem: dostęp 200. Po akcji:
  klient ze starym pełnym zestawem cookies i klient z samym remember dostają
  przekierowanie na logowanie; sesja A i konto C pozostają dostępne.
- Brak CSRF daje 419; złe hasło nie zmienia tokena i nie odbiera dostępu B.
- Zmiana i reset hasła odrzucają stary recaller; sprawdzany jest również nowy
  hash hasła. Po zmianie działa logowanie nowym hasłem.
- Ban, zawieszenie i zgłoszenie usunięcia odrzucają stare remembered cookie
  również **po przywróceniu konta**, więc sam status nie maskuje regresji.
- Model pobrany tylko z `id`, sterownik `array`: rotacja oraz usunięcie linku
  działają; status i hasło nie zmieniają się. Usuwanie aktywnych sesji nadal
  dotyczy wyłącznie sterownika `database`, jak przed poprawką.
- Razem z SecuritySettingsTest, PasswordResetSessionRotationTest,
  AccountStatusTest i TrzyRozstrzygnieciaWarstwTest: **40 testów / 329 asercji**.
- Pint: cztery zmienione pliki PHP; PHPStan: zero błędów.

Fizyczna kontrola ujemna usuwała wyłącznie rotację w rzeczywistym
`app/Models/User.php`. Wynik: PASS → FAIL (200 zamiast przekierowania) → PASS.
Kopia poza repo, identyczne MD5 i mtime po przywróceniu:
[`evidence/remember584/negative.json`](evidence/remember584/negative.json).
Log dowodu nie zawiera cookies, CSRF ani haseł.

## Granice

To pomiar kernela HTTP w niezależnych procesach, nie test przeglądarki ani
warstwy TCP/proxy. Jar odwzorowuje przyjmowanie i wygaszanie cookies dla
jednego hosta; nie mierzy polityk cross-site/SameSite. Brak pomiaru wyścigu
równoległego logowania z wylogowaniem. Turnstile jest nieskonfigurowany
lokalnie, HIBP ma atrapę transportu, a Vite nie dołącza assetów w tym teście.
Autoryzacja, CSRF, hash i token pamiętania pozostają prawdziwe.

Izolowana baza `kuking_584_tests`, PostgreSQL 18 na 127.0.0.1:55439.
Początkowy reset był odrzucany wskutek odziedziczonej strefy Europe/Warsaw
nowej bazy (token wyglądał na starszy o 7200 sekund). Ustawiono UTC wyłącznie
dla tej bazy i powtórzono testy. Nie zmieniono innych baz ani produkcji.

Rollback kodu przywróci lukę dla przyszłych operacji wylogowania; już
odwołane tokeny nie odzyskają ważności. Nie ma migracji ani nowej zależności.
Pełny hook, niezależne review, CI i odbiór wdrożenia pozostają do wykonania.

## Włączenie 2FA — #930 (D-245)

Ta sama ścieżka obejmuje teraz potwierdzenie 2FA: po dobrym haśle i dobrym
kodzie `TwoFactorSettingsController::confirm()` woła
`invalidateSessions()` z wyjątkiem bieżącej sesji. Trzy testy w tym samym
pliku (osobne procesy, database sessions, CSRF): stary remembered cookie
i pełna sesja sprzed włączenia dostają przekierowanie na logowanie, bieżąca
sesja działa; zły kod nie zmienia `remember_token`; stary recaller
moderatora nie wchodzi do `/admin/zgloszenia` (przed poprawką 403 → 200).
Kontrola ujemna (usunięte wywołanie w kontrolerze): dwa testy oblewają
na 200 zamiast 302, po przywróceniu przechodzą.

### Wyłączenie 2FA i dowód kodu w sesji — #930 (dokończenie)

- **Wyłączenie 2FA** (`TwoFactorSettingsController::disable()`, po dobrym
  haśle) woła `invalidateSessions()` z wyjątkiem bieżącej sesji — inne
  przeglądarki i recallery logują się od nowa. Złe hasło niczego nie
  odwołuje. Testy w tym samym pliku; kontrola ujemna (bez wywołania):
  sesja B zostaje, 200 zamiast 302.
- **`moderator.2fa` sprawdza dowód kodu w sesji**, nie tylko stan konta.
  Klucz `dwuetapowa.dowod` (HMAC z id konta i `two_factor_confirmed_at`)
  zapisują wyłącznie `TwoFactorChallengeController::store()` i
  `confirm()`. Sesja zalogowana bez kodu (sprzed włączenia 2FA, z recallera,
  przy sterowniku, którego `invalidateSessions()` nie czyści) dostaje 403
  z ekranem „Zaloguj się ponownie, podając kod”. Wyłączenie i ponowne
  włączenie 2FA unieważnia dowody sprzed niego. Testy:
  `PanelWymagaKoduWSesjiTest` (role moderator i admin); kontrola ujemna
  (bez warunku w middleware): dwa testy 200 zamiast 403.
- Skutek wdrożenia: moderatorzy zalogowani przed wdrożeniem raz zobaczą
  ekran z prośbą o ponowne logowanie z kodem.
- `TestCase::actingAs()` dokłada dowód dla kont z potwierdzoną 2FA (udaje
  pełne logowanie); sesję bez dowodu daje gołe `be()`.
