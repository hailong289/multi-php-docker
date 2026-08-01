<?php

declare(strict_types=1);

namespace Manager;

final class Application
{
    public function __construct(
        private readonly array $routes,
    ) {
    }

    public static function boot(): self
    {
        $routes = require __DIR__ . '/routes.php';
        return new self($routes);
    }

    public function run(): void
    {
        $request = Http\Request::capture();
        $kernel = new Http\Kernel();

        try {
            $response = $kernel->handle($request, function (Http\Request $request): Http\Response {
                return $this->dispatch($request);
            });
        } catch (Http\HttpException $exception) {
            $response = Http\Response::error(
                $exception->errorKey(),
                $exception->status(),
                $exception->fields(),
                $exception->parameters(),
            );
        } catch (\Throwable $exception) {
            $key = $exception->getMessage();
            if (
                !str_starts_with($key, 'error.')
                && !str_starts_with($key, 'php_controller.')
                && !str_starts_with($key, 'validation.')
            ) {
                error_log('manager api: ' . $exception->getMessage());
                $key = 'error.internal';
            }
            $response = Http\Response::error($key, 500);
        }

        $response->send();
    }

    private function dispatch(Http\Request $request): Http\Response
    {
        foreach ($this->routes as $route) {
            [$method, $pattern, $action] = $route;
            if (strtoupper((string) $method) !== $request->method()) {
                continue;
            }

            $regex = '#^' . $pattern . '$#';
            if (preg_match($regex, $request->path(), $matches) !== 1) {
                continue;
            }

            $params = array_filter(
                $matches,
                static fn ($key): bool => !is_int($key),
                ARRAY_FILTER_USE_KEY,
            );

            [$controllerClass, $controllerMethod] = $action;
            /** @var Controllers\Controller $controller */
            $controller = new $controllerClass();
            return $controller->{$controllerMethod}($request, $params);
        }

        throw new Http\HttpException('error.not_found', 404);
    }
}
