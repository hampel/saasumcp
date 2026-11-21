<?php

declare(strict_types=1);

namespace App\Support;

use PhpCollective\Toml\Exception\ParseException;
use PhpCollective\Toml\Toml;

/**
 * The Saasu files this server may read, and the login for each: the inventory file.
 *
 * The file is TOML, and HOLDS A PASSWORD FOR EVERY FILE, as xfmcp's holds an API key for every
 * forum - one file to render and protect, rather than settings in one place and their logins in
 * another. So nothing read from it is ever quoted back: not a parse error, not an unknown setting's
 * value. files.toml.example is its template. Every top-level table is a file, named by its key,
 * which every tool takes.
 *
 * READ AGAIN WHENEVER IT CHANGES. The server is one process for the life of a client's session,
 * so a parse kept for the life of the process would hide a newly added file until the client
 * reconnected - found in xfmcp, whose inventory this follows. The modification time and size are
 * the test.
 *
 * Everything is checked when the file is read, and an entry that cannot be used is an exception
 * naming what is wrong, not a file with a hole in it.
 */
class FileInventory
{
    private const KEYS = ['name', 'file_id', 'daily_budget', 'username', 'password'];

    /** @var array<string, SaasuFile>|null */
    private ?array $files = null;

    private ?string $signature = null;

    public function path(): string
    {
        return (string) config('saasumcp.files_path');
    }

    /**
     * @return array<string, SaasuFile>
     *
     * @throws FileInventoryException
     */
    public function all(): array
    {
        $signature = $this->signature();

        if ($this->files === null || $signature !== $this->signature) {
            $this->files = $this->load($this->raw());
            $this->signature = $signature;
        }

        return $this->files;
    }

    /** @throws FileInventoryException */
    public function get(string $key): SaasuFile
    {
        $files = $this->all();

        return $files[$key] ?? throw new FileInventoryException(sprintf(
            'there is no Saasu file called "%s"%s',
            $key,
            $files === [] ? '' : ' - the files are '.implode(', ', array_keys($files)),
        ));
    }

    /**
     * The decoded file. A test overrides this, so that the suite never reads a real inventory.
     *
     * @return array<mixed>
     *
     * @throws FileInventoryException
     */
    public function raw(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            throw new FileInventoryException('the file inventory does not exist: '.$path);
        }

        if (! is_readable($path)) {
            throw new FileInventoryException('the file inventory cannot be read: '.$path);
        }

        try {
            return Toml::decodeFile($path);
        } catch (ParseException $e) {
            // the line and column only - never the parser's message, which can quote the text
            throw new FileInventoryException(sprintf(
                'the file inventory is not valid TOML%s',
                $e->span !== null ? ', at line '.$e->span->line.', column '.$e->span->column : '',
            ));
        }
    }

    private function signature(): string
    {
        $path = $this->path();

        clearstatcache(true, $path);

        return is_file($path) ? filemtime($path).':'.filesize($path) : 'missing';
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, SaasuFile>
     *
     * @throws FileInventoryException
     */
    private function load(array $data): array
    {
        if ($data === []) {
            throw new FileInventoryException('the file inventory describes no file - add a [<key>] table');
        }

        $files = [];
        $ids = [];

        foreach ($data as $key => $row) {
            $key = (string) $key;

            if (! is_array($row) || array_is_list($row)) {
                throw new FileInventoryException('"'.$key.'" at the top of the file inventory is not a file - every setting goes under a [<key>] table');
            }

            $file = $this->file($key, $row);

            // Saasu's quota is the file's, so two keys for one file would each get a whole budget
            if (isset($ids[$file->fileId])) {
                throw new FileInventoryException(sprintf('"%s" and "%s" are the same Saasu file, %d - one entry per file, so it has one budget', $ids[$file->fileId], $key, $file->fileId));
            }

            $ids[$file->fileId] = $key;
            $files[$key] = $file;
        }

        return $files;
    }

    /**
     * @param  array<mixed>  $row
     *
     * @throws FileInventoryException
     */
    private function file(string $key, array $row): SaasuFile
    {
        if (preg_match('/^[a-z0-9]+$/', $key) !== 1) {
            throw new FileInventoryException('file "'.$key.'": a key is lowercase letters and digits only - it is the `file` every tool takes');
        }

        $fail = static fn (string $what): FileInventoryException => new FileInventoryException('file "'.$key.'": '.$what);

        foreach (array_keys($row) as $setting) {
            if (! in_array($setting, self::KEYS, true)) {
                throw $fail('"'.$setting.'" is not a setting - a file has '.implode(', ', self::KEYS));
            }
        }

        $name = $row['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw $fail('name must be the business the file belongs to');
        }

        $fileId = $row['file_id'] ?? null;

        if (! is_int($fileId) || $fileId < 1) {
            throw $fail('file_id must be a positive integer, the Saasu file id');
        }

        $budget = $row['daily_budget'] ?? (int) config('saasumcp.daily_budget');

        if (! is_int($budget) || $budget < 1) {
            throw $fail('daily_budget must be a positive integer, or left out for the default');
        }

        $username = $row['username'] ?? null;
        $password = $row['password'] ?? null;

        foreach (['username' => $username, 'password' => $password] as $setting => $value) {
            if ($value !== null && (! is_string($value) || trim($value) === '')) {
                throw $fail($setting.' must be text, or left out until the file has a login');
            }
        }

        // half a login is a typo, not a file waiting for one
        if (($username === null) !== ($password === null)) {
            throw $fail($username === null ? 'has a password and no username' : 'has a username and no password');
        }

        return new SaasuFile($key, trim($name), $fileId, $budget, $username, $password);
    }
}
