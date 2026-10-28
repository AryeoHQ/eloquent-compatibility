<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\TestCase;

#[CoversClass(ModelClass::class)]
final class ModelClassTest extends TestCase
{
    /**
     * @param  class-string<Model>  $model
     */
    #[Test]
    #[TestWith(['Tests\Fixtures\Support\Workbench\Gadget'])]
    #[TestWith(['\Tests\Fixtures\Support\Workbench\Gadget'])]
    #[TestWith(['tests\fixtures\support\workbench\gadget'])]
    #[TestWith(['\TESTS\FIXTURES\SUPPORT\WORKBENCH\GADGET'])]
    public function it_names_a_loaded_model_by_its_declared_class_name(string $model): void
    {
        $this->assertTrue(class_exists(Gadget::class));

        $this->assertSame('Tests\Fixtures\Support\Workbench\Gadget', ModelClass::canonical($model));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[Test]
    #[TestWith(['Tests\Fixtures\Support\Workbench\Nothing'])]
    #[TestWith(['\Tests\Fixtures\Support\Workbench\Nothing'])]
    public function it_keeps_a_name_that_loads_no_class(string $model): void
    {
        $this->assertSame($model, ModelClass::canonical($model));
    }
}
