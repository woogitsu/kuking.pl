<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\ZachowaneZdjecia;
use App\Exceptions\BladDlaCzlowieka;
use App\Exceptions\BladZdjecFormularza;
use App\Models\User;
use App\Support\LimityZdjec;
use Illuminate\Http\UploadedFile;

/**
 * Zdjęcia do wpisu: nowo wgrane plus te, które przetrwały nieudaną walidację
 * w ukrytych polach formularza — wyjęte z `PostController` bez zmiany
 * zachowania (issue #970).
 *
 * BRAMKA WŁASNOŚCI JEST TU JEDYNA I MUSI BYĆ SZCZELNA. `media_ids` przychodzi
 * od klienta, więc bez sprawdzenia można by podpiąć pod własny wpis CUDZE
 * zdjęcie. Pytamy o właściciela ORAZ o to, czy zdjęcie nie jest już gdzieś
 * przypięte (`ZachowaneZdjecia`). UUID w formularzu to nie autoryzacja.
 */
final class ZbierzZdjeciaFormularza
{
    public function __construct(private readonly StoreUploadedImage $storeImage) {}

    /**
     * @param  mixed  $mediaIdsZFormularza  surowe `media_ids` z żądania
     * @param  array<int, UploadedFile>  $pliki
     * @return list<string>
     *
     * @throws BladZdjecFormularza
     */
    public function handle(User $user, mixed $mediaIdsZFormularza, array $pliki): array
    {
        // Kolejność z `media_ids[]`, nie z planu bazy (issue #934).
        $odzyskane = ZachowaneZdjecia::identyfikatory($mediaIdsZFormularza, $user->getKey());

        // Najpierw liczymy WYŁĄCZNIE zdjęcia, które naprawdę wolno odzyskać,
        // oraz pliki z bieżącego żądania. Sprawdzenie po `handle()` byłoby za
        // późne: odrzucony formularz zostawiałby nowy rekord i obiekt w storage.
        if (count($odzyskane) + count($pliki) > LimityZdjec::maksZdjecNaWysylke()) {
            throw new BladZdjecFormularza(
                LimityZdjec::komunikatZaDuzoZdjec().' Nowych zdjęć nie dodano. Usuń część zachowanych zdjęć albo wybierz mniej nowych.',
                $odzyskane,
            );
        }

        $wszystkie = $odzyskane;

        foreach ($pliki as $plik) {
            try {
                $wszystkie[] = $this->storeImage->handle($user, $plik)->getKey();
            } catch (BladDlaCzlowieka $e) {
                // Pliku input nie da się odtworzyć przez `withInput()`. Jeśli
                // późniejszy plik zawiedzie, zachowujemy identyfikatory tych,
                // które zdążyły już zostać poprawnie przyjęte.
                throw new BladZdjecFormularza($e->getMessage(), $wszystkie, $e);
            }
        }

        return array_values(array_unique($wszystkie));
    }
}
