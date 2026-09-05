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

## Data minimization

Nie zbierać bez potrzeby:
- telefonu;
- dokładnego adresu;
- GPS;
- płci;
- pełnej daty urodzenia;
- danych zdrowotnych.

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
