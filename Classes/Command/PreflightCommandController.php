<?php

declare(strict_types=1);

namespace Flowpack\Neos9\PreflightCheck\Command;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\InMemoryGraph\ContentSubgraph\TraversableNode;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Reflection\ReflectionService;

class PreflightCommandController extends CommandController
{
    public function __construct(
        private readonly ReflectionService $reflectionService,
    ) {
        parent::__construct();
    }

    public function checkCommand(): void
    {
        $customNodeImplementations = $this->resolveCustomNodeImplementations();

        if ($customNodeImplementations !== []) {
            $this->outputLine();
            $this->outputLine('Custom node implementations that have to be refactored (Node is final in Neos9):');
            $this->outputLine();
            foreach ($customNodeImplementations as $customNodeImplementation) {
                $this->outputLine('  - ' . $customNodeImplementation);
            }
            $this->outputLine();
        }
    }

    /**
     * @return list<class-string<NodeInterface>>
     */
    private function resolveCustomNodeImplementations(): array
    {
        $customNodeImplementations = [];

        foreach (
            $this->reflectionService->getAllImplementationClassNamesForInterface(
                NodeInterface::class
            ) as $fqn
        ) {
            if (!in_array(
                $fqn,
                [
                    Node::class,
                    TraversableNode::class,
                ]
            )) {
                $customNodeImplementations[] = $fqn;
            }
        }

        return $customNodeImplementations;
    }
}
