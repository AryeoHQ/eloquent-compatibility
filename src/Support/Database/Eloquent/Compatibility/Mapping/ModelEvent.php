<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Bridge\Operation;

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

    /** @return list<self> */
    public static function arming(): array
    {
        return [self::Creating, self::Updating, self::Deleting];
    }

    /** @return list<self> */
    public static function settling(): array
    {
        return [self::Saved, self::Updated, self::Deleted];
    }

    /**
     * @param  class-string<Model>  $model
     */
    public function for(string $model): string
    {
        return "eloquent.{$this->value}: {$model}";
    }

    public function operation(): null|Operation
    {
        return match ($this) {
            self::Creating => Operation::Insert,
            self::Updating => Operation::Update,
            self::Deleting => Operation::Delete,
            default => null,
        };
    }
}
