<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Saasu\DailyBudget;
use App\Support\FileInventory;
use App\Support\FileInventoryException;
use App\Support\SaasuFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List the Saasu files this server can read. Call this first: every other tool takes a file key from here.

    A Saasu file is one set of books - one business. This reads the server's own list and contacts nobody, so it
    costs nothing. It says what is CONFIGURED, not what works: `credentials_configured` means a login is set, not
    that Saasu accepts it. file-access asks Saasu.

    `used_last_24h` and `daily_budget` are this server's own limit on requests to the file in any 24 hours, kept far
    below Saasu's quota because exceeding that blocks the whole file for a day. Check them before a run of lookups.
    TEXT)]
#[IsReadOnly]
class ListFiles extends Tool
{
    public function handle(Request $request, FileInventory $inventory, DailyBudget $budget): Response|ResponseFactory
    {
        try {
            $files = $inventory->all();
        } catch (FileInventoryException $e) {
            return Response::error(
                'The file inventory cannot be read: '.$e->getMessage().'. Run `saasumcp app:validate` on the machine this server runs on.'
            );
        }

        return Response::structured([
            'count' => count($files),
            'files' => array_values(array_map(static fn (SaasuFile $file): array => [
                ...$file->describe(),
                'used_last_24h' => $budget->spent($file),
            ], $files)),
        ]);
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'count' => $schema->integer()->required(),
            'files' => $schema->array()->description('One entry per file: file (the key), name, file_id, daily_budget, used_last_24h, credentials_configured.')->required(),
        ];
    }
}
