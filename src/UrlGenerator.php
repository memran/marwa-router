<?php declare(strict_types=1);

namespace Marwa\Router;

final class UrlGenerator
{
    /** @param array<int, array{path:string,name:?string}> $routes */
    public function __construct(private array $routes) {}

    public function for(string $name, array $params = []): string
    {   
        foreach ($this->routes as $route) {
            if ($route['name'] !== $name) continue;
            $path = $route['path'] ?: '/';
            // Replace tokens like {id:\d+} or {slug}
            $url = preg_replace_callback(
                '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
                function ($match) use (&$params) {
                    $key = $match[1];
                    if (!array_key_exists($key, $params)) {
                        throw new \InvalidArgumentException("Missing route param: {$key}");
                    }
                    $value = (string)$params[$key];
                    unset($params[$key]);
                    return $value;
                },
                $path
            );
            if (!empty($params)) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            }
            return $url;
        }
        throw new \RuntimeException("Route not found by name: {$name}");
    }
    /**
     * generate a signed url with expiration
     */
    public function signed(string $name, array $params, int $ttl, string $key): string
    {
        $url = $this->for($name, $params);
        $exp = time() + $ttl;
        $sig = hash_hmac('sha256', $url . $exp, $key);
        return $url . (str_contains($url, '?') ? '&' : '?') . "exp=$exp&sig=$sig";
    }
    /**
     * verify the url is valid or not.
     */
    public function verify(string $url, string $key): bool
    {
        //parsing the URL
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        //return false if exp or sig is not there
        if (empty($query['exp']) || empty($query['sig'])) return false;

        // return false if time expired
        if (time() > (int)$query['exp']) {
            return false;
        }
        /**
         * variables
         */
        $base = strtok($url, '?');
        $exp = $query['exp'];
        $userSig = $query['sig'];
        //generated again the sign 
        $generatedSig = hash_hmac('sha256', $base . $exp, $key); 
        return hash_equals($generatedSig,$userSig);
    }
}

/**
**/