<?php

namespace App\Controllers;

use App\Core\Container;

class ActionController extends BaseController
{
    public function work(\App\Services\ActionService $service)
    {
        $secret = $this->request->getHeaderLine('X-Queue-Secret');

        if ($secret !== (getenv('QUEUE_SECRET') ? : 'default')) {
            return $this->response->setStatusCode(403);
        }

        $service->processBatch();

        return $this->response->setBody('OK');
    }
}