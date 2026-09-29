# Handover sesji koordynatora — 29.09.2026

Sesja `011FKfAFGGKBgHuKygSP9a5e` działała od 28.09 ok. 20:40 UTC do 29.09 rano. Kończy się z powodu limitu użycia.
Dokument jest dla sesji, która przejmie koordynację. Najpierw przeczytaj [`AGENTS.md`](../../AGENTS.md). Poprzedni handover, [`HANDOVER_SESJI_GLOWNEJ_2026-09-25.md`](HANDOVER_SESJI_GLOWNEJ_2026-09-25.md), nadal dobrze opisuje styl pracy z właścicielem (§2) i twarde zakazy. Ten dokument opisuje **stan** i **to, co zostało**.

Narzędzia i prompty tej sesji leżą w [`sesja-glowna-2909/`](sesja-glowna-2909/). Opis w §8.

---

## 1. Streszczenie w pięciu punktach

1. **Na `main` jest Alfa 0.75** (PR #2198, merge `11699845a`). Zawiera pociąg Codexa `integracja-po-074`, falę A, paczkę B i PR-y #2191–#2197.
2. **Trzy paczki czekają w kolejce do `main`**, każda zbudowana na poprzedniej. Scalaj po kolei, zawsze merge commitem:
   - **C** — PR #2206, gałąź `claude/paczka-c`, zawiera **wydanie Alfa 0.76**;
   - **D** — PR #2207, gałąź `claude/paczka-d`, rodzinny zeszyt i strażnik decyzji;
   - **E** — gałąź `claude/paczka-e`, ok. 20 gałęzi robotników. PR otwiera się dopiero po wejściu C i D, stan w §3.3.
3. Właściciel **pozwolił scalać do `main` bez pytania, gdy CI jest zielone**. Jego słowa: „Scalaj do main kiedy można”, „scalaj do main na bieżąco, rób ci, pr itp”. Zgoda obejmuje tylko zielone PR-y.
4. Robotnicy, wysłani w tej sesji do ok. 40 issues, w większości przypadków stwierdzili, że problem **jest już naprawiony w paczce C**. Te issues zamkną się same po scaleniu #2206: opis PR ma `Closes`, a GitHub pokazuje `closed_by_pull_requests`. Po scaleniu sprawdź i domknij resztę ręcznie z dowodem (§4).
5. Dużo zostaje **po stronie właściciela**: panele Railway, Cloudflare, R2, GitHub Settings, pomiary produkcyjne i decyzje. Lista w §5 i §6.

## 2. Decyzje właściciela z tej sesji

Obowiązują, dopóki właściciel ich nie zmieni.

| Decyzja | Słowa właściciela / kontekst |
|---|---|
| Przejąć całą pracę Codexa i drugiej sesji Claude | Obie sesje zarchiwizowane przez właściciela 28.09 |
| PostgreSQL 18 lokalnie | Zainstalowany; klaster 18/main na 5432, stary 16 wyłączony na 5433 |
| Zamknąć ok. 53 issues naprawionych wcześniej, z dowodem | Zrobione (lista: `sesja-glowna-2909/ZAMKNIECIA.md`) |
| Agentów tyle, ile zadań | Twardy limit narzędzia: **20 naraz** (`CLAUDE_CODE_MAX_CONCURRENT_SUBAGENTS`) |
| **Scalanie do main: gdy zielone, bez pytania** | „Scalaj do main kiedy można” (29.09 w nocy), potwierdzone „scalaj do main na bieżąco” (29.09 rano). Wcześniejsza decyzja „Ty zatwierdzasz scalenia” jest nieaktualna |
| Po limicie: handover i wszystko na GitHub | „limit się kończy, jak agenci skończą to napisz rozbudowany handover, wszystko wypuść na github” |

Klasyfikator uprawnień **blokował** w tej sesji:
- cykliczny trigger co 30 min;
- push na cudze gałęzie `codex/integracja-*`;
- PATCH bazy PR-ów na `main`;
- hurtowe zamykanie issues (później przeszło po zgodzie właściciela);
- scalenie #2198 („Merge Without Review”, zanim była zgoda).

Nie obchodź blokad przez subagentów. Ciągłość pracy zapewniały powiadomienia agentów, subskrypcje PR-ów i `send_later` (pojedyncze sprawdzenia).

## 3. Paczki w kolejce do main

Model pracy: robotnik pracuje na własnej gałęzi `claude/<N>-<opis>`, założonej od bazy (paczki). Koordynator albo integrator scala gałęzie do paczki. Paczka idzie jednym PR-em do `main`, z pełnym CI i recenzją, i wchodzi merge commitem. Przed wydaniem koordynator robi commit `release: przygotuj Alfa 0.N`.

### 3.1 Paczka C — PR #2206 (`claude/paczka-c`)

- **Head:** `8ff6b5874` (stan na koniec sesji — sprawdź, czy CI na nim zielone).
- **Zawartość:** fala 8 (kopie PR-ów z 13 gałęzi: 1533 1567 1603 1608 1621 1627 1629 1725 1771 1780 1849 1872 1879), planer z wyszukiwarką (#2037), zapis przepisu po rejestracji (#2028), „Co mam w domu” (V2 pantry, #1958/#1969), szukanie w zeszytach po składnikach (#2068), #2189, #2190, #2086, #2199, #617 (test DR), PR-y #1531 #1598 #1674 #1698 #1548 #1826, #1387 etap (formularz kreatora), polityka prywatności zbiorcza 2026-09-29 (#1816, #1324, #619), **commit wydania Alfa 0.76** (`589f5256a`).
- **Poprawki koordynatora w tej sesji:**
  - Larastan w 7 testach;
  - harness `--safe-*` w `wyglad-nawigacja.test.mjs`;
  - przywrócona pora „Smakowicie wygląda” 17:47 czasu polskiego;
  - wpis #1548 przeniesiony z sekcji 0.69;
  - kotwica kontroli ujemnej R1 po zmianie polityki;
  - kontrole ujemne dla `PolitykaOpisujePaczkeUkryciaIReakcjeTest`.
- **Po scaleniu C:** Railway wdroży `main` automatycznie. Sprawdź `/health` i numer wersji w stopce („Alfa 0.76”).

### 3.2 Paczka D — PR #2207 (`claude/paczka-d`)

- **Head:** `6a6b9c8cc` (zawiera C `8ff6b5874`).
- **Zawartość:**
  - #1885/#1743: rodzinny zeszyt, D-302, migracje `2026_09_29_100000`/`100100`, trzy mutacje Policy w `tests/mutacje/autoryzacja.txt`;
  - #2154: `DziennikDecyzjiOdwolaniaTest`;
  - poprawka koordynatora: `collections.link.show` w `PageContext::SENSITIVE_ROUTES`, bo token linku-zaproszenia trafiłby do `page_path` kontaktu, jak w #836.
- Wpis CHANGELOG i akapit o rodzinnym zeszycie stoją pod „Nieopublikowane” i „Najnowsze zmiany”, bo **nie** wchodzą do 0.76.
- **Scalaj dopiero po C.** Po scaleniu C diff #2207 skurczy się do samej zawartości D. Jeśli pojawi się konflikt, scal `main` do `claude/paczka-d`.

### 3.3 Paczka E — `claude/paczka-e` (bez PR-a)

Integrator scalał do niej (baza: `claude/paczka-d`) następujące gałęzie:

| Gałąź | SHA | Issue | Uwagi |
|---|---|---|---|
| `claude/581-panel-moderacji-marka` | — | Refs #581 | rodzina „kolejka” w porcie marki |
| `claude/492-metryki-marka` | `8516fc401` | Refs #492 | zawiera 581; /admin/metryki w kompozycji panelu |
| `claude/1969-kolizje-rdzeni` | `520b97bd7` | Closes #1969 | tylko test |
| `claude/2049-dmarc-rua` | `c7291e78f` | Refs #2049 | krok DNS właściciela |
| `claude/2178-livewire-tmp-reszta` | `82036bcfa` | Refs #2178, #2051 | |
| `claude/1687-etap4` | `c5a920ee8` | Refs #1687 | |
| `claude/2205-wycofanie-udzialu-powiadomienia` | `ba002f49c` | Closes #2205 | |
| `claude/1860-luki-po-zamknietych` | `11404fe8e` | Refs #1860 | po scaleniu zamknąć #973 #989 #971 #1377 #986 #1378 |
| `claude/2031-zgoda-zrodla-ai` | `cd9ca1766` | Closes #2031 | |
| `claude/2130-znacznik-slownika-odzywczego` | `2fbcf353d` | Refs #2130 | |
| `claude/1046-sesja-testy-wspolbiezne` | `b7a477130` | Closes #1046 | test ok. 3,5 min |
| `claude/988-komunikat-odmowy` | `19a5cd40b` | Refs #988 | **duży** (54 kontrolery) |
| `claude/1932-wersja-po-healthchecku` | `77967b3c4` | Closes #1932 | |
| `claude/841-wyszukiwanie-wiadomosci` | `0dd31b213` | Refs #841 | narzędzia pomiaru |
| `claude/870-wyszukiwanie-pytan` | `2eda0edc3` | Refs #870 | narzędzia pomiaru |
| `claude/605-mixed-load-nasycenie` | `6a749146a` | Refs #605 | kryterium nasycenia, sonda powrotu |
| `claude/1985-podglad-paczki-eksportu` | `06acfbc29` | Refs #1985 | etap 1: podgląd bez zapisu |
| `claude/2050-zdjecia-livewire` | `78d002cb8` | Refs #2050 | tylko test kreatora |
| `claude/2025-brama-ci` | `a8026986d` | Refs #2025 | test workflow bramki wdrożenia |
| `claude/1306-caddy-zaufane-proxy` | `f9ff1caca` | Refs #1306 | test domen IaC |
| `claude/seo-1032-964-1280` | `d53836a98` | Closes #1280 | hak cache mapy strony |
| `claude/713-dlug-weryfikacyjny` | `20bf6a483` | Refs #713 | raport + pomiary przeglądarkowe |
| `claude/triaz-28-30-602-614` | `d0435df95` | Refs #28, #614 | import URL w kolejce; **migracja wymaga przemianowania** (kolizja `2026_09_29_100000` z D) |

**Stan:** integrator E pracował jeszcze w chwili przerwania sesji. Sprawdź `git ls-remote origin claude/paczka-e`. Jeśli gałęzi nie ma albo nie zawiera wszystkich pozycji z tabeli, złóż E od nowa na `claude/paczka-d` według zasad z §8.

## 4. Issues do zamknięcia po scaleniu paczek (z dowodem)

Większość zamknie się sama przez `Closes` w opisie #2206. Po scaleniu sprawdź, które zostały otwarte, i zamknij je komentarzem z dowodem.

| Issue | Dowód (commit / test) | Zamyka |
|---|---|---|
| #2086 | `7c22dd7b5` (#2095) + `e94ce4e4f`; `tests/Dwa/DegradacjaAktoraPrzedRozstrzygnieciemTest.php` | #2206 |
| #2189 | `95e311f37`; `tests/Dwa/PublikacjaPrzepisuPoKarzeTest.php` (15 przypadków) | #2206 |
| #2190 | `86241b630`; `tests/Dwa/UsuniecieKomentarzaPoSankcjiKontaTest.php` | #2206 |
| #2199 | `e08e24644`; `LogowanieApiTest` (kanał `api`) | #2206 |
| #1958 | `de86009c6`, `bfacc4029`; `tests/Dwa/PantryLimit*` | #2206 |
| #1984 | `cfa35f043`, `ddbfb589b`; `CookingModeTest` | #2206 |
| #1318 | `7e814484c`; `WlasneWpisyWFeedzieZastepczymTest` | #2206 (i PR #1567) |
| #976 | `e3ffdbe84`…; `TrybScislyEloquentTest` | #2206 (i PR #1627) |
| #1029, #957 | `515587a64`; `scripts/wydajnosc-progi.mjs`, `KolazPowitalnyPriorytetLcpTest` | #2206 (i PR #1603) |
| #1032, #964 | `15d522257`, `d4386d523`; `MapaStronyPubliczneWejsciaTest`, `RobotsTest` | #2206 (PR #1598, #1533) |
| #1572, #2050 | `e8c45f72b`, `97edcfecd`; `BladZdjeciaPowiazanyZPolemTest`, `ZachowaneZdjeciaPrzepisuTest` | #2206 |
| #1816, #1324 | polityka 2026-09-29, `bb28523fa` | #2206 |
| #836 | PR #1524 (na `main`); `KontekstKontaktuBezSekretowTest` | zamknąć ręcznie; czyszczenie produkcji = właściciel |
| #1743, #2154 | paczka D | #2207 |

**PR-y do zamknięcia po scaleniu** (wchłonięte przez paczki; zamykaj z komentarzem i dowodem, że head jest w `main`):
- PR-y bazujące na INT: 1711 1653 1710 1827 1596 2188 2145 2148 2156 2158 2159 2161 2163 2170 2171 2174 2176 2142 2191–2197 (część mogła się już oznaczyć jako scalona);
- PR-y fali 8 i stare: 1533 1567 1603 1608 1621 1627 1629 1725 1771 1780 1849 1872 1879, 1531 1598 1674 1698 1548 1826, #1823 i #1830 (wchłonięte), #1885 (przez D), #1887 (zastąpiony przez `claude/1958`), #1617 i #1681 (zastąpione przez gałąź polityki).

## 5. Kroki właściciela (panele, produkcja, ludzie)

Nie wykonuj tego za właściciela. Przypomnij mu zbiorczo, jednym pytaniem.

| Obszar | Krok | Issue |
|---|---|---|
| Kontakt | `php artisan kuking:oczysc-kontekst-kontaktu` najpierw **bez** `--wykonaj` (podgląd), potem z `--wykonaj`, po zgodzie | #836 |
| Bramka wdrożeń | Sekret `RAILWAY_TOKEN_PRODUCTION`, zmienne `RAILWAY_PRODUCTION_*`, wymagani reviewerzy środowiska `production` (#1925), wyłączenie autodeploy, potem `KUKING_CI_GATED_RAILWAY_DEPLOY=true`, kontrolowany push. Procedura: `docs/infra/RAILWAY_CI_GATE.md` | #2025, #1925 |
| Proxy | Odczyt domen w Railway (brak `*.up.railway.app`), `KUKING_EDGE_TOKEN` + reguła Cloudflare, doba obserwacji, `KUKING_EDGE_TRYB=egzekwowanie` | #1306 |
| R2 | Lifecycle 1 dzień na `livewire-tmp/`, jurysdykcja bucketu paczek i kopii, nazwa bucketu w `LOKALIZACJA_DANYCH_R2.md` | #2051, #602, #619, #617, #120 |
| Poczta | DMARC `rua` na działającego odbiorcę; Open Tracking EmailLabs wyłączony | #2049, #204 |
| AI | DPA z OpenAI; klucz `OPENAI_IMPORT_KEY` do pomiaru OCR | #2031, #28 |
| Pomiary | `kuking:raport` dla #1015 i #1045; SQL z `scripts/pomiar-841-powroty.sql`, `scripts/pomiar-870-liczba-pytan.sql`; pełna rampa #605 na czystym hoście z korpusem zdjęć | #1015 #1045 #841 #870 #605 |
| Infra P0 | Zrzut i odtworzenie bazy (#594/#193), `railway config apply` (#595), budżet połączeń (#598), monitoring i alarmy (#599), cache Cloudflare (#597/#610) | P0 |
| #713 | 11 kroków w `docs/audits/WERYFIKACJA_713_2026_09_29.md` (telefon, czytnik ekranu, drukarka, `failed_jobs`) | #713 |
| Ludzie | Test z użytkownikami 50+ (#15, #1818), prawnik (#8), pierwsi użytkownicy (#29) | P0 |

## 6. Decyzje, na które czeka właściciel

- **AI:** #813, #814, #815 i #1983 wymagają decyzji D-xxx: cel zgody, D-240 (do OpenAI tylko treść publiczna), DPA, budżet. Plan dla #1983 jest w raporcie robotnika (skrót w `sesja-glowna-2909/REJESTR.md`).
- **#30:** claim „Twoje przepisy nie zginą” — dokumenty rekomendują NIE. Trzeba zapisać decyzję w `DECISIONS.md`.
- **#602:** wariant B (`not planned`) albo C (odłożone z warunkiem wejścia).
- **#1997, #966, #960, #1744:** wcześniejsze pytania (część jest na liście „V2, ale nie teraz”).
- **#599:** alarm mailem — do kogo.
- **AGENTS §12 a planer, D-268 (prawnik).**
- **Kasowanie ok. 60 zbędnych gałęzi.** Lista w `sesja-glowna-2909/REJESTR.md` („ZBĘDNE”). Uwaga: `codex/2059-kontrola-ujemna` to sabotaż poprawki, nie scalać.
- **#1751:** forma zwracania się. Blokuje #1752 i #1753.

**Lista zakazana** (FEATURES „V2, ale nie teraz”; nie budować bez decyzji): #1902 #1903 #1904 #1906 #2000 #1999 #1997 #1996 #2024 #2016 #2067.

## 7. Co dalej (kolejka dla następnej sesji)

1. Dopilnować zielonego CI i scalić **C → D**, potem otworzyć PR paczki **E** (opis wg `.github/pull_request_template.md`) i doprowadzić ją do zieleni.
2. Po każdej paczce: zamknąć issues i PR-y z §4.
3. Kolejne issues do robotników. Kod nietknięty w tej sesji albo tylko rozpoczęty:
   - **#1011:** pełne rozwiązanie leży w PR #1511 na #1478. Oba są `dirty`, a wzorce `oczekuj` obejmują 20 kontroli, gdy w bazie jest już ponad 55. Trzeba przenieść mechanizm na monolit albo odświeżyć katalog i dopisać wzorce;
   - **#28 etap 2:** PDF w kolejce, razem z retencją R2 (#2051);
   - **#1985 etap 2:** ekran, zapis i migracja `odcisk`;
   - **#1731 poziom 4:** 353 błędy. Ciąć według identyfikatorów: najpierw `match.unhandled` i `catch.neverThrown`;
   - **#2149:** cykl modułów;
   - **#970:** walidacja z kontrolerów;
   - **#611:** uproszczenie CI;
   - **#1387:** dalsze etapy kreatora;
   - **#1687:** kolejne etapy;
   - **#988:** reszta kontrolerów, jeśli etap w E jest częściowy;
   - **#906, #873, #1000, #975, #1280** (lastmod przepisu kontra `tresc_zmieniona_at`);
   - **#957:** test pobrań 390/1280 px, opcjonalny.
4. Po wydaniu 0.76 kolejne wydanie (0.77) zrób z paczką E. Commit `release: przygotuj Alfa 0.77` wzorem `589f5256a`.

## 8. Narzędzia i pułapki

Katalog [`sesja-glowna-2909/`](sesja-glowna-2909/):
- `gh.py` — `req(metoda, ścieżka, dane)` do API GitHuba z `$GH_TOKEN`. `FOOT` i `CFOOT` to stopki dla PR-ów i komentarzy. **Zmień identyfikator sesji w `FOOT`.**
- `ci.py <sha>` — stan check-runów commita.
- `stan_pr.py` — przegląd otwartych PR-ów: zielony, czerwony, w toku, mergeable.
- `log.py <job_id>` — pobiera log joba do `../logs/`. Obsługuje przekierowanie do magazynu bez nagłówka auth; zwykły urlopen z nagłówkiem daje 401.
- `prompt-robotnik.txt`, `zadanie-issue.txt`, `prompt-recenzent.txt` — prompty robotników. Zmień w nich BAZĘ na aktualną paczkę.
- `REJESTR.md` (dziennik decyzji i stanu), `AUDYT.md` (audyt Opus gałęzi, PR-ów i issues z 28.09), `ZAMKNIECIA.md` (weryfikacja zamkniętych issues).

**Pułapki, które kosztowały przebiegi CI:**
- **Preflight kotwic kontroli ujemnych** łapie mutacje, które nie pasują do kodu, zanim ruszy jakikolwiek test. Uruchamiaj go po każdej zmianie w tekstach lub plikach, które mutują kontrole. Działa tylko w pamięci:
  ```
  CI=true PYTHONPATH=scripts python3 -c "src=open('scripts/kontrole-negatywne-alfa08.py').read(); exec(compile(src[:src.index('# KONTROLE DODATNIE PRZED MUTACJAMI')],'k','exec'),{'__name__':'x','__file__':'scripts/kontrole-negatywne-alfa08.py'}); print('OK')"
  ```
- **`StraznikTekstuMaKontroleDodatniaTest`**: każdy nowy test czytający źródła musi mieć wpis w `checks` albo `@bez-kontroli-dodatniej`.
- **`KontekstKontaktuBezSekretowTest`**: każda nowa trasa GET z `{token}` lub `signed` musi trafić do `PageContext::SENSITIVE_ROUTES`.
- **CHANGELOG po wydaniu:** gałęzie założone przed commitem wydania po merge'u wrzucają swoje wpisy do świeżo wydanej sekcji. Po każdym merge'u sprawdź `git diff <baza> -- CHANGELOG.md` (dodane linie tylko w „Nieopublikowane”) i to samo w `resources/nowosci/tresc.md` („Najnowsze zmiany”).
- **Znaczniki migracji:** dwie gałęzie z tego samego dnia potrafią dać ten sam `..._100000`.
- **Worktree robotników:** trzeba **skopiować** `vendor` i uruchomić `composer dump-autoload`. Dowiązanie symboliczne ładuje `App\` z głównego checkoutu, więc testy biegną na cudzym kodzie. Testy: `APP_BASE_PATH=$(pwd) php artisan test`. Grupa `dwa-polaczenia` wymaga `php tests/Dwa/bin/przygotuj-baze.php` i bazy `kuking_race_*`.
- **Lokalny klaster PG18** został założony z SQL_ASCII. Naprawa: `template1` odtworzony z `template0` w UTF8 C.UTF-8. Po odtworzeniu kontenera sprawdź kodowanie.
- `HealthPocztaKolejkaIWebhookTest` pada lokalnie z powodów środowiskowych. W CI przechodzi.
- Kontrole ujemne lokalnie wymagają `KUKING_KONTROLE_LOKALNIE=1` i **własnego** portu klastra (nigdy 5432). W praktyce uruchamiaj je tylko w CI, a lokalnie wystarczy preflight.
- Chromium do testów node: `CHROMIUM_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome`.

## 9. Stan na koniec sesji

- `main`: Alfa 0.75 (`11699845a`).
- #2206 (C) head `8ff6b5874`, CI w toku. Poprzednie porażki (preflight R1, StraznikTekstu) naprawione. **Zielone → scal.**
- #2207 (D) head `6a6b9c8cc`, CI w toku. Poprzednia porażka (KontekstKontaktu) naprawiona.
- E: bez PR-a (§3.3).
- Zaplanowane sprawdzenie `send_later` o 07:13 UTC — trigger tej sesji; nowa sesja powinna ustawić własne.
- Agenci tej sesji wszyscy zakończeni poza integratorem E.
