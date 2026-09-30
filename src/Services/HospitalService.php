<?php

namespace App\Services;

use App\Config\Database;
use R;

class HospitalService
{
    /**
     * Get dashboard metrics and recent trends for a hospital.
     */
    public function getDashboardData(int $hospitalId): array
    {
        $mainDb = Database::getMainDbName();

        $predict = R::getAll(
            "SELECT predictions, FROM_UNIXTIME(tym) AS date FROM `predictions` WHERE hospital_id = ? ORDER BY tym DESC LIMIT 6",
            [$hospitalId]
        );

        $lastSixMonthsUsage = [];
        try {
            $usageQuery = "SELECT 
                MIN(DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y')) AS month_year, 
                MIN(DATE_FORMAT(FROM_UNIXTIME(o.tym), '%M')) AS month_name, 
                SUM(o.qty * CAST(REPLACE(ox.size, ' Cubic Meter', '') AS DECIMAL(10,2))) AS total_cubic_meters 
            FROM `{$mainDb}`.oxygen_order AS o 
            LEFT JOIN `{$mainDb}`.oxygen AS ox ON o.product = ox.id 
            WHERE FROM_UNIXTIME(o.tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND o.order_by = ? 
            GROUP BY DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y-%m') 
            ORDER BY MIN(o.tym) DESC";

            $lastSixMonthsUsage = R::getAll($usageQuery, [$hospitalId]);
        } catch (\Exception $e) {
            // Standalone fallback: use facility_monthly_usage
            try {
                $lastSixMonthsUsage = R::getAll(
                    "SELECT 
                        MIN(DATE_FORMAT(CONCAT(period, '-01'), '%Y')) AS month_year, 
                        MIN(DATE_FORMAT(CONCAT(period, '-01'), '%M')) AS month_name, 
                        SUM(oxygen_used_m3) AS total_cubic_meters 
                    FROM `facility_monthly_usage` 
                    WHERE hospital_id = ? 
                    GROUP BY period 
                    ORDER BY period DESC 
                    LIMIT 6",
                    [$hospitalId]
                );
            } catch (\Exception $ex) {
                $lastSixMonthsUsage = [];
            }
        }

        $predictQuery = "SELECT 
            p.predictions AS total_cubic_meters, 
            DATE_FORMAT(FROM_UNIXTIME(p.tym), '%Y') AS month_year, 
            DATE_FORMAT(FROM_UNIXTIME(p.tym), '%M') AS month_name 
        FROM `predictions` p
        INNER JOIN (
            SELECT MAX(tym) AS max_tym
            FROM `predictions`
            WHERE hospital_id = ? AND FROM_UNIXTIME(tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(FROM_UNIXTIME(tym), '%Y-%m')
        ) latest ON p.tym = latest.max_tym
        WHERE p.hospital_id = ?
        ORDER BY p.tym ASC";

        $lastSixMonthPredict = R::getAll($predictQuery, [$hospitalId, $hospitalId]);

        $orders = [];
        try {
            $ordersQuery = "SELECT *, (SELECT size FROM `{$mainDb}`.oxygen WHERE oxygen.id = `{$mainDb}`.oxygen_order.product) AS size 
            FROM `{$mainDb}`.oxygen_order 
            WHERE order_by = ? 
            ORDER BY tym DESC 
            LIMIT 10";

            $orders = R::getAll($ordersQuery, [$hospitalId]);
        } catch (\Exception $e) {
            // Standalone fallback: use local oxygenorder table
            try {
                $orders = R::getAll("SELECT * FROM `oxygenorder` WHERE order_by = ? ORDER BY tym DESC LIMIT 10", [$hospitalId]);
            } catch (\Exception $ex) {
                $orders = [];
            }
        }

        return [
            'predict' => $predict,
            'chart'   => [
                'usage'     => $lastSixMonthsUsage,
                'predicted' => $lastSixMonthPredict
            ],
            'orders'  => $orders
        ];
    }

    /**
     * Create a new hospital record.
     */
    public function createHospital(array $data): int
    {
        $hospital = R::dispense('hospital');
        $hospital->name          = $data['name'] ?? '';
        $hospital->addressLine1  = $data['addressLine1'] ?? $data['address_1'] ?? $data['address'] ?? '';
        $hospital->addressLine2  = $data['addressLine2'] ?? $data['address_2'] ?? '';
        $hospital->city          = $data['city'] ?? '';
        $hospital->state         = $data['state'] ?? '';
        $hospital->hospitals_type= $data['type'] ?? $data['hospitals_type'] ?? '';
        $hospital->bedCap        = $data['bedCap'] ?? $data['bed'] ?? '';
        $hospital->departs       = $data['departs'] ?? $data['depart'] ?? '';
        $hospital->oxygenSource  = $data['oxygenSource'] ?? $data['oSource'] ?? '';
        $hospital->powerBackup   = $data['powerBackup'] ?? $data['power'] ?? '';
        $hospital->technicals    = $data['technicals'] ?? $data['technical'] ?? '';
        $hospital->contactPerson = $data['contactPerson'] ?? '';
        $hospital->contactRole   = $data['contactRole'] ?? $data['designation'] ?? '';
        $hospital->contactPhone  = $data['contactPhone'] ?? $data['phone'] ?? '';
        $hospital->contactEmail  = $data['contactEmail'] ?? $data['email'] ?? '';

        return (int)R::store($hospital);
    }
}
