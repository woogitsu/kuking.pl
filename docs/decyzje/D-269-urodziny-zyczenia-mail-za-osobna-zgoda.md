## D-269 — Urodziny (dzień i miesiąc), życzenia, mail za osobną zgodą, przypomnienie obserwującym, rocznica dołączenia (#1754, #1755, 25 września 2026)

**Data:** 25 września 2026 · Decyzja właściciela · Status: **obowiązuje**

Research: `docs/research/PROFIL_FORMA_I_URODZINY.md` (gałąź
`claude/research-profil-forma-urodziny`). Właściciel przyjął część
rekomendacji i świadomie poszedł dalej w dwóch miejscach, które research
odkładał albo odrzucał (mail z życzeniami, przypomnienie obserwującym).

### CO ZOSTAŁO POSTANOWIONE

1. **Urodziny = dzień i miesiąc, bez roku.** Pole opcjonalne
   (`users.birthday_day`, `users.birthday_month`, CHECK zakresów), przycisk
   „Usuń datę”, osobny ekran `/ustawienia/urodziny`. Roku nie zbieramy ani do
   życzeń, ani do weryfikacji wieku (`docs/legal/COMPLIANCE.md` §4). 29 lutego
   obchodzimy 28 lutego w latach nieprzestępnych. Data jest prywatna: nie ma
   jej na profilu publicznym. Eksport RODO (`konto.urodziny`, DD-MM),
   anonimizacja przy wymazaniu konta. `docs/SECURITY_PRIVACY_LEGAL.md`
   („Data minimization”) dostaje dopisek — pełna data nadal na liście „nie
   zbierać”.
2. **Życzenia od gospodarza na `/home`** — jedno zdanie w dniu urodzin, tylko
   u samego zainteresowanego, z wyłącznikiem przy dacie
   (`users.birthday_wishes_enabled`, domyślnie włączone; zasada żałoby).
3. **Mail z życzeniami — wyłącznie za OSOBNĄ zgodą** (PKE art. 398):
   `users.wants_birthday_email`, domyślnie wyłączone; podanie daty zgody nie
   daje. Każda zmiana zgody w dzienniku zgód (cel `zyczenia_urodzinowe`,
   D-072). Wysyłka w dobowych sufitach poczty (własny 20/dobę + wspólna pula
   w klasie `podsumowanie`, która gaśnie pierwsza), o stałej porze 08:40 UTC
   z harmonogramu. Po scaleniu **włączona na produkcji**
   (`KUKING_URODZINY_MAIL_WLACZONY` w roli scheduler, `.railway/railway.ts`);
   staging i PR-y nie wysyłają.
   **Wypisanie:** podpisany odnośnik w liście prowadzi na stronę z pytaniem —
   **sam GET niczego nie zmienia**, zgodę wycofuje przycisk (POST), a po
   wypisaniu jest „Jednak chcę go dostawać” (wzorem #1403).
4. **Przypomnienie obserwującym („Dziś urodziny: Ania”) — tylko gdy osoba
   sama WŁĄCZY** „Pokaż moje urodziny obserwującym”
   (`users.birthday_visible_to_followers`, domyślnie wyłączone). Powiadomienie
   w serwisie (`birthday.today`), **nie wpis w feedzie** — feed obserwowanych
   zostaje chronologiczny bez wstawek (AGENTS.md §8). Najwyżej jedno na parę
   dziennie, dobowy limit na odbiorcę, nigdy w ciszy nocnej (21–8).
5. **Konta zawieszone są wykluczone** — solenizantem w mailu i w
   przypomnieniu może być tylko konto o statusie `active`.
6. **Rocznica dołączenia** — jedno zdanie od gospodarza na `/home` w rocznicę
   `users.created_at` (Europe/Warsaw), bez maila i powiadomień, zero nowych
   danych. **Wyłącznik wspólny ze Wspomnieniami** (`users.memories_enabled`) —
   jeden przełącznik dla „tego dnia w poprzednich latach”.

### CZEGO TA DECYZJA NIE ZMIENIA

- Formy gramatycznej (#1752/#1753) — teksty rocznicy i życzeń są dziś bez
  rodzaju i powstają w jednym miejscu (`RocznicaDolaczenia::tekst()`,
  `Urodziny::tekstZyczen()`), gotowe na helper formy.
- Zasady „nie pytamy o płeć” (D-206) ani listy „nie zbierać” — rok urodzenia
  i pełna data dalej są poza zakresem.
- Imienin — dalej V1, po testach z ludźmi (#15).

### Wycofanie

Każdy element ma własną migrację z `down()` odmawiającym przy decyzjach
ludzi (D-088) — opis w `docs/DATABASE.md` („Urodziny bez roku”). Wysyłkę
maili wyłącza `KUKING_URODZINY_MAIL_WLACZONY=false` bez wdrożenia kodu.
