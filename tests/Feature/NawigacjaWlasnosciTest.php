<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Osobiste pozycje nawigacji wskazują tylko WŁASNĄ treść (issue #946).
 *
 * NA CZYM POLEGAŁ BŁĄD
 * Layout oznaczał „Mój zeszyt" / „Moje" jako bieżące po samej nazwie trasy
 * (`routeIs('collections.*')`), więc cudzy publiczny zeszyt podświetlał
 * „Mój zeszyt". „Profil" świecił na KAŻDYM `/@nazwa` — także cudzym —
 * a na własnych listach obserwujących/obserwowanych nie świecił wcale.
 * `aria-current="page"` przekazywało tę nieprawdę czytnikowi ekranu.
 *
 * Trzy warianty nawigacji sprawdzamy OSOBNO — górny pasek komputera
 * (`marka-nawigacja`), boczną i dolną — bo każdy renderuje się własnym
 * fragmentem layoutu i każdy mógłby rozjechać się sam.
 *
 * Kontrola ujemna: przywrócenie `routeIs('collections.*')` albo
 * `routeIs('profile.show')` w `layout.blade.php` (albo w
 * `App\Support\NawigacjaOsobista`) oblewa sceny cudzej własności.
 */
class NawigacjaWlasnosciTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{gorna: string, boczna: string, dolna: string} */
    private function nawigacje(string $html): array
    {
        return [
            'gorna' => $this->wytnij($html, '<nav class="marka-nawigacja"', '</nav>'),
            'boczna' => $this->wytnij($html, '<nav class="side-nav"', '</nav>'),
            'dolna' => $this->wytnij($html, '<nav class="bottom-nav"', '</nav>'),
        ];
    }

    private function wytnij(string $html, string $poczatek, string $koniec): string
    {
        $start = strpos($html, $poczatek);
        $this->assertNotFalse($start, "Brak {$poczatek} na stronie.");
        $stop = strpos($html, $koniec, $start);
        $this->assertNotFalse($stop);

        return substr($html, $start, $stop - $start);
    }

    /** Nazwy WSZYSTKICH pozycji z `aria-current="page"` we fragmencie. */
    private function biezace(string $fragment): array
    {
        preg_match_all('~<a\b[^>]*aria-current="page"[^>]*>(.*?)</a>~s', $fragment, $trafienia);

        return array_map(
            fn (string $tekst) => trim(html_entity_decode(strip_tags($tekst))),
            $trafienia[1],
        );
    }

    /**
     * @param  array{gorna: ?string, boczna: ?string, dolna: ?string}  $oczekiwane
     */
    private function sprawdz(User $kto, string $adres, array $oczekiwane, string $scena): void
    {
        $html = $this->actingAs($kto)->get($adres)->assertOk()->getContent();

        foreach ($this->nawigacje($html) as $wariant => $fragment) {
            $biezace = $this->biezace($fragment);
            $this->assertLessThanOrEqual(1, count($biezace), "{$scena}: nawigacja {$wariant} ma kilka bieżących pozycji.");
            $this->assertSame(
                $oczekiwane[$wariant],
                $biezace[0] ?? null,
                "{$scena} ({$adres}): nawigacja {$wariant} wskazuje złą bieżącą pozycję.",
            );
        }
    }

    public function test_moj_zeszyt_swieci_tylko_przy_wlasnym_zeszycie(): void
    {
        $ja = $this->user('ja_zeszyt');
        $obca = $this->user('obca_zeszyt');

        $moj = Collection::create(['owner_id' => $ja->getKey(), 'name' => 'Moje obiady', 'visibility' => 'public']);
        $cudzy = Collection::create(['owner_id' => $obca->getKey(), 'name' => 'Na święta', 'visibility' => 'public']);

        $this->sprawdz($ja, route('collections.index'), ['gorna' => 'Mój zeszyt', 'boczna' => 'Moje', 'dolna' => 'Moje'], 'Lista własnych zeszytów');
        $this->sprawdz($ja, route('collections.show', $moj), ['gorna' => 'Mój zeszyt', 'boczna' => 'Moje', 'dolna' => 'Moje'], 'Własny zeszyt');
        $this->sprawdz($ja, route('collections.show', $cudzy), ['gorna' => null, 'boczna' => null, 'dolna' => null], 'Cudzy publiczny zeszyt');
    }

    public function test_profil_swieci_na_wlasnym_profilu_i_jego_listach_a_nie_na_cudzym(): void
    {
        $ja = $this->user('ja_profil');
        $obca = $this->user('obca_profil');

        // Górny pasek komputera nie ma pozycji „Profil" — tam nic nie świeci.
        $moje = ['gorna' => null, 'boczna' => 'Profil', 'dolna' => 'Profil'];
        $nic = ['gorna' => null, 'boczna' => null, 'dolna' => null];

        $this->sprawdz($ja, route('profile.show', 'ja_profil'), $moje, 'Własny profil');
        // Adres profilu nie rozróżnia wielkości liter (audyt A25) — to wciąż ten sam profil.
        $this->sprawdz($ja, '/@JA_Profil', $moje, 'Własny profil wielkimi literami');
        $this->sprawdz($ja, route('social.followers', 'ja_profil'), $moje, 'Własni obserwujący');
        $this->sprawdz($ja, route('social.following', 'ja_profil'), $moje, 'Własni obserwowani');

        $this->sprawdz($ja, route('profile.show', 'obca_profil'), $nic, 'Cudzy profil');
        $this->sprawdz($ja, route('social.followers', 'obca_profil'), $nic, 'Cudzy obserwujący');
        $this->sprawdz($ja, route('social.following', 'obca_profil'), $nic, 'Cudzy obserwowani');

        // Kontrola dodatnia: obca osoba na SWOIM profilu widzi „Profil" jako bieżący.
        $this->sprawdz($obca, route('profile.show', 'obca_profil'), $moje, 'Profil oglądany przez właścicielkę');
    }
}
