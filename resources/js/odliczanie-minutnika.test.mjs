import test from 'node:test';
import assert from 'node:assert/strict';
import {
    DODATKOWY_MINUTNIK_MAX_MINUT, kluczStanu, nowyTerminPoDodaniu, odczytajTermin, pozostaloSekund, sprawdzDodatkoweMinuty,
    zapiszStan,
} from './minutnik-krok.js';
import {utworzOdliczanie} from './odliczanie-minutnika.js';
import {minutnikiKolejki} from './kolejka-gotowania.js';

const ID_KROKU = '01234567-89ab-cdef-0123-456789abcdef';
const SKROT = 'f'.repeat(64);

/*
 * Dodatkowy czas przy minutniku kroku (#2458) na KONTROLOWANYM zegarze:
 * `zegar.ms` to zegar monotoniczny, planista trzyma interwały i pozwala
 * odpalić dokładnie ten callback, który chcemy (także „spóźniony" po jego
 * wyczyszczeniu — jak w przeglądarce, gdy zamrożona karta budzi się po czasie).
 */
function srodowisko() {
    const zegar = {ms: 1_000_000};
    const epoka = {ms: 1_700_000_000_000};
    const interwaly = new Map();
    let nastepny = 1;
    const zapisy = {stan: null, usuniec: 0, zapisow: 0};
    const log = {pokaz: [], koniec: 0};
    let odliczanie;

    const planista = {
        ustaw: (funkcja) => {
            const id = nastepny++;
            interwaly.set(id, funkcja);

            return id;
        },
        wyczysc: (id) => {
            interwaly.delete(id);
        },
    };

    odliczanie = utworzOdliczanie({
        teraz: () => zegar.ms,
        planista,
        pokaz: (sekundy) => log.pokaz.push(sekundy),
        przyKoncu: () => {
            log.koniec += 1;
        },
        zapisz: (termin) => {
            zapisy.zapisow += 1;
            zapisy.stan = zapiszStan(2400, epoka.ms + (termin - zegar.ms), {stepId: ID_KROKU, fingerprint: SKROT, krokPierwotny: 3});
        },
        usunZapis: () => {
            zapisy.usuniec += 1;
            zapisy.stan = null;
        },
    });

    return {
        zegar, epoka, interwaly, zapisy, log, odliczanie,
        /** Mija `ms` czasu i odpala wszystkie ŻYWE interwały (co sekundę, jak setInterval). */
        uplyw(ms) {
            for (let i = 0; i < Math.ceil(ms / 1000); i++) {
                zegar.ms += 1000;
                epoka.ms += 1000;
                for (const funkcja of [...interwaly.values()]) funkcja();
            }
        },
    };
}

test('dodatkowe minuty: poprawne dają sekundy, także z odstępami; limit to 3 godziny', () => {
    assert.deepEqual(sprawdzDodatkoweMinuty('5'), {sekundy: 300});
    assert.deepEqual(sprawdzDodatkoweMinuty(' 12 '), {sekundy: 720});
    assert.deepEqual(sprawdzDodatkoweMinuty(String(DODATKOWY_MINUTNIK_MAX_MINUT)), {sekundy: DODATKOWY_MINUTNIK_MAX_MINUT * 60});
});

test('dodatkowe minuty: puste, zero, ujemne, ułamki, nieskończone i ponad limit nie zmieniają terminu', () => {
    const zle = ['', '   ', '0', '00', '-5', '- 5', '1.5', '1,5', 'pięć', '5 min', '1e3', 'Infinity', 'NaN', '٣', '99999999999999999999999', String(DODATKOWY_MINUTNIK_MAX_MINUT + 1), null, undefined];

    for (const wpisane of zle) {
        const wynik = sprawdzDodatkoweMinuty(wpisane);

        assert.equal(wynik.sekundy, undefined, `„${wpisane}" przeszło jako poprawne`);
        assert.equal(typeof wynik.blad, 'string', `„${wpisane}" bez komunikatu`);
        // Komunikat mówi, co zrobić: podaje przykład albo granicę.
        assert.match(wynik.blad, /Wpisz|można dodać/);
    }
});

test('nowy termin: do POZOSTAŁEGO czasu, a po upływie — od teraz, nigdy od pełnych 40 minut', () => {
    const teraz = 5_000_000;
    // 2 minuty zostały, dodajemy 5 -> 7 minut.
    assert.equal(pozostaloSekund(nowyTerminPoDodaniu(teraz + 120_000, teraz, 300), teraz), 420);
    // Termin minął 30 s temu -> dokładnie 5 minut od teraz.
    assert.equal(pozostaloSekund(nowyTerminPoDodaniu(teraz - 30_000, teraz, 300), teraz), 300);
    // Granica: termin równo teraz -> 5 minut, nie 0 i nie 40.
    assert.equal(pozostaloSekund(nowyTerminPoDodaniu(teraz, teraz, 300), teraz), 300);
});

test('aktywny minutnik z 2 minutami po dodaniu 5 ma 7 i nie restartuje pełnych 40', () => {
    const s = srodowisko();
    s.odliczanie.uruchom(s.zegar.ms + 2400_000); // 40 minut autora
    s.uplyw(2280_000); // minęło 38 minut -> zostały 2

    assert.equal(s.log.pokaz.at(-1), 120);
    assert.equal(s.odliczanie.dodaj(300), 'przedluzone');

    assert.equal(s.log.pokaz.at(-1), 420);
    assert.equal(s.interwaly.size, 1, 'Dodanie czasu zostawiło drugi interwał.');
    assert.equal(s.odliczanie.aktywne(), true);
    assert.equal(s.log.koniec, 0);
    // Zapis ma NOWY termin, czas autora bez zmian.
    const stan = odczytajTermin(s.zapisy.stan, s.epoka.ms, s.zegar.ms);
    assert.equal(pozostaloSekund(stan.terminMonotoniczny, s.zegar.ms), 420);
    assert.equal(stan.sekundyCalkiem, 2400);
});

test('po końcu dodatkowe minuty odliczają od kliknięcia, a pełny restart nie jest potrzebny', () => {
    const s = srodowisko();
    s.odliczanie.uruchom(s.zegar.ms + 2400_000);
    s.uplyw(2400_000);

    assert.equal(s.log.koniec, 1, 'Kontrola: koniec odliczania powinien zadzwonić raz.');
    assert.equal(s.odliczanie.aktywne(), false);
    assert.equal(s.zapisy.stan, null);

    s.uplyw(60_000); // człowiek ogląda potrawę minutę
    assert.equal(s.odliczanie.dodaj(300), 'wznowione');

    assert.equal(s.log.pokaz.at(-1), 300);
    assert.equal(s.odliczanie.aktywne(), true);
    assert.equal(pozostaloSekund(odczytajTermin(s.zapisy.stan, s.epoka.ms, s.zegar.ms).terminMonotoniczny, s.zegar.ms), 300);

    s.uplyw(300_000);
    assert.equal(s.log.koniec, 2, 'Nowe odliczanie ma zadzwonić dokładnie raz.');
});

test('zatwierdzenie tuż przy upływie terminu nie daje podwójnego interwału ani alarmu', () => {
    const s = srodowisko();
    s.odliczanie.uruchom(s.zegar.ms + 10_000);
    // Zegar doszedł do terminu, ale interwał jeszcze nie zdążył odpalić (mija w tej chwili).
    s.zegar.ms += 10_000;
    s.epoka.ms += 10_000;
    const staryCallback = [...s.interwaly.values()][0];

    assert.equal(s.odliczanie.dodaj(300), 'przedluzone');
    assert.equal(s.interwaly.size, 1, 'Zostały dwa interwały.');

    // Spóźniony callback STAREGO odliczania (budzi się po wyczyszczeniu) nie kończy nowego.
    staryCallback();
    staryCallback();
    assert.equal(s.log.koniec, 0, 'Stary callback zakończył nowe odliczanie.');
    assert.equal(s.odliczanie.aktywne(), true);
    assert.equal(s.log.pokaz.at(-1), 300);

    s.uplyw(300_000);
    assert.equal(s.log.koniec, 1, 'Alarm zadzwonił nie raz.');
});

test('anulowanie minutnika usuwa także przedłużony zapis i nic później nie dzwoni', () => {
    const s = srodowisko();
    s.odliczanie.uruchom(s.zegar.ms + 600_000);
    s.odliczanie.dodaj(300);
    assert.notEqual(s.zapisy.stan, null);

    s.odliczanie.zatrzymaj();

    assert.equal(s.zapisy.stan, null);
    assert.equal(s.interwaly.size, 0);
    s.uplyw(2400_000);
    assert.equal(s.log.koniec, 0);
});

test('przeładowanie odtwarza NOWY termin, a kolejka widzi ten sam minutnik z tym samym terminem', () => {
    const s = srodowisko();
    s.odliczanie.uruchom(s.zegar.ms + 600_000);
    s.odliczanie.dodaj(300);

    // „Nowa strona": zegar monotoniczny od zera, ten sam zegar ścienny.
    const nowyZegar = 400;
    const przywrocony = odczytajTermin(s.zapisy.stan, s.epoka.ms, nowyZegar);
    assert.equal(pozostaloSekund(przywrocony.terminMonotoniczny, nowyZegar), 900);

    // Kolejka: ten sam klucz i ten sam zapis (bez drugiego mechanizmu).
    const klucz = kluczStanu('sernik', '3', ID_KROKU, SKROT);
    const wpisy = minutnikiKolejki([klucz, 'cos.innego'], ['sernik']);
    assert.equal(wpisy.length, 1);
    const wKolejce = odczytajTermin(s.zapisy.stan, s.epoka.ms, nowyZegar);
    assert.equal(wKolejce.terminMonotoniczny, przywrocony.terminMonotoniczny);
    assert.equal(wKolejce.sekundyCalkiem, 2400, 'Czas autora w zapisie ma zostać bez zmian.');
});

test('minutnik innego kroku nie jest ruszany: dodawanie dotyczy tylko tego właściciela odliczania', () => {
    const a = srodowisko();
    const b = srodowisko();
    a.odliczanie.uruchom(a.zegar.ms + 600_000);
    b.odliczanie.uruchom(b.zegar.ms + 600_000);

    a.odliczanie.dodaj(300);

    assert.equal(b.log.pokaz.at(-1), 600);
    assert.equal(b.zapisy.zapisow, 0);
    assert.equal(b.interwaly.size, 1);
});
