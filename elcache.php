<?php
/*
	Author: Turab Garip
	https://github.com/Turab
	Version: 1.0.1
	License: GNU GPL v3
 */

namespace Ellibs\Elcache;

use Exception;

// Maybe later make a layer and move different types of caches to other files?
class FileCache {

	private $path;
	private $options;
	private $data;
	private $hash;
	private $init_time;
	private $context;

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

	public function get(?string $key = null) {
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
	public function purge_all($hard = false): void {
		$this->data = [];
		$hard ? unlink($this->path) : $this->write(true);
	}

	public function write($force = false): void {
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

	private static $cache;
	private static $options = array(
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
	 * @throws Exception
	 */
	public static function init(array $options = []) {
		if (self::$cache === null) {
			self::$options = array_merge(self::$options, $options);
			self::$cache = new FileCache(self::$options);
		}
		self::purge_expired();
	}

	public static function get(string $key, $with_ttl = false) {
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

	public static function set(string $key, $value = null, ?int $ttl = null) {
		if ($ttl === null)
			$ttl = self::get_option('ttl');
		if ($ttl > 0 && $value !== null) {
			self::$cache->set($key, $value, $ttl + time());
			return;
		}
		// Non-positive expiry or null value means revoke
		self::revoke($key);
	}
	
	public static function check(string $key, $value, $strict = false): bool {
		$cache = self::get($key);
		return !$strict ? $cache == $value : $cache === $value;
	}

	public static function revoke(string $key) {
		self::$cache->revoke($key);
	}

	public static function purge_expired($then_write = false) {
		self::$cache->purge_expired($then_write);
	}

	public static function purge_all($hard = false) {
		return self::$cache->purge_all($hard);
	}

	public static function write($force = false) {
		self::$cache->write($force);
	}

	// There is no set_option() defined because options can't be changed at runtime; but only during init.
	public static function get_option(string $option) {
		return self::$options[$option] ?? null;
	}

	// Kill the cache handler (Maybe for reinitializing with a different context.)
	public function close() {
		self::$cache = null;
	}
}
