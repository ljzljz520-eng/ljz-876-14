<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        $response = $next($request);
        // 流式/文件下载响应（如 CSV 导出）保留原始 Content-Type
        if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            || $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return $response;
        }
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }
}
