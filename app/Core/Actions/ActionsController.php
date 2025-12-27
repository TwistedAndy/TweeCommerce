<?php

namespace App\Core\Actions;

use App\Controllers\BaseController;

class ActionsController extends BaseController
{
    public function run(Actions $actions)
    {
        $secret = $this->request->getHeaderLine('X-Action-Secret');

        if ($secret !== (getenv('ACTION_SECRET') ? : 'default')) {
            return $this->response->setStatusCode(403);
        }

        $actions->runBatch();

        return $this->response->setBody('OK');
    }
}