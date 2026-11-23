<?php

namespace App\Domains\Telegram\Actions;

use App\Domains\Files\Actions\CreateDirectory;
use App\Domains\Files\Data\CreateDirectoryData;
use App\Domains\Files\Models\Directory;
use App\Domains\Identity\Models\User;
use App\Domains\Telegram\Models\TelegramImportRequest;
use Illuminate\Support\Facades\DB;

class UpdateTelegramImportDetails
{
    public function handle(TelegramImportRequest $request, string $response): bool
    {
        $details = $request->details ?? [];
        $command = strtolower($response);
        if ($command === 'details') {
            $details['stage'] = 'folder';
        } elseif (str_starts_with($command, 'folder ')) {
            $path = trim(trim(substr($response, 7)), '/');
            $segments = $path === '' ? [] : explode('/', $path);
            if (mb_strlen($path) > 2048 || count($segments) > 20 || collect($segments)->contains(fn (string $name): bool => trim($name) !== $name
                || $name === '' || $name === '.' || $name === '..' || mb_strlen($name) > 128 || preg_match('/[\\\\\x00-\x1f\x7f]/u', $name) === 1)) {
                $request->update(['last_error' => 'Use a valid path with at most 20 folder names, each at most 128 characters.']);

                return true;
            }
            $folder = $path === '' ? null : Directory::query()->where('user_id', $request->user_id)->where('path', $path)->first();
            if ($path !== '' && $folder === null) {
                $details['new_path'] = $path;
                $details['stage'] = 'create';
                $request->update(['details' => $details, 'last_error' => null, 'prompt_message_id' => null]);

                return true;
            }
            $details['directory_id'] = $folder?->id;
            $details['directory_path'] = $folder->path ?? '';
            $details['stage'] = 'tags';
        } elseif ($command === 'create' && ($details['stage'] ?? '') === 'create') {
            try {
                $details = DB::transaction(function () use ($request): array {
                    $user = User::query()->lockForUpdate()->findOrFail($request->user_id);
                    $locked = TelegramImportRequest::query()->lockForUpdate()->findOrFail($request->id);
                    $details = $locked->details ?? [];
                    if (($details['stage'] ?? '') !== 'create' || ! in_array($locked->state, ['details', 'conflict'], true)) {
                        throw new \InvalidArgumentException('This folder-creation prompt is stale. Reply details to continue.');
                    }
                    $parent = null;
                    foreach (explode('/', $details['new_path'] ?? '') as $name) {
                        $folder = Directory::query()->where('user_id', $user->id)->where('parent_id', $parent?->id)->where('name', $name)->first();
                        $parent = $folder ?? app(CreateDirectory::class)->handle($user, new CreateDirectoryData($name, $parent?->id));
                    }
                    $details['directory_id'] = $parent->id;
                    $details['directory_path'] = $parent->path;
                    $details['stage'] = 'tags';
                    $locked->update(['details' => $details, 'prompt_message_id' => null, 'last_error' => null]);

                    return $details;
                });
            } catch (\InvalidArgumentException $error) {
                $request->update(['last_error' => $error->getMessage()]);

                return true;
            }
        } elseif (str_starts_with($command, 'tags ')) {
            $tags = trim(substr($response, 5));
            $names = $tags === '-' ? [] : array_values(array_unique(array_map('trim', explode(',', $tags))));
            if (count($names) > 20 || collect($names)->contains(fn (string $name): bool => $name === '' || mb_strlen($name) > 32)) {
                $request->update(['last_error' => 'Use up to 20 non-empty tags, each at most 32 characters.']);

                return true;
            }
            $details['tags'] = $names;
            $details['stage'] = 'description';
        } elseif (str_starts_with($command, 'description ')) {
            $description = trim(substr($response, 12));
            if (mb_strlen($description) > 5000) {
                $request->update(['last_error' => 'Description is limited to 5000 characters.']);

                return true;
            }
            $details['description'] = $description === '-' ? null : $description;
            $details['stage'] = 'preview';
        } elseif ($command === 'confirm' && ($details['stage'] ?? '') === 'preview') {
            return ! $this->validateDestination($request);
        } else {
            $request->update(['last_error' => 'Reply using the options in this prompt.']);

            return true;
        }
        $request->update(['state' => $request->managed_message_id === null ? 'details' : 'conflict', 'details' => $details, 'last_error' => null, 'prompt_message_id' => null]);

        return true;
    }

    public function validateDestination(TelegramImportRequest $request): bool
    {
        $details = $request->details ?? [];
        if (($details['directory_id'] ?? null) === null) {
            return true;
        }
        $folder = Directory::query()->where('user_id', $request->user_id)->where('purging', false)->find($details['directory_id']);
        if ($folder === null) {
            $details['stage'] = 'folder';
            $request->update(['details' => $details, 'state' => $request->managed_message_id === null ? 'details' : 'conflict',
                'last_error' => 'The selected folder is unavailable. Choose another folder; other details are preserved.', 'prompt_message_id' => null]);

            return false;
        }
        if ($folder->path !== ($details['directory_path'] ?? '')) {
            $details['directory_path'] = $folder->path;
            $details['stage'] = 'preview';
            $request->update(['details' => $details, 'state' => $request->managed_message_id === null ? 'details' : 'conflict', 'last_error' => 'The folder path changed. Review the destination and confirm again.', 'prompt_message_id' => null]);

            return false;
        }

        return true;
    }

    public function prompt(TelegramImportRequest $request): ?string
    {
        $details = $request->details ?? [];
        $stage = $details['stage'] ?? null;
        if ($stage === null) {
            return null;
        }
        $error = $request->last_error === null ? '' : $request->last_error."\n";
        $options = "\nReply to this prompt. You can correct folder, tags or description; reply cancel to stop.";

        return $error.match ($stage) {
            'folder' => 'Choose a folder: reply folder / for root, or folder /path (new paths require creation confirmation). Owned folders: '
                .Directory::query()->where('user_id', $request->user_id)->where('purging', false)->orderBy('path')->limit(20)->pluck('path')->implode(', ').$options,
            'create' => 'Create folders /'.($details['new_path'] ?? '').'? Reply create to explicitly create this path, or folder /another/path to change it.'.$options,
            'tags' => 'Reply tags Summer, Family to reuse or create owned tags, or tags - for none.'.$options,
            'description' => 'Reply description your text, or description - for none.'.$options,
            default => 'Review import: /'.trim(($details['directory_path'] ?? '').'/'.$request->name, '/')
                ."\nTags: ".implode(', ', $details['tags'] ?? [])
                ."\nDescription: ".($details['description'] ?? '')
                ."\nReply confirm to save, details to edit, or cancel.".$options,
        };
    }
}
