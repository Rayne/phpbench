<?php

/*
 * This file is part of the PHPBench package
 *
 * (c) Daniel Leech <daniel@dantleech.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace PhpBench\Serializer;

use PhpBench\Assertion\AssertionFailure;
use PhpBench\Dom\Document;
use PhpBench\Environment\Information;
use PhpBench\Model\Benchmark;
use PhpBench\Model\Error;
use PhpBench\Model\ParameterSet;
use PhpBench\Model\Subject;
use PhpBench\Model\Suite;
use PhpBench\Model\SuiteCollection;
use PhpBench\Model\Variant;
use PhpBench\PhpBench;
use PhpBench\Assertion\AssertionWarning;

/**
 * Encodes the Suite object graph into an XML document.
 */
class XmlDecoder
{
    /**
     * Decode a PHPBench XML document into a SuiteCollection.
     *
     * @param Document $document
     *
     * @return SuiteCollection
     */
    public function decode(Document $document)
    {
        $suites = [];
        foreach ($document->query('//suite') as $suiteEl) {
            $suites[] = $this->processSuite($suiteEl);
        }

        return new SuiteCollection($suites);
    }

    /**
     * Return a SuiteCollection from a number of PHPBench xml files.
     *
     * @param string[] $files
     *
     * @return SuiteCollection
     */
    public function decodeFiles(array $files)
    {
        // combine into one document.
        //
        $suiteDocument = new Document('phpbench');
        $rootEl = $suiteDocument->createRoot('phpbench');

        foreach ($files as $file) {
            $fileDom = new Document();
            $fileDom->load($file);

            foreach ($fileDom->query('./suite') as $suiteEl) {
                $importedEl = $suiteDocument->importNode($suiteEl, true);
                $rootEl->appendChild($importedEl);
            }
        }

        return $this->decode($suiteDocument);
    }

    private function processSuite(\DOMElement $suiteEl)
    {
        $suite = new Suite(
            $suiteEl->getAttribute('context'),
            new \DateTime($suiteEl->getAttribute('date')),
            $suiteEl->getAttribute('config-path'),
            [],
            [],
            $suiteEl->getAttribute('uuid')
        );

        $informations = [];
        foreach ($suiteEl->query('./env/*') as $envEl) {
            $name = $envEl->nodeName;
            $info = [];
            foreach ($envEl->attributes as $iName => $valueAttr) {
                $info[$iName] = $valueAttr->nodeValue;
            }

            $informations[$name] = new Information($name, $info);
        }

        $resultClasses = [];
        foreach ($suiteEl->query('//result') as $resultEl) {
            $class = $resultEl->getAttribute('class');

            if (!class_exists($class)) {
                throw new \RuntimeException(sprintf(
                    'XML file defines a non-existing result class "%s" - maybe you are missing an extension?',
                    $class
                ));
            }

            $resultClasses[$resultEl->getAttribute('key')] = $class;
        }

        $suite->setEnvInformations($informations);

        foreach ($suiteEl->query('./benchmark') as $benchmarkEl) {
            $benchmark = $suite->createBenchmark(
                $benchmarkEl->getAttribute('class')
            );

            $this->processBenchmark($benchmark, $benchmarkEl, $resultClasses);
        }

        return $suite;
    }

    private function processBenchmark(Benchmark $benchmark, \DOMElement $benchmarkEl, array $resultClasses)
    {
        foreach ($benchmarkEl->query('./subject') as $subjectEl) {
            $subject = $benchmark->createSubject($subjectEl->getAttribute('name'));
            $this->processSubject($subject, $subjectEl, $resultClasses);
        }
    }

    private function processSubject(Subject $subject, \DOMElement $subjectEl, array $resultClasses)
    {
        $groups = [];
        foreach ($subjectEl->query('./group') as $groupEl) {
            $groups[] = $groupEl->getAttribute('name');
        }
        $subject->setGroups($groups);

        // TODO: These attributes should be on the subject, see
        // https://github.com/phpbench/phpbench/issues/307
        foreach ($subjectEl->query('./variant') as $variantEl) {
            $subject->setSleep($variantEl->getAttribute('sleep'));
            $subject->setOutputTimeUnit($variantEl->getAttribute('output-time-unit'));
            $subject->setOutputTimePrecision($variantEl->getAttribute('output-time-precision'));
            $subject->setOutputMode($variantEl->getAttribute('output-mode'));
            $subject->setRetryThreshold($variantEl->getAttribute('retry-threshold'));
            break;
        }

        foreach ($subjectEl->query('./variant') as $index => $variantEl) {
            $parameters = $this->getParameters($variantEl);
            $parameterSet = new ParameterSet($index, $parameters);
            $stats = $this->getComputedStats($variantEl);
            $variant = $subject->createVariant($parameterSet, $variantEl->getAttribute('revs'), $variantEl->getAttribute('warmup'), $stats);
            $this->processVariant($variant, $variantEl, $resultClasses);
        }
    }

    private function getComputedStats(\DOMElement $element)
    {
        $stats = [];
        foreach ($element->query('./stats') as $statsEl) {
            foreach ($statsEl->attributes as $key => $attribute) {
                $stats[$key] = $attribute->nodeValue;
            }
        }

        return $stats;
    }

    private function getParameters(\DOMElement $element)
    {
        $parameters = [];
        foreach ($element->query('./parameter') as $parameterEl) {
            $name = $parameterEl->getAttribute('name');

            if ($parameterEl->getAttribute('type') === 'collection') {
                $parameters[$name] = $this->getParameters($parameterEl);
                continue;
            }

            $parameters[$name] = $parameterEl->getAttribute('value');
        }

        return $parameters;
    }

    private function processVariant(Variant $variant, \DOMElement $variantEl, array $resultClasses)
    {
        $errorEls = $variantEl->query('.//error');
        if ($errorEls->length) {
            $errors = [];
            foreach ($errorEls as $errorEl) {
                $error = new Error(
                    $errorEl->nodeValue,
                    $errorEl->getAttribute('exception-class'),
                    $errorEl->getAttribute('code'),
                    $errorEl->getAttribute('file'),
                    $errorEl->getAttribute('line'),
                    '' // we don't serialize the trace..
                );
                $errors[] = $error;
            }
            $variant->createErrorStack($errors);

            return;
        }

        $warningEls = $variantEl->query('.//warning');
        if ($warningEls->length) {
            $warnings = [];
            foreach ($warningEls as $warningEl) {
                $variant->addWarning(new AssertionWarning($warningEl->nodeValue));
            }
        }

        $failureEls = $variantEl->query('.//failure');
        if ($failureEls->length) {
            $failures = [];
            foreach ($failureEls as $failureEl) {
                $variant->addFailure(new AssertionFailure($failureEl->nodeValue));
            }
        }

        foreach ($variantEl->query('./iteration') as $iterationEl) {
            $results = [];

            foreach ($iterationEl->attributes as $attributeEl) {
                $name = $attributeEl->name;

                if (false === strpos($name, '-')) {
                    throw new \RuntimeException(sprintf(
                        'Expected attribute name to have a result key prefix, got "%s".',
                        $name
                    ));
                }

                $prefix = substr($name, 0, strpos($name, '-'));

                if (!isset($resultClasses[$prefix])) {
                    throw new \RuntimeException(sprintf(
                        'No result class was provided with key "%s" for attribute "%s"',
                        $prefix, $name
                    ));
                }

                $suffix = substr($name, strpos($name, '-') + 1);
                $results[$prefix][str_replace('-', '_', $suffix)] = $attributeEl->value;
            }

            $iteration = $variant->createIteration();
            foreach ($results as $resultKey => $resultData) {
                $iteration->setResult(call_user_func_array([
                    $resultClasses[$resultKey],
                    'fromArray',
                ], [$resultData]));
            }
        }

        // TODO: Serialize statistics ..
        $variant->computeStats();
    }
}
