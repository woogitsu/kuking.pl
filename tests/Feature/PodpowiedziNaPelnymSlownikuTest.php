<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\TagSuggester;
use App\Models\Tag;
use Database\Seeders\TagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Podpowiedzi tagów NA PEŁNYM SŁOWNIKU (D-026) — 1409 tagów, nie trzy
 * sztuczne wiersze.
 *
 * PO CO OSOBNY PLIK, SKORO PODPOWIEDZI MAJĄ JUŻ TESTY
 * Bo tamte mierzą REGUŁY na kilku tagach zrobionych pod test, a tu mierzymy
 * SKUTEK reguł na prawdziwych danych. Różnica jest sprawdzalna: przy 651
 * tagach z ręcznej bazy kolejność w pierwszej gałęzi nie miała znaczenia,
 * a przy 1409 ze słownika wyszło, że wpisanie „chleb" dawało jako pierwszą
 * podpowiedź „chlebek bananowy", a wpisanie „barszcz" — „barszcz biały",
 * mimo że słownik ma „barszcz" jako alias „barszczu czerwonego". Żaden test
 * na trzech tagach tego nie pokaże.
 *
 * DLACZEGO TO JEST WAŻNE WŁAŚNIE TUTAJ
 * Podpowiedź jest jedyną obroną przed rozsypaniem taksonomii: gdy ktoś
 * zaczyna pisać „sernik", ma zobaczyć istniejący tag, zanim powstanie jego
 * trzecia wersja. Podpowiedź, która na wpisane CAŁE słowo pokazuje coś
 * innego niż tag o tej nazwie, tej roboty nie wykonuje.
 */
class PodpowiedziNaPelnymSlownikuTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> nazwy w kolejności podpowiedzi */
    private function podpowiedzi(string $fraza): array
    {
        return app(TagSuggester::class)->sugeruj($fraza)->pluck('name')->all();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TagSeeder::class);
    }

    /**
     * KONTROLA. Słownik naprawdę jest w bazie i naprawdę jest duży — bez tego
     * każdy pomiar niżej mógłby przechodzić na trzech wierszach.
     */
    public function test_kontrola_baza_ma_pelny_slownik(): void
    {
        $this->assertGreaterThan(1300, Tag::query()->count());
        $this->assertTrue(Tag::query()->where('normalized_name', 'chleb')->exists());
        $this->assertTrue(Tag::query()->where('normalized_name', 'chlebek bananowy')->exists());
    }

    /**
     * WŁAŚCIWY POMIAR. Wpisane CAŁE słowo, które jest nazwą tagu → ten tag
     * jest PIERWSZY, nie gdziekolwiek na liście.
     */
    public function test_dokladna_nazwa_jest_pierwsza(): void
    {
        foreach (['chleb', 'zupa', 'sernik', 'pierogi', 'kluski', 'kompot', 'dynia'] as $fraza) {
            $podpowiedzi = $this->podpowiedzi($fraza);

            $this->assertNotEmpty($podpowiedzi, "Brak podpowiedzi dla „{$fraza}”.");
            $this->assertSame(
                $fraza,
                $podpowiedzi[0],
                "Wpisane „{$fraza}” dało jako pierwszą podpowiedź „{$podpowiedzi[0]}”, a nie tag o tej nazwie. "
                .'Kolejność: '.implode(' | ', $podpowiedzi),
            );
        }
    }

    /**
     * Dokładny ALIAS wygrywa z samym początkiem nazwy. „schabowy" jest
     * w słowniku aliasem „kotleta schabowego", a „marchewka" aliasem
     * „marchwi" — i to mają być pierwsze podpowiedzi, nie tag, który tylko
     * zaczyna się tak samo („schab", „marchewka z groszkiem").
     */
    public function test_dokladny_alias_wygrywa_z_poczatkiem_nazwy(): void
    {
        $schabowy = $this->podpowiedzi('schabowy');

        $this->assertSame(
            'kotlet schabowy',
            $schabowy[0] ?? null,
            'Wpisane „schabowy” nie dało tagu, którego alias to dokładnie „schabowy”. Kolejność: '.implode(' | ', $schabowy),
        );

        // KONTROLA drugiej strony: tag, który tylko zaczyna się tak samo, NIE
        // wypada z listy — chodzi o kolejność, nie o ukrywanie.
        $this->assertContains('schab', $schabowy);

        $marchewka = $this->podpowiedzi('marchewka');

        $this->assertSame(
            'marchew',
            $marchewka[0] ?? null,
            'Wpisane „marchewka” nie dało „marchwi”. Kolejność: '.implode(' | ', $marchewka),
        );
        $this->assertContains('marchewka z groszkiem', $marchewka);
    }

    /**
     * POJĘCIA OGÓLNE, od których ludzie zaczynają pisać, MUSZĄ istnieć jako
     * tagi (D-026, plik uzupełnień).
     *
     * Zmierzone na dostarczonym słowniku: „barszcz" miał cztery odmiany
     * (czerwony, biały, ukraiński, zabielany) i ŻADNEGO tagu ogólnego, więc
     * wpisanie samego słowa „barszcz" dawało jako pierwszą podpowiedź
     * „barszcz biały" — czyli rozstrzygało za człowieka, którego barszczu mu
     * trzeba. Tak samo „kotlety" (trzynaście odmian), „krem", „kasza"
     * i „sok" (po osiem).
     *
     * To nie jest krytyka słownika: jego uwaga 25 mówi wprost, że nazwy
     * ogólne i ich odmiany celowo współistnieją — po prostu tych dziesięciu
     * ogólnych zabrakło.
     */
    public function test_pojecia_ogolne_istnieja_jako_tagi(): void
    {
        foreach (['barszcz', 'kotlety', 'krem', 'kasza', 'sok', 'syrop', 'pasta', 'placki', 'nalewka', 'ser'] as $ogolne) {
            $podpowiedzi = $this->podpowiedzi($ogolne);

            $this->assertSame(
                $ogolne,
                $podpowiedzi[0] ?? null,
                "Wpisane „{$ogolne}” nie dało tagu ogólnego jako pierwszej podpowiedzi. Kolejność: ".implode(' | ', $podpowiedzi),
            );
        }
    }

    /**
     * Wśród samych początków nazwy — najkrótsza pierwsza. Kto wpisał „zupa
     * pomid", chce „zupy pomidorowej", a nie „zupy pomidorowej z ryżem".
     */
    public function test_wsrod_poczatkow_nazwy_najkrotsza_pierwsza(): void
    {
        $podpowiedzi = $this->podpowiedzi('ogorki k');

        $this->assertNotEmpty($podpowiedzi);

        // TYLKO trafienia z gałęzi „początek nazwy". Cała lista miesza gałęzie
        // (za nimi idą jeszcze trafienia trigramowe, np. samo „ogórki" przy
        // frazie „ogorki k"), więc porównywanie długości na całej liście
        // mierzyłoby coś, czego żadna reguła nie obiecuje — pierwsza wersja
        // tego testu miała dokładnie ten błąd.
        $zGalezi = array_values(array_filter(
            $podpowiedzi,
            fn (string $nazwa): bool => str_starts_with(Str::ascii(mb_strtolower($nazwa)), 'ogorki k'),
        ));

        $this->assertGreaterThanOrEqual(3, count($zGalezi), 'Kontrola: fraza ma trafić w co najmniej trzy nazwy, inaczej nie ma czego porządkować.');

        $dlugosci = array_map('mb_strlen', $zGalezi);
        $posortowane = $dlugosci;
        sort($posortowane);

        $this->assertSame(
            $posortowane,
            $dlugosci,
            'Podpowiedzi z tej samej gałęzi nie są uporządkowane od najkrótszej: '.implode(' | ', $zGalezi),
        );
    }

    /**
     * Ta sama fraza daje ZAWSZE tę samą listę. To nie jest kosmetyka:
     * lista, która przeskakuje między naciśnięciami klawisza, jest dla osoby
     * 50+ gorsza niż lista krótsza (`docs/UX_50_PLUS.md`).
     */
    public function test_ta_sama_fraza_daje_zawsze_te_sama_liste(): void
    {
        foreach (['kotlet', 'ciasto', 'zupa', 'ogorki'] as $fraza) {
            $pierwsza = $this->podpowiedzi($fraza);

            $this->assertSame($pierwsza, $this->podpowiedzi($fraza), "Lista dla „{$fraza}” zmieniła się między wywołaniami.");
            $this->assertSame($pierwsza, $this->podpowiedzi($fraza), "Lista dla „{$fraza}” zmieniła się przy trzecim wywołaniu.");
        }
    }

    /**
     * Zapis BEZ polskich znaków znajduje nazwę z nimi — to jest cały powód,
     * dla którego podpowiadanie używa `kuking_normalize()` (z `unaccent`),
     * a unikalność nie (D-021). Osoba pisząca na telefonie bez ogonków musi
     * trafić na istniejący tag, zamiast tworzyć jego drugą wersję.
     */
    public function test_zapis_bez_ogonkow_znajduje_nazwe_z_ogonkami(): void
    {
        $this->assertContains('żurek', $this->podpowiedzi('zurek'));
        $this->assertContains('rosół', $this->podpowiedzi('rosol'));
        $this->assertContains('ogórki', $this->podpowiedzi('ogorki'));
        $this->assertContains('śledzie', $this->podpowiedzi('sledzie'));

        // KONTROLA: mimo tego „zurek” NIE JEST osobnym tagiem kanonicznym
        // (D-021 zakazuje wprost) — jest aliasem.
        $this->assertFalse(Tag::query()->where('normalized_name', 'zurek')->exists());
    }

    /** Wielkość liter nie ma znaczenia — „Sernik" i „sernik" to jedno. */
    public function test_wielkosc_liter_nie_ma_znaczenia(): void
    {
        $this->assertSame($this->podpowiedzi('sernik'), $this->podpowiedzi('Sernik'));
        $this->assertSame($this->podpowiedzi('sernik'), $this->podpowiedzi('SERNIK'));
    }

    /** Literówka jednej litery nadal trafia — gałąź trigramowa. */
    public function test_literowka_nadal_trafia(): void
    {
        $this->assertContains('sernik', $this->podpowiedzi('sernk'));
        $this->assertContains('pierogi', $this->podpowiedzi('pierogy'));
    }

    /**
     * Słownik jest po to, żeby ktoś, kto szuka słowami TEJ grupy, coś
     * znalazł. Te frazy nie są ozdobą: kategorie `pamiec` i `okolicznosci`
     * powstały dokładnie dla nich i żaden tag z poprzedniej bazy ich nie
     * pokrywał.
     */
    public function test_slowa_grupy_50_plus_znajduja_tagi(): void
    {
        $this->assertContains('przepis po babci', $this->podpowiedzi('babci'));
        $this->assertContains('z zeszytu', $this->podpowiedzi('zeszyt'));
        $this->assertContains('dla wnuków', $this->podpowiedzi('wnuk'));
        $this->assertContains('z czerstwego chleba', $this->podpowiedzi('czerstwe'));
    }

    /** Lista nigdy nie jest dłuższa niż limit z konfiguracji. */
    public function test_lista_nie_przekracza_limitu(): void
    {
        $limit = (int) config('kuking.tags.suggestions_limit');

        $this->assertGreaterThan(0, $limit, 'Kontrola: limit podpowiedzi musi być liczbą dodatnią.');

        foreach (['zupa', 'ciasto', 'kotlet', 'pierogi', 'ogorki'] as $fraza) {
            $this->assertLessThanOrEqual($limit, count($this->podpowiedzi($fraza)));
        }
    }

    /** Jedna litera nie podpowiada nic — próg z `LimityTagow::minZnakow()`. */
    public function test_jedna_litera_nie_podpowiada(): void
    {
        $this->assertSame([], $this->podpowiedzi('z'));
        $this->assertNotSame([], $this->podpowiedzi('zu'), 'Kontrola: dwie litery już podpowiadają.');
    }
}
