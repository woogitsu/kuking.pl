<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Domain\Posts\KontoNieMozePublikowac;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublikacjaWpisuPoKarzeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function stanyZamkniete(): array
    {
        return [
            'zawieszone' => [User::STATUS_SUSPENDED],
            'zbanowane' => [User::STATUS_BANNED],
            'oczekujące na usunięcie' => [User::STATUS_PENDING_DELETE],
            'wymazane' => [User::STATUS_ERASED],
        ];
    }

    #[Test]
    #[DataProvider('stanyZamkniete')]
    public function nieaktualny_model_autora_nie_publikuje_po_zmianie_statusu(string $status): void
    {
        $autor = $this->user('autor');
        $nieaktualny = User::findOrFail($autor->getKey());
        DB::table('users')->where('id', $autor->getKey())->update([
            'status' => $status,
            'delete_scope' => in_array($status, [User::STATUS_PENDING_DELETE, User::STATUS_ERASED], true)
                ? User::DELETE_SCOPE_MINIMUM : null,
            'delete_requested_at' => in_array($status, [User::STATUS_PENDING_DELETE, User::STATUS_ERASED], true)
                ? now() : null,
            'data_erased_at' => $status === User::STATUS_ERASED ? now() : null,
        ]);

        try {
            app(PublishPost::class)->handle($nieaktualny, 'Rosół po decyzji moderatora.');
            $this->fail('Publikacja po zmianie statusu nie została odrzucona.');
        } catch (KontoNieMozePublikowac $e) {
            $this->assertStringContainsString('Odśwież stronę', $e->getMessage());
        }

        $this->assertSame(0, Post::where('author_id', $autor->getKey())->count());
        $this->assertSame(0, DB::table('audit_log')->where('actor_id', $autor->getKey())->where('action', 'post.published')->count());
    }

    #[Test]
    public function wygasle_zawieszenie_pozwala_publikowac_a_ponowienie_jest_idempotentne(): void
    {
        $autor = $this->user('autor');
        $nieaktualny = User::findOrFail($autor->getKey());
        DB::table('users')->where('id', $autor->getKey())->update([
            'status' => User::STATUS_SUSPENDED,
            'status_expires_at' => now()->subMinute(),
        ]);
        $klucz = (string) Str::uuid();

        $pierwszy = app(PublishPost::class)->handle($nieaktualny, 'Rosół po końcu kary.', kluczWyslania: $klucz);
        $powtorka = app(PublishPost::class)->handle($nieaktualny, 'Rosół po końcu kary.', kluczWyslania: $klucz);

        $this->assertSame($pierwszy->getKey(), $powtorka->getKey());
        $this->assertSame(User::STATUS_ACTIVE, $autor->fresh()->status);
        $this->assertSame(1, Post::where('author_id', $autor->getKey())->count());
    }
}
