<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\ProcessUploadedImage;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * „Ugotowałem” po utracie dostępu do przepisu między sprawdzeniem Policy
 * a zapisem wykonania (issue #2017).
 *
 * CO BYŁO ZŁAMANE
 * Kontroler (i API) pytał `RecipePolicy::cook`, a potem oddawał TE SAME
 * modele do `RecordCookedEvent`, które sprawdzało je jeszcze raz — ale na
 * stanie wczytanym przed tym, zanim ktoś inny zdążył coś zmienić: przepis
 * z pamięci, autor wczytany przy pierwszym sprawdzeniu, kucharz z sesji.
 * Autor przełączał przepis na „tylko ja”, moderacja go ukrywała albo
 * banowała autora — a wykonanie i tak się zapisywało, a autor dostawał
 * powiadomienie o przepisie, którego ta osoba już nie mogła zobaczyć.
 *
 * JAK TO ODTWARZAMY NA JEDNYM POŁĄCZENIU
 * Zmiana idzie PROSTO DO BAZY, przez zapytanie, nie przez model, który
 * dostaje akcja — dokładnie tak, jak wygląda zmiana z drugiego żądania:
 * baza już ją ma, a obiekty w pamięci tego żądania o niej nie wiedzą.
 * Sprawdzenie Policy przed zmianą jest tym, co robi kontroler; akcja
 * dostaje potem nieświeże modele.
 *
 * CZEGO TEN PLIK NIE DOWODZI
 * Że zmiana, która zatwierdza się W TRAKCIE transakcji wykonania, czeka na
 * nią albo ją odcina. Tego jedno połączenie nie pokaże — robi to
 * `tests/Dwa/UgotowalemKontraUtrataDostepuNaDwochPolaczeniachTest.php`.
 *
 * Blokada i odobserwowanie oblewały się także PRZED naprawą (zapytanie
 * o `blocks` i `follows` szło do bazy zawsze świeże), więc tutaj pilnują
 * wyłącznie tego, żeby naprawa ich nie zgubiła. Ich wyścig w ścisłym
 * znaczeniu mierzy grupa `dwa-polaczenia`.
 */
class UgotowalemPoUtracieDostepuTest extends TestCase
{
    use RefreshDatabase;

    private const ODMOWA = 'Nie można dodać wykonania do tego przepisu.';

    /**
     * Zmiany stanu, które odbierają kucharzowi prawo do „Ugotowałem”.
     *
     * @return iterable<string, array{string}>
     */
    public static function utratyDostepu(): iterable
    {
        yield 'autor ustawia „tylko ja”' => ['prywatny'];
        yield 'autor ustawia „dla obserwujących”' => ['dla_obserwujacych'];
        yield 'kucharz przestaje obserwować przy „dla obserwujących”' => ['odobserwowanie'];
        yield 'moderacja ukrywa przepis' => ['ukryty'];
        yield 'moderacja zdejmuje przepis' => ['zdjety'];
        yield 'autor zbanowany' => ['autor_zbanowany'];
        yield 'autor w karencji usunięcia konta' => ['autor_w_karencji'];
        yield 'kucharz zawieszony' => ['kucharz_zawieszony'];
        yield 'kucharz zbanowany' => ['kucharz_zbanowany'];
        yield 'autor blokuje kucharza' => ['blokada_autora'];
        yield 'kucharz blokuje autora' => ['blokada_kucharza'];
    }

    /**
     * Zmiany, które dostępu NIE odbierają — kontrola dodatnia. Bez niej
     * odmowa wyżej mogłaby znaczyć, że akcja przestała zapisywać cokolwiek
     * po zmianie w bazie (`docs/PULAPKI_TESTOW.md` §4).
     *
     * Zawieszenie autora stoi tu świadomie: to kara za pisanie, nie za
     * czytanie — przepis zostaje widoczny, a autor dostaje powiadomienie
     * (AGENTS.md §1, druga granica).
     *
     * @return iterable<string, array{string}>
     */
    public static function zmianyBezUtratyDostepu(): iterable
    {
        yield 'bez żadnej zmiany' => ['nic'];
        yield 'autor poprawia tytuł' => ['nowy_tytul'];
        yield 'autor zawieszony' => ['autor_zawieszony'];
    }

    #[DataProvider('utratyDostepu')]
    public function test_akcja_odmawia_na_swiezym_stanie_bez_wykonania_zdjec_i_powiadomienia(string $zmiana): void
    {
        [$autor, $kucharz, $przepis] = $this->uczestnicy($zmiana);
        $zdjecie = Media::factory()->create(['owner_id' => $kucharz->getKey()]);

        // To, co robi kontroler i API przed akcją.
        $this->assertTrue(Gate::forUser($kucharz)->allows('cook', $przepis), 'Przed zmianą kucharz musi mieć dostęp.');

        $this->zmien($zmiana, $autor, $kucharz, $przepis);

        try {
            app(RecordCookedEvent::class)->handle(
                cook: $kucharz,
                recipe: $przepis,
                note: 'Wyszło.',
                mediaIds: [(string) $zdjecie->getKey()],
                kluczWyslania: '0199a0a0-0000-7000-8000-000000002017',
            );
            $this->fail('Wykonanie zapisało się po utracie dostępu ('.$zmiana.').');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(self::ODMOWA, $e->getMessage());
        }

        $this->assertSame(0, CookedEvent::query()->count(), 'Nie może powstać wykonanie.');
        $this->assertSame(0, DB::table('cooked_event_media')->count(), 'Zdjęcie nie może zostać przypięte.');
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_COOKED)->count(), 'Autor nie może dostać powiadomienia.');
        $this->assertSame(0, DB::table('audit_log')->where('action', 'cooked_event.created')->count());
    }

    #[DataProvider('zmianyBezUtratyDostepu')]
    public function test_kontrola_dodatnia_zmiana_bez_utraty_dostepu_zapisuje_i_powiadamia(string $zmiana): void
    {
        [$autor, $kucharz, $przepis] = $this->uczestnicy($zmiana);
        $zdjecie = Media::factory()->create(['owner_id' => $kucharz->getKey()]);

        $this->assertTrue(Gate::forUser($kucharz)->allows('cook', $przepis));

        $this->zmien($zmiana, $autor, $kucharz, $przepis);

        $wykonanie = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $przepis,
            note: 'Wyszło.',
            mediaIds: [(string) $zdjecie->getKey()],
        );

        $this->assertTrue($wykonanie->wasRecentlyCreated);
        $this->assertSame(1, CookedEvent::query()->count());
        $this->assertSame(1, DB::table('cooked_event_media')->where('cooked_event_id', $wykonanie->getKey())->count());

        $powiadomienia = Notification::query()->where('type', Notification::TYPE_COOKED)->get();
        $this->assertCount(1, $powiadomienia);
        $this->assertSame((string) $autor->getKey(), (string) $powiadomienia[0]->user_id);
        $this->assertSame((string) $wykonanie->getKey(), (string) $powiadomienia[0]->data['cooked_event_id']);

        if ($zmiana === 'nowy_tytul') {
            // Powiadomienie niesie stan z chwili zapisu, nie sprzed niej.
            $this->assertSame('Pierogi po zmianie', $powiadomienia[0]->data['recipe_title']);
        }
    }

    /**
     * Wiele wykonań tej samej osoby nadal wolno zapisać (D-005, NIGDY
     * UNIQUE) — ponowne sprawdzenie pod blokadą nie może tego odebrać.
     */
    public function test_kontrola_dwa_wykonania_tej_samej_osoby_nadal_sie_zapisuja(): void
    {
        [$autor, $kucharz, $przepis] = $this->uczestnicy('nic');
        $akcja = app(RecordCookedEvent::class);

        $akcja->handle(cook: $kucharz, recipe: $przepis, kluczWyslania: '0199a0a0-0000-7000-8000-000000000001');
        $akcja->handle(cook: $kucharz, recipe: $przepis, kluczWyslania: '0199a0a0-0000-7000-8000-000000000002');
        $ponowienie = $akcja->handle(cook: $kucharz, recipe: $przepis, kluczWyslania: '0199a0a0-0000-7000-8000-000000000002');

        $this->assertFalse($ponowienie->wasRecentlyCreated, 'To samo wysłanie to to samo wykonanie.');
        $this->assertSame(2, CookedEvent::query()->count());
        $this->assertSame(2, Notification::query()->where('user_id', $autor->getKey())->where('type', Notification::TYPE_COOKED)->count());
    }

    /**
     * Ścieżka HTTP: kontroler przepuścił żądanie (`authorize('cook')`),
     * a zmiana wchodzi zaraz po tym sprawdzeniu. Człowiek dostaje ten sam
     * komunikat co przy braku dostępu, formularz wraca z jego tekstem, a
     * wgrane zdjęcie nie zostaje przypięte do żadnego wykonania.
     *
     * @return iterable<string, array{string}>
     */
    public static function utratyDostepuPrzezHttp(): iterable
    {
        yield 'autor ustawia „tylko ja”' => ['prywatny'];
        yield 'moderacja ukrywa przepis' => ['ukryty'];
        yield 'autor zbanowany' => ['autor_zbanowany'];
        yield 'kucharz zawieszony' => ['kucharz_zawieszony'];
    }

    #[DataProvider('utratyDostepuPrzezHttp')]
    public function test_formularz_po_utracie_dostepu_wraca_z_komunikatem_bez_zapisu(string $zmiana): void
    {
        Storage::fake((string) config('kuking.media.disk'));
        Storage::fake((string) config('kuking.media.public_disk'));
        Queue::fake([ProcessUploadedImage::class]);

        [$autor, $kucharz, $przepis] = $this->uczestnicy($zmiana);

        $this->poPierwszymSprawdzeniuCook(fn () => $this->zmien($zmiana, $autor, $kucharz, $przepis));

        $this->actingAs($kucharz)
            ->from(route('cooked.create', $przepis->slug))
            ->post(route('cooked.store', $przepis->slug), [
                'note' => 'Wyszło pięknie.',
                'photos' => [UploadedFile::fake()->image('obiad.jpg', 640, 480)],
            ])
            ->assertRedirect(route('cooked.create', $przepis->slug))
            ->assertSessionHasErrors(['note' => self::ODMOWA])
            ->assertSessionHasInput('note', 'Wyszło pięknie.');

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(0, DB::table('cooked_event_media')->count());
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_COOKED)->count());
        // Kontrola dodatnia: zdjęcie naprawdę dotarło — nie przepadło, tylko
        // nie zostało przypięte (wraca w formularzu, resztę sprząta
        // `kuking:sprzataj-osierocone-zdjecia`).
        $this->assertSame(1, Media::query()->where('owner_id', $kucharz->getKey())->count());
    }

    public function test_kontrola_formularz_bez_zmiany_zapisuje_wykonanie(): void
    {
        [$autor, $kucharz, $przepis] = $this->uczestnicy('nic');

        $this->poPierwszymSprawdzeniuCook(fn () => $this->zmien('nic', $autor, $kucharz, $przepis));

        $this->actingAs($kucharz)
            ->post(route('cooked.store', $przepis->slug), ['note' => 'Wyszło pięknie.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CookedEvent::query()->count());
        $this->assertSame(1, Notification::query()->where('user_id', $autor->getKey())->where('type', Notification::TYPE_COOKED)->count());
    }

    /**
     * Kolejność blokad: `media` → `users` (rosnąco po id) → `recipes`,
     * wszystko PRZED wstawieniem wykonania. To ta sama kolejność co
     * w `PublishRecipe` (D-103) — odwrócenie którejkolwiek pary to
     * zakleszczenie z publikacją, moderacją albo kasowaniem konta.
     * Zakleszczenia jedno połączenie nie pokaże, ale ten zapis kolejności
     * pilnuje, żeby naprawa nie rozjechała się po cichu z tym, co mierzy
     * grupa `dwa-polaczenia`.
     */
    public function test_blokady_media_konta_rosnaco_przepis_i_dopiero_wtedy_zapis(): void
    {
        [$autor, $kucharz, $przepis] = $this->uczestnicy('nic');
        $zdjecie = Media::factory()->create(['owner_id' => $kucharz->getKey()]);

        $zapytania = [];
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            $zapytania[] = ['sql' => strtolower($zapytanie->sql), 'bindings' => $zapytanie->bindings];
        });

        app(RecordCookedEvent::class)->handle(cook: $kucharz, recipe: $przepis, mediaIds: [(string) $zdjecie->getKey()]);

        $gdzie = function (Closure $warunek) use ($zapytania): array {
            return array_keys(array_filter($zapytania, $warunek));
        };

        $media = $gdzie(fn (array $z): bool => str_contains($z['sql'], 'from "media"') && str_contains($z['sql'], 'for update'));
        $konta = $gdzie(fn (array $z): bool => str_contains($z['sql'], 'from "users"') && str_contains($z['sql'], 'for share'));
        $przepisy = $gdzie(fn (array $z): bool => str_contains($z['sql'], 'from "recipes"') && str_contains($z['sql'], 'for share'));
        $wstawienie = $gdzie(fn (array $z): bool => str_starts_with($z['sql'], 'insert into "cooked_events"'));

        $this->assertCount(1, $media);
        $this->assertCount(2, $konta, 'Oba konta — kucharza i autora — pod blokadą.');
        $this->assertCount(1, $przepisy);
        $this->assertCount(1, $wstawienie);

        $this->assertLessThan($konta[0], $media[0]);
        $this->assertLessThan($przepisy[0], $konta[1]);
        $this->assertLessThan($wstawienie[0], $przepisy[0]);

        $kolejnoscKont = [(string) $zapytania[$konta[0]]['bindings'][0], (string) $zapytania[$konta[1]]['bindings'][0]];
        $oczekiwana = [(string) $autor->getKey(), (string) $kucharz->getKey()];
        sort($oczekiwana, SORT_STRING);
        $this->assertSame($oczekiwana, $kolejnoscKont, 'Konta rosnąco po identyfikatorze, jak w `ZamekPary`.');

        $this->assertSame([], $gdzie(fn (array $z): bool => str_contains($z['sql'], 'for update') && (str_contains($z['sql'], 'from "users"') || str_contains($z['sql'], 'from "recipes"'))), '`FOR UPDATE` ustawiałby w kolejce dwa niezależne wykonania.');
    }

    // -----------------------------------------------------------------

    /** @return array{User, User, Recipe} */
    private function uczestnicy(string $zmiana): array
    {
        $autor = $this->user('autor2017');
        $kucharz = $this->user('kucharz2017');

        $obserwowany = in_array($zmiana, ['odobserwowanie'], true);

        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Pierogi',
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => $obserwowany ? 'followers' : 'public',
            'published_at' => now()->subDay(),
        ]);

        if ($obserwowany) {
            DB::table('follows')->insert(['follower_id' => $kucharz->getKey(), 'followed_id' => $autor->getKey()]);
        }

        return [$autor, $kucharz, $przepis];
    }

    /**
     * Zmiana stanu „z drugiego żądania”: prosto w bazie, z pominięciem
     * modeli, które trzyma to żądanie.
     */
    private function zmien(string $zmiana, User $autor, User $kucharz, Recipe $przepis): void
    {
        $przepisy = fn () => DB::table('recipes')->where('id', $przepis->getKey());
        $konto = fn (User $kto) => DB::table('users')->where('id', $kto->getKey());
        $blokada = fn (User $kto, User $kogo) => DB::table('blocks')->insert([
            'blocker_id' => $kto->getKey(), 'blocked_id' => $kogo->getKey(),
        ]);

        match ($zmiana) {
            'nic' => null,
            'nowy_tytul' => $przepisy()->update(['title' => 'Pierogi po zmianie']),
            'prywatny' => $przepisy()->update(['visibility' => 'private']),
            'dla_obserwujacych' => $przepisy()->update(['visibility' => 'followers']),
            'odobserwowanie' => DB::table('follows')->where('follower_id', $kucharz->getKey())->delete(),
            'ukryty' => $przepisy()->update(['status' => Recipe::STATUS_HIDDEN]),
            'zdjety' => $przepisy()->update(['deleted_at' => now()]),
            'autor_zbanowany' => $konto($autor)->update(['status' => User::STATUS_BANNED]),
            'autor_w_karencji' => $konto($autor)->update(['status' => User::STATUS_PENDING_DELETE]),
            'autor_zawieszony' => $konto($autor)->update(['status' => User::STATUS_SUSPENDED, 'status_expires_at' => now()->addDay()]),
            'kucharz_zawieszony' => $konto($kucharz)->update(['status' => User::STATUS_SUSPENDED, 'status_expires_at' => now()->addDay()]),
            'kucharz_zbanowany' => $konto($kucharz)->update(['status' => User::STATUS_BANNED]),
            'blokada_autora' => $blokada($autor, $kucharz),
            'blokada_kucharza' => $blokada($kucharz, $autor),
            default => throw new \LogicException('Nieobsłużony wariant w match.'),
        };
    }

    /**
     * Wykonuje `$zmiana` raz, zaraz po PIERWSZYM sprawdzeniu `cook` —
     * czyli po `authorize()` w kontrolerze. Wynik sprawdzenia zostaje
     * nietknięty (`null` = „nie nadpisuję”).
     */
    private function poPierwszymSprawdzeniuCook(Closure $zmiana): void
    {
        $uzbrojone = true;

        Gate::after(function (?User $kto, string $zdolnosc, bool|Response|null $wynik) use (&$uzbrojone, $zmiana): null {
            if ($uzbrojone && $zdolnosc === 'cook') {
                $uzbrojone = false;
                $zmiana();
            }

            return null;
        });
    }
}
