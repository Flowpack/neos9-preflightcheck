<?php

declare(strict_types=1);

namespace Flowpack\Neos9\PreflightCheck\Command;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Domain\Service\Context as ContentContext;
use Neos\ContentRepository\Domain\Service\ContextFactory as ContentContextFactory;
use Neos\ContentRepository\InMemoryGraph\ContentSubgraph\TraversableNode;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Neos\Domain\Repository\SiteRepository;

class PreflightCommandController extends CommandController
{
    public function __construct(
        private readonly ContentContextFactory $contentContextFactory,
        private readonly ContentDimensionCombinator $contentDimensionCombinator,
        private readonly NodeDataRepository $nodeDataRepository,
        private readonly ReflectionService $reflectionService,
        private readonly SiteRepository $siteRepository,
    ) {
        parent::__construct();
    }

    public function checkCommand(): void
    {
        $siteNodesMissingTheSiteNodeType = $this->findSiteNodesMissingTheSiteNodeType();
        if ($siteNodesMissingTheSiteNodeType !== []) {
            $this->outputLine();
            $this->outputLine('Site nodes missing the site node type detected (add Neos.Neos:Site as a supertype):');
            $this->outputLine();
            $this->output->outputTable(
                $siteNodesMissingTheSiteNodeType,
                [
                    'ID',
                    'Node Name',
                    'Type',
                    'Site Name'
                ],
            );
        }

        $contentContexts = $this->getContentContexts();
        $ambiguouslyTypedNodeAggregateIds = $this->findAmbiguouslyTypedNodeAggregateIds();
        if ($ambiguouslyTypedNodeAggregateIds !== []) {
            $this->outputLine();
            $this->outputLine('Ambiguously typed node aggregates detected:');
            $this->outputLine();
            foreach ($ambiguouslyTypedNodeAggregateIds as $nodeAggregateId) {
                $this->outputLine($nodeAggregateId);
                $this->output->outputTable(
                    array_filter(array_map(
                        function (ContentContext $contentContext) use ($nodeAggregateId): ?array {
                            $node = $contentContext->getNodeByIdentifier($nodeAggregateId);
                            if (!$node) {
                                return null;
                            }
                            $documentNode = null;
                            $path = '';
                            if ($node->getNodeType()->isOfType('Neos.Neos:Document')) {
                                $documentNode = $node;
                            } else {
                                $parent = $node->getParent();
                                while ($parent) {
                                    if ($parent->getNodeType()->isOfType('Neos.Neos:Document')) {
                                        $documentNode = $parent;
                                        break;
                                    }
                                    $path = \mb_substr($parent->getLabel(), 0, 24) . '/' . $path;
                                    $parent = $parent->getParent();
                                }
                            }
                            $label = $node->getLabel();
                            if (\mb_strlen($label) > 40) {
                                $label = \mb_substr($node->getLabel(), 0, 40) . '...';
                            }
                            return [
                                \json_encode($contentContext->getTargetDimensions()),
                                $label,
                                $node->getNodeType()->getName(),
                                $documentNode?->getLabel(),
                                $path,
                            ];
                        },
                        $contentContexts,
                    )),
                    [
                        'DSP',
                        'Label',
                        'Type',
                        'Document',
                        'Path'
                    ]
                );
            }
            $this->outputLine();
        }

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
     * @return array<string,array{id: string, name: string, type: string, label: string}>
     */
    private function findSiteNodesMissingTheSiteNodeType(): array
    {
        $result = [];
        foreach ($this->getContentContexts() as $contentContext) {
            $sitesRoot = $contentContext->getRootNode()->getNode('sites');
            foreach ($sitesRoot->getChildNodes() as $siteNode) {
                if (!$siteNode->getNodeType()->isOfType('Neos.Neos:Site')) {
                    $site = $this->siteRepository->findOneByNodeName($siteNode->getName());
                    $result[$siteNode->getIdentifier()] = [
                        'id' => $siteNode->getIdentifier(),
                        'name' => $siteNode->getName(),
                        'type' => $siteNode->getNodeType()->getName(),
                        'label' => $site?->getName() ?: $siteNode->getLabel(),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function findAmbiguouslyTypedNodeAggregateIds(): array
    {
        $result = [];

        $query = $this->nodeDataRepository->createQuery();
        $query->setOrderings([
            'identifier' => QueryInterface::ORDER_ASCENDING,
        ]);
        $query->setLimit(100);
        $records = $query->execute();

        $currentNodeTypeName = null;
        $currentId = null;
        while ($records->count() > 0) {
            foreach ($records as $item) {
                /** @var NodeData $item */
                if ($item->getIdentifier() !== $currentId) {
                    $currentId = $item->getIdentifier();
                    $currentNodeTypeName = $item->getNodeType()->getName();
                    continue;
                }

                if ($item->getNodeType()->getName() !== $currentNodeTypeName) {
                    $result[$item->getIdentifier()] = $item->getIdentifier();
                }
            }

            $query->setOffset($query->getOffset() + 100);
            $records = $query->execute();
        }

        return array_values($result);
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

    /**
     * @return array<ContentContext>
     */
    private function getContentContexts(): array
    {
        $result = [];
        foreach ($this->contentDimensionCombinator->getAllAllowedCombinations() as $combination) {
            $result[] = $this->contentContextFactory->create([
                'workspaceName' => 'live',
                'dimensions' => $combination,
                'targetDimensions' => array_map(
                    fn (array $values): string => $values[0],
                    $combination,
                ),
                'invisibleContentShown' => true,
                'removedContentShown' => true,
                'inaccessibleContentShown' => true,
            ]);
        }

        return $result;
    }
}
