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

    public function test_zdjecie_znika_takze_gdy_ktos_inny_na_nie_wskazuje(): void
    {
        /*
         * TA REGUŁA ZOSTAŁA ODWRÓCONA (decyzja D-018, audyt W4-01).
         *
         * Wcześniej ten test wymagał, żeby zdjęcie wskazywane przez kogoś
         * innego PRZETRWAŁO wymazanie konta — z uzasadnieniem, że kasowanie
         * za dużo jest nieodwracalne i dotyka osoby, która o niczym nie wie.
         * To było rozsądne przy dawnej regule „kasujemy tylko awatar".
         *
         * Teraz obowiązuje inna: zdjęcia osoby, która prosi o usunięcie konta,
         * kasujemy WSZYSTKIE. Zdjęcie jest jej danymi osobowymi — twarz,
         * wnętrze mieszkania, EXIF z miejscem — a nie zasobem, który ktoś inny
         * może zatrzymać, bo zdążył go sobie przypiąć. Prośba o usunięcie
         * danych wygrywa z czyjąś wygodą.
         *
         * Ten stan i tak jest w produkcie nieosiągalny: każde wgranie tworzy
         * nowy wiersz `media` z `owner_id` osoby wgrywającej, więc Marek nie
         * ma jak wskazać na zdjęcie Haliny. Test zostaje jako opis reguły
         * i jako dowód, że nie zostawiamy po sobie wskaźnika w próżnię.
         */
        Storage::fake('public');
        [$user, $avatar] = $this->kontoZAwatarem('halina');

        $ktosInny = $this->user('marek');
        $ktosInny->profile->forceFill(['avatar_media_id' => $avatar->getKey()])->save();

        app(EraseAccountData::class)->handle($user);

        // Zdjęcie Haliny znika: plik i wiersz.
        Storage::disk('public')->assertMissing($avatar->object_key);
        $this->assertNull($avatar->fresh());

        // U Marka NIE zostaje wskaźnik w próżnię — klucz obcy ma
        // `nullOnDelete()`, więc referencja zeruje się sama. Bez tego profil
        // Marka pytałby o wiersz, którego nie ma.
        $this->assertNull($ktosInny->fresh()->profile->avatar_media_id);

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
