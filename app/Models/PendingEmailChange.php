<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zamówiona, ale JESZCZE NIEOBOWIĄZUJĄCA zmiana adresu e-mail (issue #195).
 *
 * Dopóki ten wiersz istnieje, `users.email` jest nietknięty: logowanie
 * i reset hasła działają na STARYM adresie. Nowy adres wchodzi w życie
 * dopiero wtedy, gdy ktoś kliknie link wysłany na niego —
 * `App\Domain\Users\Actions\ConfirmEmailChange`.
 *
 * Wiersz znika na pięć sposobów i to jest pełna lista:
 *  1. potwierdzenie (`ConfirmEmailChange`),
 *  2. „Anuluj zmianę" na `/ustawienia/e-mail` (`CancelEmailChange`),
 *  3. zmiana albo reset hasła — bo to jest jedyna rada, jakiej udziela list
 *     ostrzegawczy do starego adresu, i musi być prawdziwa
 *     (`CancelEmailChange` wołane z `SecuritySettingsController`
 *     i `PasswordResetController`),
 *  4. kolejne żądanie tej samej osoby (`user_id` jest unikalne —
 *     `RequestEmailChange` nadpisuje wiersz),
 *  5. wygaśnięcie — `kuking:sprzataj-zmiany-adresu`
 *     (`App\Domain\Compliance\PrzedawnioneZmianyAdresu`).
 *
 * $fillable NIE ISTNIEJE i to jest celowe. Adres w tym wierszu prowadzi
 * wprost do przejęcia konta, więc zapisuje go wyłącznie
 * `RequestEmailChange`, jawnie, pole po polu — z tego samego powodu, dla
 * którego `email`, `status` i `role` są poza `$fillable` w `User`
 * (AGENTS.md §7). Domyślny `$guarded = ['*']` Eloquenta odrzuca tu każde
 * masowe przypisanie.
 *
 * @property string $new_email
 */
class PendingEmailChange extends Model
{
    use HasUuids;

    protected $table = 'pending_email_changes';

    /**
     * Tabela ma `created_at`, ale NIE ma `updated_at` — wiersza się nie
     * edytuje. Nowe żądanie zastępuje stary wiersz w całości, więc druga
     * data nie miałaby czego opisywać (ten sam wybór co w `audit_log`).
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Czy to żądanie jest jeszcze ważne.
     *
     * Pytamy o to W KODZIE, mimo że wygasłe wiersze i tak kasuje komenda
     * sprzątająca: komenda chodzi raz na dobę, a link ma przestać działać
     * co do minuty. Sprzątanie jest higieną danych, nie bramką
     * bezpieczeństwa.
     */
    public function jestWazne(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
