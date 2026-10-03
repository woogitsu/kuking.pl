#!/usr/bin/env python3
"""Kontrole regresji Alfa 0.8 i portu marki: w CI albo lokalnie na własnej bazie.

DLACZEGO TEN SKRYPT WOLNO URUCHOMIĆ LOKALNIE.
Do 20 września 2026 stał tu bezwarunkowy `if os.environ.get("CI") != "true"`.
Skutek był odwrotny do zamierzonego: stanowisko, które dopisywało nowy wpis do
`checks`, NIE MIAŁO JAK go sprawdzić przed commitem — a błąd w mutacji wywraca
cały krok CI, nie tylko nowy wpis. Racjonalną reakcją było nie dotykać tego pliku
i opisać kontrolę dodatnią słowami w commicie. Audyt z 20.09.2026 policzył skutek:
na 170 testów czytających źródła (strażników tekstu) wpisem w `checks` objęte
były TRZY. Blokada nie chroniła bazy — chroniła przed używaniem mechanizmu.

CO ZOSTAJE Z OCHRONY. Skrypt PISZE PO ŹRÓDŁACH i URUCHAMIA TESTY, które kasują
i odtwarzają bazę. Uruchomiony na cudzej albo współdzielonej bazie niszczy pracę
innych stanowisk. Dlatego lokalne uruchomienie wymaga JAWNEJ zgody
(`KUKING_KONTROLE_LOKALNIE=1`) i przechodzi przez kontrolę celu połączenia:
host musi być lokalny, port NIE MOŻE być domyślnym 5432 (tam stoi klaster
współdzielony), a nazwa bazy musi być nazwana i różna od `kuking`.
W CI nic się nie zmienia — tam gate przepuszcza jak dotąd.
"""

import hashlib
import os
import subprocess
import sys
from pathlib import Path
import tempfile

from kontrola_przyczyny import przebieg, sprawdz_wzorce, uruchom_test, werdykt, POTWIERDZONA
from kontrola_wyjscia_testu import run_test
from kontrole_oczekiwana_przyczyna import OCZEKUJ, OCZEKUJ_MIARY, kontrole_mechanizmu
from podzial_kontroli import indeksy_po_etykietach, parsuj_argumenty, poza_petla_w_tej_czesci, wybierz_indeksy
from zawezenie_testow import sprawdz_zgodnosc


ROOT = Path(__file__).resolve().parent.parent
os.chdir(ROOT)

# CZĘŚCI CI. `--czesc N/M` uruchamia co M-ty wpis `checks` (indeks % M == N-1)
# oraz, w części 3, elementy spoza pętli; bez argumentu (lokalnie) idzie całość.
# Patrz scripts/podzial_kontroli.py i job `test` w .github/workflows/ci.yml.
# `--tylko ETYKIETA` (powtarzalne) uruchamia same wpisy o tych etykietach — do
# zbierania komunikatu porażki przy pisaniu wzorca oczekiwanej przyczyny (#1011).
CZESC, TYLKO = parsuj_argumenty(sys.argv[1:])


def odmow(powod):
    raise SystemExit(
        "Kontrole negatywne nie ruszą: " + powod + "\n"
        "W CI uruchamiają się same. Lokalnie ustaw KUKING_KONTROLE_LOKALNIE=1\n"
        "i wskaż WŁASNĄ bazę stanowiska, na przykład:\n"
        "  DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=kuking_flota_<stanowisko> \\\n"
        "  KUKING_KONTROLE_LOKALNIE=1 python3 scripts/kontrole-negatywne-alfa08.py"
    )


if os.environ.get("CI") != "true":
    if os.environ.get("KUKING_KONTROLE_LOKALNIE") != "1":
        odmow("brak jawnej zgody na uruchomienie poza CI.")

    host = os.environ.get("DB_HOST", "")
    port = os.environ.get("DB_PORT", "")
    baza = os.environ.get("DB_DATABASE", "")

    if host not in ("127.0.0.1", "localhost"):
        odmow(f"DB_HOST={host!r} nie jest lokalny.")
    # 5432 to domyślny port klastra współdzielonego przez wszystkie stanowiska.
    # Test kasuje i odtwarza schemat, więc trafienie tam niszczy cudzą pracę.
    if port in ("", "5432"):
        odmow(f"DB_PORT={port!r} — podaj port własnego klastra, nigdy 5432.")
    if baza in ("", "kuking"):
        odmow(f"DB_DATABASE={baza!r} — podaj nazwaną bazę stanowiska.")
    # Ta sama rodzina, którą egzekwuje `tests/bootstrap.php` (D-334). Bez tej
    # kontroli każdy test w pętli odmawiał startu, a kontrola ujemna czyta
    # „test nie przeszedł” jako „mutacja złapana” — fałszywa zieleń całego skryptu.
    rodzina = subprocess.run(
        ["php", "-r",
         'require "tests/Support/kuking_bezpiecznik_bazy_testowej.php";'
         ' exit(kuking_ocen_baze_testowa($argv[1]) === null ? 0 : 1);',
         "--", baza],
        capture_output=True, check=False,
    )
    if rodzina.returncode != 0:
        odmow(f"DB_DATABASE={baza!r} nie należy do rodziny testowej "
              "(kuking_test*, kuking_race*, kuking_flota_*) — testy odmówiłyby startu.")

    print(f"Kontrole negatywne lokalnie: {host}:{port}/{baza}", flush=True)

PLANER_TYGODNIA = "app/Domain/Planer/PlanerTygodnia.php"
PLANER_DODAJ = "app/Domain/Planer/Actions/DodajDoPlanu.php"
WYMAZANIE_KONTA = "app/Domain/Users/Actions/EraseAccountData.php"
WYMAZANIE_PURGE = "app/Console/Commands/PurgeExpiredAccountDeletions.php"
ALARM_DZIENNIKA = "app/Domain/Monitoring/AlarmDziennikaWymazan.php"
DZIENNIK_WYCOFANIE_TEST = "test_awaria_po_dopisaniu_wpisu_wycofuje_ten_wpis_z_dziennika"
DZIENNIK_ISTNIEJE_TEST = "test_awaria_po_istnieje_zostawia_wpis_ktory_przezyl_odtworzenie_kopii"
DZIENNIK_SLEEP_TEST = "test_ponawianie_zapisu_dziennika_czeka_poza_transakcja_wymazania"
ALARM_DZIENNIKA_TEST = "test_alarm_dopiero_po_progu_kolejnych_nocy_i_dokladnie_raz"
ALARM_DZIENNIKA_RESET_TEST = "test_przebieg_bez_porazki_dziennika_zeruje_licznik_i_daje_jedno_odwolanie"
ALARM_DZIENNIKA_DZIEN_TEST = "test_drugi_przebieg_tego_samego_dnia_nie_nabija_licznika"
PLANER_TEST = "PlanerTygodniaTest"
ZAKUPY_LISTA = "app/Domain/Zakupy/ListaZakupow.php"
ZAKUPY_KONTROLER = "app/Http/Controllers/ListaZakupowController.php"
ZAKUPY_MIGRACJA = "database/migrations/2026_09_30_090000_create_shopping_list_items_table.php"
ZAKUPY_POZYCJA = "resources/views/pages/zakupy/_pozycja.blade.php"
# Jedna kontrola = jedna metoda: mutacja ma zapalić jedną przyczynę, a wzorzec
# oczekiwanej porażki dotyczy KAŻDEJ porażki po mutacji (#1011).
ZAKUPY_WIDOCZNOSC_TEST = "test_przepis_ktory_przestal_byc_widoczny_zostawia_pozycje_jako_sam_tekst"
ZAKUPY_OSTRZEZENIE_TEST = "test_ponowne_dodanie_tego_samego_przepisu_najpierw_ostrzega_i_dopisuje_po_potwierdzeniu"
ZAKUPY_LIMIT_TEST = "test_lista_ma_limit_pozycji_i_mowi_co_zrobic"
ZAKUPY_POLICY_TEST = "test_usuniecie_pozycji_i_cudza_pozycja_nietykalna"
ZAKUPY_POTWIERDZENIE_TEST = "test_pojedyncze_usuniecie_recznej_i_skopiowanej_pozycji_wymaga_otwarcia_pytania"
ZAKUPY_WYMAZANIE_TEST = "test_wymazanie_konta_kasuje_liste_tylko_tej_osoby"
ZAKUPY_ROLLBACK_TEST = "test_cofniecie_migracji_odmawia_przy_listach_ludzi_i_przechodzi_na_pustej"
PUSH_JOB = "app/Jobs/WyslijPowiadomieniePush.php"
PUSH_DWA_POLACZENIA_TEST = "PowiadomieniaPushDwaPolaczeniaTest"
ALARM_RECOVERY = "app/Domain/Moderation/Actions/AlarmujOPilnymZgloszeniu.php"
ALARM_RECOVERY_TEST = "PonowPilnyAlarmOdCzlowiekaTest"

# #2027: nowo utworzone konto po OAuth musi przejąć zamiar powrotu z wątku
# komentarzy. Testy obu dostawców przechodzą przez onboarding i sprawdzają
# końcowy adres; usunięcie przypisania do konta ma oblać każdą ścieżkę.
POWROT_KOMENTARZA_GOOGLE = "app/Http/Controllers/Auth/GoogleLoginController.php"
POWROT_KOMENTARZA_GOOGLE_TEST = "LogowanieKontemGoogleTest"
POWROT_KOMENTARZA_FACEBOOK = "app/Http/Controllers/Auth/FacebookLoginController.php"
POWROT_KOMENTARZA_FACEBOOK_TEST = "LogowanieKontemFacebookiemTest"

# Reguła wyboru własnego zeszytu mieszka od #970 (krok 5) w FormRequeście.
WYBOR_ZESZYTU = "app/Http/Requests/Collections/WyborZeszytuRequest.php"
UNANSWERED_CONTENT = "app/Domain/Moderation/UnansweredContent.php"
LAYOUT = "resources/views/components/layout.blade.php"
CSS = "resources/css/app.css"
COLLECTION_TEST = "WyborZeszytuMaWalidacjeTest"
COMPOSER_TEST = "KafelDodawaniaPrzyDuzymTekscieTest"

# Strażnik nowych strażników tekstu. Nazwa klasy stoi w tym pliku DOKŁADNIE RAZ,
# i to jest celowe: strażnik szuka w tym pliku swojej nazwy, więc ta linia
# jest jednocześnie jego pokryciem i punktem mutacji pierwszej kontroli dodatniej.
STRAZNIK_TEKSTU_TEST = "StraznikTekstuMaKontroleDodatniaTest"
STRAZNIK_SAM_SKRYPT = "scripts/kontrole-negatywne-alfa08.py"
STRAZNIK_PLIK_ODSTEPSTWA = "tests/Feature/PlikKontrolnyZOdstepstwemTest.php"

# Akcje GitHuba przypięte do pełnych SHA (#951). Strażnik parsuje `uses:`
# w workflowach i akcjach composite; mutacja cofa akcję PHP do ruchomego tagu
# i ma go zapalić — dowód, że widzi też `.github/actions/**`, nie tylko workflowy.
AKCJA_PHP = ".github/actions/php/action.yml"
AKCJE_SHA_TEST = "AkcjeGithubPrzypieteDoShaTest"

# Testy skryptów Pythona muszą mieć krok w CI. Mutacja zmienia nazwę pliku w
# kroku strażnika podziału kontroli tak, by żaden wiersz workflowu (poza
# komentarzami, które strażnik pomija) go już nie wskazywał.
CI_WORKFLOW = ".github/workflows/ci.yml"
TESTY_PYTHONA_W_CI_TEST = "TestySkryptowPythonaChodzaWCiTest"

# Etap `assets` obrazu a lista plików podana do `node --test` (regresja #1085).
# Ten strażnik pilnuje własnej NIEPUSTOŚCI (`assertNotEmpty`), ale nic w nim
# nie dowodzi, że czytnik `COPY` z Dockerfile potrafi powiedzieć „nie
# kopiowany". Gdyby parser zaczął zwracać zbiór za szeroki, `czyKopiowany()`
# byłoby zawsze prawdziwe, a test świeciłby na zielono nad niczym — dokładnie
# ta klasa usterki, dla której powstał mechanizm kontroli dodatnich.
OBRAZ_ASSETOW = "Dockerfile"
OBRAZ_PDF_TEST = "ObrazMaNarzedziaPdfTest"
MIGRACJA_IMPORTU = "database/migrations/2026_09_26_100000_create_przepisy_z_importu_table.php"
MIGRACJA_IMPORTU_TEST = "CofniecieMigracjiImportuTest"
CENY_WARZYW_WORKFLOW = ".github/workflows/ceny-warzyw-auto.yml"
CENY_WARZYW_TEST = "WorkflowCenNieUruchamiaKoduZTokenemZapisuTest"
NOWOSCI_KONTROLER = "app/Http/Controllers/NowosciController.php"
NOWOSCI_OD_NUMERU_TEST = "StronaCoNowegoOdNumeruTest"
NOWOSCI_OD_NUMERU_FIXTURE = "tests/Feature/StronaCoNowegoOdNumeruTest.php"
MIGRACJA_NO_AMOUNT = "database/migrations/2026_09_06_130000_add_no_amount_to_recipe_ingredients.php"
MIGRACJA_NO_AMOUNT_TEST = "CofniecieMigracjiNieKasujeFlagiBrakuIlosciTest"
MIGRACJA_PUSH = "database/migrations/2026_09_26_100000_utworz_powiadomienia_push.php"
MIGRACJA_PUSH_TEST = "test_wycofanie_migracji_odmawia_gdy_ktos_wybral_wlasna_cisze_nocna"
OBRAZ_ASSETOW_TEST = "ObrazAssetowMaPlikiTestowTest"

# Strażnik nowych migracji wobec AGENTS.md §6 (audyt appeal_id, #989).
# `add_appeal_id_to_moderation_actions.php` dodała FK/indeks/CHECK do
# istniejącej tabeli z pominięciem CONCURRENTLY i NOT VALID; właściciel
# zdecydował jej nie ruszać, ale nowe migracje mają się tego trzymać.
# Kontrola dodatnia (`straznik_wykrywa_kazde_z_trzech_naruszen_par6`) karmi
# strażnik trzema fabrykowanymi migracjami — po jednej na regułę. Każda
# z trzech mutacji niżej gasi wykrywanie JEDNEJ reguły, więc każda ma
# zapalić dokładnie ten sam test kontroli dodatniej.
STRAZNIK_MIGRACJI = "app/Support/Baza/StraznikNowychMigracji.php"
STRAZNIK_MIGRACJI_TEST = "straznik_wykrywa_kazde_z_trzech_naruszen_par6"

# Kolejność w `down()` migracji 2FA (D-238, DB-01). Strażnik czyta źródło
# migracji i porównuje położenie sprawdzenia liczby kont z położeniem zdjęcia
# CHECK-a i `dropColumn`. W działaniu różnicy nie widać — wyjątek leci w obu
# wersjach — więc tylko mutacja dowodzi, że asercja o kolejności naprawdę pada.
MIGRACJA_2FA = "database/migrations/2026_09_06_120000_add_two_factor_to_users_table.php"
MIGRACJA_2FA_TEST = "CofniecieMigracji2faOdmawiaTest"

# Pierwszy ekran strony powitalnej: blok reguł w warstwie `marka` ma ruszać
# WYŁĄCZNIE odstępy. Najtańsza „naprawa" przycisku pod zgięciem to mniejsze
# pismo — i właśnie tego strażnik pilnuje, czytając arkusz.
PIERWSZY_EKRAN_CSS = "resources/css/marka-ekrany.css"
PIERWSZY_EKRAN_TEST = "PierwszyEkranMiesciPrzyciskTest"

# Strażnik adresów z sekretami (#991, D-250). Kryterium issue wprost: kontrola
# ujemna osłabiająca sprawdzenie do samego `https://` ma oblać test hosta
# podszywającego się sufiksem. Druga mutacja zabiera sprawdzenie ścieżki.
STRAZNIK_HOSTA = "app/Support/DozwolonyHostApi.php"
STRAZNIK_HOSTA_TEST = "test_straznik_odrzuca_adres_spoza_listy"
# Log serwera bez danych osobowych (audyt prywatności 23.09.2026). Test czyta
# zapisany plik logu i asertuje na jego treści — bez mutacji nic nie dowodzi,
# że asercje „nie zawiera e-maila/hasha" potrafią w ogóle zapalić.
LOG_SERWERA = "app/Logging/BezDanychOsobowychWLogu.php"
LOG_SERWERA_TEST = "LogSerweraBezDanychOsobowychTest"

# Logi operacyjne bez komunikatu obcego wyjątku (#973). Test skanuje kod
# `app/` i czyta kontekst loggera — mutacja przywraca surowe getMessage().
LOG_OPERACYJNY = "app/Domain/Media/KasujZdjecie.php"
LOG_OPERACYJNY_TEST = "LogOperacyjnyBezKomunikatuWyjatkuTest"

# `unserialize()` ładunku kolejki tylko z listą klas (#1841). Strażnik czyta
# tokeny PHP w `app/`; mutacja zdejmuje `allowed_classes` w jedynym miejscu,
# które odtwarza ładunek — ma zapalić i strażnika, i test zachowania
# (atrapa z efektem ubocznym przy odtwarzaniu naprawdę się budzi).
POLECENIE_ZADANIA = "app/Domain/Kolejka/PolecenieZadania.php"
UNSERIALIZE_TEST = "UnserializeTylkoZListaKlasTest"
OBCE_KLASY_TEST = "NieudanyListNieOdtwarzaObcychKlasTest"
UNSERIALIZE_LISTA = ", ['allowed_classes' => self::WOLNO_ODTWORZYC]"

# Cofnięcie migracji CHECK-a `contact_messages_handled_complete` (#844, #1081).
# Strażnik wczytuje migrację przez `database_path(...)` i asertuje na treści
# definicji ograniczenia. Mutacja zdejmuje odmowę w `down()`: bez niej
# cofnięcie przy wiadomościach po usuniętym operatorze wywraca się dopiero
# na CHECK-u, innym wyjątkiem i bez zdania, co zrobić — test ma to zauważyć.
KONTAKT_MIGRACJA = "database/migrations/2026_09_24_100000_allow_null_handled_by_on_contact_messages.php"
KONTAKT_MIGRACJA_TEST = "UsuniecieOperatoraNiePsujeWiadomosciTest"

# Znaczniki odpowiedzi (`reply_key`, `sending_started_at`) chronią przed drugą
# wysyłką tego samego listu (#1081). Strażnik wczytuje migrację przez
# `database_path(...)`; mutacja zdejmuje odmowę cofnięcia po pierwszym
# formularzu, więc `down()` przechodzi i test odmowy ma oblać.
KONTAKT_ZNACZNIKI = "database/migrations/2026_09_24_120000_add_contact_reply_delivery_markers.php"
KONTAKT_ZNACZNIKI_TEST = "AwarieOdpowiedziKontaktuTest"

# Jedna sprawa RODO `w_toku` na konto (#1346). Test wczytuje migrację przez
# `database_path(...)`; mutacja zdejmuje odmowę w `up()`, więc przy
# duplikatach nie ma komunikatu „co zrobić" — test odmowy ma oblać.
RODO_W_TOKU_MIGRACJA = "database/migrations/2026_09_24_160000_jedna_sprawa_rodo_w_toku_na_konto.php"
RODO_W_TOKU_MIGRACJA_TEST = "JednaSprawaRodoWTokuMigracjaTest"
# Warunki reguł Cloudflare (#597). Strażnik czyta sparsowany JSON; mutacja
# zdejmuje warunek pustego ciasteczka z reguły zdjęć — to jest dokładnie
# wyciek treści prywatnej do wspólnego cache, którego #597 zakazuje.
REGULY_CF = "docs/infra/cloudflare-cache-rules-597-610.json"
REGULY_CF_TEST = "test_warunki_regul_nie_wpuszczaja_stanu_klienta_do_wspolnego_cache"
REGULA_ZDJEC_CIASTKO = '\\"/zdjecia/\\") and http.request.uri.query eq \\"\\" and http.cookie eq \\"\\"'
# Bramka zakresu w `ci.yml` (#1273): filtr warstwy widoku obejmuje lokalne
# akcje `.github/actions/`, bo joby przeglądarkowe wołają je przez `uses: ./…`.
# Strażnik pyta PRAWDZIWY skrypt bramki, ale czyta go z `scripts/ci/zakres.sh` (od #611 etap 5; wcześniej z `ci.yml`), więc tylko
# mutacja dowodzi, że zapala się, gdy akcje wypadną z filtra.
BRAMKA_CI = ".github/workflows/ci.yml"
# Od #611 (etap 5) logika bramki `zakres` żyje w skrypcie, nie w `ci.yml`;
# strażnicy czytają ten plik, więc mutacje bramki celują w niego.
BRAMKA_SKRYPT = "scripts/ci/zakres.sh"
BRAMKA_AKCJE_TEST = "test_zmiana_lokalnej_akcji_uruchamia_joby_ktore_jej_uzywaja"
# Ciężkie joby wąskiego obszaru zawężane TYLKO na PR-ach (decyzja 24.09.2026).
# Strażnicy pytają prawdziwy skrypt bramki z `scripts/ci/zakres.sh`; mutacje dowodzą, że
# zapalają się w obie strony: gdy job wypada przy zmianie własnego wejścia,
# gdy zawężenie przecieka poza PR i gdy wzorzec przestaje cokolwiek zawężać.
BRAMKA_WEJSCIA_TEST = "test_ciezki_job_rusza_przy_zmianie_kazdego_pliku_ktory_czyta"
BRAMKA_POZA_PR_TEST = "test_poza_pull_requestem_kazdy_job_rusza_przy_zmianie_kodu"
BRAMKA_OBOK_TEST = "test_na_pull_requescie_zmiana_obok_pomija_ciezkie_joby"
WIDOK_POZA_PR_TEST = "test_poza_pull_requestem_filtr_widoku_nie_zaweza"
# `\R` bez `u` tnie „ą" (C4 85) na pół (#1276). Strażnik czyta tokeny PHP
# w `tests/`, `scripts/` i `app/`; mutacja przywraca stary podział w skanerze
# poświadczeń — tym miejscu, gdzie strzępy wierszy kosztowały najwięcej.
PODZIAL_WIERSZY = "tests/Feature/PoswiadczeniaPozaRepozytoriumTest.php"
PODZIAL_WIERSZY_TEST = "PodzialWierszyNieRozrywaLiterTest"

# Test dymny po wdrożeniu (#1012, #1332, #974). Strażnik czyta workflow, bo
# GitHub Actions nie da się uruchomić z testu. Mutacja przywraca starą sondę
# HTTPS, która przepuszczała każdy kod 30x bez względu na cel przekierowania.
WDROZENIE_WORKFLOW = ".github/workflows/deploy.yml"
# Regresja #892 w przeglądarce: krok CI musi istnieć, inaczej skrypt znowu
# leży w repozytorium bez jednego przebiegu.
CI_WORKFLOW = ".github/workflows/ci.yml"
AUTOZAPIS_892_TEST = "test_autozapis_kreatora_892_chodzi_w_ci"
# #611, etap 9: `dwa-polaczenia` blokuje (bez `continue-on-error`), a `port_funkcje`
# to macierz dwóch części. Strażnicy czytają `ci.yml`; mutacje przywracają flagę,
# skracają macierz i przestawiają krok na część, której nie ma.
WYSCIGI_BLOKUJA_TEST = "WyscigiDwochPolaczenBlokujaCiTest"
AUDYT_BLOKUJE_TEST = "KrytyczneKontroleCiBlokujaTest"
ROZSZERZENIA_CZESCI_TEST = "test_rozszerzenia_dziela_sie_na_czesci_bez_utraty_pomiaru"
# #492 (D-333): cztery pomiary #713 w jobie `port_marki`; mutacja zdejmuje jeden krok.
POMIARY_713_TEST = "test_cztery_pomiary_713_chodza_w_porcie_marki"
DEPLOY_WSTRZYKNIECIE_TEST = "DeployNieWklejaDanychZdarzeniaDoPowlokiTest"
WDROZENIE_TEST = "TestDymnyNieUdajeCudzegoWydaniaTest"
# Preview i IaC nie zgadują stanu (#1389, #1390). Strażnik czyta workflow
# i railway.ts; mutacje przywracają: test dymny bez czekania na `success`,
# bez sondy wydania, apply bez przekazanej bramki CI i `=== "true"`.
PREVIEW_WORKFLOW = ".github/workflows/preview.yml"
IAC_WORKFLOW = ".github/workflows/railway-iac.yml"
# Dane od użytkownika w treści `run:` (#1859). Strażnik czyta workflowy; mutacja
# przywraca dokładnie tę linię, którą ręczny `pr_number` wstrzykiwał kod
# do joba z tokenem Railway, i drugą — `inputs.*` typu boolean w IaC.
WKLEJANIE_DO_RUN_TEST = "WorkflowyNieWklejajaDanychUzytkownikaDoRunTest"
IAC_RAILWAY_TS = ".railway/railway.ts"
PREVIEW_IAC_TEST = "PreviewIIacNieZgadujaStanuTest"
IAC_BRAMKA_ENV = "    env:\n      KUKING_WAIT_FOR_CI: ${{ vars.KUKING_WAIT_FOR_CI }}\n"
# `/wydanie` bez sesji i CSRF (przegląd #1439). Mutacja wraca z trasą do
# pełnej grupy `web` i ma zapalić test braku `Set-Cookie`.
WYDANIE_TRASY = "bootstrap/app.php"
WYDANIE_TEST = "WydanieWystawiaPelnyShaTest"

# Obrazy bazowe przypięte do digestów (#952). Strażnik parsuje linie FROM
# w Dockerfile'ach; mutacja zdejmuje digest z obrazu kopii i ma go zapalić —
# dowód, że parser widzi też drugi Dockerfile, a nie tylko główny.
OBRAZ_KOPII = "docker/kopia/Dockerfile"
APT_MIGAWKA_TEST = "test_kazdy_apt_get_install_idzie_przez_przypieta_migawke"
OBRAZY_DIGEST_TEST = "ObrazyBazowePrzypieteDoDigestowTest"

# Oryginał zdjęcia traci XMP (issue #1004). Test czyta fixture'y zapisane
# niezależną biblioteką — strażnik widzi odczyt pliku, więc kontrola dodatnia
# wyłącza samo czyszczenie XMP i test ma wtedy oblać.
USUN_GPS = "app/Domain/Media/UsunGps.php"
XMP_TEST = "OryginalTraciGpsZXmpTest"

# Zmienne Railwaya per rola (#1013, #1014). Strażnik czyta `.railway/railway.ts`
# statycznie, więc tylko mutacja dowodzi, że parser widzi bloki usług, a nie
# pusty zbiór: sekret OAuth dopisany workerowi, klucz modelu zabrany workerowi
# i adres alarmów zabrany schedulerowi — każda z trzech ma zapalić test.
RAILWAY_IAC = ".railway/railway.ts"
ZMIENNE_ROL_TEST = "ZmienneRailwayaPerRolaTest"
# Scheduler budujący mailer w digeście musi mieć klucze EmailLabs (przegląd #1013).
HARMONOGRAM_POCZTA_TEST = "harmonogram_budujacy_mailer_ma_klucze_poczty"
# Klucz modelu i adres alarmu tylko na produkcji — środowisko PR jest kopią
# bazowego, więc bez warunku preview dostałby wartości produkcji (#1014).
TYLKO_PRODUKCJA_TEST = "klucz_modelu_i_adres_alarmu_tylko_na_produkcji"
# Serwis `kopia-bazy` ma zamkniętą listę zmiennych (#193): spread zestawu
# aplikacji dałby procesowi ze zrzutem bazy APP_KEY i klucze zdjęć/poczty.
KOPIA_BEZ_SPREADU_TEST = "serwis_kopii_nie_rozwija_zadnego_zestawu_aplikacji"
KOPIA_DB_URL = "      DB_URL: db.env.DATABASE_URL,\n"
# Gołe `->format(` z datą dla człowieka omija `App\Support\Czas` (issue #746).
# Strażnik czyta widoki linia po linii; mutacja przywraca w ekranie
# potwierdzenia adresu surową godzinę UTC i strażnik ma ją zobaczyć.
WIDOK_POTWIERDZENIA = "resources/views/auth/verify-email.blade.php"
STREFA_STRAZNIK_TEST = "test_zaden_widok_nie_formatuje_daty_z_pominieciem_pomocnika"

# Polityka nie obiecuje „pełnej kopii" danych (R1, wariant A z 20.09.2026).
# Strażnik czyta dokument prawny; mutacja przywraca dawne sformułowanie
# i test ma wtedy oblać — dowód, że szuka tego słowa w tym pliku, a nie w pustce.
POLITYKA = "resources/legal/polityka-prywatnosci.md"
POLITYKA_KOPIA_TEST = "PolitykaNieObiecujePelnejKopiiTest"
# #1816: polityka ma mówić o obserwowanych tagach, ukryciach, reakcjach i „Co mam w domu”.
POLITYKA_PACZKA_TEST = "PolitykaOpisujePaczkeUkryciaIReakcjeTest"
# Kompensacja nieudanego wgrania (issue #962). Pliki idą do storage przed
# `Media::create()`; gdy wiersz nie powstanie, `StoreUploadedImage` ma je
# skasować, bo bez wiersza nie znajdzie ich żadne sprzątanie. Mutacja wyłącza
# wywołanie kompensacji — test ma oblać na oryginale i podglądzie, które
# zostały w `Storage::fake()`.
KOMPENSACJA_UPLOADU = "app/Domain/Media/Actions/StoreUploadedImage.php"
KOMPENSACJA_UPLOADU_TEST = "NieudanyZapisZdjeciaNieZostawiaPlikowTest"

# Decyzja moderacyjna tylko z człowiekiem (UzasadnienieDecyzji, G31/D-251).
# Strażnik skanuje `app/` w poszukiwaniu `ModerationAction::create(` i porównuje
# z listą dozwolonych miejsc. Mutacja dokłada to wywołanie w pliku SPOZA listy
# (`NotifyModerationDecision`, sąsiad nowego `ZdejmijZUrzedu`) — strażnik ma zapalić, czyli
# naprawdę widzi nowe pliki, a nie tylko potwierdza listę, którą już zna.
POWIADOM_O_DECYZJI = "app/Domain/Moderation/Actions/NotifyModerationDecision.php"
DECYZJA_Z_CZLOWIEKIEM_TEST = "test_nie_ma_w_kodzie_drogi_do_decyzji_bez_czlowieka"
# Polityka nazywa każde ciasteczko ustawień (R6). Strażnik czyta dokument
# prawny; mutacja zdejmuje NAZWĘ ciasteczka motywu w backtickach, a zwykłe
# słowo „motyw" zostaje w tekście — test ma wtedy oblać, bo szuka nazwy,
# a nie wyrazu. Ten sam plik co `POLITYKA` wyżej.
POLITYKA_CIASTECZKA_TEST = "PolitykaNazywaCiasteczkaUstawienTest"
# Retencja dziennika serwera (#994). Mutacja przywraca dawne zdanie, które
# wiązało dziennik z życiem instancji — strażnik ma zapalić.
POLITYKA_DZIENNIK_TEST = "PolitykaOpisujeRetencjeDziennikaSerweraTest"
POLITYKA_DZIENNIK_ZDANIE = "Jak długo go tam trzyma, zależy od planu, który mamy wykupiony u Railway."
# Liczba dni z planu Railway (decyzja właściciela 24.09.2026: Hobby, 7 dni).
POLITYKA_DZIENNIK_DNI = " Obecnie jest to **do 7 dni**."
TWARDE_USUNIECIE_TEST = "TwardeUsuniecieTresciTest"
POLITYKA_SESJE_TEST = "PolitykaOpisujeSesjeKopieIR2Test"
PRZEDAWNIONE_WPISY_AUDYTU = "app/Domain/Compliance/PrzedawnioneWpisyAudytu.php"

# Cache manifestu Vite (#809). Strażnik czyta `docker/Caddyfile`: pliki
# z hashem w `/build/assets/*` dostają rok `immutable`, manifest `no-cache`.
# Mutacja wraca do dawnej, szerokiej reguły `/build/*` — tej, która dawała
# manifestowi bez hasha roczny cache — i test ma zapalić.
CADDYFILE = "docker/Caddyfile"
CACHE_MANIFESTU_TEST = "test_manifest_bez_hasha_nie_dostaje_rocznego_cache_assetow"
# Referrer-Policy w Caddy tylko jako wartość domyślna (audyt A5-01, #1052).
# Mutacja zdejmuje prefiks `?`, czyli wraca do `set`, które przez odroczenie
# operacji nadpisywało `no-referrer` ze stron z sekretem w adresie.
REFERRER_CADDY_TEST = "test_naglowek_zalezny_od_strony_jest_w_caddy_tylko_wartoscia_domyslna"

# Limit ciała żądania w Caddy (audyt A5-16). Strażnik czyta `docker/Caddyfile`:
# każda trasa ze zdjęciem stoi poza progiem 2 MB. Mutacja zdejmuje
# `/ustawienia/zdjecie` z listy odmowy 413 — zdjęcie profilowe powyżej 2 MB
# dostałoby wtedy „Za duże żądanie", a test ma zapalić.
CADDY_LIMIT_TEST = "CaddyLimitCialaZadaniaTest"
CADDY_LIMIT_WYJATKI = "@zaDuzeBezPlikow {\n\t\tnot path /dodaj/* /pytania /przepisy/* /wpisy/* /ustawienia/zdjecie "

# Rejestr wyjątków nazywa tylko istniejące symbole (audyt A5-18). Strażnik
# czyta własną stałą REJESTR; mutacje wracają do nazw sprzed poprawki —
# klasy, której nie ma, i stałej, której model nie definiuje.
REJESTR_WYJATKOW = "tests/Feature/WrazliweKolumnyPozaMasowymPrzypisaniemTest.php"
REJESTR_WYJATKOW_TEST = "test_rejestr_nazywa_tylko_istniejace_klasy_i_stale"
# Wiersz `media` i zadanie przetwarzania w jednej transakcji (issue #1456).
# Test wymusza fizyczną odmowę INSERT-u do `jobs`; mutacja wynosi dispatch
# z powrotem ZA granicę transakcji i kompensacji — test ma wtedy oblać, bo
# zostaje wiersz `pending` bez zadania i pliki w buckecie.
STORE_UPLOADED_IMAGE = "app/Domain/Media/Actions/StoreUploadedImage.php"
ZLECENIE_ZDJECIA_TEST = "ZlecenieZdjeciaWTransakcjiTest"

# Strażnik hosta magazynu R2 (D-255). Mutacja 1 przepuszcza endpoint bez
# jurysdykcji `eu` (i każdą inną jurysdykcję), mutacja 2 zdejmuje kotwicę
# końca, więc przechodzi host podszywający się sufiksem.
STRAZNIK_R2 = "app/Support/Storage/DozwolonyHostR2.php"
STRAZNIK_R2_TEST = "test_straznik_r2_odrzuca_host_spoza_wzoru"
# Ostrzeżenie o zmianie adresu utrwala STARY adres przy prośbie (#888).
# Mutacja wraca do `$user->notify()` — adres czytany przy wysyłce, po
# potwierdzeniu już nowy — i test przechodzący przez kolejkę ma zapalić.
OSTRZEZENIE_888 = "app/Domain/Users/Actions/RequestEmailChange.php"
OSTRZEZENIE_888_TEST = "OstrzezenieZmianyAdresuWKolejceTest"
WZOR_R2 = r"""'/^[0-9a-f]{32}\.eu\.r2\.cloudflarestorage\.com$/'"""
# Polityka obiecuje zdjęcia w UE, bo strażnik wymusza jurysdykcję `eu` (D-255).
# Ta sama mutacja strażnika co wyżej musi zapalić też test polityki — dowód,
# że obietnica stoi na kodzie, a nie na zmiennej środowiskowej.
POLITYKA_R2_TEST = "test_polityka_nie_obiecuje_jurysdykcji_r2_bez_pokrycia_w_endpoincie"
R2_ZAPIS_WERYFIKACJI = "docs/infra/LOKALIZACJA_DANYCH_R2.md"
R2_ZAPIS_WERYFIKACJI_TEST = "test_data_sprawdzenia_r2_w_polityce_to_ostatni_zapis_weryfikacji"

# Dokumenty prywatności o awatarach zgodne z kodem (#1461, D-240). Mutacja 1
# dopisuje w kontrolerze prawdziwe zlecenie zadania — dokumenty mówią wtedy
# nieprawdę („nie zleca”) i test ma zapalić, bo źródłem prawdy jest kod.
# Mutacja 2 przywraca w DATABASE.md dawne zdanie o aktywnej ocenie awatara.
KONTROLER_AWATARA = "app/Http/Controllers/Settings/AvatarSettingsController.php"
DOKUMENTACJA_AWATARA_TEST = "DokumentacjaAwataraZgodnaZKodemTest"
AWATAR_KOMENTARZ = "        // Awatar dalej podlega zgłoszeniom od ludzi, jak każda treść.\n"
INDEKS_BAZY_TEST = "IndeksDokumentacjiBazyTest"
DATABASE_DOC = "docs/baza/zgloszenia.md"  # opis schematu bazy leży w docs/baza/, DATABASE.md to indeks
AWATAR_DATABASE = "Wprowadził ją automat oceny\nzdjęć profilowych (issue #237), a oznaczenie wskazywało `media.id`."

# Awans roli z powłoki gasi sesje sprzed awansu (#1315). Test chodzi po HTTP
# w osobnych procesach; bez tej linijki stara sesja wchodzi do panelu.
ZMIANA_ROLI = "app/Domain/Users/Actions/ChangeUserRole.php"
AWANS_ROLI_TEST = "AwansRoliWymagaNowejSesjiTest"
# Wybór kolażu należy do wpisu (#955). Strażnik ładuje migrację przez
# `base_path(...)` i sprawdza definicję złożonego FK w `pg_constraint`.
# Mutacja zdejmuje z migracji `ON DELETE CASCADE` — FK nadal istnieje, więc
# sama obecność constraintu przeszłaby zielono; test ma zapalić na definicji.
MIGRACJA_HERO_PICKS = "database/migrations/2026_09_24_100000_powiaz_hero_picks_z_post_media.php"
HERO_PICKS_TEST = "test_schemat_wymusza_pare_wpisu_i_zdjecia_z_kaskada"
# #1289: odnośnik „Zobacz…” na landingu nie może prowadzić gościa do trasy
# z grupy `auth`. Mutacja przywraca stary cel pod nową etykietą.
LANDING = "resources/views/pages/landing.blade.php"
LANDING_PODGLAD_TEST = "test_odnosniki_podgladu_na_landingu_nie_odsylaja_goscia_do_logowania"
# Token wydania Livewire (#977). Mutacja wraca do stałego 'a' sprzed poprawki:
# karta sprzed wdrożenia znów wysyłałaby migawkę starego kodu bez odmowy.
LIVEWIRE_KONFIG = "config/livewire.php"
LIVEWIRE_TOKEN_TEST = "LivewireReleaseTokenZWydaniaTest"

# Stan zapisu kreatora dla komunikatu „Ta strona jest nieaktualna” (#977).
# Mutacja każe kreatorowi mówić „szkic” przed pierwszym zapisem: komunikat po
# 419 obiecałby, że szkic zostaje, choć w bazie nic nie ma.
KREATOR_WIDOK = "resources/views/components/recipe-wizard.blade.php"
KREATOR_ZAPIS_TEST = "KreatorWystawiaStanZapisuDlaStronyNieaktualnejTest"
# #1387: klient nie podmienia identyfikatora przepisu w kreatorze. Mutacja zdejmuje
# `#[Locked]` z `$recipeId`. Ponowna autoryzacja stoi w DWÓCH miejscach
# (`existingRecipe()` i `PublishRecipe`), więc zdjęcie jednej z nich jest
# maskowane przez drugą — kontrola jednoplikowa nie może jej zapalić; test
# czerwienieje dopiero po zdjęciu obu (sprawdzone ręcznie przy #1387).
KREATOR_AUTORYZACJA_TEST = "KreatorPilnujeAutoryzacjiIIdentyfikatoraPrzepisuTest"
# #1387, krok 9: autozapis i rewizje w osobnych klasach (`app/Support/KreatorPrzepisu`).
KREATOR_REWIZJA = "app/Support/KreatorPrzepisu/RewizjaTresci.php"
KREATOR_STRONA_NIEAKTUALNA_TEST = "NieaktualnyFormularzPrzepisuTest"
# Jeden test, nie cała klasa (#1011): bez walidacji mutacja zapala w klasie
# kilkanaście objawów naraz (długość kolumn, CHECK-i, stan `saved`), a wzorzec
# na wszystkie pasowałby do niemal wszystkiego. Tytuł 181 znaków w pierwszym
# autozapisie to reguła wprost: surowe pole nie może dojść do bazy.
KREATOR_WALIDACJA_PRZED_ZAPISEM_TEST = "test_pierwszy_autozapis_nie_wysyla_181_znakowego_tytulu_do_bazy"
KREATOR_BLAD_PRZEPISU_TEST = "PublikacjaZBledemPrzepisuZostajeNaWlasciwymKrokuTest"
KREATOR_ZAPIS = "$recipeId === null ? 'brak' : ($juzOpublikowany ? 'opublikowany' : 'szkic')"
# Wyjęcie ze wszystkich zeszytów jest atomowe (#1384). Mutacja zdejmuje
# `DB::transaction` z `remove()` — pierwsze odpięcie zostaje po awarii drugiego.
WYJECIE_PRZEPISU = "app/Domain/Collections/Actions/SaveRecipeToCollection.php"
WYJECIE_WPISU = "app/Domain/Collections/Actions/SavePostToCollection.php"
WYJECIE_ATOMOWE_TEST = "test_awaria_drugiego_odpiecia_zostawia_wszystkie_zapisy_z_notatkami"

# Złożenie odwołania razem z zawiadomieniami administratorów (#1305). Mutacja
# zamienia transakcję na zwykłe wywołanie domknięcia.
ODWOLANIE_AUTORA = "app/Domain/Moderation/Actions/FileAppeal.php"
ODWOLANIE_ZGLASZAJACEGO = "app/Domain/Moderation/Actions/FileReporterAppeal.php"
ODWOLANIE_AUTORA_TEST = "test_awaria_przy_drugim_administratorze_nie_zostawia_pisma_autora"
ODWOLANIE_ZGLASZAJACEGO_TEST = "test_awaria_przy_drugim_administratorze_nie_zostawia_pisma_zglaszajacego"


def odwolanie_bez_transakcji(uzyte):
    def mutacja(source):
        source = replace_once(
            source,
            "$odwolanie = DB::transaction(function () use (" + uzyte + "): Appeal {",
            "$odwolanie = (function () use (" + uzyte + "): Appeal {",
        )
        return replace_once(
            source,
            "            return $odwolanie;\n        });\n",
            "            return $odwolanie;\n        })();\n",
        )
    return mutacja


# Timeout własnej blokady po udanej rezerwacji u rodzica (#1393). Test jest
# behawioralny; mutacja przywraca `return false` z `catch`, który pomijał
# zwrot miejsca do wspólnej puli poczty.
BUDZET_POCZTY = "app/Poczta/DziennyBudzetListow.php"
BUDZET_POCZTY_TEST = "test_timeout_wlasnej_blokady_oddaje_miejsce_we_wspolnej_puli"
# Klucz preview środowiska PR (#975). Zachowanie skryptu mierzą testy
# behawioralne; ten wpis pilnuje jedynego testu czytającego entrypoint —
# mutacja odcina wywołanie przed odmową startu i ma go zapalić.
ENTRYPOINT = "docker/entrypoint.sh"
KLUCZ_PREVIEW_TEST = "test_entrypoint_nadaje_klucz_preview_przed_odmowa_startu"
# Akcja zapisu do zeszytu sama sprawdza prawo do zeszytu (#942). Test woła
# akcję BEZPOŚREDNIO, z pominięciem kontrolera, więc walidacja
# `collection_id` w kontrolerze go nie ratuje. Mutacja zdejmuje `authorize`
# osobno z akcji przepisu i z akcji wpisu — każda ma zapalić ten sam test.
ZAPIS_PRZEPISU = "app/Domain/Collections/Actions/SaveRecipeToCollection.php"
ZAPIS_WPISU = "app/Domain/Collections/Actions/SavePostToCollection.php"
ZAPIS_CUDZY_ZESZYT_TEST = "ZapisDoCudzegoZeszytuWAkcjiTest"
OFFLINE_HTML = "public/offline.html"
OFFLINE_PONOWIENIE_TEST = "test_offline_ma_droge_powrotu_i_obydwa_dotychczasowe_motywy"
ENTRYPOINT = "docker/entrypoint.sh"
KOLEJKI_BEZ_GLODZENIA_TEST = "KolejkiBezGlodzeniaTest"
UMOWA_KOLEJKI_TEST = "UmowaKolejkiTest"
DEMO_SEEDER = "database/seeders/DemoSeeder.php"
DEMO_SEEDER_HASLO_TEST = "DemoSeederNieWypisujeHaslaTest"
# #1295: mutacja przywraca dawne wypisanie hasła bez rozróżnienia źródła.
WARUNEK_HASLA_Z_OTOCZENIA = "        if ($this->hasloZOtoczenia() !== '') {"
AUTORYZACJA_ZESZYTU = "        Gate::forUser($user)->authorize('addItem', $collection);\n"
# Jeden kontrakt danych karty wpisu (#1037). Test jest behawioralny: renderuje
# siedem list i liczy zapytania. Mutacje zdejmują ze wspólnej listy zdjęcie
# przepisu i tematy — każda ma zapalić test na wszystkich zależnych
# powierzchniach, czyli dowieść, że listy naprawdę idą przez `dlaKarty()`.
KONTRAKT_KARTY = "app/Models/Post.php"
KONTRAKT_KARTY_TEST = "KartaWpisuJednymKontraktemTest"
# Testy w CI idą w czterech równoległych częściach (24.09.2026). Plik, który
# nie trafi do żadnej części, nie uruchamia się nigdzie, a przebieg jest zielony.
# Pierwsza mutacja gubi plik w SAMYM ODKRYWANIU listy — własny sprawdzian
# skryptu jej nie widzi (porównuje części ze swoją, też krótszą listą), więc
# zapalić ma porównanie z listą PHPUnita. Druga skraca macierz w ci.yml.
PODZIAL_TESTOW = "scripts/podzial-testow.php"
PODZIAL_TESTOW_TEST = "PodzialTestowJestKompletnyTest"
# Turnstile wiąże token z hostem i formularzem (#992). Każda mutacja zdejmuje
# jedno porównanie w `KlientTurnstile` — test tej gałęzi ma wtedy oblać.
KLIENT_TURNSTILE = "app/Turnstile/KlientTurnstile.php"
TURNSTILE_HOST_TEST = "test_host_spoza_listy_jest_odrzucany"
TURNSTILE_AKCJA_TEST = "test_akcja_innego_formularza_jest_odrzucana"
# Graf modułów app/Domain bez cykli (#971). Strażnik czyta tokeny PHP
# w `app/Domain`; mutacja przywraca import `Social` w `ZalozKonto`, czyli
# dokładnie tę krawędź, która zamykała cykl `Users ↔ Social`.
ZALOZ_KONTO = "app/Domain/Users/Actions/ZalozKonto.php"
GRAF_MODULOW_TEST = "GrafModulowDomenyBezCykliTest"
# #2149: retencja sygnałów Analytics używa `App\Support\UsuwanieWPartiach`.
# Mutacja wraca do importu z Compliance — krawędź Analytics → Compliance.
PRZEDAWNIONE_SYGNALY = "app/Domain/Analytics/PrzedawnioneSygnaly.php"
# #2149 etap 2: `DostepDoZdjecia` bierze nazwy typów celu z `Report::TARGET_*`,
# nie z Moderation. Mutacje: powrót importu (Media → Moderation) i nowy
# cykl (Users → Moderation przy istniejącym Moderation → Users) — ma zapalić graf.
DOSTEP_DO_ZDJECIA = "app/Domain/Media/DostepDoZdjecia.php"
ERASE_ACCOUNT_DATA = "app/Domain/Users/Actions/EraseAccountData.php"
# #2149 etap 3: retencja spraw (Compliance) odświeża liczniki przez kontrakt
# `App\Support\OdswiezanieLicznikowKolejek`, a `DziennyBudzetListow` mieszka
# w `App\Poczta`. Mutacje przywracają importy Compliance → Moderation
# i Moderation → Security — graf ma zapalić.
PRZEDAWNIONE_SPRAWY = "app/Domain/Compliance/PrzedawnioneSprawyModeracyjne.php"
ALARMUJ_MODERATORA = "app/Domain/Moderation/Actions/AlarmujModeratora.php"
# #970: `app/Domain` nie zależy od `Illuminate\Http` (Collections są czyste,
# reszta ma jawne wyjątki). Mutacja dokłada `use Illuminate\Http\Request;`
# do akcji spoza list wyjątków.
ZESZYTY_AKCJA_NOTATKI = "app/Domain/Collections/Actions/UpdateCollectionItemNote.php"
ZESZYTY_HTTP_TEST = "DomenaNieZalezyOdHttpTest"
# Form Request zeszytu: Policy `update` przed polami. Mutacja wycina ją.
ZAPIS_ZESZYTU_REQUEST = "app/Http/Requests/Collections/ZapisZeszytuRequest.php"
ZESZYTY_FORMULARZ_TEST = "FormularzZeszytuKolejnoscSprawdzenTest"
# Kontrolery Google i Facebooka są adapterami nad `WejdzPrzezDostawce` (#1035).
# Mutacja wkleja do kontrolera Google własne `Auth::login` przed odpowiedzią —
# kopię wspólnej reguły wejścia — i ma zapalić strażnika architektury.
KONTROLER_GOOGLE = "app/Http/Controllers/Auth/GoogleLoginController.php"
ADAPTERY_DOSTAWCOW_TEST = "KontroleryDostawcowSaAdapteramiTest"

# Reguła doboru treści (AGENTS.md §8, D-275, #1806): tygodniowy list nie układa
# wpisów po liczbie „Ugotowałem”. Mutacja podmienia sortowanie wpisów
# obserwowanych w `ZbierzTresciDigestu` z czasu publikacji na licznik wykonań —
# dowód, że strażnik naprawdę skanuje `app/Domain/Digest`, a nie tylko feed.
# Filtr na samą metodę głównego pomiaru, żeby czerwień pochodziła z reguły,
# a nie z kotwic zasięgu w sąsiednich metodach.
DIGEST_DOBOR = "app/Domain/Digest/ZbierzTresciDigestu.php"
DIGEST_DOBOR_TEST = "test_zaden_feed_nie_sortuje_po_mierze_cudzych_reakcji"
WPUSC_GOOGLE = "        return match ($this->wejscie()->wpusc(ZadanieHttp::z($request), $user)) {\n"

# Tryb ścisły Eloquent poza produkcją (#976). Mutacja usuwa samo włączenie
# z `AppServiceProvider` — test kontraktu ma zapalić, że ochron nie ma.
TRYB_SCISLY = "app/Providers/AppServiceProvider.php"
TRYB_SCISLY_TEST = "TrybScislyEloquentTest"
# Dokumentacja API (D-270): każda trasa `/api/v1` ma wiersz w tabeli
# `docs/API.md`. Mutacja wycina wiersz feedu — strażnik ma zauważyć trasę
# bez opisu.
DOKUMENTACJA_API = "docs/API.md"
DOKUMENTACJA_API_TEST = "ApiJestUdokumentowaneTest"
WIERSZ_FEEDU = "| `GET /api/v1/feed` | wpisy obserwowanych, chronologicznie | token | to samo zapytanie co strona główna |\n"

# Prywatne ukrycia bez agregacji (#1810, D-278): moderacja i analityka nie
# czytają tabeli `hides`. Mutacja dokłada do pliku moderacji import modelu
# ukryć — strażnik skanujący `app/Domain/Moderation` ma zapalić się na czerwono.
# „Mój stół” (#1749, D-304): półka dobiera wyłącznie regułami z zamkniętej
# listy AGENTS.md §8. Mutacja podmienia kolejność półki z czasu publikacji na
# licznik wykonań — strażnik ma zobaczyć nowe miejsce, a nie tylko feed sprzed
# półki. Kontrola dodatnia przed mutacją: ten sam test co DIGEST_DOBOR_TEST.
MOJ_STOL = "app/Domain/Feed/MojStol.php"

UKRYCIA_BEZ_AGREGACJI = "app/Domain/Moderation/CelZgloszenia.php"
UKRYCIA_BEZ_AGREGACJI_TEST = "test_bez_agregacji_moderacja_i_analityka_nie_czytaja_ukryc"

# Metryki doboru (#1814, D-283) nie czytają ukryć ani reakcji „Smakowicie
# wygląda”. Mutacja dokłada do klasy metryk import modelu reakcji — strażnik
# skanujący ten plik ma zapalić się na czerwono.
METRYKI_BEZ_REAKCJI = "app/Domain/Analytics/MetrykiDoboru.php"
METRYKI_BEZ_REAKCJI_TEST = "test_nie_czyta_ukryc_ani_reakcji"

# Strona „Jak dobieramy wpisy” (#1811, D-305): każde zdanie ma dowód w kodzie.
# Mutacja zmienia porządek w rundzie rotacji Odkrywania — fragment, na który
# powołuje się zdanie o rotacji, znika i test dowodów ma zapalić się na czerwono.
DOBOR_ROTACJA = "app/Domain/Feed/DiscoverFeed.php"
DOBOR_STRONA_TEST = "JakDobieramyWpisyMowiPrawdeTest"

# Wersja regulaminu w konfiguracji i data w nagłówku dokumentu (#1811, D-306).
# Mutacja podbija samą wersję — pasek ogłaszałby zmianę, której w dokumencie
# nie ma; test daty ma oblać.
REGULAMIN_WERSJA = "config/kuking.php"
REGULAMIN_WERSJA_TEST = "ZmianaRegulaminuTest"
# Regulamin §13 „Wymagania techniczne” i §14 „Reklamacje” (#2220).
REGULAMIN_WYMAGANIA_TEST = "RegulaminWymaganiaIReklamacjeTest"
# Archiwum wersji regulaminu (#2220, kryterium 4). Mutacje: poprawka regulaminu
# bez kopii w pliku bieżącej wersji, nowa data bez nowego pliku, plik archiwum
# z inną datą w nagłówku niż w nazwie — strażnik archiwum ma oblać.
REGULAMIN_TEKST = "resources/legal/regulamin.md"
REGULAMIN_ARCHIWUM_NAJSTARSZA = "resources/legal/archiwum/regulamin-2026-09-07.md"
# Filtr na jedną metodę: mutacja daty zapala też testy stron (inny komunikat).
REGULAMIN_ARCHIWUM_TEST = "test_biezaca_wersja_regulaminu_ma_w_archiwum_plik_identyczny_z_regulaminem"
REGULAMIN_ARCHIWUM_NAGLOWEK_TEST = "test_kazdy_plik_archiwum_niesie_w_naglowku_date_ze_swojej_nazwy"
# Archiwum wersji polityki prywatności (#2220; decyzja z 30.09.2026: tylko
# 25, 29 i 30 września). Te same trzy mutacje co przy regulaminie oraz
# „starsza wersja z dziennika zgód dostaje gołe 404 zamiast strony o prośbie”.
POLITYKA_ARCHIWUM_NAJSTARSZA = "resources/legal/archiwum/polityka-prywatnosci-2026-09-25.md"
POLITYKA_ARCHIWUM_KLASA = "app/Domain/Zgody/ArchiwumDokumentu.php"
POLITYKA_ARCHIWUM_TEST = "test_biezaca_wersja_polityki_ma_w_archiwum_plik_identyczny_z_polityka"
POLITYKA_ARCHIWUM_NAGLOWEK_TEST = "test_kazdy_plik_archiwum_polityki_niesie_w_naglowku_date_ze_swojej_nazwy"
POLITYKA_ARCHIWUM_PROSBA_TEST = "test_wersja_polityki_sprzed_archiwum_z_dziennika_zgod_mowi_o_wydaniu_na_prosbe"

# Pasek o zmianie polityki (D-327, D-332): rollback bez odmowy i sekcja
# „Co się zmieniło” niezgodne z konfiguracją (drobna/istotna); pasek przy drobnej;
# wybór formy: ukryty w okresie przejściowym zmiany istotnej, od razu przy drobnej.
POLITYKA_PASEK_MIGRACJA = "database/migrations/2026_09_29_180000_add_policy_notice_dismissed_version_to_users.php"
POLITYKA_PASEK_TEST = "ZmianaPolitykiTest"
POLITYKA_PASEK_KLASA = "app/Domain/Zgody/ZmianaPolityki.php"
UDOSTEPNIANIE = "app/Domain/Sharing/Udostepnianie.php"
# Filtr na jeden test: mutacja zapala też test liczby zapytań (inny komunikat).
PODZIEL_SIE_ZESZYT_WSPOLNY_TEST = "test_zeszyt_wspolny_publiczny_dostaje_przycisk"
POLITYKA_TEKST = "resources/legal/polityka-prywatnosci.md"

# Data publikacji osobno od daty wejścia w życie (D-327). Mutacje: okres
# przejściowy znika (zmiana istotna obowiązuje od razu), 14 dni zamienia się
# w zero, zgoda zapisuje wersję opublikowaną zamiast obowiązującej.
WERSJA_DOKUMENTU = "app/Domain/Zgody/WersjaDokumentu.php"
WERSJA_DOKUMENTU_ZGODA = "app/Domain/Zgody/PrzestawZgodeNaDigest.php"
WERSJA_DOKUMENTU_TEST = "WersjaDokumentuTest"

# `@railway/cli` bez przypiętej wersji, obok tokenu produkcji (audyt B10-02).
# Mutacja zdejmuje `@5.62.1` z instalacji w `deploy.yml` — test ma zauważyć
# brak `@X.Y.Z` po `@railway/cli`.
RAILWAY_CLI_WORKFLOW = ".github/workflows/deploy.yml"
RAILWAY_CLI_TEST = "RailwayCliPrzypietaWersjaTest"
# Token Railway bez zapasowego środowiska (#2255). Strażnik OBLICZA wyrażenie
# tokenu z `deploy.yml`; mutacja przywraca `production && PRODUCTION || STAGING`,
# które przy braku sekretu produkcji podaje token staginu.
TOKEN_RAILWAY_TEST = "TokenRailwayBezZapasowegoSrodowiskaTest"
TOKEN_RAILWAY_NOWY = ("${{ (github.event.inputs.environment == 'production' && secrets.RAILWAY_TOKEN_PRODUCTION)\n"
                      "            || (github.event.inputs.environment == 'staging' && secrets.RAILWAY_TOKEN_STAGING)\n"
                      "            || '' }}")
TOKEN_RAILWAY_STARY = ("${{ github.event.inputs.environment == 'production'\n"
                       "            && secrets.RAILWAY_TOKEN_PRODUCTION\n"
                       "            || secrets.RAILWAY_TOKEN_STAGING }}")
# Runbook nie każe instalować niewdrożonych Sentry i PostHog (#1010). Strażnik
# czyta dokument; mutacje przywracają do części wykonywanej (poza `<details>`)
# polecenie instalacji pakietu i wiersz z kluczem PostHog w tabeli zmiennych.
RUNBOOK = "docs/infra/DEPLOYMENT_RUNBOOK.md"
RUNBOOK_USLUGI_TEST = "RunbookNieKazeInstalowacNiewdrozonychUslugTest"
RUNBOOK_KROK_4 = "3. Próba po wdrożeniu stoi w kroku 11.3 (punkt 19).\n"
RUNBOOK_WIERSZ_WEBHOOKA = "| `LOG_BLAD_WEBHOOK_URL` | z kroku 4 |"
# Awaria eksportu danych dociera do kolejki (#822). Testy łapały kiedyś
# `\Throwable`, więc połykały własne `fail()`; job bez `throw $e` po
# `markFailed()` przechodził, a kolejka nie wiedziała o porażce. Mutacja
# zdejmuje ten rethrow — oba testy mają oblać na braku wyjątku.
#
# Kotwica obejmuje `usunOsieroconaPaczke()` (audyt B5 pkt 4, PR #1721): ten
# wiersz stanął między `markFailed()` a `throw $e`, a stara kotwica
# („markFailed, pusta linia, throw”) przestała pasować — replace_once rzucał
# RuntimeError i cały krok „Kontrole negatywne” padał na main. Mutacja zdejmuje
# nadal WYŁĄCZNIE rethrow; sprzątanie osieroconej paczki zostaje nietknięte,
# żeby kontrola dowodziła jednej rzeczy: że testy łapią brak wyjątku.
EKSPORT_JOB = "app/Jobs/GenerateUserExport.php"
EKSPORT_PORAZKA_TEST = "test_niepowodzenie_ustawia_status_failed_z_powodem|test_powod_niepowodzenia_eksportu_nigdy"
# Od #823 `markFailed` stoi pod `if ($this->bedzieKolejnaProba())` — punkt
# mutacji to samo sprzątanie paczki tuż przed `throw $e;`.
EKSPORT_BEZ_RETHROW = "            $this->usunOsieroconaPaczke($export);\n\n"
EKSPORT_RETHROW = EKSPORT_BEZ_RETHROW + "            throw $e;\n"
# Dalsze okna wyszukiwania za kursorem rankingu (#1023). Mutacja gubi kursor
# obu list, czyli wraca do samego liczbowego `OFFSET`; test dopisania ma
# zobaczyć duplikat, test ukrycia — pominięcie.
SZUKAJ_KONTROLER = "app/Http/Controllers/SearchController.php"
STABILNE_OKNA_TEST = "StabilneOknaWyszukiwaniaTest"
KURSOR_REAL = "app/Domain/Search/SearchQuery.php"


def bez_kursora_wyszukiwania(source):
    source = replace_once(source, "$poPrzepisie = $odPrzepisu > 0 ? $this->kursor($request, 'po_przepisie') : null;", "$poPrzepisie = null;")
    return replace_once(source, "$poOsobie = $odOsoby > 0 ? $this->kursor($request, 'po_osobie') : null;", "$poOsobie = null;")
EKSPORT_DANE = "app/Domain/Users/Exports/CollectUserExportData.php"
EKSPORT_KLUCZE_TEST = "EksportKluczeBezRodzajuTest"
# #1993: samo usunięcie pola z mapy eksportu musi oblać test obu wartości.
EKSPORT_WIDOCZNOSC_TEST = "EksportWidocznosciWartosciOdzywczychTest"
EKSPORT_WIDOCZNOSC_POLE = "            'pokazuj_wartosci_odzywcze' => (bool) $recipe->pokazuj_wartosci_odzywcze,\n"
# Widoczność treści w filtrze powiadomień (#1687). Test kontraktowy porównuje
# `WidocznoscTresciSql` z Policy na macierzy stanów; każda mutacja zdejmuje
# jedną regułę z SQL i macierz ma pokazać rozjazd z Policy.
WIDOCZNOSC_TRESCI_SQL = "app/Domain/Widocznosc/WidocznoscTresciSql.php"
POWIADOMIENIA_ZGODNE_Z_POLICY_TEST = "test_filtr_powiadomien_odpowiada_jak_policy_na_calej_macierzy"
# #1746: kucharz w karencji usunięcia konta (`CookedEventPolicy::view()` p. 3a).
KUCHARZ_W_KARENCJI = """            ->where(function (QueryBuilder $kucharz) use ($widzId): void {
                $kucharz->where('ce.user_id', $widzId)
                    ->orWhereNotExists(function (QueryBuilder $konto): void {
                        $konto->selectRaw('1')
                            ->from('users as kucharze')
                            ->whereColumn('kucharze.id', 'ce.user_id')
                            ->where('kucharze.status', User::STATUS_PENDING_DELETE);
                    });
            })
"""
# #1747: zapowiedź przepisu ma bramkę w przepisie (`PostPolicy::view()`).
BRAMKA_ZAPOWIEDZI = "        if ($tabela === 'posts') {\n            self::bramkaZapowiedziPrzepisu($sub, $a, $widz);\n        }\n"
EKSPORT_DANE = "app/Domain/Users/Exports/CollectUserExportData.php"
EKSPORT_KLUCZE_TEST = "EksportKluczeBezRodzajuTest"
# Bramka produkcji przed jobem `plan` w IaC Railway (audyt B10-01). Job
# wykonuje `.railway/railway.ts` Z GAŁĘZI PR-a z tokenem
# RAILWAY_TOKEN_PRODUCTION; mutacja zdejmuje `environment: production` z
# joba `plan` (i tylko z niego — kotwica bierze fragment poprzedzający
# unikalny dla `plan`, żeby nie ruszyć tego samego ustawienia w `apply`).
PLAN_IAC_WORKFLOW = ".github/workflows/railway-iac.yml"
PLAN_IAC_TEST = "PlanIacBramkaProdukcjiTest"
PLAN_IAC_ENVIRONMENT = (
    "    # przejrzał diff `.railway/**`.\n"
    "    environment: production\n"
)
FORMA_MIGRACJA = "database/migrations/2026_09_25_140000_add_form_of_address_to_profiles.php"
FORMA_WYMAZANIE = "app/Domain/Users/Actions/EraseAccountData.php"
FORMA_TEST = "FormaZwracaniaSieTest"
FORMA_HELPER = "app/Support/Forma.php"
FORMA_KONIEC_ONBOARDINGU = "resources/views/pages/onboarding/done.blade.php"
FORMA_ODKRYWANIE = "resources/views/components/pusty-stan-odkrywania.blade.php"
FORMA_TEKSTY_TEST = "FormaTekstyTest"
TEKSTY_BEZ_PLCI_TEST = "TekstyNiePrzypisujaPlciTest"

GOOGLE_LINK_WIDOK = "resources/views/auth/google-link.blade.php"
GOOGLE_LINK_TEST = "test_widoki_nie_przypisuja_czytelnikowi_plci"
# Wspólna maszyna epizodu alarmu (#972). Cisza ma być kupowana WYŁĄCZNIE
# przyjętym dzwonkiem: nieudana próba daje tylko krótkie ponowienie. Mutacja
# wyjmuje ustawienie `cisza_do` spod `if ($przyjeto)` — wtedy odrzucony webhook
# wycisza epizod na godziny. Jeden punkt mutacji w komponencie ma zapalić
# i test samej maszyny, i test obu czujek, które z niej korzystają.
EPIZOD_ALARMU = "app/Domain/Monitoring/EpizodAlarmu.php"
EPIZOD_ALARMU_TEST = "EpizodAlarmuTest|NieudanyDzwonekNieKupujeCiszyTest"
CISZA_TYLKO_PO_PRZYJECIU = (
    "            $pamiec['cisza_do'] = $this->teraz() + $ciszaGodzin * 3600;\n"
    "        }\n"
)
CISZA_BEZ_WARUNKU = (
    "        }\n"
    "        $pamiec['cisza_do'] = $this->teraz() + $ciszaGodzin * 3600;\n"
)
# #957: pierwszy kafel kolażu hero (prawdopodobny LCP) bez `lazy`, z wysokim
# priorytetem. Mutacja przywraca bezwarunkowe `loading="lazy"` na każdym kaflu.
LANDING = "resources/views/pages/landing.blade.php"
KOLAZ_LCP_TEST = "KolazPowitalnyPriorytetLcpTest"
KOLAZ_PRIORYTET = """                                         @if($loop->first)
                                         fetchpriority="high"
                                         @else
                                         loading="lazy"
                                         @endif
"""
# Arkusz wydruku przepisu (#765): żadne pismo na kartce poniżej 12 pt.
# Strażnik czyta `wydruk-przepisu.css` i zbiera rozmiary z bloku `@media print`;
# mutacja zmniejsza pismo składników i kroków do 10 pt — test ma wtedy oblać,
# dowód, że parser widzi reguły druku, a nie pusty zbiór.
WYDRUK_CSS = "resources/css/wydruk-przepisu.css"
WYDRUK_TEST = "test_arkusz_druku_ma_prog_12_pt_i_nie_schodzi_ponizej"
DRUK_PORCJE_WIDOK = "resources/views/pages/recipes/show.blade.php"
DRUK_PORCJE_TEST = "test_glowny_link_drukowania_przenosi_wybrane_porcje_i_otwiera_przeliczona_kartke"
CZAS_KROKU_WIDOK_ZESZYTU = "resources/views/pages/collections/do-druku.blade.php"
CZAS_KROKU_STRONA_TEST = "test_strona_przepisu_i_jej_wydruk_pokazuja_czasy_przy_wlasciwych_krokach"
CZAS_KROKU_ZESZYT_TEST = "test_wydruk_zeszytu_pokazuje_czasy_przy_wlasciwych_krokach"
# Karta z kodem QR (#2349): ten sam arkusz, kod na papierze co najmniej 9 cm.
KARTA_QR_TEST = "test_arkusz_druku_dzieli_rame_z_przepisem_i_mierzy_kod_w_centymetrach"
# Ściągawka do wydruku (F4): ten sam arkusz, treść kartki co najmniej 16 pt
# i wspólna rama z przepisem (bez kopii reguł).
SCIAGAWKA_TEST = "test_arkusz_druku_obejmuje_sciagawke_duzym_drukiem"
# Wydruk „dla pomocnika” (#2345): ten sam arkusz, pismo kartki co najmniej
# 16 pt, kod QR co najmniej 3 cm.
POMOCNIK_TEST = "test_arkusz_druku_dla_pomocnika_ma_pismo_16_pt_i_kod_qr_min_3_cm"
# Objaśnienie „Zgłoś” gościa ma być ukryte w KAŻDYM wydruku (wspólna lista).
ZGLOS_GOSCIA_TEST = "test_kazdy_wydruk_chowa_objasnienie_zgloszenia_goscia"
# Zamknięcie grupy sygnałów tylko w stanie z ekranu (#1059, wariant b).
# Znacznik to liczba i najnowsze oznaczenie; każda z dwóch połówek łapie
# dopisanie, którego druga nie widzi. Mutacja 1 zdejmuje porównanie liczby
# (dopisanie w tej samej chwili z mniejszym UUID), mutacja 2 — porównanie
# kolejności (ktoś zamknął jedno, automat dopisał nowe: liczba ta sama).
GRUPA_SYGNALOW = "app/Domain/Moderation/Actions/ZamknijGrupeSygnalow.php"
GRUPA_SYGNALOW_TEST = "ZbiorczeZamkniecieSygnalowTylkoZEkranuTest"
GRUPA_LICZBA_TEST = "test_dopisanie_w_tej_samej_chwili_lapie_liczba_oznaczen"
GRUPA_KOLEJNOSC_TEST = "test_nowe_oznaczenie_przy_tej_samej_liczbie_tez_daje_odmowe"
# Przerwany onboarding (#985): backfill istniejących kont w migracji i zapis
# końca pierwszych kroków wyłącznie w POST, nigdy w GET `/witaj/gotowe`.
MIGRACJA_ONBOARDINGU = "database/migrations/2026_09_24_130000_add_onboarding_zakonczony_at_to_users.php"
MIGRACJA_ONBOARDINGU_TEST = "OnboardingMigracjaZnacznikaTest"
ONBOARDING_KONTROLER = "app/Http/Controllers/OnboardingController.php"
ONBOARDING_WZNOWIENIE_TEST = "OnboardingWznowienieTest"
# IaC: plan produkcji tylko dla PR-a do `main` (#1313). Apply jest ręczny
# (workflow_dispatch z `main`, #595), więc zamki dotyczą joba plan: filtr
# `branches` w `on.pull_request` i `base.ref == 'main'` w jego warunku.
# Mutacje zdejmują po kolei każdy z nich.
IAC_PRODUKCJA = ".github/workflows/railway-iac.yml"
IAC_PRODUKCJA_TEST = "IacProdukcjaTylkoZPrDoMainTest"
README = "README.md"
README_SECURITY_TEST = "ReadmeISecurityMowiaPrawdeTest"
IAC_GALAZ_W_WARUNKU = "      github.event.pull_request.base.ref == 'main' &&\n"
# CHANGELOG bez zdublowanych wpisów (audyt po fali 26.09.2026): rozwiązanie
# konfliktu „obie strony” wstawiało ten sam wpis dwa razy. Mutacja wstawia
# dwa identyczne wpisy na początek „Nieopublikowane”.
CHANGELOG = "CHANGELOG.md"
CHANGELOG_DUPLIKATY_TEST = "ChangelogBezZdublowanychWpisowTest"
CHANGELOG_NAGLOWEK = "## Nieopublikowane\n\n"
# Archiwum starszych wersji (docs/changelog/, 2.10.2026): testy czytają je
# razem z CHANGELOG.md (tests/Support/PelnyChangelog.php).
CHANGELOG_ARCHIWUM = "docs/changelog/archiwum-alfa-0.70-0.74.md"
CHANGELOG_ARCHIWUM_LINK = "- [Alfa 0.69](docs/changelog/archiwum-alfa-0.69.md)\n"
CHANGELOG_NUMERACJA_TEST = "PodbicieWersjiWymagaWpisuWChangelogTest"
# Strażnik strony „Co nowego” (issue #1909, AGENTS.md §10): wpis CHANGELOGA
# oznaczony `[nowa funkcja]` w sekcji „## Nieopublikowane" ma odpowiadający
# akapit (`### ...`) w sekcji „## Najnowsze zmiany" pliku nowości.
CHANGELOG_NOWOSCI = "CHANGELOG.md"
STRAZNIK_NOWOSCI_TEST = "StraznikNowosciKazdaNowaFunkcjaMaAkapitTest"
# Dziennik wdrożeń (issue #1932, D-318, D-088): down() ma ODMÓWIĆ, gdy
# tabele `wdrozenia`/`wdrozenia_funkcje` mają choć jeden wiersz — numer
# wdrożenia jest już pokazany ludziom (stopka, „od Alfa 0.NN.NNN"), a cichy
# DROP TABLE zgubiłby numerację. Mutacja zdejmuje warunek odmowy.
MIGRACJA_DZIENNIK_WDROZEN = "database/migrations/2026_09_26_130000_utworz_dziennik_wdrozen.php"
DZIENNIK_WDROZEN_TEST = "test_cofniecie_odmawia_gdy_dziennik_ma_wiersze"
# Komendy IaC w dokumentacji z jawnym KUKING_WAIT_FOR_CI (#1390 × runbook,
# audyt po fali 26.09.2026). Mutacja zdejmuje zmienną z `apply` w runbooku.
RUNBOOK = "docs/infra/DEPLOYMENT_RUNBOOK.md"
KOMENDY_IAC_TEST = "KomendyIacWDokumentachPodajaBramkeCiTest"
RUNBOOK_APPLY_Z_BRAMKA = "\nKUKING_WAIT_FOR_CI=true railway config apply\n"
# Higiena dziennika decyzji (#2154): roboczy numer i martwe odwołanie D-NNN.
# Strażnik czyta treść repozytorium, więc kontrola dopisuje wadę do PRAWDZIWEGO
# pliku (AGENTS.md, dziennik) i ma zapalić. Numery składamy z kawałków: dosłowny
# zapis w scripts/ byłby dla testów numeracji cytatem z dziennika.
DZIENNIK_ODWOLANIA_TEST = "DziennikDecyzjiOdwolaniaTest"
DZIENNIK_ODWOLANIA_PLIK_TESTU = "tests/Feature/DziennikDecyzjiOdwolaniaTest.php"
DZIENNIK_MARTWE_ODWOLANIE = "\nZob. D-" + "999 (martwe odwołanie).\n"
DZIENNIK_ROBOCZY_NUMER = "\nZob. D-" + "1000-ROBOCZA.\n"
DZIENNIK_ROBOCZY_NAGLOWEK = "\n## D-" + "1000-ROBOCZA — Szkic\n\nTreść.\n"

# Pochodzenie żądania i zaufanie do proxy (#1306). Test bramki jest
# behawioralny; mutacja wyłącza odrzucenie w trybie egzekwowania — żądanie
# bez tokenu (czyli z pominięciem Cloudflare) wchodzi dalej i test ma oblać.
BRAMKA_KRAWEDZI = "app/Http/Middleware/NormalizeForwardedFor.php"
BRAMKA_KRAWEDZI_TEST = "test_egzekwowanie_odrzuca"
BRAMKA_KRAWEDZI_WARUNEK = "            if ($egzekwowanie) {\n                if (! TokenKrawedzi::wolnoBezTokenu($request)) {\n"
# Log Caddy a adres w aplikacji. Strażnik czyta `docker/Caddyfile` i odtwarza
# regułę `trusted_proxies`; mutacje wracają do czytania nagłówka od lewej
# i do zaufania każdemu peerowi — obie mają zapalić rozjazd log/aplikacja.
CADDY_ZAUFANIE_TEST = "CaddyUfaTemuSamemuWpisowiCoAplikacjaTest"
# Zbiór publicznych domen originu w IaC jest zamknięty (#1306): dopisanie
# kolejnej domeny (tu: domeny dostawcy) zmienia topologię, dla której policzono
# zaufanie proxy, i test ma oblać z opisem, co zrobić razem ze zmianą.
DOMENY_ORIGINU_IAC = ".railway/railway.ts"
DOMENY_ORIGINU_TEST = "DomenyOriginuSaZadeklarowaneWIacTest"
DOMENY_ORIGINU_WPIS = '  { domain: "www.kuking.pl", port: APP_PORT },\n];'


def digest(path):
    return hashlib.md5(path.read_bytes()).hexdigest()


def replace_once(source, old, new):
    if source.count(old) != 1:
        raise RuntimeError("Kontrola nie znalazła dokładnie jednego miejsca mutacji.")
    return source.replace(old, new, 1)


def kopie_przestaw_krok_po_wymazaniu(source, poczatek, srodek, koniec):
    """#2708: fizycznie przenieś instrukcję CSAM za komendy wymazania."""
    if any(source.count(marker) != 1 for marker in (poczatek, srodek, koniec)):
        raise RuntimeError("Kontrola nie znalazła dokładnie jednego kroku odtworzenia CSAM.")
    od = source.index(poczatek)
    sro = source.index(srodek, od)
    do = source.index(koniec, sro)
    return source[:od] + source[sro:do] + source[od:sro] + source[do:]


def zakupy_usun_bez_pytania(source):
    """#2466: przywróć bezpośredni formularz DELETE sprzed potwierdzenia."""
    poczatek = '        <details class="confirm planer-usuwanie">'
    koniec = '        </details>'
    if source.count(poczatek) != 1 or source.count(koniec) != 1:
        raise RuntimeError("Kontrola nie znalazła dokładnie jednego pytania o usunięcie zakupów.")
    od = source.index(poczatek)
    do = source.index(koniec, od) + len(koniec)
    dawny_formularz = '''        <form method="POST" action="{{ route('shopping.destroy', $pozycja) }}">
            @csrf @method('DELETE')
            <button class="btn btn-secondary" type="submit">Usuń<span class="visually-hidden">: {{ $pozycja->text }}</span></button>
        </form>'''
    return source[:od] + dawny_formularz + source[do:]


WYBOR_FORMY_WIDOK = "resources/views/components/wybor-formy.blade.php"
DOSTEPNOSC_FORMY_TEST = "DostepnoscFormyIOnboardinguTest"


def replace_wszystkie(source, old, new, ile):
    if source.count(old) != ile:
        raise RuntimeError("Kontrola nie znalazła oczekiwanej liczby miejsc mutacji.")
    return source.replace(old, new)


def remove_notice(source):
    start = source.index("@if($collectionError)")
    end = source.index("@endif", start) + len("@endif")
    return source[:start] + source[end:]


def bez_wpisu_dla_straznika(source):
    """KONTROLA DODATNIA 1: zabierz strażnikowi jego własny wpis w tym pliku.

    Strażnik szuka tu swojej nazwy klasy. Po podmianie nie znajdzie jej, uzna
    sam siebie za strażnika tekstu bez pokrycia i ma zapalić. Podmieniamy samą
    wartość stałej, nie wpis w `checks` — dzięki temu mutacja nie rusza tego,
    KTÓRY test zostanie uruchomiony (ten stoi już w pamięci procesu).
    """
    # Wzorzec SKŁADAMY ze stałej, nie wpisujemy go tu dosłownie. Dosłowny zapis
    # dawałby DRUGIE wystąpienie nazwy klasy w tym pliku, a wtedy `replace_once`
    # odmawia („nie znalazła dokładnie jednego miejsca mutacji") — i, co gorsza,
    # strażnik znajdowałby swoją nazwę także po mutacji, więc kontrola dodatnia
    # nigdy by nie zapaliła. Zmierzone przy pierwszym uruchomieniu, 20.09.2026.
    stara = 'STRAZNIK_TEKSTU_TEST = "' + STRAZNIK_TEKSTU_TEST + '"'

    return replace_once(source, stara, 'STRAZNIK_TEKSTU_TEST = "WpisZabranyPrzezKontroleDodatnia"')


def bez_znacznika_odstepstwa(source):
    """KONTROLA DODATNIA 2: zabierz plikowi kontrolnemu znacznik odstępstwa.

    Plik czyta źródło i asertuje na jego treści, a wpisu w `checks` nie ma.
    Bez znacznika zostaje strażnikiem tekstu bez pokrycia — strażnik ma zapalić
    z drugiej strony niż w kontroli 1.
    """
    start = source.index(" * @bez-kontroli-dodatniej")
    end = source.index("\n", start) + 1

    return source[:start] + source[end:]


def smaller_help(source):
    start = source.index(".composer-help {")
    end = source.index("}", start)
    block = replace_once(source[start:end], "var(--text-body)", "var(--text-help)")
    return source[:start] + block + source[end:]


def akcja_php_na_ruchomym_tagu(source):
    """KONTROLA DODATNIA: wróć w akcji composite do gołego tagu `@v2`.

    Workflow z `setup-php@v2` działa dalej zielono — dlatego tylko mutacja
    dowodzi, że `test_kazde_zewnetrzne_uses_ma_pelny_sha_i_dokladna_wersje`
    to zauważy.
    """
    return replace_once(
        source,
        "uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # 2.37.2",
        "uses: shivammathur/setup-php@v2",
    )


def bez_kopii_testu_assetow(source):
    """KONTROLA DODATNIA: zabierz etapowi `assets` pliki `node --test` z `scripts/`.

    Etap kopiuje dziś cały katalog (`COPY scripts ./scripts`). Mutacja cofa go
    do wyliczanki z jednym plikiem — samym `kontrast-marki.mjs`, którego
    potrzebuje pierwszy człon `build` — czyli do dokładnie tej regresji, przed
    którą chroni ten test: `package.json` nadal podaje do `node --test` pliki
    `scripts/*.test.mjs`, a Dockerfile przestaje je obiecywać. Test ma to
    zauważyć; Node sam by nie zauważył, bo brakujący plik pomija bez błędu.
    """
    return replace_once(
        source,
        "COPY scripts ./scripts\n",
        "COPY scripts/kontrast-marki.mjs ./scripts/kontrast-marki.mjs\n",
    )


def zdjecie_checku_przed_straznikiem_2fa(source):
    """KONTROLA DODATNIA: zdejmij CHECK 2FA, ZANIM strażnik policzy konta.

    Dokładnie ten błąd kolejności, którego pilnuje
    `test_straznik_stoi_przed_kazda_operacja_niszczaca`: odmowa nadal leci,
    ale schemat przestał już pilnować niezmiennika. Blok `isPostgres()`
    wędruje z miejsca po strażniku na początek `down()`.
    """
    zdjecie = (
        "        if ($this->isPostgres()) {\n"
        "            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS "
        "users_two_factor_confirmed_requires_secret_check');\n"
        "        }\n\n"
    )
    straznik = "        $zPotwierdzonym = DB::table('users')->whereNotNull('two_factor_confirmed_at')->count();\n"

    bez_zdjecia = replace_once(source, zdjecie, "")

    return replace_once(bez_zdjecia, straznik, zdjecie + straznik)


def stara_sonda_https(source):
    """KONTROLA DODATNIA: wróć do `case 301|302|307|308` bez sprawdzania celu.

    `przekierowanie_https_sprawdza_cel_a_nie_sam_kod` ma zapalić (#1332).
    """
    return replace_once(
        source,
        '          sonda_https "${BASE_URL#https://}" || fail=1\n',
        '          redirect=$(curl -sS -o /dev/null -w \'%{http_code}\' --max-time 20 "http://${BASE_URL#https://}/" || echo "000")\n'
        '          case "$redirect" in\n'
        '            301|302|307|308) echo "OK    HTTP przekierowuje ($redirect)" ;;\n'
        '            *) echo "BLAD  HTTP nie przekierowuje na HTTPS ($redirect)"; fail=1 ;;\n'
        '          esac\n',
    )


def test_dymny_bez_sondy_wydania(source):
    """KONTROLA DODATNIA: zdejmij sondę wydania sprzed sprawdzeń.

    `test_dymny_najpierw_potwierdza_pelny_sha_zdarzenia` ma zapalić (#1012):
    bez niej test dymny zdarzenia A znów sprawdza wydanie B.
    """
    return replace_once(
        source,
        '          if ! sonda_wydanie "$BASE_URL" "$OCZEKIWANY_SHA"; then\n'
        '            echo "::error title=Pod adresem działa inne wydanie::Oczekiwano ${OCZEKIWANY_SHA}, otrzymano ${SONDA_OTRZYMANY:-nic}. Test dymny nie sprawdza cudzego wydania."\n'
        '            exit 1\n'
        '          fi\n',
        "",
    )


def koncowa_sonda_jedna_proba(source):
    """KONTROLA DODATNIA: końcowa sonda wydania znów z jedną próbą.

    Ten sam test (#1012, przegląd #1439) ma zapalić: jedna chwilowa porażka
    sieci po testach oblewała całe wdrożenie.
    """
    return replace_once(
        source,
        '          sonda_wydanie_koncowa "$BASE_URL" "$OCZEKIWANY_SHA" || fail=1\n',
        '          SONDA_PROBY=1 sonda_wydanie "$BASE_URL" "$OCZEKIWANY_SHA" || fail=1\n',
    )


def akcja_rollback_wraca(source):
    """KONTROLA DODATNIA: przywróć akcję `rollback`, która niczego nie cofa.

    `zadna_akcja_nie_nazywa_sie_rollback_skoro_nic_nie_cofa` ma zapalić (#974).
    """
    return replace_once(
        source,
        "options: [smoke, migrate, redeploy, instrukcja-cofniecia]",
        "options: [smoke, migrate, redeploy, rollback]",
    )


def bez_digestu_obrazu_kopii(source):
    """KONTROLA DODATNIA: wróć w obrazie kopii do gołego, ruchomego tagu.

    `FROM postgres:18` buduje się dalej zielono — dlatego tylko mutacja
    dowodzi, że `test_kazdy_from_w_kazdym_dockerfile_ma_digest` to zauważy.
    """
    start = source.index("FROM postgres:18@sha256:")
    end = source.index("\n", start)

    return source[:start] + "FROM postgres:18" + source[end:]


def dispatch_zdjecia_poza_transakcja(source):
    source = replace_once(source, "ProcessUploadedImage::dispatch($media->getKey());", "null;")
    return replace_once(
        source,
        "            throw $e;\n        }\n\n        return $media;",
        "            throw $e;\n        }\n\n        ProcessUploadedImage::dispatch($media->getKey());\n\n        return $media;",
    )


def mniejsze_pismo_na_pierwszym_ekranie(source):
    """KONTROLA DODATNIA: zmieść przycisk pod zgięciem mniejszym pismem.

    Blok pierwszego ekranu dostaje `font-size` przy akapicie hasła — skrót,
    którego poprawka świadomie nie zrobiła (progi pisma z docs/UX_50_PLUS.md).
    `test_skrocenie_nie_zostalo_oplacone_pismem_ani_celem_dotkniecia` ma zapalić.
    """
    return replace_once(
        source,
        "    .hero .hero-lead {\n      margin-bottom: var(--spacing-4);\n",
        "    .hero .hero-lead {\n      font-size: 1rem;\n      margin-bottom: var(--spacing-4);\n",
    )


def bez_sprawdzenia_hosta(source):
    """KONTROLA DODATNIA: strażnik adresu zostaje przy samym `https://`."""
    return replace_once(
        source,
        "        if (! in_array(strtolower($uri->getHost()), $hosty, true)) {\n"
        "            return 'host spoza listy dostawcy';\n"
        "        }\n",
        "",
    )


def bez_sprawdzenia_sciezki(source):
    """KONTROLA DODATNIA: strażnik adresu przestaje patrzeć na ścieżkę."""
    return replace_once(
        source,
        "        if (preg_match($sciezka, $uri->getPath()) !== 1) {\n"
        "            return 'ścieżka spoza API dostawcy';\n"
        "        }\n",
        "",
    )
def apply_bez_bramki_ci(source):
    """KONTROLA DODATNIA: zdejmij mapowanie `KUKING_WAIT_FOR_CI` z joba apply.

    Plan i apply mają je po razie; mutacja zdejmuje drugie (apply), czyli
    dokładnie ścieżkę, która po scaleniu wyłączała „Wait for CI” (#1390).
    """
    if source.count(IAC_BRAMKA_ENV) != 2:
        raise RuntimeError("Kontrola nie znalazła dokładnie dwóch mapowań bramki CI.")
    poczatek = source.rindex(IAC_BRAMKA_ENV)
    return source[:poczatek] + source[poczatek + len(IAC_BRAMKA_ENV):]


def akcje_poza_filtrem_widoku(source):
    """KONTROLA DODATNIA: wyjmij `.github/actions/` z filtra warstwy widoku.

    Zmiana lokalnej akcji znowu daje `widok=false`, więc joby przeglądarkowe,
    które jej używają, byłyby pominięte. Strażnik bramki ma zapalić.
    """
    return replace_once(
        source,
        r"|\.github/(workflows/ci\.yml|actions/)|scripts/ci/)'",
        r"|\.github/workflows/ci\.yml|scripts/ci/)'",
    )


# Polskie litery w `unicode-range` Inter (#1000). Strażnik parsuje zakresy
# z `fonts.css`; mutacja wycina „Ą ą" (U+0104–0105) z podzbioru „europa"; druga wycina je
# z listy glifów faktycznie obecnych w wygenerowanym pliku (podzbior.json).
FONTY_CSS = "resources/css/fonts.css"
FONTY_TEST = "PodzbiorFontuMaPolskieZnakiTest"
FONTY_RAPORT = "resources/fonts/podzbior.json"
# Kontrakt bezpiecznego obszaru (#987, D-260): meta viewport z `cover`
# i boki dolnej belki oraz dół podpowiedzi wyglądu przez tokeny `--safe-*`.
BEZPIECZNY_OBSZAR_TEST = "BezpiecznyObszarMaJedenKontraktTest"
MARKA_RAMA_CSS = "resources/css/marka-rama.css"
SZYBKI_WYGLAD_CSS = "resources/css/szybki-wyglad.css"
# Publiczny domyślny zeszyt a przyszłe szybkie zapisy (#1400).
EDYCJA_ZESZYTU = "resources/views/pages/collections/edit.blade.php"
DOMYSLNY_ZESZYT_TEST = "PublicznyDomyslnyZeszytJawnyPrzyZapisieTest"


def dockerfile_poza_wzorcem_obrazu(source):
    """KONTROLA DODATNIA: `Dockerfile` wypada ze wzorca `obraz`.

    Build obrazu byłby pomijany na PR-ze zmieniającym sam Dockerfile, a ten
    plik strażnik czyta z `ci.yml` jako wejście joba — ma zapalić.
    """
    return replace_once(source, "ciezki obraz '^(Dockerfile$|", "ciezki obraz '^(")


def grupa_wyscigow_poza_wzorcem(source):
    """KONTROLA DODATNIA: `tests/Dwa/` wypada ze wzorca `wyscigi`.

    Pliki grupy strażnik zbiera z dysku (atrybut `#[Group(...)]` grupy
    wołanej przez `--group=` w skrypcie joba) — ma zapalić.
    """
    return replace_once(source, "tests/(Dwa/|Support/|", "tests/(Support/|")


def zawezanie_takze_poza_pr(source):
    """KONTROLA DODATNIA: ciężkie joby zawężane także na `main`.

    Bez warunku na zdarzenie push na `main` pomijałby build obrazu, przyrząd
    #605 i wyścigi przy zmianie obok ich obszaru — wbrew decyzji właściciela.
    """
    return replace_once(
        source,
        'if [ "${ZDARZENIE:-}" != "pull_request" ] || grep -Eq "$2" <<< "${ZMIENIONE}"; then',
        'if grep -Eq "$2" <<< "${ZMIENIONE}"; then',
    )


def wzorzec_przyrzadu_lapie_wszystko(source):
    """KONTROLA UJEMNA ZAWĘŻENIA: wzorzec `obciazenie` pasuje do każdej ścieżki.

    Kontrole „job rusza" przeszłyby wtedy śpiewająco, a oszczędności nie ma.
    Strażnik zmiany obok ma zapalić.
    """
    return replace_once(source, "ciezki obciazenie '^(scripts/", "ciezki obciazenie '^(|scripts/")


def podzial_gubi_plik(source):
    """KONTROLA DODATNIA: odkrywanie plików testów gubi pierwszy plik listy."""
    return replace_once(
        source,
        "    $pliki = array_values(array_unique(array_merge(...$pliki)));",
        "    $pliki = array_slice(array_values(array_unique(array_merge(...$pliki))), 1);",
    )


def macierz_krotsza_niz_podzial(source):
    """KONTROLA DODATNIA: macierz uruchamia trzy części, skrypt dzieli na cztery."""
    return replace_once(source, "czesc: [1, 2, 3, 4]\n", "czesc: [1, 2, 3]\n")


def widok_zawezany_poza_pr(source):
    """KONTROLA DODATNIA: filtr widoku zawęża także na `main`."""
    return replace_once(
        source,
        """if [ "${ZDARZENIE:-}" != "pull_request" ] || grep -qE '""",
        """if grep -qE '""",
    )


def plan_iac_bez_bramki_produkcji(source):
    """KONTROLA DODATNIA: zdejmij `environment: production` z joba `plan`.

    Job dalej wykonuje `.railway/railway.ts` z gałęzi PR-a, z tokenem
    RAILWAY_TOKEN_PRODUCTION, ale bez zgody recenzenta — dokładnie luka
    z audytu B10-01.
    `job_plan_wymaga_srodowiska_production_przed_wykonaniem_kodu_z_pr`
    ma zapalić.
    """
    return replace_once(
        source,
        PLAN_IAC_ENVIRONMENT,
        "    # przejrzał diff `.railway/**`.\n",
    )
def railway_cli_bez_przypietej_wersji(source):
    """KONTROLA DODATNIA: zdejmij weryfikację sumy kontrolnej Railway CLI.

    Od #1865 binarka idzie wprost z GitHub Releases (`curl` + `sha256sum -c`),
    nie przez `npm install -g @railway/cli@X.Y.Z` — pakiet npm pobierał ją
    bez sprawdzenia sumy. Bez linii `sha256sum -c` pobrany plik trafia na
    runner obok RAILWAY_TOKEN niezweryfikowany; `RailwayCliPrzypietaWersjaTest`
    ma zapalić (audyt B10-02).
    """
    return replace_once(
        source,
        '          echo "${RAILWAY_CLI_SHA256}  /tmp/railway-cli.tar.gz" | sha256sum -c -\n',
        "",
    )


# Łańcuch dostaw CI i bramka wdrożenia (#2309, #2310, #2263, #2233, #2230, #2248).
# Strażnicy czytają workflowy przez yaml.safe_load; mutacje przywracają stan
# sprzed poprawki w jednym miejscu.
LANCUCH_CI_TEST = "InstalacjeCiSaPrzypieteTest"
KLIENT_PG18 = "scripts/ci/klient-postgresql-18.sh"
KLIENT_PG18_KROK = "        run: bash scripts/ci/klient-postgresql-18.sh\n"
KLIENT_PG18_DAWNY_KROK = (
    "        run: |\n"
    "          sudo curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \\\n"
    "            https://www.postgresql.org/media/keys/ACCC4CF8.asc\n"
    "          sudo apt-get update -qq\n"
    "          sudo apt-get install -y -qq postgresql-client-18\n"
)
CENY_WORKFLOW = ".github/workflows/ceny-warzyw-auto.yml"
CENY_WYMAGANIA = "scripts/ceny-warzyw-requirements.txt"
PG_CI_DIGEST = "postgres:18-alpine@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873"
PG_CI_DOCKERFILE = "docker/ci-postgres/Dockerfile"
BRAMKA_RAILWAY_WORKFLOW = ".github/workflows/railway-ci-gated-deploy.yml"
BRAMKA_RAILWAY_SKRYPT = "scripts/railway-ci-gated-deploy.py"
BRAMKA_CHECKOUT_TEST = "bramka_checkoutuje_dokladnie_sha_zielonego_ci"
BRAMKA_WARUNKI_TEST = "bramka_reaguje_tylko_na_zakonczone_ci_i_tylko_gdy_wlasciciel_ja_wlaczyl"
BRAMKA_JOB_ZBIORCZY_TEST = "skrypt_bramki_wymaga_dokladnie_tego_joba_zbiorczego_ktory_ma_ci"
BRAMKA_ALARM_TEST = "alarm_po_wdrozeniu_odpala_sie_dla_produkcji_i_ma_odczyt_przebiegow"
SENTRY_SHA_TEST = "WydanieSentryMaShaWdrozeniaTest"
STAN_WDROZENIA_TEST = "DeployAlarmujeGdyWdrozenieKonczySieBezSukcesuTest"


def pierwsze_z_wielu(source, old, new, ile):
    """Jak replace_once, ale dla fragmentu, który stoi w pliku `ile` razy."""
    if source.count(old) != ile:
        raise RuntimeError(f"Kontrola oczekiwała {ile} wystąpień fragmentu, jest {source.count(old)}.")
    return source.replace(old, new, 1)


def spizarnia_bez_potwierdzenia(source):
    start = source.index('            <details class="confirm group mt-3 w-full min-w-0" data-potwierdzenie-spizarni>')
    end = source.index('            </details>', start) + len('            </details>')
    return source[:start] + '''            <form method="POST" action="{{ route('pantry.destroy', $produkt) }}">
                @csrf
                @method('DELETE')
                <button class="btn btn-secondary" type="submit">Usuń</button>
            </form>''' + source[end:]


def planer_bez_potwierdzenia(source):
    """Przywróć bezpośredni formularz DELETE sprzed #2468."""
    poczatek = '                                <details class="confirm planer-potwierdzenie">'
    koniec = '                                </details>'
    # Zamknięcie szukamy od początku potwierdzenia: na tym samym wcięciu stoją
    # też inne `<details>` pozycji (np. dopisek z #2549).
    if source.count(poczatek) != 1 or koniec not in source[source.find(poczatek):]:
        raise RuntimeError('Nie znaleziono dokładnie jednego potwierdzenia Planera.')
    od = source.index(poczatek)
    do = source.index(koniec, od) + len(koniec)
    dawny = '''                                <form method="POST" action="{{ route('planer.destroy', $wpis) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-secondary" type="submit">Usuń z planu<span class="visually-hidden">: {{ $nazwa }}</span></button>
                                </form>'''

    return source[:od] + dawny + source[do:]


def skladniki_nie_rozpoznaja_nierozdzielajacej_spacji(source):
    old = "return preg_match('/\\A[ \\t\\n\\x{00A0}\\x{202F}]*\\z/u', $wiersz) === 1;"
    new = "return preg_match('/\\A[ \\t\\n]*\\z/u', $wiersz) === 1;"

    return replace_once(source, old, new)


def kroki_nie_rozpoznaja_nierozdzielajacej_spacji(source):
    old = next((line for line in source.splitlines() if '$bloki = preg_split(' in line), None)
    if old is None or old.count('\\\\x{00A0}\\\\x{202F}') != 2:
        raise RuntimeError('Nie znaleziono obu kotwic granicy kroków Unicode (#2621).')

    return replace_once(source, old, old.replace('\\\\x{00A0}\\\\x{202F}', ''))


checks = [
    ("Planer pomija błąd frazy w podsumowaniu (#2846)", "resources/views/pages/planer/show.blade.php",
     "test_blad_frazy_jest_przy_polu_i_w_podsumowaniu_z_zywym_odnosnikiem",
     lambda s: replace_once(s,
         "@if($bladWPlanach)\n                {{-- Błąd GET ma własny klucz i cel; nie zastępuje błędów innych formularzy z sesji. --}}",
         "@if(false)\n                {{-- Błąd GET ma własny klucz i cel; nie zastępuje błędów innych formularzy z sesji. --}}")),
    ("Odebranie współtworzenia obiecuje brak widoku zeszytu (#2822)",
     "app/Http/Controllers/CollectionSharingController.php",
     "test_koniec_wspoltworzenia_nie_obiecuje_utraty_publicznego_widoku",
     lambda s: replace_once(s, "nie może już zapisywać w tym zeszycie", "nie widzi już tego zeszytu")),
    ("Potwierdzenie odejścia obiecuje brak widoku zeszytu (#2822)",
     "resources/views/pages/collections/show.blade.php",
     "test_koniec_wspoltworzenia_nie_obiecuje_utraty_publicznego_widoku",
     lambda s: replace_once(s, "Nie będziesz już w nim zapisywać", "Stracisz do niego dostęp")),
    ("Błąd szukania w zeszytach nie trafia do podsumowania (#2850)", "resources/views/pages/collections/index.blade.php",
     "test_blad_frazy_jest_przy_polu_i_w_podsumowaniu_bez_utraty_filtrow",
     lambda s: replace_once(s,
         "@if($bladSzukania)\n                @include('components.error-summary', [",
         "@if(false)\n                @include('components.error-summary', [")),
    ("Wygasła prośba nadal blokuje poprawę uwagi (#2820)", "app/Domain/Recipes/Gotowanie/PolaKorekty.php",
     "test_prosba_blokuje_przed_terminem_a_w_chwili_wygasniecia_odblokowuje_formularz_i_zapis",
     lambda s: replace_once(s,
         "->where(static function (Builder $query): void {\n                $query->where('status', RecipeHint::STATUS_ACCEPTED)\n                    ->orWhere(static function (Builder $proposed): void {\n                        $proposed->where('status', RecipeHint::STATUS_PROPOSED)\n                            ->where('created_at', '>', RecipeHint::granicaWygasniecia());\n                    });\n            })",
         "->whereIn('status', [RecipeHint::STATUS_PROPOSED, RecipeHint::STATUS_ACCEPTED])")),
    ("Kolejka gubi zdjęcie bieżącego kroku (#2828)", "resources/views/pages/recipes/kolejka-gotowania.blade.php",
     "test_http_w_kolejce_pokazuje_tylko_zdjecie_biezacego_kroku_aktywnej_potrawy",
     lambda s: replace_once(s, "@if($krokModel->media)", "@if(false)")),
    ("Usunięta lista zakupów prowadzi do nieistniejącego formularza (#2806)", "app/Http/Controllers/ZakupyDoSpizarniController.php",
     "test_lista_usunieta_po_wstepnym_odczycie_wysyla_wprost_na_istniejacy_ekran",
     lambda s: replace_once(s,
         "if (isset($e->errors()['lista'])) {",
         "if (false) {")),
    ("Błąd spiżarni gubi nazwaną listę zakupów (#2806)", "app/Http/Controllers/ZakupyDoSpizarniController.php",
     "test_blad_w_nazwanej_liscie_odtwarza_wlasciwe_pola_wybor_i_odnosniki",
     lambda s: replace_once(s,
         "return redirect()->route('shopping.pantry.form', $wybrana !== null ? ['lista' => $wybrana->getKey()] : [])",
         "return redirect()->route('shopping.pantry.form')")),
    ("Błąd Bez składnika znika z podsumowania (#2842)", "resources/views/pages/search.blade.php",
     "test_blad_skladnika_jest_rowniez_w_podsumowaniu_z_linkiem_do_pola",
     lambda s: replace_once(s,
         "@include('components.error-summary', ['errors' => $formErrors, 'fieldIds' => ['bez_skladnika' => 'f-bez-skladnika']])",
         "@include('components.error-summary', ['errors' => $searchErrors, 'fieldIds' => ['bez_skladnika' => 'f-bez-skladnika']])")),
    ("Podsumowanie Bez składnika prowadzi do nieistniejącego pola (#2842)", "resources/views/pages/search.blade.php",
     "test_blad_skladnika_jest_rowniez_w_podsumowaniu_z_linkiem_do_pola",
     lambda s: replace_once(s,
         "'fieldIds' => ['bez_skladnika' => 'f-bez-skladnika']",
         "'fieldIds' => []")),
    ("Limit przepisu gubi wybraną listę zakupów (#2818)", "app/Http/Controllers/ListaZakupowController.php",
     "test_odmowa_limitu_przepisu_wraca_na_wybrana_liste_z_droga_wyczyszczenia",
     lambda s: replace_once(s,
         "return redirect()->route('shopping.index', $cel !== null ? ['lista' => $cel->getKey()] : [])->with(Komunikat::blad(",
         "return redirect()->route('shopping.index')->with(Komunikat::blad(")),
    ("Anonimowy licznik omija sprzeciw wobec statystyk (#2837)", "app/Domain/Analytics/ZapiszSygnal.php",
     "test_sprzeciw_zatrzymuje_siedem_typow_z_osmiu_sciezek_bez_zatrzymania_funkcji",
     lambda s: replace_once(s, "$this->zapisz($user, $signalName, $properties, false);", "$this->zapisz(null, $signalName, $properties, false);")),
    ("Dokumentacja bazy wraca do martwej lokalnej kotwicy",
     "docs/baza/zeszyty-udostepnienia-i-odzyskiwanie.md",
     "wzgledne_linki_w_plikach_obszarow_trafiaja_w_istniejacy_cel",
     lambda s: replace_once(s,
         "ugotowalem-komentarze-zeszyty.md#collection_items--przepisy-oraz-wpisy",
         "#collection_items--przepisy-oraz-wpisy")),
    ("Kopia odzyskania gubi ręczną kolejność (#2816)", "app/Domain/Collections/Odzyskiwanie/UsunZeszyt.php",
     "test_reczne_ulozenie_przezywa_usuniecie_i_odzyskanie_calego_zeszytu",
     lambda s: replace_once(s, "'position' => $p->position === null ? null : (int) $p->position,", "'position' => null,")),
    ("Odtworzenie zeszytu gubi ręczną kolejność (#2816)", "app/Domain/Collections/Odzyskiwanie/OdzyskajUsunietyZeszyt.php",
     "test_reczne_ulozenie_przezywa_usuniecie_i_odzyskanie_calego_zeszytu",
     lambda s: replace_once(s, "'position' => $kolumna === 'recipe_id' ? ($pozycja['position'] ?? null) : null,", "'position' => null,")),
    ("Stary przycisk odzyskuje nową kopię zeszytu (#2867)", "app/Domain/Collections/Odzyskiwanie/OdzyskajUsunietyZeszyt.php",
     "test_stary_przycisk_nie_odzyskuje_nowej_kopii_tego_samego_zeszytu",
     lambda s: replace_once(s, "$kopia !== null && $kopia->getKey() !== $kopiaId", "false")),
    ("Pełny limit chowa nazwę nowej listy (#2821)", "resources/views/pages/zakupy/index.blade.php",
     "test_limit_z_drugiej_karty_zostawia_nazwe_pole_i_zywy_odnosnik_bledu",
     lambda s: replace_once(s, "@if($mozeDodacListe || $blad_nazwy)", "@if($mozeDodacListe)")),
    ("Ponowienie zaproszenia ujawnia nazwę po odebraniu dostępu (#2825)", "app/Domain/Collections/Wspoldzielenie/OdpowiedzNaZaproszenie.php",
     "test_ponowienie_przyjetego_zaproszenia_bez_aktualnego_dostepu_nie_zdradza_nowej_nazwy",
     lambda s: replace_once(s, "if ($zeszyt->owner_id !== $swiezyWlasciciel->getKey()\n                    || ! $zeszyt->maCzlonka($swiezaOsoba)\n                    || ! (new CollectionPolicy)->view($swiezaOsoba, $zeszyt)) {", "if (false) {")),
    ("Zawieszona autorka nie może cofnąć udostępnienia (#2791)", "app/Http/Middleware/EnsureAccountIsActive.php",
     "test_zawieszona_autorka_przez_formularz_cofa_tylko_wlasne_udostepnienie",
     lambda s: replace_once(s, "        'recipes.shares.destroy',\n", "")),
    ("Moje rozmowy przepuszczają niemożliwą datę kursora (#2803)", "app/Domain/Comments/MojeRozmowy.php",
     "MojeRozmowyTest::test_parser_odrzuca_semantycznie_nieprawidlowy_czas_kursora",
     lambda s: replace_once(s, "if (! KursorListy::czasPasuje($m[1])) {", "if (false) {")),
    ("Ponowienie przeniesienia ujawnia niedostępny tytuł (#2809)", "app/Domain/Collections/Actions/PrzeniesPozycjeMiedzyZeszytami.php",
     "test_ponowienie_nie_ujawnia_nowego_niedostepnego_tytulu",
     lambda s: replace_once(s,
         "$tytul = $widoczna ? ($tresc instanceof Recipe ? $tresc->title : 'Wpis') : null;",
         "$tytul = $tresc instanceof Recipe ? $tresc->title : 'Wpis';")),
    # Właściciel zatwierdził 3.10.2026 izolowaną kontrolę #2784.
    # Mechanizm zapisuje mutant tylko na czas testu i przywraca źródło.
    ("Moje rozmowy pomijają Policy wpisu (#2432)", "app/Domain/Comments/MojeRozmowy.php",
     "test_tresc_niedostepna_dla_osoby_znika_bez_tytulu_fragmentu_i_adresu",
     lambda s: replace_once(s,
         "if ($wpis !== null && $bramka->allows('view', $wpis)) {",
         "if ($wpis !== null) {")),
    ("Import odrzuca ułamek czasu bez ostrzeżenia (#2546)", "app/Domain/Import/Url/ParserJsonLdPrzepisu.php",
     "OstrzezeniaParseraWImporcieTest::test_odrzucony_ulamek_minuty_jest_wskazany_przy_polach_json_ld_i_mikrodanych",
     lambda s: replace_once(s, "ostrzezeniaParsera: $ostrzezenia,", "ostrzezeniaParsera: [],")),
    ("Import nie zapisuje ostrzeżeń parsera przy szkicu (#2548)", "app/Domain/Import/PominieteWImporcie.php",
     "OstrzezeniaParseraWImporcieTest::test_mieszany_import_pamieta_liczbe_pominietych_skladnikow_i_czas_po_ponownym_otwarciu",
     lambda s: replace_once(s, "['ostrzezenia_parsera' => $this->ostrzezeniaParsera]", "['ostrzezenia_parsera' => []]")),
    ("Listy zakupów mieszają pozycje (#2528)", "app/Domain/Zakupy/ListaZakupow.php",
     "test_dotychczasowe_pozycje_sa_na_liscie_domyslnej_a_nazwana_ich_nie_miesza",
     lambda s: replace_once(s, ": $zapytanie->where('list_id', $lista->getKey());", ": $zapytanie;").replace("? $zapytanie->whereNull('list_id')", "? $zapytanie")),
    ("Cudza lista zakupów otwiera ekran i przyjmuje zapis (#2528)", "app/Domain/Zakupy/ListaZakupow.php",
     "test_cudza_lista_nie_otwiera_sie_i_nie_przyjmuje_zapisow",
     lambda s: replace_once(s, "if (! (new ShoppingListPolicy)->view($user, $lista)) {", "if (false) {")),
    ("Usunięcie listy ignoruje zmienioną liczbę pozycji (#2528)", "app/Domain/Zakupy/ListaZakupow.php",
     "test_usuniecie_listy_z_pozycjami_wymaga_potwierdzenia_z_liczba_i_nie_rusza_innych_list",
     lambda s: replace_once(s, "if ($ile > 0 && $widzianaLiczba !== $ile) {", "if (false) {")),
    ("Dołączenie zdjęcia: podmiana UUID kucharza (#2500)", "app/Policies/CookedEventPolicy.php",
     "test_obcy_autor_przepisu_i_moderator_nie_dolacza_zdjec",
     lambda s: replace_once(s, "if ($user->getKey() !== $event->user_id || ! $user->isActive()) {", "if (! $user->isActive()) {")),
    ("Ponowiony multipart zapisuje osierocone zdjęcie (#2811)", "app/Http/Controllers/CookedEventController.php",
     "test_ponowiony_multipart_jednego_wyslania_nie_tworzy_drugiego_zdjecia",
     lambda s: replace_once(s, "if (in_array($kluczWyslania, $swiezy->photo_submission_keys ?? [], true)) {", "if (false) {")),
    ("Historia zdjęć urywa się po szóstym wysłaniu (#2811)", "database/migrations/2026_10_08_080000_add_photo_submission_keys_to_cooked_events.php",
     "test_siodme_wyslanie_po_usunieciu_zdjecia_przechodzi_a_stary_klucz_nadal_nie_dodaje_media",
     lambda s: replace_once(s, "CHECK (jsonb_typeof(photo_submission_keys) = 'array')", "CHECK (jsonb_typeof(photo_submission_keys) = 'array' AND jsonb_array_length(photo_submission_keys) <= 6)")),
    ("Wymazanie zostawia prywatne klucze zdjęć (#2811)", "app/Domain/Users/Actions/EraseAccountData.php",
     "test_wymazanie_konta_usuwa_prywatna_historie_kluczy_lecz_zostawia_wykonanie",
     lambda s: replace_once(s, "            $fresh->cookedEvents()->whereRaw(\"photo_submission_keys <> '[]'::jsonb\")\n                ->update(['photo_submission_keys' => []]);\n", "")),
    ("Dołączenie zdjęcia liczy limit bez przypiętych (#2500)", "app/Domain/Recipes/Actions/DolaczZdjeciaDoWykonania.php",
     "test_limit_liczy_zdjecia_juz_przypiete_i_nowe_razem",
     lambda s: replace_once(s, "if (count($przypiete) + count($nowe) > LimityZdjec::maksZdjecNaWysylke()) {", "if (count($nowe) > LimityZdjec::maksZdjecNaWysylke()) {")),
    ("Dołączenie zdjęcia ignoruje świeży stan pod blokadą (#2500)", "app/Domain/Recipes/Actions/DolaczZdjeciaDoWykonania.php",
     "test_sankcja_albo_utrata_przepisu_miedzy_otwarciem_a_zapisem_blokuje_zapis_pod_blokada",
     lambda s: replace_once(s, "if (! (new CookedEventPolicy)->addPhotos($swiezyKucharz, $swiezeWykonanie)) {", "if (false) {")),
    ("Dołączenie zdjęcia przejmuje cudze albo cudzo-przypięte (#2500)", "app/Domain/Recipes/Actions/DolaczZdjeciaDoWykonania.php",
     "test_cudze_zabezpieczone_i_przypiete_gdzie_indziej_zdjecia_nie_zostaja_przejete",
     lambda s: replace_once(s, "&& ! in_array($id, $gdzieIndziej, true),", ",")),
    ("Niezmieniona kopia szkicu wychodzi do ludzi (#2507)", "app/Domain/Recipes/Actions/PublishRecipe.php",
     "test_publikacja_niezmienionej_kopii_przez_formularz_jest_odrzucona",
     lambda s: replace_once(s, "                MojaWersja::pilnujRoznicyKopii($recipe);\n", "")),
    ("Importowany szkic traci bramkę odczytu w kopii (#2800)", "app/Domain/Recipes/Actions/ZrobKopieSzkicu.php",
     "test_szkic_z_url_i_pdf_nie_dostaje_kopii_przez_domene_ani_http",
     lambda s: replace_once(s, "if (self::jestPowiazanyZImportem($swiezeZrodlo)) {", "if (false && self::jestPowiazanyZImportem($swiezeZrodlo)) {")),
    ("Kopia szkicu gubi podpis Mojej wersji (#2507)", "app/Domain/Recipes/Actions/ZrobKopieSzkicu.php",
     "test_kopia_adaptacji_zachowuje_podpis_oryginalu_takze_gdy_oryginal_zniknal",
     lambda s: replace_once(s, "'forked_from_id' => $swiezeZrodlo->forked_from_id,", "'forked_from_id' => null,")),
    ("Kopia szkicu przejmuje zdjęcia kroków (#2507)", "app/Domain/Recipes/Actions/ZrobKopieSzkicu.php",
     "test_kopia_to_niezalezny_prywatny_szkic_ze_skopiowana_trescia_i_bez_mediow",
     lambda s: replace_once(s, "'media_id' => null,\n                    'timer_seconds'", "'media_id' => $krok->media_id,\n                    'timer_seconds'")),
    ("Kopia szkicu ocenia prawo na starym stanie (#2507)", "app/Domain/Recipes/Actions/ZrobKopieSzkicu.php",
     "test_stan_i_prawo_sa_sprawdzane_od_nowa_pod_blokada",
     lambda s: replace_once(s, "Gate::forUser($swiezyUser)->authorize('copyDraft', $swiezeZrodlo);", "")),
    ("Kopię cudzego szkicu zrobi każdy (#2507)", "app/Policies/RecipePolicy.php",
     "test_tylko_wlasciciel_aktywny_wlasnego_szkicu",
     lambda s: replace_once(s, "public function copyDraft(User $user, Recipe $recipe): bool\n    {\n        return $user->getKey() === $recipe->author_id\n            && $user->isActive()", "public function copyDraft(User $user, Recipe $recipe): bool\n    {\n        return $user->isActive()")),
    ("Dyktowanie odbiera mikrofon zalogowanemu (#2377 etap 2)", "app/Http/Middleware/ApplySecurityHeaders.php", "test_ekran_dluzszego_pola_odblokowuje_mikrofon_tylko_zalogowanemu",
     lambda s: replace_once(s, "return $request->user() !== null", "return false")),
    ("Dyktowanie daje mikrofon gościowi (#2377 etap 2)", "app/Http/Middleware/ApplySecurityHeaders.php", "test_ekran_dluzszego_pola_odblokowuje_mikrofon_tylko_zalogowanemu",
     lambda s: replace_once(s, "return $request->user() !== null", "return true")),
    ("Dyktowanie daje mikrofon błędowi i JSON (#2377 etap 2)", "app/Http/Middleware/ApplySecurityHeaders.php", "test_nazwa_trasy_nie_odblokowuje_mikrofonu_na_bledzie_przekierowaniu_json_ani_post",
     lambda s: replace_once(s, "&& $request->isMethod('GET')\n            && $response->isSuccessful()\n            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')", "&& true")),
    ("Dyktowanie odblokowuje wszystkie trasy (#2377 etap 2)", "app/Http/Middleware/ApplySecurityHeaders.php", "test_zalogowanie_nie_odblokowuje_mikrofonu_na_innych_ekranach",
     lambda s: replace_once(s, "&& $request->routeIs(...self::TRASY_DYKTOWANIA)", "&& true")),
    ("Wspólna sesja traci zamiennik autora (#2485)", "resources/views/pages/wspolne-gotowanie/show.blade.php",
     "test_zamienniki_i_zdjecia_sa_przy_wlasciwych_elementach_dla_obu_rol",
     lambda s: replace_once(s, '@if($skladnik->substitutes)<span class="skladnik-zamiennik">Zamiast tego: {{ $skladnik->substitutes }}</span>@endif', '')),
    ("Wspólna sesja traci zdjęcie kroku (#2486)", "resources/views/pages/wspolne-gotowanie/show.blade.php",
     "test_zamienniki_i_zdjecia_sa_przy_wlasciwych_elementach_dla_obu_rol",
     lambda s: replace_once(s, '@if($krok->media)', '@if(false)')),
    # #2449: osobno wykrycie jawnej zmiany i odmowa zgadywania starego wyboru.
    ("Historia ignoruje wybór Bez ilości (#2449)", "app/Domain/Recipes/Historia/PorownanieWersji.php",
     "test_jawna_zmiana_bez_ilosci_w_obie_strony_jest_widoczna_bez_zmiany_tekstu_autora",
     lambda s: replace_once(s,
         "if ($staryWybor !== null && $nowyWybor !== null && $staryWybor !== $nowyWybor) {",
         "if (false) {")),
    ("Historia zgaduje wybór w starej migawce (#2449)", "app/Domain/Recipes/Historia/PorownanieWersji.php",
     "test_stara_migawka_bez_wyboru_nie_staje_sie_nie_a_pozostale_zmiany_sa_widoczne",
     lambda s: replace_once(s,
         "} elseif (($staryWybor === null) !== ($nowyWybor === null)) {",
         "} elseif (false) {")),
    # #2650: rollback `recipe_shares` ma ODMÓWIĆ przy istniejących udostępnieniach (D-088).
    ("Rollback udostępnień przepisów kasuje bez pytania (#2650)", "database/migrations/2026_10_03_120000_create_recipe_shares_table.php",
     "test_rollback_odmawia_przy_udostepnieniach_i_przechodzi_na_pustej_tabeli",
     lambda s: replace_once(s, "            $this->upewnijSieZeWolnoKasowac();\n", "")),
    ("Niedostępny przepis usuwa drogę rezygnacji (#2859)", "app/Http/Controllers/SharedRecipeController.php",
     "test_odbiorca_rezygnuje_z_niedostepnego_przepisu_z_listy_bez_odczytu_tresci",
     lambda s: replace_once(s, "            ->values();", "            ->filter(fn (array $pozycja): bool => $pozycja['czytelny'])\n            ->values();")),
    ("Instrukcja CSAM myli awatar i osobne zdjęcie z nieobsługiwanymi (#2708)", "docs/legal/MODERATION_PLAYBOOK.md",
     "test_instrukcja_odroznia_obslugiwane_media_od_nieobslugiwanych_tresci_i_pilota",
     lambda s: replace_once(s, "osobne zdjęcie i awatar", "awatar i samo zdjęcie są poza zakresem")),
    ("Tabela CSAM wraca do starej instrukcji zgłoszenia (#2708)", "docs/legal/MODERATION_PLAYBOOK.md",
     "test_tabela_nie_opisuje_juz_starej_instrukcji_zglaszania",
     lambda s: replace_once(s,
         "każe zawiadomić bezpośrednio Policję albo prokuraturę bez zbędnej zwłoki",
         "nadal podaje stary porządek")),
    ("Udostępnienie przechodzi na nowego właściciela nazwy (#2790)", "app/Http/Controllers/RecipeShareController.php",
     "StalyOdbiorcaUdostepnieniaPrzepisuTest::test_zmieniona_nazwa_i_nowy_wlasciciel_nazwy_nie_dostaja_udostepnienia",
     lambda s: replace_once(s,
         "$akcja->poPotwierdzeniu($request->user(), $recipe, $danePotwierdzenia['odbiorca'], $danePotwierdzenia['nazwa'])",
         "$akcja->poNazwie($request->user(), $recipe, $danePotwierdzenia['nazwa'])")),
    # #2291: regresja domyślnej konfiguracji ma zapalić odczyt `SHOW jit` na
    # rzeczywistym nowym połączeniu PostgreSQL, nie tylko test tekstu configu.
    ("Domyslny JIT wraca na polaczeniu PostgreSQL (#2291)", "config/database.php", "PolaczenieBazyMaWylaczonyJitTest::swieze_polaczenie_aplikacji_ma_jit_off",
     lambda s: replace_once(s, "'server_options' => ['jit' => env('DB_JIT') ?: 'off'],", "'server_options' => ['jit' => env('DB_JIT') ?: 'on'],")),
    ("Pusta linia Unicode tworzy składnik (#2621)", "app/Domain/Recipes/TekstNaWiersze.php",
     "test_niewidoczne_spacje_na_pustych_liniach_nie_tworza_skladnika_i_rozdzielaja_kroki",
     skladniki_nie_rozpoznaja_nierozdzielajacej_spacji),
    ("Pusta linia Unicode skleja kroki po POST (#2621)", "app/Domain/Recipes/TekstNaWiersze.php",
     "test_wklejony_tekst_z_nierozdzielajaca_spacja_na_pustej_linii_zapisuje_wlasciwe_skladniki_i_kroki",
     kroki_nie_rozpoznaja_nierozdzielajacej_spacji),
    ("Co ugotuję: ostatnia strona wraca do siebie (#2599)", "resources/views/pages/pantry/co-ugotuje.blade.php",
     "CoUgotujeGranicaPaginacjiTest::test_ostatnia_dostepna_strona_nie_odsyla_do_siebie_w_obu_trybach",
     lambda s: replace_once(s, "@if($jest_wiecej && ! $granicaPrzegladania)", "@if($jest_wiecej)")),
    ("Co ugotuję: pusty termin myli się z pustym zeszytem (#2591)", "resources/views/pages/pantry/co-ugotuje.blade.php",
     "CoUgotujeZZeszytowTest::test_pusty_tryb_krotkiego_terminu_nie_twierdzi_ze_w_zeszytach_nie_ma_przepisu_z_tymi_produktami",
     lambda s: replace_once(s, "@elseif($zZeszytow && $najpierwTermin)", "@elseif(false)")),
    ("HTTP import paczki nie pilnuje budżetu struktury (#2611)", "app/Domain/Users/Import/PodgladPaczkiEksportu.php",
     "test_paczka_tuz_ponad_budzetem_struktury_jest_odrzucona_przez_http_bez_poczekalni",
     lambda s: replace_once(s, 'if ($kontenery > self::MAX_KONTENEROW_JSON) {', 'if ($kontenery > self::MAX_KONTENEROW_JSON + 1) {')),
    ("Import partii ponownie zaznacza wykluczoną pozycję (#2843)", "app/Http/Controllers/Settings/WczytanieDanychController.php",
     "test_kolejna_partia_zachowuje_tylko_pozostaly_wybor_a_nie_wznawia_wykluczonych",
     lambda s: replace_once(s,
         '$zapisanyWybor = $request->session()->get($this->kluczWyboru($request, $paczka));',
         '$zapisanyWybor = null;')),
    ("Wydruk zeszytu pomija podpis oryginału wersji (#2852)", "resources/views/pages/collections/do-druku.blade.php",
     "WydrukZeszytuTest::test_wydruk_calosci_i_wyboru_podpisuje_widoczny_oryginal_niezaleznie_od_zdjec_i_notatek",
     lambda s: replace_once(s,
         '<x-na-podstawie-przepisu :recipe="$przepis" :oryginal="$oryginalyDlaPodpisu->get($przepis->forked_from_id)" :oryginal-ustalony="true" />',
         '')),
    # #2611: wyłączenie preflightu musi oblać izolowane procesy PHP 256M
    # konkretną odmową (w starym kodzie kończyły się fatalem), a nie bazę CI.
    ("Paczka JSON bez budżetu struktury (#2611)", "app/Domain/Users/Import/PodgladPaczkiEksportu.php",
     "BudzetStrukturyPaczkiTest::test_nadmierna_struktura_jest_odrzucona_przed_fatalem_256_mb",
     lambda s: replace_once(s, "        $this->sprawdzBudzetStruktury($json);\n", "")),
    ("Porcje mnożą procent tłuszczu (#2629)", "app/Domain/Recipes/Porcje/PrzeliczSkladnik.php",
     "PrzeliczSkladnikTest::test_procent_opisuje_produkt_a_pozniejsza_masa_jest_iloscia",
     lambda s: replace_once(s,
         r"'/^(?<przed>\s*'.self::OKOLO.')(?<calosc>'.$ilosc.$nieProcent.$poIlosci.$jednostka.')/iu',",
         r"'/^(?<przed>\s*'.self::OKOLO.')(?<calosc>'.$ilosc.$poIlosci.$jednostka.')/iu',")),
    ("Mikrodane ListItem zastępują instrukcję etykietą (#2638)", "app/Domain/Import/Url/ParserMikrodanychPrzepisu.php",
     "ImportMikrodaneTest::test_listitem_item_zachowuje_instrukcje_zamiast_nazwy_opakowania",
     lambda s: replace_once(s, "if ($this->maTyp($el, ['ListItem'])) {", "if (false) {")),
    ("Mikrodane pusty ListItem zgaduje instrukcję (#2638)", "app/Domain/Import/Url/ParserMikrodanychPrzepisu.php",
     "ImportMikrodaneTest::test_listitem_bez_obslugiwanego_lokalnego_item_nie_zgaduje_instrukcji",
     lambda s: replace_once(s, "if ($this->maTyp($el, ['ListItem'])) {", "if (false) {")),
    ("Mikrodane ListItem zapisują etykietę do szkicu (#2638)", "app/Domain/Import/Url/ParserMikrodanychPrzepisu.php",
     "ImportPrzepisuZAdresuIPdfTest::test_listitem_mikrodanych_zapisuje_item_w_prywatnym_szkicu_bez_modelu",
     lambda s: replace_once(s, "if ($this->maTyp($el, ['ListItem'])) {", "if (false) {")),
    ("Import URL: regex zostawia potomka komentarzy (#2640)",
     "app/Domain/Import/Url/TekstStrony.php",
     "ImportParseryTest::test_zagniezdzone_komentarze_znikaja_z_calym_poddrzewem_a_przepis_zostaje",
     lambda s: replace_once(s, "$html = self::bezKomentarzyCzytelnikow($html);",
         r"""$html = (string) preg_replace('#<(section|div|ol|ul)\b[^>]*\b(id|class)\s*=\s*["\'][^"\']*\bcomments?\b[^"\']*["\'][^>]*>.*?</\1\s*>#is', "\n", $html);""")),
    ("Import URL HTTP: regex wysyła komentarz do fragmentów (#2640)",
     "app/Domain/Import/Url/TekstStrony.php",
     "ImportPrzepisuZAdresuIPdfTest::test_import_http_nie_przekazuje_zagniezdzonych_komentarzy_do_fragmentow_ani_szkicu",
     lambda s: replace_once(s, "$html = self::bezKomentarzyCzytelnikow($html);",
         r"""$html = (string) preg_replace('#<(section|div|ol|ul)\b[^>]*\b(id|class)\s*=\s*["\'][^"\']*\bcomments?\b[^"\']*["\'][^>]*>.*?</\1\s*>#is', "\n", $html);""")),
    ("OCR: miesięczny limit obiecuje jutro (#2648)", "app/Domain/Import/KomunikatImportu.php",
     "test_zapisane_zlecenie_pokazuje_obecny_miesieczny_limit_zamiast_obietnicy_jutra",
     lambda s: replace_once(s,
                            "Po rozpoczęciu następnego miesiąca możesz spróbować ponownie, jeśli odczytywanie będzie dostępne.",
                            "Jutro rano będzie można dalej.")),
    ("PDF: ilość dziesiętna staje się numerem listy (#2614)",
     "app/Domain/Import/ParserTekstuPrzepisu.php",
     "ImportParseryTest::test_tekst_pdf_zachowuje_dziesietne_ilosci_a_usuwa_tylko_jednoznaczna_numeracje",
     lambda s: replace_once(s,
                            r"(?:[•*·▪●◦]\s*|[-–—](?:\h+|(?!\d))|\d{1,2}\)\s*|\d{1,2}\.\h+)",
                            r"([-–—•*·▪●◦]|\d{1,2}[.)])\s*")),
    ("Identyfikator JSON-LD udaje krok (#2582)", "app/Domain/Import/Url/ParserJsonLdPrzepisu.php",
     "test_json_ld_id_kroku_nie_staje_sie_instrukcja_ani_nie_pobiera_celu",
     lambda s: replace_once(s,
         "zamieniałoby identyfikator w krok.\n        if (! array_is_list($wartosc)) {",
         "zamieniałoby identyfikator w krok.\n        if (isset($wartosc['@type']) || isset($wartosc['itemListElement']) || isset($wartosc['text'])) {")),
    ("ListItem.item nie trafia do szkicu importu (#2570)", "app/Domain/Import/Url/ParserJsonLdPrzepisu.php",
     "test_import_json_ld_listitem_item_zapisuje_kroki_w_prywatnym_szkicu_bez_modelu",
     lambda s: replace_once(s,
         "if (is_array($item) && ($this->jestTypem($item, 'HowToStep') || $this->jestTypem($item, 'HowToSection'))) {",
         "if (false && is_array($item) && ($this->jestTypem($item, 'HowToStep') || $this->jestTypem($item, 'HowToSection'))) {")),
    ("Literalne porównanie znika z instrukcji JSON-LD (#2580)", "app/Domain/Import/Url/ParserJsonLdPrzepisu.php",
     "test_porownanie_liczbowe_zostaje_w_calym_kroku_tekstowym_i_howtostep",
     lambda s: replace_once(s, "$tekst = (string) preg_replace('/<(?=\\d)/', $znacznik.'L', $tekst);",
                            "$tekst = (string) preg_replace('/<(?=\\d)/', '<', $tekst);")),
    ("Zakodowane podziały JSON-LD sklejają instrukcje (#2564)", "app/Domain/Import/Url/ParserJsonLdPrzepisu.php",
     "test_json_ld_zakodowane_granice_zachowuja_kroki_i_skladniki",
     lambda s: replace_once(s, "        $tekst = self::decodeEntities($tekst);\n", "")),
    ("Odtworzony markup JSON-LD zostaje w zwykłym tekście (#2564)", "app/Domain/Import/Url/ParserJsonLdPrzepisu.php",
     "test_json_ld_dekoduje_tylko_dwie_warstwy_i_usuwanie_markup_zostaje",
     lambda s: replace_once(s, "strip_tags($tekst)", "$tekst")),
    ("JSON-LD dekoduje więcej niż dwie warstwy (#2564)", "app/Domain/Import/Url/ParserJsonLdPrzepisu.php",
     "test_json_ld_dekoduje_tylko_dwie_warstwy_i_usuwanie_markup_zostaje",
     lambda s: replace_once(s, "$layer < 2", "$layer < 3")),
    ("Koszt: druga ilość wraca do wyceny pierwszej (#2578)", "app/Domain/Recipes/Koszt/IloscZTekstu.php", "druga_bezposrednia_ilosc_nie_zaniza_masy_do_pierwszej_liczby",
     lambda s: replace_once(s, "if (preg_match('/^\\s*(?:(?:i|oraz)\\s+|\\+\\s*)?\\d/', $poDopasowaniu) === 1) {", "if (false) {")),
    ("Koszt: dopełniacz miary staje się sztuką (#2643)", "app/Domain/Recipes/Koszt/IloscZTekstu.php", "dopelniacz_jednostki_po_ulamku_zachowuje_mase_i_objetosc",
     lambda s: replace_once(s, "'kilograma' => ['g', 1000], ", "")),
    ("Koszt: grupowane tysiące stają się sztukami (#2561)", "app/Domain/Recipes/Koszt/IloscZTekstu.php", "grupowane_tysiace_zachowuja_cala_mase_i_nie_staja_sie_sztukami",
     lambda s: replace_once(s, r'|\d{1,3}(?: \d{3})+(?:[.,]\d+)?', '')),
    ("Prywatny zeszyt obiecuje odebranie dostepu (#2601)", "app/Http/Controllers/CollectionController.php",
     "test_prywatnosc_opisuje_zakres_i_nie_konczy_wspoldzielenia",
     lambda s: replace_once(s, "Zeszyt jest teraz prywatny. Publiczny dostęp został wyłączony.", "Zeszyt jest teraz widoczny tylko dla Ciebie.")),
    ("OCR: błąd zdjęcia znika z opisu pola (#2586)", "resources/views/pages/import/zdjecie.blade.php", "brak_pliku_i_zly_format_wiaza_widoczny_blad_z_opisem_pola_po_http",
     lambda s: replace_once(s, 'aria-describedby="f-zdjecie-help @error(\'zdjecie\') f-zdjecie-error @enderror"', 'aria-describedby="f-zdjecie-help"')),
    ("Odżywcze: inny surowiec trafia w mąkę pszenną (#2607)",
     "app/Domain/Recipes/Odzywcze/SlownikSkladnikow.php",
     "SlownikSurowcaOdzywczegoTest::test_maka_z_ciecierzycy_nie_jest_pszenna_gdy_csv_nie_ma_jej_aliasu",
     lambda s: replace_once(s, "if (in_array('z', $po, true) || in_array('ze', $po, true)) {", "if (false) {")),
    ("Odżywcze: stan przed nazwą znika (#2563)", "app/Domain/Recipes/Odzywcze/SlownikSkladnikow.php",
     "test_stan_przed_nazwa_nie_pozwala_dopasowac_surowego_produktu",
     lambda s: replace_once(s, "if (self::stanProduktu($slowo)) {", "if (false && self::stanProduktu($slowo)) {")),
    ("Odżywcze: stan po nazwie znika (#2563)", "app/Domain/Recipes/Odzywcze/SlownikSkladnikow.php",
     "test_stan_po_nazwie_nie_pozwala_dopasowac_surowego_produktu",
     lambda s: replace_once(s, "if (self::stanProduktu($slowo) || preg_match(self::ZMIENIA_PRODUKT, $slowo) === 1) {",
                            "if (preg_match(self::ZMIENIA_PRODUKT, $slowo) === 1) {")),
    ("Spiżarnia: sól z 'bez soli' udaje masło (#2613)", "app/Domain/Pantry/CoUgotuje.php",
     "CoUgotujeNegacjaSkladnikaTest::test_sol_nie_ukrywa_braku_masla_ani_nie_uruchamia_trybu_pilnego",
     lambda s: replace_once(s,
         "WHEN public.kuking_normalize(ri.ingredient_text) ~ '(^|[^a-z])bez[[:space:]]+'",
         "WHEN false")),
    ("Spiżarnia: jedyne opakowanie bez odcisku (#2783)", "resources/views/pages/pantry/termin.blade.php",
     "DwaOpakowaniaProduktuTest::test_stary_formularz_jedynego_opakowania_nie_nadpisuje_drugiego_po_awansie",
     lambda s: replace_once(s, "@elseif(! $drugie && $opakowanie !== null)",
                            "@elseif(! $drugie && $maDwa && $opakowanie !== null)")),
    ("Spiżarnia: identyczne B bez kontroli tożsamości (#2783)", "app/Domain/Pantry/ZmienTerminProduktu.php",
     "DwaOpakowaniaProduktuTest::test_identical_pola_nie_pozwalaja_staremu_formularzowi_nadpisac_awansowanego_opakowania",
     lambda s: replace_once(s, "(string) ($wiersz->first_package_id ?? $wiersz->id),",
                            "(string) $wiersz->id,")),
    ("Notatka z dalszej porcji wraca na pierwszą stronę (#2829)", "app/Http/Controllers/CollectionItemNoteController.php",
     "NotatkaZDalszejPorcjiZeszytuTest::test_blad_dlugosci_na_dalszej_porcji_przepisow_zachowuje_wpisany_tekst",
     lambda s: replace_once(s,
                            "return route('collections.show', ['collection' => $collection, $typ === UpdateCollectionItemNote::WPIS ? 'wpisy' : 'page' => $strona]);",
                            "return null;")),
    ("Porcje: Mniej od autora prowadzi do odrzucanej liczby (#2624)", "app/Domain/Recipes/Porcje/WyborPorcji.php",
     "SkalowaniePorcjiNaStroniePrzepisuTest::test_mniej_z_duzej_liczby_autora_prowadzi_do_przyjetych_stu_porcji",
     lambda s: replace_once(s, "return $kandydat >= self::NAJMNIEJ ? min($kandydat, self::NAJWIECEJ) : null;", "return $kandydat >= self::NAJMNIEJ ? $kandydat : null;")),
    ("Jawne sztuki ustępują zapamiętanym porcjom (#2848)", "app/Http/Controllers/RecipeController.php",
     "test_jawne_sztuki_i_powrot_do_autora_nie_reaktywuja_zapamietanych_porcji",
     lambda s: replace_once(s, "'wyborPorcji' => $wyborSztuk->wskazane() ? WyborPorcji::dla($model, null) : $wyborZapamietanych->wybor,",
                            "'wyborPorcji' => $wyborSztuk->przeliczone() ? WyborPorcji::dla($model, null) : $wyborZapamietanych->wybor,")),
    ("Powrót ze sztuk przywraca zapamiętane porcje (#2848)", "resources/views/pages/recipes/_wybor-sztuk.blade.php",
     "test_jawne_sztuki_i_powrot_do_autora_nie_reaktywuja_zapamietanych_porcji",
     lambda s: replace_once(s, "'porcje' => $zapamietanePorcje->maUstawienie() ? 'autor' : null,", "'porcje' => null,")),
    ("Wydruk gubi jawną podstawę sztuk autora (#2848)", "resources/views/pages/recipes/show.blade.php",
     "test_jawne_sztuki_i_powrot_do_autora_nie_reaktywuja_zapamietanych_porcji",
     lambda s: replace_once(s, "'porcje' => $wyborSztuk->podstawa() ? null : ($wyborSztuk->wskazane() ? 'autor' : $zapamietanePorcje->parametrBiezacego()),",
                            "'porcje' => $wyborSztuk->przeliczone() ? null : $zapamietanePorcje->parametrBiezacego(),")),
    ("Dopisek ze spisu gubi kontekst w formularzu (#2858)", "resources/views/pages/recipes/cooking.blade.php",
     "test_blad_dopisku_wraca_przez_rzeczywisty_link_spisu_bez_pytania_na_starcie",
     lambda s: replace_once(s, "@if($podgladZeSpisu)<input type=\"hidden\" name=\"spis\" value=\"1\">@endif\n                            <input type=\"hidden\" name=\"rewizja\"",
                            "<input type=\"hidden\" name=\"rewizja\"")),
    ("Dopisek ze spisu gubi kontekst w powrocie (#2858)", "app/Http/Controllers/DopisekGotowaniaController.php",
     "test_blad_dopisku_wraca_przez_rzeczywisty_link_spisu_bez_pytania_na_starcie",
     lambda s: replace_once(s, "'spis' => $request->input('spis') === '1' ? 1 : null,", "'spis' => null,")),
    ("Stara karta urodzin przywraca widoczność (#2864)", "app/Domain/Users/Actions/ZapiszWyboryUrodzin.php",
     "UrodzinyStaryFormularzTest::test_stara_karta_nie_przywraca_widocznosci_i_nie_wysyla_przypomnienia",
     lambda s: replace_once(s,
         'if ((bool) $current->birthday_visible_to_followers !== $widocznoscPrzyOtwarciu) {',
         'if (false) {')),
    ("Robots: awaria PCRE nie daje zgody (#2617)", "app/Domain/Import/Url/RobotsTxt.php",
     "test_wyczerpanie_pcre_nie_jest_zgoda_na_pobranie",
     lambda s: replace_once(s, "if ($wynik === false) {", "if (false) {")),
    ("Robots: zakodowane litery omijają zakaz (#2569)", "app/Domain/Import/Url/RobotsTxt.php",
     "test_zakodowane_unreserved_i_utf8_nie_omijaja_zakazu",
     lambda s: replace_once(s, "        $result = '';", "        return $value;\n        $result = '';")),
    ("Robots: znaki zarezerwowane dekodowane bez ograniczenia (#2569)", "app/Domain/Import/Url/RobotsTxt.php",
     "test_zarezerwowane_znaki_nie_staja_sie_separatorami_ani_operatorami",
     lambda s: replace_once(s, "        $result = '';", "        return rawurldecode($value);\n        $result = '';")),
    ("Robots: długość kodowania wygrywa nad oktetami (#2569)", "app/Domain/Import/Url/RobotsTxt.php",
     "test_normalizacja_nie_zmienia_grup_query_wildcardow_i_remisu",
     lambda s: replace_once(s, "strlen((string) preg_replace('/%[0-9A-F]{2}/', 'x', $wzorzec))", "strlen($wzorzec)")),
    ("Spiżarnia usuwa bez pytania (#2467)", "resources/views/pages/pantry/_produkty.blade.php",
     "test_pierwszy_klik_w_spizarni_rozwija_pytanie_zamiast_kasowac_produkt", spizarnia_bez_potwierdzenia),
    ("Stare potwierdzenie spiżarni kasuje dodane opakowanie (#2826)", "app/Domain/Pantry/UsunProduktPoPotwierdzeniu.php",
     "test_stare_potwierdzenie_jednego_opakowania_nie_usuwa_dodanego_pozniej_drugiego",
     lambda s: replace_once(s, "$widzianeDrugie !== $aktualnyZakres", "false")),
    ("Stare potwierdzenie A kasuje awansowane B (#2826)", "app/Domain/Pantry/UsunProduktPoPotwierdzeniu.php",
     "test_stare_potwierdzenie_a_nie_usuwa_b_po_awansie_nawet_przy_identycznej_tresci",
     lambda s: replace_once(s, "$widzianePierwsze !== $aktualnePierwsze", "false")),
    ("Planer usuwa bez pytania (#2468)", "resources/views/pages/planer/show.blade.php",
     "test_usuniecie_z_planera_wymaga_potwierdzenia_przy_wlasciwym_dniu_i_pozycji",
     planer_bez_potwierdzenia),
    # #2502: dawne odmierzenie nie może potwierdzić nowej liczby porcji.
    ("Zmiana porcji zachowuje stare odmierzenie (#2502)", "app/Domain/Recipes/Gotowanie/PostepGotowania.php", "test_zmiana_porcji_na_koncie_wymaga_ponownego_odmierzenia_a_krok_i_ta_sama_ilosc_nie",
     lambda s: replace_once(s, "'prepared_ingredient_ids' => [], 'servings_revision' => $wiersz->servings_revision + 1", "'prepared_ingredient_ids' => $wiersz->prepared_ingredient_ids, 'servings_revision' => $wiersz->servings_revision + 1")),
    ("Stary formularz przywraca porcje z innego urządzenia (#2502)", "app/Domain/Recipes/Gotowanie/PostepGotowania.php", "test_stary_formularz_po_zmianie_porcji_na_drugim_urzadzeniu_nie_przywraca_odmierzenia",
     lambda s: replace_once(s, "if ($wiersz->getKey() !== $widzianyPostepId || $this->porcje($wiersz) !== $porcjeZKontaNaStronie || $wiersz->servings_revision !== $widzianaRewizjaPorcji) {", "if (false) {")),
    ("Powrót porcji ABA przywraca stare odmierzenie (#2502)", "app/Domain/Recipes/Gotowanie/PostepGotowania.php", "test_stary_formularz_po_powrocie_do_tej_samej_liczby_porcji_nie_przywraca_odmierzenia",
     lambda s: replace_once(s, "$wiersz->servings_revision !== $widzianaRewizjaPorcji", "false")),
    ("Stary formularz po ponownym włączeniu postępu (#2502)", "app/Domain/Recipes/Gotowanie/PostepGotowania.php", "test_formularz_sprzed_wylaczenia_i_ponownego_wlaczenia_nie_potwierdza_dawnych_skladnikow",
     lambda s: replace_once(s, "$wiersz->getKey() !== $widzianyPostepId", "false")),
    ("Moje wpisy: stara strona udaje pusty dorobek (#2473)", "app/Http/Controllers/MojeWpisyController.php", "test_stara_druga_strona_po_usunieciu_wraca_do_istniejacych_wpisow",
     lambda s: replace_once(s, "if ($wpisy->currentPage() > $wpisy->lastPage())", "if (false)")),
    ("Moje wpisy: pusta porcja traci kontener (#2473)", "resources/views/pages/collections/moje-wpisy.blade.php", "test_pusta_lista_ma_prawdziwy_pusty_stan_i_kontener_bez_petli",
     lambda s: replace_once(s, 'id="lista-moich-wpisow"', '''id="{{ $wpisy->isEmpty() ? 'brak-listy' : 'lista-moich-wpisow' }}"''')),
    ("Zapamiętane gotowanie: 500 ukrytych wypiera dostępne (#2439)", "app/Domain/Recipes/Gotowanie/ZapamietaneGotowania.php",
     "GotowanieZapamietaneNaKoncieTest::test_wiecej_niz_500_niedostepnych_rekordow_nie_chowa_dostepnych_ani_kolejnej_strony",
     lambda s: replace_once(s, "} while ($rekordy->count() === self::PORCJA_REKORDOW);", "} while (false);")),
    ("Odżywcze: grupowana masa traci tysiące (#2560)", "app/Domain/Recipes/Odzywcze/ParserSkladnika.php",
     "GrupowanaMasaOdzywczaTest::test_kalkulator_liczy_cale_1500_g_zamiast_500_g",
     lambda s: replace_once(s, "private const LICZBA = '(?:\\d{1,3}(?: \\d{3})+|\\d+)(?:[.,]\\d+)?';", "private const LICZBA = '\\d+(?:[.,]\\d+)?';")),
    ("Odżywcze: błędna grupa jest częściową masą (#2560)", "app/Domain/Recipes/Odzywcze/ParserSkladnika.php",
     "GrupowanaMasaOdzywczaTest::test_niepoprawna_grupa_odmawia_zamiast_liczyc_fragment_lub_miare_puszki",
     lambda s: replace_once(s, "$niejednoznacznaIlosc = self::maBledneGrupowanie($t);", "$niejednoznacznaIlosc = false;")),
    ("Odżywcze: błędna masa bierze miarę puszki (#2560)", "app/Domain/Recipes/Odzywcze/KalkulatorWartosci.php",
     "GrupowanaMasaOdzywczaTest::test_niepoprawna_grupa_odmawia_zamiast_liczyc_fragment_lub_miare_puszki",
     lambda s: replace_once(s, "if ($odczyt->sprzecznaMasaWNawiasie || $odczyt->niejednoznacznaIlosc) {", "if ($odczyt->sprzecznaMasaWNawiasie) {")),
    ("Odżywcze: masa razem mnożona przez puszki (#2487)", "app/Domain/Recipes/Odzywcze/ParserSkladnika.php",
     "test_jawnie_laczna_masa_nie_jest_mnozona_przez_liczbe_opakowan",
     lambda s: replace_once(s, "! $nawiasRazem && ", "")),
    ("Odżywcze: sprzeczna masa wpada w domyślną miarę (#2487)", "app/Domain/Recipes/Odzywcze/KalkulatorWartosci.php",
     "MasaLacznaWNawiasieTest::test_kalkulator_uzywa_lacznej_masy_raz_i_odmawia_przy_sprzecznym_nawiasie",
     lambda s: replace_once(s, "if ($odczyt->sprzecznaMasaWNawiasie || $odczyt->niejednoznacznaIlosc) {",
                            "if ($odczyt->niejednoznacznaIlosc) {")),
    # #2524: przeliczony składnik i nieprzeliczona kwota autora to sprzeczna strona.
    ("Koszt autora pozostaje bazowy po zmianie porcji (#2524)", "resources/views/pages/recipes/show.blade.php", "test_koszt_autora_i_skladniki_uzywaja_tego_samego_wyboru_porcji_takze_w_wydruku",
     lambda s: replace_once(s, "{{ $kosztAutora }}</p>", "{{ $recipe->costLabel() }}</p>")),
    # #2536–#2539: strukturalny import z URL bez modelu i bez utraty treści.
    ("Pusty Recipe zasłania pełny w JSON-LD (#2536)", "app/Domain/Import/Url/ParserJsonLdPrzepisu.php",
     "test_pusty_przepis_w_tym_samym_grafie_nie_zaslania_pelnego",
     lambda s: replace_once(s,
         "            if ($znaleziony !== null) {\n                return $znaleziony;\n            }",
         "            if ($znaleziony === null) {\n                return null;\n            }")),
    ("Meta content składnika znika z mikrodanych (#2538)", "app/Domain/Import/Url/ParserMikrodanychPrzepisu.php",
     "test_meta_content_skladnik_i_kroki_sa_odczytane_w_kolejnosci",
     lambda s: replace_once(s,
         "foreach ($this->elementyAliasow($wlasciwosci, 'recipeingredient', 'ingredients') as $el) {\n            $tekst = $this->jednaLinia($this->wartosc($el));",
         "foreach ($this->elementyAliasow($wlasciwosci, 'recipeingredient', 'ingredients') as $el) {\n            $tekst = $this->jednaLinia($this->tekstElementu($el));")),
    ("Meta content kroku znika z mikrodanych (#2538)", "app/Domain/Import/Url/ParserMikrodanychPrzepisu.php",
     "test_meta_content_skladnik_i_kroki_sa_odczytane_w_kolejnosci",
     lambda s: replace_once(s,
         "        return $this->wiersze($this->wartosc($el));",
         "        return $this->wiersze($this->tekstElementu($el));")),
    ("Meta content zagnieżdżonego kroku znika (#2538)", "app/Domain/Import/Url/ParserMikrodanychPrzepisu.php",
     "test_meta_content_skladnik_i_kroki_sa_odczytane_w_kolejnosci",
     lambda s: replace_once(s,
         "                    array_push($wynik, ...$this->wiersze($this->wartosc($pole)));",
         "                    array_push($wynik, ...$this->wiersze($this->tekstElementu($pole)));")),
    ("Alias składnika powiela jeden węzeł (#2626)", "app/Domain/Import/Url/ParserMikrodanychPrzepisu.php",
     "test_wiele_nazw_itemprop_nie_powiela_skladnika_ani_nie_zmienia_kolejnosci",
     lambda s: replace_once(s,
         "$this->elementyAliasow($wlasciwosci, 'recipeingredient', 'ingredients')",
         "array_merge($wlasciwosci['recipeingredient'] ?? [], $wlasciwosci['ingredients'] ?? [])")),
    ("Alias kroku powiela jeden węzeł (#2626)", "app/Domain/Import/Url/ParserMikrodanychPrzepisu.php",
     "test_wiele_nazw_itemprop_nie_powiela_kroku_howtosection",
     lambda s: replace_once(s,
         "$this->elementyAliasow($wlasciwosci, 'itemlistelement', 'step')",
         "array_merge($wlasciwosci['itemlistelement'] ?? [], $wlasciwosci['step'] ?? [])")),
    ("Tekstowe ułamkowe porcje znikają (#2539)", "app/Domain/Import/Url/ParserJsonLdPrzepisu.php",
     "test_tekstowe_ulamkowe_porcje_sa_rownowazne_liczbie_bez_zgadywania_jednostek",
     lambda s: replace_once(s,
         "(\\d{1,4}(?:[.,]\\d{1,2})?)",
         "(\\d{1,3})")),
    ("Porcje numeryczne poza granicami formularza (#2539)", "app/Domain/Import/Url/ParserJsonLdPrzepisu.php",
     "test_numeryczne_porcje_respektuja_granice_i_precyzje_formularza",
     lambda s: replace_once(s,
         "! is_finite($liczba) || $liczba < 0.5 || $liczba > 999 || round($liczba, 2) !== $liczba",
         "$liczba <= 0 || $liczba > 1000")),
    ("Koszt: opis celu kasuje rozpoznaną masę (#2508)", "app/Domain/Recipes/Koszt/IloscZTekstu.php", "opis_celu_po_produkcie_nie_kasuje_odczytanej_masy",
     lambda s: replace_once(s, r'^\s*(?:do|lub|albo)\s+\d', r'(?:^|\s)(?:do|lub|albo)\s+\d')),
    ("Koszt: słowny zakres gubi granice i miarę (#2477)", "app/Domain/Recipes/Koszt/IloscZTekstu.php", "slowne_zakresy_zachowuja_obie_granice_i_miare",
     lambda s: replace_once(s, r'(?:\s*-\s*|\s+(?:do|lub|albo)\s+)', r'\s*-\s*')),
    ("Koszt: uszkodzony zakres przyjmuje początek (#2477)", "app/Domain/Recipes/Koszt/IloscZTekstu.php", "uszkodzony_zakres_nie_liczy_poprawnego_poczatku",
     lambda s: replace_once(s, "|| preg_match('/^[.,\\/]\\d|^\\s*(?:do|lub|albo)\\s+\\d/', $poDopasowaniu) === 1",
         "|| false")),
    ("Koszt: uszkodzona pojedyncza liczba daje wycenę (#2477)", "app/Domain/Recipes/Koszt/IloscZTekstu.php", "uszkodzony_ogon_jednej_liczby_nie_udaje_poprawnej_ilosci",
     lambda s: replace_once(s, "                $poDopasowaniu = substr($t, $trafienie[0][1] + strlen($trafienie[0][0]));",
         "                if (($trafienie[2][0] ?? '') === '') { return self::zMiara(self::liczba($trafienie[1][0]), $slowo); }\n                $poDopasowaniu = substr($t, $trafienie[0][1] + strlen($trafienie[0][0]));")),
    ("Koszt: mąka gryczana udaje kaszę (#2605)", "database/data/ceny_skladnikow.csv",
     "prawdziwy_cennik_nie_myli_maki_gryczanej",
     lambda s: replace_once(s, "kasza gryczana|kaszy gryczanej|kasze gryczana,,",
                            "kasza gryczana|kaszy gryczanej|kasze gryczana|gryczana|gryczanej,,")),
    # #2476: dwa bieżące składniki z identycznym tekstem/ile=null są różne.
    # #2531: mapowanie pól przepisu przeszło do `PrzepisDoPaczki` (wspólne dla
    # pełnej paczki i kopii jednego przepisu).
    ("Bieżący eksport pomija wybór Bez ilości (#2476)", "app/Domain/Users/Exports/PrzepisDoPaczki.php",
     "test_biezacy_szkic_zachowuje_dwa_rozne_wybory_bez_ilosci_w_json",
     lambda s: replace_once(s, "                'bez_ilosci' => (bool) $item->no_amount,\n", "")),
    # #2639: własne wykonanie musi wskazać istniejący własny plik tej paczki.
    ("Eksport pobiera tytuł przepisu bez aktualnego dostępu (#2785)", "app/Domain/Users/Exports/CollectUserExportData.php",
     "test_nowy_eksport_zawieszonego_odbiorcy_nie_zawiera_pozniej_zmienionego_tytulu",
     lambda s: replace_once(s,
         "$czyta = $recipe !== null && $policy->readShared($user, $recipe);",
         "$czyta = $recipe !== null;")),
    ("Limit listy odzyskania przed filtrem moderacji (#2868)", "app/Domain/Recipes/OdzyskajUsunietyPrzepis.php",
     "test_piecdziesiat_chronionych_nowszych_nie_zaslania_starszego_czystego_przepisu",
     lambda s: replace_once(s, "        $skan = $po;\n",
         "        return ['przepisy' => $this->kandydaci($autor)->limit(50)->get()->reject(fn (Recipe $przepis): bool => $this->wSprawieModeracyjnej($przepis))->values(), 'nastepny' => null, 'dalsza' => false];\n\n        $skan = $po;\n")),
