export type RemarkItem = { id: number; kind: string; body: string; author: string | null; role: string; acknowledged_at: string | null; created_at: string };
export type Remark = RemarkItem & { replies: RemarkItem[] };
