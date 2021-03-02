<?php

declare(strict_types=1);

namespace Sura\Cache;


/**
 * Cache storage.
 */
interface Storage
{
    /**
     * Read from cache.
     * @param string $key
     * @return mixed
     */
	public function read(string $key): mixed;

    /**
     * Prevents item reading and writing. Lock is released by write() or remove().
     * @param string $key
     */
	public function lock(string $key): void;

    /**
     * Writes item into the cache.
     * @param string $key
     * @param $data
     * @param array $dependencies
     */
	public function write(string $key, $data, array $dependencies): void;

    /**
     * Removes item from the cache.
     * @param string $key
     */
	public function remove(string $key): void;

    /**
     * Removes items from the cache by conditions.
     * @param array $conditions
     */
	public function clean(array $conditions): void;
}
