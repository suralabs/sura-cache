<?php
declare(strict_types=1);

namespace Sura\Cache;

use JetBrains\PhpStorm\Pure;
use Sura;


/**
 * Implements the cache for a application.
 */
class Cache
{
    use Sura\SmartObject;

    /** dependency */
    public const
        PRIORITY = 'priority',
        EXPIRATION = 'expire',
        EXPIRE = 'expire',
        SLIDING = 'sliding',
        TAGS = 'tags',
        FILES = 'files',
        ITEMS = 'items',
        CONSTS = 'consts',
        CALLBACKS = 'callbacks',
        NAMESPACES = 'namespaces',
        ALL = 'all';

    /** @internal */
    public const NAMESPACE_SEPARATOR = "\x00";

    /** @var Storage */
    private $storage;

    /** @var string */
    private string $namespace;

    /**
     * Cache constructor.
     * @param Storage $storage
     * @param string|null $namespace
     */
    public function __construct(Storage $storage, string $namespace = null)
    {
        $this->storage = $storage;
        $this->namespace = $namespace . self::NAMESPACE_SEPARATOR;
    }


    /**
     * Returns cache storage.
     */
    final public function getStorage(): Storage
    {
        return $this->storage;
    }


    /**
     * Returns cache namespace.
     */
    #[Pure] final public function getNamespace(): string
    {
        return (string)substr($this->namespace, 0, -1);
    }


    /**
     * Returns new nested cache object.
     * @param string $namespace
     * @return static
     */
    #[Pure] public function derive(string $namespace): static
    {
        return new static($this->storage, $this->namespace . $namespace);
    }


    /**
     * Reads the specified item from the cache or generate it.
     * @param mixed $key
     * @param callable|null $generator
     * @return mixed
     * @throws \Throwable
     */
    public function load($key, callable $generator = null): mixed
    {
        $storageKey = $this->generateKey($key);
        $data = $this->storage->read($storageKey);
        if ($data === null && $generator) {
            $this->storage->lock($storageKey);
            try {
                $data = $generator(...[&$dependencies]);
            } catch (\Throwable $e) {
                $this->storage->remove($storageKey);
                throw $e;
            }
            $this->save($key, $data, $dependencies);
        }
        return $data;
    }


    /**
     * Reads multiple items from the cache.
     * @param array $keys
     * @param callable|null $generator
     * @return array
     * @throws \Throwable
     */
    public function bulkLoad(array $keys, callable $generator = null): array
    {
        if (count($keys) === 0) {
            return [];
        }
        foreach ($keys as $key) {
            if (!is_scalar($key)) {
                throw new Sura\InvalidArgumentException('Only scalar keys are allowed in bulkLoad()');
            }
        }

        $result = [];
        if (!$this->storage instanceof BulkReader) {
            foreach ($keys as $key) {
                $result[$key] = $this->load(
                    $key,
                    $generator
                        ? function (&$dependencies) use ($key, $generator) {
                        return $generator(...[$key, &$dependencies]);
                    }
                        : null
                );
            }
            return $result;
        }

        $storageKeys = array_map([$this, 'generateKey'], $keys);
        $cacheData = $this->storage->bulkRead($storageKeys);
        foreach ($keys as $i => $key) {
            $storageKey = $storageKeys[$i];
            if (isset($cacheData[$storageKey])) {
                $result[$key] = $cacheData[$storageKey];
            } elseif ($generator) {
                $result[$key] = $this->load($key, function (&$dependencies) use ($key, $generator) {
                    return $generator(...[$key, &$dependencies]);
                });
            } else {
                $result[$key] = null;
            }
        }
        return $result;
    }


    /**
     * Writes item into the cache.
     * Dependencies are:
     * - Cache::PRIORITY => (int) priority
     * - Cache::EXPIRATION => (timestamp) expiration
     * - Cache::SLIDING => (bool) use sliding expiration?
     * - Cache::TAGS => (array) tags
     * - Cache::FILES => (array|string) file names
     * - Cache::ITEMS => (array|string) cache items
     * - Cache::CONSTS => (array|string) cache items
     *
     * @param mixed $key
     * @param mixed $data
     * @param array|null $dependencies
     * @return mixed  value itself
     * @throws \Throwable
     */
    public function save($key, $data, array $dependencies = null): mixed
    {
        $key = $this->generateKey($key);

        if ($data instanceof \Closure) {
            trigger_error(__METHOD__ . '() closure argument is deprecated.', E_USER_WARNING);
            $this->storage->lock($key);
            try {
                $data = $data(...[&$dependencies]);
            } catch (\Throwable $e) {
                $this->storage->remove($key);
                throw $e;
            }
        }

        if ($data === null) {
            $this->storage->remove($key);
            return 'null';
        } else {
            $dependencies = $this->completeDependencies($dependencies);
            if (isset($dependencies[self::EXPIRATION]) && $dependencies[self::EXPIRATION] <= 0) {
                $this->storage->remove($key);
            } else {
                $this->storage->write($key, $data, $dependencies);
            }
            return $data;
        }
    }


    private function completeDependencies(?array $dp): array
    {
        // convert expire into relative amount of seconds
        if (isset($dp[self::EXPIRATION])) {
            $dp[self::EXPIRATION] = Sura\Utils\DateTime::from($dp[self::EXPIRATION])->format('U') - time();
        }

        // make list from TAGS
        if (isset($dp[self::TAGS])) {
            $dp[self::TAGS] = array_values((array)$dp[self::TAGS]);
        }

        // make list from NAMESPACES
        if (isset($dp[self::NAMESPACES])) {
            $dp[self::NAMESPACES] = array_values((array)$dp[self::NAMESPACES]);
        }

        // convert FILES into CALLBACKS
        if (isset($dp[self::FILES])) {
            foreach (array_unique((array)$dp[self::FILES]) as $item) {
                $dp[self::CALLBACKS][] = [[self::class, 'checkFile'], $item, @filemtime($item) ?: null]; // @ - stat may fail
            }
            unset($dp[self::FILES]);
        }

        // add namespaces to items
        if (isset($dp[self::ITEMS])) {
            $dp[self::ITEMS] = array_unique(array_map([$this, 'generateKey'], (array)$dp[self::ITEMS]));
        }

        // convert CONSTS into CALLBACKS
        if (isset($dp[self::CONSTS])) {
            foreach (array_unique((array)$dp[self::CONSTS]) as $item) {
                $dp[self::CALLBACKS][] = [[self::class, 'checkConst'], $item, constant($item)];
            }
            unset($dp[self::CONSTS]);
        }

        if (!is_array($dp)) {
            $dp = [];
        }
        return $dp;
    }


    /**
     * Removes item from the cache.
     * @param mixed $key
     * @throws \Throwable
     */
    public function remove(mixed $key): void
    {
        $this->save($key, null);
    }


    /**
     * Removes items from the cache by conditions.
     * Conditions are:
     * - Cache::PRIORITY => (int) priority
     * - Cache::TAGS => (array) tags
     * - Cache::ALL => true
     * @param array|null $conditions
     */
    public function clean(array $conditions = null): void
    {
        $conditions = (array)$conditions;
        if (isset($conditions[self::TAGS])) {
            $conditions[self::TAGS] = array_values((array)$conditions[self::TAGS]);
        }
        $this->storage->clean($conditions);
    }


    /**
     * Caches results of function/method calls.
     * @param callable $function
     * @return mixed
     * @throws \Throwable
     */
    public function call(callable $function): mixed
    {
        $key = func_get_args();
        if (is_array($function) && is_object($function[0])) {
            $key[0][0] = get_class($function[0]);
        }
        return $this->load($key, function () use ($function, $key) {
            return $function(...array_slice($key, 1));
        });
    }


    /**
     * Caches results of function/method calls.
     * @param callable $function
     * @param array|null $dependencies
     * @return \Closure
     */
    public function wrap(callable $function, array $dependencies = null): \Closure
    {
        return function () use ($function, $dependencies) {
            $key = [$function, $args = func_get_args()];
            if (is_array($function) && is_object($function[0])) {
                $key[0][0] = get_class($function[0]);
            }
            return $this->load($key, function (&$deps) use ($function, $args, $dependencies) {
                $deps = $dependencies;
                return $function(...$args);
            });
        };
    }


    /**
     * Starts the output cache.
     * @param mixed $key
     * @return OutputHelper|null
     * @throws \Throwable
     */
    public function capture(mixed $key): ?OutputHelper
    {
        $data = $this->load($key);
        if ($data === null) {
            return new OutputHelper($this, $key);
        }
        echo $data;
        return null;
    }


    /**
     * @param $key
     * @return OutputHelper|null
     * @throws \Throwable
     * @deprecated  use capture()
     */
    public function start($key): ?OutputHelper
    {
        return $this->capture($key);
    }


    /**
     * Generates internal cache key.
     * @param $key
     * @return string
     */
    protected function generateKey($key): string
    {
        return $this->namespace . md5(is_scalar($key) ? (string)$key : serialize($key));
    }


    /********************* dependency checkers ****************d*g**/


    /**
     * Checks CALLBACKS dependencies.
     * @param array $callbacks
     * @return bool
     */
    public static function checkCallbacks(array $callbacks): bool
    {
        foreach ($callbacks as $callback) {
            if (!array_shift($callback)(...$callback)) {
                return false;
            }
        }
        return true;
    }


    /**
     * Checks CONSTS dependency.
     * @param string $const
     * @param $value
     * @return bool
     */
    private static function checkConst(string $const, $value): bool
    {
        return defined($const) && constant($const) === $value;
    }


    /**
     * Checks FILES dependency.
     * @param string $file
     * @param int|null $time
     * @return bool
     */
    private static function checkFile(string $file, ?int $time): bool
    {
        return filemtime($file) == $time; // @ - stat may fail
    }
}
