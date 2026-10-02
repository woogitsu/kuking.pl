<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Wspoldzielenie\OdpowiedzNaZaproszenie;
use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PrywatnyWspolnyZeszytKomunikatTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function stany(): array
    {
        return [
            'członek' => ['member'],
            'oczekujące zaproszenie' => ['pending'],
            'wygasłe zaproszenie' => ['expired'],
            'odwołane zaproszenie' => ['revoked'],
            'bez zaproszeń' => ['none'],
        ];
    }

    public function test_domyslny_zeszyt_nie_obiecuje_nieistniejacego_wspoldzielenia(): void
    {
        $owner = $this->user('wlasciciel');
        $collection = Collection::create(['owner_id' => $owner->getKey(), 'name' => 'Mój zeszyt', 'visibility' => 'public', 'is_default' => true]);
        $this->actingAs($owner)->get(route('collections.edit', $collection))->assertOk()
            ->assertDontSee('id="prywatny-zeszyt-dostep"', false);
        $this->patch(route('collections.update', $collection), ['name' => $collection->name, 'visibility' => 'private'])
            ->assertRedirect(route('collections.show', $collection))
            ->assertSessionHas('status', 'Zeszyt jest teraz prywatny. Publiczny dostęp został wyłączony.');
    }

    #[DataProvider('stany')]
    public function test_prywatnosc_opisuje_zakres_i_nie_konczy_wspoldzielenia(string $stan): void
    {
        $owner = $this->user('wlasciciel');
        $member = $this->user('zaproszony');
        $stranger = $this->user('obcy');
        $collection = Collection::create(['owner_id' => $owner->getKey(), 'name' => 'Obiady rodzinne', 'visibility' => 'public']);
        $recipe = Recipe::factory()->create();
        $collection->recipes()->attach($recipe->getKey(), ['note' => 'Rodzinny zapis', 'created_at' => now()]);
        $invitation = null;
        if ($stan !== 'none') {
            $invitation = app(ZaprosDoZeszytu::class)->poNazwie($owner, $collection, 'zaproszony');
            if ($stan === 'member') {
                app(OdpowiedzNaZaproszenie::class)->przyjmij($member, $invitation);
            } elseif ($stan === 'expired') {
                $invitation->forceFill(['expires_at' => now()->subMinute()])->save();
            } elseif ($stan === 'revoked') {
                $invitation->forceFill(['status' => CollectionInvitation::STATUS_REVOKED, 'responded_at' => now()])->save();
            }
            $invitation->refresh();
        }
        $before = $invitation?->getRawOriginal();
        $members = DB::table('collection_members')->where('collection_id', $collection->getKey())->count();

        $html = $this->actingAs($owner)->get(route('collections.edit', $collection))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $fieldset = $xpath->query('//*[@id="f-visibility"]')->item(0);
        $this->assertNotNull($fieldset);
        $this->assertStringContainsString('Prywatny zeszyt widzisz Ty i osoby, które przyjęły zaproszenie.', $fieldset->textContent);
        $this->assertStringNotContainsString('Tylko ja', $fieldset->textContent);
        $links = $xpath->query('.//a', $fieldset);
        $this->assertSame(1, $links->length);
        $link = $links->item(0);
        $this->assertInstanceOf(\DOMElement::class, $link);
        $this->assertSame(route('collections.sharing', $collection), $link->getAttribute('href'));

        $this->actingAs($owner)->patch(route('collections.update', $collection), ['name' => $collection->name, 'visibility' => 'private'])
            ->assertRedirect(route('collections.show', $collection));
        $this->assertSame(
            'Zeszyt jest teraz prywatny. Publiczny dostęp został wyłączony. Zaproszone osoby zachowują swój dostęp. Oczekujące zaproszenia nie zostały odwołane.',
            session('status'),
            'ZESZYT_2601_PRYWATNY_NIE_ODBIERA_ZAPROSZONYM: potwierdzenie nie może obiecywać dostępu tylko właściciela.',
        );
        $this->assertSame('private', $collection->fresh()->visibility);
        $this->assertSame($members, DB::table('collection_members')->where('collection_id', $collection->getKey())->count());
        $this->assertSame($before, $invitation?->fresh()->getRawOriginal());
        $this->assertSame('Rodzinny zapis', $collection->recipes()->whereKey($recipe->getKey())->first()->pivot->note);
        $this->actingAs($stranger)->get(route('collections.show', $collection))->assertForbidden();
        $response = $this->actingAs($member)->get(route('collections.show', $collection));
        if ($stan === 'member') {
            $response->assertOk();
        } else {
            $response->assertForbidden();
        }
    }
}
