<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Profile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KonfliktNazwyProfiluTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('occupiedNames')]
    public function test_konflikt_po_walidacji_wraca_do_pola_i_zachowuje_formularz(string $occupiedName): void
    {
        $user = $this->user('stara_nazwa');
        $rival = $this->user('nazwa_rywala');
        $data = ['username' => 'wspolna_nazwa', 'display_name' => 'Nowe imię', 'bio' => 'Nowy opis', 'region' => 'Nowy region', 'speciality' => 'Nowa specjalność'];
        $interleaved = false;
        // Wymuszony przeplot po walidacji. Pełny pomiar dwóch procesów
        // jest osobno w output/konto-poczta/PomiarNazwyTest.php.
        Profile::updating(function (Profile $profile) use ($user, $rival, &$interleaved, $occupiedName): void {
            if ($profile->user_id === $user->id && ! $interleaved) {
                $interleaved = true;
                DB::table('profiles')->where('user_id', $rival->id)->update(['username' => $occupiedName]);
            }
        });
        $response = $this->actingAs($user)->from(route('settings.profile'))->put(route('settings.profile'), $data);
        $this->assertTrue($interleaved, 'Przeplot po walidacji nie został wykonany.');
        $response->assertRedirect(route('settings.profile'));
        $this->assertSame('stara_nazwa', $user->profile->fresh()->username);
        $this->assertSame('Testowa osoba', $user->profile->fresh()->display_name);
        $html = $this->get(route('settings.profile'))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        foreach ($data as $key => $value) {
            $field = self::elementDom($xpath->query('//*[@name="'.$key.'"]')->item(0));
            $this->assertSame($value, $field->nodeName === 'textarea' ? $field->textContent : $field->getAttribute('value'));
        }
        $this->assertSame('true', self::elementDom($xpath->query('//*[@name="username"]')->item(0))->getAttribute('aria-invalid'), $dom->saveHTML($xpath->query('//*[@name="username"]')->item(0)));
        $this->assertSame(1, $xpath->query('//*[@role="alert"]//a[@href="#f-username"]')->length);
        $this->assertStringContainsString('Spróbuj dodać coś na końcu.', $html);
    }

    public static function occupiedNames(): array
    {
        return ['identyczna nazwa' => ['wspolna_nazwa'], 'indeks bez wielkości liter' => ['WSPOLNA_NAZWA']];
    }

    public function test_zwykla_zmiana_nazwy_dziala(): void
    {
        $user = $this->user('stara_nazwa');
        $this->actingAs($user)->from(route('settings.profile'))->put(route('settings.profile'), [
            'username' => 'nowa_nazwa', 'display_name' => 'Nowe imię',
        ])->assertRedirect(route('settings.profile'))->assertSessionHasNoErrors();
        $this->assertSame('nowa_nazwa', $user->profile->fresh()->username);
    }

    public function test_inny_konflikt_bazy_nie_udaje_zajetej_nazwy(): void
    {
        $user = $this->user();
        Profile::updating(function (): void {
            $pdo = new \PDOException("duplicate key value violates unique constraint \"profiles_inny_unique\"\nDETAIL: treść pola \"profiles_username_unique\"");
            $pdo->errorInfo = ['23505', 7, $pdo->getMessage()];
            throw new UniqueConstraintViolationException('pgsql', 'update profiles', [], $pdo);
        });
        $this->withoutExceptionHandling();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->actingAs($user)->put(route('settings.profile'), ['username' => 'nowa_nazwa', 'display_name' => 'Nowe imię']);
    }
}
