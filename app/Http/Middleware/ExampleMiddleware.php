<?php

namespace App\Http\Middleware;

use Closure;

class ExampleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        header('X-Powered-By: GIOC development team');
        $ftoken     = $request->header("Token-Access")??"";
        if($ftoken !== env('API_TOKEN')){
            return response()->json(['error' => 'INVALID_AUTH'], 401);
        }
        return $next($request);
    }
}
