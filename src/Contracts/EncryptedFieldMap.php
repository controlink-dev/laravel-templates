<?php

namespace Controlink\Templates\Contracts;

interface EncryptedFieldMap
{
    /** @return array{encrypted: list<string>, blind_indexes: list<string>} */
    public function for(string $modelClass): array;
}
