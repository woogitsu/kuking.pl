<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #780: lista relacji i profil pokazywały przycisk „Obserwuj” przy koncie
 * zawieszonym, choć `UserPolicy::follow()` wymaga `isActive()` i zawsze
 * odmawia. Konto zawieszone przechodzi `widocznyJakoOsoba()`/
 * `jestWidocznyJakoOsoba()` (zawieszenie jest tymczasowe, karta osoby ma
 * zostać) — więc filtr, który chowa konta zamknięte, tego przypadku nie
 * łapie. Widok musi sam sprawdzić `isActive()`, inaczej przycisk jest
 * martwy (D-053): zawsze kończy się błędem po kliknięciu.
 */
class ObserwujNieZawieszoneKontoTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_relacji_nie_pokazuje_martwego_przycisku_obserwuj_przy_koncie_zawieszonym(): void
    {
        $widz = $this->user('widz780');
        $gospodarz = $this->user('gospodarz780');
        $zawieszony = $this->user('zawieszony780');

        app(FollowUser::class)->handle($zawieszony, $gospodarz);

        $zawieszony->status = User::STATUS_SUSPENDED;
        $zawieszony->save();

        $html = $this->actingAs($widz)
            ->get(route('social.followers', 'gospodarz780'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('zawieszony780', $html);
        // `assertStringNotContainsString` na samym adresie łapałby fałszywie
        // trafienie: `.../obserwuj` to PODCIĄG `.../obserwujacy` z licznika
        // nad listą. Cudzysłów domyka dokładnie atrybut `action` formularza.
        $this->assertStringNotContainsString('action="'.route('social.follow', 'zawieszony780').'"', $html);
        $this->assertStringContainsString('Konto zawieszone', $html);
    }

    public function test_profil_zawieszonego_konta_nie_pokazuje_martwego_przycisku_obserwuj(): void
    {
        $widz = $this->user('widz780b');
        $zawieszony = $this->user('zawieszony780b');

        $zawieszony->status = User::STATUS_SUSPENDED;
        $zawieszony->save();

        $html = $this->actingAs($widz)
            ->get(route('profile.show', 'zawieszony780b'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('action="'.route('social.follow', 'zawieszony780b').'"', $html);
        $this->assertStringContainsString('zawieszone', $html);
    }

    public function test_klikniecie_obserwuj_na_koncie_zawieszonym_nadal_konczy_sie_bledem_po_polsku(): void
    {
        // Kontrola dodatnia dla `UserPolicy::follow()` — Policy dalej odmawia,
        // ten test tylko pilnuje, żeby widok przestał obiecywać co innego.
        $widz = $this->user('widz780c');
        $zawieszony = $this->user('zawieszony780c');

        $zawieszony->status = User::STATUS_SUSPENDED;
        $zawieszony->save();

        $this->actingAs($widz)
            ->post(route('social.follow', 'zawieszony780c'))
            ->assertForbidden();
    }
}
