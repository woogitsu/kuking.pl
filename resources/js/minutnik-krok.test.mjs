import test from 'node:test';
import assert from 'node:assert/strict';
import {pozostaloSekund, formatMinutySekundy, kluczStanu, kluczPoAlarmie, zapiszStan, odczytajStan, odczytajTermin, krokZKlucza, aktualnyKrokMinutnika, PRZETERMINOWANIE_NAJWYZEJ_MS, sprawdzMinutyWlasne, etykietaMinut, WLASNY_MINUTNIK_MAX_MINUT} from './minutnik-krok.js';

test('pozostaloSekund liczy z zegara monotonicznego, nie ze zegara sciennego (issue #751)', () => {
    // Start minutnika: 5 minut = 300 sekund, na dowolnym punkcie zegara
    // monotonicznego (nie zero -- performance.now() rzadko zaczyna od zera).
    const start = 123456;
    const termin = start + 300_000;

    // Naprawde minelo 100 sekund monotonicznego czasu -- zostalo 200.
    assert.equal(pozostaloSekund(termin, start + 100_000), 200);

    // KLUCZOWY DOWOD: gdyby liczenie oparto na Date.now() (zegarze sciennym),
    // korekta NTP w trakcie odliczania natychmiast zmienialaby wynik, mimo
    // ze naprawde uplynelo dokladnie tyle samo czasu. Ta funkcja w ogole
    // nie widzi zegara sciennego -- przyjmuje wylacznie odczyty zegara
    // monotonicznego, wiec korekta zegara systemowego nie ma tu jak wejsc.
    assert.equal(pozostaloSekund(termin, start + 100_000), 200);

    // Nie schodzi ponizej zera, gdy termin juz minal.
    assert.equal(pozostaloSekund(termin, start + 999_000), 0);
});

test('formatMinutySekundy pokazuje sekundy zawsze na dwoch cyfrach', () => {
    assert.equal(formatMinutySekundy(0), '0:00');
    assert.equal(formatMinutySekundy(5), '0:05');
    assert.equal(formatMinutySekundy(65), '1:05');
    assert.equal(formatMinutySekundy(3661), '61:01');
});

test('kluczStanu rozroznia przepis i krok, zeby minutniki sie nie mieszaly', () => {
    assert.notEqual(kluczStanu('zupa', 1), kluczStanu('zupa', 2));
    assert.notEqual(kluczStanu('zupa', 1), kluczStanu('kotlety', 1));
});

test('odczytajStan po przeladowaniu liczy nowy termin wzgledem SWIEZEGO performance.now() (issue #740)', () => {
    const zapis = zapiszStan(300, /* terminEpoka */ 1_000_000 + 200_000);

    // Strona zaladowala sie ponownie: performance.now() zaczyna od zera,
    // a od zapisania stanu minelo (na zegarze sciennym) 50 sekund.
    const stan = odczytajStan(zapis, /* terazEpoka */ 1_050_000, /* terazMonotoniczny */ 0);

    assert.ok(stan);
    // Zostalo 150 sekund odliczania (200 - 50) -- i to wzgledem NOWEGO
    // punktu zerowego zegara monotonicznego tej strony, nie starego.
    assert.equal(pozostaloSekund(stan.terminMonotoniczny, 0), 150);
});

test('odczytajStan zwraca null, gdy zapis jest pusty, uszkodzony albo termin juz minal', () => {
    assert.equal(odczytajStan(null, 0, 0), null);
    assert.equal(odczytajStan('{niepoprawny json', 0, 0), null);
    assert.equal(odczytajStan(zapiszStan(60, 1000), /* terazEpoka */ 5000, 0), null);
});

test('odczytajTermin nie gubi terminu, ktory minal w trakcie przeladowania (issue #1301)', () => {
    // Minutnik kroku 1 skonczyl sie 2 sekundy przed zaladowaniem kroku 2.
    const stan = odczytajTermin(zapiszStan(60, 1000), /* terazEpoka */ 3000, /* terazMonotoniczny */ 500);

    assert.ok(stan, 'Termin w przeszlosci musi wrocic, zeby alarm zdazyl zabrzmiec');
    assert.equal(stan.terminMonotoniczny, -1500);
    assert.equal(pozostaloSekund(stan.terminMonotoniczny, 500), 0);

    assert.equal(odczytajTermin(null, 0, 0), null);
    assert.equal(odczytajTermin('{niepoprawny json', 0, 0), null);
    assert.equal(odczytajTermin(JSON.stringify({sekundyCalkiem: 'x', terminEpoka: 1}), 0, 0), null);
});

test('odczytajTermin pomija minutnik porzucony dawno po terminie (przeglad #1301)', () => {
    // 20 minut w kroku 3, "Zakoncz gotowanie" bez "Anuluj", powrot po 3 godzinach.
    const terminEpoka = 1_000_000;
    const poTrzechGodzinach = terminEpoka + 3 * 60 * 60 * 1000;

    assert.equal(odczytajTermin(zapiszStan(1200, terminEpoka), poTrzechGodzinach, 0), null,
        'Zapis sprzed godzin to porzucony minutnik, nie alarm do zagrania');

    // Na granicy jeszcze dzwoni, tuz za nia juz nie.
    assert.ok(odczytajTermin(zapiszStan(1200, terminEpoka), terminEpoka + PRZETERMINOWANIE_NAJWYZEJ_MS, 0));
    assert.equal(odczytajTermin(zapiszStan(1200, terminEpoka), terminEpoka + PRZETERMINOWANIE_NAJWYZEJ_MS + 1, 0), null);
});

test('krokZKlucza odczytuje krok tylko z kluczy minutnika TEGO przepisu (issue #1301)', () => {
    assert.equal(krokZKlucza(kluczStanu('zupa', 2), 'zupa'), '2');
    assert.equal(krokZKlucza(kluczStanu('zupa', 12), 'zupa'), '12');
    assert.equal(krokZKlucza(kluczStanu('zupa-pomidorowa', 2), 'zupa'), null);
    assert.equal(krokZKlucza(kluczStanu('kotlety', 2), 'zupa'), null);
    assert.equal(krokZKlucza('kuking.cos-innego', 'zupa'), null);
    assert.equal(krokZKlucza(kluczStanu('zupa', ''), 'zupa'), null);
    assert.equal(krokZKlucza(null, 'zupa'), null);
});

test('2814: zakończenie kroku nie staje się drugim minutnikiem ani alarmem', () => {
    const id = '00000000-0000-4000-8000-000000000001';
    const klucz = kluczStanu('zupa', 1, id, 'a'.repeat(64));
    assert.equal(krokZKlucza(klucz, 'zupa'), `id_${id}_${'a'.repeat(64)}`);
    assert.equal(krokZKlucza(kluczPoAlarmie(klucz), 'zupa'), null);
    assert.notEqual(kluczPoAlarmie(klucz), kluczPoAlarmie(kluczStanu('zupa', 2, id, 'b'.repeat(64))));
});

test('2589: edycja i przeładowanie zachowują termin B, ale nie przypisują go nowemu krokowi 2', () => {
    const idA = '00000000-0000-4000-8000-000000000001';
    const idB = '00000000-0000-4000-8000-000000000002';
    const a = 'a'.repeat(64);
    const b = 'b'.repeat(64);
    const stanNaStart = {stepId: idB, fingerprint: b, krokPierwotny: 2};
    const zapis = zapiszStan(600, 1_600_000, stanNaStart);
    const klucz = kluczStanu('zupa', 2, idB, b);
    const poEdycji = [{id: idB, fingerprint: b}, {id: idA, fingerprint: a}];
    const odczyt = odczytajTermin(zapis, 1_060_000, 100);

    assert.equal(krokZKlucza(klucz, 'zupa'), `id_${idB}_${b}`);
    assert.equal(aktualnyKrokMinutnika(odczyt, poEdycji), 1, 'TIMER_2589_ZAMIANA_KROKOW');
    assert.equal(odczyt.krokPierwotny, 2);
    assert.equal(odczyt.sekundyCalkiem, 600);
    assert.equal(odczyt.terminMonotoniczny, 540_100, 'Edycja nie zmienia pierwotnego terminu');
    assert.equal(kluczStanu('zupa', 2, idA, a) === klucz, false, 'Nowy krok 2 nie nadpisuje licznika B');
    assert.equal(kluczStanu('zupa', 1, idB, b), klucz, 'Single i kolejka używają tego samego klucza czynności');

    assert.equal(aktualnyKrokMinutnika(odczyt, [{id: idB, fingerprint: 'c'.repeat(64)}]), null,
        'Zmieniona instrukcja lub czas nie są tą samą czynnością');
    assert.equal(aktualnyKrokMinutnika(odczyt, [{id: idA, fingerprint: a}]), null,
        'Usunięty krok nie wskazuje następcy pod dawnym numerem');
    assert.equal(aktualnyKrokMinutnika(odczytajTermin(zapiszStan(600, 1_600_000), 1_060_000, 100), poEdycji), null,
        'Stary zapis bez tożsamości nie może wskazać innej czynności');
    // Zmiana samego tytułu przepisu nie jest w odcisku kroku.
    assert.equal(aktualnyKrokMinutnika(odczyt, poEdycji), 1);
});

// --- Własny minutnik przy kroku bez czasu autora (issue #2595) ---

test('własny minutnik: poprawne minuty dają sekundy, także z odstępami', () => {
    assert.deepEqual(sprawdzMinutyWlasne('7'), {sekundy: 420});
    assert.deepEqual(sprawdzMinutyWlasne(' 20 '), {sekundy: 1200});
    assert.deepEqual(sprawdzMinutyWlasne('1'), {sekundy: 60});
    assert.deepEqual(sprawdzMinutyWlasne(String(WLASNY_MINUTNIK_MAX_MINUT)), {sekundy: 24 * 3600});
    assert.equal(etykietaMinut(420), '7 min');
});

test('własny minutnik: 0, ujemne, ponad 24 godziny i śmieci są odrzucone komunikatem po polsku', () => {
    for (const zle of ['0', '00', '-5', '-0', '1441', '99999', '', '   ', 'abc', '7,5', '7.5', '1e3', '5 min', null, undefined]) {
        const wynik = sprawdzMinutyWlasne(zle);
        assert.equal(wynik.sekundy, undefined, `„${zle}” nie może uruchomić odliczania`);
        assert.match(wynik.blad, /^[A-ZŻŹĆĄŚĘŁÓŃ].*\.$/u, `„${zle}” musi dać komunikat po polsku`);
        assert.match(wynik.blad, /minut|ujemna|liczb/);
    }

    assert.match(sprawdzMinutyWlasne('-5').blad, /ujemna/);
    assert.match(sprawdzMinutyWlasne('1441').blad, /1440 minut \(24 godziny\)/);
    assert.match(sprawdzMinutyWlasne('0').blad, /większą od zera/);
});

test('własny minutnik: ten sam przebieg co minutnik autora (zapis, odtworzenie, pas, koniec)', () => {
    const {sekundy} = sprawdzMinutyWlasne('7');
    const tozsamosc = {stepId: '00000000-0000-4000-8000-000000000002', fingerprint: 'b'.repeat(64), krokPierwotny: 2};
    const klucz = kluczStanu('zupa', 2, tozsamosc.stepId, tozsamosc.fingerprint);
    const startEpoka = 1_700_000_000_000;

    const zapis = zapiszStan(sekundy, startEpoka + sekundy * 1000, tozsamosc);
    assert.match(klucz, /^kuking\.minutnik\.zupa\.id_/);
    assert.equal(krokZKlucza(klucz, 'zupa'), `id_${tozsamosc.stepId}_${tozsamosc.fingerprint}`);

    // Przeładowanie po 100 s: zostaje 320 s, nie pełne 7 minut.
    const stan = odczytajStan(zapis, startEpoka + 100_000, 50);
    assert.equal(stan.sekundyCalkiem, 420);
    assert.equal(pozostaloSekund(stan.terminMonotoniczny, 50), 320);
    assert.equal(etykietaMinut(stan.sekundyCalkiem), '7 min');
    assert.equal(formatMinutySekundy(pozostaloSekund(stan.terminMonotoniczny, 50)), '5:20');

    // Pas innych kroków rozpoznaje tożsamość kroku; zmieniony odcisk nie dostaje cudzego alarmu.
    assert.equal(aktualnyKrokMinutnika(stan, [{id: 'x', fingerprint: 'y'}, {id: tozsamosc.stepId, fingerprint: tozsamosc.fingerprint}]), 2);
    assert.equal(aktualnyKrokMinutnika(stan, [{id: 'x', fingerprint: 'y'}, {id: tozsamosc.stepId, fingerprint: 'c'.repeat(64)}]), null);

    // Po terminie: spóźniony alarm jeszcze wraca, porzucony już nie.
    assert.equal(odczytajStan(zapis, startEpoka + 421_000, 0), null);
    assert.notEqual(odczytajTermin(zapis, startEpoka + 421_000, 0), null);
    assert.equal(odczytajTermin(zapis, startEpoka + 421_000 + PRZETERMINOWANIE_NAJWYZEJ_MS, 0), null);
});
