<?php

namespace App\Contracts;

use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;

interface SubsystemConnectionInterface
{
    public function testConnection(Subsystem $subsystem): SubsystemOperationResult;
}