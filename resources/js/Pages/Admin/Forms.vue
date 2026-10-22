<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SectionNav from '@/Components/SectionNav.vue';
import { Head, useForm, router } from '@inertiajs/vue3';

interface FormField {
    id: number;
    form: string;
    key: string;
    label: string;
    type: string;
    required: boolean;
    options: string | null;
    sort_order: number;
}

defineProps<{ fields: FormField[] }>();

const form = useForm({
    form: 'intake',
    key: '',
    label: '',
    type: 'text',
    required: false,
    options_text: '',
    sort_order: 0,
});

const save = () => form.post(route('admin.forms.store'), { onSuccess: () => form.reset('key', 'label', 'options_text') });

const remove = (field: FormField) => {
    if (!window.confirm(`Remove the "${field.label}" field? This cannot be undone.`)) return;
    router.delete(route('admin.forms.destroy', field.id));
};
</script>

<template>
    <Head title="Form builder" />
    <AuthenticatedLayout>
        <section class="mx-auto max-w-6xl space-y-5">
            <SectionNav section="admin" />
            <header class="ui-panel p-5 sm:p-7">
                <p class="text-sm font-bold text-brand-700">Administration</p>
                <h1 class="mt-1 text-2xl font-extrabold text-slate-900 sm:text-3xl">Form builder</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">Register configurable supplemental fields. Core protection fields remain governed by the system.</p>
            </header>

            <form class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2" @submit.prevent="save">
                <label class="text-sm font-bold">Form
                    <select v-model="form.form" class="mt-1 block w-full rounded-xl border-slate-300 text-sm font-normal">
                        <option>intake</option><option>report</option><option>assessment</option>
                    </select>
                </label>
                <label class="text-sm font-bold">Field key
                    <input v-model="form.key" required placeholder="e.g. tribe_affiliation" class="mt-1 block w-full rounded-xl border-slate-300 text-sm font-normal" />
                </label>
                <label class="text-sm font-bold">Label
                    <input v-model="form.label" required placeholder="e.g. Tribe / IP affiliation" class="mt-1 block w-full rounded-xl border-slate-300 text-sm font-normal" />
                </label>
                <label class="text-sm font-bold">Type
                    <select v-model="form.type" class="mt-1 block w-full rounded-xl border-slate-300 text-sm font-normal">
                        <option>text</option><option>textarea</option><option>select</option><option>date</option><option>checkbox</option>
                    </select>
                </label>
                <label class="text-sm font-bold">Options (comma-separated)
                    <input v-model="form.options_text" placeholder="Option A, Option B" class="mt-1 block w-full rounded-xl border-slate-300 text-sm font-normal" />
                </label>
                <label class="text-sm font-bold">Sort order
                    <input v-model="form.sort_order" type="number" min="0" class="mt-1 block w-full rounded-xl border-slate-300 text-sm font-normal" />
                </label>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.required" type="checkbox" class="rounded border-slate-300" /> Required</label>
                <div class="flex items-end">
                    <button class="min-h-11 rounded-xl bg-brand-700 px-5 text-sm font-bold text-white">Save field</button>
                </div>
            </form>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <article v-for="field in fields" :key="field.id" class="flex items-center justify-between gap-4 border-b border-slate-100 p-4">
                    <div>
                        <p class="text-sm font-bold">{{ field.label }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ field.form }} · {{ field.key }} · {{ field.type }}<span v-if="field.required"> · required</span></p>
                    </div>
                    <button type="button" class="shrink-0 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50" @click="remove(field)">Remove</button>
                </article>
                <p v-if="!fields.length" class="p-8 text-center text-sm text-slate-500">No supplemental fields configured.</p>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
