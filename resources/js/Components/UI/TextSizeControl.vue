<script setup lang="ts">
/** Three buttons to make all text in Kalinga bigger or smaller (kept for this device). */
import { TEXT_SIZES, savedTextSize, setTextSize, type TextSize } from '@/utils/textSize';
import { ref } from 'vue';

withDefaults(defineProps<{ compact?: boolean }>(), { compact: false });
const current = ref<TextSize>(savedTextSize());
const choose = (size: TextSize) => { current.value = size; setTextSize(size); };
const glyph: Record<TextSize, string> = { normal: 'text-xs', large: 'text-base', larger: 'text-xl' };
</script>

<template>
    <div class="flex items-center gap-2" :class="compact ? '' : 'flex-wrap'">
        <span class="text-sm font-medium text-slate-700" :class="compact ? 'sr-only sm:not-sr-only' : ''">Text size</span>
        <div class="inline-flex overflow-hidden rounded-lg border border-slate-300" role="group" aria-label="Text size">
            <button v-for="size in TEXT_SIZES" :key="size.value" type="button" class="min-h-11 min-w-11 px-2 font-bold" :class="[glyph[size.value], current === size.value ? 'bg-brand-700 text-white' : 'bg-white text-slate-800 hover:bg-slate-50']"
                :aria-pressed="current === size.value" :aria-label="size.label" :title="size.label" @click="choose(size.value)">A</button>
        </div>
    </div>
</template>
