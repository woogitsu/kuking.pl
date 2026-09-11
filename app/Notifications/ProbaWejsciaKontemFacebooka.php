<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * „Ktoś próbował wejść na Twoje konto kontem Facebooka" (issue #259, D-098).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TEN LIST W OGÓLE POWSTAŁ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wejście kontem Facebooka ODMAWIA, gdy adres z Facebooka pasuje do konta,
 * które u nas jest — bo Facebook nie mówi, czy ta skrzynka naprawdę należy
 * do osoby siedzącej przed ekranem (`FacebookLoginController`, D-098).
 *
 * Odmowa widzi jednak tylko ten, kto ją wywołał — a to może być zarówno
 * właściciel konta, który po prostu nie wie, że tak nie wolno, jak i ktoś
 * obcy, kto wpisał cudzy adres w swoim koncie na Facebooku. **Właściciel
 * konta nie dowiadywał się o tym w ogóle.** Ten list jest jedynym sygnałem,
 * jaki do niego dociera.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO NIE MA TU ODNOŚNIKA „TO JA, POŁĄCZ KONTA" — NAJWAŻNIEJSZE
 *  ZDANIE W TYM PLIKU
 * ────────────────────────────────────────────────────────────────────────
 *
 * Taki odnośnik byłby wygodny i byłby dziurą, przez którą przechodzi
 * dokładnie ten atak, przed którym stoi odmowa: obcy zakłada konto na
 * Facebooku, wpisuje w nim cudzy adres, klika „Wejdź kontem Facebooka" —
 * i wtedy MY wysyłamy właścicielowi wiarygodny list, którym ten jednym
 * kliknięciem oddaje obcemu wejście na swoje konto. Napastnik nie musiałby
 * nawet mieć dostępu do skrzynki; wystarczyłoby, żeby właściciel kliknął.
 *
 * Ten list jest więc POWIADOMIENIEM, NIE KLUCZEM. Nie zmienia niczego
 * i nie da się nim niczego zmienić. Droga do połączenia kont prowadzi przez
 * zalogowanie się u nas w zwykły sposób i ustawienia — czyli przez dowód
 * posiadania konta, którego Facebook nam nie dostarcza.
 *
 * To ta sama granica, którą trzyma `ZgloszonaZmianaAdresu` (issue #195),
 * i z tego samego powodu: żaden list nie niesie u nas uprawnienia do
 * zmiany stanu konta.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TEN LIST NIE MÓWI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Nie podaje ani nazwy konta na Facebooku, ani jego identyfikatora, ani
 * adresu IP. Właścicielowi nie są do niczego potrzebne, a napastnikowi,
 * który sam wywołał ten list na własnej skrzynce, powiedziałyby, co u nas
 * widać.
 */
final class ProbaWejsciaKontemFacebooka extends Notification implements ShouldQueue
{
    /** Kolejka — ten sam powód co w `PotwierdzenieAdresu` (audyt W3-13). */
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ktoś próbował wejść na Twoje konto w Kuking kontem Facebooka')
            ->view('mail.proba-wejscia-kontem-facebooka', [
                'displayName' => $notifiable->profile?->display_name,
                'linkLogowania' => route('login'),
                'linkBezpieczenstwo' => route('settings.security'),
            ]);
    }
}
