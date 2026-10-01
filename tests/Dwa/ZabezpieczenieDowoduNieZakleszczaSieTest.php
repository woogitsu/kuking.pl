<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * „CSAM — natychmiast ukryj i zabezpiecz” bierze zdjęcia PRZED kontami
 * i treścią, tak samo jak PublishRecipe (D-103, D-333).
 *
 * CO BYŁO ZEPSUTE
 * CSAM brał `users` przed `media`; PublishRecipe bierze `media` przed
 * `users`. Przy edycji przepisu z tym samym zdjęciem powstawał cykl
 * i PostgreSQL przerywał jedną ze stron (`40P01`).
 *
 * Pierwsze dwa testy pilnują starszego przeplotu z przepisem bez zdjęcia
 * i egzekucją wymazania. Trzeci używa prawdziwej blokady hero_media_id.
 */
#[Group('dwa-polaczenia')]
final class ZabezpieczenieDowoduNieZakleszczaSieTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $przepisy = [];

    protected function tearDown(): void
    {
        try {
            if ($this->konta !== []) {
                $ids = '{'.implode(',', $this->konta).'}';
                DB::statement('DELETE FROM zabezpieczenia_dowodow WHERE secured_by = ANY(?::uuid[]) OR subject_user_id = ANY(?::uuid[])', [$ids, $ids]);
                DB::statement('DELETE FROM notifications WHERE user_id = ANY(?::uuid[])', [$ids]);
                DB::statement('DELETE FROM moderation_actions WHERE moderator_id = ANY(?::uuid[]) OR subject_user_id = ANY(?::uuid[])', [$ids, $ids]);
            }

            if ($this->przepisy !== []) {
                DB::table('cooked_events')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipe_versions')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipe_slug_redirects')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipes')->whereIn('id', $this->przepisy)->delete();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nNie udało się posprzątać wyścigu zabezpieczenia dowodu: ".$e->getMessage()."\n");
        }

        $this->przepisy = [];

        parent::tearDown();
    }

    public function test_zabezpieczenie_przepisu_obok_zapisu_przepisu_przez_autora_nie_zakleszcza_sie(): void
    {
        $moderator = $this->moderator2fa();
        $autor = $this->konto();
        $przepis = $this->opublikowanyPrzepis($autor);

        // B (autor) trzyma `users FOR KEY SHARE` i stoi na barierze.
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2165, 1)', []);

        $edycja = $this->wTle('edytuj-przepis', [
            'autor' => (string) $autor->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'tytul' => 'Rosół zapisany obok zabezpieczenia',
            'skladnik' => 'lubczyk',
            'bariera_2165' => '1',
        ]);
        $this->czekajNaZablokowane(1);

        $zabezpieczenie = $this->wTle('zabezpiecz-csam', [
            'kto' => (string) $moderator->getKey(),
            'typ' => 'recipe',
            'id' => (string) $przepis->getKey(),
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikEdycji = $edycja->wynik();
        $wynikZabezpieczenia = $zabezpieczenie->wynik();

        // TO JEST CAŁE ZNALEZISKO.
        $this->assertBezZakleszczenia($wynikEdycji, 'zapis przepisu przez autora obok zabezpieczenia');
        $this->assertBezZakleszczenia($wynikZabezpieczenia, 'zabezpieczenie dowodu obok zapisu przepisu');

        // KONTROLE DODATNIE: obie strony naprawdę się wykonały.
        $this->assertTrue($wynikEdycji['ok'], (string) $wynikEdycji['komunikat']);
        $this->assertTrue($wynikZabezpieczenia['ok'], (string) $wynikZabezpieczenia['komunikat']);
        $this->assertSame(1, DB::table('zabezpieczenia_dowodow')
            ->where('target_type', 'recipe')->where('target_id', $przepis->getKey())->count());
        $this->assertNotNull(DB::table('recipes')->where('id', $przepis->getKey())->value('deleted_at'));
        $this->assertSame(User::STATUS_BANNED, DB::table('users')->where('id', $autor->getKey())->value('status'));
    }

    public function test_zabezpieczenie_obok_edycji_przepisu_z_tym_samym_zdjeciem_nie_zakleszcza_sie(): void
    {
        $moderator = $this->moderator2fa();
        $autor = $this->konto();
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $przepis = $this->opublikowanyPrzepis($autor);
        $przepis->update(['hero_media_id' => $zdjecie->getKey()]);

        // Edycja bierze media FOR UPDATE i czeka przed blokadą users.
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2427, 1)', []);
        $edycja = $this->wTle('edytuj-przepis', [
            'autor' => (string) $autor->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'zdjecie' => (string) $zdjecie->getKey(),
            'tytul' => 'Rosół ze zdjęciem po zabezpieczeniu',
            'skladnik' => 'lubczyk',
            'bariera_2427' => '1',
        ]);
        $this->czekajNaZablokowane(1);

        $zabezpieczenie = $this->wTle('zabezpiecz-csam', [
            'kto' => (string) $moderator->getKey(),
            'typ' => 'recipe',
            'id' => (string) $przepis->getKey(),
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikEdycji = $edycja->wynik();
        $wynikZabezpieczenia = $zabezpieczenie->wynik();

        $this->assertBezZakleszczenia($wynikEdycji, 'edycja przepisu ze zdjęciem');
        $this->assertBezZakleszczenia($wynikZabezpieczenia, 'zabezpieczenie tego samego zdjęcia');
        $this->assertTrue($wynikEdycji['ok'], (string) $wynikEdycji['komunikat']);
        $this->assertTrue($wynikZabezpieczenia['ok'], (string) $wynikZabezpieczenia['komunikat']);
        $this->assertSame(Media::STATUS_SECURED, $zdjecie->fresh()->status);
        $this->assertSame(User::STATUS_BANNED, $autor->fresh()->status);
        $this->assertNotNull(DB::table('recipes')->where('id', $przepis->getKey())->value('deleted_at'));
    }

    public function test_zabezpieczenie_przepisu_obok_wymazania_konta_autora_nie_zakleszcza_sie(): void
    {
        $moderator = $this->moderator2fa();
        $autor = $this->konto();
        $autor->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
        $przepis = $this->opublikowanyPrzepis($autor);

        CookedEvent::create([
            'user_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        // Egzekucja trzyma `users FOR UPDATE` i staje PRZED kasowaniem przepisów.
        $bariera = $this->bariera(
            'SELECT 1 FROM cooked_events WHERE user_id = ? FOR UPDATE',
            [(string) $autor->getKey()],
        );

        $kasowanie = $this->wTle('kasowanie', ['konto' => (string) $autor->getKey()]);
        $this->czekajNaZablokowane(1);

        $zabezpieczenie = $this->wTle('zabezpiecz-csam', [
            'kto' => (string) $moderator->getKey(),
            'typ' => 'recipe',
            'id' => (string) $przepis->getKey(),
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikKasowania = $kasowanie->wynik();
        $wynikZabezpieczenia = $zabezpieczenie->wynik();

        $this->assertBezZakleszczenia($wynikKasowania, 'wymazanie konta obok zabezpieczenia dowodu');
        $this->assertBezZakleszczenia($wynikZabezpieczenia, 'zabezpieczenie dowodu obok wymazania konta');

        // KONTROLA DODATNIA: egzekucja naprawdę wymazała konto i przepis.
        $this->assertTrue($wynikKasowania['ok'], $wynikKasowania['wyjatek'].' '.$wynikKasowania['komunikat']);
        $this->assertTrue($wynikKasowania['wartosc'], 'Egzekucja nie wymazała konta — przeplot był inny niż opisany.');
        $this->assertSame(0, Recipe::query()->whereKey($przepis->getKey())->count());

        // Zabezpieczenie przyszło PO wymazaniu: odmawia zdaniem po polsku
        // (treści już nie ma), nie zakleszczeniem i nie błędem serwera.
        $this->assertFalse($wynikZabezpieczenia['ok']);
        $this->assertStringContainsString('już nie ma w bazie', (string) $wynikZabezpieczenia['komunikat']);
    }

    private function moderator2fa(): User
    {
        $totp = app(TwoFactorAuthenticator::class);

        return $this->konto([
            'role' => User::ROLE_MODERATOR,
            'two_factor_secret' => $totp->generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_backup_codes' => $totp->hashBackupCodes(['abcd-efgh']),
        ]);
    }

    private function opublikowanyPrzepis(User $autor): Recipe
    {
        $znacznik = bin2hex(random_bytes(5));

        $przepis = Recipe::create([
            'author_id' => $autor->getKey(),
            'title' => 'Rosół dowodowy '.$znacznik,
            'slug' => 'rosol-dowodowy-'.$znacznik,
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->przepisy[] = (string) $przepis->getKey();

        RecipeVersion::create([
            'recipe_id' => $przepis->getKey(),
            'editor_id' => $autor->getKey(),
            'version_number' => 1,
            'change_note' => 'Pierwsza publikacja',
            'snapshot' => ['title' => $przepis->title],
        ]);

        return $przepis;
    }
}
