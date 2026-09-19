<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

use JsonSerializable;

/**
 * Compiled TypoScript for a page, as flat dotted maps (`lib.contentElement.templateName => Default`).
 *
 * Serializes only the sections that were asked for, so a `type: "setup"` call does not ship a
 * `"constants": null` key an agent then has to reason about.
 */
readonly class TypoScriptCompiledResult implements JsonSerializable
{
    /**
     * @param string $path the object path the result was filtered to, or '' for the whole tree
     * @param array<string, string>|null $constants null when constants were not requested
     * @param array<string, string>|null $setup null when setup was not requested
     * @param bool $truncated true when a section hit the entry cap and the caller should narrow `path`
     */
    public function __construct(
        public int $pageId,
        public string $path,
        public ?array $constants,
        public ?array $setup,
        public bool $truncated = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = ['pageId' => $this->pageId];

        if ($this->path !== '') {
            $data['path'] = $this->path;
        }

        if ($this->constants !== null) {
            $data['constants'] = $this->constants;
        }

        if ($this->setup !== null) {
            $data['setup'] = $this->setup;
        }

        if ($this->truncated) {
            $data['truncated'] = true;
            $data['message'] = 'Output was capped. Narrow the result with the path parameter.';
        }

        return $data;
    }
}
