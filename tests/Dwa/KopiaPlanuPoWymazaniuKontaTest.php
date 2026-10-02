<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Planer\Actions\DodajDoPlanu;
use App\Domain\Planer\Actions\SkopiujPoprzedniTydzien;
use App\Domain\Planer\PlanerTygodnia;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/** #2551: pobrany wcześniej plan nie może odtworzyć treści po wymazaniu. */
#[Group('dwa-polaczenia')]
final class KopiaPlanuPoWymazaniuKontaTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $przepisy = [];

    protected function tearDown(): void
    {
        if ($this->przepisy !== []) {
            try {
                DB::table('meal_plan_entries')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipe_versions')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipe_slug_redirects')->whereIn('recipe_id', $this->przepisy)->delete();
                DB::table('recipes')->whereIn('id', $this->przepisy)->delete();
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nNie udało się posprzątać przepisu testu #2551: ".$e->getMessage()."\n");
            }
        }

        parent::tearDown();
    }

    private function pozycja(User $user, string $dzien, string $tekst): void
    {
        $wpis = new MealPlanEntry(['day' => $dzien, 'label' => $tekst]);
        $wpis->user_id = $user->getKey();
        $wpis->save();
    }

    public function test_kopia_i_prawdziwe_wymazanie_na_dwoch_polaczeniach_nie_przywracaja_prywatnego_planu(): void
    {
        CarbonImmutable::setTestNow('2026-10-02 10:00:00 UTC');
        Carbon::setTestNow('2026-10-02 10:00:00 UTC');

        try {
            $user = $this->konto();
            $autor = $this->konto();
            $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
            $this->przepisy[] = (string) $przepis->getKey();
            $this->pozycja($user, '2026-09-21', 'Prywatny rodzinny obiad');
            $wpisPrzepisu = new MealPlanEntry(['day' => '2026-09-22', 'recipe_id' => $przepis->getKey()]);
            $wpisPrzepisu->user_id = $user->getKey();
            $wpisPrzepisu->save();
            $zrodlo = app(PlanerTygodnia::class)->pozycje(
                $user, CarbonImmutable::parse('2026-09-21'), CarbonImmutable::parse('2026-09-27'),
            );
            $this->assertCount(2, $zrodlo, 'PLAN_2551_DWA_ZRODLA');
            $this->assertSame(PlanerTygodnia::STAN_PRZEPIS, $zrodlo[1]['stan'], 'PLAN_2551_PRZEPIS_DOSTEPNY');
            $proces = null;
            $odczytZrodla = false;
            $wymazaniePrzedKopia = false;

            DB::listen(function (QueryExecuted $query) use ($user, &$proces, &$odczytZrodla, &$wymazaniePrzedKopia): void {
                $sql = mb_strtolower($query->sql);
                if ($odczytZrodla || ! str_starts_with(ltrim($sql), 'select')
                    || ! str_contains($sql, 'from "meal_plan_entries"')
                    || ! str_contains($sql, 'between')) {
                    return;
                }

                $odczytZrodla = true;
                $proces = $this->wTle('wymaz-plan-2551', ['konto' => (string) $user->getKey()]);

                if (DB::transactionLevel() === 0) {
                    // Stary kod czyta źródło PRZED blokadą: pozwalamy
                    // wymazaniu skończyć przed próbą zapisu z tej migawki.
                    $wynik = $proces->wynik();
                    $wymazaniePrzedKopia = $wynik['ok'] && $wynik['wartosc'] === true;

                    return;
                }

                // Nowy kod trzyma wiersz users: egzekutor ma naprawdę
                // czekać, aż kopia zakończy transakcję. Bez tej kontroli
                // test mógłby przejść przy braku rzeczywistego przeplotu.
                $this->czekajNaZablokowane(1);
            });

            app(SkopiujPoprzedniTydzien::class)->handle($user, CarbonImmutable::parse('2026-09-28'));

            $this->assertTrue($odczytZrodla, 'PLAN_2551_ZRODLO_NIE_ODCZYTANE');
            $this->assertNotNull($proces, 'PLAN_2551_WYMAZANIE_NIE_RUSZYLO');
            $wynik = $proces->wynik();
            $this->assertTrue($wynik['ok'] && $wynik['wartosc'] === true, 'PLAN_2551_WYMAZANIE_NIE_ZASZLO: '.$wynik['komunikat']);
            $this->assertSame(User::STATUS_ERASED, User::query()->findOrFail($user->getKey())->status);
            $this->assertSame(0, MealPlanEntry::query()->where('user_id', $user->getKey())->count(),
                'PLAN_2551_WYMAZANY_NIE_WRACA');
            $this->assertFalse($wymazaniePrzedKopia, 'PLAN_2551_KOPIA_OMINELA_ZAMEK');
        } finally {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }
    }

    public function test_aktywne_konto_kopiuje_bez_powtorzen_i_pilnuje_limitu_dnia(): void
    {
        CarbonImmutable::setTestNow('2026-10-02 10:00:00 UTC');
        Carbon::setTestNow('2026-10-02 10:00:00 UTC');
        try {
            $user = $this->konto();
            $this->pozycja($user, '2026-09-21', 'Zupa mamy');
            $this->pozycja($user, '2026-09-21', 'Drugie danie');
            $this->pozycja($user, '2026-09-22', 'Obiad we wtorek');
            $limit = (int) config('kuking.planer.wpisow_na_dzien');
            $this->assertGreaterThan(1, $limit);
            for ($i = 1; $i < $limit; $i++) {
                $this->pozycja($user, '2026-09-28', 'Już w planie '.$i);
            }
            $akcja = app(SkopiujPoprzedniTydzien::class);
            $pierwszy = $akcja->handle($user, CarbonImmutable::parse('2026-09-28'));
            $drugi = $akcja->handle($user, CarbonImmutable::parse('2026-09-28'));

            $this->assertSame(2, $pierwszy['skopiowane'], 'PLAN_2551_AKTYWNA_KOPIA');
            $this->assertSame(1, $pierwszy['pominiete'], 'PLAN_2551_LIMIT_DNIA');
            $this->assertSame(1, $drugi['juz_byly'], 'PLAN_2551_BEZ_DUPLIKATU');
            $this->assertSame($limit, MealPlanEntry::query()->where('user_id', $user->getKey())
                ->where('day', '2026-09-28')->count());
            $this->assertSame(1, MealPlanEntry::query()->where('user_id', $user->getKey())
                ->where('day', '2026-09-28')->whereIn('label', ['Zupa mamy', 'Drugie danie'])->count());
            $this->assertSame(1, MealPlanEntry::query()->where('user_id', $user->getKey())
                ->where('day', '2026-09-29')->where('label', 'Obiad we wtorek')->count());
        } finally {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }
    }

    public function test_nieaktualny_model_zamknietego_lub_usunietego_konta_nie_dopisuje_ani_nie_kopiuje(): void
    {
        CarbonImmutable::setTestNow('2026-10-02 10:00:00 UTC');
        Carbon::setTestNow('2026-10-02 10:00:00 UTC');
        try {
            foreach ([User::STATUS_SUSPENDED, User::STATUS_BANNED, User::STATUS_PENDING_DELETE, User::STATUS_ERASED] as $status) {
                $user = $this->konto();
                $this->pozycja($user, '2026-09-21', 'Prywatny wpis');
                $staryModel = User::query()->findOrFail($user->getKey());

                if ($status === User::STATUS_ERASED) {
                    $user->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
                    $this->assertTrue(app(EraseAccountData::class)->handle($user));
                } else {
                    $user->forceFill(['status' => $status])->save();
                }

                foreach (['kopia', 'dodanie'] as $operacja) {
                    try {
                        if ($operacja === 'kopia') {
                            app(SkopiujPoprzedniTydzien::class)->handle($staryModel, CarbonImmutable::parse('2026-09-28'));
                        } else {
                            app(DodajDoPlanu::class)->handle($staryModel, CarbonImmutable::parse('2026-09-28'), null, 'Nowy obiad');
                        }
                        $this->fail('PLAN_2551_ZAMKNIETE_KONTO_ZAPISALO: '.$status.' '.$operacja);
                    } catch (AuthorizationException $e) {
                        $this->assertStringContainsString('Odśwież stronę', $e->getMessage());
                    }
                }

                $this->assertSame(0, MealPlanEntry::query()->where('user_id', $user->getKey())
                    ->where('day', '2026-09-28')->count(), 'PLAN_2551_ZAMKNIETE_BEZ_ZAPISU');
            }

            $usuniety = new User;
            $usuniety->id = '0199a7c6-0000-7000-8000-000000000000';
            foreach (['kopia', 'dodanie'] as $operacja) {
                try {
                    if ($operacja === 'kopia') {
                        app(SkopiujPoprzedniTydzien::class)->handle($usuniety, CarbonImmutable::parse('2026-09-28'));
                    } else {
                        app(DodajDoPlanu::class)->handle($usuniety, CarbonImmutable::parse('2026-09-28'), null, 'Nowy obiad');
                    }
                    $this->fail('PLAN_2551_BRAK_KONTA_ZAPISAL: '.$operacja);
                } catch (AuthorizationException $e) {
                    $this->assertStringContainsString('Odśwież stronę', $e->getMessage());
                }
            }
        } finally {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }
    }
}
