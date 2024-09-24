<?php
/*
	Author: Turab Garip
	https://github.com/Turab
	Version: 1.0.2
	License: GNU GPL v3
 */

namespace Ellibs\Elcache;

use Exception;

// Maybe later make a layer and move different types of caches to other files?
class FileCache {

    private string $path;
    private array $options;
    private array $data;
    private string $hash;
    private float $init_time;
    private string $context;

    /**
     * @throws Exception
     */
    public function __construct(array $options) {

        $this->options = $options;
        $this->init_time = microtime(true);
        $this->path = $this->options['path'] . '/elcache.' . $this->options['context'] . '.php';

        if (!is_writable($this->options['path']))
            throw new Exception('Cache path is not writable!');

        // Create an empty cache file if it doesn't exist
        if (!file_exists($this->path)) {
            $init_data = 'a:0:{}'; // Empty array
            $this->hash = crc32($init_data);
            $this->data = [];
            file_put_contents($this->path, '<?php /* ' . $init_data . ' */ ?>');
            return;
        }

        list($this->data, $this->hash) = $this->retrieve_file($this->path);
    }

    private function retrieve_file($file): array {
        $data = file_get_contents($file);
        $data = substr($data, 9, -6);
        $hash = crc32($data);
        $data = unserialize($data);
        // If data is corrupt, fall back to an empty array
        return array(is_array($data) ? $data : [], $hash);
    }

    public function set(string $key, $value, int $ttl): void {
        $this->data[$key] = array($value, $ttl);
    }

    public function get(?string $key = null): mixed {
        return $this->data[$key] ?? null;
    }

    public function revoke(string $key): void {
        unset($this->data[$key]);
    }

    /**
     * @throws Exception
     */
    public function purge_expired($then_write = false): void {
        $now = time();
        foreach ($this->data as $key => $cache) {
            list (, $ttl) = $cache;
            if ($now > $ttl)
                $this->revoke($key);
        }
        if ($then_write)
            $this->write();
    }

    /**
     * @throws Exception
     */
    public function purge_all(bool $hard = false): void {
        $this->data = [];
        $hard ? unlink($this->path) : $this->write(true);
    }

    /**
     * @throws Exception
     */
    public function write(bool $force = false): void {
        $size = $this->options['max_buffer'] * 1024;
        $data = serialize($this->data);

        if (strlen($data) > $size)
            throw new Exception('Cache data is longer than it is allowed to be!
				Either increase the allowed size or revoke keys more occasionally.');

        $hash = crc32($data);
        // Write the file only if it's forced or cache is updated
        if ($force || $hash != $this->hash) {
            $this->hash = $hash;
            file_put_contents($this->path, '<?php /* ' . $data . ' */ ?>');
        }
    }

    /**
     * @throws Exception
     */
    public function __destruct() {
        // Check if there were other inits that might have modified the cache
        if (filemtime($this->path) > $this->init_time) {
            // Re-read the file, because it is modified by another init during our runtime
            list($addition, ) = $this->retrieve_file($this->path);
            $this->data = array_merge($addition, $this->data);
        }
        $this->write();
    }
}

//TODO: Add context support.
//(Like system cache, user specific cache etc.)
// Context can be given during init and maybe multiple inits should be possible for this.
// set_context() and get_context() methods for the purpose?

// TODO: Make a garbage collector.
// Store meta data of different cache files in a main cache with a special context.
// Then watch and remove if a specific file is not being updated for a long time.
class Cache {

    private static ?FileCache $cache = null;
    private static array $options = array(
        // Path to store the cache files in
        'path' => '/tmp',
        // Default context (Each context will have its own cache file)
        'context' => 'default',
        // Maximum allowed size for the cache, in Kibibytes
        'max_buffer' => 4096,
        // Default expiry (time-to-live) is 1 hour
        'ttl' => 3600
    );

    /**
     * Initializes the cache.
     * It is mandatory to initialize to be able to use the cache.
     * Cache can be initialized only once for each context.
     * Each context has their own cache file.
     *
     * @param array $options The options to initialize the cache with. If not set, default options will be used.
     *
     * @throws Exception
     */
    public static function init(array $options = []): void {
        if (self::$cache === null) {
            self::$options = array_merge(self::$options, $options);
            self::$cache = new FileCache(self::$options);
        }
        self::purge_expired();
    }

    /**
     * Retrieves the stored value of a key from the cache.
     *
     * @param string $key Key to retrieve the value of
     * @param bool $with_ttl Whether to retrieve only the value or also the ttl
     *
     * @return array|mixed|null Returns null if the key is expired or non-existent.
     * Returns array if $with_ttl is set to true; of which the first element is the stored value of the key,
     * and the second element is the timestamp indicating when this key will expire.
     * Otherwise, returns anything that is stored.
     */
    public static function get(string $key, bool $with_ttl = false): mixed {
        $cache = self::$cache->get($key);
        if ($cache !== null) {
            list($value, $ttl) = $cache;
            // Return with expiry information or only the value
            if ($ttl > time())
                return !$with_ttl ? $value : $cache;
            // If it is expired, don't return but rather destroy
            self::revoke($key);
        }
        return !$with_ttl ? null : [null, 0];
    }

    /**
     * Stores a key-value pair in the cache.
     *
     * @param string $key The key to set
     * @param mixed $value The value to store. If the value is null, it effectively revokes the key.
     * @param int|null $ttl Time to live. If not set, the default init value is applied.
     * If $ttl is negative, it effectively revokes the key.
     *
     * @return void
     */
    public static function set(string $key, mixed $value = null, ?int $ttl = null): void {
        if ($ttl === null)
            $ttl = self::get_option('ttl');
        if ($ttl > 0 && $value !== null) {
            self::$cache->set($key, $value, $ttl + time());
            return;
        }
        // Non-positive expiry or null value means revoke
        self::revoke($key);
    }

    /**
     * Alias of set()
     * 
     * @param string $key
     * @param mixed|null $value
     * @param int|null $ttl
     * 
     * @return void
     */
    public static function store(string $key, mixed $value = null, ?int $ttl = null): void {
        self::set($key, $value, $ttl);
    }

    /**
     * Pushes an element to a cached array.
     * If the array doesn't exist or the cached key is not an array,
     * then it will create an empty array and push this new element to it.
     * CAUTION: If the cached key is not an array but including other data, it will be wiped out.
     * NOTE: Indexed elements will be updated but indexless elements won't be re-pushed if called more than once,
     * but only ttl will be updated.
     * CAUTION: If the value is not scalar, it might cause unpredictable results (due to how comparison works in PHP)
     * and overwhelm the cache if called repeatedly.
     *
     * @param string $key Cached array
     * @param mixed $value Element value to push
     * @param string|null $index If provided, the element will have this index; numeric indexes will be used otherwise.
     * @param int|null $ttl Time to live (of all the cached array, not only the pushed element)
     *
     * @return void
     */
    public static function push(string $key, mixed $value, ?string $index = null, ?int $ttl = null): void {
        $cache = self::get($key);
        if (!is_array($cache))
            $cache = []; // You might have just overwritten your data, ouch!
        // This element is pushed before?
        if ($index !== null) // Override
            $cache[$index] = $value;
        elseif (!in_array($value, $cache)) // But don't re-push
            $cache[] = $value;
        self::set($key, $cache, $ttl);
    }

    /**
     * Checks a given value of a key against its cached value.
     * NOTE: If the value is not scalar, the comparison could be unpredictable.
     * Check PHP comparison documentation for details.
     *
     * @param string $key The key to check
     * @param mixed $value The value to compare
     * @param bool $strict If true, the values will be compared strictly
     *
     * @return bool True if cached value is equal to the given value, false otherwise
     */
    public static function check(string $key, mixed $value, bool $strict = false): bool {
        $cache = self::get($key);
        return !$strict ? $cache == $value : $cache === $value;
    }

    /**
     * Revokes the value of the given key from the cache.
     *
     * @param string $key The key to revoke
     *
     * @return void
     */
    public static function revoke(string $key): void {
        self::$cache->revoke($key);
    }

    /**
     * Purges the expired keys from cache.
     * This function is meant to be used internally, but it can still be used at will, albeit not very meaningful.
     *
     * @param bool $then_write If set to true, cache will be written after purging
     *
     * @return void
     * @throws Exception
     */
    public static function purge_expired(bool $then_write = false): void {
        self::$cache->purge_expired($then_write);
    }

    /**
     * Purges the cache altogether.
     *
     * @param bool $hard If set to true, not only it purges the live data, but it also removes the cache file
     *
     * @return void
     * @throws Exception
     */
    public static function purge_all(bool $hard = false): void {
        self::$cache->purge_all($hard);
    }

    /**
     * Writes the cache to the file.
     * This function is meant to be used internally, but it can still be used at will.
     * It is automatically called at the end of the runtime to write the cache only once and only if updated.
     *
     * @param bool $force If set to true, the cache file will be written immediately;
     * if set to false, the cache file will be written only if the cache is updated.
     *
     * @return void
     * @throws Exception
     */
    public static function write(bool $force = false): void {
        self::$cache->write($force);
    }

    /**
     * Gets the value of an init setting.
     * There is no set_option() defined because options can't be changed at runtime; but only during init.
     *
     * @param string $option The setting to get the value of.
     *
     * @return mixed|null
     */
    public static function get_option(string $option): mixed {
        return self::$options[$option] ?? null;
    }

    // Kill the cache handler (Maybe for reinitializing with a different context.)

    /**
     * Kills the cache handler to let re-initializing with different options (like with a different context)
     *
     * @return void
     */
    public function close(): void {
        self::$cache = null;
    }
}
