<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\ZaleglePotwierdzeniaZgloszen;
use App\Models\Report;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Liczenie spraw „na suficie" prób potwierdzenia DSA (#2218) czyta liczniki
 * porażek partiami, nie jednym `Cache::get` na sprawę (audyt P3).
 *
 * Przy awarii poczty zbiór zaległych zgłoszeń prawnych rośnie, a to liczenie
 * woła co godzinę dosyłka i każde odświeżenie sondy `/health`. Bez partii
 * każdy przebieg robił tyle zapytań do tabeli `cache`, ile było spraw.
 */
class SufitPotwierdzenDsaPartiamiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sklep `database`, bo dopiero on zamienia odczyt na zapytanie SQL.
        config(['cache.default' => 'database']);
        Cache::flush();
    }

    private function sprawaPrawna(int $numer): Report
    {
        return Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/wpisy/cos-'.$numer,
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
            'notifier_email' => "zglaszajacy{$numer}@example.com",
        ]);
    }

    private function wstrzymaj(Report $sprawa, int $porazki): void
    {
        Cache::put(PotwierdzenieZgloszeniaNielegalnejTresci::kluczPorazek((string) $sprawa->getKey()), $porazki, now()->addDay());
    }

    /**
     * @param  callable(): mixed  $praca
     */
    private function zapytaniaDoCache(callable $praca): int
    {
        $licznik = 0;
        DB::listen(function ($zapytanie) use (&$licznik): void {
            if (preg_match('/from "cache"/i', $zapytanie->sql) === 1) {
                $licznik++;
            }
        });
        $praca();

        return $licznik;
    }

    public function test_liczba_zapytan_do_cache_nie_rosnie_z_liczba_spraw(): void
    {
        $male = [];
        for ($i = 1; $i <= 5; $i++) {
            $male[] = $this->sprawaPrawna($i);
        }
        $this->wstrzymaj($male[0], 3);

        $przyMalym = $this->zapytaniaDoCache(fn () => ZaleglePotwierdzeniaZgloszen::numeryNaSuficie());

        for ($i = 6; $i <= 50; $i++) {
            $this->wstrzymaj($this->sprawaPrawna($i), $i % 2 === 0 ? 3 : 1);
        }

        $przyDuzym = $this->zapytaniaDoCache(fn () => ZaleglePotwierdzeniaZgloszen::numeryNaSuficie());

        $this->assertGreaterThan(0, $przyMalym, 'Przyrząd nie widzi zapytań do tabeli cache — test niczego nie mierzy.');
        $this->assertSame($przyMalym, $przyDuzym, 'Liczba odczytów z cache zależy od liczby spraw.');
        $this->assertLessThanOrEqual(2, $przyDuzym);
    }

    public function test_wynik_jest_taki_sam_jak_przy_odczycie_sprawa_po_sprawie(): void
    {
        $sprawy = [];
        for ($i = 1; $i <= 30; $i++) {
            $sprawy[] = $sprawa = $this->sprawaPrawna($i);
            $sprawa->forceFill(['created_at' => now()->subMinutes(100 - $i)])->save();
            if ($i % 3 === 0) {
                $this->wstrzymaj($sprawa, 3 + ($i % 2));
            } elseif ($i % 3 === 1) {
                $this->wstrzymaj($sprawa, 2);
            }
        }

        // Dawne zachowanie, dosłownie: wszystkie sprawy, osobny odczyt każdej.
        $oczekiwane = [];
        foreach (ZaleglePotwierdzeniaZgloszen::zapytanie()->whereNull('reporter_id')->orderBy('created_at')->get(['id', 'numer_sprawy']) as $sprawa) {
            if (PotwierdzenieZgloszeniaNielegalnejTresci::ponawianieWstrzymane((string) $sprawa->getKey())) {
                $oczekiwane[] = (string) ($sprawa->numer_sprawy ?? $sprawa->getKey());
            }
        }

        $this->assertCount(10, $oczekiwane);
        $this->assertSame($oczekiwane, ZaleglePotwierdzeniaZgloszen::numeryNaSuficie());
        $this->assertSame(10, ZaleglePotwierdzeniaZgloszen::ileNaSuficie());
    }

    public function test_sufit_przegladanych_spraw_obcina_i_zostawia_wpis_w_logu(): void
    {
        $log = Log::spy();

        for ($i = 1; $i <= 4; $i++) {
            $this->wstrzymaj($this->sprawaPrawna($i), 3);
        }

        // Poniżej i dokładnie na suficie: pełny wynik, bez wpisu w logu.
        $this->assertCount(4, ZaleglePotwierdzeniaZgloszen::numeryNaSuficie(10));
        $this->assertCount(4, ZaleglePotwierdzeniaZgloszen::numeryNaSuficie(4));
        $log->shouldNotHaveReceived('warning');

        // Powyżej: przegląd obcięty, o czym musi zostać ślad.
        $this->assertCount(2, ZaleglePotwierdzeniaZgloszen::numeryNaSuficie(2));
        $log->shouldHaveReceived('warning')->once();
    }
}
