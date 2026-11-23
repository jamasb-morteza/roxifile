<?php

namespace App\Domains\Files\Http\Controllers;

use App\Domains\Files\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dual-mode endpoint: renders the Tags management page for Inertia visits
 * and serves JSON for API-style fetches (Accept: application/json).
 */
class ListTagsController
{
    public function __invoke(Request $request): Response|JsonResponse
    {
        $tags = Tag::query()
            ->where('user_id', $request->user()->id)
            ->withCount('files')
            ->orderBy('name')
            ->get(['id', 'name']);

        $payload = $tags->map(fn (Tag $t): array => [
            'id' => $t->id,
            'name' => $t->name,
            'files_count' => $t->files_count,
        ])->all();

        if ($request->expectsJson()) {
            return response()->json(['tags' => $payload]);
        }

        return Inertia::render('tags/Index', [
            'tags' => $payload,
        ]);
    }
}
