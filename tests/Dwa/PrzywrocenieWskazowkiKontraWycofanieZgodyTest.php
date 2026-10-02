<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * „Przywróć wskazówkę” przez moderatora kontra „Wycofaj zgodę” kucharza (#2352,
 * decyzja właściciela z 1.10.2026).
 *
 * Przywrócenie bierze zamek jak ukrycie (`ZamekUprzywilejowanegoAktora`: zamek
 * ról, potem aktor, kucharz i autor przepisu w JEDNYM przebiegu rosnąco po
 * `id`, potem wiersz wskazówki), wycofanie — `ZamekPary` (kucharz i autor
 * rosnąco, potem wskazówka). Kolejność musi być ta sama także wtedy, gdy
 * moderator jest jednocześnie AUTOREM przepisu — tam przywrócenie odmawia (jest
 * stroną sprawy), ale odmowa pada dopiero po założeniu zamków, więc to też
 * nie może zakleszczyć się z wycofaniem.
 *
 * Wynik w obu kolejnościach jest spójny: wskazówka kończy jako `withdrawn`
 * (kucharz zawsze może wycofać zgodę); gdy przywrócenie wygrało, kucharz
 * dostał jedno powiadomienie „znowu widoczna”, a gdy przegrało wyścig o
 * zgodę, ukrycie jest zdjęte, ale nic nie wróciło publicznie i nikt nie
 * dostał wiadomości.
 */
#[Group('dwa-polaczenia')]
final class PrzywrocenieWskazowkiKontraWycofanieZgodyTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $wskazowki = [];

    protected function tearDown(): void
    {
        // `moderation_actions.moderator_id` ma `ON DELETE RESTRICT` — decyzje
        // muszą zniknąć przed kontami.
        if ($this->wskazowki !== []) {
            try {
                $this->nowePolaczenie()
                    ->prepare('DELETE FROM moderation_actions WHERE target_id = ANY(?::uuid[])')
                    ->execute(['{'.implode(',', $this->wskazowki).'}']);
            } catch (PDOException $e) {
                fwrite(STDERR, "\nSprzątanie moderacji nie powiodło się: ".$e->getMessage()."\n");
            }
        }

        parent::tearDown();
    }

    /** Przyjęta, ukryta wskazówka z decyzją `hide` w rejestrze (ukrył inny moderator). */
    private function ukrytaWskazowka(User $kucharz, User $autorPrzepisu): RecipeHint
    {
        $ukrywajacy = $this->konto(['role' => User::ROLE_MODERATOR]);
        $przepis = Recipe::factory()->for($autorPrzepisu, 'author')->create(['visibility' => 'public']);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'note' => 'Dodałem chrzan.',
        ]);
        $wskazowka = RecipeHint::factory()->dlaWykonania($wykonanie, RecipeHint::STATUS_ACCEPTED)->ukrytaPrzezModeracje()->create();
        $this->wskazowki[] = (string) $wskazowka->getKey();

        ModerationAction::create([
            'moderator_id' => $ukrywajacy->getKey(),
            'report_id' => null,
            'target_type' => 'recipe_hint',
            'target_id' => $wskazowka->getKey(),
            'subject_user_id' => $kucharz->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'cudze-dane-osobowe',
            'user_message' => 'W uwadze jest numer telefonu.',
        ]);

        return $wskazowka;
    }

    /**
     * Wyścig: bariera na wierszu konta o MNIEJSZYM `id` z pary (kucharz, autor
     * przepisu), potem dwa procesy w wybranej kolejności startu.
     *
     * @return array{array<string, mixed>, array<string, mixed>} wynik przywrócenia, wynik wycofania
     */
    private function wyscig(User $moderator, User $kucharz, User $autorPrzepisu, RecipeHint $wskazowka, bool $przywrocenieStartujePierwsze): array
    {
        $nizszy = strcmp((string) $kucharz->getKey(), (string) $autorPrzepisu->getKey()) < 0 ? $kucharz : $autorPrzepisu;
        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $nizszy->getKey()]);

        $przywrocenie = ['kto' => (string) $moderator->getKey(), 'wskazowka' => (string) $wskazowka->getKey()];
        $wycofanie = ['kto' => (string) $kucharz->getKey(), 'wskazowka' => (string) $wskazowka->getKey()];

        if ($przywrocenieStartujePierwsze) {
            $procesPrzywrocenia = $this->wTle('przywroc-wskazowke', $przywrocenie);
            $this->czekajNaZablokowane(1);
            $procesWycofania = $this->wTle('wycofaj-wskazowke', $wycofanie);
        } else {
            $procesWycofania = $this->wTle('wycofaj-wskazowke', $wycofanie);
            $this->czekajNaZablokowane(1);
            $procesPrzywrocenia = $this->wTle('przywroc-wskazowke', $przywrocenie);
        }
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikPrzywrocenia = $procesPrzywrocenia->wynik();
        $wynikWycofania = $procesWycofania->wynik();
        $this->assertBezZakleszczenia($wynikPrzywrocenia, 'przywrócenie wskazówki');
        $this->assertBezZakleszczenia($wynikWycofania, 'wycofanie zgody');

        return [$wynikPrzywrocenia, $wynikWycofania];
    }

    /** @return array<string, array{bool}> */
    public static function kolejnosci(): array
    {
        return ['przywrócenie pierwsze' => [true], 'wycofanie pierwsze' => [false]];
    }

    #[DataProvider('kolejnosci')]
    public function test_przywrocenie_przez_obcego_moderatora_kontra_wycofanie_zgody(bool $przywrocenieStartujePierwsze): void
    {
        $this->konto(['role' => User::ROLE_ADMIN]);
        $moderator = $this->konto(['role' => User::ROLE_MODERATOR]);
        $kucharz = $this->konto();
        $autor = $this->konto();
        $wskazowka = $this->ukrytaWskazowka($kucharz, $autor);

        [$przywrocenie, $wycofanie] = $this->wyscig($moderator, $kucharz, $autor, $wskazowka, $przywrocenieStartujePierwsze);

        $this->assertTrue($wycofanie['ok'], 'Wycofanie zgody padło: '.$wycofanie['komunikat']);
        $this->assertTrue($przywrocenie['ok'], 'Przywrócenie padło: '.$przywrocenie['komunikat']);

        $wiersz = DB::table('recipe_hints')->where('id', $wskazowka->getKey())->first(['status', 'moderation_hidden_at']);
        $powiadomien = DB::table('notifications')
            ->where('user_id', $kucharz->getKey())
            ->where('type', Notification::TYPE_MODERATION)
            ->count();
        $decyzji = ModerationAction::query()->where('target_id', $wskazowka->getKey())->where('action', ModerationAction::ACTION_UNHIDE)->count();

        // Kucharz zawsze wygrywa o zgodę; ukrycie jest zdjęte w obu kolejnościach.
        $this->assertSame(
            [RecipeHint::STATUS_WITHDRAWN, null, 1],
            [$wiersz->status, $wiersz->moderation_hidden_at, $decyzji],
        );
        // Wiadomość „znowu widoczna” tylko wtedy, gdy zgoda jeszcze obowiązywała.
        $this->assertSame(
            $przywrocenieStartujePierwsze ? ['widoczna', 1] : ['wycofana', 0],
            [$przywrocenie['wartosc'], $powiadomien],
        );
    }

    /**
     * Moderator jest AUTOREM przepisu — stroną sprawy, więc przywrócenie odmawia,
     * ale po założeniu zamków. Dwa układy `id` razy dwie kolejności startu.
     *
     * @return array<string, array{bool, bool}>
     */
    public static function ukladyModeratoraAutora(): array
    {
        return [
            'kucharz mniejszy, przywrócenie pierwsze' => [true, true],
            'kucharz mniejszy, wycofanie pierwsze' => [true, false],
            'kucharz większy, przywrócenie pierwsze' => [false, true],
            'kucharz większy, wycofanie pierwsze' => [false, false],
        ];
    }

    #[DataProvider('ukladyModeratoraAutora')]
    public function test_moderator_bedacy_autorem_przepisu_nie_zakleszcza_sie_z_wycofaniem_zgody_a_przywrocenie_odmawia(bool $kucharzMniejszy, bool $przywrocenieStartujePierwsze): void
    {
        $this->konto(['role' => User::ROLE_ADMIN]);
        [$mniejsze, $wieksze] = $this->paraPosortowana();
        [$kucharz, $moderator] = $kucharzMniejszy ? [$mniejsze, $wieksze] : [$wieksze, $mniejsze];
        $moderator->forceFill(['role' => User::ROLE_MODERATOR])->save();
        $wskazowka = $this->ukrytaWskazowka($kucharz, $moderator);

        [$przywrocenie, $wycofanie] = $this->wyscig($moderator, $kucharz, $moderator, $wskazowka, $przywrocenieStartujePierwsze);

        $this->assertTrue($wycofanie['ok'], 'Wycofanie zgody padło: '.$wycofanie['komunikat']);
        $this->assertFalse($przywrocenie['ok'], 'Moderator będący autorem przepisu przywrócił wskazówkę.');
        $this->assertSame(BladDlaCzlowieka::class, $przywrocenie['wyjatek'], 'Odmowa ma być komunikatem dla człowieka, nie błędem bazy: '.$przywrocenie['komunikat']);
        $this->assertNull($przywrocenie['sqlstate']);

        $wiersz = DB::table('recipe_hints')->where('id', $wskazowka->getKey())->first(['status', 'moderation_hidden_at']);
        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, $wiersz->status);
        $this->assertNotNull($wiersz->moderation_hidden_at, 'Odmowa nie może zdjąć ukrycia.');
        $this->assertSame(0, ModerationAction::query()->where('target_id', $wskazowka->getKey())->where('action', ModerationAction::ACTION_UNHIDE)->count());
    }
}
