<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One Saasu file this server may read, as the inventory and the environment describe it.
 */
final readonly class SaasuFile
{
    public function __construct(
        public string $key,
        public string $name,
        public int $fileId,
        public int $dailyBudget,
        public ?string $username,
        #[\SensitiveParameter] private ?string $password,
    ) {}

    public function hasCredentials(): bool
    {
        return $this->username !== null && $this->password !== null;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    /** The saasu-api-laravel connection this file is registered under. */
    public function connection(): string
    {
        return 'saasumcp-'.$this->key;
    }

    /** The environment variable each credential is read from. */
    public function variable(string $credential): string
    {
        return 'SAASUMCP_'.strtoupper($this->key).'_'.strtoupper($credential);
    }

    /**
     * What list-files shows. Never the username: it is half a login, and the answer goes into a
     * conversation.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'file' => $this->key,
            'name' => $this->name,
            'file_id' => $this->fileId,
            'daily_budget' => $this->dailyBudget,
            'credentials_configured' => $this->hasCredentials(),
        ];
    }
}
