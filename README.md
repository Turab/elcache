## What is Elcache?
It is a very simple file based key-value cache in PHP

## How to use it?
Just include elcache.php in your project and add namespace shortcut (optional)

```php
use Ellibs\Elcache\Cache;
include_once 'elcache/elcache.php';
```

Then initialize it:

```php
$options = array(
  'path' => '/path/to/store/the/cache/file', // Default is /tmp
  'ttl' => 3600, // Default expiry in seconds. Default is 3600 (1 hour)
  'context' => 'default', // Each context will have its own independent cache file
  'max_buffer' => 4096, // Maximum allowed size for the cache, in Kibibytes
);

Cache::init($options);
```
> [!IMPORTANT]
> You can initialize the cache only once in the program. Any later calls to init() will have no effect, unless you close the cache handle with `Cache::close()` to re-initialize.

Now you can store or retrieve data to and from cache like:

```php
// Set value with default expiry
Cache::set('name', 'John'); // "name" will be cached for one hour

// Set value with custom expiry
Cache::set('surname', 'Doe', 300); // This cache will expire after 5 minutes

// Setting null value for the key or setting expiry to zero or to a negative number,
// that is effectively revoking cache of that key
Cache::set('name'); // These all will effectively revoke "name" from cache
Cache::set('name', null);
Cache::set('name', 'Dummy', 0);
Cache::set('name', 'Purgatory', -1);

// Or revoke a key at will when necessary, before it expired
Cache::revoke('name'); // Now name is not cached anymore

// Get a cached value
// Non-existent or expired keys will return null
Cache::get('name'); // Returns the cached value if it's not expired

// If you need the expiry time of the key, you can retrieve it together with the value
// This way it will return an array whose first element is the value and second is the expiry time in timestamp
Cache::get('name', true); // Returns [value, expiretime] // Expire time is timestamp, not the remaining seconds
```

### What happens if a key is `set()` again, before it expired?

Nothing special. It will be treated as if it is being cached for the first time. (I.e. resetting the expire time to the new value or revoke if the new value is null or the new expiry is lower than 1.) In this case, expiry is not inremental.

```php
Cache::set('name', 'John', 60); // Cache for 1 minute
sleep(45); // It has 15 seconds left until it expires
Cache::set('name', 'John', 60); // Now it has 1 minute again and NOT 60+15 = 75 seconds.
```

So if you need to prolong the cache time rather than resetting its expiry, just do it manually like:

```php
list($name, $expiry) = Cache::get('name', true);
Cache::set('name', $name, $expiry - time() + 60); // Prolong the remaining cache ttl by 1 minute.
```

### Can I compare a value from the cache?

```php
// Check to see if the cached value matches the provided value
// Check is loose and case-sensitive
Cache::check('name', 'John'); // Returns true if the cached "name" is also John and is not expired; false otherwise.

// To make a strict check (i.e. check also data type)
Cache::check('age', 45, true); // It will return true only if cached age is 45 AND is integer. So "45" (string) will return false.

// This is actually nothing more than a mere shorthand for:
$cached_value = Cache::get('name');
if ($cached_value == 'John') // Or $cached_value === 'John' for strict checking.
  $check = true;
```

### What can be stored in cache?

Anything that is serializable by PHP can be stored in cache. It is best to use with scalar values only but you can also store full arrays in cache too.

```php
$user = array(
  'name' => 'John Doe',
  'age' => 45,
);

Cache::set('user', $user);
```

You can also store serializable objects in cache but it's not recommended if you really don't need it and if you really know what to expect from the object you want to cache. Because most of the objects are not serializable and this will make error handling a pain. Needless to say you cannot cache resources. (Like handlers, streams, database connections etc.)

Since you can store non-scalar values like booleans for example, you should be careful when performing checks on data. For example if a key is stored boolean `false` but it expired, it will return `null` which can evaluate to `false` as if the cache never expires for that key or integer `0` value would evaluate to `false` and may mislead you to cause wrong behaviour in your program. In these cases, always make a strict check. Furthermore, a `null` value cannot be stored in a key since it doesn't make sense as per this behaviour and setting `null` effectively runs revoking procedure for the given key.

### How to purge expired cache?

It naturally purges expired cache without any interaction from you. Nevertheless, shall you need to purge the expired keys manually at a point, you can do so like:

```php
// Purge expired keys (not really necessary)
Cache::purge_expired();
```
You can also run it as `Cache::purge_expired(true);` to trigger writing the cache file after purging expired keys. (Which is also unnecessary if you don't need to for a special reason. Because cache file is written only when cache is updated.)

### How to purge all cache?

If for a reason you need to purge all the cache at once, you can do so like:

```php
// Purge all cache and write an empty cache file at the end of runtime
Cache::purge_all();

// Or remove the cache file altogether, without waiting script to end
// (Nevertheless, you can't reach any cached value anymore after purging, even if writing is not triggered.)
Cache::purge_all(true); // Not only purging cached data but also removing the cache file, which will be re-created after cache is updated again.
```

### How to trigger writing the cache file?

Elcache automatically writes the cache file only once in the program lifetime and only if the cache data has updated. So you don't have to manually interfere. This ensures limited I/O for performance. But shall you need to trigger writing the cache file during lifetime, you can do so by:

```php
// Write only if cache is updated
Cache::write();

// Force writing the cache file
Cache::write(true);
```

### Is cached data dependant on session or user?

No. Cached data is dependant only on the cache file and expiry time. So the cache will live through sessions of different users until it expires and as long as the cache file stays at the same place (and Cache is initialized from that same place always).

### What happens if cache file is deleted or corrupted by another process?

There is no harm in that; but your cached data will be gone if it is deleted at a point where Elcache wasn't running and not updated during that time. So you better not interfere with the file manually or let other processes write to it. You should know what to do when a cached data is expired. Because it will just be considered expired if the file is not there or its content is corrupted.

### Is this production ready?

Basically yes. But it is merely a basic read/write operation on the disk. So for large and/or professional projects, you should consider more performant and reliable methods of caching like variants of Memcache, Redis etc.
