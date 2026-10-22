"""Build a local, low-resolution Philippine regional boundary layer for the dashboard.

The visual boundaries come from the MIT-licensed `faeldon/philippines-json-maps`
2023 low-resolution release. PSGC names and codes used by Kalinga IS remain the
PSA 2Q 2026 local dataset. This script is only run by maintainers; browsers never
contact the source service.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from urllib.request import urlopen

BASE = "https://raw.githubusercontent.com/faeldon/philippines-json-maps/master/2023/geojson/regions/lowres/"
REGION_CODES = [
    "100000000", "200000000", "300000000", "400000000", "500000000", "600000000", "700000000", "800000000", "900000000",
    "1000000000", "1100000000", "1200000000", "1300000000", "1400000000", "1600000000", "1700000000", "1900000000",
]
NIR_CODE = 1800000000
NIR_PROVINCES = {604500000, 704600000, 706100000}


def download_region(code: str) -> list[dict]:
    url = f"{BASE}provdists-region-{code}.0.001.json"
    with urlopen(url, timeout=45) as response:
        return json.load(response)["features"]


def main(output: str) -> None:
    features: list[dict] = []
    nir_features: list[dict] = []
    for code in REGION_CODES:
        for feature in download_region(code):
            properties = feature.setdefault("properties", {})
            province_code = int(properties.get("adm2_psgc", 0))
            if province_code in NIR_PROVINCES:
                copied = json.loads(json.dumps(feature))
                copied["properties"]["region_code"] = NIR_CODE
                nir_features.append(copied)
                continue
            properties["region_code"] = str(properties["adm1_psgc"]).zfill(10)
            features.append(feature)
    features.extend(nir_features)
    payload = {
        "type": "FeatureCollection",
        "metadata": {
            "title": "Philippine regional visualization boundaries",
            "boundary_source": "faeldon/philippines-json-maps 2023 low-resolution GeoJSON (MIT)",
            "psgc_reference": "PSA PSGC 2Q 2026 is the authoritative Kalinga IS administrative reference.",
            "note": "For dashboard visualization only; validate against an approved NAMRIA/DSWD boundary release before production use.",
        },
        "features": features,
    }
    destination = Path(output)
    destination.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"Wrote {len(features)} provincial boundary features to {destination}")


if __name__ == "__main__":
    main(sys.argv[1])
