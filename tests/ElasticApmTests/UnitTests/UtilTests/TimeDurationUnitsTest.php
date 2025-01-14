<?php

/*
 * Licensed to Elasticsearch B.V. under one or more contributor
 * license agreements. See the NOTICE file distributed with
 * this work for additional information regarding copyright
 * ownership. Elasticsearch B.V. licenses this file to you under
 * the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations
 * under the License.
 */

declare(strict_types=1);

namespace ElasticApmTests\UnitTests\UtilTests;

use Elastic\Apm\Impl\Config\DurationUnits;
use Elastic\Apm\Impl\Util\TextUtil;
use PHPUnit\Framework\TestCase;

class TimeDurationUnitsTest extends TestCase
{
    public function testSuffixAndIdIsInDescendingOrderOfSuffixLength(): void
    {
        /** @var int|null */
        $prevSuffixLength = null;
        foreach (DurationUnits::$suffixAndIdPairs as $suffixAndIdPair) {
            $suffix = $suffixAndIdPair[0];
            $suffixLength = strlen($suffix);
            if ($prevSuffixLength !== null) {
                self::assertLessThanOrEqual($prevSuffixLength, $suffixLength);
            }
            $prevSuffixLength = $suffixLength;
        }
    }
}
