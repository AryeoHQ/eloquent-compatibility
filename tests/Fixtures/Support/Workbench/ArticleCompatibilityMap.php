<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Workbench;

use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;

final class ArticleCompatibilityMap implements CompatibilityMap
{
    public function name(): string
    {
        return 'articles';
    }

    public function previousModel(): PreviousModel
    {
        return PreviousModel::servedAsView('articles')
            ->retainedInTable('retained_articles')
            ->forModel(Article::class)
            ->aliasedAs('article')
            ->primaryForeignKey('publication_id');
    }

    public function primaryCurrentModel(): PrimaryCurrentModel
    {
        return PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication');
    }

    public function dependentCurrentModels(): array
    {
        return [];
    }

    public function attributes(): array
    {
        return [
            Attribute::retained('id')->readOnlyBecause('An article keeps its id.'),
            Attribute::retained('publication_id')->readOnlyBecause('An article stays tied to its publication.'),
            Attribute::mapped('title')->asType('character varying(255)')->storedAs('publication', 'title'),
            Attribute::mapped('status')->asType('character varying(255)')->storedAs('publication', 'state'),
            Attribute::mapped('published_at')->asType('timestamp(0) without time zone')->storedAs('publication', 'published_at'),
            Attribute::mapped('featured_at')->asType('timestamp(0) without time zone')->storedAs('publication', 'featured_at'),
            Attribute::mapped('created_at')->asType('timestamp(0) without time zone')->storedAs('publication', 'created_at'),
            Attribute::mapped('updated_at')->asType('timestamp(0) without time zone')->storedAs('publication', 'updated_at'),
        ];
    }

    public function unrepresentableStates(): array
    {
        return [];
    }
}
