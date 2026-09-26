<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * STRAŻNIK ŚWIEŻOŚCI LISTU Z TOKENEM BROKERA HASEŁ — w chwili wysyłki.
 *
 * List z linkiem do ustawienia hasła czeka w kolejce. Gdy worker stoi
 * (9.09.2026, #599: cztery takie listy w `failed_jobs`), człowiek zdąży
 * poprosić drugi raz — broker ZASTĘPUJE wtedy token. Worker, który wróci,
 * wysłałby list z martwym linkiem: osoba klika i widzi „link wygasł",
 * choć przed chwilą go dostała.
 *
 * Do 25.09.2026 strażnik żył tylko w `UstawienieNowegoHasla`, a bliźniak
 * `UstawienieHaslaZamiastLinku` — ten sam token, ta sama trasa — wysyłał
 * bez sprawdzenia (audyt B8-04). Jedno miejsce reguły dla obu klas.
 *
 * Broker sprawdza zużycie i zastąpienie tokenu; sama data nie wystarcza.
 */
trait SwiezyTokenResetuHasla
{
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User || ! Password::tokenExists($notifiable, $this->token)) {
            return false;
        }

        $created = $this->createdAt($notifiable);

        return $created !== null && $this->expiresAt($created)->isFuture();
    }

    /**
     * Termin zapisany w zadaniu w chwili zlecenia (może być wcześniejszy niż
     * wynikający z konfiguracji). `null` = licz wyłącznie z wystawienia tokenu.
     */
    protected function terminZZadania(): ?Carbon
    {
        return null;
    }

    private function createdAt(User $notifiable): ?Carbon
    {
        $broker = config('auth.passwords.'.config('auth.defaults.passwords'));
        $created = DB::connection($broker['connection'] ?? null)
            ->table($broker['table'])
            ->where('email', $notifiable->getEmailForPasswordReset())
            ->value('created_at');

        return $created === null ? null : Carbon::parse($created);
    }

    private function expiresAt(Carbon $created): Carbon
    {
        $expiry = $created->copy()->addMinutes((int) config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire', 60,
        ));

        // Starsze zadanie nie ma daty: odtwarzamy ją z wystawienia w bazie,
        // nigdy z czasu wykonania kolejki. Nowe zachowuje także własny termin.
        $termin = $this->terminZZadania();

        return $termin === null ? $expiry : $expiry->min($termin);
    }
}
