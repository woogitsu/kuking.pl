<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Wymazanie konta zabiera relacje i materiał uwierzytelniający.
 *
 * Znalezisko G05 z audytu zewnętrznego, odtworzone tutaj od zera.
 *
 * CO OBIECUJE POLITYKA
 * `resources/legal/polityka-prywatnosci.md`, tabela w §2, wiersz „Relacje
 * w serwisie": „kogo obserwujesz, kogo zablokowałeś … Do usunięcia relacji
 * LUB KONTA". `AGENTS.md` część 11 mówi, że to zdanie ma się zgadzać
 * z kodem, a nie być deklaracją.
 *
 * CO ROBIŁ KOD
 * `EraseAccountData` anonimizował profil i konto, kasował zdjęcia, zamykał
 * sesje i unieważniał paczkę eksportu — ale tabel `follows`, `blocks`
 * i `tag_follows` nie tykał wcale, a pola 2FA zostawiał nietknięte.
 * Zmierzone: po wymazaniu `follows=2`, `blocks=2`, `tag_follows=1`, sekret
 * 2FA i kody zapasowe obecne.
 *
 * CZEGO TU CELOWO NIE KASUJEMY
 * Dokumentacji moderacyjnej (`reports`, `appeals`, `audit_log`). Polityka
 * daje jej własny, dłuższy okres retencji (36 miesięcy od zamknięcia sprawy)
 * i to jest osobna podstawa prawna niż umowa z użytkownikiem. Audyt mówi
 * o tym wprost: „nie kasować bezmyślnie całego audytu".
 *
 * Powiadomień też nie ruszamy i to jest ŚWIADOME, nie przeoczenie:
 * powiadomienia o decyzji moderacyjnej mają w Regulaminie §8 własny termin
 * (co najmniej 6 miesięcy), więc hurtowe kasowanie po `user_id` łamałoby
 * inną obietnicę. Reszta powiadomień znika i tak po 3 miesiącach, nocnym
 * sprzątaniem.
 */
class WymazanieKontaUsuwaRelacjeI2FATest extends TestCase
{
    use RefreshDatabase;

    /**
     * Konto z pełnym kompletem relacji i włączonym 2FA, zgłoszone do
     * usunięcia i po terminie karencji.
     *
     * @return array{0: User, 1: User, 2: User}
     */
    private function kontoZRelacjami(string $zakres): array
    {
        $odchodzi = $this->user('odchodzi', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => $zakres,
        ]);

        $obserwowany = $this->user('obserwowany');
        $zablokowany = $this->user('zablokowany');

        // Relacje w OBIE strony — bo tabela `follows` trzyma jedno i drugie
        // w tym samym wierszu z różnych stron, a łatwo wyczyścić tylko jedną.
        $odchodzi->following()->attach($obserwowany->getKey());
        $obserwowany->following()->attach($odchodzi->getKey());

        $odchodzi->blocking()->attach($zablokowany->getKey());
        $zablokowany->blocking()->attach($odchodzi->getKey());

        $tag = Tag::factory()->create();
        $odchodzi->followedTags()->attach($tag->getKey());

        $odchodzi->beginTwoFactorSetup('SEKRETNYSEKRET234567');
        $odchodzi->confirmTwoFactor([Hash::make('kod-zapasowy-1')]);
        // Licznik kroków TOTP, nie znacznik czasu — kolumna jest `bigint`
        // i model celowo nie ma na niej castu (patrz `User::casts()`).
        $odchodzi->forceFill(['two_factor_last_used_at' => 58_237_291])->save();

        return [$odchodzi->fresh(), $obserwowany, $zablokowany];
    }

    private function ileRelacji(User $user): array
    {
        $id = $user->getKey();

        return [
            'follows' => DB::table('follows')
                ->where('follower_id', $id)->orWhere('followed_id', $id)->count(),
            'blocks' => DB::table('blocks')
                ->where('blocker_id', $id)->orWhere('blocked_id', $id)->count(),
            'tag_follows' => DB::table('tag_follows')->where('user_id', $id)->count(),
        ];
    }

    /**
     * Kontrola: przed wymazaniem to wszystko naprawdę jest w bazie.
     *
     * Bez tego „zero relacji po wymazaniu" mogłoby znaczyć, że fabryka nic
     * nie zapisała, i test przechodziłby na pustym miejscu.
     */
    public function test_kontrola_przed_wymazaniem_relacje_i_2fa_sa_w_bazie(): void
    {
        [$odchodzi] = $this->kontoZRelacjami(User::DELETE_SCOPE_MINIMUM);

        $this->assertSame(
            ['follows' => 2, 'blocks' => 2, 'tag_follows' => 1],
            $this->ileRelacji($odchodzi),
        );

        $this->assertNotNull($odchodzi->two_factor_secret);
        $this->assertNotNull($odchodzi->two_factor_backup_codes);
        $this->assertTrue($odchodzi->hasTwoFactorConfirmed());
    }

    public function test_wymazanie_konta_usuwa_relacje_w_obie_strony(): void
    {
        [$odchodzi] = $this->kontoZRelacjami(User::DELETE_SCOPE_MINIMUM);

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(
            ['follows' => 0, 'blocks' => 0, 'tag_follows' => 0],
            $this->ileRelacji($odchodzi),
            'Po wymazaniu konta zostały jego relacje — polityka obiecuje, że znikają razem z kontem.',
        );
    }

    public function test_wymazanie_konta_zeruje_material_uwierzytelniajacy_2fa(): void
    {
        [$odchodzi] = $this->kontoZRelacjami(User::DELETE_SCOPE_MINIMUM);

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $odchodzi = $odchodzi->fresh();

        $this->assertNull($odchodzi->two_factor_secret, 'Sekret 2FA został po wymazanym koncie.');
        $this->assertNull($odchodzi->two_factor_backup_codes, 'Kody zapasowe zostały po wymazanym koncie.');
        $this->assertNull($odchodzi->two_factor_confirmed_at);
        $this->assertNull($odchodzi->two_factor_last_used_at);
        $this->assertFalse($odchodzi->hasTwoFactorConfirmed());
    }

    /**
     * Zakres `everything` nie może być gorszy od `minimum`. Audyt zmierzył
     * pozostałości także przy nim, więc sprawdzamy oba.
     */
    public function test_przy_zakresie_wszystko_jest_tak_samo_czysto(): void
    {
        [$odchodzi] = $this->kontoZRelacjami(User::DELETE_SCOPE_EVERYTHING);

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(
            ['follows' => 0, 'blocks' => 0, 'tag_follows' => 0],
            $this->ileRelacji($odchodzi),
        );

        $this->assertNull($odchodzi->fresh()->two_factor_secret);
    }

    /**
     * Relacje DRUGIEJ osoby, niezwiązane z odchodzącym kontem, zostają.
     *
     * To jest bezpiecznik przed najgorszą możliwą awarią tej zmiany, czyli
     * skasowaniem za dużo. Ten sam typ bezpiecznika co
     * w `AccountDeletionPurgeTest`.
     */
    public function test_relacje_innych_osob_zostaja_nietkniete(): void
    {
        [$odchodzi, $obserwowany, $zablokowany] = $this->kontoZRelacjami(User::DELETE_SCOPE_MINIMUM);

        // Relacja między dwiema osobami, które zostają.
        $obserwowany->following()->attach($zablokowany->getKey());

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(
            1,
            DB::table('follows')
                ->where('follower_id', $obserwowany->getKey())
                ->where('followed_id', $zablokowany->getKey())
                ->count(),
            'Wymazanie jednego konta zabrało relację dwóch innych osób.',
        );
    }

    /**
     * Dokumentacja moderacyjna ma własny okres retencji i NIE znika razem
     * z kontem (polityka §2, wiersz o zgłoszeniach; audyt: „nie kasować
     * bezmyślnie całego audytu").
     */
    public function test_dziennik_audytowy_konta_zostaje(): void
    {
        [$odchodzi] = $this->kontoZRelacjami(User::DELETE_SCOPE_MINIMUM);

        $wpisowPrzed = DB::table('audit_log')->where('actor_id', $odchodzi->getKey())->count();

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(
            $wpisowPrzed,
            DB::table('audit_log')->where('actor_id', $odchodzi->getKey())->count(),
            'Wymazanie konta skasowało dziennik audytowy, który ma zostać na 36 miesięcy.',
        );
    }
}
