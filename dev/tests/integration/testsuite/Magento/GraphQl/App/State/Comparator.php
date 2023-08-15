<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\App\State;

/**
 * Compare object state between requests and between first instantiation by ObjectManager
 */
class Comparator
{
    /** @var CollectedObject[] */
    private array $objectsStateBefore = [];

    /**
     * @var CollectedObject[]
     */
    private array $objectsStateAfter = [];

    public function __construct(
        private readonly Collector $collector,
        private readonly SkipListAndFilterList $skipListAndFilterList
    ) {
    }

    /**
     * Remember shared object state before request
     *
     * @param bool $firstRequest
     * @throws \Exception
     */
    public function rememberObjectsStateBefore(bool $firstRequest): void
    {
        if ($firstRequest) {
            $this->objectsStateBefore = $this->collector->getSharedObjects(ShouldResetState::DoNotResetState);
        }
    }

    /**
     * Remember shared object state after request
     *
     * @param bool $firstRequest
     * @throws \Exception
     */
    public function rememberObjectsStateAfter(bool $firstRequest): void
    {
        $this->objectsStateAfter = $this->collector->getSharedObjects(ShouldResetState::DoResetState);
        if ($firstRequest) {
            // on the end of first request add objects to init object state pool
            $this->objectsStateBefore = array_merge($this->objectsStateAfter, $this->objectsStateBefore);
        }
    }

    /**
     * Compare objectsStateAfter with objectsStateBefore
     *
     * @param string $operationName
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function compareBetweenRequests(string $operationName): array
    {
        $compareResults = [];
        $skipList = $this->skipListAndFilterList->getSkipList($operationName, CompareType::CompareBetweenRequests);
        foreach ($this->objectsStateAfter as $serviceName => $afterCollectedObject) {
            if (array_key_exists($serviceName, $skipList)) {
                continue;
            }
            $objectState = [];
            if (!isset($this->objectsStateBefore[$serviceName])) {
                $compareResults[$serviceName] = 'new object appeared after first request';
                continue;
            }
            $beforeCollectedObject = $this->objectsStateBefore[$serviceName];
            $objectState =
                $this->compare($beforeCollectedObject, $afterCollectedObject, $skipList, $serviceName);
            if ($objectState) {
                $compareResults[$serviceName] = $objectState;
            }
        }
        return $compareResults;
    }

    /**
     * Compares current objects created by Object Manager against how they were when originally constructed
     *
     * @param string $operationName
     * @return array
     */
    public function compareConstructedAgainstCurrent(string $operationName): array
    {
        $compareResults = [];
        $skipList = $this->skipListAndFilterList
            ->getSkipList($operationName, CompareType::CompareConstructedAgainstCurrent);
        foreach ($this->collector->getPropertiesConstructedAndCurrent() as $objectAndProperties) {
            $object = $objectAndProperties->getObject();
            $constructedObject = $objectAndProperties->getConstructedCollected();
            $currentObject = $objectAndProperties->getCurrentCollected();
            if ($object instanceof \Magento\Framework\ObjectManager\NoninterceptableInterface) {
                /* All Proxy classes use NoninterceptableInterface.  We skip them because for the Proxies that are
                loaded, we compare the actual loaded objects. */
                continue;
            }
            $className = get_class($object);
            if (array_key_exists($className, $skipList)) {
                continue;
            }
            $objectState = $this->compare($constructedObject, $currentObject, $skipList);
            if ($objectState) {
                $compareResults[$className] = $objectState;
            }
        }
        return $compareResults;
    }

    /**
     * Recursively compares objects.
     *
     * @param CollectedObject $before
     * @param CollectedObject $after
     * @param array $skipList
     * @param string $serviceName
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function compare(
        CollectedObject $before,
        CollectedObject $after,
        array $skipList,
        string $serviceName = '',
    ) : array {
        $skippedObject = CollectedObject::getSkippedObject();
        if ($skippedObject === $before || $skippedObject === $after) {
            return []; // skipped
        }
        if (array_key_exists($before->getClassName(), $skipList)
            && array_key_exists($after->getClassName(), $skipList)) {
            return []; // This object should be skipped
        }
        if (!$serviceName) {
            $serviceName = $before->getClassName();
        }
        $propertiesToFilterList = $this->skipListAndFilterList->getFilterListByClassNameAndServiceName(
            $before->getClassName(),
            $serviceName,
        );
        $propertiesBefore = $this->skipListAndFilterList
            ->filterProperties($before->getProperties(), $propertiesToFilterList);
        $propertiesAfter = $this->skipListAndFilterList
            ->filterProperties($after->getProperties(), $propertiesToFilterList);
        $objectState = [];
        foreach ($propertiesAfter as $propertyName => $propertyValue) {
            $result = $this->checkValues($propertiesBefore[$propertyName] ?? null, $propertyValue, $skipList);
            if ($result) {
                $objectState[$propertyName] = $result;
            }
        }
        // Check for properties that exist in before, but not after. (this is very rare)
        foreach ($propertiesBefore as $propertyName => $propertyValue) {
            if (!array_key_exists($propertyName, $propertiesAfter)) {
                $result = $this->checkValues($propertyValue, null, $skipList);
                if ($result) {
                    $objectState[$propertyName] = $result;
                }
            }
        }
        if ($objectState) {
            return [
                'objectClassBefore' => $before->getClassName(),
                'objectClassAfter' => $after->getClassName(),
                'properties' => $objectState,
                'objectIdBefore' => $before->getObjectId(),
                'objectIdAfter' => $after->getObjectId(),
            ];
        }
        return [];
    }

    /**
     * Formats value by type
     *
     * @param mixed $value
     * @return mixed
     */
    private function formatValue($value): mixed
    {
        if (is_object($value)) {
            if ($value instanceof CollectedObject) {
                return $value->getClassName();
            }
            return $value ? get_class($value) : 'NULL';
        } elseif (is_array($value)) {
            $data = [];
            foreach ($value as $key => $value2) {
                $data[$key] = $this->formatValue($value2);
            }
            return $data;
        }
        return $value;
    }

    /**
     * Compares the values, returns the differences.
     *
     * @param mixed $before
     * @param mixed $after
     * @param array $skipList
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function checkValues(mixed $before, mixed $after, array $skipList): array
    {
        $skippedObject = CollectedObject::getSkippedObject();
        if ($skippedObject === $before || $skippedObject === $after) {
            return []; // skipped
        }
        $typeBefore = gettype($before);
        $typeAfter = gettype($after);
        if ($typeBefore !== $typeAfter) {
            return [
                'before' => $this->formatValue($before),
                'after' => $this->formatValue($after),
            ];
        }
        switch ($typeBefore) {
            case 'boolean':
            case 'integer':
            case 'double':
            case 'string':
                if ($before !== $after) {
                    return ['before' => $before, 'after' => $after];
                }
                return [];
            case 'array':
                if (count($before) !== count($after) || $before != $after) {
                    $results = [];
                    $keysChecked = [];
                    foreach ($after as $key => $valueAfter) {
                        $result = $this->checkValues($before[$key] ?? null, $valueAfter, $skipList);
                        if ($result) {
                            $results[$key] = $result;
                        }
                        $keysChecked[$key] = true;
                    }
                    // Checking array keys that were in $before, but not $after
                    foreach ($before as $key => $valueAfter) {
                        if ($keysChecked[$key] ?? false) {
                            continue;
                        }
                        $result = $this->checkValues($before[$key] ?? null, $valueAfter, $skipList);
                        if ($result) {
                            $results[$key] = $result;
                        }
                    }
                    return $results;
                }
                return [];
            case 'object':
                if ($before instanceof CollectedObject) {
                    return $this->compare(
                        $before,
                        $after,
                        $skipList,
                    );
                }
                throw new \Exception("Unexpected object in checkValues()");
        }
        return [];
    }
}
