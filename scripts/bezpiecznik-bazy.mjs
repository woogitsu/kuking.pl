/**
 * =============================================================================
 *  Kuking.pl — JEDEN bezpiecznik przed `migrate:fresh` na cudzej bazie (#736)
 * =============================================================================
 *
 *  CO ROBIĄ SKRYPTY, KTÓRE TO WOŁAJĄ
 *  Kilkanaście przyrządów pomiarowych w `scripts/*.mjs` robi
 *  `php artisan migrate:fresh --seed`, czyli KASUJE CAŁĄ ZAWARTOŚĆ bazy,
 *  na którą wskazuje `DB_DATABASE`. Uruchomienie takiego skryptu w powłoce,
 *  w której ktoś wcześniej wyeksportował `DB_DATABASE`, kasuje cudzą pracę.
 *
 *  DLACZEGO POWSTAŁ
 *  Do dziś dwa skrypty miały listę `BAZY_ZAKAZANE = ['kuking', 'kuking_test']`,
 *  a pozostałych czternaście nie miało żadnej kontroli — brały `DB_DATABASE`
 *  i szły. Lista była przy tym listą ZAKAZÓW: wszystko, czego na niej nie ma,
 *  było dozwolone.
 *
 *  Po #736 i #920 nazwa bazy testowej prawie nigdzie nie brzmi już dokładnie
 *  `kuking_test`: w worktree jest `kuking_test_<worktree>`, a w kopii bez
 *  `.git` (runtime floty) `kuking_test_kat_<katalog>_<skrót>` —
 *  `tests/nazwa-bazy.php`. Lista dosłownych zakazów przestała więc trafiać
 *  niemal wszędzie, zostając w kodzie jako zabezpieczenie, którego w praktyce
 *  już nie ma. Zabezpieczenie, które przestaje działać w prawie wszystkich
 *  przypadkach naraz, a wygląda na obecne, jest gorsze niż jego brak — bo
 *  człowiek na nie liczy. Dlatego niżej stoją RODZINY nazw (`^kuking_test(_|$)`),
 *  które łapią i gołe `kuking_test` głównego checkoutu, i każdy jego wariant.
 *
 *  ZASADA — TA SAMA, CO W `scripts/cleanup-test-dbs.sh`
 *  NIE WIEM, CZYJA TO BAZA, WIĘC ODMAWIAM.
 *
 *  Bezpiecznik rozpoznaje RODZINY nazw, nie pojedyncze łańcuchy, i wpuszcza
 *  wyłącznie to, co jest jednorazową bazą pomiarową. Nazwa nierozpoznana jest
 *  odmową, a nie zgodą — bo koszt pomyłki jest niesymetryczny: odmowa kosztuje
 *  jedno polecenie z `DB_DATABASE=…`, a zgoda kosztuje cudzy dzień pracy
 *  i jest nieodwracalna.
 *
 *  CO PRZECHODZI
 *    1. własna baza domyślna skryptu (np. `kuking_kafel_pomiar`),
 *    2. jej wariant z sufiksem (`kuking_kafel_pomiar_wt_e`) — żeby dwie osoby
 *       mogły mierzyć równolegle,
 *    3. ogólna rodzina baz jednorazowych: nazwa kończąca się na `_pomiar`
 *       albo zaczynająca od `kuking_qa_` (konwencja z `scripts/kroki-kreatora.mjs`).
 *
 *  CO NIE PRZECHODZI NIGDY — nawet gdyby pasowało do punktów wyżej
 *    `kuking` (deweloperska), `kuking_test*` (bazy testowe kopii roboczych),
 *    `kuking_race*` (grupa `dwa-polaczenia`), `proba_wycofania*`,
 *    `proba_odtworzenia*`, `kuking_zrodlo_proby*`, `railway*`, `postgres`,
 *    `template0`, `template1`. Odmowa jest tu silniejsza od zgody, więc
 *    `kuking_test_pomiar` też się nie prześlizgnie.
 *
 *  Do tego: `APP_ENV=production` przerywa start bezwarunkowo, a nazwa musi być
 *  poprawnym identyfikatorem PostgreSQL-a bez cudzysłowu.
 */

import { execFileSync } from 'node:child_process';

/** Rodziny nazw, których ten bezpiecznik nie wpuści nigdy. */
const RODZINY_CHRONIONE = [
    { wzor: /^kuking$/, czym: 'baza deweloperska (dane, na których ktoś pracuje)' },
    { wzor: /^kuking_test(_|$)/, czym: 'baza testowa kopii roboczej (tests/nazwa-bazy.php)' },
    { wzor: /^kuking_race(_|$)/, czym: 'baza grupy `dwa-polaczenia` (D-105)' },
    { wzor: /^proba_wycofania(_|$)/, czym: 'baza próby wycofania migracji' },
    { wzor: /^proba_odtworzenia(_|$)/, czym: 'baza próby odtworzenia kopii' },
    { wzor: /^kuking_zrodlo_proby(_|$)/, czym: 'baza źródłowa próby odtworzenia' },
    { wzor: /^railway/, czym: 'baza Railwaya' },
    { wzor: /^(postgres|template0|template1)$/, czym: 'baza systemowa PostgreSQL-a' },
];

/**
 * Rodzina chroniona, do której należy nazwa bazy, albo `undefined`.
 * Wydzielone, żeby test mógł zapytać o to samo, o co pyta bezpiecznik,
 * także nazwy baz podawane przyrządom w `scripts/check.sh`.
 * @param {string} nazwa
 * @returns {{wzor: RegExp, czym: string} | undefined}
 */
export function rodzinaChroniona(nazwa) {
    const porownywana = String(nazwa).toLowerCase();

    return RODZINY_CHRONIONE.find(({ wzor }) => wzor.test(porownywana));
}

/**
 * Ogólna rodzina baz jednorazowych, niezwiązana z konkretnym skryptem.
 * Ta sama lista co `KUKING_RODZINY_JEDNORAZOWE` w
 * scripts/fixtures/baza-pomiarowa.php — jedna reguła w dwóch językach (D-243).
 * `kuking_port_*` to bazy portowe z `ci.yml` (`kuking_port_panel`,
 * `kuking_port_referrer`).
 */
const RODZINY_JEDNORAZOWE = [/_pomiar$/, /^kuking_qa_/, /^kuking_port_/];

/**
 * Ustala nazwę bazy pomiarowej albo ODMAWIA startu.
 *
 * Świadomie rzuca wyjątkiem zamiast zwracać `null`: skrypt, który przeoczy
 * zwrócony błąd, poleciałby dalej i zrobił `migrate:fresh`. Wyjątku nie da
 * się przeoczyć przez pomyłkę.
 *
 * @param {object} opcje
 * @param {string} opcje.domyslna   baza własna skryptu, np. 'kuking_kafel_pomiar'
 * @param {string} opcje.skrypt     nazwa pliku do komunikatu, np. 'scripts/kafel-dodawania.mjs'
 * @param {object} [opcje.srodowisko] podmiana `process.env` (używa tego test)
 * @returns {string} nazwa bazy, na której wolno pracować
 */
export function ustalBazePomiarowa({ domyslna, skrypt, srodowisko = process.env }) {
    if (typeof domyslna !== 'string' || domyslna === '') {
        throw new Error('bezpiecznik-bazy: brak nazwy bazy domyślnej skryptu.');
    }

    const gdzie = skrypt ? `${skrypt}: ` : '';

    // Produkcja przerywa start bezwarunkowo i przed czymkolwiek innym.
    // `migrate:fresh` na produkcji nie ma poprawnego zastosowania.
    if ((srodowisko.APP_ENV || '').toLowerCase() === 'production') {
        throw new Error(
            `${gdzie}ODMAWIAM STARTU: APP_ENV=production. Ten skrypt robi `
            + '`migrate:fresh`, czyli kasuje całą zawartość bazy.',
        );
    }

    const podana = (srodowisko.DB_DATABASE || '').trim();
    const baza = podana === '' ? domyslna : podana;

    // Małe litery: PostgreSQL składa identyfikator bez cudzysłowu do małych
    // liter, więc `KUKING_TEST_X` i `kuking_test_x` to ta sama baza. Bez tego
    // wielka litera omijałaby cały bezpiecznik.
    const porownywana = baza.toLowerCase();

    if (!/^[a-z][a-z0-9_]*$/.test(porownywana)) {
        throw new Error(
            `${gdzie}ODMAWIAM STARTU: „${baza}" nie jest poprawną nazwą bazy `
            + 'PostgreSQL-a (dozwolone: litera, potem litery, cyfry i podkreślenia).',
        );
    }

    const chroniona = rodzinaChroniona(porownywana);

    if (chroniona) {
        throw new Error(
            `${gdzie}ODMAWIAM STARTU: „${baza}" to ${chroniona.czym}. Ten skrypt `
            + 'robi `migrate:fresh`, czyli skasowałby wszystko, co w niej stoi.\n'
            + `  Uruchom bez DB_DATABASE (użyje „${domyslna}") albo wskaż własną `
            + `bazę jednorazową:\n    DB_DATABASE=${domyslna}_moja node ${skrypt || 'scripts/…mjs'}`,
        );
    }

    const wlasna = porownywana === domyslna.toLowerCase()
        || porownywana.startsWith(`${domyslna.toLowerCase()}_`);
    const jednorazowa = RODZINY_JEDNORAZOWE.some((wzor) => wzor.test(porownywana));

    if (!wlasna && !jednorazowa) {
        // SEDNO: nazwa nierozpoznana to ODMOWA, nie zgoda. Nie wiemy, czyja
        // jest ta baza ani co w niej stoi, a za chwilę mielibyśmy ją skasować.
        throw new Error(
            `${gdzie}ODMAWIAM STARTU: nie rozpoznaję bazy „${baza}" jako `
            + 'jednorazowej bazy pomiarowej, a ten skrypt robi na niej '
            + '`migrate:fresh`.\n'
            + '  Wpuszczam wyłącznie: bazę domyślną tego skryptu '
            + `(„${domyslna}"), jej wariant („${domyslna}_cos") albo nazwę `
            + 'z rodziny jednorazowych („…_pomiar", „kuking_qa_…", „kuking_port_…").\n'
            + `    DB_DATABASE=${domyslna}_moja node ${skrypt || 'scripts/…mjs'}`,
        );
    }

    return baza;
}

/**
 * Zakłada bazę, jeśli jeszcze jej nie ma. `migrate:fresh` zakłada TABELE,
 * nie bazę — skrypt wskazujący nieistniejącą bazę pada komunikatem o braku
 * połączenia, który niczego nie tłumaczy.
 *
 * Celowo połyka błąd: `createdb` na istniejącej bazie kończy się niezerowo
 * i to jest tu normalny przebieg, a nie awaria. Jeśli baza naprawdę nie
 * powstała, powie o tym dopiero `migrate:fresh` — i powie prawdę, bo spróbuje
 * się do niej połączyć.
 *
 * @param {string} baza nazwa PO przejściu przez `ustalBazePomiarowa()`
 */
export function zalozBazeJesliTrzeba(baza, srodowisko = process.env) {
    try {
        execFileSync('createdb', [baza], {
            stdio: 'ignore',
            env: {
                ...srodowisko,
                PGHOST: srodowisko.DB_HOST || '127.0.0.1',
                PGPORT: srodowisko.PGPORT || srodowisko.DB_PORT || '5432',
                PGUSER: srodowisko.DB_USERNAME || 'kuking',
                PGPASSWORD: srodowisko.DB_PASSWORD || 'kuking',
            },
        });
    } catch { /* baza już istnieje albo nie ma `createdb` — powie migracja */ }
}

/**
 * Wygoda dla skryptów, które budują `env()` do `execFileSync`: zwraca kopię
 * środowiska z JUŻ SPRAWDZONĄ nazwą bazy.
 */
export function srodowiskoZBezpiecznikiem({ domyslna, skrypt, dodatkowe = {}, srodowisko = process.env }) {
    return {
        ...srodowisko,
        DB_DATABASE: ustalBazePomiarowa({ domyslna, skrypt, srodowisko }),
        ...dodatkowe,
    };
}
