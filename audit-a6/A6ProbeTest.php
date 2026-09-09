<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Compliance\PrzedawnionePowiadomienia;
use App\Models\User;
use App\Models\Post;
use App\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

// Sondy POTWIERDZAJA stan zastany, nie definiuja poprawnego zachowania.
// Zielona sonda z wartoscia bug=true jest potwierdzeniem usterki.
final class A6ProbeTest extends TestCase
{
    use RefreshDatabase;

    private function wynik(string $nazwa, array $dane): void
    {
        $dir = getenv('A6_OUT') ?: sys_get_temp_dir();
        file_put_contents($dir.'/'.$nazwa.'.json', json_encode($dane, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function test_oryginal_png_i_webp_przechodzi_przez_prawdziwy_zapis_bez_usuniecia_gps(): void
    {
        $pliki = [
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAwAAAAICAIAAABChommAAAAtmVYSWZJSSoACAAAAAMADwECAAoAAAAyAAAAMgECABQAAAA8AAAAJYgEAAEAAABQAAAAAAAAAFRlc3RQaG9uZQAyMDI2OjA5OjA3IDEyOjAwOjAwAAQAAQACAAIAAABOAAAAAgAFAAMAAACGAAAAAwACAAIAAABFAAAABAAFAAMAAACeAAAAAAAAADQAAAABAAAADQAAAAEAAADgFQAAZAAAABUAAAABAAAAAAAAAAEAAAAQDgAAZAAAAB69yjEAAAANSURBVHicY2AYBcQAAAEoAAFNS8/5AAAAAElFTkSuQmCC',
            'webp' => 'UklGRvQAAABXRUJQVlA4WAoAAAAIAAAACwAABwAAVlA4IBgAAAAwAQCdASoMAAgAAUAmJaQAA3AA/v02aABFWElGtgAAAElJKgAIAAAAAwAPAQIACgAAADIAAAAyAQIAFAAAADwAAAAliAQAAQAAAFAAAAAAAAAAVGVzdFBob25lADIwMjY6MDk6MDcgMTI6MDA6MDAABAABAAIAAgAAAE4AAAACAAUAAwAAAIYAAAADAAIAAgAAAEUAAAAEAAUAAwAAAJ4AAAAAAAAANAAAAAEAAAANAAAAAQAAAOAVAABkAAAAFQAAAAEAAAAAAAAAAQAAABAOAABkAAAA',
        ];
        Storage::fake('local');
        Queue::fake();
        $osoba = $this->user('kucharkaa6');
        $wynik = [];
        foreach ($pliki as $format => $b64) {
            $bajty = base64_decode($b64, true);
            $path = tempnam(sys_get_temp_dir(), 'a6-gps');
            file_put_contents($path, $bajty);
            $upload = new UploadedFile($path, 'syntetyczne.'.$format, 'image/'.$format, null, true);
            $media = app(StoreUploadedImage::class)->handle($osoba, $upload);
            $zapisane = Storage::disk($media->disk)->get($media->object_key);
            $wynik[$format] = ['media_saved' => $media->exists, 'unchanged_bytes' => $zapisane === $bajty, 'sha256' => hash('sha256', $zapisane)];
            $this->assertSame($bajty, $zapisane);
            file_put_contents((getenv('A6_OUT') ?: sys_get_temp_dir()).'/uploaded.'.$format, $zapisane);
            unlink($path);
        }
        $this->wynik('upload-gps', $wynik);
    }

    public function test_prawdziwy_blad_sql_przez_http_wysyla_email_i_hash_na_atrape_webhooka(): void
    {
        config(['logging.channels.blad_webhook.url' => 'https://a6.example.invalid/slack', 'app.debug' => false]);
        Http::fake();
        $user = $this->user('sqlosobaa6', ['email' => 'syntetyczny-a6@example.invalid']);
        $row = $user->getAttributes();
        $row['id'] = (string) Str::uuid7();
        Route::get('/_test/a6/sql', function () use ($row): void {
            DB::transaction(fn () => DB::table('users')->insert($row));
        })->middleware('web');
        $response = $this->get('/_test/a6/sql');
        $response->assertStatus(500);
        $text = implode("\n", Http::recorded()->map(fn ($pair) => (string) ($pair[0]['text'] ?? ''))->all());
        $this->wynik('webhook-http-sql', [
            'status' => $response->status(), 'requests' => Http::recorded()->count(),
            'email_in_payload' => str_contains($text, $row['email']),
            'password_hash_in_payload' => str_contains($text, $row['password']),
            'payload' => $text,
        ]);
        $this->assertStringContainsString($row['email'], $text);
        $this->assertStringContainsString($row['password'], $text);
    }

    public function test_dwa_odczyty_przed_zapisem_przyjmuja_ten_sam_totp(): void
    {
        $totp = app(TwoFactorAuthenticator::class);
        $user = $this->user('totposobaa6');
        $secret = $totp->generateSecret();
        $user->beginTwoFactorSetup($secret);
        $user->confirmTwoFactor([]);
        $a = User::findOrFail($user->id);
        $b = User::findOrFail($user->id);
        $code = (new Google2FA)->getCurrentOtp($secret);
        $first = $totp->verifyCode($a, $secret, $code);
        $second = $totp->verifyCode($b, $secret, $code);
        $fresh = $totp->verifyCode($user->fresh(), $secret, $code);
        $this->wynik('totp-race', ['first' => $first, 'second_stale' => $second, 'fresh_control' => $fresh]);
        $this->assertSame([true, true, false], [$first, $second, $fresh]);
    }

    public function test_dwa_zapisy_kodow_zapasowych_przywracaja_zuzyty_kod(): void
    {
        $totp = app(TwoFactorAuthenticator::class);
        $user = $this->user('kodzosobaa6');
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes(['AAAA-BBBB', 'CCCC-DDDD']));
        $a = User::findOrFail($user->id);
        $b = User::findOrFail($user->id);
        $first = $totp->consumeBackupCode($a, 'AAAA-BBBB');
        $second = $totp->consumeBackupCode($b, 'CCCC-DDDD');
        $again = $totp->consumeBackupCode($user->fresh(), 'AAAA-BBBB');
        $this->wynik('backup-code-race', ['first' => $first, 'different_code_stale' => $second, 'first_code_reused_after_refresh' => $again]);
        $this->assertSame([true, true, true], [$first, $second, $again]);
    }

    public function test_raport_istniejacej_komendy_drukuje_retencje_200_procent(): void
    {
        $teraz = CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC');
        $this->travelTo($teraz);
        $start = $teraz->subWeeks(6)->startOfWeek()->addHours(12);
        $a = $this->user('kohortaa6a', ['created_at' => $start]);
        $b = $this->user('kohortaa6b', ['created_at' => $start]);
        Post::factory()->create(['author_id' => $a->id, 'published_at' => $start->addDay()]);
        foreach ([$a, $b] as $user) {
            Post::factory()->create(['author_id' => $user->id, 'published_at' => $start->addWeeks(4)->addDay()]);
        }
        Artisan::call('kuking:raport');
        $text = Artisan::output();
        $this->wynik('cohort-report', ['printed_200_percent' => str_contains($text, '200,0%'), 'output' => $text]);
        $this->assertStringContainsString('200,0%', $text);
    }

    public function test_prawdziwy_limiter_postu_nie_zachowuje_poprawnego_tekstu(): void
    {
        $this->actingAs($this->user('limitera6'));
        $last = null;
        $n = 0;
        do {
            $n++;
            $body = 'Syntetyczny poprawny wpis A6 numer '.$n;
            $last = $this->withSession(['_old_input' => []])->post(route('posts.store'), ['body' => $body, 'visibility' => 'public']);
        } while ($last->status() !== 429 && $n < 65);
        $this->wynik('post-429', ['requests' => $n, 'status' => $last->status(), 'body_retained' => session()->getOldInput('body') === $body, 'published' => Post::where('body', $body)->exists(), 'html' => $last->getContent()]);
        $last->assertStatus(429);
        $this->assertNotSame($body, session()->getOldInput('body'));
        $this->assertFalse(Post::where('body', $body)->exists());
    }

    public function test_retencja_na_koncu_miesiaca(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-31 12:00:00', 'UTC'));
        $user = $this->user('retencjaa6');
        $n = Notification::create(['user_id' => $user->id, 'type' => Notification::TYPE_COOKED, 'data' => [], 'created_at' => CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC')]);
        $cutoff = now()->subMonths(3)->toIso8601String();
        app(PrzedawnionePowiadomienia::class)->posprzataj(3);
        $deleted = ! Notification::whereKey($n->id)->exists();
        $this->wynik('retention-month-end', ['now' => now()->toIso8601String(), 'cutoff' => $cutoff, 'created' => $n->created_at->toIso8601String(), 'deleted' => $deleted]);
        $this->assertTrue($deleted);
    }
}
