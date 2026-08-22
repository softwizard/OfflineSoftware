<?php

namespace App\Sync\Scopes;

interface ScopeDefinition
{
    public function column(): string;
}
