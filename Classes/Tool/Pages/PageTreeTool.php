<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class PageTreeTool
{
    private const int MAX_DEPTH = 10;

    private const int MAX_NODES = 500;

    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(
        name: 'pages_tree',
        description: 'Get the page tree hierarchy starting from a given page ID. Returns nested structure with children. Use depth to control how deep to traverse (max 10).',
    )]
    public function execute(int $pid = 0, int $depth = 3): string
    {
        $depth = min(max($depth, 1), self::MAX_DEPTH);

        $translationConfig = $this->tcaSchemaService->getTranslationConfig('pages');
        $fields = $this->tcaSchemaService->getListFields('pages');

        $languageField = $translationConfig['languageField'];
        if ($languageField !== null && !in_array($languageField, $fields, true)) {
            $fields[] = $languageField;
        }

        $transOrigPointerField = $translationConfig['transOrigPointerField'];
        if ($transOrigPointerField !== null && !in_array($transOrigPointerField, $fields, true)) {
            $fields[] = $transOrigPointerField;
        }

        try {
            $nodeCount = 0;
            $tree = $this->buildTree($pid, $depth, $nodeCount, $fields);
        } catch (\Throwable $e) {
            $this->logger->error('pages_tree tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode(['tree' => $tree, 'totalNodes' => $nodeCount], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    private function buildTree(int $pid, int $remainingDepth, int &$nodeCount, array $fields): array
    {
        if ($remainingDepth <= 0 || $nodeCount >= self::MAX_NODES) {
            return [];
        }

        $result = $this->recordService->findByPid('pages', $pid, self::MAX_NODES, 0, $fields);

        $tree = [];
        /** @var array<string, mixed> $page */
        foreach ($result['records'] as $page) {
            if ($nodeCount >= self::MAX_NODES) {
                break;
            }

            $nodeCount++;

            /** @var int $uid */
            $uid = $page['uid'];
            $page['children'] = $this->buildTree($uid, $remainingDepth - 1, $nodeCount, $fields);
            $tree[] = $page;
        }

        return $tree;
    }
}
