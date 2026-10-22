<script setup lang="ts">
import { ArrowLeft } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { locationDatasetUrl, type LocationDataset } from '@/data/locations';

type HeatmapRow = { area: string; total: number };
type ProvinceRow = { region: string; province: string; total: number };
type BoundaryFeature = { geometry: { type: string; coordinates: unknown }; properties: { region_code: number | string } };
type BoundaryCollection = { features: BoundaryFeature[] };
type Position = [number, number];

const props = withDefaults(defineProps<{ rows: HeatmapRow[]; provinceRows?: ProvinceRow[]; selectedRegion?: string }>(), { selectedRegion: '', provinceRows: () => [] });
const emit = defineEmits<{ (event: 'region-change', region: string): void }>();
const dataset = ref<LocationDataset | null>(null);
const boundaryLayer = ref<BoundaryCollection | null>(null);
const selected = ref(props.selectedRegion);
const nationalZoom = 1.04;
const zoom = ref(nationalZoom);
const loading = ref(true);
const loadError = ref(false);
const width = 520;
const height = 700;
const padding = 28;

const severityBands = [
  { min: 0, max: 0, label: 'No cases', color: '#e5e7eb', textColor: '#9ca3af' },
  { min: 1, max: 5, label: '1-5 cases', color: '#ede9fe', textColor: '#7c3aed' },
  { min: 6, max: 15, label: '6-15 cases', color: '#c4b5fd', textColor: '#6d28d9' },
  { min: 16, max: 30, label: '16-30 cases', color: '#8b5cf6', textColor: '#fff' },
  { min: 31, max: Infinity, label: '31+ cases', color: '#5b21b6', textColor: '#fff' },
];

const bandFor = (total: number) => severityBands.find(b => total >= b.min && total <= b.max) ?? severityBands[0];

const normalizedCounts = computed(() => new Map(props.rows.map((row) => [row.area.trim().toLocaleLowerCase(), Number(row.total)])));
const regions = computed(() => (dataset.value?.regions ?? []).map((region) => ({ code: region.code, name: region.name, total: normalizedCounts.value.get(region.name.trim().toLocaleLowerCase()) ?? 0 })));
const byCode = computed(() => new Map(regions.value.map((region) => [String(region.code), region])));
const highest = computed(() => Math.max(1, ...regions.value.map((region) => region.total)));
const recordedTotal = computed(() => props.rows.reduce((sum, row) => sum + Number(row.total), 0));
const activeRegion = computed(() => regions.value.find((region) => region.name === selected.value));
const mapFeatures = computed(() => boundaryLayer.value?.features ?? []);

const provinceBreakdown = computed(() => {
  if (!selected.value) return [];
  return props.provinceRows
    .filter(p => p.region.trim().toLocaleLowerCase() === selected.value.trim().toLocaleLowerCase())
    .sort((a, b) => b.total - a.total);
});

const provincesHighest = computed(() => Math.max(1, ...provinceBreakdown.value.map(p => p.total)));

function positions(value: unknown): Position[] { if (!Array.isArray(value)) return []; if (typeof value[0] === 'number') return [value as Position]; return value.flatMap(positions); }
const bounds = computed(() => { const visible = selected.value ? mapFeatures.value.filter((feature) => regionFor(feature)?.name === selected.value) : mapFeatures.value; const points = visible.flatMap((feature) => positions(feature.geometry.coordinates)); if (!points.length) return { minLon: 116, maxLon: 127, minLat: 4, maxLat: 22 }; const minLon = Math.min(...points.map(([lon]) => lon)); const maxLon = Math.max(...points.map(([lon]) => lon)); const minLat = Math.min(...points.map(([, lat]) => lat)); const maxLat = Math.max(...points.map(([, lat]) => lat)); const viewportPadding = selected.value ? .12 : .025; const lonPadding = Math.max((maxLon - minLon) * viewportPadding, .04); const latPadding = Math.max((maxLat - minLat) * viewportPadding, .04); return { minLon: minLon - lonPadding, maxLon: maxLon + lonPadding, minLat: minLat - latPadding, maxLat: maxLat + latPadding }; });
function project([lon, lat]: Position): [number, number] { const box = bounds.value; const scale = Math.min((width - padding * 2) / (box.maxLon - box.minLon), (height - padding * 2) / (box.maxLat - box.minLat)); const usedWidth = (box.maxLon - box.minLon) * scale; return [padding + (width - padding * 2 - usedWidth) / 2 + (lon - box.minLon) * scale, height - padding - (lat - box.minLat) * scale]; }
function ringPath(ring: Position[]): string { return ring.map((point, index) => `${index ? 'L' : 'M'}${project(point).join(',')}`).join('') + 'Z'; }
function geometryPath(feature: BoundaryFeature): string { return feature.geometry.type === 'Polygon' ? (feature.geometry.coordinates as Position[][]).map(ringPath).join('') : feature.geometry.type === 'MultiPolygon' ? (feature.geometry.coordinates as Position[][][]).flatMap((polygon) => polygon.map(ringPath)).join('') : ''; }
function regionFor(feature: BoundaryFeature) { return byCode.value.get(String(feature.properties.region_code)); }

function fill(feature: BoundaryFeature) {
  const region = regionFor(feature);
  if (!region) return severityBands[0].color;
  if (selected.value && region.name !== selected.value) return '#f1f5f9';
  return bandFor(region.total).color;
}

function select(feature: BoundaryFeature) { const region = regionFor(feature); if (region) selected.value = selected.value === region.name ? '' : region.name; }
function resetMap() { selected.value = ''; zoom.value = nationalZoom; }
function shortName(name: string) { if (name.includes('National Capital')) return 'NCR'; if (name.includes('Cordillera')) return 'CAR'; return name.replace(/^Region /, 'R'); }
watch(selected, (region) => { zoom.value = region ? 1 : nationalZoom; if (region !== props.selectedRegion) emit('region-change', region); });
watch(() => props.selectedRegion, (region) => { if (region !== selected.value) selected.value = region; });
onMounted(async () => { try { const [locationsResponse, boundariesResponse] = await Promise.all([fetch(locationDatasetUrl, { cache: 'force-cache' }), fetch('/data/philippines-regional-boundaries.geojson', { cache: 'force-cache' })]); if (!locationsResponse.ok || !boundariesResponse.ok) throw new Error(); dataset.value = await locationsResponse.json(); boundaryLayer.value = await boundariesResponse.json(); } catch { loadError.value = true; } finally { loading.value = false; } });
</script>

<template>
    <section class="map-card">
        <header class="map-header">
            <div>
                <h2>Cases by region</h2>
                <p v-if="!activeRegion">Select a region to drill down into provinces.</p>
                <p v-else>{{ activeRegion.name }} - province breakdown</p>
            </div>
            <select v-model="selected" aria-label="Focus map by region">
                <option value="">All regions</option>
                <option v-for="region in regions" :key="region.code" :value="region.name">{{ shortName(region.name) }}</option>
            </select>
        </header>

        <div v-if="loading" class="map-loading">Loading local map…</div>
        <div v-else-if="loadError" class="map-loading">Map data could not be loaded. Refresh the page.</div>
        <div v-else class="map-body">
            <div class="map-canvas">
                <svg :viewBox="`0 0 ${width} ${height}`" role="img" aria-label="Interactive Philippines regional case heatmap">
                    <g :transform="`translate(${width * (1 - zoom) / 2} ${height * (1 - zoom) / 2}) scale(${zoom})`">
                        <path v-for="(feature, index) in mapFeatures" :key="index" :d="geometryPath(feature)" class="region-shape" :class="{ selected: selected === regionFor(feature)?.name }" :fill="fill(feature)" tabindex="0" role="button" :aria-label="`Focus ${regionFor(feature)?.name ?? 'region'}: ${regionFor(feature)?.total ?? 0} cases`" @click="select(feature)" @keydown.enter.prevent="select(feature)" @keydown.space.prevent="select(feature)">
                            <title>{{ regionFor(feature)?.name ?? 'Region' }} - {{ regionFor(feature)?.total ?? 0 }} case(s)</title>
                        </path>
                    </g>
                </svg>
                <div v-if="activeRegion" class="map-label">
                    <strong>{{ shortName(activeRegion.name) }}</strong>
                    <span>{{ activeRegion.total }} {{ activeRegion.total === 1 ? 'case' : 'cases' }}</span>
                </div>
            </div>

            <aside class="map-sidebar">
                <template v-if="activeRegion">
                    <div class="sidebar-header">
                        <div class="sidebar-count" :style="{ background: bandFor(activeRegion.total).color, color: bandFor(activeRegion.total).textColor }">
                            <strong>{{ activeRegion.total }}</strong>
                            <span>total cases</span>
                        </div>
                        <div class="sidebar-region">
                            <b>{{ shortName(activeRegion.name) }}</b>
                            <span>{{ activeRegion.name }}</span>
                        </div>
                    </div>

                    <div v-if="provinceBreakdown.length" class="province-list">
                        <p class="province-heading">Province breakdown</p>
                        <div v-for="prov in provinceBreakdown" :key="prov.province" class="province-row">
                            <div class="province-info">
                                <span class="province-name">{{ prov.province }}</span>
                                <strong>{{ prov.total }}</strong>
                            </div>
                            <div class="province-bar-track">
                                <div class="province-bar-fill" :style="{ width: `${(prov.total / provincesHighest) * 100}%`, background: bandFor(prov.total).color }" />
                            </div>
                        </div>
                    </div>
                    <p v-else class="no-province-data">No province-level data available.</p>

                    <button type="button" class="back-btn" @click="resetMap"><ArrowLeft class="h-3.5 w-3.5" />Back to national view</button>
                </template>

                <template v-else>
                    <div class="sidebar-header">
                        <div class="sidebar-count sidebar-count-default">
                            <strong>{{ recordedTotal }}</strong>
                            <span>total cases</span>
                        </div>
                        <div class="sidebar-region">
                            <b>National view</b>
                            <span>Select a region on the map to drill down into its provinces.</span>
                        </div>
                    </div>
                    <div v-if="regions.filter(r => r.total > 0).length" class="region-rank">
                        <p class="province-heading">Regions by caseload</p>
                        <div v-for="region in regions.filter(r => r.total > 0).slice(0, 8)" :key="region.code" class="province-row" role="button" tabindex="0" @click="selected = region.name" @keydown.enter.prevent="selected = region.name">
                            <div class="province-info">
                                <span class="province-name clickable">{{ shortName(region.name) }}</span>
                                <strong>{{ region.total }}</strong>
                            </div>
                            <div class="province-bar-track">
                                <div class="province-bar-fill" :style="{ width: `${(region.total / highest) * 100}%`, background: bandFor(region.total).color }" />
                            </div>
                        </div>
                    </div>
                </template>
            </aside>
        </div>

        <footer class="map-footer">
            <div class="severity-legend">
                <span v-for="band in severityBands" :key="band.label" class="legend-item">
                    <i class="legend-swatch" :style="{ background: band.color }" />
                    {{ band.label }}
                </span>
            </div>
        </footer>

        <details class="map-table">
            <summary>View regional workload as a table</summary>
            <table>
                <caption class="sr-only">Regional case workload</caption>
                <thead><tr><th scope="col">Region</th><th scope="col">Cases</th><th scope="col">Severity</th></tr></thead>
                <tbody>
                    <tr v-for="region in regions" :key="region.code">
                        <th scope="row">{{ region.name }}</th>
                        <td>{{ region.total }}</td>
                        <td><span class="table-severity" :style="{ background: bandFor(region.total).color, color: bandFor(region.total).textColor }">{{ bandFor(region.total).label }}</span></td>
                    </tr>
                </tbody>
            </table>
        </details>
    </section>
</template>

<style scoped>
.map-card{display:flex;min-height:46rem;flex-direction:column;border:1px solid #e6e9ef;border-radius:1.25rem;background:#fff;padding:1.25rem;box-shadow:0 1px 2px rgb(15 23 42/.04)}
.map-header{display:grid;grid-template-columns:minmax(0,1fr) 7.25rem;align-items:start;gap:.75rem}
.map-header h2{font-weight:800;color:#162038}
.map-header p{margin-top:.25rem;font-size:.825rem;color:#718096}
.map-header select{width:7.25rem;border:1px solid #dde3eb;border-radius:.65rem;background:#fff;padding:.55rem .65rem;font-size:.8rem;color:#475569}
.map-body{display:grid;grid-template-columns:minmax(0,1fr) 11rem;gap:.75rem;flex:1;margin-top:1rem}
.map-canvas{position:relative;min-height:37rem;overflow:hidden;border-radius:1rem;background:#f8fafc}
.map-canvas svg{position:absolute;inset:0;display:block;width:100%;height:100%}
.region-shape{cursor:pointer;stroke:#fff;stroke-width:1.05;transition:filter .15s,stroke .15s}
.region-shape:hover,.region-shape:focus{filter:brightness(.88);stroke:#4c1d95;stroke-width:2.4;outline:none}
.region-shape.selected{stroke:#24104f;stroke-width:3}
.map-label{position:absolute;left:.75rem;top:.75rem;display:grid;gap:.15rem;border:1px solid #d8b4fe;border-radius:.55rem;background:#fff;padding:.5rem .65rem;box-shadow:0 1px 2px rgb(15 23 42/.08);font-size:.72rem;color:#6b3aad}
.map-label span{color:#64748b}
.map-sidebar{display:flex;flex-direction:column;gap:.6rem;overflow-y:auto;max-height:42rem}
.sidebar-header{display:flex;flex-direction:column;gap:.5rem}
.sidebar-count{display:grid;place-items:center;border-radius:.75rem;padding:.65rem;text-align:center}
.sidebar-count-default{background:#f3edff;color:#7146c0}
.sidebar-count strong{font-size:1.6rem;line-height:1}
.sidebar-count span{margin-top:.25rem;font-size:.65rem}
.sidebar-region{display:grid;border:1px solid #e5e7eb;border-radius:.75rem;background:#fff;padding:.55rem}
.sidebar-region b{font-size:.75rem;color:#475569}
.sidebar-region span{margin-top:.25rem;font-size:.68rem;line-height:1rem;color:#64748b}
.province-list{display:flex;flex-direction:column;gap:.35rem}
.province-heading{font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:.15rem}
.province-row{cursor:default}
.province-info{display:flex;justify-content:space-between;align-items:baseline;gap:.35rem;font-size:.72rem}
.province-name{color:#475569;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.province-name.clickable{cursor:pointer;color:#6d28d9;font-weight:600}
.province-name.clickable:hover{text-decoration:underline}
.province-info strong{color:#1e293b;font-variant-numeric:tabular-nums;flex-shrink:0}
.province-bar-track{height:.35rem;border-radius:.2rem;background:#f1f5f9;margin-top:.2rem;overflow:hidden}
.province-bar-fill{height:100%;border-radius:.2rem;transition:width .3s ease}
.no-province-data{font-size:.72rem;color:#94a3b8;text-align:center;padding:.5rem 0}
.region-rank .province-row{cursor:pointer;padding:.15rem 0;border-radius:.35rem}
.region-rank .province-row:hover{background:#f8fafc}
.back-btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;border:1px solid #e2e8f0;border-radius:.6rem;background:#fff;padding:.5rem;font-size:.72rem;color:#64748b;cursor:pointer;margin-top:.25rem}
.back-btn:hover{background:#f8fafc}
.map-footer{margin-top:.9rem}
.severity-legend{display:flex;flex-wrap:wrap;align-items:center;gap:.55rem}
.legend-item{display:inline-flex;align-items:center;gap:.3rem;font-size:.68rem;color:#64748b}
.legend-swatch{display:inline-block;width:.65rem;height:.65rem;border-radius:.2rem;border:1px solid rgb(0 0 0/.08)}
.map-loading{display:grid;min-height:37rem;place-items:center;margin-top:1rem;border-radius:1rem;background:#f8fafc;font-size:.875rem;color:#718096}
.map-table{margin-top:.8rem;border-top:1px solid #e5e7eb;padding-top:.8rem;font-size:.75rem;color:#475569}
.map-table summary{cursor:pointer;font-weight:700;color:#4c1d95}
.map-table table{width:100%;margin-top:.65rem;border-collapse:collapse;text-align:left}
.map-table th,.map-table td{border-bottom:1px solid #e5e7eb;padding:.45rem}
.map-table td{text-align:right;font-variant-numeric:tabular-nums}
.map-table td:last-child{text-align:center}
.table-severity{display:inline-block;border-radius:.35rem;padding:.15rem .45rem;font-size:.65rem;font-weight:700}
@media(max-width:900px){.map-header{grid-template-columns:1fr}.map-header select{width:100%;max-width:none}.map-body{grid-template-columns:1fr}.map-sidebar{max-height:none;display:grid;grid-template-columns:1fr 1fr;gap:.5rem}.sidebar-header{grid-column:span 2}.province-list{grid-column:span 2}.region-rank{grid-column:span 2}.back-btn{grid-column:span 2}.severity-legend{justify-content:center}}
@media(max-width:640px){.map-card{min-height:34rem}.map-canvas,.map-loading{min-height:26rem}.map-sidebar{grid-template-columns:1fr}.sidebar-header{grid-column:1}.province-list{grid-column:1}.region-rank{grid-column:1}.back-btn{grid-column:1}}
</style>
