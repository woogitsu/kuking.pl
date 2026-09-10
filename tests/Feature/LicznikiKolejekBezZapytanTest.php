<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\KolejkiPanelu;
use App\Models\Appeal;
use App\Models\ContactMessage;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LICZNIKI PRZY POZYCJACH PANELU NIE MOGĄ KOSZTOWAĆ ZAPYTAŃ NA ODSŁONĘ
 * (`App\Domain\Moderation\KolejkiPanelu`).
 *
 * DLACZEGO TO WYMAGA POMIARU, A NIE ZAŁOŻENIA (AGENTS.md §3)
 * Menu boczne panelu stoi na KAŻDEJ stronie `/admin/**`, a kolejek jest
 * pięć. Naturalne rozwiązanie — pięć `COUNT(*)` w bloku `@php` layoutu —
 * wygląda w kodzie zupełnie niewinnie i nikt go nie zauważy przy recenzji.
 *
 * METODA — DWA RÓŻNE POMIARY, BO JEDEN NIE WYSTARCZA
 *
 *  1. `liczby()` (to, co robi widok) NIE PYTA BAZY ANI RAZ i nie zaczyna
 *     pytać, gdy w kolejkach robi się gęsto. To jest właściwa asercja na
 *     „zero zapytań per pozycja": pięć `COUNT(*)` dałoby tu pięć zapytań,
 *     a nie zero, niezależnie od zawartości kolejek. Sterownik cache
 *     w testach jest pamięciowy (`phpunit.xml`, `CACHE_STORE=array`); na
 *     produkcji (`database`) ten sam odczyt to JEDNO zapytanie do tabeli
 *     `cache` — jedno na całe menu, o stałym koszcie, nie pięć rosnących
 *     razem z kolejkami. Dlatego drugi pomiar mierzy CAŁĄ stronę:
 *
 *  2. liczba zapytań EKRANU PANELU nie rośnie, gdy kolejki puchną. Ten
 *     pomiar łapie coś, czego pierwszy nie łapie: doklejenie liczników
 *     przez relację albo `withCount` na liście pozycji menu, czyli wachlarz
 *     zapytań rosnący z zawartością (ten sam wzorzec co
 *     `MiniaturyBezWachlarzaZapytanTest`).
 *
 * Ekran do pomiaru: `/admin/tagi-promowane`. Wybrany świadomie — jego własna
 * treść nie ma nic wspólnego ze zgłoszeniami, odwołaniami, wiadomościami ani
 * wpisami bez odpowiedzi, więc wszystko, co się w pomiarze zmienia, pochodzi
 * od menu, a nie od zawartości strony.
 */
class LicznikiKolejekBezZapytanTest extends TestCase
{
    use RefreshDatabase;

    private ?User $autor = null;

    private ?User $moderatorKolejek = null;

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    /**
     * Napełnia wszystkie pięć kolejek podaną liczbą pozycji.
     *
     * Autor i moderator powstają RAZ na test (`??=` niżej), bo metoda jest
     * wołana dwa razy w każdym pomiarze — drugie wywołanie z tą samą nazwą
     * konta odbijałoby się o `UNIQUE (profiles.username)`.
     */
    private function napelnijKolejki(int $ile): void
    {
        $autor = $this->autor ??= $this->user('autor_do_kolejek');
        $moderator = $this->moderatorKolejek ??= $this->moderator();

        for ($i = 0; $i < $ile; $i++) {
            // Zgłoszenie od człowieka.
            Report::create([
                'reporter_id' => $autor->getKey(),
                'target_type' => 'post',
                'target_id' => (string) Str::uuid7(),
                'reason' => 'spam',
                'status' => Report::STATUS_OPEN,
            ]);

            // Oznaczenie automatu.
            Report::create([
                'reporter_id' => null,
                'source' => Report::SOURCE_AUTOMAT,
                'target_type' => 'post',
                'target_id' => (string) Str::uuid7(),
                'reason' => 'automat_wzorzec',
                'details' => 'Znany wzorzec spamu.',
                'status' => Report::STATUS_OPEN,
            ]);

            // Odwołanie od decyzji.
            $decyzja = ModerationAction::create([
                'moderator_id' => $moderator->getKey(),
                'target_type' => 'post',
                'target_id' => (string) Str::uuid7(),
                'subject_user_id' => $autor->getKey(),
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'spam-reklama',
                'user_message' => 'Wpis wygląda na reklamę.',
            ]);

            Appeal::create([
                'moderation_action_id' => $decyzja->getKey(),
                'user_id' => $autor->getKey(),
                'appellant' => Appeal::APPELLANT_AUTHOR,
                'body' => 'To nie była reklama, tylko przepis mojej mamy.',
                'status' => Appeal::STATUS_OPEN,
            ]);

            // Wiadomość z „Napisz do nas".
            ContactMessage::factory()->create(['status' => ContactMessage::STATUS_NOWA]);

            // Wpis bez odpowiedzi.
            Post::factory()->for($autor, 'author')->create([
                'published_at' => now()->subMinutes($i + 1),
                'visibility' => Post::VISIBILITY_PUBLIC,
            ]);
        }
    }

    public function test_odczyt_licznikow_nie_pyta_bazy_ani_raz(): void
    {
        $kolejki = app(KolejkiPanelu::class);

        $this->napelnijKolejki(2);
        $kolejki->przelicz();

        $maloZapytan = $this->policzZapytania(function () use ($kolejki): void {
            $liczby = $kolejki->liczby();
            $this->assertSame(2, $liczby['odwolania'], 'asercja kontrolna: kolejki naprawdę mają zawartość');
        });

        $this->napelnijKolejki(20);
        $kolejki->przelicz();

        $duzoZapytan = $this->policzZapytania(function () use ($kolejki): void {
            $liczby = $kolejki->liczby();
            $this->assertSame(22, $liczby['odwolania'], 'asercja kontrolna: kolejka naprawdę spuchła');
        });

        fwrite(STDERR, sprintf(
            "\n[liczniki kolejek] odczyt przy 2 pozycjach: %d zapytan, przy 22: %d zapytan\n",
            $maloZapytan,
            $duzoZapytan,
        ));

        $this->assertSame(
            0,
            $maloZapytan,
            'Odczyt liczników w widoku musi być czystym odczytem z cache — pięć COUNT(*) '
            .'na każdej stronie panelu jest dokładnie tym, czego ta klasa ma nie dopuścić.',
        );

        $this->assertSame(
            0,
            $duzoZapytan,
            'Koszt odczytu liczników nie może zależeć od zawartości kolejek.',
        );
    }

    public function test_liczba_zapytan_ekranu_panelu_nie_rosnie_z_kolejkami(): void
    {
        $moderator = $this->moderator();

        // Rozgrzewka: pierwsza odsłona po zalogowaniu robi rzeczy, które
        // z licznikami nie mają nic wspólnego (sesja, zapis ostatniej wizyty).
        $this->actingAs($moderator)->get(route('admin.tag-promotions'))->assertOk();

        $this->napelnijKolejki(2);
        app(KolejkiPanelu::class)->przelicz();

        $maloZapytan = $this->policzZapytania(
            fn () => $this->actingAs($moderator)->get(route('admin.tag-promotions'))->assertOk(),
        );

        $this->napelnijKolejki(20);
        app(KolejkiPanelu::class)->przelicz();

        $duzoZapytan = $this->policzZapytania(
            fn () => $this->actingAs($moderator)->get(route('admin.tag-promotions'))->assertOk(),
        );

        $this->assertSame(
            22,
            Appeal::query()->count(),
            'asercja kontrolna: w bazie musi naprawdę leżeć 22 odwołania, inaczej test nie mierzy niczego',
        );

        fwrite(STDERR, sprintf(
            "\n[liczniki kolejek /admin/tagi-promowane] malo (2 na kolejke): %d zapytan, duzo (22): %d zapytan\n",
            $maloZapytan,
            $duzoZapytan,
        ));

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Ekran panelu: {$maloZapytan} zapytań przy 2 pozycjach na kolejkę, {$duzoZapytan} przy 22 — "
            .'liczba zapytań menu rośnie razem z kolejkami.',
        );
    }
}
