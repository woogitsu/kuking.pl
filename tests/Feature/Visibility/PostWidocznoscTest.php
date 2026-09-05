<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Models\Post;
use Illuminate\Database\Eloquent\Model;

/**
 * Wpis — pełna macierz: public / followers / private.
 */
class PostWidocznoscTest extends WidocznoscTestCase
{
    protected function widocznosci(): array
    {
        return ['public', 'followers', 'private'];
    }

    protected function utworz(string $widocznosc): Model
    {
        return Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'body' => 'Tajny rosol '.$widocznosc,
        ]);
    }

    protected function adres(Model $tresc): string
    {
        return route('posts.show', $tresc);
    }
}
