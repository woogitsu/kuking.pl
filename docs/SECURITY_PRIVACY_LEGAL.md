# Security, privacy, DSA, GDPR

To blueprint techniczny, nie opinia prawna.

## Security baseline

### Konto
- frameworkowy password hashing;
- session rotation;
- reset tokens;
- email verification;
- rate limits;
- MFA obowiązkowe dla adminów.

### Admin
- role;
- least privilege;
- audit;
- brak współdzielonych kont.

### Web
- CSRF;
- escaping;
- secure cookies;
- HTTPS;
- HSTS po stabilizacji;
- dependency scanning;
- CSP jako production hardening.

### Upload
Patrz `MEDIA_PIPELINE.md`.

## GDPR

Projektujemy od początku:
- dostęp;
- sprostowanie;
- usunięcie;
- eksport/przenoszenie;
- privacy settings;
- czytelne informacje o przetwarzaniu.

Dlatego export i account deletion są częścią MVP.

**Sprostowanie adresu e-mail (art. 16)** ma od issue #195 własną drogę:
`/ustawienia/e-mail`. Do 9 września 2026 adres konta nie był widoczny nigdzie
w interfejsie poza ekranem „Potwierdź e-mail" i paczką RODO — czyli prawo
dostępu (art. 15) było spełnione tylko przez pobranie eksportu, a prawa do
sprostowania nie dało się w ogóle wykonać. Zmiana idzie pełną drogą:
obecne hasło → list z podpisanym odnośnikiem na NOWY adres → potwierdzenie,
plus ostrzeżenie na stary adres i wpis w `audit_log`. Do potwierdzenia
obowiązuje stary adres — szczegóły przy tabeli `pending_email_changes`
w `DATABASE.md`.

## Data minimization

Nie zbierać bez potrzeby:
- telefonu;
- dokładnego adresu;
- GPS;
- płci;
- pełnej daty urodzenia;
- danych zdrowotnych.

Urodziny (issue #1755, decyzja właściciela z 25.09.2026): zbieramy wyłącznie
**dzień i miesiąc, bez roku**, opcjonalnie, z przyciskiem „Usuń datę”. Cel:
życzenia od gospodarza. Data nie jest widoczna dla innych, poza przypomnieniem
obserwującym, które osoba musi sama włączyć. Roku nie bierzemy ani do życzeń,
ani do weryfikacji wieku (`docs/legal/COMPLIANCE.md` §4).

## DSA

Od początku:
- łatwe zgłaszanie;
- zapisywanie decyzji;
- powody ograniczeń;
- możliwość odwołania;
- oznaczenie reklam;
- brak dark patterns;
- ochrona nieletnich.

## Minors

Najbezpieczniej rozpocząć od polityki wieku ustalonej przed launch. Brak DM w MVP redukuje ryzyko.

Nie targetować reklam do nieletnich.

## Cookies / analytics

Minimalizować tracking. Nie wysyłać treści komentarzy/przepisów do analytics.

## Retention

Każda kategoria danych musi mieć:
- cel;
- okres;
- sposób usunięcia;
- relację z backupami.
