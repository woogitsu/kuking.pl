<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\CoMamWDomu;
use App\Models\PantryItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ZakupyDoSpizarniNazwanaListaTest extends TestCase
{
    use RefreshDatabase;

    private function lista(User $user, string $name): ShoppingList
    {
        $list = new ShoppingList(['name' => $name]);
        $list->user_id = $user->getKey();
        $list->save();

        return $list;
    }

    private function pozycja(User $user, string $text, ?ShoppingList $list = null): ShoppingListItem
    {
        $item = new ShoppingListItem(['text' => $text]);
        $item->user_id = $user->getKey();
        $item->list_id = $list?->getKey();
        $item->source = ShoppingListItem::SOURCE_MANUAL;
        $item->position = 0;
        $item->checked_at = now();
        $item->save();

        return $item;
    }

    public function test_blad_w_nazwanej_liscie_odtwarza_wlasciwe_pola_wybor_i_odnosniki(): void
    {
        $user = $this->user('zakupy2806');
        $list = $this->lista($user, 'Święta');
        $first = $this->pozycja($user, 'mąka pszenna', $list);
        $second = $this->pozycja($user, 'jajka', $list);
        $default = $this->pozycja($user, 'cukier');

        $form = (string) $this->actingAs($user)->get(route('shopping.pantry.form', ['lista' => $list->getKey()]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('name="lista" value="'.$list->getKey().'"', $form);
        $this->assertStringNotContainsString('name="nazwy['.$default->getKey().']"', $form);

        $html = (string) $this->actingAs($user)->followingRedirects()->post(route('shopping.pantry.store'), [
            'lista' => $list->getKey(),
            'pozycje' => [$first->getKey(), $second->getKey()],
            'nazwy' => [$first->getKey() => 'mąka', $second->getKey() => ''],
        ])->assertOk()->getContent();

        $this->assertStringContainsString('name="nazwy['.$first->getKey().']"', $html, 'ZAKUPY_2806_NAZWANA_LISTA_ZOSTAJE');
        $this->assertStringContainsString('value="mąka"', $html);
        $this->assertStringContainsString('name="nazwy['.$second->getKey().']"', $html);
        $this->assertStringContainsString('name="pozycje[]" value="'.$first->getKey().'" checked', $html);
        $this->assertStringContainsString('name="pozycje[]" value="'.$second->getKey().'" checked', $html);
        $this->assertStringContainsString('id="nazwa-'.$second->getKey().'-blad"', $html);
        $this->assertStringContainsString('Sprawdź formularz', $html);
        $this->assertStringContainsString(e(route('shopping.index', ['lista' => $list->getKey()])), $html);
        $this->assertStringNotContainsString('name="nazwy['.$default->getKey().']"', $html);
        $this->assertSame(0, PantryItem::query()->count());
    }

    public function test_wybrana_lista_nie_jest_zastapiona_domyslna_ani_inna_wlasna(): void
    {
        $user = $this->user('zakupy2806b');
        $list = $this->lista($user, 'Święta');
        $other = $this->lista($user, 'Weekend');
        $selected = $this->pozycja($user, 'mąka', $list);
        $wrong = $this->pozycja($user, 'sól', $other);
        $this->pozycja($user, 'cukier');

        $this->actingAs($user)->post(route('shopping.pantry.store'), [
            'lista' => $list->getKey(),
            'pozycje' => [$selected->getKey(), $wrong->getKey()],
            'nazwy' => [$selected->getKey() => 'mąka', $wrong->getKey() => 'sól'],
        ])->assertRedirect(route('shopping.pantry.form', ['lista' => $list->getKey()]))
            ->assertSessionHasErrors('pozycje');
        $this->assertSame(0, PantryItem::query()->count());

        $this->actingAs($user)->post(route('shopping.pantry.store'), [
            'lista' => $list->getKey(),
            'pozycje' => [$selected->getKey()],
            'nazwy' => [$selected->getKey() => 'mąka'],
        ])->assertRedirect(route('pantry.index'))->assertSessionHasNoErrors();
        $this->assertSame(['mąka'], PantryItem::query()->pluck('name')->all());
    }

    public function test_nazwana_lista_dziala_gdy_domyslna_jest_pusta_a_blad_limitu_nie_zapisuje_czesci(): void
    {
        $user = $this->user('zakupy2806d');
        $list = $this->lista($user, 'Święta');
        $first = $this->pozycja($user, 'mleko', $list);
        $second = $this->pozycja($user, 'ser', $list);

        foreach (range(1, CoMamWDomu::MAKS_PRODUKTOW - 1) as $i) {
            $user->pantryItems()->create(['name' => 'produkt '.chr(97 + intdiv($i, 26)).chr(97 + $i % 26)]);
        }
        $before = PantryItem::query()->count();

        $html = (string) $this->actingAs($user)->followingRedirects()->post(route('shopping.pantry.store'), [
            'lista' => $list->getKey(),
            'pozycje' => [$first->getKey(), $second->getKey()],
            'nazwy' => [$first->getKey() => 'mleko', $second->getKey() => 'ser'],
        ])->assertOk()->getContent();

        $this->assertStringContainsString('name="nazwy['.$first->getKey().']"', $html);
        $this->assertStringContainsString('name="nazwy['.$second->getKey().']"', $html);
        $this->assertStringContainsString('mieści się najwyżej', $html);
        $this->assertSame($before, PantryItem::query()->count());
        $this->assertSame(0, ShoppingListItem::query()->whereNull('list_id')->count());
    }

    public function test_cudza_lub_usunieta_lista_nie_przelacza_na_domyslna(): void
    {
        $user = $this->user('zakupy2806c');
        $foreign = $this->lista($this->user('obca2806'), 'Obca');
        $default = $this->pozycja($user, 'cukier');

        $this->actingAs($user)->post(route('shopping.pantry.store'), [
            'lista' => $foreign->getKey(), 'pozycje' => [$default->getKey()],
            'nazwy' => [$default->getKey() => 'cukier'],
        ])->assertForbidden();

        $deleted = $this->lista($user, 'Była');
        $deletedId = $deleted->getKey();
        $deleted->delete();
        $this->actingAs($user)->post(route('shopping.pantry.store'), [
            'lista' => $deletedId, 'pozycje' => [$default->getKey()],
            'nazwy' => [$default->getKey() => 'cukier'],
        ])->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', fn ($message) => str_contains((string) $message, 'Tej listy zakupów już nie ma'));
        $this->assertSame(0, PantryItem::query()->count());
    }
}
