<?php
declare(strict_types=1);
namespace App\Core;

/** 简易路由器：支持 PATH_INFO、REQUEST_URI 重写与 ?r= 回退 */
final class Router
{
    /** @var array<string, array<int, array{pattern:string,handler:callable}>> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[strtoupper($method)][] = ['pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes[$method] ?? [] as $r) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $r['pattern']) . '$#';
            if (preg_match($regex, $path, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                ($r['handler'])($params);
                return;
            }
        }
        if (str_starts_with($path, '/v1/')) {
            Http::apiError('接口不存在: ' . $method . ' ' . $path, 'not_found_error', 404);
        }
        Http::error('接口不存在: ' . $method . ' ' . $path, 404);
    }

    /** 解析当前请求路径（去除应用基路径） */
    public static function currentPath(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($base !== '' && str_starts_with($uri, $base)) { $uri = substr($uri, strlen($base)); }
        if ($uri === '' || $uri[0] !== '/') { $uri = '/' . $uri; }
        return '/' . trim($uri, '/');
    }
}
