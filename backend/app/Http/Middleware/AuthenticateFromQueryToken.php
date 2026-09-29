<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 文件下载（<a> 链接无法携带 Authorization 头）时，允许通过 ?token= 传入
 * Sanctum 个人访问令牌进行鉴权。校验后把用户绑定到当前请求。
 */
class AuthenticateFromQueryToken
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() && $request->filled('token')) {
            $token = PersonalAccessToken::findToken($request->query('token'));
            if ($token && $token->tokenable) {
                $user = $token->tokenable;
                if (in_array($user->role, ['admin', 'teacher'], true)) {
                    auth()->setUser($user);
                    $request->setUserResolver(fn() => $user);
                }
            }
        }
        return $next($request);
    }
}
