<?php

declare(strict_types=1);

namespace App\Domain\Posts\Actions;

use App\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * Kolejność zdjęć we wpisie i sposób ich wyświetlania (issue #92).
 *
 * DLACZEGO TO JEST REGUŁA DOMENOWA, A NIE KOD W KONTROLERZE
 * Przy karuzeli i kolażu kolejność zdjęć jest WIDOCZNA, więc autor musi móc
 * ją zmienić. Zapis kolejności ma dwa twarde warunki bazy — `UNIQUE
 * (post_id, position)` i `CHECK (position >= 0)` — i jeden warunek produktu:
 * tryb inny niż „zwykle" nie ma sensu przy jednym zdjęciu. Trzymanie tego
 * w jednym miejscu znaczy, że drugi ekran (albo API w przyszłości) nie
 * obejdzie żadnego z nich.
 */
final class ArrangePostMedia
{
    /**
     * @param  list<string>  $orderedMediaIds  identyfikatory zdjęć w nowej kolejności
     */
    public function handle(Post $post, array $orderedMediaIds, string $displayMode): void
    {
        /** @var list<string> $istniejace */
        $istniejace = $post->media()->pluck('media.id')->all();

        // Bierzemy TYLKO zdjęcia naprawdę przypięte do tego wpisu, a te,
        // których w przysłanej kolejności zabrakło, dokładamy na koniec.
        // Formularz przychodzi od klienta: bez tego dałoby się zgubić zdjęcie
        // ze wpisu, po prostu nie wysyłając jego identyfikatora.
        $kolejnosc = array_values(array_filter(
            array_unique($orderedMediaIds),
            static fn (string $id): bool => in_array($id, $istniejace, true),
        ));

        foreach ($istniejace as $id) {
            if (! in_array($id, $kolejnosc, true)) {
                $kolejnosc[] = $id;
            }
        }

        // Tryb spoza listy albo tryb przy jednym zdjęciu schodzi do „zwykle" —
        // ta sama zasada co przy publikacji (PublishPost).
        if (count($kolejnosc) < 2 || ! in_array($displayMode, Post::dozwoloneTrybyWyswietlania(), true)) {
            $displayMode = Post::DISPLAY_NORMAL;
        }

        DB::transaction(function () use ($post, $kolejnosc, $displayMode): void {
            // DWA PRZEBIEGI, I TO NIE JEST OSTROŻNOŚĆ NA ZAPAS.
            // `post_media` ma UNIQUE (post_id, position). Zamiana miejscami
            // dwóch zdjęć w jednym przebiegu przechodzi przez stan, w którym
            // dwa wiersze mają tę samą pozycję — i baza słusznie odmawia.
            // Dlatego najpierw odsuwamy wszystko w bezpieczny zakres
            // (100 i wyżej; wpis ma najwyżej kilka zdjęć), a dopiero potem
            // ustawiamy docelowe 0, 1, 2… Wartości pośrednie są dodatnie,
            // więc CHECK (position >= 0) też jest spełniony przez cały czas.
            foreach ($kolejnosc as $index => $mediaId) {
                $this->ustawPozycje($post, $mediaId, 100 + $index);
            }

            foreach ($kolejnosc as $index => $mediaId) {
                $this->ustawPozycje($post, $mediaId, $index);
            }

            $post->forceFill(['display_mode' => $displayMode])->save();
        });

        $post->unsetRelation('media');
    }

    private function ustawPozycje(Post $post, string $mediaId, int $pozycja): void
    {
        DB::table('post_media')
            ->where('post_id', $post->getKey())
            ->where('media_id', $mediaId)
            ->update(['position' => $pozycja]);
    }
}
