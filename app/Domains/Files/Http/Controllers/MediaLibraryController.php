<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Actions\ManageMediaLibrary;
use App\Domains\Files\Http\Requests\UpdateMediaLibraryRequest;
use App\Domains\Files\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class MediaLibraryController
{
    public function index(Request $request, ManageMediaLibrary $library): Response
    {
        return Inertia::render('media/Index', $library->browse($request->user()->id));
    }

    public function directory(UpdateMediaLibraryRequest $request, ManageMediaLibrary $library): JsonResponse
    {
        $library->setDirectory($request->toDto());

        return response()->json(['updated' => true]);
    }

    public function store(UpdateMediaLibraryRequest $request, ManageMediaLibrary $library): JsonResponse
    {
        return response()->json(['id' => $library->createPlaylist($request->toDto())], 201);
    }

    public function update(UpdateMediaLibraryRequest $request, int $playlist, ManageMediaLibrary $library): JsonResponse
    {
        $library->updatePlaylist($request->toDto(), $playlist);

        return response()->json(['updated' => true]);
    }

    public function destroy(UpdateMediaLibraryRequest $request, int $playlist, ManageMediaLibrary $library): HttpResponse
    {
        $library->deletePlaylist($request->toDto(), $playlist);

        return response()->noContent();
    }

    public function storeFolder(UpdateMediaLibraryRequest $request, ManageMediaLibrary $library): JsonResponse
    {
        return response()->json(['id' => $library->saveFolder($request->toDto())], 201);
    }

    public function updateFolder(UpdateMediaLibraryRequest $request, int $folder, ManageMediaLibrary $library): JsonResponse
    {
        $library->saveFolder($request->toDto(), $folder);

        return response()->json(['updated' => true]);
    }

    public function destroyFolder(UpdateMediaLibraryRequest $request, int $folder, ManageMediaLibrary $library): HttpResponse
    {
        $library->deleteFolder($request->toDto(), $folder);

        return response()->noContent();
    }

    public function playback(UpdateMediaLibraryRequest $request, File $file, ManageMediaLibrary $library): HttpResponse
    {
        $library->recordPlayback($request->toDto(), $file);

        return response()->noContent();
    }

    public function track(UpdateMediaLibraryRequest $request, File $file, ManageMediaLibrary $library): JsonResponse
    {
        $library->updateTrack($request->toDto(), $file);

        return response()->json(['updated' => true]);
    }
}
