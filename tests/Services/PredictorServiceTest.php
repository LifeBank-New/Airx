<?php

namespace Tests\Services;

use PHPUnit\Framework\TestCase;
use App\Services\PredictorService;

class PredictorServiceTest extends TestCase
{
    /** @var PredictorService */
    private $predictorService;

    protected function setUp()
    {
        parent::setUp();
        $this->predictorService = new PredictorService();
    }

    public function testCalculateNeedsWithStandardParameters()
    {
        $params = [
            'pediatric' => 2,
            'malaria'   => 1,
            'intensive' => 1,
            'accident'  => 1,
            'theatre'   => 1,
            'maternity' => 1,
            'typhoid'   => 0,
            'diabetes'  => 0
        ];

        $needs = $this->predictorService->calculateNeeds($params);

        $this->assertGreaterThan(0.0, $needs);
        $this->assertInternalType('float', $needs);
    }

    public function testCalculateNeedsClampsNegativeValuesToZero()
    {
        $params = [
            'pediatric' => -10,
            'malaria'   => -5,
            'typhoid'   => 100, // Large negative weight
            'diabetes'  => 100
        ];

        $needs = $this->predictorService->calculateNeeds($params);

        // Must clamp to 0.0, never negative
        $this->assertEquals(0.0, $needs);
    }

    public function testCalculateNeedsSupportsBothSpellingVariants()
    {
        $legacyParams = [
            'peaditric'  => 4,
            'materinity' => 2
        ];

        $modernParams = [
            'pediatric' => 4,
            'maternity' => 2
        ];

        $legacyResult = $this->predictorService->calculateNeeds($legacyParams);
        $modernResult = $this->predictorService->calculateNeeds($modernParams);

        $this->assertEquals($legacyResult, $modernResult);
    }

    public function testPredictMonthlyNeedWithEmptyHistoryReturnsNoData()
    {
        $result = $this->predictorService->predictMonthlyNeed([]);

        $this->assertEquals(0.0, $result['prediction']);
        $this->assertEquals('no_data', $result['method']);
        $this->assertEquals(0.0, $result['accuracy']);
    }
}
