<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KomunikatUgotowalemMowiPrawdeTest extends TestCase
{
    use RefreshDatabase;

    private const KOMUNIKAT = 'Twoje wykonanie, zdjęcia i odpowiedzi mogą zobaczyć osoby, które mogą zobaczyć ten przepis. Dostęp do nich mogą mieć także moderatorzy Kuking.';

    #[DataProvider('widocznoscPrzepisu')]
    public function test_formularz_przed_wyslaniem_wyjasnia_kto_zobaczy_wykonanie(
        string $widocznosc,
        bool $wlasnyPrzepis,
    ): void {
        $autor = $this->user('autor_widocznosci');
        $kucharz = $wlasnyPrzepis ? $autor : $this->user('kucharz_widocznosci');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => $widocznosc,
            'published_at' => now()->subDay(),
        ]);

        if (! $wlasnyPrzepis && $widocznosc === 'followers') {
            app(FollowUser::class)->handle($kucharz, $autor);
        }

        $html = $this->actingAs($kucharz)
            ->get(route('cooked.create', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="photos[]"', $html);
        $this->assertStringContainsString('name="note"', $html);
        $this->assertStringContainsString('name="changes_note"', $html);
        $this->assertStringContainsString('<button class="btn btn-primary" type="submit">Wyślij</button>', $html);
        $this->assertStringContainsString(
            self::KOMUNIKAT,
            $html,
        );
        // Informacja PRZED polami, które niosą osobiste treści (#2071):
        // formularz wypełnia się z góry na dół, więc zdanie pod ostatnim
        // polem przychodziło, gdy zdjęcie i uwaga były już dodane.
        $informacja = strpos($html, 'Kto to zobaczy?');
        $this->assertNotFalse($informacja);

        foreach (['name="photos[]"', 'name="note"', 'name="changes_note"', '<button class="btn btn-primary" type="submit">Wyślij</button>'] as $pole) {
            $this->assertLessThan(
                strpos($html, $pole),
                $informacja,
                "„Kto to zobaczy?” ma stać przed {$pole}.",
            );
        }
    }

    /**
     * Zdanie „mogą zobaczyć osoby, które mogą zobaczyć ten przepis” jest
     * prawdą tylko wtedy, gdy `CookedEventPolicy::view()` nigdy nie wpuszcza
     * nikogo, kogo nie wpuszcza `RecipePolicy::view()`, i wpuszcza każdego,
     * kogo ona wpuszcza — poza osobami, które wyklucza blokada z kucharzem
     * (stąd „mogą”, nie „zobaczą”).
     *
     * Najłatwiej się tu pomylić przy przepisie „dla obserwujących”: liczą się
     * obserwujący AUTORA PRZEPISU, nie osoby, która ugotowała. Obserwująca
     * kucharza nie zobaczy więc jej wykonania, jeśli sama nie obserwuje autora.
     *
     * Moderacja jest poza tą macierzą świadomie: `CookedEventPolicy` wpuszcza
     * ją z urzędu do każdego wykonania, więc zdanie wymienia ją osobno
     * („Dostęp do nich mogą mieć także moderatorzy Kuking.”) — pilnuje tego
     * `test_prywatny_przepis_komunikat_wymienia_moderatorow_zgodnie_z_policy`.
     */
    #[DataProvider('widocznosciDlaMacierzy')]
    public function test_kto_to_zobaczy_zgadza_sie_z_policy_wykonania(string $widocznosc): void
    {
        $autor = $this->user('autor_macierzy');
        // Prywatny przepis może ugotować wyłącznie jego autor (`RecipePolicy::cook`).
        $kucharz = $widocznosc === 'private' ? $autor : $this->user('kucharz_macierzy');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => $widocznosc,
            'published_at' => now()->subDay(),
        ]);
        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $kucharz->getKey(),
        ]);

        $obcy = $this->user('obcy_macierzy');
        $obserwujacaAutora = $this->user('obserwujaca_autora');
        app(FollowUser::class)->handle($obserwujacaAutora, $autor);
        $obserwujacaKucharza = $this->user('obserwujaca_kucharza');
        if ($kucharz->isNot($autor)) {
            app(FollowUser::class)->handle($obserwujacaKucharza, $kucharz);
        }
        $zablokowanaPrzezKucharza = $this->user('zablokowana_kucharz');
        app(BlockUser::class)->handle($kucharz, $zablokowanaPrzezKucharza);
        $blokujacaAutora = $this->user('blokujaca_autora');
        app(BlockUser::class)->handle($blokujacaAutora, $autor);

        $widzowie = [
            'gość' => null,
            'obca osoba' => $obcy,
            'obserwująca autora' => $obserwujacaAutora,
            'obserwująca kucharza' => $obserwujacaKucharza,
            'autor przepisu' => $autor,
            'blokująca autora' => $blokujacaAutora,
        ];

        foreach ($widzowie as $opis => $widz) {
            $this->assertSame(
                Gate::forUser($widz)->allows('view', $przepis),
                Gate::forUser($widz)->allows('view', $wykonanie),
                "{$widocznosc} / {$opis}: odbiorcy wykonania mają być dokładnie odbiorcami przepisu.",
            );
        }

        // Rozjazd dozwolony tylko w stronę WĘŻSZĄ: blokada z kucharzem.
        $this->assertFalse(Gate::forUser($zablokowanaPrzezKucharza)->allows('view', $wykonanie));

        // Oczekiwania wprost, żeby macierz nie przeszła na dwóch zepsutych Policy naraz.
        $this->assertSame($widocznosc === 'public', Gate::forUser(null)->allows('view', $wykonanie));
        $this->assertSame($widocznosc !== 'private', Gate::forUser($obserwujacaAutora)->allows('view', $wykonanie));
        $this->assertSame($widocznosc === 'public', Gate::forUser($obserwujacaKucharza)->allows('view', $wykonanie));
        $this->assertFalse(Gate::forUser($blokujacaAutora)->allows('view', $wykonanie));
    }

    /**
     * Przegląd PR #2156: przy przepisie prywatnym `RecipePolicy::view()` nie
     * wpuszcza moderatora, a `CookedEventPolicy` wpuszcza go do wykonania
     * z urzędu. Zdanie w formularzu musi to powiedzieć — inaczej obiecuje
     * autorowi prywatnego przepisu, że nikt poza nim nie zobaczy zdjęć.
     */
    public function test_prywatny_przepis_komunikat_wymienia_moderatorow_zgodnie_z_policy(): void
    {
        $autor = $this->user('autor_prywatny_mod');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'private',
            'published_at' => now()->subDay(),
        ]);

        $this->actingAs($autor)
            ->get(route('cooked.create', $przepis->slug))
            ->assertOk()
            ->assertSeeInOrder(['Kto to zobaczy?', self::KOMUNIKAT, 'name="photos[]"'], false);

        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $autor->getKey(),
        ]);
        $moderator = $this->user('moderator_prywatny', ['role' => User::ROLE_MODERATOR]);
        $obcy = $this->user('obcy_prywatny_mod');

        // Dokładnie ten wyjątek, który zdanie wymienia: przepisu moderator nie
        // widzi, wykonanie — tak. Obcej osobie nie wolno ani jednego, ani drugiego.
        $this->assertTrue($moderator->isModerator());
        $this->assertFalse(Gate::forUser($moderator)->allows('view', $przepis));
        $this->assertTrue(Gate::forUser($moderator)->allows('view', $wykonanie));
        $this->assertFalse(Gate::forUser($obcy)->allows('view', $przepis));
        $this->assertFalse(Gate::forUser($obcy)->allows('view', $wykonanie));
    }

    /** @return array<string, array{string}> */
    public static function widocznosciDlaMacierzy(): array
    {
        return [
            'publiczny' => ['public'],
            'dla_obserwujacych' => ['followers'],
            'prywatny' => ['private'],
        ];
    }

    /**
     * Osoba, która przepisu nie widzi, nie dostaje formularza — więc nie
     * dostaje też zdania o odbiorcach, które jej nie dotyczy.
     */
    public function test_formularza_nie_dostaje_osoba_bez_dostepu_do_przepisu(): void
    {
        $autor = $this->user('autor_bez_dostepu');
        $prywatny = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'private',
            'published_at' => now()->subDay(),
        ]);
        $dlaObserwujacych = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'followers',
            'published_at' => now()->subDay(),
        ]);
        $publiczny = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        $obcy = $this->user('obcy_bez_dostepu');
        $zablokowana = $this->user('zablokowana_bez_dostepu');
        app(BlockUser::class)->handle($autor, $zablokowana);

        $this->actingAs($obcy)->get(route('cooked.create', $prywatny->slug))->assertForbidden();
        $this->actingAs($obcy)->get(route('cooked.create', $dlaObserwujacych->slug))->assertForbidden();
        $this->actingAs($zablokowana)->get(route('cooked.create', $publiczny->slug))->assertForbidden();
    }

    /** @return array<string, array{string, bool}> */
    public static function widocznoscPrzepisu(): array
    {
        return [
            'publiczny' => ['public', false],
            'publiczny_autora' => ['public', true],
            'dla_obserwujacych' => ['followers', false],
            'dla_obserwujacych_autora' => ['followers', true],
            'prywatny_autora' => ['private', true],
        ];
    }

    /** @return array<string, array{bool, string, int}> */
    public static function autorzy(): array
    {
        return [
            'wlasny-przepis' => [true, 'active', 0],
            'inny-aktywny-autor' => [false, 'active', 1],
            'wymazany-autor' => [false, 'erased', 0],
            'zawieszony-autor-nadal-czyta' => [false, 'suspended', 1],
        ];
    }

    #[DataProvider('autorzy')]
    public function test_instrukcja_i_potwierdzenia_odpowiadaja_zapisowi_i_powiadomieniom(
        bool $wlasnyPrzepis,
        string $statusAutora,
        int $liczbaPowiadomien,
    ): void {
        $autor = $this->user('autor_komunikatu');
        $kucharz = $wlasnyPrzepis ? $autor : $this->user('kucharz_komunikatu');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => $wlasnyPrzepis ? 'private' : 'public',
            'published_at' => now()->subDay(),
        ]);

        if ($statusAutora === 'erased') {
            $autor->markDataErased();
        } elseif ($statusAutora === 'suspended') {
            $autor->suspend(now()->addDay());
        }
        $this->assertSame($statusAutora, $autor->fresh()->status);

        $formularz = $this->actingAs($kucharz)->get(route('cooked.create', $przepis->slug));
        $formularz->assertOk();
        if ($liczbaPowiadomien === 0) {
            $formularz->assertSeeText('Zapisz wykonanie tego przepisu.')
                ->assertDontSeeText('dowie się, że ktoś ugotował z tego przepisu.');
        } else {
            $formularz->assertSeeText('dowie się, że ktoś ugotował z tego przepisu.')
                ->assertDontSeeText('Zapisz wykonanie tego przepisu.');
        }

        $this->assertSame(1, preg_match('/name="klucz_wyslania" value="([^"]+)"/', $formularz->getContent(), $klucz));
        $dane = ['note' => 'Ugotowane przy odbiorze komunikatu.', 'klucz_wyslania' => $klucz[1]];
        $pierwsze = $this->post(route('cooked.store', $przepis->slug), $dane);
        $pierwsze->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Wykonanie zapisane.');
        $this->assertSame(1, CookedEvent::query()->where('recipe_id', $przepis->getKey())->count());
        $this->assertSame($liczbaPowiadomien, Notification::query()
            ->where('user_id', $autor->getKey())->where('type', Notification::TYPE_COOKED)->count());

        $powtorzenie = $this->post(route('cooked.store', $przepis->slug), $dane);
        $powtorzenie->assertRedirect($pierwsze->headers->get('Location'))
            ->assertSessionHasNoErrors()->assertSessionHas('status',
                'To wykonanie już zapisaliśmy. '
                .'Gotujesz ten przepis drugi raz? Otwórz „Ugotowałem” jeszcze raz — każde wykonanie zapisujemy osobno.',
            );
        $this->assertSame(1, CookedEvent::query()->where('recipe_id', $przepis->getKey())->count());
        $this->assertSame($liczbaPowiadomien, Notification::query()
            ->where('user_id', $autor->getKey())->where('type', Notification::TYPE_COOKED)->count());
    }
}
