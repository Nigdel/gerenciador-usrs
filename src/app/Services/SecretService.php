<?php

namespace App\Services;

use RuntimeException;

/**
 * Service to access application secrets safely.
 *
 * All secret values must be provided via Docker secrets / environment variables.
 * No default values are hard‑coded; if a secret is missing the service throws.
 */
class SecretService
{
    /**
     * Get the Laravel APP_KEY.
     *
     * @throws RuntimeException if the key is not set.
     */
    public function getAppKey(): string
    {
        $key = config('app.key');
        if (empty($key)) {
            throw new RuntimeException('APP_KEY is not set. Provide it via Docker secret or env var.');
        }

        return $key;
    }

    /**
     * Get the MySQL root password.
     */
    public function getMysqlRootPassword(): string
    {
        $pwd = config('database.connections.mysql.password');
        if (empty($pwd)) {
            throw new RuntimeException('DB_PASSWORD is not set.');
        }

        return $pwd;
    }

    /**
     * Get the MySQL user password.
     */
    public function getMysqlUserPassword(): string
    {
        $pwd = config('database.connections.mysql.password');
        if (empty($pwd)) {
            throw new RuntimeException('DB_PASSWORD is not set.');
        }

        return $pwd;
    }
}
