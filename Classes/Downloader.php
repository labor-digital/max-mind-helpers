<?php
/**
 * User: Martin Neundorfer
 * Date: 06.06.2018
 * Time: 17:48
 * Vendor: LABOR.digital
 */

namespace LaborDigital\MaxMindHelpers;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use LaborDigital\MaxMindHelpers\Exception\DownloadFailedException;
use LaborDigital\MaxMindHelpers\Exception\InvalidStorageDirectoryException;
use PharData;

/**
 * Class Downloader
 *
 * Helper to download the latest version of the max mind library to the local filesystem
 *
 * @package Labor\MaxmindGeoIpHelpers
 */
class Downloader {
	
	/**
	 * The license key of the max mind account we have to use in order to download the database information
	 * @var string
	 */
	protected $licenseKey;
	
	/**
	 * Contains the absolute file path to the directory where the downloader should store it's work data
	 * @var string
	 */
	protected $directory;
	
	/**
	 * Http client to execute the download with
	 * @var \GuzzleHttp\ClientInterface
	 */
	protected $client;
	
	/**
	 * General configuration
	 * @var array
	 */
	protected $config = [
		// The url where the mmdb files can be downloaded
		"libaryUrl"     => "https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key={{licenseKey}}&suffix=tar.gz",
		// The url where the mmdb file md5 has can be found to check if a download is required
		"libraryMd5Url" => "https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key={{licenseKey}}&suffix=tar.gz.md5",
	];
	
	/**
	 * Cached data for the current loop
	 * @var array
	 */
	protected $cache = [];
	
	/**
	 * Downloader constructor.
	 *
	 * @param string                      $licenseKey
	 * @param string                      $directory The directory where to store the local copy of the library
	 * @param \GuzzleHttp\ClientInterface $client    The http client to perform the download with
	 *
	 * @throws \LaborDigital\MaxMindHelpers\Exception\InvalidStorageDirectoryException
	 */
	public function __construct(string $licenseKey, string $directory, ClientInterface $client) {
		
		// Validate directory
		if (empty($directory))
			throw new InvalidStorageDirectoryException("The given storage directory is empty");
		if (!file_exists($directory))
			throw new InvalidStorageDirectoryException("The given storage directory: " . $directory . " does not exist!");
		if (!is_dir($directory))
			throw new InvalidStorageDirectoryException("The given storage directory: " . $directory . " is no directory!");
		if (!is_writable($directory))
			throw new InvalidStorageDirectoryException("The given storage directory: " . $directory . " is no writeable!");
		
		// Store values
		$this->licenseKey = $licenseKey;
		$this->directory = realpath($directory) . DIRECTORY_SEPARATOR;
		$this->client = $client;
	}
	
	/**
	 * Helper to overwrite the predefined configuration options distributed with this class.
	 * See $this->config[] for possible $key's
	 *
	 * @param string $key   The option key to overwrite
	 * @param mixed  $value The value to overwrite with
	 *
	 * @return $this
	 */
	public function setConfig(string $key, $value) {
		if (!isset($this->config[$key])) throw new InvalidArgumentException("The given config key: " . $key . " is not valid!");
		$this->config[$key] = $value;
		return $this;
	}
	
	/**
	 * Returns true if the local.md5 file exists in the given directory
	 * @return bool
	 */
	public function hasLocalFile(): bool {
		return file_exists($this->getChecksumFile()) && file_exists($this->getLibraryFile());
	}
	
	/**
	 * Returns the locally stored md5 checksum of the currently active mmdb library
	 * @return string
	 */
	public function getLocalChecksum(): string {
		// Check if we have a cached value
		if (isset($this->cache["localChecksum"])) return $this->cache["localChecksum"];
		// Check if we have the local file
		if (!$this->hasLocalFile()) return 0;
		// Load content to cache
		return $this->cache["localChecksum"] = file_get_contents($this->getChecksumFile());
	}
	
	/**
	 * Downloads the md5 checksum of the MMDB library from the publisher's server
	 * @return string
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \LaborDigital\MaxMindHelpers\Exception\DownloadFailedException
	 */
	public function getForeignChecksum(): string {
		// Check fi we have a cached value
		if (isset($this->cache["foreignChecksum"])) return $this->cache["foreignChecksum"];
		// Request from server
		try {
			$url = $this->prepareFileUrl($this->config["libraryMd5Url"]);
			$response = $this->client->request("get", $url, ["timeout" => 2]);
			$response = $response->getBody()->getContents();
		} catch (RequestException $exception) {
			throw new DownloadFailedException(
				"Could not download the library' md5 hash!", $exception->getCode(), $exception);
		}
		return $this->cache["foreignChecksum"] = $response;
	}
	
	/**
	 * Returns true if we don't have a file local and/or the remote library was updated
	 * @return bool
	 * @throws \LaborDigital\MaxMindHelpers\Exception\DownloadFailedException
	 */
	public function isDownloadRequired(): bool {
		if (!$this->hasLocalFile()) return TRUE;
		if ($this->getLocalChecksum() !== $this->getForeignChecksum()) return TRUE;
		return FALSE;
	}
	
	/**
	 * This method will ALWAYS download the library files from the max mind server and replace the current local copy
	 * with it. It will also perform a cleanup to make sure your filesystem will not clutter up, even if a previous
	 * download failed.
	 *
	 * @return string The name of the downloaded library file for you to use in Reader::class
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \LaborDigital\MaxMindHelpers\Exception\DownloadFailedException
	 */
	public function download() {
		// Remove old fractals
		$this->cleanup();
		
		// Create directory to work with
		$tmpDir = $this->createDownloadTmpDir();
		$tmpFile = $tmpDir . "tmp.tar.gz";
		
		// Request from server
		try {
			$url = $this->prepareFileUrl($this->config["libaryUrl"]);
			$this->client->request("get", $url, ["timeout" => 20, "sink" => $tmpFile]);
		} catch (RequestException $exception) {
			throw new DownloadFailedException(
				"Could not download the library file!", $exception->getCode(), $exception);
		}
		
		// Unpack the temp file
		$this->unpackDownloadFile($tmpFile, $tmpDir);
		
		// Move the library
		$this->moveDownloadedLibrary($tmpDir);
		
		// Write md5 to local file
		if (!file_put_contents($this->getChecksumFile(), $this->getForeignChecksum()))
			throw new DownloadFailedException("Could not write contents of local checksum file");
		unset($this->cache["localChecksum"]);
		
		// Remove download directory
		$this->cleanup();
		
		// Done
		return $this->getLibraryFile();
	}
	
	/**
	 * Similar to download() but it will only perform a download if there is no local copy of the max mind library,
	 * or it detects an update on the max mind server's library file.
	 *
	 * @return bool True if a download was performed, false if not
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \LaborDigital\MaxMindHelpers\Exception\DownloadFailedException
	 */
	public function doDownloadIfRequired(): bool {
		// Check if we have to do the download
		if (!$this->isDownloadRequired()) return FALSE;
		// Do download the library
		$this->download();
		// Done
		return TRUE;
	}
	
	/**
	 * Returns the name of the file where the local library' md5 hash is stored
	 * @return string
	 */
	protected function getChecksumFile(): string {
		return $this->directory . "local.md5";
	}
	
	/**
	 * Returns the name of the library file
	 * @return string
	 */
	public function getLibraryFile(): string {
		return $this->directory . "library.mmdb";
	}
	
	/**
	 * Removes all tmp-dl folders in the current directory
	 */
	protected function cleanup() {
		foreach (glob($this->directory . "tmp-dl-*") as $dir) {
			if (is_dir($dir)) $this->removeDir($dir);
		}
	}
	
	/**
	 * Creates a temporary directory to download the library to
	 * @return string
	 * @throws \LaborDigital\MaxMindHelpers\Exception\DownloadFailedException
	 */
	protected function createDownloadTmpDir(): string {
		$tmpDir = $this->directory . "tmp-dl-" . time() . "-" . rand(0, 99999) . DIRECTORY_SEPARATOR;
		if (!mkdir($tmpDir))
			throw new DownloadFailedException("The temporary download directory: \"" . $tmpDir . "\" could not be created!");
		
		return $tmpDir;
	}
	
	/**
	 * Unpacks the downloaded tar.gz file into the given targetDirectory.
	 *
	 * @param string $filename        The absolute path to the downloaded file
	 * @param string $targetDirectory The absolute path to the directory to extract the directory to
	 *
	 * @throws \LaborDigital\MaxMindHelpers\Exception\DownloadFailedException
	 */
	protected function unpackDownloadFile(string $filename, string $targetDirectory) {
		if (class_exists(PharData::class) && method_exists(PharData::class, "extractTo")) {
			$phar = new PharData($filename);
			$phar->extractTo($targetDirectory, NULL, TRUE);
		} else {
			throw new DownloadFailedException(
				"Could not extract tar.gz file using PharData!"
			);
		}
	}
	
	/**
	 * Finds the downloaded library in the temp directory and copies it into the local path
	 *
	 * @param string $sourceDirectory The temporary directory where the download was extracted to
	 *
	 * @throws \LaborDigital\MaxMindHelpers\Exception\DownloadFailedException
	 */
	protected function moveDownloadedLibrary(string $sourceDirectory) {
		$targetFile = $this->getLibraryFile();
		
		// Find downloaded library
		$sourceFile = reset(glob($sourceDirectory . "*/*.mmdb"));
		if (empty($sourceFile))
			throw new DownloadFailedException("Could not find the extracted libary file to replace the local library!");
		
		// Remove target
		if (file_exists($targetFile) && !unlink($targetFile))
			throw new DownloadFailedException("Could not remove local libary before moving downloaded copy");
		
		// Move source to new target
		if (!rename($sourceFile, $targetFile))
			throw new DownloadFailedException("Error while moving downloaded library to local directory");
		
	}
	
	/**
	 * Helper to recursively delete a directory
	 *
	 * @param string $directory
	 */
	protected function removeDir(string $directory) {
		if (!is_dir($directory)) return;
		$objects = scandir($directory);
		foreach ($objects as $object) {
			if ($object === "." || $object === "..") continue;
			if (is_dir($directory . DIRECTORY_SEPARATOR . $object))
				$this->removeDir($directory . DIRECTORY_SEPARATOR . $object);
			else
				unlink($directory . DIRECTORY_SEPARATOR . $object);
		}
		rmdir($directory);
	}
	
	/**
	 * Prepares a download url by injecting the required placeholders
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	protected function prepareFileUrl(string $url): string {
		return str_replace(["{{yyyymmdd}}", "{{licenseKey}}"],
			[date("Ymd"), $this->licenseKey], $url);
	}
}