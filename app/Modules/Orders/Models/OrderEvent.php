<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrderEvent extends Model
{
    protected $guarded = [];
    const UPDATED_AT = null;

    public function update(array $attributes = [], array $options = [])
    {
        throw new LogicException("OrderEvents are strictly immutable and cannot be updated.");
    }

    public function delete()
    {
        throw new LogicException("OrderEvents are strictly immutable and cannot be deleted.");
    }

    public static function destroy($ids)
    {
        throw new LogicException("OrderEvents are strictly immutable and cannot be deleted.");
    }

    public function forceDelete()
    {
        throw new LogicException("OrderEvents are strictly immutable and cannot be deleted.");
    }
}
