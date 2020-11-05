<?php

declare(strict_types=1);

namespace ElasticApmTests\UnitTests\LogTests;

use Elastic\Apm\Impl\Log\LogConsts;
use Elastic\Apm\Impl\Log\LoggableToEncodedJson;
use Elastic\Apm\Impl\NoopSpan;
use Elastic\Apm\Impl\NoopTransaction;
use Elastic\Apm\Impl\Tracer;
use Elastic\Apm\Impl\TracerDependencies;
use Elastic\Apm\Impl\Transaction;
use Elastic\Apm\Impl\Util\JsonUtil;
use ElasticApmTests\Util\FloatLimits;
use ElasticApmTests\Util\LogSinkForTests;
use ElasticApmTests\Util\TestCaseBase;
use PHPUnit\Framework\TestCase;

class LoggingVariousTypesTest extends TestCaseBase
{
    /**
     * @param mixed $valueToLog
     *
     * @return mixed
     */
    public static function logValueAndDecodeToJson($valueToLog)
    {
        return JsonUtil::decode(LoggableToEncodedJson::convert($valueToLog), /* asAssocArray */ true);
    }

    /**
     * @param mixed $valueToLog
     * @param mixed $expectedValue
     */
    public static function logValueAndVerify($valueToLog, $expectedValue): void
    {
        $actualValue = self::logValueAndDecodeToJson($valueToLog);
        if (is_float($expectedValue) && is_int($actualValue)) {
            $actualValue = floatval($actualValue);
        }

        if (is_array($expectedValue)) {
            TestCase::assertEquals($expectedValue, $actualValue);
        } else {
            TestCase::assertSame($expectedValue, $actualValue);
        }
    }

    public function testNull(): void
    {
        self::logValueAndVerify(null, null);
    }

    public function testBool(): void
    {
        foreach ([false, true] as $value) {
            self::logValueAndVerify($value, $value);
        }
    }

    public function testInt(): void
    {
        foreach ([0, 1, -1, 123, -654, PHP_INT_MAX, PHP_INT_MIN] as $value) {
            self::logValueAndVerify($value, $value);
        }
    }

    public function testFloat(): void
    {
        $valuesToTest = [0.0, 1.1, -2.5, 4987.41, -654.112255];
        $valuesToTest += [floatval(PHP_INT_MAX), floatval(PHP_INT_MIN)];
        $valuesToTest += [FloatLimits::MIN, FloatLimits::MAX, PHP_FLOAT_MIN];
        foreach ($valuesToTest as $value) {
            self::logValueAndVerify($value, $value);
        }
    }

    public function testString(): void
    {
        $valuesToTest = ['', 'a', 'ABC', "@#$%&*()<>{}[]+-=_~^ \t\r\n,:;.!?"];
        foreach ($valuesToTest as $value) {
            self::logValueAndVerify($value, $value);
        }
    }

    // public function testResource(): void
    // {
    //     // TODO: Sergey Kleyman: Implement: LoggingVariousTypesTest::testResource
    //     // $tmpFile = tmpfile()
    //     // self::logValueAndVerify(null, null);
    // }
    //
    // public function testListArray(): void
    // {
    //     // TODO: Sergey Kleyman: Implement: LoggingVariousTypesTest::testListArray
    //     // new SimpleObjectForTests()
    // }
    //
    // public function testMapArray(): void
    // {
    //     // TODO: Sergey Kleyman: Implement: LoggingVariousTypesTest::testMapArray
    //     // new SimpleObjectForTests()
    // }

    /**
     * @param string|null $className
     * @param bool        $isPropExcluded
     *
     * @return array<string, mixed>
     */
    private static function expectedSimpleObject(?string $className = null, bool $isPropExcluded = true): array
    {
        return (is_null($className) ? [] : [LogConsts::TYPE_KEY => $className])
               + [
                   'intProp'            => 123,
                   'stringProp'         => 'Abc',
                   'nullableStringProp' => null,
               ]
               + ($isPropExcluded ? [] : ['excludedProp' => 'excludedProp value']);
    }

    /**
     * @param string|null $className
     * @param bool        $isPropExcluded
     *
     * @return array<string, mixed>
     */
    private static function expectedDerivedSimpleObject(?string $className = null, bool $isPropExcluded = true): array
    {
        return self::expectedSimpleObject($className, $isPropExcluded)
               + ['derivedFloatProp' => 1.5]
               + ($isPropExcluded ? [] : ['anotherExcludedProp' => 'anotherExcludedProp value']);
    }

    public function tearDown(): void
    {
        ObjectForLoggableTraitTests::logWithoutClassName();
        ObjectForLoggableTraitTests::shouldExcludeProp();
        DerivedObjectForLoggableTraitTests::logWithoutClassName();
        DerivedObjectForLoggableTraitTests::shouldExcludeProp();
        parent::tearDown();
    }

    public function testObject(): void
    {
        self::logValueAndVerify(new ObjectForLoggableTraitTests(), self::expectedSimpleObject());

        ObjectForLoggableTraitTests::logWithShortClassName();

        self::logValueAndVerify(
            new ObjectForLoggableTraitTests(),
            self::expectedSimpleObject(/* className */ 'ObjectForLoggableTraitTests')
        );

        ObjectForLoggableTraitTests::logWithCustomClassName('My-custom-type');

        self::logValueAndVerify(
            new ObjectForLoggableTraitTests(),
            self::expectedSimpleObject(/* className */ 'My-custom-type')
        );

        ObjectForLoggableTraitTests::logWithoutClassName();
        ObjectForLoggableTraitTests::shouldExcludeProp(false);

        self::logValueAndVerify(
            new ObjectForLoggableTraitTests(),
            self::expectedSimpleObject(/* className */ null, /* isPropExcluded */ false)
        );
    }

    public function testDerivedObject(): void
    {
        self::logValueAndVerify(new DerivedObjectForLoggableTraitTests(), self::expectedDerivedSimpleObject());

        DerivedObjectForLoggableTraitTests::logWithShortClassName();

        self::logValueAndVerify(
            new DerivedObjectForLoggableTraitTests(),
            self::expectedDerivedSimpleObject(/* className */ 'DerivedObjectForLoggableTraitTests')
        );

        DerivedObjectForLoggableTraitTests::logWithCustomClassName('My-custom-type');

        self::logValueAndVerify(
            new DerivedObjectForLoggableTraitTests(),
            self::expectedDerivedSimpleObject(/* className */ 'My-custom-type')
        );

        DerivedObjectForLoggableTraitTests::logWithoutClassName();
        DerivedObjectForLoggableTraitTests::shouldExcludeProp(false);

        self::logValueAndVerify(
            new DerivedObjectForLoggableTraitTests(),
            self::expectedDerivedSimpleObject(/* className */ null, /* isPropExcluded */ false)
        );
    }

    // public function testObjectWithThrowingToString(): void
    // {
    //     // TODO: Sergey Kleyman: Implement: LoggingVariousTypesTest::testObjectWithThrowingToString
    //     // new SimpleObjectForTests()
    // }
    //
    // public function testObjectWithDebugInfo(): void
    // {
    //     // TODO: Sergey Kleyman: Implement: LoggingVariousTypesTest::testObjectWithThrowingToString
    //     // new SimpleObjectForTests()
    // }
    //
    // public function testThrowable(): void
    // {
    //     // TODO: Sergey Kleyman: Implement: LoggingVariousTypesTest::testObjectWithThrowingToString
    //     // new SimpleObjectForTests()
    // }

    public function testNoopTransaction(): void
    {
        self::logValueAndVerify(NoopTransaction::singletonInstance(), [LogConsts::TYPE_KEY => 'NoopTransaction']);
        self::logValueAndVerify(NoopSpan::singletonInstance(), [LogConsts::TYPE_KEY => 'NoopSpan']);
    }
}