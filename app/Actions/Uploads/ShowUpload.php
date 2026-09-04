<?php

namespace App\Actions\Uploads;

use App\Http\Resources\UploadResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Lorisleiva\Actions\Concerns\AsAction;

/** GET /uploads/{upload} -- one upload and whatever was extracted from it. */
class ShowUpload
{
    use AsAction;

    public function asController(Request $request, string $upload): Response
    {
        /** @var User $user */
        $user = $request->user();

        // Scoped to the owner, so somebody else's id is a 404 rather than a 403. A 403 would
        // confirm the id exists, which is more than a stranger should learn.
        $model = $user->uploads()->with('extraction')->findOrFail($upload);

        return Inertia::render('Uploads/Show', [
            'upload' => new UploadResource($model),
            'extraction' => $model->extraction === null ? null : [
                'data' => $model->extraction->data,
                'model' => $model->extraction->model,
                'prompt_version' => $model->extraction->prompt_version,
                'input_tokens' => $model->extraction->input_tokens,
                'output_tokens' => $model->extraction->output_tokens,
                'duration_ms' => $model->extraction->duration_ms,
            ],
        ]);
    }
}
