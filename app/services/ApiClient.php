<?php
namespace NewCasanovaPortalGiav\App\Services;

class ApiClient
{
    protected string $baseUrl;

    public function __construct(string $baseUrl = '')
    {
        $this->baseUrl = $baseUrl;
    }

    public function get(string $path, array $query = []): array
    {
        return ['url' => rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/'), 'query' => $query];
    }
}
