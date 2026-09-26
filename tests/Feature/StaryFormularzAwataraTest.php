<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaryFormularzAwataraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
    }

    private function photo(User $user): Media
    {
        $photo = Media::factory()->create(['owner_id' => $user->id, 'variants_disk' => 'public']);
        Storage::disk('public')->put($photo->object_key, 'oryginal');
        foreach ($photo->metadata['variants'] as $variant) {
            Storage::disk('public')->put($variant['key'], 'wariant');
        }
        $user->profile()->update(['avatar_media_id' => $photo->id]);

        return $photo;
    }

    private function form(User $user): array
    {
        $html = $this->actingAs($user->fresh())->get(route('settings.avatar'))->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $forms = $xpath->query('//form[input[@name="_method" and @value="DELETE"]]');
        $this->assertCount(1, $forms);
        $fields = [];
        foreach (self::elementyDom($xpath->query('.//input[@name]', $forms->item(0))) as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return $fields;
    }

    private function assertPreserved(Media $photo): void
    {
        $this->assertNotNull($photo->fresh());
        Storage::disk('public')->assertExists($photo->object_key);
        foreach ($photo->metadata['variants'] as $variant) {
            Storage::disk('public')->assertExists($variant['key']);
        }
    }

    /** Sekwencja GET → podmiana → POST; nie jest pomiarem równoległych połączeń. */
    public function test_stary_formularz_nie_kasuje_nowszego_zdjecia(): void
    {
        $user = $this->user('basia');
        $old = $this->photo($user);
        $fields = $this->form($user);
        $new = $this->photo($user);

        $this->actingAs($user->fresh())->post(route('settings.avatar.destroy'), $fields)
            ->assertRedirect(route('settings.avatar'));

        $this->assertSame($new->id, $user->fresh()->profile->avatar_media_id, 'Stary formularz usunął nowe zdjęcie.');
        $this->assertPreserved($old);
        $this->assertPreserved($new);
    }

    public function test_swiezy_formularz_usuwa_tylko_pokazane_zdjecie(): void
    {
        $user = $this->user('basia');
        $old = $this->photo($user);
        $new = $this->photo($user);
        $fields = $this->form($user);
        $this->actingAs($user->fresh())->post(route('settings.avatar.destroy'), $fields)->assertRedirect(route('settings.avatar'));
        $this->assertNull($user->fresh()->profile->avatar_media_id);
        $this->assertNull($new->fresh());
        Storage::disk('public')->assertMissing($new->object_key);
        foreach ($new->metadata['variants'] as $variant) {
            Storage::disk('public')->assertMissing($variant['key']);
        }
        $this->assertPreserved($old);
    }

    public function test_brak_id_i_cudze_id_nie_upowazniaja_do_kasowania(): void
    {
        $user = $this->user('basia');
        $own = $this->photo($user);
        $other = $this->photo($this->user('adam'));
        foreach ([[], ['avatar_media_id' => $other->id], ['avatar_media_id' => ['zly']]] as $fields) {
            $this->actingAs($user->fresh())->delete(route('settings.avatar.destroy'), $fields);
            $this->assertSame($own->id, $user->fresh()->profile->avatar_media_id);
            $this->assertPreserved($own);
            $this->assertPreserved($other);
        }
    }
}
