<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'disk' => 'public',
            'object_key' => 'media/test/'.Str::uuid().'.webp',
            'mime_type' => 'image/webp',
            'bytes' => 120_000,
            'width' => 1600,
            'height' => 1200,
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => []],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => Media::STATUS_PENDING]);
    }
}
