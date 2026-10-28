<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

final class SubclassImportsWriteHook extends ValidModel
{
    use ReplacementInsertHook;
}
