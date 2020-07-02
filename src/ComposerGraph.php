<?php

/**
 * JBZoo Toolbox - Composer-Graph
 *
 * This file is part of the JBZoo Toolbox project.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    Composer-Graph
 * @license    MIT
 * @copyright  Copyright (C) JBZoo.com, All rights reserved.
 * @link       https://github.com/JBZoo/Composer-Graph
 */

namespace JBZoo\ComposerGraph;

use JBZoo\Data\Data;
use JBZoo\MermaidPHP\Graph;
use JBZoo\MermaidPHP\Link;
use JBZoo\MermaidPHP\Node;

use function JBZoo\Data\data;

/**
 * Class ComposerGraph
 * @package JBZoo\ComposerGraph\Graphs
 */
class ComposerGraph
{
    /**
     * @var Graph
     */
    protected $graphWrapper;

    /**
     * @var Graph
     */
    protected $graphMain;

    /**
     * @var Graph
     */
    protected $graphRequire;

    /**
     * @var Graph
     */
    protected $graphDev;

    /**
     * @var Graph
     */
    protected $graphPlatform;

    /**
     * @var Collection
     */
    protected $collection;

    /**
     * @var Data
     */
    protected $params;

    /**
     * ComposerGraph constructor.
     * @param Collection $collection
     * @param array      $params
     */
    public function __construct(Collection $collection, array $params = [])
    {
        $this->collection = $collection;

        $this->params = data(array_merge([
            'php'          => true,
            'ext'          => true,
            'dev'          => true,
            'output-path'  => null,
            'direction'    => Graph::LEFT_RIGHT,
            'link-version' => true,
            'lib-version'  => true,
        ], $params));

        $direction = $this->params->get('direction');

        $this->graphWrapper = new Graph(['direction' => $direction, 'abc_order' => true]);
        $this->graphWrapper->addStyle('linkStyle default interpolate basis');
        $this->graphWrapper->addSubGraph($this->graphMain = new Graph(['title' => 'Your package']));

        $this->graphRequire = new Graph(['direction' => $direction, 'title' => 'Required']);
        $this->graphDev = new Graph(['direction' => $direction, 'title' => 'Required Dev']);
        $this->graphPlatform = new Graph(['direction' => $direction, 'title' => 'PHP Platform']);
    }

    /**
     * @return string
     */
    public function build(): string
    {
        Node::safeMode(true);

        $main = $this->collection->getMain();
        $this->createNode($main);
        $this->renderNodeTree($main);

        if (count($this->graphRequire->getNodes()) > 0) {
            $this->graphWrapper->addSubGraph($this->graphRequire);
        }

        if (count($this->graphDev->getNodes()) > 0) {
            $this->graphWrapper->addSubGraph($this->graphDev);
        }

        if (count($this->graphPlatform->getNodes()) > 0) {
            $this->graphWrapper->addSubGraph($this->graphPlatform);
        }

        $htmlPath = $this->params->get('output-path');
        file_put_contents($htmlPath, $this->graphWrapper->renderHtml([
            'title' => $main->getName() . ' - Graph of Dependencies',
        ]));

        return $htmlPath;
    }

    /**
     * @param Package $sourcePackage
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function renderNodeTree($sourcePackage): void
    {
        $showPhp = $this->params->get('php');
        $showExt = $this->params->get('ext');

        foreach ($sourcePackage->getRequired() as $target => $version) {
            $targetPackage = $this->collection->getByName($target);

            if (!$showPhp && $targetPackage->isPhp()) {
                continue;
            }

            if (!$showExt && $targetPackage->isPhpExt()) {
                continue;
            }

            $this->renderNodeTree($targetPackage);
            $this->addLink($sourcePackage, $targetPackage, $version, $this->getGraph($targetPackage));
        }

        if ($this->params->get('dev')) {
            foreach ($sourcePackage->getRequiredDev() as $target => $version) {
                $targetPackage = $this->collection->getByName($target);

                if (!$showPhp && $targetPackage->isPhp()) {
                    continue;
                }

                if (!$showExt && $targetPackage->isPhpExt()) {
                    continue;
                }

                $this->renderNodeTree($targetPackage);
                $this->addLink($sourcePackage, $targetPackage, $version, $this->getGraph($targetPackage));
            }
        }
    }

    /**
     * @param Package $package
     * @return Node
     */
    protected function createNode(Package $package): Node
    {
        $graph = $this->getGraph($package);

        $nodeId = $package->getName(false);

        if ($currentNode = $graph->getNode($nodeId)) {
            return $currentNode;
        }

        $node = new Node($nodeId, $package->getName($this->params->get('lib-version')));
        $graph->addNode($node);

        return $node;
    }

    /**
     * @param Package $source
     * @param Package $target
     * @param string  $version
     * @param Graph   $graph
     * @return bool
     */
    private function addLink(Package $source, Package $target, string $version, Graph $graph): bool
    {
        static $createdLinks = [];

        $sourceName = $source->getName(false);
        $targetName = $target->getName(false);
        $version = $version === '*' ? '' : $version;

        $pattern = "{$sourceName}=={$version}==>{$targetName}";

        if (!array_key_exists($pattern, $createdLinks)) {
            $sourceNode = $this->createNode($source);
            $targetNode = $this->createNode($target);

            $arrow = $source->isMain() && $target->isDirectPackage() ? Link::THICK : Link::DOTTED;
            if (!$this->params->get('link-version')) {
                $version = '';
            }

            $graph->addLink(new Link($sourceNode, $targetNode, $version, $arrow));
            $createdLinks[$pattern] = true;
            return true;
        }

        return false;
    }

    /**
     * @param Package $package
     * @return Graph
     */
    private function getGraph(Package $package): Graph
    {
        if ($package->isMain()) {
            return $this->graphMain;
        }

        if ($package->isPlatform()) {
            return $this->graphPlatform;
        }

        if ($package->isDirectPackageDev()) {
            return $this->graphDev;
        }

        if ($package->isDirectPackage()) {
            return $this->graphRequire;
        }

        if (!$package->isTag($package::TAG_REQUIRE) && $package->isTag($package::TAG_REQUIRE_DEV)) {
            return $this->graphDev;
        }

        if ($package->isTag($package::TAG_REQUIRE) && !$package->isTag($package::TAG_REQUIRE_DEV)) {
            return $this->graphRequire;
        }

        return $this->graphRequire;
    }
}