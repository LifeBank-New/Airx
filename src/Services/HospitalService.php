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

        $usageQuery = "SELECT 
            DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y') AS month_year, 
            DATE_FORMAT(FROM_UNIXTIME(o.tym), '%M') AS month_name, 
            SUM(o.qty * CAST(REPLACE(ox.size, ' Cubic Meter', '') AS DECIMAL(10,2))) AS total_cubic_meters 
        FROM `{$mainDb}`.oxygen_order AS o 
        LEFT JOIN `{$mainDb}`.oxygen AS ox ON o.product = ox.id 
        WHERE FROM_UNIXTIME(o.tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND o.order_by = ? 
        GROUP BY DATE_FORMAT(FROM_UNIXTIME(o.tym), '%Y-%m') 
        ORDER BY FROM_UNIXTIME(o.tym) DESC";

        $lastSixMonthsUsage = R::getAll($usageQuery, [$hospitalId]);

        $predictQuery = "SELECT 
            p.predictions AS total_cubic_meters, 
            DATE_FORMAT(FROM_UNIXTIME(p.tym), '%Y') AS month_year, 
            DATE_FORMAT(FROM_UNIXTIME(p.tym), '%M') AS month_name 
        FROM `predictions` p
        INNER JOIN (
            SELECT MAX(tym) AS max_tym
            FROM `predictions`
            WHERE (hospital_id = ? OR hospitalID = ?) AND FROM_UNIXTIME(tym) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(FROM_UNIXTIME(tym), '%Y-%m')
        ) latest ON p.tym = latest.max_tym
        WHERE (p.hospital_id = ? OR p.hospitalID = ?)
        ORDER BY p.tym ASC";

        $lastSixMonthPredict = R::getAll($predictQuery, [$hospitalId, $hospitalId, $hospitalId, $hospitalId]);

        $ordersQuery = "SELECT *, (SELECT size FROM `{$mainDb}`.oxygen WHERE oxygen.id = `{$mainDb}`.oxygen_order.product) AS size 
        FROM `{$mainDb}`.oxygen_order 
        WHERE order_by = ? 
        ORDER BY tym DESC 
        LIMIT 10";

        $orders = R::getAll($ordersQuery, [$hospitalId]);

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
        $hospital->addressLine1  = $data['addressLine1'] ?? '';
        $hospital->addressLine2  = $data['addressLine2'] ?? '';
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
