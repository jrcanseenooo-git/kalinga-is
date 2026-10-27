<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import { computed } from 'vue';
import { longDate } from '@/utils/reportFormat';
import type { ReportOffice } from '@/types/reports';
import { NAME_MODES, type NameMode } from '@/utils/clientName';

/**
 * Flat, print-ready frame shared by every Kalinga case report: toolbar,
 * header (brand, title band, report details), LGU line, body slot and the
 * confidentiality footer. Styles are global under .rpt so section bodies
 * passed in the slot use them too.
 */
const props = defineProps<{
  title: string; subtitle?: string; reportNo: string; generatedAt: string; caseId: number;
  office: ReportOffice; preparedBy: { name: string; position: string | null };
  extraMeta?: { label: string; value: string }[]; hasAlias?: boolean;
}>();
/** Masked by default; full name also shows contact numbers and other names. */
const nameMode = defineModel<NameMode>('nameMode', { default: 'masked' });
const area = computed(() => [props.office.short || props.office.name, props.office.lgu].filter(Boolean).join(' - ') || 'Not set in LGU profile');
const printNow = () => window.print();
</script>

<template>
  <div class="rpt">
    <div class="rpt-toolbar no-print">
      <Link :href="route('cases.show', caseId)" class="rpt-btn"><ArrowLeft class="h-4 w-4" aria-hidden="true"/>Back to case</Link>
      <div class="rpt-actions">
        <slot name="tools" />
        <div class="rpt-names" role="group" aria-label="Name shown on this form">
          <span class="rpt-names-label">Name on form</span>
          <button v-for="mode in NAME_MODES" :key="mode.value" type="button" class="rpt-btn" :aria-pressed="nameMode === mode.value"
            :disabled="mode.value === 'alias' && !hasAlias" :title="mode.value === 'alias' && !hasAlias ? 'No alias or nickname recorded in the intake' : undefined" @click="nameMode = mode.value">{{ mode.label }}</button>
        </div>
        <button type="button" class="rpt-btn rpt-btn-primary" @click="printNow"><Printer class="h-4 w-4" aria-hidden="true"/>Print / Save as PDF</button>
      </div>
      <p class="rpt-note">Prepared from the case record as of {{ longDate(generatedAt) }}. You may correct wording on this page before printing; corrections made here are not saved to the case record. The child's name is masked (for example J**S***D**UZ) unless you choose the alias or the full name; contact numbers and other names show only with the full name.</p>
    </div>

    <article class="rpt-sheet" :aria-label="title">
      <header class="rpt-head">
        <div class="rpt-brand">
          <img src="/kalinga-logo-purple.png" alt="Kalinga" class="rpt-brand-logo">
          <div><p class="rpt-brand-name">KALINGA</p><p class="rpt-brand-sub">CEFMU Case Management Information System</p></div>
        </div>
        <div class="rpt-title"><h1>{{ title.toUpperCase() }}</h1><p>{{ subtitle || office.name || 'Social Welfare and Development Office' }}</p></div>
        <dl class="rpt-meta">
          <div><dt>Report No.</dt><dd>{{ reportNo }}</dd></div>
          <div><dt>Date prepared</dt><dd>{{ longDate(generatedAt) }}</dd></div>
          <div><dt>Prepared by</dt><dd>{{ preparedBy.name }}</dd></div>
          <div><dt>Designation</dt><dd>{{ preparedBy.position || 'Case manager' }}</dd></div>
          <div><dt>Office</dt><dd>{{ area }}</dd></div>
          <div v-for="item in extraMeta ?? []" :key="item.label"><dt>{{ item.label }}</dt><dd>{{ item.value }}</dd></div>
        </dl>
      </header>
      <div class="rpt-lgu"><img v-if="office.logo" :src="office.logo" alt="LGU seal" class="rpt-lgu-logo"><p>Republic of the Philippines<span v-if="office.province"> | Province of {{ office.province }}</span><span v-if="office.lgu"> | {{ office.lgu }}</span><span v-if="office.address"> | {{ office.address }}</span><span v-if="office.contact"> | {{ office.contact }}</span></p></div>

      <slot />

      <footer class="rpt-foot">
        <p><strong>CONFIDENTIAL.</strong> This document contains personal information of a child protected under Republic Act No. 10173 (Data Privacy Act of 2012), Republic Act No. 7610 and Republic Act No. 11596. Do not copy or share outside the authorized case team.</p>
        <p>{{ reportNo }} | System-generated from Kalinga on {{ longDate(generatedAt) }}</p>
      </footer>
    </article>
  </div>
</template>

<style>
/* Kalinga report design: flat, one brand colour, thin rules. Shared by all report bodies. */
.rpt{--rpt-brand:#5b21b6;--rpt-ink:#1f1a33;--rpt-head:#2e1065;--rpt-tint:#f4f1fa;--rpt-line:#d6d0e6;--rpt-muted:#5b5670;min-height:100vh;background:#efeef3;padding:1rem;color:var(--rpt-ink)}
.rpt-toolbar{max-width:1100px;margin:0 auto 1rem;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
.rpt-actions{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem}
.rpt-btn{display:inline-flex;min-height:2.75rem;align-items:center;gap:.4rem;border:1px solid var(--rpt-line);border-radius:.5rem;background:#fff;padding:0 .9rem;font-size:.85rem;font-weight:600;color:#334155}
.rpt-btn:hover{background:#faf9fd}.rpt-btn:focus-visible{outline:2px solid var(--rpt-brand);outline-offset:2px}
.rpt-btn-primary{border-color:var(--rpt-brand);background:var(--rpt-brand);color:#fff}.rpt-btn-primary:hover{background:#4c1d95}
.rpt-names{display:flex;flex-wrap:wrap;align-items:center;gap:.25rem}.rpt-names-label{font-size:.75rem;font-weight:700;color:#475569;margin-right:.15rem}.rpt-names .rpt-btn[aria-pressed=true]{border-color:var(--rpt-brand);background:#f4f1fa;color:var(--rpt-brand)}.rpt-names .rpt-btn:disabled{opacity:.45;cursor:not-allowed}
.rpt-note{flex-basis:100%;font-size:.78rem;color:#475569}
.rpt-sheet{max-width:1100px;margin:0 auto;background:#fff;border:1px solid var(--rpt-line);padding:1.25rem;font-size:.86rem;line-height:1.45}
.rpt-head{display:grid;grid-template-columns:1fr 1.3fr 1fr;gap:.9rem;align-items:stretch;padding-bottom:.75rem;border-bottom:2px solid var(--rpt-brand)}
.rpt-brand{display:flex;align-items:center;gap:.6rem}.rpt-brand-logo{height:3.2rem;width:auto}.rpt-brand-name{font-size:1.45rem;font-weight:800;letter-spacing:.04em;color:var(--rpt-brand);line-height:1}.rpt-brand-sub{margin-top:.15rem;font-size:.72rem;color:var(--rpt-muted)}
.rpt-title{background:var(--rpt-brand);color:#fff;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;padding:.75rem}
.rpt-title h1{font-size:1.35rem;font-weight:800;letter-spacing:.03em;line-height:1.15}.rpt-title p{margin-top:.3rem;font-size:.7rem;letter-spacing:.14em;text-transform:uppercase}
.rpt-meta{border:1px solid var(--rpt-line);padding:.5rem .7rem;font-size:.74rem}.rpt-meta div{display:grid;grid-template-columns:6.3rem 1fr;gap:.3rem;padding:.08rem 0}.rpt-meta dt{font-weight:700;color:var(--rpt-head)}.rpt-meta dd{word-break:break-word}
.rpt-lgu{display:flex;align-items:center;gap:.6rem;margin:.6rem 0 .1rem;font-size:.74rem;color:var(--rpt-muted)}.rpt-lgu-logo{height:2.3rem;width:2.3rem;object-fit:contain}
.rpt-grid2{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:.75rem}.rpt-grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-top:.75rem}.rpt-span2{grid-column:span 2}.rpt-stack{display:flex;flex-direction:column;gap:.75rem}
.rpt-sec{margin-top:.75rem;border:1px solid var(--rpt-line);break-inside:avoid}.rpt-grid2>.rpt-sec,.rpt-grid3>.rpt-sec,.rpt-stack>.rpt-sec{margin-top:0}
.rpt-sec>h2{display:flex;align-items:center;gap:.5rem;margin:0;padding:.35rem .6rem;background:var(--rpt-tint);border-bottom:1px solid var(--rpt-line);font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--rpt-head)}
.rpt-num{display:inline-grid;min-width:1.35rem;height:1.35rem;place-items:center;background:var(--rpt-brand);color:#fff;font-size:.72rem;font-weight:800}
.rpt-body{padding:.5rem .65rem}
.rpt-kv{width:100%;border-collapse:collapse}.rpt-kv th{width:38%;text-align:left;vertical-align:top;font-weight:700;color:var(--rpt-head);padding:.22rem .4rem;border-bottom:1px solid #ece8f4}.rpt-kv td{padding:.22rem .4rem;border-bottom:1px solid #ece8f4;overflow-wrap:anywhere}
.rpt-kv tr:last-child th,.rpt-kv tr:last-child td{border-bottom:0}
.rpt-table{width:100%;border-collapse:collapse;font-size:.8rem}.rpt-table th{background:var(--rpt-tint);color:var(--rpt-head);font-weight:700;text-align:left;padding:.3rem .45rem;border:1px solid var(--rpt-line)}.rpt-table td{padding:.3rem .45rem;border:1px solid var(--rpt-line);vertical-align:top;overflow-wrap:break-word}
.rpt-wrap{overflow-x:auto}.rpt-w-date{width:7rem}.rpt-empty{text-align:center;color:var(--rpt-muted);font-style:italic}
.rpt-text{white-space:pre-wrap;outline:none}.rpt-para+.rpt-para{margin-top:.5rem}.rpt-para{text-align:justify;text-indent:2rem}.rpt-text:focus,.rpt-list:focus{box-shadow:0 0 0 2px #c4b5fd}.rpt-list{padding-left:1.1rem;list-style:disc;outline:none}.rpt-list li{margin:.12rem 0}
.rpt-sub+.rpt-sub{margin-top:.5rem}.rpt-sub h3{font-size:.74rem;font-weight:700;color:var(--rpt-head);text-transform:uppercase;letter-spacing:.03em}
.rpt-muted{color:var(--rpt-muted)}.rpt-small{font-size:.78rem}.rpt-total{margin-top:.4rem;text-align:right;font-size:.8rem}
.rpt-withheld{color:var(--rpt-muted);font-style:italic}
.rpt-sign{display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:1rem;text-align:center}.rpt-sign .lbl{text-align:left;font-size:.72rem;color:var(--rpt-muted)}.rpt-sign .sig{margin-top:1.8rem;border-top:1px solid var(--rpt-ink);padding-top:.2rem;font-weight:800;text-transform:uppercase;font-size:.78rem;min-height:1.2rem}.rpt-sign .pos{font-size:.72rem}
.rpt-warn{margin-top:.5rem;font-size:.72rem;color:#92400e}
.rpt-foot{margin-top:1rem;border-top:1px solid var(--rpt-line);padding-top:.5rem;font-size:.66rem;color:var(--rpt-muted);text-align:center}.rpt-foot p+p{margin-top:.15rem}
@media (max-width:860px){.rpt{padding:.5rem}.rpt-sheet{padding:.75rem}.rpt-head,.rpt-grid2,.rpt-grid3{grid-template-columns:1fr}.rpt-span2{grid-column:auto}}
@media print{
  @page{size:A4;margin:12mm 10mm 14mm;@bottom-right{content:"Page " counter(page) " of " counter(pages);font-size:8pt;color:#5b5670}}
  .no-print{display:none!important}
  .rpt{background:#fff;padding:0;min-height:0}
  .rpt-sheet{border:0;max-width:none;padding:0;font-size:9.5pt}
  .rpt-head{grid-template-columns:1fr 1.3fr 1fr}.rpt-grid2{grid-template-columns:1fr 1fr}.rpt-grid3{grid-template-columns:1fr 1fr 1fr}.rpt-span2{grid-column:span 2}
  .rpt-text:focus,.rpt-list:focus{box-shadow:none}
  .rpt-table thead{display:table-header-group}.rpt-table tr{break-inside:avoid}
  *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
