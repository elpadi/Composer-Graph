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

use JBZoo\Data\JSON;

use function JBZoo\Data\json;

/**
 * Class Collection
 * @package JBZoo\ComposerGraph
 */
class Collection
{
    /**
     * @var JSON
     */
    private $composerFile;

    /**
     * @var JSON
     */
    private $lockFile;

    /**
     * @var Package[]
     */
    private $collection = [];

    /**
     * Collection constructor.
     * @param JSON $composerFile
     * @param JSON $lockFile
     */
    public function __construct(JSON $composerFile, JSON $lockFile)
    {
        $this->composerFile = $composerFile;
        $this->lockFile = $lockFile;

        $this->buildCollection();
    }

    public function buildCollection(): void
    {
        $this->add('php', ['tags' => [Package::TAG_PHP]]);

        $this->add($this->composerFile->get('name'), [
            //'version'     => 'dev-master', // TODO: Get it via `git`
            'require'     => $this->composerFile->get('require'),
            'require-dev' => $this->composerFile->get('require-dev'),
            'tags'        => [Package::TAG_MAIN]
        ]);

        $mainRequire = array_keys((array)$this->composerFile->get('require'));
        foreach ($mainRequire as $package) {
            $this->add((string)$package, [
                'tags' => [Package::TAG_DIRECT, Package::TAG_REQUIRE]
            ]);
        }

        $mainRequireDev = array_keys((array)$this->composerFile->get('require-dev'));
        foreach ($mainRequireDev as $packageDev) {
            $this->add((string)$packageDev, [
                'tags' => [Package::TAG_DIRECT, Package::TAG_REQUIRE_DEV]
            ]);
        }

        // Lock file
        $scopes = [
            Package::TAG_REQUIRE     => (array)$this->lockFile->get('packages'),
            Package::TAG_REQUIRE_DEV => (array)$this->lockFile->get('packages-dev'),
        ];

        foreach ($scopes as $scopeType => $scope) {
            foreach ($scope as $package) {
                $package = json($package);

                $require = (array)$package->get('require');

                $version = $package->get('version');
                $version = $package->find("extra.branch-alias.{$version}") ?: $version;

                $this->add((string)$package->get('name'), [
                    'version' => $version,
                    'require' => $require,
                    'tags'    => $scopeType
                ]);

                foreach (array_keys($require) as $innerRequired) {
                    $this->add((string)$innerRequired, ['tags' => [$scopeType]]);
                }
            }
        }
    }

    /**
     * @param string $packageName
     * @param array  $packageMeta
     * @return Package
     */
    private function add(string $packageName, array $packageMeta): Package
    {
        $current = json($packageMeta);

        /** @var Package $package */
        $package = $this->collection[$packageName] ?? new Package($packageName);

        $package
            ->setVersion((string)$current->get('version'))
            ->addRequire((array)$current->get('require'))
            ->addRequireDev((array)$current->get('require-dev'))
            ->addTags((array)$current->get('tags'));

        $this->collection[$packageName] = $package;

        return $package;
    }

    /**
     * @return Package
     */
    public function getMain(): Package
    {
        foreach ($this->collection as $package) {
            if ($package->isMain()) {
                return $package;
            }
        }

        throw new Exception('Main package not found');
    }

    /**
     * @param string $packageName
     * @return Package
     */
    public function getByName(string $packageName): Package
    {
        $package = $this->collection[$packageName] ?? null;
        if (!$package) {
            throw new Exception("Package \"{$packageName}\ not found in collection");
        }

        return $package;
    }
}