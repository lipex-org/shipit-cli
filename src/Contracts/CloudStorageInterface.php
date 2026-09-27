<?php

declare(strict_types=1);

namespace ShipIt\Contracts;

interface CloudStorageInterface
{
    /**
     * Uploads a local file to cloud object storage.
     *
     * @param string $localFilePath Absolute path to local file
     * @param string $remoteKey Destination key/path in bucket
     * @return bool True on success
     * @throws \RuntimeException on upload failure
     */
    public function upload(string $localFilePath, string $remoteKey): bool;

    /**
     * Downloads a remote object from cloud storage to a local file.
     *
     * @param string $remoteKey Key/path in bucket
     * @param string $localDestinationPath Absolute path where file should be saved
     * @return bool True on success
     * @throws \RuntimeException on download failure
     */
    public function download(string $remoteKey, string $localDestinationPath): bool;

    /**
     * Lists objects under a prefix.
     *
     * @param string $prefix Optional key prefix to filter objects
     * @return array Array of objects with metadata: [['key' => string, 'size' => int, 'last_modified' => string], ...]
     */
    public function listObjects(string $prefix = ''): array;

    /**
     * Deletes a remote object from cloud storage.
     *
     * @param string $remoteKey Key/path in bucket
     * @return bool True on success
     */
    public function delete(string $remoteKey): bool;

    /**
     * Checks if a remote object exists.
     *
     * @param string $remoteKey Key/path in bucket
     * @return bool
     */
    public function exists(string $remoteKey): bool;

    /**
     * Returns driver name (e.g. 'r2', 's3').
     *
     * @return string
     */
    public function getDriverName(): string;
}
