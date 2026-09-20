<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\WyslijLinkDoLogowania;
use App\Models\LoginLinkToken;
use App\Notifications\LinkDoLogowania;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NieaktualnyLinkWKolejceTest extends TestCase
{
    use RefreshDatabase;

    public static function delays(): array
    {
        return ['od razu' => [0, 1], 'sekunda przed' => [1799, 1], 'dokladnie termin' => [1800, 0], 'po terminie' => [1801, 0]];
    }

    #[DataProvider('delays')]
    public function test_kolejka_respektuje_termin_i_nie_odnawia_tokenu(int $seconds, int $count): void
    {
        $this->freezeTime();
        config(['mail.default' => 'array', 'kuking.login_link.waznosc_minut' => 30]);
        Queue::fake();
        $user = $this->user();
        $this->assertTrue(app(WyslijLinkDoLogowania::class)->handle($user->email));
        $job = Queue::pushed(SendQueuedNotifications::class)->sole();
        $payload = serialize($job);
        $before = LoginLinkToken::sole()->getAttributes();
        $this->travel($seconds)->seconds();
        // Zmiana konfiguracji nie wydłuża obietnicy dla istniejącego tokenu.
        config(['kuking.login_link.waznosc_minut' => 60]);
        unserialize($payload)->handle(app(ChannelManager::class));
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount($count, $transport->messages(), 'Nieaktualny link nie może dotrzeć do transportu.');
        $this->assertSame($before, LoginLinkToken::sole()->getAttributes());
        if ($count) {
            $html = $transport->messages()->sole()->getOriginalMessage()->getHtmlBody();
            $this->assertStringContainsString('przez pół godziny od chwili zamówienia', $html);
        }
    }

    public function test_zastapiony_link_nie_wychodzi_po_nowszym(): void
    {
        $this->freezeTime();
        config(['mail.default' => 'array']);
        Queue::fake();
        $user = $this->user();
        $action = app(WyslijLinkDoLogowania::class);
        $this->assertTrue($action->handle($user->email));
        $old = serialize(Queue::pushed(SendQueuedNotifications::class)->last());
        $this->assertTrue($action->handle($user->email));
        $new = serialize(Queue::pushed(SendQueuedNotifications::class)->last());
        $before = LoginLinkToken::sole()->getAttributes();
        unserialize($new)->handle(app(ChannelManager::class));
        unserialize($old)->handle(app(ChannelManager::class));
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages(), 'Zastąpiony link nie może być wysłany.');
        $this->assertSame($before, LoginLinkToken::sole()->getAttributes());
    }

    public function test_stary_job_bez_terminu_sprawdza_baze(): void
    {
        $this->freezeTime();
        config(['mail.default' => 'array']);
        $user = $this->user();
        $token = str_repeat('a', 64);
        $row = new LoginLinkToken;
        $row->user_id = $user->id;
        $row->token_hash = LoginLinkToken::skrot($token);
        $row->created_at = now();
        $row->expires_at = now()->addMinutes(30);
        $row->save();
        $payload = serialize(new SendQueuedNotifications($user, new LinkDoLogowania($token), ['mail']));
        unserialize($payload)->handle(app(ChannelManager::class));
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->travel(31)->minutes();
        unserialize($payload)->handle(app(ChannelManager::class));
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages(), 'Stary job bez terminu też nie wysyła wygasłego linku.');
    }

    public function test_przekazany_wczesniejszy_termin_tez_zamyka_wysylke(): void
    {
        $this->freezeTime();
        config(['mail.default' => 'array']);
        $user = $this->user();
        $token = str_repeat('b', 64);
        $row = new LoginLinkToken;
        $row->user_id = $user->id;
        $row->token_hash = LoginLinkToken::skrot($token);
        $row->created_at = now();
        $row->expires_at = now()->addMinutes(30);
        $row->save();
        $payload = serialize(new SendQueuedNotifications($user, new LinkDoLogowania($token, now()->addMinute()), ['mail']));
        unserialize($payload)->handle(app(ChannelManager::class));
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->travel(60)->seconds();
        $this->assertTrue($row->fresh()->jestWazny());
        unserialize($payload)->handle(app(ChannelManager::class));
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }
}
