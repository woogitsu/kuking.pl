# Rejestr sesji 01WgxV2k (koordynator od 29.09 ~06:50 UTC)
## W toku (agenci Sonnet, max 10)
- integrator E (paczka-e na paczka-d)
- #2038 #1952 #1814 #1011 #2149 #611 #975 #1000
## Kolejka
#1731→w toku #906 #873 #1280-lastmod #1387-etap #1687-etap #28-etap2 #970(po E, koliduje z 988) #957 #601 #1985-etap2(po E)
## Scalenia
- #2206 C: czeka na CI
- #2207 D: po C
## Do zamknięcia po scaleniu C/D (dowody od robotników)
- #1811: całość w BAZIE (routes/web.php:171 feed-rules, JakDobieramyWpisyMowiPrawdeTest 0cfc6e626, regulamin); gałąź 1811 w BAZIE
- #1952: całość w BAZIE (41fca48c8 dc36c57ef c455dc889 8f3ac687e; OdkrywanieLimitZapytanTest, PublicznyFeedLimitGosciTest); etap 2 = Cloudflare/Railway właściciel; uwaga runbook §10.6 /logowanie vs /login
- #1814: całość w BAZIE (5639e3782 8e451ccd6 e21942d79; MetrykiDoboruTest 6/6)
- #975: kod w BAZIE (c08a554fc dd1b04c35; KluczPreviewSrodowiskaPrTest); zostają kroki Railway właściciela
## Gotowe gałęzie do paczki F (po E)
- claude/2149-cykl-modulow 107162dd8 — Refs #2149 etap 1 (UsuwanieWPartiach → App\Support; cykl 6→5)
## Triaż (ZROBIONE-W-BAZIE → zamknąć po C/D z dowodem)
#2199 e08e24644 | #2190 86241b630 | #2068 5c88805c2 | #2058 e22633c7d | #2037 1a8ea9af5 96d0be0e9 | #2028 1d52cba1e | #1984 cfa35f043 | #1958 de86009c6 | #1572 e8c45f72b | #1318 a6cd0c64f | #1029 515587a64 | #976 TrybScislyEloquentTest | #965 d4386d523 700e0a509 | #830 #829 31ebb46e1 de9079107 | #765 c216116bb | #684 b11d87780
Po wdrożeniu/odbiorze: #372 (flaga QUESTIONS), #35 (VAPID na Railway), #987 (iPhone), #1745
Właściciel/decyzja: #1818 #5 #615 #22 #27(lista zakupów D-310) #1756 #603 #612 #613
- claude/1000-fonty-podzbior 75f3acc7e — Refs #1000 (133→53 kB)
- #2077: w BAZIE 932f43080 (ZaproszenieKontraBlokadaNaDwochPolaczeniachTest)
- claude/611-uproszczenie-ci db042040c — Refs #611 etap 2 (port_panelu → akcja php); dalej port_funkcje, dostepnosc
- claude/2149-cykl-modulow-etap2 8267a143c — Refs #2149 etap 2 (Media→Moderation; raport krawędzi) [zawiera etap 1]
- #873: w main 06d07d6ce (PR #1620 MERGED) — zamknąć (uwaga: brak testu współbieżnego, kompromis P3)
- claude/2038-wymazanie-po-odtworzeniu 802b7a19a — #2038 etap 2 wariant A (gałąź PR #2145, baza PR = codex/integracja; do paczki F)
- claude/1387-stan-kreatora 21f69c4cd — Refs #1387 krok 4 (PodgladPrzepisu); claude/1387-kreator-krok3 = zbędna (dubel 76c9384de)
- claude/957-zadanie-przegladarka eddc1b772 — Refs #957 (test CDP 390/1280; krok w ci.yml — możliwy konflikt z 611)
- claude/611-uproszczenie-ci-etap3 1ac4cf0de — Refs #611 etap 3 [zawiera etap 2]; zostaje zakres→plik, preview.smoke
- #906: na main (PR #1213 cc4f0e44d, D-070) — zamknięcie ZABLOKOWANE przez klasyfikator; czeka na zgodę właściciela (razem z #873)
- claude/1280-lastmod-przepisu ea78064bd — Closes #1280 (nadbudowa seo-1032-964-1280) → do E
- claude/1387-mapowanie-publikacji 0b95cc003 — Refs #1387 krok 5 [zawiera krok 4] (DanePublikacji; test #[Locked] recipeId)
## Recenzja F (07:25): GOTOWE 2149-etap2, 1000, 611, 1387-stan; 2038 DO POPRAWY (przekazane robotnikowi etapu 3). Konflikt 1000×2038 tylko CHANGELOG. Kolejność: 611,1387,2149,1000,2038.
Drobne: config/kuking.php:3180 komentarz UsuwanieWPartiach (2149); docs/design/system-v3.1/CZYTAJ-NAJPIERW.md:61 rozmiary fontów (1000)
- claude/2038-wymazanie-po-odtworzeniu f57b02926 — etap 3 alarm po N nocach; blokery recenzji wysłane (czekam, czy robotnik wznowił)
- claude/611-ci-etap4 0f1db1490 — Refs #611 etap 4 [zawiera 2,3] (duplikat /otworz-link, porażki w check.sh)
- claude/1687-etap5 083cb13da — Refs #1687 etap 5 [na 1687-etap4 z E]
## Paczka E: claude/paczka-e 5f3ab1c77 (24 gałęzie + 1280-lastmod). Closes #1969 #2205 #2031 #1046 #1932 #1280; Refs #581 #492 #2049 #2178 #2051 #1687 #1860 #2130 #988 #841 #870 #605 #1985 #2050 #2025 #1306 #713 #28 #614. Po scaleniu zamknąć #973 #989 #971 #1377 #986 #1378. Migracja triaz → 2026_09_29_120000. Rejestr czynności OpenAI → §3.23. 6 testów PDF czerwonych lokalnie (brak poppler).
- claude/2149-cykl-modulow-etap3 d81ef7ea9 — Closes #2149 (zero cykli) [zawiera 1,2]; DziennyBudzetListow → App\Poczta (33 pliki — ryzyko konfliktu z E)
- claude/1687-etap6 c4b81e614 — Refs #1687 etap 6 [zawiera 5]
- claude/2038-wymazanie-po-odtworzeniu d4adf1ed1 — blokery recenzji naprawione (etapy 2+3 kompletne) → F
- claude/970-kontroler 844418c91 — Refs #970 krok 3 (ZapisWpisuRequest) [baza paczka-e]
## CI #2206 CZERWONE (07:13): job kontrole negatywne anulowany po 40 min (153/208 kontroli). Przyczyna: przyrost checks (main 175 → C 208 → E 219). Naprawa = podział kontroli na części w macierzy. Push na claude/paczka-c ZABLOKOWANY przez klasyfikator → pytanie do właściciela.
## Recenzja E: bloker ImportStraznikAdresowTest:242 (komunikat ZGODA_AI_NIEAKTUALNA) → robotnik claude/2031-komunikat-zgody
## Po restarcie kontenera (raporty z plików)
- claude/1731-phpstan-poziom-4-etap1 e3a685946 — Refs #1731 (51 błędów poz.4; ratchet phpstan-etap4.neon + scripts/phpstan-wyczyszczone.sh w CI; 342→288)
- claude/1011-oczekiwana-przyczyna-v2 8e7ac1792 — Refs #1011 (werdykt z JUnit, wzorce dla 213 kontroli; WYMAGAJ_WZORCA=False) — koliduje z podziałem kontroli (ten sam skrypt)
- Recenzja 2: GOTOWE 957, 611-etap3, 1387-mapowanie, 1280-lastmod
- claude/1687-etap7 44ca148aa — Refs #1687 (różnice lista/Policy zamierzone, utrwalone testem)
- claude/2031-komunikat-zgody 2902934b3 — bloker E naprawiony → scalić do paczki-e
- claude/1985-import-etap2 8dfe16a28 — Refs #1985 (migracja 2026_09_29_140000 wczytane_z_paczki; nowa funkcja) [na 1985-podglad]
- claude/28-import-pdf-etap2 d6032551a — Refs #28 (PDF w kolejce; migracja 2026_09_29_150000; konflikt z 2031 w pdf() kontrolera!) [na triaz]
- claude/611-ci-etap5 bc6ad1a37 — Refs #611 (zakres → scripts/ci/zakres.sh; zawiera 957 i etapy 2-4)
## Właściciel 29.09: ZGODA na push na claude/paczka-c/-d/-e (bez force)
## 07:50 #2208 (handover) SCALONY do main (e1e63ba65). W toku: naprawa C (podział kontroli), integrator F (na E), 1731e2, 970k4, 1387 pola, 1985e3, analiza V2.
## C 158a2d2d1: podział kontroli na 3 części (CI w toku). Propagacja C→D→E zlecona.
- D 8243b6887, E 6bb26829e (z poprawką C)
- claude/1011-oczekiwana-przyczyna-v2 82976ad0e — scalona z E (podział + werdykt JUnit); 6 wpisów bez wzorca (WYMAGAJ_WZORCA=False) → do F po recenzji
- #2207 czerwone: AUDYT.md (z #2208 na main) ma czterocyfrowy numer roboczy decyzji → strażnik #2154. Naprawa: merge main do D + przepięcie; propagacja do E.
- claude/970-krok4 0564df28c — Refs #970 krok 4 (EdycjaWpisuRequest, KomentarzRequest; zawiera krok 3 + E)
- D 3bdb828ab (merge main + AUDYT D-329), E 68f8cd279
- claude/1387-pola-w-formularzu befebccd9 — Refs #1387 krok 6 (pola w PrzepisForm, WERSJA_STANU 4, kontrakt z ZapisPrzepisuRequest) [zawiera 4,5 + E]
## 09:20 #2206 (C) SCALONY do main (bd70e2ecb). D: merge main → d4ee351cf (drzewo = zielone 3bdb828ab), CI w toku → scalić. 
- claude/1985-import-etap3 aec79730a — Refs #1985 (sprzątanie 03:30, pytania pomijane) [ma merge paczki D]
- Limit sesji 08:0x–09:10: przerwane F-integrator, 1731e2, 970k5, rec3 → wznowione
- Klaster PG kontrole: port 55439 (user kuking/kuking, superuser) — do przebiegów mutacji lokalnie
- claude/drobne-po-recenzji f72effd91 — drobne (nazwy klas w komentarzach, fonty w docs, docblock) → na koniec F
- claude/1687-domkniecie fd38fbe6e — #1687 kryteria 1–8 spełnione (Closes po akceptacji); kontrola ujemna eksportu ZABLOKOWANA przez klasyfikator (usunięcie visibleTo w CollectUserExportData) → dla właściciela ręcznie
- claude/1387-zdjecia-kreatora decf5cb97 — Refs #1387 krok 7 (ZdjeciaKreatora) [zawiera 4–6]
- claude/970-zgloszenia f300b9b86 — Refs #970 (ZgloszenieTresciRequest; NotificationController bez walidacji)
- claude/970-onboarding dd8c819b9 — Refs #970 (OnboardingController 436→176; 3 FormRequesty + 2 akcje)
- claude/1387-nawigacja-kreatora 2dbe4b7d9 — Refs #1387 krok 8 (NawigacjaKreatora; utajony bug ingredients/sprawdzilemOdczyt → Naprawione)
- 09:50 DYSK był pełny (51 worktree × vendor) → usunięto 36 czystych worktree, 55% zajęte
- claude/970-profil fde4e450f — Refs #970 (ProfilRequest bez reguł; brak walidacji inline)
- claude/970-krok5 a79aba727 — Refs #970 (CollectionController 1157→1049; 4 FormRequesty; ZeszytyNieRosnaOdHttpTest)
- #1035: zrobione w E (225535e79 WejdzPrzezDostawce, WejdzPrzezDostawceTest) → zamknąć po E
- #611 etap 6: claude/611-ci-etap6 215658a2a (alarm_bez_sukcesu w deploy.yml) [zawiera E]
- #1387 krok 9: claude/1387-autozapis-kreatora 9c4c84c1d (AutozapisKreatora, StanZapisu, RewizjaTresci; bug publish→krok 3 naprawiony)
## 09:55 #2207 (D) SCALONY (9baab92c6). E: + 2031-komunikat-zgody + main → 6ab35bada
- claude/970-krok6 39701518 — Refs #970 (saveRecipe → SaveRecipeToCollection::zapiszAlboPrzywroc) [zawiera krok5]
## PR #2209 = paczka E (6ab35bada) otwarty 09:58, obserwowany
- claude/970-transakcje 25f612add — Refs #970 (ZamknijGrupeSygnalow, ZuzyjLinkDoLogowania)
- #2209 CI czerwone (nasycenie-605.test.mjs poza build; Larastan x2) → naprawa w toku; CodeQL naprawione 6165b5b46
- claude/1731-phpstan-poziom-4-etap2 09915979f — Refs #1731 (nullCoalesce.*, deadCode, booleanNot; 294→257) [na etap1 + E]
## 10:20 Właściciel: zgoda na \R w teście (zrobione 386a60270 na E) i na zamykanie issues (zamknięte: 1811 1952 1814 975 2077 873 906 2058 684). #836 zostaje (produkcja).
- F 00f603ef1 (integrator) → naprawa blokerów rec3 + merge E + 1731e3 + 970k7 w toku
- claude/1731-phpstan-poziom-4-etap3 2da56a403 (257→192); claude/970-krok7 75f5fc27c
- E 859add86a: release Alfa 0.77 (CHANGELOG, tresc.md, config). Build obrazu: nasycenie-605 wymaga docs/ (dockerignore) → naprawa w toku
- claude/1387-markup-podgladu 767d0be63 — Refs #1387 pkt 5 (podgląd w x-kreator.podglad-przepisu; HTML identyczny) [zawiera 4–9]
- E fd96f82b0: dowody 605 kopiowane do scripts/fixtures/obciazenie605 (build obrazu)
- claude/970-2fa-zaproszenia 6552d37e9 — Refs #970 (2FA: 3 FormRequesty + WlaczDwuetapowa, WygenerujNoweKodyZapasowe; zaproszenie)
- E 16921d541: filtr obciazenie + scripts/fixtures/obciazenie605/; rampa-obciazenia-605.sh python3→node
- claude/1731-phpstan-poziom-4-etap4 b1508334a — Refs #1731 (nullsafe.neverNull 137→0; poziom 4: 192→50) [zawiera 1–3]
- claude/1011-oczekiwana-przyczyna-v2 94943322a — 6 wzorców + WYMAGAJ_WZORCA=True + --tylko; część 1/3 lokalnie 73/73; 2/3 i 3/3 niesprawdzone → weryfikacja; do F dopiero po
## F 503ca6818 gotowa (blokery rec3 naprawione, +1731e4, 970k7, 1387 markup, 970 2fa). Czeka na scalenie E → PR F
## 11:55 #2209 (E, Alfa 0.77) SCALONY de27cbddf. PR #2210 = paczka F (503ca6818), obserwowany.
## 12:30 F: 88f00a944 (kontrola sufitu), 8b9f7c4d8 (LoginLink bez getMessage, iac phpstan-etap4), 8dff9f624 (DECISIONS ścieżki DziennyBudzetListow — do wiadomości właściciela; UzasadnienieDecyzji; CI_611 trasa). Limit API zresetowany przez właściciela. Wznowione: 1731e5, 1011 cz.2–3; nowy: pełny zestaw testów na F.
## 12:55 F c92272c94 (wzorzec autozapisu varying(\d+)). Właściciel: „kontynuuj na 10 agentów sonnet non stop”. Prompt robotnika → prompt-robotnik-f.txt (BAZA = paczka-f).
- claude/1731-phpstan-poziom-4-etap5 b961d4eaf — Closes #1731 (level 4, ratchet usunięty, bug CorrelationServiceProvider → bootstrap/app.php) [na etap4, NIE na F] → do G po recenzji
- 12:58 zlecone: 2178, 2130, 2050, 2025, 988, 987, 1306, recenzja 1731e5
- #987: kod gotowy na F (D-260, BezpiecznyObszarMaJedenKontraktTest) — zostaje odbiór na iPhonie (WŁAŚCICIEL), potem D-260 → obowiązuje i zamknięcie. 13:0x zlecone #581
- #1306: kod gotowy na F — zostają kroki WŁAŚCICIELA (C6 w docs/infra/LISTA_KROKOW_ALFA.md: domeny Railway, KUKING_EDGE_TOKEN, reguła CF, pomiar XFF, egzekwowanie). Zlecone #970 kolejny etap
- #988 ZAMKNIĘTE (na main). #2025: kod gotowy — zostają kroki WŁAŚCICIELA (sekret RAILWAY_TOKEN_PRODUCTION, zmienne RAILWAY_PRODUCTION_*, wyłączyć autodeploy, KUKING_CI_GATED_RAILWAY_DEPLOY=true, test pushem)
- ZAMKNIĘTE #2050 #2178 (na main). #2130: kod na main (e17840607, 2fbcf353d), luka „starszy import nie nadpisze nowszego” wymaga monotonicznej wersji danych → DECYZJA WŁAŚCICIELA, zostaje otwarte
- 13:1x zlecone: 1387 markup kroków 1–3, 970 CookedEventController, audyty A (2038 2049 2051 1000 611 1687), B (1045 1015 870 841 372 1860 713 492), C (funkcje V2 — ranking)
## 13:35 F 13dd5faf8: kontrola autozapisu na jednym teście (test_pierwszy_autozapis...), font: drugi objaw — lokalnie POTWIERDZONA obie
- claude/581-panel-moderacji-etap 4060d3fb8 — Refs #581 („Zdejmij z urzędu” w mierniku panelu, zdania o skutku 18px) → G
- Audyty: #2038 #1687 → zamknąć po #2210 (closing refs). #1000 → po #2210 pomiar artefaktu wydajności. #611 dalej etapy. #2049 #2051 #1045 #1015 #870 #841 #713 #492 #372 → kroki/pomiary WŁAŚCICIELA. #1860: zostaje tylko #1030 (okno zamknięcia 130 s vs eksport 900 s) → DECYZJA. #22 #1756 #27(lista zakupów) → DECYZJA. #1996 #1997 #2000 #2024 #2016 → „V2, ale nie teraz”.
- 13:35 zlecone: 27 pomiar planera, 1818 protokół, 611 etap 7, weryfikacja zamknięć 1985/28/35
- claude/1818-protokol-badania df2a5da11 — Refs #1818 (docs/product/PROTOKOL_BADANIA_1818.md; propozycje do zatwierdzenia: wariant środowiska A, prowadzący ≠ właściciel, retencja 90 dni) → G. Zlecone #970 HealthController
- claude/27-pomiar-planera f58918e6c — Refs #27 (PlanDoUgotowania w kuking:raport, okno +3 dni, próg 20) → G. Zlecona recenzja G1: 581, 1818, 27
- claude/970-ugotowalem 70706b01d — CookedEventController 569→325 (269 testów zielonych) WSTRZYMANE: poza zawężeniem #970 z 28.09 → pytanie do właściciela. HealthController poza zakresem #970 (nic nie zrobiono). Zlecone: 970 domena bez Illuminate\Http (zgodnie z komentarzem 28.09)
- #35 ZAMKNIĘTE (na main). claude/35-test-kryterium 651f5f843 (test sw.js push, Refs #35) → G.
- #28 i #1985 → zamknąć PO #2210 (teksty komentarzy: raport agenta aefec938; 3f5631b53 PDF i 8dfe16a28/30b703bbc tylko na F). #28: brak fallbacku mikrodanych → zlecone.
- claude/611-ci-etap7 c9470a64b — Refs #611 (preview_bramka: pominięcie smoke z powodem; KROK WŁAŚCICIELA: KUKING_DEPLOY_ENABLED=true dokładnie; wymagane checki → preview_bramka) → G
- claude/1731-etap5-na-f 42b5b553a — GOTOWE (Closes #1731; konflikty z F, 6 nowych błędów poz.4 naprawione, StanTransakcjiWymazania) → G. Zlecona recenzja G2: 611-ci-etap7, 35-test-kryterium
- claude/970-postcontroller b7f27a106 — Refs #970 (PostController 927→514; 1155 testów zielonych; w kryteriach issue) → G po recenzji
- claude/970-domena-bez-http e43e8866b — Refs #970 (Collections bez Request, DomenaNieZalezyOdHttpTest; 4 wyjątki Request zostają → zlecone 970-domena-bez-http-2)
- claude/paczka-g-kandydat d7c84b7cb = F + 581 + 1818 + 27 (recenzja G1 GOTOWE, 232 testy). Do dołożenia: 1731-etap5-na-f, 611e7 i 35-test (po G2), 970-postcontroller (po recenzji), 970-domena-bez-http(-2), 1011-v2, 28-mikrodane, 1387 kroki, 1000-pomiar, kroki-wlasciciela
## 14:30 G kandydat e2a430920 = F + 581 + 1818 + 27 + 1731e5 + 611e7 + 35-test + 970-post(3961959) + 970-domena(-2, Closes #970) + 28-mikrodane + 1387-markup-krokow + 1000-pomiar + kroki-wlasciciela; fix tokenExists (fasada) + wyjątek UploadedFile ZbierzZdjeciaFormularza; PHPStan poz.4 = 0
- 1000: docs/pomiary/1000-fonty-przed-po.md (−59,8% bajtów, font −0,34 s Slow4G, CLS bez zmian) → zamknąć #1000 po main
- Zlecone: recenzje 970-domena(-2), 28-mikrodane, 1387-markup-krokow; pełny zestaw testów na G
## 14:40 #2210 (F) SCALONY do main 734bed9c6. Auto-zamknięte: #2149 #2038 #1687. Zamknięte ręcznie: #1985, PR #2145 (wchłonięty). Po G: #1731 (Closes), #970 (Closes w 970-domena-bez-http-2), #28, #1000.
- claude/611-ci-etap8 430c27d6f — Refs #611 (bramka: idempotencja SHA, starsza próba/SHA → Skip; RYZYKO meta.commitHash niezweryfikowane) → weryfikacja API Railway zlecona
- Recenzja bezpieczeństwa 970-domena(-2) a0f910f42: GOTOWE (logowanie/sesja/IP/walidacja identyczne; brak worker mode). /health w WdrozenieWejsciaFacebookiemTest oblewa lokalnie przez AWS_* w powłoce (środowisko).
## PR #2211 = paczka G (fb8350a9a po poprawkach recenzji 28 i 1387); obserwowany. 611e8 + 1387 walidacja (Closes #1387) w G.
## DECYZJE WŁAŚCICIELA 29.09 (klik):
- D1: TAK, plan płatny Railway — „hobby albo pro, raczej pro (backup i inne funkcje); poszukaj, co Pro ma do wykorzystania”
- D2 (#2130): TAK, wersja rosnąca słownika
- D13: SCALIĆ claude/970-ugotowalem do kolejnej paczki (po recenzji)
- D14: osobne issue HealthController, najpierw test kształtu /health
- D11 (#599): alarmy → Discord, JUŻ działa (zmienna w Railway); ostatni alarm 29.09 14:28 „wolna baza, 1330 ms zapytań SQL” → sprawdzić
- D7 (#1751): TAK, forma neutralna domyślna, bez czekania na prawnika (prawnik: jedno zdanie w polityce)
- D3: okno 130 s zostaje na alfę
- D4 (#22): P3 po bramce WAC/D30
## 14:51 G: a3041d07a (\R w teście architektury) + b840b1d13 (miernik panelu: ekran poza menu; lokalnie P581 PASS 720) wypchnięte. GitHub miał awarię 503.
- D8: nic nowego w AI, DPA z OpenAI podpisać (właściciel); D9: claim NIE do odtworzenia; D10 (#602): odłożyć z warunkami; D12: DMARC → Cloudflare Email Routing
- claude/health-kontrakt 249ea7628 — test kształtu /health (13 testów), uwaga: /health w grupie web zakłada sesję (Set-Cookie)
- claude/railway-pro-wykorzystanie 2cebe3e4a — docs/infra/RAILWAY_PRO_WYKORZYSTANIE.md (Pro zalecany; backupy Railway UZUPEŁNIAJĄ offsite)
- claude/2130-wersja-slownika dfb11d98a — Closes #2130 (D-330, WERSJA, porównanie pod blokadą)
- Alarm Discord 14:28 PL = 12:28 UTC: GET / 1330 ms i 12:40 UTC 1116 ms, po restarcie po wdrożeniu 0.77.001; po F brak → agent profil zapytań
- #2212 = HealthController (issue założone)
- D15: ODBLOKOWANE wszystkie z „V2, ale nie teraz”: #1997 #2000 #1996 #2024 #2016 (koordynator robi jeden wpis FEATURES.md + D-331)
- D16: akceptacja ścieżek DECISIONS (8dff9f624). D17: #1687 bez ręcznej kontroli. D19: kasować zbędne gałęzie po sprawdzeniu scalenia
- claude/970-ugotowalem-na-g 31b58ac9a — GOTOWE (recenzja: authorize w kontrolerze dla cooked.comment/thank, wyjątek UploadedFile ZbierzZdjeciaWykonania) → paczka H
- claude/v2-odblokowanie 1e1a7377e — D-331 (FEATURES.md + DECISIONS.md) → H
- D19: NIE usunięto (push --delete → 403, sesja nie ma prawa kasowania gałęzi); codex/2130-kontrola-ujemna scalona — do skasowania przez właściciela; 7 z listy już nie istniało; 5 nie scalonych zostaje (lista dla właściciela)
- claude/1997-zakresy-czasu 2c4785f8b — Closes #1997 (czas=15/30/60, stary szybkie działa; pomiar przeglądarkowy NIE uruchomiony) → H po recenzji
- claude/2000-udostepnianie-zeszytu 4ad762287 — Closes #2000 (wspólny zeszyt D-302 bez przycisku — interpretacja) → H
- claude/1996-kalorie-jsonld f89a04349 — Closes #1996 → H
- G lokalnie 4d…: wzorzec „Domena importuje Request” + drugi objaw (czeka na push po Panel marki)
- G fc0c8fdfe wypchnięte (wzorzec Request). claude/1751-forma-zwracania 3aa1e0473 — Closes #1751 #1752 (#1753 częściowo), D-332; polityka zmieniona BEZ podbicia wersji → pytanie do właściciela; migracja 2026_09_25_140000 (starszy timestamp) → recenzja
- claude/paczka-f-poprawki 40e65ba3a — 2 wzorce kontroli (BezpiecznyKomunikat w logu: drugi objaw „insert into”); lokalnie ZLA_PRZYCZYNA, w CI main zielone → do H (alternatywa, nie osłabia). Pełny zestaw F: tylko porażki środowiskowe.
