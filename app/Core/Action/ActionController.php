<?php

namespace App\Core\Action;

use App\Controllers\BaseController;

class ActionController extends BaseController
{
    public function run(ActionService $actions)
    {
        if ($actions->checkSpawnKey($this->request->getGet('key'))) {
            $actions->runBatch();
            return $this->response->setBody('OK');
        } else {
            return $this->response->setStatusCode(403)->setBody('Invalid Key');
        }
    }
}