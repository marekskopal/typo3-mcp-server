<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\AbstractTableToolHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\CreateHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\DeleteBatchHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\DeleteHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\GetHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\ListHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\MoveBatchHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\MoveHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\TranslatableCreateHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\TranslatableListHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\UpdateBatchHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\UpdateHandler;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;

/**
 * Builds table CRUD tools from a {@see TableToolConfig} and registers them on the MCP builder.
 *
 * `DynamicToolRegistrar`, `RedirectToolRegistrar` and `SchedulerToolRegistrar` each hand-rolled the
 * same get/update/delete bodies against a fixed table — near-identical code whose drift is what
 * produced TMS-31's bug, where one of the copies put its `json_decode` outside the audit wrapper.
 * Registrars now ask for the tools they want and write only what is genuinely theirs: redirect's
 * filtered list, scheduler's task-type list, and so on.
 *
 * Handlers are passed as `[$handler, '__invoke']`, the SDK's instance-handler form: the MCP SDK
 * reflects `__invoke` for the input schema and calls it on this very instance, so each tool carries
 * its own table config without a container round-trip.
 */
readonly class TableToolFactory
{
    public function __construct(
        private RecordService $recordService,
        private DataHandlerService $dataHandlerService,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /** Registers the full nine-tool CRUD + batch surface. */
    public function registerAll(Builder $builder, TableToolConfig $config): void
    {
        $this->register($builder, $this->list($config));
        $this->register($builder, $this->get($config));
        $this->register($builder, $this->create($config));
        $this->register($builder, $this->update($config));
        $this->register($builder, $this->delete($config));
        $this->register($builder, $this->move($config));
        $this->register($builder, $this->deleteBatch($config));
        $this->register($builder, $this->updateBatch($config));
        $this->register($builder, $this->moveBatch($config));
    }

    /**
     * Registers one handler, taking its name and description from the handler itself so the three
     * cannot be changed independently.
     */
    public function register(Builder $builder, AbstractTableToolHandler $handler): void
    {
        $builder->addTool(
            handler: [$handler, '__invoke'],
            name: $handler->toolName(),
            description: $handler->description(),
        );
    }

    public function list(TableToolConfig $config): AbstractTableToolHandler
    {
        return $config->isTranslatable()
            ? new TranslatableListHandler($config, $this->auditLogger, $this->logger, $this->recordService)
            : new ListHandler($config, $this->auditLogger, $this->logger, $this->recordService);
    }

    public function get(TableToolConfig $config): GetHandler
    {
        return new GetHandler($config, $this->auditLogger, $this->logger, $this->recordService);
    }

    public function create(TableToolConfig $config): AbstractTableToolHandler
    {
        return $config->isTranslatable()
            ? new TranslatableCreateHandler($config, $this->auditLogger, $this->logger, $this->dataHandlerService)
            : new CreateHandler($config, $this->auditLogger, $this->logger, $this->dataHandlerService);
    }

    public function update(TableToolConfig $config): UpdateHandler
    {
        return new UpdateHandler($config, $this->auditLogger, $this->logger, $this->dataHandlerService);
    }

    public function delete(TableToolConfig $config): DeleteHandler
    {
        return new DeleteHandler($config, $this->auditLogger, $this->logger, $this->dataHandlerService, $this->recordService);
    }

    public function move(TableToolConfig $config): MoveHandler
    {
        return new MoveHandler($config, $this->auditLogger, $this->logger, $this->dataHandlerService);
    }

    public function deleteBatch(TableToolConfig $config): DeleteBatchHandler
    {
        return new DeleteBatchHandler($config, $this->auditLogger, $this->logger, $this->recordService, $this->dataHandlerService);
    }

    public function updateBatch(TableToolConfig $config): UpdateBatchHandler
    {
        return new UpdateBatchHandler($config, $this->auditLogger, $this->logger, $this->recordService, $this->dataHandlerService);
    }

    public function moveBatch(TableToolConfig $config): MoveBatchHandler
    {
        return new MoveBatchHandler($config, $this->auditLogger, $this->logger, $this->recordService, $this->dataHandlerService);
    }
}
