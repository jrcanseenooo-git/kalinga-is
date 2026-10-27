<script setup lang="ts">
/**
 * Analytics map that drills down Philippines > region > province > city or
 * municipality > barangay. Boundaries are hosted by Kalinga
 * (public/data/boundaries, PSGC 3Q 2026) and loaded one area at a time, so
 * no outside map service sees what users look at. Counts are aggregates from
 * the server (small cells already withheld); no case data reaches the map.
 */
import { feature } from 'topojson-client';
import type { Topology } from 'topojson-specification';
import { ArrowLeft, Map as MapIcon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { locationDatasetUrl, type LocationDataset, type Municipality, type Province, type Region } from '@/data/locations';

export type MapLevel = 'region' | 'province' | 'municipality' | 'barangay';
type Row = { name: string; total: number; active: number };
type Position = [number, number];
type Geometry = { type: string; coordinates: unknown };
type Shape = { code: string; name: string; geometry: Geometry };

const props = defineProps<{ level: MapLevel; rows: Row[]; region: string; province: string; municipality: string; barangay: string }>();
const emit = defineEmits<{ select: [name: string]; back: [] }>();

const dataset = ref<LocationDataset | null>(null);
const shapes = ref<Shape[]>([]);
const loading = ref(true);
const failed = ref(false);
const hovered = ref<string | null>(null);

const norm = (value: string) => value.trim().toLocaleLowerCase();
const plain = (code: string) => String(Number(code));

async function json<T>(url: string): Promise<T> {
  const response = await fetch(url, { cache: 'force-cache' });
  if (!response.ok) throw new Error(url);
  return response.json() as Promise<T>;
}

/** Children of the current area in the PSGC list (names and codes), for joining counts to shapes. */
const regionItem = computed<Region | undefined>(() => dataset.value?.regions.find(item => norm(item.name) === norm(props.region)));
const provinceItem = computed<Province | undefined>(() => regionItem.value?.provinces.find(item => norm(item.name) === norm(props.province)));
const municipalityItem = computed<Municipality | undefined>(() => (provinceItem.value?.municipalities ?? regionItem.value?.municipalities ?? []).find(item => norm(item.name) === norm(props.municipality)));
const children = computed<{ code: string; name: string }[]>(() => {
  if (!dataset.value) return [];
  if (props.level === 'region') return dataset.value.regions;
  if (props.level === 'province') return regionItem.value?.provinces ?? [];
  if (props.level === 'municipality') return provinceItem.value?.municipalities ?? regionItem.value?.municipalities ?? [];
  return municipalityItem.value?.barangays ?? [];
});

function topoShapes(topology: Topology): Shape[] {
  const object = Object.values(topology.objects)[0];
  const collection = feature(topology, object) as unknown as { features: { geometry: Geometry; properties: Record<string, unknown> }[] };
  return collection.features.filter(item => item.geometry).map(item => {
    const props = item.properties;
    const level = [4, 3, 2, 1].find(n => props[`adm${n}_psgc`] !== undefined && props[`adm${n}_en`] !== undefined) ?? 1;
    return { code: String(props[`adm${level}_psgc`]), name: String(props[`adm${level}_en`] ?? ''), geometry: item.geometry };
  });
}

/** Which boundary file(s) hold the children of the current area. */
async function load() {
  loading.value = true; failed.value = false;
  try {
    dataset.value ??= await json<LocationDataset>(locationDatasetUrl);
    let files: string[] = [];
    if (props.level === 'region') files = ['/data/boundaries/country/country.topo.0.001.json'];
    else if (props.level === 'province' && regionItem.value) files = [`/data/boundaries/regions/provdists-region-${plain(regionItem.value.code)}.topo.0.001.json`];
    else if (props.level === 'municipality' && regionItem.value && !regionItem.value.provinces.length) {
      // A region without provinces (NCR): its cities are in the files of its districts.
      const districts = topoShapes(await json<Topology>(`/data/boundaries/regions/provdists-region-${plain(regionItem.value.code)}.topo.0.001.json`));
      files = districts.map(item => `/data/boundaries/provdists/municities-provdist-${item.code}.topo.0.001.json`);
    } else if (props.level === 'municipality' && provinceItem.value) files = [`/data/boundaries/provdists/municities-provdist-${plain(provinceItem.value.code)}.topo.0.001.json`];
    else if (props.level === 'barangay' && municipalityItem.value) files = [`/data/boundaries/municities/bgysubmuns-municity-${plain(municipalityItem.value.code)}.topo.0.001.json`];
    const loaded = await Promise.all(files.map(file => json<Topology>(file).then(topoShapes).catch(() => [] as Shape[])));
    shapes.value = loaded.flat();
    if (files.length && !shapes.value.length) failed.value = true;
  } catch { failed.value = true; shapes.value = []; } finally { loading.value = false; }
}
watch(() => [props.level, props.region, props.province, props.municipality], load, { immediate: true });

/** Counts by PSGC code: the server groups by the recorded name; the PSGC list gives the code. */
const totals = computed(() => {
  const byName = new Map(props.rows.map(row => [norm(row.name), row]));
  const map = new Map<string, Row & { label: string }>();
  for (const child of children.value) {
    const row = byName.get(norm(child.name));
    map.set(plain(child.code), { name: child.name, label: child.name, total: row?.total ?? 0, active: row?.active ?? 0 });
  }
  return map;
});
const unmapped = computed(() => {
  const known = new Set(children.value.map(child => norm(child.name)));
  return props.rows.filter(row => !known.has(norm(row.name)));
});
const highest = computed(() => Math.max(1, ...props.rows.map(row => row.total)));
const bands = [
  { max: 0, color: '#eef0f4', label: 'No cases' }, { max: 0.25, color: '#ede9fe', label: 'Few' }, { max: 0.5, color: '#c4b5fd', label: 'Some' },
  { max: 0.75, color: '#8b5cf6', label: 'Many' }, { max: 1, color: '#5b21b6', label: 'Most' },
];
const fillFor = (code: string) => { const total = totals.value.get(code)?.total ?? 0; if (!total) return bands[0].color; const share = total / highest.value; return (bands.find(band => band.max > 0 && share <= band.max) ?? bands[4]).color; };
const selectedName = computed(() => props.level === 'barangay' ? props.barangay : '');

// Fit the shapes to the drawing area (equirectangular, scaled for latitude).
const width = 520; const height = 560; const pad = 12;
function positions(value: unknown): Position[] { if (!Array.isArray(value)) return []; if (typeof value[0] === 'number') return [value as Position]; return value.flatMap(positions); }
const box = computed(() => {
  const points = shapes.value.flatMap(shape => positions(shape.geometry.coordinates));
  if (!points.length) return { minX: 116, maxX: 127, minY: 4, maxY: 22, k: 1 };
  const lats = points.map(p => p[1]); const lons = points.map(p => p[0]);
  const minY = Math.min(...lats), maxY = Math.max(...lats);
  const k = Math.cos((((minY + maxY) / 2) * Math.PI) / 180);
  return { minX: Math.min(...lons) * k, maxX: Math.max(...lons) * k, minY, maxY, k };
});
function project([lon, lat]: Position): string {
  const b = box.value; const scale = Math.min((width - pad * 2) / Math.max(b.maxX - b.minX, 1e-6), (height - pad * 2) / Math.max(b.maxY - b.minY, 1e-6));
  const offsetX = (width - (b.maxX - b.minX) * scale) / 2; const offsetY = (height - (b.maxY - b.minY) * scale) / 2;
  return `${(offsetX + (lon * b.k - b.minX) * scale).toFixed(1)},${(height - offsetY - (lat - b.minY) * scale).toFixed(1)}`;
}
const ring = (points: Position[]) => points.map((point, index) => `${index ? 'L' : 'M'}${project(point)}`).join('') + 'Z';
const pathOf = (geometry: Geometry) => geometry.type === 'Polygon' ? (geometry.coordinates as Position[][]).map(ring).join('') : geometry.type === 'MultiPolygon' ? (geometry.coordinates as Position[][][]).flatMap(polygon => polygon.map(ring)).join('') : '';
const paths = computed(() => shapes.value.map(shape => ({ ...shape, d: pathOf(shape.geometry), label: totals.value.get(shape.code)?.label ?? shape.name })));

const levelName: Record<MapLevel, string> = { region: 'regions', province: 'provinces', municipality: 'cities and municipalities', barangay: 'barangays' };
const areaTitle = computed(() => props.municipality || props.province || props.region || 'Philippines');
const choose = (name: string) => emit('select', name);
const listRows = computed(() => [...totals.value.values()].filter(row => row.total > 0).sort((a, b) => b.total - a.total));
const hoverRow = computed(() => hovered.value ? totals.value.get(hovered.value) : null);
</script>

<template>
  <section class="rounded-2xl border border-[var(--color-line)] bg-white p-4 shadow-[var(--shadow-card)]" aria-labelledby="drill-map-title">
    <div class="flex flex-wrap items-start justify-between gap-2">
      <div class="min-w-0">
        <h2 id="drill-map-title" class="flex items-center gap-2 text-base font-bold text-slate-900"><MapIcon class="h-4 w-4 text-brand-700" aria-hidden="true" />{{ areaTitle }}</h2>
        <p class="text-xs text-slate-600">Cases by {{ levelName[level] }}, from the child's recorded address. Tap an area to go down a level.</p>
      </div>
      <button v-if="region" type="button" class="ui-button min-h-10 px-3 text-xs print:hidden" @click="emit('back')"><ArrowLeft class="h-4 w-4" aria-hidden="true" />Up one level</button>
    </div>
    <div class="mt-3 grid gap-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
      <div class="relative min-w-0">
        <p v-if="loading" role="status" class="grid h-64 place-items-center text-sm text-slate-600">Loading the map...</p>
        <p v-else-if="failed" class="grid h-40 place-items-center rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-600">The map for this area is not available. The list shows the figures.</p>
        <svg v-else :viewBox="`0 0 ${width} ${height}`" role="img" :aria-label="`Map of ${areaTitle} by ${levelName[level]}`" class="h-auto w-full max-h-[34rem]">
          <path v-for="item in paths" :key="item.code" :d="item.d" :fill="fillFor(item.code)" :stroke="selectedName && item.label === selectedName ? '#1e1b4b' : '#ffffff'" :stroke-width="selectedName && item.label === selectedName ? 2 : 0.6"
            class="cursor-pointer transition-opacity hover:opacity-80" @click="choose(item.label)" @mouseenter="hovered = item.code" @mouseleave="hovered = null"><title>{{ item.label }}: {{ totals.get(item.code)?.total ?? 0 }} cases</title></path>
        </svg>
        <p v-if="hoverRow" class="pointer-events-none absolute left-2 top-2 rounded-lg bg-white/95 px-3 py-1.5 text-xs shadow ring-1 ring-slate-200" aria-hidden="true"><b>{{ hoverRow.label }}</b>: {{ hoverRow.total }} cases ({{ hoverRow.active }} active)</p>
        <ul class="mt-2 flex flex-wrap gap-3 text-[11px] text-slate-600" aria-label="Map key"><li v-for="band in bands" :key="band.label" class="flex items-center gap-1"><span class="h-3 w-3 rounded-sm ring-1 ring-slate-200" :style="{ background: band.color }"></span>{{ band.label }}</li></ul>
      </div>
      <div class="min-w-0">
        <h3 class="text-sm font-semibold text-slate-900">{{ listRows.length }} {{ levelName[level] }} with cases</h3>
        <ul class="mt-2 max-h-[26rem] divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200">
          <li v-for="row in listRows" :key="row.name"><button type="button" class="flex min-h-11 w-full items-center gap-3 px-3 text-left text-sm hover:bg-brand-50" :class="selectedName === row.name ? 'bg-brand-50 font-semibold' : ''" @click="choose(row.name)">
            <span class="min-w-0 flex-1 truncate">{{ row.name }}</span><span class="text-xs text-slate-600">{{ row.active }} active</span><b class="w-10 text-right">{{ row.total }}</b></button></li>
          <li v-if="!listRows.length" class="p-3 text-sm text-slate-600">No cases to show here (or too few to show safely).</li>
        </ul>
        <p v-if="unmapped.length" class="mt-2 text-xs text-slate-600">Not matched to the map: {{ unmapped.map(row => `${row.name} (${row.total})`).join(', ') }}.</p>
      </div>
    </div>
  </section>
</template>
