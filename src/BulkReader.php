<?php

declare(strict_types=1);

namespace Sura\Cache;


/**
 * Cache storage with a bulk read support.
 */
interface BulkReader
{
    /**
     * Reads from cache in bulk.
     * @param array $keys
     * @return array key => value pairs, missing items are omitted
     */
	public function bulkRead(array $keys): array;
}
