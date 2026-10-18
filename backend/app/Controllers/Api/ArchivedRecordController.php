<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Archive\PermanentDeletionService;
use CodeIgniter\HTTP\ResponseInterface;

final class ArchivedRecordController extends ApiController
{
    public function deletePermanently(string $resource, int $id): ResponseInterface
    {
        return $this->ok((new PermanentDeletionService())->delete($resource, $id));
    }
}
