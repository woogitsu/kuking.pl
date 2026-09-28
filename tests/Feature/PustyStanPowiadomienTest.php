<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PustyStanPowiadomienTest extends TestCase
{
    use RefreshDatabase;

    public function test_pusta_lista_wyjasnia_pelniejszy_zakres_powiadomien(): void
    {
        $this->actingAs($this->user('ala'))
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Nie ma jeszcze żadnych powiadomień')
            ->assertSee('Tu zobaczysz powiadomienia o Twoich przepisach i wpisach, nowych obserwujących oraz ważnych sprawach dotyczących Twojego konta.')
            ->assertDontSee('Tu pojawi się informacja, kiedy ktoś ugotuje z Twojego przepisu albo napisze komentarz.');
    }

    public function test_opis_pustej_listy_nie_zastepuje_prawdziwego_powiadomienia(): void
    {
        $ala = $this->user('ala');
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        app(NotifyUser::class)->handle(
            recipient: $ala,
            type: Notification::TYPE_FOLLOW,
            actor: $basia,
            data: ['username' => 'basia'],
        );

        $this->actingAs($ala)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Basia')
            ->assertDontSee('Nie ma jeszcze żadnych powiadomień')
            ->assertDontSee('Tu zobaczysz powiadomienia o Twoich przepisach i wpisach, nowych obserwujących oraz ważnych sprawach dotyczących Twojego konta.');
    }
}
