# Location dataset and PSGC replacement

The application uses [philippine-locations.sample.json](../../resources/js/data/philippine-locations.sample.json) for its dependent Region → Province → Municipality/City → Barangay selectors. It is a small, presentation-only Kalinga hierarchy and deliberately includes `SAMPLE-` codes.

It is not an official PSGC publication and must not be treated as authoritative geographic data. Replace the file's values and codes with the official PSGC export before production use. Keep the same hierarchy:

```json
{
  "regions": [
    { "code": "REGION_CODE", "name": "Region name", "provinces": [
      { "code": "PROVINCE_CODE", "name": "Province name", "municipalities": [
        { "code": "LGU_CODE", "name": "Municipality / City", "barangays": ["Barangay name"] }
      ] }
    ] }
  ]
}
```

The selected display names are stored with client and service-provider records (`region`, `province`, `municipality`, and `barangay`). Future PSGC import work can add code columns and preserve the existing display labels during migration.
