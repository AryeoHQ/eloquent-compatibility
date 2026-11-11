<?php

declare(strict_types=1);

namespace Tests\Fixtures\Tooling\Operations;

use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

final class LegacyInvoice extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'legacy_invoices';

    public static function compatibility(Compatibility $invoice): Compatibility
    {
        return LegacyInvoiceMap::define($invoice);
    }
}
