<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ISSUE #1994 — dzienne podsumowanie „Smakowicie wygląda" po usunięciu wpisu
 * nie prowadzi na 404.
 *
 * Digest zapisuje `data.post_id` najnowszego wpisu z reakcją, a cel „Zobacz"
 * budował z niego `posts.show` bez sprawdzenia, czy wpis dalej jest. Autor
 * usuwa wpis miękko (`SoftDeletes`), moderacja zdejmuje go tak samo — więc
 * kliknięcie w powiadomienie zapisane wcześniej kończyło się stroną 404.
 *
 * Ten sam wzorzec co #771 (usunięte wykonanie) i #1034 (usunięty przepis):
 * powiadomienie ZOSTAJE na liście jako prawdziwe zdarzenie, traci „Zobacz",
 * dostaje jedno zdanie, co się stało, a stara karta (wyrenderowana przed
 * usunięciem) po kliknięciu wraca na listę z tym samym zdaniem. Bez
 * żadnego fragmentu usuniętej treści.
 *
 * Kontrola ujemna: gałąź `TYPE_SMAKOWICIE` w `CelPowiadomienia::adres()`
 * przywrócona do gołego `route('posts.show', $data['post_id'])` →
 * `test_pelna_sciezka_digest_usuniecie_przez_autora_lista_otwarcie` oblewa
 * (lista pokazuje „Zobacz", otwarcie odsyła na 404).
 */
class SmakowicieUsunietyWpisPowiadomienieTest extends TestCase
{
    use RefreshDatabase;

    private const ZDANIE = 'Ten wpis został usunięty albo nie jest już dostępny.';

    /** Komunikat po kliknięciu starej karty — to samo zdanie co na karcie, jak w #1034. */
    private const KOMUNIKAT = self::ZDANIE;

    public function test_pelna_sciezka_digest_usuniecie_przez_autora_lista_otwarcie(): void
    {
        [$autorka, $wpis, $powiadomienie] = $this->digest();

        // Kontrola dodatnia: wpis istnieje, „Zobacz" jest i prowadzi na wpis.
        $this->assertSame(route('posts.show', $wpis), $powiadomienie->adresDocelowy());
        $karta = $this->karta($autorka, $powiadomienie);
        $this->assertStringContainsString('>Zobacz</button>', $karta);
        $this->assertStringNotContainsString(self::ZDANIE, $karta);

        // Prawdziwa akcja autora: usunięcie wpisu.
        $this->actingAs($autorka)->delete(route('posts.destroy', $wpis))->assertRedirect(route('home'));
        $this->assertSoftDeleted($wpis);

        $karta = $this->karta($autorka, $powiadomienie);
        $this->assertStringContainsString('Jedna osoba napisała: Smakowicie wygląda', $karta);
        $this->assertStringContainsString(self::ZDANIE, $karta);
        $this->assertStringNotContainsString('>Zobacz</button>', $karta);
        $this->assertStringContainsString('Oznacz jako przeczytane', $karta);
        $this->assertStringNotContainsString('Sekretne pierogi', $karta, 'Karta nie może cytować usuniętej treści.');
        $this->assertNull($powiadomienie->refresh()->adresDocelowy());

        // Stara karta — kliknięcie „Zobacz" wraca na listę z komunikatem, nie 404.
        $this->actingAs($autorka)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status', self::KOMUNIKAT);

        $this->assertNotNull($powiadomienie->refresh()->read_at);
        $this->assertSame(0, $autorka->refresh()->unreadNotificationsCount());

        $html = (string) $this->actingAs($autorka)->get(route('notifications.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Sekretne pierogi', $html);
        $this->assertStringContainsString('Jedna osoba napisała: Smakowicie wygląda', $html);
    }

    public function test_wpis_zdjety_przez_moderacje_nie_daje_404(): void
    {
        [$autorka, $wpis, $powiadomienie] = $this->digest();

        // Zdjęcie przez moderację: `removed` i miękkie usunięcie (`ZdejmijZUrzedu`).
        $wpis->forceFill(['status' => Post::STATUS_REMOVED])->save();
        $wpis->delete();

        $karta = $this->karta($autorka, $powiadomienie);
        $this->assertStringContainsString(self::ZDANIE, $karta);
        $this->assertStringNotContainsString('>Zobacz</button>', $karta);

        $this->actingAs($autorka)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status', self::KOMUNIKAT);
    }

    /**
     * Ukryty przez moderację wpis autor dalej otwiera (`PostPolicy::view()`),
     * więc „Zobacz" zostaje i prowadzi na stronę, a nie na 403/404.
     */
    public function test_wpis_ukryty_przez_moderacje_autor_dalej_otwiera(): void
    {
        [$autorka, $wpis, $powiadomienie] = $this->digest();

        $wpis->forceFill(['status' => Post::STATUS_HIDDEN])->save();

        $this->assertStringContainsString('>Zobacz</button>', $this->karta($autorka, $powiadomienie));
        $this->actingAs($autorka)
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('posts.show', $wpis));
        $this->actingAs($autorka)->get(route('posts.show', $wpis))->assertOk();
    }

    /**
     * Odbiorcą digestu jest AUTOR wpisu. Zawężenie widoczności ani blokada
     * z reagującą osobą nie zamykają mu własnego wpisu — „Zobacz" działa,
     * a strona wpisu nie pokazuje osoby, z którą autor ma blokadę.
     */
    public function test_zmiana_widocznosci_i_blokada_nie_psuja_celu_ani_nie_ujawniaja(): void
    {
        [$autorka, $wpis, $powiadomienie, $anna] = $this->digest();

        foreach ([Post::VISIBILITY_FOLLOWERS, Post::VISIBILITY_PRIVATE] as $widocznosc) {
            $wpis->forceFill(['visibility' => $widocznosc])->save();

            $this->assertStringContainsString('>Zobacz</button>', $this->karta($autorka, $powiadomienie));
            $this->actingAs($autorka)
                ->post(route('notifications.open', $powiadomienie))
                ->assertRedirect(route('posts.show', $wpis));
            $this->actingAs($autorka)->get(route('posts.show', $wpis))->assertOk();
        }

        DB::table('blocks')->insert(['blocker_id' => $autorka->id, 'blocked_id' => $anna->id, 'created_at' => now()]);

        $karta = $this->karta($autorka, $powiadomienie);
        $this->assertStringContainsString('>Zobacz</button>', $karta);
        $this->assertStringNotContainsString('Anna Reagująca', $karta);
        $this->actingAs($autorka)
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('posts.show', $wpis));
        $this->actingAs($autorka)->get(route('posts.show', $wpis))->assertOk()->assertDontSee('Anna Reagująca');
    }

    /** Wpis istnieje, ale autor nie może go dziś otworzyć (pytania wyłączone) — bez 403. */
    public function test_wpis_ktorego_autor_nie_moze_otworzyc_nie_daje_403(): void
    {
        config(['kuking.questions.enabled' => true]);
        [$autorka, $wpis, $powiadomienie] = $this->digest(['kind' => Post::KIND_QUESTION, 'title' => 'Jak zagęścić żurek?']);
        config(['kuking.questions.enabled' => false]);

        $this->assertStringContainsString(self::ZDANIE, $this->karta($autorka, $powiadomienie));
        $this->actingAs($autorka)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status', self::KOMUNIKAT);
    }

    /** Stare albo uszkodzone `data.post_id` — bez 500 (PostgreSQL odrzuca nie-UUID). */
    public function test_uszkodzony_identyfikator_wpisu_nie_daje_500(): void
    {
        $autorka = $this->user('autorka');
        $powiadomienie = Notification::query()->create([
            'user_id' => $autorka->id,
            'type' => Notification::TYPE_SMAKOWICIE,
            'data' => ['osob' => 2, 'wpisow' => 1, 'post_id' => 'to-nie-uuid'],
        ]);

        $this->assertNull($powiadomienie->adresDocelowy());
        $this->assertStringContainsString(self::ZDANIE, $this->karta($autorka, $powiadomienie));
        $this->actingAs($autorka)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status', self::KOMUNIKAT);
    }

    /** Sprawdzenie wpisów idzie jednym zapytaniem na stronę, nie jednym na powiadomienie. */
    public function test_liczba_zapytan_o_wpisy_nie_rosnie_z_liczba_powiadomien(): void
    {
        $autorka = $this->user('autorka');
        $zmierz = function () use ($autorka): int {
            $licznik = 0;
            DB::listen(function ($zapytanie) use (&$licznik): void {
                if (preg_match('/^select .* from "posts" where "id" in/', $zapytanie->sql) === 1) {
                    $licznik++;
                }
            });
            $this->actingAs($autorka)->get(route('notifications.index'))->assertOk();

            return $licznik;
        };

        $this->powiadomienieDla($autorka, $this->wpis($autorka));
        $jedno = $zmierz();

        for ($i = 0; $i < 4; $i++) {
            $wpis = $this->wpis($autorka);
            $this->powiadomienieDla($autorka, $wpis);
            if ($i % 2 === 0) {
                $wpis->delete();
            }
        }
        $piec = $zmierz();

        $this->assertSame(1, $jedno);
        $this->assertSame(1, $piec, 'Przy pięciu powiadomieniach lista pyta o wpisy więcej niż raz.');
    }

    /**
     * Digest przez prawdziwą ścieżkę: reakcja z formularza, potem komenda
     * z harmonogramu.
     *
     * @return array{User, Post, Notification, User}
     */
    private function digest(array $atrybutyWpisu = []): array
    {
        $autorka = $this->user('autorka');
        $anna = $this->user('anna', ['display_name' => 'Anna Reagująca']);
        $wpis = $this->wpis($autorka, $atrybutyWpisu);

        $this->actingAs($anna)->post(route('posts.smakowicie', $wpis))->assertSessionHasNoErrors();
        Artisan::call('kuking:powiadom-smakowicie');

        $powiadomienie = Notification::query()
            ->where('user_id', $autorka->id)
            ->where('type', Notification::TYPE_SMAKOWICIE)
            ->sole();
        $this->assertSame((string) $wpis->getKey(), $powiadomienie->data['post_id']);

        return [$autorka, $wpis, $powiadomienie, $anna];
    }

    private function wpis(User $autorka, array $atrybuty = []): Post
    {
        return Post::factory()->create([
            'author_id' => $autorka->id,
            'body' => 'Sekretne pierogi',
            'published_at' => now()->subMinutes(5),
            ...$atrybuty,
        ]);
    }

    private function powiadomienieDla(User $autorka, Post $wpis): void
    {
        Notification::query()->create([
            'user_id' => $autorka->id,
            'type' => Notification::TYPE_SMAKOWICIE,
            'data' => ['osob' => 1, 'wpisow' => 1, 'post_id' => (string) $wpis->getKey()],
        ]);
    }

    /** Sama karta tego powiadomienia z listy (PULAPKI_TESTOW §1). */
    private function karta(User $odbiorca, Notification $powiadomienie): string
    {
        $html = (string) $this->actingAs($odbiorca)->get(route('notifications.index'))->assertOk()->getContent();
        $od = strpos($html, 'data-klucz="powiadomienie-'.$powiadomienie->getKey().'"');
        $this->assertNotFalse($od, 'Brak karty powiadomienia na liście.');
        $do = strpos($html, '</li>', $od);

        return substr($html, $od, $do - $od);
    }
}
