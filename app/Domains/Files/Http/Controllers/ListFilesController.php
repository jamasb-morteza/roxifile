<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\ListFileOperations;
use App\Domains\Files\Actions\SearchFiles;
use App\Domains\Files\Http\Requests\ListFilesRequest;
use App\Domains\Files\Models\Directory;
use App\Domains\Files\Models\File;
use App\Domains\Telegram\Actions\ListStorageConnectionOptions;
use App\Domains\Telegram\Models\TelegramWorkerStatus;
use App\Domains\Telegram\Runtime\TelegramErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ListFilesController
{
    public function __invoke(ListFilesRequest $request, SearchFiles $searchFiles, ListFileOperations $operations, ListStorageConnectionOptions $connectionOptions): Response|JsonResponse|RedirectResponse
    {
        $user = $request->user();

        $directoryId = $request->integer('directory_id') ?: null;
        $directory = $directoryId !== null
            ? Directory::query()->when($request->boolean('trash'), fn ($query) => $query->withTrashed())->where('user_id', $user->id)->findOrFail($directoryId)
            : null;

        $breadcrumbs = collect();

        if ($directory !== null) {
            $pathPrefixes = collect(explode('/', $directory->path))
                ->map(fn (string $segment, int $i): string => implode('/', array_slice(explode('/', $directory->path), 0, $i + 1)))
                ->values();

            $idsByPath = Directory::query()->when($request->boolean('trash'), fn ($query) => $query->withTrashed())
                ->where('user_id', $user->id)
                ->whereIn('path', $pathPrefixes->all())
                ->pluck('id', 'path');

            $breadcrumbs = $pathPrefixes
                ->map(fn (string $prefix): array => [
                    // Materialized path prefixes rebuild without recursion (spec §16).
                    'id' => $idsByPath->get($prefix),
                    'name' => collect(explode('/', $prefix))->last(),
                    'path' => $prefix,
                ])
                ->values();
        }

        $directories = Directory::query()
            ->when($request->boolean('trash'), fn ($query) => $query->onlyTrashed())
            ->where('user_id', $user->id)
            ->when($request->boolean('trash') && $directory === null,
                fn ($query) => $query->where(fn ($parents) => $parents->whereNull('parent_id')->orWhereNotIn('parent_id', Directory::onlyTrashed()->where('user_id', $user->id)->select('id'))),
                fn ($query) => $query->where('parent_id', $directory?->id))
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'path', 'created_at', 'updated_at', 'purging']);

        $files = $searchFiles->handle(
            $user,
            [...$request->toDto()->toFilters(), 'global' => $directory === null || $request->input('scope') === 'all'],
        );

        $files->through($this->serializeFile(...));

        $selectedFile = null;
        if ($request->filled('file')) {
            $identifier = (string) $request->input('file');
            $fileQuery = File::query()
                ->with(['account', 'connection.account', 'tags', 'directory'])
                ->where('user_id', $user->id);

            $file = ctype_digit($identifier)
                ? $fileQuery->findOrFail($identifier)
                : $fileQuery->where('uuid', $identifier)->firstOrFail();

            if (ctype_digit($identifier)) {
                return redirect()->route('files.index', [...$request->query(), 'file' => $file->uuid]);
            }

            $selectedFile = $this->serializeFile($file);
        }

        if ($request->expectsJson()) {
            return response()->json(['files' => $files, 'selected_file' => $selectedFile]);
        }

        $workerStatus = TelegramWorkerStatus::query()->find(1);

        $options = $connectionOptions->handle($user);

        return Inertia::render('files/Index', [
            'max_upload_bytes' => (int) config('telegram.storage.max_upload_kib', 204800) * 1024,
            'directory' => $directory ? [
                'id' => $directory->id,
                'name' => $directory->name,
                'path' => $directory->path,
            ] : null,
            'breadcrumbs' => $breadcrumbs,
            'directories' => $directories,
            'filter_connections' => $options['filters'],
            'connections' => $options['connections'],
            'vault_connections' => $options['vaults'],
            'operations' => $operations->handle($user),
            'files' => $files,
            'selected_file' => $selectedFile,
            'filters' => [
                'mime_type' => $request->input('mime_type'),
                'telegram_connection_id' => $request->integer('telegram_connection_id') ?: null,
                'created_after' => $request->input('created_after'),
                'created_before' => $request->input('created_before'),
                'scope' => $request->input('scope', 'folder'),
                'q' => $request->input('q'),
                'file_type' => $request->input('file_type'),
                'tag_ids' => (array) $request->input('tag_ids', []),
                'sort' => $request->input('sort', 'updated_at'),
                'direction' => $request->input('direction', 'desc'),
                'trash' => $request->boolean('trash'),
            ],
            'workerStatus' => $workerStatus ? [
                'running' => $workerStatus->last_heartbeat_at !== null
                    && $workerStatus->last_heartbeat_at->gt(now()->subSeconds(45)),
                'roxi_connected' => (bool) $workerStatus->roxi_connected,
                'session_loaded' => (bool) $workerStatus->session_loaded,
                'last_activity_at' => $workerStatus->last_activity_at?->toIso8601String(),
                'last_successful_operation_at' => $workerStatus->last_successful_operation_at?->toIso8601String(),
                'last_error' => $workerStatus->last_error !== null ? TelegramErrors::message($workerStatus->last_error) : null,
                'reconnect_count' => $workerStatus->reconnect_count,
            ] : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeFile(File $file): array
    {
        $connectionId = $file->telegram_connection_id;
        $encryptionMode = $connectionId === null
            ? 'none'
            : $file->connection->encryption_mode;
        $metadataProtected = $encryptionMode === 'contents_and_metadata';

        return [
            'id' => $file->id,
            'uuid' => $file->uuid,
            'encryption_header' => $file->encryption_header,
            'connection_id' => $file->telegram_connection_id,
            'encryption_mode' => $encryptionMode,
            'vault_envelope' => $encryptionMode !== 'none'
                ? $file->connection->vault_envelope
                : null,
            'name' => $file->name,
            'storage_name' => $file->name,
            'metadata_protected' => $metadataProtected,
            'protected_metadata' => $file->metadata['protected_metadata'] ?? null,
            'directory_path' => $file->directory->path ?? '',
            'mime_type' => $encryptionMode === 'contents'
                ? ($file->metadata['plaintext_mime'] ?? 'application/octet-stream')
                : $file->mime_type,
            'file_type' => $file->file_type->value,
            'size' => $file->size,
            'status' => $file->status->value,
            'description' => $file->description,
            'flagged' => isset($file->metadata['reconciliation']),
            'created_at' => $file->created_at?->toIso8601String(),
            'updated_at' => $file->updated_at?->toIso8601String(),
            'deleted_at' => $file->deleted_at?->toIso8601String(),
            'directory_id' => $file->directory_id,
            'tags' => $file->tags->map(fn ($t): array => ['id' => $t->id, 'name' => $t->name])->all(),
            'telegram' => [
                'account' => $file->account?->name,
                'connection' => $file->connection?->chat_title ?: $file->connection?->name,
                'method' => $file->connection?->method?->value,
                'chat_id' => $file->telegram_chat_id,
                'message_id' => $file->telegram_message_id,
            ],
        ];
    }
}
