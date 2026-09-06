<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Media;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Issue #93 — po usunięciu konta plik awatara zostawał na dysku.
 *
 * Egzekutor karencji anonimizował e-mail, hasło, nazwę i bio, i ODPINAŁ
 * awatar (`avatar_media_id = null`). Sam plik zostawał, razem ze wszystkimi
 * wariantami.
 *
 * Zdjęcie twarzy to dana osobowa. Zdjęcie z nietkniętym EXIF-em to dana
 * osobowa razem z modelem telefonu i — jeśli aparat je zapisał —
 * współrzędnymi miejsca, w którym powstało. Człowiek prosił o usunięcie
 * konta, dostawał potwierdzenie, że dane zostały usunięte, a jego zdjęcie
 * leżało dalej pod adresem, który wciąż działał.
 */
class AwatarZnikaZDyskuTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Media} */
    private function kontoZAwatarem(string $nazwa): array
    {
        $user = $this->user($nazwa);

        $avatar = Media::factory()->create([
            'owner_id' => $user->getKey(),
            'disk' => 'public',
            'object_key' => "media/{$nazwa}/oryginal.jpg",
            'metadata' => ['variants' => [
                'thumb' => ['key' => "media/{$nazwa}/oryginal_thumb.webp"],
                'feed' => ['key' => "media/{$nazwa}/oryginal_feed.webp"],
                'large' => ['key' => "media/{$nazwa}/oryginal_large.webp"],
            ]],
        ]);

        Storage::disk('public')->put($avatar->object_key, 'oryginal');

        foreach ($avatar->metadata['variants'] as $wariant) {
            Storage::disk('public')->put($wariant['key'], 'wariant');
        }

        $user->profile->forceFill(['avatar_media_id' => $avatar->getKey()])->save();

        // Przez model, nie `forceFill`: kolumna `status_expires_at` ma CHECK
        // w bazie, ktorego recznie ustawiona data z przeszlosci nie spelnia.
        // Karencje przesuwamy zegarem, nie danymi.
        $user->markForDeletion();

        return [$user->fresh(), $avatar];
    }

    public function test_po_wymazaniu_konta_plik_awatara_i_warianty_znikaja(): void
    {
        Storage::fake('public');
        [$user, $avatar] = $this->kontoZAwatarem('halina');

        $this->assertTrue(app(EraseAccountData::class)->handle($user));

        // TO JEST NAJWAŻNIEJSZA ASERCJA W TYM PLIKU.
        Storage::disk('public')->assertMissing($avatar->object_key);

        foreach ($avatar->metadata['variants'] as $wariant) {
            Storage::disk('public')->assertMissing($wariant['key']);
        }

        $this->assertNull($avatar->fresh());
        $this->assertNull($user->fresh()->profile->avatar_media_id);
    }

    public function test_plik_uzywany_przez_kogos_innego_zostaje_nietkniety(): void
    {
        Storage::fake('public');
        [$user, $avatar] = $this->kontoZAwatarem('halina');

        // Ktoś inny wskazuje na to samo zdjęcie. Sytuacja rzadka, ale
        // kasowanie za dużo jest tu NIEODWRACALNE — pliku nie da się
        // przywrócić, a szkoda dotyczy kogoś, kto o niczym nie wie.
        $ktosInny = $this->user('marek');
        $ktosInny->profile->forceFill(['avatar_media_id' => $avatar->getKey()])->save();

        app(EraseAccountData::class)->handle($user);

        Storage::disk('public')->assertExists($avatar->object_key);
        $this->assertNotNull($avatar->fresh());

        // Konto i tak jest wymazane — plik zostaje, bo należy już do kogoś
        // innego, ale referencja z wymazanego profilu znika.
        $this->assertNull($user->fresh()->profile->avatar_media_id);
    }

    public function test_konto_bez_awatara_nie_wywala_egzekutora(): void
    {
        Storage::fake('public');

        $user = $this->user('bezzdjecia');
        $user->markForDeletion();

        $this->assertTrue(app(EraseAccountData::class)->handle($user->fresh()));
    }

    public function test_powtorne_wymazanie_nie_kasuje_niczego_drugi_raz(): void
    {
        Storage::fake('public');
        [$user, $avatar] = $this->kontoZAwatarem('halina');

        $this->assertTrue(app(EraseAccountData::class)->handle($user));

        // Idempotencja: egzekutor chodzi z harmonogramu, więc drugie
        // uruchomienie na tym samym koncie musi być bezpieczne.
        $this->assertFalse(app(EraseAccountData::class)->handle($user->fresh()));
        $this->assertNull($avatar->fresh());
    }

    public function test_awatar_zostaje_dopoki_konto_jest_w_karencji(): void
    {
        Storage::fake('public');
        [$user, $avatar] = $this->kontoZAwatarem('halina');

        // Egzekutor sam nie sprawdza terminu — robi to komenda, która go woła.
        // Ten test pilnuje czegoś innego: że cofnięcie usunięcia w trakcie
        // karencji zostawia człowiekowi jego zdjęcie.
        $user->cancelDeletion();

        $this->assertFalse(app(EraseAccountData::class)->handle($user->fresh()));

        Storage::disk('public')->assertExists($avatar->object_key);
        $this->assertNotNull(Profile::where('user_id', $user->getKey())->first()->avatar_media_id);
    }
}
