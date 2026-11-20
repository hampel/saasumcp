<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The state directory: cached Saasu tokens, the shared throttle, each file's request count, and by
 * default the file inventory.
 *
 * Created 0700 before anything is written into it, because the cached tokens are credentials and
 * the file cache store cannot protect them itself. Laravel's FileStore applies its `permission`
 * setting to the directories it creates as well as to the files, so 0600 there makes every cache
 * directory impossible to enter - measured by saasusync on 2026-11-18, "Permission denied" on the
 * first token write. What is inside a 0700 directory is unreachable to anyone else whatever its own
 * mode.
 */
final class StateDirectory
{
    public static function path(): string
    {
        return (string) config('saasumcp.state_path');
    }

    public static function ensure(): void
    {
        $path = self::path();

        if (is_dir($path)) {
            return;
        }

        if (! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new \RuntimeException('cannot create the state directory '.$path);
        }

        chmod($path, 0700);
    }
}
