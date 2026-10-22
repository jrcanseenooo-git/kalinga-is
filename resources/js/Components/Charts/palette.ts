// Shared chart palette (Kalinga violet first). Values are always printed next to
// colours, so colour never carries meaning alone.
export const CHART_COLORS = ['#7c5cd6', '#3b82d6', '#2f9e6b', '#e0a526', '#e0607e', '#2aa3a3', '#64748b', '#a855f7'];
export type ChartRow = { label: string; total: number };
export const sumRows = (rows: ChartRow[]) => rows.reduce((sum, row) => sum + Number(row.total || 0), 0);
export const pct = (value: number, total: number) => (total ? Math.round((value / total) * 100) : 0);
