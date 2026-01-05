<?php

namespace App\Core\Action;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class ActionController extends BaseController
{
    public function run(ActionService $actions): ResponseInterface
    {
        if ($actions->checkSpawnKey($this->request->getGet('key'))) {
            $actions->runBatch();
            return $this->response->setBody('OK');
        }

        return $this->response->setStatusCode(403)->setBody('Invalid Key');
    }
}