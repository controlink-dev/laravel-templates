<?php

namespace Controlink\Templates\Support;

use Controlink\Templates\Contracts\EncryptedFieldMap;

final class EmptyEncryptedFieldMap implements EncryptedFieldMap
{
    public function for(string $modelClass): array
    {
        return ['encrypted' => [], 'blind_indexes' => []];
    }
}
