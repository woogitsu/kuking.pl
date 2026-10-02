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
KOLAZ_PRIORYTET = """                                 @if($loop->first)
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
    if source.count(poczatek) != 1 or source.count(koniec) != 1:
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
    ("HTTP import paczki nie pilnuje budżetu struktury (#2611)", "app/Domain/Users/Import/PodgladPaczkiEksportu.php",
     "test_paczka_tuz_ponad_budzetem_struktury_jest_odrzucona_przez_http_bez_poczekalni",
     lambda s: replace_once(s, 'if ($kontenery > self::MAX_KONTENEROW_JSON) {', 'if ($kontenery > self::MAX_KONTENEROW_JSON + 1) {')),
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
         "if (! array_is_list($wartosc)) {",
         "if (isset($wartosc['@type']) || isset($wartosc['itemListElement']) || isset($wartosc['text'])) {")),
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
    ("Porcje: Mniej od autora prowadzi do odrzucanej liczby (#2624)", "app/Domain/Recipes/Porcje/WyborPorcji.php",
     "SkalowaniePorcjiNaStroniePrzepisuTest::test_mniej_z_duzej_liczby_autora_prowadzi_do_przyjetych_stu_porcji",
     lambda s: replace_once(s, "return $kandydat >= self::NAJMNIEJ ? min($kandydat, self::NAJWIECEJ) : null;", "return $kandydat >= self::NAJMNIEJ ? $kandydat : null;")),
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
    ("Bieżący eksport pomija wybór Bez ilości (#2476)", "app/Domain/Users/Exports/CollectUserExportData.php",
     "test_biezacy_szkic_zachowuje_dwa_rozne_wybory_bez_ilosci_w_json",
     lambda s: replace_once(s, "                'bez_ilosci' => (bool) $item->no_amount,\n", "")),
    # #2639: własne wykonanie musi wskazać istniejący własny plik tej paczki.
    ("Eksport gubi relację wykonania z własnym przepisem (#2639)", "app/Domain/Users/Exports/CollectUserExportData.php",
     "test_dwa_wlasne_przepisy_o_tym_samym_tytule_maja_odrebne_prawdziwe_cele_w_paczce",
     lambda s: replace_once(s,
         "            'plik_wlasnego_przepisu' => $this->granica->widzi($event->recipe)\n"
         "                ? ($this->plikiWlasnychPrzepisow[(string) $event->recipe_id] ?? null)\n"
         "                : null,\n", "")),
    ("Eksport planu zdjęć ładuje autora przepisu (#2639)", "app/Domain/Users/Exports/ExportPhotoPlan.php",
     "test_dwa_wlasne_przepisy_o_tym_samym_tytule_maja_odrebne_prawdziwe_cele_w_paczce",
     lambda s: replace_once(s, "->with(['media', 'recipe.author'])->get()", "->with(['media', 'recipe'])->get()")),
    # #2479: wrócenie do nazywania hidden szkicem ma oblać na rzeczywistym ZIP-ie.
    ("Ukryty przepis nazwany szkicem w eksporcie (#2479)", "app/Domain/Users/Exports/RecipeArchiveStatus.php",
     "test_ukryty_po_publikacji_nie_jest_szkicem_w_karcie_ani_spisie",
     lambda s: replace_once(s,
         "Recipe::STATUS_HIDDEN => ['full' => 'Przepis ukryty', 'short' => 'ukryty'],",
         "Recipe::STATUS_HIDDEN => ['full' => 'To był szkic — nigdy nie został opublikowany', 'short' => 'szkic'],")),
    # #2478: rok jako jedyne źródło ma naprawdę otworzyć sekcję w pliku ZIP.
    ("Sam rok rodzinny znika z HTML eksportu (#2478)", "resources/views/exports/recipe.blade.php",
     "test_sam_rok_rodzinny_jest_w_html_i_json_bez_pustej_sekcji_dla_innego_przepisu",
     lambda s: replace_once(s,
         "@if($recipe->source_note || $recipe->source_person || $recipe->source_url || $recipe->family_since_year)",
         "@if($recipe->source_note || $recipe->source_person || $recipe->source_url)")),
    # #988: komunikat po akcji ma jawny rodzaj. Goły `->with('status', …)`
    # wróciłby do zielonej plakietki także dla odmowy.
    ("Goły ->with('status') wraca do kontrolera", "app/Http/Controllers/SmakowicieController.php", "test_w_app_nie_ma_golego_zapisu_statusu_bez_rodzaju",
     lambda s: replace_once(s, "->with(Komunikat::sukces('Cofnięte.'))", "->with('status', 'Cofnięte.')")),
    ("Odmowa nazwana sukcesem", "app/Http/Controllers/SmakowicieController.php", "test_sukces_nie_niesie_tekstu_odmowy",
     lambda s: replace_once(s, "Komunikat::sukces('Cofnięte.')", "Komunikat::sukces('Nie udało się cofnąć.')")),
    # #2027: rejestracja Google/Facebook wiąże zapamiętany cel z nowym
    # kontem. Bez tej linijki onboarding kończy się na stronie domyślnej.
    ("Nowe konto Google gubi powrót do rozmowy", POWROT_KOMENTARZA_GOOGLE, POWROT_KOMENTARZA_GOOGLE_TEST,
     lambda s: replace_once(s, "        $rozmowa->przypiszKonto($request);\n", "")),
    ("Nowe konto Facebook gubi powrót do rozmowy", POWROT_KOMENTARZA_FACEBOOK, POWROT_KOMENTARZA_FACEBOOK_TEST,
     lambda s: replace_once(s, "        $rozmowa->przypiszKonto($request);\n", "")),
    # #2420: każdy z trzech sposobów zakładania konta musi przejąć
    # zaproszenie z sesji. Znacznik asercji wskazuje dokładnie ten powrót.
    ("Rejestracja hasłem gubi zaproszenie do zeszytu (#2420)", "app/Http/Controllers/Auth/RegisterController.php", "test_gosc_po_rejestracji_i_pominieciu_wraca_na_podglad_a_dolacza_dopiero_po_kliknieciu",
     lambda s: replace_once(s, "        $dolaczenie->przypiszKonto($request);\n", "")),
    ("Nowe konto Google gubi zaproszenie do zeszytu (#2420)", POWROT_KOMENTARZA_GOOGLE, "test_nowe_konto_google_wraca_do_podgladu_zaproszenia_do_zeszytu",
     lambda s: replace_once(s, "        $dolaczenie->przypiszKonto($request);\n", "")),
    ("Nowe konto Facebook gubi zaproszenie do zeszytu (#2420)", POWROT_KOMENTARZA_FACEBOOK, "test_nowe_konto_facebook_wraca_do_podgladu_zaproszenia_do_zeszytu",
     lambda s: replace_once(s, "        $dolaczenie->przypiszKonto($request);\n", "")),
    # #1868: instalacja bez wskazania migawki wróciłaby do ruchomego mirrora.
    ("APT install bez migawki", OBRAZ_KOPII, APT_MIGAWKA_TEST,
     lambda s: replace_once(s,
         "apt-get -o Dir::Etc::sourcelist=/tmp/apt-snapshot/snapshot.list -o Dir::Etc::sourceparts=/tmp/apt-snapshot/puste install",
         "apt-get install")),
    # D-088: usunięcie odmowy rollbacku nie może przejść niezauważone, nawet
    # gdy osobny przypadek z wygasłym ukryciem poprawnie cofa migrację.
    ("Rollback aktywnych ukryć przestaje odmawiać", "database/migrations/2026_09_26_100000_create_hides_table.php", "CofniecieMigracjiUkrycNieOdslaniaTest",
     lambda s: replace_once(s, 'if ($aktywne > 0) {', 'if (false) {')),
    ("Composer błędnie deklaruje MIT", "composer.json", "DeklaracjaLicencjiJestSpojnaTest",
     lambda s: replace_once(s, '"license": "proprietary"', '"license": "MIT"')),
    ("LICENSE traci zastrzeżenie praw", "LICENSE", "DeklaracjaLicencjiJestSpojnaTest",
     lambda s: replace_once(s, 'Wszelkie prawa zastrzeżone.', 'Prawa nie są zastrzeżone.')),
    ("Obraz błędnie deklaruje MIT", "Dockerfile", "DeklaracjaLicencjiJestSpojnaTest",
     lambda s: replace_once(s, 'org.opencontainers.image.licenses="proprietary"', 'org.opencontainers.image.licenses="MIT"')),
    ("Format UUID", WYBOR_ZESZYTU, COLLECTION_TEST,
     lambda s: replace_once(s, "'bail', 'nullable', 'uuid',", "'bail', 'nullable',")),
    # Paginacja panelu moderacji (audyt B1, zn. 1): powrót do `links()`, czyli
    # widoku Tailwinda niewidocznego na komputerze, ma zapalić test.
    ("Kolejka zgłoszeń wraca do links()", "resources/views/pages/admin/reports.blade.php", "PaginacjaPaneluModeracjiTest",
     lambda s: replace_once(s, '<x-paginacja-panelu :paginator="$reports" />', "{{ $reports->links() }}")),
    ("Własność zeszytu", WYBOR_ZESZYTU, COLLECTION_TEST,
     lambda s: replace_once(s, "Rule::exists('collections', 'id')->where(fn ($q) => $q->whereIn('id', Collection::query()->dostepneDoZapisuDla($osoba)->select('collections.id')))", "Rule::exists('collections', 'id')")),
    ("Komunikat po powrocie", LAYOUT, COLLECTION_TEST, remove_notice),
    ("Podpis co najmniej 18 px", CSS, COMPOSER_TEST, smaller_help),
    # Obwódka list w panelu „Aa · Wygląd” (audyt B1, zn. 3): powrót do
    # `--color-border` (1,3:1 na tle panelu) ma zapalić test kontrastu.
    ("Obwódka listy wyglądu poniżej 3:1", "resources/css/szybki-wyglad.css", "KontrolkiPaneluWygladuMajaWidocznaObwodkeTest",
     lambda s: replace_once(s, "select { border: 2px solid var(--color-border-strong);", "select { border: 2px solid var(--color-border);")),
    # D-262 (AGENTS.md §5): piąty selektor nie może powołać się na wyjątek
    # panelu moderacji bez zmiany zamkniętej listy.
    ("Piąty selektor powołuje się na D-262", CSS, "WyjatekD262ZamknietaListaTest",
     lambda s: replace_once(s, "\n  .badge-cichy {\n", "\n  /* wyjątek D-262 */\n  .badge-cichy {\n")),
    ("Akcja GitHuba na ruchomym tagu", AKCJA_PHP, AKCJE_SHA_TEST, akcja_php_na_ruchomym_tagu),
    ("Test skryptu Pythona bez kroku w CI", CI_WORKFLOW, TESTY_PYTHONA_W_CI_TEST,
     lambda s: replace_once(s, "discover -s scripts -p test_podzial_kontroli.py -v", "discover -s scripts -p test_podzial_kontroli_x.py -v")),
    ("Test przyrządu obciążenia bez kroku w CI", CI_WORKFLOW, TESTY_PYTHONA_W_CI_TEST,
     lambda s: replace_once(s, "discover -s docs/obciazenie -p test_connection.py -v", "discover -s docs/obciazenie -p test_connection_x.py -v")),
    ("Licznik w widocznym menu konta", LAYOUT, "test_wejscie_do_panelu_pokazuje_sume_kolejek",
     lambda s: replace_once(s, """<li><a href="{{ route('admin.reports') }}">Otwórz panel moderacji <x-licznik-kolejki :ile="$czekaWPanelu" /></a></li>""", """<li><a href="{{ route('admin.reports') }}">Otwórz panel moderacji</a></li>""")),
    ("Strażnik tekstu bez własnego wpisu", STRAZNIK_SAM_SKRYPT, STRAZNIK_TEKSTU_TEST,
     bez_wpisu_dla_straznika),
    ("Odstępstwo bez znacznika", STRAZNIK_PLIK_ODSTEPSTWA, STRAZNIK_TEKSTU_TEST,
     bez_znacznika_odstepstwa),
    ("Plik z node --test nieskopiowany do etapu assets", OBRAZ_ASSETOW, OBRAZ_ASSETOW_TEST,
     bez_kopii_testu_assetow),
    ("Zdjęcie CHECK-a 2FA przed strażnikiem cofnięcia", MIGRACJA_2FA, MIGRACJA_2FA_TEST,
     zdjecie_checku_przed_straznikiem_2fa),
    ("Pierwszy ekran opłacony mniejszym pismem", PIERWSZY_EKRAN_CSS, PIERWSZY_EKRAN_TEST,
     mniejsze_pismo_na_pierwszym_ekranie),
    ("Strażnik sekretów osłabiony do samego https", STRAZNIK_HOSTA, STRAZNIK_HOSTA_TEST,
     bez_sprawdzenia_hosta),
    ("Strażnik sekretów bez sprawdzenia ścieżki", STRAZNIK_HOSTA, STRAZNIK_HOSTA_TEST,
     bez_sprawdzenia_sciezki),
    ("Komunikat bazy z wartościami w logu serwera", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, "return $this->komunikatBazy($e);", "return $e->getMessage();")),
    ("Błąd PCRE w filtrze logu po cichu zeruje treść", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, "return $czysty ?? self::BLAD_FILTRA.' (długość: '.strlen($tekst).' B)';",
                            "return (string) $czysty;")),
    ("Filtr logu na loggerze zabiera webhookowi obiekt wyjątku", "app/Logging/FiltrDanychOsobowych.php", LOG_SERWERA_TEST,
     lambda s: replace_once(s, "if ($handler instanceof ProcessableHandlerInterface) {", "if (false) {")),
    ("Surowy komunikat wyjątku w logu kasowania zdjęcia", LOG_OPERACYJNY, LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "'error' => BezpiecznyBlad::kontekst($e),", "'error' => $e->getMessage(),")),
    ("Wzorzec e-maila z katastrofalnym nawracaniem", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, r"'/(?<![A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]++@(?=[A-Za-z0-9\-.]*?\.[A-Za-z]{2})[A-Za-z0-9\-]++(?:\.[A-Za-z0-9\-]++)*+/'",
                            r"'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/'")),
    ("Klucz tablicy z adresem przechodzi do logu", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, "$klucz = $this->oczyscTekst($klucz);", "$klucz = (string) $klucz;")),
    ("Obiekt w kontekście logu idzie do formatera w całości", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, "return $this->obiekt($wartosc, $glebokosc);", "return $wartosc;")),
    ("Za głęboka tablica przechodzi surowa", LOG_SERWERA, LOG_SERWERA_TEST,
     lambda s: replace_once(s, "return self::ZA_GLEBOKO;", "return $wartosc;")),
    ("Połknięty wyjątek bez miejsca przyczyny", "app/Logging/BezpiecznyBlad.php", LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "$miejscaPrzyczyn[] = self::miejsceWApp($p);", "$miejscaPrzyczyn[] = null;")),
    ("Komunikat wyjątku w kontekście budowanym przez metodę pomocniczą", "app/Turnstile/KlientTurnstile.php", LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "'Cloudflare nie odpowiedział na weryfikację Turnstile.', [\n                'error' => BezpiecznyBlad::kontekst($e),",
                            "'Cloudflare nie odpowiedział na weryfikację Turnstile.', [\n                'komunikat' => $e->getMessage(),")),
    ("Komunikat transportu przez BezpiecznyKomunikat w logu listu o paczce", "app/Jobs/NotifyUserExportReady.php", LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "'error' => BezpiecznyBlad::kontekst($e),", "'error' => \\App\\Poczta\\BezpiecznyKomunikat::z($e->getMessage()),")),
    ("Komunikat bazy przez BezpiecznyKomunikat w logu śladu listu", "app/Poczta/ZapiszNieudanyList.php", LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "'error' => BezpiecznyBlad::kontekst($e),", "'error' => BezpiecznyKomunikat::z($e->getMessage()),")),
    ("Skaner logów ślepy na report()", "tests/Feature/LogOperacyjnyBezKomunikatuWyjatkuTest.php", LOG_OPERACYJNY_TEST,
     lambda s: replace_once(s, "(?:logger|report)", "(?:logger)")),
    # Audyt B1, zn. 9: nieistniejąca zmienna koloru ma zapalić strażnika.
    ("Kolor z niezdefiniowanej zmiennej", "resources/css/tagi-w-opisie.css", "UzyteZmienneKolorowIstniejaTest",
     lambda s: replace_once(s, "color: var(--color-ink);", "color: var(--color-text);")),
    # #492: ciemne powierzchnie ramy marki biorą tokeny, nie gołe kolory.
    ("Rama marki z zaszytym kolorem", "resources/css/marka-rama.css", "RamaMarkiNieZaszywaKolorowTest",
     lambda s: replace_once(s, ".composer-help { color: var(--marka-ciemny-tekst-cichy); }", ".composer-help { color: #CBD0C6; }")),
    # Audyt B1, zn. 8: sztywny rem zamiast tokenu ignoruje skalę tekstu.
    ("Bramka R2 każe sprawdzać Sentry (#2382)", "docs/infra/BRAMKA_R2.md", "BramkaR2NieKierujeDoSentryTest",
     lambda s: replace_once(s, "nie jest wdrożony", "jest wdrożony")),
    ("Linki sąsiednich wpisów bez skali tekstu", "resources/css/wpis-nawigacja-sasiedzi.css", "TekstyZAudytuB9MowiaPrawdeTest",
     lambda s: replace_once(s, "font-size: var(--text-body);", "font-size: 1.125rem;")),
    ("Cofnięcie CHECK-a kontaktu bez odmowy przy sierotach", KONTAKT_MIGRACJA, KONTAKT_MIGRACJA_TEST,
     lambda s: replace_once(s, "        if ($istniejaSieroty) {\n", "        if (false && $istniejaSieroty) {\n")),
    ("Cofnięcie znaczników odpowiedzi bez odmowy", KONTAKT_ZNACZNIKI, KONTAKT_ZNACZNIKI_TEST,
     lambda s: replace_once(s, "        if (DB::table('contact_message_replies')->whereNotNull('reply_key')->exists()) {\n", "        if (false) {\n")),
    ("Jedna sprawa RODO w toku bez odmowy przy duplikatach", RODO_W_TOKU_MIGRACJA, RODO_W_TOKU_MIGRACJA_TEST,
     lambda s: replace_once(s, "        if ($ileKont > 0) {\n", "        if (false) {\n")),
    ("Lokalne akcje poza filtrem widoku", BRAMKA_SKRYPT, BRAMKA_AKCJE_TEST,
     akcje_poza_filtrem_widoku),
    ("Dockerfile poza wzorcem builda obrazu", BRAMKA_SKRYPT, BRAMKA_WEJSCIA_TEST,
     dockerfile_poza_wzorcem_obrazu),
    ("Pliki grupy wyścigów poza wzorcem joba", BRAMKA_SKRYPT, BRAMKA_WEJSCIA_TEST,
     grupa_wyscigow_poza_wzorcem),
    ("Ciężkie joby zawężane także poza PR-em", BRAMKA_SKRYPT, BRAMKA_POZA_PR_TEST,
     zawezanie_takze_poza_pr),
    ("Wzorzec przyrządu #605 łapie każdą zmianę", BRAMKA_SKRYPT, BRAMKA_OBOK_TEST,
     wzorzec_przyrzadu_lapie_wszystko),
    ("Filtr widoku zawężany także poza PR-em", BRAMKA_SKRYPT, WIDOK_POZA_PR_TEST,
     widok_zawezany_poza_pr),
    ("Podział wierszy przez \\R bez u", PODZIAL_WIERSZY, PODZIAL_WIERSZY_TEST,
     lambda s: replace_once(s, r"preg_split('/\r\n|\n|\r/', $tresc)", r"preg_split('/\R/', $tresc)")),
    ("Test dymny przepuszcza każde przekierowanie", WDROZENIE_WORKFLOW, WDROZENIE_TEST,
     stara_sonda_https),
    ("Test dymny bez sondy wydania przed sprawdzeniami", WDROZENIE_WORKFLOW, WDROZENIE_TEST,
     test_dymny_bez_sondy_wydania),
    ("Końcowa sonda wydania z jedną próbą", WDROZENIE_WORKFLOW, WDROZENIE_TEST,
     koncowa_sonda_jedna_proba),
    ("Akcja rollback, która nic nie cofa", WDROZENIE_WORKFLOW, WDROZENIE_TEST,
     akcja_rollback_wraca),
    ("Preview gotowe po samym adresie", PREVIEW_WORKFLOW, PREVIEW_IAC_TEST,
     lambda s: replace_once(s, 'if ! czekaj_na_preview "$REPO" "$SHA"; then', 'if false; then')),
    ("Preview bez sondy wydania przed sprawdzeniami", PREVIEW_WORKFLOW, PREVIEW_IAC_TEST,
     lambda s: replace_once(s, 'if ! sonda_wydanie "$BASE_URL" "$OCZEKIWANY_SHA"; then', 'if false; then')),
    ("Job smoke znów dostaje prawo zapisu do PR-ów (#1941)", PREVIEW_WORKFLOW, PREVIEW_IAC_TEST,
     lambda s: replace_once(
         s,
         "    permissions:\n      contents: read\n      deployments: read\n",
         "    permissions:\n      contents: read\n      deployments: read\n      pull-requests: write\n",
     )),
    ("Apply IaC bez przekazanej bramki CI", IAC_WORKFLOW, PREVIEW_IAC_TEST,
     apply_bez_bramki_ci),
    ("Brak KUKING_WAIT_FOR_CI jako wyłączenie", IAC_RAILWAY_TS, PREVIEW_IAC_TEST,
     lambda s: replace_once(s, 'if (bramkaCI !== "true" && bramkaCI !== "false") {', 'if (false) {')),
    ("/wydanie z sesją i ciasteczkami", WYDANIE_TRASY, WYDANIE_TEST,
     lambda s: replace_once(s, "Route::get('/wydanie', WydanieController::class)->name('wydanie');", "Route::middleware('web')->get('/wydanie', WydanieController::class)->name('wydanie');")),
    ("Obraz bazowy bez digestu", OBRAZ_KOPII, OBRAZY_DIGEST_TEST,
     bez_digestu_obrazu_kopii),
    ("Oryginał zdjęcia z nietkniętym XMP", USUN_GPS, XMP_TEST,
     lambda s: replace_once(s, "$wynik = self::usunXmp(self::usunGpsZExif($bajty));", "$wynik = self::usunGpsZExif($bajty);")),
    ("Sekret OAuth w workerze", RAILWAY_IAC, ZMIENNE_ROL_TEST,
     lambda s: replace_once(s, 'env: { ...workerEnv, APP_ROLE: "worker" },', 'env: { ...workerEnv, GOOGLE_CLIENT_SECRET: ctx.shared.GOOGLE_CLIENT_SECRET, APP_ROLE: "worker" },')),
    ("Worker bez klucza moderacji modelem", RAILWAY_IAC, ZMIENNE_ROL_TEST,
     lambda s: replace_once(s, "    ...modelEnv,\n", "")),
    ("Scheduler bez adresu alarmów moderacji", RAILWAY_IAC, ZMIENNE_ROL_TEST,
     lambda s: replace_once(s, "const schedulerEnv = { ...appEnv, ...pocztaEnv, ...alarmModeratoraEnv, ...kopieOdczytEnv, ", "const schedulerEnv = { ...appEnv, ...pocztaEnv, ...kopieOdczytEnv, ")),
    # Filtr na samą metodę strażnika, nie całą klasę: ta sama mutacja zapala
    # też macierz, a kontrola ma dowieść, że parser `routes/console.php`
    # i komend WIDZI digest wołający `Mail::` z procesu schedulera.
    # Web czyta adres synchronicznie w `AlarmujOPilnymZgloszeniu` (D-236).
    ("Web bez adresu alarmów moderacji", RAILWAY_IAC, ZMIENNE_ROL_TEST,
     lambda s: replace_once(s, "...czyszczenieCdnEnv, ...alarmModeratoraEnv, ...pushPublicznyEnv, ...importEnv };", "...czyszczenieCdnEnv, ...pushPublicznyEnv, ...importEnv };")),
    ("Klucz modelu bez warunku produkcji", RAILWAY_IAC, TYLKO_PRODUKCJA_TEST,
     lambda s: replace_once(s, 'OPENAI_MODERATION_KEY: isProduction ? ctx.shared.OPENAI_MODERATION_KEY : "",', "OPENAI_MODERATION_KEY: ctx.shared.OPENAI_MODERATION_KEY,")),
    ("Adres alarmu bez warunku produkcji", RAILWAY_IAC, TYLKO_PRODUKCJA_TEST,
     lambda s: replace_once(s, 'KUKING_MODEL_ALARM_EMAIL: isProduction ? ctx.shared.KUKING_MODEL_ALARM_EMAIL : "",', "KUKING_MODEL_ALARM_EMAIL: ctx.shared.KUKING_MODEL_ALARM_EMAIL,")),
    ("Scheduler bez kluczy poczty przy digeście", RAILWAY_IAC, HARMONOGRAM_POCZTA_TEST,
     lambda s: replace_once(s, "const schedulerEnv = { ...appEnv, ...pocztaEnv, ", "const schedulerEnv = { ...appEnv, ")),
    ("Kopia bazy ze spreadem zestawu aplikacji", RAILWAY_IAC, KOPIA_BEZ_SPREADU_TEST,
     lambda s: replace_once(s, KOPIA_DB_URL, "      ...schedulerEnv,\n" + KOPIA_DB_URL)),
    ("Polityka znowu obiecuje pełną kopię", POLITYKA, POLITYKA_KOPIA_TEST,
     lambda s: replace_once(s, "pobrać stamtąd **paczkę z Twoimi danymi**", "pobrać stamtąd pełną kopię swoich danych")),
    ("Polityka przestaje wymieniać obserwowane tagi", POLITYKA, POLITYKA_PACZKA_TEST,
     lambda s: replace_once(s, "jakie tagi obserwujesz", "kogo obserwujesz")),
    ("Polityka gubi prywatność listy „Co mam w domu”", POLITYKA, POLITYKA_PACZKA_TEST,
     lambda s: replace_once(s, "widzisz ją tylko Ty", "widzą ją Twoi obserwujący")),
    ("Godzina w widoku z pominięciem Czas", WIDOK_POTWIERDZENIA, STREFA_STRAZNIK_TEST,
     lambda s: replace_once(s, "{{ \\App\\Support\\Czas::lokalnie($nieudanaWysylka->failed_at)->format('H:i') }}", "{{ $nieudanaWysylka->failed_at->format('H:i') }}")),
    ("Nieudane wgranie bez kompensacji plików", KOMPENSACJA_UPLOADU, KOMPENSACJA_UPLOADU_TEST,
     lambda s: replace_once(s, "            $this->posprzatajPoNieudanymZapisie($disk, $objectKey, $dyskWariantow);\n", "")),
    ("Decyzja moderacyjna tworzona poza listą", POWIADOM_O_DECYZJI, DECYZJA_Z_CZLOWIEKIEM_TEST,
     lambda s: replace_once(s, "final class NotifyModerationDecision\n{\n", "final class NotifyModerationDecision\n{\n    // ModerationAction::create( — mutacja kontroli dodatniej\n")),
    ("Polityka bez nazwy ciasteczka motywu", POLITYKA, POLITYKA_CIASTECZKA_TEST,
     lambda s: replace_once(s, "ciemnego motywu (`motyw`)", "ciemnego motywu")),
    ("Polityka wiąże dziennik z instancją", POLITYKA, POLITYKA_DZIENNIK_TEST,
     lambda s: replace_once(s, POLITYKA_DZIENNIK_ZDANIE, "Dzienniki serwera żyją tyle, ile działająca instancja serwisu.")),
    ("Polityka bez liczby dni dziennika", POLITYKA, POLITYKA_DZIENNIK_TEST,
     lambda s: replace_once(s, POLITYKA_DZIENNIK_DNI, "")),
    ("Manifest Vite z rocznym cache assetów", CADDYFILE, CACHE_MANIFESTU_TEST,
     lambda s: replace_once(s, "@viteAssets path /build/assets/*", "@viteAssets path /build/*")),
    ("Trasa ze zdjęciem pod progiem 2 MB w Caddy", CADDYFILE, CADDY_LIMIT_TEST,
     lambda s: replace_once(s, CADDY_LIMIT_WYJATKI, CADDY_LIMIT_WYJATKI.replace("/ustawienia/zdjecie ", ""))),
    ("Rejestr wyjątków z nieistniejącą klasą", REJESTR_WYJATKOW, REJESTR_WYJATKOW_TEST,
     lambda s: replace_once(s, "'PublishComment składa", "'AddComment składa")),
    ("Rejestr wyjątków z nieistniejącą stałą", REJESTR_WYJATKOW, REJESTR_WYJATKOW_TEST,
     lambda s: replace_once(s, "dostaje STATUS_OPEN na sztywno", "dostaje STATUS_NEW na sztywno")),
    ("Zlecenie zdjęcia poza transakcją wiersza", STORE_UPLOADED_IMAGE, ZLECENIE_ZDJECIA_TEST,
     dispatch_zdjecia_poza_transakcja),
    ("Referrer-Policy w Caddy nadpisuje decyzję aplikacji", CADDYFILE, REFERRER_CADDY_TEST,
     lambda s: replace_once(s, '\t?Referrer-Policy "', '\tReferrer-Policy "')),
    ("Strażnik R2 bez segmentu eu", STRAZNIK_R2, STRAZNIK_R2_TEST,
     lambda s: replace_once(s, WZOR_R2, WZOR_R2.replace(r"\.eu\.", r"(\.[a-z]+)?\."))),
    ("Strażnik R2 bez kotwicy końca", STRAZNIK_R2, STRAZNIK_R2_TEST,
     lambda s: replace_once(s, WZOR_R2, WZOR_R2.replace("$/", "/"))),
    ("Ostrzeżenie o zmianie adresu czyta adres przy wysyłce", OSTRZEZENIE_888, OSTRZEZENIE_888_TEST,
     lambda s: replace_once(s, "Notification::route('mail', $oldAddress)->notify(new ZgloszonaZmianaAdresu(", "$user->notify(new ZgloszonaZmianaAdresu(")),
    ("Awans roli bez odwołania sesji", ZMIANA_ROLI, AWANS_ROLI_TEST,
     lambda s: replace_once(s, "            $fresh->invalidateSessions();\n", "")),
    ("Wybór kolażu bez kaskady przy odpięciu zdjęcia", MIGRACJA_HERO_PICKS, HERO_PICKS_TEST,
     lambda s: replace_once(s, "\n            .'ON DELETE CASCADE',", "")),
    ("Landing: podgląd prowadzi do trasy auth", LANDING, LANDING_PODGLAD_TEST,
     lambda s: replace_once(s, "{{ route('help') }}#kto-widzi", "{{ route('posts.create') }}")),
    ("Autozapis kreatora #892 bez kroku CI", CI_WORKFLOW, AUTOZAPIS_892_TEST,
     lambda s: replace_once(s, "          node scripts/kreator-zachowanie.mjs autosave\n", "")),
    ("Pomiar #713 paska bez kroku CI", CI_WORKFLOW, POMIARY_713_TEST,
     lambda s: replace_once(s, "        run: node scripts/pasek-uklady.mjs\n", "        run: 'true'\n")),
    ("Stały token wydania Livewire", LIVEWIRE_KONFIG, LIVEWIRE_TOKEN_TEST,
     lambda s: replace_once(s, "'release_token' => strtolower(trim((string) env('RAILWAY_GIT_COMMIT_SHA'))) ?: 'lokalnie',", "'release_token' => 'a',")),
    ("Kreator obiecuje szkic przed zapisem", KREATOR_WIDOK, KREATOR_ZAPIS_TEST,
     lambda s: replace_once(s, KREATOR_ZAPIS, "$juzOpublikowany ? 'opublikowany' : 'szkic'")),
    ("Kreator pozwala klientowi podmienić recipeId", KREATOR_WIDOK, KREATOR_AUTORYZACJA_TEST,
     lambda s: replace_once(s, "    #[Locked]\n    public ?string $recipeId = null;", "    public ?string $recipeId = null;")),
    # #1387, krok 9: strona nieaktualna. Rewizja treści z bazy jedzie do
    # `PublishRecipe`; mutacja gubi ją i druga karta nadpisuje pierwszą.
    ("Kreator nie wysyła oczekiwanej rewizji treści", KREATOR_REWIZJA, KREATOR_STRONA_NIEAKTUALNA_TEST,
     lambda s: replace_once(s, "return $recipeId === null ? null : $rewizjaTresci;", "return null;")),
    # #1387, krok 9: walidacja SUROWYCH pól przed zapisem szkicu.
    ("Autozapis kreatora zapisuje bez walidacji pól", KREATOR_WIDOK, KREATOR_WALIDACJA_PRZED_ZAPISEM_TEST,
     lambda s: replace_once(s, "if (! $aboutValid || ! $rowsValid) {", "if (false) {")),
    # #1387, krok 9: błąd „przepis zniknął” stoi na podglądzie; mutacja
    # przywraca stałe „krok 3” z `publish()`.
    ("Publikacja cofa z podglądu przy błędzie przepisu", KREATOR_WIDOK, KREATOR_BLAD_PRZEPISU_TEST,
     lambda s: replace_once(
         s,
         "$this->step = NawigacjaKreatora::krokPierwszegoBledu($this->getErrorBag()->keys());\n\n            return;\n        }\n\n        if (! $this->storePendingPhotos()) {",
         "$this->step = 3;\n\n            return;\n        }\n\n        if (! $this->storePendingPhotos()) {",
     )),
    ("Polityka obiecuje UE przy strażniku bez eu", STRAZNIK_R2, POLITYKA_R2_TEST,
     lambda s: replace_once(s, WZOR_R2, WZOR_R2.replace(r"\.eu\.", r"(\.[a-z]+)?\."))),
    # #619: zapis weryfikacji w dokumencie infrastruktury z inną datą niż
    # „sprawdzone <data>” w wierszu R2 polityki ma zapalić test.
    ("Zapis weryfikacji R2 z inną datą niż polityka", R2_ZAPIS_WERYFIKACJI, R2_ZAPIS_WERYFIKACJI_TEST,
     lambda s: replace_once(s, "- **2026-09-25** — właściciel potwierdził", "- **2026-09-26** — właściciel potwierdził")),
    ("Kontroler znów zleca analizę awatara", KONTROLER_AWATARA, DOKUMENTACJA_AWATARA_TEST,
     lambda s: replace_once(s, AWATAR_KOMENTARZ, AWATAR_KOMENTARZ
                            + "        \\App\\Jobs\\PrzeanalizujAwatar::dispatch((string) $zdjecie->getKey());\n")),
    ("DATABASE.md znów mówi, że model ocenia awatar", DATABASE_DOC, DOKUMENTACJA_AWATARA_TEST,
     lambda s: replace_once(s, AWATAR_DATABASE, "Dziś trafia tu wyłącznie\nzdjęcie profilowe: model ocenia je po "
                            "przetworzeniu (`PrzeanalizujAwatar`),\na oznaczenie wskazuje `media.id`.")),
    ("Indeks bazy gubi plik obszaru", "docs/DATABASE.md", INDEKS_BAZY_TEST,
     lambda s: replace_once(s, "(baza/kontakt.md)", "(baza/kontakt-stary.md)")),
    ("Link względny w docs/baza/ bez poprawki po przeniesieniu", "docs/baza/budzet-polaczen.md", INDEKS_BAZY_TEST,
     lambda s: replace_once(s, "(../infra/MONITORING_ODBIOR_2026_09_20.md)", "(infra/MONITORING_ODBIOR_2026_09_20.md)")),
    ("Wyjęcie przepisu ze wszystkich zeszytów bez transakcji", WYJECIE_PRZEPISU, WYJECIE_ATOMOWE_TEST,
     lambda s: replace_once(s, "return DB::transaction(fn (): array => $this->zdejmij($user, $recipe, $collection));",
                            "return $this->zdejmij($user, $recipe, $collection);")),
    ("Wyjęcie wpisu ze wszystkich zeszytów bez transakcji", WYJECIE_WPISU, WYJECIE_ATOMOWE_TEST,
     lambda s: replace_once(s, "return DB::transaction(fn (): array => $this->zdejmij($user, $post, $collection));",
                            "return $this->zdejmij($user, $post, $collection);")),
    ("Odwołanie autora bez wspólnej transakcji z zawiadomieniami", ODWOLANIE_AUTORA, ODWOLANIE_AUTORA_TEST,
     odwolanie_bez_transakcji("$osoba, $decyzja, $tresc")),
    ("Odwołanie zgłaszającego bez wspólnej transakcji z zawiadomieniami", ODWOLANIE_ZGLASZAJACEGO,
     ODWOLANIE_ZGLASZAJACEGO_TEST, odwolanie_bez_transakcji("$zgloszenie, $decyzja, $tresc")),
    ("Reguła zdjęć Cloudflare bez warunku ciasteczka", REGULY_CF, REGULY_CF_TEST,
     lambda s: replace_once(s, REGULA_ZDJEC_CIASTKO, REGULA_ZDJEC_CIASTKO.replace(' and http.cookie eq \\"\\"', ""))),
    ("Timeout blokady funkcji nie oddaje miejsca wspólnej puli", BUDZET_POCZTY, BUDZET_POCZTY_TEST,
     lambda s: replace_once(s, "            $zajete = false;\n", "            return false;\n")),
    ("Zapis przepisu do cudzego zeszytu", ZAPIS_PRZEPISU, ZAPIS_CUDZY_ZESZYT_TEST,
     lambda s: replace_once(s, AUTORYZACJA_ZESZYTU, "")),
    ("Zapis wpisu do cudzego zeszytu", ZAPIS_WPISU, ZAPIS_CUDZY_ZESZYT_TEST,
     lambda s: replace_once(s, AUTORYZACJA_ZESZYTU, "")),
    ("Podział testów gubi plik", PODZIAL_TESTOW, PODZIAL_TESTOW_TEST, podzial_gubi_plik),
    ("Macierz testów krótsza niż podział", BRAMKA_CI, PODZIAL_TESTOW_TEST, macierz_krotsza_niz_podzial),
    # #2299: części kontroli przestają uruchamiać regresje zawężenia `--filter`.
    ("Kontrole bez regresji zawężenia filtra (#2299)", CI_WORKFLOW, PODZIAL_TESTOW_TEST,
     lambda s: replace_once(s, "-p 'test_zawezenie_testow.py'", "-p 'test_kontrola_wyjscia_testu.py'")),
    # #2299, etap 2: panel marki w częściach, sam Vite w jobach przeglądarkowych,
    # cache `vendor/` i przeglądarki bez starych wyników.
    ("Macierz panelu krótsza niż podział (#2299)", CI_WORKFLOW, "PanelMarkiDzieliSieBezUtratyPomiaruTest",
     lambda s: replace_once(s, "        # rozjazd wyłapuje `PanelMarkiDzieliSieBezUtratyPomiaruTest`.\n        czesc: [1, 2]\n",
                            "        # rozjazd wyłapuje `PanelMarkiDzieliSieBezUtratyPomiaruTest`.\n        czesc: [1]\n")),
    ("Panel marki bez numeru części (#2299)", CI_WORKFLOW, "PanelMarkiDzieliSieBezUtratyPomiaruTest",
     lambda s: replace_once(s, "      PANEL_CZESC: ${{ matrix.czesc }}\n", "")),
    ("Job zbiorczy panelu pomijany po czerwonej części (#2299)", CI_WORKFLOW, "PanelMarkiDzieliSieBezUtratyPomiaruTest",
     lambda s: replace_once(s, "    needs: [zakres, port_panelu]\n    if: ${{ !cancelled() }}\n", "    needs: [zakres, port_panelu]\n")),
    ("Testy JS nie biegną nigdzie, bo assets też buduje sam Vite (#2299)", CI_WORKFLOW, "TestyJsBiegnaWJobieAssetowTest",
     lambda s: replace_once(s, "      - name: Build\n        run: npm run build\n", "      - name: Build\n        run: npm run build:assets\n")),
    ("Cache przeglądarki odtwarza inną wersję (#2299)", CI_WORKFLOW, "CacheMiedzyJobamiNieDajeStarychWynikowTest",
     lambda s: pierwsze_z_wielu(s, "          key: playwright-${{ runner.os }}-${{ runner.arch }}-${{ steps.playwright.outputs.wersja }}\n",
                                "          key: playwright-${{ runner.os }}-${{ runner.arch }}-${{ steps.playwright.outputs.wersja }}\n"
                                "          restore-keys: playwright-${{ runner.os }}-\n", 6)),
    ("Pomiar portu zależny od rodzaju runnera (#2299)", CI_WORKFLOW, "test_job_przegladarkowy_nie_moze_byc_zielony_bez_pomiaru",
     lambda s: pierwsze_z_wielu(s, "      - name: Port projektu — układ i kontrola ujemna\n",
                                "      - name: Port projektu — układ i kontrola ujemna\n        if: runner.environment == 'github-hosted'\n", 2)),
    ("Cache przeglądarki na własnym runnerze (#2299)", CI_WORKFLOW, "CacheMiedzyJobamiNieDajeStarychWynikowTest",
     lambda s: pierwsze_z_wielu(s, "        if: runner.environment == 'github-hosted'\n", "", 6)),
    ("Wyścigi dwóch połączeń znów nie blokują CI", CI_WORKFLOW, WYSCIGI_BLOKUJA_TEST,
     lambda s: replace_once(s, "    name: Wyścigi na dwóch połączeniach\n", "    name: Wyścigi na dwóch połączeniach\n    continue-on-error: true\n")),
    # #2215: audyt zależności blokuje; flaga wracająca na job albo skrypt bramki
    # zastąpiony pustym poleceniem zostawiłyby „zielony CI" mimo high/critical.
    ("Audyt zależności znów nie blokuje CI", CI_WORKFLOW, AUDYT_BLOKUJE_TEST,
     lambda s: replace_once(s, "    name: Audyt zależności (blokuje high i critical)\n", "    name: Audyt zależności (blokuje high i critical)\n    continue-on-error: true\n")),
    ("Audyt zależności bez skryptu bramki", CI_WORKFLOW, AUDYT_BLOKUJE_TEST,
     lambda s: replace_once(s, "python3 scripts/audyt-zaleznosci.py", "true scripts/audyt-zaleznosci.py")),
    ("Nowe continue-on-error w jobie testów", CI_WORKFLOW, AUDYT_BLOKUJE_TEST,
     lambda s: replace_once(s, "  testy:\n    name: Testy (PostgreSQL 18)\n", "  testy:\n    name: Testy (PostgreSQL 18)\n    continue-on-error: true\n")),
    # #2299: `czesc: [1, 2]` ma też macierz panelu marki — kotwica z komentarzem portu.
    ("Macierz portu krótsza niż podział grup", CI_WORKFLOW, ROZSZERZENIA_CZESCI_TEST,
     lambda s: replace_once(s, "        # `PortMarkiMaWlasnaBramkeCiTest`.\n        czesc: [1, 2]\n",
                            "        # `PortMarkiMaWlasnaBramkeCiTest`.\n        czesc: [1]\n")),
    ("Minutnik poza macierzą portu", CI_WORKFLOW, ROZSZERZENIA_CZESCI_TEST,
     lambda s: replace_once(s, "Minutnik — opóźnione wywołania i dostępny czas\n        if: matrix.czesc == 2\n", "Minutnik — opóźnione wywołania i dostępny czas\n        if: matrix.czesc == 3\n")),
    ("Runbook znów instaluje Sentry", RUNBOOK, RUNBOOK_USLUGI_TEST,
     lambda s: replace_once(s, RUNBOOK_KROK_4, RUNBOOK_KROK_4 + "\n```bash\ncomposer require sentry/sentry-laravel\n```\n")),
    ("Runbook znów wymaga klucza PostHog", RUNBOOK, RUNBOOK_USLUGI_TEST,
     lambda s: replace_once(s, RUNBOOK_WIERSZ_WEBHOOKA, "| `POSTHOG_KEY` | z kroku 5 | nie | Project API Key PostHog |\n" + RUNBOOK_WIERSZ_WEBHOOKA)),
    ("Jeden worker ze ścisłym priorytetem kolejek", ENTRYPOINT, KOLEJKI_BEZ_GLODZENIA_TEST,
     lambda s: replace_once(s, 'local osobne="high default media low"', 'local osobne="high,default,media,low"')),
    ("Rola all z procesem na kolejkę (OOM w 1024 MB)", ENTRYPOINT, UMOWA_KOLEJKI_TEST,
     lambda s: replace_once(s, 'local wspolnyKontener="high,default media,low"', 'local wspolnyKontener="high default media low"')),
    ("Tryb ścisły Eloquent niewłączony", TRYB_SCISLY, TRYB_SCISLY_TEST,
     lambda s: replace_once(s, "        Model::shouldBeStrict($this->app->environment('local', 'testing') || $staging);\n", "")),
    ("Awaria eksportu bez przekazania wyjątku kolejce", EKSPORT_JOB, EKSPORT_PORAZKA_TEST,
     lambda s: replace_once(s, EKSPORT_RETHROW, EKSPORT_BEZ_RETHROW)),
    # #1750: klucz paczki RODO wraca do formy żeńskiej sprzed poprawki.
    ("Klucz eksportu z rodzajem", EKSPORT_DANE, EKSPORT_KLUCZE_TEST,
     lambda s: replace_once(s, "'na_czym_sie_znam' =>", "'w_czym_jestem_dobra' =>")),
    ("Eksport gubi wybór widoczności wartości odżywczych", EKSPORT_DANE, EKSPORT_WIDOCZNOSC_TEST,
     lambda s: replace_once(s, EKSPORT_WIDOCZNOSC_POLE, "")),
    # #1752 (D-332): rollback formy zwracania się bez strażnika D-088
    # i anonimizacja konta, która zostawia wybraną formę.
    ("Cofnięcie formy zwracania się bez odmowy", FORMA_MIGRACJA, FORMA_TEST,
     lambda s: replace_once(s, "        if ($zWyborem > 0) {\n", "        if (false) {\n")),
    ("Anonimizacja zostawia formę zwracania się", FORMA_WYMAZANIE, FORMA_TEST,
     lambda s: replace_once(s, "                    'form_of_address' => null,\n", "")),
    # #1753 (D-332): helper ignoruje formę żeńską; wariant neutralny z rodzajem;
    # goły rodzaj OBOK helpera w tej samej linii (wycinamy tylko argumenty).
    ("Helper formy ignoruje formę żeńską", FORMA_HELPER, FORMA_TEKSTY_TEST,
     lambda s: replace_once(s, "Profile::FORM_FEMININE => $zenska,", "Profile::FORM_FEMININE => $neutralna,")),
    ("Wariant neutralny helpera z rodzajem", FORMA_KONIEC_ONBOARDINGU, FORMA_TEKSTY_TEST,
     lambda s: replace_once(s, "'ugotowałaś', 'ugotowałeś', 'gotujesz') }} —", "'ugotowałaś', 'ugotowałeś', 'ugotowałeś') }} —")),
    ("Goły rodzaj obok wywołania helpera", FORMA_ODKRYWANIE, TEKSTY_BEZ_PLCI_TEST,
     lambda s: replace_once(s, "}}. Nie musi być ładne", "}} ugotowałaś. Nie musi być ładne")),

    # „jesteś zalogowany” wraca na ekran łączenia konta Google — wzorzec
    # `jestem_przymiotnik` w `WzorceRodzaju` ma to złapać.
    ("Rodzaj po „jesteś” na ekranie Google", GOOGLE_LINK_WIDOK, GOOGLE_LINK_TEST,
     lambda s: replace_once(s, "jakie konto Google jest zalogowane", "jakim kontem Google jesteś zalogowany")),
    ("Entrypoint bez klucza preview", ENTRYPOINT, KLUCZ_PREVIEW_TEST,
     lambda s: replace_once(s, '[[ -z "${APP_KEY:-}" ]] && kuking_klucz_preview; then', '[[ -z "${APP_KEY:-}" ]] && false; then')),
    ("Dalsze okno wyszukiwania bez kursora rankingu", SZUKAJ_KONTROLER, STABILNE_OKNA_TEST,
     bez_kursora_wyszukiwania),
    ("Nieudany dzwonek kupuje ciszę epizodu", EPIZOD_ALARMU, EPIZOD_ALARMU_TEST,
     lambda s: replace_once(s, CISZA_TYLKO_PO_PRZYJECIU, CISZA_BEZ_WARUNKU)),
    ("Podzbiór fontu bez „ą\"", FONTY_CSS, FONTY_TEST,
     lambda s: replace_once(s, "unicode-range: U+0100-017F;", "unicode-range: U+0100-0103, U+0106-017F;")),
    ("Wygenerowany font bez glifu „ą\"", FONTY_RAPORT, FONTY_TEST,
     lambda s: replace_once(s, '"glify": "U+0100-0130,', '"glify": "U+0106-0130,')),
    ("Viewport bez viewport-fit=cover", LAYOUT, BEZPIECZNY_OBSZAR_TEST,
     lambda s: replace_once(s, ", viewport-fit=cover", "")),
    ("Dolna belka bez lewego insetu", MARKA_RAMA_CSS, BEZPIECZNY_OBSZAR_TEST,
     lambda s: replace_once(s, "left: calc(8px + var(--safe-left));", "left: 8px;")),
    ("Podpowiedź wyglądu bez dolnego insetu", SZYBKI_WYGLAD_CSS, BEZPIECZNY_OBSZAR_TEST,
     lambda s: replace_once(s, "+ var(--safe-bottom) + 76px)", "+ 76px)")),
    ("Edycja domyślnego zeszytu bez skutku dla przyszłych zapisów", EDYCJA_ZESZYTU, DOMYSLNY_ZESZYT_TEST,
     lambda s: replace_once(s, " i wszystko, co zapiszesz tu później", "")),
    ("Wydruk przepisu z pismem poniżej 12 pt", WYDRUK_CSS, WYDRUK_TEST,
     lambda s: replace_once(s, "font-size: calc(13pt * var(--druk-skala));", "font-size: calc(10pt * var(--druk-skala));")),
    ("Główny link wydruku gubi wybrane porcje (#2474)", DRUK_PORCJE_WIDOK, DRUK_PORCJE_TEST,
     lambda s: replace_once(s, 'href="{{ $adresDruku(false) }}" rel="nofollow" data-drukuj-przepis',
                            'href="{{ route(\'recipes.show\', [\'recipe\' => $recipe->slug, \'druk\' => 1]) }}#jak-wydrukowac" rel="nofollow" data-drukuj-przepis')),
    ("Strona przepisu gubi czas kroku (#2484)", DRUK_PORCJE_WIDOK, CZAS_KROKU_STRONA_TEST,
     lambda s: replace_once(s, '                                    @if($step->timerLabel())\n                                        <p class="m-0">Czas kroku: {{ $step->timerLabel() }}</p>\n                                    @endif\n', '')),
    ("Wydruk zeszytu gubi czas kroku (#2484)", CZAS_KROKU_WIDOK_ZESZYTU, CZAS_KROKU_ZESZYT_TEST,
     lambda s: replace_once(s, '                                    @if($krok->timerLabel())\n                                        <p class="m-0">Czas kroku: {{ $krok->timerLabel() }}</p>\n                                    @endif\n', '')),
    ("Ściągawka do wydruku z pismem poniżej 16 pt (F4)", WYDRUK_CSS, SCIAGAWKA_TEST,
     lambda s: replace_once(s, "body:has(.sciagawka) .sciagawka * {\n    font-size: max(calc(16pt * var(--druk-skala)), 1em);", "body:has(.sciagawka) .sciagawka * {\n    font-size: max(calc(11pt * var(--druk-skala)), 1em);")),
    ("Wydruk dla pomocnika z pismem poniżej 16 pt (#2345)", WYDRUK_CSS, POMOCNIK_TEST,
     lambda s: replace_once(s, "body:has(.dla-pomocnika) .przepis-uklad.dla-pomocnika * {\n    font-size: max(calc(16pt * var(--druk-skala)), 1em);", "body:has(.dla-pomocnika) .przepis-uklad.dla-pomocnika * {\n    font-size: max(calc(11pt * var(--druk-skala)), 1em);")),
    ("Wydruk z widocznym objaśnieniem Zgłoś dla gościa (#2345)", WYDRUK_CSS, ZGLOS_GOSCIA_TEST,
     lambda s: replace_once(s, ".druk-podpowiedz, .zglos-goscia,", ".druk-podpowiedz,")),
    ("Wydruk dla pomocnika z kodem QR mniejszym niż 3 cm (#2345)", WYDRUK_CSS, POMOCNIK_TEST,
     lambda s: replace_once(s, ".druk-pomocnik-qr-kod {\n    width: 4cm;", ".druk-pomocnik-qr-kod {\n    width: 2cm;")),
    ("Karta QR z kodem mniejszym niż 9 cm (#2349)", WYDRUK_CSS, KARTA_QR_TEST,
     lambda s: replace_once(s, "    width: 9cm;\n    margin: 12pt 0;", "    width: 4cm;\n    margin: 12pt 0;")),
    ("Ściągawka bez wspólnej ramy druku (F4)", WYDRUK_CSS, SCIAGAWKA_TEST,
     lambda s: replace_once(s, "body:has(.przepis-uklad, .sciagawka, .zeszyt-druk) main :is(.btn, button, form)", "body:has(.przepis-uklad) main :is(.btn, button, form)")),
    ("Offline: „Spróbuj ponownie” znów prowadzi na /home (#749)", OFFLINE_HTML, OFFLINE_PONOWIENIE_TEST,
     lambda s: replace_once(s, '<a href="">Spróbuj ponownie</a>', '<a href="/home">Spróbuj ponownie</a>')),
    ("Kontrakt karty bez zdjęcia przepisu", KONTRAKT_KARTY, KONTRAKT_KARTY_TEST,
     lambda s: replace_once(s, "        'recipe.heroMedia',\n    ];", "    ];")),
    ("Kontrakt karty bez tematów", KONTRAKT_KARTY, KONTRAKT_KARTY_TEST,
     lambda s: replace_once(s, "        'tags:id,slug,name,status',\n    ];", "    ];")),
    ("Zamknięcie grupy sygnałów bez porównania liczby", GRUPA_SYGNALOW, GRUPA_LICZBA_TEST,
     lambda s: replace_once(s, " || $oznaczenia->count() > $stanIle) {", ") {")),
    ("Zamknięcie grupy sygnałów bez porównania kolejności", GRUPA_SYGNALOW, GRUPA_KOLEJNOSC_TEST,
     lambda s: replace_once(s, "return $oznaczenia->contains(", "return false && $oznaczenia->contains(")),
    ("Migracja pierwszych kroków bez backfillu", MIGRACJA_ONBOARDINGU, MIGRACJA_ONBOARDINGU_TEST,
     lambda s: replace_once(s, "        DB::table('users')->update(['onboarding_zakonczony_at' => DB::raw('created_at')]);\n", "")),
    ("Koniec pierwszych kroków zapisywany w GET", ONBOARDING_KONTROLER, ONBOARDING_WZNOWIENIE_TEST,
     lambda s: replace_once(s, "        $request->session()->forget('onboarding.selection');\n\n        // „Ugotowałem” PRZED obserwowaniem", "        $request->session()->forget('onboarding.selection');\n        $this->oznaczZakonczony($request);\n\n        // „Ugotowałem” PRZED obserwowaniem")),
    ("Turnstile bez porównania hosta", KLIENT_TURNSTILE, TURNSTILE_HOST_TEST,
     lambda s: replace_once(s, "! in_array(strtolower($host), $dozwolone, true) => 'host_spoza_listy',\n", "")),
    ("Turnstile bez porównania akcji", KLIENT_TURNSTILE, TURNSTILE_AKCJA_TEST,
     lambda s: replace_once(s, "! hash_equals($akcja, $akcjaZOdpowiedzi) => 'inna_akcja',\n", "")),
    ("Kolaż hero z lazy na pierwszym kaflu", LANDING, KOLAZ_LCP_TEST,
     lambda s: replace_once(s, KOLAZ_PRIORYTET, '                                 loading="lazy"\n')),
    ("Polityka z innym terminem usunięcia treści niż konfiguracja", POLITYKA, TWARDE_USUNIECIE_TEST,
     lambda s: replace_once(s, "najpóźniej **30 dni** po usunięciu", "najpóźniej **60 dni** po usunięciu")),
    ("Users znowu importuje Social", ZALOZ_KONTO, GRAF_MODULOW_TEST,
     lambda s: replace_once(s, "use App\\Domain\\Users\\ObserwowanieGospodarza;\n", "use App\\Domain\\Social\\Actions\\FollowUser;\nuse App\\Domain\\Users\\ObserwowanieGospodarza;\n")),
    ("Analytics znowu importuje Compliance", PRZEDAWNIONE_SYGNALY, GRAF_MODULOW_TEST,
     lambda s: replace_once(s, "use App\\Support\\UsuwanieWPartiach;\n", "use App\\Domain\\Compliance\\UsuwanieWPartiach;\n")),
    ("Media znowu importuje Moderation", DOSTEP_DO_ZDJECIA, GRAF_MODULOW_TEST,
     lambda s: replace_once(s, "use App\\Models\\CookedEvent;\n", "use App\\Domain\\Moderation\\ModeratedContent;\nuse App\\Models\\CookedEvent;\n")),
    ("Compliance znowu importuje Moderation", PRZEDAWNIONE_SPRAWY, GRAF_MODULOW_TEST,
     lambda s: replace_once(s, "use App\\Logging\\BezpiecznyBlad;\n", "use App\\Domain\\Moderation\\KolejkiPanelu;\nuse App\\Logging\\BezpiecznyBlad;\n")),
    ("Moderation znowu importuje Security", ALARMUJ_MODERATORA, GRAF_MODULOW_TEST,
     lambda s: replace_once(s, "use App\\Domain\\Moderation\\Sygnaly\\Sygnal;\n", "use App\\Domain\\Moderation\\Sygnaly\\Sygnal;\nuse App\\Domain\\Security\\DziennyBudzetListow as BudzetZSecurity;\n")),
    ("Nowy cykl: Users importuje Moderation (Moderation → Users już jest)", ERASE_ACCOUNT_DATA, GRAF_MODULOW_TEST,
     lambda s: replace_once(s, "namespace App\\Domain\\Users\\Actions;\n", "namespace App\\Domain\\Users\\Actions;\n\nuse App\\Domain\\Moderation\\ModeratedContent;\n")),
    ("Domena importuje Illuminate\\Http\\Request", ZESZYTY_AKCJA_NOTATKI, ZESZYTY_HTTP_TEST,
     lambda s: replace_once(s, "use Illuminate\\Support\\Facades\\DB;\n", "use Illuminate\\Http\\Request;\nuse Illuminate\\Support\\Facades\\DB;\n")),
    ("Formularz zeszytu sprawdza pola przed Policy", ZAPIS_ZESZYTU_REQUEST, ZESZYTY_FORMULARZ_TEST,
     lambda s: replace_once(s, "            Gate::inspect('update', $zeszyt)->authorize();\n", "")),
    ("DemoSeeder wypisuje hasło z KUKING_DEMO_HASLO", DEMO_SEEDER, DEMO_SEEDER_HASLO_TEST,
     lambda s: replace_once(s, WARUNEK_HASLA_Z_OTOCZENIA, "        if (false) {")),
    ("Polityka z okresem sesji innym niż życie sesji na produkcji", POLITYKA, POLITYKA_SESJE_TEST,
     lambda s: replace_once(s, "Do **30 dni** od ostatniej aktywności", "Do **7 dni** od ostatniej aktywności")),
    ("Sprzątanie audytu zostawia skrót IP we wpisach dowodowych", PRZEDAWNIONE_WPISY_AUDYTU, POLITYKA_SESJE_TEST,
     lambda s: replace_once(s, "$zeSkrotem->update(['ip_hash' => null])", "0")),
    ("Kontroler Google z własną kopią wejścia na konto", KONTROLER_GOOGLE, ADAPTERY_DOSTAWCOW_TEST,
     lambda s: replace_once(s, WPUSC_GOOGLE, "        \\Illuminate\\Support\\Facades\\Auth::login($user, remember: true);\n\n" + WPUSC_GOOGLE)),
    ("Instalacja Railway CLI bez sprawdzenia sumy kontrolnej", RAILWAY_CLI_WORKFLOW, RAILWAY_CLI_TEST,
     railway_cli_bez_przypietej_wersji),
    ("Operacja na produkcji bierze token staginu, gdy brak sekretu produkcji", WDROZENIE_WORKFLOW, TOKEN_RAILWAY_TEST,
     lambda s: replace_once(s, TOKEN_RAILWAY_NOWY, TOKEN_RAILWAY_STARY)),
    ("Bramka tokenu krawędzi przepuszcza żądanie bez tokenu", BRAMKA_KRAWEDZI, BRAMKA_KRAWEDZI_TEST,
     lambda s: replace_once(s, BRAMKA_KRAWEDZI_WARUNEK, BRAMKA_KRAWEDZI_WARUNEK.replace("if ($egzekwowanie) {", "if (false) {"))),
    ("Caddy czyta X-Forwarded-For od lewej", CADDYFILE, CADDY_ZAUFANIE_TEST,
     lambda s: replace_once(s, "\t\ttrusted_proxies_strict\n", "")),
    ("Caddy ufa każdemu peerowi", CADDYFILE, CADDY_ZAUFANIE_TEST,
     lambda s: replace_once(s, "trusted_proxies static private_ranges", "trusted_proxies static 0.0.0.0/0 ::/0")),
    ("Produkcja dostaje domenę dostawcy obok kuking.pl", DOMENY_ORIGINU_IAC, DOMENY_ORIGINU_TEST,
     lambda s: replace_once(s, DOMENY_ORIGINU_WPIS, '  { domain: "www.kuking.pl", port: APP_PORT },\n  { domain: "kuking-prod.up.railway.app", port: APP_PORT },\n];')),
    # Audyt B10-03: start kontenera nie czyści tabeli `cache` (RateLimiter,
    # sufit listów D-076). Mutacja przywraca stare `cache:clear`.
    ("Entrypoint czyści cache aplikacji", "docker/entrypoint.sh", "StartKonteneraNieCzysciCacheTest",
     lambda s: replace_once(s, "php /app/artisan event:clear  --no-interaction >/dev/null\n", "php /app/artisan event:clear  --no-interaction >/dev/null\nphp /app/artisan cache:clear --no-interaction >/dev/null 2>&1 || true\n")),
    # Issue #1932 (audyt 28.09.2026): numer wdrożenia zapisuje nowy kontener
    # PO gotowości, nie pre-deploy przed seedem i healthcheckiem.
    ("Rejestracja wdrożenia wraca do preDeployCommand", ".railway/railway.ts", "RejestracjaWdrozeniaPoGotowosciTest",
     lambda s: replace_once(s, '        "php artisan db:seed --force --no-interaction",\n', '        "php artisan kuking:zarejestruj-wdrozenie --no-interaction",\n        "php artisan db:seed --force --no-interaction",\n')),
    ("Entrypoint rejestruje wdrożenie bez czekania na /health", "docker/entrypoint.sh", "RejestracjaWdrozeniaPoGotowosciTest",
     lambda s: replace_once(s, "kuking:zarejestruj-wdrozenie --po-gotowosci --no-interaction", "kuking:zarejestruj-wdrozenie --no-interaction")),
    # Audyt B8-02: list z rezerwacją niesie znacznik, a rejestr klas jest
    # zamknięty w obie strony. Zgubiony znacznik = list policzony dwa razy.
    ("Alarm automatu bez znacznika rezerwacji", "app/Notifications/PilnyAlarmModeracyjny.php", "KazdyListLiczySieWPuliTest",
     lambda s: replace_once(s, "ListZarezerwowany::oznacz(new MailMessage)", "(new MailMessage)")),
    ("Życzenia urodzinowe bez znacznika rezerwacji", "app/Mail/ZyczeniaUrodzinowe.php", "KazdyListLiczySieWPuliTest",
     lambda s: replace_once(s, "            ...ListZarezerwowany::naglowekTekstowy(),\n", "")),
    ("List alarmowy bez znacznika rezerwacji", "app/Mail/AlarmOperacyjny.php", "KazdyListLiczySieWPuliTest",
     lambda s: replace_once(s, "new Headers(text: ListZarezerwowany::naglowekTekstowy())", "new Headers")),
    # #599: poczta jako drugi kanał alarmowy obok Discorda.
    ("Poczta wypada z kanałów alarmowych", "app/Logging/KanalyAlarmowe.php", "KanalAlarmowyMailemTest",
     lambda s: replace_once(s, "        self::POCZTA => 'logging.channels.blad_email.adres',\n", "")),
    ("Awaria poczty przewraca raport i zabiera Discord", "app/Logging/EmailBleduHandler.php", "KanalAlarmowyMailemTest",
     lambda s: replace_once(s, "            $this->zapiszNiedodzwonienie($e::class);\n", "            $this->zapiszNiedodzwonienie($e::class);\n\n            throw $e;\n")),
    ("List alarmowy bez dobowego sufitu", "app/Logging/EmailBleduHandler.php", "KanalAlarmowyMailemTest",
     lambda s: replace_once(s, "            if (! $budzet->sprobujZarezerwowac()) {", "            if (! $budzet->sprobujZarezerwowac() && false) {")),
    ("List alarmowy niesie komunikat wyjątku", "app/Logging/EmailBleduHandler.php", "KanalAlarmowyMailemTest",
     lambda s: replace_once(s, "            $tresc = WebhookBleduHandler::tresc($record);", "            $tresc = WebhookBleduHandler::tresc($record).(($record->context['exception'] ?? null) instanceof Throwable ? $record->context['exception']->getMessage() : '');")),
    ("Trasa API bez wiersza w dokumentacji", DOKUMENTACJA_API, DOKUMENTACJA_API_TEST,
     lambda s: replace_once(s, WIERSZ_FEEDU, "")),
    ("Powiadomienie o wykonaniu kucharza w karencji usunięcia", WIDOCZNOSC_TRESCI_SQL, POWIADOMIENIA_ZGODNE_Z_POLICY_TEST,
     lambda s: replace_once(s, KUCHARZ_W_KARENCJI, "")),
    ("Powiadomienie o komentarzu pod zapowiedzią ukrytego przepisu", WIDOCZNOSC_TRESCI_SQL, POWIADOMIENIA_ZGODNE_Z_POLICY_TEST,
     lambda s: replace_once(s, BRAMKA_ZAPOWIEDZI, "")),
    ("Tygodniowy list układa wpisy po liczbie „Ugotowałem”", DIGEST_DOBOR, DIGEST_DOBOR_TEST,
     lambda s: replace_once(s, "            ->orderByDesc('published_at')\n", "            ->orderByDesc('cooked_events_count')\n")),
    ("Mój stół układa przepisy po liczbie „Ugotowałem”", MOJ_STOL, DIGEST_DOBOR_TEST,
     lambda s: replace_once(s, "            ->orderByDesc('posts.published_at')\n", "            ->orderByDesc('cooked_events_count')\n")),
    ("Mój stół układa „kuKINGi na dziś” po liczbie „Ugotowałem”", MOJ_STOL, DIGEST_DOBOR_TEST,
     lambda s: replace_once(s, "            ->orderBy('daily_picks.position')\n", "            ->orderByDesc('cooked_events_count')\n")),
    ("Moderacja czyta prywatne ukrycia widzów", UKRYCIA_BEZ_AGREGACJI, UKRYCIA_BEZ_AGREGACJI_TEST,
     lambda s: replace_once(s, "use App\\Models\\Comment;\n", "use App\\Models\\Comment;\nuse App\\Models\\Hide;\n")),
    ("Metryki doboru czytają reakcje „Smakowicie wygląda”", METRYKI_BEZ_REAKCJI, METRYKI_BEZ_REAKCJI_TEST,
     lambda s: replace_once(s, "use App\\Models\\Comment;\n", "use App\\Models\\Comment;\nuse App\\Models\\PostReaction;\n")),
    ("Strona doboru opisuje rotację, której kod nie robi", DOBOR_ROTACJA, DOBOR_STRONA_TEST,
     lambda s: replace_once(s, "PARTITION BY posts.author_id ORDER BY posts.published_at DESC", "PARTITION BY posts.author_id ORDER BY posts.id DESC, posts.published_at DESC")),
    ("Rollback paska polityki bez odmowy", POLITYKA_PASEK_MIGRACJA, POLITYKA_PASEK_TEST,
     lambda s: replace_once(s, "if ($ile > 0) {", "if (false) {")),
    # Decyzja właściciela z 29.09.2026 (wieczór): wersja 2026-09-30 jest drobna.
    # Wpis „Co się zmieniło” ma mówić o wejściu w życie to samo co konfiguracja.
    ("Polityka z terminem wejścia niezgodnym z konfiguracją", POLITYKA_TEKST, POLITYKA_PASEK_TEST,
     lambda s: replace_once(s, "umowy. Ta poprawka obowiązuje od dnia publikacji.",
                            "umowy. To zmiana istotna: nowa wersja obowiązuje od 14 października 2026. "
                            "Do tego dnia obowiązuje poprzednia wersja z 29 września 2026.")),
    ("Pasek polityki przy drobnej zmianie", POLITYKA_PASEK_KLASA, POLITYKA_PASEK_TEST,
     lambda s: replace_once(s, "        if (! $this->dokument()->istotna) {\n            return false;\n        }\n\n", "")),
    # Mechanizm na przyszłe zmiany istotne: test ustawia istotną w konfiguracji.
    ("Wybór formy widoczny w okresie przejściowym polityki", FORMA_HELPER, FORMA_TEST,
     lambda s: replace_once(s, "return ! WersjaDokumentu::polityka()->wOkresiePrzejsciowym();", "return true;")),
    ("Wybór formy czeka na dzień wersji przy drobnej zmianie", FORMA_HELPER, "test_przy_drobnej_zmianie_polityki_wybor_jest_od_razu",
     lambda s: replace_once(s, "return ! WersjaDokumentu::polityka()->wOkresiePrzejsciowym();",
                            "return now()->greaterThanOrEqualTo(WersjaDokumentu::polityka()->obowiazujeOd());")),
    # #2000, decyzja właściciela z 29.09.2026: wspólny zeszyt „wszyscy” ma przycisk.
    ("Wspólny publiczny zeszyt bez „Podziel się”", UDOSTEPNIANIE, PODZIEL_SIE_ZESZYT_WSPOLNY_TEST,
     lambda s: replace_once(s, "return ! $tresc->is_default;", "return ! $tresc->is_default && ! $tresc->members()->exists();")),
    ("Wersja regulaminu podbita bez nagłówka dokumentu", REGULAMIN_WERSJA, REGULAMIN_WERSJA_TEST,
     lambda s: replace_once(s, "'wersja_regulaminu' => '2026-09-30'", "'wersja_regulaminu' => '2026-10-01'")),
    # #2220: regulamin §13/§14 podaje liczby z konfiguracji — mutacja zmienia
    # konfigurację, dokument zostaje, strażnik ma oblać.
    ("Termin odpowiedzi na reklamację inny niż w regulaminie", REGULAMIN_WERSJA, REGULAMIN_WYMAGANIA_TEST,
     lambda s: replace_once(s, "'termin_odpowiedzi_dni' => 14,", "'termin_odpowiedzi_dni' => 7,")),
    ("Limit zdjęć we wpisie inny niż w regulaminie", REGULAMIN_WERSJA, REGULAMIN_WYMAGANIA_TEST,
     lambda s: replace_once(s, "'max_per_post' => 6,", "'max_per_post' => 5,")),
    ("Regulamin poprawiony bez kopii w archiwum", REGULAMIN_TEKST, REGULAMIN_ARCHIWUM_TEST,
     lambda s: replace_once(s, "## W skrócie\n", "## W skrócie (poprawione)\n")),
    ("Nowa data regulaminu bez pliku w archiwum", REGULAMIN_WERSJA, REGULAMIN_ARCHIWUM_TEST,
     lambda s: replace_once(s, "'wersja_regulaminu' => '2026-09-30'", "'wersja_regulaminu' => '2026-10-02'")),
    ("Plik archiwum regulaminu z inną datą w nagłówku", REGULAMIN_ARCHIWUM_NAJSTARSZA, REGULAMIN_ARCHIWUM_NAGLOWEK_TEST,
     lambda s: replace_once(s, "stan serwisu na 7 września 2026", "stan serwisu na 8 września 2026")),
    ("Polityka poprawiona bez kopii w archiwum", POLITYKA_TEKST, POLITYKA_ARCHIWUM_TEST,
     lambda s: replace_once(s, "## W skrócie\n", "## W skrócie (poprawione)\n")),
    ("Nowa data polityki bez pliku w archiwum", REGULAMIN_WERSJA, POLITYKA_ARCHIWUM_TEST,
     lambda s: replace_once(s, "'wersja_polityki' => '2026-09-30'", "'wersja_polityki' => '2026-10-02'")),
    ("Plik archiwum polityki z inną datą w nagłówku", POLITYKA_ARCHIWUM_NAJSTARSZA, POLITYKA_ARCHIWUM_NAGLOWEK_TEST,
     lambda s: replace_once(s, "stan serwisu na 25 września 2026", "stan serwisu na 26 września 2026")),
    ("Starsza wersja polityki bez strony o wydaniu na prośbę", POLITYKA_ARCHIWUM_KLASA, POLITYKA_ARCHIWUM_PROSBA_TEST,
     lambda s: replace_once(s, "        return $this->starszeNaProsbe\n", "        return false\n            && $this->starszeNaProsbe\n")),
    ("Zmiana istotna bez okresu przejściowego", WERSJA_DOKUMENTU, WERSJA_DOKUMENTU_TEST,
     lambda s: replace_once(s, "        return ($chwila ?? now())->lessThan($this->obowiazujeOd());\n", "        return false;\n")),
    ("Zmiana istotna wchodzi w dniu publikacji zamiast po 14 dniach", WERSJA_DOKUMENTU, WERSJA_DOKUMENTU_TEST,
     lambda s: replace_once(s, "$najwczesniej = $publikacja->addDays(self::okresIstotnejZmianyDni());", "$najwczesniej = $publikacja;")),
    ("Zgoda zapisuje wersję opublikowaną zamiast obowiązującej", WERSJA_DOKUMENTU_ZGODA, WERSJA_DOKUMENTU_TEST,
     lambda s: replace_once(s, "WersjaDokumentu::polityka()->obowiazujaca()", "WersjaDokumentu::polityka()->opublikowana")),
    # #1324: nagłówek polityki z inną datą niż `kuking.zgody.wersja_polityki`
    # ma zapalić strażnika zgodności (mutacja niezależna od bieżącej daty).
    ("Nagłówek polityki z inną datą niż dziennik zgód", POLITYKA, "WersjaPolitykiZgadzaSieZNaglowkiemTest",
     lambda s: replace_once(s, "stan serwisu na ", "stan serwisu na 1 stycznia 2000, a nie ")),
    ("IaC: plan produkcji bez filtra gałęzi docelowej", IAC_PRODUKCJA, IAC_PRODUKCJA_TEST,
     lambda s: replace_once(s, "    branches: [main]\n", "")),
    ("IaC: plan produkcji bez base.ref == main", IAC_PRODUKCJA, IAC_PRODUKCJA_TEST,
     lambda s: replace_once(s, IAC_GALAZ_W_WARUNKU, "")),
    # Mediana reakcji w panelu gospodarza rozdzielona na dania i pytania (#372).
    # Pierwsza mutacja zdejmuje warunek rodzaju — pytania wracają do mediany
    # „Wpisów”. Druga liczy pytaniom dopiski pod cudzym komentarzem jako odpowiedź.
    ("Mediana wpisów bez warunku rodzaju", UNANSWERED_CONTENT, "test_mediana_wpisow_liczy_tylko_dania_a_pytania_maja_wlasna",
     lambda s: replace_once(s, "            ->where('posts.kind', $kind)\n", "")),
    ("Mediana pytań liczy dopiski jako odpowiedź", UNANSWERED_CONTENT, "test_mediana_pytan_liczy_tylko_glowne_odpowiedzi",
     lambda s: replace_once(s, "Post::KIND_QUESTION, $this->answers()", "Post::KIND_QUESTION, $this->responses('post_id', 'posts', 'author_id')")),
    # Indeks częściowy licznika „Czeka na odpowiedź” (#372). Test pyta planistę
    # o zapytanie z prawdziwego QuestionList; predykat na daniach ma go zgasić.
    ("Indeks pytań z predykatem na daniach", "database/migrations/2026_09_28_233800_add_questions_published_index_to_posts.php",
     "test_licznik_goscia_i_zalogowanego_moze_uzyc_indeksu_pytan",
     lambda s: replace_once(s, "WHERE kind = 'question' AND deleted_at IS NULL", "WHERE kind = 'dish' AND deleted_at IS NULL")),
    # Licznik „Czeka na odpowiedź” w tle (#372, decyzja 25.09.2026): poprawka
    # widza na blokady, wspólna definicja odpowiedzi i odświeżenie po odpowiedzi.
    ("Licznik widza bez poprawki na blokady", "app/Domain/Questions/PytaniaBezOdpowiedzi.php",
     "test_blokada_zmniejsza_licznik_widza_ale_nie_goscia",
     lambda s: replace_once(s, "        if ($wBlokadzie !== []) {\n", "        if (false) {\n")),
    ("Dopisek autora liczony jako odpowiedź", "app/Domain/Questions/OdpowiedzNaPytanie.php",
     "test_komentarz_autora_pod_wlasnym_pytaniem_nie_jest_odpowiedzia",
     lambda s: replace_once(s, "\n            ->whereColumn($tabela.'.author_id', '!=', $autorPytania);", ";")),
    ("Odpowiedź nie odświeża licznika pytań", "app/Providers/AppServiceProvider.php",
     "test_nowa_odpowiedz_odswieza_licznik_bez_recznego_przeliczenia",
     lambda s: replace_once(s, "        Comment::saved($komentarz);\n", "")),
    # V2 import/OCR (D-298): zadanie odczytu zapisujące szkic jako publikację
    # ma wywrócić architektoniczny test „import nigdy nie publikuje”.
    ("Odczyt kartki publikuje przepis", "app/Jobs/OdczytajPrzepis.php", "test_import_nigdy_nie_publikuje_sprawdzone_w_kodzie",
     lambda s: replace_once(s, "publish: false,", "publish: true,")),
    # #2520: osobne okna przed płatnym żądaniem, po odpowiedzi modelu pod
    # blokadą szkicu oraz przy ponawianiu. Każde mierzy prawdziwy zapis autora.
    ("OCR ignoruje ręczną edycję przed modelem (#2520)", "app/Jobs/OdczytajPrzepis.php",
     "test_reczna_zmiana_samego_pola_przed_startem_ocr_nie_jest_nadpisywana_ani_wysylana_do_modelu",
     lambda s: replace_once(s, "        if (! $this->szkicNietkniety($szkic)) {", "        if (false) {")),
    ("OCR nadpisuje ręczną edycję po odpowiedzi (#2520)", "app/Jobs/OdczytajPrzepis.php",
     "test_reczna_zmiana_podczas_odczytu_modelu_wygrywa_z_jego_pozniejsza_odpowiedzia",
     lambda s: replace_once(s, "            if (! $this->szkicNietkniety($swiezy)) {", "            if (false) {")),
    ("OCR ponawia odczyt zmienionego szkicu (#2520)", "app/Domain/Import/ZlecImportPrzepisu.php",
     "test_ponowienie_po_samej_recznej_zmianie_pola_nie_rezerwuje_nowego_odczytu",
     lambda s: replace_once(s, " || $szkic->content_revision !== 0 ||", " ||")),
    # #28: import z adresu chodzi w zadaniu — także ono podlega zakazowi publikacji.
    ("Import z adresu publikuje przepis", "app/Jobs/ImportujPrzepisZAdresu.php", "test_import_nigdy_nie_publikuje_sprawdzone_w_kodzie",
     lambda s: replace_once(s, "use Throwable;\n", "use Throwable;\n\n// publish: true\n")),
    ("Wspólny limit ignoruje nowe próby importu", "app/Domain/Import/LimitImportowOsoby.php", "WspolnyLimitImportuTest",
     lambda s: replace_once(s, "return $proby + $odczytyBezProby;", "return $odczytyBezProby;")),
    # D-298 „maszyna stanów płatnego wywołania” (#1973, #1974, #1977, #1980).
    # Każda mutacja przywraca dokładnie okno opisane w zgłoszeniu.
    # #1973: rezerwacja poza transakcją z licznikiem prób I `failed()` bez
    # domykania księgi — obie warstwy naraz, bo każda z osobna zamyka okno.
    ("Odczyt: rezerwacja bez śladu po awarii zapisu", "app/Jobs/OdczytajPrzepis.php", "MaszynaStanowOdczytuTest::test_1973_awaria_po_rezerwacji",
     lambda s: replace_once(replace_once(replace_once(s,
        "        $rezerwacja = DB::transaction(function () use ($budzet, $zlecenie, $proba): Rezerwacja|string {",
        "        $rezerwacja = (function () use ($budzet, $zlecenie, $proba): Rezerwacja|string {"),
        "            return $wynik;\n        });",
        "            return $wynik;\n        })();"),
        "    {\n        app(RozliczenieOdczytu::class)->zamknijPorzucone($this->importId);\n",
        "    {\n")),
    ("Odczyt: porzucona rezerwacja nie wygasa", "app/Domain/Import/OdzyskanieImportow.php", "MaszynaStanowOdczytuTest::test_1973_rezerwacja_osierocona",
     lambda s: replace_once(s, "            $this->rozliczenie->zamknijPorzucone($importId, $granicaRezerwacji);\n", "")),
    ("Odczyt: rozliczenie bez strażnika stanu", "app/Domain/Import/BudzetAi.php", "MaszynaStanowOdczytuTest::test_1974",
     lambda s: replace_once(s, "            if ($zamknieta !== 1) {\n                return null;\n            }\n", "")),
    # #1977: `afterCommit()` przenosi zapis zadania za commit — czyli
    # odtwarza okno, zamiast je zamknąć (tak jak w `ZamowEksportDanych`).
    ("Odczyt: zadanie za commitem zlecenia", "app/Domain/Import/ZlecImportPrzepisu.php", "MaszynaStanowOdczytuTest::test_1977_awaria_kolejki",
     lambda s: replace_once(s, "OdczytajPrzepis::dispatch((string) $zlecenie->getKey());", "OdczytajPrzepis::dispatch((string) $zlecenie->getKey())->afterCommit();")),
    ("Odczyt: ponowienie woła model mimo zapisanej odpowiedzi", "app/Jobs/OdczytajPrzepis.php", "MaszynaStanowOdczytuTest::test_1980_ponowienie",
     lambda s: replace_once(s, "        if (is_array($zlecenie->odpowiedz_modelu)) {", "        if (false && is_array($zlecenie->odpowiedz_modelu)) {")),
    # #2213: job nie wskrzesza zlecenia domkniętego przez odzyskiwanie.
    ("Odczyt: bezwarunkowy zapis w_toku wskrzesza zlecenie", "app/Jobs/OdczytajPrzepis.php", "MaszynaStanowOdczytuTest::test_2213",
     lambda s: replace_once(s, "        if (! $this->rozpocznij($zlecenie)) {\n            return;\n        }\n",
        "        $zlecenie->forceFill(['status' => ImportPrzepisu::STATUS_W_TOKU, 'rozpoczeto_at' => $zlecenie->rozpoczeto_at ?? now()])->save();\n")),
    # D-299: wartości odżywcze tylko przy pokryciu >= 90% masy. Obniżony próg
    # ma zapalić test przepisu z 85% pokrycia.
    ("Wartości odżywcze liczone poniżej 90% pokrycia", "app/Domain/Recipes/Odzywcze/WynikWartosci.php", "KalkulatorWartosciOdzywczychTest",
     lambda s: replace_once(s, "public const PROG_POKRYCIA = 0.9;", "public const PROG_POKRYCIA = 0.8;")),
    # D-299: licencja CIQUAL (Etalab) wymaga wskazania źródła i wersji.
    ("Źródła wartości odżywczych bez identyfikatora wersji CIQUAL", "database/data/odzywcze/ZRODLA.md", "WartosciOdzywczeImportTest",
     lambda s: replace_once(s, "DOI **10.57745/RDMHWY**", "DOI (brak)")),
    ("Job plan IaC bez bramki produkcji", PLAN_IAC_WORKFLOW, PLAN_IAC_TEST,
     plan_iac_bez_bramki_produkcji),
    ("unserialize ładunku kolejki bez allowed_classes", POLECENIE_ZADANIA, UNSERIALIZE_TEST,
     lambda s: replace_once(s, UNSERIALIZE_LISTA, "")),
    ("Ślad listu odtwarza obcą klasę z failed_jobs", POLECENIE_ZADANIA, OBCE_KLASY_TEST,
     lambda s: replace_once(s, UNSERIALIZE_LISTA, "")),
    # #27 (D-310): planer pokazuje przepis, którego właściciel planu już nie
    # widzi — zawężony, usunięty albo odcięty blokadą. Plan nie jest furtką
    # do treści.
    ("Planer bez filtra widoczności przepisu", PLANER_TYGODNIA, PLANER_TEST,
     lambda s: replace_once(s, "        return Recipe::query()\n            ->widoczneDla($user)",
                            "        return Recipe::query()->when(false, fn ($q) => $q\n            ->widoczneDla($user))")),
    # Ten sam plan bez dziennego limitu pozycji — pętla dopisuje wiersze bez końca.
    ("Planer bez dziennego limitu pozycji", PLANER_DODAJ, PLANER_TEST,
     lambda s: replace_once(s, "if ($ile >= PlanerTygodnia::wpisowNaDzien()) {", "if (false) {")),
    # Wymazanie konta zostawia prywatny plan tygodnia w bazie.
    ("Wymazanie konta nie kasuje planu tygodnia", WYMAZANIE_KONTA, PLANER_TEST,
     lambda s: replace_once(s, "            $fresh->mealPlanEntries()->delete();\n", "")),
    # #27 etap 2 (D-333): lista zakupów pokazuje tytuł przepisu, którego
    # właściciel listy już nie widzi. Lista nie jest furtką do treści.
    ("Lista zakupów bez filtra widoczności przepisu", ZAKUPY_LISTA, ZAKUPY_WIDOCZNOSC_TEST,
     lambda s: replace_once(s, "$this->planer->widocznePrzepisy($user)->whereIn('recipes.id', $idPrzepisow)",
                            "Recipe::query()->whereIn('recipes.id', $idPrzepisow)")),
    # Ponowne dodanie tego samego przepisu dopisuje bez ostrzeżenia.
    ("Lista zakupów bez ostrzeżenia przy ponownym dodaniu przepisu", ZAKUPY_LISTA, ZAKUPY_OSTRZEZENIE_TEST,
     lambda s: replace_once(s, "if ($wczesniej !== null && ! $potwierdzone) {", "if (false) {")),
    # Pętla dopisuje pozycje bez końca.
    ("Lista zakupów bez limitu pozycji", ZAKUPY_LISTA, ZAKUPY_LIMIT_TEST,
     lambda s: replace_once(s, "if ($jest + $ile > self::maksPozycji()) {", "if (false) {")),
    # Cudzą pozycję da się usunąć znając jej UUID.
    ("Lista zakupów: usunięcie pozycji bez Policy", ZAKUPY_KONTROLER, ZAKUPY_POLICY_TEST,
     lambda s: replace_once(s, "        $this->authorize('delete', $pozycja);\n", "")),
    ("Lista zakupów: pojedyncze usunięcie bez pytania (#2466)", ZAKUPY_POZYCJA, ZAKUPY_POTWIERDZENIE_TEST,
     zakupy_usun_bez_pytania),
    # Wymazanie konta zostawia prywatną listę zakupów w bazie.
    ("Wymazanie konta nie kasuje listy zakupów", WYMAZANIE_KONTA, ZAKUPY_WYMAZANIE_TEST,
     lambda s: replace_once(s, "            $fresh->shoppingListItems()->delete();\n", "")),
    # Rollback kasuje listy ludzi bez pytania.
    ("Rollback listy zakupów nie odmawia przy danych", ZAKUPY_MIGRACJA, ZAKUPY_ROLLBACK_TEST,
     lambda s: replace_once(s, "        if ($ile === 0) {", "        if (true) {")),
    # #2593: „Zrobione” w Planerze — własność, kopia tygodnia i rollback.
    ("Zrobione w Planerze: policy wpuszcza cudzą osobę (#2593)", "app/Policies/MealPlanEntryPolicy.php", "PlanerZrobioneTest",
     lambda s: replace_once(s, "    public function markDone(User $user, MealPlanEntry $entry): bool\n    {\n        return $user->getKey() === $entry->user_id;", "    public function markDone(User $user, MealPlanEntry $entry): bool\n    {\n        return true;")),
    ("Zrobione w Planerze: domena nie sprawdza własności (#2593)", "app/Domain/Planer/Actions/OznaczPozycjePlanu.php", "PlanerZrobioneTest",
     lambda s: replace_once(s, "                ->where('user_id', $swiezy->getKey())\n", "")),
    ("Zrobione w Planerze: kopia tygodnia przenosi oznaczenie (#2593)", "app/Domain/Planer/Actions/SkopiujPoprzedniTydzien.php", "PlanerZrobioneTest",
     lambda s: replace_once(s, "                    'label' => $pozycja['wpis']->label,\n", "                    'label' => $pozycja['wpis']->label,\n                    'done_at' => $pozycja['wpis']->done_at,\n")),
    ("Zrobione w Planerze: rollback nie odmawia przy oznaczeniach (#2593)", "database/migrations/2026_10_02_190200_add_done_at_to_meal_plan_entries.php", "PlanerZrobioneTest",
     lambda s: replace_once(s, "        if ($oznaczone > 0) {", "        if (false) {")),
    # #2549: dopisek przy przepisie w Planerze — własność, konflikt kart, kopia tygodnia, rollback i polityka.
    ("Dopisek w Planerze: policy wpuszcza cudzą osobę (#2549)", "app/Policies/MealPlanEntryPolicy.php", "test_cudza_pozycja_nie_zmienia_sie_i_nie_ujawnia_dopisku",
     lambda s: replace_once(s, "    public function editNote(User $user, MealPlanEntry $entry): bool\n    {\n        return $user->getKey() === $entry->user_id;", "    public function editNote(User $user, MealPlanEntry $entry): bool\n    {\n        return true;")),
    ("Dopisek w Planerze: domena nie sprawdza własności (#2549)", "app/Domain/Planer/Actions/ZapiszDopisekPlanu.php", "test_cudza_pozycja_nie_zmienia_sie_i_nie_ujawnia_dopisku",
     lambda s: replace_once(s, "                ->where('user_id', $swiezy->getKey())\n", "")),
    ("Dopisek w Planerze: stara karta nadpisuje nowszy dopisek (#2549)", "app/Domain/Planer/Actions/ZapiszDopisekPlanu.php", "test_stary_znacznik_nie_wyczysci_nowszego_dopisku",
     lambda s: replace_once(s, "            if (self::znacznik($wpis) !== ($widzianyZnacznik ?? '')) {", "            if (false) {")),
    ("Dopisek w Planerze: kopia tygodnia gubi dopisek (#2549)", "app/Domain/Planer/Actions/SkopiujPoprzedniTydzien.php", "test_kopia_tygodnia_przenosi_dopisek_i_nie_nadpisuje_istniejacego",
     lambda s: replace_once(s, "                    'note' => $pozycja['wpis']->note,\n", "")),
    ("Dopisek w Planerze: rollback nie odmawia przy dopiskach (#2549)", "database/migrations/2026_10_03_130000_add_note_to_meal_plan_entries.php", "test_cofniecie_migracji_odmawia_przy_dopiskach_i_przechodzi_bez_nich",
     lambda s: replace_once(s, "        if ($zDopiskiem > 0) {", "        if (false) {")),
    ("Dopisek w Planerze: polityka bez wiersza o dopisku (#2549)", POLITYKA_TEKST, "test_dopisek_w_planie_ma_wiersz_w_polityce_z_limitem_z_kodu",
     lambda s: replace_once(s, "| Dopisek przy przepisie w planie |", "| Notatka przy planie |")),
    # #2550: „Odłóż na później” szkicu — własność, bieżąca lista, publikacja i rollback.
    ("Odłożenie szkicu: policy wpuszcza cudzą osobę (#2550)", "app/Policies/RecipePolicy.php", "OdlozenieSzkicuPrzepisuTest",
     lambda s: replace_once(s, "        return $user->getKey() === $recipe->author_id\n            && $recipe->status === Recipe::STATUS_DRAFT", "        return true\n            && $recipe->status === Recipe::STATUS_DRAFT")),
    ("Odłożenie szkicu: domena nie sprawdza autorstwa (#2550)", "app/Domain/Recipes/Actions/OdlozSzkicPrzepisu.php", "OdlozenieSzkicuPrzepisuTest",
     lambda s: replace_once(s, "                ->where('author_id', $swiezy->getKey())\n", "")),
    ("Odłożenie szkicu: bieżąca lista pokazuje też odłożone (#2550)", "app/Http/Controllers/RecipeController.php", "OdlozenieSzkicuPrzepisuTest",
     lambda s: replace_once(s, "($odlozone ? $zapytanie->whereNotNull('odlozony_at') : $zapytanie->whereNull('odlozony_at'))", "$zapytanie")),
    ("Odłożenie szkicu: publikacja nie zdejmuje oznaczenia (#2550)", "app/Domain/Recipes/Actions/PublishRecipe.php", "OdlozenieSzkicuPrzepisuTest",
     lambda s: replace_once(s, "                        $recipe->forceFill(['odlozony_at' => null]);\n", "")),
    ("Odłożenie szkicu: rollback nie odmawia przy odłożonych (#2550)", "database/migrations/2026_10_03_150000_add_odlozony_at_to_recipes.php", "OdlozenieSzkicuPrzepisuTest",
     lambda s: replace_once(s, "        if ($odlozone > 0) {", "        if (false) {")),
    # #2038: wpis dziennika dopisany PRZED nieudanym commitem wymazania musi
    # zostać wycofany — inaczej `wymaz-ponownie` wymaże konto przed końcem karencji.
    ("Wymazanie nie wycofuje wpisu dziennika po nieudanym commicie", WYMAZANIE_KONTA, DZIENNIK_WYCOFANIE_TEST,
     lambda s: replace_once(s, "                if ($stan->wpisDopisany) {", "                if (false) {")),
    # #2038: wpis, który PRZEŻYŁ odtworzenie kopii (`ISTNIEJE`), nie jest nasz —
    # nieudany commit ponownego wymazania nie wolno go skasować.
    ("Wymazanie kasuje cudzy wpis dziennika (ISTNIEJE)", WYMAZANIE_KONTA, DZIENNIK_ISTNIEJE_TEST,
     lambda s: replace_once(s, "$stan->wpisDopisany = $wpis === DziennikWymazan::DOPISANO;", "$stan->wpisDopisany = true;")),
    # #2038: ponawianie zapisu dziennika czeka POZA transakcją z blokadą konta.
    # Bez `proby: 1` `Sleep` wraca do środka transakcji.
    ("Zapis dziennika czeka wewnątrz transakcji z blokadą konta", WYMAZANIE_KONTA, DZIENNIK_SLEEP_TEST,
     lambda s: replace_once(s, "now(), proby: 1);", "now());")),
    # #2038 etap 3: alarm po N nocach z porażką dziennika — licznik, reset, jedna noc na dzień.
    ("Egzekutor nie liczy porażek dziennika wymazań", WYMAZANIE_PURGE, ALARM_DZIENNIKA_TEST,
     lambda s: replace_once(s, "$nieudaneDziennik++;", "")),
    ("Alarm dziennika wymazań bez zerowania licznika", ALARM_DZIENNIKA, ALARM_DZIENNIKA_RESET_TEST,
     lambda s: replace_once(s, "$this->pamiec->forget(self::KLUCZ_LICZNIKA);", "")),
    ("Alarm dziennika wymazań liczy każdy przebieg dnia jako noc", ALARM_DZIENNIKA, ALARM_DZIENNIKA_DZIEN_TEST,
     lambda s: replace_once(s, "if (! is_array($zapis) || ($zapis['dzien'] ?? null) !== $dzis) {", "if (true) {")),
    # #1992: pierwszy worker zarezerwował slot i czeka na transport. Liczenie
    # wyłącznie potwierdzonych wysyłek musi zapalić test dwóch połączeń.
    ("Limit push nie liczy rezerwacji w transporcie", PUSH_JOB, PUSH_DWA_POLACZENIA_TEST,
     lambda s: replace_once(s, "            ->where($wlicz)\n            ->distinct()",
                            "            ->whereNotNull('push_wyslano_at')\n            ->distinct()")),
    ("Recover alarmu pomija potwierdzona odmowe", ALARM_RECOVERY, ALARM_RECOVERY_TEST,
     lambda s: replace_once(s, "$poprzednia->state !== HumanUrgentAlarmAttempt::REJECTED", "false")),
    ("Preview wkleja ręczny pr_number w run:", PREVIEW_WORKFLOW, WKLEJANIE_DO_RUN_TEST,
     lambda s: replace_once(s, '          env_name="pr-${PR_NUMBER}"\n          echo "Tworzę', '          env_name="pr-${{ github.event.inputs.pr_number }}"\n          echo "Tworzę')),
    ("IaC wkleja inputs.* w podsumowanie", IAC_WORKFLOW, WKLEJANIE_DO_RUN_TEST,
     lambda s: replace_once(s, 'echo "| Zmiany destrukcyjne | ${DESTRUKCYJNE} |"', 'echo "| Zmiany destrukcyjne | ${{ inputs.zmiany_destrukcyjne }} |"')),
    # #1740: topologia w docs/DEPLOYMENT.md nazywa czwarty proces tak jak IaC
    # (`scheduler`, długo działający `schedule:work`), nie `cron`.
    ("DEPLOYMENT.md nazywa scheduler „cron”", "docs/DEPLOYMENT.md", "DeploymentSchedulerNieNazywaSieCronTest",
     lambda s: replace_once(s, "├── scheduler\n", "├── cron\n")),
    # D-300: etap runtime obrazu bez poppler-utils — import PDF padałby
    # dopiero na produkcji; strażnik obrazu ma to złapać.
    ("Obraz runtime bez poppler-utils", OBRAZ_ASSETOW, OBRAZ_PDF_TEST,
     lambda s: replace_once(s, "      poppler-utils \\\n", "")),
    # D-088/D-300: down() migracji importu bez odmowy przy niesprawdzonym
    # szkicu — test cofnięcia ma oblać.
    ("Cofnięcie importu bez odmowy przy niesprawdzonym szkicu", MIGRACJA_IMPORTU, MIGRACJA_IMPORTU_TEST,
     lambda s: replace_once(s, "            if ($ile > 0) {", "            if ($ile > 0 && false) {")),
    # #1741: `.env.example` zna obie zmienne czyszczenia CDN, które czyta
    # `config/kuking.php` — bez nich wdrożenie z szablonu ma czyszczenie wyłączone.
    ("Szablon .env bez tokenu czyszczenia CDN", ".env.example", "EnvExampleMaZmienneCzyszczeniaCdnTest",
     lambda s: replace_once(s, "CLOUDFLARE_PURGE_TOKEN=\n", "")),
    # Audyt A13: README wraca do zdania z blueprintu, że GitHub Actions nie
    # działają — strażnik README ma to złapać, choć ci.yml mówi co innego.
    ("README: „Dopóki ich nie ma” wraca", README, README_SECURITY_TEST,
     lambda s: s + "\nDopóki ich nie ma, testy uruchamiasz lokalnie.\n"),
    ("CHANGELOG z tym samym wpisem dwa razy", CHANGELOG, CHANGELOG_DUPLIKATY_TEST,
     lambda s: replace_once(s, CHANGELOG_NAGLOWEK, CHANGELOG_NAGLOWEK
                            + "- Wpis zdublowany przez kontrolę dodatnią.\n" * 2)),
    # Archiwum changelogu czytane razem z plikiem głównym: ten sam wpis
    # dwa razy w samym archiwum nadal ma zapalić test duplikatów.
    ("Archiwum CHANGELOG z tym samym wpisem dwa razy", CHANGELOG_ARCHIWUM, CHANGELOG_DUPLIKATY_TEST,
     lambda s: s + "\n- Wpis zdublowany w archiwum.\n- Wpis zdublowany w archiwum.\n"),
    # Plik archiwum bez odnośnika w CHANGELOG.md wypadłby po cichu z testów.
    ("CHANGELOG bez odnośnika do pliku archiwum", CHANGELOG, CHANGELOG_NUMERACJA_TEST,
     lambda s: replace_once(s, CHANGELOG_ARCHIWUM_LINK, "")),
    # Strona „Co nowego” (issue #1909): nowa funkcja w sekcji
    # „Nieopublikowane” musi mieć akapit w „Najnowszych zmianach”. Dodajemy
    # osierocony wpis, zamiast zdejmować znacznik ze starego wydania: po
    # nadaniu numeru wydania obie bieżące sekcje mogą być puste (0 = 0).
    ("Nowa funkcja bez akapitu na stronie Co nowego", CHANGELOG_NOWOSCI, STRAZNIK_NOWOSCI_TEST,
     lambda s: replace_once(s, "## Nieopublikowane\n",
                            "## Nieopublikowane\n\n- Kontrola ujemna bez opisu. [nowa funkcja]\n")),
    # Dziennik wdrożeń (#1932, D-318, D-088): zdjęcie warunku odmowy z down()
    # ma zapalić strażnika cofnięcia — bez niego migracja ciągnie DROP TABLE
    # nawet na wypełnionym dzienniku.
    ("Dziennik wdrożeń: down() bez warunku odmowy", MIGRACJA_DZIENNIK_WDROZEN, DZIENNIK_WDROZEN_TEST,
     lambda s: replace_once(s, "if ($wierszyWdrozen > 0 || $wierszyFunkcji > 0) {", "if (false) {")),
    # #1932 (D-318): „Co nowego” przestaje czytać mapę nagłówek → numer
    # wdrożenia — dopisek „od Alfa …” znika, test strony ma oblać.
    ("Co nowego bez dopisku „od numeru”", NOWOSCI_KONTROLER, NOWOSCI_OD_NUMERU_TEST,
     lambda s: replace_once(s, "$dopisek = $mapa[$slug] ?? null;", "$dopisek = null;")),
    ("Co nowego: brak nagłówka w fixture integracyjnej", NOWOSCI_OD_NUMERU_FIXTURE,
     "test_strona_pokazuje_od_numeru_dla_znanego_naglowka_w_najnowszych_zmianach",
     lambda s: replace_once(s, r'### {$naglowek}\n\nOpis funkcji.', r'Bez nagłówka {$naglowek}\n\nOpis funkcji.')),
    # D-088 (#44): down() migracji no_amount bez odmowy przy składnikach
    # oznaczonych „bez wymiernej ilości” — test cofnięcia ma oblać.
    ("Cofnięcie no_amount bez odmowy przy oznaczonych składnikach", MIGRACJA_NO_AMOUNT, MIGRACJA_NO_AMOUNT_TEST,
     lambda s: replace_once(s,
                            "            && DB::table('recipe_ingredients')->where('no_amount', true)->exists()) {",
                            "            && false && DB::table('recipe_ingredients')->where('no_amount', true)->exists()) {")),
    # #35 (D-088): down() migracji Web Push bez odmowy, choć ludzie wybrali
    # własną ciszę nocną — test wycofania ma oblać.
    ("Wycofanie Web Push bez odmowy przy wybranej ciszy nocnej", MIGRACJA_PUSH, MIGRACJA_PUSH_TEST,
     lambda s: replace_once(s, "        if (Schema::hasTable('ustawienia_powiadomien_zewnetrznych')\n", "        if (false && Schema::hasTable('ustawienia_powiadomien_zewnetrznych')\n")),
    # #2154: dziennik decyzji nie może znowu przyjąć numeru roboczego ani
    # odwołania do decyzji bez nagłówka (martwa „reguła" o numerze 235). Trzy wady dopisane do
    # prawdziwych plików i dwa wzorce strażnika wyłączone w jego własnym kodzie —
    # każde ma dać czerwony test, bo tylko wtedy wiadomo, że strażnik widzi.
    ("Martwe odwołanie D-NNN w AGENTS.md", "AGENTS.md", DZIENNIK_ODWOLANIA_TEST,
     lambda s: s + DZIENNIK_MARTWE_ODWOLANIE),
    ("Roboczy identyfikator w AGENTS.md", "AGENTS.md", DZIENNIK_ODWOLANIA_TEST,
     lambda s: s + DZIENNIK_ROBOCZY_NUMER),
    ("Roboczy nagłówek w dzienniku decyzji", "docs/decyzje/D-001-modularny-monolit-laravel-bez-mikroserwisow.md", DZIENNIK_ODWOLANIA_TEST,
     lambda s: s + DZIENNIK_ROBOCZY_NAGLOWEK),
    ("Strażnik dziennika ślepy na dopisek ROBOCZA", DZIENNIK_ODWOLANIA_PLIK_TESTU, DZIENNIK_ODWOLANIA_TEST,
     lambda s: replace_once(s, r"'/\bD-\d+-(?:ROBOCZ\w*|TYMCZAS\w*|TMP|DRAFT|WIP|TODO)\b/iu'", "'/(*FAIL)/'")),
    ("Strażnik dziennika ślepy na identyfikator czterocyfrowy", DZIENNIK_ODWOLANIA_PLIK_TESTU, DZIENNIK_ODWOLANIA_TEST,
     lambda s: replace_once(s, r"'/\bD-\d{4,}\b/'", "'/(*FAIL)/'")),
    ("Runbook: railway config apply bez KUKING_WAIT_FOR_CI", RUNBOOK, KOMENDY_IAC_TEST,
     lambda s: replace_once(s, RUNBOOK_APPLY_Z_BRAMKA, "\nrailway config apply\n")),
    # #1957: job, który uruchamia kod repozytorium, traci
    # `persist-credentials: false` — token zapisu mógłby wrócić do jego
    # konfiguracji gita bez zabezpieczenia; strażnik workflow ma oblać.
    ("Workflow cen: checkout z kodem bez persist-credentials: false", CENY_WARZYW_WORKFLOW, CENY_WARZYW_TEST,
     lambda s: replace_once(s, "          persist-credentials: false\n", "")),
    # #1851: krok „Ustal adres środowiska" wraca do wklejania danych zdarzenia
    # w treść skryptu — strażnik ma to złapać, zanim nazwa środowiska stanie
    # się poleceniem na runnerze.
    ("Deploy: dane zdarzenia wklejone do Basha", WDROZENIE_WORKFLOW, DEPLOY_WSTRZYKNIECIE_TEST,
     lambda s: replace_once(s, 'env_name="${ZDARZENIE_SRODOWISKO:-}"', "env_name='${{ github.event.deployment.environment }}'")),
    ("Strażnik migracji ślepy na FK dodany przez Blueprint", STRAZNIK_MIGRACJI, STRAZNIK_MIGRACJI_TEST,
     lambda s: replace_once(s, "if (preg_match('/->\\s*(constrained|foreign)\\s*\\(/', $body)) {",
                            "if (false && preg_match('/->\\s*(constrained|foreign)\\s*\\(/', $body)) {")),
    ("Strażnik migracji ślepy na indeks bez CONCURRENTLY", STRAZNIK_MIGRACJI, STRAZNIK_MIGRACJI_TEST,
     lambda s: replace_once(s, "if (! $maCONCURRENTLY) {", "if (false) {")),
    ("Strażnik migracji ślepy na CHECK/FK bez NOT VALID", STRAZNIK_MIGRACJI, STRAZNIK_MIGRACJI_TEST,
     lambda s: replace_once(s, "if (stripos($instrukcja, 'NOT VALID') === false) {\n                    $rodzaj",
                            "if (false) {\n                    $rodzaj")),
    # Audyt A4 5.1: job `lint` wraca do samego `kopia-bazy.sh` zamiast
    # wspólnego `scripts/kontrole-powloki.sh` — rozjazd CI i check.sh.
    ("Job lint bez wspólnych kontroli powłoki", ".github/workflows/ci.yml", "KontrolePowlokiLokalnieIWCiTest",
     lambda s: replace_once(s, "        run: bash scripts/kontrole-powloki.sh\n", "        run: bash tests/skrypty/kopia-bazy.sh\n")),
    # Ten sam audyt: test powłoki wypada z listy wspólnego skryptu — bez
    # strażnika `tests/skrypty/*.sh` zostałby pominięty w check.sh i w CI.
    ("Test powłoki wypada z listy wspólnego skryptu", "scripts/kontrole-powloki.sh", "KontrolePowlokiLokalnieIWCiTest",
     lambda s: replace_once(s, "tests/skrypty/bramka-migracji.sh|Bramka migracji workera i schedulera oblewa\n", "")),
    # #1985: sufit danych paczki importu trzyma się limitu pamięci PHP (256M,
    # json_decode ~8×). Powrót do 32 MB kończył się fatalem 500 bez komunikatu.
    ("Sufit paczki importu wraca do 32 MB", "app/Domain/Users/Import/PodgladPaczkiEksportu.php", "test_sufit_danych_pozostaje_bezpieczny_dla_limitu_pamieci_php",
     lambda s: replace_once(s, "MAX_DANE_BAJTOW = 12 * 1024 * 1024;", "MAX_DANE_BAJTOW = 32 * 1024 * 1024;")),
    # #2224: stary numer KRS wraca do audytu ADR — strażnik danych rejestrowych
    # w dokumentach ma go wyłapać (dokument historyczny nie ma wyjątku).
    ("Audyt ADR: stary KRS operatora", "docs/legal/AUDYT_ADR_WARSTWA_MERYTORYCZNA.md", "test_zadne_dane_rejestrowe_w_dokumentach_nie_odbiegaja_od_konfiguracji",
     lambda s: replace_once(s, "(KRS 0000901262, NIP", "(KRS 0000854321, NIP")),
    # #2223 (D-333): tinker wraca do `require` — obraz --no-dev znów niesie PsySH.
    ("Tinker wraca do require", "composer.json", "test_tinker_jest_tylko_w_require_dev",
     lambda s: replace_once(s, '"laravel/sanctum": "^4.0",\n', '"laravel/sanctum": "^4.0",\n        "laravel/tinker": "^3.0",\n')),
    # Ten sam issue: runbook wraca do tinkera zamiast komendy kuking:*.
    ("Runbook znów każe użyć tinkera", "docs/infra/MONITORING_BLEDOW.md", "test_runbooki_produkcyjne_nie_kaza_uzywac_tinkera",
     lambda s: replace_once(s, "php artisan kuking:sprawdz-alarm --przez-wyjatek\n",
                            "php artisan tinker --execute=\"report(new RuntimeException('x'));\"\n")),
    # #2293 (IN-01): proc_open wraca na listę wyłączonych dla kolejki — odczyt
    # PDF-a w podprocesie z produkcyjnym php.ini znów pada.
    ("Kolejka traci proc_open (#2293)", "docker/entrypoint.sh", "KolejkaCzytaPdfZProdukcyjnymPhpIniTest",
     lambda s: replace_once(s, 'FUNKCJE_ZABRONIONE_KOLEJKI="exec,passthru,shell_exec,system,popen"',
                            'FUNKCJE_ZABRONIONE_KOLEJKI="exec,passthru,shell_exec,system,proc_open,popen"')),
    # Ten sam issue: queue:work przestaje dostawać flagę -d disable_functions.
    ("queue:work bez listy funkcji kolejki (#2293)", "docker/entrypoint.sh", "KolejkaCzytaPdfZProdukcyjnymPhpIniTest",
     lambda s: replace_once(s, '    -d "disable_functions=${FUNKCJE_ZABRONIONE_KOLEJKI}" \\\n', '')),
    # #2298 (IN-06): job z pull_request bez straży forka w runs-on.
    ("Job PR-a bez straży forka (#2298)", ".github/workflows/railway-iac.yml", "WorkflowyNieWpuszczajaForkowNaWlasneRunneryTest",
     lambda s: s.replace("fromJSON((github.event_name == 'pull_request' && github.event.pull_request.head.repo.full_name != github.repository && '\"ubuntu-latest\"') || vars", "fromJSON(vars")),
    # Ten sam issue: bramka deployu z workflow_run bez warunku repozytorium źródłowego.
    ("Bramka deployu bez warunku repozytorium (#2298)", ".github/workflows/railway-ci-gated-deploy.yml", "WorkflowyNieWpuszczajaForkowNaWlasneRunneryTest",
     lambda s: replace_once(s, "      github.event.workflow_run.head_repository.full_name == github.repository", "      true")),
    # #2302 (IN-10): nagłówek workflowu znów mówi o repozytorium prywatnym.
    ("Nagłówek deploy.yml znów o repo prywatnym (#2302)", ".github/workflows/deploy.yml", "WorkflowyNieWpuszczajaForkowNaWlasneRunneryTest",
     lambda s: replace_once(s, "#  Repozytorium jest PUBLICZNE (decyzja", "#  Repozytorium jest prywatne (decyzja")),
    # #2296 (IN-04): runbook #595 przestaje wymieniać wyłącznik włączany przez apply.
    ("Runbook #595 bez wyłącznika życzeń (#2296)", "docs/infra/PRZELACZENIE_NA_3_SERWISY_595.md", "RunbookApplyNazywaWlaczniki595Test",
     lambda s: replace_once(s, "| dodanie `KUKING_URODZINY_MAIL_WLACZONY=true`", "| dodanie wyłącznika życzeń")),
    # #2301 (IN-09): composer.json bez jawnej platformy PHP dla Dependabota.
    ("Composer bez config.platform.php (#2301)", "composer.json", "ComposerRozwiazujeSieDlaDependabotaTest",
     lambda s: replace_once(s, '"php": "8.4.1"', '"php": "8.4.0"')),
    # #2302 (IN-15): nowa migracja dołącza do historycznej grupy znacznika.
    ("Nowa migracja w starej grupie znacznika (#2302)", "tests/Feature/MigracjeMajaUnikalnyZnacznikCzasuTest.php", "test_kazda_nowa_migracja_ma_wlasny_znacznik_czasu",
     lambda s: replace_once(s, "'2026_09_26_100000' => 8,", "'2026_09_26_100000' => 7,")),
    # #2309: krok CI znów sam dodaje klucz PGDG, z pominięciem sprawdzenia odcisku.
    ("Krok CI dodaje klucz PGDG z pominięciem odcisku", CI_WORKFLOW, LANCUCH_CI_TEST,
     lambda s: pierwsze_z_wielu(s, KLIENT_PG18_KROK, KLIENT_PG18_DAWNY_KROK, 2)),
    # #2309: skrypt klienta ufa pobranemu kluczowi bez sprawdzenia odcisku.
    ("Klient PostgreSQL 18 bez sprawdzenia odcisku klucza PGDG", KLIENT_PG18, LANCUCH_CI_TEST,
     lambda s: replace_once(s, 'sprawdz_klucz_pgdg "$tymczasowy" || exit 1', 'true')),
    # #2310: pip wraca do samego numeru wersji, bez hasha.
    ("openpyxl bez --require-hashes", CENY_WORKFLOW, LANCUCH_CI_TEST,
     lambda s: replace_once(s, "pip install --disable-pip-version-check --require-hashes --only-binary :all: -r scripts/ceny-warzyw-requirements.txt",
                            "pip install --disable-pip-version-check openpyxl==3.1.5")),
    ("Zależność openpyxl bez hasha w pliku wymagań", CENY_WYMAGANIA, LANCUCH_CI_TEST,
     lambda s: replace_once(s, "et-xmlfile==2.0.0 \\\n    --hash=sha256:7a91720bc756843502c3b7504c77b8fe44217c85c537d85037f0f536151b2caa",
                            "et-xmlfile==2.0.0")),
    # #2263: usługa PostgreSQL z ruchomego tagu i digest rozjechany z Dependabotem.
    ("Usługa PostgreSQL w CI z ruchomego tagu", CI_WORKFLOW, LANCUCH_CI_TEST,
     lambda s: pierwsze_z_wielu(s, "image: " + PG_CI_DIGEST + "\n", "image: postgres:18-alpine\n", 7)),
    ("Digest usługi PostgreSQL inny niż w Dockerfile Dependabota", PG_CI_DOCKERFILE, LANCUCH_CI_TEST,
     lambda s: replace_once(s, "FROM " + PG_CI_DIGEST, "FROM postgres:18-alpine@sha256:" + "0" * 64)),
    # #2233: checkout bramki bez `ref` i skrypt bez porównania HEAD.
    ("Bramka Railway checkoutuje bieżący main zamiast SHA z CI", BRAMKA_RAILWAY_WORKFLOW, BRAMKA_CHECKOUT_TEST,
     lambda s: replace_once(s, "          ref: ${{ github.event.workflow_run.head_sha }}\n", "")),
    ("Skrypt bramki Railway nie porównuje HEAD z SHA z CI", BRAMKA_RAILWAY_SKRYPT, BRAMKA_CHECKOUT_TEST,
     lambda s: replace_once(s, "    verify_checkout(sha, local_head())\n", "")),
    # #2025: bramka przepuszcza każdy zakończony przebieg CI, nie tylko sukces
    # (anulowany, pominięty i nieudany też by wdrażały produkcję).
    ("Bramka Railway wdraża po CI bez sukcesu (#2025)", BRAMKA_RAILWAY_WORKFLOW, BRAMKA_WARUNKI_TEST,
     lambda s: replace_once(s, "      github.event.workflow_run.conclusion == 'success' &&\n",
                            "      github.event.workflow_run.conclusion != 'cancelled' &&\n")),
    # #2025: zmiana nazwy joba zbiorczego CI — bramka szuka go po nazwie.
    ("Job zbiorczy CI zmienia nazwę, bramka go nie znajdzie (#2025)", CI_WORKFLOW, BRAMKA_JOB_ZBIORCZY_TEST,
     lambda s: replace_once(s, "  testy:\n    name: Testy (PostgreSQL 18)\n", "  testy:\n    name: Testy PG18\n")),
    # #2025: alarm po wdrożeniu traci odczyt przebiegów CI.
    ("Alarm audit_ci bez odczytu przebiegów (#2025)", WDROZENIE_WORKFLOW, BRAMKA_ALARM_TEST,
     lambda s: replace_once(s, "      actions: read # #2025:", "      checks: read # #2025:")),
    # #2230: wydanie Sentry wraca do github.sha.
    ("Wydanie Sentry z github.sha zamiast SHA wdrożenia", WDROZENIE_WORKFLOW, SENTRY_SHA_TEST,
     lambda s: replace_once(s, "version: ${{ github.event.deployment.sha }}", "version: ${{ github.sha }}")),
    # #2248: nieznany stan w historii wdrożenia znów pomijany po cichu.
    ("Nieznany stan w historii wdrożenia pominięty", "scripts/ci/stan-wdrozenia.sh", STAN_WDROZENIA_TEST,
     lambda s: replace_once(s, '    *)\n      echo "::error title=Nieznany stan w historii wdrożenia::',
                            '    *) continue\n      echo "::error title=Nieznany stan w historii wdrożenia::')),
    # Audyt prywatności 30.09 Z2 (#2278): polityka nazywa ciasteczko
    # „zapamiętaj mnie”, jego termin z bramki logowania i klucze localStorage.
    ("Polityka bez nazwy ciasteczka zapamiętaj mnie", POLITYKA_TEKST, "PolitykaNazywaPamiecPrzegladarkiTest",
     lambda s: replace_once(s, "(`remember_web_…`, dalszy", "(`remember_…`, dalszy")),
    ("Polityka z terminem zapamiętaj mnie innym niż bramka", POLITYKA_TEKST, "PolitykaNazywaPamiecPrzegladarkiTest",
     lambda s: replace_once(s, "ważne **400 dni** od zalogowania: gdy sesja", "ważne **30 dni** od zalogowania: gdy sesja")),
    ("Klucz localStorage bez opisu w polityce", "resources/js/szybki-wyglad.js", "PolitykaNazywaPamiecPrzegladarkiTest",
     lambda s: replace_once(s, "localStorage.setItem('kuking-wyglad-poznany', '1')", "localStorage.setItem('kuking-wyglad-nowy', '1')")),
    # Z5 (#2281): każda sekcja paczki ma opis w polityce, terminy z konfiguracji.
    ("Planer bez wiersza w polityce", POLITYKA_TEKST, "PolitykaOpisujeKazdaSekcjePaczkiTest",
     lambda s: replace_once(s, "| Plan na tydzień |", "| Planowanie posiłków |")),
    ("Nowa sekcja paczki bez opisu w polityce", "app/Domain/Users/Exports/InwentarzDanychKonta.php", "PolitykaOpisujeKazdaSekcjePaczkiTest",
     lambda s: replace_once(s, "'meal_plan_entries.user_id' => [self::EKSPORT, 'planer'],", "'meal_plan_entries.user_id' => [self::EKSPORT, 'planer_nowy'],")),
    ("Postęp gotowania z terminem niezgodnym z konfiguracją", POLITYKA_TEKST, "test_terminy_nowych_wierszy_zgadzaja_sie_z_konfiguracja",
     lambda s: replace_once(s, "**24 godziny** od ostatniej zmiany — potem postęp", "**48 godzin** od ostatniej zmiany — potem postęp")),
    # Z6 (#2282): Cloudflare jako pośrednik całego ruchu ma własny wiersz.
    ("Cloudflare jako pośrednik bez wiersza w polityce", POLITYKA_TEKST, "kazda_usluga_uzywana_przez_kod_jest_wymieniona_w_polityce",
     lambda s: replace_once(s, "| Cloudflare (sieć, CDN i ochrona przed atakami) |", "| Cloudflare |")),
    # #2267: kolor paska przeglądarki idzie za motywem, manifest ma jasny.
    ("Pasek przeglądarki znów zawsze ciemny", LAYOUT, "test_gosc_bez_wyboru_i_z_jasnym_wyborem_dostaje_jasny_pasek",
     lambda s: replace_once(s, "content=\"{{ $theme === 'dark' ? '#151714' : '#F3F4F1' }}\"", 'content="#151714"')),
    ("Manifest wraca do ciemnego theme_color", "public/manifest.webmanifest", "test_kolory_to_tlo_strony_z_tokenow_a_manifest_ma_jasny",
     lambda s: replace_once(s, '"theme_color": "#F3F4F1"', '"theme_color": "#151714"')),
    # UX-02 (#2287): samodzielne zdanie pomocnicze wraca do 16 px.
    ("Pusty dzień planera znów w 16 px", "resources/views/pages/planer/show.blade.php", "test_instrukcje_i_puste_stany_niosa_klase_tekstu_podstawowego",
     lambda s: replace_once(s, '<p class="meta meta-samodzielne">Nic jeszcze nie zaplanowane.</p>', '<p class="meta">Nic jeszcze nie zaplanowane.</p>')),
    ("Samodzielne zdanie pomocnicze w arkuszu na 16 px", CSS, "test_klasa_w_arkuszu_daje_tekst_podstawowy_18_px",
     lambda s: replace_once(s, "  .meta-samodzielne {\n    font-size: var(--text-body);", "  .meta-samodzielne {\n    font-size: var(--text-help);")),
    # D-333 „novalidate wszędzie” (30.09.2026): formularz z natywną walidacją
    # bez `novalidate` zatrzymuje dymek przeglądarki przed polskim podsumowaniem.
    # Pierwsza mutacja zapala oba spojrzenia (HTML ekranu i szablon), druga —
    # formularz w komponencie, rozwijany przez skaner szablonów.
    ("Logowanie bez novalidate", "resources/views/auth/login.blade.php", "FormularzeZWalidacjaMajaNovalidateTest",
     lambda s: replace_once(s, "action=\"{{ route('login') }}\" novalidate>", "action=\"{{ route('login') }}\">")),
    ("Wybór zeszytu bez novalidate", "resources/views/components/wybor-zeszytu.blade.php", "test_kazdy_formularz_w_szablonach_z_natywna_walidacja_ma_novalidate",
     lambda s: replace_once(s, '<form method="POST" action="{{ $action }}" novalidate>', '<form method="POST" action="{{ $action }}">')),
    # #2308: lista kursorowa bez `KursorListy` wraca do HTTP 500 na zmyślonym `?cursor=`.
    ("Strona tagu stronicuje gołym cursorPaginate", "app/Http/Controllers/TagController.php", "test_w_app_cursor_paginate_wola_tylko_kursor_listy",
     lambda s: replace_once(s,
         "KursorListy::strona($zapytanie, (int) config('kuking.feed.page_size'), ['published_at' => KursorListy::CZAS, 'id' => KursorListy::UUID])",
         "$zapytanie->cursorPaginate((int) config('kuking.feed.page_size'))")),
    # Z8, Z10, Z11 (#2283): polityka mówi o śladzie nieudanej wysyłki tyle dni,
    # ile queue:prune-failed w harmonogramie; §9 bez obietnicy e-maila;
    # zakres `profile` przy Google obejmuje zdjęcie.
    ("Retencja failed_jobs inna niż w polityce", "routes/console.php", "PolitykaMowiPrawdeOPoczcieGoogleIZmianachTest",
     lambda s: replace_once(s, "Harmonogram::artisan('queue:prune-failed', ['--hours' => 720])",
                            "Harmonogram::artisan('queue:prune-failed', ['--hours' => 168])")),
    ("Polityka znów obiecuje e-mail o zmianie", POLITYKA_TEKST, "PolitykaMowiPrawdeOPoczcieGoogleIZmianachTest",
     lambda s: replace_once(s, "O zmianie polityki nie piszemy do Ciebie e-mailem", "O zmianie polityki napiszemy także e-mailem")),
    ("Polityka pomija zdjęcie z zakresu Google", POLITYKA_TEKST, "PolitykaMowiPrawdeOPoczcieGoogleIZmianachTest",
     lambda s: replace_once(s, "Google opisuje je na swoim ekranie zgody jako imię i **zdjęcie profilowe** — nie ma osobnej prośby o samo imię.",
                            "Google podaje nam imię.")),
    # Z9 (#2283): regulamin §2 wymienia usługi, których adresy istnieją.
    ("Regulamin §2 bez „Poradźcie”", "resources/legal/regulamin.md", "RegulaminWymieniaUslugiSerwisuTest",
     lambda s: replace_once(s, " („Poradźcie”),", ",")),
    # #2480: sam skan musi otwierać sekcję źródła bez dopisywania historii.
    ("Sam skan bez notatki znika ze strony przepisu", "resources/views/pages/recipes/show.blade.php", "test_wlasciciel_widzi_sam_skan_na_zwyklej_stronie_prywatnego_przepisu",
     lambda s: replace_once(s,
         "@if(($recipe->source_note || $recipe->source_person || $recipe->sourceScan) && ! $dlaPomocnika)",
         "@if(($recipe->source_note || $recipe->source_person) && ! $dlaPomocnika)")),
    ("Fixture wspomnienia znika po północy w Polsce", "scripts/fixtures/rocznice-wykonania-s.php",
     "test_fixture_pomiaru_pokazuje_wspomnienie_po_polnocy_w_polsce",
     lambda s: replace_once(s, "$dzis->copy()->addDay()->subYear()", "$dzis->copy()->subYear()")),
    ("Szyna zeszytu ponownie czyta całą historię (#2030)", "app/Http/Controllers/CollectionController.php",
     "test_szyna_sprawdza_widocznosc_tylko_malej_partii_kandydatow",
     lambda s: replace_once(s, "        $partia = 20;\n", "        $partia = 1000;\n")),
    ("Przeterminowany use_by wraca do doboru (#2453)", "app/Domain/Pantry/PriorytetZuzycia.php",
     "test_po_terminie_nalezy_zuzyc_do_nie_jest_skladnikiem_w_zadnym_trybie_ale_inne_terminy_pozostaja",
     lambda s: replace_once(s,
         'public const DOSTEPNY_SQL = "(p.expiry_kind IS DISTINCT FROM \'use_by\' OR p.expires_on >= ? OR p.frozen)";',
         'public const DOSTEPNY_SQL = "(true OR p.expires_on >= ? OR p.frozen)";')),
    ("Przeterminowany use_by wybiera odbiorcę listu (#2453)", "app/Console/Commands/WyslijPrzypomnieniaOProduktach.php",
     "test_produkt_po_terminie_nalezy_zuzyc_do_nie_wywoluje_listu",
     lambda s: replace_once(s,
         "                    ->where('p.frozen', false)\n                    ->whereRaw(PriorytetZuzycia::DOSTEPNY_SQL, [$dzis]);",
         "                    ->where('p.frozen', false);")),
]

# CZERWIEŃ Z OCZEKIWANEJ PRZYCZYNY (#1011, docs/PULAPKI_TESTOW.md §5b). Dawniej
# kontrolę zaliczał każdy niezerowy kod ze słowem `FAILED` w wyjściu, więc błąd
# składni, awaria bazy albo niezależna asercja z tej samej klasy dawały ten sam
# „dowód" co asercja, którą mutacja miała zapalić. Teraz wynik czytamy z raportu
# JUnit, a wzorzec oczekiwanej porażki stoi w `OCZEKUJ` (osobny plik, klucz to
# nazwa kontroli z `checks`). Kontrola bez wzorca przechodzi tylko jako
# BEZ_WZORCA i raport wymienia ją z nazwy — nie jest pełnym dowodem.
# Wszystkie wpisy `checks` mają wzorzec, więc `WYMAGAJ_WZORCA = True`: nowy wpis
# bez wzorca jest odrzucany w PREFLIGHCIE (niżej), przed pierwszym testem, z
# nazwą wpisu. Jak zebrać komunikat do wzorca: uruchom sam wpis na własnym
# klastrze — `--tylko "<etykieta>"` (ten tryb nie wymaga wzorca) — a werdykt
# BEZ_WZORCA poda komunikaty porażki. Strażnik: tests/skrypty/kontrole-negatywne-przyczyna.py.
WYMAGAJ_WZORCA = True
WYMAGAJ_TERAZ = WYMAGAJ_WZORCA and not TYLKO

# PREFLIGHT KOTWIC: każda mutacja próbna W PAMIĘCI, zanim ruszy jakikolwiek test.
# Po PR #1721 kotwica eksportu przestała pasować, a krok padał dopiero po kilku
# minutach, anonimowym „nie znalazła dokładnie jednego miejsca” — bez nazwy
# kontroli. Czytanie w logu ~500 linii oczekiwanych porażek (np. „Format UUID”
# celowo daje 500 w WyborZeszytuMaWalidacjeTest) wyglądało jak regresja w kodzie.
# Tu nic nie jest zapisywane na dysk; błąd mówi, KTÓRA kontrola i w jakim pliku.
for label, filename, _test, mutate in checks:
    try:
        source = (ROOT / filename).read_text()
        if mutate(source) == source:
            raise RuntimeError("Mutacja nie zmieniła źródła.")
    except Exception as error:
        raise RuntimeError(f"Kontrola „{label}” ({filename}) nie pasuje do kodu: {error}") from error

# Wzorce oczekiwanej przyczyny (#1011) sprawdzamy w tym samym preflighcie, PRZED
# jakimkolwiek testem: literówka w nazwie kontroli albo zły regex wywraca krok
# w sekundę, z nazwą kontroli, a nie po kilkunastu minutach.
KONTROLE_MECHANIZMU = kontrole_mechanizmu(
    STRAZNIK_HOSTA, STRAZNIK_HOSTA_TEST, bez_sprawdzenia_sciezki, replace_once,
)
sprawdz_wzorce(checks, OCZEKUJ, wymagaj=WYMAGAJ_TERAZ)
for label, filename, _test, mutate, _oczekuj in KONTROLE_MECHANIZMU:
    try:
        source = (ROOT / filename).read_text()
        if mutate(source) == source:
            raise RuntimeError("Mutacja nie zmieniła źródła.")
    except Exception as error:
        raise RuntimeError(f"Kontrola mechanizmu „{label}” ({filename}) nie pasuje do kodu: {error}") from error

# KONTROLE DODATNIE PRZED MUTACJAMI wynikają z `checks`, nie z ręcznej listy.
# Do tej pory stała tu ręczna lista ponad 90 wywołań `run_test(..., True)`,
# osobna od `checks`. Rozjechała się z nią: audyt po fali 25.09 znalazł testy
# z `checks` bez kontroli dodatniej PRZED mutacją (m.in.
# KontrolkiPaneluWygladuMajaWidocznaObwodkeTest, StartKonteneraNieCzysciCacheTest,
# PlanerTygodniaTest), a każdy nowy wpis wymagał dopisania nazwy w dwóch miejscach.
# Test czerwony jeszcze przed mutacją wyglądał wtedy w logu jak „mutacja wykryta”.
# Teraz każdy test z `checks` idzie na zielono przed pierwszą mutacją, raz,
# w kolejności z `checks` — nowy wpis nie ma czego zapomnieć.
# Podział na części CI: wpis o indeksie i należy do części i % M + 1. PREFLIGHT
# wyżej sprawdził WSZYSTKIE kotwice w każdej części (jest tani), a poniżej idą
# tylko wpisy wybranej części — każdy wpis w dokładnie jednej.
if TYLKO:
    if CZESC is not None:
        raise SystemExit("--tylko i --czesc wykluczają się: podaj jedno z nich.")
    wybrane = [checks[i] for i in indeksy_po_etykietach([nazwa for nazwa, *_ in checks], TYLKO)]
    poza_petla = False
    print(f"--tylko: {len(wybrane)} z {len(checks)} wpisów `checks`, bez elementów spoza pętli.", flush=True)
else:
    wybrane = [checks[i] for i in wybierz_indeksy(len(checks), CZESC)]
    poza_petla = poza_petla_w_tej_czesci(CZESC)
if CZESC is not None:
    print(f"Część {CZESC[0]}/{CZESC[1]}: {len(wybrane)} z {len(checks)} wpisów `checks`"
          f"{' oraz elementy spoza pętli' if poza_petla else ''}.", flush=True)
kontrole_dodatnie = list(dict.fromkeys(test for _label, _filename, test, _mutate in wybrane))
if not kontrole_dodatnie:
    raise RuntimeError("Wybrana część `checks` jest pusta — nie ma czego sprawdzać.")
# #2299: każdy `artisan test --filter` dostaje w argumencie pliki, w których filtr
# coś wybiera (scripts/zawezenie_testow.py) — bez tego PHPUnit budował cały zestaw
# przy każdym z ok. 270 wywołań na część. Zanim cokolwiek zmutujemy: próba na
# dwóch pierwszych testach części, że zawężony przebieg wykonuje te same testy.
sprawdz_zgodnosc(kontrole_dodatnie[:2])
# JEDYNY test bez własnej mutacji, który ma iść na zielono przed pętlą: klasa
# obejmująca oba testy metod z wpisów #1059 (GRUPA_LICZBA_TEST i
# GRUPA_KOLEJNOSC_TEST). Nie jest to druga lista kontroli dodatnich — dopisuj
# tu tylko test, którego nie da się wskazać wpisem w `checks`.
KONTROLE_DODATNIE_BEZ_MUTACJI = [GRUPA_SYGNALOW_TEST] if poza_petla else []
for test in dict.fromkeys(kontrole_dodatnie + KONTROLE_DODATNIE_BEZ_MUTACJI):
    run_test(test, True)
# Podział na części: pętlę mutacji dostaje tylko `wybrane`, a wzorce są sprawdzane
# względem CAŁEGO `checks` (`wszystkie`). Kontrole mechanizmu (osobne od `checks`)
# należą do elementów spoza pętli, czyli do części `poza_petla`.
potwierdzone, bez_wzorca = przebieg(
    [],  # kontrole dodatnie poszły wyżej, `run_test` z własnym komunikatem błędu
    wybrane, OCZEKUJ, KONTROLE_MECHANIZMU if poza_petla else (),
    wymagaj_wzorca=WYMAGAJ_TERAZ, wszystkie=checks,
)
# #2167: usunięcie wymaganego CSV ma zakończyć test porażką, nie skipem.
# Robimy to osobno, bo kontrola usuwa plik zamiast podmieniać jego treść.
# Element spoza pętli: w części CI tylko w części 3 (`poza_petla`).
if poza_petla:
    miary = ROOT / "database/data/odzywcze/miary.csv"
    oryginal_miar = miary.read_bytes()
    try:
        miary.unlink()
        ocena_miar = werdykt("masa_kotleta_zgadza_sie_z_miarami_domowymi", OCZEKUJ_MIARY,
                             uruchom_test("masa_kotleta_zgadza_sie_z_miarami_domowymi"))
        print(f"WERDYKT brak miary.csv: {ocena_miar.werdykt} — {ocena_miar.powod}", flush=True)
        if ocena_miar.werdykt != POTWIERDZONA:
            raise RuntimeError("Brak miary.csv nie oblał testu z oczekiwanej przyczyny: " + ocena_miar.powod)
    finally:
        miary.write_bytes(oryginal_miar)
    run_test("masa_kotleta_zgadza_sie_z_miarami_domowymi", True)
# Podsumowanie (potwierdzone i lista BEZ WZORCA) wypisał `przebieg` — liczebniki
# z `len(checks)`, nie z tekstu (dawniej stało tu wpisane słowo „Pięć”).
