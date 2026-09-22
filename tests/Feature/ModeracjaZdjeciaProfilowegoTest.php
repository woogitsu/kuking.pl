<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\AlarmujModeratora;
use App\Domain\Moderation\Actions\OznaczDoPrzegladu;
use App\Jobs\PrzeanalizujAwatar;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Notification as PowiadomienieWSerwisie;
use App\Models\Profile;
use App\Models\Report;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use App\Notifications\PilnyAlarmModeracyjny;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ZDJĘCIE PROFILOWE PRZECHODZI PRZEZ MODEL (issue #237).
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
    // ŁAPIE
    // ---------------------------------------------------------------

    #[Test]
    public function test_zdjecie_profilowe_trafia_do_kolejki_jako_oznaczenie_pliku(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);

        $osoba = $this->user('awatarowa');
        $zdjecie = $this->awatar($osoba);

        $this->analizuj($zdjecie);

        $oznaczenie = Report::query()->where('source', Report::SOURCE_AUTOMAT)->sole();

        // CELEM JEST PLIK, NIE KONTO — patrz komentarz klasy.
        $this->assertSame('media', $oznaczenie->target_type);
        $this->assertSame((string) $zdjecie->getKey(), (string) $oznaczenie->target_id);

        // …ale człowiek, którego to dotyczy, jest zapisany, bo kolejka
        // automatu grupuje po autorze i kara zawsze dotyczy człowieka.
        $this->assertSame((string) $osoba->getKey(), (string) $oznaczenie->autor_tresci_id);

        $this->assertSame(OcenaModelem::KOD, $oznaczenie->reason);

        // Moderator musi wiedzieć, NA CO patrzy, zanim otworzy podgląd.
        $this->assertStringContainsString('Zdjęcie profilowe', (string) $oznaczenie->details);
        $this->assertStringContainsString('mowa nienawiści', (string) $oznaczenie->details);
    }

    #[Test]
    public function test_awatar_zostaje_widoczny_a_autor_niczego_sie_nie_dowiaduje(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);

        $osoba = $this->user('bezkonsekwencji');
        $zdjecie = $this->awatar($osoba);

        $this->analizuj($zdjecie);

        // Zdjęcie nadal jest awatarem i nadal jest gotowe do pokazania.
        $osoba->refresh();
        $this->assertSame(
            (string) $zdjecie->getKey(),
            (string) $osoba->profile->avatar_media_id,
            'Automat podmienił komuś awatar — to jest shadow filtering (D-052 poz. 3.16).',
        );
        $this->assertSame(Media::STATUS_READY, $zdjecie->refresh()->status);

        // Żadnej decyzji i żadnego powiadomienia — bo nic się nie stało.
        $this->assertSame(0, ModerationAction::query()->count());
        $this->assertSame(0, PowiadomienieWSerwisie::query()->where('user_id', $osoba->getKey())->count());
    }

    #[Test]
    public function test_drugie_zdjecie_tego_samego_konta_dostaje_wlasne_oznaczenie(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);

        $osoba = $this->user('podmieniajaca');

        $pierwsze = $this->awatar($osoba);
        $this->analizuj($pierwsze);

        // Podmiana zdjęcia to sekunda pracy. Gdyby celem oznaczenia było
        // konto, indeks `reports_jeden_automat_na_tresc` przepuściłby tylko
        // pierwsze zdjęcie — i cała funkcja dałaby się obejść jednym klikiem.
        $drugie = $this->awatar($osoba);
        $this->analizuj($drugie);

        $cele = Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->pluck('target_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $this->assertCount(2, $cele);
        $this->assertContains((string) $pierwsze->getKey(), $cele);
        $this->assertContains((string) $drugie->getKey(), $cele);
    }

    #[Test]
    public function test_pilna_kategoria_alarmuje_moderatora_poczta(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['sexual' => 0.88]);

        $this->analizuj($this->awatar($this->user('pilna')));

        Notification::assertSentOnDemand(PilnyAlarmModeracyjny::class);
    }

    #[Test]
    public function test_do_modelu_idzie_przekodowana_miniatura_a_nie_oryginal(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);

        $this->analizuj($this->awatar($this->user('miniaturowa')));

        Http::assertSent(function ($zadanie): bool {
            $tresc = $zadanie->data()['input'][0] ?? [];
            $adres = (string) ($tresc['image_url']['url'] ?? '');

            // JPEG, nie WebP i nie oryginał: oryginał niesie pełny EXIF,
            // czyli współrzędne GPS kuchni (AGENTS.md §7).
            return str_starts_with($adres, 'data:image/jpeg;base64,')
                && ! str_contains($adres, 'incoming/');
        });
    }

    // ---------------------------------------------------------------
    // NIE ŁAPIE — I TO TEŻ MUSI BYĆ PILNOWANE
    // ---------------------------------------------------------------

    #[Test]
    public function test_zdjecie_ktore_przestalo_byc_awatarem_nie_jest_oceniane(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);

        $osoba = $this->user('rozmyslila');
        $zdjecie = $this->awatar($osoba);

        // Ktoś zdążył podmienić zdjęcie albo je usunąć, zanim zadanie
        // wyszło z kolejki. Oglądanie pliku, którego nikt już nie widzi,
        // dokładałoby moderatorowi pozycję za treść, której nie ma na ekranie.
        Profile::query()->where('user_id', $osoba->getKey())->update(['avatar_media_id' => null]);

        $this->analizuj($zdjecie);

        Http::assertNothingSent();
        $this->assertSame(0, Report::query()->count());
    }

    #[Test]
    public function test_zdjecie_w_przetwarzaniu_wraca_do_kolejki_zamiast_cicho_przepasc(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);

        $osoba = $this->user('wtrakcie');
        $zdjecie = $this->awatar($osoba);
        $zdjecie->forceFill(['status' => Media::STATUS_PROCESSING])->save();

        $zadanie = (new PrzeanalizujAwatar((string) $zdjecie->getKey()))->withFakeQueueInteractions();

        $zadanie->handle(
            app(OcenaModelem::class),
            app(OznaczDoPrzegladu::class),
            app(AlarmujModeratora::class),
        );

        // Wariant `thumb` powstaje w INNYM zadaniu, na innej kolejce. Gdyby
        // to zadanie kończyło się tu powodzeniem, cała funkcja działałaby
        // wyłącznie wtedy, gdy worker mediów wyprzedzi worker kolejki `low`.
        $zadanie->assertReleased(30);

        Http::assertNothingSent();
        $this->assertSame(0, Report::query()->count());
    }

    #[Test]
    public function test_odrzucone_zdjecie_nie_stawia_oznaczenia(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);

        $osoba = $this->user('odrzucone');
        $zdjecie = $this->awatar($osoba);
        $zdjecie->forceFill(['status' => Media::STATUS_REJECTED])->save();

        $this->analizuj($zdjecie);

        Http::assertNothingSent();
        $this->assertSame(0, Report::query()->count());
    }

    #[Test]
    public function test_awaria_modelu_nie_ma_zadnego_skutku(): void
    {
        Notification::fake();
        Http::fake(['*api.openai.com*' => Http::response('', 500)]);

        $osoba = $this->user('awaria');
        $zdjecie = $this->awatar($osoba);

        $this->analizuj($zdjecie);

        $this->assertSame(0, Report::query()->count());
        $this->assertSame(
            (string) $zdjecie->getKey(),
            (string) $osoba->refresh()->profile->avatar_media_id,
        );
    }

    #[Test]
    public function test_wylaczony_automat_nie_wysyla_zdjecia_nikomu(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);
        config(['kuking.moderation.sygnaly.wlaczone' => false]);

        $this->analizuj($this->awatar($this->user('wylaczony')));

        Http::assertNothingSent();
        $this->assertSame(0, Report::query()->count());
    }

    #[Test]
    public function test_wylaczona_ocena_zdjec_nie_wysyla_awatara(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);
        config(['kuking.moderation.model.ocenia_zdjecia' => false]);

        $this->analizuj($this->awatar($this->user('bezzdjec')));

        Http::assertNothingSent();
        $this->assertSame(0, Report::query()->count());
    }

    // ---------------------------------------------------------------
    // DROGA OD FORMULARZA I EKRAN MODERATORA
    // ---------------------------------------------------------------

    #[Test]
    public function test_wgranie_zdjecia_zleca_ocene_modelem(): void
    {
        Queue::fake();

        $osoba = $this->user('wgrywajaca');

        $this->actingAs($osoba)
            ->post(route('settings.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('ja.jpg', 800, 800),
            ])
            ->assertRedirect(route('settings.avatar'));

        // Bez tego zlecenia cała funkcja nie istnieje — a widać to tylko tu,
        // bo formularz działa identycznie w obu przypadkach.
        Queue::assertPushed(PrzeanalizujAwatar::class);
    }

    #[Test]
    public function test_kolejka_pokazuje_miniature_zdjecia_i_prowadzi_na_profil(): void
    {
        Notification::fake();
        $this->modelOdpowiada(['hate' => 0.93]);

        $osoba = $this->user('ogladana');
        $zdjecie = $this->awatar($osoba);
        $this->analizuj($zdjecie);

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
        $this->modelOdpowiada(['hate' => 0.93]);

        $osoba = $this->user('decyzyjna');
        $this->analizuj($this->awatar($osoba));

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
