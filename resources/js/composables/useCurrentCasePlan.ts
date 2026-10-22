import { computed, ref } from 'vue';
import type { PlanEntry, PlanningResponse } from '@/types/planning';

/**
 * Loads the case plan on demand for the Case plan and Services tabs.
 * Goal statements and intervention details stay out of the case page props;
 * they are fetched from the same permission-checked endpoint the plan editor uses,
 * only when one of those tabs is opened, and kept in memory only.
 */
export function useCurrentCasePlan(caseId: () => number) {
    const entry = ref<PlanEntry | null>(null);
    const status = ref<'idle' | 'loading' | 'ready' | 'denied' | 'failed'>('idle');
    let request: Promise<void> | null = null;

    function load(force = false): Promise<void> {
        if (request && !force) return request;
        status.value = 'loading';
        request = window.axios.get<PlanningResponse>(route('cases.plans.index', caseId()))
            .then(({ data }) => {
                entry.value = data.plans.find(item => item.plan.is_current) ?? data.plans[0] ?? null;
                status.value = 'ready';
            })
            .catch((error: { response?: { status?: number } }) => {
                status.value = [401, 403].includes(error.response?.status ?? 0) ? 'denied' : 'failed';
                request = null;
            });
        return request;
    }

    const goals = computed(() => entry.value?.goals ?? []);
    const interventions = computed(() => entry.value?.interventions ?? []);
    return { entry, goals, interventions, status, load };
}
