<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Models\Post;
use Illuminate\Database\Eloquent\Model;

/** Schemat pytań korzysta z tej samej macierzy uprawnień co zwykły wpis. */
class PostQuestionVisibilityTest extends PostWidocznoscTest
{
    protected function utworz(string $widocznosc): Model
    {
        return Post::factory()->question()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
            'body' => 'Tajny rosol '.$widocznosc,
        ]);
    }
}
