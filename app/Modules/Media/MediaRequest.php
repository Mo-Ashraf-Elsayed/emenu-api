<?php

namespace App\Modules\Media;

use App\Shared\Http\ApiProblem;
use App\Shared\Http\StrictRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class MediaRequest extends StrictRequest
{
    protected function prepareForValidation(): void
    {
        $type = explode(';', (string) $this->header('Content-Type'), 2)[0];
        abort_unless(strtolower(trim($type)) === 'multipart/form-data', 415);
        $file = $this->file('file');
        if ($file instanceof UploadedFile && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiProblem(413, 'PAYLOAD_TOO_LARGE');
        }
        if ($file instanceof UploadedFile && $file->isValid() && $file->getSize() > Raster::MAX_BYTES) {
            throw new ApiProblem(413, 'PAYLOAD_TOO_LARGE');
        }
    }

    /** @return array<string,mixed> */
    public function validationData(): array
    {
        return $this->request->all() + $this->allFiles();
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:8192']];
    }

    public function bytes(): string
    {
        $file = $this->validated('file');
        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => 'Select an image.']);
        }

        return $file->getContent();
    }
}
