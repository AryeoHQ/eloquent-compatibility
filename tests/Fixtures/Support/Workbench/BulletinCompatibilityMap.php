<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;

final readonly class BulletinCompatibilityMap implements CompatibilityMap
{
    /**
     * @param  class-string<Model>  $bulletin
     * @param  class-string<Publication>  $publication
     */
    public function __construct(private string $bulletin = Bulletin::class, private string $publication = Publication::class) {}

    public function name(): string
    {
        return 'bulletins';
    }

    public function previousModel(): PreviousModel
    {
        return PreviousModel::servedAsView('bulletins')
            ->retainedInTable('retained_bulletins')
            ->forModel($this->bulletin)
            ->aliasedAs('bulletin')
            ->primaryForeignKey('publication_id');
    }

    public function primaryCurrentModel(): PrimaryCurrentModel
    {
        return PrimaryCurrentModel::for($this->publication)->aliasedAs('publication');
    }

    public function dependentCurrentModels(): array
    {
        return [
            DependentCurrentModel::for(Placement::class)
                ->aliasedAs('placement')
                ->primaryForeignKey('publication_id'),
        ];
    }

    public function attributes(): array
    {
        return [
            Attribute::retained('id')->readOnlyBecause('A bulletin keeps its id.'),
            Attribute::retained('publication_id')->readOnlyBecause('A bulletin stays tied to its publication.'),
            Attribute::mapped('title')->asType('character varying(255)')->storedAs('publication', 'title'),
            Attribute::mapped('status')->asType('character varying(255)')->storedAs('publication', 'state'),
            Attribute::mapped('review_status')->asType('character varying(255)')->storedAs('publication', 'review'),
            Attribute::mapped('slot')->asType('character varying(255)')->storedAs('placement', 'slot'),
        ];
    }

    public function unrepresentableStates(): array
    {
        return [];
    }
}
