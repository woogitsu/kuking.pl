<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Security\FacebookConnectionConfirmation;
use App\Models\FacebookConnectionProof;
use App\Models\User;
use App\Support\AdresKanoniczny;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PotwierdzeniePolaczeniaFacebooka extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function viaQueues(): array
    {
        return ['mail' => 'high'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User || ! $notifiable->hasVerifiedEmail()) {
            return false;
        }

        $proof = FacebookConnectionProof::query()
            ->where('user_id', $notifiable->getKey())
            ->where('token_hash', FacebookConnectionProof::hashToken($this->token))
            ->where('expires_at', '>', now())->first();

        return $proof !== null && hash_equals($proof->account_state_hash,
            app(FacebookConnectionConfirmation::class)->accountState($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Potwierdź połączenie konta Facebooka z Kuking')
            ->line('Ktoś poprosił o połączenie konta Facebooka z Twoim kontem Kuking. Jeśli to Ty, wróć do tej samej przeglądarki i potwierdź połączenie w ciągu 10 minut.')
            ->action('Potwierdź połączenie', AdresKanoniczny::zbuduj(
                fn (): string => route('facebook.link.confirm', ['token' => $this->token]),
            ))
            ->line('Jeśli to nie Ty, zignoruj tę wiadomość. Samo kliknięcie nie połączy kont.');
    }
}
