<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Compatibility;

final class SubclassImportsEventHook extends ValidModel
{
    use ReplacementEventHook;
}
