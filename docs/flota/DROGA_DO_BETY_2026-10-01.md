# Droga do bety — stan na 1 października 2026

Dla: właściciela. Podstawa treści z rana: `origin/main` z 1.10.2026 (`aca7cfb4b`); stan zweryfikowany na `223f171b3` w sekcji 0, treść i komentarze zgłoszeń
#594, #595, #597, #598, #599, #120, #193, #8, #15, #29, #1895, #2025, #1925, #2295, #2051, #617, #600, #601,
#605, #610, #604, [`KROKI_WLASCICIELA_2026-09-29.md`](KROKI_WLASCICIELA_2026-09-29.md),
[`LISTA_KROKOW_ALFA.md`](../infra/LISTA_KROKOW_ALFA.md), [`PRZED_ZAPROSZENIEM_LUDZI.md`](PRZED_ZAPROSZENIEM_LUDZI.md)
i decyzje D-333. Dokument nic nie zmienia w panelach, na produkcji ani na GitHubie. Sekretów tu nie ma.

Bramka z `docs/ROADMAP.md` („Closed alpha gate”): 20+ realnych osób, stabilny upload, brak blokerów UX,
moderacja działa, restore przetestowany.

Czego nie da się sprawdzić z repo: stan paneli Railway, Cloudflare, R2, EmailLabs. Opieram się tam na
ostatnich odczytach właściciela i sesji (25–29.09) i oznaczam je datą. Przed działaniem odczytaj panel jeszcze raz.

## 0. Aktualizacja z 1.10.2026, popołudnie (stan `origin/main` = `223f171b3`)

Rano dokument opisywał `aca7cfb4b`. Od tamtej pory na `main` weszły paczki S, T i U, integracja Codexa
(#2419, audyt GPT-6 Astra #2408/#2409/#2410) i podział dziennika decyzji. Każdy punkt poniżej sprawdzono
na dzisiejszym `main` (git, `gh api` do stanu zgłoszeń). Stan paneli nadal pochodzi z odczytów 25–29.09 i
**nie jest tu zweryfikowany** (brak dostępu do paneli).

### 0.1. Poprawki do tego, co napisano rano

| Co rano | Jak jest naprawdę | Dowód |
|---|---|---|
| „Zamknięcie `/register` na zaproszenia to drobna zmiana w kodzie” (TL;DR, B9) | **Przełącznik już jest**: `KUKING_REGISTRATION_OPEN=false` (`config/kuking.php`, `account.registration_open`; `RegisterController::show`, wejście przez Google/Facebooka, `RejestracjaZamknieta`). **Ale zamyka też zaproszonych**: ekran zaproszenia przy zamkniętej rejestracji mówi „Zakładanie konta z zaproszenia jest teraz wyłączone”, a zaproszeń wtedy się nie wysyła (`RegistrationInviteController::pokaz`, `WyslijZaproszenieDoRejestracji::handle`). Trybu „publicznie zamknięte, ale z zaproszeniem wolno” **nie ma**, a dzisiejsze zaproszenie (D-085) wysyła się automatycznie każdemu, kto poda nieznany adres na ekranie linku do zalogowania — nie wystawia go człowiek. | `app/Http/Controllers/Auth/RegistrationInviteController.php`, `tests/Feature/ZamknietaRejestracjaNieUdajeAwariiTest.php` |
| B4: „Poprawka kodu #2295 jest do zlecenia agentowi” | **Zrobiona i scalona** (strażnik wyjątków z panelu w obie strony, ostrzeżenie o `apply` w runbooku starego bucketu, bilans `PANELOWE_Z_ZALOZENIA`). Zostaje wyłącznie odczyt panelu. Zgłoszenie #2295 jest jeszcze otwarte (do zamknięcia z dowodem). | `cebb86e32`, `67f698e9b` (paczka U), `docs/infra/ZMIENNE_SPOZA_IAC.md`, `docs/infra/STARY_BUCKET_R2_LEGACY.md` |
| B9: „Zamknięcie `/register` ... po cichu przeżyje `apply`” (milczące założenie) | **Nie przeżywało**: `KUKING_REGISTRATION_OPEN` nie było w `.railway/railway.ts`, więc `railway config apply` usunąłby `false` z panelu i `/register` otworzyłby się dla wszystkich. Naprawione w tej gałęzi (patrz 0.3). | `daee883a1` na `claude/droga-do-bety-stan` |
| §1: „Ścieżki CSAM nie ma w kodzie” | Nadal nie ma **na `main`**, ale jest otwarty PR z panelem moderacji „CSAM — natychmiast ukryj i zabezpiecz” (rejestr dowodów, status zdjęcia `secured`). Prawnik i Dyżurnet zostają bez zmian. | PR #2416 (`claude/csam-panel`), `docs/flota/CSAM_JEDNA_KARTKA.md` |

### 0.2. Blokery po podziale: (a) repo, (b) właściciel, (c) zrobione

**(c) Już na `main` (z dowodem).** Nic z tego nie wymaga już pracy w repo; zgłoszenie można zamknąć dopiero po kroku właściciela, jeśli taki jest.

| Co | Dowód |
|---|---|
| Integracja audytu GPT-6 Astra, dziennik decyzji podzielony na pliki `docs/decyzje/` | PR #2408, #2410, #2419 (`9c7449e99`), `docs/audyt/2026-10-01-gpt6-astra.md` |
| #2402 (P1): importy spójne z transakcją i database queue | PR #2409 (`dddfc9376`, `9a288cd38`), `docs/infra/KOLEJKA_DATABASE_ATOMOWA.md`. Zgłoszenie #2402 otwarte, kod scalony |
| #2390 (P1, prywatność): historia wersji bez usuniętych danych osobowych, zgłoszenie wersji, retencja | `650f4310b`, `013c7e416`, `795015acb`, `0b4abe17b`, `b91424649` (paczki S/T/U). Zgłoszenie otwarte, kod scalony |
| #2295 (IN-03) i #2296 (IN-04): strażniki zmiennych z panelu i wartości dosłownych z `railway.ts` | `cebb86e32`, `398c306d2` |
| #2291: JIT PostgreSQL wyłączony w sesji aplikacji (`DB_JIT` w rejestrze zmiennych panelu) | `01a709b39`, `55acc6871`, `795015acb` |
| #2025: strażnik bramki wdrożenia po CI (kontrole ujemne warunku sukcesu, nazwy joba zbiorczego) | `b5f3efabe`. Odbiór na produkcji dalej po stronie właściciela |
| #2381: CSP zdjęć zawężone do zaufanych hostów | `7177b5b78`, `99dfc71f3` |
| #2382: bramka R2 pkt 12 mówi o kanale `blad_webhook`, nie o niewdrożonym Sentry | `5ade28173` |
| #2299: CI, etap 3 | `643e13215` |
| Komendy operacyjne istnieją (kod, nie dowód uruchomienia na produkcji): `kuking:bramka-r2`, `kuking:sprawdz-alarm`, `kuking:martwe-zadania`, `kuking:puls-harmonogramu`, `kuking:sprawdz-piksel`, `kuking:sprawdz-poczte`, `kuking:sprawdz-model`, `kuking:budzet-polaczen`, `kuking:sprawdz-retencje-livewire`, `kuking:raport`, `kuking:wac` | `app/Console/Commands/` |
| Obraz i skrypty kopii bazy (pętla dump → szyfr → S3 → restore na MinIO) | `docker/kopia/`, `docs/infra/evidence/dr594/RAPORT.md` |
| Przełącznik zamknięcia rejestracji przeżywa `apply` (nowe) | `daee883a1`, `tests/Feature/ZamkniecieRejestracjiPrzezyjeApplyTest.php` |
| Narzędzia cold startu, protokół badania, zestaw testów z użytkownikami | bez zmian względem rana (B11, B12) |

**(a) Do zrobienia w repo bez dostępu do produkcji.** Żadne z tych nie blokuje B1–B8 (panele); część blokuje „publiczny start”, nie „zamkniętą alfę”.

| # | Co | Rozmiar | Uwagi |
|---|---|---|---|
| A1 | **Tryb „zamknięte publicznie, wejście tylko z zaproszeniem”** (B9, pytanie 1). Dziś zamknięcie odcina też zaproszonych (patrz 0.1). | średni | **Wymaga decyzji właściciela, jej nie ma w D-333.** Dzisiejsze „zaproszenie” (D-085) to automatyczny link, który dostaje KAŻDA osoba wpisująca nieznany adres na ekranie „Wyślij mi link do zalogowania” — nie jest to więc zaproszenie przez człowieka i samo z siebie niczego nie ogranicza. Pytania: kto wystawia zaproszenie (tylko gospodarz/admin czy każdy uczestnik), czy wystawione zaproszenie obchodzi `registration_open=false`, ile ich wolno na osobę, co z Google/Facebookiem (też zamknięte), czy adres musi pasować do zaproszenia (dziś: tak, adres pochodzi z wiersza zaproszenia). Propozycja podziału: (1) decyzja + wiersz D-333; (2) model/akcja „wystaw zaproszenie” (gospodarz, wiersz `registration_invites` z adresem, bez wysyłki automatycznej) + migracja, jeśli brakuje kolumny wystawcy; (3) `RegisterController`/`RegistrationInviteController`: ważne, ręcznie wystawione zaproszenie przechodzi mimo `registration_open=false`, reszta zostaje zamknięta; test + kontrola ujemna; (4) ekran gospodarza „Zaproś osobę” (UX 50+) i dokumenty (`ROADMAP`, `KROKI_WLASCICIELA`). Do czasu decyzji właściciel zostaje przy wyborze: otwarta rejestracja albo całkowicie zamknięta. |
| A2 | **Ścieżka CSAM w panelu moderacji** | gotowe do przeglądu | PR #2416. Po scaleniu: wykreślić z B9. Nie ruszać w tej gałęzi. |
| A3 | **Zdanie o DPA w polityce + test** (B8): po podpisaniu umów zmienia się jedno zdanie (`resources/legal/polityka-prywatnosci.md:105`) i test `test_nie_twierdzimy_ze_mamy_umowy_powierzenia` w jednym commicie | mały | Czeka na właściciela (B8); przy podbiciu wersji dokumentu D-327: ustaw `zmiana_*.istotna`. |
| A4 | Data wejścia w życie regulaminu (`regulamin.md`) | mały | Czeka na prawnika (B9). |
| A5 | #2403 (P2): atomowa alokacja slugu przepisu; #2404 (P2): wspólny lock pary follow/unfollow; #2405 (P2): `aria-describedby` błędu radiobuttonów formy; #2406 (P2): `aria-current="step"` w onboardingu; #2407 (P3): casty czasu UTC | #2405, #2406 małe; #2403, #2404 średnie (test dwóch połączeń); #2407 mały, bez migracji (same casty) | Audyt GPT-6 Astra ustawia je przed zamkniętą alfą (pkt 5). **Stan po paczce V (1.10):** wszystkie pięć zrobione w paczce V — #2405+#2406 jedną gałęzią, #2403 i #2404 osobno (testy dwóch połączeń), #2407 częściowo (trzy casty; reszta kolumn do inwentaryzacji). |
| A6 | Zamknięcie zgłoszeń z dowodem po stronie repo: #2402, #2390, #2295, #2296 | mały | Koordynator, po scaleniu paczki. |
| A7 | Szkielet odbioru #601 na prawdziwym uploadzie, wpis wyniku `kuking:bramka-r2` do `BRAMKA_R2.md` i `KOPIE_I_ODTWORZENIE.md` §5 | tylko dokument | Wypełnia się z wyników właściciela (B3, B6, B7); puste pola zostają, dopóki nie ma wyniku. |

**(b) Kroki właściciela** (bez zmian względem tabeli w §2 i listy w §4; nic z tego nie zostało dziś potwierdzone):
B1 plan płatny Railway przed 6.10; B2 required reviewers `production` (#1925); B3 ręczny zrzut produkcji i odtworzenie (#594, #193);
B4 odczyt zmiennych tylko-z-panelu (`AWS_LEGACY_*` itd.) i przeniesienie do Shared Variables **przed** `apply`;
**B4a (nowe): jeśli zamkniesz rejestrację (`KUKING_REGISTRATION_OPEN=false`), załóż tę samą wartość jako Shared Variable
`KUKING_REGISTRATION_OPEN` przed `apply`** (pusta Shared Variable = otwarta);
B5 `config apply` (#595); B6 `kopia-bazy` (#193/#594); B7 bramka R2 + reguła lifecycle `livewire-tmp/` (#120, #2051);
B8 DPA; B9 prawnik (#8) i decyzja o zaproszeniach (A1); B10 monitoring, puls, Open Tracking; B11 13 sesji (#15); B12 20 osób (#29).
Odbiory czekające na produkcję: #2025 (odbiór bramki CI), #601 (jedno dekodowanie), #598 (budżet połączeń).

### 0.3. Co zrobiono w tej gałęzi (`claude/droga-do-bety-stan`)

- Ten dokument: stan na teraz, korekty (0.1) i podział (0.2).
- `daee883a1`: `KUKING_REGISTRATION_OPEN` w `.railway/railway.ts` (tylko web, przez `ctx.shared`), `config/kuking.php`
  czyta pusty napis jako „otwarta” (wcześniej `(bool) ''` zamykał rejestrację), wiersz w `ZmienneRailwayaPerRolaTest`,
  nowy `ZamkniecieRejestracjiPrzezyjeApplyTest`, wiersz w tabeli planu `PRZELACZENIE_NA_3_SERWISY_595.md`, CHANGELOG.

Uwaga: poniższe sekcje 1–5 to nadal tekst z rana; w razie rozbieżności wiąże sekcja 0.

## 1. TL;DR

- Kod nie blokuje bety. Wszystkie zgłoszenia infrastrukturalne z listy mają kod, testy i runbooki na `main`.
  Zostają kliknięcia w panelach, jedna decyzja prawna i ludzie.
- **Bramka wymaga 12 pozycji po stronie właściciela** (tabela w §2: B1–B12). Dziewięć to praca (B1–B8 i B10:
  panele, komendy, DPA), razem **ok. 12–16 godzin rozłożonych na 3–5 dni**. Trzy to głównie czas kalendarzowy:
  przegląd prawnika (B9, #8, tygodnie), testy z osobami 50+ (B11, #15, min. 2 tygodnie) i pierwsze 20 osób
  (B12, #29, tygodnie).
- **Najdłuższa ścieżka to ludzie, nie infrastruktura**: #15 (13 sesji) i #29 (20 osób) to ok. 3–6 tygodni po tym,
  jak infrastruktura stoi. Realna data „można zapraszać pierwsze osoby” (do #15 i #29) to
  **ok. 7–10 dni od startu pracy w panelach**; pełna bramka zamkniętej alfy to **ok. 5–8 tygodni**.
- **Pilne z kalendarza: płatny plan Railway przed 6.10.2026** (komentarz w #1895 z 28.09: „8 days or $2.96 left”).
  Decyzja zapadła 29.09 (D-333, raczej Pro, limit 100 USD twardo, alert 60 USD). Brakuje samego kliknięcia.
- Już zrobione i nie wymaga Ciebie: alarmy na Discordzie działają (D-333), bucket zdjęć jest w jurysdykcji UE
  (#619 zamknięte), poczta wychodzi (`/health`: `poczta: ok`), tożsamość administratora w dokumentach prawnych,
  kod kopii bazy (obraz zbudowany i przećwiczony na MinIO, 18.09), jedno dekodowanie zdjęcia (#625).
- **Jedno pytanie do Ciebie zmienia harmonogram**: czy zamknięta alfa (znajomi, rodzina, 4+16 osób do #15)
  może ruszyć przed końcem przeglądu prawnika (#8)? Dokumenty (#8, `PRZED_ZAPROSZENIEM_LUDZI.md`) mówią:
  „nie ma publicznego startu” bez prawnika i bez umów powierzenia (DPA). Polityka sama obiecuje podpisanie DPA
  „przed otwarciem rejestracji dla wszystkich”. Rekomendacja: zapraszać ręcznie wybrane osoby po DPA
  (B8) i po rozmowie z prawnikiem umówionej (B9), ale nie otwierać publicznie przed jego opinią.
  Dziś `/register` jest otwarty bez bramki; zamknięcie na zaproszenia to drobna zmiana w kodzie do zlecenia
  (stan z 12.09 w #8, w repo nic nowego nie znalazłem).

## 2. Blokery bety — tabela

Kolejność = kolejność wykonania. Czas = praca właściciela (nie licząc czekania). „Kto” = kto robi krok.
Numery B w §4 odpowiadają kolejności tutaj.

| # | Zgłoszenie | Stan na dziś (dowód) | Co zostało | Kto | Czas | Po czym |
|---|---|---|---|---|---|---|
| B1 | #595 (część: plan) i #599 (koszty) | Decyzja D-333 z 29.09: Pro, limit 100 USD, alert 60 USD. Nie wykonane (komentarz #1895 28.09: trial kończy się ok. 6.10). | Włączyć płatny plan, ustawić limit i alert, adres alertu | Ty, panel Railway | 15 min | — (przed 6.10) |
| B2 | #1925 | `railway-iac.yml` deklaruje `environment: production`, środowisko ma `protection_rules: []` (odczyt 26.09). Ryzyko staje się aktywne dopiero po dodaniu `RAILWAY_TOKEN_PRODUCTION` | Required reviewers w GitHub Environments (G3) | Ty, GitHub | 5 min | przed B6 |
| B3 | #594 (krok 1–3), #193 | Kod i skrypty gotowe: obraz `docker/kopia` zbudowany, pętla dump → szyfr → S3 → restore przećwiczona na MinIO (komentarz #594 z 18.09), lokalna próba 5,5 mln wierszy (`docs/infra/evidence/dr594/RAPORT.md`). `KOPIE_I_ODTWORZENIE.md` §5 (tabela wyników produkcyjnych) pusta: **kopii produkcyjnej dziś nie ma** | Ręczny `pg_dump` produkcji, odtworzenie do osobnej bazy, zapis RPO/RTO — wg [`DR594_PIERWSZY_ZRZUT_WLASCICIEL.md`](../infra/DR594_PIERWSZY_ZRZUT_WLASCICIEL.md). To też warunek przed `apply` | Ty (komendy) | 45 min | — |
| B4 | #2295 (IN-03), #1895 §5 | Otwarte. `config/filesystems.php:257-266` czyta `AWS_LEGACY_*`; test i runbook zakładają, że ta zmienna jest „tylko w panelu”, a `apply` zmienne spoza pliku usuwa (`docs/infra/ZMIENNE_SPOZA_IAC.md`). Stary bucket bywa jedyną kopią części zdjęć ([`STARY_BUCKET_R2_LEGACY.md`](../infra/STARY_BUCKET_R2_LEGACY.md)) | Odczytać w panelu, czy `AWS_LEGACY_*`, `KUKING_EDGE_TRYB`, `KUKING_HTML_EDGE_CACHE_SECONDS`, `KUKING_TAG_TYGODNIA`, `KUKING_HEALTH_TOKEN`, `KUKING_QUESTIONS_ENABLED`, `KUKING_MEDIA_DISK` stoją tylko w serwisie `kuking.pl`; jeśli tak, przenieść do Shared Variables **przed** `apply`. Poprawka kodu #2295 jest do zlecenia agentowi, ale nie jest konieczna, jeśli zmiennych legacy nie ma | Ty (odczyt), agent (poprawka) | 30 min | B3 |
| B5 | #593→#595 (rozdział web/worker/scheduler) | Kod IaC i runbook gotowe ([`PRZELACZENIE_NA_3_SERWISY_595.md`](../infra/PRZELACZENIE_NA_3_SERWISY_595.md)); `railway config apply` nigdy nie uruchomiony (`.railway/railway.ts`, komentarz #595 27.09). Dziś jedna usługa `all` | Shared Variables, `config plan`, czytanie planu, `apply`, weryfikacja 15 min (decyzja 25.09: bez stagingu, okno serwisowe i zrzut ≤ 24 h) | Ty | 2–3 h | B1, B2, B3, B4 |
| B6 | #594, #193 (kopia automatyczna) | Serwis `kopia-bazy` zadeklarowany w IaC, nieutworzony; brak bucketu kopii i kluczy | Bucket R2 kopii + token, para kluczy szyfrujących (prywatny poza Railway), zmienne `KOPIA_KLUCZ_PUBLICZNY`, `AWS_KOPIE_*`, pierwszy przebieg, nazajutrz czujka, odtworzenie z bucketu z RPO/RTO wpisanym do §5 [`KOPIE_I_ODTWORZENIE.md`](../infra/KOPIE_I_ODTWORZENIE.md) (§7.3, §4A) | Ty | 2 h + 1 noc | B5 |
| B7 | #120 | Kod i adapter `r2` gotowe; test na MinIO: 8 punktów TAK, 4 NIE WIEMY, 4 kontrole ujemne (komentarz #120 z 18.09). Tabela wyników w [`BRAMKA_R2.md`](../infra/BRAMKA_R2.md) pusta. #619 (jurysdykcja) zamknięte 29.09 | `railway ssh -- php artisan kuking:bramka-r2 --zapis` z `KUKING_R2_PUBLICZNE_ADRESY` (także `r2.dev`), przegląd panelu Cloudflare (r2.dev wyłączone, tokeny, domeny), wpis wyniku z datą. To zamyka „stabilny upload” od strony prywatności | Ty | 1 h | B5 |
| B8 | #8 (część DPA) | Polityka (`resources/legal/polityka-prywatnosci.md:104`): „umów powierzenia jeszcze nie mamy”. Rejestr umów ([`REJESTR_UMOW_POWIERZENIA.md`](../legal/REJESTR_UMOW_POWIERZENIA.md)) to lista do odhaczenia, nie umowy. Rejestr czynności (RODO art. 30) **już istnieje**: [`REJESTR_CZYNNOSCI_PRZETWARZANIA.md`](../legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md), do uzupełnienia pola właściciela | Zaakceptować/pobrać DPA: Railway, Cloudflare (R2, Turnstile, Analytics), EmailLabs (Vercom), OpenAI. Po podpisaniu zdanie w polityce i test `test_nie_twierdzimy_ze_mamy_umowy_powierzenia` zmieniają się w jednym commicie (zlecenie agentowi) | Ty | 1–2 h | — |
| B9 | #8 (prawnik) | Otwarte. Nikt spoza projektu nie potwierdził zgodności dokumentów. **Ścieżki CSAM nie ma w kodzie** (`git grep -i "csam\|dyżurnet"` po `app/`, `resources/views/`, `routes/`: zero trafień; jest tylko procedura w `MODERATION_PLAYBOOK.md` i [`CSAM_JEDNA_KARTKA.md`](CSAM_JEDNA_KARTKA.md)). Brak też daty wejścia w życie regulaminu (rozstrzyga prawnik) | Umówić prawnika (30 min pracy, czekanie tygodnie), przekazać listy pytań z #8, `docs/research/DSA-LUKI.md` §5, komentarze o retencji potwierdzeń usunięć (#8, 25.09). Decyzja: czy zamknąć `/register` na zaproszenia na czas przeglądu | Ty; agent po decyzji (kod: przycisk CSAM w panelu moderacji, rejestracja na zaproszenie) | 30 min + czekanie | — (zacznij od razu) |
| B10 | #599 (monitoring), #1895 §8 | Kod gotowy: alarmy kolejki, połączeń i kopii potwierdzają doręczenie (komentarz #599 24.09: luka „nieudany webhook kupuje ciszę” naprawiona, testy `NieudanyDzwonekNieKupujeCiszyTest`), `kuking:sprawdz-alarm`, puls harmonogramu. Discord działa (D-333). Brak: zewnętrznego monitora, pulsu harmonogramu, rozliczenia `failed_jobs`, wyłączenia Open Tracking w EmailLabs | Zewnętrzny monitor `https://kuking.pl/` i `/health` (na domenie), `KUKING_PULS_HARMONOGRAMU_URL` + heartbeat, `kuking:martwe-zadania`, Open Tracking off + test `.eml` | Ty | 1,5 h | B5 (puls); reszta od razu |
| B11 | #15 | Zestaw gotowy: [`TESTY_Z_UZYTKOWNIKAMI.md`](../product/TESTY_Z_UZYTKOWNIKAMI.md), `TrescZalazkowaSeeder`, protokół [`PROTOKOL_BADANIA_1818.md`](../product/PROTOKOL_BADANIA_1818.md). Wykonana 1 z 13 sesji (`PRZED_ZAPROSZENIEM_LUDZI.md`). Całość po stronie ludzi | Decyzje D18 (protokół) i rekrutacja (§9), 13 sesji (5×50–59, 5×60–69, 3×70+; Android, iPhone, komputer) | Ty + prowadzący ≠ Ty | min. 2 tygodnie | B5, B6 (nie zapraszać na bazę bez kopii), B10 |
| B12 | #29 | Narzędzia cold startu są na `main` (`/admin/bez-odpowiedzi`, `kuking:raport`, `kuking:wac`, tag tygodnia, kolaż, zaproszenia). Brakuje ludzi | Lista 40–60 osób, kontakt indywidualny, 20 osób z wpisem, Bramka A (liczby w #29) | Ty | tygodnie, ok. 2 h dziennie | B11 bez blokerów, B6, B7, B10 |

Moderacja („moderation działa”): kod i panel są gotowe (#581). Brakuje 20-minutowego odbioru na produkcji:
`kuking:sprawdz-model`, jedno zgłoszenie próbne między dwoma kontami, odbiór panelu moderatora (O7 w
[`KROKI_WLASCICIELA`](KROKI_WLASCICIELA_2026-09-29.md)). Robisz to po B5; wliczone do B12.

## 3. Może poczekać po starcie bety

| Zgłoszenie | Stan | Dlaczego może poczekać | Kiedy wrócić |
|---|---|---|---|
| #2025 (wdrożenie mimo anulowanego CI) | Natywne „Wait for CI” w Railway jest włączone (odczyt 28.09 18:18 UTC; poprzednie wrażenie z 12:47 zostało skorygowane). Bramka własna (`KUKING_CI_GATED_RAILWAY_DEPLOY`, [`RAILWAY_CI_GATE.md`](../infra/RAILWAY_CI_GATE.md)) jest ulepszeniem; kod gotowy. Brak testu czerwonego CI na produkcji | Nic się nie psuje; ryzyko to wdrożenie z czerwonym CI przy rzadkich zdarzeniach. Kolejność K1 → G2 → K2 → G4 jest łatwa do pomylenia, więc nie rób tego w tygodniu `apply` | Po B5, w spokojnym oknie |
| #1895 pozostałe punkty | §1 `CENY_WARZYW_PAT`, §2 OpenAI (D8), §3 Web Push, §4, §7 odbiór #581 | Funkcje dodatkowe. Web Push wymaga najpierw zapisu w polityce i #1979. Import AI jest wyłączony do podpisania DPA z OpenAI (#2214) | Po becie lub razem z B8 (OpenAI) |
| #2051 (retencja `livewire-tmp/`) | Kod i `kuking:sprawdz-retencje-livewire` gotowe. Reguła lifecycle R2 nie ustawiona, nigdy niepotwierdzona obiektem kontrolnym | Zdjęcia z EXIF/GPS mogą leżeć w buckecie dłużej niż deklaruje polityka. **Zrób regułę (30 min) razem z B7**, bo to ten sam panel R2, ale pełny odbiór (48 h obiektu kontrolnego) może iść w trakcie bety | Reguła z B7, odbiór w trakcie F3 |
| #617 (DR zdjęć) | Kod próby odtworzenia i runbook [`DR_ZDJEC_R2.md`](../infra/DR_ZDJEC_R2.md) są. Bucket kopii zdjęć nie istnieje; claim „Twoje przepisy nie zginą” zakazany (D-333) | Poza bramką alfy (`LISTA_KROKOW_ALFA.md` D7), zależy też od prawnika (retencja a prawo do usunięcia). Zrób przed rozszerzeniem poza zamkniętą grupę | Po B6, przed kampanią |
| #597 (cache `/zdjecia/*`), #610 (cache HTML gościa) | Reguły i test sondy gotowe: [`CLOUDFLARE_CACHE_597_610.md`](../infra/CLOUDFLARE_CACHE_597_610.md), `cloudflare-cache-rules-597-610.json`. Nie zastosowane w panelu | Przy ruchu 20–50 osób żaden cache nie jest potrzebny (baseline p95 33–54 ms, #605/#610). Nie blokuje alfy wg `LISTA_KROKOW_ALFA.md` | Przy wzroście ruchu albo po kampanii |
| #598 (budżet połączeń) | Komenda `kuking:budzet-polaczen` i skrypt odczytu logów są. Odczyt `max_connections` po `apply` (B6 w LISTA_KROKOW_ALFA) | Zadanie pomiarowe; sensowne dopiero po `apply`, nie blokuje zaproszeń. Zrób 15 min po B5 przy okazji | Tydzień po B5 |
| #599 pozostałe punkty | Panele p95/p99, wiek jobów, cache hit ratio | Alarmy dostępności, kolejki i połączeń wystarczą na alfę; pełny dashboard to obserwowalność wzrostu. Railway Pro daje część (monitory zasobów, [`RAILWAY_PRO_WYKORZYSTANIE.md`](../infra/RAILWAY_PRO_WYKORZYSTANIE.md)) | W trakcie bety |
| #600 (druga replika, PgBouncer, worker `media`) | Warunki wstępne: #595, #599, #598 | Zadanie sterowane metrykami, nie datą | Po tygodniach danych |
| #601 (jedno dekodowanie) | Kod wdrożony w #625, test `tests/Feature/JednoDekodowanieZdjeciaTest.php`. Zostaje odbiór na prawdziwym uploadzie | Odbierz przy pierwszym realnym uploadzie po B5 (wpis w #601, że media job doszedł) | Przy B7 |
| #605 (test do nasycenia) | Narzędzia w `docs/infra/evidence/obciazenie605`; baseline nie wyznacza pojemności (nie ekstrapolować) | Pytanie o pojemność przy tysiącach osób, nie przy 20 | Przed kampanią |
| #604 (Postgres HA) | Tylko odczyt kosztu i warunków | Decyzja SLA/koszt, nie bety | Po Bramce A |
| #2295 (poprawka kodu) | Zob. B4: sam odczyt panelu wystarcza, poprawka kodu może iść równolegle | — | Przed B5 jeśli zmienne legacy stoją tylko w panelu |
| Web Push (#35), Cloudflare token krawędzi (#1306), raporty DMARC, imieniny, grupy | Zob. `KROKI_WLASCICIELA` | Nie bramka | Po becie |

## 4. Kroki właściciela w kolejności wykonania

Zaznaczaj po wykonaniu i wpisuj w odpowiednie zgłoszenie datę i wynik (bez kluczy i adresów). Dłuższe
szczegóły kliknięć: [`LISTA_KROKOW_ALFA.md`](../infra/LISTA_KROKOW_ALFA.md) (numeracja w nawiasie to kolejność z jej §0).

### Dziś / jutro (przed 6.10)

- [ ] **B1. Plan płatny Railway** + limit 100 USD twardo + alert 60 USD + adres alertu.
  [`RAILWAY_PRO_WYKORZYSTANIE.md`](../infra/RAILWAY_PRO_WYKORZYSTANIE.md), `LISTA_KROKOW_ALFA.md` A3 (krok 8). Wpis w #595/#599.
- [ ] **B9a. Umów prawnika** (przegląd regulaminu, polityki, zasad; lista pytań w #8). Zacznij od razu, bo to
  najdłuższe czekanie (F1, krok 7). Wpisz w #8 datę i imię kancelarii (bez danych osobowych).
- [ ] **B2. Required reviewers** dla środowiska `production` w GitHubie (Settings → Environments → production).
  Zrzut ustawień do #1925. [`RAILWAY_CI_GATE.md`](../infra/RAILWAY_CI_GATE.md), G3.
- [ ] **B10a. Odczyt stanu produkcji** (nic nie zmienia): nazwy usług, region, Start Command, „Wait for CI”,
  nazwy zmiennych (A1, krok 1). 2FA na kontach Railway, Cloudflare, GitHub, EmailLabs (A2, krok 2).

### W ciągu 2–3 dni (przed `apply`)

- [ ] **B3. Ręczny zrzut produkcji i odtworzenie do osobnej bazy** (D1, krok 6).
  [`DR594_PIERWSZY_ZRZUT_WLASCICIEL.md`](../infra/DR594_PIERWSZY_ZRZUT_WLASCICIEL.md), [`KOPIE_I_ODTWORZENIE.md`](../infra/KOPIE_I_ODTWORZENIE.md) §4A. Zapisz RPO/RTO do #594.
- [ ] **B10b. Próba kanału alarmów** `kuking:sprawdz-alarm` (E1, krok 3), rozliczenie starych `failed_jobs`
  (`kuking:martwe-zadania`; same wpisy z 9.09 znikną same ok. 10.10, E3, krok 4), zewnętrzny monitor
  `https://kuking.pl/` i `/health` na domenie (E2, krok 5; [`MONITORING_ODBIOR_2026_09_20.md`](../infra/MONITORING_ODBIOR_2026_09_20.md), [`MONITORING_BLEDOW.md`](../infra/MONITORING_BLEDOW.md)).
- [ ] **B10c. EmailLabs: wyłącz Open Tracking**, wyślij list `kuking:sprawdz-poczte`, zapisz `.eml`, uruchom
  `kuking:sprawdz-piksel` ([`POCZTA_URUCHOMIENIE.md`](../infra/POCZTA_URUCHOMIENIE.md) krok 6; #1895 §8).
- [ ] **B4. Zmienne tylko w panelu** do Shared Variables (A4–A5, kroki 9 i 11):
  [`ZMIENNE_SPOZA_IAC.md`](../infra/ZMIENNE_SPOZA_IAC.md), [`STARY_BUCKET_R2_LEGACY.md`](../infra/STARY_BUCKET_R2_LEGACY.md). Najpierw odczyt, czy `AWS_LEGACY_*` w ogóle jest ustawione.
  Bucket kopii i tokeny (D2–D3, krok 10) przygotuj tu, bo `apply` ma wtedy gotowy serwis `kopia-bazy`.
- [ ] **B8. DPA**: Railway, Cloudflare, EmailLabs, OpenAI ([`REJESTR_UMOW_POWIERZENIA.md`](../legal/REJESTR_UMOW_POWIERZENIA.md),
  [`DECYZJE_WLASCICIELA_R1_R6_DPA.md`](../legal/DECYZJE_WLASCICIELA_R1_R6_DPA.md)). Potem zlecenie agentowi: polityka + test w jednym commicie.

### `apply` (jedno okno serwisowe, ok. pół dnia)

- [ ] **B5. Zabezpieczenia na produkcji** (B1 w LISTA: zrzut ≤ 24 h, okno, plan cofnięcia; krok 16) →
  `railway config plan` i czytanie (krok 17) → `apply` (krok 18) → weryfikacja w 15 min + odbiór #601 (krok 19)
  → ustawienia ręczne po `apply` (krok 20). [`PRZELACZENIE_NA_3_SERWISY_595.md`](../infra/PRZELACZENIE_NA_3_SERWISY_595.md),
  [`DEPLOYMENT_RUNBOOK.md`](../infra/DEPLOYMENT_RUNBOOK.md). Wpis w #595.
- [ ] **Puls harmonogramu** `KUKING_PULS_HARMONOGRAMU_URL` + monitor heartbeat (E4, krok 22). Wpis w #599.
- [ ] **Zrzuty metryk „przed” i „po”** (E6, krok 15) do porównania.
- [ ] **Odczyt budżetu połączeń** (B6 w LISTA, krok 23), wpis w #598 (może iść później).

### W ciągu tygodnia po `apply`

- [ ] **B6. `kopia-bazy`**: pierwszy przebieg (D4, krok 21), nazajutrz czujka (D5), odtworzenie z bucketu z RPO/RTO (D6, krok 28).
  [`KOPIE_I_ODTWORZENIE.md`](../infra/KOPIE_I_ODTWORZENIE.md) §7.3, §4A. Wpis w #193 i #594; zamknięcie obu to Twoja decyzja po D6.
- [ ] **B7. Bramka R2**: przegląd Cloudflare (C1, C2 — krok 24–25; [`LOKALIZACJA_DANYCH_R2.md`](../infra/LOKALIZACJA_DANYCH_R2.md)),
  `kuking:bramka-r2 --zapis` (C3, krok 26; [`BRAMKA_R2.md`](../infra/BRAMKA_R2.md)). Przy tym samym panelu: **reguła lifecycle `livewire-tmp/`**
  ([`LIVEWIRE_TMP_R2_RETENCJA_2051.md`](../infra/LIVEWIRE_TMP_R2_RETENCJA_2051.md), R1–R3) — wpis w #2051.
- [ ] **Moderacja działa** (F2, krok 30): `kuking:sprawdz-model`, zgłoszenie próbne, odbiór panelu #581, `KUKING_HOST_USER_ID`.
- [ ] **Decyzja o rejestracji**: zamknąć `/register` na zaproszenia na czas przeglądu prawnika, czy zostawić.
  Jeśli zamknąć, zleć agentowi (kod: drobny). Dopisz do zlecenia przycisk zgłoszenia CSAM w panelu moderacji,
  jeśli prawnik/Dyżurnet tego wymaga (#8).

### Ludzie (zaczyna się, gdy B3–B7 mają wpis z datą)

- [ ] **B11. Zatwierdź protokół #1818 (D18)** i rozstrzygnij rekrutację z `TESTY_Z_UZYTKOWNIKAMI.md` §9
  (uczestnicy spoza publiczności startowej albo jawne „to jeszcze nie otwarcie”). Uruchom `TrescZalazkowaSeeder`,
  sprawdź `kuking:sprawdz-poczte`, przejdź 10 ścieżek dzień przed. Prowadzący ≠ Ty (O5 w `KROKI_WLASCICIELA`).
- [ ] **13 sesji #15** (w tym iPhone z HEIC, #119). Każdy bloker jako issue `obszar: ux`.
- [ ] **B12. Lista 40–60 osób, 20 z wpisem**, Bramka A z liczb `kuking:raport` ([`docs/product/COLD_START.md`](../product/COLD_START.md)).
  STOP przy WAC/zarejestrowani poniżej 35%.
- [ ] **Opinia prawnika (B9)**: wpływ na tekst dokumentów wchodzi PR-em z testami `DokumentyPrawneNieKlamiaTest`
  (bez noty „nie weryfikowano”, D-140). Przed publicznym startem: ścieżka CSAM, data wejścia w życie, rejestr czynności uzupełniony przez Ciebie.

## 5. Ryzyka i pytania

1. **Prawnik przed czy po zaproszeniu zamkniętej grupy?** (§1). Od tego zależy, czy B9 jest na ścieżce krytycznej bety, czy publicznego startu.
2. **`apply` bez stagingu** (decyzja 25.09) to największe jednorazowe ryzyko operacyjne w tej liście. Łagodzi je B3 (zrzut) i B4 (zmienne), plan cofnięcia w `PRZELACZENIE_NA_3_SERWISY_595.md`.
3. **Kopia zdjęć nie istnieje** (#617), a kopia bazy jeszcze nie ma wpisu produkcyjnego. Do czasu B6 i #617 nie wolno obiecywać „Twoje przepisy nie zginą” (D-333). Przy zaproszeniach powiedz to wprost zaproszonym.
4. **Odtworzenie starej kopii przywróci dane osób, które zażądały usunięcia** (komentarz #594 z 21.09). Mechanizmu ponownego zastosowania usunięć po restore nie ma; to warunek dojrzałości DR, nie dnia zero. Przy 20 znajomych można przyjąć świadomie, wpisz decyzję do #594.
5. Stan paneli w tym dokumencie pochodzi z odczytów 25–29.09. Nie zmieniaj niczego bez ponownego odczytu panelu.
