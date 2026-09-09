<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContactMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    protected $model = ContactMessage::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'kind' => ContactMessage::KIND_BLAD,
            'message' => 'Nie mogę wgrać zdjęcia z telefonu — po kliknięciu Opublikuj nic się nie dzieje.',
            'contact_email' => fake()->safeEmail(),
            'page_path' => '/dodaj/zdjecie',
            'wydanie' => 'test',
        ];
    }

    /**
     * Wiadomość ZAŁATWIONA — czyli taka, od której w ogóle liczy się retencja.
     *
     * `handled_by` i `handled_at` idą przez `state()`, a nie przez
     * `ContactMessage::oznaczJako()`, bo fabryka buduje wiersz jednym
     * `INSERT`-em; komplet trzech kolumn naraz jest tu wymogiem CHECK-a
     * `contact_messages_handled_complete`, nie wygodą.
     */
    public function zalatwiona(?User $operator = null, ?CarbonInterface $kiedy = null): self
    {
        return $this->state(fn (): array => [
            'status' => ContactMessage::STATUS_ZALATWIONA,
            'handled_by' => ($operator ?? User::factory()->create())->getKey(),
            'handled_at' => $kiedy ?? now(),
        ]);
    }

    public function odZalogowanego(User $autor): self
    {
        return $this->state(fn (): array => [
            'user_id' => $autor->getKey(),
            // Adres zalogowanego jest na koncie — kopiowanie go do tej tabeli
            // byłoby powielaniem danych osobowych bez powodu.
            'contact_email' => null,
        ]);
    }
}
