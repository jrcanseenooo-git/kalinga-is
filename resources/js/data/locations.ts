export type Barangay = { code: string; name: string };
export type Municipality = { code: string; name: string; type: 'City' | 'Municipality'; barangays: Barangay[] };
export type Province = { code: string; name: string; municipalities: Municipality[] };
export type Region = { code: string; name: string; provinces: Province[]; municipalities: Municipality[] };
export type LocationDataset = { dataset: string; source: string; version: string; publication_date: string; use: string; counts: Record<string, number>; regions: Region[] };

export const locationDatasetUrl = '/data/psgc-2q-2026.json';
