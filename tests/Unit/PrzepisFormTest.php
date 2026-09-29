<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Livewire\Forms\PrzepisForm;
use App\Support\KreatorPrzepisu\KrokOPrzepisie;
use Livewire\Component;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Issue #1387 — Livewire Form Object kreatora przepisu (`PrzepisForm`)
 * sprawdzany SAM, bez renderowania kreatora i bez bazy.
 *
 * Form Object trzyma tylko to, co klient może zmienić (surowe pola kroku
 * „o przepisie”). Reguły i komunikaty mają jedno źródło — `KrokOPrzepisie` —
 * więc test walidacji przepuszcza pola formularza przez ten sam walidator
 * i sprawdza, pod jakimi kluczami błędy wracają do worka komponentu.
 */
final class PrzepisFormTest extends TestCase
{
    private function formularz(): PrzepisForm
    {
        $komponent = new class extends Component
        {
            public function render(): string
            {
                return '';
            }
        };

        return new PrzepisForm($komponent, 'form');
    }

    /** @return list<string> */
    private function wlasciwosci(): array
    {
        return array_map(
            fn (ReflectionProperty $w): string => $w->getName(),
            (new ReflectionClass(PrzepisForm::class))->getProperties(ReflectionProperty::IS_PUBLIC),
        );
    }

    public function test_domyslne_wartosci_odpowiadaja_nowemu_przepisowi(): void
    {
        $form = $this->formularz();

        $this->assertSame(
            ['', '', '', '', '', '', '', 'public', 'own', '', '', '', ''],
            [$form->title, $form->summary, $form->servings, $form->estimated_cost_pln, $form->prep_minutes, $form->cook_minutes, $form->difficulty, $form->visibility, $form->source_type, $form->source_person, $form->source_note, $form->source_url, $form->family_since_year],
        );
    }

    public function test_lista_pol_zgadza_sie_z_publicznymi_wlasciwosciami_i_z_pola(): void
    {
        $form = $this->formularz();

        $this->assertEqualsCanonicalizing(PrzepisForm::POLA, $this->wlasciwosci());
        $this->assertEqualsCanonicalizing(PrzepisForm::POLA, array_keys($form->pola()));
        // Każde pole formularza ma reguły w `KrokOPrzepisie` — inaczej byłoby bez walidacji.
        $this->assertSame([], array_diff(PrzepisForm::POLA, KrokOPrzepisie::POLA));
    }

    public function test_pola_sterujace_i_identyfikatory_nie_naleza_do_formularza(): void
    {
        // Klient może zmienić KAŻDĄ publiczną właściwość Form Objectu, więc nic
        // tu nie może decydować o tym, KTÓRY przepis się zapisuje ani w jakim
        // jest stanie (AGENTS.md §7, D-006). Te pola zostają w komponencie
        // (`#[Locked]`) albo w akcji domenowej. Form Object nie jest też
        // modelem, więc nie ma `$fillable`, którym dałoby się je wypełnić.
        $zabronione = [
            'status', 'role', 'kind', 'recipeId', 'recipe_id', 'author_id',
            'heroMediaId', 'hero_media_id', 'sourceScanMediaId', 'source_scan_media_id',
            'published_at', 'juzOpublikowany', 'wersjaStanu', 'fillable', 'guarded',
        ];

        $this->assertSame([], array_intersect($zabronione, $this->wlasciwosci()));
        $this->assertSame([], array_intersect($zabronione, PrzepisForm::POLA));
        $this->assertSame([], array_intersect($zabronione, array_keys($this->formularz()->pola())));
    }

    public function test_poprawne_pola_przechodza_walidacje(): void
    {
        $form = $this->formularz();
        $form->title = 'Rosół';
        $form->servings = '4';
        $form->prep_minutes = '20';
        $form->cook_minutes = '90';
        $form->estimated_cost_pln = '24,50';
        $form->difficulty = 'medium';
        $form->visibility = 'followers';
        $form->source_type = 'family';
        $form->source_person = 'od mamy';
        $form->source_url = 'https://example.com/przepis';
        $form->family_since_year = '1974';

        $walidator = KrokOPrzepisie::walidator($form->pola(), null);

        $this->assertFalse($walidator->fails(), implode(' ', $walidator->errors()->all()));
    }

    public function test_bledne_pola_formularza_dostaja_komunikaty_pod_kluczami_form(): void
    {
        $form = $this->formularz();
        $form->title = 'Ro';
        $form->servings = 'cztery';
        $form->prep_minutes = '-5';
        $form->cook_minutes = '20000';
        $form->estimated_cost_pln = 'dużo';
        $form->summary = str_repeat('a', 2001);
        $form->difficulty = 'nie-wiem';
        $form->visibility = 'wszyscy-na-swiecie';
        $form->source_type = 'z-kosmosu';
        $form->source_person = str_repeat('a', 121);
        $form->source_note = str_repeat('a', 2001);
        $form->source_url = 'ftp://example.invalid/przepis';
        $form->family_since_year = '1200';

        $bledy = KrokOPrzepisie::walidator($form->pola(), null)->errors()->messages();

        $this->assertEqualsCanonicalizing(PrzepisForm::POLA, array_keys($bledy));
        $this->assertSame('Nazwa przepisu musi mieć co najmniej 3 znaki. Dopisz kilka liter.', $bledy['title'][0]);
        $this->assertSame('Liczba porcji musi być liczbą. Wpisz na przykład 4.', $bledy['servings'][0]);
        $this->assertSame('Zaznacz, kto ma widzieć ten przepis.', $bledy['visibility'][0]);
        $this->assertSame('Zaznacz, skąd jest ten przepis.', $bledy['source_type'][0]);
        $this->assertSame('Ten rok jest za wczesny. Wpisz rok od 1850.', $bledy['family_since_year'][0]);

        foreach (array_keys($bledy) as $pole) {
            $this->assertSame('form.'.$pole, PrzepisForm::kluczBledu($pole));
        }
    }

    public function test_nazwa_spoza_formularza_nie_dostaje_przedrostka(): void
    {
        $this->assertSame('steps', PrzepisForm::kluczBledu('steps'));
        $this->assertSame('formularz.visibility', PrzepisForm::kluczBledu('visibility', 'formularz'));
    }
}
