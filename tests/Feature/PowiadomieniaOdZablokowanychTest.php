<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blokada a powiadomienia, które POWSTAŁY WCZEŚNIEJ.
 *
 * `NotifyUser` od początku odmawiał tworzenia NOWYCH powiadomień, gdy między
 * osobami jest blokada. Nie robił jednak nic z tymi, które powstały wcześniej —
 * a to jest właśnie ten moment, w którym ludzie blokują: po nieprzyjemnym
 * komentarzu, którego powiadomienie już leży na liście.
 *
 * Skutek: człowiek odcina się od kogoś i dalej widzi jego nazwisko i zdjęcie
 * na swojej liście powiadomień. Blokada wygląda wtedy jak przycisk, który nic
 * nie robi — a to gorsze niż brak blokady, bo obiecuje ochronę.
 *
 * Naprawa filtruje przy ODCZYCIE, nie kasuje wierszy: odblokowanie ma
 * przywrócić stan sprzed blokady, a kasowanie jest nieodwracalne.
 * Ostatni test pilnuje dokładnie tego.
 */
class PowiadomieniaOdZablokowanychTest extends TestCase
{
    use RefreshDatabase;

    public function test_powiadomienie_sprzed_blokady_znika_z_listy(): void
    {
        $ala = $this->user('ala');
        $basia = $this->user('basia', ['display_name' => 'Basia Nieprzyjemna']);

        app(NotifyUser::class)->handle(
            recipient: $ala,
            type: Notification::TYPE_FOLLOW,
            actor: $basia,
            data: ['username' => 'basia'],
        );

        // Zanim blokada padnie — powiadomienie jest widoczne. Bez tej
        // asercji test przechodziłby także wtedy, gdyby powiadomienie
        // w ogóle nie powstało (fałszywa zieleń).
        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Basia Nieprzyjemna');

        app(BlockUser::class)->handle($ala, $basia);

        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Basia Nieprzyjemna')
            ->assertSee('Nie ma jeszcze żadnych powiadomień');
    }

    public function test_licznik_w_belce_nie_liczy_powiadomien_od_zablokowanych(): void
    {
        $ala = $this->user('ala');
        $basia = $this->user('basia', ['display_name' => 'Basia Nieprzyjemna']);

        app(NotifyUser::class)->handle(
            recipient: $ala,
            type: Notification::TYPE_FOLLOW,
            actor: $basia,
            data: ['username' => 'basia'],
        );

        app(BlockUser::class)->handle($ala, $basia);

        // „3 nieprzeczytane" nad pustą listą wygląda jak zepsuty serwis.
        // Licznik i lista MUSZĄ liczyć to samo.
        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('nieprzeczytanych');

        $this->assertSame(0, $ala->fresh()->unreadNotificationsCount());
    }

    public function test_blokada_w_druga_strone_tez_ukrywa_powiadomienie(): void
    {
        $ala = $this->user('ala');
        $basia = $this->user('basia', ['display_name' => 'Basia Nieprzyjemna']);

        app(NotifyUser::class)->handle(
            recipient: $ala,
            type: Notification::TYPE_FOLLOW,
            actor: $basia,
            data: ['username' => 'basia'],
        );

        // To Basia blokuje Alę. Skutki blokady są obustronne
        // (User::hasBlockRelationWith), więc lista Ali też ma być czysta.
        app(BlockUser::class)->handle($basia, $ala);

        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Basia Nieprzyjemna');
    }

    public function test_odblokowanie_przywraca_powiadomienie(): void
    {
        $ala = $this->user('ala');
        $basia = $this->user('basia', ['display_name' => 'Basia Nieprzyjemna']);

        app(NotifyUser::class)->handle(
            recipient: $ala,
            type: Notification::TYPE_FOLLOW,
            actor: $basia,
            data: ['username' => 'basia'],
        );

        app(BlockUser::class)->handle($ala, $basia);
        app(UnblockUser::class)->handle($ala, $basia);

        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Basia Nieprzyjemna');

        // Wiersz nigdy nie został skasowany — to jest warunek powyższego.
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_powiadomienia_bez_autora_zostaja_widoczne(): void
    {
        $ala = $this->user('ala');
        $basia = $this->user('basia', ['display_name' => 'Basia Nieprzyjemna']);

        // Powitanie nie ma autora (actor_id = NULL). Filtr po blokadzie nie
        // może go przy okazji ukryć — inaczej blokada jednej osoby wycinałaby
        // wiadomości od serwisu.
        app(NotifyUser::class)->handle(
            recipient: $ala,
            type: Notification::TYPE_WELCOME,
            data: ['display_name' => 'Ala'],
        );

        app(BlockUser::class)->handle($ala, $basia);

        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Witamy w Kuking');
    }
}
