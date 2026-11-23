<?php

use App\Domains\Files\Http\Controllers\AttachTagsController;
use App\Domains\Files\Http\Controllers\BulkAttachTagsController;
use App\Domains\Files\Http\Controllers\BulkDeleteFilesController;
use App\Domains\Files\Http\Controllers\BulkMoveFilesController;
use App\Domains\Files\Http\Controllers\CancelFileOperationController;
use App\Domains\Files\Http\Controllers\CopyFileController;
use App\Domains\Files\Http\Controllers\CreateDirectoryController;
use App\Domains\Files\Http\Controllers\CreateTagController;
use App\Domains\Files\Http\Controllers\DeleteDirectoryController;
use App\Domains\Files\Http\Controllers\DeleteFileController;
use App\Domains\Files\Http\Controllers\DeleteTagController;
use App\Domains\Files\Http\Controllers\DetachTagController;
use App\Domains\Files\Http\Controllers\DownloadFileController;
use App\Domains\Files\Http\Controllers\ListDirectoriesController;
use App\Domains\Files\Http\Controllers\ListFileOperationsController;
use App\Domains\Files\Http\Controllers\ListFilesController;
use App\Domains\Files\Http\Controllers\ListTagsController;
use App\Domains\Files\Http\Controllers\MediaLibraryController;
use App\Domains\Files\Http\Controllers\MoveDirectoryController;
use App\Domains\Files\Http\Controllers\MoveFileController;
use App\Domains\Files\Http\Controllers\PrepareDownloadController;
use App\Domains\Files\Http\Controllers\PurgeDirectoryController;
use App\Domains\Files\Http\Controllers\PurgeFileController;
use App\Domains\Files\Http\Controllers\RenameDirectoryController;
use App\Domains\Files\Http\Controllers\RenameFileController;
use App\Domains\Files\Http\Controllers\RestoreDirectoryController;
use App\Domains\Files\Http\Controllers\RestoreFileController;
use App\Domains\Files\Http\Controllers\RetryFileOperationController;
use App\Domains\Files\Http\Controllers\StreamDownloadFileController;
use App\Domains\Files\Http\Controllers\UpdateFileDescriptionController;
use App\Domains\Files\Http\Controllers\UpdateProtectedMetadataController;
use App\Domains\Files\Http\Controllers\UpdateTagController;
use App\Domains\Files\Http\Controllers\UploadFileController;
use App\Domains\Telegram\Http\Controllers\ConfigureConnectionEncryptionController;
use App\Domains\Telegram\Http\Controllers\ConfirmRoxiProvisioningController;
use App\Domains\Telegram\Http\Controllers\CreateBotConnectionController;
use App\Domains\Telegram\Http\Controllers\CreatePersonalTelegramConnectionController;
use App\Domains\Telegram\Http\Controllers\DeleteTelegramConnectionController;
use App\Domains\Telegram\Http\Controllers\ReconnectPersonalTelegramConnectionController;
use App\Domains\Telegram\Http\Controllers\RegenerateProvisioningCodeController;
use App\Domains\Telegram\Http\Controllers\SetupRoxiStorageChatController;
use App\Domains\Telegram\Http\Controllers\StartInviteConnectionController;
use App\Domains\Telegram\Http\Controllers\StartRoxiProvisioningController;
use App\Domains\Telegram\Http\Controllers\StartTelegramWorkerController;
use App\Domains\Telegram\Http\Controllers\SubmitPersonalTelegramLoginCodeController;
use App\Domains\Telegram\Http\Controllers\SubmitPersonalTelegramLoginPasswordController;
use App\Domains\Telegram\Http\Controllers\TelegramConnectionsPageController;
use App\Domains\Telegram\Http\Controllers\TelegramWorkerStatusController;
use App\Domains\Telegram\Http\Controllers\UpdateBotCredentialsController;
use App\Domains\Telegram\Http\Controllers\UpdateTelegramConnectionController;
use App\Domains\Telegram\Http\Controllers\VerifyTelegramConnectionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    // ── File manager page ────────────────────────────────────────────
    Route::get('media', [MediaLibraryController::class, 'index'])->name('media.index');
    Route::patch('media/library', [MediaLibraryController::class, 'directory'])->name('media.library');
    Route::post('media/playlists', [MediaLibraryController::class, 'store'])->name('media.playlists.store');
    Route::patch('media/playlists/{playlist}', [MediaLibraryController::class, 'update'])->whereNumber('playlist')->name('media.playlists.update');
    Route::delete('media/playlists/{playlist}', [MediaLibraryController::class, 'destroy'])->whereNumber('playlist')->name('media.playlists.destroy');
    Route::post('media/folders', [MediaLibraryController::class, 'storeFolder'])->name('media.folders.store');
    Route::patch('media/folders/{folder}', [MediaLibraryController::class, 'updateFolder'])->whereNumber('folder')->name('media.folders.update');
    Route::delete('media/folders/{folder}', [MediaLibraryController::class, 'destroyFolder'])->whereNumber('folder')->name('media.folders.destroy');
    Route::patch('media/tracks/{file}', [MediaLibraryController::class, 'track'])->name('media.tracks.update');

    Route::post('media/tracks/{file}/playback', [MediaLibraryController::class, 'playback'])->name('media.tracks.playback');

    Route::get('files', ListFilesController::class)->name('files.index');

    // ── Directories ──────────────────────────────────────────────────
    Route::get('directories', ListDirectoriesController::class)->name('directories.index');
    Route::post('directories', CreateDirectoryController::class)->name('directories.store');
    Route::patch('directories/{directory}/rename', RenameDirectoryController::class)->name('directories.rename');
    Route::patch('directories/{directory}/move', MoveDirectoryController::class)->name('directories.move');
    Route::delete('directories/{directory}', DeleteDirectoryController::class)->name('directories.destroy');
    Route::post('directories/{directory}/restore', RestoreDirectoryController::class)->name('directories.restore')->withTrashed();
    Route::delete('directories/{directory}/purge', PurgeDirectoryController::class)->name('directories.purge')->withTrashed();

    // ── Files ────────────────────────────────────────────────────────
    Route::post('files/upload', UploadFileController::class)->middleware('throttle:20,1')->name('files.upload');
    Route::post('files/{file}/prepare-download', PrepareDownloadController::class)->middleware('throttle:30,1')->name('files.prepare-download');
    Route::get('files/{file}/stream-download', StreamDownloadFileController::class)->middleware('throttle:30,1')->name('files.stream-download');
    Route::post('file-operations/{operation}/retry', RetryFileOperationController::class)->middleware('throttle:20,1')->name('file-operations.retry');
    Route::post('file-operations/{operation}/cancel', CancelFileOperationController::class)->name('file-operations.cancel');
    Route::get('file-operations', ListFileOperationsController::class)->name('file-operations.index');
    Route::post('files/{file}/copy', CopyFileController::class)->middleware('throttle:20,1')->name('files.copy');
    Route::get('files/{file}/download', DownloadFileController::class)->name('files.download');
    Route::patch('files/{file}/protected-metadata', UpdateProtectedMetadataController::class)->name('files.protected-metadata');
    Route::patch('files/{file}/rename', RenameFileController::class)->name('files.rename');
    Route::patch('files/{file}/move', MoveFileController::class)->name('files.move');
    Route::patch('files/{file}/description', UpdateFileDescriptionController::class)->name('files.description');
    Route::delete('files/{file}', DeleteFileController::class)->name('files.destroy');
    Route::post('files/{file}/restore', RestoreFileController::class)->name('files.restore')->withTrashed();
    Route::delete('files/{file}/purge', PurgeFileController::class)->name('files.purge')->withTrashed();

    Route::post('files/bulk/tags', BulkAttachTagsController::class)->name('files.bulk-tags');
    Route::post('files/bulk/move', BulkMoveFilesController::class)->name('files.bulk-move');
    Route::post('files/bulk/delete', BulkDeleteFilesController::class)->name('files.bulk-delete');

    // ── Tags ─────────────────────────────────────────────────────────
    Route::get('tags', ListTagsController::class)->name('tags.index');
    Route::post('tags', CreateTagController::class)->name('tags.store');
    Route::patch('tags/{tag}', UpdateTagController::class)->name('tags.update');
    Route::delete('tags/{tag}', DeleteTagController::class)->name('tags.destroy');
    Route::post('files/{file}/tags', AttachTagsController::class)->name('files.tags.attach');
    Route::delete('files/{file}/tags/{tag}', DetachTagController::class)->name('files.tags.detach');

    // ── Telegram connections ─────────────────────────────────────────
    Route::get('telegram/connections', TelegramConnectionsPageController::class)->name('telegram.connections');
    Route::post('telegram/connections/roxi', StartRoxiProvisioningController::class)->middleware('throttle:6,1')->name('telegram.roxi.start');
    Route::post('telegram/connections/invite', StartInviteConnectionController::class)->middleware('throttle:6,1')->name('telegram.invite.start');
    Route::post('telegram/connections/bot', CreateBotConnectionController::class)->middleware('throttle:6,1')->name('telegram.bot.start');
    Route::post('telegram/connections/personal', CreatePersonalTelegramConnectionController::class)->middleware('throttle:6,1')->name('telegram.personal.start');
    Route::patch('telegram/connections/{connection}', UpdateTelegramConnectionController::class)->name('telegram.connections.update');
    Route::post('telegram/connections/{connection}/encryption', ConfigureConnectionEncryptionController::class)
        ->middleware('throttle:6,1')->name('telegram.connections.encryption');
    Route::delete('telegram/connections/{connection}', DeleteTelegramConnectionController::class)->name('telegram.connections.destroy');
    Route::post('telegram/connections/{connection}/provisioning-code', RegenerateProvisioningCodeController::class)
        ->name('telegram.connections.regenerate-code');
    Route::post('telegram/connections/{connection}/provisioning-confirmation', ConfirmRoxiProvisioningController::class)
        ->middleware('throttle:6,1')->name('telegram.connections.confirm-provisioning');
    Route::post('telegram/connections/{connection}/verify', VerifyTelegramConnectionController::class)
        ->middleware('throttle:6,1')->name('telegram.connections.verify');
    Route::post('telegram/connections/{connection}/storage-chat', SetupRoxiStorageChatController::class)
        ->middleware('throttle:6,1')->name('telegram.connections.setup-chat');
    Route::patch('telegram/connections/{connection}/credentials', UpdateBotCredentialsController::class)
        ->name('telegram.connections.credentials');
    Route::post('telegram/connections/{connection}/personal-login/code', SubmitPersonalTelegramLoginCodeController::class)
        ->middleware('throttle:6,1')->name('telegram.personal.login-code');
    Route::post('telegram/connections/{connection}/personal-login/password', SubmitPersonalTelegramLoginPasswordController::class)
        ->middleware('throttle:6,1')->name('telegram.personal.login-password');
    Route::post('telegram/connections/{connection}/personal-reconnect', ReconnectPersonalTelegramConnectionController::class)
        ->middleware('throttle:6,1')->name('telegram.personal.reconnect');

    Route::get('telegram/worker-status', TelegramWorkerStatusController::class)->name('telegram.worker-status');
    Route::post('telegram/worker/start', StartTelegramWorkerController::class)
        ->middleware(['can:start-telegram-worker', 'throttle:6,1'])
        ->name('telegram.worker.start');
});
