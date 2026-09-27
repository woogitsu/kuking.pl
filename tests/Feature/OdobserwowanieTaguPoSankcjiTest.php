<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\Actions\UpdateTagFollows;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #2091: sankcja blokuje nowe relacje, ale nie utrudnia ich cofnięcia. */
final class OdobserwowanieTaguPoSankcjiTest extends TestCase
{
    use RefreshDatabase;

    public function test_nieaktywne_konto_moze_cofnac_obserwowanie_przez_formularz_i_przycisk(): void
    {
        $user = $this->user();
        $a = Tag::factory()->create();
        $b = Tag::factory()->create();
        $user->followedTags()->attach([$a->getKey(), $b->getKey()], ['created_at' => now()]);
        $timestamp = DB::table('tag_follows')->where('user_id', $user->getKey())
            ->where('tag_id', $a->getKey())->value('created_at');
        $stale = User::query()->findOrFail($user->getKey());
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        app(UpdateTagFollows::class)->save($stale, [], [
            'shown' => [$a->getKey()], 'followed' => [$a->getKey() => $timestamp],
        ]);
        app(UpdateTagFollows::class)->unfollow($stale, $b->getKey());

        $this->assertSame(0, DB::table('tag_follows')->where('user_id', $user->getKey())->count());
    }

    public function test_formularz_z_nowym_tagiem_nie_czysci_starego_po_sankcji(): void
    {
        $user = $this->user();
        $a = Tag::factory()->create();
        $b = Tag::factory()->create();
        $user->followedTags()->attach($a->getKey(), ['created_at' => now()]);
        $timestamp = DB::table('tag_follows')->where('user_id', $user->getKey())
            ->where('tag_id', $a->getKey())->value('created_at');
        $stale = User::query()->findOrFail($user->getKey());
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        try {
            app(UpdateTagFollows::class)->save($stale, [$b->getKey()], [
                'shown' => [$a->getKey(), $b->getKey()],
                'followed' => [$a->getKey() => $timestamp],
            ]);
            $this->fail('Formularz dodał tag po zawieszeniu konta.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame('To konto jest niedostępne.', $e->getMessage());
        }

        $this->assertSame([$a->getKey()], DB::table('tag_follows')->where('user_id', $user->getKey())
            ->pluck('tag_id')->all());
        $this->assertSame($timestamp, DB::table('tag_follows')->where('user_id', $user->getKey())
            ->where('tag_id', $a->getKey())->value('created_at'));
    }
}
