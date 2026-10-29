<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(InstallerAction::class)]
final class InstallerActionTest extends TestCase
{
    #[Test]
    public function it_names_each_action_by_the_installer_method_it_lists(): void
    {
        $this->assertSame(['Install' => 'install', 'Recompile' => 'recompile', 'Uninstall' => 'uninstall'], array_column(InstallerAction::cases(), 'value', 'name'));
    }
}
