"""Build a browser-safe PSGC hierarchy from the PSA publication datafile.

The output contains only public administrative reference data. It must be
generated from a reviewed PSA publication, never from case records.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import pandas as pd


SOURCE = Path(sys.argv[1])
DESTINATION = Path(sys.argv[2])


def as_text(value: object) -> str:
    if pd.isna(value):
        return ""
    return str(value).strip()


def main() -> None:
    table = pd.read_excel(SOURCE, sheet_name="PSGC", dtype=str)
    regions: list[dict] = []
    current_region: dict | None = None
    current_province: dict | None = None
    current_municipality: dict | None = None
    counts = {"regions": 0, "provinces": 0, "municipalities": 0, "barangays": 0}

    for row in table.to_dict("records"):
        code = as_text(row.get("10-digit PSGC"))
        name = as_text(row.get("Name"))
        level = as_text(row.get("Geographic Level"))
        if not code or not name:
            continue

        if level == "Reg":
            current_region = {"code": code, "name": name, "provinces": [], "municipalities": []}
            regions.append(current_region)
            current_province = None
            current_municipality = None
            counts["regions"] += 1
        elif level == "Prov" and current_region:
            current_province = {"code": code, "name": name, "municipalities": []}
            current_region["provinces"].append(current_province)
            current_municipality = None
            counts["provinces"] += 1
        elif level in {"City", "Mun"} and current_region:
            current_municipality = {
                "code": code,
                "name": name,
                "type": "City" if level == "City" else "Municipality",
                "barangays": [],
            }
            # Highly urbanized cities and special municipalities may belong
            # directly to a region rather than a province.
            parent = current_province["municipalities"] if current_province else current_region["municipalities"]
            parent.append(current_municipality)
            counts["municipalities"] += 1
        elif level == "Bgy" and current_municipality:
            current_municipality["barangays"].append({"code": code, "name": name})
            counts["barangays"] += 1

    payload = {
        "dataset": "Philippine Standard Geographic Code (PSGC)",
        "source": "Philippine Statistics Authority",
        "version": "2Q-2026",
        "publication_date": "2026-06-30",
        "use": "Local administrative reference data only. No case or client information is included.",
        "counts": counts,
        "regions": regions,
    }
    DESTINATION.parent.mkdir(parents=True, exist_ok=True)
    DESTINATION.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(json.dumps({"output": str(DESTINATION), "counts": counts, "bytes": DESTINATION.stat().st_size}))


if __name__ == "__main__":
    main()
