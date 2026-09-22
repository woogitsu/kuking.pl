<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\OznaczDoPrzegladu;
use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Jobs\PrzeanalizujAwatar;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Notification as PowiadomienieWSerwisie;
use App\Models\Profile;
use App\Models\Report;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ZDJĘCIE PROFILOWE NIE WYCHODZI DO MODELU (D-240; wcześniej issue #237).
 *
 * CO SIĘ ZMIENIŁO
 * Od #237 do D-240 miniatura awatara szła do OpenAI. Decyzja właściciela
 * z 22.09.2026: awatar bez potwierdzonej zgody nie wychodzi, a serwis nie ma
 * mechanizmu takiej zgody. Te testy pilnują więc dwóch rzeczy: że żadna droga
 * (formularz, zadanie zostawione w kolejce) nie wysyła zdjęcia profilowego,
 * i że oznaczenia awatarów sprzed tej zmiany dalej da się rozpatrzyć
 * w panelu moderatora.
 *
 * Poniżej zostaje opis z #237 — powody, dla których ocena awatarów w ogóle
 * powstała, są dalej prawdziwe i będą potrzebne przy jej przywracaniu.
 *
 * DLACZEGO TO JEST WAŻNIEJSZE, NIŻ WYGLĄDA
 * Awatar jest widoczny CZĘŚCIEJ niż jakikolwiek wpis: chodzi za człowiekiem
 * po całym serwisie — przy każdym komentarzu pod cudzym przepisem, na tablicy
 * dnia, na listach obserwujących. Do tego jest najtańszym miejscem dla kogoś,
 * kto chce zaszkodzić: nie wymaga napisania ani jednego słowa, więc nie rusza
 * `WykrywaczSygnalow`, który pracuje na tekście.
 *
 * CZEGO TE TESTY PILNUJĄ
 * Granicy, tej samej co przy wpisach: automat PODNOSI RĘKĘ, nigdy nie zamyka
 * drzwi. Awatar zostaje widoczny, autor niczego się nie dowiaduje, powstaje
 * jedna pozycja dla człowieka. I drugiej rzeczy, specyficznej dla zdjęć:
 * celem oznaczenia jest KONKRETNY PLIK, nie konto — inaczej „jedno
 * oznaczenie automatu na treść" znaczyłoby „pierwszy awatar tego konta
 * i już nigdy więcej".
 */
class ModeracjaZdjeciaProfilowegoTest extends TestCase
{
    use RefreshDatabase;

    private const ALARM = 'moderacja@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'kuking.moderation.model.klucz' => 'testowy-klucz',
            'kuking.moderation.model.alarm_email' => self::ALARM,
            'kuking.moderation.model.ocenia_zdjecia' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // NIE WYCHODZI (D-240)
    // ---------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function stanyAwatara(): array
    {
        return [
            'gotowy, model odpowiedzialby „nienawisc"' => ['hate'],
            'gotowy, model odpowiedzialby „tresc seksualna"' => ['sexual'],
            'w przetwarzaniu' => ['processing'],
        ];
    }

    #[Test]
    #[DataProvider('stanyAwatara')]
    public function test_zadanie_z_kolejki_nie_wysyla_zdjecia_profilowego(string $stan): void
    {
        Notification::fake();
        $this->modelOdpowiada([$stan === 'sexual' ? 'sexual' : 'hate' => 0.93]);

        $osoba = $this->user('awatarowa');
        $zdjecie = $this->awatar($osoba);

        if ($stan === 'processing') {
            $zdjecie->forceFill(['status' => Media::STATUS_PROCESSING])->save();
        }

        // Zadanie mogło zostać w kolejce sprzed wdrożenia D-240.
        $this->analizuj($zdjecie);

        Http::assertNothingSent();
        $this->assertSame(0, Report::query()->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function test_awatar_zostaje_widoczny_a_autor_niczego_sie_nie_dowiaduje(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);

        $osoba = $this->user('bezkonsekwencji');
        $zdjecie = $this->awatar($osoba);

        $this->analizuj($zdjecie);

        $osoba->refresh();
        $this->assertSame(
            (string) $zdjecie->getKey(),
            (string) $osoba->profile->avatar_media_id,
            'Automat podmienił komuś awatar — to jest shadow filtering (D-052 poz. 3.16).',
        );
        $this->assertSame(Media::STATUS_READY, $zdjecie->refresh()->status);
        $this->assertSame(0, ModerationAction::query()->count());
        $this->assertSame(0, PowiadomienieWSerwisie::query()->where('user_id', $osoba->getKey())->count());
    }

    // ---------------------------------------------------------------
    // DROGA OD FORMULARZA I EKRAN MODERATORA
    // ---------------------------------------------------------------

    #[Test]
    public function test_wgranie_zdjecia_nie_zleca_oceny_modelem(): void
    {
        Queue::fake();

        $osoba = $this->user('wgrywajaca');

        $this->actingAs($osoba)
            ->post(route('settings.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('ja.jpg', 800, 800),
            ])
            ->assertRedirect(route('settings.avatar'));

        Queue::assertNotPushed(PrzeanalizujAwatar::class);
    }

    #[Test]
    public function test_kolejka_pokazuje_miniature_zdjecia_i_prowadzi_na_profil(): void
    {
        Notification::fake();

        $osoba = $this->user('ogladana');
        $zdjecie = $this->awatar($osoba);
        $this->oznaczenieSprzedD239($zdjecie);

        // `moderator()` z `TestCase`, bo bez POTWIERDZONEGO 2FA żądanie
        // odbija się o `EnsureModeratorHasTwoFactor` (403), zanim dojdzie do
        // sprawdzanej rzeczy.
        $odpowiedz = $this->actingAs($this->moderator())->get(route('admin.sygnaly'))->assertOk();

        $odpowiedz->assertSee('Zdjęcie profilowe — przy nim nie ma żadnego tekstu.', false);
        $odpowiedz->assertSee('Otwórz profil i zobacz to zdjęcie');
        $odpowiedz->assertSee($zdjecie->url('thumb'), false);
    }

    #[Test]
    public function test_przy_zdjeciu_nie_wolno_ukryc_ani_usunac(): void
    {
        Notification::fake();

        $osoba = $this->user('decyzyjna');
        $this->oznaczenieSprzedD239($this->awatar($osoba));

        $oznaczenie = Report::query()->where('source', Report::SOURCE_AUTOMAT)->sole();

        $moderator = $this->moderator();

        foreach ([ModerationAction::ACTION_HIDE, ModerationAction::ACTION_REMOVE] as $decyzja) {
            $this->actingAs($moderator)
                ->from(route('admin.reports'))
                ->post(route('admin.reports.decide', $oznaczenie), [
                    'action' => $decyzja,
                    'reason_code' => 'inne',
                    'note' => 'Próba decyzji, której przy zdjęciu nie ma.',
                ])
                ->assertSessionHasErrors();
        }

        // Sprawa zostaje otwarta, zdjęcie nietknięte, autor bez powiadomienia.
        $this->assertTrue($oznaczenie->refresh()->isOpen());
        $this->assertSame(0, ModerationAction::query()->count());
    }

    // ---------------------------------------------------------------
    // POMOCNICZE
    // ---------------------------------------------------------------

    /**
     * Awatar z PRAWDZIWYM wariantem `thumb`, bo `OcenaModelem` dekoduje go
     * przez GD. Atrapa z napisem „obrazek" przeszłaby przez zapis do dysku
     * i wywaliła się dopiero w bibliotece — czyli test pokazywałby brak
     * oznaczenia z zupełnie innego powodu, niż się wydaje.
     */
    private function awatar(User $osoba): Media
    {
        $thumb = 'media/awatary/'.Str::uuid()->toString().'_thumb.webp';

        Storage::disk('public')->put(
            $thumb,
            (string) ImageManager::gd()->create(320, 320)->fill('cc4400')->toWebp(),
        );

        $zdjecie = Media::factory()->create([
            'owner_id' => $osoba->getKey(),
            'disk' => 'public',
            'object_key' => 'incoming/awatary/'.Str::uuid()->toString().'.jpg',
            'mime_type' => 'image/jpeg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => ['thumb' => ['key' => $thumb, 'width' => 320, 'height' => 320]]],
        ]);

        Profile::query()->where('user_id', $osoba->getKey())->update(['avatar_media_id' => $zdjecie->getKey()]);

        return $zdjecie->refresh();
    }

    /**
     * Oznaczenie awatara, jakie postawiał automat przed D-240. Takie wiersze
     * dalej leżą w kolejce i moderator musi móc je rozpatrzyć.
     */
    private function oznaczenieSprzedD239(Media $zdjecie): void
    {
        app(OznaczDoPrzegladu::class)->handle($zdjecie, [
            new Sygnal(OcenaModelem::KOD, 'Zdjęcie profilowe: mowa nienawiści', false),
        ]);
    }

    private function analizuj(Media $zdjecie): void
    {
        dispatch_sync(new PrzeanalizujAwatar((string) $zdjecie->getKey()));
    }

    /** @param array<string, float> $wyniki */
    private function modelOdpowiada(array $wyniki): void
    {
        Http::fake([
            '*api.openai.com*' => Http::response([
                'results' => [[
                    'flagged' => false,
                    'category_scores' => $wyniki,
                ]],
            ]),
        ]);
    }
}
