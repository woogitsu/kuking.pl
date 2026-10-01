<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ukrycie wskazówki przez moderatora kontra „Wycofaj zgodę” kucharza (#2352).
 *
 * Obie ścieżki biorą wiersze kont i wskazówki: wycofanie przez `ZamekPary`
 * (kucharz i autor przepisu rosnąco po `id`, potem wiersz wskazówki),
 * ukrycie przez `ZamekUprzywilejowanegoAktora` (zamek ról, potem aktor) i
 * `RecipeHint::zablokujDoDecyzji()`. Kolejność musi być ta sama, także gdy
 * rozstrzygający moderator jest jednocześnie AUTOREM PRZEPISU — wtedy jego
 * wiersz był brany jako pierwszy, niezależnie od `id`, i odwracał kolejność
 * względem wycofania (40P01).
 *
 * Wyniki obu układów muszą być spójne: wskazówka kończy jako `withdrawn`
 * (kucharz zawsze może wycofać zgodę), a ukrycie jest zapisane wtedy i tylko
 * wtedy, gdy moderator był pierwszy; przegrany moderator dostaje komunikat
 * przy polu „Decyzja”, nie błąd bazy.
 */
#[Group('dwa-polaczenia')]
final class UkrycieWskazowkiKontraWycofanieZgodyTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $zgloszenia = [];

    /** @var list<string> */
    private array $wskazowki = [];

    protected function tearDown(): void
    {
        // `moderation_actions.moderator_id` ma `ON DELETE RESTRICT` — decyzje
        // muszą zniknąć przed kontami.
        if ($this->zgloszenia !== []) {
            try {
                $polaczenie = $this->nowePolaczenie();
                $zgloszenia = '{'.implode(',', $this->zgloszenia).'}';
                $wskazowki = '{'.implode(',', $this->wskazowki).'}';
                $polaczenie->prepare('DELETE FROM moderation_actions WHERE report_id = ANY(?::uuid[]) OR target_id = ANY(?::uuid[])')
                    ->execute([$zgloszenia, $wskazowki]);
                $polaczenie->prepare('DELETE FROM reports WHERE id = ANY(?::uuid[])')->execute([$zgloszenia]);
            } catch (PDOException $e) {
                fwrite(STDERR, "\nSprzątanie moderacji nie powiodło się: ".$e->getMessage()."\n");
            }
        }

        parent::tearDown();
    }

    /**
     * @return array{User, RecipeHint, Report} kucharz, przyjęta wskazówka, zgłoszenie
     */
    private function swiat(User $kucharz, User $autorPrzepisu): array
    {
        $this->konto(['role' => User::ROLE_ADMIN]);
        $przepis = Recipe::factory()->for($autorPrzepisu, 'author')->create(['visibility' => 'public']);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'note' => 'Dodałem chrzan.',
        ]);
        $wskazowka = RecipeHint::factory()->dlaWykonania($wykonanie, RecipeHint::STATUS_ACCEPTED)->create();
        $this->wskazowki[] = (string) $wskazowka->getKey();

        $zgloszenie = Report::create([
            'reporter_id' => $this->konto()->getKey(),
            'target_type' => 'recipe_hint',
            'target_id' => $wskazowka->getKey(),
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);
        $this->zgloszenia[] = (string) $zgloszenie->getKey();

        return [$kucharz, $wskazowka, $zgloszenie];
    }

    /**
     * Wyścig: bariera na wierszu konta o MNIEJSZYM `id` z pary (kucharz,
     * autor przepisu), potem dwa procesy w wybranej kolejności startu.
     *
     * @return array{array<string, mixed>, array<string, mixed>} wynik ukrycia, wynik wycofania
     */
    private function wyscig(User $moderator, User $kucharz, User $autorPrzepisu, RecipeHint $wskazowka, Report $zgloszenie, bool $moderatorPierwszy): array
    {
        $niższy = strcmp((string) $kucharz->getKey(), (string) $autorPrzepisu->getKey()) < 0 ? $kucharz : $autorPrzepisu;
        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $niższy->getKey()]);

        $ukrycie = ['kto' => (string) $moderator->getKey(), 'zgloszenie' => (string) $zgloszenie->getKey(), 'akcja' => ModerationAction::ACTION_HIDE];
        $wycofanie = ['kto' => (string) $kucharz->getKey(), 'wskazowka' => (string) $wskazowka->getKey()];

        if ($moderatorPierwszy) {
            $procesUkrycia = $this->wTle('decyzja-zgloszenia', $ukrycie);
            $this->czekajNaZablokowane(1);
            $procesWycofania = $this->wTle('wycofaj-wskazowke', $wycofanie);
        } else {
            $procesWycofania = $this->wTle('wycofaj-wskazowke', $wycofanie);
            $this->czekajNaZablokowane(1);
            $procesUkrycia = $this->wTle('decyzja-zgloszenia', $ukrycie);
        }
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikUkrycia = $procesUkrycia->wynik();
        $wynikWycofania = $procesWycofania->wynik();
        $this->assertBezZakleszczenia($wynikUkrycia, 'ukrycie wskazówki');
        $this->assertBezZakleszczenia($wynikWycofania, 'wycofanie zgody');

        return [$wynikUkrycia, $wynikWycofania];
    }

    /**
     * @param  array<string, mixed>  $wynikUkrycia
     * @param  array<string, mixed>  $wynikWycofania
     */
    private function assertSpojnyKoniec(RecipeHint $wskazowka, array $wynikUkrycia, array $wynikWycofania): void
    {
        $this->assertTrue($wynikWycofania['ok'], 'Wycofanie zgody padło: '.$wynikWycofania['komunikat']);
        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, DB::table('recipe_hints')->where('id', $wskazowka->getKey())->value('status'));

        $ukryto = DB::table('recipe_hints')->where('id', $wskazowka->getKey())->value('moderation_hidden_at') !== null;
        $decyzji = ModerationAction::query()->where('target_id', $wskazowka->getKey())->where('action', ModerationAction::ACTION_HIDE)->count();
        $this->assertSame((int) $ukryto, $decyzji, 'Ślad ukrycia i decyzja `hide` muszą iść razem.');
        // Moderator, który przegrał, dostaje komunikat przy polu „Decyzja”, nie błąd bazy.
        if ($ukryto) {
            $this->assertTrue($wynikUkrycia['ok'], 'Ukrycie padło: '.$wynikUkrycia['komunikat']);
        } else {
            $this->assertSame(ValidationException::class, $wynikUkrycia['wyjatek'], 'Przegrany moderator ma dostać błąd walidacji, nie błąd bazy: '.$wynikUkrycia['komunikat']);
            $this->assertNull($wynikUkrycia['sqlstate']);
        }
    }

    /** @return array<string, array{bool}> */
    public static function kolejnosci(): array
    {
        return ['moderator pierwszy' => [true], 'kucharz pierwszy' => [false]];
    }

    #[DataProvider('kolejnosci')]
    public function test_ukrycie_przez_obcego_moderatora_kontra_wycofanie_zgody(bool $moderatorPierwszy): void
    {
        $moderator = $this->konto(['role' => User::ROLE_MODERATOR]);
        [$kucharz, $wskazowka, $zgloszenie] = $this->swiat($this->konto(), $this->konto());
        $autor = User::query()->findOrFail(Recipe::query()->findOrFail($wskazowka->recipe_id)->author_id);

        [$ukrycie, $wycofanie] = $this->wyscig($moderator, $kucharz, $autor, $wskazowka, $zgloszenie, $moderatorPierwszy);

        $this->assertSpojnyKoniec($wskazowka, $ukrycie, $wycofanie);
    }

    /**
     * Przypadek szczególny: rozstrzygający moderator jest AUTOREM przepisu.
     * Dwa układy `id` (kucharz mniejszy i większy od moderatora) razy dwie
     * kolejności startu — zakleszczenie dawał układ „kucharz mniejszy,
     * wycofanie pierwsze”.
     *
     * @return array<string, array{bool, bool}>
     */
    public static function ukladyModeratoraAutora(): array
    {
        return [
            'kucharz mniejszy, moderator pierwszy' => [true, true],
            'kucharz mniejszy, kucharz pierwszy' => [true, false],
            'kucharz większy, moderator pierwszy' => [false, true],
            'kucharz większy, kucharz pierwszy' => [false, false],
        ];
    }

    #[DataProvider('ukladyModeratoraAutora')]
    public function test_moderator_bedacy_autorem_przepisu_nie_zakleszcza_sie_z_wycofaniem_zgody(bool $kucharzMniejszy, bool $moderatorPierwszy): void
    {
        // Para kont w żądanym układzie `id`: rola moderatora trafia na to konto
        // pary, które ma być moderatorem (autorem przepisu).
        [$mniejsze, $wieksze] = $this->paraPosortowana();
        [$kucharz, $moderator] = $kucharzMniejszy ? [$mniejsze, $wieksze] : [$wieksze, $mniejsze];
        $moderator->forceFill(['role' => User::ROLE_MODERATOR])->save();

        [$kucharz, $wskazowka, $zgloszenie] = $this->swiat($kucharz, $moderator);

        [$ukrycie, $wycofanie] = $this->wyscig($moderator, $kucharz, $moderator, $wskazowka, $zgloszenie, $moderatorPierwszy);

        $this->assertSpojnyKoniec($wskazowka, $ukrycie, $wycofanie);
    }
}
