<?php

namespace App\Controllers;

class ActionController extends BaseController
{
    public function work(\App\Core\Actions\ActionsService $service)
    {
        $secret = $this->request->getHeaderLine('X-Queue-Secret');

        if ($secret !== (getenv('QUEUE_SECRET') ? : 'default')) {
            return $this->response->setStatusCode(403);
        }

        $service->processBatch();

        return $this->response->setBody('OK');
    }
}