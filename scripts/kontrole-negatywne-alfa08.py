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
from pathlib import Path
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parent.parent
os.chdir(ROOT)


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

    print(f"Kontrole negatywne lokalnie: {host}:{port}/{baza}", flush=True)

CONTROLLER = "app/Http/Controllers/CollectionController.php"
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

# Etap `assets` obrazu a lista plików podana do `node --test` (regresja #1085).
# Ten strażnik pilnuje własnej NIEPUSTOŚCI (`assertNotEmpty`), ale nic w nim
# nie dowodzi, że czytnik `COPY` z Dockerfile potrafi powiedzieć „nie
# kopiowany". Gdyby parser zaczął zwracać zbiór za szeroki, `czyKopiowany()`
# byłoby zawsze prawdziwe, a test świeciłby na zielono nad niczym — dokładnie
# ta klasa usterki, dla której powstał mechanizm kontroli dodatnich.
OBRAZ_ASSETOW = "Dockerfile"
OBRAZ_ASSETOW_TEST = "ObrazAssetowMaPlikiTestowTest"

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
# Warunki reguł Cloudflare (#597). Strażnik czyta sparsowany JSON; mutacja
# zdejmuje warunek pustego ciasteczka z reguły zdjęć — to jest dokładnie
# wyciek treści prywatnej do wspólnego cache, którego #597 zakazuje.
REGULY_CF = "docs/infra/cloudflare-cache-rules-597-610.json"
REGULY_CF_TEST = "test_warunki_regul_nie_wpuszczaja_stanu_klienta_do_wspolnego_cache"
REGULA_ZDJEC_CIASTKO = '\\"/zdjecia/\\") and http.request.uri.query eq \\"\\" and http.cookie eq \\"\\"'
# Bramka zakresu w `ci.yml` (#1273): filtr warstwy widoku obejmuje lokalne
# akcje `.github/actions/`, bo joby przeglądarkowe wołają je przez `uses: ./…`.
# Strażnik pyta PRAWDZIWY skrypt bramki, ale czyta go z `ci.yml`, więc tylko
# mutacja dowodzi, że zapala się, gdy akcje wypadną z filtra.
BRAMKA_CI = ".github/workflows/ci.yml"
BRAMKA_AKCJE_TEST = "test_zmiana_lokalnej_akcji_uruchamia_joby_ktore_jej_uzywaja"
# Ciężkie joby wąskiego obszaru zawężane TYLKO na PR-ach (decyzja 24.09.2026).
# Strażnicy pytają prawdziwy skrypt bramki z `ci.yml`; mutacje dowodzą, że
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
WDROZENIE_TEST = "TestDymnyNieUdajeCudzegoWydaniaTest"
# `/wydanie` bez sesji i CSRF (przegląd #1439). Mutacja wraca z trasą do
# pełnej grupy `web` i ma zapalić test braku `Set-Cookie`.
WYDANIE_TRASY = "bootstrap/app.php"
WYDANIE_TEST = "WydanieWystawiaPelnyShaTest"

# Obrazy bazowe przypięte do digestów (#952). Strażnik parsuje linie FROM
# w Dockerfile'ach; mutacja zdejmuje digest z obrazu kopii i ma go zapalić —
# dowód, że parser widzi też drugi Dockerfile, a nie tylko główny.
OBRAZ_KOPII = "docker/kopia/Dockerfile"
OBRAZY_DIGEST_TEST = "ObrazyBazowePrzypieteDoDigestowTest"

# Oryginał zdjęcia traci XMP (issue #1004). Test czyta fixture'y zapisane
# niezależną biblioteką — strażnik widzi odczyt pliku, więc kontrola dodatnia
# wyłącza samo czyszczenie XMP i test ma wtedy oblać.
USUN_GPS = "app/Domain/Media/UsunGps.php"
XMP_TEST = "OryginalTraciGpsZXmpTest"

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
# a nie wyrazu.
POLITYKA = "resources/legal/polityka-prywatnosci.md"
POLITYKA_CIASTECZKA_TEST = "PolitykaNazywaCiasteczkaUstawienTest"

# Cache manifestu Vite (#809). Strażnik czyta `docker/Caddyfile`: pliki
# z hashem w `/build/assets/*` dostają rok `immutable`, manifest `no-cache`.
# Mutacja wraca do dawnej, szerokiej reguły `/build/*` — tej, która dawała
# manifestowi bez hasha roczny cache — i test ma zapalić.
CADDYFILE = "docker/Caddyfile"
CACHE_MANIFESTU_TEST = "test_manifest_bez_hasha_nie_dostaje_rocznego_cache_assetow"

# Strażnik hosta magazynu R2 (D-255). Mutacja 1 przepuszcza endpoint bez
# jurysdykcji `eu` (i każdą inną jurysdykcję), mutacja 2 zdejmuje kotwicę
# końca, więc przechodzi host podszywający się sufiksem.
STRAZNIK_R2 = "app/Support/Storage/DozwolonyHostR2.php"
STRAZNIK_R2_TEST = "test_straznik_r2_odrzuca_host_spoza_wzoru"
WZOR_R2 = r"""'/^[0-9a-f]{32}\.eu\.r2\.cloudflarestorage\.com$/'"""

# Timeout własnej blokady po udanej rezerwacji u rodzica (#1393). Test jest
# behawioralny; mutacja przywraca `return false` z `catch`, który pomijał
# zwrot miejsca do wspólnej puli poczty.
BUDZET_POCZTY = "app/Domain/Security/DziennyBudzetListow.php"
BUDZET_POCZTY_TEST = "test_timeout_wlasnej_blokady_oddaje_miejsce_we_wspolnej_puli"
# Akcja zapisu do zeszytu sama sprawdza prawo do zeszytu (#942). Test woła
# akcję BEZPOŚREDNIO, z pominięciem kontrolera, więc walidacja
# `collection_id` w kontrolerze go nie ratuje. Mutacja zdejmuje `authorize`
# osobno z akcji przepisu i z akcji wpisu — każda ma zapalić ten sam test.
ZAPIS_PRZEPISU = "app/Domain/Collections/Actions/SaveRecipeToCollection.php"
ZAPIS_WPISU = "app/Domain/Collections/Actions/SavePostToCollection.php"
ZAPIS_CUDZY_ZESZYT_TEST = "ZapisDoCudzegoZeszytuWAkcjiTest"
AUTORYZACJA_ZESZYTU = "        Gate::forUser($user)->authorize('update', $collection);\n"
# Kontrolery Google i Facebooka są adapterami nad `WejdzPrzezDostawce` (#1035).
# Mutacja wkleja do kontrolera Google własne `Auth::login` przed odpowiedzią —
# kopię wspólnej reguły wejścia — i ma zapalić strażnika architektury.
KONTROLER_GOOGLE = "app/Http/Controllers/Auth/GoogleLoginController.php"
ADAPTERY_DOSTAWCOW_TEST = "KontroleryDostawcowSaAdapteramiTest"
WPUSC_GOOGLE = "        return match ($this->wejscie()->wpusc($request, $user)) {\n"

# Awaria eksportu danych dociera do kolejki (#822). Testy łapały kiedyś
# `\Throwable`, więc połykały własne `fail()`; job bez `throw $e` po
# `markFailed()` przechodził, a kolejka nie wiedziała o porażce. Mutacja
# zdejmuje ten rethrow — oba testy mają oblać na braku wyjątku.
EKSPORT_JOB = "app/Jobs/GenerateUserExport.php"
EKSPORT_PORAZKA_TEST = "test_niepowodzenie_ustawia_status_failed_z_powodem|test_powod_niepowodzenia_eksportu_nigdy"
EKSPORT_RETHROW = "            $this->markFailed($export, $this->reasonFor($e));\n\n            throw $e;\n"


def digest(path):
    return hashlib.md5(path.read_bytes()).hexdigest()


def run_test(name, expected_success):
    result = subprocess.run(
        ["php", "artisan", "test", "--filter=" + name, "--no-ansi"],
        text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        timeout=180,
    )
    print(result.stdout, flush=True)
    if (result.returncode == 0) != expected_success:
        raise RuntimeError("Nieoczekiwany wynik testu: " + name)
    if not expected_success and "FAILED" not in result.stdout:
        raise RuntimeError("Brak dowodu niezaliczonej asercji; sama awaria procesu nie wystarczy.")


def replace_once(source, old, new):
    if source.count(old) != 1:
        raise RuntimeError("Kontrola nie znalazła dokładnie jednego miejsca mutacji.")
    return source.replace(old, new, 1)


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


def akcje_poza_filtrem_widoku(source):
    """KONTROLA DODATNIA: wyjmij `.github/actions/` z filtra warstwy widoku.

    Zmiana lokalnej akcji znowu daje `widok=false`, więc joby przeglądarkowe,
    które jej używają, byłyby pominięte. Strażnik bramki ma zapalić.
    """
    return replace_once(
        source,
        r"|\.github/(workflows/ci\.yml|actions/))'",
        r"|\.github/workflows/ci\.yml)'",
    )


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


def widok_zawezany_poza_pr(source):
    """KONTROLA DODATNIA: filtr widoku zawęża także na `main`."""
    return replace_once(
        source,
        """if [ "${ZDARZENIE:-}" != "pull_request" ] || grep -qE '""",
        """if grep -qE '""",
    )


checks = [
    ("Format UUID", CONTROLLER, COLLECTION_TEST,
     lambda s: replace_once(s, "'bail', 'nullable', 'uuid',", "'bail', 'nullable',")),
    ("Własność zeszytu", CONTROLLER, COLLECTION_TEST,
     lambda s: replace_once(s, "Rule::exists('collections', 'id')->where('owner_id', $request->user()->getKey())", "Rule::exists('collections', 'id')")),
    ("Komunikat po powrocie", LAYOUT, COLLECTION_TEST, remove_notice),
    ("Podpis co najmniej 18 px", CSS, COMPOSER_TEST, smaller_help),
    ("Akcja GitHuba na ruchomym tagu", AKCJA_PHP, AKCJE_SHA_TEST, akcja_php_na_ruchomym_tagu),
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
    ("Cofnięcie CHECK-a kontaktu bez odmowy przy sierotach", KONTAKT_MIGRACJA, KONTAKT_MIGRACJA_TEST,
     lambda s: replace_once(s, "        if ($istniejaSieroty) {\n", "        if (false && $istniejaSieroty) {\n")),
    ("Cofnięcie znaczników odpowiedzi bez odmowy", KONTAKT_ZNACZNIKI, KONTAKT_ZNACZNIKI_TEST,
     lambda s: replace_once(s, "        if (DB::table('contact_message_replies')->whereNotNull('reply_key')->exists()) {\n", "        if (false) {\n")),
    ("Lokalne akcje poza filtrem widoku", BRAMKA_CI, BRAMKA_AKCJE_TEST,
     akcje_poza_filtrem_widoku),
    ("Dockerfile poza wzorcem builda obrazu", BRAMKA_CI, BRAMKA_WEJSCIA_TEST,
     dockerfile_poza_wzorcem_obrazu),
    ("Pliki grupy wyścigów poza wzorcem joba", BRAMKA_CI, BRAMKA_WEJSCIA_TEST,
     grupa_wyscigow_poza_wzorcem),
    ("Ciężkie joby zawężane także poza PR-em", BRAMKA_CI, BRAMKA_POZA_PR_TEST,
     zawezanie_takze_poza_pr),
    ("Wzorzec przyrządu #605 łapie każdą zmianę", BRAMKA_CI, BRAMKA_OBOK_TEST,
     wzorzec_przyrzadu_lapie_wszystko),
    ("Filtr widoku zawężany także poza PR-em", BRAMKA_CI, WIDOK_POZA_PR_TEST,
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
    ("/wydanie z sesją i ciasteczkami", WYDANIE_TRASY, WYDANIE_TEST,
     lambda s: replace_once(s, "Route::get('/wydanie', WydanieController::class)->name('wydanie');", "Route::middleware('web')->get('/wydanie', WydanieController::class)->name('wydanie');")),
    ("Obraz bazowy bez digestu", OBRAZ_KOPII, OBRAZY_DIGEST_TEST,
     bez_digestu_obrazu_kopii),
    ("Oryginał zdjęcia z nietkniętym XMP", USUN_GPS, XMP_TEST,
     lambda s: replace_once(s, "return self::usunXmp(self::usunGpsZExif($bajty));", "return self::usunGpsZExif($bajty);")),
    ("Nieudane wgranie bez kompensacji plików", KOMPENSACJA_UPLOADU, KOMPENSACJA_UPLOADU_TEST,
     lambda s: replace_once(s, "            $this->posprzatajPoNieudanymZapisie($disk, $objectKey, $dyskWariantow);\n", "")),
    ("Decyzja moderacyjna tworzona poza listą", POWIADOM_O_DECYZJI, DECYZJA_Z_CZLOWIEKIEM_TEST,
     lambda s: replace_once(s, "final class NotifyModerationDecision\n{\n", "final class NotifyModerationDecision\n{\n    // ModerationAction::create( — mutacja kontroli dodatniej\n")),
    ("Polityka bez nazwy ciasteczka motywu", POLITYKA, POLITYKA_CIASTECZKA_TEST,
     lambda s: replace_once(s, "ciemnego motywu (`motyw`)", "ciemnego motywu")),
    ("Manifest Vite z rocznym cache assetów", CADDYFILE, CACHE_MANIFESTU_TEST,
     lambda s: replace_once(s, "@viteAssets path /build/assets/*", "@viteAssets path /build/*")),
    ("Strażnik R2 bez segmentu eu", STRAZNIK_R2, STRAZNIK_R2_TEST,
     lambda s: replace_once(s, WZOR_R2, WZOR_R2.replace(r"\.eu\.", r"(\.[a-z]+)?\."))),
    ("Strażnik R2 bez kotwicy końca", STRAZNIK_R2, STRAZNIK_R2_TEST,
     lambda s: replace_once(s, WZOR_R2, WZOR_R2.replace("$/", "/"))),
    ("Reguła zdjęć Cloudflare bez warunku ciasteczka", REGULY_CF, REGULY_CF_TEST,
     lambda s: replace_once(s, REGULA_ZDJEC_CIASTKO, REGULA_ZDJEC_CIASTKO.replace(' and http.cookie eq \\"\\"', ""))),
    ("Timeout blokady funkcji nie oddaje miejsca wspólnej puli", BUDZET_POCZTY, BUDZET_POCZTY_TEST,
     lambda s: replace_once(s, "            $zajete = false;\n", "            return false;\n")),
    ("Zapis przepisu do cudzego zeszytu", ZAPIS_PRZEPISU, ZAPIS_CUDZY_ZESZYT_TEST,
     lambda s: replace_once(s, AUTORYZACJA_ZESZYTU, "")),
    ("Zapis wpisu do cudzego zeszytu", ZAPIS_WPISU, ZAPIS_CUDZY_ZESZYT_TEST,
     lambda s: replace_once(s, AUTORYZACJA_ZESZYTU, "")),
    ("Awaria eksportu bez przekazania wyjątku kolejce", EKSPORT_JOB, EKSPORT_PORAZKA_TEST,
     lambda s: replace_once(s, EKSPORT_RETHROW, "            $this->markFailed($export, $this->reasonFor($e));\n\n")),
    ("Kontroler Google z własną kopią wejścia na konto", KONTROLER_GOOGLE, ADAPTERY_DOSTAWCOW_TEST,
     lambda s: replace_once(s, WPUSC_GOOGLE, "        \\Illuminate\\Support\\Facades\\Auth::login($user, remember: true);\n\n" + WPUSC_GOOGLE)),
]

run_test(COLLECTION_TEST, True)
run_test(COMPOSER_TEST, True)
run_test(AKCJE_SHA_TEST, True)
run_test(STRAZNIK_TEKSTU_TEST, True)
run_test(OBRAZ_ASSETOW_TEST, True)
run_test(MIGRACJA_2FA_TEST, True)
run_test(PIERWSZY_EKRAN_TEST, True)
run_test(KONTAKT_MIGRACJA_TEST, True)
run_test(KONTAKT_ZNACZNIKI_TEST, True)
run_test(BRAMKA_AKCJE_TEST, True)
run_test(BRAMKA_WEJSCIA_TEST, True)
run_test(BRAMKA_POZA_PR_TEST, True)
run_test(BRAMKA_OBOK_TEST, True)
run_test(WIDOK_POZA_PR_TEST, True)
run_test(PODZIAL_WIERSZY_TEST, True)
run_test(WDROZENIE_TEST, True)
run_test(WYDANIE_TEST, True)
run_test(OBRAZY_DIGEST_TEST, True)
run_test(XMP_TEST, True)
run_test(KOMPENSACJA_UPLOADU_TEST, True)
run_test(DECYZJA_Z_CZLOWIEKIEM_TEST, True)
run_test(POLITYKA_CIASTECZKA_TEST, True)
run_test(CACHE_MANIFESTU_TEST, True)
run_test(STRAZNIK_R2_TEST, True)
run_test(REGULY_CF_TEST, True)
run_test(ZAPIS_CUDZY_ZESZYT_TEST, True)
run_test(EKSPORT_PORAZKA_TEST, True)
run_test(ADAPTERY_DOSTAWCOW_TEST, True)
with tempfile.TemporaryDirectory(prefix="kuking-kontrola-") as directory:
    backup = Path(directory) / "oryginal"
    for label, filename, test, mutate in checks:
        path = ROOT / filename
        subprocess.run(["cp", str(path), str(backup)], check=True)
        before = digest(path)
        try:
            path.write_text(mutate(path.read_text()))
            changed = digest(path)
            if changed == before:
                raise RuntimeError("Mutacja nie zmieniła źródła.")
            print(f"{label}: przed={before}, mutacja={changed}", flush=True)
            run_test(test, False)
        finally:
            subprocess.run(["cp", str(backup), str(path)], check=True)
            restored = digest(path)
            print(f"{label}: po przywróceniu={restored}", flush=True)
            if restored != before:
                raise RuntimeError("Przywrócone źródło różni się od oryginału.")
        run_test(test, True)
# Liczebnik bierzemy z `len(checks)`, nie z tekstu. Wcześniej stało tu wpisane
# słowo „Pięć": po dodaniu szóstego wpisu CI nadal wypisywałoby „Pięć", a to
# jedyne miejsce, z którego człowiek czyta wynik tego kroku.
print(f"{len(checks)} kontroli negatywnych wykryło regresje; źródła przywrócone.")
