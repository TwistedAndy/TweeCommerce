<?php

namespace App\Core\Action;

use App\Controllers\BaseController;

class ActionController extends BaseController
{
    public function run(ActionService $actions)
    {
        $secret = $this->request->getHeaderLine('X-Action-Secret');

        if ($secret !== (getenv('ACTION_SECRET') ? : 'default')) {
            return $this->response->setStatusCode(403);
        }

        $actions->runBatch();

        return $this->response->setBody('OK');
    }
}