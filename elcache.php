<?php
/*
	Author: Turab Garip
	https://github.com/Turab
	License: GNU GPL v3
 */

namespace Ellibs\Elcache;

// Maybe later make a layer and move different types of caches to other files?
class FileCache {

	private $path;
	private $options;
	private $data;
	private $hash;

	public function __construct(array $options) {

		$this->options = $options;
		$this->path = $this->options['path'] . '/elcache.php';

		if (!is_writable($this->options['path'])) {
			throw new \Exception('Cache path is not writable!');
		}

		// Create an empty cache file if it doesn't exist
		if (!file_exists($this->path)) {
			$init_data = 'a:0:{}'; // Empty array
			$this->hash = crc32($init_data);
			$this->data = [];
			file_put_contents($this->path, '<?php /* ' . $init_data . ' */ ?>');
			return;
		}

		$data = file_get_contents($this->path);
		$data = substr($data, 9, -6);
		$this->hash = crc32($data);
		$data = unserialize($data);
		$this->data = is_array($data) ? $data : []; // If data is corrupt, fall back to an empty array
	}

	public function set(string $key, $value, int $expiry): void {
		$this->data[$key] = array($value, $expiry);
	}

	public function get(?string $key = null) {
		return $this->data[$key] ?? null;
	}

	public function revoke(string $key): void {
		unset($this->data[$key]);
	}

	public function purge_expired($then_write = false): void {
		$now = time();
		foreach ($this->data as $key => $cache) {
			list (, $expiry) = $cache;
			if ($now > $expiry)
				$this->revoke($key);
		}
		if ($then_write)
			$this->write();
	}

	public function purge_all($hard = false): void {
		$this->data = [];
		$hard ? unlink($this->path) : $this->write(true);
	}

	public function write($force = false): void {
		$data = serialize($this->data);
		$hash = crc32($data);
		// Write the file only if it's forced or cache is updated
		if ($force || $hash != $this->hash) {
			$this->hash = $hash;
			file_put_contents($this->path, '<?php /* ' . $data . ' */ ?>');
		}
	}

	public function __destruct() {
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
		// Path to store the cache file in
		'path' => '/tmp',
		// Default expiry is 1 hour
		'default_expiry' => 3600
	);

	public static function init(?array $options = []) {
		if (self::$cache === null) {
			self::$options = array_merge(self::$options, $options);
			self::$cache = new FileCache(self::$options);
		}
		self::purge_expired();
	}

	public static function get(string $key, $with_expiry = false) {
		$cache = self::$cache->get($key);
		if ($cache !== null) {
			list($value, $expiry) = $cache;
			// Return with expiry information or only the value
			if ($expiry > time())
				return !$with_expiry ? $value : $cache;
			// If it is expired, don't return but rather destroy
			self::revoke($key);
		}
		return !$with_expiry ? null : [null, 0];
	}

	public static function set(string $key, $value = null, ?int $expiry = null) {
		if ($expiry === null)
			$expiry = self::get_option('default_expiry');
		// Non-positive expiry or null value means revoke
		if ($expiry < 1 || $value === null) {
			self::revoke($key);
			return;
		}
		$expiry += time();
		self::$cache->set($key, $value, $expiry);
	}
	
	public static function check(string $key, $value, $strict = false): bool {
		return !$strict ? self::get($key) == $value : self::get($key) === $value;
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
}
