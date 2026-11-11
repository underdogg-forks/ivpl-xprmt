<?php

namespace Tests\Support\SecurityScanner;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * CodeIgniter has no route list: every public method of a controller is a URL. This reads the controllers and
 * produces that list, in the shape of `php artisan route:list --json` so that tools written for it can use it.
 */
final class RouteInventory
{
    public function __construct(private readonly string $root) {}

    /**
     * @return list<array{domain: null, method: string, uri: string, name: string, action: string, middleware: list<string>, parameters: list<string>}>
     */
    public function routes(): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $finder = new NodeFinder();
        $routes = [];
        $files  = glob($this->root . '/application/modules/*/controllers/{,*/}*.php', GLOB_BRACE) ?: [];
        sort($files);

        foreach ($files as $file) {
            $class = $finder->findFirstInstanceOf($parser->parse((string) file_get_contents($file)) ?? [], Stmt\Class_::class);

            if ( ! $class instanceof Stmt\Class_ || $class->isAbstract()) {
                continue;
            }

            preg_match('#/modules/([^/]+)/controllers/(.*)\.php$#', $file, $path);
            $module   = $path[1];
            $segments = array_map('strtolower', explode('/', $path[2]));
            $prefix   = $segments === [$module] ? $module : $module . '/' . implode('/', $segments);
            $guard    = $class->extends instanceof Node\Name ? $class->extends->toString() : '';

            foreach ($class->getMethods() as $method) {
                $name = $method->name->toString();

                if ( ! $method->isPublic() || $method->isStatic() || str_starts_with($name, '_')) {
                    continue;
                }

                $parameters = array_map(static fn (Node\Param $p): string => (string) $p->var->name, $method->params);
                $uri        = $name === 'index' && $parameters === [] ? $prefix : $prefix . '/' . $name;

                foreach ($parameters as $parameter) {
                    $uri .= '/{' . $parameter . '}';
                }

                $routes[] = [
                    'domain'     => null,
                    'method'     => 'GET|HEAD|POST',
                    'uri'        => $uri,
                    'name'       => str_replace('/', '.', $prefix) . '.' . $name,
                    'action'     => $class->name . '@' . $name,
                    'middleware' => [$guard],
                    'parameters' => $parameters,
                ];
            }
        }

        return $routes;
    }
}
