<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Samodzielne oznaczenie alergenów (`OznaczAlergenyPrzepisu::handle()`, #1902)
 * bierze `users` przed `recipes`, tak jak `PublishRecipe` — inaczej zakleszcza
 * się z egzekucją kasowania konta.
 *
 * Przeplot jest ten sam co w `EdycjaPrzepisuNieZakleszczaSieZKasowaniemKontaTest`
 * (tam pełne uzasadnienie): egzekucja trzyma `users FOR UPDATE` i czeka na
 * barierze pod `cooked_events`; w tym czasie autor oznacza alergeny. Przed
 * naprawą akcja brała `recipes FOR UPDATE`, a potem wpis `audit_log`
 * zakładał `FOR KEY SHARE` na `users` (klucz obcy `actor_id`) — cykl, 40P01.
 *
 * KONTROLA UJEMNA (ręcznie): usunięcie `zablokujAutora()` z `handle()`
 * oblewa ten test zakleszczeniem 40P01.
 */
#[Group('dwa-polaczenia')]
final class OznaczenieAlergenowNieZakleszczaSieZKasowaniemKontaTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $przepisy = [];

    protected function tearDown(): void
    {
        if ($this->przepisy !== []) {
            try {
                DB::table('recipe_versions')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipe_slug_redirects')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipes')->whereIn('id', $this->przepisy)->delete();
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nNie udało się posprzątać przepisów testu: ".$e->getMessage()."\n");
            }

            $this->przepisy = [];
        }

        parent::tearDown();
    }

    public function test_oznaczenie_alergenow_obok_egzekucji_kasowania_konta_nie_zakleszcza_sie(): void
    {
        $autor = $this->konto();
        $autor->markForDeletion(User::DELETE_SCOPE_EVERYTHING);

        $znacznik = bin2hex(random_bytes(5));
        $przepis = Recipe::create([
            'author_id' => $autor->getKey(),
            'title' => 'Sernik alergenowy '.$znacznik,
            'slug' => 'sernik-alergenowy-'.$znacznik,
            'visibility' => 'public',
            'source_type' => Recipe::SOURCE_OWN,
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        $this->przepisy[] = (string) $przepis->getKey();

        CookedEvent::create([
            'user_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        $bariera = $this->bariera(
            'SELECT 1 FROM cooked_events WHERE user_id = ? FOR UPDATE',
            [(string) $autor->getKey()],
        );

        $kasowanie = $this->wTle('kasowanie', ['konto' => (string) $autor->getKey()]);
        $this->czekajNaZablokowane(1);

        $oznaczenie = $this->wTle('oznacz-alergeny', [
            'autor' => (string) $autor->getKey(),
            'przepis' => (string) $przepis->getKey(),
        ]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $wynikKasowania = $kasowanie->wynik();
        $wynikOznaczenia = $oznaczenie->wynik();

        $this->assertBezZakleszczenia($wynikKasowania, 'egzekucja kasowania konta obok oznaczenia alergenów');
        $this->assertBezZakleszczenia($wynikOznaczenia, 'oznaczenie alergenów obok egzekucji kasowania konta');

        // Kontrola dodatnia: egzekucja naprawdę poszła, a oznaczenie odbiło się
        // zdaniem po polsku (konto wymazane, przepis skasowany), nie błędem.
        $this->assertTrue($wynikKasowania['ok'], 'Egzekucja nie przeszła: '.$wynikKasowania['wyjatek'].' '.$wynikKasowania['komunikat']);
        $this->assertTrue($wynikKasowania['wartosc'], 'Egzekucja nie wymazała konta — przeplot był inny niż opisany.');
        $this->assertFalse($wynikOznaczenia['ok'], 'Oznaczenie przeszło na przepisie, który przestał istnieć.');
        $this->assertMatchesRegularExpression(
            '/Stan Twojego konta zmienił się|Tego przepisu już nie ma|Nie masz uprawnień|This action is unauthorized/u',
            (string) $wynikOznaczenia['komunikat'],
            'Oznaczenie padło z innego powodu: '.$wynikOznaczenia['wyjatek'].' '.$wynikOznaczenia['komunikat'],
        );
        $this->assertSame(0, Recipe::query()->whereKey($przepis->getKey())->count());
    }
}
