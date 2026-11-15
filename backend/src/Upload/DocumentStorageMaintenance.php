<?php

declare(strict_types=1);

namespace Kavoryn\Upload;

interface DocumentStorageMaintenance
{
    /**
     * Delete objects older than the cutoff that are not referenced by the
     * document registry. Implementations must only accept validated storage keys.
     *
     * @param list<string> $referencedStorageKeys
     */
    public function deleteUnreferencedOlderThan(array $referencedStorageKeys, int $olderThanEpoch): int;
}
