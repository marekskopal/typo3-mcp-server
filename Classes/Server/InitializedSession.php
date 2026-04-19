<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;
use function array_key_exists;
use function count;
use function is_array;
use function is_object;
use const JSON_THROW_ON_ERROR;

/**
 * Fixed Session implementation that properly reads data from the store.
 *
 * The SDK's Session::readData() uses `isset($this->data)` to check if data
 * was loaded, but $data is initialized to [] so isset() is always true.
 * This means createWithId() never reads persisted session data from the store,
 * causing empty responses (HTTP 202 with 0 bytes).
 *
 * This implementation uses a $loaded flag instead.
 */
class InitializedSession implements SessionInterface
{
    private bool $loaded = false;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private SessionStoreInterface $store, private Uuid $id,)
    {
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStore(): SessionStoreInterface
    {
        return $this->store;
    }

    public function save(): bool
    {
        return $this->store->write($this->id, json_encode($this->data, JSON_THROW_ON_ERROR));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $data = $this->readData();

        foreach ($segments as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }

            $data = $data[$segment];
        }

        return $data;
    }

    public function set(string $key, mixed $value, bool $overwrite = true): void
    {
        $segments = explode('.', $key);
        $this->readData();
        $data = &$this->data;

        while (count($segments) > 1) {
            $segment = (string) array_shift($segments);
            if (!isset($data[$segment]) || !is_array($data[$segment])) {
                $data[$segment] = [];
            }

            $data = &$data[$segment];
        }

        $lastKey = (string) array_shift($segments);
        if ($overwrite || !isset($data[$lastKey])) {
            $data[$lastKey] = $value;
        }
    }

    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $data = $this->readData();

        foreach ($segments as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } elseif (is_object($data) && isset($data->{$segment})) {
                $data = $data->{$segment};
            } else {
                return false;
            }
        }

        return true;
    }

    public function forget(string $key): void
    {
        $segments = explode('.', $key);
        $this->readData();
        $data = &$this->data;

        while (count($segments) > 1) {
            $segment = (string) array_shift($segments);
            if (!isset($data[$segment]) || !is_array($data[$segment])) {
                $data[$segment] = [];
            }

            $data = &$data[$segment];
        }

        $lastKey = (string) array_shift($segments);
        if (isset($data[$lastKey])) {
            unset($data[$lastKey]);
        }
    }

    public function clear(): void
    {
        $this->data = [];
        $this->loaded = true;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->readData();
    }

    /** @param array<string, mixed> $attributes */
    public function hydrate(array $attributes): void
    {
        $this->data = $attributes;
        $this->loaded = true;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->all();
    }

    /** @return array<string, mixed> */
    private function readData(): array
    {
        if ($this->loaded) {
            return $this->data;
        }

        $this->loaded = true;

        $rawData = $this->store->read($this->id);

        if ($rawData === false) {
            return $this->data = [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($rawData, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            return $this->data = [];
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        return $this->data = $data;
    }
}
