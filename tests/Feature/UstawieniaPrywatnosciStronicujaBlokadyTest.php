<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Http\Controllers\Settings\PrivacySettingsController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1366: `/ustawienia/prywatnosc` pobierało CAŁĄ listę zablokowanych osób
 * (`blocking()->...->get()`) i rysowało kartę z formularzem przy każdej —
 * przy każdym wejściu, także po to, żeby zmienić jedną zgodę. Teraz lista
 * idzie kursorem po `blocked_id`, po `BLOKAD_NA_STRONE` osób.
 */
class UstawieniaPrywatnosciStronicujaBlokadyTest extends TestCase
{
    use RefreshDatabase;

    private const ILE = PrivacySettingsController::BLOKAD_NA_STRONE + 5;

    /**
     * @return array{0: User, 1: list<User>}
     */
    private function blokujacaZDuzaLista(): array
    {
        $ona = $this->user('blokujaca1366');
        $zablokowani = [];

        for ($i = 0; $i < self::ILE; $i++) {
            $osoba = $this->user(sprintf('zablokowany1366_%02d', $i), ['display_name' => sprintf('Zablokowany %02d', $i)]);
            app(BlockUser::class)->handle($ona, $osoba);
            $zablokowani[] = $osoba;
        }

        // Kolejność listy to kolejność `blocked_id` — tak samo ją liczymy tu.
        usort($zablokowani, fn (User $a, User $b): int => strcmp((string) $a->getKey(), (string) $b->getKey()));

        return [$ona, $zablokowani];
    }

    public function test_pierwsza_strona_pokazuje_limit_osob_i_przycisk_dalej(): void
    {
        [$ona, $zablokowani] = $this->blokujacaZDuzaLista();

        $odpowiedz = $this->actingAs($ona)->get(route('settings.privacy'));

        $odpowiedz->assertOk();
        $blocked = $odpowiedz->viewData('blocked');
        $this->assertCount(PrivacySettingsController::BLOKAD_NA_STRONE, $blocked->items());
        $this->assertSame(
            PrivacySettingsController::BLOKAD_NA_STRONE,
            substr_count($odpowiedz->getContent(), 'Zdejmij blokadę'),
        );

        // Formularz zgód jest na miejscu, niezależnie od listy.
        $odpowiedz->assertSee('name="wants_weekly_digest"', false);
        $odpowiedz->assertSee('name="memories_enabled"', false);

        $odpowiedz->assertSee('Pokaż więcej osób');
        $odpowiedz->assertSee($zablokowani[0]->displayName());
        $odpowiedz->assertDontSee($zablokowani[self::ILE - 1]->displayName());
        $this->assertStringEndsWith('#zablokowane', $blocked->nextPageUrl());
    }

    public function test_pierwsza_strona_nie_laduje_modeli_spoza_limitu(): void
    {
        [$ona] = $this->blokujacaZDuzaLista();

        $zapytania = [];
        DB::listen(function ($query) use (&$zapytania): void {
            $zapytania[] = $query->sql;
        });

        $this->actingAs($ona)->get(route('settings.privacy'))->assertOk();

        $listy = array_values(array_filter($zapytania, fn (string $sql): bool => str_contains($sql, 'inner join "blocks"')));
        $this->assertCount(1, $listy, 'Lista zablokowanych ma iść jednym zapytaniem.');
        foreach ($listy as $sql) {
            $this->assertMatchesRegularExpression('/limit\s+'.(PrivacySettingsController::BLOKAD_NA_STRONE + 1).'\b/i', $sql);
        }

        // Profile i awatary jednym zapytaniem na stronę, nie na osobę.
        $profile = array_filter($zapytania, fn (string $sql): bool => (bool) preg_match('/from\s+"profiles"/i', $sql));
        $this->assertLessThanOrEqual(2, count($profile));
    }

    public function test_dalsza_strona_pokazuje_reszte_i_odblokowanie_tam_dziala(): void
    {
        [$ona, $zablokowani] = $this->blokujacaZDuzaLista();

        $nastepna = $this->actingAs($ona)->get(route('settings.privacy'))->viewData('blocked')->nextPageUrl();
        $druga = $this->actingAs($ona)->get($nastepna);

        $druga->assertOk();
        $this->assertCount(self::ILE - PrivacySettingsController::BLOKAD_NA_STRONE, $druga->viewData('blocked')->items());
        $druga->assertSee($zablokowani[self::ILE - 1]->displayName());
        $druga->assertDontSee($zablokowani[0]->displayName());
        $druga->assertDontSee('Pokaż więcej osób');

        $ostatni = $zablokowani[self::ILE - 1];
        $this->actingAs($ona)
            ->from($nastepna)
            ->delete(route('social.unblock', $ostatni->profile->username), ['oczekiwany_id' => $ostatni->getKey()])
            ->assertSessionHasNoErrors()
            ->assertRedirect($nastepna);

        $this->assertFalse($ona->fresh()->blocking()->whereKey($ostatni->getKey())->exists());
    }

    public function test_pusta_dalsza_strona_nie_twierdzi_ze_nikogo_nie_blokujesz(): void
    {
        [$ona, $zablokowani] = $this->blokujacaZDuzaLista();

        $nastepna = $this->actingAs($ona)->get(route('settings.privacy'))->viewData('blocked')->nextPageUrl();
        foreach (array_slice($zablokowani, PrivacySettingsController::BLOKAD_NA_STRONE) as $osoba) {
            DB::table('blocks')->where('blocker_id', $ona->getKey())->where('blocked_id', $osoba->getKey())->delete();
        }

        $pusta = $this->actingAs($ona)->get($nastepna);

        $pusta->assertOk();
        $pusta->assertDontSee('Nikogo nie blokujesz.');
        $pusta->assertSee('Wróć do początku listy');
    }

    public function test_kontrola_dodatnia_krotka_lista_bez_przycisku_i_pusta_lista(): void
    {
        $ona = $this->user('blokujaca1366b');
        $marek = $this->user('marek1366b', ['display_name' => 'Marek Krótki']);
        app(BlockUser::class)->handle($ona, $marek);

        $krotka = $this->actingAs($ona)->get(route('settings.privacy'));
        $krotka->assertSee('Marek Krótki');
        $krotka->assertSee('Zdejmij blokadę');
        $krotka->assertDontSee('Pokaż więcej osób');

        $pusta = $this->actingAs($this->user('nikogo1366'))->get(route('settings.privacy'));
        $pusta->assertSee('Nikogo nie blokujesz.');
        $pusta->assertDontSee('Wróć do początku listy');
    }
}
