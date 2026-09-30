<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\CelPowiadomienia;
use App\Domain\Notifications\WidocznoscPowiadomien;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/powiadomienia` — wachlarz zapytań (N+1) na trzech rodzajach wierszy,
 * które dotąd pytały bazę osobno dla każdego wiersza strony (audyt
 * wydajności W2): digest „Smakowicie wygląda", partia zapisów przepisu
 * i zaproszenia do wspólnego zeszytu. Dodatkowo W5: plakietka w belce nie
 * planuje ciężkiego zapytania, gdy nic nie czeka.
 *
 * METODA jak w `PowiadomieniaBezWachlarzaZapytanTest`: nie „ile zapytań
 * wypada", tylko „czy liczba ROŚNIE z liczbą wierszy".
 *
 * ZMIERZONE PRZED POPRAWKĄ (strona = 30 wierszy): zapowiedzi przepisu
 * w digestach — wyjątek lazy loadingu (w produkcji: kilka zapytań na wiersz),
 * zapisy przepisów — 1 zapytanie o imię na wiersz, zaproszenia i dołączenia
 * — 1 zapytanie na wiersz.
 */
class PowiadomieniaBezWachlarzaNaTypachTest extends TestCase
{
    use RefreshDatabase;

    private const PELNA_STRONA = 30;

    /** @return array{int, list<string>} liczba zapytań i ich treść */
    private function zapytania(callable $akcja): array
    {
        $sql = [];
        DB::listen(function ($zapytanie) use (&$sql): void {
            $sql[] = $zapytanie->sql;
        });
        $akcja();

        return [count($sql), $sql];
    }

    private function liczbaZapytanNaLiscie(User $odbiorca): int
    {
        return $this->zapytania(
            fn () => $this->actingAs($odbiorca)->get(route('notifications.index'))->assertOk(),
        )[0];
    }

    public function test_digesty_zapowiedzi_przepisu_nie_daja_wachlarza_zapytan(): void
    {
        $autor = $this->user('digest_autor');

        $dodaj = function (int $ile) use ($autor): void {
            for ($i = 0; $i < $ile; $i++) {
                $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
                // Zapowiedź przepisu: wpis bez treści i bez zdjęć, z `recipe_id`.
                $wpis = Post::factory()->create([
                    'author_id' => $autor->getKey(),
                    'recipe_id' => $przepis->getKey(),
                    'body' => null,
                    'published_at' => now()->subMinutes(5),
                ]);
                Notification::query()->create([
                    'user_id' => $autor->getKey(),
                    'type' => Notification::TYPE_SMAKOWICIE,
                    'data' => ['osob' => 1, 'wpisow' => 1, 'post_id' => (string) $wpis->getKey()],
                ]);
            }
        };

        $dodaj(2);
        $malo = $this->liczbaZapytanNaLiscie($autor);

        $dodaj(self::PELNA_STRONA - 2);
        $this->assertSame(self::PELNA_STRONA, $autor->notifications()->count());

        $html = $this->actingAs($autor)->get(route('notifications.index'))->assertOk()->getContent();
        // KONTROLA DODATNIA: „Zobacz" przy digeście istnieje, więc Policy
        // naprawdę przeszła dla zapowiedzi, a nie tylko „nic się nie wyrenderowało".
        $this->assertSame(self::PELNA_STRONA, substr_count($html, '>Zobacz</button>'));

        $duzo = $this->liczbaZapytanNaLiscie($autor);

        $this->assertSame($malo, $duzo, "Digesty: {$malo} zapytań przy 2 wierszach, {$duzo} przy ".self::PELNA_STRONA.'.');
    }

    public function test_partie_zapisow_przepisu_nie_daja_wachlarza_zapytan(): void
    {
        $autor = $this->user('zapisy_autor');
        $zablokowana = $this->user('zapisy_zablokowana', ['display_name' => 'Zablokowana']);
        $aktywna = $this->user('zapisy_aktywna', ['display_name' => 'Aktywna Osoba']);
        DB::table('blocks')->insert(['blocker_id' => $autor->getKey(), 'blocked_id' => $zablokowana->getKey(), 'created_at' => now()]);

        $dodaj = function (int $ile) use ($autor, $zablokowana, $aktywna): void {
            for ($i = 0; $i < $ile; $i++) {
                $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
                Notification::query()->create([
                    'user_id' => $autor->getKey(),
                    'actor_id' => $zablokowana->getKey(),
                    'type' => Notification::TYPE_SAVED,
                    'data' => [
                        'recipe_id' => $przepis->getKey(),
                        'recipe_title' => $przepis->title,
                        'recipe_slug' => $przepis->slug,
                        // Pierwsza osoba zablokowana — imię ma wyjść od drugiej.
                        'savers' => [$zablokowana->getKey(), $aktywna->getKey()],
                        'others_count' => 1,
                    ],
                ]);
            }
        };

        $dodaj(2);
        $malo = $this->liczbaZapytanNaLiscie($autor);

        $dodaj(self::PELNA_STRONA - 2);
        $html = $this->actingAs($autor)->get(route('notifications.index'))->assertOk()->getContent();

        // KONTROLA DODATNIA: imię widocznej osoby jest, zablokowanej nie ma.
        $this->assertSame(self::PELNA_STRONA, substr_count($html, 'Aktywna Osoba'));
        $this->assertStringNotContainsString('Zablokowana', $html);

        $duzo = $this->liczbaZapytanNaLiscie($autor);

        $this->assertSame($malo, $duzo, "Zapisy: {$malo} zapytań przy 2 wierszach, {$duzo} przy ".self::PELNA_STRONA.'.');
    }

    public function test_zaproszenia_i_dolaczenia_do_zeszytu_nie_daja_wachlarza_zapytan(): void
    {
        $halina = $this->user('zeszyt_halina', ['display_name' => 'Halina']);
        $jurek = $this->user('zeszyt_jurek', ['display_name' => 'Jurek']);

        $dodaj = function (int $ile) use ($halina, $jurek): void {
            for ($i = 0; $i < $ile; $i++) {
                $zeszyt = Collection::create([
                    'owner_id' => $halina->getKey(),
                    'name' => 'Zeszyt '.uniqid(),
                    'visibility' => 'private',
                ]);

                $zaproszenie = new CollectionInvitation;
                $zaproszenie->forceFill([
                    'collection_id' => $zeszyt->getKey(),
                    'inviter_id' => $halina->getKey(),
                    'invitee_id' => $jurek->getKey(),
                    'via_link' => false,
                    'status' => CollectionInvitation::STATUS_PENDING,
                    'expires_at' => now()->addDays(3),
                ]);
                $zaproszenie->save();

                Notification::query()->create([
                    'user_id' => $jurek->getKey(),
                    'actor_id' => $halina->getKey(),
                    'type' => Notification::TYPE_COLLECTION_INVITED,
                    'data' => ['invitation_id' => (string) $zaproszenie->getKey(), 'zeszyt' => $zeszyt->name],
                ]);
                Notification::query()->create([
                    'user_id' => $jurek->getKey(),
                    'actor_id' => $halina->getKey(),
                    'type' => Notification::TYPE_COLLECTION_JOINED,
                    'data' => ['collection_id' => (string) $zeszyt->getKey(), 'zeszyt' => $zeszyt->name],
                ]);
            }
        };

        $dodaj(1);
        $malo = $this->liczbaZapytanNaLiscie($jurek);

        $dodaj(self::PELNA_STRONA / 2 - 1);
        $this->assertSame(self::PELNA_STRONA, $jurek->notifications()->count());

        $html = $this->actingAs($jurek)->get(route('notifications.index'))->assertOk()->getContent();
        // KONTROLA DODATNIA: każdy z trzydziestu wierszy ma „Zobacz" — adres
        // zaproszenia albo zeszytu został rozstrzygnięty, nie pominięty.
        $this->assertSame(self::PELNA_STRONA, substr_count($html, '>Zobacz</button>'));

        $duzo = $this->liczbaZapytanNaLiscie($jurek);

        $this->assertSame($malo, $duzo, "Zeszyty: {$malo} zapytań przy 2 wierszach, {$duzo} przy ".self::PELNA_STRONA.'.');
    }

    /** Zaproszenie odwołane albo wygasłe i usunięty zeszyt dalej tracą „Zobacz" — bez zmiany reguły. */
    public function test_zbiorcze_sprawdzenie_zeszytow_zachowuje_reguly(): void
    {
        $halina = $this->user('regula_halina');
        $jurek = $this->user('regula_jurek');
        $zeszyt = Collection::create(['owner_id' => $halina->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);

        $drugi = Collection::create(['owner_id' => $halina->getKey(), 'name' => 'Desery', 'visibility' => 'private']);
        $zaproszenie = function (string $status, $wygasa, ?Collection $do = null) use ($halina, $jurek, $zeszyt): CollectionInvitation {
            $z = new CollectionInvitation;
            $z->forceFill([
                'responded_at' => $status === CollectionInvitation::STATUS_PENDING ? null : now(),
                'collection_id' => ($do ?? $zeszyt)->getKey(),
                'inviter_id' => $halina->getKey(),
                'invitee_id' => $jurek->getKey(),
                'via_link' => false,
                'status' => $status,
                'expires_at' => $wygasa,
            ]);
            $z->save();

            return $z;
        };
        $powiadom = fn (CollectionInvitation $z): Notification => Notification::query()->create([
            'user_id' => $jurek->getKey(),
            'actor_id' => $halina->getKey(),
            'type' => Notification::TYPE_COLLECTION_INVITED,
            'data' => ['invitation_id' => (string) $z->getKey(), 'zeszyt' => 'Obiady'],
        ]);

        $oczekujace = $powiadom($zaproszenie(CollectionInvitation::STATUS_PENDING, now()->addDay()));
        $odrzucone = $powiadom($zaproszenie(CollectionInvitation::STATUS_DECLINED, now()->addDay()));
        $wygasle = $powiadom($zaproszenie(CollectionInvitation::STATUS_PENDING, now()->subMinute(), $drugi));
        $bezZeszytu = Notification::query()->create([
            'user_id' => $jurek->getKey(),
            'type' => Notification::TYPE_COLLECTION_JOINED,
            'data' => ['collection_id' => (string) Str::uuid()],
        ]);

        $adresy = app(CelPowiadomienia::class)
            ->adresy([$oczekujace, $odrzucone, $wygasle, $bezZeszytu], $jurek);

        $this->assertNotNull($adresy[(string) $oczekujace->getKey()]);
        $this->assertNull($adresy[(string) $odrzucone->getKey()]);
        $this->assertNull($adresy[(string) $wygasle->getKey()]);
        $this->assertNull($adresy[(string) $bezZeszytu->getKey()]);
    }

    /**
     * Zbiorczy wybór imienia daje TO SAMO co wybór osobno dla każdej partii
     * — w całej macierzy: blokada w obie strony, ban, usunięcie konta,
     * zawieszenie (nie ukrywa), kolejność zapisu.
     */
    public function test_zbiorczy_wybor_imienia_jest_rownowazny_wyborowi_osobnemu(): void
    {
        $odbiorca = $this->user('rownowazny_odbiorca');
        $aktywna = $this->user('rownowazny_aktywna');
        $zablokowanaPrzezOdbiorce = $this->user('rownowazny_zablokowana');
        $blokujaca = $this->user('rownowazny_blokujaca');
        $zbanowana = $this->user('rownowazny_zbanowana', ['status' => User::STATUS_BANNED]);
        $doUsuniecia = $this->user('rownowazny_do_usuniecia', ['status' => User::STATUS_PENDING_DELETE]);
        $zawieszona = $this->user('rownowazny_zawieszona', ['status' => User::STATUS_SUSPENDED]);

        DB::table('blocks')->insert([
            ['blocker_id' => $odbiorca->getKey(), 'blocked_id' => $zablokowanaPrzezOdbiorce->getKey(), 'created_at' => now()],
            ['blocker_id' => $blokujaca->getKey(), 'blocked_id' => $odbiorca->getKey(), 'created_at' => now()],
        ]);

        $id = fn (User $u): string => (string) $u->getKey();
        $partie = [
            'a' => [$id($zablokowanaPrzezOdbiorce), $id($blokujaca), $id($zbanowana), $id($doUsuniecia), $id($zawieszona), $id($aktywna)],
            'b' => [$id($zablokowanaPrzezOdbiorce), $id($zbanowana), $id($doUsuniecia)],
            'c' => [$id($aktywna), $id($zawieszona)],
            'd' => [$id($zawieszona), $id($aktywna)],
            'e' => [$id($blokujaca)],
            'f' => [$id($doUsuniecia), $id($aktywna), $id($zablokowanaPrzezOdbiorce)],
        ];

        $widocznosc = app(WidocznoscPowiadomien::class);
        $hurtem = $widocznosc->pierwsiWidoczniZapisujacy($partie, $id($odbiorca));

        $this->assertSame(array_keys($partie), array_keys($hurtem));

        foreach ($partie as $klucz => $zapisujacy) {
            $osobno = $widocznosc->pierwszyWidocznyZapisujacy($zapisujacy, $id($odbiorca));

            $this->assertSame($osobno?->getKey(), $hurtem[$klucz]?->getKey(), "Partia {$klucz}: wynik hurtem różni się od wyniku osobnego.");
        }

        // Kontrola dodatnia macierzy: odpowiedzi nie są wszystkie takie same.
        // Zawieszenie nie ukrywa treści, więc zawieszona jest pierwszą widoczną.
        $this->assertSame($id($zawieszona), $hurtem['a']->getKey());
        $this->assertSame($id($aktywna), $hurtem['c']->getKey());
        $this->assertSame($id($zawieszona), $hurtem['d']->getKey());
        $this->assertNull($hurtem['b']);
        $this->assertNull($hurtem['e']);
    }

    public function test_plakietka_bez_nieprzeczytanych_nie_planuje_ciezkiego_zapytania(): void
    {
        $osoba = $this->user('plakietka_pusta');
        Notification::query()->create([
            'user_id' => $osoba->getKey(),
            'type' => Notification::TYPE_WELCOME,
            'data' => [],
        ]);
        Notification::query()->where('user_id', $osoba->getKey())->update(['read_at' => now()]);

        [, $sql] = $this->zapytania(fn () => $this->assertSame(0, $osoba->unreadNotificationsBadgeCount()));

        $this->assertCount(1, $sql, 'Bez nieprzeczytanych plakietka ma zrobić tylko tani EXISTS.');
        $this->assertStringContainsString('exists', strtolower($sql[0]));
        $this->assertStringNotContainsString('"blocks"', $sql[0]);
    }

    public function test_plakietka_z_nieprzeczytanymi_liczy_jak_dotad(): void
    {
        $osoba = $this->user('plakietka_pelna');
        $zablokowany = $this->user('plakietka_zablokowany');
        DB::table('blocks')->insert(['blocker_id' => $osoba->getKey(), 'blocked_id' => $zablokowany->getKey(), 'created_at' => now()]);

        // Dwa widoczne nieprzeczytane, jedno ukryte blokadą, jedno przeczytane.
        foreach ([null, null] as $_) {
            Notification::query()->create(['user_id' => $osoba->getKey(), 'type' => Notification::TYPE_WELCOME, 'data' => []]);
        }
        Notification::query()->create([
            'user_id' => $osoba->getKey(),
            'actor_id' => $zablokowany->getKey(),
            'type' => Notification::TYPE_FOLLOW,
            'data' => [],
        ]);
        Notification::query()->create(['user_id' => $osoba->getKey(), 'type' => Notification::TYPE_WELCOME, 'data' => []])
            ->forceFill(['read_at' => now()])->save();

        [, $sql] = $this->zapytania(fn () => $this->assertSame(2, $osoba->unreadNotificationsBadgeCount()));

        $this->assertCount(2, $sql, 'Z nieprzeczytanymi: tani EXISTS i właściwe zliczenie.');

        // Wszystkie nieprzeczytane ukryte blokadą: pre-check przepuszcza,
        // właściwe zapytanie dalej zwraca 0 (wynik identyczny jak dotąd).
        Notification::query()->where('user_id', $osoba->getKey())->where('type', Notification::TYPE_WELCOME)->update(['read_at' => now()]);
        $this->assertSame(0, $osoba->unreadNotificationsBadgeCount());
    }
}
