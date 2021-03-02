<?php

declare(strict_types=1);

namespace Sura\Cache\Storages;


/**
 * Cache journal provider.
 */
interface Journal
{
    /**
     * Writes entry information into the journal.
     * @param string $key
     * @param array $dependencies
     */
    public function write(string $key, array $dependencies): void;

    /**
     * Cleans entries from journal.
     * @param array $conditions
     * @return array|null of removed items or null when performing a full cleanup
     */
    public function clean(array $conditions): ?array;
}
