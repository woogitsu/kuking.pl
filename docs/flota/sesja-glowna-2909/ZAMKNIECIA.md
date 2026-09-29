# Weryfikacja zamknięć issues — stan `origin/main` @ 00c9a12f3 (28.09.2026)

Tryb: tylko odczyt (treść z `git show/log/archive origin/main`, GitHub API tylko GET). Wszystkie wskazane SHA sprawdzone przez `git merge-base --is-ancestor <sha> origin/main` (przodek main). CI `push` na main @ 00c9a12f3: success (run 36465941156).
PR #2198 (`origin/claude/amazing-gauss-i1qh2a`, 183 commity przed main) nie zawiera poprawek żadnego z 59 issues z tej listy, więc werdykt „PO #2198” nie wystąpił. Test `SesjaPoUniewaznieniuNieWracaTest` na #2198 jest identyczny jak na main.

Legenda: **ZAMKNĄĆ** = wszystkie kryteria z treści i komentarzy spełnione na main. **ZAMKNĄĆ\*** = jak wyżej, ale z jedną drobną uwagą (zapisana w kolumnie „dowód” i w komentarzu), którą koordynator może zaakceptować albo wydzielić. **NIE** = brakuje konkretnego kryterium. **WŁAŚCICIEL** = kod jest, zostaje decyzja/akcja właściciela.

Podsumowanie: 59 issues → ZAMKNĄĆ 49 + ZAMKNĄĆ\* 4 (razem 53), NIE 4, WŁAŚCICIEL 2, PO #2198 0.

## Tabela

| issue | werdykt | dowód (1 linia) |
|---|---|---|
| #836 | WŁAŚCICIEL | b71d377dc, 5bf0d531a (PR #1524, 6b51890b5): `PageContext::clean()` (app/Domain/Contact/PageContext.php:37) w `PrzyjmijWiadomosc.php:68` i `NapiszDoNasController.php:67`, `KontekstKontaktuBezSekretowTest` (7 testów); zostaje decyzja właściciela o uruchomieniu `kuking:oczysc-kontekst-kontaktu --wykonaj` na produkcji |
| #1046 | NIE | Mechanizm jest (`users.session_generation`, `SprawdzGeneracjeSesji`, f460491ac), ale `tests/Feature/SesjaPoUniewaznieniuNieWracaTest.php:178-186` ma 6 operacji; brak wyścigu dla: potwierdzenia zmiany e-maila, włączenia 2FA (trasa `auth` i `/admin/**`), awansu roli (z 2FA i bez) — kryteria z komentarzy 25.09 i 28.09 |
| #1974 | ZAMKNĄĆ | c7f4674a8 (`Closes #1973 #1974 #1977 #1980`): `RozliczenieOdczytu` + księga `ai_rezerwacje`; `tests/Feature/Import/MaszynaStanowOdczytuTest.php::test_1974_*` (2 testy) |
| #1977 | ZAMKNĄĆ | c7f4674a8: dispatch w transakcji zlecenia + `kuking:odzyskaj-importy` (routes/console.php:366); `MaszynaStanowOdczytuTest::test_1977_*` (2 testy) |
| #1980 | ZAMKNĄĆ | c7f4674a8: zapisana odpowiedź zamyka etap, sufit `PROBY_MODELU`; `MaszynaStanowOdczytuTest::test_1980_*` (3 testy) |
| #2033 | ZAMKNĄĆ | ae0d1a491, cdb4c6441, 9148b7326 (PR #2162, 250c75e8b): `components/zgoda-odczyt-ai.blade.php` użyty w `settings/privacy.blade.php:123`; `Zgody/InformacjaPrzedZgodaOdczytuAiTest` (5 testów); brak liczby dni retencji OpenAI (zgodnie z decyzją 28.09) |
| #2066 | ZAMKNĄĆ | 04be07ad8 (PR #2132), c1ff80260: transakcja + `catch` w `AlarmujOPilnymZgloszeniu.php:100-150`; `PilnyAlarmZgloszeniaPrawnegoPoAwariiTest::test_ponowienie_formularza_po_awarii_kolejki_wysyla_jeden_alarm`, `KolejkaModeracjiStawiaPilneNaGorzeTest` (nazwa `PilnyAlarmDwaPolaczeniaTest` z audytu nie istnieje) |
| #994 | ZAMKNĄĆ | e83042e8b (PR #1499): `resources/legal/polityka-prywatnosci.md:36` (Railway, do 7 dni), `docs/DEPLOYMENT.md:65` (decyzja 24.09), `Feature/PolitykaOpisujeRetencjeDziennikaSerweraTest` |
| #1746 | ZAMKNĄĆ | fdaa97c3d: `WidocznoscTresciSql`; macierz `Feature/Visibility/PowiadomieniaZgodneZPolicyTest` (stan „karencja”, brak tolerowanego rozjazdu), kontrola ujemna `scripts/kontrole-negatywne-alfa08.py:540` |
| #1747 | ZAMKNĄĆ | 3a0c40108: bramka przepisu zapowiedzi w `WidocznoscTresciSql`; cel `zapowiedz` w macierzy (test:271-279), kontrola ujemna `kontrole-negatywne-alfa08.py:551` |
| #1807 | ZAMKNĄĆ | 510d8e1bc, 20de4c01c, a6cd0c64f: `DiscoverFeed.php:83` `row_number() … runda`; `OdkrywanieRotacjaAutorowTest` (12 testów, 10×5=50 kart), `OdkrywaniePustyStanZWyjsciemTest`, D-276 |
| #1808 | ZAMKNĄĆ | d00aa31ef, 417755500, 7c339e708 (PR #1831, 21ffa4614): `FollowingFeed` = osoby + tagi, podpis „Z tagu: …” (słowo „tag” wg JednoSlowoNaTagiTest), D-277 zmienia D-021, `TagiObserwowanieTest` (widoczność, blokady, brak duplikatów) |
| #1809 | ZAMKNĄĆ | 9697abb79 (PR #1832, e6e0ca652): `post-card.blade.php:255-285`, `SkrotyObserwowania::NAJWYZEJ_TAGOW=2`, komunikaty w `SocialController.php:68`; `SkrotyObserwowaniaWMenuTest` (6 testów) |
| #1810 | ZAMKNĄĆ | e74d5ba7b (PR #1833), abcb34956: migracja `2026_09_26_100000_create_hides_table.php` (rollback odmawia, D-088), `docs/DATABASE.md:1381`, `UkryjWpisIOsobeTest` (12 testów, w tym eksport, moderacja, „Ugotowałem”), `settings/ukryte.blade.php` |
| #1813 | ZAMKNĄĆ | f79a3080f, dfbf475d6, 2b5cd32a7: migracja `2026_09_26_110000_create_post_reactions_table.php`, `routes/console.php:74` `kuking:powiadom-smakowicie`, `SmakowicieWygladaTest` (10 testów, w tym eksport, rollback), D-280 |
| #1927 | ZAMKNĄĆ | e25f22125, dd784e447: `deploy.yml:173-175,271-272` przez `env:`; `DeployNieWklejaDanychZdarzeniaDoPowlokiTest`, `WorkflowyNieWklejajaDanychUzytkownikaDoRunTest` (wszystkie workflow) |
| #2061 | ZAMKNĄĆ | 554c75ab9, 2548aee5b: `tests/Dwa/OpoznionyEkran2faNieNadpisujeSekretuTest.php` (5 testów, dwa procesy) |
| #2064 | ZAMKNĄĆ | a440066b3 (+ `ZlecImportPrzepisu::ponow():92-125` pod blokadą szkicu): `tests/Dwa/PonowienieImportuPrzezHttpNaDwochPolaczeniachTest`, `PonowienieImportuNaDwochPolaczeniachTest`, `Feature/Import/PonowienieOdczytuLiczySieRazTest` |
| #1806 | ZAMKNĄĆ | 407c04ed4: `AGENTS.md:506` (§8), `:789` (§12), D-275 (`DECISIONS.md:17699`), D-194 sprostowany; `FeedNieSortujePoMierzeReakcjiTest` skanuje `app/Domain/Digest`, kontrola ujemna `kontrole-negatywne-alfa08.py:465-471` |
| #1944 | ZAMKNĄĆ | e0bdb769b (PR #1951, bf4555bd5), PR #1981 (c1b62dee1): `scripts/przegladarka/klawiatura-belki.test.mjs:51-53,148` (844×390, 768×500, 100% i 140%); CI push na main zielone |
| #2021 | ZAMKNĄĆ | 512307aa5, fe53241ce, 8f95adf44 (PR #2160): `WyslijPowiadomieniePush::$grupaId`, `Feature/PushDuzaGrupaTest` (8 testów), plany EXPLAIN `docs/infra/pomiary/2021/plany-przed|po.txt`; grupa bez limitu liczby zgodnie z decyzją 28.09 |
| #2112 | ZAMKNĄĆ | f3b8bcfdf (PR #2127): `UstawWidocznoscWartosci.php:30-55` (`lockForUpdate` + Gate na świeżym wierszu); `tests/Dwa/WartosciOdzywczePoModeracjiTest.php` (hidden, removed, soft delete + kolejność odwrotna) |
| #2050 | NIE | Poprawka tylko na gałęzi `origin/claude/2050-zdjecia-po-bledzie` (97edcfecd „etap 1: bramka”, poza main i poza #2198); na main brak uploadu tymczasowego dla `hero_photo`/`source_scan`/zdjęć kroków w formularzu przepisu |
| #2072 | ZAMKNĄĆ | fe42beea9, d7150506e (PR #1899): `LimitImportowOsoby::rezerwuj()` (`pg_advisory_xact_lock`); `tests/Dwa/WspolnyLimitImportuNaDwochPolaczeniachTest` (4/5, 29/30, kontrola dodatnia i ujemna) |
| #2048 | ZAMKNĄĆ | cf7f90bab: `scripts/railway-ci-gated-deploy.py::deploy` (rerun `GITHUB_RUN_ATTEMPT>1` bez mutacji, niejednoznaczny wynik = stop), `test_lost_mutation_response_and_rerun_never_repeat_deploy`, `docs/infra/RAILWAY_CI_GATE.md` |
| #769 | ZAMKNĄĆ | 891fecc01 (+ a4f6a0af): `cooked-card.blade.php:81`, `cooked/celebrate.blade.php:39`; `Feature/ZdjecieWykonaniaOpisZastepczyTest` (6 testów) |
| #871 | ZAMKNĄĆ | 4c267174f: `Domain/Media/ZachowaneZdjecia.php`; `Feature/ZdjeciaFormularzyPoBledzieTest` (wpis, pytanie, nie-UUID, zagnieżdżone) |
| #872 | ZAMKNĄĆ | 4c267174f: `CookedEventController` zapisuje zdjęcia przed walidacją; `ZdjeciaFormularzyPoBledzieTest::test_ugotowalem_*` (przez dwa błędy, usunięcie, tylko własne) |
| #874 | ZAMKNĄĆ | 4c267174f: `error-summary` z mapą `'photos.*' => 'f-photos'` (posts/cooked/questions); `ZdjeciaFormularzyPoBledzieTest::test_blad_pojedynczego_pliku_*` (3 formularze) + `test_indeksowane_pola_bez_wzorca_*`. Powiązanie `aria-describedby`/`aria-invalid` wydzielone do #1572 (otwarte) |
| #934 | ZAMKNĄĆ | 73e50be97 + `ZachowaneZdjecia`; `Feature/KolejnoscZachowanychZdjecPoBledzieTest` (wpis, odrzucone ID, pytanie) |
| #946 | ZAMKNĄĆ | 7648b7570: `Support/NawigacjaOsobista`; `Feature/NawigacjaWlasnosciTest` (3 warianty nawigacji, własny/cudzy zeszyt, profil i listy) |
| #988 | NIE | 7648b7570 dodaje `Support/Komunikat` (sukces/informacja/błąd) tylko dla znanych odmów (40 wywołań); ~146 gołych `->with('status')` nadal domyślnie „sukces” (`Komunikat.php:22-24`), API nie wymusza typu, brak klasyfikacji pozostałych zapisów i odbioru NVDA/VoiceOver (kryteria z treści) |
| #975 | WŁAŚCICIEL | c08a554fc: `docker/klucz-preview.sh`, `KluczPreviewSrodowiskaPrTest` (natywny + `--copy staging`), runbook `DEPLOYMENT_RUNBOOK.md` ~2156; kryteria 1–2 (smoke `/health` i `/` na żywym PR Environment) niesprawdzalne statycznie: `preview.yml` daje na PR-ach `skipped` |
| #982 | ZAMKNĄĆ | d8b7ee7ea: `EditComment` (odcisk treści `wersja` pod blokadą); `Feature/PoprawkaKomentarzaDwieKartyTest`, `tests/Dwa/PoprawkaKomentarzaNaDwochPolaczeniachTest` |
| #984 | ZAMKNĄĆ | c0d5a840e: `SearchController` (`ile_przepisow`, `ile_osob`); `Feature/PokazWiecejRozszerzaJednaListeTest` |
| #1023 | ZAMKNĄĆ\* | 7b0a11c58: kursor rankingu `po_przepisie`/`po_osobie`; `Feature/StabilneOknaWyszukiwaniaTest` (7 testów, kontrola ujemna w skryptach). Uwaga: komentarz 22.09 o kosztach skrajnego `od_*` (`SearchController::offset()` nadal do `PHP_INT_MAX-200`) nie jest zamknięty — poza kryteriami z treści |
| #1037 | ZAMKNĄĆ | ee268ac33: `Post::scopeDlaKarty()`; `Feature/KartaWpisuJednymKontraktemTest` (preventLazyLoading na 7 listach, stała liczba zapytań 2 vs 8 kart, kontrole ujemne) |
| #1289 | ZAMKNĄĆ | 3c03eb002: `landing.blade.php:180-195,277` (pomoc, wyszukiwarka, odkrywanie zamiast tras `auth`); `Feature/LandingPodgladNieOdsylaDoLogowaniaTest` (2 testy) |
| #1400 | ZAMKNĄĆ | 9e79f134c: `collections/edit.blade.php`, `recipes/show.blade.php`, `post-card.blade.php`; `Feature/PublicznyDomyslnyZeszytJawnyPrzyZapisieTest` (2 testy) + kontrola ujemna |
| #1748 | ZAMKNĄĆ | e8262d3ba: `RestoreContent::wolnoCofnac()`, `admin/reports.blade.php:400` „Ukrył administrator.”; `PrzywrocenieTylkoPoDecyzjiModeracjiTest::test_kolejka_*` (4 testy) |
| #1754 | ZAMKNĄĆ\* | 752fd6253: `Domain/Rocznice/RocznicaDolaczenia`, `Feature/RocznicaDolaczeniaTest` (9 testów), wyłącznik = `users.memories_enabled`. Uwaga: „uwzględnienie formy” = tekst obojętny rodzajowo („Gotujesz z nami…”); helper formy z #1752/#1753 ma wejść w `tekst()` później |
| #1755 | ZAMKNĄĆ | e94b02315, a6fc179de, f1407d3bc, 01bf4e91c, a70527e4d (D-269): CHECK-i w `2026_09_25_200000_add_birthday_to_users.php`, `Feature/ZyczeniaUrodzinoweNaStronieTest`, `ZyczeniaUrodzinoweMailemTest`, `PrzypomnienieOUrodzinachTest`, polityka `polityka-prywatnosci.md:29-30`, rejestr czynności, eksport, wymazanie |
| #1812 | ZAMKNĄĆ | PR #1834 (f17da36c1): `Feature/ZwijanieSeriiWObserwowanychTest` (seria osoby, seria z tagu, list tygodniowy max 1 wpis na autora) |
| #1851 | ZAMKNĄĆ | e25f22125, dd784e447 (jak #1927): `deploy.yml` przez `env:` + allowlista; `DeployNieWklejaDanychZdarzeniaDoPowlokiTest`, `WorkflowyNieWklejajaDanychUzytkownikaDoRunTest` |
| #1909 | ZAMKNĄĆ | 8e15161c3: `/co-nowego`, `resources/nowosci/tresc.md` (Alfa 0.62–0.74), `Feature/StronaCoNowegoTest`, `StraznikNowosciKazdaNowaFunkcjaMaAkapitTest`, D-317 |
| #1932 | NIE | Kryteria z treści są (e549e420a, e87739cfe: `wdrozenia`, `ZarejestrujWdrozenie`, `tests/Dwa/RejestracjaWdrozeniaNaDwochPolaczeniachTest`), ale komentarz audytu z 28.09 opisuje otwartą lukę: `.railway/railway.ts:1184` rejestruje wdrożenie w `preDeployCommand` przed seedem/importem/healthcheckiem, a numer i funkcje „od Alfa” zostają także po nieudanym rolloucie |
| #2008 | ZAMKNĄĆ | PR #2015 (b5310afbd): `recipes/show.blade.php`; `Feature/KomuWyszloUkladTest::test_pusty_stan_prowadzi_goscia_do_rejestracji_i_logowania` i `…_uprawnionego_do_formularza_wykonania` |
| #2056 | ZAMKNĄĆ | ea78ef8dc: `docs/DEPLOYMENT.md:127-148` (tabela topologia × rola × `drainingSeconds`), strażnik `scripts/railway/iac.test.mjs` |
| #2069 | ZAMKNĄĆ | d4a846245: `cooking.blade.php`, `Feature/ChecklistaSkladnikowGotowaniaTest`, `scripts/przegladarka/skladniki-gotowania.test.mjs` (sessionStorage, reset niezależny od kroków, 320 px) |
| #2135 | ZAMKNĄĆ | 045947422: `Support/KanonicznyAdresStrony.php:60` `'tags.show' => ['cursor']`; `Feature/CanonicalKursoraTaguTest` |
| #1050 | ZAMKNĄĆ | 26da87a99: `SearchQuery::jestPrzeszukiwalna()` (recipes, people, SearchController, OnboardingController); `Feature/FrazaPustaPoNormalizacjiTest` |
| #1657 | ZAMKNĄĆ\* | bb00a6cd8: `Domain/Compliance/UsuwanieWPartiach` (budżet 50 000, partia 1000), `Feature/RetencjaPartiamiTest` (8 testów, w tym awaria po pierwszej partii, `--na-sucho`). Uwaga: brak zapisanego planu EXPLAIN przed/po (kryterium dopuszcza „równoważny pomiar testowy”) |
| #1740 | ZAMKNĄĆ | e77989bb4, cbebf7a42: `docs/DEPLOYMENT.md:13-23` (`scheduler`, `schedule:work`, bez Railway Cron) + strażnik z kontrolą ujemną |
| #1741 | ZAMKNĄĆ | 1c8936482: `.env.example:162-163`; `Feature/EnvExampleMaZmienneCzyszczeniaCdnTest` |
| #1742 | ZAMKNĄĆ | 82890daa6: workflow `audyt-a7-final-check.yml` usunięty; `.github/workflows/` na main ma tylko 6 plików (ci, deploy, preview, railway-ci-gated-deploy, railway-iac, ceny-warzyw-auto) |
| #1749 | ZAMKNĄĆ\* | 77f0679cb (D-304): `Domain/Feed/MojStol`, `Feature/MojStolTest` (wyłączalny, „Pokazujemy, bo…”, „Nie pokazuj mi tego”, wymazanie i eksport). Uwaga: zakres celowo zawężony decyzją właściciela D-304 do zamkniętej listy D-275 (bez scoringu i uczenia), więc „reset dopasowania” i pomiary modelu nie dotyczą |
| #1805 | ZAMKNĄĆ | a949f6aed: `SitemapController`; `Feature/MapaStronyProfileAutorowTest::test_zapowiedz_niedostepnego_przepisu_nie_wpuszcza_profilu` + kontrola dodatnia |
| #1824 | ZAMKNĄĆ | 67b1e6d22 + przeniesienie do `FollowingFeed::obserwowaneTematy():195-209` (`aktywne()`); `Feature/UkrytyTagNieZasilaStartuTest` (4 testy) |
| #2165 | ZAMKNĄĆ | d67e7c4cb: `DecyzjaPoOdwolaniu.php`, `tests/Dwa/OdwolanieNieZakleszczaEdycjiPrzepisuTest`, `scripts/kontrola-negatywna-2165.py` |

## Braki dla werdyktów innych niż ZAMKNĄĆ

- **#1046 (NIE):** rozszerzyć `SesjaPoUniewaznieniuNieWracaTest::odwolaj()` i `operacje()` o `potwierdzenie-email`, `wlaczenie-2fa` (z próbą zwykłej trasy `auth` i `/admin/**` moderatora) i `awans-roli` (user→moderator/admin, z 2FA i bez).
- **#2050 (NIE):** brak wdrożenia na main; jest `claude/2050-zdjecia-po-bledzie` (etap 1, bez PR-a).
- **#988 (NIE):** wymusić jawny typ w API komunikatu, sklasyfikować pozostałe zapisy `with('status')`, odbiór z czytnikiem ekranu.
- **#1932 (NIE):** przenieść rejestrację/aktywację wdrożenia za readiness check (albo stan `pending/active`), odczyty stopki i „Co nowego” tylko z aktywnych.
- **#836 (WŁAŚCICIEL):** decyzja o `php artisan kuking:oczysc-kontekst-kontaktu --wykonaj` na produkcji (domyślnie tylko podgląd). Kod, testy i komenda są na main.
- **#975 (WŁAŚCICIEL):** potwierdzenie na żywo (włączone Railway PR Environments, `preview.yml` nie jest już `skipped`) albo decyzja, że test kontraktowy wystarcza.

## Komentarze zamykające (do wklejenia przy zamknięciu; komentarz nie jest publikowany)

**#1974** — Zweryfikowane na main (00c9a12f3): rozliczenie kosztu jest idempotentne dzięki księdze `ai_rezerwacje` (klucz UNIQUE `import_id`+`proba`, warunkowe przejścia stanów) i wspólnej transakcji budżetu z zapisem zlecenia (`RozliczenieOdczytu`), commit c7f4674a8. Testy `MaszynaStanowOdczytuTest::test_1974_awaria_po_rozliczeniu_budzetu_nie_liczy_kosztu_drugi_raz` i `test_1974_ponowne_rozliczenie_tej_samej_proby_nic_nie_zmienia`. Zamykam.

**#1977** — Zweryfikowane na main: zadanie `OdczytajPrzepis` jest zlecane w transakcji zlecenia (outbox), a `kuking:odzyskaj-importy` (routes/console.php:366, co kwadrans) domyka zlecenia bez zadania; commit c7f4674a8. Testy `MaszynaStanowOdczytuTest::test_1977_awaria_kolejki_nie_zostawia_zlecenia_bez_zadania_a_ponowienie_je_wysyla` i `test_1977_porzucone_zlecenie_konczy_sie_jawnym_bledem_z_ponowieniem`. Zamykam.

**#1980** — Zweryfikowane na main: zapisana odpowiedź modelu zamyka etap OCR, ponowienie dokańcza z niej bez drugiego płatnego żądania, a sufit `PROBY_MODELU` obejmuje każdy rodzaj ponowienia (c7f4674a8). Testy `MaszynaStanowOdczytuTest::test_1980_ponowienie_po_zapisanej_odpowiedzi_nie_wola_modelu_drugi_raz`, `test_1980_awaria_tuz_za_szkicem_nie_konczy_sie_szkic_zmieniony`, `test_1980_sufit_platnych_zadan_obejmuje_kazdy_rodzaj_ponowienia`. Zamykam.

**#2033** — Zweryfikowane na main: ustawienia prywatności i ekran importu pokazują tę samą informację o zgodzie (`components/zgoda-odczyt-ai.blade.php`, `settings/privacy.blade.php:123`), a zgoda bez aktualnej wersji informacji nie zapisuje się z żadnej drogi (ae0d1a491, cdb4c6441, 9148b7326, PR #2162). Testy w `Zgody/InformacjaPrzedZgodaOdczytuAiTest` (5). Tekst nie obiecuje okresu retencji po stronie OpenAI, zgodnie z decyzją z 28.09. Zamykam.

**#2066** — Zweryfikowane na main: `AlarmujOPilnymZgloszeniu` zajmuje blokadę celu, budżet i zlecenie powiadomienia w jednej transakcji, więc wyjątek po `INSERT` do `jobs` cofa wszystko, a ponowienie zleca dokładnie jeden alarm (04be07ad8 / PR #2132, c1ff80260 dla drogi DSA). Test `PilnyAlarmZgloszeniaPrawnegoPoAwariiTest::test_ponowienie_formularza_po_awarii_kolejki_wysyla_jeden_alarm` oraz `KolejkaModeracjiStawiaPilneNaGorzeTest`. Późniejsza terminalna porażka workera zostaje w #2169, zgodnie z decyzją właściciela. Zamykam.

**#994** — Zweryfikowane na main (e83042e8b, PR #1499): `resources/legal/polityka-prywatnosci.md:36` opisuje dziennik serwera zbierany przez Railway i okres „do 7 dni” (plan Hobby, decyzja właściciela z 24.09, `docs/DEPLOYMENT.md:65` z checklistą przejścia na Pro), Railway jest w tabeli dostawców. Test `PolitykaOpisujeRetencjeDziennikaSerweraTest` wykrywa powrót dawnego zdania. Zamykam.

**#1746** — Zweryfikowane na main: `WidocznoscTresciSql` odrzuca komentarz pod wykonaniem kucharza w karencji usunięcia konta tak samo jak `CookedEventPolicy` (fdaa97c3d). Macierz `Feature/Visibility/PowiadomieniaZgodneZPolicyTest` ma stan „karencja” bez tolerowanego rozjazdu, a kontrola ujemna jest w `scripts/kontrole-negatywne-alfa08.py:540`. Zamykam.

**#1747** — Zweryfikowane na main: filtr powiadomień stosuje bramkę przepisu dla zapowiedzi (3a0c40108, `WidocznoscTresciSql`). Zapowiedź jest celem w macierzy `PowiadomieniaZgodneZPolicyTest`, a kontrola ujemna to `kontrole-negatywne-alfa08.py:551`. Zamykam.

**#1807** — Zweryfikowane na main (510d8e1bc, 20de4c01c, a6cd0c64f): `DiscoverFeed.php:83` numeruje wpisy `row_number() OVER (PARTITION BY author_id …)` i sortuje po rundzie, a pusty stan rozróżnia „nic nowego” od „część ukrywasz” i ma wyjścia. Testy `OdkrywanieRotacjaAutorowTest` (m.in. 10 osób × 5 wpisów = 50 kart, kursor stabilny) i `OdkrywaniePustyStanZWyjsciemTest`; reguła w D-276. Zamykam.

**#1808** — Zweryfikowane na main (d00aa31ef, 417755500, PR #1831): `FollowingFeed` zwraca chronologiczną sumę wpisów obserwowanych osób i obserwowanych tagów (bez duplikatów, z blokadami), karta z tagu ma podpis „Z tagu: …”, decyzja zapisana jako D-277 (zmienia D-021), `AGENTS.md` §8 poprawiony. Testy w `TagiObserwowanieTest` (prywatne i „dla obserwujących” z tagu nie przeciekają, blokady, jeden wpis przy dwóch tagach). Zamykam.

**#1809** — Zweryfikowane na main (9697abb79, PR #1832): menu karty ma „Obserwuj tę osobę” i „Obserwuj tag: …” (najwyżej 2 tagi, tylko nieobserwowane, reguła jak `UserPolicy::follow`), komunikat przez `status_powrot` z „Cofnij”, formularze POST bez JS. Testy `SkrotyObserwowaniaWMenuTest` (6). Zamykam.

**#1810** — Zweryfikowane na main (PR #1833, abcb34956): tabela `hides` z ograniczeniami i rollbackiem odmawiającym przy aktywnych ukryciach (D-088), `docs/DATABASE.md:1381`, „Ukryj ten wpis” / „Ukryj tę osobę” z komunikatem „tylko dla Ciebie” i „Cofnij”, lista w Ustawieniach → Ukryte, ostrzeżenie przy ≥1/3 autorów, eksport i wymazanie z kontem, ukrycia nie czytane przez moderację ani analitykę. Testy `UkryjWpisIOsobeTest` (12) i `UkryciaOdporneNaZnikanieIWyscigTest`. Zamykam.

**#1813** — Zweryfikowane na main (f79a3080f, dfbf475d6, 2b5cd32a7): tabela `post_reactions` (jedna reakcja osoby na wpis, rollback odmawia przy danych), przycisk drugorzędny bez licznika z cofnięciem, zbiorcze powiadomienie raz dziennie (`routes/console.php:74`), eksport i wymazanie, D-194 sprostowana, D-280. Testy `SmakowicieWygladaTest` (10); lista osób jest publiczna wg decyzji właściciela z 26.09. Zamykam.

**#1927** — Zweryfikowane na main (e25f22125, dd784e447): `deploy.yml` przekazuje dane zdarzenia przez `env:` (linie 173-175, 271-272) z allowlistą, a nie do treści `run:`. Testy `DeployNieWklejaDanychZdarzeniaDoPowlokiTest` (złośliwe nazwy środowiska) i `WorkflowyNieWklejajaDanychUzytkownikaDoRunTest` (wszystkie workflow, bez listy wyjątków). Zamykam razem z #1851.

**#2061** — Zweryfikowane na main (554c75ab9, 2548aee5b): opóźniony ekran włączenia 2FA czyta świeży stan pod blokadą i nie podmienia potwierdzonego sekretu ani kodów zapasowych. Testy na dwóch procesach: `tests/Dwa/OpoznionyEkran2faNieNadpisujeSekretuTest` (5 testów, w tym przy zaczętej konfiguracji). Zamykam.

**#2064** — Zweryfikowane na main: `ZlecImportPrzepisu::ponow()` serializuje ponowienia pod blokadą osoby i szkicu, drugie żądanie przejmuje aktywne zlecenie; jedno płatne wywołanie potwierdzają `tests/Dwa/PonowienieImportuPrzezHttpNaDwochPolaczeniachTest`, `PonowienieImportuNaDwochPolaczeniachTest` i `Feature/Import/PonowienieOdczytuLiczySieRazTest` (a440066b3). Zamykam.

**#1806** — Zweryfikowane na main (407c04ed4): `AGENTS.md:506` (§8, zamknięta lista reguł doboru), `:789` (§12), D-275 w `DECISIONS.md`, D-194 sprostowana, strażnik `FeedNieSortujePoMierzeReakcjiTest` obejmuje `app/Domain/Digest` z kontrolą ujemną (`scripts/kontrole-negatywne-alfa08.py:465-471`). Zamykam.

**#1944** — Zweryfikowane na main: test `scripts/przegladarka/klawiatura-belki.test.mjs` (844×390, 768×500, tekst 100% i 140%) jest stabilny po poprawkach z PR #1951 (e0bdb769b) i #1981, a CI `push` na main jest zielone (run 36465941156). Zgłoszenia z prawdziwego telefonu nie ma, więc zamykam zgodnie z komentarzem z 26.09.

**#2021** — Zweryfikowane na main (512307aa5, fe53241ce, 8f95adf44, PR #2160): retry niesie tylko `push_grupa_id`, hydratacja i payload mają stały koszt, testy `PushDuzaGrupaTest` (8: stała liczba modeli, rozmiar payloadu, częściowa porażka, sierota), surowe plany EXPLAIN przed i po w `docs/infra/pomiary/2021/`. Grupa pozostaje bez arbitralnego limitu, zgodnie z decyzją z 28.09. Zamykam.

**#2112** — Zweryfikowane na main (f3b8bcfdf, PR #2127): `UstawWidocznoscWartosci` pobiera przepis z `lockForUpdate()` i sprawdza Policy na świeżym wierszu, po decyzji moderatora zwraca czytelną odmowę bez zapisu i bez zmiany `updated_at`. Testy `tests/Dwa/WartosciOdzywczePoModeracjiTest` (ukrycie, zdjęcie, soft delete oraz kolejność odwrotna) i `WartosciOdzywczeNaStroniePrzepisuTest`. Zamykam.

**#2072** — Zweryfikowane na main (fe42beea9, d7150506e): `LimitImportowOsoby::rezerwuj()` sprawdza limit i zapisuje próbę pod `pg_advisory_xact_lock` osoby, więc równoległe żądania nie przekroczą 5/dzień ani 30/miesiąc. Testy `tests/Dwa/WspolnyLimitImportuNaDwochPolaczeniachTest` (4 z 5, 29 z 30, kontrola dodatnia i ujemna). Zamykam.

**#2048** — Zweryfikowane na main (cf7f90bab): `scripts/railway-ci-gated-deploy.py::deploy` przy `GITHUB_RUN_ATTEMPT>1` nie wywołuje mutacji Railway, a zgubiona odpowiedź mutacji kończy się komunikatem o niejednoznacznym wyniku z instrukcją ręcznego uzgodnienia (`docs/infra/RAILWAY_CI_GATE.md`). Test `test_lost_mutation_response_and_rerun_never_repeat_deploy`. Sama bramka nadal czeka na decyzje właściciela w #2025.

**#769** — Zweryfikowane na main (891fecc01, a4f6a0af): karta wykonania i ekran „Komuś wyszło” dają zdjęciu opis zastępczy („Zdjęcie wykonania”, rozróżnialny numer, opis w powiększeniu), autorski `alt_text` ma pierwszeństwo, tytuł niedostępnego przepisu nie wycieka. `Feature/ZdjecieWykonaniaOpisZastepczyTest` (6 testów, w tym celebrate). Zamykam.

**#871** — Zweryfikowane na main (4c267174f): `ZachowaneZdjecia` waliduje `old('media_ids')` w kontrolerze i widokach; nie-UUID i zagnieżdżona tablica wracają do formularza z tekstem i polskim komunikatem zamiast 500. Testy w `Feature/ZdjeciaFormularzyPoBledzieTest` (wpis, pytanie, poprawne własne zdjęcie wraca, cudze nie). Zamykam.

**#872** — Zweryfikowane na main (4c267174f): „Ugotowałem” zapisuje poprawne zdjęcia przed walidacją pozostałych pól i odsyła je jako `media_ids` z podglądem i „Usuń to zdjęcie”; przyjmuje tylko własne, nieprzypięte. Testy `ZdjeciaFormularzyPoBledzieTest::test_ugotowalem_*` (dwa kolejne błędy, świadome usunięcie, brak zdjęcia dozwolony). Zamykam.

**#874** — Zweryfikowane na main (4c267174f): podsumowanie błędów mapuje `photos.*` na `#f-photos` we wpisie, pytaniu i „Ugotowałem” (także gdy zachowane zdjęcie ukrywa pole), a indeksowane pola innych formularzy zachowują własny cel. Testy `ZdjeciaFormularzyPoBledzieTest::test_blad_pojedynczego_pliku_*` i `test_indeksowane_pola_bez_wzorca_zachowuja_wlasny_cel`. Powiązanie komunikatu z polem przez `aria-describedby` śledzi #1572, które zostaje otwarte.

**#934** — Zweryfikowane na main (73e50be97 + `ZachowaneZdjecia`): kolejność zachowanych zdjęć jest zgodna z listą wejściową w kontrolerze i w ukrytych polach, a pivot `position` zapisuje tę kolejność. Testy `Feature/KolejnoscZachowanychZdjecPoBledzieTest` (wpis, odrzucone ID nie przestawiają reszty, pytanie). Zamykam.

**#946** — Zweryfikowane na main (7648b7570): `Support/NawigacjaOsobista` wyznacza bieżącą pozycję według właściciela treści, „Mój zeszyt”/„Moje” i „Profil” nie świecą na cudzych zeszytach ani profilach. `Feature/NawigacjaWlasnosciTest` sprawdza osobno nawigację górną, boczną i dolną, najwyżej jedną pozycję `aria-current` oraz własne i cudze listy relacji. Zamykam.

**#982** — Zweryfikowane na main (d8b7ee7ea): formularze poprawki niosą `wersja` (odcisk treści), `EditComment` porównuje ją pod blokadą wiersza; druga karta dostaje konflikt bez zapisu, z zachowanym tekstem. Testy `Feature/PoprawkaKomentarzaDwieKartyTest` i `tests/Dwa/PoprawkaKomentarzaNaDwochPolaczeniachTest` (z kontrolą dodatnią). Zamykam.

**#984** — Zweryfikowane na main (c0d5a840e): każda lista na zakładce „Wszystko” ma własny rozmiar okna (`ile_przepisow`, `ile_osob`) i własne przejście przez próg 200. `Feature/PokazWiecejRozszerzaJednaListeTest`. Zamykam.

**#1023 (ZAMKNĄĆ\*)** — Zweryfikowane na main (7b0a11c58): dalsze okna po 200 zaczynają się za kluczem sortowania ostatniego rekordu (`po_przepisie`/`po_osobie`), więc zmiany rankingu między kliknięciami nie dublują ani nie pomijają wyników; nieczytelny kursor wraca do okna liczbowego. Testy `Feature/StabilneOknaWyszukiwaniaTest` (7) i kontrola ujemna. Otwarta zostaje tylko uwaga z komentarza 22.09 o koszcie skrajnego `od_*` (górna granica `PHP_INT_MAX-200`) — proponuję osobne zgłoszenie, jeśli ma być prowadzona.

**#1037** — Zweryfikowane na main (ee268ac33): `Post::scopeDlaKarty()` i stałe relacji karty zasilają feed obserwowanych, Odkrywanie, tablicę dnia, profil, stronę tagu i zeszyt. `Feature/KartaWpisuJednymKontraktemTest` (preventLazyLoading na 7 listach, stała liczba zapytań dla 2 i 8 kart na profilu, tagu i w zeszycie) z kontrolami ujemnymi w `scripts/kontrole-negatywne-alfa08.py`. Zamykam.

**#1289** — Zweryfikowane na main (3c03eb002): odnośniki „Zobacz…” na stronie powitalnej prowadzą do publicznej pomocy, wyszukiwarki i Odkrywania, a nie do tras za logowaniem (`landing.blade.php:180-195,277`). `Feature/LandingPodgladNieOdsylaDoLogowaniaTest` (gość zbiera odnośniki i sprawdza odpowiedzi/kotwice). Zamykam.

**#1400** — Zweryfikowane na main (9e79f134c): edycja domyślnego zeszytu mówi o przyszłych szybkich zapisach, a przy „Zapisuję” na przepisie i karcie wpisu, gdy zeszyt jest publiczny, stoi nazwa celu i kto go widzi; wariant prywatny bez zmian. `Feature/PublicznyDomyslnyZeszytJawnyPrzyZapisieTest` (pełna droga) i kontrola ujemna w skryptach. Zamykam.

**#1748** — Zweryfikowane na main (e8262d3ba): reguła rangi wyszła do `RestoreContent::wolnoCofnac()` i steruje widokiem kolejki; moderator przy treści ukrytej przez administratora widzi „Ukrył administrator.” zamiast przycisku (`admin/reports.blade.php:400`). Testy `PrzywrocenieTylkoPoDecyzjiModeracjiTest::test_kolejka_*` dla obu rang i kontrola ujemna. Zamykam.

**#1754 (ZAMKNĄĆ\*)** — Zweryfikowane na main (752fd6253): w rocznicę założenia konta (strefa Europe/Warsaw, 29.02 obchodzone 28.02) `/home` pokazuje jedno zdanie od gospodarza, wyłącznik wspólny ze Wspomnieniami, bez maili, powiadomień i nowych danych; `Feature/RocznicaDolaczeniaTest` (9). Forma: zdanie jest obojętne rodzajowo, helper formy z #1752/#1753 wejdzie w `RocznicaDolaczenia::tekst()` przy tamtym zadaniu.

**#1755** — Zweryfikowane na main (e94b02315, a6fc179de, f1407d3bc, 01bf4e91c, D-269): dzień i miesiąc bez roku z CHECK-ami i „Usuń datę”, życzenia na `/home` z wyłącznikiem, mail tylko po osobnej zgodzie w sufitach dobowych, przypomnienie dla obserwujących tylko po włączeniu przez solenizanta (limity, cisza nocna, poza feedem), polityka, rejestr czynności, eksport i wymazanie. Testy `ZyczeniaUrodzinoweNaStronieTest`, `ZyczeniaUrodzinoweMailemTest`, `PrzypomnienieOUrodzinachTest`. Zamykam.

**#1812** — Zweryfikowane na main (PR #1834, f17da36c1): serie powyżej dwóch wpisów jednej osoby (i jednego tagu) zwijają się w `<details>` bez zmiany kolejności i bez utraty wpisów, tygodniowy list ma najwyżej jeden wpis na autora. `Feature/ZwijanieSeriiWObserwowanychTest` (5 testów). Zamykam.

**#1851** — Zweryfikowane na main (e25f22125, dd784e447): `deploy.yml` przekazuje `deployment.environment`, stan i inputy przez `env:` z allowlistą; ogólnorepozytoryjny strażnik `WorkflowyNieWklejajaDanychUzytkownikaDoRunTest` i test wykonawczy `DeployNieWklejaDanychZdarzeniaDoPowlokiTest`. Zamykam (dubel #1927).

**#1909** — Zweryfikowane na main (8e15161c3, D-317): publiczna strona `/co-nowego` z treścią w `resources/nowosci/tresc.md` (Alfa 0.62–0.74), numer wersji w stopce jest odnośnikiem z kotwicą bieżącego wydania, strażnik `StraznikNowosciKazdaNowaFunkcjaMaAkapitTest` z kontrolą ujemną, `StronaCoNowegoTest` (200 dla gościa, kotwice). Zamykam.

**#2008** — Zweryfikowane na main (PR #2015, b5310afbd): pusty stan „Komu wyszło” ma akcję — dla gościa rejestracja i logowanie, dla uprawnionego formularz wykonania. Testy `Feature/KomuWyszloUkladTest::test_pusty_stan_prowadzi_goscia_do_rejestracji_i_logowania` i `…_uprawnionego_do_formularza_wykonania`. Zamykam.

**#2056** — Zweryfikowane na main (ea78ef8dc): `docs/DEPLOYMENT.md:127-148` opisuje `drainingSeconds` według topologii i roli (130 s dla `all` i `worker`, 30 s dla `web` i `scheduler`), a `scripts/railway/iac.test.mjs` pilnuje zgodności z `.railway/railway.ts`. Zamykam.

**#2069** — Zweryfikowane na main (d4a846245): tryb „Gotuję” ma opcjonalną checklistę „Przygotowane” w zwijanej sekcji składników (stan w `sessionStorage` po ID składnika, reset niezależny od odhaczeń kroków, bez JS lista jak dotąd). Testy `Feature/ChecklistaSkladnikowGotowaniaTest` i `scripts/przegladarka/skladniki-gotowania.test.mjs` (320 px, klawiatura). Zamykam.

**#2135** — Zweryfikowane na main (045947422): `KanonicznyAdresStrony.php:60` ma `'tags.show' => ['cursor']`, więc dalsza strona tagu ma self-canonical i `og:url` z własnym kursorem. `Feature/CanonicalKursoraTaguTest` (rzeczywisty `nextPageUrl()`). Zamykam.

**#1050** — Zweryfikowane na main (26da87a99): `SearchQuery::jestPrzeszukiwalna()` sprawdza długość po transliteracji dla `recipes()`, `people()`, `SearchController` i kroku onboardingu; pusta po normalizacji fraza nie buduje `LIKE '%%'`. `Feature/FrazaPustaPoNormalizacjiTest` (fixture z asercją pustej normalizacji, kontrole dodatnie dla polskich znaków i metaznaków). Zamykam.

**#1657 (ZAMKNĄĆ\*)** — Zweryfikowane na main (bb00a6cd8): `UsuwanieWPartiach` kasuje `product_signals`, `audit_log`, zwykłe `notifications`, `sessions` i potwierdzenia RODO partiami (1000) z budżetem 50 000 na przebieg, każda partia we własnej transakcji, z ostrzeżeniem przy wyczerpaniu i bez zmiany wyjątków retencyjnych. `Feature/RetencjaPartiamiTest` (8, w tym awaria po pierwszej partii i `--na-sucho`). Brak zapisanego planu EXPLAIN przed/po (kryterium dopuszcza równoważny pomiar testowy).

**#1740** — Zweryfikowane na main (e77989bb4, cbebf7a42): `docs/DEPLOYMENT.md:13-23` opisuje topologię `web + worker + scheduler + postgres`, `scheduler` jako długo działający `schedule:work` i wyjaśnia, dlaczego nie Railway Cron; strażnik nazwy ma kontrolę ujemną. Zamykam.

**#1741** — Zweryfikowane na main (1c8936482): `.env.example:162-163` ma puste `CLOUDFLARE_ZONE_ID` i `CLOUDFLARE_PURGE_TOKEN` z komentarzem o zakresie tokenu i skutku braku (purge wyłączony, `/health` degraded). `Feature/EnvExampleMaZmienneCzyszczeniaCdnTest`. Zamykam.

**#1742** — Zweryfikowane na main (82890daa6): jednorazowy workflow `audyt-a7-final-check.yml` z `contents: write` i `persist-credentials: true` został usunięty; w `.github/workflows/` zostało 6 workflow bez zapisu do repozytorium poza opisanymi. Nic do naprawiania. Zamykam.

**#1749 (ZAMKNĄĆ\*)** — Zweryfikowane na main (77f0679cb, D-304): prywatna, domyślnie wyłączona półka „Mój stół” z zamkniętej listy doboru D-275 (czas, równość autorów, wybór gospodarza, bramki, blokady i ukrycia przed wyborem), z „Pokazujemy, bo…” i „Nie pokazuj mi tego”, eksportem i wymazaniem; główny feed pozostaje chronologiczny, strażnik `FeedNieSortujePoMierzeReakcjiTest` obejmuje `MojStol`. `Feature/MojStolTest`. Zakres zawężono decyzją właściciela (bez scoringu i uczenia), więc „reset dopasowania” i pomiary modelu nie mają zastosowania.

**#1805** — Zweryfikowane na main (a949f6aed): `SitemapController` przepuszcza wpis przez `zWidocznymPrzepisemAlboWlasnaTrescia(null)`, więc profil z samą zapowiedzią prywatnego, „dla obserwujących” lub ukrytego przepisu nie trafia do `/sitemap.xml`. `Feature/MapaStronyProfileAutorowTest::test_zapowiedz_niedostepnego_przepisu_nie_wpuszcza_profilu` i kontrola dodatnia dla przepisu publicznego. Zamykam.

**#1824** — Zweryfikowane na main (67b1e6d22, po przeniesieniu do `FollowingFeed::obserwowaneTematy():195-209`): ukryty po zaobserwowaniu tag nie zasila Startu i nie wybiera źródła „tagi”, a zastana relacja nadal da się zdjąć w „Twoich tagach”. `Feature/UkrytyTagNieZasilaStartuTest` (4 testy, w tym scalenie). Zamykam.

**#2165** — Zweryfikowane na main (d67e7c4cb): `DecyzjaPoOdwolaniu` bierze blokady w kolejności zgodnej z `PublishRecipe`, nota `2026-09-28-odwolanie-przepis-blokady.md`. Test na dwóch połączeniach `tests/Dwa/OdwolanieNieZakleszczaEdycjiPrzepisuTest` i kontrola ujemna `scripts/kontrola-negatywna-2165.py`. Zamykam.
