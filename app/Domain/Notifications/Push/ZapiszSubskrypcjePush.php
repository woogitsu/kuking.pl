<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Zapisuje przeglądarkę, której człowiek właśnie pozwolił pokazywać
 * powiadomienia (D-303). Jedyne miejsce, w którym powstaje wiersz
 * `push_subscriptions` — model ma puste `$fillable`.
 *
 * JEDNA PRZEGLĄDARKA = JEDEN WIERSZ, NIEZALEŻNIE OD KONTA. Adres subskrypcji
 * należy do przeglądarki. Gdy na wspólnym komputerze po Basi zaloguje się
 * Zenek i włączy powiadomienia, wiersz przechodzi na Zenka — inaczej Zenek
 * dostawałby na swoim ekranie powiadomienia Basi.
 *
 * Host adresu sprawdza wołający (`KanalPush::hostDozwolony()` w walidacji
 * kontrolera); tu sprawdzamy go jeszcze raz, bo to jest ostatnia linia przed
 * zapisem adresu, pod który serwer będzie później wysyłał żądania.
 */
final class ZapiszSubskrypcjePush
{
    private const BLOKADA_ENDPOINTU = 1998;

    public function handle(User $user, string $endpoint, string $p256dh, string $auth, string $kodowanie): PushSubscription
    {
        if (! KanalPush::hostDozwolony($endpoint)) {
            throw new \InvalidArgumentException('Niedozwolony adres usługi push.');
        }

        try {
            return $this->zapisz($user, $endpoint, $p256dh, $auth, $kodowanie);
        } catch (UniqueConstraintViolationException) {
            // Dwa równoległe zapisy tej samej przeglądarki (podwójne
            // kliknięcie): drugi trafia na wiersz pierwszego i go aktualizuje.
            return $this->zapisz($user, $endpoint, $p256dh, $auth, $kodowanie);
        }
    }

    private function zapisz(User $user, string $endpoint, string $p256dh, string $auth, string $kodowanie): PushSubscription
    {
        return DB::transaction(function () use ($user, $endpoint, $p256dh, $auth, $kodowanie): PushSubscription {
            // Ten sam endpoint musi najpierw ustalić jednego właściciela.
            // Blokada wiersza nie działa, gdy subskrypcja jeszcze nie istnieje.
            DB::selectOne('SELECT pg_advisory_xact_lock(?, hashtext(?))', [self::BLOKADA_ENDPOINTU, $endpoint]);

            $poprzedniWlasciciel = PushSubscription::query()
                ->whereRaw('md5(endpoint) = md5(?)', [$endpoint])
                ->where('endpoint', $endpoint)
                ->value('user_id');

            // Wszystkie zapisy kolekcji danego konta muszą przejść przez
            // wspólny wiersz. Przy transferze blokujemy oba konta w stałej
            // kolejności, zanim zablokujemy wiersz subskrypcji.
            $uzytkownicy = array_unique(array_filter([(string) $user->getKey(), (string) $poprzedniWlasciciel]));
            sort($uzytkownicy, SORT_STRING);
            foreach ($uzytkownicy as $id) {
                User::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            }

            $subskrypcja = PushSubscription::query()
                ->whereRaw('md5(endpoint) = md5(?)', [$endpoint])
                ->where('endpoint', $endpoint)
                ->lockForUpdate()
                ->first() ?? new PushSubscription;

            $subskrypcja->forceFill([
                'user_id' => $user->getKey(),
                'endpoint' => $endpoint,
                'klucz_p256dh' => $p256dh,
                'klucz_auth' => $auth,
                'kodowanie' => $kodowanie,
            ])->save();

            $this->przytnijNadmiar($user);

            return $subskrypcja;
        });
    }

    /** Ponad limit urządzeń znika najstarsze — nie ma odmowy przy dziesiątej przeglądarce. */
    private function przytnijNadmiar(User $user): void
    {
        $maks = max(1, (int) config('kuking.notifications.zewnetrzne.push_maks_urzadzen', 10));

        $nadmiar = $user->pushSubscriptions()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->skip($maks)
            ->pluck('id');

        if ($nadmiar->isNotEmpty()) {
            PushSubscription::query()->whereKey($nadmiar->all())->delete();
        }
    }
}
