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

namespace Elastic\Apm\Impl;

use Closure;
use Elastic\Apm\CustomErrorData;
use Elastic\Apm\DistributedTracingData;
use Elastic\Apm\ExecutionSegmentInterface;
use Elastic\Apm\Impl\BreakdownMetrics\SelfTimeTracker as BreakdownMetricsSelfTimeTracker;
use Elastic\Apm\Impl\Log\LogCategory;
use Elastic\Apm\Impl\Log\LoggableInterface;
use Elastic\Apm\Impl\Log\LoggableTrait;
use Elastic\Apm\Impl\Log\Logger;
use Elastic\Apm\Impl\Util\ClassNameUtil;
use Elastic\Apm\Impl\Util\IdGenerator;
use Elastic\Apm\Impl\Util\TextUtil;
use Elastic\Apm\Impl\Util\TimeUtil;
use Elastic\Apm\SpanInterface;
use Throwable;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
abstract class ExecutionSegment implements ExecutionSegmentInterface, LoggableInterface
{
    use LoggableTrait;

    /** @var bool */
    protected $isDiscarded = false;

    /** @var ?BreakdownMetricsSelfTimeTracker */
    protected $breakdownMetricsSelfTimeTracker = null;

    /** @var float */
    private $diffStartTimeWithSystemClockOnBeginInMicroseconds;

    /** @var float Monotonic time since some unspecified starting point, in microseconds */
    private $monotonicBeginTime;

    /** @var float */
    private $systemClockBeginTime;

    /** @var Logger */
    private $logger;

    /** @var bool */
    private $isEnded = false;

    /** @var ExecutionSegmentData */
    private $data;

    protected function __construct(
        ExecutionSegmentData $data,
        Tracer $tracer,
        ?ExecutionSegment $parentExecutionSegment,
        string $traceId,
        string $name,
        string $type,
        ?float $sampleRate,
        ?float $timestampArg = null
    ) {
        $clock = $tracer->getClock();
        $monotonicClockNow = $clock->getMonotonicClockCurrentTime();
        $systemClockNow = $clock->getSystemClockCurrentTime();

        $this->data = $data;
        $this->data->id = IdGenerator::generateId(Constants::EXECUTION_SEGMENT_ID_SIZE_IN_BYTES);

        $this->systemClockBeginTime = $systemClockNow;
        if ($timestampArg === null) {
            $this->data->timestamp = $systemClockNow;
        } elseif ($timestampArg <= $systemClockNow) {
            $this->data->timestamp = $timestampArg;
        } else {
            $this->data->timestamp = $systemClockNow;

            $localLogger = $tracer->loggerFactory()
                                  ->loggerForClass(LogCategory::PUBLIC_API, __NAMESPACE__, __CLASS__, __FILE__);
            ($loggerProxy = $localLogger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log(
                'Using systemClockNow for start time instead of timestampArg argument'
                . ' because timestampArg argument is later (further into the future) than systemClockNow',
                [
                    'systemClockNow' => $systemClockNow,
                    'timestampArg'   => $timestampArg,
                    'timestampArg - systemClockNow (seconds)'
                                     => TimeUtil::microsecondsToSeconds($timestampArg - $systemClockNow),
                    'id'             => $this->data->id,
                    'name'           => $name,
                    'type'           => $type,
                ]
            );
        }
        $this->diffStartTimeWithSystemClockOnBeginInMicroseconds
            = TimeUtil::calcDurationInMicrosecondsClampNegativeToZero($this->data->timestamp, $systemClockNow);
        $this->monotonicBeginTime = $monotonicClockNow;
        $this->data->traceId = $traceId;
        $this->setName($name);
        $this->setType($type);
        $this->data->sampleRate = $sampleRate;

        if ($this->containingTransaction()->getConfig()->breakdownMetrics()) {
            $this->breakdownMetricsSelfTimeTracker = new BreakdownMetricsSelfTimeTracker($monotonicClockNow);
            if ($parentExecutionSegment !== null) {
                /**
                 * If breakdownMetrics config is true then all transaction's spans
                 * breakdownMetricsSelfTimeTracker should not be null
                 *
                 * Local variable to workaround PHPStan not having a way to declare that
                 * $parentExecutionSegment->breakdownMetricsSelfTimeTracker is not null
                 *
                 * @var BreakdownMetricsSelfTimeTracker $parentBreakdownMetricsSelfTimeTracker
                 */
                $parentBreakdownMetricsSelfTimeTracker = $parentExecutionSegment->breakdownMetricsSelfTimeTracker;
                $parentBreakdownMetricsSelfTimeTracker->onChildBegin($monotonicClockNow);
            }
        }

        $this->logger = $tracer->loggerFactory()
                               ->loggerForClass(LogCategory::PUBLIC_API, __NAMESPACE__, __CLASS__, __FILE__)
                               ->addContext('this', $this);
        ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log(
            'Exiting...',
            [
                'systemClockNow' => $systemClockNow,
                'timestampArg'   => $timestampArg,
                'systemClockNow - timestampArg (seconds)'
                                 => TimeUtil::microsecondsToSeconds($systemClockNow - $timestampArg),
            ]
        );
    }

    /**
     * @return array<string>
     */
    protected static function propertiesExcludedFromLog(): array
    {
        return ['tracer'];
    }

    public function isSampled(): bool
    {
        return $this->containingTransaction()->isSampled();
    }

    /**
     * @return Transaction
     */
    abstract public function containingTransaction(): Transaction;

    /**
     * @return ExecutionSegment|null
     */
    abstract public function parentExecutionSegment(): ?ExecutionSegment;

    public function getName(): string
    {
        return $this->data->name;
    }

    public function getType(): string
    {
        return $this->data->type;
    }

    /**
     * Begins a new span with this execution segment as the new span's parent,
     * runs the provided callback as the new span and automatically ends the new span.
     *
     * @param string      $name      New span's name
     * @param string      $type      New span's type
     * @param Closure     $callback  Callback to execute as the new span
     * @param string|null $subtype   New span's subtype
     * @param string|null $action    New span's action
     * @param float|null  $timestamp Start time of the new span
     *
     * @param int         $numberOfStackFramesToSkip
     *
     * @return mixed The return value of $callback
     *
     * @template        T
     * @phpstan-param   Closure(SpanInterface $newSpan): T $callback
     * @phpstan-return  T
     *
     * @see             SpanInterface
     *
     * @noinspection    PhpDocMissingThrowsInspection
     */
    protected function captureChildSpanImpl(
        string $name,
        string $type,
        Closure $callback,
        ?string $subtype,
        ?string $action,
        ?float $timestamp,
        int $numberOfStackFramesToSkip
    ) {
        $newSpan = $this->beginChildSpan(
            $name,
            $type,
            $subtype,
            $action,
            $timestamp
        );
        try {
            return $callback($newSpan);
        } catch (Throwable $throwable) {
            $newSpan->createErrorFromThrowable($throwable);
            /** @noinspection PhpUnhandledExceptionInspection */
            throw $throwable;
        } finally {
            // Since endSpanEx was not called directly it should not be kept in the stack trace
            $newSpan->endSpanEx(/* numberOfStackFramesToSkip: */ $numberOfStackFramesToSkip + 1);
        }
    }

    /**
     * @param ErrorExceptionData $errorExceptionData
     *
     * @return string|null
     */
    abstract public function dispatchCreateError(ErrorExceptionData $errorExceptionData): ?string;

    private function createError(?CustomErrorData $customErrorData, ?Throwable $throwable): ?string
    {
        return $this->dispatchCreateError(
            ErrorExceptionData::build(
                $this->containingTransaction()->tracer(),
                $customErrorData,
                $throwable
            )
        );
    }

    /** @inheritDoc */
    public function createErrorFromThrowable(Throwable $throwable): ?string
    {
        return $this->createError(/* customErrorData: */ null, $throwable);
    }

    /** @inheritDoc */
    public function createCustomError(CustomErrorData $customErrorData): ?string
    {
        return $this->createError($customErrorData, /* throwable: */ null);
    }

    public function beforeMutating(): bool
    {
        if (!$this->isEnded) {
            return false;
        }

        if ($this->isDiscarded) {
            return true;
        }

        ($loggerProxy = $this->logger->ifWarningLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->includeStacktrace()->log('A mutating method has been called on already ended event');

        return true;
    }

    /** @inheritDoc */
    public function isNoop(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function getId(): string
    {
        return $this->data->id;
    }

    /** @inheritDoc */
    public function getTimestamp(): float
    {
        return $this->data->timestamp;
    }

    /** @inheritDoc */
    public function getTraceId(): string
    {
        return $this->data->traceId;
    }

    /** @inheritDoc */
    public function setName(string $name): void
    {
        if ($this->beforeMutating()) {
            return;
        }

        $this->data->name = Tracer::limitKeywordString($name);
    }

    /** @inheritDoc */
    public function setType(string $type): void
    {
        if ($this->beforeMutating()) {
            return;
        }

        $this->data->type = TextUtil::isEmptyString($type)
            ? Constants::EXECUTION_SEGMENT_TYPE_DEFAULT
            : Tracer::limitKeywordString($type);
    }

    /** @inheritDoc */
    public function getDistributedTracingData(): ?DistributedTracingData
    {
        return $this->getDistributedTracingDataInternal();
    }

    /**
     * Returns distributed tracing data
     */
    abstract public function getDistributedTracingDataInternal(): ?DistributedTracingDataInternal;

    /** @inheritDoc */
    public function injectDistributedTracingHeaders(Closure $headerInjector): void
    {
        /** @noinspection PhpDeprecationInspection */
        $distTracingData = $this->getDistributedTracingData();
        if ($distTracingData !== null) {
            $distTracingData->injectHeaders($headerInjector);
        }
    }

    public static function isValidOutcome(?string $outcome): bool
    {
        return $outcome === null
               || $outcome === Constants::OUTCOME_SUCCESS
               || $outcome === Constants::OUTCOME_FAILURE
               || $outcome === Constants::OUTCOME_UNKNOWN;
    }

    /** @inheritDoc */
    public function setOutcome(?string $outcome): void
    {
        if (!self::isValidOutcome($outcome)) {
            ($loggerProxy = $this->logger->ifErrorLevelEnabled(__LINE__, __FUNCTION__))
            && $loggerProxy->log('Given outcome value is invalid', ['outcome' => $outcome]);
            return;
        }

        $this->data->outcome = $outcome;
    }

    /** @inheritDoc */
    public function getOutcome(): ?string
    {
        return $this->data->outcome;
    }

    /** @inheritDoc */
    public function discard(): void
    {
        if ($this->beforeMutating()) {
            return;
        }

        $this->isDiscarded = true;
        $this->end();
    }

    protected function endExecutionSegment(?float $durationArg = null): bool
    {
        if ($this->isDiscarded) {
            $this->isEnded = true;
            return false;
        }

        if ($this->beforeMutating()) {
            return false;
        }

        $clock = $this->containingTransaction()->tracer()->getClock();
        $monotonicClockNow = $clock->getMonotonicClockCurrentTime();
        if ($durationArg === null) {
            $systemClockNow = $clock->getSystemClockCurrentTime();
            $monotonicDurationInMicroseconds = TimeUtil::calcDurationInMicrosecondsClampNegativeToZero(
                $this->monotonicBeginTime,
                $monotonicClockNow
            );
            $systemClockDurationInMicroseconds = TimeUtil::calcDurationInMicrosecondsClampNegativeToZero(
                $this->systemClockBeginTime,
                $systemClockNow
            );
            if ($monotonicDurationInMicroseconds < $systemClockDurationInMicroseconds) {
                $durationAfterBeginInMicroseconds = $systemClockDurationInMicroseconds;
                ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
                && $loggerProxy->log(
                    'Using systemClockDurationInMicroseconds as durationAfterBegin'
                    . ' instead of monotonicDurationInMicroseconds '
                    . ' because systemClockDurationInMicroseconds is larger than monotonicDurationInMicroseconds',
                    [
                        'systemClockDurationInMicroseconds' => $systemClockDurationInMicroseconds,
                        'monotonicDurationInMicroseconds'   => $monotonicDurationInMicroseconds,
                        'systemClockDurationInMicroseconds - monotonicDurationInMicroseconds (seconds)'
                                                            => TimeUtil::microsecondsToSeconds(
                                                                $systemClockDurationInMicroseconds
                                                                - $monotonicDurationInMicroseconds
                                                            ),
                        'systemClockNow'                    => $systemClockNow,
                        'monotonicClockNow'                 => $monotonicClockNow,
                    ]
                );
            } else {
                $durationAfterBeginInMicroseconds = $monotonicDurationInMicroseconds;
                ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
                && $loggerProxy->log(
                    'Using monotonicDurationInMicroseconds as durationAfterBegin',
                    [
                        'monotonicDurationInMicroseconds'   => $monotonicDurationInMicroseconds,
                        'systemClockDurationInMicroseconds' => $systemClockDurationInMicroseconds,
                        'monotonicDurationInMicroseconds - systemClockDurationInMicroseconds (seconds)'
                                                            => TimeUtil::microsecondsToSeconds(
                                                                $monotonicDurationInMicroseconds
                                                                - $systemClockDurationInMicroseconds
                                                            ),
                        'monotonicClockNow'                 => $monotonicClockNow,
                        'systemClockNow'                    => $systemClockNow,
                    ]
                );
            }
            $this->data->duration = TimeUtil::microsecondsToMilliseconds(
                $this->diffStartTimeWithSystemClockOnBeginInMicroseconds + $durationAfterBeginInMicroseconds
            );
        } else {
            $this->data->duration = $durationArg;
        }

        if ($this->breakdownMetricsSelfTimeTracker !== null && !$this->containingTransaction()->hasEnded()) {
            $this->updateBreakdownMetricsOnEnd($monotonicClockNow);
        }

        $this->isEnded = true;

        ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log(ClassNameUtil::fqToShort(get_class($this)) . ' ended', ['durationArg' => $durationArg]);

        return true;
    }

    /**
     * @param float $monotonicClockNow
     *
     * @return void
     */
    abstract protected function updateBreakdownMetricsOnEnd(float $monotonicClockNow): void;

    protected function doUpdateBreakdownMetricsOnEnd(
        float $monotonicClockNow,
        string $spanType,
        ?string $spanSubtype
    ): void {
        /**
         * @var BreakdownMetricsSelfTimeTracker $breakdownMetricsSelfTimeTracker
         *
         * doUpdateBreakdownMetricsOnEnd is called only if breakdownMetricsSelfTimeTracker is not null
         */
        $breakdownMetricsSelfTimeTracker = $this->breakdownMetricsSelfTimeTracker;

        $breakdownMetricsSelfTimeTracker->end($monotonicClockNow);
        $this->containingTransaction()->addSpanSelfTime(
            $spanType,
            $spanSubtype,
            $breakdownMetricsSelfTimeTracker->accumulatedSelfTimeInMicroseconds()
        );

        $parentExecutionSegment = $this->parentExecutionSegment();
        if ($parentExecutionSegment !== null) {
            /**
             * doUpdateBreakdownMetricsOnEnd is called only if breakdownMetricsSelfTimeTracker is not null
             *
             * Local variable to workaround PHPStan not having a way to declare that
             * $parentExecutionSegment->breakdownMetricsSelfTimeTracker is not null
             *
             * @var BreakdownMetricsSelfTimeTracker $parentBreakdownMetricsSelfTimeTracker
             */
            $parentBreakdownMetricsSelfTimeTracker = $parentExecutionSegment->breakdownMetricsSelfTimeTracker;
            $parentBreakdownMetricsSelfTimeTracker->onChildEnd($monotonicClockNow);
        }
    }

    /** @inheritDoc */
    public function hasEnded(): bool
    {
        return $this->isEnded;
    }
}
