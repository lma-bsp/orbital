<?php

declare(strict_types=1);

namespace App\Http\Controllers\WellKnown;

use App\Services\Security\SecurityTxt;
use Illuminate\Http\Response;

/**
 * GET /.well-known/security.txt — where to report a security problem
 * with this deployment. 404 until the platform operator sets a contact
 * in Platform Settings → Security.
 */
class SecurityTxtController
{
    public function __invoke(SecurityTxt $securityTxt): Response
    {
        $body = $securityTxt->render();

        abort_if($body === null, 404);

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
