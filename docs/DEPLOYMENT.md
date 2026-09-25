# Deployment: GitHub + Railway

## Topologia startowa

```text
Kuking
├── web
└── postgres
```

Queue MVP: database.

Po wzroście:
```text
Kuking
├── web
├── worker
├── postgres
└── cron
```

Zdjęcia docelowo: Cloudflare R2.

## GitHub

- `main` → production;
- `staging` → staging;
- feature branches → PR.

Włączyć Railway **Wait for CI**.

## Railway IaC

Nowy kierunek Railway to:
`.railway/railway.ts`

Nie projektować nowego repo wokół starego `railway.json` / `railway.toml`, ponieważ Config as Code jest oznaczone jako deprecated.

## Production env

- `APP_ENV=production`;
- `APP_DEBUG=false`;
- sekrety tylko w platformie;
- osobne staging secrets;
- HTTPS;
- healthcheck;
- backup.

## Dziennik serwera i polityka prywatności

Produkcja loguje na `stderr` (`.railway/railway.ts` → `LOG_CHANNEL`,
`LOG_STDERR_FORMATTER`). Railway przechwytuje `stdout`/`stderr` do swojego
narzędzia dzienników, więc wpisy **nie znikają** razem z instancją — żyją
tyle, ile pozwala plan konta Railway.

Polityka prywatności (§2, wiersz „Wykrywanie i naprawa błędów
technicznych”, oraz tabela dostawców w §3) opisuje ten przepływ.

**Stan na 24.09.2026 (decyzja właściciela, #994):** plan Railway **Hobby**,
w polityce „**do 7 dni**”. Liczby wg dokumentacji Railway (retencja logów):
Free 3 dni, Hobby 7, Pro 30, Enterprise do 90. Przy publicznym starcie
produkcji właściciel przechodzi na **Pro**.

Checklista przejścia na Pro (zrób wszystko w jednym PR-ze):

- [ ] `resources/legal/polityka-prywatnosci.md`, wiersz „Wykrywanie i naprawa
      błędów technicznych”: **„do 7 dni” → „do 30 dni”**;
- [ ] podbij wersję polityki: data w nagłówku („opisuje stan serwisu na …”)
      **i** `config/kuking.php` → `zgody.wersja_polityki` — ta sama data
      (pilnuje `PolitykaOpisujeRetencjeDziennikaSerweraTest`);
- [ ] `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.18: plan Pro, 30 dni;
- [ ] zdanie dla ludzi w `CHANGELOG.md`;
- [ ] kontrola dodatnia w `scripts/kontrole-negatywne-alfa08.py`
      (`POLITYKA_DZIENNIK_DNI`) — tekst mutacji musi odpowiadać nowemu zdaniu.

**Kiedy trzeba zmienić tekst polityki** (razem z datą stanu w jej nagłówku
i `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.18):

- zmiana planu Railway albo ustawień przechowywania dzienników
  (przejście na Pro — checklista wyżej);
- ustawienie `LOG_BLAD_WEBHOOK_URL` (Slack/Discord) albo eksport logów poza
  Railway — to nowy odbiorca zapisu błędu i trafia do tabeli dostawców;
- zmiana `LOG_CHANNEL` na inny odbiornik (plik, zewnętrzne narzędzie
  do zbierania błędów) — nowy odbiorca trafia też do tabeli dostawców.

Powrotu do zdania, które wiązało retencję z życiem instancji, pilnuje
`PolitykaOpisujeRetencjeDziennikaSerweraTest`.

## Migrations

Preferowany rollout:
```text
build
→ CI passed
→ deploy
→ migrate --force
→ healthcheck
```

Ryzykowne zmiany:
`expand → migrate/backfill → switch → contract`.

## Konto gospodarza (`KUKING_HOST_USER_ID`, #1089)

Mechanizmy społeczności (auto-obserwowanie przy rejestracji, alert pierwszego
wpisu, wykluczenia w analityce) rozpoznają gospodarza po stabilnym UUID konta,
nie po nazwie profilu. Nazwę da się zmienić, a zwolnioną może zająć ktoś inny.

Przed wdrożeniem kodu z #1089:

1. Odczytaj UUID aktualnego konta gospodarza zapytaniem **tylko do odczytu**
   (np. w konsoli bazy z rolą bez prawa zapisu albo w transakcji
   `BEGIN READ ONLY; … ROLLBACK;`):

   ```sql
   SELECT u.id, p.username
   FROM users u JOIN profiles p ON p.user_id = u.id
   WHERE lower(p.username) = lower('<obecna nazwa gospodarza>');
   ```

   Oczekiwany wynik: dokładnie jeden wiersz. Zero albo więcej wierszy —
   zatrzymaj się i wyjaśnij, zanim cokolwiek ustawisz.
2. Wpisz wartość `id` do zmiennej `KUKING_HOST_USER_ID` w zmiennych serwisu
   (sekrety tylko w platformie, nie w repozytorium).
3. Wdróż kod. Dopiero potem gospodarz może zmienić nazwę profilu.

Zachowanie konfiguracji:

- **`KUKING_HOST_USER_ID` pusty** — działa przejściowy fallback po nazwie
  z `KUKING_HOST_USERNAME` (jak przed #1089). Jest bezpieczny tylko dopóki
  nikt nie zmienia nazwy konta gospodarza.
- **`KUKING_HOST_USER_ID` ustawiony poprawnie** — nazwa profilu nie ma
  znaczenia; `KUKING_HOST_USERNAME` nie jest czytane przez te mechanizmy.
- **`KUKING_HOST_USER_ID` ustawiony, ale błędny** (nie-UUID albo nieistniejące
  konto) — serwis celowo działa **bez gospodarza** i nie cofa się do nazwy.
  Nowi użytkownicy nie obserwują nikogo automatycznie, alert pierwszego wpisu
  nie trafia do nikogo. Sprawdź wartość i popraw zmienną.

Migracja `2026_09_21_100900_create_first_post_events` (już wdrożona) nie jest
zmieniana: jej backfill wykonał się raz, po nazwie gospodarza z dnia
wdrożenia. Jej `down()` nadal odtwarza dane po `KUKING_HOST_USERNAME`; jeżeli
nazwa gospodarza się zmieni, rollback tej migracji może świadomie odmówić
(D-088) — to bezpieczny kierunek, dane zostają.

## Rollback

Kod:
- poprzedni commit/deployment.

Baza:
- migracje mają być backward-compatible;
- rollback kodu nie naprawia automatycznie destrukcyjnej migracji.

## Staging

Osobna:
- baza;
- storage;
- secrets.

Nie kopiować produkcyjnych danych użytkowników bez potrzeby i podstawy.
