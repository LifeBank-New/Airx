<?php

namespace Tests\Services;

use PHPUnit\Framework\TestCase;

class CsvIngestionTest extends TestCase
{
    private $monthlyCsvPath;
    private $patientCsvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->monthlyCsvPath = dirname(__DIR__, 2) . '/demo_monthly_usage.csv';
        $this->patientCsvPath = dirname(__DIR__, 2) . '/sample.csv';
    }

    public function testDemoMonthlyUsageCsvExistsAndIsReadable()
    {
        $this->assertFileExists($this->monthlyCsvPath);
        $this->assertTrue(is_readable($this->monthlyCsvPath));
    }

    public function testDemoMonthlyUsageHeadersMatchFacilityUsageFields()
    {
        $handle = fopen($this->monthlyCsvPath, 'r');
        $this->assertNotFalse($handle);

        $headers = fgetcsv($handle);
        fclose($handle);

        $expected = ['period', 'oxygen_used_m3'];
        $this->assertEquals($expected, array_map('trim', $headers));
    }

    public function testDemoMonthlyUsageCsvSpansTwelveMonthsAndHasNoMissingData()
    {
        $rows = [];
        $handle = fopen($this->monthlyCsvPath, 'r');
        $headers = array_map('trim', fgetcsv($handle));

        while (($data = fgetcsv($handle)) !== false) {
            if (count($headers) === count($data)) {
                $rows[] = array_combine($headers, array_map('trim', $data));
            }
        }
        fclose($handle);

        $this->assertCount(12, $rows, 'Demo dataset must contain exactly 12 monthly rows');

        $months = [];
        foreach ($rows as $row) {
            $this->assertArrayHasKey('period', $row);
            $this->assertArrayHasKey('oxygen_used_m3', $row);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $row['period']);
            $this->assertGreaterThan(0.0, (float)$row['oxygen_used_m3']);
            $months[$row['period']] = true;
        }

        $this->assertCount(12, $months, 'Dataset must cover exactly 12 distinct months');
    }

    public function testPatientCsvContainsProhibitedPatientAttributes()
    {
        if (!file_exists($this->patientCsvPath)) {
            $this->markTestSkipped('Patient sample CSV not present');
        }

        $handle = fopen($this->patientCsvPath, 'r');
        $headers = array_map('trim', fgetcsv($handle));
        fclose($handle);

        $patientIndicators = ['gender', 'age', 'conditions', 'flow_rate', 'treatment'];
        $foundIndicators = array_intersect($patientIndicators, $headers);

        $this->assertNotEmpty($foundIndicators, 'Patient CSV contains prohibited patient telemetry attributes that must trigger 422');
    }
}
