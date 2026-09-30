<?php

namespace Tests\Services;

use PHPUnit\Framework\TestCase;
use App\Services\PredictorService;

class PredictorServiceTest extends TestCase
{
    /** @var PredictorService */
    private $predictorService;

    protected function setUp(): void
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
        $this->assertIsFloat($needs);
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

    public function testValidateHistoricalDataHelperFunctions()
    {
        require_once dirname(__DIR__, 2) . '/include/functions/helper.php';

        $rawHistory = [
            ['month' => '2025-10', 'total_cubic_meters' => 310.5, 'avg_temp' => 28.0, 'avg_humidity' => 75.0],
            ['month' => 'invalid-date', 'total_cubic_meters' => 100.0],
            ['month' => '2025-11', 'total_cubic_meters' => -50.0]
        ];

        $validated1 = validateHistoryData($rawHistory);
        $validated2 = validateHistoricalData($rawHistory);

        $this->assertCount(1, $validated1);
        $this->assertEquals($validated1, $validated2);
        $this->assertEquals('2025-10', $validated1[0]['month']);
        $this->assertEquals(310.5, $validated1[0]['total_cubic_meters']);
    }
}
