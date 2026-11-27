<?php

namespace App\Models;

use App\Platform\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** In-app notification for one user inside one agency (bell menu). */
#[Fillable(['organization_id', 'user_id', 'type', 'title', 'body', 'link', 'data', 'read_at'])]
class AppNotification extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }
}
