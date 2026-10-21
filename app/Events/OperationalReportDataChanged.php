<?php

namespace App\Events;

final readonly class OperationalReportDataChanged
{
    public function __construct(public int $organizationId) {}
}
