<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Database\Eloquent\Model;

enum ModelEvent: string
{
    case Saving = 'saving';
    case Saved = 'saved';
    case Creating = 'creating';
    case Created = 'created';
    case Updating = 'updating';
    case Updated = 'updated';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
    case Trashed = 'trashed';
    case Restoring = 'restoring';
    case Restored = 'restored';
    case ForceDeleting = 'forceDeleting';
    case ForceDeleted = 'forceDeleted';

    /**
     * @param  class-string<Model>  $model
     */
    public function for(string $model): string
    {
        return "eloquent.{$this->value}: {$model}";
    }
}
