# Max Mind Helpers
This library utilizes the free [Max Mind GeoLite2 Database](https://dev.maxmind.com/geoip/geoip2/geolite2/#IP_Geolocation_Usage) to perform IP => geolocation queries. The package downloads the MaxMind library to your local filesystem, so you can be sure that the client's IP will never leave your server.

The package consists of two parts. The first one is the "Downloader" which simplifies the process of "getting" the database and the "Reader" that is a wrapper around Maxmind's implementation. The reader can also cache queries to save performance using a PSR-16 SimpleCache\CacheInterface

## Installation
Install this package using Composer:

```
composer require labor-digital/max-mind-helpers
```

## Usage
Download the database:
```php
<?php
use LaborDigital\MaxMindHelpers\Downloader;

// This should probably run in a cron job once a day!
$licenseKey = "YOUR LICENSE KEY";
$path = '/path/to/a/writable/directory/to/store/database';
$downloader = new Downloader($licenseKey, $path, new \GuzzleHttp\Client()); 

// This will check if an update is available based on the md5 hash of the local database
// and download the (~40MB) file only if required
$downloader->doDownloadIfRequired();

// Optional
// Always triggers a download ignoring all requirements.
$forceDownload = true;
if($forceDownload) $downloader->download();

// Get path to library file
$libraryFile = $downloader->getLibraryFile();
```

Use the database:
```php
<?php
use LaborDigital\MaxMindHelpers\Reader;

// Define the path to the stored database
$path = '/path/to/a/writable/directory/to/store/database/library.mmdb';

// Get the reader
$reader = new Reader(new \MaxMind\Db\Reader($path));

// Optional
// Use the PSR-16 Cache interface to cache queries for faster lookups
$cache = new AwesomeCache(); // A class that implements \Psr\SimpleCache\CacheInterface
$reader = new Reader(new \MaxMind\Db\Reader($path), $cache);

// Get the geolocation of an ip
$location = $reader->getLocation('129.200.121.12');
// Result ['latitude' => 0.0, 'longitude' => 0.0];
// Result if query failed: NULL

// Get the geolocation of the current client's ip
$location = $reader->getLocation();
// Result's as above. If you use this at 127.0.0.1 the result will always be NULL

// Get additional information of an ip
$info = $reader->getInformation('129.200.121.12');
```

## Further reading

* https://github.com/maxmind/MaxMind-DB-Reader-php
* https://dev.maxmind.com/geoip/geoip2/geolite2/#IP_Geolocation_Usage

## Postcardware
You're free to use this package, but if it makes it to your production environment, we highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using.

Our address is: LABOR.digital - Fischtorplatz 21 - 55116 Mainz, Germany.

We publish all received postcards on our [company website](https://labor.digital). 
