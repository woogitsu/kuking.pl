<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class PostTag extends Pivot
{
    protected $table = 'post_tags';

    protected function casts(): array
    {
        return ['dodany_recznie' => 'boolean', 'position' => 'integer'];
    }
}
