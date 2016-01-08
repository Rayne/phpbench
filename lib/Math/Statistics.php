<?php

/*
 * This file is part of the PHPBench package
 *
 * (c) Daniel Leech <daniel@dantleech.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpBench\Math;

/**
 * Static class containing functions related to statistics.
 */
class Statistics
{
    /**
     * Return the standard deviation of a given population.
     *
     * @param \DOMNodeList $nodeList
     * @param bool $sample
     *
     * @return float
     */
    public static function stdev(array $values, $sample = false)
    {
        $variance = self::variance($values, $sample);

        return \sqrt($variance);
    }

    /**
     * Return the variance for a given population.
     *
     * @param array $values
     * @param bool $sample
     *
     * @return float
     */
    public static function variance(array $values, $sample = false)
    {
        $average = self::mean($values);
        $sum = 0;
        foreach ($values as $value) {
            $diff = pow($value - $average, 2);
            $sum += $diff;
        }

        if (count($values) === 0) {
            return 0;
        }

        $variance = $sum / (count($values) - ($sample ? 1 : 0));

        return $variance;
    }

    /**
     * Return the mean (average) value of the given values.
     *
     * @param array $values
     *
     * @return mixed
     */
    public static function mean($values)
    {
        if (empty($values)) {
            return 0;
        }

        $sum = array_sum($values);

        if (0 == $sum) {
            return 0;
        }

        $count = count($values);

        return $sum / $count;
    }

    /**
     * Return the mode using the kernel density estimator using the normal
     * distribution.
     *
     * The mode is the point in the kernel density estimate with the highest
     * frequency, i.e. the time which correlates with the highest peak.
     *
     * If there are two or more modes (i.e. bimodal, trimodal, etc) then we
     * could take the average of these modes.
     *
     * NOTE: If the kde estimate of the population is multi-modal (it has two
     * points with exactly the same value) then the mean mode is returned. This
     * is potentially misleading, but When benchmarking this should be a very
     * rare occurance.
     *
     * @param array $population
     * @param int $space
     * @param float $bandwidth
     *
     * @return float[]
     */
    public static function kdeMode(array $population, $space = 512, $bandwidth = null)
    {
        if (count($population) === 1) {
            return current($population);
        }

        if (count($population) === 0) {
            return 0;
        }

        if (min($population) == max($population)) {
            return min($population);
        }

        $kde = new Kde($population, $bandwidth);
        $space = self::linspace(min($population), max($population), $space, true);
        $dist = $kde->evaluate($space);

        $maxKeys = array_keys($dist, max($dist));
        $modes = array();

        foreach ($maxKeys as $maxKey) {
            $modes[] = $space[$maxKey];
        }

        $mode = array_sum($modes) / count($modes);

        return $mode;
    }

    /**
     * Return an array populated with $num numbers from $min to $max.
     *
     * @param float $min
     * @param float $max
     * @param int $num
     * @param bool $endpoint
     *
     * @return float[]
     */
    public static function linspace($min, $max, $num = 50, $endpoint = true)
    {
        $range = $max - $min;

        if ($max == $min) {
            throw new \InvalidArgumentException(sprintf(
                'Min and max cannot be the same number: %s', $max
            ));
        }

        $unit = $range / ($endpoint ? $num - 1 : $num);
        $space = array();

        for ($value = $min; $value <= $max; $value += $unit) {
            $space[] = $value;
        }

        if ($endpoint === false) {
            array_pop($space);
        }

        return $space;
    }
}
