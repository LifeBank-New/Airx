#!/usr/bin/env python3
"""
Generate a realistic 1-year sample CSV dataset for AirX clinical telemetry and historical oxygen consumption.
The generated file can be uploaded via the POST /v1/data/hospital/add endpoint.
"""

import csv
import random
from datetime import date, timedelta

# Seed for reproducible realistic data
random.seed(42)

start_date = date(2025, 10, 1)
end_date = date(2026, 9, 29)

clinical_profiles = [
    {
        "condition": "Pneumonia",
        "treatments": ["Oxygen Therapy", "Nasal Cannula", "Face Mask"],
        "min_age": 18, "max_age": 75,
        "flow_min": 4.0, "flow_max": 10.0,
        "need_min": 8.0, "need_max": 22.0
    },
    {
        "condition": "Asthma",
        "treatments": ["Nasal Cannula", "Face Mask", "Oxygen Therapy"],
        "min_age": 6, "max_age": 60,
        "flow_min": 2.0, "flow_max": 6.0,
        "need_min": 4.0, "need_max": 14.0
    },
    {
        "condition": "COPD",
        "treatments": ["Nasal Cannula", "BiPAP", "Venturi Mask"],
        "min_age": 52, "max_age": 82,
        "flow_min": 1.5, "flow_max": 4.0,
        "need_min": 6.0, "need_max": 16.0
    },
    {
        "condition": "COVID-19",
        "treatments": ["Non-Rebreather Mask", "CPAP", "Mechanical Ventilation"],
        "min_age": 28, "max_age": 78,
        "flow_min": 6.0, "flow_max": 15.0,
        "need_min": 14.0, "need_max": 36.0
    },
    {
        "condition": "Hypoxemia",
        "treatments": ["Oxygen Therapy", "Venturi Mask", "Face Mask"],
        "min_age": 15, "max_age": 72,
        "flow_min": 4.0, "flow_max": 10.0,
        "need_min": 8.0, "need_max": 20.0
    },
    {
        "condition": "Trauma",
        "treatments": ["Face Mask", "Mechanical Ventilation", "Oxygen Therapy"],
        "min_age": 18, "max_age": 60,
        "flow_min": 8.0, "flow_max": 15.0,
        "need_min": 12.0, "need_max": 30.0
    },
    {
        "condition": "Heart Failure",
        "treatments": ["CPAP", "Nasal Cannula", "Oxygen Therapy"],
        "min_age": 48, "max_age": 85,
        "flow_min": 3.0, "flow_max": 8.0,
        "need_min": 6.0, "need_max": 18.0
    },
    {
        "condition": "Bronchitis",
        "treatments": ["Nasal Cannula", "Oxygen Therapy"],
        "min_age": 10, "max_age": 65,
        "flow_min": 2.0, "flow_max": 5.0,
        "need_min": 4.0, "need_max": 12.0
    },
    {
        "condition": "Respiratory Distress",
        "treatments": ["CPAP", "Mechanical Ventilation", "Non-Rebreather Mask"],
        "min_age": 2, "max_age": 70,
        "flow_min": 6.0, "flow_max": 15.0,
        "need_min": 12.0, "need_max": 34.0
    },
    {
        "condition": "None",
        "treatments": ["Oxygen Therapy", "Nasal Cannula"],
        "min_age": 12, "max_age": 65,
        "flow_min": 2.0, "flow_max": 4.0,
        "need_min": 3.0, "need_max": 8.0
    }
]

genders = ["Male", "Female"]

fieldnames = ["gender", "age", "conditions", "estimate_need", "flow_rate", "treatment", "date_used"]

output_path = "sample_data_1_year.csv"

records = []
current = start_date
while current <= end_date:
    # 1 to 3 records per day for realistic hospital throughput
    daily_count = random.choices([1, 2, 3], weights=[0.25, 0.55, 0.20])[0]
    for _ in range(daily_count):
        profile = random.choice(clinical_profiles)
        gender = random.choice(genders)
        age = random.randint(profile["min_age"], profile["max_age"])
        condition = profile["condition"]
        treatment = random.choice(profile["treatments"])
        
        # Add slight seasonal variation (e.g. peak respiratory demand in harmattan / mid-year)
        month = current.month
        seasonal_multiplier = 1.15 if month in [11, 12, 1, 6, 7] else 1.0
        
        flow_rate = round(random.uniform(profile["flow_min"], profile["flow_max"]), 1)
        estimate_need = round(random.uniform(profile["need_min"], profile["need_max"]) * seasonal_multiplier, 2)
        
        records.append({
            "gender": gender,
            "age": age,
            "conditions": condition,
            "estimate_need": estimate_need,
            "flow_rate": flow_rate,
            "treatment": treatment,
            "date_used": current.strftime("%Y-%m-%d")
        })
    current += timedelta(days=1)

with open(output_path, mode="w", newline="", encoding="utf-8") as f:
    writer = csv.DictWriter(f, fieldnames=fieldnames)
    writer.writeheader()
    writer.writerows(records)

print(f"Generated {len(records)} records from {start_date} to {end_date} into {output_path}")
